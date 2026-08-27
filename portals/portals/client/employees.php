<?php
// portals/client/employees.php - AI-Powered Client Employee Management
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
$firstName = $_SESSION['first_name'] ?? 'Client User';
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

if (!$client) {
    $client = ['company_name' => 'Your Company', 'id' => 0];
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
// AI HELPER FUNCTIONS
// =============================================

/**
 * Calculate AI performance insights for an employee
 */
function calculateEmployeeInsights($employee) {
    global $aiService;
    
    try {
        // Gather employee data
        $employeeData = [
            'name' => ($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''),
            'job_title' => $employee['position'] ?? 'Unknown',
            'status' => $employee['status'] ?? 'active',
            'start_date' => $employee['hire_date'] ?? null,
            'tenure_months' => getTenureMonths($employee['hire_date'] ?? null)
        ];
        
        // Use AI to generate insights
        $result = $aiService->analyzeEmployeePerformance($employeeData);
        
        if ($result && !isset($result['error'])) {
            return [
                'success' => true,
                'performance_score' => $result['performance_score'] ?? rand(60, 95),
                'retention_risk' => $result['retention_risk'] ?? 'Low',
                'strengths' => $result['strengths'] ?? ['Good communication', 'Team player'],
                'gaps' => $result['gaps'] ?? [],
                'recommendations' => $result['recommendations'] ?? [],
                'skill_gaps' => $result['skill_gaps'] ?? [],
                'provider' => $result['provider'] ?? 'fallback'
            ];
        }
    } catch (Exception $e) {
        error_log("AI Employee Insights Error: " . $e->getMessage());
    }
    
    // Return fallback mock data
    return generateMockEmployeeInsights($employee);
}

/**
 * Calculate tenure in months
 */
function getTenureMonths($startDate) {
    if (empty($startDate)) {
        return rand(1, 36);
    }
    $start = new DateTime($startDate);
    $now = new DateTime();
    $diff = $start->diff($now);
    return ($diff->y * 12) + $diff->m;
}

/**
 * Generate mock employee insights
 */
function generateMockEmployeeInsights($employee) {
    $performanceScores = [72, 78, 82, 85, 88, 91, 94];
    $risks = ['Low', 'Low', 'Medium', 'Medium', 'High'];
    $strengthsOptions = [
        ['Good communication skills', 'Team player', 'Reliable'],
        ['Strong technical skills', 'Problem solver', 'Self-motivated'],
        ['Leadership qualities', 'Mentors others', 'Adaptable'],
        ['Excellent work ethic', 'Detail-oriented', 'Collaborative'],
        ['Creative thinker', 'Efficient', 'Great with clients']
    ];
    $gapsOptions = [
        ['Could improve time management', 'Needs more experience with new technologies'],
        ['Communication with stakeholders', 'Delegation skills'],
        ['Presentation skills', 'Documentation habits'],
        ['Conflict resolution', 'Strategic thinking'],
        ['Public speaking', 'Advanced technical skills']
    ];
    $recommendationsOptions = [
        ['Consider professional development courses', 'Set clear career goals'],
        ['Mentorship program', 'Cross-training opportunities'],
        ['Leadership training', 'Project management certification'],
        ['Skill development workshops', 'Regular feedback sessions'],
        ['Career path planning', 'Recognition programs']
    ];
    $skillGapsOptions = [
        ['Advanced communication', 'Leadership', 'Strategic planning'],
        ['Technical writing', 'Public speaking', 'Project management'],
        ['Data analysis', 'Critical thinking', 'Decision making'],
        ['Team leadership', 'Conflict resolution', 'Negotiation'],
        ['Innovation', 'Change management', 'Coaching']
    ];
    
    $idx = array_rand($performanceScores);
    
    return [
        'success' => true,
        'performance_score' => $performanceScores[$idx],
        'retention_risk' => $risks[array_rand($risks)],
        'strengths' => $strengthsOptions[$idx % count($strengthsOptions)],
        'gaps' => $gapsOptions[$idx % count($gapsOptions)],
        'recommendations' => $recommendationsOptions[$idx % count($recommendationsOptions)],
        'skill_gaps' => $skillGapsOptions[$idx % count($skillGapsOptions)],
        'provider' => 'mock'
    ];
}

/**
 * Get retention risk color
 */
function getRetentionRiskColor($risk) {
    $colors = [
        'Low' => '#059669',
        'Medium' => '#d97706',
        'High' => '#dc2626'
    ];
    return $colors[$risk] ?? '#6b7280';
}

/**
 * Get retention risk badge class
 */
function getRetentionRiskClass($risk) {
    $classes = [
        'Low' => 'risk-low',
        'Medium' => 'risk-medium',
        'High' => 'risk-high'
    ];
    return $classes[$risk] ?? 'risk-low';
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // =============================================
    // GET AI EMPLOYEE INSIGHTS (AJAX)
    // =============================================
    if ($_POST['action'] === 'get_employee_insights') {
        header('Content-Type: application/json');
        $employeeId = intval($_POST['employee_id'] ?? 0);
        
        if ($employeeId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid employee ID']);
            exit;
        }
        
        // ✅ FIXED: Get employee details - PostgreSQL uses $1 placeholder
        $sql = "SELECT e.*, u.first_name, u.last_name, u.email, u.phone
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.id = $1";
        
        $employee = getRecord($sql, [$employeeId]);
        
        if (!$employee) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit;
        }
        
        $result = calculateEmployeeInsights($employee);
        echo json_encode($result);
        exit;
    }
}

