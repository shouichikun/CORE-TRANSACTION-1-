<?php
// portals/applicant/profile.php - Applicant Profile
session_start();

// Include configuration file
require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has the correct role
if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Applicant';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// Get applications count for the badge
$applications = [];
if ($applicantId) {
    $applications = getApplicationsByApplicant($applicantId);
}
$totalApplications = count($applications);

// Get user data
$user = getUserById($userId);

// Get applicant interests
$interests = [];
if ($applicantId) {
    $interests = getApplicantInterests($applicantId);
}

// Handle profile picture upload
$uploadMessage = '';
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $file = $_FILES['profile_picture'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] === 0) {
        if (!in_array($file['type'], $allowedTypes)) {
            $uploadError = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $uploadError = 'Image size must be less than 5MB.';
        } else {
            // Create upload directory if it doesn't exist
            $uploadDir = '../../uploads/profile_pictures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Update database
                $relativePath = 'uploads/profile_pictures/' . $filename;
                $updateSql = "UPDATE applicants SET profile_picture = ? WHERE user_id = ?";
                $updated = updateRecord($updateSql, [$relativePath, $userId], "si");
                
                if ($updated) {
                    $uploadMessage = 'Profile picture updated successfully!';
                    // Refresh applicant data
                    $applicant = getApplicantByUserId($userId);
                } else {
                    $uploadError = 'Failed to update database. Please try again.';
                }
            } else {
                $uploadError = 'Failed to upload image. Please try again.';
            }
        }
    } else {
        $uploadError = 'Please select an image to upload.';
    }
}

// Parse birthday for display
$birthMonth = '';
$birthDay = '';
$birthYear = '';
if (!empty($user['birth_date'])) {
    $birthDate = date_parse($user['birth_date']);
    $birthMonth = $birthDate['month'] ?? '';
    $birthDay = $birthDate['day'] ?? '';
    $birthYear = $birthDate['year'] ?? '';
}

