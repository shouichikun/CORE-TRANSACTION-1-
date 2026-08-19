<?php
// portals/client/agency_applications.php - Client Agency Application Management
// IMPROVED UI/UX - With Confirmation Modal - FIXED
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

$message = '';
$messageType = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
    
    if ($_POST['action'] === 'approve_agency' && $applicationId > 0) {
        $application = getRecord("
            SELECT a.*, u.first_name, u.last_name, u.email as user_email
            FROM agency_applications a
            JOIN users u ON a.user_id = u.id
            WHERE a.id = ? AND a.client_id = ?
        ", [$applicationId, $clientId], "ii");
        
        if ($application) {
            beginTransaction();
            
            try {
                $existing = getRecord("
                    SELECT id FROM recruitment_agencies 
                    WHERE agency_code = ? AND client_id = ?
                ", [$application['agency_code'], $clientId], "si");
                
                if ($existing) {
                    throw new Exception('An agency with this code already exists for your company.');
                }
                
                // =============================================
                // FIXED: Correct number of placeholders and bind values
                // =============================================
                $insertSql = "INSERT INTO recruitment_agencies (
                    user_id, client_id, agency_name, agency_code, contact_person, contact_email,
                    contact_phone, address, website, is_active, application_status, approved_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'approved', NOW())";
                
                $stmt = mysqli_prepare($conn, $insertSql);
                // 9 values, type string: i i s s s s s s s
                mysqli_stmt_bind_param($stmt, 'iisssssss', 
                    $application['user_id'],
                    $clientId,
                    $application['agency_name'],
                    $application['agency_code'],
                    $application['contact_person'],
                    $application['contact_email'],
                    $application['contact_phone'],
                    $application['address'],
                    $application['website']
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $agencyId = mysqli_insert_id($conn);
                    
                    $updateSql = "UPDATE agency_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?";
                    $stmt2 = mysqli_prepare($conn, $updateSql);
                    mysqli_stmt_bind_param($stmt2, 'ii', $userId, $applicationId);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                    
                    commitTransaction();
                    
                    $message = 'Agency approved successfully! They can now handle your job postings.';
                    $messageType = 'success';
                    
                    logActivity($userId, 'Approved Recruitment Agency', 'recruitment_agencies', $agencyId, 
                        'Approved agency: ' . $application['agency_name']);
                    
                } else {
                    rollbackTransaction();
                    $message = 'Error creating agency: ' . mysqli_error($conn);
                    $messageType = 'error';
                }
                mysqli_stmt_close($stmt);
            } catch (Exception $e) {
                rollbackTransaction();
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = 'Application not found or does not belong to your company.';
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'reject_agency' && $applicationId > 0) {
        $rejectionReason = trim($_POST['rejection_reason'] ?? 'No reason provided');
        
        $updateSql = "UPDATE agency_applications SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ? AND client_id = ?";
        $stmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($stmt, 'isii', $userId, $rejectionReason, $applicationId, $clientId);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = 'Application rejected successfully.';
            $messageType = 'success';
            
            logActivity($userId, 'Rejected Recruitment Agency', 'agency_applications', $applicationId, 
                'Rejected agency application');
        } else {
            $message = 'Error rejecting application: ' . mysqli_error($conn);
            $messageType = 'error';
        }
        mysqli_stmt_close($stmt);
    }
}

// Get pending applications for this client
$pendingApplications = getRecords("
    SELECT a.*, 
           CONCAT(u.first_name, ' ', u.last_name) as applicant_name,
           u.email as applicant_email,
           u.profile_picture
    FROM agency_applications a
    JOIN users u ON a.user_id = u.id
    WHERE a.client_id = ? AND a.status = 'pending'
    ORDER BY a.created_at ASC
", [$clientId], "i");

// Get approved agencies for this client
$approvedAgencies = getRecords("
    SELECT ra.*, 
           CONCAT(u.first_name, ' ', u.last_name) as owner_name,
           u.email as owner_email,
           (SELECT COUNT(*) FROM job_orders WHERE agency_id = ra.id) as job_count
    FROM recruitment_agencies ra
    JOIN users u ON ra.user_id = u.id
    WHERE ra.client_id = ? AND ra.is_active = 1 AND ra.application_status = 'approved'
    ORDER BY ra.agency_name ASC
", [$clientId], "i");

// Get rejected applications
$rejectedApplications = getRecords("
    SELECT a.*, 
           CONCAT(u.first_name, ' ', u.last_name) as applicant_name,
           u.email as applicant_email
    FROM agency_applications a
    JOIN users u ON a.user_id = u.id
    WHERE a.client_id = ? AND a.status = 'rejected'
    ORDER BY a.updated_at DESC
    LIMIT 20
", [$clientId], "i");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Recruitment Agencies - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           CLEAN UI/UX - CLIENT AGENCY MANAGEMENT
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
            margin-bottom: 1.5rem;
        }
        @media (min-width: 640px) {
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
        }
        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .page-header h1 .material-symbols-outlined {
            font-size: 2rem;
            color: var(--primary);
        }
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
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* =============================================
           CARDS
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            background: var(--bg-surface-low);
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
            background: var(--bg-surface);
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            border: 1px solid var(--slate-200);
        }
        .card-body { padding: 1.5rem; }

        /* =============================================
           BADGES
        ============================================= */
        .badge {
            display: inline-block;
            padding: 0.125rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fecaca; color: #991b1b; }

        /* =============================================
           APPLICATION CARD - CLEAN VERSION
        ============================================= */
        .application-card {
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200);
            padding: 1.25rem 1.5rem;
            margin-bottom: 0.75rem;
            transition: all var(--transition-fast);
        }
        .application-card:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--slate-300);
        }
        .application-card:last-child { margin-bottom: 0; }

        .app-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .app-row .app-main {
            flex: 1;
            min-width: 200px;
        }
        .app-row .app-main .app-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .app-row .app-main .app-name .agency-name {
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-on-surface);
        }
        .app-row .app-main .app-name .agency-code {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.0625rem 0.5rem;
            border-radius: var(--radius-full);
            border: 1px solid var(--slate-200);
        }
        .app-row .app-main .app-submitter {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.125rem;
        }
        .app-row .app-main .app-submitter strong { color: var(--text-on-surface); }

        .app-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem 1.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .app-grid .grid-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.125rem 0;
        }
        .app-grid .grid-item .material-symbols-outlined {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            flex-shrink: 0;
        }

        .app-specialization {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 1px solid var(--slate-200);
        }
        .app-specialization .material-symbols-outlined {
            font-size: 0.875rem;
            color: var(--primary);
        }

        .app-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }

        /* =============================================
           AGENCY CARD - CLEAN VERSION
        ============================================= */
        .agency-card {
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200);
            padding: 0.75rem 1.25rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            transition: all var(--transition-fast);
        }
        .agency-card:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--slate-300);
        }
        .agency-card:last-child { margin-bottom: 0; }

        .agency-card .agency-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .agency-card .agency-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 0.5rem;
        }

        .agency-card .agency-details { flex: 1; min-width: 150px; }
        .agency-card .agency-details .agency-name { font-weight: 700; font-size: 0.9375rem; }
        .agency-card .agency-details .agency-meta {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .agency-card .agency-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .agency-card .agency-stats span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            background: var(--bg-surface-low);
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
        }

        /* =============================================
           REJECTED ITEMS
        ============================================= */
        .rejected-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.25rem;
            border-bottom: 1px solid var(--slate-100);
        }
        .rejected-item:last-child { border-bottom: none; }
        .rejected-item .rejected-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .rejected-item .rejected-info .agency-name { font-weight: 600; font-size: 0.875rem; }
        .rejected-item .rejected-info .agency-code {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
        }
        .rejected-item .rejected-info .rejected-meta {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .empty-state .material-symbols-outlined {
            font-size: 3.5rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.5rem;
        }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); }
        .empty-state p { font-size: 0.875rem; }

        /* =============================================
           MODALS
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
            max-width: 480px;
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

        /* Confirmation Modal */
        .modal-confirm-icon {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }
        .modal-confirm-icon.success { background: #d1fae5; color: #059669; }
        .modal-confirm-icon.danger { background: #fecaca; color: #dc2626; }
        .modal-confirm-icon.warning { background: #fef3c7; color: #d97706; }

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
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
        textarea.form-control { resize: vertical; min-height: 60px; }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-sm); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
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
            .app-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .page-header h1 .material-symbols-outlined { font-size: 1.5rem; }
            .app-grid { grid-template-columns: 1fr; gap: 0.25rem; }
            .application-card { padding: 1rem; }
            .agency-card { flex-direction: column; align-items: stretch; text-align: center; }
            .agency-card .agency-stats { justify-content: center; }
            .app-row .app-main .app-name { flex-direction: column; align-items: flex-start; }
            .app-actions { justify-content: center; }
            .rejected-item { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
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
                <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
                    Agencies
                </span>
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
                        <span class="material-symbols-outlined">apartment</span>
                        <span>Recruitment Agencies</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">
                        <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">pending</span>
                        <?php echo count($pendingApplications); ?> pending ·
                        <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">check_circle</span>
                        <?php echo count($approvedAgencies); ?> active
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>
                            <span class="material-symbols-outlined">apartment</span>
                            Recruitment Agencies
                        </h1>
                        <p>Review and manage agency applications for your company</p>
                    </div>
                </div>

                <!-- Pending Applications -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">pending</span>
                            Pending Applications
                        </h3>
                        <span class="count-badge">
                            <?php echo count($pendingApplications); ?> pending
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pendingApplications)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">check_circle</span>
                                <h4>All Caught Up</h4>
                                <p>No pending agency applications to review.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pendingApplications as $app): ?>
                                <div class="application-card">
                                    <div class="app-row">
                                        <div class="app-main">
                                            <div class="app-name">
                                                <span class="agency-name"><?php echo htmlspecialchars($app['agency_name']); ?></span>
                                                <span class="agency-code"><?php echo htmlspecialchars($app['agency_code']); ?></span>
                                            </div>
                                            <div class="app-submitter">
                                                Submitted by: <strong><?php echo htmlspecialchars($app['applicant_name']); ?></strong>
                                                <span style="color:var(--text-on-surface-variant);">(<?php echo htmlspecialchars($app['applicant_email']); ?>)</span>
                                            </div>
                                        </div>
                                        <span class="badge badge-pending">Pending</span>
                                    </div>

                                    <div class="app-grid">
                                        <div class="grid-item">
                                            <span class="material-symbols-outlined">person</span>
                                            <?php echo htmlspecialchars($app['contact_person']); ?>
                                        </div>
                                        <div class="grid-item">
                                            <span class="material-symbols-outlined">email</span>
                                            <?php echo htmlspecialchars($app['contact_email']); ?>
                                        </div>
                                        <?php if ($app['contact_phone']): ?>
                                            <div class="grid-item">
                                                <span class="material-symbols-outlined">phone</span>
                                                <?php echo htmlspecialchars($app['contact_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($app['years_experience']): ?>
                                            <div class="grid-item">
                                                <span class="material-symbols-outlined">work_history</span>
                                                <?php echo htmlspecialchars($app['years_experience']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($app['team_size']): ?>
                                            <div class="grid-item">
                                                <span class="material-symbols-outlined">group</span>
                                                <?php echo htmlspecialchars($app['team_size']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="grid-item">
                                            <span class="material-symbols-outlined">calendar_today</span>
                                            <?php echo date('M d, Y', strtotime($app['created_at'])); ?>
                                        </div>
                                    </div>

                                    <?php if ($app['specialization']): ?>
                                        <div class="app-specialization">
                                            <span class="material-symbols-outlined">sell</span>
                                            <?php echo htmlspecialchars($app['specialization']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="app-actions">
                                        <button class="btn btn-success btn-sm" onclick="openApproveModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['agency_name']); ?>')">
                                            <span class="material-symbols-outlined">check</span>
                                            Approve
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="rejectAgency(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['agency_name']); ?>')">
                                            <span class="material-symbols-outlined">close</span>
                                            Reject
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Approved Agencies -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">check_circle</span>
                            Approved Agencies
                        </h3>
                        <span class="count-badge"><?php echo count($approvedAgencies); ?> agencies</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($approvedAgencies)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">apartment</span>
                                <h4>No Approved Agencies</h4>
                                <p>You haven't approved any recruitment agencies yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($approvedAgencies as $agency): ?>
                                <div class="agency-card">
                                    <div class="agency-icon">
                                        <?php if (!empty($agency['logo_path']) && file_exists('../../' . $agency['logo_path'])): ?>
                                            <img src="../../<?php echo htmlspecialchars($agency['logo_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($agency['agency_name']); ?>">
                                        <?php else: ?>
                                            <?php echo substr($agency['agency_name'], 0, 1); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="agency-details">
                                        <div class="agency-name"><?php echo htmlspecialchars($agency['agency_name']); ?></div>
                                        <div class="agency-meta">
                                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">code</span>
                                            <?php echo htmlspecialchars($agency['agency_code']); ?> ·
                                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">person</span>
                                            <?php echo htmlspecialchars($agency['contact_person']); ?>
                                            <span style="color:var(--text-on-surface-variant);">(<?php echo htmlspecialchars($agency['contact_email']); ?>)</span>
                                        </div>
                                    </div>
                                    <div class="agency-stats">
                                        <span>
                                            <span class="material-symbols-outlined">work</span>
                                            <?php echo $agency['job_count'] ?? 0; ?>
                                        </span>
                                        <span>
                                            <span class="material-symbols-outlined">check_circle</span>
                                            Active
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Rejected Applications -->
                <?php if (!empty($rejectedApplications)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <span class="material-symbols-outlined">cancel</span>
                                Rejected Applications
                            </h3>
                            <span class="count-badge"><?php echo count($rejectedApplications); ?></span>
                        </div>
                        <div class="card-body">
                            <?php foreach ($rejectedApplications as $app): ?>
                                <div class="rejected-item">
                                    <div class="rejected-info">
                                        <span class="agency-name"><?php echo htmlspecialchars($app['agency_name']); ?></span>
                                        <span class="agency-code">(<?php echo htmlspecialchars($app['agency_code']); ?>)</span>
                                        <span class="rejected-meta">· <?php echo htmlspecialchars($app['applicant_name']); ?></span>
                                        <span class="rejected-meta">· <?php echo date('M d, Y', strtotime($app['updated_at'])); ?></span>
                                    </div>
                                    <span class="badge badge-rejected">Rejected</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- =============================================
    APPROVE CONFIRMATION MODAL
    ============================================= -->
    <div class="modal-overlay" id="approveModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">check_circle</span>
                    Confirm Approval
                </h2>
                <button class="modal-close" onclick="closeModal('approveModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="modal-confirm-icon success">
                    <span class="material-symbols-outlined">check_circle</span>
                </div>
                <p style="font-size:0.875rem; text-align:center; color:var(--text-on-surface-variant); margin-bottom:1rem;">
                    You are about to approve <strong id="approveAgencyName" style="color:var(--text-on-surface);"></strong> as a recruitment agency for your company.
                </p>
                <div style="background:#f0fdf4; padding:0.75rem 1rem; border-radius:0.5rem; border:1px solid #bbf7d0; text-align:center;">
                    <span style="font-size:0.8125rem; color:#065f46;">
                        ✅ This agency will be able to handle your job postings immediately.
                    </span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('approveModal')">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmApproveBtn">
                    <span class="material-symbols-outlined">check</span>
                    Yes, Approve Agency
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    REJECT MODAL
    ============================================= -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">cancel</span>
                    Reject Application
                </h2>
                <button class="modal-close" onclick="closeModal('rejectModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form method="POST" action="" id="rejectForm">
                <input type="hidden" name="action" value="reject_agency">
                <input type="hidden" name="application_id" id="rejectApplicationId" value="">
                <div class="modal-body">
                    <div class="modal-confirm-icon danger">
                        <span class="material-symbols-outlined">cancel</span>
                    </div>
                    <p style="font-size:0.875rem; text-align:center; color:var(--text-on-surface-variant); margin-bottom:1rem;">
                        You are about to reject <strong id="rejectAgencyName" style="color:var(--text-on-surface);"></strong>'s application.
                    </p>
                    <div class="form-group">
                        <label>Reason for Rejection <span class="required">*</span></label>
                        <textarea name="rejection_reason" class="form-control" placeholder="Please provide a reason for rejecting this application..." rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <span class="material-symbols-outlined">cancel</span>
                        Reject Application
                    </button>
                </div>
            </form>
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

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', openMobileSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        }

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
        // 4. MODAL FUNCTIONS
        // =============================================
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('approveModal');
                closeModal('rejectModal');
                closeMobileSidebar();
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // 5. AGENCY ACTIONS - WITH CONFIRMATION MODAL
        // =============================================
        
        // Approve Agency - Show confirmation modal
        let approveApplicationId = null;
        
        function openApproveModal(id, name) {
            approveApplicationId = id;
            document.getElementById('approveAgencyName').textContent = name;
            openModal('approveModal');
        }

        // Confirm approve
        document.getElementById('confirmApproveBtn').addEventListener('click', function() {
            if (!approveApplicationId) return;
            
            // Create and submit form
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="action" value="approve_agency">
                <input type="hidden" name="application_id" value="${approveApplicationId}">
            `;
            document.body.appendChild(form);
            
            // Close modal first
            closeModal('approveModal');
            
            // Submit form
            form.submit();
        });

        // Reject Agency - Open reject modal
        function rejectAgency(id, name) {
            document.getElementById('rejectApplicationId').value = id;
            document.getElementById('rejectAgencyName').textContent = name;
            openModal('rejectModal');
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

        console.log('🏢 ISMERS Agency Management (Clean UI) loaded successfully!');
    </script>

</body>
</html>