<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_orders.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die('Invalid order ID');
}

$pdo = get_db_connection();
$invoiceSettings = get_invoice_settings($pdo);
$invoiceLogo = get_invoice_logo($pdo);

try {
    $stmt = $pdo->prepare('
        SELECT po.*, pv.name as vendor_name, pv.address as vendor_address, pv.phone as vendor_phone,
               u.name as created_by_name
        FROM purchase_orders po 
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id 
        LEFT JOIN users u ON po.created_by = u.id
        WHERE po.id = ?
    ');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        die('Order not found');
    }

    $stmt = $pdo->prepare('
        SELECT poi.item_name, poi.quantity_ordered, poi.quantity_received, 
               poi.unit_price, poi.line_total,
               COALESCE(ret.qty_returned, 0) as quantity_returned,
               COALESCE(ret.amount_returned, 0) as amount_returned
        FROM purchase_order_items poi
        LEFT JOIN (
            SELECT pri.purchase_order_item_id,
                   SUM(pri.quantity_returned) as qty_returned,
                   SUM(pri.total_cost) as amount_returned
            FROM purchase_return_items pri
            JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
            WHERE pr.purchase_order_id = ?
            GROUP BY pri.purchase_order_item_id
        ) ret ON ret.purchase_order_item_id = poi.id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ');
    $stmt->execute([$order_id, $order_id]);
    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT pp.*, u.name as payment_created_by
        FROM purchase_payments pp 
        LEFT JOIN users u ON pp.paid_by = u.id
        WHERE pp.purchase_order_id = ?
        ORDER BY pp.payment_date DESC
    ');
    $stmt->execute([$order_id]);
    $payments = $stmt->fetchAll();

    $subtotal = $order['subtotal'];
    $tax_amount = $order['tax_amount'] ?? 0;
    $shipping_cost = $order['shipping_cost'] ?? 0;
    $total_with_tax = $order['total_amount'];
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

$companyName = $invoiceSettings['company_name'] ?? 'My Company';
$companyAddress = $invoiceSettings['company_address'] ?? '';
$companyPhone = $invoiceSettings['company_phone'] ?? '';
$companyEmail = $invoiceSettings['company_email'] ?? '';
$contactPerson = $invoiceSettings['contact_person'] ?? '';
$paymentUrl = trim($invoiceSettings['payment_url'] ?? '');

$logoWidth = max(40, min(200, (int)($invoiceSettings['logo_width'] ?? 80)));
$logoHeight = max(40, min(200, (int)($invoiceSettings['logo_height'] ?? 70)));
$logoStyle = "max-height: {$logoHeight}px; max-width: {$logoWidth}px; object-fit: contain;";

$logoHtml = '';
if ($invoiceLogo && !empty($invoiceLogo['file_path'])) {
    $logoPath = __DIR__ . '/../' . ltrim($invoiceLogo['file_path'], '/');
    if (is_file($logoPath)) {
        $mime = @mime_content_type($logoPath) ?: 'image/png';
        $data = base64_encode(file_get_contents($logoPath));
        $logoSrc = 'data:' . $mime . ';base64,' . $data;
        $logoHtml = '<img src="' . $logoSrc . '" alt="Logo" class="invoice-logo" style="' . $logoStyle . '">';
    } else {
        $logoSrc = rtrim($DOMAIN ?? '', '/') . '/' . ltrim($invoiceLogo['file_path'], '/');
        $logoHtml = '<img src="' . htmlspecialchars($logoSrc) . '" alt="Logo" class="invoice-logo" style="' . $logoStyle . '">';
    }
}

$qrHtml = '';
if ($paymentUrl !== '') {
    $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paymentUrl);
    $qrHtml = '<div class="invoice-qr-section">
        <img src="' . htmlspecialchars($qrApiUrl) . '" alt="QR Code" class="invoice-qr" style="width: 120px; height: 120px;">
        <p class="invoice-qr-text">For online payment, scan QR code or visit:<br><a href="' . htmlspecialchars($paymentUrl) . '">' . htmlspecialchars($paymentUrl) . '</a></p>
    </div>';
}

$issuedDate = date('m/d/Y', strtotime($order['order_date']));
$dueDate = $order['due_date'] ? date('m/d/Y', strtotime($order['due_date'])) : 'Upon Receipt';

$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Order Invoice - ' . htmlspecialchars($order['order_number']) . '</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 30px 40px; color: #000; }
        .invoice-page { max-width: 900px; margin: 0 auto; }
        .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #e0e8f0; }
        .invoice-header-left { display: flex; align-items: center; gap: 15px; }
        .invoice-logo { border-radius: 8px; }
        .invoice-company-name { font-size: 24px; font-weight: bold; color: #1e3a5f; letter-spacing: 0.5px; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { font-size: 28px; font-weight: bold; color: #1e3a5f; margin: 0 0 8px 0; }
        .invoice-meta { font-size: 13px; color: #000; }
        .invoice-meta span { display: block; }
        .billing-section { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin: 24px 0; }
        .billing-box h4 { font-size: 11px; color: #1e3a5f; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px 0; border-bottom: 1px solid #e0e8f0; padding-bottom: 6px; }
        .billing-box p { margin: 4px 0; font-size: 14px; }
        .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .items-table th { background: #1e3a5f; color: white; padding: 12px 14px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .items-table th.text-right { text-align: right; }
        .items-table td { padding: 12px 14px; border-bottom: 1px solid #e8eef4; font-size: 14px; }
        .items-table tbody tr:nth-child(even) { background: #f8fafc; }
        .items-table tbody tr:nth-child(odd) { background: #fff; }
        .text-right { text-align: right; }
        .totals-box { max-width: 320px; margin-left: auto; margin-top: 10px; }
        .totals-box table { width: 100%; border: none; }
        .totals-box td { padding: 8px 0; border: none; }
        .totals-box .total-row { background: #1e3a5f; color: white; font-weight: bold; padding: 12px 14px !important; }
        .totals-box .total-row td { color: white; font-size: 16px; }
        .payment-section { background: #f8fafc; padding: 20px; border-radius: 8px; margin: 24px 0; }
        .payment-section h3 { margin: 0 0 12px 0; color: #1e3a5f; font-size: 16px; }
        .invoice-footer { margin-top: 40px; padding-top: 24px; border-top: 1px solid #e0e8f0; display: flex; align-items: flex-start; gap: 30px; flex-wrap: wrap; }
        .invoice-qr-section { display: flex; align-items: center; gap: 20px; }
        .invoice-qr { border: 1px solid #ddd; border-radius: 6px; }
        .invoice-qr-text { margin: 0; font-size: 13px; color: #000; }
        .invoice-qr-text a { color: #000; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg); font-size: 120px; color: rgba(30,58,95,0.06); z-index: -1; pointer-events: none; }
        .notes-section { margin-top: 24px; }
        .notes-section h3 { color: #1e3a5f; font-size: 14px; margin: 0 0 8px 0; }
        .notes-section p { margin: 0; font-size: 13px; color: #000; }
    </style>
</head>
<body>
    <div class="watermark">INVOICE</div>
    <div class="invoice-page">';

$html .= '
        <div class="invoice-header">
            <div class="invoice-header-left">
                ' . $logoHtml . '
                <div>
                    <div class="invoice-company-name">' . htmlspecialchars($companyName) . '</div>
                </div>
            </div>
            <div class="invoice-title">
                <h1>INVOICE</h1>
                <div class="invoice-meta">
                    <span>#' . htmlspecialchars($order['order_number']) . '</span>
                    <span>Due ' . htmlspecialchars($dueDate) . '</span>
                    <span>Issued ' . htmlspecialchars($issuedDate) . '</span>
                </div>
            </div>
        </div>

        <div class="billing-section">
            <div class="billing-box">
                <h4>Bill From</h4>
                <p><strong>' . htmlspecialchars($companyName) . '</strong></p>';
if ($contactPerson) {
    $html .= '<p>' . htmlspecialchars($contactPerson) . '</p>';
}
if ($companyPhone) {
    $html .= '<p>' . htmlspecialchars($companyPhone) . '</p>';
}
if ($companyEmail) {
    $html .= '<p>' . htmlspecialchars($companyEmail) . '</p>';
}
if ($companyAddress) {
    $html .= '<p>' . nl2br(htmlspecialchars($companyAddress)) . '</p>';
}
$html .= '
            </div>
            <div class="billing-box">
                <h4>Bill To</h4>
                <p><strong>' . htmlspecialchars($order['vendor_name']) . '</strong></p>';
if ($order['vendor_phone']) {
    $html .= '<p>' . htmlspecialchars($order['vendor_phone']) . '</p>';
}
if ($order['vendor_address']) {
    $html .= '<p>' . nl2br(htmlspecialchars($order['vendor_address'])) . '</p>';
}
$html .= '
            </div>
            <div class="billing-box">
                <h4>Order Summary</h4>
                <p><strong>Order #</strong> ' . htmlspecialchars($order['order_number']) . '</p>
                <p><strong>Status</strong> ' . ucfirst($order['status']) . '</p>
                <p><strong>Payment</strong> <span style="color: ' . ($order['payment_status'] === 'paid' ? '#28a745' : '#fd7e14') . '; font-weight: bold;">' . ucfirst($order['payment_status']) . '</span></p>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">QTY</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Tax</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Qty Return</th>
                    <th class="text-right">Amount Return</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>';

$taxRate = $order['tax_rate'] ?? 0;
foreach ($items as $item) {
    $itemTax = 0;
    if ($taxRate > 0 && $item['line_total'] > 0) {
        $itemTax = $item['line_total'] * ($taxRate / 100) / (1 + $taxRate / 100);
    }
    $qtyReturn = (float)($item['quantity_returned'] ?? 0);
    $amtReturn = (float)($item['amount_returned'] ?? 0);
    $lineTotal = (float)$item['line_total'];
    $netTotal = max(0, $lineTotal - $amtReturn);
    $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['item_name']) . '</td>
                    <td class="text-right">' . number_format($item['quantity_ordered'], 2) . '</td>
                    <td class="text-right">$' . number_format($item['unit_price'], 2) . '</td>
                    <td class="text-right">' . number_format($taxRate, 0) . '%</td>
                    <td class="text-right">$' . number_format($lineTotal, 2) . '</td>
                    <td class="text-right">' . number_format($qtyReturn, 2) . '</td>
                    <td class="text-right">$' . number_format($amtReturn, 2) . '</td>
                    <td class="text-right">$' . number_format($netTotal, 2) . '</td>
                </tr>';
}

$total_amount_return = array_sum(array_map(function ($item) {
    return (float)($item['amount_returned'] ?? 0);
}, $items));

$html .= '
            </tbody>
        </table>

        <div class="totals-box">
            <table>
                <tr><td>Subtotal</td><td class="text-right">$' . number_format($subtotal, 2) . '</td></tr>
                <tr><td>Tax (' . number_format($taxRate, 2) . '%)</td><td class="text-right">$' . number_format($tax_amount, 2) . '</td></tr>
                <tr><td>Shipping</td><td class="text-right">$' . number_format($shipping_cost, 2) . '</td></tr>
                <tr><td>Amount Return</td><td class="text-right">$' . number_format($total_amount_return, 2) . '</td></tr>
                <tr class="total-row"><td>Total</td><td class="text-right">$' . number_format($total_with_tax, 2) . '</td></tr>
            </table>
        </div>';

if (!empty($payments)) {
    $completed_payments = array_filter($payments, function ($p) {
        return $p['payment_status'] === 'completed';
    });
    $total_paid = array_sum(array_column($completed_payments, 'payment_amount'));
    $balance_due = $order['total_amount'] - $total_paid;

    $html .= '
        <div class="payment-section">
            <h3>Payment History</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($payments as $payment) {
        $html .= '
                    <tr>
                        <td>' . htmlspecialchars($payment['payment_number']) . '</td>
                        <td>' . date('M j, Y', strtotime($payment['payment_date'])) . '</td>
                        <td>' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . '</td>
                        <td class="text-right">$' . number_format($payment['payment_amount'], 2) . '</td>
                        <td><span style="color: ' . ($payment['payment_status'] === 'completed' ? '#28a745' : '#fd7e14') . '; font-weight: bold;">' . ucfirst($payment['payment_status']) . '</span></td>
                    </tr>';
    }
    $html .= '
                </tbody>
            </table>
            <div class="totals-box" style="margin-top: 12px;">
                <table>
                    <tr><td>Total Paid</td><td class="text-right">$' . number_format($total_paid, 2) . '</td></tr>
                    <tr class="total-row"><td>Balance Due</td><td class="text-right">$' . number_format($balance_due, 2) . '</td></tr>
                </table>
            </div>
        </div>';
}

$html .= '
        <div class="notes-section">
            <h3>Notes</h3>
            <p>' . nl2br(htmlspecialchars($order['notes'] ?? 'No additional notes.')) . '</p>
        </div>';

if ($qrHtml) {
    $html .= '
        <div class="invoice-footer">
            ' . $qrHtml . '
        </div>';
}

$html .= '
        <div style="margin-top: 30px; text-align: center; font-size: 12px; color: #000;">
            Generated on ' . date('F j, Y H:i') . ' &mdash; Thank you for your business!
        </div>
    </div>
</body>
</html>';

echo $html;
