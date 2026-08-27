<?php
// portals/client/ajax/get_invoice.php - Get Invoice Data
// ✅ PostgreSQL COMPATIBLE VERSION

session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($invoiceId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid invoice ID.']);
    exit;
}

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string "i"
$client = getRecord("SELECT id FROM clients WHERE user_id = $1", [$userId]);
if (!$client) {
    echo json_encode(['success' => false, 'error' => 'Client not found.']);
    exit;
}

$clientId = $client['id'];

// ✅ FIXED: PostgreSQL uses $1, $2 placeholders, removed type string "ii"
$invoice = getRecord("
    SELECT * FROM invoices 
    WHERE id = $1 AND client_id = $2
", [$invoiceId, $clientId]);

if (!$invoice) {
    echo json_encode(['success' => false, 'error' => 'Invoice not found.']);
    exit;
}

// ✅ FIXED: PostgreSQL uses $1 placeholder, removed type string "i"
$items = getRecords("
    SELECT * FROM invoice_items 
    WHERE invoice_id = $1
", [$invoiceId]);

// Build HTML
$html = '
<div class="invoice-detail-grid">
    <div class="invoice-detail-item">
        <div class="label">Invoice #</div>
        <div class="value">#' . htmlspecialchars($invoice['invoice_number']) . '</div>
    </div>
    <div class="invoice-detail-item">
        <div class="label">Date</div>
        <div class="value">' . date('M d, Y', strtotime($invoice['invoice_date'])) . '</div>
    </div>
    <div class="invoice-detail-item">
        <div class="label">Due Date</div>
        <div class="value">' . date('M d, Y', strtotime($invoice['due_date'])) . '</div>
    </div>
    <div class="invoice-detail-item">
        <div class="label">Status</div>
        <div class="value">
            <span class="badge badge-' . $invoice['status'] . '">' . ucfirst($invoice['status']) . '</span>
        </div>
    </div>
    <div class="invoice-detail-item" style="grid-column:1/-1;">
        <div class="label">Description</div>
        <div class="value">' . nl2br(htmlspecialchars($invoice['description'] ?? '—')) . '</div>
    </div>
</div>
';

if (!empty($items)) {
    $html .= '
    <table class="invoice-items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
    ';
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += $item['total'];
        $html .= '
            <tr>
                <td>' . htmlspecialchars($item['description']) . '</td>
                <td>' . $item['quantity'] . '</td>
                <td>₱' . number_format($item['unit_price'], 2) . '</td>
                <td>₱' . number_format($item['total'], 2) . '</td>
            </tr>
        ';
    }
    $html .= '
        </tbody>
    </table>
    <div style="display:flex; justify-content:flex-end; margin-top:1rem; padding:0.5rem 0.75rem; background:var(--bg-surface-low); border-radius:0.5rem;">
        <div style="text-align:right;">
            <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">Total</div>
            <div style="font-size:1.25rem; font-weight:800; color:var(--text-on-surface);">₱' . number_format($invoice['amount'], 2) . '</div>
        </div>
    </div>
    ';
}

if (!empty($invoice['notes'])) {
    $html .= '
    <div style="margin-top:1rem; padding:0.75rem 1rem; background:var(--bg-surface-low); border-radius:0.5rem; border:1px solid var(--slate-200);">
        <div style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant); text-transform:uppercase; letter-spacing:0.05em;">Notes</div>
        <div style="font-size:0.8125rem; color:var(--text-on-surface-variant); margin-top:0.125rem;">' . nl2br(htmlspecialchars($invoice['notes'])) . '</div>
    </div>
    ';
}

echo json_encode([
    'success' => true,
    'html' => $html
]);
?>