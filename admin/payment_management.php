
<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role_or_permission(['admin'], 'payment_management.view');
require_once __DIR__ . '/../config.php';

$pdo = get_db_connection();

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$payment_method_filter = $_GET['payment_method'] ?? '';

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$scan_date_from = $_GET['scan_date_from'] ?? '';
$scan_date_to = $_GET['scan_date_to'] ?? '';

$delivery_by_filter = $_GET['delivery_by'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 100;
$deliveryByOptions = [];
$stmtDelivery = $pdo->query("SELECT DISTINCT delivery_by FROM out_items WHERE delivery_by IS NOT NULL AND delivery_by != '' ORDER BY delivery_by");
if ($stmtDelivery) {
    $deliveryByOptions = $stmtDelivery->fetchAll(PDO::FETCH_COLUMN);
}

// Get payment note options from database
$paymentNoteOptions = [];
$stmtNotes = $pdo->query("SELECT id, option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order ASC, option_text ASC");
if ($stmtNotes) {
    $paymentNoteOptions = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);
}

$paymentMethodOptions = [];
foreach ($paymentNoteOptions as $option) {
    $optionText = trim((string)($option['option_text'] ?? ''));
    if ($optionText !== '') {
        $paymentMethodOptions[] = $optionText;
    }
}

$latestOutItemsSql = "
    SELECT oi1.inv, oi1.delivery_by, oi1.date_time
    FROM out_items oi1
    INNER JOIN (
        SELECT inv, MAX(id) AS mid
        FROM out_items
        GROUP BY inv
    ) oi2 ON oi1.inv = oi2.inv AND oi1.id = oi2.mid
";

