<?php
// portals/admin/biometric_settings.php - Biometric Security Settings
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

// Get settings
$settings = [];
$result = getRecords("SELECT * FROM biometric_settings");
foreach ($result as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $faceThreshold = $_POST['face_threshold'] ?? 0.85;
    $fingerprintThreshold = $_POST['fingerprint_threshold'] ?? 0.90;
    $biometricTimeout = $_POST['biometric_timeout'] ?? 300;
    $requiredRoles = $_POST['required_roles'] ?? 'admin,hr_manager,recruiter';
    $enableBiometrics = isset($_POST['enable_biometrics']) ? 1 : 0;
    $enableFace = isset($_POST['enable_face']) ? 1 : 0;
    $enableFingerprint = isset($_POST['enable_fingerprint']) ? 1 : 0;
    $maxAttempts = $_POST['max_attempts'] ?? 3;
    $lockoutMinutes = $_POST['lockout_minutes'] ?? 30;
    
    updateSetting('face_confidence_threshold', $faceThreshold);
    updateSetting('fingerprint_confidence_threshold', $fingerprintThreshold);
    updateSetting('biometric_timeout', $biometricTimeout);
    updateSetting('biometric_required_roles', $requiredRoles);
    updateSetting('biometric_enabled', $enableBiometrics);
    updateSetting('face_enabled', $enableFace);
    updateSetting('fingerprint_enabled', $enableFingerprint);
    updateSetting('max_attempts', $maxAttempts);
    updateSetting('lockout_minutes', $lockoutMinutes);
    
    $success = 'Biometric settings updated successfully!';
    
    // Refresh settings
    $settings = [];
    $result = getRecords("SELECT * FROM biometric_settings");
    foreach ($result as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'clear_logs') {
        // Clear biometric logs
        $conn->query("TRUNCATE TABLE biometric_logs");
        echo json_encode(['success' => true, 'message' => 'Logs cleared successfully!']);
        exit;
    }
    
    if ($action === 'get_stats') {
        // Get biometric statistics
        $totalAttempts = getRecord("SELECT COUNT(*) as count FROM biometric_logs")['count'] ?? 0;
        $successRate = getRecord("SELECT COUNT(*) as count FROM biometric_logs WHERE status = 'success'")['count'] ?? 0;
        $failureRate = getRecord("SELECT COUNT(*) as count FROM biometric_logs WHERE status = 'failed'")['count'] ?? 0;
        $uniqueUsers = getRecord("SELECT COUNT(DISTINCT user_id) as count FROM biometric_logs")['count'] ?? 0;
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_attempts' => $totalAttempts,
                'success_count' => $successRate,
                'failure_count' => $failureRate,
                'unique_users' => $uniqueUsers,
                'success_rate' => $totalAttempts > 0 ? round(($successRate / $totalAttempts) * 100, 1) : 0
            ]
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Biometric Settings - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - BIOMETRIC SETTINGS
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
           STATS CARDS
        ============================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            transition: all var(--transition-fast);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            margin-top: 0.25rem;
        }

        .stat-card .stat-change {
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        .stat-card .stat-change.positive {
            color: #22c55e;
        }

        .stat-card .stat-change.negative {
            color: #dc2626;
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
           SETTINGS CARD
        ============================================= */
        .settings-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .settings-card .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .settings-card .card-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .settings-card .card-header h2 .material-symbols-outlined {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .settings-card .card-body {
            padding: 1.5rem;
        }

        .settings-card .card-body .form-group {
            margin-bottom: 1.25rem;
        }

        .settings-card .card-body .form-group:last-child {
            margin-bottom: 0;
        }

        .settings-card .card-body .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.375rem;
            color: var(--text-on-surface);
        }

        .settings-card .card-body .form-group .form-control {
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

        .settings-card .card-body .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .settings-card .card-body .form-group .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .settings-card .card-body .form-group .helper-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .settings-card .card-body .form-group .toggle-switch {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 0;
        }

        .settings-card .card-body .form-group .toggle-switch input[type="checkbox"] {
            display: none;
        }

        .settings-card .card-body .form-group .toggle-switch .toggle-track {
            width: 3rem;
            height: 1.75rem;
            background: var(--slate-200);
            border-radius: 50px;
            cursor: pointer;
            position: relative;
            transition: all var(--transition-fast);
            flex-shrink: 0;
        }

        .settings-card .card-body .form-group .toggle-switch .toggle-track .toggle-thumb {
            width: 1.25rem;
            height: 1.25rem;
            background: white;
            border-radius: 50%;
            position: absolute;
            top: 0.25rem;
            left: 0.25rem;
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-sm);
        }

        .settings-card .card-body .form-group .toggle-switch input:checked + .toggle-track {
            background: var(--primary);
        }

        .settings-card .card-body .form-group .toggle-switch input:checked + .toggle-track .toggle-thumb {
            left: 1.5rem;
        }

        .settings-card .card-body .form-group .toggle-switch .toggle-label {
            font-weight: 500;
            font-size: 0.875rem;
        }

        .settings-card .card-body .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .settings-card .card-body .form-row {
                grid-template-columns: 1fr;
            }
        }

        .settings-card .card-body .btn-primary {
            padding: 0.625rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: var(--font-sans);
        }

        .settings-card .card-body .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .settings-card .card-body .btn-primary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .settings-card .card-body .btn-secondary {
            padding: 0.625rem 1.5rem;
            background: transparent;
            color: var(--text-on-surface-variant);
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
        }

        .settings-card .card-body .btn-secondary:hover {
            background: var(--bg-surface-low);
            border-color: var(--primary);
            color: var(--primary);
        }

        .settings-card .card-body .btn-danger {
            padding: 0.625rem 1.5rem;
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
        }

        .settings-card .card-body .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .settings-card .card-body .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }

        /* =============================================
           ACTIVITY LOG
        ============================================= */
        .activity-log {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .activity-log .log-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .activity-log .log-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .activity-log .log-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .activity-log .log-body {
            padding: 0;
            overflow-x: auto;
        }

        .activity-log table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .activity-log table thead {
            background: var(--bg-surface-low);
        }

        .activity-log table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 1px solid var(--slate-200);
        }

        .activity-log table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        .activity-log table tr:last-child td {
            border-bottom: none;
        }

        .activity-log table tr:hover td {
            background: var(--bg-surface-low);
        }

        .activity-log .status-badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .activity-log .status-badge.success {
            background: #d1fae5;
            color: #059669;
        }

        .activity-log .status-badge.failed {
            background: #fecaca;
            color: #dc2626;
        }

        .activity-log .status-badge.pending {
            background: #fef3c7;
            color: #d97706;
        }

        .activity-log .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-on-surface-variant);
        }

        .activity-log .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 0.5rem;
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-card .stat-value {
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

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.5rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-card .stat-value {
                font-size: 1.125rem;
            }

            .stat-card .stat-label {
                font-size: 0.625rem;
            }

            .settings-card .card-header {
                padding: 1rem 1.25rem;
            }

            .settings-card .card-body {
                padding: 1rem 1.25rem;
            }

            .activity-log .log-header {
                padding: 1rem 1.25rem;
            }

            .activity-log table th,
            .activity-log table td {
                padding: 0.5rem 0.75rem;
                font-size: 0.75rem;
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
                    <span class="material-symbols-outlined">fingerprint</span>
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

            <a href="roles.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">shield</span>
                <span class="nav-text">Roles</span>
            </a>

            <a href="biometric_settings.php" class="sidebar-main-link active">
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
                <span style="font-weight:600; font-size:0.875rem;">Biometric Security</span>
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
                        <span class="material-symbols-outlined">fingerprint</span>
                        <span>Biometric Security</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">Settings & Logs</span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        Last updated: <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid" id="statsGrid">
                    <div class="stat-card">
                        <span class="stat-icon">
                            <span class="material-symbols-outlined">analytics</span>
                        </span>
                        <div class="stat-label">Total Attempts</div>
                        <div class="stat-value" id="totalAttempts">--</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="color:#22c55e; background:rgba(34,197,94,0.1);">
                            <span class="material-symbols-outlined">check_circle</span>
                        </span>
                        <div class="stat-label">Success Rate</div>
                        <div class="stat-value" id="successRate">--%</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="color:#dc2626; background:rgba(220,38,38,0.1);">
                            <span class="material-symbols-outlined">error</span>
                        </span>
                        <div class="stat-label">Failures</div>
                        <div class="stat-value" id="failureCount">--</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="color:#d97706; background:rgba(217,119,6,0.1);">
                            <span class="material-symbols-outlined">group</span>
                        </span>
                        <div class="stat-label">Unique Users</div>
                        <div class="stat-value" id="uniqueUsers">--</div>
                    </div>
                </div>

                <!-- Settings Form -->
                <div class="settings-card">
                    <div class="card-header">
                        <h2>
                            <span class="material-symbols-outlined">settings</span>
                            Biometric Configuration
                        </h2>
                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                            Status: 
                            <span style="color:<?php echo ($settings['biometric_enabled'] ?? 0) ? '#22c55e' : '#dc2626'; ?>; font-weight:700;">
                                <?php echo ($settings['biometric_enabled'] ?? 0) ? '🟢 Active' : '🔴 Disabled'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="biometricForm">
                            <!-- Toggle Switches -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Enable Biometric System</label>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="enableBiometrics" name="enable_biometrics" value="1" <?php echo ($settings['biometric_enabled'] ?? 0) ? 'checked' : ''; ?>>
                                        <label for="enableBiometrics" class="toggle-track">
                                            <span class="toggle-thumb"></span>
                                        </label>
                                        <span class="toggle-label"><?php echo ($settings['biometric_enabled'] ?? 0) ? 'Enabled' : 'Disabled'; ?></span>
                                    </div>
                                    <div class="helper-text">Enable or disable all biometric authentication features</div>
                                </div>
                                <div class="form-group">
                                    <label>Face Recognition</label>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="enableFace" name="enable_face" value="1" <?php echo ($settings['face_enabled'] ?? 0) ? 'checked' : ''; ?>>
                                        <label for="enableFace" class="toggle-track">
                                            <span class="toggle-thumb"></span>
                                        </label>
                                        <span class="toggle-label"><?php echo ($settings['face_enabled'] ?? 0) ? 'Enabled' : 'Disabled'; ?></span>
                                    </div>
                                    <div class="helper-text">Allow face recognition for authentication</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Fingerprint Scanner</label>
                                    <div class="toggle-switch">
                                        <input type="checkbox" id="enableFingerprint" name="enable_fingerprint" value="1" <?php echo ($settings['fingerprint_enabled'] ?? 0) ? 'checked' : ''; ?>>
                                        <label for="enableFingerprint" class="toggle-track">
                                            <span class="toggle-thumb"></span>
                                        </label>
                                        <span class="toggle-label"><?php echo ($settings['fingerprint_enabled'] ?? 0) ? 'Enabled' : 'Disabled'; ?></span>
                                    </div>
                                    <div class="helper-text">Allow fingerprint scanning for authentication</div>
                                </div>
                            </div>

                            <!-- Threshold Settings -->
                            <div class="form-row" style="margin-top:1rem;">
                                <div class="form-group">
                                    <label>Face Recognition Threshold</label>
                                    <input type="number" name="face_threshold" step="0.01" min="0.5" max="0.99" 
                                           class="form-control" 
                                           value="<?php echo $settings['face_confidence_threshold'] ?? 0.85; ?>">
                                    <div class="helper-text">Minimum confidence score required (0.50 - 0.99)</div>
                                </div>
                                <div class="form-group">
                                    <label>Fingerprint Threshold</label>
                                    <input type="number" name="fingerprint_threshold" step="0.01" min="0.5" max="0.99" 
                                           class="form-control" 
                                           value="<?php echo $settings['fingerprint_confidence_threshold'] ?? 0.90; ?>">
                                    <div class="helper-text">Minimum confidence score required (0.50 - 0.99)</div>
                                </div>
                            </div>

                            <!-- Additional Settings -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Session Timeout (seconds)</label>
                                    <input type="number" name="biometric_timeout" min="60" max="3600" 
                                           class="form-control" 
                                           value="<?php echo $settings['biometric_timeout'] ?? 300; ?>">
                                    <div class="helper-text">Auto-logout after inactivity (60 - 3600 seconds)</div>
                                </div>
                                <div class="form-group">
                                    <label>Max Attempts</label>
                                    <input type="number" name="max_attempts" min="1" max="10" 
                                           class="form-control" 
                                           value="<?php echo $settings['max_attempts'] ?? 3; ?>">
                                    <div class="helper-text">Maximum failed attempts before lockout</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Lockout Duration (minutes)</label>
                                    <input type="number" name="lockout_minutes" min="5" max="1440" 
                                           class="form-control" 
                                           value="<?php echo $settings['lockout_minutes'] ?? 30; ?>">
                                    <div class="helper-text">How long to lock out after max attempts</div>
                                </div>
                                <div class="form-group">
                                    <label>Required Roles (comma separated)</label>
                                    <input type="text" name="required_roles" 
                                           class="form-control" 
                                           value="<?php echo $settings['biometric_required_roles'] ?? 'admin,hr_manager,recruiter'; ?>">
                                    <div class="helper-text">Roles that require biometric verification</div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn-primary">
                                    <span class="material-symbols-outlined">save</span>
                                    Save Settings
                                </button>
                                <button type="button" class="btn-secondary" onclick="resetSettings()">
                                    <span class="material-symbols-outlined">refresh</span>
                                    Reset Defaults
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Activity Log -->
                <div class="activity-log">
                    <div class="log-header">
                        <h3>
                            <span class="material-symbols-outlined">history</span>
                            Biometric Activity Log
                            <span style="font-weight:400; font-size:0.75rem; color:var(--text-on-surface-variant);">
                                (Last 20 entries)
                            </span>
                        </h3>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <button class="btn-secondary" style="padding:0.375rem 0.75rem; font-size:0.75rem; border-radius:0.5rem;" onclick="refreshLogs()">
                                <span class="material-symbols-outlined" style="font-size:1rem;">refresh</span>
                                Refresh
                            </button>
                            <button class="btn-danger" style="padding:0.375rem 0.75rem; font-size:0.75rem; border-radius:0.5rem;" onclick="clearLogs()">
                                <span class="material-symbols-outlined" style="font-size:1rem;">delete_sweep</span>
                                Clear Logs
                            </button>
                        </div>
                    </div>
                    <div class="log-body" id="logTable">
                        <?php
                        $logs = getRecords("SELECT bl.*, u.first_name, u.last_name, u.email 
                                            FROM biometric_logs bl 
                                            JOIN users u ON bl.user_id = u.id 
                                            ORDER BY bl.created_at DESC LIMIT 20");
                        if (!empty($logs)): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Type</th>
                                        <th>Action</th>
                                        <th>Score</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:0.5rem;">
                                                    <span style="display:inline-flex; align-items:center; justify-content:center; width:1.75rem; height:1.75rem; border-radius:50%; background:var(--bg-surface-low); font-weight:600; font-size:0.7rem; color:var(--primary);">
                                                        <?php echo strtoupper(substr($log['first_name'], 0, 1) ?: 'U'); ?>
                                                    </span>
                                                    <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                                </div>
                                            </td>
                                            <td style="text-transform:capitalize;">
                                                <?php echo $log['biometric_type']; ?>
                                            </td>
                                            <td style="text-transform:capitalize;">
                                                <?php echo $log['action_type']; ?>
                                            </td>
                                            <td>
                                                <?php echo $log['confidence_score'] ?? 'N/A'; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $log['status']; ?>">
                                                    <?php echo ucfirst($log['status']); ?>
                                                </span>
                                            </td>
                                            <td style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">inbox</span>
                                <p>No biometric activity logged yet.</p>
                                <p style="font-size:0.75rem; margin-top:0.25rem;">Biometric events will appear here as users authenticate.</p>
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
        // 4. TOGGLE SWITCH LABELS
        // =============================================
        document.querySelectorAll('.toggle-switch input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', function() {
                const label = this.closest('.toggle-switch').querySelector('.toggle-label');
                if (label) {
                    label.textContent = this.checked ? 'Enabled' : 'Disabled';
                }
            });
        });

        // =============================================
        // 5. LOAD STATISTICS
        // =============================================
        function loadStats() {
            fetch('biometric_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_stats'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const stats = data.data;
                    document.getElementById('totalAttempts').textContent = stats.total_attempts || 0;
                    document.getElementById('successRate').textContent = (stats.success_rate || 0) + '%';
                    document.getElementById('failureCount').textContent = stats.failure_count || 0;
                    document.getElementById('uniqueUsers').textContent = stats.unique_users || 0;
                }
            })
            .catch(error => {
                console.error('Error loading stats:', error);
            });
        }

        // Load stats on page load
        loadStats();

        // =============================================
        // 6. CLEAR LOGS
        // =============================================
        function clearLogs() {
            if (!confirm('Are you sure you want to clear all biometric logs? This action cannot be undone.')) {
                return;
            }

            fetch('biometric_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=clear_logs'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Logs cleared successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Failed to clear logs.', 'error');
                }
            })
            .catch(error => {
                showToast('Error clearing logs.', 'error');
            });
        }

        // =============================================
        // 7. REFRESH LOGS
        // =============================================
        function refreshLogs() {
            location.reload();
        }

        // =============================================
        // 8. RESET SETTINGS
        // =============================================
        function resetSettings() {
            if (!confirm('Reset all biometric settings to default values?')) {
                return;
            }

            // Set default values
            document.querySelector('input[name="face_threshold"]').value = '0.85';
            document.querySelector('input[name="fingerprint_threshold"]').value = '0.90';
            document.querySelector('input[name="biometric_timeout"]').value = '300';
            document.querySelector('input[name="max_attempts"]').value = '3';
            document.querySelector('input[name="lockout_minutes"]').value = '30';
            document.querySelector('input[name="required_roles"]').value = 'admin,hr_manager,recruiter';
            
            // Reset toggles
            const toggles = document.querySelectorAll('.toggle-switch input[type="checkbox"]');
            toggles.forEach(toggle => {
                toggle.checked = true;
                const label = toggle.closest('.toggle-switch').querySelector('.toggle-label');
                if (label) label.textContent = 'Enabled';
            });

            showToast('Settings reset to defaults. Click Save to apply.', 'info');
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
        // 10. FORM SUBMISSION HANDLER
        // =============================================
        document.getElementById('biometricForm').addEventListener('submit', function(e) {
            // Validate threshold values
            const faceThreshold = parseFloat(this.querySelector('input[name="face_threshold"]').value);
            const fingerprintThreshold = parseFloat(this.querySelector('input[name="fingerprint_threshold"]').value);
            
            if (faceThreshold < 0.5 || faceThreshold > 0.99) {
                e.preventDefault();
                showToast('Face threshold must be between 0.50 and 0.99.', 'error');
                return;
            }
            
            if (fingerprintThreshold < 0.5 || fingerprintThreshold > 0.99) {
                e.preventDefault();
                showToast('Fingerprint threshold must be between 0.50 and 0.99.', 'error');
                return;
            }
            
            showToast('Settings saved successfully!', 'success');
        });

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
            }
        });

        console.log('🔐 ISMERS Biometric Settings loaded successfully!');
    </script>

</body>
</html>