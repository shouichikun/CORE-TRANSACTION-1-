<?php
// portals/employee/profile.php - Employee Profile Management
session_start();

// =============================================
// DEBUG MODE - Remove in production
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

// Clear any previous output
ob_clean();

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Only employee can access
if ($_SESSION['role'] !== 'employee') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Employee';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'employee';

// =============================================
// GET EMPLOYEE DATA - Using only existing columns
// =============================================
$employee = getRecord("
    SELECT u.*, 
           e.id as employee_record_id,
           e.user_id,
           e.application_id,
           e.first_name as emp_first_name,
           e.last_name as emp_last_name,
           e.email as emp_email,
           e.phone as emp_phone,
           e.position, 
           e.department, 
           e.hire_date, 
           e.status as employment_status,
           e.created_at,
           e.updated_at
    FROM users u
    JOIN employees e ON u.id = e.user_id
    WHERE u.id = ?
", [$userId], "i");

if (!$employee) {
    // If no employee record, redirect to setup
    header('Location: setup_profile.php');
    exit;
}

// =============================================
// HANDLE FORM SUBMISSION
// =============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // =============================================
    // UPDATE PERSONAL INFO
    // =============================================
    if ($action === 'update_personal') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $birth_date = $_POST['birth_date'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $civil_status = $_POST['civil_status'] ?? '';
        
        $errors = [];
        if (empty($first_name)) $errors[] = 'First name is required.';
        if (empty($last_name)) $errors[] = 'Last name is required.';
        
        if (empty($errors)) {
            // Update users table
            $sql = "UPDATE users SET 
                    first_name = ?, last_name = ?, phone = ?,
                    address = ?, city = ?, province = ?, zip_code = ?,
                    birth_date = ?, gender = ?, civil_status = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            
            $result = updateRecord($sql, [
                $first_name, $last_name, $phone,
                $address, $city, $province, $zip_code,
                $birth_date, $gender, $civil_status,
                $userId
            ], "ssssssssssi");
            
            if ($result) {
                logActivity($userId, 'Profile Updated', 'users', $userId, 'Updated personal information');
                $message = 'Personal information updated successfully!';
                $messageType = 'success';
                
                // Refresh data
                $employee = getRecord("
                    SELECT u.*, 
                           e.id as employee_record_id,
                           e.user_id,
                           e.application_id,
                           e.first_name as emp_first_name,
                           e.last_name as emp_last_name,
                           e.email as emp_email,
                           e.phone as emp_phone,
                           e.position, 
                           e.department, 
                           e.hire_date, 
                           e.status as employment_status,
                           e.created_at,
                           e.updated_at
                    FROM users u
                    JOIN employees e ON u.id = e.user_id
                    WHERE u.id = ?
                ", [$userId], "i");
            } else {
                $message = 'Failed to update profile. Please try again.';
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }
    
    // =============================================
    // CHANGE PASSWORD
    // =============================================
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $errors = [];
        if (empty($current_password)) $errors[] = 'Current password is required.';
        if (empty($new_password)) $errors[] = 'New password is required.';
        if (strlen($new_password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($new_password !== $confirm_password) $errors[] = 'Passwords do not match.';
        
        if (empty($errors)) {
            // Verify current password
            $user = getRecord("SELECT password FROM users WHERE id = ?", [$userId], "i");
            if ($user && password_verify($current_password, $user['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
                $result = updateRecord($sql, [$hashed_password, $userId], "si");
                
                if ($result) {
                    logActivity($userId, 'Password Changed', 'users', $userId, 'Changed password');
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to change password.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Current password is incorrect.';
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }
    
    // =============================================
    // UPLOAD PROFILE PICTURE
    // =============================================
    if ($action === 'upload_picture') {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $file_info = pathinfo($_FILES['profile_picture']['name']);
            $extension = strtolower($file_info['extension']);
            
            if (in_array($extension, $allowed)) {
                $upload_dir = '../../uploads/profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
                $target_file = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                    $sql = "UPDATE users SET profile_picture = ? WHERE id = ?";
                    $result = updateRecord($sql, ['uploads/profiles/' . $filename, $userId], "si");
                    
                    if ($result) {
                        logActivity($userId, 'Profile Picture Uploaded', 'users', $userId, 'Uploaded profile picture');
                        $message = 'Profile picture uploaded successfully!';
                        $messageType = 'success';
                        
                        // Refresh data
                        $employee = getRecord("
                            SELECT u.*, 
                                   e.id as employee_record_id,
                                   e.user_id,
                                   e.application_id,
                                   e.first_name as emp_first_name,
                                   e.last_name as emp_last_name,
                                   e.email as emp_email,
                                   e.phone as emp_phone,
                                   e.position, 
                                   e.department, 
                                   e.hire_date, 
                                   e.status as employment_status,
                                   e.created_at,
                                   e.updated_at
                            FROM users u
                            JOIN employees e ON u.id = e.user_id
                            WHERE u.id = ?
                        ", [$userId], "i");
                    } else {
                        $message = 'Failed to save profile picture.';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Failed to upload file.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Allowed: jpg, jpeg, png, gif';
                $messageType = 'error';
            }
        } else {
            $message = 'Please select a file to upload.';
            $messageType = 'error';
        }
    }
}

// Format dates for display
$hireDateFormatted = !empty($employee['hire_date']) ? date('F d, Y', strtotime($employee['hire_date'])) : 'Not set';
$birthDateFormatted = !empty($employee['birth_date']) ? date('F d, Y', strtotime($employee['birth_date'])) : 'Not set';

// Check if profile picture exists
$profilePic = !empty($employee['profile_picture']) ? '../../' . $employee['profile_picture'] : '';
$hasProfilePic = !empty($profilePic) && file_exists($profilePic);

// Use first_name/last_name from users table (or fallback to employees table)
$displayFirstName = $employee['first_name'] ?? $employee['emp_first_name'] ?? 'Employee';
$displayLastName = $employee['last_name'] ?? $employee['emp_last_name'] ?? '';
$displayEmail = $employee['email'] ?? $employee['emp_email'] ?? '';
$displayPhone = $employee['phone'] ?? $employee['emp_phone'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Profile - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - EMPLOYEE PROFILE
           ========================================================================== */
        :root {
            --bg-background: #f8f7fc;
            --bg-surface: #ffffff;
            --bg-surface-low: #f5f3ff;
            --bg-surface-container-low: #f5f3ff;
            --bg-surface-container-lowest: #ffffff;
            --bg-surface-container-high: #ede9fe;
            --text-on-surface: #1b1b24;
            --text-on-surface-variant: #464555;
            --text-on-background: #1b1b24;
            --outline-variant: #c7c4d8;
            --primary: #4f46e5;
            --primary-container: #4f46e5;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-500: #64748b;
            --slate-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.06), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            --radius-xl: 1rem;
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
           SIDEBAR - FIXED
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
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 1.25rem;
        }
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
        }
        .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-main-link.active { background: var(--bg-surface-container-high); color: var(--primary); }
        .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
        .sidebar-main-link .nav-text { transition: opacity 0.3s ease; }

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
        .sidebar-footer .user-card .user-info .user-email { font-size: 0.75rem; color: var(--text-on-surface-variant); }

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

        /* =============================================
           MAIN CONTENT
        ============================================= */
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

        /* =============================================
           TOP HEADER
        ============================================= */
        .top-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(199, 196, 216, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
            padding: 0 1.5rem;
            flex-shrink: 0;
            z-index: 30;
            width: 100%;
        }
        .top-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .sidebar-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(199, 196, 216, 0.3);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.5rem;
            min-height: 2.5rem;
        }
        .sidebar-toggle-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .sidebar-toggle-btn .material-symbols-outlined { font-size: 1.25rem; }
        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(199, 196, 216, 0.3);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.5rem;
            min-height: 2.5rem;
        }
        .mobile-menu-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .mobile-menu-btn .material-symbols-outlined { font-size: 1.25rem; }

        /* =============================================
           PROFILE DROPDOWN
        ============================================= */
        .profile-dropdown-wrapper { position: relative; }
        .profile-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.375rem 0.75rem 0.375rem 0.375rem;
            border-radius: var(--radius-full);
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .profile-dropdown-toggle:hover { background: var(--bg-surface-low); border-color: rgba(199, 196, 216, 0.3); }
        .profile-dropdown-toggle .avatar-small {
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
        .profile-dropdown-toggle .profile-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
        .profile-dropdown-toggle .profile-role { font-size: 0.75rem; color: var(--text-on-surface-variant); font-weight: 400; }
        .profile-dropdown-toggle .material-symbols-outlined { font-size: 1rem; color: var(--text-on-surface-variant); transition: transform var(--transition-fast); }
        .profile-dropdown-toggle.open .material-symbols-outlined:last-child { transform: rotate(180deg); }
        .profile-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 14rem;
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--slate-200);
            padding: 0.5rem;
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-0.5rem) scale(0.95);
            transition: all var(--transition-smooth);
            transform-origin: top right;
        }
        .profile-dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown-menu .dropdown-header {
            padding: 0.5rem 0.875rem 0.25rem;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }
        .profile-dropdown-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
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

        /* =============================================
           MAIN SCROLLABLE AREA
        ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }
        .main-scroll .container {
            max-width: 80rem;
            margin: 0 auto;
        }

        /* =============================================
           BREADCRUMB
        ============================================= */
        .breadcrumb-bar {
            background: var(--bg-surface-container-lowest);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
            padding: 1rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 640px) {
            .breadcrumb-bar {
                border-radius: var(--radius-2xl);
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        .breadcrumb-view {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            border-radius: 0.75rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid rgba(79, 70, 229, 0.2);
        }
        .breadcrumb-view .material-symbols-outlined { font-size: 1.25rem; }
        .breadcrumb-view .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        /* =============================================
           PAGE HEADER
        ============================================= */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 640px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        .page-header h1 { font-size: 1.875rem; font-weight: 700; color: var(--text-on-surface); letter-spacing: -0.025em; }
        .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.25rem; }

        /* =============================================
           BUTTONS
        ============================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--on-primary-fixed-variant); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--bg-surface-low); }
        .btn-success { background: #22c55e; color: white; }
        .btn-success:hover { background: #16a34a; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; border-radius: 0.5rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 1rem; }

        /* =============================================
           TOAST
        ============================================= */
        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            animation: slideUp 0.4s ease-out;
            max-width: 400px;
        }
        .toast.success { background: #22c55e; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* =============================================
           PROFILE LAYOUT
        ============================================= */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        @media (min-width: 1024px) {
            .profile-grid {
                grid-template-columns: 1fr 2fr;
            }
        }

        /* Profile Card */
        .profile-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            padding: 1.5rem;
            text-align: center;
        }
        .profile-card .profile-image-container {
            position: relative;
            width: 10rem;
            height: 10rem;
            margin: 0 auto 1rem;
        }
        .profile-card .profile-image {
            width: 10rem;
            height: 10rem;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-container);
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
        }
        .profile-card .profile-image-container .upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: 3px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .profile-card .profile-image-container .upload-btn:hover {
            transform: scale(1.1);
            background: var(--on-primary-fixed-variant);
        }
        .profile-card .profile-image-container .upload-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }
        .profile-card .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }
        .profile-card .profile-email {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }
        .profile-card .profile-role {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }
        .profile-card .profile-stats {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--slate-200);
        }
        .profile-card .profile-stats .stat-item {
            text-align: center;
        }
        .profile-card .profile-stats .stat-item .number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }
        .profile-card .profile-stats .stat-item .label {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        /* =============================================
           TABS
        ============================================= */
        .tabs {
            display: flex;
            gap: 0.25rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius-xl);
            padding: 0.25rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }
        .tab-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        .tab-btn:hover {
            color: var(--text-on-surface);
        }
        .tab-btn.active {
            background: var(--bg-surface);
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        .tab-btn .material-symbols-outlined {
            font-size: 1rem;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }

        /* =============================================
           FORMS
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .card-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }
        .card-header .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-weight: 600;
        }
        .card-header .status-badge.active { background: #d1fae5; color: #059669; }
        .card-header .status-badge.inactive { background: #fecaca; color: #dc2626; }
        .card-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }
        .form-group:last-child {
            margin-bottom: 0;
        }
        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }
        .form-group label .required {
            color: #dc2626;
            margin-left: 0.125rem;
        }
        .form-group .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        .form-group .form-control:disabled {
            background: var(--bg-surface-low);
            cursor: not-allowed;
            opacity: 0.7;
        }
        .form-group .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }
        .form-group textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }
        .form-group .helper-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }
        .form-group .helper-text .material-symbols-outlined {
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* =============================================
           EMPLOYMENT DETAILS
        ============================================= */
        .employment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .employment-grid {
                grid-template-columns: 1fr;
            }
        }
        .employment-item {
            padding: 0.75rem 1rem;
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }
        .employment-item .label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .employment-item .value {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-on-surface);
            margin-top: 0.125rem;
        }

        /* =============================================
           MESSAGES
        ============================================= */
        .message {
            padding: 0.875rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid transparent;
        }
        .message .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
            margin-top: 0.0625rem;
        }
        .message.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #16a34a;
        }
        .message.success .material-symbols-outlined { color: #16a34a; }
        .message.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }
        .message.error .material-symbols-outlined { color: #dc2626; }
        .message.info {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #2563eb;
        }
        .message.info .material-symbols-outlined { color: #2563eb; }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-xl); height: 100vh; }
            .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: inline; }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-xl); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .top-header-left .separator { display: none; }
            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role { display: none; }
            .profile-grid { grid-template-columns: 1fr; }
            .tabs { justify-content: flex-start; }
            .tab-btn { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
            .form-row { grid-template-columns: 1fr; }
            .employment-grid { grid-template-columns: 1fr; }
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
            .breadcrumb-bar { padding: 0.75rem 1rem; }
            .page-header h1 { font-size: 1.5rem; }
            .profile-card .profile-image-container { width: 8rem; height: 8rem; }
            .profile-card .profile-image { width: 8rem; height: 8rem; font-size: 2.5rem; }
            .profile-card .profile-name { font-size: 1.25rem; }
            .profile-card .profile-stats { gap: 1rem; }
            .card-header { padding: 1rem 1.25rem; }
            .card-body { padding: 1rem 1.25rem; }
            .toast { max-width: 90%; bottom: 1rem; right: 1rem; }
        }

        /* Scrollbar Styling */
        .main-scroll::-webkit-scrollbar { width: 6px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 3px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-500); }
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <span class="sidebar-brand-icon">
            <span class="material-symbols-outlined">account_balance</span>
        </span>
        <p class="sidebar-brand-text">Company Name</p>
        <p class="sidebar-brand-category">Employee Portal</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="profile.php" class="sidebar-main-link active">
            <span class="material-symbols-outlined">person</span>
            <span class="nav-text">My Profile</span>
        </a>
        <a href="leaves.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">beach_access</span>
            <span class="nav-text">Leaves</span>
        </a>
        <a href="attendance.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">schedule</span>
            <span class="nav-text">Attendance</span>
        </a>
        <a href="payroll.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">payments</span>
            <span class="nav-text">Payroll</span>
        </a>
        <a href="performance.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">stars</span>
            <span class="nav-text">Performance</span>
        </a>
        <a href="directory.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">group</span>
            <span class="nav-text">Directory</span>
        </a>
        <a href="announcements.php" class="sidebar-main-link">
            <span class="material-symbols-outlined">campaign</span>
            <span class="nav-text">Announcements</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <span class="avatar"><?php echo strtoupper(substr($displayFirstName, 0, 1) ?: 'E'); ?></span>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($displayFirstName . ' ' . $displayLastName); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($displayEmail); ?></div>
            </div>
        </div>
    </div>