$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(o.order_code LIKE ? OR o.id LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($status_filter) {
    switch ($status_filter) {
        case 'paid':
            $where_conditions[] = "o.is_paid = 1 AND o.is_cancelled = 0 AND o.is_returned = 0";
            break;
        case 'unpaid':
            $where_conditions[] = "o.is_paid = 0 AND o.is_cancelled = 0 AND o.is_returned = 0";
            break;
        case 'cancelled':
            $where_conditions[] = "o.is_cancelled = 1";
            break;
        case 'returned':
            $where_conditions[] = "o.is_returned = 1";
            break;
    }
}

if ($payment_method_filter) {
    if ($payment_method_filter === '__empty__') {
        $where_conditions[] = "(o.payment_method IS NULL OR o.payment_method = '')";
    } else {
        $where_conditions[] = "o.payment_method = ?";
        $params[] = $payment_method_filter;
    }
}


if ($date_from) {
    $where_conditions[] = "o.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to) {
    $where_conditions[] = "o.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}
// Scan date filter (subquery column)
if ($scan_date_from) {
    $where_conditions[] = "loi.date_time >= ?";
    $params[] = $scan_date_from . ' 00:00:00';
}
if ($scan_date_to) {
    $where_conditions[] = "loi.date_time <= ?";
    $params[] = $scan_date_to . ' 23:59:59';
}

if ($delivery_by_filter) {
    if ($delivery_by_filter === 'not_delivered') {
        $where_conditions[] = "NOT EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.delivery_by IS NOT NULL AND oi.delivery_by != '')";
    } else {
        $where_conditions[] = "EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.delivery_by = ?)";
        $params[] = $delivery_by_filter;
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);
$offset = ($page - 1) * $per_page;
$limit_clause = "LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare("
    SELECT
        o.id, o.order_code, pj.printed_at AS print_date, o.updated_at, o.customer_name, o.phone,
        o.total_amount, o.discount, o.is_paid, o.is_cancelled, o.is_returned, o.payment_method, o.paid_note,
        o.cancel_note, o.return_note, o.status, o.seller_id, o.updated_by,
        u.name as seller_name,
        updater.name as updated_by_name,
        loi.delivery_by AS delivery_by,
        o.location AS location,
        loi.date_time AS scan_date
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN users updater ON o.updated_by = updater.id
    LEFT JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id
    LEFT JOIN ($latestOutItemsSql) loi ON loi.inv = o.order_code
    $where_clause
    ORDER BY pj.printed_at DESC, o.created_at DESC
    $limit_clause
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$count_sql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN users u ON o.seller_id = u.id LEFT JOIN users updater ON o.updated_by = updater.id LEFT JOIN ($latestOutItemsSql) loi ON loi.inv = o.order_code $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_orders = $count_stmt->fetch()['total'];

$total_pages = ceil($total_orders / $per_page);
$start_item = ($page - 1) * $per_page + 1;
$end_item = min($page * $per_page, $total_orders);

$page_title = "Payment Management";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Order System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<?php include __DIR__ . '/../layout/header.php'; ?>



<div class="container-fluid flex-grow-1 py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-cash-coin me-2"></i>
                            Payment Management
                        </h5>
                        <div class="badge bg-white text-primary fs-6">
                            <i class="bi bi-list-ol me-1"></i>
                            <?= number_format($total_orders) ?> Orders
                        </div>
                    </div>
                </div>
                <div class="card-body">

                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body">
                            <form method="GET">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-search me-1"></i>Search Orders
                                        </label>
                                        <input type="text" class="form-control" name="search" placeholder="Order code, customer, phone..." value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-funnel me-1"></i>Status
                                        </label>
                                        <select class="form-select" name="status">
                                            <option value="">All Status</option>
                                            <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>> Paid</option>
                                            <option value="unpaid" <?= $status_filter === 'unpaid' ? 'selected' : '' ?>> Unpaid</option>
                                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>> Cancelled</option>
                                            <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>> Returned</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-credit-card me-1"></i>Payment Method
                                        </label>
                                        <select class="form-select" name="payment_method">
                                            <option value="">All</option>
                                            <option value="__empty__" <?= $payment_method_filter === '__empty__' ? 'selected' : '' ?>>No Payment Method</option>
                                            <?php foreach ($paymentMethodOptions as $option): ?>
                                                <option value="<?= htmlspecialchars($option) ?>" <?= $payment_method_filter === $option ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($option) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-calendar-range me-1"></i>Order Date From
                                        </label>
                                        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-calendar-range me-1"></i>Order Date To
                                        </label>
                                        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-calendar-range me-1"></i>Scan Date From
                                        </label>
                                        <input type="date" class="form-control" name="scan_date_from" value="<?= htmlspecialchars($scan_date_from ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-calendar-range me-1"></i>Scan Date To
                                        </label>
                                        <input type="date" class="form-control" name="scan_date_to" value="<?= htmlspecialchars($scan_date_to ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-truck me-1"></i>Delivery By
                                        </label>
                                        <select class="form-select" name="delivery_by">
                                            <option value="">All</option>
                                            <option value="not_delivered" <?= $delivery_by_filter === 'not_delivered' ? 'selected' : '' ?>>Not Delivered</option>
                                            <?php foreach ($deliveryByOptions as $option): ?>
                                                <option value="<?= htmlspecialchars($option) ?>" <?= $delivery_by_filter === $option ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($option) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search me-1"></i>Search
                                            </button>
                                            <a href="payment_management.php" class="btn btn-outline-secondary">
                                                <i class="bi bi-arrow-clockwise me-1"></i>Reset
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Delivery Summary Section (moved after search/filter) -->
                    <div class="mb-4">
                        <div class="card shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><i class="bi bi-truck me-2"></i>Delivery Summary</strong>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-primary" onclick="printTable('deliverySummaryTable')"><i class="bi bi-printer"></i> Print</button>
                                    <button class="btn btn-sm btn-outline-success" onclick="exportTableToExcel('deliverySummaryTable', 'delivery_summary')"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#deliverySummaryCollapse" aria-expanded="true" aria-controls="deliverySummaryCollapse">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="collapse show" id="deliverySummaryCollapse">
                                <div class="card-body">
                                <?php
                                // Delivery summary for payment management
                                // Build delivery summary filters (same as main order list)
                                $deliverySummaryWhere = [];
                                $deliverySummaryParams = [];
                                if ($search) {
                                    $deliverySummaryWhere[] = "(o.order_code LIKE ? OR o.id LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)";
                                    $search_param = "%$search%";
                                    $deliverySummaryParams[] = $search_param;
                                    $deliverySummaryParams[] = $search_param;
                                    $deliverySummaryParams[] = $search_param;
                                    $deliverySummaryParams[] = $search_param;
                                }
                                if ($status_filter) {
                                    switch ($status_filter) {
                                        case 'paid':
                                            $deliverySummaryWhere[] = "o.is_paid = 1 AND o.is_cancelled = 0 AND o.is_returned = 0";
                                            break;
                                        case 'unpaid':
                                            $deliverySummaryWhere[] = "o.is_paid = 0 AND o.is_cancelled = 0 AND o.is_returned = 0";
                                            break;
                                        case 'cancelled':
                                            $deliverySummaryWhere[] = "o.is_cancelled = 1";
                                            break;
                                        case 'returned':
                                            $deliverySummaryWhere[] = "o.is_returned = 1";
                                            break;
                                    }
                                }
                                if ($payment_method_filter) {
                                    if ($payment_method_filter === '__empty__') {
                                        $deliverySummaryWhere[] = "(o.payment_method IS NULL OR o.payment_method = '')";
                                    } else {
                                        $deliverySummaryWhere[] = "o.payment_method = ?";
                                        $deliverySummaryParams[] = $payment_method_filter;
                                    }
                                }
                                if ($date_from) {
                                    $deliverySummaryWhere[] = "o.created_at >= ?";
                                    $deliverySummaryParams[] = $date_from . ' 00:00:00';
                                }
                                if ($date_to) {
                                    $deliverySummaryWhere[] = "o.created_at <= ?";
                                    $deliverySummaryParams[] = $date_to . ' 23:59:59';
                                }
                                if ($scan_date_from) {
                                    $deliverySummaryWhere[] = "oi.date_time >= ?";
                                    $deliverySummaryParams[] = $scan_date_from . ' 00:00:00';
                                }
                                if ($scan_date_to) {
                                    $deliverySummaryWhere[] = "oi.date_time <= ?";
                                    $deliverySummaryParams[] = $scan_date_to . ' 23:59:59';
                                }
                                if ($delivery_by_filter) {
                                    if ($delivery_by_filter === 'not_delivered') {
                                        $deliverySummaryWhere[] = "NOT EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.delivery_by IS NOT NULL AND oi.delivery_by != '')";
                                    } else {
                                        $deliverySummaryWhere[] = "EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.delivery_by = ?)";
                                        $deliverySummaryParams[] = $delivery_by_filter;
                                    }
                                }
                                $deliverySummaryWhereClause = $deliverySummaryWhere ? ('WHERE ' . implode(' AND ', $deliverySummaryWhere)) : '';
                                $deliverySummarySql = 'SELECT 
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
                                          WHEN oi.delivery_by IS NULL OR oi.delivery_by = "" THEN "Not Delivered"
                                          ELSE oi.delivery_by
                                        END as delivery_status,
                                        o.total_amount,
                                        CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = "paid" THEN "paid" ELSE "unpaid" END as payment_status
                                    FROM orders o
                                    LEFT JOIN (' . $latestOutItemsSql . ') oi ON oi.inv = o.order_code
                                    ' . $deliverySummaryWhereClause . '
                                ) as delivery_data
                                  GROUP BY delivery_status
                                  ORDER BY count DESC';
                                $deliverySummaryStmt = $pdo->prepare($deliverySummarySql);
                                $deliverySummaryStmt->execute($deliverySummaryParams);
                                $deliverySummary = $deliverySummaryStmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                <div class="table-responsive">
                                    <table class="table table-sm" id="deliverySummaryTable">
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
                                            <?php
                                            $total_count = 0;
                                            $total_amount = 0;
                                            $total_paid = 0;
                                            $total_unpaid = 0;
                                            $total_paid_count = 0;
                                            $total_unpaid_count = 0;
                                            foreach ($deliverySummary as $stat):
                                                $total_count += $stat['count'];
                                                $total_amount += $stat['total_amount'];
                                                $total_paid += $stat['paid_amount'];
                                                $total_unpaid += $stat['unpaid_amount'];
                                                $total_paid_count += $stat['paid_count'];
                                                $total_unpaid_count += $stat['unpaid_count'];
                                            ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($stat['delivery_status']) ?></td>
                                                    <td class="text-center"><?= number_format($stat['count']) ?></td>
                                                    <td class="text-end">$<?= number_format($stat['total_amount'], 2) ?></td>
                                                    <td class="text-center text-success">$<?= number_format($stat['paid_amount'], 2) ?> <small>(<?= $stat['paid_count'] ?>)</small></td>
                                                    <td class="text-center text-danger">$<?= number_format($stat['unpaid_amount'], 2) ?> <small>(<?= $stat['unpaid_count'] ?>)</small></td>
                                                    <td class="text-center">
                                                        <?php
                                                        $percent = ($stat['total_amount'] > 0 && $deliverySummary[0]['total_amount'] > 0)
                                                            ? round(($stat['total_amount'] / $deliverySummary[0]['total_amount']) * 100, 1)
                                                            : 0;
                                                        echo $percent . '%';
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr style="font-weight:bold; background:#f8f9fa;">
                                                <td>Total</td>
                                                <td class="text-center"><?= number_format($total_count) ?></td>
                                                <td class="text-end">$<?= number_format($total_amount, 2) ?></td>
                                                <td class="text-center text-success">$<?= number_format($total_paid, 2) ?> <small>(<?= $total_paid_count ?>)</small></td>
                                                <td class="text-center text-danger">$<?= number_format($total_unpaid, 2) ?> <small>(<?= $total_unpaid_count ?>)</small></td>
                                                <td></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <div class="d-flex justify-content-end mb-2 gap-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="printTable('ordersTable')"><i class="bi bi-printer"></i> Print All</button>
                            <button class="btn btn-sm btn-outline-success" onclick="exportTableToExcel('ordersTable', 'orders')"><i class="bi bi-file-earmark-excel"></i> Excel All</button>
                            <button class="btn btn-sm btn-primary" onclick="printCheckedOrders()"><i class="bi bi-printer"></i> Print Checked</button>
                            <button class="btn btn-sm btn-success" onclick="exportCheckedOrdersToExcel()"><i class="bi bi-file-earmark-excel"></i> Excel Checked</button>
                            <button class="btn btn-sm btn-warning" id="sendToTelegramBtn" onclick="sendCheckedOrdersToTelegram()"><i class="bi bi-send"></i> Send to Telegram</button>
                        </div>
                        <table class="table table-striped table-hover" id="ordersTable">
                            <thead class="table-dark">
                                <tr>
                                    <th><input type="checkbox" id="selectAllOrders" onclick="toggleAllOrders(this)"></th>
                                    <th>No</th>
                                    <th>Order Code</th>
                                    <th>Print Date</th>
                                    <th>Scan Date</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Location</th>
                                    <th>Amount</th>
                                    <th>Delivery By</th>
                                    <!-- Discount column removed -->
                                    <th>Payment Status</th>
                                    <th>Payment Method</th>
                                    <th>Paid Note</th>
                                    <!-- Updated Date column removed -->
                                    <!-- Updated By column removed -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = $start_item;
                                $total_order_amount = 0;
                                foreach ($orders as $order):
                                    $total_order_amount += $order['total_amount'];
                                ?>
                                    <tr>
                                        <td><input type="checkbox" class="order-checkbox" value="<?= htmlspecialchars($order['id']) ?>"></td>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($order['order_code']) ?></strong></td>
                                        <td><?= $order['print_date'] ? date('M j, Y H:i', strtotime($order['print_date'])) : '<span style="color:#e53935;font-weight:bold;">N/A</span>' ?></td>
                                        <td><?= $order['scan_date'] ? date('M j, Y H:i', strtotime($order['scan_date'])) : '<span style="color:#e53935;font-weight:bold;">N/A</span>' ?></td>
                                        <td><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($order['phone'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($order['location'] ?: '-') ?></td>
                                        <td><strong>$<?= number_format($order['total_amount'], 2) ?></strong></td>
                                        <td>
                                            <?php 
                                            $deliveryBy = $order['delivery_by'] ?? '';
                                            if (!empty($deliveryBy)) {
                                                echo '<span class="badge bg-info">' . htmlspecialchars($deliveryBy) . '</span>';
                                            } else {
                                                echo '<span class="text-muted small">Not delivered</span>';
                                            }
                                            ?>
                                        </td>
                                        <!-- Discount column removed -->
                                        <td>
                                            <?php
                                            if ($order['is_cancelled']) {
                                                echo '<span class="badge bg-danger">Cancelled</span>';
                                            } elseif ($order['is_returned']) {
                                                echo '<span class="badge bg-warning text-dark">Returned</span>';
                                            } elseif ($order['is_paid']) {
                                                echo '<span class="badge bg-success">Paid</span>';
                                            } else {
                                                echo '<span class="badge bg-warning text-dark">Unpaid</span>';
                                            }
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($order['payment_method'] ?: '-') ?></td>
                                        <td>
                                            <?php 
                                            $paidNote = $order['paid_note'] ?? '';
                                            if ($paidNote) {
                                                $displayNote = mb_strlen($paidNote) > 30 ? mb_substr($paidNote, 0, 30) . '...' : $paidNote;
                                                echo '<span class="badge bg-info clickable-note small" style="cursor:pointer;" onclick="showPaidNoteModal(' . $order['id'] . ', \'" . htmlspecialchars(addslashes($paidNote), ENT_QUOTES) . "\')" title="' . htmlspecialchars($paidNote) . '">' . htmlspecialchars($displayNote) . '</span>';
                                            } else {
                                                echo '<span class="text-muted small">-</span>';
                                            }
                                            ?>
                                        </td>
                                        <!-- Updated Date column removed -->
                                        <!-- Updated By column removed -->
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="font-weight:bold; background:#f8f9fa;">
                                    <td colspan="7" class="text-end">Total</td>
                                    <td><strong>$<?= number_format($total_order_amount, 2) ?></strong></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Improved Pagination -->
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php
                            $max_links = 5; // Number of page links to show (excluding first/last/ellipsis)
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            if ($end - $start + 1 < $max_links) {
                                if ($start === 1) {
                                    $end = min($total_pages, $start + $max_links - 1);
                                } else if ($end === $total_pages) {
                                    $start = max(1, $end - $max_links + 1);
                                }
                            }

                            // Previous button
                            if ($page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $page - 1])) . '">&laquo; Prev</a></li>';
                            } else {
                                echo '<li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>';
                            }

                            // First page
                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                                if ($start > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }

                            // Page links
                            for ($i = $start; $i <= $end; $i++) {
                                $active = $i === $page ? 'active' : '';
                                echo '<li class="page-item ' . $active . '"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '">' . $i . '</a></li>';
                            }

                            // Last page
                            if ($end < $total_pages) {
                                if ($end < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                            }

                            // Next button
                            if ($page < $total_pages) {
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $page + 1])) . '">Next &raquo;</a></li>';
                            } else {
                                echo '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
                            }
                            ?>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Print only checked orders
