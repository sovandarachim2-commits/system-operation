<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['seller'], 'order_history.view');
require_once __DIR__ . '/../helpers.php';

$pdo  = get_db_connection();
ensure_order_items_lucky_box_column($pdo);
$user = current_user();

$errors  = [];
$success = '';
$warning = '';

if (!empty($_SESSION['order_flash_success'])) {
    $success = (string)$_SESSION['order_flash_success'];
    unset($_SESSION['order_flash_success']);
}
if (!empty($_SESSION['order_flash_warning'])) {
    $warning = (string)$_SESSION['order_flash_warning'];
    unset($_SESSION['order_flash_warning']);
}

if ($success === '' && isset($_GET['created']) && $_GET['created'] == '1') {
    $success = 'Order created successfully.';
} elseif ($success === '' && isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success = 'Order updated successfully.';
}

// Load dropdown data for edit modal
$pagesStmt = $pdo->query('SELECT id, name FROM pages ORDER BY name');
$pages     = $pagesStmt->fetchAll();

$typesStmt = $pdo->query('SELECT id, name FROM delivery_types ORDER BY name');
$types     = $typesStmt->fetchAll();

$costsStmt = $pdo->query('SELECT id, label FROM delivery_costs ORDER BY amount');
$costs     = $costsStmt->fetchAll();

// Handle update status / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['seller', 'admin'], 'seller_orders.update');
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        // Ensure order belongs to current seller
        $check = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND seller_id = ?');
        $check->execute([$id, $user['id']]);
        $own = $check->fetchColumn();

        if ($own) {
            if ($action === 'update_status') {
                $customer_name    = trim($_POST['customer_name'] ?? '');
                $phone            = trim($_POST['phone'] ?? '');
                $location         = trim($_POST['location'] ?? '');
                $page_id          = (int)($_POST['page_id'] ?? 0);
                $delivery_type_id = (int)($_POST['delivery_type_id'] ?? 0);
                $delivery_cost_id = (int)($_POST['delivery_cost_id'] ?? 0);
                $status           = $_POST['status'] === 'paid' ? 'paid' : 'unpaid';
                $paid_note        = trim($_POST['paid_note'] ?? '');

                $phoneError = validate_customer_phones($phone);
                if ($customer_name === '' || $phoneError !== null) {
                    if ($customer_name === '') {
                        $errors[] = 'Customer name is required.';
                    }
                    if ($phoneError !== null) {
                        $errors[] = $phoneError;
                    }
                } else {
                    // Load products and existing items for calculation
                    $productsStmt = $pdo->query('SELECT id, name, cost FROM products ORDER BY name');
                    $products     = $productsStmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

                    $product_ids = $_POST['product_id'] ?? [];
                    $quantities  = $_POST['quantity'] ?? [];

                    $items         = [];
                    $totalProducts = 0;
                    foreach ($product_ids as $idx => $pid) {
                        $pid = (int)$pid;
                        $qty = (int)($quantities[$idx] ?? 0);
                        if ($pid > 0 && $qty > 0 && isset($products[$pid])) {
                            $prod        = $products[$pid];
                            $unitCost    = (float)$prod['cost'];
                            $lineTotal   = $unitCost * $qty;
                            $totalProducts += $lineTotal;
                            $items[]     = [
                                'product_id' => $pid,
                                'quantity'   => $qty,
                                'unit_cost'  => $unitCost,
                                'line_total' => $lineTotal,
                            ];
                        }
                    }

                    if (!$items) {
                        $errors[] = 'Please add at least one product.';
                    }

                    if ($status === 'paid' && $paid_note === '') {
                        $errors[] = 'Paid confirmation note is required when status is Paid.';
                    }

                    if (!$errors) {
                        try {
                            $pdo->beginTransaction();

                            // Fetch delivery cost amount
                            $deliveryAmount = 0;
                            if ($delivery_cost_id > 0) {
                                $cstmt = $pdo->prepare('SELECT amount FROM delivery_costs WHERE id = ?');
                                $cstmt->execute([$delivery_cost_id]);
                                $row = $cstmt->fetch();
                                if ($row && $row['amount'] !== null) {
                                    $deliveryAmount = (float)$row['amount'];
                                }
                            }

                            $grandTotal = $totalProducts + $deliveryAmount;

                            $stmt = $pdo->prepare('UPDATE orders
                                SET customer_name = ?, phone = ?, location = ?, page_id = ?, delivery_type_id = ?, delivery_cost_id = ?, status = ?, paid_note = ?, total_amount = ?
                                WHERE id = ?');
                            $stmt->execute([
                                $customer_name,
                                $phone,
                                $location,
                                $page_id ?: null,
                                $delivery_type_id ?: null,
                                $delivery_cost_id ?: null,
                                $status,
                                $status === 'paid' ? $paid_note : null,
                                $grandTotal,
                                $id,
                            ]);

                            // Replace order items
                            $delStmt = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
                            $delStmt->execute([$id]);

                            $itemsStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_cost, line_total, is_lucky_box) VALUES (?,?,?,?,?,?)');
                            foreach ($items as $item) {
                                $itemsStmt->execute([
                                    $id,
                                    $item['product_id'],
                                    $item['quantity'],
                                    $item['unit_cost'],
                                    $item['line_total'],
                                    0,
                                ]);
                            }

                            $pdo->commit();

                            // Send an updated Telegram message as a reply to the original one (if it exists)
                            send_order_to_telegram_async($pdo, $id);

                            $success = 'Order updated.';
                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            $errors[] = 'Failed to update order.';
                        }
                    }
                }
            } elseif ($action === 'cancel_telegram') {
                // Send a cancel order message as a reply in Telegram, including seller-provided reason
                $cancel_reason = trim($_POST['cancel_reason'] ?? '');
                send_order_cancel_telegram($pdo, $id, $cancel_reason);

                // Also mark order as cancelled so cashier can see it and cannot print
                $upd = $pdo->prepare('UPDATE orders SET is_cancelled = 1, status = \'cancelled\', is_paid = 0, payment_method = NULL, paid_note = NULL, payment_date = NULL WHERE id = ? AND seller_id = ?');
                $upd->execute([$id, $user['id']]);

                $success = 'Cancel order message sent to Telegram.';
            } elseif ($action === 'delete') {
                // Legacy delete action no longer used; keep orders for history
                $success = 'Delete is disabled. Use Cancel instead.';
            }
        }
    }
}

