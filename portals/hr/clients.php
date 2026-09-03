<?php
// portals/hr/clients.php - Enhanced Client Management with Company Profiling
// FIXED: PHPMailer path issues
// FIXED: Removed unnecessary fields (city, province, zip, website, tax_id)
// FIXED: Email sending no longer breaks JSON response

// =============================================
// ERROR REPORTING - DISABLE WARNINGS
// =============================================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to prevent any accidental output
ob_start();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug function
function debug_log($message) {
    $logFile = __DIR__ . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] " . (is_array($message) || is_object($message) ? print_r($message, true) : $message) . PHP_EOL;
    @file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// =============================================
// AJAX HANDLER - MUST BE AT THE VERY TOP
// =============================================
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    function sendJsonResponse($data) {
        echo json_encode($data);
        exit;
    }
    
    try {
        require_once '../../app/config.php';
        require_once 'includes/functions.php';
        
        /**
         * Convert boolean/string to integer for display
         */
        function normalizeIsActive($value) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if (is_string($value)) {
                $value = strtolower($value);
                if ($value === 't' || $value === 'true' || $value === '1') {
                    return 1;
                }
                return 0;
            }
            return (int)$value;
        }

        /**
         * Check if client is active (works with boolean or integer)
         */
        function isClientActive($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                $value = strtolower($value);
                return ($value === 't' || $value === 'true' || $value === '1');
            }
            return $value == 1;
        }
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            sendJsonResponse(['success' => false, 'error' => 'Not logged in']);
        }

        if (!in_array($_SESSION['role'], ['hr_manager', 'admin'])) {
            sendJsonResponse(['success' => false, 'error' => 'Unauthorized']);
        }
        
        $action = $_POST['action'] ?? '';
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        
        // =============================================
        // GET COMPANY DETAILS WITH APPLICANTS
        // =============================================
        if ($action === 'get_company_details' && $clientId > 0) {
            $company = @getRecord("
                SELECT c.*, u.email as user_email, u.full_name as user_full_name,
                       u.profile_picture, u.created_at as user_created_at
                FROM clients c
                JOIN users u ON c.user_id = u.id
                WHERE c.id = $1
            ", [$clientId]);
            
            if (!$company) {
                sendJsonResponse(['success' => false, 'error' => 'Company not found.']);
            }
            
            $jobs = @getRecords("
                SELECT id, title, description, status, created_at,
                       (SELECT COUNT(*) FROM applications WHERE job_order_id = job_orders.id) as applicant_count
                FROM job_orders 
                WHERE client_id = $1
                ORDER BY created_at DESC
            ", [$clientId]);
            
            $applicants = @getRecords("
                SELECT DISTINCT 
                    a.id as application_id,
                    a.status as application_status,
                    a.applied_at,
                    a.cover_letter,
                    ap.id as applicant_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    ap.phone,
                    ap.address,
                    ap.skills,
                    ap.experience,
                    ap.education,
                    ap.profile_picture,
                    u.profile_picture as user_profile_picture,
                    jo.id as job_id,
                    jo.title as job_title,
                    jo.status as job_status
                FROM applications a
                JOIN applicants ap ON a.applicant_id = ap.id
                JOIN users u ON ap.user_id = u.id
                JOIN job_orders jo ON a.job_order_id = jo.id
                WHERE jo.client_id = $1
                ORDER BY a.applied_at DESC
            ", [$clientId]);
            
            if (!is_array($jobs)) $jobs = [];
            if (!is_array($applicants)) $applicants = [];
            
            $statusCounts = [
                'pending' => 0,
                'reviewing' => 0,
                'interview_scheduled' => 0,
                'interviewed' => 0,
                'offered' => 0,
                'hired' => 0,
                'rejected' => 0
            ];
            
            foreach ($applicants as $app) {
                $status = $app['application_status'] ?? 'pending';
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
            }
            
            sendJsonResponse([
                'success' => true,
                'company' => $company,
                'jobs' => $jobs,
                'applicants' => $applicants,
                'status_counts' => $statusCounts,
                'total_applicants' => count($applicants),
                'total_jobs' => count($jobs)
            ]);
        }
        
        // =============================================
        // GET CLIENT FOR EDIT - FIXED: Returns correct status
        // =============================================
        if ($action === 'get_client' && $clientId > 0) {
            $client = @getRecord("
                SELECT c.*, u.email, u.full_name 
                FROM clients c
                JOIN users u ON c.user_id = u.id
                WHERE c.id = $1
            ", [$clientId]);

            if ($client) {
                // Handle boolean properly
                $isActive = $client['is_active'] ?? true;
                if (is_bool($isActive)) {
                    $client['is_active'] = $isActive ? 1 : 0;
                } else {
                    $client['is_active'] = ($isActive == 1 || $isActive === '1' || $isActive === 't' || $isActive === 'true') ? 1 : 0;
                }
                sendJsonResponse(['success' => true, 'client' => $client]);
            } else {
                sendJsonResponse(['success' => false, 'error' => 'Client not found']);
            }
        }
        
        // =============================================
        // CREATE CLIENT - FIXED
        // =============================================
        if ($action === 'create_client') {
            debug_log("=== CREATE CLIENT START ===");
            debug_log("POST data: " . print_r($_POST, true));
            
            try {
                $companyName = trim($_POST['company_name'] ?? '');
                $contactPerson = trim($_POST['contact_person'] ?? '');
                $email = trim($_POST['email'] ?? '');
                
                debug_log("Company: $companyName, Contact: $contactPerson, Email: $email");
                
                if (empty($companyName) || empty($contactPerson) || empty($email)) {
                    sendJsonResponse(['success' => false, 'error' => 'Company name, contact person, and email are required.']);
                }
                
                $existing = @getRecord("SELECT id FROM users WHERE email = $1", [$email]);
                if ($existing) {
                    sendJsonResponse(['success' => false, 'error' => 'Email already exists.']);
                }
                
                $tempPassword = generatePassword(10);
                $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);
                
                @beginTransaction();
                
                $userId = @insertRecord("
                    INSERT INTO users (email, password_hash, role, full_name, first_name, last_name, created_at)
                    VALUES ($1, $2, 'client', $3, $4, $5, NOW())
                    RETURNING id
                ", [
                    $email,
                    $passwordHash,
                    $contactPerson,
                    $contactPerson,
                    ''
                ]);
                
                debug_log("User ID created: " . ($userId ? $userId : 'FAILED'));
                
                if (!$userId) {
                    @rollbackTransaction();
                    sendJsonResponse(['success' => false, 'error' => 'Failed to create user account.']);
                }
                
                $clientId = @insertRecord("
                    INSERT INTO clients (
                        user_id, company_name, contact_person, contact_phone, 
                        industry, company_size, address, notes, is_active, created_at
                    ) VALUES (
                        $1, $2, $3, $4, $5, $6, $7, $8, $9, NOW()
                    )
                    RETURNING id
                ", [
                    $userId,
                    $companyName,
                    $contactPerson,
                    $_POST['phone'] ?? '',
                    $_POST['industry'] ?? '',
                    $_POST['company_size'] ?? '',
                    $_POST['address'] ?? '',
                    $_POST['notes'] ?? '',
                    isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1
                ]);
                
                debug_log("Client ID created: " . ($clientId ? $clientId : 'FAILED'));
                
                if (!$clientId) {
                    @rollbackTransaction();
                    sendJsonResponse(['success' => false, 'error' => 'Failed to create client record.']);
                }
                
                @commitTransaction();
                
                // Try to send email but don't let it break the response
                $emailSent = false;
                try {
                    // Only load PHPMailer if we're going to send email
                    if (file_exists(__DIR__ . '/../../PHPMailer-master/src/PHPMailer.php')) {
                        require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
                        require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
                        require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';
                        
                        $emailSent = sendClientWelcomeEmail($email, $contactPerson, $tempPassword, $companyName);
                        if ($emailSent) {
                            debug_log("Welcome email sent successfully to: $email");
                        } else {
                            debug_log("Welcome email failed to send to: $email");
                        }
                    } else {
                        debug_log("PHPMailer not found at: " . __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php');
                    }
                } catch (Exception $e) {
                    debug_log("Welcome email exception: " . $e->getMessage());
                    // Don't re-throw - just log it
                }
                
                debug_log("=== CREATE CLIENT SUCCESS ===");
                sendJsonResponse([
                    'success' => true,
                    'message' => 'Client created successfully.' . ($emailSent ? '' : ' (Email notification failed)'),
                    'client_id' => $clientId,
                    'email_sent' => $emailSent
                ]);
                
            } catch (Exception $e) {
                @rollbackTransaction();
                debug_log("CREATE CLIENT ERROR: " . $e->getMessage());
                sendJsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        
        // =============================================
        // UPDATE CLIENT - FIXED
        // =============================================
        if ($action === 'update_client' && $clientId > 0) {
            debug_log("=== UPDATE CLIENT START ===");
            debug_log("POST data: " . print_r($_POST, true));
            
            $fields = [];
            $params = [];
            $counter = 1;
            
            $allowedFields = [
                'company_name', 'contact_person', 'contact_phone', 'industry',
                'company_size', 'address', 'notes'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $fields[] = "$field = $" . $counter++;
                    $params[] = $_POST[$field];
                }
            }
            
            // Handle is_active properly
            if (isset($_POST['is_active'])) {
                $isActive = (int)$_POST['is_active'];
                $fields[] = "is_active = $" . $counter++;
                $params[] = $isActive;
                debug_log("Setting is_active to: $isActive for client $clientId");
            }
            
            if (empty($fields)) {
                sendJsonResponse(['success' => false, 'error' => 'No fields to update']);
            }
            
            $params[] = $clientId;
            $sql = "UPDATE clients SET " . implode(", ", $fields) . " WHERE id = $" . $counter;
            
            debug_log("UPDATE SQL: " . $sql);
            debug_log("UPDATE Params: " . print_r($params, true));
            
            $result = @updateRecord($sql, $params);
            
            debug_log("UPDATE Result: " . ($result ? 'true' : 'false'));
            
            if ($result) {
                $check = @getRecord("SELECT is_active FROM clients WHERE id = $1", [$clientId]);
                debug_log("After update, is_active = " . ($check ? $check['is_active'] : 'not found'));
                sendJsonResponse(['success' => true, 'message' => 'Client updated successfully.']);
            } else {
                sendJsonResponse(['success' => false, 'error' => 'Failed to update client.']);
            }
        }
        
        // =============================================
        // DELETE CLIENT
        // =============================================
        if ($action === 'delete_client' && $clientId > 0) {
            $client = @getRecord("SELECT user_id FROM clients WHERE id = $1", [$clientId]);
            if (!$client) {
                sendJsonResponse(['success' => false, 'error' => 'Client not found.']);
            }
            
            $userId = $client['user_id'];
            
            @beginTransaction();
            
            try {
                $result1 = @deleteRecord("DELETE FROM clients WHERE id = $1", [$clientId]);
                $result2 = @deleteRecord("DELETE FROM users WHERE id = $1", [$userId]);
                
                if ($result1 && $result2) {
                    @commitTransaction();
                    sendJsonResponse(['success' => true, 'message' => 'Client deleted successfully.']);
                } else {
                    throw new Exception('Failed to delete client.');
                }
            } catch (Exception $e) {
                @rollbackTransaction();
                sendJsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        
        sendJsonResponse(['success' => false, 'error' => 'Invalid action: ' . $action]);
        
    } catch (Exception $e) {
        @rollbackTransaction();
        sendJsonResponse(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}

// =============================================
// NORMAL PAGE LOAD
// =============================================

require_once '../../app/config.php';
initSessionTimeout();
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if (!in_array($_SESSION['role'], ['hr_manager', 'admin'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';

// =============================================
// PHPMailer Function - Only define if files exist
// =============================================
function sendClientWelcomeEmail($to, $name, $tempPassword, $companyName) {
    // Check if PHPMailer files exist
    $phpmailerPath = __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
    if (!file_exists($phpmailerPath)) {
        debug_log("PHPMailer not found at: $phpmailerPath");
        return false;
    }
    
    try {
        // Use the correct namespace
        use PHPMailer\PHPMailer\PHPMailer;
        use PHPMailer\PHPMailer\SMTP;
        use PHPMailer\PHPMailer\Exception;
        
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);
        $mail->isHTML(true);
        $mail->Subject = "Welcome to ISMERS - Your Client Account";
        $mail->Body = "<h2>Welcome to ISMERS!</h2><p>Your client account for <strong>$companyName</strong> has been created.</p><p><strong>Email:</strong> $to<br><strong>Password:</strong> $tempPassword</p><p>Please login and change your password.</p>";
        $mail->AltBody = "Welcome to ISMERS! Your account for $companyName has been created. Email: $to, Password: $tempPassword";
        $mail->send();
        return true;
    } catch (Exception $e) {
        debug_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}

$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// =============================================
// GET ALL CLIENTS
// =============================================
$conditions = [];
$params = [];
$counter = 1;

if ($statusFilter !== 'all') {
    if ($statusFilter === 'active') {
        $conditions[] = "c.is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $conditions[] = "c.is_active = 0";
    }
}

if (!empty($searchQuery)) {
    $conditions[] = "(c.company_name LIKE $" . $counter . " OR c.contact_person LIKE $" . ($counter+1) . " OR u.email LIKE $" . ($counter+2) . ")";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $counter += 3;
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

$sql = "SELECT c.*, 
        u.id as user_id, u.email as user_email, u.first_name, u.last_name,
        u.profile_picture, u.created_at as user_created_at,
        (SELECT COUNT(*) FROM job_orders WHERE client_id = c.id) as total_jobs,
        (SELECT COUNT(*) FROM applications a 
         JOIN job_orders jo ON a.job_order_id = jo.id 
         WHERE jo.client_id = c.id) as total_applications,
        (SELECT COUNT(DISTINCT a.applicant_id) FROM applications a 
         JOIN job_orders jo ON a.job_order_id = jo.id 
         WHERE jo.client_id = c.id) as unique_applicants
        FROM clients c
        JOIN users u ON c.user_id = u.id
        $whereClause
        ORDER BY c.created_at DESC";

$clients = @getRecords($sql, $params);
if (!is_array($clients)) $clients = [];

$statusCounts = ['all' => count($clients)];
$activeCount = 0;
$inactiveCount = 0;

foreach ($clients as $client) {
    $isActive = $client['is_active'] ?? false;
    if (is_bool($isActive)) {
        if ($isActive) $activeCount++; else $inactiveCount++;
    } else {
        if ($isActive == 1 || $isActive === '1' || $isActive === 't' || $isActive === 'true') {
            $activeCount++;
        } else {
            $inactiveCount++;
        }
    }
}

$statusCounts['active'] = $activeCount;
$statusCounts['inactive'] = $inactiveCount;

$allStatuses = ['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'];
$statusBadges = ['active' => 'badge-active', 'inactive' => 'badge-inactive'];
$statusLabels = ['active' => 'Active', 'inactive' => 'Inactive'];

$industries = [
    'Technology & Software', 'Information Technology', 'BFSI (Banking, Financial Services, Insurance)',
    'Healthcare & Pharmaceuticals', 'Retail & E-commerce', 'Manufacturing & Industrial',
    'Real Estate & Construction', 'Education & Training', 'Hospitality & Tourism',
    'Transportation & Logistics', 'Media & Entertainment', 'Telecommunications',
    'Energy & Utilities', 'Consulting & Professional Services', 'Non-Profit & NGO',
    'Government & Public Sector', 'Other'
];

$companySizes = [
    '1-10' => '1-10 employees', '11-50' => '11-50 employees',
    '51-200' => '51-200 employees', '201-500' => '201-500 employees',
    '501-1000' => '501-1000 employees', '1000+' => '1000+ employees'
];

$pendingCount = 0;
$pendingResult = @getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", []);
if ($pendingResult && isset($pendingResult['count'])) {
    $pendingCount = (int)$pendingResult['count'];
}

$totalArchived = 0;
$tables = ['examination_records', 'interview_evaluations', 'client_assignments', 'deployment_archive'];
foreach ($tables as $table) {
    $result = @getRecord("SELECT COUNT(*) as count FROM $table", []);
    if ($result && isset($result['count'])) {
        $totalArchived += (int)$result['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Clients - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* =============================================
           ALL YOUR EXISTING STYLES HERE (keep as is)
           ============================================= */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --bg-surface-container-low: #f5f6fa;
            --bg-surface-container-lowest: #ffffff;
            --bg-surface-container-high: #eef0f5;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --text-on-background: #0a0e1a;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
            --primary-container: #eef0ff;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 10px 10px -5px rgba(0, 0, 0, 0.02);
            --radius-sm: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.25rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-label: 'Public Sans', system-ui, -apple-system, sans-serif;
            --transition-fast: 0.15s ease;
            --transition-smooth: 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            --sidebar-width: 280px;
            --sidebar-collapsed: 72px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-sans);
            background: var(--bg-background);
            color: var(--text-on-surface);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: row;
            overflow: hidden;
            height: 100vh;
        }
        a { text-decoration: none; color: inherit; }

        /* =============================================
           SIDEBAR STYLES - Standardized
           ============================================= */
        .dashboard-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 50;
            background: var(--bg-surface);
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: var(--sidebar-width);
            border-right: 1px solid var(--slate-200);
            transition: width 0.3s ease, transform 0.3s ease;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            flex-shrink: 0;
        }

        .dashboard-sidebar.collapsed { width: var(--sidebar-collapsed); }
        .dashboard-sidebar.mobile-hidden { transform: translateX(-100%); }
        .dashboard-sidebar.mobile-open { transform: translateX(0); }

        .dashboard-sidebar .sidebar-brand-text,
        .dashboard-sidebar .sidebar-brand-category,
        .dashboard-sidebar .sidebar-nav .nav-label,
        .dashboard-sidebar .sidebar-nav .nav-text,
        .dashboard-sidebar .sidebar-nav .nav-badge,
        .dashboard-sidebar .sidebar-footer .user-info {
            opacity: 1;
            transition: opacity 0.3s ease;
            overflow: hidden;
            white-space: nowrap;
        }

        .dashboard-sidebar.collapsed .sidebar-brand-text,
        .dashboard-sidebar.collapsed .sidebar-brand-category,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
        .dashboard-sidebar.collapsed .sidebar-footer .user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }

        .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1rem 0.5rem; }
        .dashboard-sidebar.collapsed .sidebar-nav { padding: 0.5rem 0.25rem; }
        .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: center; padding: 0.75rem 0.5rem; }
        .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.5rem; }
        .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: center; padding: 0.5rem; }
        .dashboard-sidebar.collapsed .sidebar-footer .user-card .avatar { width: 2.5rem; height: 2.5rem; font-size: 0.875rem; }

        .sidebar-brand-card {
            border-radius: 2rem;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
        }

        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background: var(--slate-100);
            color: var(--primary);
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .sidebar-brand-icon .material-symbols-outlined { font-size: 1.5rem; }
        .sidebar-brand-text { font-size: 0.875rem; font-weight: 600; color: var(--slate-900); }
        .sidebar-brand-category { font-size: 0.75rem; color: var(--slate-500); margin-top: 0.25rem; }

        .sidebar-nav { flex: 1; overflow-y: auto; padding: 1.5rem 1.25rem; }
        .sidebar-nav .nav-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--slate-500);
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
        }

        .sidebar-main-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            margin-bottom: 0.25rem;
            font-family: var(--font-label);
            font-weight: 500;
            font-size: 0.875rem;
            position: relative;
        }

        .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-main-link.active { background: var(--bg-surface-container-high); color: var(--primary); }
        .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            border-radius: 50px;
            transition: opacity 0.3s ease;
        }

        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--slate-200);
        }

        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 1rem;
            background: var(--bg-surface-low);
        }

        .sidebar-footer .user-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .sidebar-footer .user-card .user-info .user-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.75rem; color: var(--text-on-surface-variant); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(17, 24, 39, 0.5);
            backdrop-filter: blur(8px);
            z-index: 40;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        .sidebar-backdrop.active { display: block; opacity: 1; }

        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
        }
        .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }

        .top-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
            padding: 0 1.5rem;
            flex-shrink: 0;
            z-index: 30;
        }
        .top-header-left { display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.5rem;
            border: 1px solid var(--slate-200);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.25rem;
            min-height: 2.25rem;
        }
        .sidebar-toggle-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-toggle-btn .material-symbols-outlined { font-size: 1.25rem; }
        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.5rem;
            border: 1px solid var(--slate-200);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.25rem;
            min-height: 2.25rem;
        }
        .mobile-menu-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .mobile-menu-btn .material-symbols-outlined { font-size: 1.25rem; }

        .profile-dropdown-wrapper { position: relative; }
        .profile-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.25rem 0.75rem 0.25rem 0.25rem;
            border-radius: var(--radius-full);
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .profile-dropdown-toggle:hover { background: var(--bg-surface-low); border-color: var(--slate-200); }
        .profile-dropdown-toggle .avatar-small {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
        }
        .profile-dropdown-toggle .profile-name { font-size: 0.8125rem; font-weight: 600; color: var(--text-on-surface); }
        .profile-dropdown-toggle .profile-role { font-size: 0.6875rem; color: var(--text-on-surface-variant); font-weight: 400; }
        .profile-dropdown-toggle .material-symbols-outlined { font-size: 1rem; color: var(--text-on-surface-variant); transition: transform var(--transition-fast); }
        .profile-dropdown-toggle.open .material-symbols-outlined:last-child { transform: rotate(180deg); }
        .profile-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 13rem;
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--slate-200);
            padding: 0.5rem;
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-0.25rem) scale(0.97);
            transition: all var(--transition-smooth);
            transform-origin: top right;
        }
        .profile-dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown-menu .dropdown-header { padding: 0.25rem 0.75rem 0.25rem; font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-on-surface-variant); }
        .profile-dropdown-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            font-family: var(--font-sans);
        }
        .profile-dropdown-menu .dropdown-item:hover { background: var(--bg-surface-low); color: var(--primary); }
        .profile-dropdown-menu .dropdown-item .material-symbols-outlined { font-size: 1.125rem; color: var(--text-on-surface-variant); }
        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined { color: var(--primary); }
        .profile-dropdown-menu .dropdown-item.danger { color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger:hover { background: #fef2f2; color: #dc2626; }
        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined { color: #dc2626; }
        .profile-dropdown-menu .dropdown-divider { height: 1px; background: var(--slate-200); margin: 0.25rem 0.5rem; }

        .main-scroll { flex: 1; overflow-y: auto; padding: 1.5rem 2rem; }
        .main-scroll .container { max-width: 96rem; margin: 0 auto; }

        .breadcrumb-bar {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 0.75rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-xs);
        }
        @media (min-width: 640px) {
            .breadcrumb-bar { border-radius: var(--radius-xl); flex-direction: row; align-items: center; justify-content: space-between; }
        }
        .breadcrumb-view {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 600;
        }
        .breadcrumb-view .material-symbols-outlined { font-size: 1rem; }
        .breadcrumb-view .status-dot {
            width: 0.375rem;
            height: 0.375rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .breadcrumb-meta { font-size: 0.75rem; color: var(--text-on-surface-variant); }

        .page-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) { .page-header { flex-direction: row; align-items: center; justify-content: space-between; } }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.125rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8125rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 1px 2px rgba(79, 70, 229, 0.15); }
        .btn-primary:hover { background: var(--on-primary-fixed-variant); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--primary-container); }
        .btn-ghost { background: transparent; color: var(--text-on-surface-variant); }
        .btn-ghost:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* Tooltip styles */
        .tooltip-trigger {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .tooltip-trigger .tooltip-text {
            visibility: hidden;
            width: 120px;
            background-color: #1e293b;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 0.25rem 0.5rem;
            font-size: 0.65rem;
            font-weight: 500;
            position: absolute;
            z-index: 100;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            white-space: nowrap;
            pointer-events: none;
        }
        .tooltip-trigger .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #1e293b transparent transparent transparent;
        }
        .tooltip-trigger:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-xs);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .stat-card .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.primary { background: #eef0ff; color: #4f46e5; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }
        .stat-card .stat-icon .material-symbols-outlined { font-size: 1.25rem; }
        .stat-card .stat-info { display: flex; flex-direction: column; }
        .stat-card .stat-number { font-size: 1.5rem; font-weight: 800; color: var(--text-on-surface); line-height: 1.2; }
        .stat-card .stat-label { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-on-surface-variant); }

        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 180px;
            position: relative;
        }
        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.125rem;
        }
        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.5rem 0.875rem 0.5rem 2.5rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .filter-btn {
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            transition: all var(--transition-fast);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 2px 10px rgba(79, 70, 229, 0.25); }
        .filter-btn .count { background: rgba(0,0,0,0.08); border-radius: var(--radius-full); padding: 0 0.375rem; font-size: 0.625rem; font-weight: 700; }
        .filter-btn.active .count { background: rgba(255,255,255,0.25); }

        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-header h3 .material-symbols-outlined { font-size: 1.125rem; color: var(--primary); }
        .card-header .count-badge { font-size: 0.75rem; color: var(--text-on-surface-variant); background: var(--bg-surface-low); padding: 0.125rem 0.625rem; border-radius: var(--radius-full); }
        .card-body { padding: 0; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            min-width: 750px;
        }
        table thead { background: var(--bg-surface-low); }
        table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        table tbody tr:hover td { background: var(--bg-surface-low); }
        table tbody tr:last-child td { border-bottom: none; }

        .client-cell {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .client-cell .company-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        .client-cell .company-img {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            object-fit: cover;
            flex-shrink: 0;
        }
        .client-cell .info .name { font-weight: 600; color: var(--text-on-surface); }
        .client-cell .info .contact { font-size: 0.6875rem; color: var(--text-on-surface-variant); }
        .client-cell .info .applicant-count { font-size: 0.625rem; color: var(--primary); font-weight: 600; }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-active { background: #d1fae5; color: #059669; }
        .badge-inactive { background: #fecaca; color: #dc2626; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-reviewing { background: #dbeafe; color: #2563eb; }
        .badge-interview_scheduled { background: #e0e7ff; color: #4f46e5; }
        .badge-interviewed { background: #ede9fe; color: #7c3aed; }
        .badge-offered { background: #d1fae5; color: #059669; }
        .badge-hired { background: #a7f3d0; color: #047857; }
        .badge-rejected { background: #fecaca; color: #dc2626; }

        .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; justify-content: center; }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        .empty-state .material-symbols-outlined {
            font-size: 3rem;
            color: var(--slate-300);
            display: block;
            margin-bottom: 0.5rem;
        }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); }
        .empty-state p { font-size: 0.8125rem; color: var(--text-on-surface-variant); }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 44rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }
        .modal-wide { max-width: 80rem; }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 1.125rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-header h2 .material-symbols-outlined { font-size: 1.25rem; color: var(--primary); }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.375rem;
            border-radius: 0.375rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }
        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-close .material-symbols-outlined { font-size: 1.25rem; }
        .modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.1875rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-group .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-group textarea.form-control { resize: vertical; min-height: 60px; }
        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
        .helper-text {
            font-size: 0.6875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.1875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .helper-text .material-symbols-outlined { font-size: 0.875rem; }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.625rem 1.125rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.8125rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideUp 0.35s ease-out;
            max-width: 380px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toast .material-symbols-outlined { font-size: 1.125rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .company-profile-header {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
            padding: 1rem 0 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }
        .company-profile-header .company-logo {
            width: 5rem;
            height: 5rem;
            border-radius: var(--radius-xl);
            background: var(--primary-container);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            flex-shrink: 0;
        }
        .company-profile-header .company-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: var(--radius-xl); }
        .company-profile-header .company-info h2 { font-size: 1.5rem; font-weight: 800; color: var(--text-on-surface); }
        .company-profile-header .company-info .sub-info {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 0.25rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
        }
        .company-profile-header .company-info .sub-info span { display: flex; align-items: center; gap: 0.25rem; }

        .company-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin: 1rem 0 1.5rem;
        }
        .company-stat-card {
            background: var(--bg-surface-low);
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            text-align: center;
        }
        .company-stat-card .number { font-size: 1.5rem; font-weight: 800; color: var(--primary); }
        .company-stat-card .label { font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-on-surface-variant); }

        .applicant-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--slate-200);
            transition: all var(--transition-fast);
            margin-bottom: 0.5rem;
        }
        .applicant-card:hover { background: var(--bg-surface-low); border-color: var(--slate-300); }
        .applicant-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: var(--primary-container);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--primary);
            flex-shrink: 0;
        }
        .applicant-card .avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .applicant-card .info { flex: 1; min-width: 0; }
        .applicant-card .info .name { font-weight: 600; font-size: 0.875rem; }
        .applicant-card .info .details { font-size: 0.6875rem; color: var(--text-on-surface-variant); display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .applicant-card .info .job-title { font-size: 0.75rem; color: var(--primary); font-weight: 500; }
        .applicant-card .status-badge { flex-shrink: 0; }

        .tab-bar {
            display: flex;
            gap: 0.25rem;
            border-bottom: 2px solid var(--slate-200);
            margin-bottom: 1rem;
        }
        .tab-btn {
            padding: 0.5rem 1rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: none;
            border: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
        }
        .tab-btn:hover { color: var(--text-on-surface); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-sm); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-lg); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: none; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .search-bar { flex-direction: column; }
            .filters { overflow-x: auto; flex-wrap: nowrap; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
            .company-profile-header { flex-direction: column; align-items: center; text-align: center; }
            .company-profile-header .company-info .sub-info { justify-content: center; }
            .company-stats-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .stats-row { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.25rem; }
            table { font-size: 0.75rem; min-width: 500px; }
            table th, table td { padding: 0.375rem 0.5rem; }
            .company-stats-grid { grid-template-columns: 1fr; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }

        .header-logo {
            height: 2rem;
            width: auto;
            max-height: 2.5rem;
            object-fit: contain;
            border-radius: 0.375rem;
        }

        @media (max-width: 480px) {
            .header-logo {
                height: 1.5rem;
            }
        }
        .sidebar-logo-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            flex-shrink: 0;
        }

        .sidebar-logo {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
        }

        .dashboard-sidebar.collapsed .sidebar-logo {
            width: 2.5rem;
            height: 2.5rem;
        }
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <div class="sidebar-logo-wrapper">
            <img src="logo.png" alt="ISMERS" class="sidebar-logo">
        </div>
        <p class="sidebar-brand-category">HR Portal</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="clients.php" class="sidebar-main-link active">
            <span class="material-symbols-outlined">business</span>
            <span class="nav-text">Clients</span>
        </a>
        <a href="jobs.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">work</span>
            <span class="nav-text">My Jobs</span>
        </a>
        <a href="applicants.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">people</span>
            <span class="nav-text">Applicants</span>
            <span class="nav-badge"><?php echo $pendingCount; ?></span>
        </a>
        <a href="interviews.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">calendar_month</span>
            <span class="nav-text">Interviews</span>
        </a>
        <a href="offers.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">description</span>
            <span class="nav-text">Offers</span>
        </a>
        <a href="archive.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">archive</span>
            <span class="nav-text">Archive</span>
            <span class="nav-badge"><?php echo $totalArchived; ?></span>
        </a>
        <a href="apply_agency.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">apartment</span>
            <span class="nav-text">Apply as Agency</span>
        </a>
        <a href="deployments.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">assignment</span>
            <span class="nav-text">Deployments</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
            </div>
        </div>
    </div>
