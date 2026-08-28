<?php
// =============================================
// START SESSION - MUST BE FIRST (no output before this)
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// app/config.php - Main Configuration File with Supabase PostgreSQL

// =============================================
// ERROR REPORTING
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// =============================================
// LOAD ENVIRONMENT VARIABLES FROM .env
// =============================================

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
                $value = substr($value, 1, -1);
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// =============================================
// SUPABASE POSTGRESQL DATABASE CONFIGURATION
// =============================================

define('PROJECT_REF', 'xpiylbzbkmymqigrvmgq');
define('DB_HOST', 'aws-0-ap-northeast-1.pooler.supabase.com');
define('DB_PORT', '6543');
define('DB_USER', 'postgres.xpiylbzbkmymqigrvmgq');
define('DB_PASS', 'CoreTransac1');
define('DB_NAME', 'postgres');

// Check if PostgreSQL extension is loaded
if (!function_exists('pg_connect')) {
    die("<h2>PostgreSQL extension not loaded!</h2>
         <p>Please enable pgsql in php.ini:</p>
         <ol>
             <li>Open <code>php.ini</code></li>
             <li>Find and uncomment: <code>extension=pgsql</code></li>
             <li>Find and uncomment: <code>extension=pdo_pgsql</code></li>
             <li>Restart your web server</li>
         </ol>");
}

// Try connection methods
$conn = null;

// Method 1: Transaction pooler (port 6543)
error_log("🔌 Attempting Supabase connection via Transaction Pooler...");
$conn = @pg_connect(sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s sslmode=require",
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
));

// Method 2: Direct connection (port 5432)
if (!$conn) {
    error_log("🔌 Transaction Pooler failed, trying Direct connection...");
    $conn = @pg_connect(sprintf(
        "host=db.%s.supabase.co port=5432 dbname=postgres user=postgres password=%s sslmode=require",
        PROJECT_REF, DB_PASS
    ));
}

// Method 3: Session pooler
if (!$conn) {
    error_log("🔌 Direct connection failed, trying Session Pooler...");
    $conn = @pg_connect(sprintf(
        "host=%s.pooler.supabase.com port=5432 dbname=postgres user=%s password=%s sslmode=require",
        PROJECT_REF, DB_USER, DB_PASS
    ));
}

// Check connection
if (!$conn) {
    $error = function_exists('pg_last_error') ? @pg_last_error() : 'Unknown error';
    error_log("❌ Supabase connection failed: " . $error);
    error_log("   Host: " . DB_HOST);
    error_log("   User: " . DB_USER);
    error_log("   Database: " . DB_NAME);
    // Don't die - let application handle gracefully
} else {
    error_log("✅ Supabase connected successfully!");
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Set PostgreSQL timezone to match PHP
if ($conn && function_exists('pg_query')) {
    @pg_query($conn, "SET timezone = 'Asia/Manila'");
}

// =============================================
// APPLICATION CONFIGURATION
// =============================================
define('SITE_NAME', 'ISMERS');
define('SITE_URL', 'https://core1.greatsolomonmpservices.com/');
define('APP_TIMEZONE', 'Asia/Manila');
define('SESSION_TIMEOUT', 3600);
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 16);
define('FACE_SCAN_CONFIDENCE_THRESHOLD', 0.85);
define('MAX_LOGIN_ATTEMPTS', 5);
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

// =============================================
// ✅ HELPER: PostgreSQL Boolean to PHP Boolean
// =============================================

if (!function_exists('pgBoolToPhp')) {
    /**
     * Convert PostgreSQL boolean to PHP boolean
     * Handles: true/false, 't'/'f', 'true'/'false', 1/0
     */
    function pgBoolToPhp($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return ($lower === 't' || $lower === 'true' || $lower === '1');
        }
        if (is_numeric($value)) {
            return intval($value) === 1;
        }
        return false;
    }
}

// =============================================
// ✅ DATABASE HELPER FUNCTIONS (PostgreSQL)
// =============================================

if (!function_exists('executeQuery')) {
    function executeQuery($sql, $params = []) {
        global $conn;
        if (!$conn) {
            error_log("⚠️ executeQuery: No database connection");
            return false;
        }
        if (!is_array($params)) {
            $params = [];
        }
        $result = @pg_query_params($conn, $sql, $params);
        if (!$result) {
            error_log("⚠️ Query failed: " . @pg_last_error($conn));
            error_log("   SQL: " . $sql);
            return false;
        }
        return $result;
    }
}

if (!function_exists('getRecord')) {
    function getRecord($sql, $params = []) {
        if (!is_array($params)) {
            $params = [];
        }
        $result = executeQuery($sql, $params);
        if ($result && @pg_num_rows($result) > 0) {
            $row = @pg_fetch_assoc($result);
            @pg_free_result($result);
            return $row;
        }
        if ($result) {
            @pg_free_result($result);
        }
        return null;
    }
}

