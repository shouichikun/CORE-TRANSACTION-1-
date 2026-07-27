<?php
// portals/client/profile.php - Client Profile Management (Resume Style - Single Container)
session_start();

require_once '../../app/config.php';

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

$userId = $_SESSION['user_id'];
$firstName = $_SESSION['first_name'] ?? 'Client User';
$lastName = $_SESSION['last_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$fullName = $_SESSION['full_name'] ?? 'Client User';
$role = $_SESSION['role'] ?? 'client'; // ADD THIS LINE

$message = '';
$messageType = '';
$isEditing = isset($_GET['edit']) && $_GET['edit'] === 'true';

// Get client profile
$client = getRecord("
    SELECT c.*, u.email as user_email, u.full_name, u.first_name, u.last_name, u.phone, u.profile_picture
    FROM clients c
    JOIN users u ON c.user_id = u.id
    WHERE c.user_id = ?
", [$userId], "i");

if (!$client) {
    $client = [
        'id' => 0,
        'company_name' => '',
        'industry' => '',
        'company_size' => '',
        'company_website' => '',
        'company_description' => '',
        'company_address' => '',
        'company_phone' => '',
        'logo_path' => '',
        'is_active' => 1,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'user_email' => $email,
        'email' => $email,
        'phone' => '',
        'profile_picture' => ''
    ];
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $companyName = trim($_POST['company_name'] ?? '');
        $industry = trim($_POST['industry'] ?? '');
        $companySize = trim($_POST['company_size'] ?? '');
        $companyWebsite = trim($_POST['company_website'] ?? '');
        $companyDescription = trim($_POST['company_description'] ?? '');
        $companyAddress = trim($_POST['company_address'] ?? '');
        $companyPhone = trim($_POST['company_phone'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($companyName)) {
            $message = 'Company name is required.';
            $messageType = 'error';
        } elseif (empty($firstName) || empty($lastName)) {
            $message = 'First name and last name are required.';
            $messageType = 'error';
        } else {
            mysqli_begin_transaction($conn);
            
            try {
                $updateUserSql = "UPDATE users SET 
                                first_name = ?,
                                last_name = ?,
                                full_name = ?,
                                phone = ?,
                                updated_at = NOW()
                                WHERE id = ?";
                $stmt = mysqli_prepare($conn, $updateUserSql);
                $fullName = $firstName . ' ' . $lastName;
                mysqli_stmt_bind_param($stmt, 'ssssi', $firstName, $lastName, $fullName, $phone, $userId);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Failed to update user information.');
                }
                mysqli_stmt_close($stmt);
                
                $updateClientSql = "UPDATE clients SET 
                                    company_name = ?,
                                    industry = ?,
                                    company_size = ?,
                                    company_website = ?,
                                    company_description = ?,
                                    company_address = ?,
                                    company_phone = ?,
                                    updated_at = NOW()
                                    WHERE user_id = ?";
                $stmt = mysqli_prepare($conn, $updateClientSql);
                mysqli_stmt_bind_param($stmt, 'sssssssi', 
                    $companyName, $industry, $companySize, $companyWebsite,
                    $companyDescription, $companyAddress, $companyPhone, $userId
                );
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Failed to update company information.');
                }
                mysqli_stmt_close($stmt);
                
                mysqli_commit($conn);
                
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['full_name'] = $fullName;
                
                $message = 'Profile updated successfully!';
                $messageType = 'success';
                
                $client = getRecord("
                    SELECT c.*, u.email as user_email, u.full_name, u.first_name, u.last_name, u.phone, u.profile_picture
                    FROM clients c
                    JOIN users u ON c.user_id = u.id
                    WHERE c.user_id = ?
                ", [$userId], "i");
                
                if ($client) {
                    $client['email'] = $client['user_email'] ?? $email;
                }
                
                header('Location: profile.php?updated=1');
                exit;
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = 'Error updating profile: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
    
    // Handle Profile Picture Upload
    if ($action === 'upload_profile_picture') {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../uploads/profile_pictures/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileInfo = pathinfo($_FILES['profile_picture']['name']);
            $extension = strtolower($fileInfo['extension']);
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($extension, $allowedExtensions)) {
                $message = 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP';
                $messageType = 'error';
            } elseif ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
                $message = 'File size must be less than 2MB.';
                $messageType = 'error';
            } else {
                $newFileName = 'profile_' . $userId . '_' . time() . '.' . $extension;
                $targetPath = $uploadDir . $newFileName;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                    if (!empty($client['profile_picture']) && file_exists('../../' . $client['profile_picture'])) {
                        unlink('../../' . $client['profile_picture']);
                    }
                    
                    $profilePath = 'uploads/profile_pictures/' . $newFileName;
                    $updateSql = "UPDATE users SET profile_picture = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $updateSql);
                    mysqli_stmt_bind_param($stmt, 'si', $profilePath, $userId);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = 'Profile picture uploaded successfully!';
                        $messageType = 'success';
                        $client['profile_picture'] = $profilePath;
                    } else {
                        $message = 'Failed to update profile picture in database.';
                        $messageType = 'error';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $message = 'Failed to upload profile picture.';
                    $messageType = 'error';
                }
            }
        } else {
            $message = 'Please select a file to upload.';
            $messageType = 'error';
        }
    }
}