</aside>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-wrapper" id="mainWrapper">
<!-- ===== TOP HEADER ===== -->
<header class="top-header">
    <div class="top-header-left">
        <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
            <span class="material-symbols-outlined" id="sidebarToggleIcon">chevron_left</span>
        </button>
        <img src="logo.png" alt="ISMERS" class="header-logo">
        <span class="separator">|</span>
        <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
            <?php 
                $pageTitle = basename($_SERVER['PHP_SELF'], '.php');
                echo ucwords(str_replace('_', ' ', $pageTitle));
            ?>
        </span>
    </div>
        <div class="profile-dropdown-wrapper">
            <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                <span class="material-symbols-outlined">expand_more</span>
            </button>
            <div class="profile-dropdown-menu" id="profileMenu">
                <div class="dropdown-header">Account</div>
                <button class="dropdown-item" onclick="window.location.href='profile.php'">
                    <span class="material-symbols-outlined">person</span> Profile
                </button>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">
                    <span class="material-symbols-outlined">logout</span> Logout
                </button>
            </div>
        </div>
    </header>

    <main class="main-scroll">
        <div class="container">
            <!-- Breadcrumb -->
            <div class="breadcrumb-bar">
                <div class="breadcrumb-view">
                    <span class="material-symbols-outlined">business</span>
                    <span>Clients</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> (<?php echo count($clients); ?>)
                    </span>
                </div>
                <span class="breadcrumb-meta">Updated <?php echo date('M d, Y H:i'); ?></span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Client Management</h1>
                    <p>Manage client companies and their accounts</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <span class="material-symbols-outlined">add</span>
                        Create New Client
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <span class="material-symbols-outlined">business</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['all']; ?></div>
                        <div class="stat-label">Total Clients</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">
                        <span class="material-symbols-outlined">check_circle</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['active'] ?? 0; ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red">
                        <span class="material-symbols-outlined">block</span>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $statusCounts['inactive'] ?? 0; ?></div>
                        <div class="stat-label">Inactive</div>
                    </div>
                </div>
            </div>

            <!-- Search -->
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" id="searchInput" placeholder="Search by company name, contact person, or email..." 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                <?php if (!empty($searchQuery) || $statusFilter !== 'all'): ?>
                    <a href="clients.php" class="btn btn-outline">Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filters">
                <?php foreach ($allStatuses as $key => $label): ?>
                    <a href="?status=<?php echo $key; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                       class="filter-btn <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                        <?php echo $label; ?>
                        <span class="count"><?php echo $statusCounts[$key] ?? 0; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Clients Table -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <span class="material-symbols-outlined">business</span>
                        <?php echo $statusFilter === 'all' ? 'All Clients' : ucfirst($statusFilter) . ' Clients'; ?>
                    </h3>
                    <span class="count-badge"><?php echo count($clients); ?> clients</span>
                </div>
                <div class="card-body">
                    <?php if (empty($clients)): ?>
                        <div class="empty-state">
                            <span class="material-symbols-outlined">business</span>
                            <h4>No Clients Found</h4>
                            <p>No clients have been created yet.</p>
                            <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top:0.75rem;">
                                <span class="material-symbols-outlined">add</span>
                                Create First Client
                            </button>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Contact</th>
                                    <th>Industry</th>
                                    <th>Stats</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clients as $client): ?>
                                <?php 
                                $isActive = $client['is_active'];
                                if (is_bool($isActive)) {
                                    $status = $isActive ? 'active' : 'inactive';
                                } else {
                                    $status = ($isActive == 1 || $isActive === '1' || $isActive === 't' || $isActive === 'true') ? 'active' : 'inactive';
                                }
                                $profilePic = !empty($client['profile_picture']) ? $client['profile_picture'] : '';
                                $imagePath = '../../' . $profilePic;
                                $hasProfileImage = !empty($profilePic) && file_exists($imagePath);
                                ?>
                                <tr>
                                    <td>
                                        <div class="client-cell">
                                            <?php if ($hasProfileImage): ?>
                                                <img src="<?php echo htmlspecialchars($imagePath); ?>" 
                                                     alt="<?php echo htmlspecialchars($client['company_name']); ?>" 
                                                     class="company-img">
                                            <?php else: ?>
                                                <span class="company-icon">
                                                    <?php echo strtoupper(substr($client['company_name'] ?? 'C', 0, 1)); ?>
                                                </span>
                                            <?php endif; ?>
                                            <div class="info">
                                                <div class="name"><?php echo htmlspecialchars($client['company_name']); ?></div>
                                                <div class="contact"><?php echo htmlspecialchars($client['user_email']); ?></div>
                                                <div class="applicant-count">
                                                    <?php echo ($client['total_applications'] ?? 0); ?> applications · 
                                                    <?php echo ($client['unique_applicants'] ?? 0); ?> applicants
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:500;"><?php echo htmlspecialchars($client['contact_person']); ?></div>
                                        <div style="font-size:0.6875rem; color:var(--text-on-surface-variant);">
                                            <?php echo htmlspecialchars($client['contact_phone'] ?? 'No phone'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                            <?php echo htmlspecialchars($client['industry'] ?? '—'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:var(--text-on-surface);">
                                            <?php echo $client['total_jobs'] ?? 0; ?> Jobs
                                        </div>
                                        <div style="font-size:0.625rem; color:var(--text-on-surface-variant);">
                                            <?php echo $client['total_applications'] ?? 0; ?> applications
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $statusBadges[$status] ?? 'badge-active'; ?>">
                                            <?php echo $statusLabels[$status] ?? ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <div class="tooltip-trigger">
                                                <button class="btn btn-primary btn-sm" onclick="viewCompanyDetails(<?php echo $client['id']; ?>)">
                                                    <span class="material-symbols-outlined">visibility</span>
                                                </button>
                                                <span class="tooltip-text">View Details</span>
                                            </div>
                                            <div class="tooltip-trigger">
                                                <button class="btn btn-outline btn-sm" onclick="editClient(<?php echo $client['id']; ?>)">
                                                    <span class="material-symbols-outlined">edit</span>
                                                </button>
                                                <span class="tooltip-text">Edit Client</span>
                                            </div>
                                            <div class="tooltip-trigger">
                                                <button class="btn btn-danger btn-sm" onclick="deleteClient(<?php echo $client['id']; ?>)">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                                <span class="tooltip-text">Delete Client</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- =============================================
MODAL: Create/Edit Client - UPDATED (removed city, province, zip, website, tax_id)
============================================= -->
<div class="modal-overlay" id="clientModal">
    <div class="modal">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">business</span>
                <span id="modalTitle">Create New Client</span>
            </h2>
            <button class="modal-close" onclick="closeModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form id="clientForm" onsubmit="submitClient(event)">
                <input type="hidden" id="clientId" name="client_id" value="0">
                <input type="hidden" id="formAction" name="action" value="create_client">
                
                <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin-bottom:1rem;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:0.05em;">Company Information</div>
                </div>
                
                <div class="form-group">
                    <label>Company Name <span class="required">*</span></label>
                    <input type="text" id="companyName" name="company_name" class="form-control" required placeholder="e.g., TechCorp Inc.">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Industry</label>
                        <select id="industry" name="industry" class="form-control">
                            <option value="">Select industry...</option>
                            <?php foreach ($industries as $ind): ?>
                                <option value="<?php echo $ind; ?>"><?php echo $ind; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Company Size</label>
                        <select id="companySize" name="company_size" class="form-control">
                            <option value="">Select size...</option>
                            <?php foreach ($companySizes as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin:1rem 0 0.75rem;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:0.05em;">Contact Information</div>
                </div>
                
                <div class="form-group">
                    <label>Contact Person <span class="required">*</span></label>
                    <input type="text" id="contactPerson" name="contact_person" class="form-control" required placeholder="Full name of contact person">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" id="clientEmail" name="email" class="form-control" required placeholder="contact@company.com">
                        <div class="helper-text">
                            <span class="material-symbols-outlined">info</span>
                            This will be used for client login
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" id="clientPhone" name="phone" class="form-control" placeholder="+63 912 345 6789">
                    </div>
                </div>
                
                <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin:1rem 0 0.75rem;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:0.05em;">Address</div>
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" id="clientAddress" name="address" class="form-control" placeholder="Street address">
                </div>
                
                <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin:1rem 0 0.75rem;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:0.05em;">Additional Information</div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="clientNotes" name="notes" class="form-control" placeholder="Any additional notes..." rows="2"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select id="clientStatus" name="is_active" class="form-control">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" id="submitBtn" onclick="document.getElementById('clientForm').dispatchEvent(new Event('submit'))">
                <span class="material-symbols-outlined">check</span>
                <span id="submitBtnText">Create Client</span>
            </button>
        </div>
    </div>
</div>

<!-- =============================================
MODAL: Company Details with Applicants
============================================= -->
<div class="modal-overlay" id="companyDetailsModal">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h2>
                <span class="material-symbols-outlined">visibility</span>
                <span id="companyDetailsTitle">Company Details</span>
            </h2>
            <button class="modal-close" onclick="closeModal('companyDetailsModal')">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body" id="companyDetailsBody">
            <div id="companyDetailsLoading" style="text-align:center; padding:1.5rem;">
                <div style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div>
                <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading company details...</p>
            </div>
            <div id="companyDetailsContent" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('companyDetailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- =============================================
JAVASCRIPT - COMPLETELY FIXED
============================================= -->
<script>
// =============================================
// 1. SIDEBAR TOGGLE
// =============================================
const sidebar = document.getElementById('appSidebar');
const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
const isMobile = window.innerWidth <= 768;
const savedState = localStorage.getItem('sidebarCollapsed');

if (savedState === 'true' && !isMobile) {
    sidebar.classList.add('collapsed');
    const icon = sidebarToggleBtn.querySelector('.material-symbols-outlined');
    if (icon) icon.textContent = 'chevron_right';
}

sidebarToggleBtn.addEventListener('click', function() {
    if (window.innerWidth <= 768) return;
    sidebar.classList.toggle('collapsed');
    const icon = this.querySelector('.material-symbols-outlined');
    if (icon) {
        icon.textContent = sidebar.classList.contains('collapsed') ? 'chevron_right' : 'chevron_left';
    }
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});

// =============================================
// 2. MOBILE SIDEBAR
// =============================================
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

function openMobileSidebar() {
    sidebar.classList.add('mobile-open');
    sidebarBackdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
    sidebar.classList.remove('mobile-open');
    sidebarBackdrop.classList.remove('active');
    document.body.style.overflow = '';
}

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', openMobileSidebar);
}
if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeMobileSidebar);
}

// =============================================
// 3. PROFILE DROPDOWN
// =============================================
const profileToggle = document.getElementById('profileToggle');
const profileMenu = document.getElementById('profileMenu');

if (profileToggle && profileMenu) {
    profileToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        this.classList.toggle('open');
        profileMenu.classList.toggle('open');
    });

    document.addEventListener('click', function(e) {
        if (!profileToggle.contains(e.target) && !profileMenu.contains(e.target)) {
            profileToggle.classList.remove('open');
            profileMenu.classList.remove('open');
        }
    });
}