// Format full birthday
$formattedBirthday = '';
if ($birthMonth && $birthDay && $birthYear) {
    $dateObj = DateTime::createFromFormat('Y-m-d', $birthYear . '-' . str_pad($birthMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($birthDay, 2, '0', STR_PAD_LEFT));
    $formattedBirthday = $dateObj ? $dateObj->format('F d, Y') : '';
}

// Get profile picture
$profilePicture = $applicant['profile_picture'] ?? '';
$avatarLetter = strtoupper(substr($firstName, 0, 1) ?: 'A');
$hasProfilePicture = !empty($profilePicture) && file_exists('../../' . $profilePicture);
$profilePictureUrl = $hasProfilePicture ? '../../' . $profilePicture : '';
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
           MATERIAL 3 DESIGN SYSTEM - PROFILE PAGE
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        a {
            text-decoration: none;
            color: inherit;
        }

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

        .dashboard-sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .dashboard-sidebar.mobile-hidden {
            transform: translateX(-100%);
        }

        .dashboard-sidebar.mobile-open {
            transform: translateX(0);
        }

        /* Sidebar collapsed hide/show */
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

        .dashboard-sidebar.collapsed .sidebar-brand-card {
            padding: 1rem 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-nav {
            padding: 0.5rem 0.25rem;
        }

        .dashboard-sidebar.collapsed .sidebar-main-link {
            justify-content: center;
            padding: 0.75rem 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-footer .user-card {
            justify-content: center;
            padding: 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-footer .user-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            font-size: 0.875rem;
        }

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

        .sidebar-brand-icon .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .sidebar-brand-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--slate-900);
        }

        .sidebar-brand-category {
            font-size: 0.75rem;
            color: var(--slate-500);
            margin-top: 0.25rem;
        }

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

        .sidebar-main-link:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-main-link.active {
            background: var(--bg-surface-container-high);
            color: var(--primary);
        }

        .sidebar-main-link .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .sidebar-main-link .nav-text {
            transition: opacity 0.3s ease;
        }

        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            border-radius: 50px;
            transition: opacity 0.3s ease;
        }

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

        .sidebar-footer .user-card .user-info .user-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .sidebar-footer .user-card .user-info .user-email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* =============================================
                   SIDEBAR BACKDROP
                ============================================= */
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

        .sidebar-backdrop.active {
            display: block;
            opacity: 1;
        }

        /* =============================================
                   MAIN CONTENT - PUSHED BY SIDEBAR
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

        .dashboard-sidebar.collapsed ~ .main-wrapper {
            margin-left: var(--sidebar-collapsed);
        }

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

        .top-header-left .logo {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            background: var(--slate-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.875rem;
            color: var(--primary);
            border: 1px solid rgba(199, 196, 216, 0.3);
        }

        .top-header-left .separator {
            color: var(--outline-variant);
            font-weight: 300;
            user-select: none;
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

        .sidebar-toggle-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-toggle-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

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

        .mobile-menu-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .mobile-menu-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

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

        .profile-dropdown-toggle:hover {
            background: var(--bg-surface-low);
            border-color: rgba(199, 196, 216, 0.3);
        }

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
        }

        .profile-dropdown-toggle .profile-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .profile-dropdown-toggle .profile-role {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            font-weight: 400;
        }

        .profile-dropdown-toggle .material-symbols-outlined {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
        }

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

        .breadcrumb-view .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .breadcrumb-view .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

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

        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: var(--primary);
            color: var(--on-primary);
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
        }

        .btn-primary .material-symbols-outlined {
            font-size: 1.125rem;
        }

        /* =============================================
                   MESSAGES
                ============================================= */
        .message {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .message.success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }

        .message.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .message .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        /* =============================================
                   PROFILE CARD
                ============================================= */
        .profile-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .profile-header {
            padding: 2rem;
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--slate-200);
        }

        /* Profile Picture */
        .profile-picture-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--bg-surface);
            box-shadow: var(--shadow-md);
            background: var(--slate-100);
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .profile-picture:hover {
            opacity: 0.9;
        }

        .profile-picture-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            color: white;
            border: 4px solid var(--bg-surface);
            box-shadow: var(--shadow-md);
            cursor: pointer;
            transition: var(--transition-fast);
        }

        .profile-picture-placeholder:hover {
            opacity: 0.9;
        }

        .upload-overlay {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 2.5rem;
            height: 2.5rem;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid var(--bg-surface);
            box-shadow: var(--shadow-sm);
            transition: var(--transition-fast);
            color: white;
        }

        .upload-overlay:hover {
            transform: scale(1.1);
        }

        .upload-overlay .material-symbols-outlined {
            font-size: 1.125rem;
        }

        #profileUpload {
            display: none;
        }

        /* Profile Info */
        .profile-info {
            flex: 1;
            min-width: 200px;
        }

        .profile-info .name {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.125rem;
        }

        .profile-info .title {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 0.5rem;
        }

        .profile-info .quick-info {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
        }

        .profile-info .quick-info .info-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .profile-info .quick-info .info-item .material-symbols-outlined {
            font-size: 1rem;
        }

        /* =============================================
                   PROFILE BODY
                ============================================= */
        .profile-body {
            padding: 1.5rem 2rem 2rem;
        }

        .profile-section {
            margin-bottom: 1.75rem;
        }

        .profile-section:last-child {
            margin-bottom: 0;
        }

        .profile-section .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .profile-section .section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--slate-200);
        }

        .profile-section .section-content {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            line-height: 1.8;
        }

        .profile-section .section-content .empty-text {
            color: #aab3c0;
            font-style: italic;
        }

        /* Skills & Interests Tags */
        .skills-list,
        .interests-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .skill-tag {
            display: inline-block;
            padding: 0.25rem 0.875rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
        }

        .interest-tag {
            display: inline-block;
            padding: 0.25rem 0.875rem;
            background: rgba(124, 58, 237, 0.08);
            color: #7c3aed;
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid rgba(124, 58, 237, 0.15);
        }

        /* Empty state button */
        .empty-state-btn {
            margin-top: 0.75rem;
        }

        /* =============================================
                   RESPONSIVE
                ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop {
                display: none !important;
            }

            .mobile-menu-btn {
                display: none !important;
            }

            .dashboard-sidebar {
                position: fixed;
                transform: translateX(0) !important;
                box-shadow: var(--shadow-xl);
                height: 100vh;
            }

            .dashboard-sidebar.mobile-hidden {
                transform: translateX(0) !important;
            }

            .main-wrapper {
                margin-left: var(--sidebar-width);
            }

            .dashboard-sidebar.collapsed ~ .main-wrapper {
                margin-left: var(--sidebar-collapsed);
            }
        }

        @media (max-width: 767px) {
            .dashboard-sidebar {
                position: fixed;
                width: var(--sidebar-width);
                transform: translateX(-100%);
                box-shadow: var(--shadow-xl);
            }

            .dashboard-sidebar.mobile-open {
                transform: translateX(0);
            }

            .dashboard-sidebar.collapsed {
                width: var(--sidebar-width);
            }

            .sidebar-toggle-btn {
                display: none !important;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .main-scroll {
                padding: 1rem;
            }

            .top-header-left .separator {
                display: none;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: none;
            }

            .profile-header {
                padding: 1.25rem;
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .profile-info .quick-info {
                justify-content: center;
            }

            .profile-body {
                padding: 1.25rem;
            }

            .profile-picture,
            .profile-picture-placeholder {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }

            .profile-info .name {
                font-size: 1.375rem;
            }

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

            .dashboard-sidebar.collapsed .sidebar-brand-card {
                padding: 1.5rem;
            }

            .dashboard-sidebar.collapsed .sidebar-nav {
                padding: 1.5rem 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link {
                justify-content: flex-start;
                padding: 0.75rem 1rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
                font-size: 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-footer .user-card {
                justify-content: flex-start;
                padding: 0.5rem 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .main-scroll {
                padding: 0.75rem;
            }

            .breadcrumb-bar {
                padding: 0.75rem 1rem;
            }

            .profile-header {
                padding: 1rem;
            }

            .profile-body {
                padding: 1rem;
            }

            .profile-picture,
            .profile-picture-placeholder {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }

            .upload-overlay {
                width: 2rem;
                height: 2rem;
            }

            .upload-overlay .material-symbols-outlined {
                font-size: 0.875rem;
            }

            .profile-info .name {
                font-size: 1.25rem;
            }

            .profile-info .quick-info {
                gap: 0.5rem 1rem;
                font-size: 0.8125rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .profile-section .section-title {
                font-size: 0.875rem;
            }
        }

        /* Scrollbar Styling */
        .main-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .main-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .main-scroll::-webkit-scrollbar-thumb {
            background: var(--slate-200);
            border-radius: 3px;
        }

        .main-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--slate-500);
        }

        /* =============================================
                   PROFILE DROPDOWN
                ============================================= */
