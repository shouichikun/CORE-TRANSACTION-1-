<?php
// portals/admin/roles.php - Role-Based Access Control Management
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();
// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$firstName = $_SESSION['first_name'] ?? 'Admin';
$email = $_SESSION['email'] ?? '';

// =============================================
// PERMISSION DEFINITIONS
// =============================================
$permissionGroups = [
    'dashboard' => [
        'label' => 'Dashboard',
        'icon' => 'dashboard',
        'permissions' => [
            'view_dashboard' => 'View Dashboard',
            'view_stats' => 'View Statistics',
        ]
    ],
    'users' => [
        'label' => 'User Management',
        'icon' => 'people',
        'permissions' => [
            'view_users' => 'View Users',
            'create_users' => 'Create Users',
            'edit_users' => 'Edit Users',
            'delete_users' => 'Delete Users',
            'manage_roles' => 'Manage Roles',
        ]
    ],
    'clients' => [
        'label' => 'Client Management',
        'icon' => 'business',
        'permissions' => [
            'view_clients' => 'View Clients',
            'create_clients' => 'Create Clients',
            'edit_clients' => 'Edit Clients',
            'delete_clients' => 'Delete Clients',
        ]
    ],
    'employees' => [
        'label' => 'Employee Management',
        'icon' => 'badge',
        'permissions' => [
            'view_employees' => 'View Employees',
            'create_employees' => 'Create Employees',
            'edit_employees' => 'Edit Employees',
            'delete_employees' => 'Delete Employees',
        ]
    ],
    'jobs' => [
        'label' => 'Job Management',
        'icon' => 'work',
        'permissions' => [
            'view_jobs' => 'View Jobs',
            'create_jobs' => 'Create Jobs',
            'edit_jobs' => 'Edit Jobs',
            'delete_jobs' => 'Delete Jobs',
            'manage_applications' => 'Manage Applications',
        ]
    ],
    'applications' => [
        'label' => 'Applications',
        'icon' => 'description',
        'permissions' => [
            'view_applications' => 'View Applications',
            'review_applications' => 'Review Applications',
            'schedule_interviews' => 'Schedule Interviews',
        ]
    ],
    'agencies' => [
        'label' => 'Agency Management',
        'icon' => 'apartment',
        'permissions' => [
            'view_agencies' => 'View Agencies',
            'create_agencies' => 'Create Agencies',
            'edit_agencies' => 'Edit Agencies',
            'delete_agencies' => 'Delete Agencies',
        ]
    ],
    'settings' => [
        'label' => 'System Settings',
        'icon' => 'settings',
        'permissions' => [
            'view_settings' => 'View Settings',
            'edit_settings' => 'Edit Settings',
            'manage_biometric' => 'Manage Biometric',
            'view_logs' => 'View Audit Logs',
        ]
    ],
    'profile' => [
        'label' => 'Profile',
        'icon' => 'person',
        'permissions' => [
            'view_profile' => 'View Profile',
            'edit_profile' => 'Edit Profile',
            'change_password' => 'Change Password',
        ]
    ],
    'reports' => [
        'label' => 'Reports & Analytics',
        'icon' => 'analytics',
        'permissions' => [
            'view_reports' => 'View Reports',
            'export_reports' => 'Export Reports',
        ]
    ]
];

// Default role permissions (each role gets a set of permissions)
// FIXED: Properly collect all permission keys without using spread operator with named parameters
$allPermissionKeys = [];
foreach ($permissionGroups as $group) {
    $allPermissionKeys = array_merge($allPermissionKeys, array_keys($group['permissions']));
}

$defaultRolePermissions = [
    'admin' => $allPermissionKeys,
    'hr_manager' => [
        'view_dashboard', 'view_stats',
        'view_users', 'create_users', 'edit_users',
        'view_clients', 'create_clients', 'edit_clients',
        'view_employees', 'create_employees', 'edit_employees',
        'view_jobs', 'create_jobs', 'edit_jobs', 'manage_applications',
        'view_applications', 'review_applications', 'schedule_interviews',
        'view_agencies',
        'view_profile', 'edit_profile', 'change_password',
        'view_reports'
    ],
    'recruiter' => [
        'view_dashboard', 'view_stats',
        'view_users',
        'view_clients',
        'view_employees',
        'view_jobs', 'create_jobs', 'edit_jobs', 'manage_applications',
        'view_applications', 'review_applications', 'schedule_interviews',
        'view_agencies',
        'view_profile', 'edit_profile', 'change_password',
        'view_reports'
    ],
    'client' => [
        'view_dashboard', 'view_stats',
        'view_jobs', 'create_jobs', 'edit_jobs',
        'view_applications', 'review_applications',
        'view_profile', 'edit_profile', 'change_password'
    ],
    'applicant' => [
        'view_dashboard', 'view_stats',
        'view_profile', 'edit_profile', 'change_password'
    ],
    'employee' => [
        'view_dashboard', 'view_stats',
        'view_profile', 'edit_profile', 'change_password'
    ],
    'supervisor' => [
        'view_dashboard', 'view_stats',
        'view_employees', 'edit_employees',
        'view_jobs',
        'view_applications', 'review_applications',
        'view_profile', 'edit_profile', 'change_password'
    ]
];

