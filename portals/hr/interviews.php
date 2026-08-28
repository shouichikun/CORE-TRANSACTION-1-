<?php
// portals/hr/interviews.php - Interview Management with AI Integration
// FIXED: Default scheduled filter + Auto no-show for past interviews

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
// FUNCTION: Auto-update no-show interviews
// =============================================
function autoUpdateNoShowInterviews() {
    global $userId;
    
    // Find scheduled interviews that have passed their date
    $pastInterviews = @getRecords("
        SELECT i.id, i.application_id, i.interview_date, i.status,
               u.first_name, u.last_name, u.email,
               jo.title as job_title
        FROM interviews i
        JOIN applications a ON i.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE i.status = 'scheduled' 
        AND i.interview_date < NOW()
        AND jo.created_by = $1
    ", [$userId]);
    
    if (empty($pastInterviews)) {
        return 0;
    }
    
    $updatedCount = 0;
    
    foreach ($pastInterviews as $interview) {
        // Update to no_show
        $updateResult = @updateRecord(
            "UPDATE interviews SET status = 'no_show', 
             notes = CONCAT(COALESCE(notes, ''), '\n[Auto] Interview passed without completion - marked as no-show.'),
             updated_at = NOW() 
             WHERE id = $1",
            [$interview['id']]
        );
        
        if ($updateResult) {
            $updatedCount++;
            
            // Update application status back to shortlisted
            @updateRecord(
                "UPDATE applications SET status = 'shortlisted', updated_at = NOW() WHERE id = $1",
                [$interview['application_id']]
            );
            
            // Log activity
            @logActivity($userId, 'Auto No-Show', 'interviews', $interview['id'], 
                'Interview auto-marked as no-show for ' . $interview['first_name'] . ' ' . $interview['last_name']);
        }
    }
    
    return $updatedCount;
}

// =============================================
// FUNCTION: Move Interview to Archive - FIXED PostgreSQL
// =============================================
function moveInterviewToArchive($interviewId, $rating, $feedback, $recommendation = 'consider') {
    global $userId;
    
    $interview = @getRecord("
        SELECT i.*, 
               a.applicant_id, a.job_order_id, a.id as application_id,
               u.first_name, u.last_name, u.email,
               jo.title as job_title, jo.client_id,
               c.company_name,
               i.ai_questions
        FROM interviews i
        JOIN applications a ON i.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        WHERE i.id = $1
    ", [$interviewId]);
    
    if (!$interview) {
        return ['success' => false, 'error' => 'Interview not found.'];
    }
    
    $checkArchive = @getRecord("
        SELECT id FROM interview_evaluations 
        WHERE interview_id = $1 OR (application_id = $2 AND applicant_id = $3)
    ", [$interviewId, $interview['application_id'], $interview['applicant_id']]);
    
    if ($checkArchive) {
        $updateSql = "UPDATE interview_evaluations SET 
                      overall_rating = $1, 
                      comments = $2,
                      recommendation = $3,
                      evaluator_id = $4,
                      evaluation_date = NOW(),
                      updated_at = NOW()
                      WHERE id = $5";
        $updateResult = @updateRecord($updateSql, [
            $rating,
            $feedback,
            $recommendation,
            $userId,
            $checkArchive['id']
        ]);
        
        if ($updateResult) {
            return ['success' => true, 'message' => 'Archive record updated.'];
        }
        return ['success' => false, 'error' => 'Failed to update archive record.'];
    }
    
    $archiveSql = "INSERT INTO interview_evaluations (
        interview_id, application_id, applicant_id, job_order_id, evaluator_id,
        evaluation_date, overall_rating, recommendation, comments,
        created_at, updated_at
    ) VALUES ($1, $2, $3, $4, $5, NOW(), $6, $7, $8, NOW(), NOW())
    RETURNING id";
    
    $result = @insertRecord($archiveSql, [
        $interviewId,
        $interview['application_id'],
        $interview['applicant_id'],
        $interview['job_order_id'],
        $userId,
        $rating,
        $recommendation,
        $feedback
    ]);
    
    if ($result) {
        @logActivity($userId, 'Interview Archived', 'interview_evaluations', $result, 
            'Archived interview #' . $interviewId . ' for ' . $interview['first_name'] . ' ' . $interview['last_name']);
        
        return ['success' => true, 'message' => 'Interview moved to archive.'];
    }
    
    return ['success' => false, 'error' => 'Failed to archive interview.'];
}

// =============================================
// AI HELPER FUNCTIONS (kept the same)
// =============================================

function generateAIQuestions($jobId, $applicantId = null) {
    global $aiService;
    
    $job = @getRecord("
        SELECT title, description, skills_required, experience_level 
        FROM job_orders 
        WHERE id = $1
    ", [$jobId]);
    
    if (!$job) {
        return ['error' => 'Job not found'];
    }
    
    $applicantSkills = '';
    if ($applicantId) {
        $applicant = @getRecord("
            SELECT skills, experience 
            FROM applicants 
            WHERE id = $1
        ", [$applicantId]);
        if ($applicant) {
            $applicantSkills = $applicant['skills'] ?? '';
        }
    }
    
    $jobData = [
        'title' => $job['title'],
        'description' => $job['description'],
        'skills_required' => $job['skills_required'],
        'experience_level' => $job['experience_level']
    ];
    
    $result = $aiService->generateInterviewQuestions($jobData);
    
    if (!$result || isset($result['error']) || $result['provider'] === 'mock') {
        $result = generateFallbackQuestions($job, $applicantSkills);
        $result['provider'] = 'fallback';
    }
    
    return $result;
}

function generateFallbackQuestions($job, $applicantSkills) {
    $title = $job['title'] ?? 'this position';
    $skills = array_map('trim', explode(',', $job['skills_required'] ?? ''));
    $skillList = array_slice($skills, 0, 3);
    $firstSkill = $skillList[0] ?? 'the required technologies';
    $secondSkill = $skillList[1] ?? 'your skills';
    
    $personalized = '';
    if (!empty($applicantSkills)) {
        $appSkills = array_map('trim', explode(',', $applicantSkills));
        $matchedSkills = array_intersect($skills, $appSkills);
        if (!empty($matchedSkills)) {
            $personalized = " Based on your experience with " . implode(', ', array_slice($matchedSkills, 0, 3)) . ", we'd like to explore your expertise further.";
        }
    }
    
    return [
        'technical' => [
            "What is your experience with {$firstSkill}?",
            "Can you describe a project where you used {$secondSkill}?",
            "How do you approach testing and quality assurance?",
            "Explain your experience with database design and optimization."
        ],
        'behavioral' => [
            "Describe a challenging situation you faced at work and how you resolved it.",
            "How do you prioritize tasks when working on multiple projects?",
            "Tell me about a time you had to learn a new technology quickly.",
            "How do you handle feedback and criticism?"
        ],
        'role_specific' => [
            "Why are you interested in this {$title} position?{$personalized}",
            "What unique skills would you bring to this role?",
            "Where do you see yourself in the next 5 years?",
            "Do you have any questions about the role or the company?"
        ],
        'provider' => 'fallback'
    ];
}

function getInterviewTips($jobId) {
    $job = @getRecord("
        SELECT title, skills_required 
        FROM job_orders 
        WHERE id = $1
    ", [$jobId]);
    
    if (!$job) {
        return ['error' => 'Job not found'];
    }
    
    $skills = $job['skills_required'] ?? '';
    $title = $job['title'] ?? 'this position';
    $skillList = array_map('trim', explode(',', $skills));
    $skillList = array_filter($skillList);
    
    $tips = [
        "Focus on demonstrating your experience with: " . ($skills ?: 'the required technologies'),
        "Prepare specific examples of your achievements in " . ($skillList ? implode(', ', array_slice($skillList, 0, 3)) : 'relevant projects'),
        "Research the company culture and values before the interview",
        "Be ready to explain how your skills align with the {$title} position",
        "Have thoughtful questions prepared about the role and team"
    ];
    
    if (!empty($skillList)) {
        $tips[] = "Be prepared to discuss your experience with: " . implode(', ', array_slice($skillList, 0, 5));
    }
    
    return $tips;
}

function generateInterviewFeedback($interviewId) {
    $interview = @getRecord("
        SELECT i.*, u.first_name, u.last_name, u.email, jo.title as job_title, 
               ap.skills, ap.experience
        FROM interviews i
        JOIN applications a ON i.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        WHERE i.id = $1
    ", [$interviewId]);
    
    if (!$interview) {
        return ['error' => 'Interview not found'];
    }
    
    return generateFallbackFeedback($interview);
}

function generateFallbackFeedback($interview) {
    $rating = $interview['rating'] ?? 0;
    $skills = $interview['skills'] ?? '';
    
    if ($rating >= 4) {
        $assessment = "Strong candidate with excellent qualifications. Demonstrates good technical skills and communication.";
        $strengths = "Technical proficiency, communication skills, relevant experience";
        $improvements = "Could benefit from more experience in specific advanced areas";
        $recommendation = "hire";
    } elseif ($rating >= 3) {
        $assessment = "Good candidate with solid potential. Shows competence in key areas.";
        $strengths = "Technical skills, team fit, willingness to learn";
        $improvements = "Need more experience in " . (strlen($skills) > 30 ? substr($skills, 0, 30) . '...' : 'specific areas');
        $recommendation = "consider";
    } else {
        $assessment = "Candidate may need more experience or training. Consider for junior positions.";
        $strengths = "Basic qualifications and enthusiasm";
        $improvements = "Significant gaps in " . (strlen($skills) > 30 ? substr($skills, 0, 30) . '...' : 'required skills');
        $recommendation = "reject";
    }
    
    return [
        'assessment' => $assessment,
        'strengths' => $strengths,
        'improvements' => $improvements,
        'recommendation' => $recommendation
    ];
}

function getInterviewDetails($interviewId) {
    global $userId;
    
    $interview = @getRecord("
        SELECT i.*, 
               u.id as user_id, u.first_name, u.last_name, u.email,
               jo.title as job_title, jo.id as job_id, c.company_name,
               a.id as application_id,
               ap.id as applicant_id, ap.skills, ap.experience
        FROM interviews i
        JOIN applications a ON i.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        WHERE i.id = $1 AND jo.created_by = $2
    ", [$interviewId, $userId]);
    
    return $interview;
}

// =============================================
// RUN AUTO NO-SHOW ON PAGE LOAD
// =============================================
$noShowUpdated = autoUpdateNoShowInterviews();

// =============================================
// Get filter parameters
// =============================================
// ✅ FIXED: Default to 'scheduled' status
$statusFilter = $_GET['status'] ?? 'scheduled';
$searchQuery = $_GET['search'] ?? '';
$jobFilter = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

// =============================================
// Get job IDs for this user - PostgreSQL syntax
// =============================================
$jobIdsResult = @getRecords("SELECT id FROM job_orders WHERE created_by = $1", [$userId]);
$jobIds = [];
if (is_array($jobIdsResult)) {
    foreach ($jobIdsResult as $row) {
        $jobIds[] = $row['id'];
    }
}

if (empty($jobIds)) {
    $interviews = [];
    $statusCounts = ['all' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0, 'rescheduled' => 0, 'no_show' => 0];
    $upcomingCount = 0;
    $upcomingInterviews = [];
} else {
    $conditions = [];
    $params = [];
    $counter = 1;
    
    $conditions[] = "a.job_order_id = ANY($" . $counter . "::int[])";
    $params[] = '{' . implode(',', $jobIds) . '}';
    $counter++;
    
    if ($statusFilter !== 'all') {
        $conditions[] = "i.status = $" . $counter++;
        $params[] = $statusFilter;
    }
    
    if ($jobFilter > 0) {
        $conditions[] = "a.job_order_id = $" . $counter++;
        $params[] = $jobFilter;
    }
    
    if (!empty($searchQuery)) {
        $searchParam = "%$searchQuery%";
        $conditions[] = "(u.first_name ILIKE $" . $counter . " OR u.last_name ILIKE $" . ($counter+1) . " OR u.email ILIKE $" . ($counter+2) . " OR jo.title ILIKE $" . ($counter+3) . ")";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $counter += 4;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $conditions);
    
    $sql = "SELECT i.*, 
            u.id as user_id, u.first_name, u.last_name, u.email,
            ap.profile_picture, ap.skills, ap.experience,
            jo.id as job_id, jo.title as job_title, jo.skills_required,
            c.company_name,
            a.status as application_status
            FROM interviews i
            JOIN applications a ON i.application_id = a.id
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            $whereClause
            ORDER BY i.interview_date ASC";
    
    $interviews = @getRecords($sql, $params);
    if (!is_array($interviews)) $interviews = [];
    
    $statusCounts = ['all' => count($interviews)];
    $statuses = ['scheduled', 'completed', 'cancelled', 'rescheduled', 'no_show'];
    foreach ($statuses as $status) {
        $countResult = @getRecord("
            SELECT COUNT(*) as count FROM interviews i 
            JOIN applications a ON i.application_id = a.id
            JOIN job_orders jo ON a.job_order_id = jo.id 
            WHERE jo.created_by = $1 AND i.status = $2
        ", [$userId, $status]);
        $statusCounts[$status] = isset($countResult['count']) ? (int)$countResult['count'] : 0;
    }
    
    $upcomingCountResult = @getRecord("
        SELECT COUNT(*) as count FROM interviews i 
        JOIN applications a ON i.application_id = a.id
        JOIN job_orders jo ON a.job_order_id = jo.id 
        WHERE jo.created_by = $1 AND i.status = 'scheduled' AND i.interview_date >= NOW()
    ", [$userId]);
    $upcomingCount = isset($upcomingCountResult['count']) ? (int)$upcomingCountResult['count'] : 0;
    
    $upcomingSql = "SELECT i.*, u.first_name, u.last_name, u.email, jo.title as job_title
                    FROM interviews i
                    JOIN applications a ON i.application_id = a.id
                    JOIN applicants ap ON a.applicant_id = ap.id
                    JOIN users u ON ap.user_id = u.id
                    JOIN job_orders jo ON a.job_order_id = jo.id
                    WHERE jo.created_by = $1 AND i.status = 'scheduled' AND i.interview_date >= NOW()
                    ORDER BY i.interview_date ASC
                    LIMIT 5";
    $upcomingInterviews = @getRecords($upcomingSql, [$userId]);
    if (!is_array($upcomingInterviews)) $upcomingInterviews = [];
}

// Get jobs for filter - PostgreSQL syntax
$jobs = @getRecords("SELECT id, title FROM job_orders WHERE created_by = $1 ORDER BY created_at DESC", [$userId]);
if (!is_array($jobs)) $jobs = [];

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
// Get applicants for dropdown - FIXED PostgreSQL
// =============================================
$applicantsList = @getRecords("
    SELECT a.id, u.first_name, u.last_name, u.email, jo.title as job_title, jo.id as job_id, a.applicant_id
    FROM applications a
    JOIN applicants ap ON a.applicant_id = ap.id
    JOIN users u ON ap.user_id = u.id
    JOIN job_orders jo ON a.job_order_id = jo.id
    WHERE jo.created_by = $1 AND a.status IN ('pending', 'shortlisted')
    ORDER BY a.applied_at DESC
", [$userId]);
if (!is_array($applicantsList)) $applicantsList = [];

$statusBadges = [
    'scheduled' => 'badge-scheduled',
    'completed' => 'badge-completed',
    'cancelled' => 'badge-cancelled',
    'rescheduled' => 'badge-rescheduled',
    'no_show' => 'badge-no-show'
];

$statusLabels = [
    'scheduled' => 'Scheduled',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'rescheduled' => 'Rescheduled',
    'no_show' => 'No-Show'
];

$interviewTypes = ['online', 'onsite', 'phone', 'panel'];
$interviewTypeLabels = [
    'online' => 'Online (Video)',
    'onsite' => 'Onsite',
    'phone' => 'Phone Call',
    'panel' => 'Panel Interview'
];

$allStatuses = ['all' => 'All'] + $statusLabels;
$statuses = ['scheduled', 'completed', 'cancelled', 'rescheduled', 'no_show'];

// =============================================
// AJAX HANDLER - PostgreSQL syntax
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $interviewId = isset($_POST['interview_id']) ? (int)$_POST['interview_id'] : 0;
    
    // ========== GENERATE AI QUESTIONS ==========
    if ($action === 'generate_questions') {
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        $applicantId = isset($_POST['applicant_id']) ? (int)$_POST['applicant_id'] : 0;
        
        if ($jobId <= 0 && $interviewId > 0) {
            $interview = getInterviewDetails($interviewId);
            if ($interview) {
                $jobId = $interview['job_id'] ?? 0;
                $applicantId = $interview['applicant_id'] ?? 0;
            }
        }
        
        if ($jobId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Job ID required. Please select a valid job.']);
            exit;
        }
        
        $questions = generateAIQuestions($jobId, $applicantId);
        
        if (isset($questions['error'])) {
            echo json_encode(['success' => false, 'error' => $questions['error']]);
            exit;
        }
        
        if ($interviewId > 0) {
            $questionsJson = json_encode($questions);
            @updateRecord("UPDATE interviews SET ai_questions = $1, updated_at = NOW() WHERE id = $2", [$questionsJson, $interviewId]);
        }
        
        echo json_encode(['success' => true, 'questions' => $questions]);
        exit;
    }
    
    // ========== GET AI TIPS ==========
    if ($action === 'get_tips') {
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        
        if ($jobId <= 0 && $interviewId > 0) {
            $interview = getInterviewDetails($interviewId);
            if ($interview) {
                $jobId = $interview['job_id'] ?? 0;
            }
        }
        
        if ($jobId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Job ID required.']);
            exit;
        }
        
        $tips = getInterviewTips($jobId);
        echo json_encode(['success' => true, 'tips' => $tips]);
        exit;
    }
    
    // ========== GENERATE FEEDBACK ==========
    if ($action === 'generate_feedback') {
        $interviewId = isset($_POST['interview_id']) ? (int)$_POST['interview_id'] : 0;
        
        if ($interviewId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Interview ID required']);
            exit;
        }
        
        $feedback = generateInterviewFeedback($interviewId);
        
        if (isset($feedback['error'])) {
            echo json_encode(['success' => false, 'error' => $feedback['error']]);
        } else {
            echo json_encode(['success' => true, 'feedback' => $feedback]);
        }
        exit;
    }
    
    // ========== SCHEDULE INTERVIEW - PostgreSQL ==========
    if ($action === 'schedule_interview') {
        $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
        $interviewDate = $_POST['interview_date'] ?? '';
        $interviewType = $_POST['interview_type'] ?? 'online';
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $interviewers = trim($_POST['interviewers'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $generateQuestions = isset($_POST['generate_questions']) && $_POST['generate_questions'] === '1';
        
        if (empty($interviewDate)) {
            echo json_encode(['success' => false, 'error' => 'Please select an interview date and time.']);
            exit;
        }
        
        $app = @getRecord("
            SELECT id, status, job_order_id, applicant_id 
            FROM applications 
            WHERE id = $1
        ", [$applicationId]);
        
        if (!$app) {
            echo json_encode(['success' => false, 'error' => 'Application not found.']);
            exit;
        }
        
        $existing = @getRecord("
            SELECT id, status FROM interviews 
            WHERE application_id = $1 AND status != 'completed'
        ", [$applicationId]);
        
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'This applicant already has a scheduled interview.']);
            exit;
        }
        
        $dbDateTime = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $interviewDate)));
        
        $sql = "INSERT INTO interviews (application_id, interview_date, interview_type, meeting_link, interviewers, notes, status, created_by, created_at) 
                VALUES ($1, $2, $3, $4, $5, $6, 'scheduled', $7, NOW())
                RETURNING id";
        
        $result = @insertRecord($sql, [
            $applicationId,
            $dbDateTime,
            $interviewType,
            $meetingLink,
            $interviewers,
            $notes,
            $userId
        ]);
        
        if ($result) {
            $interviewId = $result;
            @updateRecord("UPDATE applications SET status = 'scheduled' WHERE id = $1", [$applicationId]);
            @logActivity($userId, 'Interview Scheduled', 'interviews', $interviewId, 'Interview scheduled for application #' . $applicationId);
            
            $questions = null;
            if ($generateQuestions) {
                $questions = generateAIQuestions($app['job_order_id'], $app['applicant_id']);
                if ($questions && !isset($questions['error'])) {
                    $questionsJson = json_encode($questions);
                    @updateRecord("UPDATE interviews SET ai_questions = $1 WHERE id = $2", [$questionsJson, $interviewId]);
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Interview scheduled successfully!',
                'questions' => $questions
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to schedule interview.']);
        }
        exit;
    }
    
    // ========== UPDATE INTERVIEW - PostgreSQL ==========
    if ($action === 'update_interview' && $interviewId > 0) {
        $interviewDate = $_POST['interview_date'] ?? '';
        $interviewType = $_POST['interview_type'] ?? 'online';
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $interviewers = trim($_POST['interviewers'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'scheduled';
        $feedback = trim($_POST['feedback'] ?? '');
        $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $aiQuestions = isset($_POST['ai_questions']) ? $_POST['ai_questions'] : null;
        
        if (empty($interviewDate)) {
            echo json_encode(['success' => false, 'error' => 'Please select an interview date and time.']);
            exit;
        }
        
        $dbDateTime = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $interviewDate)));
        
        $updateFields = [];
        $params = [];
        $counter = 1;
        
        $updateFields[] = "interview_date = $" . $counter++;
        $params[] = $dbDateTime;
        $updateFields[] = "interview_type = $" . $counter++;
        $params[] = $interviewType;
        $updateFields[] = "meeting_link = $" . $counter++;
        $params[] = $meetingLink;
        $updateFields[] = "interviewers = $" . $counter++;
        $params[] = $interviewers;
        $updateFields[] = "notes = $" . $counter++;
        $params[] = $notes;
        $updateFields[] = "status = $" . $counter++;
        $params[] = $status;
        $updateFields[] = "feedback = $" . $counter++;
        $params[] = $feedback;
        $updateFields[] = "rating = $" . $counter++;
        $params[] = $rating;
        $updateFields[] = "updated_at = NOW()";
        
        if ($aiQuestions) {
            $updateFields[] = "ai_questions = $" . $counter++;
            $params[] = $aiQuestions;
        }
        
        $params[] = $interviewId;
        $sql = "UPDATE interviews SET " . implode(", ", $updateFields) . " WHERE id = $" . $counter;
        
        $result = @updateRecord($sql, $params);
        
        if ($result) {
            @logActivity($userId, 'Interview Updated', 'interviews', $interviewId, 'Updated interview #' . $interviewId);
            echo json_encode(['success' => true, 'message' => 'Interview updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update interview.']);
        }
        exit;
    }
    
    // ========== CANCEL INTERVIEW - PostgreSQL ==========
    if ($action === 'cancel_interview' && $interviewId > 0) {
        $result = @updateRecord("UPDATE interviews SET status = 'cancelled', updated_at = NOW() WHERE id = $1", [$interviewId]);
        
        if ($result) {
            $interview = getInterviewDetails($interviewId);
            if ($interview) {
                @updateRecord("UPDATE applications SET status = 'shortlisted' WHERE id = $1", [$interview['application_id']]);
            }
            
            @logActivity($userId, 'Interview Cancelled', 'interviews', $interviewId, 'Cancelled interview #' . $interviewId);
            echo json_encode(['success' => true, 'message' => 'Interview cancelled successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to cancel interview.']);
        }
        exit;
    }
    
    // ========== COMPLETE INTERVIEW WITH ARCHIVE - PostgreSQL ==========
    if ($action === 'complete_interview' && $interviewId > 0) {
        $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $feedback = trim($_POST['feedback'] ?? '');
        $recommendation = $_POST['recommendation'] ?? 'consider';
        
        if ($rating < 1 || $rating > 5) {
            echo json_encode(['success' => false, 'error' => 'Please provide a rating between 1 and 5.']);
            exit;
        }
        
        if (empty($feedback)) {
            echo json_encode(['success' => false, 'error' => 'Please provide feedback for this interview.']);
            exit;
        }
        
        @beginTransaction();
        
        try {
            $updateResult = @updateRecord("
                UPDATE interviews SET 
                status = 'completed',
                rating = $1,
                feedback = $2,
                updated_at = NOW()
                WHERE id = $3
            ", [$rating, $feedback, $interviewId]);
            
            if (!$updateResult) {
                throw new Exception('Failed to update interview status.');
            }
            
            $archiveResult = moveInterviewToArchive($interviewId, $rating, $feedback, $recommendation);
            
            if (!$archiveResult['success']) {
                throw new Exception($archiveResult['error'] ?? 'Failed to archive interview.');
            }
            
            $interview = getInterviewDetails($interviewId);
            if ($interview) {
                @updateRecord("UPDATE applications SET status = 'interviewed' WHERE id = $1", [$interview['application_id']]);
            }
            
            @logActivity($userId, 'Interview Completed', 'interviews', $interviewId, 
                'Completed interview #' . $interviewId . ' with rating ' . $rating);
            
            @commitTransaction();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Interview completed and archived successfully!',
                'archived' => true
            ]);
            
        } catch (Exception $e) {
            @rollbackTransaction();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // ========== GET INTERVIEW - PostgreSQL ==========
    if ($action === 'get_interview' && $interviewId > 0) {
        $interview = getInterviewDetails($interviewId);
        if ($interview) {
            $interview['ai_questions'] = $interview['ai_questions'] ? json_decode($interview['ai_questions'], true) : null;
            echo json_encode(['success' => true, 'interview' => $interview]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Interview not found.']);
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
    <title>Interviews - ISMERS AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
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

        .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .ai-badge .material-symbols-outlined { font-size: 0.75rem; }

        .ai-questions-box {
            background: var(--bg-surface-low);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-top: 0.75rem;
        }
        .ai-questions-box .question-category {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.5rem;
        }
        .ai-questions-box .question-category:first-child { margin-top: 0; }
        .ai-questions-box .question-item {
            padding: 0.25rem 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            border-bottom: 1px solid var(--slate-100);
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .ai-questions-box .question-item:last-child { border-bottom: none; }
        .ai-questions-box .question-item .q-number {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.7rem;
            min-width: 1.25rem;
        }
        .ai-questions-box .ai-disclaimer {
            font-size: 0.625rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px dashed var(--slate-200);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .ai-tips-box {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-top: 0.75rem;
        }
        .ai-tips-box .tip-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
        }
        .ai-tips-box .tip-item .material-symbols-outlined { color: var(--primary); font-size: 1rem; }

        .ai-feedback-box {
            background: var(--bg-surface-low);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-top: 0.75rem;
        }
        .ai-feedback-box .feedback-section { margin-top: 0.5rem; }
        .ai-feedback-box .feedback-section:first-child { margin-top: 0; }
        .ai-feedback-box .feedback-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }
        .ai-feedback-box .feedback-value { font-size: 0.875rem; color: var(--text-on-surface); padding: 0.25rem 0; }
        .ai-feedback-box .feedback-recommendation {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 700;
        }
        .ai-feedback-box .feedback-recommendation.hire { background: #d1fae5; color: #059669; }
        .ai-feedback-box .feedback-recommendation.consider { background: #fef3c7; color: #d97706; }
        .ai-feedback-box .feedback-recommendation.pass { background: #fecaca; color: #dc2626; }

        .ai-loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 14, 26, 0.6);
            backdrop-filter: blur(8px);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .ai-loading-overlay.active { display: flex; }
        .ai-loading-box {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 2.5rem 3rem;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
        }
        .ai-loading-box .loading-icon { font-size: 3rem; color: var(--primary); margin-bottom: 1rem; display: block; }
        .ai-loading-box .loading-title { font-size: 1.125rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.5rem; }
        .ai-loading-box .loading-subtitle { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-bottom: 1.5rem; }
        .dot-loader {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
        }
        .dot-loader .dot {
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background: var(--primary);
            animation: dotBounce 1.4s infinite ease-in-out both;
        }
        .dot-loader .dot:nth-child(1) { animation-delay: -0.32s; }
        .dot-loader .dot:nth-child(2) { animation-delay: -0.16s; }
        .dot-loader .dot:nth-child(3) { animation-delay: 0s; }
        .dot-loader .dot:nth-child(4) { animation-delay: 0.16s; }
        .dot-loader .dot:nth-child(5) { animation-delay: 0.32s; }

        @keyframes dotBounce {
            0%, 80%, 100% { transform: scale(0); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        .loading-status {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.75rem;
            min-height: 1.5rem;
        }
        .loading-status .status-text { display: inline-block; }
        .loading-status .status-dots::after {
            content: '';
            animation: statusDots 1.5s steps(4) infinite;
        }
        @keyframes statusDots {
            0% { content: ''; }
            25% { content: '.'; }
            50% { content: '..'; }
            75% { content: '...'; }
            100% { content: ''; }
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

        .ai-questions-modal .modal { max-width: 48rem; }
        .ai-questions-modal .modal-body { max-height: 65vh; }
        .ai-questions-result { padding: 0.5rem 0; }
        .ai-questions-result .result-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, #ede9fe, #eef0ff);
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }
        .ai-questions-result .result-header .material-symbols-outlined { font-size: 1.5rem; color: var(--primary); }
        .ai-questions-result .result-header .result-title { font-weight: 700; font-size: 1rem; color: var(--text-on-surface); }
        .ai-questions-result .result-header .result-subtitle { font-size: 0.75rem; color: var(--text-on-surface-variant); }
        .ai-questions-result .result-job-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-sm);
            margin-bottom: 0.75rem;
            font-size: 0.8125rem;
        }
        .ai-questions-result .result-job-info .label { color: var(--text-on-surface-variant); font-weight: 500; }
        .ai-questions-result .result-job-info .value { font-weight: 600; color: var(--text-on-surface); }
        .ai-questions-result .question-category {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.75rem;
            padding: 0.25rem 0.5rem;
            background: var(--primary-container);
            border-radius: var(--radius-sm);
        }
        .ai-questions-result .question-category:first-child { margin-top: 0; }
        .ai-questions-result .question-item {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            color: var(--text-on-surface);
            border-bottom: 1px solid var(--slate-100);
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            transition: background 0.15s ease;
            border-radius: var(--radius-sm);
        }
        .ai-questions-result .question-item:hover { background: var(--bg-surface-low); }
        .ai-questions-result .question-item:last-child { border-bottom: none; }
        .ai-questions-result .question-item .q-number {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.7rem;
            min-width: 1.5rem;
            padding-top: 0.0625rem;
        }
        .ai-questions-result .question-item .q-text { line-height: 1.5; }
        .ai-questions-result .ai-disclaimer {
            font-size: 0.625rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.75rem;
            padding-top: 0.5rem;
            border-top: 1px dashed var(--slate-200);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .ai-questions-result .no-questions {
            text-align: center;
            padding: 1.5rem;
            color: var(--text-on-surface-variant);
        }
        .ai-questions-result .no-questions .material-symbols-outlined {
            font-size: 2.5rem;
            display: block;
            margin-bottom: 0.5rem;
            color: var(--slate-300);
        }

        .complete-modal .modal { max-width: 40rem; }
        .rating-stars-input {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            padding: 0.5rem 0;
        }
        .rating-stars-input .star-btn {
            background: none;
            border: none;
            font-size: 2.5rem;
            cursor: pointer;
            color: #d1d5db;
            transition: all 0.2s ease;
            padding: 0.25rem;
        }
        .rating-stars-input .star-btn:hover { transform: scale(1.1); }
        .rating-stars-input .star-btn.active { color: #f59e0b; }
        .rating-stars-input .star-btn.active:hover { transform: scale(1.1); }
        .rating-label {
            text-align: center;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }
        .recommendation-options {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .recommendation-option {
            padding: 0.5rem;
            border: 2px solid var(--slate-200);
            border-radius: var(--radius-md);
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--bg-surface);
        }
        .recommendation-option:hover { border-color: var(--primary); background: var(--bg-surface-low); }
        .recommendation-option.selected { border-color: var(--primary); background: var(--primary-container); }
        .recommendation-option .rec-label { font-size: 0.75rem; font-weight: 600; }
        .recommendation-option .rec-desc { font-size: 0.625rem; color: var(--text-on-surface-variant); }

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
        .page-header .ai-header-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
            font-size: 0.625rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
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
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }
        .stat-card .stat-icon.orange { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
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
            min-width: 700px;
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

        .datetime-cell .date { font-weight: 600; }
        .datetime-cell .time { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-scheduled { background: #dbeafe; color: #2563eb; }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-cancelled { background: #fecaca; color: #dc2626; }
        .badge-rescheduled { background: #fef3c7; color: #d97706; }
        .badge-no-show { background: #f3f4f6; color: #6b7280; }

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
            .rating-stars-input .star-btn { font-size: 2rem; }
            .recommendation-options { grid-template-columns: 1fr; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }

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
                    <span class="material-symbols-outlined">calendar_month</span>
                    <span>Interviews</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> (<?php echo count($interviews); ?>)
                    </span>
                </div>
                <span class="breadcrumb-meta">Updated <?php echo date('M d, Y H:i'); ?></span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Interview Management</h1>
                    <p style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                        Schedule, track, and manage all candidate interviews
                        <span class="ai-header-badge">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            AI Enhanced
                        </span>
                    </p>
                </div>
                <div>
                    <button class="btn btn-ai" onclick="openScheduleModal()">
                        <span class="material-symbols-outlined">add</span>
                        Schedule Interview
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <span class="material-symbols-outlined">event_upcoming</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $upcomingCount; ?></div>
                        <div class="stat-label">Upcoming</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">
                        <span class="material-symbols-outlined">check_circle</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['completed'] ?? 0; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red">
                        <span class="material-symbols-outlined">cancel</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['cancelled'] ?? 0; ?></div>
                        <div class="stat-label">Cancelled</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <span class="material-symbols-outlined">auto_awesome</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['scheduled'] ?? 0; ?></div>
                        <div class="stat-label">AI Ready</div>
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
                    <a href="interviews.php" class="btn btn-outline">Clear Filters</a>
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

            <!-- Interviews Table -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <span class="material-symbols-outlined">calendar_month</span>
                        <?php echo $statusFilter === 'all' ? 'All Interviews' : ucfirst($statusFilter) . ' Interviews'; ?>
                        <span class="ai-badge" style="font-size:0.55rem;">
                            <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                            AI
                        </span>
                    </h3>
                    <span class="count-badge"><?php echo count($interviews); ?> interviews</span>
                </div>
                <div class="card-body">
                    <?php if (empty($interviews)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">event_busy</span>
                            <h4>No Interviews Found</h4>
                            <p>No interviews have been scheduled yet.</p>
                            <button class="btn btn-ai" onclick="openScheduleModal()" style="margin-top:0.75rem;">
                                <span class="material-symbols-outlined">add</span>
                                Schedule First Interview
                            </button>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Candidate</th>
                                    <th>Position</th>
                                    <th>Date & Time</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($interviews as $interview): ?>
                                    <?php 
                                    $isUpcoming = $interview['status'] === 'scheduled' && strtotime($interview['interview_date']) > time();
                                    $hasAIQuestions = !empty($interview['ai_questions']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="applicant-cell">
                                                <span class="avatar">
                                                    <?php echo strtoupper(substr($interview['first_name'] ?? 'U', 0, 1)); ?>
                                                </span>
                                                <div class="info">
                                                    <div class="name">
                                                        <?php echo htmlspecialchars($interview['first_name'] . ' ' . $interview['last_name']); ?>
                                                        <?php if ($hasAIQuestions): ?>
                                                            <span class="ai-badge" style="font-size:0.5rem; margin-left:0.25rem;">
                                                                <span class="material-symbols-outlined" style="font-size:0.5rem;">auto_awesome</span>
                                                                AI
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="email"><?php echo htmlspecialchars($interview['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="job-cell">
                                                <div class="title"><?php echo htmlspecialchars($interview['job_title'] ?? 'Position'); ?></div>
                                                <div class="company"><?php echo htmlspecialchars($interview['company_name'] ?? ''); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="datetime-cell">
                                                <div class="date"><?php echo date('M d, Y', strtotime($interview['interview_date'])); ?></div>
                                                <div class="time"><?php echo date('g:i A', strtotime($interview['interview_date'])); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo $interviewTypeLabels[$interview['interview_type']] ?? $interview['interview_type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $statusBadges[$interview['status']] ?? 'badge-scheduled'; ?>">
                                                <?php echo $statusLabels[$interview['status']] ?? ucfirst($interview['status']); ?>
                                            </span>
                                            <?php if ($isUpcoming): ?>
                                                <span style="font-size:0.625rem; color:#22c55e; display:block;">Upcoming</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-primary btn-sm" onclick="viewInterview(<?php echo $interview['id']; ?>)" data-tooltip="View interview details">
                                                    <span class="material-symbols-outlined">visibility</span>
                                                </button>
                                                <button class="btn btn-outline btn-sm" onclick="editInterview(<?php echo $interview['id']; ?>)" data-tooltip="Edit interview">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <?php if ($interview['status'] === 'scheduled'): ?>
                                                    <button class="btn btn-success btn-sm" onclick="openCompleteModal(<?php echo $interview['id']; ?>)" data-tooltip="Complete interview">
                                                        <span class="material-symbols-outlined">check</span>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelInterview(<?php echo $interview['id']; ?>)" data-tooltip="Cancel interview">
                                                        <span class="material-symbols-outlined">cancel</span>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-ai btn-sm" onclick="generateQuestionsForInterview(<?php echo $interview['id']; ?>)" data-tooltip="Generate AI questions">
                                                    <span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span>
                                                </button>
                                                <?php if ($hasAIQuestions): ?>
                                                    <button class="btn btn-primary btn-sm" onclick="viewAIQuestions(<?php echo $interview['id']; ?>)" data-tooltip="View AI questions" style="background:#7c3aed;">
                                                        <span class="material-symbols-outlined" style="font-size:0.875rem;">quiz</span>
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
MODAL: Schedule/Edit Interview
============================================= -->
<div class="modal-overlay" id="interviewModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">calendar_month</span>
                <span id="modalTitle">Schedule Interview</span>
                <span class="ai-badge" style="font-size:0.55rem; margin-left:0.5rem;">
                    <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                    AI
                </span>
            </h2>
            <button class="modal-close" onclick="closeModal('interviewModal')" data-tooltip="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form id="interviewForm" onsubmit="submitInterview(event)">
                <input type="hidden" id="interviewId" name="interview_id" value="0">
                <input type="hidden" id="formAction" name="action" value="schedule_interview">
                
                <div class="form-group">
                    <label for="applicationSelect">Select Applicant <span class="required">*</span></label>
                    <select id="applicationSelect" name="application_id" class="form-control" required>
                        <option value="">— Select an applicant —</option>
                        <?php foreach ($applicantsList as $app): ?>
                            <option value="<?php echo $app['id']; ?>" data-job-id="<?php echo $app['job_id']; ?>" data-applicant-id="<?php echo $app['applicant_id']; ?>">
                                <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name'] . ' - ' . $app['job_title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">info</span>
                        Only pending or shortlisted applicants can be scheduled
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="interviewDate">Date & Time <span class="required">*</span></label>
                        <input type="datetime-local" id="interviewDate" name="interview_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="interviewType">Interview Type</label>
                        <select id="interviewType" name="interview_type" class="form-control">
                            <?php foreach ($interviewTypes as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $interviewTypeLabels[$type]; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="meetingLink">Meeting Link</label>
                    <input type="url" id="meetingLink" name="meeting_link" class="form-control" placeholder="https://meet.google.com/...">
                </div>
                
                <div class="form-group">
                    <label for="interviewers">Interviewers</label>
                    <input type="text" id="interviewers" name="interviewers" class="form-control" placeholder="John Doe, Jane Smith">
                </div>
                
                <div class="form-group">
                    <label for="interviewNotes">Notes / Preparation</label>
                    <textarea id="interviewNotes" name="notes" class="form-control" placeholder="Add any preparation notes or instructions..." rows="2"></textarea>
                </div>

                <div class="form-group" style="border-top:1px solid var(--slate-200); padding-top:0.75rem; margin-top:0.25rem;">
                    <div class="checkbox-group">
                        <input type="checkbox" id="generateQuestions" name="generate_questions" value="1" checked>
                        <label for="generateQuestions">
                            <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle; color:var(--primary);">auto_awesome</span>
                            Generate AI Interview Questions
                        </label>
                    </div>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">info</span>
                        AI will generate technical, behavioral, and role-specific questions based on the job
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
            <button class="btn btn-outline" onclick="closeModal('interviewModal')">Cancel</button>
            <button class="btn btn-ai" id="submitBtn" onclick="document.getElementById('interviewForm').dispatchEvent(new Event('submit'))">
                <span class="material-symbols-outlined">check</span>
                <span id="submitBtnText">Schedule Interview</span>
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: View Interview Details with AI
============================================= -->
<div class="modal-overlay" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">visibility</span>
                Interview Details
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
                <div class="spinner"></div>
                <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading interview details...</p>
            </div>
            <div id="viewContent" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
            <button class="btn btn-ai" id="generateFeedbackBtn" onclick="generateFeedbackFromModal()" style="display:none;" data-tooltip="Generate AI feedback">
                <span class="material-symbols-outlined">auto_awesome</span>
                Generate AI Feedback
            </button>
            <button class="btn btn-success" id="completeFromViewBtn" onclick="openCompleteModalFromView()" style="display:none;" data-tooltip="Complete this interview">
                <span class="material-symbols-outlined">check</span>
                Complete Interview
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: AI Questions Display
============================================= -->
<div class="modal-overlay ai-questions-modal" id="aiQuestionsModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">auto_awesome</span>
                <span>AI Generated Questions</span>
                <span class="ai-badge" style="font-size:0.55rem; margin-left:0.5rem;">
                    <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                    AI
                </span>
            </h2>
            <button class="modal-close" onclick="closeModal('aiQuestionsModal')" data-tooltip="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="aiQuestionsBody">
            <div id="aiQuestionsContent">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading questions...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('aiQuestionsModal')">Close</button>
            <button class="btn btn-ai" onclick="copyQuestionsToClipboard()">
                <span class="material-symbols-outlined">content_copy</span> Copy All
            </button>
            <button class="btn btn-success" onclick="printQuestions()">
                <span class="material-symbols-outlined">print</span> Print
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: Complete Interview
============================================= -->
<div class="modal-overlay complete-modal" id="completeModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">check_circle</span>
                Complete Interview
            </h2>
            <button class="modal-close" onclick="closeModal('completeModal')" data-tooltip="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form id="completeForm" onsubmit="submitComplete(event)">
                <input type="hidden" id="completeInterviewId" name="interview_id" value="0">
                
                <div id="completeCandidateInfo" style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin-bottom:1rem; border:1px solid var(--slate-200);">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Candidate</div>
                            <div style="font-weight:600; font-size:0.875rem;" id="completeCandidateName">Loading...</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Position</div>
                            <div style="font-weight:600; font-size:0.875rem;" id="completePosition">Loading...</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Rating <span class="required">*</span></label>
                    <div class="rating-stars-input" id="ratingStars">
                        <button type="button" class="star-btn" data-value="1" onclick="setRating(1)">★</button>
                        <button type="button" class="star-btn" data-value="2" onclick="setRating(2)">★</button>
                        <button type="button" class="star-btn" data-value="3" onclick="setRating(3)">★</button>
                        <button type="button" class="star-btn" data-value="4" onclick="setRating(4)">★</button>
                        <button type="button" class="star-btn" data-value="5" onclick="setRating(5)">★</button>
                    </div>
                    <div class="rating-label" id="ratingLabel">Select a rating</div>
                    <input type="hidden" id="selectedRating" name="rating" value="0">
                </div>
                
                <div class="form-group">
                    <label>Recommendation <span class="required">*</span></label>
                    <div class="recommendation-options">
                        <div class="recommendation-option" data-value="hire" onclick="selectRecommendation('hire')">
                            <div class="rec-label" style="color:#059669;">Hire</div>
                            <div class="rec-desc">Strong candidate, proceed with offer</div>
                        </div>
                        <div class="recommendation-option" data-value="consider" onclick="selectRecommendation('consider')">
                            <div class="rec-label" style="color:#d97706;">Consider</div>
                            <div class="rec-desc">Good potential, keep in pipeline</div>
                        </div>
                        <div class="recommendation-option" data-value="reject" onclick="selectRecommendation('reject')">
                            <div class="rec-label" style="color:#dc2626;">Reject</div>
                            <div class="rec-desc">Not a fit for the role</div>
                        </div>
                    </div>
                    <input type="hidden" id="selectedRecommendation" name="recommendation" value="">
                </div>
                
                <div class="form-group">
                    <label for="completeFeedback">Feedback <span class="required">*</span></label>
                    <textarea id="completeFeedback" name="feedback" class="form-control" placeholder="Provide detailed feedback about the candidate's performance..." rows="4" required></textarea>
                    <div class="helper-text">
                        <span class="material-symbols-outlined">info</span>
                        This feedback will be saved to the archive
                    </div>
                </div>
                
                <div class="form-group" style="border-top:1px solid var(--slate-200); padding-top:0.75rem; margin-top:0.25rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span class="material-symbols-outlined" style="color:var(--primary);">auto_awesome</span>
                        <span style="font-weight:600; font-size:0.8125rem;">AI will generate feedback suggestions</span>
                    </div>
                    <button type="button" class="btn btn-ai btn-sm" onclick="generateAIFeedbackForComplete()" style="margin-top:0.5rem;">
                        <span class="material-symbols-outlined" style="font-size:0.875rem;">auto_awesome</span>
                        Generate AI Feedback Suggestions
                    </button>
                    <div id="aiFeedbackSuggestions" style="display:none; margin-top:0.5rem;"></div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('completeModal')">Cancel</button>
            <button class="btn btn-success" id="completeSubmitBtn" onclick="document.getElementById('completeForm').dispatchEvent(new Event('submit'))">
                <span class="material-symbols-outlined">check</span>
                Complete & Archive
            </button>
        </div>
    </div>
</div>

<!-- =============================================
JAVASCRIPT - COMPLETE FIXED
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
const sidebarBackdrop = document.createElement('div');
sidebarBackdrop.className = 'sidebar-backdrop';
sidebarBackdrop.id = 'sidebarBackdrop';
document.body.appendChild(sidebarBackdrop);

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
        const interviewModal = document.getElementById('interviewModal');
        const viewModal = document.getElementById('viewModal');
        const aiQuestionsModal = document.getElementById('aiQuestionsModal');
        const completeModal = document.getElementById('completeModal');
        if (interviewModal && interviewModal.classList.contains('active')) {
            closeModal('interviewModal');
        } else if (viewModal && viewModal.classList.contains('active')) {
            closeModal('viewModal');
        } else if (aiQuestionsModal && aiQuestionsModal.classList.contains('active')) {
            closeModal('aiQuestionsModal');
        } else if (completeModal && completeModal.classList.contains('active')) {
            closeModal('completeModal');
        }
        closeMobileSidebar();
        if (profileToggle) profileToggle.classList.remove('open');
        if (profileMenu) profileMenu.classList.remove('open');
    }
});

// =============================================
// 5. SCHEDULE INTERVIEW
// =============================================
function openScheduleModal() {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const interviewId = document.getElementById('interviewId');
    const submitBtnText = document.getElementById('submitBtnText');
    const statusField = document.getElementById('statusField');
    const interviewForm = document.getElementById('interviewForm');
    const interviewDate = document.getElementById('interviewDate');
    const applicationSelect = document.getElementById('applicationSelect');
    
    if (modalTitle) modalTitle.textContent = 'Schedule Interview';
    if (formAction) formAction.value = 'schedule_interview';
    if (interviewId) interviewId.value = '0';
    if (submitBtnText) submitBtnText.textContent = 'Schedule Interview';
    if (statusField) statusField.style.display = 'none';
    if (interviewForm) interviewForm.reset();
    if (applicationSelect) applicationSelect.disabled = false;
    
    const generateQuestions = document.getElementById('generateQuestions');
    if (generateQuestions) generateQuestions.checked = true;
    
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0);
    if (interviewDate) interviewDate.value = tomorrow.toISOString().slice(0, 16);
    
    openModal('interviewModal');
}

// =============================================
// 6. EDIT INTERVIEW
// =============================================
function editInterview(id) {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const interviewId = document.getElementById('interviewId');
    const submitBtnText = document.getElementById('submitBtnText');
    const statusField = document.getElementById('statusField');
    const applicationSelect = document.getElementById('applicationSelect');
    const generateQuestions = document.getElementById('generateQuestions');
    
    if (modalTitle) modalTitle.textContent = 'Edit Interview';
    if (formAction) formAction.value = 'update_interview';
    if (interviewId) interviewId.value = id;
    if (submitBtnText) submitBtnText.textContent = 'Update Interview';
    if (statusField) statusField.style.display = 'block';
    if (applicationSelect) applicationSelect.disabled = true;
    if (generateQuestions) generateQuestions.checked = false;

    const formData = new FormData();
    formData.append('action', 'get_interview');
    formData.append('interview_id', id);

    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const interview = data.interview;
            const appSelect = document.getElementById('applicationSelect');
            const intDate = document.getElementById('interviewDate');
            const intType = document.getElementById('interviewType');
            const meetingLink = document.getElementById('meetingLink');
            const interviewers = document.getElementById('interviewers');
            const intNotes = document.getElementById('interviewNotes');
            const editStatus = document.getElementById('editStatus');
            
            if (appSelect) appSelect.value = interview.application_id;
            if (intDate) intDate.value = interview.interview_date.replace(' ', 'T');
            if (intType) intType.value = interview.interview_type;
            if (meetingLink) meetingLink.value = interview.meeting_link || '';
            if (interviewers) interviewers.value = interview.interviewers || '';
            if (intNotes) intNotes.value = interview.notes || '';
            if (editStatus) editStatus.value = interview.status;
            openModal('interviewModal');
        } else {
            showToast(data.error || 'Failed to load interview.', 'error');
        }
    })
    .catch(error => {
        console.error('Edit error:', error);
        showToast('Error loading interview details.', 'error');
    });
}

// =============================================
// 7. SUBMIT INTERVIEW
// =============================================
function submitInterview(event) {
    event.preventDefault();
    
    const form = document.getElementById('interviewForm');
    if (!form) return;
    
    const formData = new FormData(form);
    
    const date = document.getElementById('interviewDate');
    if (!date || !date.value) {
        showToast('Please select an interview date and time.', 'error');
        return;
    }
    
    const btn = document.getElementById('submitBtn');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block; width:1rem; height:1rem; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Saving...';

    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            let message = data.message;
            if (data.questions && !data.questions.error) {
                message += ' AI questions generated!';
            }
            showToast(message, 'success');
            closeModal('interviewModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to save interview.', 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('Error saving interview. Please try again.', 'error');
    });
}

// =============================================
// 8. VIEW INTERVIEW
// =============================================
let currentInterviewId = null;
let currentInterviewData = null;

function viewInterview(id) {
    currentInterviewId = id;
    openModal('viewModal');
    
    const loading = document.getElementById('viewLoading');
    const content = document.getElementById('viewContent');
    const generateFeedbackBtn = document.getElementById('generateFeedbackBtn');
    const completeFromViewBtn = document.getElementById('completeFromViewBtn');
    
    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';
    if (generateFeedbackBtn) generateFeedbackBtn.style.display = 'none';
    if (completeFromViewBtn) completeFromViewBtn.style.display = 'none';

    const formData = new FormData();
    formData.append('action', 'get_interview');
    formData.append('interview_id', id);

    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        if (data.success) {
            const i = data.interview;
            currentInterviewData = i;
            
            if (i.status === 'scheduled') {
                if (completeFromViewBtn) completeFromViewBtn.style.display = 'inline-flex';
            }
            if (i.status === 'completed') {
                if (generateFeedbackBtn) generateFeedbackBtn.style.display = 'inline-flex';
            }
            
            let questionsHtml = '';
            if (i.ai_questions) {
                const q = i.ai_questions;
                questionsHtml = `
                    <div class="ai-questions-box">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                            <span class="ai-badge">
                                <span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span>
                                AI Generated Questions
                            </span>
                            <button class="btn btn-ai btn-sm" onclick="viewAIQuestions(${i.id})" style="margin-left:auto;">
                                <span class="material-symbols-outlined" style="font-size:0.75rem;">open_in_full</span>
                                View All
                            </button>
                        </div>
                        ${q.technical ? `
                            <div class="question-category">Technical</div>
                            ${q.technical.slice(0, 2).map((qText, idx) => `
                                <div class="question-item">
                                    <span class="q-number">${idx + 1}.</span>
                                    ${escapeHtml(qText)}
                                </div>
                            `).join('')}
                            ${q.technical.length > 2 ? `<div style="font-size:0.75rem; color:var(--text-on-surface-variant); padding:0.25rem 0.5rem;">+ ${q.technical.length - 2} more technical questions</div>` : ''}
                        ` : ''}
                        <div class="ai-disclaimer">
                            <span class="material-symbols-outlined">info</span>
                            ${q.technical ? `${q.technical.length + (q.behavioral?.length || 0) + (q.role_specific?.length || 0)} questions generated` : 'No questions generated yet'}
                        </div>
                    </div>
                `;
            } else if (i.status === 'scheduled') {
                questionsHtml = `
                    <div style="display:flex; justify-content:center; padding:0.5rem;">
                        <button class="btn btn-ai btn-sm" onclick="generateQuestionsForInterview(${i.id})" data-tooltip="Generate AI questions">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            Generate AI Questions
                        </button>
                    </div>
                `;
            }
            
            let tipsHtml = '';
            if (i.job_id) {
                const tips = <?php echo json_encode(getInterviewTips(0)); ?>;
                const tipItems = Array.isArray(tips) ? tips : ['Focus on key skills', 'Prepare specific examples', 'Research the company'];
                tipsHtml = `
                    <div class="ai-tips-box">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
                            <span class="ai-badge">
                                <span class="material-symbols-outlined" style="font-size:0.75rem;">lightbulb</span>
                                AI Interview Tips
                            </span>
                        </div>
                        ${tipItems.slice(0, 5).map(tip => `
                            <div class="tip-item">
                                <span class="material-symbols-outlined">check_circle</span>
                                ${escapeHtml(tip)}
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            if (content) {
                content.innerHTML = `
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Candidate</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(i.first_name)} ${escapeHtml(i.last_name)}</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(i.email)}</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Position</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(i.job_title)}</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(i.company_name)}</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Date & Time</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${new Date(i.interview_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${new Date(i.interview_date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</div>
                        </div>
                        <div>
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Type</div>
                            <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(i.interview_type)}</div>
                            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                <span class="badge badge-${i.status}">${escapeHtml(i.status)}</span>
                                ${i.rating && i.rating > 0 ? ` &nbsp;⭐ ${i.rating}/5` : ''}
                            </div>
                        </div>
                        ${i.meeting_link ? `
                        <div style="grid-column:1/-1;">
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Meeting Link</div>
                            <a href="${escapeHtml(i.meeting_link)}" target="_blank" style="color:var(--primary); text-decoration:underline; word-break:break-all;">${escapeHtml(i.meeting_link)}</a>
                        </div>
                        ` : ''}
                        ${i.interviewers ? `
                        <div style="grid-column:1/-1;">
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Interviewers</div>
                            <div>${escapeHtml(i.interviewers)}</div>
                        </div>
                        ` : ''}
                        ${i.notes ? `
                        <div style="grid-column:1/-1;">
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Notes</div>
                            <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(i.notes)}</div>
                        </div>
                        ` : ''}
                        ${i.feedback ? `
                        <div style="grid-column:1/-1;">
                            <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Feedback</div>
                            <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(i.feedback)}</div>
                        </div>
                        ` : ''}
                    </div>
                    ${questionsHtml}
                    ${tipsHtml}
                    <div id="aiFeedbackContainer"></div>
                `;
            }
        } else {
            if (content) {
                content.innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">${data.error || 'Failed to load interview details.'}</p>
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
                    <p style="margin-top:0.5rem;">Error loading interview details. Please try again.</p>
                </div>
            `;
        }
    });
}

// =============================================
// 9. VIEW AI QUESTIONS
// =============================================
let currentQuestions = null;
let currentQuestionsInterviewId = null;

function viewAIQuestions(interviewId) {
    currentQuestionsInterviewId = interviewId;
    
    const content = document.getElementById('aiQuestionsContent');
    content.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading questions...</p>
        </div>
    `;
    
    openModal('aiQuestionsModal');
    
    const formData = new FormData();
    formData.append('action', 'get_interview');
    formData.append('interview_id', interviewId);
    
    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.interview && data.interview.ai_questions) {
            const questions = data.interview.ai_questions;
            currentQuestions = questions;
            displayAIQuestions(questions, data.interview);
        } else {
            content.innerHTML = `
                <div class="ai-questions-result">
                    <div class="no-questions">
                        <span class="material-symbols-outlined">auto_awesome</span>
                        <p>No AI questions generated for this interview yet.</p>
                        <button class="btn btn-ai" onclick="generateQuestionsForInterview(${interviewId}); closeModal('aiQuestionsModal');" style="margin-top:0.75rem;">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            Generate Now
                        </button>
                    </div>
                </div>
            `;
        }
    })
    .catch(error => {
        content.innerHTML = `
            <div class="ai-questions-result">
                <div class="no-questions" style="color:#dc2626;">
                    <span class="material-symbols-outlined">error</span>
                    <p>Error loading questions. Please try again.</p>
                </div>
            </div>
        `;
    });
}

function displayAIQuestions(questions, interview) {
    const content = document.getElementById('aiQuestionsContent');
    
    let html = `
        <div class="ai-questions-result">
            <div class="result-header">
                <span class="material-symbols-outlined">auto_awesome</span>
                <div>
                    <div class="result-title">AI Generated Interview Questions</div>
                    <div class="result-subtitle">Based on job requirements and candidate profile</div>
                </div>
            </div>
            <div class="result-job-info">
                <div><span class="label">Position:</span> <span class="value">${escapeHtml(interview.job_title || 'N/A')}</span></div>
                <div><span class="label">Candidate:</span> <span class="value">${escapeHtml(interview.first_name + ' ' + interview.last_name)}</span></div>
                <div style="grid-column:1/-1;"><span class="label">Company:</span> <span class="value">${escapeHtml(interview.company_name || 'N/A')}</span></div>
            </div>
    `;
    
    if (questions.technical && questions.technical.length > 0) {
        html += `
            <div class="question-category">Technical Questions</div>
            ${questions.technical.map((q, idx) => `
                <div class="question-item">
                    <span class="q-number">${idx + 1}.</span>
                    <span class="q-text">${escapeHtml(q)}</span>
                </div>
            `).join('')}
        `;
    }
    
    if (questions.behavioral && questions.behavioral.length > 0) {
        html += `
            <div class="question-category">Behavioral Questions</div>
            ${questions.behavioral.map((q, idx) => `
                <div class="question-item">
                    <span class="q-number">${idx + 1}.</span>
                    <span class="q-text">${escapeHtml(q)}</span>
                </div>
            `).join('')}
        `;
    }
    
    if (questions.role_specific && questions.role_specific.length > 0) {
        html += `
            <div class="question-category">Role Specific Questions</div>
            ${questions.role_specific.map((q, idx) => `
                <div class="question-item">
                    <span class="q-number">${idx + 1}.</span>
                    <span class="q-text">${escapeHtml(q)}</span>
                </div>
            `).join('')}
        `;
    }
    
    html += `
            <div class="ai-disclaimer">
                <span class="material-symbols-outlined">info</span>
                Questions generated by AI based on job requirements and best practices
            </div>
        </div>
    `;
    
    content.innerHTML = html;
}

function copyQuestionsToClipboard() {
    if (!currentQuestions) {
        showToast('No questions to copy.', 'error');
        return;
    }
    
    let text = 'AI Generated Interview Questions\n';
    text += '='.repeat(40) + '\n\n';
    
    if (currentQuestions.technical) {
        text += 'TECHNICAL QUESTIONS:\n';
        currentQuestions.technical.forEach((q, i) => {
            text += `  ${i+1}. ${q}\n`;
        });
        text += '\n';
    }
    
    if (currentQuestions.behavioral) {
        text += 'BEHAVIORAL QUESTIONS:\n';
        currentQuestions.behavioral.forEach((q, i) => {
            text += `  ${i+1}. ${q}\n`;
        });
        text += '\n';
    }
    
    if (currentQuestions.role_specific) {
        text += 'ROLE SPECIFIC QUESTIONS:\n';
        currentQuestions.role_specific.forEach((q, i) => {
            text += `  ${i+1}. ${q}\n`;
        });
    }
    
    navigator.clipboard.writeText(text).then(() => {
        showToast('Questions copied to clipboard!', 'success');
    }).catch(() => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('Questions copied to clipboard!', 'success');
    });
}

function printQuestions() {
    const content = document.getElementById('aiQuestionsContent');
    if (!content) return;
    
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write(`
        <html>
            <head>
                <title>AI Generated Interview Questions</title>
                <style>
                    body { font-family: 'Inter', sans-serif; padding: 2rem; max-width: 800px; margin: 0 auto; }
                    h1 { color: #4f46e5; }
                    .category { font-weight: 700; color: #4f46e5; margin-top: 1.5rem; }
                    .question { padding: 0.25rem 0; border-bottom: 1px solid #e2e8f0; }
                    .disclaimer { margin-top: 2rem; font-size: 0.8rem; color: #64748b; border-top: 1px dashed #cbd5e1; padding-top: 1rem; }
                    @media print { body { padding: 1rem; } }
                </style>
            </head>
            <body>
                ${content.innerHTML}
            </body>
        </html>
    `);
    printWindow.document.close();
    setTimeout(() => {
        printWindow.print();
    }, 500);
}

// =============================================
// 10. GENERATE AI QUESTIONS FOR INTERVIEW
// =============================================
function generateQuestionsForInterview(interviewId) {
    const formData = new FormData();
    formData.append('action', 'get_interview');
    formData.append('interview_id', interviewId);
    
    showToast('Generating AI questions...', 'info');
    
    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.interview) {
            const i = data.interview;
            
            const qData = new FormData();
            qData.append('action', 'generate_questions');
            qData.append('job_id', i.job_id);
            qData.append('applicant_id', i.applicant_id);
            qData.append('interview_id', interviewId);
            
            return fetch('interviews.php', {
                method: 'POST',
                body: qData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        } else {
            throw new Error('Failed to get interview details');
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.questions) {
            showToast('AI questions generated successfully!', 'success');
            setTimeout(() => {
                viewAIQuestions(interviewId);
            }, 500);
        } else {
            showToast(data.error || 'Failed to generate questions.', 'error');
        }
    })
    .catch(error => {
        console.error('Generate questions error:', error);
        showToast('Error generating questions. Please try again.', 'error');
    });
}

// =============================================
// 11. GENERATE AI FEEDBACK FROM MODAL
// =============================================
function generateFeedbackFromModal() {
    if (!currentInterviewId) {
        showToast('No interview selected.', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'generate_feedback');
    formData.append('interview_id', currentInterviewId);
    
    showToast('Generating AI feedback...', 'info');
    
    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const feedback = data.feedback;
            const container = document.getElementById('aiFeedbackContainer');
            if (container) {
                const recommendationClass = feedback.recommendation.toLowerCase();
                container.innerHTML = `
                    <div class="ai-feedback-box">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                            <span class="ai-badge">
                                <span class="material-symbols-outlined" style="font-size:0.75rem;">auto_awesome</span>
                                AI Generated Feedback
                            </span>
                        </div>
                        <div class="feedback-section">
                            <div class="feedback-label">Overall Assessment</div>
                            <div class="feedback-value">${escapeHtml(feedback.assessment)}</div>
                        </div>
                        <div class="feedback-section">
                            <div class="feedback-label">Key Strengths</div>
                            <div class="feedback-value">${escapeHtml(feedback.strengths)}</div>
                        </div>
                        <div class="feedback-section">
                            <div class="feedback-label">Areas for Improvement</div>
                            <div class="feedback-value">${escapeHtml(feedback.improvements)}</div>
                        </div>
                        <div class="feedback-section">
                            <div class="feedback-label">Recommendation</div>
                            <div class="feedback-value">
                                <span class="feedback-recommendation ${recommendationClass}">${escapeHtml(feedback.recommendation)}</span>
                            </div>
                        </div>
                        <div class="ai-disclaimer" style="margin-top:0.5rem; padding-top:0.5rem; border-top:1px dashed var(--slate-200); font-size:0.625rem; color:var(--text-on-surface-variant);">
                            <span class="material-symbols-outlined" style="font-size:0.75rem;">info</span>
                            AI-generated feedback based on interview data
                        </div>
                    </div>
                `;
            }
            showToast('AI feedback generated!', 'success');
        } else {
            showToast(data.error || 'Failed to generate feedback.', 'error');
        }
    })
    .catch(error => {
        console.error('Feedback error:', error);
        showToast('Error generating feedback.', 'error');
    });
}

// =============================================
// 12. COMPLETE INTERVIEW
// =============================================
let completeInterviewId = 0;

function openCompleteModal(interviewId) {
    completeInterviewId = interviewId;
    document.getElementById('completeInterviewId').value = interviewId;
    
    // Reset form
    document.getElementById('completeForm').reset();
    document.getElementById('selectedRating').value = '0';
    document.getElementById('selectedRecommendation').value = '';
    document.getElementById('aiFeedbackSuggestions').style.display = 'none';
    document.getElementById('aiFeedbackSuggestions').innerHTML = '';
    
    // Reset stars
    document.querySelectorAll('#ratingStars .star-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.getElementById('ratingLabel').textContent = 'Select a rating';
    
    // Reset recommendation options
    document.querySelectorAll('.recommendation-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    
    // Load interview info
    const formData = new FormData();
    formData.append('action', 'get_interview');
    formData.append('interview_id', interviewId);
    
    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const i = data.interview;
            document.getElementById('completeCandidateName').textContent = i.first_name + ' ' + i.last_name;
            document.getElementById('completePosition').textContent = i.job_title || 'N/A';
            openModal('completeModal');
        } else {
            showToast('Failed to load interview details.', 'error');
        }
    })
    .catch(error => {
        showToast('Error loading interview details.', 'error');
    });
}

function openCompleteModalFromView() {
    if (currentInterviewId) {
        openCompleteModal(currentInterviewId);
    }
}

function setRating(value) {
    document.getElementById('selectedRating').value = value;
    
    document.querySelectorAll('#ratingStars .star-btn').forEach(btn => {
        const val = parseInt(btn.dataset.value);
        btn.classList.toggle('active', val <= value);
    });
    
    const labels = ['', 'Poor', 'Below Average', 'Average', 'Good', 'Excellent'];
    document.getElementById('ratingLabel').textContent = labels[value] || 'Select a rating';
}

function selectRecommendation(value) {
    document.getElementById('selectedRecommendation').value = value;
    
    document.querySelectorAll('.recommendation-option').forEach(opt => {
        opt.classList.toggle('selected', opt.dataset.value === value);
    });
}

function generateAIFeedbackForComplete() {
    if (!completeInterviewId) {
        showToast('No interview selected.', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'generate_feedback');
    formData.append('interview_id', completeInterviewId);
    
    showToast('Generating AI feedback suggestions...', 'info');
    
    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const feedback = data.feedback;
            const container = document.getElementById('aiFeedbackSuggestions');
            container.style.display = 'block';
            container.innerHTML = `
                <div style="background:var(--bg-surface-low); border:1px solid var(--slate-200); border-radius:var(--radius-md); padding:0.75rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                        <span class="ai-badge" style="font-size:0.55rem;">
                            <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                            AI Suggestions
                        </span>
                        <button type="button" class="btn btn-sm btn-outline" onclick="applyAIFeedback()" style="margin-left:auto;">
                            <span class="material-symbols-outlined" style="font-size:0.75rem;">check</span>
                            Apply
                        </button>
                    </div>
                    <div style="font-size:0.8125rem; color:var(--text-on-surface);">
                        <div><strong>Assessment:</strong> ${escapeHtml(feedback.assessment)}</div>
                        <div style="margin-top:0.25rem;"><strong>Strengths:</strong> ${escapeHtml(feedback.strengths)}</div>
                        <div style="margin-top:0.25rem;"><strong>Improvements:</strong> ${escapeHtml(feedback.improvements)}</div>
                        <div style="margin-top:0.25rem;"><strong>Recommendation:</strong> <span style="font-weight:700;">${escapeHtml(feedback.recommendation)}</span></div>
                    </div>
                    <div style="font-size:0.625rem; color:var(--text-on-surface-variant); margin-top:0.5rem; border-top:1px dashed var(--slate-200); padding-top:0.5rem;">
                        Click "Apply" to use these suggestions
                    </div>
                </div>
            `;
            window._aiFeedbackData = feedback;
        } else {
            showToast('Failed to generate AI feedback.', 'error');
        }
    })
    .catch(error => {
        console.error('AI feedback error:', error);
        showToast('Error generating AI feedback.', 'error');
    });
}

function applyAIFeedback() {
    if (!window._aiFeedbackData) return;
    
    const feedback = window._aiFeedbackData;
    
    let rating = 3;
    if (feedback.recommendation === 'hire') rating = 4;
    else if (feedback.recommendation === 'consider') rating = 3;
    else if (feedback.recommendation === 'reject') rating = 2;
    setRating(rating);
    
    selectRecommendation(feedback.recommendation || 'consider');
    
    const feedbackText = document.getElementById('completeFeedback');
    if (feedbackText) {
        feedbackText.value = `AI Assessment: ${feedback.assessment}\n\nStrengths: ${feedback.strengths}\n\nAreas for Improvement: ${feedback.improvements}`;
    }
    
    document.getElementById('selectedRating').value = rating;
    document.getElementById('selectedRecommendation').value = feedback.recommendation || 'consider';
    
    showToast('AI feedback applied successfully!', 'success');
}

function submitComplete(event) {
    event.preventDefault();
    
    const interviewId = document.getElementById('completeInterviewId').value;
    const rating = document.getElementById('selectedRating').value;
    const recommendation = document.getElementById('selectedRecommendation').value;
    const feedback = document.getElementById('completeFeedback').value.trim();
    
    if (!rating || rating < 1 || rating > 5) {
        showToast('Please select a rating.', 'error');
        return;
    }
    
    if (!recommendation) {
        showToast('Please select a recommendation.', 'error');
        return;
    }
    
    if (!feedback) {
        showToast('Please provide feedback.', 'error');
        return;
    }
    
    const btn = document.getElementById('completeSubmitBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block; width:1rem; height:1rem; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Saving...';

    const formData = new FormData();
    formData.append('action', 'complete_interview');
    formData.append('interview_id', interviewId);
    formData.append('rating', rating);
    formData.append('recommendation', recommendation);
    formData.append('feedback', feedback);

    fetch('interviews.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            showToast('Interview completed and archived successfully!', 'success');
            closeModal('completeModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to complete interview.', 'error');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.error('Complete error:', error);
        showToast('Error completing interview. Please try again.', 'error');
    });
}

// =============================================
// 13. CANCEL INTERVIEW
// =============================================
function cancelInterview(id) {
    if (!confirm('Are you sure you want to cancel this interview?')) return;

    const formData = new FormData();
    formData.append('action', 'cancel_interview');
    formData.append('interview_id', id);

    showToast('Cancelling interview...', 'info');

    fetch('interviews.php', {
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
            showToast(data.error || 'Failed to cancel interview.', 'error');
        }
    })
    .catch(error => {
        showToast('Error cancelling interview.', 'error');
    });
}

// =============================================
// 14. SEARCH & FILTERS
// =============================================
function applyFilters() {
    const search = document.getElementById('searchInput');
    if (!search) return;
    
    const status = '<?php echo $statusFilter; ?>';
    let url = 'interviews.php?';
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
// 15. TOAST SYSTEM
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
// 16. UTILITY FUNCTIONS
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
// 17. RESPONSIVE HANDLING
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

console.log('ISMERS Interviews Management with AI Integration loaded successfully!');
console.log('AI Features: Question Generation, Interview Tips, Feedback Analysis');
console.log('Completed interviews are automatically archived.');
</script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>