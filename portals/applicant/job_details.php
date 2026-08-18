<?php
// portals/applicant/job_details.php - View Job Details & Apply with Face Scanner
session_start();

require_once '../../app/config.php';
require_once '../../app/ai/AiService.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has applicant role
if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Applicant';
$lastName = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$fullName = $_SESSION['full_name'] ?? 'Applicant User';

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// =============================================
// GET COUNTS FOR SIDEBAR BADGES
// =============================================
$totalApplications = 0;
$interviewCount = 0;
$pendingOffers = 0;

if ($applicantId) {
    $appResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = ?
    ", [$applicantId], "i");
    $totalApplications = $appResult['count'] ?? 0;
    
    $interviewResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = ? AND interview_date IS NOT NULL
    ", [$applicantId], "i");
    $interviewCount = $interviewResult['count'] ?? 0;
    
    $offersResult = getRecord("
        SELECT COUNT(*) as count FROM offers o
        JOIN applications a ON o.application_id = a.id
        WHERE a.applicant_id = ? AND o.status = 'sent'
    ", [$applicantId], "i");
    $pendingOffers = $offersResult['count'] ?? 0;
}

// Get job ID from URL
$jobId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($jobId <= 0) {
    header('Location: dashboard.php');
    exit;
}

// Get job details
$jobSql = "SELECT j.*, 
           c.company_name,
           u.first_name as client_first_name, u.last_name as client_last_name,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id) as total_applicants
           FROM job_orders j
           JOIN clients c ON j.client_id = c.id
           JOIN users u ON c.user_id = u.id
           WHERE j.id = ? AND j.status IN ('open', 'ongoing')";

$stmt = mysqli_prepare($conn, $jobSql);
mysqli_stmt_bind_param($stmt, 'i', $jobId);
mysqli_stmt_execute($stmt);
$jobResult = mysqli_stmt_get_result($stmt);
$job = mysqli_fetch_assoc($jobResult);
mysqli_stmt_close($stmt);

if (!$job) {
    header('Location: dashboard.php');
    exit;
}