function printCheckedOrders() {
    var table = document.getElementById('ordersTable');
    var rows = table.querySelectorAll('tbody tr');
    var checkedRows = [];
    rows.forEach(function(row) {
        var checkbox = row.querySelector('.order-checkbox');
        if (checkbox && checkbox.checked) {
            var cells = row.querySelectorAll('td');
            // Table columns: [0]=checkbox, [1]=No, [2]=Order Code, [3]=Created Date, [4]=Scan Date, [5]=Customer, [6]=Phone, [7]=Location, [8]=Amount, [9]=Delivery By, [10]=Payment Status, ...
            var no = cells[1]?.innerText || '';
            var orderCode = cells[2]?.innerText || '';
            var customer = cells[5]?.innerText || '';
            var phone = cells[6]?.innerText || '';
            var location = cells[7]?.innerText || '';
            var amount = cells[8]?.innerText || '';
            var deliveryBy = cells[9]?.innerText || '';
            var status = cells[10]?.innerText || '';
            checkedRows.push([
                no, orderCode, customer, phone, location, deliveryBy, status, amount
            ]);
        }
    });
    if (!checkedRows.length) { alert('No orders selected!'); return; }
    var today = new Date();
    var dateStr = today.toLocaleDateString() + ' ' + today.toLocaleTimeString();
    // Calculate paid, unpaid, total
    var paid = 0, unpaid = 0, total = 0;
    checkedRows.forEach(function(cols) {
        var status = cols[6]?.trim().toLowerCase(); // Status is now index 6
        var amountStr = cols[7]?.replace(/[^\d.\-]/g, '') || '0'; // Amount is now index 7
        var amount = parseFloat(amountStr) || 0;
        total += amount;
        if (status === 'paid') paid += amount;
        else if (status === 'unpaid') unpaid += amount;
    });
    var printContents = `
        <div style="text-align:center; margin-bottom:20px; color:#000;">
            <h2 style="margin-bottom:0.5em; font-size:2em; color:#000;">Payment Report</h2>
            <div style="font-size:1.1em; color:#000;">Printed: ${dateStr}</div>
        </div>
        <div style="overflow-x:auto; color:#000;">
        <table class="table table-bordered table-striped" style="font-size:1em; background:#fff; color:#000; border-collapse:collapse;">
            <thead class="table-dark" style="color:#000;">
                <tr>
                    <th style="border:2px solid #000;">No</th>
                    <th style="border:2px solid #000;">Order code</th>
                    <th style="border:2px solid #000;">Customer</th>
                    <th style="border:2px solid #000;">Phone</th>
                    <th style="border:2px solid #000;">Location</th>
                    <th style="border:2px solid #000;">Delivery By</th>
                    <th style="border:2px solid #000;">Status</th>
                    <th style="border:2px solid #000;">Amount</th>
                </tr>
            </thead>
            <tbody>
                ${checkedRows.map(cols => `<tr style=\"border-bottom:2px solid #000;\">${cols.map((val, idx) => {
                    // Status column is idx 6
                    if(idx === 6 && val.trim().toLowerCase() === 'unpaid') {
                        return `<td style=\"color:#e53935;font-weight:bold;border:2px solid #000;\">${val}</td>`;
                    }
                    return `<td style=\"color:#000;border:2px solid #000;\">${val}</td>`;
                }).join('')}</tr>`).join('')}
                <tr style="font-weight:bold; background:#f8f9fa;">
                    <td colspan="6" class="text-end" style="border:2px solid #000; font-size:1.1em;">Paid</td>
                    <td colspan="2" style="color:#198754; font-weight:bold; font-size:1.2em; border:2px solid #000; text-align:center;">$${paid.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
                <tr style="font-weight:bold; background:#f8f9fa;">
                    <td colspan="6" class="text-end" style="border:2px solid #000; font-size:1.1em;">Unpaid</td>
                    <td colspan="2" style="color:#e53935; font-weight:bold; font-size:1.2em; border:2px solid #000; text-align:center;">$${unpaid.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
                <tr style="font-weight:bold; background:#e2e3e5;">
                    <td colspan="6" class="text-end" style="border:2px solid #000; font-size:1.1em;">Total</td>
                    <td colspan="2" style="font-weight:bold; font-size:1.2em; border:2px solid #000; text-align:center;">$${total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                </tr>
            </tbody>
        </table>
        </div>
    `;
    var printWindow = window.open('', '', 'height=800,width=1100');
    printWindow.document.write('<html><head><title>Print Orders</title>');
    printWindow.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    printWindow.document.write('<style>body{background:#fff!important;} th,td{text-align:center;vertical-align:middle;font-size:1em;} .table{margin-bottom:0;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(printContents);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.focus();
        printWindow.print();
        printWindow.close();
    };
}

