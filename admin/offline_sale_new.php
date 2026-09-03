<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/offline_lib.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);
$user = current_user() ?: [];
$isPopupMode = isset($_GET['popup']) && $_GET['popup'] === '1';

function offline_sale_redirect(string $url, bool $isPopupMode): void
{
    if ($isPopupMode) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';
        echo '<script>window.parent.location.href = ' . json_encode($url, JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '</body></html>';
        exit;
    }
    header('Location: ' . $url);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'location_products') {
    require_role_or_permission(['admin'], 'offline_sales.create', 'offline_sales.update');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $ajaxTeamId = (int)($_GET['team_id'] ?? 0);
    $ajaxLocationId = (int)($_GET['location_id'] ?? 0);

    if ($ajaxTeamId > 0) {
        $teamStmt = $pdo->prepare('SELECT location_id FROM offline_teams WHERE id = ? LIMIT 1');
        $teamStmt->execute([$ajaxTeamId]);
        $teamLocationId = (int)($teamStmt->fetchColumn() ?: 0);
        if ($teamLocationId > 0) {
            $ajaxLocationId = $teamLocationId;
        }
    }

    if ($ajaxLocationId <= 0) {
        echo json_encode([
            'ok' => false,
            'products' => [],
            'location_id' => 0,
            'location_name' => '',
            'message' => 'No storage location assigned to this team.',
        ]);
        exit;
    }

    $cachedProducts = offline_products($pdo);

    $ajaxProducts = offline_location_products_with_stock($pdo, $ajaxLocationId, $cachedProducts);
    $nameStmt = $pdo->prepare('SELECT location_name FROM storage_locations WHERE id = ? LIMIT 1');
    $nameStmt->execute([$ajaxLocationId]);
    echo json_encode([
        'ok' => true,
        'products' => $ajaxProducts,
        'location_id' => $ajaxLocationId,
        'location_name' => (string)($nameStmt->fetchColumn() ?: ''),
    ]);
    exit;
}

$errors = [];

$editOrderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
$isEdit = $editOrderId > 0;
if ($isEdit) {
    require_role_or_permission(['admin'], 'offline_sales.update');
} else {
    require_role_or_permission(['admin'], 'offline_sales.create');
}

