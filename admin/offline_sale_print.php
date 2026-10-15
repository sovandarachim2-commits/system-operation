<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'offline_sales.view', 'offline_sales.create');
require_once __DIR__ . '/offline_lib.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);

if (($_GET['embed'] ?? '') === '1') {
    header_remove('X-Frame-Options');
}
$type       = in_array($_GET['type'] ?? '', ['receipt', 'invoice', 'delivery']) ? $_GET['type'] : 'receipt';
$isInvoice  = $type === 'invoice';
$isDelivery = $type === 'delivery';

$idsParam = $_GET['ids'] ?? '';
$idsRaw   = array_filter(array_map('trim', explode(',', $idsParam)), 'strlen');
$orderIds = [];
foreach ($idsRaw as $id) {
    $id = (int)$id;
    if ($id > 0) $orderIds[] = $id;
}
$orderIds = array_values(array_unique($orderIds));

if (!$orderIds) {
    header('Location: offline_sale_orders.php');
    exit;
}

// Exchange rate
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT
)");
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_to_khr_rate'");
$stmt->execute();
$exchangeRate = (float)($stmt->fetchColumn() ?: 4100);

// Invoice settings
$invoiceSettings = get_invoice_settings($pdo);
$companyName    = $invoiceSettings['company_name']    ?? '';
$companyAddress = $invoiceSettings['company_address'] ?? '';
$companyPhone   = $invoiceSettings['company_phone']   ?? '';
$companyEmail   = $invoiceSettings['company_email']   ?? '';
$contactPerson  = $invoiceSettings['contact_person']  ?? '';
$paymentUrl     = trim($invoiceSettings['payment_url'] ?? '');
$logoWidth      = max(40, min(200, (int)($invoiceSettings['logo_width']  ?? 100)));
$logoHeight     = max(40, min(200, (int)($invoiceSettings['logo_height'] ?? 90)));
$logoStyle      = "max-height:{$logoHeight}px;max-width:{$logoWidth}px;object-fit:contain;";

// Logo — embed as base64 for reliable print rendering
$invoiceLogo = null;
if (!empty($invoiceSettings['logo_id'])) {
    $lStmt = $pdo->prepare('SELECT * FROM logos WHERE id = ? LIMIT 1');
    $lStmt->execute([(int)$invoiceSettings['logo_id']]);
    $invoiceLogo = $lStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$invoiceLogo) {
    $invoiceLogo = get_default_logo($pdo);
}
$defaultLogo = get_default_logo($pdo);

function make_logo_html(string $BASE_URL, ?array $logo, string $style = '', string $cls = ''): string {
    if (!$logo || empty($logo['file_path'])) {
        return '';
    }

    $relative = ltrim((string)$logo['file_path'], '/');
    $logoPath = __DIR__ . '/../' . $relative;
    $src = '';

    if (is_file($logoPath)) {
        $mime = @mime_content_type($logoPath) ?: 'image/png';
        $src = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
    } else {
        $remoteUrl = '';
        if (function_exists('uploaded_file_url')) {
            $remoteUrl = trim((string)uploaded_file_url($relative, 'logos'));
        }
        if ($remoteUrl === '') {
            $remoteUrl = rtrim($BASE_URL, '/') . '/' . $relative;
        }

        // Prefer embedded data URI so print/PDF still shows the logo.
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'follow_location' => 1],
            'https' => ['timeout' => 5, 'follow_location' => 1],
        ]);
        $bytes = @file_get_contents($remoteUrl, false, $context);
        if ($bytes !== false && $bytes !== '') {
            $mime = 'image/jpeg';
            if (str_ends_with(strtolower($relative), '.png')) {
                $mime = 'image/png';
            } elseif (str_ends_with(strtolower($relative), '.webp')) {
                $mime = 'image/webp';
            } elseif (str_ends_with(strtolower($relative), '.gif')) {
                $mime = 'image/gif';
            }
            $finfoMime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : false;
            if (is_string($finfoMime) && str_starts_with($finfoMime, 'image/')) {
                $mime = $finfoMime;
            }
            $src = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } else {
            $src = $remoteUrl;
        }
    }

    if ($src === '') {
        return '';
    }

    return '<img src="' . htmlspecialchars($src) . '" alt="Logo"'
         . ($cls   ? ' class="' . htmlspecialchars($cls)   . '"' : '')
         . ($style ? ' style="' . htmlspecialchars($style) . '"' : '')
         . '>';
}

// QR for payment URL
$qrHtml = '';
if ($paymentUrl !== '') {
    $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($paymentUrl);
    $qrHtml   = '<div class="invoice-qr-section">
        <img src="' . htmlspecialchars($qrApiUrl) . '" alt="QR Code" class="invoice-qr">
        <p class="invoice-qr-text">For online payment, scan QR code or visit:<br>
            <a href="' . htmlspecialchars($paymentUrl) . '">' . htmlspecialchars($paymentUrl) . '</a></p>
    </div>';
}

