<?php
// portals/applicant/job_details.php - View Job Details & Apply with Face Scanner
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

// Check if user has applicant role
if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Applicant';
$lastName = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$fullName = $_SESSION['full_name'] ?? 'Applicant User';

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// =============================================
// GET COUNTS FOR SIDEBAR BADGES
// =============================================
$totalApplications = 0;
$interviewCount = 0;
$pendingOffers = 0;

if ($applicantId) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $appResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = $1
    ", [$applicantId]);
    $totalApplications = (int)($appResult['count'] ?? 0);
    
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $interviewResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = $1 AND interview_date IS NOT NULL
    ", [$applicantId]);
    $interviewCount = (int)($interviewResult['count'] ?? 0);
    
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $offersResult = getRecord("
        SELECT COUNT(*) as count FROM offers o
        JOIN applications a ON o.application_id = a.id
        WHERE a.applicant_id = $1 AND o.status = 'sent'
    ", [$applicantId]);
    $pendingOffers = (int)($offersResult['count'] ?? 0);
}

// Get job ID from URL
$jobId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($jobId <= 0) {
    header('Location: dashboard.php');
    exit;
}

// ✅ FIXED: Get job details - PostgreSQL uses $1 placeholder
$jobSql = "SELECT j.*, 
           c.company_name,
           u.first_name as client_first_name, u.last_name as client_last_name,
           (SELECT COUNT(*) FROM applications WHERE job_order_id = j.id) as total_applicants
           FROM job_orders j
           JOIN clients c ON j.client_id = c.id
           JOIN users u ON c.user_id = u.id
           WHERE j.id = $1 AND j.status IN ('open', 'ongoing')";

$job = getRecord($jobSql, [$jobId]);

if (!$job) {
    header('Location: dashboard.php');
    exit;
}

