<?php
session_start();
require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has HR role
if ($_SESSION['role'] !== 'hr_manager' && $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

// =============================================
// ERROR REPORTING - DISABLE WARNINGS
// =============================================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

require_once '../../app/config.php';
initSessionTimeout();
require_once '../../app/ai/AiService.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has HR role
if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter', 'admin'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';
$roleLabel = $role === 'hr_manager' ? 'HR Manager' : 'Recruiter';

// =============================================
// AI SERVICE INITIALIZATION
// =============================================
$aiService = new AiService();

// =============================================
// AI HELPER FUNCTIONS
// =============================================

/**
 * Get fallback insights when AI fails
 */
function getFallbackInsights($context) {
    $recommendations = [];
    
    if (($context['pending_applications'] ?? 0) > 5) {
        $recommendations[] = "📋 Review " . ($context['pending_applications'] ?? 0) . " pending applications";
    }
    if (($context['active_jobs'] ?? 0) < 3 && ($context['total_jobs'] ?? 0) > 0) {
        $recommendations[] = "📌 Post more jobs to increase your talent pipeline";
    }
    if (($context['total_applications'] ?? 0) > 0 && ($context['upcoming_interviews'] ?? 0) == 0) {
        $recommendations[] = "📅 Schedule interviews for qualified candidates";
    }
    if (empty($recommendations)) {
        $recommendations = [
            "📊 Review your recruitment metrics weekly",
            "🎯 Set up automated follow-ups for pending applications",
            "💡 Consider expanding your job boards for better reach"
        ];
    }
    
    return [
        'summary' => "Your recruitment shows " . ($context['total_applications'] ?? 0) . " applications across " . ($context['active_jobs'] ?? 0) . " active jobs.",
        'recommendations' => $recommendations,
        'alerts' => ($context['pending_applications'] ?? 0) > 5 
            ? ["⚠️ " . ($context['pending_applications'] ?? 0) . " applications pending review"] 
            : ["✅ Recruitment is on track"],
        'trends' => [
            "📈 " . ($context['total_applications'] ?? 0) . " total applications",
            "📊 " . (($context['active_jobs'] ?? 0) > 0 
                ? round(($context['total_applications'] ?? 0) / ($context['active_jobs'] ?? 0), 1) 
                : 0) . " applications per active job"
        ],
        'provider' => 'fallback'
    ];
}

/**
 * Get AI-powered dashboard insights
 */
function getAIInsights($stats, $recentApplications, $activeJobs) {
    global $aiService;
    
    // Build context with safe defaults
    $context = [
        'total_jobs' => $stats['total_jobs'] ?? 0,
        'active_jobs' => $stats['active_jobs'] ?? 0,
        'total_applications' => $stats['total_applications'] ?? 0,
        'pending_applications' => $stats['pending_applications'] ?? 0,
        'total_applicants' => $stats['total_applicants'] ?? 0,
        'upcoming_interviews' => $stats['upcoming_interviews'] ?? 0,
    ];
    
    // Try to get AI insights
    try {
        $jobData = [
            'title' => 'HR Analytics Dashboard',
            'description' => "Recruitment Metrics: " . ($context['total_jobs'] ?? 0) . " total jobs, " . ($context['active_jobs'] ?? 0) . " active jobs, " . ($context['total_applications'] ?? 0) . " applications, " . ($context['pending_applications'] ?? 0) . " pending review, " . ($context['total_applicants'] ?? 0) . " unique applicants, " . ($context['upcoming_interviews'] ?? 0) . " upcoming interviews.",
            'skills_required' => 'HR Analytics, Recruitment, Data Analysis, Dashboard Reporting',
            'experience_level' => 'Mid'
        ];
        
        $result = @$aiService->optimizeJobDescription($jobData);
        
        if ($result && !isset($result['error'])) {
            $recommendations = $result['suggested_skills'] ?? [
                'Review pending applications',
                'Post more jobs to attract talent',
                'Schedule interviews for qualified candidates'
            ];
            
            $summary = "Your recruitment shows " . ($context['total_applications'] ?? 0) . " applications across " . ($context['active_jobs'] ?? 0) . " active jobs.";
            if (($context['pending_applications'] ?? 0) > 0) {
                $summary .= " You have " . ($context['pending_applications'] ?? 0) . " applications pending review.";
            }
            
            return [
                'summary' => $summary,
                'recommendations' => $recommendations,
                'alerts' => ($context['pending_applications'] ?? 0) > 5 
                    ? ["⚠️ " . ($context['pending_applications'] ?? 0) . " applications pending review"] 
                    : ["✅ Recruitment is on track"],
                'trends' => [
                    "📈 " . ($context['total_applications'] ?? 0) . " total applications",
                    "📊 " . (($context['active_jobs'] ?? 0) > 0 
                        ? round(($context['total_applications'] ?? 0) / ($context['active_jobs'] ?? 0), 1) 
                        : 0) . " applications per active job"
                ],
                'provider' => 'groq'
            ];
        }
    } catch (Exception $e) {
        @error_log("AI Error: " . $e->getMessage());
    }
    
    // Fallback insights
    return getFallbackInsights($context);
}

// =============================================
// GET HR STATS - REMOVED DUPLICATE FUNCTION
// USING THE ONE FROM CONFIG.PHP INSTEAD
// =============================================

// Get stats - USE THE CONFIG.PHP FUNCTION
$stats = getHRStats($userId);

// Get recent applications safely
$recentApplications = [];
try {
    $sql = "SELECT a.*, u.first_name, u.last_name, u.email, 
                   jo.title as job_title, c.company_name
            FROM applications a
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            ORDER BY a.applied_at DESC 
            LIMIT 10";
    $recentApplications = @getRecords($sql);
    if (!is_array($recentApplications)) {
        $recentApplications = [];
    }
} catch (Exception $e) {
    $recentApplications = [];
}

// Get active jobs safely
$activeJobs = [];
try {
    $sql = "SELECT jo.*, c.company_name, 
            (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id) as application_count
            FROM job_orders jo
            JOIN clients c ON jo.client_id = c.id
            WHERE jo.status IN ('open', 'ongoing')
            ORDER BY jo.created_at DESC
            LIMIT 5";
    $activeJobs = @getRecords($sql);
    if (!is_array($activeJobs)) {
        $activeJobs = [];
    }
} catch (Exception $e) {
    $activeJobs = [];
}

// =============================================
// GET AI INSIGHTS
// =============================================
$aiInsights = getAIInsights($stats, $recentApplications, $activeJobs);

// Determine AI provider
$aiProvider = $aiInsights['provider'] ?? 'unknown';

// Get user profile data
$userProfile = getUserProfileData($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>HR Dashboard - AI Powered</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ========================================================================== */
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

        /* =============================================
           AI INSIGHTS CARD STYLES
           ============================================= */
        .ai-insights-card {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-2xl);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .ai-insights-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: rgba(79, 70, 229, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .ai-insights-card .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.625rem;
            border-radius: 12px;
            font-size: 0.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 0.5rem;
        }

        .ai-insights-card .ai-badge .material-symbols-outlined {
            font-size: 0.75rem;
        }

        .ai-insights-card .insight-summary {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-on-surface);
            margin-bottom: 0.75rem;
            padding-right: 1rem;
        }

        .ai-insights-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .ai-insights-grid {
                grid-template-columns: 1fr;
            }
        }

        .ai-insight-item {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(8px);
            border-radius: var(--radius-lg);
            padding: 0.75rem 1rem;
            border: 1px solid rgba(196, 181, 253, 0.3);
        }

        .ai-insight-item .insight-icon {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .ai-insight-item .insight-label {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.125rem;
        }

        .ai-insight-item .insight-text {
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            line-height: 1.4;
        }

        .ai-insight-item .insight-text .highlight {
            font-weight: 700;
            color: var(--primary);
        }

        .ai-insight-item .insight-text .alert {
            color: #dc2626;
        }

        .ai-insight-item .insight-text .success {
            color: #059669;
        }

        .ai-insight-item .insight-text .warning {
            color: #d97706;
        }

        .ai-provider-tag {
            font-size: 0.55rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.5rem;
            text-align: right;
            opacity: 0.6;
        }

        .ai-provider-tag .provider-name {
            font-weight: 600;
            text-transform: uppercase;
        }
        .ai-provider-tag .provider-name.groq { color: #7c3aed; }
        .ai-provider-tag .provider-name.fallback { color: #6b7280; }

        /* =============================================
           REST OF STYLES
           ============================================= */
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
            background: var(--slate-100);
            color: var(--primary);
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .sidebar-brand-icon .material-symbols-outlined { font-size: 1.5rem; }
        .sidebar-brand-text { font-size: 0.875rem; font-weight: 600; color: var(--slate-900); }
        .sidebar-brand-category { font-size: 0.75rem; color: var(--slate-500); margin-top: 0.25rem; }

        .sidebar-nav { flex: 1; overflow-y: auto; padding: 1.5rem 1.25rem; }
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
        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            border-radius: 50px;
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

        .top-header-left { display: flex; align-items: center; gap: 0.75rem; }
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

        .top-header-left .separator { color: var(--outline-variant); font-weight: 300; user-select: none; }

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
        .profile-dropdown-menu .dropdown-header { padding: 0.5rem 0.875rem 0.25rem; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-on-surface-variant); }
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

        .main-scroll { flex: 1; overflow-y: auto; padding: 1.5rem 2rem; }
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
            .breadcrumb-bar { border-radius: var(--radius-2xl); flex-direction: row; align-items: center; justify-content: space-between; }
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
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
        }

        .page-header h1 { font-size: 1.875rem; font-weight: 700; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.25rem; }

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

        .btn-primary .material-symbols-outlined { font-size: 1.125rem; }

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

        .btn-outline:hover { background: var(--primary); color: var(--on-primary); }
        .btn-outline .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm { padding: 0.375rem 0.875rem; font-size: 0.75rem; }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 1280px) { .stats-grid { grid-template-columns: repeat(6, 1fr); } }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            transition: all var(--transition-fast);
        }

        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--slate-500);
        }

        .stat-card .stat-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card .stat-icon.blue { background: #eff6ff; color: #2563eb; }
        .stat-card .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }
        .stat-card .stat-icon.orange { background: #ffedd5; color: #ea580c; }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--slate-900);
            line-height: 1;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        @media (min-width: 1024px) {
            .dashboard-grid { grid-template-columns: 2fr 1fr; }
        }

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
        }

        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .card-header a {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            transition: color var(--transition-fast);
        }

        .card-header a:hover { color: var(--on-primary-fixed-variant); }
        .card-header a .material-symbols-outlined { font-size: 1rem; }

        .card-body { padding: 0.75rem 1.5rem; }

        .app-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--slate-200);
        }

        .app-item:last-child { border-bottom: none; }

        .app-item .app-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .app-item .app-info .app-avatar {
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

        .app-item .app-info .app-details h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .app-item .app-info .app-details p {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-shortlisted { background: #dbeafe; color: #2563eb; }
        .badge-interviewed { background: #e0e7ff; color: #4f46e5; }
        .badge-hired { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-withdrawn { background: #f3f4f6; color: #6b7280; }

        .job-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--slate-200);
        }

        .job-item:last-child { border-bottom: none; }

        .job-item .job-info h4 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .job-item .job-info p {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
        }

        .empty-state .empty-icon { margin-bottom: 0.75rem; opacity: 0.3; }
        .empty-state h4 { font-size: 1.125rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.875rem; color: var(--text-on-surface-variant); }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-xl); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
            .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: inline; }
        }

        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-xl); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .dashboard-sidebar.collapsed { width: var(--sidebar-width); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: none; }
            .app-item { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .job-item { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .job-item .btn { width: 100%; justify-content: center; }
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
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.75rem 1rem; }
            .page-header h1 { font-size: 1.5rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.5rem; }
            .card-header { padding: 0.75rem 1rem; }
            .card-body { padding: 0.5rem 1rem; }
        }

        .main-scroll::-webkit-scrollbar { width: 6px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 3px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-500); }

        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: #4f46e5;
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            min-width: 1.25rem;
            min-height: 1.25rem;
            border-radius: 50px;
            transition: opacity 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            line-height: 1;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
        }

        .dashboard-sidebar.collapsed .sidebar-main-link .nav-badge {
            opacity: 0;
            width: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
            min-width: 0;
            min-height: 0;
        }
        .header-logo {
    height: 2rem;
    width: auto;
    max-height: 2.5rem;
    object-fit: contain;
    border-radius: 0.375rem;
}

