<?php
// portals/client/settings.php - Client Settings
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();
// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has client role
if ($_SESSION['role'] !== 'client') {
    header('Location: ../../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Client User';
$lastName = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$fullName = $_SESSION['full_name'] ?? 'Client User';
$role = $_SESSION['role'] ?? 'client';

// Get client profile - PostgreSQL version
$client = getRecord("
    SELECT c.*, u.email as user_email, u.full_name, u.first_name, u.last_name, u.phone
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.user_id = $1
", [$userId]);

if (!$client) {
    $client = [
        'company_name' => 'Your Company',
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'id' => 0
    ];
}

// =============================================
// GET PENDING AGENCY APPLICATIONS FOR SIDEBAR BADGE
// =============================================
$pendingAgencyCount = 0;
$clientId = (int)($client['id'] ?? 0);
if ($clientId > 0) {
    $pendingAgencies = getRecord("
        SELECT COUNT(*) as count FROM agency_applications 
        WHERE client_id = $1 AND status = 'pending'
    ", [$clientId]);
    if ($pendingAgencies) {
        $pendingAgencyCount = (int)($pendingAgencies['count'] ?? 0);
    }
}

$message = '';
$messageType = '';
$activeTab = $_GET['tab'] ?? 'security';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // =============================================
    // CHANGE PASSWORD - PostgreSQL version
    // =============================================
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validate
        if (empty($currentPassword)) {
            $message = 'Please enter your current password.';
            $messageType = 'error';
        } elseif (empty($newPassword)) {
            $message = 'Please enter a new password.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'New password must be at least 8 characters long.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $messageType = 'error';
        } else {
            // Verify current password
            $user = getRecord("SELECT password_hash FROM users WHERE id = $1", [$userId]);
            
            if ($user && password_verify($currentPassword, $user['password_hash'])) {
                // Hash new password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update password - PostgreSQL
                $updateSql = "UPDATE users SET password_hash = $1, updated_at = NOW() WHERE id = $2";
                $updateResult = updateRecord($updateSql, [$hashedPassword, $userId]);
                
                if ($updateResult) {
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                    
                    // Log the activity
                    if (function_exists('logActivity')) {
                        logActivity($userId, 'Password Changed', 'users', $userId, 'Password updated successfully');
                    }
                } else {
                    $message = 'Failed to update password. Please try again.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Current password is incorrect.';
                $messageType = 'error';
            }
        }
    }
    
    // =============================================
    // UPDATE NOTIFICATION SETTINGS - PostgreSQL version
    // =============================================
    if ($action === 'update_notifications') {
        $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
        $jobAlerts = isset($_POST['job_alerts']) ? 1 : 0;
        $applicationUpdates = isset($_POST['application_updates']) ? 1 : 0;
        $marketingEmails = isset($_POST['marketing_emails']) ? 1 : 0;
        
        $settingsValue = json_encode([
            'email_notifications' => $emailNotifications,
            'job_alerts' => $jobAlerts,
            'application_updates' => $applicationUpdates,
            'marketing_emails' => $marketingEmails
        ]);
        
        // Check if settings exist - PostgreSQL
        $checkResult = getRecord("SELECT id FROM user_settings WHERE user_id = $1 AND setting_type = 'notifications'", [$userId]);
        
        if ($checkResult) {
            // Update existing
            $updateSql = "UPDATE user_settings SET 
                          setting_value = $1,
                          updated_at = NOW()
                          WHERE user_id = $2 AND setting_type = 'notifications'";
            $updateResult = updateRecord($updateSql, [$settingsValue, $userId]);
        } else {
            // Insert new
            $insertSql = "INSERT INTO user_settings (user_id, setting_type, setting_value, created_at, updated_at) 
                          VALUES ($1, 'notifications', $2, NOW(), NOW())";
            $updateResult = insertRecord($insertSql, [$userId, $settingsValue]);
        }
        
        if ($updateResult !== false) {
            $message = 'Notification settings updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update notification settings.';
            $messageType = 'error';
        }
    }
    
    // =============================================
    // UPDATE PREFERENCES - PostgreSQL version
    // =============================================
    if ($action === 'update_preferences') {
        $language = $_POST['language'] ?? 'en';
        $timezone = $_POST['timezone'] ?? 'Asia/Manila';
        $dateFormat = $_POST['date_format'] ?? 'Y-m-d';
        
        $settingsValue = json_encode([
            'language' => $language,
            'timezone' => $timezone,
            'date_format' => $dateFormat
        ]);
        
        // Check if settings exist
        $checkResult = getRecord("SELECT id FROM user_settings WHERE user_id = $1 AND setting_type = 'preferences'", [$userId]);
        
        if ($checkResult) {
            $updateSql = "UPDATE user_settings SET 
                          setting_value = $1,
                          updated_at = NOW()
                          WHERE user_id = $2 AND setting_type = 'preferences'";
            $updateResult = updateRecord($updateSql, [$settingsValue, $userId]);
        } else {
            $insertSql = "INSERT INTO user_settings (user_id, setting_type, setting_value, created_at, updated_at) 
                          VALUES ($1, 'preferences', $2, NOW(), NOW())";
            $updateResult = insertRecord($insertSql, [$userId, $settingsValue]);
        }
        
        if ($updateResult !== false) {
            $message = 'Preferences updated successfully!';
            $messageType = 'success';
        } else {
            $message = 'Failed to update preferences.';
            $messageType = 'error';
        }
    }
    
    // =============================================
    // SESSION MANAGEMENT - LOGOUT ALL DEVICES - PostgreSQL version
    // =============================================
    if ($action === 'logout_all_devices') {
        // Invalidate all sessions for this user - PostgreSQL
        $updateSql = "UPDATE users SET session_token = NULL, updated_at = NOW() WHERE id = $1";
        $updateResult = updateRecord($updateSql, [$userId]);
        
        if ($updateResult) {
            $message = 'Logged out from all devices successfully!';
            $messageType = 'success';
            
            // Clear current session
            $_SESSION = array();
            session_destroy();
            
            // Redirect to login after 2 seconds
            echo '<script>
                setTimeout(function() {
                    window.location.href = "../../login.php?logout=all";
                }, 2000);
            </script>';
        } else {
            $message = 'Failed to log out from all devices.';
            $messageType = 'error';
        }
    }
    
    // =============================================
    // DELETE ACCOUNT - PostgreSQL version
    // =============================================
    if ($action === 'delete_account') {
        // Start transaction
        beginTransaction();
        
        try {
            // Delete client record first
            $deleteClient = updateRecord("DELETE FROM clients WHERE user_id = $1", [$userId]);
            
            // Delete user settings
            $deleteSettings = updateRecord("DELETE FROM user_settings WHERE user_id = $1", [$userId]);
            
            // Delete user account
            $deleteUser = updateRecord("DELETE FROM users WHERE id = $1", [$userId]);
            
            if ($deleteUser) {
                commitTransaction();
                $message = 'Account deleted successfully. You will be redirected to login.';
                $messageType = 'success';
                
                // Clear session and redirect
                $_SESSION = array();
                session_destroy();
                
                echo '<script>
                    setTimeout(function() {
                        window.location.href = "../../login.php?deleted=1";
                    }, 2000);
                </script>';
            } else {
                rollbackTransaction();
                $message = 'Failed to delete account. Please try again.';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            rollbackTransaction();
            $message = 'Error deleting account: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get user settings - PostgreSQL
$notificationSettings = [
    'email_notifications' => 1,
    'job_alerts' => 1,
    'application_updates' => 1,
    'marketing_emails' => 0
];

$preferences = [
    'language' => 'en',
    'timezone' => 'Asia/Manila',
    'date_format' => 'Y-m-d'
];

$allSettings = getRecords("SELECT setting_type, setting_value FROM user_settings WHERE user_id = $1", [$userId]);
if ($allSettings) {
    foreach ($allSettings as $setting) {
        if ($setting['setting_type'] === 'notifications') {
            $decoded = json_decode($setting['setting_value'], true);
            if ($decoded) {
                $notificationSettings = array_merge($notificationSettings, $decoded);
            }
        }
        if ($setting['setting_type'] === 'preferences') {
            $decoded = json_decode($setting['setting_value'], true);
            if ($decoded) {
                $preferences = array_merge($preferences, $decoded);
            }
        }
    }
}

$clientEmail = $client['email'] ?? $email;

// Get user profile for sidebar
$userProfile = getUserProfileData($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Settings - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           CLIENT SETTINGS
           ========================================================================== */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --bg-surface-container-low: #f5f6fa;
            --bg-surface-container-high: #eef0f5;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
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

        /* Sidebar */
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
            object-fit: cover;
        }
        .sidebar-footer .user-card .avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
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
            object-fit: cover;
        }
        .profile-dropdown-toggle .avatar-small img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
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

        /* Breadcrumb */
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
            .breadcrumb-bar { flex-direction: row; align-items: center; justify-content: space-between; }
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
        .breadcrumb-meta { font-size: 0.75rem; color: var(--text-on-surface-variant); }

        /* Settings Layout */
        .settings-container {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 1024px) {
            .settings-container { grid-template-columns: 1fr; }
        }

        /* Settings Sidebar */
        .settings-sidebar {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 1rem;
            box-shadow: var(--shadow-sm);
            height: fit-content;
        }
        .settings-sidebar .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .settings-sidebar .nav-item:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }
        .settings-sidebar .nav-item.active {
            background: var(--primary-container);
            color: var(--primary);
        }
        .settings-sidebar .nav-item .material-symbols-outlined {
            font-size: 1.25rem;
        }
        .settings-sidebar .nav-divider {
            height: 1px;
            background: var(--slate-200);
            margin: 0.5rem 0.75rem;
        }

        /* Settings Card */
        .settings-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .settings-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary);
        }
        .settings-card .card-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .settings-card .card-header h2 .material-symbols-outlined {
            color: var(--primary);
        }

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
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

        /* Form */
        .form-group { margin-bottom: 1.25rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.375rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-group label .helper {
            font-weight: 400;
            color: var(--text-on-surface-variant);
            font-size: 0.6875rem;
        }
        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-control::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }
        .form-control:disabled {
            background: var(--bg-surface-low);
            opacity: 0.7;
            cursor: not-allowed;
        }
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5168' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.5rem;
        }

        /* Toggle Switch */
        .toggle-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--slate-100);
        }
        .toggle-wrapper:last-child { border-bottom: none; }
        .toggle-wrapper .toggle-info .toggle-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }
        .toggle-wrapper .toggle-info .toggle-desc {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }
        .toggle-switch {
            position: relative;
            width: 48px;
            height: 26px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--slate-300);
            transition: var(--transition-fast);
            border-radius: 34px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: var(--transition-fast);
            border-radius: 50%;
        }
        .toggle-switch input:checked + .toggle-slider {
            background: var(--primary);
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(22px);
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.8125rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideDown 0.35s ease-out;
            max-width: 380px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .toast .material-symbols-outlined { font-size: 1.25rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .password-requirements {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
            padding: 0.5rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
        }
        .password-requirements .req {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.125rem 0;
        }
        .password-requirements .req .material-symbols-outlined {
            font-size: 0.875rem;
        }
        .password-requirements .req.valid { color: #059669; }
        .password-requirements .req.invalid { color: #dc2626; }

        /* Responsive */
        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
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
            .settings-container { grid-template-columns: 1fr; }
            .settings-sidebar { display: flex; flex-wrap: wrap; gap: 0.25rem; padding: 0.5rem; }
            .settings-sidebar .nav-item { padding: 0.375rem 0.625rem; font-size: 0.75rem; }
            .settings-sidebar .nav-divider { display: none; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .settings-card { padding: 1rem; }
            .toggle-wrapper { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }
        
        /* Profile Picture Styles */
        .avatar-img {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            background: var(--primary-container);
        }
        .avatar-small {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
            object-fit: cover;
        }
        .avatar-small img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .avatar-img-large {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        .sidebar-footer .user-card .avatar-img {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
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
    </style>
</head>
<body>

    <!-- Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <span class="sidebar-brand-icon">
                <span class="material-symbols-outlined">business</span>
            </span>
            <p class="sidebar-brand-text">ISMERS</p>
            <p class="sidebar-brand-category">Client Portal</p>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="jobs.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'jobs.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
            </a>
            <a href="agency_application.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'agency_applications.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">apartment</span>
                <span class="nav-text">Agencies</span>
                <?php if ($pendingAgencyCount > 0): ?>
                    <span class="nav-badge"><?php echo $pendingAgencyCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="employees.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Employees</span>
            </a>
            <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">person_search</span>
                <span class="nav-text">Applicants</span>
            </a>
            <a href="invoices.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">receipt</span>
                <span class="nav-text">Invoices</span>
            </a>
            <a href="support.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'support.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">support_agent</span>
                <span class="nav-text">Support</span>
            </a>
            <a href="reports.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">analytics</span>
                <span class="nav-text">Reports</span>
            </a>
            <div class="nav-label" style="margin-top:1rem;">Settings</div>
            <a href="profile.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="settings.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <!-- =============================================
        SIDEBAR FOOTER
        ============================================= -->
        <div class="sidebar-footer">
            <div class="user-card">
                <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                         alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                         class="avatar">
                <?php else: ?>
                    <span class="avatar"><?php echo $userProfile['initials']; ?></span>
                <?php endif; ?>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($userProfile['first_name']); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($userProfile['email']); ?></div>
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Settings</span>
            </div>
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                             alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                             class="avatar-small">
                    <?php else: ?>
                        <span class="avatar-small"><?php echo $userProfile['initials']; ?></span>
                    <?php endif; ?>
                    <span class="profile-name"><?php echo htmlspecialchars($userProfile['first_name']); ?></span>
                    <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">
                        <span class="material-symbols-outlined">logout</span> Logout
                    </button>
                </div>
            </div>
        </header>

        <main class="main-scroll">
            <div class="container">
                <!-- Toast Messages -->
                <?php if ($message): ?>
                    <div class="toast <?php echo $messageType; ?>" id="toastMessage">
                        <span class="material-symbols-outlined">
                            <?php echo $messageType === 'success' ? 'check_circle' : ($messageType === 'error' ? 'error' : 'info'); ?>
                        </span>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                    <script>
                        setTimeout(() => {
                            const toast = document.getElementById('toastMessage');
                            if (toast) toast.remove();
                        }, 5000);
                    </script>
                <?php endif; ?>

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">settings</span>
                        <span>Settings</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($client['company_name'] ?? 'Your Company'); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Account settings and preferences</span>
                </div>

                <!-- Settings Container -->
                <div class="settings-container">
                    <!-- Settings Sidebar -->
                    <div class="settings-sidebar">
                        <a href="?tab=security" class="nav-item <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                            <span class="material-symbols-outlined">lock</span>
                            Security
                        </a>
                        <a href="?tab=notifications" class="nav-item <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>">
                            <span class="material-symbols-outlined">notifications</span>
                            Notifications
                        </a>
                        <a href="?tab=preferences" class="nav-item <?php echo $activeTab === 'preferences' ? 'active' : ''; ?>">
                            <span class="material-symbols-outlined">tune</span>
                            Preferences
                        </a>
                        <div class="nav-divider"></div>
                        <a href="?tab=session" class="nav-item <?php echo $activeTab === 'session' ? 'active' : ''; ?>">
                            <span class="material-symbols-outlined">devices</span>
                            Sessions
                        </a>
                        <a href="?tab=danger" class="nav-item <?php echo $activeTab === 'danger' ? 'active' : ''; ?>" style="color:#dc2626;">
                            <span class="material-symbols-outlined">warning</span>
                            Danger Zone
                        </a>
                    </div>

                    <!-- Settings Content -->
                    <div>
                        <!-- SECURITY TAB -->
                        <?php if ($activeTab === 'security'): ?>
                        <div class="settings-card">
                            <div class="card-header">
                                <h2>
                                    <span class="material-symbols-outlined">lock</span>
                                    Change Password
                                </h2>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">
                                
                                <div class="form-group">
                                    <label>Current Password <span class="required">*</span></label>
                                    <input type="password" name="current_password" class="form-control" 
                                           placeholder="Enter your current password" required>
                                </div>

                                <div class="form-group">
                                    <label>New Password <span class="required">*</span></label>
                                    <input type="password" name="new_password" class="form-control" 
                                           placeholder="Enter new password" required minlength="8"
                                           id="newPassword">
                                    <div class="password-requirements">
                                        <div class="req" id="req-length">
                                            <span class="material-symbols-outlined">circle</span>
                                            At least 8 characters
                                        </div>
                                        <div class="req" id="req-uppercase">
                                            <span class="material-symbols-outlined">circle</span>
                                            At least one uppercase letter
                                        </div>
                                        <div class="req" id="req-number">
                                            <span class="material-symbols-outlined">circle</span>
                                            At least one number
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Confirm New Password <span class="required">*</span></label>
                                    <input type="password" name="confirm_password" class="form-control" 
                                           placeholder="Confirm new password" required>
                                </div>

                                <div style="display:flex; gap:0.75rem; justify-content:flex-end; margin-top:1rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <span class="material-symbols-outlined">save</span>
                                        Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- NOTIFICATIONS TAB -->
                        <?php if ($activeTab === 'notifications'): ?>
                        <div class="settings-card">
                            <div class="card-header">
                                <h2>
                                    <span class="material-symbols-outlined">notifications</span>
                                    Notification Settings
                                </h2>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_notifications">

                                <div class="toggle-wrapper">
                                    <div class="toggle-info">
                                        <div class="toggle-title">Email Notifications</div>
                                        <div class="toggle-desc">Receive important updates via email</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="email_notifications" value="1" 
                                               <?php echo $notificationSettings['email_notifications'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div class="toggle-info">
                                        <div class="toggle-title">Job Alerts</div>
                                        <div class="toggle-desc">Get notified when new matching jobs are posted</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="job_alerts" value="1" 
                                               <?php echo $notificationSettings['job_alerts'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper">
                                    <div class="toggle-info">
                                        <div class="toggle-title">Application Updates</div>
                                        <div class="toggle-desc">Receive updates about your job applications</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="application_updates" value="1" 
                                               <?php echo $notificationSettings['application_updates'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="toggle-wrapper" style="border-bottom:none;">
                                    <div class="toggle-info">
                                        <div class="toggle-title">Marketing Emails</div>
                                        <div class="toggle-desc">Receive promotional offers and updates</div>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="marketing_emails" value="1" 
                                               <?php echo $notificationSettings['marketing_emails'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>

                                <div style="display:flex; gap:0.75rem; justify-content:flex-end; margin-top:1.5rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <span class="material-symbols-outlined">save</span>
                                        Save Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- PREFERENCES TAB -->
                        <?php if ($activeTab === 'preferences'): ?>
                        <div class="settings-card">
                            <div class="card-header">
                                <h2>
                                    <span class="material-symbols-outlined">tune</span>
                                    Preferences
                                </h2>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_preferences">

                                <div class="form-group">
                                    <label>Language</label>
                                    <select name="language" class="form-control">
                                        <option value="en" <?php echo $preferences['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                                        <option value="es" <?php echo $preferences['language'] === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                        <option value="fr" <?php echo $preferences['language'] === 'fr' ? 'selected' : ''; ?>>French</option>
                                        <option value="de" <?php echo $preferences['language'] === 'de' ? 'selected' : ''; ?>>German</option>
                                        <option value="ja" <?php echo $preferences['language'] === 'ja' ? 'selected' : ''; ?>>Japanese</option>
                                        <option value="zh" <?php echo $preferences['language'] === 'zh' ? 'selected' : ''; ?>>Chinese</option>
                                        <option value="tl" <?php echo $preferences['language'] === 'tl' ? 'selected' : ''; ?>>Filipino</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Time Zone</label>
                                    <select name="timezone" class="form-control">
                                        <option value="Asia/Manila" <?php echo $preferences['timezone'] === 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila (UTC+8)</option>
                                        <option value="America/New_York" <?php echo $preferences['timezone'] === 'America/New_York' ? 'selected' : ''; ?>>America/New_York (UTC-5)</option>
                                        <option value="America/Los_Angeles" <?php echo $preferences['timezone'] === 'America/Los_Angeles' ? 'selected' : ''; ?>>America/Los_Angeles (UTC-8)</option>
                                        <option value="Europe/London" <?php echo $preferences['timezone'] === 'Europe/London' ? 'selected' : ''; ?>>Europe/London (UTC+0)</option>
                                        <option value="Europe/Paris" <?php echo $preferences['timezone'] === 'Europe/Paris' ? 'selected' : ''; ?>>Europe/Paris (UTC+1)</option>
                                        <option value="Asia/Tokyo" <?php echo $preferences['timezone'] === 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo (UTC+9)</option>
                                        <option value="Australia/Sydney" <?php echo $preferences['timezone'] === 'Australia/Sydney' ? 'selected' : ''; ?>>Australia/Sydney (UTC+11)</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Date Format</label>
                                    <select name="date_format" class="form-control">
                                        <option value="Y-m-d" <?php echo $preferences['date_format'] === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                        <option value="m/d/Y" <?php echo $preferences['date_format'] === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                        <option value="d/m/Y" <?php echo $preferences['date_format'] === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                        <option value="M d, Y" <?php echo $preferences['date_format'] === 'M d, Y' ? 'selected' : ''; ?>>Mon DD, YYYY</option>
                                    </select>
                                </div>

                                <div style="display:flex; gap:0.75rem; justify-content:flex-end; margin-top:1rem;">
                                    <button type="submit" class="btn btn-primary">
                                        <span class="material-symbols-outlined">save</span>
                                        Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- SESSIONS TAB -->
                        <?php if ($activeTab === 'session'): ?>
                        <div class="settings-card">
                            <div class="card-header">
                                <h2>
                                    <span class="material-symbols-outlined">devices</span>
                                    Active Sessions
                                </h2>
                            </div>
                            
                            <div style="margin-bottom:1.5rem;">
                                <div style="display:flex; align-items:center; gap:1rem; padding:0.75rem; background:var(--bg-surface-low); border-radius:0.75rem; border:1px solid var(--slate-200);">
                                    <span class="material-symbols-outlined" style="font-size:2rem; color:var(--primary);">computer</span>
                                    <div style="flex:1;">
                                        <div style="font-weight:600;">Current Session</div>
                                        <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                            <?php echo $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device'; ?>
                                        </div>
                                    </div>
                                    <span class="badge" style="background:#d1fae5; color:#059669; padding:0.25rem 0.75rem; border-radius:9999px; font-size:0.75rem; font-weight:600;">Active</span>
                                </div>
                            </div>

                            <div style="padding:1rem; background:#fef2f2; border-radius:0.75rem; border:1px solid #fecaca; margin-bottom:1rem;">
                                <div style="display:flex; align-items:center; gap:0.5rem; color:#dc2626;">
                                    <span class="material-symbols-outlined">info</span>
                                    <span style="font-size:0.8125rem;">Logging out from all devices will end all active sessions including this one.</span>
                                </div>
                            </div>

                            <form method="POST" onsubmit="return confirm('Are you sure you want to log out from all devices? You will be redirected to login.');">
                                <input type="hidden" name="action" value="logout_all_devices">
                                <button type="submit" class="btn btn-danger" style="width:100%; justify-content:center;">
                                    <span class="material-symbols-outlined">logout</span>
                                    Log Out From All Devices
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- DANGER ZONE TAB -->
                        <?php if ($activeTab === 'danger'): ?>
                        <div class="settings-card">
                            <div class="card-header" style="border-bottom-color:#dc2626;">
                                <h2 style="color:#dc2626;">
                                    <span class="material-symbols-outlined" style="color:#dc2626;">warning</span>
                                    Danger Zone
                                </h2>
                            </div>

                            <div style="padding:1rem; background:#fef2f2; border-radius:0.75rem; border:1px solid #fecaca; margin-bottom:1.5rem;">
                                <div style="display:flex; align-items:flex-start; gap:0.75rem;">
                                    <span class="material-symbols-outlined" style="color:#dc2626;">error</span>
                                    <div>
                                        <div style="font-weight:600; color:#dc2626;">Account Deletion</div>
                                        <div style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                            Once you delete your account, there is no going back. All your data, including jobs, 
                                            applications, and company information will be permanently removed.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" onsubmit="return confirm('⚠️ WARNING: This action cannot be undone! Are you sure you want to delete your account?');">
                                <input type="hidden" name="action" value="delete_account">
                                <button type="submit" class="btn btn-danger" style="width:100%; justify-content:center;">
                                    <span class="material-symbols-outlined">delete_forever</span>
                                    Delete Account
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // =============================================
        // SIDEBAR TOGGLE
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
        // MOBILE SIDEBAR
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
        // PROFILE DROPDOWN
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
        // PASSWORD STRENGTH CHECKER
        // =============================================
        const newPassword = document.getElementById('newPassword');
        if (newPassword) {
            newPassword.addEventListener('input', function() {
                const val = this.value;
                
                const lengthReq = document.getElementById('req-length');
                if (val.length >= 8) {
                    lengthReq.className = 'req valid';
                    lengthReq.innerHTML = '<span class="material-symbols-outlined">check_circle</span> At least 8 characters';
                } else {
                    lengthReq.className = 'req invalid';
                    lengthReq.innerHTML = '<span class="material-symbols-outlined">circle</span> At least 8 characters';
                }
                
                const upperReq = document.getElementById('req-uppercase');
                if (/[A-Z]/.test(val)) {
                    upperReq.className = 'req valid';
                    upperReq.innerHTML = '<span class="material-symbols-outlined">check_circle</span> At least one uppercase letter';
                } else {
                    upperReq.className = 'req invalid';
                    upperReq.innerHTML = '<span class="material-symbols-outlined">circle</span> At least one uppercase letter';
                }
                
                const numReq = document.getElementById('req-number');
                if (/[0-9]/.test(val)) {
                    numReq.className = 'req valid';
                    numReq.innerHTML = '<span class="material-symbols-outlined">check_circle</span> At least one number';
                } else {
                    numReq.className = 'req invalid';
                    numReq.innerHTML = '<span class="material-symbols-outlined">circle</span> At least one number';
                }
            });
        }

        // =============================================
        // KEYBOARD SHORTCUTS
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileSidebar();
                profileToggle.classList.remove('open');
                profileMenu.classList.remove('open');
            }
        });


// =============================================
// SESSION ACTIVITY MONITOR
// =============================================

let sessionTimer = null;
let warningShown = false;
const SESSION_TIMEOUT = <?php echo SESSION_TIMEOUT_SECONDS; ?>; // 7 minutes
const WARNING_TIME = 60; // Show warning 60 seconds before timeout

/**
 * Update session timer display
 */
function updateSessionTimer() {
    // Get remaining time from server
    fetch('check_session.php')
        .then(response => response.json())
        .then(data => {
            const remaining = data.remaining;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            
            // Update timer display if exists
            const timerEl = document.getElementById('sessionTimer');
            if (timerEl) {
                timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                
                // Change color when running low
                if (remaining < 60) {
                    timerEl.style.color = '#dc2626';
                    timerEl.style.fontWeight = 'bold';
                } else if (remaining < 120) {
                    timerEl.style.color = '#f59e0b';
                } else {
                    timerEl.style.color = '';
                }
            }
            
            // Show warning modal if session is about to expire
            if (remaining <= WARNING_TIME && !warningShown && remaining > 0) {
                warningShown = true;
                showSessionWarning(remaining);
            }
            
            // If session expired, redirect
            if (remaining <= 0) {
                window.location.href = '../../login.php?timeout=1';
            }
        })
        .catch(error => {
            console.log('Session check error:', error);
        });
}

/**
 * Show session expiration warning
 */
function showSessionWarning(remaining) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('sessionWarningModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sessionWarningModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        `;
        
        modal.innerHTML = `
            <div style="
                background: white;
                border-radius: 1.5rem;
                max-width: 440px;
                width: 100%;
                padding: 2rem;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: slideUp 0.3s ease;
                text-align: center;
            ">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">⏰</div>
                <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Session Expiring Soon</h2>
                <p style="color: #464555; font-size: 0.875rem; margin-bottom: 1rem;">
                    Your session will expire in <strong id="warningTimer" style="color: #dc2626;">60</strong> seconds.
                    Please click "Stay Logged In" to continue.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button onclick="extendSession()" style="
                        padding: 0.625rem 1.5rem;
                        background: #4f46e5;
                        color: white;
                        border: none;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Stay Logged In</button>
                    <button onclick="logoutNow()" style="
                        padding: 0.625rem 1.5rem;
                        background: #fef2f2;
                        color: #dc2626;
                        border: 1px solid #fecaca;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Logout</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Show modal
    modal.style.display = 'flex';
    
    // Update countdown inside modal
    const warningTimer = document.getElementById('warningTimer');
    if (warningTimer) {
        let countdown = remaining;
        const interval = setInterval(() => {
            countdown--;
            warningTimer.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(interval);
                window.location.href = '../../login.php?timeout=1';
            }
        }, 1000);
        
        // Store interval to clear it when extending
        modal.dataset.interval = interval;
    }
}

/**
 * Extend session (reset timer)
 */
function extendSession() {
    // Clear any existing warning interval
    const modal = document.getElementById('sessionWarningModal');
    if (modal && modal.dataset.interval) {
        clearInterval(parseInt(modal.dataset.interval));
    }
    
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            if (modal) modal.style.display = 'none';
            showToast('Session extended!', 'success');
        }
    })
    .catch(error => {
        console.log('Extend session error:', error);
    });
}

/**
 * Logout immediately
 */
function logoutNow() {
    window.location.href = '../../logout.php';
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.style.cssText = `
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        padding: 0.875rem 1.5rem;
        border-radius: 0.75rem;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        z-index: 100000;
        animation: slideUp 0.4s ease-out;
    `;
    if (type === 'success') toast.style.background = '#22c55e';
    else if (type === 'error') toast.style.background = '#dc2626';
    else toast.style.background = '#4f46e5';
    
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.transition = 'all 0.4s ease';
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

// =============================================
// TRACK USER ACTIVITY
// =============================================

let activityTimer = null;

function resetActivityTimer() {
    // Reset the server-side timer via AJAX
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            // Hide warning modal if shown
            const modal = document.getElementById('sessionWarningModal');
            if (modal) modal.style.display = 'none';
        }
    })
    .catch(error => console.log('Reset timer error:', error));
}

// Track user activity events
const activityEvents = ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'];
activityEvents.forEach(event => {
    document.addEventListener(event, () => {
        resetActivityTimer();
    });
});

// =============================================
// START SESSION TIMER
// =============================================

// Update timer every 10 seconds
sessionTimer = setInterval(updateSessionTimer, 10000);

// Initial update
updateSessionTimer();

console.log('⏰ Session timeout: 7 minutes');
console.log('🔄 Activity tracking enabled');


        // =============================================
        // RESPONSIVE HANDLING
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

        console.log('⚙️ ISMERS Client Settings loaded successfully!');
    </script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>