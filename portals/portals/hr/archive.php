<?php
// portals/hr/archive.php - HR Archive Management System
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
require_once '../../app/email_functions.php';

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

// =============================================
// GET ARCHIVE COUNTS FOR SIDEBAR - PostgreSQL syntax
// =============================================

$examCount = 0;
$examResult = @getRecord("SELECT COUNT(*) as count FROM examination_records", []);
if ($examResult && isset($examResult['count'])) {
    $examCount = (int)$examResult['count'];
}

$evalCount = 0;
$evalResult = @getRecord("SELECT COUNT(*) as count FROM interview_evaluations", []);
if ($evalResult && isset($evalResult['count'])) {
    $evalCount = (int)$evalResult['count'];
}

$assignmentCount = 0;
$assignmentResult = @getRecord("SELECT COUNT(*) as count FROM client_assignments", []);
if ($assignmentResult && isset($assignmentResult['count'])) {
    $assignmentCount = (int)$assignmentResult['count'];
}

$deploymentArchiveCount = 0;
$archiveResult = @getRecord("SELECT COUNT(*) as count FROM deployment_archive", []);
if ($archiveResult && isset($archiveResult['count'])) {
    $deploymentArchiveCount = (int)$archiveResult['count'];
}

// =============================================
// GET FILTERS
// =============================================
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'examinations';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$clientFilter = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get all clients for filter dropdown - PostgreSQL syntax
$clients = @getRecords("SELECT id, company_name FROM clients ORDER BY company_name", []);
if (!is_array($clients)) $clients = [];

// =============================================
// GET SIDEBAR COUNTS - PostgreSQL syntax
// =============================================
$pendingAppsCount = 0;
$pendingResult = @getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", []);
if ($pendingResult && isset($pendingResult['count'])) {
    $pendingAppsCount = (int)$pendingResult['count'];
}

$totalArchived = $examCount + $evalCount + $assignmentCount + $deploymentArchiveCount;