$editOrder = null;
$editCartBootstrap = [];
$editPurchaseBootstrap = [];
$editPaidTotal = 0.0;
$editTeamLocationId = 0;
if ($isEdit) {
    $orderStmt = $pdo->prepare("SELECT * FROM offline_sale_orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$editOrderId]);
    $editOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editOrder) {
        header('Location: offline_sale_orders.php');
        exit;
    }
    $itemStmt = $pdo->prepare("
        SELECT product_id, product_name, quantity, unit_price
        FROM offline_sale_order_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $itemStmt->execute([$editOrderId]);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $editCartBootstrap[] = [
            'id' => $pid,
            'name' => (string)$row['product_name'],
            'selling_price' => (float)$row['unit_price'],
            'quantity' => (float)$row['quantity'],
        ];
    }
    $purchaseStmt = $pdo->prepare("
        SELECT product_id, product_name, quantity, unit_price, item_condition, reason
        FROM offline_sale_purchase_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $purchaseStmt->execute([$editOrderId]);
    foreach ($purchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $editPurchaseBootstrap[] = [
            'id' => (int)$row['product_id'],
            'name' => (string)$row['product_name'],
            'quantity' => (float)$row['quantity'],
            'purchase_price' => (float)$row['unit_price'],
            'condition' => (string)$row['item_condition'],
            'reason' => (string)$row['reason'],
        ];
    }
    $payments = offline_payments_for_orders($pdo, [$editOrderId])[$editOrderId] ?? [];
    $editPaidTotal = offline_order_paid_from_payments($payments, $editOrder);
    if ($editOrder['team_id']) {
        $tl = $pdo->prepare("SELECT location_id FROM offline_teams WHERE id = ? LIMIT 1");
        $tl->execute([(int)$editOrder['team_id']]);
        $editTeamLocationId = (int)($tl->fetchColumn() ?: 0);
    }
    if ($editTeamLocationId <= 0) {
        $editTeamLocationId = (int)($editOrder['location_id'] ?? 0);
    }
}
$offlineLocations = offline_locations($pdo, true);
$defaultLocationId = $offlineLocations ? (int)$offlineLocations[0]['id'] : 0;

$allProducts = offline_products($pdo);
$allProductsById = [];
foreach ($allProducts as $p) {
    $allProductsById[(int)$p['id']] = $p;
}

// Collect all location IDs used by active teams
$teamsLocData = $pdo->query("SELECT id, location_id FROM offline_teams WHERE is_active = 1 AND location_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$teamLocMapPhp = [];
foreach ($teamsLocData as $t) {
    $teamLocMapPhp[(int)$t['id']] = (int)$t['location_id'];
}
$relevantLocIds = array_values(array_unique(array_filter(array_merge([$defaultLocationId], array_values($teamLocMapPhp)))));

// Build product list per location
$locationProductsMap = [];
$locationNameMap = [];
foreach ($offlineLocations as $loc) {
    $locationNameMap[(int)$loc['id']] = $loc['location_name'];
}
foreach ($relevantLocIds as $locId) {
    $locId = (int)$locId;
    if (!isset($locationNameMap[$locId])) {
        $stmt2 = $pdo->prepare("SELECT location_name FROM storage_locations WHERE id = ? LIMIT 1");
        $stmt2->execute([$locId]);
        $locationNameMap[$locId] = (string)($stmt2->fetchColumn() ?: '');
    }
}
$locationProductsMap = offline_build_location_products_map($pdo, $allProducts, $relevantLocIds);

// Products for initial display (default location, before seller is selected)
$products = $locationProductsMap[$defaultLocationId] ?? [];
$paymentMethods = $pdo->query("SELECT option_text FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_COLUMN);
$offlineTeams = $pdo->query("SELECT id, name, location_id FROM offline_teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$offlineBrands = [];
try {
    $offlineBrands = $pdo->query("SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$formCustomerName = '';
$formPhone = '';
$formCustomerLocation = '';
$formSaleDate = date('Y-m-d');
$formTeamId = 0;
$formDiscount = '';
$formReceived = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postOrderId = (int)($_POST['order_id'] ?? 0);
    $isEditPost = $postOrderId > 0;
    if ($isEditPost) {
        require_role_or_permission(['admin'], 'offline_sales.update');
    }

    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $saleDate = trim((string)($_POST['sale_date'] ?? date('Y-m-d')));
    $discount = max(0, (float)($_POST['discount'] ?? 0));
    $receivedAmount = max(0, (float)($_POST['received_amount'] ?? 0));
    $customerLocation = trim((string)($_POST['customer_location'] ?? ''));
    $teamId = (int)($_POST['team_id'] ?? 0) ?: null;
    $productIds = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $unitPrices  = $_POST['unit_price'] ?? [];
    $purchaseProductIds = $_POST['purchase_product_id'] ?? [];
    $purchaseQuantities = $_POST['purchase_quantity'] ?? [];
    $purchasePrices = $_POST['purchase_unit_price'] ?? [];
    $purchaseConditions = $_POST['purchase_condition'] ?? [];
    $purchaseReasons = $_POST['purchase_reason'] ?? [];

    if ($customerName === '') {
        $errors[] = 'Customer name is required.';
    }
    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    }

    // Stock comes from the selected team's storage location
    $locationId = $defaultLocationId;
    if ($teamId) {
        $locStmt = $pdo->prepare("SELECT location_id FROM offline_teams WHERE id = ? AND location_id IS NOT NULL LIMIT 1");
        $locStmt->execute([$teamId]);
        $teamLocId = $locStmt->fetchColumn();
        if ($teamLocId) {
            $locationId = (int)$teamLocId;
        }
    }

    if ($customerLocation === '') {
        $errors[] = 'Customer location is required.';
    }
    if ($locationId <= 0) {
        $errors[] = 'No offline stock location found. Assign a storage location to this team.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
        $errors[] = 'Sale date is invalid.';
    }

    $items = [];
    foreach ($productIds as $idx => $pidRaw) {
        $pid = (int)$pidRaw;
        $qty = (float)($quantities[$idx] ?? 0);
        $customPrice = (float)($unitPrices[$idx] ?? 0);
        if ($pid <= 0 || $qty <= 0 || !isset($allProductsById[$pid])) {
            continue;
        }
        if ($customPrice < 0) {
            $errors[] = 'Sale unit price cannot be negative.';
            continue;
        }
        if (!isset($items[$pid])) {
            $items[$pid] = $allProductsById[$pid];
            $items[$pid]['quantity'] = 0.0;
            $items[$pid]['selling_price'] = $customPrice;
        }
        $items[$pid]['quantity'] += $qty;
    }
    if (!$items) {
        $errors[] = 'Add at least one product.';
    }

    $purchaseItems = [];
    foreach ($purchaseProductIds as $idx => $pidRaw) {
        $pid = (int)$pidRaw;
        $qty = (float)($purchaseQuantities[$idx] ?? 0);
        $price = (float)($purchasePrices[$idx] ?? 0);
        $condition = strtolower(trim((string)($purchaseConditions[$idx] ?? 'good')));
        $reason = trim((string)($purchaseReasons[$idx] ?? 'Customer purchase'));
        if ($pid <= 0 || $qty <= 0 || !isset($allProductsById[$pid])) {
            continue;
        }
        if ($price < 0 || !in_array($condition, ['good', 'fair', 'poor', 'damaged'], true) || $reason === '') {
            $errors[] = 'Every purchased product needs a valid purchase price.';
            continue;
        }
        $key = $pid . '|' . $condition . '|' . $reason . '|' . number_format($price, 2, '.', '');
        if (!isset($purchaseItems[$key])) {
            $purchaseItems[$key] = $allProductsById[$pid];
            $purchaseItems[$key]['quantity'] = 0.0;
            $purchaseItems[$key]['purchase_price'] = $price;
            $purchaseItems[$key]['condition'] = $condition;
            $purchaseItems[$key]['reason'] = $reason;
        }
        $purchaseItems[$key]['quantity'] += $qty;
    }

    if (!$errors) {
        try {
            if ($isEditPost) {
                offline_update_sale_order(
                    $pdo,
                    $postOrderId,
                    $customerName,
                    $phone ?: null,
                    $customerLocation ?: null,
                    $locationId,
                    $teamId,
                    $saleDate,
                    $discount,
                    $items,
                    $purchaseItems,
                    $receivedAmount,
                    $user
                );
                offline_sale_redirect('offline_sale_orders.php?updated=1', $isPopupMode);
            }

            foreach ($items as $item) {
                $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
                if (!$inv || (float)$inv['quantity_on_hand'] < (float)$item['quantity']) {
                    throw new RuntimeException('Insufficient offline stock for ' . $item['name']);
                }
            }

            $orderCode = offline_next_order_code($pdo);
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float)$item['selling_price'] * (float)$item['quantity'];
            }
            $purchaseTotal = 0.0;
            foreach ($purchaseItems as $item) {
                $purchaseTotal += (float)$item['purchase_price'] * (float)$item['quantity'];
            }
            $total = $subtotal - $discount - $purchaseTotal;
            if ($total < -0.009) {
                throw new RuntimeException('Purchase total cannot be greater than the sale amount after discount.');
            }
            $total = max(0, $total);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO offline_sale_orders (order_code, customer_name, phone, customer_location, location_id, team_id, sale_date, status, subtotal, discount, purchase_total, total_amount, received_amount, payment_date, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?, 0, NULL, ?, ?)");
            $stmt->execute([$orderCode, $customerName ?: null, $phone ?: null, $customerLocation ?: null, $locationId, $teamId, $saleDate, $subtotal, $discount, $purchaseTotal, $total, $user['id'] ?? null, $user['id'] ?? null]);
            $orderId = (int)$pdo->lastInsertId();
            offline_log_order_activity($pdo, $orderId, 'created', 'Order created', isset($user['id']) ? (int)$user['id'] : null);

            $itemStmt = $pdo->prepare("INSERT INTO offline_sale_order_items (order_id, product_id, product_name, quantity, unit_price, line_total, unit_cost) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $pid => $item) {
                $qty = (float)$item['quantity'];
                $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
                $prev = (float)$inv['quantity_on_hand'];
                $new = $prev - $qty;
                $unitCost = (float)($inv['unit_cost'] ?? $item['unit_cost'] ?? 0);
                offline_save_inventory($pdo, $locationId, (string)$item['name'], $new, $unitCost, $inv['sku'] ?? null, (int)$inv['id']);
                offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
                $lineTotal = (float)$item['selling_price'] * $qty;
                $itemStmt->execute([$orderId, $pid, $item['name'], $qty, $item['selling_price'], $lineTotal, $unitCost]);
                offline_insert_stock_movement($pdo, (int)$pid, 'out', $qty, $prev, $new, 'offline_sale', $orderCode, 'Offline sale ' . $orderCode . ' [Location:' . $locationId . ']', $unitCost, offline_current_user_label($user), $locationId, null);
            }
            $purchaseStmt = $pdo->prepare("INSERT INTO offline_sale_purchase_items (order_id, product_id, product_name, quantity, unit_price, line_total, item_condition, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($purchaseItems as $item) {
                $pid = (int)$item['id'];
                $qty = (float)$item['quantity'];
                $price = (float)$item['purchase_price'];
                $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
                $prev = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
                $new = $prev + $qty;
                $oldCost = $inv ? (float)($inv['unit_cost'] ?? 0) : 0.0;
                $weightedCost = $new > 0 ? (($prev * $oldCost) + ($qty * $price)) / $new : $price;
                offline_save_inventory($pdo, $locationId, (string)$item['name'], $new, $weightedCost, $inv['sku'] ?? ($item['sku'] ?? null), $inv ? (int)$inv['id'] : null);
                if ($inv) {
                    offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
                }
                $purchaseStmt->execute([$orderId, $pid, $item['name'], $qty, $price, $price * $qty, $item['condition'], $item['reason']]);
                offline_insert_stock_movement($pdo, $pid, 'in', $qty, $prev, $new, 'offline_customer_purchase', $orderCode, 'Purchased from customer ' . $orderCode . ' - ' . $item['condition'] . ' / ' . $item['reason'] . ' [Location:' . $locationId . ']', $price, offline_current_user_label($user), null, $locationId);
            }
            if ($receivedAmount > 0) {
                offline_add_order_payment($pdo, $orderId, $saleDate, $receivedAmount, null, null, isset($user['id']) ? (int)$user['id'] : null);
            }
            $pdo->commit();
            offline_sale_redirect('offline_sale_orders.php?created=1', $isPopupMode);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
            if ($isEditPost) {
                $isEdit = true;
                $editOrderId = $postOrderId;
                if (!$editOrder) {
                    $orderStmt = $pdo->prepare("SELECT * FROM offline_sale_orders WHERE id = ? LIMIT 1");
                    $orderStmt->execute([$editOrderId]);
                    $editOrder = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }
    }

    if ($errors) {
        $formCustomerName = $customerName;
        $formPhone = $phone;
        $formCustomerLocation = $customerLocation;
        $formSaleDate = $saleDate;
        $formTeamId = (int)($teamId ?? 0);
        $formDiscount = $discount > 0 ? number_format($discount, 2, '.', '') : '';
        $formReceived = $receivedAmount > 0 ? number_format($receivedAmount, 2, '.', '') : '';
        if ($isEditPost) {
            $isEdit = true;
            $editOrderId = $postOrderId;
        }
    }
}

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $formCustomerName = (string)($editOrder['customer_name'] ?? '');
    $formPhone = (string)($editOrder['phone'] ?? '');
    $formCustomerLocation = (string)($editOrder['customer_location'] ?? '');
    $formSaleDate = (string)($editOrder['sale_date'] ?? date('Y-m-d'));
    $formTeamId = (int)($editOrder['team_id'] ?? 0);
    $editDiscountAmt = (float)($editOrder['discount'] ?? 0);
    $formDiscount = $editDiscountAmt > 0 ? number_format($editDiscountAmt, 2, '.', '') : '';
    $formReceived = $editPaidTotal > 0 ? number_format($editPaidTotal, 2, '.', '') : '';
}

$initialLocationId = $defaultLocationId;
if ($formTeamId > 0) {
    if (isset($teamLocMapPhp[$formTeamId]) && (int)$teamLocMapPhp[$formTeamId] > 0) {
        $initialLocationId = (int)$teamLocMapPhp[$formTeamId];
    } else {
        foreach ($offlineTeams as $team) {
            if ((int)$team['id'] === $formTeamId) {
                $teamLoc = (int)($team['location_id'] ?? 0);
                if ($teamLoc > 0) {
                    $initialLocationId = $teamLoc;
                }
                break;
            }
        }
    }
}
if ($isEdit && $editTeamLocationId > 0) {
    $initialLocationId = $editTeamLocationId;
}
$hasInitialTeam = $formTeamId > 0;

if ($isEdit && $editCartBootstrap && $initialLocationId > 0) {
    $qtyOnOrder = [];
    foreach ($editCartBootstrap as $cartRow) {
        $pid = (int)$cartRow['id'];
        $qtyOnOrder[$pid] = ($qtyOnOrder[$pid] ?? 0.0) + (float)$cartRow['quantity'];
    }
    if (isset($locationProductsMap[$initialLocationId])) {
        foreach ($locationProductsMap[$initialLocationId] as &$prod) {
            $pid = (int)$prod['id'];
            if (isset($qtyOnOrder[$pid])) {
                $prod['available_stock'] = (float)($prod['available_stock'] ?? 0) + $qtyOnOrder[$pid];
            }
        }
        unset($prod);
    }
    $listedIds = array_map(static fn($p) => (int)$p['id'], $locationProductsMap[$initialLocationId] ?? []);
    foreach ($editCartBootstrap as $cartRow) {
        $pid = (int)$cartRow['id'];
        if (in_array($pid, $listedIds, true)) {
            continue;
        }
        $product = $allProductsById[$pid] ?? null;
        if (!$product) {
            continue;
        }
        $product['available_stock'] = (float)$cartRow['quantity'];
        $locationProductsMap[$initialLocationId][] = $product;
        $listedIds[] = $pid;
    }
}

$teamProductsMap = [];
foreach ($offlineTeams as $team) {
    $teamId = (int)$team['id'];
    $teamLocId = (int)($team['location_id'] ?? 0);
    $teamProductsMap[$teamId] = $teamLocId > 0 ? ($locationProductsMap[$teamLocId] ?? []) : [];
}

include __DIR__ . '/../layout/header.php';
offline_status_badge_styles();
?>
<style>
<?php if ($isPopupMode): ?>
body {
    background: #f8f9fa !important;
}
.admin-sidebar,
.admin-topbar,
.sidebar-overlay {
    display: none !important;
}
.admin-content {
    margin-left: 0 !important;
    width: 100% !important;
}
#mainPageContent {
    padding: 0 !important;
}
.offline-sale-page {
    padding: 1rem !important;
}
<?php endif; ?>
.offline-sale-page {
    --sale-pink: #e91e63;
    --sale-border: #e8edf5;
    --sale-muted: #667085;
    --sale-ink: #111827;
    --seller-yellow: #ffe889;
    --seller-pink: #f6a0d1;
    --seller-soft-pink: #ffd5d7;
}
.offline-sale-page .sale-card,
.offline-sale-page .summary-card {
    border: 1px solid #e6eaf0;
    border-radius: 14px;
    box-shadow: none;
    background: #fff;
}
.offline-sale-page .sale-info-card {
    background: #fffdf6;
    border-color: #f6dfa0;
}
.offline-sale-page .add-product-card {
    background: #fff9fb;
    border-color: #f7d3df;
}
.offline-sale-page .product-list-card,
.offline-sale-page .summary-card {
    background: #f8fbff;
    border-color: #cfe2f7;
}
.offline-sale-page .sale-info-card .step-dot {
    background: #f9b800;
}
.offline-sale-page .product-list-card .step-dot {
    background: #2f80d0;
}
.offline-sale-page .step-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--sale-pink);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: .9rem;
}
.offline-sale-page .form-label {
    font-weight: 700;
    color: #344054;
    font-size: .88rem;
}
.offline-sale-page .form-control,
.offline-sale-page .form-select,
.offline-sale-page .input-group-text {
    min-height: 48px;
    border-color: #e3e8ef;
    border-radius: 9px;
    font-size: 1rem;
    box-shadow: none;
}
.offline-sale-page .form-control:focus,
.offline-sale-page .form-select:focus {
    border-color: #c7d7f4;
    box-shadow: 0 0 0 .2rem rgba(59, 130, 246, .16);
}
.offline-sale-page .input-group .input-group-text:first-child {
    border-radius: 9px 0 0 9px;
}
.offline-sale-page .input-group .form-control:last-child {
    border-radius: 0 9px 9px 0;
}
.offline-sale-page .btn-sale {
    background: var(--sale-pink);
    border-color: var(--sale-pink);
    color: #fff;
    font-weight: 700;
}
.offline-sale-page .btn-sale:hover {
    background: #c2185b;
    border-color: #c2185b;
    color: #fff;
}
.offline-sale-page .btn-outline-sale {
    border-color: #f8b8cf;
    color: var(--sale-pink);
    font-weight: 700;
}
.offline-sale-page .btn-outline-sale:hover {
    background: #fff0f6;
    color: var(--sale-pink);
}
.offline-sale-page .product-avatar {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: linear-gradient(135deg, #fff0f6, #f8fafc);
    color: var(--sale-pink);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}
.offline-sale-page .qty-box {
    display: inline-flex;
    align-items: center;
    border: 1px solid #dbe3ef;
    border-radius: 9px;
    overflow: hidden;
}
.offline-sale-page .purchase-card {
    background: #f8fff9;
    border-color: #cce8d2;
}
.offline-sale-page .purchase-card .form-control,
.offline-sale-page .purchase-card .form-select {
    min-height: 46px;
}
.offline-sale-page .purchase-product-group .purchase-brand-select {
    flex: 0 0 38%;
    max-width: 38%;
    border-radius: 9px 0 0 9px;
}
.offline-sale-page .purchase-product-group .form-control {
    border-radius: 0 9px 9px 0;
}
.offline-sale-page .purchase-search-wrap {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
}
.offline-sale-page .purchase-search-wrap .form-control {
    width: 100%;
}
.offline-sale-page .purchase-suggestions {
    position: absolute;
    top: calc(100% + 5px);
    left: 0;
    right: 0;
    z-index: 1060;
    max-height: 280px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #d9e1ea;
    border-radius: 9px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .14);
}
.offline-sale-page .purchase-suggestion {
    display: block;
    width: 100%;
    border: 0;
    border-bottom: 1px solid #eef2f6;
    background: #fff;
    padding: .65rem .8rem;
    text-align: left;
}
.offline-sale-page .purchase-suggestion:last-child {
    border-bottom: 0;
}
.offline-sale-page .purchase-suggestion:hover,
.offline-sale-page .purchase-suggestion:focus {
    background: #f1f8ff;
    outline: 0;
}
.offline-sale-page .purchase-qty-box {
    width: 100%;
    min-height: 46px;
}
.offline-sale-page .purchase-qty-box button {
    width: 34px;
    height: 44px;
}
.offline-sale-page .purchase-qty-box span {
    min-width: 28px;
}
.offline-sale-page .qty-input {
    width: 64px;
    min-width: 56px;
    height: 44px;
    border: 0;
    border-left: 1px solid #dbe3ef;
    border-right: 1px solid #dbe3ef;
    text-align: center;
    font-weight: 700;
    background: #fff;
}
.offline-sale-page .qty-input:focus {
    outline: 2px solid rgba(13, 110, 253, .25);
    outline-offset: -2px;
}
.offline-sale-page .qty-box button {
    border: 0;
    background: #fff;
    width: 44px;
    height: 44px;
    font-weight: 800;
}
.offline-sale-page .qty-box span {
    min-width: 42px;
    text-align: center;
    font-weight: 700;
}
.offline-sale-page .summary-total {
    color: var(--sale-pink);
    font-size: 1.35rem;
}
.offline-sale-page .mobile-summary {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    box-shadow: 0 -2px 12px rgba(0,0,0,.10);
}
@media (min-width: 1200px) {
    .offline-sale-page .mobile-summary { display: none !important; }
}
@media (max-width: 1199.98px) {
    .offline-sale-page { padding-bottom: 80px; }
}
@media (max-width: 1199.98px) {
    .offline-sale-page .summary-card { position: static !important; }
}
.offline-sale-page .field-clear-wrap {
    position: relative;
}
.offline-sale-page .field-clear-wrap .form-control {
    padding-right: 2.75rem;
}
.offline-sale-page .field-clear-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: var(--sale-muted);
    padding: 0 .25rem;
    line-height: 1;
    z-index: 2;
}
.offline-sale-page .field-clear-btn:hover {
    color: var(--sale-pink);
}
.offline-sale-page .field-help {
    color: var(--sale-muted);
    font-size: .78rem;
    margin-top: .35rem;
}
@media (min-width: 1200px) {
    .offline-sale-page .sale-info-card .col-xl-3 {
        width: 25%;
    }
    .offline-sale-page .sale-info-card .col-xl-6 {
        width: 50%;
    }
}
</style>
<div class="container-fluid py-4 offline-sale-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">
                <i class="bi <?= $isEdit ? 'bi-pencil' : 'bi-cart-plus' ?> me-2 text-danger"></i>
                <?= $isEdit ? 'Edit Offline Sale' : 'New Offline Sale' ?>
            </h1>
        </div>
        <div class="d-flex gap-2">
            <?php if ($isEdit): ?>
                <?php if ($isPopupMode): ?>
                    <button type="button" class="btn btn-outline-secondary" onclick="window.parent.closeOfflineOrderFormPopup ? window.parent.closeOfflineOrderFormPopup() : (window.parent.location.href = 'offline_sale_orders.php')">
                        <i class="bi bi-arrow-left me-1"></i>Back to Orders
                    </button>
                <?php else: ?>
                    <a href="offline_sale_orders.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Orders</a>
                <?php endif; ?>
            <?php else: ?>
                <button type="button" class="btn btn-outline-danger" onclick="clearForm()">
                    <i class="bi bi-x-circle me-1"></i>Clear Form
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($errors as $e): ?><div class="alert alert-danger alert-dismissible fade show auto-dismiss" role="alert"><?= htmlspecialchars($e) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endforeach; ?>
    <?php if (!$offlineLocations): ?><div class="alert alert-warning alert-dismissible fade show auto-dismiss" role="alert">No offline location found. Mark at least one storage location as offline.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($offlineLocations && !array_filter($locationProductsMap)): ?><div class="alert alert-warning alert-dismissible fade show auto-dismiss" role="alert">No products have stock in any offline location.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

    <div id="validationAlert" class="alert alert-danger alert-dismissible fade show d-none mb-3" role="alert">
        <button type="button" class="btn-close" onclick="document.getElementById('validationAlert').classList.add('d-none')" aria-label="Close"></button>
        <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Please fill in the required fields:</strong>
        <ul id="validationList" class="mb-0 mt-2"></ul>
    </div>

    <form method="post" id="offlineSaleForm" novalidate>
        <?php if ($isEdit): ?>
            <input type="hidden" name="order_id" value="<?= (int)$editOrderId ?>">
        <?php endif; ?>
        <div id="cartInputs"></div>
        <input type="hidden" name="received_amount" id="receivedAmountHidden">
        <div class="row g-3 align-items-start">
            <div class="col-12 col-xl-9">
                <div class="sale-card sale-info-card mb-3">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-center gap-2 mb-4">
                            <span class="step-dot">1</span>
                            <h2 class="h5 mb-0">Sale Information</h2>
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <div class="field-clear-wrap">
                                    <input name="customer_name" id="customerNameInput" class="form-control" placeholder="Search name or enter new" value="<?= htmlspecialchars($formCustomerName) ?>">
                                    <button type="button" class="field-clear-btn" onclick="clearCustomerField('customerNameInput')" title="Clear name" aria-label="Clear customer name"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="form-label">Sale Date <span class="text-danger">*</span></label>
                                <input type="date" name="sale_date" class="form-control" value="<?= htmlspecialchars($formSaleDate) ?>" required>
                            </div>
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="form-label">Offline Team <span class="text-danger">*</span></label>
                                <select name="team_id" id="teamSelect" class="form-select">
                                    <option value="">Select offline team</option>
                                    <?php foreach ($offlineTeams as $team): ?>
                                        <option value="<?= (int)$team['id'] ?>" data-location="<?= (int)($team['location_id'] ?? 0) ?>" <?= $formTeamId === (int)$team['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($team['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-help" id="teamStockHelp">Select a team to load products from its stock location.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sale-card sale-info-card mb-3">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-center gap-2 mb-4">
                            <span class="step-dot">2</span>
                            <h2 class="h5 mb-0">Contact &amp; Customer Location</h2>
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <div class="field-clear-wrap">
                                    <input name="phone" id="phoneInput" class="form-control" placeholder="Enter phone number" value="<?= htmlspecialchars($formPhone) ?>">
                                    <button type="button" class="field-clear-btn" onclick="clearCustomerField('phoneInput')" title="Clear phone" aria-label="Clear phone number"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Customer Location <span class="text-danger">*</span></label>
                                <input type="text" name="customer_location" id="customerLocationInput" class="form-control" placeholder="Customer address or area" value="<?= htmlspecialchars($formCustomerLocation) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sale-card add-product-card mb-3">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-center gap-2 mb-4">
                            <span class="step-dot">3</span>
                            <h2 class="h5 mb-0">Add Product</h2>
                            <span id="productCountBadge" class="badge rounded-pill bg-danger-subtle text-danger d-none"></span>
                            <small class="text-muted" id="stockLocationLabel"></small>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-lg-5">
                                <label class="form-label">Product <span class="text-danger">*</span></label>
                                <div class="purchase-search-wrap">
                                    <input id="saleProductSearch" class="form-control" placeholder="Search product, SKU, or barcode" autocomplete="off" disabled>
                                    <div id="saleProductSuggestions" class="purchase-suggestions d-none" role="listbox"></div>
                                </div>
                                <select id="productSelect" class="form-select d-none" aria-hidden="true" tabindex="-1">
                                    <option value="">Select offline team first</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3 col-lg-2">
                                <label class="form-label">Quantity</label>
                                <div class="qty-box w-100 justify-content-between">
                                    <button type="button" onclick="stepAddQty(-1)">-</button>
                                    <input id="addQtyInput" type="number" min="1" step="1" value="1" class="qty-input" aria-label="Sale quantity">
                                    <button type="button" onclick="stepAddQty(1)">+</button>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-lg-2">
                                <label class="form-label">Unit Price</label>
                                <input id="addPrice" type="number" step="0.01" min="0" class="form-control" value="0.00">
                            </div>
                            <div class="col-6 col-md-3 col-lg-1">
                                <label class="form-label">Stock</label>
                                <input id="addStock" class="form-control" value="0" readonly>
                            </div>
                            <div class="col-6 col-md-3 col-lg-2 d-grid">
                                <button type="button" id="addProductBtn" class="btn btn-sale" onclick="addSelectedProduct()"><i class="bi bi-plus me-1"></i>Add Product</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sale-card purchase-card mb-3">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-center gap-2 mb-4">
                            <span class="step-dot" style="background:#198754;">4</span>
                            <h2 class="h5 mb-0">Purchase Back</h2>
                            <span id="purchaseCountBadge" class="badge rounded-pill bg-success-subtle text-success d-none"></span>
                            <small class="text-muted" id="purchaseLocationLabel"></small>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-lg-5">
                                <label class="form-label">Products<span class="text-danger">*</span></label>
                                <div class="input-group purchase-product-group">
                                    <select id="purchaseBrand" class="form-select purchase-brand-select" aria-label="Purchase brand">
                                        <option value="">All</option>
                                        <?php foreach ($offlineBrands as $brand): ?>
                                            <option value="<?= (int)$brand['id'] ?>"><?= htmlspecialchars($brand['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="purchase-search-wrap">
                                        <input id="purchaseSearch" class="form-control" placeholder="Search product, SKU, or barcode" autocomplete="off">
                                        <div id="purchaseSuggestions" class="purchase-suggestions d-none" role="listbox"></div>
                                    </div>
                                    <input id="purchaseProduct" type="hidden" value="">
                                </div>
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label">Quantity</label>
                                <div class="qty-box purchase-qty-box justify-content-between">
                                    <button type="button" onclick="stepPurchaseQty(-1)">-</button>
                                    <input id="purchaseQty" type="number" min="1" step="1" value="1" class="qty-input" aria-label="Purchase quantity">
                                    <button type="button" onclick="stepPurchaseQty(1)">+</button>
                                </div>
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label">Purchase Price</label>
                                <input id="purchasePrice" type="number" min="0" step="0.01" value="0.00" class="form-control">
                            </div>
                            <div class="col-12 col-lg-2 d-grid">
                                <button type="button" id="addPurchaseBtn" class="btn btn-success" onclick="addPurchaseItem()">
                                    <i class="bi bi-plus-lg me-1"></i>Add Purchase
                                </button>
                            </div>
                        </div>
                        <div id="purchaseHelp" class="form-text mt-3">Old products purchased from customer will increase your team stock.</div>
                    </div>
                </div>

                <div class="sale-card product-list-card">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-center gap-2 mb-4">
                            <span class="step-dot">5</span>
                            <h2 class="h5 mb-0">Product List</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr><th>#</th><th>Type</th><th>Product</th><th class="text-end">Price</th><th class="text-center">Qty</th><th class="text-end">Total</th><th class="text-end">Action</th></tr>
                                </thead>
                                <tbody id="cartRows">
                                    <tr><td colspan="7" class="text-center text-muted py-4">No products added.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <button type="button" class="btn btn-outline-danger" onclick="clearCart()"><i class="bi bi-trash me-1"></i>Clear All</button>
                            <div class="d-flex gap-3 flex-wrap justify-content-end">
                                <span class="fw-bold text-success">Sale Items: <span id="saleItemsText">0</span></span>
                                <span class="fw-bold text-warning">Purchased Items: <span id="purchaseItemsText">0</span></span>
                                <span class="fw-bold text-primary">Total Items: <span id="totalItemsText">0</span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-3">
                <div class="summary-card position-sticky" style="top: 1rem;">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="step-dot">6</span>
                            <h2 class="h5 mb-0">Order Summary</h2>
                        </div>

                        <!-- Discount input -->
                        <div class="mb-3">
                            <label class="form-label fw-bold mb-1">Discount</label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold">$</span>
                                <input id="discountInput" type="number" inputmode="decimal" step="0.01" min="0" name="discount" class="form-control form-control-lg fw-bold" placeholder="0.00" style="font-size:1.2rem;" value="<?= htmlspecialchars($formDiscount) ?>">
                            </div>
                        </div>

                        <!-- Subtotal & Discount -->
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Total Amount (You Sell)</span>
                            <span class="fw-semibold" id="subtotalText">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Discount</span>
                            <span class="fw-semibold text-danger" id="discountText">-$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Total Purchase (You Buy)</span>
                            <span class="fw-semibold text-success" id="purchaseTotalText">-$0.00</span>
                        </div>
                        <hr class="my-2">

                        <!-- Grand Total -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold">Net Amount</span>
                            <span class="summary-total fw-bold" id="grandTotalText" style="font-size:1.6rem;">$0.00</span>
                        </div>

                        <!-- Received Amount -->
                        <div class="mb-3">
                            <label class="form-label fw-bold mb-1">Received Amount</label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold">$</span>
                                <input id="receivedInput" type="number" inputmode="decimal" step="0.01" min="0" class="form-control form-control-lg fw-bold" placeholder="0.00" style="font-size:1.2rem;" value="<?= htmlspecialchars($formReceived) ?>">
                            </div>
                        </div>

                        <!-- Payment Result -->
                        <div id="paymentResult" class="mb-3 d-none">
                            <div id="paymentResultBox" class="rounded-3 p-3 text-center">
                                <div class="small fw-semibold mb-1" id="paymentResultLabel"></div>
                                <div class="fw-bold" id="paymentResultAmount" style="font-size:1.3rem;"></div>
                                <div class="small mt-1" id="paymentResultMsg"></div>
                            </div>
                        </div>

                        <!-- Payment Status -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted small">Payment Status</span>
                            <span id="paymentStatusBadge"></span>
                        </div>

                        <!-- Buttons -->
                        <div class="d-grid gap-2">
                            <?php if (!$isEdit): ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="saveDraft()">
                                <i class="bi bi-file-earmark me-1"></i>Save as Draft
                            </button>
                            <?php endif; ?>
                            <button id="saveBtn" class="btn btn-sale btn-lg">
                                <i class="bi bi-save me-1"></i><?= $isEdit ? 'Update Offline Sale' : 'Save Offline Sale' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile sticky action bar -->
            <div class="d-xl-none mobile-summary bg-white border-top px-3 py-2 d-flex align-items-center gap-2">
                <div class="flex-grow-1">
                    <div class="text-muted" style="font-size:.75rem;">Grand Total</div>
                    <div class="fw-bold summary-total" id="grandTotalMobile" style="font-size:1.1rem;">$0.00</div>
                </div>
                <div id="mobilePaymentStatus"></div>
                <button id="saveBtnMobile" class="btn btn-sale btn-lg" form="offlineSaleForm">
                    <i class="bi bi-save me-1"></i><?= $isEdit ? 'Update' : 'Save' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="stockWarningModal" tabindex="-1" aria-labelledby="stockWarningModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger-subtle border-0">
                <h5 class="modal-title text-danger" id="stockWarningModalLabel">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Not Enough Stock
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="fw-bold mb-2" id="stockWarningProduct"></div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Requested quantity</span>
                    <strong class="text-danger" id="stockWarningRequested">0</strong>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted">Available stock</span>
                    <strong class="text-success" id="stockWarningAvailable">0</strong>
                </div>
                <div class="alert alert-warning mb-0 mt-2">Please reduce the quantity before adding this product.</div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-danger px-4" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
const locationProductsMap = <?= json_encode($locationProductsMap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const teamProductsMap = <?= json_encode($teamProductsMap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const teamLocationMap = <?= json_encode($teamLocMapPhp) ?>;
const locationNameMap = <?= json_encode($locationNameMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const defaultLocationId = <?= (int)$defaultLocationId ?>;
const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
const editCartBootstrap = <?= json_encode($editCartBootstrap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const editPurchaseBootstrap = <?= json_encode($editPurchaseBootstrap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const allPurchaseProducts = <?= json_encode(array_values($allProducts), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const editPaidMinimum = <?= $isEdit ? json_encode((float)$editPaidTotal) : '0' ?>;
const hasInitialTeam = <?= ($hasInitialTeam || $isEdit) ? 'true' : 'false' ?>;
const offlineBrands = <?= json_encode($offlineBrands, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let currentLocationId = hasInitialTeam ? <?= (int)$initialLocationId ?> : 0;
let products = [];
const cart = new Map();
const purchaseCart = new Map();
let addQty = 1;

function normalizeQty(value, min = 1, max = null) {
    let qty = Number(value);
    if (!Number.isFinite(qty)) {
        qty = min;
    }
    qty = Math.max(min, Math.floor(qty));
    if (max !== null && max > 0) {
        qty = Math.min(qty, Math.floor(max));
    }
    return qty;
}

function money(value) {
    return '$' + Number(value || 0).toFixed(2);
}

function paymentStatusBadgeHtml(status) {
    const icons = {
        paid: 'bi-check-lg',
        unpaid: 'bi-exclamation-triangle-fill',
        partial: 'bi-pie-chart-fill',
    };
    const labels = {
        paid: 'Paid',
        unpaid: 'Unpaid',
        partial: 'Partially Paid',
    };
    const key = ['paid', 'unpaid', 'partial'].includes(status) ? status : 'unpaid';
    const icon = icons[key];
    const label = labels[key];
    return `<span class="offline-status-badge offline-status-${key}" role="status">`
        + `<span class="offline-status-icon" aria-hidden="true"><i class="bi ${icon}"></i></span>`
        + `<span class="offline-status-text">${label}</span></span>`;
}

function productLabel(product) {
    const stock = Number(product.available_stock || 0);
    const stockText = stock > 0 ? `Stock ${stock.toFixed(2)}` : 'Out of stock';
    return `${product.name} - ${money(product.selling_price)} - ${stockText}`;
}

const teamProductOptionsHtml = {};

function buildProductOptionsHtml(list, placeholder) {
    const emptyText = placeholder || 'No products found';
    if (!list || !list.length) {
        return `<option value="">${escapeHtml(emptyText)}</option>`;
    }
    const parts = [`<option value="">${escapeHtml('Search product or scan barcode')}</option>`];
    list.forEach(product => {
        parts.push(`<option value="${Number(product.id)}">${escapeHtml(productLabel(product))}</option>`);
    });
    return parts.join('');
}

function rebuildTeamProductOptionsCache(teamId, list) {
    const html = buildProductOptionsHtml(list);
    teamProductOptionsHtml[String(teamId)] = html;
    teamProductsMap[teamId] = list;
    teamProductsMap[String(teamId)] = list;
}

Object.keys(teamProductsMap).forEach(teamKey => {
    teamProductOptionsHtml[teamKey] = buildProductOptionsHtml(teamProductsMap[teamKey] || []);
});

function initProductSelect(placeholder) {
    const select = document.getElementById('productSelect');
    if (!select) {
        return;
    }
    const teamSelectEl = document.getElementById('teamSelect');
    const teamId = parseInt(teamSelectEl?.value || '0', 10) || 0;
    const cachedHtml = teamId > 0 ? teamProductOptionsHtml[String(teamId)] : '';
    if (cachedHtml) {
        select.innerHTML = cachedHtml;
    } else {
        const emptyLabel = placeholder || 'Search product or scan barcode';
        select.innerHTML = `<option value="">${emptyLabel}</option>`;
        products.forEach(product => {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = productLabel(product);
            select.appendChild(option);
        });
    }
    syncAddProductInfo();
}

function selectedProduct() {
    const id = Number(document.getElementById('productSelect').value || 0);
    return products.find(p => Number(p.id) === id) || null;
}

function refreshSaleProductSuggestions() {
    const search = document.getElementById('saleProductSearch');
    const suggestions = document.getElementById('saleProductSuggestions');
    const select = document.getElementById('productSelect');
    if (!search || !suggestions || !select) return;

    const query = search.value.trim().toLowerCase();
    const matches = products.filter(product => {
        if (Number(product.available_stock || 0) <= 0) return false;
        if (!query) return true;
        return [product.name, product.sku, product.barcode].some(value =>
            String(value || '').toLowerCase().includes(query)
        );
    }).slice(0, 50);

    suggestions.innerHTML = matches.length
        ? matches.map(product => {
            const meta = [product.sku, product.barcode].filter(Boolean).join(' · ');
            return `<button type="button" class="purchase-suggestion sale-product-suggestion" role="option" data-product-id="${Number(product.id)}">`
                + `<span class="fw-semibold">${escapeHtml(product.name)}</span>`
                + `<span class="d-block small text-muted">${escapeHtml(meta ? meta + ' · ' : '')}Stock ${Number(product.available_stock || 0).toFixed(2)} · ${money(product.selling_price)}</span>`
                + '</button>';
        }).join('')
        : '<div class="p-3 text-muted small">No in-stock products found.</div>';

    const exact = products.find(product =>
        Number(product.available_stock || 0) > 0
        && [product.name, product.sku, product.barcode].some(value => String(value || '').toLowerCase() === query)
    );
    select.value = exact ? String(exact.id) : '';
    syncAddProductInfo();
    if (document.activeElement === search && !search.disabled) {
        suggestions.classList.remove('d-none');
    }
}

function syncAddProductInfo() {
    const product = selectedProduct();
    document.getElementById('addPrice').value = product ? Number(product.selling_price || 0).toFixed(2) : '0.00';
    document.getElementById('addStock').value = product ? Number(product.available_stock || 0).toFixed(2) : '0';
}

function stepAddQty(delta) {
    const input = document.getElementById('addQtyInput');
    addQty = normalizeQty((input ? input.value : addQty) || addQty);
    addQty = normalizeQty(addQty + delta);
    if (input) {
        input.value = String(addQty);
    }
}

function showStockWarning(product, requested, available) {
    document.getElementById('stockWarningProduct').textContent = product.name || 'Product';
    document.getElementById('stockWarningRequested').textContent = Number(requested).toFixed(2);
    document.getElementById('stockWarningAvailable').textContent = Number(available).toFixed(2);
    const modalElement = document.getElementById('stockWarningModal');
    if (window.bootstrap?.Modal && modalElement) {
        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        return;
    }
    alert(`Not enough stock for ${product.name}. Requested: ${requested}. Available: ${available}.`);
}

function addSelectedProduct() {
    const product = selectedProduct();
    if (!product) return;
    const qtyInput = document.getElementById('addQtyInput');
    addQty = normalizeQty(qtyInput ? qtyInput.value : addQty);
    if (qtyInput) {
        qtyInput.value = String(addQty);
    }
    const existingQty = Number(cart.get(Number(product.id))?.quantity || 0);
    const requestedQty = existingQty + Number(addQty || 0);
    const availableStock = Number(product.available_stock || 0);
    if (requestedQty > availableStock + 0.009) {
        showStockWarning(product, requestedQty, availableStock);
        return;
    }
    const customPrice = Number(document.getElementById('addPrice').value || 0);
    if (!Number.isFinite(customPrice) || customPrice < 0) {
        alert('Enter a valid unit price of 0 or more.');
        return;
    }
    addProductToCart(Number(product.id), addQty, customPrice);
    document.getElementById('saleProductSearch').value = '';
    document.getElementById('productSelect').value = '';
    document.getElementById('saleProductSuggestions').classList.add('d-none');
    syncAddProductInfo();
    addQty = 1;
    if (qtyInput) {
        qtyInput.value = '1';
    }
}

function addProductToCart(pid, qty, customPrice) {
    const id = Number(pid);
    const product = products.find(p => Number(p.id) === id);
    if (!product) return;
    const existing = cart.get(id);
    const nextQty = (existing ? existing.quantity : 0) + qty;
    const maxStock = Number(product.available_stock || 0);
    const price = Number.isFinite(Number(customPrice)) && Number(customPrice) >= 0
        ? Number(customPrice)
        : Number(product.selling_price || 0);
    cart.set(id, {...product, selling_price: price, quantity: maxStock > 0 ? Math.min(nextQty, maxStock) : nextQty});
    renderCart();
}

function conditionLabel(value) {
    return {
        good: 'Good - Usable',
        fair: 'Fair - Minor Issues',
        poor: 'Poor - Major Issues',
        damaged: 'Damaged - Not Usable'
    }[value] || value;
}

function stepPurchaseQty(delta) {
    const input = document.getElementById('purchaseQty');
    const next = normalizeQty((input ? input.value : 1) || 1);
    if (input) {
        input.value = String(normalizeQty(next + delta));
    }
}

function refreshPurchaseProducts() {
    const brandId = Number(document.getElementById('purchaseBrand')?.value || 0);
    const query = (document.getElementById('purchaseSearch')?.value || '').trim().toLowerCase();
    const list = document.getElementById('purchaseSuggestions');
    const hidden = document.getElementById('purchaseProduct');
    if (!list || !hidden) return;
    const matches = allPurchaseProducts.filter(product => {
        if (brandId && Number(product.brand_id || 0) !== brandId) return false;
        if (!query) return true;
        return [product.name, product.sku, product.barcode].some(value => String(value || '').toLowerCase().includes(query));
    });
    updatePurchaseHeader();
    const visibleMatches = matches.slice(0, 50);
    list.innerHTML = visibleMatches.length
        ? visibleMatches.map(product => {
            const meta = [product.sku, product.barcode].filter(Boolean).join(' · ');
            return `<button type="button" class="purchase-suggestion" role="option" data-product-id="${Number(product.id)}">`
                + `<span class="fw-semibold">${escapeHtml(product.name)}</span>`
                + (meta ? `<span class="d-block small text-muted">${escapeHtml(meta)}</span>` : '')
                + '</button>';
        }).join('')
        : '<div class="p-3 text-muted small">No products found.</div>';
    const exact = allPurchaseProducts.find(product => {
        if (brandId && Number(product.brand_id || 0) !== brandId) return false;
        return [product.name, product.sku, product.barcode].some(value => String(value || '').toLowerCase() === query);
    });
    hidden.value = exact ? String(exact.id) : '';
    if (document.activeElement === document.getElementById('purchaseSearch')) {
        list.classList.remove('d-none');
    }
    document.getElementById('purchaseHelp').textContent = exact
        ? `${exact.name} selected. It will increase the selected team's stock.`
        : 'Old products purchased from customer will increase your team stock.';
}

function updatePurchaseHeader() {
    const badge = document.getElementById('purchaseCountBadge');
    const brandId = Number(document.getElementById('purchaseBrand')?.value || 0);
    const count = allPurchaseProducts.filter(product =>
        !brandId || Number(product.brand_id || 0) === brandId
    ).length;
    if (badge) {
        badge.textContent = `${count} product${count === 1 ? '' : 's'}`;
        badge.classList.toggle('d-none', !hasTeamSelected());
    }
}

function addPurchaseItem() {
    if (!hasTeamSelected()) {
        alert('Select an offline team first.');
        return;
    }
    let id = Number(document.getElementById('purchaseProduct').value || 0);
    const searchValue = (document.getElementById('purchaseSearch').value || '').trim().toLowerCase();
    let product = allPurchaseProducts.find(item => Number(item.id) === id);
    if (!product && searchValue) {
        const brandId = Number(document.getElementById('purchaseBrand').value || 0);
        const matches = allPurchaseProducts.filter(item => {
            if (brandId && Number(item.brand_id || 0) !== brandId) return false;
            return [item.name, item.sku, item.barcode].some(value => String(value || '').toLowerCase().includes(searchValue));
        });
        if (matches.length === 1) {
            product = matches[0];
            id = Number(product.id);
        }
    }
    const purchaseQtyInput = document.getElementById('purchaseQty');
    const quantity = normalizeQty(purchaseQtyInput ? purchaseQtyInput.value : 1);
    if (purchaseQtyInput) {
        purchaseQtyInput.value = String(quantity);
    }
    const price = Number(document.getElementById('purchasePrice').value || 0);
    const condition = 'good';
    const reason = 'Customer purchase';
    if (!product || quantity <= 0 || !Number.isFinite(price) || price < 0) {
        alert('Select a product and enter a valid purchase price of 0 or more.');
        return;
    }
    const existing = purchaseCart.get(id);
    purchaseCart.set(id, {
        ...product,
        quantity: (existing ? Number(existing.quantity) : 0) + quantity,
        purchase_price: price,
        condition,
        condition_label: conditionLabel(condition),
        reason
    });
    document.getElementById('purchaseQty').value = '1';
    document.getElementById('purchaseSearch').value = '';
    document.getElementById('purchaseProduct').value = '';
    document.getElementById('purchaseSuggestions').classList.add('d-none');
    refreshPurchaseProducts();
    renderCart();
}

function changePurchaseQty(id, delta) {
    const item = purchaseCart.get(Number(id));
    if (!item) return;
    item.quantity = normalizeQty(Number(item.quantity) + delta);
    purchaseCart.set(Number(id), item);
    renderCart();
}

function setPurchaseQty(id, value) {
    const item = purchaseCart.get(Number(id));
    if (!item) return;
    item.quantity = normalizeQty(value);
    purchaseCart.set(Number(id), item);
    renderCart();
}

function removePurchaseItem(id) {
    purchaseCart.delete(Number(id));
    renderCart();
}

function syncAddButtonState() {
    syncTeamProductUiState();
}

function hasTeamSelected() {
    const teamSelectEl = document.getElementById('teamSelect');
    return !!(teamSelectEl && teamSelectEl.value);
}

function syncTeamProductUiState() {
    const teamSelected = hasTeamSelected();
    const productSelect = document.getElementById('productSelect');
    const productSearch = document.getElementById('saleProductSearch');
    const addButton = document.getElementById('addProductBtn');
    const productHelp = document.getElementById('productHelpText');
    const productCountBadge = document.getElementById('productCountBadge');
    const teamHelp = document.getElementById('teamStockHelp');
    const inStockCount = products.filter(p => Number(p.available_stock || 0) > 0).length;
    const locationName = locationNameMap[currentLocationId] || '';

    if (productSelect) {
        productSelect.disabled = !teamSelected || inStockCount === 0;
    }
    if (productSearch) {
        productSearch.disabled = !teamSelected || inStockCount === 0;
        productSearch.placeholder = !teamSelected
            ? 'Select offline team first'
            : (inStockCount === 0 ? 'No products in stock for this team' : 'Search product, SKU, or barcode');
    }
    if (addButton) {
        addButton.disabled = !teamSelected || inStockCount === 0;
    }
    if (productCountBadge) {
        productCountBadge.textContent = `${inStockCount} product${inStockCount === 1 ? '' : 's'}`;
        productCountBadge.classList.toggle('d-none', !teamSelected);
    }
    updatePurchaseHeader();
    if (teamHelp) {
        teamHelp.textContent = teamSelected
            ? (locationName ? `Stock location: ${locationName}` : 'Products will load from the selected team stock.')
            : 'Select a team to load products from its stock location.';
    }
    if (productHelp) {
        if (!teamSelected) {
            productHelp.textContent = 'Products appear automatically after choosing a team.';
        } else if (inStockCount === 0) {
            productHelp.textContent = 'No in-stock products found for this team.';
        } else {
            productHelp.textContent = `${inStockCount} in-stock product${inStockCount === 1 ? '' : 's'} loaded for this team.`;
        }
    }
}




function changeQty(id, delta) {
    const item = cart.get(Number(id));
    if (!item) return;
    const maxStock = Number(item.available_stock || 0);
    const next = normalizeQty(Number(item.quantity) + delta, 1, maxStock);
    item.quantity = next;
    cart.set(Number(id), item);
    renderCart();
}

function setQty(id, value) {
    const item = cart.get(Number(id));
    if (!item) return;
    const maxStock = Number(item.available_stock || 0);
    item.quantity = normalizeQty(value, 1, maxStock);
    cart.set(Number(id), item);
    renderCart();
}

function removeItem(id) {
    cart.delete(Number(id));
    renderCart();
}

function clearCart() {
    cart.clear();
    purchaseCart.clear();
    localStorage.removeItem('offlineSaleDraft');
    renderCart();
}

function clearCustomerField(fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    field.value = '';
    field.focus();
}

function clearForm() {
    document.getElementById('customerNameInput').value = '';
    document.getElementById('phoneInput').value = '';
    document.getElementById('customerLocationInput').value = '';
    document.getElementById('customerNameInput').focus();
    document.getElementById('validationAlert').classList.add('d-none');
}

function renderCart() {
    const tbody = document.getElementById('cartRows');
    const inputs = document.getElementById('cartInputs');
    tbody.innerHTML = '';
    inputs.innerHTML = '';
    const items = Array.from(cart.values());
    const purchases = Array.from(purchaseCart.values());
    if (!items.length && !purchases.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No products added.</td></tr>';
    }
    items.forEach((item, index) => {
        const lineTotal = Number(item.selling_price || 0) * Number(item.quantity || 0);
        const initial = String(item.name || 'P').charAt(0).toUpperCase();
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="text-muted fw-semibold">${index + 1}</td>
            <td><span class="badge bg-success-subtle text-success border border-success-subtle">SALE</span></td>
            <td><div class="d-flex align-items-center gap-3"><div class="product-avatar">${initial}</div><div><div class="fw-bold">${escapeHtml(item.name || '')}</div><div class="text-muted small">Stock: ${Number(item.available_stock || 0).toFixed(2)}</div></div></div></td>
            <td class="text-end fw-semibold">${money(item.selling_price)}</td>
            <td class="text-center"><div class="qty-box"><button type="button" onclick="changeQty(${Number(item.id)}, -1)">-</button><input type="number" min="1" step="1" max="${Number(item.available_stock || 0) > 0 ? Math.floor(Number(item.available_stock || 0)) : ''}" value="${Number(item.quantity).toFixed(0)}" class="qty-input" aria-label="Sale quantity for ${escapeHtml(item.name || '')}" onchange="setQty(${Number(item.id)}, this.value)" onblur="setQty(${Number(item.id)}, this.value)"><button type="button" onclick="changeQty(${Number(item.id)}, 1)">+</button></div></td>
            <td class="text-end fw-bold">${money(lineTotal)}</td>
            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeItem(${Number(item.id)})"><i class="bi bi-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        inputs.insertAdjacentHTML('beforeend', `<input type="hidden" name="product_id[]" value="${Number(item.id)}"><input type="hidden" name="quantity[]" value="${Number(item.quantity)}"><input type="hidden" name="unit_price[]" value="${Number(item.selling_price || 0).toFixed(2)}">`);
    });
    purchases.forEach((item, purchaseIndex) => {
        const lineTotal = Number(item.purchase_price || 0) * Number(item.quantity || 0);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="text-muted fw-semibold">${items.length + purchaseIndex + 1}</td>
            <td><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">PURCHASE</span></td>
            <td><div class="fw-bold">${escapeHtml(item.name || '')}</div></td>
            <td class="text-end fw-semibold">${money(item.purchase_price)}</td>
            <td class="text-center"><div class="qty-box"><button type="button" onclick="changePurchaseQty(${Number(item.id)}, -1)">-</button><input type="number" min="1" step="1" value="${Number(item.quantity).toFixed(0)}" class="qty-input" aria-label="Purchase quantity for ${escapeHtml(item.name || '')}" onchange="setPurchaseQty(${Number(item.id)}, this.value)" onblur="setPurchaseQty(${Number(item.id)}, this.value)"><button type="button" onclick="changePurchaseQty(${Number(item.id)}, 1)">+</button></div></td>
            <td class="text-end fw-bold text-success">${money(lineTotal)}</td>
            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="removePurchaseItem(${Number(item.id)})"><i class="bi bi-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        inputs.insertAdjacentHTML('beforeend',
            `<input type="hidden" name="purchase_product_id[]" value="${Number(item.id)}">`
            + `<input type="hidden" name="purchase_quantity[]" value="${Number(item.quantity)}">`
            + `<input type="hidden" name="purchase_unit_price[]" value="${Number(item.purchase_price || 0).toFixed(2)}">`
            + `<input type="hidden" name="purchase_condition[]" value="${escapeHtml(item.condition)}">`
            + `<input type="hidden" name="purchase_reason[]" value="${escapeHtml(item.reason)}">`
        );
    });
    updateSummary();
}

function updateSummary() {
    const items = Array.from(cart.values());
    const purchases = Array.from(purchaseCart.values());
    const subtotal = items.reduce((sum, item) => sum + (Number(item.selling_price || 0) * Number(item.quantity || 0)), 0);
    const purchaseTotal = purchases.reduce((sum, item) => sum + (Number(item.purchase_price || 0) * Number(item.quantity || 0)), 0);
    const discount = Math.max(0, Number(document.getElementById('discountInput').value || 0));
    const total = Math.max(0, subtotal - discount - purchaseTotal);
    const receivedRaw = document.getElementById('receivedInput').value;
    const received = receivedRaw === '' || receivedRaw === null ? 0 : Math.max(0, Number(receivedRaw));

    document.getElementById('subtotalText').textContent = money(subtotal);
    document.getElementById('discountText').textContent = '-' + money(discount);
    document.getElementById('purchaseTotalText').textContent = '-' + money(purchaseTotal);
    document.getElementById('grandTotalText').textContent = money(total);
    const grandMobile = document.getElementById('grandTotalMobile');
    if (grandMobile) grandMobile.textContent = money(total);
    const saleQty = items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    const purchaseQty = purchases.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    document.getElementById('saleItemsText').textContent = String(saleQty);
    document.getElementById('purchaseItemsText').textContent = String(purchaseQty);
    document.getElementById('totalItemsText').textContent = String(saleQty + purchaseQty);

    const resultBox   = document.getElementById('paymentResult');
    const resultWrap  = document.getElementById('paymentResultBox');
    const resultLabel = document.getElementById('paymentResultLabel');
    const resultAmt   = document.getElementById('paymentResultAmount');
    const resultMsg   = document.getElementById('paymentResultMsg');
    const badge       = document.getElementById('paymentStatusBadge');
    const saveBtn     = document.getElementById('saveBtn');
    const saveBtnMob  = document.getElementById('saveBtnMobile');
    const mobilePay   = document.getElementById('mobilePaymentStatus');

    function setSaveEnabled(enabled) {
        if (saveBtn)    { saveBtn.disabled    = !enabled; }
        if (saveBtnMob) { saveBtnMob.disabled = !enabled; }
    }

    // Received = 0 → Unpaid
    if (received <= 0) {
        resultBox.classList.remove('d-none');
        resultWrap.style.cssText = 'background:#f8f9fa;border:1.5px solid #dee2e6;';
        resultLabel.textContent = 'Remaining Amount';
        resultLabel.style.color = '#6c757d';
        resultAmt.textContent = money(total);
        resultAmt.style.color = '#6c757d';
        resultMsg.textContent = 'No payment received';
        resultMsg.style.color = '#6c757d';
        badge.innerHTML = paymentStatusBadgeHtml('unpaid');
        setSaveEnabled(true);
        if (mobilePay) mobilePay.innerHTML = paymentStatusBadgeHtml('unpaid');
    } else if (received < total) {
        // Received < Total → Partially Paid
        const remaining = total - received;
        resultBox.classList.remove('d-none');
        resultWrap.style.cssText = 'background:#fff8f0;border:1.5px solid #ffd08a;';
        resultLabel.textContent = 'Remaining Amount';
        resultLabel.style.color = '#e07b00';
        resultAmt.textContent = money(remaining);
        resultAmt.style.color = '#e07b00';
        resultMsg.textContent = `Customer still owes ${money(remaining)}`;
        resultMsg.style.color = '#e07b00';
        badge.innerHTML = paymentStatusBadgeHtml('partial');
        setSaveEnabled(true);
        if (mobilePay) mobilePay.innerHTML = paymentStatusBadgeHtml('partial');
    } else {
        // Received >= Total → Paid
        const change = received - total;
        resultBox.classList.remove('d-none');
        resultWrap.style.cssText = 'background:#f0fff4;border:1.5px solid #b7ebc6;';
        resultLabel.textContent = 'Change';
        resultLabel.style.color = '#16a34a';
        resultAmt.textContent = money(change);
        resultAmt.style.color = '#16a34a';
        resultMsg.textContent = change > 0 ? 'Return change to customer' : 'Exact payment';
        resultMsg.style.color = '#16a34a';
        badge.innerHTML = paymentStatusBadgeHtml('paid');
        setSaveEnabled(true);
        if (mobilePay) mobilePay.innerHTML = paymentStatusBadgeHtml('paid');
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, match => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[match]));
}

function saveDraft() {
    const field = name => document.querySelector(`[name="${name}"]`);
    const draft = {
        customer_name: field('customer_name')?.value || '',
        phone: field('phone')?.value || '',
        customer_location: field('customer_location')?.value || '',
        sale_date: field('sale_date')?.value || '',
        team_id: document.getElementById('teamSelect')?.value || '',
        status: field('status')?.value || 'unpaid',
        payment_method: field('payment_method')?.value || '',
        paid_note: field('paid_note')?.value || '',
        discount: document.getElementById('discountInput').value || '0',
        received: document.getElementById('receivedInput').value || '0',
        cart: Array.from(cart.values()).map(item => ({id: Number(item.id), quantity: Number(item.quantity || 0)})),
        purchaseCart: Array.from(purchaseCart.values()).map(item => ({
            id: Number(item.id),
            quantity: Number(item.quantity || 0),
            purchase_price: Number(item.purchase_price || 0),
            condition: item.condition,
            reason: item.reason
        }))
    };
    localStorage.setItem('offlineSaleDraft', JSON.stringify(draft));
    alert('Draft saved on this device.');
}

async function loadDraft() {
    let draft = null;
    try {
        draft = JSON.parse(localStorage.getItem('offlineSaleDraft') || 'null');
    } catch (error) {
        draft = null;
    }
    if (!draft) return;
    const field = name => document.querySelector(`[name="${name}"]`);
    const teamSelectEl = document.getElementById('teamSelect');
    if (teamSelectEl && draft.team_id) {
        teamSelectEl.value = String(draft.team_id);
    }
    ['customer_name', 'phone', 'customer_location', 'sale_date', 'status', 'payment_method', 'paid_note'].forEach(name => {
        const input = field(name);
        if (input && draft[name] !== undefined) input.value = draft[name];
    });
    const draftDiscount = Number(draft.discount || 0);
    const draftReceived = Number(draft.received || 0);
    document.getElementById('discountInput').value = draftDiscount > 0 ? String(draft.discount) : '';
    document.getElementById('receivedInput').value = draftReceived > 0 ? String(draft.received) : '';
    return draft;
}

document.getElementById('discountInput').addEventListener('input', updateSummary);
document.getElementById('discountInput').addEventListener('change', updateSummary);
document.getElementById('receivedInput').addEventListener('input', updateSummary);
document.getElementById('receivedInput').addEventListener('change', updateSummary);
document.getElementById('productSelect').addEventListener('change', syncAddProductInfo);
document.getElementById('saleProductSearch')?.addEventListener('input', refreshSaleProductSuggestions);
document.getElementById('saleProductSearch')?.addEventListener('focus', refreshSaleProductSuggestions);
document.getElementById('saleProductSuggestions')?.addEventListener('mousedown', event => {
    const option = event.target.closest('.sale-product-suggestion');
    if (!option) return;
    event.preventDefault();
    const product = products.find(item => Number(item.id) === Number(option.dataset.productId || 0));
    if (!product) return;
    document.getElementById('saleProductSearch').value = product.name;
    document.getElementById('productSelect').value = String(product.id);
    document.getElementById('saleProductSuggestions').classList.add('d-none');
    syncAddProductInfo();
});

function updateLocationLabel() {
    const label = document.getElementById('stockLocationLabel');
    const purchaseLabel = document.getElementById('purchaseLocationLabel');
    const name = locationNameMap[currentLocationId] || '';
    if (purchaseLabel) purchaseLabel.textContent = name ? `— ${name}` : '';
    label.textContent = name ? `— ${name}` : '';
}

function loadEditCart() {
    if (!isEditMode) {
        return;
    }
    editCartBootstrap.forEach(row => {
        const id = Number(row.id);
        let product = products.find(item => Number(item.id) === id);
        if (!product) {
            product = {
                id,
                name: row.name || 'Product',
                selling_price: Number(row.selling_price || 0),
                available_stock: Number(row.quantity || 0),
            };
        }
        const qty = Number(row.quantity || 0);
        const maxStock = Number(product.available_stock || 0);
        cart.set(id, {
            ...product,
            selling_price: Number(row.selling_price ?? product.selling_price ?? 0),
            quantity: maxStock > 0 ? Math.min(qty, maxStock) : qty,
        });
    });
    editPurchaseBootstrap.forEach(row => {
        const product = allPurchaseProducts.find(item => Number(item.id) === Number(row.id)) || row;
        purchaseCart.set(Number(row.id), {
            ...product,
            quantity: Number(row.quantity || 0),
            purchase_price: Number(row.purchase_price || 0),
            condition: row.condition,
            condition_label: conditionLabel(row.condition),
            reason: row.reason
        });
    });
    renderCart();
}

function resolveTeamLocationId(teamSelectEl) {
    if (!teamSelectEl || !teamSelectEl.value) {
        return 0;
    }
    const teamId = parseInt(teamSelectEl.value, 10) || 0;
    const selectedOption = teamSelectEl.options[teamSelectEl.selectedIndex];
    const fromOption = parseInt(selectedOption?.dataset.location || '0', 10) || 0;
    const fromMap = parseInt(teamLocationMap[teamId] ?? teamLocationMap[String(teamId)] ?? '0', 10) || 0;
    return fromOption || fromMap || 0;
}

function productsFromLocationMap(locId) {
    const key = String(locId);
    return locationProductsMap[key] || locationProductsMap[locId] || null;
}

let productLoadToken = 0;
let lastLoadedTeamId = 0;
let ensureProductsDebounce = null;
const loadedTeamIds = new Set();

function scheduleEnsureProductsMatchTeam() {
    if (ensureProductsDebounce) {
        window.clearTimeout(ensureProductsDebounce);
    }
    ensureProductsDebounce = window.setTimeout(() => {
        ensureProductsDebounce = null;
        ensureProductsMatchTeam();
    }, 80);
}

async function fetchLocationProducts(teamId, locId, attempt = 1) {
    const params = new URLSearchParams({
        ajax: 'location_products',
        team_id: String(teamId || 0),
        location_id: String(locId || 0),
    });
    const response = await fetch(`offline_sale_new.php?${params.toString()}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin',
        cache: 'no-store',
    });

    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        data = null;
    }

    if (!response.ok || !data) {
        if (attempt < 2) {
            await new Promise(resolve => window.setTimeout(resolve, 300));
            return fetchLocationProducts(teamId, locId, attempt + 1);
        }
        throw new Error('Failed to load products');
    }
    if (!data.ok) {
        throw new Error(data.message || 'Failed to load products');
    }

    return data;
}

function restoreDraftCart(draft) {
    if (!draft) {
        return;
    }
    (draft.cart || []).forEach(draftItem => {
        const product = products.find(item => Number(item.id) === Number(draftItem.id));
        if (product && Number(draftItem.quantity || 0) > 0) {
            cart.set(Number(product.id), {...product, quantity: Number(draftItem.quantity)});
        }
    });
    (draft.purchaseCart || []).forEach(draftItem => {
        const product = allPurchaseProducts.find(item => Number(item.id) === Number(draftItem.id));
        if (product && Number(draftItem.quantity || 0) > 0) {
            purchaseCart.set(Number(product.id), {
                ...product,
                quantity: Number(draftItem.quantity),
                purchase_price: Number(draftItem.purchase_price || 0),
                condition: draftItem.condition,
                condition_label: conditionLabel(draftItem.condition),
                reason: draftItem.reason
            });
        }
    });
}

function resetProductSelectWithoutTeam(preserveCart) {
    const productSelect = document.getElementById('productSelect');
    const productSearch = document.getElementById('saleProductSearch');
    products = [];
    if (!preserveCart) {
        cart.clear();
        purchaseCart.clear();
    }
    currentLocationId = 0;
    lastLoadedTeamId = 0;
    if (productSelect) {
        productSelect.innerHTML = '<option value="">Select offline team first</option>';
    }
    if (productSearch) {
        productSearch.value = '';
    }
    document.getElementById('saleProductSuggestions')?.classList.add('d-none');
    syncAddProductInfo();
    syncAddButtonState();
    renderCart();
    updateLocationLabel();
}

function populateProductSelect() {
    const sel = document.getElementById('productSelect');
    if (!sel) return;
    if (!hasTeamSelected()) {
        sel.innerHTML = '<option value="">Select offline team first</option>';
        document.getElementById('saleProductSearch').value = '';
        syncAddProductInfo();
        syncTeamProductUiState();
        return;
    }
    const inStock = products.filter(p => Number(p.available_stock || 0) > 0);
    if (!inStock.length) {
        sel.innerHTML = '<option value="">No products in stock for this team</option>';
        syncAddProductInfo();
        syncTeamProductUiState();
        return;
    }
    sel.innerHTML = '<option value="">Select product</option>'
        + inStock.map(p =>
            `<option value="${Number(p.id)}">${escapeHtml(p.name + ' — ' + money(p.selling_price) + ' — Stock ' + Number(p.available_stock || 0).toFixed(2))}</option>`
        ).join('');
    syncAddProductInfo();
    syncTeamProductUiState();
    refreshSaleProductSuggestions();
}

function applyProductsFromCache(list, teamId, locId, preserveCart) {
    if (!preserveCart) {
        cart.clear();
        purchaseCart.clear();
        document.getElementById('saleProductSearch').value = '';
    }
    products = list;
    currentLocationId = parseInt(locId || '0', 10) || 0;
    lastLoadedTeamId = teamId;
    populateProductSelect();
    syncAddButtonState();
    renderCart();
    updateLocationLabel();
}

function loadProductsForTeam(preserveCart) {
    const teamSelectEl = document.getElementById('teamSelect');
    if (!teamSelectEl?.value) {
        resetProductSelectWithoutTeam(preserveCart);
        return Promise.resolve();
    }

    const teamId = parseInt(teamSelectEl?.value || '0', 10) || 0;
    if (!teamId) {
        resetProductSelectWithoutTeam(preserveCart);
        return Promise.resolve();
    }

    if (teamId === lastLoadedTeamId && products.length > 0) {
        populateProductSelect();
        syncTeamProductUiState();
        return Promise.resolve();
    }

    const locId = resolveTeamLocationId(teamSelectEl);
    const cachedList = teamProductsMap[teamId] ?? teamProductsMap[String(teamId)];

    if (Array.isArray(cachedList) && (cachedList.length > 0 || loadedTeamIds.has(teamId))) {
        applyProductsFromCache(cachedList, teamId, locId, preserveCart);
        return Promise.resolve();
    }

    return loadProductsForTeamAjax(preserveCart, teamId, locId);
}

async function loadProductsForTeamAjax(preserveCart, teamId, locId) {
    const loadToken = ++productLoadToken;
    const productSelect = document.getElementById('productSelect');
    const productHelp = document.getElementById('productHelpText');
    if (productSelect) {
        productSelect.disabled = true;
        productSelect.innerHTML = '<option value="">Loading products...</option>';
    }
    if (productHelp) {
        productHelp.textContent = 'Loading products for selected team...';
    }

    try {
        const data = await fetchLocationProducts(teamId, locId);
        if (loadToken !== productLoadToken) {
            return;
        }
        const list = Array.isArray(data.products) ? data.products : [];
        const resolvedLocId = parseInt(data.location_id || locId || '0', 10) || 0;
        if (data.location_name) {
            locationNameMap[resolvedLocId] = data.location_name;
            locationNameMap[String(resolvedLocId)] = data.location_name;
        }
        locationProductsMap[resolvedLocId] = list;
        locationProductsMap[String(resolvedLocId)] = list;
        rebuildTeamProductOptionsCache(teamId, list);
        loadedTeamIds.add(teamId);
        applyProductsFromCache(list, teamId, resolvedLocId, preserveCart);
    } catch (error) {
        if (loadToken !== productLoadToken) {
            return;
        }
        const fallback = locId > 0 ? (productsFromLocationMap(locId) || []) : [];
        if (fallback.length > 0) {
            rebuildTeamProductOptionsCache(teamId, fallback);
        }
        loadedTeamIds.add(teamId);
        applyProductsFromCache(fallback, teamId, locId, preserveCart);
    } finally {
        if (productSelect) {
            productSelect.disabled = false;
        }
        syncTeamProductUiState();
    }
}

function handleTeamSelectionChange(preserveCart) {
    const teamSelectEl = document.getElementById('teamSelect');
    if (!teamSelectEl?.value) {
        resetProductSelectWithoutTeam(preserveCart);
        return;
    }
    const teamId = parseInt(teamSelectEl.value || '0', 10) || 0;
    const locId = resolveTeamLocationId(teamSelectEl);
    if (!teamId) {
        resetProductSelectWithoutTeam(preserveCart);
        return;
    }
    loadProductsForTeamAjax(preserveCart, teamId, locId);
}

function bindTeamProductLoader() {
    const teamSelectEl = document.getElementById('teamSelect');
    if (!teamSelectEl || teamSelectEl.dataset.productLoaderBound === '1') {
        return;
    }
    teamSelectEl.dataset.productLoaderBound = '1';
    const onTeamChanged = () => handleTeamSelectionChange(false);
    teamSelectEl.addEventListener('change', onTeamChanged);
    teamSelectEl.addEventListener('input', onTeamChanged);
}

function productSelectNeedsTeamLoad() {
    const teamSelectEl = document.getElementById('teamSelect');
    if (!teamSelectEl?.value) {
        return false;
    }
    const teamId = parseInt(teamSelectEl.value, 10) || 0;
    if (teamId !== lastLoadedTeamId) {
        return true;
    }
    if (!products.length) {
        return true;
    }
    const productSelect = document.getElementById('productSelect');
    const placeholder = productSelect?.options[0]?.textContent?.trim() || '';
    return placeholder.includes('Select offline team') || placeholder.startsWith('Loading');
}

async function ensureProductsMatchTeam() {
    bindTeamProductLoader();
    const teamSelectEl = document.getElementById('teamSelect');
    if (!teamSelectEl?.value) {
        resetProductSelectWithoutTeam(false);
        return;
    }
    if (!productSelectNeedsTeamLoad()) {
        syncTeamProductUiState();
        return;
    }
    loadProductsForTeam(isEditMode);
}

bindTeamProductLoader();

window.addEventListener('pageshow', () => {
    scheduleEnsureProductsMatchTeam();
});

window.addEventListener('focus', () => {
    scheduleEnsureProductsMatchTeam();
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        scheduleEnsureProductsMatchTeam();
    }
});

function validateForm() {
    const errors = [];

    const customerVal = (document.querySelector('[name="customer_name"]')?.value || '').trim();
    if (!customerVal) errors.push('Customer Name is required.');

    const phoneVal = (document.querySelector('[name="phone"]')?.value || '').trim();
    if (!phoneVal) errors.push('Phone Number is required.');

    const dateVal = document.querySelector('[name="sale_date"]')?.value || '';
    if (!dateVal) errors.push('Sale Date is required.');

    const teamVal = document.querySelector('[name="team_id"]')?.value || '';
    if (!teamVal) errors.push('Offline Team is required.');

    const customerLocVal = (document.querySelector('[name="customer_location"]')?.value || '').trim();
    if (!customerLocVal) errors.push('Customer Location is required.');

    if (cart.size === 0) errors.push('Add at least one product to sell.');

    const saleTotal = Array.from(cart.values()).reduce((sum, item) => sum + Number(item.selling_price || 0) * Number(item.quantity || 0), 0);
    const discount = Math.max(0, Number(document.getElementById('discountInput').value || 0));
    const purchaseTotal = Array.from(purchaseCart.values()).reduce((sum, item) => sum + Number(item.purchase_price || 0) * Number(item.quantity || 0), 0);
    if (purchaseTotal > saleTotal - discount + 0.009) {
        errors.push('Total purchase cannot be greater than the sale amount after discount.');
    }

    const receivedVal = Number(document.getElementById('receivedInput').value || 0);
    if (isEditMode && receivedVal + 0.009 < editPaidMinimum) {
        errors.push('Received amount cannot be less than payments already recorded ($' + editPaidMinimum.toFixed(2) + ').');
    }

    const alertBox = document.getElementById('validationAlert');
    const alertList = document.getElementById('validationList');
    if (errors.length) {
        alertList.innerHTML = errors.map(e => `<li>${e}</li>`).join('');
        alertBox.classList.remove('d-none');
        alertBox.scrollIntoView({behavior: 'smooth', block: 'center'});
        clearTimeout(alertBox._dismissTimer);
        alertBox._dismissTimer = setTimeout(function() {
            alertBox.classList.add('d-none');
        }, 3000);
        return false;
    }
    alertBox.classList.add('d-none');
    return true;
}

function getModalProducts() {
    if (products.length > 0) return products;
    const byId = {};
    Object.values(locationProductsMap).forEach(list => {
        (list || []).forEach(p => {
            const id = Number(p.id);
            const stock = Number(p.available_stock || 0);
            if (!byId[id] || stock > Number(byId[id].available_stock || 0)) {
                byId[id] = p;
            }
        });
    });
    return Object.values(byId);
}

function fillOfflinePickSelect() {
    const sel = document.getElementById('offlinePickProductSelect');
    if (!sel) return;
    const inStock = getModalProducts().filter(p => Number(p.available_stock || 0) > 0);
    sel.innerHTML = '<option value="">Select product</option>'
        + inStock.map(p =>
            `<option value="${Number(p.id)}" data-price="${Number(p.selling_price || 0).toFixed(2)}" data-stock="${Number(p.available_stock || 0).toFixed(2)}">`
            + escapeHtml(`${p.name} — ${money(p.selling_price)} — Stock ${Number(p.available_stock || 0).toFixed(2)}`)
            + '</option>'
        ).join('');
    syncOfflinePickInfo();
}

function syncOfflinePickInfo() {
    const sel = document.getElementById('offlinePickProductSelect');
    const opt = sel?.options[sel.selectedIndex];
    document.getElementById('offlinePickPrice').value = opt?.dataset.price || '0.00';
    document.getElementById('offlinePickStockText').textContent = opt?.dataset.stock || '0';
}

const offlinePickModalEl = document.getElementById('offlinePickProductModal');
if (offlinePickModalEl) {
    offlinePickModalEl.addEventListener('show.bs.modal', () => {
        offlinePickQty = 1;
        document.getElementById('offlinePickQtyText').textContent = '1';
        fillOfflinePickSelect();
    });
}

document.getElementById('offlineSaleForm').addEventListener('submit', function (e) {
    if (!validateForm()) { e.preventDefault(); return; }
    const hidden = document.getElementById('receivedAmountHidden');
    if (hidden) hidden.value = document.getElementById('receivedInput').value || '0';
});

document.getElementById('purchaseBrand')?.addEventListener('change', () => {
    document.getElementById('purchaseSearch').value = '';
    document.getElementById('purchaseProduct').value = '';
    refreshPurchaseProducts();
});
document.getElementById('purchaseSearch')?.addEventListener('input', refreshPurchaseProducts);
document.getElementById('purchaseSearch')?.addEventListener('focus', refreshPurchaseProducts);
document.getElementById('purchaseSuggestions')?.addEventListener('mousedown', event => {
    const option = event.target.closest('.purchase-suggestion');
    if (!option) return;
    event.preventDefault();
    const product = allPurchaseProducts.find(item => Number(item.id) === Number(option.dataset.productId || 0));
    if (!product) return;
    document.getElementById('purchaseSearch').value = product.name;
    document.getElementById('purchaseProduct').value = String(product.id);
    document.getElementById('purchaseSuggestions').classList.add('d-none');
    document.getElementById('purchaseHelp').textContent = `${product.name} selected. It will increase the selected team's stock.`;
});
document.addEventListener('mousedown', event => {
    if (!event.target.closest('.purchase-product-group')) {
        document.getElementById('purchaseSuggestions')?.classList.add('d-none');
    }
    if (!event.target.closest('#saleProductSearch') && !event.target.closest('#saleProductSuggestions')) {
        document.getElementById('saleProductSuggestions')?.classList.add('d-none');
    }
});

(async function initOfflineSalePage() {
    refreshPurchaseProducts();
    let draft = null;
    if (!isEditMode) {
        draft = await loadDraft();
    }

    await loadProductsForTeam(isEditMode);

    if (isEditMode) {
        loadEditCart();
    } else {
        restoreDraftCart(draft);
        renderCart();
    }

    populateProductSelect();
    updateLocationLabel();
    updateSummary();
    scheduleEnsureProductsMatchTeam();
})();

document.querySelectorAll('.auto-dismiss').forEach(function(el) {
    setTimeout(function() {
        el.classList.remove('show');
        setTimeout(function() { el.remove(); }, 300);
    }, 3000);
});
</script>
<?php include __DIR__ . '/../layout/footer.php'; ?>
