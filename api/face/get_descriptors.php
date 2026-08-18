<?php
// api/face/get_descriptors.php - Get face descriptors for login
session_start();
header('Content-Type: application/json');

require_once '../../app/config.php';

// Get user_id from request
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// If no user_id provided, get all users (for login page to match against)
$sql = "SELECT fs.user_id, fs.face_encoding, fs.image_path, fs.liveness_score,
               u.first_name, u.last_name, u.email
        FROM face_scans fs
        JOIN users u ON fs.user_id = u.id
        WHERE fs.is_active = 1";

$params = [];
$types = "";

if ($userId > 0) {
    $sql .= " AND fs.user_id = ?";
    $params[] = $userId;
    $types .= "i";
}

$sql .= " ORDER BY fs.created_at DESC";

$results = getRecords($sql, $params, $types);

if ($results) {
    $descriptors = [];
    foreach ($results as $row) {
        $descriptors[] = [
            'user_id' => $row['user_id'],
            'user_name' => $row['first_name'] . ' ' . $row['last_name'],
            'email' => $row['email'],
            'descriptor' => json_decode($row['face_encoding'], true),
            'liveness_score' => floatval($row['liveness_score'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($descriptors),
        'descriptors' => $descriptors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No face data found',
        'descriptors' => []
    ]);
}
?>