.profile-dropdown-wrapper {
    position: relative;
}

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

.profile-dropdown-toggle:hover {
    background: var(--bg-surface-low);
    border-color: rgba(199, 196, 216, 0.3);
}

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

.profile-dropdown-toggle .profile-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-on-surface);
}

.profile-dropdown-toggle .profile-role {
    font-size: 0.75rem;
    color: var(--text-on-surface-variant);
    font-weight: 400;
}

.profile-dropdown-toggle .material-symbols-outlined {
    font-size: 1rem;
    color: var(--text-on-surface-variant);
    transition: transform var(--transition-fast);
}

.profile-dropdown-toggle.open .material-symbols-outlined:last-child {
    transform: rotate(180deg);
}

/* Profile Dropdown Menu */
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

.profile-dropdown-menu.open {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
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

.profile-dropdown-menu .dropdown-item:hover {
    background: var(--bg-surface-low);
    color: var(--primary);
}

.profile-dropdown-menu .dropdown-item .material-symbols-outlined {
    font-size: 1.125rem;
    color: var(--text-on-surface-variant);
}

.profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined {
    color: var(--primary);
}

.profile-dropdown-menu .dropdown-item.danger {
    color: #dc2626;
}

.profile-dropdown-menu .dropdown-item.danger:hover {
    background: #fef2f2;
    color: #dc2626;
}

.profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined {
    color: #dc2626;
}

.profile-dropdown-menu .dropdown-divider {
    height: 1px;
    background: var(--slate-200);
    margin: 0.25rem 0.5rem;
}

.profile-dropdown-menu .dropdown-header {
    padding: 0.5rem 0.875rem 0.25rem;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-on-surface-variant);
}