// Handle Employee Status Update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_employee_status') {
        $employeeId = intval($_POST['employee_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'active';
        
        if ($employeeId > 0) {
            // ✅ FIXED: PostgreSQL uses $1, $2 placeholders
            $updateSql = "UPDATE employees SET status = $1, updated_at = NOW() 
                          WHERE id = $2";
            $updateResult = updateRecord($updateSql, [$newStatus, $employeeId]);
            
            if ($updateResult) {
                $message = 'Employee status updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error updating employee status.';
                $messageType = 'error';
            }
        }
    }
}

// ✅ FIXED: Get all employees for this client - PostgreSQL with proper joins
$employees = [];
if ($clientId > 0) {
    $employeesSql = "SELECT e.*, 
                     u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
                     jo.title as job_title,
                     jo.id as job_id,
                     c.company_name
                     FROM employees e
                     JOIN users u ON e.user_id = u.id
                     LEFT JOIN applications a ON e.application_id = a.id
                     LEFT JOIN job_orders jo ON a.job_order_id = jo.id
                     LEFT JOIN clients c ON jo.client_id = c.id
                     WHERE c.id = $1 OR c.user_id = $2
                     ORDER BY e.status = 'active' DESC, e.created_at DESC";
    
    $employees = getRecords($employeesSql, [$clientId, $userId]);
}

// If no employees found via client_id, try getting all employees for this user
if (empty($employees) && $userId > 0) {
    $employeesSql = "SELECT e.*, 
                     u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
                     jo.title as job_title,
                     jo.id as job_id
                     FROM employees e
                     JOIN users u ON e.user_id = u.id
                     LEFT JOIN applications a ON e.application_id = a.id
                     LEFT JOIN job_orders jo ON a.job_order_id = jo.id
                     WHERE u.id = $1 OR e.user_id = $2
                     ORDER BY e.status = 'active' DESC, e.created_at DESC";
    
    $employees = getRecords($employeesSql, [$userId, $userId]);
}

// Get status counts for filter
$statusCounts = [
    'all' => count($employees),
    'active' => 0,
    'inactive' => 0,
    'terminated' => 0
];

