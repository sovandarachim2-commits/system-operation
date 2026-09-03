<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_payments.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$payment_id = (int)($_GET['payment_id'] ?? 0);

if ($payment_id <= 0) {
    die('Invalid payment ID');
}

$pdo = get_db_connection();
$invoiceSettings = get_invoice_settings($pdo);
$invoiceLogo = get_invoice_logo($pdo);

try {
    $stmt = $pdo->prepare('
        SELECT pp.*, po.order_number, po.order_date, po.due_date, po.total_amount as order_total,
               po.tax_rate, po.tax_amount as order_tax_amount, po.shipping_cost as order_shipping_cost,
               pv.name as vendor_name, pv.address as vendor_address, pv.phone as vendor_phone,
               u.name as created_by_name
        FROM purchase_payments pp 
        LEFT JOIN purchase_orders po ON pp.purchase_order_id = po.id 
        LEFT JOIN purchase_vendors pv ON po.vendor_id = pv.id 
        LEFT JOIN users u ON pp.paid_by = u.id
        WHERE pp.id = ?
    ');
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();

    if (!$payment) {
        die('Payment not found');
    }

    $stmt = $pdo->prepare('
        SELECT poi.item_name, poi.quantity_ordered, poi.quantity_received, 
               poi.unit_price, poi.line_total
        FROM purchase_order_items poi
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ');
    $stmt->execute([$payment['purchase_order_id']]);
    $items = $stmt->fetchAll();

    $subtotal = $payment['order_total'];
    $tax_amount = (float)($payment['order_tax_amount'] ?? 0);
    $shipping_cost = (float)($payment['order_shipping_cost'] ?? 0);
    $total_with_tax = $payment['order_total'] + $tax_amount + $shipping_cost;

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

$issuedDate = date('m/d/Y', strtotime($payment['payment_date']));

$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Invoice - ' . htmlspecialchars($payment['payment_number']) . '</title>
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
    <div class="watermark">PAID</div>
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
                <h1>PAYMENT INVOICE</h1>
                <div class="invoice-meta">
                    <span>#' . htmlspecialchars($payment['payment_number']) . '</span>
                    <span>Order #' . htmlspecialchars($payment['order_number']) . '</span>
                    <span>Issued ' . htmlspecialchars($issuedDate) . '</span>
                </div>
            </div>
        </div>

        <div class="billing-section">
            <div class="billing-box">
                <h4>Bill From</h4>
                <p><strong>' . htmlspecialchars($companyName) . '</strong></p>';
if ($contactPerson) $html .= '<p>' . htmlspecialchars($contactPerson) . '</p>';
if ($companyPhone) $html .= '<p>' . htmlspecialchars($companyPhone) . '</p>';
if ($companyEmail) $html .= '<p>' . htmlspecialchars($companyEmail) . '</p>';
if ($companyAddress) $html .= '<p>' . nl2br(htmlspecialchars($companyAddress)) . '</p>';
$html .= '
            </div>
            <div class="billing-box">
                <h4>Bill To</h4>
                <p><strong>' . htmlspecialchars($payment['vendor_name']) . '</strong></p>';
if ($payment['vendor_phone']) $html .= '<p>' . htmlspecialchars($payment['vendor_phone']) . '</p>';
if ($payment['vendor_address']) $html .= '<p>' . nl2br(htmlspecialchars($payment['vendor_address'])) . '</p>';
$html .= '
            </div>
            <div class="billing-box">
                <h4>Payment Summary</h4>
                <p><strong>Payment #</strong> ' . htmlspecialchars($payment['payment_number']) . '</p>
                <p><strong>Method</strong> ' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . '</p>
                <p><strong>Status</strong> <span style="color: #28a745; font-weight: bold;">' . ucfirst($payment['payment_status']) . '</span></p>
            </div>
        </div>

        <div class="totals-box" style="margin-bottom: 24px;">
            <table>
                <tr><td>Payment Amount</td><td class="text-right">$' . number_format($payment['payment_amount'], 2) . '</td></tr>
                <tr><td>Tax (' . number_format($payment['tax_rate'] ?? 0, 2) . '%)</td><td class="text-right">$' . number_format($tax_amount, 2) . '</td></tr>
                <tr><td>Shipping</td><td class="text-right">$' . number_format($shipping_cost, 2) . '</td></tr>
                <tr class="total-row"><td>Total</td><td class="text-right">$' . number_format($total_with_tax, 2) . '</td></tr>
            </table>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">QTY</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>';

foreach ($items as $item) {
    $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['item_name']) . '</td>
                    <td class="text-right">' . number_format($item['quantity_ordered'], 2) . '</td>
                    <td class="text-right">$' . number_format($item['unit_price'], 2) . '</td>
                    <td class="text-right">$' . number_format($item['line_total'], 2) . '</td>
                </tr>';
}

$html .= '
            </tbody>
        </table>

        <table class="items-table" style="margin-top: 20px;">
            <tr>
                <th>Reference Number</th>
                <td>' . htmlspecialchars($payment['reference_number'] ?? 'N/A') . '</td>
            </tr>
            <tr>
                <th>Processed By</th>
                <td>' . htmlspecialchars($payment['created_by_name'] ?? 'Unknown') . '</td>
            </tr>
        </table>

        <div class="notes-section">
            <h3>Notes</h3>
            <p>' . nl2br(htmlspecialchars($payment['notes'] ?? 'No additional notes.')) . '</p>
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