@media (max-width: 767px) {
    .profile-dropdown-toggle .profile-name,
    .profile-dropdown-toggle .profile-role {
        display: none;
    }
}
    </style>
</head>
<body>

    <!-- Sidebar Backdrop (Mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =============================================
    SIDEBAR - FIXED
    ============================================= -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="px-5 pt-6 pb-5 border-b border-slate-200">
            <div class="sidebar-brand-card">
                <span class="sidebar-brand-icon">
                    <span class="material-symbols-outlined">account_balance</span>
                </span>
                <p class="sidebar-brand-text">ISMERS</p>
                <p class="sidebar-brand-category">Applicant Portal</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link active">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">search</span>
                <span class="nav-text">Job Search</span>
            </a>

            <div class="nav-label" style="margin-top:1.5rem;">Settings</div>

            <a href="settings.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">settings</span>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- =============================================
    MAIN CONTENT - PUSHED BY SIDEBAR
    ============================================= -->
    <div class="main-wrapper" id="mainWrapper">

        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
                <div class="logo">I</div>
                <span class="separator">|</span>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle Sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">menu_open</span>
                </button>
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" title="Open Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <span class="logo-text" style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface); display:none;">ISMERS</span>
            </div>

           <!-- Profile Dropdown -->
<div class="profile-dropdown-wrapper">
    <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
        <div class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></div>
        <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
        <span class="profile-role">Applicant</span>
        <span class="material-symbols-outlined">expand_more</span>
    </button>

    <!-- Dropdown Menu -->
    <div class="profile-dropdown-menu" id="profileDropdownMenu">
        <div class="dropdown-header">Account</div>
        <a href="profile.php" class="dropdown-item">
            <span class="material-symbols-outlined">person</span>
            My Profile
        </a>
        <a href="settings.php" class="dropdown-item">
            <span class="material-symbols-outlined">settings</span>
            Settings
        </a>
        <div class="dropdown-divider"></div>
        <a href="../../logout.php" class="dropdown-item danger">
            <span class="material-symbols-outlined">logout</span>
            Log Out
        </a>
    </div>
