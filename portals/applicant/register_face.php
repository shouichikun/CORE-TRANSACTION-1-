<?php
// portals/applicant/register_face.php - Face Registration for Applicants
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();
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

// Check if face is already verified
$faceVerified = false;
$faceEnrollmentId = null;
if ($applicantId) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $faceCheck = getRecord("
        SELECT id FROM face_verification WHERE user_id = $1
    ", [$userId]);
    if ($faceCheck) {
        $faceVerified = true;
        $faceEnrollmentId = $faceCheck['id'];
    }
}

// Get redirect URL (where to go after registration)
$redirectUrl = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';

// =============================================
// GET COUNTS FOR SIDEBAR BADGES
// =============================================
$totalApplications = 0;
$interviewCount = 0;
$pendingOffers = 0;

if ($applicantId) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $appResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = $1
    ", [$applicantId]);
    $totalApplications = (int)($appResult['count'] ?? 0);
    
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $interviewResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = $1 AND interview_date IS NOT NULL
    ", [$applicantId]);
    $interviewCount = (int)($interviewResult['count'] ?? 0);
    
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $offersResult = getRecord("
        SELECT COUNT(*) as count FROM offers o
        JOIN applications a ON o.application_id = a.id
        WHERE a.applicant_id = $1 AND o.status = 'sent'
    ", [$applicantId]);
    $pendingOffers = (int)($offersResult['count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Face Registration - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           FACE REGISTRATION - MATERIAL 3 DESIGN
           ========================================================================== */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --bg-surface-container-low: #f5f6fa;
            --bg-surface-container-high: #eef0f5;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
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

        .header-logo {
            height: 2rem;
            width: auto;
            max-height: 2.5rem;
            object-fit: contain;
            border-radius: 0.375rem;
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
            box-shadow: var(--shadow-sm);
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

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) {
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
        }
        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }
        .page-header p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.125rem;
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
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--primary-container); }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-ai {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
        }
        .btn-ai:hover {
            background: linear-gradient(135deg, #6d28d9, #4338ca);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* =============================================
           FACE SCANNER SECTION
           ============================================= */
        .face-scan-container {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
        }

        .face-scan-header-section {
            padding: 1.5rem 2rem;
            background: var(--bg-surface-low);
            border-bottom: 3px solid var(--primary);
            text-align: center;
        }

        .face-scan-header-section .icon-big {
            font-size: 3rem;
            color: var(--primary);
            display: block;
            margin-bottom: 0.5rem;
        }

        .face-scan-header-section h2 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }

        .face-scan-header-section p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .face-scan-body {
            padding: 1.5rem 2rem;
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
            transform: scaleX(-1);
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

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.6; }
        }

        .face-scan-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .face-scan-actions .btn {
            flex: 1;
            justify-content: center;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            min-width: 120px;
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

        /* Already verified section */
        .already-verified {
            text-align: center;
            padding: 2rem;
        }

        .already-verified .icon-big {
            font-size: 4rem;
            color: var(--success-color);
            display: block;
            margin-bottom: 0.5rem;
        }

        .already-verified h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .already-verified p {
            font-size: 0.875rem;
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
            .face-scan-body { padding: 1rem; }
            .face-scan-actions { flex-direction: column; }
            .face-scan-actions .btn { width: 100%; }
            .face-scan-circle { width: 140px; height: 140px; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .face-scan-header-section { padding: 1rem; }
            .face-scan-header-section h2 { font-size: 1.25rem; }
            .face-scan-circle { width: 120px; height: 120px; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
        .sidebar-logo {
            width: 3.5rem;
            height: 3.5rem;
            object-fit: contain;
            border-radius: 0.75rem;
            display: block;
            margin: 0 auto;
        }

        /* For collapsed sidebar */
        .dashboard-sidebar.collapsed .sidebar-logo {
            width: 2.5rem;
            height: 2.5rem;
        }

        /* If using Option 2 - background image on icon */
        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background-size: contain !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            background-color: transparent !important;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

    <!-- Sidebar Backdrop (Mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =============================================
    SIDEBAR - FIXED POSITION
    ============================================= -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <img src="logo.png" alt="ISMERS" class="sidebar-logo">
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
        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
                <img src="logo.png" alt="ISMERS" class="header-logo">
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
                <button class="profile-dropdown-toggle" id="profileToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Applicant</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="profile-dropdown-menu" id="profileMenu">
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


        <main class="main-scroll">
            <div class="container">
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">scan</span>
                        <span>Face Registration</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $faceVerified ? 'Already Verified' : 'Required for Applying'; ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta"><?php echo date('M d, Y H:i'); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Face Registration</h1>
                        <p>Register your face for biometric verification</p>
                    </div>
                </div>

                <!-- Face Scanner Container -->
                <div class="face-scan-container">
                    <?php if ($faceVerified): ?>
                    <!-- Already Verified -->
                    <div class="already-verified">
                        <span class="icon-big material-symbols-outlined">verified</span>
                        <h3>Face Already Verified</h3>
                        <p>Your face has already been registered and verified in the system.</p>
                        <div style="margin-top:1.5rem; display:flex; gap:0.75rem; justify-content:center; flex-wrap:wrap;">
                            <a href="<?php echo htmlspecialchars($redirectUrl); ?>" class="btn btn-primary">
                                <span class="material-symbols-outlined">arrow_forward</span>
                                Continue
                            </a>
                            <button class="btn btn-outline" onclick="reverifyFace()">
                                <span class="material-symbols-outlined">refresh</span>
                                Re-verify Face
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Face Registration Form -->
                    <div class="face-scan-header-section">
                        <span class="icon-big material-symbols-outlined">scan</span>
                        <h2>Position Your Face for Verification</h2>
                        <p>Look directly at the camera and ensure your face is clearly visible</p>
                    </div>

                    <div class="face-scan-body">
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
                            <button type="button" class="btn btn-outline" onclick="window.location.href='<?php echo htmlspecialchars($redirectUrl); ?>'">
                                <span class="material-symbols-outlined">close</span>
                                Cancel
                            </button>
                            <button type="button" class="btn btn-primary" id="captureFaceBtn" onclick="registerFace()" disabled>
                                <span class="material-symbols-outlined">scan</span>
                                Register Face
                            </button>
                            <button type="button" class="btn btn-ai" id="retryBtn" onclick="retryCamera()" style="display:none;">
                                <span class="material-symbols-outlined">refresh</span>
                                Retry Camera
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Face-api.js -->
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

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', openMobileSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        }

        // =============================================
        // PROFILE DROPDOWN - FIXED ID
        // =============================================
        const profileToggle = document.getElementById('profileToggle');
        const profileMenu = document.getElementById('profileMenu');

        if (profileToggle && profileMenu) {
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
        }

        // =============================================
        // FACE REGISTRATION - COMPLETE
        // =============================================
        let faceScanVideo = null;
        let faceScanCanvas = null;
        let faceScanStream = null;
        let faceScanDetection = null;
        let faceScanInitialized = false;
        let faceScanTimer = null;
        let faceApiLoaded = false;
        let captureAttempts = 0;
        let isRegistering = false;

        const redirectUrl = '<?php echo htmlspecialchars($redirectUrl); ?>';

        async function initFaceScanner() {
            try {
                faceScanVideo = document.getElementById('faceScanVideo');
                faceScanCanvas = document.getElementById('faceScanCanvas');

                // Check if face-api.js is loaded
                if (typeof faceapi === 'undefined') {
                    updateStatus('Face API not loaded. Please refresh the page.', 'error');
                    document.getElementById('captureFaceBtn').disabled = true;
                    return;
                }

                // ✅ FIXED: Load face-api.js models from /public/js/ (no CT1)
                const modelPath = '/public/js';
                console.log('Loading models from:', modelPath);
                
                await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
                await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
                await faceapi.nets.faceExpressionNet.loadFromUri(modelPath);

                faceApiLoaded = true;
                console.log('✅ Face models loaded successfully');

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

                updateStatus('Camera ready - Position your face', 'idle');
                document.getElementById('captureFaceBtn').disabled = false;
                document.getElementById('retryBtn').style.display = 'none';
                faceScanInitialized = true;

                // Start detection loop
                detectFaceForScan();

            } catch (error) {
                console.error('Face scanner error:', error);
                if (error.message && error.message.includes('Permission')) {
                    updateStatus('Camera access denied. Please allow camera permissions.', 'error');
                } else if (error.message && error.message.includes('404')) {
                    updateStatus('Model files not found. Please check the model path.', 'error');
                } else {
                    updateStatus('Camera error: ' + error.message, 'error');
                }
                document.getElementById('captureFaceBtn').disabled = true;
                document.getElementById('retryBtn').style.display = 'inline-flex';
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
                    // Draw detection on canvas
                    const box = detection.detection.box;
                    const flippedX = faceScanCanvas.width - box.x - box.width;

                    ctx.strokeStyle = '#22c55e';
                    ctx.lineWidth = 3;
                    ctx.strokeRect(flippedX, box.y, box.width, box.height);

                    // Draw landmarks
                    const landmarks = detection.landmarks;
                    const positions = landmarks.positions;
                    ctx.fillStyle = '#22c55e';
                    ctx.strokeStyle = '#22c55e';
                    ctx.lineWidth = 2;

                    for (let i = 0; i < positions.length; i++) {
                        const flippedPosX = faceScanCanvas.width - positions[i].x;
                        ctx.beginPath();
                        ctx.arc(flippedPosX, positions[i].y, 2, 0, 2 * Math.PI);
                        ctx.fill();
                    }

                    updateStatus('Face detected - Ready to register', 'success');
                    document.getElementById('captureFaceBtn').disabled = false;
                    faceScanDetection = detection;

                } else {
                    updateStatus('Looking for face...', 'scanning');
                    document.getElementById('captureFaceBtn').disabled = true;
                    faceScanDetection = null;
                }

            } catch (error) {
                // Silent fail for loop
            }

            faceScanTimer = setTimeout(detectFaceForScan, 150);
        }

        function updateStatus(text, type = 'idle') {
            const dot = document.getElementById('faceScanStatusDot');
            const textEl = document.getElementById('faceScanStatusText');

            dot.className = 'status-dot ' + type;
            textEl.textContent = text;
        }

        function retryCamera() {
            stopFaceScanner();
            document.getElementById('retryBtn').style.display = 'none';
            updateStatus('Restarting camera...', 'idle');
            setTimeout(initFaceScanner, 500);
        }

        async function registerFace() {
            if (!faceScanDetection || !faceScanInitialized) {
                showToast('No face detected. Please position your face.', 'error');
                return;
            }

            if (isRegistering) return;
            isRegistering = true;

            const captureBtn = document.getElementById('captureFaceBtn');
            captureBtn.disabled = true;
            captureBtn.innerHTML = '<span class="loading-spinner"></span> Registering...';
            captureAttempts++;

            updateStatus('Processing face data... (Attempt ' + captureAttempts + ')', 'scanning');

            try {
                // Get face descriptor as array
                const descriptor = Array.from(faceScanDetection.descriptor);

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
                    snapshot: snapshot,
                    redirect: redirectUrl
                };

                console.log('Sending face data:', {
                    user_id: requestData.user_id,
                    descriptor_length: requestData.descriptor.length,
                    snapshot_length: requestData.snapshot ? requestData.snapshot.length : 0
                });

                // ✅ FIXED: Update API path to remove /CT1
                const apiUrl = '/api/biometric_verify.php';
                console.log('Calling API:', apiUrl);

                // Send to server for enrollment
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                });

                if (!response.ok) {
                    const text = await response.text();
                    console.error('Server error response:', text);
                    throw new Error('Server returned ' + response.status + ': ' + text);
                }

                const data = await response.json();
                console.log('Server response:', data);

                if (data.success) {
                    updateStatus('Face registered successfully!', 'success');
                    showToast('Face registration complete!', 'success');

                    // Redirect after success
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 1500);

                } else {
                    updateStatus('Registration failed: ' + (data.error || 'Unknown error'), 'error');
                    showToast('Registration failed: ' + (data.error || 'Please try again.'), 'error');
                    captureBtn.disabled = false;
                    captureBtn.innerHTML = '<span class="material-symbols-outlined">scan</span> Register Face';
                    isRegistering = false;
                }

            } catch (error) {
                console.error('Registration error:', error);
                updateStatus('Error: ' + error.message, 'error');
                showToast('Error registering face: ' + error.message, 'error');
                captureBtn.disabled = false;
                captureBtn.innerHTML = '<span class="material-symbols-outlined">scan</span> Register Face';
                isRegistering = false;
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

        function reverifyFace() {
            // This will reload the page and show the face scanner again
            window.location.href = window.location.href.split('?')[0] + '?reverify=1';
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
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
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
        // INITIALIZE FACE SCANNER
        // =============================================
        <?php if (!$faceVerified): ?>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initFaceScanner, 500);
        });
        <?php endif; ?>

        console.log('👤 Face Registration Page loaded successfully!');
        <?php if ($faceVerified): ?>
        console.log('✅ Face already verified');
        <?php else: ?>
        console.log('📷 Face scanner initialized');
        <?php endif; ?>
    </script>
    <!-- REMOVED: <script src="/CT1/session_guard.js"></script> -->
    <!-- Session monitoring is already built into the page above -->
</body>
</html>
