<?php
// portals/client/view_applicant.php - View Applicant Details (Single Container)
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
$lastName = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'client'; // ADD THIS LINE


// Get application ID from URL
$applicationId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($applicationId <= 0) {
    header('Location: applicants.php');
    exit;
}

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

// Get applicant details with verification that it belongs to this client
$applicantSql = "SELECT a.*, 
                 a.cover_letter, a.resume_path as application_resume_path,
                 ap.id as applicant_profile_id, ap.phone, ap.address, 
                 ap.skills, ap.experience, ap.education, ap.resume_path as applicant_resume_path,
                 ap.profile_picture, ap.expected_salary, ap.availability_date,
                 ap.face_match_score, ap.is_identity_verified,
                 u.id as user_id, u.first_name, u.last_name, u.email,
                 jo.id as job_id, jo.title as job_title, jo.location as job_location,
                 jo.job_type as job_type, jo.description as job_description,
                 jo.salary_min as job_salary_min, jo.salary_max as job_salary_max,
                 c.company_name,
                 (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id) as total_applications,
                 (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id AND status = 'hired') as hired_count,
                 (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id AND status = 'shortlisted') as shortlisted_count
                 FROM applications a
                 JOIN applicants ap ON a.applicant_id = ap.id
                 JOIN users u ON ap.user_id = u.id
                 JOIN job_orders jo ON a.job_order_id = jo.id
                 JOIN clients c ON jo.client_id = c.id
                 WHERE a.id = ? AND jo.client_id = ?";

$stmt = mysqli_prepare($conn, $applicantSql);
mysqli_stmt_bind_param($stmt, 'ii', $applicationId, $clientId);
mysqli_stmt_execute($stmt);
$applicantResult = mysqli_stmt_get_result($stmt);
$applicant = mysqli_fetch_assoc($applicantResult);
mysqli_stmt_close($stmt);

// If applicant doesn't exist or doesn't belong to this client
if (!$applicant) {
    header('Location: applicants.php');
    exit;
}

// =============================================
// RESUME PATH CONFIGURATION - MULTI-PATH FINDER
// =============================================
function getResumeUrl($filename) {
    if (empty($filename)) {
        return ['url' => null, 'exists' => false];
    }
    
    // Clean the filename
    $filename = trim($filename);
    $filename = ltrim($filename, '/');
    $filename = ltrim($filename, '\\');
    
    // Get just the filename without any path
    $justFilename = basename($filename);
    
    // Get the base directory (CT1 folder)
    $baseDir = dirname(__DIR__, 2);
    
    // Get the timestamp from the filename
    preg_match('/resume_8_(\d+)_(\d+)\.pdf/', $justFilename, $matches);
    $dbNumber = $matches[1] ?? null;
    $dbTimestamp = $matches[2] ?? null;
    
    // 1. Check exact path as stored in database
    $directPath = $baseDir . '/' . $filename;
    if (file_exists($directPath)) {
        return ['url' => '../../' . $filename, 'exists' => true];
    }
    
    // 2. Check in hr/includes/resumes/
    $hrResumeDir = $baseDir . '/hr/includes/resumes/';
    $foundFile = null;
    $foundPath = null;
    
    if (is_dir($hrResumeDir)) {
        $files = scandir($hrResumeDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            if ($file === $justFilename) {
                $foundFile = $file;
                $foundPath = $hrResumeDir;
                break;
            }
            
            if ($dbTimestamp && strpos($file, $dbTimestamp) !== false) {
                $foundFile = $file;
                $foundPath = $hrResumeDir;
                break;
            }
            
            if ($dbNumber && strpos($file, 'resume_8_' . $dbNumber . '_') !== false) {
                $foundFile = $file;
                $foundPath = $hrResumeDir;
                break;
            }
        }
        
        if (!$foundFile) {
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && strpos($file, '.pdf') !== false) {
                    $foundFile = $file;
                    $foundPath = $hrResumeDir;
                    break;
                }
            }
        }
    }
    
    if ($foundFile && file_exists($foundPath . $foundFile)) {
        return ['url' => '../../hr/includes/resumes/' . $foundFile, 'exists' => true];
    }
    
    // 3. Check in portals/hr/resumes/
    $hrPortalResumeDir = $baseDir . '/portals/hr/resumes/';
    $foundFile = null;
    $foundPath = null;
    
    if (is_dir($hrPortalResumeDir)) {
        $files = scandir($hrPortalResumeDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            if ($file === $justFilename) {
                $foundFile = $file;
                $foundPath = $hrPortalResumeDir;
                break;
            }
            
            if ($dbTimestamp && strpos($file, $dbTimestamp) !== false) {
                $foundFile = $file;
                $foundPath = $hrPortalResumeDir;
                break;
            }
            
            if ($dbNumber && strpos($file, 'resume_8_' . $dbNumber . '_') !== false) {
                $foundFile = $file;
                $foundPath = $hrPortalResumeDir;
                break;
            }
        }
        
        if (!$foundFile) {
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && strpos($file, '.pdf') !== false) {
                    $foundFile = $file;
                    $foundPath = $hrPortalResumeDir;
                    break;
                }
            }
        }
    }
    
    if ($foundFile && file_exists($foundPath . $foundFile)) {
        return ['url' => '../../portals/hr/resumes/' . $foundFile, 'exists' => true];
    }
    
    // 4. Check in uploads/resumes/
    $uploadResumePath = $baseDir . '/uploads/resumes/' . $justFilename;
    if (file_exists($uploadResumePath)) {
        return ['url' => '../../uploads/resumes/' . $justFilename, 'exists' => true];
    }
    
    // 5. Check in portals/uploads/resumes/
    $portalUploadPath = $baseDir . '/portals/uploads/resumes/' . $justFilename;
    if (file_exists($portalUploadPath)) {
        return ['url' => '../uploads/resumes/' . $justFilename, 'exists' => true];
    }
    
    return ['url' => null, 'exists' => false];
}

