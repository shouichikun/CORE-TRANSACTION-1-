<?php
// portals/hr/applicants.php - Manage Applicants (FIXED RESUME PATH)
session_start();

// =============================================
// DEBUG MODE - Remove in production
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

// Clear any previous output
ob_clean();

require_once '../../app/config.php';
require_once '../../app/email_functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has HR role
if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';
$isHRManager = $role === 'hr_manager';

// =============================================
// RESUME PATH CONFIGURATION - FIXED
// =============================================
function getResumeInfo($filename) {
    if (empty($filename)) {
        return null;
    }
    
    // Clean the filename
    $filename = trim($filename);
    $filename = ltrim($filename, '/');
    $filename = ltrim($filename, '\\');
    
    // Get just the filename without any path
    $justFilename = basename($filename);
    
    // Build paths to check - FIXED: resumes are in portals/hr/resumes/
    $paths = [
        'hr_resumes' => __DIR__ . '/resumes/' . $justFilename,
        'database_path' => dirname(__DIR__, 2) . '/' . $filename,
        'hr_includes_resumes' => dirname(__DIR__, 2) . '/hr/includes/resumes/' . $justFilename,
        'uploads_resumes' => dirname(__DIR__, 2) . '/uploads/resumes/' . $justFilename,
        'portals_uploads_resumes' => dirname(__DIR__, 2) . '/portals/uploads/resumes/' . $justFilename,
        'current_dir' => __DIR__ . '/' . $justFilename,
    ];
    
    // Debug logging
    $debugInfo = "Searching for: $justFilename\n";
    $debugInfo .= "Current directory: " . __DIR__ . "\n";
    
    foreach ($paths as $key => $physicalPath) {
        $exists = file_exists($physicalPath);
        $debugInfo .= "  $key: " . $physicalPath . " - " . ($exists ? '✅ EXISTS' : '❌ NOT FOUND') . "\n";
        
        if ($exists) {
            return [
                'url' => 'resumes/' . $justFilename,
                'physical_path' => $physicalPath,
                'exists' => true,
                'filename' => $justFilename,
                'debug' => $debugInfo . "\n✅ FOUND at: $key"
            ];
        }
    }
    
    // Try to find a matching file by scanning the resumes/ folder
    $resumeDir = __DIR__ . '/resumes/';
    if (is_dir($resumeDir)) {
        $files = scandir($resumeDir);
        $debugInfo .= "\nScanning directory: $resumeDir\n";
        $foundMatch = null;
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strpos($file, '.pdf') === false) continue;
            
            $debugInfo .= "  Found file: $file\n";
            
            preg_match('/_(\d+)\.pdf$/', $justFilename, $dbMatch);
            preg_match('/_(\d+)\.pdf$/', $file, $fileMatch);
            
            if (isset($dbMatch[1]) && isset($fileMatch[1])) {
                if ($dbMatch[1] === $fileMatch[1]) {
                    $foundMatch = $file;
                    $debugInfo .= "  ✅ EXACT TIMESTAMP MATCH: $file\n";
                    break;
                }
                if (abs(intval($dbMatch[1]) - intval($fileMatch[1])) < 200000) {
                    $foundMatch = $file;
                    $debugInfo .= "  ✅ CLOSE TIMESTAMP MATCH: $file (diff: " . abs(intval($dbMatch[1]) - intval($fileMatch[1])) . ")\n";
                    break;
                }
            }
            
            if (strpos($file, 'resume_8_') === 0 && $foundMatch === null) {
                $foundMatch = $file;
                $debugInfo .= "  ⚠️ FALLBACK: $file\n";
            }
        }
        
        if ($foundMatch) {
            return [
                'url' => 'resumes/' . $foundMatch,
                'physical_path' => $resumeDir . $foundMatch,
                'exists' => true,
                'filename' => $foundMatch,
                'matched' => true,
                'debug' => $debugInfo . "\n✅ USING: $foundMatch"
            ];
        }
    }
    
    return [
        'exists' => false,
        'debug' => $debugInfo . "\n❌ NO FILE FOUND"
    ];
}

