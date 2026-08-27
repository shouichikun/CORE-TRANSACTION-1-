<?php
// api/face/log_verification.php - Log face verification attempts
session_start();
header('Content-Type: application/json');

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['user_id']) || !isset($data['status'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$userId = intval($data['user_id']);
$status = $data['status'];
$confidenceScore = $data['confidence_score'] ?? 0;
$actionType = $data['action_type'] ?? 'verify';

// Log the verification - PostgreSQL uses $1, $2, etc.
$logSql = "INSERT INTO face_logs (user_id, action_type, status, confidence_score, ip_address, user_agent) 
           VALUES ($1, $2, $3, $4, $5, $6)";
$result = insertRecord($logSql, [
    $userId,
    $actionType,
    $status,
    $confidenceScore,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
]);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Verification logged']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to log verification']);
}
?>