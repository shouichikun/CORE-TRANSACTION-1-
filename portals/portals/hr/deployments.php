<?php
// portals/hr/deployments.php - Manage Employee Deployments
// FIXED: PostgreSQL compatibility + proper error handling

session_start();

// =============================================
// ERROR REPORTING - DISABLE WARNINGS
// =============================================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../app/config.php';
initSessionTimeout();
// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter', 'admin'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';
$employeeFilter = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

// Build query conditions - PostgreSQL syntax
$conditions = [];
$params = [];
$counter = 1;

if ($statusFilter !== 'all') {
    $conditions[] = "d.status = $" . $counter++;
    $params[] = $statusFilter;
}

if ($employeeFilter > 0) {
    $conditions[] = "d.employee_id = $" . $counter++;
    $params[] = $employeeFilter;
}

if (!empty($searchQuery)) {
    $conditions[] = "(e.first_name ILIKE $" . $counter . " OR e.last_name ILIKE $" . ($counter+1) . " OR e.email ILIKE $" . ($counter+2) . " OR c.company_name ILIKE $" . ($counter+3) . ")";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $counter += 4;
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get deployments with employee and client info - PostgreSQL syntax
$sql = "SELECT d.*, 
        e.id as employee_id, e.first_name, e.last_name, e.email, e.position,
        c.company_name, c.id as client_id,
        jo.title as job_title, jo.id as job_id
        FROM deployments d
        JOIN employees e ON d.employee_id = e.id
        JOIN clients c ON d.client_id = c.id
        JOIN job_orders jo ON d.job_order_id = jo.id
        $whereClause
        ORDER BY d.created_at DESC";

$deployments = @getRecords($sql, $params);
if (!is_array($deployments)) $deployments = [];

// Get status counts - PostgreSQL syntax
$statusCounts = ['all' => count($deployments)];
$statuses = ['active', 'completed', 'terminated', 'on_hold'];
foreach ($statuses as $status) {
    $countResult = @getRecord("SELECT COUNT(*) as count FROM deployments WHERE status = $1", [$status]);
    $statusCounts[$status] = isset($countResult['count']) ? (int)$countResult['count'] : 0;
}

// =============================================
// GET ELIGIBLE EMPLOYEES FOR DEPLOYMENT - PostgreSQL syntax
// =============================================
$eligibleEmployees = @getRecords("
    SELECT DISTINCT e.id, e.first_name, e.last_name, e.email, e.position,
           e.application_id,
           a.job_order_id,
           jo.title as job_title,
           jo.client_id as client_id,
           c.company_name,
           c.id as client_company_id
    FROM employees e
    JOIN applications a ON e.application_id = a.id
    JOIN job_orders jo ON a.job_order_id = jo.id
    JOIN clients c ON jo.client_id = c.id
    WHERE e.status = 'active'
    AND e.application_id IS NOT NULL
    AND a.status = 'hired'
    ORDER BY e.first_name ASC
");
if (!is_array($eligibleEmployees)) $eligibleEmployees = [];

// Get all clients for deployment creation - PostgreSQL syntax
$clients = @getRecords("
    SELECT id, company_name FROM clients WHERE is_active = 1 ORDER BY company_name ASC
");
if (!is_array($clients)) $clients = [];

// Get all job orders for deployment creation - PostgreSQL syntax
$jobOrders = @getRecords("
    SELECT id, title, client_id FROM job_orders WHERE status IN ('open', 'ongoing') ORDER BY title ASC
");
if (!is_array($jobOrders)) $jobOrders = [];

// =============================================
// MOVE TO ARCHIVE FUNCTION - FIXED PostgreSQL
// =============================================
function moveToArchive($deploymentId) {
    global $userId;
    
    // Get deployment details with all necessary data - PostgreSQL syntax
    $deployment = @getRecord("
        SELECT d.*, 
               e.id as employee_id, e.first_name, e.last_name, e.email, e.position,
               c.company_name, c.id as client_id,
               jo.title as job_title, jo.id as job_id,
               a.id as application_id
        FROM deployments d
        JOIN employees e ON d.employee_id = e.id
        JOIN clients c ON d.client_id = c.id
        JOIN job_orders jo ON d.job_order_id = jo.id
        JOIN applications a ON d.application_id = a.id
        WHERE d.id = $1
    ", [$deploymentId]);
    
    if (!$deployment) {
        return ['success' => false, 'error' => 'Deployment not found.'];
    }
    
    // Check if already in archive - PostgreSQL syntax
    $checkArchive = @getRecord("
        SELECT id FROM deployment_archive 
        WHERE original_assignment_id = $1 OR (employee_id = $2 AND client_id = $3 AND job_order_id = $4)
    ", [$deploymentId, $deployment['employee_id'], $deployment['client_id'], $deployment['job_order_id']]);
    
    if ($checkArchive) {
        // Update existing archive record - PostgreSQL syntax
        $updateSql = "UPDATE deployment_archive 
                      SET status = 'archived', 
                          end_date = NOW(),
                          archived_at = NOW(),
                          archived_by = $1,
                          archive_reason = 'Status changed to ' || $2
                      WHERE id = $3";
        $updateResult = @updateRecord($updateSql, [$userId, $deployment['status'], $checkArchive['id']]);
        
        if ($updateResult) {
            @deleteRecord("DELETE FROM deployments WHERE id = $1", [$deploymentId]);
            return ['success' => true, 'message' => 'Archive record updated and deployment removed.'];
        }
        return ['success' => false, 'error' => 'Failed to update archive record.'];
    }
    
    // Insert into archive - PostgreSQL syntax
    $archiveSql = "INSERT INTO deployment_archive (
        original_assignment_id, applicant_id, job_order_id, client_id, application_id, employee_id,
        assignment_date, start_date, end_date, status, position_title,
        department, manager_id, salary, salary_type, contract_type,
        notes, termination_reason, termination_date,
        archived_by, archived_at, archive_reason
    ) VALUES (
        $1, $2, $3, $4, $5, $6,
        $7, $8, $9, $10, $11,
        $12, $13, $14, $15, $16,
        $17, $18, $19,
        $20, NOW(), $21
    ) RETURNING id";
    
    $result = @insertRecord($archiveSql, [
        $deployment['id'],
        $deployment['employee_id'],
        $deployment['job_order_id'],
        $deployment['client_id'],
        $deployment['application_id'],
        $deployment['employee_id'],
        $deployment['start_date'],
        $deployment['start_date'],
        $deployment['end_date'] ?? null,
        'archived',
        $deployment['position'] ?? $deployment['job_title'],
        null,
        null,
        null,
        null,
        null,
        $deployment['deployment_notes'] ?? null,
        'Deployment ' . $deployment['status'],
        $deployment['end_date'] ?? date('Y-m-d'),
        $userId,
        'Moved to archive from deployments'
    ]);
    
    if ($result) {
        @logActivity($userId, 'Deployment Archived', 'deployment_archive', $result, 
            'Archived deployment #' . $deploymentId . ' for ' . $deployment['first_name'] . ' ' . $deployment['last_name']);
        
        @deleteRecord("DELETE FROM deployments WHERE id = $1", [$deploymentId]);
        
        return ['success' => true, 'message' => 'Deployment moved to archive.'];
    }
    
    return ['success' => false, 'error' => 'Failed to archive deployment.'];
}

// =============================================
// Get sidebar counts - PostgreSQL syntax
// =============================================
$pendingAppsCount = 0;
$pendingResult = @getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", []);
if ($pendingResult && isset($pendingResult['count'])) {
    $pendingAppsCount = (int)$pendingResult['count'];
}

$totalArchived = 0;
$archivedTables = ['examination_records', 'interview_evaluations', 'client_assignments', 'deployment_archive'];
foreach ($archivedTables as $table) {
    $result = @getRecord("SELECT COUNT(*) as count FROM $table", []);
    if ($result && isset($result['count'])) {
        $totalArchived += (int)$result['count'];
    }
}

// =============================================
// Handle AJAX requests - PostgreSQL syntax
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $deploymentId = isset($_POST['deployment_id']) ? (int)$_POST['deployment_id'] : 0;
    
    // ========== CREATE DEPLOYMENT - PostgreSQL ==========
    if ($action === 'create_deployment') {
        $employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $jobOrderId = isset($_POST['job_order_id']) ? (int)$_POST['job_order_id'] : 0;
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $deploymentNotes = trim($_POST['deployment_notes'] ?? '');
        
        $errors = [];
        if ($employeeId <= 0) $errors[] = 'Please select an employee.';
        if ($clientId <= 0) $errors[] = 'Please select a client.';
        if ($jobOrderId <= 0) $errors[] = 'Please select a job order.';
        if (empty($startDate)) $errors[] = 'Please select a start date.';
        
        if (empty($errors)) {
            $employee = @getRecord("
                SELECT e.id, e.application_id, a.job_order_id, jo.client_id
                FROM employees e
                JOIN applications a ON e.application_id = a.id
                JOIN job_orders jo ON a.job_order_id = jo.id
                WHERE e.id = $1
            ", [$employeeId]);
            
            if (!$employee) {
                echo json_encode(['success' => false, 'error' => 'Employee not found or not fully hired.']);
                exit;
            }
            
            if ($employee['client_id'] != $clientId) {
                echo json_encode(['success' => false, 'error' => 'This employee can only be deployed to their hired client.']);
                exit;
            }
            
            if ($employee['job_order_id'] != $jobOrderId) {
                echo json_encode(['success' => false, 'error' => 'This employee can only be deployed to their hired job order.']);
                exit;
            }
            
            $existingDeployment = @getRecord("
                SELECT id FROM deployments 
                WHERE employee_id = $1 AND status = 'active'
            ", [$employeeId]);
            
            if ($existingDeployment) {
                echo json_encode(['success' => false, 'error' => 'This employee already has an active deployment.']);
                exit;
            }
            
            $sql = "INSERT INTO deployments (
                application_id, employee_id, client_id, job_order_id,
                start_date, end_date, status, deployment_notes, created_at
            ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())
            RETURNING id";
            
            $result = @insertRecord($sql, [
                $employee['application_id'] ?? null,
                $employeeId,
                $clientId,
                $jobOrderId,
                $startDate,
                $endDate,
                $status,
                $deploymentNotes
            ]);
            
            if ($result) {
                @updateRecord("UPDATE employees SET status = 'deployed' WHERE id = $1", [$employeeId]);
                @logActivity($userId, 'Deployment Created', 'deployments', $result, 
                    'Created deployment for employee #' . $employeeId);
                
                echo json_encode(['success' => true, 'message' => 'Deployment created successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create deployment.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
        }
        exit;
    }
    
    // ========== UPDATE DEPLOYMENT STATUS - FIXED PostgreSQL ==========
    if ($action === 'update_status' && $deploymentId > 0) {
        $newStatus = $_POST['status'] ?? '';
        $terminationReason = trim($_POST['termination_reason'] ?? '');
        $endDate = $_POST['end_date'] ?? date('Y-m-d');
        
        if (!in_array($newStatus, $statuses)) {
            echo json_encode(['success' => false, 'error' => 'Invalid status.']);
            exit;
        }
        
        $deployment = @getRecord("SELECT * FROM deployments WHERE id = $1", [$deploymentId]);
        
        if (!$deployment) {
            echo json_encode(['success' => false, 'error' => 'Deployment not found.']);
            exit;
        }
        
        // If status is completed or terminated, move to archive
        if (in_array($newStatus, ['completed', 'terminated'])) {
            // Use manual transaction with PostgreSQL
            @beginTransaction();
            
            try {
                $updateSql = "UPDATE deployments SET 
                              status = $1, 
                              end_date = COALESCE($2, end_date),
                              updated_at = NOW() 
                              WHERE id = $3";
                $updateResult = @updateRecord($updateSql, [$newStatus, $endDate, $deploymentId]);
                
                if (!$updateResult) {
                    throw new Exception('Failed to update deployment status.');
                }
                
                $archiveResult = moveToArchive($deploymentId);
                
                if (!$archiveResult['success']) {
                    throw new Exception($archiveResult['error'] ?? 'Failed to archive deployment.');
                }
                
                @updateRecord("UPDATE employees SET status = 'active' WHERE id = $1", [$deployment['employee_id']]);
                @logActivity($userId, 'Deployment Archived', 'deployment_archive', $deploymentId, 
                    'Archived deployment with status: ' . $newStatus);
                
                @commitTransaction();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Deployment ' . $newStatus . ' and moved to archive.',
                    'archived' => true
                ]);
                
            } catch (Exception $e) {
                @rollbackTransaction();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            
        } else {
            // Regular status update (active, on_hold)
            $updateSql = "UPDATE deployments SET status = $1, updated_at = NOW() WHERE id = $2";
            $result = @updateRecord($updateSql, [$newStatus, $deploymentId]);
            
            if ($result) {
                if ($newStatus === 'on_hold') {
                    @updateRecord("UPDATE employees SET status = 'on_hold' WHERE id = $1", [$deployment['employee_id']]);
                } elseif ($newStatus === 'active') {
                    @updateRecord("UPDATE employees SET status = 'deployed' WHERE id = $1", [$deployment['employee_id']]);
                }
                
                @logActivity($userId, 'Deployment Status Updated', 'deployments', $deploymentId, 
                    'Updated status to: ' . $newStatus);
                
                echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update status.']);
            }
        }
        exit;
    }
    
    // ========== GET DEPLOYMENT DETAILS - PostgreSQL ==========
    if ($action === 'get_deployment' && $deploymentId > 0) {
        $deployment = @getRecord("
            SELECT d.*, 
                   e.first_name, e.last_name, e.position, e.email,
                   c.company_name,
                   jo.title as job_title
            FROM deployments d
            JOIN employees e ON d.employee_id = e.id
            JOIN clients c ON d.client_id = c.id
            JOIN job_orders jo ON d.job_order_id = jo.id
            WHERE d.id = $1
        ", [$deploymentId]);
        
        if ($deployment) {
            echo json_encode(['success' => true, 'deployment' => $deployment]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Deployment not found.']);
        }
        exit;
    }
    
    // ========== GET DEPLOYMENT FOR STATUS UPDATE - PostgreSQL ==========
    if ($action === 'get_deployment_status' && $deploymentId > 0) {
        $deployment = @getRecord("
            SELECT d.*, 
                   e.first_name, e.last_name, e.position,
                   c.company_name,
                   jo.title as job_title
            FROM deployments d
            JOIN employees e ON d.employee_id = e.id
            JOIN clients c ON d.client_id = c.id
            JOIN job_orders jo ON d.job_order_id = jo.id
            WHERE d.id = $1
        ", [$deploymentId]);
        
        if ($deployment) {
            echo json_encode(['success' => true, 'deployment' => $deployment]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Deployment not found.']);
        }
        exit;
    }
}

// Status badge mapping
$statusBadges = [
    'active' => 'badge-active',
    'completed' => 'badge-completed',
    'terminated' => 'badge-terminated',
    'on_hold' => 'badge-on_hold'
];

$statusLabels = [
    'active' => 'Active',
    'completed' => 'Completed',
    'terminated' => 'Terminated',
    'on_hold' => 'On Hold'
];

$allStatuses = ['all' => 'All'] + $statusLabels;

// Get deployment archive count for sidebar - PostgreSQL syntax
$deploymentArchiveCount = 0;
$archiveResult = @getRecord("SELECT COUNT(*) as count FROM deployment_archive", []);
if ($archiveResult && isset($archiveResult['count'])) {
    $deploymentArchiveCount = (int)$archiveResult['count'];
}

$statusColors = [
    'active' => '#22c55e',
    'on_hold' => '#f59e0b',
    'completed' => '#3b82f6',
    'terminated' => '#ef4444'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Deployments - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* =============================================
           MATERIAL 3 DESIGN SYSTEM - DEPLOYMENTS
           ============================================= */
        :root {
            --bg-background: #f4f6fa;
            --bg-surface: #ffffff;
            --bg-surface-low: #f8f9fc;
            --text-on-surface: #0a0e1a;
            --text-on-surface-variant: #4a5168;
            --outline-variant: #d0d5dd;
            --primary: #4f46e5;
            --primary-container: #eef0ff;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
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
            --color-active: #22c55e;
            --color-on-hold: #f59e0b;
            --color-completed: #3b82f6;
            --color-terminated: #ef4444;
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
           SIDEBAR - STANDARDIZED
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
        .sidebar-brand-text { font-size: 1rem; font-weight: 700; color: var(--slate-900); }
        .sidebar-brand-category { font-size: 0.7rem; font-weight: 500; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.05em; }
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
        .btn-sm { padding: 0.25rem 0.625rem; font-size: 0.6875rem; border-radius: 0.375rem; }
        .btn-success { background: #22c55e; color: white; }
        .btn-success:hover { background: #16a34a; transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--slate-200);
            padding: 0.875rem 1.25rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .stat-card .stat-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.625rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .stat-card .stat-icon.primary { background: #eef0ff; color: #4f46e5; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-card .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-card .stat-icon.red { background: #fecaca; color: #dc2626; }
        .stat-card .stat-number { font-size: 1.25rem; font-weight: 800; color: var(--text-on-surface); line-height: 1.2; }
        .stat-card .stat-label { font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-on-surface-variant); }

        .search-bar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .search-bar .search-input-wrapper {
            flex: 1;
            min-width: 180px;
            position: relative;
        }
        .search-bar .search-input-wrapper .material-symbols-outlined {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-on-surface-variant);
            font-size: 1.125rem;
        }
        .search-bar .search-input-wrapper input {
            width: 100%;
            padding: 0.5rem 0.875rem 0.5rem 2.5rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .search-bar .search-input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .filter-btn {
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            border: 1.5px solid var(--slate-200);
            transition: all var(--transition-fast);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 2px 10px rgba(79, 70, 229, 0.25); }
        .filter-btn .count {
            background: rgba(0,0,0,0.08);
            border-radius: var(--radius-full);
            padding: 0 0.375rem;
            font-size: 0.625rem;
            font-weight: 700;
        }

        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .card-header h3 { font-size: 0.9375rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .card-body { padding: 0; overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            min-width: 700px;
        }
        table thead { background: var(--bg-surface-low); }
        table th {
            padding: 0.625rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            border-bottom: 2px solid var(--slate-200);
        }
        table td {
            padding: 0.625rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            vertical-align: middle;
        }
        table tbody tr:hover td { background: var(--bg-surface-low); }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-active { background: #d1fae5; color: #059669; }
        .badge-completed { background: #dbeafe; color: #2563eb; }
        .badge-terminated { background: #fecaca; color: #dc2626; }
        .badge-on_hold { background: #fef3c7; color: #d97706; }

        .action-buttons { display: flex; gap: 0.25rem; flex-wrap: wrap; justify-content: center; }

        .empty-state { text-align: center; padding: 3rem 1.5rem; }
        .empty-state .material-symbols-outlined { font-size: 3rem; color: var(--slate-300); display: block; margin-bottom: 0.5rem; }
        .empty-state h4 { font-size: 1rem; font-weight: 700; color: var(--text-on-surface); }

        .toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.625rem 1.125rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.8125rem;
            box-shadow: var(--shadow-lg);
            z-index: 10000;
            animation: slideUp 0.35s ease-out;
            max-width: 380px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toast .material-symbols-outlined { font-size: 1.125rem; }
        .toast.success { background: #059669; }
        .toast.error { background: #dc2626; }
        .toast.info { background: var(--primary); }

        /* =============================================
           MODALS
           ============================================= */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 44rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 1.125rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .modal-header h2 { 
            font-size: 1.125rem; 
            font-weight: 700; 
            display: flex; 
            align-items: center; 
            gap: 0.5rem;
            color: var(--text-on-surface);
        }
        .modal-header h2 .material-symbols-outlined {
            font-size: 1.5rem;
            color: var(--primary);
        }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.375rem;
            border-radius: 0.375rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }
        .modal-close:hover { background: var(--bg-surface-low); }
        .modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 0.875rem 1.5rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.1875rem;
        }
        .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
        .form-group .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }
        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-group textarea.form-control { resize: vertical; min-height: 60px; }
        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.25rem;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }

        /* =============================================
           INFO BOX - Employee Eligibility
           ============================================= */
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: #1e40af;
        }
        .info-box .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
            margin-top: 0.0625rem;
        }

        /* =============================================
           STATUS UPDATE MODAL - COLOR BASED
           ============================================= */
        .status-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin: 0.5rem 0 1rem;
        }
        .status-option {
            padding: 0.75rem 1rem;
            border: 2px solid var(--slate-200);
            border-radius: var(--radius-md);
            cursor: pointer;
            text-align: center;
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            font-weight: 600;
            font-size: 0.8125rem;
            position: relative;
        }
        .status-option:hover {
            border-color: var(--primary);
            background: var(--bg-surface-low);
        }
        .status-option.selected {
            border-color: var(--primary);
            background: var(--primary-container);
            color: var(--primary);
        }
        .status-option .status-color-dot {
            display: inline-block;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            margin-right: 0.5rem;
            vertical-align: middle;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .status-option .status-label {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 500;
            vertical-align: middle;
        }

        @media (min-width: 768px) {
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
            .dashboard-sidebar { position: fixed; transform: translateX(0) !important; }
            .main-wrapper { margin-left: var(--sidebar-width); }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
        }
        @media (max-width: 767px) {
            .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }
            .sidebar-toggle-btn { display: none !important; }
            .mobile-menu-btn { display: flex; }
            .main-wrapper { margin-left: 0 !important; }
            .main-scroll { padding: 1rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .search-bar { flex-direction: column; }
            .filters { overflow-x: auto; flex-wrap: nowrap; }
            .modal { max-height: 95vh; margin: 0.5rem; }
            .modal-footer { flex-direction: column; }
            .modal-footer .btn { width: 100%; justify-content: center; }
            .status-options { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .main-scroll { padding: 0.75rem; }
            .stats-row { grid-template-columns: 1fr; }
            table { font-size: 0.75rem; min-width: 500px; }
            table th, table td { padding: 0.375rem 0.5rem; }
            .status-options { grid-template-columns: 1fr; }
        }
        .main-scroll::-webkit-scrollbar { width: 5px; }
        .main-scroll::-webkit-scrollbar-track { background: transparent; }
        .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 4px; }
        .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-300); }

        /* Loading spinner */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .spinner {
            display: inline-block;
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        .header-logo {
    height: 2rem;
    width: auto;
    max-height: 2.5rem;
    object-fit: contain;
    border-radius: 0.375rem;
}

/* For mobile responsiveness */
@media (max-width: 480px) {
    .header-logo {
        height: 1.5rem;
    }
}
  .sidebar-logo-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    flex-shrink: 0;
}

.sidebar-logo {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 0.75rem;
    transition: all 0.3s ease;
}

.dashboard-sidebar.collapsed .sidebar-logo {
    width: 2.5rem;
    height: 2.5rem;
}
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <div class="sidebar-logo-wrapper">
            <img src="logo.png" alt="ISMERS" class="sidebar-logo">
        </div>
        <p class="sidebar-brand-category">HR Portal</p>
    </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="clients.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'clients.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">business</span>
                <span class="nav-text">Clients</span>
            </a>
            <a href="jobs.php" class="sidebar-main-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['jobs.php', 'job_view.php', 'post_job.php']) ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">work</span>
                <span class="nav-text">My Jobs</span>
            </a>
            <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Applicants</span>
                <span class="nav-badge"><?php 
                    $pendingApps = getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", [])['count'] ?? 0;
                    echo $pendingApps; 
                ?></span>
            </a>

            <a href="interviews.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'interviews.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
            </a>
            <a href="offers.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'offers.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Offers</span>
            </a>
            <a href="archive.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'archive.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">archive</span>
                <span class="nav-text">Archive</span>
                <span class="nav-badge"><?php 
                    $totalArchived = 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM examination_records", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM interview_evaluations", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM client_assignments", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM deployment_archive", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    echo $totalArchived;
                ?></span>
            </a>
            <a href="apply_agency.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'apply_agency.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">apartment</span>
                <span class="nav-text">Apply as Agency</span>
            </a>
            <a href="deployments.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'deployments.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">assignment</span>
                <span class="nav-text">Deployments</span>
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
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="main-wrapper" id="mainWrapper">
      <!-- ===== TOP HEADER ===== -->
<header class="top-header">
    <div class="top-header-left">
        <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
            <span class="material-symbols-outlined" id="sidebarToggleIcon">chevron_left</span>
        </button>
        <!-- ✅ Logo added here -->
        <img src="logo.png" alt="ISMERS" class="header-logo">
        <span class="separator">|</span>
        <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
            <?php 
                $pageTitle = basename($_SERVER['PHP_SELF'], '.php');
                echo ucwords(str_replace('_', ' ', $pageTitle));
            ?>
        </span>
    </div>
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                    <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="profile-dropdown-menu" id="profileMenu">
                    <div class="dropdown-header">Account</div>
                    <button class="dropdown-item" onclick="window.location.href='profile.php'">Profile</button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">Logout</button>
                </div>
            </div>
        </header>

        <main class="main-scroll">
            <div class="container">
                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">assignment</span>
                        <span>Deployments</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $statusFilter === 'all' ? 'All' : ucfirst($statusFilter); ?> (<?php echo count($deployments); ?>)
                        </span>
                    </div>
                    <span class="breadcrumb-meta">Updated <?php echo date('M d, Y H:i'); ?></span>
                </div>

                <!-- Page Header -->
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
                    <div>
                        <h1 style="font-size:1.875rem; font-weight:700; color:var(--text-on-surface); letter-spacing:-0.025em;">Employee Deployments</h1>
                        <p style="font-size:0.875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">Manage employee assignments to clients and projects</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add</span>
                            Create Deployment
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon primary"><span class="material-symbols-outlined">assignment</span></div>
                        <div><div class="stat-number"><?php echo $statusCounts['all']; ?></div><div class="stat-label">Total</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><span class="material-symbols-outlined">check_circle</span></div>
                        <div><div class="stat-number"><?php echo $statusCounts['active'] ?? 0; ?></div><div class="stat-label">Active</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow"><span class="material-symbols-outlined">pause</span></div>
                        <div><div class="stat-number"><?php echo $statusCounts['on_hold'] ?? 0; ?></div><div class="stat-label">On Hold</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><span class="material-symbols-outlined">block</span></div>
                        <div><div class="stat-number"><?php echo $statusCounts['terminated'] ?? 0; ?></div><div class="stat-label">Terminated</div></div>
                    </div>
                </div>

                <!-- Search -->
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <span class="material-symbols-outlined">search</span>
                        <input type="text" id="searchInput" placeholder="Search by employee, client, or job..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                    <?php if (!empty($searchQuery) || $statusFilter !== 'all' || $employeeFilter > 0): ?>
                        <a href="deployments.php" class="btn btn-outline">Clear Filters</a>
                    <?php endif; ?>
                </div>

                <!-- Filters -->
                <div class="filters">
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <a href="?status=<?php echo $key; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                           class="filter-btn <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                            <span class="count"><?php echo $statusCounts[$key] ?? 0; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-header">
                        <h3><span class="material-symbols-outlined">assignment</span> Deployments</h3>
                        <span class="count-badge"><?php echo count($deployments); ?> records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($deployments)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">assignment</span>
                                <h4>No Deployments Found</h4>
                                <p>Create a deployment to assign an employee to a client or project.</p>
                                <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top:0.75rem;">
                                    <span class="material-symbols-outlined">add</span>
                                    Create First Deployment
                                </button>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Position</th>
                                        <th>Client</th>
                                        <th>Job Order</th>
                                        <th>Dates</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deployments as $deployment): ?>
                                        <tr>
                                            <td>
                                                <div class="employee-cell">
                                                    <div class="name"><?php echo htmlspecialchars($deployment['first_name'] . ' ' . $deployment['last_name']); ?></div>
                                                    <div class="position"><?php echo htmlspecialchars($deployment['email']); ?></div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($deployment['position'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($deployment['company_name']); ?></td>
                                            <td><?php echo htmlspecialchars($deployment['job_title']); ?></td>
                                            <td>
                                                <div style="font-size:0.75rem;">
                                                    <div>Start: <?php echo date('M d, Y', strtotime($deployment['start_date'])); ?></div>
                                                    <?php if ($deployment['end_date']): ?>
                                                        <div>End: <?php echo date('M d, Y', strtotime($deployment['end_date'])); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $statusBadges[$deployment['status']] ?? 'badge-active'; ?>">
                                                    <?php echo $statusLabels[$deployment['status']] ?? ucfirst($deployment['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-primary btn-sm" onclick="viewDeployment(<?php echo $deployment['id']; ?>)" title="View">
                                                        <span class="material-symbols-outlined">visibility</span>
                                                    </button>
                                                    <button class="btn btn-outline btn-sm" onclick="openStatusModal(<?php echo $deployment['id']; ?>, '<?php echo $deployment['status']; ?>')" title="Update Status">
                                                        <span class="material-symbols-outlined">edit</span>
                                                    </button>
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
    </div>

    <!-- =============================================
    MODAL: Create Deployment
    ============================================= -->
    <div class="modal-overlay" id="createModal">
        <div class="modal">
            <div class="modal-header">
                <h2><span class="material-symbols-outlined">add</span> Create Deployment</h2>
                <button class="modal-close" onclick="closeModal('createModal')"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="modal-body">
                <div class="info-box">
                    <span class="material-symbols-outlined">info</span>
                    <div>
                        <strong>Employee Deployment Restriction:</strong> 
                        An employee can only be deployed to the client and job order where they were hired. 
                        This ensures proper assignment and tracking.
                    </div>
                </div>
                <form id="createForm" onsubmit="submitDeployment(event)">
                    <input type="hidden" name="action" value="create_deployment">
                    
                    <div class="form-group">
                        <label>Employee <span class="required">*</span></label>
                        <select name="employee_id" class="form-control" required id="employeeSelect">
                            <option value="">Select employee...</option>
                            <?php foreach ($eligibleEmployees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" 
                                        data-client-id="<?php echo $emp['client_company_id']; ?>"
                                        data-client-name="<?php echo htmlspecialchars($emp['company_name']); ?>"
                                        data-job-id="<?php echo $emp['job_order_id']; ?>"
                                        data-job-title="<?php echo htmlspecialchars($emp['job_title']); ?>">
                                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' - ' . $emp['position'] . ' (' . $emp['company_name'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:0.6875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                            <span class="material-symbols-outlined" style="font-size:0.75rem; vertical-align:middle;">info</span>
                            Only employees who have been hired for a specific job can be deployed to that job's client.
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Client <span class="required">*</span></label>
                            <input type="text" id="clientDisplay" class="form-control" readonly style="background:var(--bg-surface-low);">
                            <input type="hidden" name="client_id" id="clientId">
                            <div style="font-size:0.6875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                                <span class="material-symbols-outlined" style="font-size:0.75rem; vertical-align:middle;">lock</span>
                                Client is locked to the employee's hired job.
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Job Order <span class="required">*</span></label>
                            <input type="text" id="jobDisplay" class="form-control" readonly style="background:var(--bg-surface-low);">
                            <input type="hidden" name="job_order_id" id="jobOrderId">
                            <div style="font-size:0.6875rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">
                                <span class="material-symbols-outlined" style="font-size:0.75rem; vertical-align:middle;">lock</span>
                                Job Order is locked to the employee's hired position.
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date <span class="required">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="on_hold">On Hold</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Deployment Notes</label>
                        <textarea name="deployment_notes" class="form-control" placeholder="Add any notes about this deployment..." rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
                <button class="btn btn-primary" onclick="document.getElementById('createForm').dispatchEvent(new Event('submit'))">
                    <span class="material-symbols-outlined">check</span> Create
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    MODAL: View Deployment
    ============================================= -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h2><span class="material-symbols-outlined">visibility</span> Deployment Details</h2>
                <button class="modal-close" onclick="closeModal('viewModal')"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div id="viewLoading" style="text-align:center; padding:1.5rem;">
                    <div style="width:2rem; height:2rem; border:3px solid var(--slate-200); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto;"></div>
                    <p style="margin-top:0.5rem; color:var(--text-on-surface-variant); font-size:0.8125rem;">Loading...</p>
                </div>
                <div id="viewContent" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- =============================================
    MODAL: Update Status - WITH COLOR INDICATORS
    ============================================= -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined">edit</span>
                    Update Deployment Status
                </h2>
                <button class="modal-close" onclick="closeModal('statusModal')">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="statusModalContent">
                    <div class="info-box" style="margin-bottom:1rem;">
                        <span class="material-symbols-outlined">info</span>
                        <div>
                            <strong>Select a new status for this deployment.</strong>
                            <div style="font-size:0.75rem; margin-top:0.25rem; color:#1e40af;">
                                <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">warning</span>
                                <span style="font-weight:500;">Note:</span> Setting to "Completed" or "Terminated" will move this deployment to the <strong>Archive</strong>.
                            </div>
                        </div>
                    </div>
                    
                    <div id="statusEmployeeInfo" style="background:var(--bg-surface-low); padding:0.75rem 1rem; border-radius:0.5rem; margin-bottom:1rem; border:1px solid var(--slate-200);">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Employee</div>
                                <div style="font-weight:600; font-size:0.875rem;" id="statusEmployeeName">Loading...</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Position</div>
                                <div style="font-weight:600; font-size:0.875rem;" id="statusEmployeePosition">Loading...</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Client</div>
                                <div style="font-weight:600; font-size:0.875rem;" id="statusClientName">Loading...</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Current Status</div>
                                <div style="font-weight:600; font-size:0.875rem;" id="statusCurrentStatus">Loading...</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="status-options" id="statusOptions">
                        <div class="status-option" data-status="active" onclick="selectStatus('active')">
                            <span class="status-color-dot" style="background: var(--color-active);"></span>
                            <span class="status-label">Active</span>
                        </div>
                        <div class="status-option" data-status="on_hold" onclick="selectStatus('on_hold')">
                            <span class="status-color-dot" style="background: var(--color-on-hold);"></span>
                            <span class="status-label">On Hold</span>
                        </div>
                        <div class="status-option" data-status="completed" onclick="selectStatus('completed')">
                            <span class="status-color-dot" style="background: var(--color-completed);"></span>
                            <span class="status-label">Completed</span>
                        </div>
                        <div class="status-option" data-status="terminated" onclick="selectStatus('terminated')">
                            <span class="status-color-dot" style="background: var(--color-terminated);"></span>
                            <span class="status-label">Terminated</span>
                        </div>
                    </div>
                    
                    <div class="form-group" id="terminationReasonGroup" style="display:none;">
                        <label for="terminationReason">Termination / Completion Reason</label>
                        <textarea id="terminationReason" class="form-control" placeholder="Please provide a reason for this status change..." rows="2"></textarea>
                    </div>
                    
                    <input type="hidden" id="statusDeploymentId" value="0">
                    <input type="hidden" id="selectedStatus" value="">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('statusModal')">Cancel</button>
                <button class="btn btn-primary" id="confirmStatusBtn" onclick="confirmStatusUpdate()" disabled>
                    <span class="material-symbols-outlined">check</span>
                    Update Status
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    JAVASCRIPT - FIXED
    ============================================= -->
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

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', openMobileSidebar);
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        }

        // =============================================
        // PROFILE DROPDOWN
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
        // EMPLOYEE SELECT - Auto fill client and job
        // =============================================
        const employeeSelect = document.getElementById('employeeSelect');
        const clientDisplay = document.getElementById('clientDisplay');
        const clientId = document.getElementById('clientId');
        const jobDisplay = document.getElementById('jobDisplay');
        const jobOrderId = document.getElementById('jobOrderId');

        if (employeeSelect) {
            employeeSelect.addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                if (selected && selected.value) {
                    const clientName = selected.dataset.clientName || '';
                    const jobTitle = selected.dataset.jobTitle || '';
                    clientDisplay.value = clientName;
                    clientId.value = selected.dataset.clientId || '';
                    jobDisplay.value = jobTitle;
                    jobOrderId.value = selected.dataset.jobId || '';
                } else {
                    clientDisplay.value = '';
                    clientId.value = '';
                    jobDisplay.value = '';
                    jobOrderId.value = '';
                }
            });
        }

        // =============================================
        // MODAL FUNCTIONS
        // =============================================
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const createModal = document.getElementById('createModal');
                const viewModal = document.getElementById('viewModal');
                const statusModal = document.getElementById('statusModal');
                if (createModal && createModal.classList.contains('active')) {
                    closeModal('createModal');
                } else if (viewModal && viewModal.classList.contains('active')) {
                    closeModal('viewModal');
                } else if (statusModal && statusModal.classList.contains('active')) {
                    closeModal('statusModal');
                }
                closeMobileSidebar();
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // CREATE DEPLOYMENT
        // =============================================
        function openCreateModal() {
            const form = document.getElementById('createForm');
            if (form) form.reset();
            document.getElementById('clientDisplay').value = '';
            document.getElementById('jobDisplay').value = '';
            openModal('createModal');
        }

        function submitDeployment(event) {
            event.preventDefault();
            
            const form = document.getElementById('createForm');
            if (!form) return;
            
            const formData = new FormData(form);
            
            const employeeSelect = document.getElementById('employeeSelect');
            if (!employeeSelect.value) {
                showToast('Please select an employee.', 'error');
                return;
            }
            
            const btn = document.querySelector('#createModal .modal-footer .btn-primary');
            if (!btn) return;
            
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Saving...';

            fetch('deployments.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('createModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error || 'Failed to create deployment.', 'error');
                }
            })
           // Around line 1770-1780 in the JavaScript
.catch(error => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    console.error('Status update error:', error);  // This is line 1774
    showToast('Error updating status. Please check console for details.', 'error');
});
        }

        // =============================================
        // VIEW DEPLOYMENT
        // =============================================
        function viewDeployment(id) {
            openModal('viewModal');
            
            const loading = document.getElementById('viewLoading');
            const content = document.getElementById('viewContent');
            
            if (loading) loading.style.display = 'block';
            if (content) content.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'get_deployment');
            formData.append('deployment_id', id);

            fetch('deployments.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (loading) loading.style.display = 'none';
                if (content) content.style.display = 'block';

                if (data.success) {
                    const d = data.deployment;
                    content.innerHTML = `
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Employee</div>
                                <div style="font-weight:600; font-size:0.9375rem;">${escapeHtml(d.first_name)} ${escapeHtml(d.last_name)}</div>
                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">${escapeHtml(d.position)}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Client</div>
                                <div style="font-weight:600; font-size:0.9375rem;">${escapeHtml(d.company_name)}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Job Order</div>
                                <div style="font-weight:600; font-size:0.9375rem;">${escapeHtml(d.job_title)}</div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Status</div>
                                <div><span class="badge ${getStatusBadge(d.status)}">${escapeHtml(d.status)}</span></div>
                            </div>
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Start Date</div>
                                <div style="font-weight:600; font-size:0.9375rem;">${new Date(d.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                            </div>
                            ${d.end_date ? `
                            <div>
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">End Date</div>
                                <div style="font-weight:600; font-size:0.9375rem;">${new Date(d.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                            </div>
                            ` : ''}
                            ${d.deployment_notes ? `
                            <div style="grid-column:1/-1;">
                                <div style="font-size:0.625rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-on-surface-variant);">Notes</div>
                                <div style="background:var(--bg-surface-low); padding:0.5rem; border-radius:0.375rem;">${escapeHtml(d.deployment_notes)}</div>
                            </div>
                            ` : ''}
                            <div style="grid-column:1/-1; font-size:0.6875rem; color:var(--text-on-surface-variant);">
                                Created: ${new Date(d.created_at).toLocaleString()}
                            </div>
                        </div>
                    `;
                } else {
                    content.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">${data.error || 'Failed to load deployment details.'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                if (loading) loading.style.display = 'none';
                if (content) {
                    content.style.display = 'block';
                    content.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">Error loading deployment details. Please try again.</p>
                        </div>
                    `;
                }
            });
        }

        // =============================================
        // STATUS UPDATE MODAL - FIXED
        // =============================================
        let currentDeploymentId = 0;
        let currentDeploymentStatus = '';
        let selectedNewStatus = '';

        function openStatusModal(id, currentStatus) {
            currentDeploymentId = id;
            currentDeploymentStatus = currentStatus;
            selectedNewStatus = '';
            
            // Reset modal
            document.getElementById('statusDeploymentId').value = id;
            document.getElementById('selectedStatus').value = '';
            document.getElementById('terminationReasonGroup').style.display = 'none';
            document.getElementById('terminationReason').value = '';
            document.getElementById('confirmStatusBtn').disabled = true;
            
            // Reset status options
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Load deployment info
            const formData = new FormData();
            formData.append('action', 'get_deployment_status');
            formData.append('deployment_id', id);
            
            fetch('deployments.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const d = data.deployment;
                    document.getElementById('statusEmployeeName').textContent = d.first_name + ' ' + d.last_name;
                    document.getElementById('statusEmployeePosition').textContent = d.position || 'N/A';
                    document.getElementById('statusClientName').textContent = d.company_name;
                    
                    const statusBadge = getStatusBadge(d.status);
                    const statusLabel = d.status.charAt(0).toUpperCase() + d.status.slice(1);
                    document.getElementById('statusCurrentStatus').innerHTML = `<span class="badge ${statusBadge}">${statusLabel}</span>`;
                    
                    openModal('statusModal');
                } else {
                    showToast('Failed to load deployment details.', 'error');
                }
            })
            .catch(error => {
                showToast('Error loading deployment details.', 'error');
            });
        }

        function selectStatus(status) {
            selectedNewStatus = status;
            document.getElementById('selectedStatus').value = status;
            
            // Update UI
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.toggle('selected', opt.dataset.status === status);
            });
            
            // Show termination reason for completed/terminated
            if (status === 'completed' || status === 'terminated') {
                document.getElementById('terminationReasonGroup').style.display = 'block';
            } else {
                document.getElementById('terminationReasonGroup').style.display = 'none';
            }
            
            // Enable confirm button
            document.getElementById('confirmStatusBtn').disabled = false;
        }

        function confirmStatusUpdate() {
            const status = document.getElementById('selectedStatus').value;
            if (!status) {
                showToast('Please select a status.', 'error');
                return;
            }
            
            if (status === currentDeploymentStatus) {
                showToast('Already in this status.', 'info');
                closeModal('statusModal');
                return;
            }
            
            // For completed/terminated, confirm with user
            if (status === 'completed' || status === 'terminated') {
                const reason = document.getElementById('terminationReason').value.trim();
                if (!reason) {
                    showToast('Please provide a reason for this status change.', 'error');
                    document.getElementById('terminationReason').focus();
                    return;
                }
                
                const action = status === 'completed' ? 'Complete' : 'Terminate';
                if (!confirm(`Are you sure you want to ${action} this deployment? This will move it to the archive and cannot be undone.`)) {
                    return;
                }
            }
            
            // Send update
            const btn = document.getElementById('confirmStatusBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Updating...';
            
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('deployment_id', currentDeploymentId);
            formData.append('status', status);
            formData.append('termination_reason', document.getElementById('terminationReason').value);
            formData.append('end_date', new Date().toISOString().split('T')[0]);
            
            fetch('deployments.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('statusModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to update status.', 'error');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                console.error('Status update error:', error);
                showToast('Error updating status. Please check console for details.', 'error');
            });
        }

        // =============================================
        // SEARCH & FILTERS
        // =============================================
        function applyFilters() {
            const search = document.getElementById('searchInput');
            if (!search) return;
            
            const status = '<?php echo $statusFilter; ?>';
            let url = 'deployments.php?';
            if (status !== 'all') url += 'status=' + status + '&';
            if (search.value) url += 'search=' + encodeURIComponent(search.value);
            window.location.href = url;
        }

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
        }

        // =============================================
        // TOAST SYSTEM
        // =============================================
        function showToast(message, type) {
            type = type || 'info';
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info' };
            toast.innerHTML = `<span class="material-symbols-outlined">${iconMap[type] || 'info'}</span> ${message}`;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        // =============================================
        // UTILITY FUNCTIONS
        // =============================================
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getStatusBadge(status) {
            const badges = {
                'active': 'badge-active',
                'completed': 'badge-completed',
                'terminated': 'badge-terminated',
                'on_hold': 'badge-on_hold'
            };
            return badges[status] || 'badge-active';
        }


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

        console.log('ISMERS Deployments Management loaded successfully.');
        console.log('Deployment restricted to hired employee\'s client and job order only.');
        console.log('Completed/Terminated deployments automatically moved to archive.');
    </script>

</body>
</html>