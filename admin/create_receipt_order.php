<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['seller', 'admin'], 'receipts.view');
require_once __DIR__ . '/../helpers.php';

$pdo  = get_db_connection();
$user = current_user();

// Load dropdown data
$productsStmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        COALESCE(pc.selling_price, p.cost) as cost,
        COALESCE(pc.original_cost, 0) as original_cost
    FROM products p
    LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
    WHERE p.active = 1
    ORDER BY p.name
");
$current_month = date('Y-m');
$productsStmt->execute([$current_month]);
$products = $productsStmt->fetchAll();

$pagesStmt = $pdo->query('SELECT id, name FROM pages ORDER BY name');
$pages     = $pagesStmt->fetchAll();

$typesStmt = $pdo->query('SELECT id, name FROM delivery_types ORDER BY name');
$types     = $typesStmt->fetchAll();

$costsStmt = $pdo->query('SELECT id, label, amount FROM delivery_costs ORDER BY amount');
$costs     = $costsStmt->fetchAll();

// Load sellers for selection
$sellersStmt = $pdo->query('SELECT id, name, username FROM users WHERE role = "seller" ORDER BY name');
$sellers     = $sellersStmt->fetchAll();

$errors  = [];
$success = '';

// Defaults
$customer_name    = '';
$phone            = '';
$location         = '';
$page_id          = 0;
$delivery_type_id = 0;
$delivery_cost_id = 0;
$seller_id        = $user['id']; // Default to current user
$notes            = '';
$discount         = 0;
$submittedProductRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin', 'seller'], 'receipts.create');
    $customer_name    = trim($_POST['customer_name'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $location         = trim($_POST['location'] ?? '');
    $page_id          = (int)($_POST['page_id'] ?? 0);
    $delivery_type_id = (int)($_POST['delivery_type_id'] ?? 0);
    $delivery_cost_id = (int)($_POST['delivery_cost_id'] ?? 0);
    $seller_id        = (int)($_POST['seller_id'] ?? $user['id']);
    $notes            = trim($_POST['notes'] ?? '');
    $discountRaw      = trim($_POST['discount'] ?? '');
    $discount         = $discountRaw === '' ? 0 : (float)$discountRaw;

    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];

    foreach ($product_ids as $idx => $pidRaw) {
        $submittedProductRows[] = [
            'product_id' => (int)$pidRaw,
            'quantity' => max(1, (int)($quantities[$idx] ?? 1)),
        ];
    }

    // Basic validation
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
    $totalProducts = 0;

    foreach ($product_ids as $idx => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$idx] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.name,
                    COALESCE(pc.selling_price, p.cost) as cost
                FROM products p
                LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
                WHERE p.id = ?
            ");
            $stmt->execute([$current_month, $pid]);
            $prod = $stmt->fetch();
            if ($prod) {
                $productKey = (int)$prod['id'];
                $unitCost = (float)$prod['cost'];
                if (!isset($itemsByProduct[$productKey])) {
                    $itemsByProduct[$productKey] = [
                        'product_id' => $prod['id'],
                        'name'       => $prod['name'],
                        'unit_cost'  => $unitCost,
                        'quantity'   => 0,
                        'line_total' => 0,
                    ];
                }
                $itemsByProduct[$productKey]['quantity'] += $qty;
                $itemsByProduct[$productKey]['line_total'] = $itemsByProduct[$productKey]['unit_cost'] * $itemsByProduct[$productKey]['quantity'];
            }
        }
    }

    $items = array_values($itemsByProduct);
    foreach ($items as $item) {
        $totalProducts += (float)$item['line_total'];
    }

    if (empty($items)) {
        $errors[] = 'Please select at least one product to prepare.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $receipt_code = 'REC' . date('ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

            // Calculate total
            $grandTotal = $totalProducts - $discount;
            if ($grandTotal < 0) {
                $grandTotal = 0;
            }

            $stmt = $pdo->prepare('INSERT INTO receipt_orders (receipt_code, customer_name, seller_id, created_by, phone, location, page_id, delivery_type_id, delivery_cost_id, discount, total_amount, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

            $result = $stmt->execute([
                $receipt_code,
                $customer_name,
                $seller_id, // Use selected seller instead of current user
                $user['id'], // Track who created the receipt order
                $phone,
                $location,
                $page_id ?: null,
                $delivery_type_id ?: null,
                $delivery_cost_id ?: null,
                $discount,
                $grandTotal,
                $notes ?: null,
            ]);

            $receipt_order_id = (int)$pdo->lastInsertId();

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
            $success = 'Receipt order created successfully! Code: ' . $receipt_code . ' <a href="receipt_order_receipt.php?id=' . $receipt_order_id . '" class="btn btn-sm btn-success ms-2" target="_blank"><i class="bi bi-printer me-1"></i>Print Receipt</a>';

            // Reset form
            $customer_name = $phone = $location = $notes = '';
            $page_id = $delivery_type_id = $delivery_cost_id = 0;
            $discount = 0;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to create receipt order: ' . $e->getMessage();
        }
    }
}
?>