$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';
$limitRaw     = $_GET['limit'] ?? '100';
$limit        = in_array($limitRaw, ['100', '200', 'all'], true) ? $limitRaw : '100';
$printStatus  = in_array($_GET['print_status'] ?? '', ['printed', 'pending'], true) ? $_GET['print_status'] : 'all';

$params = [$user['id']];
$sql    = 'SELECT o.*, p.name AS page_name, dt.name AS delivery_type_name, dc.label AS delivery_cost_label,
                  CASE WHEN pj.id IS NULL THEN 0 ELSE 1 END AS is_printed
           FROM orders o
           LEFT JOIN pages p ON o.page_id = p.id
           LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
           LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
           LEFT JOIN print_jobs pj ON pj.order_id = o.id
           WHERE o.seller_id = ?';

if ($start_date !== '') {
    $sql      .= ' AND DATE(o.created_at) >= ?';
    $params[] = $start_date;
}
if ($end_date !== '') {
    $sql      .= ' AND DATE(o.created_at) <= ?';
    $params[] = $end_date;
}
if ($printStatus === 'printed') {
    $sql .= ' AND pj.id IS NOT NULL';
} elseif ($printStatus === 'pending') {
    $sql .= ' AND pj.id IS NULL AND o.is_cancelled = 0';
}

$sql  .= ' ORDER BY o.created_at DESC';
if ($limit !== 'all') {
    $sql .= ' LIMIT ' . (int)$limit;
}
$stmt  = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Load products for use in edit modals (product editing)
$productsStmt = $pdo->query('SELECT id, name, cost FROM products ORDER BY name');
$allProducts  = $productsStmt->fetchAll();

