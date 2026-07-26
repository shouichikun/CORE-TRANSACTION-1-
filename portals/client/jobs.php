<?php
// portals/client/jobs.php - Client Job Management
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

// Handle Job Posting
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'post_job') {
        // Validate inputs
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $job_type = trim($_POST['job_type'] ?? 'full-time');
        $salary_min = floatval($_POST['salary_min'] ?? 0);
        $salary_max = floatval($_POST['salary_max'] ?? 0);
        $positions = intval($_POST['positions'] ?? 1);
        $status = 'open';
        
        // Validate required fields
        if (empty($title) || empty($description) || empty($requirements) || empty($location)) {
            $message = 'Please fill in all required fields.';
            $messageType = 'error';
        } else {
            // Insert job
            $insertSql = "INSERT INTO job_orders (
                client_id, title, description, requirements, location, 
                job_type, salary_min, salary_max, positions, status, 
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = mysqli_prepare($conn, $insertSql);
            mysqli_stmt_bind_param($stmt, 'isssssddss', 
                $clientId, $title, $description, $requirements, $location,
                $job_type, $salary_min, $salary_max, $positions, $status
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Job posted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error posting job: ' . mysqli_error($conn);
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Handle Job Status Update (Close/Reopen)
    if ($_POST['action'] === 'update_status') {
        $jobId = intval($_POST['job_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'closed';
        
        if ($jobId > 0) {
            $updateSql = "UPDATE job_orders SET status = ?, updated_at = NOW() 
                          WHERE id = ? AND client_id = ?";
            $stmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($stmt, 'sii', $newStatus, $jobId, $clientId);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Job status updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error updating job status.';
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get all jobs for this client
$jobsSql = "SELECT j.*, 
            (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id) as applicant_count
            FROM job_orders j
            WHERE j.client_id = ?
            ORDER BY j.created_at DESC";
$stmt = mysqli_prepare($conn, $jobsSql);
mysqli_stmt_bind_param($stmt, 'i', $clientId);
mysqli_stmt_execute($stmt);
$jobsResult = mysqli_stmt_get_result($stmt);
$jobs = [];
while ($row = mysqli_fetch_assoc($jobsResult)) {
    $jobs[] = $row;
}
mysqli_stmt_close($stmt);

// Get status counts for filter
$statusCounts = [
    'all' => count($jobs),
    'open' => 0,
    'ongoing' => 0,
    'closed' => 0,
    'on_hold' => 0
];

foreach ($jobs as $job) {
    $status = $job['status'] ?? 'closed';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$filteredJobs = $jobs;
if ($filter !== 'all') {
    $filteredJobs = array_filter($jobs, function($job) use ($filter) {
        return ($job['status'] ?? '') === $filter;
    });
}

// Get job types for dropdown
$jobTypes = ['full-time', 'part-time', 'contract', 'temporary', 'internship', 'freelance'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Jobs - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* Same base styles as dashboard.php */
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

        /* Sidebar Styles - Same as dashboard */
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

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) {
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
        }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.125rem; }

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
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* Toast Message */
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
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 720px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalIn 0.3s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 { font-size: 1.25rem; font-weight: 700; }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-on-surface-variant);
            padding: 0.25rem;
            border-radius: 0.375rem;
            transition: all var(--transition-fast);
        }
        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-body { padding: 1.5rem; }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.375rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-control::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }
        textarea.form-control { resize: vertical; min-height: 100px; }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5168' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.75rem center; padding-right: 2.5rem; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }

        /* Job Cards */
        .job-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
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

        .job-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-xs);
        }
        .job-card:hover { box-shadow: var(--shadow-md); border-color: var(--slate-300); }
        .job-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .job-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }
        .job-card-title a { color: var(--primary); }
        .job-card-title a:hover { text-decoration: underline; }
        .job-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .job-card-meta .material-symbols-outlined { font-size: 0.875rem; vertical-align: middle; }
        .job-card-description {
            margin-top: 0.625rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .job-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.875rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .job-card-stats {
            display: flex;
            gap: 1.25rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .job-card-stats span { display: flex; align-items: center; gap: 0.25rem; }
        .job-card-stats .material-symbols-outlined { font-size: 0.875rem; }
        .job-card-actions { display: flex; gap: 0.375rem; }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-open { background: #dbeafe; color: #2563eb; }
        .badge-ongoing { background: #e0e7ff; color: #4f46e5; }
        .badge-closed { background: #f1f5f9; color: #64748b; }
        .badge-on_hold { background: #fef3c7; color: #d97706; }

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

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-sm); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
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
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .job-card-header { flex-direction: column; }
            .job-card-footer { flex-direction: column; align-items: stretch; }
            .job-card-actions { justify-content: flex-end; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
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
            <a href="jobs.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
                <?php if ($statusCounts['open'] > 0): ?>
                    <span class="nav-badge"><?php echo $statusCounts['open']; ?></span>
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
        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'C'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
            <a href="../../logout.php" class="logout-btn">
                <span class="material-symbols-outlined">logout</span>
                <span class="logout-text">Logout</span>
            </a>
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">My Jobs</span>
            </div>
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'C'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Client</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <button class="dropdown-item" onclick="window.location.href='profile.php'">
                        <span class="material-symbols-outlined">person</span> Profile
                    </button>
                    <button class="dropdown-item" onclick="window.location.href='settings.php'">
                        <span class="material-symbols-outlined">settings</span> Settings
                    </button>
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
                            <?php echo $messageType === 'success' ? 'check_circle' : 'error'; ?>
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
                        <span class="material-symbols-outlined">work</span>
                        <span>Job Management</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Total Jobs: <?php echo count($jobs); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>My Jobs</h1>
                        <p>Manage your job postings and track applications</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="openModal()">
                            <span class="material-symbols-outlined">add</span>
                            Post New Job
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="job-filters">
                    <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $statusCounts['all']; ?></span>
                    </a>
                    <a href="?filter=open" class="filter-btn <?php echo $filter === 'open' ? 'active' : ''; ?>">
                        Open <span class="count"><?php echo $statusCounts['open']; ?></span>
                    </a>
                    <a href="?filter=ongoing" class="filter-btn <?php echo $filter === 'ongoing' ? 'active' : ''; ?>">
                        Ongoing <span class="count"><?php echo $statusCounts['ongoing']; ?></span>
                    </a>
                    <a href="?filter=on_hold" class="filter-btn <?php echo $filter === 'on_hold' ? 'active' : ''; ?>">
                        On Hold <span class="count"><?php echo $statusCounts['on_hold']; ?></span>
                    </a>
                    <a href="?filter=closed" class="filter-btn <?php echo $filter === 'closed' ? 'active' : ''; ?>">
                        Closed <span class="count"><?php echo $statusCounts['closed']; ?></span>
                    </a>
                </div>

                <!-- Job Listings -->
                <?php if (empty($filteredJobs)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">work_off</span>
                        <h3>No jobs found</h3>
                        <p>
                            <?php if ($filter !== 'all'): ?>
                                No jobs with status "<?php echo htmlspecialchars($filter); ?>".
                                <a href="?filter=all" style="color:var(--primary); font-weight:600;">View all jobs</a>
                            <?php else: ?>
                                You haven't posted any jobs yet. Click the "Post New Job" button to get started.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($filteredJobs as $job): ?>
                        <div class="job-card">
                            <div class="job-card-header">
                                <div>
                                    <div class="job-card-title">
                                        <a href="job-details.php?id=<?php echo $job['id']; ?>">
                                            <?php echo htmlspecialchars($job['title']); ?>
                                        </a>
                                    </div>
                                    <div class="job-card-meta">
                                        <span>
                                            <span class="material-symbols-outlined">location_on</span>
                                            <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                                        </span>
                                        <span>
                                            <span class="material-symbols-outlined">work</span>
                                            <?php echo ucfirst(str_replace('_', ' ', $job['job_type'] ?? 'Full-time')); ?>
                                        </span>
                                        <?php if ($job['salary_min'] > 0 || $job['salary_max'] > 0): ?>
                                            <span>
                                                <span class="material-symbols-outlined">payments</span>
                                                ₱<?php echo number_format($job['salary_min']); ?> - ₱<?php echo number_format($job['salary_max']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span>
                                            <span class="material-symbols-outlined">calendar_today</span>
                                            Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <span class="badge badge-<?php echo $job['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $job['status'])); ?>
                                </span>
                            </div>

                            <div class="job-card-description">
                                <?php echo htmlspecialchars(substr($job['description'] ?? '', 0, 200)) . '...'; ?>
                            </div>

                            <div class="job-card-footer">
                                <div class="job-card-stats">
                                    <span>
                                        <span class="material-symbols-outlined">person_search</span>
                                        <?php echo $job['applicant_count'] ?? 0; ?> Applicants
                                    </span>
                                    <span>
                                        <span class="material-symbols-outlined">people</span>
                                        <?php echo $job['positions'] ?? 1; ?> Positions
                                    </span>
                                </div>
                                <div class="job-card-actions">
                                    <a href="job-details.php?id=<?php echo $job['id']; ?>" class="btn btn-sm btn-outline">
                                        <span class="material-symbols-outlined">visibility</span>
                                        View
                                    </a>
                                    <?php if ($job['status'] === 'open' || $job['status'] === 'ongoing'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Close this job posting?')">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <input type="hidden" name="new_status" value="closed">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <span class="material-symbols-outlined">close</span>
                                                Close
                                            </button>
                                        </form>
                                    <?php elseif ($job['status'] === 'closed'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <input type="hidden" name="new_status" value="open">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <span class="material-symbols-outlined">refresh</span>
                                                Reopen
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ===== POST JOB MODAL ===== -->
    <div class="modal-overlay" id="postJobModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Post New Job</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <form method="POST" action="" onsubmit="return validateJobForm()">
                <input type="hidden" name="action" value="post_job">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Job Title <span class="required">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., Senior Software Engineer" required>
                    </div>

                    <div class="form-group">
                        <label>Location <span class="required">*</span></label>
                        <input type="text" name="location" class="form-control" placeholder="e.g., Makati City, Remote, Hybrid" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Job Type <span class="required">*</span></label>
                            <select name="job_type" class="form-control" required>
                                <option value="full-time">Full-time</option>
                                <option value="part-time">Part-time</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                                <option value="internship">Internship</option>
                                <option value="freelance">Freelance</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Number of Positions</label>
                            <input type="number" name="positions" class="form-control" value="1" min="1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Salary Range (Min)</label>
                            <input type="number" name="salary_min" class="form-control" placeholder="e.g., 30000" min="0">
                        </div>
                        <div class="form-group">
                            <label>Salary Range (Max)</label>
                            <input type="number" name="salary_max" class="form-control" placeholder="e.g., 50000" min="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Job Description <span class="required">*</span></label>
                        <textarea name="description" class="form-control" placeholder="Describe the role, responsibilities, and what makes this opportunity great..." rows="4" required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Requirements <span class="required">*</span></label>
                        <textarea name="requirements" class="form-control" placeholder="List the qualifications, skills, and experience needed..." rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">send</span>
                        Post Job
                    </button>
                </div>
            </form>
        </div>
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
        // MODAL
        // =============================================
        function openModal() {
            document.getElementById('postJobModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('postJobModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal on backdrop click
        document.getElementById('postJobModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // FORM VALIDATION
        // =============================================
        function validateJobForm() {
            const title = document.querySelector('input[name="title"]').value.trim();
            const description = document.querySelector('textarea[name="description"]').value.trim();
            const requirements = document.querySelector('textarea[name="requirements"]').value.trim();
            const location = document.querySelector('input[name="location"]').value.trim();

            if (!title || !description || !requirements || !location) {
                alert('Please fill in all required fields marked with *');
                return false;
            }

            const salaryMin = parseFloat(document.querySelector('input[name="salary_min"]').value);
            const salaryMax = parseFloat(document.querySelector('input[name="salary_max"]').value);

            if (salaryMin > 0 && salaryMax > 0 && salaryMin > salaryMax) {
                alert('Minimum salary cannot be greater than maximum salary.');
                return false;
            }

            return true;
        }

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

        console.log('📋 ISMERS Job Management loaded successfully!');
    </script>

</body>
</html>