if (!function_exists('getRecords')) {
    function getRecords($sql, $params = []) {
        if (!is_array($params)) {
            $params = [];
        }
        $result = executeQuery($sql, $params);
        $records = [];
        if ($result) {
            while ($row = @pg_fetch_assoc($result)) {
                $records[] = $row;
            }
            @pg_free_result($result);
        }
        return $records;
    }
}

if (!function_exists('insertRecord')) {
    function insertRecord($sql, $params = []) {
        global $conn;
        if (!is_array($params)) {
            $params = [];
        }
        $result = executeQuery($sql, $params);
        if (!$result) {
            return false;
        }
        
        $idResult = @pg_query($conn, "SELECT LASTVAL() as id");
        $id = false;
        if ($idResult) {
            $row = @pg_fetch_assoc($idResult);
            $id = $row['id'] ?? false;
            @pg_free_result($idResult);
        }
        @pg_free_result($result);
        return $id;
    }
}

if (!function_exists('updateRecord')) {
    function updateRecord($sql, $params = []) {
        if (!is_array($params)) {
            $params = [];
        }
        $result = executeQuery($sql, $params);
        if (!$result) {
            return false;
        }
        $affected = @pg_affected_rows($result);
        @pg_free_result($result);
        return $affected > 0;
    }
}

if (!function_exists('deleteRecord')) {
    function deleteRecord($sql, $params = []) {
        if (!is_array($params)) {
            $params = [];
        }
        $result = executeQuery($sql, $params);
        if (!$result) {
            return false;
        }
        $affected = @pg_affected_rows($result);
        @pg_free_result($result);
        return $affected > 0;
    }
}

if (!function_exists('getLastInsertId')) {
    function getLastInsertId() {
        global $conn;
        if (!$conn) return false;
        $result = @pg_query($conn, "SELECT LASTVAL() as id");
        if (!$result) {
            return false;
        }
        $row = @pg_fetch_assoc($result);
        @pg_free_result($result);
        return $row['id'] ?? false;
    }
}

if (!function_exists('beginTransaction')) {
    function beginTransaction() {
        global $conn;
        if (!$conn) return false;
        return @pg_query($conn, "BEGIN");
    }
}

if (!function_exists('commitTransaction')) {
    function commitTransaction() {
        global $conn;
        if (!$conn) return false;
        return @pg_query($conn, "COMMIT");
    }
}

if (!function_exists('rollbackTransaction')) {
    function rollbackTransaction() {
        global $conn;
        if (!$conn) return false;
        return @pg_query($conn, "ROLLBACK");
    }
}

if (!function_exists('escapeString')) {
    function escapeString($str) {
        global $conn;
        if (!$conn) return $str;
        return @pg_escape_string($conn, $str);
    }
}

if (!function_exists('recordExists')) {
    function recordExists($table, $field, $value) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || 
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return false;
        }
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $field = $1";
        $result = getRecord($sql, [$value]);
        return $result && isset($result['count']) && intval($result['count']) > 0;
    }
}

if (!function_exists('getAffectedRows')) {
    function getAffectedRows() {
        global $conn;
        if (!$conn) return 0;
        $rows = @pg_affected_rows($conn);
        return $rows !== false ? $rows : 0;
    }
}

// =============================================
// ✅ COMPLETE HR STATS FUNCTION
// =============================================