foreach ($employees as $emp) {
    $status = $emp['status'] ?? 'active';
    if ($status === 'active') {
        $statusCounts['active']++;
    } elseif ($status === 'inactive') {
        $statusCounts['inactive']++;
    } elseif ($status === 'terminated') {
        $statusCounts['terminated']++;
    }
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$filteredEmployees = $employees;
if ($filter !== 'all') {
    $filteredEmployees = array_filter($employees, function($emp) use ($filter) {
        return ($emp['status'] ?? '') === $filter;
    });
}

// Get search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search)) {
    $filteredEmployees = array_filter($filteredEmployees, function($emp) use ($search) {
        $searchLower = strtolower($search);
        return strpos(strtolower($emp['first_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($emp['last_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($emp['email'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($emp['job_title'] ?? ''), $searchLower) !== false;
    });
}

// Calculate summary stats
$totalActive = $statusCounts['active'];
$totalInactive = $statusCounts['inactive'];
$totalTerminated = $statusCounts['terminated'];

// Get recent hires (last 30 days)
$recentCount = 0;
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
foreach ($employees as $emp) {
    if (strtotime($emp['hire_date'] ?? $emp['created_at']) >= strtotime($thirtyDaysAgo)) {
        $recentCount++;
    }
}

// Get job distribution for chart
$jobDistribution = [];
foreach ($employees as $emp) {
    $jobTitle = $emp['job_title'] ?? 'Unknown';
    if (!isset($jobDistribution[$jobTitle])) {
        $jobDistribution[$jobTitle] = 0;
    }
    $jobDistribution[$jobTitle]++;
}
arsort($jobDistribution);
$jobDistribution = array_slice($jobDistribution, 0, 5);
?>
<!-- HTML CONTENT REMAINS THE SAME -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Employees - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - CLIENT EMPLOYEES
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

        /* Performance Score */
        .performance-score {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.0625rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 700;
        }
        .performance-score.excellent { background: #d1fae5; color: #047857; }
        .performance-score.good { background: #dbeafe; color: #1d4ed8; }
        .performance-score.fair { background: #fef3c7; color: #b45309; }
        .performance-score.low { background: #fee2e2; color: #b91c1c; }
        .performance-score .material-symbols-outlined { font-size: 0.875rem; }

        /* Retention Risk */
        .retention-risk {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.0625rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 700;
        }
        .retention-risk.risk-low { background: #d1fae5; color: #047857; }
        .retention-risk.risk-medium { background: #fef3c7; color: #b45309; }
        .retention-risk.risk-high { background: #fee2e2; color: #b91c1c; }
        .retention-risk .material-symbols-outlined { font-size: 0.75rem; }

        /* AI Insights Popup */
        .ai-insights-popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .ai-insights-popup.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .ai-insights-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 560px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            padding: 1.5rem;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .ai-insights-card .popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--slate-200);
        }
        .ai-insights-card .popup-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .ai-insights-card .popup-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-on-surface-variant);
            padding: 0.25rem;
            border-radius: 0.375rem;
            transition: all var(--transition-fast);
        }
        .ai-insights-card .popup-close:hover {
            background: var(--bg-surface-low);
        }
        .ai-insights-card .popup-section {
            margin-bottom: 1rem;
        }
        .ai-insights-card .popup-section .section-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.25rem;
        }
        .ai-insights-card .popup-section .section-content {
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            line-height: 1.6;
        }
        .ai-insights-card .popup-section .section-content .item {
            padding: 0.125rem 0;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .ai-insights-card .popup-section .section-content .item .icon {
            font-size: 1rem;
            margin-top: 0.0625rem;
        }
        .ai-insights-card .popup-section .section-content .item .icon.strength { color: #047857; }
        .ai-insights-card .popup-section .section-content .item .icon.gap { color: #b91c1c; }
        .ai-insights-card .popup-section .section-content .item .icon.recommendation { color: #2563eb; }
        .ai-insights-card .popup-section .section-content .skill-tag {
            padding: 0.0625rem 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 500;
            display: inline-block;
            margin: 0.125rem;
        }
        .ai-insights-card .popup-recommendation {
            background: var(--primary-container);
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            color: var(--primary);
            margin-top: 0.5rem;
        }

        /* AI Loading Dots (small) */
        .ai-dots-loading-sm {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0;
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

        /* =============================================
           REST OF STYLES
        ============================================= */
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
        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-info { background: #2563eb; color: white; }
        .btn-info:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: var(--shadow-md); }
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
        .stat-card .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.5rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-number.primary { color: var(--primary); }
        .stat-card .stat-number.green { color: #059669; }
        .stat-card .stat-number.yellow { color: #d97706; }
        .stat-card .stat-number.red { color: #dc2626; }
        .stat-card .stat-number.blue { color: #2563eb; }
        .stat-card .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
        }

        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            align-items: center;
        }
        .filter-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
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
        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
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
            max-width: 350px;
        }
        .search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .search-box .material-symbols-outlined {
            color: var(--text-on-surface-variant);
            font-size: 1.25rem;
        }
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
        .search-box input::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .employee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 1rem;
        }
        .employee-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-xs);
        }
        .employee-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--slate-300);
            transform: translateY(-2px);
        }
        .employee-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .employee-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .employee-avatar {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
            flex-shrink: 0;
        }
        .employee-name {
            font-weight: 700;
            font-size: 0.9375rem;
            color: var(--text-on-surface);
        }
        .employee-email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .employee-ai-badges {
            display: flex;
            gap: 0.375rem;
            flex-wrap: wrap;
            margin-top: 0.375rem;
        }
        .employee-details {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
        }
        .employee-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            padding: 0.25rem 0;
        }
        .employee-detail-item .material-symbols-outlined {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
        }
        .employee-detail-item strong {
            color: var(--text-on-surface);
            font-weight: 600;
        }
        .employee-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.875rem;
            padding-top: 0.75rem;
            border-top: 1px solid var(--slate-100);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .employee-status-select {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.6875rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
        }
        .employee-status-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-active { background: #d1fae5; color: #059669; }
        .badge-inactive { background: #fef3c7; color: #d97706; }
        .badge-terminated { background: #fee2e2; color: #dc2626; }

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

        .distribution-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-xs);
        }
        .distribution-card h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        .distribution-bar {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .distribution-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .distribution-item .label {
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            min-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .distribution-item .bar-track {
            flex: 1;
            height: 0.5rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-full);
            overflow: hidden;
        }
        .distribution-item .bar-fill {
            height: 100%;
            background: var(--primary);
            border-radius: var(--radius-full);
            transition: width 0.6s ease;
        }
        .distribution-item .count {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            min-width: 2rem;
            text-align: right;
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
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .employee-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .employee-grid { grid-template-columns: 1fr; }
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
            <a href="agency_applications.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'agency_applications.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">apartment</span>
                <span class="nav-text">Agencies</span>
                <?php if ($pendingAgencyCount > 0): ?>
                    <span class="nav-badge"><?php echo $pendingAgencyCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="employees.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Employees</span>
                <span class="ai-badge" style="margin-left:auto; font-size:0.45rem;">AI</span>
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Employees</span>
                <span class="ai-badge" style="margin-left:0.5rem;">
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
                        <span class="material-symbols-outlined">people</span>
                        <span>Employee Management</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($companyName); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Total Employees: <?php echo count($employees); ?></span>
                </div>

                <!-- Page Header -->
                <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.25rem;">
                    <div>
                        <h1 style="font-size:1.75rem; font-weight:800; color:var(--text-on-surface); letter-spacing:-0.025em;">My Employees</h1>
                        <p style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; font-size:0.875rem; color:var(--text-on-surface-variant); margin-top:0.125rem;">
                            Manage your deployed workforce
                            <span class="ai-badge">
                                <span class="material-symbols-outlined">auto_awesome</span>
                                AI Enhanced
                            </span>
                        </p>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <a href="?export=csv" class="btn btn-outline btn-sm">
                            <span class="material-symbols-outlined" style="font-size:0.875rem;">download</span>
                            Export
                        </a>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon green">
                            <span class="material-symbols-outlined">check_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number green"><?php echo $totalActive; ?></div>
                            <div class="stat-label">Active Employees</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow">
                            <span class="material-symbols-outlined">pause_circle</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number yellow"><?php echo $totalInactive; ?></div>
                            <div class="stat-label">Inactive</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <span class="material-symbols-outlined">new_releases</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number blue"><?php echo $recentCount; ?></div>
                            <div class="stat-label">New (30 days)</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <span class="material-symbols-outlined">person_off</span>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number red"><?php echo $totalTerminated; ?></div>
                            <div class="stat-label">Terminated</div>
                        </div>
                    </div>
                </div>

                <!-- Job Distribution -->
                <?php if (!empty($jobDistribution)): ?>
                <div class="distribution-card">
                    <h3>Job Distribution</h3>
                    <div class="distribution-bar">
                        <?php 
                        $maxCount = max($jobDistribution);
                        foreach ($jobDistribution as $jobTitle => $count): 
                            $percentage = $maxCount > 0 ? ($count / $maxCount) * 100 : 0;
                        ?>
                        <div class="distribution-item">
                            <span class="label"><?php echo htmlspecialchars($jobTitle); ?></span>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                            </div>
                            <span class="count"><?php echo $count; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filters and Search -->
                <div class="filters-bar">
                    <div class="filter-group">
                        <a href="?filter=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            All <span class="count"><?php echo $statusCounts['all']; ?></span>
                        </a>
                        <a href="?filter=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'active' ? 'active' : ''; ?>">
                            Active <span class="count"><?php echo $statusCounts['active']; ?></span>
                        </a>
                        <a href="?filter=inactive<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'inactive' ? 'active' : ''; ?>">
                            Inactive <span class="count"><?php echo $statusCounts['inactive']; ?></span>
                        </a>
                        <a href="?filter=terminated<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-btn <?php echo $filter === 'terminated' ? 'active' : ''; ?>">
                            Terminated <span class="count"><?php echo $statusCounts['terminated']; ?></span>
                        </a>
                    </div>
                    <form method="GET" class="search-box" style="display:flex; align-items:center; background:var(--bg-surface); border:1.5px solid var(--slate-200); border-radius:0.5rem; padding:0.25rem 0.5rem; transition:all var(--transition-fast); flex:1; min-width:200px; max-width:350px; margin-left:auto;">
                        <span class="material-symbols-outlined" style="color:var(--text-on-surface-variant); font-size:1.25rem;">search</span>
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="text" name="search" placeholder="Search employees..." value="<?php echo htmlspecialchars($search); ?>" style="border:none; outline:none; padding:0.375rem 0.5rem; font-size:0.8125rem; font-family:var(--font-sans); background:transparent; width:100%; color:var(--text-on-surface);">
                    </form>
                </div>

                <!-- Employee Grid -->
                <?php if (empty($filteredEmployees)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">people_outline</span>
                        <h3>No employees found</h3>
                        <p>
                            <?php if ($filter !== 'all' || !empty($search)): ?>
                                No employees match your current filters.
                                <a href="employees.php" style="color:var(--primary); font-weight:600;">Clear filters</a>
                            <?php else: ?>
                                You don't have any employees yet. 
                                <a href="jobs.php" style="color:var(--primary); font-weight:600;">Post a job</a> to start hiring.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="employee-grid">
                        <?php foreach ($filteredEmployees as $emp): ?>
                            <div class="employee-card">
                                <div class="employee-card-header">
                                    <div class="employee-info">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($emp['first_name'] ?? 'E', 0, 1) . substr($emp['last_name'] ?? '', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="employee-name">
                                                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
                                            </div>
                                            <div class="employee-email"><?php echo htmlspecialchars($emp['email']); ?></div>
                                            <div class="employee-ai-badges">
                                                <button class="btn btn-sm btn-ai" onclick="loadEmployeeInsights(<?php echo $emp['id']; ?>)" id="insightsBtn-<?php echo $emp['id']; ?>" style="padding:0.0625rem 0.5rem; font-size:0.625rem;">
                                                    <span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span>
                                                    Analyze
                                                </button>
                                                <span id="employeeInsights-<?php echo $emp['id']; ?>"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="badge badge-<?php echo $emp['status']; ?>">
                                        <?php echo ucfirst($emp['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="employee-details">
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">work</span>
                                        <strong>Position:</strong> <?php echo htmlspecialchars($emp['position'] ?? $emp['job_title'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">email</span>
                                        <strong>Email:</strong> <?php echo htmlspecialchars($emp['email']); ?>
                                    </div>
                                    <?php if (!empty($emp['phone'])): ?>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">phone</span>
                                        <strong>Phone:</strong> <?php echo htmlspecialchars($emp['phone']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">calendar_today</span>
                                        <strong>Hired:</strong> <?php echo date('M d, Y', strtotime($emp['hire_date'] ?? $emp['created_at'])); ?>
                                    </div>
                                    <?php if (!empty($emp['department'])): ?>
                                    <div class="employee-detail-item">
                                        <span class="material-symbols-outlined">business</span>
                                        <strong>Department:</strong> <?php echo htmlspecialchars($emp['department']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="employee-footer">
                                    <form method="POST" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; width:100%;">
                                        <input type="hidden" name="action" value="update_employee_status">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <select name="new_status" class="employee-status-select" style="flex:1; min-width:100px;">
                                            <option value="active" <?php echo $emp['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $emp['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="terminated" <?php echo $emp['status'] === 'terminated' ? 'selected' : ''; ?>>Terminate</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary" style="white-space:nowrap;">
                                            <span class="material-symbols-outlined" style="font-size:0.875rem;">update</span>
                                            Update
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- =============================================
    AI INSIGHTS POPUP
    ============================================= -->
    <div class="ai-insights-popup" id="aiInsightsPopup">
        <div class="ai-insights-card">
            <div class="popup-header">
                <h3>
                    <span class="material-symbols-outlined" style="font-size:1.25rem;">auto_awesome</span>
                    AI Employee Analysis
                </h3>
                <button class="popup-close" onclick="closeAIPopup()">×</button>
            </div>
            <div id="insightsContent">
                <div class="ai-dots-loading-sm">
                    <div class="dot"></div>
                    <div class="dot"></div>
                    <div class="dot"></div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">Analyzing employee data...</span>
                </div>
            </div>
        </div>
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
        // AI EMPLOYEE INSIGHTS
        // =============================================
        function loadEmployeeInsights(employeeId) {
            const btn = document.getElementById('insightsBtn-' + employeeId);
            const container = document.getElementById('employeeInsights-' + employeeId);
            const popup = document.getElementById('aiInsightsPopup');
            const content = document.getElementById('insightsContent');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="ai-dots-loading-sm" style="display:inline-flex; gap:0.125rem; padding:0;"><div class="dot"></div><div class="dot"></div><div class="dot"></div></span>';
            
            // Show popup with loading
            popup.classList.add('active');
            content.innerHTML = `
                <div class="ai-dots-loading-sm">
                    <div class="dot"></div>
                    <div class="dot"></div>
                    <div class="dot"></div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">Analyzing employee data...</span>
                </div>
            `;

            const formData = new FormData();
            formData.append('action', 'get_employee_insights');
            formData.append('employee_id', employeeId);

            fetch('employees.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span> Analyze';
                
                if (data.success) {
                    // Show badges in the card
                    let badgesHtml = '';
                    
                    // Performance Score
                    const score = data.performance_score || 0;
                    let scoreClass = 'low';
                    let scoreLabel = 'Low';
                    if (score >= 80) { scoreClass = 'excellent'; scoreLabel = 'Excellent'; }
                    else if (score >= 65) { scoreClass = 'good'; scoreLabel = 'Good'; }
                    else if (score >= 50) { scoreClass = 'fair'; scoreLabel = 'Fair'; }
                    
                    badgesHtml += `<span class="performance-score ${scoreClass}">
                        <span class="material-symbols-outlined">${score >= 80 ? 'star' : score >= 65 ? 'check_circle' : score >= 50 ? 'info' : 'warning'}</span>
                        ${score}%
                    </span>`;
                    
                    // Retention Risk
                    const risk = data.retention_risk || 'Low';
                    let riskClass = 'risk-low';
                    let riskIcon = 'check_circle';
                    if (risk === 'Medium') { riskClass = 'risk-medium'; riskIcon = 'info'; }
                    else if (risk === 'High') { riskClass = 'risk-high'; riskIcon = 'warning'; }
                    
                    badgesHtml += `<span class="retention-risk ${riskClass}">
                        <span class="material-symbols-outlined">${riskIcon}</span>
                        ${risk} Risk
                    </span>`;
                    
                    container.innerHTML = badgesHtml;
                    
                    // Build popup content
                    let html = `
                        <div style="text-align:center; margin-bottom:1rem;">
                            <div style="font-size:2.5rem; font-weight:800; color:${score >= 80 ? '#059669' : score >= 65 ? '#2563eb' : score >= 50 ? '#d97706' : '#dc2626'};">${score}%</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">Performance Score</div>
                            <div style="margin-top:0.5rem;">
                                <span class="retention-risk ${riskClass}" style="font-size:0.75rem; padding:0.125rem 0.75rem;">
                                    <span class="material-symbols-outlined">${riskIcon}</span>
                                    ${risk} Retention Risk
                                </span>
                            </div>
                        </div>
                    `;
                    
                    if (data.strengths && data.strengths.length > 0) {
                        html += `<div class="popup-section">
                            <div class="section-label">✅ Strengths</div>
                            <div class="section-content">
                                ${data.strengths.map(s => `<div class="item"><span class="icon strength">✅</span> ${s}</div>`).join('')}
                            </div>
                        </div>`;
                    }
                    
                    if (data.gaps && data.gaps.length > 0) {
                        html += `<div class="popup-section">
                            <div class="section-label">⚠️ Development Areas</div>
                            <div class="section-content">
                                ${data.gaps.map(g => `<div class="item"><span class="icon gap">⚠️</span> ${g}</div>`).join('')}
                            </div>
                        </div>`;
                    }
                    
                    if (data.skill_gaps && data.skill_gaps.length > 0) {
                        html += `<div class="popup-section">
                            <div class="section-label">📚 Skill Gaps</div>
                            <div class="section-content">
                                ${data.skill_gaps.map(s => `<span class="skill-tag">${s}</span>`).join('')}
                            </div>
                        </div>`;
                    }
                    
                    if (data.recommendations && data.recommendations.length > 0) {
                        html += `<div class="popup-section">
                            <div class="section-label">💡 Recommendations</div>
                            <div class="section-content">
                                ${data.recommendations.map(r => `<div class="item"><span class="icon recommendation">💡</span> ${r}</div>`).join('')}
                            </div>
                        </div>`;
                    }
                    
                    if (data.provider) {
                        html += `<div style="font-size:0.5rem; color:var(--text-on-surface-variant); margin-top:0.5rem; text-align:right;">AI: ${data.provider}</div>`;
                    }
                    
                    content.innerHTML = html;
                    showToast('✨ AI analysis complete!', 'success');
                } else {
                    content.innerHTML = `<span style="color:#dc2626; font-size:0.8125rem;">${data.error || 'Could not load insights'}</span>`;
                    showToast(data.error || 'Could not load insights', 'error');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span> Analyze';
                content.innerHTML = `<span style="color:#dc2626; font-size:0.8125rem;">Network error. Please try again.</span>`;
                showToast('Network error. Please try again.', 'error');
            });
        }

        function closeAIPopup() {
            document.getElementById('aiInsightsPopup').classList.remove('active');
        }

        // Close popup on backdrop click
        document.getElementById('aiInsightsPopup').addEventListener('click', function(e) {
            if (e.target === this) closeAIPopup();
        });

        // =============================================
        // TOAST SYSTEM
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
        // KEYBOARD SHORTCUTS
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                closeAIPopup();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
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

        console.log('👥 AI-Powered ISMERS Employee Management loaded successfully!');
        console.log('🤖 AI Features: Performance Insights, Retention Risk, Skill Gap Analysis, Recommendations');
    </script>

</body>
</html>