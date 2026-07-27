<?php
// portals/hr/interviews.php - Interview Management (SIMPLIFIED & WORKING)
session_start();

require_once '../../app/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';

// =============================================
// FUNCTION: Get Interview Details
// =============================================
function getInterviewDetails($interviewId) {
    global $userId, $conn;
    $sql = "SELECT i.*, 
                   u.id as user_id, u.first_name, u.last_name, u.email,
                   jo.title as job_title, c.company_name,
                   a.id as application_id
            FROM interviews i
            JOIN applications a ON i.application_id = a.id
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            WHERE i.id = '$interviewId' AND jo.created_by = '$userId'";
    
    $result = mysqli_query($conn, $sql);
    if ($result) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// =============================================
// SIMPLIFIED: Direct SQL queries
// =============================================
// First, get all job IDs created by this user
$jobIdsSql = "SELECT id FROM job_orders WHERE created_by = '$userId'";
$jobIdsResult = mysqli_query($conn, $jobIdsSql);
$jobIds = [];
while ($row = mysqli_fetch_assoc($jobIdsResult)) {
    $jobIds[] = $row['id'];
}

// If the user has no jobs, show empty state
if (empty($jobIds)) {
    $interviews = [];
    $statusCounts = ['all' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0, 'rescheduled' => 0, 'no_show' => 0];
    $upcomingCount = 0;
    $upcomingInterviews = [];
} else {
    $jobIdsString = implode(',', $jobIds);
    
    // Build the WHERE clause
    $whereClause = "a.job_order_id IN ($jobIdsString)";
    
    if ($statusFilter !== 'all') {
        $whereClause .= " AND i.status = '$statusFilter'";
    } else {
        $whereClause .= " AND i.status IN ('scheduled', 'completed')";
    }
    
    if (!empty($searchQuery)) {
        $searchParam = mysqli_real_escape_string($conn, $searchQuery);
        $whereClause .= " AND (u.first_name LIKE '%$searchParam%' OR u.last_name LIKE '%$searchParam%' OR u.email LIKE '%$searchParam%' OR jo.title LIKE '%$searchParam%')";
    }
    
    // Get interviews with applicant and job info - DIRECT SQL
    $sql = "SELECT i.*, 
            u.id as user_id, u.first_name, u.last_name, u.email,
            ap.profile_picture,
            jo.id as job_id, jo.title as job_title,
            c.company_name,
            a.status as application_status
            FROM interviews i
            JOIN applications a ON i.application_id = a.id
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            WHERE $whereClause
            ORDER BY i.interview_date ASC";
    
    $result = mysqli_query($conn, $sql);
    $interviews = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $interviews[] = $row;
    }
    
    // Get status counts
    $statusCounts = ['all' => count($interviews)];
    $statuses = ['scheduled', 'completed', 'cancelled', 'rescheduled', 'no_show'];
    foreach ($statuses as $status) {
        $countSql = "SELECT COUNT(*) as count FROM interviews i 
                     JOIN applications a ON i.application_id = a.id
                     JOIN job_orders jo ON a.job_order_id = jo.id 
                     WHERE jo.created_by = '$userId' AND i.status = '$status'";
        $countResult = mysqli_query($conn, $countSql);
        $countRow = mysqli_fetch_assoc($countResult);
        $statusCounts[$status] = $countRow['count'] ?? 0;
    }
    
    // Upcoming interviews count
    $upcomingSql = "SELECT COUNT(*) as count FROM interviews i 
                    JOIN applications a ON i.application_id = a.id
                    JOIN job_orders jo ON a.job_order_id = jo.id 
                    WHERE jo.created_by = '$userId' AND i.status = 'scheduled' AND i.interview_date >= NOW()";
    $upcomingResult = mysqli_query($conn, $upcomingSql);
    $upcomingRow = mysqli_fetch_assoc($upcomingResult);
    $upcomingCount = $upcomingRow['count'] ?? 0;
    
    // Get upcoming interviews for dashboard
    $upcomingSql = "SELECT i.*, u.first_name, u.last_name, u.email, jo.title as job_title
                    FROM interviews i
                    JOIN applications a ON i.application_id = a.id
                    JOIN applicants ap ON a.applicant_id = ap.id
                    JOIN users u ON ap.user_id = u.id
                    JOIN job_orders jo ON a.job_order_id = jo.id
                    WHERE jo.created_by = '$userId' AND i.status = 'scheduled' AND i.interview_date >= NOW()
                    ORDER BY i.interview_date ASC
                    LIMIT 5";
    $upcomingResult = mysqli_query($conn, $upcomingSql);
    $upcomingInterviews = [];
    while ($row = mysqli_fetch_assoc($upcomingResult)) {
        $upcomingInterviews[] = $row;
    }
}

