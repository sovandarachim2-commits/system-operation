<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier'], 'inventory.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

// Normalize empty
$from = $from === '' ? date('Y-m-d') : $from;
$to   = $to === ''   ? $from : $to;

$mode = $_GET['mode'] ?? '';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $paramsOrders = [$from, $to];

    // Reuse queries for CSV
    // Products (exclude cancelled orders from inventory counts)
    $sqlProducts = 'SELECT pr.name AS product_name,
                           SUM(oi.quantity) AS total_qty,
                           SUM(oi.line_total) AS total_amount
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id
                    JOIN products pr ON oi.product_id = pr.id
                    WHERE DATE(o.created_at) BETWEEN ? AND ?
                      AND o.is_cancelled = 0
                    GROUP BY pr.id, pr.name
                    ORDER BY pr.name';
    $stmt = $pdo->prepare($sqlProducts);
    $stmt->execute($paramsOrders);
    $csvProducts = $stmt->fetchAll();

    // Money
    // Money summary (exclude cancelled orders)
    $sqlMoney = 'SELECT status, SUM(total_amount) AS total
                 FROM orders
                 WHERE DATE(created_at) BETWEEN ? AND ?
                   AND is_cancelled = 0
                 GROUP BY status';
    $stmt = $pdo->prepare($sqlMoney);
    $stmt->execute($paramsOrders);
    $rowsMoney = $stmt->fetchAll();
    $paidTotal = 0;
    $unpaidTotal = 0;
    foreach ($rowsMoney as $row) {
        if ($row['status'] === 'paid') {
            $paidTotal = (float)$row['total'];
        } elseif ($row['status'] === 'unpaid') {
            $unpaidTotal = (float)$row['total'];
        }
    }
    $grandTotal = $paidTotal + $unpaidTotal;

    // Cashiers
    $sqlCashier = 'SELECT u.name AS cashier_name,
                          COUNT(DISTINCT pj.order_id) AS orders_printed,
                          SUM(o.total_amount) AS total_printed,
                          MIN(pj.printed_at) AS first_print,
                          MAX(pj.printed_at) AS last_print
                   FROM print_jobs pj
                   JOIN users u ON pj.cashier_id = u.id
                   JOIN orders o ON pj.order_id = o.id
                   WHERE DATE(pj.printed_at) BETWEEN ? AND ?
                   GROUP BY u.id, u.name
                   ORDER BY u.name';
    $stmt = $pdo->prepare($sqlCashier);
    $stmt->execute([$from, $to]);
    $csvCashiers = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_cashier_' . $from . '_to_' . $to . '.csv"');

    $out = fopen('php://output', 'w');

    // Section: Money summary
    fputcsv($out, ['Money Summary', 'From', $from, 'To', $to]);
    fputcsv($out, ['Type', 'Amount']);
    fputcsv($out, ['Paid', number_format($paidTotal, 2)]);
    fputcsv($out, ['Unpaid', number_format($unpaidTotal, 2)]);
    fputcsv($out, ['Grand Total', number_format($grandTotal, 2)]);
    fputcsv($out, []);

    // Section: Products
    fputcsv($out, ['Product Inventory']);
    fputcsv($out, ['Product', 'Qty', 'Total']);
    foreach ($csvProducts as $p) {
        fputcsv($out, [
            $p['product_name'],
            (int)$p['total_qty'],
            number_format($p['total_amount'], 2),
        ]);
    }
    fputcsv($out, []);

    // Section: Cashier Work
    fputcsv($out, ['Cashier Work']);
    fputcsv($out, ['Cashier', 'Orders Printed', 'Total Printed', 'First Print', 'Last Print']);
    foreach ($csvCashiers as $c) {
        fputcsv($out, [
            $c['cashier_name'],
            (int)$c['orders_printed'],
            number_format($c['total_printed'], 2),
            $c['first_print'],
            $c['last_print'],
        ]);
    }

    fclose($out);
    exit;
}

// Product inventory summary based only on printed orders in this period (exclude cancelled)
$paramsOrders = [$from, $to];
$sqlProducts = 'SELECT pr.name AS product_name,
                       SUM(oi.quantity) AS total_qty,
                       SUM(oi.line_total) AS total_amount
                FROM print_jobs pj
                JOIN orders o ON pj.order_id = o.id
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products pr ON oi.product_id = pr.id
                WHERE DATE(pj.printed_at) BETWEEN ? AND ?
                  AND o.is_cancelled = 0
                GROUP BY pr.id, pr.name
                ORDER BY pr.name';
$stmt = $pdo->prepare($sqlProducts);
$stmt->execute($paramsOrders);
$products = $stmt->fetchAll();

