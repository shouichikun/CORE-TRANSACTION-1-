<?php
// portals/applicant/get_application.php - Fetch Application Details (AJAX)
session_start();

// Turn off error display for JSON responses
error_reporting(0);
ini_set('display_errors', 0);

require_once '../../app/config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'applicant') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$appId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($appId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid application ID']);
    exit;
}

// Get applicant ID
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

if ($applicantId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Applicant profile not found']);
    exit;
}

try {
    // Fetch application details with job, company, AND APPLICANT USER INFO
    global $conn;
    
    // FIXED: Added joins to get applicant's name from users table
    $sql = "SELECT 
                a.id, 
                a.cover_letter, 
                a.status, 
                a.applied_at, 
                a.updated_at, 
                a.resume_path,
                jo.title as job_title, 
                jo.description as job_description, 
                jo.location, 
                jo.job_type, 
                jo.salary_range,
                c.company_name,
                u.first_name,
                u.last_name,
                u.email
            FROM applications a
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            WHERE a.id = ? AND a.applicant_id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $appId, $applicantId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $application = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$application) {
        echo json_encode([
            'success' => false,
            'error' => 'Application not found or you do not have permission to view it.'
        ]);
        exit;
    }
    
    // Fetch feedback from system_logs
    $feedbackSql = "SELECT sl.*, u.first_name, u.last_name 
                    FROM system_logs sl
                    LEFT JOIN users u ON sl.user_id = u.id
                    WHERE sl.entity_type = 'applications' 
                    AND sl.entity_id = ? 
                    AND sl.action = 'Application Status Updated'
                    ORDER BY sl.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $feedbackSql);
    mysqli_stmt_bind_param($stmt, "i", $appId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $feedbackList = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $feedbackText = '';
        
        // Try multiple patterns
        if (preg_match('/Feedback:\s*(.+)$/i', $row['details'], $matches)) {
            $feedbackText = trim($matches[1]);
        } elseif (preg_match('/\|\s*Feedback:\s*(.+)$/i', $row['details'], $matches)) {
            $feedbackText = trim($matches[1]);
        } elseif (preg_match('/Feedback\s*[-:]\s*(.+)$/i', $row['details'], $matches)) {
            $feedbackText = trim($matches[1]);
        }
        
        if (!empty($feedbackText) && $feedbackText !== 'No feedback provided' && $feedbackText !== '') {
            $row['feedback'] = $feedbackText;
            $feedbackList[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    
    // If no feedback and status is pending, add pending message
    if (empty($feedbackList) && $application['status'] === 'pending') {
        $pendingMessage = [
            'id' => 0,
            'user_id' => null,
            'first_name' => 'System',
            'last_name' => '',
            'feedback' => 'Please wait for HR to review your application.',
            'created_at' => date('Y-m-d H:i:s'),
            'is_pending' => true
        ];
        $feedbackList[] = $pendingMessage;
    }
    
    // Build response
    $response = [
        'success' => true,
        'application' => $application,
        'feedback' => $feedbackList
    ];
    
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Output JSON
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
exit;
?>