// Get profile picture URL
$profilePicUrl = !empty($client['profile_picture']) && file_exists('../../' . $client['profile_picture']) ? '../../' . $client['profile_picture'] : '../../assets/default-avatar.png';
$clientEmail = $client['user_email'] ?? $client['email'] ?? $email;

// Company size options
$companySizes = [
    '1-10' => '1-10 employees',
    '11-50' => '11-50 employees',
    '51-200' => '51-200 employees',
    '201-500' => '201-500 employees',
    '501-1000' => '501-1000 employees',
    '1000+' => '1000+ employees'
];

$industries = [
    'Technology' => 'Technology',
    'Healthcare' => 'Healthcare',
    'Finance' => 'Finance',
    'Education' => 'Education',
    'Retail' => 'Retail',
    'Manufacturing' => 'Manufacturing',
    'Construction' => 'Construction',
    'Transportation' => 'Transportation',
    'Hospitality' => 'Hospitality',
    'Real Estate' => 'Real Estate',
    'Professional Services' => 'Professional Services',
    'Nonprofit' => 'Nonprofit',
    'Government' => 'Government',
    'Other' => 'Other'
];

$showSuccess = isset($_GET['updated']) && $_GET['updated'] == 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Profile - ISMERS Client</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           RESUME STYLE PROFILE - SINGLE CONTAINER
           ========================================================================== */
        :root {
            --bg-background: #f0f2f5;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --text-on-surface: #1a1a2e;
            --text-on-surface-variant: #4a4a6a;
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --primary-dark: #4338ca;
            --primary-container: #eef0ff;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 10px 10px -5px rgba(0, 0, 0, 0.02);
            --radius-lg: 1rem;
            --radius-xl: 1.25rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
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
            box-shadow: var(--shadow-sm);
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

        /* =============================================
           SINGLE CONTAINER RESUME PROFILE
        ============================================= */
        .resume-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        /* Profile Header */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 2rem;
            background: var(--bg-surface-low);
            border-bottom: 3px solid var(--primary);
            flex-wrap: wrap;
        }
        @media (max-width: 640px) {
            .profile-header { flex-direction: column; text-align: center; }
        }

        .profile-header .avatar-wrapper {
            position: relative;
            flex-shrink: 0;
        }
        .profile-header .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--primary-container);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 2.5rem;
            object-fit: cover;
            border: 4px solid var(--primary);
        }
        .profile-header .avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .profile-header .avatar-overlay {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: var(--primary);
            color: white;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid var(--bg-surface-low);
            transition: all var(--transition-fast);
        }
        .profile-header .avatar-overlay:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }
        .profile-header .avatar-overlay .material-symbols-outlined {
            font-size: 1rem;
        }

        .profile-header .header-info {
            flex: 1;
        }
        .profile-header .header-info .full-name {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-on-surface);
        }
        .profile-header .header-info .position-title {
            font-size: 0.875rem;
            color: var(--primary);
            font-weight: 600;
        }
        .profile-header .header-info .header-meta {
            display: flex;
            gap: 1.5rem;
            margin-top: 0.375rem;
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            flex-wrap: wrap;
        }
        .profile-header .header-info .header-meta .material-symbols-outlined {
            font-size: 1rem;
            vertical-align: middle;
            color: var(--primary);
        }

        .profile-header .header-actions {
            flex-shrink: 0;
        }

        /* Divider Lines */
        .section-divider {
            height: 2px;
            background: linear-gradient(to right, var(--primary), var(--primary-light), transparent);
            margin: 0;
            border: none;
            opacity: 0.6;
        }

        /* Section Styles */
        .profile-section {
            padding: 1.5rem 2rem;
        }
        .profile-section:last-child {
            padding-bottom: 2rem;
        }

        .profile-section .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-on-surface);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
        }
        .profile-section .section-title .material-symbols-outlined {
            color: var(--primary);
            font-size: 1.25rem;
        }

        .profile-section .section-content {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            line-height: 1.7;
        }

        /* Field Grid */
        .field-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 0.5rem 1rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--slate-100);
        }
        .field-grid:last-child {
            border-bottom: none;
        }
        .field-grid .field-label {
            font-weight: 600;
            color: var(--text-on-surface);
        }
        .field-grid .field-value {
            color: var(--text-on-surface-variant);
        }
        .field-grid .field-value .empty {
            color: var(--slate-400);
            font-style: italic;
        }

        .field-grid .company-description {
            background: var(--bg-surface-low);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border-left: 4px solid var(--primary);
            margin-top: 0.25rem;
            white-space: pre-wrap;
        }

        /* Edit Mode */
        .edit-mode .field-grid {
            grid-template-columns: 140px 1fr;
        }
        .edit-mode .form-control {
            width: 100%;
            padding: 0.375rem 0.75rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .edit-mode .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .edit-mode .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }
        .edit-mode textarea.form-control {
            resize: vertical;
            min-height: 60px;
        }
        .edit-mode select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5168' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.5rem;
        }
        .edit-mode .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .edit-mode .form-row { grid-template-columns: 1fr; }
            .edit-mode .field-grid { grid-template-columns: 1fr; }
        }

        .edit-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 2px solid var(--slate-100);
            justify-content: flex-end;
        }

        /* Buttons */
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
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-outline:hover { background: var(--primary-container); }
        .btn-ghost { background: transparent; color: var(--text-on-surface-variant); }
        .btn-ghost:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn .material-symbols-outlined { font-size: 1.125rem; }
        .btn-sm .material-symbols-outlined { font-size: 0.875rem; }

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
            .profile-header { flex-direction: column; text-align: center; }
            .field-grid { grid-template-columns: 1fr; }
            .profile-section { padding: 1rem 1.25rem; }
            .profile-header { padding: 1.25rem; }
            .edit-mode .field-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .breadcrumb-bar { padding: 0.625rem 0.875rem; }
            .profile-header .avatar { width: 80px; height: 80px; font-size: 2rem; }
            .profile-section { padding: 0.75rem 1rem; }
            .profile-section:last-child { padding-bottom: 1rem; }
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

