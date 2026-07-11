<?php
// portals/hr/applicants.php - Manage Applicants
session_start();

require_once '../../app/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has HR role
if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';
$isHRManager = $role === 'hr_manager';

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$jobFilter = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$searchQuery = $_GET['search'] ?? '';

// Build query conditions
$conditions = [];
$params = [];
$types = "";

// Only show applicants for jobs created by this user
$conditions[] = "jo.created_by = ?";
$params[] = $userId;
$types .= "i";

if ($statusFilter !== 'all') {
    $conditions[] = "a.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($jobFilter > 0) {
    $conditions[] = "a.job_order_id = ?";
    $params[] = $jobFilter;
    $types .= "i";
}

if (!empty($searchQuery)) {
    $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR jo.title LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// =============================================
// FIXED: Correct SQL query with proper joins
// =============================================
// The chain is: applications → applicants → users
// applications.applicant_id = applicants.id
// applicants.user_id = users.id
$sql = "SELECT a.*, a.resume_path,
        u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
        ap.profile_picture, ap.skills, ap.experience, ap.education,
        jo.title as job_title, jo.id as job_id, c.company_name,
        (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id) as total_applications
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        $whereClause
        ORDER BY a.applied_at DESC";

$applicants = getRecords($sql, $params, $types);

// Get all jobs for filter dropdown
$jobs = getRecords("SELECT id, title FROM job_orders WHERE created_by = ? ORDER BY created_at DESC", [$userId], "i");

// Get status counts
$statusCounts = ['all' => count($applicants)];
$statuses = ['pending', 'shortlisted', 'interviewed', 'hired', 'rejected', 'withdrawn'];
foreach ($statuses as $status) {
    $countSql = "SELECT COUNT(*) as count FROM applications a 
                 JOIN job_orders jo ON a.job_order_id = jo.id 
                 WHERE jo.created_by = ? AND a.status = ?";
    $result = getRecord($countSql, [$userId, $status], "is");
    $statusCounts[$status] = $result['count'] ?? 0;
}

// Status badge mapping
$statusBadges = [
    'pending' => 'badge-pending',
    'shortlisted' => 'badge-shortlisted',
    'interviewed' => 'badge-interviewed',
    'hired' => 'badge-hired',
    'rejected' => 'badge-rejected',
    'withdrawn' => 'badge-withdrawn'
];

$statusLabels = [
    'pending' => 'Pending Review',
    'shortlisted' => 'Shortlisted',
    'interviewed' => 'Interviewed',
    'hired' => 'Hired',
    'rejected' => 'Rejected',
    'withdrawn' => 'Withdrawn'
];

$allStatuses = ['all' => 'All'] + $statusLabels;

// Handle AJAX POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $applicationId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
    $newStatus = $_POST['status'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');
    
    if ($action === 'update_status' && $applicationId > 0 && in_array($newStatus, $statuses)) {
        // Get current status for logging
        $current = getRecord("SELECT status FROM applications WHERE id = ?", [$applicationId], "i");
        $oldStatus = $current['status'] ?? 'unknown';
        
        $result = updateApplicationStatus($applicationId, $newStatus);
        
        if ($result) {
            // Log the activity with feedback
            $logMessage = 'Status changed from ' . $oldStatus . ' to: ' . $newStatus;
            if (!empty($feedback)) {
                $logMessage .= ' | Feedback: ' . $feedback;
            }
            logActivity($userId, 'Application Status Updated', 'applications', $applicationId, $logMessage);
            
            echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update status.']);
        }
        exit;
    }
    
    if ($action === 'view_applicant' && $applicationId > 0) {
        // =============================================
        // FIXED: Correct SQL for viewing single applicant
        // =============================================
        $applicant = getRecord("SELECT a.*, a.cover_letter, a.resume_path,
                               u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
                               ap.skills, ap.experience, ap.education, ap.profile_picture,
                               jo.title as job_title, c.company_name,
                               (SELECT COUNT(*) FROM applications WHERE applicant_id = a.applicant_id) as total_applications
                               FROM applications a
                               JOIN applicants ap ON a.applicant_id = ap.id
                               JOIN users u ON ap.user_id = u.id
                               JOIN job_orders jo ON a.job_order_id = jo.id
                               JOIN clients c ON jo.client_id = c.id
                               WHERE a.id = ?", [$applicationId], "i");
        if ($applicant) {
            // Get resume file info
            if (!empty($applicant['resume_path'])) {
                $resumePath = '../../' . $applicant['resume_path'];
                if (file_exists($resumePath)) {
                    $applicant['resume_exists'] = true;
                    $applicant['resume_filename'] = basename($applicant['resume_path']);
                    $applicant['resume_size'] = filesize($resumePath);
                    $applicant['resume_extension'] = strtolower(pathinfo($applicant['resume_path'], PATHINFO_EXTENSION));
                } else {
                    $applicant['resume_exists'] = false;
                }
            } else {
                $applicant['resume_exists'] = false;
            }
            
            echo json_encode(['success' => true, 'applicant' => $applicant]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Applicant not found.']);
        }
        exit;
    }
}
?>
<!-- Rest of the HTML stays the same as your original code -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Applicants - ISMERS</title>
    
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
            --shadow-sm: 0 2px 8px rgba(26, 58, 92, 0.08);
            --shadow-md: 0 8px 30px rgba(26, 58, 92, 0.12);
            --shadow-lg: 0 20px 60px rgba(26, 58, 92, 0.15);
            --radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
            --success-color: #22c55e;
            --error-color: #dc2626;
            --warning-color: #f59e0b;
            --info-color: #2563eb;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--gray-light);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* ===== BUTTONS ===== */
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--error-color);
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-2px);
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
        }

        .btn-close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--text-gray);
            cursor: pointer;
            transition: var(--transition);
            padding: 0 8px;
            line-height: 1;
        }

        .btn-close-modal:hover {
            color: var(--primary-dark);
            transform: rotate(90deg);
        }

        /* =============================================
                   SIDEBAR
                ============================================= */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--white);
            border-right: 1px solid var(--gray-border);
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
            overflow: hidden;
            box-shadow: 2px 0 12px rgba(0, 0, 0, 0.04);
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 16px;
            border-bottom: 1px solid var(--gray-border);
            min-height: 72px;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-icon {
            width: 38px;
            height: 38px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-text {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-blue);
            white-space: nowrap;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .brand-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-toggle {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            color: var(--text-gray);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-toggle:hover {
            color: var(--primary-blue);
        }

        .sidebar-toggle svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: transform 0.3s ease;
        }

        .sidebar.collapsed .sidebar-toggle svg {
            transform: rotate(180deg);
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            overflow-y: auto;
        }

        .sidebar-nav .nav-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-gray);
            padding: 12px 12px 8px;
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .nav-label {
            opacity: 0;
            height: 0;
            padding: 0;
            overflow: hidden;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-gray);
            transition: var(--transition);
            margin-bottom: 2px;
            position: relative;
            white-space: nowrap;
        }

        .sidebar-nav a:hover {
            background: rgba(74, 144, 217, 0.06);
            color: var(--primary-blue);
        }

        .sidebar-nav a.active {
            background: rgba(74, 144, 217, 0.1);
            color: var(--primary-blue);
        }

        .sidebar-nav a.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 24px;
            background: var(--primary-gradient);
            border-radius: 0 4px 4px 0;
        }

        .sidebar-nav a .nav-icon {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .sidebar-nav a .nav-text {
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed a .nav-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-nav a .nav-badge {
            margin-left: auto;
            background: var(--primary-light);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 10px;
            border-radius: 50px;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed a .nav-badge {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-footer {
            padding: 16px 16px;
            border-top: 1px solid var(--gray-border);
            flex-shrink: 0;
        }

        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-footer .user-card .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .sidebar-footer .user-card .user-info {
            flex: 1;
            min-width: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        .sidebar-footer .user-card .user-info .user-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .sidebar-footer .user-card .user-info .user-email {
            font-size: 12px;
            color: var(--text-gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            color: #dc2626;
            transition: var(--transition);
            margin-top: 8px;
            border: none;
            background: none;
            cursor: pointer;
            width: 100%;
        }

        .sidebar-footer .logout-btn:hover {
            background: rgba(220, 38, 38, 0.08);
        }

        .sidebar-footer .logout-btn svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }

        .sidebar-footer .logout-btn .logout-text {
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .logout-btn .logout-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        /* =============================================
                   MOBILE SIDEBAR
                ============================================= */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 999;
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: var(--text-dark);
        }

        .mobile-menu-toggle svg {
            width: 28px;
            height: 28px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* =============================================
                   MAIN CONTENT
                ============================================= */
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            padding: 24px 32px 40px;
        }

        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed);
        }

        .applicants-wrapper {
            max-width: 1280px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header .header-left h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .page-header .header-left p {
            font-size: 15px;
            color: var(--text-gray);
            margin-top: 4px;
        }

        /* ===== SEARCH & FILTERS ===== */
        .search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 10px 16px 10px 44px;
            border: 2px solid var(--gray-border);
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            background: var(--white);
            transition: var(--transition);
            color: var(--text-dark);
        }

        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        .search-bar .search-input-wrapper .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-gray);
        }

        .search-bar .search-input-wrapper .search-icon svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filters select {
            padding: 10px 14px;
            border: 2px solid var(--gray-border);
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            background: var(--white);
            color: var(--text-dark);
            transition: var(--transition);
            cursor: pointer;
            min-width: 160px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%235a6a7a'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .filters select:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        /* ===== APPLICANTS TABLE ===== */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--gray-border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--gray-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .card-body {
            padding: 0;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 700px;
        }

        table thead {
            background: var(--gray-light);
        }

        table th {
            padding: 14px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--text-gray);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-border);
        }

        table td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--gray-border);
            vertical-align: middle;
        }

        table tbody tr:hover {
            background: rgba(74, 144, 217, 0.02);
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .applicant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .applicant-info .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .applicant-info .details .name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .applicant-info .details .email {
            font-size: 13px;
            color: var(--text-gray);
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-shortlisted { background: #dbeafe; color: #2563eb; }
        .badge-interviewed { background: #e0e7ff; color: #4f46e5; }
        .badge-hired { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fecaca; color: #dc2626; }
        .badge-withdrawn { background: #f3f4f6; color: #6b7280; }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state .empty-icon svg {
            width: 64px;
            height: 64px;
            stroke: var(--text-gray);
            fill: none;
            stroke-width: 1.5;
            opacity: 0.3;
            margin-bottom: 12px;
        }

        .empty-state h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        .empty-state p {
            font-size: 14px;
            color: var(--text-gray);
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius);
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            border-radius: var(--radius) var(--radius) 0 0;
            z-index: 1;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: var(--gray-light);
            border-radius: 0 0 var(--radius) var(--radius);
            flex-shrink: 0;
        }

        /* ===== APPLICANT DETAILS ===== */
        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-border);
            font-size: 14px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .label {
            width: 140px;
            font-weight: 600;
            color: var(--text-gray);
            flex-shrink: 0;
        }

        .detail-row .value {
            color: var(--text-dark);
            flex: 1;
        }

        .detail-row .value .skill-tag {
            display: inline-block;
            padding: 2px 12px;
            background: rgba(74, 144, 217, 0.08);
            color: var(--primary-light);
            border-radius: 50px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid rgba(74, 144, 217, 0.15);
            margin: 2px 4px 2px 0;
        }

        /* ===== RESUME STYLES ===== */
        .resume-section {
            margin-top: 16px;
            padding: 16px;
            background: var(--gray-light);
            border-radius: 10px;
            border: 1px solid var(--gray-border);
        }

        .resume-section .resume-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .resume-section .resume-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .resume-section .resume-info .resume-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 12px;
            color: white;
            flex-shrink: 0;
        }

        .resume-section .resume-info .resume-icon.pdf { background: #dc2626; }
        .resume-section .resume-info .resume-icon.doc { background: #2563eb; }
        .resume-section .resume-info .resume-icon.docx { background: #2563eb; }
        .resume-section .resume-info .resume-icon.default { background: #6b7280; }

        .resume-section .resume-info .resume-details .resume-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .resume-section .resume-info .resume-details .resume-size {
            font-size: 12px;
            color: var(--text-gray);
        }

        .resume-section .resume-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .resume-section .resume-actions .btn {
            font-size: 12px;
            padding: 6px 16px;
        }

        .resume-empty {
            text-align: center;
            padding: 20px;
            color: var(--text-gray);
        }

        .resume-empty svg {
            width: 48px;
            height: 48px;
            stroke: var(--text-gray);
            fill: none;
            stroke-width: 1.5;
            opacity: 0.3;
            margin-bottom: 8px;
        }

        /* ===== STATUS UPDATE MODAL ===== */
        .status-modal .modal {
            max-width: 480px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        .form-group label .required {
            color: var(--error-color);
            margin-left: 2px;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--gray-border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: var(--white);
            transition: var(--transition);
            color: var(--text-dark);
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group .helper-text {
            font-size: 12px;
            color: var(--text-gray);
            margin-top: 4px;
        }

        /* ===== TOAST ===== */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 14px 24px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            animation: slideUp 0.4s ease-out;
            max-width: 400px;
        }

        .toast.success {
            background: var(--success-color);
        }

        .toast.error {
            background: var(--error-color);
        }

        .toast.info {
            background: var(--primary-blue);
        }

        /* =============================================
                   RESPONSIVE
                ============================================= */
        @media (max-width: 768px) {
            :root {
                --sidebar-width: 280px;
                --sidebar-collapsed: 0px;
            }

            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width);
                transition: transform 0.3s ease;
                box-shadow: var(--shadow-lg);
                border-right: none;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar.collapsed {
                width: var(--sidebar-width);
            }

            .sidebar.collapsed .brand-text,
            .sidebar.collapsed .nav-text,
            .sidebar.collapsed .user-info,
            .sidebar.collapsed .logout-text,
            .sidebar.collapsed .nav-badge,
            .sidebar.collapsed .nav-label {
                opacity: 1;
                width: auto;
                overflow: visible;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 16px;
            }

            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .sidebar-overlay.active {
                display: block;
            }

            .sidebar-footer .logout-btn .logout-text {
                opacity: 1 !important;
                width: auto !important;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
            }

            .search-bar {
                flex-direction: column;
            }

            .filters {
                flex-direction: column;
            }

            .filters select {
                width: 100%;
            }

            table {
                font-size: 13px;
                min-width: 600px;
            }

            table th,
            table td {
                padding: 10px 14px;
            }

            .modal {
                max-width: 100%;
                margin: 10px;
                max-height: 95vh;
            }

            .modal-body {
                padding: 16px;
            }

            .detail-row {
                flex-direction: column;
                padding: 6px 0;
            }

            .detail-row .label {
                width: 100%;
                font-size: 12px;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .resume-section .resume-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .resume-section .resume-actions {
                width: 100%;
            }

            .resume-section .resume-actions .btn {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 12px;
            }

            .page-header .header-left h1 {
                font-size: 22px;
            }

            .card-header {
                padding: 14px 16px;
            }

            .card-header h3 {
                font-size: 14px;
            }

            table {
                font-size: 12px;
                min-width: 500px;
            }

            table th,
            table td {
                padding: 8px 10px;
            }

            .applicant-info .avatar {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .resume-section .resume-info {
                flex-wrap: wrap;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- ===== SIDEBAR OVERLAY (Mobile) ===== -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon">I</span>
            <span class="brand-text">ISMERS</span>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <svg viewBox="0 0 24 24">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="jobs.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
                <span class="nav-text">My Jobs</span>
            </a>

            <a href="applicants.php" class="active">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <span class="nav-text">Applicants</span>
                <span class="nav-badge"><?php echo $statusCounts['all']; ?></span>
            </a>

            <a href="post_job.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
                <span class="nav-text">Post Job</span>
            </a>

            <div class="nav-label" style="margin-top:16px;">Settings</div>

            <a href="settings.php">
                <svg class="nav-icon" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                <span class="nav-text">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
            <a href="../../logout.php" class="logout-btn">
                <svg viewBox="0 0 24 24">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                <span class="logout-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content" id="mainContent">

        <!-- Mobile Header -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open menu">
                <svg viewBox="0 0 24 24">
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <span style="font-weight:700; color:var(--primary-blue); font-size:18px; display:none;" id="mobileBrand">ISMERS</span>
            <span class="user-info" style="display:flex; align-items:center; gap:10px;">
                <span class="avatar" style="width:36px; height:36px; border-radius:50%; background:var(--primary-gradient); display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:14px;">
                    <?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?>
                </span>
            </span>
        </div>

        <div class="applicants-wrapper">

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <h1>Applicants</h1>
                    <p>Manage all applicants who applied to your jobs</p>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <span class="search-icon">
                        <svg viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="searchInput" placeholder="Search by name, email, or job title..." 
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                <?php if (!empty($searchQuery) || $statusFilter !== 'all' || $jobFilter > 0): ?>
                    <a href="applicants.php" class="btn btn-outline">Clear Filters</a>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="filters">
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <?php if ($key === 'all') continue; ?>
                        <option value="<?php echo $key; ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?> (<?php echo $statusCounts[$key] ?? 0; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="jobFilter" onchange="applyFilters()">
                    <option value="0" <?php echo $jobFilter === 0 ? 'selected' : ''; ?>>All Jobs</option>
                    <?php foreach ($jobs as $job): ?>
                        <option value="<?php echo $job['id']; ?>" <?php echo $jobFilter === $job['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($job['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Applicants Table -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <?php if ($statusFilter === 'all'): ?>
                            All Applicants
                        <?php else: ?>
                            <?php echo ucfirst($statusFilter); ?> Applicants
                        <?php endif; ?>
                    </h3>
                    <span style="font-size:13px; color:var(--text-gray);">
                        <?php echo count($applicants); ?> applicants found
                    </span>
                </div>
                <div class="card-body">
                    <?php if (empty($applicants)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            </div>
                            <h4>No Applicants Found</h4>
                            <p>
                                <?php if ($statusFilter !== 'all'): ?>
                                    You don't have any <?php echo $statusFilter; ?> applicants.
                                <?php else: ?>
                                    No applicants have applied to your jobs yet.
                                <?php endif; ?>
                            </p>
                            <br>
                            <a href="post_job.php" class="btn btn-primary">Post a Job</a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Job</th>
                                    <th>Applied</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applicants as $app): ?>
                                    <tr>
                                        <td>
                                            <div class="applicant-info">
                                                <span class="avatar">
                                                    <?php echo strtoupper(substr($app['first_name'] ?? 'A', 0, 1)); ?>
                                                </span>
                                                <div class="details">
                                                    <div class="name"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                                    <div class="email"><?php echo htmlspecialchars($app['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:500; color:var(--text-dark);">
                                                <?php echo htmlspecialchars($app['job_title'] ?? 'Position'); ?>
                                            </div>
                                            <div style="font-size:12px; color:var(--text-gray);">
                                                <?php echo htmlspecialchars($app['company_name'] ?? ''); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size:13px; color:var(--text-gray);">
                                                <?php echo date('M d, Y', strtotime($app['applied_at'] ?? 'now')); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $statusBadges[$app['status']] ?? 'badge-pending'; ?>">
                                                <?php echo $statusLabels[$app['status']] ?? ucfirst($app['status']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                                <button class="btn btn-outline btn-sm" onclick="viewApplicant(<?php echo $app['id']; ?>)">View</button>
                                                <button class="btn btn-primary btn-sm" onclick="openStatusModal(<?php echo $app['id']; ?>)">Status</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <!-- ===== MODAL: VIEW APPLICANT ===== -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Applicant Details</h2>
                <button class="btn-close-modal" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div id="viewLoading" style="text-align:center; padding:40px;">
                    <div style="width:40px; height:40px; border:4px solid var(--gray-border); border-top-color:var(--primary-light); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div>
                    <p style="margin-top:12px; color:var(--text-gray);">Loading applicant details...</p>
                </div>
                <div id="viewContent" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL: UPDATE STATUS WITH FEEDBACK ===== -->
    <div class="modal-overlay status-modal" id="statusModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Update Application Status</h2>
                <button class="btn-close-modal" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="statusForm" onsubmit="submitStatusUpdate(event)">
                    <input type="hidden" id="statusApplicationId" name="application_id">
                    
                    <div class="form-group">
                        <label for="statusSelect">Status <span class="required">*</span></label>
                        <select id="statusSelect" name="status" required>
                            <option value="">Select status...</option>
                            <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?php echo $key; ?>">
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="helper-text">Select the new status for this application</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="statusFeedback">Feedback / Notes</label>
                        <textarea id="statusFeedback" name="feedback" placeholder="Add any feedback, comments, or notes about this decision..." rows="3"></textarea>
                        <div class="helper-text">Optional: Provide feedback to the applicant (will be logged)</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
                <button class="btn btn-primary" onclick="document.getElementById('statusForm').dispatchEvent(new Event('submit'))">
                    <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/>
                        <polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/>
                    </svg>
                    Update Status
                </button>
            </div>
        </div>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // =============================================
        // 1. SIDEBAR TOGGLE
        // =============================================
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const isMobile = window.innerWidth <= 768;

        const savedState = localStorage.getItem('sidebarCollapsed');
        if (savedState === 'true' && !isMobile) {
            sidebar.classList.add('collapsed');
        }

        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth <= 768) return;
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });

        // =============================================
        // 2. MOBILE SIDEBAR
        // =============================================
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function openMobileSidebar() {
            sidebar.classList.add('mobile-open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileMenuToggle.addEventListener('click', openMobileSidebar);
        sidebarOverlay.addEventListener('click', closeMobileSidebar);

        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    closeMobileSidebar();
                }
            });
        });

        // =============================================
        // 3. RESPONSIVE
        // =============================================
        let resizeTimer;

        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                const width = window.innerWidth;
                const mobileBrand = document.getElementById('mobileBrand');

                if (width <= 768) {
                    sidebar.classList.remove('collapsed');
                    mobileBrand.style.display = 'block';
                } else {
                    sidebar.classList.remove('mobile-open');
                    sidebarOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                    mobileBrand.style.display = 'none';

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
        // 4. FILTER FUNCTIONS
        // =============================================
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const job = document.getElementById('jobFilter').value;
            
            let url = 'applicants.php?';
            if (status !== 'all') url += 'status=' + status + '&';
            if (job > 0) url += 'job_id=' + job + '&';
            if (search) url += 'search=' + encodeURIComponent(search);
            
            window.location.href = url;
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        // =============================================
        // 5. MODAL FUNCTIONS
        // =============================================
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // =============================================
        // 6. VIEW APPLICANT
        // =============================================
        function viewApplicant(applicationId) {
            openModal('viewModal');
            
            const loading = document.getElementById('viewLoading');
            const content = document.getElementById('viewContent');
            
            loading.style.display = 'block';
            content.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'view_applicant');
            formData.append('application_id', applicationId);

            fetch('applicants.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                content.style.display = 'block';

                if (data.success) {
                    const app = data.applicant;
                    const skills = (app.skills || '').split(',').filter(s => s.trim());
                    const skillsHtml = skills.map(s => 
                        '<span class="skill-tag">' + escapeHtml(s.trim()) + '</span>'
                    ).join('');

                    // Resume section
                    let resumeHtml = '';
                    if (app.resume_exists) {
                        const iconClass = app.resume_extension === 'pdf' ? 'pdf' : 
                                        (app.resume_extension === 'doc' || app.resume_extension === 'docx' ? 'doc' : 'default');
                        const sizeKB = (app.resume_size / 1024).toFixed(1);
                        const sizeLabel = app.resume_size > 1024 * 1024 ? 
                            (app.resume_size / (1024 * 1024)).toFixed(2) + ' MB' : 
                            sizeKB + ' KB';
                        
                        resumeHtml = `
                            <div class="resume-section">
                                <div class="resume-header">
                                    <div class="resume-info">
                                        <div class="resume-icon ${iconClass}">${app.resume_extension.toUpperCase()}</div>
                                        <div class="resume-details">
                                            <div class="resume-name">${escapeHtml(app.resume_filename)}</div>
                                            <div class="resume-size">${sizeLabel}</div>
                                        </div>
                                    </div>
                                    <div class="resume-actions">
                                        <a href="../../${app.resume_path}" target="_blank" class="btn btn-info btn-sm">
                                            <svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                                <circle cx="12" cy="12" r="3"/>
                                            </svg>
                                            View
                                        </a>
                                        <a href="../../${app.resume_path}" download class="btn btn-success btn-sm">
                                            <svg width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                <polyline points="7 10 12 15 17 10"/>
                                                <line x1="12" y1="15" x2="12" y2="3"/>
                                            </svg>
                                            Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        resumeHtml = `
                            <div class="resume-section">
                                <div class="resume-empty">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <path d="M16 13l-4 4-4-4"/>
                                        <path d="M12 17V9"/>
                                    </svg>
                                    <p>No resume uploaded by the applicant.</p>
                                </div>
                            </div>
                        `;
                    }

                    content.innerHTML = `
                        <div class="detail-row">
                            <span class="label">Name</span>
                            <span class="value">${escapeHtml(app.first_name)} ${escapeHtml(app.last_name)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Email</span>
                            <span class="value">${escapeHtml(app.email)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Phone</span>
                            <span class="value">${escapeHtml(app.phone || 'Not provided')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Applied For</span>
                            <span class="value"><strong>${escapeHtml(app.job_title)}</strong> (${escapeHtml(app.company_name)})</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Applied Date</span>
                            <span class="value">${formatDate(app.applied_at)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Status</span>
                            <span class="value"><span class="badge ${getStatusBadge(app.status)}">${getStatusLabel(app.status)}</span></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Skills</span>
                            <span class="value">${skillsHtml || '<span style="color:var(--text-gray);">No skills listed</span>'}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Experience</span>
                            <span class="value">${escapeHtml(app.experience || 'Not provided')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Education</span>
                            <span class="value">${escapeHtml(app.education || 'Not provided')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Cover Letter</span>
                            <span class="value">${escapeHtml(app.cover_letter || 'No cover letter provided')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Total Applications</span>
                            <span class="value">${app.total_applications || 1}</span>
                        </div>
                        <div class="detail-row" style="border-bottom: none; padding-bottom: 0;">
                            <span class="label">Resume / CV</span>
                            <span class="value">${resumeHtml}</span>
                        </div>
                    `;
                } else {
                    content.innerHTML = `
                        <div style="text-align:center; padding:20px; color:var(--error-color);">
                            <svg width="48" height="48" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto; display:block;">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            <p style="margin-top:8px;">${data.error || 'Failed to load applicant details.'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                content.style.display = 'block';
                content.innerHTML = `
                    <div style="text-align:center; padding:20px; color:var(--error-color);">
                        <svg width="48" height="48" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto; display:block;">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <p style="margin-top:8px;">Error loading applicant details. Please try again.</p>
                    </div>
                `;
            });
        }

        // =============================================
        // 7. STATUS UPDATE WITH FEEDBACK
        // =============================================
        function openStatusModal(applicationId) {
            document.getElementById('statusApplicationId').value = applicationId;
            document.getElementById('statusSelect').value = '';
            document.getElementById('statusFeedback').value = '';
            openModal('statusModal');
        }

        function submitStatusUpdate(event) {
            event.preventDefault();
            
            const form = document.getElementById('statusForm');
            const formData = new FormData(form);
            formData.append('action', 'update_status');

            const btn = document.querySelector('#statusModal .modal-footer .btn-primary');
            btn.disabled = true;
            btn.innerHTML = '<span class="loader" style="width:16px;height:16px;border-width:2px;"></span> Updating...';

            fetch('applicants.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/>
                        <polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/>
                    </svg>
                    Update Status
                `;
                
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('statusModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to update status.', 'error');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/>
                        <polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/>
                    </svg>
                    Update Status
                `;
                showToast('Error updating status. Please try again.', 'error');
            });
        }

        // =============================================
        // 8. TOAST SYSTEM
        // =============================================
        function showToast(message, type = 'info') {
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        // =============================================
        // 9. UTILITY FUNCTIONS
        // =============================================
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(date) {
            if (!date) return 'N/A';
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function getStatusBadge(status) {
            const badges = {
                'pending': 'badge-pending',
                'shortlisted': 'badge-shortlisted',
                'interviewed': 'badge-interviewed',
                'hired': 'badge-hired',
                'rejected': 'badge-rejected',
                'withdrawn': 'badge-withdrawn'
            };
            return badges[status] || 'badge-pending';
        }

        function getStatusLabel(status) {
            const labels = {
                'pending': 'Pending Review',
                'shortlisted': 'Shortlisted',
                'interviewed': 'Interviewed',
                'hired': 'Hired',
                'rejected': 'Rejected',
                'withdrawn': 'Withdrawn'
            };
            return labels[status] || status;
        }

        // =============================================
        // 10. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const viewModal = document.getElementById('viewModal');
                const statusModal = document.getElementById('statusModal');
                
                if (viewModal.classList.contains('active')) {
                    closeModal('viewModal');
                } else if (statusModal.classList.contains('active')) {
                    closeModal('statusModal');
                } else {
                    closeMobileSidebar();
                }
            }
        });

        console.log('ISMERS Applicants Management loaded successfully.');
    </script>

</body>
</html>