<?php
// portals/hr/pipeline.php - Kanban Pipeline (Updated Stages)
session_start();

// =============================================
// DEBUG: Log all errors to a file
// =============================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/pipeline_errors.log');
error_reporting(E_ALL);

// For AJAX requests, capture any output
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    ob_start();
}

require_once '../../app/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

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
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

// If no job ID, show job selection
$selectedJob = null;
if ($jobId > 0) {
    $selectedJob = getRecord("SELECT * FROM job_orders WHERE id = ? AND created_by = ?", [$jobId, $userId], "ii");
}

// Get all jobs for dropdown
$jobs = getRecords("SELECT id, title FROM job_orders WHERE created_by = ? ORDER BY created_at DESC", [$userId], "i");

// =============================================
// PIPELINE STAGES - NEW ORDER
// =============================================
$stages = [
    'pending' => [
        'label' => 'New Applicant',
        'color' => '#f59e0b',
        'bg' => 'rgba(245, 158, 11, 0.10)',
        'border' => 'rgba(245, 158, 11, 0.30)',
        'icon' => 'person_add',
        'order' => 1
    ],
    'scheduled' => [
        'label' => 'Scheduled',
        'color' => '#2563eb',
        'bg' => 'rgba(37, 99, 235, 0.10)',
        'border' => 'rgba(37, 99, 235, 0.30)',
        'icon' => 'calendar_month',
        'order' => 2
    ],
    'interviewed' => [
        'label' => 'Interviewed',
        'color' => '#7c3aed',
        'bg' => 'rgba(124, 58, 237, 0.10)',
        'border' => 'rgba(124, 58, 237, 0.30)',
        'icon' => 'record_voice_over',
        'order' => 3
    ],
    'shortlisted' => [
        'label' => 'Shortlisted',
        'color' => '#0891b2',
        'bg' => 'rgba(8, 145, 178, 0.10)',
        'border' => 'rgba(8, 145, 178, 0.30)',
        'icon' => 'stars',
        'order' => 4
    ],
    'hired' => [
        'label' => 'Hired',
        'color' => '#059669',
        'bg' => 'rgba(5, 150, 105, 0.10)',
        'border' => 'rgba(5, 150, 105, 0.30)',
        'icon' => 'workspace_premium',
        'order' => 5
    ],
    'rejected' => [
        'label' => 'Not Selected',
        'color' => '#dc2626',
        'bg' => 'rgba(220, 38, 38, 0.08)',
        'border' => 'rgba(220, 38, 38, 0.25)',
        'icon' => 'block',
        'order' => 6
    ]
];

// Sort stages by order
uasort($stages, function($a, $b) {
    return $a['order'] - $b['order'];
});

