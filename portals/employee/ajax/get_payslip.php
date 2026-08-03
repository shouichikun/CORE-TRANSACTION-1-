<?php
// portals/employee/ajax/get_payslip.php - Get Payslip Data
session_start();
require_once '../../../app/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$payslipId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payslipId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid payslip ID.']);
    exit;
}

// Get payslip data
$payslip = getRecord("
    SELECT * FROM payroll 
    WHERE id = ? AND user_id = ?
", [$payslipId, $userId], "ii");

if (!$payslip) {
    echo json_encode(['success' => false, 'error' => 'Payslip not found.']);
    exit;
}

// Get employee details
$employee = getRecord("
    SELECT e.*, u.first_name, u.last_name, u.email
    FROM employees e
    JOIN users u ON e.user_id = u.id
    WHERE e.user_id = ?
", [$userId], "i");

// Format dates
$periodStart = date('F d, Y', strtotime($payslip['pay_period_start']));
$periodEnd = date('F d, Y', strtotime($payslip['pay_period_end']));
$paymentDate = !empty($payslip['payment_date']) ? date('F d, Y', strtotime($payslip['payment_date'])) : '—';

// Build HTML
$html = '
<div class="payslip-header">
    <div class="payslip-company">
        <h3>ISMERS</h3>
        <p>Payroll Services</p>
    </div>
    <div class="payslip-period">
        <div class="period-label">Pay Period</div>
        <div class="period-value">' . $periodStart . ' - ' . $periodEnd . '</div>
    </div>
</div>

<div class="payslip-grid">
    <div class="payslip-item">
        <div class="item-label">Employee</div>
        <div class="item-value">' . htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) . '</div>
    </div>
    <div class="payslip-item">
        <div class="item-label">Position</div>
        <div class="item-value">' . htmlspecialchars($employee['position'] ?? 'Employee') . '</div>
    </div>
    <div class="payslip-item">
        <div class="item-label">Gross Pay</div>
        <div class="item-value green">₱' . number_format($payslip['gross_pay'], 2) . '</div>
    </div>
    <div class="payslip-item">
        <div class="item-label">Total Deductions</div>
        <div class="item-value red">-₱' . number_format($payslip['total_deductions'], 2) . '</div>
    </div>
    <div class="payslip-item" style="grid-column:1/-1; background:var(--bg-surface); border:2px solid var(--primary);">
        <div class="item-label" style="color:var(--primary);">Net Pay</div>
        <div class="item-value" style="font-size:1.5rem; color:var(--primary);">₱' . number_format($payslip['net_pay'], 2) . '</div>
    </div>
</div>

<div class="payslip-breakdown">
    <div class="breakdown-title">Payment Details</div>
    <div class="breakdown-grid">
        <div class="breakdown-item">
            <span class="breakdown-label">Payment Date</span>
            <span class="breakdown-value">' . $paymentDate . '</span>
        </div>
        <div class="breakdown-item">
            <span class="breakdown-label">Status</span>
            <span class="breakdown-value"><span class="badge badge-' . ($payslip['status'] ?? 'paid') . '">' . ucfirst($payslip['status'] ?? 'Paid') . '</span></span>
        </div>
    </div>
    ' . (!empty($payslip['notes']) ? '
    <div style="margin-top:1rem; padding:0.75rem 1rem; background:var(--bg-surface-low); border-radius:0.75rem; border:1px solid var(--slate-200);">
        <div style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant); text-transform:uppercase; letter-spacing:0.05em;">Notes</div>
        <div style="font-size:0.8125rem; color:var(--text-on-surface-variant); margin-top:0.125rem;">' . htmlspecialchars($payslip['notes']) . '</div>
    </div>
    ' : '') . '
</div>
';

echo json_encode([
    'success' => true,
    'html' => $html
]);
?>