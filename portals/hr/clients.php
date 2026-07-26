<?php
// portals/hr/clients.php - Client Management with PHPMailer (FIXED PATH)
session_start();

require_once '../../app/config.php';
require_once 'includes/functions.php';

// =============================================
// INCLUDE PHPMailer - FIXED PATH
// =============================================
// Path: from portals/hr/ to root/PHPMailer-master/src/
require_once '../../PHPMailer-master/src/Exception.php';
require_once '../../PHPMailer-master/src/PHPMailer.php';
require_once '../../PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Only HR Manager can manage clients
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
// PHPMailer Function - FIXED
// =============================================
function sendClientWelcomeEmail($to, $name, $tempPassword, $companyName) {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings - using your config values
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Set to DEBUG_SERVER for testing
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to, $name);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Welcome to ISMERS - Your Client Account";
        
        $loginUrl = SITE_URL . "portals/client/login.php";
        $resetUrl = SITE_URL . "forgot_password.php";
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #1b1b24; background: #f8f7fc; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #ffffff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.06); }
                .header { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); padding: 30px; text-align: center; color: white; border-radius: 16px 16px 0 0; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 800; letter-spacing: -0.5px; }
                .header p { margin: 8px 0 0; opacity: 0.85; font-size: 14px; }
                .content { padding: 30px; background: #ffffff; border-radius: 0 0 16px 16px; border: 1px solid #e2e8f0; border-top: none; }
                .content h2 { color: #1b1b24; font-size: 22px; margin-top: 0; }
                .content p { color: #4a5168; font-size: 15px; }
                .credentials-box { background: #f5f3ff; padding: 24px; border-radius: 12px; margin: 20px 0; border: 1px solid #e0d7ff; }
                .credentials-box p { margin: 8px 0; }
                .credentials-box .label { font-weight: 600; color: #4a5168; }
                .credentials-box .value { font-weight: 700; color: #1b1b24; }
                .credentials-box .password-code { 
                    background: #1e293b; 
                    color: #e2e8f0; 
                    padding: 4px 14px; 
                    border-radius: 6px; 
                    font-family: 'Courier New', monospace; 
                    font-size: 18px; 
                    font-weight: 700;
                    letter-spacing: 1px;
                    display: inline-block;
                }
                .btn { 
                    display: inline-block; 
                    padding: 14px 32px; 
                    background: #4f46e5; 
                    color: white !important; 
                    text-decoration: none; 
                    border-radius: 10px; 
                    font-weight: 600; 
                    font-size: 16px;
                }
                .btn:hover { background: #4338ca; }
                .footer { text-align: center; padding: 20px; font-size: 13px; color: #94a3b8; border-top: 1px solid #e2e8f0; margin-top: 20px; }
                .footer a { color: #4f46e5; text-decoration: none; }
                .warning { background: #fef3c7; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #f59e0b; margin: 16px 0; }
                .warning p { margin: 0; font-size: 14px; color: #92400e; }
                .divider { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <div class=\"header\">
                    <h1>🏢 ISMERS</h1>
                    <p>Service Management & Enterprise Resource System</p>
                </div>
                <div class=\"content\">
                    <h2>Welcome, " . htmlspecialchars($name) . "!</h2>
                    <p>Your client account for <strong>" . htmlspecialchars($companyName) . "</strong> has been created successfully. You can now access the ISMERS platform to manage your job orders and candidates.</p>
                    
                    <div class=\"credentials-box\">
                        <p><span class=\"label\">📧 Email:</span> <span class=\"value\">" . htmlspecialchars($to) . "</span></p>
                        <p><span class=\"label\">🔑 Temporary Password:</span> <span class=\"password-code\">" . htmlspecialchars($tempPassword) . "</span></p>
                    </div>
                    
                    <div class=\"warning\">
                        <p>⚠️ <strong>Important:</strong> For security reasons, please change your password upon first login.</p>
                    </div>
                    
                    <p style=\"text-align: center; margin: 28px 0;\">
                        <a href=\"" . $loginUrl . "\" class=\"btn\">🔐 Login to ISMERS</a>
                    </p>
                    
                    <p>You can also reset your password anytime using the <a href=\"" . $resetUrl . "\">Forgot Password</a> feature.</p>
                    
                    <hr class=\"divider\">
                    
                    <p style=\"font-size: 14px; color: #64748b;\">
                        <strong>Company:</strong> " . htmlspecialchars($companyName) . "<br>
                        <strong>Contact Person:</strong> " . htmlspecialchars($name) . "
                    </p>
                </div>
                <div class=\"footer\">
                    <p>This is an automated message from ISMERS. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact our support team at <a href=\"mailto:" . MAIL_REPLY_TO . "\">" . MAIL_REPLY_TO . "</a></p>
                    <p style=\"margin-top: 8px; font-size: 12px;\">&copy; " . date('Y') . " ISMERS. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Plain text alternative
        $mail->AltBody = "
        Welcome to ISMERS, " . $name . "!
        
        Your client account for " . $companyName . " has been created successfully.
        
        Your Login Credentials:
        Email: " . $to . "
        Temporary Password: " . $tempPassword . "
        
        IMPORTANT: Please change your password upon first login.
        
        Login URL: " . $loginUrl . "
        
        You can also reset your password anytime using the Forgot Password feature.
        ";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        // Log the error but don't show it to user
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// =============================================
// GET ALL CLIENTS
// =============================================
$conditions = [];
$params = [];
$types = "";

// FIX: Use is_active instead of status
if ($statusFilter !== 'all') {
    if ($statusFilter === 'active') {
        $conditions[] = "c.is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $conditions[] = "c.is_active = 0";
    }
}

if (!empty($searchQuery)) {
    $conditions[] = "(c.company_name LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// FIX: Use correct column names from your table
$sql = "SELECT c.*, 
        u.id as user_id, u.email as user_email, u.first_name, u.last_name,
        u.created_at as user_created_at,
        (SELECT COUNT(*) FROM job_orders WHERE client_id = c.id) as total_jobs,
        (SELECT COUNT(*) FROM applications a 
         JOIN job_orders jo ON a.job_order_id = jo.id 
         WHERE jo.client_id = c.id) as total_applications
        FROM clients c
        JOIN users u ON c.user_id = u.id
        $whereClause
        ORDER BY c.created_at DESC";

$clients = getRecords($sql, $params, $types);

// Get status counts (based on is_active)
$statusCounts = ['all' => count($clients)];
$activeCount = 0;
$inactiveCount = 0;

foreach ($clients as $client) {
    if ($client['is_active'] == 1) {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

$statusCounts['active'] = $activeCount;
$statusCounts['inactive'] = $inactiveCount;
$statusCounts['prospect'] = 0;

$allStatuses = [
    'all' => 'All',
    'active' => 'Active',
    'inactive' => 'Inactive'
];

// Status badge mapping - FIX: Use is_active
$statusBadges = [
    'active' => 'badge-active',
    'inactive' => 'badge-inactive'
];

$statusLabels = [
    'active' => 'Active',
    'inactive' => 'Inactive'
];

// Industries list
$industries = [
    'Technology & Software',
    'Information Technology',
    'BFSI (Banking, Financial Services, Insurance)',
    'Healthcare & Pharmaceuticals',
    'Retail & E-commerce',
    'Manufacturing & Industrial',
    'Real Estate & Construction',
    'Education & Training',
    'Hospitality & Tourism',
    'Transportation & Logistics',
    'Media & Entertainment',
    'Telecommunications',
    'Energy & Utilities',
    'Consulting & Professional Services',
    'Non-Profit & NGO',
    'Government & Public Sector',
    'Other'
];

// =============================================
// AJAX HANDLER
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    
    if ($action === 'create_client') {
        $companyName = trim($_POST['company_name'] ?? '');
        $industry = trim($_POST['industry'] ?? '');
        $companySize = trim($_POST['company_size'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $zipCode = trim($_POST['zip_code'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $taxId = trim($_POST['tax_id'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        // Validate
        if (empty($companyName) || empty($email) || empty($contactPerson)) {
            echo json_encode(['success' => false, 'error' => 'Company name, email, and contact person are required.']);
            exit;
        }
        
        // Check if email already exists
        $existing = getRecord("SELECT id FROM users WHERE email = ?", [$email], "s");
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'This email is already registered.']);
            exit;
        }
        
        // Check if company already exists
        $existingCompany = getRecord("SELECT id FROM clients WHERE company_name = ?", [$companyName], "s");
        if ($existingCompany) {
            echo json_encode(['success' => false, 'error' => 'This company name is already registered.']);
            exit;
        }
        
     // Generate temporary password
$tempPassword = generatePassword(10);
// Use PASSWORD_DEFAULT explicitly
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        // STEP 1: Create user account
        $userData = [
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'client',
            'full_name' => $contactPerson,
            'first_name' => trim(strtok($contactPerson, ' ')),
            'last_name' => trim(strstr($contactPerson, ' ') ?: ''),
        ];
        $newUserId = createUser($userData);
        
        if (!$newUserId) {
            echo json_encode(['success' => false, 'error' => 'Failed to create user account.']);
            exit;
        }
        
        // STEP 2: Create client profile
        $clientSql = "INSERT INTO clients (
            user_id, company_name, industry, company_size, contact_person, 
            contact_phone, website, address, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $clientResult = insertRecord($clientSql, [
            $newUserId,
            $companyName,
            $industry,
            $companySize,
            $contactPerson,
            $phone,
            $website,
            $address,
            $isActive
        ], "isssssssi");
        
        if (!$clientResult) {
            // Rollback - delete user if client creation fails
            deleteRecord("DELETE FROM users WHERE id = ?", [$newUserId], "i");
            echo json_encode(['success' => false, 'error' => 'Failed to create client profile.']);
            exit;
        }
        
        // STEP 3: Send welcome email using PHPMailer
        $emailSent = sendClientWelcomeEmail($email, $contactPerson, $tempPassword, $companyName);
        
        // STEP 4: Log activity
        logActivity($userId, 'Client Created', 'clients', $clientResult, 'Created client: ' . $companyName);
        
        $message = 'Client created successfully!';
        if ($emailSent) {
            $message .= ' Welcome email sent to ' . $email;
        } else {
            $message .= ' But email sending failed. Please send credentials manually.';
        }
        
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'temp_password' => $tempPassword // For debugging only
        ]);
        exit;
    }
    
    if ($action === 'update_client' && $clientId > 0) {
        $companyName = trim($_POST['company_name'] ?? '');
        $industry = trim($_POST['industry'] ?? '');
        $companySize = trim($_POST['company_size'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $zipCode = trim($_POST['zip_code'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $taxId = trim($_POST['tax_id'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        
        if (empty($companyName) || empty($email) || empty($contactPerson)) {
            echo json_encode(['success' => false, 'error' => 'Company name, email, and contact person are required.']);
            exit;
        }
        
        // Update client
        $sql = "UPDATE clients SET 
                company_name = ?,
                industry = ?,
                company_size = ?,
                contact_person = ?,
                contact_phone = ?,
                website = ?,
                address = ?,
                is_active = ?,
                updated_at = NOW()
                WHERE id = ?";
        
        $result = updateRecord($sql, [
            $companyName,
            $industry,
            $companySize,
            $contactPerson,
            $phone,
            $website,
            $address,
            $isActive,
            $clientId
        ], "sssssssii");
        
        if ($result) {
            // Update user email if changed
            $client = getRecord("SELECT user_id FROM clients WHERE id = ?", [$clientId], "i");
            if ($client) {
                updateRecord("UPDATE users SET email = ?, full_name = ? WHERE id = ?", [
                    $email,
                    $contactPerson,
                    $client['user_id']
                ], "ssi");
            }
            
            logActivity($userId, 'Client Updated', 'clients', $clientId, 'Updated client: ' . $companyName);
            echo json_encode(['success' => true, 'message' => 'Client updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update client.']);
        }
        exit;
    }
    
    if ($action === 'get_client' && $clientId > 0) {
        $client = getRecord("
            SELECT c.*, u.id as user_id, u.email as user_email, u.first_name, u.last_name
            FROM clients c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ", [$clientId], "i");
        
        if ($client) {
            // Convert is_active to status for the frontend
            $client['status'] = $client['is_active'] == 1 ? 'active' : 'inactive';
            echo json_encode(['success' => true, 'client' => $client]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Client not found.']);
        }
        exit;
    }
    
    if ($action === 'delete_client' && $clientId > 0) {
        $client = getRecord("SELECT company_name, user_id FROM clients WHERE id = ?", [$clientId], "i");
        if ($client) {
            // Delete client (user will also be deleted due to foreign key cascade)
            $sql = "DELETE FROM clients WHERE id = ?";
            $result = deleteRecord($sql, [$clientId], "i");
            if ($result) {
                // Also delete the user
                deleteRecord("DELETE FROM users WHERE id = ?", [$client['user_id']], "i");
                logActivity($userId, 'Client Deleted', 'clients', $clientId, 'Deleted client: ' . $client['company_name']);
                echo json_encode(['success' => true, 'message' => 'Client deleted successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete client.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Client not found.']);
        }
        exit;
    }
}
?>
<!-- HTML and CSS are the same as before (omitted for brevity) -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Clients - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           CLIENTS MANAGEMENT - PROFESSIONAL EDITION
           ========================================================================== */
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
           SIDEBAR
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
            box-shadow: var(--shadow-sm);
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
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.5rem;
        }
        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background: var(--primary-container);
            color: var(--primary);
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .sidebar-brand-icon .material-symbols-outlined { font-size: 1.5rem; }
        .sidebar-brand-text { font-size: 1rem; font-weight: 700; color: var(--slate-900); letter-spacing: -0.025em; }
        .sidebar-brand-category { font-size: 0.7rem; font-weight: 500; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.1rem; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 1rem 0.75rem; }
        .sidebar-nav .nav-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--slate-400);
            padding: 0.75rem 0.75rem 0.5rem;
        }
        .sidebar-main-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            margin-bottom: 0.125rem;
            font-family: var(--font-label);
            font-weight: 500;
            font-size: 0.875rem;
        }
        .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-main-link.active { background: var(--primary-container); color: var(--primary); }
        .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0.1rem 0.5rem;
            border-radius: 50px;
        }
        .sidebar-footer {
            padding: 0.75rem 0.75rem;
            border-top: 1px solid var(--slate-200);
        }
        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            background: var(--bg-surface-low);
        }
        .sidebar-footer .user-card .avatar {
            width: 2.25rem;
            height: 2.25rem;
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
        .sidebar-footer .user-card .user-info .user-name { font-size: 0.8125rem; font-weight: 600; color: var(--text-on-surface); }
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.6875rem; color: var(--text-on-surface-variant); }
        .sidebar-footer .logout-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            margin-top: 0.5rem;
            border-radius: 0.75rem;
            color: #dc2626;
            transition: all var(--transition-fast);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.8125rem;
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
        }
        .sidebar-footer .logout-btn:hover { background: #fef2f2; }
        .sidebar-footer .logout-btn .material-symbols-outlined { font-size: 1.125rem; }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(4px);
            z-index: 40;
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
        .profile-dropdown-menu .dropdown-header {
            padding: 0.25rem 0.75rem 0.25rem;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-on-surface-variant);
        }
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
        .stat-card .stat-number {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

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
        .filter-btn .count {
            background: rgba(0,0,0,0.08);
            border-radius: var(--radius-full);
            padding: 0 0.375rem;
            font-size: 0.625rem;
            font-weight: 700;
        }
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
        .card-header .count-badge {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface-low);
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
        }
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
        .client-cell .info .name { font-weight: 600; color: var(--text-on-surface); }
        .client-cell .info .contact { font-size: 0.6875rem; color: var(--text-on-surface-variant); }

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

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-sm); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-lg); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .search-bar { flex-direction: column; }
            .filters { overflow-x: auto; flex-wrap: nowrap; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
            .dashboard-sidebar.collapsed .sidebar-brand-text,
            .dashboard-sidebar.collapsed .sidebar-brand-category,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
            .dashboard-sidebar.collapsed .sidebar-footer .user-info {
                opacity: 1;
                width: auto;
                overflow: visible;
            }
            .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1.5rem; }
            .dashboard-sidebar.collapsed .sidebar-nav { padding: 1.5rem 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: flex-start; padding: 0.75rem 1rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; }
            .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: flex-start; padding: 0.5rem 0.75rem; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .page-header h1 { font-size: 1.25rem; }
            .stats-row { grid-template-columns: 1fr; }
            .stat-card .stat-number { font-size: 1.25rem; }
            table { font-size: 0.75rem; min-width: 500px; }
            table th, table td { padding: 0.375rem 0.5rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
    </style>
</head>
<body>

    <!-- Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">account_balance</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">HR Portal</p>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="clients.php" class="sidebar-main-link">
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
            </a>
            <a href="pipeline.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">view_kanban</span>
                <span class="nav-text">Pipeline</span>
            </a>
            <a href="interviews.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
            </a>
            <a href="offers.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Offers</span>
            </a>
            <a href="post_job.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">add_circle</span>
                <span class="nav-text">Post Job</span>
            </a>
            <div class="nav-label" style="margin-top:1rem;">System</div>
            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
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
        <header class="top-header">
            <div class="top-header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                    <span class="material-symbols-outlined">chevron_left</span>
                </button>
                <span class="separator">|</span>
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Clients</span>
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
                    <button class="dropdown-item" onclick="window.location.href='settings.php'">
                        <span class="material-symbols-outlined">settings</span> Settings
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
                                <p>
                                    <?php if ($statusFilter !== 'all'): ?>
                                        You don't have any <?php echo $statusFilter; ?> clients.
                                    <?php else: ?>
                                        No clients have been created yet.
                                    <?php endif; ?>
                                </p>
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
                                        <th>Jobs</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                               <?php foreach ($clients as $client): ?>
    <?php 
    $status = $client['is_active'] == 1 ? 'active' : 'inactive';
    ?>
    <tr>
        <td>
            <div class="client-cell">
                <span class="company-icon">
                    <?php echo strtoupper(substr($client['company_name'] ?? 'C', 0, 1)); ?>
                </span>
                <div class="info">
                    <div class="name"><?php echo htmlspecialchars($client['company_name']); ?></div>
                    <div class="contact"><?php echo htmlspecialchars($client['user_email']); ?></div>
                    <!-- Change 'email' to 'user_email' -->
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
                <?php echo $client['total_jobs'] ?? 0; ?>
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
                <button class="btn btn-primary btn-sm" onclick="viewClient(<?php echo $client['id']; ?>)">
                    <span class="material-symbols-outlined">visibility</span>
                </button>
                <button class="btn btn-outline btn-sm" onclick="editClient(<?php echo $client['id']; ?>)">
                    <span class="material-symbols-outlined">edit</span>
                </button>
                <button class="btn btn-danger btn-sm" onclick="deleteClient(<?php echo $client['id']; ?>)">
                    <span class="material-symbols-outlined">delete</span>
                </button>
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
    MODAL: Create/Edit Client
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
                    
                    <!-- Company Information -->
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
                                <option value="1-10">1-10 employees</option>
                                <option value="11-50">11-50 employees</option>
                                <option value="51-200">51-200 employees</option>
                                <option value="201-500">201-500 employees</option>
                                <option value="501-1000">501-1000 employees</option>
                                <option value="1000+">1000+ employees</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Contact Information -->
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
                    
                    <!-- Address -->
                    <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin:1rem 0 0.75rem;">
                        <div style="font-size:0.75rem; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:0.05em;">Address</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" id="clientAddress" name="address" class="form-control" placeholder="Street address">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" id="clientCity" name="city" class="form-control" placeholder="City">
                        </div>
                        <div class="form-group">
                            <label>Province</label>
                            <input type="text" id="clientProvince" name="province" class="form-control" placeholder="Province">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>ZIP Code</label>
                        <input type="text" id="clientZip" name="zip_code" class="form-control" placeholder="ZIP code">
                    </div>
                    
                    <!-- Additional Information -->
                    <div style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin:1rem 0 0.75rem;">
                        <div style="font-size:0.75rem; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:0.05em;">Additional Information</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Website</label>
                            <input type="url" id="clientWebsite" name="website" class="form-control" placeholder="https://www.company.com">
                        </div>
                        <div class="form-group">
                            <label>Tax ID</label>
                            <input type="text" id="clientTaxId" name="tax_id" class="form-control" placeholder="Tax identification number">
                        </div>
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
    MODAL: View Client Details
    ============================================= -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">visibility</span>
                    Client Details
                </h2>
                <button class="modal-close" onclick="closeModal('viewModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="loading-spinner" id="viewLoading">
                    <div style="text-align:center; padding:1.5rem;">
                        <div style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div>
                        <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading...</p>
                    </div>
                </div>
                <div id="viewContent" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- =============================================
    JAVASCRIPT
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

        mobileMenuBtn.addEventListener('click', openMobileSidebar);
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);

        // =============================================
        // 3. PROFILE DROPDOWN
        // =============================================
        const profileToggle = document.getElementById('profileToggle');
        const profileMenu = document.getElementById('profileMenu');

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

        // =============================================
        // 4. MODAL FUNCTIONS
        // =============================================
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            if (!id) id = 'clientModal';
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
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
                const viewModal = document.getElementById('viewModal');
                if (clientModal.classList.contains('active')) {
                    closeModal('clientModal');
                } else if (viewModal.classList.contains('active')) {
                    closeModal('viewModal');
                }
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // 5. CREATE CLIENT
        // =============================================
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create New Client';
            document.getElementById('formAction').value = 'create_client';
            document.getElementById('clientId').value = '0';
            document.getElementById('submitBtnText').textContent = 'Create Client';
            document.getElementById('clientForm').reset();
            document.getElementById('clientStatus').value = '1';
            openModal('clientModal');
        }

        // =============================================
        // 6. EDIT CLIENT
        // =============================================
        function editClient(id) {
            document.getElementById('modalTitle').textContent = 'Edit Client';
            document.getElementById('formAction').value = 'update_client';
            document.getElementById('clientId').value = id;
            document.getElementById('submitBtnText').textContent = 'Update Client';

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
                    document.getElementById('clientCity').value = c.city || '';
                    document.getElementById('clientProvince').value = c.province || '';
                    document.getElementById('clientZip').value = c.zip_code || '';
                    document.getElementById('clientWebsite').value = c.website || '';
                    document.getElementById('clientTaxId').value = c.tax_id || '';
                    document.getElementById('clientNotes').value = c.notes || '';
                    document.getElementById('clientStatus').value = c.is_active || 1;
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
        // 7. SUBMIT CLIENT
        // =============================================
        function submitClient(event) {
            event.preventDefault();
            
            const form = document.getElementById('clientForm');
            const formData = new FormData(form);
            
            // Validate
            const companyName = document.getElementById('companyName').value.trim();
            const contactPerson = document.getElementById('contactPerson').value.trim();
            const email = document.getElementById('clientEmail').value.trim();
            
            if (!companyName || !contactPerson || !email) {
                showToast('Company name, contact person, and email are required.', 'error');
                return;
            }
            
            const btn = document.getElementById('submitBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span style="display:inline-block; width:1rem; height:1rem; border:2px solid white; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Saving...';

            fetch('clients.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('clientModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to save client.', 'error');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                showToast('Error saving client. Please try again.', 'error');
            });
        }

        // =============================================
        // 8. VIEW CLIENT
        // =============================================
        function viewClient(id) {
            openModal('viewModal');
            
            const loading = document.getElementById('viewLoading');
            const content = document.getElementById('viewContent');
            
            loading.style.display = 'block';
            content.style.display = 'none';

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
                loading.style.display = 'none';
                content.style.display = 'block';

                if (data.success) {
                    const c = data.client;
                    const statusBadges = <?php echo json_encode($statusBadges); ?>;
                    const statusLabels = <?php echo json_encode($statusLabels); ?>;
                    const status = c.is_active == 1 ? 'active' : 'inactive';
                    
                    content.innerHTML = `
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                            <div style="grid-column:1/-1; background:var(--primary-container); padding:0.75rem 1rem; border-radius:0.5rem; text-align:center;">
                                <div style="font-size:1.25rem; font-weight:700; color:var(--primary);">${escapeHtml(c.company_name)}</div>
                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(c.industry || 'Industry not specified')}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Contact Person</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(c.contact_person)}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Email</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(c.email)}</div>
                            </div>
                            ${c.contact_phone ? `
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Phone</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(c.contact_phone)}</div>
                            </div>
                            ` : ''}
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Status</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">
                                    <span class="badge ${statusBadges[status] || 'badge-active'}">${statusLabels[status] || status}</span>
                                </div>
                            </div>
                            ${c.company_size ? `
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Company Size</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(c.company_size)} employees</div>
                            </div>
                            ` : ''}
                            ${c.address ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Address</div>
                                <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(c.address)}${c.city ? ', ' + escapeHtml(c.city) : ''}${c.province ? ', ' + escapeHtml(c.province) : ''}${c.zip_code ? ' ' + escapeHtml(c.zip_code) : ''}</div>
                            </div>
                            ` : ''}
                            ${c.website ? `
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Website</div>
                                <a href="${escapeHtml(c.website)}" target="_blank" style="color:var(--primary); text-decoration:underline;">${escapeHtml(c.website)}</a>
                            </div>
                            ` : ''}
                            ${c.tax_id ? `
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Tax ID</div>
                                <div style="font-weight:600; font-size:0.9375rem; margin-top:0.0625rem;">${escapeHtml(c.tax_id)}</div>
                            </div>
                            ` : ''}
                            ${c.notes ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Notes</div>
                                <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(c.notes)}</div>
                            </div>
                            ` : ''}
                            <div style="grid-column:1/-1; border-top:1px solid var(--slate-200); padding-top:0.75rem; display:flex; gap:1rem; flex-wrap:wrap;">
                                <div><span style="font-size:0.625rem; color:var(--text-on-surface-variant);">Created:</span> ${new Date(c.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                                ${c.updated_at ? `<div><span style="font-size:0.625rem; color:var(--text-on-surface-variant);">Updated:</span> ${new Date(c.updated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>` : ''}
                            </div>
                        </div>
                    `;
                } else {
                    content.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${data.error || 'Failed to load client details.'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('View error:', error);
                loading.style.display = 'none';
                content.style.display = 'block';
                content.innerHTML = `
                    <div style="text-align:center; padding:1rem; color:#dc2626;">
                        <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                        <p style="margin-top:0.5rem;">Error loading client details. Please try again.</p>
                    </div>
                `;
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
            const search = document.getElementById('searchInput').value;
            const status = '<?php echo $statusFilter; ?>';
            let url = 'clients.php?';
            if (status !== 'all') url += 'status=' + status + '&';
            if (search) url += 'search=' + encodeURIComponent(search);
            window.location.href = url;
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

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

        console.log('🏢 ISMERS Clients Management loaded successfully!');
    </script>

</body>
</html>