</aside>

<!-- =============================================
MAIN CONTENT
============================================= -->
<div class="main-wrapper" id="mainWrapper">
    <!-- ===== TOP HEADER ===== -->
    <header class="top-header">
        <div class="top-header-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <span class="separator">|</span>
            <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
                My Profile
            </span>
        </div>
        <div class="profile-dropdown-wrapper">
            <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                <span class="avatar-small"><?php echo strtoupper(substr($displayFirstName, 0, 1) ?: 'E'); ?></span>
                <span class="profile-name"><?php echo htmlspecialchars($displayFirstName); ?></span>
                <span class="profile-role">Employee</span>
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

    <!-- Main Scrollable Content -->
    <main class="main-scroll">
        <div class="container">
            <!-- Breadcrumb -->
            <div class="breadcrumb-bar">
                <div class="breadcrumb-view">
                    <span class="material-symbols-outlined">person</span>
                    <span>My Profile</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">
                        Manage your personal information
                    </span>
                </div>
                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                    Application ID: <?php echo htmlspecialchars($employee['application_id'] ?? 'N/A'); ?>
                </span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>My Profile</h1>
                    <p>View and manage your personal and employment information</p>
                </div>
            </div>

            <!-- Toast Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $messageType; ?>" id="toastMessage">
                    <span class="material-symbols-outlined">
                        <?php echo $messageType === 'success' ? 'check_circle' : ($messageType === 'error' ? 'error' : 'info'); ?>
                    </span>
                    <div>
                        <strong><?php echo $messageType === 'success' ? 'Success!' : ($messageType === 'error' ? 'Error:' : 'Info:'); ?></strong>
                        <span style="display:block; font-weight:400;"><?php echo $message; ?></span>
                    </div>
                </div>
                <script>
                    setTimeout(() => {
                        const toast = document.getElementById('toastMessage');
                        if (toast) toast.remove();
                    }, 5000);
                </script>
            <?php endif; ?>

            <!-- Profile Grid -->
            <div class="profile-grid">
                <!-- Profile Card -->
                <div class="profile-card">
                    <div class="profile-image-container">
                        <?php if ($hasProfilePic): ?>
                            <img src="<?php echo htmlspecialchars($profilePic); ?>" alt="Profile Picture" class="profile-image">
                        <?php else: ?>
                            <div class="profile-image">
                                <?php echo strtoupper(substr($displayFirstName, 0, 1) ?: 'E'); ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <input type="hidden" name="action" value="upload_picture">
                            <input type="file" name="profile_picture" id="fileInput" accept="image/*" style="display:none;" onchange="document.getElementById('uploadForm').submit()">
                            <label for="fileInput" class="upload-btn" title="Upload Profile Picture">
                                <span class="material-symbols-outlined">photo_camera</span>
                            </label>
                        </form>
                    </div>
                    <div class="profile-name"><?php echo htmlspecialchars($displayFirstName . ' ' . $displayLastName); ?></div>
                    <div class="profile-email"><?php echo htmlspecialchars($displayEmail); ?></div>
                    <div class="profile-role">
                        <?php echo htmlspecialchars($employee['position'] ?? 'Employee'); ?> • 
                        <?php echo htmlspecialchars($employee['department'] ?? 'Department'); ?>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="number"><?php echo date('Y'); ?></div>
                            <div class="label">Year Joined</div>
                        </div>
                        <div class="stat-item">
                            <div class="number"><?php echo $employee['employment_status'] === 'active' ? '✓' : '✕'; ?></div>
                            <div class="label">Status</div>
                        </div>
                        <div class="stat-item">
                            <div class="number"><?php echo $employee['application_id'] ?? 'N/A'; ?></div>
                            <div class="label">Application ID</div>
                        </div>
                    </div>
                </div>

                <!-- Tabs Section -->
                <div>
                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab-btn active" data-tab="personal">
                            <span class="material-symbols-outlined">person</span>
                            Personal Info
                        </button>
                        <button class="tab-btn" data-tab="employment">
                            <span class="material-symbols-outlined">work</span>
                            Employment
                        </button>
                        <button class="tab-btn" data-tab="password">
                            <span class="material-symbols-outlined">key</span>
                            Change Password
                        </button>
                    </div>

                    <!-- =============================================
                    TAB: PERSONAL INFO
                    ============================================= -->
                    <div class="tab-content active" id="tab-personal">
                        <div class="card">
                            <div class="card-header">
                                <h3>
                                    <span class="material-symbols-outlined">person</span>
                                    Personal Information
                                </h3>
                                <span class="status-badge <?php echo ($employee['employment_status'] ?? 'active') === 'active' ? 'active' : 'inactive'; ?>">
                                    <?php echo ucfirst($employee['employment_status'] ?? 'Active'); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="update_personal">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>First Name <span class="required">*</span></label>
                                            <input type="text" name="first_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Last Name <span class="required">*</span></label>
                                            <input type="text" name="last_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <input type="email" class="form-control" 
                                               value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" disabled>
                                        <div class="helper-text">
                                            <span class="material-symbols-outlined">info</span>
                                            Email cannot be changed. Contact HR for updates.
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Phone Number</label>
                                            <input type="text" name="phone" class="form-control" 
                                                   placeholder="+63 912 345 6789"
                                                   value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Birth Date</label>
                                            <input type="date" name="birth_date" class="form-control" 
                                                   value="<?php echo htmlspecialchars($employee['birth_date'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Gender</label>
                                            <select name="gender" class="form-control">
                                                <option value="">Select...</option>
                                                <option value="Male" <?php echo ($employee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="Female" <?php echo ($employee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                                <option value="Other" <?php echo ($employee['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Civil Status</label>
                                            <select name="civil_status" class="form-control">
                                                <option value="">Select...</option>
                                                <option value="Single" <?php echo ($employee['civil_status'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
                                                <option value="Married" <?php echo ($employee['civil_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                                <option value="Divorced" <?php echo ($employee['civil_status'] ?? '') === 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                                <option value="Widowed" <?php echo ($employee['civil_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                                <option value="Separated" <?php echo ($employee['civil_status'] ?? '') === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Address</label>
                                        <input type="text" name="address" class="form-control" 
                                               placeholder="Street address"
                                               value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>">
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>City</label>
                                            <input type="text" name="city" class="form-control" 
                                                   placeholder="City"
                                                   value="<?php echo htmlspecialchars($employee['city'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Province</label>
                                            <input type="text" name="province" class="form-control" 
                                                   placeholder="Province"
                                                   value="<?php echo htmlspecialchars($employee['province'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>ZIP Code</label>
                                        <input type="text" name="zip_code" class="form-control" 
                                               placeholder="ZIP code"
                                               value="<?php echo htmlspecialchars($employee['zip_code'] ?? ''); ?>">
                                    </div>

                                    <div style="display:flex; gap:0.75rem; margin-top:1rem; justify-content:flex-end;">
                                        <button type="reset" class="btn btn-outline">
                                            <span class="material-symbols-outlined">clear</span>
                                            Reset
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <span class="material-symbols-outlined">save</span>
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- =============================================
                    TAB: EMPLOYMENT
                    ============================================= -->
                    <div class="tab-content" id="tab-employment">
                        <div class="card">
                            <div class="card-header">
                                <h3>
                                    <span class="material-symbols-outlined">work</span>
                                    Employment Details
                                </h3>
                                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                    Read-only information
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="employment-grid">
                                    <div class="employment-item">
                                        <div class="label">Application ID</div>
                                        <div class="value"><?php echo htmlspecialchars($employee['application_id'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="employment-item">
                                        <div class="label">Department</div>
                                        <div class="value"><?php echo htmlspecialchars($employee['department'] ?? 'Not assigned'); ?></div>
                                    </div>
                                    <div class="employment-item">
                                        <div class="label">Position</div>
                                        <div class="value"><?php echo htmlspecialchars($employee['position'] ?? 'Not assigned'); ?></div>
                                    </div>
                                    <div class="employment-item">
                                        <div class="label">Hire Date</div>
                                        <div class="value"><?php echo $hireDateFormatted; ?></div>
                                    </div>
                                    <div class="employment-item">
                                        <div class="label">Employment Status</div>
                                        <div class="value">
                                            <span class="status-badge <?php echo ($employee['employment_status'] ?? 'active') === 'active' ? 'active' : 'inactive'; ?>" style="display:inline-block; padding:0.125rem 0.5rem; border-radius:var(--radius-full); font-size:0.75rem;">
                                                <?php echo ucfirst($employee['employment_status'] ?? 'Active'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- =============================================
                    TAB: CHANGE PASSWORD
                    ============================================= -->
                    <div class="tab-content" id="tab-password">
                        <div class="card">
                            <div class="card-header">
                                <h3>
                                    <span class="material-symbols-outlined">key</span>
                                    Change Password
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="form-group">
                                        <label>Current Password <span class="required">*</span></label>
                                        <input type="password" name="current_password" class="form-control" 
                                               placeholder="Enter your current password" required>
                                        <div class="helper-text">
                                            <span class="material-symbols-outlined">lock</span>
                                            Enter your current password to verify identity
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>New Password <span class="required">*</span></label>
                                        <input type="password" name="new_password" class="form-control" 
                                               placeholder="Enter new password (min 8 characters)" required>
                                        <div class="helper-text">
                                            <span class="material-symbols-outlined">info</span>
                                            Password must be at least 8 characters long
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Confirm New Password <span class="required">*</span></label>
                                        <input type="password" name="confirm_password" class="form-control" 
                                               placeholder="Re-enter new password" required>
                                    </div>

                                    <div style="display:flex; gap:0.75rem; margin-top:1rem; justify-content:flex-end;">
                                        <button type="reset" class="btn btn-outline">
                                            <span class="material-symbols-outlined">clear</span>
                                            Reset
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <span class="material-symbols-outlined">key</span>
                                            Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
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

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', openMobileSidebar);
}
if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeMobileSidebar);
}

document.querySelectorAll('.sidebar-main-link').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }
    });
});

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
// 4. TABS
// =============================================
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        // Remove active from all tabs
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        // Add active to clicked tab
        this.classList.add('active');
        const tabId = this.dataset.tab;
        const content = document.getElementById('tab-' + tabId);
        if (content) {
            content.classList.add('active');
        }
    });
});

// =============================================
// 5. RESPONSIVE HANDLING
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

// =============================================
// 6. KEYBOARD ACCESSIBILITY
// =============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMobileSidebar();
        if (profileToggle) profileToggle.classList.remove('open');
        if (profileMenu) profileMenu.classList.remove('open');
    }
});

console.log('👤 ISMERS Employee Profile loaded successfully!');
</script>

</body>
</html>