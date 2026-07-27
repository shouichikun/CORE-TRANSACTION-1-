<?php
// portals/client/employees.php - Client Employee Management
session_start();

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
$role = $_SESSION['role'] ?? 'client'; // ADD THIS LINE

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

// Handle Employee Status Update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_employee_status') {
        $deploymentId = intval($_POST['deployment_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'active';
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        if ($deploymentId > 0) {
            $updateSql = "UPDATE deployments SET status = ?, end_date = ?, updated_at = NOW() 
                          WHERE id = ? AND client_id = ?";
            $stmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($stmt, 'ssii', $newStatus, $endDate, $deploymentId, $clientId);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Employee status updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error updating employee status.';
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Handle Bulk Export
    if ($_POST['action'] === 'export_employees') {
        // This will be handled below
    }
}

// Get all employees deployed to this client
$employeesSql = "SELECT d.*, 
                 u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
                 jo.title as job_title,
                 jo.id as job_id
                 FROM deployments d
                 JOIN users u ON d.employee_id = u.id
                 JOIN job_orders jo ON d.job_order_id = jo.id
                 WHERE d.client_id = ?
                 ORDER BY d.status = 'active' DESC, d.created_at DESC";

$stmt = mysqli_prepare($conn, $employeesSql);
mysqli_stmt_bind_param($stmt, 'i', $clientId);
mysqli_stmt_execute($stmt);
$employeesResult = mysqli_stmt_get_result($stmt);
$employees = [];
while ($row = mysqli_fetch_assoc($employeesResult)) {
    $employees[] = $row;
}
mysqli_stmt_close($stmt);

// Get status counts for filter
$statusCounts = [
    'all' => count($employees),
    'active' => 0,
    'on_hold' => 0,
    'terminated' => 0,
    'completed' => 0
];

foreach ($employees as $emp) {
    $status = $emp['status'] ?? 'active';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$filteredEmployees = $employees;
if ($filter !== 'all') {
    $filteredEmployees = array_filter($employees, function($emp) use ($filter) {
        return ($emp['status'] ?? '') === $filter;
    });
}

// Get search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search)) {
    $filteredEmployees = array_filter($filteredEmployees, function($emp) use ($search) {
        $searchLower = strtolower($search);
        return strpos(strtolower($emp['first_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($emp['last_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($emp['email'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($emp['job_title'] ?? ''), $searchLower) !== false;
    });
}

// Calculate summary stats
$totalActive = $statusCounts['active'];
$totalOnHold = $statusCounts['on_hold'];
$totalTerminated = $statusCounts['terminated'];
$totalCompleted = $statusCounts['completed'];

// Get recent deployments (last 30 days)
$recentCount = 0;
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
foreach ($employees as $emp) {
    if (strtotime($emp['created_at']) >= strtotime($thirtyDaysAgo)) {
        $recentCount++;
    }
}

// Get job distribution for chart
$jobDistribution = [];
foreach ($employees as $emp) {
    $jobTitle = $emp['job_title'] ?? 'Unknown';
    if (!isset($jobDistribution[$jobTitle])) {
        $jobDistribution[$jobTitle] = 0;
    }
    $jobDistribution[$jobTitle]++;
}
arsort($jobDistribution);
$jobDistribution = array_slice($jobDistribution, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Employees - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* Base styles - Same as previous */
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

        /* Sidebar - Same as before */
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
            font-size: 0.8125rem;
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
        }
        .sidebar-footer .logout-btn:hover { background: #fef2f2; }
        .sidebar-footer .logout-btn .material-symbols-outlined { font-size: 1.125rem; }

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

        /* Breadcrumb */
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
        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-info { background: #2563eb; color: white; }
        .btn-info:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* Toast */
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

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            width: 3rem;
            height: 3rem;
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
        .stat-card .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.5rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-number.primary { color: var(--primary); }
        .stat-card .stat-number.green { color: #059669; }
        .stat-card .stat-number.yellow { color: #d97706; }
        .stat-card .stat-number.red { color: #dc2626; }
        .stat-card .stat-number.blue { color: #2563eb; }
        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
        }

        /* Filters and Search */
        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            align-items: center;
        }
        .filter-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
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
        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
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
            max-width: 350px;
        }
        .search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .search-box .material-symbols-outlined {
            color: var(--text-on-surface-variant);
            font-size: 1.25rem;
        }
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
        .search-box input::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        /* Employee Cards */
        .employee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
        }
        .employee-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-xs);
        }
        .employee-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--slate-300);
            transform: translateY(-2px);
        }
        .employee-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .employee-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .employee-avatar {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
            flex-shrink: 0;
        }
        .employee-name {
            font-weight: 700;
            font-size: 0.9375rem;
            color: var(--text-on-surface);
        }
        .employee-email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .employee-details {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
        }
        .employee-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            padding: 0.25rem 0;
        }
        .employee-detail-item .material-symbols-outlined {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
        }
        .employee-detail-item strong {
            color: var(--text-on-surface);
            font-weight: 600;
        }
        .employee-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.875rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .employee-status-select {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.6875rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
        }
        .employee-status-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-active { background: #d1fae5; color: #059669; }
        .badge-on_hold { background: #fef3c7; color: #d97706; }
        .badge-terminated { background: #fee2e2; color: #dc2626; }
        .badge-completed { background: #dbeafe; color: #2563eb; }

        .badge-lg {
            padding: 0.25rem 0.75rem;
            font-size: 0.6875rem;
        }

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

        /* Job Distribution */
        .distribution-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-xs);
        }
        .distribution-card h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        .distribution-bar {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .distribution-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .distribution-item .label {
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            min-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .distribution-item .bar-track {
            flex: 1;
            height: 0.5rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-full);
            overflow: hidden;
        }
        .distribution-item .bar-fill {
            height: 100%;
            background: var(--primary);
            border-radius: var(--radius-full);
            transition: width 0.6s ease;
        }
        .distribution-item .count {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            min-width: 2rem;
            text-align: right;
        }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .employee-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .employee-grid { grid-template-columns: 1fr; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
    /* Profile Picture Styles */
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

.avatar-img-large {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

/* Sidebar user card with profile picture */
.sidebar-footer .user-card .avatar-img {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
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
            <a href="dashboard.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="jobs.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
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
      <?php
// Get user profile data for sidebar
$userProfile = getUserProfileData($userId);
?>
<!-- Sidebar Footer -->
<div class="sidebar-footer">
    <div class="user-card">
        <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                 alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                 class="avatar-img" 
                 style="width:2.25rem; height:2.25rem; border-radius:50%; object-fit:cover; flex-shrink:0;">
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Dashboard</span>
            </div>
         <?php
// Get user profile data for dropdown
$userProfile = getUserProfileData($userId);
?>
<div class="profile-dropdown-wrapper">
    <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
        <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                 alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                 class="avatar-small" 
                 style="width:2rem; height:2rem; border-radius:50%; object-fit:cover; flex-shrink:0; background:var(--primary);">
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
                <!-- Toast Messages -->
                <?php if ($message): ?>
                    <div class="toast <?php echo $messageType; ?>" id="toastMessage">
                        <span class="material-symbols-outlined">
                            <?php echo $messageType === 'success' ? 'check_circle' : ($messageType === 'error' ? 'error' : 'info'); ?>
                        </span>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                    <script>
                        setTimeout(() => {
                            const toast = document.getElementById('toastMessage');
                            if (toast) toast.remove();
                        }, 5000);
                    </script>
                <?php endif; ?>

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">people</span>
                        <span>Employee Management</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Total Employees: <?php echo count($employees); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.25rem;">
                    <div>
                        <h1 style="font-size:1.75rem; font-weight:800; color:var(--text-on-surface); letter-spacing:-0.025em;">My Employees</h1>
                        <p style="font-size:0.875rem; color:var(--text-on-surface-variant); margin-top:0.125rem;">Manage your deployed workforce</p>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <a href="?export=csv" class="btn btn-outline btn-sm">
                            <span class="material-symbols-outlined" style="font-size:0.875rem;">download</span>
                            Export
                        </a>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number green"><?php echo $totalActive; ?></div>
                            <div class="stat-label">Active Employees</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">pause_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number yellow"><?php echo $totalOnHold; ?></div>
                            <div class="stat-label">On Hold</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <span class="material-symbols-outlined">new_releases</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number blue"><?php echo $recentCount; ?></div>
                            <div class="stat-label">New (30 days)</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <span class="material-symbols-outlined">person_off</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number red"><?php echo $totalTerminated + $totalCompleted; ?></div>
                            <div class="stat-label">Inactive</div>
                        </div>
                    </div>
                </div>

                <!-- Job Distribution -->
                <?php if (!empty($jobDistribution)): ?>
                <div class="distribution-card">
                    <h3>Job Distribution</h3>
                    <div class="distribution-bar">
                        <?php 
                        $maxCount = max($jobDistribution);
                        foreach ($jobDistribution as $jobTitle => $count): 
                            $percentage = $maxCount > 0 ? ($count / $maxCount) * 100 : 0;
                        ?>
                        <div class="distribution-item">
                            <span class="label"><?php echo htmlspecialchars($jobTitle); ?></span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                            </div>
                            <span class="count"><?php echo $count; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filters and Search -->
                <div class="filters-bar">
                    <div class="filter-group">
                        <a href="?filter=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            All <span class="count"><?php echo $statusCounts['all']; ?></span>
                        </a>
                        <a href="?filter=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'active' ? 'active' : ''; ?>">
                            Active <span class="count"><?php echo $statusCounts['active']; ?></span>
                        </a>
                        <a href="?filter=on_hold<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'on_hold' ? 'active' : ''; ?>">
                            On Hold <span class="count"><?php echo $statusCounts['on_hold']; ?></span>
                        </a>
                        <a href="?filter=terminated<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'terminated' ? 'active' : ''; ?>">
                            Terminated <span class="count"><?php echo $statusCounts['terminated']; ?></span>
                        </a>
                        <a href="?filter=completed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'completed' ? 'active' : ''; ?>">
                            Completed <span class="count"><?php echo $statusCounts['completed']; ?></span>
                        </a>
                    </div>
                    <form method="GET" class="search-box" style="display:flex; align-items:center; background:var(--bg-surface); border:1.5px solid var(--slate-200); border-radius:0.5rem; padding:0.25rem 0.5rem; transition:all var(--transition-fast); flex:1; min-width:200px; max-width:350px; margin-left:auto;">
                        <span class="material-symbols-outlined" style="color:var(--text-on-surface-variant); font-size:1.25rem;">search</span>
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="text" name="search" placeholder="Search employees..." value="<?php echo htmlspecialchars($search); ?>" style="border:none; outline:none; padding:0.375rem 0.5rem; font-size:0.8125rem; font-family:var(--font-sans); background:transparent; width:100%; color:var(--text-on-surface);">
                    </form>
                </div>

                <!-- Employee Grid -->
                <?php if (empty($filteredEmployees)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">people_outline</span>
                        <h3>No employees found</h3>
                        <p>
                            <?php if ($filter !== 'all' || !empty($search)): ?>
                                No employees match your current filters.
                                <a href="employees.php" style="color:var(--primary); font-weight:600;">Clear filters</a>
                            <?php else: ?>
                                You don't have any employees deployed yet. 
                                <a href="jobs.php" style="color:var(--primary); font-weight:600;">Post a job</a> to start hiring.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="employee-grid">
                        <?php foreach ($filteredEmployees as $emp): ?>
                            <div class="employee-card">
                                <div class="employee-card-header">
                                    <div class="employee-info">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($emp['first_name'] ?? 'E', 0, 1) . substr($emp['last_name'] ?? '', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="employee-name">
                                                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                            </div>
                                            <div class="employee-email"><?php echo htmlspecialchars($emp['email']); ?></div>
                                        </div>
                                    </div>
                                    <span class="badge badge-<?php echo $emp['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $emp['status'])); ?>
                                    </span>
                                </div>
                                
                                <div class="employee-details">
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">work</span>
                                        <strong>Position:</strong> <?php echo htmlspecialchars($emp['job_title']); ?>
                                    </div>
                                    <?php if (!empty($emp['phone'])): ?>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">phone</span>
                                        <strong>Phone:</strong> <?php echo htmlspecialchars($emp['phone']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">calendar_today</span>
                                        <strong>Started:</strong> <?php echo date('M d, Y', strtotime($emp['start_date'] ?? 'now')); ?>
                                    </div>
                                    <?php if (!empty($emp['end_date'])): ?>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">calendar_today</span>
                                        <strong>End Date:</strong> <?php echo date('M d, Y', strtotime($emp['end_date'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="employee-footer">
                                    <form method="POST" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; width:100%;">
                                        <input type="hidden" name="action" value="update_employee_status">
                                        <input type="hidden" name="deployment_id" value="<?php echo $emp['id']; ?>">
                                        <select name="new_status" class="employee-status-select" style="flex:1; min-width:100px;">
                                            <option value="active" <?php echo $emp['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="on_hold" <?php echo $emp['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="terminated" <?php echo $emp['status'] === 'terminated' ? 'selected' : ''; ?>>Terminate</option>
                                            <option value="completed" <?php echo $emp['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        </select>
                                        <?php if ($emp['status'] === 'terminated' || $emp['status'] === 'completed'): ?>
                                            <input type="date" name="end_date" class="employee-status-select" value="<?php echo $emp['end_date'] ?? date('Y-m-d'); ?>" style="min-width:120px;">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-primary" style="white-space:nowrap;">
                                            <span class="material-symbols-outlined" style="font-size:0.875rem;">update</span>
                                            Update
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // =============================================
        // SIDEBAR TOGGLE
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

        mobileMenuBtn.addEventListener('click', openMobileSidebar);
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);

        // =============================================
        // PROFILE DROPDOWN
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
        // KEYBOARD SHORTCUTS
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
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

        console.log('👥 ISMERS Employee Management loaded successfully!');
    </script>

</body>
</html>