<?php
// app/config.php - Main Configuration File

// =============================================
// DATABASE CONFIGURATION
// =============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'core1_db');

// =============================================
// APPLICATION CONFIGURATION
// =============================================
define('SITE_NAME', 'ISMERS');
define('SITE_URL', 'http://localhost/CT1/');
define('APP_TIMEZONE', 'Asia/Manila');
define('SESSION_TIMEOUT', 3600); // 1 hour

// =============================================
// SECURITY CONFIGURATION
// =============================================
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 16);
define('FACE_SCAN_CONFIDENCE_THRESHOLD', 0.85);
define('MAX_LOGIN_ATTEMPTS', 5);

// =============================================
// FILE UPLOAD CONFIGURATION
// =============================================
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

// =============================================
// CREATE DATABASE CONNECTION
// =============================================
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to UTF-8
mysqli_set_charset($conn, "utf8mb4");

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// =============================================
// DATABASE HELPER FUNCTIONS
// =============================================

/**
 * Execute a prepared statement query
 */
function executeQuery($sql, $params = [], $types = "") {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        die("Query preparation failed: " . mysqli_error($conn));
    }
    
    if (!empty($params)) {
        if (empty($types)) {
            $types = str_repeat("s", count($params));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    return $result;
}

/**
 * Get a single record
 */
function getRecord($sql, $params = [], $types = "") {
    $result = executeQuery($sql, $params, $types);
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

/**
 * Get multiple records
 */
function getRecords($sql, $params = [], $types = "") {
    $result = executeQuery($sql, $params, $types);
    $records = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $records[] = $row;
        }
    }
    return $records;
}

/**
 * Insert a record and return the ID
 */