// =============================================
// HANDLE AJAX POST ACTIONS - PostgreSQL syntax
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    // =============================================
    // VIEW EXAMINATION DETAILS - PostgreSQL
    // =============================================
    if ($action === 'view_examination' && $id > 0) {
        $exam = @getRecord("
            SELECT e.*, 
                   u.first_name, u.last_name, u.email,
                   a.applicant_id,
                   jo.title as job_title,
                   ev.first_name as evaluator_first_name, ev.last_name as evaluator_last_name
            FROM examination_records e
            JOIN applicants ap ON e.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN applications a ON e.application_id = a.id
            JOIN job_orders jo ON e.job_order_id = jo.id
            LEFT JOIN users ev ON e.evaluator_id = ev.id
            WHERE e.id = $1
        ", [$id]);
        
        if ($exam) {
            echo json_encode(['success' => true, 'data' => $exam]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Examination record not found.']);
        }
        exit;
    }
    
    // =============================================
    // VIEW INTERVIEW EVALUATION DETAILS - PostgreSQL
    // =============================================
    if ($action === 'view_evaluation' && $id > 0) {
        $eval = @getRecord("
            SELECT ie.*,
                   u.first_name, u.last_name, u.email,
                   a.applicant_id,
                   jo.title as job_title,
                   ev.first_name as evaluator_first_name, ev.last_name as evaluator_last_name,
                   c.company_name,
                   intv.interview_date,
                   intv.ai_questions,
                   intv.feedback as interview_feedback,
                   intv.rating as interview_rating
            FROM interview_evaluations ie
            JOIN applicants ap ON ie.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN applications a ON ie.application_id = a.id
            JOIN job_orders jo ON ie.job_order_id = jo.id
            JOIN users ev ON ie.evaluator_id = ev.id
            JOIN clients c ON jo.client_id = c.id
            LEFT JOIN interviews intv ON ie.interview_id = intv.id
            WHERE ie.id = $1
        ", [$id]);
        
        if ($eval) {
            if ($eval['ai_questions']) {
                $eval['ai_questions_decoded'] = @json_decode($eval['ai_questions'], true);
            }
            echo json_encode(['success' => true, 'data' => $eval]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Evaluation record not found.']);
        }
        exit;
    }
    
    // =============================================
    // VIEW ASSIGNMENT DETAILS - PostgreSQL
    // =============================================
    if ($action === 'view_assignment' && $id > 0) {
        $assignment = @getRecord("
            SELECT ca.*,
                   u.first_name, u.last_name, u.email,
                   c.company_name,
                   jo.title as job_title,
                   m.first_name as manager_first_name, m.last_name as manager_last_name,
                   cb.first_name as created_by_first_name, cb.last_name as created_by_last_name
            FROM client_assignments ca
            JOIN applicants ap ON ca.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN clients c ON ca.client_id = c.id
            JOIN job_orders jo ON ca.job_order_id = jo.id
            LEFT JOIN users m ON ca.manager_id = m.id
            LEFT JOIN users cb ON ca.created_by = cb.id
            WHERE ca.id = $1
        ", [$id]);
        
        if ($assignment) {
            echo json_encode(['success' => true, 'data' => $assignment]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Assignment record not found.']);
        }
        exit;
    }
    
    // =============================================
    // VIEW DEPLOYMENT ARCHIVE DETAILS - PostgreSQL
    // =============================================
    if ($action === 'view_deployment_archive' && $id > 0) {
        $archive = @getRecord("
            SELECT da.*,
                   u.first_name, u.last_name, u.email,
                   c.company_name,
                   jo.title as job_title,
                   m.first_name as manager_first_name, m.last_name as manager_last_name,
                   ab.first_name as archived_by_first_name, ab.last_name as archived_by_last_name
            FROM deployment_archive da
            JOIN applicants ap ON da.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN clients c ON da.client_id = c.id
            JOIN job_orders jo ON da.job_order_id = jo.id
            LEFT JOIN users m ON da.manager_id = m.id
            LEFT JOIN users ab ON da.archived_by = ab.id
            WHERE da.id = $1
        ", [$id]);
        
        if ($archive) {
            echo json_encode(['success' => true, 'data' => $archive]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Archive record not found.']);
        }
        exit;
    }
    
    // =============================================
    // DELETE EXAMINATION - PostgreSQL
    // =============================================
    if ($action === 'delete_examination' && $id > 0) {
        $result = @deleteRecord("DELETE FROM examination_records WHERE id = $1", [$id]);
        if ($result) {
            @logActivity($userId, 'Deleted Examination Record', 'examination_records', $id, 'Examination record deleted');
            echo json_encode(['success' => true, 'message' => 'Examination record deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete record.']);
        }
        exit;
    }
    
    // =============================================
    // DELETE EVALUATION - PostgreSQL
    // =============================================
    if ($action === 'delete_evaluation' && $id > 0) {
        $result = @deleteRecord("DELETE FROM interview_evaluations WHERE id = $1", [$id]);
        if ($result) {
            @logActivity($userId, 'Deleted Interview Evaluation', 'interview_evaluations', $id, 'Interview evaluation deleted');
            echo json_encode(['success' => true, 'message' => 'Evaluation deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete record.']);
        }
        exit;
    }
    
    // =============================================
    // DELETE ASSIGNMENT - PostgreSQL
    // =============================================
    if ($action === 'delete_assignment' && $id > 0) {
        $result = @deleteRecord("DELETE FROM client_assignments WHERE id = $1", [$id]);
        if ($result) {
            @logActivity($userId, 'Deleted Client Assignment', 'client_assignments', $id, 'Client assignment deleted');
            echo json_encode(['success' => true, 'message' => 'Assignment deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete record.']);
        }
        exit;
    }
    
    // =============================================
    // DELETE DEPLOYMENT ARCHIVE - PostgreSQL
    // =============================================
    if ($action === 'delete_deployment_archive' && $id > 0) {
        $result = @deleteRecord("DELETE FROM deployment_archive WHERE id = $1", [$id]);
        if ($result) {
            @logActivity($userId, 'Deleted Deployment Archive', 'deployment_archive', $id, 'Deployment archive deleted');
            echo json_encode(['success' => true, 'message' => 'Archive record deleted successfully.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete record.']);
        }
        exit;
    }
    
    // =============================================
    // RESTORE FROM DEPLOYMENT ARCHIVE - PostgreSQL
    // =============================================
    if ($action === 'restore_deployment' && $id > 0) {
        $archive = @getRecord("SELECT * FROM deployment_archive WHERE id = $1", [$id]);
        if (!$archive) {
            echo json_encode(['success' => false, 'error' => 'Archive record not found.']);
            exit;
        }
        
        $insertSql = "INSERT INTO client_assignments (
            applicant_id, job_order_id, client_id, application_id, employee_id,
            assignment_date, start_date, end_date, status, position_title,
            department, manager_id, salary, salary_type, contract_type,
            notes, created_by, created_at
        ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'active', $9, $10, $11, $12, $13, $14, $15, $16, NOW())
        RETURNING id";
        
        $result = @insertRecord($insertSql, [
            $archive['applicant_id'],
            $archive['job_order_id'],
            $archive['client_id'],
            $archive['application_id'],
            $archive['employee_id'] ?? null,
            $archive['assignment_date'],
            $archive['start_date'],
            $archive['end_date'],
            $archive['position_title'],
            $archive['department'],
            $archive['manager_id'],
            $archive['salary'],
            $archive['salary_type'],
            $archive['contract_type'],
            $archive['notes'],
            $userId
        ]);
        
        if ($result) {
            @deleteRecord("DELETE FROM deployment_archive WHERE id = $1", [$id]);
            @logActivity($userId, 'Restored Deployment from Archive', 'deployment_archive', $id, 'Deployment restored from archive');
            echo json_encode(['success' => true, 'message' => 'Deployment restored successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to restore deployment.']);
        }
        exit;
    }
    
    // =============================================
    // ARCHIVE INTERVIEW - PostgreSQL
    // =============================================
    if ($action === 'archive_interview' && $id > 0) {
        $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $feedback = trim($_POST['feedback'] ?? '');
        $recommendation = trim($_POST['recommendation'] ?? 'consider');
        $strengths = trim($_POST['strengths'] ?? '');
        $weaknesses = trim($_POST['weaknesses'] ?? '');
        $overall_rating = isset($_POST['overall_rating']) ? (int)$_POST['overall_rating'] : 0;
        
        $interview = @getRecord("
            SELECT i.*, a.applicant_id, a.job_order_id, a.id as application_id,
                   ap.user_id
            FROM interviews i
            JOIN applications a ON i.application_id = a.id
            JOIN applicants ap ON a.applicant_id = ap.id
            WHERE i.id = $1
        ", [$id]);
        
        if (!$interview) {
            echo json_encode(['success' => false, 'error' => 'Interview not found.']);
            exit;
        }
        
        $insertSql = "INSERT INTO interview_evaluations (
            interview_id, application_id, applicant_id, job_order_id,
            evaluator_id, rating, overall_rating,
            communication_rating, technical_skills_rating, problem_solving_rating,
            cultural_fit_rating, leadership_potential,
            recommendation, strengths, weaknesses, comments,
            evaluation_date, created_at
        ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, NOW(), NOW())
        RETURNING id";
        
        $result = @insertRecord($insertSql, [
            $id,
            $interview['application_id'],
            $interview['applicant_id'],
            $interview['job_order_id'],
            $userId,
            $rating,
            $overall_rating,
            $rating,
            $rating,
            $rating,
            $rating,
            $rating,
            $recommendation,
            $strengths,
            $weaknesses,
            $feedback
        ]);
        
        if ($result) {
            @updateRecord("UPDATE interviews SET status = 'completed' WHERE id = $1", [$id]);
            @updateRecord("UPDATE applications SET status = 'interviewed' WHERE id = $1", [$interview['application_id']]);
            @logActivity($userId, 'Interview Archived', 'interview_evaluations', $result, 'Interview evaluation archived');
            echo json_encode(['success' => true, 'message' => 'Interview evaluation archived successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to archive interview evaluation.']);
        }
        exit;
    }
}

// =============================================
// GET DATA BASED ON TAB - PostgreSQL syntax
// =============================================

$examinations = [];
$evaluations = [];
$assignments = [];
$deploymentArchives = [];

// Build WHERE clause for searches
$whereClause = "";
$params = [];
$counter = 1;

if (!empty($search)) {
    $whereClause = " WHERE (u.first_name ILIKE $" . $counter . " OR u.last_name ILIKE $" . ($counter+1) . " OR u.email ILIKE $" . ($counter+2) . " OR jo.title ILIKE $" . ($counter+3) . ")";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam, $searchParam, $searchParam];
    $counter += 4;
}

if ($tab === 'examinations') {
    $sql = "SELECT e.*, 
                   u.first_name, u.last_name, u.email,
                   jo.title as job_title,
                   ev.first_name as evaluator_first_name, ev.last_name as evaluator_last_name
            FROM examination_records e
            JOIN applicants ap ON e.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON e.job_order_id = jo.id
            LEFT JOIN users ev ON e.evaluator_id = ev.id
            $whereClause
            ORDER BY e.exam_date DESC";
    $examinations = @getRecords($sql, $params);
    if (!is_array($examinations)) $examinations = [];
    
} elseif ($tab === 'evaluations') {
    $sql = "SELECT ie.*,
                   u.first_name, u.last_name, u.email,
                   jo.title as job_title,
                   c.company_name,
                   ev.first_name as evaluator_first_name, ev.last_name as evaluator_last_name,
                   intv.interview_date,
                   intv.ai_questions
            FROM interview_evaluations ie
            JOIN applicants ap ON ie.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON ie.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            JOIN users ev ON ie.evaluator_id = ev.id
            LEFT JOIN interviews intv ON ie.interview_id = intv.id
            $whereClause
            ORDER BY ie.evaluation_date DESC";
    $evaluations = @getRecords($sql, $params);
    if (!is_array($evaluations)) $evaluations = [];
    
} elseif ($tab === 'assignments') {
    $sql = "SELECT ca.*,
                   u.first_name, u.last_name, u.email,
                   c.company_name,
                   jo.title as job_title,
                   m.first_name as manager_first_name, m.last_name as manager_last_name,
                   cb.first_name as created_by_first_name, cb.last_name as created_by_last_name
            FROM client_assignments ca
            JOIN applicants ap ON ca.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN clients c ON ca.client_id = c.id
            JOIN job_orders jo ON ca.job_order_id = jo.id
            LEFT JOIN users m ON ca.manager_id = m.id
            LEFT JOIN users cb ON ca.created_by = cb.id
            $whereClause
            ORDER BY ca.created_at DESC";
    $assignments = @getRecords($sql, $params);
    if (!is_array($assignments)) $assignments = [];
    
} elseif ($tab === 'deployment_archive') {
    $sql = "SELECT da.*,
                   u.first_name, u.last_name, u.email,
                   c.company_name,
                   jo.title as job_title,
                   m.first_name as manager_first_name, m.last_name as manager_last_name,
                   ab.first_name as archived_by_first_name, ab.last_name as archived_by_last_name
            FROM deployment_archive da
            JOIN applicants ap ON da.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN clients c ON da.client_id = c.id
            JOIN job_orders jo ON da.job_order_id = jo.id
            LEFT JOIN users m ON da.manager_id = m.id
            LEFT JOIN users ab ON da.archived_by = ab.id
            $whereClause
            ORDER BY da.archived_at DESC";
    $deploymentArchives = @getRecords($sql, $params);
    if (!is_array($deploymentArchives)) $deploymentArchives = [];
}

// =============================================
// GET EXAMINATION STATISTICS - PostgreSQL
// =============================================
$examStats = @getRecord("
    SELECT 
        COUNT(*) as total,
        AVG(percentage) as avg_score,
        SUM(CASE WHEN result = 'passed' THEN 1 ELSE 0 END) as passed,
        SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN result = 'pending' THEN 1 ELSE 0 END) as pending
    FROM examination_records
", []);

// =============================================
// GET EVALUATION STATISTICS - PostgreSQL
// =============================================
$evalStats = @getRecord("
    SELECT 
        COUNT(*) as total,
        AVG(overall_rating) as avg_rating,
        SUM(CASE WHEN recommendation = 'hire' THEN 1 ELSE 0 END) as recommend_hire,
        SUM(CASE WHEN recommendation = 'consider' THEN 1 ELSE 0 END) as recommend_consider,
        SUM(CASE WHEN recommendation = 'reject' THEN 1 ELSE 0 END) as recommend_reject
    FROM interview_evaluations
", []);

// =============================================
// GET ASSIGNMENT STATISTICS - PostgreSQL
// =============================================
$assignmentStats = @getRecord("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) as terminated
    FROM client_assignments
", []);

// =============================================
// SIDEBAR BADGE COUNTS - PostgreSQL
// =============================================
$totalApplicants = 0;
$result = @getRecord("SELECT COUNT(*) as count FROM applicants", []);
if ($result && isset($result['count'])) {
    $totalApplicants = (int)$result['count'];
}

$totalJobs = 0;
$result = @getRecord("SELECT COUNT(*) as count FROM job_orders", []);
if ($result && isset($result['count'])) {
    $totalJobs = (int)$result['count'];
}

$totalApplications = 0;
$result = @getRecord("SELECT COUNT(*) as count FROM applications", []);
if ($result && isset($result['count'])) {
    $totalApplications = (int)$result['count'];
}

$totalInterviews = 0;
$result = @getRecord("SELECT COUNT(*) as count FROM interviews", []);
if ($result && isset($result['count'])) {
    $totalInterviews = (int)$result['count'];
}

$pendingApps = 0;
$result = @getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", []);
if ($result && isset($result['count'])) {
    $pendingApps = (int)$result['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>HR Archive - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           ARCHIVE MANAGEMENT - MATERIAL 3 DESIGN
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

        .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }

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

        .profile-dropdown-menu .dropdown-item:hover { background: var(--bg-surface-low); color: var(--primary); }
        .profile-dropdown-menu .dropdown-item .material-symbols-outlined { font-size: 1.125rem; color: var(--text-on-surface-variant); }
        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined { color: var(--primary); }
        .profile-dropdown-menu .dropdown-item.danger { color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger:hover { background: #fef2f2; color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined { color: #dc2626; }
        .profile-dropdown-menu .dropdown-divider { height: 1px; background: var(--slate-200); margin: 0.25rem 0.5rem; }

        /* =============================================
           MAIN SCROLLABLE AREA
        ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }

        .main-scroll .container { max-width: 80rem; margin: 0 auto; }

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

        .breadcrumb-view .material-symbols-outlined { font-size: 1.25rem; }
        .breadcrumb-view .status-dot { width: 0.5rem; height: 0.5rem; border-radius: 50%; background: #22c55e; animation: pulse 2s infinite; }

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
            .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
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

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--on-primary-fixed-variant); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--bg-surface-low); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-success:hover { background: #16a34a; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-warning:hover { background: #d97706; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: var(--error-color); color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; border-radius: 0.5rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 1rem; }

        /* =============================================
           STATS CARDS
        ============================================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
        }

        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .stat-card .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            float: right;
        }

        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.5rem; }

        /* =============================================
           TABS
        ============================================= */
        .archive-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            padding: 0.5rem;
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
        }

        .archive-tab {
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            border: none;
            background: transparent;
            color: var(--text-on-surface-variant);
            font-weight: 600;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .archive-tab:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .archive-tab.active { background: var(--primary); color: white; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
        .archive-tab .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 0.375rem;
            border-radius: 50px;
            font-size: 0.625rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.2);
            color: inherit;
        }
        .archive-tab.active .tab-count { background: rgba(255, 255, 255, 0.25); }
        .archive-tab:not(.active) .tab-count { background: var(--slate-100); color: var(--text-on-surface-variant); }

        /* =============================================
           SEARCH BAR
        ============================================= */
        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.25rem;
        }

        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.625rem 0.875rem 0.625rem 2.75rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }

        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .search-bar .search-input-wrapper input::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        /* =============================================
           TABLE
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .card-header h3 .material-symbols-outlined { font-size: 1.25rem; color: var(--primary); }
        .card-header .record-count { font-size: 0.8125rem; color: var(--text-on-surface-variant); background: var(--bg-surface-low); padding: 0.25rem 0.75rem; border-radius: var(--radius-full); }

        .card-body { padding: 0; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            min-width: 700px;
        }

        table thead { background: var(--bg-surface-low); }

        table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }

        table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }

        table tbody tr:hover td { background: var(--bg-surface-low); }
        table tbody tr:last-child td { border-bottom: none; }

        /* =============================================
           BADGES
        ============================================= */
        .badge {
            display: inline-block;
            padding: 0.1875rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-danger { background: #fecaca; color: #dc2626; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        .badge-gray { background: #f3f4f6; color: #6b7280; }

        .badge-passed { background: #d1fae5; color: #059669; }
        .badge-failed { background: #fecaca; color: #dc2626; }
        .badge-pending { background: #fef3c7; color: #d97706; }

        .badge-hire { background: #d1fae5; color: #059669; }
        .badge-consider { background: #fef3c7; color: #d97706; }
        .badge-reject { background: #fecaca; color: #dc2626; }
        .badge-hold { background: #dbeafe; color: #2563eb; }

        .badge-active { background: #d1fae5; color: #059669; }
        .badge-completed { background: #dbeafe; color: #2563eb; }
        .badge-terminated { background: #fecaca; color: #dc2626; }
        .badge-on-hold { background: #fef3c7; color: #d97706; }
        .badge-archived { background: #f3f4f6; color: #6b7280; }

        /* AI Badge */
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
        .ai-badge .material-symbols-outlined {
            font-size: 0.75rem;
        }

        /* =============================================
           ACTION BUTTONS
        ============================================= */
        .action-buttons {
            display: flex;
            gap: 0.375rem;
            justify-content: center;
            flex-wrap: wrap;
            align-items: center;
        }

        /* =============================================
           EMPTY STATE
        ============================================= */
        .empty-state {
            text-align: center;
            padding: 4rem 1.5rem;
        }

        .empty-state .material-symbols-outlined {
            font-size: 4rem;
            color: var(--slate-200);
            display: block;
            margin-bottom: 1rem;
        }

        .empty-state h4 { font-size: 1.125rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
        .empty-state p { font-size: 0.875rem; color: var(--text-on-surface-variant); }

        /* =============================================
           MODALS
        ============================================= */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
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
            max-width: 52rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalSlideUp {
            from { transform: translateY(20px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .modal-header h2 .material-symbols-outlined { font-size: 1.5rem; color: var(--primary); }

        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }

        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-close .material-symbols-outlined { font-size: 1.5rem; }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-shrink: 0;
        }

        /* =============================================
           DETAIL GRID
        ============================================= */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .detail-item { margin-bottom: 0.25rem; }

        .detail-item .label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-item .value {
            font-size: 0.875rem;
            color: var(--text-on-surface);
            padding: 0.5rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            margin-top: 0.125rem;
        }

        .detail-item.full-width { grid-column: 1 / -1; }

        /* AI Questions Display */
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
        .ai-questions-box .question-category:first-child {
            margin-top: 0;
        }
        .ai-questions-box .question-item {
            padding: 0.25rem 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
            border-bottom: 1px solid var(--slate-100);
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .ai-questions-box .question-item:last-child {
            border-bottom: none;
        }
        .ai-questions-box .question-item .q-number {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.7rem;
            min-width: 1.25rem;
        }

        /* =============================================
           RATING STARS
        ============================================= */
        .rating-stars {
            color: #f59e0b;
            font-size: 1rem;
            letter-spacing: 0.1rem;
        }

        .rating-stars .filled { color: #f59e0b; }
        .rating-stars .empty { color: #d1d5db; }

        /* =============================================
           TOAST
        ============================================= */
        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            animation: slideUp 0.4s ease-out;
            max-width: 400px;
        }

        .toast.success { background: var(--success-color); }
        .toast.error { background: var(--error-color); }
        .toast.info { background: var(--primary); }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-xl); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
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
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .archive-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; padding: 0.5rem; gap: 0.25rem; }
            .archive-tab { padding: 0.375rem 0.75rem; font-size: 0.75rem; white-space: nowrap; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-header { padding: 1rem 1.25rem; }
            .modal-body { padding: 1rem 1.25rem; }
            .modal-footer { padding: 0.75rem 1.25rem; flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
            table { font-size: 0.8125rem; min-width: 600px; }
            table th, table td { padding: 0.5rem 0.75rem; }
            .action-buttons .btn-sm { font-size: 0.6875rem; padding: 0.25rem 0.5rem; }
            .dashboard-sidebar.collapsed .sidebar-brand-text,
            .dashboard-sidebar.collapsed .sidebar-brand-category,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
            .dashboard-sidebar.collapsed .sidebar-footer .user-info {
                opacity: 1; width: auto; overflow: visible;
            }
            .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1.5rem; }
            .dashboard-sidebar.collapsed .sidebar-nav { padding: 1.5rem 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: flex-start; padding: 0.75rem 1rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: flex-start; padding: 0.5rem 0.75rem; }
        }

        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.75rem 1rem; }
            .page-header h1 { font-size: 1.5rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .stat-card { padding: 0.75rem 1rem; }
            .stat-card .stat-number { font-size: 1.25rem; }
            .modal-body { padding: 0.75rem 1rem; }
            .toast { max-width: 90%; bottom: 1rem; right: 1rem; }
        }

        /* Scrollbar */
        .main-scroll::-webkit-scrollbar { width: 6px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 3px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-500); }

        /* Loading spinner */
        @keyframes spin {
            to { transform: rotate(360deg); }
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

            <a href="dashboard.php" class="sidebar-main-link">
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
                <span class="nav-badge"><?php echo $pendingApps; ?></span>
            </a>


            <a href="interviews.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
            </a>

            <a href="offers.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Offers</span>
            </a>

            <a href="archive.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">archive</span>
                <span class="nav-text">Archive</span>
                <span class="nav-badge">
                    <?php echo $examCount + $evalCount + $assignmentCount + $deploymentArchiveCount; ?>
                </span>
            </a>

            <a href="apply_agency.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">apartment</span>
                <span class="nav-text">Apply as Agency</span>
            </a>

            <a href="deployments.php" class="sidebar-main-link">
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

    <!-- =============================================
    MAIN CONTENT
    ============================================= -->
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

        <!-- Scrollable Content -->
        <main class="main-scroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">archive</span>
                        <span>Archive</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php 
                            $tabLabels = [
                                'examinations' => 'Examination Records',
                                'evaluations' => 'Interview Evaluations',
                                'assignments' => 'Client Assignments',
                                'deployment_archive' => 'Deployment Archive'
                            ];
                            echo $tabLabels[$tab] ?? 'Archive';
                            ?>
                            (<?php 
                                $counts = [
                                    'examinations' => count($examinations),
                                    'evaluations' => count($evaluations),
                                    'assignments' => count($assignments),
                                    'deployment_archive' => count($deploymentArchives)
                                ];
                                echo $counts[$tab] ?? 0;
                            ?> records)
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Archive Management</h1>
                        <p>View and manage examination records, interview evaluations, and client assignments</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-icon">
                            <span class="material-symbols-outlined">assignment</span>
                        </span>
                        <div class="stat-number"><?php echo $examCount; ?></div>
                        <div class="stat-label">Examinations</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="background:rgba(79,70,229,0.1); color:var(--primary);">
                            <span class="material-symbols-outlined">rate_review</span>
                        </span>
                        <div class="stat-number" style="color:var(--primary);"><?php echo $evalCount; ?></div>
                        <div class="stat-label">Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="background:rgba(34,197,94,0.1); color:#22c55e;">
                            <span class="material-symbols-outlined">business_center</span>
                        </span>
                        <div class="stat-number" style="color:#22c55e;"><?php echo $assignmentCount; ?></div>
                        <div class="stat-label">Client Assignments</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="background:rgba(100,116,139,0.1); color:var(--slate-500);">
                            <span class="material-symbols-outlined">history</span>
                        </span>
                        <div class="stat-number" style="color:var(--slate-500);"><?php echo $deploymentArchiveCount; ?></div>
                        <div class="stat-label">Deployment Archive</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="archive-tabs">
                    <a href="?tab=examinations" class="archive-tab <?php echo $tab === 'examinations' ? 'active' : ''; ?>">
                        <span class="material-symbols-outlined" style="font-size:1rem;">assignment</span>
                        Examinations
                        <span class="tab-count"><?php echo $examCount; ?></span>
                    </a>
                    <a href="?tab=evaluations" class="archive-tab <?php echo $tab === 'evaluations' ? 'active' : ''; ?>">
                        <span class="material-symbols-outlined" style="font-size:1rem;">rate_review</span>
                        Evaluations
                        <span class="tab-count"><?php echo $evalCount; ?></span>
                    </a>
                    <a href="?tab=assignments" class="archive-tab <?php echo $tab === 'assignments' ? 'active' : ''; ?>">
                        <span class="material-symbols-outlined" style="font-size:1rem;">business_center</span>
                        Client Assignments
                        <span class="tab-count"><?php echo $assignmentCount; ?></span>
                    </a>
                    <a href="?tab=deployment_archive" class="archive-tab <?php echo $tab === 'deployment_archive' ? 'active' : ''; ?>">
                        <span class="material-symbols-outlined" style="font-size:1rem;">history</span>
                        Deployment Archive
                        <span class="tab-count"><?php echo $deploymentArchiveCount; ?></span>
                    </a>
                </div>

                <!-- Search Bar -->
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <span class="material-symbols-outlined">search</span>
                        <input type="text" id="searchInput" placeholder="Search by name, email, or job title..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button class="btn btn-primary" onclick="applySearch()">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="?tab=<?php echo $tab; ?>" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </div>

                <!-- =============================================
                TAB: EXAMINATIONS
                ============================================= -->
                <?php if ($tab === 'examinations'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">assignment</span>
                            Examination Records
                        </h3>
                        <span class="record-count"><?php echo count($examinations); ?> records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($examinations)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">description</span>
                                <h4>No Examination Records</h4>
                                <p>No examination records found. Start by conducting exams for applicants.</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>Job</th>
                                        <th>Exam Type</th>
                                        <th>Score</th>
                                        <th>Result</th>
                                        <th>Date</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($examinations as $exam): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($exam['first_name'] . ' ' . $exam['last_name']); ?></strong>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($exam['email']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($exam['job_title']); ?></td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?php echo ucfirst($exam['exam_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($exam['score'] !== null): ?>
                                                    <strong><?php echo $exam['score']; ?></strong> / <?php echo $exam['max_score']; ?>
                                                    <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                        <?php echo round($exam['percentage'], 1); ?>%
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:var(--text-on-surface-variant);">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $resultBadge = 'badge-pending';
                                                if ($exam['result'] === 'passed') $resultBadge = 'badge-passed';
                                                elseif ($exam['result'] === 'failed') $resultBadge = 'badge-failed';
                                                ?>
                                                <span class="badge <?php echo $resultBadge; ?>">
                                                    <?php echo ucfirst($exam['result'] ?? 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y', strtotime($exam['exam_date'])); ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-outline btn-sm" onclick="viewExamination(<?php echo $exam['id']; ?>)" title="View Details">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteExamination(<?php echo $exam['id']; ?>)" title="Delete Record">
                                                        <span class="material-symbols-outlined">delete</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- =============================================
                TAB: EVALUATIONS - UPDATED WITH AI QUESTIONS
                ============================================= -->
                <?php if ($tab === 'evaluations'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">rate_review</span>
                            Interview Evaluations
                            <span class="ai-badge" style="font-size:0.55rem; margin-left:0.5rem;">
                                <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                                AI
                            </span>
                        </h3>
                        <span class="record-count"><?php echo count($evaluations); ?> records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($evaluations)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">rate_review</span>
                                <h4>No Interview Evaluations</h4>
                                <p>No interview evaluations found. Complete interviews to generate evaluations.</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>Job</th>
                                        <th>Company</th>
                                        <th>Rating</th>
                                        <th>Recommendation</th>
                                        <th>Date</th>
                                        <th>AI Questions</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($evaluations as $eval): 
                                        $hasAIQuestions = !empty($eval['ai_questions']);
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($eval['first_name'] . ' ' . $eval['last_name']); ?></strong>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($eval['email']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($eval['job_title']); ?></td>
                                            <td><?php echo htmlspecialchars($eval['company_name']); ?></td>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:0.5rem;">
                                                    <span style="font-size:1.25rem; font-weight:700; color:var(--primary);">
                                                        <?php echo $eval['overall_rating']; ?>
                                                    </span>
                                                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">/ 5</span>
                                                    <div class="rating-stars">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <span class="<?php echo $i <= $eval['overall_rating'] ? 'filled' : 'empty'; ?>">★</span>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php 
                                                $recBadge = 'badge-consider';
                                                if ($eval['recommendation'] === 'hire') $recBadge = 'badge-hire';
                                                elseif ($eval['recommendation'] === 'reject') $recBadge = 'badge-reject';
                                                elseif ($eval['recommendation'] === 'hold') $recBadge = 'badge-hold';
                                                ?>
                                                <span class="badge <?php echo $recBadge; ?>">
                                                    <?php echo ucfirst($eval['recommendation'] ?? 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y', strtotime($eval['evaluation_date'])); ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <?php if ($hasAIQuestions): ?>
                                                    <span class="ai-badge" style="font-size:0.5rem;">
                                                        <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                                                        AI
                                                    </span>
                                                <?php else: ?>
                                                    <span style="font-size:0.65rem; color:var(--text-on-surface-variant);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-outline btn-sm" onclick="viewEvaluation(<?php echo $eval['id']; ?>)" title="View Details">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteEvaluation(<?php echo $eval['id']; ?>)" title="Delete Record">
                                                        <span class="material-symbols-outlined">delete</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- =============================================
                TAB: ASSIGNMENTS
                ============================================= -->
                <?php if ($tab === 'assignments'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">business_center</span>
                            Client Assignments
                        </h3>
                        <span class="record-count"><?php echo count($assignments); ?> records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assignments)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">business_center</span>
                                <h4>No Client Assignments</h4>
                                <p>No client assignments found. Assign applicants to clients for deployment.</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>Client</th>
                                        <th>Position</th>
                                        <th>Status</th>
                                        <th>Start Date</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($assignment['first_name'] . ' ' . $assignment['last_name']); ?></strong>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($assignment['email']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($assignment['company_name']); ?></td>
                                            <td><?php echo htmlspecialchars($assignment['position_title'] ?? $assignment['job_title']); ?></td>
                                            <td>
                                                <?php 
                                                $statusBadge = 'badge-active';
                                                if ($assignment['status'] === 'completed') $statusBadge = 'badge-completed';
                                                elseif ($assignment['status'] === 'terminated') $statusBadge = 'badge-terminated';
                                                elseif ($assignment['status'] === 'on_hold') $statusBadge = 'badge-on-hold';
                                                ?>
                                                <span class="badge <?php echo $statusBadge; ?>">
                                                    <?php echo ucfirst($assignment['status'] ?? 'Active'); ?>
                                                </span>
                                            </td>
                                            <td style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo $assignment['start_date'] ? date('M d, Y', strtotime($assignment['start_date'])) : 'N/A'; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-outline btn-sm" onclick="viewAssignment(<?php echo $assignment['id']; ?>)" title="View Details">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteAssignment(<?php echo $assignment['id']; ?>)" title="Delete Record">
                                                        <span class="material-symbols-outlined">delete</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- =============================================
                TAB: DEPLOYMENT ARCHIVE
                ============================================= -->
                <?php if ($tab === 'deployment_archive'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">history</span>
                            Deployment Archive
                        </h3>
                        <span class="record-count"><?php echo count($deploymentArchives); ?> records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($deploymentArchives)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">history</span>
                                <h4>No Deployment Archives</h4>
                                <p>No archived deployments found. Completed or terminated assignments will appear here.</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Applicant</th>
                                        <th>Client</th>
                                        <th>Position</th>
                                        <th>Status</th>
                                        <th>Archived Date</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deploymentArchives as $archive): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($archive['first_name'] . ' ' . $archive['last_name']); ?></strong>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($archive['email']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($archive['company_name']); ?></td>
                                            <td><?php echo htmlspecialchars($archive['position_title'] ?? $archive['job_title']); ?></td>
                                            <td>
                                                <span class="badge badge-archived">Archived</span>
                                            </td>
                                            <td style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                <?php echo date('M d, Y', strtotime($archive['archived_at'])); ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-outline btn-sm" onclick="viewDeploymentArchive(<?php echo $archive['id']; ?>)" title="View Details">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-success btn-sm" onclick="restoreDeployment(<?php echo $archive['id']; ?>)" title="Restore Deployment">
                                                        <span class="material-symbols-outlined">restore</span>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteDeploymentArchive(<?php echo $archive['id']; ?>)" title="Delete Permanently">
                                                        <span class="material-symbols-outlined">delete_forever</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- =============================================
    MODAL: VIEW DETAILS
    ============================================= -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">visibility</span>
                    <span id="modalTitle">Record Details</span>
                </h2>
                <button class="modal-close" onclick="closeModal('viewModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="loading-spinner" id="viewLoading">
                    <div style="text-align:center; padding:2rem;">
                        <div class="spinner"></div>
                        <p style="margin-top:0.75rem; color:var(--text-on-surface-variant);">Loading details...</p>
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
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mainWrapper = document.getElementById('mainWrapper');
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
        // 2. MOBILE SIDEBAR
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
        // 4. SEARCH
        // =============================================
        function applySearch() {
            const search = document.getElementById('searchInput').value;
            const url = new URL(window.location.href);
            if (search) {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search');
            }
            window.location.href = url.toString();
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applySearch();
            }
        });

        // =============================================
        // 5. MODAL FUNCTIONS
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

        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // =============================================
        // 6. VIEW EXAMINATION
        // =============================================
        function viewExamination(id) {
            openModal('viewModal');
            document.getElementById('modalTitle').textContent = 'Examination Details';
            document.getElementById('viewLoading').style.display = 'block';
            document.getElementById('viewContent').style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'view_examination');
            formData.append('id', id);

            fetch('archive.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';

                if (data.success) {
                    const exam = data.data;
                    document.getElementById('viewContent').innerHTML = `
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="label">Applicant</div>
                                <div class="value"><strong>${escapeHtml(exam.first_name)} ${escapeHtml(exam.last_name)}</strong></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Email</div>
                                <div class="value">${escapeHtml(exam.email)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Job Position</div>
                                <div class="value">${escapeHtml(exam.job_title)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Exam Type</div>
                                <div class="value"><span class="badge badge-info">${escapeHtml(exam.exam_type)}</span></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Exam Date</div>
                                <div class="value">${formatDate(exam.exam_date)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Result</div>
                                <div class="value">
                                    <span class="badge ${exam.result === 'passed' ? 'badge-passed' : exam.result === 'failed' ? 'badge-failed' : 'badge-pending'}">
                                        ${exam.result ? exam.result.toUpperCase() : 'PENDING'}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Score</div>
                                <div class="value">
                                    ${exam.score !== null ? exam.score : 'N/A'} / ${exam.max_score !== null ? exam.max_score : 'N/A'}
                                    ${exam.percentage !== null ? `<div style="font-size:0.8rem; color:var(--text-on-surface-variant);">${Math.round(exam.percentage)}%</div>` : ''}
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Evaluator</div>
                                <div class="value">${exam.evaluator_first_name ? escapeHtml(exam.evaluator_first_name + ' ' + exam.evaluator_last_name) : 'N/A'}</div>
                            </div>
                            ${exam.notes ? `
                            <div class="detail-item full-width">
                                <div class="label">Notes</div>
                                <div class="value">${escapeHtml(exam.notes)}</div>
                            </div>
                            ` : ''}
                            ${exam.details ? `
                            <div class="detail-item full-width">
                                <div class="label">Details</div>
                                <div class="value"><pre style="white-space:pre-wrap; font-family:inherit; margin:0;">${escapeHtml(exam.details)}</pre></div>
                            </div>
                            ` : ''}
                        </div>
                    `;
                } else {
                    document.getElementById('viewContent').innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${escapeHtml(data.error)}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';
                document.getElementById('viewContent').innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">Error loading details. Please try again.</p>
                    </div>
                `;
            });
        }

        // =============================================
        // 7. VIEW EVALUATION - UPDATED WITH AI QUESTIONS
        // =============================================
        function viewEvaluation(id) {
            openModal('viewModal');
            document.getElementById('modalTitle').textContent = 'Interview Evaluation Details';
            document.getElementById('viewLoading').style.display = 'block';
            document.getElementById('viewContent').style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'view_evaluation');
            formData.append('id', id);

            fetch('archive.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';

                if (data.success) {
                    const evalData = data.data;
                    
                    function renderStars(rating) {
                        let html = '';
                        for (let i = 1; i <= 5; i++) {
                            html += `<span class="${i <= rating ? 'filled' : 'empty'}">★</span>`;
                        }
                        return html;
                    }

                    // Build AI questions HTML if present
                    let aiQuestionsHtml = '';
                    if (evalData.ai_questions_decoded) {
                        const q = evalData.ai_questions_decoded;
                        let qHtml = '';
                        
                        if (q.technical && q.technical.length > 0) {
                            qHtml += `
                                <div class="question-category">Technical Questions</div>
                                ${q.technical.map((qText, idx) => `
                                    <div class="question-item">
                                        <span class="q-number">${idx + 1}.</span>
                                        ${escapeHtml(qText)}
                                    </div>
                                `).join('')}
                            `;
                        }
                        
                        if (q.behavioral && q.behavioral.length > 0) {
                            qHtml += `
                                <div class="question-category">Behavioral Questions</div>
                                ${q.behavioral.map((qText, idx) => `
                                    <div class="question-item">
                                        <span class="q-number">${idx + 1}.</span>
                                        ${escapeHtml(qText)}
                                    </div>
                                `).join('')}
                            `;
                        }
                        
                        if (q.role_specific && q.role_specific.length > 0) {
                            qHtml += `
                                <div class="question-category">Role Specific Questions</div>
                                ${q.role_specific.map((qText, idx) => `
                                    <div class="question-item">
                                        <span class="q-number">${idx + 1}.</span>
                                        ${escapeHtml(qText)}
                                    </div>
                                `).join('')}
                            `;
                        }
                        
                        if (qHtml) {
                            aiQuestionsHtml = `
                                <div class="detail-item full-width">
                                    <div class="label">
                                        <span class="ai-badge" style="font-size:0.55rem;">
                                            <span class="material-symbols-outlined" style="font-size:0.65rem;">auto_awesome</span>
                                            AI Generated Questions
                                        </span>
                                    </div>
                                    <div class="ai-questions-box">
                                        ${qHtml}
                                        <div style="font-size:0.625rem; color:var(--text-on-surface-variant); margin-top:0.5rem; padding-top:0.5rem; border-top:1px dashed var(--slate-200);">
                                            <span class="material-symbols-outlined" style="font-size:0.75rem; vertical-align:middle;">info</span>
                                            Questions generated by AI based on job requirements
                                        </div>
                                    </div>
                                </div>
                            `;
                        }
                    }

                    document.getElementById('viewContent').innerHTML = `
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="label">Applicant</div>
                                <div class="value"><strong>${escapeHtml(evalData.first_name)} ${escapeHtml(evalData.last_name)}</strong></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Email</div>
                                <div class="value">${escapeHtml(evalData.email)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Job Position</div>
                                <div class="value">${escapeHtml(evalData.job_title)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Company</div>
                                <div class="value">${escapeHtml(evalData.company_name)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Evaluation Date</div>
                                <div class="value">${formatDate(evalData.evaluation_date)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Interview Date</div>
                                <div class="value">${evalData.interview_date ? formatDate(evalData.interview_date) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Evaluator</div>
                                <div class="value">${escapeHtml(evalData.evaluator_first_name + ' ' + evalData.evaluator_last_name)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Recommendation</div>
                                <div class="value">
                                    <span class="badge ${evalData.recommendation === 'hire' ? 'badge-hire' : evalData.recommendation === 'reject' ? 'badge-reject' : evalData.recommendation === 'hold' ? 'badge-hold' : 'badge-consider'}">
                                        ${evalData.recommendation ? evalData.recommendation.toUpperCase() : 'PENDING'}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item full-width">
                                <div class="label">Ratings</div>
                                <div class="value" style="background:transparent; padding:0;">
                                    <table style="width:100%; min-width:auto; font-size:0.875rem;">
                                        <tr>
                                            <td style="padding:0.25rem 0; border:none;">Overall Rating</td>
                                            <td style="padding:0.25rem 0; border:none; text-align:right;">${renderStars(evalData.overall_rating || 0)}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:0.25rem 0; border:none;">Communication</td>
                                            <td style="padding:0.25rem 0; border:none; text-align:right;">${renderStars(evalData.communication_rating || 0)}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:0.25rem 0; border:none;">Technical Skills</td>
                                            <td style="padding:0.25rem 0; border:none; text-align:right;">${renderStars(evalData.technical_skills_rating || 0)}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:0.25rem 0; border:none;">Problem Solving</td>
                                            <td style="padding:0.25rem 0; border:none; text-align:right;">${renderStars(evalData.problem_solving_rating || 0)}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:0.25rem 0; border:none;">Cultural Fit</td>
                                            <td style="padding:0.25rem 0; border:none; text-align:right;">${renderStars(evalData.cultural_fit_rating || 0)}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:0.25rem 0; border:none;">Leadership Potential</td>
                                            <td style="padding:0.25rem 0; border:none; text-align:right;">${renderStars(evalData.leadership_potential || 0)}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            ${evalData.strengths ? `
                            <div class="detail-item full-width">
                                <div class="label">Strengths</div>
                                <div class="value">${escapeHtml(evalData.strengths)}</div>
                            </div>
                            ` : ''}
                            ${evalData.weaknesses ? `
                            <div class="detail-item full-width">
                                <div class="label">Weaknesses</div>
                                <div class="value">${escapeHtml(evalData.weaknesses)}</div>
                            </div>
                            ` : ''}
                            ${evalData.comments ? `
                            <div class="detail-item full-width">
                                <div class="label">Comments</div>
                                <div class="value">${escapeHtml(evalData.comments)}</div>
                            </div>
                            ` : ''}
                            ${evalData.interview_feedback ? `
                            <div class="detail-item full-width">
                                <div class="label">Interview Feedback</div>
                                <div class="value">${escapeHtml(evalData.interview_feedback)}</div>
                            </div>
                            ` : ''}
                            ${aiQuestionsHtml}
                        </div>
                    `;
                } else {
                    document.getElementById('viewContent').innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${escapeHtml(data.error)}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';
                document.getElementById('viewContent').innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">Error loading details. Please try again.</p>
                    </div>
                `;
            });
        }

        // =============================================
        // 8. VIEW ASSIGNMENT
        // =============================================
        function viewAssignment(id) {
            openModal('viewModal');
            document.getElementById('modalTitle').textContent = 'Client Assignment Details';
            document.getElementById('viewLoading').style.display = 'block';
            document.getElementById('viewContent').style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'view_assignment');
            formData.append('id', id);

            fetch('archive.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';

                if (data.success) {
                    const assignment = data.data;
                    
                    document.getElementById('viewContent').innerHTML = `
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="label">Applicant</div>
                                <div class="value"><strong>${escapeHtml(assignment.first_name)} ${escapeHtml(assignment.last_name)}</strong></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Email</div>
                                <div class="value">${escapeHtml(assignment.email)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Client</div>
                                <div class="value"><strong>${escapeHtml(assignment.company_name)}</strong></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Position</div>
                                <div class="value">${escapeHtml(assignment.position_title || assignment.job_title)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Status</div>
                                <div class="value">
                                    <span class="badge ${assignment.status === 'active' ? 'badge-active' : assignment.status === 'completed' ? 'badge-completed' : assignment.status === 'terminated' ? 'badge-terminated' : 'badge-on-hold'}">
                                        ${assignment.status ? assignment.status.toUpperCase() : 'ACTIVE'}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Contract Type</div>
                                <div class="value">${escapeHtml(assignment.contract_type || 'N/A')}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Start Date</div>
                                <div class="value">${assignment.start_date ? formatDate(assignment.start_date) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">End Date</div>
                                <div class="value">${assignment.end_date ? formatDate(assignment.end_date) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Salary</div>
                                <div class="value">${assignment.salary ? '₱' + Number(assignment.salary).toLocaleString() : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Salary Type</div>
                                <div class="value">${escapeHtml(assignment.salary_type || 'N/A')}</div>
                            </div>
                            ${assignment.manager_first_name ? `
                            <div class="detail-item">
                                <div class="label">Manager</div>
                                <div class="value">${escapeHtml(assignment.manager_first_name + ' ' + assignment.manager_last_name)}</div>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <div class="label">Department</div>
                                <div class="value">${escapeHtml(assignment.department || 'N/A')}</div>
                            </div>
                            ${assignment.notes ? `
                            <div class="detail-item full-width">
                                <div class="label">Notes</div>
                                <div class="value">${escapeHtml(assignment.notes)}</div>
                            </div>
                            ` : ''}
                            ${assignment.termination_reason ? `
                            <div class="detail-item full-width">
                                <div class="label">Termination Reason</div>
                                <div class="value">${escapeHtml(assignment.termination_reason)}</div>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <div class="label">Created By</div>
                                <div class="value">${assignment.created_by_first_name ? escapeHtml(assignment.created_by_first_name + ' ' + assignment.created_by_last_name) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Created At</div>
                                <div class="value">${formatDate(assignment.created_at)}</div>
                            </div>
                        </div>
                    `;
                } else {
                    document.getElementById('viewContent').innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${escapeHtml(data.error)}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';
                document.getElementById('viewContent').innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">Error loading details. Please try again.</p>
                    </div>
                `;
            });
        }

        // =============================================
        // 9. VIEW DEPLOYMENT ARCHIVE
        // =============================================
        function viewDeploymentArchive(id) {
            openModal('viewModal');
            document.getElementById('modalTitle').textContent = 'Deployment Archive Details';
            document.getElementById('viewLoading').style.display = 'block';
            document.getElementById('viewContent').style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'view_deployment_archive');
            formData.append('id', id);

            fetch('archive.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';

                if (data.success) {
                    const archive = data.data;
                    
                    document.getElementById('viewContent').innerHTML = `
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="label">Applicant</div>
                                <div class="value"><strong>${escapeHtml(archive.first_name)} ${escapeHtml(archive.last_name)}</strong></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Email</div>
                                <div class="value">${escapeHtml(archive.email)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Client</div>
                                <div class="value"><strong>${escapeHtml(archive.company_name)}</strong></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Position</div>
                                <div class="value">${escapeHtml(archive.position_title || archive.job_title)}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Status</div>
                                <div class="value"><span class="badge badge-archived">Archived</span></div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Contract Type</div>
                                <div class="value">${escapeHtml(archive.contract_type || 'N/A')}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Start Date</div>
                                <div class="value">${archive.start_date ? formatDate(archive.start_date) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">End Date</div>
                                <div class="value">${archive.end_date ? formatDate(archive.end_date) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Salary</div>
                                <div class="value">${archive.salary ? '₱' + Number(archive.salary).toLocaleString() : 'N/A'}</div>
                            </div>
                            ${archive.manager_first_name ? `
                            <div class="detail-item">
                                <div class="label">Manager</div>
                                <div class="value">${escapeHtml(archive.manager_first_name + ' ' + archive.manager_last_name)}</div>
                            </div>
                            ` : ''}
                            ${archive.notes ? `
                            <div class="detail-item full-width">
                                <div class="label">Notes</div>
                                <div class="value">${escapeHtml(archive.notes)}</div>
                            </div>
                            ` : ''}
                            ${archive.termination_reason ? `
                            <div class="detail-item full-width">
                                <div class="label">Termination Reason</div>
                                <div class="value">${escapeHtml(archive.termination_reason)}</div>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <div class="label">Archived By</div>
                                <div class="value">${archive.archived_by_first_name ? escapeHtml(archive.archived_by_first_name + ' ' + archive.archived_by_last_name) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="label">Archived At</div>
                                <div class="value">${formatDate(archive.archived_at)}</div>
                            </div>
                            ${archive.archive_reason ? `
                            <div class="detail-item full-width">
                                <div class="label">Archive Reason</div>
                                <div class="value">${escapeHtml(archive.archive_reason)}</div>
                            </div>
                            ` : ''}
                        </div>
                    `;
                } else {
                    document.getElementById('viewContent').innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${escapeHtml(data.error)}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('viewLoading').style.display = 'none';
                document.getElementById('viewContent').style.display = 'block';
                document.getElementById('viewContent').innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">Error loading details. Please try again.</p>
                    </div>
                `;
            });
        }

        // =============================================
        // 10. DELETE FUNCTIONS
        // =============================================
        function deleteExamination(id) {
            showConfirmModal('Delete Examination Record', 'Are you sure you want to delete this examination record? This action cannot be undone.', function() {
                const formData = new FormData();
                formData.append('action', 'delete_examination');
                formData.append('id', id);

                fetch('archive.php', {
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
                        showToast(data.error, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error deleting record.', 'error');
                });
            });
        }

        function deleteEvaluation(id) {
            showConfirmModal('Delete Evaluation', 'Are you sure you want to delete this interview evaluation? This action cannot be undone.', function() {
                const formData = new FormData();
                formData.append('action', 'delete_evaluation');
                formData.append('id', id);

                fetch('archive.php', {
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
                        showToast(data.error, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error deleting record.', 'error');
                });
            });
        }

        function deleteAssignment(id) {
            showConfirmModal('Delete Assignment', 'Are you sure you want to delete this client assignment? This action cannot be undone.', function() {
                const formData = new FormData();
                formData.append('action', 'delete_assignment');
                formData.append('id', id);

                fetch('archive.php', {
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
                        showToast(data.error, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error deleting record.', 'error');
                });
            });
        }

        function deleteDeploymentArchive(id) {
            showConfirmModal('Delete Permanently', 'Are you sure you want to permanently delete this deployment archive record? This action cannot be undone.', function() {
                const formData = new FormData();
                formData.append('action', 'delete_deployment_archive');
                formData.append('id', id);

                fetch('archive.php', {
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
                        showToast(data.error, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error deleting record.', 'error');
                });
            });
        }

        // =============================================
        // 11. RESTORE DEPLOYMENT
        // =============================================
        function restoreDeployment(id) {
            showConfirmModal('Restore Deployment', 'Restore this deployment from archive? The record will be moved back to active assignments.', function() {
                const formData = new FormData();
                formData.append('action', 'restore_deployment');
                formData.append('id', id);

                fetch('archive.php', {
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
                        showToast(data.error, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error restoring deployment.', 'error');
                });
            });
        }

        // =============================================
        // 12. CONFIRM MODAL (Replaces alerts)
        // =============================================
        function showConfirmModal(title, message, onConfirm) {
            // Create modal dynamically if it doesn't exist
            let confirmModal = document.getElementById('confirmModal');
            if (!confirmModal) {
                confirmModal = document.createElement('div');
                confirmModal.id = 'confirmModal';
                confirmModal.className = 'modal-overlay';
                confirmModal.innerHTML = `
                    <div class="modal" style="max-width: 32rem;">
                        <div class="modal-header">
                            <h2>
                                <span class="material-symbols-outlined">warning</span>
                                <span id="confirmModalTitle">Confirm</span>
                            </h2>
                            <button class="modal-close" onclick="closeModal('confirmModal')">
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p id="confirmModalMessage" style="font-size: 0.9375rem; color: var(--text-on-surface);"></p>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button>
                            <button class="btn btn-danger" id="confirmModalBtn">
                                <span class="material-symbols-outlined">check</span>
                                Confirm
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(confirmModal);
                
                // Close on overlay click
                confirmModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal('confirmModal');
                    }
                });
                
                // Close on Escape
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && confirmModal.classList.contains('active')) {
                        closeModal('confirmModal');
                    }
                });
            }
            
            document.getElementById('confirmModalTitle').textContent = title || 'Confirm';
            document.getElementById('confirmModalMessage').textContent = message || 'Are you sure?';
            
            // Remove old event listener
            const btn = document.getElementById('confirmModalBtn');
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            newBtn.addEventListener('click', function() {
                closeModal('confirmModal');
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            });
            
            openModal('confirmModal');
        }

        // =============================================
        // 13. UTILITY FUNCTIONS
        // =============================================
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(date) {
            if (!date) return 'N/A';
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // =============================================
        // 14. TOAST SYSTEM
        // =============================================
        function showToast(message, type) {
            type = type || 'info';
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        // =============================================
        // 15. RESPONSIVE HANDLING
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
        // 16. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
                closeModal('viewModal');
                closeModal('confirmModal');
            }
        });

        console.log('ISMERS Archive Management loaded successfully.');
        console.log('Interview evaluations with AI questions are now archived.');
    </script>

</body>
</html>