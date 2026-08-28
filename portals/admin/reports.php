<?php
// portals/admin/reports.php - AI-Powered Client & Agency Reports Dashboard
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();
require_once '../../app/ai/AiService.php';

// =============================================
// FIX: Ensure permission functions are loaded
// =============================================
if (!function_exists('requirePermission')) {
    if (file_exists('../../app/permissions.php')) {
        require_once '../../app/permissions.php';
    }
    
    if (!function_exists('requirePermission')) {
        function requirePermission($userId, $permission, $redirectUrl = 'dashboard.php') {
            $user = getUserById($userId);
            if (!$user || $user['role'] !== 'admin') {
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
        
        function hasPermission($userId, $permission) {
            $user = getUserById($userId);
            if (!$user) return false;
            return $user['role'] === 'admin';
        }
    }
}
// =============================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has permission to view reports
requirePermission($_SESSION['user_id'], 'view_reports', 'dashboard.php');

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$firstName = $_SESSION['first_name'] ?? 'Admin';
$email = $_SESSION['email'] ?? '';

// =============================================
// AI SERVICE INITIALIZATION
// =============================================
$aiService = new AiService();

// Get selected filters from GET
$selectedClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$selectedAgency = isset($_GET['agency_id']) ? (int)$_GET['agency_id'] : 0;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'clients';

// =============================================
// AI HELPER FUNCTIONS
// =============================================

/**
 * Generate AI executive summary for admin
 */
function generateAdminAIInsights($data) {
    global $aiService;
    
    try {
        $result = $aiService->generateAdminExecutiveSummary([
            'total_clients' => $data['total_clients'] ?? 0,
            'total_agencies' => $data['total_agencies'] ?? 0,
            'total_jobs' => $data['total_jobs'] ?? 0,
            'total_applications' => $data['total_applications'] ?? 0,
            'total_users' => $data['total_users'] ?? 0,
            'online_users' => $data['online_users'] ?? 0,
            'pending_agencies' => $data['pending_agencies'] ?? 0,
            'active_clients' => $data['active_clients'] ?? 0,
            'industry_distribution' => $data['industry_distribution'] ?? []
        ]);
        
        if ($result && !isset($result['error'])) {
            return [
                'success' => true,
                'summary' => $result['summary'] ?? 'System is operating normally.',
                'insights' => $result['insights'] ?? [],
                'recommendations' => $result['recommendations'] ?? [],
                'health_score' => $result['health_score'] ?? 85,
                'trend_forecast' => $result['trend_forecast'] ?? 'Stable',
                'provider' => $result['provider'] ?? 'fallback'
            ];
        }
    } catch (Exception $e) {
        error_log("AI Admin Insights Error: " . $e->getMessage());
    }
    
    // Return fallback mock summary
    return generateMockAdminInsights($data);
}

/**
 * Generate mock admin insights (fallback)
 */
function generateMockAdminInsights($data) {
    $totalClients = $data['total_clients'] ?? 0;
    $totalAgencies = $data['total_agencies'] ?? 0;
    $totalJobs = $data['total_jobs'] ?? 0;
    $totalApplications = $data['total_applications'] ?? 0;
    $totalUsers = $data['total_users'] ?? 0;
    $onlineUsers = $data['online_users'] ?? 0;
    $pendingAgencies = $data['pending_agencies'] ?? 0;
    $activeClients = $data['active_clients'] ?? 0;
    
    $insights = [];
    $recommendations = [];
    $healthScore = 85;
    $trendForecast = 'Stable';
    
    // Client insights
    if ($totalClients > 10) {
        $insights[] = "You have {$totalClients} active clients, showing strong platform adoption.";
        $healthScore += 5;
    } elseif ($totalClients > 0) {
        $insights[] = "You have {$totalClients} clients. Consider expanding your outreach.";
        $healthScore += 2;
    } else {
        $insights[] = "No clients registered yet. Start onboarding clients to grow your platform.";
        $healthScore -= 10;
        $recommendations[] = "Launch a client acquisition campaign to onboard new clients.";
    }
    
    // Agency insights
    if ($totalAgencies > 5) {
        $insights[] = "{$totalAgencies} agencies are approved and active on the platform.";
        $healthScore += 3;
    } else {
        $insights[] = "Consider reviewing pending agency applications to grow your network.";
        if ($pendingAgencies > 0) {
            $insights[] = "{$pendingAgencies} agency applications are pending review.";
            $recommendations[] = "Review pending agency applications to expand your agency network.";
        }
    }
    
    // Job and application insights
    if ($totalJobs > 20) {
        $insights[] = "{$totalJobs} jobs posted across the platform, indicating healthy activity.";
        $healthScore += 5;
    } elseif ($totalJobs > 0) {
        $insights[] = "{$totalJobs} jobs posted. Encourage more clients to post jobs.";
        $recommendations[] = "Send a reminder to clients to post new job openings.";
    }
    
    if ($totalApplications > 50) {
        $insights[] = "{$totalApplications} applications received, showing strong candidate engagement.";
        $healthScore += 5;
    } elseif ($totalApplications > 0) {
        $insights[] = "{$totalApplications} applications received. Consider promoting job postings.";
        $recommendations[] = "Run a marketing campaign to attract more applicants.";
    }
    
    // User engagement
    if ($onlineUsers > 5) {
        $insights[] = "{$onlineUsers} users are currently online, showing good platform engagement.";
        $healthScore += 3;
    }
    
    // Health score adjustments
    if ($healthScore > 90) $healthScore = 92;
    if ($healthScore < 40) $healthScore = 45;
    
    // Trend forecast
    if ($totalJobs > 30 && $totalApplications > 60) {
        $trendForecast = 'Growing';
    } elseif ($totalJobs > 10 || $totalApplications > 20) {
        $trendForecast = 'Stable';
    } else {
        $trendForecast = 'Needs Attention';
    }
    
    // General recommendations
    if (empty($recommendations)) {
        $recommendations = [
            "Monitor key metrics regularly to identify trends early.",
            "Engage with clients to understand their hiring needs.",
            "Review agency performance to optimize partnerships."
        ];
    }
    
    $summary = "ISMERS platform is operating with {$totalUsers} users, {$totalClients} clients, and {$totalAgencies} agencies. " .
               "There are {$totalJobs} active jobs and {$totalApplications} applications. " .
               "The overall health score is {$healthScore}% with a {$trendForecast} trend.";
    
    return [
        'success' => true,
        'summary' => $summary,
        'insights' => $insights,
        'recommendations' => $recommendations,
        'health_score' => $healthScore,
        'trend_forecast' => $trendForecast,
        'provider' => 'mock'
    ];
}

/**
 * Calculate client health score
 */
function calculateClientHealthScore($clientData) {
    $score = 0;
    $maxScore = 100;
    
    // Job activity (30%)
    $jobScore = min(30, $clientData['total_jobs'] * 3);
    $score += $jobScore;
    
    // Application activity (30%)
    $appScore = min(30, $clientData['total_applications'] * 2);
    $score += $appScore;
    
    // Hiring success (20%)
    $hireRate = $clientData['hire_rate'] ?? 0;
    $hireScore = min(20, $hireRate * 2);
    $score += $hireScore;
    
    // Recent activity (20%)
    $recentScore = 0;
    if ($clientData['recent_jobs'] ?? 0 > 0) $recentScore += 10;
    if ($clientData['recent_applications'] ?? 0 > 0) $recentScore += 10;
    $score += $recentScore;
    
    // Score classification
    if ($score >= 80) {
        $status = 'Excellent';
        $color = '#059669';
        $icon = '🌟';
    } elseif ($score >= 60) {
        $status = 'Good';
        $color = '#2563eb';
        $icon = '✅';
    } elseif ($score >= 40) {
        $status = 'Fair';
        $color = '#d97706';
        $icon = '⚠️';
    } else {
        $status = 'At Risk';
        $color = '#dc2626';
        $icon = '🚨';
    }
    
    return [
        'score' => min(100, $score),
        'status' => $status,
        'color' => $color,
        'icon' => $icon,
        'job_score' => $jobScore,
        'app_score' => $appScore,
        'hire_score' => $hireScore,
        'recent_score' => $recentScore
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // =============================================
    // GET AI EXECUTIVE SUMMARY (AJAX)
    // =============================================
    if ($_POST['action'] === 'get_ai_summary') {
        header('Content-Type: application/json');
        
        // ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
        // Gather system data
        $data = [
            'total_clients' => getRecord("SELECT COUNT(*) as count FROM clients WHERE is_active = 1")['count'] ?? 0,
            'total_agencies' => getRecord("SELECT COUNT(*) as count FROM agency_applications WHERE status = 'approved'")['count'] ?? 0,
            'total_jobs' => getRecord("SELECT COUNT(*) as count FROM job_orders")['count'] ?? 0,
            'total_applications' => getRecord("SELECT COUNT(*) as count FROM applications")['count'] ?? 0,
            'total_users' => getRecord("SELECT COUNT(*) as count FROM users")['count'] ?? 0,
            // ✅ FIXED: PostgreSQL uses NOW() - INTERVAL instead of DATE_SUB
            'online_users' => getRecord("SELECT COUNT(*) as count FROM users WHERE last_activity >= NOW() - INTERVAL '5 minutes'")['count'] ?? 0,
            'pending_agencies' => getRecord("SELECT COUNT(*) as count FROM agency_applications WHERE status = 'pending'")['count'] ?? 0,
            'active_clients' => getRecord("SELECT COUNT(*) as count FROM clients WHERE is_active = 1")['count'] ?? 0,
            'industry_distribution' => getRecords("SELECT industry, COUNT(*) as count FROM clients WHERE is_active = 1 AND industry IS NOT NULL AND industry != '' GROUP BY industry ORDER BY count DESC")
        ];
        
        $result = generateAdminAIInsights($data);
        echo json_encode($result);
        exit;
    }
    
    // =============================================
    // GET CLIENT HEALTH SCORE (AJAX)
    // =============================================
    if ($_POST['action'] === 'get_client_health') {
        header('Content-Type: application/json');
        $clientId = intval($_POST['client_id'] ?? 0);
        
        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
            exit;
        }
        
        // ✅ FIXED: PostgreSQL syntax - no ?, uses $1 placeholder, removed type string
        $clientData = getRecord("
            SELECT 
                c.id,
                c.company_name,
                COUNT(DISTINCT jo.id) as total_jobs,
                COUNT(a.id) as total_applications,
                SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as total_hires,
                ROUND(SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0) * 100, 1) as hire_rate,
                SUM(CASE WHEN jo.created_at >= NOW() - INTERVAL '30 days' THEN 1 ELSE 0 END) as recent_jobs,
                SUM(CASE WHEN a.applied_at >= NOW() - INTERVAL '30 days' THEN 1 ELSE 0 END) as recent_applications
            FROM clients c
            LEFT JOIN job_orders jo ON c.id = jo.client_id
            LEFT JOIN applications a ON jo.id = a.job_order_id
            WHERE c.id = $1
            GROUP BY c.id
        ", [$clientId]);
        
        if (!$clientData) {
            echo json_encode(['success' => false, 'error' => 'Client not found']);
            exit;
        }
        
        $health = calculateClientHealthScore($clientData);
        $health['company_name'] = $clientData['company_name'];
        $health['total_jobs'] = $clientData['total_jobs'] ?? 0;
        $health['total_applications'] = $clientData['total_applications'] ?? 0;
        $health['total_hires'] = $clientData['total_hires'] ?? 0;
        $health['hire_rate'] = $clientData['hire_rate'] ?? 0;
        
        echo json_encode(['success' => true, 'data' => $health]);
        exit;
    }
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Export based on tab
    if ($activeTab === 'clients' && !empty($selectedClient)) {
        // Client CSV export
        fputcsv($output, ['Company', 'Contact', 'Phone', 'Industry', 'Open Jobs', 'Filled Jobs', 'Ongoing Jobs', 'Total Jobs', 'Applications']);
        
        foreach ($clientsWithJobs as $client) {
            fputcsv($output, [
                $client['company_name'],
                $client['contact_person'],
                $client['phone'],
                $client['industry'],
                $client['open_jobs'],
                $client['filled_jobs'],
                $client['ongoing_jobs'],
                $client['total_jobs'],
                $client['total_applications']
            ]);
        }
    } elseif ($activeTab === 'agencies' && !empty($selectedAgency)) {
        // Agency CSV export
        fputcsv($output, ['Agency', 'Code', 'Contact', 'Email', 'Phone', 'Client', 'Status', 'Specialization', 'Submitted']);
        
        foreach ($agencyApplications as $agency) {
            fputcsv($output, [
                $agency['agency_name'],
                $agency['agency_code'],
                $agency['contact_person'],
                $agency['contact_email'],
                $agency['contact_phone'],
                $agency['client_company_name'] ?? 'N/A',
                $agency['status'],
                $agency['specialization'],
                date('Y-m-d', strtotime($agency['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// =============================================
// FETCH CLIENT DATA - PostgreSQL syntax
// =============================================

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
$allClients = getRecords("SELECT id, company_name FROM clients WHERE is_active = 1 ORDER BY company_name ASC");

// 1. TOTAL CLIENTS
$totalClients = getRecord("SELECT COUNT(*) as count FROM clients WHERE is_active = 1")['count'] ?? 0;
$totalInactiveClients = getRecord("SELECT COUNT(*) as count FROM clients WHERE is_active = 0")['count'] ?? 0;

// 2. CLIENT DETAILS (Selected or All)
if ($selectedClient > 0) {
    $clientCondition = "AND c.id = $selectedClient";
} else {
    $clientCondition = "";
}

// ✅ FIXED: PostgreSQL uses NOW() - INTERVAL instead of DATE_SUB
$clientsWithJobs = getRecords("
    SELECT 
        c.id,
        c.company_name,
        c.contact_person,
        c.contact_phone as phone,
        c.industry,
        c.is_active,
        c.created_at,
        COUNT(jo.id) as total_jobs,
        SUM(CASE WHEN jo.status = 'open' THEN 1 ELSE 0 END) as open_jobs,
        SUM(CASE WHEN jo.status = 'filled' THEN 1 ELSE 0 END) as filled_jobs,
        SUM(CASE WHEN jo.status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_jobs,
        (SELECT COUNT(*) FROM applications a 
         JOIN job_orders jo2 ON a.job_order_id = jo2.id 
         WHERE jo2.client_id = c.id) as total_applications,
        (SELECT COUNT(*) FROM applications a 
         JOIN job_orders jo2 ON a.job_order_id = jo2.id 
         WHERE jo2.client_id = c.id AND a.status = 'hired') as total_hires,
        (SELECT COUNT(*) FROM applications a 
         JOIN job_orders jo2 ON a.job_order_id = jo2.id 
         WHERE jo2.client_id = c.id AND a.applied_at >= NOW() - INTERVAL '30 days') as recent_applications
    FROM clients c
    LEFT JOIN job_orders jo ON c.id = jo.client_id
    WHERE c.is_active = 1 $clientCondition
    GROUP BY c.id
    ORDER BY total_jobs DESC
");

// 3. TOP PERFORMING CLIENTS
$topClients = getRecords("
    SELECT 
        c.id,
        c.company_name,
        c.industry,
        COUNT(DISTINCT jo.id) as total_jobs,
        COUNT(a.id) as total_applications,
        SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as total_hires,
        ROUND(SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0) * 100, 1) as hire_rate
    FROM clients c
    LEFT JOIN job_orders jo ON c.id = jo.client_id
    LEFT JOIN applications a ON jo.id = a.job_order_id
    WHERE c.is_active = 1
    GROUP BY c.id
    HAVING total_applications > 0
    ORDER BY total_applications DESC
    LIMIT 10
");

// 4. RECENT CLIENTS
$recentClients = getRecords("
    SELECT 
        c.id,
        c.company_name,
        c.contact_person,
        c.contact_phone as phone,
        c.industry,
        c.is_active,
        c.created_at,
        u.email
    FROM clients c
    JOIN users u ON c.user_id = u.id
    ORDER BY c.created_at DESC
    LIMIT 10
");

// 5. INDUSTRY DISTRIBUTION
$industryDistribution = getRecords("
    SELECT 
        industry,
        COUNT(*) as count
    FROM clients
    WHERE is_active = 1 AND industry IS NOT NULL AND industry != ''
    GROUP BY industry
    ORDER BY count DESC
");

// =============================================
// FETCH AGENCY APPLICATION DATA - PostgreSQL syntax
// =============================================

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
$allAgencies = getRecords("SELECT id, agency_name FROM agency_applications WHERE status = 'approved' ORDER BY agency_name ASC");

// 6. TOTAL AGENCY APPLICATIONS
$totalAgencies = getRecord("SELECT COUNT(*) as count FROM agency_applications WHERE status = 'approved'")['count'] ?? 0;
$totalPendingAgencies = getRecord("SELECT COUNT(*) as count FROM agency_applications WHERE status = 'pending'")['count'] ?? 0;
$totalRejectedAgencies = getRecord("SELECT COUNT(*) as count FROM agency_applications WHERE status = 'rejected'")['count'] ?? 0;

// 7. AGENCY APPLICATIONS LIST (Filtered by selected agency)
if ($selectedAgency > 0) {
    $agencyCondition = "AND aa.id = $selectedAgency";
} else {
    $agencyCondition = "";
}

$agencyApplications = getRecords("
    SELECT 
        aa.id,
        aa.user_id,
        aa.client_id,
        aa.agency_name,
        aa.agency_code,
        aa.contact_person,
        aa.contact_email,
        aa.contact_phone,
        aa.address,
        aa.website,
        aa.specialization,
        aa.years_experience,
        aa.team_size,
        aa.status,
        aa.reviewed_by,
        aa.reviewed_at,
        aa.rejection_reason,
        aa.created_at,
        aa.updated_at,
        c.company_name as client_company_name,
        u.first_name as reviewer_first_name,
        u.last_name as reviewer_last_name
    FROM agency_applications aa
    LEFT JOIN clients c ON aa.client_id = c.id
    LEFT JOIN users u ON aa.reviewed_by = u.id
    WHERE 1=1 $agencyCondition
    ORDER BY aa.created_at DESC
");

// 8. AGENCY STATUS SUMMARY
$agencyStatusSummary = getRecords("
    SELECT 
        status,
        COUNT(*) as count
    FROM agency_applications
    GROUP BY status
");

// 9. RECENT AGENCY APPLICATIONS
$recentAgencies = getRecords("
    SELECT 
        id,
        agency_name,
        agency_code,
        contact_person,
        contact_email,
        contact_phone,
        status,
        created_at
    FROM agency_applications 
    ORDER BY created_at DESC 
    LIMIT 10
");

// 10. AGENCY BY CLIENT
$agencyByClient = getRecords("
    SELECT 
        c.company_name,
        COUNT(aa.id) as agency_count,
        SUM(CASE WHEN aa.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN aa.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN aa.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
    FROM clients c
    LEFT JOIN agency_applications aa ON c.id = aa.client_id
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY agency_count DESC
    LIMIT 10
");

// Get selected client/agency names for display
$selectedClientName = '';
if ($selectedClient > 0) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
    $client = getRecord("SELECT company_name FROM clients WHERE id = $1", [$selectedClient]);
    $selectedClientName = $client['company_name'] ?? '';
}

$selectedAgencyName = '';
if ($selectedAgency > 0) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
    $agency = getRecord("SELECT agency_name FROM agency_applications WHERE id = $1", [$selectedAgency]);
    $selectedAgencyName = $agency['agency_name'] ?? '';
}

// Determine if we should show details
$showClientDetails = ($selectedClient > 0);
$showAgencyDetails = ($selectedAgency > 0);

// Get greeting
$currentHour = date('H');
$greeting = 'Good Evening';
if ($currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour < 18) {
    $greeting = 'Good Afternoon';
}

// ✅ FIXED: PostgreSQL uses NOW() - INTERVAL instead of DATE_SUB
$onlineUsers = getRecord("SELECT COUNT(*) as count FROM users WHERE last_activity >= NOW() - INTERVAL '5 minutes'")['count'] ?? 0;
$totalUsers = getRecord("SELECT COUNT(*) as count FROM users")['count'] ?? 0;

// Get user profile data for sidebar
$userProfile = getUserProfileData($userId);

// Pre-compute AI summary
$systemData = [
    'total_clients' => $totalClients,
    'total_agencies' => $totalAgencies,
    'total_jobs' => getRecord("SELECT COUNT(*) as count FROM job_orders")['count'] ?? 0,
    'total_applications' => getRecord("SELECT COUNT(*) as count FROM applications")['count'] ?? 0,
    'total_users' => $totalUsers,
    'online_users' => $onlineUsers,
    'pending_agencies' => $totalPendingAgencies,
    'active_clients' => $totalClients,
    'industry_distribution' => $industryDistribution
];
$aiSummary = generateAdminAIInsights($systemData);

// Get trend forecast color
function getTrendColor($trend) {
    $colors = [
        'Growing' => '#059669',
        'Stable' => '#2563eb',
        'Needs Attention' => '#d97706',
        'Declining' => '#dc2626'
    ];
    return $colors[$trend] ?? '#6b7280';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reports - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - REPORTS
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
            --info-color: #2563eb;
        }

        /* AI Badge */
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
        .ai-badge .material-symbols-outlined {
            font-size: 0.7rem;
        }

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
        .btn-ai .material-symbols-outlined {
            font-size: 1rem;
        }
        .btn-ai:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        /* AI Dots Loading (small) */
        .ai-dots-loading-sm {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0;
        }
        .ai-dots-loading-sm .dot {
            width: 0.375rem;
            height: 0.375rem;
            background: var(--primary);
            border-radius: 50%;
            animation: dotPulseSm 1.4s infinite ease-in-out both;
        }
        .ai-dots-loading-sm .dot:nth-child(1) { animation-delay: -0.32s; }
        .ai-dots-loading-sm .dot:nth-child(2) { animation-delay: -0.16s; }
        .ai-dots-loading-sm .dot:nth-child(3) { animation-delay: 0s; }
        @keyframes dotPulseSm {
            0%, 80%, 100% { transform: scale(0.5); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* AI Summary Panel */
        .ai-summary-panel {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .ai-summary-panel .summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }
        .ai-summary-panel .summary-header .title {
            font-weight: 700;
            font-size: 0.875rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .ai-summary-panel .summary-header .health-score {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 700;
            color: white;
        }
        .ai-summary-panel .summary-header .trend-badge {
            font-size: 0.625rem;
            font-weight: 600;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            text-transform: uppercase;
            color: white;
        }
        .ai-summary-panel .summary-text {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            line-height: 1.7;
            margin-bottom: 0.75rem;
        }
        .ai-summary-panel .summary-insights {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        @media (max-width: 480px) {
            .ai-summary-panel .summary-insights { grid-template-columns: 1fr; }
        }
        .ai-summary-panel .insight-item {
            background: rgba(255,255,255,0.6);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .ai-summary-panel .insight-item .icon {
            color: var(--primary);
            font-size: 1rem;
            margin-top: 0.0625rem;
        }
        .ai-summary-panel .summary-recommendations {
            padding-top: 0.75rem;
            border-top: 1px solid rgba(196, 181, 253, 0.3);
        }
        .ai-summary-panel .summary-recommendations .rec-item {
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            padding: 0.125rem 0;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .ai-summary-panel .summary-recommendations .rec-item .icon {
            color: #7c3aed;
            font-size: 1rem;
            margin-top: 0.0625rem;
        }
        .ai-summary-panel .summary-provider {
            font-size: 0.5rem;
            color: var(--text-on-surface-variant);
            text-align: right;
            margin-top: 0.5rem;
        }

        /* =============================================
           REST OF STYLES (same as your existing)
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

        /* =============================================
           SIDEBAR - FIXED
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

        .dashboard-sidebar.collapsed ~ .main-wrapper {
            margin-left: var(--sidebar-collapsed);
        }

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

        .top-header-left .separator {
            color: var(--outline-variant);
            font-weight: 300;
            user-select: none;
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

        .sidebar-toggle-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-toggle-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

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

        .mobile-menu-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .mobile-menu-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

        /* =============================================
           PROFILE DROPDOWN
        ============================================= */
        .profile-dropdown-wrapper {
            position: relative;
        }

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

        .profile-dropdown-toggle:hover {
            background: var(--bg-surface-low);
            border-color: rgba(199, 196, 216, 0.3);
        }

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

        .profile-dropdown-toggle .profile-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .profile-dropdown-toggle .profile-role {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            font-weight: 400;
        }

        .profile-dropdown-toggle .material-symbols-outlined {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
            transition: transform var(--transition-fast);
        }

        .profile-dropdown-toggle.open .material-symbols-outlined:last-child {
            transform: rotate(180deg);
        }

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

        .profile-dropdown-menu.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

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

        .profile-dropdown-menu .dropdown-item:hover {
            background: var(--bg-surface-low);
            color: var(--primary);
        }

        .profile-dropdown-menu .dropdown-item .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--text-on-surface-variant);
        }

        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined {
            color: var(--primary);
        }

        .profile-dropdown-menu .dropdown-item.danger {
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-item.danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined {
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-divider {
            height: 1px;
            background: var(--slate-200);
            margin: 0.25rem 0.5rem;
        }

        /* =============================================
           MAIN SCROLLABLE AREA
        ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }

        .main-scroll .container {
            max-width: 80rem;
            margin: 0 auto;
        }

        /* =============================================
           BREADCRUMB
        ============================================= */
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

        .breadcrumb-view .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .breadcrumb-view .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: var(--success-color);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* =============================================
           PAGE HEADER
        ============================================= */
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

        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .page-header .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* =============================================
           BUTTONS
        ============================================= */
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

        .btn-primary {
            background: var(--primary);
            color: var(--on-primary);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
        }

        .btn-primary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: var(--on-primary);
        }

        .btn-outline .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-danger {
            background: var(--error-color);
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.5rem;
        }

        .btn-sm .material-symbols-outlined {
            font-size: 1rem;
        }

        /* =============================================
           TABS
        ============================================= */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--slate-200);
            padding-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border: none;
            background: transparent;
            color: var(--text-on-surface-variant);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            border-radius: 0.5rem;
            transition: all var(--transition-fast);
            position: relative;
            font-family: var(--font-sans);
        }

        .tab-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .tab-btn.active {
            color: var(--primary);
            background: rgba(79, 70, 229, 0.1);
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -0.55rem;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
            border-radius: 3px 3px 0 0;
        }

        .tab-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* =============================================
           FILTER BAR
        ============================================= */
        .filter-bar {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .filter-bar .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-bar select {
            padding: 0.5rem 2.25rem 0.5rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.625rem;
            font-size: 0.8125rem;
            font-family: inherit;
            background: var(--bg-surface);
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            min-width: 180px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%235a6a7a'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
        }

        .filter-bar select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .filter-bar .btn {
            padding: 0.5rem 1rem;
            font-size: 0.8125rem;
        }

        .filter-bar .selected-info {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            background: var(--bg-surface-low);
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-bar .selected-info .material-symbols-outlined {
            font-size: 1rem;
            color: var(--primary);
        }

        /* =============================================
           STATS GRID
        ============================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

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

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

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
        .stat-card .stat-icon.teal { background: #ccfbf1; color: #0d9488; }
        .stat-card .stat-icon.pink { background: #fce7f3; color: #db2777; }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--slate-900);
            line-height: 1;
        }

        .stat-card .stat-change {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           SELECT PROMPT
        ============================================= */
        .select-prompt {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 2px dashed var(--slate-200);
        }

        .select-prompt .prompt-icon {
            font-size: 4rem;
            color: var(--primary);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .select-prompt h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.5rem;
        }

        .select-prompt p {
            color: var(--text-on-surface-variant);
            font-size: 1rem;
        }

        /* =============================================
           CHART BARS
        ============================================= */
        .chart-bar-container {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .chart-bar-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .chart-bar-item .bar-label {
            min-width: 120px;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            font-weight: 500;
        }

        .chart-bar-item .bar-track {
            flex: 1;
            height: 1.5rem;
            background: var(--bg-surface-low);
            border-radius: 0.375rem;
            overflow: hidden;
            position: relative;
        }

        .chart-bar-item .bar-fill {
            height: 100%;
            border-radius: 0.375rem;
            transition: width 1s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 0.5rem;
            font-size: 0.625rem;
            font-weight: 600;
            color: white;
            min-width: 30px;
        }

        .chart-bar-item .bar-value {
            min-width: 40px;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            text-align: right;
        }

        /* =============================================
           CARDS
        ============================================= */
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
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header .result-count {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
        }

        .card-body {
            padding: 1.25rem 1.5rem;
        }

        .card-body.table-body {
            padding: 0;
            overflow-x: auto;
        }

        /* =============================================
           TABLES
        ============================================= */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        table thead {
            background: var(--bg-surface-low);
        }

        table th {
            padding: 0.75rem 1.25rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--slate-200);
        }

        table td {
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        table tbody tr:hover {
            background: var(--bg-surface-low);
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.inactive { background: #fecaca; color: #dc2626; }
        .status-badge.open { background: #d1fae5; color: #059669; }
        .status-badge.filled { background: #dbeafe; color: #2563eb; }
        .status-badge.ongoing { background: #fef3c7; color: #d97706; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.approved { background: #d1fae5; color: #059669; }
        .status-badge.rejected { background: #fecaca; color: #dc2626; }

        .company-name {
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .text-muted {
            color: var(--text-on-surface-variant);
            font-size: 0.8125rem;
        }

        .text-success { color: #059669; }
        .text-danger { color: #dc2626; }
        .text-warning { color: #d97706; }
        .text-primary { color: var(--primary); }

        .fw-bold { font-weight: 700; }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 2rem 1.5rem;
        }

        .empty-state .empty-icon {
            margin-bottom: 0.75rem;
            opacity: 0.3;
        }

        .empty-state h4 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .empty-state p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop {
                display: none !important;
            }

            .mobile-menu-btn {
                display: none !important;
            }

            .dashboard-sidebar {
                position: fixed;
                transform: translateX(0) !important;
                box-shadow: var(--shadow-xl);
                height: 100vh;
            }

            .dashboard-sidebar.mobile-hidden {
                transform: translateX(0) !important;
            }

            .main-wrapper {
                margin-left: var(--sidebar-width);
            }

            .dashboard-sidebar.collapsed ~ .main-wrapper {
                margin-left: var(--sidebar-collapsed);
            }

            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: inline;
            }
        }

        @media (max-width: 767px) {
            .dashboard-sidebar {
                position: fixed;
                width: var(--sidebar-width);
                transform: translateX(-100%);
                box-shadow: var(--shadow-xl);
            }

            .dashboard-sidebar.mobile-open {
                transform: translateX(0);
            }

            .dashboard-sidebar.collapsed {
                width: var(--sidebar-width);
            }

            .sidebar-toggle-btn {
                display: none !important;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .main-scroll {
                padding: 1rem;
            }

            .top-header-left .separator {
                display: none;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: none;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-bar select {
                width: 100%;
            }

            table {
                font-size: 0.8125rem;
                min-width: 600px;
            }

            table th,
            table td {
                padding: 0.625rem 0.875rem;
            }

            .chart-bar-item .bar-label {
                min-width: 80px;
                font-size: 0.75rem;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab-btn {
                flex: 1;
                justify-content: center;
                font-size: 0.75rem;
                padding: 0.5rem 0.75rem;
            }

            .select-prompt {
                padding: 2rem 1rem;
            }

            .select-prompt .prompt-icon {
                font-size: 3rem;
            }

            .select-prompt h3 {
                font-size: 1.25rem;
            }

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

            .dashboard-sidebar.collapsed .sidebar-brand-card {
                padding: 1.5rem;
            }

            .dashboard-sidebar.collapsed .sidebar-nav {
                padding: 1.5rem 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link {
                justify-content: flex-start;
                padding: 0.75rem 1rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
                font-size: 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-footer .user-card {
                justify-content: flex-start;
                padding: 0.5rem 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .main-scroll {
                padding: 0.75rem;
            }

            .breadcrumb-bar {
                padding: 0.75rem 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card .stat-number {
                font-size: 1.5rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .card-body {
                padding: 0.75rem 1rem;
            }

            .chart-bar-item {
                flex-wrap: wrap;
            }

            .chart-bar-item .bar-label {
                min-width: 70px;
                font-size: 0.7rem;
            }
        }

        /* Scrollbar Styling */
        .main-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .main-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .main-scroll::-webkit-scrollbar-thumb {
            background: var(--slate-200);
            border-radius: 3px;
        }

        .main-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--slate-500);
        }

        /* Print styles */
        @media print {
            .top-header,
            .sidebar-toggle-btn,
            .mobile-menu-btn,
            .filter-bar .btn,
            .header-actions,
            .tabs,
            .select-prompt {
                display: none !important;
            }

            .dashboard-sidebar {
                display: none !important;
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .stat-card {
                break-inside: avoid;
            }

            .card {
                break-inside: avoid;
            }

            .tab-content {
                display: block !important;
            }

            .report-section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- Sidebar Backdrop (Mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =============================================
    SIDEBAR - FIXED
    ============================================= -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">admin_panel_settings</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">Admin Portal</p>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="users.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Users</span>
                <span class="nav-badge"><?php echo $totalUsers; ?></span>
            </a>

            <a href="roles.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">shield</span>
                <span class="nav-text">Roles</span>
            </a>

            <a href="reports.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">analytics</span>
                <span class="nav-text">Reports</span>
                <span class="ai-badge" style="margin-left:auto; font-size:0.45rem;">AI</span>
            </a>

            <a href="biometric_settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">fingerprint</span>
                <span class="nav-text">Biometric</span>
            </a>
        </nav>

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
                    <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- =============================================
    MAIN CONTENT
    ============================================= -->
    <div class="main-wrapper" id="mainWrapper">

        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" title="Open Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle Sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">menu_open</span>
                </button>
                <span class="separator">|</span>
                <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">Reports</span>
                <span class="ai-badge" style="margin-left:0.5rem;">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    AI Powered
                </span>
            </div>

            <!-- Profile Dropdown -->
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo $userProfile['initials']; ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Administrator</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-header">Account</div>
                    
                    <a href="profile.php" class="dropdown-item">
                        <span class="material-symbols-outlined">person</span>
                        My Profile
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <span class="material-symbols-outlined">settings</span>
                        Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../../logout.php" class="dropdown-item danger">
                        <span class="material-symbols-outlined">logout</span>
                        Log Out
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Scrollable Area -->
        <main class="main-scroll" id="mainScroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">analytics</span>
                        <span>Reports & Analytics</span>
                        <span class="status-dot"></span>
                        <span class="ai-badge" style="margin-left:0.5rem; font-size:0.45rem;">
                            <span class="material-symbols-outlined" style="font-size:0.6rem;">auto_awesome</span>
                            AI
                        </span>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <span class="material-symbols-outlined" style="font-size:1rem;">online_prediction</span>
                        <span><?php echo $onlineUsers; ?> online now</span>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($firstName); ?></h1>
                        <p>
                            Comprehensive client and agency performance analytics
                            <span class="ai-badge">
                                <span class="material-symbols-outlined">auto_awesome</span>
                                AI Enhanced
                            </span>
                        </p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-ai" onclick="regenerateAISummary()" id="aiSummaryBtn">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            AI Executive Summary
                        </button>
                        <?php if (($activeTab === 'clients' && $selectedClient > 0) || ($activeTab === 'agencies' && $selectedAgency > 0)): ?>
                        <button class="btn btn-primary" onclick="window.print()">
                            <span class="material-symbols-outlined">print</span>
                            Print Report
                        </button>
                        <a href="?tab=<?php echo $activeTab; ?>&<?php echo $activeTab === 'clients' ? 'client_id' : 'agency_id'; ?>=<?php echo $activeTab === 'clients' ? $selectedClient : $selectedAgency; ?>&export=csv" class="btn btn-success">
                            <span class="material-symbols-outlined">download</span>
                            Export CSV
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- AI Executive Summary Panel -->
                <div class="ai-summary-panel" id="aiSummaryPanel">
                    <div class="summary-header">
                        <div class="title">
                            <span class="material-symbols-outlined" style="font-size:1rem;">auto_awesome</span>
                            AI Executive Summary
                            <span style="font-size:0.55rem; font-weight:400; color:var(--text-on-surface-variant);" id="aiSummaryProvider">
                                <?php echo $aiSummary['provider'] ?? 'AI'; ?>
                            </span>
                        </div>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <span class="health-score" id="aiHealthBadge" style="background:<?php echo ($aiSummary['health_score'] ?? 85) >= 70 ? '#059669' : (($aiSummary['health_score'] ?? 85) >= 40 ? '#d97706' : '#dc2626'); ?>;">
                                <span class="material-symbols-outlined" style="font-size:0.875rem;">favorite</span>
                                <span id="aiHealthScore"><?php echo $aiSummary['health_score'] ?? 85; ?>%</span>
                            </span>
                            <span class="trend-badge" id="aiTrendBadge" style="background:<?php echo getTrendColor($aiSummary['trend_forecast'] ?? 'Stable'); ?>;">
                                <?php echo $aiSummary['trend_forecast'] ?? 'Stable'; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div id="aiSummaryContent">
                        <div class="summary-text" id="aiSummaryText">
                            <?php echo $aiSummary['summary'] ?? 'No summary available.'; ?>
                        </div>
                        
                        <?php if (!empty($aiSummary['insights'])): ?>
                        <div class="summary-insights" id="aiInsightsList">
                            <?php foreach ($aiSummary['insights'] as $insight): ?>
                                <div class="insight-item">
                                    <span class="icon material-symbols-outlined">lightbulb</span>
                                    <span class="text"><?php echo $insight; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($aiSummary['recommendations'])): ?>
                        <div class="summary-recommendations" id="aiRecommendationsList">
                            <?php foreach ($aiSummary['recommendations'] as $rec): ?>
                                <div class="rec-item">
                                    <span class="icon material-symbols-outlined">trending_up</span>
                                    <span><?php echo $rec; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="summary-provider">
                        AI insights powered by <?php echo $aiSummary['provider'] ?? 'ISMERS AI'; ?>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn <?php echo $activeTab === 'clients' ? 'active' : ''; ?>" onclick="switchTab('clients')">
                        <span class="material-symbols-outlined">business</span>
                        Client Reports
                    </button>
                    <button class="tab-btn <?php echo $activeTab === 'agencies' ? 'active' : ''; ?>" onclick="switchTab('agencies')">
                        <span class="material-symbols-outlined">apartment</span>
                        Agency Reports
                    </button>
                </div>

                <!-- =============================================
                TAB 1: CLIENT REPORTS
                ============================================= -->
                <div id="tab-clients" class="tab-content <?php echo $activeTab === 'clients' ? 'active' : ''; ?>">

                    <!-- Client Filter -->
                    <div class="filter-bar">
                        <span class="filter-label">📋 Filter by Client:</span>
                        <form method="GET" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; flex:1;">
                            <input type="hidden" name="tab" value="clients">
                            <select name="client_id" onchange="this.form.submit()">
                                <option value="0">Select a Client...</option>
                                <?php foreach ($allClients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" <?php echo $selectedClient == $client['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selectedClient > 0): ?>
                                <span class="selected-info">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    Showing: <?php echo htmlspecialchars($selectedClientName); ?>
                                </span>
                                <button type="button" class="btn btn-sm btn-ai" onclick="loadClientHealth(<?php echo $selectedClient; ?>)" id="clientHealthBtn">
                                    <span class="material-symbols-outlined" style="font-size:0.875rem;">health_metrics</span>
                                    Health Score
                                </button>
                                <span id="clientHealthScore"></span>
                                <a href="reports.php?tab=clients" class="btn btn-outline btn-sm">
                                    <span class="material-symbols-outlined">close</span>
                                    Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if ($selectedClient > 0): ?>
                        <!-- Show details when client is selected -->
                        
                        <!-- Overview Statistics -->
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">overview</span>
                                    Client Overview
                                </h2>
                            </div>
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <span class="stat-label">Total Clients</span>
                                        <div class="stat-icon blue"><span class="material-symbols-outlined">business</span></div>
                                    </div>
                                    <div class="stat-number"><?php echo $totalClients; ?></div>
                                    <div class="stat-change">Active client companies</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <span class="stat-label">Inactive Clients</span>
                                        <div class="stat-icon red"><span class="material-symbols-outlined">business_off</span></div>
                                    </div>
                                    <div class="stat-number"><?php echo $totalInactiveClients; ?></div>
                                    <div class="stat-change">Inactive accounts</div>
                                </div>
                            </div>
                        </div>

                        <!-- Industry Distribution -->
                        <?php if (!empty($industryDistribution)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">pie_chart</span>
                                    Industry Distribution
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <div class="chart-bar-container">
                                        <?php 
                                        $maxIndustry = $industryDistribution[0]['count'] ?? 1;
                                        foreach ($industryDistribution as $industry):
                                            $percentage = round(($industry['count'] / $maxIndustry) * 100);
                                            $colors = ['#4f46e5', '#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626', '#0891b2', '#db2777'];
                                            $color = $colors[array_rand($colors)];
                                        ?>
                                            <div class="chart-bar-item">
                                                <span class="bar-label"><?php echo htmlspecialchars($industry['industry'] ?: 'Unspecified'); ?></span>
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: <?php echo $percentage; ?>%; background: <?php echo $color; ?>;">
                                                        <?php if ($percentage > 15): ?>
                                                            <?php echo $industry['count']; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="bar-value"><?php echo $industry['count']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- All Clients with Jobs -->
                        <?php if (!empty($clientsWithJobs)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">business</span>
                                    Client Job Summary
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Client job and application summary</h3>
                                    <span class="result-count"><?php echo count($clientsWithJobs); ?> clients</span>
                                </div>
                                <div class="card-body table-body">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Company</th>
                                                <th>Contact</th>
                                                <th>Open Jobs</th>
                                                <th>Filled</th>
                                                <th>Ongoing</th>
                                                <th>Total Jobs</th>
                                                <th>Applications</th>
                                                <th>Health</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($clientsWithJobs as $client): 
                                                $clientHealth = calculateClientHealthScore([
                                                    'total_jobs' => $client['total_jobs'] ?? 0,
                                                    'total_applications' => $client['total_applications'] ?? 0,
                                                    'hire_rate' => $client['total_hires'] > 0 ? round(($client['total_hires'] / max(1, $client['total_applications'])) * 100, 1) : 0,
                                                    'recent_jobs' => 0,
                                                    'recent_applications' => $client['recent_applications'] ?? 0
                                                ]);
                                            ?>
                                                <tr>
                                                    <td><span class="company-name"><?php echo htmlspecialchars($client['company_name']); ?></span></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars($client['contact_person']); ?></td>
                                                    <td><span class="status-badge open"><?php echo $client['open_jobs']; ?></span></td>
                                                    <td><span class="status-badge filled"><?php echo $client['filled_jobs']; ?></span></td>
                                                    <td><span class="status-badge ongoing"><?php echo $client['ongoing_jobs']; ?></span></td>
                                                    <td><strong><?php echo $client['total_jobs']; ?></strong></td>
                                                    <td><?php echo $client['total_applications']; ?></td>
                                                    <td>
                                                        <span style="display:inline-flex; align-items:center; gap:0.25rem; font-weight:700; color:<?php echo $clientHealth['color']; ?>;">
                                                            <?php echo $clientHealth['icon']; ?>
                                                            <?php echo $clientHealth['score']; ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Top Performing Clients -->
                        <?php if (!empty($topClients)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">trending_up</span>
                                    Top Performing Clients
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Clients with most applications</h3>
                                    <span class="result-count">Top 10</span>
                                </div>
                                <div class="card-body table-body">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Company</th>
                                                <th>Industry</th>
                                                <th>Jobs</th>
                                                <th>Applications</th>
                                                <th>Hires</th>
                                                <th>Hire Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topClients as $client): ?>
                                                <tr>
                                                    <td><span class="company-name"><?php echo htmlspecialchars($client['company_name']); ?></span></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars($client['industry'] ?: 'N/A'); ?></td>
                                                    <td><?php echo $client['total_jobs']; ?></td>
                                                    <td><strong><?php echo $client['total_applications']; ?></strong></td>
                                                    <td class="text-success"><?php echo $client['total_hires']; ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $client['hire_rate'] >= 20 ? 'active' : 'inactive'; ?>">
                                                            <?php echo $client['hire_rate'] ?? 0; ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Clients -->
                        <?php if (!empty($recentClients)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">recent</span>
                                    Recent Clients
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Recently registered clients</h3>
                                    <span class="result-count">Last 10</span>
                                </div>
                                <div class="card-body table-body">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Company</th>
                                                <th>Contact</th>
                                                <th>Email</th>
                                                <th>Industry</th>
                                                <th>Status</th>
                                                <th>Joined</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentClients as $client): ?>
                                                <tr>
                                                    <td><span class="company-name"><?php echo htmlspecialchars($client['company_name']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($client['contact_person']); ?></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars($client['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($client['industry'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $client['is_active'] ? 'active' : 'inactive'; ?>">
                                                            <?php echo $client['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-muted"><?php echo date('M d, Y', strtotime($client['created_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- Show prompt to select a client -->
                        <div class="select-prompt">
                            <div class="prompt-icon">
                                <span class="material-symbols-outlined" style="font-size:4rem;">business</span>
                            </div>
                            <h3>Select a Client to View Report</h3>
                            <p>Please choose a client from the dropdown above to see detailed analytics and performance data.</p>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- =============================================
                TAB 2: AGENCY REPORTS
                ============================================= -->
                <div id="tab-agencies" class="tab-content <?php echo $activeTab === 'agencies' ? 'active' : ''; ?>">

                    <!-- Agency Filter -->
                    <div class="filter-bar">
                        <span class="filter-label">📋 Filter by Agency:</span>
                        <form method="GET" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; flex:1;">
                            <input type="hidden" name="tab" value="agencies">
                            <select name="agency_id" onchange="this.form.submit()">
                                <option value="0">Select an Agency...</option>
                                <?php foreach ($allAgencies as $agency): ?>
                                    <option value="<?php echo $agency['id']; ?>" <?php echo $selectedAgency == $agency['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agency['agency_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selectedAgency > 0): ?>
                                <span class="selected-info">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    Showing: <?php echo htmlspecialchars($selectedAgencyName); ?>
                                </span>
                                <a href="reports.php?tab=agencies" class="btn btn-outline btn-sm">
                                    <span class="material-symbols-outlined">close</span>
                                    Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if ($selectedAgency > 0): ?>
                        <!-- Show details when agency is selected -->
                        
                        <!-- Overview Statistics -->
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">overview</span>
                                    Agency Overview
                                </h2>
                            </div>
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <span class="stat-label">Approved Agencies</span>
                                        <div class="stat-icon green"><span class="material-symbols-outlined">check_circle</span></div>
                                    </div>
                                    <div class="stat-number"><?php echo $totalAgencies; ?></div>
                                    <div class="stat-change">Approved agency applications</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <span class="stat-label">Pending Agencies</span>
                                        <div class="stat-icon yellow"><span class="material-symbols-outlined">pending</span></div>
                                    </div>
                                    <div class="stat-number"><?php echo $totalPendingAgencies; ?></div>
                                    <div class="stat-change">Awaiting review</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-header">
                                        <span class="stat-label">Rejected Agencies</span>
                                        <div class="stat-icon red"><span class="material-symbols-outlined">cancel</span></div>
                                    </div>
                                    <div class="stat-number"><?php echo $totalRejectedAgencies; ?></div>
                                    <div class="stat-change">Rejected applications</div>
                                </div>
                            </div>
                        </div>

                        <!-- Agency Status Summary -->
                        <?php if (!empty($agencyStatusSummary)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">pie_chart</span>
                                    Agency Status Distribution
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <div class="chart-bar-container">
                                        <?php 
                                        $maxStatus = $agencyStatusSummary[0]['count'] ?? 1;
                                        foreach ($agencyStatusSummary as $status):
                                            $percentage = round(($status['count'] / $maxStatus) * 100);
                                            $statusColors = [
                                                'pending' => '#f59e0b',
                                                'approved' => '#22c55e',
                                                'rejected' => '#dc2626'
                                            ];
                                            $color = $statusColors[$status['status']] ?? '#6b7280';
                                        ?>
                                            <div class="chart-bar-item">
                                                <span class="bar-label"><?php echo ucfirst($status['status']); ?></span>
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width: <?php echo $percentage; ?>%; background: <?php echo $color; ?>;">
                                                        <?php if ($percentage > 15): ?>
                                                            <?php echo $status['count']; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="bar-value"><?php echo $status['count']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Agencies by Client -->
                        <?php if (!empty($agencyByClient)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">business</span>
                                    Agencies by Client
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Top clients with most agency applications</h3>
                                    <span class="result-count">Top 10</span>
                                </div>
                                <div class="card-body table-body">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Client Company</th>
                                                <th>Total Agencies</th>
                                                <th>Approved</th>
                                                <th>Pending</th>
                                                <th>Rejected</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($agencyByClient as $client): ?>
                                                <tr>
                                                    <td><span class="company-name"><?php echo htmlspecialchars($client['company_name']); ?></span></td>
                                                    <td><strong><?php echo $client['agency_count']; ?></strong></td>
                                                    <td><span class="status-badge approved"><?php echo $client['approved_count']; ?></span></td>
                                                    <td><span class="status-badge pending"><?php echo $client['pending_count']; ?></span></td>
                                                    <td><span class="status-badge rejected"><?php echo $client['rejected_count']; ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- All Agency Applications -->
                        <?php if (!empty($agencyApplications)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">apartment</span>
                                    All Agency Applications
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Complete list of agency applications</h3>
                                    <span class="result-count"><?php echo count($agencyApplications); ?> applications</span>
                                </div>
                                <div class="card-body table-body">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Agency</th>
                                                <th>Code</th>
                                                <th>Contact</th>
                                                <th>Client</th>
                                                <th>Specialization</th>
                                                <th>Status</th>
                                                <th>Submitted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($agencyApplications as $agency): ?>
                                                <tr>
                                                    <td><span class="company-name"><?php echo htmlspecialchars($agency['agency_name']); ?></span></td>
                                                    <td><span class="text-muted"><?php echo htmlspecialchars($agency['agency_code']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($agency['contact_person']); ?></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars($agency['client_company_name'] ?? 'N/A'); ?></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars(substr($agency['specialization'] ?? '', 0, 30)) . (strlen($agency['specialization'] ?? '') > 30 ? '...' : ''); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $agency['status']; ?>">
                                                            <?php echo ucfirst($agency['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-muted"><?php echo date('M d, Y', strtotime($agency['created_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Agency Applications -->
                        <?php if (!empty($recentAgencies)): ?>
                        <div class="report-section">
                            <div class="section-header">
                                <h2>
                                    <span class="material-symbols-outlined">recent</span>
                                    Recent Agency Applications
                                </h2>
                            </div>
                            <div class="card">
                                <div class="card-header">
                                    <h3>Recently submitted agency applications</h3>
                                    <span class="result-count">Last 10</span>
                                </div>
                                <div class="card-body table-body">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Agency</th>
                                                <th>Code</th>
                                                <th>Contact</th>
                                                <th>Email</th>
                                                <th>Status</th>
                                                <th>Submitted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentAgencies as $agency): ?>
                                                <tr>
                                                    <td><span class="company-name"><?php echo htmlspecialchars($agency['agency_name']); ?></span></td>
                                                    <td><span class="text-muted"><?php echo htmlspecialchars($agency['agency_code']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($agency['contact_person']); ?></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars($agency['contact_email']); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $agency['status']; ?>">
                                                            <?php echo ucfirst($agency['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-muted"><?php echo date('M d, Y', strtotime($agency['created_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- Show prompt to select an agency -->
                        <div class="select-prompt">
                            <div class="prompt-icon">
                                <span class="material-symbols-outlined" style="font-size:4rem;">apartment</span>
                            </div>
                            <h3>Select an Agency to View Report</h3>
                            <p>Please choose an agency from the dropdown above to see detailed analytics and performance data.</p>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- =============================================
                EMPTY STATE
                ============================================= -->
                <?php if (empty($clientsWithJobs) && empty($agencyApplications) && $selectedClient == 0 && $selectedAgency == 0): ?>
                <div class="report-section">
                    <div class="card">
                        <div class="empty-state">
                            <div class="empty-icon">
                                <span class="material-symbols-outlined" style="font-size:3rem;">analytics</span>
                            </div>
                            <h4>No Data Available</h4>
                            <p>Start adding clients and agency applications to see reports here.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
            const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');

            const savedState = localStorage.getItem('sidebarCollapsed');
            const isDesktop = window.innerWidth >= 768;

            if (savedState === 'true' && isDesktop) {
                sidebar.classList.add('collapsed');
                sidebarToggleIcon.textContent = 'menu';
            }

            sidebarToggleBtn.addEventListener('click', function() {
                if (window.innerWidth < 768) return;
                sidebar.classList.toggle('collapsed');
                const isCollapsed = sidebar.classList.contains('collapsed');
                sidebarToggleIcon.textContent = isCollapsed ? 'menu' : 'menu_open';
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

            mobileMenuBtn.addEventListener('click', openMobileSidebar);
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);

            document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                });
            });

            // =============================================
            // 3. PROFILE DROPDOWN TOGGLE
            // =============================================
            const profileToggle = document.getElementById('profileDropdownToggle');
            const profileMenu = document.getElementById('profileDropdownMenu');

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

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    profileToggle.classList.remove('open');
                    profileMenu.classList.remove('open');
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
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

                    if (width >= 768) {
                        closeMobileSidebar();
                        sidebar.classList.remove('mobile-open', 'mobile-hidden');
                        const saved = localStorage.getItem('sidebarCollapsed');
                        if (saved === 'true') {
                            sidebar.classList.add('collapsed');
                            sidebarToggleIcon.textContent = 'menu';
                        } else {
                            sidebar.classList.remove('collapsed');
                            sidebarToggleIcon.textContent = 'menu_open';
                        }
                    } else {
                        sidebar.classList.add('mobile-hidden');
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'menu_open';
                    }
                }, 250);
            });

            // =============================================
            // 5. TAB SWITCHING
            // =============================================
            window.switchTab = function(tab) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                if (tab === 'clients') {
                    url.searchParams.delete('agency_id');
                } else {
                    url.searchParams.delete('client_id');
                }
                window.location.href = url.toString();
            };

            // =============================================
            // 6. REGENERATE AI SUMMARY
            // =============================================
            function regenerateAISummary() {
                const btn = document.getElementById('aiSummaryBtn');
                const panel = document.getElementById('aiSummaryPanel');
                const content = document.getElementById('aiSummaryContent');
                
                btn.disabled = true;
                btn.innerHTML = '<span class="ai-dots-loading-sm"><div class="dot"></div><div class="dot"></div><div class="dot"></div></span> Generating...';
                
                // Show loading state
                content.innerHTML = `
                    <div style="text-align:center; padding:1.5rem 0;">
                        <div class="ai-dots-loading-sm" style="justify-content:center; gap:0.5rem;">
                            <div class="dot" style="width:0.75rem; height:0.75rem;"></div>
                            <div class="dot" style="width:0.75rem; height:0.75rem;"></div>
                            <div class="dot" style="width:0.75rem; height:0.75rem;"></div>
                            <span style="font-size:0.875rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">Generating AI insights...</span>
                        </div>
                    </div>
                `;

                const formData = new FormData();
                formData.append('action', 'get_ai_summary');

                fetch('reports.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> AI Executive Summary';
                    
                    if (data.success) {
                        // Update provider
                        document.getElementById('aiSummaryProvider').textContent = data.provider || 'AI';
                        
                        // Update health badge
                        const healthBadge = document.getElementById('aiHealthBadge');
                        const healthScore = data.health_score || 85;
                        healthBadge.innerHTML = `<span class="material-symbols-outlined" style="font-size:0.875rem;">favorite</span> ${healthScore}%`;
                        healthBadge.style.background = healthScore >= 70 ? '#059669' : healthScore >= 40 ? '#d97706' : '#dc2626';
                        
                        // Update trend badge
                        const trendBadge = document.getElementById('aiTrendBadge');
                        const trendColors = {
                            'Growing': '#059669',
                            'Stable': '#2563eb',
                            'Needs Attention': '#d97706',
                            'Declining': '#dc2626'
                        };
                        trendBadge.textContent = data.trend_forecast || 'Stable';
                        trendBadge.style.background = trendColors[data.trend_forecast] || '#6b7280';
                        
                        // Build content
                        let html = `
                            <div class="summary-text">${data.summary || 'No summary available.'}</div>
                        `;
                        
                        if (data.insights && data.insights.length > 0) {
                            html += `<div class="summary-insights">`;
                            data.insights.forEach(insight => {
                                html += `
                                    <div class="insight-item">
                                        <span class="icon material-symbols-outlined">lightbulb</span>
                                        <span class="text">${insight}</span>
                                    </div>
                                `;
                            });
                            html += `</div>`;
                        }
                        
                        if (data.recommendations && data.recommendations.length > 0) {
                            html += `<div class="summary-recommendations">`;
                            data.recommendations.forEach(rec => {
                                html += `
                                    <div class="rec-item">
                                        <span class="icon material-symbols-outlined">trending_up</span>
                                        <span>${rec}</span>
                                    </div>
                                `;
                            });
                            html += `</div>`;
                        }
                        
                        content.innerHTML = html;
                        showToast('✨ AI summary regenerated successfully!', 'success');
                    } else {
                        content.innerHTML = `<div style="color:#dc2626; padding:0.5rem;">${data.error || 'Could not generate AI summary'}</div>`;
                        showToast(data.error || 'Could not generate AI summary', 'error');
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> AI Executive Summary';
                    content.innerHTML = `<div style="color:#dc2626; padding:0.5rem;">Network error. Please try again.</div>`;
                    showToast('Network error. Please try again.', 'error');
                });
            }

            // =============================================
            // 7. LOAD CLIENT HEALTH SCORE
            // =============================================
            function loadClientHealth(clientId) {
                const btn = document.getElementById('clientHealthBtn');
                const scoreSpan = document.getElementById('clientHealthScore');
                
                btn.disabled = true;
                btn.innerHTML = '<span class="ai-dots-loading-sm" style="display:inline-flex; gap:0.125rem; padding:0;"><div class="dot"></div><div class="dot"></div><div class="dot"></div></span>';
                
                const formData = new FormData();
                formData.append('action', 'get_client_health');
                formData.append('client_id', clientId);

                fetch('reports.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.875rem;">health_metrics</span> Health Score';
                    
                    if (data.success) {
                        const health = data.data;
                        const score = health.score || 0;
                        const status = health.status || 'Unknown';
                        const color = health.color || '#6b7280';
                        const icon = health.icon || '📊';
                        
                        scoreSpan.innerHTML = `
                            <span style="display:inline-flex; align-items:center; gap:0.375rem; padding:0.125rem 0.625rem; border-radius:var(--radius-full); font-weight:700; font-size:0.75rem; background:${color}20; color:${color};">
                                ${icon} ${score}% (${status})
                            </span>
                        `;
                        
                        showToast(`Client health score: ${score}% (${status})`, 'info');
                    } else {
                        scoreSpan.innerHTML = `<span style="color:#dc2626; font-size:0.75rem;">${data.error || 'Error loading health'}</span>`;
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.875rem;">health_metrics</span> Health Score';
                    scoreSpan.innerHTML = `<span style="color:#dc2626; font-size:0.75rem;">Network error</span>`;
                });
            }

            // =============================================
            // 8. TOAST SYSTEM
            // =============================================
            function showToast(message, type) {
                type = type || 'info';
                const existingToast = document.querySelector('.toast');
                if (existingToast) existingToast.remove();

                const toast = document.createElement('div');
                toast.className = 'toast ' + type;
                const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info' };
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
            // 9. INITIAL STATE
            // =============================================
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            console.log('📊 AI-Powered ISMERS Reports loaded successfully!');
            console.log('🤖 AI Features: Executive Summary, Client Health Scores, Trend Analysis');
        })();
    </script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>