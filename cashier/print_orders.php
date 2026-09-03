<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'print_orders.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../stock_print_lib.php';

$pdo  = get_db_connection();
$user = current_user();

$errors  = [];
$success = '';
$printOrderStockError = $_SESSION['print_orders_stock_error'] ?? null;
if (isset($_SESSION['print_orders_stock_error'])) {
    unset($_SESSION['print_orders_stock_error']);
}
$printOrderStockErrorItems = [];
if (is_array($printOrderStockError)) {
    $reqByProduct = [];
    if (!empty($printOrderStockError['order_ids']) && is_array($printOrderStockError['order_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $printOrderStockError['order_ids']), static fn($id) => $id > 0));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtReq = $pdo->prepare("
                SELECT p.name AS product_name, SUM(oi.quantity) AS required_qty
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id IN ($placeholders)
                GROUP BY p.name
            ");
            $stmtReq->execute($ids);
            foreach ($stmtReq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $reqByProduct[(string)($r['product_name'] ?? '')] = (float)($r['required_qty'] ?? 0);
            }
        }
    }

    if (!empty($printOrderStockError['items']) && is_array($printOrderStockError['items'])) {
        $printOrderStockErrorItems = $printOrderStockError['items'];
    } elseif (!empty($printOrderStockError['errors']) && is_array($printOrderStockError['errors'])) {
        foreach ($printOrderStockError['errors'] as $rawError) {
            $msg = (string)$rawError;
            $item = [
                'product_name' => 'Stock issue',
                'required' => null,
                'available' => null,
                'shortage' => null,
                'component_details' => [],
                'message_detail' => $msg,
            ];
            if (preg_match("/^Product '(.+)' not found in default location \\((.+)\\)\\.?$/u", $msg, $m)) {
                $item['product_name'] = $m[1];
                $requiredQty = (float)($reqByProduct[$item['product_name']] ?? 0);
                if ($requiredQty > 0) {
                    $item['required'] = $requiredQty;
                    $item['available'] = 0.0;
                    $item['shortage'] = $requiredQty;
                }
                $item['message_detail'] = "Not found in default location ({$m[2]}).";
            } elseif (preg_match("/^Insufficient stock for (.+) in default location \\((.+)\\): Required ([0-9.]+), Available ([0-9.]+)/u", $msg, $m)) {
                $item['product_name'] = $m[1];
                $item['required'] = (float)$m[3];
                $item['available'] = (float)$m[4];
                $item['shortage'] = max(0, (float)$m[3] - (float)$m[4]);
                $item['message_detail'] = "Default location: {$m[2]}";
            }
            $printOrderStockErrorItems[] = $item;
        }
    }
}

// EOD finalized guard
function isEodFinalized($pdo) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eod_stock_reports WHERE report_date = ? AND status = 'finalized'");
    $stmt->execute([$yesterday]);
    return (int)$stmt->fetchColumn() > 0;
}

