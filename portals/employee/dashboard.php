<?php
// portals/employee/dashboard.php - Employee Dashboard
session_start();

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has the correct role
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Employee';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// Get employee data
$employee = getEmployeeByUserId($userId);
$employeeId = $employee['id'] ?? 0;

if ($employeeId <= 0) {
    // If employee record doesn't exist, create it
    $insertSql = "INSERT INTO employees (user_id, first_name, last_name, email, hire_date, status, created_at) 
                  VALUES (?, ?, ?, ?, NOW(), 'active', NOW())";
    $newId = insertRecord($insertSql, [
        $userId,
        $firstName,
        $_SESSION['last_name'] ?? 'User',
        $email
    ], "isss");
    
    if ($newId) {
        $employee = getEmployeeByUserId($userId);
        $employeeId = $employee['id'] ?? 0;
    }
}

// =============================================
// FIXED: Get employee details with job info - removed a.hired_at
// =============================================
$employeeDetails = getRecord("
    SELECT e.*, 
           jo.id as job_id, jo.title as job_title, jo.description as job_description,
           jo.location as job_location, jo.job_type, jo.salary_range,
           c.company_name, c.id as company_id,
           a.id as application_id, a.interview_date, a.applied_at as hired_at,
           u.first_name as hr_first_name, u.last_name as hr_last_name
    FROM employees e
    LEFT JOIN applications a ON e.application_id = a.id
    LEFT JOIN job_orders jo ON a.job_order_id = jo.id
    LEFT JOIN clients c ON jo.client_id = c.id
    LEFT JOIN users u ON jo.created_by = u.id
    WHERE e.user_id = ?
", [$userId], "i");

// Get attendance for today
$todayAttendance = getEmployeeTodayAttendance($userId);

$hasCheckedIn = $todayAttendance && $todayAttendance['check_in_time'] && !$todayAttendance['check_out_time'];
$hasCheckedOut = $todayAttendance && $todayAttendance['check_out_time'];

// Get attendance stats for the month
$attendanceStats = getEmployeeAttendanceStats($userId);

// Get recent attendance records
$recentAttendance = getEmployeeRecentAttendance($userId, 7);

// Get notification count for badge
$notificationCount = getRecord("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = 0
", [$userId], "i");
$totalNotifications = $notificationCount['count'] ?? 0;

// Get upcoming schedule (if any)
$upcomingSchedule = getEmployeeSchedule($employeeId, 5);

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

// Get current time for greeting
$currentHour = date('H');
$greeting = 'Good Evening';
if ($currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour < 18) {
    $greeting = 'Good Afternoon';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Employee Dashboard - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - EMPLOYEE DASHBOARD
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

        /* =============================================
           WELCOME CARD
        ============================================= */
        .welcome-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            padding: 2rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (min-width: 640px) {
            .welcome-card {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .welcome-card .welcome-text h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .welcome-card .welcome-text p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .welcome-card .welcome-text .company-name {
            color: var(--primary);
            font-weight: 600;
        }

        .welcome-card .welcome-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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
           ATTENDANCE SECTION
        ============================================= */
        .section-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .section-card .section-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .section-card .section-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .section-card .section-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .section-card .section-body {
            padding: 1.5rem;
        }

        /* ===== ATTENDANCE STATUS ===== */
        .attendance-status {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .attendance-status .status-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .attendance-status .status-item .status-dot {
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .attendance-status .status-item .status-dot.checked-in {
            background: var(--success-color);
        }

        .attendance-status .status-item .status-dot.checked-out {
            background: var(--slate-500);
        }

        .attendance-status .status-item .status-dot.absent {
            background: var(--error-color);
        }

        .attendance-status .status-item .status-dot.late {
            background: var(--warning-color);
        }

        .attendance-status .status-item .status-label {
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }

        .attendance-status .status-item .status-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        /* ===== ATTENDANCE TABLE ===== */
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .attendance-table thead {
            background: var(--bg-surface-low);
        }

        .attendance-table th {
            padding: 0.625rem 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }

        .attendance-table td {
            padding: 0.625rem 0.75rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        .attendance-table tr:last-child td {
            border-bottom: none;
        }

        .attendance-table .status-badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-table .status-badge.present {
            background: #d1fae5;
            color: #059669;
        }

        .attendance-table .status-badge.absent {
            background: #fecaca;
            color: #dc2626;
        }

        .attendance-table .status-badge.late {
            background: #fef3c7;
            color: #d97706;
        }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 2rem 1.5rem;
        }

        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 0.75rem;
        }

        .empty-state h4 {
            font-size: 1rem;
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

            .attendance-status {
                flex-direction: column;
                gap: 0.75rem;
            }

            .attendance-table {
                font-size: 0.75rem;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 0.375rem 0.5rem;
            }

            .welcome-card {
                padding: 1.25rem;
            }

            .welcome-card .welcome-text h2 {
                font-size: 1.25rem;
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

            .section-card .section-header {
                padding: 0.75rem 1rem;
            }

            .section-card .section-body {
                padding: 0.75rem 1rem;
            }

            .attendance-table {
                font-size: 0.6875rem;
                min-width: 300px;
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
                    <span class="material-symbols-outlined">badge</span>
                </span>
                <p class="sidebar-brand-text">ISMERS</p>
                <p class="sidebar-brand-category">Employee Portal</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="attendance.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">event_available</span>
                <span class="nav-text">Attendance</span>
            </a>

            <a href="schedule.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">schedule</span>
                <span class="nav-text">My Schedule</span>
            </a>

            <div class="nav-label" style="margin-top:1.5rem;">Settings</div>

            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'E'); ?></span>
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
                <span style="font-weight:600; font-size:0.875rem;">Dashboard</span>
            </div>

            <div style="display:flex; align-items:center; gap:0.5rem;">
                <!-- Notification Bell -->
                <div class="notification-wrapper" style="position:relative;">
                    <button class="notification-btn" id="notificationBtn" aria-label="Notifications" style="background:none;border:none;cursor:pointer;padding:0.5rem;border-radius:0.75rem;color:var(--text-on-surface-variant);position:relative;">
                        <span class="material-symbols-outlined" style="font-size:1.5rem;">notifications</span>
                        <span class="notification-badge" id="notifBadge" style="position:absolute;top:0.25rem;right:0.25rem;background:#dc2626;color:white;font-size:0.625rem;font-weight:700;min-width:1.25rem;height:1.25rem;border-radius:50%;display:flex;align-items:center;justify-content:center;padding:0 0.25rem;<?php echo $totalNotifications > 0 ? '' : 'display:none;'; ?>">
                            <?php echo $totalNotifications > 0 ? $totalNotifications : ''; ?>
                        </span>
                    </button>
                </div>

                <!-- Profile -->
                <div class="profile-dropdown-wrapper">
                    <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                        <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'E'); ?></span>
                        <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                        <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? 'Employee')); ?></span>
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
                        <span class="material-symbols-outlined">dashboard</span>
                        <span>Dashboard</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            Employee Portal
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <?php echo date('l, F j, Y'); ?>
                    </span>
                </div>

                <!-- Welcome Card -->
                <div class="welcome-card">
                    <div class="welcome-text">
                        <h2><?php echo $greeting; ?>, <?php echo htmlspecialchars($firstName); ?>!</h2>
                        <p>
                            Welcome to your employee dashboard. 
                            <?php if ($employeeDetails && $employeeDetails['company_name']): ?>
                                You are working at <span class="company-name"><?php echo htmlspecialchars($employeeDetails['company_name']); ?></span> 
                                as <span class="company-name"><?php echo htmlspecialchars($employeeDetails['job_title'] ?? 'Employee'); ?></span>
                            <?php else: ?>
                                You are now part of the ISMERS team. Welcome aboard!
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="welcome-actions">
                        <?php if (!$hasCheckedIn && !$hasCheckedOut): ?>
                            <button class="btn btn-success" onclick="checkIn()">
                                <span class="material-symbols-outlined">login</span>
                                Check In
                            </button>
                        <?php elseif ($hasCheckedIn && !$hasCheckedOut): ?>
                            <button class="btn btn-danger" onclick="checkOut()">
                                <span class="material-symbols-outlined">logout</span>
                                Check Out
                            </button>
                            <span class="btn btn-outline" style="cursor:default;">
                                <span class="material-symbols-outlined">verified</span>
                                Checked In
                            </span>
                        <?php else: ?>
                            <span class="btn btn-outline" style="cursor:default;">
                                <span class="material-symbols-outlined">check_circle</span>
                                Checked Out
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-icon">
                            <span class="material-symbols-outlined">event_available</span>
                        </span>
                        <div class="stat-number"><?php echo $attendanceStats['days_present'] ?? 0; ?></div>
                        <div class="stat-label">Days Present</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="background:rgba(220,38,38,0.1); color:#dc2626;">
                            <span class="material-symbols-outlined">event_busy</span>
                        </span>
                        <div class="stat-number" style="color:#dc2626;"><?php echo $attendanceStats['days_absent'] ?? 0; ?></div>
                        <div class="stat-label">Days Absent</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="background:rgba(245,158,11,0.1); color:#f59e0b;">
                            <span class="material-symbols-outlined">warning</span>
                        </span>
                        <div class="stat-number" style="color:#f59e0b;"><?php echo $attendanceStats['days_late'] ?? 0; ?></div>
                        <div class="stat-label">Late Arrivals</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="background:rgba(34,197,94,0.1); color:#22c55e;">
                            <span class="material-symbols-outlined">trending_up</span>
                        </span>
                        <div class="stat-number" style="color:#22c55e;">
                            <?php 
                            $total = $attendanceStats['total_days'] ?? 1;
                            $present = $attendanceStats['days_present'] ?? 0;
                            echo $total > 0 ? round(($present / $total) * 100) . '%' : '0%';
                            ?>
                        </div>
                        <div class="stat-label">Attendance Rate</div>
                    </div>
                </div>

                <!-- Recent Attendance -->
                <div class="section-card">
                    <div class="section-header">
                        <h3>
                            <span class="material-symbols-outlined">history</span>
                            Recent Attendance
                        </h3>
                        <a href="attendance.php" style="font-size:0.875rem; color:var(--primary); font-weight:600;">View All</a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($recentAttendance)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">inbox</span>
                                <h4>No Attendance Records</h4>
                                <p>Your attendance records will appear here once you start checking in.</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x:auto;">
                                <table class="attendance-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Check In</th>
                                            <th>Check Out</th>
                                            <th>Hours</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAttendance as $record): ?>
                                            <?php
                                            $status = 'present';
                                            $statusLabel = 'Present';
                                            if (!$record['check_in_time']) {
                                                $status = 'absent';
                                                $statusLabel = 'Absent';
                                            } elseif ($record['is_late']) {
                                                $status = 'late';
                                                $statusLabel = 'Late';
                                            }
                                            $hours = 0;
                                            if ($record['check_in_time'] && $record['check_out_time']) {
                                                $checkIn = strtotime($record['check_in_time']);
                                                $checkOut = strtotime($record['check_out_time']);
                                                $hours = round(($checkOut - $checkIn) / 3600, 1);
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></td>
                                                <td><?php echo $record['check_in_time'] ? date('h:i A', strtotime($record['check_in_time'])) : '—'; ?></td>
                                                <td><?php echo $record['check_out_time'] ? date('h:i A', strtotime($record['check_out_time'])) : '—'; ?></td>
                                                <td><?php echo $hours > 0 ? $hours . 'h' : '—'; ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status; ?>">
                                                        <?php echo $statusLabel; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Schedule -->
                <div class="section-card">
                    <div class="section-header">
                        <h3>
                            <span class="material-symbols-outlined">schedule</span>
                            Upcoming Schedule
                        </h3>
                        <a href="schedule.php" style="font-size:0.875rem; color:var(--primary); font-weight:600;">View All</a>
                    </div>
                    <div class="section-body">
                        <?php if (empty($upcomingSchedule)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">calendar_month</span>
                                <h4>No Upcoming Schedule</h4>
                                <p>Your schedule will appear here once it's assigned by your supervisor.</p>
                            </div>
                        <?php else: ?>
                            <div style="display:grid; grid-template-columns:1fr; gap:0.75rem;">
                                <?php foreach ($upcomingSchedule as $schedule): ?>
                                    <div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1rem; background:var(--bg-surface-low); border-radius:0.75rem; flex-wrap:wrap; gap:0.5rem;">
                                        <div>
                                            <div style="font-weight:600; font-size:0.875rem;">
                                                <?php echo date('l, F j, Y', strtotime($schedule['schedule_date'])); ?>
                                            </div>
                                            <div style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('h:i A', strtotime($schedule['start_time'])); ?> - 
                                                <?php echo date('h:i A', strtotime($schedule['end_time'])); ?>
                                            </div>
                                        </div>
                                        <div style="text-align:right;">
                                            <div style="font-size:0.8125rem; font-weight:600; color:var(--primary);">
                                                <?php echo htmlspecialchars($schedule['shift_type'] ?? 'Regular Shift'); ?>
                                            </div>
                                            <?php if (!empty($schedule['notes'])): ?>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($schedule['notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

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
        // 4. CHECK IN / CHECK OUT
        // =============================================
        function checkIn() {
            const btn = event.target.closest('button');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 0.8s linear infinite;">refresh</span> Processing...';

            fetch('ajax/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_in'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Checked in successfully at ' + data.time, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to check in.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">login</span> Check In';
                }
            })
            .catch(error => {
                showToast('Error checking in. Please try again.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">login</span> Check In';
            });
        }

        function checkOut() {
            const btn = event.target.closest('button');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 0.8s linear infinite;">refresh</span> Processing...';

            fetch('ajax/attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_out'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Checked out successfully at ' + data.time, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to check out.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">logout</span> Check Out';
                }
            })
            .catch(error => {
                showToast('Error checking out. Please try again.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">logout</span> Check Out';
            });
        }

        // =============================================
        // 5. TOAST SYSTEM
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
            }, 4000);
        }

        // =============================================
        // 6. RESPONSIVE HANDLING
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
        // 7. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        console.log('Employee Dashboard loaded successfully.');
    </script>

</body>
</html>