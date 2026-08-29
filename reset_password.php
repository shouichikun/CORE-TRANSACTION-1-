<?php
// reset_password.php - ISMERS Reset Password Page
session_start();

// Include configuration
require_once 'app/config.php';

// Check if token is provided
$token = isset($_GET['token']) ? $_GET['token'] : '';

// If no token, redirect to forgot password
if (empty($token)) {
    header('Location: forgot_password.php');
    exit;
}

// ✅ FIXED: Use PostgreSQL placeholders ($1, $2, $3)
$tokenData = getRecord("SELECT * FROM password_resets WHERE token = $1 AND is_used = false AND expires_at > NOW()", [$token]);

if (!$tokenData) {
    $error = 'Invalid or expired reset link. Please request a new one.';
}

// Handle form submission
$success = '';
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenData) {
    $password = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || empty($confirmPassword)) {
        $error = 'Please enter both password fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Get user
        $user = getUserById($tokenData['user_id']);
        
        if ($user) {
            // Hash the new password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // ✅ FIXED: Use PostgreSQL placeholders
            $updateSql = "UPDATE users SET password_hash = $1 WHERE id = $2";
            $result = updateRecord($updateSql, [$passwordHash, $user['id']]);
            
            if ($result) {
                // Mark token as used
                $updateTokenSql = "UPDATE password_resets SET is_used = true WHERE id = $1";
                updateRecord($updateTokenSql, [$tokenData['id']]);
                
                // Log activity (try/catch to prevent failure)
                try {
                    if (function_exists('logActivity')) {
                        logActivity($user['id'], 'Password Reset', 'users', $user['id'], 'Password reset successfully');
                    }
                } catch (Exception $e) {
                    // Ignore logging errors
                }
                
                $success = 'Your password has been reset successfully!';
                $tokenData = null;
                
                // Redirect to login after 3 seconds
                echo '<meta http-equiv="refresh" content="3;url=login.php">';
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
        } else {
            $error = 'User not found.';
        }
    }
}

