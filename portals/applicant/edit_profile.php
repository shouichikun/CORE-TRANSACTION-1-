<?php
// portals/applicant/edit_profile.php - Edit Profile
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

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// Get applications count for the badge
$applications = [];
if ($applicantId) {
    $applications = getApplicationsByApplicant($applicantId);
}
$totalApplications = count($applications);

// Get user data
$user = getUserById($userId);

// Get applicant interests
$interests = [];
if ($applicantId) {
    $interests = getApplicantInterests($applicantId);
}

// Initialize variables
$successMessage = '';
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        // Get form data
        $careerObjective = trim($_POST['career_objective'] ?? '');
        $skills = trim($_POST['skills'] ?? '');
        $experience = trim($_POST['experience'] ?? '');
        $education = trim($_POST['education'] ?? '');
        
        // Update applicant profile
        $applicantData = [
            'career_objective' => $careerObjective,
            'skills' => $skills,
            'experience' => $experience,
            'education' => $education
        ];
        
        $applicantUpdated = updateApplicant($applicantId, $applicantData);
        
        if ($applicantUpdated) {
            $successMessage = 'Profile updated successfully!';
            // Refresh applicant data
            $applicant = getApplicantByUserId($userId);
        } else {
            $errorMessage = 'Failed to update profile. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Edit Profile - ISMERS</title>
    
    <style>
        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-dark: #0a2647;
            --primary-blue: #1a3a5c;
            --primary-medium: #2c5f8a;
            --primary-light: #4a90d9;
            --primary-lighter: #6db3f2;
            --primary-gradient: linear-gradient(135deg, #1a3a5c 0%, #4a90d9 100%);
            --white: #ffffff;
            --gray-light: #f8f9fc;
            --gray-border: #e8ecf1;
            --text-dark: #1a2a3a;
            --text-gray: #5a6a7a;
            --shadow-sm: 0 2px 8px rgba(26, 58, 92, 0.08);
            --shadow-md: 0 8px 30px rgba(26, 58, 92, 0.12);
            --shadow-lg: 0 20px 60px rgba(26, 58, 92, 0.15);
            --radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--gray-light);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* ===== BUTTONS ===== */
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(74, 144, 217, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(74, 144, 217, 0.45);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
        }

        /* =============================================
                   SIDEBAR
                ============================================= */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--gray-border);
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
            overflow: hidden;
            box-shadow: 2px 0 12px rgba(0, 0, 0, 0.04);
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 16px;
            border-bottom: 1px solid var(--gray-border);
            min-height: 72px;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-icon {
            width: 38px;
            height: 38px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-text {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-blue);
            white-space: nowrap;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .brand-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-toggle {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            color: var(--text-gray);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-toggle:hover {
            color: var(--primary-blue);
        }

        .sidebar-toggle svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform 0.3s ease;
        }

        .sidebar.collapsed .sidebar-toggle svg {
            transform: rotate(180deg);
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            overflow-y: auto;
        }

        .sidebar-nav .nav-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-gray);
            padding: 12px 12px 8px;
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .nav-label {
            opacity: 0;
            height: 0;
            padding: 0;
            overflow: hidden;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-gray);
            transition: var(--transition);
            margin-bottom: 2px;
            position: relative;
            white-space: nowrap;
        }

        .sidebar-nav a:hover {
            background: rgba(74, 144, 217, 0.06);
            color: var(--primary-blue);
        }

        .sidebar-nav a.active {
            background: rgba(74, 144, 217, 0.1);
            color: var(--primary-blue);
        }

        .sidebar-nav a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 24px;
            background: var(--primary-gradient);
            border-radius: 0 4px 4px 0;
        }

        .sidebar-nav a .nav-icon {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .sidebar-nav a .nav-text {
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed a .nav-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-nav a .nav-badge {
            margin-left: auto;
            background: var(--primary-light);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 10px;
            border-radius: 50px;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed a .nav-badge {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-footer {
            padding: 16px 16px;
            border-top: 1px solid var(--gray-border);
            flex-shrink: 0;
        }

        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-footer .user-card .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .sidebar-footer .user-card .user-info {
            flex: 1;
            min-width: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-footer .user-card .user-info .user-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .sidebar-footer .user-card .user-info .user-email {
            font-size: 12px;
            color: var(--text-gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            color: #dc2626;
            transition: var(--transition);
            margin-top: 8px;
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
        }

        .sidebar-footer .logout-btn:hover {
            background: rgba(220, 38, 38, 0.08);
        }

        .sidebar-footer .logout-btn svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .sidebar-footer .logout-btn .logout-text {
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .logout-btn .logout-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        /* =============================================
                   MOBILE SIDEBAR
                ============================================= */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 999;
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: var(--text-dark);
        }

        .mobile-menu-toggle svg {
            width: 28px;
            height: 28px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* =============================================
                   MAIN CONTENT
                ============================================= */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            padding: 24px 32px 40px;
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed);
        }

        /* =============================================
                   EDIT PROFILE CONTENT
                ============================================= */
        .edit-wrapper {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 24px;
        }

        .page-header .header-left h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .page-header .header-left p {
            font-size: 14px;
            color: var(--text-gray);
        }

        /* ===== FORM CARD ===== */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray-border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--gray-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .card-body {
            padding: 24px;
        }

        /* ===== FORM ===== */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        .form-group label .required {
            color: #dc2626;
            margin-left: 2px;
        }

        .form-group .helper-text {
            font-size: 12px;
            color: var(--text-gray);
            margin-top: 4px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--gray-border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: var(--gray-light);
            transition: var(--transition);
            color: var(--text-dark);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-light);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group textarea#careerObjective {
            min-height: 80px;
        }

        .form-group textarea#experience {
            min-height: 150px;
        }

        .form-group textarea#education {
            min-height: 120px;
        }

        .form-group .char-count {
            text-align: right;
            font-size: 12px;
            color: var(--text-gray);
            margin-top: 4px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-border);
            flex-wrap: wrap;
        }

        /* ===== MESSAGES ===== */
        .message {
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }

        .message.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .message svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            flex-shrink: 0;
        }

        /* =============================================
                   RESPONSIVE
                ============================================= */
        @media (min-width: 768px) {
            .page-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .page-header .header-left h1 {
                font-size: 28px;
            }
        }

        @media (max-width: 767px) {
            :root {
                --sidebar-width: 280px;
                --sidebar-collapsed: 0px;
            }

            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width);
                transition: transform 0.3s ease;
                box-shadow: var(--shadow-lg);
                border-right: none;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar.collapsed {
                width: var(--sidebar-width);
            }

            .sidebar.collapsed .brand-text,
            .sidebar.collapsed .nav-text,
            .sidebar.collapsed .user-info,
            .sidebar.collapsed .logout-text,
            .sidebar.collapsed .nav-badge,
            .sidebar.collapsed .nav-label {
                opacity: 1;
                width: auto;
                overflow: visible;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 16px;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .sidebar-overlay.active {
                display: block;
            }

            .sidebar-footer .logout-btn .logout-text {
                opacity: 1 !important;
                width: auto !important;
            }

            .card-body {
                padding: 16px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 12px;
            }

            .page-header .header-left h1 {
                font-size: 20px;
            }

            .card-header {
                padding: 14px 16px;
            }

            .card-body {
                padding: 14px;
            }
        }
    </style>
