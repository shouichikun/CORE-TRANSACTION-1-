<?php
// portals/admin/users.php - User Management with Modal System
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

// Get filter parameters
$roleFilter = $_GET['role'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Build query conditions
$conditions = [];
$params = [];
$types = "";

if ($roleFilter !== 'all') {
    $conditions[] = "role = ?";
    $params[] = $roleFilter;
    $types .= "s";
}

if ($statusFilter !== 'all') {
    $conditions[] = "is_active = ?";
    $params[] = $statusFilter === 'active' ? 1 : 0;
    $types .= "i";
}

if (!empty($searchQuery)) {
    $conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get users
$sql = "SELECT id, first_name, last_name, email, role, is_active, created_at, last_activity 
        FROM users 
        $whereClause
        ORDER BY created_at DESC";

$users = getRecords($sql, $params, $types);

// Get role counts for filter
$roleCounts = [];
$roles = ['admin', 'hr_manager', 'recruiter', 'client', 'applicant', 'employee', 'supervisor'];
foreach ($roles as $role) {
    $count = getRecord("SELECT COUNT(*) as count FROM users WHERE role = ?", [$role], "s")['count'] ?? 0;
    $roleCounts[$role] = $count;
}

$totalUsers = getRecord("SELECT COUNT(*) as count FROM users")['count'] ?? 0;
$activeUsers = getRecord("SELECT COUNT(*) as count FROM users WHERE is_active = 1")['count'] ?? 0;
$inactiveUsers = getRecord("SELECT COUNT(*) as count FROM users WHERE is_active = 0")['count'] ?? 0;

// Handle AJAX POST actions (for modal operations)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    
    // Toggle status
    if ($action === 'toggle_status' && $userId > 0) {
        $user = getUserById($userId);
        if ($user) {
            $newStatus = $user['is_active'] == 1 ? 0 : 1;
            $updateSql = "UPDATE users SET is_active = ? WHERE id = ?";
            $result = updateRecord($updateSql, [$newStatus, $userId], "ii");
            
            if ($result) {
                logActivity($_SESSION['user_id'], 'User Status Toggled', 'users', $userId, 'User ' . $user['email'] . ' status changed to ' . ($newStatus ? 'Active' : 'Inactive'));
                echo json_encode(['success' => true, 'message' => 'User status updated successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update user status.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found.']);
        }
        exit;
    }
    
    // Delete user
    if ($action === 'delete_user' && $userId > 0) {
        if ($userId == $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'error' => 'You cannot delete your own account.']);
            exit;
        }
        
        $user = getUserById($userId);
        if ($user) {
            $sql = "DELETE FROM users WHERE id = ?";
            $result = deleteRecord($sql, [$userId], "i");
            if ($result) {
                logActivity($_SESSION['user_id'], 'User Deleted', 'users', $userId, 'Deleted user: ' . $user['email']);
                echo json_encode(['success' => true, 'message' => 'User deleted successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete user.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found.']);
        }
        exit;
    }
    
    // Update role
    if ($action === 'update_role' && $userId > 0) {
        $newRole = $_POST['role'] ?? '';
        if (!in_array($newRole, $roles)) {
            echo json_encode(['success' => false, 'error' => 'Invalid role.']);
            exit;
        }
        
        $user = getUserById($userId);
        if ($user) {
            $updateSql = "UPDATE users SET role = ? WHERE id = ?";
            $result = updateRecord($updateSql, [$newRole, $userId], "si");
            if ($result) {
                logActivity($_SESSION['user_id'], 'User Role Updated', 'users', $userId, 'User ' . $user['email'] . ' role changed to ' . $newRole);
                echo json_encode(['success' => true, 'message' => 'User role updated successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update user role.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found.']);
        }
        exit;
    }
    
    // Update user details
    if ($action === 'update_user' && $userId > 0) {
        $firstName = $_POST['first_name'] ?? '';
        $lastName = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? '';
        
        if (empty($firstName) || empty($lastName) || empty($email) || empty($role)) {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']);
            exit;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid email format.']);
            exit;
        }
        
        if (!in_array($role, $roles)) {
            echo json_encode(['success' => false, 'error' => 'Invalid role.']);
            exit;
        }
        
        // Check if email exists for other users
        $existing = getRecord("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId], "si");
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'Email already in use by another user.']);
            exit;
        }
        
        $user = getUserById($userId);
        if ($user) {
            $updateSql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ? WHERE id = ?";
            $result = updateRecord($updateSql, [$firstName, $lastName, $email, $role, $userId], "ssssi");
            if ($result) {
                logActivity($_SESSION['user_id'], 'User Updated', 'users', $userId, 'Updated user: ' . $email);
                echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update user.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found.']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

// Status badge mapping
$roleLabels = [
    'admin' => 'Admin',
    'hr_manager' => 'HR Manager',
    'recruiter' => 'Recruiter',
    'client' => 'Client',
    'applicant' => 'Applicant',
    'employee' => 'Employee',
    'supervisor' => 'Supervisor'
];

$roleBadges = [
    'admin' => 'badge-admin',
    'hr_manager' => 'badge-hr',
    'recruiter' => 'badge-recruiter',
    'client' => 'badge-client',
    'applicant' => 'badge-applicant',
    'employee' => 'badge-employee',
    'supervisor' => 'badge-supervisor'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>User Management - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - USER MANAGEMENT
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
            --online-color: #22c55e;
            --offline-color: #9ca3af;
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

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: var(--primary);
            color: var(--on-primary);
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
        }

        .btn-primary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        /* =============================================
                   STATS ROW
                ============================================= */
        .stats-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .stat-item {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
            text-align: center;
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

        /* =============================================
                   SEARCH & FILTERS
                ============================================= */
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-input-wrapper input {
            width: 100%;
            padding: 0.625rem 1rem 0.625rem 2.75rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: inherit;
            background: var(--bg-surface);
            transition: all var(--transition-fast);
            color: var(--text-on-surface);
        }

        .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .search-input-wrapper .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
        }

        .search-input-wrapper .search-icon .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: transparent;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.875rem;
            border: 2px solid var(--primary);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--on-primary);
        }

        .btn-outline .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .filters {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }

        .filters select {
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.625rem;
            font-size: 0.8125rem;
            font-family: inherit;
            background: var(--bg-surface);
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            min-width: 140px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%235a6a7a'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }

        .filters select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* =============================================
                   USERS TABLE
                ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .card-header .result-count {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
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
            padding: 0.75rem 1.25rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--slate-200);
        }

        table td {
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        table tbody tr:hover {
            background: var(--bg-surface-low);
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-info .avatar {
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
            position: relative;
        }

        .user-info .avatar .status-dot {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid var(--bg-surface);
        }

        .status-dot.online {
            background: var(--online-color);
        }

        .status-dot.offline {
            background: var(--offline-color);
        }

        .user-info .details .name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .user-info .details .email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .status-badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-badge.active {
            background: #d1fae5;
            color: #059669;
        }

        .status-badge.inactive {
            background: #fecaca;
            color: #dc2626;
        }

        .role-badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .role-badge.badge-admin { background: #fef3c7; color: #d97706; }
        .role-badge.badge-hr { background: #dbeafe; color: #2563eb; }
        .role-badge.badge-recruiter { background: #d1fae5; color: #059669; }
        .role-badge.badge-client { background: #e0e7ff; color: #4f46e5; }
        .role-badge.badge-applicant { background: #fce7f3; color: #db2777; }
        .role-badge.badge-employee { background: #cffafe; color: #0891b2; }
        .role-badge.badge-supervisor { background: #ede9fe; color: #7c3aed; }

        /* =============================================
                   EMPTY STATE
                ============================================= */
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

        /* =============================================
                   MODAL SYSTEM
                ============================================= */
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
            max-width: 560px;
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
        }

        .modal-footer .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .modal-footer .btn-primary {
            background: var(--primary);
            color: var(--on-primary);
        }

        .modal-footer .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
        }

        .modal-footer .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .modal-footer .btn-outline:hover {
            background: var(--primary);
            color: var(--on-primary);
        }

        .modal-footer .btn-danger {
            background: #dc2626;
            color: white;
        }

        .modal-footer .btn-danger:hover {
            background: #b91c1c;
        }

        .modal-footer .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .modal-footer .btn-warning:hover {
            background: #d97706;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        .form-group {
            margin-bottom: 1rem;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group input:disabled {
            background: var(--bg-surface-low);
            cursor: not-allowed;
            opacity: 0.7;
        }

        /* Countdown */
        .countdown-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            background: #fef3c7;
            border-radius: 0.625rem;
            margin: 0.75rem 0 0.25rem;
            border: 1px solid #fcd34d;
        }

        .countdown-container .countdown-number {
            font-size: 2rem;
            font-weight: 800;
            color: #d97706;
            min-width: 2.5rem;
            text-align: center;
        }

        .countdown-container .countdown-label {
            font-size: 0.875rem;
            color: #92400e;
            font-weight: 500;
        }

        .countdown-container.danger {
            background: #fecaca;
            border-color: #f87171;
        }

        .countdown-container.danger .countdown-number {
            color: #dc2626;
        }

        .countdown-container.danger .countdown-label {
            color: #991b1b;
        }

        .modal-body .warning-text {
            color: #dc2626;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .modal-body .info-text {
            color: var(--text-on-surface-variant);
            font-size: 0.875rem;
            line-height: 1.6;
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

            .stats-row {
                grid-template-columns: 1fr 1fr;
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
                padding: 0.625rem 0.875rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal {
                max-width: 100%;
                margin: 0.625rem;
                max-height: 95vh;
            }

            .modal-header h2 {
                font-size: 1.0625rem;
            }

            .modal-body {
                padding: 1rem;
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

            .stats-row {
                grid-template-columns: 1fr;
            }

            .stat-item .number {
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
                padding: 0.5rem 0.625rem;
            }

            .user-info .avatar {
                width: 2rem;
                height: 2rem;
                font-size: 0.625rem;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .countdown-container .countdown-number {
                font-size: 1.5rem;
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

        /* Loader */
        .loader {
            width: 40px;
            height: 40px;
            border: 4px solid var(--slate-200);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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

            <a href="users.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Users</span>
                <span class="nav-badge"><?php echo $totalUsers; ?></span>
            </a>

            <a href="roles.php" class="sidebar-main-link">
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
    MAIN CONTENT - PUSHED BY SIDEBAR
    ============================================= -->
    <div class="main-wrapper" id="mainWrapper">

        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
                <div class="logo">I</div>
                <span class="separator">|</span>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle Sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">menu_open</span>
                </button>
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" title="Open Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <span class="logo-text" style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface); display:none;">ISMERS</span>
            </div>

            <!-- Profile Dropdown -->
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Administrator</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-header">Account</div>
                    
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
                        <span class="material-symbols-outlined">people</span>
                        <span>User Management</span>
                        <span class="status-dot"></span>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>User Management</h1>
                        <p>Manage all users and their roles</p>
                    </div>
                    <a href="add_user.php" class="btn-primary">
                        <span class="material-symbols-outlined">person_add</span>
                        Add New User
                    </a>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="number"><?php echo $totalUsers; ?></div>
                        <div class="label">Total Users</div>
                    </div>
                    <div class="stat-item">
                        <div class="number"><?php echo $activeUsers; ?></div>
                        <div class="label">Active</div>
                    </div>
                    <div class="stat-item">
                        <div class="number"><?php echo $inactiveUsers; ?></div>
                        <div class="label">Inactive</div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <span class="search-icon">
                            <span class="material-symbols-outlined">search</span>
                        </span>
                        <input type="text" id="searchInput" placeholder="Search by name or email..." 
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <button class="btn-primary" onclick="applyFilters()">Search</button>
                    <?php if (!empty($searchQuery) || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
                        <a href="users.php" class="btn-outline">
                            <span class="material-symbols-outlined">close</span>
                            Clear Filters
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Filters -->
                <div class="filters">
                    <select id="roleFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <?php foreach ($roleLabels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $roleFilter === $key ? 'selected' : ''; ?>>
                                <?php echo $label; ?> (<?php echo $roleCounts[$key] ?? 0; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="statusFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <!-- Users Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>All Users</h3>
                        <span class="result-count"><?php echo count($users); ?> users found</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($users)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <span class="material-symbols-outlined" style="font-size:3rem;">people_outline</span>
                                </div>
                                <h4>No Users Found</h4>
                                <p>Try adjusting your search or filters.</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): 
                                        $isOnline = !empty($user['last_activity']) && strtotime($user['last_activity']) > strtotime('-5 minutes');
                                        $statusClass = $isOnline ? 'online' : 'offline';
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="user-info">
                                                    <span class="avatar">
                                                        <?php echo strtoupper(substr($user['first_name'] ?? 'U', 0, 1)); ?>
                                                        <span class="status-dot <?php echo $statusClass; ?>"></span>
                                                    </span>
                                                    <div class="details">
                                                        <div class="name">
                                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                        </div>
                                                        <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="role-badge <?php echo $roleBadges[$user['role']] ?? 'badge-applicant'; ?>">
                                                    <?php echo $roleLabels[$user['role']] ?? ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <div style="display:flex; gap:0.375rem; justify-content:center; flex-wrap:wrap;">
                                                    <button class="btn-primary btn-sm" onclick="openEditModal(<?php echo $user['id']; ?>)">
                                                        <span class="material-symbols-outlined" style="font-size:1rem;">edit</span>
                                                        Edit
                                                    </button>
                                                    <button class="btn-warning btn-sm" onclick="openStatusModal(<?php echo $user['id']; ?>, <?php echo $user['is_active']; ?>)">
                                                        <?php if ($user['is_active']): ?>
                                                            <span class="material-symbols-outlined" style="font-size:1rem;">block</span>
                                                            Deactivate
                                                        <?php else: ?>
                                                            <span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span>
                                                            Activate
                                                        <?php endif; ?>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <button class="btn-danger btn-sm" onclick="openDeleteModal(<?php echo $user['id']; ?>)">
                                                            <span class="material-symbols-outlined" style="font-size:1rem;">delete</span>
                                                            Delete
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
    MODAL: EDIT USER
    ============================================= -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">edit</span>
                    Edit User
                </h2>
                <button class="btn-close-modal" onclick="closeModal('editModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <input type="hidden" name="action" value="update_user">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_first_name">First Name</label>
                            <input type="text" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_last_name">Last Name</label>
                            <input type="text" id="edit_last_name" name="last_name" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email Address</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_role">Role</label>
                        <select id="edit_role" name="role" required>
                            <?php foreach ($roleLabels as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('editModal')">Cancel</button>
                <button class="btn-primary" onclick="submitEditForm()">
                    <span class="material-symbols-outlined">save</span>
                    Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    MODAL: CONFIRM STATUS CHANGE (with 5s countdown)
    ============================================= -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal">
            <div class="modal-header">
                <h2 id="statusModalTitle">
                    <span class="material-symbols-outlined">warning</span>
                    Confirm Status Change
                </h2>
                <button class="btn-close-modal" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="status_user_id">
                <input type="hidden" id="status_new_status">
                
                <p class="info-text" id="statusMessage">
                    Are you sure you want to <strong id="statusActionText">deactivate</strong> this user?
                </p>
                <p class="warning-text" id="statusWarning" style="display:none;">
                    This user will lose access to the system immediately!
                </p>
                
                <div class="countdown-container" id="statusCountdown">
                    <span class="countdown-number" id="statusCountdownNumber">5</span>
                    <span class="countdown-label">seconds remaining to confirm...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('statusModal')">Cancel</button>
                <button class="btn-warning" id="statusConfirmBtn" disabled onclick="confirmStatusChange()">
                    <span class="material-symbols-outlined">check</span>
                    Confirm <span id="statusConfirmLabel">(5s)</span>
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    MODAL: CONFIRM DELETE (with 5s countdown)
    ============================================= -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined" style="color:#dc2626;">delete_forever</span>
                    Delete User
                </h2>
                <button class="btn-close-modal" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="delete_user_id">
                
                <p class="info-text">
                    Are you sure you want to <strong style="color:#dc2626;">permanently delete</strong> this user?
                </p>
                <p class="warning-text">
                    This action <strong>cannot be undone</strong>! All user data will be lost.
                </p>
                
                <div class="countdown-container danger" id="deleteCountdown">
                    <span class="countdown-number" id="deleteCountdownNumber">5</span>
                    <span class="countdown-label">seconds remaining to confirm...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <button class="btn-danger" id="deleteConfirmBtn" disabled onclick="confirmDelete()">
                    <span class="material-symbols-outlined">delete</span>
                    Confirm Delete <span id="deleteConfirmLabel">(5s)</span>
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
            // 5. FILTERS
            // =============================================
            window.applyFilters = function() {
                const search = document.getElementById('searchInput').value;
                const role = document.getElementById('roleFilter').value;
                const status = document.getElementById('statusFilter').value;
                
                let url = 'users.php?';
                if (role !== 'all') url += 'role=' + role + '&';
                if (status !== 'all') url += 'status=' + status + '&';
                if (search) url += 'search=' + encodeURIComponent(search);
                
                window.location.href = url;
            };

            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });

            // =============================================
            // 6. MODAL SYSTEM
            // =============================================
            let countdownInterval = null;
            let currentCountdown = 5;

            function openModal(id) {
                document.getElementById(id).classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeModal(id) {
                document.getElementById(id).classList.remove('active');
                document.body.style.overflow = '';
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
            }

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
            // 7. EDIT MODAL
            // =============================================
            window.openEditModal = function(userId) {
                fetch('get_user.php?id=' + userId)
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            document.getElementById('edit_user_id').value = data.user.id;
                            document.getElementById('edit_first_name').value = data.user.first_name;
                            document.getElementById('edit_last_name').value = data.user.last_name;
                            document.getElementById('edit_email').value = data.user.email;
                            document.getElementById('edit_role').value = data.user.role;
                            openModal('editModal');
                        } else {
                            showToast('Error loading user data.', 'error');
                        }
                    })
                    .catch(function() {
                        showToast('Error loading user data.', 'error');
                    });
            };

            window.submitEditForm = function() {
                const form = document.getElementById('editForm');
                const formData = new FormData(form);
                
                const btn = document.querySelector('#editModal .modal-footer .btn-primary');
                btn.disabled = true;
                btn.innerHTML = '<span class="loader" style="width:16px;height:16px;border-width:2px;margin:0 auto;"></span>';
                
                fetch('users.php', {
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
                        closeModal('editModal');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(data.error || 'Failed to update user.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Changes';
                    }
                })
                .catch(function() {
                    showToast('Error updating user.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">save</span> Save Changes';
                });
            };

            // =============================================
            // 8. STATUS MODAL (with 5s countdown)
            // =============================================
            window.openStatusModal = function(userId, currentStatus) {
                const newStatus = currentStatus ? 0 : 1;
                const action = currentStatus ? 'deactivate' : 'activate';
                const actionText = currentStatus ? 'Deactivate' : 'Activate';
                
                document.getElementById('status_user_id').value = userId;
                document.getElementById('status_new_status').value = newStatus;
                document.getElementById('statusActionText').textContent = action;
                document.getElementById('statusModalTitle').innerHTML = '<span class="material-symbols-outlined">warning</span> Confirm ' + actionText;
                document.getElementById('statusMessage').innerHTML = 
                    'Are you sure you want to <strong>' + action + '</strong> this user?';
                
                const warning = document.getElementById('statusWarning');
                if (action === 'deactivate') {
                    warning.style.display = 'block';
                } else {
                    warning.style.display = 'none';
                }
                
                const confirmBtn = document.getElementById('statusConfirmBtn');
                confirmBtn.disabled = true;
                confirmBtn.className = 'btn btn-warning';
                
                if (countdownInterval) clearInterval(countdownInterval);
                currentCountdown = 5;
                document.getElementById('statusCountdownNumber').textContent = currentCountdown;
                document.getElementById('statusConfirmLabel').textContent = '(' + currentCountdown + 's)';
                
                openModal('statusModal');
                
                countdownInterval = setInterval(function() {
                    currentCountdown--;
                    document.getElementById('statusCountdownNumber').textContent = currentCountdown;
                    document.getElementById('statusConfirmLabel').textContent = '(' + currentCountdown + 's)';
                    
                    if (currentCountdown <= 0) {
                        clearInterval(countdownInterval);
                        countdownInterval = null;
                        confirmBtn.disabled = false;
                        document.getElementById('statusConfirmLabel').textContent = 'Confirm';
                    }
                }, 1000);
            };

            window.confirmStatusChange = function() {
                const userId = document.getElementById('status_user_id').value;
                const newStatus = document.getElementById('status_new_status').value;
                
                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('user_id', userId);
                
                const btn = document.getElementById('statusConfirmBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="loader" style="width:16px;height:16px;border-width:2px;margin:0 auto;"></span>';
                
                fetch('users.php', {
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
                        closeModal('statusModal');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(data.error || 'Failed to update status.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<span class="material-symbols-outlined">check</span> Confirm';
                    }
                })
                .catch(function() {
                    showToast('Error updating status.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">check</span> Confirm';
                });
            };

            // =============================================
            // 9. DELETE MODAL (with 5s countdown)
            // =============================================
            window.openDeleteModal = function(userId) {
                document.getElementById('delete_user_id').value = userId;
                
                const confirmBtn = document.getElementById('deleteConfirmBtn');
                confirmBtn.disabled = true;
                confirmBtn.className = 'btn btn-danger';
                
                if (countdownInterval) clearInterval(countdownInterval);
                currentCountdown = 5;
                document.getElementById('deleteCountdownNumber').textContent = currentCountdown;
                document.getElementById('deleteConfirmLabel').textContent = '(' + currentCountdown + 's)';
                
                openModal('deleteModal');
                
                countdownInterval = setInterval(function() {
                    currentCountdown--;
                    document.getElementById('deleteCountdownNumber').textContent = currentCountdown;
                    document.getElementById('deleteConfirmLabel').textContent = '(' + currentCountdown + 's)';
                    
                    if (currentCountdown <= 0) {
                        clearInterval(countdownInterval);
                        countdownInterval = null;
                        confirmBtn.disabled = false;
                        document.getElementById('deleteConfirmLabel').textContent = 'Confirm Delete';
                    }
                }, 1000);
            };

            window.confirmDelete = function() {
                const userId = document.getElementById('delete_user_id').value;
                
                const formData = new FormData();
                formData.append('action', 'delete_user');
                formData.append('user_id', userId);
                
                const btn = document.getElementById('deleteConfirmBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="loader" style="width:16px;height:16px;border-width:2px;margin:0 auto;"></span>';
                
                fetch('users.php', {
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
                        closeModal('deleteModal');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(data.error || 'Failed to delete user.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<span class="material-symbols-outlined">delete</span> Confirm Delete';
                    }
                })
                .catch(function() {
                    showToast('Error deleting user.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">delete</span> Confirm Delete';
                });
            };

            // =============================================
            // 10. TOAST SYSTEM
            // =============================================
            function showToast(message, type) {
                type = type || 'info';
                const existingToast = document.querySelector('.toast');
                if (existingToast) existingToast.remove();

                const toast = document.createElement('div');
                toast.className = 'toast ' + type;
                toast.textContent = message;
                document.body.appendChild(toast);

                setTimeout(function() {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(20px)';
                    toast.style.transition = 'all 0.4s ease';
                    setTimeout(function() { toast.remove(); }, 400);
                }, 4000);
            }

            // =============================================
            // 11. INITIAL STATE
            // =============================================
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            console.log('ISMERS User Management loaded successfully.');
        })();
    </script>

</body>
</html>