// =============================================
// 4. MODAL FUNCTIONS
// =============================================
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    if (!id) id = 'clientModal';
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this.id);
        }
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const clientModal = document.getElementById('clientModal');
        const detailsModal = document.getElementById('companyDetailsModal');
        if (clientModal && clientModal.classList.contains('active')) {
            closeModal('clientModal');
        } else if (detailsModal && detailsModal.classList.contains('active')) {
            closeModal('companyDetailsModal');
        }
        closeMobileSidebar();
        if (profileToggle) profileToggle.classList.remove('open');
        if (profileMenu) profileMenu.classList.remove('open');
    }
});

// =============================================
// 5. CREATE CLIENT
// =============================================
function openCreateModal() {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const clientId = document.getElementById('clientId');
    const submitBtnText = document.getElementById('submitBtnText');
    const clientForm = document.getElementById('clientForm');
    const clientStatus = document.getElementById('clientStatus');
    
    if (modalTitle) modalTitle.textContent = 'Create New Client';
    if (formAction) formAction.value = 'create_client';
    if (clientId) clientId.value = '0';
    if (submitBtnText) submitBtnText.textContent = 'Create Client';
    if (clientForm) clientForm.reset();
    if (clientStatus) clientStatus.value = '1';
    openModal('clientModal');
}

