<?php
// portals/applicant/offers.php - Applicant Offers Management
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Applicant';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// ✅ FIXED: Only show offers that have been SENT to the applicant
// Exclude 'draft' offers - applicants should only see sent offers
$offers = getRecords("
    SELECT o.*, 
           a.id as application_id, a.status as application_status, a.applied_at,
           jo.id as job_id, jo.title as job_title, jo.description as job_description,
           c.company_name, c.id as client_id,
           u.first_name, u.last_name, u.email
    FROM offers o
    JOIN applications a ON o.application_id = a.id
    JOIN applicants ap ON a.applicant_id = ap.id
    JOIN users u ON ap.user_id = u.id
    JOIN job_orders jo ON a.job_order_id = jo.id
    JOIN clients c ON jo.client_id = c.id
    WHERE ap.user_id = $1
    AND o.status IN ('sent', 'accepted', 'rejected', 'expired')
    ORDER BY o.created_at DESC
", [$userId]);

// Get counts for dashboard
$totalOffers = count($offers);
$pendingOffers = 0;
$acceptedOffers = 0;
$rejectedOffers = 0;
$expiredOffers = 0;

foreach ($offers as $offer) {
    // Check if expired (7 days after sent)
    $isExpired = false;
    if ($offer['status'] === 'sent' && !empty($offer['sent_at'])) {
        $sentDate = new DateTime($offer['sent_at']);
        $currentDate = new DateTime();
        $daysDiff = $sentDate->diff($currentDate)->days;
        if ($daysDiff > 7) {
            $isExpired = true;
            $expiredOffers++;
            continue;
        }
    }
    
    switch ($offer['status']) {
        case 'sent':
            $pendingOffers++;
            break;
        case 'accepted':
            $acceptedOffers++;
            break;
        case 'rejected':
            $rejectedOffers++;
            break;
        case 'expired':
            $expiredOffers++;
            break;
    }
}

// Filter offers by status
$statusFilter = $_GET['status'] ?? 'all';
$filteredOffers = $offers;

if ($statusFilter !== 'all') {
    if ($statusFilter === 'pending') {
        $filteredOffers = array_filter($offers, function($offer) {
            if ($offer['status'] !== 'sent') return false;
            if (!empty($offer['sent_at'])) {
                $sentDate = new DateTime($offer['sent_at']);
                $currentDate = new DateTime();
                $daysDiff = $sentDate->diff($currentDate)->days;
                return $daysDiff <= 7;
            }
            return true;
        });
    } elseif ($statusFilter === 'expired') {
        $filteredOffers = array_filter($offers, function($offer) {
            if ($offer['status'] === 'expired') return true;
            if ($offer['status'] === 'sent' && !empty($offer['sent_at'])) {
                $sentDate = new DateTime($offer['sent_at']);
                $currentDate = new DateTime();
                $daysDiff = $sentDate->diff($currentDate)->days;
                return $daysDiff > 7;
            }
            return false;
        });
    } else {
        $filteredOffers = array_filter($offers, function($offer) use ($statusFilter) {
            return $offer['status'] === $statusFilter;
        });
    }
}

// Get status counts for filters
$statusCounts = [
    'all' => $totalOffers, 
    'pending' => $pendingOffers, 
    'accepted' => $acceptedOffers, 
    'rejected' => $rejectedOffers, 
    'expired' => $expiredOffers
];

// Status badge mapping
$offerStatusBadges = [
    'draft' => 'badge-draft',
    'sent' => 'badge-sent',
    'accepted' => 'badge-accepted',
    'rejected' => 'badge-rejected',
    'expired' => 'badge-expired'
];

$offerStatusLabels = [
    'draft' => 'Draft',
    'sent' => 'Pending Your Response',
    'accepted' => 'Accepted ✅',
    'rejected' => 'Declined',
    'expired' => 'Expired'
];

// Get interview count for sidebar
$interviewCount = 0;
if ($applicantId) {
    $interviewResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = $1 AND interview_date IS NOT NULL
    ", [$applicantId]);
    $interviewCount = (int)($interviewResult['count'] ?? 0);
}

// Get total applications for sidebar
$totalApplications = 0;
if ($applicantId) {
    $appResult = getRecord("
        SELECT COUNT(*) as count FROM applications WHERE applicant_id = $1
    ", [$applicantId]);
    $totalApplications = (int)($appResult['count'] ?? 0);
}
?>
<!-- HTML CONTENT REMAINS THE SAME -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Offers - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - OFFERS PAGE
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
            --warning-color: #f59e0b;
            --error-color: #dc2626;
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
        .header-logo {
    height: 2rem;
    width: auto;
    max-height: 2.5rem;
    object-fit: contain;
    border-radius: 0.375rem;
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
           STATS ROW
        ============================================= */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card .stat-icon.primary { background: #eef0ff; color: #4f46e5; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }

        .stat-card .stat-icon .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .stat-card .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           FILTERS
        ============================================= */
        .filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }

        .filter-btn {
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            transition: all var(--transition-fast);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        .filter-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 2px 10px rgba(79, 70, 229, 0.25);
        }

        .filter-btn .count {
            background: rgba(0,0,0,0.08);
            border-radius: var(--radius-full);
            padding: 0 0.375rem;
            font-size: 0.625rem;
            font-weight: 700;
        }

        .filter-btn.active .count {
            background: rgba(255,255,255,0.25);
        }

        /* =============================================
           OFFER CARDS
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
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
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--primary);
        }

        .card-header .count-badge {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
        }

        .card-body {
            padding: 0;
        }

        .offer-card {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            transition: all var(--transition-fast);
        }

        .offer-card:last-child {
            border-bottom: none;
        }

        .offer-card:hover {
            background: var(--bg-surface-low);
        }

        .offer-card .offer-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .offer-card .offer-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .offer-card .offer-company {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .offer-card .offer-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.5rem 1rem;
            margin: 0.5rem 0;
            padding: 0.5rem 0;
            border-top: 1px solid var(--slate-200);
            border-bottom: 1px solid var(--slate-200);
        }

        .offer-card .offer-details .detail-item {
            display: flex;
            flex-direction: column;
        }

        .offer-card .offer-details .detail-item .label {
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .offer-card .offer-details .detail-item .value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .offer-card .offer-details .detail-item .value.salary {
            color: #059669;
        }

        .offer-card .offer-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .offer-card .offer-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* =============================================
           BADGES
        ============================================= */
        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .badge-draft { background: #f3f4f6; color: #6b7280; }
        .badge-sent { background: #dbeafe; color: #2563eb; }
        .badge-accepted { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-expired { background: #fef3c7; color: #d97706; }

        /* =============================================
           BUTTONS
        ============================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8125rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }

        .btn-success {
            background: #059669;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary-container);
        }

        .btn-secondary {
            background: var(--bg-surface-low);
            color: var(--text-on-surface-variant);
            border: 1.5px solid var(--slate-200);
        }

        .btn-secondary:hover {
            background: var(--slate-100);
        }

        .btn-sm {
            padding: 0.25rem 0.625rem;
            font-size: 0.6875rem;
            border-radius: 0.375rem;
        }

        .btn .material-symbols-outlined {
            font-size: 1rem;
        }

        .btn-sm .material-symbols-outlined {
            font-size: 0.875rem;
        }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .empty-state .material-symbols-outlined {
            font-size: 3.5rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.5rem;
        }

        .empty-state h4 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .empty-state p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            max-width: 400px;
            margin: 0.25rem auto 0;
        }

        .empty-state .btn {
            margin-top: 1rem;
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

        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }

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
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar {
                position: fixed;
                transform: translateX(0) !important;
                box-shadow: var(--shadow-xl);
                height: 100vh;
            }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }

        @media (max-width: 767px) {
            .dashboard-sidebar {
                position: fixed;
                width: var(--sidebar-width);
                transform: translateX(-100%);
                box-shadow: var(--shadow-xl);
            }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .offer-card .offer-details {
                grid-template-columns: 1fr;
            }
            .offer-card .offer-footer {
                flex-direction: column;
                align-items: stretch;
            }
            .offer-card .offer-actions {
                width: 100%;
            }
            .offer-card .offer-actions .btn {
                flex: 1;
                justify-content: center;
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
            .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1.5rem; }
            .dashboard-sidebar.collapsed .sidebar-nav { padding: 1.5rem 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: flex-start; padding: 0.75rem 1rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: flex-start; padding: 0.5rem 0.75rem; }
        }

        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .stats-row { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.25rem; }
            .offer-card { padding: 0.875rem 1rem; }
            .offer-card .offer-title { font-size: 0.875rem; }
            .filters { overflow-x: auto; flex-wrap: nowrap; }
            .filter-btn { font-size: 0.6875rem; padding: 0.25rem 0.625rem; }
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
    .sidebar-logo {
    width: 3.5rem;
    height: 3.5rem;
    object-fit: contain;
    border-radius: 0.75rem;
    display: block;
    margin: 0 auto;
}

/* For collapsed sidebar */
.dashboard-sidebar.collapsed .sidebar-logo {
    width: 2.5rem;
    height: 2.5rem;
}

/* If using Option 2 - background image on icon */
.sidebar-brand-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 1.75rem;
    background-size: contain !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-color: transparent !important;
    flex-shrink: 0;
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
    <div class="sidebar-brand-card">
        <img src="logo.png" alt="ISMERS" class="sidebar-logo">
        <p class="sidebar-brand-category">Applicant Portal</p>
    </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applications.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="offers.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'offers.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">My Offers</span>
                <span class="nav-badge"><?php echo $pendingOffers; ?></span>
            </a>

            <a href="interview.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'interview.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
                <span class="nav-badge"><?php echo $interviewCount; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'job_search.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">search</span>
                <span class="nav-text">Job Search</span>
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
<img src="logo.png" alt="ISMERS" class="logo" style="height: 2rem; width: auto;">     
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
                        <span>My Offers</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> 
                            (<?php echo count($filteredOffers); ?> offers)
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        Updated <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>My Offers</h1>
                        <p>View and manage all your job offers in one place</p>
                    </div>
                    <a href="job_search.php" class="btn-primary">
                        <span class="material-symbols-outlined">search</span>
                        Browse Jobs
                    </a>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <span class="material-symbols-outlined">description</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalOffers; ?></div>
                            <div class="stat-label">Total Offers</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">pending</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $pendingOffers; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $acceptedOffers; ?></div>
                            <div class="stat-label">Accepted</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <span class="material-symbols-outlined">cancel</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $rejectedOffers + $expiredOffers; ?></div>
                            <div class="stat-label">Declined / Expired</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters">
                    <?php
                    $filterLabels = [
                        'all' => 'All',
                        'pending' => 'Pending',
                        'accepted' => 'Accepted',
                        'rejected' => 'Declined',
                        'expired' => 'Expired'
                    ];
                    foreach ($filterLabels as $key => $label):
                        $count = $statusCounts[$key] ?? 0;
                    ?>
                        <a href="?status=<?php echo $key; ?>" 
                           class="filter-btn <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                            <span class="count"><?php echo $count; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Offers List -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">description</span>
                            <?php echo $statusFilter === 'all' ? 'All Offers' : ucfirst($statusFilter) . ' Offers'; ?>
                        </h3>
                        <span class="count-badge"><?php echo count($filteredOffers); ?> offers</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($filteredOffers)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">description</span>
                                <h4>No Offers Found</h4>
                                <p>
                                    <?php if ($statusFilter !== 'all'): ?>
                                        You don't have any <?php echo $statusFilter; ?> offers.
                                    <?php else: ?>
                                        You haven't received any job offers yet. Keep applying!
                                    <?php endif; ?>
                                </p>
                                <a href="job_search.php" class="btn btn-primary" style="margin-top:0.75rem;">
                                    <span class="material-symbols-outlined">search</span>
                                    Browse Jobs
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($filteredOffers as $offer): 
                                // Check if offer is expired
                                $isExpired = false;
                                if ($offer['status'] === 'sent' && !empty($offer['sent_at'])) {
                                    $sentDate = new DateTime($offer['sent_at']);
                                    $currentDate = new DateTime();
                                    $daysDiff = $sentDate->diff($currentDate)->days;
                                    $isExpired = $daysDiff > 7;
                                }
                                $status = $isExpired ? 'expired' : $offer['status'];
                            ?>
                                <div class="offer-card">
                                    <div class="offer-header">
                                        <div>
                                            <div class="offer-title"><?php echo htmlspecialchars($offer['job_title']); ?></div>
                                            <div class="offer-company"><?php echo htmlspecialchars($offer['company_name']); ?></div>
                                        </div>
                                        <span class="badge <?php echo $offerStatusBadges[$status] ?? 'badge-draft'; ?>">
                                            <?php echo $offerStatusLabels[$status] ?? ucfirst($status); ?>
                                        </span>
                                    </div>

                                    <div class="offer-details">
                                        <div class="detail-item">
                                            <span class="label">Salary Offered</span>
                                            <span class="value salary">₱<?php echo number_format($offer['salary_offered'] ?? 0, 2); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Offer Date</span>
                                            <span class="value"><?php echo date('M d, Y', strtotime($offer['offer_date'])); ?></span>
                                        </div>
                                        <?php if (!empty($offer['start_date'])): ?>
                                        <div class="detail-item">
                                            <span class="label">Start Date</span>
                                            <span class="value"><?php echo date('M d, Y', strtotime($offer['start_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($offer['sent_at'])): ?>
                                        <div class="detail-item">
                                            <span class="label">Sent On</span>
                                            <span class="value"><?php echo date('M d, Y', strtotime($offer['sent_at'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($status === 'sent' && !$isExpired): ?>
                                        <div class="detail-item">
                                            <span class="label">Expires</span>
                                            <span class="value" style="color:#d97706;">
                                                <?php echo date('M d, Y', strtotime($offer['sent_at'] . ' +7 days')); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="offer-footer">
                                        <?php if (!empty($offer['benefits'])): ?>
                                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">favorite</span>
                                                <?php echo htmlspecialchars(substr($offer['benefits'], 0, 60)) . (strlen($offer['benefits']) > 60 ? '...' : ''); ?>
                                            </div>
                                        <?php else: ?>
                                            <div></div>
                                        <?php endif; ?>

                                        <div class="offer-actions">
                                            <?php if ($status === 'sent' && !$isExpired): ?>
                                                <a href="accept_offer.php?id=<?php echo $offer['id']; ?>" class="btn btn-success btn-sm">
                                                    <span class="material-symbols-outlined">check_circle</span>
                                                    View & Respond
                                                </a>
                                            <?php elseif ($status === 'accepted'): ?>
                                                <span class="btn btn-success btn-sm" style="background:#065f46; cursor:default;">
                                                    <span class="material-symbols-outlined">celebration</span>
                                                    Accepted 🎉
                                                </span>
                                            <?php elseif ($status === 'rejected'): ?>
                                                <span class="btn btn-secondary btn-sm" style="cursor:default;">
                                                    <span class="material-symbols-outlined">cancel</span>
                                                    Declined
                                                </span>
                                            <?php elseif ($status === 'expired'): ?>
                                                <span class="btn btn-secondary btn-sm" style="cursor:default; background:#fef3c7; color:#92400e; border-color:#fcd34d;">
                                                    <span class="material-symbols-outlined">warning</span>
                                                    Expired
                                                </span>
                                            <?php endif; ?>
                                            <a href="job_details.php?id=<?php echo $offer['job_id']; ?>" class="btn btn-secondary btn-sm">
                                                <span class="material-symbols-outlined">visibility</span>
                                                View Job
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

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', openMobileSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        }

        document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
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

        if (profileToggle && profileMenu) {
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
        }

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
// SESSION ACTIVITY MONITOR
// =============================================

let sessionTimer = null;
let warningShown = false;
const SESSION_TIMEOUT = <?php echo SESSION_TIMEOUT_SECONDS; ?>; // 7 minutes
const WARNING_TIME = 60; // Show warning 60 seconds before timeout

/**
 * Update session timer display
 */
function updateSessionTimer() {
    // Get remaining time from server
    fetch('check_session.php')
        .then(response => response.json())
        .then(data => {
            const remaining = data.remaining;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            
            // Update timer display if exists
            const timerEl = document.getElementById('sessionTimer');
            if (timerEl) {
                timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                
                // Change color when running low
                if (remaining < 60) {
                    timerEl.style.color = '#dc2626';
                    timerEl.style.fontWeight = 'bold';
                } else if (remaining < 120) {
                    timerEl.style.color = '#f59e0b';
                } else {
                    timerEl.style.color = '';
                }
            }
            
            // Show warning modal if session is about to expire
            if (remaining <= WARNING_TIME && !warningShown && remaining > 0) {
                warningShown = true;
                showSessionWarning(remaining);
            }
            
            // If session expired, redirect
            if (remaining <= 0) {
                window.location.href = '../../login.php?timeout=1';
            }
        })
        .catch(error => {
            console.log('Session check error:', error);
        });
}

/**
 * Show session expiration warning
 */
function showSessionWarning(remaining) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('sessionWarningModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sessionWarningModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        `;
        
        modal.innerHTML = `
            <div style="
                background: white;
                border-radius: 1.5rem;
                max-width: 440px;
                width: 100%;
                padding: 2rem;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: slideUp 0.3s ease;
                text-align: center;
            ">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">⏰</div>
                <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Session Expiring Soon</h2>
                <p style="color: #464555; font-size: 0.875rem; margin-bottom: 1rem;">
                    Your session will expire in <strong id="warningTimer" style="color: #dc2626;">60</strong> seconds.
                    Please click "Stay Logged In" to continue.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button onclick="extendSession()" style="
                        padding: 0.625rem 1.5rem;
                        background: #4f46e5;
                        color: white;
                        border: none;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Stay Logged In</button>
                    <button onclick="logoutNow()" style="
                        padding: 0.625rem 1.5rem;
                        background: #fef2f2;
                        color: #dc2626;
                        border: 1px solid #fecaca;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Logout</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Show modal
    modal.style.display = 'flex';
    
    // Update countdown inside modal
    const warningTimer = document.getElementById('warningTimer');
    if (warningTimer) {
        let countdown = remaining;
        const interval = setInterval(() => {
            countdown--;
            warningTimer.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(interval);
                window.location.href = '../../login.php?timeout=1';
            }
        }, 1000);
        
        // Store interval to clear it when extending
        modal.dataset.interval = interval;
    }
}

/**
 * Extend session (reset timer)
 */
function extendSession() {
    // Clear any existing warning interval
    const modal = document.getElementById('sessionWarningModal');
    if (modal && modal.dataset.interval) {
        clearInterval(parseInt(modal.dataset.interval));
    }
    
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            if (modal) modal.style.display = 'none';
            showToast('Session extended!', 'success');
        }
    })
    .catch(error => {
        console.log('Extend session error:', error);
    });
}

/**
 * Logout immediately
 */
function logoutNow() {
    window.location.href = '../../logout.php';
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.style.cssText = `
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        padding: 0.875rem 1.5rem;
        border-radius: 0.75rem;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        z-index: 100000;
        animation: slideUp 0.4s ease-out;
    `;
    if (type === 'success') toast.style.background = '#22c55e';
    else if (type === 'error') toast.style.background = '#dc2626';
    else toast.style.background = '#4f46e5';
    
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
// TRACK USER ACTIVITY
// =============================================

let activityTimer = null;

function resetActivityTimer() {
    // Reset the server-side timer via AJAX
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            // Hide warning modal if shown
            const modal = document.getElementById('sessionWarningModal');
            if (modal) modal.style.display = 'none';
        }
    })
    .catch(error => console.log('Reset timer error:', error));
}

// Track user activity events
const activityEvents = ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'];
activityEvents.forEach(event => {
    document.addEventListener(event, () => {
        resetActivityTimer();
    });
});

// =============================================
// START SESSION TIMER
// =============================================

// Update timer every 10 seconds
sessionTimer = setInterval(updateSessionTimer, 10000);

// Initial update
updateSessionTimer();

console.log('⏰ Session timeout: 7 minutes');
console.log('🔄 Activity tracking enabled');

        // =============================================
        // 5. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (window.innerWidth <= 768) {
                    closeMobileSidebar();
                }
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        });

        console.log('📄 ISMERS Offers Page loaded successfully!');
        console.log('📊 Total Offers: <?php echo $totalOffers; ?>, Pending: <?php echo $pendingOffers; ?>');
    </script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>