<?php
// portals/client/job-details.php - AI-Powered Job Details & Applicant Management
session_start();

require_once '../../app/config.php';
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

// Get job ID from URL
$jobId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($jobId <= 0) {
    header('Location: jobs.php');
    exit;
}

// Get job details with verification that it belongs to this client
$jobSql = "SELECT j.*, 
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id) as total_applicants,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id AND status = 'pending') as pending_applicants,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id AND status = 'reviewed') as reviewed_applicants,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id AND status = 'shortlisted') as shortlisted_applicants,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id AND status = 'hired') as hired_applicants,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id AND status = 'rejected') as rejected_applicants
           FROM job_orders j
           WHERE j.id = ? AND j.client_id = ?";

$stmt = mysqli_prepare($conn, $jobSql);
mysqli_stmt_bind_param($stmt, 'ii', $jobId, $clientId);
mysqli_stmt_execute($stmt);
$jobResult = mysqli_stmt_get_result($stmt);
$job = mysqli_fetch_assoc($jobResult);
mysqli_stmt_close($stmt);

// If job doesn't exist or doesn't belong to this client
if (!$job) {
    header('Location: jobs.php');
    exit;
}

// Parse skills from job
$skillsData = json_decode($job['skills_required'] ?? '{}', true);
$jobSkills = $skillsData['skills'] ?? [];
$jobQualifications = $skillsData['qualifications'] ?? [];
$jobExperience = $skillsData['experience'] ?? [];

// Check if salary columns exist
$checkColumnsSql = "SHOW COLUMNS FROM job_orders LIKE 'salary_min'";
$checkResult = mysqli_query($conn, $checkColumnsSql);
$hasSalaryColumns = mysqli_num_rows($checkResult) > 0;

// =============================================
// AI HELPER FUNCTIONS
// =============================================

/**
 * Calculate AI match score for an applicant against a job
 */
function calculateAIMatchScore($job, $applicant) {
    global $aiService;
    
    try {
        // Parse job skills
        $skillsData = json_decode($job['skills_required'] ?? '{}', true);
        $jobSkills = $skillsData['skills'] ?? [];
        
        // Get applicant skills from their profile
        $applicantSkills = [];
        if (!empty($applicant['skills'])) {
            // Skills might be stored as comma-separated or JSON
            if (is_string($applicant['skills'])) {
                // Check if it's JSON
                $decoded = json_decode($applicant['skills'], true);
                if (is_array($decoded)) {
                    $applicantSkills = $decoded;
                } else {
                    $applicantSkills = array_map('trim', explode(',', $applicant['skills']));
                }
            } elseif (is_array($applicant['skills'])) {
                $applicantSkills = $applicant['skills'];
            }
        }
        
        // Extract years from experience text
        $experienceYears = 0;
        if (!empty($applicant['experience'])) {
            $expText = $applicant['experience'];
            // Try to extract years from text like "5 years", "3+ years", "2-4 years"
            if (preg_match('/(\d+)\s*(?:\+|\-)?\s*years?/i', $expText, $matches)) {
                $experienceYears = intval($matches[1]);
            } elseif (preg_match('/(\d+)\s*(?:\+|\-)?\s*yrs?/i', $expText, $matches)) {
                $experienceYears = intval($matches[1]);
            } else {
                // Fallback: try to find any number
                preg_match('/(\d+)/', $expText, $matches);
                $experienceYears = isset($matches[1]) ? intval($matches[1]) : 0;
            }
        }
        
        $result = $aiService->calculateMatchScore([
            'job_title' => $job['title'],
            'job_skills' => $jobSkills,
            'job_experience' => $job['experience_level'] ?? 'Mid',
            'applicant_skills' => $applicantSkills,
            'applicant_experience' => $experienceYears
        ]);
        
        if ($result && !isset($result['error'])) {
            return [
                'success' => true,
                'score' => $result['match_score'] ?? 0,
                'strengths' => $result['strengths'] ?? [],
                'gaps' => $result['gaps'] ?? [],
                'recommendation' => $result['recommendation'] ?? '',
                'provider' => $result['provider'] ?? 'fallback'
            ];
        }
    } catch (Exception $e) {
        error_log("AI Match Score Error: " . $e->getMessage());
    }
    
    return [
        'success' => false,
        'score' => 0,
        'error' => 'Could not calculate match score'
    ];
}

/**
 * Get AI job insights
 */
