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
        'client' => 'portals/client/index.php',
        'applicant' => 'portals/applicant/dashboard.php',
        'employee' => 'portals/employee/index.php',
        'supervisor' => 'portals/supervisor/index.php'
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
                        'client' => 'portals/client/index.php',
                        'applicant' => 'portals/applicant/dashboard.php',
                        'employee' => 'portals/employee/index.php',
                        'supervisor' => 'portals/supervisor/index.php'
                    ];
                    header('Location: ' . ($redirects[$user['role']] ?? 'index.php'));
                    exit;
                }
                
                // =============================================
                // REAL ACCOUNT - Check if verified
                // =============================================
                if ($user['is_verified'] == 1) {
                    // Already verified - direct login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    
                    updateLastLogin($user['id']);
                    $updateSql = "UPDATE users SET last_activity = NOW() WHERE id = ?";
                    updateRecord($updateSql, [$user['id']], "i");
                    
                    if ($remember) {
                        setcookie('remember_email', $email, time() + 86400 * 7, '/');
                    }
                    
                    $redirects = [
                        'admin' => 'portals/admin/dashboard.php',
                        'hr_manager' => 'portals/hr/dashboard.php',
                        'recruiter' => 'portals/hr/dashboard.php',
                        'client' => 'portals/client/index.php',
                        'applicant' => 'portals/applicant/dashboard.php',
                        'employee' => 'portals/employee/index.php',
                        'supervisor' => 'portals/supervisor/index.php'
                    ];
                    header('Location: ' . ($redirects[$user['role']] ?? 'index.php'));
                    exit;
                } else {
                    // Not verified - send verification code
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
                    
                    // Send verification email
                    $mailSent = sendLoginVerificationEmail($email, $user['full_name'], $code);
                    
                    // Redirect to verify.php
                    header('Location: verify.php');
                    exit;
                }
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
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">

        <!-- Header -->
        <div class="auth-header">
            <h1>Sign In</h1>
            <p>Access your account to continue.</p>
        </div>

        <!-- Success Message -->
        <div class="message success <?php echo empty($logoutMessage) ? 'hidden' : ''; ?>" id="successMessage">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo htmlspecialchars($logoutMessage); ?></span>
        </div>

        <!-- Error Message -->
        <div class="message error <?php echo empty($error) ? 'hidden' : ''; ?>" id="errorMessage">
            <span class="material-symbols-outlined">error</span>
            <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
        </div>

        <!-- System Account Notice -->
        <?php if (!empty($email) && isSystemAccount($email)): ?>
            <div style="background:#fef3c7; border:1px solid #fcd34d; border-radius:0.75rem; padding:0.75rem 1rem; margin-bottom:1rem; text-align:center;">
                <span style="font-size:0.875rem; color:#92400e;">
                    🔑 <strong>System Account</strong>
                    <br>
                    <span style="font-size:0.75rem;">Direct login enabled (no verification required)</span>
                </span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="" id="loginForm">
            <!-- Email -->
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

            <!-- Password -->
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

            <!-- Options -->
            <div class="form-options">
                <label>
                    <input type="checkbox" name="remember" 
                           <?php echo isset($_COOKIE['remember_email']) ? 'checked' : ''; ?>>
                    Remember me
                </label>
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-login" id="loginBtn">
                <span>Sign In</span>
                <span class="material-symbols-outlined" style="font-size:1.25rem;">arrow_forward</span>
            </button>
        </form>

        <!-- Sign Up Link -->
        <div class="signup-link">
            Don't have an account? <a href="portals/applicant/register.php">Get Started</a>
        </div>

        <!-- Back to Home -->
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
    // 2. FORM VALIDATION
    // =============================================
    const form = document.getElementById('loginForm');
    const errorMsg = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');

    form.addEventListener('submit', function(e) {
        const email = document.getElementById('email').value.trim();
        const password = passwordInput.value.trim();

        // Hide previous errors
        errorMsg.classList.add('hidden');

        // Validate
        if (!email) {
            e.preventDefault();
            showError('Please enter your email address.');
            return false;
        }

        if (!isValidEmail(email)) {
            e.preventDefault();
            showError('Please enter a valid email address.');
            return false;
        }

        if (!password) {
            e.preventDefault();
            showError('Please enter your password.');
            return false;
        }

        if (password.length < 6) {
            e.preventDefault();
            showError('Password must be at least 6 characters.');
            return false;
        }

        return true;
    });

    function showError(message) {
        errorText.textContent = message;
        errorMsg.classList.remove('hidden');
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // =============================================
    // 3. CLEAR ERROR ON INPUT
    // =============================================
    document.getElementById('email').addEventListener('input', function() {
        errorMsg.classList.add('hidden');
    });

    passwordInput.addEventListener('input', function() {
        errorMsg.classList.add('hidden');
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
    // 5. KEYBOARD SUPPORT
    // =============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const active = document.activeElement;
            if (active && (active.id === 'email' || active.id === 'password')) {
                form.dispatchEvent(new Event('submit'));
            }
        }
    });

    // =============================================
    // 6. SYSTEM ACCOUNT DETECTION - Show on email input
    // =============================================
    const emailInput = document.getElementById('email');
    emailInput.addEventListener('input', function() {
        const email = this.value.trim();
        const isSystem = email.includes('@ismers.com');
        
        // Remove existing badge
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

    console.log('ISMERS Login Page loaded with System Account Detection.');
</script>

</body>
</html>