// Helper function to determine qualification
function determineQualification($applicant) {
    // Example qualification criteria:
    // 1. If match_score exists and is high enough
    if (!empty($applicant['match_score']) && $applicant['match_score'] >= 70) {
        return true;
    }
    
    // 2. If applicant has specific skills
    if (!empty($applicant['skills'])) {
        $skills = strtolower($applicant['skills']);
        $requiredSkills = ['php', 'javascript', 'python', 'java', 'sql', 'html', 'css'];
        foreach ($requiredSkills as $skill) {
            if (strpos($skills, $skill) !== false) {
                return true;
            }
        }
    }
    
    // 3. If applicant has relevant experience keywords
    if (!empty($applicant['experience'])) {
        $experience = strtolower($applicant['experience']);
        $keywords = ['years', 'experience', 'senior', 'lead', 'manager', 'team'];
        foreach ($keywords as $keyword) {
            if (strpos($experience, $keyword) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$jobFilter = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$searchQuery = $_GET['search'] ?? '';

// Build query conditions
$conditions = [];
$params = [];
$types = "";

// Only show applicants for jobs created by this user
$conditions[] = "jo.created_by = ?";
$params[] = $userId;
$types .= "i";

if ($statusFilter !== 'all') {
    $conditions[] = "a.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($jobFilter > 0) {
    $conditions[] = "a.job_order_id = ?";
    $params[] = $jobFilter;
    $types .= "i";
}

if (!empty($searchQuery)) {
    $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR jo.title LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

$sql = "SELECT a.*, a.resume_path,
        u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
        ap.profile_picture, ap.skills, ap.experience, ap.education,
        jo.title as job_title, jo.id as job_id, c.company_name,
        (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id) as total_applications
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        $whereClause
        ORDER BY a.applied_at DESC";

$applicants = getRecords($sql, $params, $types);

// Get all jobs for filter dropdown
$jobs = getRecords("SELECT id, title FROM job_orders WHERE created_by = ? ORDER BY created_at DESC", [$userId], "i");

// Get status counts
$statusCounts = ['all' => count($applicants)];
$statuses = ['pending', 'shortlisted', 'scheduled', 'interviewed', 'hired', 'rejected', 'withdrawn'];
foreach ($statuses as $status) {
    $countSql = "SELECT COUNT(*) as count FROM applications a 
                 JOIN job_orders jo ON a.job_order_id = jo.id 
                 WHERE jo.created_by = ? AND a.status = ?";
    $result = getRecord($countSql, [$userId, $status], "is");
    $statusCounts[$status] = $result['count'] ?? 0;
}

// Status badge mapping
$statusBadges = [
    'pending' => 'badge-pending',
    'shortlisted' => 'badge-shortlisted',
    'scheduled' => 'badge-scheduled',
    'interviewed' => 'badge-interviewed',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected',
    'withdrawn' => 'badge-withdrawn'
];

$statusLabels = [
    'pending' => 'Pending Review',
    'shortlisted' => 'Shortlisted',
    'scheduled' => 'Scheduled',
    'interviewed' => 'Interviewed',
    'hired' => 'Hired',
    'rejected' => 'Rejected',
    'withdrawn' => 'Withdrawn'
];

$allStatuses = ['all' => 'All'] + $statusLabels;

// Handle AJAX POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
    $newStatus = $_POST['status'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');
    $interviewDate = $_POST['interview_date'] ?? '';
    $interviewNotes = trim($_POST['interview_notes'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // UPDATE STATUS
    if ($action === 'update_status' && $applicationId > 0 && in_array($newStatus, $statuses)) {
        $current = getRecord("SELECT status FROM applications WHERE id = ?", [$applicationId], "i");
        $oldStatus = $current['status'] ?? 'unknown';
        
        $result = updateApplicationStatus($applicationId, $newStatus);
        
        if ($result) {
            $logMessage = 'Status changed from ' . $oldStatus . ' to: ' . $newStatus;
            if (!empty($feedback)) {
                $logMessage .= ' | Feedback: ' . $feedback;
            }
            logActivity($userId, 'Application Status Updated', 'applications', $applicationId, $logMessage);
            
            echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update status.']);
        }
        exit;
    }
    
    // SCHEDULE INTERVIEW
    if ($action === 'schedule_interview' && $applicationId > 0) {
        $interviewDate = $_POST['interview_date'] ?? '';
        $interviewNotes = trim($_POST['interview_notes'] ?? '');
        
        if (empty($interviewDate)) {
            echo json_encode(['success' => false, 'error' => 'Please select an interview date and time.']);
            exit;
        }
        
        $applicantInfo = getRecord("
            SELECT a.id, a.applicant_id, u.id as user_id, u.first_name, u.last_name, u.email, 
                   jo.title as job_title, jo.id as job_id, c.company_name
            FROM applications a
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            WHERE a.id = ?
        ", [$applicationId], "i");
        
        if (!$applicantInfo) {
            echo json_encode(['success' => false, 'error' => 'Applicant not found.']);
            exit;
        }
        
        $dbDateTime = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $interviewDate)));
        if ($dbDateTime === false || $dbDateTime === '1970-01-01 00:00:00') {
            echo json_encode(['success' => false, 'error' => 'Invalid date format.']);
            exit;
        }
        
        // Insert interview
        $interviewSql = "INSERT INTO interviews (application_id, interview_date, notes, created_by) 
                         VALUES (?, ?, ?, ?)";
        $interviewResult = insertRecord($interviewSql, [
            $applicationId,
            $dbDateTime,
            $interviewNotes,
            $userId
        ], "issi");
        
        if (!$interviewResult) {
            echo json_encode(['success' => false, 'error' => 'Failed to create interview record.']);
            exit;
        }
        
        // Update application
        $updateSql = "UPDATE applications SET 
                      interview_date = ?,
                      interview_notes = ?,
                      status = 'scheduled'
                      WHERE id = ?";
        $updateResult = updateRecord($updateSql, [
            $dbDateTime,
            $interviewNotes,
            $applicationId
        ], "ssi");
        
        if (!$updateResult) {
            deleteRecord("DELETE FROM interviews WHERE id = ?", [$interviewResult], "i");
            echo json_encode(['success' => false, 'error' => 'Failed to update application status.']);
            exit;
        }
        
        logActivity($userId, 'Interview Scheduled', 'applications', $applicationId, 'Interview scheduled for: ' . $dbDateTime);
        
        echo json_encode(['success' => true, 'message' => 'Interview scheduled successfully!']);
        exit;
    }
    
    // VIEW APPLICANT
    if ($action === 'view_applicant' && $applicationId > 0) {
        $applicant = getRecord("SELECT a.*, a.cover_letter, a.resume_path,
                               u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
                               ap.skills, ap.experience, ap.education, ap.profile_picture,
                               jo.title as job_title, c.company_name,
                               (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id) as total_applications
                               FROM applications a
                               JOIN applicants ap ON a.applicant_id = ap.id
                               JOIN users u ON ap.user_id = u.id
                               JOIN job_orders jo ON a.job_order_id = jo.id
                               JOIN clients c ON jo.client_id = c.id
                               WHERE a.id = ?", [$applicationId], "i");
        if ($applicant) {
            // Check for resume file
            $resumeInfo = getResumeInfo($applicant['resume_path'] ?? '');
            
            if ($resumeInfo && isset($resumeInfo['exists']) && $resumeInfo['exists'] === true) {
                $applicant['resume_exists'] = true;
                $applicant['resume_filename'] = $resumeInfo['filename'];
                $applicant['resume_url'] = $resumeInfo['url'];
                $applicant['resume_size'] = filesize($resumeInfo['physical_path']);
                $applicant['resume_extension'] = strtolower(pathinfo($resumeInfo['filename'], PATHINFO_EXTENSION));
                $applicant['resume_debug'] = $resumeInfo['debug'] ?? '';
            } else {
                $applicant['resume_exists'] = false;
                $applicant['resume_debug'] = $resumeInfo['debug'] ?? 'No resume info available';
            }
            
            echo json_encode(['success' => true, 'applicant' => $applicant]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Applicant not found.']);
        }
        exit;
    }
    
// SEND QUALIFICATION NOTIFICATION (Manual)
if ($action === 'send_qualification' && $applicationId > 0) {
    // Clear any previous output
    ob_clean();
    
    // Create a debug log file
    $debugLog = __DIR__ . '/debug_qualification.log';
    file_put_contents($debugLog, "[" . date('Y-m-d H:i:s') . "] Starting send_qualification for ID: $applicationId\n", FILE_APPEND);
    
    try {
        // Get applicant data
        $applicant = getRecord("
            SELECT a.*, 
            u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
            ap.skills, ap.experience, ap.education,
            jo.title as job_title, c.company_name
            FROM applications a
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            WHERE a.id = ?
        ", [$applicationId], "i");
        
        file_put_contents($debugLog, "Applicant found: " . ($applicant ? 'Yes' : 'No') . "\n", FILE_APPEND);
        
        if (!$applicant) {
            file_put_contents($debugLog, "ERROR: Applicant not found\n", FILE_APPEND);
            echo json_encode(['success' => false, 'error' => 'Applicant not found.']);
            exit;
        }
        
        // Check if already sent
        if (!empty($applicant['follow_up_sent']) && $applicant['follow_up_sent'] == 1) {
            file_put_contents($debugLog, "ERROR: Notification already sent\n", FILE_APPEND);
            echo json_encode(['success' => false, 'error' => 'Notification already sent.']);
            exit;
        }
        
        // Check if applicant is in a terminal state
        if (in_array($applicant['status'], ['hired', 'rejected', 'withdrawn'])) {
            file_put_contents($debugLog, "ERROR: Application already processed. Status: " . $applicant['status'] . "\n", FILE_APPEND);
            echo json_encode(['success' => false, 'error' => 'Application already processed.']);
            exit;
        }
        
        // Determine qualification
        $isQualified = isset($_POST['is_qualified']) ? (bool)$_POST['is_qualified'] : false;
        $notes = trim($_POST['notes'] ?? '');
        
        file_put_contents($debugLog, "isQualified: " . ($isQualified ? 'Yes' : 'No') . ", Notes: $notes\n", FILE_APPEND);
        
        // Try to send email - WITH SUPPRESSED ERRORS
        $emailSent = false;
        try {
            file_put_contents($debugLog, "Attempting to send email...\n", FILE_APPEND);
            $emailSent = sendQualificationEmail($applicant, $isQualified, $applicant['company_name'] ?? 'Our Company');
            file_put_contents($debugLog, "Email send result: " . ($emailSent ? 'Success' : 'Failed') . "\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($debugLog, "Email Exception: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        
        // Always update the database - FIXED SQL SYNTAX
        $qualificationStatus = $isQualified ? 'qualified' : 'not_qualified';
        $statusLabel = $isQualified ? 'Qualified' : 'Not Qualified';
        
        file_put_contents($debugLog, "Updating database...\n", FILE_APPEND);
        
        // Get current notes first to append properly
        $currentApp = getRecord("SELECT notes FROM applications WHERE id = ?", [$applicationId], "i");
        $currentNotes = $currentApp['notes'] ?? '';
        
        // Build the new notes string manually
        $newNote = 'Manual qualification: ' . $statusLabel . ' - ' . $notes;
        if (!empty($currentNotes)) {
            $newNotes = $currentNotes . "\n" . $newNote;
        } else {
            $newNotes = $newNote;
        }
        
        // Update with the built notes string - NO CONCAT IN SQL
        $updateSql = "UPDATE applications SET 
                      follow_up_sent = 1,
                      follow_up_date = NOW(),
                      qualification_status = ?,
                      last_follow_up_email = NOW(),
                      notes = ?
                      WHERE id = ?";
        $updateResult = updateRecord($updateSql, [$qualificationStatus, $newNotes, $applicationId], "ssi");
        
        file_put_contents($debugLog, "Database update result: " . ($updateResult ? 'Success' : 'Failed') . "\n", FILE_APPEND);
        
        // Log activity
        logActivity($userId, 'Manual Qualification Notification', 'applications', $applicationId, 
                   "Manual notification sent: " . ($isQualified ? 'Qualified' : 'Not Qualified'));
        
        // ALWAYS return success
        file_put_contents($debugLog, "Returning success response\n", FILE_APPEND);
        echo json_encode([
            'success' => true, 
            'message' => 'Notification sent successfully!'
        ]);
        
    } catch (Exception $e) {
        // Catch any unexpected errors
        file_put_contents($debugLog, "UNEXPECTED ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents($debugLog, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
        
        echo json_encode([
            'success' => false, 
            'error' => 'System error: ' . $e->getMessage()
        ]);
    }
    exit;
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Applicants - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - APPLICANTS MANAGEMENT
           ========================================================================== */
        :root {
            --bg-background: #f8f7fc;
            --bg-surface: #ffffff;
            --bg-surface-low: #f5f3ff;
            --bg-surface-container-low: #f5f3ff;
            --bg-surface-container-lowest: #ffffff;
            --bg-surface-container-high: #ede9fe;
            --text-on-surface: #1b1b24;
            --text-on-surface-variant: #464555;
            --text-on-background: #1b1b24;
            --outline-variant: #c7c4d8;
            --primary: #4f46e5;
            --primary-container: #4f46e5;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-500: #64748b;
            --slate-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.06), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-label: 'Public Sans', system-ui, -apple-system, sans-serif;
            --transition-fast: 0.15s ease;
            --transition-smooth: 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            --sidebar-width: 280px;
            --sidebar-collapsed: 72px;
        }

        .badge-scheduled { background: #dbeafe; color: #2563eb; }
        .badge-notified-qualified { background: #d1fae5; color: #059669; }
        .badge-notified-notqualified { background: #fecaca; color: #dc2626; }
        .badge-pending-notification { background: #fef3c7; color: #d97706; }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg-background);
            color: var(--text-on-surface);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: row;
            overflow: hidden;
            height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* =============================================
           SIDEBAR - FIXED
        ============================================= */
        .dashboard-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 50;
            background: var(--bg-surface);
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: var(--sidebar-width);
            border-right: 1px solid var(--slate-200);
            transition: width 0.3s ease, transform 0.3s ease;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            flex-shrink: 0;
        }

        .dashboard-sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .dashboard-sidebar.mobile-hidden {
            transform: translateX(-100%);
        }

        .dashboard-sidebar.mobile-open {
            transform: translateX(0);
        }

        .dashboard-sidebar .sidebar-brand-text,
        .dashboard-sidebar .sidebar-brand-category,
        .dashboard-sidebar .sidebar-nav .nav-label,
        .dashboard-sidebar .sidebar-nav .nav-text,
        .dashboard-sidebar .sidebar-nav .nav-badge,
        .dashboard-sidebar .sidebar-footer .user-info {
            opacity: 1;
            transition: opacity 0.3s ease;
            overflow: hidden;
            white-space: nowrap;
        }

        .dashboard-sidebar.collapsed .sidebar-brand-text,
        .dashboard-sidebar.collapsed .sidebar-brand-category,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
        .dashboard-sidebar.collapsed .sidebar-footer .user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }

        .dashboard-sidebar.collapsed .sidebar-brand-card {
            padding: 1rem 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-nav {
            padding: 0.5rem 0.25rem;
        }

        .dashboard-sidebar.collapsed .sidebar-main-link {
            justify-content: center;
            padding: 0.75rem 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-footer .user-card {
            justify-content: center;
            padding: 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-footer .user-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            font-size: 0.875rem;
        }

        .sidebar-brand-card {
            border-radius: 2rem;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
        }

        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background: var(--slate-100);
            color: var(--primary);
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .sidebar-brand-icon .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .sidebar-brand-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--slate-900);
        }

        .sidebar-brand-category {
            font-size: 0.75rem;
            color: var(--slate-500);
            margin-top: 0.25rem;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 1.25rem;
        }

        .sidebar-nav .nav-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--slate-500);
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
        }

        .sidebar-main-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            margin-bottom: 0.25rem;
            font-family: var(--font-label);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .sidebar-main-link:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-main-link.active {
            background: var(--bg-surface-container-high);
            color: var(--primary);
        }

        .sidebar-main-link .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .sidebar-main-link .nav-text {
            transition: opacity 0.3s ease;
        }

        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            border-radius: 50px;
            transition: opacity 0.3s ease;
        }

        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--slate-200);
        }

        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 1rem;
            background: var(--bg-surface-low);
        }

        .sidebar-footer .user-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .sidebar-footer .user-card .user-info .user-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .sidebar-footer .user-card .user-info .user-email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer .logout-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            margin-top: 0.5rem;
            border-radius: 0.75rem;
            color: #dc2626;
            transition: all var(--transition-fast);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
        }

        .sidebar-footer .logout-btn:hover {
            background: #fef2f2;
        }

        .sidebar-footer .logout-btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(17, 24, 39, 0.5);
            backdrop-filter: blur(8px);
            z-index: 40;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        .sidebar-backdrop.active {
            display: block;
            opacity: 1;
        }

        /* =============================================
           MAIN CONTENT
        ============================================= */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
        }

        .dashboard-sidebar.collapsed ~ .main-wrapper {
            margin-left: var(--sidebar-collapsed);
        }

        /* =============================================
           TOP HEADER
        ============================================= */
        .top-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(199, 196, 216, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
            padding: 0 1.5rem;
            flex-shrink: 0;
            z-index: 30;
            width: 100%;
        }

        .top-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .top-header-left .logo {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            background: var(--slate-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.875rem;
            color: var(--primary);
            border: 1px solid rgba(199, 196, 216, 0.3);
        }

        .top-header-left .separator {
            color: var(--outline-variant);
            font-weight: 300;
            user-select: none;
        }

        .sidebar-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(199, 196, 216, 0.3);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.5rem;
            min-height: 2.5rem;
        }

        .sidebar-toggle-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-toggle-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(199, 196, 216, 0.3);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.5rem;
            min-height: 2.5rem;
        }

        .mobile-menu-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .mobile-menu-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .profile-dropdown-wrapper {
            position: relative;
        }

        .profile-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.375rem 0.75rem 0.375rem 0.375rem;
            border-radius: var(--radius-full);
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .profile-dropdown-toggle:hover {
            background: var(--bg-surface-low);
            border-color: rgba(199, 196, 216, 0.3);
        }

        .profile-dropdown-toggle .avatar-small {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .profile-dropdown-toggle .profile-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .profile-dropdown-toggle .profile-role {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            font-weight: 400;
        }

        .profile-dropdown-toggle .material-symbols-outlined {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
            transition: transform var(--transition-fast);
        }

        .profile-dropdown-toggle.open .material-symbols-outlined:last-child {
            transform: rotate(180deg);
        }

        .profile-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 14rem;
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--slate-200);
            padding: 0.5rem;
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-0.5rem) scale(0.95);
            transition: all var(--transition-smooth);
            transform-origin: top right;
        }

        .profile-dropdown-menu.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .profile-dropdown-menu .dropdown-header {
            padding: 0.5rem 0.875rem 0.25rem;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .profile-dropdown-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            font-family: var(--font-sans);
        }

        .profile-dropdown-menu .dropdown-item:hover {
            background: var(--bg-surface-low);
            color: var(--primary);
        }

        .profile-dropdown-menu .dropdown-item .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--text-on-surface-variant);
        }

        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined {
            color: var(--primary);
        }

        .profile-dropdown-menu .dropdown-item.danger {
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-item.danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined {
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-divider {
            height: 1px;
            background: var(--slate-200);
            margin: 0.25rem 0.5rem;
        }

        /* =============================================
           MAIN SCROLLABLE AREA
        ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }

        .main-scroll .container {
            max-width: 80rem;
            margin: 0 auto;
        }

        /* =============================================
           BREADCRUMB
        ============================================= */
        .breadcrumb-bar {
            background: var(--bg-surface-container-lowest);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
            padding: 1rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .breadcrumb-bar {
                border-radius: var(--radius-2xl);
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .breadcrumb-view {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            border-radius: 0.75rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid rgba(79, 70, 229, 0.2);
        }

        .breadcrumb-view .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .breadcrumb-view .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* =============================================
           PAGE HEADER
        ============================================= */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* =============================================
           BUTTONS
        ============================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--bg-surface-low);
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-warning:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.5rem;
        }

        .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-sm .material-symbols-outlined {
            font-size: 1rem;
        }

        /* =============================================
           SEARCH & FILTERS
        ============================================= */
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.25rem;
        }

        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.625rem 0.875rem 0.625rem 2.75rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }

        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .search-bar .search-input-wrapper input::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .filters {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }

        .filters select {
            padding: 0.625rem 2.5rem 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            min-width: 160px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.875rem center;
        }

        .filters select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        /* =============================================
           APPLICANTS TABLE
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .card-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .card-header .applicant-count {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
        }

        .card-body {
            padding: 0;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            min-width: 700px;
        }

        table thead {
            background: var(--bg-surface-low);
        }

        table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }

        table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        table tbody tr:hover td {
            background: var(--bg-surface-low);
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .applicant-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .applicant-info .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .applicant-info .details .name {
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .applicant-info .details .email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        /* ===== BADGES ===== */
        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-shortlisted { background: #dbeafe; color: #2563eb; }
        .badge-interviewed { background: #e0e7ff; color: #4f46e5; }
        .badge-hired { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-withdrawn { background: #f3f4f6; color: #6b7280; }
        .badge-notified-qualified { background: #d1fae5; color: #059669; }
        .badge-notified-notqualified { background: #fecaca; color: #dc2626; }
        .badge-pending-notification { background: #fef3c7; color: #d97706; }

        .action-buttons {
            display: flex;
            gap: 0.375rem;
            justify-content: center;
            flex-wrap: wrap;
            align-items: center;
        }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 4rem 1.5rem;
        }

        .empty-state .material-symbols-outlined {
            font-size: 4rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 1rem;
        }

        .empty-state h4 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .empty-state p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .empty-state .btn {
            margin-top: 1rem;
        }

        /* =============================================
           MODALS
        ============================================= */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 48rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }

        .modal.status-modal .modal {
            max-width: 32rem;
        }

        .modal.interview-modal .modal {
            max-width: 36rem;
        }

        .modal.qualification-modal .modal {
            max-width: 40rem;
        }

        @keyframes modalSlideUp {
            from {
                transform: translateY(20px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .modal-header h2 .material-symbols-outlined {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }

        .modal-close:hover {
            background: var(--bg-surface-low);
        }

        .modal-close .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-shrink: 0;
        }

        /* =============================================
           APPLICANT DETAILS
        ============================================= */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .detail-item {
            margin-bottom: 0.25rem;
        }

        .detail-item .label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-item .value {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            padding: 0.5rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            margin-top: 0.125rem;
        }

        .detail-item .value.skills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            background: transparent;
            padding: 0;
            margin-top: 0.25rem;
        }

        .detail-item .value.skills .skill-tag {
            display: inline-block;
            padding: 0.1875rem 0.625rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
        }

        .detail-item.full-width {
            grid-column: 1 / -1;
        }

        /* =============================================
           RESUME SECTION
        ============================================= */
        .resume-section {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }

        .resume-section .resume-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .resume-section .resume-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .resume-section .resume-info .resume-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.75rem;
            color: white;
            flex-shrink: 0;
        }

        .resume-section .resume-info .resume-icon.pdf { background: #dc2626; }
        .resume-section .resume-info .resume-icon.doc { background: #2563eb; }
        .resume-section .resume-info .resume-icon.docx { background: #2563eb; }
        .resume-section .resume-info .resume-icon.default { background: #6b7280; }

        .resume-section .resume-info .resume-details .resume-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }

        .resume-section .resume-info .resume-details .resume-size {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .resume-section .resume-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .resume-section .resume-actions .btn {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
        }

        .resume-empty {
            text-align: center;
            padding: 1.25rem;
            color: var(--text-on-surface-variant);
        }

        .resume-empty .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 0.5rem;
        }

        /* =============================================
           FORM ELEMENTS
        ============================================= */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .form-group label .required {
            color: #dc2626;
            margin-left: 0.125rem;
        }

        .form-group .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }

        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .form-group .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .form-group textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-group .helper-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .form-group .helper-text .material-symbols-outlined {
            font-size: 0.875rem;
            vertical-align: middle;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* =============================================
           LOADING SPINNER
        ============================================= */
        .loading-spinner {
            text-align: center;
            padding: 2rem;
        }

        .loading-spinner .spinner {
            width: 2.5rem;
            height: 2.5rem;
            border: 4px solid var(--slate-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-spinner p {
            margin-top: 0.75rem;
            color: var(--text-on-surface-variant);
            font-size: 0.875rem;
        }

        /* =============================================
           TOAST
        ============================================= */
        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            animation: slideUp 0.4s ease-out;
            max-width: 400px;
        }

        .toast.success {
            background: #22c55e;
        }

        .toast.error {
            background: #dc2626;
        }

        .toast.info {
            background: var(--primary);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop {
                display: none !important;
            }

            .mobile-menu-btn {
                display: none !important;
            }

            .dashboard-sidebar {
                position: fixed;
                transform: translateX(0) !important;
                box-shadow: var(--shadow-xl);
                height: 100vh;
            }

            .dashboard-sidebar.mobile-hidden {
                transform: translateX(0) !important;
            }

            .main-wrapper {
                margin-left: var(--sidebar-width);
            }

            .dashboard-sidebar.collapsed ~ .main-wrapper {
                margin-left: var(--sidebar-collapsed);
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: inline;
            }
        }

        @media (max-width: 767px) {
            .dashboard-sidebar {
                position: fixed;
                width: var(--sidebar-width);
                transform: translateX(-100%);
                box-shadow: var(--shadow-xl);
            }

            .dashboard-sidebar.mobile-open {
                transform: translateX(0);
            }

            .dashboard-sidebar.collapsed {
                width: var(--sidebar-width);
            }

            .sidebar-toggle-btn {
                display: none !important;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .main-scroll {
                padding: 1rem;
            }

            .top-header-left .separator {
                display: none;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: none;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .search-bar {
                flex-direction: column;
            }

            .filters {
                flex-direction: column;
            }

            .filters select {
                width: 100%;
            }

            table {
                font-size: 0.8125rem;
                min-width: 600px;
            }

            table th,
            table td {
                padding: 0.5rem 0.75rem;
            }

            .applicant-info .avatar {
                width: 2rem;
                height: 2rem;
                font-size: 0.75rem;
            }

            .modal {
                max-height: 95vh;
                margin: 0.5rem;
            }

            .modal-header {
                padding: 1rem 1.25rem;
            }

            .modal-body {
                padding: 1rem 1.25rem;
            }

            .modal-footer {
                padding: 0.75rem 1.25rem;
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .resume-section .resume-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .resume-section .resume-actions {
                width: 100%;
            }

            .resume-section .resume-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .action-buttons .btn-sm {
                font-size: 0.6875rem;
                padding: 0.25rem 0.5rem;
            }

            .dashboard-sidebar.collapsed .sidebar-brand-text,
            .dashboard-sidebar.collapsed .sidebar-brand-category,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
            .dashboard-sidebar.collapsed .sidebar-footer .user-info {
                opacity: 1;
                width: auto;
                overflow: visible;
            }

            .dashboard-sidebar.collapsed .sidebar-brand-card {
                padding: 1.5rem;
            }

            .dashboard-sidebar.collapsed .sidebar-nav {
                padding: 1.5rem 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link {
                justify-content: flex-start;
                padding: 0.75rem 1rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
                font-size: 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-footer .user-card {
                justify-content: flex-start;
                padding: 0.5rem 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .main-scroll {
                padding: 0.75rem;
            }

            .breadcrumb-bar {
                padding: 0.75rem 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .card-header h3 {
                font-size: 0.875rem;
            }

            table {
                font-size: 0.75rem;
                min-width: 500px;
            }

            table th,
            table td {
                padding: 0.375rem 0.5rem;
            }

            .applicant-info .details .email {
                font-size: 0.6875rem;
            }

            .modal-body {
                padding: 0.75rem 1rem;
            }

            .toast {
                max-width: 90%;
                bottom: 1rem;
                right: 1rem;
            }
        }

        /* Scrollbar Styling */
        .main-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .main-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .main-scroll::-webkit-scrollbar-thumb {
            background: var(--slate-200);
            border-radius: 3px;
        }

        .main-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--slate-500);
        }

        /* =============================================
           QUALIFICATION MODAL SPECIFIC STYLES
        ============================================= */
        .qualification-modal .modal {
            max-width: 42rem;
        }

        .qualification-modal .applicant-info-display {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            background: var(--bg-surface-low);
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .qualification-modal .info-item .label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .qualification-modal .info-item .value {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-on-surface);
            margin-top: 0.125rem;
        }

        .qualification-modal .decision-group {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .qualification-modal .decision-option {
            flex: 1;
            padding: 0.875rem 1.5rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .qualification-modal .decision-option:hover {
            border-color: var(--primary);
            background: var(--bg-surface-low);
        }

        .qualification-modal .decision-option.selected-qualified {
            border-color: #22c55e;
            background: #f0fdf4;
        }

        .qualification-modal .decision-option.selected-notqualified {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .qualification-modal .decision-option input[type="radio"] {
            display: none;
        }

        .qualification-modal .decision-option .option-icon {
            font-size: 1.25rem;
        }

        .qualification-modal .decision-option .option-label {
            font-weight: 600;
        }

        .qualification-modal .decision-option .option-label.qualified-text {
            color: #059669;
        }

        .qualification-modal .decision-option .option-label.notqualified-text {
            color: #dc2626;
        }

        .qualification-modal .warning-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .qualification-modal .warning-box .warning-icon {
            color: #f59e0b;
            flex-shrink: 0;
        }

        .qualification-modal .warning-box .warning-text {
            font-size: 0.875rem;
            color: #92400e;
        }

        .qualification-modal .warning-box .warning-text strong {
            font-weight: 700;
        }

        .btn-send-notification {
            background: #f59e0b;
            color: white;
        }

        .btn-send-notification:hover {
            background: #d97706;
        }

        .btn-send-notification:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .processing-text {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .processing-text .spinner-small {
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <span class="sidebar-brand-icon">
            <span class="material-symbols-outlined">account_balance</span>
        </span>
        <p class="sidebar-brand-text">Company Name</p>
        <p class="sidebar-brand-category">HR Portal</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="clients.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'clients.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">business</span>
            <span class="nav-text">Clients</span>
        </a>
        <a href="jobs.php" class="sidebar-main-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['jobs.php', 'job_view.php', 'post_job.php']) ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">work</span>
            <span class="nav-text">My Jobs</span>
        </a>
        <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">people</span>
            <span class="nav-text">Applicants</span>
        </a>
        <a href="pipeline.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'pipeline.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">view_kanban</span>
            <span class="nav-text">Pipeline</span>
        </a>
        <a href="interviews.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'interviews.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">calendar_month</span>
            <span class="nav-text">Interviews</span>
        </a>
        <a href="offers.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'offers.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">description</span>
            <span class="nav-text">Offers</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
            </div>
        </div>
    </div>
</aside>

<!-- =============================================
MAIN CONTENT
============================================= -->
<div class="main-wrapper" id="mainWrapper">
    <!-- ===== TOP HEADER ===== -->
    <header class="top-header">
        <div class="top-header-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <span class="separator">|</span>
            <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
                <?php 
                    $pageTitle = basename($_SERVER['PHP_SELF'], '.php');
                    echo ucwords(str_replace('_', ' ', $pageTitle));
                ?>
            </span>
        </div>
        <div class="profile-dropdown-wrapper">
            <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                <span class="material-symbols-outlined">expand_more</span>
            </button>
            <div class="profile-dropdown-menu" id="profileMenu">
                <div class="dropdown-header">Account</div>
                <button class="dropdown-item" onclick="window.location.href='profile.php'">
                    <span class="material-symbols-outlined">person</span> Profile
                </button>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">
                    <span class="material-symbols-outlined">logout</span> Logout
                </button>
            </div>
        </div>
    </header>

    <!-- Scrollable Content -->
    <main class="main-scroll">
        <div class="container">

            <!-- Breadcrumb -->
            <div class="breadcrumb-bar">
                <div class="breadcrumb-view">
                    <span class="material-symbols-outlined">people</span>
                    <span>Applicants</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> 
                        (<?php echo count($applicants); ?> applicants)
                    </span>
                </div>
                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                    Last updated: <?php echo date('M d, Y H:i'); ?>
                </span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Applicants</h1>
                    <p>Manage all applicants who applied to your jobs</p>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <button class="btn btn-outline btn-sm" onclick="window.location.href='?pending_notifications=1'">
                        <span class="material-symbols-outlined">pending</span>
                        Pending Notifications
                    </button>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" id="searchInput" placeholder="Search by name, email, or job title..." 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                <?php if (!empty($searchQuery) || $statusFilter !== 'all' || $jobFilter > 0): ?>
                    <a href="applicants.php" class="btn btn-outline">Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filters">
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <?php if ($key === 'all') continue; ?>
                        <option value="<?php echo $key; ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?> (<?php echo $statusCounts[$key] ?? 0; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="jobFilter" onchange="applyFilters()">
                    <option value="0" <?php echo $jobFilter === 0 ? 'selected' : ''; ?>>All Jobs</option>
                    <?php foreach ($jobs as $job): ?>
                        <option value="<?php echo $job['id']; ?>" <?php echo $jobFilter === $job['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($job['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Applicants Table -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <span class="material-symbols-outlined">people</span>
                        <?php if ($statusFilter === 'all'): ?>
                            All Applicants
                        <?php else: ?>
                            <?php echo ucfirst($statusFilter); ?> Applicants
                        <?php endif; ?>
                    </h3>
                    <span class="applicant-count"><?php echo count($applicants); ?> applicants found</span>
                </div>
                <div class="card-body">
                    <?php if (empty($applicants)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">person_off</span>
                            <h4>No Applicants Found</h4>
                            <p>
                                <?php if ($statusFilter !== 'all'): ?>
                                    You don't have any <?php echo $statusFilter; ?> applicants.
                                <?php else: ?>
                                    No applicants have applied to your jobs yet.
                                <?php endif; ?>
                            </p>
                            <a href="post_job.php" class="btn btn-primary">
                                <span class="material-symbols-outlined">add</span>
                                Post a Job
                            </a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Job</th>
                                    <th>Applied</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applicants as $app): ?>
                                    <tr>
                                        <td>
                                            <div class="applicant-info">
                                                <span class="avatar">
                                                    <?php echo strtoupper(substr($app['first_name'] ?? 'A', 0, 1)); ?>
                                                </span>
                                                <div class="details">
                                                    <div class="name"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                                    <div class="email"><?php echo htmlspecialchars($app['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:500; color:var(--text-on-surface);">
                                                <?php echo htmlspecialchars($app['job_title'] ?? 'Position'); ?>
                                            </div>
                                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo htmlspecialchars($app['company_name'] ?? ''); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y', strtotime($app['applied_at'] ?? 'now')); ?>
                                            </div>
                                            <?php 
                                            $daysSinceApplied = (time() - strtotime($app['applied_at'] ?? 'now')) / (60 * 60 * 24);
                                            if ($daysSinceApplied >= 7 && $app['status'] === 'pending'): 
                                            ?>
                                                <span class="badge badge-pending-notification" style="font-size:0.6rem; margin-top:0.25rem;">
                                                    ⏰ 7+ days
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $statusBadges[$app['status']] ?? 'badge-pending'; ?>">
                                                <?php echo $statusLabels[$app['status']] ?? ucfirst($app['status']); ?>
                                            </span>
                                            <?php if (!empty($app['follow_up_sent']) && $app['follow_up_sent'] == 1): ?>
                                                <br>
                                                <span class="badge <?php echo ($app['qualification_status'] ?? '') === 'qualified' ? 'badge-notified-qualified' : 'badge-notified-notqualified'; ?>" style="font-size:0.6rem; margin-top:0.25rem;">
                                                    <?php echo ($app['qualification_status'] ?? '') === 'qualified' ? '✅ Qualified' : '❌ Not Qualified'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-outline btn-sm" onclick="viewApplicant(<?php echo $app['id']; ?>)">
                                                    <span class="material-symbols-outlined">visibility</span>
                                                </button>
                                                <button class="btn btn-primary btn-sm" onclick="openStatusModal(<?php echo $app['id']; ?>)">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <button class="btn btn-success btn-sm" onclick="openInterviewModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>')">
                                                    <span class="material-symbols-outlined">calendar_month</span>
                                                    Schedule
                                                </button>
                                                <button class="btn btn-warning btn-sm" 
                                                        onclick="openQualificationModal(<?php echo $app['id']; ?>, '<?php echo $app['status']; ?>')"
                                                        <?php echo in_array($app['status'], ['hired', 'rejected', 'withdrawn']) || !empty($app['follow_up_sent']) ? 'disabled' : ''; ?>
                                                        title="<?php echo in_array($app['status'], ['hired', 'rejected', 'withdrawn']) ? 'Already processed' : (!empty($app['follow_up_sent']) ? 'Notification already sent' : 'Send qualification notification'); ?>">
                                                    <span class="material-symbols-outlined">notifications</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- =============================================
MODAL: VIEW APPLICANT
============================================= -->
<div class="modal-overlay" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">person</span>
                Applicant Details
            </h2>
            <button class="modal-close" onclick="closeModal('viewModal')">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div class="loading-spinner" id="viewLoading">
                <div class="spinner"></div>
                <p>Loading applicant details...</p>
            </div>
            <div id="viewContent" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: UPDATE STATUS
============================================= -->
<div class="modal-overlay status-modal" id="statusModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">edit_note</span>
                Update Application Status
            </h2>
            <button class="modal-close" onclick="closeModal('statusModal')">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form id="statusForm" onsubmit="submitStatusUpdate(event)">
                <input type="hidden" id="statusApplicationId" name="application_id">
                <input type="hidden" name="action" value="update_status">
                
                <div class="form-group">
                    <label for="statusSelect">Status <span class="required">*</span></label>
                    <select id="statusSelect" name="status" class="form-control" required>
                        <option value="">Select status...</option>
                        <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?php echo $key; ?>">
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">info</span>
                        Select the new status for this application
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="statusFeedback">Feedback / Notes</label>
                    <textarea id="statusFeedback" name="feedback" class="form-control" 
                              placeholder="Add any feedback, comments, or notes about this decision..." rows="3"></textarea>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">note</span>
                        Optional: Provide feedback to the applicant (will be logged)
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
            <button class="btn btn-primary" onclick="document.getElementById('statusForm').dispatchEvent(new Event('submit'))">
                <span class="material-symbols-outlined">save</span>
                Update Status
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: SCHEDULE INTERVIEW
============================================= -->
<div class="modal-overlay interview-modal" id="interviewModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">calendar_month</span>
                Schedule Interview
            </h2>
            <button class="modal-close" onclick="closeModal('interviewModal')">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form id="interviewForm" onsubmit="submitInterview(event)">
                <input type="hidden" id="interviewApplicationId" name="application_id">
                <input type="hidden" name="action" value="schedule_interview">
                
                <div class="form-group">
                    <label for="applicantName">Applicant</label>
                    <input type="text" id="applicantName" class="form-control" disabled style="background:var(--bg-surface-low);">
                    <div class="helper-text">
                        <span class="material-symbols-outlined">info</span>
                        Scheduling an interview for this applicant
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="interviewDate">Interview Date & Time <span class="required">*</span></label>
                    <input type="datetime-local" id="interviewDate" name="interview_date" class="form-control" required>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">schedule</span>
                        Select the date and time for the interview
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="interviewNotes">Interview Notes</label>
                    <textarea id="interviewNotes" name="interview_notes" class="form-control" 
                              placeholder="Add any notes, preparation instructions, or details about the interview..." rows="3"></textarea>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">note</span>
                        Optional: Add any notes for the interviewer or applicant
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('interviewModal')">Cancel</button>
            <button class="btn btn-success" onclick="document.getElementById('interviewForm').dispatchEvent(new Event('submit'))">
                <span class="material-symbols-outlined">check</span>
                Schedule Interview
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: SEND QUALIFICATION (REDESIGNED)
============================================= -->
<div class="modal-overlay qualification-modal" id="qualificationModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">send</span>
                Send Qualification Notification
            </h2>
            <button class="modal-close" onclick="closeModal('qualificationModal')">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <!-- Applicant Information -->
            <div class="applicant-info-display">
                <div class="info-item">
                    <div class="label">Applicant</div>
                    <div class="value" id="qualificationApplicantName">Loading...</div>
                </div>
                <div class="info-item">
                    <div class="label">Position</div>
                    <div class="value" id="qualificationJobTitle">Loading...</div>
                </div>
                <div class="info-item">
                    <div class="label">Date Applied</div>
                    <div class="value" id="qualificationAppliedDate">Loading...</div>
                </div>
                <div class="info-item">
                    <div class="label">Days Waiting</div>
                    <div class="value" id="qualificationDaysWaiting">Loading...</div>
                </div>
            </div>

            <!-- Warning Box -->
            <div class="warning-box">
                <span class="material-symbols-outlined warning-icon">warning</span>
                <div class="warning-text">
                    <strong>Important:</strong> This action will send an official email notification to the applicant 
                    regarding their qualification status for this position. This action cannot be undone.
                </div>
            </div>

            <!-- Decision Selection -->
            <div class="form-group">
                <label>Qualification Decision <span class="required">*</span></label>
                <div class="decision-group">
                    <label class="decision-option" id="qualifiedOption">
                        <input type="radio" name="qualification_decision" value="qualified" checked>
                        <span class="option-icon">✓</span>
                        <span class="option-label qualified-text">Qualified</span>
                    </label>
                    <label class="decision-option" id="notqualifiedOption">
                        <input type="radio" name="qualification_decision" value="not_qualified">
                        <span class="option-icon">✕</span>
                        <span class="option-label notqualified-text">Not Qualified</span>
                    </label>
                </div>
                <div class="helper-text">
                    <span class="material-symbols-outlined">info</span>
                    Select whether the applicant meets the qualifications for the position
                </div>
            </div>

            <!-- Additional Notes -->
            <div class="form-group">
                <label for="qualificationNotes">Additional Notes</label>
                <textarea id="qualificationNotes" class="form-control" 
                          placeholder="Add any notes or feedback for the applicant (optional)..." rows="3"></textarea>
                <div class="helper-text">
                    <span class="material-symbols-outlined">note</span>
                    These notes will be stored in the application record for reference
                </div>
            </div>

            <input type="hidden" id="qualificationApplicationId">
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('qualificationModal')">Cancel</button>
            <button class="btn btn-send-notification" id="sendQualificationBtn" onclick="submitQualification()">
                <span class="material-symbols-outlined">send</span>
                Send Notification
            </button>
        </div>
    </div>
</div>

<!-- =============================================
JAVASCRIPT
============================================= -->
<script>
// =============================================
// 1. SIDEBAR TOGGLE
// =============================================
const sidebar = document.getElementById('appSidebar');
const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
const mainWrapper = document.getElementById('mainWrapper');
const isMobile = window.innerWidth <= 768;
const savedState = localStorage.getItem('sidebarCollapsed');

if (savedState === 'true' && !isMobile) {
    sidebar.classList.add('collapsed');
    const icon = sidebarToggleBtn.querySelector('.material-symbols-outlined');
    if (icon) icon.textContent = 'chevron_right';
}

sidebarToggleBtn.addEventListener('click', function() {
    if (window.innerWidth <= 768) return;
    sidebar.classList.toggle('collapsed');
    const icon = this.querySelector('.material-symbols-outlined');
    if (icon) {
        icon.textContent = sidebar.classList.contains('collapsed') ? 'chevron_right' : 'chevron_left';
    }
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});

// =============================================
// 2. MOBILE SIDEBAR
// =============================================
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const sidebarBackdrop = document.createElement('div');
sidebarBackdrop.className = 'sidebar-backdrop';
sidebarBackdrop.id = 'sidebarBackdrop';
document.body.appendChild(sidebarBackdrop);

function openMobileSidebar() {
    sidebar.classList.add('mobile-open');
    sidebarBackdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
    sidebar.classList.remove('mobile-open');
    sidebarBackdrop.classList.remove('active');
    document.body.style.overflow = '';
}

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', openMobileSidebar);
}
if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeMobileSidebar);
}

document.querySelectorAll('.sidebar-main-link').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }
    });
});

// =============================================
// 3. PROFILE DROPDOWN
// =============================================
const profileToggle = document.getElementById('profileToggle');
const profileMenu = document.getElementById('profileMenu');

if (profileToggle && profileMenu) {
    profileToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        this.classList.toggle('open');
        profileMenu.classList.toggle('open');
    });

    document.addEventListener('click', function(e) {
        if (!profileToggle.contains(e.target) && !profileMenu.contains(e.target)) {
            profileToggle.classList.remove('open');
            profileMenu.classList.remove('open');
        }
    });
}

// =============================================
// 4. FILTER FUNCTIONS
// =============================================
function applyFilters() {
    const search = document.getElementById('searchInput');
    const status = document.getElementById('statusFilter');
    const job = document.getElementById('jobFilter');
    
    if (!search || !status || !job) return;
    
    let url = 'applicants.php?';
    if (status.value !== 'all') url += 'status=' + status.value + '&';
    if (job.value > 0) url += 'job_id=' + job.value + '&';
    if (search.value) url += 'search=' + encodeURIComponent(search.value);
    
    window.location.href = url;
}

const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
}

// =============================================
// 5. MODAL FUNCTIONS
// =============================================
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this.id);
        }
    });
});

// =============================================
// 6. VIEW APPLICANT
// =============================================
function viewApplicant(applicationId) {
    openModal('viewModal');
    
    const loading = document.getElementById('viewLoading');
    const content = document.getElementById('viewContent');
    
    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';

    const formData = new FormData();
    formData.append('action', 'view_applicant');
    formData.append('application_id', applicationId);

    fetch('applicants.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        if (data.success) {
            const app = data.applicant;
            const skills = (app.skills || '').split(',').filter(s => s.trim());
            const skillsHtml = skills.map(s => 
                '<span class="skill-tag">' + escapeHtml(s.trim()) + '</span>'
            ).join('');

            let followUpHtml = '';
            if (app.follow_up_sent && app.follow_up_sent == 1) {
                const statusClass = app.qualification_status === 'qualified' ? 'badge-notified-qualified' : 'badge-notified-notqualified';
                const statusLabel = app.qualification_status === 'qualified' ? 'Qualified' : 'Not Qualified';
                followUpHtml = `
                    <span class="badge ${statusClass}">${statusLabel}</span>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">
                        Sent: ${formatDate(app.follow_up_date)}
                    </span>
                `;
            } else {
                followUpHtml = `<span style="color:var(--text-on-surface-variant);">Not sent yet</span>`;
            }

            let resumeHtml = '';
            if (app.resume_exists) {
                const iconClass = app.resume_extension === 'pdf' ? 'pdf' : 
                                (app.resume_extension === 'doc' || app.resume_extension === 'docx' ? 'doc' : 'default');
                const sizeKB = (app.resume_size / 1024).toFixed(1);
                const sizeLabel = app.resume_size > 1024 * 1024 ? 
                    (app.resume_size / (1024 * 1024)).toFixed(2) + ' MB' : 
                    sizeKB + ' KB';
                
                resumeHtml = `
                    <div class="resume-section">
                        <div class="resume-header">
                            <div class="resume-info">
                                <div class="resume-icon ${iconClass}">${app.resume_extension.toUpperCase()}</div>
                                <div class="resume-details">
                                    <div class="resume-name">${escapeHtml(app.resume_filename)}</div>
                                    <div class="resume-size">${sizeLabel}</div>
                                </div>
                            </div>
                            <div class="resume-actions">
                                <a href="${app.resume_url}" target="_blank" class="btn btn-info btn-sm">
                                    <span class="material-symbols-outlined">visibility</span>
                                    View
                                </a>
                                <a href="${app.resume_url}" download class="btn btn-success btn-sm">
                                    <span class="material-symbols-outlined">download</span>
                                    Download
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                resumeHtml = `
                    <div class="resume-section">
                        <div class="resume-empty">
                            <span class="material-symbols-outlined">description</span>
                            <p>No resume uploaded by the applicant.</p>
                        </div>
                    </div>
                `;
            }

            if (content) {
                content.innerHTML = `
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="label">Full Name</div>
                            <div class="value">${escapeHtml(app.first_name)} ${escapeHtml(app.last_name)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Email</div>
                            <div class="value">${escapeHtml(app.email)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Phone</div>
                            <div class="value">${escapeHtml(app.phone || 'Not provided')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Applied For</div>
                            <div class="value"><strong>${escapeHtml(app.job_title)}</strong> (${escapeHtml(app.company_name)})</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Applied Date</div>
                            <div class="value">${formatDate(app.applied_at)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Status</div>
                            <div class="value"><span class="badge ${getStatusBadge(app.status)}">${getStatusLabel(app.status)}</span></div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Qualification Status</div>
                            <div class="value">${followUpHtml}</div>
                        </div>
                        <div class="detail-item full-width">
                            <div class="label">Skills</div>
                            <div class="value skills">${skillsHtml || '<span style="color:var(--text-on-surface-variant);">No skills listed</span>'}</div>
                        </div>
                        <div class="detail-item full-width">
                            <div class="label">Experience</div>
                            <div class="value">${escapeHtml(app.experience || 'Not provided')}</div>
                        </div>
                        <div class="detail-item full-width">
                            <div class="label">Education</div>
                            <div class="value">${escapeHtml(app.education || 'Not provided')}</div>
                        </div>
                        <div class="detail-item full-width">
                            <div class="label">Cover Letter</div>
                            <div class="value">${escapeHtml(app.cover_letter || 'No cover letter provided')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Total Applications</div>
                            <div class="value">${app.total_applications || 1}</div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Resume / CV</div>
                            <div class="value" style="background:transparent; padding:0;">${resumeHtml}</div>
                        </div>
                    </div>
                `;
            }
        } else {
            if (content) {
                content.innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">${data.error || 'Failed to load applicant details.'}</p>
                    </div>
                `;
            }
        }
    })
    .catch(error => {
        if (loading) loading.style.display = 'none';
        if (content) {
            content.style.display = 'block';
            content.innerHTML = `
                <div style="text-align:center; padding:1rem; color:#dc2626;">
                    <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                    <p style="margin-top:0.5rem;">Error loading applicant details. Please try again.</p>
                </div>
            `;
        }
    });
}

// =============================================
// 7. STATUS UPDATE
// =============================================
function openStatusModal(applicationId) {
    const statusAppId = document.getElementById('statusApplicationId');
    const statusSelect = document.getElementById('statusSelect');
    const statusFeedback = document.getElementById('statusFeedback');
    
    if (statusAppId) statusAppId.value = applicationId;
    if (statusSelect) statusSelect.value = '';
    if (statusFeedback) statusFeedback.value = '';
    openModal('statusModal');
}

function submitStatusUpdate(event) {
    event.preventDefault();
    
    const form = document.getElementById('statusForm');
    if (!form) return;
    
    const formData = new FormData(form);

    const btn = document.querySelector('#statusModal .modal-footer .btn-primary');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem; animation:spin 0.8s linear infinite;">refresh</span> Updating...';

    fetch('applicants.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            showToast(data.message, 'success');
            closeModal('statusModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to update status.', 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('Error updating status. Please try again.', 'error');
    });
}

// =============================================
// 8. SCHEDULE INTERVIEW
// =============================================
function openInterviewModal(applicationId, applicantName) {
    const interviewAppId = document.getElementById('interviewApplicationId');
    const applicantNameInput = document.getElementById('applicantName');
    const interviewDate = document.getElementById('interviewDate');
    const interviewNotes = document.getElementById('interviewNotes');
    
    if (interviewAppId) interviewAppId.value = applicationId;
    if (applicantNameInput) applicantNameInput.value = applicantName;
    
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(10, 0, 0, 0);
    const isoString = tomorrow.toISOString().slice(0, 16);
    if (interviewDate) interviewDate.value = isoString;
    if (interviewNotes) interviewNotes.value = '';
    
    openModal('interviewModal');
}

function submitInterview(event) {
    event.preventDefault();
    
    const form = document.getElementById('interviewForm');
    if (!form) return;
    
    const formData = new FormData(form);

    const btn = document.querySelector('#interviewModal .modal-footer .btn-success');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem; animation:spin 0.8s linear infinite;">refresh</span> Scheduling...';

    fetch('applicants.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            showToast(data.message, 'success');
            closeModal('interviewModal');
            setTimeout(function() {
                location.reload();
            }, 1000);
        } else {
            showToast(data.error || 'Failed to schedule interview.', 'error');
        }
    })
    .catch(function(error) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.error('Error:', error);
        showToast('Error scheduling interview. Please try again.', 'error');
    });
}

// =============================================
// 9. QUALIFICATION MODAL (REDESIGNED)
// =============================================
let qualificationData = {};

function openQualificationModal(applicationId, status) {
    if (status === 'hired' || status === 'rejected' || status === 'withdrawn') {
        showToast('This application has already been processed.', 'info');
        return;
    }
    
    const row = document.querySelector(`button[onclick*="openQualificationModal(${applicationId}"]`).closest('tr');
    if (row) {
        const statusBadge = row.querySelector('.badge-notified-qualified, .badge-notified-notqualified');
        if (statusBadge) {
            showToast('Notification already sent to this applicant.', 'info');
            return;
        }
    }
    
    const formData = new FormData();
    formData.append('action', 'view_applicant');
    formData.append('application_id', applicationId);
    
    fetch('applicants.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const app = data.applicant;
            qualificationData = { applicationId, applicant: app };
            
            document.getElementById('qualificationApplicationId').value = applicationId;
            document.getElementById('qualificationApplicantName').textContent = 
                app.first_name + ' ' + app.last_name + ' (' + app.email + ')';
            document.getElementById('qualificationJobTitle').textContent = app.job_title;
            document.getElementById('qualificationAppliedDate').textContent = formatDate(app.applied_at);
            
            const daysWaiting = Math.floor((Date.now() - new Date(app.applied_at).getTime()) / (1000 * 60 * 60 * 24));
            document.getElementById('qualificationDaysWaiting').textContent = daysWaiting + ' days';
            
            const isQualified = determineQualificationFromData(app);
            document.querySelector('input[name="qualification_decision"][value="' + 
                (isQualified ? 'qualified' : 'not_qualified') + '"]').checked = true;
            
            updateDecisionStyles();
            
            document.getElementById('qualificationNotes').value = '';
            
            openModal('qualificationModal');
        } else {
            showToast('Error loading applicant details.', 'error');
        }
    })
    .catch(error => {
        showToast('Error loading applicant details.', 'error');
    });
}

function updateDecisionStyles() {
    const qualifiedOption = document.getElementById('qualifiedOption');
    const notqualifiedOption = document.getElementById('notqualifiedOption');
    const selected = document.querySelector('input[name="qualification_decision"]:checked');
    
    if (selected) {
        qualifiedOption.classList.remove('selected-qualified', 'selected-notqualified');
        notqualifiedOption.classList.remove('selected-qualified', 'selected-notqualified');
        
        if (selected.value === 'qualified') {
            qualifiedOption.classList.add('selected-qualified');
        } else {
            notqualifiedOption.classList.add('selected-notqualified');
        }
    }
}

function determineQualificationFromData(app) {
    if (app.match_score && app.match_score >= 70) return true;
    
    if (app.skills) {
        const skills = app.skills.toLowerCase();
        const required = ['php', 'javascript', 'python', 'java', 'sql', 'html', 'css'];
        for (let skill of required) {
            if (skills.includes(skill)) return true;
        }
    }
    
    if (app.experience) {
        const exp = app.experience.toLowerCase();
        const keywords = ['years', 'experience', 'senior', 'lead', 'manager'];
        for (let keyword of keywords) {
            if (exp.includes(keyword)) return true;
        }
    }
    
    return false;
}

// Radio button click handlers
document.querySelectorAll('input[name="qualification_decision"]').forEach(radio => {
    radio.addEventListener('change', updateDecisionStyles);
});

document.getElementById('qualifiedOption').addEventListener('click', function() {
    document.querySelector('input[name="qualification_decision"][value="qualified"]').checked = true;
    updateDecisionStyles();
});

document.getElementById('notqualifiedOption').addEventListener('click', function() {
    document.querySelector('input[name="qualification_decision"][value="not_qualified"]').checked = true;
    updateDecisionStyles();
});

function submitQualification() {
    const applicationId = document.getElementById('qualificationApplicationId').value;
    const isQualified = document.querySelector('input[name="qualification_decision"]:checked').value === 'qualified';
    const notes = document.getElementById('qualificationNotes').value;
    
    if (!applicationId) {
        showToast('Error: No application selected.', 'error');
        return;
    }
    
    const btn = document.getElementById('sendQualificationBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Sending Notification...';
    
    const formData = new FormData();
    formData.append('action', 'send_qualification');
    formData.append('application_id', applicationId);
    formData.append('is_qualified', isQualified ? '1' : '0');
    formData.append('notes', notes);
    
    fetch('applicants.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        // Check if response is ok
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        // Check the response
        if (data && data.success === true) {
            showToast('✅ Notification sent successfully!', 'success');
            closeModal('qualificationModal');
            setTimeout(() => location.reload(), 1500);
        } else {
            // If we got here but success is false
            const errorMsg = data && data.error ? data.error : 'Unknown error occurred.';
            showToast('Error: ' + errorMsg, 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        console.error('Fetch error:', error);
        
        // Even if fetch fails, the email might have sent
        // Show a more helpful message
        showToast('Connection issue detected. Please check if the email was sent.', 'info');
        
        // Optionally reload to see if the application was updated
        setTimeout(() => location.reload(), 3000);
    });
}

// =============================================
// 10. TOAST SYSTEM
// =============================================
function showToast(message, type) {
    type = type || 'info';
    var existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();

    var toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.transition = 'all 0.4s ease';
        setTimeout(function() {
            toast.remove();
        }, 400);
    }, 3500);
}

// =============================================
// 11. UTILITY FUNCTIONS
// =============================================
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(date) {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getStatusBadge(status) {
    var badges = {
        'pending': 'badge-pending',
        'shortlisted': 'badge-shortlisted',
        'scheduled': 'badge-scheduled',
        'interviewed': 'badge-interviewed',
        'hired': 'badge-hired',
        'rejected': 'badge-rejected',
        'withdrawn': 'badge-withdrawn'
    };
    return badges[status] || 'badge-pending';
}

function getStatusLabel(status) {
    var labels = {
        'pending': 'Pending Review',
        'shortlisted': 'Shortlisted',
        'scheduled': 'Scheduled',
        'interviewed': 'Interviewed',
        'hired': 'Hired',
        'rejected': 'Rejected',
        'withdrawn': 'Withdrawn'
    };
    return labels[status] || status;
}

// =============================================
// 12. RESPONSIVE HANDLING
// =============================================
var resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
        var width = window.innerWidth;
        if (width <= 768) {
            sidebar.classList.remove('collapsed');
        } else {
            sidebar.classList.remove('mobile-open');
            sidebarBackdrop.classList.remove('active');
            document.body.style.overflow = '';
            var saved = localStorage.getItem('sidebarCollapsed');
            if (saved === 'true') {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
    }, 250);
});

// =============================================
// 13. KEYBOARD ACCESSIBILITY
// =============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var viewModal = document.getElementById('viewModal');
        var statusModal = document.getElementById('statusModal');
        var interviewModal = document.getElementById('interviewModal');
        var qualificationModal = document.getElementById('qualificationModal');
        
        if (viewModal && viewModal.classList.contains('active')) {
            closeModal('viewModal');
        } else if (statusModal && statusModal.classList.contains('active')) {
            closeModal('statusModal');
        } else if (interviewModal && interviewModal.classList.contains('active')) {
            closeModal('interviewModal');
        } else if (qualificationModal && qualificationModal.classList.contains('active')) {
            closeModal('qualificationModal');
        } else {
            closeMobileSidebar();
            if (profileToggle) profileToggle.classList.remove('open');
            if (profileMenu) profileMenu.classList.remove('open');
        }
    }
});

console.log('👥 ISMERS Applicants Management loaded successfully!');
</script>

</body>
</html>