if (!function_exists('getHRStats')) {
    function getHRStats($hrId = null) {
        global $conn;
        
        // Default stats
        $stats = [
            'total_jobs' => 0,
            'active_jobs' => 0,
            'total_applications' => 0,
            'pending_applications' => 0,
            'total_applicants' => 0,
            'upcoming_interviews' => 0,
            'pending_review' => 0
        ];
        
        if (!$conn) {
            error_log("⚠️ getHRStats: No database connection, returning default stats");
            return $stats;
        }
        
        try {
            // =============================================
            // 1. JOB ORDERS TABLE
            // =============================================
            $checkJobOrders = @pg_query($conn, "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'job_orders')");
            if ($checkJobOrders) {
                $row = @pg_fetch_row($checkJobOrders);
                @pg_free_result($checkJobOrders);
                if ($row && $row[0] === 't') {
                    // Total jobs
                    $result = @pg_query($conn, "SELECT COUNT(*) as count FROM job_orders");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['total_jobs'] = (int)($data['count'] ?? 0);
                    }
                    
                    // Active jobs
                    $result = @pg_query($conn, "SELECT COUNT(*) as count FROM job_orders WHERE status IN ('open', 'ongoing')");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['active_jobs'] = (int)($data['count'] ?? 0);
                    }
                    
                    // Pending review jobs
                    $result = @pg_query($conn, "SELECT COUNT(*) as count FROM job_orders WHERE status = 'pending_review'");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['pending_review'] = (int)($data['count'] ?? 0);
                    }
                } else {
                    error_log("⚠️ Table 'job_orders' does not exist in public schema");
                }
            }
            
            // =============================================
            // 2. APPLICATIONS TABLE
            // =============================================
            $checkApplications = @pg_query($conn, "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'applications')");
            if ($checkApplications) {
                $row = @pg_fetch_row($checkApplications);
                @pg_free_result($checkApplications);
                if ($row && $row[0] === 't') {
                    // Total applications
                    $result = @pg_query($conn, "SELECT COUNT(*) as count FROM applications");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['total_applications'] = (int)($data['count'] ?? 0);
                    }
                    
                    // Pending applications
                    $result = @pg_query($conn, "SELECT COUNT(*) as count FROM applications WHERE status = 'pending'");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['pending_applications'] = (int)($data['count'] ?? 0);
                    }
                    
                    // Total applicants (distinct)
                    $result = @pg_query($conn, "SELECT COUNT(DISTINCT applicant_id) as count FROM applications");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['total_applicants'] = (int)($data['count'] ?? 0);
                    }
                } else {
                    error_log("⚠️ Table 'applications' does not exist in public schema");
                }
            }
            
            // =============================================
            // 3. INTERVIEW SCHEDULES TABLE
            // =============================================
            $checkInterviews = @pg_query($conn, "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'interview_schedules')");
            if ($checkInterviews) {
                $row = @pg_fetch_row($checkInterviews);
                @pg_free_result($checkInterviews);
                if ($row && $row[0] === 't') {
                    // Upcoming interviews
                    $result = @pg_query($conn, "SELECT COUNT(*) as count FROM interview_schedules WHERE status = 'scheduled' AND scheduled_date > NOW()");
                    if ($result) {
                        $data = @pg_fetch_assoc($result);
                        @pg_free_result($result);
                        $stats['upcoming_interviews'] = (int)($data['count'] ?? 0);
                    }
                } else {
                    error_log("⚠️ Table 'interview_schedules' does not exist in public schema");
                }
            }
            
        } catch (Exception $e) {
            error_log("getHRStats error: " . $e->getMessage());
        }
        
        error_log("📊 HR Stats: " . json_encode($stats));
        return $stats;
    }
}

// =============================================
// USER FUNCTIONS
// =============================================

if (!function_exists('getUserById')) {
    function getUserById($userId) {
        if (!$userId) return null;
        $result = getRecord("SELECT * FROM users WHERE id = $1", [$userId]);
        return $result;
    }
}

if (!function_exists('getUserByEmail')) {
    function getUserByEmail($email) {
        if (!$email) return null;
        return getRecord("SELECT * FROM users WHERE email = $1", [$email]);
    }
}

if (!function_exists('getUserByEmailAndRole')) {
    function getUserByEmailAndRole($email, $role) {
        if (!$email || !$role) return null;
        return getRecord("SELECT * FROM users WHERE email = $1 AND role = $2", [$email, $role]);
    }
}

if (!function_exists('createUser')) {
    function createUser($data) {
        if (!isset($data['email']) || !isset($data['password_hash'])) {
            return false;
        }
        $sql = "INSERT INTO users (email, password_hash, role, full_name, first_name, last_name, 
                middle_initial, suffix, gender, birth_date, place_of_birth, region, city) 
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)";
        return insertRecord($sql, [
            $data['email'],
            $data['password_hash'],
            $data['role'] ?? 'applicant',
            $data['full_name'] ?? '',
            $data['first_name'] ?? '',
            $data['last_name'] ?? '',
            $data['middle_initial'] ?? '',
            $data['suffix'] ?? '',
            $data['gender'] ?? '',
            $data['birth_date'] ?? null,
            $data['place_of_birth'] ?? '',
            $data['region'] ?? '',
            $data['city'] ?? ''
        ]);
    }
}

if (!function_exists('updateLastLogin')) {
    function updateLastLogin($userId) {
        if (!$userId) return false;
        $sql = "UPDATE users SET last_login = NOW() WHERE id = $1";
        return updateRecord($sql, [$userId]);
    }
}

if (!function_exists('updateUser')) {
    function updateUser($userId, $data) {
        if (!$userId || empty($data)) return false;
        $fields = [];
        $params = [];
        $counter = 1;
        foreach ($data as $key => $value) {
            if ($key !== 'id' && $key !== 'password_hash') {
                $fields[] = "$key = $" . $counter++;
                $params[] = $value;
            }
        }
        if (isset($data['password_hash'])) {
            $fields[] = "password_hash = $" . $counter++;
            $params[] = $data['password_hash'];
        }
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = $" . $counter;
        return updateRecord($sql, $params);
    }
}

// =============================================
// APPLICANT FUNCTIONS
// =============================================

if (!function_exists('createApplicant')) {
    function createApplicant($userId, $data) {
        if (!$userId) return false;
        $sql = "INSERT INTO applicants (user_id, phone, address, skills, experience, education) 
                VALUES ($1, $2, $3, $4, $5, $6)";
        return insertRecord($sql, [
            $userId,
            $data['phone'] ?? '',
            $data['address'] ?? '',
            $data['skills'] ?? '',
            $data['experience'] ?? '',
            $data['education'] ?? ''
        ]);
    }
}

