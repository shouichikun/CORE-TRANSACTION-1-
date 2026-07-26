<?php
// portals/applicant/interview.php - Applicant Interview Dashboard
session_start();

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

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Applicant';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

if ($applicantId <= 0) {
    header('Location: dashboard.php');
    exit;
}

// =============================================
// GET INTERVIEW COUNT FOR SIDEBAR BADGE
// =============================================
$interviewCount = 0;
$interviewResult = getRecord("
    SELECT COUNT(*) as count FROM applications 
    WHERE applicant_id = ? AND interview_date IS NOT NULL
", [$applicantId], "i");
$interviewCount = $interviewResult['count'] ?? 0;

// Get all interviews for this applicant
$interviews = getRecords("
    SELECT a.id as application_id, a.status, a.applied_at, a.cover_letter,
           a.interview_date, a.interview_notes,
           jo.id as job_id, jo.title as job_title, jo.description as job_description,
           jo.location as job_location, jo.job_type, jo.salary_range,
           c.company_name, c.id as company_id,
           u.first_name as hr_first_name, u.last_name as hr_last_name, u.email as hr_email
    FROM applications a
    JOIN job_orders jo ON a.job_order_id = jo.id
    JOIN clients c ON jo.client_id = c.id
    JOIN users u ON jo.created_by = u.id
    WHERE a.applicant_id = ? 
      AND a.interview_date IS NOT NULL
      AND a.status IN ('interviewed', 'shortlisted')
    ORDER BY a.interview_date ASC
", [$applicantId], "i");

// Get upcoming interviews (future dates)
$upcomingInterviews = array_filter($interviews, function($interview) {
    return strtotime($interview['interview_date']) > time();
});

// Get past interviews
$pastInterviews = array_filter($interviews, function($interview) {
    return strtotime($interview['interview_date']) <= time();
});

// Check if there are any new interview notifications
$notificationCheck = getRecord("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? 
      AND type = 'interview_scheduled' 
      AND is_read = 0
", [$userId], "i");

$hasNewNotifications = ($notificationCheck['count'] ?? 0) > 0;

// Mark notifications as read when viewing
if ($hasNewNotifications) {
    updateRecord("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE user_id = ? AND type = 'interview_scheduled' AND is_read = 0
    ", [$userId], "i");
}

// Get notification count for badge
$notificationCount = getRecord("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = 0
", [$userId], "i");
$totalNotifications = $notificationCount['count'] ?? 0;

// Role labels for display
$roleLabels = [
    'admin' => 'Administrator',
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
    <title>My Interviews - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - INTERVIEWS PAGE
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
            --info-color: #2563eb;
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
           NOTIFICATION BELL
        ============================================= */
        .notification-wrapper {
            position: relative;
        }

        .notification-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            position: relative;
        }

        .notification-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .notification-btn .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .notification-badge {
            position: absolute;
            top: 0.25rem;
            right: 0.25rem;
            background: #dc2626;
            color: white;
            font-size: 0.625rem;
            font-weight: 700;
            min-width: 1.25rem;
            height: 1.25rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.25rem;
        }

        .notification-badge.hidden {
            display: none;
        }

        .notification-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 22rem;
            max-height: 24rem;
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--slate-200);
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-0.5rem) scale(0.95);
            transition: all var(--transition-smooth);
            transform-origin: top right;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .notification-dropdown.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .notification-dropdown .dropdown-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-dropdown .dropdown-header h4 {
            font-size: 0.875rem;
            font-weight: 700;
        }

        .notification-dropdown .dropdown-header .mark-all {
            font-size: 0.75rem;
            color: var(--primary);
            cursor: pointer;
            font-weight: 600;
            background: none;
            border: none;
        }

        .notification-dropdown .dropdown-header .mark-all:hover {
            text-decoration: underline;
        }

        .notification-list {
            overflow-y: auto;
            padding: 0.25rem 0;
            flex: 1;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-100);
            transition: all var(--transition-fast);
            cursor: default;
        }

        .notification-item:hover {
            background: var(--bg-surface-low);
        }

        .notification-item.unread {
            background: rgba(79, 70, 229, 0.04);
            border-left: 3px solid var(--primary);
        }

        .notification-item .notif-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .notification-item .notif-icon .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .notification-item .notif-content .notif-title {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .notification-item .notif-content .notif-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.125rem;
        }

        .notification-item .notif-content .notif-time {
            font-size: 0.625rem;
            color: var(--text-dim);
            margin-top: 0.25rem;
        }

        .notification-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--text-on-surface-variant);
        }

        .notification-empty .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 0.5rem;
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
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
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

        /* =============================================
           STATS CARDS
        ============================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            transition: none;
        }

        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .stat-card .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            float: right;
        }

        .stat-card .stat-icon .material-symbols-outlined {
            font-size: 1.5rem;
        }

        /* =============================================
           INTERVIEW CARDS
        ============================================= */
        .interview-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            transition: none;
        }

        .interview-card.upcoming {
            border-left: 4px solid var(--primary);
        }

        .interview-card.past {
            border-left: 4px solid var(--slate-500);
            opacity: 0.7;
        }

        .interview-card .interview-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .interview-card .interview-header .job-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .interview-card .interview-header .company-name {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .interview-card .interview-header .status-badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .interview-card .interview-header .status-badge.upcoming {
            background: #dbeafe;
            color: #2563eb;
        }

        .interview-card .interview-header .status-badge.past {
            background: #f3f4f6;
            color: #6b7280;
        }

        .interview-card .interview-header .status-badge.today {
            background: #fef3c7;
            color: #d97706;
        }

        .interview-card .interview-body {
            padding: 1.25rem 1.5rem;
        }

        .interview-card .interview-body .interview-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .interview-card .interview-body .detail-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .interview-card .interview-body .detail-item .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .interview-card .interview-body .detail-item .detail-label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .interview-card .interview-body .detail-item .detail-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .interview-card .interview-notes {
            padding: 1rem 1.5rem;
            background: var(--bg-surface-low);
            border-top: 1px solid var(--slate-200);
            border-radius: 0 0 var(--radius-2xl) var(--radius-2xl);
        }

        .interview-card .interview-notes .notes-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .interview-card .interview-notes .notes-text {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            margin-top: 0.25rem;
        }

        .interview-card .interview-actions {
            padding: 0.75rem 1.5rem 1.25rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .interview-card .interview-body .interview-details {
                grid-template-columns: 1fr;
            }

            .notification-dropdown {
                width: 18rem;
                right: -2rem;
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

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
            }

            .stat-card {
                padding: 0.75rem 1rem;
            }

            .stat-card .stat-number {
                font-size: 1.25rem;
            }

            .interview-card .interview-header {
                padding: 1rem 1.25rem;
            }

            .interview-card .interview-body {
                padding: 1rem 1.25rem;
            }

            .interview-card .interview-notes {
                padding: 0.75rem 1.25rem;
            }

            .interview-card .interview-actions {
                padding: 0.75rem 1.25rem;
            }

            .notification-dropdown {
                width: 16rem;
                right: -1rem;
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
                    <span class="material-symbols-outlined">calendar_month</span>
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
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
            </a>

            <a href="interview.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
                <span class="nav-badge"><?php echo $interviewCount; ?></span>
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
                <span style="font-weight:600; font-size:0.875rem;">My Interviews</span>
            </div>

            <div style="display:flex; align-items:center; gap:0.5rem;">
                <!-- Notification Bell -->
                <div class="notification-wrapper">
                    <button class="notification-btn" id="notificationBtn" aria-label="Notifications">
                        <span class="material-symbols-outlined">notifications</span>
                        <span class="notification-badge <?php echo $totalNotifications > 0 ? '' : 'hidden'; ?>" id="notifBadge">
                            <?php echo $totalNotifications > 0 ? $totalNotifications : ''; ?>
                        </span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h4>Notifications</h4>
                            <?php if ($totalNotifications > 0): ?>
                                <button class="mark-all" onclick="markAllNotifications()">Mark all as read</button>
                            <?php endif; ?>
                        </div>
                        <div class="notification-list" id="notificationList">
                            <!-- Notifications loaded via AJAX -->
                            <div style="text-align:center; padding:1rem; color:var(--text-on-surface-variant);">
                                Loading...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile -->
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
            </div>
        </header>

        <!-- Scrollable Content -->
        <main class="main-scroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">calendar_month</span>
                        <span>My Interviews</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $interviewCount; ?> interviews
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>My Interviews</h1>
                        <p>View and manage all your scheduled interviews</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-icon">
                            <span class="material-symbols-outlined">calendar_month</span>
                        </span>
                        <div class="stat-number"><?php echo $interviewCount; ?></div>
                        <div class="stat-label">Total Interviews</div>
                    </div>
                    <div class="stat-card" style="border-left:4px solid var(--primary);">
                        <span class="stat-icon" style="background:rgba(79,70,229,0.1); color:var(--primary);">
                            <span class="material-symbols-outlined">upcoming</span>
                        </span>
                        <div class="stat-number" style="color:var(--primary);"><?php echo count($upcomingInterviews); ?></div>
                        <div class="stat-label">Upcoming</div>
                    </div>
                    <div class="stat-card" style="border-left:4px solid var(--slate-500);">
                        <span class="stat-icon" style="background:rgba(100,116,139,0.1); color:var(--slate-500);">
                            <span class="material-symbols-outlined">history</span>
                        </span>
                        <div class="stat-number" style="color:var(--slate-500);"><?php echo count($pastInterviews); ?></div>
                        <div class="stat-label">Past</div>
                    </div>
                    <div class="stat-card" style="border-left:4px solid #22c55e;">
                        <span class="stat-icon" style="background:rgba(34,197,94,0.1); color:#22c55e;">
                            <span class="material-symbols-outlined">check_circle</span>
                        </span>
                        <div class="stat-number" style="color:#22c55e;">
                            <?php 
                            $completed = array_filter($interviews, function($i) { 
                                return strtotime($i['interview_date']) <= time() && $i['status'] === 'interviewed';
                            });
                            echo count($completed);
                            ?>
                        </div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>

                <!-- Interviews List -->
                <?php if (empty($interviews)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">event_busy</span>
                        <h4>No Interviews Scheduled</h4>
                        <p>You don't have any interviews scheduled yet. Keep applying to jobs!</p>
                        <br>
                        <a href="job_search.php" class="btn btn-primary">
                            <span class="material-symbols-outlined">search</span>
                            Browse Jobs
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($interviews as $interview): ?>
                        <?php
                        $isUpcoming = strtotime($interview['interview_date']) > time();
                        $isToday = date('Y-m-d', strtotime($interview['interview_date'])) === date('Y-m-d');
                        $statusClass = $isUpcoming ? 'upcoming' : 'past';
                        $statusLabel = $isUpcoming ? 'Upcoming' : 'Past';
                        if ($isToday && $isUpcoming) {
                            $statusClass = 'today';
                            $statusLabel = 'Today';
                        }
                        ?>
                        <div class="interview-card <?php echo $isUpcoming ? 'upcoming' : 'past'; ?>">
                            <div class="interview-header">
                                <div>
                                    <div class="job-title"><?php echo htmlspecialchars($interview['job_title']); ?></div>
                                    <div class="company-name"><?php echo htmlspecialchars($interview['company_name']); ?></div>
                                </div>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </div>
                            <div class="interview-body">
                                <div class="interview-details">
                                    <div class="detail-item">
                                        <span class="material-symbols-outlined">event</span>
                                        <div>
                                            <div class="detail-label">Date & Time</div>
                                            <div class="detail-value"><?php echo date('l, F j, Y', strtotime($interview['interview_date'])); ?></div>
                                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('g:i A', strtotime($interview['interview_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <span class="material-symbols-outlined">person</span>
                                        <div>
                                            <div class="detail-label">Interviewer</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($interview['hr_first_name'] . ' ' . $interview['hr_last_name']); ?></div>
                                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo htmlspecialchars($interview['hr_email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <span class="material-symbols-outlined">work</span>
                                        <div>
                                            <div class="detail-label">Job Details</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($interview['job_type'] ?? 'Full-time'); ?></div>
                                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo htmlspecialchars($interview['salary_range'] ?? 'Salary not specified'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <span class="material-symbols-outlined">location_on</span>
                                        <div>
                                            <div class="detail-label">Location</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($interview['job_location'] ?? 'Remote'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($interview['interview_notes'])): ?>
                                <div class="interview-notes">
                                    <div class="notes-label">Interview Notes</div>
                                    <div class="notes-text"><?php echo nl2br(htmlspecialchars($interview['interview_notes'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="interview-actions">
                                <?php if ($isUpcoming): ?>
                                    <button class="btn btn-outline btn-sm" onclick="addToCalendar('<?php echo htmlspecialchars($interview['job_title']); ?>', '<?php echo $interview['interview_date']; ?>', '<?php echo htmlspecialchars($interview['interview_notes'] ?? ''); ?>')">
                                        <span class="material-symbols-outlined">calendar_add_on</span>
                                        Add to Calendar
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-outline btn-sm" onclick="viewJobDetails(<?php echo $interview['job_id']; ?>)">
                                    <span class="material-symbols-outlined">visibility</span>
                                    View Job
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="contactHR('<?php echo htmlspecialchars($interview['hr_email']); ?>', '<?php echo htmlspecialchars($interview['job_title']); ?>')">
                                    <span class="material-symbols-outlined">email</span>
                                    Contact HR
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
        // 4. NOTIFICATIONS
        // =============================================
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationList = document.getElementById('notificationList');

        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = notificationDropdown.classList.contains('open');
            if (!isOpen) {
                loadNotifications();
            }
            notificationDropdown.classList.toggle('open');
        });

        document.addEventListener('click', function(e) {
            if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.classList.remove('open');
            }
        });

        function loadNotifications() {
            fetch('get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.notifications.length > 0) {
                        let html = '';
                        data.notifications.forEach(notif => {
                            const isUnread = !notif.is_read ? 'unread' : '';
                            html += `
                                <div class="notification-item ${isUnread}" onclick="markNotificationRead(${notif.id})">
                                    <div class="notif-icon">
                                        <span class="material-symbols-outlined">${notif.icon || 'info'}</span>
                                    </div>
                                    <div class="notif-content">
                                        <div class="notif-title">${escapeHtml(notif.title)}</div>
                                        <div class="notif-text">${escapeHtml(notif.message)}</div>
                                        <div class="notif-time">${timeAgo(notif.created_at)}</div>
                                    </div>
                                </div>
                            `;
                        });
                        notificationList.innerHTML = html;
                    } else {
                        notificationList.innerHTML = `
                            <div class="notification-empty">
                                <span class="material-symbols-outlined">notifications_off</span>
                                <p>No notifications yet</p>
                            </div>
                        `;
                    }
                    // Update badge
                    const badge = document.getElementById('notifBadge');
                    if (data.unread_count > 0) {
                        badge.textContent = data.unread_count;
                        badge.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                    }
                })
                .catch(error => {
                    notificationList.innerHTML = `
                        <div class="notification-empty">
                            <span class="material-symbols-outlined">error</span>
                            <p>Failed to load notifications</p>
                        </div>
                    `;
                });
        }

        function markNotificationRead(id) {
            fetch('mark_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                }
            });
        }

        function markAllNotifications() {
            fetch('mark_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                }
            });
        }

        // =============================================
        // 5. REAL-TIME NOTIFICATION CHECK (Polling) - 1 SECOND
        // =============================================
        function checkNewNotifications() {
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.has_new) {
                        // Update badge
                        const badge = document.getElementById('notifBadge');
                        badge.textContent = data.unread_count;
                        badge.classList.remove('hidden');
                        
                        // Show toast for new interview notification
                        if (data.latest && data.latest.type === 'interview_scheduled') {
                            showToast('New interview scheduled for ' + data.latest.job_title, 'info');
                        }
                    }
                })
                .catch(error => {
                    console.log('Notification check failed:', error);
                });
        }

        // Check for new notifications every 1 second (1000ms)
        setInterval(checkNewNotifications, 1000);

        // Initial load of notifications
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($hasNewNotifications): ?>
            setTimeout(function() {
                showToast('You have a new interview scheduled!', 'info');
            }, 1000);
            <?php endif; ?>
        });

        // =============================================
        // 6. ADD TO CALENDAR
        // =============================================
        function addToCalendar(jobTitle, interviewDate, notes) {
            const date = new Date(interviewDate);
            const formattedDate = date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Create Google Calendar link
            const start = date.toISOString().replace(/-|:|\.\d+/g, '');
            const end = new Date(date.getTime() + 60 * 60 * 1000).toISOString().replace(/-|:|\.\d+/g, '');
            const url = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=Interview: ${encodeURIComponent(jobTitle)}&dates=${start}/${end}&details=${encodeURIComponent('Interview Notes: ' + notes)}`;
            
            window.open(url, '_blank');
        }

        // =============================================
        // 7. VIEW JOB DETAILS
        // =============================================
        function viewJobDetails(jobId) {
            window.location.href = 'job_details.php?id=' + jobId;
        }

        // =============================================
        // 8. CONTACT HR
        // =============================================
        function contactHR(hrEmail, jobTitle) {
            window.location.href = 'mailto:' + hrEmail + '?subject=Interview for ' + encodeURIComponent(jobTitle);
        }

        // =============================================
        // 9. UTILITY FUNCTIONS
        // =============================================
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function timeAgo(date) {
            const now = new Date();
            const past = new Date(date);
            const diff = Math.floor((now - past) / 1000);
            
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return past.toLocaleDateString();
        }

        // =============================================
        // 10. TOAST SYSTEM
        // =============================================
        function showToast(message, type = 'info') {
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
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
        // 11. RESPONSIVE HANDLING
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
        // 12. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
                notificationDropdown.classList.remove('open');
            }
        });

        console.log('Interview Management loaded successfully.');
    </script>

</body>
</html>