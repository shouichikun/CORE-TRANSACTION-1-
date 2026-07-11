<?php
// biometric_login.php - Biometric Login with Backup Codes for System Accounts
session_start();
require_once 'app/config.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: portals/' . ($_SESSION['role'] ?? 'applicant') . '/dashboard.php');
    exit;
}

$error = '';
$success = '';
$email = $_GET['email'] ?? $_SESSION['biometric_email'] ?? '';
$biometricType = $_GET['type'] ?? 'face';
$showCodeInput = false;
$generatedCode = '';
$isSystemEmail = false;
$backupCodes = [];
$showBackupOption = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Step 1: Enter email
    if ($action === 'initiate') {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } else {
            $user = getUserByEmail($email);
            if (!$user) {
                $error = 'User not found.';
            } elseif ($user['biometric_enabled'] != 1) {
                $error = 'Biometric not enabled for this account. <a href="login.php">Use password login</a>';
            } else {
                // Generate verification code
                $code = sprintf("%06d", rand(100000, 999999));
                $expires = time() + 300; // 5 minutes
                
                // Store in session
                $_SESSION['biometric_email'] = $email;
                $_SESSION['biometric_code'] = $code;
                $_SESSION['biometric_expires'] = $expires;
                $_SESSION['biometric_user_id'] = $user['id'];
                $_SESSION['biometric_role'] = $user['role'];
                $_SESSION['biometric_full_name'] = $user['full_name'];
                $_SESSION['biometric_first_name'] = $user['first_name'];
                
                // Check if system email
                $isSystemEmail = strpos($email, '@ismers.com') !== false;
                
                if ($isSystemEmail) {
                    // =============================================
                    // SYSTEM ACCOUNT - Use Backup Codes
                    // =============================================
                    // Generate 5 backup codes for system accounts
                    $backupCodes = [];
                    for ($i = 0; $i < 5; $i++) {
                        $backupCodes[] = sprintf("%06d", rand(100000, 999999));
                    }
                    
                    // Store in session
                    $_SESSION['biometric_backup_codes'] = $backupCodes;
                    $showCodeInput = true;
                    $showBackupOption = true;
                    $success = 'Use one of your backup codes to login.';
                } else {
                    // =============================================
                    // REAL ACCOUNT - Send email verification
                    // =============================================
                    $sent = sendVerificationCode($email, $user['full_name'], $code);
                    if ($sent) {
                        $showCodeInput = true;
                        $success = 'A verification code has been sent to your email.';
                    } else {
                        $error = 'Failed to send verification code. Please try again.';
                    }
                }
            }
        }
    }
    
    // Step 2: Verify code
    if ($action === 'verify') {
        $userCode = trim($_POST['verification_code'] ?? '');
        $storedCode = $_SESSION['biometric_code'] ?? '';
        $storedBackupCodes = $_SESSION['biometric_backup_codes'] ?? [];
        $expires = $_SESSION['biometric_expires'] ?? 0;
        
        $codeValid = false;
        $usedBackup = false;
        
        // Check if code matches stored code (for real accounts)
        if (!empty($storedCode) && $userCode === $storedCode && time() <= $expires) {
            $codeValid = true;
        }
        
        // Check if code matches any backup code (for system accounts)
        if (!$codeValid && !empty($storedBackupCodes)) {
            foreach ($storedBackupCodes as $index => $backupCode) {
                if ($userCode === $backupCode) {
                    $codeValid = true;
                    $usedBackup = true;
                    // Remove used backup code
                    unset($storedBackupCodes[$index]);
                    $_SESSION['biometric_backup_codes'] = array_values($storedBackupCodes);
                    break;
                }
            }
        }
        
        if (empty($userCode)) {
            $error = 'Please enter the verification code.';
        } elseif (time() > $expires && !$usedBackup) {
            $error = 'Verification code has expired. Please request a new one.';
            unset($_SESSION['biometric_code']);
            unset($_SESSION['biometric_expires']);
        } elseif ($codeValid) {
            // Code verified! Login successful
            $userId = $_SESSION['biometric_user_id'] ?? 0;
            $role = $_SESSION['biometric_role'] ?? 'applicant';
            $fullName = $_SESSION['biometric_full_name'] ?? 'User';
            $firstName = $_SESSION['biometric_first_name'] ?? '';
            
            // Set session
            $_SESSION['user_id'] = $userId;
            $_SESSION['role'] = $role;
            $_SESSION['full_name'] = $fullName;
            $_SESSION['email'] = $email;
            $_SESSION['first_name'] = $firstName;
            $_SESSION['biometric_verified'] = true;
            $_SESSION['biometric_verified_at'] = time();
            $_SESSION['biometric_used_backup'] = $usedBackup;
            
            // Update last login
            updateLastLogin($userId);
            $updateSql = "UPDATE users SET last_activity = NOW(), biometric_verified_at = NOW() WHERE id = ?";
            updateRecord($updateSql, [$userId], "i");
            
            // Log biometric activity
            logBiometricActivity($userId, $biometricType, 'login', 0.95, 'success');
            
            // Redirect based on role
            $redirects = [
                'admin' => 'portals/admin/dashboard.php',
                'hr_manager' => 'portals/hr/dashboard.php',
                'recruiter' => 'portals/hr/dashboard.php',
                'client' => 'portals/client/index.php',
                'applicant' => 'portals/applicant/dashboard.php',
                'employee' => 'portals/employee/index.php',
                'supervisor' => 'portals/supervisor/index.php'
            ];
            
            // Clear biometric session data
            unset($_SESSION['biometric_email']);
            unset($_SESSION['biometric_code']);
            unset($_SESSION['biometric_expires']);
            unset($_SESSION['biometric_user_id']);
            unset($_SESSION['biometric_role']);
            unset($_SESSION['biometric_full_name']);
            unset($_SESSION['biometric_first_name']);
            unset($_SESSION['biometric_backup_codes']);
            
            header('Location: ' . ($redirects[$role] ?? 'index.php'));
            exit;
        } else {
            $error = 'Invalid verification code. Please try again.';
        }
    }
}