// Money summary and order status counts based only on printed orders (exclude cancelled)
$sqlMoney = 'SELECT o.status, COUNT(DISTINCT o.id) AS cnt, SUM(o.total_amount) AS total
             FROM print_jobs pj
             JOIN orders o ON pj.order_id = o.id
             WHERE DATE(pj.printed_at) BETWEEN ? AND ?
               AND o.is_cancelled = 0
             GROUP BY o.status';
$stmt = $pdo->prepare($sqlMoney);
$stmt->execute($paramsOrders);
$rowsMoney     = $stmt->fetchAll();
$paidTotal     = 0;
$unpaidTotal   = 0;
$paidCount     = 0;
$unpaidCount   = 0;
foreach ($rowsMoney as $row) {
    if ($row['status'] === 'paid') {
        $paidTotal = (float)$row['total'];
        $paidCount = (int)$row['cnt'];
    } elseif ($row['status'] === 'unpaid') {
        $unpaidTotal = (float)$row['total'];
        $unpaidCount = (int)$row['cnt'];
    }
}
$grandTotal   = $paidTotal + $unpaidTotal;
$totalOrders  = $paidCount + $unpaidCount;

// Products from cancelled orders in this period (all cancelled orders, still based on created_at)
$sqlCancelledProducts = 'SELECT pr.name AS product_name,
                               SUM(oi.quantity) AS total_qty,
                               SUM(oi.line_total) AS total_amount
                        FROM orders o
                        JOIN order_items oi ON oi.order_id = o.id
                        JOIN products pr ON oi.product_id = pr.id
                        WHERE DATE(o.created_at) BETWEEN ? AND ?
                          AND o.is_cancelled = 1
                        GROUP BY pr.id, pr.name
                        ORDER BY pr.name';
$stmt = $pdo->prepare($sqlCancelledProducts);
$stmt->execute($paramsOrders);
$cancelledProducts = $stmt->fetchAll();

// Daily cashier work from print_jobs (with duplicate vs single prints)
$sqlCashier = 'SELECT u.name AS cashier_name,
                      COUNT(DISTINCT pj.order_id) AS orders_printed,
                      SUM(o.total_amount) AS total_printed,
                      MIN(pj.printed_at) AS first_print,
                      MAX(pj.printed_at) AS last_print,
                      SUM(CASE WHEN x.print_count > 1 THEN 1 ELSE 0 END) AS duplicate_orders,
                      SUM(CASE WHEN x.print_count = 1 THEN 1 ELSE 0 END) AS single_orders
               FROM print_jobs pj
               JOIN users u ON pj.cashier_id = u.id
               JOIN orders o ON pj.order_id = o.id
               JOIN (
                    SELECT order_id, COUNT(*) AS print_count
                    FROM print_jobs
                    WHERE DATE(printed_at) BETWEEN ? AND ?
                    GROUP BY order_id
               ) x ON x.order_id = pj.order_id
               WHERE DATE(pj.printed_at) BETWEEN ? AND ?
               GROUP BY u.id, u.name
               ORDER BY u.name';
$stmt = $pdo->prepare($sqlCashier);
$stmt->execute([$from, $to, $from, $to]);
$cashiers = $stmt->fetchAll();

// Individual printed orders in this period (based on printed_at)
$sqlOrders = 'SELECT o.*, u.name AS seller_name,
                    1 AS is_printed,
                    pj.printed_at
             FROM print_jobs pj
             JOIN orders o ON pj.order_id = o.id
             JOIN users u ON o.seller_id = u.id
             WHERE DATE(pj.printed_at) BETWEEN ? AND ?
             ORDER BY pj.printed_at DESC';
$stmt = $pdo->prepare($sqlOrders);
$stmt->execute([$from, $to]);
$orders = $stmt->fetchAll();

// Products for printed orders in this period (used for on-screen Items and exports)
$orderIds = array_column($orders, 'id');
$orderProductsByOrder = [];
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $sqlOrderItems = "SELECT oi.order_id, pr.name AS product_name, oi.quantity, oi.line_total
                      FROM order_items oi
                      JOIN products pr ON oi.product_id = pr.id
                      WHERE oi.order_id IN ($placeholders)
                      ORDER BY pr.name";
    $stmt = $pdo->prepare($sqlOrderItems);
    $stmt->execute($orderIds);
    $rowsItems = $stmt->fetchAll();
    foreach ($rowsItems as $row) {
        $oid = $row['order_id'];
        if (!isset($orderProductsByOrder[$oid])) {
            $orderProductsByOrder[$oid] = [];
        }
        $orderProductsByOrder[$oid][] = $row;
    }
}