// Get applicant profile
// ✅ FIXED: PostgreSQL uses $1 placeholder
$applicant = getRecord("
    SELECT id, phone, address, skills, experience, education, resume_path, profile_picture
    FROM applicants
    WHERE user_id = $1
", [$userId]);

// Check if face is already verified
$faceVerified = false;
if ($applicantId) {
    // ✅ FIXED: PostgreSQL uses $1 placeholder
    $faceCheck = getRecord("
        SELECT id FROM face_verification WHERE user_id = $1
    ", [$userId]);
    $faceVerified = $faceCheck !== null;
}

// =============================================
// AI MATCH SCORE CALCULATION - SIMPLIFIED & FORCED
// =============================================
$matchScore = null;
$matchStrengths = [];
$matchGaps = [];
$matchRecommendation = '';
$matchProvider = '';

// FIRST: Check if applicant has skills - if not, show profile incomplete message
if ($applicant && !empty($applicant['skills'])) {
    
    // Try to get AI match score
    try {
        $aiService = new AiService();
        
        $jobData = [
            'title' => $job['title'] ?? '',
            'skills_required' => $job['skills_required'] ?? '',
            'description' => $job['description'] ?? '',
            'experience_level' => $job['experience_level'] ?? '',
        ];
        
        $applicantData = [
            'skills' => $applicant['skills'] ?? '',
            'experience' => $applicant['experience'] ?? '',
            'education' => $applicant['education'] ?? '',
        ];
        
        $result = $aiService->calculateMatchScore($jobData, $applicantData);
        
        if (isset($result['match_score']) && $result['match_score'] !== null) {
            $matchScore = (int)$result['match_score'];
            $matchStrengths = $result['strengths'] ?? [];
            $matchGaps = $result['gaps'] ?? [];
            $matchRecommendation = $result['recommendation'] ?? '';
            $matchProvider = $result['provider'] ?? 'unknown';
        }
    } catch (Exception $e) {
        error_log("Match score error: " . $e->getMessage());
    }
    
    // =============================================
    // FALLBACK: If AI failed or returned null, calculate simple match
    // =============================================
    if ($matchScore === null || $matchScore === 0) {
        // Calculate simple match based on skills
        $jobSkills = array_map('trim', explode(',', $job['skills_required'] ?? ''));
        $applicantSkills = array_map('trim', explode(',', $applicant['skills'] ?? ''));
        
        $jobSkills = array_filter($jobSkills);
        $applicantSkills = array_filter($applicantSkills);
        
        if (!empty($jobSkills) && !empty($applicantSkills)) {
            $matchingSkills = array_intersect($jobSkills, $applicantSkills);
            $totalJobSkills = count($jobSkills);
            $percentage = round((count($matchingSkills) / $totalJobSkills) * 100);
            $matchScore = min($percentage, 100);
            
            // Set strengths and gaps
            if (count($matchingSkills) > 0) {
                $matchStrengths = array_values(array_slice($matchingSkills, 0, 3));
            }
            $missingSkills = array_diff($jobSkills, $applicantSkills);
            if (count($missingSkills) > 0) {
                $matchGaps = array_values(array_slice($missingSkills, 0, 3));
            }
            
            // Set recommendation based on score
            if ($matchScore >= 70) {
                $matchRecommendation = 'Great match! Your skills align well with this position.';
            } elseif ($matchScore >= 40) {
                $matchRecommendation = 'Good potential. Consider developing skills in: ' . implode(', ', array_slice($missingSkills, 0, 2));
            } else {
                $matchRecommendation = 'Consider building more relevant skills for this position.';
            }
            
            $matchProvider = 'fallback';
        } else {
            // No skills to match against
            $matchScore = null;
        }
    }
}

// Get AI provider status
$isAIEnabled = false;
if (isset($aiService) && $aiService) {
    $isAIEnabled = !$aiService->isUsingMock();
}

// Check if already applied
$hasApplied = false;
$applicationStatus = '';
if ($applicant) {
    // ✅ FIXED: PostgreSQL uses $1, $2 placeholders
    $existingApp = getRecord("
        SELECT id, status FROM applications 
        WHERE job_order_id = $1 AND applicant_id = $2
    ", [$jobId, $applicant['id']]);
    
    if ($existingApp) {
        $hasApplied = true;
        $applicationStatus = $existingApp['status'];
    }
}

// Handle Application Submission
$message = '';
$messageType = '';
$showSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'apply') {
        // Check if already applied
        if ($hasApplied) {
            $message = 'You have already applied to this job.';
            $messageType = 'error';
        } elseif (!$applicant) {
            $message = 'Please complete your profile before applying.';
            $messageType = 'error';
        } else {
            // Handle file upload
            $resumePath = $applicant['resume_path'] ?? '';
            $coverLetter = trim($_POST['cover_letter'] ?? '');
            
            // If resume file is uploaded
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../../uploads/resumes/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileInfo = pathinfo($_FILES['resume']['name']);
                $extension = strtolower($fileInfo['extension']);
                $allowedExtensions = ['pdf', 'doc', 'docx'];
                
                if (!in_array($extension, $allowedExtensions)) {
                    $message = 'Invalid file type. Allowed: PDF, DOC, DOCX';
                    $messageType = 'error';
                } elseif ($_FILES['resume']['size'] > 5 * 1024 * 1024) {
                    $message = 'File size must be less than 5MB.';
                    $messageType = 'error';
                } else {
                    $newFileName = 'resume_' . $userId . '_' . time() . '.' . $extension;
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetPath)) {
                        $resumePath = 'uploads/resumes/' . $newFileName;
                        
                        // ✅ FIXED: PostgreSQL uses $1 placeholder
                        updateRecord("UPDATE applicants SET resume_path = $1 WHERE user_id = $2", [$resumePath, $userId]);
                    } else {
                        $message = 'Failed to upload resume.';
                        $messageType = 'error';
                    }
                }
            }
            
            // If no error, proceed with application
            if (empty($message)) {
                // ✅ FIXED: Create application - PostgreSQL uses $1, $2, $3, $4 placeholders
                $insertSql = "INSERT INTO applications (job_order_id, applicant_id, cover_letter, resume_path, status, applied_at) 
                              VALUES ($1, $2, $3, $4, 'pending', NOW())";
                
                $applicationId = insertRecord($insertSql, [
                    $jobId,
                    $applicant['id'],
                    $coverLetter,
                    $resumePath
                ]);
                
                if ($applicationId) {
                    $message = 'Application submitted successfully!';
                    $messageType = 'success';
                    $showSuccess = true;
                    $hasApplied = true;
                    $applicationStatus = 'pending';
                    
                    logActivity($userId, 'Job Application Submitted', 'applications', $applicationId, 
                               'Applied to job: ' . $job['title']);
                } else {
                    $message = 'Failed to submit application. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    }
}

// Get similar jobs
// ✅ FIXED: PostgreSQL uses $1, $2, $3, $4 placeholders
$similarJobsSql = "SELECT j.*, c.company_name 
                   FROM job_orders j
                   JOIN clients c ON j.client_id = c.id
                   WHERE j.id != $1 
                   AND j.status IN ('open', 'ongoing')
                   AND (j.job_type = $2 OR j.location = $3)
                   AND j.client_id != $4
                   ORDER BY j.created_at DESC
                   LIMIT 4";

$similarJobs = getRecords($similarJobsSql, [
    $jobId,
    $job['job_type'] ?? '',
    $job['location'] ?? '',
    $job['client_id'] ?? 0
]);

$statusLabels = [
    'pending' => 'Pending Review',
    'reviewed' => 'Reviewed',
    'shortlisted' => 'Shortlisted',
    'hired' => 'Hired',
    'rejected' => 'Rejected'
];

$statusBadges = [
    'pending' => 'badge-pending',
    'reviewed' => 'badge-reviewed',
    'shortlisted' => 'badge-shortlisted',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected'
];

// =============================================
// DEBUG: Check if match score is set
// =============================================
error_log("=== JOB DETAILS MATCH SCORE DEBUG ===");
error_log("Applicant Skills: " . ($applicant['skills'] ?? 'EMPTY'));
error_log("Job Skills Required: " . ($job['skills_required'] ?? 'EMPTY'));
error_log("Match Score: " . ($matchScore ?? 'NULL'));
error_log("=====================================");
?>
<!-- HTML CONTENT REMAINS THE SAME -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($job['title']); ?> - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           JOB DETAILS - SINGLE CONTAINER
           ========================================================================== */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
            --primary-light: #818cf8;
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
            --success-color: #22c55e;
            --error-color: #dc2626;
            --warning-color: #f59e0b;
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
           AI MATCH SCORE CARD
        ============================================= */
        .match-score-card {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 1px solid #c4b5fd;
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
        }

        .match-score-card .score-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: white;
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.15);
        }

        .match-score-card .score-circle .score-number {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
        }

        .match-score-card .score-circle .score-label {
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .match-score-card .score-circle.high .score-number { color: #059669; }
        .match-score-card .score-circle.medium .score-number { color: #d97706; }
        .match-score-card .score-circle.low .score-number { color: #dc2626; }

        .match-score-card .score-details {
            flex: 1;
            min-width: 200px;
        }

        .match-score-card .score-details .score-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .match-score-card .score-details .score-recommendation {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            line-height: 1.5;
        }

        .match-score-card .score-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .match-score-card .score-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 500;
        }

        .match-score-card .score-tag.strength {
            background: #d1fae5;
            color: #065f46;
        }

        .match-score-card .score-tag.gap {
            background: #fef3c7;
            color: #92400e;
        }

        .match-score-card .score-provider {
            font-size: 0.6rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .match-score-card .no-skills {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            width: 100%;
        }

        .match-score-card .no-skills .material-symbols-outlined {
            font-size: 2rem;
            color: #92400e;
        }

        .match-score-card .no-skills .no-skills-text {
            flex: 1;
        }

        .match-score-card .no-skills .no-skills-text strong {
            font-weight: 600;
            color: #92400e;
            display: block;
        }

        .match-score-card .no-skills .no-skills-text span {
            font-size: 0.8125rem;
            color: #92400e;
        }

        .match-score-card .no-skills .no-skills-link {
            margin-top: 0.25rem;
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4f46e5;
        }

        /* Face Registration Banner */
        .face-register-banner {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 1px solid #fcd34d;
            border-radius: var(--radius-xl);
            padding: 1rem 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .face-register-banner .banner-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .face-register-banner .banner-content .material-symbols-outlined {
            font-size: 2rem;
            color: #92400e;
        }

        .face-register-banner .banner-content .banner-text {
            font-size: 0.875rem;
            color: #92400e;
        }

        .face-register-banner .banner-content .banner-text strong {
            display: block;
            font-weight: 700;
            color: #78350f;
        }

        .face-register-banner .btn-register-face {
            background: #78350f;
            color: #fef3c7;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: var(--radius-full);
            font-weight: 600;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-family: var(--font-sans);
        }

        .face-register-banner .btn-register-face:hover {
            background: #92400e;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .face-register-banner .btn-register-face .material-symbols-outlined {
            font-size: 1.125rem;
        }

        /* =============================================
           SINGLE CONTAINER - JOB DETAILS
        ============================================= */
        .job-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .section-divider {
            height: 2px;
            background: linear-gradient(to right, var(--primary), var(--primary-light), transparent);
            margin: 0;
            border: none;
            opacity: 0.6;
        }

        .job-header {
            padding: 1.5rem 2rem;
            background: var(--bg-surface-low);
            border-bottom: 3px solid var(--primary);
        }
        @media (max-width: 640px) {
            .job-header { padding: 1rem 1.25rem; }
        }

        .job-header .job-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }
        .job-header .job-company {
            font-size: 1rem;
            color: var(--primary);
            font-weight: 600;
            margin-top: 0.125rem;
        }
        .job-header .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-top: 0.75rem;
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }
        .job-header .job-meta .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
            color: var(--primary);
        }
        .job-header .job-status {
            margin-top: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-open { background: #d1fae5; color: #059669; }
        .badge-ongoing { background: #dbeafe; color: #2563eb; }
        .badge-closed { background: #f1f5f9; color: #64748b; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-reviewed { background: #dbeafe; color: #2563eb; }
        .badge-shortlisted { background: #d1fae5; color: #059669; }
        .badge-hired { background: #a7f3d0; color: #047857; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }

        .face-verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .face-verified-badge .material-symbols-outlined {
            font-size: 0.875rem;
        }

        .job-section {
            padding: 1.25rem 2rem;
        }
        .job-section:last-child {
            padding-bottom: 1.5rem;
        }
        @media (max-width: 640px) {
            .job-section { padding: 1rem 1.25rem; }
            .job-section:last-child { padding-bottom: 1rem; }
        }

        .job-section .section-title {
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
        .job-section .section-title .material-symbols-outlined {
            color: var(--primary);
            font-size: 1.25rem;
        }

        .job-section .section-content {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            line-height: 1.8;
            white-space: pre-wrap;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem 1.5rem;
        }
        @media (max-width: 640px) {
            .info-grid { grid-template-columns: 1fr; }
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 0.375rem 0;
            border-bottom: 1px solid var(--slate-100);
        }
        .info-item:last-child { border-bottom: none; }
        .info-item .label {
            color: var(--text-on-surface-variant);
            font-size: 0.8125rem;
        }
        .info-item .value {
            font-weight: 600;
            color: var(--text-on-surface);
            font-size: 0.875rem;
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
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--primary-container); }
        .btn-ghost { background: transparent; color: var(--text-on-surface-variant); }
        .btn-ghost:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        .apply-btn {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        }

        .apply-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.45);
            background: linear-gradient(135deg, #4338ca, #6d28d9);
        }

        .applied-status {
            text-align: center;
            padding: 1rem;
            background: var(--bg-surface);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }
        .applied-status .material-symbols-outlined {
            font-size: 2.5rem;
            color: var(--primary);
        }
        .applied-status .status-text {
            font-size: 1rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        .applied-status .status-sub {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .similar-job-card {
            padding: 0.75rem 1rem;
            border: 1px solid var(--slate-200);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
            transition: all var(--transition-fast);
            cursor: pointer;
        }
        .similar-job-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        .similar-job-card .job-title-sm {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }
        .similar-job-card .job-company-sm {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .similar-job-card .job-meta-sm {
            display: flex;
            gap: 1rem;
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

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

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.1875rem;
        }
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

        .file-input-wrapper {
            position: relative;
        }
        .file-input-wrapper input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 2;
        }
        .file-input-wrapper .btn {
            width: 100%;
            pointer-events: none;
            justify-content: center;
            padding: 0.5rem;
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
            .job-header .job-title { font-size: 1.25rem; }
            .info-grid { grid-template-columns: 1fr; }
            .match-score-card { flex-direction: column; align-items: stretch; text-align: center; }
            .match-score-card .score-circle { margin: 0 auto; }
            .match-score-card .score-tags { justify-content: center; }
            .face-register-banner { flex-direction: column; text-align: center; }
            .face-register-banner .banner-content { flex-direction: column; text-align: center; }
            .job-header .job-status { justify-content: center; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .job-header { padding: 0.75rem 1rem; }
            .job-section { padding: 0.75rem 1rem; }
            .job-section:last-child { padding-bottom: 0.75rem; }
            .job-header .job-title { font-size: 1.125rem; }
            .match-score-card .score-circle { width: 64px; height: 64px; }
            .match-score-card .score-circle .score-number { font-size: 1.25rem; }
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
.sidebar-logo {
    width: 3.5rem;
    height: 3.5rem;
    object-fit: contain;
    border-radius: 0.75rem;
    display: block;
    margin: 0 auto;
}

/* For collapsed sidebar */
.dashboard-sidebar.collapsed .sidebar-logo {
    width: 2.5rem;
    height: 2.5rem;
}

/* If using Option 2 - background image on icon */
.sidebar-brand-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 1.75rem;
    background-size: contain !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-color: transparent !important;
    flex-shrink: 0;
}
    </style>
</head>
<body>

    <!-- Sidebar Backdrop (Mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =============================================
    SIDEBAR - FIXED POSITION
    ============================================= -->
   <aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <img src="logo.png" alt="ISMERS" class="sidebar-logo">
        <p class="sidebar-brand-category">Applicant Portal</p>
    </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="offers.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">My Offers</span>
                <span class="nav-badge"><?php echo $pendingOffers; ?></span>
            </a>

            <a href="interview.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
                <span class="nav-badge"><?php echo $interviewCount; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">search</span>
                <span class="nav-text">Job Search</span>
            </a>

        </nav>
        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-wrapper" id="mainWrapper">
       
       <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
<img src="logo.png" alt="ISMERS" class="logo" style="height: 2rem; width: auto;">     
           <span class="separator">|</span>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle Sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">menu_open</span>
                </button>
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" title="Open Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <span class="logo-text" style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface); display:none;">ISMERS</span>
            </div>

            <!-- Profile Dropdown -->
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Applicant</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-header">Account</div>
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
                        <span class="material-symbols-outlined">work</span>
                        <span>Job Details</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($job['title']); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                </div>

                <!-- ============================================= -->
                <!-- AI MATCH SCORE CARD - WILL ALWAYS SHOW IF APPLICANT HAS SKILLS -->
                <!-- ============================================= -->
                <?php if ($matchScore !== null && $matchScore >= 0): ?>
                <div class="match-score-card">
                    <?php 
                    $scoreClass = 'low';
                    if ($matchScore >= 70) $scoreClass = 'high';
                    elseif ($matchScore >= 40) $scoreClass = 'medium';
                    ?>
                    <div class="score-circle <?php echo $scoreClass; ?>">
                        <span class="score-number"><?php echo $matchScore; ?>%</span>
                        <span class="score-label">Match</span>
                    </div>
                    <div class="score-details">
                        <div class="score-title">Your Match Score</div>
                        <div class="score-recommendation"><?php echo htmlspecialchars($matchRecommendation); ?></div>
                        
                        <?php if (!empty($matchStrengths) || !empty($matchGaps)): ?>
                        <div class="score-tags">
                            <?php foreach (array_slice($matchStrengths, 0, 2) as $strength): ?>
                                <span class="score-tag strength">
                                    <span class="material-symbols-outlined" style="font-size:0.75rem;">check</span>
                                    <?php echo htmlspecialchars($strength); ?>
                                </span>
                            <?php endforeach; ?>
                            <?php foreach (array_slice($matchGaps, 0, 2) as $gap): ?>
                                <span class="score-tag gap">
                                    <span class="material-symbols-outlined" style="font-size:0.75rem;">warning</span>
                                    <?php echo htmlspecialchars($gap); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($matchProvider && $matchProvider !== 'mock' && $matchProvider !== 'unknown' && $matchProvider !== 'fallback'): ?>
                        <div class="score-provider">✨ Powered by <?php echo ucfirst($matchProvider); ?></div>
                        <?php elseif ($matchProvider === 'fallback'): ?>
                        <div class="score-provider">📊 Based on your skills</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($applicant && empty($applicant['skills'])): ?>
                <!-- Profile Incomplete Message -->
                <div class="match-score-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #fcd34d;">
                    <div class="no-skills">
                        <span class="material-symbols-outlined">info</span>
                        <div class="no-skills-text">
                            <strong>Profile Incomplete</strong>
                            <span>Add your skills to see your match score for this job.</span>
                            <a href="profile.php" class="no-skills-link">Update Profile →</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Face Registration Banner -->
                <?php if (!$faceVerified && $applicant): ?>
                <div class="face-register-banner">
                    <div class="banner-content">
                        <span class="material-symbols-outlined">verified</span>
                        <div class="banner-text">
                            <strong>Face Verification Required</strong>
                            You need to register your face before applying to jobs.
                        </div>
                    </div>
                    <a href="register_face.php?redirect=job_details.php?id=<?php echo $jobId; ?>" class="btn-register-face">
                        <span class="material-symbols-outlined">scan</span>
                        Register Face Now
                    </a>
                </div>
                <?php endif; ?>

                <!-- Single Job Card -->
                <div class="job-card">
                    <!-- Job Header -->
                    <div class="job-header">
                        <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                        <div class="job-company"><?php echo htmlspecialchars($job['company_name']); ?></div>
                        <div class="job-meta">
                            <span>
                                <span class="material-symbols-outlined">location_on</span>
                                <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                            </span>
                            <span>
                                <span class="material-symbols-outlined">work</span>
                                <?php echo ucfirst(str_replace('_', ' ', $job['job_type'] ?? 'Full-time')); ?>
                            </span>
                            <?php if (!empty($job['salary_min']) || !empty($job['salary_max'])): ?>
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
                                <?php echo $job['total_applicants'] ?? 0; ?> applicants
                            </span>
                            <span>
                                <span class="material-symbols-outlined">schedule</span>
                                Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                            </span>
                        </div>
                        <div class="job-status">
                            <span class="badge badge-<?php echo $job['status']; ?>">
                                <?php echo ucfirst($job['status']); ?>
                            </span>
                            <?php if ($faceVerified): ?>
                            <span class="face-verified-badge">
                                <span class="material-symbols-outlined">verified</span>
                                Face Verified
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Job Description -->
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">description</span>
                            Job Description
                        </div>
                        <div class="section-content">
                            <?php echo nl2br(htmlspecialchars($job['description'] ?? '')); ?>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Skills Required -->
                    <?php if (!empty($job['skills_required'])): ?>
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">star</span>
                            Skills Required
                        </div>
                        <div class="section-content">
                            <?php echo nl2br(htmlspecialchars($job['skills_required'])); ?>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">
                    <?php endif; ?>

                    <!-- Job Overview -->
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">info</span>
                            Job Overview
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="label">Position</span>
                                <span class="value"><?php echo htmlspecialchars($job['title']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Company</span>
                                <span class="value"><?php echo htmlspecialchars($job['company_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Location</span>
                                <span class="value"><?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Job Type</span>
                                <span class="value"><?php echo ucfirst(str_replace('_', ' ', $job['job_type'] ?? 'Full-time')); ?></span>
                            </div>
                            <?php if (!empty($job['salary_min']) || !empty($job['salary_max'])): ?>
                            <div class="info-item">
                                <span class="label">Salary</span>
                                <span class="value">
                                    <?php if (!empty($job['salary_min']) && !empty($job['salary_max'])): ?>
                                        ₱<?php echo number_format($job['salary_min']); ?> - ₱<?php echo number_format($job['salary_max']); ?>
                                    <?php elseif (!empty($job['salary_min'])): ?>
                                        ₱<?php echo number_format($job['salary_min']); ?>+
                                    <?php elseif (!empty($job['salary_max'])): ?>
                                        Up to ₱<?php echo number_format($job['salary_max']); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <span class="label">Posted</span>
                                <span class="value"><?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Posted By</span>
                                <span class="value"><?php echo htmlspecialchars($job['client_first_name'] . ' ' . $job['client_last_name']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Apply Section -->
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">send</span>
                            Apply Now
                        </div>

                        <?php if ($hasApplied): ?>
                            <div class="applied-status">
                                <span class="material-symbols-outlined">check_circle</span>
                                <div class="status-text">You have applied!</div>
                                <div class="status-sub">
                                    Status: <span class="badge <?php echo $statusBadges[$applicationStatus] ?? 'badge-pending'; ?>">
                                        <?php echo $statusLabels[$applicationStatus] ?? ucfirst($applicationStatus); ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Face Verification Status -->
                            <?php if ($faceVerified): ?>
                            <div style="margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0.75rem; background:#d1fae5; border-radius:0.5rem; border:1px solid #6ee7b7;">
                                <span class="material-symbols-outlined" style="color:#059669;">verified</span>
                                <span style="font-size:0.8125rem; color:#065f46; font-weight:500;">Face verified ✓</span>
                            </div>
                            <?php endif; ?>

                            <?php if (!$faceVerified): ?>
                            <!-- If not verified, show disabled apply button with register link -->
                            <button class="btn btn-primary btn-block apply-btn" onclick="window.location.href='register_face.php?redirect=job_details.php?id=<?php echo $jobId; ?>'" style="background: linear-gradient(135deg, #92400e, #78350f);">
                                <span class="material-symbols-outlined">scan</span>
                                Register Face to Apply
                            </button>
                            <?php else: ?>
                            <!-- If verified, show apply button -->
                            <button class="btn btn-primary btn-block apply-btn" onclick="document.getElementById('applicationForm').style.display='block'; document.getElementById('applicationForm').scrollIntoView({ behavior: 'smooth', block: 'center' });">
                                <span class="material-symbols-outlined">send</span>
                                Apply Now
                            </button>
                            <?php endif; ?>

                            <!-- Application Form -->
                            <div class="application-form" id="applicationForm" style="display:none; margin-top:1rem;">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="apply">
                                    
                                    <div class="form-group">
                                        <label for="coverLetter">Cover Letter</label>
                                        <textarea name="cover_letter" id="coverLetter" class="form-control" 
                                                  placeholder="Tell us why you're the perfect fit for this role..." 
                                                  rows="3"></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>Resume / CV</label>
                                        <div class="file-input-wrapper">
                                            <input type="file" name="resume" accept=".pdf,.doc,.docx">
                                            <button type="button" class="btn btn-outline btn-block">
                                                <span class="material-symbols-outlined">upload</span>
                                                Upload Resume (PDF, DOC, DOCX)
                                            </button>
                                        </div>
                                        <div style="font-size:0.6875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                                            Max 5MB. Leave empty to use your uploaded resume.
                                        </div>
                                        <?php if (!empty($applicant['resume_path'])): ?>
                                        <div style="font-size:0.75rem; color:var(--primary); margin-top:0.25rem;">
                                            ✅ Current resume: <?php echo htmlspecialchars(basename($applicant['resume_path'])); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div style="display:flex; gap:0.5rem; margin-top:1rem;">
                                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('applicationForm').style.display='none';" style="flex:1;">
                                            Cancel
                                        </button>
                                        <button type="submit" class="btn btn-success" style="flex:2;">
                                            <span class="material-symbols-outlined">check</span>
                                            Submit Application
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Divider -->
                    <hr class="section-divider">

                    <!-- Similar Jobs -->
                    <?php if (!empty($similarJobs)): ?>
                    <div class="job-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">work</span>
                            Similar Jobs
                        </div>
                        <?php foreach ($similarJobs as $similarJob): ?>
                        <a href="job_details.php?id=<?php echo $similarJob['id']; ?>" class="similar-job-card">
                            <div class="job-title-sm"><?php echo htmlspecialchars($similarJob['title']); ?></div>
                            <div class="job-company-sm"><?php echo htmlspecialchars($similarJob['company_name']); ?></div>
                            <div class="job-meta-sm">
                                <span>📍 <?php echo htmlspecialchars($similarJob['location'] ?? 'Remote'); ?></span>
                                <span>📋 <?php echo ucfirst(str_replace('_', ' ', $similarJob['job_type'] ?? 'Full-time')); ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
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
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const isMobile = window.innerWidth <= 768;
        const savedState = localStorage.getItem('sidebarCollapsed');

        if (savedState === 'true' && !isMobile) {
            sidebar.classList.add('collapsed');
            sidebarToggleIcon.textContent = 'chevron_right';
        }

        sidebarToggleBtn.addEventListener('click', function() {
            if (window.innerWidth <= 768) return;
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            sidebarToggleIcon.textContent = isCollapsed ? 'chevron_right' : 'chevron_left';
            localStorage.setItem('sidebarCollapsed', isCollapsed);
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
        // FILE INPUT LABEL UPDATE
        // =============================================
        document.querySelector('input[type="file"]')?.addEventListener('change', function() {
            if (this.files.length > 0) {
                const label = this.closest('.file-input-wrapper').querySelector('.btn');
                if (label) {
                    label.innerHTML = `<span class="material-symbols-outlined">check</span> ${this.files[0].name}`;
                    label.style.borderColor = '#059669';
                    label.style.color = '#059669';
                }
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
                document.getElementById('applicationForm').style.display = 'none';
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
                    sidebarToggleIcon.textContent = 'chevron_left';
                } else {
                    sidebar.classList.remove('mobile-open');
                    sidebarBackdrop.classList.remove('active');
                    document.body.style.overflow = '';
                    const saved = localStorage.getItem('sidebarCollapsed');
                    if (saved === 'true') {
                        sidebar.classList.add('collapsed');
                        sidebarToggleIcon.textContent = 'chevron_right';
                    } else {
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'chevron_left';
                    }
                }
            }, 250);
        });

        console.log('💼 ISMERS Job Details loaded successfully!');
        <?php if ($matchScore !== null): ?>
        console.log('🤖 Match Score: <?php echo $matchScore; ?>%');
        <?php else: ?>
        console.log('⚠️ Match Score: Not calculated');
        <?php endif; ?>
        <?php if ($faceVerified): ?>
        console.log('✅ Face Verified: Yes');
        <?php else: ?>
        console.log('⚠️ Face Not Verified');
        <?php endif; ?>
    </script>

</body>
</html>