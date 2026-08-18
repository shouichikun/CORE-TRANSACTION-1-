<?php
// portals/hr/apply_agency.php - Apply as Recruitment Agency for a Client
// IMPROVED UI/UX - Professional Design with Confirmation Modal
session_start();

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Only HR Manager can apply
if (!in_array($_SESSION['role'], ['hr_manager', 'admin'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';

// Get all clients for dropdown
$clients = getRecords("
    SELECT c.*, u.email as user_email 
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.is_active = 1
    ORDER BY c.company_name ASC
");

// Get filter parameters
$clientFilter = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

// Get user's applications
$applications = getRecords("
    SELECT a.*, c.company_name, c.id as client_id, 
           CONCAT(u.first_name, ' ', u.last_name) as reviewer_name
    FROM agency_applications a
    JOIN clients c ON a.client_id = c.id
    LEFT JOIN users u ON a.reviewed_by = u.id
    WHERE a.user_id = ?
    ORDER BY a.created_at DESC
", [$userId], "i");

$message = '';
$messageType = '';

// Handle application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'apply_agency') {
        $client_id = intval($_POST['client_id'] ?? 0);
        $agency_name = trim($_POST['agency_name'] ?? '');
        $agency_code = strtoupper(trim($_POST['agency_code'] ?? ''));
        $contact_person = trim($_POST['contact_person'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $years_experience = trim($_POST['years_experience'] ?? '');
        $team_size = trim($_POST['team_size'] ?? '');
        
        // Validate
        $errors = [];
        if (empty($client_id)) $errors[] = 'Please select a client.';
        if (empty($agency_name)) $errors[] = 'Agency name is required.';
        if (empty($agency_code)) $errors[] = 'Agency code is required.';
        if (strlen($agency_code) < 2) $errors[] = 'Agency code must be at least 2 characters.';
        if (empty($contact_person)) $errors[] = 'Contact person is required.';
        if (empty($contact_email)) $errors[] = 'Contact email is required.';
        if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
        
        // Check if already applied for this client
        if (empty($errors)) {
            $existing = getRecord("
                SELECT id FROM agency_applications 
                WHERE user_id = ? AND client_id = ? AND status IN ('pending', 'approved')
            ", [$userId, $client_id], "ii");
            if ($existing) {
                $errors[] = 'You have already applied for this client.';
            }
        }
        
        // Check if already an agency for this client
        if (empty($errors)) {
            $existingAgency = getRecord("
                SELECT id FROM recruitment_agencies 
                WHERE user_id = ? AND client_id = ? AND is_active = 1
            ", [$userId, $client_id], "ii");
            if ($existingAgency) {
                $errors[] = 'You are already an approved agency for this client.';
            }
        }
        
        // Check if agency code exists for this client
        if (empty($errors)) {
            $existingCode = getRecord("
                SELECT id FROM recruitment_agencies 
                WHERE agency_code = ? AND client_id = ?
            ", [$agency_code, $client_id], "si");
            if ($existingCode) {
                $errors[] = 'This agency code is already taken for this client.';
            }
        }
        
        if (empty($errors)) {
            // Insert application
            $insertSql = "INSERT INTO agency_applications (
                user_id, client_id, agency_name, agency_code, contact_person, contact_email,
                contact_phone, address, website, specialization,
                years_experience, team_size, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            
            $stmt = mysqli_prepare($conn, $insertSql);
            mysqli_stmt_bind_param($stmt, 'iissssssssss', 
                $userId,
                $client_id,
                $agency_name,
                $agency_code,
                $contact_person,
                $contact_email,
                $contact_phone,
                $address,
                $website,
                $specialization,
                $years_experience,
                $team_size
            );
            
            if (mysqli_stmt_execute($stmt)) {
                // Log activity
                $client = getRecord("SELECT company_name FROM clients WHERE id = ?", [$client_id], "i");
                logActivity($userId, 'Applied as Agency for Client', 'agency_applications', mysqli_insert_id($conn), 
                    'Applied as: ' . $agency_name . ' for client: ' . ($client['company_name'] ?? 'Unknown'));
                
                $message = 'Your application has been submitted successfully! The client will review it shortly.';
                $messageType = 'success';
                
                header('Location: apply_agency.php?success=1');
                exit;
            } else {
                $message = 'Error submitting application: ' . mysqli_error($conn);
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }
}

// Check for success message
if (isset($_GET['success'])) {
    $message = 'Your application has been submitted successfully! The client will review it shortly.';
    $messageType = 'success';
}

// Get client name for filter
$filterClientName = '';
if ($clientFilter > 0) {
    $filterClient = getRecord("SELECT company_name FROM clients WHERE id = ?", [$clientFilter], "i");
    if ($filterClient) {
        $filterClientName = $filterClient['company_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Apply as Recruitment Agency - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
            --primary-container: #eef0ff;
            --on-primary: #ffffff;
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
   SIDEBAR - STANDARDIZED
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

/* Hide text when collapsed */
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

/* Sidebar Brand */
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

/* Sidebar Navigation */
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

/* Sidebar Footer */
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

/* Sidebar Backdrop */
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
        textarea.form-control { resize: vertical; min-height: 80px; }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5168' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.75rem center; padding-right: 2.5rem; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }
        .helper-text {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.1875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .helper-text .material-symbols-outlined { font-size: 0.875rem; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.approved { background: #d1fae5; color: #059669; }
        .status-badge.rejected { background: #fecaca; color: #dc2626; }
        .status-badge .material-symbols-outlined { font-size: 0.875rem; }

        .application-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            transition: all var(--transition-fast);
        }
        .application-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--slate-300);
        }
        .application-card .app-info { flex: 1; }
        .application-card .app-info .agency-name {
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-on-surface);
        }
        .application-card .app-info .agency-code {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.0625rem 0.5rem;
            border-radius: var(--radius-full);
            border: 1px solid var(--slate-200);
        }
        .application-card .app-info .client-name {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.125rem;
        }
        .application-card .app-info .client-name .material-symbols-outlined {
            font-size: 0.875rem;
            vertical-align: middle;
            color: var(--primary);
        }
        .application-card .app-info .meta {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.125rem;
        }

        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .card-header {
            padding: 1rem 1.25rem;
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
        .card-body { padding: 1.25rem; }

        .empty-state {
            text-align: center;
            padding: 2rem 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.5rem;
        }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); }
        .empty-state p { font-size: 0.875rem; }

        /* =============================================
           CONFIRMATION MODAL
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

        .modal-confirm-icon {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 2rem;
        }
        .modal-confirm-icon.info { background: #eef0ff; color: #4f46e5; }
        .modal-confirm-icon.success { background: #d1fae5; color: #059669; }
        .modal-confirm-icon.warning { background: #fef3c7; color: #d97706; }
        .modal-confirm-icon.danger { background: #fecaca; color: #dc2626; }

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
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .application-card { flex-direction: column; align-items: stretch; text-align: center; }
            .form-row { grid-template-columns: 1fr; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
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
            <span class="material-symbols-outlined">apartment</span>
        </span>
        <p class="sidebar-brand-text">ISMERS</p>
        <p class="sidebar-brand-category">HR Portal</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="clients.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'clients.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">business</span>
            <span class="nav-text">Clients</span>
        </a>
        <a href="jobs.php" class="sidebar-main-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['jobs.php', 'job_view.php', 'post_job.php']) ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">work</span>
            <span class="nav-text">My Jobs</span>
        </a>
        <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">people</span>
            <span class="nav-text">Applicants</span>
            <span class="nav-badge"><?php 
                // Get pending applications count
                $pendingApps = getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", [], "")['count'] ?? 0;
                echo $pendingApps; 
            ?></span>
        </a>
        <a href="pipeline.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'pipeline.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">view_kanban</span>
            <span class="nav-text">Pipeline</span>
        </a>
        <a href="interviews.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'interviews.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">calendar_month</span>
            <span class="nav-text">Interviews</span>
        </a>
        <a href="offers.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'offers.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">description</span>
            <span class="nav-text">Offers</span>
        </a>
        <a href="archive.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'archive.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">archive</span>
            <span class="nav-text">Archive</span>
            <span class="nav-badge"><?php 
                // Get total archive count
                $totalArchived = 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM examination_records", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM interview_evaluations", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM client_assignments", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM deployment_archive", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                echo $totalArchived;
            ?></span>
        </a>
        <a href="apply_agency.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'apply_agency.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">apartment</span>
            <span class="nav-text">Apply as Agency</span>
        </a>
        <a href="deployments.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'deployments.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">assignment</span>
            <span class="nav-text">Deployments</span>
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
                    Apply as Agency
                </span>
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
                        <span>Recruitment Agency Application</span>
                    </div>
                    <span class="breadcrumb-meta">Apply to be a recruitment agency for a client</span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>
                            <span class="material-symbols-outlined">apartment</span>
                            Apply as Recruitment Agency
                        </h1>
                        <p>Register your agency to serve specific clients</p>
                    </div>
                </div>

                <!-- My Applications -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">list_alt</span>
                            My Applications
                        </h3>
                        <span class="count-badge"><?php echo count($applications); ?> applications</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($applications)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">apartment</span>
                                <h4>No Applications Yet</h4>
                                <p>Apply to become a recruitment agency for a client below.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($applications as $app): ?>
                                <div class="application-card">
                                    <div class="app-info">
                                        <div class="agency-name">
                                            <?php echo htmlspecialchars($app['agency_name']); ?>
                                            <span class="agency-code"><?php echo htmlspecialchars($app['agency_code']); ?></span>
                                        </div>
                                        <div class="client-name">
                                            <span class="material-symbols-outlined">business</span>
                                            <?php echo htmlspecialchars($app['company_name']); ?>
                                        </div>
                                        <div class="meta">
                                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">schedule</span>
                                            Submitted: <?php echo date('M d, Y', strtotime($app['created_at'])); ?>
                                            <?php if ($app['reviewer_name'] && $app['status'] !== 'pending'): ?>
                                                · <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">person</span>
                                                Reviewed by: <?php echo htmlspecialchars($app['reviewer_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php 
                                        $statusLabels = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
                                        $statusClasses = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'];
                                        $statusIcons = ['pending' => 'pending', 'approved' => 'check_circle', 'rejected' => 'cancel'];
                                        ?>
                                        <span class="status-badge <?php echo $statusClasses[$app['status']] ?? 'pending'; ?>">
                                            <span class="material-symbols-outlined"><?php echo $statusIcons[$app['status']] ?? 'pending'; ?></span>
                                            <?php echo $statusLabels[$app['status']] ?? 'Pending'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Application Form -->
                <div style="max-width: 700px; margin: 0 auto;">
                    <div style="background: var(--bg-surface); border-radius: var(--radius-2xl); border: 1px solid var(--slate-200); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                        <div style="text-align: center; margin-bottom: 1.5rem;">
                            <span style="font-size: 2.5rem; color: var(--primary);">
                                <span class="material-symbols-outlined" style="font-size: 2.5rem;">apartment</span>
                            </span>
                            <h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0.25rem;">New Application</h2>
                            <p style="color: var(--text-on-surface-variant); font-size: 0.875rem;">Apply to become a recruitment agency for a client</p>
                        </div>

                        <form method="POST" action="" id="agencyForm">
                            <input type="hidden" name="action" value="apply_agency">

                            <div class="form-group">
                                <label>Select Client <span class="required">*</span></label>
                                <select name="client_id" class="form-control" required>
                                    <option value="">Select a client...</option>
                                    <?php foreach ($clients as $client): ?>
                                        <?php 
                                        $hasApp = false;
                                        foreach ($applications as $app) {
                                            if ($app['client_id'] == $client['id'] && in_array($app['status'], ['pending', 'approved'])) {
                                                $hasApp = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <option value="<?php echo $client['id']; ?>" 
                                                <?php echo ($clientFilter == $client['id']) ? 'selected' : ''; ?>
                                                <?php echo $hasApp ? 'disabled' : ''; ?>>
                                            <?php echo htmlspecialchars($client['company_name']); ?>
                                            <?php if ($hasApp): ?> (Already Applied)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">info</span>
                                    Select the client you want to provide recruitment services for
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Agency Name <span class="required">*</span></label>
                                <input type="text" name="agency_name" class="form-control" placeholder="e.g., TechHire Solutions" required>
                            </div>

                            <div class="form-group">
                                <label>Agency Code <span class="required">*</span></label>
                                <input type="text" name="agency_code" class="form-control" placeholder="e.g., TECH" maxlength="10" required>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">info</span>
                                    Short unique identifier for this client (2-10 characters)
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Contact Person <span class="required">*</span></label>
                                    <input type="text" name="contact_person" class="form-control" placeholder="Full name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Contact Email <span class="required">*</span></label>
                                    <input type="email" name="contact_email" class="form-control" placeholder="email@agency.com" value="<?php echo htmlspecialchars($email); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Contact Phone</label>
                                <input type="text" name="contact_phone" class="form-control" placeholder="+63 912 345 6789">
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" name="address" class="form-control" placeholder="Office address">
                            </div>

                            <div class="form-group">
                                <label>Website</label>
                                <input type="url" name="website" class="form-control" placeholder="https://www.agency.com">
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Years of Experience</label>
                                    <select name="years_experience" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="Less than 1 year">Less than 1 year</option>
                                        <option value="1-3 years">1-3 years</option>
                                        <option value="3-5 years">3-5 years</option>
                                        <option value="5-10 years">5-10 years</option>
                                        <option value="10+ years">10+ years</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Team Size</label>
                                    <select name="team_size" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="1-5">1-5</option>
                                        <option value="6-10">6-10</option>
                                        <option value="11-20">11-20</option>
                                        <option value="21-50">21-50</option>
                                        <option value="50+">50+</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Specialization</label>
                                <textarea name="specialization" class="form-control" placeholder="What industries or roles do you specialize in?" rows="3"></textarea>
                            </div>

                            <div style="background: var(--bg-surface-low); padding: 0.75rem 1rem; border-radius: var(--radius-md); margin-top: 1rem;">
                                <p style="font-size: 0.8125rem; color: var(--text-on-surface-variant); display: flex; align-items: flex-start; gap: 0.5rem;">
                                    <span class="material-symbols-outlined" style="font-size: 1rem;">info</span>
                                    By submitting this application, you agree that the information provided is accurate. The client will review your application and approve or reject it.
                                </p>
                            </div>

                            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem; justify-content: flex-end;">
                                <button type="button" class="btn btn-primary" onclick="showConfirmModal()">
                                    <span class="material-symbols-outlined">send</span>
                                    Submit Application
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- =============================================
    CONFIRMATION MODAL
    ============================================= -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">check_circle</span>
                    Confirm Submission
                </h2>
                <button class="modal-close" onclick="closeModal('confirmModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="modal-confirm-icon info">
                    <span class="material-symbols-outlined">info</span>
                </div>
                <p style="font-size:0.875rem; text-align:center; color:var(--text-on-surface-variant); margin-bottom:1rem;">
                    You are about to submit an application to become a recruitment agency for:
                </p>
                <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; text-align:center; border:1px solid var(--slate-200); margin-bottom:1rem;">
                    <span id="confirmClientName" style="font-weight:600; font-size:1rem; color:var(--text-on-surface);">--</span>
                </div>
                <div style="background:#f0fdf4; padding:0.75rem 1rem; border-radius:0.5rem; border:1px solid #bbf7d0; text-align:center;">
                    <span style="font-size:0.8125rem; color:#065f46; display:flex; align-items:center; justify-content:center; gap:0.5rem;">
                        <span class="material-symbols-outlined" style="font-size:1rem;">info</span>
                        The client will review your application and respond within 3-5 business days.
                    </span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('confirmModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmSubmitBtn">
                    <span class="material-symbols-outlined">check</span>
                    Confirm & Submit
                </button>
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
                closeModal('confirmModal');
                closeMobileSidebar();
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // 5. CONFIRMATION MODAL
        // =============================================
        function showConfirmModal() {
            const form = document.getElementById('agencyForm');
            const clientSelect = form.querySelector('select[name="client_id"]');
            const selectedClient = clientSelect.options[clientSelect.selectedIndex];
            
            if (!clientSelect.value) {
                alert('Please select a client first.');
                clientSelect.focus();
                return;
            }
            
            document.getElementById('confirmClientName').textContent = selectedClient.text;
            openModal('confirmModal');
        }

        document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
            closeModal('confirmModal');
            document.getElementById('agencyForm').submit();
        });

        // =============================================
        // 6. FORM VALIDATION
        // =============================================
        document.getElementById('agencyForm').addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default, we handle via modal
            
            const clientId = this.querySelector('select[name="client_id"]');
            const agencyName = this.querySelector('input[name="agency_name"]');
            const agencyCode = this.querySelector('input[name="agency_code"]');
            const contactPerson = this.querySelector('input[name="contact_person"]');
            const contactEmail = this.querySelector('input[name="contact_email"]');
            
            let errors = [];

            // Reset styles
            [clientId, agencyName, agencyCode, contactPerson, contactEmail].forEach(el => {
                if (el) el.style.borderColor = '';
            });

            if (!clientId || !clientId.value) {
                errors.push('Please select a client.');
                if (clientId) clientId.style.borderColor = '#dc2626';
            }

            if (!agencyName || !agencyName.value.trim()) {
                errors.push('Please enter an agency name.');
                if (agencyName) agencyName.style.borderColor = '#dc2626';
            }

            if (!agencyCode || !agencyCode.value.trim()) {
                errors.push('Please enter an agency code.');
                if (agencyCode) agencyCode.style.borderColor = '#dc2626';
            }

            if (agencyCode && agencyCode.value.trim().length < 2) {
                errors.push('Agency code must be at least 2 characters.');
                if (agencyCode) agencyCode.style.borderColor = '#dc2626';
            }

            if (!contactPerson || !contactPerson.value.trim()) {
                errors.push('Please enter a contact person.');
                if (contactPerson) contactPerson.style.borderColor = '#dc2626';
            }

            if (!contactEmail || !contactEmail.value.trim()) {
                errors.push('Please enter a contact email.');
                if (contactEmail) contactEmail.style.borderColor = '#dc2626';
            }

            if (contactEmail && contactEmail.value.trim() && !contactEmail.value.includes('@')) {
                errors.push('Please enter a valid email address.');
                if (contactEmail) contactEmail.style.borderColor = '#dc2626';
            }

            if (errors.length > 0) {
                alert('Please fix the following errors:\n\n• ' + errors.join('\n• '));
                
                const firstError = [clientId, agencyName, agencyCode, contactPerson, contactEmail].find(el => 
                    el && el.style.borderColor === '#dc2626'
                );
                if (firstError) firstError.focus();
            } else {
                showConfirmModal();
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

        console.log('🏢 ISMERS Agency Application loaded successfully!');
    </script>

</body>
</html>