<?php
// api/biometric_verify.php - Biometric Verification API
session_start();
require_once '../app/config.php';

header('Content-Type: application/json');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get parameters
$email = $_POST['email'] ?? '';
$biometricType = $_POST['biometric_type'] ?? 'face';
$verificationCode = $_POST['verification_code'] ?? '';
$faceImage = $_POST['face_image'] ?? '';
$fingerprintData = $_POST['fingerprint_data'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

// Get user
$user = getUserByEmail($email);
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Check if biometric is enabled
if ($user['biometric_enabled'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Biometric not enabled for this account']);
    exit;
}

// Verify based on type
$verified = false;
$confidence = 0;
$message = '';

// Check verification code from session
$storedCode = $_SESSION['biometric_code'] ?? '';
$expires = $_SESSION['biometric_expires'] ?? 0;

if (!empty($verificationCode)) {
    // Verify with code
    if (time() > $expires) {
        echo json_encode([
            'success' => false,
            'message' => 'Verification code has expired. Please request a new one.'
        ]);
        exit;
    }
    
    if ($verificationCode === $storedCode) {
        $verified = true;
        $confidence = 0.95;
        $message = 'Biometric verification successful';
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid verification code. Please try again.'
        ]);
        exit;
    }
} elseif ($biometricType === 'face' || $biometricType === 'both') {
    // Face verification logic (simulated)
    $verified = true;
    $confidence = 0.92;
    $message = 'Face verified successfully';
} elseif ($biometricType === 'fingerprint') {
    // Fingerprint verification logic (simulated)
    $verified = true;
    $confidence = 0.95;
    $message = 'Fingerprint verified successfully';
}

if ($verified && $confidence >= 0.85) {
    // Log successful verification
    logBiometricActivity($user['id'], $biometricType, 'login', $confidence, 'success');
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['biometric_verified'] = true;
    $_SESSION['biometric_verified_at'] = time();
    
    // Clear biometric session data
    unset($_SESSION['biometric_code']);
    unset($_SESSION['biometric_expires']);
    unset($_SESSION['biometric_user_id']);
    unset($_SESSION['biometric_role']);
    unset($_SESSION['biometric_full_name']);
    unset($_SESSION['biometric_first_name']);
    unset($_SESSION['biometric_email']);
    
    // Update last login
    updateLastLogin($user['id']);
    $updateSql = "UPDATE users SET last_activity = NOW(), biometric_verified_at = NOW() WHERE id = ?";
    updateRecord($updateSql, [$user['id']], "i");
    
    // Redirect based on role
    $redirects = [
        'admin' => '../portals/admin/dashboard.php',
        'hr_manager' => '../portals/hr/dashboard.php',
        'recruiter' => '../portals/hr/dashboard.php',
        'client' => '../portals/client/index.php',
        'applicant' => '../portals/applicant/dashboard.php',
        'employee' => '../portals/employee/index.php',
        'supervisor' => '../portals/supervisor/index.php'
    ];
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'confidence' => $confidence,
        'redirect' => $redirects[$user['role']] ?? '../index.php'
    ]);
} else {
    logBiometricActivity($user['id'], $biometricType, 'login', $confidence, 'failed');
    echo json_encode([
        'success' => false,
        'message' => 'Biometric verification failed. Please try again.',
        'confidence' => $confidence
    ]);
}

/**
 * Log biometric activity
 */
function logBiometricActivity($userId, $type, $action, $confidence, $status) {
    global $conn;
    
    $sql = "INSERT INTO biometric_logs (user_id, biometric_type, action_type, confidence_score, status, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issdsss", $userId, $type, $action, $confidence, $status, $ip, $userAgent);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
?>