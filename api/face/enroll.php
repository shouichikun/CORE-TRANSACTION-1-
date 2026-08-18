<?php
// api/face/enroll.php - Face Enrollment API
session_start();
header('Content-Type: application/json');

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['user_id']) || !isset($data['descriptor'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$userId = intval($data['user_id']);
$descriptor = json_encode($data['descriptor']);
$snapshot = $data['snapshot'] ?? null;
$expressions = json_encode($data['expressions'] ?? []);
$livenessScore = floatval($data['liveness_score'] ?? 0);

// Validate user exists
$user = getRecord("SELECT id, first_name, last_name FROM users WHERE id = ?", [$userId], "i");
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Check if face already exists for this user
$existing = getRecord("SELECT id FROM face_scans WHERE user_id = ?", [$userId], "i");

if ($existing) {
    // Update existing
    $sql = "UPDATE face_scans SET face_encoding = ?, image_path = ?, expressions = ?, liveness_score = ?, updated_at = NOW() WHERE user_id = ?";
    $result = updateRecord($sql, [$descriptor, $snapshot, $expressions, $livenessScore, $userId], "sssdi");
} else {
    // Insert new
    $sql = "INSERT INTO face_scans (user_id, face_encoding, image_path, expressions, liveness_score, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $result = insertRecord($sql, [$userId, $descriptor, $snapshot, $expressions, $livenessScore], "isssd");
}

if ($result) {
    // Log the enrollment
    $logSql = "INSERT INTO face_logs (user_id, action_type, status, confidence_score, liveness_score, ip_address, user_agent) 
               VALUES (?, 'enroll', 'success', ?, ?, ?, ?)";
    insertRecord($logSql, [
        $userId,
        100.00,
        $livenessScore,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ], "iddss");
    
    // Log activity
    logActivity($_SESSION['user_id'], 'Face Enrolled', 'face_scans', $userId, 'Face enrolled for user: ' . $user['first_name'] . ' ' . $user['last_name']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Face enrolled successfully',
        'user_id' => $userId,
        'user_name' => $user['first_name'] . ' ' . $user['last_name']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save face data'
    ]);
}
?>