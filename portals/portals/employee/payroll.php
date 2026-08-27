<?php
// portals/employee/payroll.php - Employee Payroll & Payslip Viewing
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

// Check if user has the correct role
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Employee';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// =============================================
// GET EMPLOYEE DATA
// =============================================
$employee = getRecord("
    SELECT e.*, 
           u.first_name, u.last_name, u.email
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE e.user_id = ?
", [$userId], "i");

// =============================================
// GET PAYROLL RECORDS
// =============================================
$payrollRecords = getRecords("
    SELECT * FROM payroll 
    WHERE user_id = ? 
    ORDER BY pay_period_end DESC
", [$userId], "i");

// =============================================
// CALCULATE PAYROLL SUMMARY
// =============================================
$totalEarned = 0;
$totalDeductions = 0;
$totalNetPay = 0;

foreach ($payrollRecords as $record) {
    $totalEarned += $record['gross_pay'] ?? 0;
    $totalDeductions += $record['total_deductions'] ?? 0;
    $totalNetPay += $record['net_pay'] ?? 0;
}

$recordCount = count($payrollRecords);

// Get greeting
$currentHour = date('H');
$greeting = 'Good Evening';
if ($currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour < 18) {
    $greeting = 'Good Afternoon';
}

// Status badge mapping
$statusBadges = [
    'paid' => 'badge-paid',
    'pending' => 'badge-pending',
    'processing' => 'badge-processing'
];

$statusLabels = [
    'paid' => 'Paid',
    'pending' => 'Pending',
    'processing' => 'Processing'
];

// =============================================
// HANDLE PAYSLIP VIEWING (MODAL)
// =============================================
$viewPayrollId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewRecord = null;

if ($viewPayrollId > 0) {
    $viewRecord = getRecord("
        SELECT * FROM payroll 
        WHERE id = ? AND user_id = ?
    ", [$viewPayrollId, $userId], "ii");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Payroll - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - PAYROLL MANAGEMENT
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
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.75rem; color: var(--text-on-surface-variant); }

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

        /* =============================================
           MAIN SCROLLABLE AREA
        ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }
        .main-scroll .container { max-width: 80rem; margin: 0 auto; }

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
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

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
        .page-header h1 { font-size: 1.875rem; font-weight: 700; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.25rem; }

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
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--on-primary-fixed-variant); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--bg-surface-low); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-success:hover { background: #16a34a; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: var(--error-color); color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; border-radius: 0.5rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }

        /* =============================================
           STATS GRID
        ============================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 480px) { .stats-grid { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 1rem 1.25rem;
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
        }
        .stat-card .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .stat-card .stat-number { 
            font-size: 1.75rem; 
            font-weight: 800; 
            color: var(--text-on-surface); 
            line-height: 1.2; 
        }
        .stat-card .stat-label { 
            font-size: 0.6875rem; 
            color: var(--text-on-surface-variant); 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            font-weight: 600; 
            margin-top: 0.125rem; 
        }
        .stat-card .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.5rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
        }
        .stat-card .stat-icon.green { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .stat-card .stat-icon.red { background: rgba(220, 38, 38, 0.1); color: #dc2626; }
        .stat-card .stat-icon.yellow { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .stat-card .stat-icon.blue { background: rgba(37, 99, 235, 0.1); color: #2563eb; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.25rem; }

        /* =============================================
           PAYROLL TABLE
        ============================================= */
        .payroll-section {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .payroll-section .payroll-header {
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .payroll-section .payroll-header .payroll-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .payroll-section .payroll-body { padding: 0; overflow-x: auto; }

        .payroll-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .payroll-table thead { background: var(--bg-surface-low); }
        .payroll-table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        .payroll-table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        .payroll-table tr:last-child td { border-bottom: none; }
        .payroll-table tbody tr:hover td { background: var(--bg-surface-low); }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-processing { background: #dbeafe; color: #2563eb; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        .empty-state .material-symbols-outlined { font-size: 3rem; color: var(--slate-200); display: block; margin-bottom: 0.75rem; }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.8125rem; color: var(--text-on-surface-variant); }

        /* =============================================
           PAYSLIP MODAL
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
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 48rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .modal-header h2 .material-symbols-outlined { font-size: 1.5rem; color: var(--primary); }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }
        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-close .material-symbols-outlined { font-size: 1.5rem; }
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
            flex-shrink: 0;
        }

        /* Payslip Details */
        .payslip-header {
            background: var(--bg-surface-low);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .payslip-header .payslip-company h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }
        .payslip-header .payslip-company p {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }
        .payslip-header .payslip-period {
            text-align: right;
        }
        .payslip-header .payslip-period .period-label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .payslip-header .payslip-period .period-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .payslip-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .payslip-grid { grid-template-columns: 1fr; }
        }
        .payslip-grid .payslip-item {
            padding: 0.75rem 1rem;
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }
        .payslip-grid .payslip-item .item-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .payslip-grid .payslip-item .item-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-top: 0.125rem;
        }
        .payslip-grid .payslip-item .item-value.green { color: #22c55e; }
        .payslip-grid .payslip-item .item-value.red { color: #dc2626; }

        .payslip-breakdown {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--slate-200);
        }
        .payslip-breakdown .breakdown-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        .payslip-breakdown .breakdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        @media (max-width: 640px) {
            .payslip-breakdown .breakdown-grid { grid-template-columns: 1fr; }
        }
        .payslip-breakdown .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--slate-200);
        }
        .payslip-breakdown .breakdown-item:last-child { border-bottom: none; }
        .payslip-breakdown .breakdown-item .breakdown-label {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }
        .payslip-breakdown .breakdown-item .breakdown-value {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

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
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-xl); height: 100vh; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-xl); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .payroll-table { font-size: 0.75rem; }
            .payroll-table th, .payroll-table td { padding: 0.375rem 0.5rem; }
            .payslip-header { flex-direction: column; text-align: center; }
            .payslip-header .payslip-period { text-align: center; }
            .modal { max-height: 95vh; margin: 0.5rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.75rem 1rem; }
            .page-header h1 { font-size: 1.25rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .stat-card { padding: 0.75rem 1rem; }
            .stat-card .stat-number { font-size: 1.25rem; }
            .payroll-table { font-size: 0.6875rem; min-width: 300px; }
        }
        .main-scroll::-webkit-scrollbar { width: 6px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 3px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-500); }
    </style>
</head>
<body>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <span class="sidebar-brand-icon">
            <span class="material-symbols-outlined">account_balance</span>
        </span>
        <p class="sidebar-brand-text">Company Name</p>
        <p class="sidebar-brand-category">Employee Portal</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="profile.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">person</span>
            <span class="nav-text">My Profile</span>
        </a>
        <a href="leaves.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">beach_access</span>
            <span class="nav-text">Leaves</span>
        </a>
        <a href="attendance.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">schedule</span>
            <span class="nav-text">Attendance</span>
        </a>
        <a href="payroll.php" class="sidebar-main-link active">
            <span class="material-symbols-outlined">payments</span>
            <span class="nav-text">Payroll</span>
        </a>
        <a href="performance.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">stars</span>
            <span class="nav-text">Performance</span>
        </a>
        <a href="directory.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">group</span>
            <span class="nav-text">Directory</span>
        </a>
        <a href="announcements.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">campaign</span>
            <span class="nav-text">Announcements</span>
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

<!-- ===== MAIN CONTENT ===== -->
<div class="main-wrapper" id="mainWrapper">
    <!-- Top Header -->
    <header class="top-header">
        <div class="top-header-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu"><span class="material-symbols-outlined">menu</span></button>
            <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar"><span class="material-symbols-outlined">chevron_left</span></button>
            <span class="separator">|</span>
            <span style="font-weight:600; font-size:0.875rem;">Payroll</span>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem;">
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'E'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? 'Employee')); ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <button class="dropdown-item" onclick="window.location.href='profile.php'"><span class="material-symbols-outlined">person</span> Profile</button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'"><span class="material-symbols-outlined">logout</span> Logout</button>
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
                    <span class="material-symbols-outlined">payments</span>
                    <span>Payroll</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo $recordCount; ?> records
                    </span>
                </div>
                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                    <?php echo date('F Y'); ?>
                </span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Payroll</h1>
                    <p>View your payslips and payment history</p>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-number">₱<?php echo number_format($totalNetPay, 2); ?></div>
                            <div class="stat-label">Total Net Pay</div>
                        </div>
                        <div class="stat-icon green"><span class="material-symbols-outlined">payments</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-number">₱<?php echo number_format($totalEarned, 2); ?></div>
                            <div class="stat-label">Total Gross Pay</div>
                        </div>
                        <div class="stat-icon blue"><span class="material-symbols-outlined">trending_up</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-number">₱<?php echo number_format($totalDeductions, 2); ?></div>
                            <div class="stat-label">Total Deductions</div>
                        </div>
                        <div class="stat-icon red"><span class="material-symbols-outlined">remove_circle</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-top">
                        <div>
                            <div class="stat-number"><?php echo $recordCount; ?></div>
                            <div class="stat-label">Payslips</div>
                        </div>
                        <div class="stat-icon yellow"><span class="material-symbols-outlined">receipt_long</span></div>
                    </div>
                </div>
            </div>

            <!-- Payroll Table -->
            <div class="payroll-section">
                <div class="payroll-header">
                    <div class="payroll-title">Payroll History</div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <?php echo $recordCount; ?> payslips
                    </span>
                </div>
                <div class="payroll-body">
                    <?php if (empty($payrollRecords)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">receipt_long</span>
                            <h4>No Payroll Records</h4>
                            <p>Your payroll records will appear here once processed.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="payroll-table">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th>Gross Pay</th>
                                        <th>Deductions</th>
                                        <th>Net Pay</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payrollRecords as $record): ?>
                                        <?php
                                        $periodStart = date('M d', strtotime($record['pay_period_start'] ?? $record['pay_period_end']));
                                        $periodEnd = date('M d, Y', strtotime($record['pay_period_end']));
                                        $status = $record['status'] ?? 'paid';
                                        ?>
                                        <tr>
                                            <td>
                                                <div style="font-size:0.8125rem; font-weight:500; color:var(--text-on-surface);">
                                                    <?php echo $periodStart; ?> - <?php echo $periodEnd; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-weight:600; color:var(--text-on-surface);">
                                                    ₱<?php echo number_format($record['gross_pay'] ?? 0, 2); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-weight:600; color:#dc2626;">
                                                    -₱<?php echo number_format($record['total_deductions'] ?? 0, 2); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-weight:700; color:#22c55e; font-size:1rem;">
                                                    ₱<?php echo number_format($record['net_pay'] ?? 0, 2); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $statusBadges[$status] ?? 'badge-paid'; ?>">
                                                    <?php echo $statusLabels[$status] ?? ucfirst($status); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;">
                                                <button class="btn btn-primary btn-sm" onclick="viewPayslip(<?php echo $record['id']; ?>)">
                                                    <span class="material-symbols-outlined">receipt_long</span>
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- =============================================
PAYSLIP MODAL
============================================= -->
<div class="modal-overlay" id="payslipModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">receipt_long</span>
                Payslip
            </h2>
            <button class="modal-close" onclick="closeModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="payslipModalBody">
            <div id="payslipContent">
                <!-- Payslip content will be loaded via AJAX -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal()">Close</button>
            <button class="btn btn-primary" id="downloadPayslipBtn" style="display:none;" onclick="downloadPayslip()">
                <span class="material-symbols-outlined">download</span>
                Download PDF
            </button>
        </div>
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
    // 4. VIEW PAYSLIP
    // =============================================
    function viewPayslip(id) {
        const modal = document.getElementById('payslipModal');
        const content = document.getElementById('payslipContent');
        const downloadBtn = document.getElementById('downloadPayslipBtn');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        content.innerHTML = '<div style="text-align:center; padding:2rem;"><div class="spinner" style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div><p style="margin-top:0.5rem; color:var(--text-on-surface-variant);">Loading payslip...</p></div>';
        downloadBtn.style.display = 'none';

        // Fetch payslip data
        fetch('ajax/get_payslip.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                content.innerHTML = data.html;
                downloadBtn.style.display = 'flex';
                downloadBtn.dataset.id = id;
            } else {
                content.innerHTML = `
                    <div style="text-align:center; padding:2rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:3rem;">error</span>
                        <p style="margin-top:0.5rem;">${data.error || 'Failed to load payslip.'}</p>
                    </div>
                `;
                downloadBtn.style.display = 'none';
            }
        })
        .catch(error => {
            content.innerHTML = `
                <div style="text-align:center; padding:2rem; color:#dc2626;">
                    <span class="material-symbols-outlined" style="font-size:3rem;">error</span>
                    <p style="margin-top:0.5rem;">Error loading payslip. Please try again.</p>
                </div>
            `;
            downloadBtn.style.display = 'none';
        });
    }

    // =============================================
    // 5. CLOSE MODAL
    // =============================================
    function closeModal() {
        const modal = document.getElementById('payslipModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close modal on overlay click
    document.getElementById('payslipModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // =============================================
    // 6. DOWNLOAD PAYSLIP (Placeholder)
    // =============================================
    function downloadPayslip() {
        const id = document.getElementById('downloadPayslipBtn').dataset.id;
        if (id) {
            window.location.href = 'ajax/download_payslip.php?id=' + id;
        }
    }

    // =============================================
    // 7. TOAST SYSTEM
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
    // 8. RESPONSIVE HANDLING
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
    // 9. KEYBOARD ACCESSIBILITY
    // =============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileSidebar();
            profileToggle.classList.remove('open');
            profileMenu.classList.remove('open');
        }
    });

    console.log('Payroll Management loaded successfully.');
</script>
</body>
</html>