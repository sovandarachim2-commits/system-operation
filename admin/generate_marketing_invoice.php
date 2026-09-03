<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'marketing_take.view', 'marketing_take_report.view', 'marketing_take_reconcile.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Invalid marketing take ID');
}

$pdo = get_db_connection();
$invoiceSettings = get_invoice_settings($pdo);
$invoiceLogo = get_invoice_logo($pdo);

// Load marketing take
$stmt = $pdo->prepare("
    SELECT mt.*, u1.name as created_by_name, u2.name as approved_by_name,
           sl.location_code, sl.location_name
    FROM marketing_takes mt
    LEFT JOIN users u1 ON mt.created_by = u1.id
    LEFT JOIN users u2 ON mt.approved_by = u2.id
    LEFT JOIN storage_locations sl ON mt.storage_location_id = sl.id
    WHERE mt.id = ?
");
$stmt->execute([$id]);
$take = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$take) {
    die('Marketing take not found');
}

// Access: same as marketing_take_detail
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$userRoles = $currentUser ? user_role_names($pdo, $currentUser) : [];
$isAdmin = in_array('admin', $userRoles, true);
$canViewAllMarkets = $isAdmin || (function_exists('has_permission') && has_permission('marketing_take_view_all.view'));
if (!$canViewAllMarkets && $userId > 0 && (int)$take['created_by'] !== $userId) {
    die('Access denied');
}

// Load items with product cost
$stmt = $pdo->prepare("
    SELECT mti.*, p.name as product_name, COALESCE(p.cost, 0) as unit_cost
    FROM marketing_take_items mti
    JOIN products p ON mti.product_id = p.id
    WHERE mti.marketing_take_id = ?
    ORDER BY p.name
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate line totals and grand total
$subtotal = 0;
foreach ($items as &$it) {
    $qty = (float)$it['quantity_taken'];
    $cost = (float)$it['unit_cost'];
    $it['line_total'] = $qty * $cost;
    $subtotal += $it['line_total'];
}
unset($it);

$companyName = $invoiceSettings['company_name'] ?? 'My Company';
$companyAddress = $invoiceSettings['company_address'] ?? '';
$companyPhone = $invoiceSettings['company_phone'] ?? '';
$companyEmail = $invoiceSettings['company_email'] ?? '';
$contactPerson = $invoiceSettings['contact_person'] ?? '';
$paymentUrl = trim($invoiceSettings['payment_url'] ?? '');

$logoWidth = max(40, min(200, (int)($invoiceSettings['logo_width'] ?? 80)));
$logoHeight = max(40, min(200, (int)($invoiceSettings['logo_height'] ?? 70)));
$logoStyle = "max-height: {$logoHeight}px; max-width: {$logoWidth}px; object-fit: contain;";

function marketing_invoice_logo_html(?array $logo, string $style): string
{
    global $BASE_URL;

    $filePath = $logo['file_path'] ?? '';
    if ($filePath === '') {
        $filePath = 'public/image.png';
    }

    $relative = ltrim(str_replace('\\', '/', (string)$filePath), '/');
    if ($relative === '') {
        return '';
    }

    $src = '';
    $localPath = __DIR__ . '/../' . $relative;
    if (is_file($localPath)) {
        $mime = @mime_content_type($localPath) ?: 'image/png';
        $src = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($localPath));
    } elseif (function_exists('uploaded_file_url')) {
        $src = uploaded_file_url($relative, 'logos');
    }

    if ($src === '') {
        $src = rtrim((string)($BASE_URL ?? ''), '/') . '/' . $relative;
    }

    return '<img src="' . htmlspecialchars($src) . '" alt="Logo" class="invoice-logo" style="' . htmlspecialchars($style) . '">';
}

$logoHtml = marketing_invoice_logo_html($invoiceLogo, $logoStyle);

$qrHtml = '';
if ($paymentUrl !== '') {
    $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paymentUrl);
    $qrHtml = '<div class="invoice-qr-section">
        <img src="' . htmlspecialchars($qrApiUrl) . '" alt="QR Code" class="invoice-qr" style="width: 120px; height: 120px;">
        <p class="invoice-qr-text">For online payment, scan QR code or visit:<br><a href="' . htmlspecialchars($paymentUrl) . '">' . htmlspecialchars($paymentUrl) . '</a></p>
    </div>';
}

$takeCode = $take['take_code'] ?? 'MT-#' . $id;
$issuedDate = date('m/d/Y', strtotime($take['event_date']));
$isReconciled = ($take['status'] === 'completed');

// Load reconciled_by for reconciled takes
$reconciledByName = null;
if ($isReconciled) {
    $rStmt = $pdo->prepare("SELECT u.name FROM marketing_takes mt LEFT JOIN users u ON mt.reconciled_by = u.id WHERE mt.id = ?");
    $rStmt->execute([$id]);
    $reconciledByName = $rStmt->fetchColumn();
}