</head>
<body>

    <!-- ===== SIDEBAR OVERLAY (Mobile) ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon">I</span>
            <span class="brand-text">ISMERS</span>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <svg viewBox="0 0 24 24">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="active">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="job_search.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span class="nav-text">Job Search</span>
            </a>

            <div class="nav-label" style="margin-top:16px;">Settings</div>

            <a href="settings.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
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
                <svg viewBox="0 0 24 24">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                <span class="logout-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content" id="mainContent">

        <!-- Mobile Header -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open menu">
                <svg viewBox="0 0 24 24">
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <span style="font-weight:700; color:var(--primary-blue); font-size:18px; display:none;" id="mobileBrand">ISMERS</span>
            <span class="user-info" style="display:flex; align-items:center; gap:10px;">
                <span class="avatar" style="width:36px; height:36px; border-radius:50%; background:var(--primary-gradient); display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:14px;">
                    <?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?>
                </span>
            </span>
        </div>

        <div class="edit-wrapper">

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <h1>Edit Profile</h1>
                    <p>Update your professional resume information</p>
                </div>
                <a href="profile.php" class="btn btn-outline">
                    <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5"/>
                        <path d="M12 19l-7-7 7-7"/>
                    </svg>
                    Back to Profile
                </a>
            </div>

            <!-- Success Message -->
            <?php if (!empty($successMessage)): ?>
            <div class="message success">
                <svg viewBox="0 0 24 24">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <span><?php echo htmlspecialchars($successMessage); ?></span>
            </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($errorMessage)): ?>
            <div class="message error">
                <svg viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <div class="card">
                <div class="card-header">
                    <h3>Resume Information</h3>
                </div>
                <div class="card-body">

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">

                        <!-- Career Objective -->
                        <div class="form-group">
                            <label for="careerObjective">Career Objective</label>
                            <textarea id="careerObjective" name="career_objective" 
                                      placeholder="e.g., Motivated and detail-oriented Software Developer seeking a position in a dynamic tech company to apply my skills and contribute to organizational growth." 
                                      maxlength="500"><?php echo htmlspecialchars($applicant['career_objective'] ?? ''); ?></textarea>
                            <div class="helper-text">Briefly describe your career goals and what you're looking for (max 500 characters).</div>
                            <div class="char-count"><span id="careerCharCount">0</span>/500</div>
                        </div>

                        <!-- Skills -->
                        <div class="form-group">
                            <label for="skills">Key Skills <span class="required">*</span></label>
                            <textarea id="skills" name="skills" 
                                      placeholder="e.g., PHP, Laravel, MySQL, JavaScript, HTML, CSS, Git, Leadership, Communication" 
                                      required><?php echo htmlspecialchars($applicant['skills'] ?? ''); ?></textarea>
                            <div class="helper-text">List your technical and soft skills separated by commas.</div>
                        </div>

                        <!-- Experience -->
                        <div class="form-group">
                            <label for="experience">Experience</label>
                            <textarea id="experience" name="experience" 
                                      placeholder="e.g., Job Title - Company Name (Month Year - Month Year)&#10;• Key responsibility or achievement #1&#10;• Key responsibility or achievement #2&#10;• Key responsibility or achievement #3"><?php echo htmlspecialchars($applicant['experience'] ?? ''); ?></textarea>
                            <div class="helper-text">Describe your work experience. Include job title, company, dates, and key achievements.</div>
                        </div>

                        <!-- Education -->
                        <div class="form-group">
                            <label for="education">Education</label>
                            <textarea id="education" name="education" 
                                      placeholder="e.g., B.S. in Computer Science - University of the Philippines (2016 - 2020)&#10;GPA: 1.75"><?php echo htmlspecialchars($applicant['education'] ?? ''); ?></textarea>
                            <div class="helper-text">List your educational background including degree, institution, and years.</div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                </svg>
                                Save Changes
                            </button>
                            <a href="profile.php" class="btn btn-outline">Cancel</a>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </main>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // =============================================
        // 1. SIDEBAR TOGGLE
        // =============================================
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const isMobile = window.innerWidth <= 768;

        const savedState = localStorage.getItem('sidebarCollapsed');
        if (savedState === 'true' && !isMobile) {
            sidebar.classList.add('collapsed');
        }

        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth <= 768) return;
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });

        // =============================================
        // 2. MOBILE SIDEBAR
        // =============================================
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function openMobileSidebar() {
            sidebar.classList.add('mobile-open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileMenuToggle.addEventListener('click', openMobileSidebar);
        sidebarOverlay.addEventListener('click', closeMobileSidebar);

        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    closeMobileSidebar();
                }
            });
        });

        // =============================================
        // 3. RESPONSIVE
        // =============================================
        let resizeTimer;

        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                const width = window.innerWidth;
                const mobileBrand = document.getElementById('mobileBrand');

                if (width <= 768) {
                    sidebar.classList.remove('collapsed');
                    mobileBrand.style.display = 'block';
                } else {
                    sidebar.classList.remove('mobile-open');
                    sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                    mobileBrand.style.display = 'none';

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
        // 4. CHARACTER COUNTER
        // =============================================
        const careerInput = document.getElementById('careerObjective');
        const careerCounter = document.getElementById('careerCharCount');

        if (careerInput && careerCounter) {
            careerInput.addEventListener('input', function() {
                const length = this.value.length;
                careerCounter.textContent = length;
                if (length > 500) {
                    careerCounter.style.color = '#dc2626';
                } else {
                    careerCounter.style.color = '';
                }
            });
            // Initial count
            careerCounter.textContent = careerInput.value.length;
        }

        // =============================================
        // 5. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
            }
        });

        console.log('✏️ ISMERS Edit Profile Page loaded successfully!');
    </script>

</body>
</html>