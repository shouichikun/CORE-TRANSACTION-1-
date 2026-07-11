<?php
// portals/hr/jobs.php - Manage Jobs with Modals
session_start();

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has HR role
if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';
$isHRManager = $role === 'hr_manager';

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

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Build query conditions
$conditions = [];
$params = [];
$types = "";

if ($role !== 'admin') {
    $conditions[] = "jo.created_by = ?";
    $params[] = $userId;
    $types .= "i";
}

if ($statusFilter !== 'all') {
    $conditions[] = "jo.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (!empty($searchQuery)) {
    $conditions[] = "(jo.title LIKE ? OR c.company_name LIKE ? OR jo.location LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get jobs
$sql = "SELECT jo.*, c.company_name, 
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id) as application_count,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'pending') as pending_count
        FROM job_orders jo
        JOIN clients c ON jo.client_id = c.id
        $whereClause
        ORDER BY jo.created_at DESC";

$jobs = getRecords($sql, $params, $types);

// Get status counts
$statusCounts = ['all' => count($jobs)];
$statuses = ['open', 'ongoing', 'filled', 'cancelled', 'draft'];
foreach ($statuses as $status) {
    $countSql = "SELECT COUNT(*) as count FROM job_orders jo WHERE jo.status = ?";
    $countParams = [$status];
    $countTypes = "s";
    if ($role !== 'admin') {
        $countSql .= " AND jo.created_by = ?";
        $countParams[] = $userId;
        $countTypes .= "i";
    }
    $result = getRecord($countSql, $countParams, $countTypes);
    $statusCounts[$status] = $result['count'] ?? 0;
}

// Get all statuses for filter
$allStatuses = ['all' => 'All Jobs', 'open' => 'Open', 'ongoing' => 'Ongoing', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'draft' => 'Draft'];

// Status badge mapping
$statusBadges = [
    'open' => 'badge-open',
    'ongoing' => 'badge-ongoing',
    'filled' => 'badge-filled',
    'cancelled' => 'badge-cancelled',
    'draft' => 'badge-draft'
];

$statusLabels = [
    'open' => 'Open',
    'ongoing' => 'Ongoing',
    'filled' => 'Filled',
    'cancelled' => 'Cancelled',
    'draft' => 'Draft'
];

$urgencyBadges = [
    'low' => 'badge-urgency-low',
    'medium' => 'badge-urgency-medium',
    'high' => 'badge-urgency-high'
];

$jobTypes = ['Full-time', 'Part-time', 'Contract', 'Temporary', 'Internship', 'Freelance'];
$experienceLevels = ['Entry', 'Junior', 'Mid', 'Senior', 'Lead', 'Manager'];
$jobStatuses = ['draft', 'open', 'ongoing', 'filled', 'cancelled'];
$urgencyLevels = ['low', 'medium', 'high'];

