<?php
// logout.php - ISMERS Logout Handler with Confirmation
session_start();

// Include configuration file
require_once 'app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user info for display
$fullName = $_SESSION['full_name'] ?? 'User';
$userId = $_SESSION['user_id'];

// Check if logout is confirmed
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'true';

// Check if logout is cancelled
if (isset($_GET['cancel'])) {
    // Redirect back to dashboard based on role
    $role = $_SESSION['role'] ?? 'applicant';
    $redirect = 'index.php';
    
    switch ($role) {
        case 'admin':
            $redirect = 'portals/admin/dashboard.php';
            break;
        case 'hr_manager':
        case 'recruiter':
            $redirect = 'portals/hr/dashboard.php';
            break;
        case 'client':
            $redirect = 'portals/client/index.php';
            break;
        case 'applicant':
            $redirect = 'portals/applicant/dashboard.php';
            break;
        case 'employee':
            $redirect = 'portals/employee/index.php';
            break;
        case 'supervisor':
            $redirect = 'portals/supervisor/index.php';
            break;
        default:
            $redirect = 'index.php';
    }
    
    header('Location: ' . $redirect);
    exit;
}

// If confirmed, proceed with logout
if ($confirmed) {
    // ✅ FIX: Update last_activity to NULL (user is now offline)
    $updateSql = "UPDATE users SET last_activity = NULL WHERE id = ?";
    updateRecord($updateSql, [$userId], "i");
    
    // Log the logout activity
    logActivity($userId, 'Logout', 'user', $userId, 'User logged out successfully');
    
    // Clear all session variables
    $_SESSION = array();
    
    // If session cookies are used, delete them
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
    
    // Destroy the session
    session_destroy();
    
    // Clear remember me cookie if it exists
    if (isset($_COOKIE['remember_email'])) {
        setcookie('remember_email', '', time() - 3600, '/');
    }
    
    // Redirect to login page with logout message
    header('Location: login.php?logout=success');
    exit;
}

// If not confirmed, show confirmation page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - ISMERS</title>
    
    <style>
        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-dark: #0a2647;
            --primary-blue: #1a3a5c;
            --primary-light: #4a90d9;
            --primary-gradient: linear-gradient(135deg, #1a3a5c 0%, #4a90d9 100%);
            --white: #ffffff;
            --gray-light: #f8f9fc;
            --gray-border: #e8ecf1;
            --text-dark: #1a2a3a;
            --text-gray: #5a6a7a;
            --shadow-lg: 0 20px 60px rgba(26, 58, 92, 0.15);
            --radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--gray-light);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(160deg, #f0f5ff 0%, #ffffff 50%, #f8faff 100%);
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .logout-wrapper {
            width: 100%;
            max-width: 440px;
            animation: fadeInUp 0.6s ease-out;
        }

        .logout-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 48px 40px 40px;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(26, 58, 92, 0.06);
            text-align: center;
        }

        .logout-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #fef3c7;
            color: #d97706;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .logout-icon svg {
            width: 36px;
            height: 36px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .logout-card h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .logout-card p {
            font-size: 15px;
            color: var(--text-gray);
            margin-bottom: 24px;
        }

        .logout-card .user-name {
            font-weight: 700;
            color: var(--primary-dark);
        }

        .logout-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn {
            padding: 12px 32px;
            border: none;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(74, 144, 217, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(74, 144, 217, 0.45);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 480px) {
            .logout-card {
                padding: 32px 20px 28px;
            }

            .logout-actions {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <div class="logout-wrapper">
        <div class="logout-card">
            <div class="logout-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </div>
            <h1>Logout Confirmation</h1>
            <p>
                Are you sure you want to logout, <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>?
            </p>
            <div class="logout-actions">
                <a href="?cancel=true" class="btn btn-outline">Cancel</a>
                <a href="?confirm=true" class="btn btn-danger">Yes, Logout</a>
            </div>
        </div>
    </div>

</body>
</html>