// ✅ POSTGRESQL FIX: Use $1 placeholder instead of ?
$sql = "SELECT * FROM roles ORDER BY role_name";
$roles = getRecords($sql);

// If no roles in database, create default roles
if (empty($roles)) {
    foreach ($defaultRolePermissions as $roleName => $permissions) {
        $permissionJson = json_encode($permissions);
        // ✅ POSTGRESQL FIX: PostgreSQL uses $1, $2 placeholders - removed type string
        $insertSql = "INSERT INTO roles (role_name, permissions, created_at) VALUES ($1, $2, NOW())";
        insertRecord($insertSql, [$roleName, $permissionJson]);
    }
    $roles = getRecords($sql);
}

// Get role counts for each role
$roleCounts = [];
foreach ($roles as $role) {
    // ✅ POSTGRESQL FIX: $1 placeholder instead of ?
    $count = getRecord("SELECT COUNT(*) as count FROM users WHERE role = $1", [$role['role_name']])['count'] ?? 0;
    $roleCounts[$role['role_name']] = $count;
}

$totalRoles = count($roles);

// Handle AJAX POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
    
    // Get role by ID
    if ($action === 'get_role' && $roleId > 0) {
        // ✅ POSTGRESQL FIX: $1 placeholder
        $role = getRecord("SELECT * FROM roles WHERE id = $1", [$roleId]);
        if ($role) {
            $role['permissions'] = json_decode($role['permissions'], true) ?: [];
            echo json_encode(['success' => true, 'role' => $role]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Role not found.']);
        }
        exit;
    }
    
    // Update role permissions
    if ($action === 'update_permissions' && $roleId > 0) {
        $permissions = isset($_POST['permissions']) ? json_decode($_POST['permissions'], true) : [];
        $roleName = $_POST['role_name'] ?? '';
        
        if (empty($roleName)) {
            echo json_encode(['success' => false, 'error' => 'Role name is required.']);
            exit;
        }
        
        $permissionJson = json_encode($permissions);
        // ✅ POSTGRESQL FIX: $1, $2, $3 placeholders - removed type string
        $updateSql = "UPDATE roles SET role_name = $1, permissions = $2 WHERE id = $3";
        $result = updateRecord($updateSql, [$roleName, $permissionJson, $roleId]);
        
        if ($result) {
            logActivity($_SESSION['user_id'], 'Role Permissions Updated', 'roles', $roleId, 'Updated permissions for role: ' . $roleName);
            echo json_encode(['success' => true, 'message' => 'Role permissions updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update role permissions.']);
        }
        exit;
    }
    
    // Create new role
    if ($action === 'create_role') {
        $roleName = $_POST['role_name'] ?? '';
        $permissions = isset($_POST['permissions']) ? json_decode($_POST['permissions'], true) : [];
        
        if (empty($roleName)) {
            echo json_encode(['success' => false, 'error' => 'Role name is required.']);
            exit;
        }
        
        // Check if role exists
        // ✅ POSTGRESQL FIX: $1 placeholder
        $existing = getRecord("SELECT id FROM roles WHERE role_name = $1", [$roleName]);
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'Role already exists.']);
            exit;
        }
        
        $permissionJson = json_encode($permissions);
        // ✅ POSTGRESQL FIX: $1, $2 placeholders - removed type string
        $insertSql = "INSERT INTO roles (role_name, permissions, created_at) VALUES ($1, $2, NOW())";
        $newId = insertRecord($insertSql, [$roleName, $permissionJson]);
        
        if ($newId) {
            logActivity($_SESSION['user_id'], 'Role Created', 'roles', $newId, 'Created new role: ' . $roleName);
            echo json_encode(['success' => true, 'message' => 'Role created successfully!', 'role_id' => $newId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create role.']);
        }
        exit;
    }
    
    // Delete role
    if ($action === 'delete_role' && $roleId > 0) {
        // ✅ POSTGRESQL FIX: $1 placeholder
        $role = getRecord("SELECT * FROM roles WHERE id = $1", [$roleId]);
        if (!$role) {
            echo json_encode(['success' => false, 'error' => 'Role not found.']);
            exit;
        }
        
        // Check if role is in use
        // ✅ POSTGRESQL FIX: $1 placeholder
        $usersWithRole = getRecord("SELECT COUNT(*) as count FROM users WHERE role = $1", [$role['role_name']])['count'] ?? 0;
        if ($usersWithRole > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete role that is assigned to ' . $usersWithRole . ' users.']);
            exit;
        }
        
        // ✅ POSTGRESQL FIX: $1 placeholder - removed type string
        $deleteSql = "DELETE FROM roles WHERE id = $1";
        $result = deleteRecord($deleteSql, [$roleId]);
        
        if ($result) {
            logActivity($_SESSION['user_id'], 'Role Deleted', 'roles', $roleId, 'Deleted role: ' . $role['role_name']);
            echo json_encode(['success' => true, 'message' => 'Role deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete role.']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

// Get greeting
$currentHour = date('H');
$greeting = 'Good Evening';
if ($currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour < 18) {
    $greeting = 'Good Afternoon';
}

// Get online users count
$onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
// ✅ POSTGRESQL FIX: $1 placeholder - removed type string
$onlineUsers = getRecord("SELECT COUNT(*) as count FROM users WHERE last_activity >= $1", [$onlineThreshold])['count'] ?? 0;
// ✅ POSTGRESQL FIX: Simple query with no parameters
$totalUsers = getRecord("SELECT COUNT(*) as count FROM users")['count'] ?? 0;

// Get user profile data for sidebar
$userProfile = getUserProfileData($userId);
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
        /* ========================================================================== */
        /* ALL CSS REMAINS EXACTLY THE SAME AS YOUR ORIGINAL */
        /* ========================================================================== */
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

        /* ============================================= */
        /* SIDEBAR - FIXED */
        /* ============================================= */
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

        /* ============================================= */
        /* MAIN CONTENT */
        /* ============================================= */
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

        /* ============================================= */
        /* TOP HEADER */
        /* ============================================= */
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

        /* ============================================= */
        /* PROFILE DROPDOWN */
        /* ============================================= */
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

        /* ============================================= */
        /* MAIN SCROLLABLE AREA */
        /* ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }

        .main-scroll .container {
            max-width: 80rem;
            margin: 0 auto;
        }

        /* ============================================= */
        /* BREADCRUMB */
        /* ============================================= */
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
            background: var(--success-color);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* ============================================= */
        /* PAGE HEADER */
        /* ============================================= */
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

        /* ============================================= */
        /* BUTTONS */
        /* ============================================= */
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
            color: var(--on-primary);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
        }

        .btn-primary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--on-primary);
        }

        .btn-outline .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-danger {
            background: var(--error-color);
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.5rem;
        }

        .btn-sm .material-symbols-outlined {
            font-size: 1rem;
        }

        /* ============================================= */
        /* STATS ROW */
        /* ============================================= */
        .stats-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-row {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-item {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
            text-align: center;
            transition: all var(--transition-fast);
        }

        .stat-item:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-item .number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--slate-900);
            line-height: 1.2;
        }

        .stat-item .label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            font-weight: 500;
        }

        .stat-item .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }

        .stat-item .stat-icon.blue { background: #eff6ff; color: #2563eb; }
        .stat-item .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-item .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-item .stat-icon.orange { background: #fef3c7; color: #d97706; }

        /* ============================================= */
        /* ROLES GRID */
        /* ============================================= */
        .roles-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 768px) {
            .roles-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (min-width: 1024px) {
            .roles-grid {
                grid-template-columns: 1fr 1fr 1fr;
            }
        }

        .role-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: all var(--transition-fast);
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
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .role-card .role-header .role-badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            background: var(--primary);
            color: white;
        }

        .role-card .role-body {
            padding: 1.25rem 1.5rem;
        }

        .role-card .role-body .permission-count {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.75rem;
        }

        .role-card .role-body .permission-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
        }

        .role-card .role-body .permission-pill {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 500;
            background: var(--bg-surface-low);
            color: var(--text-on-surface-variant);
            border: 1px solid var(--slate-200);
        }

        .role-card .role-body .permission-pill.has-permission {
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            border-color: rgba(79, 70, 229, 0.2);
        }

        .role-card .role-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            background: var(--bg-surface-low);
        }

        .role-card .role-footer .btn {
            font-size: 0.75rem;
            padding: 0.375rem 0.875rem;
        }

        .role-card .role-footer .btn .material-symbols-outlined {
            font-size: 1rem;
        }

        /* ============================================= */
        /* PERMISSION EDITOR MODAL */
        /* ============================================= */
        .permission-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        @media (min-width: 640px) {
            .permission-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .permission-group {
            border: 1px solid var(--slate-200);
            border-radius: 0.75rem;
            overflow: hidden;
        }

        .permission-group .group-header {
            padding: 0.625rem 1rem;
            background: var(--bg-surface-low);
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
        }

        .permission-group .group-header .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--primary);
        }

        .permission-group .group-body {
            padding: 0.5rem 0.75rem;
        }

        .permission-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.375rem 0.5rem;
            border-radius: 0.375rem;
            transition: background var(--transition-fast);
        }

        .permission-item:hover {
            background: var(--bg-surface-low);
        }

        .permission-item .permission-label {
            font-size: 0.8125rem;
            color: var(--text-on-surface);
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: var(--transition-fast);
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: var(--transition-fast);
            border-radius: 50%;
        }

        .toggle-switch input:checked + .toggle-slider {
            background-color: var(--primary);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(18px);
        }

        .toggle-switch input:disabled + .toggle-slider {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* ============================================= */
        /* EMPTY STATE */
        /* ============================================= */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .empty-state .empty-icon {
            margin-bottom: 0.75rem;
            opacity: 0.3;
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

        /* ============================================= */
        /* MODAL SYSTEM */
        /* ============================================= */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            display: flex;
            flex-direction: column;
            animation: slideUp 0.3s ease;
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

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--bg-surface);
            border-radius: var(--radius-2xl) var(--radius-2xl) 0 0;
            z-index: 1;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .modal-header h2 .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .btn-close-modal {
            background: none;
            border: none;
            font-size: 1.75rem;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            padding: 0 0.5rem;
            line-height: 1;
        }

        .btn-close-modal:hover {
            color: var(--text-on-surface);
            transform: rotate(90deg);
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
            background: var(--bg-surface-low);
            border-radius: 0 0 var(--radius-2xl) var(--radius-2xl);
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .modal-footer .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .modal-footer .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.625rem;
            font-size: 0.875rem;
            font-family: inherit;
            background: var(--bg-surface);
            transition: all var(--transition-fast);
            color: var(--text-on-surface);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .form-group .helper-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* ============================================= */
        /* TOAST */
        /* ============================================= */
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .toast .material-symbols-outlined {
            font-size: 1.25rem;
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

        .toast.warning {
            background: var(--warning-color);
        }

        /* ============================================= */
        /* LOADER */
        /* ============================================= */
        .loader {
            width: 40px;
            height: 40px;
            border: 4px solid var(--slate-200);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        .loader-sm {
            width: 16px;
            height: 16px;
            border-width: 2px;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ============================================= */
        /* RESPONSIVE - ALL REMAINS THE SAME */
        /* ============================================= */
        /* ... keep all your existing responsive CSS ... */
        @media (min-width: 768px) {
            /* ... keep existing ... */
        }

        @media (max-width: 767px) {
            /* ... keep existing ... */
        }

        @media (max-width: 480px) {
            /* ... keep existing ... */
        }

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

        .select-all-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
        }

        .select-all-row .select-all-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
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
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">admin_panel_settings</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">Admin Portal</p>
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
                <span class="nav-badge"><?php echo $totalUsers; ?></span>
            </a>

            <a href="roles.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">shield</span>
                <span class="nav-text">Roles</span>
                <span class="nav-badge"><?php echo $totalRoles; ?></span>
            </a>

            <a href="reports.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="biometric_settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">fingerprint</span>
                <span class="nav-text">Biometric</span>
            </a>

        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                         alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                         class="avatar">
                <?php else: ?>
                    <span class="avatar"><?php echo $userProfile['initials']; ?></span>
                <?php endif; ?>
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
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" title="Open Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle Sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">menu_open</span>
                </button>
                <span class="separator">|</span>
                <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">Role Management</span>
            </div>

            <!-- Profile Dropdown -->
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo $userProfile['initials']; ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Administrator</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-header">Account</div>
                    
                    <a href="profile.php" class="dropdown-item">
                        <span class="material-symbols-outlined">person</span>
                        My Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <span class="material-symbols-outlined">settings</span>
                        Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../../logout.php" class="dropdown-item danger">
                        <span class="material-symbols-outlined">logout</span>
                        Log Out
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Scrollable Area -->
        <main class="main-scroll" id="mainScroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">shield</span>
                        <span>Role Management</span>
                        <span class="status-dot"></span>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <span class="material-symbols-outlined" style="font-size:1rem;">online_prediction</span>
                        <span><?php echo $onlineUsers; ?> online now</span>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($firstName); ?></h1>
                        <p>Define and manage user roles and their permissions</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-primary" onclick="openCreateRoleModal()">
                            <span class="material-symbols-outlined">add</span>
                            Create Role
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="stat-icon blue"><span class="material-symbols-outlined">shield</span></div>
                        <div class="number"><?php echo $totalRoles; ?></div>
                        <div class="label">Total Roles</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon green"><span class="material-symbols-outlined">people</span></div>
                        <div class="number"><?php echo $totalUsers; ?></div>
                        <div class="label">Total Users</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon purple"><span class="material-symbols-outlined">lock</span></div>
                        <div class="number"><?php echo count($permissionGroups); ?></div>
                        <div class="label">Permission Groups</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon orange"><span class="material-symbols-outlined">online_prediction</span></div>
                        <div class="number"><?php echo $onlineUsers; ?></div>
                        <div class="label">Online Now</div>
                    </div>
                </div>

                <!-- Roles Grid -->
                <div class="roles-grid">
                    <?php foreach ($roles as $role): 
                        $permissions = json_decode($role['permissions'], true) ?: [];
                        $userCount = $roleCounts[$role['role_name']] ?? 0;
                        $isDefault = array_key_exists($role['role_name'], $defaultRolePermissions);
                    ?>
                        <div class="role-card">
                            <div class="role-header">
                                <div class="role-name">
                                    <span class="material-symbols-outlined" style="font-size:1.25rem; color:var(--primary);">
                                        <?php echo $role['role_name'] === 'admin' ? 'admin_panel_settings' : 'shield'; ?>
                                    </span>
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $role['role_name']))); ?>
                                </div>
                                <span class="role-badge"><?php echo $userCount; ?> users</span>
                            </div>
                            <div class="role-body">
                                <div class="permission-count">
                                    <?php echo count($permissions); ?> permissions assigned
                                </div>
                                <div class="permission-pills">
                                    <?php 
                                    $displayPermissions = array_slice($permissions, 0, 6);
                                    foreach ($displayPermissions as $perm): 
                                        $permLabel = '';
                                        foreach ($permissionGroups as $group) {
                                            if (isset($group['permissions'][$perm])) {
                                                $permLabel = $group['permissions'][$perm];
                                                break;
                                            }
                                        }
                                        if ($permLabel):
                                    ?>
                                        <span class="permission-pill has-permission"><?php echo htmlspecialchars($permLabel); ?></span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    if (count($permissions) > 6): 
                                    ?>
                                        <span class="permission-pill">+<?php echo count($permissions) - 6; ?> more</span>
                                    <?php endif; ?>
                                    <?php if (empty($permissions)): ?>
                                        <span class="permission-pill" style="color:var(--text-on-surface-variant);">No permissions assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="role-footer">
                                <?php if ($isDefault): ?>
                                    <span style="font-size:0.65rem; color:var(--text-on-surface-variant); padding:0.25rem 0.5rem; border-radius:var(--radius-full); background:var(--bg-surface);">
                                        Default Role
                                    </span>
                                <?php endif; ?>
                                <button class="btn btn-primary btn-sm" onclick="openEditRoleModal(<?php echo $role['id']; ?>)">
                                    <span class="material-symbols-outlined">edit</span>
                                    Edit Permissions
                                </button>
                                <?php if (!$isDefault && $userCount == 0): ?>
                                    <button class="btn btn-danger btn-sm" onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['role_name']); ?>')">
                                        <span class="material-symbols-outlined">delete</span>
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($roles)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <span class="material-symbols-outlined" style="font-size:3rem;">shield_off</span>
                        </div>
                        <h4>No Roles Found</h4>
                        <p>Create your first role to start managing permissions.</p>
                        <button class="btn btn-primary" onclick="openCreateRoleModal()" style="margin-top:1rem;">
                            <span class="material-symbols-outlined">add</span>
                            Create Role
                        </button>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- =============================================
    MODAL: EDIT/CREATE ROLE PERMISSIONS
    ============================================= -->
    <div class="modal-overlay" id="roleModal">
        <div class="modal">
            <div class="modal-header">
                <h2 id="roleModalTitle">
                    <span class="material-symbols-outlined">edit</span>
                    Edit Role Permissions
                </h2>
                <button class="btn-close-modal" onclick="closeModal('roleModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" name="role_id" id="role_id">
                    <input type="hidden" name="action" id="roleAction" value="update_permissions">
                    <input type="hidden" name="permissions" id="permissionsInput">
                    
                    <div class="form-group">
                        <label for="role_name">Role Name</label>
                        <input type="text" id="role_name" name="role_name" required 
                               placeholder="Enter role name (e.g., department_head)">
                        <div class="helper-text">Use lowercase with underscores (e.g., hr_manager)</div>
                    </div>

                    <div style="margin-top:1.5rem;">
                        <div class="select-all-row">
                            <span class="select-all-label">Permissions</span>
                            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllPermissions(true)">
                                Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllPermissions(false)">
                                Deselect All
                            </button>
                        </div>

                        <div class="permission-grid" id="permissionGrid">
                            <?php foreach ($permissionGroups as $groupKey => $group): ?>
                                <div class="permission-group">
                                    <div class="group-header">
                                        <span class="material-symbols-outlined"><?php echo $group['icon']; ?></span>
                                        <?php echo $group['label']; ?>
                                    </div>
                                    <div class="group-body">
                                        <?php foreach ($group['permissions'] as $permKey => $permLabel): ?>
                                            <div class="permission-item">
                                                <span class="permission-label"><?php echo $permLabel; ?></span>
                                                <label class="toggle-switch">
                                                    <input type="checkbox" class="permission-checkbox" 
                                                           value="<?php echo $permKey; ?>"
                                                           data-permission="<?php echo $permKey; ?>">
                                                    <span class="toggle-slider"></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('roleModal')">Cancel</button>
                <button class="btn btn-primary" onclick="saveRolePermissions()">
                    <span class="material-symbols-outlined">save</span>
                    Save Permissions
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    JAVASCRIPT
    ============================================= -->
    <script>
        (function() {
            'use strict';

            // =============================================
            // 1. SIDEBAR TOGGLE (Desktop Collapse)
            // =============================================
            const sidebar = document.getElementById('appSidebar');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');

            const savedState = localStorage.getItem('sidebarCollapsed');
            const isDesktop = window.innerWidth >= 768;

            if (savedState === 'true' && isDesktop) {
                sidebar.classList.add('collapsed');
                sidebarToggleIcon.textContent = 'menu';
            }

            sidebarToggleBtn.addEventListener('click', function() {
                if (window.innerWidth < 768) return;
                sidebar.classList.toggle('collapsed');
                const isCollapsed = sidebar.classList.contains('collapsed');
                sidebarToggleIcon.textContent = isCollapsed ? 'menu' : 'menu_open';
                localStorage.setItem('sidebarCollapsed', isCollapsed);
            });

            // =============================================
            // 2. MOBILE SIDEBAR TOGGLE
            // =============================================
            function openMobileSidebar() {
                sidebar.classList.add('mobile-open');
                sidebar.classList.remove('mobile-hidden');
                sidebarBackdrop.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeMobileSidebar() {
                sidebar.classList.remove('mobile-open');
                sidebar.classList.add('mobile-hidden');
                sidebarBackdrop.classList.remove('active');
                document.body.style.overflow = '';
            }

            mobileMenuBtn.addEventListener('click', openMobileSidebar);
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);

            document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                });
            });

            // =============================================
            // 3. PROFILE DROPDOWN TOGGLE
            // =============================================
            const profileToggle = document.getElementById('profileDropdownToggle');
            const profileMenu = document.getElementById('profileDropdownMenu');

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

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    profileToggle.classList.remove('open');
                    profileMenu.classList.remove('open');
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                }
            });

            // =============================================
            // 4. RESPONSIVE HANDLING
            // =============================================
            let resizeTimer;

            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    const width = window.innerWidth;

                    if (width >= 768) {
                        closeMobileSidebar();
                        sidebar.classList.remove('mobile-open', 'mobile-hidden');
                        const saved = localStorage.getItem('sidebarCollapsed');
                        if (saved === 'true') {
                            sidebar.classList.add('collapsed');
                            sidebarToggleIcon.textContent = 'menu';
                        } else {
                            sidebar.classList.remove('collapsed');
                            sidebarToggleIcon.textContent = 'menu_open';
                        }
                    } else {
                        sidebar.classList.add('mobile-hidden');
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'menu_open';
                    }
                }, 250);
            });

            // =============================================
            // 5. MODAL SYSTEM
            // =============================================
            window.openModal = function(id) {
                document.getElementById(id).classList.add('active');
                document.body.style.overflow = 'hidden';
            };

            window.closeModal = function(id) {
                document.getElementById(id).classList.remove('active');
                document.body.style.overflow = '';
            };

            document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal(this.id);
                    }
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-overlay.active').forEach(function(modal) {
                        closeModal(modal.id);
                    });
                }
            });

            // =============================================
            // 6. ROLE PERMISSION EDITOR
            // =============================================
            let currentRolePermissions = [];

            window.openEditRoleModal = function(roleId) {
                document.getElementById('role_id').value = roleId;
                document.getElementById('roleAction').value = 'update_permissions';
                document.getElementById('roleModalTitle').innerHTML = 
                    '<span class="material-symbols-outlined">edit</span> Edit Role Permissions';

                // Reset all checkboxes
                document.querySelectorAll('.permission-checkbox').forEach(function(cb) {
                    cb.checked = false;
                });

                // Fetch role data
                const formData = new FormData();
                formData.append('action', 'get_role');
                formData.append('role_id', roleId);

                fetch('roles.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('role_name').value = data.role.role_name;
                        currentRolePermissions = data.role.permissions || [];
                        
                        // Check the boxes for this role's permissions
                        document.querySelectorAll('.permission-checkbox').forEach(function(cb) {
                            if (currentRolePermissions.includes(cb.value)) {
                                cb.checked = true;
                            }
                        });
                        
                        openModal('roleModal');
                    } else {
                        showToast(data.error || 'Failed to load role.', 'error');
                    }
                })
                .catch(function() {
                    showToast('Error loading role data.', 'error');
                });
            };

            window.openCreateRoleModal = function() {
                document.getElementById('role_id').value = '';
                document.getElementById('role_name').value = '';
                document.getElementById('roleAction').value = 'create_role';
                document.getElementById('roleModalTitle').innerHTML = 
                    '<span class="material-symbols-outlined">add</span> Create New Role';

                // Reset all checkboxes
                document.querySelectorAll('.permission-checkbox').forEach(function(cb) {
                    cb.checked = false;
                });
                currentRolePermissions = [];

                openModal('roleModal');
            };

            window.toggleAllPermissions = function(state) {
                document.querySelectorAll('.permission-checkbox').forEach(function(cb) {
                    cb.checked = state;
                });
            };

            window.saveRolePermissions = function() {
                const roleId = document.getElementById('role_id').value;
                const roleName = document.getElementById('role_name').value.trim();
                const action = document.getElementById('roleAction').value;

                if (!roleName) {
                    showToast('Please enter a role name.', 'warning');
                    return;
                }

                // Collect selected permissions
                const selectedPermissions = [];
                document.querySelectorAll('.permission-checkbox:checked').forEach(function(cb) {
                    selectedPermissions.push(cb.value);
                });

                const formData = new FormData();
                formData.append('action', action);
                formData.append('role_name', roleName);
                formData.append('permissions', JSON.stringify(selectedPermissions));
                
                if (roleId) {
                    formData.append('role_id', roleId);
                }

                const btn = document.querySelector('#roleModal .modal-footer .btn-primary');
                btn.disabled = true;
                btn.innerHTML = '<span class="loader loader-sm" style="margin:0 auto;"></span>';

                fetch('roles.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeModal('roleModal');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(data.error || 'Failed to save role.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Permissions';
                    }
                })
                .catch(function() {
                    showToast('Error saving role.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Permissions';
                });
            };

            // =============================================
            // 7. DELETE ROLE
            // =============================================
            window.deleteRole = function(roleId, roleName) {
                if (!confirm('Are you sure you want to delete the role "' + roleName + '"? This action cannot be undone.')) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'delete_role');
                formData.append('role_id', roleId);

                fetch('roles.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(data.error || 'Failed to delete role.', 'error');
                    }
                })
                .catch(function() {
                    showToast('Error deleting role.', 'error');
                });
            };

            // =============================================
            // 8. TOAST SYSTEM
            // =============================================
            window.showToast = function(message, type) {
                type = type || 'info';
                const existingToast = document.querySelector('.toast');
                if (existingToast) existingToast.remove();

                const toast = document.createElement('div');
                toast.className = 'toast ' + type;
                
                const iconMap = {
                    'success': 'check_circle',
                    'error': 'error',
                    'warning': 'warning',
                    'info': 'info'
                };
                
                toast.innerHTML = '<span class="material-symbols-outlined">' + (iconMap[type] || 'info') + '</span> ' + message;
                document.body.appendChild(toast);

                setTimeout(function() {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(20px)';
                    toast.style.transition = 'all 0.4s ease';
                    setTimeout(function() { toast.remove(); }, 400);
                }, 4000);
            };

