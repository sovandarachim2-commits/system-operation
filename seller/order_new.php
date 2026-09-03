<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['seller', 'admin'], 'seller_orders.view');
require_once __DIR__ . '/../helpers.php';

$pdo  = get_db_connection();
$user = current_user();

// Load dropdown data - use current month costs from product_costs system
$current_month = date('Y-m');

$hasLuckyBoxColumn = false;
try {
    $luckyColChk = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'is_lucky_box'");
    $hasLuckyBoxColumn = (bool) ($luckyColChk && $luckyColChk->fetch());
} catch (Throwable $e) {
    $hasLuckyBoxColumn = false;
}

// General catalog: all sellable products except sets marked as lucky box (those are only under "Lucky box")
$generalExcludeLuckySql = '';
if ($hasLuckyBoxColumn) {
    $generalExcludeLuckySql = "
    AND NOT (
        COALESCE(NULLIF(p.product_type, ''), 'normal') = 'set'
        AND EXISTS (
            SELECT 1 FROM product_sets ps
            WHERE ps.set_name = p.name
            AND COALESCE(ps.is_lucky_box, 0) = 1
        )
    )";
}

$productsSql = "
    SELECT
        p.id,
        p.name,
        COALESCE(pc.selling_price, p.cost) as cost,
        COALESCE(pc.original_cost, 0) as original_cost,
        COALESCE(pc.supplier_cost, 0) as supplier_cost,
        COALESCE(pc.shipping_cost, 0) as shipping_cost,
        COALESCE(pc.other_costs, 0) as other_costs,
        COALESCE(pc.total_cost, 0) as total_cost,
        pc.month_year,
        CASE
            WHEN pc.original_cost > 0 OR pc.supplier_cost > 0 OR pc.shipping_cost > 0 OR pc.other_costs > 0
            THEN 1
            ELSE 0
        END as has_costs,
        COALESCE(p.brand_id, 0) as brand_id
    FROM products p
    LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
    WHERE p.active = 1
    AND p.id IN (
        SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
    )
    {$generalExcludeLuckySql}
    ORDER BY p.name
";
$productsStmt = $pdo->prepare($productsSql);
$productsStmt->execute([$current_month, $current_month]);
$products = $productsStmt->fetchAll();

