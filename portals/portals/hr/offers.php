<?php
// portals/hr/offers.php - AI-Powered Offer Management
// FIXED: PostgreSQL compatibility + proper error handling

session_start();

// =============================================
// ERROR REPORTING - DISABLE WARNINGS
// =============================================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../app/config.php';
initSessionTimeout();
require_once 'includes/functions.php';
require_once '../../app/ai/AiService.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter', 'admin'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';

// =============================================
// AI SERVICE INITIALIZATION
// =============================================
$aiService = new AiService();

// =============================================
// AI HELPER FUNCTIONS
// =============================================

/**
 * Get AI-powered salary recommendations
 */
function getAISalaryRecommendation($jobTitle, $applicantSkills, $experience) {
    global $aiService;
    
    try {
        $jobData = [
            'title' => $jobTitle,
            'description' => "This is a {$jobTitle} position. The candidate has skills: {$applicantSkills} and experience: {$experience} years.",
            'skills_required' => $applicantSkills,
            'experience_level' => $experience >= 5 ? 'Senior' : ($experience >= 3 ? 'Mid' : 'Junior')
        ];
        
        $result = $aiService->optimizeJobDescription($jobData);
        
        if ($result && !isset($result['error'])) {
            $provider = $result['provider'] ?? 'fallback';
            $salaryRange = $result['salary_range'] ?? '';
            $salaryMin = $result['salary_min'] ?? 0;
            $salaryMax = $result['salary_max'] ?? 0;
            
            if ($salaryMin > 0 && $salaryMax > 0 && $salaryMax > $salaryMin) {
                $experienceFactor = 0.8 + ($experience / 10);
                $recommended = round(($salaryMin + $salaryMax) / 2 * $experienceFactor);
                
                if ($recommended < $salaryMin) $recommended = $salaryMin;
                if ($recommended > $salaryMax) $recommended = $salaryMax;
                
                $rangeDisplay = '₱' . number_format($salaryMin, 0) . ' - ₱' . number_format($salaryMax, 0);
                
                return [
                    'min' => $salaryMin,
                    'max' => $salaryMax,
                    'recommended' => $recommended,
                    'range_display' => $rangeDisplay,
                    'provider' => $provider,
                    'confidence' => $provider === 'groq' ? 'high' : 'medium'
                ];
            }
            
            if (!empty($salaryRange)) {
                $patterns = [
                    '/₱([0-9,]+)\s*-\s*₱([0-9,]+)/',
                    '/PHP\s*([0-9,]+)\s*-\s*PHP\s*([0-9,]+)/i',
                    '/([0-9,]+)\s*-\s*([0-9,]+)/',
                ];
                
                foreach ($patterns as $pattern) {
                    preg_match($pattern, $salaryRange, $matches);
                    if (count($matches) === 3) {
                        $min = (int)str_replace(',', '', $matches[1]);
                        $max = (int)str_replace(',', '', $matches[2]);
                        if ($min > 0 && $max > 0 && $max > $min) {
                            $experienceFactor = 0.8 + ($experience / 10);
                            $recommended = round(($min + $max) / 2 * $experienceFactor);
                            
                            if ($recommended < $min) $recommended = $min;
                            if ($recommended > $max) $recommended = $max;
                            
                            return [
                                'min' => $min,
                                'max' => $max,
                                'recommended' => $recommended,
                                'range_display' => '₱' . number_format($min, 0) . ' - ₱' . number_format($max, 0),
                                'provider' => $provider,
                                'confidence' => $provider === 'groq' ? 'high' : 'medium'
                            ];
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        @error_log("AI Salary Error: " . $e->getMessage());
    }
    
    return getFallbackSalaryRecommendation($jobTitle, $experience);
}

/**
 * Get fallback salary recommendation
 */
function getFallbackSalaryRecommendation($jobTitle, $experience) {
    $baseSalaries = [
        'fitness' => 25000, 'coach' => 25000, 'trainer' => 25000,
        'developer' => 55000, 'engineer' => 60000, 'designer' => 45000,
        'manager' => 75000, 'analyst' => 50000, 'senior' => 85000,
        'lead' => 95000, 'architect' => 110000, 'junior' => 35000,
        'intern' => 20000, 'assistant' => 30000, 'supervisor' => 65000,
        'director' => 130000
    ];
    
    $base = 45000;
    $titleLower = strtolower($jobTitle);
    foreach ($baseSalaries as $keyword => $amount) {
        if (strpos($titleLower, $keyword) !== false) {
            $base = max($base, $amount);
            break;
        }
    }
    
    $experienceFactor = 0.8 + ($experience / 10);
    $adjusted = $base * $experienceFactor;
    $recommended = round($adjusted / 5000) * 5000;
    $min = max(15000, $recommended - 10000);
    $max = $recommended + 15000;
    
    return [
        'min' => $min,
        'max' => $max,
        'recommended' => $recommended,
        'range_display' => '₱' . number_format($min, 0) . ' - ₱' . number_format($max, 0),
        'provider' => 'fallback',
        'confidence' => 'medium'
    ];
}

/**
 * Get AI-powered offer optimization tips
 */
function getOfferOptimizationTips($jobTitle, $applicantName) {
    global $aiService;
    
    $tips = [
        'Personalize the offer letter with the candidate\'s name and specific skills',
        'Highlight company culture and growth opportunities',
        'Include a clear breakdown of compensation and benefits',
        'Set a reasonable deadline for response (5-7 business days)',
        'Mention the start date and onboarding process'
    ];
    
    try {
        $jobData = [
            'title' => $jobTitle,
            'description' => "Creating an offer for a {$jobTitle} position.",
            'skills_required' => 'Offer Management, Recruitment',
            'experience_level' => 'Mid'
        ];
        
        $result = $aiService->optimizeJobDescription($jobData);
        
        if ($result && !isset($result['error'])) {
            return [
                "✨ Personalize the offer for {$applicantName} with specific achievements mentioned in their interview",
                "💰 Consider offering the upper end of the salary range for top talent",
                "📝 Highlight the benefits package and career growth opportunities",
                "🎯 Emphasize the impact they'll make in the role",
                "📅 Include a clear timeline for decision and onboarding"
            ];
        }
    } catch (Exception $e) {
        @error_log("AI Tips Error: " . $e->getMessage());
    }
    
    return $tips;
}

/**
 * Get AI-powered offer acceptance prediction
 */
function predictOfferAcceptance($offerData, $applicantData) {
    $jobTitle = $offerData['job_title'] ?? 'position';
    $salary = $offerData['salary_offered'] ?? 0;
    $applicantSkills = $applicantData['skills'] ?? '';
    $experience = $applicantData['experience'] ?? 0;
    
    $recommendation = getAISalaryRecommendation($jobTitle, $applicantSkills, $experience);
    $recommendedSalary = $recommendation['recommended'] ?? 0;
    
    $salaryScore = 50;
    if ($salary > 0 && $recommendedSalary > 0) {
        $ratio = $salary / $recommendedSalary;
        if ($ratio >= 1.1) $salaryScore = 90;
        elseif ($ratio >= 0.95) $salaryScore = 75;
        elseif ($ratio >= 0.8) $salaryScore = 50;
        else $salaryScore = 25;
    }
    
    $skillScore = !empty($applicantSkills) ? 70 + min(25, count(explode(',', $applicantSkills)) * 5) : 50;
    $experienceScore = $experience >= 3 ? 80 : ($experience >= 1 ? 60 : 40);
    
    $score = round(($salaryScore * 0.5) + ($skillScore * 0.3) + ($experienceScore * 0.2));
    
    if ($score >= 80) {
        return ['score' => $score, 'level' => 'High', 'emoji' => '🔥', 'message' => 'Strong likelihood of acceptance. The offer is competitive!'];
    } elseif ($score >= 60) {
        return ['score' => $score, 'level' => 'Medium', 'emoji' => '📊', 'message' => 'Moderate likelihood. Consider adjusting the offer to improve chances.'];
    } else {
        return ['score' => $score, 'level' => 'Low', 'emoji' => '⚠️', 'message' => 'Low likelihood. The offer may need significant improvement.'];
    }
}

// =============================================
// GET OFFERS - PostgreSQL syntax
// =============================================
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

$conditions = [];
$params = [];
$counter = 1;

$conditions[] = "jo.created_by = $" . $counter++;
$params[] = $userId;

if ($statusFilter !== 'all') {
    $conditions[] = "o.status = $" . $counter++;
    $params[] = $statusFilter;
}

if (!empty($searchQuery)) {
    $conditions[] = "(u.first_name ILIKE $" . $counter . " OR u.last_name ILIKE $" . ($counter+1) . " OR u.email ILIKE $" . ($counter+2) . " OR jo.title ILIKE $" . ($counter+3) . ")";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $counter += 4;
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

$sql = "SELECT o.*, 
        u.id as user_id, u.first_name, u.last_name, u.email,
        ap.skills, ap.experience,
        jo.id as job_id, jo.title as job_title,
        c.company_name,
        a.id as application_id,
        a.status as application_status
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        $whereClause
        ORDER BY o.created_at DESC";

$offers = @getRecords($sql, $params);
if (!is_array($offers)) $offers = [];

// Get status counts - PostgreSQL syntax
$statusCounts = ['all' => count($offers)];
$statuses = ['draft', 'sent', 'accepted', 'rejected', 'expired'];
foreach ($statuses as $status) {
    $countResult = @getRecord("
        SELECT COUNT(*) as count FROM offers o 
        JOIN applications a ON o.application_id = a.id
        JOIN job_orders jo ON a.job_order_id = jo.id 
        WHERE jo.created_by = $1 AND o.status = $2
    ", [$userId, $status]);
    $statusCounts[$status] = isset($countResult['count']) ? (int)$countResult['count'] : 0;
}

// Status badge mapping
$statusBadges = [
    'draft' => 'badge-draft',
    'sent' => 'badge-sent',
    'accepted' => 'badge-accepted',
    'rejected' => 'badge-rejected',
    'expired' => 'badge-expired'
];

$statusLabels = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
    'expired' => 'Expired'
];

$allStatuses = ['all' => 'All'] + $statusLabels;

// =============================================
// GET ELIGIBLE APPLICANTS - PostgreSQL syntax
// =============================================
$eligibleApplicants = @getRecords("
    SELECT a.id, u.first_name, u.last_name, u.email,
           jo.id as job_id, jo.title as job_title, c.company_name,
           a.status as application_status,
           ap.skills, ap.experience
    FROM applications a
    JOIN applicants ap ON a.applicant_id = ap.id
    JOIN users u ON ap.user_id = u.id
    JOIN job_orders jo ON a.job_order_id = jo.id
    JOIN clients c ON jo.client_id = c.id
    WHERE jo.created_by = $1 
    AND a.status IN ('interviewed', 'shortlisted')
    AND NOT EXISTS (
        SELECT 1 FROM offers o WHERE o.application_id = a.id AND o.status IN ('draft', 'sent', 'accepted')
    )
    ORDER BY a.applied_at DESC
", [$userId]);
if (!is_array($eligibleApplicants)) $eligibleApplicants = [];

// =============================================
// Get sidebar counts - PostgreSQL syntax
// =============================================
$pendingAppsCount = 0;
$pendingResult = @getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", []);
if ($pendingResult && isset($pendingResult['count'])) {
    $pendingAppsCount = (int)$pendingResult['count'];
}

$totalArchived = 0;
$archivedTables = ['examination_records', 'interview_evaluations', 'client_assignments', 'deployment_archive'];
foreach ($archivedTables as $table) {
    $result = @getRecord("SELECT COUNT(*) as count FROM $table", []);
    if ($result && isset($result['count'])) {
        $totalArchived += (int)$result['count'];
    }
}

// =============================================
// AJAX HANDLER - PostgreSQL syntax
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $offerId = isset($_POST['offer_id']) ? (int)$_POST['offer_id'] : 0;
    
    // ========== GET AI SALARY RECOMMENDATION ==========
    if ($action === 'get_salary_recommendation') {
        $jobTitle = $_POST['job_title'] ?? '';
        $applicantSkills = $_POST['applicant_skills'] ?? '';
        $experience = isset($_POST['experience']) ? (int)$_POST['experience'] : 0;
        
        $recommendation = getAISalaryRecommendation($jobTitle, $applicantSkills, $experience);
        echo json_encode(['success' => true, 'recommendation' => $recommendation]);
        exit;
    }
    
    // ========== GET OFFER OPTIMIZATION TIPS ==========
    if ($action === 'get_offer_tips') {
        $jobTitle = $_POST['job_title'] ?? '';
        $applicantName = $_POST['applicant_name'] ?? '';
        
        $tips = getOfferOptimizationTips($jobTitle, $applicantName);
        echo json_encode(['success' => true, 'tips' => $tips]);
        exit;
    }
    
    // ========== PREDICT OFFER ACCEPTANCE ==========
    if ($action === 'predict_acceptance') {
        $offerData = [
            'job_title' => $_POST['job_title'] ?? '',
            'salary_offered' => isset($_POST['salary_offered']) ? (int)$_POST['salary_offered'] : 0
        ];
        $applicantData = [
            'skills' => $_POST['applicant_skills'] ?? '',
            'experience' => isset($_POST['experience']) ? (int)$_POST['experience'] : 0
        ];
        
        $prediction = predictOfferAcceptance($offerData, $applicantData);
        echo json_encode(['success' => true, 'prediction' => $prediction]);
        exit;
    }
    
    // ========== CREATE OFFER - PostgreSQL ==========
    if ($action === 'create_offer') {
        $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
        $offerDate = $_POST['offer_date'] ?? date('Y-m-d');
        $startDate = $_POST['start_date'] ?? null;
        $salaryOffered = $_POST['salary_offered'] ?? null;
        $benefits = trim($_POST['benefits'] ?? '');
        
        if (empty($applicationId)) {
            echo json_encode(['success' => false, 'error' => 'Please select an applicant.']);
            exit;
        }
        
        $existing = @getRecord("SELECT id FROM offers WHERE application_id = $1 AND status IN ('draft', 'sent')", [$applicationId]);
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'This applicant already has an active offer.']);
            exit;
        }
        
        if (!empty($salaryOffered)) {
            $salaryOffered = (float)preg_replace('/[^0-9.]/', '', $salaryOffered);
        } else {
            $salaryOffered = null;
        }
        
        if (empty($startDate)) $startDate = null;
        if (empty($benefits)) $benefits = null;
        
        $sql = "INSERT INTO offers (
            application_id, offer_date, start_date, salary_offered, benefits, 
            status, created_by, created_at
        ) VALUES ($1, $2, $3, $4, $5, 'draft', $6, NOW())
        RETURNING id";
        
        $result = @insertRecord($sql, [
            $applicationId,
            $offerDate,
            $startDate,
            $salaryOffered,
            $benefits,
            $userId
        ]);
        
        if ($result) {
            @logActivity($userId, 'Offer Created', 'offers', $result, 'Created offer for application #' . $applicationId);
            echo json_encode(['success' => true, 'message' => 'Offer created successfully!', 'id' => $result]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create offer.']);
        }
        exit;
    }
    
    // ========== UPDATE OFFER - PostgreSQL ==========
    if ($action === 'update_offer' && $offerId > 0) {
        $offerDate = $_POST['offer_date'] ?? date('Y-m-d');
        $startDate = $_POST['start_date'] ?? null;
        $salaryOffered = $_POST['salary_offered'] ?? null;
        $benefits = trim($_POST['benefits'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        
        if (!empty($salaryOffered)) {
            $salaryOffered = (float)preg_replace('/[^0-9.]/', '', $salaryOffered);
        } else {
            $salaryOffered = null;
        }
        
        if (empty($startDate)) $startDate = null;
        if (empty($benefits)) $benefits = null;
        
        $sql = "UPDATE offers SET 
                offer_date = $1,
                start_date = $2,
                salary_offered = $3,
                benefits = $4,
                status = $5,
                updated_at = NOW()
                WHERE id = $6";
        
        $result = @updateRecord($sql, [
            $offerDate,
            $startDate,
            $salaryOffered,
            $benefits,
            $status,
            $offerId
        ]);
        
        if ($result) {
            if ($status === 'sent') {
                $offer = @getRecord("SELECT application_id FROM offers WHERE id = $1", [$offerId]);
                if ($offer) {
                    @updateRecord("UPDATE applications SET status = 'offered' WHERE id = $1", [$offer['application_id']]);
                }
            }
            @logActivity($userId, 'Offer Updated', 'offers', $offerId, 'Updated offer #' . $offerId);
            echo json_encode(['success' => true, 'message' => 'Offer updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update offer.']);
        }
        exit;
    }
    
    // ========== GET OFFER - PostgreSQL ==========
    if ($action === 'get_offer' && $offerId > 0) {
        $offer = @getRecord("
            SELECT o.*, 
                   u.first_name, u.last_name, u.email,
                   jo.title as job_title, c.company_name,
                   a.id as application_id,
                   ap.skills, ap.experience
            FROM offers o
            JOIN applications a ON o.application_id = a.id
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            WHERE o.id = $1 AND jo.created_by = $2
        ", [$offerId, $userId]);
        
        if ($offer) {
            echo json_encode(['success' => true, 'offer' => $offer]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Offer not found.']);
        }
        exit;
    }
    
    // ========== SEND OFFER - PostgreSQL ==========
    if ($action === 'send_offer' && $offerId > 0) {
        $result = @updateRecord("UPDATE offers SET status = 'sent', sent_at = NOW(), updated_at = NOW() WHERE id = $1", [$offerId]);
        
        if ($result) {
            $offer = @getRecord("
                SELECT o.*, u.first_name, u.last_name, u.email,
                       jo.title as job_title, c.company_name
                FROM offers o
                JOIN applications a ON o.application_id = a.id
                JOIN applicants ap ON a.applicant_id = ap.id
                JOIN users u ON ap.user_id = u.id
                JOIN job_orders jo ON a.job_order_id = jo.id
                JOIN clients c ON jo.client_id = c.id
                WHERE o.id = $1
            ", [$offerId]);
            
            if ($offer) {
                @updateRecord("UPDATE applications SET status = 'offered' WHERE id = $1", [$offer['application_id']]);
                @logActivity($userId, 'Offer Sent', 'offers', $offerId, 'Sent offer #' . $offerId . ' to ' . $offer['first_name'] . ' ' . $offer['last_name']);
                
                // Try to send email if function exists
                if (function_exists('sendOfferEmail')) {
                    try {
                        @sendOfferEmail($offerId);
                    } catch (Exception $e) {
                        @error_log("Email sending failed: " . $e->getMessage());
                    }
                }
                echo json_encode(['success' => true, 'message' => 'Offer sent successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Offer details not found.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to send offer.']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Offers - ISMERS AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           OFFERS MANAGEMENT - AI EDITION
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
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
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

        /* AI Badge Styles */
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

        /* AI Prediction Card */
        .ai-prediction-card {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-top: 0.75rem;
        }
        .ai-prediction-card .prediction-score {
            font-size: 1.75rem;
            font-weight: 800;
            display: inline-block;
        }
        .ai-prediction-card .prediction-score.high { color: #059669; }
        .ai-prediction-card .prediction-score.medium { color: #d97706; }
        .ai-prediction-card .prediction-score.low { color: #dc2626; }
        .ai-prediction-card .prediction-details {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* AI Salary Recommendation */
        .ai-salary-box {
            background: var(--bg-surface-low);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-top: 0.5rem;
        }
        .ai-salary-box .salary-range {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
        }
        .ai-salary-box .salary-recommended {
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }
        .ai-salary-box .salary-provider {
            font-size: 0.55rem;
            color: var(--text-on-surface-variant);
            opacity: 0.6;
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

        .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-main-link.active { background: var(--bg-surface-container-high); color: var(--primary); }
        .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
        .sidebar-main-link .nav-text { transition: opacity 0.3s ease; }
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

        .sidebar-footer .user-card .user-info .user-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.75rem; color: var(--text-on-surface-variant); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

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
        .profile-dropdown-menu .dropdown-header { padding: 0.25rem 0.75rem 0.25rem; font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-on-surface-variant); }
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
            box-shadow: var(--shadow-sm);
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
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-ai {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
        }
        .btn-ai:hover {
            background: linear-gradient(135deg, #6d28d9, #4338ca);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }
        .btn-ai .material-symbols-outlined { font-size: 1rem; }

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
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.primary { background: #eef0ff; color: #4f46e5; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.25rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 180px;
            position: relative;
        }
        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.125rem;
        }
        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.5rem 0.875rem 0.5rem 2.5rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .filter-btn {
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            transition: all var(--transition-fast);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 2px 10px rgba(79, 70, 229, 0.25); }
        .filter-btn .count {
            background: rgba(0,0,0,0.08);
            border-radius: var(--radius-full);
            padding: 0 0.375rem;
            font-size: 0.625rem;
            font-weight: 700;
        }
        .filter-btn.active .count { background: rgba(255,255,255,0.25); }

        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-header h3 .material-symbols-outlined { font-size: 1.125rem; color: var(--primary); }
        .card-header .count-badge {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
        }
        .card-body { padding: 0; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            min-width: 750px;
        }
        table thead { background: var(--bg-surface-low); }
        table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        table tbody tr:hover td { background: var(--bg-surface-low); }
        table tbody tr:last-child td { border-bottom: none; }

        .applicant-cell {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .applicant-cell .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.6875rem;
            flex-shrink: 0;
        }
        .applicant-cell .info .name { font-weight: 600; color: var(--text-on-surface); }
        .applicant-cell .info .email { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

        .job-cell .title { font-weight: 500; }
        .job-cell .company { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-draft { background: #f3f4f6; color: #6b7280; }
        .badge-sent { background: #dbeafe; color: #2563eb; }
        .badge-accepted { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-expired { background: #fef3c7; color: #d97706; }

        .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; justify-content: center; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.5rem;
        }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); }
        .empty-state p { font-size: 0.8125rem; color: var(--text-on-surface-variant); }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
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
            max-width: 44rem;
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
            padding: 1.125rem 1.5rem;
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
        .modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.1875rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-group .form-control {
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
        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-group textarea.form-control { resize: vertical; min-height: 60px; }
        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
        .helper-text {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.1875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .helper-text .material-symbols-outlined { font-size: 0.875rem; }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        .checkbox-group input[type="checkbox"] {
            width: 1.125rem;
            height: 1.125rem;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .checkbox-group label {
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            margin-bottom: 0;
        }

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
            .search-bar { flex-direction: column; }
            .filters { overflow-x: auto; flex-wrap: nowrap; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
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
            .stats-row { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.25rem; }
            table { font-size: 0.75rem; min-width: 500px; }
            table th, table td { padding: 0.375rem 0.5rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }

        .loading-spinner {
            text-align: center;
            padding: 1.5rem;
        }
        .loading-spinner .spinner {
            width: 2rem;
            height: 2rem;
            border: 3px solid var(--slate-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
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
    </style>
</head>
<body>

<!-- =============================================
AI LOADING OVERLAY
============================================= -->
<div class="ai-loading-overlay" id="aiLoadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(10,14,26,0.6); backdrop-filter:blur(8px); z-index:9999; justify-content:center; align-items:center; flex-direction:column;">
    <div class="ai-loading-box" style="background:var(--bg-surface); border-radius:var(--radius-2xl); padding:2.5rem 3rem; max-width:400px; width:90%; text-align:center; box-shadow:var(--shadow-xl); animation:modalSlideUp 0.3s ease-out;">
        <span class="loading-icon material-symbols-outlined" style="font-size:3rem; color:var(--primary); margin-bottom:1rem; display:block;">auto_awesome</span>
        <div class="loading-title" id="loadingTitle" style="font-size:1.125rem; font-weight:700; color:var(--text-on-surface); margin-bottom:0.5rem;">Analyzing with AI</div>
        <div class="loading-subtitle" id="loadingSubtitle" style="font-size:0.875rem; color:var(--text-on-surface-variant); margin-bottom:1.5rem;">Calculating the best offer strategy</div>
        <div class="dot-loader" style="display:flex; justify-content:center; gap:0.5rem; padding:0.5rem 0;">
            <span class="dot" style="width:0.75rem; height:0.75rem; border-radius:50%; background:var(--primary); animation:dotBounce 1.4s infinite ease-in-out both;"></span>
            <span class="dot" style="width:0.75rem; height:0.75rem; border-radius:50%; background:var(--primary); animation:dotBounce 1.4s infinite ease-in-out both; animation-delay:-0.32s;"></span>
            <span class="dot" style="width:0.75rem; height:0.75rem; border-radius:50%; background:var(--primary); animation:dotBounce 1.4s infinite ease-in-out both; animation-delay:-0.16s;"></span>
            <span class="dot" style="width:0.75rem; height:0.75rem; border-radius:50%; background:var(--primary); animation:dotBounce 1.4s infinite ease-in-out both; animation-delay:0s;"></span>
            <span class="dot" style="width:0.75rem; height:0.75rem; border-radius:50%; background:var(--primary); animation:dotBounce 1.4s infinite ease-in-out both; animation-delay:0.16s;"></span>
        </div>
        <div class="loading-status" style="font-size:0.8125rem; color:var(--text-on-surface-variant); margin-top:0.75rem; min-height:1.5rem;">
            <span class="status-text" id="loadingStatus" style="display:inline-block;">Processing</span>
            <span class="status-dots" style="animation:statusDots 1.5s steps(4) infinite;"></span>
        </div>
    </div>
</div>

<style>
    @keyframes dotBounce {
        0%, 80%, 100% { transform: scale(0); opacity: 0.4; }
        40% { transform: scale(1); opacity: 1; }
    }
    @keyframes statusDots {
        0% { content: ''; }
        25% { content: '.'; }
        50% { content: '..'; }
        75% { content: '...'; }
        100% { content: ''; }
    }
    @keyframes modalSlideUp {
        from { opacity: 0; transform: translateY(20px) scale(0.96); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    [data-tooltip] {
        position: relative;
        cursor: pointer;
    }
    [data-tooltip]:before {
        content: attr(data-tooltip);
        position: absolute;
        bottom: calc(100% + 0.5rem);
        left: 50%;
        transform: translateX(-50%) scale(0.9);
        padding: 0.375rem 0.75rem;
        background: var(--slate-900);
        color: white;
        font-size: 0.6875rem;
        font-weight: 500;
        border-radius: 0.375rem;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease;
        pointer-events: none;
        box-shadow: var(--shadow-md);
    }
    [data-tooltip]:after {
        content: '';
        position: absolute;
        bottom: calc(100% + 0.25rem);
        left: 50%;
        transform: translateX(-50%) scale(0.9);
        border: 0.375rem solid transparent;
        border-top-color: var(--slate-900);
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease;
    }
    [data-tooltip]:hover:before,
    [data-tooltip]:hover:after {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) scale(1);
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
        </a>
        <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">people</span>
            <span class="nav-text">Applicants</span>
            <span class="nav-badge"><?php echo $pendingAppsCount; ?></span>
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
            <span class="nav-badge"><?php echo $totalArchived; ?></span>
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
            <!-- Breadcrumb -->
            <div class="breadcrumb-bar">
                <div class="breadcrumb-view">
                    <span class="material-symbols-outlined">description</span>
                    <span>Offers</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> (<?php echo count($offers); ?>)
                    </span>
                </div>
                <span class="breadcrumb-meta">Updated <?php echo date('M d, Y H:i'); ?></span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Offer Management</h1>
                    <p style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                        Create and manage job offers with AI-powered insights
                        <span class="ai-badge">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            AI Enhanced
                        </span>
                    </p>
                </div>
                <div>
                    <button class="btn btn-ai" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add</span>
                        Create Offer
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <span class="material-symbols-outlined">description</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['draft'] ?? 0; ?></div>
                        <div class="stat-label">Drafts</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <span class="material-symbols-outlined">send</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['sent'] ?? 0; ?></div>
                        <div class="stat-label">Sent</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">
                        <span class="material-symbols-outlined">check_circle</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['accepted'] ?? 0; ?></div>
                        <div class="stat-label">Accepted</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red">
                        <span class="material-symbols-outlined">cancel</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['rejected'] ?? 0; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>
            </div>

            <!-- Search -->
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" id="searchInput" placeholder="Search by name, email, or job..." 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                <?php if (!empty($searchQuery) || $statusFilter !== 'all'): ?>
                    <a href="offers.php" class="btn btn-outline">Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filters">
                <?php foreach ($allStatuses as $key => $label): ?>
                    <a href="?status=<?php echo $key; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                       class="filter-btn <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                        <?php echo $label; ?>
                        <span class="count"><?php echo $statusCounts[$key] ?? 0; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Offers Table -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <span class="material-symbols-outlined">description</span>
                        <?php echo $statusFilter === 'all' ? 'All Offers' : ucfirst($statusFilter) . ' Offers'; ?>
                        <span class="ai-badge" style="font-size:0.55rem;">
                            <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                            AI
                        </span>
                    </h3>
                    <span class="count-badge"><?php echo count($offers); ?> offers</span>
                </div>
                <div class="card-body">
                    <?php if (empty($offers)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">description</span>
                            <h4>No Offers Found</h4>
                            <p>No offers have been created yet.</p>
                            <button class="btn btn-ai" onclick="openCreateModal()" style="margin-top:0.75rem;">
                                <span class="material-symbols-outlined">add</span>
                                Create First Offer
                            </button>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Candidate</th>
                                    <th>Position</th>
                                    <th>Offer Date</th>
                                    <th>Salary</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($offers as $offer): ?>
                                    <tr>
                                        <td>
                                            <div class="applicant-cell">
                                                <span class="avatar">
                                                    <?php echo strtoupper(substr($offer['first_name'] ?? 'U', 0, 1)); ?>
                                                </span>
                                                <div class="info">
                                                    <div class="name"><?php echo htmlspecialchars($offer['first_name'] . ' ' . $offer['last_name']); ?></div>
                                                    <div class="email"><?php echo htmlspecialchars($offer['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="job-cell">
                                                <div class="title"><?php echo htmlspecialchars($offer['job_title'] ?? 'Position'); ?></div>
                                                <div class="company"><?php echo htmlspecialchars($offer['company_name'] ?? ''); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size:0.8125rem;">
                                                <?php echo date('M d, Y', strtotime($offer['offer_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600; color:var(--text-on-surface);">
                                                <?php echo !empty($offer['salary_offered']) ? '₱' . number_format($offer['salary_offered'], 2) : '—'; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $statusBadges[$offer['status']] ?? 'badge-draft'; ?>">
                                                <?php echo $statusLabels[$offer['status']] ?? ucfirst($offer['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-primary btn-sm" onclick="viewOffer(<?php echo $offer['id']; ?>)" data-tooltip="View offer details">
                                                    <span class="material-symbols-outlined">visibility</span>
                                                </button>
                                                <?php if ($offer['status'] === 'draft'): ?>
                                                    <button class="btn btn-outline btn-sm" onclick="editOffer(<?php echo $offer['id']; ?>)" data-tooltip="Edit offer">
                                                        <span class="material-symbols-outlined">edit</span>
                                                    </button>
                                                    <button class="btn btn-success btn-sm" onclick="sendOffer(<?php echo $offer['id']; ?>)" data-tooltip="Send offer to candidate">
                                                        <span class="material-symbols-outlined">send</span>
                                                        Send
                                                    </button>
                                                    <button class="btn btn-ai btn-sm" onclick="getAISalarySuggestion(<?php echo $offer['id']; ?>)" data-tooltip="Get AI salary recommendation">
                                                        <span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- =============================================
MODAL: Create/Edit Offer with AI
============================================= -->
<div class="modal-overlay" id="offerModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">description</span>
                <span id="modalTitle">Create Offer</span>
                <span class="ai-badge" style="font-size:0.55rem; margin-left:0.5rem;">
                    <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                    AI
                </span>
            </h2>
            <button class="modal-close" onclick="closeModal('offerModal')" data-tooltip="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form id="offerForm" onsubmit="submitOffer(event)">
                <input type="hidden" id="offerId" name="offer_id" value="0">
                <input type="hidden" id="formAction" name="action" value="create_offer">
                
                <div class="form-group">
                    <label for="applicationSelect">Select Applicant <span class="required">*</span></label>
                    <select id="applicationSelect" name="application_id" class="form-control" required onchange="updateAIFields()">
                        <option value="">— Select an applicant —</option>
                        <?php foreach ($eligibleApplicants as $app): ?>
                            <option value="<?php echo $app['id']; ?>" 
                                    data-job-title="<?php echo htmlspecialchars($app['job_title']); ?>"
                                    data-skills="<?php echo htmlspecialchars($app['skills'] ?? ''); ?>"
                                    data-experience="<?php echo (int)($app['experience'] ?? 0); ?>"
                                    data-name="<?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>">
                                <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name'] . ' - ' . $app['job_title'] . ' (' . ucfirst($app['application_status']) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">info</span>
                        Only interviewed or shortlisted applicants can receive offers
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="offerDate">Offer Date <span class="required">*</span></label>
                        <input type="date" id="offerDate" name="offer_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="startDate">Start Date</label>
                        <input type="date" id="startDate" name="start_date" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="salaryOffered">Salary Offered</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="text" id="salaryOffered" name="salary_offered" class="form-control" placeholder="e.g., 60000" style="flex:1;">
                        <button type="button" class="btn btn-ai btn-sm" onclick="getAISalaryForForm()" data-tooltip="Get AI salary recommendation" style="white-space:nowrap;">
                            <span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span>
                            AI Suggest
                        </button>
                    </div>
                    <div id="aiSalaryResult" style="display:none;" class="ai-salary-box">
                        <div class="salary-range" id="aiSalaryRange">₱50,000 - ₱80,000</div>
                        <div class="salary-recommended">💡 Recommended: <strong id="aiSalaryRecommended">₱65,000</strong></div>
                        <div class="salary-provider">Powered by: <span id="aiSalaryProvider">Groq</span></div>
                    </div>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">payments</span>
                        Enter the salary amount in PHP. Click "AI Suggest" for recommendation
                    </div>
                </div>

                <!-- AI Prediction Card -->
                <div id="aiPredictionContainer" style="display:none;" class="ai-prediction-card">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <span class="ai-badge">
                            <span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span>
                            Acceptance Prediction
                        </span>
                        <span class="prediction-score" id="predictionScore">75%</span>
                        <span id="predictionEmoji" style="font-size:1.25rem;">📊</span>
                    </div>
                    <div class="prediction-details" id="predictionMessage">Moderate likelihood of acceptance.</div>
                </div>
                
                <div class="form-group">
                    <label for="benefits">Benefits</label>
                    <textarea id="benefits" name="benefits" class="form-control" placeholder="List any benefits included..." rows="2"></textarea>
                </div>

                <!-- AI Optimization Tips -->
                <div id="aiTipsContainer" style="display:none; margin-top:0.5rem;">
                    <div class="ai-tips-box" style="background:linear-gradient(135deg,#f5f3ff,#ede9fe); border:1px solid #c4b5fd; border-radius:var(--radius-md); padding:0.75rem 1rem;">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
                            <span class="ai-badge">
                                <span class="material-symbols-outlined" style="font-size:0.75rem;">lightbulb</span>
                                AI Offer Tips
                            </span>
                        </div>
                        <div id="aiTipsList" style="font-size:0.8125rem; color:var(--text-on-surface);">
                            <div class="tip-item" style="padding:0.125rem 0;">• Personalized the offer with the candidate's name</div>
                            <div class="tip-item" style="padding:0.125rem 0;">• Highlight company culture and growth opportunities</div>
                            <div class="tip-item" style="padding:0.125rem 0;">• Include a clear breakdown of compensation</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group" id="statusField" style="display:none;">
                    <label for="editStatus">Status</label>
                    <select id="editStatus" name="status" class="form-control">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo $status; ?>"><?php echo $statusLabels[$status]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('offerModal')">Cancel</button>
            <button class="btn btn-ai" id="submitBtn" onclick="document.getElementById('offerForm').dispatchEvent(new Event('submit'))">
                <span class="material-symbols-outlined">check</span>
                <span id="submitBtnText">Create Offer</span>
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: View Offer Details
============================================= -->
<div class="modal-overlay" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">visibility</span>
                Offer Details
                <span class="ai-badge" style="font-size:0.55rem; margin-left:0.5rem;">
                    <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                    AI
                </span>
            </h2>
            <button class="modal-close" onclick="closeModal('viewModal')" data-tooltip="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div class="loading-spinner" id="viewLoading">
                <div style="text-align:center; padding:1.5rem;">
                    <div style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div>
                    <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading...</p>
                </div>
            </div>
            <div id="viewContent" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
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
    if (!id) id = 'offerModal';
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
        const offerModal = document.getElementById('offerModal');
        const viewModal = document.getElementById('viewModal');
        if (offerModal && offerModal.classList.contains('active')) {
            closeModal('offerModal');
        } else if (viewModal && viewModal.classList.contains('active')) {
            closeModal('viewModal');
        }
        closeMobileSidebar();
        if (profileToggle) profileToggle.classList.remove('open');
        if (profileMenu) profileMenu.classList.remove('open');
    }
});

// =============================================
// 5. AI LOADING OVERLAY
// =============================================
const loadingOverlay = document.getElementById('aiLoadingOverlay');
const loadingTitle = document.getElementById('loadingTitle');
const loadingSubtitle = document.getElementById('loadingSubtitle');
const loadingStatus = document.getElementById('loadingStatus');

function showLoading(title = 'Analyzing with AI', subtitle = 'Calculating the best offer strategy') {
    if (loadingTitle) loadingTitle.textContent = title;
    if (loadingSubtitle) loadingSubtitle.textContent = subtitle;
    if (loadingOverlay) loadingOverlay.style.display = 'flex';
    
    const statusMessages = ['Processing', 'Analyzing data', 'Generating insights', 'Finalizing results'];
    let index = 0;
    const interval = setInterval(() => {
        index++;
        if (index < statusMessages.length && loadingStatus) {
            loadingStatus.textContent = statusMessages[index];
        } else {
            clearInterval(interval);
        }
    }, 1500);
    return interval;
}

function hideLoading(interval) {
    if (interval) clearInterval(interval);
    if (loadingOverlay) loadingOverlay.style.display = 'none';
}

// =============================================
// 6. CREATE OFFER
// =============================================
function openCreateModal() {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const offerId = document.getElementById('offerId');
    const submitBtnText = document.getElementById('submitBtnText');
    const statusField = document.getElementById('statusField');
    const offerForm = document.getElementById('offerForm');
    const offerDate = document.getElementById('offerDate');
    const aiSalaryResult = document.getElementById('aiSalaryResult');
    const aiPredictionContainer = document.getElementById('aiPredictionContainer');
    const aiTipsContainer = document.getElementById('aiTipsContainer');
    
    if (modalTitle) modalTitle.textContent = 'Create Offer';
    if (formAction) formAction.value = 'create_offer';
    if (offerId) offerId.value = '0';
    if (submitBtnText) submitBtnText.textContent = 'Create Offer';
    if (statusField) statusField.style.display = 'none';
    if (offerForm) offerForm.reset();
    if (aiSalaryResult) aiSalaryResult.style.display = 'none';
    if (aiPredictionContainer) aiPredictionContainer.style.display = 'none';
    if (aiTipsContainer) aiTipsContainer.style.display = 'none';
    
    const today = new Date().toISOString().split('T')[0];
    if (offerDate) offerDate.value = today;
    
    openModal('offerModal');
}

// =============================================
// 7. UPDATE AI FIELDS
// =============================================
function updateAIFields() {
    const select = document.getElementById('applicationSelect');
    const selectedOption = select.options[select.selectedIndex];
    const aiSalaryResult = document.getElementById('aiSalaryResult');
    const aiPredictionContainer = document.getElementById('aiPredictionContainer');
    const aiTipsContainer = document.getElementById('aiTipsContainer');
    
    if (selectedOption && selectedOption.value) {
        if (aiSalaryResult) aiSalaryResult.style.display = 'block';
        if (aiPredictionContainer) aiPredictionContainer.style.display = 'block';
        if (aiTipsContainer) aiTipsContainer.style.display = 'block';
        
        getAISalaryForForm();
        getAIPredictionForForm();
        getAITipsForForm();
    } else {
        if (aiSalaryResult) aiSalaryResult.style.display = 'none';
        if (aiPredictionContainer) aiPredictionContainer.style.display = 'none';
        if (aiTipsContainer) aiTipsContainer.style.display = 'none';
    }
}

// =============================================
// 8. GET AI SALARY FOR FORM
// =============================================
function getAISalaryForForm() {
    const select = document.getElementById('applicationSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption || !selectedOption.value) {
        showToast('Please select an applicant first.', 'info');
        return;
    }
    
    const jobTitle = selectedOption.dataset.jobTitle || '';
    const skills = selectedOption.dataset.skills || '';
    const experience = parseInt(selectedOption.dataset.experience) || 0;
    
    const interval = showLoading('Analyzing Salary Data', 'Calculating market rate for this position');
    
    const formData = new FormData();
    formData.append('action', 'get_salary_recommendation');
    formData.append('job_title', jobTitle);
    formData.append('applicant_skills', skills);
    formData.append('experience', experience);
    
    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(interval);
        if (data.success) {
            const rec = data.recommendation;
            const salaryInput = document.getElementById('salaryOffered');
            const rangeDisplay = document.getElementById('aiSalaryRange');
            const recommendedDisplay = document.getElementById('aiSalaryRecommended');
            const providerDisplay = document.getElementById('aiSalaryProvider');
            const resultBox = document.getElementById('aiSalaryResult');
            
            if (salaryInput && !salaryInput.value) {
                salaryInput.value = rec.recommended || '';
            }
            if (rangeDisplay) rangeDisplay.textContent = rec.range_display || '₱50,000 - ₱80,000';
            if (recommendedDisplay) recommendedDisplay.textContent = '₱' + Number(rec.recommended || 65000).toLocaleString();
            if (providerDisplay) providerDisplay.textContent = rec.provider || 'Groq';
            if (resultBox) resultBox.style.display = 'block';
            
            getAIPredictionForForm();
        } else {
            showToast('Failed to get salary recommendation.', 'error');
        }
    })
    .catch(error => {
        hideLoading(interval);
        showToast('Error getting salary recommendation.', 'error');
    });
}

// =============================================
// 9. GET AI PREDICTION FOR FORM
// =============================================
function getAIPredictionForForm() {
    const select = document.getElementById('applicationSelect');
    const selectedOption = select.options[select.selectedIndex];
    const salaryInput = document.getElementById('salaryOffered');
    
    if (!selectedOption || !selectedOption.value) return;
    
    const jobTitle = selectedOption.dataset.jobTitle || '';
    const skills = selectedOption.dataset.skills || '';
    const experience = parseInt(selectedOption.dataset.experience) || 0;
    const salary = parseInt(salaryInput.value) || 0;
    
    const formData = new FormData();
    formData.append('action', 'predict_acceptance');
    formData.append('job_title', jobTitle);
    formData.append('applicant_skills', skills);
    formData.append('experience', experience);
    formData.append('salary_offered', salary);
    
    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.prediction) {
            const pred = data.prediction;
            const scoreDisplay = document.getElementById('predictionScore');
            const emojiDisplay = document.getElementById('predictionEmoji');
            const messageDisplay = document.getElementById('predictionMessage');
            const container = document.getElementById('aiPredictionContainer');
            
            if (scoreDisplay) {
                scoreDisplay.textContent = pred.score + '%';
                scoreDisplay.className = 'prediction-score ' + pred.level.toLowerCase();
            }
            if (emojiDisplay) emojiDisplay.textContent = pred.emoji || '📊';
            if (messageDisplay) messageDisplay.textContent = pred.message || '';
            if (container) container.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Prediction error:', error);
    });
}

// =============================================
// 10. GET AI TIPS FOR FORM
// =============================================
function getAITipsForForm() {
    const select = document.getElementById('applicationSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption || !selectedOption.value) return;
    
    const jobTitle = selectedOption.dataset.jobTitle || '';
    const applicantName = selectedOption.dataset.name || 'the candidate';
    
    const formData = new FormData();
    formData.append('action', 'get_offer_tips');
    formData.append('job_title', jobTitle);
    formData.append('applicant_name', applicantName);
    
    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.tips) {
            const tipsList = document.getElementById('aiTipsList');
            if (tipsList) {
                tipsList.innerHTML = data.tips.map(tip => 
                    `<div class="tip-item" style="padding:0.125rem 0;">• ${escapeHtml(tip)}</div>`
                ).join('');
            }
            const container = document.getElementById('aiTipsContainer');
            if (container) container.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Tips error:', error);
    });
}

// =============================================
// 11. EDIT OFFER
// =============================================
function editOffer(id) {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const offerId = document.getElementById('offerId');
    const submitBtnText = document.getElementById('submitBtnText');
    const statusField = document.getElementById('statusField');
    const applicationSelect = document.getElementById('applicationSelect');
    
    if (modalTitle) modalTitle.textContent = 'Edit Offer';
    if (formAction) formAction.value = 'update_offer';
    if (offerId) offerId.value = id;
    if (submitBtnText) submitBtnText.textContent = 'Update Offer';
    if (statusField) statusField.style.display = 'block';
    if (applicationSelect) applicationSelect.disabled = true;

    const formData = new FormData();
    formData.append('action', 'get_offer');
    formData.append('offer_id', id);

    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const offer = data.offer;
            const appSelect = document.getElementById('applicationSelect');
            const offerDate = document.getElementById('offerDate');
            const startDate = document.getElementById('startDate');
            const salaryOffered = document.getElementById('salaryOffered');
            const benefits = document.getElementById('benefits');
            const editStatus = document.getElementById('editStatus');
            
            if (appSelect) appSelect.value = offer.application_id;
            if (offerDate) offerDate.value = offer.offer_date;
            if (startDate) startDate.value = offer.start_date || '';
            if (salaryOffered) salaryOffered.value = offer.salary_offered || '';
            if (benefits) benefits.value = offer.benefits || '';
            if (editStatus) editStatus.value = offer.status;
            openModal('offerModal');
        } else {
            showToast(data.error || 'Failed to load offer.', 'error');
        }
    })
    .catch(error => {
        console.error('Edit error:', error);
        showToast('Error loading offer details.', 'error');
    });
}

// =============================================
// 12. SUBMIT OFFER
// =============================================
function submitOffer(event) {
    event.preventDefault();
    
    const form = document.getElementById('offerForm');
    if (!form) return;
    
    const formData = new FormData(form);
    
    const applicationSelect = document.getElementById('applicationSelect');
    if (!applicationSelect || !applicationSelect.value) {
        showToast('Please select an applicant.', 'error');
        return;
    }
    
    const btn = document.getElementById('submitBtn');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block; width:1rem; height:1rem; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Saving...';

    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            showToast(data.message, 'success');
            closeModal('offerModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to save offer.', 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('Error saving offer. Please try again.', 'error');
    });
}

// =============================================
// 13. VIEW OFFER
// =============================================
function viewOffer(id) {
    openModal('viewModal');
    
    const loading = document.getElementById('viewLoading');
    const content = document.getElementById('viewContent');
    
    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';

    const formData = new FormData();
    formData.append('action', 'get_offer');
    formData.append('offer_id', id);

    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        if (data.success) {
            const o = data.offer;
            const statusBadges = <?php echo json_encode($statusBadges); ?>;
            const statusLabels = <?php echo json_encode($statusLabels); ?>;
            
            if (content) {
                content.innerHTML = `
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Candidate</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(o.first_name)} ${escapeHtml(o.last_name)}</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(o.email)}</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Position</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(o.job_title)}</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(o.company_name)}</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Offer Date</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${new Date(o.offer_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Status</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">
                                <span class="badge ${statusBadges[o.status] || 'badge-draft'}">${statusLabels[o.status] || o.status}</span>
                            </div>
                        </div>
                        ${o.start_date ? `
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Start Date</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${new Date(o.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                        </div>
                        ` : ''}
                        ${o.salary_offered ? `
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Salary</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem; color:#059669;">₱${Number(o.salary_offered).toLocaleString()}</div>
                        </div>
                        ` : ''}
                        ${o.benefits ? `
                        <div style="grid-column:1/-1;">
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Benefits</div>
                            <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(o.benefits)}</div>
                        </div>
                        ` : ''}
                    </div>
                `;
            }
        } else {
            if (content) {
                content.innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">${data.error || 'Failed to load offer details.'}</p>
                    </div>
                `;
            }
        }
    })
    .catch(error => {
        if (loading) loading.style.display = 'none';
        if (content) {
            content.style.display = 'block';
            content.innerHTML = `
                <div style="text-align:center; padding:1rem; color:#dc2626;">
                    <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                    <p style="margin-top:0.5rem;">Error loading offer details. Please try again.</p>
                </div>
            `;
        }
    });
}

// =============================================
// 14. SEND OFFER
// =============================================
function sendOffer(id) {
    if (!confirm('Send this offer to the candidate? The candidate will receive an email notification.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_offer');
    formData.append('offer_id', id);

    showToast('Sending offer...', 'info');

    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to send offer.', 'error');
        }
    })
    .catch(error => {
        showToast('Error sending offer.', 'error');
    });
}

// =============================================
// 15. GET AI SALARY SUGGESTION (from table)
// =============================================
function getAISalarySuggestion(offerId) {
    const formData = new FormData();
    formData.append('action', 'get_offer');
    formData.append('offer_id', offerId);
    
    fetch('offers.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const offer = data.offer;
            const interval = showLoading('Analyzing Salary', 'Calculating competitive offer');
            
            const salaryFormData = new FormData();
            salaryFormData.append('action', 'get_salary_recommendation');
            salaryFormData.append('job_title', offer.job_title || '');
            salaryFormData.append('applicant_skills', offer.skills || '');
            salaryFormData.append('experience', parseInt(offer.experience) || 0);
            
            return fetch('offers.php', {
                method: 'POST',
                body: salaryFormData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        } else {
            throw new Error('Failed to get offer details');
        }
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(interval);
        if (data.success && data.recommendation) {
            const rec = data.recommendation;
            showToast(`💡 AI Recommended Salary: ₱${Number(rec.recommended).toLocaleString()} (Range: ${rec.range_display})`, 'success');
        } else {
            showToast('Failed to get salary recommendation.', 'error');
        }
    })
    .catch(error => {
        hideLoading(interval);
        showToast('Error getting salary recommendation.', 'error');
    });
}

// =============================================
// 16. SEARCH & FILTERS
// =============================================
function applyFilters() {
    const search = document.getElementById('searchInput');
    if (!search) return;
    
    const status = '<?php echo $statusFilter; ?>';
    let url = 'offers.php?';
    if (status !== 'all') url += 'status=' + status + '&';
    if (search.value) url += 'search=' + encodeURIComponent(search.value);
    window.location.href = url;
}

const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
}

// =============================================
// 17. TOAST SYSTEM
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
// 18. UTILITY FUNCTIONS
// =============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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
// 19. RESPONSIVE HANDLING
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

console.log('📄 ISMERS Offers Management with AI Integration loaded successfully!');
console.log('🤖 AI Features: Salary Recommendations, Acceptance Prediction, Offer Tips');
</script>

</body>
</html>