// =============================================
// SESSION ACTIVITY MONITOR
// =============================================

let sessionTimer = null;
let warningShown = false;
const SESSION_TIMEOUT = <?php echo SESSION_TIMEOUT_SECONDS; ?>; // 7 minutes
const WARNING_TIME = 60; // Show warning 60 seconds before timeout

/**
 * Update session timer display
 */
function updateSessionTimer() {
    // Get remaining time from server
    fetch('check_session.php')
        .then(response => response.json())
        .then(data => {
            const remaining = data.remaining;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            
            // Update timer display if exists
            const timerEl = document.getElementById('sessionTimer');
            if (timerEl) {
                timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                
                // Change color when running low
                if (remaining < 60) {
                    timerEl.style.color = '#dc2626';
                    timerEl.style.fontWeight = 'bold';
                } else if (remaining < 120) {
                    timerEl.style.color = '#f59e0b';
                } else {
                    timerEl.style.color = '';
                }
            }
            
            // Show warning modal if session is about to expire
            if (remaining <= WARNING_TIME && !warningShown && remaining > 0) {
                warningShown = true;
                showSessionWarning(remaining);
            }
            
            // If session expired, redirect
            if (remaining <= 0) {
                window.location.href = '../../login.php?timeout=1';
            }
        })
        .catch(error => {
            console.log('Session check error:', error);
        });
}

