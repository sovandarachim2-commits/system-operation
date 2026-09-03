<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'offline_sales.view', 'offline_sales.create');
require_once __DIR__ . '/offline_lib.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);
if (!isset($_SESSION)) {
    session_start();
}
$errors = [];
$success = '';
if (!empty($_SESSION['offline_sale_flash_success'])) {
    $success = (string)$_SESSION['offline_sale_flash_success'];
    unset($_SESSION['offline_sale_flash_success']);
} elseif (!empty($_SESSION['offline_sale_flash_errors'])) {
    $errors = (array)$_SESSION['offline_sale_flash_errors'];
    unset($_SESSION['offline_sale_flash_errors']);
} elseif (isset($_GET['created'])) {
    $success = 'Offline sale created.';
} elseif (isset($_GET['updated'])) {
    $success = 'Order updated.';
} elseif (isset($_GET['cancelled'])) {
    $success = 'Order cancelled and stock restored.';
}
$paymentMethods = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);
$userRoles = user_role_names($pdo, current_user() ?: []);
$canUpdatePayment = in_array('admin', $userRoles, true) || has_permission('offline_sales.update');
$canEditOrder = in_array('admin', $userRoles, true) || has_permission('offline_sales.update');
$canCreateOrder = in_array('admin', $userRoles, true) || has_permission('offline_sales.create');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_payment') {
    require_role_or_permission(['admin'], 'offline_sales.update');
    $returnFilters = [
        'date_from' => offline_optional_filter_date($_POST['return_date_from'] ?? null) ?? '',
        'date_to' => offline_optional_filter_date($_POST['return_date_to'] ?? null) ?? '',
        'search' => trim((string)($_POST['return_search'] ?? '')),
        'status' => trim((string)($_POST['return_status'] ?? '')),
    ];
    $returnUrl = offline_orders_list_query($returnFilters);

    $id = (int)($_POST['id'] ?? 0);
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $paidNote = trim((string)($_POST['paid_note'] ?? ''));
    $paymentAmount = max(0, (float)($_POST['payment_amount'] ?? 0));
    $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));

    if ($id <= 0) {
        $errors[] = 'Invalid order.';
    } elseif ($paymentMethod === '') {
        $errors[] = 'Payment method is required.';
    } elseif ($paymentAmount <= 0) {
        $errors[] = 'Payment amount must be greater than zero.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        $errors[] = 'Payment date is invalid.';
    } else {
        $orderStmt = $pdo->prepare("SELECT total_amount, received_amount, status FROM offline_sale_orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$id]);
        $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalAmount = (float)($orderRow['total_amount'] ?? 0);
        $existingPayments = offline_payments_for_orders($pdo, [$id])[$id] ?? [];
        $alreadyReceived = offline_order_paid_from_payments($existingPayments, $orderRow);
        $balanceDue = max(0, $totalAmount - $alreadyReceived);

        if (in_array(strtolower((string)($orderRow['status'] ?? '')), ['cancelled', 'canceled'], true)) {
            $errors[] = 'Cannot record payment for a cancelled order.';
        } elseif ($paymentAmount > $balanceDue + 0.009) {
            $errors[] = 'Payment amount cannot exceed balance due ($' . number_format($balanceDue, 2) . ').';
        } else {
            try {
                offline_add_order_payment($pdo, $id, $paymentDate, $paymentAmount, $paymentMethod, $paidNote ?: null, current_user()['id'] ?? null);
                $refresh = $pdo->prepare("SELECT total_amount, received_amount, status FROM offline_sale_orders WHERE id = ? LIMIT 1");
                $refresh->execute([$id]);
                $orderRow = $refresh->fetch(PDO::FETCH_ASSOC) ?: [];
                $totalAmount = (float)($orderRow['total_amount'] ?? 0);
                $receivedAmount = (float)($orderRow['received_amount'] ?? 0);
                $computedStatus = (string)($orderRow['status'] ?? 'unpaid');
                $_SESSION['offline_sale_flash_success'] = $computedStatus === 'paid'
                    ? 'Payment recorded. Order is fully paid.'
                    : 'Payment of $' . number_format($paymentAmount, 2) . ' recorded. Balance due: $' . number_format(max(0, $totalAmount - $receivedAmount), 2) . '.';
                header('Location: ' . $returnUrl);
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    if ($errors) {
        $_SESSION['offline_sale_flash_errors'] = $errors;
        header('Location: ' . $returnUrl);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    require_role_or_permission(['admin'], 'offline_sales.update');
    $returnFilters = [
        'date_from' => offline_optional_filter_date($_POST['return_date_from'] ?? null) ?? '',
        'date_to' => offline_optional_filter_date($_POST['return_date_to'] ?? null) ?? '',
        'search' => trim((string)($_POST['return_search'] ?? '')),
        'status' => trim((string)($_POST['return_status'] ?? '')),
    ];
    $returnUrl = offline_orders_list_query($returnFilters);

    $id = (int)($_POST['id'] ?? 0);
    $cancelNote = trim((string)($_POST['cancel_note'] ?? ''));
    if ($id <= 0) {
        $_SESSION['offline_sale_flash_errors'] = ['Invalid order.'];
        header('Location: ' . $returnUrl);
        exit;
    }
    try {
        offline_cancel_sale_order($pdo, $id, $cancelNote, current_user() ?: []);
        $_SESSION['offline_sale_flash_success'] = 'Order cancelled and stock restored.';
    } catch (Throwable $e) {
        $_SESSION['offline_sale_flash_errors'] = [$e->getMessage()];
    }
    header('Location: ' . $returnUrl);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim((string)($_GET['search'] ?? ''));
$dateFrom = offline_optional_filter_date($_GET['date_from'] ?? null);
$dateTo = offline_optional_filter_date($_GET['date_to'] ?? null);
if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$params = [];
$where = ['1=1'];
if ($dateFrom && $dateTo) {
    $where[] = 'DATE(oso.sale_date) BETWEEN ? AND ?';
    array_push($params, $dateFrom, $dateTo);
} elseif ($dateFrom) {
    $where[] = 'DATE(oso.sale_date) >= ?';
    $params[] = $dateFrom;
} elseif ($dateTo) {
    $where[] = 'DATE(oso.sale_date) <= ?';
    $params[] = $dateTo;
}
if (in_array($statusFilter, ['paid', 'unpaid', 'partial', 'cancelled'], true)) {
    $where[] = 'oso.status = ?';
    $params[] = $statusFilter;
}
if ($searchQuery !== '') {
    $where[] = '(oso.order_code LIKE ? OR oso.customer_name LIKE ? OR oso.phone LIKE ? OR oso.customer_location LIKE ? OR ot.name LIKE ?)';
    $searchTerm = '%' . $searchQuery . '%';
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
$stmt = $pdo->prepare("
    SELECT
        oso.*,
        sl.location_code,
        sl.location_name,
        ot.name AS team_name,
        uc.name AS created_by_name,
        uu.name AS updated_by_name
    FROM offline_sale_orders oso
    JOIN storage_locations sl ON sl.id = oso.location_id
    LEFT JOIN offline_teams ot ON ot.id = oso.team_id
    LEFT JOIN users uc ON uc.id = oso.created_by
    LEFT JOIN users uu ON uu.id = oso.updated_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY oso.sale_date DESC, oso.id DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsByOrder = [];
$purchaseItemsByOrder = [];
$paymentsByOrder = [];
$logsByOrder = [];
if ($orders) {
    $orderIds = array_map(static fn($row) => (int)$row['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = $pdo->prepare("
        SELECT order_id, product_name, quantity, line_total
        FROM offline_sale_order_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_id, id
    ");
    $itemStmt->execute($orderIds);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByOrder[(int)$item['order_id']][] = $item;
    }
    $purchaseStmt = $pdo->prepare("
        SELECT order_id, product_name, quantity, line_total, item_condition, reason
        FROM offline_sale_purchase_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_id, id
    ");
    $purchaseStmt->execute($orderIds);
    foreach ($purchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $purchaseItemsByOrder[(int)$item['order_id']][] = $item;
    }
    $paymentsByOrder = offline_payments_for_orders($pdo, $orderIds);
    $logsByOrder = offline_logs_for_orders($pdo, $orderIds);
}

$totalOrders = count($orders);
$hasActiveFilters = $searchQuery !== '' || $statusFilter !== '' || $dateFrom !== null || $dateTo !== null;

function offline_orders_filter_query(array $overrides = []): string
{
    global $searchQuery, $statusFilter, $dateFrom, $dateTo;
    $params = array_filter([
        'search' => $searchQuery,
        'status' => $statusFilter,
        'date_from' => $dateFrom ?? '',
        'date_to' => $dateTo ?? '',
    ], static fn($v) => $v !== '' && $v !== null);
    $params = array_merge($params, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    $query = 'offline_sale_orders.php';
    if ($params) {
        $query .= '?' . http_build_query($params);
    }

    return $query;
}

function offline_order_paid_amount(array $order, array $payments = []): float
{
    return offline_order_paid_from_payments($payments, $order);
}

function offline_order_balance(array $order, array $payments = []): float
{
    $total = (float)($order['total_amount'] ?? 0);
    return max(0, $total - offline_order_paid_amount($order, $payments));
}

function offline_format_qty(float $qty): string
{
    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}

function offline_format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y H:i', $ts) : '—';
}

function offline_format_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y', $ts) : '—';
}

function offline_log_action_label(string $action): string
{
    return match ($action) {
        'created' => 'Created',
        'payment' => 'Payment',
        'updated' => 'Updated',
        default => ucfirst($action),
    };
}

include __DIR__ . '/../layout/header.php';
offline_status_badge_styles();
?>
<style>
.offline-orders-page .order-row:hover {
    background-color: #f8f9fa;
}
.offline-orders-page .order-row-cancelled,
.offline-orders-page .table-striped > tbody > tr.order-row-cancelled:nth-of-type(odd) > *,
.offline-orders-page .table-striped > tbody > tr.order-row-cancelled:nth-of-type(even) > * {
    background-color: #f8d7da;
}
.offline-orders-page .order-row-cancelled:hover > * {
    background-color: #f5c2c7;
}
.offline-orders-page {
    max-width: 100%;
    min-width: 0;
    overflow-x: hidden;
}
.offline-orders-page .card,
.offline-orders-page .card-body {
    min-width: 0;
}
.offline-orders-page .orders-table-scroll {
    max-width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    overscroll-behavior-x: contain;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.offline-orders-page .orders-table-scroll > table {
    min-width: 1680px;
    width: max-content;
}
.offline-orders-bottom-scroll {
    background: #f8f9fa;
    border: 1px solid #cfd6df;
    bottom: 0;
    display: none;
    height: 18px;
    overflow-x: auto;
    overflow-y: hidden;
    position: fixed;
    z-index: 1040;
}
.offline-orders-bottom-scroll.is-visible {
    display: block;
}
.offline-orders-bottom-scroll-content {
    height: 1px;
}
@media (max-width: 767.98px) {
    .offline-orders-page {
        padding-left: .5rem !important;
        padding-right: .5rem !important;
    }
    .offline-orders-page .orders-table-scroll {
        border-top: 1px solid #dee2e6;
    }
    .offline-orders-bottom-scroll {
        display: none !important;
    }
}
.offline-orders-page .product-lines {
    min-width: 180px;
    max-width: 280px;
}
.offline-orders-page .btn-payment-dates,
.offline-orders-page .btn-audit-view {
    font-size: .82rem;
    text-decoration: none;
    padding: 0;
}
.offline-orders-page .btn-payment-dates:hover,
.offline-orders-page .btn-audit-view:hover {
    text-decoration: underline;
}
.offline-orders-page .date-filter-group {
    display: flex;
    gap: 0.5rem;
    align-items: stretch;
}
.offline-orders-page .date-filter-group .form-control {
    flex: 1 1 0;
    min-width: 0;
}
.offline-orders-page .offline-status-badge {
    gap: 0.3rem;
    padding: 0.2rem 0.45rem 0.2rem 0.3rem;
    border-radius: 6px;
    font-size: 0.72rem;
}
.offline-orders-page .offline-status-badge .offline-status-icon {
    font-size: 0.75rem;
}
.offline-orders-page .offline-status-paid .offline-status-icon,
.offline-orders-page .offline-status-partial .offline-status-icon {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    font-size: 0.65rem;
}
.offline-orders-page .offline-status-unpaid .offline-status-icon {
    font-size: 0.8rem;
}
.offline-orders-page .status-payment-note {
    font-size: 0.72rem;
    line-height: 1.2;
    margin-top: 0.15rem;
}
.offline-orders-page .offline-actions-menu {
    min-width: 190px;
}
.offline-orders-page .offline-actions-menu .dropdown-item {
    align-items: center;
    display: flex;
    gap: 0.55rem;
    padding: 0.65rem 0.85rem;
}
.offline-orders-page .offline-actions-menu .dropdown-item i {
    font-size: 1rem;
}
.offline-order-create-modal .modal-dialog {
    align-items: flex-start;
    margin-top: 1.25rem;
    max-width: min(1720px, calc(100vw - 2rem));
}
.offline-order-create-modal .modal-content {
    height: calc(100vh - 2.5rem);
}
.offline-order-create-modal .modal-body {
    min-height: 0;
}
.offline-order-create-frame {
    border: 0;
    display: block;
    height: 100%;
    width: 100%;
}
.offline-filter-panel {
    width: min(430px, 100vw);
}
.offline-filter-panel .offcanvas-header {
    border-bottom: 1px solid #e5e7eb;
}
.offline-filter-panel .offcanvas-title {
    color: #1f2937;
    font-weight: 800;
}
.offline-filter-panel .form-label {
    color: #374151;
    font-weight: 700;
}
.offline-filter-panel .form-control,
.offline-filter-panel .form-select {
    min-height: 46px;
}
</style>
<div class="container-fluid py-4 offline-orders-page">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0">
                    <i class="bi bi-receipt me-2"></i>Offline Sale Orders
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white text-primary fs-6">
                        <i class="bi bi-list-ol me-1"></i><?= number_format($totalOrders) ?> Orders
                    </span>
                    <?php if ($canCreateOrder): ?>
                        <button type="button"
                            class="btn btn-light btn-sm text-primary fw-semibold"
                            data-bs-toggle="modal"
                            data-bs-target="#createOfflineOrderModal"
                            data-order-form-title="New Offline Sale"
                            data-order-form-src="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_new.php?popup=1"
                            onclick="openOfflineOrderForm(this)">
                            <i class="bi bi-plus-lg me-1"></i>Add New Order
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($errors as $e): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($e) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offlineOrdersFilterPanel" aria-controls="offlineOrdersFilterPanel">
                    <i class="bi bi-funnel-fill me-1"></i>Filter
                    <?php if ($hasActiveFilters): ?><span class="badge bg-primary ms-1">On</span><?php endif; ?>
                </button>
                <?php if ($hasActiveFilters): ?>
                    <a href="offline_sale_orders.php" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-x-circle me-1"></i>Clear All
                    </a>
                <?php endif; ?>
                <!-- Print toolbar (shown when rows are selected) -->
                <div id="offlinePrintToolbar" class="d-none d-flex align-items-center gap-2">
                    <span class="fw-semibold text-primary" id="offlinePrintCount">0 selected</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="offlinePrintClear">
                        <i class="bi bi-x-lg me-1"></i>Clear
                    </button>
                    <a href="#" id="offlinePrintReceiptBtn" target="_blank" class="btn btn-sm btn-danger">
                        <i class="bi bi-receipt me-1"></i>Print Receipt
                    </a>
                    <a href="#" id="offlinePrintInvoiceBtn" target="_blank" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-file-earmark-text me-1"></i>Print Invoice
                    </a>
                    <a href="#" id="offlinePrintDeliveryBtn" target="_blank" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-truck me-1"></i>Print Delivery
                    </a>
                </div>
            </div>

            <div class="card bg-light border-0 mb-4 d-none">
                <div class="card-body">
                    <form method="get">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-search me-1"></i>Search Orders
                                </label>
                                <input type="text" name="search" class="form-control" placeholder="Order code, customer, phone, team..." value="<?= htmlspecialchars($searchQuery) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-calendar-range me-1"></i>Sale Date
                                </label>
                                <div class="date-filter-group">
                                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom ?? '') ?>" title="From date">
                                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo ?? '') ?>" title="To date">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-funnel me-1"></i>Status
                                </label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                                    <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-1"></i>Filter
                                    </button>
                                    <a href="offline_sale_orders.php" class="btn btn-outline-secondary" title="Reset">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php if ($hasActiveFilters): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="text-muted fw-semibold">Active Filters:</span>
                                        <?php if ($dateFrom || $dateTo): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-calendar me-1"></i>
                                                <?= htmlspecialchars(($dateFrom ?: '…') . ' → ' . ($dateTo ?: '…')) ?>
                                                <a href="<?= htmlspecialchars(offline_orders_filter_query(['date_from' => '', 'date_to' => ''])) ?>" class="text-white text-decoration-none ms-1">×</a>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($searchQuery !== ''): ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-search me-1"></i><?= htmlspecialchars($searchQuery) ?>
                                                <a href="<?= htmlspecialchars(offline_orders_filter_query(['search' => ''])) ?>" class="text-white text-decoration-none ms-1">×</a>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($statusFilter): ?>
                                            <span class="badge bg-info">
                                                <i class="bi bi-funnel me-1"></i><?= htmlspecialchars(offline_status_badge_label($statusFilter)) ?>
                                                <a href="<?= htmlspecialchars(offline_orders_filter_query(['status' => ''])) ?>" class="text-white text-decoration-none ms-1">×</a>
                                            </span>
                                        <?php endif; ?>
                                        <a href="offline_sale_orders.php" class="btn btn-sm btn-outline-danger">Clear All</a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="table-responsive orders-table-scroll" tabindex="0" aria-label="Offline sale orders table; swipe horizontally to see more columns">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" id="selectAllOfflineOrders" class="form-check-input" title="Select all">
                            </th>
                            <th>No</th>
                            <th>Order Code</th>
                            <th>Sale Date</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Products</th>
                            <th>Team</th>
                            <th>Customer Location</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th class="text-end">Paid</th>
                            <th>Payment Dates</th>
                            <th class="text-end">Balance Due</th>
                            <th>Created</th>
                            <th>Updated By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                <?php $rowNo = 1; foreach ($orders as $order):
                    $orderId = (int)$order['id'];
                    $items = $itemsByOrder[$orderId] ?? [];
                    $purchaseItems = $purchaseItemsByOrder[$orderId] ?? [];
                    $payments = $paymentsByOrder[$orderId] ?? [];
                    $logs = $logsByOrder[$orderId] ?? [];
                    $paid = offline_order_paid_amount($order, $payments);
                    $balance = offline_order_balance($order, $payments);
                    $status = offline_order_display_status($order, $payments);
                    $isCancelledOrder = $status === 'cancelled';
                    if ($isCancelledOrder) {
                        $balance = 0.0;
                    }
                    $paymentCount = count($payments);
                    $logCount = count($logs);
                    $activityJson = htmlspecialchars(json_encode(array_map(static function ($log) {
                        return [
                            'action' => $log['action'] ?? '',
                            'action_label' => offline_log_action_label((string)($log['action'] ?? '')),
                            'description' => $log['description'] ?? '',
                            'by' => $log['created_by_name'] ?? '',
                            'at' => $log['created_at'] ?? '',
                        ];
                    }, $logs), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $paymentsJson = htmlspecialchars(json_encode(array_map(static function ($p) {
                        return [
                            'date' => $p['payment_date'],
                            'amount' => (float)$p['amount'],
                            'method' => $p['payment_method'] ?? '',
                            'note' => $p['paid_note'] ?? '',
                            'by' => $p['created_by_name'] ?? '',
                            'at' => $p['created_at'] ?? '',
                        ];
                    }, $payments), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    $createdAt = offline_format_datetime($order['created_at'] ?? null);
                    $updatedAt = offline_format_datetime($order['updated_at'] ?? null);
                    $subtotal = (float)($order['subtotal'] ?? 0);
                    $discount = (float)($order['discount'] ?? 0);
                    $totalAmount = (float)($order['total_amount'] ?? 0);
                ?>
                    <tr class="order-row <?= $isCancelledOrder ? 'order-row-cancelled' : '' ?>">
                        <td>
                            <input type="checkbox" class="form-check-input offline-order-checkbox"
                                value="<?= $orderId ?>"
                                data-order-code="<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>"
                                <?= $isCancelledOrder ? 'disabled' : '' ?>>
                        </td>
                        <td class="text-muted"><?= $rowNo++ ?></td>
                        <td class="text-nowrap">
                            <strong><?= htmlspecialchars($order['order_code']) ?></strong>
                        </td>
                        <td class="text-nowrap"><?= htmlspecialchars(offline_format_date($order['sale_date'] ?? null)) ?></td>
                        <td><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars($order['phone'] ?: 'N/A') ?></td>
                        <td class="product-lines">
                            <?php if ($items): ?>
                                <?php foreach ($items as $item): ?>
                                    <div class="small mb-1">
                                        <span class="badge bg-success-subtle text-success me-1">SALE</span>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <span class="text-muted">x<?= offline_format_qty((float)$item['quantity']) ?></span>
                                        <span class="fw-semibold">$<?= number_format((float)$item['line_total'], 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($purchaseItems as $item): ?>
                                    <div class="small mb-1 text-success">
                                        <span class="badge bg-warning-subtle text-warning-emphasis me-1">PURCHASE</span>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <span class="text-muted">x<?= offline_format_qty((float)$item['quantity']) ?></span>
                                        <span class="fw-semibold">-$<?= number_format((float)$item['line_total'], 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted small">No products</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($order['team_name'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($order['customer_location'] ?? $order['location_name'] ?? 'N/A') ?></td>
                        <td>
                            <?= offline_status_badge_html($status) ?>
                            <?php if (!empty($order['payment_method'])): ?>
                                <div class="text-muted status-payment-note"><?= htmlspecialchars($order['payment_method']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <strong>$<?= number_format($totalAmount, 2) ?></strong>
                            <?php if ($discount > 0): ?>
                                <br><small class="text-muted">Subtotal $<?= number_format($subtotal, 2) ?> · Disc $<?= number_format($discount, 2) ?></small>
                            <?php elseif ($subtotal > $totalAmount): ?>
                                <br><small class="text-muted">Subtotal $<?= number_format($subtotal, 2) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-success fw-semibold">$<?= number_format($paid, 2) ?></td>
                        <td class="text-nowrap">
                            <?php if ($paymentCount === 0): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <button type="button"
                                    class="btn btn-link btn-payment-dates p-0 align-baseline"
                                    data-bs-toggle="modal"
                                    data-bs-target="#paymentHistoryModal"
                                    data-order-code="<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>"
                                    data-customer="<?= htmlspecialchars($order['customer_name'] ?? '', ENT_QUOTES) ?>"
                                    data-payments="<?= $paymentsJson ?>"
                                    title="View all payment dates">
                                    <?php if ($paymentCount === 1): ?>
                                        <?= htmlspecialchars($payments[0]['payment_date']) ?>
                                    <?php else: ?>
                                        <span class="fw-semibold"><?= $paymentCount ?> payments</span>
                                        <span class="d-block text-muted small">Latest: <?= htmlspecialchars($payments[0]['payment_date']) ?></span>
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>"><?= $isCancelledOrder ? '—' : '$' . number_format($balance, 2) ?></td>
                        <td class="small text-nowrap">
                            <div class="fw-semibold"><?= htmlspecialchars($order['created_by_name'] ?? 'N/A') ?></div>
                            <div class="text-muted"><?= htmlspecialchars($createdAt) ?></div>
                            <button type="button"
                                class="btn btn-link btn-audit-view p-0"
                                data-bs-toggle="modal"
                                data-bs-target="#orderAuditModal"
                                data-order-code="<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>"
                                data-customer="<?= htmlspecialchars($order['customer_name'] ?? '', ENT_QUOTES) ?>"
                                data-created-by="<?= htmlspecialchars($order['created_by_name'] ?? '—', ENT_QUOTES) ?>"
                                data-created-at="<?= htmlspecialchars($createdAt, ENT_QUOTES) ?>"
                                data-activity="<?= $activityJson ?>"
                                title="View full order history">
                                <i class="bi bi-eye me-1"></i>View<?= $logCount > 0 ? ' (' . $logCount . ')' : '' ?>
                            </button>
                        </td>
                        <td class="small text-nowrap">
                            <div class="fw-semibold"><?= htmlspecialchars($order['updated_by_name'] ?? 'N/A') ?></div>
                            <div class="text-muted"><?= htmlspecialchars($updatedAt) ?></div>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($canEditOrder && !$isCancelledOrder): ?>
                                    <div class="dropdown">
                                        <button type="button"
                                            class="btn btn-sm btn-primary dropdown-toggle"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false"
                                            title="Order actions">
                                            <i class="bi bi-gear me-1"></i>Actions
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end offline-actions-menu">
                                            <li>
                                                <button type="button"
                                                    class="dropdown-item js-edit-offline-order"
                                                    data-order-form-title="Edit Offline Sale"
                                                    data-order-form-src="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_new.php?id=<?= $orderId ?>&popup=1"
                                                    data-order-id="<?= $orderId ?>"
                                                    data-order-code="<?= htmlspecialchars($order['order_code'] ?? '', ENT_QUOTES) ?>">
                                                    <i class="bi bi-pencil text-warning"></i>Edit Order
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button"
                                                    class="dropdown-item text-danger js-cancel-offline-order"
                                                    data-order-id="<?= $orderId ?>"
                                                    data-order-code="<?= htmlspecialchars($order['order_code'] ?? '', ENT_QUOTES) ?>">
                                                    <i class="bi bi-x-square"></i>Cancel Order
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                <?php if (!$isCancelledOrder && $balance > 0 && $canUpdatePayment): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-success"
                                        title="Pay — balance $<?= number_format($balance, 2) ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#paymentModal"
                                        data-order-id="<?= $orderId ?>"
                                        data-order-code="<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>"
                                        data-customer="<?= htmlspecialchars($order['customer_name'] ?? '', ENT_QUOTES) ?>"
                                        data-total="<?= number_format((float)($order['total_amount'] ?? 0), 2, '.', '') ?>"
                                        data-paid="<?= number_format($paid, 2, '.', '') ?>"
                                        data-balance="<?= number_format($balance, 2, '.', '') ?>"
                                        data-payment-method="<?= htmlspecialchars($order['payment_method'] ?? '', ENT_QUOTES) ?>"
                                        data-payments="<?= $paymentsJson ?>">
                                        <i class="bi bi-cash-coin"></i> Pay
                                    </button>
                                <?php elseif ($balance > 0): ?>
                                    <span class="text-muted small" title="No permission to record payment">—</span>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_print.php?type=invoice&ids=<?= $orderId ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-dark"
                                   title="View Invoice">
                                    <i class="bi bi-file-earmark-text me-1"></i>Invoice
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="17" class="text-center text-muted py-4">No offline sale orders found.</td></tr>
                <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="offlineOrdersBottomScroll" class="offline-orders-bottom-scroll" aria-label="Horizontal scrollbar for offline sale orders">
                <div class="offline-orders-bottom-scroll-content"></div>
            </div>
        </div>
    </div>
</div>


<div class="offcanvas offcanvas-end offline-filter-panel" tabindex="-1" id="offlineOrdersFilterPanel" aria-labelledby="offlineOrdersFilterPanelLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offlineOrdersFilterPanelLabel">
            <i class="bi bi-funnel-fill me-2"></i>Filter
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form method="get" class="d-flex flex-column gap-3">
            <div>
                <label class="form-label">
                    <i class="bi bi-search me-1"></i>Search Orders
                </label>
                <input type="text" name="search" class="form-control" placeholder="Order code, customer, phone, team..." value="<?= htmlspecialchars($searchQuery) ?>">
            </div>
            <div>
                <label class="form-label">
                    <i class="bi bi-calendar-range me-1"></i>Sale Date
                </label>
                <div class="date-filter-group">
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom ?? '') ?>" title="From date">
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo ?? '') ?>" title="To date">
                </div>
            </div>
            <div>
                <label class="form-label">
                    <i class="bi bi-funnel me-1"></i>Status
                </label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="d-flex gap-2 justify-content-end mt-2">
                <a href="offline_sale_orders.php" class="btn btn-outline-danger">
                    <i class="bi bi-chevron-double-left me-1"></i>Clear and Close
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">
                    <i class="bi bi-x-lg me-1"></i>Close
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>Search
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($canCreateOrder): ?>
<div class="modal fade offline-order-create-modal" id="createOfflineOrderModal" tabindex="-1" aria-labelledby="createOfflineOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createOfflineOrderModalLabel"><i class="bi bi-cart-plus me-2"></i>New Offline Sale</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe
                    id="createOfflineOrderFrame"
                    class="offline-order-create-frame"
                    title="New Offline Sale"
                    src="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_new.php?popup=1"
                    data-src="<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_new.php?popup=1">
                </iframe>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canEditOrder): ?>
<div class="modal fade" id="cancelOfflineOrderModal" tabindex="-1" aria-labelledby="cancelOfflineOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="cancelOfflineOrderForm">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="id" id="cancelOfflineOrderId" value="">
                <input type="hidden" name="return_date_from" value="<?= htmlspecialchars($dateFrom ?? '') ?>">
                <input type="hidden" name="return_date_to" value="<?= htmlspecialchars($dateTo ?? '') ?>">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($searchQuery) ?>">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelOfflineOrderModalLabel">
                        <i class="bi bi-x-square me-2 text-danger"></i>Cancel Order
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1 text-muted small">Order</p>
                    <p class="fw-semibold mb-3" id="cancelOfflineOrderCode"></p>
                    <label class="form-label" for="cancelOfflineOrderNote">Reason</label>
                    <textarea name="cancel_note" id="cancelOfflineOrderNote" class="form-control" rows="4" placeholder="Write the reason for cancelling this order"></textarea>
                    <div class="form-text">Cancelling will restore the product stock and mark this order as cancelled.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Close
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-1"></i>Cancel Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canUpdatePayment): ?>
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="paymentModalForm">
                <input type="hidden" name="action" value="mark_payment">
                <input type="hidden" name="id" id="paymentOrderId" value="">
                <input type="hidden" name="return_date_from" value="<?= htmlspecialchars($dateFrom ?? '') ?>">
                <input type="hidden" name="return_date_to" value="<?= htmlspecialchars($dateTo ?? '') ?>">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($searchQuery) ?>">
                <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentModalLabel"><i class="bi bi-cash-coin me-2"></i>Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1 text-muted small">Order</p>
                    <p class="fw-semibold mb-2" id="paymentOrderCode"></p>
                    <p class="mb-1 text-muted small">Customer</p>
                    <p class="mb-3" id="paymentCustomerName"></p>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <p class="mb-1 text-muted small">Total</p>
                            <p class="fw-semibold mb-0" id="paymentOrderTotal"></p>
                        </div>
                        <div class="col-4">
                            <p class="mb-1 text-muted small">Already Paid</p>
                            <p class="fw-semibold text-success mb-0" id="paymentAlreadyPaid"></p>
                        </div>
                        <div class="col-4">
                            <p class="mb-1 text-muted small">Balance Due</p>
                            <p class="fw-bold text-danger mb-0" id="paymentBalanceDue"></p>
                        </div>
                    </div>
                    <div id="paymentPreviousWrap" class="mb-3 d-none">
                        <p class="mb-1 text-muted small">Previous payments (click date in table to view all)</p>
                        <ul class="list-group list-group-flush small" id="paymentPreviousList"></ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" id="paymentDateInput" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="payment_amount" id="paymentAmountInput" class="form-control" step="0.01" min="0.01" required placeholder="0.00">
                        </div>
                        <div class="form-text">Enter amount received now (max = balance due).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" id="paymentMethodSelect" class="form-select" required>
                            <option value="">Select method</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= htmlspecialchars($method) ?>"><?= htmlspecialchars($method) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Note</label>
                        <input type="text" name="paid_note" id="paymentPaidNote" class="form-control" placeholder="Optional note">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="orderAuditModal" tabindex="-1" aria-labelledby="orderAuditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="orderAuditModalLabel"><i class="bi bi-person-lines-fill me-2"></i>Order Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1 text-muted small">Order</p>
                <p class="fw-semibold mb-1" id="auditOrderCode"></p>
                <p class="mb-3 text-muted small" id="auditCustomerName"></p>
                <div class="border rounded p-3 mb-3 bg-light">
                    <p class="mb-1 text-muted small">Originally created by</p>
                    <p class="fw-semibold mb-0" id="auditCreatedSummary"></p>
                </div>
                <p class="mb-2 fw-semibold"><i class="bi bi-clock-history me-1"></i>Activity history (every update)</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>By</th>
                                <th>Date &amp; time</th>
                            </tr>
                        </thead>
                        <tbody id="auditActivityRows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentHistoryModal" tabindex="-1" aria-labelledby="paymentHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentHistoryModalLabel"><i class="bi bi-calendar-check me-2"></i>Payment History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1 text-muted small">Order</p>
                <p class="fw-semibold mb-1" id="historyOrderCode"></p>
                <p class="mb-3 text-muted small" id="historyCustomerName"></p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Payment Date</th>
                                <th class="text-end">Amount</th>
                                <th>Method</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody id="paymentHistoryRows"></tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Total Paid</th>
                                <th class="text-end" id="historyTotalPaid">$0.00</th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
function parsePaymentsAttr(raw) {
    if (!raw) return [];
    try {
        const data = JSON.parse(raw);
        return Array.isArray(data) ? data : [];
    } catch (e) {
        return [];
    }
}

function renderPaymentHistoryModal(btn) {
    const payments = parsePaymentsAttr(btn.getAttribute('data-payments'));
    document.getElementById('historyOrderCode').textContent = btn.getAttribute('data-order-code') || '';
    document.getElementById('historyCustomerName').textContent = btn.getAttribute('data-customer') || '—';
    const tbody = document.getElementById('paymentHistoryRows');
    tbody.innerHTML = '';
    let total = 0;
    payments.forEach((p, idx) => {
        total += Number(p.amount || 0);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td class="fw-semibold">${p.date || '—'}</td>
            <td class="text-end">$${Number(p.amount || 0).toFixed(2)}</td>
            <td>${p.method ? escapeHtml(p.method) : '—'}</td>
            <td class="text-muted">${p.note ? escapeHtml(p.note) : '—'}</td>
        `;
        tbody.appendChild(tr);
    });
    if (!payments.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No payments recorded.</td></tr>';
    }
    document.getElementById('historyTotalPaid').textContent = '$' + total.toFixed(2);
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

function formatAuditDatetime(value) {
    if (!value || value === '—') return '—';
    const d = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function parseActivityAttr(raw) {
    if (!raw) return [];
    try {
        const data = JSON.parse(raw);
        return Array.isArray(data) ? data : [];
    } catch (e) {
        return [];
    }
}

function activityBadgeClass(action) {
    if (action === 'created') return 'bg-primary';
    if (action === 'payment') return 'bg-success';
    if (action === 'updated') return 'bg-secondary';
    return 'bg-light text-dark';
}

var createOfflineOrderModal = document.getElementById('createOfflineOrderModal');
var createOfflineOrderFrame = document.getElementById('createOfflineOrderFrame');
function openOfflineOrderForm(trigger) {
    const formTitle = trigger?.getAttribute('data-order-form-title') || 'New Offline Sale';
    const formSrc = trigger?.getAttribute('data-order-form-src') || '<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_new.php?popup=1';
    const titleEl = document.getElementById('createOfflineOrderModalLabel');
    if (titleEl) {
        titleEl.innerHTML = '<i class="bi bi-cart-plus me-2"></i>' + escapeHtml(formTitle);
    }
    if (createOfflineOrderFrame) {
        createOfflineOrderFrame.removeAttribute('src');
        createOfflineOrderFrame.src = formSrc;
    }
}
window.openOfflineOrderForm = openOfflineOrderForm;
function closeOfflineOrderFormPopup() {
    if (createOfflineOrderModal) {
        bootstrap.Modal.getOrCreateInstance(createOfflineOrderModal).hide();
    }
    if (createOfflineOrderFrame) {
        createOfflineOrderFrame.src = 'about:blank';
    }
}
window.closeOfflineOrderFormPopup = closeOfflineOrderFormPopup;
createOfflineOrderModal?.addEventListener('show.bs.modal', function (event) {
    if (!createOfflineOrderFrame?.src || createOfflineOrderFrame.src === 'about:blank') {
        openOfflineOrderForm(event.relatedTarget);
    }
});
createOfflineOrderModal?.addEventListener('hidden.bs.modal', function () {
    if (createOfflineOrderFrame) {
        createOfflineOrderFrame.src = 'about:blank';
    }
});
createOfflineOrderFrame?.addEventListener('load', function () {
    let frameUrl = '';
    try {
        frameUrl = createOfflineOrderFrame.contentWindow.location.href;
    } catch (e) {
        return;
    }
    if (frameUrl.includes('offline_sale_orders.php') && (frameUrl.includes('created=1') || frameUrl.includes('updated=1'))) {
        window.location.href = frameUrl.includes('updated=1') ? 'offline_sale_orders.php?updated=1' : 'offline_sale_orders.php?created=1';
    } else if (frameUrl.includes('offline_sale_orders.php')) {
        closeOfflineOrderFormPopup();
    }
});

function openOfflineOrderCancel(orderId, orderCode) {
    if (!orderId) {
        return;
    }
    const idInput = document.getElementById('cancelOfflineOrderId');
    const codeText = document.getElementById('cancelOfflineOrderCode');
    const noteInput = document.getElementById('cancelOfflineOrderNote');
    if (idInput) {
        idInput.value = orderId;
    }
    if (codeText) {
        codeText.textContent = orderCode || 'Selected order';
    }
    if (noteInput) {
        noteInput.value = '';
    }
    const modalEl = document.getElementById('cancelOfflineOrderModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

document.addEventListener('click', function (event) {
    const editBtn = event.target.closest('.js-edit-offline-order');
    if (editBtn) {
        openOfflineOrderForm(editBtn);
        if (createOfflineOrderModal) {
            bootstrap.Modal.getOrCreateInstance(createOfflineOrderModal).show();
        }
        return;
    }
    const cancelBtn = event.target.closest('.js-cancel-offline-order');
    if (cancelBtn) {
        openOfflineOrderCancel(
            cancelBtn.getAttribute('data-order-id') || '',
            cancelBtn.getAttribute('data-order-code') || ''
        );
    }
});

document.getElementById('orderAuditModal')?.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    document.getElementById('auditOrderCode').textContent = btn.getAttribute('data-order-code') || '';
    document.getElementById('auditCustomerName').textContent = btn.getAttribute('data-customer') || '—';
    const createdBy = btn.getAttribute('data-created-by') || '—';
    const createdAt = btn.getAttribute('data-created-at') || '—';
    document.getElementById('auditCreatedSummary').textContent = createdBy + ' · ' + createdAt;
    const activities = parseActivityAttr(btn.getAttribute('data-activity'));
    const tbody = document.getElementById('auditActivityRows');
    tbody.innerHTML = '';
    if (!activities.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No activity recorded yet.</td></tr>';
        return;
    }
    activities.forEach((row, idx) => {
        const tr = document.createElement('tr');
        const action = row.action || '';
        const label = row.action_label || action;
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><span class="badge ${activityBadgeClass(action)}">${escapeHtml(label)}</span></td>
            <td>${escapeHtml(row.description || '—')}</td>
            <td>${escapeHtml(row.by || '—')}</td>
            <td class="text-muted small text-nowrap">${row.at ? escapeHtml(formatAuditDatetime(row.at)) : '—'}</td>
        `;
        tbody.appendChild(tr);
    });
});

document.getElementById('paymentHistoryModal')?.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (btn) renderPaymentHistoryModal(btn);
});

document.getElementById('paymentModal')?.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    document.getElementById('paymentOrderId').value = btn.getAttribute('data-order-id') || '';
    document.getElementById('paymentOrderCode').textContent = btn.getAttribute('data-order-code') || '';
    document.getElementById('paymentCustomerName').textContent = btn.getAttribute('data-customer') || '—';
    const total = parseFloat(btn.getAttribute('data-total') || '0');
    const paid = parseFloat(btn.getAttribute('data-paid') || '0');
    const balance = parseFloat(btn.getAttribute('data-balance') || '0');
    document.getElementById('paymentOrderTotal').textContent = '$' + total.toFixed(2);
    document.getElementById('paymentAlreadyPaid').textContent = '$' + paid.toFixed(2);
    document.getElementById('paymentBalanceDue').textContent = '$' + balance.toFixed(2);
    const amountInput = document.getElementById('paymentAmountInput');
    if (amountInput) {
        amountInput.value = balance > 0 ? balance.toFixed(2) : '';
        amountInput.max = balance > 0 ? balance.toFixed(2) : '';
    }
    const dateInput = document.getElementById('paymentDateInput');
    if (dateInput) dateInput.value = new Date().toISOString().slice(0, 10);
    const select = document.getElementById('paymentMethodSelect');
    if (select) select.value = btn.getAttribute('data-payment-method') || '';
    document.getElementById('paymentPaidNote').value = '';

    const payments = parsePaymentsAttr(btn.getAttribute('data-payments'));
    const wrap = document.getElementById('paymentPreviousWrap');
    const list = document.getElementById('paymentPreviousList');
    if (wrap && list) {
        list.innerHTML = '';
        if (payments.length) {
            wrap.classList.remove('d-none');
            payments.forEach(p => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between px-0';
                li.innerHTML = `<span>${p.date} · ${p.method ? escapeHtml(p.method) : '—'}</span><span class="fw-semibold">$${Number(p.amount || 0).toFixed(2)}</span>`;
                list.appendChild(li);
            });
        } else {
            wrap.classList.add('d-none');
        }
    }
});

// --- Offline order checkbox selection & print toolbar ---
(function () {
    const selectAll = document.getElementById('selectAllOfflineOrders');
    const toolbar = document.getElementById('offlinePrintToolbar');
    const countEl = document.getElementById('offlinePrintCount');
    const clearBtn = document.getElementById('offlinePrintClear');
    const receiptBtn  = document.getElementById('offlinePrintReceiptBtn');
    const invoiceBtn  = document.getElementById('offlinePrintInvoiceBtn');
    const deliveryBtn = document.getElementById('offlinePrintDeliveryBtn');
    const printBase = '<?= htmlspecialchars($BASE_URL) ?>/admin/offline_sale_print.php';

    function getChecked() {
        return Array.from(document.querySelectorAll('.offline-order-checkbox:checked'));
    }

    function updateToolbar() {
        const checked = getChecked();
        if (checked.length === 0) {
            toolbar.classList.add('d-none');
            toolbar.classList.remove('d-flex');
        } else {
            toolbar.classList.remove('d-none');
            toolbar.classList.add('d-flex');
            countEl.textContent = checked.length + ' selected';
            const ids = checked.map(cb => cb.value).join(',');
            receiptBtn.href  = printBase + '?type=receipt&ids='  + ids;
            invoiceBtn.href  = printBase + '?type=invoice&ids='  + ids;
            deliveryBtn.href = printBase + '?type=delivery&ids=' + ids;
        }
        const allCbs = document.querySelectorAll('.offline-order-checkbox:not(:disabled)');
        selectAll.indeterminate = checked.length > 0 && checked.length < allCbs.length;
        selectAll.checked = checked.length > 0 && checked.length === allCbs.length;
    }

    selectAll?.addEventListener('change', function () {
        document.querySelectorAll('.offline-order-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = selectAll.checked;
        });
        updateToolbar();
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('offline-order-checkbox')) {
            updateToolbar();
        }
    });

    clearBtn?.addEventListener('click', function () {
        document.querySelectorAll('.offline-order-checkbox').forEach(cb => { cb.checked = false; });
        if (selectAll) selectAll.checked = false;
        updateToolbar();
    });
})();

document.getElementById('paymentModalForm')?.addEventListener('submit', function (e) {
    const amountInput = document.getElementById('paymentAmountInput');
    const balance = parseFloat(document.getElementById('paymentBalanceDue')?.textContent?.replace(/[^0-9.-]/g, '') || '0');
    const amount = parseFloat(amountInput?.value || '0');
    if (amount <= 0) {
        e.preventDefault();
        alert('Please enter a payment amount greater than zero.');
        return;
    }
    if (amount > balance + 0.009) {
        e.preventDefault();
        alert('Payment amount cannot exceed balance due ($' + balance.toFixed(2) + ').');
    }
});

(function initOfflineOrdersBottomScrollbar() {
    const tableScroll = document.querySelector('.orders-table-scroll');
    const bottomScroll = document.getElementById('offlineOrdersBottomScroll');
    const bottomContent = bottomScroll?.querySelector('.offline-orders-bottom-scroll-content');
    if (!tableScroll || !bottomScroll || !bottomContent) return;

    let syncing = false;

    function updateScrollbar() {
        const rect = tableScroll.getBoundingClientRect();
        const hasOverflow = tableScroll.scrollWidth > tableScroll.clientWidth + 1;
        const tableIsVisible = rect.top < window.innerHeight && rect.bottom > 18;

        bottomContent.style.width = tableScroll.scrollWidth + 'px';
        bottomScroll.style.left = Math.max(0, rect.left) + 'px';
        bottomScroll.style.width = Math.max(0, Math.min(rect.right, window.innerWidth) - Math.max(0, rect.left)) + 'px';
        bottomScroll.classList.toggle('is-visible', hasOverflow && tableIsVisible);

        if (!syncing) {
            bottomScroll.scrollLeft = tableScroll.scrollLeft;
        }
    }

    tableScroll.addEventListener('scroll', function () {
        if (syncing) return;
        syncing = true;
        bottomScroll.scrollLeft = tableScroll.scrollLeft;
        syncing = false;
    });

    bottomScroll.addEventListener('scroll', function () {
        if (syncing) return;
        syncing = true;
        tableScroll.scrollLeft = bottomScroll.scrollLeft;
        syncing = false;
    });

    window.addEventListener('resize', updateScrollbar);
    window.addEventListener('scroll', updateScrollbar, {passive: true});
    if (window.ResizeObserver) {
        new ResizeObserver(updateScrollbar).observe(tableScroll);
    }
    updateScrollbar();
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