if (!function_exists('getApplicantByUserId')) {
    function getApplicantByUserId($userId) {
        if (!$userId) return null;
        return getRecord("SELECT * FROM applicants WHERE user_id = $1", [$userId]);
    }
}

if (!function_exists('getApplicantById')) {
    function getApplicantById($applicantId) {
        if (!$applicantId) return null;
        return getRecord("SELECT * FROM applicants WHERE id = $1", [$applicantId]);
    }
}

if (!function_exists('updateApplicant')) {
    function updateApplicant($applicantId, $data) {
        if (!$applicantId || empty($data)) return false;
        $fields = [];
        $params = [];
        $counter = 1;
        foreach ($data as $key => $value) {
            if ($key !== 'id' && $key !== 'user_id') {
                $fields[] = "$key = $" . $counter++;
                $params[] = $value;
            }
        }
        $params[] = $applicantId;
        $sql = "UPDATE applicants SET " . implode(", ", $fields) . " WHERE id = $" . $counter;
        return updateRecord($sql, $params);
    }
}

if (!function_exists('saveApplicantInterests')) {
    function saveApplicantInterests($applicantId, $interests) {
        if (!$applicantId || empty($interests)) return false;
        deleteRecord("DELETE FROM applicant_interests WHERE applicant_id = $1", [$applicantId]);
        $sql = "INSERT INTO applicant_interests (applicant_id, interest) VALUES ($1, $2)";
        $success = true;
        foreach ($interests as $interest) {
            $result = insertRecord($sql, [$applicantId, trim($interest)]);
            if (!$result) $success = false;
        }
        return $success;
    }
}

if (!function_exists('getApplicantInterests')) {
    function getApplicantInterests($applicantId) {
        if (!$applicantId) return [];
        $sql = "SELECT interest FROM applicant_interests WHERE applicant_id = $1";
        $result = getRecords($sql, [$applicantId]);
        return array_column($result, 'interest');
    }
}

// =============================================
// SESSION FUNCTIONS
// =============================================

if (!function_exists('createSession')) {
    function createSession($userId, $token, $expiresAt) {
        if (!$userId || !$token || !$expiresAt) return false;
        $sql = "INSERT INTO sessions (user_id, session_token, expires_at) VALUES ($1, $2, $3)";
        return insertRecord($sql, [$userId, $token, $expiresAt]);
    }
}

if (!function_exists('validateSession')) {
    function validateSession($token) {
        if (!$token) return null;
        $sql = "SELECT * FROM sessions WHERE session_token = $1 AND expires_at > NOW()";
        return getRecord($sql, [$token]);
    }
}

if (!function_exists('cleanExpiredSessions')) {
    function cleanExpiredSessions() {
        $sql = "DELETE FROM sessions WHERE expires_at < NOW()";
        return deleteRecord($sql);
    }
}

if (!function_exists('deleteUserSessions')) {
    function deleteUserSessions($userId) {
        if (!$userId) return false;
        $sql = "DELETE FROM sessions WHERE user_id = $1";
        return deleteRecord($sql, [$userId]);
    }
}

// =============================================
// CLIENT FUNCTIONS
// =============================================

if (!function_exists('getClientByUserId')) {
    function getClientByUserId($userId) {
        if (!$userId) return null;
        return getRecord("SELECT * FROM clients WHERE user_id = $1", [$userId]);
    }
}

if (!function_exists('getActiveClients')) {
    function getActiveClients() {
        $sql = "SELECT c.*, u.email, u.full_name FROM clients c 
                JOIN users u ON c.user_id = u.id 
                WHERE c.is_active = 1";
        return getRecords($sql);
    }
}

// =============================================
// JOB ORDER FUNCTIONS
// =============================================

if (!function_exists('getJobOrdersByClient')) {
    function getJobOrdersByClient($clientId) {
        if (!$clientId) return [];
        $sql = "SELECT * FROM job_orders WHERE client_id = $1 ORDER BY created_at DESC";
        return getRecords($sql, [$clientId]);
    }
}

if (!function_exists('getOpenJobOrders')) {
    function getOpenJobOrders() {
        $sql = "SELECT jo.*, c.company_name FROM job_orders jo 
                JOIN clients c ON jo.client_id = c.id 
                WHERE jo.status IN ('open', 'ongoing') 
                ORDER BY jo.created_at DESC";
        return getRecords($sql);
    }
}

// =============================================
// APPLICATION FUNCTIONS
// =============================================

if (!function_exists('getApplicationsByApplicant')) {
    function getApplicationsByApplicant($applicantId) {
        if (!$applicantId) return [];
        $sql = "SELECT a.*, jo.title, c.company_name 
                FROM applications a 
                JOIN job_orders jo ON a.job_order_id = jo.id 
                JOIN clients c ON jo.client_id = c.id 
                WHERE a.applicant_id = $1 
                ORDER BY a.applied_at DESC";
        return getRecords($sql, [$applicantId]);
    }
}