<?php include __DIR__ . '/../layout/header.php'; ?>

<style>
    .receipt-product-row {
        background: rgba(255, 255, 255, 0.72);
        border-radius: 1rem;
        padding: 0.75rem;
    }

    .receipt-note-soft {
        background: rgba(255, 255, 255, 0.55);
        border-radius: 1rem;
        padding: 0.85rem 1rem;
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
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="receiptOrderForm">
            <div class="seller-panel seller-panel-yellow">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <div class="fw-bold" style="font-size:1rem;">Create receipt order</div>
                        <div class="text-muted" style="font-size:0.85rem;">Prepare products without changing stock or sales totals</div>
                        <div class="mt-1">
                            <span class="badge bg-info" style="font-size:11px;">Using costs for: <?php echo date('F Y', strtotime($current_month . '-01')); ?></span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="history_receipt.php" class="btn btn-light btn-add-more">History</a>
                        <span class="badge bg-light text-dark px-3 py-2">+ Receipt</span>
                    </div>
                </div>

                <div class="receipt-note-soft mb-3">
                    <div class="fw-semibold mb-1">Important note</div>
                    <div class="small text-muted">Receipt orders are for product preparation tracking only. They do not affect stock, revenue reports, or financial calculations.</div>
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
                                <option value="<?php echo (int)$seller['id']; ?>" <?php echo $seller_id == $seller['id'] ? 'selected' : ''; ?>>
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
                                <option value="<?php echo (int)$type['id']; ?>" <?php echo $delivery_type_id == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Delivery Cost</div>
                        <select class="form-select form-select-lg seller-select" name="delivery_cost_id" id="deliveryCostSelect" required>
                            <option value="">Select Cost</option>
                            <?php foreach ($costs as $cost): ?>
                                <option value="<?php echo (int)$cost['id']; ?>" data-amount="<?php echo htmlspecialchars((string)$cost['amount']); ?>" <?php echo $delivery_cost_id == $cost['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cost['label']); ?> - $<?php echo number_format($cost['amount'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Page</div>
                        <select class="form-select form-select-lg seller-select" name="page_id">
                            <option value="">Select Page</option>
                            <?php foreach ($pages as $page): ?>
                                <option value="<?php echo (int)$page['id']; ?>" <?php echo $page_id == $page['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($page['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
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
                        <button type="submit" class="btn btn-next btn-lg" style="color:#fff;">Create Receipt Order</button>
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
    const deliverySelect = document.getElementById('deliveryCostSelect');
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
                <input type="number" class="form-control form-control-lg seller-input quantity-input" name="quantity[]" placeholder="Qty" min="1" value="${quantity ? escapeHtml(quantity) : ''}" required>
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

    function rowProductId(row) {
        const select = row.querySelector('.product-select');
        return select && select.value ? String(select.value) : '';
    }

    function mergeDuplicateRows() {
        const rows = Array.from(rowsContainer.querySelectorAll('.receipt-product-row'));
        const grouped = new Map();

        rows.forEach(row => {
            const productId = rowProductId(row);
            if (!productId) {
                return;
            }
            if (!grouped.has(productId)) {
                grouped.set(productId, []);
            }
            grouped.get(productId).push(row);
        });

        grouped.forEach(groupRows => {
            if (groupRows.length < 2) {
                return;
            }

            const keepRow = groupRows[0];
            const keepQty = keepRow.querySelector('.quantity-input');
            let quantityTotal = parseInt(keepQty.value || '0', 10);
            if (isNaN(quantityTotal) || quantityTotal < 1) {
                quantityTotal = 1;
            }

            for (let i = 1; i < groupRows.length; i++) {
                const qtyInput = groupRows[i].querySelector('.quantity-input');
                const qty = parseInt(qtyInput.value || '0', 10);
                quantityTotal += isNaN(qty) || qty < 1 ? 1 : qty;
                groupRows[i].remove();
            }

            keepQty.value = quantityTotal;
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
        if (isNaN(discount) || discount < 0) {
            discount = 0;
        }

        let grandTotal = productTotal - discount;
        if (grandTotal < 0) {
            grandTotal = 0;
        }

        totalEl.textContent = '$' + grandTotal.toFixed(2);
    }

    addProductBtn.addEventListener('click', function() {
        createRow();
    });

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
    if (deliverySelect) {
        deliverySelect.addEventListener('change', updateTotals);
    }

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
