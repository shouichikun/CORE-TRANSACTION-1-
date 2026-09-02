<?php
// portals/client/offers.php - AI-Powered Client Job Offers Management
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();
require_once '../../app/ai/AiService.php';

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

$userId = (int)$_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Client User';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'client';

// =============================================
// GET CLIENT PROFILE - Get the correct client.id
// =============================================
$client = getRecord("
    SELECT c.*, u.email as user_email, u.full_name
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.user_id = $1
", [$userId]);

if (!$client) {
    $client = ['company_name' => 'Your Company', 'id' => 0];
}

$companyName = $client['company_name'] ?? 'Your Company';
$clientId = (int)($client['id'] ?? 0);

// =============================================
// GET OFFERS FOR THIS CLIENT - PostgreSQL
// =============================================
$filter = $_GET['filter'] ?? 'all';

// ✅ FIXED: Properly select job_title from job_orders
$sql = "SELECT 
        o.*, 
        u.first_name, 
        u.last_name, 
        u.email,
        a.id as application_id,
        jo.id as job_id,
        jo.title as job_title,
        jo.location as job_location,
        jo.job_type as job_type,
        ag.agency_name,
        ag.agency_code,
        jo.client_id
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        LEFT JOIN recruitment_agencies ag ON jo.agency_id = ag.id
        WHERE jo.client_id = $1";

$params = [$clientId];

if ($filter !== 'all') {
    $sql .= " AND o.status = $2";
    $params[] = $filter;
}

$sql .= " ORDER BY 
    CASE o.status 
        WHEN 'pending' THEN 1 
        WHEN 'accepted' THEN 2 
        WHEN 'rejected' THEN 3 
        WHEN 'withdrawn' THEN 4 
        ELSE 5 
    END, 
    o.created_at DESC";

$offers = getRecords($sql, $params);

// Get status counts for filter
$statusCounts = ['all' => count($offers)];
$statuses = ['pending', 'accepted', 'rejected', 'withdrawn'];
foreach ($statuses as $status) {
    $countResult = getRecord("
        SELECT COUNT(*) as count FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = $1 AND o.status = $2
    ", [$clientId, $status]);
    $statusCounts[$status] = isset($countResult['count']) ? (int)$countResult['count'] : 0;
}

// Get pending agency count for sidebar badge
$pendingAgencyCount = 0;
if ($clientId > 0) {
    $pendingAgencies = getRecord("
        SELECT COUNT(*) as count FROM agency_applications 
        WHERE client_id = $1 AND status = 'pending'
    ", [$clientId]);
    $pendingAgencyCount = (int)($pendingAgencies['count'] ?? 0);
}

// Get user profile for sidebar
$userProfile = getUserProfileData($userId);

// =============================================
// HANDLE OFFER STATUS UPDATE
// =============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_offer_status') {
        $offerId = intval($_POST['offer_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');
        
        if ($offerId > 0 && in_array($newStatus, ['accepted', 'rejected', 'withdrawn'])) {
            // Verify ownership
            $checkSql = "SELECT o.id FROM offers o
                         JOIN applications a ON o.application_id = a.id
                         JOIN job_orders jo ON a.job_order_id = jo.id
                         WHERE o.id = $1 AND jo.client_id = $2";
            $check = getRecord($checkSql, [$offerId, $clientId]);
            
            if ($check) {
                $updateSql = "UPDATE offers SET status = $1, updated_at = NOW() WHERE id = $2";
                $result = updateRecord($updateSql, [$newStatus, $offerId]);
                
                if ($result) {
                    // If accepted, mark applicant as hired
                    if ($newStatus === 'accepted') {
                        $appSql = "SELECT a.applicant_id FROM offers o
                                   JOIN applications a ON o.application_id = a.id
                                   WHERE o.id = $1";
                        $appResult = getRecord($appSql, [$offerId]);
                        if ($appResult && isset($appResult['applicant_id'])) {
                            // You might need a function to update applicant status
                            // updateApplicantHiredStatus($appResult['applicant_id'], true);
                        }
                    }
                    
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => 'Offer status updated successfully!'
                    ];
                } else {
                    $_SESSION['flash'] = [
                        'type' => 'error',
                        'message' => 'Error updating offer status.'
                    ];
                }
            } else {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => 'Offer not found or access denied.'
                ];
            }
            header('Location: offers.php?filter=' . $filter);
            exit;
        }
    }

    // =============================================
    // HANDLE OFFER EDIT (UPDATE)
    // =============================================
    if ($_POST['action'] === 'update_offer') {
        $offerId = intval($_POST['offer_id'] ?? 0);
        $salaryOffered = floatval($_POST['salary_offered'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $benefits = trim($_POST['benefits'] ?? '');
        $status = trim($_POST['status'] ?? 'pending');
        $documentPath = trim($_POST['document_path'] ?? '');
        
        // Validate
        $errors = [];
        if ($offerId <= 0) $errors[] = 'Invalid offer ID.';
        if ($salaryOffered <= 0) $errors[] = 'Please enter a valid salary amount.';
        
        // Convert empty date to null for PostgreSQL
        if ($startDate === '') {
            $startDate = null;
        }
        
        if (empty($errors)) {
            // Check if offer belongs to this client
            $checkSql = "SELECT o.id FROM offers o
                         JOIN applications a ON o.application_id = a.id
                         JOIN job_orders jo ON a.job_order_id = jo.id
                         WHERE o.id = $1 AND jo.client_id = $2";
            $check = getRecord($checkSql, [$offerId, $clientId]);
            
            if ($check) {
                $updateSql = "UPDATE offers SET 
                              salary_offered = $1, 
                              start_date = $2, 
                              benefits = $3, 
                              status = $4,
                              document_path = $5,
                              updated_at = NOW()
                              WHERE id = $6";
                
                $params = [
                    $salaryOffered,
                    $startDate,
                    $benefits,
                    $status,
                    $documentPath ?: null,
                    $offerId
                ];
                
                $result = executeQuery($updateSql, $params);
                
                if ($result) {
                    if (function_exists('logActivity')) {
                        logActivity($userId, 'Offer Updated', 'offers', $offerId, 
                                   'Client updated offer details');
                    }
                    
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => '✅ Offer updated successfully!'
                    ];
                } else {
                    $_SESSION['flash'] = [
                        'type' => 'error',
                        'message' => 'Failed to update offer. Please try again.'
                    ];
                }
            } else {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => 'Offer not found or you don\'t have permission to edit it.'
                ];
            }
        } else {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => implode('<br>', $errors)
            ];
        }
        
        header('Location: offers.php?filter=' . $filter);
        exit;
    }
}

