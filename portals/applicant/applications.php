<?php
// portals/applicant/applications.php - Applicant Applications
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
$allApplications = [];
if ($applicantId) {
    $allApplications = getApplicationsByApplicant($applicantId);
}
$totalApplications = count($allApplications);

// Filter applications by status
$statusFilter = $_GET['status'] ?? 'all';
$filteredApplications = $allApplications;

if ($statusFilter !== 'all') {
    $filteredApplications = array_filter($allApplications, function($app) use ($statusFilter) {
        return $app['status'] === $statusFilter;
    });
}

// Get counts for each status
$statusCounts = [
    'all' => count($allApplications),
    'pending' => 0,
    'shortlisted' => 0,
    'interviewed' => 0,
    'hired' => 0,
    'rejected' => 0,
    'withdrawn' => 0
];

foreach ($allApplications as $app) {
    $status = $app['status'] ?? 'pending';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// Status badge mapping
$statusBadges = [
    'pending' => 'badge-pending',
    'shortlisted' => 'badge-shortlisted',
    'interviewed' => 'badge-interviewed',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected',
    'withdrawn' => 'badge-withdrawn'
];

$statusLabels = [
    'pending' => 'Pending Review',
    'shortlisted' => 'Shortlisted',
    'interviewed' => 'Interviewed',
    'hired' => 'Hired',
    'rejected' => 'Rejected',
    'withdrawn' => 'Withdrawn'
];

// Get application details for modal
$applicationDetails = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $appId = (int)$_GET['view'];
   $applicationDetails = getRecord("
    SELECT a.id, a.cover_letter, a.status, a.applied_at, a.updated_at, a.resume_path,
           jo.title as job_title, jo.description as job_description, 
           jo.location, jo.job_type, jo.salary_range, c.company_name,
           u.first_name, u.last_name, u.email
    FROM applications a
    JOIN job_orders jo ON a.job_order_id = jo.id
    JOIN clients c ON jo.client_id = c.id
    JOIN applicants ap ON a.applicant_id = ap.id
    JOIN users u ON ap.user_id = u.id
    WHERE a.id = ? AND a.applicant_id = ?
", [$appId, $applicantId], "ii");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Applications - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - APPLICATIONS PAGE
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
            --feedback-bg: #f0fdf4;
            --feedback-border: #86efac;
            --feedback-text: #166534;
            --pending-bg: #fef3c7;
            --pending-border: #fcd34d;
            --pending-text: #92400e;
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

        /* Sidebar collapsed hide/show */
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
                   SIDEBAR BACKDROP
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
                   STATUS FILTERS
                ============================================= */
        .status-filters {
            display: flex;
            gap: 0.375rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding: 0.25rem 0.125rem 0.5rem 0.125rem;
            scrollbar-width: none;
            flex-wrap: nowrap;
        }

        .status-filters::-webkit-scrollbar {
            display: none;
        }

        .status-filter {
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 1px solid var(--slate-200);
            cursor: pointer;
            transition: all var(--transition-fast);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .status-filter:hover {
            border-color: var(--primary);
            color: var(--text-on-surface);
        }

        .status-filter.active {
            background: var(--primary);
            color: var(--on-primary);
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
        }

        .status-filter .filter-count {
            display: inline-block;
            background: rgba(0, 0, 0, 0.08);
            border-radius: var(--radius-full);
            padding: 0 0.375rem;
            font-size: 0.625rem;
            margin-left: 0.1875rem;
        }

        .status-filter.active .filter-count {
            background: rgba(255, 255, 255, 0.25);
        }

        /* =============================================
                   APPLICATIONS CARD
                ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
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
        }

        /* =============================================
                   APPLICATION ITEMS
                ============================================= */
        .app-card {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            transition: all var(--transition-fast);
        }

        .app-card:last-child {
            border-bottom: none;
        }

        .app-card:hover {
            background: var(--bg-surface-low);
        }

        .app-card .app-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.375rem;
        }

        .app-card .app-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-on-surface);
            flex: 1;
        }

        .app-card .app-company {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }

        .app-card .app-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .app-card .app-date {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .app-card .app-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* ===== BADGES ===== */
        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-shortlisted { background: #dbeafe; color: #2563eb; }
        .badge-interviewed { background: #e0e7ff; color: #4f46e5; }
        .badge-hired { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-withdrawn { background: #f3f4f6; color: #6b7280; }

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
            max-width: 320px;
            margin: 0 auto;
        }

        .empty-state .btn {
            margin-top: 1rem;
        }

        /* =============================================
                   BTN OUTLINE (for view details)
                ============================================= */
        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            border: 1px solid var(--slate-200);
            background: transparent;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(79, 70, 229, 0.04);
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        /* =============================================
                   MODAL
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
            max-width: 640px;
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

        .modal-header .badge {
            font-size: 0.6875rem;
            padding: 0.25rem 0.875rem;
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

        /* Modal content styles */
        .modal-detail-row {
            display: flex;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--slate-200);
        }

        .modal-detail-row:last-child {
            border-bottom: none;
        }

        .modal-detail-row .label {
            font-weight: 600;
            color: var(--text-on-surface-variant);
            min-width: 7.5rem;
            flex-shrink: 0;
        }

        .modal-detail-row .value {
            color: var(--text-on-surface);
            flex: 1;
        }

        .modal-detail-row .value .company-name {
            font-weight: 600;
            color: var(--primary);
        }

        .modal-section {
            margin-top: 1rem;
        }

        .modal-section h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-section .section-content {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            line-height: 1.7;
            padding: 0.75rem 1rem;
            background: var(--bg-surface-low);
            border-radius: 0.625rem;
            border: 1px solid var(--slate-200);
        }

        .modal-section .section-content.cover-letter {
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }

        /* ===== FEEDBACK STYLES ===== */
        .feedback-item {
            padding: 0.75rem 1rem;
            background: var(--feedback-bg);
            border: 1px solid var(--feedback-border);
            border-radius: 0.625rem;
            margin-bottom: 0.625rem;
        }

        .feedback-item:last-child {
            margin-bottom: 0;
        }

        .feedback-item .feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.25rem;
            font-size: 0.8125rem;
        }

        .feedback-item .feedback-header .feedback-from {
            font-weight: 600;
            color: var(--feedback-text);
        }

        .feedback-item .feedback-header .feedback-date {
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
        }

        .feedback-item .feedback-text {
            color: var(--text-on-surface);
            font-size: 0.875rem;
            line-height: 1.6;
        }

        .feedback-empty {
            text-align: center;
            padding: 1.25rem;
            color: var(--text-on-surface-variant);
            font-size: 0.875rem;
        }

        .feedback-pending {
            background: var(--pending-bg);
            border-color: var(--pending-border);
        }

        .feedback-pending .feedback-from {
            color: var(--pending-text);
        }

        .feedback-pending .feedback-text {
            color: var(--pending-text);
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

            .status-filters {
                gap: 0.5rem;
            }

            .status-filter {
                font-size: 0.8125rem;
                padding: 0.5rem 1.125rem;
            }

            .app-card {
                padding: 1rem 1.5rem;
            }

            .modal-detail-row .label {
                min-width: 9.375rem;
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

            .app-card .app-bottom {
                flex-direction: column;
                align-items: stretch;
            }

            .app-card .app-actions {
                width: 100%;
            }

            .app-card .app-actions .btn-outline {
                width: 100%;
                justify-content: center;
            }

            .app-card .app-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .app-card .app-title {
                font-size: 0.875rem;
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

            .modal-detail-row {
                flex-direction: column;
                padding: 0.5rem 0;
            }

            .modal-detail-row .label {
                min-width: auto;
                font-size: 0.75rem;
            }

            .modal-detail-row .value {
                font-size: 0.875rem;
            }

            .feedback-item .feedback-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
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

            .status-filters {
                gap: 0.25rem;
            }

            .status-filter {
                font-size: 0.625rem;
                padding: 0.25rem 0.625rem;
            }

            .app-card {
                padding: 0.75rem 1rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .card-header h3 {
                font-size: 0.875rem;
            }

            .empty-state {
                padding: 2rem 1rem;
            }

            .empty-state .empty-icon svg {
                width: 48px;
                height: 48px;
            }

            .empty-state h4 {
                font-size: 1rem;
            }

            .modal-header {
                padding: 0.875rem 1rem;
            }

            .modal-body {
                padding: 0.875rem;
            }

            .modal-footer {
                padding: 0.75rem 1rem;
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
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
    SIDEBAR - FIXED
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

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php" class="sidebar-main-link active">
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
                        <span class="material-symbols-outlined">description</span>
                        <span>My Applications</span>
                        <span class="status-dot"></span>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>My Applications</h1>
                        <p>Track the status of all your job applications</p>
                    </div>
                    <a href="job_search.php" class="btn-primary">
                        <span class="material-symbols-outlined">search</span>
                        Browse Jobs
                    </a>
                </div>

                <!-- Status Filters -->
                <div class="status-filters">
                    <a href="?status=all" class="status-filter <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                        All <span class="filter-count"><?php echo $statusCounts['all']; ?></span>
                    </a>
                    <a href="?status=pending" class="status-filter <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                        Pending <span class="filter-count"><?php echo $statusCounts['pending']; ?></span>
                    </a>
                    <a href="?status=shortlisted" class="status-filter <?php echo $statusFilter === 'shortlisted' ? 'active' : ''; ?>">
                        Shortlisted <span class="filter-count"><?php echo $statusCounts['shortlisted']; ?></span>
                    </a>
                    <a href="?status=interviewed" class="status-filter <?php echo $statusFilter === 'interviewed' ? 'active' : ''; ?>">
                        Interviewed <span class="filter-count"><?php echo $statusCounts['interviewed']; ?></span>
                    </a>
                    <a href="?status=hired" class="status-filter <?php echo $statusFilter === 'hired' ? 'active' : ''; ?>">
                        Hired <span class="filter-count"><?php echo $statusCounts['hired']; ?></span>
                    </a>
                    <a href="?status=rejected" class="status-filter <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                        Rejected <span class="filter-count"><?php echo $statusCounts['rejected']; ?></span>
                    </a>
                    <a href="?status=withdrawn" class="status-filter <?php echo $statusFilter === 'withdrawn' ? 'active' : ''; ?>">
                        Withdrawn <span class="filter-count"><?php echo $statusCounts['withdrawn']; ?></span>
                    </a>
                </div>

                <!-- Applications List -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <?php if ($statusFilter === 'all'): ?>
                                All Applications
                            <?php else: ?>
                                <?php echo ucfirst($statusFilter); ?> Applications
                            <?php endif; ?>
                        </h3>
                        <span class="result-count"><?php echo count($filteredApplications); ?> found</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($filteredApplications)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <span class="material-symbols-outlined" style="font-size:3rem; opacity:0.3;">inbox</span>
                                </div>
                                <h4>No Applications Found</h4>
                                <p>
                                    <?php if ($statusFilter === 'all'): ?>
                                        You haven't submitted any applications yet. Start your job search today!
                                    <?php else: ?>
                                        You don't have any <?php echo $statusFilter; ?> applications.
                                    <?php endif; ?>
                                </p>
                                <a href="job_search.php" class="btn-primary" style="display:inline-flex;">Browse Jobs</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($filteredApplications as $app): ?>
                                <div class="app-card">
                                    <!-- Top: Title + Badge -->
                                    <div class="app-top">
                                        <span class="app-title"><?php echo htmlspecialchars($app['title'] ?? 'Position'); ?></span>
                                        <span class="badge <?php echo $statusBadges[$app['status']] ?? 'badge-pending'; ?>">
                                            <?php echo $statusLabels[$app['status']] ?? ucfirst($app['status']); ?>
                                        </span>
                                    </div>

                                    <!-- Company -->
                                    <div class="app-company"><?php echo htmlspecialchars($app['company_name'] ?? 'Company'); ?></div>

                                    <!-- Bottom: Date + Actions -->
                                    <div class="app-bottom">
                                        <span class="app-date">Applied <?php echo date('M d, Y', strtotime($app['applied_at'] ?? 'now')); ?></span>
                                        <div class="app-actions">
                                            <a href="?view=<?php echo $app['id']; ?>" class="btn-outline btn-sm view-details-btn" data-id="<?php echo $app['id']; ?>">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- =============================================
    MODAL: APPLICATION DETAILS
    ============================================= -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    Application Details
                    <span class="badge badge-pending" id="modalStatusBadge">Pending</span>
                </h2>
                <button class="btn-close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div id="modalLoading" style="text-align:center; padding:2.5rem 0;">
                    <div class="loader"></div>
                    <p style="margin-top:0.75rem; color:var(--text-on-surface-variant);">Loading application details...</p>
                </div>
                <div id="modalContent" style="display:none;">
                    <!-- Dynamically populated -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeModal()">Close</button>
            </div>
        </div>
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
            // 3. RESPONSIVE HANDLING
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
            // 4. MODAL SYSTEM
            // =============================================
            const modal = document.getElementById('detailsModal');
            const modalContent = document.getElementById('modalContent');
            const modalLoading = document.getElementById('modalLoading');
            const modalStatusBadge = document.getElementById('modalStatusBadge');

            const statusBadgeMap = {
                'pending': 'badge-pending',
                'shortlisted': 'badge-shortlisted',
                'interviewed': 'badge-interviewed',
                'hired': 'badge-hired',
                'rejected': 'badge-rejected',
                'withdrawn': 'badge-withdrawn'
            };

            const statusLabelMap = {
                'pending': 'Pending Review',
                'shortlisted': 'Shortlisted',
                'interviewed': 'Interviewed',
                'hired': 'Hired',
                'rejected': 'Rejected',
                'withdrawn': 'Withdrawn'
            };

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function renderApplicationDetails(app, feedback) {
                const statusClass = statusBadgeMap[app.status] || 'badge-pending';
                const statusLabel = statusLabelMap[app.status] || app.status;
                
                modalStatusBadge.className = 'badge ' + statusClass;
                modalStatusBadge.textContent = statusLabel;
                
                const appliedDate = app.applied_at ? new Date(app.applied_at).toLocaleDateString('en-US', {
                    year: 'numeric', month: 'long', day: 'numeric'
                }) : 'N/A';
                
                const coverLetter = app.cover_letter || 'No cover letter provided.';
                
                // Build feedback section
                let feedbackHtml = '';
                if (feedback && feedback.length > 0) {
                    let feedbackItemsHtml = '';
                    
                    feedback.forEach(item => {
                        const date = new Date(item.created_at).toLocaleDateString('en-US', {
                            year: 'numeric', month: 'short', day: 'numeric',
                            hour: '2-digit', minute: '2-digit'
                        });
                        
                        if (item.is_pending) {
                            feedbackItemsHtml += `
                                <div class="feedback-item feedback-pending">
                                    <div class="feedback-header">
                                        <span class="feedback-from">${escapeHtml(item.first_name || 'System')}</span>
                                        <span class="feedback-date">${date}</span>
                                    </div>
                                    <div class="feedback-text">${escapeHtml(item.feedback)}</div>
                                </div>
                            `;
                        } else if (item.feedback && item.feedback.trim() !== '') {
                            const fromName = (item.first_name || 'HR') + ' ' + (item.last_name || 'Staff');
                            feedbackItemsHtml += `
                                <div class="feedback-item">
                                    <div class="feedback-header">
                                        <span class="feedback-from">${escapeHtml(fromName)}</span>
                                        <span class="feedback-date">${date}</span>
                                    </div>
                                    <div class="feedback-text">${escapeHtml(item.feedback)}</div>
                                </div>
                            `;
                        }
                    });
                    
                    if (feedbackItemsHtml) {
                        feedbackHtml = `
                            <div class="modal-section">
                                <h4>
                                    <span class="material-symbols-outlined" style="font-size:1.125rem;">chat</span>
                                    Feedback from HR
                                </h4>
                                <div id="feedbackContainer">
                                    ${feedbackItemsHtml}
                                </div>
                            </div>
                        `;
                    } else if (app.status === 'pending') {
                        feedbackHtml = `
                            <div class="modal-section">
                                <h4>
                                    <span class="material-symbols-outlined" style="font-size:1.125rem;">chat</span>
                                    Feedback from HR
                                </h4>
                                <div class="feedback-item feedback-pending">
                                    <div class="feedback-header">
                                        <span class="feedback-from">Pending Review</span>
                                    </div>
                                    <div class="feedback-text">Please wait for HR to review your application.</div>
                                </div>
                            </div>
                        `;
                    } else {
                        feedbackHtml = `
                            <div class="modal-section">
                                <h4>
                                    <span class="material-symbols-outlined" style="font-size:1.125rem;">chat</span>
                                    Feedback from HR
                                </h4>
                                <div class="feedback-empty">No feedback has been provided yet.</div>
                            </div>
                        `;
                    }
                } else {
                    if (app.status === 'pending') {
                        feedbackHtml = `
                            <div class="modal-section">
                                <h4>
                                    <span class="material-symbols-outlined" style="font-size:1.125rem;">chat</span>
                                    Feedback from HR
                                </h4>
                                <div class="feedback-item feedback-pending">
                                    <div class="feedback-header">
                                        <span class="feedback-from">Pending Review</span>
                                    </div>
                                    <div class="feedback-text">Please wait for HR to review your application.</div>
                                </div>
                            </div>
                        `;
                    } else {
                        feedbackHtml = `
                            <div class="modal-section">
                                <h4>
                                    <span class="material-symbols-outlined" style="font-size:1.125rem;">chat</span>
                                    Feedback from HR
                                </h4>
                                <div class="feedback-empty">No feedback has been provided yet.</div>
                            </div>
                        `;
                    }
                }
                
                modalContent.innerHTML = `
                    <div class="modal-detail-row">
                        <span class="label">Position</span>
                        <span class="value"><strong>${escapeHtml(app.job_title || 'N/A')}</strong></span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="label">Company</span>
                        <span class="value"><span class="company-name">${escapeHtml(app.company_name || 'N/A')}</span></span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="label">Location</span>
                        <span class="value">${escapeHtml(app.location || 'N/A')}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="label">Job Type</span>
                        <span class="value">${escapeHtml(app.job_type || 'N/A')}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="label">Salary Range</span>
                        <span class="value">${escapeHtml(app.salary_range || 'Not specified')}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="label">Applied On</span>
                        <span class="value">${appliedDate}</span>
                    </div>
                    <div class="modal-detail-row">
                        <span class="label">Status</span>
                        <span class="value">
                            <span class="badge ${statusClass}">${statusLabel}</span>
                        </span>
                    </div>
                    <div class="modal-section">
                        <h4>
                            <span class="material-symbols-outlined" style="font-size:1.125rem;">description</span>
                            Cover Letter
                        </h4>
                        <div class="section-content cover-letter">${escapeHtml(coverLetter)}</div>
                    </div>
                    ${app.job_description ? `
                    <div class="modal-section">
                        <h4>
                            <span class="material-symbols-outlined" style="font-size:1.125rem;">work</span>
                            Job Description
                        </h4>
                        <div class="section-content">${escapeHtml(app.job_description)}</div>
                    </div>
                    ` : ''}
                    ${feedbackHtml}
                `;
            }

            function openModal(applicationId) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                
                modalLoading.style.display = 'block';
                modalContent.style.display = 'none';
                
                fetch('get_application.php?id=' + applicationId)
                    .then(response => response.json())
                    .then(data => {
                        modalLoading.style.display = 'none';
                        
                        if (data.success) {
                            renderApplicationDetails(data.application, data.feedback || []);
                            modalContent.style.display = 'block';
                        } else {
                            modalContent.innerHTML = `
                                <div style="text-align:center; padding:1.875rem 0; color:#dc2626;">
                                    <span class="material-symbols-outlined" style="font-size:3rem;">error</span>
                                    <p style="margin-top:0.5rem;">${escapeHtml(data.error || 'Failed to load application details.')}</p>
                                </div>
                            `;
                            modalContent.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        modalLoading.style.display = 'none';
                        modalContent.innerHTML = `
                            <div style="text-align:center; padding:1.875rem 0; color:#dc2626;">
                                <span class="material-symbols-outlined" style="font-size:3rem;">error</span>
                                <p style="margin-top:0.5rem;">Error loading application details. Please try again.</p>
                                <p style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">${escapeHtml(error.message)}</p>
                            </div>
                        `;
                        modalContent.style.display = 'block';
                    });
            }

            function closeModal() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }

            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeModal();
                }
            });

            // =============================================
            // 5. HANDLE VIEW DETAILS CLICKS
            // =============================================
            document.querySelectorAll('.view-details-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const id = this.getAttribute('data-id');
                    if (id) {
                        openModal(id);
                    }
                });
            });

            // =============================================
            // 6. KEYBOARD ACCESSIBILITY
            // =============================================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (modal.classList.contains('active')) {
                        closeModal();
                    } else if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                }
            });

            // =============================================
            // 7. INITIAL STATE
            // =============================================
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            <?php if (isset($_GET['view']) && is_numeric($_GET['view']) && $applicationDetails): ?>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    openModal(<?php echo (int)$_GET['view']; ?>);
                }, 300);
            });
            <?php endif; ?>

            console.log('ISMERS Applications Page loaded successfully.');
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