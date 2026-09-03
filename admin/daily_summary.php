<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'daily_summary.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$deliveryByFilter = $_GET['delivery_by'] ?? '';
$search = trim($_GET['search'] ?? '');
$confirmFilter = $_GET['confirm'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 100;

// If no date specified, default to current month
$hasDateFilter = !empty($_GET['from']) || !empty($_GET['to']);
if (empty($from)) $from = date('Y-m-01');
if (empty($to)) $to = date('Y-m-d');

// Get unique delivery_by values for filter dropdown
$deliveryByOptions = [];
$stmtDelivery = $pdo->query("SELECT DISTINCT delivery_by FROM out_items WHERE delivery_by IS NOT NULL AND delivery_by != '' ORDER BY delivery_by");
if ($stmtDelivery) {
    $deliveryByOptions = $stmtDelivery->fetchAll(PDO::FETCH_COLUMN);
}

// Overall totals for period (all orders by created date)
$toNext = date('Y-m-d', strtotime($to . ' +1 day'));
$params = [$from, $toNext];

// One out_items row per inv (latest by id) to avoid duplicate counting
$deliveryJoin = ' LEFT JOIN product_entries pe ON pe.inv = o.order_code
    LEFT JOIN (
        SELECT oi1.inv, oi1.delivery_by FROM out_items oi1
        INNER JOIN (SELECT inv, MAX(id) AS mid FROM out_items GROUP BY inv) oi2
        ON oi1.inv = oi2.inv AND oi1.id = oi2.mid
    ) oi ON oi.inv = o.order_code';

// One print_job per order - LEFT JOIN to include unprinted orders (printed_at NULL)
$printJobJoin = ' LEFT JOIN (
        SELECT order_id, MAX(printed_at) as printed_at FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id';

// Extra joins for search/confirm filter (like order_management)
$ocfJoin = ' LEFT JOIN (
        SELECT oc.order_id, oc.confirm_status FROM order_confirmations oc
        JOIN (SELECT order_id, MAX(id) AS max_id FROM order_confirmations GROUP BY order_id) x ON x.max_id = oc.id
    ) ocf ON ocf.order_id = o.id';
$usersJoin = ' JOIN users u ON o.seller_id = u.id ';
$extraJoinForFilter = ($search !== '' || $confirmFilter !== '') ? $usersJoin . $ocfJoin : '';

// Date filter: use range so index on created_at can be used (DATE() prevents index use)
$dateFilterSql = ' AND o.created_at >= ? AND o.created_at < ?';

$deliveryFilterSql = '';
$deliveryTotalsParams = [
    'params' => $params,
    'clauses' => [],
];

if (!empty($deliveryByFilter)) {
    if ($deliveryByFilter === 'not_yet_prepare_items') {
        $deliveryFilterSql = ' AND pe.id IS NULL';
    } elseif ($deliveryByFilter === 'not_delivered') {
        $deliveryFilterSql = ' AND pe.id IS NOT NULL AND (oi.delivery_by IS NULL OR oi.delivery_by = "")';
    } else {
        $deliveryFilterSql = ' AND oi.delivery_by = ?';
        $deliveryTotalsParams['params'][] = $deliveryByFilter;
    }
}

// Search filter (like order_management)
$searchFilterSql = '';
if ($search !== '') {
    $searchFilterSql = ' AND (o.order_code LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR u.name LIKE ?)';
    $s = '%' . $search . '%';
    $deliveryTotalsParams['params'][] = $s;
    $deliveryTotalsParams['params'][] = $s;
    $deliveryTotalsParams['params'][] = $s;
    $deliveryTotalsParams['params'][] = $s;
}

// Confirm status filter (server-side, like order_management)
$confirmFilterSql = '';
if (!empty($confirmFilter)) {
    if ($confirmFilter === 'complete') {
        $confirmFilterSql = ' AND o.is_cancelled = 0 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") AND COALESCE(o.is_returned, 0) = 0 AND o.status NOT IN ("returned", "return") AND COALESCE(ocf.confirm_status, "") != "return"';
    } elseif ($confirmFilter === 'pending') {
        $confirmFilterSql = ' AND o.is_cancelled = 0 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") AND NOT (COALESCE(o.is_returned, 0) = 1 OR o.status IN ("returned", "return") OR COALESCE(ocf.confirm_status, "") = "return")';
    } elseif ($confirmFilter === 'return') {
        $confirmFilterSql = ' AND (o.is_returned = 1 OR o.status IN ("returned", "return") OR COALESCE(ocf.confirm_status, "") = "return")';
    } elseif ($confirmFilter === 'cancelled') {
        $confirmFilterSql = ' AND o.is_cancelled = 1';
    }
}