// Load orders (exclude cancelled)
$placeholders = implode(',', array_fill(0, count($orderIds), '?'));
$stmt = $pdo->prepare("
    SELECT oso.*, sl.location_name, ot.name AS team_name, uc.name AS created_by_name
    FROM offline_sale_orders oso
    JOIN storage_locations sl ON sl.id = oso.location_id
    LEFT JOIN offline_teams ot ON ot.id = oso.team_id
    LEFT JOIN users uc ON uc.id = oso.created_by
    WHERE oso.id IN ($placeholders)
    ORDER BY oso.sale_date ASC, oso.id ASC
");
$stmt->execute($orderIds);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$loadedIds = array_map(static fn($r) => (int)$r['id'], $orders);

$itemsByOrder   = [];
$purchaseItemsByOrder = [];
$paymentsByOrder = [];

if ($loadedIds) {
    $ph = implode(',', array_fill(0, count($loadedIds), '?'));

    $iStmt = $pdo->prepare("
        SELECT i.order_id, i.product_name, i.quantity, i.unit_price, i.line_total
        FROM offline_sale_order_items i
        LEFT JOIN products p ON p.id = i.product_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE i.order_id IN ($ph)
        ORDER BY i.order_id,
                 CASE WHEN TRIM(COALESCE(b.name, '')) = '' THEN 1 ELSE 0 END,
                 b.name,
                 i.product_name,
                 i.id
    ");
    $iStmt->execute($loadedIds);
    foreach ($iStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemsByOrder[(int)$row['order_id']][] = $row;
    }
    $pStmt = $pdo->prepare("
        SELECT i.order_id, i.product_name, i.quantity, i.unit_price, i.line_total, i.item_condition, i.reason
        FROM offline_sale_purchase_items i
        LEFT JOIN products p ON p.id = i.product_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE i.order_id IN ($ph)
        ORDER BY i.order_id,
                 CASE WHEN TRIM(COALESCE(b.name, '')) = '' THEN 1 ELSE 0 END,
                 b.name,
                 i.product_name,
                 i.id
    ");
    $pStmt->execute($loadedIds);
    foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $purchaseItemsByOrder[(int)$row['order_id']][] = $row;
    }

    $paymentsByOrder = offline_payments_for_orders($pdo, $loadedIds);
}

$pageTitle = $isInvoice ? 'Offline Sale Invoice' : ($isDelivery ? 'Delivery Slip' : 'Offline Sale Receipt');

function fq(float $q): string { return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.'); }
function fd(?string $v): string {
    if (!$v) return '—';
    $ts = strtotime($v);
    return $ts ? date('M j, Y', $ts) : '—';
}
function status_kh(string $s): string {
    return match(strtolower($s)) {
        'paid'      => 'បានបង់',
        'partial'   => 'បានបង់មួយផ្នែក',
        'unpaid'    => 'មិនទាន់បង់',
        'cancelled','canceled' => 'បានបោះបង់',
        default     => $s,
    };
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;900&family=Battambang:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* ════════════════════════════════
           RECEIPT  (matches bulk_print.php)
           ════════════════════════════════ */
        body {
            background: #e5e7eb;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .bulk-receipt-bundle {
            page-break-after: always;
            max-width: 360px;
            margin: 1.5rem auto;
        }
        .bulk-receipt-bundle:last-of-type { page-break-after: auto; }
        .receipt-card {
            box-sizing: border-box;
            overflow: hidden;
            padding: 0;
            max-width: 360px;
            margin: 0 auto;
            border-radius: 0;
            box-shadow: 0 18px 40px rgba(15,23,42,.18);
            background: #fff;
            border: 1px solid #e5e7eb;
        }
        .receipt-header-logo { text-align: center; margin-bottom: .5rem; }
        .receipt-header-logo img { max-height: 80px; max-width: 120px; object-fit: contain; }
        .receipt-title {
            font-size: .8rem; font-weight: 700;
            letter-spacing: .14em; text-transform: uppercase;
            text-align: center; margin-bottom: .75rem;
        }
        .section-title {
            font-size: .75rem; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase;
            color: #000; margin-bottom: .25rem;
        }
        .receipt-qr { width: 72px; height: 72px; margin-left: -115px; }
        .amount-col  { text-align: left; min-width: 80px; margin-left: -20px; }
        .section-divider {
            border-bottom: 1px solid black;
            border-top: 1px dashed #e5e7eb;
            margin: .6rem 0;
        }
        .label-col { color: #000; font-weight: 600; font-size: 16px; }
        .value-col { color: #000; font-weight: 600; font-size: 16px; }
        /* ── status stamp ── */
        .status-stamp {
            display: inline-block;
            border: 3px double currentColor;
            border-radius: 8px;
            padding: 5px 20px;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 3px;
            font-family: 'Noto Sans Khmer', sans-serif;
            transform: rotate(-5deg);
            opacity: .88;
            text-transform: uppercase;
        }
        .status-stamp-paid    { color: #065f46; background: rgba(209,250,229,.25); }
        .status-stamp-partial { color: #92400e; background: rgba(255,237,213,.25); }
        .status-stamp-unpaid  { color: #991b1b; background: rgba(254,226,226,.25); }
        .stamp-wrap { text-align: center; margin: 8px 0 6px; }

        /* ── invoice large stamp ── */
        .inv-stamp-wrap { text-align: right; margin-top: 8px; }
        .inv-stamp {
            display: inline-block;
            border: 4px double currentColor;
            border-radius: 10px;
            padding: 6px 24px;
            font-size: 28px;
            font-weight: 900;
            letter-spacing: 4px;
            font-family: 'Noto Sans Khmer', sans-serif;
            transform: rotate(-6deg);
            opacity: .82;
            text-transform: uppercase;
        }
        .inv-stamp-paid    { color: #065f46; background: rgba(209,250,229,.2); }
        .inv-stamp-partial { color: #92400e; background: rgba(255,237,213,.2); }
        .inv-stamp-unpaid  { color: #991b1b; background: rgba(254,226,226,.2); }

        /* payment summary rows in receipt */
        .pay-row {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: .2rem;
        }
        .pay-row .pay-label { font-weight: 600; font-size: 14px; color: #000; }
        .pay-row .pay-val   { font-weight: 700; font-size: 14px; }
        .pay-history-row {
            display: flex; justify-content: space-between;
            font-size: 12px; color: #374151; margin-bottom: .15rem;
        }
        .receipt-card,
        .receipt-card .card-body,
        .receipt-card .section-title,
        .receipt-card .value-col,
        .receipt-card .label-col,
        .receipt-card .amount-col,
        .receipt-card .text-center,
        .receipt-card .justify-content-between,
        .receipt-card .justify-content-center,
        .receipt-card .text-end { text-align: left !important; }
        .thermal-cut-feed { display: none; }

        /* ════════════════════════════════
           INVOICE — customer document
           ════════════════════════════════ */
        .invoice-bundle { page-break-after: always; max-width: 820px; margin: 1.5rem auto; }
        .invoice-bundle:last-of-type { page-break-after: auto; }
        .invoice-page {
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,.13);
            overflow: hidden;
            padding: 32px 40px;
            color: #111;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .invoice-page .inv-accent { background: #1e3a5f; height: 7px; margin: -32px -40px 24px; }
        .invoice-page .inv-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 18px; }
        .invoice-page .inv-top-left { flex: 1; min-width: 0; }
        .invoice-page .inv-top-right { flex: 0 0 46%; text-align: right; }
        .invoice-page .inv-brand { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
        .invoice-page .invoice-logo { display: block; object-fit: contain; }
        .invoice-page .inv-company-name { font-size: 17px; font-weight: 800; color: #111; margin-bottom: 4px; }
        .invoice-page .inv-company-info { font-size: 13px; color: #111; line-height: 1.75; }
        .invoice-page .inv-title-km { font-size: 22px; font-weight: 800; color: #111; line-height: 1.25; }
        .invoice-page .inv-title-en { font-size: 13px; font-weight: 700; color: #111; letter-spacing: 1.5px; margin: 2px 0 10px; }
        .invoice-page .inv-info-tbl { margin-left: auto; font-size: 13px; border-collapse: collapse; }
        .invoice-page .inv-info-tbl td { padding: 5px 8px; color: #111; text-align: left; line-height: 1.35; vertical-align: top; border: 0; }
        .invoice-page .inv-info-tbl td:first-child { font-weight: 700; white-space: nowrap; }
        .invoice-page .inv-info-tbl td:last-child { font-weight: 600; overflow-wrap: anywhere; }
        .invoice-page .inv-info-tbl tr:nth-child(odd) td { background: #e8eef5; }
        .invoice-page .inv-info-tbl tr:nth-child(even) td { background: #f8fafc; }
        .invoice-page .lbl-km,
        .invoice-page .lbl-en { display: inline; font-size: inherit; font-weight: inherit; color: inherit; }
        .invoice-page .lbl-en::before { content: " / "; }
        .invoice-page .inv-divider { border: none; border-top: 1px solid #000; margin: 16px 0; }
        .invoice-page .inv-items,
        .invoice-page .inv-pay-table { width: 100%; border-collapse: collapse; }
        .invoice-page .inv-items th,
        .invoice-page .inv-pay-table th {
            background: #1e3a5f;
            color: #fff;
            padding: 9px 12px;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #16304d;
        }
        .invoice-page .inv-items th .th-en { display: block; font-size: 10px; font-weight: 600; letter-spacing: .3px; color: inherit; }
        .invoice-page .inv-pay-table th .th-en { display: inline; font-size: inherit; font-weight: inherit; letter-spacing: 0; color: inherit; }
        .invoice-page .inv-pay-table th .th-en::before { content: " / "; }
        .invoice-page .inv-items th.tl { text-align: left; }
        .invoice-page .inv-items td,
        .invoice-page .inv-pay-table td { padding: 8px 12px; border: 1px solid #000; font-size: 13px; color: #111; }
        .invoice-page .tc { text-align: center; }
        .invoice-page .tr { text-align: right; }
        .invoice-page .inv-items tbody tr:nth-child(even),
        .invoice-page .inv-pay-table tbody tr:nth-child(even) { background: #f3f6fa; }
        .invoice-page .line-note { margin-top: 2px; font-size: 11px; font-weight: 600; color: #111; }
        .invoice-page .inv-sum-wrap { display: flex; justify-content: flex-end; margin-top: 0; }
        .invoice-page .inv-sum { border-collapse: collapse; min-width: 280px; font-size: 13px; }
        .invoice-page .inv-sum td { padding: 7px 12px; border: 1px solid #000; color: #111; }
        .invoice-page .inv-sum td:first-child { text-align: right; font-weight: 700; background: #e8eef5; white-space: nowrap; }
        .invoice-page .inv-sum tr:nth-child(even) td:first-child { background: #f8fafc; }
        .invoice-page .inv-sum td:last-child { text-align: right; font-weight: 700; min-width: 120px; }
        .invoice-page .inv-sum tr.sum-total td { background: #1e3a5f; color: #fff; font-weight: 800; }
        .invoice-page .inv-sum tr.sum-total .lbl-en,
        .invoice-page .inv-sum tr.sum-total .khr { color: #fff; background: transparent; }
        .invoice-page .inv-sum .khr { display: block; margin-top: 2px; font-size: 12px; font-weight: 700; }
        .invoice-page .inv-rate { margin-top: 6px; text-align: right; font-size: 11px; font-weight: 600; color: #111; }
        .invoice-page .inv-section-label { font-size: 13px; font-weight: 800; color: #111; margin: 16px 0 8px; }
        .invoice-page .inv-pay-table th { padding: 7px 10px; }
        .invoice-page .inv-pay-table td { padding: 6px 10px; font-size: 12px; }
        .invoice-page .inv-pay-table tfoot td { font-weight: 800; background: #e8eef5; }
        .invoice-page .inv-notes { min-height: 28px; padding: 10px 0 4px; font-size: 13px; color: #111; }
        .invoice-page .inv-footer { margin-top: 16px; padding-top: 12px; border-top: 1px solid #000; display: flex; align-items: center; gap: 16px; }
        .invoice-page .inv-footer-qr img { width: 84px; height: 84px; border: 1px solid #000; display: block; }
        .invoice-page .inv-footer-text { font-size: 12px; color: #111; line-height: 1.5; }
        .invoice-page .inv-footer-text a { color: #111; font-weight: 700; word-break: break-all; }
        .invoice-page .inv-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-top: 48px; padding: 0 24px; }
        .invoice-page .inv-sig { text-align: center; font-size: 14px; font-weight: 800; color: #111; }
        .invoice-page .inv-sig .sig-en { display: inline; font-size: inherit; font-weight: inherit; }
        .invoice-page .sig-en::before { content: " / "; }
        .invoice-page .inv-sig-line { border-top: 1px solid #111; margin-top: 56px; }
        .invoice-page .inv-generated { margin-top: 16px; text-align: center; font-size: 11px; color: #111; padding-top: 8px; }

        /* ════════════════════════════════
           DELIVERY SLIP
           ════════════════════════════════ */
        .delivery-bundle {
            page-break-after: always;
            max-width: 360px;
            margin: 1.5rem auto;
        }
        .delivery-bundle:last-of-type { page-break-after: auto; }
        .delivery-card {
            background: #fff;
            border: 2px solid #000;
            padding: 0;
            max-width: 360px;
            margin: 0 auto;
            box-shadow: 0 4px 16px rgba(0,0,0,.15);
        }
        .delivery-header {
            background: #1e3a5f;
            color: #fff;
            text-align: center;
            padding: .6rem .5rem .5rem;
        }
        .delivery-header-title {
            font-size: 1rem; font-weight: 900;
            letter-spacing: .12em; text-transform: uppercase;
            font-family: 'Noto Sans Khmer', sans-serif;
        }
        .delivery-header-sub { font-size: .7rem; opacity: .85; margin-top: 2px; }
        .delivery-body { padding: .75rem 1rem; }
        .delivery-qr-row {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: .5rem; margin-bottom: .5rem;
        }
        .delivery-qr-row img { width: 72px; height: 72px; border: 1px solid #000; }
        .delivery-order-meta { flex: 1; font-size: 12px; font-weight: 700; }
        .delivery-order-meta .dm-code { font-size: 14px; font-weight: 900; }
        .delivery-order-meta .dm-date { font-size: 11px; color: #374151; }
        .delivery-divider { border-top: 1px dashed #000; margin: .45rem 0; }
        .delivery-section-label {
            font-size: .65rem; font-weight: 800; letter-spacing: .08em;
            text-transform: uppercase; color: #1e3a5f;
            margin-bottom: .25rem; font-family: 'Noto Sans Khmer', sans-serif;
        }
        .delivery-row {
            display: flex; gap: .4rem; font-size: 13px;
            margin-bottom: .2rem; font-weight: 600;
        }
        .delivery-row .dl { flex: 0 0 80px; color: #374151; font-weight: 600; }
        .delivery-row .dv { flex: 1; color: #000; font-weight: 700; }
        .delivery-product-row {
            display: flex; justify-content: space-between;
            font-size: 13px; font-weight: 600; margin-bottom: .2rem;
        }
        .delivery-footer {
            border-top: 2px solid #000;
            padding: .5rem 1rem;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 11px;
        }
        .delivery-sig { text-align: center; }
        .delivery-sig .sig-line { width: 90px; border-top: 1px solid #000; margin: 24px auto 2px; }
        .delivery-sig .sig-label { font-size: 10px; font-weight: 700; font-family: 'Noto Sans Khmer', sans-serif; }
        .delivery-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: .25rem;
            font-family: 'Noto Sans Khmer', sans-serif;
        }
        .delivery-items-table th,
        .delivery-items-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 13px;
            color: #111;
            vertical-align: middle;
        }
        .delivery-items-table th {
            background: #d1d5db;
            text-align: center;
            font-weight: 700;
            line-height: 1.25;
        }
        .delivery-items-table th .th-en {
            display: block;
            font-size: 11px;
            font-weight: 600;
        }
        .delivery-items-table td.tc { text-align: center; }
        .delivery-items-table td.tl { text-align: left; }
        .delivery-items-table td.tr { text-align: right; }
        .delivery-items-table tfoot td {
            font-weight: 700;
            background: #fff;
        }
        .delivery-items-table tfoot .qty-total-label {
            text-align: right;
            font-size: 12px;
            line-height: 1.3;
        }
        .delivery-items-table tfoot .qty-total-value {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
        }

        /* ── shared ── */
        @media print {
            body, html { background: #fff; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .receipt-card { page-break-inside: avoid; }
            .invoice-page { box-shadow: none; }
            .thermal-cut-feed {
                display: block;
                height: 18mm;
            }
        }

        <?php if ($isInvoice): ?>
        /* Leave page size, margins, and scale to Chrome's print dialog. */
        @media print {
            .container-fluid { padding: 0 !important; }
            .invoice-bundle { max-width: none; margin: 0; }
            .invoice-page { padding: 8px 12px !important; box-shadow: none !important; }
            .invoice-page .inv-accent { margin: -8px -12px 12px; }
            .invoice-page .inv-title-km { font-size: 18px; }
            .invoice-page .inv-signatures { margin-top: 36px; }
            .invoice-page .inv-sig-line { margin-top: 42px; }
        }
        <?php endif; ?>
    </style>
</head>
<body>
<div class="container-fluid py-3">
    <div class="no-print mb-3 d-flex justify-content-between align-items-center">
        <h1 class="h5 mb-0"><?= htmlspecialchars($pageTitle) ?></h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button>
        </div>
    </div>

    <?php if (!$orders): ?>
        <div class="alert alert-warning">No orders found to print.</div>
    <?php else: ?>

    <?php foreach ($orders as $order):
        $orderId     = (int)$order['id'];
        $items       = $itemsByOrder[$orderId]    ?? [];
        $purchaseItems = $purchaseItemsByOrder[$orderId] ?? [];
        $payments    = $paymentsByOrder[$orderId] ?? [];
        $subtotal    = (float)($order['subtotal']     ?? 0);
        $discount    = (float)($order['discount']     ?? 0);
        $purchaseTotal = (float)($order['purchase_total'] ?? 0);
        $totalAmount = (float)($order['total_amount'] ?? 0);

        $totalPaid = 0.0;
        foreach ($payments as $p) { $totalPaid += (float)($p['amount'] ?? 0); }
        $totalPaid  = min($totalPaid, $totalAmount);
        $balanceDue = max(0, $totalAmount - $totalPaid);

        $qrOrderUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($order['order_code']);
    ?>

    <?php /* ════════════ DELIVERY ════════════ */ if ($isDelivery): ?>
    <div class="bulk-receipt-bundle">
    <div class="receipt-card card">
        <div class="card-body p-3">

            <!-- Logo -->
            <div class="receipt-header-logo">
                <?= make_logo_html($BASE_URL, $defaultLogo) ?>
            </div>
            <div class="receipt-title" style="font-family:'Noto Sans Khmer',sans-serif;">
                ប័ណ្ណដឹក / Delivery Slip
            </div>

            <!-- Order code + date LEFT | QR RIGHT -->
            <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                <div class="small">
                    <?php if (!empty($order['team_name'])): ?>
                    <div>
                        <span class="label-col" style="font-size:16px;font-family:'Noto Sans Khmer',sans-serif;">ក្រុម:</span>
                        <span class="value-col" style="font-size:16px;"><?= htmlspecialchars($order['team_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:12px;font-weight:600;margin-top:2px;color:black;font-family:'Noto Sans Khmer',sans-serif;">
                        លេខកូដ: <?= htmlspecialchars($order['order_code']) ?>
                    </div>
                    <div style="font-size:12px;font-weight:600;color:black;font-family:'Noto Sans Khmer',sans-serif;">
                        កាលបរិច្ឆេទ: <?= htmlspecialchars(fd($order['sale_date'] ?? null)) ?>
                    </div>
                </div>
                <div class="d-flex justify-content-center mb-2">
                    <img src="<?= htmlspecialchars($qrOrderUrl) ?>" alt="QR" class="mb-1 receipt-qr">
                </div>
            </div>

            <!-- Customer -->
            <div class="section-divider"></div>
            <div class="section-title mb-1" style="font-family:'Noto Sans Khmer',sans-serif;">អតិថិជន / Customer</div>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-family:'Noto Sans Khmer',sans-serif;">ឈ្មោះ</div>
                <div class="col-8 value-col"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></div>
            </div>
            <?php if (!empty($order['phone'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-family:'Noto Sans Khmer',sans-serif;">លេខទូរស័ព្ទ</div>
                <div class="col-8 value-col"><?= htmlspecialchars($order['phone']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['customer_location'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:16px;font-family:'Noto Sans Khmer',sans-serif;">ទីតាំង</div>
                <div class="col-8 value-col" style="font-size:15px;font-family:'Noto Sans Khmer',sans-serif;"><?= htmlspecialchars($order['customer_location']) ?></div>
            </div>
            <?php endif; ?>

            <!-- Products table (only when order has items) -->
            <?php if ($items): ?>
            <?php
            $deliveryTotalQty = 0.0;
            foreach ($items as $deliveryItem) {
                $deliveryTotalQty += (float)($deliveryItem['quantity'] ?? 0);
            }
            ?>
            <div class="section-divider"></div>
            <table class="delivery-items-table">
                <thead>
                    <tr>
                        <th style="width:12%;">ល.រ<span class="th-en">No</span></th>
                        <th style="width:58%;">មុខទំនិញ<span class="th-en">Product Name</span></th>
                        <th style="width:30%;">ចំនួន<span class="th-en">Qty</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $deliveryRowNo = 1; foreach ($items as $item): ?>
                    <tr>
                        <td class="tc"><?= $deliveryRowNo++ ?></td>
                        <td class="tl"><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="tc"><?= htmlspecialchars(fq((float)$item['quantity'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="qty-total-label">សរុបចំនួន</td>
                        <td class="qty-total-value"><?= htmlspecialchars(fq($deliveryTotalQty)) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="small mb-0" style="color:black;font-weight:600;">Created: <?= htmlspecialchars($order['created_at'] ?? '') ?></div>
            <div class="label-col" style="font-size:14px;">Powered by : One Night Solution</div>
            <div class="thermal-cut-feed" aria-hidden="true"></div>
        </div>
    </div>
    </div><!-- /.delivery-bundle -->

    <?php /* ════════════ RECEIPT ════════════ */ elseif (!$isInvoice): ?>
    <div class="bulk-receipt-bundle">
    <div class="receipt-card card">
        <div class="card-body p-3">

            <div class="receipt-header-logo">
                <?= make_logo_html($BASE_URL, $defaultLogo) ?>
            </div>
            <div class="receipt-title" style="font-family:'Noto Sans Khmer',sans-serif;">វិក្កយបត្រការបញ្ជាទិញ</div>

            <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                <div class="small">
                    <?php if (!empty($order['team_name'])): ?>
                    <div>
                        <span class="label-col" style="font-size:16px;font-family:'Noto Sans Khmer',sans-serif;">ក្រុម:</span>
                        <span class="value-col" style="font-size:16px;"><?= htmlspecialchars($order['team_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:12px;font-weight:600;margin-top:2px;color:black;font-family:'Noto Sans Khmer',sans-serif;">
                        លេខកូដ: <?= htmlspecialchars($order['order_code']) ?>
                    </div>
                    <div style="font-size:12px;font-weight:600;color:black;font-family:'Noto Sans Khmer',sans-serif;">
                        កាលបរិច្ឆេទ: <?= htmlspecialchars(fd($order['sale_date'] ?? null)) ?>
                    </div>
                </div>
                <div class="d-flex justify-content-center mb-2">
                    <img src="<?= htmlspecialchars($qrOrderUrl) ?>" alt="QR" class="mb-1 receipt-qr">
                </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-title mb-1" style="font-family:'Noto Sans Khmer',sans-serif;">អតិថិជន</div>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-family:'Noto Sans Khmer',sans-serif;">ឈ្មោះ</div>
                <div class="col-8 value-col"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></div>
            </div>
            <?php if (!empty($order['phone'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-family:'Noto Sans Khmer',sans-serif;">លេខទូរស័ព្ទ</div>
                <div class="col-8 value-col"><?= htmlspecialchars($order['phone']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['customer_location'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:16px;font-family:'Noto Sans Khmer',sans-serif;">ទីតាំង</div>
                <div class="col-8 value-col" style="font-size:15px;font-family:'Noto Sans Khmer',sans-serif;"><?= htmlspecialchars($order['customer_location']) ?></div>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="section-title mb-1" style="font-family:'Noto Sans Khmer',sans-serif;">ផលិតផល</div>
            <?php foreach ($items as $item): ?>
            <div class="d-flex justify-content-between small mb-1">
                <div class="value-col flex-grow-1 me-2" style="font-size:16px;font-family:'Noto Sans Khmer',sans-serif;">
                    <?= htmlspecialchars($item['product_name']) ?> x <?= htmlspecialchars(fq((float)$item['quantity'])) ?>
                </div>
                <div class="value-col amount-col" style="font-size:14px;">$<?= number_format((float)$item['line_total'], 2) ?></div>
            </div>
            <?php endforeach; ?>
            <?php foreach ($purchaseItems as $item): ?>
            <div class="d-flex justify-content-between small mb-1">
                <div class="value-col flex-grow-1 me-2">
                    <strong>[PURCHASE]</strong> <?= htmlspecialchars($item['product_name']) ?> x <?= htmlspecialchars(fq((float)$item['quantity'])) ?>
                </div>
                <div class="value-col amount-col text-success">-$<?= number_format((float)$item['line_total'], 2) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (!$items): ?><div class="text-muted small" style="font-family:'Noto Sans Khmer',sans-serif;">គ្មានផលិតផល</div><?php endif; ?>

            <div class="section-divider"></div>
            <div class="section-title mb-1" style="font-family:'Noto Sans Khmer',sans-serif;">ការទូទាត់</div>

            <?php
            $rcptStatusClass = match($order['status']) {
                'paid'    => 'inv-status-paid',
                'partial' => 'inv-status-partial',
                default   => 'inv-status-unpaid',
            };
            ?>
            <div class="stamp-wrap">
                <div class="status-stamp status-stamp-<?= $order['status'] === 'partial' ? 'partial' : ($order['status'] === 'paid' ? 'paid' : 'unpaid') ?>">
                    <?= status_kh($order['status']) ?>
                </div>
            </div>

            <?php if ($discount > 0): ?>
            <div class="pay-row">
                <span class="pay-label">Subtotal</span>
                <span class="pay-val">$<?= number_format($subtotal, 2) ?></span>
            </div>
            <div class="pay-row">
                <span class="pay-label" style="color:#dc3545;font-family:'Noto Sans Khmer',sans-serif;">បញ្ចុះតម្លៃ</span>
                <span class="pay-val" style="color:#dc3545;">-$<?= number_format($discount, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($purchaseTotal > 0): ?>
            <div class="pay-row">
                <span class="pay-label text-success">Purchase From Customer</span>
                <span class="pay-val text-success">-$<?= number_format($purchaseTotal, 2) ?></span>
            </div>
            <?php endif; ?>

            <div class="pay-row">
                <span class="pay-label" style="font-family:'Noto Sans Khmer',sans-serif;">សរុប</span>
                <span class="fw-bold" style="font-size:16px;color:black;">$<?= number_format($totalAmount, 2) ?></span>
            </div>

            <?php if ($payments): ?>
            <div style="border-top:1px dashed #999;margin:.4rem 0 .3rem;"></div>
            <?php foreach ($payments as $p): ?>
            <div class="pay-history-row">
                <span><?= htmlspecialchars(fd($p['payment_date'])) ?>
                    <?php if (!empty($p['payment_method'])): ?>· <em><?= htmlspecialchars($p['payment_method']) ?></em><?php endif; ?>
                    <?php if (!empty($p['paid_note'])): ?>· <?= htmlspecialchars($p['paid_note']) ?><?php endif; ?>
                </span>
                <span class="fw-semibold text-success">+$<?= number_format((float)$p['amount'], 2) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="pay-row" style="margin-top:.35rem;">
                <span class="pay-label" style="font-size:15px;font-family:'Noto Sans Khmer',sans-serif;">បានបង់សរុប</span>
                <span class="pay-val" style="font-size:15px;color:#198754;">$<?= number_format($totalPaid, 2) ?></span>
            </div>
            <div class="pay-row">
                <span class="pay-label" style="font-size:15px;font-family:'Noto Sans Khmer',sans-serif;">ត្រូវ​បង់ ($)</span>
                <span class="pay-val" style="font-size:18px;color:<?= $balanceDue > 0 ? '#dc3545' : '#000000' ?>;">
                    $<?= number_format($balanceDue, 2) ?>
                </span>
            </div>
            <?php if ($balanceDue > 0 && !empty($order['due_date'])): ?>
            <div class="pay-row">
                <span class="pay-label" style="font-size:14px;">Due Date</span>
                <span class="pay-val" style="font-size:14px;color:#dc3545;"><?= htmlspecialchars(fd($order['due_date'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($balanceDue > 0 && !empty($order['collection_assigned_to'])): ?>
            <div class="pay-row">
                <span class="pay-label" style="font-size:14px;">Collection Assigned To</span>
                <span class="pay-val" style="font-size:14px;"><?= htmlspecialchars($order['collection_assigned_to']) ?></span>
            </div>
            <?php endif; ?>
            <div class="pay-row">
                <span class="label-col" style="font-family:'Noto Sans Khmer',sans-serif;">ត្រូវ​បង់ (រៀល)</span>
                <span class="fw-bold" style="font-size:16px;color:black;font-family:'Noto Sans Khmer',sans-serif;">
                    ៛<?= number_format($totalAmount * $exchangeRate, 0) ?>
                </span>
            </div>

            <?php if (!empty($order['payment_method'])): ?>
            <div class="pay-row" style="margin-top:.25rem;">
                <span class="pay-label" style="font-size:14px;font-family:'Noto Sans Khmer',sans-serif;">វិធីទូទាត់</span>
                <span class="pay-val" style="font-size:13px;"><?= htmlspecialchars($order['payment_method']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['paid_note'])): ?>
            <div class="pay-row">
                <span class="pay-label" style="font-size:14px;font-family:'Noto Sans Khmer',sans-serif;">កំណត់ចំណាំ</span>
                <span class="pay-val" style="font-size:12px;font-family:'Noto Sans Khmer',sans-serif;"><?= htmlspecialchars($order['paid_note']) ?></span>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="label-col" style="font-size:11px;font-family:'Noto Sans Khmer',sans-serif;margin-bottom:.3rem;">
                អត្រាប្តូរប្រាក់: 1 USD = <?= number_format($exchangeRate, 0) ?>រៀល
            </div>
            <div class="small mb-0" style="color:black;font-weight:600;">Created: <?= htmlspecialchars($order['created_at'] ?? '') ?></div>
            <div class="label-col" style="font-size:14px;">Powered by : One Night Solution</div>
            <div class="thermal-cut-feed" aria-hidden="true"></div>
        </div>
    </div>
    </div><!-- /.bulk-receipt-bundle -->

    <?php /* ════════════ INVOICE ════════════ */ elseif ($isInvoice): ?>
    <div class="invoice-bundle">
    <div class="invoice-page">
        <div class="inv-accent"></div>
        <div class="inv-top">
            <div class="inv-top-left">
                <div class="inv-brand">
                    <?= make_logo_html($BASE_URL, $invoiceLogo, $logoStyle, 'invoice-logo') ?>
                    <div class="inv-company">
                        <div class="inv-company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="inv-company-info">
                            <?php if ($contactPerson): ?><div>ទំនាក់ទំនង / Contact: <?= htmlspecialchars($contactPerson) ?></div><?php endif; ?>
                            <?php if ($companyAddress): ?><div><?= nl2br(htmlspecialchars($companyAddress)) ?></div><?php endif; ?>
                            <?php if ($companyPhone): ?><div>ទូរស័ព្ទ / Phone: <?= htmlspecialchars($companyPhone) ?></div><?php endif; ?>
                            <?php if ($companyEmail): ?><div>អ៊ីមែល / Email: <?= htmlspecialchars($companyEmail) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="inv-top-right">
                <div class="inv-title-km">វិក្កយបត្រលក់</div>
                <div class="inv-title-en">OFFLINE SALE INVOICE</div>
                <table class="inv-info-tbl">
                    <tr><td><span class="lbl-km">លេខវិក្កយបត្រ</span><span class="lbl-en">Invoice No</span></td><td><?= htmlspecialchars($order['order_code']) ?></td></tr>
                    <tr><td><span class="lbl-km">កាលបរិច្ឆេទ</span><span class="lbl-en">Date</span></td><td><?= htmlspecialchars(fd($order['sale_date'] ?? null)) ?></td></tr>
                    <?php if ($balanceDue > 0 && !empty($order['due_date'])): ?>
                    <tr><td><span class="lbl-km">ថ្ងៃផុតកំណត់</span><span class="lbl-en">Due Date</span></td><td><?= htmlspecialchars(fd($order['due_date'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($balanceDue > 0 && !empty($order['collection_assigned_to'])): ?>
                    <tr><td><span class="lbl-en">Collection Assigned To</span></td><td><?= htmlspecialchars($order['collection_assigned_to']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td><span class="lbl-km">អតិថិជន</span><span class="lbl-en">Customer</span></td><td><?= htmlspecialchars($order['customer_name'] ?: '—') ?></td></tr>
                    <tr><td><span class="lbl-km">ទូរស័ព្ទ</span><span class="lbl-en">Phone</span></td><td><?= htmlspecialchars($order['phone'] ?: '—') ?></td></tr>
                    <tr><td><span class="lbl-km">អាសយដ្ឋាន</span><span class="lbl-en">Address</span></td><td><?= htmlspecialchars($order['customer_location'] ?: '—') ?></td></tr>
                    <?php if (!empty($order['team_name'])): ?>
                    <tr><td><span class="lbl-km">ក្រុម</span><span class="lbl-en">Team</span></td><td><?= htmlspecialchars($order['team_name']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td><span class="lbl-km">ស្ថានភាព</span><span class="lbl-en">Status</span></td><td><?= htmlspecialchars(status_kh($order['status'])) ?></td></tr>
                    <?php if (!empty($order['payment_method'])): ?>
                    <tr><td><span class="lbl-km">វិធីទូទាត់</span><span class="lbl-en">Payment</span></td><td><?= htmlspecialchars($order['payment_method']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <hr class="inv-divider">

        <table class="inv-items">
            <thead>
                <tr>
                    <th class="tl" style="width:7%;">ល.រ<span class="th-en">No</span></th>
                    <th class="tl">ផលិតផល<span class="th-en">Product</span></th>
                    <th style="width:12%;">ចំនួន<span class="th-en">Qty</span></th>
                    <th style="width:16%;">តម្លៃ<span class="th-en">Price</span></th>
                    <th style="width:16%;">សរុប<span class="th-en">Total</span></th>
                </tr>
            </thead>
            <tbody>
                <?php $rowNum = 1; foreach ($items as $item): ?>
                <tr>
                    <td class="tc"><?= $rowNum++ ?></td>
                    <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
                    <td class="tc"><?= fq((float)$item['quantity']) ?></td>
                    <td class="tr">$<?= number_format((float)$item['unit_price'], 2) ?></td>
                    <td class="tr">$<?= number_format((float)$item['line_total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($purchaseItems as $item): ?>
                <tr>
                    <td class="tc"><?= $rowNum++ ?></td>
                    <td>
                        <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                        <div class="line-note">ទិញពីអតិថិជន / Purchase from customer</div>
                    </td>
                    <td class="tc"><?= fq((float)$item['quantity']) ?></td>
                    <td class="tr">-$<?= number_format((float)$item['unit_price'], 2) ?></td>
                    <td class="tr">-$<?= number_format((float)$item['line_total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$items && !$purchaseItems): ?>
                <tr><td colspan="5" class="tc">គ្មានផលិតផល / No products</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="inv-sum-wrap">
            <table class="inv-sum">
                <?php if ($discount > 0 || $purchaseTotal > 0): ?>
                <tr>
                    <td>សរុបរង<span class="lbl-en">Subtotal</span></td>
                    <td>$<?= number_format($subtotal, 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($discount > 0): ?>
                <tr>
                    <td>បញ្ចុះតម្លៃ<span class="lbl-en">Discount</span></td>
                    <td>-$<?= number_format($discount, 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($purchaseTotal > 0): ?>
                <tr>
                    <td>ទិញពីអតិថិជន<span class="lbl-en">Purchase</span></td>
                    <td>-$<?= number_format($purchaseTotal, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="sum-total">
                    <td>សរុបចុងក្រោយ<span class="lbl-en">Grand Total</span></td>
                    <td>
                        $<?= number_format($totalAmount, 2) ?>
                        <span class="khr">៛<?= number_format($totalAmount * $exchangeRate, 0) ?></span>
                    </td>
                </tr>
                <tr>
                    <td>បានទទួល<span class="lbl-en">Received</span></td>
                    <td>$<?= number_format($totalPaid, 2) ?></td>
                </tr>
                <tr>
                    <td>នៅសល់<span class="lbl-en">Balance</span></td>
                    <td>
                        $<?= number_format($balanceDue, 2) ?>
                        <span class="khr">៛<?= number_format($balanceDue * $exchangeRate, 0) ?></span>
                    </td>
                </tr>
            </table>
        </div>
        <div class="inv-rate">អត្រាប្តូរប្រាក់ / Exchange rate: 1 USD = <?= number_format($exchangeRate, 0) ?> រៀល</div>

        <?php if ($payments): ?>
        <div class="inv-section-label">ប្រវត្តិទូទាត់<span class="lbl-en">Payment History</span></div>
        <table class="inv-pay-table">
            <thead>
                <tr>
                    <th>កាលបរិច្ឆេទ<span class="th-en">Date</span></th>
                    <th>វិធីសាស្ត្រ<span class="th-en">Method</span></th>
                    <th>កំណត់សម្គាល់<span class="th-en">Note</span></th>
                    <th class="tr">ចំនួនទឹកប្រាក់<span class="th-en">Amount</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($payments) as $p): ?>
                <tr>
                    <td><?= htmlspecialchars(fd($p['payment_date'])) ?></td>
                    <td><?= htmlspecialchars($p['payment_method'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($p['paid_note'] ?? '—') ?></td>
                    <td class="tr">$<?= number_format((float)$p['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="tr">សរុបបានបង់ / Total Paid</td>
                    <td class="tr">$<?= number_format($totalPaid, 2) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>

        <?php if (!empty($order['paid_note'])): ?>
        <div class="inv-notes">* <?= nl2br(htmlspecialchars($order['paid_note'])) ?></div>
        <?php endif; ?>

        <?php if ($paymentUrl !== ''): ?>
        <div class="inv-footer">
            <div class="inv-footer-qr">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($paymentUrl) ?>" alt="QR">
            </div>
            <div class="inv-footer-text">
                <strong>សម្រាប់ការទូទាត់អនឡាញ ស្កែន QR</strong>
                <span class="lbl-en">Scan to pay online</span>
                <a href="<?= htmlspecialchars($paymentUrl) ?>"><?= htmlspecialchars($paymentUrl) ?></a>
            </div>
        </div>
        <?php endif; ?>

        <div class="inv-signatures">
            <div class="inv-sig">អ្នកលក់<span class="sig-en">Seller</span><div class="inv-sig-line"></div></div>
            <div class="inv-sig">អ្នកទិញ<span class="sig-en">Buyer</span><div class="inv-sig-line"></div></div>
        </div>

        <div class="inv-generated">
            បង្កើតនៅ <?= date('d/m/Y H:i') ?> / Generated <?= date('d/m/Y H:i') ?> — សូមអរគុណ! / Thank you!
        </div>
    </div>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>