// =============================================
// 6. EDIT CLIENT - FIXED
// =============================================
function editClient(id) {
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const clientId = document.getElementById('clientId');
    const submitBtnText = document.getElementById('submitBtnText');
    
    if (modalTitle) modalTitle.textContent = 'Edit Client';
    if (formAction) formAction.value = 'update_client';
    if (clientId) clientId.value = id;
    if (submitBtnText) submitBtnText.textContent = 'Update Client';

    const formData = new FormData();
    formData.append('action', 'get_client');
    formData.append('client_id', id);

    fetch('clients.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const c = data.client;
            document.getElementById('companyName').value = c.company_name || '';
            document.getElementById('industry').value = c.industry || '';
            document.getElementById('companySize').value = c.company_size || '';
            document.getElementById('contactPerson').value = c.contact_person || '';
            document.getElementById('clientEmail').value = c.email || '';
            document.getElementById('clientPhone').value = c.contact_phone || '';
            document.getElementById('clientAddress').value = c.address || '';
            document.getElementById('clientNotes').value = c.notes || '';
            
            const statusValue = c.is_active !== undefined && c.is_active !== null ? String(c.is_active) : '1';
            document.getElementById('clientStatus').value = statusValue;
            
            console.log('Setting status to:', statusValue);
            
            openModal('clientModal');
        } else {
            showToast(data.error || 'Failed to load client.', 'error');
        }
    })
    .catch(error => {
        console.error('Edit error:', error);
        showToast('Error loading client details.', 'error');
    });
}

