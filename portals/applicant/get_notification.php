<?php
// portals/applicant/get_notifications.php - Get notifications via AJAX
session_start();

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'applicant') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

// Get notifications
$notifications = getRecords("
    SELECT id, type, title, message, icon, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 20
", [$userId], "i");

$unreadCount = getRecord("
    SELECT COUNT(*) as count FROM notifications
    WHERE user_id = ? AND is_read = 0
", [$userId], "i");

echo json_encode([
    'success' => true,
    'notifications' => $notifications ?: [],
    'unread_count' => $unreadCount['count'] ?? 0
]);
exit;
?>