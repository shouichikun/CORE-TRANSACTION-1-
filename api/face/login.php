<?php
// /CT1/api/face/login.php - Face Login API
session_start();
header('Content-Type: application/json');

require_once '../../app/config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['user_id']) || !isset($data['match_score'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$userId = intval($data['user_id']);
$matchScore = floatval($data['match_score']);
$livenessScore = floatval($data['liveness_score'] ?? 0);
$snapshot = $data['snapshot'] ?? null;

// Verify user exists
$user = getRecord("SELECT id, first_name, last_name, role, is_active FROM users WHERE id = ?", [$userId], "i");

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

if ($user['is_active'] != 1) {
    echo json_encode(['success' => false, 'error' => 'Account is inactive']);
    exit;
}

// Check if match score meets threshold (65%)
if ($matchScore < 65) {
    // Log failed attempt
    $logSql = "INSERT INTO face_logs (user_id, action_type, status, confidence_score, liveness_score, ip_address, user_agent) 
               VALUES (?, 'login', 'failed', ?, ?, ?, ?)";
    insertRecord($logSql, [
        $userId,
        $matchScore,
        $livenessScore,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ], "iddss");
    
    echo json_encode([
        'success' => false,
        'error' => 'Face not recognized. Please try again.',
        'match_score' => $matchScore
    ]);
    exit;
}

// Login successful
$_SESSION['user_id'] = $user['id'];
$_SESSION['first_name'] = $user['first_name'];
$_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
$_SESSION['role'] = $user['role'];

// Update last activity
updateRecord("UPDATE users SET last_activity = NOW() WHERE id = ?", [$userId], "i");

// Log successful attempt
$logSql = "INSERT INTO face_logs (user_id, action_type, status, confidence_score, liveness_score, ip_address, user_agent) 
           VALUES (?, 'login', 'success', ?, ?, ?, ?)";
insertRecord($logSql, [
    $userId,
    $matchScore,
    $livenessScore,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
], "iddss");

// Log activity
logActivity($userId, 'Face Login', 'users', $userId, 'User logged in via face recognition');

// ✅ FIXED: Return redirect with /CT1/ prefix
$redirectPath = getRedirectUrl($user['role']);

echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'user' => [
        'id' => $user['id'],
        'name' => $user['first_name'] . ' ' . $user['last_name'],
        'role' => $user['role']
    ],
    'match_score' => $matchScore,
    'redirect' => $redirectPath  // This should now include /CT1/
]);

function getRedirectUrl($role) {
    // ✅ FIXED: All redirects now include /CT1/ prefix
    switch ($role) {
        case 'admin':
            return '/CT1/portals/admin/dashboard.php';
        case 'hr_manager':
        case 'recruiter':
            return '/CT1/portals/hr/dashboard.php';
        case 'client':
            return '/CT1/portals/client/dashboard.php';
        case 'employee':
            return '/CT1/portals/employee/dashboard.php';
        case 'applicant':
            return '/CT1/portals/applicant/dashboard.php';
        case 'supervisor':
            return '/CT1/portals/supervisor/dashboard.php';
        default:
            return '/CT1/index.php';
    }
}
?>