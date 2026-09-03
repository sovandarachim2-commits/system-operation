<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'print_orders.view');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../user_activity_lib.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../stock_print_lib.php';

$pdo  = get_db_connection();
ensure_order_items_lucky_box_column($pdo);

// EOD finalized guard
function isEodFinalized($pdo) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eod_stock_reports WHERE report_date = ? AND status = 'finalized'");
    $stmt->execute([$yesterday]);
    return (int)$stmt->fetchColumn() > 0;
}

// Create settings table if not exists and get exchange rate
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT
)");
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_to_khr_rate'");
$stmt->execute();
$exchangeRate = $stmt->fetchColumn() ?: 4100;

$user = current_user();

$idsParam = $_GET['ids'] ?? '';
$idsRaw   = array_filter(array_map('trim', explode(',', $idsParam)), 'strlen');
$orderIds = [];
foreach ($idsRaw as $id) {
    $id = (int)$id;
    if ($id > 0) {
        $orderIds[] = $id;
    }
}
$orderIds = array_values(array_unique($orderIds));

if (!$orderIds) {
    header('Location: print_orders.php');
    exit;
}

// Block bulk printing if yesterday EOD is not finalized
if (!isEodFinalized($pdo)) {
    include __DIR__ . '/../layout/header.php';
    ?>
    <div id="eod-top-alert" class="alert alert-danger shadow position-fixed start-50 translate-middle-x mt-3" style="top: 0; z-index: 1080; min-width: 320px; max-width: 90vw;">
        <div class="fw-bold">Cannot Print Orders</div>
        <div>End of Day (EOD) is not finalized for yesterday. Please finalize EOD before printing.</div>
    </div>
    <script>
    (function(){
        var el = document.getElementById('eod-top-alert');
        if (el) {
            setTimeout(function(){ el.style.display = 'none'; }, 5000);
        }
    })();
    </script>
    <div class="d-flex flex-column h-100">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h1 class="h3 mb-1 text-danger">
                    <i class="bi bi-exclamation-octagon-fill me-2"></i>
                    Cannot Print Orders
                </h1>
                <p class="text-muted mb-0">End of Day (EOD) is not finalized for yesterday. Please finalize EOD before printing.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-danger shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-x-circle-fill me-2"></i>
                            EOD Not Finalized
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger" role="alert">
                            Printing is locked until the EOD stock report for yesterday is finalized.
                        </div>
                        <a class="btn btn-outline-primary" href="../admin/eod_eom_stock_reports.php?report_type=eod">Go to EOD Reports</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Next steps
                        </h6>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0 ps-3">
                            <li>Open EOD Stock Reports.</li>
                            <li>Review and finalize yesterday's report.</li>
                            <li>Return here to print orders.</li>
                        </ol>
                    </div>
                    <div class="card-footer">
                        <a href="print_orders.php" class="btn btn-primary w-100">
                            <i class="bi bi-arrow-left me-2"></i>
                            Back to Order Selection
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../layout/footer.php';
    exit;
}

// Determine which orders are newly printed by this cashier
$stmtCheck = $pdo->prepare('SELECT id FROM print_jobs WHERE order_id = ? AND cashier_id = ? LIMIT 1');
$stmtIns   = $pdo->prepare('INSERT INTO print_jobs (order_id, cashier_id) VALUES (?, ?)');
$newPrintOrderIds = [];

foreach ($orderIds as $oid) {
    $stmtCheck->execute([$oid, $user['id']]);
    if (!$stmtCheck->fetch()) {
        $newPrintOrderIds[] = (int)$oid;
    }
}

// Strict flow: reduce stock first, then mark print_jobs.
if (!empty($newPrintOrderIds)) {
    $stockCheck = stock_print_check_orders($pdo, $newPrintOrderIds);
    if (!$stockCheck['can_print']) {
        $_SESSION['print_orders_stock_error'] = [
            'title' => 'Cannot bulk print',
            'message' => 'Insufficient stock detected in ' . ($stockCheck['location']['location_name'] ?? 'Default Location') . '.',
            'items' => $stockCheck['insufficient_items'] ?? [],
            'order_ids' => $newPrintOrderIds,
        ];
        header('Location: print_orders.php');
        exit;
    }

    $stockReductionResult = stock_print_reduce_orders($pdo, $newPrintOrderIds, $user);
    if (!$stockReductionResult['success']) {
        $_SESSION['print_orders_stock_error'] = [
            'title' => 'Cannot print orders',
            'message' => 'Stock reduction failed, so printing is blocked.',
            'errors' => $stockReductionResult['errors'] ?? [],
            'order_ids' => $newPrintOrderIds,
        ];
        header('Location: print_orders.php');
        exit;
    }

    foreach ($newPrintOrderIds as $oid) {
        $stmtIns->execute([$oid, $user['id']]);
    }
    user_activity_log_module_mutation($user, 'cashier', 'create', __FILE__, 'print_jobs ' . implode(',', $newPrintOrderIds));
}

