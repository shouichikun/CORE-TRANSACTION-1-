<?php
// portals/client/support.php - Client Support Ticket System
session_start();

// =============================================
// DEBUG MODE
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();

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

// Get client profile - PostgreSQL version
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
// GET PENDING AGENCY APPLICATIONS FOR SIDEBAR BADGE
// =============================================
$pendingAgencyCount = 0;
if ($clientId > 0) {
    $pendingAgencies = getRecord("
        SELECT COUNT(*) as count FROM agency_applications 
        WHERE client_id = $1 AND status = 'pending'
    ", [$clientId]);
    if ($pendingAgencies) {
        $pendingAgencyCount = (int)($pendingAgencies['count'] ?? 0);
    }
}

// =============================================
// GET SUPPORT TICKETS - PostgreSQL version
// =============================================
$tickets = getRecords("
    SELECT * FROM support_tickets 
    WHERE user_id = $1 
    ORDER BY created_at DESC
", [$userId]);

// =============================================
// CALCULATE SUMMARY STATS
// =============================================
$totalTickets = count($tickets);
$openTickets = 0;
$inProgressTickets = 0;
$resolvedTickets = 0;
$closedTickets = 0;

foreach ($tickets as $ticket) {
    switch ($ticket['status']) {
        case 'open':
            $openTickets++;
            break;
        case 'in_progress':
            $inProgressTickets++;
            break;
        case 'resolved':
            $resolvedTickets++;
            break;
        case 'closed':
            $closedTickets++;
            break;
    }
}

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // =============================================
    // SUBMIT TICKET - PostgreSQL version
    // =============================================
    if ($action === 'submit_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $priority = trim($_POST['priority'] ?? 'medium');
        $message_body = trim($_POST['message'] ?? '');
        
        $errors = [];
        if (empty($subject)) $errors[] = 'Please enter a subject.';
        if (empty($category)) $errors[] = 'Please select a category.';
        if (empty($message_body)) $errors[] = 'Please enter a message.';
        
        if (empty($errors)) {
            $sql = "INSERT INTO support_tickets (user_id, client_id, subject, category, priority, message, status, created_at, updated_at) 
                    VALUES ($1, $2, $3, $4, $5, $6, 'open', NOW(), NOW())";
            
            $result = insertRecord($sql, [
                $userId,
                $clientId,
                $subject,
                $category,
                $priority,
                $message_body
            ]);
            
            if ($result) {
                if (function_exists('logActivity')) {
                    logActivity($userId, 'Support Ticket Submitted', 'support_tickets', $result, 'Submitted ticket: ' . $subject);
                }
                $message = 'Support ticket submitted successfully! We\'ll get back to you soon.';
                $messageType = 'success';
                
                // Refresh data - PostgreSQL
                $tickets = getRecords("
                    SELECT * FROM support_tickets 
                    WHERE user_id = $1 
                    ORDER BY created_at DESC
                ", [$userId]);
            } else {
                $message = 'Failed to submit ticket. Please try again.';
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }
    
    // =============================================
    // ADD REPLY TO TICKET - PostgreSQL version
    // =============================================
    if ($action === 'add_reply') {
        $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
        $reply_message = trim($_POST['reply_message'] ?? '');
        
        if ($ticket_id > 0 && !empty($reply_message)) {
            $sql = "INSERT INTO support_ticket_replies (ticket_id, user_id, message, created_at) 
                    VALUES ($1, $2, $3, NOW())";
            
            $result = insertRecord($sql, [
                $ticket_id,
                $userId,
                $reply_message
            ]);
            
            if ($result) {
                // Update ticket status to in_progress if it was open
                $updateSql = "UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = $1 AND status = 'open'";
                updateRecord($updateSql, [$ticket_id]);
                
                if (function_exists('logActivity')) {
                    logActivity($userId, 'Support Ticket Reply', 'support_tickets', $ticket_id, 'Added reply to ticket #' . $ticket_id);
                }
                $message = 'Reply added successfully!';
                $messageType = 'success';
                
                // Refresh data - PostgreSQL
                $tickets = getRecords("
                    SELECT * FROM support_tickets 
                    WHERE user_id = $1 
                    ORDER BY created_at DESC
                ", [$userId]);
            } else {
                $message = 'Failed to add reply. Please try again.';
                $messageType = 'error';
            }
        } else {
            $message = 'Please enter a reply message.';
            $messageType = 'error';
        }
    }
    
    // =============================================
    // CLOSE TICKET - PostgreSQL version
    // =============================================
    if ($action === 'close_ticket') {
        $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
        
        if ($ticket_id > 0) {
            $sql = "UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = $1 AND user_id = $2";
            $result = updateRecord($sql, [$ticket_id, $userId]);
            
            if ($result) {
                if (function_exists('logActivity')) {
                    logActivity($userId, 'Support Ticket Closed', 'support_tickets', $ticket_id, 'Closed ticket #' . $ticket_id);
                }
                $message = 'Ticket closed successfully!';
                $messageType = 'success';
                
                // Refresh data - PostgreSQL
                $tickets = getRecords("
                    SELECT * FROM support_tickets 
                    WHERE user_id = $1 
                    ORDER BY created_at DESC
                ", [$userId]);
            } else {
                $message = 'Failed to close ticket.';
                $messageType = 'error';
            }
        }
    }
}

// =============================================
// GET TICKET DETAILS FOR VIEWING - PostgreSQL version
// =============================================
$viewTicketId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewTicket = null;
$viewReplies = [];

if ($viewTicketId > 0) {
    $viewTicket = getRecord("
        SELECT * FROM support_tickets 
        WHERE id = $1 AND user_id = $2
    ", [$viewTicketId, $userId]);
    
    if ($viewTicket) {
        $viewReplies = getRecords("
            SELECT r.*, u.first_name, u.last_name, u.role
            FROM support_ticket_replies r
            JOIN users u ON r.user_id = u.id
            WHERE r.ticket_id = $1
            ORDER BY r.created_at ASC
        ", [$viewTicketId]);
    }
}

// Ticket categories
$categories = [
    'billing' => 'Billing & Invoices',
    'technical' => 'Technical Support',
    'account' => 'Account Issues',
    'jobs' => 'Job Posting',
    'applicants' => 'Applicants',
    'employees' => 'Employees',
    'general' => 'General Inquiry',
    'feedback' => 'Feedback'
];

// Priority levels
$priorities = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'urgent' => 'Urgent'
];

// Status labels
$statusLabels = [
    'open' => 'Open',
    'in_progress' => 'In Progress',
    'resolved' => 'Resolved',
    'closed' => 'Closed'
];

$statusBadges = [
    'open' => 'badge-open',
    'in_progress' => 'badge-in-progress',
    'resolved' => 'badge-resolved',
    'closed' => 'badge-closed'
];

// Get user profile for sidebar
$userProfile = getUserProfileData($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Support - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           SUPPORT TICKETS - CLEAN PROFESSIONAL UI
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
           TICKET LIST
        ============================================= */
        .ticket-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
        }
        .ticket-card .ticket-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .ticket-card .ticket-header .ticket-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .ticket-card .ticket-body { padding: 0; overflow-x: auto; }

        .ticket-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .ticket-table thead { background: var(--bg-surface-low); }
        .ticket-table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        .ticket-table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        .ticket-table tr:last-child td { border-bottom: none; }
        .ticket-table tbody tr:hover td { background: var(--bg-surface-low); }

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
        .badge-in-progress { background: #fef3c7; color: #d97706; }
        .badge-resolved { background: #d1fae5; color: #059669; }
        .badge-closed { background: #f1f5f9; color: #6b7280; }

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
           TICKET DETAILS VIEW
        ============================================= */
        .ticket-detail-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .ticket-detail-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .ticket-detail-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }
        .ticket-detail-body {
            padding: 1.5rem;
        }
        .ticket-detail-body .ticket-message {
            background: var(--bg-surface-low);
            padding: 1rem;
            border-radius: 0.75rem;
            border-left: 4px solid var(--primary);
            margin-bottom: 1.5rem;
            white-space: pre-wrap;
        }

        .reply-item {
            padding: 0.75rem 1rem;
            background: var(--bg-surface);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
            margin-bottom: 0.75rem;
        }
        .reply-item:last-child { margin-bottom: 0; }
        .reply-item .reply-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }
        .reply-item .reply-header .reply-author {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }
        .reply-item .reply-header .reply-author .role-badge {
            font-size: 0.625rem;
            padding: 0.0625rem 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            border-radius: var(--radius-full);
            margin-left: 0.5rem;
        }
        .reply-item .reply-header .reply-time {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .reply-item .reply-body {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            white-space: pre-wrap;
        }

        .reply-form {
            margin-top: 1.5rem;
        }
        .reply-form textarea {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            resize: vertical;
            min-height: 80px;
            transition: all var(--transition-fast);
        }
        .reply-form textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* =============================================
           NEW TICKET FORM
        ============================================= */
        .ticket-form-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem 2rem;
        }
        .ticket-form-card .form-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.75rem;
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
        textarea.form-control { resize: vertical; min-height: 80px; }
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5168' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.5rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .form-row { grid-template-columns: 1fr; }
        }
        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.25rem;
            justify-content: flex-end;
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
            .ticket-table { font-size: 0.75rem; }
            .ticket-table th, .ticket-table td { padding: 0.375rem 0.5rem; }
            .form-row { grid-template-columns: 1fr; }
            .ticket-form-card { padding: 1rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .ticket-table { font-size: 0.6875rem; min-width: 300px; }
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Support</span>
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
                        <span class="material-symbols-outlined">support_agent</span>
                        <span>Support</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta"><?php echo $totalTickets; ?> tickets</span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Support</h1>
                        <p>Submit and track your support tickets</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="showNewTicketForm()">
                            <span class="material-symbols-outlined">add</span>
                            New Ticket
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <span class="material-symbols-outlined">receipt_long</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalTickets; ?></div>
                            <div class="stat-label">Total Tickets</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">pending</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $openTickets; ?></div>
                            <div class="stat-label">Open</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <span class="material-symbols-outlined">hourglass_top</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $inProgressTickets; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $resolvedTickets; ?></div>
                            <div class="stat-label">Resolved</div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_GET['view']) && $viewTicket): ?>
                    <!-- Ticket Detail View -->
                    <div class="ticket-detail-card">
                        <div class="ticket-detail-header">
                            <div>
                                <h2><?php echo htmlspecialchars($viewTicket['subject']); ?></h2>
                                <div style="display:flex; gap:1rem; margin-top:0.25rem; font-size:0.8125rem; color:var(--text-on-surface-variant); flex-wrap:wrap;">
                                    <span>#<?php echo $viewTicket['id']; ?></span>
                                    <span>•</span>
                                    <span><?php echo $categories[$viewTicket['category']] ?? $viewTicket['category']; ?></span>
                                    <span>•</span>
                                    <span>Priority: <?php echo $priorities[$viewTicket['priority']] ?? $viewTicket['priority']; ?></span>
                                    <span>•</span>
                                    <span>Created: <?php echo date('M d, Y', strtotime($viewTicket['created_at'])); ?></span>
                                </div>
                            </div>
                            <span class="badge <?php echo $statusBadges[$viewTicket['status']] ?? 'badge-open'; ?>">
                                <?php echo $statusLabels[$viewTicket['status']] ?? ucfirst($viewTicket['status']); ?>
                            </span>
                        </div>
                        <div class="ticket-detail-body">
                            <div class="ticket-message">
                                <?php echo nl2br(htmlspecialchars($viewTicket['message'])); ?>
                            </div>

                            <?php if (!empty($viewReplies)): ?>
                                <h3 style="font-size:0.875rem; font-weight:700; margin-bottom:0.75rem;">Replies</h3>
                                <?php foreach ($viewReplies as $reply): ?>
                                    <div class="reply-item">
                                        <div class="reply-header">
                                            <div class="reply-author">
                                                <?php echo htmlspecialchars($reply['first_name'] . ' ' . $reply['last_name']); ?>
                                                <span class="role-badge">
                                                    <?php echo ucfirst(str_replace('_', ' ', $reply['role'] ?? 'User')); ?>
                                                </span>
                                            </div>
                                            <div class="reply-time">
                                                <?php echo date('M d, Y g:i A', strtotime($reply['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="reply-body">
                                            <?php echo nl2br(htmlspecialchars($reply['message'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Reply Form -->
                            <?php if ($viewTicket['status'] !== 'closed'): ?>
                                <div class="reply-form">
                                    <h3 style="font-size:0.875rem; font-weight:700; margin-bottom:0.5rem;">Add Reply</h3>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="add_reply">
                                        <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                                        <textarea name="reply_message" placeholder="Type your reply here..." required></textarea>
                                        <div style="display:flex; gap:0.75rem; margin-top:0.75rem; flex-wrap:wrap;">
                                            <button type="submit" class="btn btn-primary">
                                                <span class="material-symbols-outlined">send</span>
                                                Send Reply
                                            </button>
                                            <?php if ($viewTicket['status'] !== 'closed'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Close this ticket?')">
                                                    <input type="hidden" name="action" value="close_ticket">
                                                    <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                                                    <button type="submit" class="btn btn-danger">
                                                        <span class="material-symbols-outlined">check</span>
                                                        Close Ticket
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="support.php" class="btn btn-outline">
                                                <span class="material-symbols-outlined">arrow_back</span>
                                                Back to List
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="padding:1rem; background:var(--bg-surface-low); border-radius:0.75rem; border:1px solid var(--slate-200); text-align:center; color:var(--text-on-surface-variant);">
                                    <span class="material-symbols-outlined" style="font-size:2rem; display:block; margin-bottom:0.25rem;">check_circle</span>
                                    <p>This ticket is closed.</p>
                                    <a href="support.php" class="btn btn-outline btn-sm" style="margin-top:0.5rem;">
                                        <span class="material-symbols-outlined">arrow_back</span>
                                        Back to List
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Ticket List -->
                    <div class="ticket-card">
                        <div class="ticket-header">
                            <div class="ticket-title">Support Tickets</div>
                            <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                <?php echo count($tickets); ?> tickets
                            </span>
                        </div>
                        <div class="ticket-body">
                            <?php if (empty($tickets)): ?>
                                <div class="empty-state">
                                    <span class="material-symbols-outlined">support_agent</span>
                                    <h3>No tickets yet</h3>
                                    <p>Submit your first support ticket to get help.</p>
                                    <button class="btn btn-primary" onclick="showNewTicketForm()" style="margin-top:1rem;">
                                        <span class="material-symbols-outlined">add</span>
                                        Submit Ticket
                                    </button>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto;">
                                    <table class="ticket-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Subject</th>
                                                <th>Category</th>
                                                <th>Priority</th>
                                                <th>Status</th>
                                                <th>Updated</th>
                                                <th style="text-align:center;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tickets as $ticket): ?>
                                                <tr>
                                                    <td>
                                                        <span style="font-weight:600; color:var(--text-on-surface);">
                                                            #<?php echo $ticket['id']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div style="font-weight:500; color:var(--text-on-surface);">
                                                            <?php echo htmlspecialchars($ticket['subject']); ?>
                                                        </div>
                                                        <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                            <?php echo htmlspecialchars(substr($ticket['message'], 0, 60)); ?>...
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                            <?php echo $categories[$ticket['category']] ?? $ticket['category']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-size:0.75rem; font-weight:500; color:<?php echo $ticket['priority'] === 'urgent' ? '#dc2626' : ($ticket['priority'] === 'high' ? '#f59e0b' : 'var(--text-on-surface-variant)'); ?>">
                                                            <?php echo $priorities[$ticket['priority']] ?? $ticket['priority']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $statusBadges[$ticket['status']] ?? 'badge-open'; ?>">
                                                            <?php echo $statusLabels[$ticket['status']] ?? ucfirst($ticket['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                            <?php echo date('M d, Y', strtotime($ticket['updated_at'] ?? $ticket['created_at'])); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <a href="?view=<?php echo $ticket['id']; ?>" class="btn btn-primary btn-sm">
                                                            <span class="material-symbols-outlined">visibility</span>
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- New Ticket Form (Hidden by default) -->
                    <div class="ticket-form-card" id="newTicketForm" style="display:none; margin-top:1.5rem;">
                        <div class="form-title">Submit a New Ticket</div>
                        <form method="POST">
                            <input type="hidden" name="action" value="submit_ticket">
                            
                            <div class="form-group">
                                <label>Subject <span class="required">*</span></label>
                                <input type="text" name="subject" class="form-control" placeholder="Brief description of your issue..." required>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Category <span class="required">*</span></label>
                                    <select name="category" class="form-control" required>
                                        <option value="">Select category...</option>
                                        <?php foreach ($categories as $key => $label): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Priority <span class="required">*</span></label>
                                    <select name="priority" class="form-control" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Message <span class="required">*</span></label>
                                <textarea name="message" class="form-control" placeholder="Describe your issue in detail..." rows="4" required></textarea>
                                <div class="helper-text" style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                                    <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">info</span>
                                    Include any relevant details to help us resolve your issue faster.
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn btn-ghost" onclick="hideNewTicketForm()">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <span class="material-symbols-outlined">send</span>
                                    Submit Ticket
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
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
        // 4. NEW TICKET FORM TOGGLE
        // =============================================
        function showNewTicketForm() {
            const form = document.getElementById('newTicketForm');
            if (form) {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth' });
            }
        }

        function hideNewTicketForm() {
            const form = document.getElementById('newTicketForm');
            if (form) {
                form.style.display = 'none';
                form.querySelector('form').reset();
            }
        }

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
        // 5. RESPONSIVE HANDLING
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

        console.log('📄 Support loaded successfully!');
    </script>
</body>
</html>