// Handle exports for Printed Orders in Period (one row per order, with Items summary)
if ($mode === 'printed_orders_excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="printed_orders_' . $from . '_to_' . $to . '.csv"');

    $out = fopen('php://output', 'w');
    // Write UTF-8 BOM so Excel detects encoding correctly (for Khmer characters)
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Printed Orders', 'From', $from, 'To', $to]);
    fputcsv($out, []);
    fputcsv($out, ['No', 'Print Time', 'Order Time', 'Order Code', 'Customer', 'Items', 'Seller', 'Status', 'Total']);

    $no = 1;
    foreach ($orders as $o) {
        $items = $orderProductsByOrder[$o['id']] ?? [];
        $itemTexts = [];
        foreach ($items as $it) {
            $itemTexts[] = $it['product_name'] . ' x' . (int)$it['quantity'];
        }
        $itemsPreview = $itemTexts ? implode(', ', $itemTexts) : '';

        fputcsv($out, [
            $no++,
            substr($o['printed_at'], 0, 10),
            substr($o['created_at'], 0, 10),
            $o['order_code'],
            $o['customer_name'],
            $itemsPreview,
            $o['seller_name'],
            strtoupper($o['status']),
            number_format($o['total_amount'], 2),
        ]);
    }

    fclose($out);
    exit;
}