// For product sets in the general catalog, find which brand_ids their components belong to
// so the brand filter can show a set when any of its component products matches the selected brand
$setComponentBrands = [];
try {
    $scbRows = $pdo->query("
        SELECT
            p.id AS product_id,
            GROUP_CONCAT(DISTINCT comp.brand_id ORDER BY comp.brand_id SEPARATOR ',') AS brand_ids
        FROM products p
        INNER JOIN product_sets ps ON ps.set_name = p.name
            AND COALESCE(NULLIF(p.product_type, ''), 'normal') = 'set'
        INNER JOIN product_set_items psi ON psi.product_set_id = ps.id
        INNER JOIN products comp ON comp.id = psi.product_id
        WHERE comp.brand_id IS NOT NULL
        GROUP BY p.id
    ")->fetchAll();
    foreach ($scbRows as $r) {
        $setComponentBrands[(int)$r['product_id']] = array_map('intval', explode(',', (string)$r['brand_ids']));
    }
} catch (Throwable $e) {}

// Attach component_brand_ids to every product (empty array for normal products or sets with no branded components)
foreach ($products as &$prod) {
    $prod['component_brand_ids'] = $setComponentBrands[(int)$prod['id']] ?? [];
}
unset($prod);

$luckyProducts = [];
if ($hasLuckyBoxColumn) {
    try {
        $luckyStmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                COALESCE(pc.selling_price, p.cost) as cost,
                COALESCE(pc.original_cost, 0) as original_cost,
                COALESCE(pc.supplier_cost, 0) as supplier_cost,
                COALESCE(pc.shipping_cost, 0) as shipping_cost,
                COALESCE(pc.other_costs, 0) as other_costs,
                COALESCE(pc.total_cost, 0) as total_cost,
                pc.month_year,
                CASE
                    WHEN pc.original_cost > 0 OR pc.supplier_cost > 0 OR pc.shipping_cost > 0 OR pc.other_costs > 0
                    THEN 1
                    ELSE 0
                END as has_costs,
                COALESCE(p.brand_id, 0) as brand_id
            FROM products p
            LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
            INNER JOIN product_sets ps ON ps.set_name = p.name AND COALESCE(ps.is_lucky_box, 0) = 1
            WHERE p.active = 1
            AND COALESCE(NULLIF(p.product_type, ''), 'normal') = 'set'
            AND p.id IN (
                SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
            )
            ORDER BY p.name
        ");
        $luckyStmt->execute([$current_month, $current_month]);
        $luckyProducts = $luckyStmt->fetchAll();
    } catch (Throwable $e) {
        $luckyProducts = [];
    }
}

// Load active brands
$brands = [];
try {
    $brands = $pdo->query("SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $brands = [];
}

$pagesStmt = $pdo->query('SELECT id, name FROM pages ORDER BY name');
$pages     = $pagesStmt->fetchAll();

$typesStmt = $pdo->query('SELECT id, name FROM delivery_types ORDER BY name');
$types     = $typesStmt->fetchAll();

$costsStmt = $pdo->query('SELECT id, label, amount FROM delivery_costs ORDER BY amount');
$costs     = $costsStmt->fetchAll();

// Load payment methods from note_options table
$paymentMethodsStmt = $pdo->query('SELECT id, option_text FROM note_options WHERE is_active = 1 AND is_seller_active = 1 ORDER BY sort_order, option_text');
$paymentMethods     = $paymentMethodsStmt->fetchAll();

$errors  = [];
$success = '';

// Defaults so we can re-fill the form on validation errors
$customer_name    = '';
$phone            = '';
$location         = '';
$page_id          = 0;
$delivery_type_id = 0;
$delivery_cost_id = 0;
$status           = '';
$payment_method   = '';
$paid_note        = '';
$payment_date     = date('Y-m-d');
$discount         = 0;
$submittedProductRows = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['clear_preview']) && $_GET['clear_preview'] === '1') {
    unset($_SESSION['pending_new_order']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['clear_preview'])) {
    unset($_SESSION['pending_new_order']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['seller', 'admin'], 'seller_orders.create');
    $customer_name    = trim($_POST['customer_name'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $location         = trim($_POST['location'] ?? '');
    $page_id          = (int)($_POST['page_id'] ?? 0);
    $delivery_type_id = (int)($_POST['delivery_type_id'] ?? 0);
    $delivery_cost_id = (int)($_POST['delivery_cost_id'] ?? 0);
    $statusRaw = trim($_POST['status'] ?? '');
    $status = in_array($statusRaw, ['paid', 'unpaid'], true) ? $statusRaw : '';
    $payment_method = trim($_POST['payment_method'] ?? '');
    $paid_note        = trim($_POST['paid_note'] ?? '');
    $payment_date     = trim($_POST['payment_date'] ?? '');
    $discountRaw      = trim($_POST['discount'] ?? '');
    $discount         = $discountRaw === '' ? 0 : (float)$discountRaw;
    if ($discount < 0) {
        $discount = 0;
    }

    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $line_modes  = $_POST['line_mode'] ?? [];

    foreach ($product_ids as $idx => $pidRaw) {
        $mode = (isset($line_modes[$idx]) && $line_modes[$idx] === 'lucky') ? 'lucky' : 'general';
        $submittedProductRows[] = [
            'product_id' => (int)$pidRaw,
            'quantity' => max(1, (int)($quantities[$idx] ?? 1)),
            'line_mode' => $mode,
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
    if ($page_id <= 0) {
        $errors[] = 'Page is required.';
    }
    if ($delivery_type_id <= 0) {
        $errors[] = 'Delivery type is required.';
    }
    if ($delivery_cost_id <= 0) {
        $errors[] = 'Delivery cost is required.';
    }
    if ($status === '') {
        $errors[] = 'Status is required.';
    }
    if ($status === 'paid' && empty($payment_method)) {
        $errors[] = 'Payment method is required when status is Paid.';
    }
    if ($status === 'paid' && empty($payment_date)) {
        $errors[] = 'Payment date is required when status is Paid.';
    }
    if ($status !== 'paid') {
        $payment_method = '';
        $paid_note = '';
        $payment_date = '';
    }

    $items = [];
    $itemsByKey = [];
    $current_month = date('Y-m');
    
    foreach ($product_ids as $idx => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$idx] ?? 0);
        $mode = (isset($line_modes[$idx]) && $line_modes[$idx] === 'lucky') ? 'lucky' : 'general';
        if ($pid > 0 && $qty > 0) {
            // Get product with current month costs from product_costs system
            $stmt = $pdo->prepare("
                SELECT 
                    p.id, 
                    p.name, 
                    COALESCE(pc.selling_price, p.cost) as cost,
                    pc.month_year
                FROM products p
                LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
                WHERE p.id = ?
            ");
            $stmt->execute([$current_month, $pid]);
            $prod = $stmt->fetch();
            if ($prod) {
                $isLucky = $mode === 'lucky' ? 1 : 0;
                $key = $prod['id'] . '_' . $isLucky;
                $unitCost = (float)$prod['cost'];
                if (!isset($itemsByKey[$key])) {
                    $itemsByKey[$key] = [
                        'product_id' => $prod['id'],
                        'name'       => $prod['name'],
                        'unit_cost'  => $unitCost,
                        'quantity'   => $qty,
                        'line_total' => $unitCost * $qty,
                        'month_year' => $prod['month_year'],
                        'is_lucky_box' => $isLucky,
                    ];
                } else {
                    $itemsByKey[$key]['quantity'] = (int)$itemsByKey[$key]['quantity'] + $qty;
                    $itemsByKey[$key]['line_total'] = $itemsByKey[$key]['unit_cost'] * $itemsByKey[$key]['quantity'];
                }
            }
        }
    }

    $items = array_values($itemsByKey);
    $totalProducts = 0;
    foreach ($items as $it) {
        $totalProducts += (float)($it['line_total'] ?? 0);
    }

    if (!$errors) {
        try {
            error_log("Order Processing Debug: Preparing order preview");
            error_log("POST data: " . print_r($_POST, true));
            error_log("Items count: " . count($items));
            error_log("Total products: $totalProducts");

            if (empty($items)) {
                error_log("ERROR: No items found in order");
                $errors[] = 'No valid products found in order.';
                throw new Exception("No items to process");
            }

            $deliveryAmount = 0;
            if ($delivery_cost_id > 0) {
                $cstmt = $pdo->prepare('SELECT amount FROM delivery_costs WHERE id = ?');
                $cstmt->execute([$delivery_cost_id]);
                $row = $cstmt->fetch();
                if ($row && $row['amount'] !== null) {
                    $deliveryAmount = (float)$row['amount'];
                }
            }
            error_log("Delivery amount: $deliveryAmount");

            $grandTotal = $totalProducts + $deliveryAmount - $discount;
            if ($grandTotal < 0) {
                $grandTotal = 0;
            }
            error_log("Grand total: $grandTotal");

            if (empty($customer_name) || empty($phone) || empty($location)) {
                throw new Exception("Missing required order data");
            }

            $_SESSION['pending_new_order'] = [
                'customer_name' => $customer_name,
                'phone' => $phone,
                'location' => $location,
                'page_id' => $page_id ?: null,
                'delivery_type_id' => $delivery_type_id ?: null,
                'delivery_cost_id' => $delivery_cost_id ?: null,
                'status' => $status,
                'payment_method' => $payment_method ?: null,
                'paid_note' => $paid_note ?: null,
                'payment_date' => ($status === 'paid' && $payment_date) ? $payment_date : null,
                'discount' => $discount,
                'total_amount' => $grandTotal,
                'delivery_amount' => $deliveryAmount,
                'items' => $items,
                'seller_id' => (int)$user['id'],
            ];

            header('Location: ../receipt.php?from=new');
            exit;
        } catch (Throwable $e) {
            error_log("ORDER PREVIEW ERROR: " . $e->getMessage());
            error_log("ERROR TRACE: " . $e->getTraceAsString());
            $errors[] = 'Failed to prepare order: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="row flex-grow-1">
    <div class="col-12 col-lg-8 mx-auto py-3">
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

        <form method="post" id="orderForm">
            <div class="seller-panel seller-panel-yellow">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <div class="fw-bold" style="font-size:1rem;">Create new order</div>
                        <div class="text-muted" style="font-size:0.85rem;">
                            Find the card for your customer and confirm payment status
                        </div>
                        <div class="mt-1">
                            <span class="badge bg-info" style="font-size: 11px;">
                                Using costs for: <?= date('F Y', strtotime($current_month . '-01')) ?>
                            </span>
                            <small class="text-muted ms-2">Products without costs show warning</small>
                        </div>
                    </div>
                    <span class="badge bg-light text-dark px-3 py-2">+ Order</span>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Customer Name</div>
                        <input type="text" name="customer_name" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($customer_name) ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Seller</div>
                        <input type="text" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($user['name']) ?>" disabled>
                    </div>
                </div>

                <div class="seller-label mb-1">Product Order</div>
                <div id="productRows" class="d-flex flex-column gap-2 mb-2"></div>
                <button type="button" class="btn btn-light btn-add-more mt-1" id="addProductRow" data-bs-toggle="modal" data-bs-target="#pickProductModal">Add product</button>
                <p class="small text-muted mt-2 mb-0" id="productRowsHint">Use <strong>Add product</strong> to choose General (full catalog) or Lucky box (sets marked in admin).</p>
            </div>

            <div class="seller-panel seller-panel-pink">
                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">ដឹកដោយ</div>
                        <select name="delivery_type_id" class="form-select form-select-lg seller-select">
                            <option value="">Select delivery</option>
                            <?php foreach ($types as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $delivery_type_id === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">តម្លៃថ្លៃដឹក</div>
                        <select name="delivery_cost_id" class="form-select form-select-lg seller-select">
                            <option value="">Select តម្លៃ</option>
                            <?php foreach ($costs as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" data-amount="<?= htmlspecialchars((string)$c['amount']) ?>" <?= $delivery_cost_id === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <div class="seller-label mb-1">Page</div>
                        <select name="page_id" class="form-select form-select-lg seller-select">
                            <option value="">Select page</option>
                            <?php foreach ($pages as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= $page_id === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Discount</div>
                        <input type="number" name="discount" id="discountInput" class="form-control form-control-lg seller-input" min="0" step="0.01" value="<?= htmlspecialchars((string)$discount) ?>">
                    </div>
                </div>
            </div>

            <div class="seller-panel seller-panel-peach">
                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Location</div>
                        <input type="text" name="location" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($location) ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Phone number</div>
                        <input type="text" name="phone" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($phone) ?>" required>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Status</div>
                        <select name="status" id="statusSelect" class="form-select form-select-lg seller-select" required>
                            <option value="">Select status</option>
                            <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>មិនទាន់ទូរទាត់</option>
                            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>ទូរទាត់រួច</option>
                        </select>
                    </div>
                </div>

                <div id="paidFieldsGroup" style="<?= $status === 'paid' ? '' : 'display:none;' ?>">
                    <div class="row g-3 mb-2">
                        <div class="col-12 col-md-6">
                            <div class="seller-label mb-1">Payment Date <span class="text-danger">*</span></div>
                            <input type="date" name="payment_date" id="payment_date_input" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($payment_date) ?>" <?= $status === 'paid' ? 'required' : '' ?>>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="seller-label mb-1">Payment Method</div>
                            <select name="payment_method" id="paymentMethodSelect" class="form-select form-select-lg seller-select">
                                <option value="">Select payment method</option>
                                <?php foreach ($paymentMethods as $pm): ?>
                                    <option value="<?= htmlspecialchars($pm['option_text']) ?>" <?= $payment_method === $pm['option_text'] ? 'selected' : '' ?>><?= htmlspecialchars($pm['option_text']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="seller-label mb-1">Paid confirmation</div>
                            <input type="text" name="paid_note" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($paid_note) ?>">
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="seller-label mb-1">Total amount</div>
                        <div class="fw-bold" id="totalAmount">$0.00</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="orders.php" class="btn btn-cancel-soft btn-lg">Cancel</a>
                        <button type="submit" class="btn btn-next btn-lg" style="color: #ffffffff;">Next</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="pickProductModal" tabindex="-1" aria-labelledby="pickProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="pickProductModalLabel">Add product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">

                <!-- Step 1: General or Lucky box -->
                <div id="pickStepChoose">
                    <p class="text-muted small mb-3">Choose how this line is sold for the customer.</p>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-dark btn-lg py-3" id="pickGeneralBtn">
                            <span class="fw-bold">General product</span>
                            <span class="d-block small text-muted mt-1">All catalog items &amp; product sets</span>
                        </button>
                        <button type="button" class="btn btn-outline-warning btn-lg py-3" id="pickLuckyBtn">
                            <span class="fw-bold">Lucky box</span>
                            <span class="d-block small text-muted mt-1">Only sets marked as lucky box (admin)</span>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Brand selection -->
                <div id="pickStepBrand" style="display:none;">
                    <button type="button" class="btn btn-link text-decoration-none ps-0 mb-3" id="pickBackToBrandStep">&larr; Back</button>
                    <div class="seller-label mb-2">Select Brand</div>
                    <div id="pickBrandGrid" class="d-flex flex-wrap gap-2 mb-2">
                        <!-- brand buttons injected by JS -->
                    </div>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-1" id="pickAllBrandsBtn">
                        All brands (no filter)
                    </button>
                </div>

                <!-- Step 3: Product selection -->
                <div id="pickStepProduct" style="display:none;">
                    <button type="button" class="btn btn-link text-decoration-none ps-0 mb-2" id="pickBackBtn">&larr; Back</button>
                    <div id="pickActiveBrandBadge" class="mb-2"></div>
                    <div id="pickLuckyEmpty" class="alert alert-warning d-none mb-0" role="alert">
                        No lucky box sets are configured. An admin can open <strong>Product Management &rarr; Lucky Box Sets</strong> and mark sets.
                    </div>
                    <div id="pickProductFields">
                        <div class="seller-label mb-1">Product</div>
                        <select id="pickProductSelect" class="form-select form-select-lg seller-select"></select>
                        <div class="seller-label mb-1 mt-3">Quantity</div>
                        <input type="number" id="pickQtyInput" class="form-control form-control-lg seller-input" min="1" value="1">
                        <button type="button" class="btn btn-next w-100 mt-4" id="pickConfirmBtn" style="color:#fff;">Add to order</button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const products = <?php echo json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const luckyProducts = <?php echo json_encode($luckyProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const brands = <?php echo json_encode($brands, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const initialRows = <?php echo json_encode($submittedProductRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const rowsContainer   = document.getElementById('productRows');
    const totalEl         = document.getElementById('totalAmount');
    const deliverySelect  = document.querySelector('select[name="delivery_cost_id"]');
    const statusSelect    = document.getElementById('statusSelect');
    const paidFieldsGroup = document.getElementById('paidFieldsGroup');
    const discountInput   = document.getElementById('discountInput');
    const orderForm       = document.getElementById('orderForm');
    const pickModalEl     = document.getElementById('pickProductModal');

    const pickStepChoose  = document.getElementById('pickStepChoose');
    const pickStepBrand   = document.getElementById('pickStepBrand');
    const pickStepProduct = document.getElementById('pickStepProduct');
    const pickGeneralBtn  = document.getElementById('pickGeneralBtn');
    const pickLuckyBtn    = document.getElementById('pickLuckyBtn');
    const pickBackToBrandStep = document.getElementById('pickBackToBrandStep');
    const pickBackBtn     = document.getElementById('pickBackBtn');
    const pickBrandGrid   = document.getElementById('pickBrandGrid');
    const pickAllBrandsBtn = document.getElementById('pickAllBrandsBtn');
    const pickActiveBrandBadge = document.getElementById('pickActiveBrandBadge');
    const pickProductSelect = document.getElementById('pickProductSelect');
    const pickQtyInput    = document.getElementById('pickQtyInput');
    const pickConfirmBtn  = document.getElementById('pickConfirmBtn');
    const pickLuckyEmpty  = document.getElementById('pickLuckyEmpty');
    const pickProductFields = document.getElementById('pickProductFields');

    let pickMode      = null;
    let pickBrandId   = null; // null = all brands

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function resetPickModal() {
        pickMode    = null;
        pickBrandId = null;
        pickStepChoose.style.display  = '';
        pickStepBrand.style.display   = 'none';
        pickStepProduct.style.display = 'none';
        pickProductSelect.innerHTML   = '';
        pickQtyInput.value            = '1';
        pickLuckyEmpty.classList.add('d-none');
        pickProductFields.classList.remove('d-none');
        pickActiveBrandBadge.innerHTML = '';
    }

    // Build brand buttons
    function buildBrandGrid() {
        pickBrandGrid.innerHTML = '';
        if (!brands.length) {
            pickBrandGrid.innerHTML = '<p class="text-muted small">No brands configured. <a href="/LuckyBox/admin/brands.php" target="_blank">Add brands</a> first.</p>';
            return;
        }
        brands.forEach(function(b) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-lg px-4';
            btn.style.cssText = 'background:' + b.color + ';color:#fff;border:none;border-radius:10px;';
            btn.textContent = b.name;
            btn.addEventListener('click', function() {
                pickBrandId = b.id;
                goToProductStep(b);
            });
            pickBrandGrid.appendChild(btn);
        });
    }

    function goToBrandStep() {
        pickStepChoose.style.display  = 'none';
        pickStepBrand.style.display   = '';
        pickStepProduct.style.display = 'none';
        buildBrandGrid();
    }

    function goToProductStep(brand) {
        pickStepBrand.style.display   = 'none';
        pickStepProduct.style.display = '';

        // Show active brand badge
        if (brand) {
            pickActiveBrandBadge.innerHTML = '<span class="badge rounded-pill mb-2" style="background:' + brand.color + ';font-size:0.9rem;padding:5px 14px;">' + escapeHtml(brand.name) + '</span>';
        } else {
            pickActiveBrandBadge.innerHTML = '<span class="badge bg-secondary rounded-pill mb-2" style="font-size:0.9rem;padding:5px 14px;">All brands</span>';
        }

        const sourceList = pickMode === 'lucky' ? luckyProducts : products;
        // Filter by brand:
        //  - Normal products: match if product.brand_id == selectedBrand
        //  - Product sets: match if any component product belongs to selectedBrand
        const filtered = pickBrandId
            ? sourceList.filter(function(p) {
                if (p.component_brand_ids && p.component_brand_ids.length > 0) {
                    return p.component_brand_ids.indexOf(Number(pickBrandId)) !== -1;
                }
                return p.brand_id == pickBrandId;
            })
            : sourceList;

        if (pickMode === 'lucky' && !luckyProducts.length) {
            pickLuckyEmpty.classList.remove('d-none');
            pickProductFields.classList.add('d-none');
        } else if (filtered.length === 0) {
            pickLuckyEmpty.classList.remove('d-none');
            pickLuckyEmpty.textContent = 'No products found for this brand.';
            pickProductFields.classList.add('d-none');
        } else {
            pickLuckyEmpty.classList.add('d-none');
            pickProductFields.classList.remove('d-none');
            fillPickSelect(filtered);
        }
    }

    function fillPickSelect(arr) {
        const opts = ['<option value="">Select product</option>'].concat(
            arr.map(p => '<option value="' + p.id + '" data-cost="' + p.cost + '" data-has-costs="' + p.has_costs + '">' + escapeHtml(productLabel(p)) + '</option>')
        );
        pickProductSelect.innerHTML = opts.join('');
    }

    if (pickModalEl) {
        pickModalEl.addEventListener('show.bs.modal', resetPickModal);
    }

    pickGeneralBtn.addEventListener('click', () => {
        pickMode = 'general';
        if (brands.length > 0) {
            goToBrandStep();
        } else {
            // No brands configured — skip brand step
            pickBrandId = null;
            pickStepChoose.style.display = 'none';
            goToProductStep(null);
        }
    });

    pickLuckyBtn.addEventListener('click', () => {
        pickMode    = 'lucky';
        pickBrandId = null;
        pickStepChoose.style.display = 'none';
        goToProductStep(null);
    });

    // "All brands" button — skip brand filter
    pickAllBrandsBtn.addEventListener('click', () => {
        pickBrandId = null;
        goToProductStep(null);
    });

    // Back from brand step → step 1
    pickBackToBrandStep.addEventListener('click', () => {
        pickStepBrand.style.display  = 'none';
        pickStepChoose.style.display = '';
        pickMode    = null;
        pickBrandId = null;
    });

    // Back from product step → brand step (general only) or step 1
    pickBackBtn.addEventListener('click', () => {
        pickStepProduct.style.display = 'none';
        pickLuckyEmpty.classList.add('d-none');
        pickProductFields.classList.remove('d-none');
        pickActiveBrandBadge.innerHTML = '';
        if (pickMode === 'general' && brands.length > 0) {
            goToBrandStep();
        } else {
            pickStepChoose.style.display = '';
            pickMode    = null;
            pickBrandId = null;
        }
    });

    pickConfirmBtn.addEventListener('click', () => {
        if (!pickMode) {
            return;
        }
        if (pickMode === 'lucky' && !luckyProducts.length) {
            return;
        }
        const pid = pickProductSelect.value;
        const qty = parseInt(pickQtyInput.value || '1', 10);
        if (!pid) {
            return;
        }
        createRow(pid, qty, pickMode);
        mergeDuplicateProductRows();
        if (typeof bootstrap !== 'undefined' && pickModalEl) {
            const modalInst = bootstrap.Modal.getOrCreateInstance(pickModalEl);
            modalInst.hide();
        }
    });

    function optionsHtmlForList(list, selectedId) {
        const sid = String(selectedId || '');
        return list.map(p => {
            const isSelected = String(p.id) === sid ? ' selected' : '';
            return '<option value="' + p.id + '" data-cost="' + p.cost + '" data-has-costs="' + p.has_costs + '"' + isSelected + '>' + escapeHtml(productLabel(p)) + '</option>';
        }).join('');
    }

    function getBrandById(id) {
        return brands.find(b => b.id == id) || null;
    }

    function productLabel(p) {
        return p.name;
    }

    function createRow(selectedProductId = '', quantity = 1, lineMode = 'general'){
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-center';
        const mode = lineMode === 'lucky' ? 'lucky' : 'general';
        const sourceList = mode === 'lucky' ? luckyProducts : products;
        const normalizedProductId = String(selectedProductId || '');
        const normalizedQuantity = Number.isFinite(Number(quantity)) && Number(quantity) > 0 ? Number(quantity) : 1;
        const badgeClass = mode === 'lucky' ? 'bg-warning text-dark' : 'bg-secondary';
        const badgeLabel = mode === 'lucky' ? 'Lucky box' : 'General';

        // Find brand for selected product
        const selectedProduct = sourceList.find(p => String(p.id) === normalizedProductId);
        const brand = selectedProduct ? getBrandById(selectedProduct.brand_id) : null;
        const brandBadgeHtml = brand
            ? `<span class="badge rounded-pill brand-badge" style="background:${brand.color};font-size:0.78rem;padding:3px 9px;">${escapeHtml(brand.name)}</span>`
            : '';

        row.innerHTML = `
            <input type="hidden" name="line_mode[]" value="${mode}">
            <div class="col-12 col-md-2 col-lg-2 d-flex flex-wrap gap-1 align-items-center">
                <span class="badge ${badgeClass} line-mode-pill">${badgeLabel}</span>
                <span class="brand-badge-wrap">${brandBadgeHtml}</span>
            </div>
            <div class="col-12 col-md-5 col-lg-5">
                <select name="product_id[]" class="form-select form-select-lg product-select" required>
                    <option value="">Select product</option>
                    ${optionsHtmlForList(sourceList, normalizedProductId)}
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="number" name="quantity[]" class="form-control form-control-lg quantity-input" min="1" value="${normalizedQuantity}" required>
            </div>
            <div class="col-6 col-md-2 text-md-end">
                <span class="fw-semibold line-total">$0.00</span>
            </div>
            <div class="col-12 col-md-1 text-md-end">
                <button type="button" class="btn btn-outline-danger btn-sm px-3 remove-row">X</button>
            </div>
        `;
        rowsContainer.appendChild(row);

        // Update brand badge when product selection changes
        row.querySelector('.product-select').addEventListener('change', function() {
            const pid = this.value;
            const rowMode = row.querySelector('input[name="line_mode[]"]').value;
            const list = rowMode === 'lucky' ? luckyProducts : products;
            const prod = list.find(p => String(p.id) === String(pid));
            const b = prod ? getBrandById(prod.brand_id) : null;
            const wrap = row.querySelector('.brand-badge-wrap');
            wrap.innerHTML = b
                ? `<span class="badge rounded-pill brand-badge" style="background:${b.color};font-size:0.78rem;padding:3px 9px;">${escapeHtml(b.name)}</span>`
                : '';
        });

        mergeDuplicateProductRows();
    }

    function rowLineKey(row) {
        const sel = row.querySelector('.product-select');
        const modeH = row.querySelector('input[name="line_mode[]"]');
        const pid = sel && sel.value;
        if (!pid) {
            return null;
        }
        const mode = modeH && modeH.value === 'lucky' ? 'lucky' : 'general';
        return mode + '|' + pid;
    }

    function mergeDuplicateProductRows() {
        const rows = Array.from(rowsContainer.querySelectorAll('.row'));
        const groups = new Map();
        rows.forEach(row => {
            const key = rowLineKey(row);
            if (!key) {
                return;
            }
            if (!groups.has(key)) {
                groups.set(key, []);
            }
            groups.get(key).push(row);
        });
        groups.forEach((groupRows) => {
            if (groupRows.length < 2) {
                return;
            }
            const keep = groupRows[0];
            const qKeep = keep.querySelector('.quantity-input');
            let sum = parseInt(qKeep.value || '0', 10);
            if (isNaN(sum) || sum < 1) {
                sum = 1;
            }
            for (let i = 1; i < groupRows.length; i++) {
                const q = groupRows[i].querySelector('.quantity-input');
                const add = parseInt(q.value || '0', 10);
                sum += (isNaN(add) ? 0 : add);
                groupRows[i].remove();
            }
            qKeep.value = Math.max(1, sum);
        });
        updateTotals();
    }

    function updateTotals(){
        let productTotal = 0;
        const rows = rowsContainer.querySelectorAll('.row');
        rows.forEach(row => {
            const select = row.querySelector('.product-select');
            const qtyEl  = row.querySelector('.quantity-input');
            const lineEl = row.querySelector('.line-total');
            const pid    = select.value;
            const qty    = parseInt(qtyEl.value || '0', 10);
            let line     = 0;
            if (pid && qty > 0) {
                const opt  = select.selectedOptions[0];
                const cost = parseFloat(opt.getAttribute('data-cost') || '0');
                line = cost * qty;
            }
            productTotal += line;
            lineEl.textContent = '$' + line.toFixed(2);
        });
        let deliveryAmount = 0;
        if (deliverySelect && deliverySelect.value) {
            const opt = deliverySelect.selectedOptions[0];
            if (opt && opt.dataset.amount) {
                deliveryAmount = parseFloat(opt.dataset.amount || '0');
            }
        }
        let discount = 0;
        if (discountInput && discountInput.value !== '') {
            discount = parseFloat(discountInput.value || '0');
            if (isNaN(discount) || discount < 0) {
                discount = 0;
            }
        }
        let grandTotal = productTotal + deliveryAmount - discount;
        if (grandTotal < 0) grandTotal = 0;
        totalEl.textContent = '$' + grandTotal.toFixed(2);
    }

    rowsContainer.addEventListener('change', (e) => {
        if (e.target.classList.contains('product-select')) {
            const currentSelect = e.target;
            const pid = currentSelect.value;
            const currentRow = currentSelect.closest('.row');
            const rowModeHidden = currentRow ? currentRow.querySelector('input[name="line_mode[]"]') : null;
            const rowMode = rowModeHidden && rowModeHidden.value === 'lucky' ? 'lucky' : 'general';
            const list = rowMode === 'lucky' ? luckyProducts : products;

            if (pid) {
                const stillValid = list.some(p => String(p.id) === String(pid));
                if (!stillValid) {
                    currentSelect.innerHTML = '<option value="">Select product</option>' + optionsHtmlForList(list, '');
                    updateTotals();
                    return;
                }
            }
            mergeDuplicateProductRows();
            return;
        }
        if (e.target.classList.contains('quantity-input')) {
            mergeDuplicateProductRows();
        }
    });

    rowsContainer.addEventListener('input', updateTotals);

    if (deliverySelect) {
        deliverySelect.addEventListener('change', updateTotals);
    }

    if (discountInput) {
        discountInput.addEventListener('input', updateTotals);
    }

    rowsContainer.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-row')) {
            const row = e.target.closest('.row');
            if (row) {
                row.remove();
                updateTotals();
            }
        }
    });

    statusSelect.addEventListener('change', () => {
        const isPaid = statusSelect.value === 'paid';
        paidFieldsGroup.style.display = isPaid ? '' : 'none';
        const pdInput = document.getElementById('payment_date_input');
        if (pdInput) pdInput.required = isPaid;
    });

    if (orderForm) {
        orderForm.addEventListener('submit', (ev) => {
            const rows = rowsContainer.querySelectorAll('.row');
            if (rows.length === 0) {
                ev.preventDefault();
                alert('Please add at least one product line.');
            }
        });
    }

    if (Array.isArray(initialRows) && initialRows.length > 0) {
        initialRows.forEach(row => {
            const lm = row.line_mode === 'lucky' ? 'lucky' : 'general';
            createRow(row.product_id || '', row.quantity || 1, lm);
        });
    }
    updateTotals();
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
