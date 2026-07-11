<?php
// forgot_password.php - ISMERS Forgot Password Page with PHPMailer
session_start();

// Include configuration
require_once 'app/config.php';

// Load PHPMailer manually (since vendor/autoload.php doesn't exist)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Manually include PHPMailer files
require_once 'PHPMailer-master/src/Exception.php';
require_once 'PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer-master/src/SMTP.php';

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

// Handle form submission
$success = '';
$error = '';
$email = '';
$showDebugLink = false;
$debugLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if email exists
        $user = getUserByEmail($email);
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in password_resets table
            $sql = "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)";
            $result = insertRecord($sql, [$user['id'], $token, $expires], "iss");
            
            if ($result) {
                // Log the activity
                logActivity($user['id'], 'Password Reset Requested', 'password_resets', $result, 'Password reset requested for: ' . $email);
                
                // Build reset link
                $resetLink = SITE_URL . 'reset_password.php?token=' . $token;
                
                // Send email using PHPMailer
                $mailSent = sendResetEmail($email, $user['full_name'], $resetLink);
                
                if ($mailSent) {
                    $success = 'A password reset link has been sent to your email address.';
                    // Show debug link for testing
                    $showDebugLink = true;
                    $debugLink = $resetLink;
                } else {
                    $error = 'Failed to send email. Please try again later.';
                    // Show debug link so user can still reset
                    $showDebugLink = true;
                    $debugLink = $resetLink;
                }
            } else {
                $error = 'Failed to generate reset link. Please try again.';
            }
        } else {
            // Don't reveal if email exists or not for security
            $success = 'If an account exists with this email, a password reset link has been sent.';
        }
    }
}

/**
 * Send password reset email using PHPMailer
 */