if ($mode === 'printed_orders_pdf') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Printed Orders <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; margin: 10px; }
            h1 { font-size: 18px; margin-bottom: 6px; }
            p { margin: 4px 0 10px; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            th, td { border: 1px solid #000; padding: 3px 4px; text-align: left; }
            th { background: #f0f0f0; }
            .text-right { text-align: right; }
        </style>
    </head>
    <body>
    <h1>Printed Orders Report</h1>
    <p>Period: <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></p>
    <table>
        <thead>
        <tr>
            <th style="width:4%;">No</th>
            <th style="width:14%;">Print Time</th>
            <th style="width:14%;">Order Time</th>
            <th style="width:16%;">Order Code</th>
            <th style="width:18%;">Customer</th>
            <th style="width:20%;">Items</th>
            <th style="width:8%;">Seller</th>
            <th style="width:6%;">Status</th>
            <th class="text-right" style="width:10%;">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$orders): ?>
            <tr><td colspan="8" style="text-align:center;">No printed orders in this period.</td></tr>
        <?php else: ?>
            <?php $no = 1; foreach ($orders as $o): ?>
                <?php
                    $items = $orderProductsByOrder[$o['id']] ?? [];
                    $itemTexts = [];
                    foreach ($items as $it) {
                        $itemTexts[] = $it['product_name'] . ' x' . (int)$it['quantity'];
                    }
                    $itemsPreview = $itemTexts ? implode(', ', $itemTexts) : '';
                ?>
                <tr>
                    <td><?= $no++; ?></td>
                    <td><?= htmlspecialchars(substr($o['printed_at'], 0, 10)) ?></td>
                    <td><?= htmlspecialchars(substr($o['created_at'], 0, 10)) ?></td>
                    <td><?= htmlspecialchars($o['order_code']) ?></td>
                    <td><?= htmlspecialchars($o['customer_name']) ?></td>
                    <td><?= htmlspecialchars($itemsPreview) ?></td>
                    <td><?= htmlspecialchars($o['seller_name']) ?></td>
                    <td><?= strtoupper($o['status']) ?></td>
                    <td class="text-right">$<?= number_format($o['total_amount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <script>
        window.print();
    </script>
    </body>
    </html>
    <?php
    exit;
}

// Products for printed orders in this period
$orderIds = array_column($orders, 'id');
$orderProductsByOrder = [];
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $sqlOrderItems = "SELECT oi.order_id, pr.name AS product_name, oi.quantity, oi.line_total
                      FROM order_items oi
                      JOIN products pr ON oi.product_id = pr.id
                      WHERE oi.order_id IN ($placeholders)
                      ORDER BY pr.name";
    $stmt = $pdo->prepare($sqlOrderItems);
    $stmt->execute($orderIds);
    $rowsItems = $stmt->fetchAll();
    foreach ($rowsItems as $row) {
        $oid = $row['order_id'];
        if (!isset($orderProductsByOrder[$oid])) {
            $orderProductsByOrder[$oid] = [];
        }
        $orderProductsByOrder[$oid][] = $row;
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Printed Orders Inventory Report</h1>
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">From date</label>
                <input type="date" name="from" class="form-control form-control-lg" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">To date</label>
                <input type="date" name="to" class="form-control form-control-lg" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary btn-lg w-100">Filter</button>
                <a href="inventory.php" class="btn btn-outline-secondary btn-lg w-100">Reset</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-3">
            <div><strong>Orders (Total):</strong> <?= (int)$totalOrders ?></div>
            <div><strong>Orders Paid:</strong> <?= (int)$paidCount ?></div>
            <div><strong>Orders Unpaid:</strong> <?= (int)$unpaidCount ?></div>
            <div><strong>Paid Total:</strong> $<?= number_format($paidTotal, 2) ?></div>
            <div><strong>Unpaid Total:</strong> $<?= number_format($unpaidTotal, 2) ?></div>
            <div><strong>Grand Total:</strong> $<?= number_format($grandTotal, 2) ?></div>
        </div>
    </div>

    <div class="row g-3 flex-grow-1">
        <div class="col-12 col-lg-6 d-flex flex-column">
            <div class="card shadow-sm flex-grow-1 d-flex flex-column mb-3">
                <div class="card-header">
                    <strong>Product Inventory from Printed Orders</strong>
                </div>
                <div class="card-body d-flex flex-column p-0">
                    <div class="table-responsive table-responsive-full">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Total</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$products): ?>
                                <tr><td colspan="3" class="text-center py-4">No orders in this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['product_name']) ?></td>
                                        <td class="text-end"><?= (int)$p['total_qty'] ?></td>
                                        <td class="text-end">$<?= number_format($p['total_amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm flex-grow-1 d-flex flex-column">
                <div class="card-header">
                    <strong>Cancelled Orders - Products</strong>
                </div>
                <div class="card-body d-flex flex-column p-0">
                    <div class="table-responsive table-responsive-full">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Total</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$cancelledProducts): ?>
                                <tr><td colspan="3" class="text-center py-4">No cancelled orders in this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($cancelledProducts as $cp): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($cp['product_name']) ?></td>
                                        <td class="text-end"><?= (int)$cp['total_qty'] ?></td>
                                        <td class="text-end">$
                                            <?= number_format($cp['total_amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6 d-flex flex-column">
            <div class="card shadow-sm flex-grow-1 d-flex flex-column">
                <div class="card-header">
                    <strong>Cashier Work</strong>
                </div>
                <div class="card-body d-flex flex-column p-0">
                    <div class="table-responsive table-responsive-full">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Cashier</th>
                                <th class="text-end">Orders Printed</th>
                                <th class="text-end">Single Prints</th>
                                <th class="text-end">Duplicate Prints</th>
                                <th class="text-end">Total Printed</th>
                                <th>First Print</th>
                                <th>Last Print</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$cashiers): ?>
                                <tr><td colspan="5" class="text-center py-4">No printed orders in this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($cashiers as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['cashier_name']) ?></td>
                                        <td class="text-end"><?= (int)$c['orders_printed'] ?></td>
                                        <td class="text-end"><?= (int)($c['single_orders'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($c['duplicate_orders'] ?? 0) ?></td>
                                        <td class="text-end">$<?= number_format($c['total_printed'], 2) ?></td>
                                        <td><?= htmlspecialchars($c['first_print']) ?></td>
                                        <td><?= htmlspecialchars($c['last_print']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong>Printed Orders in Period</strong>
                <div class="d-flex gap-2">
                    <a href="inventory.php?from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>&amp;mode=printed_orders_pdf" class="btn btn-sm btn-outline-secondary" target="_blank">Download PDF</a>
                    <a href="inventory.php?from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>&amp;mode=printed_orders_excel" class="btn btn-sm btn-outline-success">Export Excel</a>
                </div>
            </div>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full mb-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th>Print Time</th>
                        <th>Order Time</th>
                        <th>Order Code</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Seller</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th>Printed</th>
                        <th>View</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="9" class="text-center py-4">No orders in this period.</td></tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($orders as $o): ?>
                            <?php
                                $items = $orderProductsByOrder[$o['id']] ?? [];
                                $itemTexts = [];
                                foreach ($items as $it) {
                                    $itemTexts[] = $it['product_name'] . ' x' . (int)$it['quantity'];
                                }
                                $itemsPreview = $itemTexts ? implode(', ', array_slice($itemTexts, 0, 3)) : '';
                                if (count($itemTexts) > 3) {
                                    $itemsPreview .= ' ...';
                                }
                            ?>
                            <tr>
                                <td><?= $no++; ?></td>
                                <td><?= htmlspecialchars($o['printed_at']) ?></td>
                                <td><?= htmlspecialchars($o['created_at']) ?></td>
                                <td><?= htmlspecialchars($o['order_code']) ?></td>
                                <td><?= htmlspecialchars($o['customer_name']) ?></td>
                                <td><?= htmlspecialchars($itemsPreview) ?></td>
                                <td><?= htmlspecialchars($o['seller_name']) ?></td>
                                <td>
                                    <span class="badge <?= $o['status']==='paid'?'bg-success':'bg-warning text-dark' ?>">
                                        <?= strtoupper($o['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end">$<?= number_format($o['total_amount'], 2) ?></td>
                                <td><?= $o['is_printed'] ? 'Yes' : 'No' ?></td>
                                <td>
                                    <?php if (!empty($o['is_cancelled'])): ?>
                                        <span class="badge bg-danger">Cancelled</span>
                                    <?php else: ?>
                                        <a href="../receipt.php?id=<?= (int)$o['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