// Function to check stock availability for orders
function checkOrderStockAvailability($pdo, $orderIds) {
    return stock_print_check_orders($pdo, $orderIds);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_ids = $_POST['order_ids'] ?? [];
    if (!$order_ids) {
        $errors[] = 'Please select at least one order to mark as printed.';
    } else {
        // Block printing if yesterday EOD is not finalized
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

        $cleanIds = [];
        foreach ($order_ids as $oid) {
            $oid = (int)$oid;
            if ($oid > 0) {
                $cleanIds[] = $oid;
            }
        }
        if ($cleanIds) {
            // Check stock availability before allowing print
            $stockCheck = checkOrderStockAvailability($pdo, $cleanIds);
            
            if (!$stockCheck['can_print']) {
                // Show error page instead of redirecting
                include __DIR__ . '/../layout/header.php';
                ?>
                <div class="d-flex flex-column h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                        <div>
                            <h1 class="h3 mb-1 text-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                Cannot Print Orders
                            </h1>
                            <p class="text-muted mb-0">Stock verification failed - insufficient inventory detected</p>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Main Error Card -->
                        <div class="col-lg-8">
                            <div class="card border-danger shadow-sm">
                                <div class="card-header bg-danger text-white">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-x-circle-fill me-2"></i>
                                        Insufficient Stock Alert
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-danger" role="alert">
                                        <h6 class="alert-heading">
                                            <i class="bi bi-info-circle-fill me-2"></i>
                                            The following items have insufficient stock in <strong><?php echo htmlspecialchars($stockCheck['location']['location_name'] ?? 'Default Location'); ?></strong>
                                        </h6>
                                        <hr>
                                        <div class="stock-details">
                                            <?php
                                            $insufficientItems = $stockCheck['insufficient_items'] ?? [];
                                            ?>
                                            <?php foreach ($insufficientItems as $item): ?>
                                                <div class="stock-item d-flex justify-content-between align-items-center p-3 mb-2 bg-light rounded">
                                                    <div class="item-info">
                                                        <strong class="text-dark"><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                        <?php if (!empty($item['component_details']) && is_array($item['component_details'])): ?>
                                                            <div class="mt-2">
                                                                <div class="small text-muted fw-semibold mb-1">Needed component items:</div>
                                                                <?php foreach ($item['component_details'] as $component): ?>
                                                                    <div class="small text-muted">
                                                                        <?php echo htmlspecialchars($component['product_name']); ?>
                                                                        -
                                                                        Need <?php echo number_format((float)($component['required'] ?? 0), 2); ?>,
                                                                        Have <?php echo number_format((float)($component['available'] ?? 0), 2); ?>,
                                                                        Short <?php echo number_format((float)($component['shortage'] ?? 0), 2); ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="quantities text-end">
                                                        <div class="text-danger fw-bold">
                                                            <small class="text-muted">Required:</small>
                                                            <span class="badge bg-danger"><?php echo $item['required']; ?></span>
                                                        </div>
                                                        <div class="text-success">
                                                            <small class="text-muted">Available:</small>
                                                            <span class="badge bg-success"><?php echo $item['available']; ?></span>
                                                        </div>
                                                        <div class="text-warning fw-bold">
                                                            <small class="text-muted">Shortage:</small>
                                                            <span class="badge bg-warning text-dark"><?php echo $item['shortage']; ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Panel -->
                        <div class="col-lg-4">
                            <div class="card shadow-sm">
                                <div class="card-header bg-light">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                                        What to do next?
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-3">
                                        <div class="action-item">
                                            <div class="d-flex align-items-start">
                                                <i class="bi bi-check-circle-fill text-success me-3 mt-1"></i>
                                                <div>
                                                    <strong>Check Inventory</strong>
                                                    <p class="text-muted small mb-0">Verify stock levels in your storage locations</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="action-item">
                                            <div class="d-flex align-items-start">
                                                <i class="bi bi-truck text-primary me-3 mt-1"></i>
                                                <div>
                                                    <strong>Restock Items</strong>
                                                    <p class="text-muted small mb-0">Receive new inventory or transfer from other locations</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="action-item">
                                            <div class="d-flex align-items-start">
                                                <i class="bi bi-pencil-square text-info me-3 mt-1"></i>
                                                <div>
                                                    <strong>Adjust Orders</strong>
                                                    <p class="text-muted small mb-0">Modify order quantities or select different items</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="action-item">
                                            <div class="d-flex align-items-start">
                                                <i class="bi bi-person-fill text-secondary me-3 mt-1"></i>
                                                <div>
                                                    <strong>Contact Manager</strong>
                                                    <p class="text-muted small mb-0">Notify inventory manager for assistance</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <a href="print_orders.php" class="btn btn-primary w-100">
                                        <i class="bi bi-arrow-left me-2"></i>
                                        Back to Order Selection
                                    </a>
                                </div>
                            </div>

                            <!-- Quick Stats -->
                            <div class="card mt-3 shadow-sm">
                                <div class="card-body text-center">
                                    <h6 class="card-title text-muted mb-2">Quick Stats</h6>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="border-end">
                                                <div class="h4 text-danger mb-0"><?php echo count($insufficientItems); ?></div>
                                                <small class="text-muted">Items Short</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="h4 text-warning mb-0">
                                                <?php echo array_sum(array_map(function($item) { return $item['shortage']; }, $insufficientItems)); ?>
                                            </div>
                                            <small class="text-muted">Total Shortage</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                include __DIR__ . '/../layout/footer.php';
                exit;
            }
            
            $idsParam = implode(',', $cleanIds);
            header('Location: bulk_print.php?ids=' . urlencode($idsParam));
            exit;
        } else {
            $errors[] = 'Please select at least one valid order to mark as printed.';
        }
    }
}

