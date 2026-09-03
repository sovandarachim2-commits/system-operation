<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/user_activity_lib.php';

$pdo = get_db_connection();
ensure_order_items_lucky_box_column($pdo);

// Create settings table if not exists and get exchange rate
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT
)");
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_to_khr_rate'");
$stmt->execute();
$exchangeRate = $stmt->fetchColumn() ?: 4100;

$from = $_GET['from'] ?? '';
$order_id = (int)($_GET['id'] ?? 0);
$pendingOrder = $_SESSION['pending_new_order'] ?? null;
$isPendingPreview = $from === 'new' && $order_id <= 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_send']) && $isPendingPreview) {
    if (!$pendingOrder || empty($pendingOrder['items'])) {
        http_response_code(400);
        echo 'Pending order not found.';
        exit;
    }

    $user = current_user();
    if (!$user || (int)$user['id'] !== (int)$pendingOrder['seller_id']) {
        http_response_code(403);
        echo 'Unauthorized order confirmation.';
        exit;
    }

    try {
        $pdo->beginTransaction();

        $order_code = generate_order_code($pdo);
        $insertOrderStmt = $pdo->prepare('INSERT INTO orders (order_code, customer_name, seller_id, phone, location, page_id, delivery_type_id, delivery_cost_id, status, is_cancelled, cancel_note, is_returned, paid_note, return_note, discount, total_amount, telegram_message_id, telegram_last_message_id, updated_by, is_paid, payment_method, payment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insertOrderStmt->execute([
            $order_code,
            $pendingOrder['customer_name'],
            $pendingOrder['seller_id'],
            $pendingOrder['phone'],
            $pendingOrder['location'],
            $pendingOrder['page_id'],
            $pendingOrder['delivery_type_id'],
            $pendingOrder['delivery_cost_id'],
            $pendingOrder['status'],
            0,
            null,
            0,
            $pendingOrder['paid_note'],
            null,
            $pendingOrder['discount'],
            $pendingOrder['total_amount'],
            null,
            null,
            null,
            ($pendingOrder['status'] === 'paid') ? 1 : 0,
            $pendingOrder['payment_method'],
            $pendingOrder['payment_date'] ?? null,
        ]);

        $order_id = (int)$pdo->lastInsertId();
        if ($order_id <= 0) {
            $checkStmt = $pdo->prepare('SELECT id FROM orders WHERE order_code = ? ORDER BY id DESC LIMIT 1');
            $checkStmt->execute([$order_code]);
            $foundOrderId = (int)($checkStmt->fetchColumn() ?: 0);
            if ($foundOrderId <= 0) {
                throw new Exception('Failed to get valid order ID after insert.');
            }
            $order_id = $foundOrderId;
        }

        $insertItemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_cost, line_total, is_lucky_box) VALUES (?,?,?,?,?,?)');
        foreach ($pendingOrder['items'] as $item) {
            $insertItemStmt->execute([
                $order_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_cost'],
                $item['line_total'],
                !empty($item['is_lucky_box']) ? 1 : 0,
            ]);
        }

        $pdo->commit();
        unset($_SESSION['pending_new_order']);

        $det = user_activity_seller_order_log_details([
            'order_id' => $order_id,
            'code' => $order_code,
            'customer' => $pendingOrder['customer_name'] ?? '',
            'phone' => $pendingOrder['phone'] ?? '',
            'status' => $pendingOrder['status'] ?? '',
            'total' => $pendingOrder['total_amount'] ?? '',
        ], $pendingOrder['items'] ?? []);
        user_activity_log_module_mutation($user, 'seller', 'create', __FILE__, $det !== '' ? $det : 'order ' . $order_code . ' (id ' . $order_id . ')');

        $tgResult = send_order_to_telegram($pdo, $order_id);
        if (!empty($tgResult['ok'])) {
            $_SESSION['order_flash_success'] = 'Order created and sent to Telegram.';
        } else {
            $_SESSION['order_flash_warning'] = 'Order saved, but Telegram failed: ' . ($tgResult['error'] ?? 'unknown error')
                . ' (group ' . ($tgResult['chat_id'] ?? '?')
                . (isset($tgResult['thread_id']) && $tgResult['thread_id'] !== null ? ', topic ' . $tgResult['thread_id'] : '')
                . ')';
        }
        header('Location: seller/orders.php?created=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo 'Failed to confirm order: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

if ($isPendingPreview) {
    if (!$pendingOrder || empty($pendingOrder['items'])) {
        http_response_code(400);
        echo 'Pending order not found.';
        exit;
    }

    $sellerStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $sellerStmt->execute([(int)$pendingOrder['seller_id']]);
    $sellerName = (string)($sellerStmt->fetchColumn() ?: '');

    $pageName = '';
    if (!empty($pendingOrder['page_id'])) {
        $pageStmt = $pdo->prepare('SELECT name FROM pages WHERE id = ?');
        $pageStmt->execute([(int)$pendingOrder['page_id']]);
        $pageName = (string)($pageStmt->fetchColumn() ?: '');
    }

    $deliveryTypeName = '';
    if (!empty($pendingOrder['delivery_type_id'])) {
        $typeStmt = $pdo->prepare('SELECT name FROM delivery_types WHERE id = ?');
        $typeStmt->execute([(int)$pendingOrder['delivery_type_id']]);
        $deliveryTypeName = (string)($typeStmt->fetchColumn() ?: '');
    }

    $deliveryCostLabel = '';
    $deliveryCostAmount = null;
    if (!empty($pendingOrder['delivery_cost_id'])) {
        $costStmt = $pdo->prepare('SELECT label, amount FROM delivery_costs WHERE id = ?');
        $costStmt->execute([(int)$pendingOrder['delivery_cost_id']]);
        $costRow = $costStmt->fetch();
        if ($costRow) {
            $deliveryCostLabel = (string)($costRow['label'] ?? '');
            $deliveryCostAmount = $costRow['amount'];
        }
    }

    $order = [
        'order_code' => 'Pending confirmation',
        'seller_name' => $sellerName,
        'customer_name' => $pendingOrder['customer_name'],
        'phone' => $pendingOrder['phone'],
        'location' => $pendingOrder['location'],
        'page_name' => $pageName,
        'delivery_type_name' => $deliveryTypeName,
        'delivery_cost_label' => $deliveryCostLabel,
        'delivery_cost_amount' => $deliveryCostAmount,
        'status' => $pendingOrder['status'],
        'payment_method' => $pendingOrder['payment_method'] ?? '',
        'paid_note' => $pendingOrder['paid_note'] ?? '',
        'discount' => $pendingOrder['discount'],
        'total_amount' => $pendingOrder['total_amount'],
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $items = [];
    foreach ($pendingOrder['items'] as $item) {
        $items[] = [
            'product_name' => $item['name'],
            'quantity' => $item['quantity'],
            'line_total' => $item['line_total'],
            'is_lucky_box' => !empty($item['is_lucky_box']) ? 1 : 0,
        ];
    }
} else {
    if ($order_id <= 0) {
        http_response_code(400);
        echo 'Invalid order.';
        exit;
    }

    $stmt = $pdo->prepare('SELECT o.*, u.name AS seller_name, p.name AS page_name, dt.name AS delivery_type_name, dc.label AS delivery_cost_label, dc.amount AS delivery_cost_amount
                           FROM orders o
                           JOIN users u ON o.seller_id = u.id
                           LEFT JOIN pages p ON o.page_id = p.id
                           LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
                           LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
                           WHERE o.id = ?');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }

    $itemsStmt = $pdo->prepare('SELECT oi.*, pr.name AS product_name FROM order_items oi JOIN products pr ON oi.product_id = pr.id WHERE oi.order_id = ?');
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll();
}

$receiptDisplayItems = receipt_normalize_items_for_display($items, (string)($order['order_code'] ?? ''));

$luckyInfoCardItem = null;
foreach ($receiptDisplayItems as $_luckyIt) {
    if (($_luckyIt['display_kind'] ?? '') === 'lucky_merged') {
        $luckyInfoCardItem = $_luckyIt;
        break;
    }
}

$logo = get_default_logo($pdo);

// When seller confirms from an existing order receipt, only send Telegram
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_send']) && !$isPendingPreview) {
    $tgResult = send_order_to_telegram($pdo, $order_id);
    if (!empty($tgResult['ok'])) {
        $_SESSION['order_flash_success'] = 'Order sent to Telegram.';
    } else {
        $_SESSION['order_flash_warning'] = 'Telegram failed: ' . ($tgResult['error'] ?? 'unknown error');
    }
    header('Location: seller/orders.php?created=1');
    exit;
}

// Simple QR: encode the order_code itself. You could change this to a URL if needed.
$qrText = $order['order_code'];

// Use a public QR API to generate PNG
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($qrText);

require_once __DIR__ . '/config.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= htmlspecialchars($order['order_code']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <style>
        :root {
            --receipt-font-khmer: "Khmer OS Battambang", "Khmer OS Siemreap", "Khmer", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        body {
            background: #e5e7eb;
            font-family: var(--receipt-font-khmer);
        }
        .receipt-card,
        .receipt-card *,
        .receipt-lucky-outer-card,
        .receipt-lucky-outer-card * {
            font-family: var(--receipt-font-khmer) !important;
        }
        .receipt-card {
            max-width: 360px;
            margin: 1.5rem auto;
            border-radius: 1.25rem;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
            background: #ffffff;
            border: 1px solid #e5e7eb;
        }
        .receipt-header-logo {
            text-align: center;
            margin-bottom: 0.5rem;
        }
        .receipt-header-logo img {
            max-height: 90px;
            max-width: 150px;
            object-fit: contain;
        }
        .receipt-title {
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 0.75rem;
            color: #000000ff;
        }
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #000000ff;
            margin-bottom: 0.25rem;
        }
        .receipt-qr {
            width: 72px;
            height: 72px;
            margin-left: -112px;
        }

        .amount-col {
            text-align: left;
            min-width: 80px;
            margin-left: -20px;
        }
        .section-divider {
            border-top: 1px solid black;
            margin: 0.6rem 0 0.6rem 0;
        }
        .receipt-lucky-outer-card {
            border-radius: 0.75rem;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.1);
            margin-top: 0.75rem;
        }
        .receipt-lucky-outer-card .card-body {
            padding: 0.75rem 0.85rem;
        }
        .receipt-lucky-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            font-family: var(--receipt-font-khmer);
        }
        .receipt-lucky-field {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .receipt-lucky-detail-label {
            display: block;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 12px;
            font-weight: 700;
        }
        .receipt-lucky-field-box {
            border: 1px solid #000000;
            border-radius: 6px;
            padding: 0.5rem 0.6rem;
            background: #f8f9fa;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.45;
            color: #000000;
            word-break: break-word;
        }
        .receipt-lucky-field-box--multi {
            padding: 0.45rem 0.6rem;
        }
        .receipt-lucky-qty-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.5rem;
            padding-bottom: 0.35rem;
            margin-bottom: 0.35rem;
            border-bottom: 1px solid #000000;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #000000;
        }
        .receipt-lucky-qty-line {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.65rem;
            color: #000000;
            font-weight: 700;
            font-size: 14px;
            line-height: 1.45;
        }
        .receipt-lucky-qty-name {
            flex: 1 1 auto;
            min-width: 0;
            word-break: break-word;
        }
        .receipt-lucky-qty-num {
            flex: 0 0 auto;
            text-align: right;
            white-space: nowrap;
        }
        .receipt-lucky-qty-line + .receipt-lucky-qty-line {
            margin-top: 0.35rem;
            padding-top: 0.35rem;
            border-top: 1px solid #000000;
        }
        .label-col {
            color: #000000ff;
            font-weight: 600;
            font-size: 18px;
        }
        .value-col {
            color: #000000ff;
            font-weight: 600;
            font-size: 18px;
        }
        .btn-pill {
            border-radius: 999px;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
        }
        .thermal-cut-feed {
            display: none;
        }
        @media print {
            body {
                background: #ffffff;
                font-family: var(--receipt-font-khmer);
            }
            body * {
                visibility: hidden;
            }
            .receipt-capture-stack, .receipt-capture-stack * {
                visibility: visible;
            }
            .receipt-card, .receipt-card * {
                visibility: visible;
                font-family: var(--receipt-font-khmer) !important;
            }
            .receipt-lucky-outer-card, .receipt-lucky-outer-card * {
                visibility: visible;
            }
            .receipt-capture-stack {
                /* static flow so page breaks work (absolute breaks lucky card onto same â€œsurfaceâ€) */
                position: static;
                left: auto;
                top: auto;
                max-width: 100%;
                margin: 0 auto;
            }
            .receipt-card {
                box-shadow: none;
                border: none;
                margin: 0 auto;
                page-break-inside: avoid;
            }
            .receipt-lucky-outer-card {
                box-shadow: none;
                break-before: page;
                page-break-before: always;
                margin-top: 0;
            }
            .receipt-lucky-field-box,
            .receipt-lucky-field-box--multi {
                background: #ffffff;
            }
            .thermal-cut-feed {
                display: block;
                height: 18mm;
            }
        }
    </style>
    <?php if ($from === 'reprint'): ?>
    <style>
        form.receipt-actions-form {
            display: none !important;
        }
    </style>
    <?php endif; ?>
