<?php
// portals/applicant/apply.php - Apply for Job
session_start();

// Include configuration file
require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has the correct role
if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Applicant';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// Database helper function (if not already in config.php)
if (!function_exists('getRecord')) {
    function getRecord($sql, $params = [], $types = "") {
        global $conn;
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return ['count' => 0];
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?? ['count' => 0];
    }
}

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// Get applications count for the badge
$applications = [];
if ($applicantId) {
    $applications = getApplicationsByApplicant($applicantId);
}
$totalApplications = count($applications);

// Get job ID from URL
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

// If no job ID, redirect to job search
if ($jobId <= 0) {
    header('Location: job_search.php');
    exit;
}

// Get job details
$job = getRecord("SELECT jo.*, c.company_name FROM job_orders jo 
                  JOIN clients c ON jo.client_id = c.id 
                  WHERE jo.id = ?", [$jobId], "i");

// If job not found or not open, redirect
if (!$job || !in_array($job['status'], ['open', 'ongoing'])) {
    header('Location: job_search.php');
    exit;
}

// Check if already applied
$alreadyApplied = false;
if ($applicantId) {
    $existingApplication = getRecord(
        "SELECT id FROM applications WHERE applicant_id = ? AND job_order_id = ?",
        [$applicantId, $jobId],
        "ii"
    );
    if ($existingApplication) {
        $alreadyApplied = true;
    }
}

// Initialize variables
$successMessage = '';
$errorMessage = '';
$coverLetter = '';
$resumePath = '';
$uploadError = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyApplied) {
    $coverLetter = trim($_POST['cover_letter'] ?? '');
    $agreeTerms = isset($_POST['agree_terms']);
    
    // Handle file upload
    $resumeFile = $_FILES['resume'] ?? null;
    $uploadSuccess = false;
    
    // Validate
    $errors = [];
    
    if (empty($coverLetter)) {
        $errors[] = 'Please write a cover letter.';
    }
    if (strlen($coverLetter) < 50) {
        $errors[] = 'Cover letter must be at least 50 characters.';
    }
    
    // Resume validation
    if (!$resumeFile || $resumeFile['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please upload your resume/CV.';
    } elseif ($resumeFile['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Error uploading file. Please try again.';
    } else {
        // Validate file type
        $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $fileType = mime_content_type($resumeFile['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Please upload a PDF, DOC, or DOCX file.';
        }
        
        // Validate file size (max 5MB)
        if ($resumeFile['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File size must be less than 5MB.';
        }
        
        // Upload file if no errors
        if (empty($errors)) {
            $uploadDir = '../../uploads/resumes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExtension = pathinfo($resumeFile['name'], PATHINFO_EXTENSION);
            $fileName = 'resume_' . $userId . '_' . $jobId . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($resumeFile['tmp_name'], $filePath)) {
                $resumePath = 'uploads/resumes/' . $fileName;
                $uploadSuccess = true;
            } else {
                $errors[] = 'Failed to upload resume. Please try again.';
            }
        }
    }
    
    if (!$agreeTerms) {
        $errors[] = 'You must agree to the terms to proceed.';
    }
    
    // Check if applicant has a complete profile
    if (empty($applicant['skills'])) {
        $errors[] = 'Please complete your profile skills before applying. <a href="edit_profile.php" style="color:var(--primary-light);">Edit Profile</a>';
    }
    
    if (empty($errors) && $uploadSuccess) {
        // Insert application
        $sql = "INSERT INTO applications (applicant_id, job_order_id, cover_letter, resume_path, status, face_scan_verified, applied_at) 
                VALUES (?, ?, ?, ?, 'pending', 0, NOW())";
        
        $result = insertRecord($sql, [$applicantId, $jobId, $coverLetter, $resumePath], "iiss");
        
        if ($result) {
            $successMessage = 'Application submitted successfully!';
            
            // Log activity
            logActivity($userId, 'Applied for Job', 'application', $result, 'Applied for: ' . $job['title'] . ' | Resume: ' . $resumePath);
            
            // Redirect after 2 seconds
            header('Refresh: 2; URL=applications.php');
        } else {
            $errorMessage = 'Failed to submit application. Please try again.';
            // Clean up uploaded file if application fails
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    } else {
        $errorMessage = implode('<br>', $errors);
    }
}

// Role labels for display
$roleLabels = [
    'admin' => 'Admin',
    'hr_manager' => 'HR Manager',
    'recruiter' => 'Recruiter',
    'client' => 'Client',
    'applicant' => 'Applicant',
    'employee' => 'Employee',
    'supervisor' => 'Supervisor'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Apply for Job - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - APPLY FOR JOB
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
            --success-color: #22c55e;
            --error-color: #dc2626;
            --warning-color: #f59e0b;
        }

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
            max-width: 56rem;
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

        .page-header .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger {
            background: var(--error-color);
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

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        /* =============================================
           JOB SUMMARY
        ============================================= */
        .job-summary {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .job-summary .job-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .job-summary .job-company {
            font-size: 0.9375rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.5rem;
        }

        .job-summary .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.25rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }

        .job-summary .job-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .job-summary .job-meta .meta-item .material-symbols-outlined {
            font-size: 1rem;
        }

        /* =============================================
           FORM CARD
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
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .card-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .card-body {
            padding: 1.5rem;
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

        .form-group textarea.form-control {
            resize: vertical;
            min-height: 150px;
        }

        .form-group .form-control.is-invalid {
            border-color: #dc2626;
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

        .form-group .char-count {
            text-align: right;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* =============================================
           FILE UPLOAD
        ============================================= */
        .file-upload-wrapper {
            position: relative;
        }

        .file-upload-area {
            border: 2px dashed var(--slate-200);
            border-radius: 0.75rem;
            padding: 2rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--bg-surface-low);
        }

        .file-upload-area:hover {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.04);
        }

        .file-upload-area.dragover {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.08);
        }

        .file-upload-area .upload-icon .material-symbols-outlined {
            font-size: 3rem;
            color: var(--text-on-surface-variant);
            display: block;
            margin-bottom: 0.5rem;
        }

        .file-upload-area .upload-text {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .file-upload-area .upload-hint {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .file-upload-area .upload-formats {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
            opacity: 0.6;
        }

        .file-upload-area .file-preview {
            display: none;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            background: var(--bg-surface);
            border-radius: 0.75rem;
            border: 1px solid var(--success-color);
            margin-top: 0.75rem;
        }

        .file-upload-area .file-preview.show {
            display: flex;
        }

        .file-upload-area .file-preview .file-icon .material-symbols-outlined {
            font-size: 2rem;
            color: var(--success-color);
        }

        .file-upload-area .file-preview .file-info {
            flex: 1;
            text-align: left;
        }

        .file-upload-area .file-preview .file-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .file-upload-area .file-preview .file-size {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .file-upload-area .file-preview .file-remove {
            background: none;
            border: none;
            color: var(--error-color);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1.25rem;
            transition: all var(--transition-fast);
        }

        .file-upload-area .file-preview .file-remove:hover {
            opacity: 0.7;
            transform: scale(1.1);
        }

        #resume_file {
            display: none;
        }

        /* =============================================
           CHECKBOX
        ============================================= */
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
            padding: 0.5rem 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 1.125rem;
            height: 1.125rem;
            margin-top: 0.125rem;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-group label {
            font-weight: 400;
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            cursor: pointer;
        }

        .checkbox-group label a {
            color: var(--primary);
            font-weight: 600;
        }

        .checkbox-group label a:hover {
            text-decoration: underline;
        }

        /* =============================================
           PROFILE INFO BOX
        ============================================= */
        .profile-info-box {
            background: var(--bg-surface-low);
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .profile-info-box .user-details .user-name {
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .profile-info-box .user-details .user-email {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }

        .profile-info-box .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .profile-info-box .status-badge.complete {
            background: #f0fdf4;
            color: #22c55e;
        }

        .profile-info-box .status-badge.incomplete {
            background: #fef2f2;
            color: #dc2626;
        }

        .profile-info-box .status-badge .material-symbols-outlined {
            font-size: 0.875rem;
        }

        /* =============================================
           ALREADY APPLIED
        ============================================= */
        .already-applied {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .already-applied .material-symbols-outlined {
            font-size: 4rem;
            color: var(--warning-color);
            display: block;
            margin-bottom: 0.75rem;
        }

        .already-applied h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .already-applied p {
            color: var(--text-on-surface-variant);
            margin-bottom: 1rem;
        }

        /* =============================================
           MESSAGES
        ============================================= */
        .message {
            padding: 0.875rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid transparent;
        }

        .message .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
            margin-top: 0.0625rem;
        }

        .message.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #16a34a;
        }

        .message.success .material-symbols-outlined {
            color: #16a34a;
        }

        .message.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .message.error .material-symbols-outlined {
            color: #dc2626;
        }

        .message.info {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #2563eb;
        }

        .message.info .material-symbols-outlined {
            color: #2563eb;
        }

        .message .btn {
            margin-left: auto;
            flex-shrink: 0;
        }

        /* =============================================
           FORM ACTIONS
        ============================================= */
        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
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
            background: var(--success-color);
        }

        .toast.error {
            background: var(--error-color);
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

            .job-summary {
                padding: 1rem 1.25rem;
            }

            .job-summary .job-title {
                font-size: 1.125rem;
            }

            .card-body {
                padding: 1rem 1.25rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .file-upload-area {
                padding: 1.5rem 1rem;
            }

            .message .btn {
                margin-left: 0;
                width: 100%;
                justify-content: center;
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

            .job-summary .job-title {
                font-size: 1rem;
            }

            .job-summary .job-meta {
                font-size: 0.75rem;
                gap: 0.5rem 0.75rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .card-header h3 {
                font-size: 1rem;
            }

            .card-body {
                padding: 0.75rem 1rem;
            }

            .form-group {
                margin-bottom: 0.875rem;
            }

            .file-upload-area .upload-icon .material-symbols-outlined {
                font-size: 2.5rem;
            }

            .file-upload-area .upload-text {
                font-size: 0.875rem;
            }

            .profile-info-box {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .profile-info-box .status-badge {
                justify-content: center;
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
    </style>
</head>
<body>

    <!-- Sidebar Backdrop (Mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =============================================
    SIDEBAR - FIXED
    ============================================= -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="px-5 pt-6 pb-5 border-b border-slate-200">
            <div class="sidebar-brand-card">
                <span class="sidebar-brand-icon">
                    <span class="material-symbols-outlined">person</span>
                </span>
                <p class="sidebar-brand-text">ISMERS</p>
                <p class="sidebar-brand-category">Applicant Portal</p>
            </div>
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
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">search</span>
                <span class="nav-text">Job Search</span>
            </a>

            <div class="nav-label" style="margin-top:1.5rem;">Settings</div>

            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
            <a href="../../logout.php" class="logout-btn">
                <span class="material-symbols-outlined">logout</span>
                <span class="logout-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- =============================================
    MAIN CONTENT
    ============================================= -->
    <div class="main-wrapper" id="mainWrapper">
        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                    <span class="material-symbols-outlined">chevron_left</span>
                </button>
                <span class="separator">/</span>
                <span style="font-weight:600; font-size:0.875rem;">Apply for Job</span>
            </div>

            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? 'Applicant')); ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <button class="dropdown-item" onclick="window.location.href='profile.php'">
                        <span class="material-symbols-outlined">person</span>
                        Profile
                    </button>
                    <button class="dropdown-item" onclick="window.location.href='settings.php'">
                        <span class="material-symbols-outlined">settings</span>
                        Settings
                    </button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">
                        <span class="material-symbols-outlined">logout</span>
                        Logout
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
                        <span class="material-symbols-outlined">description</span>
                        <span>Apply</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">Submit Application</span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Apply for Position</h1>
                        <p>Submit your application for this job opportunity</p>
                    </div>
                    <div class="header-actions">
                        <a href="job_search.php" class="btn btn-outline">
                            <span class="material-symbols-outlined">arrow_back</span>
                            Back to Jobs
                        </a>
                    </div>
                </div>

                <!-- Success Message -->
                <?php if (!empty($successMessage)): ?>
                    <div class="message success">
                        <span class="material-symbols-outlined">check_circle</span>
                        <div>
                            <strong><?php echo htmlspecialchars($successMessage); ?></strong>
                            <span style="display:block; font-weight:400;">Redirecting to your applications...</span>
                        </div>
                        <a href="applications.php" class="btn btn-sm" style="background:rgba(22,163,74,0.15); color:#16a34a;">
                            View Applications
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if (!empty($errorMessage)): ?>
                    <div class="message error">
                        <span class="material-symbols-outlined">error</span>
                        <div>
                            <strong>Error:</strong>
                            <span style="display:block; font-weight:400;"><?php echo $errorMessage; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Job Summary -->
                <div class="job-summary">
                    <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                    <div class="job-company"><?php echo htmlspecialchars($job['company_name']); ?></div>
                    <div class="job-meta">
                        <span class="meta-item">
                            <span class="material-symbols-outlined">work_history</span>
                            <?php echo htmlspecialchars($job['job_type'] ?? 'Full-time'); ?>
                        </span>
                        <span class="meta-item">
                            <span class="material-symbols-outlined">location_on</span>
                            <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                        </span>
                        <span class="meta-item">
                            <span class="material-symbols-outlined">trending_up</span>
                            <?php echo htmlspecialchars($job['experience_level'] ?? 'Entry'); ?>
                        </span>
                        <?php if (!empty($job['salary_range'])): ?>
                            <span class="meta-item">
                                <span class="material-symbols-outlined">payments</span>
                                <?php echo htmlspecialchars($job['salary_range']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($job['urgency'])): ?>
                            <span class="meta-item">
                                <span class="material-symbols-outlined">priority_high</span>
                                <span style="text-transform:capitalize;"><?php echo htmlspecialchars($job['urgency']); ?> urgency</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Application Form -->
                <?php if ($alreadyApplied): ?>
                    <div class="card">
                        <div class="card-body already-applied">
                            <span class="material-symbols-outlined">info</span>
                            <h3>You've Already Applied</h3>
                            <p>You have already submitted an application for this position. Please wait for the employer's response.</p>
                            <a href="applications.php" class="btn btn-primary">
                                <span class="material-symbols-outlined">work</span>
                                View My Applications
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <span class="material-symbols-outlined">edit_note</span>
                                Application Form
                            </h3>
                            <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                Fields with <span style="color:#dc2626;">*</span> are required
                            </span>
                        </div>
                        <div class="card-body">

                            <form method="POST" action="" enctype="multipart/form-data" id="applyForm" novalidate>

                                <!-- Cover Letter -->
                                <div class="form-group">
                                    <label for="coverLetter">Cover Letter <span class="required">*</span></label>
                                    <textarea id="coverLetter" name="cover_letter" class="form-control" 
                                              placeholder="Write a compelling cover letter explaining why you're the perfect candidate for this position..." 
                                              rows="6" required><?php echo htmlspecialchars($coverLetter); ?></textarea>
                                    <div class="helper-text">
                                        <span class="material-symbols-outlined">info</span>
                                        Minimum 50 characters. Be specific about your skills and experience.
                                    </div>
                                    <div class="char-count"><span id="charCount">0</span> / 50+ characters</div>
                                </div>

                                <!-- Resume Upload -->
                                <div class="form-group">
                                    <label>Resume / CV <span class="required">*</span></label>
                                    <div class="file-upload-wrapper">
                                        <div class="file-upload-area" id="fileDropArea">
                                            <div class="upload-icon">
                                                <span class="material-symbols-outlined">upload_file</span>
                                            </div>
                                            <div class="upload-text">Drop your resume here or click to browse</div>
                                            <div class="upload-hint">Supported formats: PDF, DOC, DOCX</div>
                                            <div class="upload-formats">Maximum file size: 5MB</div>
                                            
                                            <div class="file-preview" id="filePreview">
                                                <span class="file-icon">
                                                    <span class="material-symbols-outlined">description</span>
                                                </span>
                                                <div class="file-info">
                                                    <div class="file-name" id="fileName">resume.pdf</div>
                                                    <div class="file-size" id="fileSize">0 KB</div>
                                                </div>
                                                <button type="button" class="file-remove" id="fileRemove">×</button>
                                            </div>
                                        </div>
                                        <input type="file" id="resume_file" name="resume" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                    </div>
                                    <div class="helper-text">
                                        <span class="material-symbols-outlined">info</span>
                                        Upload your most recent resume or CV
                                    </div>
                                </div>

                                <!-- Profile Info -->
                                <div class="form-group">
                                    <label>Your Information</label>
                                    <div class="profile-info-box">
                                        <div class="user-details">
                                            <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                                            <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                                        </div>
                                        <?php if (empty($applicant['skills'])): ?>
                                            <span class="status-badge incomplete">
                                                <span class="material-symbols-outlined">warning</span>
                                                Profile incomplete
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge complete">
                                                <span class="material-symbols-outlined">check_circle</span>
                                                Profile complete
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Terms -->
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="agreeTerms" name="agree_terms" required>
                                        <label for="agreeTerms">
                                            I confirm that all the information provided is accurate and complete. 
                                            I understand that false information may result in disqualification.
                                            <a href="#" onclick="event.preventDefault(); alert('Terms and conditions will be displayed here.');">View Terms</a>
                                        </label>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-success">
                                        <span class="material-symbols-outlined">send</span>
                                        Submit Application
                                    </button>
                                    <a href="job_search.php" class="btn btn-outline">
                                        <span class="material-symbols-outlined">cancel</span>
                                        Cancel
                                    </a>
                                </div>

                            </form>

                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </main>
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
        // 4. CHARACTER COUNTER
        // =============================================
        const coverLetter = document.getElementById('coverLetter');
        const charCount = document.getElementById('charCount');

        if (coverLetter && charCount) {
            function updateCharCount() {
                const length = coverLetter.value.length;
                charCount.textContent = length;
                if (length >= 50) {
                    charCount.style.color = '#22c55e';
                } else {
                    charCount.style.color = '#dc2626';
                }
            }
            
            coverLetter.addEventListener('input', updateCharCount);
            updateCharCount();
        }

        // =============================================
        // 5. FILE UPLOAD
        // =============================================
        const fileInput = document.getElementById('resume_file');
        const dropArea = document.getElementById('fileDropArea');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const fileRemove = document.getElementById('fileRemove');

        // Click to upload
        dropArea.addEventListener('click', function() {
            fileInput.click();
        });

        // File selected
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                showFilePreview(this.files[0]);
            }
        });

        // Drag and drop
        dropArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        dropArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });

        dropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                fileInput.files = e.dataTransfer.files;
                showFilePreview(e.dataTransfer.files[0]);
            }
        });

        function showFilePreview(file) {
            const validTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            const ext = file.name.split('.').pop().toLowerCase();
            const validExt = ['pdf', 'doc', 'docx'];
            
            if (!validExt.includes(ext)) {
                showToast('Please upload a PDF, DOC, or DOCX file.', 'error');
                fileInput.value = '';
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                showToast('File size must be less than 5MB.', 'error');
                fileInput.value = '';
                return;
            }
            
            fileName.textContent = file.name;
            fileSize.textContent = (file.size / 1024).toFixed(1) + ' KB';
            filePreview.classList.add('show');
        }

        fileRemove.addEventListener('click', function() {
            fileInput.value = '';
            filePreview.classList.remove('show');
        });

        // =============================================
        // 6. FORM VALIDATION
        // =============================================
        document.getElementById('applyForm').addEventListener('submit', function(e) {
            const coverLetter = document.getElementById('coverLetter');
            const fileInput = document.getElementById('resume_file');
            const agreeTerms = document.getElementById('agreeTerms');
            let errors = [];
            let hasError = false;

            // Reset styles
            coverLetter.classList.remove('is-invalid');
            agreeTerms.style.borderColor = '';

            // Validate cover letter
            if (!coverLetter.value.trim() || coverLetter.value.trim().length < 50) {
                errors.push('Cover letter must be at least 50 characters.');
                coverLetter.classList.add('is-invalid');
                hasError = true;
            }

            // Validate file
            if (!fileInput.files || !fileInput.files[0]) {
                errors.push('Please upload your resume/CV.');
                dropArea.style.borderColor = '#dc2626';
                hasError = true;
            }

            // Validate terms
            if (!agreeTerms.checked) {
                errors.push('You must agree to the terms to proceed.');
                agreeTerms.style.outline = '2px solid #dc2626';
                hasError = true;
            }

            if (hasError) {
                e.preventDefault();
                showToast('Please fix the following errors:\n• ' + errors.join('\n• '), 'error');
            }
        });

        // Clear error styling on input
        document.getElementById('coverLetter').addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });

        document.getElementById('resume_file').addEventListener('change', function() {
            document.getElementById('fileDropArea').style.borderColor = '';
        });

        document.getElementById('agreeTerms').addEventListener('change', function() {
            this.style.outline = '';
        });

        // =============================================
        // 7. TOAST SYSTEM
        // =============================================
        function showToast(message, type = 'info') {
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            
            if (message.includes('\n')) {
                toast.style.whiteSpace = 'pre-line';
            }
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 5000);
        }

        // =============================================
        // 8. RESPONSIVE HANDLING
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

        // =============================================
        // 9. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        console.log('📝 ISMERS Apply Page loaded successfully!');
    </script>

</body>
</html>