function getAIJobInsights($job) {
    global $aiService;
    
    try {
        $skillsData = json_decode($job['skills_required'] ?? '{}', true);
        $jobSkills = $skillsData['skills'] ?? [];
        
        $result = $aiService->getJobInsights([
            'title' => $job['title'],
            'skills' => $jobSkills,
            'experience_level' => $job['experience_level'] ?? 'Mid',
            'location' => $job['location'] ?? '',
            'job_type' => $job['job_type'] ?? 'Full-time'
        ]);
        
        if ($result && !isset($result['error'])) {
            return [
                'success' => true,
                'market_demand' => $result['market_demand'] ?? 'Medium',
                'salary_range' => $result['salary_range'] ?? '',
                'top_cities' => $result['top_cities'] ?? [],
                'trending_skills' => $result['trending_skills'] ?? [],
                'recommendations' => $result['recommendations'] ?? [],
                'provider' => $result['provider'] ?? 'fallback'
            ];
        }
    } catch (Exception $e) {
        error_log("AI Insights Error: " . $e->getMessage());
    }
    
    return [
        'success' => false,
        'error' => 'Could not generate job insights'
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // =============================================
    // GET AI MATCH SCORE (AJAX)
    // =============================================
    if ($_POST['action'] === 'get_ai_match') {
        header('Content-Type: application/json');
        $applicantId = intval($_POST['applicant_id'] ?? 0);
        
        if ($applicantId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid applicant ID']);
            exit;
        }
        
        // Get applicant details
        $applicantSql = "SELECT a.*, ap.*, u.first_name, u.last_name, u.email
                        FROM applications a
                        JOIN applicants ap ON a.applicant_id = ap.id
                        JOIN users u ON ap.user_id = u.id
                        WHERE a.id = ? AND a.job_order_id = ?";
        $stmt = mysqli_prepare($conn, $applicantSql);
        mysqli_stmt_bind_param($stmt, 'ii', $applicantId, $jobId);
        mysqli_stmt_execute($stmt);
        $applicantResult = mysqli_stmt_get_result($stmt);
        $applicant = mysqli_fetch_assoc($applicantResult);
        mysqli_stmt_close($stmt);
        
        if (!$applicant) {
            echo json_encode(['success' => false, 'error' => 'Applicant not found']);
            exit;
        }
        
        $result = calculateAIMatchScore($job, $applicant);
        echo json_encode($result);
        exit;
    }
    
    // =============================================
    // GET AI JOB INSIGHTS (AJAX)
    // =============================================
    if ($_POST['action'] === 'get_ai_insights') {
        header('Content-Type: application/json');
        $result = getAIJobInsights($job);
        echo json_encode($result);
        exit;
    }
}

// Handle Status Update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Update job status
    if ($_POST['action'] === 'update_job_status') {
        $newStatus = $_POST['new_status'] ?? 'closed';
        $updateSql = "UPDATE job_orders SET status = ?, updated_at = NOW() 
                      WHERE id = ? AND client_id = ?";
        $stmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($stmt, 'sii', $newStatus, $jobId, $clientId);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = 'Job status updated successfully!';
            $messageType = 'success';
            // Refresh job data
            $job['status'] = $newStatus;
        } else {
            $message = 'Error updating job status.';
            $messageType = 'error';
        }
        mysqli_stmt_close($stmt);
    }
    
    // Update applicant status
    if ($_POST['action'] === 'update_applicant_status') {
        $applicationId = intval($_POST['application_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'pending';
        
        if ($applicationId > 0) {
            $updateSql = "UPDATE applications SET status = ?, updated_at = NOW() 
                          WHERE id = ? AND job_order_id = ?";
            $stmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($stmt, 'sii', $newStatus, $applicationId, $jobId);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Applicant status updated successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error updating applicant status.';
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get applicants for this job with their details - FIXED (removed experience_years)
$applicantsSql = "SELECT a.*, 
                  ap.id as applicant_profile_id, ap.phone, ap.address, ap.resume_path, ap.skills, ap.experience, ap.education,
                  u.id as user_id, u.first_name, u.last_name, u.email,
                  (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id AND status IN ('hired', 'shortlisted')) as other_applications
                  FROM applications a
                  JOIN applicants ap ON a.applicant_id = ap.id
                  JOIN users u ON ap.user_id = u.id
                  WHERE a.job_order_id = ?
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
mysqli_stmt_bind_param($stmt, 'i', $jobId);
mysqli_stmt_execute($stmt);
$applicantsResult = mysqli_stmt_get_result($stmt);
$applicants = [];
while ($row = mysqli_fetch_assoc($applicantsResult)) {
    $applicants[] = $row;
}
mysqli_stmt_close($stmt);

// Get status filter
$statusFilter = $_GET['status'] ?? 'all';
$filteredApplicants = $applicants;
if ($statusFilter !== 'all') {
    $filteredApplicants = array_filter($applicants, function($app) use ($statusFilter) {
        return ($app['status'] ?? '') === $statusFilter;
    });
}

// Get application status counts
$statusCounts = [
    'all' => count($applicants),
    'pending' => 0,
    'reviewed' => 0,
    'shortlisted' => 0,
    'hired' => 0,
    'rejected' => 0
];

foreach ($applicants as $app) {
    $status = $app['status'] ?? 'pending';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

// Check if there's a toast message from session
if (isset($_SESSION['toast_message'])) {
    $message = $_SESSION['toast_message'];
    $messageType = $_SESSION['toast_type'] ?? 'info';
    unset($_SESSION['toast_message']);
    unset($_SESSION['toast_type']);
}

// Pre-compute AI insights for first load (optional - can be loaded via AJAX)
$aiInsights = getAIJobInsights($job);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($job['title']); ?> - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - JOB DETAILS
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
            --green-500: #059669;
            --green-600: #047857;
            --yellow-500: #d97706;
            --red-500: #dc2626;
            --blue-500: #2563eb;
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

        /* Match Score Badge */
        .match-score {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 700;
        }
        .match-score.high { background: #d1fae5; color: #047857; }
        .match-score.medium { background: #fef3c7; color: #b45309; }
        .match-score.low { background: #fee2e2; color: #b91c1c; }
        .match-score .material-symbols-outlined { font-size: 0.875rem; }

        /* Match Details Tooltip/Modal */
        .match-details {
            display: none;
            background: var(--bg-surface);
            border-radius: var(--radius-md);
            border: 1px solid var(--slate-200);
            padding: 0.75rem;
            margin-top: 0.5rem;
            box-shadow: var(--shadow-sm);
        }
        .match-details.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .match-details .strength { color: #047857; }
        .match-details .gap { color: #b91c1c; }
        .match-details .recommendation { 
            background: var(--primary-container);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            color: var(--primary);
        }

        /* =============================================
           AI INSIGHTS PANEL
        ============================================= */
        .ai-insights-panel {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-lg);
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }
        .ai-insights-panel .insight-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            color: var(--primary);
            font-size: 0.8125rem;
            margin-bottom: 0.75rem;
        }
        .ai-insights-panel .insight-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        @media (max-width: 480px) {
            .ai-insights-panel .insight-grid { grid-template-columns: 1fr; }
        }
        .ai-insights-panel .insight-item {
            background: rgba(255,255,255,0.6);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
        }
        .ai-insights-panel .insight-item .label {
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }
        .ai-insights-panel .insight-item .value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }
        .ai-insights-panel .insight-item .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 0.25rem;
        }
        .ai-insights-panel .insight-item .tags .tag {
            padding: 0.0625rem 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 500;
        }
        .ai-insights-panel .insight-item .recommendations {
            font-size: 0.75rem;
            color: var(--text-on-surface);
            list-style: disc;
            padding-left: 1.25rem;
            margin-top: 0.25rem;
        }
        .ai-insights-panel .insight-item .recommendations li {
            margin-bottom: 0.125rem;
        }

        /* =============================================
           AI LOADING DOTS (small)
        ============================================= */
        .ai-dots-loading-sm {
            display: flex;
            align-items: center;
            justify-content: center;
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
           REST OF STYLES (same as your existing)
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

        .job-detail-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-xs);
        }
        .job-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .job-detail-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }
        .job-detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-top: 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }
        .job-detail-meta .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
        }
        .job-detail-body {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-top: 1rem;
        }
        @media (max-width: 768px) {
            .job-detail-body { grid-template-columns: 1fr; }
        }
        .job-detail-section h4 {
            font-size: 0.8125rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.5rem;
        }
        .job-detail-section p {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            line-height: 1.7;
            white-space: pre-wrap;
        }
        .job-detail-section ul {
            list-style: none;
            padding: 0;
        }
        .job-detail-section ul li {
            padding: 0.25rem 0;
            font-size: 0.875rem;
            color: var(--text-on-surface);
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .job-detail-section ul li .material-symbols-outlined {
            font-size: 1rem;
            color: var(--primary);
            margin-top: 0.125rem;
        }
        .job-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        .job-stat-item {
            background: var(--bg-surface-low);
            padding: 0.75rem;
            border-radius: 0.75rem;
            text-align: center;
        }
        .job-stat-item .number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }
        .job-stat-item .label {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
        }
        .job-stat-item .number.primary { color: var(--primary); }
        .job-stat-item .number.green { color: #059669; }
        .job-stat-item .number.yellow { color: #d97706; }
        .job-stat-item .number.red { color: #dc2626; }
        .job-stat-item .number.blue { color: #2563eb; }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-lg {
            padding: 0.25rem 0.875rem;
            font-size: 0.75rem;
        }
        .badge-open { background: #dbeafe; color: #2563eb; }
        .badge-ongoing { background: #e0e7ff; color: #4f46e5; }
        .badge-closed { background: #f1f5f9; color: #64748b; }
        .badge-on_hold { background: #fef3c7; color: #d97706; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-reviewed { background: #dbeafe; color: #2563eb; }
        .badge-shortlisted { background: #d1fae5; color: #059669; }
        .badge-hired { background: #a7f3d0; color: #047857; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }

        .applicant-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
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

        .applicant-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            margin-bottom: 0.75rem;
            transition: all var(--transition-fast);
        }
        .applicant-card:hover { box-shadow: var(--shadow-sm); border-color: var(--slate-300); }
        .applicant-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .applicant-name {
            font-weight: 700;
            font-size: 0.9375rem;
            color: var(--text-on-surface);
        }
        .applicant-name .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
            color: var(--primary);
        }
        .applicant-email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .applicant-details {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-top: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .applicant-details .material-symbols-outlined {
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .applicant-actions {
            display: flex;
            gap: 0.375rem;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 0.625rem;
        }
        .applicant-status-select {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            border: 1.5px solid var(--slate-200);
            font-size: 0.6875rem;
            font-family: var(--font-sans);
            background: var(--bg-surface);
            color: var(--text-on-surface);
            cursor: pointer;
        }
        .applicant-status-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.75rem;
        }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.8125rem; }

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
            .job-stats-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .job-detail-title { font-size: 1.25rem; }
            .job-stats-grid { grid-template-columns: 1fr 1fr; }
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Job Details</span>
                <span class="ai-badge" style="margin-left:0.5rem;">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    AI Enhanced
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
                        <span class="material-symbols-outlined">work</span>
                        <span>Job Details</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($job['title']); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                </div>

                <!-- Job Details -->
                <div class="job-detail-card">
                    <div class="job-detail-header">
                        <div>
                            <h1 class="job-detail-title"><?php echo htmlspecialchars($job['title']); ?></h1>
                            <div class="job-detail-meta">
                                <span>
                                    <span class="material-symbols-outlined">location_on</span>
                                    <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                                </span>
                                <span>
                                    <span class="material-symbols-outlined">work</span>
                                    <?php echo ucfirst(str_replace('_', ' ', $job['job_type'] ?? 'Full-time')); ?>
                                </span>
                                <?php if ($hasSalaryColumns && (!empty($job['salary_min']) || !empty($job['salary_max']))): ?>
                                    <span>
                                        <span class="material-symbols-outlined">payments</span>
                                        <?php if (!empty($job['salary_min']) && !empty($job['salary_max'])): ?>
                                            ₱<?php echo number_format($job['salary_min']); ?> - ₱<?php echo number_format($job['salary_max']); ?>
                                        <?php elseif (!empty($job['salary_min'])): ?>
                                            ₱<?php echo number_format($job['salary_min']); ?>+
                                        <?php elseif (!empty($job['salary_max'])): ?>
                                            Up to ₱<?php echo number_format($job['salary_max']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <span>
                                    <span class="material-symbols-outlined">people</span>
                                    <?php echo $job['positions_available'] ?? 1; ?> positions
                                </span>
                                <span>
                                    <span class="material-symbols-outlined">trending_up</span>
                                    <?php echo htmlspecialchars($job['experience_level'] ?? 'Not specified'); ?>
                                </span>
                            </div>
                        </div>
                        <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                            <span class="badge badge-lg badge-<?php echo $job['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $job['status'])); ?>
                            </span>
                            <form method="POST" style="display:inline;" id="statusForm">
                                <input type="hidden" name="action" value="update_job_status">
                                <select name="new_status" class="applicant-status-select" onchange="document.getElementById('statusForm').submit()" style="padding:0.375rem 0.75rem;">
                                    <option value="open" <?php echo $job['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="ongoing" <?php echo $job['status'] === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                    <option value="on_hold" <?php echo $job['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                    <option value="closed" <?php echo $job['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </form>
                            <button class="btn btn-sm btn-ai" onclick="loadAIInsights()" id="aiInsightsBtn">
                                <span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span>
                                AI Insights
                            </button>
                        </div>
                    </div>

                    <!-- AI Insights Panel -->
                    <div id="aiInsightsPanel" class="ai-insights-panel" style="display:none;">
                        <div class="insight-header">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            AI Job Insights
                            <span style="font-size:0.55rem; font-weight:400; color:var(--text-on-surface-variant); margin-left:auto;" id="insightsProvider">Groq</span>
                        </div>
                        <div id="insightsLoading" class="ai-dots-loading-sm">
                            <div class="dot"></div>
                            <div class="dot"></div>
                            <div class="dot"></div>
                            <span style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">Analyzing market data...</span>
                        </div>
                        <div id="insightsContent" style="display:none;">
                            <div class="insight-grid">
                                <div class="insight-item">
                                    <div class="label">Market Demand</div>
                                    <div class="value" id="insightDemand">-</div>
                                </div>
                                <div class="insight-item">
                                    <div class="label">Salary Range</div>
                                    <div class="value" id="insightSalary">-</div>
                                </div>
                                <div class="insight-item">
                                    <div class="label">Top Cities</div>
                                    <div class="tags" id="insightCities"></div>
                                </div>
                                <div class="insight-item">
                                    <div class="label">Trending Skills</div>
                                    <div class="tags" id="insightSkills"></div>
                                </div>
                                <div class="insight-item" style="grid-column:1/-1;">
                                    <div class="label">Recommendations</div>
                                    <ul class="recommendations" id="insightRecommendations"></ul>
                                </div>
                            </div>
                        </div>
                        <div id="insightsError" style="display:none; color:#dc2626; font-size:0.8125rem; padding:0.5rem;">
                            <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">error</span>
                            <span id="insightsErrorMessage">Could not load insights</span>
                        </div>
                    </div>

                    <div class="job-detail-body">
                        <div>
                            <div class="job-detail-section" style="margin-bottom:1.5rem;">
                                <h4>Job Description</h4>
                                <p><?php echo nl2br(htmlspecialchars($job['description'] ?? '')); ?></p>
                            </div>
                            <?php if (!empty($jobSkills) || !empty($jobQualifications) || !empty($jobExperience)): ?>
                                <div class="job-detail-section">
                                    <h4>Requirements</h4>
                                    <?php if (!empty($jobSkills)): ?>
                                        <div style="margin-bottom:0.5rem;">
                                            <strong style="font-size:0.75rem; color:var(--text-on-surface-variant);">Skills:</strong>
                                            <div style="display:flex; flex-wrap:wrap; gap:0.25rem; margin-top:0.25rem;">
                                                <?php foreach ($jobSkills as $skill): ?>
                                                    <span style="padding:0.0625rem 0.5rem; background:var(--primary-container); color:var(--primary); border-radius:var(--radius-full); font-size:0.6875rem; font-weight:500;"><?php echo htmlspecialchars($skill); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($jobQualifications)): ?>
                                        <div style="margin-bottom:0.5rem;">
                                            <strong style="font-size:0.75rem; color:var(--text-on-surface-variant);">Qualifications:</strong>
                                            <ul>
                                                <?php foreach ($jobQualifications as $qual): ?>
                                                    <li><span class="material-symbols-outlined">check_circle</span> <?php echo htmlspecialchars($qual); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($jobExperience)): ?>
                                        <div>
                                            <strong style="font-size:0.75rem; color:var(--text-on-surface-variant);">Experience:</strong>
                                            <ul>
                                                <?php foreach ($jobExperience as $exp): ?>
                                                    <li><span class="material-symbols-outlined">work_history</span> <?php echo htmlspecialchars($exp); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="job-detail-section">
                                <h4>Application Stats</h4>
                                <div class="job-stats-grid">
                                    <div class="job-stat-item">
                                        <div class="number primary"><?php echo $job['total_applicants'] ?? 0; ?></div>
                                        <div class="label">Total Applicants</div>
                                    </div>
                                    <div class="job-stat-item">
                                        <div class="number yellow"><?php echo $job['pending_applicants'] ?? 0; ?></div>
                                        <div class="label">Pending</div>
                                    </div>
                                    <div class="job-stat-item">
                                        <div class="number blue"><?php echo $job['reviewed_applicants'] ?? 0; ?></div>
                                        <div class="label">Reviewed</div>
                                    </div>
                                    <div class="job-stat-item">
                                        <div class="number green"><?php echo $job['shortlisted_applicants'] ?? 0; ?></div>
                                        <div class="label">Shortlisted</div>
                                    </div>
                                    <div class="job-stat-item">
                                        <div class="number green" style="color:#047857;"><?php echo $job['hired_applicants'] ?? 0; ?></div>
                                        <div class="label">Hired</div>
                                    </div>
                                    <div class="job-stat-item">
                                        <div class="number red"><?php echo $job['rejected_applicants'] ?? 0; ?></div>
                                        <div class="label">Rejected</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Applicants Section -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem;">
                    <h2 style="font-size:1.125rem; font-weight:700;">Applicants</h2>
                    <span style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                        <?php echo count($applicants); ?> applicant<?php echo count($applicants) !== 1 ? 's' : ''; ?>
                    </span>
                </div>

                <!-- Applicant Filters -->
                <div class="applicant-filters">
                    <a href="?id=<?php echo $jobId; ?>&status=all" class="filter-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $statusCounts['all']; ?></span>
                    </a>
                    <a href="?id=<?php echo $jobId; ?>&status=pending" class="filter-btn <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                        Pending <span class="count"><?php echo $statusCounts['pending']; ?></span>
                    </a>
                    <a href="?id=<?php echo $jobId; ?>&status=reviewed" class="filter-btn <?php echo $statusFilter === 'reviewed' ? 'active' : ''; ?>">
                        Reviewed <span class="count"><?php echo $statusCounts['reviewed']; ?></span>
                    </a>
                    <a href="?id=<?php echo $jobId; ?>&status=shortlisted" class="filter-btn <?php echo $statusFilter === 'shortlisted' ? 'active' : ''; ?>">
                        Shortlisted <span class="count"><?php echo $statusCounts['shortlisted']; ?></span>
                    </a>
                    <a href="?id=<?php echo $jobId; ?>&status=hired" class="filter-btn <?php echo $statusFilter === 'hired' ? 'active' : ''; ?>">
                        Hired <span class="count"><?php echo $statusCounts['hired']; ?></span>
                    </a>
                    <a href="?id=<?php echo $jobId; ?>&status=rejected" class="filter-btn <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                        Rejected <span class="count"><?php echo $statusCounts['rejected']; ?></span>
                    </a>
                </div>

                <!-- Applicant List -->
                <?php if (empty($filteredApplicants)): ?>
                    <div class="empty-state">
                        <span class="material-symbols-outlined">person_off</span>
                        <h3>No applicants found</h3>
                        <p>
                            <?php if ($statusFilter !== 'all'): ?>
                                No applicants with status "<?php echo htmlspecialchars($statusFilter); ?>".
                                <a href="?id=<?php echo $jobId; ?>&status=all" style="color:var(--primary); font-weight:600;">View all applicants</a>
                            <?php else: ?>
                                No one has applied to this job yet.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($filteredApplicants as $app): ?>
                        <div class="applicant-card" id="applicant-<?php echo $app['id']; ?>">
                            <div class="applicant-card-header">
                                <div>
                                    <div class="applicant-name">
                                        <span class="material-symbols-outlined">person</span>
                                        <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>
                                        <!-- AI Match Score -->
                                        <button class="btn btn-sm btn-ai" onclick="loadAIMatch(<?php echo $app['id']; ?>)" id="matchBtn-<?php echo $app['id']; ?>" style="padding:0.0625rem 0.5rem; font-size:0.625rem; margin-left:0.5rem;">
                                            <span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span>
                                            Match
                                        </button>
                                        <span id="matchScore-<?php echo $app['id']; ?>"></span>
                                    </div>
                                    <div class="applicant-email"><?php echo htmlspecialchars($app['email']); ?></div>
                                    <div class="applicant-details">
                                        <?php if (!empty($app['phone'])): ?>
                                            <span>
                                                <span class="material-symbols-outlined">phone</span>
                                                <?php echo htmlspecialchars($app['phone']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($app['address'])): ?>
                                            <span>
                                                <span class="material-symbols-outlined">location_on</span>
                                                <?php echo htmlspecialchars($app['address']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span>
                                            <span class="material-symbols-outlined">schedule</span>
                                            Applied <?php echo date('M d, Y', strtotime($app['applied_at'] ?? 'now')); ?>
                                        </span>
                                        <?php if ($app['other_applications'] > 0): ?>
                                            <span style="color:var(--primary);">
                                                <span class="material-symbols-outlined">info</span>
                                                <?php echo $app['other_applications']; ?> other application<?php echo $app['other_applications'] > 1 ? 's' : ''; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($app['skills'])): ?>
                                            <span>
                                                <span class="material-symbols-outlined">psychology</span>
                                                <?php echo htmlspecialchars(substr($app['skills'], 0, 50)) . (strlen($app['skills']) > 50 ? '...' : ''); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Match Details -->
                                    <div class="match-details" id="matchDetails-<?php echo $app['id']; ?>">
                                        <div id="matchContent-<?php echo $app['id']; ?>">
                                            <div class="ai-dots-loading-sm">
                                                <div class="dot"></div>
                                                <div class="dot"></div>
                                                <div class="dot"></div>
                                                <span style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-left:0.5rem;">Analyzing match...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge badge-<?php echo $app['status']; ?>">
                                    <?php echo ucfirst($app['status']); ?>
                                </span>
                            </div>
                            <div class="applicant-actions">
                                <form method="POST" style="display:flex; gap:0.375rem; flex-wrap:wrap; align-items:center;">
                                    <input type="hidden" name="action" value="update_applicant_status">
                                    <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                    <select name="new_status" class="applicant-status-select" onchange="this.form.submit()">
                                        <option value="pending" <?php echo $app['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="reviewed" <?php echo $app['status'] === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                        <option value="shortlisted" <?php echo $app['status'] === 'shortlisted' ? 'selected' : ''; ?>>Shortlist</option>
                                        <option value="hired" <?php echo $app['status'] === 'hired' ? 'selected' : ''; ?>>Hire</option>
                                        <option value="rejected" <?php echo $app['status'] === 'rejected' ? 'selected' : ''; ?>>Reject</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary" style="padding:0.25rem 0.625rem;">
                                        <span class="material-symbols-outlined" style="font-size:0.875rem;">update</span>
                                        Update
                                    </button>
                                </form>
                                <?php if (!empty($app['resume_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($app['resume_path']); ?>" target="_blank" class="btn btn-sm btn-outline">
                                        <span class="material-symbols-outlined" style="font-size:0.875rem;">description</span>
                                        Resume
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
        // AI MATCH SCORE
        // =============================================
        function loadAIMatch(applicantId) {
            const btn = document.getElementById('matchBtn-' + applicantId);
            const scoreSpan = document.getElementById('matchScore-' + applicantId);
            const details = document.getElementById('matchDetails-' + applicantId);
            const content = document.getElementById('matchContent-' + applicantId);
            
            btn.disabled = true;
            btn.innerHTML = '<span class="ai-dots-loading-sm" style="display:inline-flex; gap:0.125rem; padding:0;"><div class="dot"></div><div class="dot"></div><div class="dot"></div></span>';
            
            const formData = new FormData();
            formData.append('action', 'get_ai_match');
            formData.append('applicant_id', applicantId);

            fetch('job-details.php?id=<?php echo $jobId; ?>', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span> Match';
                
                if (data.success) {
                    const score = data.score;
                    let scoreClass = 'low';
                    if (score >= 70) scoreClass = 'high';
                    else if (score >= 40) scoreClass = 'medium';
                    
                    scoreSpan.innerHTML = `<span class="match-score ${scoreClass}">
                        <span class="material-symbols-outlined">${score >= 70 ? 'check_circle' : score >= 40 ? 'info' : 'warning'}</span>
                        ${score}% Match
                    </span>`;
                    
                    // Build match details
                    let html = '';
                    if (data.strengths && data.strengths.length > 0) {
                        html += '<div style="margin-bottom:0.25rem;"><strong style="font-size:0.6875rem; color:#047857;">✅ Strengths:</strong><br>';
                        data.strengths.forEach(s => {
                            html += `<span style="font-size:0.6875rem; color:var(--text-on-surface);">• ${s}</span><br>`;
                        });
                        html += '</div>';
                    }
                    if (data.gaps && data.gaps.length > 0) {
                        html += '<div style="margin-bottom:0.25rem;"><strong style="font-size:0.6875rem; color:#b91c1c;">⚠️ Gaps:</strong><br>';
                        data.gaps.forEach(g => {
                            html += `<span style="font-size:0.6875rem; color:var(--text-on-surface);">• ${g}</span><br>`;
                        });
                        html += '</div>';
                    }
                    if (data.recommendation) {
                        html += `<div class="recommendation">💡 ${data.recommendation}</div>`;
                    }
                    if (data.provider) {
                        html += `<div style="font-size:0.5rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">AI: ${data.provider}</div>`;
                    }
                    content.innerHTML = html;
                    details.classList.add('show');
                } else {
                    scoreSpan.innerHTML = `<span style="font-size:0.6875rem; color:#dc2626;">Error</span>`;
                    content.innerHTML = `<span style="color:#dc2626; font-size:0.75rem;">${data.error || 'Could not calculate match'}</span>`;
                    details.classList.add('show');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span> Match';
                scoreSpan.innerHTML = `<span style="font-size:0.6875rem; color:#dc2626;">Error</span>`;
                content.innerHTML = `<span style="color:#dc2626; font-size:0.75rem;">Network error. Please try again.</span>`;
                details.classList.add('show');
            });
        }

        // =============================================
        // AI JOB INSIGHTS
        // =============================================
        let insightsLoaded = false;

        function loadAIInsights() {
            if (insightsLoaded) {
                const panel = document.getElementById('aiInsightsPanel');
                panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
                return;
            }

            const panel = document.getElementById('aiInsightsPanel');
            const loading = document.getElementById('insightsLoading');
            const content = document.getElementById('insightsContent');
            const error = document.getElementById('insightsError');
            const btn = document.getElementById('aiInsightsBtn');

            panel.style.display = 'block';
            loading.style.display = 'flex';
            content.style.display = 'none';
            error.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '⏳ Loading...';

            const formData = new FormData();
            formData.append('action', 'get_ai_insights');

            fetch('job-details.php?id=<?php echo $jobId; ?>', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span> AI Insights';
                loading.style.display = 'none';

                if (data.success) {
                    document.getElementById('insightDemand').textContent = data.market_demand || 'N/A';
                    document.getElementById('insightSalary').textContent = data.salary_range || 'N/A';
                    document.getElementById('insightsProvider').textContent = data.provider || 'Groq';
                    
                    // Cities
                    const citiesContainer = document.getElementById('insightCities');
                    citiesContainer.innerHTML = '';
                    if (data.top_cities && data.top_cities.length > 0) {
                        data.top_cities.forEach(city => {
                            const tag = document.createElement('span');
                            tag.className = 'tag';
                            tag.textContent = city;
                            citiesContainer.appendChild(tag);
                        });
                    } else {
                        citiesContainer.innerHTML = '<span style="font-size:0.75rem;color:var(--text-on-surface-variant);">No data</span>';
                    }
                    
                    // Skills
                    const skillsContainer = document.getElementById('insightSkills');
                    skillsContainer.innerHTML = '';
                    if (data.trending_skills && data.trending_skills.length > 0) {
                        data.trending_skills.forEach(skill => {
                            const tag = document.createElement('span');
                            tag.className = 'tag';
                            tag.textContent = skill;
                            skillsContainer.appendChild(tag);
                        });
                    } else {
                        skillsContainer.innerHTML = '<span style="font-size:0.75rem;color:var(--text-on-surface-variant);">No data</span>';
                    }
                    
                    // Recommendations
                    const recContainer = document.getElementById('insightRecommendations');
                    recContainer.innerHTML = '';
                    if (data.recommendations && data.recommendations.length > 0) {
                        data.recommendations.forEach(rec => {
                            const li = document.createElement('li');
                            li.textContent = rec;
                            recContainer.appendChild(li);
                        });
                    } else {
                        recContainer.innerHTML = '<li style="color:var(--text-on-surface-variant);">No recommendations available</li>';
                    }
                    
                    content.style.display = 'block';
                    insightsLoaded = true;
                    showToast('✨ AI insights loaded!', 'success');
                } else {
                    error.style.display = 'block';
                    document.getElementById('insightsErrorMessage').textContent = data.error || 'Could not load insights';
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span> AI Insights';
                loading.style.display = 'none';
                error.style.display = 'block';
                document.getElementById('insightsErrorMessage').textContent = 'Network error. Please try again.';
            });
        }

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

        console.log('📋 AI-Powered ISMERS Job Details loaded successfully!');
        console.log('🤖 AI Features: Match Scoring, Job Insights, Candidate Analysis');
    </script>

</body>
</html>