<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'receipts.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();
$user = current_user();
$receipt_order_id = (int)($_GET['id'] ?? 0);

if ($receipt_order_id <= 0) {
    die('Invalid receipt order ID');
}

$current_month = date('Y-m');

$productsStmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        COALESCE(pc.selling_price, p.cost) as cost
    FROM products p
    LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
    WHERE p.active = 1
    ORDER BY p.name
");
$productsStmt->execute([$current_month]);
$products = $productsStmt->fetchAll();

$pages = $pdo->query('SELECT id, name FROM pages ORDER BY name')->fetchAll();
$types = $pdo->query('SELECT id, name FROM delivery_types ORDER BY name')->fetchAll();
$costs = $pdo->query('SELECT id, label, amount FROM delivery_costs ORDER BY amount')->fetchAll();
$sellers = $pdo->query('SELECT id, name, username FROM users WHERE role = "seller" ORDER BY name')->fetchAll();

$errors = [];
$success = '';

function fetch_receipt_order_for_edit(PDO $pdo, int $receiptOrderId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM receipt_orders WHERE id = ?');
    $stmt->execute([$receiptOrderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

function fetch_receipt_items_for_edit(PDO $pdo, int $receiptOrderId): array {
    $stmt = $pdo->prepare('
        SELECT product_id, quantity
        FROM receipt_order_items
        WHERE receipt_order_id = ?
        ORDER BY id
    ');
    $stmt->execute([$receiptOrderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$receipt_order = fetch_receipt_order_for_edit($pdo, $receipt_order_id);
if (!$receipt_order) {
    die('Receipt order not found');
}

$submittedProductRows = fetch_receipt_items_for_edit($pdo, $receipt_order_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'receipts.view');

    $customer_name    = trim($_POST['customer_name'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $location         = trim($_POST['location'] ?? '');
    $page_id          = (int)($_POST['page_id'] ?? 0);
    $delivery_type_id = (int)($_POST['delivery_type_id'] ?? 0);
    $delivery_cost_id = (int)($_POST['delivery_cost_id'] ?? 0);
    $seller_id        = (int)($_POST['seller_id'] ?? 0);
    $statusRaw        = trim($_POST['status'] ?? 'preparing');
    $status           = in_array($statusRaw, ['preparing', 'completed', 'cancelled'], true) ? $statusRaw : 'preparing';
    $notes            = trim($_POST['notes'] ?? '');
    $discountRaw      = trim($_POST['discount'] ?? '');
    $discount         = $discountRaw === '' ? 0 : (float)$discountRaw;
    if ($discount < 0) {
        $discount = 0;
    }

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $submittedProductRows = [];
    foreach ($product_ids as $idx => $pidRaw) {
        $submittedProductRows[] = [
            'product_id' => (int)$pidRaw,
            'quantity' => max(1, (int)($quantities[$idx] ?? 1)),
        ];
    }

    if ($customer_name === '') {
        $errors[] = 'Customer name is required.';
    }
    $phoneError = validate_customer_phones($phone);
    if ($phoneError !== null) {
        $errors[] = $phoneError;
    }
    if ($location === '') {
        $errors[] = 'Location is required.';
    }
    if ($delivery_cost_id <= 0) {
        $errors[] = 'Delivery cost is required.';
    }
    if ($seller_id <= 0) {
        $errors[] = 'Seller selection is required.';
    }

    $itemsByProduct = [];
    foreach ($product_ids as $idx => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$idx] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $stmt = $pdo->prepare("
                SELECT p.id, p.name, COALESCE(pc.selling_price, p.cost) as cost
                FROM products p
                LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
                WHERE p.id = ?
            ");
            $stmt->execute([$current_month, $pid]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $productKey = (int)$prod['id'];
                $unitCost = (float)$prod['cost'];
                if (!isset($itemsByProduct[$productKey])) {
                    $itemsByProduct[$productKey] = [
                        'product_id' => $prod['id'],
                        'unit_cost' => $unitCost,
                        'quantity' => 0,
                        'line_total' => 0,
                    ];
                }
                $itemsByProduct[$productKey]['quantity'] += $qty;
                $itemsByProduct[$productKey]['line_total'] = $itemsByProduct[$productKey]['unit_cost'] * $itemsByProduct[$productKey]['quantity'];
            }
        }
    }

    $items = array_values($itemsByProduct);
    if (empty($items)) {
        $errors[] = 'Please select at least one product to prepare.';
    }

    $totalProducts = 0;
    foreach ($items as $item) {
        $totalProducts += (float)$item['line_total'];
    }
    $grandTotal = $totalProducts - $discount;
    if ($grandTotal < 0) {
        $grandTotal = 0;
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                UPDATE receipt_orders
                SET customer_name = ?, seller_id = ?, phone = ?, location = ?, page_id = ?, delivery_type_id = ?,
                    delivery_cost_id = ?, discount = ?, total_amount = ?, notes = ?, status = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $customer_name,
                $seller_id,
                $phone,
                $location,
                $page_id ?: null,
                $delivery_type_id ?: null,
                $delivery_cost_id ?: null,
                $discount,
                $grandTotal,
                $notes ?: null,
                $status,
                $receipt_order_id,
            ]);

            $pdo->prepare('DELETE FROM receipt_order_items WHERE receipt_order_id = ?')->execute([$receipt_order_id]);
            $itemsStmt = $pdo->prepare('INSERT INTO receipt_order_items (receipt_order_id, product_id, quantity, unit_cost, line_total) VALUES (?,?,?,?,?)');
            foreach ($items as $item) {
                $itemsStmt->execute([
                    $receipt_order_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['line_total'],
                ]);
            }

            $pdo->commit();
            $success = 'Receipt order updated successfully.';
            $receipt_order = fetch_receipt_order_for_edit($pdo, $receipt_order_id);
            $submittedProductRows = fetch_receipt_items_for_edit($pdo, $receipt_order_id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to update receipt order: ' . $e->getMessage();
        }
    }
}

$customer_name = (string)($receipt_order['customer_name'] ?? '');
$phone = (string)($receipt_order['phone'] ?? '');
$location = (string)($receipt_order['location'] ?? '');
$page_id = (int)($receipt_order['page_id'] ?? 0);
$delivery_type_id = (int)($receipt_order['delivery_type_id'] ?? 0);
$delivery_cost_id = (int)($receipt_order['delivery_cost_id'] ?? 0);
$seller_id = (int)($receipt_order['seller_id'] ?? 0);
$status = (string)($receipt_order['status'] ?? 'preparing');
$notes = (string)($receipt_order['notes'] ?? '');
$discount = (float)($receipt_order['discount'] ?? 0);
?>

<?php include __DIR__ . '/../layout/header.php'; ?>

<style>
    .receipt-product-row {
        background: rgba(255, 255, 255, 0.72);
        border-radius: 1rem;
        padding: 0.75rem;
    }
    .receipt-line-total {
        min-width: 6.25rem;
        display: inline-block;
    }
    .receipt-notes-input {
        border-radius: 1rem;
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
    }
</style>

<div class="row flex-grow-1">
    <div class="col-12 col-lg-8 mx-auto py-3">
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" id="receiptOrderForm">
            <div class="seller-panel seller-panel-yellow">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <div class="fw-bold" style="font-size:1rem;">Edit receipt order</div>
                        <div class="text-muted" style="font-size:0.85rem;">Code: <?php echo htmlspecialchars($receipt_order['receipt_code']); ?></div>
                        <div class="mt-1">
                            <span class="badge bg-info" style="font-size:11px;">Using costs for: <?php echo date('F Y', strtotime($current_month . '-01')); ?></span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="history_receipt.php" class="btn btn-light btn-add-more">History</a>
                        <a href="receipt_order_receipt.php?id=<?php echo (int)$receipt_order_id; ?>" target="_blank" class="btn btn-light btn-add-more">Print</a>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Customer Name</div>
                        <input type="text" class="form-control form-control-lg seller-input" name="customer_name" value="<?php echo htmlspecialchars($customer_name); ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Seller</div>
                        <select class="form-select form-select-lg seller-select" name="seller_id" required>
                            <option value="">Select Seller</option>
                            <?php foreach ($sellers as $seller): ?>
                                <option value="<?php echo (int)$seller['id']; ?>" <?php echo $seller_id === (int)$seller['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($seller['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="seller-label mb-1">Products to Prepare</div>
                <div id="products-container" class="d-flex flex-column gap-2 mb-2"></div>
                <button type="button" class="btn btn-light btn-add-more mt-1" id="add-product">Add product</button>
            </div>

            <div class="seller-panel seller-panel-pink">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Delivery Type</div>
                        <select class="form-select form-select-lg seller-select" name="delivery_type_id">
                            <option value="">Select Type</option>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo (int)$type['id']; ?>" <?php echo $delivery_type_id === (int)$type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Delivery Cost</div>
                        <select class="form-select form-select-lg seller-select" name="delivery_cost_id" required>
                            <option value="">Select Cost</option>
                            <?php foreach ($costs as $cost): ?>
                                <option value="<?php echo (int)$cost['id']; ?>" <?php echo $delivery_cost_id === (int)$cost['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cost['label']); ?> - $<?php echo number_format((float)$cost['amount'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="seller-label mb-1">Page</div>
                        <select class="form-select form-select-lg seller-select" name="page_id">
                            <option value="">Select Page</option>
                            <?php foreach ($pages as $page): ?>
                                <option value="<?php echo (int)$page['id']; ?>" <?php echo $page_id === (int)$page['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($page['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="seller-label mb-1">Status</div>
                        <select class="form-select form-select-lg seller-select" name="status">
                            <option value="preparing" <?php echo $status === 'preparing' ? 'selected' : ''; ?>>Preparing</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="seller-label mb-1">Discount</div>
                        <input type="number" class="form-control form-control-lg seller-input" name="discount" id="discountInput" value="<?php echo htmlspecialchars((string)$discount); ?>" step="0.01" min="0">
                    </div>
                </div>
            </div>

            <div class="seller-panel seller-panel-peach">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Location</div>
                        <input type="text" class="form-control form-control-lg seller-input" name="location" value="<?php echo htmlspecialchars($location); ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Phone number</div>
                        <input type="text" class="form-control form-control-lg seller-input" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="seller-label mb-1">Notes</div>
                    <textarea class="form-control seller-input receipt-notes-input" name="notes" rows="2"><?php echo htmlspecialchars($notes); ?></textarea>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="seller-label mb-1">Total amount</div>
                        <div class="fw-bold" id="totalAmount">$0.00</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="history_receipt.php" class="btn btn-cancel-soft btn-lg">Cancel</a>
                        <button type="submit" class="btn btn-next btn-lg" style="color:#fff;">Save Receipt</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const products = <?php echo json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const initialRows = <?php echo json_encode($submittedProductRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const rowsContainer = document.getElementById('products-container');
    const addProductBtn = document.getElementById('add-product');
    const totalEl = document.getElementById('totalAmount');
    const discountInput = document.getElementById('discountInput');

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function productOptions(selectedId) {
        const sid = String(selectedId || '');
        return products.map(product => {
            const selected = String(product.id) === sid ? ' selected' : '';
            const cost = parseFloat(product.cost || 0);
            return '<option value="' + product.id + '" data-cost="' + cost + '"' + selected + '>' + escapeHtml(product.name) + ' - $' + cost.toFixed(2) + '</option>';
        }).join('');
    }

    function createRow(selectedProductId = '', quantity = 1) {
        const row = document.createElement('div');
        row.className = 'receipt-product-row row g-2 align-items-center';
        row.innerHTML = `
            <div class="col-12 col-md-6">
                <select class="form-select form-select-lg seller-select product-select" name="product_id[]" required>
                    <option value="">Select Product</option>
                    ${productOptions(selectedProductId)}
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="number" class="form-control form-control-lg seller-input quantity-input" name="quantity[]" placeholder="Qty" min="1" value="${quantity ? escapeHtml(quantity) : '1'}" required>
            </div>
            <div class="col-6 col-md-3 text-md-end">
                <span class="fw-semibold receipt-line-total">$0.00</span>
            </div>
            <div class="col-12 col-md-1 text-md-end">
                <button type="button" class="btn btn-outline-danger btn-sm px-3 remove-product">X</button>
            </div>
        `;
        rowsContainer.appendChild(row);
        mergeDuplicateRows();
    }

    function mergeDuplicateRows() {
        const grouped = new Map();
        Array.from(rowsContainer.querySelectorAll('.receipt-product-row')).forEach(row => {
            const select = row.querySelector('.product-select');
            const productId = select && select.value ? String(select.value) : '';
            if (!productId) return;
            if (!grouped.has(productId)) grouped.set(productId, []);
            grouped.get(productId).push(row);
        });
        grouped.forEach(groupRows => {
            if (groupRows.length < 2) return;
            const keepQty = groupRows[0].querySelector('.quantity-input');
            let total = parseInt(keepQty.value || '0', 10);
            if (isNaN(total) || total < 1) total = 1;
            for (let i = 1; i < groupRows.length; i++) {
                const qty = parseInt(groupRows[i].querySelector('.quantity-input').value || '0', 10);
                total += isNaN(qty) || qty < 1 ? 1 : qty;
                groupRows[i].remove();
            }
            keepQty.value = total;
        });
        updateTotals();
    }

    function updateTotals() {
        let productTotal = 0;
        rowsContainer.querySelectorAll('.receipt-product-row').forEach(row => {
            const select = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.quantity-input');
            const totalCell = row.querySelector('.receipt-line-total');
            const qty = parseInt(qtyInput.value || '0', 10);
            let lineTotal = 0;
            if (select.value && qty > 0) {
                const selected = select.selectedOptions[0];
                const cost = selected ? parseFloat(selected.dataset.cost || '0') : 0;
                lineTotal = cost * qty;
            }
            productTotal += lineTotal;
            totalCell.textContent = '$' + lineTotal.toFixed(2);
        });

        let discount = parseFloat(discountInput.value || '0');
        if (isNaN(discount) || discount < 0) discount = 0;
        let grandTotal = productTotal - discount;
        if (grandTotal < 0) grandTotal = 0;
        totalEl.textContent = '$' + grandTotal.toFixed(2);
    }

    addProductBtn.addEventListener('click', () => createRow());
    rowsContainer.addEventListener('input', updateTotals);
    rowsContainer.addEventListener('change', function(e) {
        if (e.target.classList.contains('product-select')) {
            const row = e.target.closest('.receipt-product-row');
            const qtyInput = row ? row.querySelector('.quantity-input') : null;
            if (e.target.value && qtyInput && (!qtyInput.value || parseInt(qtyInput.value, 10) < 1)) {
                qtyInput.value = '1';
            }
            mergeDuplicateRows();
        } else if (e.target.classList.contains('quantity-input')) {
            mergeDuplicateRows();
        } else {
            updateTotals();
        }
    });
    discountInput.addEventListener('input', updateTotals);
    rowsContainer.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-product')) {
            const row = e.target.closest('.receipt-product-row');
            if (row) {
                row.remove();
                updateTotals();
            }
        }
    });
    document.getElementById('receiptOrderForm').addEventListener('submit', function(e) {
        const hasProduct = Array.from(rowsContainer.querySelectorAll('.product-select')).some(select => select.value);
        if (!hasProduct) {
            e.preventDefault();
            alert('Please select at least one product to prepare.');
        }
    });

    if (Array.isArray(initialRows) && initialRows.length > 0) {
        initialRows.forEach(row => createRow(row.product_id || '', row.quantity || 1));
    } else {
        createRow();
    }
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