</head>
<body>
<div class="container-fluid py-3">
    <div id="receiptCaptureArea" class="receipt-capture-stack">
    <div class="receipt-card card">
        <div class="card-body p-3" id="receiptRoot">
            <div id="receiptContent">
            <div class="receipt-header-logo">
                <?php if ($logo): ?>
                    <img src="<?= htmlspecialchars(uploaded_file_url($logo['file_path'], 'logos')) ?>" alt="Logo">
                <?php endif; ?>
            </div>
            <div class="receipt-title">Order Receipt</div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="small">
                    <div>
                        <span class="label-col" style="font-size:16px;">Seller:</span>
                        <span class="value-col" style="font-size:16px;"><?= htmlspecialchars($order['seller_name']) ?></span>
                    </div>
                    <div style="font-weight:600; font-size:14px; margin-top:2px;color:black">
                        Code: <?= htmlspecialchars($order['order_code']) ?>
                    </div>
                </div>
            
                <div class="d-flex align-items-center">
                    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" class="receipt-qr">
                </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-title mb-1">Customer</div>
            <div class="row small mb-1">
                <div class="col-4 label-col">Name</div>
                <div class="col-8 value-col"><?= htmlspecialchars($order['customer_name']) ?></div>
            </div>
            <?php if (!empty($order['phone'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col">Phone</div>
                <div class="col-8 value-col"><?= htmlspecialchars($order['phone']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['location'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:16px; font-family: var(--receipt-font-khmer);">&#x1791;&#x17B8;&#x178F;&#x17B6;&#x17C6;&#x1784;</div>
                <div class="col-8 value-col" style="font-size:16px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($order['location']) ?></div>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="section-title mb-1">Products</div>
            <?php foreach ($receiptDisplayItems as $item): ?>
                <?php if (($item['display_kind'] ?? '') === 'lucky_merged'): ?>
                <div class="d-flex justify-content-between small mb-1 align-items-start">
                    <div class="value-col flex-grow-1 me-2" style="font-size:16px;">Lucky box x <?= (int)$item['quantity'] ?></div>
                    <div class="value-col amount-col" style="font-size:14px; align-self: flex-start;">$<?= number_format((float)$item['line_total'], 2) ?></div>
                </div>
                <?php else: ?>
                <div class="d-flex justify-content-between small mb-1">
                    <div class="value-col flex-grow-1 me-2" style="font-size:16px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($item['product_name']) ?> x <?= (int)$item['quantity'] ?></div>
                    <div class="value-col amount-col" style="font-size:14px;">$<?= number_format((float)$item['line_total'], 2) ?></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="section-divider"></div>
            <div class="section-title mb-1">Delivery</div>
            <?php if (!empty($order['delivery_type_name'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:14px; font-family: var(--receipt-font-khmer);">&#x178A;&#x17B9;&#x1780;&#x178A;&#x17C4;&#x1799;</div>
                <div class="col-8 value-col" style="font-size:14px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($order['delivery_type_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php
            $costLabel = $order['delivery_cost_label'] ?? '';
            if ($costLabel === '' && isset($order['delivery_cost_amount']) && $order['delivery_cost_amount'] !== null) {
                $costLabel = '$' . number_format($order['delivery_cost_amount'], 2);
            }
            ?>
            <?php if ($costLabel !== ''): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:14px; font-family: var(--receipt-font-khmer);">&#x178F;&#x1798;&#x17D2;&#x179B;&#x17C3;&#x178A;&#x17B9;&#x1780;</div>
                <div class="col-8 value-col" style="font-size:14px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($costLabel) ?></div>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:18px;">Status</div>
                <div class="col-8 value-col"><?= strtoupper($order['status']) ?></div>
            </div>
            <?php if (!empty($order['payment_method'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:15px;">Payment</div>
                <div class="col-8 value-col" style="font-size:14px;"><?= htmlspecialchars($order['payment_method']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($order['status'] === 'paid' && !empty($order['paid_note'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col" style="font-size:15px;">Note</div>
                <div class="col-8 value-col" style="font-size:12px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($order['paid_note']) ?></div>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <div class="section-title mb-1">Summary</div>
            <?php if ((float)$order['discount'] > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="label-col">Discount</div>
                <div class="value-col amount-col">$<?= number_format((float)$order['discount'], 2) ?></div>
            </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="label-col">Total</div>
                <div class="fw-bold amount-col" style="font-size:20px; color:black;">$<?= number_format($order['total_amount'], 2) ?></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="label-col" style="font-family: var(--receipt-font-khmer);">&#x179B;&#x17BB;&#x1799;&#x1781;&#x17D2;&#x1798;&#x17C2;&#x179A;</div>
                <div class="fw-bold amount-col" style="font-size:20px; color:black;font-family: var(--receipt-font-khmer);">&#x17DB;<?= number_format($order['total_amount'] * $exchangeRate, 0) ?></div>
            </div>
                <div class="label-col" style="font-size:14px; font-family: var(--receipt-font-khmer);">Exchange Rate: 1 USD = <?= number_format($exchangeRate, 0) ?>&#x179A;&#x17C0;&#x179B;</div>
                <div class="section-divider"></div>
                <?php if (!empty($order['page_name'])): ?>
                    <div><span class="label-col"style="font-size:16px;">Page:</span> <span class="value-col" style="font-size:14px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($order['page_name']) ?></span></div>
                <?php endif; ?>
                <div class="small mb-0" style="color:black; font-weight: 600;">Created: <?= htmlspecialchars($order['created_at']) ?></div>
                <div class="label-col"style="font-size:14px;">Powered by : One Night Solution</div>
                <div class="thermal-cut-feed" aria-hidden="true"></div>
            </div><!-- /#receiptContent -->
        </div>
    </div>

    <?php if ($luckyInfoCardItem): ?>
    <div class="receipt-lucky-outer-card card border-0">
        <div class="card-body receipt-lucky-form">
            <div class="receipt-lucky-field">
                <span class="receipt-lucky-detail-label">Order code</span>
                <div class="receipt-lucky-field-box"><?= htmlspecialchars($luckyInfoCardItem['lucky_detail_code'] ?? '') ?></div>
            </div>
            <?php if (!empty($luckyInfoCardItem['lucky_product_lines'])): ?>
            <div class="receipt-lucky-field">
                <span class="receipt-lucky-detail-label">Lucky box product</span>
                <div class="receipt-lucky-field-box receipt-lucky-field-box--multi">
                    <div class="receipt-lucky-qty-head">
                        <span>Name</span>
                        <span>QTY</span>
                    </div>
                    <?php foreach ($luckyInfoCardItem['lucky_product_lines'] as $pl): ?>
                    <div class="receipt-lucky-qty-line">
                        <span class="receipt-lucky-qty-name"><?= htmlspecialchars($pl['product_name'] ?? '') ?></span>
                        <span class="receipt-lucky-qty-num"><?= (int)($pl['quantity'] ?? 0) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif (!empty($luckyInfoCardItem['lucky_detail_names'])): ?>
            <div class="receipt-lucky-field">
                <span class="receipt-lucky-detail-label">Lucky box product</span>
                <div class="receipt-lucky-field-box"><?= htmlspecialchars($luckyInfoCardItem['lucky_detail_names']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /#receiptCaptureArea -->

    <form method="post" class="d-flex justify-content-center gap-2 mt-3 mb-2 flex-wrap receipt-actions-form">
        <button type="button" class="btn btn-light btn-pill" onclick="(function(){ <?php if ($isPendingPreview): ?>window.location.href = 'seller/order_new.php?clear_preview=1';<?php else: ?>if (document.referrer) { window.location.href = document.referrer; } else { window.history.back(); }<?php endif; ?> })();">Back</button>
        <button type="button" class="btn btn-primary btn-pill" id="copyReceipt">Copy receipt</button>
        <button type="button" class="btn btn-outline-primary btn-pill" id="saveCard">Save card</button>
        <?php if ($from === 'new'): ?>
            <button type="submit" name="confirm_send" value="1" class="btn btn-success btn-pill">Confirm</button>
        <?php endif; ?>
    </form>
</div>
<script>
(function(){
    const btn = document.getElementById('copyReceipt');
    const saveBtn = document.getElementById('saveCard');
    if (!btn) return;

    btn.addEventListener('click', function(){
        const lines = [];
        lines.push('ORDER RECEIPT');
        lines.push('Code: <?= addslashes($order['order_code']) ?>');
        lines.push('Seller: <?= addslashes($order['seller_name']) ?>');
        <?php if (!empty($order['page_name'])): ?>
        lines.push('Page: <?= addslashes($order['page_name']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('Customer: <?= addslashes($order['customer_name']) ?>');
        <?php if (!empty($order['phone'])): ?>
        lines.push('Phone: <?= addslashes($order['phone']) ?>');
        <?php endif; ?>
        <?php if (!empty($order['location'])): ?>
        lines.push('Location: <?= addslashes($order['location']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('Products:');
        <?php foreach ($receiptDisplayItems as $item): ?>
        <?php if (($item['display_kind'] ?? '') === 'lucky_merged'): ?>
        lines.push('- Lucky box x <?= (int)$item['quantity'] ?> = $<?= number_format((float)$item['line_total'], 2) ?>');
        <?php else: ?>
        lines.push('- <?= addslashes($item['product_name']) ?> x <?= (int)$item['quantity'] ?> = $<?= number_format((float)$item['line_total'], 2) ?>');
        <?php endif; ?>
        <?php endforeach; ?>
        lines.push('');
        <?php if (!empty($order['delivery_type_name'])): ?>
        lines.push('Delivery Type: <?= addslashes($order['delivery_type_name']) ?>');
        <?php endif; ?>
        <?php if (!empty($order['payment_method'])): ?>
        lines.push('Payment Method: <?= addslashes($order['payment_method']) ?>');
        <?php endif; ?>
        <?php if (!empty($order['delivery_cost_label'])): ?>
        lines.push('Delivery Cost: <?= addslashes($order['delivery_cost_label']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('Status: <?= strtoupper(addslashes($order['status'])) ?>');
        <?php if ($order['status'] === 'paid' && !empty($order['paid_note'])): ?>
        lines.push('Note: <?= addslashes($order['paid_note']) ?>');
        <?php endif; ?>
        <?php if ((float)$order['discount'] > 0): ?>
        lines.push('Discount: $<?= number_format((float)$order['discount'], 2) ?>');
        <?php endif; ?>
        lines.push('Total: $<?= number_format($order['total_amount'], 2) ?>');
        lines.push('Total in Riel: &#x17DB;<?= number_format($order['total_amount'] * $exchangeRate, 0) ?>');
        lines.push('Created: <?= addslashes($order['created_at']) ?>');

        const text = lines.join('\n');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function(){
                alert('Receipt copied to clipboard');
            }, function(){
                alert('Unable to copy receipt');
            });
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch(e) {}
            document.body.removeChild(ta);
            alert('Receipt copied to clipboard');
        }
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', function(){
            const card = document.getElementById('receiptCaptureArea');
            if (!card || typeof html2canvas === 'undefined') {
                alert('Unable to capture card image.');
                return;
            }
            html2canvas(card, {scale: 2, useCORS: true}).then(function(canvas){
                canvas.toBlob(function(blob){
                    if (!blob) {
                        alert('Unable to create image.');
                        return;
                    }
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = '<?= addslashes($order['order_code']) ?>.png';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(link.href);
                });
            });
        });
    }
})();
</script>
<?php if ($from === 'reprint'): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 300);
});
</script>
<?php endif; ?>
</body>
</html>
