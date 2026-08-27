<?php
// portals/admin/face_enroll.php - Face Enrollment for Users
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

// Get user to enroll
$enrollUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$user = null;
$userName = '';

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
if ($enrollUserId > 0) {
    $user = getRecord("SELECT id, first_name, last_name, email, role FROM users WHERE id = $1", [$enrollUserId]);
    if ($user) {
        $userName = $user['first_name'] . ' ' . $user['last_name'];
    }
}

// ✅ FIXED: Check if user already has a face enrolled - PostgreSQL uses $1 placeholder
$hasFace = false;
if ($enrollUserId > 0) {
    $existing = getRecord("SELECT id FROM face_scans WHERE user_id = $1", [$enrollUserId]);
    $hasFace = !empty($existing);
}

// ✅ FIXED: PostgreSQL uses $1 placeholder - removed type string
$totalUsers = getRecord("SELECT COUNT(*) as count FROM users")['count'] ?? 0;

// Get user profile data for sidebar
$userProfile = getUserProfileData($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Face Enrollment - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
        MATERIAL 3 DESIGN SYSTEM - FACE ENROLLMENT
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
        .sidebar-brand-icon .material-symbols-outlined { font-size: 1.5rem; }
        .sidebar-brand-text { font-size: 0.875rem; font-weight: 600; color: var(--slate-900); }
        .sidebar-brand-category { font-size: 0.75rem; color: var(--slate-500); margin-top: 0.25rem; }

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
        .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-main-link.active { background: var(--bg-surface-container-high); color: var(--primary); }
        .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
        .sidebar-main-link .nav-text { transition: opacity 0.3s ease; }
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
        .sidebar-footer .user-card .user-info .user-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.75rem; color: var(--text-on-surface-variant); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

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
        .sidebar-backdrop.active { display: block; opacity: 1; }

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
        .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }

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
        .top-header-left { display: flex; align-items: center; gap: 0.75rem; }
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
        .top-header-left .separator { color: var(--outline-variant); font-weight: 300; user-select: none; }
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
        .sidebar-toggle-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-toggle-btn .material-symbols-outlined { font-size: 1.25rem; }
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
        .mobile-menu-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .mobile-menu-btn .material-symbols-outlined { font-size: 1.25rem; }

        .profile-dropdown-wrapper { position: relative; }
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
        .profile-dropdown-toggle:hover { background: var(--bg-surface-low); border-color: rgba(199, 196, 216, 0.3); }
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
        .profile-dropdown-toggle .profile-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
        .profile-dropdown-toggle .profile-role { font-size: 0.75rem; color: var(--text-on-surface-variant); font-weight: 400; }
        .profile-dropdown-toggle .material-symbols-outlined { font-size: 1rem; color: var(--text-on-surface-variant); transition: transform var(--transition-fast); }
        .profile-dropdown-toggle.open .material-symbols-outlined:last-child { transform: rotate(180deg); }
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
        .profile-dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
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
        .profile-dropdown-menu .dropdown-item:hover { background: var(--bg-surface-low); color: var(--primary); }
        .profile-dropdown-menu .dropdown-item .material-symbols-outlined { font-size: 1.125rem; color: var(--text-on-surface-variant); }
        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined { color: var(--primary); }
        .profile-dropdown-menu .dropdown-item.danger { color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger:hover { background: #fef2f2; color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined { color: #dc2626; }
        .profile-dropdown-menu .dropdown-divider { height: 1px; background: var(--slate-200); margin: 0.25rem 0.5rem; }

        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }
        .main-scroll .container { max-width: 80rem; margin: 0 auto; }

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
        .breadcrumb-view .material-symbols-outlined { font-size: 1.25rem; }
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
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
        }
        .page-header h1 { font-size: 1.875rem; font-weight: 700; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.25rem; }

        /* =============================================
        FACE ENROLLMENT UI
        ============================================= */
        .enrollment-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 768px) {
            .enrollment-container { grid-template-columns: 1fr; }
        }

        .enrollment-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .enrollment-card .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .enrollment-card .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .enrollment-card .card-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }
        .enrollment-card .card-body {
            padding: 1.5rem;
        }

        /* User Info */
        .user-info-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-xl);
            margin-bottom: 1rem;
        }
        .user-info-card .user-avatar {
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .user-info-card .user-details .name {
            font-size: 1.125rem;
            font-weight: 700;
        }
        .user-info-card .user-details .email {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }
        .user-info-card .user-details .role {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            background: var(--bg-surface-low);
            color: var(--text-on-surface-variant);
            border: 1px solid var(--slate-200);
            margin-top: 0.25rem;
        }
        .user-info-card .user-details .role.admin { background: #fef3c7; color: #d97706; border-color: #fcd34d; }
        .user-info-card .user-details .role.hr_manager { background: #dbeafe; color: #2563eb; border-color: #93c5fd; }
        .user-info-card .user-details .role.recruiter { background: #d1fae5; color: #059669; border-color: #6ee7b7; }
        .user-info-card .user-details .role.client { background: #e0e7ff; color: #4f46e5; border-color: #a5b4fc; }
        .user-info-card .user-details .role.applicant { background: #fce7f3; color: #db2777; border-color: #f9a8d4; }
        .user-info-card .user-details .role.employee { background: #cffafe; color: #0891b2; border-color: #67e8f9; }
        .user-info-card .user-details .role.supervisor { background: #ede9fe; color: #7c3aed; border-color: #c4b5fd; }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-badge.enrolled { background: #d1fae5; color: #059669; }
        .status-badge.not-enrolled { background: #fef3c7; color: #d97706; }

        /* Video Container */
        .face-video-wrapper {
            position: relative;
            background: #1a1a2e;
            border-radius: var(--radius-xl);
            overflow: hidden;
            aspect-ratio: 4 / 3;
            min-height: 280px;
            border: 2px solid var(--slate-200);
            transition: all 0.3s ease;
        }
        .face-video-wrapper.active { border-color: var(--primary); }
        .face-video-wrapper.scanning { border-color: var(--warning-color); animation: scanPulse 1.5s ease-in-out infinite; }
        .face-video-wrapper.success { border-color: var(--success-color); }
        .face-video-wrapper.failed { border-color: var(--error-color); }

        @keyframes scanPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.3); }
            50% { box-shadow: 0 0 30px 10px rgba(79, 70, 229, 0.1); }
        }

        .face-video-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .face-video-wrapper canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
        }
        .face-video-wrapper .face-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: 1;
        }
        .face-video-wrapper .face-overlay .face-guide {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
        }
        .face-video-wrapper .face-overlay .face-guide .guide-dot {
            position: absolute;
            width: 8px;
            height: 8px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
        }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.tl { top: -4px; left: -4px; }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.tr { top: -4px; right: -4px; }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.bl { bottom: -4px; left: -4px; }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.br { bottom: -4px; right: -4px; }

        .face-video-wrapper .face-overlay .face-status {
            position: absolute;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            padding: 0.5rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            color: white;
            display: none;
            white-space: nowrap;
            z-index: 3;
        }
        .face-video-wrapper .face-overlay .face-status.show { display: block; animation: slideUp 0.3s ease; }
        .face-video-wrapper .face-overlay .face-status.success { background: rgba(34, 197, 94, 0.9); }
        .face-video-wrapper .face-overlay .face-status.failed { background: rgba(220, 38, 38, 0.9); }
        .face-video-wrapper .face-overlay .face-status.scanning { background: rgba(79, 70, 229, 0.9); }

        @keyframes slideUp {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        .face-status-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            background: var(--bg-surface-low);
            margin-top: 1rem;
        }
        .face-status-indicator .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        .face-status-indicator .status-dot.idle { background: #9ca3af; }
        .face-status-indicator .status-dot.loading { background: var(--warning-color); animation: pulse 1s infinite; }
        .face-status-indicator .status-dot.success { background: var(--success-color); }
        .face-status-indicator .status-dot.failed { background: var(--error-color); }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.6; }
        }

        .face-controls {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .face-controls .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }
        .face-controls .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        .face-controls .btn-primary {
            background: var(--primary);
            color: white;
        }
        .face-controls .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
        }
        .face-controls .btn-success {
            background: var(--success-color);
            color: white;
        }
        .face-controls .btn-success:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.3);
        }
        .face-controls .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        .face-controls .btn-outline:hover:not(:disabled) {
            background: var(--primary);
            color: white;
        }
        .face-controls .btn .material-symbols-outlined { font-size: 1.125rem; }

        .ai-dots-loading-sm {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0;
        }
        .ai-dots-loading-sm .dot {
            width: 0.375rem;
            height: 0.375rem;
            background: white;
            border-radius: 50%;
            animation: dotPulseSm 1.4s infinite ease-in-out both;
        }
        .ai-dots-loading-sm .dot:nth-child(1) { animation-delay: -0.32s; }
        .ai-dots-loading-sm .dot:nth-child(2) { animation-delay: -0.16s; }
        .ai-dots-loading-sm .dot:nth-child(3) { animation-delay: 0s; }
        @keyframes dotPulseSm {
            0%, 80%, 100% { transform: scale(0.5); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* Enroll Steps */
        .enroll-steps {
            margin-top: 1rem;
        }
        .enroll-steps .step {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            background: var(--bg-surface-low);
            margin-bottom: 0.5rem;
            opacity: 0.5;
            transition: all 0.3s ease;
        }
        .enroll-steps .step.active {
            opacity: 1;
            background: var(--primary-container);
            border-left: 3px solid var(--primary);
        }
        .enroll-steps .step.completed {
            opacity: 1;
            background: #d1fae5;
            border-left: 3px solid var(--success-color);
        }
        .enroll-steps .step .step-num {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        .enroll-steps .step.completed .step-num { background: var(--success-color); }
        .enroll-steps .step .step-text { flex: 1; font-size: 0.875rem; }
        .enroll-steps .step .step-status {
            font-size: 0.75rem;
            font-weight: 600;
        }
        .enroll-steps .step .step-status.pending { color: var(--text-on-surface-variant); }
        .enroll-steps .step .step-status.done { color: var(--success-color); }
        .enroll-steps .step .step-status.failed { color: var(--error-color); }

        /* Toast */
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
        .toast.success { background: var(--success-color); }
        .toast.error { background: var(--error-color); }
        .toast.info { background: var(--primary); }

        /* Responsive */
        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-xl); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-xl); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .dashboard-sidebar.collapsed { width: var(--sidebar-width); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .enrollment-container { grid-template-columns: 1fr; }
            .face-video-wrapper { min-height: 200px; }
            .user-info-card { flex-direction: column; text-align: center; }
            .face-controls .btn { flex: 1; justify-content: center; }
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
            .breadcrumb-bar { padding: 0.75rem 1rem; }
            .page-header h1 { font-size: 1.5rem; }
            .face-video-wrapper { min-height: 180px; }
            .face-video-wrapper .face-overlay .face-guide { width: 140px; height: 140px; }
            .user-info-card .user-avatar { width: 3rem; height: 3rem; font-size: 1rem; }
            .enrollment-card .card-header { padding: 1rem 1.25rem; }
            .enrollment-card .card-body { padding: 1rem 1.25rem; }
            .toast { max-width: 90%; bottom: 1rem; right: 1rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 6px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 3px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-500); }
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
            <a href="roles.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">shield</span>
                <span class="nav-text">Roles</span>
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
        <header class="top-header">
            <div class="top-header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                    <span class="material-symbols-outlined">chevron_left</span>
                </button>
                <span class="separator">/</span>
                <span style="font-weight:600; font-size:0.875rem;">Face Enrollment</span>
            </div>
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo $userProfile['initials']; ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Admin</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <a href="profile.php" class="dropdown-item">
                        <span class="material-symbols-outlined">person</span> My Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <span class="material-symbols-outlined">settings</span> Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../../logout.php" class="dropdown-item danger">
                        <span class="material-symbols-outlined">logout</span> Logout
                    </a>
                </div>
            </div>
        </header>

        <main class="main-scroll">
            <div class="container">
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">face</span>
                        <span>Face Enrollment</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $enrollUserId > 0 && $user ? htmlspecialchars($userName) : 'Select a user'; ?>
                        </span>
                    </div>
                    <a href="biometric_settings.php" class="btn btn-sm btn-outline" style="padding:0.375rem 0.75rem; font-size:0.75rem; border-radius:0.5rem; text-decoration:none;">
                        <span class="material-symbols-outlined" style="font-size:1rem;">arrow_back</span>
                        Back to Settings
                    </a>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Face Enrollment</h1>
                        <p>Register a user's face for facial recognition authentication</p>
                    </div>
                </div>

                <?php if (!$enrollUserId || !$user): ?>
                    <!-- No user selected -->
                    <div class="enrollment-card">
                        <div class="card-body" style="text-align:center; padding:3rem 1.5rem;">
                            <span class="material-symbols-outlined" style="font-size:4rem; color:var(--slate-300); display:block; margin-bottom:0.75rem;">face</span>
                            <h3 style="font-size:1.125rem; font-weight:700; margin-bottom:0.25rem;">No User Selected</h3>
                            <p style="color:var(--text-on-surface-variant); font-size:0.875rem;">Please go to Biometric Settings and click "Enroll" on a user.</p>
                            <a href="biometric_settings.php" class="btn btn-primary" style="margin-top:1rem; display:inline-flex;">
                                <span class="material-symbols-outlined">arrow_back</span>
                                Go to Biometric Settings
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Enrollment UI -->
                    <div class="enrollment-container">
                        <!-- Left: User Info & Steps -->
                        <div>
                            <div class="enrollment-card">
                                <div class="card-header">
                                    <h3>
                                        <span class="material-symbols-outlined">person</span>
                                        User Information
                                    </h3>
                                    <?php if ($hasFace): ?>
                                        <span class="status-badge enrolled">✅ Enrolled</span>
                                    <?php else: ?>
                                        <span class="status-badge not-enrolled">⚠️ Not Enrolled</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="user-info-card">
                                        <div class="user-avatar">
                                            <?php echo strtoupper(substr($user['first_name'], 0, 1) ?: 'U'); ?>
                                        </div>
                                        <div class="user-details">
                                            <div class="name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                            <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                                            <span class="role <?php echo $user['role']; ?>"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></span>
                                        </div>
                                    </div>

                                    <!-- Enrollment Steps -->
                                    <div class="enroll-steps">
                                        <div class="step" id="step1">
                                            <span class="step-num">1</span>
                                            <span class="step-text">Position your face in the circle</span>
                                            <span class="step-status pending" id="step1Status">Pending</span>
                                        </div>
                                        <div class="step" id="step2">
                                            <span class="step-num">2</span>
                                            <span class="step-text">Scan and capture face data</span>
                                            <span class="step-status pending" id="step2Status">Pending</span>
                                        </div>
                                        <div class="step" id="step3">
                                            <span class="step-num">3</span>
                                            <span class="step-text">Save enrollment to database</span>
                                            <span class="step-status pending" id="step3Status">Pending</span>
                                        </div>
                                    </div>

                                    <?php if ($hasFace): ?>
                                        <div style="margin-top:1rem; padding:1rem; background:#fef3c7; border-radius:var(--radius); text-align:center;">
                                            <span class="material-symbols-outlined" style="color:#d97706;">info</span>
                                            <span style="font-size:0.875rem; color:#92400e;">This user already has a face enrolled. Enrolling again will overwrite the existing face data.</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Camera & Controls -->
                        <div>
                            <div class="enrollment-card">
                                <div class="card-header">
                                    <h3>
                                        <span class="material-symbols-outlined">camera</span>
                                        Face Scanner
                                    </h3>
                                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);" id="cameraStatus">Initializing...</span>
                                </div>
                                <div class="card-body">
                                    <div class="face-video-wrapper" id="faceWrapper">
                                        <video id="video" autoplay muted playsinline></video>
                                        <canvas id="canvas"></canvas>
                                        <div class="face-overlay">
                                            <div class="face-guide">
                                                <span class="guide-dot tl"></span>
                                                <span class="guide-dot tr"></span>
                                                <span class="guide-dot bl"></span>
                                                <span class="guide-dot br"></span>
                                            </div>
                                            <div class="face-status" id="faceStatus">Position your face in the circle</div>
                                        </div>
                                    </div>

                                    <div class="face-status-indicator">
                                        <span class="status-dot loading" id="statusDot"></span>
                                        <span class="status-text" id="statusText">Loading face models...</span>
                                    </div>

                                    <div class="face-controls">
                                        <button class="btn btn-primary" id="enrollBtn" onclick="enrollFace()" disabled>
                                            <span class="material-symbols-outlined">add_photo_alternate</span>
                                            <?php echo $hasFace ? 'Update Face' : 'Enroll Face'; ?>
                                        </button>
                                        <button class="btn btn-outline" onclick="stopCamera()">
                                            <span class="material-symbols-outlined">stop</span>
                                            Stop
                                        </button>
                                        <button class="btn btn-outline" onclick="resetCamera()">
                                            <span class="material-symbols-outlined">refresh</span>
                                            Reset
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- =============================================
    FACE API & SCRIPTS
    ============================================= -->
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

    <script>
        // =============================================
        // STATE
        // =============================================
        let video = null;
        let canvas = null;
        let isInitialized = false;
        let isScanning = false;
        let isEnrolled = false;
        let currentStream = null;
        let faceDetections = [];
        let currentDescriptor = null;

        <?php if ($enrollUserId && $user): ?>
        const ENROLL_USER_ID = <?php echo $enrollUserId; ?>;
        const ENROLL_USER_NAME = '<?php echo htmlspecialchars($userName); ?>';
        const HAS_FACE = <?php echo $hasFace ? 'true' : 'false'; ?>;
        <?php else: ?>
        const ENROLL_USER_ID = 0;
        const ENROLL_USER_NAME = '';
        const HAS_FACE = false;
        <?php endif; ?>

        // =============================================
        // DEBUG / STATUS
        // =============================================
        function updateStatus(text, type = 'loading') {
            const dot = document.getElementById('statusDot');
            const textEl = document.getElementById('statusText');
            const statusEl = document.getElementById('cameraStatus');
            
            if (dot) dot.className = 'status-dot ' + type;
            if (textEl) textEl.textContent = text;
            if (statusEl) statusEl.textContent = text;
            
            console.log('[FaceEnroll] ' + text);
        }

        function updateStep(step, status, message = '') {
            const stepEl = document.getElementById('step' + step);
            const statusEl = document.getElementById('step' + step + 'Status');
            
            if (!stepEl) return;
            
            stepEl.className = 'step ' + status;
            if (statusEl) {
                const labels = {
                    'pending': 'Pending',
                    'active': 'In progress...',
                    'completed': '✅ Done',
                    'failed': '❌ Failed'
                };
                statusEl.textContent = labels[status] || status;
                statusEl.className = 'step-status ' + status;
            }
        }

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
        // CAMERA
        // =============================================
        async function startCamera() {
            try {
                updateStatus('Starting camera...', 'loading');
                
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' },
                    audio: false
                });

                currentStream = stream;
                video.srcObject = stream;
                await video.play();

                const wrapper = document.getElementById('faceWrapper');
                if (wrapper) wrapper.classList.add('active');

                updateStatus('Camera ready', 'idle');
                return true;
            } catch (error) {
                console.error('Camera error:', error);
                if (error.name === 'NotAllowedError') {
                    showToast('Camera access denied. Please allow camera permissions.', 'error');
                } else if (error.name === 'NotFoundError') {
                    showToast('No camera found. Please connect a camera.', 'error');
                } else {
                    showToast('Could not access camera: ' + error.message, 'error');
                }
                updateStatus('Camera error', 'failed');
                return false;
            }
        }

        function stopCamera() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            if (video) video.srcObject = null;
            
            const ctx = canvas?.getContext('2d');
            if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            const wrapper = document.getElementById('faceWrapper');
            if (wrapper) wrapper.classList.remove('active', 'scanning', 'success', 'failed');
            
            updateStatus('Camera stopped', 'idle');
        }

        function resetCamera() {
            stopCamera();
            setTimeout(() => {
                initFaceAuth();
            }, 500);
        }

        // =============================================
        // FACE DETECTION
        // =============================================
        async function detectFace() {
            if (!video || video.paused || !isInitialized) return null;

            const options = new faceapi.TinyFaceDetectorOptions({
                inputSize: 224,
                scoreThreshold: 0.5
            });

            try {
                const detections = await faceapi.detectAllFaces(video, options)
                    .withFaceLandmarks()
                    .withFaceExpressions()
                    .withFaceDescriptors();

                if (detections && detections.length > 0) {
                    const sorted = detections.sort((a, b) => {
                        const aArea = a.detection.box.width * a.detection.box.height;
                        const bArea = b.detection.box.width * b.detection.box.height;
                        return bArea - aArea;
                    });
                    return sorted[0];
                }
            } catch (error) {
                // Silent fail
            }
            return null;
        }

        function drawDetection(detection) {
            const ctx = canvas?.getContext('2d');
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!detection) return;

            const dims = faceapi.matchDimensions(canvas, video, true);
            const resized = faceapi.resizeResults(detection, dims);

            faceapi.draw.drawDetections(canvas, resized);
            faceapi.draw.drawFaceLandmarks(canvas, resized);

            if (resized.expressions) {
                const exp = resized.expressions;
                const top = Object.keys(exp).reduce((a, b) => exp[a] > exp[b] ? a : b);
                ctx.fillStyle = 'rgba(79, 70, 229, 0.8)';
                ctx.font = 'bold 14px Inter, sans-serif';
                ctx.fillText(`${top}: ${Math.round(exp[top] * 100)}%`, resized.detection.box.x, resized.detection.box.y - 10);
            }
        }

        // =============================================
        // SCAN FACE
        // =============================================
        async function scanFace() {
            if (isScanning) return null;
            isScanning = true;

            const wrapper = document.getElementById('faceWrapper');
            if (wrapper) wrapper.classList.add('scanning');

            updateStatus('Scanning face...', 'loading');
            document.getElementById('faceStatus').textContent = 'Scanning...';
            document.getElementById('faceStatus').className = 'face-status show scanning';

            updateStep(1, 'active');
            updateStep(2, 'pending');

            try {
                let detection = null;
                let attempts = 0;
                const maxAttempts = 15;

                while (attempts < maxAttempts) {
                    await new Promise(r => setTimeout(r, 150));
                    detection = await detectFace();
                    if (detection) break;
                    attempts++;
                    if (attempts % 3 === 0) {
                        updateStatus(`Looking for face... (${attempts}/${maxAttempts})`, 'loading');
                    }
                }

                if (!detection) {
                    updateStatus('No face detected', 'failed');
                    document.getElementById('faceStatus').textContent = '❌ No face detected';
                    document.getElementById('faceStatus').className = 'face-status show failed';
                    updateStep(1, 'failed');
                    return null;
                }

                updateStep(1, 'completed');
                updateStep(2, 'active');

                // Check liveness (basic)
                const livenessScore = calculateLivenessScore(detection);
                const isLive = livenessScore > 0.6;

                if (!isLive) {
                    updateStatus('⚠️ Spoof detected!', 'failed');
                    document.getElementById('faceStatus').textContent = '⚠️ Spoof detected';
                    document.getElementById('faceStatus').className = 'face-status show failed';
                    updateStep(2, 'failed');
                    showToast('Spoof detected! Please use your real face.', 'error');
                    return null;
                }

                const descriptor = detection.descriptor;
                currentDescriptor = descriptor;

                updateStatus('Face captured ✓', 'success');
                document.getElementById('faceStatus').textContent = '✅ Face captured';
                document.getElementById('faceStatus').className = 'face-status show success';
                updateStep(2, 'completed');

                // Take snapshot
                const snapshotCanvas = document.createElement('canvas');
                snapshotCanvas.width = video.videoWidth || 640;
                snapshotCanvas.height = video.videoHeight || 480;
                const ctx = snapshotCanvas.getContext('2d');
                ctx.drawImage(video, 0, 0, snapshotCanvas.width, snapshotCanvas.height);

                return {
                    descriptor: Array.from(descriptor),
                    snapshot: snapshotCanvas.toDataURL('image/jpeg', 0.9),
                    expressions: detection.expressions,
                    livenessScore: livenessScore
                };

            } catch (error) {
                console.error('Scan error:', error);
                updateStep(2, 'failed');
                return null;
            } finally {
                isScanning = false;
                if (wrapper) wrapper.classList.remove('scanning');
            }
        }

        function calculateLivenessScore(detection) {
            if (!detection || !detection.expressions) return 0;
            
            const exp = detection.expressions;
            let score = 0;
            
            const naturalExpressions = ['neutral', 'happy', 'surprised', 'sad'];
            const maxNatural = Math.max(...naturalExpressions.map(e => exp[e] || 0));
            
            score += maxNatural * 0.5;
            if (exp.happy > 0.3) score += 0.2;
            if (exp.surprised > 0.2) score += 0.2;
            if (exp.neutral > 0.3) score += 0.1;
            
            const expressionVariance = Object.keys(exp).reduce((sum, key) => {
                return sum + Math.abs(exp[key] - 0.2);
            }, 0);
            score += Math.min(expressionVariance / 2, 0.3);
            
            return Math.min(score, 1);
        }

        // =============================================
        // ENROLL FACE
        // =============================================
        async function enrollFace() {
            if (!isInitialized || isEnrolled || ENROLL_USER_ID === 0) {
                showToast('Please wait for camera to initialize.', 'error');
                return;
            }

            const btn = document.getElementById('enrollBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="ai-dots-loading-sm"><div class="dot"></div><div class="dot"></div><div class="dot"></div></span> Enrolling...';

            updateStep(1, 'pending');
            updateStep(2, 'pending');
            updateStep(3, 'pending');

            try {
                const scanResult = await scanFace();
                
                if (!scanResult) {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">add_photo_alternate</span> ' + (HAS_FACE ? 'Update Face' : 'Enroll Face');
                    return;
                }

                updateStep(3, 'active');
                updateStatus('Saving enrollment...', 'loading');

                // Send to server
                const enrollmentData = {
                    user_id: ENROLL_USER_ID,
                    user_name: ENROLL_USER_NAME,
                    descriptor: scanResult.descriptor,
                    expressions: scanResult.expressions,
                    snapshot: scanResult.snapshot,
                    liveness_score: scanResult.livenessScore,
                    enrolled_at: new Date().toISOString(),
                    provider: 'face-api.js'
                };

                const response = await fetch('/CT1/api/face/enroll.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(enrollmentData)
                });

                const result = await response.json();

                if (result.success) {
                    isEnrolled = true;
                    updateStep(3, 'completed');
                    updateStatus('✅ Face enrolled successfully!', 'success');
                    document.getElementById('faceStatus').textContent = '✅ Enrolled successfully!';
                    document.getElementById('faceStatus').className = 'face-status show success';
                    
                    const wrapper = document.getElementById('faceWrapper');
                    if (wrapper) wrapper.classList.add('success');
                    
                    showToast('✅ Face enrolled successfully for ' + ENROLL_USER_NAME + '!', 'success');
                    
                    setTimeout(() => {
                        window.location.href = 'biometric_settings.php';
                    }, 2000);
                } else {
                    updateStep(3, 'failed');
                    showToast(result.error || 'Enrollment failed.', 'error');
                    updateStatus('❌ Enrollment failed', 'failed');
                }

            } catch (error) {
                console.error('Enrollment error:', error);
                updateStep(3, 'failed');
                showToast('Enrollment failed. Please try again.', 'error');
                updateStatus('❌ Error: ' + error.message, 'failed');
            }

            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">add_photo_alternate</span> ' + (HAS_FACE ? 'Update Face' : 'Enroll Face');
        }

        // =============================================
        // INITIALIZE
        // =============================================
        async function initFaceAuth() {
            video = document.getElementById('video');
            canvas = document.getElementById('canvas');

            if (typeof faceapi === 'undefined') {
                showToast('Face API library not loaded. Please refresh.', 'error');
                updateStatus('face-api.js not loaded', 'failed');
                return;
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showToast('Your browser does not support camera access.', 'error');
                updateStatus('Camera not supported', 'failed');
                return;
            }

            // Check HTTPS
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                showToast('Camera requires HTTPS. Please use a secure connection.', 'error');
                updateStatus('HTTPS required', 'failed');
                return;
            }

            // Load models from CDN
            const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/';

            try {
                updateStatus('Loading face models from CDN...', 'loading');
                
                await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
                await faceapi.nets.faceExpressionNet.loadFromUri(MODEL_URL);

                updateStatus('Models loaded ✓', 'idle');

                const cameraStarted = await startCamera();
                if (!cameraStarted) return;

                const rect = video.getBoundingClientRect();
                canvas.width = rect.width || 640;
                canvas.height = rect.height || 480;

                isInitialized = true;
                document.getElementById('enrollBtn').disabled = false;
                updateStatus('Ready - Position face in circle', 'idle');

                // Start detection loop
                async function detectLoop() {
                    if (!isInitialized) return;
                    const detection = await detectFace();
                    drawDetection(detection);
                    requestAnimationFrame(detectLoop);
                }
                detectLoop();

                updateStep(1, 'pending');

            } catch (error) {
                console.error('Init error:', error);
                showToast('Failed to initialize: ' + error.message, 'error');
                updateStatus('Error: ' + error.message, 'failed');
            }
        }

        // =============================================
        // INIT ON LOAD
        // =============================================
        document.addEventListener('DOMContentLoaded', function() {
            if (ENROLL_USER_ID > 0) {
                initFaceAuth();
            } else {
                updateStatus('No user selected', 'idle');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                stopCamera();
                if (document.querySelector('.modal-overlay.active')) {
                    document.querySelector('.modal-overlay.active').classList.remove('active');
                }
            }
        });

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
    </script>
</body>
</html>