$start_date       = $_GET['start_date'] ?? '';
$end_date         = $_GET['end_date'] ?? '';
$printed          = $_GET['printed'] ?? 'no'; // no, yes, all
$delivery_type_id = $_GET['delivery_type_id'] ?? '';
$payment_status   = $_GET['payment_status'] ?? '';
$brand_filter     = $_GET['brand_id'] ?? '';

$params = [];
$sql    = 'SELECT o.*, u.name AS seller_name, dt.name AS delivery_type_name,
                  CASE WHEN pj.id IS NULL THEN 0 ELSE 1 END AS is_printed
           FROM orders o
           JOIN users u ON o.seller_id = u.id
           LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
           LEFT JOIN print_jobs pj ON pj.order_id = o.id';

$conditions = ['o.is_cancelled = 0'];

if ($start_date !== '') {
    $conditions[] = 'DATE(o.created_at) >= ?';
    $params[]     = $start_date;
}
if ($end_date !== '') {
    $conditions[] = 'DATE(o.created_at) <= ?';
    $params[]     = $end_date;
}

if ($printed === 'no') {
    $conditions[] = 'pj.id IS NULL';
} elseif ($printed === 'yes') {
    $conditions[] = 'pj.id IS NOT NULL';
}

if ($delivery_type_id !== '') {
    $conditions[] = 'o.delivery_type_id = ?';
    $params[] = $delivery_type_id;
}

if ($payment_status === 'paid' || $payment_status === 'unpaid') {
    $conditions[] = 'o.status = ?';
    $params[] = $payment_status;
}

