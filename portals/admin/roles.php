<?php
// portals/admin/roles.php - Role Management
session_start();

require_once '../../app/config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$firstName = $_SESSION['first_name'] ?? 'Admin';
$email = $_SESSION['email'] ?? '';

// Database helper function (if not already in config.php)
if (!function_exists('getRecord')) {
    function getRecord($sql, $params = [], $types = "") {
        global $conn; // Assuming $conn is your database connection
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

// Role definitions with descriptions, icons, and permissions
$roles = [
    'admin' => [
        'label' => 'Administrator',
        'icon' => 'admin_panel_settings',
        'color' => '#d97706',
        'description' => 'Full system access. Can manage users, roles, and all system settings.',
        'permissions' => ['all']
    ],
    'hr_manager' => [
        'label' => 'HR Manager',
        'icon' => 'business_center',
        'color' => '#2563eb',
        'description' => 'Manage job postings, applications, and HR operations.',
        'permissions' => ['manage_jobs', 'manage_applications', 'view_reports']
    ],
    'recruiter' => [
        'label' => 'Recruiter',
        'icon' => 'search',
        'color' => '#059669',
        'description' => 'Post jobs, review applications, and manage candidates.',
        'permissions' => ['post_jobs', 'review_applications', 'shortlist_candidates']
    ],
    'client' => [
        'label' => 'Client',
        'icon' => 'business',
        'color' => '#4f46e5',
        'description' => 'View job orders, track applications, and manage company profile.',
        'permissions' => ['view_jobs', 'track_applications', 'manage_company']
    ],
    'applicant' => [
        'label' => 'Applicant',
        'icon' => 'person_search',
        'color' => '#db2777',
        'description' => 'Search jobs, apply, and manage personal profile.',
        'permissions' => ['search_jobs', 'apply_jobs', 'manage_profile']
    ],
    'employee' => [
        'label' => 'Employee',
        'icon' => 'badge',
        'color' => '#0891b2',
        'description' => 'Check attendance, view schedule, and manage profile.',
        'permissions' => ['view_schedule', 'check_attendance', 'manage_profile']
    ],
    'supervisor' => [
        'label' => 'Supervisor',
        'icon' => 'people',
        'color' => '#7c3aed',
        'description' => 'Manage team attendance, approve requests, and view reports.',
        'permissions' => ['manage_team', 'approve_requests', 'view_team_reports']
    ]
];

// Get role counts
$roleCounts = [];
foreach ($roles as $key => $role) {
    $count = getRecord("SELECT COUNT(*) as count FROM users WHERE role = ?", [$key], "s")['count'] ?? 0;
    $roleCounts[$key] = $count;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $roleKey = $_POST['role'] ?? '';
    $permissions = $_POST['permissions'] ?? [];

    // Validate role exists
    if (!isset($roles[$roleKey])) {
        echo json_encode(['success' => false, 'error' => 'Invalid role specified.']);
        exit;
    }

    if ($action === 'update_permissions') {
        // In a real system, you'd update a permissions table or a JSON column
        // For demonstration, we'll just return success
        // Implement your actual permission update logic here
        
        // Example: Update permissions in a permissions table
        // $updateStmt = $conn->prepare("UPDATE role_permissions SET permissions = ? WHERE role_key = ?");
        // $permissionsJson = json_encode($permissions);
        // $updateStmt->bind_param("ss", $permissionsJson, $roleKey);
        // $updateStmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Permissions updated successfully!']);
        exit;
    }
    
    if ($action === 'get_permissions') {
        // Return current permissions for a role
        $currentPermissions = $roles[$roleKey]['permissions'] ?? [];
        echo json_encode(['success' => true, 'permissions' => $currentPermissions]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Role Management - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - ROLE MANAGEMENT
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

        /* =============================================
           PROFILE DROPDOWN
        ============================================= */
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
           MAIN CONTENT - PUSHED BY SIDEBAR
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

        .page-header .header-actions {
            display: flex;
            gap: 0.75rem;
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

        .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        /* =============================================
           ROLES GRID
        ============================================= */
        .roles-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 640px) {
            .roles-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .roles-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .role-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: all var(--transition-fast);
            position: relative;
        }

        .role-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .role-card .role-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .role-card .role-header .role-name {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .role-card .role-header .role-name .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .role-card .role-header .role-count {
            background: var(--bg-surface-low);
            padding: 0.125rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
        }

        .role-card .role-body {
            padding: 1.25rem 1.5rem;
        }

        .role-card .role-body .role-description {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .role-card .role-body .permissions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
        }

        .role-card .role-body .permissions .perm-tag {
            display: inline-block;
            padding: 0.1875rem 0.625rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
        }

        .role-card .role-body .permissions .perm-tag.all {
            background: #fef3c7;
            color: #d97706;
            border-color: #fcd34d;
        }

        .role-card .role-actions {
            padding: 0.75rem 1.5rem 1.25rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .role-card .role-actions .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.5rem;
        }

        /* =============================================
           MODAL
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
            max-width: 32rem;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
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
            margin-bottom: 1.5rem;
        }

        .modal-body .form-group {
            margin-bottom: 1rem;
        }

        .modal-body .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.375rem;
        }

        .modal-body .form-group .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .modal-body .form-group .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .modal-body .form-group .checkbox-item:hover {
            background: var(--bg-surface-container-high);
        }

        .modal-body .form-group .checkbox-item input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .modal-body .form-group .checkbox-item label {
            margin-bottom: 0;
            font-weight: 500;
            font-size: 0.8125rem;
            cursor: pointer;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--slate-200);
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

            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
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

            .roles-grid {
                grid-template-columns: 1fr;
            }

            .role-card .role-header {
                padding: 1rem 1.25rem;
            }

            .role-card .role-body {
                padding: 1rem 1.25rem;
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

            .role-card .role-header .role-name {
                font-size: 1rem;
            }

            .role-card .role-body .role-description {
                font-size: 0.8125rem;
            }

            .role-card .role-body .permissions .perm-tag {
                font-size: 0.625rem;
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
                    <span class="material-symbols-outlined">admin_panel_settings</span>
                </span>
                <p class="sidebar-brand-text">ISMERS</p>
                <p class="sidebar-brand-category">Admin Portal</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="users.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Users</span>
            </a>

            <a href="roles.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">shield</span>
                <span class="nav-text">Roles</span>
            </a>

            
            <a href="biometric_settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">fingerprint</span>
                <span class="nav-text">Biometric</span>
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
                <span style="font-weight:600; font-size:0.875rem;">Role Management</span>
            </div>

            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Admin</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                 
                    
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
                        <span class="material-symbols-outlined">shield</span>
                        <span>Roles</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);"><?php echo count($roles); ?> roles</span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        Last updated: <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Role Management</h1>
                        <p>View all system roles and their permissions</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-primary" onclick="showAddRoleModal()">
                            <span class="material-symbols-outlined">add</span>
                            Add Role
                        </button>
                    </div>
                </div>

                <!-- Roles Grid -->
                <div class="roles-grid">
                    <?php foreach ($roles as $key => $role): ?>
                        <div class="role-card" data-role="<?php echo htmlspecialchars($key); ?>">
                            <div class="role-header">
                                <div class="role-name">
                                    <span class="material-symbols-outlined" style="color:<?php echo htmlspecialchars($role['color']); ?>;">
                                        <?php echo htmlspecialchars($role['icon']); ?>
                                    </span>
                                    <?php echo htmlspecialchars($role['label']); ?>
                                </div>
                                <span class="role-count">
                                    <?php echo $roleCounts[$key] ?? 0; ?> users
                                </span>
                            </div>
                            <div class="role-body">
                                <div class="role-description">
                                    <?php echo htmlspecialchars($role['description']); ?>
                                </div>
                                <div class="permissions">
                                    <?php if (in_array('all', $role['permissions'])): ?>
                                        <span class="perm-tag all">🔑 Full Access</span>
                                    <?php else: ?>
                                        <?php foreach ($role['permissions'] as $perm): ?>
                                            <span class="perm-tag">
                                                <?php echo ucwords(str_replace('_', ' ', $perm)); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="role-actions">
                                <button class="btn btn-outline btn-sm" onclick="editPermissions('<?php echo htmlspecialchars($key); ?>')">
                                    <span class="material-symbols-outlined" style="font-size:1rem;">edit</span>
                                    Edit Permissions
                                </button>
                                <?php if ($key !== 'admin'): ?>
                                    <button class="btn btn-sm" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca;" onclick="deleteRole('<?php echo htmlspecialchars($key); ?>')">
                                        <span class="material-symbols-outlined" style="font-size:1rem;">delete</span>
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </main>
    </div>

    <!-- =============================================
    EDIT PERMISSIONS MODAL
    ============================================= -->
    <div class="modal-overlay" id="permissionsModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Edit Permissions</h2>
                <button class="modal-close" onclick="closeModal('permissionsModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form id="permissionsForm" method="POST">
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" name="role" id="editRoleKey" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Role: <span id="editRoleLabel" style="font-weight:700; color:var(--primary);"></span></label>
                    </div>
                    <div class="form-group">
                        <label>Permissions</label>
                        <div class="checkbox-group" id="permissionsCheckboxes">
                            <!-- Dynamically populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('permissionsModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- =============================================
    ADD ROLE MODAL
    ============================================= -->
    <div class="modal-overlay" id="addRoleModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Add New Role</h2>
                <button class="modal-close" onclick="closeModal('addRoleModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form id="addRoleForm" method="POST">
                <input type="hidden" name="action" value="add_role">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="newRoleKey">Role Key</label>
                        <input type="text" id="newRoleKey" name="role_key" placeholder="e.g., intern" style="width:100%; padding:0.625rem; border:1px solid var(--slate-200); border-radius:0.5rem; font-family:var(--font-sans); font-size:0.875rem;" required>
                    </div>
                    <div class="form-group">
                        <label for="newRoleLabel">Role Label</label>
                        <input type="text" id="newRoleLabel" name="role_label" placeholder="e.g., Intern" style="width:100%; padding:0.625rem; border:1px solid var(--slate-200); border-radius:0.5rem; font-family:var(--font-sans); font-size:0.875rem;" required>
                    </div>
                    <div class="form-group">
                        <label for="newRoleDescription">Description</label>
                        <textarea id="newRoleDescription" name="role_description" rows="3" placeholder="Describe the role and its responsibilities..." style="width:100%; padding:0.625rem; border:1px solid var(--slate-200); border-radius:0.5rem; font-family:var(--font-sans); font-size:0.875rem; resize:vertical;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addRoleModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Role</button>
                </div>
            </form>
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

        // Close mobile sidebar when a nav link is clicked
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
        // 4. MODAL FUNCTIONS
        // =============================================
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal on backdrop click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // =============================================
        // 5. EDIT PERMISSIONS
        // =============================================
        const allPermissions = [
            'manage_users', 'manage_roles', 'manage_jobs', 'manage_applications',
            'view_reports', 'post_jobs', 'review_applications', 'shortlist_candidates',
            'view_jobs', 'track_applications', 'manage_company', 'search_jobs',
            'apply_jobs', 'manage_profile', 'view_schedule', 'check_attendance',
            'manage_team', 'approve_requests', 'view_team_reports'
        ];

        function editPermissions(roleKey) {
            const role = <?php echo json_encode($roles); ?>[roleKey];
            if (!role) return;

            document.getElementById('editRoleKey').value = roleKey;
            document.getElementById('editRoleLabel').textContent = role.label;

            const container = document.getElementById('permissionsCheckboxes');
            container.innerHTML = '';

            // Get current permissions
            fetch('roles.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_permissions&role=' + encodeURIComponent(roleKey)
            })
            .then(response => response.json())
            .then(data => {
                const currentPerms = data.permissions || [];

                // If role has 'all' permission, show all checkboxes checked
                const hasAll = currentPerms.includes('all');

                allPermissions.forEach(perm => {
                    const div = document.createElement('div');
                    div.className = 'checkbox-item';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'permissions[]';
                    checkbox.value = perm;
                    checkbox.id = 'perm_' + perm;
                    checkbox.checked = hasAll || currentPerms.includes(perm);

                    const label = document.createElement('label');
                    label.htmlFor = 'perm_' + perm;
                    label.textContent = perm.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                    div.appendChild(checkbox);
                    div.appendChild(label);
                    container.appendChild(div);
                });

                // Add "All" permission option
                const allDiv = document.createElement('div');
                allDiv.className = 'checkbox-item';
                allDiv.style.background = '#fef3c7';

                const allCheckbox = document.createElement('input');
                allCheckbox.type = 'checkbox';
                allCheckbox.name = 'permissions[]';
                allCheckbox.value = 'all';
                allCheckbox.id = 'perm_all';
                allCheckbox.checked = hasAll;

                const allLabel = document.createElement('label');
                allLabel.htmlFor = 'perm_all';
                allLabel.textContent = '🔑 Full Access (All Permissions)';

                allDiv.appendChild(allCheckbox);
                allDiv.appendChild(allLabel);
                container.prepend(allDiv);

                // When "All" is toggled, toggle all other checkboxes
                allCheckbox.addEventListener('change', function() {
                    const checkboxes = container.querySelectorAll('input[type="checkbox"]:not(#perm_all)');
                    checkboxes.forEach(cb => {
                        cb.checked = this.checked;
                    });
                });

                openModal('permissionsModal');
            })
            .catch(error => {
                showToast('Error loading permissions.', 'error');
            });
        }

        // =============================================
        // 6. HANDLE PERMISSIONS FORM SUBMIT
        // =============================================
        document.getElementById('permissionsForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const roleKey = formData.get('role');
            const permissions = formData.getAll('permissions[]');

            fetch('roles.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'update_permissions',
                    role: roleKey,
                    permissions: permissions
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Permissions updated successfully!', 'success');
                    closeModal('permissionsModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to update permissions.', 'error');
                }
            })
            .catch(error => {
                showToast('Error updating permissions.', 'error');
            });
        });

        // =============================================
        // 7. ADD ROLE MODAL
        // =============================================
        function showAddRoleModal() {
            document.getElementById('newRoleKey').value = '';
            document.getElementById('newRoleLabel').value = '';
            document.getElementById('newRoleDescription').value = '';
            openModal('addRoleModal');
        }

        document.getElementById('addRoleForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            // In a real system, you'd send this to the server to add the role
            // For demonstration, we'll show a success message

            // Validate role key (no spaces, lowercase)
            const roleKey = formData.get('role_key');
            if (!/^[a-z_]+$/.test(roleKey)) {
                showToast('Role key must be lowercase letters and underscores only.', 'error');
                return;
            }

            showToast('Role "' + formData.get('role_label') + '" created successfully!', 'success');
            closeModal('addRoleModal');
            // In production, you'd reload or redirect
            // setTimeout(() => location.reload(), 1000);
        });

        // =============================================
        // 8. DELETE ROLE
        // =============================================
        function deleteRole(roleKey) {
            if (!confirm('Are you sure you want to delete the "' + roleKey + '" role? This cannot be undone.')) {
                return;
            }

            fetch('roles.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_role&role=' + encodeURIComponent(roleKey)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Role deleted successfully.', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to delete role.', 'error');
                }
            })
            .catch(error => {
                showToast('Error deleting role.', 'error');
            });
        }

        // =============================================
        // 9. TOAST SYSTEM
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
            }, 3000);
        }

        // =============================================
        // 10. RESPONSIVE HANDLING
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
        // 11. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('permissionsModal');
                closeModal('addRoleModal');
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        console.log('🔑 ISMERS Role Management loaded successfully!');
    </script>

</body>
</html>