/* For mobile responsiveness */
@media (max-width: 480px) {
    .header-logo {
        height: 1.5rem;
    }
}
.sidebar-logo-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    flex-shrink: 0;
}

.sidebar-logo {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 0.75rem;
    transition: all 0.3s ease;
}

.dashboard-sidebar.collapsed .sidebar-logo {
    width: 2.5rem;
    height: 2.5rem;
}
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <div class="sidebar-logo-wrapper">
            <img src="logo.png" alt="ISMERS" class="sidebar-logo">
        </div>
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
            <?php if ($stats['pending_review'] > 0): ?>
                <span class="nav-badge" style="background:#d97706;"><?php echo $stats['pending_review']; ?></span>
            <?php endif; ?>
        </a>
        <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">people</span>
            <span class="nav-text">Applicants</span>
            <span class="nav-badge"><?php echo $stats['pending_applications']; ?></span>
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

    <!-- ===== TOP HEADER ===== -->
   <!-- ===== TOP HEADER ===== -->
<header class="top-header">
    <div class="top-header-left">
        <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
            <span class="material-symbols-outlined" id="sidebarToggleIcon">chevron_left</span>
        </button>
        <!-- ✅ Logo added here -->
        <img src="logo.png" alt="ISMERS" class="header-logo">
        <span class="separator">|</span>
        <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
            <?php 
                $pageTitle = basename($_SERVER['PHP_SELF'], '.php');
                echo ucwords(str_replace('_', ' ', $pageTitle));
            ?>
        </span>
    </div>
        <!-- Profile Dropdown -->
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

    <!-- Main Scrollable Area -->
    <main class="main-scroll" id="mainScroll">
        <div class="container">

            <!-- Breadcrumb -->
            <div class="breadcrumb-bar">
                <div class="breadcrumb-view">
                    <span class="material-symbols-outlined">dashboard</span>
                    <span>Dashboard</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>
                <span class="ai-provider-tag" style="font-size:0.6rem; color:var(--text-on-surface-variant); opacity:0.6;">
                    AI: <span class="provider-name <?php echo $aiProvider; ?>"><?php echo ucfirst($aiProvider); ?></span>
                </span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Welcome back, <?php echo htmlspecialchars($firstName ?: 'HR'); ?></h1>
                    <p>Here's an overview of your recruitment activity with AI insights</p>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <a href="post_job.php" class="btn-primary">
                        <span class="material-symbols-outlined">add</span>
                        Post New Job
                    </a>
                    <a href="applicants.php" class="btn-outline">
                        View All Applicants
                    </a>
                </div>
            </div>

            <!-- ============================================= -->
            <!-- AI INSIGHTS CARD -->
            <!-- ============================================= -->
            <div class="ai-insights-card">
                <div class="ai-badge">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    AI Insights
                    <span style="font-size:0.5rem; opacity:0.7; margin-left:0.25rem;">
                        <?php echo ucfirst($aiProvider); ?>
                    </span>
                </div>
                
                <div class="insight-summary">
                    <?php echo htmlspecialchars($aiInsights['summary'] ?? 'Your recruitment pipeline is active.'); ?>
                </div>

                <div class="ai-insights-grid">
                    <!-- Recommendations -->
                    <div class="ai-insight-item">
                        <div class="insight-icon">💡</div>
                        <div class="insight-label">Recommendations</div>
                        <div class="insight-text">
                            <?php 
                            $recommendations = $aiInsights['recommendations'] ?? ['Review pending applications', 'Post more jobs'];
                            foreach (array_slice($recommendations, 0, 3) as $rec):
                            ?>
                                <div style="padding:0.125rem 0; font-size:0.75rem;">• <?php echo htmlspecialchars($rec); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Alerts -->
                    <div class="ai-insight-item">
                        <div class="insight-icon">🚨</div>
                        <div class="insight-label">Alerts</div>
                        <div class="insight-text">
                            <?php 
                            $alerts = $aiInsights['alerts'] ?? ['No critical alerts'];
                            if (empty($alerts)) $alerts = ['No critical alerts'];
                            foreach (array_slice($alerts, 0, 3) as $alert):
                            ?>
                                <div style="padding:0.125rem 0; font-size:0.75rem;">• <?php echo htmlspecialchars($alert); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Trends -->
                    <div class="ai-insight-item">
                        <div class="insight-icon">📈</div>
                        <div class="insight-label">Trends</div>
                        <div class="insight-text">
                            <?php 
                            $trends = $aiInsights['trends'] ?? ['Monitor application flow'];
                            if (empty($trends)) $trends = ['Monitor application flow'];
                            foreach (array_slice($trends, 0, 3) as $trend):
                            ?>
                                <div style="padding:0.125rem 0; font-size:0.75rem;">• <?php echo htmlspecialchars($trend); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-label">Total Jobs</span>
                        <div class="stat-icon blue">
                            <span class="material-symbols-outlined">work</span>
                        </div>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_jobs']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-label">Active Jobs</span>
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                    </div>
                    <div class="stat-number"><?php echo $stats['active_jobs']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-label">Applications</span>
                        <div class="stat-icon purple">
                            <span class="material-symbols-outlined">mail</span>
                        </div>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_applications']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-label">Pending Review</span>
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">hourglass_empty</span>
                        </div>
                    </div>
                    <div class="stat-number"><?php echo $stats['pending_applications']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-label">Total Applicants</span>
                        <div class="stat-icon orange">
                            <span class="material-symbols-outlined">people</span>
                        </div>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_applicants']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-label">Upcoming Interviews</span>
                        <div class="stat-icon red">
                            <span class="material-symbols-outlined">event</span>
                        </div>
                    </div>
                    <div class="stat-number"><?php echo $stats['upcoming_interviews']; ?></div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">

                <!-- Recent Applications -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Applications</h3>
                        <a href="applicants.php">
                            View All <span class="material-symbols-outlined">arrow_forward</span>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentApplications)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <span class="material-symbols-outlined" style="font-size:3rem;">inbox</span>
                                </div>
                                <h4>No Applications Yet</h4>
                                <p>Applications will appear here once candidates apply.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentApplications as $app): ?>
                                <div class="app-item">
                                    <div class="app-info">
                                        <span class="app-avatar">
                                            <?php echo strtoupper(substr($app['first_name'] ?? 'A', 0, 1)); ?>
                                        </span>
                                        <div class="app-details">
                                            <h4><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></h4>
                                            <p><?php echo htmlspecialchars($app['job_title'] ?? 'Position'); ?> • <?php echo htmlspecialchars($app['company_name'] ?? 'Company'); ?></p>
                                            <p style="font-size:0.65rem; color:var(--text-on-surface-variant);">
                                                Applied <?php echo date('M d, Y', strtotime($app['applied_at'] ?? 'now')); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="badge badge-<?php echo $app['status'] ?? 'pending'; ?>">
                                        <?php echo ucfirst($app['status'] ?? 'Pending'); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Active Jobs -->
                <div class="card">
                    <div class="card-header">
                        <h3>Active Jobs</h3>
                        <a href="jobs.php">
                            View All <span class="material-symbols-outlined">arrow_forward</span>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($activeJobs)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <span class="material-symbols-outlined" style="font-size:3rem;">work_off</span>
                                </div>
                                <h4>No Active Jobs</h4>
                                <p>Post your first job to start receiving applications.</p>
                                <br>
                                <a href="post_job.php" class="btn-primary" style="display:inline-flex;">Post Job</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($activeJobs as $job): ?>
                                <div class="job-item">
                                    <div class="job-info">
                                        <h4><?php echo htmlspecialchars($job['title']); ?></h4>
                                        <p><?php echo htmlspecialchars($job['company_name']); ?> • <?php echo $job['application_count'] ?? 0; ?> applicants</p>
                                    </div>
                                    <a href="job_view.php?id=<?php echo $job['id']; ?>" class="btn-outline btn-sm">
                                        View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- =============================================