// =============================================
// SYSTEM FUNCTIONS
// =============================================

if (!function_exists('getSetting')) {
    function getSetting($key) {
        if (!$key) return null;
        $record = getRecord("SELECT setting_value FROM settings WHERE setting_key = $1", [$key]);
        return $record ? $record['setting_value'] : null;
    }
}

if (!function_exists('updateSetting')) {
    function updateSetting($key, $value) {
        if (!$key) return false;
        $sql = "UPDATE settings SET setting_value = $1 WHERE setting_key = $2";
        return updateRecord($sql, [$value, $key]);
    }
}

if (!function_exists('logActivity')) {
    function logActivity($userId, $action, $entityType = null, $entityId = null, $details = null) {
        $sql = "INSERT INTO system_logs (user_id, action, entity_type, entity_id, details) 
                VALUES ($1, $2, $3, $4, $5)";
        return insertRecord($sql, [$userId, $action, $entityType, $entityId, $details]);
    }
}

// =============================================
// EMPLOYEE FUNCTIONS
// =============================================

if (!function_exists('getEmployeeByUserId')) {
    function getEmployeeByUserId($userId) {
        if (!$userId) return null;
        return getRecord("SELECT * FROM employees WHERE user_id = $1", [$userId]);
    }
}

if (!function_exists('getEmployeeById')) {
    function getEmployeeById($employeeId) {
        if (!$employeeId) return null;
        return getRecord("SELECT * FROM employees WHERE id = $1", [$employeeId]);
    }
}

if (!function_exists('getEmployees')) {
    function getEmployees($filters = []) {
        $sql = "SELECT e.*, u.email, u.role 
                FROM employees e 
                JOIN users u ON e.user_id = u.id";
        $conditions = [];
        $params = [];
        $counter = 1;
        
        if (!empty($filters['status'])) {
            $conditions[] = "e.status = $" . $counter++;
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['search'])) {
            $search = "%" . $filters['search'] . "%";
            $conditions[] = "(e.first_name LIKE $" . $counter . " OR e.last_name LIKE $" . ($counter+1) . " OR e.email LIKE $" . ($counter+2) . ")";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $counter += 3;
        }
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY e.created_at DESC";
        return getRecords($sql, $params);
    }
}

if (!function_exists('createEmployee')) {
    function createEmployee($userId, $data) {
        if (!$userId) return false;
        $applicationId = isset($data['application_id']) ? (int)$data['application_id'] : null;
        $firstName = $data['first_name'] ?? '';
        $lastName = $data['last_name'] ?? '';
        $email = $data['email'] ?? '';
        $phone = $data['phone'] ?? null;
        $position = $data['position'] ?? null;
        $department = $data['department'] ?? null;
        $hireDate = $data['hire_date'] ?? date('Y-m-d');
        $status = $data['status'] ?? 'active';
        
        $sql = "INSERT INTO employees (
            user_id, application_id, first_name, last_name, email, phone, 
            position, department, hire_date, status, created_at
        ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, NOW())";
        
        return insertRecord($sql, [
            $userId, $applicationId, $firstName, $lastName, $email, $phone,
            $position, $department, $hireDate, $status
        ]);
    }
}

if (!function_exists('updateEmployee')) {
    function updateEmployee($employeeId, $data) {
        if (!$employeeId || empty($data)) return false;
        $fields = [];
        $params = [];
        $counter = 1;
        $allowedFields = ['first_name', 'last_name', 'email', 'phone', 'position', 'department', 'status'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = $" . $counter++;
                $params[] = $data[$field] ?? '';
            }
        }
        if (empty($fields)) {
            return false;
        }
        $params[] = $employeeId;
        $sql = "UPDATE employees SET " . implode(", ", $fields) . " WHERE id = $" . $counter;
        return updateRecord($sql, $params);
    }
}

// =============================================
// RECENT APPLICATIONS & ACTIVE JOBS
// =============================================

if (!function_exists('getRecentApplications')) {
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
            $sql .= " WHERE jo.created_by = " . intval($hrId);
        }
        
        $sql .= " ORDER BY a.applied_at DESC LIMIT " . intval($limit);
        return getRecords($sql);
    }
}

if (!function_exists('getActiveJobs')) {
    function getActiveJobs($hrId = null) {
        $sql = "SELECT jo.*, c.company_name, 
                (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id) as application_count
                FROM job_orders jo
                JOIN clients c ON jo.client_id = c.id
                WHERE jo.status IN ('open', 'ongoing')";
        
        if ($hrId) {
            $sql .= " AND jo.created_by = " . intval($hrId);
        }
        
        $sql .= " ORDER BY jo.created_at DESC";
        return getRecords($sql);
    }
}

if (!function_exists('updateApplicationStatus')) {
    function updateApplicationStatus($applicationId, $status) {
        if (!$applicationId || !$status) return false;
        $sql = "UPDATE applications SET status = $1 WHERE id = $2";
        return updateRecord($sql, [$status, $applicationId]);
    }
}

