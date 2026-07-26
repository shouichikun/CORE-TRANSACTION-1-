<?php
// portals/hr/job_view.php - View Job Details
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

// Get job ID from URL
$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($jobId <= 0) {
    header('Location: jobs.php');
    exit;
}

// Database helper function
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

// Get job details with company info
$sql = "SELECT jo.*, c.company_name, c.id as company_id,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id) as application_count,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'pending') as pending_count,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'shortlisted') as shortlisted_count,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'interviewed') as interviewed_count,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'hired') as hired_count,
        (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'rejected') as rejected_count
        FROM job_orders jo
        JOIN clients c ON jo.client_id = c.id
        WHERE jo.id = ? AND jo.created_by = ?";

$job = getRecord($sql, [$jobId, $userId], "ii");

if (!$job) {
    header('Location: jobs.php');
    exit;
}

// =============================================
// FIXED: Correct SQL query with proper joins for applicants
// The chain is: applications → applicants → users
// applications.applicant_id = applicants.id
// applicants.user_id = users.id
// =============================================
$recentApplicants = getRecords("
    SELECT a.id, a.status, a.applied_at, a.resume_path,
           u.id as user_id, u.first_name, u.last_name, u.email,
           ap.skills, ap.profile_picture
    FROM applications a
    JOIN applicants ap ON a.applicant_id = ap.id
    JOIN users u ON ap.user_id = u.id
    WHERE a.job_order_id = ?
    ORDER BY a.applied_at DESC
    LIMIT 5
", [$jobId], "i");

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

$applicationStatusBadges = [
    'pending' => 'badge-pending',
    'shortlisted' => 'badge-shortlisted',
    'interviewed' => 'badge-interviewed',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected',
    'withdrawn' => 'badge-withdrawn'
];

$applicationStatusLabels = [
    'pending' => 'Pending Review',
    'shortlisted' => 'Shortlisted',
    'interviewed' => 'Interviewed',
    'hired' => 'Hired',
    'rejected' => 'Rejected',
    'withdrawn' => 'Withdrawn'
];

$urgencyBadges = [
    'low' => 'badge-urgency-low',
    'medium' => 'badge-urgency-medium',
    'high' => 'badge-urgency-high'
];

// Get skills as array
$skills = !empty($job['skills_required']) ? explode(',', $job['skills_required']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($job['title']); ?> - Job Details</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - JOB VIEW
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

        .page-header .header-left h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }

        .page-header .header-left p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
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
           JOB DETAILS CARD
        ============================================= */
        .job-details-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .job-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .job-header .job-title-section h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .job-header .job-title-section .company-name {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .job-header .job-title-section .company-name .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
        }

        .job-header .job-status {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .job-body {
            padding: 1.5rem;
        }

        /* ===== JOB META GRID ===== */
        .job-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .meta-item .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .meta-item .meta-label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .meta-item .meta-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
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

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-shortlisted { background: #dbeafe; color: #2563eb; }
        .badge-interviewed { background: #e0e7ff; color: #4f46e5; }
        .badge-hired { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-withdrawn { background: #f3f4f6; color: #6b7280; }

        .badge-lg {
            padding: 0.375rem 1rem;
            font-size: 0.8125rem;
        }

        /* ===== SKILLS ===== */
        .skills-section {
            margin-bottom: 1.5rem;
        }

        .skills-section .section-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .skills-section .skill-tag {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
            margin-right: 0.375rem;
            margin-bottom: 0.375rem;
        }

        /* ===== DESCRIPTION ===== */
        .description-section {
            margin-bottom: 1.5rem;
        }

        .description-section .section-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .description-section .description-text {
            font-size: 0.9375rem;
            color: var(--text-on-surface);
            line-height: 1.8;
            white-space: pre-wrap;
        }

        /* ===== STATS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
            padding: 1rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }

        .stat-item .stat-label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        /* ===== RECENT APPLICANTS ===== */
        .applicants-section {
            margin-top: 1.5rem;
        }

        .applicants-section .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .applicants-section .section-header h3 {
            font-size: 1rem;
            font-weight: 700;
        }

        .applicants-section .section-header a {
            font-size: 0.875rem;
            color: var(--primary);
            font-weight: 600;
        }

        .applicants-section .section-header a:hover {
            text-decoration: underline;
        }

        .applicant-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            transition: all var(--transition-fast);
        }

        .applicant-item:hover {
            background: var(--bg-surface-low);
        }

        .applicant-item:last-child {
            border-bottom: none;
        }

        .applicant-item .applicant-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .applicant-item .applicant-info .avatar {
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

        .applicant-item .applicant-info .details .name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }

        .applicant-item .applicant-info .details .email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .applicant-item .applicant-status {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .applicant-item .applicant-status .applied-date {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .empty-applicants {
            text-align: center;
            padding: 1.5rem;
            color: var(--text-on-surface-variant);
        }

        .empty-applicants .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 0.5rem;
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

            .job-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .job-meta-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .applicant-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .applicant-item .applicant-status {
                width: 100%;
                justify-content: space-between;
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

            .page-header .header-left h1 {
                font-size: 1.5rem;
            }

            .job-header .job-title-section h2 {
                font-size: 1.25rem;
            }

            .job-meta-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stat-item .stat-number {
                font-size: 1.25rem;
            }

            .job-body {
                padding: 1rem;
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

     <!-- Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">account_balance</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">HR Portal</p>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="clients.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">business</span>
                <span class="nav-text">Clients</span>
            </a>
            <a href="jobs.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
            </a>
            <a href="applicants.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Applicants</span>
            </a>
            <a href="pipeline.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">view_kanban</span>
                <span class="nav-text">Pipeline</span>
            </a>
            <a href="interviews.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
            </a>
            <a href="offers.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Offers</span>
            </a>
            <a href="post_job.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">add_circle</span>
                <span class="nav-text">Post Job</span>
            </a>
            <div class="nav-label" style="margin-top:1rem;">System</div>
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
                <span style="font-weight:600; font-size:0.875rem;">Job Details</span>
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
                        <span>Job Details</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($job['title']); ?>
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        Posted: <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-left">
                        <h1>Job Details</h1>
                        <p>Full overview of the job posting</p>
                    </div>
                    <div class="header-actions">
                        <a href="jobs.php" class="btn btn-outline">
                            <span class="material-symbols-outlined">arrow_back</span>
                            Back to Jobs
                        </a>
                        <a href="edit_job.php?id=<?php echo $job['id']; ?>" class="btn btn-primary">
                            <span class="material-symbols-outlined">edit</span>
                            Edit Job
                        </a>
                    </div>
                </div>

                <!-- Job Details Card -->
                <div class="job-details-card">
                    <!-- Header -->
                    <div class="job-header">
                        <div class="job-title-section">
                            <h2><?php echo htmlspecialchars($job['title']); ?></h2>
                            <div class="company-name">
                                <span class="material-symbols-outlined">business</span>
                                <?php echo htmlspecialchars($job['company_name']); ?>
                            </div>
                        </div>
                        <div class="job-status">
                            <span class="badge badge-lg <?php echo $statusBadges[$job['status']] ?? 'badge-draft'; ?>">
                                <?php echo $statusLabels[$job['status']] ?? ucfirst($job['status']); ?>
                            </span>
                            <span class="badge badge-lg <?php echo $urgencyBadges[$job['urgency']] ?? 'badge-urgency-low'; ?>">
                                <?php echo ucfirst($job['urgency'] ?? 'Low'); ?> Urgency
                            </span>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="job-body">
                        <!-- Meta Grid -->
                        <div class="job-meta-grid">
                            <div class="meta-item">
                                <span class="material-symbols-outlined">work_history</span>
                                <div>
                                    <div class="meta-label">Job Type</div>
                                    <div class="meta-value"><?php echo htmlspecialchars($job['job_type'] ?? 'Full-time'); ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <span class="material-symbols-outlined">trending_up</span>
                                <div>
                                    <div class="meta-label">Experience Level</div>
                                    <div class="meta-value"><?php echo htmlspecialchars($job['experience_level'] ?? 'Entry'); ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <span class="material-symbols-outlined">location_on</span>
                                <div>
                                    <div class="meta-label">Location</div>
                                    <div class="meta-value"><?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <span class="material-symbols-outlined">payments</span>
                                <div>
                                    <div class="meta-label">Salary Range</div>
                                    <div class="meta-value"><?php echo htmlspecialchars($job['salary_range'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <span class="material-symbols-outlined">groups</span>
                                <div>
                                    <div class="meta-label">Positions Available</div>
                                    <div class="meta-value"><?php echo $job['positions_available'] ?? 1; ?></div>
                                </div>
                            </div>
                            <div class="meta-item">
                                <span class="material-symbols-outlined">calendar_today</span>
                                <div>
                                    <div class="meta-label">Application Deadline</div>
                                    <div class="meta-value">
                                        <?php echo !empty($job['application_deadline']) ? date('M d, Y', strtotime($job['application_deadline'])) : 'Ongoing'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Skills -->
                        <?php if (!empty($skills)): ?>
                        <div class="skills-section">
                            <div class="section-label">Required Skills</div>
                            <?php foreach ($skills as $skill): ?>
                                <?php $skill = trim($skill); if (!empty($skill)): ?>
                                    <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Description -->
                        <div class="description-section">
                            <div class="section-label">Job Description</div>
                            <div class="description-text"><?php echo nl2br(htmlspecialchars($job['description'] ?? 'No description provided.')); ?></div>
                        </div>

                        <!-- Stats -->
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $job['application_count'] ?? 0; ?></div>
                                <div class="stat-label">Total Applications</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" style="color:#d97706;"><?php echo $job['pending_count'] ?? 0; ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" style="color:#2563eb;"><?php echo $job['shortlisted_count'] ?? 0; ?></div>
                                <div class="stat-label">Shortlisted</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" style="color:#4f46e5;"><?php echo $job['interviewed_count'] ?? 0; ?></div>
                                <div class="stat-label">Interviewed</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" style="color:#059669;"><?php echo $job['hired_count'] ?? 0; ?></div>
                                <div class="stat-label">Hired</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" style="color:#dc2626;"><?php echo $job['rejected_count'] ?? 0; ?></div>
                                <div class="stat-label">Rejected</div>
                            </div>
                        </div>

                        <!-- Recent Applicants -->
                        <div class="applicants-section">
                            <div class="section-header">
                                <h3>Recent Applicants</h3>
                                <a href="applicants.php?job_id=<?php echo $job['id']; ?>">
                                    View All <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">arrow_forward</span>
                                </a>
                            </div>
                            <?php if (!empty($recentApplicants)): ?>
                                <?php foreach ($recentApplicants as $applicant): ?>
                                    <div class="applicant-item">
                                        <div class="applicant-info">
                                            <span class="avatar">
                                                <?php echo strtoupper(substr($applicant['first_name'] ?? 'A', 0, 1)); ?>
                                            </span>
                                            <div class="details">
                                                <div class="name"><?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?></div>
                                                <div class="email"><?php echo htmlspecialchars($applicant['email']); ?></div>
                                            </div>
                                        </div>
                                        <div class="applicant-status">
                                            <span class="badge <?php echo $applicationStatusBadges[$applicant['status']] ?? 'badge-pending'; ?>">
                                                <?php echo $applicationStatusLabels[$applicant['status']] ?? ucfirst($applicant['status']); ?>
                                            </span>
                                            <span class="applied-date"><?php echo date('M d, Y', strtotime($applicant['applied_at'])); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-applicants">
                                    <span class="material-symbols-outlined">person_off</span>
                                    <p>No applicants have applied to this job yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
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
        // 4. RESPONSIVE HANDLING
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
        // 5. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        console.log('📄 ISMERS Job View loaded successfully!');
    </script>

</body>
</html>