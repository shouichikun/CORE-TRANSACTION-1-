<?php
// portals/client/applicants.php - Client Applicant Management
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

// =============================================
// RESUME PATH CONFIGURATION - MULTI-PATH FINDER
// =============================================
function getResumeUrl($filename) {
    if (empty($filename)) {
        return ['url' => null];
    }
    
    // Clean the filename
    $filename = trim($filename);
    $filename = ltrim($filename, '/');
    $filename = ltrim($filename, '\\');
    
    // Get just the filename without any path
    $justFilename = basename($filename);
    
    // Get the base directory (CT1 folder)
    $baseDir = dirname(__DIR__, 2);
    
    // Get the timestamp from the filename (the number at the end)
    preg_match('/resume_8_(\d+)_(\d+)\.pdf/', $justFilename, $matches);
    $dbNumber = $matches[1] ?? null;
    $dbTimestamp = $matches[2] ?? null;
    
    // 1. Check exact path as stored in database
    $directPath = $baseDir . '/' . $filename;
    if (file_exists($directPath)) {
        return ['url' => '../../' . $filename];
    }
    
    // 2. Check in hr/includes/resumes/ (Client portal resumes)
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
        return ['url' => '../../hr/includes/resumes/' . $foundFile];
    }
    
    // 3. Check in portals/hr/resumes/ (HR portal resumes)
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
        return ['url' => '../../portals/hr/resumes/' . $foundFile];
    }
    
    // 4. Check in uploads/resumes/ as fallback
    $uploadResumePath = $baseDir . '/uploads/resumes/' . $justFilename;
    if (file_exists($uploadResumePath)) {
        return ['url' => '../../uploads/resumes/' . $justFilename];
    }
    
    // 5. Check in portals/uploads/resumes/
    $portalUploadPath = $baseDir . '/portals/uploads/resumes/' . $justFilename;
    if (file_exists($portalUploadPath)) {
        return ['url' => '../uploads/resumes/' . $justFilename];
    }
    
    return ['url' => null];
}

// Handle Applicant Status Update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_applicant_status') {
        $applicationId = intval($_POST['application_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'pending';
        
        if ($applicationId > 0) {
            $verifySql = "SELECT a.id FROM applications a
                        JOIN job_orders jo ON a.job_order_id = jo.id
                        WHERE a.id = ? AND jo.client_id = ?";
            $stmt = mysqli_prepare($conn, $verifySql);
            mysqli_stmt_bind_param($stmt, 'ii', $applicationId, $clientId);
            mysqli_stmt_execute($stmt);
            $verifyResult = mysqli_stmt_get_result($stmt);
            
            if (mysqli_fetch_assoc($verifyResult)) {
                $updateSql = "UPDATE applications SET status = ?, updated_at = NOW() 
                            WHERE id = ?";
                $stmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($stmt, 'si', $newStatus, $applicationId);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = 'Applicant status updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Error updating applicant status.';
                    $messageType = 'error';
                }
                mysqli_stmt_close($stmt);
            } else {
                $message = 'You do not have permission to update this applicant.';
                $messageType = 'error';
            }
        }
    }
    
    if ($_POST['action'] === 'bulk_action') {
        $applicationIds = $_POST['application_ids'] ?? [];
        $bulkStatus = $_POST['bulk_status'] ?? '';
        
        if (!empty($applicationIds) && !empty($bulkStatus)) {
            $ids = array_map('intval', $applicationIds);
            $idsString = implode(',', $ids);
            
            $verifySql = "SELECT COUNT(*) as count FROM applications a
                        JOIN job_orders jo ON a.job_order_id = jo.id
                        WHERE a.id IN ($idsString) AND jo.client_id = ?";
            $stmt = mysqli_prepare($conn, $verifySql);
            mysqli_stmt_bind_param($stmt, 'i', $clientId);
            mysqli_stmt_execute($stmt);
            $verifyResult = mysqli_stmt_get_result($stmt);
            $verifyRow = mysqli_fetch_assoc($verifyResult);
            
            if ($verifyRow['count'] == count($ids)) {
                $updateSql = "UPDATE applications SET status = ?, updated_at = NOW() 
                            WHERE id IN ($idsString)";
                $stmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($stmt, 's', $bulkStatus);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = count($ids) . ' applicants updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Error updating applicants.';
                    $messageType = 'error';
                }
                mysqli_stmt_close($stmt);
            } else {
                $message = 'Some applications do not belong to you.';
                $messageType = 'error';
            }
        }
    }
}

