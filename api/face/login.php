<?php
// /api/face/login.php - Face Login API (PostgreSQL Fixed - NO CT1 FOLDER)
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

// Verify user exists - PostgreSQL uses $1 placeholder
$user = getRecord("SELECT id, first_name, last_name, role, is_active FROM users WHERE id = $1", [$userId]);

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// ✅ FIXED: PostgreSQL boolean handling
$isActive = false;

if (isset($user['is_active'])) {
    $val = $user['is_active'];
    
    if (is_bool($val)) {
        $isActive = $val === true;
    } else if (is_string($val)) {
        $lower = strtolower($val);
        $isActive = ($lower === 't' || $lower === 'true' || $lower === '1');
    } else if (is_numeric($val)) {
        $isActive = intval($val) === 1;
    }
}

if (!$isActive) {
    echo json_encode([
        'success' => false, 
        'error' => 'Account is inactive',
        'debug' => [
            'is_active_value' => $user['is_active'],
            'is_active_type' => gettype($user['is_active'])
        ]
    ]);
    exit;
}

// Check if match score meets threshold (65%)
if ($matchScore < 65) {
    // Log failed attempt
    $logSql = "INSERT INTO face_logs (user_id, action_type, status, confidence_score, liveness_score, ip_address, user_agent) 
               VALUES ($1, 'login', 'failed', $2, $3, $4, $5)";
    insertRecord($logSql, [
        $userId,
        $matchScore,
        $livenessScore,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
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
updateRecord("UPDATE users SET last_activity = NOW() WHERE id = $1", [$userId]);

// Log successful attempt
$logSql = "INSERT INTO face_logs (user_id, action_type, status, confidence_score, liveness_score, ip_address, user_agent) 
           VALUES ($1, 'login', 'success', $2, $3, $4, $5)";
insertRecord($logSql, [
    $userId,
    $matchScore,
    $livenessScore,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
]);

// Log activity
if (function_exists('logActivity')) {
    logActivity($userId, 'Face Login', 'users', $userId, 'User logged in via face recognition');
}

// ✅ FIXED: Return redirect WITHOUT /CT1/ prefix
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
    'redirect' => $redirectPath
]);

// ✅ FIXED: getRedirectUrl - NO /CT1/ prefix
function getRedirectUrl($role) {
    switch ($role) {
        case 'admin':
            return '/portals/admin/dashboard.php';
        case 'hr_manager':
        case 'recruiter':
            return '/portals/hr/dashboard.php';
        case 'client':
            return '/portals/client/dashboard.php';
        case 'employee':
            return '/portals/employee/dashboard.php';
        case 'applicant':
            return '/portals/applicant/dashboard.php';
        case 'supervisor':
            return '/portals/supervisor/dashboard.php';
        default:
            return '/index.php';
    }
}
?>
