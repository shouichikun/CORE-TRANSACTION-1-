<?php
// portals/applicant/job_search.php - Job Search
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

// Get applied job IDs to check if already applied
$appliedJobIds = [];
foreach ($allApplications as $app) {
    $appliedJobIds[] = $app['job_order_id'] ?? 0;
}

// Get search parameters
$searchQuery = $_GET['search'] ?? '';
$jobType = $_GET['job_type'] ?? '';
$location = $_GET['location'] ?? '';
$experienceLevel = $_GET['experience'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Build search conditions
$conditions = ["jo.status IN ('open', 'ongoing')"];
$params = [];
$types = "";

if (!empty($searchQuery)) {
    $conditions[] = "(jo.title LIKE ? OR jo.description LIKE ? OR jo.skills_required LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($jobType)) {
    $conditions[] = "jo.job_type = ?";
    $params[] = $jobType;
    $types .= "s";
}

if (!empty($location)) {
    $conditions[] = "jo.location LIKE ?";
    $params[] = "%$location%";
    $types .= "s";
}

if (!empty($experienceLevel)) {
    $conditions[] = "jo.experience_level = ?";
    $params[] = $experienceLevel;
    $types .= "s";
}

$whereClause = implode(" AND ", $conditions);

// Count total jobs
$countSql = "SELECT COUNT(*) as total FROM job_orders jo 
             JOIN clients c ON jo.client_id = c.id 
             WHERE $whereClause";
$countResult = getRecord($countSql, $params, $types);
$totalJobs = $countResult['total'] ?? 0;
$totalPages = ceil($totalJobs / $perPage);
$offset = ($page - 1) * $perPage;

// Get jobs with pagination
$sql = "SELECT jo.*, c.company_name, c.logo_path 
        FROM job_orders jo 
        JOIN clients c ON jo.client_id = c.id 
        WHERE $whereClause 
        ORDER BY jo.created_at DESC 
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$jobs = getRecords($sql, $params, $types);

// Get all locations for filter
$locations = getRecords("SELECT DISTINCT location FROM job_orders WHERE status IN ('open', 'ongoing') ORDER BY location");
$jobTypes = ['Full-time', 'Part-time', 'Contract', 'Temporary', 'Internship'];
$experienceLevels = ['Entry', 'Junior', 'Mid', 'Senior', 'Lead'];

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Job Search - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - JOB SEARCH
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

        .btn-outline {
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

        .btn-outline:hover {
            background: var(--primary);
            color: var(--on-primary);
        }

        .btn-outline .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-success {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: #22c55e;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-success .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-sm {
            padding: 0.375rem 0.875rem;
            font-size: 0.75rem;
        }

        .btn-applied {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e5e7eb;
            color: #6b7280;
            border: none;
            cursor: default;
        }

        .btn-applied .material-symbols-outlined {
            font-size: 1rem;
        }

        /* =============================================
                   SEARCH BAR
                ============================================= */
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-input-wrapper input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: inherit;
            background: var(--bg-surface);
            transition: all var(--transition-fast);
            color: var(--text-on-surface);
        }

        .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .search-input-wrapper input::placeholder {
            color: var(--text-on-surface-variant);
        }

        .search-input-wrapper .search-icon {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            pointer-events: none;
        }

        .search-input-wrapper .search-icon .material-symbols-outlined {
            font-size: 1.25rem;
        }

        /* =============================================
                   FILTERS
                ============================================= */
        .filters {
            display: flex;
            gap: 0.625rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .filters select {
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.625rem;
            font-size: 0.8125rem;
            font-family: inherit;
            background: var(--bg-surface);
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            min-width: 140px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%235a6a7a'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }

        .filters select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* =============================================
                   RESULTS INFO
                ============================================= */
        .results-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .results-info .count {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .results-info .count strong {
            color: var(--text-on-surface);
        }

        /* =============================================
                   JOB CARDS
                ============================================= */
        .job-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-sm);
        }

        .job-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .job-card .job-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .job-card .job-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .job-card .job-company {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.25rem;
        }

        .badge-urgent {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fcd34d;
        }

        .job-card .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.25rem;
            margin: 0.5rem 0 0.75rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }

        .job-card .job-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .job-card .job-meta .meta-item .material-symbols-outlined {
            font-size: 1rem;
        }

        .job-card .job-description {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .job-card .job-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .job-card .job-footer .job-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
        }

        .job-card .job-footer .job-tag {
            display: inline-block;
            padding: 0.125rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 500;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border: 1px solid rgba(79, 70, 229, 0.15);
        }

        .job-card .job-footer .job-tag.high-demand {
            background: #dbeafe;
            color: #2563eb;
            border-color: #93c5fd;
        }

        /* =============================================
                   EMPTY STATE
                ============================================= */
        .empty-state {
            text-align: center;
            padding: 3.75rem 1.25rem;
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
            max-width: 400px;
            margin: 0 auto;
        }

        /* =============================================
                   PAGINATION
                ============================================= */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.375rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            padding: 0 0.75rem;
            border-radius: 0.625rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 2px solid var(--slate-200);
            transition: all var(--transition-fast);
        }

        .pagination a:hover {
            background: var(--bg-surface-low);
            border-color: var(--primary);
            color: var(--text-on-surface);
        }

        .pagination a.active {
            background: var(--primary);
            color: var(--on-primary);
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
        }

        .pagination a.disabled,
        .pagination span.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .pagination a .material-symbols-outlined {
            font-size: 1.125rem;
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

            .search-bar {
                flex-direction: column;
            }

            .filters {
                flex-direction: column;
            }

            .filters select {
                width: 100%;
            }

            .job-card {
                padding: 1rem;
            }

            .job-card .job-title {
                font-size: 1rem;
            }

            .job-card .job-header {
                flex-direction: column;
            }

            .job-card .job-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .job-card .job-footer .job-tags {
                justify-content: center;
            }

            .pagination a,
            .pagination span {
                min-width: 2.125rem;
                height: 2.125rem;
                font-size: 0.8125rem;
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

            .job-card {
                padding: 0.875rem;
            }

            .job-card .job-title {
                font-size: 0.9375rem;
            }

            .job-card .job-meta {
                font-size: 0.75rem;
                gap: 0.5rem 0.875rem;
            }

            .results-info {
                flex-direction: column;
                align-items: flex-start;
            }

            .empty-state {
                padding: 2rem 1rem;
            }

            .empty-state .empty-icon .material-symbols-outlined {
                font-size: 3rem;
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

            <a href="applications.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link active">
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

            <div class="relative flex items-center">
                <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Applicant</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
            </div>
        </header>

        <!-- Main Scrollable Area -->
        <main class="main-scroll" id="mainScroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">search</span>
                        <span>Job Search</span>
                        <span class="status-dot"></span>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Find Your Next Opportunity</h1>
                        <p>Browse and apply for jobs that match your skills</p>
                    </div>
                </div>

                <!-- Search Bar -->
                <form method="GET" action="" class="search-bar" id="searchForm">
                    <div class="search-input-wrapper">
                        <span class="search-icon">
                            <span class="material-symbols-outlined">search</span>
                        </span>
                        <input type="text" name="search" placeholder="Search jobs, companies, skills..." 
                               value="<?php echo htmlspecialchars($searchQuery); ?>" id="searchInput">
                    </div>
                    <button type="submit" class="btn-primary">Search</button>
                    <?php if (!empty($searchQuery) || !empty($jobType) || !empty($location) || !empty($experienceLevel)): ?>
                        <a href="job_search.php" class="btn-outline">Clear Filters</a>
                    <?php endif; ?>
                </form>

                <!-- Filters -->
                <div class="filters">
                    <select name="job_type" id="jobTypeFilter">
                        <option value="">All Job Types</option>
                        <?php foreach ($jobTypes as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $jobType === $type ? 'selected' : ''; ?>>
                                <?php echo $type; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="experience" id="experienceFilter">
                        <option value="">All Experience Levels</option>
                        <?php foreach ($experienceLevels as $level): ?>
                            <option value="<?php echo $level; ?>" <?php echo $experienceLevel === $level ? 'selected' : ''; ?>>
                                <?php echo $level; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="location" id="locationFilter">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                            <?php if (!empty($loc['location'])): ?>
                                <option value="<?php echo htmlspecialchars($loc['location']); ?>" <?php echo $location === $loc['location'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($loc['location']); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Results Info -->
                <div class="results-info">
                    <span class="count">
                        Found <strong><?php echo $totalJobs; ?></strong> job<?php echo $totalJobs > 1 ? 's' : ''; ?>
                        <?php if (!empty($searchQuery)): ?> matching "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>"<?php endif; ?>
                    </span>
                    <?php if ($totalJobs > 0): ?>
                        <span style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Job Cards -->
                <?php if (empty($jobs)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <span class="material-symbols-outlined" style="font-size:3rem;">search_off</span>
                        </div>
                        <h4>No Jobs Found</h4>
                        <p>
                            <?php if (!empty($searchQuery)): ?>
                                We couldn't find any jobs matching "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>". Try adjusting your search or filters.
                            <?php else: ?>
                                There are no open job positions at the moment. Please check back later.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                        <div class="job-card">
                            <div class="job-header">
                                <div>
                                    <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                                    <div class="job-company"><?php echo htmlspecialchars($job['company_name']); ?></div>
                                </div>
                                <?php if ($job['urgency'] === 'high'): ?>
                                    <span class="badge-urgent">Urgent</span>
                                <?php endif; ?>
                            </div>

                            <div class="job-meta">
                                <span class="meta-item">
                                    <span class="material-symbols-outlined">work</span>
                                    <?php echo htmlspecialchars($job['job_type'] ?? 'Full-time'); ?>
                                </span>
                                <span class="meta-item">
                                    <span class="material-symbols-outlined">location_on</span>
                                    <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                                </span>
                                <span class="meta-item">
                                    <span class="material-symbols-outlined">trending_up</span>
                                    <?php echo htmlspecialchars($job['experience_level'] ?? 'Entry'); ?>
                                </span>
                                <?php if (!empty($job['salary_range'])): ?>
                                    <span class="meta-item">
                                        <span class="material-symbols-outlined">payments</span>
                                        <?php echo htmlspecialchars($job['salary_range']); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="meta-item">
                                    <span class="material-symbols-outlined">calendar_today</span>
                                    <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                                </span>
                            </div>

                            <div class="job-description">
                                <?php echo htmlspecialchars(substr($job['description'] ?? '', 0, 150)); ?>...
                            </div>

                            <div class="job-footer">
                                <div class="job-tags">
                                    <?php if (!empty($job['skills_required'])): ?>
                                        <?php 
                                            $skills = array_slice(explode(',', $job['skills_required']), 0, 4);
                                            foreach ($skills as $skill): 
                                        ?>
                                            <span class="job-tag"><?php echo htmlspecialchars(trim($skill)); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count(explode(',', $job['skills_required'])) > 4): ?>
                                            <span class="job-tag">+<?php echo count(explode(',', $job['skills_required'])) - 4; ?> more</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($job['status'] === 'ongoing'): ?>
                                        <span class="job-tag high-demand">Active</span>
                                    <?php endif; ?>
                                </div>

                                <?php 
                                $isApplied = in_array($job['id'], $appliedJobIds);
                                ?>
                                <?php if ($isApplied): ?>
                                    <span class="btn-applied">
                                        <span class="material-symbols-outlined">check</span>
                                        Applied
                                    </span>
                                <?php else: ?>
                                    <a href="apply.php?job_id=<?php echo $job['id']; ?>" class="btn-success btn-sm">
                                        <span class="material-symbols-outlined">send</span>
                                        Apply Now
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchQuery); ?>&job_type=<?php echo urlencode($jobType); ?>&location=<?php echo urlencode($location); ?>&experience=<?php echo urlencode($experienceLevel); ?>">
                                <span class="material-symbols-outlined">chevron_left</span>
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                <span class="material-symbols-outlined">chevron_left</span>
                            </span>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchQuery); ?>&job_type=<?php echo urlencode($jobType); ?>&location=<?php echo urlencode($location); ?>&experience=<?php echo urlencode($experienceLevel); ?>" 
                               class="<?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchQuery); ?>&job_type=<?php echo urlencode($jobType); ?>&location=<?php echo urlencode($location); ?>&experience=<?php echo urlencode($experienceLevel); ?>">
                                <span class="material-symbols-outlined">chevron_right</span>
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                <span class="material-symbols-outlined">chevron_right</span>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

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
            // 4. AUTO SUBMIT FILTERS
            // =============================================
            document.querySelectorAll('.filters select').forEach(function(select) {
                select.addEventListener('change', function() {
                    document.getElementById('searchForm').submit();
                });
            });

            // =============================================
            // 5. KEYBOARD ACCESSIBILITY
            // =============================================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                }
            });

            // =============================================
            // 6. INITIAL STATE
            // =============================================
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            console.log('ISMERS Job Search Page loaded successfully.');
        })();
    </script>

</body>
</html>