// Handle AJAX requests for view/edit
if (isset($_GET['ajax'])) {
    $ajaxAction = $_GET['ajax'] ?? '';
    $jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($ajaxAction === 'view' && $jobId > 0) {
        $job = getRecord("SELECT jo.*, c.company_name FROM job_orders jo 
                         JOIN clients c ON jo.client_id = c.id 
                         WHERE jo.id = ?", [$jobId], "i");
        if ($job) {
            $job['skills_list'] = explode(',', $job['skills_required'] ?? '');
            echo json_encode(['success' => true, 'job' => $job]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Job not found']);
        }
        exit;
    }
    
    if ($ajaxAction === 'edit' && $jobId > 0) {
        $job = getRecord("SELECT * FROM job_orders WHERE id = ?", [$jobId], "i");
        if ($job) {
            echo json_encode(['success' => true, 'job' => $job]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Job not found']);
        }
        exit;
    }
}

// Handle POST for edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    
    if ($action === 'update_job' && $jobId > 0) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $skillsRequired = trim($_POST['skills_required'] ?? '');
        $salaryRange = trim($_POST['salary_range'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $jobType = $_POST['job_type'] ?? 'Full-time';
        $experienceLevel = $_POST['experience_level'] ?? 'Entry';
        $status = $_POST['status'] ?? 'draft';
        $urgency = $_POST['urgency'] ?? 'medium';
        $positionsAvailable = (int)($_POST['positions_available'] ?? 1);
        
        $sql = "UPDATE job_orders SET 
                title = ?,
                description = ?,
                skills_required = ?,
                salary_range = ?,
                location = ?,
                job_type = ?,
                experience_level = ?,
                status = ?,
                urgency = ?,
                positions_available = ?,
                updated_at = NOW()
                WHERE id = ? AND created_by = ?";
        
        $result = updateRecord($sql, [
            $title, $description, $skillsRequired, $salaryRange, $location,
            $jobType, $experienceLevel, $status, $urgency, $positionsAvailable,
            $jobId, $userId
        ], "sssssssssiii");
        
        if ($result) {
            logActivity($userId, 'Job Updated', 'job_orders', $jobId, 'Updated job: ' . $title);
            echo json_encode(['success' => true, 'message' => 'Job updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update job.']);
        }
        exit;
    }
    
    if ($action === 'delete_job' && $jobId > 0) {
        $job = getRecord("SELECT title FROM job_orders WHERE id = ? AND created_by = ?", [$jobId, $userId], "ii");
        if ($job) {
            $sql = "DELETE FROM job_orders WHERE id = ? AND created_by = ?";
            $result = deleteRecord($sql, [$jobId, $userId], "ii");
            if ($result) {
                logActivity($userId, 'Job Deleted', 'job_orders', $jobId, 'Deleted job: ' . $job['title']);
                echo json_encode(['success' => true, 'message' => 'Job deleted successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete job.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Job not found or you don\'t have permission.']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manage Jobs - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - JOBS MANAGEMENT
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

        .page-header .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* =============================================
           BUTTONS
        ============================================= */
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

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--bg-surface-low);
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.5rem;
        }

        .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-sm .material-symbols-outlined {
            font-size: 1rem;
        }

        /* =============================================
           SEARCH & FILTERS
        ============================================= */
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.25rem;
        }

        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.625rem 0.875rem 0.625rem 2.75rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }

        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .search-bar .search-input-wrapper input::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .status-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }

        .status-filter {
            padding: 0.375rem 1rem;
            border-radius: var(--radius-full);
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 2px solid var(--slate-200);
            transition: all var(--transition-fast);
            white-space: nowrap;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .status-filter:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .status-filter.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.35);
        }

        .status-filter .filter-count {
            display: inline-block;
            background: rgba(0, 0, 0, 0.08);
            border-radius: var(--radius-full);
            padding: 0 0.5rem;
            font-size: 0.6875rem;
            font-weight: 700;
        }

        .status-filter.active .filter-count {
            background: rgba(255, 255, 255, 0.25);
        }

        /* =============================================
           JOBS TABLE
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .card-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .card-header .job-count {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
        }

        .card-body {
            padding: 0;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            min-width: 640px;
        }

        table thead {
            background: var(--bg-surface-low);
        }

        table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }

        table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        table tbody tr:hover td {
            background: var(--bg-surface-low);
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .job-title {
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .job-company {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }

        .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem 0.75rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .job-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .job-meta .meta-item .material-symbols-outlined {
            font-size: 0.875rem;
        }

        /* ===== BADGES ===== */
        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-open { background: #d1fae5; color: #059669; }
        .badge-ongoing { background: #dbeafe; color: #2563eb; }
        .badge-filled { background: #f3e8ff; color: #7c3aed; }
        .badge-cancelled { background: #fecaca; color: #dc2626; }
        .badge-draft { background: #f3f4f6; color: #6b7280; }

        .badge-urgency-low { background: #f3f4f6; color: #6b7280; }
        .badge-urgency-medium { background: #fef3c7; color: #d97706; }
        .badge-urgency-high { background: #fecaca; color: #dc2626; }

        .action-buttons {
            display: flex;
            gap: 0.375rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 4rem 1.5rem;
        }

        .empty-state .material-symbols-outlined {
            font-size: 4rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 1rem;
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
        }

        .empty-state .btn {
            margin-top: 1rem;
        }

        /* =============================================
           MODAL
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

        .modal-overlay.active {
            display: flex;
        }

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
            from {
                transform: translateY(20px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
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

        .modal-header h2 .material-symbols-outlined {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }

        .modal-close:hover {
            background: var(--bg-surface-low);
        }

        .modal-close .material-symbols-outlined {
            font-size: 1.5rem;
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
            flex-shrink: 0;
        }

        /* View Modal */
        .view-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .view-item {
            margin-bottom: 0.25rem;
        }

        .view-item .label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .view-item .value {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            padding: 0.5rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            margin-top: 0.125rem;
        }

        .view-item .value.skills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            background: transparent;
            padding: 0;
            margin-top: 0.25rem;
        }

        .view-item .value.skills .skill-tag {
            display: inline-block;
            padding: 0.1875rem 0.625rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
        }

        .view-item.full-width {
            grid-column: 1 / -1;
        }

        /* Edit Form */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .form-group label .required {
            color: #dc2626;
            margin-left: 0.125rem;
        }

        .form-group .form-control {
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

        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .form-group .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .form-group textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* =============================================
           LOADING SPINNER
        ============================================= */
        .loading-spinner {
            text-align: center;
            padding: 2rem;
        }

        .loading-spinner .spinner {
            width: 2.5rem;
            height: 2.5rem;
            border: 4px solid var(--slate-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-spinner p {
            margin-top: 0.75rem;
            color: var(--text-on-surface-variant);
            font-size: 0.875rem;
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

            .view-grid {
                grid-template-columns: 1fr;
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

            .page-header h1 {
                font-size: 1.5rem;
            }

            .search-bar {
                flex-direction: column;
            }

            .status-filters {
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 0.25rem;
                -webkit-overflow-scrolling: touch;
            }

            .status-filter {
                font-size: 0.75rem;
                padding: 0.25rem 0.75rem;
            }

            table {
                font-size: 0.8125rem;
                min-width: 500px;
            }

            table th,
            table td {
                padding: 0.5rem 0.75rem;
            }

            .modal {
                max-height: 95vh;
                margin: 0.5rem;
            }

            .modal-header {
                padding: 1rem 1.25rem;
            }

            .modal-body {
                padding: 1rem 1.25rem;
            }

            .modal-footer {
                padding: 0.75rem 1.25rem;
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .action-buttons .btn-sm {
                font-size: 0.6875rem;
                padding: 0.25rem 0.5rem;
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
                    <span class="material-symbols-outlined">work</span>
                </span>
                <p class="sidebar-brand-text">ISMERS</p>
                <p class="sidebar-brand-category">HR Portal</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="jobs.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
                <span class="nav-badge"><?php echo $statusCounts['all']; ?></span>
            </a>

            <a href="applicants.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Applicants</span>
            </a>

            <a href="post_job.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">add_circle</span>
                <span class="nav-text">Post Job</span>
            </a>

            <div class="nav-label" style="margin-top:1.5rem;">Settings</div>

            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
            <a href="../../logout.php" class="logout-btn">
                <span class="material-symbols-outlined">logout</span>
                <span class="logout-text">Logout</span>
            </a>
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
                <span style="font-weight:600; font-size:0.875rem;">Job Management</span>
            </div>

            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <button class="dropdown-item" onclick="window.location.href='profile.php'">
                        <span class="material-symbols-outlined">person</span>
                        Profile
                    </button>
                    <button class="dropdown-item" onclick="window.location.href='settings.php'">
                        <span class="material-symbols-outlined">settings</span>
                        Settings
                    </button>
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
                        <span class="material-symbols-outlined">work</span>
                        <span>Jobs</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> 
                            (<?php echo count($jobs); ?> jobs)
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        Last updated: <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Manage Jobs</h1>
                        <p>View and manage all your job postings</p>
                    </div>
                    <div class="header-actions">
                        <a href="post_job.php" class="btn btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            Post New Job
                        </a>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <span class="material-symbols-outlined">search</span>
                        <input type="text" id="searchInput" placeholder="Search jobs, companies, locations..." 
                               value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                    <?php if (!empty($searchQuery) || $statusFilter !== 'all'): ?>
                        <a href="jobs.php" class="btn btn-outline">Clear Filters</a>
                    <?php endif; ?>
                </div>

                <!-- Status Filters -->
                <div class="status-filters">
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <a href="?status=<?php echo $key; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                           class="status-filter <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                            <span class="filter-count"><?php echo $statusCounts[$key] ?? 0; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Jobs Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">work</span>
                            <?php if ($statusFilter === 'all'): ?>
                                All Jobs
                            <?php else: ?>
                                <?php echo ucfirst($statusFilter); ?> Jobs
                            <?php endif; ?>
                        </h3>
                        <span class="job-count"><?php echo count($jobs); ?> jobs found</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($jobs)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">work_off</span>
                                <h4>No Jobs Found</h4>
                                <p>
                                    <?php if ($statusFilter !== 'all'): ?>
                                        You don't have any <?php echo $statusFilter; ?> jobs.
                                    <?php else: ?>
                                        You haven't posted any jobs yet.
                                    <?php endif; ?>
                                </p>
                                <a href="post_job.php" class="btn btn-primary">Post Your First Job</a>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Job Title</th>
                                        <th>Company</th>
                                        <th>Location</th>
                                        <th>Applications</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($jobs as $job): ?>
                                        <tr>
                                            <td>
                                                <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                                                <div class="job-meta">
                                                    <span class="meta-item">
                                                        <span class="material-symbols-outlined">work_history</span>
                                                        <?php echo htmlspecialchars($job['job_type'] ?? 'Full-time'); ?>
                                                    </span>
                                                    <span class="meta-item">
                                                        <span class="material-symbols-outlined">payments</span>
                                                        <?php echo htmlspecialchars($job['salary_range'] ?? 'N/A'); ?>
                                                    </span>
                                                    <span class="meta-item">
                                                        <span class="badge <?php echo $urgencyBadges[$job['urgency']] ?? 'badge-urgency-low'; ?>">
                                                            <?php echo ucfirst($job['urgency'] ?? 'Low'); ?> Urgency
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="job-company"><?php echo htmlspecialchars($job['company_name']); ?></div>
                                            </td>
                                            <td>
                                                <div style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                                    <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">location_on</span>
                                                    <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight:600; color:var(--text-on-surface);">
                                                    <?php echo $job['application_count'] ?? 0; ?>
                                                </div>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo $job['pending_count'] ?? 0; ?> pending
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $statusBadges[$job['status']] ?? 'badge-draft'; ?>">
                                                    <?php echo $statusLabels[$job['status']] ?? ucfirst($job['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-outline btn-sm" onclick="viewJob(<?php echo $job['id']; ?>)">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-primary btn-sm" onclick="editJob(<?php echo $job['id']; ?>)">
                                                        <span class="material-symbols-outlined">edit</span>
                                                    </button>
                                                    <?php if ($isHRManager || $role === 'admin'): ?>
                                                        <button class="btn btn-danger btn-sm" onclick="deleteJob(<?php echo $job['id']; ?>)">
                                                            <span class="material-symbols-outlined">delete</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- =============================================
    MODAL
    ============================================= -->
    <div class="modal-overlay" id="jobModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined" id="modalIcon">work</span>
                    <span id="modalTitle">Job Details</span>
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading-spinner" id="modalLoading">
                    <div class="spinner"></div>
                    <p>Loading...</p>
                </div>
                <div id="modalContent" style="display:none;"></div>
            </div>
            <div class="modal-footer" id="modalFooter">
                <button class="btn btn-outline" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" id="modalActionBtn" style="display:none;">Save Changes</button>
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
        // 4. SEARCH FUNCTION
        // =============================================
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = '<?php echo $statusFilter; ?>';
            let url = 'jobs.php?';
            if (status !== 'all') url += 'status=' + status + '&';
            if (search) url += 'search=' + encodeURIComponent(search);
            window.location.href = url;
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        // =============================================
        // 5. MODAL FUNCTIONS
        // =============================================
        const modalOverlay = document.getElementById('jobModal');
        const modalBody = document.getElementById('modalBody');
        const modalContent = document.getElementById('modalContent');
        const modalLoading = document.getElementById('modalLoading');
        const modalTitle = document.getElementById('modalTitle');
        const modalIcon = document.getElementById('modalIcon');
        const modalFooter = document.getElementById('modalFooter');
        const modalActionBtn = document.getElementById('modalActionBtn');

        function openModal() {
            modalOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modalOverlay.classList.remove('active');
            document.body.style.overflow = '';
            modalContent.style.display = 'none';
            modalLoading.style.display = 'block';
            modalActionBtn.style.display = 'none';
        }

        // Close on overlay click
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
                closeModal();
            }
        });

        // =============================================
        // 6. VIEW JOB
        // =============================================
        function viewJob(jobId) {
            openModal();
            modalTitle.textContent = 'Job Details';
            modalIcon.textContent = 'work';
            modalActionBtn.style.display = 'none';
            modalLoading.style.display = 'block';
            modalContent.style.display = 'none';

            fetch('jobs.php?ajax=view&id=' + jobId)
                .then(response => response.json())
                .then(data => {
                    modalLoading.style.display = 'none';
                    modalContent.style.display = 'block';

                    if (data.success) {
                        const job = data.job;
                        const skills = job.skills_list || [];
                        const skillsHtml = skills.filter(s => s.trim()).map(s => 
                            '<span class="skill-tag">' + escapeHtml(s.trim()) + '</span>'
                        ).join('');

                        modalContent.innerHTML = `
                            <div class="view-grid">
                                <div class="view-item">
                                    <div class="label">Job Title</div>
                                    <div class="value">${escapeHtml(job.title)}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Company</div>
                                    <div class="value">${escapeHtml(job.company_name)}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Location</div>
                                    <div class="value">${escapeHtml(job.location || 'Remote')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Job Type</div>
                                    <div class="value">${escapeHtml(job.job_type || 'Full-time')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Experience Level</div>
                                    <div class="value">${escapeHtml(job.experience_level || 'Entry')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Salary Range</div>
                                    <div class="value">${escapeHtml(job.salary_range || 'N/A')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Status</div>
                                    <div class="value"><span class="badge ${getStatusBadge(job.status)}">${escapeHtml(job.status || 'Draft')}</span></div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Urgency</div>
                                    <div class="value"><span class="badge ${getUrgencyBadge(job.urgency)}">${escapeHtml(job.urgency || 'Low')}</span></div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Required Skills</div>
                                    <div class="value skills">${skillsHtml || '<span style="color:var(--text-on-surface-variant);">No skills listed</span>'}</div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Job Description</div>
                                    <div class="value">${escapeHtml(job.description || 'No description provided.')}</div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Applications</div>
                                    <div class="value">${job.application_count || 0} total (${job.pending_count || 0} pending)</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Positions Available</div>
                                    <div class="value">${job.positions_available || 1}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Created</div>
                                    <div class="value">${new Date(job.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                                </div>
                            </div>
                        `;
                    } else {
                        modalContent.innerHTML = `
                            <div style="text-align:center; padding:1rem; color:#dc2626;">
                                <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                                <p style="margin-top:0.5rem;">${data.error || 'Failed to load job details.'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    modalLoading.style.display = 'none';
                    modalContent.style.display = 'block';
                    modalContent.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">Error loading job details. Please try again.</p>
                        </div>
                    `;
                });
        }

        // =============================================
        // 7. EDIT JOB
        // =============================================
        function editJob(jobId) {
            openModal();
            modalTitle.textContent = 'Edit Job';
            modalIcon.textContent = 'edit';
            modalActionBtn.style.display = 'flex';
            modalActionBtn.textContent = 'Update Job';
            modalLoading.style.display = 'block';
            modalContent.style.display = 'none';

            fetch('jobs.php?ajax=edit&id=' + jobId)
                .then(response => response.json())
                .then(data => {
                    modalLoading.style.display = 'none';
                    modalContent.style.display = 'block';

                    if (data.success) {
                        const job = data.job;
                        const jobTypes = <?php echo json_encode($jobTypes); ?>;
                        const experienceLevels = <?php echo json_encode($experienceLevels); ?>;
                        const jobStatuses = <?php echo json_encode($jobStatuses); ?>;
                        const urgencyLevels = <?php echo json_encode($urgencyLevels); ?>;

                        function createOptions(options, selected) {
                            return options.map(opt => 
                                `<option value="${opt}" ${opt === selected ? 'selected' : ''}>${opt}</option>`
                            ).join('');
                        }

                        modalContent.innerHTML = `
                            <form id="editJobForm" onsubmit="submitEditJob(event, ${jobId})">
                                <input type="hidden" name="action" value="update_job">
                                <input type="hidden" name="job_id" value="${jobId}">

                                <div class="form-group">
                                    <label>Job Title <span class="required">*</span></label>
                                    <input type="text" name="title" class="form-control" value="${escapeHtml(job.title)}" required>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Job Type</label>
                                        <select name="job_type" class="form-control">${createOptions(jobTypes, job.job_type)}</select>
                                    </div>
                                    <div class="form-group">
                                        <label>Experience Level</label>
                                        <select name="experience_level" class="form-control">${createOptions(experienceLevels, job.experience_level)}</select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Location</label>
                                        <input type="text" name="location" class="form-control" value="${escapeHtml(job.location || '')}" placeholder="e.g., Makati, Philippines">
                                    </div>
                                    <div class="form-group">
                                        <label>Salary Range</label>
                                        <input type="text" name="salary_range" class="form-control" value="${escapeHtml(job.salary_range || '')}" placeholder="e.g., ₱50,000 - ₱80,000">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Skills Required</label>
                                    <input type="text" name="skills_required" class="form-control" value="${escapeHtml(job.skills_required || '')}" placeholder="e.g., PHP, Laravel, MySQL, JavaScript">
                                    <div style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">Separate skills with commas</div>
                                </div>

                                <div class="form-group">
                                    <label>Job Description</label>
                                    <textarea name="description" class="form-control" rows="4" placeholder="Describe the job responsibilities and requirements">${escapeHtml(job.description || '')}</textarea>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="status" class="form-control">${createOptions(jobStatuses, job.status)}</select>
                                    </div>
                                    <div class="form-group">
                                        <label>Urgency</label>
                                        <select name="urgency" class="form-control">${createOptions(urgencyLevels, job.urgency)}</select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Positions Available</label>
                                    <input type="number" name="positions_available" class="form-control" value="${job.positions_available || 1}" min="1">
                                </div>
                            </form>
                        `;
                    } else {
                        modalContent.innerHTML = `
                            <div style="text-align:center; padding:1rem; color:#dc2626;">
                                <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                                <p style="margin-top:0.5rem;">${data.error || 'Failed to load job details.'}</p>
                            </div>
                        `;
                        modalActionBtn.style.display = 'none';
                    }
                })
                .catch(error => {
                    modalLoading.style.display = 'none';
                    modalContent.style.display = 'block';
                    modalContent.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">Error loading job details. Please try again.</p>
                        </div>
                    `;
                    modalActionBtn.style.display = 'none';
                });
        }

        // =============================================
        // 8. SUBMIT EDIT JOB
        // =============================================
        function submitEditJob(event, jobId) {
            event.preventDefault();
            const form = document.getElementById('editJobForm');
            const formData = new FormData(form);
            
            modalActionBtn.disabled = true;
            modalActionBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem; animation:spin 0.8s linear infinite;">refresh</span> Saving...';

            fetch('jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                modalActionBtn.disabled = false;
                modalActionBtn.innerHTML = 'Save Changes';

                if (data.success) {
                    showToast('Job updated successfully!', 'success');
                    setTimeout(() => {
                        closeModal();
                        location.reload();
                    }, 1000);
                } else {
                    showToast(data.error || 'Failed to update job.', 'error');
                }
            })
            .catch(error => {
                modalActionBtn.disabled = false;
                modalActionBtn.innerHTML = 'Save Changes';
                showToast('Error updating job. Please try again.', 'error');
            });
        }

        // =============================================
        // 9. DELETE JOB
        // =============================================
        function deleteJob(jobId) {
            if (!confirm('Are you sure you want to delete this job? This action cannot be undone.')) {
                return;
            }

            showToast('Deleting job...', 'info');
            
            const formData = new FormData();
            formData.append('action', 'delete_job');
            formData.append('job_id', jobId);

            fetch('jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to delete job.', 'error');
                }
            })
            .catch(error => {
                showToast('Error deleting job. Please try again.', 'error');
            });
        }

        // =============================================
        // 10. TOAST SYSTEM
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
        // 11. UTILITY FUNCTIONS
        // =============================================
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getStatusBadge(status) {
            const badges = {
                'open': 'badge-open',
                'ongoing': 'badge-ongoing',
                'filled': 'badge-filled',
                'cancelled': 'badge-cancelled',
                'draft': 'badge-draft'
            };
            return badges[status] || 'badge-draft';
        }

        function getUrgencyBadge(urgency) {
            const badges = {
                'low': 'badge-urgency-low',
                'medium': 'badge-urgency-medium',
                'high': 'badge-urgency-high'
            };
            return badges[urgency] || 'badge-urgency-low';
        }

        // =============================================
        // 12. RESPONSIVE HANDLING
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
        // 13. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (modalOverlay.classList.contains('active')) {
                    closeModal();
                } else {
                    closeMobileSidebar();
                    profileToggle.classList.remove('open');
                    profileMenu.classList.remove('open');
                }
            }
        });

        console.log('📋 ISMERS Jobs Management loaded successfully!');
    </script>

</body>
</html>