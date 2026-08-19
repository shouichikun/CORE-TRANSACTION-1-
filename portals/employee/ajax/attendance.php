<?php
// portals/employee/ajax/attendance.php - Check In/Out Handler
session_start();
require_once '../../../app/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

// Check if attendance record exists for today
$existing = getRecord("
    SELECT * FROM attendance 
    WHERE user_id = ? AND DATE(check_in_time) = ?
", [$userId, $today], "is");

if ($action === 'check_in') {
    if ($existing && !empty($existing['check_in_time'])) {
        echo json_encode(['success' => false, 'error' => 'You already checked in today.']);
        exit;
    }
    
    $sql = "INSERT INTO attendance (user_id, check_in_time, created_at) 
            VALUES (?, ?, NOW())";
    $result = insertRecord($sql, [$userId, $now], "is");
    
    if ($result) {
        logActivity($userId, 'Checked In', 'attendance', $result, 'Checked in at ' . date('h:i A'));
        echo json_encode(['success' => true, 'time' => date('h:i A', strtotime($now))]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
    exit;
}

if ($action === 'check_out') {
    if (!$existing || empty($existing['check_in_time'])) {
        echo json_encode(['success' => false, 'error' => 'You must check in first.']);
        exit;
    }
    
    if (!empty($existing['check_out_time'])) {
        echo json_encode(['success' => false, 'error' => 'You already checked out today.']);
        exit;
    }
    
    $sql = "UPDATE attendance SET check_out_time = ?, updated_at = NOW() WHERE id = ?";
    $result = updateRecord($sql, [$now, $existing['id']], "si");
    
    if ($result) {
        logActivity($userId, 'Checked Out', 'attendance', $existing['id'], 'Checked out at ' . date('h:i A'));
        echo json_encode(['success' => true, 'time' => date('h:i A', strtotime($now))]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
?>