$sqlTotals = 'SELECT 
    COUNT(*) AS total_orders,
    SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_orders,
    SUM(CASE WHEN o.is_cancelled = 0 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS orders_paid,
    SUM(CASE WHEN o.is_cancelled = 0 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS orders_unpaid,
    SUM(CASE WHEN o.is_cancelled = 1 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS cancel_paid,
    SUM(CASE WHEN o.is_cancelled = 1 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS cancel_unpaid
  FROM orders o
  ' . $extraJoinForFilter . '
  ' . $printJobJoin . '
  ' . $deliveryJoin . '
  WHERE 1=1' . $dateFilterSql . $deliveryFilterSql . $searchFilterSql . $confirmFilterSql;
$stmt = $pdo->prepare($sqlTotals);
$stmt->execute($deliveryTotalsParams['params']);
$totals = $stmt->fetch() ?: [
    'total_orders' => 0,
    'cancelled_orders' => 0,
    'orders_paid' => 0,
    'orders_unpaid' => 0,
    'cancel_paid' => 0,
    'cancel_unpaid' => 0,
];

// Detailed orders with products for the period (only printed orders)
// Totals by confirm status: paid=1 or status=paid → complete; return → return; else pending
$statusCountSql = 'SELECT 
    COUNT(*) AS all_count,
    SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled_count,
    SUM(CASE WHEN o.is_cancelled = 0 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") AND (COALESCE(o.is_returned, 0) = 0 AND o.status NOT IN ("returned", "return")) AND COALESCE(ocf.confirm_status, "") != "return" THEN 1 ELSE 0 END) AS complete_count,
    SUM(CASE WHEN o.is_cancelled = 0 AND (COALESCE(o.is_returned, 0) = 1 OR o.status IN ("returned", "return") OR COALESCE(ocf.confirm_status, "") = "return") THEN 1 ELSE 0 END) AS return_count,
    SUM(CASE WHEN o.is_cancelled = 0 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") AND NOT (COALESCE(o.is_returned, 0) = 1 OR o.status IN ("returned", "return") OR COALESCE(ocf.confirm_status, "") = "return") THEN 1 ELSE 0 END) AS pending_count
  FROM orders o
  ' . $printJobJoin . '
  ' . $deliveryJoin . '
  LEFT JOIN (
        SELECT oc.order_id, oc.confirm_status
        FROM order_confirmations oc
        JOIN (
            SELECT order_id, MAX(id) AS max_id
            FROM order_confirmations
            GROUP BY order_id
        ) x ON x.max_id = oc.id
  ) ocf ON ocf.order_id = o.id
';
$statusCountSql .= ($search !== '' ? $usersJoin : '') . ' WHERE 1=1' . $dateFilterSql . $deliveryFilterSql . $searchFilterSql . $confirmFilterSql;
$stmt = $pdo->prepare($statusCountSql);
$stmt->execute($deliveryTotalsParams['params']);
$statusCounts = $stmt->fetch() ?: [
    'all_count' => 0,
    'cancelled_count' => 0,
    'complete_count' => 0,
    'return_count' => 0,
    'pending_count' => 0,
];

$statusCounts = [
    'all' => (int)($statusCounts['all_count'] ?? 0),
    'cancelled' => (int)($statusCounts['cancelled_count'] ?? 0),
    'complete' => (int)($statusCounts['complete_count'] ?? 0),
    'return' => (int)($statusCounts['return_count'] ?? 0),
    'pending' => (int)($statusCounts['pending_count'] ?? 0),
];

$sqlOrders = 'SELECT 
    o.id,
    o.order_code,
    DATE(o.created_at) as order_date,
    o.created_at,
    pj.printed_at,
    o.customer_name,
    o.phone AS customer_phone,
    u.name as seller_name,
    o.total_amount,
    o.discount,
    o.is_paid,
    o.status,
    o.is_cancelled,
    o.is_returned,
    o.updated_by,
    o.updated_at,
    editor.name as edited_by_name,
    COALESCE(o.payment_method, "") as payment_method,
    COALESCE(o.paid_note, "") as paid_note,
    COALESCE(o.cancel_note, "") as cancel_note,
    COALESCE(o.return_note, "") as return_note,
    CASE
      WHEN o.is_cancelled = 1 THEN "cancelled"
      WHEN o.is_returned = 1 OR o.status IN ("returned", "return") THEN "return"
      WHEN COALESCE(ocf.confirm_status, "") = "return" THEN "return"
      WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = "paid" THEN "complete"
      ELSE COALESCE(ocf.confirm_status, "pending")
    END as confirm_status,
    COALESCE(ocf.note, "") as confirm_note,
    CASE
      WHEN pe.id IS NULL THEN "Not yet prepare items"
      WHEN oi.delivery_by IS NULL OR oi.delivery_by = "" THEN "Not Delivered"
      ELSE oi.delivery_by
    END as delivery_by
  FROM orders o
  JOIN users u ON o.seller_id = u.id
  ' . $printJobJoin . '
  LEFT JOIN users editor ON o.updated_by = editor.id
  LEFT JOIN product_entries pe ON pe.inv = o.order_code
  LEFT JOIN (
        SELECT oc.order_id, oc.confirm_status, oc.note, oc.created_by, oc.created_at
        FROM order_confirmations oc
        JOIN (
            SELECT order_id, MAX(id) AS max_id
            FROM order_confirmations
            GROUP BY order_id
        ) x ON x.max_id = oc.id
  ) ocf ON ocf.order_id = o.id
  LEFT JOIN (
        SELECT oi1.inv, oi1.delivery_by FROM out_items oi1
        INNER JOIN (SELECT inv, MAX(id) AS mid FROM out_items GROUP BY inv) oi2
        ON oi1.inv = oi2.inv AND oi1.id = oi2.mid
    ) oi ON oi.inv = o.order_code
  WHERE 1=1' . $dateFilterSql . $deliveryFilterSql . $searchFilterSql . $confirmFilterSql;

$orderParams = $deliveryTotalsParams['params'];

$sqlOrders .= ' ORDER BY COALESCE(pj.printed_at, o.created_at) DESC';

// Count total orders for pagination (same WHERE as main query)
$countSql = 'SELECT COUNT(*) AS total FROM orders o
  JOIN users u ON o.seller_id = u.id
  ' . ($confirmFilter !== '' ? $ocfJoin : '') . '
  ' . $printJobJoin . '
  ' . $deliveryJoin . '
  WHERE 1=1' . $dateFilterSql . $deliveryFilterSql . $searchFilterSql . $confirmFilterSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($deliveryTotalsParams['params']);
$total_orders = (int)($countStmt->fetch()['total'] ?? 0);
$total_pages = $per_page > 0 ? max(1, (int)ceil($total_orders / $per_page)) : 1;
$offset = ($page - 1) * $per_page;
$start_item = $total_orders > 0 ? $offset + 1 : 0;
$end_item = min($offset + $per_page, $total_orders);

$sqlOrders .= ' LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset;

$stmt = $pdo->prepare($sqlOrders);
$stmt->execute($orderParams);
$orders = $stmt->fetchAll();

// Batch load order items (single query instead of N+1)
$orderItems = [];
if (!empty($orders)) {
    $orderIds = array_map(fn($o) => (int)$o['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtItems = $pdo->prepare("
        SELECT oi.order_id, p.name AS product_name, oi.quantity
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_id, p.name
    ");
    $stmtItems->execute($orderIds);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = (int)$row['order_id'];
        $orderItems[$oid][] = [
            'product_name' => $row['product_name'],
            'quantity' => $row['quantity'],
        ];
    }
}

// Per-seller/page breakdown for the period
$sqlPages = 'SELECT 
    u.name AS page_name,
    COUNT(o.id) AS total_orders,
    SUM(CASE WHEN o.is_cancelled = 1 THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN o.is_cancelled = 0 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS orders_paid,
    SUM(CASE WHEN o.is_cancelled = 0 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS orders_unpaid,
    SUM(CASE WHEN o.is_cancelled = 1 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS cancel_paid,
    SUM(CASE WHEN o.is_cancelled = 1 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid") THEN o.total_amount ELSE 0 END) AS cancel_unpaid
  FROM orders o
  JOIN users u ON o.seller_id = u.id
  ' . ($confirmFilter !== '' ? $ocfJoin : '') . '
  ' . $printJobJoin . '
  ' . $deliveryJoin . '
  WHERE 1=1' . $dateFilterSql . $deliveryFilterSql . $searchFilterSql . $confirmFilterSql . '
  GROUP BY u.id, u.name
  ORDER BY u.name';
$stmt = $pdo->prepare($sqlPages);
$stmt->execute($deliveryTotalsParams['params']);
$pages = $stmt->fetchAll();

// Totals for pages table
$pageTotalOrders   = 0;
$pageTotalCancelled = 0;
$pageTotalPaid     = 0.0;
$pageTotalUnpaid   = 0.0;
$pageCancelPaid    = 0.0;
$pageCancelUnpaid  = 0.0;
foreach ($pages as $p) {
    $pageTotalOrders   += (int)($p['total_orders'] ?? 0);
    $pageTotalCancelled += (int)($p['cancelled'] ?? 0);
    $pageTotalPaid     += (float)($p['orders_paid'] ?? 0);
    $pageTotalUnpaid   += (float)($p['orders_unpaid'] ?? 0);
    $pageCancelPaid    += (float)($p['cancel_paid'] ?? 0);
    $pageCancelUnpaid  += (float)($p['cancel_unpaid'] ?? 0);
}

include __DIR__ . '/../layout/header.php';
?>
<div class="container-fluid flex-grow-1 py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-calendar-day me-2"></i>
                            Daily Summary
                        </h5>
                        <div class="badge bg-white text-primary fs-6">
                            <i class="bi bi-list-ol me-1"></i>
                            <?= number_format($total_orders) ?> Orders
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filter Section -->
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body py-3">
    <form method="get" id="filterForm">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="search" id="searchInput" class="form-control form-control-sm" placeholder="Order code, customer, phone, seller..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="confirm" id="confirmFilter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="complete" <?= $confirmFilter === 'complete' ? 'selected' : '' ?>>Complete</option>
                    <option value="pending" <?= $confirmFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="return" <?= $confirmFilter === 'return' ? 'selected' : '' ?>>Return</option>
                    <option value="cancelled" <?= $confirmFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">From <small>(created)</small></label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= $hasDateFilter ? htmlspecialchars($_GET['from'] ?? '') : '' ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">To <small>(created)</small></label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= $hasDateFilter ? htmlspecialchars($_GET['to'] ?? '') : '' ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Delivery</label>
                <select name="delivery_by" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="not_yet_prepare_items" <?= $deliveryByFilter === 'not_yet_prepare_items' ? 'selected' : '' ?>>Not yet prepare items</option>
                    <option value="not_delivered" <?= $deliveryByFilter === 'not_delivered' ? 'selected' : '' ?>>Not Delivered</option>
                    <?php foreach ($deliveryByOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= $deliveryByFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted mb-1 d-block">&nbsp;</label>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                    <?php $today = date('Y-m-d'); $yesterday = date('Y-m-d', strtotime('-1 day')); ?>
                    <a href="daily_summary.php?from=<?= $today ?>&amp;to=<?= $today ?>" class="btn btn-sm btn-outline-secondary">Today</a>
                    <a href="daily_summary.php?from=<?= $yesterday ?>&amp;to=<?= $yesterday ?>" class="btn btn-sm btn-outline-secondary">Yesterday</a>
                    <a href="daily_summary.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                    <div class="vr d-none d-md-inline"></div>
                    <div class="d-flex gap-1">
                        <input type="month" id="monthPicker" class="form-control form-control-sm" style="max-width: 140px;">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="loadMonthBtn">Load</button>
                        <button type="button" id="printReportBtn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-pdf"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </form>
                        </div>
                    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-truck me-2"></i>Delivery Summary</strong>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#deliverySummaryCollapse" aria-expanded="true" aria-controls="deliverySummaryCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="deliverySummaryCollapse">
            <div class="card-body">
            <?php
            // Get delivery statistics for printed orders (exclude cancelled and returned)
            // Status: Not yet prepare items | Not Delivered | delivery_by
            $deliverySql = 'SELECT 
                delivery_status,
                COUNT(*) as count,
                SUM(total_amount) as total_amount,
                SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN payment_status = "unpaid" THEN total_amount ELSE 0 END) as unpaid_amount,
                COUNT(CASE WHEN payment_status = "paid" THEN 1 END) as paid_count,
                COUNT(CASE WHEN payment_status = "unpaid" THEN 1 END) as unpaid_count
              FROM (
                SELECT 
                    CASE
                      WHEN pe.id IS NULL THEN "Not yet prepare items"
                      WHEN oi.delivery_by IS NULL OR oi.delivery_by = "" THEN "Not Delivered"
                      ELSE oi.delivery_by
                    END as delivery_status,
                    o.total_amount,
                    CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = "paid" THEN "paid" ELSE "unpaid" END as payment_status
                FROM orders o
                INNER JOIN (SELECT order_id FROM print_jobs WHERE printed_at >= ? AND printed_at < ? GROUP BY order_id) pj ON pj.order_id = o.id
                LEFT JOIN product_entries pe ON pe.inv = o.order_code
                LEFT JOIN (
                    SELECT oi1.inv, oi1.delivery_by FROM out_items oi1
                    INNER JOIN (SELECT inv, MAX(id) AS mid FROM out_items GROUP BY inv) oi2
                    ON oi1.inv = oi2.inv AND oi1.id = oi2.mid
                ) oi ON oi.inv = o.order_code
                LEFT JOIN (
                    SELECT oc.order_id, oc.confirm_status FROM order_confirmations oc
                    JOIN (SELECT order_id, MAX(id) AS max_id FROM order_confirmations GROUP BY order_id) x ON x.max_id = oc.id
                ) ocf ON ocf.order_id = o.id
                WHERE o.is_cancelled = 0
                  AND COALESCE(o.is_returned, 0) = 0
                  AND o.status NOT IN ("returned", "return")
                  AND COALESCE(ocf.confirm_status, "") != "return"';
            
            // Add delivery filter if selected
            $deliveryParams = $params;
            if (!empty($deliveryByFilter)) {
                if ($deliveryByFilter === 'not_yet_prepare_items') {
                    $deliverySql .= ' AND pe.id IS NULL';
                } elseif ($deliveryByFilter === 'not_delivered') {
                    $deliverySql .= ' AND pe.id IS NOT NULL AND (oi.delivery_by IS NULL OR oi.delivery_by = "")';
                } else {
                    $deliverySql .= ' AND oi.delivery_by = ?';
                    $deliveryParams[] = $deliveryByFilter;
                }
            }
            
            $deliverySql .= ') as delivery_data
              GROUP BY delivery_status
              ORDER BY count DESC';
            
            $stmt = $pdo->prepare($deliverySql);
            $stmt->execute($deliveryParams);
            $deliveryStats = $stmt->fetchAll();
            
            // Get total orders for comparison (same filters, exclude cancelled and returned)
            $totalOrdersSql = 'SELECT COUNT(*) as total FROM orders o
                INNER JOIN (SELECT order_id FROM print_jobs WHERE printed_at >= ? AND printed_at < ? GROUP BY order_id) pj ON pj.order_id = o.id
                LEFT JOIN product_entries pe ON pe.inv = o.order_code
                LEFT JOIN (
                    SELECT oi1.inv, oi1.delivery_by FROM out_items oi1
                    INNER JOIN (SELECT inv, MAX(id) AS mid FROM out_items GROUP BY inv) oi2
                    ON oi1.inv = oi2.inv AND oi1.id = oi2.mid
                ) oi ON oi.inv = o.order_code
                LEFT JOIN (
                    SELECT oc.order_id, oc.confirm_status FROM order_confirmations oc
                    JOIN (SELECT order_id, MAX(id) AS max_id FROM order_confirmations GROUP BY order_id) x ON x.max_id = oc.id
                ) ocf ON ocf.order_id = o.id
                WHERE o.is_cancelled = 0
                  AND COALESCE(o.is_returned, 0) = 0
                  AND o.status NOT IN ("returned", "return")
                  AND COALESCE(ocf.confirm_status, "") != "return"';
            $totalOrdersParams = $params;
            if (!empty($deliveryByFilter)) {
                if ($deliveryByFilter === 'not_yet_prepare_items') {
                    $totalOrdersSql .= ' AND pe.id IS NULL';
                } elseif ($deliveryByFilter === 'not_delivered') {
                    $totalOrdersSql .= ' AND pe.id IS NOT NULL AND (oi.delivery_by IS NULL OR oi.delivery_by = "")';
                } else {
                    $totalOrdersSql .= ' AND oi.delivery_by = ?';
                    $totalOrdersParams[] = $deliveryByFilter;
                }
            }
            
            $stmt = $pdo->prepare($totalOrdersSql);
            $stmt->execute($totalOrdersParams);
            $totalOrdersInPeriod = $stmt->fetch()['total'];
            
            $totalDeliveries = 0;
            $totalDeliveryAmount = 0;
            $totalPaidAmount = 0;
            $totalUnpaidAmount = 0;
            $totalPaidCount = 0;
            $totalUnpaidCount = 0;
            $notYetPrepareCount = 0;
            $notDeliveredCount = 0;
            foreach ($deliveryStats as $stat) {
                $totalDeliveries += (int)$stat['count'];
                $totalDeliveryAmount += (float)$stat['total_amount'];
                $totalPaidAmount += (float)$stat['paid_amount'];
                $totalUnpaidAmount += (float)$stat['unpaid_amount'];
                $totalPaidCount += (int)$stat['paid_count'];
                $totalUnpaidCount += (int)$stat['unpaid_count'];
                if ($stat['delivery_status'] === 'Not yet prepare items') {
                    $notYetPrepareCount = (int)$stat['count'];
                } elseif ($stat['delivery_status'] === 'Not Delivered') {
                    $notDeliveredCount = (int)$stat['count'];
                }
            }
            $deliveredCount = $totalDeliveries - $notYetPrepareCount - $notDeliveredCount;
            ?>
            
            <div class="row mb-3 g-2">
                <div class="col-6 col-md-4 col-lg">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-box-seam fs-4 text-primary me-2"></i>
                        <div>
                            <div class="h6 mb-0">
                                <?php if (!empty($deliveryByFilter)): ?>
                                    Filtered Orders
                                <?php else: ?>
                                    Total Orders
                                <?php endif; ?>
                            </div>
                            <div class="h4 mb-0"><?= number_format($totalOrdersInPeriod) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-truck fs-4 text-success me-2"></i>
                        <div>
                            <div class="h6 mb-0">
                                <?php if (!empty($deliveryByFilter)): ?>
                                    <?= htmlspecialchars($deliveryByFilter) ?> Orders
                                <?php else: ?>
                                    Delivered Orders
                                <?php endif; ?>
                            </div>
                            <div class="h4 mb-0"><?= number_format($deliveredCount) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-box-seam fs-4 text-warning me-2"></i>
                        <div>
                            <div class="h6 mb-0">Not yet prepare</div>
                            <div class="h4 mb-0"><?= number_format($notYetPrepareCount) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-x-circle fs-4 text-danger me-2"></i>
                        <div>
                            <div class="h6 mb-0">Not Delivered</div>
                            <div class="h4 mb-0"><?= number_format($notDeliveredCount) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-currency-dollar fs-4 text-info me-2"></i>
                        <div>
                            <div class="h6 mb-0">
                                <?php if (!empty($deliveryByFilter)): ?>
                                    <?= htmlspecialchars($deliveryByFilter) ?> Amount
                                <?php else: ?>
                                    Total Amount
                                <?php endif; ?>
                            </div>
                            <div class="h4 mb-0">$<?= number_format($totalDeliveryAmount, 2) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($deliveryStats)): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Delivery Method</th>
                                <th class="text-center">Count</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Paid</th>
                                <th class="text-center">Unpaid</th>
                                <th class="text-center">%</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveryStats as $stat): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($stat['delivery_status']) ?>
                                        <?php if ($stat['delivery_status'] === 'Not yet prepare items'): ?>
                                            <i class="bi bi-box-seam text-warning ms-1"></i>
                                        <?php elseif ($stat['delivery_status'] === 'Not Delivered'): ?>
                                            <i class="bi bi-x-circle text-danger ms-1"></i>
                                        <?php else: ?>
                                            <i class="bi bi-truck text-success ms-1"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= number_format($stat['count']) ?></td>
                                    <td class="text-end">
                                        $<?= number_format($stat['total_amount'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="text-success fw-bold">
                                            $<?= number_format($stat['paid_amount'], 2) ?>
                                            <small class="d-block text-muted">(<?= number_format($stat['paid_count']) ?>)</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="text-danger fw-bold">
                                            $<?= number_format($stat['unpaid_amount'], 2) ?>
                                            <small class="d-block text-muted">(<?= number_format($stat['unpaid_count']) ?>)</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?= $totalOrdersInPeriod > 0 ? round(($stat['count'] / $totalOrdersInPeriod) * 100, 1) : 0 ?>%
                                    </td>
                                    <td class="text-center">
                                        <?php if ($stat['delivery_status'] === 'Not yet prepare items'): ?>
                                            <span class="badge bg-warning text-dark">Not Prepared</span>
                                        <?php elseif ($stat['delivery_status'] === 'Not Delivered'): ?>
                                            <span class="badge bg-danger">Not Delivered</span>
                                        <?php elseif ((int)$stat['count'] > 100): ?>
                                            <span class="badge bg-warning text-dark">High Volume</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Delivered</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th>Total</th>
                                <th class="text-center"><?= number_format($totalOrdersInPeriod) ?></th>
                                <th class="text-end">
                                    $<?= number_format($totalDeliveryAmount, 2) ?>
                                </th>
                                <th class="text-center">
                                    <div class="text-success fw-bold">
                                        $<?= number_format($totalPaidAmount, 2) ?>
                                        <small class="d-block text-muted">(<?= number_format($totalPaidCount) ?>)</small>
                                    </div>
                                </th>
                                <th class="text-center">
                                    <div class="text-danger fw-bold">
                                        $<?= number_format($totalUnpaidAmount, 2) ?>
                                        <small class="d-block text-muted">(<?= number_format($totalUnpaidCount) ?>)</small>
                                    </div>
                                </th>
                                <th class="text-center">100%</th>
                                <th class="text-center">
                                    <span class="badge bg-info">All Methods</span>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center mb-0">No delivery data available for this period.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Return Summary - same structure as Delivery Summary but only returned orders
    $returnSql = 'SELECT 
        delivery_by,
        COUNT(*) as count,
        SUM(total_amount) as total_amount,
        SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE 0 END) as paid_amount,
        SUM(CASE WHEN payment_status = "unpaid" THEN total_amount ELSE 0 END) as unpaid_amount,
        COUNT(CASE WHEN payment_status = "paid" THEN 1 END) as paid_count,
        COUNT(CASE WHEN payment_status = "unpaid" THEN 1 END) as unpaid_count
      FROM (
        SELECT 
            COALESCE(oi.delivery_by, "Not Delivered") as delivery_by,
            o.total_amount,
            CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = "paid" THEN "paid" ELSE "unpaid" END as payment_status
        FROM orders o
        INNER JOIN (SELECT order_id FROM print_jobs WHERE printed_at >= ? AND printed_at < ? GROUP BY order_id) pj ON pj.order_id = o.id
        LEFT JOIN (
            SELECT oi1.inv, oi1.delivery_by FROM out_items oi1
            INNER JOIN (SELECT inv, MAX(id) AS mid FROM out_items GROUP BY inv) oi2
            ON oi1.inv = oi2.inv AND oi1.id = oi2.mid
        ) oi ON oi.inv = o.order_code
        LEFT JOIN (
            SELECT oc.order_id, oc.confirm_status FROM order_confirmations oc
            JOIN (SELECT order_id, MAX(id) AS max_id FROM order_confirmations GROUP BY order_id) x ON x.max_id = oc.id
        ) ocf ON ocf.order_id = o.id
        WHERE (o.is_returned = 1 OR o.status IN ("returned", "return") OR COALESCE(ocf.confirm_status, "") = "return")';
    $returnParams = $params;
    if (!empty($deliveryByFilter)) {
        if ($deliveryByFilter === 'not_delivered') {
            $returnSql .= ' AND (oi.delivery_by IS NULL OR oi.delivery_by = "")';
        } else {
            $returnSql .= ' AND oi.delivery_by = ?';
            $returnParams[] = $deliveryByFilter;
        }
    }
    $returnSql .= ') as return_data GROUP BY delivery_by ORDER BY count DESC';
    $stmt = $pdo->prepare($returnSql);
    $stmt->execute($returnParams);
    $returnStats = $stmt->fetchAll();
    $totalReturnOrders = array_sum(array_column($returnStats, 'count'));
    $totalReturnAmount = array_sum(array_column($returnStats, 'total_amount'));
    ?>

    <div class="card shadow-sm mb-3 mt-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-warning text-dark">
            <strong><i class="bi bi-arrow-return-left me-2"></i>Return Summary</strong>
            <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#returnSummaryCollapse" aria-expanded="true" aria-controls="returnSummaryCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="returnSummaryCollapse">
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-arrow-return-left fs-4 text-warning me-2"></i>
                            <div>
                                <div class="h6 mb-0">Total Return Orders</div>
                                <div class="h4 mb-0"><?= number_format($totalReturnOrders) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-currency-dollar fs-4 text-info me-2"></i>
                            <div>
                                <div class="h6 mb-0">Total Return Amount</div>
                                <div class="h4 mb-0">$<?= number_format($totalReturnAmount, 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($returnStats)): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Delivery Method</th>
                                <th class="text-center">Count</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Paid</th>
                                <th class="text-center">Unpaid</th>
                                <th class="text-center">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returnStats as $stat): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($stat['delivery_by']) ?>
                                    <?php if ($stat['delivery_by'] === 'Not Delivered'): ?>
                                        <i class="bi bi-x-circle text-danger ms-1"></i>
                                    <?php else: ?>
                                        <i class="bi bi-truck text-success ms-1"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= number_format($stat['count']) ?></td>
                                <td class="text-end">$<?= number_format($stat['total_amount'], 2) ?></td>
                                <td class="text-center">
                                    <div class="text-success fw-bold">
                                        $<?= number_format($stat['paid_amount'], 2) ?>
                                        <small class="d-block text-muted">(<?= number_format($stat['paid_count']) ?>)</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="text-danger fw-bold">
                                        $<?= number_format($stat['unpaid_amount'], 2) ?>
                                        <small class="d-block text-muted">(<?= number_format($stat['unpaid_count']) ?>)</small>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?= $totalReturnOrders > 0 ? round(($stat['count'] / $totalReturnOrders) * 100, 1) : 0 ?>%
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th>Total</th>
                                <th class="text-center"><?= number_format($totalReturnOrders) ?></th>
                                <th class="text-end">$<?= number_format($totalReturnAmount, 2) ?></th>
                                <th class="text-center">—</th>
                                <th class="text-center">—</th>
                                <th class="text-center">100%</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center mb-0">No return orders in this period.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center">
                    <strong><i class="bi bi-list-ul me-2"></i>Daily Orders</strong>
                    <div class="d-flex flex-wrap gap-2 small" id="statusCounts">
                        <span><strong>All:</strong> <span id="countAll"><?= (int)($statusCounts['all'] ?? 0) ?></span></span>
                        <span><strong>Complete:</strong> <span id="countComplete"><?= (int)($statusCounts['complete'] ?? 0) ?></span></span>
                        <span><strong>Pending:</strong> <span id="countPending"><?= (int)($statusCounts['pending'] ?? 0) ?></span></span>
                        <span><strong>Return:</strong> <span id="countReturn"><?= (int)($statusCounts['return'] ?? 0) ?></span></span>
                        <span><strong>Cancelled:</strong> <span id="countCancelled"><?= (int)($statusCounts['cancelled'] ?? 0) ?></span></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full mb-0">
                <table class="table table-striped table-hover align-middle mb-0" id="ordersTable">
                    <thead class="table-dark">
                    <tr>
                        <th>No</th>
                        <th>Order Code</th>
                        <th>Created Date</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Products</th>
                        <th>Seller</th>
                        <th>Delivery By</th>
                        <th>Amount</th>
                        <th>Print Status</th>
                        <th>Confirm</th>
                        <th>Payment</th>
                        <th>Note</th>
                        <th>Updated Date</th>
                        <th>Updated By</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="16" class="text-center py-4">No orders for this period.</td></tr>
                    <?php else: ?>
                        <?php $no = $start_item; foreach ($orders as $order): ?>
                            <?php
                                $items = $orderItems[$order['id']] ?? [];
                                $isCancelledOrReturned = !empty($order['is_cancelled']) || !empty($order['is_returned']) || in_array($order['status'] ?? '', ['returned', 'return']);
                            ?>
                            <tr class="order-row <?= $isCancelledOrReturned ? 'table-danger' : '' ?>" data-order-id="<?= (int)$order['id'] ?>" data-status="<?= htmlspecialchars($order['status']) ?>" data-confirm-status="<?= htmlspecialchars($order['confirm_status'] ?? '') ?>">
                                <td><?= $no++; ?></td>
                                <td><strong><?= htmlspecialchars($order['order_code']) ?></strong></td>
                                <td><?= date('M j, Y H:i', strtotime($order['created_at'])) ?></td>
                                <td><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($order['customer_phone'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if (!empty($items)): ?>
                                        <?php foreach ($items as $it): ?>
                                            <div class="small mb-1">
                                                <?= htmlspecialchars($it['product_name']) ?>
                                                <span class="text-muted">x<?= rtrim(rtrim(number_format((float)$it['quantity'], 2, '.', ''), '0'), '.') ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">No products</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($order['seller_name'] ?: 'N/A') ?></td>
                                <td>
                                    <?php $deliveryBy = $order['delivery_by'] ?? ''; ?>
                                    <?php if ($deliveryBy === 'Not yet prepare items'): ?>
                                        <span class="badge bg-warning text-dark">Not yet prepare items</span>
                                    <?php elseif ($deliveryBy === 'Not Delivered'): ?>
                                        <span class="badge bg-danger">Not Delivered</span>
                                    <?php elseif (!empty($deliveryBy)): ?>
                                        <span class="badge bg-info"><?= htmlspecialchars($deliveryBy) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>$<?= number_format($order['total_amount'], 2) ?></strong>
                                    <?php if (!empty($order['discount']) && (float)$order['discount'] > 0): ?>
                                        <br><small class="text-muted">Discount: $<?= number_format($order['discount'], 2) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($order['printed_at'])): ?>
                                        <span class="badge bg-success">Printed</span>
                                        <br><small class="text-muted"><?= date('M j, H:i', strtotime($order['printed_at'])) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Not Printed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($order['is_cancelled'])): ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php elseif (($order['confirm_status'] ?? '') === 'return'): ?>
                                        <span class="badge bg-danger">Return</span>
                                    <?php elseif (($order['confirm_status'] ?? '') === 'complete'): ?>
                                        <span class="badge bg-success">Complete</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($order['is_cancelled'])): ?>
                                        <span class="badge bg-danger">Cancelled</span>
                                        <?php if (!empty($order['is_paid'])): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unpaid</span>
                                        <?php endif; ?>
                                    <?php elseif (!empty($order['is_returned']) || in_array($order['status'] ?? '', ['returned', 'return'])): ?>
                                        <span class="badge bg-warning text-dark">Returned</span>
                                        <?php if (!empty($order['is_paid'])): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unpaid</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!empty($order['is_paid'])): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Unpaid</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $note = '';
                                    if (!empty($order['is_returned']) && !empty($order['return_note'])) {
                                        $note = htmlspecialchars($order['return_note']);
                                        $displayNote = strlen($note) > 30 ? substr($note, 0, 30) . '...' : $note;
                                        echo '<span class="badge bg-warning text-dark small">' . $displayNote . '</span>';
                                    } elseif (!empty($order['is_cancelled']) && !empty($order['cancel_note'])) {
                                        $note = htmlspecialchars($order['cancel_note']);
                                        $displayNote = strlen($note) > 30 ? substr($note, 0, 30) . '...' : $note;
                                        echo '<span class="badge bg-danger small">' . $displayNote . '</span>';
                                    } elseif (!empty($order['is_paid']) && (!empty($order['payment_method']) || !empty($order['paid_note']))) {
                                        $paymentMethod = !empty($order['payment_method']) ? htmlspecialchars($order['payment_method']) : '';
                                        $paidNote = !empty($order['paid_note']) ? htmlspecialchars($order['paid_note']) : '';
                                        $fullNote = (!empty($paymentMethod) && !empty($paidNote)) ? $paymentMethod . ': ' . $paidNote : ($paymentMethod ?: $paidNote);
                                        $displayNote = strlen($fullNote) > 50 ? substr($fullNote, 0, 50) . '...' : $fullNote;
                                        echo '<span class="text-muted small">' . $displayNote . '</span>';
                                    } else {
                                        echo '<span class="text-muted small">-</span>';
                                    }
                                    ?>
                                </td>
                                <td><?= date('M j, Y H:i', strtotime($order['updated_at'])) ?></td>
                                <td><?= htmlspecialchars($order['edited_by_name'] ?: 'N/A') ?></td>
                                <td>
                                    <a href="../receipt.php?id=<?= (int)$order['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info" title="View Receipt">
                                        <i class="bi bi-receipt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="row mt-3 mx-3 mb-3">
                    <div class="col-12">
                        <nav aria-label="Order pagination">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="text-muted small">
                                    Showing <?= number_format($start_item) ?> to <?= number_format($end_item) ?> of <?= number_format($total_orders) ?> orders
                                    <?php if ($total_orders > $per_page): ?>
                                        (Page <?= $page ?> of <?= $total_pages ?>)
                                    <?php endif; ?>
                                </div>
                                <ul class="pagination pagination-sm mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                                <i class="bi bi-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link"><i class="bi bi-chevron-left"></i> Previous</span>
                                        </li>
                                    <?php endif; ?>
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    if ($start_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                                        </li>
                                        <?php if ($start_page > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                                Next <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">Next <i class="bi bi-chevron-right"></i></span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const monthPicker = document.getElementById('monthPicker');
        const loadMonthBtn = document.getElementById('loadMonthBtn');
        const printReportBtn = document.getElementById('printReportBtn');
        
        // Month picker helper
        if (loadMonthBtn && monthPicker) {
            loadMonthBtn.addEventListener('click', function() {
                const value = monthPicker.value;
                if (!value) {
                    alert('Please select a month.');
                    return;
                }
                const [year, monthNum] = value.split('-');
                const startDate = `${year}-${monthNum}-01`;
                const endDate = new Date(year, parseInt(monthNum), 0);
                const lastDay = `${year}-${monthNum}-${String(endDate.getDate()).padStart(2, '0')}`;
                document.querySelector('input[name="from"]').value = startDate;
                document.querySelector('input[name="to"]').value = lastDay;
                const filterForm = document.getElementById('filterForm');
                if (filterForm) {
                    filterForm.submit();
                }
            });
        }

        if (printReportBtn) {
            printReportBtn.addEventListener('click', function(event) {
                event.preventDefault();
                window.print();
            });
        }

        // Search: Enter key submits form (server-side filter like order_management)
        if (searchInput) {
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') document.getElementById('filterForm').submit();
            });
        }
    });
    </script>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