// Get email for display
if ($tokenData) {
    $user = getUserById($tokenData['user_id']);
    if ($user) {
        $email = $user['email'];
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reset Password - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #f5f3ff;
            --bg-surface: #ffffff;
            --bg-surface-low: #f5f2ff;
            --bg-surface-bright: #f8f7ff;
            --text-main: #1b1b24;
            --text-muted: #464555;
            --text-dim: #777587;
            --outline: #777587;
            --outline-variant: #c7c4d8;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-container: #4f46e5;
            --on-primary: #ffffff;
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

        * { margin: 0; padding: 0; box-sizing: border-box; }
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

        .reset-wrapper { width: 100%; max-width: 28rem; }
        .reset-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
            transition: box-shadow var(--transition-smooth);
        }
        .reset-card:hover { box-shadow: 0 24px 30px -8px rgba(27, 27, 36, 0.12); }

        .reset-header { text-align: center; margin-bottom: 2rem; }
        .reset-header .icon-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary-container);
            margin-bottom: 1rem;
        }
        .reset-header .icon-wrapper .material-symbols-outlined { font-size: 1.5rem; }
        .reset-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
            margin-bottom: 0.25rem;
        }
        .reset-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
            font-family: var(--font-label);
        }

        .message {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .message.hidden { display: none; }
        .message.success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
        .message.error { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error); }
        .message .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }

        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.375rem;
            font-family: var(--font-label);
        }

        .input-wrapper { position: relative; }
        .input-wrapper .input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--outline);
            pointer-events: none;
        }
        .input-wrapper .input-icon .material-symbols-outlined { font-size: 1.25rem; }
        .input-wrapper input {
            width: 100%;
            padding: 0.625rem 0.75rem 0.625rem 2.5rem;
            border: 1px solid var(--outline-variant);
            border-radius: var(--radius-lg);
            background: var(--bg-surface);
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
        .input-wrapper input::placeholder { color: var(--outline-variant); }
        .input-wrapper input:disabled,
        .input-wrapper input[readonly] {
            background: var(--bg-surface-bright);
            opacity: 0.7;
            cursor: not-allowed;
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
        .toggle-password:hover { color: var(--text-main); }
        .toggle-password .material-symbols-outlined { font-size: 1.25rem; }

        .helper-text {
            margin-top: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-dim);
        }

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
        .btn-submit:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn-submit:active { transform: scale(0.98); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .reset-footer {
            margin-top: 1.5rem;
            text-align: center;
        }
        .reset-footer a {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--primary);
            transition: color var(--transition-fast);
            font-family: var(--font-label);
        }
        .reset-footer a:hover { color: var(--primary-hover); }
        .reset-footer a .material-symbols-outlined { font-size: 1.125rem; }

        @media (max-width: 480px) {
            .reset-card { padding: 1.5rem; }
            .reset-header h1 { font-size: 1.25rem; }
        }
    </style>
</head>
<body>

<div class="reset-wrapper">
    <div class="reset-card">

        <div class="reset-header">
            <div class="icon-wrapper">
                <span class="material-symbols-outlined">lock_reset</span>
            </div>
            <h1>Reset Password</h1>
            <p>Please enter your new password below to regain access to your account.</p>
        </div>

        <div class="message success <?php echo empty($success) ? 'hidden' : ''; ?>" id="successMessage">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>

        <div class="message error <?php echo empty($error) ? 'hidden' : ''; ?>" id="errorMessage">
            <span class="material-symbols-outlined">error</span>
            <span id="errorText"><?php echo htmlspecialchars($error); ?></span>
        </div>

        <?php if ($tokenData): ?>
            <form method="POST" action="" id="resetForm">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <span class="material-symbols-outlined">mail</span>
                        </span>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new-password">New Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <span class="material-symbols-outlined">lock</span>
                        </span>
                        <input type="password" id="new-password" name="new_password" placeholder="········" required minlength="8">
                        <button type="button" class="toggle-password" onclick="togglePassword('new-password', 'toggle-icon-1')">
                            <span class="material-symbols-outlined" id="toggle-icon-1">visibility_off</span>
                        </button>
                    </div>
                    <div class="helper-text">Must be at least 8 characters long.</div>
                </div>

                <div class="form-group">
                    <label for="confirm-password">Confirm Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <span class="material-symbols-outlined">lock_clock</span>
                        </span>
                        <input type="password" id="confirm-password" name="confirm_password" placeholder="········" required minlength="8">
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <span>Update Password</span>
                </button>
            </form>

            <div class="reset-footer">
                <a href="login.php">
                    <span class="material-symbols-outlined">arrow_back</span>
                    Back to login
                </a>
            </div>

        <?php else: ?>
            <div style="text-align: center; padding: 1rem 0;">
                <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
                    <?php echo htmlspecialchars($error ?? 'Invalid or expired reset link.'); ?>
                </p>
                <a href="forgot_password.php" class="btn-submit" style="display: inline-flex; width: auto; padding: 0.625rem 1.5rem; text-decoration: none;">
                    Request New Link
                </a>
            </div>

            <div class="reset-footer">
                <a href="login.php">
                    <span class="material-symbols-outlined">arrow_back</span>
                    Back to login
                </a>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        
        if (input && icon) {
            const isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            icon.textContent = isVisible ? 'visibility_off' : 'visibility';
        }
    }

    const form = document.getElementById('resetForm');
    const submitBtn = document.getElementById('submitBtn');
    const errorMsg = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');
    const successMsg = document.getElementById('successMessage');

    if (form) {
        form.addEventListener('submit', function(e) {
            const password = document.getElementById('new-password').value;
            const confirm = document.getElementById('confirm-password').value;

            errorMsg.classList.add('hidden');
            successMsg.classList.add('hidden');

            if (!password) {
                e.preventDefault();
                errorText.textContent = 'Please enter a new password.';
                errorMsg.classList.remove('hidden');
                return false;
            }

            if (password.length < 8) {
                e.preventDefault();
                errorText.textContent = 'Password must be at least 8 characters long.';
                errorMsg.classList.remove('hidden');
                return false;
            }

            if (!confirm) {
                e.preventDefault();
                errorText.textContent = 'Please confirm your password.';
                errorMsg.classList.remove('hidden');
                return false;
            }

            if (password !== confirm) {
                e.preventDefault();
                errorText.textContent = 'Passwords do not match.';
                errorMsg.classList.remove('hidden');
                return false;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <span>Updating...</span>
                <span class="material-symbols-outlined" style="font-size:1.25rem; animation: spin 1s linear infinite;">refresh</span>
            `;

            return true;
        });
    }

    if (successMsg && !successMsg.classList.contains('hidden')) {
        setTimeout(function() {
            successMsg.classList.add('hidden');
        }, 5000);
    }

    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);

    console.log('ISMERS Reset Password Page loaded.');
</script>

</body>
</html>