// Get all applicants for this client's jobs
$applicantsSql = "SELECT a.*, 
                ap.id as applicant_profile_id, ap.phone, ap.address, 
                ap.resume_path as applicant_resume_path,
                a.resume_path as application_resume_path,
                u.id as user_id, u.first_name, u.last_name, u.email,
                jo.id as job_id, jo.title as job_title, jo.location as job_location,
                jo.job_type as job_type,
                (SELECT COUNT(*) FROM applications 
                WHERE applicant_id = a.applicant_id AND status IN ('shortlisted', 'hired')) as other_applications
                FROM applications a
                JOIN applicants ap ON a.applicant_id = ap.id
                JOIN users u ON ap.user_id = u.id
                JOIN job_orders jo ON a.job_order_id = jo.id
                WHERE jo.client_id = ?
                ORDER BY 
                    CASE a.status 
                    WHEN 'pending' THEN 1
                    WHEN 'reviewed' THEN 2
                    WHEN 'shortlisted' THEN 3
                    WHEN 'hired' THEN 4
                    WHEN 'rejected' THEN 5
                    ELSE 6
                    END,
                    a.applied_at DESC";

$stmt = mysqli_prepare($conn, $applicantsSql);
mysqli_stmt_bind_param($stmt, 'i', $clientId);
mysqli_stmt_execute($stmt);
$applicantsResult = mysqli_stmt_get_result($stmt);
$allApplicants = [];
while ($row = mysqli_fetch_assoc($applicantsResult)) {
    $allApplicants[] = $row;
}
mysqli_stmt_close($stmt);

// Get status counts
$statusCounts = [
    'all' => count($allApplicants),
    'pending' => 0,
    'reviewed' => 0,
    'shortlisted' => 0,
    'hired' => 0,
    'rejected' => 0
];

