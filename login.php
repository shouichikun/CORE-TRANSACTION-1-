<?php
// login.php - ISMERS Login Page with System Account Detection
session_start();

// Include configuration
require_once 'app/config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'applicant';
    $redirects = [
        'admin' => 'portals/admin/dashboard.php',
        'hr_manager' => 'portals/hr/dashboard.php',
        'recruiter' => 'portals/hr/dashboard.php',
        'client' => 'portals/client/dashboard.php',
        'applicant' => 'portals/applicant/dashboard.php',
        'employee' => 'portals/employee/dashboard.php',
        'supervisor' => 'portals/supervisor/dashboard.php'
    ];
    header('Location: ' . ($redirects[$role] ?? 'index.php'));
    exit;
}

// Load PHPMailer for verification email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer-master/src/Exception.php';
require_once 'PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer-master/src/SMTP.php';

/**
 * Send verification email using PHPMailer
 */
function sendLoginVerificationEmail($toEmail, $toName, $code) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        
        $mail->isHTML(true);
        $mail->Subject = 'Login Verification Code - ISMERS';
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Login Verification</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f3ff; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
                .header { text-align: center; margin-bottom: 30px; }
                .logo { display: inline-block; background: #4f46e5; color: white; font-size: 24px; font-weight: 800; padding: 8px 20px; border-radius: 12px; }
                h1 { color: #1b1b24; font-size: 24px; margin-bottom: 8px; }
                p { color: #464555; font-size: 16px; line-height: 1.6; }
                .code-box { background: #f5f3ff; border: 2px dashed #4f46e5; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #4f46e5; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e8e5f0; color: #777587; font-size: 14px; }
                .warning { font-size: 14px; color: #92400e; background: #fef3c7; padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="logo">ISMERS</div>
                    <h1>Login Verification</h1>
                </div>
                <p>Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                <p>We received a login attempt for your ISMERS account. Please use the verification code below to complete your login:</p>
                <div class="code-box">' . $code . '</div>
                <div class="warning">
                    <strong>⚠️ This code will expire in 10 minutes.</strong>
                </div>
                <p>If you didn\'t try to log in, you can safely ignore this email.</p>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ISMERS. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "Hello " . $toName . ",\n\n" .
                         "We received a login attempt for your ISMERS account.\n\n" .
                         "Your verification code is: " . $code . "\n\n" .
                         "This code will expire in 10 minutes.\n\n" .
                         "If you didn't try to log in, please ignore this email.\n\n" .
                         "— ISMERS Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Login verification email failed: " . $e->getMessage());
        return false;
    }
}

// =============================================
// CHECK IF SYSTEM ACCOUNT
// =============================================
function isSystemAccount($email) {
    $systemDomains = ['@ismers.com', '@system.ismers.com'];
    foreach ($systemDomains as $domain) {
        if (strpos($email, $domain) !== false) {
            return true;
        }
    }
    return false;
}

// =============================================
// SYSTEM ACCOUNT ROLE CHECK
// =============================================
function getSystemAccountRole($email) {
    $systemRoles = [
        'admin@ismers.com' => 'admin',
        'hr_manager@ismers.com' => 'hr_manager',
        'recruiter@ismers.com' => 'recruiter',
        'applicant@ismers.com' => 'applicant',
        'employee@ismers.com' => 'employee',
        'supervisor@ismers.com' => 'supervisor',
        'client@ismers.com' => 'client'
    ];
    
    return $systemRoles[$email] ?? 'applicant';
}

// Handle login
$error = '';
$email = '';
$isSystemAccount = false;
$showModal = false;
$modalEmail = '';
$modalUserId = 0;
$modalFullName = '';
$modalCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        // Check if system account
        $isSystemAccount = isSystemAccount($email);
        
        // Get user from database
        $user = getUserByEmail($email);
        
        // If system account and user doesn't exist, create it
        if ($isSystemAccount && !$user) {
            // Create system account on the fly
            $role = getSystemAccountRole($email);
            $nameParts = explode('@', $email);
            $username = ucfirst(str_replace('_', ' ', $nameParts[0]));
            
            $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
            
            $sql = "INSERT INTO users (email, password_hash, role, full_name, first_name, last_name, is_active, is_verified, biometric_enabled, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, NOW())";
            
            $userId = insertRecord($sql, [
                $email,
                $passwordHash,
                $role,
                $username . ' User',
                $username,
                'User'
            ], "ssssss");
            
            if ($userId) {
                $user = getUserById($userId);
                
                // Log creation
                logActivity($userId, 'System Account Created', 'users', $userId, 'System account created for: ' . $email);
            }
        }
        
        // Verify user exists
        if (!$user) {
            $error = 'Account not found. Please check your email and try again.';
        } elseif (password_verify($password, $user['password_hash'])) {
            // Check if active
            if ($user['is_active'] == 0) {
                $error = 'Your account has been deactivated.';
            } else {
                // =============================================
                // SYSTEM ACCOUNT - Direct Login (No Verification)
                // =============================================
                if ($isSystemAccount) {
                    // Direct login for system accounts
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['is_system_account'] = true;
                    
                    updateLastLogin($user['id']);
                    $updateSql = "UPDATE users SET last_activity = NOW() WHERE id = ?";
                    updateRecord($updateSql, [$user['id']], "i");
                    
                    if ($remember) {
                        setcookie('remember_email', $email, time() + 86400 * 7, '/');
                    }
                    
                    // Log system login
                    logActivity($user['id'], 'System Account Login', 'users', $user['id'], 'System account login: ' . $email);
                    
                    $redirects = [
                        'admin' => 'portals/admin/dashboard.php',
                        'hr_manager' => 'portals/hr/dashboard.php',
                        'recruiter' => 'portals/hr/dashboard.php',
                        'client' => 'portals/client/dashboard.php',
                        'applicant' => 'portals/applicant/dashboard.php',
                        'employee' => 'portals/employee/dashboard.php',
                        'supervisor' => 'portals/supervisor/dashboard.php'
                    ];
                    header('Location: ' . ($redirects[$user['role']] ?? 'index.php'));
                    exit;
                }
                
                // =============================================
                // REGULAR USER - SHOW MODAL, SEND EMAIL
                // =============================================
                // Generate OTP code
                $code = sprintf("%06d", rand(100000, 999999));
                $expires = time() + 600; // 10 minutes
                
                // Store in session
                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_role'] = $user['role'];
                $_SESSION['temp_full_name'] = $user['full_name'];
                $_SESSION['temp_email'] = $user['email'];
                $_SESSION['temp_first_name'] = $user['first_name'];
                $_SESSION['verification_code'] = $code;
                $_SESSION['verification_expires'] = $expires;
                $_SESSION['remember_me'] = $remember;
                
                // Store in database
                $updateSql = "UPDATE users SET verification_code = ?, verification_expires = FROM_UNIXTIME(?) WHERE id = ?";
                updateRecord($updateSql, [$code, $expires, $user['id']], "sii");
                
                // Log OTP generated
                logActivity($user['id'], 'Login OTP Generated', 'users', $user['id'], 'OTP generated for: ' . $email);
                
                // Set modal data - SHOW IMMEDIATELY
                $showModal = true;
                $modalEmail = $email;
                $modalUserId = $user['id'];
                $modalFullName = $user['full_name'];
                $modalCode = $code;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

// Check for logout message
$logoutMessage = isset($_GET['logout']) && $_GET['logout'] === 'success' 
    ? 'You have been logged out successfully.' 
    : '';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Sign In - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - CLEAN VERSION
           ========================================================================== */
        :root {
            --bg-main: #f5f3ff;
            --bg-surface: #ffffff;
            --text-main: #1b1b24;
            --text-muted: #464555;
            --text-dim: #777587;
            --outline: #777587;
            --outline-variant: #c7c4d8;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --on-primary: #ffffff;
            --error: #dc2626;
            --error-bg: #fef2f2;
            --error-border: #fecaca;
            --success: #16a34a;
            --success-bg: #ecfdf5;
            --success-border: #bbf7d0;
            --shadow-xl: 0 20px 25px -5px rgba(27, 27, 36, 0.1), 0 10px 10px -5px rgba(27, 27, 36, 0.04);
            --radius-md: 0.5rem;
            --radius-xl: 1rem;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-label: 'Public Sans', system-ui, -apple-system, sans-serif;
            --transition-fast: 0.15s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg-main);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .auth-wrapper {
            width: 100%;
            max-width: 28rem;
        }

        .auth-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .auth-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        .auth-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            font-family: var(--font-label);
        }

        .message {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .message.hidden {
            display: none;
        }

        .message.success {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success);
        }

        .message.error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error);
        }

        .message .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.375rem;
            font-family: var(--font-label);
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper .input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--outline);
            pointer-events: none;
        }

        .input-wrapper .input-icon .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .input-wrapper input {
            width: 100%;
            padding: 0.625rem 0.75rem 0.625rem 2.5rem;
            border: 1px solid var(--outline-variant);
            border-radius: var(--radius-md);
            background: var(--bg-surface);
            color: var(--text-main);
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .input-wrapper input::placeholder {
            color: rgba(119, 117, 135, 0.5);
        }

        .toggle-password {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--outline);
            transition: color var(--transition-fast);
            padding: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: var(--text-main);
        }

        .toggle-password .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .form-options label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            cursor: pointer;
            font-family: var(--font-label);
        }

        .form-options input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            accent-color: var(--primary);
            cursor: pointer;
            border-radius: 0.25rem;
            border: 1px solid var(--outline-variant);
        }

        .form-options a {
            font-size: 0.875rem;
            color: var(--primary);
            font-weight: 500;
            transition: color var(--transition-fast);
            font-family: var(--font-label);
        }

        .form-options a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border: 1px solid transparent;
            border-radius: var(--radius-md);
            background: var(--primary);
            color: var(--on-primary);
            font-size: 0.875rem;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: background var(--transition-fast), transform var(--transition-fast);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.2);
        }

        .btn-login:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .auth-footer {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(199, 196, 216, 0.3);
            text-align: center;
        }

        .auth-footer a {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: color var(--transition-fast);
            font-family: var(--font-label);
        }

        .auth-footer a:hover {
            color: var(--primary);
        }

        .auth-footer a .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .signup-link {
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            font-family: var(--font-label);
        }

        .signup-link a {
            color: var(--primary);
            font-weight: 700;
            transition: color var(--transition-fast);
        }

        .signup-link a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .system-badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 0.125rem 0.75rem;
            border-radius: 50px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }

        /* =============================================
           VERIFICATION MODAL - AESTHETIC & MODERN
        ============================================= */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(27, 27, 36, 0.5);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            width: 100%;
            height: 100%;
            min-height: 100vh;
        }

        .modal-overlay.active {
            display: flex !important;
        }

        .modal-container {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            max-width: 420px;
            width: 100%;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            z-index: 10000;
            animation: modalSlideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from {
                transform: translateY(30px) scale(0.96);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .modal-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.12), rgba(79, 70, 229, 0.04));
            border-radius: 50%;
            margin: 0 auto 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .modal-icon .material-symbols-outlined {
            font-size: 2.25rem;
            color: var(--primary);
            animation: iconPulse 1.5s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.7;
            }
        }

        .modal-icon::before {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 2px solid rgba(79, 70, 229, 0.15);
            animation: ringPulse 2s ease-out infinite;
        }

        .modal-icon::after {
            content: '';
            position: absolute;
            inset: -14px;
            border-radius: 50%;
            border: 2px solid rgba(79, 70, 229, 0.08);
            animation: ringPulse 2s ease-out infinite 0.6s;
        }

        @keyframes ringPulse {
            0% {
                transform: scale(0.8);
                opacity: 1;
            }
            100% {
                transform: scale(1.4);
                opacity: 0;
            }
        }

        .success-icon {
            display: none;
            width: 72px;
            height: 72px;
            background: #22c55e;
            border-radius: 50%;
            margin: 0 auto 1.25rem;
            align-items: center;
            justify-content: center;
            animation: popIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .success-icon.show {
            display: flex;
        }

        @keyframes popIn {
            0% {
                transform: scale(0) rotate(-10deg);
                opacity: 0;
            }
            100% {
                transform: scale(1) rotate(0deg);
                opacity: 1;
            }
        }

        .success-icon .material-symbols-outlined {
            color: white;
            font-size: 2.25rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.375rem;
        }

        .modal-subtitle {
            font-size: 0.875rem;
            color: var(--text-muted);
            font-family: var(--font-label);
            line-height: 1.6;
        }

        .modal-email {
            display: inline-block;
            background: var(--bg-main);
            padding: 0.25rem 0.875rem;
            border-radius: 50px;
            font-weight: 600;
            color: var(--primary);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .progress-dots {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin: 1.25rem 0 0.75rem;
        }

        .progress-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--outline-variant);
            transition: all 0.4s ease;
        }

        .progress-dot.active {
            background: var(--primary);
            transform: scale(1.3);
            box-shadow: 0 0 12px rgba(79, 70, 229, 0.3);
        }

        .progress-dot.done {
            background: #22c55e;
            transform: scale(1);
        }

        .btn-continue {
            display: none;
            width: 100%;
            padding: 0.75rem 1rem;
            border: none;
            border-radius: var(--radius-md);
            background: var(--primary);
            color: var(--on-primary);
            font-size: 0.875rem;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: all var(--transition-fast);
            margin-top: 1.25rem;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-continue.show {
            display: flex;
        }

        .btn-continue:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.3);
        }

        .status-text {
            font-size: 0.75rem;
            color: var(--text-dim);
            font-family: var(--font-label);
            margin-top: 0.5rem;
            transition: all 0.3s ease;
        }

        .status-text.success {
            color: var(--success);
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 1.5rem;
            }
            .auth-header h1 {
                font-size: 1.25rem;
            }
            .form-options {
                flex-direction: column;
                gap: 0.75rem;
                align-items: flex-start;
            }
            .modal-container {
                padding: 1.75rem 1.25rem;
            }
            .modal-icon {
                width: 60px;
                height: 60px;
            }
            .modal-icon .material-symbols-outlined {
                font-size: 1.75rem;
            }
            .success-icon {
                width: 60px;
                height: 60px;
            }
            .success-icon .material-symbols-outlined {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>

<!-- =============================================
     VERIFICATION MODAL - AESTHETIC
     ============================================= -->
<div class="modal-overlay <?php echo $showModal ? 'active' : ''; ?>" id="verificationModal">
    <div class="modal-container">
        <div class="modal-icon" id="modalIcon">
            <span class="material-symbols-outlined">mail</span>
        </div>

        <div class="success-icon" id="successIcon">
            <span class="material-symbols-outlined">check</span>
        </div>

        <h2 class="modal-title" id="modalTitle">Sending Verification Code</h2>
        
        <p class="modal-subtitle">
            We're sending a 6-digit code to
            <br>
            <span class="modal-email"><?php echo htmlspecialchars($modalEmail); ?></span>
        </p>

        <div class="progress-dots" id="progressDots">
            <span class="progress-dot active" id="dot1"></span>
            <span class="progress-dot" id="dot2"></span>
            <span class="progress-dot" id="dot3"></span>
            <span class="progress-dot" id="dot4"></span>
        </div>

        <p class="status-text" id="statusText">Preparing your code...</p>

        <button class="btn-continue" id="continueBtn">
            <span class="material-symbols-outlined">arrow_forward</span>
            Continue to Verify
        </button>
    </div>
</div>

<!-- =============================================
     LOGIN FORM
     ============================================= -->
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1>Sign In</h1>
            <p>Access your account to continue.</p>
        </div>

        <div class="message success <?php echo empty($logoutMessage) ? 'hidden' : ''; ?>" id="successMessage">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo htmlspecialchars($logoutMessage); ?></span>
        </div>

        <div class="message error <?php echo empty($error) ? 'hidden' : ''; ?>" id="errorMessage">
            <span class="material-symbols-outlined">error</span>
            <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
        </div>

        <?php if (!empty($email) && isSystemAccount($email)): ?>
            <div style="background:#fef3c7; border:1px solid #fcd34d; border-radius:0.75rem; padding:0.75rem 1rem; margin-bottom:1rem; text-align:center;">
                <span style="font-size:0.875rem; color:#92400e;">
                    🔑 <strong>System Account</strong>
                    <br>
                    <span style="font-size:0.75rem;">Direct login enabled (no verification required)</span>
                </span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" novalidate>
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon">
                        <span class="material-symbols-outlined">mail</span>
                    </span>
                    <input type="email" id="email" name="email" placeholder="you@example.com" 
                           value="<?php echo htmlspecialchars($email); ?>" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <span class="input-icon">
                        <span class="material-symbols-outlined">lock</span>
                    </span>
                    <input type="password" id="password" name="password" placeholder="········" 
                           required minlength="6">
                    <button type="button" class="toggle-password" id="togglePassword" 
                            aria-label="Toggle password visibility">
                        <span class="material-symbols-outlined" id="eyeIcon">visibility</span>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label>
                    <input type="checkbox" name="remember" 
                           <?php echo isset($_COOKIE['remember_email']) ? 'checked' : ''; ?>>
                    Remember me
                </label>
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">
                <span>Sign In</span>
                <span class="material-symbols-outlined" style="font-size:1.25rem;">arrow_forward</span>
            </button>
        </form>

        <div class="signup-link">
            Don't have an account? <a href="portals/applicant/register.php">Get Started</a>
        </div>

        <div class="auth-footer">
            <a href="index.php">
                <span class="material-symbols-outlined">arrow_back</span>
                Back to Home
            </a>
        </div>
    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
    // =============================================
    // 1. PASSWORD TOGGLE
    // =============================================
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    let isVisible = false;

    togglePassword.addEventListener('click', function() {
        isVisible = !isVisible;
        passwordInput.type = isVisible ? 'text' : 'password';
        eyeIcon.textContent = isVisible ? 'visibility_off' : 'visibility';
    });

    // =============================================
    // 2. FORM SUBMISSION - FIXED
    // =============================================
    const form = document.getElementById('loginForm');
    const loginBtn = document.getElementById('loginBtn');
    const errorMsg = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');

    // Store original button HTML for reset
    const originalBtnHTML = loginBtn.innerHTML;

    function showError(message) {
        errorText.textContent = message;
        errorMsg.classList.remove('hidden');
        // Reset button
        loginBtn.disabled = false;
        loginBtn.innerHTML = originalBtnHTML;
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    form.addEventListener('submit', function(e) {
        // Clear previous errors
        errorMsg.classList.add('hidden');

        const email = document.getElementById('email').value.trim();
        const password = passwordInput.value.trim();

        // Validation
        if (!email) {
            e.preventDefault();
            showError('Please enter your email address.');
            document.getElementById('email').focus();
            return false;
        }

        if (!isValidEmail(email)) {
            e.preventDefault();
            showError('Please enter a valid email address.');
            document.getElementById('email').focus();
            return false;
        }

        if (!password) {
            e.preventDefault();
            showError('Please enter your password.');
            passwordInput.focus();
            return false;
        }

        if (password.length < 6) {
            e.preventDefault();
            showError('Password must be at least 6 characters.');
            passwordInput.focus();
            return false;
        }

        // Show loading state
        loginBtn.disabled = true;
        loginBtn.innerHTML = `
            <span>Sending code...</span>
            <span class="material-symbols-outlined" style="font-size:1.25rem; animation: spin 1s linear infinite;">refresh</span>
        `;

        // Add spin animation
        const style = document.createElement('style');
        style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(style);

        // Allow form to submit
        return true;
    });

    // =============================================
    // 3. CLEAR ERROR ON INPUT
    // =============================================
    document.getElementById('email').addEventListener('input', function() {
        errorMsg.classList.add('hidden');
        // Reset button if it was in loading state
        if (loginBtn.disabled) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = originalBtnHTML;
        }
    });

    passwordInput.addEventListener('input', function() {
        errorMsg.classList.add('hidden');
        if (loginBtn.disabled) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = originalBtnHTML;
        }
    });

    // =============================================
    // 4. AUTO-HIDE SUCCESS MESSAGE
    // =============================================
    const successMsg = document.getElementById('successMessage');
    if (!successMsg.classList.contains('hidden')) {
        setTimeout(function() {
            successMsg.classList.add('hidden');
        }, 5000);
    }

    // =============================================
    // 5. VERIFICATION MODAL
    // =============================================
    <?php if ($showModal): ?>
    (function() {
        const modal = document.getElementById('verificationModal');
        const modalIcon = document.getElementById('modalIcon');
        const successIcon = document.getElementById('successIcon');
        const modalTitle = document.getElementById('modalTitle');
        const statusText = document.getElementById('statusText');
        const continueBtn = document.getElementById('continueBtn');
        const dots = [
            document.getElementById('dot1'),
            document.getElementById('dot2'),
            document.getElementById('dot3'),
            document.getElementById('dot4')
        ];

        // Show modal IMMEDIATELY
        modal.style.display = 'flex';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Reset button state (in case form submission got stuck)
        const btn = document.getElementById('loginBtn');
        btn.disabled = false;
        btn.innerHTML = `
            <span>Sign In</span>
            <span class="material-symbols-outlined" style="font-size:1.25rem;">arrow_forward</span>
        `;

        // Step 1: Update status after 0.8s
        setTimeout(function() {
            statusText.textContent = 'Sending verification code...';
            dots[0].classList.remove('active');
            dots[0].classList.add('done');
            dots[1].classList.add('active');
        }, 800);

        // Step 2: Update status after 1.6s
        setTimeout(function() {
            statusText.textContent = 'Almost there...';
            dots[1].classList.remove('active');
            dots[1].classList.add('done');
            dots[2].classList.add('active');
        }, 1600);

        // Step 3: Success state after 2.4s
        setTimeout(function() {
            modalIcon.style.display = 'none';
            successIcon.classList.add('show');
            
            dots[2].classList.remove('active');
            dots[2].classList.add('done');
            dots[3].classList.add('active');
            
            modalTitle.textContent = 'Verification Code Sent!';
            statusText.textContent = 'Check your email for the 6-digit code.';
            statusText.classList.add('success');
            
            continueBtn.classList.add('show');
            
            setTimeout(function() {
                dots[3].classList.remove('active');
                dots[3].classList.add('done');
            }, 400);
        }, 2400);

        // Continue button redirect
        continueBtn.addEventListener('click', function() {
            window.location.href = 'verify.php';
        });

        // =============================================
        // SEND EMAIL VIA AJAX (BACKGROUND)
        // =============================================
        <?php if ($showModal && isset($modalUserId) && $modalUserId > 0): ?>
        fetch('send_otp.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'user_id=<?php echo $modalUserId; ?>&code=<?php echo $modalCode; ?>&email=<?php echo urlencode($modalEmail); ?>&name=<?php echo urlencode($modalFullName); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Email sent successfully');
            } else {
                console.log('Email failed:', data.error);
            }
        })
        .catch(error => {
            console.log('Email error:', error);
        });
        <?php endif; ?>
    })();
    <?php endif; ?>

    // =============================================
    // 6. SYSTEM ACCOUNT DETECTION
    // =============================================
    const emailInput = document.getElementById('email');
    emailInput.addEventListener('input', function() {
        const email = this.value.trim();
        const isSystem = email.includes('@ismers.com');
        
        const existingBadge = document.querySelector('.system-badge-notice');
        if (existingBadge) existingBadge.remove();
        
        if (isSystem) {
            const badge = document.createElement('div');
            badge.className = 'system-badge-notice';
            badge.style.cssText = `
                background: #fef3c7;
                border: 1px solid #fcd34d;
                border-radius: 0.75rem;
                padding: 0.5rem 0.75rem;
                margin-top: 0.5rem;
                text-align: center;
                font-size: 0.75rem;
                color: #92400e;
                animation: fadeIn 0.3s ease;
            `;
            badge.innerHTML = '🔑 <strong>System Account</strong> — Direct login (no verification)';
            emailInput.parentNode.parentNode.appendChild(badge);
        }
    });

    console.log('✅ ISMERS Login Page loaded successfully.');
</script>

</body>
</html>