// Status badge mapping
$statusBadges = [
    'scheduled' => 'badge-scheduled',
    'completed' => 'badge-completed',
    'cancelled' => 'badge-cancelled',
    'rescheduled' => 'badge-rescheduled',
    'no_show' => 'badge-no-show'
];

$statusLabels = [
    'scheduled' => 'Scheduled',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'rescheduled' => 'Rescheduled',
    'no_show' => 'No-Show'
];

$interviewTypes = ['online', 'onsite', 'phone', 'panel'];
$interviewTypeLabels = [
    'online' => 'Online (Video)',
    'onsite' => 'Onsite',
    'phone' => 'Phone Call',
    'panel' => 'Panel Interview'
];

$allStatuses = ['all' => 'All'] + $statusLabels;

// Get all jobs for filter dropdown
$jobsSql = "SELECT id, title FROM job_orders WHERE created_by = '$userId' ORDER BY created_at DESC";
$jobsResult = mysqli_query($conn, $jobsSql);
$jobs = [];
while ($row = mysqli_fetch_assoc($jobsResult)) {
    $jobs[] = $row;
}

// =============================================
// AJAX HANDLER
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $interviewId = isset($_POST['interview_id']) ? (int)$_POST['interview_id'] : 0;
    
    if ($action === 'schedule_interview') {
        $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
        $interviewDate = $_POST['interview_date'] ?? '';
        $interviewType = $_POST['interview_type'] ?? 'online';
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $interviewers = trim($_POST['interviewers'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($interviewDate)) {
            echo json_encode(['success' => false, 'error' => 'Please select an interview date and time.']);
            exit;
        }
        
        // Check if application exists
        $appSql = "SELECT id, status FROM applications WHERE id = '$applicationId'";
        $appResult = mysqli_query($conn, $appSql);
        $app = mysqli_fetch_assoc($appResult);
        if (!$app) {
            echo json_encode(['success' => false, 'error' => 'Application not found.']);
            exit;
        }
        
        // Check if interview already exists
        $existingSql = "SELECT id, status FROM interviews WHERE application_id = '$applicationId' AND status != 'completed'";
        $existingResult = mysqli_query($conn, $existingSql);
        if (mysqli_num_rows($existingResult) > 0) {
            echo json_encode(['success' => false, 'error' => 'This applicant already has a scheduled interview.']);
            exit;
        }
        
        // Convert date format
        $dbDateTime = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $interviewDate)));
        
        // Insert interview
        $sql = "INSERT INTO interviews (application_id, interview_date, interview_type, meeting_link, interviewers, notes, status, created_by) 
                VALUES ('$applicationId', '$dbDateTime', '$interviewType', '$meetingLink', '$interviewers', '$notes', 'scheduled', '$userId')";
        $result = mysqli_query($conn, $sql);
        
        if ($result) {
            $interviewId = mysqli_insert_id($conn);
            // Update application status to scheduled
            mysqli_query($conn, "UPDATE applications SET status = 'scheduled' WHERE id = '$applicationId'");
            
            // Log activity
            logActivity($userId, 'Interview Scheduled', 'interviews', $interviewId, 'Interview scheduled for application #' . $applicationId);
            
            echo json_encode(['success' => true, 'message' => 'Interview scheduled successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to schedule interview: ' . mysqli_error($conn)]);
        }
        exit;
    }
    
    if ($action === 'update_interview' && $interviewId > 0) {
        $interviewDate = $_POST['interview_date'] ?? '';
        $interviewType = $_POST['interview_type'] ?? 'online';
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $interviewers = trim($_POST['interviewers'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'scheduled';
        
        if (empty($interviewDate)) {
            echo json_encode(['success' => false, 'error' => 'Please select an interview date and time.']);
            exit;
        }
        
        $dbDateTime = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $interviewDate)));
        
        $sql = "UPDATE interviews SET 
                interview_date = '$dbDateTime',
                interview_type = '$interviewType',
                meeting_link = '$meetingLink',
                interviewers = '$interviewers',
                notes = '$notes',
                status = '$status',
                updated_at = NOW()
                WHERE id = '$interviewId'";
        $result = mysqli_query($conn, $sql);
        
        if ($result) {
            logActivity($userId, 'Interview Updated', 'interviews', $interviewId, 'Updated interview #' . $interviewId);
            echo json_encode(['success' => true, 'message' => 'Interview updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update interview.']);
        }
        exit;
    }
    
    if ($action === 'cancel_interview' && $interviewId > 0) {
        $sql = "UPDATE interviews SET status = 'cancelled', updated_at = NOW() WHERE id = '$interviewId'";
        $result = mysqli_query($conn, $sql);
        
        if ($result) {
            $interview = getInterviewDetails($interviewId);
            if ($interview) {
                mysqli_query($conn, "UPDATE applications SET status = 'shortlisted' WHERE id = '" . $interview['application_id'] . "'");
            }
            
            logActivity($userId, 'Interview Cancelled', 'interviews', $interviewId, 'Cancelled interview #' . $interviewId);
            echo json_encode(['success' => true, 'message' => 'Interview cancelled successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to cancel interview.']);
        }
        exit;
    }
    
    if ($action === 'complete_interview' && $interviewId > 0) {
        $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $feedback = trim($_POST['feedback'] ?? '');
        
        $sql = "UPDATE interviews SET 
                status = 'completed',
                rating = '$rating',
                feedback = '$feedback',
                updated_at = NOW()
                WHERE id = '$interviewId'";
        $result = mysqli_query($conn, $sql);
        
        if ($result) {
            $interview = getInterviewDetails($interviewId);
            if ($interview) {
                mysqli_query($conn, "UPDATE applications SET status = 'interviewed' WHERE id = '" . $interview['application_id'] . "'");
            }
            
            logActivity($userId, 'Interview Completed', 'interviews', $interviewId, 'Completed interview #' . $interviewId);
            echo json_encode(['success' => true, 'message' => 'Interview completed successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to complete interview.']);
        }
        exit;
    }
    
    if ($action === 'get_interview' && $interviewId > 0) {
        $interview = getInterviewDetails($interviewId);
        if ($interview) {
            echo json_encode(['success' => true, 'interview' => $interview]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Interview not found.']);
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
    <title>Interviews - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           INTERVIEWS MANAGEMENT - PROFESSIONAL EDITION
           ========================================================================== */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --bg-surface-container-low: #f5f6fa;
            --bg-surface-container-lowest: #ffffff;
            --bg-surface-container-high: #eef0f5;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --text-on-background: #0a0e1a;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
            --primary-container: #eef0ff;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 10px 10px -5px rgba(0, 0, 0, 0.02);
            --radius-sm: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.25rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-label: 'Public Sans', system-ui, -apple-system, sans-serif;
            --transition-fast: 0.15s ease;
            --transition-smooth: 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            --sidebar-width: 280px;
            --sidebar-collapsed: 72px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        a { text-decoration: none; color: inherit; }

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
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }
        .dashboard-sidebar.collapsed { width: var(--sidebar-collapsed); }
        .dashboard-sidebar.mobile-hidden { transform: translateX(-100%); }
        .dashboard-sidebar.mobile-open { transform: translateX(0); }

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
        .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1rem 0.5rem; }
        .dashboard-sidebar.collapsed .sidebar-nav { padding: 0.5rem 0.25rem; }
        .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: center; padding: 0.75rem 0.5rem; }
        .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.5rem; }
        .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: center; padding: 0.5rem; }
        .dashboard-sidebar.collapsed .sidebar-footer .user-card .avatar { width: 2.5rem; height: 2.5rem; font-size: 0.875rem; }

        .sidebar-brand-card {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.5rem;
        }
        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background: var(--primary-container);
            color: var(--primary);
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .sidebar-brand-icon .material-symbols-outlined { font-size: 1.5rem; }
        .sidebar-brand-text { font-size: 1rem; font-weight: 700; color: var(--slate-900); letter-spacing: -0.025em; }
        .sidebar-brand-category { font-size: 0.7rem; font-weight: 500; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.1rem; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 1rem 0.75rem; }
        .sidebar-nav .nav-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--slate-400);
            padding: 0.75rem 0.75rem 0.5rem;
        }
        .sidebar-main-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            margin-bottom: 0.125rem;
            font-family: var(--font-label);
            font-weight: 500;
            font-size: 0.875rem;
        }
        .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-main-link.active { background: var(--primary-container); color: var(--primary); }
        .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0.1rem 0.5rem;
            border-radius: 50px;
        }
        .sidebar-footer {
            padding: 0.75rem 0.75rem;
            border-top: 1px solid var(--slate-200);
        }
        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            background: var(--bg-surface-low);
        }
        .sidebar-footer .user-card .avatar {
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
        .sidebar-footer .user-card .user-info .user-name { font-size: 0.8125rem; font-weight: 600; color: var(--text-on-surface); }
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.6875rem; color: var(--text-on-surface-variant); }
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
            font-size: 0.8125rem;
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
        }
        .sidebar-footer .logout-btn:hover { background: #fef2f2; }
        .sidebar-footer .logout-btn .material-symbols-outlined { font-size: 1.125rem; }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(4px);
            z-index: 40;
            opacity: 0;
        }
        .sidebar-backdrop.active { display: block; opacity: 1; }

        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
        }
        .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }

        .top-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
            padding: 0 1.5rem;
            flex-shrink: 0;
            z-index: 30;
        }
        .top-header-left { display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.5rem;
            border: 1px solid var(--slate-200);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.25rem;
            min-height: 2.25rem;
        }
        .sidebar-toggle-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-toggle-btn .material-symbols-outlined { font-size: 1.25rem; }
        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.5rem;
            border: 1px solid var(--slate-200);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.25rem;
            min-height: 2.25rem;
        }
        .mobile-menu-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .mobile-menu-btn .material-symbols-outlined { font-size: 1.25rem; }

        .profile-dropdown-wrapper { position: relative; }
        .profile-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.25rem 0.75rem 0.25rem 0.25rem;
            border-radius: var(--radius-full);
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .profile-dropdown-toggle:hover { background: var(--bg-surface-low); border-color: var(--slate-200); }
        .profile-dropdown-toggle .avatar-small {
            width: 2rem;
            height: 2rem;
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
        .profile-dropdown-toggle .profile-name { font-size: 0.8125rem; font-weight: 600; color: var(--text-on-surface); }
        .profile-dropdown-toggle .profile-role { font-size: 0.6875rem; color: var(--text-on-surface-variant); font-weight: 400; }
        .profile-dropdown-toggle .material-symbols-outlined { font-size: 1rem; color: var(--text-on-surface-variant); transition: transform var(--transition-fast); }
        .profile-dropdown-toggle.open .material-symbols-outlined:last-child { transform: rotate(180deg); }
        .profile-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 13rem;
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--slate-200);
            padding: 0.5rem;
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-0.25rem) scale(0.97);
            transition: all var(--transition-smooth);
            transform-origin: top right;
        }
        .profile-dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown-menu .dropdown-header {
            padding: 0.25rem 0.75rem 0.25rem;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-on-surface-variant);
        }
        .profile-dropdown-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
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
        .profile-dropdown-menu .dropdown-item:hover { background: var(--bg-surface-low); color: var(--primary); }
        .profile-dropdown-menu .dropdown-item .material-symbols-outlined { font-size: 1.125rem; color: var(--text-on-surface-variant); }
        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined { color: var(--primary); }
        .profile-dropdown-menu .dropdown-item.danger { color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger:hover { background: #fef2f2; color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined { color: #dc2626; }
        .profile-dropdown-menu .dropdown-divider { height: 1px; background: var(--slate-200); margin: 0.25rem 0.5rem; }

        .main-scroll { flex: 1; overflow-y: auto; padding: 1.5rem 2rem; }
        .main-scroll .container { max-width: 96rem; margin: 0 auto; }

        .breadcrumb-bar {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 0.75rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-xs);
        }
        @media (min-width: 640px) {
            .breadcrumb-bar { border-radius: var(--radius-xl); flex-direction: row; align-items: center; justify-content: space-between; }
        }
        .breadcrumb-view {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 600;
        }
        .breadcrumb-view .material-symbols-outlined { font-size: 1rem; }
        .breadcrumb-view .status-dot {
            width: 0.375rem;
            height: 0.375rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .breadcrumb-meta { font-size: 0.75rem; color: var(--text-on-surface-variant); }

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) { .page-header { flex-direction: row; align-items: center; justify-content: space-between; } }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.125rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8125rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 1px 2px rgba(79, 70, 229, 0.15); }
        .btn-primary:hover { background: var(--on-primary-fixed-variant); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--primary-container); }
        .btn-ghost { background: transparent; color: var(--text-on-surface-variant); }
        .btn-ghost:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-xs);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.primary { background: #eef0ff; color: #4f46e5; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }
        .stat-card .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.25rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 180px;
            position: relative;
        }
        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.125rem;
        }
        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.5rem 0.875rem 0.5rem 2.5rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .filter-btn {
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            transition: all var(--transition-fast);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 2px 10px rgba(79, 70, 229, 0.25); }
        .filter-btn .count {
            background: rgba(0,0,0,0.08);
            border-radius: var(--radius-full);
            padding: 0 0.375rem;
            font-size: 0.625rem;
            font-weight: 700;
        }
        .filter-btn.active .count { background: rgba(255,255,255,0.25); }

        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-header h3 .material-symbols-outlined { font-size: 1.125rem; color: var(--primary); }
        .card-header .count-badge {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
        }
        .card-body { padding: 0; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            min-width: 700px;
        }
        table thead { background: var(--bg-surface-low); }
        table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        table tbody tr:hover td { background: var(--bg-surface-low); }
        table tbody tr:last-child td { border-bottom: none; }

        .applicant-cell {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .applicant-cell .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.6875rem;
            flex-shrink: 0;
        }
        .applicant-cell .info .name { font-weight: 600; color: var(--text-on-surface); }
        .applicant-cell .info .email { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

        .job-cell .title { font-weight: 500; }
        .job-cell .company { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

        .datetime-cell .date { font-weight: 600; }
        .datetime-cell .time { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-scheduled { background: #dbeafe; color: #2563eb; }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-cancelled { background: #fecaca; color: #dc2626; }
        .badge-rescheduled { background: #fef3c7; color: #d97706; }
        .badge-no-show { background: #f3f4f6; color: #6b7280; }

        .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; justify-content: center; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.5rem;
        }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); }
        .empty-state p { font-size: 0.8125rem; color: var(--text-on-surface-variant); }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 40rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 1.125rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-header h2 .material-symbols-outlined { font-size: 1.25rem; color: var(--primary); }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.375rem;
            border-radius: 0.375rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }
        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-close .material-symbols-outlined { font-size: 1.25rem; }
        .modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.1875rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-group .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-group textarea.form-control { resize: vertical; min-height: 60px; }
        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
        .helper-text {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.1875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .helper-text .material-symbols-outlined { font-size: 0.875rem; }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.625rem 1.125rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.8125rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideUp 0.35s ease-out;
            max-width: 380px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toast .material-symbols-outlined { font-size: 1.125rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-sm); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-lg); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .search-bar { flex-direction: column; }
            .filters { overflow-x: auto; flex-wrap: nowrap; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
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
            .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1.5rem; }
            .dashboard-sidebar.collapsed .sidebar-nav { padding: 1.5rem 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: flex-start; padding: 0.75rem 1rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: flex-start; padding: 0.5rem 0.75rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .stats-row { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.25rem; }
            table { font-size: 0.75rem; min-width: 500px; }
            table th, table td { padding: 0.375rem 0.5rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
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
        <!-- NO "System" section with Settings -->
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
            </div>
        </div>
        <!-- NO logout-btn here - only in profile dropdown -->
    </div>
</aside>

    <!-- ===== MAIN CONTENT ===== -->
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

        <main class="main-scroll">
            <div class="container">
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">calendar_month</span>
                        <span>Interviews</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> (<?php echo count($interviews); ?>)
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Updated <?php echo date('M d, Y H:i'); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Interview Management</h1>
                        <p>Schedule, track, and manage all candidate interviews</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="openScheduleModal()">
                            <span class="material-symbols-outlined">add</span>
                            Schedule Interview
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <span class="material-symbols-outlined">event_upcoming</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $upcomingCount; ?></div>
                            <div class="stat-label">Upcoming</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $statusCounts['completed'] ?? 0; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <span class="material-symbols-outlined">cancel</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $statusCounts['cancelled'] ?? 0; ?></div>
                            <div class="stat-label">Cancelled</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <span class="material-symbols-outlined">refresh</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $statusCounts['rescheduled'] ?? 0; ?></div>
                            <div class="stat-label">Rescheduled</div>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <span class="material-symbols-outlined">search</span>
                        <input type="text" id="searchInput" placeholder="Search by name, email, or job..." 
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                    <?php if (!empty($searchQuery) || $statusFilter !== 'all'): ?>
                        <a href="interviews.php" class="btn btn-outline">Clear Filters</a>
                    <?php endif; ?>
                </div>

                <!-- Filters -->
                <div class="filters">
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <a href="?status=<?php echo $key; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                           class="filter-btn <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                            <span class="count"><?php echo $statusCounts[$key] ?? 0; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Interviews Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">calendar_month</span>
                            <?php echo $statusFilter === 'all' ? 'All Interviews' : ucfirst($statusFilter) . ' Interviews'; ?>
                        </h3>
                        <span class="count-badge"><?php echo count($interviews); ?> interviews</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($interviews)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">event_busy</span>
                                <h4>No Interviews Found</h4>
                                <p>No interviews have been scheduled yet.</p>
                                <button class="btn btn-primary" onclick="openScheduleModal()" style="margin-top:0.75rem;">
                                    <span class="material-symbols-outlined">add</span>
                                    Schedule First Interview
                                </button>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Candidate</th>
                                        <th>Position</th>
                                        <th>Date & Time</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($interviews as $interview): ?>
                                        <?php 
                                        $isUpcoming = $interview['status'] === 'scheduled' && strtotime($interview['interview_date']) > time();
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="applicant-cell">
                                                    <span class="avatar">
                                                        <?php echo strtoupper(substr($interview['first_name'] ?? 'U', 0, 1)); ?>
                                                    </span>
                                                    <div class="info">
                                                        <div class="name"><?php echo htmlspecialchars($interview['first_name'] . ' ' . $interview['last_name']); ?></div>
                                                        <div class="email"><?php echo htmlspecialchars($interview['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="job-cell">
                                                    <div class="title"><?php echo htmlspecialchars($interview['job_title'] ?? 'Position'); ?></div>
                                                    <div class="company"><?php echo htmlspecialchars($interview['company_name'] ?? ''); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="datetime-cell">
                                                    <div class="date"><?php echo date('M d, Y', strtotime($interview['interview_date'])); ?></div>
                                                    <div class="time"><?php echo date('g:i A', strtotime($interview['interview_date'])); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo $interviewTypeLabels[$interview['interview_type']] ?? $interview['interview_type']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $statusBadges[$interview['status']] ?? 'badge-scheduled'; ?>">
                                                    <?php echo $statusLabels[$interview['status']] ?? ucfirst($interview['status']); ?>
                                                </span>
                                                <?php if ($isUpcoming): ?>
                                                    <span style="font-size:0.625rem; color:#22c55e; display:block;">Upcoming</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-primary btn-sm" onclick="viewInterview(<?php echo $interview['id']; ?>)">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-outline btn-sm" onclick="editInterview(<?php echo $interview['id']; ?>)">
                                                        <span class="material-symbols-outlined">edit</span>
                                                    </button>
                                                    <?php if ($interview['status'] === 'scheduled'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="completeInterview(<?php echo $interview['id']; ?>)">
                                                            <span class="material-symbols-outlined">check</span>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="cancelInterview(<?php echo $interview['id']; ?>)">
                                                            <span class="material-symbols-outlined">cancel</span>
                                                        </button>
                                                    <?php endif; ?>
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
    MODAL: Schedule/Edit Interview
    ============================================= -->
    <div class="modal-overlay" id="interviewModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">calendar_month</span>
                    <span id="modalTitle">Schedule Interview</span>
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="interviewForm" onsubmit="submitInterview(event)">
                    <input type="hidden" id="interviewId" name="interview_id" value="0">
                    <input type="hidden" id="formAction" name="action" value="schedule_interview">
                    
                    <div class="form-group">
                        <label for="applicationSelect">Select Applicant <span class="required">*</span></label>
                        <select id="applicationSelect" name="application_id" class="form-control" required>
                            <option value="">— Select an applicant —</option>
                            <?php
                            $applicantsSql = "SELECT a.id, u.first_name, u.last_name, u.email, jo.title as job_title
                                FROM applications a
                                JOIN applicants ap ON a.applicant_id = ap.id
                                JOIN users u ON ap.user_id = u.id
                                JOIN job_orders jo ON a.job_order_id = jo.id
                                WHERE jo.created_by = '$userId' AND a.status IN ('pending', 'shortlisted')
                                ORDER BY a.applied_at DESC";
                            $applicantsResult = mysqli_query($conn, $applicantsSql);
                            while ($app = mysqli_fetch_assoc($applicantsResult)): ?>
                                <option value="<?php echo $app['id']; ?>">
                                    <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name'] . ' - ' . $app['job_title']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div class="helper-text">
                            <span class="material-symbols-outlined">info</span>
                            Only pending or shortlisted applicants can be scheduled
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="interviewDate">Date & Time <span class="required">*</span></label>
                            <input type="datetime-local" id="interviewDate" name="interview_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="interviewType">Interview Type</label>
                            <select id="interviewType" name="interview_type" class="form-control">
                                <?php foreach ($interviewTypes as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $interviewTypeLabels[$type]; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="meetingLink">Meeting Link</label>
                        <input type="url" id="meetingLink" name="meeting_link" class="form-control" placeholder="https://meet.google.com/...">
                        <div class="helper-text">
                            <span class="material-symbols-outlined">link</span>
                            For online interviews, paste the meeting link
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="interviewers">Interviewers</label>
                        <input type="text" id="interviewers" name="interviewers" class="form-control" placeholder="John Doe, Jane Smith">
                        <div class="helper-text">
                            <span class="material-symbols-outlined">group</span>
                            Names of interviewers (comma separated)
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="interviewNotes">Notes / Preparation</label>
                        <textarea id="interviewNotes" name="notes" class="form-control" placeholder="Add any preparation notes or instructions..." rows="3"></textarea>
                    </div>
                    
                    <div class="form-group" id="statusField" style="display:none;">
                        <label for="editStatus">Status</label>
                        <select id="editStatus" name="status" class="form-control">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>"><?php echo $statusLabels[$status]; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button class="btn btn-primary" id="submitBtn" onclick="document.getElementById('interviewForm').dispatchEvent(new Event('submit'))">
                    <span class="material-symbols-outlined">check</span>
                    <span id="submitBtnText">Schedule Interview</span>
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    MODAL: View Interview Details
    ============================================= -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">visibility</span>
                    Interview Details
                </h2>
                <button class="modal-close" onclick="closeModal('viewModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="loading-spinner" id="viewLoading">
                    <div style="text-align:center; padding:1.5rem;">
                        <div style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div>
                        <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading...</p>
                    </div>
                </div>
                <div id="viewContent" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
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
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

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

    // =============================================
    // 3. PROFILE DROPDOWN - FIXED WITH NULL CHECK
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
    // 4. MODAL FUNCTIONS
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

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const interviewModal = document.getElementById('interviewModal');
            const viewModal = document.getElementById('viewModal');
            if (interviewModal && interviewModal.classList.contains('active')) {
                closeModal('interviewModal');
            } else if (viewModal && viewModal.classList.contains('active')) {
                closeModal('viewModal');
            }
            closeMobileSidebar();
            if (profileToggle) profileToggle.classList.remove('open');
            if (profileMenu) profileMenu.classList.remove('open');
        }
    });

    // =============================================
    // 5. SCHEDULE INTERVIEW
    // =============================================
    function openScheduleModal() {
        const modalTitle = document.getElementById('modalTitle');
        const formAction = document.getElementById('formAction');
        const interviewId = document.getElementById('interviewId');
        const submitBtnText = document.getElementById('submitBtnText');
        const statusField = document.getElementById('statusField');
        const interviewForm = document.getElementById('interviewForm');
        const interviewDate = document.getElementById('interviewDate');
        
        if (modalTitle) modalTitle.textContent = 'Schedule Interview';
        if (formAction) formAction.value = 'schedule_interview';
        if (interviewId) interviewId.value = '0';
        if (submitBtnText) submitBtnText.textContent = 'Schedule Interview';
        if (statusField) statusField.style.display = 'none';
        if (interviewForm) interviewForm.reset();
        
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        if (interviewDate) interviewDate.value = tomorrow.toISOString().slice(0, 16);
        
        openModal('interviewModal');
    }

    // =============================================
    // 6. EDIT INTERVIEW
    // =============================================
    function editInterview(id) {
        const modalTitle = document.getElementById('modalTitle');
        const formAction = document.getElementById('formAction');
        const interviewId = document.getElementById('interviewId');
        const submitBtnText = document.getElementById('submitBtnText');
        const statusField = document.getElementById('statusField');
        const applicationSelect = document.getElementById('applicationSelect');
        
        if (modalTitle) modalTitle.textContent = 'Edit Interview';
        if (formAction) formAction.value = 'update_interview';
        if (interviewId) interviewId.value = id;
        if (submitBtnText) submitBtnText.textContent = 'Update Interview';
        if (statusField) statusField.style.display = 'block';
        if (applicationSelect) applicationSelect.disabled = true;

        const formData = new FormData();
        formData.append('action', 'get_interview');
        formData.append('interview_id', id);

        fetch('interviews.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const interview = data.interview;
                const appSelect = document.getElementById('applicationSelect');
                const intDate = document.getElementById('interviewDate');
                const intType = document.getElementById('interviewType');
                const meetingLink = document.getElementById('meetingLink');
                const interviewers = document.getElementById('interviewers');
                const intNotes = document.getElementById('interviewNotes');
                const editStatus = document.getElementById('editStatus');
                
                if (appSelect) appSelect.value = interview.application_id;
                if (intDate) intDate.value = interview.interview_date.replace(' ', 'T');
                if (intType) intType.value = interview.interview_type;
                if (meetingLink) meetingLink.value = interview.meeting_link || '';
                if (interviewers) interviewers.value = interview.interviewers || '';
                if (intNotes) intNotes.value = interview.notes || '';
                if (editStatus) editStatus.value = interview.status;
                openModal('interviewModal');
            } else {
                showToast(data.error || 'Failed to load interview.', 'error');
            }
        })
        .catch(error => {
            console.error('Edit error:', error);
            showToast('Error loading interview details.', 'error');
        });
    }

    // =============================================
    // 7. SUBMIT INTERVIEW
    // =============================================
    function submitInterview(event) {
        event.preventDefault();
        
        const form = document.getElementById('interviewForm');
        if (!form) return;
        
        const formData = new FormData(form);
        
        const date = document.getElementById('interviewDate');
        if (!date || !date.value) {
            showToast('Please select an interview date and time.', 'error');
            return;
        }
        
        const btn = document.getElementById('submitBtn');
        if (!btn) return;
        
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block; width:1rem; height:1rem; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Saving...';

        fetch('interviews.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            
            if (data.success) {
                showToast(data.message, 'success');
                closeModal('interviewModal');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.error || 'Failed to save interview.', 'error');
            }
        })
        .catch(error => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            showToast('Error saving interview. Please try again.', 'error');
        });
    }

    // =============================================
    // 8. VIEW INTERVIEW
    // =============================================
    function viewInterview(id) {
        openModal('viewModal');
        
        const loading = document.getElementById('viewLoading');
        const content = document.getElementById('viewContent');
        
        if (loading) loading.style.display = 'block';
        if (content) content.style.display = 'none';

        const formData = new FormData();
        formData.append('action', 'get_interview');
        formData.append('interview_id', id);

        fetch('interviews.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (loading) loading.style.display = 'none';
            if (content) content.style.display = 'block';

            if (data.success) {
                const i = data.interview;
                
                if (content) {
                    content.innerHTML = `
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Candidate</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(i.first_name)} ${escapeHtml(i.last_name)}</div>
                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(i.email)}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Position</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(i.job_title)}</div>
                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(i.company_name)}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Date & Time</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${new Date(i.interview_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${new Date(i.interview_date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Type</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(i.interview_type)}</div>
                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                    <span class="badge badge-${i.status}">${escapeHtml(i.status)}</span>
                                </div>
                            </div>
                            ${i.meeting_link ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Meeting Link</div>
                                <a href="${escapeHtml(i.meeting_link)}" target="_blank" style="color:var(--primary); text-decoration:underline; word-break:break-all;">${escapeHtml(i.meeting_link)}</a>
                            </div>
                            ` : ''}
                            ${i.interviewers ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Interviewers</div>
                                <div>${escapeHtml(i.interviewers)}</div>
                            </div>
                            ` : ''}
                            ${i.notes ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Notes</div>
                                <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(i.notes)}</div>
                            </div>
                            ` : ''}
                            ${i.rating && i.rating > 0 ? `
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Rating</div>
                                <div style="font-weight:700; font-size:1.125rem; color:#f59e0b;">${'★'.repeat(Math.min(5, i.rating))}${'☆'.repeat(5 - Math.min(5, i.rating))}</div>
                            </div>
                            ` : ''}
                            ${i.feedback ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Feedback</div>
                                <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(i.feedback)}</div>
                            </div>
                            ` : ''}
                        </div>
                    `;
                }
            } else {
                if (content) {
                    content.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${data.error || 'Failed to load interview details.'}</p>
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
                        <p style="margin-top:0.5rem;">Error loading interview details. Please try again.</p>
                    </div>
                `;
            }
        });
    }

    // =============================================
    // 9. CANCEL INTERVIEW
    // =============================================
    function cancelInterview(id) {
        if (!confirm('Are you sure you want to cancel this interview?')) return;

        const formData = new FormData();
        formData.append('action', 'cancel_interview');
        formData.append('interview_id', id);

        showToast('Cancelling interview...', 'info');

        fetch('interviews.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.error || 'Failed to cancel interview.', 'error');
            }
        })
        .catch(error => {
            showToast('Error cancelling interview.', 'error');
        });
    }

    // =============================================
    // 10. COMPLETE INTERVIEW
    // =============================================
    function completeInterview(id) {
        const rating = prompt('Enter rating (1-5):', '3');
        if (rating === null) return;
        const numRating = parseInt(rating);
        if (isNaN(numRating) || numRating < 1 || numRating > 5) {
            showToast('Please enter a rating between 1 and 5.', 'error');
            return;
        }
        
        const feedback = prompt('Enter feedback (optional):', '') || '';

        const formData = new FormData();
        formData.append('action', 'complete_interview');
        formData.append('interview_id', id);
        formData.append('rating', numRating);
        formData.append('feedback', feedback);

        showToast('Completing interview...', 'info');

        fetch('interviews.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.error || 'Failed to complete interview.', 'error');
            }
        })
        .catch(error => {
            showToast('Error completing interview.', 'error');
        });
    }

    // =============================================
    // 11. SEARCH & FILTERS
    // =============================================
    function applyFilters() {
        const search = document.getElementById('searchInput');
        if (!search) return;
        
        const status = '<?php echo $statusFilter; ?>';
        let url = 'interviews.php?';
        if (status !== 'all') url += 'status=' + status + '&';
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
    // 12. TOAST SYSTEM
    // =============================================
    function showToast(message, type) {
        type = type || 'info';
        const existingToast = document.querySelector('.toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info' };
        toast.innerHTML = `<span class="material-symbols-outlined">${iconMap[type] || 'info'}</span> ${message}`;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(20px)';
            toast.style.transition = 'all 0.4s ease';
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    // =============================================
    // 13. UTILITY FUNCTIONS
    // =============================================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =============================================
    // 14. RESPONSIVE HANDLING
    // =============================================
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const width = window.innerWidth;
            if (width <= 768) {
                sidebar.classList.remove('collapsed');
            } else {
                sidebar.classList.remove('mobile-open');
                sidebarBackdrop.classList.remove('active');
                document.body.style.overflow = '';
                const saved = localStorage.getItem('sidebarCollapsed');
                if (saved === 'true') {
                    sidebar.classList.add('collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                }
            }
        }, 250);
    });

    console.log('📅 ISMERS Interviews Management loaded successfully!');
</script>

</body>
</html>