JAVASCRIPT
============================================= -->
<script>
(function() {
    'use strict';

    // =============================================
    // 1. SIDEBAR TOGGLE (Desktop Collapse)
    // =============================================
    const sidebar = document.getElementById('appSidebar');
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebarBackdrop = document.querySelector('.sidebar-backdrop') || document.createElement('div');
    
    // Create backdrop if doesn't exist
    if (!document.querySelector('.sidebar-backdrop')) {
        sidebarBackdrop.className = 'sidebar-backdrop';
        sidebarBackdrop.id = 'sidebarBackdrop';
        document.body.prepend(sidebarBackdrop);
    }

    const savedState = localStorage.getItem('sidebarCollapsed');
    const isDesktop = window.innerWidth >= 768;

    if (savedState === 'true' && isDesktop) {
        sidebar.classList.add('collapsed');
    }

    sidebarToggleBtn.addEventListener('click', function() {
        if (window.innerWidth < 768) return;
        sidebar.classList.toggle('collapsed');
        const isCollapsed = sidebar.classList.contains('collapsed');
        const icon = this.querySelector('.material-symbols-outlined');
        if (icon) {
            icon.textContent = isCollapsed ? 'chevron_right' : 'chevron_left';
        }
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

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', openMobileSidebar);
    }
    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);
    }

    document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth < 768) {
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

            if (width >= 768) {
                closeMobileSidebar();
                sidebar.classList.remove('mobile-open', 'mobile-hidden');
                const saved = localStorage.getItem('sidebarCollapsed');
                if (saved === 'true') {
                    sidebar.classList.add('collapsed');
                } else {
                    sidebar.classList.remove('collapsed');
                }
            } else {
                sidebar.classList.add('mobile-hidden');
                sidebar.classList.remove('collapsed');
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
    // 5. INITIAL STATE
    // =============================================
    if (window.innerWidth < 768) {
        sidebar.classList.add('mobile-hidden');
    }

    console.log('🤖 AI-Powered HR Dashboard loaded successfully!');
    console.log('📊 AI Provider: <?php echo $aiProvider; ?>');
})();
</script>

</body>
</html>