// =============================================
// AJAX HANDLER
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    
    try {
        $action = $_POST['action'] ?? '';
        $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
        $newStatus = $_POST['status'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        error_log('AJAX Request: action=' . $action . ', id=' . $applicationId . ', status=' . $newStatus);
        
        if ($action === 'move_stage' && $applicationId > 0 && array_key_exists($newStatus, $stages)) {
            $current = getRecord("SELECT status FROM applications WHERE id = ?", [$applicationId], "i");
            $oldStatus = $current['status'] ?? 'pending';
            
            $updateSql = "UPDATE applications SET status = ?, updated_at = NOW() WHERE id = ?";
            $result = updateRecord($updateSql, [$newStatus, $applicationId], "si");
            
            if ($result) {
                $historySql = "INSERT INTO pipeline_history (application_id, old_status, new_status, changed_by, notes) 
                               VALUES (?, ?, ?, ?, ?)";
                insertRecord($historySql, [$applicationId, $oldStatus, $newStatus, $userId, $notes], "issss");
                
                if (in_array($newStatus, ['shortlisted', 'interviewed', 'hired', 'rejected'])) {
                    sendStatusUpdateEmail($applicationId, $newStatus, $notes);
                }
                
                echo json_encode(['success' => true, 'message' => 'Candidate moved to ' . $stages[$newStatus]['label']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update status in database.']);
            }
            exit;
        }
        
        if ($action === 'get_applicants' && $jobId > 0) {
            $applicants = getRecords("
                SELECT a.id, a.status, a.applied_at, a.match_score, a.match_details,
                       u.id as user_id, u.first_name, u.last_name, u.email,
                       ap.skills, ap.experience, ap.profile_picture
                FROM applications a
                JOIN applicants ap ON a.applicant_id = ap.id
                JOIN users u ON ap.user_id = u.id
                WHERE a.job_order_id = ?
                ORDER BY a.applied_at DESC
            ", [$jobId], "i");
            
            foreach ($applicants as &$app) {
                if ($app['match_score'] === null) {
                    $matchData = calculateMatchScore($app['user_id'], $jobId);
                    $app['match_score'] = $matchData['score'];
                    $app['match_level'] = $matchData['level'];
                    updateRecord(
                        "UPDATE applications SET match_score = ?, match_details = ? WHERE id = ?",
                        [$matchData['score'], json_encode($matchData['details']), $app['id']],
                        "dsi"
                    );
                } else {
                    $app['match_level'] = getMatchLevel($app['match_score']);
                }
            }
            
            echo json_encode(['success' => true, 'applicants' => $applicants]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => 'Invalid action or parameters.']);
        exit;
        
    } catch (Exception $e) {
        error_log('AJAX Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine());
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// =============================================
// GET APPLICANTS FOR THE SELECTED JOB
// =============================================
$applicantsByStage = [];
if ($selectedJob) {
    $allApplicants = getRecords("
        SELECT a.id, a.status, a.applied_at, a.match_score, a.match_details,
               u.id as user_id, u.first_name, u.last_name, u.email,
               ap.skills, ap.experience, ap.profile_picture
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        WHERE a.job_order_id = ?
        ORDER BY a.applied_at DESC
    ", [$jobId], "i");
    
    foreach ($allApplicants as $app) {
        if ($app['match_score'] === null) {
            $matchData = calculateMatchScore($app['user_id'], $jobId);
            $app['match_score'] = $matchData['score'];
            $app['match_level'] = $matchData['level'];
            updateRecord(
                "UPDATE applications SET match_score = ?, match_details = ? WHERE id = ?",
                [$matchData['score'], json_encode($matchData['details']), $app['id']],
                "dsi"
            );
        } else {
            $app['match_level'] = getMatchLevel($app['match_score']);
        }
        $applicantsByStage[$app['status']][] = $app;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Pipeline - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           PIPELINE - PROFESSIONAL EDITION
           ========================================================================== */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --bg-surface-container-low: #f5f6fa;
            --bg-surface-container-lowest: #ffffff;
            --bg-surface-container-high: #eef0f5;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --text-on-background: #0a0e1a;
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

        /* =============================================
           BREADCRUMB, PAGE HEADER, JOB SELECTOR, COLUMNS, CARDS
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
            .breadcrumb-bar { border-radius: var(--radius-xl); flex-direction: row; align-items: center; justify-content: space-between; }
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
        .breadcrumb-view .status-dot {
            width: 0.375rem;
            height: 0.375rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .breadcrumb-meta { font-size: 0.75rem; color: var(--text-on-surface-variant); }

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) { .page-header { flex-direction: row; align-items: center; justify-content: space-between; } }
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
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        .job-selector {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem 1rem;
            box-shadow: var(--shadow-xs);
        }
        .job-selector label { font-weight: 600; font-size: 0.8125rem; color: var(--text-on-surface); }
        .job-selector .select-wrapper {
            position: relative;
            flex: 1;
            min-width: 180px;
        }
        .job-selector select {
            width: 100%;
            padding: 0.5rem 2.5rem 0.5rem 0.875rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
        }
        .job-selector select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .job-selector .badge {
            background: var(--bg-surface-low);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
            border: 1px solid var(--slate-200);
        }

        .pipeline-container {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.875rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            min-height: 420px;
        }
        @media (max-width: 1200px) { .pipeline-container { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) { .pipeline-container { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 480px) { .pipeline-container { grid-template-columns: 1fr; } }

        .column {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            min-width: 200px;
            display: flex;
            flex-direction: column;
            max-height: 72vh;
            box-shadow: var(--shadow-xs);
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
        }
        .column:hover { border-color: var(--slate-300); box-shadow: var(--shadow-sm); }
        .column-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
            transition: background var(--transition-fast);
        }
        .column-header .col-title {
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .column-header .col-title .material-symbols-outlined { font-size: 1rem; }
        .column-header .col-count {
            background: var(--bg-surface);
            padding: 0.1rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 700;
            color: var(--text-on-surface-variant);
            border: 1px solid var(--slate-200);
            min-width: 1.5rem;
            text-align: center;
        }
        .column-body {
            flex: 1;
            overflow-y: auto;
            padding: 0.625rem;
            min-height: 120px;
            scrollbar-width: thin;
            scrollbar-color: var(--slate-200) transparent;
        }
        .column-body::-webkit-scrollbar { width: 4px; }
        .column-body::-webkit-scrollbar-track { background: transparent; }
        .column-body::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .column-body.drag-over {
            background: rgba(79, 70, 229, 0.04);
            border-radius: 0.5rem;
        }

        .applicant-card {
            background: var(--bg-surface);
            border: 1px solid var(--slate-200);
            border-radius: 0.625rem;
            padding: 0.625rem 0.875rem;
            margin-bottom: 0.5rem;
            cursor: grab;
            transition: all var(--transition-smooth);
            box-shadow: var(--shadow-xs);
            position: relative;
        }
        .applicant-card:last-child { margin-bottom: 0; }
        .applicant-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
            border-color: var(--slate-300);
        }
        .applicant-card:active { cursor: grabbing; transform: scale(0.97); }
        .applicant-card .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .applicant-card .card-top .name {
            font-weight: 600;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
        }
        .applicant-card .card-top .email {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.0625rem;
        }
        .applicant-card .match-score {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 700;
            padding: 0.1rem 0.5rem;
            border-radius: var(--radius-full);
            flex-shrink: 0;
            min-width: 2.75rem;
            border: 1px solid transparent;
        }
        .applicant-card .match-score.excellent { background: #d1fae5; color: #059669; border-color: #6ee7b7; }
        .applicant-card .match-score.good { background: #dbeafe; color: #2563eb; border-color: #93c5fd; }
        .applicant-card .match-score.fair { background: #fef3c7; color: #d97706; border-color: #fcd34d; }
        .applicant-card .match-score.low { background: #fecaca; color: #dc2626; border-color: #fca5a5; }

        .applicant-card .applied-date {
            font-size: 0.625rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .applicant-card .applied-date .material-symbols-outlined { font-size: 0.75rem; }
        .applicant-card .skills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 0.375rem;
        }
        .applicant-card .skills .skill-tag {
            font-size: 0.5625rem;
            background: var(--bg-surface-low);
            padding: 0.0625rem 0.5rem;
            border-radius: var(--radius-full);
            color: var(--text-on-surface-variant);
            border: 1px solid var(--slate-200);
            font-weight: 500;
            letter-spacing: 0.01em;
        }
        .applicant-card .card-actions {
            display: flex;
            gap: 0.25rem;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--slate-100);
        }
        .applicant-card .card-actions .btn-sm {
            padding: 0.1875rem 0.5rem;
            font-size: 0.625rem;
            border-radius: 0.375rem;
        }
        .applicant-card .card-actions .btn-sm .material-symbols-outlined { font-size: 0.75rem; }

        .empty-column {
            text-align: center;
            padding: 1.5rem 0.5rem;
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
        }
        .empty-column .material-symbols-outlined {
            font-size: 2rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.25rem;
        }

        .empty-state {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: var(--shadow-xs);
        }
        .empty-state .material-symbols-outlined { font-size: 3.5rem; color: var(--slate-300); }
        .empty-state h3 { margin-top: 1rem; font-size: 1.25rem; font-weight: 700; color: var(--text-on-surface); }
        .empty-state p { color: var(--text-on-surface-variant); font-size: 0.875rem; margin-top: 0.25rem; }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.625rem;
            color: white;
            font-weight: 600;
            font-size: 0.8125rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideUp 0.35s ease-out;
            max-width: 380px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toast .material-symbols-outlined { font-size: 1.25rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Responsive */
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
            .job-selector { flex-direction: column; align-items: stretch; }
            .job-selector select { width: 100%; }
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
            .column { min-width: 160px; }
            .applicant-card .card-top { flex-direction: column; align-items: flex-start; }
            .applicant-card .match-score { align-self: flex-start; }
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Pipeline</span>
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
                    <button class="dropdown-item" onclick="window.location.href='settings.php'">
                        <span class="material-symbols-outlined">settings</span> Settings
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
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">view_kanban</span>
                        <span>Pipeline</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $selectedJob ? htmlspecialchars($selectedJob['title']) : 'No job selected'; ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">
                        Updated <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Recruitment Pipeline</h1>
                        <p>Visualize and manage candidate progression through hiring stages</p>
                    </div>
                    <div>
                        <a href="applicants.php" class="btn btn-outline">
                            <span class="material-symbols-outlined">people</span>
                            View All Applicants
                        </a>
                    </div>
                </div>

                <!-- Job Selector -->
                <div class="job-selector">
                    <label for="jobSelect">Select Position:</label>
                    <div class="select-wrapper">
                        <select id="jobSelect" onchange="window.location.href='?job_id=' + this.value">
                            <option value="0">— Select a job —</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?php echo $job['id']; ?>" <?php echo $jobId == $job['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($job['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($selectedJob): ?>
                        <span class="badge">
                            <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">people</span>
                            <?php echo count($allApplicants ?? []); ?> candidates
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Pipeline -->
                <?php if (!$selectedJob): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">view_kanban</span>
                        <h3>Select a Job to View Pipeline</h3>
                        <p>Choose a position from the dropdown above to see candidates in the hiring pipeline.</p>
                    </div>
                <?php else: ?>
                    <div class="pipeline-container" id="pipelineContainer">
                        <?php foreach ($stages as $stageKey => $stage): ?>
                            <?php $applicants = $applicantsByStage[$stageKey] ?? []; ?>
                            <div class="column" data-stage="<?php echo $stageKey; ?>">
                                <div class="column-header" style="background:<?php echo $stage['bg']; ?>; border-bottom-color:<?php echo $stage['border']; ?>;">
                                    <span class="col-title" style="color:<?php echo $stage['color']; ?>;">
                                        <span class="material-symbols-outlined"><?php echo $stage['icon']; ?></span>
                                        <?php echo $stage['label']; ?>
                                    </span>
                                    <span class="col-count" id="count-<?php echo $stageKey; ?>" style="border-color:<?php echo $stage['border']; ?>; color:<?php echo $stage['color']; ?>;">
                                        <?php echo count($applicants); ?>
                                    </span>
                                </div>
                                <div class="column-body" id="col-<?php echo $stageKey; ?>" 
                                     ondrop="dropHandler(event)" ondragover="dragOverHandler(event)" ondragleave="dragLeaveHandler(event)">
                                    <?php if (empty($applicants)): ?>
                                        <div class="empty-column" id="empty-<?php echo $stageKey; ?>">
                                            <span class="material-symbols-outlined">inbox</span>
                                            No candidates
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($applicants as $app): ?>
                                            <div class="applicant-card" id="card-<?php echo $app['id']; ?>" draggable="true" 
                                                 data-id="<?php echo $app['id']; ?>" 
                                                 data-status="<?php echo $app['status']; ?>"
                                                 ondragstart="dragStartHandler(event)">
                                                <div class="card-top">
                                                    <div>
                                                        <div class="name"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                                        <div class="email"><?php echo htmlspecialchars($app['email']); ?></div>
                                                    </div>
                                                    <?php if ($app['match_score'] !== null): ?>
                                                        <span class="match-score <?php echo strtolower($app['match_level']['label'] ?? 'low'); ?>">
                                                            <?php echo $app['match_score']; ?>%
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="applied-date">
                                                    <span class="material-symbols-outlined">schedule</span>
                                                    Applied <?php echo date('M d, Y', strtotime($app['applied_at'])); ?>
                                                </div>
                                                <?php if (!empty($app['skills'])): ?>
                                                    <div class="skills">
                                                        <?php $skills = array_slice(array_map('trim', explode(',', $app['skills'])), 0, 3); ?>
                                                        <?php foreach ($skills as $skill): ?>
                                                            <?php if (!empty($skill)): ?>
                                                                <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                        <?php if (count(array_filter(explode(',', $app['skills']))) > 3): ?>
                                                            <span class="skill-tag">+<?php echo count(array_filter(explode(',', $app['skills']))) - 3; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="card-actions">
                                                    <button class="btn btn-primary btn-sm" onclick="viewApplicant(<?php echo $app['id']; ?>)" title="View details">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-ghost btn-sm" onclick="moveToStage(<?php echo $app['id']; ?>, '<?php echo $stageKey; ?>')" title="Move to stage">
                                                        <span class="material-symbols-outlined">arrow_forward</span>
                                                    </button>
                                                    <button class="btn btn-ghost btn-sm" onclick="showStageMenu(<?php echo $app['id']; ?>, '<?php echo $stageKey; ?>')" title="More actions">
                                                        <span class="material-symbols-outlined">more_horiz</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- =============================================
    JAVASCRIPT - OPTIMISTIC UI (NO REFRESH!)
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
        // 4. DRAG & DROP
        // =============================================
        let draggedId = null;
        let draggedStatus = null;

        function dragStartHandler(e) {
            const card = e.target.closest('.applicant-card');
            if (!card) return;
            draggedId = card.dataset.id;
            draggedStatus = card.dataset.status;
            e.dataTransfer.setData('text/plain', draggedId);
            e.dataTransfer.effectAllowed = 'move';
            setTimeout(() => card.style.opacity = '0.4', 0);
        }

        function dragOverHandler(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const column = e.target.closest('.column-body');
            if (column) {
                column.classList.add('drag-over');
            }
        }

        function dragLeaveHandler(e) {
            const column = e.target.closest('.column-body');
            if (column) {
                column.classList.remove('drag-over');
            }
        }

        function dropHandler(e) {
            e.preventDefault();
            const column = e.target.closest('.column-body');
            if (!column) return;
            column.classList.remove('drag-over');

            const newStatus = column.id.replace('col-', '');
            const id = e.dataTransfer.getData('text/plain');
            
            if (id && newStatus !== draggedStatus) {
                moveApplicant(id, newStatus);
            } else if (id && newStatus === draggedStatus) {
                showToast('Candidate is already in this stage.', 'info');
            }
            
            draggedId = null;
            draggedStatus = null;
        }

        // =============================================
        // 5. MOVE APPLICANT - OPTIMISTIC UI (NO REFRESH!)
        // =============================================
        function moveApplicant(id, newStatus) {
            const card = document.getElementById('card-' + id);
            if (!card) {
                showToast('Card not found.', 'error');
                return;
            }

            const currentColumn = card.closest('.column-body');
            const oldStatus = currentColumn ? currentColumn.id.replace('col-', '') : null;

            if (!oldStatus || oldStatus === newStatus) {
                showToast('Candidate is already in this stage.', 'info');
                return;
            }

            const targetColumn = document.getElementById('col-' + newStatus);
            if (!targetColumn) {
                showToast('Target stage not found.', 'error');
                return;
            }

            const emptyEl = targetColumn.querySelector('.empty-column');
            if (emptyEl) emptyEl.remove();

            const originalParent = card.parentNode;
            const nextSibling = card.nextSibling;

            targetColumn.appendChild(card);
            card.dataset.status = newStatus;
            card.setAttribute('data-status', newStatus);

            updateColumnCounts(oldStatus, newStatus);

            showToast('Moving candidate...', 'info');

            const formData = new FormData();
            formData.append('action', 'move_stage');
            formData.append('application_id', id);
            formData.append('status', newStatus);
            formData.append('notes', 'Moved via drag & drop');

            const url = window.location.pathname + '?job_id=' + <?php echo $jobId; ?>;

            fetch(url, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                } else {
                    showToast(data.error || 'Failed to move candidate. Reverting...', 'error');
                    revertCard(id, oldStatus, newStatus, originalParent, nextSibling);
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showToast('Error connecting to server. Reverting...', 'error');
                revertCard(id, oldStatus, newStatus, originalParent, nextSibling);
            });
        }

        // =============================================
        // 6. REVERT CARD
        // =============================================
        function revertCard(id, oldStatus, newStatus, originalParent, nextSibling) {
            const card = document.getElementById('card-' + id);
            if (!card) return;

            card.remove();

            if (originalParent && originalParent.id === 'col-' + oldStatus) {
                if (nextSibling) {
                    originalParent.insertBefore(card, nextSibling);
                } else {
                    originalParent.appendChild(card);
                }
                card.dataset.status = oldStatus;
                card.setAttribute('data-status', oldStatus);
            } else {
                const targetColumn = document.getElementById('col-' + oldStatus);
                if (targetColumn) {
                    const emptyEl = targetColumn.querySelector('.empty-column');
                    if (emptyEl) emptyEl.remove();
                    
                    targetColumn.appendChild(card);
                    card.dataset.status = oldStatus;
                    card.setAttribute('data-status', oldStatus);
                }
            }

            updateColumnCounts(newStatus, oldStatus);
        }

        // =============================================
        // 7. UPDATE COLUMN COUNTS
        // =============================================
        function updateColumnCounts(fromStatus, toStatus) {
            if (fromStatus) {
                const fromCol = document.getElementById('col-' + fromStatus);
                const fromCount = fromCol ? fromCol.querySelectorAll('.applicant-card').length : 0;
                const fromCountEl = document.getElementById('count-' + fromStatus);
                if (fromCountEl) {
                    fromCountEl.textContent = fromCount;
                }
                
                if (fromCount === 0 && fromCol) {
                    let emptyEl = document.getElementById('empty-' + fromStatus);
                    if (!emptyEl) {
                        emptyEl = document.createElement('div');
                        emptyEl.className = 'empty-column';
                        emptyEl.id = 'empty-' + fromStatus;
                        emptyEl.innerHTML = '<span class="material-symbols-outlined">inbox</span> No candidates';
                        fromCol.appendChild(emptyEl);
                    }
                } else {
                    const existingEmpty = document.getElementById('empty-' + fromStatus);
                    if (existingEmpty) existingEmpty.remove();
                }
            }

            if (toStatus) {
                const toCol = document.getElementById('col-' + toStatus);
                const toCount = toCol ? toCol.querySelectorAll('.applicant-card').length : 0;
                const toCountEl = document.getElementById('count-' + toStatus);
                if (toCountEl) {
                    toCountEl.textContent = toCount;
                }
                const existingEmpty = document.getElementById('empty-' + toStatus);
                if (existingEmpty && toCount > 0) {
                    existingEmpty.remove();
                }
            }
        }

        // =============================================
        // 8. MOVE TO STAGE (Button click)
        // =============================================
        function moveToStage(id, currentStatus) {
            const stages = <?php echo json_encode(array_keys($stages)); ?>;
            const stageLabels = <?php echo json_encode(array_column($stages, 'label')); ?>;
            
            let options = '';
            stages.forEach((stage, index) => {
                if (stage !== currentStatus) {
                    options += `${index+1}. ${stageLabels[index]}\n`;
                }
            });
            
            if (!options) {
                showToast('No other stages available.', 'info');
                return;
            }
            
            const choice = prompt(
                'Move candidate to stage:\n\n' + options +
                '\nEnter the number (1-' + (stages.length - 1) + '):'
            );
            
            if (choice) {
                const num = parseInt(choice.trim());
                if (!isNaN(num) && num > 0 && num <= stages.length) {
                    const stage = stages[num - 1];
                    if (stage !== currentStatus) {
                        moveApplicant(id, stage);
                    } else {
                        showToast('Candidate is already in this stage.', 'info');
                    }
                } else {
                    showToast('Invalid selection. Please enter a valid number.', 'error');
                }
            }
        }

        function showStageMenu(id, currentStatus) {
            moveToStage(id, currentStatus);
        }

        // =============================================
        // 9. VIEW APPLICANT
        // =============================================
        function viewApplicant(id) {
            window.location.href = 'applicants.php?view=' + id;
        }

        // =============================================
        // 10. TOAST SYSTEM
        // =============================================
        function showToast(message, type = 'info') {
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            
            const iconMap = {
                'success': 'check_circle',
                'error': 'error',
                'info': 'info'
            };
            toast.innerHTML = `<span class="material-symbols-outlined">${iconMap[type] || 'info'}</span> ${message}`;
            
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        // =============================================
        // 11. RESPONSIVE HANDLING
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
        // 12. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        console.log('🔷 ISMERS Pipeline loaded successfully (No Refresh!).');
    </script>

</body>
</html>