// Get user for display
$user = null;
if (!empty($email)) {
    $user = getUserByEmail($email);
}

/**
 * Send verification code via email
 */
function sendVerificationCode($toEmail, $toName, $code) {
    require_once 'PHPMailer-master/src/Exception.php';
    require_once 'PHPMailer-master/src/PHPMailer.php';
    require_once 'PHPMailer-master/src/SMTP.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
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
        
        $mail->isHTML(true);
        $mail->Subject = 'Biometric Login Code - ISMERS';
        $mail->Body = '
        <html>
        <head>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f3ff; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .header { text-align: center; margin-bottom: 30px; }
            .logo { display: inline-block; background: #4f46e5; color: white; font-size: 24px; font-weight: 800; padding: 8px 20px; border-radius: 12px; }
            h1 { color: #1b1b24; font-size: 24px; margin-bottom: 8px; }
            p { color: #464555; font-size: 16px; line-height: 1.6; }
            .code-box { background: #f5f3ff; border: 2px dashed #4f46e5; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #4f46e5; }
            .warning { font-size: 14px; color: #92400e; background: #fef3c7; padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e8e5f0; color: #777587; font-size: 14px; }
        </style>
        </head>
        <body>
            <div class="container">
                <div class="header"><div class="logo">ISMERS</div></div>
                <h1>Biometric Login Verification</h1>
                <p>Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                <p>Use the code below to complete your biometric login:</p>
                <div class="code-box">' . $code . '</div>
                <div class="warning"><strong>This code will expire in 5 minutes.</strong></div>
                <p>If you didn\'t request this, please ignore this email.</p>
                <div class="footer"><p>&copy; ' . date('Y') . ' ISMERS. All rights reserved.</p></div>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "Hello " . $toName . ",\n\nYour biometric login code is: " . $code . "\n\nThis code will expire in 5 minutes.\n\nIf you didn't request this, please ignore this email.\n\n— ISMERS Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Biometric email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log biometric activity
 */
function logBiometricActivity($userId, $type, $action, $confidence, $status) {
    global $conn;
    
    $checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'biometric_logs'");
    if (mysqli_num_rows($checkTable) == 0) {
        error_log("biometric_logs table doesn't exist yet");
        return;
    }
    
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Biometric Login - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ===== STYLES ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-background: #f8f7fc;
            --bg-surface: #ffffff;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --text-on-surface: #1b1b24;
            --text-on-surface-variant: #464555;
            --slate-200: #e2e8f0;
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            --font-sans: 'Inter', system-ui, sans-serif;
            --transition-fast: 0.15s ease;
        }
        body {
            font-family: var(--font-sans);
            background: var(--bg-background);
            color: var(--text-on-surface);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .biometric-wrapper { width: 100%; max-width: 28rem; }
        .biometric-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--slate-200);
            text-align: center;
        }
        .biometric-header { margin-bottom: 2rem; }
        .biometric-header .icon-wrapper {
            width: 4.5rem;
            height: 4.5rem;
            border-radius: 50%;
            background: rgba(79, 70, 229, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: var(--primary);
        }
        .biometric-header .icon-wrapper .material-symbols-outlined { font-size: 2.5rem; }
        .biometric-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--text-on-surface); }
        .biometric-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.25rem; }
        
        .form-group { margin-bottom: 1rem; text-align: left; }
        .form-group label { display: block; font-size: 0.875rem; font-weight: 500; color: var(--text-on-surface-variant); margin-bottom: 0.25rem; }
        .form-group input {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.625rem;
            font-size: 0.875rem;
            font-family: inherit;
            background: var(--bg-surface);
            transition: all var(--transition-fast);
            color: var(--text-on-surface);
            text-align: center;
            letter-spacing: 4px;
            font-size: 1.25rem;
            font-weight: 700;
        }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.15); }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: var(--primary);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            width: 100%;
            justify-content: center;
        }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-2px); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: transparent;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.875rem;
            border: 2px solid var(--primary);
            cursor: pointer;
            transition: all var(--transition-fast);
            width: 100%;
            justify-content: center;
            margin-top: 0.5rem;
        }
        .btn-outline:hover { background: var(--primary); color: white; }
        
        .status-message {
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .status-message.error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
        .status-message.success { background: #ecfdf5; border: 1px solid #bbf7d0; color: #16a34a; }
        .status-message.info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }
        
        .biometric-footer { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--slate-200); text-align: center; }
        .biometric-footer a { font-size: 0.875rem; color: var(--text-on-surface-variant); transition: color var(--transition-fast); }
        .biometric-footer a:hover { color: var(--primary); }
        
        .code-display {
            background: #f0f0f5;
            border-radius: 0.75rem;
            padding: 1rem;
            margin: 1rem 0;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 8px;
            color: var(--primary);
            border: 2px dashed var(--primary);
            text-align: center;
        }
        
        .backup-codes {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 0.75rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .backup-codes .code-item {
            display: inline-block;
            background: white;
            padding: 0.25rem 0.75rem;
            border-radius: 0.375rem;
            margin: 0.25rem;
            font-family: monospace;
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
            border: 1px solid #86efac;
        }
        
        .info-box {
            background: #f8f7fc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            text-align: left;
        }
        .info-box strong { color: var(--text-on-surface); }
        
        .loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        .biometric-area {
            background: var(--bg-surface-low);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 2px dashed var(--slate-200);
            min-height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 1rem;
        }
        .biometric-area .icon-large { font-size: 3rem; }
        
        .system-badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 0.125rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        @media (max-width: 480px) {
            .biometric-card { padding: 1.5rem; }
            .biometric-header h1 { font-size: 1.25rem; }
            .code-display { font-size: 1.5rem; letter-spacing: 4px; }
            .backup-codes .code-item { font-size: 0.875rem; }
        }
    </style>
</head>
<body>

<div class="biometric-wrapper">
    <div class="biometric-card">

        <!-- Header -->
        <div class="biometric-header">
            <div class="icon-wrapper">
                <span class="material-symbols-outlined">fingerprint</span>
            </div>
            <h1>Biometric Login</h1>
            <p>Secure access using biometric verification</p>
        </div>

        <!-- Status Messages -->
        <?php if (!empty($error)): ?>
            <div class="status-message error">
                <span class="material-symbols-outlined">error</span>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="status-message success">
                <span class="material-symbols-outlined">check_circle</span>
                <span><?php echo $success; ?></span>
            </div>
        <?php endif; ?>

        <?php if (empty($showCodeInput)): ?>
            <!-- Step 1: Enter Email -->
            <div class="info-box">
                <strong>📧 Enter your email to start</strong>
                <br>You will receive a verification code or backup codes.
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="initiate">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required value="<?php echo htmlspecialchars($email); ?>">
                </div>
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-outlined">send</span>
                    Send Verification
                </button>
            </form>

            <div class="biometric-footer">
                <a href="login.php">← Back to password login</a>
            </div>

        <?php else: ?>
            <!-- Step 2: Enter Code -->
            <div class="info-box">
                <strong>📧 Email:</strong> <?php echo htmlspecialchars($email); ?>
                <?php if ($isSystemEmail): ?>
                    <span class="system-badge">System Account</span>
                <?php endif; ?>
            </div>

            <!-- Show backup codes for system accounts -->
            <?php if ($isSystemEmail && !empty($_SESSION['biometric_backup_codes'])): ?>
                <div class="backup-codes">
                    <p style="font-weight:600; color:#166534; margin-bottom:0.5rem;">🔑 Your Backup Codes</p>
                    <p style="font-size:0.75rem; color:#166534; margin-bottom:0.75rem;">
                        Use one of these codes to login. Each code can only be used once.
                    </p>
                    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:0.25rem;">
                        <?php foreach ($_SESSION['biometric_backup_codes'] as $code): ?>
                            <span class="code-item"><?php echo $code; ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:0.7rem; color:#166534; margin-top:0.5rem; opacity:0.7;">
                        Remaining: <?php echo count($_SESSION['biometric_backup_codes']); ?> codes
                    </p>
                </div>
            <?php endif; ?>

            <!-- Biometric Icon -->
            <div class="biometric-area">
                <div class="icon-large">🖐️</div>
                <div style="font-size:0.875rem; color:var(--text-on-surface-variant);">
                    <?php if ($isSystemEmail): ?>
                        Enter one of your backup codes
                    <?php else: ?>
                        Enter the 6-digit verification code
                    <?php endif; ?>
                </div>
            </div>

            <!-- Code Input Form -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="verify">
                <div class="form-group">
                    <label for="verification_code">
                        <?php if ($isSystemEmail): ?>
                            Backup Code
                        <?php else: ?>
                            Verification Code
                        <?php endif; ?>
                    </label>
                    <input type="text" id="verification_code" name="verification_code" 
                           placeholder="000000" maxlength="6" required 
                           autofocus pattern="[0-9]{6}" inputmode="numeric">
                    <?php if (!$isSystemEmail): ?>
                        <div style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                            Code expires in <span id="timer">5:00</span>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-outlined">verified</span>
                    Verify & Login
                </button>
            </form>

            <div style="margin-top:0.75rem;">
                <form method="POST" action="" style="display:inline;">
                    <input type="hidden" name="action" value="initiate">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <button type="submit" style="background:none; border:none; color:var(--primary); cursor:pointer; font-size:0.875rem; text-decoration:underline;">
                        <?php if ($isSystemEmail): ?>
                            Generate New Backup Codes
                        <?php else: ?>
                            Resend Code
                        <?php endif; ?>
                    </button>
                </form>
                <span style="color:var(--text-on-surface-variant); font-size:0.875rem; margin:0 0.5rem;">|</span>
                <a href="login.php" style="font-size:0.875rem; color:var(--text-on-surface-variant);">Back to Login</a>
            </div>

            <div class="biometric-footer">
                <a href="login.php">← Back to password login</a>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- =============================================
JAVASCRIPT - Timer & Code Validation
============================================= -->
<script>
    // =============================================
    // 1. TIMER COUNTDOWN (Only for real accounts)
    // =============================================
    <?php if (!$isSystemEmail): ?>
    let timeLeft = 300; // 5 minutes
    const timerElement = document.getElementById('timer');

    if (timerElement) {
        const timerInterval = setInterval(function() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            timerElement.textContent = minutes + ':' + String(seconds).padStart(2, '0');
            
            if (timeLeft <= 60) {
                timerElement.style.color = '#dc2626';
            }
            
            timeLeft--;
            
            if (timeLeft < 0) {
                clearInterval(timerInterval);
                timerElement.textContent = 'Expired';
                timerElement.style.color = '#dc2626';
            }
        }, 1000);
    }
    <?php endif; ?>

    // =============================================
    // 2. AUTO-FORMAT CODE INPUT (Numbers only)
    // =============================================
    const codeInput = document.getElementById('verification_code');
    if (codeInput) {
        codeInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
        });
        
        // Auto-submit when 6 digits entered
        codeInput.addEventListener('input', function() {
            if (this.value.length === 6) {
                this.form.submit();
            }
        });
    }

    // =============================================
    // 3. SHOW CODES IN CONSOLE
    // =============================================
    <?php if ($isSystemEmail && !empty($_SESSION['biometric_backup_codes'])): ?>
        console.log('🔑 BACKUP CODES:', <?php echo json_encode($_SESSION['biometric_backup_codes']); ?>);
        console.log('📧 System account - use one of the backup codes shown on screen');
    <?php endif; ?>

    console.log('🔐 Biometric Login loaded successfully.');
</script>

</body>
</html>