if ($brand_filter !== '') {
    $conditions[] = 'EXISTS (SELECT 1 FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id AND p.brand_id = ?)';
    $params[] = (int)$brand_filter;
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY o.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$deliveryTypes = $pdo->query('SELECT id, name FROM delivery_types ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$brands = $pdo->query('SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$productMap = [];
if (!empty($orders)) {
    $orderIds = array_map(static fn($order) => (int)($order['id'] ?? 0), $orders);
    $orderIds = array_values(array_filter($orderIds, static fn($id) => $id > 0));
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemsStmt = $pdo->prepare("
            SELECT oi.order_id, p.name AS product_name, oi.quantity,
                   b.name AS brand_name, b.color AS brand_color
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE oi.order_id IN ($placeholders)
            ORDER BY oi.order_id ASC, oi.id ASC
        ");
        $itemsStmt->execute($orderIds);
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $productMap[$orderId][] = [
                'name'        => (string)($row['product_name'] ?? ''),
                'quantity'    => (float)($row['quantity'] ?? 0),
                'brand_name'  => (string)($row['brand_name'] ?? ''),
                'brand_color' => (string)($row['brand_color'] ?? ''),
            ];
        }
    }
}

// Count paid/unpaid and delivery types
$paidCount = 0;
$unpaidCount = 0;
$deliveryTypeCounts = [];
$hasUnpaidVetJt = false;
foreach ($orders as $o) {
    if ($o['status'] === 'paid') {
        $paidCount++;
    } else {
        $unpaidCount++;
    }
    $type = $o['delivery_type_name'] ?? 'N/A';
    if (!isset($deliveryTypeCounts[$type])) {
        $deliveryTypeCounts[$type] = 0;
    }
    $deliveryTypeCounts[$type]++;

    $isVetJt = in_array($o['delivery_type_name'], ['VET', 'J&T', 'J&T (សេវ៉ាខាងភ្ញៀវ) ✔✔✔✔', 'J&T✔✔✔✔']);
    $cannotPrintUnpaid = $isVetJt && $o['status'] !== 'paid';
    if ($cannotPrintUnpaid) $hasUnpaidVetJt = true;
}

include __DIR__ . '/../layout/header.php';
?>
<style>
.table-danger-strong {
    --bs-table-bg: #f8d7da;
    --bs-table-striped-bg: #f1bfc5;
    --bs-table-hover-bg: #efb0b8;
    --bs-table-color: #842029;
}

.table-danger-strong td,
.table-danger-strong th {
    border-color: #f1aeb5;
}

.stock-short-card { border: 1px solid #f5c2c7; background: #fff5f5; border-radius: 10px; }
.stock-short-product { font-weight: 700; color: #842029; }
.stock-short-sub { color: #6c757d; font-size: 0.92rem; }
.stock-chip { display: inline-block; padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; margin-right: 0.35rem; margin-bottom: 0.25rem; }
.stock-chip.req { background: #f8d7da; color: #842029; }
.stock-chip.avl { background: #d1e7dd; color: #0f5132; }
.stock-chip.sho { background: #fff3cd; color: #664d03; }
.stock-comp-box { margin-top: 0.6rem; padding: 0.6rem 0.75rem; border: 1px dashed #f1aeb5; border-radius: 8px; background: #fff; }
.stock-comp-title { font-size: 0.82rem; font-weight: 600; color: #842029; margin-bottom: 0.35rem; }
.stock-comp-item { font-size: 0.86rem; color: #495057; margin-bottom: 0.2rem; }
.stock-comp-item:last-child { margin-bottom: 0; }
.stock-note { margin-top: 0.5rem; font-size: 0.86rem; color: #842029; font-weight: 500; }
.stock-help { font-size: 0.88rem; color: #6c757d; }
</style>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Print Orders</h1>
    </div>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
            <div class="col-12 col-md-6 col-xl-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-lg" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-lg" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label">Delivery Type</label>
                <select name="delivery_type_id" class="form-select form-select-lg">
                    <option value="">All</option>
                    <?php foreach ($deliveryTypes as $dt): ?>
                        <option value="<?= (int)$dt['id'] ?>" <?= $delivery_type_id == $dt['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label">Payment Status</label>
                <select name="payment_status" class="form-select form-select-lg">
                    <option value="">All</option>
                    <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="unpaid" <?= $payment_status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label">Brand</label>
                <select name="brand_id" class="form-select form-select-lg">
                    <option value="">All Brands</option>
                    <?php foreach ($brands as $br): ?>
                        <option value="<?= (int)$br['id'] ?>" <?= $brand_filter == $br['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($br['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-xl-2">
                <div class="d-grid gap-2 d-md-flex d-xl-grid">
                    <button type="submit" class="btn btn-primary btn-lg flex-fill">Filter</button>
                    <a href="print_orders.php" class="btn btn-outline-secondary btn-lg flex-fill">Reset</a>
                </div>
            </div>
            </div>
        </div>
    </form>

    <?php if ($hasUnpaidVetJt): ?>
    <div id="warning" class="alert alert-warning alert-dismissible fade show mb-3">
        <strong>Warning:</strong> Some orders (VET/J&T unpaid) cannot be printed.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if (is_array($printOrderStockError)): ?>
    <div class="alert alert-danger shadow-sm mb-3">
        <div class="fw-bold mb-1"><?= htmlspecialchars((string)($printOrderStockError['title'] ?? 'Cannot print orders')) ?></div>
        <div class="small mb-1"><?= htmlspecialchars((string)($printOrderStockError['message'] ?? 'Insufficient stock detected in default location.')) ?></div>
        <div class="stock-help mb-2">Restock products/components, then try again.</div>
        <?php if (empty($printOrderStockErrorItems) && !empty($printOrderStockError['errors']) && is_array($printOrderStockError['errors'])): ?>
            <ul class="mb-0">
                <?php foreach ($printOrderStockError['errors'] as $stockError): ?>
                    <li><?= htmlspecialchars((string)$stockError) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if (!empty($printOrderStockErrorItems) && is_array($printOrderStockErrorItems)): ?>
        <div class="row g-3 mb-3">
            <?php foreach ($printOrderStockErrorItems as $item): ?>
                <div class="col-12">
                    <div class="stock-short-card p-3">
                        <div class="stock-short-product"><?= htmlspecialchars((string)($item['product_name'] ?? 'Unknown item')) ?></div>
                        <?php if (($item['required'] ?? null) !== null && ($item['available'] ?? null) !== null): ?>
                            <div class="stock-short-sub mt-2">
                                <span class="stock-chip req">Required: <?= number_format((float)($item['required'] ?? 0), 2) ?></span>
                                <span class="stock-chip avl">Available: <?= number_format((float)($item['available'] ?? 0), 2) ?></span>
                                <span class="stock-chip sho">Shortage: <?= number_format((float)($item['shortage'] ?? 0), 2) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($item['component_details']) && is_array($item['component_details'])): ?>
                            <div class="stock-comp-box">
                                <div class="stock-comp-title">Component requirements for this set</div>
                                <?php foreach ($item['component_details'] as $component): ?>
                                    <div class="stock-comp-item">
                                        <?= htmlspecialchars((string)($component['product_name'] ?? 'Component')) ?>:
                                        Need <?= number_format((float)($component['required'] ?? 0), 2) ?>,
                                        Have <?= number_format((float)($component['available'] ?? 0), 2) ?>,
                                        Short <?= number_format((float)($component['shortage'] ?? 0), 2) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($item['message_detail'])): ?>
                            <div class="stock-note"><?= htmlspecialchars((string)$item['message_detail']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2 mb-3">
            <a href="../admin/inventory_view.php" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-box-seam me-1"></i>Go to Inventory
            </a>
            <a href="print_orders.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh Orders
            </a>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">Order Counts by Status</div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="h4 text-success mb-1"><?php echo $paidCount; ?></div>
                            <small class="text-muted">Paid</small>
                        </div>
                        <div class="col-4">
                            <div class="h4 text-warning mb-1"><?php echo $unpaidCount; ?></div>
                            <small class="text-muted">Unpaid</small>
                        </div>
                        <div class="col-4">
                            <div class="h4 text-primary mb-1"><?php echo count($orders); ?></div>
                            <small class="text-muted">Total</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light">Counts by Delivery Type</div>
                <div class="card-body">
                    <?php if ($deliveryTypeCounts): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($deliveryTypeCounts as $type => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?php echo htmlspecialchars($type); ?>
                                    <span class="badge bg-primary rounded-pill"><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0">No data</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <form method="post" id="printForm" class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">Select orders and click "Mark as Printed" after printing.</div>
            <button type="button" id="previewBtn" class="btn btn-primary btn-lg">Mark as Printed</button>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th><input type="checkbox" id="checkAll"></th>
                            <th>Time</th>
                            <th>Order Code</th>
                            <th>Product</th>
                            <th>Brand</th>
                            <th>Customer</th>
                            <th>Seller</th>
                            <th>Delivery Type</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Printed</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="13" class="text-center py-4">No orders found.</td></tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($orders as $o): ?>
                        <?php
                        $isVetJt = in_array($o['delivery_type_name'], ['VET', 'J&T', 'J&T (សេវ៉ាខាងភ្ញៀវ) ✔✔✔✔', 'J&T✔✔✔✔']);
                        $cannotPrintUnpaid = $isVetJt && $o['status'] !== 'paid';
                        ?>
                        <tr class="<?= $cannotPrintUnpaid ? 'table-danger-strong' : '' ?>">
                            <td><?= $no++; ?></td>
                            <td>
                                <?php if (!$o['is_printed'] && empty($o['is_cancelled'])): ?>
                                    <input type="checkbox" name="order_ids[]" value="<?= (int)$o['id'] ?>" <?= $cannotPrintUnpaid ? 'disabled title="Cannot print unpaid VET/J&T orders"' : '' ?>>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($o['created_at']) ?></td>
                            <td><?= htmlspecialchars($o['order_code']) ?></td>
                            <td>
                                <?php $orderProducts = $productMap[(int)$o['id']] ?? []; ?>
                                <?php if (empty($orderProducts)): ?>
                                    <span class="text-muted small">-</span>
                                <?php else: ?>
                                    <div class="small">
                                        <?php foreach ($orderProducts as $product): ?>
                                            <div><?= htmlspecialchars($product['name']) ?> x <?= number_format((float)$product['quantity'], 0) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $seenBrands = [];
                                foreach ($orderProducts as $product) {
                                    $bn = $product['brand_name'];
                                    $bc = $product['brand_color'];
                                    if ($bn !== '' && !isset($seenBrands[$bn])) {
                                        $seenBrands[$bn] = $bc;
                                    }
                                }
                                ?>
                                <?php if (empty($seenBrands)): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <?php foreach ($seenBrands as $bn => $bc): ?>
                                        <span class="badge rounded-pill mb-1" style="background:<?= htmlspecialchars($bc ?: '#6c757d') ?>;font-size:0.78rem;padding:3px 10px;font-weight:600;">
                                            <?= htmlspecialchars($bn) ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($o['customer_name']) ?></td>
                            <td><?= htmlspecialchars($o['seller_name']) ?></td>
                            <td><?= htmlspecialchars($o['delivery_type_name'] ?? '') ?></td>
                            <td>
                                <span class="badge <?= $o['status']==='paid'?'bg-success':'bg-warning text-dark' ?>">
                                    <?= strtoupper($o['status']) ?>
                                </span>
                            </td>
                            <td>$<?= number_format($o['total_amount'], 2) ?></td>
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
    </form>
</div>

<script>
(function() {
    function initPrintOrdersPage() {
        const checkAll = document.getElementById('checkAll');
        if (checkAll) {
            checkAll.addEventListener('change', () => {
                document.querySelectorAll('input[name="order_ids[]"]').forEach(cb => {
                    if (!cb.disabled) {
                        cb.checked = checkAll.checked;
                    }
                });
            });
        }

        const warning = document.getElementById('warning');
        if (warning) {
            setTimeout(() => {
                warning.style.display = 'none';
            }, 180000); // 3 minutes
        }

        const previewBtn = document.getElementById('previewBtn');
        const printForm = document.getElementById('printForm');
        const previewModalEl = document.getElementById('previewModal');

        if (!previewBtn || !printForm || !previewModalEl) return;

        function initializeModal() {
            if (typeof bootstrap === 'undefined') {
                setTimeout(initializeModal, 100);
                return;
            }

            const previewModal = new bootstrap.Modal(previewModalEl);

            previewBtn.addEventListener('click', () => {
                const checkedBoxes = document.querySelectorAll('input[name="order_ids[]"]:checked:not(:disabled)');

                if (checkedBoxes.length === 0) {
                    alert('Please select at least one order to print.');
                    return;
                }

                const itemsContainer = document.getElementById('previewItems');
                if (!itemsContainer) return;
                itemsContainer.innerHTML = '';

                const productTotals = {};

                checkedBoxes.forEach((checkbox) => {
                    const row = checkbox.closest('tr');
                    if (!row) return;
                    const itemsCell = row.querySelector('td:nth-child(5)');
                    if (!itemsCell) return;

                    const productDivs = itemsCell.querySelectorAll('.small > div');
                    productDivs.forEach(div => {
                        const text = div.textContent.trim();
                        const match = text.match(/^(.+?)\s+x\s+(\d+(?:\.\d+)?)$/);
                        if (match) {
                            const productName = match[1];
                            const qty = parseFloat(match[2]);
                            productTotals[productName] = (productTotals[productName] || 0) + qty;
                        }
                    });
                });

                let tableHtml = `
                    <div style="background: #f8f9fa; border-radius: 8px; padding: 0; overflow: hidden;">
                        <table class="table table-hover mb-0" style="margin: 0;">
                            <thead style="background-color: #e9ecef; border-bottom: 2px solid #dee2e6;">
                                <tr>
                                    <th style="padding: 12px 16px; font-size: 14px; font-weight: 600; color: #495057; text-transform: uppercase; letter-spacing: 0.5px;">Product Name</th>
                                    <th style="padding: 12px 16px; text-align: right; font-size: 14px; font-weight: 600; color: #495057; text-transform: uppercase; letter-spacing: 0.5px;">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                let rowCount = 0;
                for (const [productName, qty] of Object.entries(productTotals)) {
                    rowCount++;
                    const bgColor = rowCount % 2 === 0 ? '#ffffff' : '#f8f9fa';
                    tableHtml += `
                        <tr style="background-color: ${bgColor}; border-bottom: 1px solid #dee2e6;">
                            <td style="padding: 12px 16px; font-size: 15px; font-weight: 500; color: #333;">${escapeHtml(productName)}</td>
                            <td style="padding: 12px 16px; text-align: right; font-size: 15px; font-weight: 600; color: #0066cc;">x ${qty}</td>
                        </tr>
                    `;
                }
                tableHtml += `
                            </tbody>
                        </table>
                    </div>
                `;

                itemsContainer.innerHTML = tableHtml;
                const totalOrdersEl = document.getElementById('totalOrders');
                if (totalOrdersEl) {
                    totalOrdersEl.textContent = checkedBoxes.length;
                }

                previewModal.show();
            });

            const confirmBtn = document.getElementById('confirmPrintBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', () => {
                    previewModal.hide();
                    printForm.submit();
                });
            }

            const cancelBtn = document.getElementById('cancelPrintBtn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    previewModal.hide();
                });
            }
        }

        initializeModal();
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrintOrdersPage);
    } else {
        initPrintOrdersPage();
    }
})();
</script>

<!-- Print Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%); border: none;">
                <h5 class="modal-title" id="previewModalLabel" style="color: white; font-weight: 600; font-size: 18px;">
                    <i class="bi bi-box-seam me-2"></i>Print Preview - Review Items
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 24px; background-color: #fff;">
                <div style="margin-bottom: 20px;">
                    <h6 style="font-size: 16px; font-weight: 600; color: #333; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #e9ecef;">
                        <i class="bi bi-list me-2 text-primary"></i>Product Summary:
                    </h6>
                    <div id="previewItems" class="mb-4">
                        <!-- Items will be populated here by JavaScript -->
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%); border-radius: 10px; padding: 16px; border-left: 4px solid #0066cc;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 14px; font-weight: 600; color: #333;">Total Orders to Print:</span>
                        <span style="background-color: #0066cc; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;">
                            <span id="totalOrders">0</span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e9ecef; padding: 16px 24px; background-color: #f8f9fa;">
                <button type="button" id="cancelPrintBtn" class="btn" style="background-color: #6c757d; border: none; color: white; padding: 8px 24px; font-weight: 500;">
                    <i class="bi bi-x-circle me-2"></i>Cancel
                </button>
                <button type="button" id="confirmPrintBtn" class="btn" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); border: none; color: white; padding: 8px 24px; font-weight: 500;">
                    <i class="bi bi-check-circle me-2"></i>Confirm & Print
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