$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>' . ($isReconciled ? 'Reconcile Invoice' : 'Marketing Invoice') . ' - ' . htmlspecialchars($takeCode) . '</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 30px 40px; color: #000; }
        .invoice-page { max-width: 900px; margin: 0 auto; }
        .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #000; }
        .invoice-header-left { display: flex; align-items: center; gap: 15px; }
        .invoice-logo { border-radius: 8px; }
        .invoice-company-name { font-size: 24px; font-weight: bold; color: #000; letter-spacing: 0.5px; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { font-size: 28px; font-weight: bold; color: #000; margin: 0 0 8px 0; }
        .invoice-meta { font-size: 13px; color: #000; }
        .invoice-meta span { display: block; }
        .billing-section { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 24px 0; }
        .billing-box h4 { font-size: 11px; color: #000; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px 0; border-bottom: 1px solid #000; padding-bottom: 6px; }
        .billing-box p { margin: 4px 0; font-size: 14px; color: #000; }
        .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .items-table th { background: #000; color: white; padding: 12px 14px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .items-table th.text-right { text-align: right; }
        .items-table td { padding: 12px 14px; border-bottom: 1px solid #000; font-size: 14px; color: #000; }
        .items-table tbody tr:nth-child(even) { background: #fff; }
        .text-right { text-align: right; }
        .totals-box { max-width: 320px; margin-left: auto; margin-top: 10px; }
        .totals-box table { width: 100%; border: none; }
        .totals-box td { padding: 8px 0; border: none; color: #000; }
        .totals-box .total-row { background: #000; color: white; font-weight: bold; padding: 12px 14px !important; }
        .totals-box .total-row td { color: white; font-size: 16px; }
        .invoice-footer { margin-top: 40px; padding-top: 24px; border-top: 1px solid #000; display: flex; align-items: flex-start; gap: 30px; flex-wrap: wrap; }
        .invoice-qr-section { display: flex; align-items: center; gap: 20px; }
        .invoice-qr { border: 1px solid #000; border-radius: 6px; }
        .invoice-qr-text { margin: 0; font-size: 13px; color: #000; }
        .notes-section { margin-top: 24px; }
        .notes-section h3 { color: #000; font-size: 14px; margin: 0 0 8px 0; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg); font-size: 120px; color: rgba(0,0,0,0.06); z-index: -1; pointer-events: none; }
    </style>
</head>
<body>
    <div class="watermark">INVOICE</div>
    <div class="invoice-page">
        <div class="invoice-header">
            <div class="invoice-header-left">
                ' . $logoHtml . '
                <div>
                    <div class="invoice-company-name">' . htmlspecialchars($companyName) . '</div>
                </div>
            </div>
            <div class="invoice-title">
                <h1>' . ($isReconciled ? 'RECONCILE INVOICE' : 'MARKETING INVOICE') . '</h1>
                <div class="invoice-meta">
                    <span>#' . htmlspecialchars($takeCode) . '</span>
                    <span>Event: ' . htmlspecialchars($take['event_name']) . '</span>
                    <span>Date: ' . htmlspecialchars($issuedDate) . '</span>
                    <span>Status: ' . ($take['status'] === 'pending' ? 'In Marketing' : ucfirst(str_replace('_', ' ', $take['status']))) . '</span>
                </div>
            </div>
        </div>

        <div class="billing-section">
            <div class="billing-box">
                <h4>From</h4>
                <p><strong>' . htmlspecialchars($companyName) . '</strong></p>';
if ($contactPerson) $html .= '<p>' . htmlspecialchars($contactPerson) . '</p>';
if ($companyPhone) $html .= '<p>' . htmlspecialchars($companyPhone) . '</p>';
if ($companyEmail) $html .= '<p>' . htmlspecialchars($companyEmail) . '</p>';
if ($companyAddress) $html .= '<p>' . nl2br(htmlspecialchars($companyAddress)) . '</p>';
$html .= '
            </div>
            <div class="billing-box">
                <h4>Event Details</h4>
                <p><strong>' . htmlspecialchars($take['event_name']) . '</strong></p>
                <p><strong>Date:</strong> ' . date('M j, Y', strtotime($take['event_date'])) . '</p>
                <p><strong>Location:</strong> ' . htmlspecialchars($take['location'] ?: '-') . '</p>
                <p><strong>Created by:</strong> ' . htmlspecialchars($take['created_by_name'] ?? '-') . '</p>
                <p><strong>Approved by:</strong> ' . htmlspecialchars($take['approved_by_name'] ?? '-') . '</p>' .
    ($isReconciled && $reconciledByName ? '<p><strong>Reconciled by:</strong> ' . htmlspecialchars($reconciledByName) . '</p><p><strong>Reconciled at:</strong> ' . ($take['reconciled_at'] ? date('M j, Y H:i', strtotime($take['reconciled_at'])) : '-') . '</p>' : '') . '
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="text-right">Qty Taken</th>' .
    ($isReconciled ? '
                    <th class="text-right">Qty Returned</th>
                    <th class="text-right">Qty Not Returned</th>' : '') . '
                </tr>
            </thead>
            <tbody>';

foreach ($items as $it) {
    $html .= '
                <tr>
                    <td>' . htmlspecialchars($it['product_name']) . '</td>
                    <td class="text-right">' . number_format((float)$it['quantity_taken'], 2) . '</td>';
    if ($isReconciled) {
        $html .= '
                    <td class="text-right">' . number_format((float)$it['quantity_returned'], 2) . '</td>
                    <td class="text-right">' . number_format((float)$it['quantity_not_returned'], 2) . '</td>';
    }
    $html .= '
                </tr>';
}

$html .= '
            </tbody>
        </table>';

if (!empty($take['notes'])) {
    $html .= '
        <div class="notes-section">
            <h3>Notes</h3>
            <p>' . nl2br(htmlspecialchars($take['notes'])) . '</p>
        </div>';
}

if ($qrHtml) {
    $html .= '
        <div class="invoice-footer">' . $qrHtml . '</div>';
}

$html .= '
        <div style="margin-top: 30px; text-align: center; font-size: 12px; color: #000;">
            Generated on ' . date('F j, Y H:i') . ' &mdash; Thank you!
        </div>
    </div>
</body>
</html>';

echo $html;
