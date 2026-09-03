<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['seller', 'admin'], 'receipts.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

// Get one receipt ID or a comma-separated list of selected receipt IDs.
$receipt_order_ids = [];
if (!empty($_GET['ids'])) {
    $receipt_order_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$_GET['ids'])))));
} else {
    $singleId = (int)($_GET['id'] ?? 0);
    if ($singleId > 0) {
        $receipt_order_ids[] = $singleId;
    }
}
$printType = strtolower(trim((string)($_GET['type'] ?? 'receipt')));
$printTitles = [
    'receipt' => 'Receipt Order',
    'delivery' => 'ប័ណ្ណដឹក / Delivery Slip',
    'invoice' => 'Invoice',
];
if (!isset($printTitles[$printType])) {
    $printType = 'receipt';
}
$printTitle = $printTitles[$printType];
$showMoney = $printType !== 'delivery';

if (!$receipt_order_ids) {
    die('Invalid receipt order ID');
}

// Fetch receipt order details
$placeholders = implode(',', array_fill(0, count($receipt_order_ids), '?'));
$orderByIds = implode(',', $receipt_order_ids);
$stmt = $pdo->prepare("
    SELECT ro.*,
           u.name as seller_name,
           p.name as page_name,
           dt.name as delivery_type_name,
           dc.amount as delivery_cost_amount,
           dc.label as delivery_cost_label
    FROM receipt_orders ro
    LEFT JOIN users u ON ro.seller_id = u.id
    LEFT JOIN pages p ON ro.page_id = p.id
    LEFT JOIN delivery_types dt ON ro.delivery_type_id = dt.id
    LEFT JOIN delivery_costs dc ON ro.delivery_cost_id = dc.id
    WHERE ro.id IN ($placeholders)
    ORDER BY FIELD(ro.id, $orderByIds)
");
$stmt->execute($receipt_order_ids);
$receipt_orders = $stmt->fetchAll();

if (!$receipt_orders) {
    die('Receipt order not found');
}

// Fetch receipt order items
$itemsStmt = $pdo->prepare("
    SELECT roi.*,
           p.name as product_name
    FROM receipt_order_items roi
    JOIN products p ON roi.product_id = p.id
    WHERE roi.receipt_order_id IN ($placeholders)
    ORDER BY roi.id
");
$itemsStmt->execute($receipt_order_ids);
$itemsByOrder = [];
foreach ($itemsStmt->fetchAll() as $itemRow) {
    $itemsByOrder[(int)$itemRow['receipt_order_id']][] = $itemRow;
}

$logo = get_default_logo($pdo);
$isMultiReceipt = count($receipt_orders) > 1;
$pageTitleCode = $isMultiReceipt ? count($receipt_orders) . ' receipts' : ($receipt_orders[0]['receipt_code'] ?? '');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($printTitle) ?> - <?= htmlspecialchars($pageTitleCode) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;900&family=Battambang:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<style>
    body {
        background: #e5e7eb;
        font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
    }
    .receipt-card {
        max-width: 360px;
        margin: 1.5rem auto;
        border-radius: 1.25rem;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
        background: #ffffff;
        border: 1px solid #e5e7eb;
    }
    .receipt-card + .receipt-card {
        margin-top: 2rem;
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
        color: #000000ff;
    }
    .receipt-title.delivery-slip-title {
        font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        font-size: 0.9rem;
        letter-spacing: 0.08em;
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
    .khmer-text {
        font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
    }
    .btn-pill {
        border-radius: 999px;
        padding-left: 1.5rem;
        padding-right: 1.5rem;
    }
    .thermal-cut-feed {
        display: none;
    }
    .delivery-items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0.25rem;
        font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
    }
    .delivery-items-table th,
    .delivery-items-table td {
        border: 1px solid #000;
        padding: 6px 8px;
        color: #111;
        font-size: 13px;
        font-weight: 700;
        vertical-align: middle;
    }
    .delivery-items-table th {
        background: #d1d5db;
        text-align: center;
        line-height: 1.25;
    }
    .delivery-items-table th .th-en {
        display: block;
        font-size: 11px;
        font-weight: 600;
    }
    .delivery-items-table .tc {
        text-align: center;
    }
    .delivery-items-table .tl {
        text-align: left;
    }
    .delivery-items-table tfoot td {
        background: #ffffff;
        font-weight: 800;
    }
    .delivery-items-table .qty-total-label {
        text-align: right;
        font-size: 12px;
        line-height: 1.3;
    }
    .delivery-items-table .qty-total-value {
        text-align: center;
        font-size: 15px;
        font-weight: 900;
    }
    .delivery-info-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin: 0.2rem 0 0.35rem;
        padding: 5px 7px;
        border: 1px solid #111;
        border-radius: 8px;
        font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
    }
    .delivery-info-line .delivery-label {
        color: #111;
        font-size: 13px;
        font-weight: 800;
        line-height: 1.25;
    }
    .delivery-info-line .delivery-value {
        min-width: 72px;
        border-radius: 999px;
        background: #111;
        color: #fff;
        padding: 3px 10px;
        font-size: 13px;
        font-weight: 900;
        line-height: 1.25;
        text-align: center;
    }
    .receipt-powered-by {
        margin-top: 0.65rem;
        color: #111;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: center;
    }
    @media print {
        body {
            background: #ffffff;
        }
        body > * {
            visibility: hidden;
        }
        .receipt-card, .receipt-card * {
            visibility: visible;
        }
        .receipt-card {
            box-shadow: none;
            border: none;
            margin: 0;
            max-width: 100%;
            page-break-after: always;
            break-after: page;
        }
        .receipt-card:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        /* Hide all form elements and buttons when printing */
        form, .receipt-actions, .receipt-actions *, button {
            visibility: hidden !important;
            display: none !important;
        }
        .thermal-cut-feed {
            display: block;
            height: 18mm;
        }
    }
</style>
</head>
<body>

<div class="container-fluid py-3">
    <?php foreach ($receipt_orders as $cardIndex => $receipt_order): ?>
    <?php
    $items = $itemsByOrder[(int)$receipt_order['id']] ?? [];
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += (float)$item['line_total'];
    }
    $total = $subtotal - (float)$receipt_order['discount'];
    if ($total < 0) {
        $total = 0;
    }
    $qrText = $receipt_order['receipt_code'];
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($qrText);
    $receiptContentId = $cardIndex === 0 ? 'receiptContent' : 'receiptContent' . (int)$cardIndex;
    ?>
    <div class="receipt-card card">
        <div class="card-body p-3" id="receiptRoot">
            <div id="<?= htmlspecialchars($receiptContentId) ?>">
            <div class="receipt-header-logo">
                <?php if ($logo): ?>
                    <img src="<?= htmlspecialchars(uploaded_file_url($logo['file_path'], 'logos')) ?>" alt="Logo">
                <?php endif; ?>
            </div>
            <div class="receipt-title <?= $printType === 'delivery' ? 'delivery-slip-title' : '' ?>"><?= htmlspecialchars($printTitle) ?></div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="small">
                    <div>
                        <span class="label-col" style="font-size:16px;">Seller:</span>
                        <span class="value-col" style="font-size:16px;"><?= htmlspecialchars($receipt_order['seller_name']) ?></span>
                    </div>
                    <div style="font-weight:600; font-size:14px; margin-top:2px;">
                        Code: <?= htmlspecialchars($receipt_order['receipt_code']) ?>
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
                <div class="col-8 value-col"><?= htmlspecialchars($receipt_order['customer_name']) ?></div>
            </div>
            <?php if (!empty($receipt_order['phone'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col">Phone</div>
                <div class="col-8 value-col"><?= htmlspecialchars($receipt_order['phone']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($receipt_order['location'])): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col khmer-text" style="font-size:16px;">&#x1791;&#x17B8;&#x178F;&#x17B6;&#x17C6;&#x1784;</div>
                <div class="col-8 value-col khmer-text" style="font-size:16px;"><?= htmlspecialchars($receipt_order['location']) ?></div>
            </div>
            <?php endif; ?>

            <div class="section-divider"></div>
            <?php if ($printType === 'delivery'): ?>
                <?php
                $deliveryTotalQty = 0.0;
                foreach ($items as $deliveryItem) {
                    $deliveryTotalQty += (float)($deliveryItem['quantity'] ?? 0);
                }
                ?>
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
                                <td class="tc"><?= htmlspecialchars(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="qty-total-label">សរុបចំនួន</td>
                            <td class="qty-total-value"><?= htmlspecialchars(rtrim(rtrim(number_format($deliveryTotalQty, 2, '.', ''), '0'), '.')) ?></td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="section-title mb-1">Products</div>
                <?php foreach ($items as $item): ?>
                    <div class="d-flex justify-content-between small mb-1">
                        <div class="value-col khmer-text flex-grow-1 me-2" style="font-size:16px;"><?= htmlspecialchars($item['product_name']) ?> x <?= (int)$item['quantity'] ?></div>
                        <?php if ($showMoney): ?>
                        <div class="value-col amount-col" style="font-size:14px;">$<?= number_format($item['line_total'], 2) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="section-divider"></div>
            <div class="section-title mb-1">Delivery</div>
            <?php if (!empty($receipt_order['delivery_type_name'])): ?>
            <div class="delivery-info-line">
                <span class="delivery-label">&#x178A;&#x17B9;&#x1780;&#x178A;&#x17C4;&#x1799;</span>
                <span class="delivery-value"><?= htmlspecialchars($receipt_order['delivery_type_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php
            $costLabel = $receipt_order['delivery_cost_label'] ?? '';
            if ($costLabel === '' && isset($receipt_order['delivery_cost_amount']) && $receipt_order['delivery_cost_amount'] !== null) {
                $costLabel = '$' . number_format($receipt_order['delivery_cost_amount'], 2);
            }
            ?>
            <?php if ($showMoney && $costLabel !== ''): ?>
            <div class="row small mb-1">
                <div class="col-4 label-col khmer-text" style="font-size:14px;">&#x178F;&#x1798;&#x17D2;&#x179B;&#x17C3;&#x178A;&#x17B9;&#x1780;</div>
                <div class="col-8 value-col khmer-text" style="font-size:14px;"><?= htmlspecialchars($costLabel) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($showMoney): ?>
                <div class="section-divider"></div>
                <div class="section-title mb-1">Summary</div>
                <?php if ((float)$receipt_order['discount'] > 0): ?>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="label-col">Discount</div>
                    <div class="value-col amount-col">$<?php echo number_format((float)$receipt_order['discount'], 2); ?></div>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="label-col">Total</div>
                    <div class="fw-bold amount-col" style="font-size:20px; color:black;">$<?php echo number_format($total, 2); ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($receipt_order['page_name'])): ?>
                <div><span class="label-col"style="font-size:16px;">Page:</span> <span class="value-col khmer-text" style="font-size:14px;"><?php echo htmlspecialchars($receipt_order['page_name']); ?></span></div>
            <?php endif; ?>
            <div class="small mb-0" style="color:black; font-weight: 600;">Created: <?php echo htmlspecialchars($receipt_order['created_at']); ?></div>

            <?php if ($receipt_order['notes']): ?>
            <div class="section-divider"></div>
            <div class="section-title mb-1">Notes</div>
            <div class="small khmer-text" style="color: #666;">
                <?php echo nl2br(htmlspecialchars($receipt_order['notes'])); ?>
            </div>
            <?php endif; ?>
            <div class="receipt-powered-by">Powered by : One Night Solution</div>
            <div class="thermal-cut-feed" aria-hidden="true"></div>

            <?php if (!$isMultiReceipt): ?>
            <form method="post" class="receipt-actions d-flex justify-content-center gap-2 mt-3 flex-wrap">
                <button type="button" class="btn btn-light btn-pill" onclick="(function(){ if (document.referrer) { window.location.href = document.referrer; } else { window.history.back(); } })();">Back</button>
                <button type="button" class="btn btn-primary btn-pill" id="copyReceipt">Copy receipt</button>
                <button type="button" class="btn btn-outline-primary btn-pill" id="saveCard">Save card</button>
                <button type="button" class="btn btn-secondary btn-pill" onclick="window.print()">Print</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!$isMultiReceipt): ?>
<script>
(function(){
    const btn = document.getElementById('copyReceipt');
    const saveBtn = document.getElementById('saveCard');
    if (!btn) return;

    btn.addEventListener('click', function(){
        const lines = [];
        lines.push('RECEIPT ORDER');
        lines.push('Code: <?= addslashes($receipt_order['receipt_code']) ?>');
        lines.push('Seller: <?= addslashes($receipt_order['seller_name']) ?>');
        <?php if (!empty($receipt_order['page_name'])): ?>
        lines.push('Page: <?= addslashes($receipt_order['page_name']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('Customer: <?= addslashes($receipt_order['customer_name']) ?>');
        <?php if (!empty($receipt_order['phone'])): ?>
        lines.push('Phone: <?= addslashes($receipt_order['phone']) ?>');
        <?php endif; ?>
        <?php if (!empty($receipt_order['location'])): ?>
        lines.push('Location: <?= addslashes($receipt_order['location']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('Products:');
        <?php foreach ($items as $item): ?>
        lines.push('- <?= addslashes($item['product_name']) ?> x <?= (int)$item['quantity'] ?> = $<?= number_format($item['line_total'], 2) ?>');
        <?php endforeach; ?>
        lines.push('');
        <?php if (!empty($receipt_order['delivery_type_name'])): ?>
        lines.push('Delivery Type: <?= addslashes($receipt_order['delivery_type_name']) ?>');
        <?php endif; ?>
        <?php if (!empty($receipt_order['delivery_cost_label'])): ?>
        lines.push('Delivery Cost: <?= addslashes($receipt_order['delivery_cost_label']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('Status: <?= strtoupper(addslashes($receipt_order['status'])) ?>');
        <?php if ((float)$receipt_order['discount'] > 0): ?>
        lines.push('Discount: $<?= number_format((float)$receipt_order['discount'], 2) ?>');
        <?php endif; ?>
        lines.push('Total: $<?= number_format($total, 2) ?>');
        lines.push('Created: <?= addslashes($receipt_order['created_at']) ?>');
        <?php if ($receipt_order['notes']): ?>
        lines.push('Notes: <?= addslashes($receipt_order['notes']) ?>');
        <?php endif; ?>
        lines.push('');
        lines.push('*** RECEIPT ORDER - For Preparation Only ***');

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
            const card = document.getElementById('receiptContent');
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
                    link.download = '<?= addslashes($receipt_order['receipt_code']) ?>.png';
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
<?php endif; ?>

<?php if (($_GET['from'] ?? '') === 'reprint'): ?>
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

