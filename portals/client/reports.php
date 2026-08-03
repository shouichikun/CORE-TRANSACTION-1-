<?php
// portals/client/reports.php - Client Reports & Analytics
session_start();

// =============================================
// DEBUG MODE
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has client role
if ($_SESSION['role'] !== 'client') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Client User';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'client';

// Get client profile
$client = getRecord("
    SELECT c.*, u.email as user_email, u.full_name
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.user_id = ?
", [$userId], "i");

if (!$client) {
    $client = ['company_name' => 'Your Company', 'id' => 0];
}

$companyName = $client['company_name'] ?? 'Your Company';
$clientId = $client['id'] ?? 0;

// =============================================
// GET PENDING AGENCY APPLICATIONS FOR SIDEBAR BADGE
// =============================================
$pendingAgencyCount = 0;
$pendingAgencies = getRecords("
    SELECT COUNT(*) as count FROM agency_applications 
    WHERE client_id = ? AND status = 'pending'
", [$clientId], "i");

if (!empty($pendingAgencies)) {
    $pendingAgencyCount = $pendingAgencies[0]['count'] ?? 0;
}

// =============================================
// GET FILTER PARAMETERS
// =============================================
$reportType = $_GET['type'] ?? 'applications';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// =============================================
// REPORT 1: APPLICATIONS BY JOB
// =============================================
if ($reportType === 'applications') {
    $reportData = getRecords("
        SELECT 
            jo.id as job_id,
            jo.title as job_title,
            jo.status as job_status,
            jo.created_at as job_created,
            COUNT(a.id) as total_applications,
            SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN a.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
            SUM(CASE WHEN a.status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
            SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as hired,
            SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM job_orders jo
        LEFT JOIN applications a ON jo.id = a.job_order_id
        WHERE jo.client_id = ?
        AND DATE(jo.created_at) BETWEEN ? AND ?
        GROUP BY jo.id
        ORDER BY total_applications DESC
    ", [$clientId, $startDate, $endDate], "iss");
}

// =============================================
// REPORT 2: APPLICANTS BY STATUS
// =============================================
if ($reportType === 'status') {
    $reportData = getRecords("
        SELECT 
            a.status,
            COUNT(a.id) as count,
            COUNT(DISTINCT a.applicant_id) as unique_applicants
        FROM applications a
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = ?
        AND DATE(a.applied_at) BETWEEN ? AND ?
        GROUP BY a.status
        ORDER BY count DESC
    ", [$clientId, $startDate, $endDate], "iss");
}

// =============================================
// REPORT 3: EMPLOYEES BY JOB
// =============================================
if ($reportType === 'employees') {
    $reportData = getRecords("
        SELECT 
            jo.id as job_id,
            jo.title as job_title,
            COUNT(d.id) as total_employees,
            SUM(CASE WHEN d.status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN d.status = 'on_hold' THEN 1 ELSE 0 END) as on_hold,
            SUM(CASE WHEN d.status = 'terminated' THEN 1 ELSE 0 END) as terminated,
            SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) as completed,
            AVG(TIMESTAMPDIFF(DAY, d.start_date, COALESCE(d.end_date, CURDATE()))) as avg_days
        FROM job_orders jo
        LEFT JOIN deployments d ON jo.id = d.job_order_id
        WHERE jo.client_id = ?
        AND DATE(d.created_at) BETWEEN ? AND ?
        GROUP BY jo.id
        ORDER BY total_employees DESC
    ", [$clientId, $startDate, $endDate], "iss");
}

// =============================================
// REPORT 4: REVENUE SUMMARY
// =============================================
if ($reportType === 'revenue') {
    $reportData = getRecords("
        SELECT 
            DATE_FORMAT(o.created_at, '%Y-%m') as month,
            COUNT(o.id) as total_offers,
            SUM(CASE WHEN o.status = 'accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN o.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN o.status = 'accepted' THEN o.salary_offered ELSE 0 END) as total_revenue,
            AVG(CASE WHEN o.status = 'accepted' THEN o.salary_offered ELSE NULL END) as avg_salary
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = ?
        AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY month
        ORDER BY month DESC
    ", [$clientId, $startDate, $endDate], "iss");
}

// =============================================
// REPORT 5: HIRING Funnel
// =============================================
if ($reportType === 'funnel') {
    $funnelData = getRecord("
        SELECT 
            (SELECT COUNT(*) FROM applications a 
             JOIN job_orders jo ON a.job_order_id = jo.id 
             WHERE jo.client_id = ?) as total_applications,
            (SELECT COUNT(*) FROM applications a 
             JOIN job_orders jo ON a.job_order_id = jo.id 
             WHERE jo.client_id = ? AND a.status = 'shortlisted') as shortlisted,
            (SELECT COUNT(*) FROM applications a 
             JOIN job_orders jo ON a.job_order_id = jo.id 
             WHERE jo.client_id = ? AND a.status = 'hired') as hired,
            (SELECT COUNT(*) FROM offers o 
             JOIN applications a ON o.application_id = a.id 
             JOIN job_orders jo ON a.job_order_id = jo.id 
             WHERE jo.client_id = ? AND o.status = 'accepted') as offers_accepted
    ", [$clientId, $clientId, $clientId, $clientId], "iiii");
    
    // Also get monthly trend
    $monthlyTrend = getRecords("
        SELECT 
            DATE_FORMAT(a.applied_at, '%Y-%m') as month,
            COUNT(*) as applications,
            SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as hires
        FROM applications a
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = ?
        AND DATE(a.applied_at) BETWEEN ? AND ?
        GROUP BY month
        ORDER BY month ASC
    ", [$clientId, $startDate, $endDate], "iss");
    
    $reportData = [
        'funnel' => $funnelData,
        'trend' => $monthlyTrend
    ];
}

// =============================================
// REPORT 6: AGENCY PERFORMANCE
// =============================================
if ($reportType === 'agencies') {
    $reportData = getRecords("
        SELECT 
            ra.id as agency_id,
            ra.agency_name,
            ra.agency_code,
            COUNT(jo.id) as total_jobs,
            COUNT(a.id) as total_applications,
            SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as hires,
            SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejections
        FROM recruitment_agencies ra
        LEFT JOIN job_orders jo ON ra.id = jo.agency_id
        LEFT JOIN applications a ON jo.id = a.job_order_id
        WHERE ra.client_id = ?
        AND (jo.created_at BETWEEN ? AND ? OR jo.created_at IS NULL)
        GROUP BY ra.id
        ORDER BY total_applications DESC
    ", [$clientId, $startDate, $endDate], "iss");
}

// =============================================
// CALCULATE OVERALL STATS
// =============================================
$overallStats = getRecord("
    SELECT 
        (SELECT COUNT(*) FROM job_orders WHERE client_id = ?) as total_jobs,
        (SELECT COUNT(*) FROM applications a 
         JOIN job_orders jo ON a.job_order_id = jo.id 
         WHERE jo.client_id = ?) as total_applications,
        (SELECT COUNT(*) FROM deployments d 
         JOIN job_orders jo ON d.job_order_id = jo.id 
         WHERE jo.client_id = ? AND d.status = 'active') as active_employees,
        (SELECT COUNT(*) FROM offers o 
         JOIN applications a ON o.application_id = a.id 
         JOIN job_orders jo ON a.job_order_id = jo.id 
         WHERE jo.client_id = ? AND o.status = 'accepted') as total_hires
", [$clientId, $clientId, $clientId, $clientId], "iiii");

// Get greeting
$currentHour = date('H');
$greeting = 'Good Evening';
if ($currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour < 18) {
    $greeting = 'Good Afternoon';
}

// Report type labels
$reportLabels = [
    'applications' => 'Applications by Job',
    'status' => 'Applicants by Status',
    'employees' => 'Employees by Job',
    'revenue' => 'Revenue Summary',
    'funnel' => 'Hiring Funnel',
    'agencies' => 'Agency Performance'
];

// Helper function to format number
function formatNumber($num) {
    return number_format($num);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reports - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           REPORTS & ANALYTICS - PROFESSIONAL UI
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
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.04);
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
           SIDEBAR
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

        /* =============================================
           BREADCRUMB
        ============================================= */
        .breadcrumb-bar {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 0.75rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-xs);
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

        /* =============================================
           PAGE HEADER
        ============================================= */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) { .page-header { flex-direction: row; align-items: center; justify-content: space-between; } }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.125rem; }

        /* =============================================
           BUTTONS
        ============================================= */
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
        .btn-ghost { background: transparent; color: var(--text-on-surface-variant); }
        .btn-ghost:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* =============================================
           OVERALL STATS
        ============================================= */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            box-shadow: var(--shadow-xs);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.primary { background: #eef0ff; color: #4f46e5; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-card .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.5rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           REPORT CONTROLS
        ============================================= */
        .report-controls {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-xs);
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }
        .report-controls .control-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
        }
        .report-controls select,
        .report-controls input {
            padding: 0.375rem 0.75rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .report-controls select:focus,
        .report-controls input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* =============================================
           REPORT TABLE
        ============================================= */
        .report-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
        }
        .report-card .report-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .report-card .report-header .report-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .report-card .report-body { padding: 0; overflow-x: auto; }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .report-table thead { background: var(--bg-surface-low); }
        .report-table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        .report-table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        .report-table tr:last-child td { border-bottom: none; }
        .report-table tbody tr:hover td { background: var(--bg-surface-low); }

        .report-table .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .report-table .badge-open { background: #dbeafe; color: #2563eb; }
        .report-table .badge-ongoing { background: #e0e7ff; color: #4f46e5; }
        .report-table .badge-closed { background: #f1f5f9; color: #64748b; }
        .report-table .badge-on_hold { background: #fef3c7; color: #d97706; }
        .report-table .badge-active { background: #d1fae5; color: #059669; }
        .report-table .badge-terminated { background: #fee2e2; color: #dc2626; }
        .report-table .badge-completed { background: #dbeafe; color: #2563eb; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .empty-state .material-symbols-outlined {
            font-size: 4rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.75rem;
        }
        .empty-state h3 { font-size: 1.125rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.8125rem; }

        /* =============================================
           FUNNEL VISUALIZATION
        ============================================= */
        .funnel-container {
            padding: 1.5rem;
        }
        .funnel-step {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        .funnel-step:last-child { margin-bottom: 0; }
        .funnel-step .step-label {
            min-width: 120px;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }
        .funnel-step .step-bar-track {
            flex: 1;
            height: 2.5rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            overflow: hidden;
            position: relative;
        }
        .funnel-step .step-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            border-radius: 0.5rem;
            transition: width 1s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 1rem;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
        }
        .funnel-step .step-bar-fill.green { background: linear-gradient(90deg, #059669, #34d399); }
        .funnel-step .step-bar-fill.yellow { background: linear-gradient(90deg, #d97706, #fbbf24); }
        .funnel-step .step-bar-fill.blue { background: linear-gradient(90deg, #2563eb, #60a5fa); }
        .funnel-step .step-count {
            min-width: 3rem;
            text-align: right;
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-on-surface);
        }

        /* =============================================
           MONTHLY TREND CHART (CSS-based)
        ============================================= */
        .trend-container {
            padding: 1.5rem;
        }
        .trend-bar {
            display: flex;
            align-items: flex-end;
            gap: 0.5rem;
            height: 200px;
            padding-top: 1rem;
        }
        .trend-bar .bar-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
        }
        .trend-bar .bar-item .bar {
            width: 100%;
            min-height: 4px;
            background: var(--primary);
            border-radius: 4px 4px 0 0;
            transition: height 0.6s ease;
            position: relative;
        }
        .trend-bar .bar-item .bar .bar-value {
            position: absolute;
            top: -1.5rem;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.625rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }
        .trend-bar .bar-item .bar-label {
            font-size: 0.625rem;
            color: var(--text-on-surface-variant);
            text-align: center;
        }

        /* =============================================
           TOAST
        ============================================= */
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

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-lg); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .report-controls { flex-direction: column; align-items: stretch; }
            .report-table { font-size: 0.75rem; }
            .report-table th, .report-table td { padding: 0.375rem 0.5rem; }
            .funnel-step { flex-direction: column; align-items: stretch; gap: 0.25rem; }
            .funnel-step .step-label { min-width: auto; }
            .funnel-step .step-count { text-align: left; }
            .trend-bar { height: 150px; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .report-table { font-size: 0.6875rem; min-width: 300px; }
            .trend-bar { height: 120px; gap: 0.25rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
        
        /* =============================================
           PROFILE PICTURE STYLES
        ============================================= */
        .avatar-img {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            background: var(--primary-container);
        }
        .avatar-small {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
            object-fit: cover;
        }
        .avatar-small img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
    </style>
</head>
<body>

    <!-- Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">business</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">Client Portal</p>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="jobs.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
            </a>
            <a href="agency_application.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">apartment</span>
                <span class="nav-text">Agencies</span>
                <?php if ($pendingAgencyCount > 0): ?>
                    <span class="nav-badge"><?php echo $pendingAgencyCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="employees.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Employees</span>
            </a>
            <a href="applicants.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person_search</span>
                <span class="nav-text">Applicants</span>
            </a>
            <a href="invoices.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">receipt</span>
                <span class="nav-text">Invoices</span>
            </a>
            <a href="support.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">support_agent</span>
                <span class="nav-text">Support</span>
            </a>
            <a href="reports.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">analytics</span>
                <span class="nav-text">Reports</span>
            </a>
            <div class="nav-label" style="margin-top:1rem;">Settings</div>
            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <!-- =============================================
        SIDEBAR FOOTER
        ============================================= -->
        <?php
        $userProfile = getUserProfileData($userId);
        ?>
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
                    <div class="user-name"><?php echo htmlspecialchars($userProfile['first_name']); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($userProfile['email']); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-wrapper" id="mainWrapper">
        <header class="top-header">
            <div class="top-header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                    <span class="material-symbols-outlined">chevron_left</span>
                </button>
                <span class="separator">|</span>
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Reports</span>
            </div>
            <?php
            $userProfile = getUserProfileData($userId);
            ?>
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                             alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                             class="avatar-small">
                    <?php else: ?>
                        <span class="avatar-small"><?php echo $userProfile['initials']; ?></span>
                    <?php endif; ?>
                    <span class="profile-name"><?php echo htmlspecialchars($userProfile['first_name']); ?></span>
                    <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">
                        <span class="material-symbols-outlined">logout</span> Logout
                    </button>
                </div>
            </div>
        </header>

        <main class="main-scroll">
            <div class="container">
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">analytics</span>
                        <span>Reports</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Data-driven insights for your business</span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Reports & Analytics</h1>
                        <p>Track your hiring metrics and make data-driven decisions</p>
                    </div>
                </div>

                <!-- Overall Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <span class="material-symbols-outlined">work</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo formatNumber($overallStats['total_jobs'] ?? 0); ?></div>
                            <div class="stat-label">Total Jobs</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <span class="material-symbols-outlined">person_search</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo formatNumber($overallStats['total_applications'] ?? 0); ?></div>
                            <div class="stat-label">Total Applications</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo formatNumber($overallStats['total_hires'] ?? 0); ?></div>
                            <div class="stat-label">Total Hires</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">people</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo formatNumber($overallStats['active_employees'] ?? 0); ?></div>
                            <div class="stat-label">Active Employees</div>
                        </div>
                    </div>
                </div>

                <!-- Report Controls -->
                <div class="report-controls">
                    <span class="control-label">Report Type:</span>
                    <select id="reportType" onchange="applyFilters()">
                        <?php foreach ($reportLabels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $reportType === $key ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="control-label" style="margin-left:1rem;">Date Range:</span>
                    <input type="date" id="startDate" value="<?php echo $startDate; ?>">
                    <span style="color:var(--text-on-surface-variant);">to</span>
                    <input type="date" id="endDate" value="<?php echo $endDate; ?>">

                    <button class="btn btn-primary" onclick="applyFilters()">
                        <span class="material-symbols-outlined">refresh</span>
                        Generate Report
                    </button>

                    <button class="btn btn-outline" onclick="exportReport()">
                        <span class="material-symbols-outlined">download</span>
                        Export CSV
                    </button>
                </div>

                <!-- Report Content -->
                <div class="report-card">
                    <div class="report-header">
                        <div class="report-title">
                            <?php echo $reportLabels[$reportType] ?? 'Report'; ?>
                        </div>
                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                            <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
                        </span>
                    </div>
                    <div class="report-body">
                        <?php if (empty($reportData) || (is_array($reportData) && count($reportData) === 0)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">data_usage</span>
                                <h3>No data available</h3>
                                <p>Try adjusting your date range or selecting a different report type.</p>
                            </div>
                        <?php else: ?>
                            <?php if ($reportType === 'applications'): ?>
                                <div style="overflow-x:auto;">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>Job Title</th>
                                                <th>Status</th>
                                                <th>Total</th>
                                                <th>Pending</th>
                                                <th>Reviewed</th>
                                                <th>Shortlisted</th>
                                                <th>Hired</th>
                                                <th>Rejected</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData as $row): ?>
                                                <tr>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo htmlspecialchars($row['job_title']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo 'badge-' . ($row['job_status'] ?? 'closed'); ?>">
                                                            <?php echo ucfirst($row['job_status'] ?? 'Closed'); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-weight:700; color:var(--text-on-surface);">
                                                            <?php echo $row['total_applications']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#d97706; font-weight:600;">
                                                            <?php echo $row['pending'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#2563eb; font-weight:600;">
                                                            <?php echo $row['reviewed'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#059669; font-weight:600;">
                                                            <?php echo $row['shortlisted'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#047857; font-weight:600;">
                                                            <?php echo $row['hired'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#dc2626; font-weight:600;">
                                                            <?php echo $row['rejected'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($reportType === 'status'): ?>
                                <div style="overflow-x:auto;">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th>Applications</th>
                                                <th>Unique Applicants</th>
                                                <th>% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total = array_sum(array_column($reportData, 'count'));
                                            foreach ($reportData as $row): 
                                                $percent = $total > 0 ? round(($row['count'] / $total) * 100) : 0;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge badge-<?php echo $row['status']; ?>">
                                                            <?php echo ucfirst($row['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo $row['count']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $row['unique_applicants']; ?>
                                                    </td>
                                                    <td>
                                                        <div style="display:flex; align-items:center; gap:0.5rem;">
                                                            <span style="font-weight:600; color:var(--text-on-surface);">
                                                                <?php echo $percent; ?>%
                                                            </span>
                                                            <div style="flex:1; height:0.375rem; background:var(--bg-surface-low); border-radius:9999px; overflow:hidden;">
                                                                <div style="height:100%; width:<?php echo $percent; ?>%; background:var(--primary); border-radius:9999px;"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($reportType === 'employees'): ?>
                                <div style="overflow-x:auto;">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>Job Title</th>
                                                <th>Total</th>
                                                <th>Active</th>
                                                <th>On Hold</th>
                                                <th>Completed</th>
                                                <th>Terminated</th>
                                                <th>Avg Days</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData as $row): ?>
                                                <tr>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo htmlspecialchars($row['job_title']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-weight:700; color:var(--text-on-surface);">
                                                            <?php echo $row['total_employees']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#059669; font-weight:600;">
                                                            <?php echo $row['active'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#d97706; font-weight:600;">
                                                            <?php echo $row['on_hold'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#2563eb; font-weight:600;">
                                                            <?php echo $row['completed'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#dc2626; font-weight:600;">
                                                            <?php echo $row['terminated'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo round($row['avg_days'] ?? 0); ?> days
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($reportType === 'revenue'): ?>
                                <div style="overflow-x:auto;">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>Month</th>
                                                <th>Offers</th>
                                                <th>Accepted</th>
                                                <th>Rejected</th>
                                                <th>Total Revenue</th>
                                                <th>Avg Salary</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData as $row): ?>
                                                <tr>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo date('M Y', strtotime($row['month'] . '-01')); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $row['total_offers']; ?></td>
                                                    <td>
                                                        <span style="color:#059669; font-weight:600;">
                                                            <?php echo $row['accepted']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#dc2626; font-weight:600;">
                                                            <?php echo $row['rejected']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-weight:700; color:var(--text-on-surface);">
                                                            ₱<?php echo number_format($row['total_revenue'] ?? 0, 2); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        ₱<?php echo number_format($row['avg_salary'] ?? 0, 2); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($reportType === 'funnel'): ?>
                                <div class="funnel-container">
                                    <?php 
                                    $funnel = $reportData['funnel'] ?? [];
                                    $total = $funnel['total_applications'] ?? 1;
                                    $shortlisted = $funnel['shortlisted'] ?? 0;
                                    $hired = $funnel['hired'] ?? 0;
                                    $offersAccepted = $funnel['offers_accepted'] ?? 0;
                                    ?>
                                    
                                    <div class="funnel-step">
                                        <span class="step-label">Applications</span>
                                        <div class="step-bar-track">
                                            <div class="step-bar-fill blue" style="width:100%;">
                                                <?php echo $total; ?>
                                            </div>
                                        </div>
                                        <span class="step-count">100%</span>
                                    </div>
                                    
                                    <div class="funnel-step">
                                        <span class="step-label">Shortlisted</span>
                                        <div class="step-bar-track">
                                            <div class="step-bar-fill yellow" style="width:<?php echo $total > 0 ? ($shortlisted / $total) * 100 : 0; ?>%;">
                                                <?php echo $shortlisted; ?>
                                            </div>
                                        </div>
                                        <span class="step-count"><?php echo $total > 0 ? round(($shortlisted / $total) * 100) : 0; ?>%</span>
                                    </div>
                                    
                                    <div class="funnel-step">
                                        <span class="step-label">Hired</span>
                                        <div class="step-bar-track">
                                            <div class="step-bar-fill green" style="width:<?php echo $total > 0 ? ($hired / $total) * 100 : 0; ?>%;">
                                                <?php echo $hired; ?>
                                            </div>
                                        </div>
                                        <span class="step-count"><?php echo $total > 0 ? round(($hired / $total) * 100) : 0; ?>%</span>
                                    </div>
                                    
                                    <div class="funnel-step">
                                        <span class="step-label">Offers Accepted</span>
                                        <div class="step-bar-track">
                                            <div class="step-bar-fill green" style="width:<?php echo $total > 0 ? ($offersAccepted / $total) * 100 : 0; ?>%;">
                                                <?php echo $offersAccepted; ?>
                                            </div>
                                        </div>
                                        <span class="step-count"><?php echo $total > 0 ? round(($offersAccepted / $total) * 100) : 0; ?>%</span>
                                    </div>
                                </div>

                                <?php if (!empty($reportData['trend'])): ?>
                                <div class="trend-container">
                                    <h4 style="font-size:0.875rem; font-weight:600; color:var(--text-on-surface-variant); margin-bottom:1rem;">Monthly Trend</h4>
                                    <div class="trend-bar">
                                        <?php 
                                        $maxApps = max(array_column($reportData['trend'], 'applications'));
                                        $maxHires = max(array_column($reportData['trend'], 'hires'));
                                        $maxVal = max($maxApps, $maxHires);
                                        foreach ($reportData['trend'] as $trend): 
                                            $appPercent = $maxVal > 0 ? ($trend['applications'] / $maxVal) * 100 : 0;
                                            $hirePercent = $maxVal > 0 ? ($trend['hires'] / $maxVal) * 100 : 0;
                                        ?>
                                        <div class="bar-item">
                                            <div class="bar" style="height:<?php echo max($appPercent, 4); ?>%; background:var(--primary);">
                                                <span class="bar-value"><?php echo $trend['applications']; ?></span>
                                            </div>
                                            <div class="bar" style="height:<?php echo max($hirePercent, 4); ?>%; background:#059669;">
                                                <span class="bar-value"><?php echo $trend['hires']; ?></span>
                                            </div>
                                            <div class="bar-label"><?php echo date('M', strtotime($trend['month'] . '-01')); ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="display:flex; gap:1rem; justify-content:center; margin-top:0.5rem; font-size:0.75rem; color:var(--text-on-surface-variant);">
                                        <span><span style="display:inline-block; width:0.75rem; height:0.75rem; background:var(--primary); border-radius:4px; vertical-align:middle;"></span> Applications</span>
                                        <span><span style="display:inline-block; width:0.75rem; height:0.75rem; background:#059669; border-radius:4px; vertical-align:middle;"></span> Hires</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($reportType === 'agencies'): ?>
                                <div style="overflow-x:auto;">
                                    <table class="report-table">
                                        <thead>
                                            <tr>
                                                <th>Agency</th>
                                                <th>Code</th>
                                                <th>Jobs</th>
                                                <th>Applications</th>
                                                <th>Hires</th>
                                                <th>Rejections</th>
                                                <th>Conversion Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reportData as $row): ?>
                                                <?php 
                                                $convRate = ($row['total_applications'] ?? 0) > 0 
                                                    ? round(($row['hires'] / $row['total_applications']) * 100) 
                                                    : 0;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo htmlspecialchars($row['agency_name']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                            <?php echo htmlspecialchars($row['agency_code']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $row['total_jobs'] ?? 0; ?></td>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo $row['total_applications'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#059669; font-weight:600;">
                                                            <?php echo $row['hires'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="color:#dc2626; font-weight:600;">
                                                            <?php echo $row['rejections'] ?? 0; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            <?php echo $convRate; ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
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
                if (window.innerWidth <= 768) closeMobileSidebar();
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
        // 4. APPLY FILTERS
        // =============================================
        function applyFilters() {
            const type = document.getElementById('reportType').value;
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            
            let url = 'reports.php?type=' + type;
            if (start) url += '&start_date=' + start;
            if (end) url += '&end_date=' + end;
            
            window.location.href = url;
        }

        // =============================================
        // 5. EXPORT REPORT
        // =============================================
        function exportReport() {
            const type = document.getElementById('reportType').value;
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            
            let url = 'ajax/export_report.php?type=' + type;
            if (start) url += '&start_date=' + start;
            if (end) url += '&end_date=' + end;
            
            window.location.href = url;
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

        console.log('📊 Reports & Analytics loaded successfully!');
    </script>
</body>
</html>