if (!function_exists('scheduleInterview')) {
    function scheduleInterview($data) {
        if (empty($data['application_id']) || empty($data['scheduled_date'])) return false;
        $sql = "INSERT INTO interview_schedules 
                (application_id, scheduled_date, duration, location, meeting_link, interviewer, notes) 
                VALUES ($1, $2, $3, $4, $5, $6, $7)";
        return insertRecord($sql, [
            $data['application_id'],
            $data['scheduled_date'],
            $data['duration'] ?? 60,
            $data['location'] ?? '',
            $data['meeting_link'] ?? '',
            $data['interviewer'] ?? '',
            $data['notes'] ?? ''
        ]);
    }
}

if (!function_exists('updateApplicantHiredStatus')) {
    function updateApplicantHiredStatus($applicantId, $isHired = true) {
        if (!$applicantId) return false;
        $hiredValue = $isHired ? 1 : 0;
        $sql = "UPDATE applicants SET is_hired = $1, hired_at = $2 WHERE id = $3";
        return updateRecord($sql, [
            $hiredValue,
            $isHired ? date('Y-m-d H:i:s') : null,
            $applicantId
        ]);
    }
}

// =============================================
// FACE SCAN FUNCTIONS
// =============================================

if (!function_exists('logFaceScan')) {
    function logFaceScan($userId, $actionType, $confidence, $status, $imagePath = null) {
        if (!$userId) return false;
        $sql = "INSERT INTO face_logs (user_id, action_type, image_path, confidence_score, status) 
                VALUES ($1, $2, $3, $4, $5)";
        return insertRecord($sql, [$userId, $actionType, $imagePath, $confidence, $status]);
    }
}

if (!function_exists('getFaceLogsByUserId')) {
    function getFaceLogsByUserId($userId, $limit = 50) {
        if (!$userId) return [];
        $sql = "SELECT * FROM face_logs WHERE user_id = $1 ORDER BY created_at DESC LIMIT $2";
        return getRecords($sql, [$userId, $limit]);
    }
}

// =============================================
// ATTENDANCE FUNCTIONS
// =============================================

if (!function_exists('logAttendanceCheckIn')) {
    function logAttendanceCheckIn($deploymentId, $userId, $faceScore, $selfiePath = null) {
        if (!$deploymentId || !$userId) return false;
        $sql = "INSERT INTO attendance (deployment_id, user_id, check_in_time, face_match_score, is_face_verified, selfie_path) 
                VALUES ($1, $2, NOW(), $3, 1, $4)";
        return insertRecord($sql, [$deploymentId, $userId, $faceScore, $selfiePath]);
    }
}

if (!function_exists('logAttendanceCheckOut')) {
    function logAttendanceCheckOut($userId) {
        if (!$userId) return false;
        $sql = "UPDATE attendance 
                SET check_out_time = NOW() 
                WHERE user_id = $1 AND check_out_time IS NULL 
                ORDER BY id DESC LIMIT 1";
        return updateRecord($sql, [$userId]);
    }
}

if (!function_exists('getAttendanceByUserId')) {
    function getAttendanceByUserId($userId, $date = null) {
        if (!$userId) return [];
        if ($date) {
            $sql = "SELECT * FROM attendance WHERE user_id = $1 AND DATE(check_in_time) = $2 ORDER BY check_in_time DESC";
            return getRecords($sql, [$userId, $date]);
        }
        $sql = "SELECT * FROM attendance WHERE user_id = $1 ORDER BY check_in_time DESC";
        return getRecords($sql, [$userId]);
    }
}

if (!function_exists('getUserTodayAttendance')) {
    function getUserTodayAttendance($userId) {
        if (!$userId) return [];
        $sql = "SELECT * FROM attendance WHERE user_id = $1 AND DATE(check_in_time) = CURRENT_DATE ORDER BY check_in_time DESC";
        return getRecords($sql, [$userId]);
    }
}

if (!function_exists('getEmployeeTodayAttendance')) {
    function getEmployeeTodayAttendance($userId) {
        return getUserTodayAttendance($userId);
    }
}

if (!function_exists('checkInEmployee')) {
    function checkInEmployee($userId) {
        global $conn;
        if (!$conn) return ['success' => false, 'error' => 'No database connection'];
        if (!$userId) return ['success' => false, 'error' => 'Invalid user ID'];
        
        $existing = getEmployeeTodayAttendance($userId);
        if ($existing && is_array($existing) && count($existing) > 0) {
            $first = $existing[0] ?? null;
            if ($first) {
                if (!empty($first['check_out_time'])) {
                    return ['success' => false, 'error' => 'Already checked out for today'];
                }
                if (!empty($first['check_in_time'])) {
                    return ['success' => false, 'error' => 'Already checked in'];
                }
            }
        }
        
        $user = getUserById($userId);
        $isFaceVerified = isset($user['is_face_verified']) ? (int)$user['is_face_verified'] : 0;
        
        $sql = "INSERT INTO attendance (user_id, check_in_time, is_face_verified, created_at) 
                VALUES ($1, NOW(), $2, NOW())";
        $result = executeQuery($sql, [$userId, $isFaceVerified]);
        
        if ($result) {
            $id = getLastInsertId();
            logActivity($userId, 'Checked In', 'attendance', $id, 'Employee checked in');
            return ['success' => true, 'id' => $id];
        }
        return ['success' => false, 'error' => 'Failed to check in'];
    }
}