function insertRecord($sql, $params = [], $types = "") {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        die("Query preparation failed: " . mysqli_error($conn));
    }
    
    if (!empty($params)) {
        if (empty($types)) {
            $types = str_repeat("s", count($params));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

/**
 * Update a record
 */
function updateRecord($sql, $params = [], $types = "") {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        die("Query preparation failed: " . mysqli_error($conn));
    }
    
    if (!empty($params)) {
        if (empty($types)) {
            $types = str_repeat("s", count($params));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    $result = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected > 0;
}

/**
 * Delete a record
 */
function deleteRecord($sql, $params = [], $types = "") {
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        die("Query preparation failed: " . mysqli_error($conn));
    }
    
    if (!empty($params)) {
        if (empty($types)) {
            $types = str_repeat("s", count($params));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    $result = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected > 0;
}

/**
 * Check if a record exists
 */
function recordExists($table, $field, $value, $type = "s") {
    $sql = "SELECT COUNT(*) as count FROM $table WHERE $field = ?";
    $result = getRecord($sql, [$value], $type);
    return $result && $result['count'] > 0;
}

/**
 * Escape a string for safe SQL
 */
function escapeString($str) {
    global $conn;
    return mysqli_real_escape_string($conn, $str);
}

/**
 * Get the last inserted ID
 */
function getLastInsertId() {
    global $conn;
    return mysqli_insert_id($conn);
}

/**
 * Get the number of rows affected by the last query
 */
function getAffectedRows() {
    global $conn;
    return mysqli_affected_rows($conn);
}

/**
 * Begin a transaction
 */
function beginTransaction() {
    global $conn;
    return mysqli_begin_transaction($conn);
}

/**
 * Commit a transaction
 */
function commitTransaction() {
    global $conn;
    return mysqli_commit($conn);
}

/**
 * Rollback a transaction
 */
function rollbackTransaction() {
    global $conn;
    return mysqli_rollback($conn);
}

// =============================================
// USER FUNCTIONS
// =============================================

/**
 * Get user by ID
 */
function getUserById($userId) {
    return getRecord("SELECT * FROM users WHERE id = ?", [$userId], "i");
}

/**
 * Get user by email
 */
function getUserByEmail($email) {
    return getRecord("SELECT * FROM users WHERE email = ?", [$email], "s");
}

/**
 * Get user by email with role
 */
function getUserByEmailAndRole($email, $role) {
    return getRecord("SELECT * FROM users WHERE email = ? AND role = ?", [$email, $role], "ss");
}

/**
 * Create a new user
 */
function createUser($data) {
    $sql = "INSERT INTO users (email, password_hash, role, full_name, first_name, last_name, 
            middle_initial, suffix, gender, birth_date, place_of_birth, region, city) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    return insertRecord($sql, [
        $data['email'],
        $data['password_hash'],
        $data['role'] ?? 'applicant',
        $data['full_name'],
        $data['first_name'] ?? '',
        $data['last_name'] ?? '',
        $data['middle_initial'] ?? '',
        $data['suffix'] ?? '',
        $data['gender'] ?? '',
        $data['birth_date'] ?? null,
        $data['place_of_birth'] ?? '',
        $data['region'] ?? '',
        $data['city'] ?? ''
    ], "sssssssssssss");
}

/**
 * Update user last login
 */
function updateLastLogin($userId) {
    $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
    return updateRecord($sql, [$userId], "i");
}

/**
 * Update user profile
 */
function updateUser($userId, $data) {
    $fields = [];
    $params = [];
    $types = "";
    
    foreach ($data as $key => $value) {
        if ($key !== 'id' && $key !== 'password_hash') {
            $fields[] = "$key = ?";
            $params[] = $value;
            $types .= "s";
        }
    }
    
    if (isset($data['password_hash'])) {
        $fields[] = "password_hash = ?";
        $params[] = $data['password_hash'];
        $types .= "s";
    }
    
    $params[] = $userId;
    $types .= "i";
    
    $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
    return updateRecord($sql, $params, $types);
}

// =============================================
// APPLICANT FUNCTIONS
// =============================================

/**
 * Create an applicant profile
 */
function createApplicant($userId, $data) {
    $sql = "INSERT INTO applicants (user_id, phone, address, skills, experience, education) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    return insertRecord($sql, [
        $userId,
        $data['phone'] ?? '',
        $data['address'] ?? '',
        $data['skills'] ?? '',
        $data['experience'] ?? '',
        $data['education'] ?? ''
    ], "isssss");
}

/**
 * Get applicant by user ID
 */
function getApplicantByUserId($userId) {
    return getRecord("SELECT * FROM applicants WHERE user_id = ?", [$userId], "i");
}

/**
 * Get applicant by ID
 */
function getApplicantById($applicantId) {
    return getRecord("SELECT * FROM applicants WHERE id = ?", [$applicantId], "i");
}

/**
 * Update applicant profile
 */
function updateApplicant($applicantId, $data) {
    $fields = [];
    $params = [];
    $types = "";
    
    foreach ($data as $key => $value) {
        if ($key !== 'id' && $key !== 'user_id') {
            $fields[] = "$key = ?";
            $params[] = $value;
            $types .= "s";
        }
    }
    
    $params[] = $applicantId;
    $types .= "i";
    
    $sql = "UPDATE applicants SET " . implode(", ", $fields) . " WHERE id = ?";
    return updateRecord($sql, $params, $types);
}

/**
 * Save applicant interests
 */
function saveApplicantInterests($applicantId, $interests) {
    // Delete existing interests first
    deleteRecord("DELETE FROM applicant_interests WHERE applicant_id = ?", [$applicantId], "i");
    
    // Insert new interests
    $sql = "INSERT INTO applicant_interests (applicant_id, interest) VALUES (?, ?)";
    $success = true;
    foreach ($interests as $interest) {
        $result = insertRecord($sql, [$applicantId, trim($interest)], "is");
        if (!$result) $success = false;
    }
    return $success;
}

/**
 * Get applicant interests
 */
function getApplicantInterests($applicantId) {
    $sql = "SELECT interest FROM applicant_interests WHERE applicant_id = ?";
    $result = getRecords($sql, [$applicantId], "i");
    return array_column($result, 'interest');
}

// =============================================
// SESSION FUNCTIONS
// =============================================

/**
 * Create a session token
 */
function createSession($userId, $token, $expiresAt) {
    $sql = "INSERT INTO sessions (user_id, session_token, expires_at) VALUES (?, ?, ?)";
    return insertRecord($sql, [$userId, $token, $expiresAt], "iss");
}

/**
 * Validate a session token
 */
function validateSession($token) {
    $sql = "SELECT * FROM sessions WHERE session_token = ? AND expires_at > NOW()";
    return getRecord($sql, [$token], "s");
}

/**
 * Delete expired sessions
 */
function cleanExpiredSessions() {
    $sql = "DELETE FROM sessions WHERE expires_at < NOW()";
    return deleteRecord($sql);
}

/**
 * Delete user sessions
 */
function deleteUserSessions($userId) {
    $sql = "DELETE FROM sessions WHERE user_id = ?";
    return deleteRecord($sql, [$userId], "i");
}

// =============================================
// FACE LOG FUNCTIONS
// =============================================

/**
 * Log a face scan event
 */
function logFaceScan($userId, $actionType, $confidence, $status, $imagePath = null) {
    $sql = "INSERT INTO face_logs (user_id, action_type, image_path, confidence_score, status) 
            VALUES (?, ?, ?, ?, ?)";
    return insertRecord($sql, [$userId, $actionType, $imagePath, $confidence, $status], "issss");
}

/**
 * Get face scan logs for a user
 */
function getFaceLogsByUserId($userId, $limit = 50) {
    $sql = "SELECT * FROM face_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
    return getRecords($sql, [$userId, $limit], "ii");
}

// =============================================
// ATTENDANCE FUNCTIONS
// =============================================

/**
 * Log attendance check-in
 */
function logAttendanceCheckIn($deploymentId, $userId, $faceScore, $selfiePath = null) {
    $sql = "INSERT INTO attendance (deployment_id, user_id, check_in_time, face_match_score, is_face_verified, selfie_path) 
            VALUES (?, ?, NOW(), ?, 1, ?)";
    return insertRecord($sql, [$deploymentId, $userId, $faceScore, $selfiePath], "iiss");
}

/**
 * Log attendance check-out
 */
function logAttendanceCheckOut($userId) {
    $sql = "UPDATE attendance 
            SET check_out_time = NOW() 
            WHERE user_id = ? AND check_out_time IS NULL 
            ORDER BY id DESC LIMIT 1";
    return updateRecord($sql, [$userId], "i");
}

/**
 * Get attendance records for a user
 */
function getAttendanceByUserId($userId, $date = null) {
    if ($date) {
        $sql = "SELECT * FROM attendance WHERE user_id = ? AND DATE(check_in_time) = ? ORDER BY check_in_time DESC";
        return getRecords($sql, [$userId, $date], "is");
    }
    $sql = "SELECT * FROM attendance WHERE user_id = ? ORDER BY check_in_time DESC";
    return getRecords($sql, [$userId], "i");
}

/**
 * Get today's attendance for a user
 */
function getTodayAttendance($userId) {
    $sql = "SELECT * FROM attendance WHERE user_id = ? AND DATE(check_in_time) = CURDATE() ORDER BY check_in_time DESC";
    return getRecords($sql, [$userId], "i");
}

// =============================================
// CLIENT FUNCTIONS
// =============================================

/**
 * Get client by user ID
 */
function getClientByUserId($userId) {
    return getRecord("SELECT * FROM clients WHERE user_id = ?", [$userId], "i");
}

/**
 * Get all active clients
 */
function getActiveClients() {
    $sql = "SELECT c.*, u.email, u.full_name FROM clients c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.is_active = 1";
    return getRecords($sql);
}

// =============================================
// JOB ORDER FUNCTIONS
// =============================================

/**
 * Get job orders by client
 */
function getJobOrdersByClient($clientId) {
    $sql = "SELECT * FROM job_orders WHERE client_id = ? ORDER BY created_at DESC";
    return getRecords($sql, [$clientId], "i");
}

/**
 * Get open job orders
 */
function getOpenJobOrders() {
    $sql = "SELECT jo.*, c.company_name FROM job_orders jo 
            JOIN clients c ON jo.client_id = c.id 
            WHERE jo.status IN ('open', 'ongoing') 
            ORDER BY jo.created_at DESC";
    return getRecords($sql);
}

// =============================================
// APPLICATION FUNCTIONS
// =============================================

/**
 * Get applications by applicant
 */
function getApplicationsByApplicant($applicantId) {
    $sql = "SELECT a.*, jo.title, c.company_name 
            FROM applications a 
            JOIN job_orders jo ON a.job_order_id = jo.id 
            JOIN clients c ON jo.client_id = c.id 
            WHERE a.applicant_id = ? 
            ORDER BY a.applied_at DESC";
    return getRecords($sql, [$applicantId], "i");
}

// =============================================
// SYSTEM FUNCTIONS
// =============================================

/**
 * Get system setting
 */
function getSetting($key) {
    $record = getRecord("SELECT setting_value FROM settings WHERE setting_key = ?", [$key], "s");
    return $record ? $record['setting_value'] : null;
}

/**
 * Update system setting
 */
function updateSetting($key, $value) {
    $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
    return updateRecord($sql, [$value, $key], "ss");
}

/**
 * Log system activity
 */
function logActivity($userId, $action, $entityType = null, $entityId = null, $details = null) {
    $sql = "INSERT INTO system_logs (user_id, action, entity_type, entity_id, details) 
            VALUES (?, ?, ?, ?, ?)";
    return insertRecord($sql, [$userId, $action, $entityType, $entityId, $details], "issis");
}

// =============================================
// SANITIZATION FUNCTIONS
// =============================================

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Philippines)
 */
