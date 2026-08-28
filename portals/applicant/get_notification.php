<?php
// portals/applicant/get_notifications.php - Get notifications via AJAX
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();
// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'applicant') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
// Get notifications
$notifications = getRecords("
    SELECT id, type, title, message, icon, is_read, created_at
    FROM notifications
    WHERE user_id = $1
    ORDER BY created_at DESC
    LIMIT 20
", [$userId]);

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
$unreadCount = getRecord("
    SELECT COUNT(*) as count FROM notifications
    WHERE user_id = $1 AND is_read = 0
", [$userId]);

echo json_encode([
    'success' => true,
    'notifications' => $notifications ?: [],
    'unread_count' => (int)($unreadCount['count'] ?? 0)
]);
exit;
?>