if (!function_exists('checkOutEmployee')) {
    function checkOutEmployee($userId) {
        if (!$userId) return ['success' => false, 'error' => 'Invalid user ID'];
        
        $today = getEmployeeTodayAttendance($userId);
        if (!$today || empty($today)) {
            return ['success' => false, 'error' => 'Not checked in today'];
        }
        $first = $today[0] ?? null;
        if (!$first) {
            return ['success' => false, 'error' => 'No attendance record found'];
        }
        if (!empty($first['check_out_time'])) {
            return ['success' => false, 'error' => 'Already checked out'];
        }
        
        $sql = "UPDATE attendance SET check_out_time = NOW() WHERE id = $1";
        $result = updateRecord($sql, [$first['id']]);
        
        if ($result) {
            logActivity($userId, 'Checked Out', 'attendance', $first['id'], 'Employee checked out');
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'Failed to check out'];
    }
}

if (!function_exists('getEmployeeAttendanceStats')) {
    function getEmployeeAttendanceStats($userId) {
        if (!$userId) {
            return ['total_days' => 0, 'days_present' => 0, 'days_absent' => 0];
        }
        $sql = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN check_in_time IS NOT NULL THEN 1 ELSE 0 END) as days_present,
                    SUM(CASE WHEN check_in_time IS NULL THEN 1 ELSE 0 END) as days_absent
                FROM attendance 
                WHERE user_id = $1 
                AND EXTRACT(MONTH FROM check_in_time) = EXTRACT(MONTH FROM CURRENT_DATE) 
                AND EXTRACT(YEAR FROM check_in_time) = EXTRACT(YEAR FROM CURRENT_DATE)";
        
        $stats = getRecord($sql, [$userId]);
        
        if (!$stats) {
            $stats = ['total_days' => 0, 'days_present' => 0, 'days_absent' => 0];
        }
        
        return $stats;
    }
}

if (!function_exists('getEmployeeRecentAttendance')) {
    function getEmployeeRecentAttendance($userId, $limit = 7) {
        if (!$userId) return [];
        $sql = "SELECT * FROM attendance 
                WHERE user_id = $1 
                ORDER BY check_in_time DESC 
                LIMIT $2";
        return getRecords($sql, [$userId, $limit]);
    }
}

if (!function_exists('getEmployeeSchedule')) {
    function getEmployeeSchedule($userId, $limit = 5) {
        if (!$userId) return [];
        $employee = getEmployeeByUserId($userId);
        $employeeId = $employee['id'] ?? 0;
        
        if ($employeeId <= 0) {
            return [];
        }
        
        $sql = "SELECT * FROM schedules 
                WHERE employee_id = $1 AND schedule_date >= CURRENT_DATE
                ORDER BY schedule_date ASC 
                LIMIT $2";
        return getRecords($sql, [$employeeId, $limit]);
    }
}

// =============================================
// PROFILE FUNCTIONS
// =============================================

if (!function_exists('getInitials')) {
    function getInitials($firstName, $lastName = '') {
        $first = !empty($firstName) ? strtoupper(substr($firstName, 0, 1)) : 'U';
        $last = !empty($lastName) ? strtoupper(substr($lastName, 0, 1)) : '';
        return $first . $last;
    }
}

if (!function_exists('getUserProfileData')) {
    function getUserProfileData($userId) {
        if (!$userId) {
            return [
                'first_name' => 'User',
                'last_name' => '',
                'email' => '',
                'profile_picture' => null,
                'initials' => 'U',
                'avatar_url' => '../../assets/default-avatar.png'
            ];
        }
        $user = getRecord("SELECT first_name, last_name, email, profile_picture FROM users WHERE id = $1", [$userId]);
        
        if (!$user) {
            return [
                'first_name' => 'User',
                'last_name' => '',
                'email' => '',
                'profile_picture' => null,
                'initials' => 'U',
                'avatar_url' => '../../assets/default-avatar.png'
            ];
        }
        
        $profilePic = !empty($user['profile_picture']) ? $user['profile_picture'] : null;
        $initials = getInitials($user['first_name'], $user['last_name']);
        
        $avatarUrl = '../../assets/default-avatar.png';
        if (!empty($profilePic)) {
            $paths = [
                '../../' . $profilePic,
                $profilePic,
                '../../uploads/profile_pictures/' . basename($profilePic)
            ];
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    $avatarUrl = '../../' . $profilePic;
                    break;
                }
            }
        }
        
        return [
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'profile_picture' => $profilePic,
            'initials' => $initials,
            'avatar_url' => $avatarUrl
        ];
    }
}

