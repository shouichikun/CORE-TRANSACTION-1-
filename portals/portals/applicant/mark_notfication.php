<?php
// portals/applicant/check_notifications.php - Check for new notifications
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
// Get latest unread notifications
$latest = getRecord("
    SELECT id, type, title, message, icon, is_read, created_at
    FROM notifications
    WHERE user_id = $1 AND is_read = 0
    ORDER BY created_at DESC
    LIMIT 1
", [$userId]);

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
$unreadCount = getRecord("
    SELECT COUNT(*) as count FROM notifications
    WHERE user_id = $1 AND is_read = 0
", [$userId]);

// Get job title if it's an interview notification
if ($latest && $latest['type'] === 'interview_scheduled') {
    // Extract job title from message
    preg_match('/for (.+?) at/i', $latest['message'], $matches);
    $latest['job_title'] = $matches[1] ?? 'position';
}

echo json_encode([
    'success' => true,
    'has_new' => ((int)($unreadCount['count'] ?? 0)) > 0,
    'unread_count' => (int)($unreadCount['count'] ?? 0),
    'latest' => $latest
]);
exit;
?>