// =============================================
// 7. SUBMIT CLIENT - FIXED with better error handling
// =============================================
function submitClient(event) {
    event.preventDefault();
    
    const form = document.getElementById('clientForm');
    if (!form) {
        showToast('Form not found', 'error');
        return;
    }
    
    const formData = new FormData(form);
    
    const companyName = document.getElementById('companyName');
    const contactPerson = document.getElementById('contactPerson');
    const email = document.getElementById('clientEmail');
    
    if (!companyName || !companyName.value.trim() || !contactPerson || !contactPerson.value.trim() || !email || !email.value.trim()) {
        showToast('Company name, contact person, and email are required.', 'error');
        return;
    }
    
    console.log('Submitting form data:');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    const btn = document.getElementById('submitBtn');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block; width:1rem; height:1rem; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Saving...';

    fetch('clients.php', {
        method: 'POST',
        body: formData,
        headers: { 
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(response) {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.text();
    })
    .then(function(text) {
        console.log('Server response:', text);
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Failed to parse JSON. Response was:', text.substring(0, 500));
            throw new Error('Server returned invalid JSON. Please check server logs.');
        }
    })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            showToast(data.message || 'Client saved successfully!', 'success');
            closeModal('clientModal');
            setTimeout(function() {
                location.reload();
            }, 1500);
        } else {
            showToast(data.error || 'Failed to save client.', 'error');
        }
    })
    .catch(function(error) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        console.error('Submit error:', error);
        showToast('Error saving client: ' + error.message, 'error');
    });
}