// Summary totals
$total_orders       = 0;
$paid_total         = 0;
$unpaid_total       = 0;
$printed_completed  = 0;
$printed_pending    = 0;
$cancelled_orders   = 0;
foreach ($orders as $o) {
    if (!empty($o['is_cancelled'])) {
        $cancelled_orders++;
        continue;
    }

    $total_orders++;

    if ($o['status'] === 'paid') {
        $paid_total += $o['total_amount'];
    } else {
        $unpaid_total += $o['total_amount'];
    }
    if (!empty($o['is_printed'])) {
        $printed_completed++;
    } else {
        $printed_pending++;
    }
}
$grand_total = $paid_total + $unpaid_total;

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h1 class="h4 mb-0">My Orders</h1>
            <a href="daily.php" class="btn btn-outline-secondary btn-sm">Daily Summary</a>
        </div>
        <a href="order_new.php" class="btn btn-primary btn-lg">+ New Order</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($warning): ?><div class="alert alert-warning"><?= htmlspecialchars($warning) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body row g-3 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-lg" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-lg" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Print Status</label>
                <select name="print_status" class="form-select form-select-lg">
                    <option value="all"     <?= $printStatus === 'all'     ? 'selected' : '' ?>>All</option>
                    <option value="printed" <?= $printStatus === 'printed' ? 'selected' : '' ?>>Printed</option>
                    <option value="pending" <?= $printStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Rows</label>
                <select name="limit" class="form-select form-select-lg">
                    <option value="100" <?= $limit === '100' ? 'selected' : '' ?>>Top 100</option>
                    <option value="200" <?= $limit === '200' ? 'selected' : '' ?>>Top 200</option>
                    <option value="all" <?= $limit === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary btn-lg w-100">Filter</button>
                <a href="orders.php" class="btn btn-outline-secondary btn-lg w-100">Reset</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-3">
            <div><strong>Total Orders:</strong> <?= $total_orders ?></div>
            <div><strong>Paid Total:</strong> $<?= number_format($paid_total, 2) ?></div>
            <div><strong>Unpaid Total:</strong> $<?= number_format($unpaid_total, 2) ?></div>
            <div><strong>Grand Total:</strong> $<?= number_format($grand_total, 2) ?></div>
            <div><strong>Print Pending:</strong> <?= $printed_pending ?></div>
            <div><strong>Print Completed:</strong> <?= $printed_completed ?></div>
            <div><strong>Cancelled Orders:</strong> <?= $cancelled_orders ?></div>
        </div>
    </div>

    <div class="card shadow-sm flex-grow-1 d-flex flex-column">
        <div class="card-body d-flex flex-column p-0">
            <div class="table-responsive table-responsive-full">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                            <th>No</th>
                            <th>Time</th>
                            <th>Actions</th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Page</th>
                            <th>Delivery</th>
                            <th>Status</th>
                            <th>Print</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="11" class="text-center py-4">No orders found.</td></tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($o['created_at']) ?></td>
                            <td>
                                <?php if (!empty($o['is_cancelled'])): ?>
                                    <span class="badge bg-danger">Cancelled</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="../receipt.php?id=<?= (int)$o['id'] ?>" class="btn btn-outline-primary btn-sm" target="_blank">View</a>
                                        <?php if ((int)$o['is_printed'] === 0): ?>
                                            <a href="order_edit.php?id=<?= (int)$o['id'] ?>" class="btn btn-outline-secondary btn-sm">Edit</a>
                                            <button type="button"
                                                    class="btn btn-outline-danger btn-sm btn-cancel-order-trigger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancelOrderModal"
                                                    data-order-id="<?= (int)$o['id'] ?>"
                                                    data-order-code="<?= htmlspecialchars($o['order_code']) ?>">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($o['order_code']) ?></td>
                            <td><?= htmlspecialchars($o['customer_name']) ?></td>
                            <td><?= htmlspecialchars($o['phone'] ?? '') ?></td>
                            <td><?= htmlspecialchars($o['page_name'] ?? '') ?></td>
                            <td>
                                <?= htmlspecialchars($o['delivery_type_name'] ?? '') ?>
                                <?php if (!empty($o['delivery_cost_label'])): ?>
                                    (<?= htmlspecialchars($o['delivery_cost_label']) ?>)
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $o['status']==='paid'?'bg-success':'bg-warning text-dark' ?>">
                                    <?= strtoupper($o['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($o['is_cancelled'])): ?>
                                    <span class="badge bg-danger">Cancelled</span>
                                <?php elseif ((int)$o['is_printed'] === 1): ?>
                                    <span class="badge bg-success">Completed</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>$<?= number_format($o['total_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Order Reason Modal -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="cancelOrderForm">
        <div class="modal-header">
          <h5 class="modal-title">Cancel order</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="cancel_telegram">
          <input type="hidden" name="id" id="cancelOrderId" value="">
          <div class="mb-3">
            <label class="form-label">Reason for cancel order</label>
            <textarea name="cancel_reason" id="cancelOrderReason" class="form-control" rows="3" required></textarea>
          </div>
          <p class="small text-muted mb-0">This reason will be sent to Telegram together with the cancel order message.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-danger">Send cancel to Telegram</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
    const cancelModalEl = document.getElementById('cancelOrderModal');
    const idInput = document.getElementById('cancelOrderId');
    const reasonInput = document.getElementById('cancelOrderReason');
    if (!cancelModalEl) return;

    cancelModalEl.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        if (!button) return;
        const orderId = button.getAttribute('data-order-id');
        const orderCode = button.getAttribute('data-order-code') || '';
        if (idInput) idInput.value = orderId || '';
        if (reasonInput) {
            reasonInput.value = '';
            reasonInput.placeholder = orderCode ? 'Reason for cancel order ' + orderCode : 'Reason for cancel order';
        }
    });
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