// Display flash messages
$flashMessage = '';
$flashType = '';
if (isset($_SESSION['flash'])) {
    $flashMessage = $_SESSION['flash']['message'] ?? '';
    $flashType = $_SESSION['flash']['type'] ?? 'info';
    unset($_SESSION['flash']);
}

// =============================================
// HELPER: Get status badge class
// =============================================
function getOfferStatusBadge($status) {
    $badges = [
        'pending' => 'badge-warning',
        'accepted' => 'badge-success',
        'rejected' => 'badge-danger',
        'withdrawn' => 'badge-secondary'
    ];
    return $badges[$status] ?? 'badge-secondary';
}

function getOfferStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn'
    ];
    return $labels[$status] ?? ucfirst($status);
}

// Format currency
function formatCurrency($amount) {
    if ($amount === null || $amount === 0) return '₱0.00';
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Job Offers - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* =============================================
           TOAST - SMALL AND SUBTLE
           ============================================= */
        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 500;
            font-size: 0.8rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideDown 0.35s ease-out;
            max-width: 320px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toast .material-symbols-outlined { font-size: 1rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --primary: #4f46e5;
            --primary-container: #eef0ff;
            --on-primary-fixed-variant: #4338ca;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
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

        .badge-success { background: #d1fae5; color: #059669; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-secondary { background: #f1f5f9; color: #64748b; }

        .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.55rem;
            font-weight: 700;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .ai-badge .material-symbols-outlined { font-size: 0.7rem; }

        .btn-ai {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
        }
        .btn-ai:hover {
            background: linear-gradient(135deg, #6d28d9, #4338ca);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .btn-ai .material-symbols-outlined { font-size: 1rem; }
        .btn-ai:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

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

        /* ===== SIDEBAR ===== */
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
            object-fit: cover;
        }
        .sidebar-footer .user-card .avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
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
            object-fit: cover;
        }
        .profile-dropdown-toggle .avatar-small img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
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

        /* ===== BREADCRUMB ===== */
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
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }

        /* ===== OFFER FILTERS ===== */
        .offer-filters {
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

        /* ===== OFFER CARDS ===== */
        .offer-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            margin-bottom: 0.75rem;
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-xs);
            position: relative;
        }
        .offer-card:hover { box-shadow: var(--shadow-md); border-color: var(--slate-300); }

        .offer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .offer-card-left {
            flex: 1;
            min-width: 200px;
        }

        .offer-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.375rem;
        }

        .offer-card-title .offer-badge {
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
        }

        .offer-card-applicant {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .offer-card-applicant .material-symbols-outlined {
            font-size: 1rem;
            color: var(--primary);
        }

        .offer-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.25rem;
            margin-top: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .offer-card-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .offer-card-meta .meta-item .material-symbols-outlined {
            font-size: 0.875rem;
        }

        .offer-card-salary {
            margin-top: 0.625rem;
            padding: 0.5rem 0.75rem;
            background: var(--primary-container);
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            font-size: 0.9375rem;
            color: var(--primary);
        }

        .offer-card-salary .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--primary);
        }

        .offer-card-actions {
            display: flex;
            gap: 0.375rem;
            margin-top: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .offer-card-actions .status-select {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.6875rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
        }

        .offer-card-actions .status-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .offer-card-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .offer-status-badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .offer-card-agency {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.0625rem 0.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-top: 0.25rem;
        }

        .offer-card-agency .material-symbols-outlined {
            font-size: 0.875rem;
            color: var(--primary);
        }

        .offer-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.875rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .offer-card-stats {
            display: flex;
            gap: 1.25rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .offer-card-stats span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .offer-card-stats .material-symbols-outlined {
            font-size: 0.875rem;
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
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-accepted { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }
        .badge-withdrawn { background: #f1f5f9; color: #64748b; }

        .badge-lg { padding: 0.25rem 0.875rem; font-size: 0.75rem; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.75rem;
        }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.8125rem; }

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

        /* ===== MODAL STYLES ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
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
            max-width: 44rem;
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
            background: var(--bg-surface);
        }
        .modal-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-on-surface);
        }
        .modal-header h2 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.375rem;
            border-radius: 0.375rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover {
            background: var(--bg-surface-low);
        }
        .modal-close .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .modal-body {
            padding: 1.25rem 1.5rem;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-shrink: 0;
            flex-wrap: wrap;
            background: var(--bg-surface);
        }

        .form-group {
            margin-bottom: 1rem;
        }
        .form-group:last-child {
            margin-bottom: 0;
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
        .form-group .helper-text {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.1875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .form-group .helper-text .material-symbols-outlined {
            font-size: 0.875rem;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
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
        .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5168' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }
        select.form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

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
            .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: none; }
            .offer-card-header { flex-direction: column; }
            .offer-card-right { align-items: flex-start; width: 100%; }
            .offer-card-actions { flex-direction: column; width: 100%; }
            .offer-card-actions .status-select { width: 100%; }
            .offer-card-actions .btn { width: 100%; justify-content: center; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .offer-card { padding: 1rem; }
            .offer-card-meta { flex-direction: column; gap: 0.25rem; }
            .offer-card-stats { flex-direction: column; gap: 0.25rem; }
            .offer-filters { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 0.25rem; -webkit-overflow-scrolling: touch; }
            .filter-btn { font-size: 0.6875rem; padding: 0.25rem 0.75rem; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
        }

        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }

        /* ===== TOAST SYSTEM ===== */
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
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
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
            <a href="offers.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'offers.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Offers</span>
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
        <!-- ===== SIDEBAR FOOTER ===== -->
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Offers</span>
                <span class="ai-badge" style="margin-left:0.5rem;">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    AI Powered
                </span>
            </div>
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
                    <button class="dropdown-item" onclick="window.location.href='profile.php'">
                        <span class="material-symbols-outlined">person</span> Profile
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
                <?php if ($flashMessage): ?>
                    <div class="toast <?php echo $flashType; ?>" id="toastMessage">
                        <span class="material-symbols-outlined">
                            <?php echo $flashType === 'success' ? 'check_circle' : 'error'; ?>
                        </span>
                        <?php echo htmlspecialchars($flashMessage); ?>
                    </div>
                    <script>
                        setTimeout(() => {
                            const toast = document.getElementById('toastMessage');
                            if (toast) {
                                toast.style.opacity = '0';
                                toast.style.transform = 'translateY(-15px)';
                                toast.style.transition = 'all 0.4s ease';
                                setTimeout(() => toast.remove(), 400);
                            }
                        }, 4000);
                    </script>
                <?php endif; ?>

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">description</span>
                        <span>Job Offers</span>
                        <span style="font-weight:400;">●</span>
                        <span style="font-weight:400;"><?php echo htmlspecialchars($companyName); ?></span>
                    </div>
                    <span class="breadcrumb-meta">Total Offers: <?php echo count($offers); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Job Offers</h1>
                        <p>View and manage job offers sent to applicants for your positions</p>
                    </div>
                </div>

                <!-- Offer Filters -->
                <div class="offer-filters">
                    <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $statusCounts['all']; ?></span>
                    </a>
                    <a href="?filter=pending" class="filter-btn <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                        Pending <span class="count"><?php echo $statusCounts['pending']; ?></span>
                    </a>
                    <a href="?filter=accepted" class="filter-btn <?php echo $filter === 'accepted' ? 'active' : ''; ?>">
                        Accepted <span class="count"><?php echo $statusCounts['accepted']; ?></span>
                    </a>
                    <a href="?filter=rejected" class="filter-btn <?php echo $filter === 'rejected' ? 'active' : ''; ?>">
                        Rejected <span class="count"><?php echo $statusCounts['rejected']; ?></span>
                    </a>
                    <a href="?filter=withdrawn" class="filter-btn <?php echo $filter === 'withdrawn' ? 'active' : ''; ?>">
                        Withdrawn <span class="count"><?php echo $statusCounts['withdrawn']; ?></span>
                    </a>
                </div>

                <!-- Offer Listings -->
                <?php if (empty($offers)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">description</span>
                        <h3>No offers found</h3>
                        <p>
                            <?php if ($filter !== 'all'): ?>
                                No offers with status "<?php echo htmlspecialchars($filter); ?>".
                                <a href="?filter=all" style="color:var(--primary); font-weight:600;">View all offers</a>
                            <?php else: ?>
                                You haven't received any job offers yet. Offers will appear here once HR sends them to applicants for your job postings.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($offers as $offer): 
                        $isPending = $offer['status'] === 'pending';
                        $isAccepted = $offer['status'] === 'accepted';
                        $isRejected = $offer['status'] === 'rejected';
                        $isWithdrawn = $offer['status'] === 'withdrawn';
                        $statusClass = $isPending ? 'badge-pending' : ($isAccepted ? 'badge-accepted' : ($isRejected ? 'badge-rejected' : 'badge-withdrawn'));
                        $statusLabel = ucfirst($offer['status'] ?? 'Pending');
                        $applicantName = htmlspecialchars(($offer['first_name'] ?? '') . ' ' . ($offer['last_name'] ?? ''));
                        // ✅ FIXED: Use the job_title from the query, fallback to 'Position' if empty
                        $jobTitle = !empty($offer['job_title']) ? htmlspecialchars($offer['job_title']) : 'Position';
                        $location = htmlspecialchars($offer['job_location'] ?? 'Remote');
                        $jobType = htmlspecialchars($offer['job_type'] ?? 'Full-time');
                        $salary = !empty($offer['salary_offered']) ? '₱' . number_format($offer['salary_offered'], 2) : 'N/A';
                        $agency = htmlspecialchars($offer['agency_name'] ?? '');
                    ?>
                        <div class="offer-card" id="offer-<?php echo $offer['id']; ?>">
                            <div class="offer-card-header">
                                <div class="offer-card-left">
                                    <div class="offer-card-title">
                                        <?php echo $jobTitle; ?>
                                        <span class="badge <?php echo $statusClass; ?> badge-lg">
                                            <?php echo $statusLabel; ?>
                                        </span>
                                        <?php if ($isPending): ?>
                                            <span style="font-size:0.6rem; color:var(--text-on-surface-variant); font-weight:400;">
                                                ⏳ Awaiting response
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="offer-card-applicant">
                                        <span class="material-symbols-outlined">person</span>
                                        <?php echo $applicantName ?: 'Unknown Applicant'; ?>
                                        <span style="font-size:0.75rem; font-weight:400; color:var(--text-on-surface-variant);">
                                            <?php echo htmlspecialchars($offer['email'] ?? ''); ?>
                                        </span>
                                    </div>

                                    <div class="offer-card-meta">
                                        <span class="meta-item">
                                            <span class="material-symbols-outlined">location_on</span>
                                            <?php echo $location; ?>
                                        </span>
                                        <span class="meta-item">
                                            <span class="material-symbols-outlined">work</span>
                                            <?php echo $jobType; ?>
                                        </span>
                                        <span class="meta-item">
                                            <span class="material-symbols-outlined">calendar_today</span>
                                            Offered: <?php echo date('M d, Y', strtotime($offer['created_at'] ?? 'now')); ?>
                                        </span>
                                    </div>

                                    <div class="offer-card-salary">
                                        <span class="material-symbols-outlined">payments</span>
                                        Salary Offered: <?php echo $salary; ?>
                                    </div>

                                    <?php if ($agency): ?>
                                        <div class="offer-card-agency">
                                            <span class="material-symbols-outlined">apartment</span>
                                            Agency: <?php echo $agency; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($offer['document_path'])): ?>
                                        <div style="margin-top:0.375rem;">
                                            <a href="<?php echo htmlspecialchars($offer['document_path']); ?>" target="_blank" class="btn btn-sm btn-outline">
                                                <span class="material-symbols-outlined" style="font-size:0.875rem;">description</span>
                                                View Offer Letter
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="offer-card-right">
                                    <?php if ($isPending): ?>
                                        <div style="display:flex; gap:0.375rem; flex-wrap:wrap;">
                                            <button class="btn btn-sm btn-outline" onclick="openEditModal(<?php echo $offer['id']; ?>)">
                                                <span class="material-symbols-outlined" style="font-size:0.875rem;">edit</span>
                                                Edit Offer
                                            </button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_offer_status">
                                                <input type="hidden" name="offer_id" value="<?php echo $offer['id']; ?>">
                                                <input type="hidden" name="new_status" value="accepted">
                                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Accept this offer? This will mark the applicant as hired.')">
                                                    <span class="material-symbols-outlined" style="font-size:0.875rem;">check</span>
                                                    Accept
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_offer_status">
                                                <input type="hidden" name="offer_id" value="<?php echo $offer['id']; ?>">
                                                <input type="hidden" name="new_status" value="rejected">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this offer?')">
                                                    <span class="material-symbols-outlined" style="font-size:0.875rem;">close</span>
                                                    Reject
                                                </button>
                                            </form>
                                        </div>
                                        <span style="font-size:0.65rem; color:var(--text-on-surface-variant);">⏳ Awaiting decision</span>
                                    <?php elseif ($isAccepted): ?>
                                        <span style="font-size:0.75rem; color:#059669; font-weight:600; display:flex; align-items:center; gap:0.25rem;">
                                            <span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span>
                                            Accepted ✓
                                        </span>
                                    <?php elseif ($isRejected): ?>
                                        <span style="font-size:0.75rem; color:#dc2626; font-weight:600; display:flex; align-items:center; gap:0.25rem;">
                                            <span class="material-symbols-outlined" style="font-size:1rem;">cancel</span>
                                            Rejected ✗
                                        </span>
                                    <?php elseif ($isWithdrawn): ?>
                                        <span style="font-size:0.75rem; color:#64748b; font-weight:600; display:flex; align-items:center; gap:0.25rem;">
                                            <span class="material-symbols-outlined" style="font-size:1rem;">block</span>
                                            Withdrawn
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="offer-card-footer">
                                <div class="offer-card-stats">
                                    <?php if ($isAccepted): ?>
                                        <span style="color:#059669;">
                                            <span class="material-symbols-outlined">check_circle</span>
                                            Applicant hired
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($offer['application_id'])): ?>
                                        <span>
                                            <span class="material-symbols-outlined">info</span>
                                            Application #<?php echo $offer['application_id']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isPending): ?>
                                    <span style="font-size:0.7rem; color:var(--text-on-surface-variant);">
                                        ⏳ Waiting for applicant response
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ===== EDIT OFFER MODAL ===== -->
    <div class="modal-overlay" id="editOfferModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">edit</span>
                    Edit Job Offer
                </h2>
                <button class="modal-close" onclick="closeEditModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form method="POST" action="" id="editOfferForm">
                <input type="hidden" name="action" value="update_offer">
                <input type="hidden" name="offer_id" id="editOfferId">
                <div class="modal-body">
                    <!-- Applicant Info -->
                    <div style="background:var(--bg-surface-low); padding:0.625rem 1rem; border-radius:var(--radius-md); margin-bottom:1.25rem; border:1px solid var(--slate-200);">
                        <div style="display:flex; gap:1.25rem; flex-wrap:wrap; font-size:0.8125rem; color:var(--text-on-surface);">
                            <span><strong style="font-weight:600; color:var(--text-on-surface-variant);">Applicant:</strong> <span id="editApplicantName" style="font-weight:500;">Loading...</span></span>
                            <span><strong style="font-weight:600; color:var(--text-on-surface-variant);">Job:</strong> <span id="editJobTitle" style="font-weight:500;">Loading...</span></span>
                            <span><strong style="font-weight:600; color:var(--text-on-surface-variant);">Agency:</strong> <span id="editAgencyName" style="font-weight:500;">Loading...</span></span>
                        </div>
                    </div>

                    <!-- Salary Offered -->
                    <div class="form-group">
                        <label for="editSalaryOffered">Salary Offered <span class="required">*</span></label>
                        <div style="display:flex; gap:0.5rem;">
                            <span style="display:inline-flex; align-items:center; padding:0 0.75rem; background:var(--bg-surface-low); border:1.5px solid var(--slate-200); border-radius:0.5rem; border-right:none; border-radius:0.5rem 0 0 0.5rem; font-weight:600; font-size:0.8125rem; color:var(--text-on-surface-variant);">₱</span>
                            <input type="number" id="editSalaryOffered" name="salary_offered" class="form-control" style="border-radius:0 0.5rem 0.5rem 0; flex:1;" placeholder="0.00" min="0" step="0.01" required>
                        </div>
                        <div class="helper-text">
                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">info</span>
                            Enter the monthly salary amount in PHP
                        </div>
                    </div>

                    <div class="form-row">
                        <!-- Start Date -->
                        <div class="form-group">
                            <label for="editStartDate">Start Date</label>
                            <input type="date" id="editStartDate" name="start_date" class="form-control">
                        </div>
                        <!-- Status -->
                        <div class="form-group">
                            <label for="editStatus">Status</label>
                            <select id="editStatus" name="status" class="form-control">
                                <option value="pending">Pending</option>
                                <option value="accepted">Accepted</option>
                                <option value="rejected">Rejected</option>
                                <option value="withdrawn">Withdrawn</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                    </div>

                    <!-- Benefits -->
                    <div class="form-group">
                        <label for="editBenefits">Benefits</label>
                        <textarea id="editBenefits" name="benefits" class="form-control" 
                                  placeholder="List the benefits offered (e.g., Health insurance, 13th month pay, etc.)" rows="3"></textarea>
                        <div class="helper-text">
                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">info</span>
                            Include all benefits included in this offer
                        </div>
                    </div>

                    <!-- Document Path -->
                    <div class="form-group">
                        <label for="editDocumentPath">Document Path <span style="font-weight:400; color:var(--text-on-surface-variant);">(Optional)</span></label>
                        <input type="text" id="editDocumentPath" name="document_path" class="form-control" 
                               placeholder="e.g., /uploads/offers/offer_123.pdf">
                        <div class="helper-text">
                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">description</span>
                            Path to the offer document file (if available)
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editSubmitBtn">
                        <span class="material-symbols-outlined">save</span>
                        Update Offer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================= JAVASCRIPT ============================================= -->
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
        // 4. TOAST SYSTEM
        // =============================================
        function showToast(message, type = 'info') {
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info' };
            toast.innerHTML = `<span class="material-symbols-outlined">${iconMap[type] || 'info'}</span> ${message}`;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-10px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // =============================================
        // 5. EDIT OFFER MODAL
        // =============================================
        const offersData = <?php echo json_encode($offers); ?>;

        function openEditModal(offerId) {
            const offer = offersData.find(o => o.id === offerId);
            if (!offer) {
                showToast('Offer not found.', 'error');
                return;
            }

            document.getElementById('editOfferId').value = offer.id;
            document.getElementById('editApplicantName').textContent = (offer.first_name || '') + ' ' + (offer.last_name || '');
            document.getElementById('editJobTitle').textContent = offer.job_title || 'N/A';
            document.getElementById('editAgencyName').textContent = offer.agency_name || 'N/A';
            document.getElementById('editSalaryOffered').value = offer.salary_offered || '';
            document.getElementById('editStartDate').value = offer.start_date || '';
            document.getElementById('editBenefits').value = offer.benefits || '';
            document.getElementById('editStatus').value = offer.status || 'pending';
            document.getElementById('editDocumentPath').value = offer.document_path || '';

            const modal = document.getElementById('editOfferModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            const modal = document.getElementById('editOfferModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        const editModalOverlay = document.getElementById('editOfferModal');
        if (editModalOverlay) {
            editModalOverlay.addEventListener('click', function(e) {
                if (e.target === this) closeEditModal();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
                closeMobileSidebar();
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // 6. FORM VALIDATION
        // =============================================
        document.getElementById('editOfferForm').addEventListener('submit', function(e) {
            const salary = document.getElementById('editSalaryOffered');
            const submitBtn = document.getElementById('editSubmitBtn');
            let errors = [];

            if (!salary || parseFloat(salary.value) <= 0) {
                errors.push('Please enter a valid salary amount.');
                if (salary) salary.style.borderColor = '#dc2626';
            }

            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n• ' + errors.join('\n• '));
                if (salary && salary.style.borderColor === '#dc2626') salary.focus();
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span> Updating...';
            }
        });

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
                    if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
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
        // 8. SESSION ACTIVITY MONITOR
        // =============================================
        let sessionTimer = null;
        const SESSION_TIMEOUT = 420; // 7 minutes
        const WARNING_TIME = 60;

        function updateSessionTimer() {
            fetch('check_session.php')
                .then(response => response.json())
                .then(data => {
                    if (data && data.remaining !== undefined) {
                        if (data.remaining <= 0) {
                            window.location.href = '../../login.php?timeout=1';
                        }
                    }
                })
                .catch(() => {});
        }

        sessionTimer = setInterval(updateSessionTimer, 30000);
        console.log('📋 ISMERS Client Job Offers loaded successfully!');
        console.log('📊 Total offers: <?php echo count($offers); ?>');
        
        // Debug: Log the first offer's job_title
        <?php if (!empty($offers)): ?>
        console.log('📝 First offer job_title:', '<?php echo addslashes($offers[0]['job_title'] ?? 'NULL'); ?>');
        <?php endif; ?>
    </script>
    <script src="/session_guard.js"></script>
</body>
</html>
