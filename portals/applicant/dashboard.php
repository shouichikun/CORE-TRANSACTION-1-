<?php
// portals/applicant/dashboard.php - Applicant Dashboard
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

// Get applicant interests
$interests = [];
if ($applicantId) {
    $interests = getApplicantInterests($applicantId);
}

// Get applications
$applications = [];
if ($applicantId) {
    $applications = getApplicationsByApplicant($applicantId);
}

// Get stats
$totalApplications = count($applications);
$pendingApplications = 0;
$shortlistedApplications = 0;
$interviewedApplications = 0;
$hiredApplications = 0;
$rejectedApplications = 0;

foreach ($applications as $app) {
    switch ($app['status']) {
        case 'pending':
            $pendingApplications++;
            break;
        case 'shortlisted':
            $shortlistedApplications++;
            break;
        case 'interviewed':
            $interviewedApplications++;
            break;
        case 'hired':
            $hiredApplications++;
            break;
        case 'rejected':
            $rejectedApplications++;
            break;
    }
}

// Get recent applications (last 5)
$recentApplications = array_slice($applications, 0, 5);

// Get application status counts for chart
$statusData = [
    ['label' => 'Pending', 'value' => $pendingApplications, 'color' => '#f59e0b'],
    ['label' => 'Shortlisted', 'value' => $shortlistedApplications, 'color' => '#3b82f6'],
    ['label' => 'Interviewed', 'value' => $interviewedApplications, 'color' => '#8b5cf6'],
    ['label' => 'Hired', 'value' => $hiredApplications, 'color' => '#22c55e'],
    ['label' => 'Rejected', 'value' => $rejectedApplications, 'color' => '#ef4444']
];

// Filter out zero values for chart
$chartData = array_filter($statusData, function($item) {
    return $item['value'] > 0;
});

// If no data, add placeholder
if (empty($chartData)) {
    $chartData = [
        ['label' => 'No Applications', 'value' => 1, 'color' => '#e5e7eb']
    ];
}

// Activity data (recent applications as activity)
$activities = [];
foreach ($recentApplications as $app) {
    $statusLabels = [
        'pending' => 'Pending Review',
        'shortlisted' => 'Shortlisted',
        'interviewed' => 'Interviewed',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn'
    ];
    $statusColors = [
        'pending' => 'warning',
        'shortlisted' => 'info',
        'interviewed' => 'accent',
        'hired' => 'success',
        'rejected' => 'neutral',
        'withdrawn' => 'neutral'
    ];
    
    $activities[] = [
        'label' => 'Applied for: ' . ($app['title'] ?? 'Position'),
        'company' => $app['company_name'] ?? 'Company',
        'status' => $statusLabels[$app['status']] ?? ucfirst($app['status']),
        'status_color' => $statusColors[$app['status']] ?? 'neutral',
        'time' => date('M d, Y', strtotime($app['applied_at'] ?? 'now'))
    ];
}