foreach ($allApplicants as $app) {
    $status = $app['status'] ?? 'pending';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// Get job filter options
$jobOptions = [];
foreach ($allApplicants as $app) {
    $jobKey = $app['job_id'];
    if (!isset($jobOptions[$jobKey])) {
        $jobOptions[$jobKey] = [
            'id' => $app['job_id'],
            'title' => $app['job_title']
        ];
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$jobFilter = isset($_GET['job']) ? intval($_GET['job']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Apply filters
$filteredApplicants = $allApplicants;

if ($filter !== 'all') {
    $filteredApplicants = array_filter($filteredApplicants, function($app) use ($filter) {
        return ($app['status'] ?? '') === $filter;
    });
}

if ($jobFilter > 0) {
    $filteredApplicants = array_filter($filteredApplicants, function($app) use ($jobFilter) {
        return $app['job_id'] == $jobFilter;
    });
}

if (!empty($search)) {
    $searchLower = strtolower($search);
    $filteredApplicants = array_filter($filteredApplicants, function($app) use ($searchLower) {
        return strpos(strtolower($app['first_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($app['last_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($app['email'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($app['job_title'] ?? ''), $searchLower) !== false;
    });
}

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$totalApplicants = count($filteredApplicants);
$totalPages = ceil($totalApplicants / $perPage);
$offset = ($page - 1) * $perPage;
$paginatedApplicants = array_slice($filteredApplicants, $offset, $perPage);

if (isset($_SESSION['toast_message'])) {
    $message = $_SESSION['toast_message'];
    $messageType = $_SESSION['toast_type'] ?? 'info';
    unset($_SESSION['toast_message']);
    unset($_SESSION['toast_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Applicants - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - CLIENT APPLICANTS
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

        /* Breadcrumb */
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

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-xs);
            text-align: center;
        }
        .stat-card .stat-number { font-size: 1.5rem; font-weight: 800; color: var(--text-on-surface); line-height: 1.2; }
        .stat-card .stat-number.primary { color: var(--primary); }
        .stat-card .stat-number.green { color: #059669; }
        .stat-card .stat-number.yellow { color: #d97706; }
        .stat-card .stat-number.blue { color: #2563eb; }
        .stat-card .stat-number.red { color: #dc2626; }
        .stat-card .stat-number.purple { color: #7c3aed; }
        .stat-card .stat-label { font-size: 0.75rem; font-weight: 500; color: var(--text-on-surface-variant); margin-top: 0.125rem; }

        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            align-items: center;
        }
        .filter-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
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
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .filter-btn .count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.05rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.6rem;
            margin-left: 0.25rem;
        }
        .filter-btn.active .count { background: rgba(255, 255, 255, 0.25); }

        .search-box {
            display: flex;
            align-items: center;
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            padding: 0.25rem 0.5rem;
            transition: all var(--transition-fast);
            flex: 1;
            min-width: 200px;
            max-width: 300px;
        }
        .search-box:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .search-box .material-symbols-outlined { color: var(--text-on-surface-variant); font-size: 1.25rem; }
        .search-box input {
            border: none;
            outline: none;
            padding: 0.375rem 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            background: transparent;
            width: 100%;
            color: var(--text-on-surface);
        }
        .search-box input::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }

        .job-filter-select {
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.75rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
            min-width: 150px;
        }
        .job-filter-select:focus { outline: none; border-color: var(--primary); }

        .bulk-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            padding: 0.75rem 1rem;
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .applicant-table-wrapper {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            overflow: hidden;
            box-shadow: var(--shadow-xs);
        }
        .applicant-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .applicant-table thead { background: var(--bg-surface-low); }
        .applicant-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            border-bottom: 1px solid var(--slate-200);
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        .applicant-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-100);
            vertical-align: middle;
        }
        .applicant-table tbody tr:hover { background: var(--bg-surface-low); }
        .applicant-table tbody tr:last-child td { border-bottom: none; }

        .applicant-checkbox { width: 1rem; height: 1rem; cursor: pointer; accent-color: var(--primary); }

        .applicant-avatar-small {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            background: var(--primary-container);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
        }
        .applicant-name-cell { display: flex; align-items: center; gap: 0.625rem; }
        .applicant-name-cell .name { font-weight: 600; color: var(--text-on-surface); }
        .applicant-name-cell .email { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

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
        .badge-reviewed { background: #dbeafe; color: #2563eb; }
        .badge-shortlisted { background: #d1fae5; color: #059669; }
        .badge-hired { background: #a7f3d0; color: #047857; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }

        .status-select {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.6875rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
        }
        .status-select:focus { outline: none; border-color: var(--primary); }

        .action-buttons { display: flex; gap: 0.375rem; flex-wrap: wrap; justify-content: flex-end; }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }
        .pagination .page-btn {
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid var(--slate-200);
            background: var(--bg-surface);
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
            min-width: 2.25rem;
            text-align: center;
        }
        .pagination .page-btn:hover { background: var(--bg-surface-low); border-color: var(--slate-300); }
        .pagination .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .page-btn.disabled { opacity: 0.4; cursor: not-allowed; }
        .pagination .page-info { font-size: 0.75rem; color: var(--text-on-surface-variant); }

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

        /* Tooltip styles */
        .btn-with-tooltip {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-with-tooltip .tooltip-text {
            visibility: hidden;
            width: 120px;
            background-color: #1a1a2e;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px 8px;
            position: absolute;
            z-index: 100;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.65rem;
            font-weight: 400;
            white-space: nowrap;
            pointer-events: none;
        }
        .btn-with-tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #1a1a2e transparent transparent transparent;
        }
        .btn-with-tooltip:hover .tooltip-text { visibility: visible; opacity: 1; }

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
            .applicant-table { font-size: 0.75rem; }
            .applicant-table th, .applicant-table td { padding: 0.5rem 0.625rem; }
            .applicant-name-cell .email { display: none; }
            .action-buttons .btn-sm span { display: none; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
            .stats-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .applicant-table { font-size: 0.6875rem; }
            .applicant-table th, .applicant-table td { padding: 0.375rem 0.5rem; }
            .applicant-table th:nth-child(4), .applicant-table td:nth-child(4) { display: none; }
            .applicant-table th:nth-child(5), .applicant-table td:nth-child(5) { display: none; }
            .bulk-actions { flex-direction: column; align-items: stretch; }
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

    <!-- ===== SIDEBAR - FIXED ===== -->
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Applicants</span>
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
                        <span class="material-symbols-outlined">person_search</span>
                        <span>Applicant Management</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Total Applicants: <?php echo count($allApplicants); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Applicants</h1>
                        <p>Review and manage job applicants</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-number primary"><?php echo $statusCounts['all']; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number yellow"><?php echo $statusCounts['pending']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number blue"><?php echo $statusCounts['reviewed']; ?></div>
                        <div class="stat-label">Reviewed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number green"><?php echo $statusCounts['shortlisted']; ?></div>
                        <div class="stat-label">Shortlisted</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number purple"><?php echo $statusCounts['hired']; ?></div>
                        <div class="stat-label">Hired</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number red"><?php echo $statusCounts['rejected']; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-bar">
                    <div class="filter-group">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'all', 'page' => 1])); ?>" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            All <span class="count"><?php echo $statusCounts['all']; ?></span>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'pending', 'page' => 1])); ?>" class="filter-btn <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                            Pending <span class="count"><?php echo $statusCounts['pending']; ?></span>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'reviewed', 'page' => 1])); ?>" class="filter-btn <?php echo $filter === 'reviewed' ? 'active' : ''; ?>">
                            Reviewed <span class="count"><?php echo $statusCounts['reviewed']; ?></span>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'shortlisted', 'page' => 1])); ?>" class="filter-btn <?php echo $filter === 'shortlisted' ? 'active' : ''; ?>">
                            Shortlisted <span class="count"><?php echo $statusCounts['shortlisted']; ?></span>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'hired', 'page' => 1])); ?>" class="filter-btn <?php echo $filter === 'hired' ? 'active' : ''; ?>">
                            Hired <span class="count"><?php echo $statusCounts['hired']; ?></span>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['filter' => 'rejected', 'page' => 1])); ?>" class="filter-btn <?php echo $filter === 'rejected' ? 'active' : ''; ?>">
                            Rejected <span class="count"><?php echo $statusCounts['rejected']; ?></span>
                        </a>
                    </div>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-left:auto;">
                        <select class="job-filter-select" onchange="window.location.href='?' + new URLSearchParams({...Object.fromEntries(new URLSearchParams(window.location.search)), job: this.value, page: 1}).toString()">
                            <option value="0">All Jobs</option>
                            <?php foreach ($jobOptions as $job): ?>
                                <option value="<?php echo $job['id']; ?>" <?php echo $jobFilter == $job['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($job['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <form method="GET" class="search-box">
                            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                            <input type="hidden" name="job" value="<?php echo htmlspecialchars($jobFilter); ?>">
                            <input type="hidden" name="page" value="1">
                            <span class="material-symbols-outlined">search</span>
                            <input type="text" name="search" placeholder="Search applicants..." value="<?php echo htmlspecialchars($search); ?>">
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <form method="POST" id="bulkActionForm">
                    <input type="hidden" name="action" value="bulk_action">
                    <input type="hidden" name="bulk_status" id="bulkStatus" value="">
                    
                    <div class="bulk-actions">
                        <label>
                            <input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes()">
                            Select All
                        </label>
                        <span style="color:var(--text-on-surface-variant); font-size:0.75rem;">|</span>
                        <label style="font-weight:400;">Bulk Update:</label>
                        <select class="status-select" id="bulkStatusSelect" style="padding:0.375rem 0.75rem;">
                            <option value="pending">Pending</option>
                            <option value="reviewed">Reviewed</option>
                            <option value="shortlisted">Shortlist</option>
                            <option value="hired">Hire</option>
                            <option value="rejected">Reject</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary" onclick="document.getElementById('bulkStatus').value = document.getElementById('bulkStatusSelect').value; return confirm('Update selected applicants?')">
                            <span class="material-symbols-outlined" style="font-size:0.875rem;">update</span>
                            Apply to Selected
                        </button>
                        <span id="selectedCount" style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">0 selected</span>
                    </div>

                    <!-- Applicant Table -->
                    <div class="applicant-table-wrapper">
                        <?php if (empty($paginatedApplicants)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">person_off</span>
                                <h3>No applicants found</h3>
                                <p>
                                    <?php if ($filter !== 'all' || $jobFilter > 0 || !empty($search)): ?>
                                        No applicants match your current filters.
                                        <a href="applicants.php" style="color:var(--primary); font-weight:600;">Clear filters</a>
                                    <?php else: ?>
                                        No one has applied to your jobs yet. 
                                        <a href="jobs.php" style="color:var(--primary); font-weight:600;">Post a job</a> to start receiving applications.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <table class="applicant-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="selectAllTable" onchange="toggleAllCheckboxes()" class="applicant-checkbox">
                                        </th>
                                        <th>Applicant</th>
                                        <th>Job</th>
                                        <th>Applied</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paginatedApplicants as $app): ?>
                                        <?php 
                                        // Try to get resume from applications table first
                                        $resumeFile = null;
                                        
                                        if (!empty($app['application_resume_path'])) {
                                            $resumeFile = $app['application_resume_path'];
                                        } elseif (!empty($app['applicant_resume_path'])) {
                                            $resumeFile = $app['applicant_resume_path'];
                                        }
                                        
                                        $resumeUrl = null;
                                        
                                        if ($resumeFile) {
                                            $resumeData = getResumeUrl($resumeFile);
                                            if ($resumeData && isset($resumeData['url'])) {
                                                $resumeUrl = $resumeData['url'];
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="application_ids[]" value="<?php echo $app['id']; ?>" class="applicant-checkbox applicant-select" onchange="updateSelectedCount()">
                                            </td>
                                            <td>
                                                <div class="applicant-name-cell">
                                                    <span class="applicant-avatar-small">
                                                        <?php echo strtoupper(substr($app['first_name'] ?? 'A', 0, 1) . substr($app['last_name'] ?? '', 0, 1)); ?>
                                                    </span>
                                                    <div>
                                                        <div class="name"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                                        <div class="email"><?php echo htmlspecialchars($app['email']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight:500; font-size:0.75rem; color:var(--text-on-surface);">
                                                    <?php echo htmlspecialchars($app['job_title']); ?>
                                                </div>
                                                <div style="font-size:0.625rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($app['job_location'] ?? 'Remote'); ?>
                                                </div>
                                            </td>
                                            <td style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y', strtotime($app['applied_at'] ?? 'now')); ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $app['status']; ?>">
                                                    <?php echo ucfirst($app['status']); ?>
                                                </span>
                                                <?php if ($app['other_applications'] > 0): ?>
                                                    <span style="display:block; font-size:0.5625rem; color:var(--primary); margin-top:0.125rem;">
                                                        <?php echo $app['other_applications']; ?> other app(s)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <div class="action-buttons">
                                                    <form method="POST" style="display:flex; gap:0.375rem; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                                                        <input type="hidden" name="action" value="update_applicant_status">
                                                        <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                        <select name="new_status" class="status-select" style="max-width:100px;">
                                                            <option value="pending" <?php echo $app['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                            <option value="reviewed" <?php echo $app['status'] === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                                            <option value="shortlisted" <?php echo $app['status'] === 'shortlisted' ? 'selected' : ''; ?>>Shortlist</option>
                                                            <option value="hired" <?php echo $app['status'] === 'hired' ? 'selected' : ''; ?>>Hire</option>
                                                            <option value="rejected" <?php echo $app['status'] === 'rejected' ? 'selected' : ''; ?>>Reject</option>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-primary" style="padding:0.25rem 0.5rem;">
                                                            <span class="material-symbols-outlined" style="font-size:0.75rem;">update</span>
                                                        </button>
                                                    </form>
                                                    
                                                    <?php if ($resumeUrl): ?>
                                                        <div class="btn-with-tooltip">
                                                            <a href="<?php echo htmlspecialchars($resumeUrl); ?>" target="_blank" class="btn btn-sm btn-outline" style="padding:0.25rem 0.5rem; background:#059669; color:white; border-color:#059669;">
                                                                <span class="material-symbols-outlined" style="font-size:0.75rem;">description</span>
                                                            </a>
                                                            <span class="tooltip-text">View Resume</span>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="btn-with-tooltip">
                                                            <span class="btn btn-sm btn-ghost" style="padding:0.25rem 0.5rem; opacity:0.4; cursor:not-allowed;">
                                                                <span class="material-symbols-outlined" style="font-size:0.75rem;">description</span>
                                                            </span>
                                                            <span class="tooltip-text">No Resume</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <a href="view_applicant.php?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline" style="padding:0.25rem 0.5rem;" title="View Details">
                                                        <span class="material-symbols-outlined" style="font-size:0.75rem;">visibility</span>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-btn">
                                        <span class="material-symbols-outlined" style="font-size:1rem;">chevron_left</span>
                                    </a>
                                <?php else: ?>
                                    <span class="page-btn disabled">
                                        <span class="material-symbols-outlined" style="font-size:1rem;">chevron_left</span>
                                    </span>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                if ($startPage > 1) {
                                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="page-btn">1</a>';
                                    if ($startPage > 2) echo '<span class="page-btn disabled">...</span>';
                                }
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    $active = $i == $page ? 'active' : '';
                                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '" class="page-btn ' . $active . '">' . $i . '</a>';
                                }
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) echo '<span class="page-btn disabled">...</span>';
                                    echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '" class="page-btn">' . $totalPages . '</a>';
                                }
                                ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-btn">
                                        <span class="material-symbols-outlined" style="font-size:1rem;">chevron_right</span>
                                    </a>
                                <?php else: ?>
                                    <span class="page-btn disabled">
                                        <span class="material-symbols-outlined" style="font-size:1rem;">chevron_right</span>
                                    </span>
                                <?php endif; ?>
                                
                                <span class="page-info">
                                    Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $perPage, $totalApplicants); ?> of <?php echo $totalApplicants; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
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

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', openMobileSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        }

        // =============================================
        // PROFILE DROPDOWN
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
        // BULK ACTIONS
        // =============================================
        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.applicant-select');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.applicant-select:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length + ' selected';
        }

        // Update count on page load
        document.addEventListener('DOMContentLoaded', updateSelectedCount);

        // =============================================
        // KEYBOARD SHORTCUTS
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
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

        console.log('👤 ISMERS Applicant Management loaded successfully!');
    </script>

</body>
</html>