// Get applicant profile
$applicant = getRecord("
    SELECT id, phone, address, skills, experience, education, resume_path, profile_picture
    FROM applicants
    WHERE user_id = ?
", [$userId], "i");

// Check if face is already verified
$faceVerified = false;
if ($applicantId) {
    $faceCheck = getRecord("
        SELECT id FROM face_verification WHERE user_id = ?
    ", [$userId], "i");
    $faceVerified = $faceCheck !== null;
}

// =============================================
// AI MATCH SCORE CALCULATION
// =============================================
$matchScore = null;
$matchStrengths = [];
$matchGaps = [];
$matchRecommendation = '';
$matchProvider = '';

if ($applicant && !empty($applicant['skills'])) {
    try {
        $aiService = new AiService();
        
        $jobData = [
            'title' => $job['title'] ?? '',
            'skills_required' => $job['skills_required'] ?? '',
            'description' => $job['description'] ?? '',
            'experience_level' => $job['experience_level'] ?? '',
        ];
        
        $applicantData = [
            'skills' => $applicant['skills'] ?? '',
            'experience' => $applicant['experience'] ?? '',
            'education' => $applicant['education'] ?? '',
        ];
        
        $result = $aiService->calculateMatchScore($jobData, $applicantData);
        
        if (isset($result['match_score']) && $result['match_score'] !== null) {
            $matchScore = (int)$result['match_score'];
            $matchStrengths = $result['strengths'] ?? [];
            $matchGaps = $result['gaps'] ?? [];
            $matchRecommendation = $result['recommendation'] ?? '';
            $matchProvider = $result['provider'] ?? 'unknown';
        }
    } catch (Exception $e) {
        error_log("Match score error for job {$job['id']}: " . $e->getMessage());
    }
}

// Get AI provider status
$aiService = new AiService();
$isAIEnabled = !$aiService->isUsingMock();

// Check if already applied
$hasApplied = false;
$applicationStatus = '';
if ($applicant) {
    $checkSql = "SELECT id, status FROM applications WHERE job_order_id = ? AND applicant_id = ?";
    $stmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($stmt, 'ii', $jobId, $applicant['id']);
    mysqli_stmt_execute($stmt);
    $checkResult = mysqli_stmt_get_result($stmt);
    $existingApp = mysqli_fetch_assoc($checkResult);
    mysqli_stmt_close($stmt);
    
    if ($existingApp) {
        $hasApplied = true;
        $applicationStatus = $existingApp['status'];
    }
}

// Handle Application Submission
$message = '';
$messageType = '';
$showSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'apply') {
        // Check if already applied
        if ($hasApplied) {
            $message = 'You have already applied to this job.';
            $messageType = 'error';
        } elseif (!$applicant) {
            $message = 'Please complete your profile before applying.';
            $messageType = 'error';
        } else {
            // Handle file upload
            $resumePath = $applicant['resume_path'] ?? '';
            $coverLetter = trim($_POST['cover_letter'] ?? '');
            
            // If resume file is uploaded
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../../uploads/resumes/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileInfo = pathinfo($_FILES['resume']['name']);
                $extension = strtolower($fileInfo['extension']);
                $allowedExtensions = ['pdf', 'doc', 'docx'];
                
                if (!in_array($extension, $allowedExtensions)) {
                    $message = 'Invalid file type. Allowed: PDF, DOC, DOCX';
                    $messageType = 'error';
                } elseif ($_FILES['resume']['size'] > 5 * 1024 * 1024) {
                    $message = 'File size must be less than 5MB.';
                    $messageType = 'error';
                } else {
                    $newFileName = 'resume_' . $userId . '_' . time() . '.' . $extension;
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetPath)) {
                        $resumePath = 'uploads/resumes/' . $newFileName;
                        
                        $updateSql = "UPDATE applicants SET resume_path = ? WHERE user_id = ?";
                        $stmt = mysqli_prepare($conn, $updateSql);
                        mysqli_stmt_bind_param($stmt, 'si', $resumePath, $userId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    } else {
                        $message = 'Failed to upload resume.';
                        $messageType = 'error';
                    }
                }
            }
            
            // If no error, proceed with application
            if (empty($message)) {
                // Create application
                $insertSql = "INSERT INTO applications (job_order_id, applicant_id, cover_letter, resume_path, status, applied_at) 
                              VALUES (?, ?, ?, ?, 'pending', NOW())";
                $stmt = mysqli_prepare($conn, $insertSql);
                mysqli_stmt_bind_param($stmt, 'iiss', $jobId, $applicant['id'], $coverLetter, $resumePath);
                
                if (mysqli_stmt_execute($stmt)) {
                    $applicationId = mysqli_insert_id($conn);
                    $message = 'Application submitted successfully!';
                    $messageType = 'success';
                    $showSuccess = true;
                    $hasApplied = true;
                    $applicationStatus = 'pending';
                    
                    logActivity($userId, 'Job Application Submitted', 'applications', $applicationId, 
                               'Applied to job: ' . $job['title']);
                } else {
                    $message = 'Failed to submit application. Please try again.';
                    $messageType = 'error';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Get similar jobs
$similarJobsSql = "SELECT j.*, c.company_name 
                   FROM job_orders j
                   JOIN clients c ON j.client_id = c.id
                   WHERE j.id != ? 
                   AND j.status IN ('open', 'ongoing')
                   AND (j.job_type = ? OR j.location = ?)
                   AND j.client_id != ?
                   ORDER BY j.created_at DESC
                   LIMIT 4";

$stmt = mysqli_prepare($conn, $similarJobsSql);
$jobType = $job['job_type'] ?? '';
$location = $job['location'] ?? '';
$clientId = $job['client_id'] ?? 0;
mysqli_stmt_bind_param($stmt, 'issi', $jobId, $jobType, $location, $clientId);
mysqli_stmt_execute($stmt);
$similarJobsResult = mysqli_stmt_get_result($stmt);
$similarJobs = [];
while ($row = mysqli_fetch_assoc($similarJobsResult)) {
    $similarJobs[] = $row;
}
mysqli_stmt_close($stmt);

$statusLabels = [
    'pending' => 'Pending Review',
    'reviewed' => 'Reviewed',
    'shortlisted' => 'Shortlisted',
    'hired' => 'Hired',
    'rejected' => 'Rejected'
];

$statusBadges = [
    'pending' => 'badge-pending',
    'reviewed' => 'badge-reviewed',
    'shortlisted' => 'badge-shortlisted',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($job['title']); ?> - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           JOB DETAILS - SINGLE CONTAINER
           ========================================================================== */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --primary-container: #eef0ff;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
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
            --success-color: #22c55e;
            --error-color: #dc2626;
            --warning-color: #f59e0b;
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

        /* Sidebar */
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
            .breadcrumb-bar { flex-direction: row; align-items: center; justify-content: space-between; }
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
        .breadcrumb-meta { font-size: 0.75rem; color: var(--text-on-surface-variant); }

        /* =============================================
           AI MATCH SCORE CARD
        ============================================= */
        .match-score-card {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
        }

        .match-score-card .score-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: white;
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.15);
        }

        .match-score-card .score-circle .score-number {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
        }

        .match-score-card .score-circle .score-label {
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .match-score-card .score-circle.high .score-number { color: #059669; }
        .match-score-card .score-circle.medium .score-number { color: #d97706; }
        .match-score-card .score-circle.low .score-number { color: #dc2626; }

        .match-score-card .score-details {
            flex: 1;
            min-width: 200px;
        }

        .match-score-card .score-details .score-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .match-score-card .score-details .score-recommendation {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            line-height: 1.5;
        }

        .match-score-card .score-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .match-score-card .score-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 500;
        }

        .match-score-card .score-tag.strength {
            background: #d1fae5;
            color: #065f46;
        }

        .match-score-card .score-tag.gap {
            background: #fef3c7;
            color: #92400e;
        }

        .match-score-card .score-provider {
            font-size: 0.6rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* =============================================
           FACE SCANNER MODAL
        ============================================= */
        .face-scan-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            animation: fadeIn 0.3s ease;
        }
        .face-scan-modal.active {
            display: flex;
        }

        .face-scan-modal-content {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 520px;
            width: 100%;
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 95vh;
            overflow-y: auto;
        }

        .face-scan-header {
            text-align: center;
            margin-bottom: 1.25rem;
        }

        .face-scan-header h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .face-scan-header p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .face-scan-wrapper {
            position: relative;
            background: #1a1a2e;
            border-radius: var(--radius-xl);
            overflow: hidden;
            aspect-ratio: 4/3;
            margin-bottom: 1rem;
        }

        .face-scan-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transform: scaleX(-1); /* This fixes the mirror effect */

        }

        .face-scan-wrapper canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
        }

        .face-scan-guide {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
            pointer-events: none;
        }

        .face-scan-circle {
            width: 180px;
            height: 180px;
            border: 3px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .face-scan-circle .guide-text {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
            padding: 0.5rem;
        }

        .face-scan-circle::after {
            content: '';
            position: absolute;
            inset: -10px;
            border-radius: 50%;
            border: 2px dashed rgba(255, 255, 255, 0.1);
            animation: spin 20s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .face-scan-status {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            background: var(--bg-surface-low);
            margin-bottom: 1rem;
            font-size: 0.8125rem;
        }

        .face-scan-status .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .face-scan-status .status-dot.idle { background: #9ca3af; }
        .face-scan-status .status-dot.scanning { background: var(--warning-color); animation: pulse 1s infinite; }
        .face-scan-status .status-dot.success { background: var(--success-color); }
        .face-scan-status .status-dot.error { background: var(--error-color); }

        .face-scan-actions {
            display: flex;
            gap: 0.75rem;
        }

        .face-scan-actions .btn {
            flex: 1;
            justify-content: center;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .face-scan-actions .btn-outline {
            background: transparent;
            color: var(--text-on-surface-variant);
            border: 2px solid var(--slate-200);
        }

        .face-scan-actions .btn-outline:hover {
            background: var(--bg-surface-low);
            border-color: var(--primary);
            color: var(--primary);
        }

        .face-scan-actions .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .face-scan-actions .btn-primary:hover:not(:disabled) {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
        }

        .face-scan-actions .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .face-scan-actions .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .face-scan-actions .loading-spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        /* Face verified badge */
        .face-verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .face-verified-badge .material-symbols-outlined {
            font-size: 0.875rem;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.6; }
        }

        /* =============================================
           SINGLE CONTAINER - JOB DETAILS
        ============================================= */
        .job-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .section-divider {
            height: 2px;
            background: linear-gradient(to right, var(--primary), var(--primary-light), transparent);
            margin: 0;
            border: none;
            opacity: 0.6;
        }

        .job-header {
            padding: 1.5rem 2rem;
            background: var(--bg-surface-low);
            border-bottom: 3px solid var(--primary);
        }
        @media (max-width: 640px) {
            .job-header { padding: 1rem 1.25rem; }
        }

        .job-header .job-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }
        .job-header .job-company {
            font-size: 1rem;
            color: var(--primary);
            font-weight: 600;
            margin-top: 0.125rem;
        }
        .job-header .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-top: 0.75rem;
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }
        .job-header .job-meta .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
            color: var(--primary);
        }
        .job-header .job-status {
            margin-top: 0.75rem;
        }

        .job-section {
            padding: 1.25rem 2rem;
        }
        .job-section:last-child {
            padding-bottom: 1.5rem;
        }
        @media (max-width: 640px) {
            .job-section { padding: 1rem 1.25rem; }
            .job-section:last-child { padding-bottom: 1rem; }
        }

        .job-section .section-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
        }
        .job-section .section-title .material-symbols-outlined {
            color: var(--primary);
            font-size: 1.25rem;
        }

        .job-section .section-content {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            line-height: 1.8;
            white-space: pre-wrap;
        }

        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-open { background: #d1fae5; color: #059669; }
        .badge-ongoing { background: #dbeafe; color: #2563eb; }
        .badge-closed { background: #f1f5f9; color: #64748b; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-reviewed { background: #dbeafe; color: #2563eb; }
        .badge-shortlisted { background: #d1fae5; color: #059669; }
        .badge-hired { background: #a7f3d0; color: #047857; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem 1.5rem;
        }
        @media (max-width: 640px) {
            .info-grid { grid-template-columns: 1fr; }
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.375rem 0;
            border-bottom: 1px solid var(--slate-100);
        }
        .info-item:last-child { border-bottom: none; }
        .info-item .label {
            color: var(--text-on-surface-variant);
            font-size: 0.8125rem;
        }
        .info-item .value {
            font-weight: 600;
            color: var(--text-on-surface);
            font-size: 0.875rem;
        }

        .apply-section {
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            margin-top: 0.5rem;
        }

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
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--primary-container); }
        .btn-ghost { background: transparent; color: var(--text-on-surface-variant); }
        .btn-ghost:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        .apply-btn {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        }

        .apply-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.45);
            background: linear-gradient(135deg, #4338ca, #6d28d9);
        }

        .applied-status {
            text-align: center;
            padding: 1rem;
            background: var(--bg-surface);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }
        .applied-status .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--primary);
        }
        .applied-status .status-text {
            font-size: 1rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        .applied-status .status-sub {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .similar-job-card {
            padding: 0.75rem 1rem;
            border: 1px solid var(--slate-200);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
            transition: all var(--transition-fast);
            cursor: pointer;
        }
        .similar-job-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        .similar-job-card .job-title-sm {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }
        .similar-job-card .job-company-sm {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .similar-job-card .job-meta-sm {
            display: flex;
            gap: 1rem;
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.8125rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideDown 0.35s ease-out;
            max-width: 380px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .toast .material-symbols-outlined { font-size: 1.25rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
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
            .job-header .job-title { font-size: 1.25rem; }
            .info-grid { grid-template-columns: 1fr; }
            .match-score-card { flex-direction: column; align-items: stretch; text-align: center; }
            .match-score-card .score-circle { margin: 0 auto; }
            .match-score-card .score-tags { justify-content: center; }
            .face-scan-modal-content { padding: 1.25rem; margin: 0.5rem; }
            .face-scan-circle { width: 140px; height: 140px; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .job-header { padding: 0.75rem 1rem; }
            .job-section { padding: 0.75rem 1rem; }
            .job-section:last-child { padding-bottom: 0.75rem; }
            .job-header .job-title { font-size: 1.125rem; }
            .match-score-card .score-circle { width: 64px; height: 64px; }
            .match-score-card .score-circle .score-number { font-size: 1.25rem; }
            .face-scan-circle { width: 120px; height: 120px; }
            .face-scan-actions { flex-direction: column; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
    </style>
</head>
<body>

    <!-- Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">work</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">Applicant Portal</p>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="offers.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">My Offers</span>
                <span class="nav-badge"><?php echo $pendingOffers; ?></span>
            </a>

            <a href="interview.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
                <span class="nav-badge"><?php echo $interviewCount; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">search</span>
                <span class="nav-text">Job Search</span>
            </a>

        </nav>
        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-wrapper" id="mainWrapper">
        <header class="top-header">
            <div class="top-header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">chevron_left</span>
                </button>
                <span class="separator">|</span>
                <a href="job_search.php" style="font-weight:500; font-size:0.8125rem; color:var(--text-on-surface-variant); display:flex; align-items:center; gap:0.25rem;">
                    <span class="material-symbols-outlined" style="font-size:1rem;">arrow_back</span>
                    Back to Jobs
                </a>
            </div>
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Applicant</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <button class="dropdown-item" onclick="window.location.href='profile.php'">
                        <span class="material-symbols-outlined">person</span> Profile
                    </button>
                    <button class="dropdown-item" onclick="window.location.href='settings.php'">
                        <span class="material-symbols-outlined">settings</span> Settings
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
                <!-- Toast Messages -->
                <?php if ($message): ?>
                    <div class="toast <?php echo $messageType; ?>" id="toastMessage">
                        <span class="material-symbols-outlined">
                            <?php echo $messageType === 'success' ? 'check_circle' : 'error'; ?>
                        </span>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                    <script>
                        setTimeout(() => {
                            const toast = document.getElementById('toastMessage');
                            if (toast) toast.remove();
                        }, 5000);
                    </script>
                <?php endif; ?>

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">work</span>
                        <span>Job Details</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($job['title']); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                </div>

                <!-- AI Match Score Card -->
                <?php if ($matchScore !== null && $matchScore > 0): ?>
                <div class="match-score-card">
                    <?php 
                    $scoreClass = 'low';
                    if ($matchScore >= 70) $scoreClass = 'high';
                    elseif ($matchScore >= 40) $scoreClass = 'medium';
                    ?>
                    <div class="score-circle <?php echo $scoreClass; ?>">
                        <span class="score-number"><?php echo $matchScore; ?>%</span>
                        <span class="score-label">Match</span>
                    </div>
                    <div class="score-details">
                        <div class="score-title">Your Match Score</div>
                        <div class="score-recommendation"><?php echo htmlspecialchars($matchRecommendation); ?></div>
                        
                        <?php if (!empty($matchStrengths) || !empty($matchGaps)): ?>
                        <div class="score-tags">
                            <?php foreach (array_slice($matchStrengths, 0, 2) as $strength): ?>
                                <span class="score-tag strength">
                                    <span class="material-symbols-outlined" style="font-size:0.75rem;">check</span>
                                    <?php echo htmlspecialchars($strength); ?>
                                </span>
                            <?php endforeach; ?>
                            <?php foreach (array_slice($matchGaps, 0, 2) as $gap): ?>
                                <span class="score-tag gap">
                                    <span class="material-symbols-outlined" style="font-size:0.75rem;">warning</span>
                                    <?php echo htmlspecialchars($gap); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($matchProvider && $matchProvider !== 'mock'): ?>
                        <div class="score-provider">✨ Powered by <?php echo ucfirst($matchProvider); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($applicant && empty($applicant['skills'])): ?>
                <div class="match-score-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #fcd34d;">
                    <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; width:100%;">
                        <span class="material-symbols-outlined" style="font-size:2rem; color:#92400e;">info</span>
                        <div style="flex:1;">
                            <div style="font-weight:600; color:#92400e;">Profile Incomplete</div>
                            <div style="font-size:0.8125rem; color:#92400e;">Add your skills to see your match score for this job.</div>
                            <a href="edit_profile.php" style="margin-top:0.25rem; display:inline-block; font-size:0.75rem; font-weight:600; color:#4f46e5;">Update Profile →</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Single Job Card -->
                <div class="job-card">
                    <!-- Job Header -->
                    <div class="job-header">
                        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                        <div class="job-company"><?php echo htmlspecialchars($job['company_name']); ?></div>
                        <div class="job-meta">
                            <span>
                                <span class="material-symbols-outlined">location_on</span>
                                <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                            </span>
                            <span>
                                <span class="material-symbols-outlined">work</span>
                                <?php echo ucfirst(str_replace('_', ' ', $job['job_type'] ?? 'Full-time')); ?>
                            </span>
                            <?php if (!empty($job['salary_min']) || !empty($job['salary_max'])): ?>
                            <span>
                                <span class="material-symbols-outlined">payments</span>
                                <?php if (!empty($job['salary_min']) && !empty($job['salary_max'])): ?>
                                    ₱<?php echo number_format($job['salary_min']); ?> - ₱<?php echo number_format($job['salary_max']); ?>
                                <?php elseif (!empty($job['salary_min'])): ?>
                                    ₱<?php echo number_format($job['salary_min']); ?>+
                                <?php elseif (!empty($job['salary_max'])): ?>
                                    Up to ₱<?php echo number_format($job['salary_max']); ?>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                            <span>
                                <span class="material-symbols-outlined">people</span>
                                <?php echo $job['total_applicants'] ?? 0; ?> applicants
                            </span>
                            <span>
                                <span class="material-symbols-outlined">schedule</span>
                                Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                            </span>
                        </div>
                        <div class="job-status">
                            <span class="badge badge-<?php echo $job['status']; ?>">
                                <?php echo ucfirst($job['status']); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Job Description -->
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">description</span>
                            Job Description
                        </div>
                        <div class="section-content">
                            <?php echo nl2br(htmlspecialchars($job['description'] ?? '')); ?>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Skills Required -->
                    <?php if (!empty($job['skills_required'])): ?>
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">star</span>
                            Skills Required
                        </div>
                        <div class="section-content">
                            <?php echo nl2br(htmlspecialchars($job['skills_required'])); ?>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">
                    <?php endif; ?>

                    <!-- Job Overview -->
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">info</span>
                            Job Overview
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="label">Position</span>
                                <span class="value"><?php echo htmlspecialchars($job['title']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Company</span>
                                <span class="value"><?php echo htmlspecialchars($job['company_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Location</span>
                                <span class="value"><?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Job Type</span>
                                <span class="value"><?php echo ucfirst(str_replace('_', ' ', $job['job_type'] ?? 'Full-time')); ?></span>
                            </div>
                            <?php if (!empty($job['salary_min']) || !empty($job['salary_max'])): ?>
                            <div class="info-item">
                                <span class="label">Salary</span>
                                <span class="value">
                                    <?php if (!empty($job['salary_min']) && !empty($job['salary_max'])): ?>
                                        ₱<?php echo number_format($job['salary_min']); ?> - ₱<?php echo number_format($job['salary_max']); ?>
                                    <?php elseif (!empty($job['salary_min'])): ?>
                                        ₱<?php echo number_format($job['salary_min']); ?>+
                                    <?php elseif (!empty($job['salary_max'])): ?>
                                        Up to ₱<?php echo number_format($job['salary_max']); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <span class="label">Posted</span>
                                <span class="value"><?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Posted By</span>
                                <span class="value"><?php echo htmlspecialchars($job['client_first_name'] . ' ' . $job['client_last_name']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Apply Section -->
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">send</span>
                            Apply Now
                        </div>

                        <?php if ($hasApplied): ?>
                            <div class="applied-status">
                                <span class="material-symbols-outlined">check_circle</span>
                                <div class="status-text">You have applied!</div>
                                <div class="status-sub">
                                    Status: <span class="badge <?php echo $statusBadges[$applicationStatus] ?? 'badge-pending'; ?>">
                                        <?php echo $statusLabels[$applicationStatus] ?? ucfirst($applicationStatus); ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Face Verification Status -->
                            <?php if ($faceVerified): ?>
                            <div style="margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0.75rem; background:#d1fae5; border-radius:0.5rem; border:1px solid #6ee7b7;">
                                <span class="material-symbols-outlined" style="color:#059669;">verified</span>
                                <span style="font-size:0.8125rem; color:#065f46; font-weight:500;">Face verified ✓</span>
                            </div>
                            <?php endif; ?>

                            <button class="btn btn-primary btn-block apply-btn" onclick="openFaceScanner()">
                                <span class="material-symbols-outlined">scan</span>
                                <?php echo $faceVerified ? 'Apply Now' : 'Apply Now (Face Verification Required)'; ?>
                            </button>

                            <!-- Hidden form that gets submitted after face capture -->
                            <form id="applyForm" method="POST" enctype="multipart/form-data" style="display:none;">
                                <input type="hidden" name="action" value="apply">
                                <input type="hidden" name="cover_letter" id="applyCoverLetter">
                                <input type="hidden" name="resume" id="applyResume">
                            </form>

                            <!-- Original application form (kept for manual apply) -->
                            <div class="application-form" id="applicationForm" style="display:none;">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="apply">
                                    
                                    <div class="form-group">
                                        <label for="coverLetter">Cover Letter</label>
                                        <textarea name="cover_letter" id="coverLetter" class="form-control" 
                                                  placeholder="Tell us why you're the perfect fit for this role..."></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>Resume / CV</label>
                                        <div class="file-input-wrapper">
                                            <button type="button" class="btn btn-outline btn-block">
                                                <span class="material-symbols-outlined">upload</span>
                                                Upload Resume (PDF, DOC, DOCX)
                                            </button>
                                            <input type="file" name="resume" accept=".pdf,.doc,.docx">
                                        </div>
                                        <div style="font-size:0.6875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                                            Max 5MB. Leave empty to use your uploaded resume.
                                        </div>
                                        <?php if (!empty($applicant['resume_path'])): ?>
                                        <div style="font-size:0.75rem; color:var(--primary); margin-top:0.25rem;">
                                            ✅ Current resume: <?php echo htmlspecialchars(basename($applicant['resume_path'])); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div style="display:flex; gap:0.5rem; margin-top:1rem;">
                                        <button type="button" class="btn btn-ghost" onclick="toggleApplicationForm()" style="flex:1;">
                                            Cancel
                                        </button>
                                        <button type="submit" class="btn btn-success" style="flex:2;">
                                            <span class="material-symbols-outlined">check</span>
                                            Submit Application
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Similar Jobs -->
                    <?php if (!empty($similarJobs)): ?>
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">work</span>
                            Similar Jobs
                        </div>
                        <?php foreach ($similarJobs as $similarJob): ?>
                        <a href="job_details.php?id=<?php echo $similarJob['id']; ?>" class="similar-job-card">
                            <div class="job-title-sm"><?php echo htmlspecialchars($similarJob['title']); ?></div>
                            <div class="job-company-sm"><?php echo htmlspecialchars($similarJob['company_name']); ?></div>
                            <div class="job-meta-sm">
                                <span>📍 <?php echo htmlspecialchars($similarJob['location'] ?? 'Remote'); ?></span>
                                <span>📋 <?php echo ucfirst(str_replace('_', ' ', $similarJob['job_type'] ?? 'Full-time')); ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- =============================================
    FACE SCANNER MODAL
    ============================================= -->
    <div class="face-scan-modal" id="faceScanModal">
        <div class="face-scan-modal-content">
            <div class="face-scan-header">
                <h3>
                    <span class="material-symbols-outlined" style="color:var(--primary);">scan</span>
                    Face Verification Required
                </h3>
                <p>Please position your face for biometric verification</p>
            </div>

            <div class="face-scan-wrapper">
                <video id="faceScanVideo" autoplay muted playsinline></video>
                <canvas id="faceScanCanvas"></canvas>
                <div class="face-scan-guide">
                    <div class="face-scan-circle">
                        <span class="guide-text">Position your face here</span>
                    </div>
                </div>
            </div>

            <div class="face-scan-status">
                <span class="status-dot idle" id="faceScanStatusDot"></span>
                <span id="faceScanStatusText">Initializing camera...</span>
            </div>

            <div class="face-scan-actions">
                <button type="button" class="btn btn-outline" onclick="closeFaceScanner()">
                    <span class="material-symbols-outlined">close</span>
                    Cancel
                </button>
                <button type="button" class="btn btn-primary" id="captureFaceBtn" onclick="captureFace()" disabled>
                    <span class="material-symbols-outlined">scan</span>
                    Capture Face
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

    <script>
        // =============================================
        // SIDEBAR TOGGLE
        // =============================================
        const sidebar = document.getElementById('appSidebar');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const isMobile = window.innerWidth <= 768;
        const savedState = localStorage.getItem('sidebarCollapsed');

        if (savedState === 'true' && !isMobile) {
            sidebar.classList.add('collapsed');
            sidebarToggleIcon.textContent = 'chevron_right';
        }

        sidebarToggleBtn.addEventListener('click', function() {
            if (window.innerWidth <= 768) return;
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            sidebarToggleIcon.textContent = isCollapsed ? 'chevron_right' : 'chevron_left';
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });

        // =============================================
        // MOBILE SIDEBAR
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

        mobileMenuBtn.addEventListener('click', openMobileSidebar);
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);

        // =============================================
        // PROFILE DROPDOWN
        // =============================================
        const profileToggle = document.getElementById('profileToggle');
        const profileMenu = document.getElementById('profileMenu');

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

        // =============================================
        // APPLICATION FORM TOGGLE
        // =============================================
        function toggleApplicationForm() {
            const form = document.getElementById('applicationForm');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                form.style.display = 'none';
            }
        }

     // =============================================
// FACE SCANNER - IMPROVED CAPTURE
// =============================================
let faceScanVideo = null;
let faceScanCanvas = null;
let faceScanStream = null;
let faceScanDetection = null;
let faceScanInitialized = false;
let faceScanCaptured = false;
let faceScanTimer = null;
let faceApiLoaded = false;
let captureAttempts = 0;

function openFaceScanner() {
    const modal = document.getElementById('faceScanModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    captureAttempts = 0;
    
    // Reset state
    faceScanCaptured = false;
    faceScanDetection = null;
    
    if (typeof faceapi === 'undefined') {
        updateFaceScanStatus('❌ Face API not loaded. Please refresh.', 'error');
        document.getElementById('captureFaceBtn').disabled = true;
        return;
    }
    
    initFaceScanner();
}

function closeFaceScanner() {
    const modal = document.getElementById('faceScanModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    stopFaceScanner();
}

async function initFaceScanner() {
    try {
        faceScanVideo = document.getElementById('faceScanVideo');
        faceScanCanvas = document.getElementById('faceScanCanvas');
        
        // Load face-api.js models
        const modelPath = '/CT1/public/js';
        await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath);
        await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
        await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
        await faceapi.nets.faceExpressionNet.loadFromUri(modelPath);
        
        faceApiLoaded = true;

        // Start camera
        faceScanStream = await navigator.mediaDevices.getUserMedia({
            video: { width: 480, height: 360, facingMode: 'user' },
            audio: false
        });

        faceScanVideo.srcObject = faceScanStream;
        await faceScanVideo.play();

        // Set canvas size
        faceScanCanvas.width = 480;
        faceScanCanvas.height = 360;

        updateFaceScanStatus('Camera ready - Position your face', 'idle');
        document.getElementById('captureFaceBtn').disabled = false;
        faceScanInitialized = true;

        // Start detection loop
        detectFaceForScan();

    } catch (error) {
        console.error('Face scanner error:', error);
        if (error.message && error.message.includes('Permission')) {
            updateFaceScanStatus('❌ Camera access denied. Please allow camera permissions.', 'error');
        } else {
            updateFaceScanStatus('❌ Camera error: ' + error.message, 'error');
        }
        document.getElementById('captureFaceBtn').disabled = true;
    }
}

function stopFaceScanner() {
    if (faceScanStream) {
        faceScanStream.getTracks().forEach(track => track.stop());
        faceScanStream = null;
    }
    if (faceScanTimer) {
        clearTimeout(faceScanTimer);
        faceScanTimer = null;
    }
    faceScanInitialized = false;
    
    if (faceScanCanvas) {
        const ctx = faceScanCanvas.getContext('2d');
        ctx.clearRect(0, 0, faceScanCanvas.width, faceScanCanvas.height);
    }
}

async function detectFaceForScan() {
    if (!faceScanInitialized || !faceApiLoaded) return;

    try {
        const options = new faceapi.TinyFaceDetectorOptions({
            inputSize: 224,
            scoreThreshold: 0.6
        });

        const detection = await faceapi.detectSingleFace(faceScanVideo, options)
            .withFaceLandmarks()
            .withFaceDescriptor();

        const ctx = faceScanCanvas.getContext('2d');
        ctx.clearRect(0, 0, faceScanCanvas.width, faceScanCanvas.height);

        if (detection) {
            // Draw detection on canvas - flip the coordinates to match the mirrored video
            const box = detection.detection.box;
            
            // Flip the x coordinates to match the mirrored video
            const flippedX = faceScanCanvas.width - box.x - box.width;
            
            ctx.strokeStyle = '#22c55e';
            ctx.lineWidth = 3;
            ctx.strokeRect(flippedX, box.y, box.width, box.height);

            // Draw landmarks flipped horizontally
            const landmarks = detection.landmarks;
            const positions = landmarks.positions;
            
            // Draw landmarks with flipped x coordinates
            ctx.fillStyle = '#22c55e';
            ctx.strokeStyle = '#22c55e';
            ctx.lineWidth = 2;
            
            // Draw the facial landmarks (mirrored)
            for (let i = 0; i < positions.length; i++) {
                const flippedPosX = faceScanCanvas.width - positions[i].x;
                ctx.beginPath();
                ctx.arc(flippedPosX, positions[i].y, 2, 0, 2 * Math.PI);
                ctx.fill();
            }
            
            // Draw connections between landmarks (jawline, eyebrows, etc.)
            // This is a simplified version - you can use faceapi.draw for more accuracy
            
            updateFaceScanStatus('✅ Face detected - Ready to capture', 'success');
            document.getElementById('captureFaceBtn').disabled = false;
            faceScanDetection = detection;

        } else {
            updateFaceScanStatus('Looking for face...', 'scanning');
            document.getElementById('captureFaceBtn').disabled = true;
            faceScanDetection = null;
        }

    } catch (error) {
        // Silent fail for loop
    }

    faceScanTimer = setTimeout(detectFaceForScan, 150);
}
function updateFaceScanStatus(text, type = 'idle') {
    const dot = document.getElementById('faceScanStatusDot');
    const textEl = document.getElementById('faceScanStatusText');

    dot.className = 'status-dot ' + type;
    textEl.textContent = text;
}

async function captureFace() {
    if (!faceScanDetection || !faceScanInitialized) {
        showToast('No face detected. Please position your face.', 'error');
        return;
    }

    const captureBtn = document.getElementById('captureFaceBtn');
    captureBtn.disabled = true;
    captureBtn.innerHTML = '<span class="loading-spinner"></span> Processing...';
    captureAttempts++;

    updateFaceScanStatus('Processing face data... (Attempt ' + captureAttempts + ')', 'scanning');

    try {
        // Get face descriptor as array
        const descriptor = Array.from(faceScanDetection.descriptor);
        
        // Validate descriptor
        if (!descriptor || descriptor.length < 10) {
            throw new Error('Invalid face descriptor data');
        }

        // Take snapshot
        const snapshot = await takeFaceSnapshot();

        // Prepare request data
        const requestData = {
            action: 'enroll',
            user_id: <?php echo $userId; ?>,
            descriptor: descriptor,
            snapshot: snapshot
        };

        console.log('Sending face data:', {
            user_id: requestData.user_id,
            descriptor_length: requestData.descriptor.length,
            snapshot_length: requestData.snapshot ? requestData.snapshot.length : 0
        });

        // Send to server for enrollment
        const response = await fetch('/CT1/api/biometric_verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(requestData)
        });

        // Check if response is ok
        if (!response.ok) {
            const text = await response.text();
            console.error('Server error response:', text);
            throw new Error('Server returned ' + response.status + ': ' + text);
        }

        const data = await response.json();
        console.log('Server response:', data);

        if (data.success) {
            faceScanCaptured = true;
            updateFaceScanStatus('✅ Face captured successfully!', 'success');
            showToast('Face verification complete!', 'success');

            // Close modal and submit application
            setTimeout(() => {
                closeFaceScanner();
                // Submit the application form
                document.getElementById('applyForm').submit();
            }, 1000);

        } else {
            updateFaceScanStatus('❌ Face capture failed: ' + (data.error || 'Unknown error'), 'error');
            showToast('Face capture failed: ' + (data.error || 'Please try again.'), 'error');
            captureBtn.disabled = false;
            captureBtn.innerHTML = '<span class="material-symbols-outlined">scan</span> Capture Face';
        }

    } catch (error) {
        console.error('Capture error:', error);
        updateFaceScanStatus('❌ Error: ' + error.message, 'error');
        showToast('Error capturing face: ' + error.message, 'error');
        captureBtn.disabled = false;
        captureBtn.innerHTML = '<span class="material-symbols-outlined">scan</span> Capture Face';
    }
}

function takeFaceSnapshot() {
    return new Promise((resolve) => {
        const canvas = document.createElement('canvas');
        canvas.width = faceScanVideo.videoWidth || 480;
        canvas.height = faceScanVideo.videoHeight || 360;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(faceScanVideo, 0, 0, canvas.width, canvas.height);
        resolve(canvas.toDataURL('image/jpeg', 0.8));
    });
}

        function showToast(message, type) {
            type = type || 'info';
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info', 'warning': 'warning' };
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
        // KEYBOARD SHORTCUTS
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
                if (document.getElementById('faceScanModal').classList.contains('active')) {
                    closeFaceScanner();
                }
            }
        });

        // =============================================
        // RESPONSIVE HANDLING
        // =============================================
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                const width = window.innerWidth;
                if (width <= 768) {
                    sidebar.classList.remove('collapsed');
                    sidebarToggleIcon.textContent = 'chevron_left';
                } else {
                    sidebar.classList.remove('mobile-open');
                    sidebarBackdrop.classList.remove('active');
                    document.body.style.overflow = '';
                    const saved = localStorage.getItem('sidebarCollapsed');
                    if (saved === 'true') {
                        sidebar.classList.add('collapsed');
                        sidebarToggleIcon.textContent = 'chevron_right';
                    } else {
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'chevron_left';
                    }
                }
            }, 250);
        });

        // =============================================
        // FILE INPUT LABEL UPDATE
        // =============================================
        document.querySelector('input[type="file"]')?.addEventListener('change', function() {
            if (this.files.length > 0) {
                const label = this.closest('.file-input-wrapper').querySelector('.btn');
                if (label) {
                    label.innerHTML = `<span class="material-symbols-outlined">check</span> ${this.files[0].name}`;
                    label.style.borderColor = '#059669';
                    label.style.color = '#059669';
                }
            }
        });

        console.log('💼 ISMERS Job Details loaded successfully!');
        <?php if ($matchScore !== null): ?>
        console.log('🤖 Match Score: <?php echo $matchScore; ?>%');
        <?php endif; ?>
        <?php if ($faceVerified): ?>
        console.log('✅ Face Verified: Yes');
        <?php endif; ?>
    </script>

</body>
</html>