<?php
// api/online_status.php - Get online users status
session_start();

require_once '../app/config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get online users (last 5 minutes)
$onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));

// Get all users with their last activity
$users = getRecords("SELECT id, first_name, last_name, email, role, is_active, last_activity 
                     FROM users ORDER BY created_at DESC LIMIT 10");

$onlineUsers = [];

foreach ($users as $user) {
    $isOnline = !empty($user['last_activity']) && strtotime($user['last_activity']) > strtotime('-5 minutes');
    if ($isOnline) {
        $onlineUsers[] = $user['id'];
    }
}

// Get online count
$onlineCount = getRecord("SELECT COUNT(*) as count FROM users WHERE last_activity > ?", [$onlineThreshold], "s")['count'] ?? 0;

// Build response with all users
$allUsers = [];
foreach ($users as $user) {
    $isOnline = !empty($user['last_activity']) && strtotime($user['last_activity']) > strtotime('-5 minutes');
    $allUsers[] = [
        'id' => $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'is_active' => (bool)$user['is_active'],
        'is_online' => $isOnline,
        'last_activity' => $user['last_activity'] ? date('M d, Y h:i A', strtotime($user['last_activity'])) : 'Never logged in'
    ];
}

echo json_encode([
    'success' => true,
    'online_count' => $onlineCount,
    'users' => $allUsers
]);
?>