// =============================================
// HELPER FUNCTIONS
// =============================================

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map('sanitizeInput', $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validatePhoneNumber')) {
    function validatePhoneNumber($phone) {
        return preg_match('/^(\+63|0)[0-9]{10}$/', $phone);
    }
}

if (!function_exists('generateToken')) {
    function generateToken($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
}

if (!function_exists('generatePassword')) {
    function generatePassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        return substr(str_shuffle($chars), 0, $length);
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit;
    }
}

if (!function_exists('redirectBack')) {
    function redirectBack() {
        if (isset($_SERVER['HTTP_REFERER'])) {
            header("Location: " . $_SERVER['HTTP_REFERER']);
        } else {
            header("Location: /ismers/");
        }
        exit;
    }
}

if (!function_exists('setFlashMessage')) {
    function setFlashMessage($type, $message) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage() {
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }
}

if (!function_exists('displayFlashMessage')) {
    function displayFlashMessage() {
        $flash = getFlashMessage();
        if ($flash) {
            $class = $flash['type'] === 'error' ? 'alert-danger' : 'alert-success';
            echo '<div class="alert ' . $class . '">' . htmlspecialchars($flash['message']) . '</div>';
        }
    }
}

if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        if (!$datetime) return 'Unknown';
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;
        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }
        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'Just now';
    }
}

// =============================================
// EMAIL CONFIGURATION
// =============================================
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'calicaarvy13@gmail.com');
define('SMTP_PASS', 'cetc iywq dnpz wdub');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('MAIL_FROM', 'calicaarvy13@gmail.com');
define('MAIL_FROM_NAME', 'ISMERS System');
define('MAIL_REPLY_TO', 'calicaarvy13@gmail.com');
define('MAIL_REPLY_TO_NAME', 'ISMERS Support');

// =============================================
// AI CONFIGURATIONS
// =============================================

$groqKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');
$geminiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');

define('GEMINI_API_KEY', $geminiKey);
define('USE_GEMINI', !empty($geminiKey));
define('GROQ_API_KEY', $groqKey);
define('USE_GROQ', !empty($groqKey));

// =============================================
// PERMISSIONS (with fallback if file doesn't exist)
// =============================================
$permissionsFile = __DIR__ . '/permissions.php';
if (file_exists($permissionsFile)) {
    require_once $permissionsFile;
} else {
    // Define fallback permission functions
    if (!function_exists('hasPermission')) {
        function hasPermission($userId, $permission) {
            $user = getUserById($userId);
            return $user && ($user['role'] === 'admin' || $user['role'] === 'hr_manager');
        }
    }
    if (!function_exists('getUserPermissions')) {
        function getUserPermissions($userId) {
            $user = getUserById($userId);
            if (!$user) return [];
            return [$user['role']];
        }
    }
    if (!function_exists('hasAnyPermission')) {
        function hasAnyPermission($userId, $permissions) {
            foreach ($permissions as $permission) {
                if (hasPermission($userId, $permission)) return true;
            }
            return false;
        }
    }
}



// =============================================
// SESSION TIMEOUT CONFIGURATION
// =============================================

define('SESSION_TIMEOUT_SECONDS', 420); // 7 minutes (7 * 60)

/**
 * Initialize session with timeout tracking
 * Call this at the start of every page after session_start()
 */
function initSessionTimeout() {
    // Session is already started by config.php above
    // DO NOT call session_start() here - it causes "headers already sent" errors
    
    // Define timeout in seconds
    $timeout = SESSION_TIMEOUT_SECONDS;
    
    // Check if last activity timestamp exists
    if (isset($_SESSION['last_activity'])) {
        $inactivity = time() - $_SESSION['last_activity'];
        
        // If inactivity exceeds timeout, destroy session
        if ($inactivity > $timeout) {
            // Clear all session variables
            $_SESSION = array();
            
            // Delete session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            
            // Destroy session
            session_destroy();
            
            // Redirect to login with timeout message
            header('Location: ../../login.php?timeout=1');
            exit;
        }
    }
    
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
    
    // Regenerate session ID periodically (every 5 minutes)
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 300) { // 5 minutes
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

/**
 * Get remaining session time in seconds
 */
function getRemainingSessionTime() {
    if (!isset($_SESSION['last_activity'])) {
        return SESSION_TIMEOUT_SECONDS;
    }
    
    $elapsed = time() - $_SESSION['last_activity'];
    $remaining = SESSION_TIMEOUT_SECONDS - $elapsed;
    
    return max(0, $remaining);
}

/**
 * Get formatted remaining session time (MM:SS)
 */
function getFormattedRemainingTime() {
    $seconds = getRemainingSessionTime();
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return sprintf("%02d:%02d", $minutes, $secs);
}

/**
 * Check if session is about to expire (within 60 seconds)
 */
function isSessionExpiringSoon() {
    return getRemainingSessionTime() < 60;
}

/**
 * Extend session (reset the timer)
 */
function extendSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

?>