// Load orders (exclude cancelled)
$placeholders = implode(',', array_fill(0, count($orderIds), '?'));
$sql = 'SELECT o.*, u.name AS seller_name, p.name AS page_name, dt.name AS delivery_type_name, dc.label AS delivery_cost_label, dc.amount AS delivery_cost_amount
        FROM orders o
        JOIN users u ON o.seller_id = u.id
        LEFT JOIN pages p ON o.page_id = p.id
        LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
        LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
        WHERE o.is_cancelled = 0 AND o.id IN (' . $placeholders . ')
        ORDER BY o.created_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($orderIds);
$orders = $stmt->fetchAll();

// Preload items per order
$itemsByOrder = [];
if ($orders) {
    $itemsStmt = $pdo->prepare('SELECT oi.*, pr.name AS product_name FROM order_items oi JOIN products pr ON oi.product_id = pr.id WHERE oi.order_id = ?');
    foreach ($orders as $o) {
        $itemsStmt->execute([$o['id']]);
        $itemsByOrder[$o['id']] = $itemsStmt->fetchAll();
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Print Receipts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .bulk-receipt-bundle {
            page-break-after: always;
            max-width: 360px;
            margin: 1.5rem auto;
        }
        .bulk-receipt-bundle:last-of-type {
            page-break-after: auto;
        }
        .receipt-card {
            box-sizing: border-box;
            overflow: hidden;
            padding: 0;
            max-width: 360px;
            margin: 0 auto;
            border-radius: 0;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
            background: #ffffff;
            border: 1px solid #e5e7eb;
        }
        .receipt-lucky-outer-card {
            border-radius: 0.75rem;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.1);
            margin-top: 0.75rem;
            max-width: 360px;
            margin-left: auto;
            margin-right: auto;
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
        .receipt-header-logo {
            text-align: center;
            margin-bottom: 0.5rem;
        }
        .receipt-header-logo img {
            max-height: 60px;
            max-width: 100px;
            object-fit: contain;
        }
        .receipt-title {
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 0.75rem;
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
            margin-left:-115px;
        }
        .amount-col {
            text-align: left;
            min-width: 80px;
            margin-left: -20px;
        }
        .section-divider {
            border-bottom: 1px solid black;
            border-top: 1px dashed #e5e7eb;
            margin: 0.6rem 0 0.6rem 0;
        }
        .label-col {
            color: #000000ff;
            font-weight: 600;
            font-size: 17px;
        }
        .value-col {
            color: #000000ff;
            font-weight: 600;
            font-size: 15px;
        }
        .thermal-cut-feed {
            display: none;
        }
        /*@media print {*/
        /*    body {*/
        /*        background: #ffffff;*/
        /*    }*/
        /*    .no-print {*/
        /*        display: none !important;*/
        /*    }*/
        /*}*/
        @media print {
            body, html {
                margin: 0;
                padding: 0;
                background: #fff;
                font-family: var(--receipt-font-khmer);
            }
            .no-print {
                display: none !important;
            }
            /* Main receipt page 1; lucky detail card starts on a new page (page 2 of this order). */
            .receipt-card {
                page-break-inside: avoid;
                font-family: var(--receipt-font-khmer) !important;
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
        .card-body,
        .receipt-card,
        .section-title,
        .value-col,
        .label-col,
        .amount-col {
            text-align: left !important;
        }
        
        .text-center,
        .justify-content-between,
        .justify-content-center,
        .text-end {
            text-align: left !important;
        }

    </style>
</head>
<body>
<div class="container-fluid py-3">
    <div class="no-print mb-3 d-flex justify-content-between align-items-center">
        <h1 class="h5 mb-0">Bulk Print Receipts</h1>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.close();">Close</button>
    </div>
    <?php if (!$orders): ?>
        <div class="alert alert-warning">No orders found to print.</div>
    <?php else: ?>
        <?php foreach ($orders as $order): ?>
            <?php
            $items = $itemsByOrder[$order['id']] ?? [];
            $receiptDisplayItems = receipt_normalize_items_for_display($items, (string)($order['order_code'] ?? ''));
            $luckyInfoCardItem = null;
            foreach ($receiptDisplayItems as $_bulkIt) {
                if (($_bulkIt['display_kind'] ?? '') === 'lucky_merged') {
                    $luckyInfoCardItem = $_bulkIt;
                    break;
                }
            }
            $qrText = $order['order_code'];
            $qrUrl  = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($qrText);
            $logo   = get_default_logo($pdo);
            $costLabel = $order['delivery_cost_label'] ?? '';
            if ($costLabel === '' && isset($order['delivery_cost_amount']) && $order['delivery_cost_amount'] !== null) {
                $costLabel = '$' . number_format($order['delivery_cost_amount'], 2);
            }
            ?>
            <div class="bulk-receipt-bundle">
            <div class="receipt-card card">
                <div class="card-body p-3">
                    <div class="receipt-header-logo">
                        <?php if ($logo): ?>
                            <img src="<?= htmlspecialchars(uploaded_file_url($logo['file_path'], 'logos')) ?>" alt="Logo">
                        <?php endif; ?>
                    </div>
                    <div class="receipt-title">Order Receipt</div>
                        <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                            <div class="small">
                                <div>
                                    <span class="label-col" style="font-size:16px;">Seller:</span>
                                    <span class="value-col" style="font-size:16px;"><?= htmlspecialchars($order['seller_name']) ?></span>
                                </div>
                                <div style="font-size:12px; font-weight:600; margin-top:2px;color:black">
                                    Code: <?= htmlspecialchars($order['order_code']) ?>
                                </div>
                            </div>
                        
                            <div class="d-flex justify-content-center mb-2">
                                <div class="d-flex flex-column align-items-center">
                                    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" class="mb-1 receipt-qr">
                                    <!-- removed text under QR -->
                                </div>
                            </div>
                        </div>



                    <div class="section-divider"></div>
                    <div class="section-title mb-1">Customer</div>
                    <div class="row small mb-1">
                        <div class="col-4 label-col" >Name</div>
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
                        <div class="col-8 value-col" style="font-size:15px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($order['location']) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="section-divider"></div>
                    <div class="section-title mb-1">Products</div>
                    <?php foreach ($receiptDisplayItems as $item): ?>
                        <?php if (($item['display_kind'] ?? '') === 'lucky_merged'): ?>
                        <div class="d-flex justify-content-between small mb-1 align-items-start">
                            <div class="value-col flex-grow-1 me-2" style="font-size:16px;">Lucky box x <?= (int)$item['quantity'] ?></div>
                            <div class="value-col amount-col" style="font-size:14px;">$<?= number_format((float)$item['line_total'], 2) ?></div>
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
                    <?php if ($costLabel !== ''): ?>
                    <div class="row small mb-1">
                        <div class="col-4 label-col" style="font-size:14px; font-family: var(--receipt-font-khmer);">&#x178F;&#x1798;&#x17D2;&#x179B;&#x17C3;&#x178A;&#x17B9;&#x1780;</div>
                        <div class="col-8 value-col" style="font-size:14px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($costLabel) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="section-divider"></div>
                    <!--<div class="section-title mb-1" style="font-size:12px;">Status</div>-->
                    <div class="row small mb-1">
                        <div class="col-4 label-col" style="font-size:14px;">Status</div>
                        <div class="col-8 value-col" ><?= strtoupper($order['status']) ?></div>
                    </div>
                    <?php if (!empty($order['payment_method'])): ?>
                    <div class="row small mb-1">
                        <div class="col-4 label-col" style="font-size:14px;">Payment</div>
                        <div class="col-8 value-col" style="font-size:13px;"><?= htmlspecialchars($order['payment_method']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['status'] === 'paid' && !empty($order['paid_note'])): ?>
                    <div class="row small mb-1">
                        <div class="col-4 label-col" style="font-size:14px">Note</div>
                        <div class="col-8 value-col" style="font-size:12px; font-family: var(--receipt-font-khmer);"><?= htmlspecialchars($order['paid_note']) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="section-divider"></div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="label-col">Total</div>
                        <div class="fw-bold amount-col" style="font-size:20px; color:black;">
                            $<?= number_format($order['total_amount'], 2) ?>
                    </div>
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

            </div><!-- /.bulk-receipt-bundle -->
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
window.addEventListener('load', function () {
    window.print();
});
</script>
</body>
</html>