function sendResetEmail($toEmail, $toName, $resetLink) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
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
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - ISMERS';
        
        // Email body
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Password Reset</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f3ff; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
                .header { text-align: center; margin-bottom: 30px; }
                .logo { display: inline-block; background: #4f46e5; color: white; font-size: 24px; font-weight: 800; padding: 8px 20px; border-radius: 12px; }
                h1 { color: #1b1b24; font-size: 24px; margin-bottom: 8px; }
                p { color: #464555; font-size: 16px; line-height: 1.6; }
                .btn { display: inline-block; background: #4f46e5; color: white; padding: 12px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; margin: 20px 0; }
                .btn:hover { background: #4338ca; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e8e5f0; color: #777587; font-size: 14px; }
                .warning { font-size: 14px; color: #92400e; background: #fef3c7; padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
                .link-box { font-size: 14px; word-break: break-all; background: #f5f3ff; padding: 12px; border-radius: 8px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="logo">ISMERS</div>
                    <h1>Reset Your Password</h1>
                </div>
                <p>Hello <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                <p>We received a request to reset the password for your ISMERS account. Click the button below to create a new password:</p>
                <div style="text-align: center;">
                    <a href="' . $resetLink . '" class="btn">Reset Password</a>
                </div>
                <p style="font-size: 14px; color: #777587;">If the button doesn\'t work, copy and paste this link into your browser:</p>
                <div class="link-box">' . $resetLink . '</div>
                <div class="warning">
                    <strong>⚠️ This link will expire in 1 hour.</strong>
                </div>
                <p>If you didn\'t request this password reset, you can ignore this email. Your password will remain unchanged.</p>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ISMERS. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        // Plain text alternative
        $mail->AltBody = "Hello " . $toName . ",\n\n" .
                         "We received a request to reset the password for your ISMERS account.\n\n" .
                         "Click the link below to reset your password:\n" .
                         $resetLink . "\n\n" .
                         "This link will expire in 1 hour.\n\n" .
                         "If you didn't request this, please ignore this email.\n\n" .
                         "— ISMERS Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Forgot Password - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - FORGOT PASSWORD
           ========================================================================== */
        :root {
            --bg-main: #f5f3ff;
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

        /* ===== FORGOT PASSWORD CARD ===== */
        .forgot-wrapper {
            width: 100%;
            max-width: 28rem;
        }

        .forgot-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
            transition: box-shadow var(--transition-smooth);
        }

        .forgot-card:hover {
            box-shadow: 0 24px 30px -8px rgba(27, 27, 36, 0.12);
        }

        /* ===== HEADER ===== */
        .forgot-header {
            margin-bottom: 2rem;
        }

        .forgot-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
            margin-bottom: 0.5rem;
        }

        .forgot-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
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

        /* ===== DEBUG LINK ===== */
        .message.debug {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
        }

        .message.debug a {
            color: var(--primary);
            word-break: break-all;
        }

        .message.debug a:hover {
            text-decoration: underline;
        }

        /* ===== FORM ===== */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
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
            border-radius: var(--radius-lg);
            background: var(--bg-surface-bright);
            color: var(--text-main);
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary-container);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .input-wrapper input::placeholder {
            color: var(--outline-variant);
        }

        /* ===== SUBMIT BUTTON ===== */
        .btn-submit {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border: 1px solid transparent;
            border-radius: var(--radius-lg);
            background: var(--primary-container);
            color: var(--on-primary);
            font-size: 0.875rem;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: background var(--transition-fast), transform var(--transition-fast);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.2);
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* ===== BACK LINK ===== */
        .forgot-footer {
            margin-top: 1.5rem;
            text-align: center;
        }

        .forgot-footer a {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--primary);
            transition: color var(--transition-fast);
            font-family: var(--font-label);
        }

        .forgot-footer a:hover {
            color: var(--primary-hover);
        }

        .forgot-footer a .material-symbols-outlined {
            font-size: 1.125rem;
            transition: transform var(--transition-fast);
        }

        .forgot-footer a:hover .material-symbols-outlined {
            transform: translateX(-3px);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .forgot-card {
                padding: 1.5rem;
            }

            .forgot-header h1 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>

<div class="forgot-wrapper">
    <div class="forgot-card">

        <!-- Header -->
        <div class="forgot-header">
            <h1>Forgot your password?</h1>
            <p>No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.</p>
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

        <!-- Debug Link (Shows reset link for testing) -->
        <?php if ($showDebugLink && !empty($debugLink)): ?>
            <div class="message debug">
                <span class="material-symbols-outlined">link</span>
                <span>
                    <strong>Reset Link (for testing):</strong><br>
                    <a href="<?php echo $debugLink; ?>" target="_blank"><?php echo $debugLink; ?></a>
                </span>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="" id="forgotForm">
            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-wrapper">
                    <span class="input-icon">
                        <span class="material-symbols-outlined">mail</span>
                    </span>
                    <input type="email" id="email" name="email" placeholder="you@example.com" 
                           value="<?php echo htmlspecialchars($email); ?>" required autofocus>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <span>Email Password Reset Link</span>
            </button>
        </form>

        <!-- Back to Login -->
        <div class="forgot-footer">
            <a href="login.php">
                <span class="material-symbols-outlined">arrow_back</span>
                Back to login
            </a>
        </div>

    </div>
</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
    // =============================================
    // 1. FORM VALIDATION
    // =============================================
    const form = document.getElementById('forgotForm');
    const submitBtn = document.getElementById('submitBtn');
    const errorMsg = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');
    const successMsg = document.getElementById('successMessage');

    form.addEventListener('submit', function(e) {
        const email = document.getElementById('email').value.trim();

        // Hide previous messages
        errorMsg.classList.add('hidden');
        successMsg.classList.add('hidden');

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

        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = `
            <span>Sending...</span>
            <span class="material-symbols-outlined" style="font-size:1.25rem; animation: spin 1s linear infinite;">refresh</span>
        `;

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
    // 2. CLEAR ERROR ON INPUT
    // =============================================
    document.getElementById('email').addEventListener('input', function() {
        errorMsg.classList.add('hidden');
        successMsg.classList.add('hidden');
    });

    // =============================================
    // 3. AUTO-HIDE SUCCESS MESSAGE
    // =============================================
    if (!successMsg.classList.contains('hidden')) {
        setTimeout(function() {
            successMsg.classList.add('hidden');
        }, 8000);
    }

    // =============================================
    // 4. KEYBOARD SUPPORT
    // =============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const active = document.activeElement;
            if (active && active.id === 'email') {
                form.dispatchEvent(new Event('submit'));
            }
        }
    });

    // =============================================
    // 5. SPIN ANIMATION
    // =============================================
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);

    console.log('ISMERS Forgot Password Page loaded.');
</script>

</body>
</html>