// Quick actions for applicant
$quickActions = [
    ['icon' => 'search', 'label' => 'Browse Jobs', 'link' => 'job_search.php'],
    ['icon' => 'edit_note', 'label' => 'Update Profile', 'link' => 'edit_profile.php'],
    ['icon' => 'description', 'label' => 'View Applications', 'link' => 'applications.php'],
    ['icon' => 'settings', 'label' => 'Account Settings', 'link' => 'settings.php']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Dashboard - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - APPLICANT DASHBOARD
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
                   SIDEBAR - FIXED POSITION
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

        /* Desktop: collapsed state */
        .dashboard-sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        /* Mobile: hidden by default, shown with .open */
        .dashboard-sidebar.mobile-hidden {
            transform: translateX(-100%);
        }

        .dashboard-sidebar.mobile-open {
            transform: translateX(0);
        }

        /* =============================================
                   SIDEBAR CONTENT HIDE/SHOW ON COLLAPSE
                ============================================= */
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

        /* =============================================
                   SIDEBAR COMPONENTS
                ============================================= */
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

        .sidebar-brand-title {
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

        .sidebar-toggle-btn .menu-icon {
            transition: transform 0.3s ease;
        }

        /* Mobile menu toggle */
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

        /* Profile Dropdown */
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
                   WELCOME SECTION
                ============================================= */
        .welcome-section {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .welcome-section {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .welcome-section h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }

        .welcome-section p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .welcome-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
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

        .btn-secondary {
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

        .btn-secondary:hover {
            background: var(--primary);
            color: var(--on-primary);
        }

        .btn-secondary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-primary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        /* =============================================
                   STATS GRID
                ============================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition-fast);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--slate-500);
        }

        .stat-card .stat-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card .stat-icon.blue {
            background: #eff6ff;
            color: #2563eb;
        }

        .stat-card .stat-icon.yellow {
            background: #fef3c7;
            color: #d97706;
        }

        .stat-card .stat-icon.purple {
            background: #ede9fe;
            color: #7c3aed;
        }

        .stat-card .stat-icon.green {
            background: #d1fae5;
            color: #059669;
        }

        .stat-card .stat-icon.red {
            background: #fecaca;
            color: #dc2626;
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--slate-900);
            line-height: 1;
        }

        .stat-card .stat-footer {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
            font-size: 0.75rem;
        }

        .stat-card .stat-footer .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
        }

        .stat-card .stat-footer .stat-change.positive {
            color: #15803d;
            background: #d1fae5;
        }

        .stat-card .stat-footer .stat-change.negative {
            color: #b91c1c;
            background: #fecaca;
        }

        /* =============================================
                   CHARTS SECTION
                ============================================= */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1.8fr 1fr;
            }
        }

        .chart-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
        }

        .chart-card .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-card .chart-title {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--slate-500);
        }

        .chart-card .chart-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .chart-card .chart-subtitle {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* Simple Bar Chart */
        .bar-chart {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            height: 150px;
            padding-top: 1rem;
            gap: 0.5rem;
        }

        .bar-chart .bar-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
        }

        .bar-chart .bar-item .bar {
            width: 100%;
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            transition: height 0.6s ease;
            max-height: 130px;
        }

        .bar-chart .bar-item .bar-label {
            font-size: 0.65rem;
            color: var(--text-on-surface-variant);
            text-align: center;
            font-weight: 500;
        }

        /* Donut Chart (CSS) */
        .donut-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }

        @media (min-width: 640px) {
            .donut-wrapper {
                flex-direction: row;
                justify-content: center;
            }
        }

        .donut-container {
            position: relative;
            width: 180px;
            height: 180px;
            flex-shrink: 0;
        }

        .donut-container .donut-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .donut-container .donut-center .donut-total {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .donut-container .donut-center .donut-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .donut-container svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }

        .donut-container svg circle {
            fill: none;
            stroke-width: 32;
            stroke-linecap: round;
        }

        .donut-container svg .donut-bg {
            stroke: var(--slate-100);
        }

        .donut-legend {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
        }

        .donut-legend .legend-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            background: var(--bg-surface-low);
            border: 1px solid rgba(199, 196, 216, 0.16);
        }

        .donut-legend .legend-item .legend-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .donut-legend .legend-item .legend-color {
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 0.375rem;
        }

        .donut-legend .legend-item .legend-label {
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }

        .donut-legend .legend-item .legend-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        /* =============================================
                   ACTIVITY TABLE
                ============================================= */
        .activity-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .activity-card .activity-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(199, 196, 216, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .activity-card .activity-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .activity-card .activity-header a {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: color var(--transition-fast);
        }

        .activity-card .activity-header a:hover {
            color: var(--on-primary-fixed-variant);
        }

        .activity-card .activity-header a .material-symbols-outlined {
            font-size: 1rem;
        }

        .activity-card table {
            width: 100%;
            border-collapse: collapse;
        }

        .activity-card table thead {
            background: rgba(245, 243, 255, 0.5);
        }

        .activity-card table th {
            padding: 0.75rem 1.5rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 1px solid rgba(199, 196, 216, 0.3);
        }

        .activity-card table td {
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            color: var(--text-on-surface);
            border-bottom: 1px solid rgba(199, 196, 216, 0.15);
        }

        .activity-card table tbody tr:hover {
            background: var(--bg-surface-low);
        }

        .status-pill {
            display: inline-flex;
            padding: 0.125rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pill-success {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pill-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-pill-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pill-accent {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-pill-neutral {
            background: #f1f5f9;
            color: #475569;
        }

        /* =============================================
                   QUICK ACTIONS
                ============================================= */
        .quick-actions-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
        }

        .quick-actions-card h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 1rem;
        }

        .quick-actions-card .quick-action-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: var(--bg-surface);
            border: 1px solid rgba(199, 196, 216, 0.16);
            transition: all var(--transition-fast);
            cursor: pointer;
            width: 100%;
            text-align: left;
        }

        .quick-actions-card .quick-action-item:hover {
            background: var(--bg-surface-low);
            border-color: var(--primary);
            transform: translateX(4px);
        }

        .quick-actions-card .quick-action-item .qa-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .quick-actions-card .quick-action-item .qa-left .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--primary);
        }

        .quick-actions-card .quick-action-item .qa-left span:last-child {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-on-surface);
        }

        .quick-actions-card .quick-action-item .material-symbols-outlined:last-child {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
                   FOOTER
                ============================================= */
        .dashboard-footer {
            padding: 2rem 0;
            border-top: 1px solid rgba(199, 196, 216, 0.3);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (min-width: 768px) {
            .dashboard-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }

        .dashboard-footer .footer-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .dashboard-footer .footer-brand span {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .dashboard-footer .footer-brand small {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .dashboard-footer .footer-links {
            display: flex;
            gap: 1.5rem;
        }

        .dashboard-footer .footer-links a {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            transition: color var(--transition-fast);
        }

        .dashboard-footer .footer-links a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        /* =============================================
                   BACKDROP
                ============================================= */
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

            .top-header-left .logo-text {
                display: none;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: none;
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
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .breadcrumb-bar {
                padding: 0.75rem 1rem;
            }

            .main-scroll {
                padding: 0.75rem;
            }

            .activity-card table {
                font-size: 0.75rem;
            }

            .activity-card table th,
            .activity-card table td {
                padding: 0.5rem 0.75rem;
            }

            .donut-container {
                width: 140px;
                height: 140px;
            }

            .welcome-section h1 {
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

/* Profile Dropdown Menu */
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

.profile-dropdown-menu .dropdown-header {
    padding: 0.5rem 0.875rem 0.25rem;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-on-surface-variant);
}

@media (max-width: 767px) {
    .profile-dropdown-toggle .profile-name,
    .profile-dropdown-toggle .profile-role {
        display: none;
    }
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
        <div class="px-5 pt-6 pb-5 border-b border-slate-200">
            <div class="sidebar-brand-card">
                <span class="sidebar-brand-icon">
                    <span class="material-symbols-outlined">account_balance</span>
                </span>
                <p class="sidebar-brand-text">ISMERS</p>
                <p class="sidebar-brand-category">Applicant Portal</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="#" class="sidebar-main-link active">
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
        <span class="profile-role">Applicant</span>
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
                        <span class="material-symbols-outlined">dashboard</span>
                        <span>Dashboard</span>
                        <span class="status-dot"></span>
                    </div>
                </div>

                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div>
                        <h1>Welcome back, <?php echo htmlspecialchars($firstName ?: 'Applicant'); ?></h1>
                        <p>Here's what's happening with your job applications</p>
                    </div>
                    <div class="welcome-actions">
                        <a href="job_search.php" class="btn-primary">
                            <span class="material-symbols-outlined">search</span>
                            Browse Jobs
                        </a>
                        <a href="edit_profile.php" class="btn-secondary">
                            <span class="material-symbols-outlined">edit</span>
                            Update Profile
                        </a>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid" id="statsGrid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Total Applications</span>
                            <div class="stat-icon blue">
                                <span class="material-symbols-outlined">description</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $totalApplications; ?></div>
                        <div class="stat-footer">
                            <span>All applications submitted</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Pending Review</span>
                            <div class="stat-icon yellow">
                                <span class="material-symbols-outlined">hourglass_empty</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $pendingApplications; ?></div>
                        <div class="stat-footer">
                            <span class="stat-change <?php echo $pendingApplications > 0 ? 'positive' : 'neutral'; ?>">
                                <?php echo $pendingApplications > 0 ? 'Awaiting review' : 'No pending'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Shortlisted</span>
                            <div class="stat-icon purple">
                                <span class="material-symbols-outlined">star</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $shortlistedApplications; ?></div>
                        <div class="stat-footer">
                            <span class="stat-change <?php echo $shortlistedApplications > 0 ? 'positive' : 'neutral'; ?>">
                                <?php echo $shortlistedApplications > 0 ? 'Moving forward' : 'None yet'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-label">Hired</span>
                            <div class="stat-icon green">
                                <span class="material-symbols-outlined">check_circle</span>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo $hiredApplications; ?></div>
                        <div class="stat-footer">
                            <span class="stat-change <?php echo $hiredApplications > 0 ? 'positive' : 'neutral'; ?>">
                                <?php echo $hiredApplications > 0 ? 'Congratulations!' : 'Not yet'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid" id="chartsGrid">
                    <!-- Bar Chart - Application Status -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <div class="chart-title">Application Status</div>
                                <div class="chart-value"><?php echo $totalApplications; ?> Total</div>
                                <div class="chart-subtitle">Overview of your applications</div>
                            </div>
                        </div>
                        <div class="bar-chart" id="barChart">
                            <?php
                            $statusColors = [
                                'pending' => '#f59e0b',
                                'shortlisted' => '#3b82f6',
                                'interviewed' => '#8b5cf6',
                                'hired' => '#22c55e',
                                'rejected' => '#ef4444'
                            ];
                            $statusLabels = [
                                'pending' => 'Pending',
                                'shortlisted' => 'Shortlisted',
                                'interviewed' => 'Interviewed',
                                'hired' => 'Hired',
                                'rejected' => 'Rejected'
                            ];
                            $maxValue = max(array_column($statusData, 'value')) ?: 1;
                            foreach ($statusData as $item):
                                $height = max(8, ($item['value'] / $maxValue) * 120);
                            ?>
                            <div class="bar-item">
                                <div class="bar" style="height: <?php echo $height; ?>px; background: <?php echo $item['color']; ?>;"></div>
                                <span class="bar-label"><?php echo $item['value']; ?></span>
                                <span class="bar-label" style="font-size:0.6rem; opacity:0.6;"><?php echo $item['label']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Donut Chart -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <div class="chart-title">Application Distribution</div>
                                <div class="chart-value"><?php echo $totalApplications; ?> Total</div>
                            </div>
                        </div>
                        <div class="donut-wrapper">
                            <div class="donut-container">
                                <svg viewBox="0 0 100 100">
                                    <circle class="donut-bg" cx="50" cy="50" r="34"/>
                                    <?php
                                    $total = array_sum(array_column($chartData, 'value'));
                                    $currentAngle = 0;
                                    foreach ($chartData as $item):
                                        $percentage = ($item['value'] / $total) * 100;
                                        $circumference = 2 * pi() * 34;
                                        $dashArray = ($percentage / 100) * $circumference;
                                        $dashOffset = $circumference - $dashArray;
                                    ?>
                                    <circle cx="50" cy="50" r="34"
                                            stroke="<?php echo $item['color']; ?>"
                                            stroke-dasharray="<?php echo $dashArray; ?> <?php echo $circumference - $dashArray; ?>"
                                            stroke-dashoffset="<?php echo $dashOffset; ?>"
                                            style="transition: stroke-dashoffset 0.8s ease;"/>
                                    <?php endforeach; ?>
                                </svg>
                                <div class="donut-center">
                                    <span class="donut-total">Total</span>
                                    <span class="donut-value"><?php echo $totalApplications; ?></span>
                                </div>
                            </div>
                            <div class="donut-legend">
                                <?php foreach ($chartData as $item): ?>
                                <div class="legend-item">
                                    <div class="legend-left">
                                        <span class="legend-color" style="background: <?php echo $item['color']; ?>;"></span>
                                        <span class="legend-label"><?php echo $item['label']; ?></span>
                                    </div>
                                    <span class="legend-value"><?php echo $item['value']; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Table -->
                <div class="activity-card">
                    <div class="activity-header">
                        <h2>Recent Activity</h2>
                        <a href="applications.php">
                            View All <span class="material-symbols-outlined">arrow_forward</span>
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if (empty($activities)): ?>
                        <div style="text-align:center; padding:2rem 1.5rem; color:var(--text-on-surface-variant);">
                            <span class="material-symbols-outlined" style="font-size:2rem; opacity:0.3;">inbox</span>
                            <p style="margin-top:0.5rem;">No recent activity. Start applying to jobs!</p>
                            <br>
                            <a href="job_search.php" class="btn-primary" style="display:inline-flex;">Browse Jobs</a>
                        </div>
                        <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Activity</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                    <th style="text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <div style="font-weight:500; color:var(--text-on-surface);"><?php echo htmlspecialchars($activity['label']); ?></div>
                                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);"><?php echo htmlspecialchars($activity['company']); ?></div>
                                        </div>
                                    </td>
                                    <td><span class="status-pill status-pill-<?php echo $activity['status_color']; ?>"><?php echo $activity['status']; ?></span></td>
                                    <td style="color:var(--text-on-surface-variant);"><?php echo $activity['time']; ?></td>
                                    <td style="text-align:center;">
                                        <a href="applications.php" style="color:var(--text-on-surface-variant); transition:color var(--transition-fast);" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color=''">
                                            <span class="material-symbols-outlined" style="font-size:1.125rem;">visibility</span>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions-card">
                    <h2>Quick Actions</h2>
                    <div class="space-y-3">
                        <?php foreach ($quickActions as $action): ?>
                        <a href="<?php echo $action['link']; ?>" class="quick-action-item">
                            <div class="qa-left">
                                <span class="material-symbols-outlined"><?php echo $action['icon']; ?></span>
                                <span><?php echo $action['label']; ?></span>
                            </div>
                            <span class="material-symbols-outlined">arrow_forward</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Footer -->
                <footer class="dashboard-footer">
                    <div class="footer-brand">
                        <span>ISMERS</span>
                        <small>© <?php echo date('Y'); ?> All rights reserved.</small>
                    </div>
                    <div class="footer-links">
                        <a href="#">Privacy Policy</a>
                        <a href="#">Terms of Service</a>
                        <a href="#">Support</a>
                    </div>
                </footer>

            </div>
        </main>
    </div>

    <!-- =============================================
    JAVASCRIPT (Internal)
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

            // Load saved state from localStorage
            const savedState = localStorage.getItem('sidebarCollapsed');
            const isDesktop = window.innerWidth >= 768;

            // Apply saved state on desktop
            if (savedState === 'true' && isDesktop) {
                sidebar.classList.add('collapsed');
                sidebarToggleIcon.textContent = 'menu';
            }

            // Desktop: Toggle collapse/expand
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

            // Close sidebar when clicking a link on mobile
            document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                });
            });

            // =============================================
            // 3. RESPONSIVE HANDLING
            // =============================================
            let resizeTimer;

            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    const width = window.innerWidth;
                    
                    if (width >= 768) {
                        // Desktop
                        closeMobileSidebar();
                        sidebar.classList.remove('mobile-open', 'mobile-hidden');
                        
                        // Restore collapsed state from localStorage
                        const saved = localStorage.getItem('sidebarCollapsed');
                        if (saved === 'true') {
                            sidebar.classList.add('collapsed');
                            sidebarToggleIcon.textContent = 'menu';
                        } else {
                            sidebar.classList.remove('collapsed');
                            sidebarToggleIcon.textContent = 'menu_open';
                        }
                    } else {
                        // Mobile
                        sidebar.classList.add('mobile-hidden');
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'menu_open';
                    }
                }, 250);
            });

            // =============================================
            // 4. KEYBOARD ACCESSIBILITY (ESC closes sidebar)
            // =============================================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                }
            });

            // =============================================
            // 5. BAR CHART ANIMATION ON LOAD
            // =============================================
            document.addEventListener('DOMContentLoaded', function() {
                const bars = document.querySelectorAll('.bar-chart .bar');
                bars.forEach(function(bar, index) {
                    const height = bar.style.height;
                    bar.style.height = '0px';
                    setTimeout(function() {
                        bar.style.height = height;
                        bar.style.transition = 'height 0.6s cubic-bezier(0.16, 1, 0.3, 1)';
                    }, 100 + (index * 100));
                });

                // Animate donut chart
                const circles = document.querySelectorAll('.donut-container svg circle:not(.donut-bg)');
                circles.forEach(function(circle, index) {
                    const dashOffset = circle.getAttribute('stroke-dashoffset');
                    circle.style.strokeDashoffset = '100%';
                    setTimeout(function() {
                        circle.style.strokeDashoffset = dashOffset;
                        circle.style.transition = 'stroke-dashoffset 0.8s cubic-bezier(0.16, 1, 0.3, 1)';
                    }, 200 + (index * 150));
                });
            });

            // =============================================
            // 6. INITIAL STATE
            // =============================================
            // On mobile, hide sidebar by default
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            console.log('ISMERS Applicant Dashboard loaded successfully.');
        })();
        // =============================================
// PROFILE DROPDOWN TOGGLE
// =============================================
const profileToggle = document.getElementById('profileDropdownToggle');
const profileMenu = document.getElementById('profileDropdownMenu');

profileToggle.addEventListener('click', function(e) {
    e.stopPropagation();
    this.classList.toggle('open');
    profileMenu.classList.toggle('open');
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!profileToggle.contains(e.target) && !profileMenu.contains(e.target)) {
        profileToggle.classList.remove('open');
        profileMenu.classList.remove('open');
    }
});

// Close dropdown on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        profileToggle.classList.remove('open');
        profileMenu.classList.remove('open');
    }
});
    </script>

</body>
</html>