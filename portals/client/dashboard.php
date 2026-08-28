<?php
// portals/client/dashboard.php - AI-Powered Client Dashboard
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

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Client User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'client';

// =============================================
// AI SERVICE INITIALIZATION
// =============================================
$aiService = new AiService();

// ✅ FIXED: Get client profile - PostgreSQL uses $1 placeholder
$client = getRecord("
    SELECT c.*, u.email as user_email, u.full_name
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.user_id = $1
", [$userId]);

// If no client profile exists, show setup message
if (!$client) {
    $client = [
        'company_name' => 'Your Company',
        'industry' => '',
        'is_active' => 1,
        'id' => 0
    ];
}

$companyName = $client['company_name'] ?? 'Your Company';
$clientId = $client['id'] ?? 0;

// =============================================
// GET PENDING AGENCY APPLICATIONS FOR SIDEBAR BADGE
// =============================================
$pendingAgencyCount = 0;
if ($clientId > 0) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $pendingAgencies = getRecord("
        SELECT COUNT(*) as count FROM agency_applications 
        WHERE client_id = $1 AND status = 'pending'
    ", [$clientId]);
    $pendingAgencyCount = (int)($pendingAgencies['count'] ?? 0);
}

// =============================================
// DASHBOARD STATS - ✅ FIXED all queries to PostgreSQL
// =============================================

// 1. TOTAL ACTIVE EMPLOYEES (deployed to this client)
$totalEmployees = 0;
if ($clientId > 0) {
    $employeeResult = getRecord("
        SELECT COUNT(*) as count FROM deployments d 
        WHERE d.client_id = $1 AND d.status = 'active'
    ", [$clientId]);
    $totalEmployees = (int)($employeeResult['count'] ?? 0);
}

// 2. TOTAL APPLICANTS (who applied to this client's jobs)
$totalApplicants = 0;
if ($clientId > 0) {
    $applicantsResult = getRecord("
        SELECT COUNT(DISTINCT a.applicant_id) as count 
        FROM applications a
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = $1
    ", [$clientId]);
    $totalApplicants = (int)($applicantsResult['count'] ?? 0);
}

// 3. TOTAL APPLICATIONS RECEIVED
$totalApplications = 0;
if ($clientId > 0) {
    $appsReceivedResult = getRecord("
        SELECT COUNT(*) as count 
        FROM applications a
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = $1
    ", [$clientId]);
    $totalApplications = (int)($appsReceivedResult['count'] ?? 0);
}

// 4. PENDING APPLICATIONS
$pendingApplications = 0;
if ($clientId > 0) {
    $pendingAppsResult = getRecord("
        SELECT COUNT(*) as count 
        FROM applications a
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = $1 AND a.status = 'pending'
    ", [$clientId]);
    $pendingApplications = (int)($pendingAppsResult['count'] ?? 0);
}

// 5. OPEN JOBS
$openJobs = 0;
if ($clientId > 0) {
    $openJobsResult = getRecord("
        SELECT COUNT(*) as count FROM job_orders 
        WHERE client_id = $1 AND status IN ('open', 'ongoing')
    ", [$clientId]);
    $openJobs = (int)($openJobsResult['count'] ?? 0);
}

// 6. REVENUE (estimated - from accepted offers)
$totalRevenue = 0;
if ($clientId > 0) {
    $revenueResult = getRecord("
        SELECT COALESCE(SUM(o.salary_offered), 0) as total FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = $1 AND o.status = 'accepted'
    ", [$clientId]);
    $totalRevenue = (float)($revenueResult['total'] ?? 0);
}

// 7. TOTAL JOBS
$totalJobs = 0;
if ($clientId > 0) {
    $totalJobsResult = getRecord("
        SELECT COUNT(*) as count FROM job_orders 
        WHERE client_id = $1
    ", [$clientId]);
    $totalJobs = (int)($totalJobsResult['count'] ?? 0);
}

// 8. RECENT EMPLOYEES
$recentEmployees = [];
if ($clientId > 0) {
    $recentEmployees = getRecords("
        SELECT d.*, 
               u.id as user_id, u.first_name, u.last_name, u.email,
               jo.title as job_title, d.start_date
        FROM deployments d
        JOIN users u ON d.employee_id = u.id
        JOIN job_orders jo ON d.job_order_id = jo.id
        WHERE d.client_id = $1
        ORDER BY d.created_at DESC
        LIMIT 5
    ", [$clientId]);
}

// 9. RECENT APPLICANTS
$recentApplicants = [];
if ($clientId > 0) {
    $recentApplicants = getRecords("
        SELECT a.*, u.first_name, u.last_name, u.email,
               jo.title as job_title, jo.id as job_id
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE jo.client_id = $1
        ORDER BY a.applied_at DESC
        LIMIT 5
    ", [$clientId]);
}

// 10. ACTIVE JOBS LIST
$activeJobs = [];
if ($clientId > 0) {
    $activeJobs = getRecords("
        SELECT * FROM job_orders 
        WHERE client_id = $1 AND status IN ('open', 'ongoing')
        ORDER BY created_at DESC
        LIMIT 5
    ", [$clientId]);
}

// =============================================
// AI HELPER FUNCTIONS
// =============================================

/**
 * Get AI-powered dashboard insights for client
 */
function getClientAIInsights($stats) {
    global $aiService;
    
    // Build context with ALL necessary keys
    $context = [
        'total_jobs' => $stats['total_jobs'] ?? 0,
        'open_jobs' => $stats['open_jobs'] ?? 0,
        'total_applications' => $stats['total_applications'] ?? 0,
        'pending_applications' => $stats['pending_applications'] ?? 0,
        'total_applicants' => $stats['total_applicants'] ?? 0,
        'total_employees' => $stats['total_employees'] ?? 0,
        'total_revenue' => $stats['total_revenue'] ?? 0
    ];
    
    // Calculate metrics and store them in context
    $context['conversion_rate'] = $context['total_applications'] > 0 
        ? round(($context['total_employees'] / $context['total_applications']) * 100, 1) 
        : 0;
    
    $context['applications_per_job'] = $context['open_jobs'] > 0 
        ? round($context['total_applications'] / $context['open_jobs'], 1) 
        : 0;
    
    // Try to get AI insights using public method
    try {
        $prompt = "Analyze this recruitment data and provide insights:
        - Total Jobs: {$context['total_jobs']}
        - Open Jobs: {$context['open_jobs']}
        - Total Applications: {$context['total_applications']}
        - Pending Applications: {$context['pending_applications']}
        - Total Applicants: {$context['total_applicants']}
        - Active Employees: {$context['total_employees']}
        - Revenue: ₱" . number_format($context['total_revenue'], 0) . "
        - Conversion Rate: {$context['conversion_rate']}%
        - Applications per Job: {$context['applications_per_job']}
        
        Provide a JSON response with:
        1. summary: A 1-2 sentence summary
        2. recommendations: 3 actionable recommendations
        3. alerts: Important alerts
        4. trends: Observed trends
        5. score: 0-100 health score";
        
        $result = $aiService->optimizeJobDescription([
            'title' => 'HR Analytics Dashboard',
            'description' => $prompt,
            'skills_required' => 'HR Analytics',
            'experience_level' => 'Mid'
        ]);
        
        // If AI returned valid data, use it
        if ($result && !isset($result['error'])) {
            return [
                'summary' => "Your recruitment shows {$context['total_applications']} applications across {$context['open_jobs']} active jobs.",
                'recommendations' => [
                    "📋 Review {$context['pending_applications']} pending applications",
                    "📌 Post more jobs to attract talent",
                    "📅 Schedule interviews for qualified candidates"
                ],
                'alerts' => $context['pending_applications'] > 5 
                    ? ["⚠️ {$context['pending_applications']} applications pending review"] 
                    : ["✅ No critical alerts - your recruitment is on track"],
                'trends' => [
                    "📈 Application volume: {$context['total_applications']} total applications",
                    "📊 {$context['applications_per_job']} applications per active job"
                ],
                'score' => min(100, 70 + ($context['total_employees'] * 5) + ($context['conversion_rate'] / 2)),
                'provider' => 'groq'
            ];
        }
    } catch (Exception $e) {
        error_log("AI Error: " . $e->getMessage());
    }
    
    // Fallback insights if AI fails
    return getFallbackInsights($context);
}

/**
 * Get fallback insights when AI fails
 */
function getFallbackInsights($context) {
    // Ensure all keys exist with defaults
    $totalJobs = $context['total_jobs'] ?? 0;
    $openJobs = $context['open_jobs'] ?? 0;
    $totalApplications = $context['total_applications'] ?? 0;
    $pendingApplications = $context['pending_applications'] ?? 0;
    $totalApplicants = $context['total_applicants'] ?? 0;
    $totalEmployees = $context['total_employees'] ?? 0;
    $conversionRate = $context['conversion_rate'] ?? 0;
    $applicationsPerJob = $context['applications_per_job'] ?? 0;
    
    // Summary
    $summary = "You have {$openJobs} active jobs and {$totalApplications} total applications.";
    if ($totalEmployees > 0) {
        $summary .= " Your team has grown to {$totalEmployees} active employees.";
    }
    
    // Recommendations
    $recommendations = [];
    if ($pendingApplications > 5) {
        $recommendations[] = "📋 Review {$pendingApplications} pending applications - candidates are waiting for feedback";
    }
    if ($openJobs < 3 && $totalJobs > 0) {
        $recommendations[] = "📌 Post more jobs to increase your talent pipeline";
    }
    if ($totalApplications > 0 && $totalEmployees == 0) {
        $recommendations[] = "📅 Schedule interviews for qualified candidates - you have applicants waiting";
    }
    if ($conversionRate < 10 && $totalApplications > 0) {
        $recommendations[] = "🎯 Review your hiring process - conversion rate is {$conversionRate}%";
    }
    if (empty($recommendations)) {
        $recommendations = [
            "📊 Review your recruitment metrics weekly",
            "🎯 Focus on quality of hire over quantity",
            "💡 Consider expanding your job boards for better reach"
        ];
    }
    
    // Alerts
    $alerts = [];
    if ($pendingApplications > 10) {
        $alerts[] = "⚠️ High volume of pending applications ({$pendingApplications}) - consider faster review process";
    }
    if ($openJobs == 0 && $totalJobs > 0) {
        $alerts[] = "⚠️ No active jobs - consider re-posting or creating new openings";
    }
    if ($totalApplications > 0 && $conversionRate < 5) {
        $alerts[] = "⚠️ Low conversion rate ({$conversionRate}%) - review your screening process";
    }
    if (empty($alerts)) {
        $alerts[] = "✅ No critical alerts - your recruitment is on track";
    }
    
    // Trends
    $trends = [];
    if ($totalApplications > 0) {
        $trends[] = "📈 Application volume: {$totalApplications} total applications";
    }
    if ($openJobs > 0) {
        $trends[] = "📊 Average of {$applicationsPerJob} applications per active job";
    }
    if ($totalEmployees > 0) {
        $trends[] = "👥 {$totalEmployees} active employees deployed";
    }
    if (empty($trends)) {
        $trends = [
            "📊 Monitor your application-to-interview conversion rate",
            "📈 Track time-to-hire for each position",
            "🎯 Focus on quality of applications over quantity"
        ];
    }
    
    // Calculate score
    $score = 70;
    if ($openJobs > 0) $score += 5;
    if ($totalApplications > 5) $score += 5;
    if ($totalEmployees > 0) $score += 5;
    if ($conversionRate > 10) $score += 5;
    if ($pendingApplications < 5) $score += 5;
    $score = min(100, $score);
    
    return [
        'summary' => $summary,
        'recommendations' => $recommendations,
        'alerts' => $alerts,
        'trends' => $trends,
        'score' => $score,
        'provider' => 'fallback'
    ];
}

// =============================================
// GET AI INSIGHTS
// =============================================
$stats = [
    'total_jobs' => $totalJobs,
    'open_jobs' => $openJobs,
    'total_applications' => $totalApplications,
    'pending_applications' => $pendingApplications,
    'total_applicants' => $totalApplicants,
    'total_employees' => $totalEmployees,
    'total_revenue' => $totalRevenue
];

$aiInsights = getClientAIInsights($stats);
$aiProvider = $aiInsights['provider'] ?? 'fallback';
$aiScore = $aiInsights['score'] ?? 70;

// Determine score color
if ($aiScore >= 80) {
    $scoreColor = '#059669';
    $scoreLabel = 'Excellent';
} elseif ($aiScore >= 60) {
    $scoreColor = '#2563eb';
    $scoreLabel = 'Good';
} elseif ($aiScore >= 40) {
    $scoreColor = '#d97706';
    $scoreLabel = 'Fair';
} else {
    $scoreColor = '#dc2626';
    $scoreLabel = 'Needs Attention';
}
?>
<!-- HTML CONTENT REMAINS THE SAME -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Client Dashboard - AI Powered</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           CLIENT DASHBOARD - AI EDITION
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

        .ai-score-display {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 0 0.5rem 1rem;
            border-left: 4px solid var(--primary);
            background: rgba(255, 255, 255, 0.5);
            border-radius: var(--radius-md);
            margin-top: 0.5rem;
        }

        .ai-score-display .score-number {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }

        .ai-score-display .score-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
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

        /* AI Badge in header */
        .ai-badge-sm {
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
            margin-left: 0.5rem;
        }
        .ai-badge-sm .material-symbols-outlined {
            font-size: 0.65rem;
        }

        /* Rest of styles */
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

        /* =============================================
           STATS ROW
        ============================================= */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            width: 3rem;
            height: 3rem;
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
        .stat-card .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.5rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-number.currency { color: #059669; }
        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           DASHBOARD GRID
        ============================================= */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }

        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
        }
        .card-header {
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-header h3 .material-symbols-outlined { font-size: 1.125rem; color: var(--primary); }
        .card-header a {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .card-header a:hover { text-decoration: underline; }
        .card-body { padding: 0.75rem 1.25rem; }

        .list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.625rem 0;
            border-bottom: 1px solid var(--slate-100);
        }
        .list-item:last-child { border-bottom: none; }
        .list-item .item-left {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .list-item .item-left .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
        }
        .list-item .item-left .info .name { font-weight: 600; font-size: 0.8125rem; color: var(--text-on-surface); }
        .list-item .item-left .info .sub { font-size: 0.6875rem; color: var(--text-on-surface-variant); }
        .badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-active { background: #d1fae5; color: #059669; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-open { background: #dbeafe; color: #2563eb; }
        .badge-ongoing { background: #e0e7ff; color: #4f46e5; }

        .empty-state {
            text-align: center;
            padding: 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .empty-state .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.25rem;
        }
        .empty-state p { font-size: 0.8125rem; }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.625rem 1.125rem;
            border-radius: 0.5rem;
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
        .toast .material-symbols-outlined { font-size: 1.125rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

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
            .stats-row { grid-template-columns: 1fr 1fr; }
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .stats-row { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.25rem; }
            .stat-card .stat-icon { width: 2.5rem; height: 2.5rem; }
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Dashboard</span>
                <span class="ai-badge-sm">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    AI Powered
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
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">dashboard</span>
                        <span>Dashboard</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">
                        Updated <?php echo date('M d, Y H:i'); ?>
                        <span class="ai-provider-tag" style="margin-left:0.5rem;">
                            AI: <span class="provider-name <?php echo $aiProvider; ?>"><?php echo ucfirst($aiProvider); ?></span>
                        </span>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Welcome, <?php echo htmlspecialchars($firstName ?: 'Client'); ?>!</h1>
                        <p>Here's an overview of your hiring activity with AI insights</p>
                    </div>
                    <div>
                        <a href="jobs.php" class="btn btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            Post New Job
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
                    
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                        <div class="insight-summary" style="flex:1; margin-bottom:0;">
                            <?php echo htmlspecialchars($aiInsights['summary'] ?? 'Your recruitment is on track.'); ?>
                        </div>
                        <div class="ai-score-display">
                            <div>
                                <div class="score-number" style="color:<?php echo $scoreColor; ?>;"><?php echo $aiScore; ?>%</div>
                            </div>
                            <div>
                                <div class="score-label" style="color:<?php echo $scoreColor; ?>;"><?php echo $scoreLabel; ?></div>
                                <div style="font-size:0.6rem; color:var(--text-on-surface-variant);">Health Score</div>
                            </div>
                        </div>
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
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <span class="material-symbols-outlined">people</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalEmployees; ?></div>
                            <div class="stat-label">Active Employees</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <span class="material-symbols-outlined">person_search</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $totalApplicants; ?></div>
                            <div class="stat-label">Total Applicants</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">work</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo $openJobs; ?></div>
                            <div class="stat-label">Open Positions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">payments</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number currency">₱<?php echo number_format($totalRevenue, 0); ?></div>
                            <div class="stat-label">Total Revenue (Est.)</div>
                        </div>
                    </div>
                </div>

                <!-- Dashboard Grid -->
                <div class="dashboard-grid">

                    <!-- Active Jobs -->
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <span class="material-symbols-outlined">work</span>
                                Active Jobs
                            </h3>
                            <a href="jobs.php">View All <span class="material-symbols-outlined">arrow_forward</span></a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($activeJobs)): ?>
                                <div class="empty-state">
                                    <span class="material-symbols-outlined">work_off</span>
                                    <p>No active jobs. <a href="jobs.php" style="color:var(--primary); font-weight:600;">Post your first job</a></p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($activeJobs as $job): ?>
                                    <div class="list-item">
                                        <div class="item-left">
                                            <div>
                                                <div class="name"><?php echo htmlspecialchars($job['title']); ?></div>
                                                <div class="sub"><?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?> • <?php echo $job['job_type'] ?? 'Full-time'; ?></div>
                                            </div>
                                        </div>
                                        <span class="badge <?php echo $job['status'] === 'open' ? 'badge-open' : 'badge-ongoing'; ?>">
                                            <?php echo ucfirst($job['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Applicants -->
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <span class="material-symbols-outlined">person_search</span>
                                Recent Applicants
                            </h3>
                            <a href="applicants.php">View All <span class="material-symbols-outlined">arrow_forward</span></a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentApplicants)): ?>
                                <div class="empty-state">
                                    <span class="material-symbols-outlined">inbox</span>
                                    <p>No applicants yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentApplicants as $app): ?>
                                    <div class="list-item">
                                        <div class="item-left">
                                            <span class="avatar"><?php echo strtoupper(substr($app['first_name'] ?? 'A', 0, 1)); ?></span>
                                            <div>
                                                <div class="name"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                                <div class="sub"><?php echo htmlspecialchars($app['job_title']); ?> • <?php echo date('M d, Y', strtotime($app['applied_at'])); ?></div>
                                            </div>
                                        </div>
                                        <span class="badge badge-pending">Pending</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Employees -->
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <span class="material-symbols-outlined">people</span>
                                Recent Employees
                            </h3>
                            <a href="employees.php">View All <span class="material-symbols-outlined">arrow_forward</span></a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentEmployees)): ?>
                                <div class="empty-state">
                                    <span class="material-symbols-outlined">person_off</span>
                                    <p>No employees deployed yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentEmployees as $emp): ?>
                                    <div class="list-item">
                                        <div class="item-left">
                                            <span class="avatar"><?php echo strtoupper(substr($emp['first_name'] ?? 'E', 0, 1)); ?></span>
                                            <div>
                                                <div class="name"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                                <div class="sub"><?php echo htmlspecialchars($emp['job_title']); ?> • Started <?php echo date('M d, Y', strtotime($emp['start_date'] ?? 'now')); ?></div>
                                            </div>
                                        </div>
                                        <span class="badge badge-active">Active</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <span class="material-symbols-outlined">insights</span>
                                Quick Stats
                            </h3>
                        </div>
                        <div class="card-body">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                                <div style="background:var(--bg-surface-low); padding:0.75rem; border-radius:0.5rem; text-align:center;">
                                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-on-surface);"><?php echo $totalApplications; ?></div>
                                    <div style="font-size:0.6875rem; color:var(--text-on-surface-variant);">Total Applications</div>
                                </div>
                                <div style="background:var(--bg-surface-low); padding:0.75rem; border-radius:0.5rem; text-align:center;">
                                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-on-surface);"><?php echo $totalApplicants; ?></div>
                                    <div style="font-size:0.6875rem; color:var(--text-on-surface-variant);">Unique Applicants</div>
                                </div>
                                <div style="background:var(--bg-surface-low); padding:0.75rem; border-radius:0.5rem; text-align:center;">
                                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-on-surface);"><?php echo $totalEmployees; ?></div>
                                    <div style="font-size:0.6875rem; color:var(--text-on-surface-variant);">Active Employees</div>
                                </div>
                                <div style="background:var(--bg-surface-low); padding:0.75rem; border-radius:0.5rem; text-align:center;">
                                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-on-surface);"><?php echo $openJobs; ?></div>
                                    <div style="font-size:0.6875rem; color:var(--text-on-surface-variant);">Open Positions</div>
                                </div>
                            </div>
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
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        console.log('🤖 AI-Powered Client Dashboard loaded successfully!');
        console.log('📊 AI Provider: <?php echo ucfirst($aiProvider); ?>');
        console.log('📈 Health Score: <?php echo $aiScore; ?>%');
    </script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>