/**
 * Show session expiration warning
 */
function showSessionWarning(remaining) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('sessionWarningModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sessionWarningModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        `;
        
        modal.innerHTML = `
            <div style="
                background: white;
                border-radius: 1.5rem;
                max-width: 440px;
                width: 100%;
                padding: 2rem;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: slideUp 0.3s ease;
                text-align: center;
            ">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">⏰</div>
                <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Session Expiring Soon</h2>
                <p style="color: #464555; font-size: 0.875rem; margin-bottom: 1rem;">
                    Your session will expire in <strong id="warningTimer" style="color: #dc2626;">60</strong> seconds.
                    Please click "Stay Logged In" to continue.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button onclick="extendSession()" style="
                        padding: 0.625rem 1.5rem;
                        background: #4f46e5;
                        color: white;
                        border: none;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Stay Logged In</button>
                    <button onclick="logoutNow()" style="
                        padding: 0.625rem 1.5rem;
                        background: #fef2f2;
                        color: #dc2626;
                        border: 1px solid #fecaca;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Logout</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Show modal
    modal.style.display = 'flex';
    
    // Update countdown inside modal
    const warningTimer = document.getElementById('warningTimer');
    if (warningTimer) {
        let countdown = remaining;
        const interval = setInterval(() => {
            countdown--;
            warningTimer.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(interval);
                window.location.href = '../../login.php?timeout=1';
            }
        }, 1000);
        
        // Store interval to clear it when extending
        modal.dataset.interval = interval;
    }
}

/**
 * Extend session (reset timer)
 */
function extendSession() {
    // Clear any existing warning interval
    const modal = document.getElementById('sessionWarningModal');
    if (modal && modal.dataset.interval) {
        clearInterval(parseInt(modal.dataset.interval));
    }
    
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            if (modal) modal.style.display = 'none';
            showToast('Session extended!', 'success');
        }
    })
    .catch(error => {
        console.log('Extend session error:', error);
    });
}

/**
 * Logout immediately
 */
function logoutNow() {
    window.location.href = '../../logout.php';
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.style.cssText = `
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        padding: 0.875rem 1.5rem;
        border-radius: 0.75rem;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        z-index: 100000;
        animation: slideUp 0.4s ease-out;
    `;
    if (type === 'success') toast.style.background = '#22c55e';
    else if (type === 'error') toast.style.background = '#dc2626';
    else toast.style.background = '#4f46e5';
    
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
// TRACK USER ACTIVITY
// =============================================

let activityTimer = null;

function resetActivityTimer() {
    // Reset the server-side timer via AJAX
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            // Hide warning modal if shown
            const modal = document.getElementById('sessionWarningModal');
            if (modal) modal.style.display = 'none';
        }
    })
    .catch(error => console.log('Reset timer error:', error));
}

// Track user activity events
const activityEvents = ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'];
activityEvents.forEach(event => {
    document.addEventListener(event, () => {
        resetActivityTimer();
    });
});

// =============================================
// START SESSION TIMER
// =============================================

// Update timer every 10 seconds
sessionTimer = setInterval(updateSessionTimer, 10000);

// Initial update
updateSessionTimer();

console.log('⏰ Session timeout: 7 minutes');
console.log('🔄 Activity tracking enabled');


            // =============================================
            // 9. INITIAL STATE
            // =============================================
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            console.log('ISMERS Role Management loaded successfully.');
        })();
    </script>

</body>
</html>