<?php
// portals/client/ajax/export_report.php - Export Reports as CSV
// ✅ POSTGRESQL COMPATIBLE VERSION

session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$reportType = $_GET['type'] ?? 'applications';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Get client ID
// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string
$client = getRecord("SELECT id FROM clients WHERE user_id = $1", [$userId]);
if (!$client) {
    die('Client not found.');
}

$clientId = $client['id'];

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="report_' . $reportType . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Generate report based on type
switch ($reportType) {
    case 'applications':
        fputcsv($output, ['Job Title', 'Status', 'Total Applications', 'Pending', 'Reviewed', 'Shortlisted', 'Hired', 'Rejected']);
        // ✅ FIXED: PostgreSQL uses $1, $2, $3 placeholders, removed type string
        $data = getRecords("
            SELECT 
                jo.title as job_title,
                jo.status as job_status,
                COUNT(a.id) as total_applications,
                SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN a.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
                SUM(CASE WHEN a.status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
                SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as hired,
                SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM job_orders jo
            LEFT JOIN applications a ON jo.id = a.job_order_id
            WHERE jo.client_id = $1
            AND DATE(jo.created_at) BETWEEN $2 AND $3
            GROUP BY jo.id, jo.title, jo.status
            ORDER BY total_applications DESC
        ", [$clientId, $startDate, $endDate]);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['job_title'],
                $row['job_status'],
                $row['total_applications'],
                $row['pending'],
                $row['reviewed'],
                $row['shortlisted'],
                $row['hired'],
                $row['rejected']
            ]);
        }
        break;
        
    case 'status':
        fputcsv($output, ['Status', 'Applications', 'Unique Applicants']);
        // ✅ FIXED: PostgreSQL uses $1, $2, $3 placeholders
        $data = getRecords("
            SELECT 
                a.status,
                COUNT(a.id) as count,
                COUNT(DISTINCT a.applicant_id) as unique_applicants
            FROM applications a
            JOIN job_orders jo ON a.job_order_id = jo.id
            WHERE jo.client_id = $1
            AND DATE(a.applied_at) BETWEEN $2 AND $3
            GROUP BY a.status
            ORDER BY count DESC
        ", [$clientId, $startDate, $endDate]);
        foreach ($data as $row) {
            fputcsv($output, [$row['status'], $row['count'], $row['unique_applicants']]);
        }
        break;
        
    case 'employees':
        fputcsv($output, ['Job Title', 'Total', 'Active', 'On Hold', 'Completed', 'Terminated', 'Avg Days']);
        // ✅ FIXED: PostgreSQL - removed TIMESTAMPDIFF, using EXTRACT
        $data = getRecords("
            SELECT 
                jo.title as job_title,
                COUNT(d.id) as total_employees,
                SUM(CASE WHEN d.status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN d.status = 'on_hold' THEN 1 ELSE 0 END) as on_hold,
                SUM(CASE WHEN d.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN d.status = 'terminated' THEN 1 ELSE 0 END) as terminated,
                AVG(EXTRACT(DAY FROM COALESCE(d.end_date, CURRENT_DATE) - d.start_date)) as avg_days
            FROM job_orders jo
            LEFT JOIN deployments d ON jo.id = d.job_order_id
            WHERE jo.client_id = $1
            AND DATE(d.created_at) BETWEEN $2 AND $3
            GROUP BY jo.id, jo.title
            ORDER BY total_employees DESC
        ", [$clientId, $startDate, $endDate]);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['job_title'],
                $row['total_employees'],
                $row['active'],
                $row['on_hold'],
                $row['completed'],
                $row['terminated'],
                round($row['avg_days'] ?? 0) . ' days'
            ]);
        }
        break;
        
    case 'revenue':
        fputcsv($output, ['Month', 'Offers', 'Accepted', 'Rejected', 'Total Revenue', 'Avg Salary']);
        // ✅ FIXED: PostgreSQL uses TO_CHAR instead of DATE_FORMAT
        $data = getRecords("
            SELECT 
                TO_CHAR(o.created_at, 'YYYY-MM') as month,
                COUNT(o.id) as total_offers,
                SUM(CASE WHEN o.status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN o.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN o.status = 'accepted' THEN o.salary_offered ELSE 0 END) as total_revenue,
                AVG(CASE WHEN o.status = 'accepted' THEN o.salary_offered ELSE NULL END) as avg_salary
            FROM offers o
            JOIN applications a ON o.application_id = a.id
            JOIN job_orders jo ON a.job_order_id = jo.id
            WHERE jo.client_id = $1
            AND DATE(o.created_at) BETWEEN $2 AND $3
            GROUP BY TO_CHAR(o.created_at, 'YYYY-MM')
            ORDER BY month DESC
        ", [$clientId, $startDate, $endDate]);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['month'],
                $row['total_offers'],
                $row['accepted'],
                $row['rejected'],
                number_format($row['total_revenue'] ?? 0, 2),
                number_format($row['avg_salary'] ?? 0, 2)
            ]);
        }
        break;
        
    case 'agencies':
        fputcsv($output, ['Agency', 'Code', 'Jobs', 'Applications', 'Hires', 'Rejections', 'Conversion Rate']);
        // ✅ FIXED: PostgreSQL uses $1, $2, $3 placeholders
        $data = getRecords("
            SELECT 
                ra.agency_name,
                ra.agency_code,
                COUNT(jo.id) as total_jobs,
                COUNT(a.id) as total_applications,
                SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as hires,
                SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejections
            FROM recruitment_agencies ra
            LEFT JOIN job_orders jo ON ra.id = jo.agency_id
            LEFT JOIN applications a ON jo.id = a.job_order_id
            WHERE ra.client_id = $1
            AND (DATE(jo.created_at) BETWEEN $2 AND $3 OR jo.created_at IS NULL)
            GROUP BY ra.id, ra.agency_name, ra.agency_code
            ORDER BY total_applications DESC
        ", [$clientId, $startDate, $endDate]);
        foreach ($data as $row) {
            $convRate = ($row['total_applications'] ?? 0) > 0 
                ? round(($row['hires'] / $row['total_applications']) * 100) 
                : 0;
            fputcsv($output, [
                $row['agency_name'],
                $row['agency_code'],
                $row['total_jobs'] ?? 0,
                $row['total_applications'] ?? 0,
                $row['hires'] ?? 0,
                $row['rejections'] ?? 0,
                $convRate . '%'
            ]);
        }
        break;
        
    case 'funnel':
    default:
        fputcsv($output, ['Metric', 'Value']);
        // ✅ FIXED: PostgreSQL uses $1 placeholder, removed "iiii" type
        $funnel = getRecord("
            SELECT 
                (SELECT COUNT(*) FROM applications a 
                 JOIN job_orders jo ON a.job_order_id = jo.id 
                 WHERE jo.client_id = $1) as total_applications,
                (SELECT COUNT(*) FROM applications a 
                 JOIN job_orders jo ON a.job_order_id = jo.id 
                 WHERE jo.client_id = $1 AND a.status = 'shortlisted') as shortlisted,
                (SELECT COUNT(*) FROM applications a 
                 JOIN job_orders jo ON a.job_order_id = jo.id 
                 WHERE jo.client_id = $1 AND a.status = 'hired') as hired,
                (SELECT COUNT(*) FROM offers o 
                 JOIN applications a ON o.application_id = a.id 
                 JOIN job_orders jo ON a.job_order_id = jo.id 
                 WHERE jo.client_id = $1 AND o.status = 'accepted') as offers_accepted
        ", [$clientId]);
        fputcsv($output, ['Total Applications', $funnel['total_applications'] ?? 0]);
        fputcsv($output, ['Shortlisted', $funnel['shortlisted'] ?? 0]);
        fputcsv($output, ['Hired', $funnel['hired'] ?? 0]);
        fputcsv($output, ['Offers Accepted', $funnel['offers_accepted'] ?? 0]);
        break;
}

fclose($output);
exit;
?>