/* Sidebar user card with profile picture */
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
            <a href="dashboard.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="jobs.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
            </a>
            <a href="employees.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Employees</span>
            </a>
            <a href="applicants.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person_search</span>
                <span class="nav-text">Applicants</span>
            </a>
            <a href="invoices.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">receipt</span>
                <span class="nav-text">Invoices</span>
            </a>
            <a href="support.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">support_agent</span>
                <span class="nav-text">Support</span>
            </a>
            <div class="nav-label" style="margin-top:1rem;">Settings</div>
            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>
      <?php
// Get user profile data for sidebar
$userProfile = getUserProfileData($userId);
?>
<!-- Sidebar Footer -->
<div class="sidebar-footer">
    <div class="user-card">
        <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                 alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                 class="avatar-img" 
                 style="width:2.25rem; height:2.25rem; border-radius:50%; object-fit:cover; flex-shrink:0;">
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
                <span style="font-weight:600; font-size:0.8125rem; color:var(--text-on-surface);">Dashboard</span>
            </div>
         <?php
// Get user profile data for dropdown
$userProfile = getUserProfileData($userId);
?>
<div class="profile-dropdown-wrapper">
    <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
        <?php if (!empty($userProfile['profile_picture']) && file_exists('../../' . $userProfile['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($userProfile['avatar_url']); ?>" 
                 alt="<?php echo htmlspecialchars($userProfile['first_name']); ?>" 
                 class="avatar-small" 
                 style="width:2rem; height:2rem; border-radius:50%; object-fit:cover; flex-shrink:0; background:var(--primary);">
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

                <?php if ($showSuccess): ?>
                    <div class="toast success" id="successToast">
                        <span class="material-symbols-outlined">check_circle</span>
                        Profile updated successfully!
                    </div>
                    <script>
                        setTimeout(() => {
                            const toast = document.getElementById('successToast');
                            if (toast) toast.remove();
                        }, 4000);
                    </script>
                <?php endif; ?>

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">person</span>
                        <span>Profile</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo htmlspecialchars($client['company_name'] ?? 'Your Company'); ?>
                        </span>
                    </div>
                    <span class="breadcrumb-meta">
                        <?php if ($isEditing): ?>
                            <span style="color:var(--primary); font-weight:600;">✏️ Editing Mode</span>
                        <?php else: ?>
                            Member since <?php echo date('M d, Y', strtotime($client['created_at'] ?? 'now')); ?>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Single Resume Card -->
                <div class="resume-card <?php echo $isEditing ? 'edit-mode' : ''; ?>">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="avatar-wrapper">
                            <?php if (!empty($client['profile_picture']) && file_exists('../../' . $client['profile_picture'])): ?>
                                <img src="<?php echo $profilePicUrl; ?>" alt="Profile Picture" class="avatar">
                            <?php else: ?>
                                <div class="avatar">
                                    <?php echo strtoupper(substr($client['first_name'] ?? 'C', 0, 1) . substr($client['last_name'] ?? '', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="avatar-overlay" onclick="document.getElementById('profilePicInput').click()" title="Upload Profile Picture">
                                <span class="material-symbols-outlined">camera_alt</span>
                            </div>
                            <form method="POST" enctype="multipart/form-data" style="display:none;">
                                <input type="hidden" name="action" value="upload_profile_picture">
                                <input type="file" id="profilePicInput" name="profile_picture" accept="image/*" onchange="this.form.submit()">
                            </form>
                        </div>

                        <div class="header-info">
                            <div class="full-name"><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></div>
                            <div class="position-title"><?php echo htmlspecialchars($client['company_name'] ?? 'Company Representative'); ?></div>
                            <div class="header-meta">
                                <span>
                                    <span class="material-symbols-outlined">email</span>
                                    <?php echo htmlspecialchars($clientEmail); ?>
                                </span>
                                <?php if (!empty($client['phone'])): ?>
                                <span>
                                    <span class="material-symbols-outlined">phone</span>
                                    <?php echo htmlspecialchars($client['phone']); ?>
                                </span>
                                <?php endif; ?>
                                <span>
                                    <span class="material-symbols-outlined">business</span>
                                    <?php echo htmlspecialchars($client['industry'] ?? 'Industry not set'); ?>
                                </span>
                                <span>
                                    <span class="material-symbols-outlined">groups</span>
                                    <?php echo htmlspecialchars($client['company_size'] ?? 'Size not set'); ?>
                                </span>
                            </div>
                        </div>

                        <div class="header-actions">
                            <?php if (!$isEditing): ?>
                                <a href="?edit=true" class="btn btn-primary">
                                    <span class="material-symbols-outlined">edit</span>
                                    Edit Profile
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Company Information Section -->
                    <div class="profile-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">business</span>
                            Company Information
                            <?php if ($isEditing): ?>
                                <span style="font-size:0.7rem; color:var(--primary); font-weight:600; margin-left:auto;">✏️ Editing</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isEditing): ?>
                            <form method="POST" class="section-content">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="field-grid">
                                    <span class="field-label">Company Name <span style="color:#dc2626;">*</span></span>
                                    <input type="text" name="company_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($client['company_name'] ?? ''); ?>" required>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Industry</span>
                                    <select name="industry" class="form-control">
                                        <option value="">Select industry...</option>
                                        <?php foreach ($industries as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo ($client['industry'] ?? '') === $key ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Company Size</span>
                                    <select name="company_size" class="form-control">
                                        <option value="">Select size...</option>
                                        <?php foreach ($companySizes as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo ($client['company_size'] ?? '') === $key ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Website</span>
                                    <input type="url" name="company_website" class="form-control" 
                                           placeholder="https://example.com" 
                                           value="<?php echo htmlspecialchars($client['company_website'] ?? ''); ?>">
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Phone</span>
                                    <input type="tel" name="company_phone" class="form-control" 
                                           placeholder="(123) 456-7890" 
                                           value="<?php echo htmlspecialchars($client['company_phone'] ?? ''); ?>">
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Address</span>
                                    <textarea name="company_address" class="form-control" rows="2" 
                                              placeholder="Enter company address..."><?php echo htmlspecialchars($client['company_address'] ?? ''); ?></textarea>
                                </div>
                                <div class="field-grid" style="border-bottom:none; padding-bottom:0;">
                                    <span class="field-label">Description</span>
                                    <textarea name="company_description" class="form-control" rows="3" 
                                              placeholder="Describe your company..."><?php echo htmlspecialchars($client['company_description'] ?? ''); ?></textarea>
                                </div>
                                <div class="edit-actions">
                                    <a href="profile.php" class="btn btn-ghost">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <span class="material-symbols-outlined">save</span>
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="section-content">
                                <div class="field-grid">
                                    <span class="field-label">Company Name</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['company_name'] ?? 'Not set'); ?></span>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Industry</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['industry'] ?? 'Not set'); ?></span>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Company Size</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['company_size'] ?? 'Not set'); ?></span>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Website</span>
                                    <span class="field-value">
                                        <?php if (!empty($client['company_website'])): ?>
                                            <a href="<?php echo htmlspecialchars($client['company_website']); ?>" target="_blank" style="color:var(--primary);">
                                                <?php echo htmlspecialchars($client['company_website']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="empty">Not set</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Phone</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['company_phone'] ?? 'Not set'); ?></span>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Address</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['company_address'] ?? 'Not set'); ?></span>
                                </div>
                                <div class="field-grid" style="border-bottom:none; padding-bottom:0;">
                                    <span class="field-label">Description</span>
                                    <span class="field-value">
                                        <?php if (!empty($client['company_description'])): ?>
                                            <div class="company-description">
                                                <?php echo nl2br(htmlspecialchars($client['company_description'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="empty">No description provided</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Divider Line -->
                    <hr class="section-divider">

                    <!-- Personal Information Section -->
                    <div class="profile-section">
                        <div class="section-title">
                            <span class="material-symbols-outlined">person</span>
                            Personal Information
                        </div>

                        <?php if ($isEditing): ?>
                            <form method="POST" class="section-content">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="field-grid">
                                    <span class="field-label">First Name <span style="color:#dc2626;">*</span></span>
                                    <input type="text" name="first_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($client['first_name'] ?? ''); ?>" required>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Last Name <span style="color:#dc2626;">*</span></span>
                                    <input type="text" name="last_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($client['last_name'] ?? ''); ?>" required>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Email</span>
                                    <input type="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($clientEmail); ?>" disabled
                                           style="background:var(--bg-surface-low); opacity:0.7;">
                                    <div style="font-size:0.6875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                                        <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">info</span>
                                        Email cannot be changed. Contact support for assistance.
                                    </div>
                                </div>
                                <div class="field-grid" style="border-bottom:none; padding-bottom:0;">
                                    <span class="field-label">Phone</span>
                                    <input type="tel" name="phone" class="form-control" 
                                           placeholder="(123) 456-7890" 
                                           value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
                                </div>
                                <div class="edit-actions">
                                    <a href="profile.php" class="btn btn-ghost">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <span class="material-symbols-outlined">save</span>
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="section-content">
                                <div class="field-grid">
                                    <span class="field-label">Full Name</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></span>
                                </div>
                                <div class="field-grid">
                                    <span class="field-label">Email</span>
                                    <span class="field-value"><?php echo htmlspecialchars($clientEmail); ?></span>
                                </div>
                                <div class="field-grid" style="border-bottom:none; padding-bottom:0;">
                                    <span class="field-label">Phone</span>
                                    <span class="field-value"><?php echo htmlspecialchars($client['phone'] ?? 'Not set'); ?></span>
                                </div>
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

        // =============================================
        // PROFILE PICTURE UPLOAD
        // =============================================
        document.querySelector('input[name="profile_picture"]')?.addEventListener('change', function() {
            if (this.files.length > 0) {
                this.closest('form').submit();
            }
        });

        console.log('👤 ISMERS Client Profile (Single Container) loaded successfully!');
    </script>

</body>
</html>