<?php
// portals/employee/leaves.php - Employee Leave Management (CLEAN & MINIMAL)
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
// LEAVE TYPES
// =============================================
$leaveTypes = [
    'vacation' => 'Vacation Leave',
    'sick' => 'Sick Leave',
    'emergency' => 'Emergency Leave',
    'maternity' => 'Maternity Leave',
    'paternity' => 'Paternity Leave',
    'bereavement' => 'Bereavement Leave',
    'training' => 'Training/Seminar',
    'others' => 'Others'
];

// =============================================
// GET LEAVE BALANCES (Still needed for validation)
// =============================================
$leaveBalances = getRecord("
    SELECT 
        SUM(CASE WHEN leave_type = 'vacation' AND status = 'approved' THEN days_count ELSE 0 END) as vacation_used,
        SUM(CASE WHEN leave_type = 'sick' AND status = 'approved' THEN days_count ELSE 0 END) as sick_used,
        SUM(CASE WHEN leave_type = 'emergency' AND status = 'approved' THEN days_count ELSE 0 END) as emergency_used,
        SUM(CASE WHEN leave_type = 'maternity' AND status = 'approved' THEN days_count ELSE 0 END) as maternity_used,
        SUM(CASE WHEN leave_type = 'paternity' AND status = 'approved' THEN days_count ELSE 0 END) as paternity_used,
        SUM(CASE WHEN leave_type = 'bereavement' AND status = 'approved' THEN days_count ELSE 0 END) as bereavement_used,
        SUM(CASE WHEN leave_type = 'training' AND status = 'approved' THEN days_count ELSE 0 END) as training_used,
        SUM(CASE WHEN leave_type = 'others' AND status = 'approved' THEN days_count ELSE 0 END) as others_used
    FROM leave_requests 
    WHERE user_id = ?
", [$userId], "i");

// Default annual leave credits (configurable)
$annualCredits = [
    'vacation' => 15,
    'sick' => 10,
    'emergency' => 5,
    'maternity' => 60,
    'paternity' => 7,
    'bereavement' => 3,
    'training' => 5,
    'others' => 5
];

// Calculate remaining balances (for dropdown display only)
$balances = [];
foreach ($annualCredits as $type => $total) {
    $used = $leaveBalances[$type . '_used'] ?? 0;
    $remaining = max(0, $total - $used);
    $balances[$type] = [
        'total' => $total,
        'used' => $used,
        'remaining' => $remaining
    ];
}

// =============================================
// GET LEAVE REQUESTS
// =============================================
$leaveRequests = getRecords("
    SELECT * FROM leave_requests 
    WHERE user_id = ? 
    ORDER BY created_at DESC
", [$userId], "i");

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // =============================================
    // SUBMIT LEAVE REQUEST
    // =============================================
    if ($action === 'submit_leave') {
        $leave_type = $_POST['leave_type'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        
        $errors = [];
        if (empty($leave_type)) $errors[] = 'Please select a leave type.';
        if (empty($start_date)) $errors[] = 'Please select a start date.';
        if (empty($end_date)) $errors[] = 'Please select an end date.';
        if ($start_date > $end_date) $errors[] = 'End date must be after start date.';
        if (empty($reason)) $errors[] = 'Please provide a reason.';
        
        // Calculate days count (excluding weekends)
        if (empty($errors)) {
            $daysCount = 0;
            $current = new DateTime($start_date);
            $end = new DateTime($end_date);
            
            while ($current <= $end) {
                $dayOfWeek = $current->format('N');
                if ($dayOfWeek < 6) { // Monday to Friday
                    $daysCount++;
                }
                $current->modify('+1 day');
            }
            
            if ($daysCount == 0) {
                $errors[] = 'No working days selected.';
            }
        }
        
        if (empty($errors)) {
            // Check if leave type has enough balance
            $balance = $balances[$leave_type]['remaining'] ?? 0;
            if ($daysCount > $balance) {
                $errors[] = 'Insufficient leave balance. Available: ' . $balance . ' days, Requested: ' . $daysCount . ' days.';
            }
        }
        
        if (empty($errors)) {
            $sql = "INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, days_count, reason, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
            
            $result = insertRecord($sql, [
                $userId,
                $leave_type,
                $start_date,
                $end_date,
                $daysCount,
                $reason
            ], "isssis");
            
            if ($result) {
                logActivity($userId, 'Leave Request Submitted', 'leave_requests', $result, 
                    'Requested ' . $daysCount . ' days of ' . $leaveTypes[$leave_type]);
                $message = 'Leave request submitted successfully!';
                $messageType = 'success';
                
                // Refresh data
                $leaveRequests = getRecords("
                    SELECT * FROM leave_requests 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC
                ", [$userId], "i");
            } else {
                $message = 'Failed to submit leave request. Please try again.';
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }
    
    // =============================================
    // CANCEL LEAVE REQUEST
    // =============================================
    if ($action === 'cancel_leave') {
        $leave_id = isset($_POST['leave_id']) ? (int)$_POST['leave_id'] : 0;
        
        if ($leave_id > 0) {
            $sql = "UPDATE leave_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'";
            $result = updateRecord($sql, [$leave_id, $userId], "ii");
            
            if ($result) {
                logActivity($userId, 'Leave Request Cancelled', 'leave_requests', $leave_id, 'Cancelled leave request');
                $message = 'Leave request cancelled.';
                $messageType = 'success';
                
                // Refresh data
                $leaveRequests = getRecords("
                    SELECT * FROM leave_requests 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC
                ", [$userId], "i");
            } else {
                $message = 'Failed to cancel leave request.';
                $messageType = 'error';
            }
        }
    }
}

// Status badge mapping
$statusBadges = [
    'pending' => 'badge-pending',
    'approved' => 'badge-approved',
    'rejected' => 'badge-rejected',
    'cancelled' => 'badge-cancelled'
];

$statusLabels = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Leave Management - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - LEAVE MANAGEMENT (MINIMAL)
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
           FORM SECTION
        ============================================= */
        .form-section {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
        }
        .form-section .form-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
        }
        .form-section .form-group {
            margin-bottom: 1rem;
        }
        .form-section .form-group:last-child { margin-bottom: 0; }
        .form-section .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }
        .form-section .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-section .form-group .form-control {
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
        .form-section .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        .form-section .form-group .form-control::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }
        .form-section .form-group textarea.form-control { resize: vertical; min-height: 80px; }
        .form-section .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }
        .form-section .form-group .helper-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }
        .form-section .form-group .helper-text .material-symbols-outlined { font-size: 0.875rem; vertical-align: middle; }
        .form-section .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .form-section .form-row { grid-template-columns: 1fr; }
        }
        .form-section .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }

        /* =============================================
           HISTORY SECTION (FULL WIDTH)
        ============================================= */
        .history-section {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .history-section .history-header {
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .history-section .history-header .history-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .history-section .history-body { padding: 0; overflow-x: auto; }

        .leave-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .leave-table thead { background: var(--bg-surface-low); }
        .leave-table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        .leave-table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        .leave-table tr:last-child td { border-bottom: none; }
        .leave-table tbody tr:hover td { background: var(--bg-surface-low); }

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
        .badge-approved { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-cancelled { background: #f3f4f6; color: #6b7280; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        .empty-state .material-symbols-outlined { font-size: 3rem; color: var(--slate-200); display: block; margin-bottom: 0.75rem; }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.8125rem; color: var(--text-on-surface-variant); }

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
            .form-section { padding: 1rem; }
            .leave-table { font-size: 0.75rem; }
            .leave-table th, .leave-table td { padding: 0.375rem 0.5rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.75rem 1rem; }
            .page-header h1 { font-size: 1.25rem; }
            .form-section .form-row { grid-template-columns: 1fr; }
            .leave-table { font-size: 0.6875rem; min-width: 300px; }
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
        <a href="leaves.php" class="sidebar-main-link active">
            <span class="material-symbols-outlined">beach_access</span>
            <span class="nav-text">Leaves</span>
        </a>
        <a href="attendance.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">schedule</span>
            <span class="nav-text">Attendance</span>
        </a>
        <a href="payroll.php" class="sidebar-main-link">
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
            <span style="font-weight:600; font-size:0.875rem;">Leave Management</span>
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
                    <span class="material-symbols-outlined">beach_access</span>
                    <span>Leave Management</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo count($leaveRequests); ?> requests
                    </span>
                </div>
                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                    <?php echo date('l, F j, Y'); ?>
                </span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Leave Management</h1>
                    <p>Request and track your leave requests</p>
                </div>
            </div>

            <!-- Toast Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $messageType; ?>" id="toastMessage" style="padding:0.875rem 1.25rem; border-radius:0.75rem; font-size:0.875rem; margin-bottom:1rem; display:flex; align-items:flex-start; gap:0.75rem; border:1px solid transparent; <?php echo $messageType === 'success' ? 'background:#f0fdf4; border-color:#bbf7d0; color:#16a34a;' : ($messageType === 'error' ? 'background:#fef2f2; border-color:#fecaca; color:#dc2626;' : 'background:#dbeafe; border-color:#93c5fd; color:#2563eb;'); ?>">
                    <span class="material-symbols-outlined" style="font-size:1.25rem; flex-shrink:0; margin-top:0.0625rem;">
                        <?php echo $messageType === 'success' ? 'check_circle' : ($messageType === 'error' ? 'error' : 'info'); ?>
                    </span>
                    <div>
                        <strong><?php echo $messageType === 'success' ? 'Success!' : ($messageType === 'error' ? 'Error:' : 'Info:'); ?></strong>
                        <span style="display:block; font-weight:400;"><?php echo $message; ?></span>
                    </div>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.getElementById('toastMessage');
                        if (toast) toast.remove();
                    }, 5000);
                </script>
            <?php endif; ?>

            <!-- Leave Request Form -->
            <div class="form-section">
                <div class="form-title">Request Leave</div>
                <form method="POST" action="" id="leaveForm">
                    <input type="hidden" name="action" value="submit_leave">
                    
                    <div class="form-group">
                        <label>Leave Type <span class="required">*</span></label>
                        <select name="leave_type" class="form-control" required>
                            <option value="">Select leave type...</option>
                            <?php foreach ($leaveTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>">
                                    <?php echo $label; ?> 
                                    (<?php echo $balances[$key]['remaining'] ?? 0; ?> days left)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date <span class="required">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>End Date <span class="required">*</span></label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Reason <span class="required">*</span></label>
                        <textarea name="reason" class="form-control" placeholder="Provide details about your leave request..." rows="3" required></textarea>
                        <div class="helper-text">
                            <span class="material-symbols-outlined">info</span>
                            Be specific to help HR process your request faster
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <span class="material-symbols-outlined">send</span>
                            Submit Request
                        </button>
                        <button type="reset" class="btn btn-outline">
                            <span class="material-symbols-outlined">clear</span>
                            Reset
                        </button>
                    </div>
                </form>
            </div>

            <!-- Leave History Table -->
            <div class="history-section">
                <div class="history-header">
                    <div class="history-title">Leave History</div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <?php echo count($leaveRequests); ?> requests
                    </span>
                </div>
                <div class="history-body">
                    <?php if (empty($leaveRequests)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">inbox</span>
                            <h4>No Leave Requests</h4>
                            <p>You haven't submitted any leave requests yet.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="leave-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Date Range</th>
                                        <th>Days</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leaveRequests as $request): ?>
                                        <?php 
                                        $typeLabel = $leaveTypes[$request['leave_type']] ?? ucfirst($request['leave_type']);
                                        $status = $request['status'] ?? 'pending';
                                        $canCancel = $status === 'pending';
                                        ?>
                                        <tr>
                                            <td>
                                                <span style="font-weight:600; font-size:0.8125rem;"><?php echo htmlspecialchars($typeLabel); ?></span>
                                            </td>
                                            <td>
                                                <div style="font-size:0.8125rem;">
                                                    <?php echo date('M d, Y', strtotime($request['start_date'])); ?>
                                                    <?php if ($request['end_date'] != $request['start_date']): ?>
                                                        - <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-weight:600; color:var(--text-on-surface);"><?php echo $request['days_count']; ?></span>
                                            </td>
                                            <td>
                                                <div style="font-size:0.8125rem; color:var(--text-on-surface-variant); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <?php echo htmlspecialchars($request['reason'] ?? '—'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $statusBadges[$status] ?? 'badge-pending'; ?>">
                                                    <?php echo $statusLabels[$status] ?? ucfirst($status); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php if ($canCancel): ?>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelLeave(<?php echo $request['id']; ?>)">
                                                        <span class="material-symbols-outlined">cancel</span>
                                                        Cancel
                                                    </button>
                                                <?php else: ?>
                                                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">—</span>
                                                <?php endif; ?>
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
    // 4. CANCEL LEAVE REQUEST
    // =============================================
    function cancelLeave(id) {
        if (!confirm('Are you sure you want to cancel this leave request?')) return;

        const formData = new FormData();
        formData.append('action', 'cancel_leave');
        formData.append('leave_id', id);

        fetch('leaves.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.error || 'Failed to cancel request.', 'error');
            }
        })
        .catch(error => {
            showToast('Error. Please try again.', 'error');
        });
    }

    // =============================================
    // 5. TOAST SYSTEM
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
    // 6. RESPONSIVE HANDLING
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
    // 7. KEYBOARD ACCESSIBILITY
    // =============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileSidebar();
            profileToggle.classList.remove('open');
            profileMenu.classList.remove('open');
        }
    });

    console.log('Leave Management loaded successfully.');
</script>
</body>
</html>