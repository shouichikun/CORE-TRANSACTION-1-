<?php
// portals/client/invoices.php - Client Invoice Management
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
// GET INVOICES
// =============================================
$invoices = getRecords("
    SELECT * FROM invoices 
    WHERE client_id = ? 
    ORDER BY invoice_date DESC
", [$clientId], "i");

// =============================================
// CALCULATE SUMMARY STATS
// =============================================
$totalInvoices = count($invoices);
$totalPaid = 0;
$totalPending = 0;
$totalOverdue = 0;
$totalAmount = 0;
$totalPaidAmount = 0;

foreach ($invoices as $invoice) {
    $totalAmount += $invoice['amount'] ?? 0;
    
    if ($invoice['status'] === 'paid') {
        $totalPaid++;
        $totalPaidAmount += $invoice['amount'] ?? 0;
    } elseif ($invoice['status'] === 'overdue') {
        $totalOverdue++;
    } else {
        $totalPending++;
    }
}

// =============================================
// GET FILTER PARAMETERS
// =============================================
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Apply filters
$filteredInvoices = $invoices;

if ($statusFilter !== 'all') {
    $filteredInvoices = array_filter($filteredInvoices, function($inv) use ($statusFilter) {
        return ($inv['status'] ?? '') === $statusFilter;
    });
}

if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $filteredInvoices = array_filter($filteredInvoices, function($inv) use ($searchLower) {
        return strpos(strtolower($inv['invoice_number'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($inv['description'] ?? ''), $searchLower) !== false;
    });
}

// Status badge mapping
$statusBadges = [
    'paid' => 'badge-paid',
    'pending' => 'badge-pending',
    'overdue' => 'badge-overdue'
];

$statusLabels = [
    'paid' => 'Paid',
    'pending' => 'Pending',
    'overdue' => 'Overdue'
];

// Get greeting
$currentHour = date('H');
$greeting = 'Good Evening';
if ($currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour < 18) {
    $greeting = 'Good Afternoon';
}

// =============================================
// HANDLE INVOICE VIEW (MODAL)
// =============================================
$viewInvoiceId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewInvoice = null;

if ($viewInvoiceId > 0) {
    $viewInvoice = getRecord("
        SELECT * FROM invoices 
        WHERE id = ? AND client_id = ?
    ", [$viewInvoiceId, $clientId], "ii");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Invoices - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           INVOICES - CLEAN PROFESSIONAL UI
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
           STATS ROW
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
        .stat-card .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.red { background: #fee2e2; color: #dc2626; }
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
           FILTERS
        ============================================= */
        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            align-items: center;
        }
        .filter-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .filter-btn {
            padding: 0.375rem 1rem;
            border-radius: var(--radius-full);
            border: 1px solid var(--slate-200);
            background: var(--bg-surface);
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .filter-btn:hover { background: var(--bg-surface-low); border-color: var(--slate-300); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .filter-btn .count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.05rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.6rem;
            margin-left: 0.25rem;
        }
        .filter-btn.active .count { background: rgba(255, 255, 255, 0.25); }

        .search-box {
            display: flex;
            align-items: center;
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            padding: 0.25rem 0.5rem;
            transition: all var(--transition-fast);
            flex: 1;
            min-width: 200px;
            max-width: 300px;
            margin-left: auto;
        }
        .search-box:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .search-box .material-symbols-outlined { color: var(--text-on-surface-variant); font-size: 1.25rem; }
        .search-box input {
            border: none;
            outline: none;
            padding: 0.375rem 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            background: transparent;
            width: 100%;
            color: var(--text-on-surface);
        }
        .search-box input::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }

        /* =============================================
           INVOICE TABLE
        ============================================= */
        .invoice-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
        }
        .invoice-card .invoice-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .invoice-card .invoice-header .invoice-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .invoice-card .invoice-body { padding: 0; overflow-x: auto; }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .invoice-table thead { background: var(--bg-surface-low); }
        .invoice-table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        .invoice-table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        .invoice-table tr:last-child td { border-bottom: none; }
        .invoice-table tbody tr:hover td { background: var(--bg-surface-low); }

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
        .badge-overdue { background: #fee2e2; color: #dc2626; }

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
           INVOICE DETAILS MODAL
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
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-header h2 .material-symbols-outlined { font-size: 1.25rem; color: var(--primary); }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.375rem;
            border-radius: 0.375rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }
        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-close .material-symbols-outlined { font-size: 1.25rem; }
        .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .invoice-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .invoice-detail-item {
            padding: 0.5rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            border: 1px solid var(--slate-200);
        }
        .invoice-detail-item .label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .invoice-detail-item .value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-top: 0.125rem;
        }
        .invoice-detail-item .value.green { color: #059669; }
        .invoice-detail-item .value.red { color: #dc2626; }
        .invoice-detail-item .value.yellow { color: #d97706; }

        .invoice-items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            margin-top: 1rem;
        }
        .invoice-items-table th {
            padding: 0.5rem 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        .invoice-items-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--slate-100);
        }
        .invoice-items-table tr:last-child td { border-bottom: none; }

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
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; margin-left: 0; }
            .invoice-table { font-size: 0.75rem; }
            .invoice-table th, .invoice-table td { padding: 0.375rem 0.5rem; }
            .invoice-detail-grid { grid-template-columns: 1fr; }
            .modal { max-height: 95vh; margin: 0.5rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .invoice-table { font-size: 0.6875rem; min-width: 300px; }
            .invoice-detail-grid { grid-template-columns: 1fr; }
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
    <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">dashboard</span>
        <span class="nav-text">Dashboard</span>
    </a>
    <a href="jobs.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'jobs.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">work</span>
        <span class="nav-text">My Jobs</span>
    </a>
    <a href="agency_application.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'agency_applications.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">apartment</span>
        <span class="nav-text">Agencies</span>
        <?php if ($pendingAgencyCount > 0): ?>
            <span class="nav-badge"><?php echo $pendingAgencyCount; ?></span>
        <?php endif; ?>
    </a>
    <a href="employees.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">people</span>
        <span class="nav-text">Employees</span>
    </a>
    <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">person_search</span>
        <span class="nav-text">Applicants</span>
    </a>
    <a href="invoices.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">receipt</span>
        <span class="nav-text">Invoices</span>
    </a>
    <a href="support.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'support.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">support_agent</span>
        <span class="nav-text">Support</span>
    </a>
    <a href="reports.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">analytics</span>
        <span class="nav-text">Reports</span>
    </a>
    <div class="nav-label" style="margin-top:1rem;">Settings</div>
    <a href="profile.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
        <span class="material-symbols-outlined">person</span>
        <span class="nav-text">Profile</span>
    </a>
    <a href="settings.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Invoices</span>
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
                        <span class="material-symbols-outlined">receipt</span>
                        <span>Invoices</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta"><?php echo $totalInvoices; ?> invoices</span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Invoices</h1>
                        <p>View and manage your billing history</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <span class="material-symbols-outlined">receipt_long</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalInvoices; ?></div>
                            <div class="stat-label">Total Invoices</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalPaid; ?></div>
                            <div class="stat-label">Paid</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">hourglass_top</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalPending; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <span class="material-symbols-outlined">error</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalOverdue; ?></div>
                            <div class="stat-label">Overdue</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-bar">
                    <div class="filter-group">
                        <a href="?status=all" class="filter-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                            All <span class="count"><?php echo $totalInvoices; ?></span>
                        </a>
                        <a href="?status=paid" class="filter-btn <?php echo $statusFilter === 'paid' ? 'active' : ''; ?>">
                            Paid <span class="count"><?php echo $totalPaid; ?></span>
                        </a>
                        <a href="?status=pending" class="filter-btn <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                            Pending <span class="count"><?php echo $totalPending; ?></span>
                        </a>
                        <a href="?status=overdue" class="filter-btn <?php echo $statusFilter === 'overdue' ? 'active' : ''; ?>">
                            Overdue <span class="count"><?php echo $totalOverdue; ?></span>
                        </a>
                    </div>
                    <form method="GET" class="search-box">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <span class="material-symbols-outlined">search</span>
                        <input type="text" name="search" placeholder="Search invoice # or description..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </form>
                </div>

                <!-- Invoice Table -->
                <div class="invoice-card">
                    <div class="invoice-header">
                        <div class="invoice-title">Invoices</div>
                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                            <?php echo count($filteredInvoices); ?> invoices
                        </span>
                    </div>
                    <div class="invoice-body">
                        <?php if (empty($filteredInvoices)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">receipt_long</span>
                                <h3>No invoices found</h3>
                                <p>
                                    <?php if ($statusFilter !== 'all' || !empty($searchQuery)): ?>
                                        No invoices match your current filters.
                                        <a href="invoices.php" style="color:var(--primary); font-weight:600;">Clear filters</a>
                                    <?php else: ?>
                                        You don't have any invoices yet.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x:auto;">
                                <table class="invoice-table">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th style="text-align:center;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($filteredInvoices as $invoice): ?>
                                            <tr>
                                                <td>
                                                    <span style="font-weight:600; color:var(--text-on-surface);">
                                                        #<?php echo htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($invoice['invoice_date'] ?? 'now')); ?>
                                                </td>
                                                <td>
                                                    <div style="font-size:0.8125rem; color:var(--text-on-surface-variant); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                        <?php echo htmlspecialchars($invoice['description'] ?? '—'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span style="font-weight:700; color:var(--text-on-surface);">
                                                        ₱<?php echo number_format($invoice['amount'] ?? 0, 2); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $statusBadges[$invoice['status']] ?? 'badge-pending'; ?>">
                                                        <?php echo $statusLabels[$invoice['status']] ?? ucfirst($invoice['status']); ?>
                                                    </span>
                                                </td>
                                                <td style="text-align:center;">
                                                    <button class="btn btn-primary btn-sm" onclick="viewInvoice(<?php echo $invoice['id']; ?>)">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                        View
                                                    </button>
                                                    <button class="btn btn-outline btn-sm" onclick="downloadInvoice(<?php echo $invoice['id']; ?>)">
                                                        <span class="material-symbols-outlined">download</span>
                                                        Download
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
    INVOICE DETAILS MODAL
    ============================================= -->
    <div class="modal-overlay" id="invoiceModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">receipt_long</span>
                    Invoice Details
                </h2>
                <button class="modal-close" onclick="closeInvoiceModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body" id="invoiceModalBody">
                <div id="invoiceContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeInvoiceModal()">Close</button>
                <button class="btn btn-primary" id="downloadInvoiceBtn" style="display:none;" onclick="downloadInvoiceFromModal()">
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
        // 4. VIEW INVOICE
        // =============================================
        function viewInvoice(id) {
            const modal = document.getElementById('invoiceModal');
            const content = document.getElementById('invoiceContent');
            const downloadBtn = document.getElementById('downloadInvoiceBtn');

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            content.innerHTML = '<div style="text-align:center; padding:2rem;"><div class="spinner" style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div><p style="margin-top:0.5rem; color:var(--text-on-surface-variant);">Loading invoice...</p></div>';
            downloadBtn.style.display = 'none';

            // Fetch invoice data
            fetch('ajax/get_invoice.php?id=' + id)
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
                            <p style="margin-top:0.5rem;">${data.error || 'Failed to load invoice.'}</p>
                        </div>
                    `;
                    downloadBtn.style.display = 'none';
                }
            })
            .catch(error => {
                content.innerHTML = `
                    <div style="text-align:center; padding:2rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:3rem;">error</span>
                        <p style="margin-top:0.5rem;">Error loading invoice. Please try again.</p>
                    </div>
                `;
                downloadBtn.style.display = 'none';
            });
        }

        // =============================================
        // 5. CLOSE INVOICE MODAL
        // =============================================
        function closeInvoiceModal() {
            const modal = document.getElementById('invoiceModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal on overlay click
        document.getElementById('invoiceModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeInvoiceModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeInvoiceModal();
            }
        });

        // =============================================
        // 6. DOWNLOAD INVOICE
        // =============================================
        function downloadInvoice(id) {
            window.location.href = 'ajax/download_invoice.php?id=' + id;
        }

        function downloadInvoiceFromModal() {
            const id = document.getElementById('downloadInvoiceBtn').dataset.id;
            if (id) {
                downloadInvoice(id);
            }
        }

        // =============================================
        // 7. RESPONSIVE HANDLING
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

        console.log('📄 Invoices loaded successfully!');
    </script>
</body>
</html>