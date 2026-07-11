<?php
// verify.php - ISMERS Email Verification Page
session_start();

// Include configuration
require_once 'app/config.php';

// Check if user has temp session (coming from login)
if (!isset($_SESSION['temp_user_id']) || !isset($_SESSION['temp_email'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['temp_user_id'];
$email = $_SESSION['temp_email'] ?? '';
$fullName = $_SESSION['temp_full_name'] ?? 'User';
$role = $_SESSION['temp_role'] ?? 'applicant';
$firstName = $_SESSION['temp_first_name'] ?? '';
$remember = $_SESSION['remember_me'] ?? false;

// Check if user is already verified
$user = getUserById($userId);
if ($user && $user['is_verified'] == 1) {
    // Already verified - direct login
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['email'] = $email;
    $_SESSION['first_name'] = $firstName;
    
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
    header('Location: ' . ($redirects[$role] ?? 'index.php'));
    exit;
}

// Generate verification code if not exists
if (!isset($_SESSION['verification_code']) || !isset($_SESSION['verification_expires'])) {
    $code = sprintf("%06d", rand(100000, 999999));
    $expires = time() + 600; // 10 minutes
    
    $_SESSION['verification_code'] = $code;
    $_SESSION['verification_expires'] = $expires;
    
    // Store in database
    $updateSql = "UPDATE users SET verification_code = ?, verification_expires = FROM_UNIXTIME(?) WHERE id = ?";
    updateRecord($updateSql, [$code, $expires, $userId], "sii");
    
    // Send verification email
    sendVerificationEmail($email, $fullName, $code);
}

// Handle verification
$error = '';
$success = '';
$showResend = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify') {
        $code = implode('', [
            $_POST['code1'] ?? '',
            $_POST['code2'] ?? '',
            $_POST['code3'] ?? '',
            $_POST['code4'] ?? '',
            $_POST['code5'] ?? '',
            $_POST['code6'] ?? ''
        ]);
        
        if (strlen($code) !== 6) {
            $error = 'Please enter a valid 6-digit code.';
        } else {
            // Check code from database
            $user = getUserById($userId);
            $storedCode = $user['verification_code'] ?? '';
            $storedExpires = strtotime($user['verification_expires'] ?? '');
            
            if ($storedCode && $code === $storedCode) {
                if (time() > $storedExpires) {
                    $error = 'Verification code has expired. Please request a new one.';
                    $showResend = true;
                } else {
                    // Mark user as verified
                    $updateSql = "UPDATE users SET is_verified = 1, verification_code = NULL, verification_expires = NULL WHERE id = ?";
                    updateRecord($updateSql, [$userId], "i");
                    
                    // Log activity
                    logActivity($userId, 'Email Verified', 'users', $userId, 'User email verified successfully');
                    
                    // Clear session verification data
                    unset($_SESSION['verification_code']);
                    unset($_SESSION['verification_expires']);
                    
                    // Set actual session
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['role'] = $role;
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $_SESSION['first_name'] = $firstName;
                    
                    // Update last login
                    updateLastLogin($userId);
                    $updateSql = "UPDATE users SET last_activity = NOW() WHERE id = ?";
                    updateRecord($updateSql, [$userId], "i");
                    
                    if ($remember) {
                        setcookie('remember_email', $email, time() + 86400 * 7, '/');
                    }
                    
                    $success = 'Email verified successfully! Redirecting...';
                    
                    // Redirect after 2 seconds
                    $redirects = [
                        'admin' => 'portals/admin/dashboard.php',
                        'hr_manager' => 'portals/hr/dashboard.php',
                        'recruiter' => 'portals/hr/dashboard.php',
                        'client' => 'portals/client/index.php',
                        'applicant' => 'portals/applicant/dashboard.php',
                        'employee' => 'portals/employee/index.php',
                        'supervisor' => 'portals/supervisor/index.php'
                    ];
                    header('Refresh: 2; URL=' . ($redirects[$role] ?? 'index.php'));
                }
            } else {
                $error = 'Invalid verification code. Please try again.';
            }
        }
    } elseif ($action === 'resend') {
        // Generate new code
        $code = sprintf("%06d", rand(100000, 999999));
        $expires = time() + 600;
        
        $_SESSION['verification_code'] = $code;
        $_SESSION['verification_expires'] = $expires;
        
        $updateSql = "UPDATE users SET verification_code = ?, verification_expires = FROM_UNIXTIME(?) WHERE id = ?";
        updateRecord($updateSql, [$code, $expires, $userId], "sii");
        
        sendVerificationEmail($email, $fullName, $code);
        
        $success = 'A new verification code has been sent to your email.';
        $showResend = false;
    }
}

/**
 * Send verification email using PHPMailer
 */
function sendVerificationEmail($toEmail, $toName, $code) {
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
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - ISMERS';
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Email Verification</title>
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
                    <h1>Verify Your Email</h1>
                </div>
                <p>Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                <p>Thank you for registering with ISMERS. Please use the verification code below to complete your registration:</p>
                <div class="code-box">' . $code . '</div>
                <div class="warning">
                    <strong>⚠️ This code will expire in 10 minutes.</strong>
                </div>
                <p>If you didn\'t create an account with ISMERS, you can safely ignore this email.</p>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ISMERS. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "Hello " . $toName . ",\n\n" .
                         "Thank you for registering with ISMERS.\n\n" .
                         "Your verification code is: " . $code . "\n\n" .
                         "This code will expire in 10 minutes.\n\n" .
                         "If you didn't create an account, please ignore this email.\n\n" .
                         "— ISMERS Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Verification email failed: " . $e->getMessage());
        return false;
    }
}