function validatePhoneNumber($phone) {
    return preg_match('/^(\+63|0)[0-9]{10}$/', $phone);
}

/**
 * Generate a random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a random password
 */
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle($chars), 0, $length);
}

// =============================================
// REDIRECTION FUNCTIONS
// =============================================

/**
 * Redirect to a URL
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Redirect back to previous page
 */
function redirectBack() {
    if (isset($_SERVER['HTTP_REFERER'])) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
    } else {
        header("Location: /ismers/");
    }
    exit;
}

// =============================================
// FLASH MESSAGE FUNCTIONS
// =============================================

/**
 * Set a flash message
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Display flash message
 */
function displayFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        $class = $flash['type'] === 'error' ? 'alert-danger' : 'alert-success';
        echo '<div class="alert ' . $class . '">' . htmlspecialchars($flash['message']) . '</div>';
    }
}

// =============================================
// CHECK IF CONNECTION IS WORKING
// =============================================
// Comment this out in production
// echo "✅ Database connection established successfully!";


// =============================================
// HR FUNCTIONS
// =============================================

/**
 * Get HR stats
 */
function getHRStats($hrId = null) {
    global $conn;
    
    $stats = [
        'total_jobs' => 0,
        'active_jobs' => 0,
        'total_applications' => 0,
        'pending_applications' => 0,
        'total_applicants' => 0,
        'upcoming_interviews' => 0
    ];
    
    // Total jobs
    $sql = "SELECT COUNT(*) as count FROM job_orders";
    if ($hrId) {
        $sql .= " WHERE created_by = $hrId";
    }
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    $stats['total_jobs'] = $row['count'] ?? 0;
    
    // Active jobs
    $sql = "SELECT COUNT(*) as count FROM job_orders WHERE status IN ('open', 'ongoing')";
    if ($hrId) {
        $sql .= " AND created_by = $hrId";
    }
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    $stats['active_jobs'] = $row['count'] ?? 0;
    
    // Total applications
    $sql = "SELECT COUNT(*) as count FROM applications a 
            JOIN job_orders jo ON a.job_order_id = jo.id";
    if ($hrId) {
        $sql .= " WHERE jo.created_by = $hrId";
    }
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    $stats['total_applications'] = $row['count'] ?? 0;
    
    // Pending applications
    $sql = "SELECT COUNT(*) as count FROM applications a 
            JOIN job_orders jo ON a.job_order_id = jo.id 
            WHERE a.status = 'pending'";
    if ($hrId) {
        $sql .= " AND jo.created_by = $hrId";
    }
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    $stats['pending_applications'] = $row['count'] ?? 0;
    
    // Total applicants
    $sql = "SELECT COUNT(DISTINCT a.applicant_id) as count FROM applications a 
            JOIN job_orders jo ON a.job_order_id = jo.id";
    if ($hrId) {
        $sql .= " WHERE jo.created_by = $hrId";
    }
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    $stats['total_applicants'] = $row['count'] ?? 0;
    
    // Upcoming interviews
    $sql = "SELECT COUNT(*) as count FROM interview_schedules 
            WHERE status = 'scheduled' AND scheduled_date > NOW()";
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    $stats['upcoming_interviews'] = $row['count'] ?? 0;
    
    return $stats;
}