// =============================================
// 8. VIEW COMPANY DETAILS WITH APPLICANTS
// =============================================
function viewCompanyDetails(id) {
    openModal('companyDetailsModal');
    
    const loading = document.getElementById('companyDetailsLoading');
    const content = document.getElementById('companyDetailsContent');
    const title = document.getElementById('companyDetailsTitle');
    
    if (loading) loading.style.display = 'block';
    if (content) {
        content.style.display = 'none';
        content.innerHTML = '';
    }
    if (title) title.textContent = 'Loading...';

    const formData = new FormData();
    formData.append('action', 'get_company_details');
    formData.append('client_id', id);

    fetch('clients.php', {
        method: 'POST',
        body: formData,
        headers: { 
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(async function(response) {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Server returned HTML instead of JSON:', text.substring(0, 200));
            throw new Error('Server error: ' + text.substring(0, 100));
        }
    })
    .then(function(data) {
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        if (data.success) {
            const c = data.company;
            const jobs = data.jobs || [];
            const applicants = data.applicants || [];
            const statusCounts = data.status_counts || {};
            
            if (title) title.textContent = c.company_name + ' - Company Profile';
            
            let html = '';
            
            html += `
                <div class="company-profile-header">
                    <div class="company-logo">
                        ${c.profile_picture ? `<img src="../../${c.profile_picture}" alt="${c.company_name}">` : c.company_name.charAt(0).toUpperCase()}
                    </div>
                    <div class="company-info">
                        <h2>${escapeHtml(c.company_name)}</h2>
                        <div class="sub-info">
                            <span><span class="material-symbols-outlined" style="font-size:1rem;">email</span> ${escapeHtml(c.user_email)}</span>
                            ${c.contact_phone ? `<span><span class="material-symbols-outlined" style="font-size:1rem;">phone</span> ${escapeHtml(c.contact_phone)}</span>` : ''}
                            ${c.industry ? `<span><span class="material-symbols-outlined" style="font-size:1rem;">category</span> ${escapeHtml(c.industry)}</span>` : ''}
                            ${c.company_size ? `<span><span class="material-symbols-outlined" style="font-size:1rem;">group</span> ${escapeHtml(c.company_size)}</span>` : ''}
                        </div>
                        ${c.address ? `<div style="margin-top:0.25rem; font-size:0.8125rem; color:var(--text-on-surface-variant);"><span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">location_on</span> ${escapeHtml(c.address)}</div>` : ''}
                        <div style="margin-top:0.25rem; font-size:0.75rem; color:var(--text-on-surface-variant);">
                            <span class="badge ${c.is_active == 1 ? 'badge-active' : 'badge-inactive'}">${c.is_active == 1 ? 'Active' : 'Inactive'}</span>
                            <span style="margin-left:0.5rem;">Joined: ${new Date(c.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</span>
                        </div>
                    </div>
                </div>
            `;
            
            html += `
                <div class="company-stats-grid">
                    <div class="company-stat-card">
                        <div class="number">${data.total_jobs}</div>
                        <div class="label">Total Jobs</div>
                    </div>
                    <div class="company-stat-card">
                        <div class="number">${data.total_applicants}</div>
                        <div class="label">Total Applicants</div>
                    </div>
                    <div class="company-stat-card">
                        <div class="number">${statusCounts.pending || 0}</div>
                        <div class="label">Pending</div>
                    </div>
                    <div class="company-stat-card">
                        <div class="number">${statusCounts.reviewing || 0}</div>
                        <div class="label">Reviewing</div>
                    </div>
                    <div class="company-stat-card">
                        <div class="number">${statusCounts.interview_scheduled || 0}</div>
                        <div class="label">Interviews</div>
                    </div>
                    <div class="company-stat-card">
                        <div class="number">${statusCounts.hired || 0}</div>
                        <div class="label">Hired</div>
                    </div>
                </div>
            `;
            
            html += `
                <div class="tab-bar">
                    <button class="tab-btn active" data-tab="applicants">Applicants (${applicants.length})</button>
                    <button class="tab-btn" data-tab="jobs">Job Orders (${jobs.length})</button>
                </div>
            `;
            
            html += `<div class="tab-content active" id="tab-applicants">`;
            if (applicants.length === 0) {
                html += `<div class="empty-state" style="padding:1.5rem;">
                            <span class="material-symbols-outlined" style="font-size:2rem;">person_search</span>
                            <h4>No Applicants Yet</h4>
                            <p>This company hasn't received any applications yet.</p>
                        </div>`;
            } else {
                html += `<div style="max-height:400px; overflow-y:auto;">`;
                applicants.forEach(app => {
                    const appStatus = app.application_status || 'pending';
                    const statusBadges = {
                        'pending': 'badge-pending',
                        'reviewing': 'badge-reviewing',
                        'interview_scheduled': 'badge-interview_scheduled',
                        'interviewed': 'badge-interviewed',
                        'offered': 'badge-offered',
                        'hired': 'badge-hired',
                        'rejected': 'badge-rejected'
                    };
                    const statusLabels = {
                        'pending': 'Pending',
                        'reviewing': 'Reviewing',
                        'interview_scheduled': 'Interview Scheduled',
                        'interviewed': 'Interviewed',
                        'offered': 'Offered',
                        'hired': 'Hired',
                        'rejected': 'Rejected'
                    };
                    const badgeClass = statusBadges[appStatus] || 'badge-pending';
                    const label = statusLabels[appStatus] || appStatus;
                    
                    html += `
                        <div class="applicant-card">
                            <div class="avatar">
                                ${app.user_profile_picture ? `<img src="../../${app.user_profile_picture}" alt="${escapeHtml(app.first_name)}">` : escapeHtml((app.first_name || 'A').charAt(0).toUpperCase())}
                            </div>
                            <div class="info">
                                <div class="name">${escapeHtml(app.first_name + ' ' + app.last_name)}</div>
                                <div class="details">
                                    <span>${escapeHtml(app.email || '')}</span>
                                    ${app.phone ? `<span>${escapeHtml(app.phone)}</span>` : ''}
                                    <span class="job-title">Applied for: ${escapeHtml(app.job_title || 'Unknown')}</span>
                                </div>
                                <div style="font-size:0.625rem; color:var(--text-on-surface-variant);">
                                    Applied: ${new Date(app.applied_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                </div>
                            </div>
                            <div class="status-badge">
                                <span class="badge ${badgeClass}">${label}</span>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }
            html += `</div>`;
            
            html += `<div class="tab-content" id="tab-jobs">`;
            if (jobs.length === 0) {
                html += `<div class="empty-state" style="padding:1.5rem;">
                            <span class="material-symbols-outlined" style="font-size:2rem;">work_off</span>
                            <h4>No Jobs Posted</h4>
                            <p>This company hasn't posted any jobs yet.</p>
                        </div>`;
            } else {
                html += `<div style="max-height:400px; overflow-y:auto;">`;
                jobs.forEach(job => {
                    const jobStatus = job.status || 'closed';
                    const statusBadges = {
                        'open': 'badge-active',
                        'ongoing': 'badge-reviewing',
                        'on_hold': 'badge-pending',
                        'closed': 'badge-inactive'
                    };
                    const statusLabels = {
                        'open': 'Open',
                        'ongoing': 'Ongoing',
                        'on_hold': 'On Hold',
                        'closed': 'Closed'
                    };
                    html += `
                        <div style="padding:0.625rem; border:1px solid var(--slate-200); border-radius:var(--radius-md); margin-bottom:0.5rem;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:0.5rem;">
                                <div>
                                    <div style="font-weight:600;">${escapeHtml(job.title)}</div>
                                    <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                        ${job.applicant_count || 0} applicants · Posted: ${new Date(job.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                    </div>
                                </div>
                                <span class="badge ${statusBadges[jobStatus] || 'badge-inactive'}">${statusLabels[jobStatus] || jobStatus}</span>
                            </div>
                            ${job.description ? `<div style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-top:0.25rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">${escapeHtml(job.description)}</div>` : ''}
                        </div>
                    `;
                });
                html += `</div>`;
            }
            html += `</div>`;
            
            content.innerHTML = html;
            
            setTimeout(function() {
                const tabBtns = document.querySelectorAll('.tab-btn');
                if (tabBtns.length > 0) {
                    tabBtns.forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            document.querySelectorAll('.tab-btn').forEach(function(b) {
                                b.classList.remove('active');
                            });
                            document.querySelectorAll('.tab-content').forEach(function(t) {
                                t.classList.remove('active');
                            });
                            this.classList.add('active');
                            const tabId = this.dataset.tab;
                            const targetContent = document.getElementById('tab-' + tabId);
                            if (targetContent) {
                                targetContent.classList.add('active');
                            }
                        });
                    });
                }
            }, 100);
            
        } else {
            content.innerHTML = `
                <div style="text-align:center; padding:1rem; color:#dc2626;">
                    <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                    <p style="margin-top:0.5rem;">${data.error || 'Failed to load company details.'}</p>
                </div>
            `;
        }
    })
    .catch(function(error) {
        console.error('Error loading company details:', error);
        if (loading) loading.style.display = 'none';
        if (content) {
            content.style.display = 'block';
            content.innerHTML = `
                <div style="text-align:center; padding:1rem; color:#dc2626;">
                    <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                    <p style="margin-top:0.5rem;">Error loading company details. Please try again.</p>
                    <p style="font-size:0.75rem; color:var(--text-on-surface-variant);">${error.message || 'Unknown error'}</p>
                </div>
            `;
        }
    });
}

// =============================================
// 9. DELETE CLIENT
// =============================================
function deleteClient(id) {
    if (!confirm('Are you sure you want to delete this client? This will also delete the user account and all associated data. This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_client');
    formData.append('client_id', id);

    showToast('Deleting client...', 'info');

    fetch('clients.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to delete client.', 'error');
        }
    })
    .catch(error => {
        showToast('Error deleting client.', 'error');
    });
}

// =============================================
// 10. SEARCH & FILTERS
// =============================================
function applyFilters() {
    const search = document.getElementById('searchInput');
    if (!search) return;
    
    const status = '<?php echo $statusFilter; ?>';
    let url = 'clients.php?';
    if (status !== 'all') url += 'status=' + status + '&';
    if (search.value) url += 'search=' + encodeURIComponent(search.value);
    window.location.href = url;
}

const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
}

// =============================================
// 11. TOAST SYSTEM
// =============================================
function showToast(message, type) {
    type = type || 'info';
    const existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();

    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info' };
    toast.innerHTML = `<span class="material-symbols-outlined">${iconMap[type] || 'info'}</span> ${message}`;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.transition = 'all 0.4s ease';
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}

// =============================================
// 12. UTILITY FUNCTIONS
// =============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// 13. RESPONSIVE HANDLING
// =============================================
let resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
        const width = window.innerWidth;
        if (width <= 768) {
            sidebar.classList.remove('collapsed');
        } else {
            sidebar.classList.remove('mobile-open');
            sidebarBackdrop.classList.remove('active');
            document.body.style.overflow = '';
            const saved = localStorage.getItem('sidebarCollapsed');
            if (saved === 'true') {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
    }, 250);
});

console.log('🏢 ISMERS Enhanced Clients Management loaded successfully!');
</script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>