</div>
        </header>

        <!-- Main Scrollable Area -->
        <main class="main-scroll" id="mainScroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">person</span>
                        <span>My Profile</span>
                        <span class="status-dot"></span>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>My Profile</h1>
                        <p>Your professional resume and personal information</p>
                    </div>
                    <a href="edit_profile.php" class="btn-primary">
                        <span class="material-symbols-outlined">edit</span>
                        Edit Profile
                    </a>
                </div>

                <!-- Messages -->
                <?php if (!empty($uploadMessage)): ?>
                <div class="message success">
                    <span class="material-symbols-outlined">check_circle</span>
                    <span><?php echo htmlspecialchars($uploadMessage); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($uploadError)): ?>
                <div class="message error">
                    <span class="material-symbols-outlined">error</span>
                    <span><?php echo htmlspecialchars($uploadError); ?></span>
                </div>
                <?php endif; ?>

                <!-- Profile Card -->
                <div class="profile-card">

                    <!-- Profile Header -->
                    <div class="profile-header">

                        <!-- Profile Picture -->
                        <div class="profile-picture-wrapper">
                            <?php if ($hasProfilePicture): ?>
                                <img src="<?php echo $profilePictureUrl; ?>" alt="Profile Picture" class="profile-picture" id="profileImage">
                            <?php else: ?>
                                <div class="profile-picture-placeholder" id="profileImage">
                                    <?php echo $avatarLetter; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Upload Overlay -->
                            <div class="upload-overlay" id="uploadOverlay" title="Change Profile Picture">
                                <span class="material-symbols-outlined">add</span>
                            </div>

                            <!-- Hidden File Input -->
                            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                <input type="file" name="profile_picture" id="profileUpload" accept="image/*">
                            </form>
                        </div>

                        <!-- Profile Info -->
                        <div class="profile-info">
                            <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
                            <div class="title"><?php echo htmlspecialchars($user['gender'] ?? 'Applicant'); ?></div>
                            <div class="quick-info">
                                <span class="info-item">
                                    <span class="material-symbols-outlined">mail</span>
                                    <?php echo htmlspecialchars($email); ?>
                                </span>
                                <?php if (!empty($applicant['phone'])): ?>
                                <span class="info-item">
                                    <span class="material-symbols-outlined">phone</span>
                                    <?php echo htmlspecialchars($applicant['phone']); ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($applicant['address'])): ?>
                                <span class="info-item">
                                    <span class="material-symbols-outlined">location_on</span>
                                    <?php echo htmlspecialchars($applicant['address']); ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($formattedBirthday)): ?>
                                <span class="info-item">
                                    <span class="material-symbols-outlined">cake</span>
                                    <?php echo htmlspecialchars($formattedBirthday); ?>
                                </span>
                                <?php endif; ?>
                                <span class="info-item">
                                    <span class="material-symbols-outlined">verified</span>
                                    Member since <?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Body -->
                    <div class="profile-body">

                        <!-- Career Objective -->
                        <?php if (!empty($applicant['career_objective'])): ?>
                        <div class="profile-section">
                            <div class="section-title">Career Objective</div>
                            <div class="section-content">
                                <?php echo nl2br(htmlspecialchars($applicant['career_objective'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Skills -->
                        <?php if (!empty($applicant['skills'])): ?>
                        <div class="profile-section">
                            <div class="section-title">Key Skills</div>
                            <div class="section-content">
                                <div class="skills-list">
                                    <?php 
                                        $skills = array_map('trim', explode(',', $applicant['skills']));
                                        foreach ($skills as $skill):
                                            if (!empty($skill)):
                                    ?>
                                        <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                    <?php endif; endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Experience -->
                        <?php if (!empty($applicant['experience'])): ?>
                        <div class="profile-section">
                            <div class="section-title">Experience</div>
                            <div class="section-content">
                                <?php echo nl2br(htmlspecialchars($applicant['experience'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Education -->
                        <?php if (!empty($applicant['education'])): ?>
                        <div class="profile-section">
                            <div class="section-title">Education</div>
                            <div class="section-content">
                                <?php echo nl2br(htmlspecialchars($applicant['education'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Interests -->
                        <?php if (!empty($interests)): ?>
                        <div class="profile-section">
                            <div class="section-title">Areas of Interest</div>
                            <div class="section-content">
                                <div class="interests-list">
                                    <?php foreach ($interests as $interest): ?>
                                        <span class="interest-tag"><?php echo htmlspecialchars($interest); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Place of Birth -->
                        <?php if (!empty($user['place_of_birth'])): ?>
                        <div class="profile-section">
                            <div class="section-title">Place of Birth</div>
                            <div class="section-content">
                                <?php echo htmlspecialchars($user['place_of_birth']); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Empty State -->
                        <?php if (empty($applicant['career_objective']) && empty($applicant['skills']) && empty($applicant['experience']) && empty($applicant['education']) && empty($interests)): ?>
                        <div class="profile-section">
                            <div class="section-title">Profile Overview</div>
                            <div class="section-content">
                                <p class="empty-text">Your profile is empty. Click "Edit Profile" to add your career objective, skills, experience, education, and interests.</p>
                                <div class="empty-state-btn">
                                    <a href="edit_profile.php" class="btn-primary">
                                        <span class="material-symbols-outlined">add</span>
                                        Complete Your Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- =============================================
    JAVASCRIPT (Internal)
    ============================================= -->
    <script>
        (function() {
            'use strict';

            // =============================================
            // 1. SIDEBAR TOGGLE (Desktop Collapse)
            // =============================================
            const sidebar = document.getElementById('appSidebar');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');

            const savedState = localStorage.getItem('sidebarCollapsed');
            const isDesktop = window.innerWidth >= 768;

            if (savedState === 'true' && isDesktop) {
                sidebar.classList.add('collapsed');
                sidebarToggleIcon.textContent = 'menu';
            }

            sidebarToggleBtn.addEventListener('click', function() {
                if (window.innerWidth < 768) return;
                sidebar.classList.toggle('collapsed');
                const isCollapsed = sidebar.classList.contains('collapsed');
                sidebarToggleIcon.textContent = isCollapsed ? 'menu' : 'menu_open';
                localStorage.setItem('sidebarCollapsed', isCollapsed);
            });

            // =============================================
            // 2. MOBILE SIDEBAR TOGGLE
            // =============================================
            function openMobileSidebar() {
                sidebar.classList.add('mobile-open');
                sidebar.classList.remove('mobile-hidden');
                sidebarBackdrop.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeMobileSidebar() {
                sidebar.classList.remove('mobile-open');
                sidebar.classList.add('mobile-hidden');
                sidebarBackdrop.classList.remove('active');
                document.body.style.overflow = '';
            }

            mobileMenuBtn.addEventListener('click', openMobileSidebar);
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);

            document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                });
            });

            // =============================================
            // 3. RESPONSIVE HANDLING
            // =============================================
            let resizeTimer;

            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    const width = window.innerWidth;

                    if (width >= 768) {
                        closeMobileSidebar();
                        sidebar.classList.remove('mobile-open', 'mobile-hidden');
                        const saved = localStorage.getItem('sidebarCollapsed');
                        if (saved === 'true') {
                            sidebar.classList.add('collapsed');
                            sidebarToggleIcon.textContent = 'menu';
                        } else {
                            sidebar.classList.remove('collapsed');
                            sidebarToggleIcon.textContent = 'menu_open';
                        }
                    } else {
                        sidebar.classList.add('mobile-hidden');
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'menu_open';
                    }
                }, 250);
            });

            // =============================================
            // 4. PROFILE PICTURE UPLOAD
            // =============================================
            const uploadOverlay = document.getElementById('uploadOverlay');
            const profileUpload = document.getElementById('profileUpload');
            const uploadForm = document.getElementById('uploadForm');

            uploadOverlay.addEventListener('click', function(e) {
                e.stopPropagation();
                profileUpload.click();
            });

            document.getElementById('profileImage').addEventListener('click', function() {
                profileUpload.click();
            });

            profileUpload.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    uploadForm.submit();
                }
            });

            // =============================================
            // 5. KEYBOARD ACCESSIBILITY
            // =============================================
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (window.innerWidth < 768) {
                        closeMobileSidebar();
                    }
                }
            });

            // =============================================
            // 6. INITIAL STATE
            // =============================================
            if (window.innerWidth < 768) {
                sidebar.classList.add('mobile-hidden');
            }

            console.log('ISMERS Profile Page loaded successfully.');
        })();

        // =============================================
// PROFILE DROPDOWN TOGGLE
// =============================================
const profileToggle = document.getElementById('profileDropdownToggle');
const profileMenu = document.getElementById('profileDropdownMenu');

profileToggle.addEventListener('click', function(e) {
    e.stopPropagation();
    this.classList.toggle('open');
    profileMenu.classList.toggle('open');
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!profileToggle.contains(e.target) && !profileMenu.contains(e.target)) {
        profileToggle.classList.remove('open');
        profileMenu.classList.remove('open');
    }
});

// Close dropdown on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        profileToggle.classList.remove('open');
        profileMenu.classList.remove('open');
    }
});
    </script>

</body>
</html>