// Export only checked orders to Excel
function exportCheckedOrdersToExcel() {
    var table = document.getElementById('ordersTable');
    var rows = table.querySelectorAll('tbody tr');
    var checkedRows = [];
    rows.forEach(function(row) {
        var checkbox = row.querySelector('.order-checkbox');
        if (checkbox && checkbox.checked) {
            var cells = row.querySelectorAll('td');
            // Table columns: [0]=checkbox, [1]=No, [2]=Order Code, [3]=Created Date, [4]=Scan Date, [5]=Customer, [6]=Phone, [7]=Location, [8]=Amount, [9]=Delivery By, [10]=Payment Status, ...
            var no = cells[1]?.innerText || '';
            var orderCode = cells[2]?.innerText || '';
            var customer = cells[5]?.innerText || '';
            var phone = cells[6]?.innerText || '';
            var location = cells[7]?.innerText || '';
            var amount = cells[8]?.innerText || '';
            var deliveryBy = cells[9]?.innerText || '';
            var status = cells[10]?.innerText || '';
            checkedRows.push([
                no, orderCode, customer, phone, location, deliveryBy, status, amount
            ]);
        }
    });
    if (!checkedRows.length) { alert('No orders selected!'); return; }
    // Calculate paid, unpaid, total
    var paid = 0, unpaid = 0, total = 0;
    checkedRows.forEach(function(cols) {
        var status = cols[6]?.trim().toLowerCase(); // Status is now index 6
        var amountStr = cols[7]?.replace(/[^\d.\-]/g, '') || '0'; // Amount is now index 7
        var amount = parseFloat(amountStr) || 0;
        total += amount;
        if (status === 'paid') paid += amount;
        else if (status === 'unpaid') unpaid += amount;
    });
    var tableHTML = '<table style="border-collapse:collapse;">' +
        '<thead><tr>' +
        '<th style="border:1px solid #333;">No</th>' +
        '<th style="border:1px solid #333;">Order code</th>' +
        '<th style="border:1px solid #333;">Customer</th>' +
        '<th style="border:1px solid #333;">Phone</th>' +
        '<th style="border:1px solid #333;">Location</th>' +
        '<th style="border:1px solid #333;">Delivery By</th>' +
        '<th style="border:1px solid #333;">Status</th>' +
        '<th style="border:1px solid #333;">Amount</th>' +
        '</tr></thead><tbody>' +
        checkedRows.map(cols => '<tr>' + cols.map((val, idx) => {
            if(idx === 6 && val.trim().toLowerCase() === 'unpaid') {
                return '<td style="color:#e53935;font-weight:bold;border:1px solid #333;">' + val + '</td>';
            }
            return '<td style="border:1px solid #333;">' + val + '</td>';
        }).join('') + '</tr>').join('') +
        `<tr style="font-weight:bold; background:#f8f9fa;"><td colspan="7" style="text-align:right;border:1px solid #333; font-size:1.1em;">Paid</td><td style="color:#198754; font-weight:bold; font-size:1.2em; border:1px solid #333; text-align:center;">$${paid.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td></tr>` +
        `<tr style="font-weight:bold; background:#f8f9fa;"><td colspan="7" style="text-align:right;border:1px solid #333; font-size:1.1em;">Unpaid</td><td style="color:#e53935; font-weight:bold; font-size:1.2em; border:1px solid #333; text-align:center;">$${unpaid.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td></tr>` +
        `<tr style="font-weight:bold; background:#e2e3e5;"><td colspan="7" style="text-align:right;border:1px solid #333; font-size:1.1em;">Total</td><td style="font-weight:bold; font-size:1.2em; border:1px solid #333; text-align:center;">$${total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</td></tr>` +
        '</tbody></table>';
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var filename = 'orders_checked.xls';
    downloadLink = document.createElement('a');
    document.body.appendChild(downloadLink);
    if (navigator.msSaveOrOpenBlob) {
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ';charset=utf-8,' + encodeURIComponent(tableHTML);
        downloadLink.download = filename;
        downloadLink.click();
    }
    document.body.removeChild(downloadLink);
    // Clear checkedRows array after export
    checkedRows = [];
}
function toggleAllOrders(source) {
    const checkboxes = document.querySelectorAll('.order-checkbox');
    for (const cb of checkboxes) {
        cb.checked = source.checked;
    }
}
// Send checked orders to Telegram as Excel
function sendCheckedOrdersToTelegram() {
    var table = document.getElementById('ordersTable');
    var rows = table.querySelectorAll('tbody tr');
    var checkedIds = [];
    rows.forEach(function(row) {
        var checkbox = row.querySelector('.order-checkbox');
        if (checkbox && checkbox.checked) {
            checkedIds.push(checkbox.value);
        }
    });
    if (!checkedIds.length) {
        alert('No orders selected!');
        return;
    }
    var btn = document.getElementById('sendToTelegramBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
    fetch('send_payment_excel_telegram.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ orderIds: checkedIds })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Send to Telegram';
        if (data.success) {
            alert('Excel sent to Telegram successfully!');
        } else {
            alert('Failed to send: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Send to Telegram';
        alert('Failed to send: ' + err);
    });
}
</script>
<script>
// Print table by id
function printTable(tableId) {
    var printContents = document.getElementById(tableId).outerHTML;
    var originalContents = document.body.innerHTML;
    document.body.innerHTML = printContents;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}
// Export table to Excel by id
function exportTableToExcel(tableID, filename = '') {
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    var tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    filename = filename ? filename + '.xls' : 'excel_data.xls';
    downloadLink = document.createElement('a');
    document.body.appendChild(downloadLink);
    if (navigator.msSaveOrOpenBlob) {
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
    document.body.removeChild(downloadLink);
}
</script>
</body>
<!-- Paid Note Modal -->
<div class="modal fade" id="paidNoteModal" tabindex="-1" aria-labelledby="paidNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paidNoteModalLabel">Paid Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="paidNoteModalBody">
            </div>
        </div>
    </div>
</div>
<script>
function showPaidNoteModal(orderId, note) {
        var modal = new bootstrap.Modal(document.getElementById('paidNoteModal'));
        document.getElementById('paidNoteModalBody').textContent = note;
        modal.show();
}
</script>
</html>
    <!-- Table removed as requested -->
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