// Get resume info
$resumeInfo = getResumeUrl($applicant['application_resume_path'] ?? $applicant['applicant_resume_path'] ?? '');

// Handle status update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        $validStatuses = ['pending', 'reviewed', 'shortlisted', 'hired', 'rejected'];
        
        if (in_array($newStatus, $validStatuses)) {
            $updateSql = "UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($stmt, 'si', $newStatus, $applicationId);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Application status updated successfully!';
                $messageType = 'success';
                $applicant['status'] = $newStatus;
                
                // Log activity
                logActivity($userId, 'Application Status Updated', 'applications', $applicationId, 
                           'Status changed to: ' . $newStatus . ($notes ? ' | Notes: ' . $notes : ''));
            } else {
                $message = 'Failed to update status.';
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Status badge mapping
$statusBadges = [
    'pending' => 'badge-pending',
    'reviewed' => 'badge-reviewed',
    'shortlisted' => 'badge-shortlisted',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected'
];

$statusLabels = [
    'pending' => 'Pending Review',
    'reviewed' => 'Reviewed',
    'shortlisted' => 'Shortlisted',
    'hired' => 'Hired',
    'rejected' => 'Rejected'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Applicant Details - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           APPLICANT DETAILS - SINGLE CONTAINER
           ========================================================================== */
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
            --on-primary-fixed-variant: #4338ca;
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

        /* Sidebar */
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
           SINGLE CONTAINER - APPLICANT DETAILS
        ============================================= */
        .details-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        /* Profile Header */
        .details-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 1.5rem 2rem;
            background: var(--bg-surface-low);
            border-bottom: 3px solid var(--primary);
            flex-wrap: wrap;
        }
        @media (max-width: 640px) {
            .details-header { flex-direction: column; text-align: center; }
        }

        .details-header .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 2rem;
            flex-shrink: 0;
        }

        .details-header .header-info {
            flex: 1;
        }
        .details-header .header-info .name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }
        .details-header .header-info .email {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }
        .details-header .header-info .meta {
            display: flex;
            gap: 1.5rem;
            margin-top: 0.375rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            flex-wrap: wrap;
        }
        .details-header .header-info .meta .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
            color: var(--primary);
        }

        .details-header .header-status {
            flex-shrink: 0;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-reviewed { background: #dbeafe; color: #2563eb; }
        .badge-shortlisted { background: #d1fae5; color: #059669; }
        .badge-hired { background: #a7f3d0; color: #047857; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }

        /* Divider Lines */
        .section-divider {
            height: 2px;
            background: linear-gradient(to right, var(--primary), var(--primary-light), transparent);
            margin: 0;
            border: none;
            opacity: 0.6;
        }

        /* Section Styles */
        .details-section {
            padding: 1.25rem 2rem;
        }
        .details-section:last-child {
            padding-bottom: 1.5rem;
        }

        .details-section .section-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
        }
        .details-section .section-title .material-symbols-outlined {
            color: var(--primary);
            font-size: 1.25rem;
        }

        /* Field Grid */
        .field-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 0.5rem 1rem;
            padding: 0.375rem 0;
            border-bottom: 1px solid var(--slate-100);
        }
        .field-grid:last-child {
            border-bottom: none;
        }
        .field-grid .field-label {
            font-weight: 600;
            color: var(--text-on-surface);
            font-size: 0.8125rem;
        }
        .field-grid .field-value {
            color: var(--text-on-surface-variant);
            font-size: 0.875rem;
        }
        .field-grid .field-value .empty {
            color: var(--slate-400);
            font-style: italic;
        }

        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
        }
        .skills-tags .skill-tag {
            display: inline-block;
            padding: 0.1875rem 0.625rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
        }

        .cover-letter-content {
            background: var(--bg-surface-low);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border-left: 4px solid var(--primary);
            white-space: pre-wrap;
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            line-height: 1.7;
            max-height: 200px;
            overflow-y: auto;
        }

        .status-select {
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
            min-width: 150px;
        }
        .status-select:focus {
            outline: none;
            border-color: var(--primary);
        }

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
        .btn-info { background: #2563eb; color: white; }
        .btn-info:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* Resume Section */
        .resume-section {
            padding: 0.75rem 1rem;
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }
        .resume-section .resume-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .resume-section .resume-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .resume-section .resume-info .resume-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.75rem;
            color: white;
            flex-shrink: 0;
        }
        .resume-section .resume-info .resume-icon.pdf { background: #dc2626; }
        .resume-section .resume-info .resume-icon.doc { background: #2563eb; }
        .resume-section .resume-info .resume-icon.docx { background: #2563eb; }
        .resume-section .resume-info .resume-icon.default { background: #6b7280; }
        .resume-section .resume-info .resume-details .resume-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }
        .resume-section .resume-info .resume-details .resume-size {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .resume-section .resume-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .resume-empty {
            text-align: center;
            padding: 1.25rem;
            color: var(--text-on-surface-variant);
        }
        .resume-empty .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 0.5rem;
        }

        /* Toast */
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

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
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
            .details-header { flex-direction: column; text-align: center; }
            .field-grid { grid-template-columns: 1fr; }
            .details-section { padding: 1rem 1.25rem; }
            .details-header { padding: 1.25rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .details-header .avatar { width: 60px; height: 60px; font-size: 1.5rem; }
            .details-section { padding: 0.75rem 1rem; }
            .details-section:last-child { padding-bottom: 1rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
 /* Profile Picture Styles */
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

.avatar-img-large {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

/* Sidebar user card with profile picture */
.sidebar-footer .user-card .avatar-img {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
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
        SIDEBAR FOOTER - FIXED
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Dashboard</span>
            </div>
         <?php
// Get user profile data for dropdown
$userProfile = getUserProfileData($userId);
?>
<div class="profile-dropdown-wrapper">
    <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
        <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                 alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                 class="avatar-small" 
                 style="width:2rem; height:2rem; border-radius:50%; object-fit:cover; flex-shrink:0; background:var(--primary);">
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
                        <span class="material-symbols-outlined">person</span>
                        <span>Applicant Details</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Applied <?php echo date('M d, Y', strtotime($applicant['applied_at'] ?? 'now')); ?></span>
                </div>

                <!-- Single Details Card -->
                <div class="details-card">
                    <!-- Header -->
                    <div class="details-header">
                        <div class="avatar">
                            <?php 
                            $initial = strtoupper(substr($applicant['first_name'] ?? 'A', 0, 1) . substr($applicant['last_name'] ?? '', 0, 1));
                            echo htmlspecialchars($initial);
                            ?>
                        </div>
                        <div class="header-info">
                            <div class="name"><?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?></div>
                            <div class="email"><?php echo htmlspecialchars($applicant['email']); ?></div>
                            <div class="meta">
                                <span>
                                    <span class="material-symbols-outlined">phone</span>
                                    <?php echo htmlspecialchars($applicant['phone'] ?? 'Not provided'); ?>
                                </span>
                                <span>
                                    <span class="material-symbols-outlined">work</span>
                                    <?php echo htmlspecialchars($applicant['job_title']); ?>
                                </span>
                                <span>
                                    <span class="material-symbols-outlined">business</span>
                                    <?php echo htmlspecialchars($applicant['company_name']); ?>
                                </span>
                                <span>
                                    <span class="material-symbols-outlined">schedule</span>
                                    Applied <?php echo date('M d, Y', strtotime($applicant['applied_at'] ?? 'now')); ?>
                                </span>
                            </div>
                        </div>
                        <div class="header-status">
                            <span class="badge <?php echo $statusBadges[$applicant['status']] ?? 'badge-pending'; ?>">
                                <?php echo $statusLabels[$applicant['status']] ?? ucfirst($applicant['status']); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Status Update Section -->
                    <div class="details-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">edit_note</span>
                            Update Status
                        </div>
                        <form method="POST" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
                            <input type="hidden" name="action" value="update_status">
                            <div style="flex:1; min-width:200px;">
                                <label style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant); display:block; margin-bottom:0.25rem;">Change Status</label>
                                <select name="status" class="status-select" style="width:100%;">
                                    <option value="pending" <?php echo $applicant['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                    <option value="reviewed" <?php echo $applicant['status'] === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                    <option value="shortlisted" <?php echo $applicant['status'] === 'shortlisted' ? 'selected' : ''; ?>>Shortlist</option>
                                    <option value="hired" <?php echo $applicant['status'] === 'hired' ? 'selected' : ''; ?>>Hire</option>
                                    <option value="rejected" <?php echo $applicant['status'] === 'rejected' ? 'selected' : ''; ?>>Reject</option>
                                </select>
                            </div>
                            <div style="flex:1; min-width:200px;">
                                <label style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant); display:block; margin-bottom:0.25rem;">Notes (Optional)</label>
                                <input type="text" name="notes" class="form-control" style="width:100%; padding:0.5rem 0.75rem; border:1.5px solid var(--slate-200); border-radius:0.5rem; font-size:0.8125rem;" placeholder="Add a note...">
                            </div>
                            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">
                                <span class="material-symbols-outlined">update</span>
                                Update Status
                            </button>
                        </form>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Resume Section -->
                    <div class="details-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">description</span>
                            Resume / CV
                        </div>
                        <?php if ($resumeInfo['exists']): ?>
                            <div class="resume-section">
                                <div class="resume-header">
                                    <div class="resume-info">
                                        <?php
                                        $extension = strtolower(pathinfo($resumeInfo['url'], PATHINFO_EXTENSION));
                                        $iconClass = $extension === 'pdf' ? 'pdf' : ($extension === 'doc' || $extension === 'docx' ? 'doc' : 'default');
                                        ?>
                                        <div class="resume-icon <?php echo $iconClass; ?>">
                                            <?php echo strtoupper($extension ?: 'PDF'); ?>
                                        </div>
                                        <div class="resume-details">
                                            <div class="resume-name"><?php echo htmlspecialchars(basename($resumeInfo['url'])); ?></div>
                                            <div class="resume-size">Resume file</div>
                                        </div>
                                    </div>
                                    <div class="resume-actions">
                                        <a href="<?php echo htmlspecialchars($resumeInfo['url']); ?>" target="_blank" class="btn btn-info btn-sm">
                                            <span class="material-symbols-outlined">visibility</span>
                                            View
                                        </a>
                                        <a href="<?php echo htmlspecialchars($resumeInfo['url']); ?>" download class="btn btn-success btn-sm">
                                            <span class="material-symbols-outlined">download</span>
                                            Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="resume-empty">
                                <span class="material-symbols-outlined">description</span>
                                <p>No resume uploaded by the applicant.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Personal Information -->
                    <div class="details-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">person</span>
                            Personal Information
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Full Name</span>
                            <span class="field-value"><?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?></span>
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Email</span>
                            <span class="field-value"><?php echo htmlspecialchars($applicant['email']); ?></span>
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Phone</span>
                            <span class="field-value"><?php echo htmlspecialchars($applicant['phone'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="field-grid" style="border-bottom:none;">
                            <span class="field-label">Address</span>
                            <span class="field-value"><?php echo htmlspecialchars($applicant['address'] ?? 'Not provided'); ?></span>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Skills & Experience -->
                    <div class="details-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">star</span>
                            Skills & Experience
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Skills</span>
                            <span class="field-value">
                                <?php if (!empty($applicant['skills'])): ?>
                                    <?php 
                                    $skills = array_map('trim', explode(',', $applicant['skills']));
                                    ?>
                                    <div class="skills-tags">
                                        <?php foreach ($skills as $skill): ?>
                                            <?php if (!empty($skill)): ?>
                                                <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="empty">No skills listed</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Experience</span>
                            <span class="field-value"><?php echo nl2br(htmlspecialchars($applicant['experience'] ?? 'Not provided')); ?></span>
                        </div>
                        <div class="field-grid" style="border-bottom:none;">
                            <span class="field-label">Education</span>
                            <span class="field-value"><?php echo nl2br(htmlspecialchars($applicant['education'] ?? 'Not provided')); ?></span>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Cover Letter -->
                    <?php if (!empty($applicant['cover_letter'])): ?>
                    <div class="details-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">draft</span>
                            Cover Letter
                        </div>
                        <div class="cover-letter-content">
                            <?php echo nl2br(htmlspecialchars($applicant['cover_letter'])); ?>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">
                    <?php endif; ?>

                    <!-- Job Details -->
                    <div class="details-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">work</span>
                            Job Details
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Position</span>
                            <span class="field-value"><strong><?php echo htmlspecialchars($applicant['job_title']); ?></strong></span>
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Company</span>
                            <span class="field-value"><?php echo htmlspecialchars($applicant['company_name']); ?></span>
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Location</span>
                            <span class="field-value"><?php echo htmlspecialchars($applicant['job_location'] ?? 'Remote'); ?></span>
                        </div>
                        <div class="field-grid">
                            <span class="field-label">Job Type</span>
                            <span class="field-value"><?php echo ucfirst(str_replace('_', ' ', $applicant['job_type'] ?? 'Full-time')); ?></span>
                        </div>
                        <?php if (!empty($applicant['job_salary_min']) || !empty($applicant['job_salary_max'])): ?>
                        <div class="field-grid" style="border-bottom:none;">
                            <span class="field-label">Salary Range</span>
                            <span class="field-value">
                                <?php if (!empty($applicant['job_salary_min']) && !empty($applicant['job_salary_max'])): ?>
                                    ₱<?php echo number_format($applicant['job_salary_min']); ?> - ₱<?php echo number_format($applicant['job_salary_max']); ?>
                                <?php elseif (!empty($applicant['job_salary_min'])): ?>
                                    ₱<?php echo number_format($applicant['job_salary_min']); ?>+
                                <?php elseif (!empty($applicant['job_salary_max'])): ?>
                                    Up to ₱<?php echo number_format($applicant['job_salary_max']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
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
        // KEYBOARD SHORTCUTS
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

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

        console.log('👤 ISMERS View Applicant (Single Container) loaded successfully!');
    </script>

</body>
</html>