/**
 * Get recent applications for HR
 */
function getRecentApplications($hrId = null, $limit = 10) {
    $sql = "SELECT a.*, u.first_name, u.last_name, u.email, 
                   jo.title as job_title, c.company_name,
                   ap.profile_picture
            FROM applications a
            JOIN applicants ap ON a.applicant_id = ap.id
            JOIN users u ON ap.user_id = u.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            JOIN clients c ON jo.client_id = c.id";
    
    if ($hrId) {
        $sql .= " WHERE jo.created_by = $hrId";
    }
    
    $sql .= " ORDER BY a.applied_at DESC LIMIT $limit";
    
    return getRecords($sql);
}

/**
 * Get active jobs
 */
function getActiveJobs($hrId = null) {
    $sql = "SELECT jo.*, c.company_name, 
            (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id) as application_count
            FROM job_orders jo
            JOIN clients c ON jo.client_id = c.id
            WHERE jo.status IN ('open', 'ongoing')";
    
    if ($hrId) {
        $sql .= " AND jo.created_by = $hrId";
    }
    
    $sql .= " ORDER BY jo.created_at DESC";
    
    return getRecords($sql);
}

/**
 * Update application status
 */
function updateApplicationStatus($applicationId, $status) {
    $sql = "UPDATE applications SET status = ? WHERE id = ?";
    return updateRecord($sql, [$status, $applicationId], "si");
}

/**
 * Schedule interview
 */
function scheduleInterview($data) {
    $sql = "INSERT INTO interview_schedules 
            (application_id, scheduled_date, duration, location, meeting_link, interviewer, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    return insertRecord($sql, [
        $data['application_id'],
        $data['scheduled_date'],
        $data['duration'] ?? 60,
        $data['location'] ?? '',
        $data['meeting_link'] ?? '',
        $data['interviewer'] ?? '',
        $data['notes'] ?? ''
    ], "isissis");
}

// =============================================
// EMAIL CONFIGURATION (PHPMailer)
// =============================================
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'calicaarvy13@gmail.com');
define('SMTP_PASS', 'cetc iywq dnpz wdub');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('MAIL_FROM', 'calicaarvy13@gmail.com');  // FIXED: Use your actual email
define('MAIL_FROM_NAME', 'ISMERS System');
define('MAIL_REPLY_TO', 'calicaarvy13@gmail.com');  // FIXED
define('MAIL_REPLY_TO_NAME', 'ISMERS Support');

?>