// Get remaining time for display
$remainingTime = 0;
if (isset($_SESSION['verification_expires'])) {
    $remainingTime = max(0, $_SESSION['verification_expires'] - time());
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Verify Email - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - VERIFY EMAIL
           ========================================================================== */
        :root {
            --bg-main: #fcf8ff;
            --bg-surface-low: #f5f2ff;
            --bg-surface: #ffffff;
            --bg-surface-lowest: #ffffff;
            --bg-surface-bright: #f8f7ff;
            --text-main: #1b1b24;
            --text-muted: #464555;
            --text-dim: #777587;
            --text-inverse: #ffffff;
            --outline: #777587;
            --outline-variant: #c7c4d8;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-container: #4f46e5;
            --on-primary: #ffffff;
            --on-primary-container: #dad7ff;
            --error: #dc2626;
            --error-bg: #fef2f2;
            --error-border: #fecaca;
            --success: #16a34a;
            --success-bg: #ecfdf5;
            --success-border: #bbf7d0;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(27, 27, 36, 0.06), 0 2px 4px -2px rgba(27, 27, 36, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(27, 27, 36, 0.08), 0 4px 6px -2px rgba(27, 27, 36, 0.04);
            --shadow-xl: 0 20px 25px -5px rgba(27, 27, 36, 0.1), 0 10px 10px -5px rgba(27, 27, 36, 0.04);
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-label: 'Public Sans', system-ui, -apple-system, sans-serif;
            --transition-fast: 0.15s ease;
            --transition-smooth: 0.25s cubic-bezier(0.16, 1, 0.3, 1);
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
            -webkit-font-smoothing: antialiased;
        }

        /* ===== VERIFY CARD ===== */
        .verify-wrapper {
            width: 100%;
            max-width: 28rem;
        }

        .verify-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        /* ===== ICON ===== */
        .verify-icon {
            width: 4rem;
            height: 4rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary-container);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .verify-icon .material-symbols-outlined {
            font-size: 1.75rem;
        }

        /* ===== HEADER ===== */
        .verify-header {
            margin-bottom: 0.5rem;
        }

        .verify-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        .verify-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
            max-width: 280px;
            margin: 0 auto;
            line-height: 1.6;
            font-family: var(--font-label);
        }

        /* ===== MESSAGES ===== */
        .message {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            width: 100%;
            text-align: left;
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

        /* ===== OTP INPUT ===== */
        .otp-container {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            width: 100%;
        }

        .otp-container input {
            width: 2.5rem;
            height: 3rem;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 600;
            background: var(--bg-surface);
            border: 1px solid var(--outline-variant);
            border-radius: var(--radius-lg);
            color: var(--text-main);
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-sm);
        }

        .otp-container input:focus {
            outline: none;
            border-color: var(--primary-container);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .otp-container input::-webkit-inner-spin-button,
        .otp-container input::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .otp-container input[type="number"] {
            -moz-appearance: textfield;
        }

        /* ===== EXPIRY INFO ===== */
        .expiry-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: var(--font-label);
            margin-bottom: 2rem;
        }

        .expiry-info .material-symbols-outlined {
            font-size: 1rem;
        }

        /* ===== SUBMIT BUTTON ===== */
        .btn-verify {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            border: 1px solid transparent;
            border-radius: var(--radius-lg);
            background: var(--primary-container);
            color: var(--on-primary);
            font-size: 0.875rem;
            font-weight: 500;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: background var(--transition-fast), transform var(--transition-fast);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .btn-verify:hover {
            background: var(--primary-hover);
        }

        .btn-verify:active {
            transform: scale(0.98);
        }

        .btn-verify:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-verify .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ===== RESEND LINK ===== */
        .resend-link {
            font-size: 0.875rem;
            color: var(--text-muted);
            font-family: var(--font-label);
        }

        .resend-link button {
            background: none;
            border: none;
            color: var(--primary);
            font-weight: 500;
            cursor: pointer;
            transition: color var(--transition-fast);
            font-family: var(--font-label);
            font-size: 0.875rem;
        }

        .resend-link button:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        /* ===== BACK LINK ===== */
        .verify-footer {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(199, 196, 216, 0.3);
            text-align: center;
            width: 100%;
        }

        .verify-footer a {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: color var(--transition-fast);
            font-family: var(--font-label);
        }

        .verify-footer a:hover {
            color: var(--primary);
        }

        .verify-footer a .material-symbols-outlined {
            font-size: 1rem;
        }

        /* ===== RESPONSIVE ===== */
        @media (min-width: 640px) {
            .otp-container input {
                width: 3rem;
                height: 3.5rem;
                font-size: 1.5rem;
            }

            .otp-container {
                gap: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .verify-card {
                padding: 1.5rem;
            }

            .verify-header h1 {
                font-size: 1.25rem;
            }

            .otp-container {
                gap: 0.375rem;
            }

            .otp-container input {
                width: 2.25rem;
                height: 2.75rem;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="verify-wrapper">
    <div class="verify-card">

        <!-- Icon -->
        <div class="verify-icon">
            <span class="material-symbols-outlined">shield_lock</span>
        </div>

        <!-- Header -->
        <div class="verify-header">
            <h1>Verify your identity</h1>
            <p>We've sent a 6-digit verification code to your email.</p>
        </div>

        <!-- Success Message -->
        <div class="message success <?php echo empty($success) ? 'hidden' : ''; ?>" id="successMessage">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>

        <!-- Error Message -->
        <div class="message error <?php echo empty($error) ? 'hidden' : ''; ?>" id="errorMessage">
            <span class="material-symbols-outlined">error</span>
            <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
        </div>

        <!-- OTP Form -->
        <form method="POST" action="" id="verifyForm" class="w-full">
            <input type="hidden" name="action" value="verify">

            <div class="otp-container" id="otpContainer">
                <input type="number" name="code1" maxlength="1" pattern="[0-9]" required autofocus>
                <input type="number" name="code2" maxlength="1" pattern="[0-9]" required>
                <input type="number" name="code3" maxlength="1" pattern="[0-9]" required>
                <input type="number" name="code4" maxlength="1" pattern="[0-9]" required>
                <input type="number" name="code5" maxlength="1" pattern="[0-9]" required>
                <input type="number" name="code6" maxlength="1" pattern="[0-9]" required>
            </div>

            <!-- Expiry Info -->
            <div class="expiry-info">
                <span class="material-symbols-outlined">schedule</span>
                <span>Code expires in <span id="timer"><?php echo $remainingTime; ?></span> seconds</span>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-verify" id="verifyBtn">
                <span>Verify Code</span>
            </button>
        </form>

        <!-- Resend Link -->
        <div class="resend-link">
            Didn't receive the code?
            <form method="POST" action="" style="display: inline;">
                <input type="hidden" name="action" value="resend">
                <button type="submit" id="resendBtn">Resend code</button>
            </form>
        </div>

        <!-- Back to Login -->
        <div class="verify-footer">
            <a href="login.php">
                <span class="material-symbols-outlined">arrow_back</span>
                Back to Sign In
            </a>
        </div>

    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
    // =============================================
    // 1. OTP AUTO-FOCUS
    // =============================================
    const inputs = document.querySelectorAll('#otpContainer input');

    inputs.forEach((input, index) => {
        input.addEventListener('input', function(e) {
            // Auto-advance to next input
            if (this.value.length === 1 && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        });

        input.addEventListener('keydown', function(e) {
            // Backspace to previous input
            if (e.key === 'Backspace' && !this.value && index > 0) {
                inputs[index - 1].focus();
            }
        });

        // Only allow numbers
        input.addEventListener('keypress', function(e) {
            if (!/^\d$/.test(e.key)) {
                e.preventDefault();
            }
        });
    });

    // =============================================
    // 2. PASTE SUPPORT
    // =============================================
    document.getElementById('otpContainer').addEventListener('paste', function(e) {
        e.preventDefault();
        const pasteData = e.clipboardData.getData('text').trim();
        if (/^\d{6}$/.test(pasteData)) {
            const digits = pasteData.split('');
            inputs.forEach((input, index) => {
                if (index < digits.length) {
                    input.value = digits[index];
                }
            });
            // Focus the last input
            inputs[inputs.length - 1].focus();
        }
    });

    // =============================================
    // 3. FORM SUBMIT
    // =============================================
    const form = document.getElementById('verifyForm');
    const verifyBtn = document.getElementById('verifyBtn');
    const errorMsg = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');
    const successMsg = document.getElementById('successMessage');

    form.addEventListener('submit', function(e) {
        // Check if all inputs are filled
        let code = '';
        inputs.forEach(input => {
            code += input.value;
        });

        if (code.length !== 6) {
            e.preventDefault();
            showError('Please enter the complete 6-digit verification code.');
            return false;
        }

        // Show loading state
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = `
            <span>Verifying...</span>
            <span class="material-symbols-outlined spinner" style="font-size:1.25rem;">refresh</span>
        `;

        return true;
    });

    function showError(message) {
        errorText.textContent = message;
        errorMsg.classList.remove('hidden');
        successMsg.classList.add('hidden');
    }

    // =============================================
    // 4. CLEAR ERROR ON INPUT
    // =============================================
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            errorMsg.classList.add('hidden');
            successMsg.classList.add('hidden');
        });
    });

    // =============================================
    // 5. TIMER COUNTDOWN
    // =============================================
    let remainingTime = <?php echo $remainingTime; ?>;
    const timerEl = document.getElementById('timer');

    if (remainingTime > 0) {
        const timerInterval = setInterval(function() {
            remainingTime--;
            timerEl.textContent = remainingTime;

            if (remainingTime <= 0) {
                clearInterval(timerInterval);
                timerEl.textContent = '0';
                document.getElementById('resendBtn').style.color = '#4f46e5';
                document.getElementById('resendBtn').style.fontWeight = '600';
            }
        }, 1000);
    } else {
        timerEl.textContent = '0';
        document.getElementById('resendBtn').style.color = '#4f46e5';
        document.getElementById('resendBtn').style.fontWeight = '600';
    }

    // =============================================
    // 6. RESEND BUTTON HANDLER
    // =============================================
    document.getElementById('resendBtn').addEventListener('click', function() {
        // Reset timer
        remainingTime = 600;
        timerEl.textContent = remainingTime;
        this.style.color = '';
        this.style.fontWeight = '';

        // Show success message
        successMsg.classList.remove('hidden');
        successMsg.querySelector('span:last-child').textContent = 'Resending verification code...';
    });

    console.log('ISMERS Verify Page loaded.');
</script>

</body>
</html>