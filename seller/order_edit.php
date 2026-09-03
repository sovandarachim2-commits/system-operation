<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['seller', 'admin'], 'seller_orders.update', 'orders.update');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../user_activity_lib.php';

$pdo  = get_db_connection();
ensure_order_items_lucky_box_column($pdo);
$user = current_user();

// Same capability as Order Management "Update": edit any order (not only own seller orders).
$canEditAnyOrder = ($user['role'] ?? '') === 'admin'
    || (rbac_is_enabled($pdo) && has_permission('orders.update'));

// Load dropdown data ($products and pick lists set after existing order items are loaded)

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

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($order_id <= 0) {
    header('Location: orders.php');
    exit;
}

// Ensure order belongs to current seller and load it
if ($canEditAnyOrder) {
    // Admin / Order Management editors can access any order - load with seller info
    $loadStmt = $pdo->prepare('
        SELECT o.*, u.name as seller_name 
        FROM orders o 
        LEFT JOIN users u ON o.seller_id = u.id 
        WHERE o.id = ?
    ');
    $loadStmt->execute([$order_id]);
} else {
    // Seller can only access their own orders
    $loadStmt = $pdo->prepare('
        SELECT o.*, u.name as seller_name 
        FROM orders o 
        LEFT JOIN users u ON o.seller_id = u.id 
        WHERE o.id = ? AND o.seller_id = ?
    ');
    $loadStmt->execute([$order_id, $user['id']]);
}
$order = $loadStmt->fetch();
if (!$order) {
    header('Location: orders.php');
    exit;
}

// Determine if order has been printed (exists in print_jobs)
$printedStmt = $pdo->prepare('SELECT 1 FROM print_jobs WHERE order_id = ? LIMIT 1');
$printedStmt->execute([$order_id]);
$isPrinted = (bool)$printedStmt->fetchColumn();

$fromOrderManagement = $canEditAnyOrder && (
    (isset($_GET['from']) && $_GET['from'] === 'order_management')
    || (isset($_POST['return_to']) && $_POST['return_to'] === 'order_management')
);
$cancel_href = $fromOrderManagement ? '../admin/order_management.php' : 'orders.php';

// Load existing items
$itemsStmt = $pdo->prepare('SELECT oi.*, pr.name, pr.cost FROM order_items oi JOIN products pr ON oi.product_id = pr.id WHERE oi.order_id = ?');
$itemsStmt->execute([$order_id]);
$existingItems = $itemsStmt->fetchAll();

$existingProductIds = array_values(array_unique(array_map(static function ($item) {
    return (int)($item['product_id'] ?? 0);
}, $existingItems)));
$existingProductIds = array_values(array_filter($existingProductIds, static fn($id) => $id > 0));

$current_month = date('Y-m');
$hasLuckyBoxColumn = false;
try {
    $luckyColChk = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'is_lucky_box'");
    $hasLuckyBoxColumn = (bool) ($luckyColChk && $luckyColChk->fetch());
} catch (Throwable $e) {
    $hasLuckyBoxColumn = false;
}

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

if (!empty($existingProductIds)) {
    $phEx = implode(',', array_fill(0, count($existingProductIds), '?'));
    $activeOrExistingSql = "AND (p.active = 1 OR p.id IN ($phEx))";
    $bindExtra = $existingProductIds;
} else {
    $activeOrExistingSql = 'AND p.active = 1';
    $bindExtra = [];
}

$pickGeneralSql = "
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
        END as has_costs
    FROM products p
    LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
    WHERE p.id IN (
        SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
    )
    {$activeOrExistingSql}
    {$generalExcludeLuckySql}
    ORDER BY p.name
";
$pickGeneralStmt = $pdo->prepare($pickGeneralSql);
$pickGeneralStmt->execute(array_merge([$current_month, $current_month], $bindExtra));
$pickProductsGeneral = $pickGeneralStmt->fetchAll();

$pickProductsLucky = [];
if ($hasLuckyBoxColumn) {
    try {
        $pickLuckySql = "
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
                END as has_costs
            FROM products p
            LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
            INNER JOIN product_sets ps ON ps.set_name = p.name AND COALESCE(ps.is_lucky_box, 0) = 1
            WHERE p.id IN (
                SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
            )
            AND COALESCE(NULLIF(p.product_type, ''), 'normal') = 'set'
            {$activeOrExistingSql}
            ORDER BY p.name
        ";
        $pickLuckyStmt = $pdo->prepare($pickLuckySql);
        $pickLuckyStmt->execute(array_merge([$current_month, $current_month], $bindExtra));
        $pickProductsLucky = $pickLuckyStmt->fetchAll();
    } catch (Throwable $e) {
        $pickProductsLucky = [];
    }
}

$byPid = [];
foreach ($pickProductsGeneral as $p) {
    $byPid[(int)$p['id']] = $p;
}
foreach ($pickProductsLucky as $p) {
    if (!isset($byPid[(int)$p['id']])) {
        $byPid[(int)$p['id']] = $p;
    }
}
foreach ($existingItems as $it) {
    $pid = (int)($it['product_id'] ?? 0);
    if ($pid > 0 && !isset($byPid[$pid])) {
        $byPid[$pid] = [
            'id' => $pid,
            'name' => $it['name'],
            'cost' => (float)($it['cost'] ?? 0),
            'original_cost' => 0,
            'supplier_cost' => 0,
            'shipping_cost' => 0,
            'other_costs' => 0,
            'total_cost' => 0,
            'month_year' => null,
            'has_costs' => 0,
        ];
    }
}
$products = array_values($byPid);
usort($products, static function ($a, $b) {
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

// Defaults from existing order
$customer_name    = $order['customer_name'] ?? '';
$phone            = $order['phone'] ?? '';
$location         = $order['location'] ?? '';
$page_id          = (int)($order['page_id'] ?? 0);
$delivery_type_id = (int)($order['delivery_type_id'] ?? 0);
$delivery_cost_id = (int)($order['delivery_cost_id'] ?? 0);
$status           = $order['status'] ?? '';
$payment_method   = $order['payment_method'] ?? '';
$paid_note        = $order['paid_note'] ?? '';
$payment_date     = $order['payment_date'] ?? date('Y-m-d');
$discount         = (float)($order['discount'] ?? 0);

// Block sellers from editing printed orders (admin / Order Management editors may edit)
if ($isPrinted && !$canEditAnyOrder) {
    $errors[] = 'This order was already printed and can only be edited by an admin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $customer_name    = trim($_POST['customer_name'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $location         = trim($_POST['location'] ?? '');
    $page_id          = (int)($_POST['page_id'] ?? 0);
    $delivery_type_id = (int)($_POST['delivery_type_id'] ?? 0);
    $delivery_cost_id = (int)($_POST['delivery_cost_id'] ?? 0);
    $statusRaw        = trim($_POST['status'] ?? '');
    $status           = in_array($statusRaw, ['paid', 'unpaid'], true) ? $statusRaw : '';
    $payment_method   = trim($_POST['payment_method'] ?? '');
    $paid_note        = trim($_POST['paid_note'] ?? '');
    $payment_date     = trim($_POST['payment_date'] ?? '');
    $discountRaw      = trim($_POST['discount'] ?? '');
    $discount         = $discountRaw === '' ? 0 : (float)$discountRaw;
    if ($discount < 0) {
        $discount = 0;
    }

    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $luckyFlags  = $_POST['is_lucky_box'] ?? [];

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

    $items = [];
    $totalProducts = 0;

    // Index products by id for fast lookup
    $productIndex = [];
    foreach ($products as $p) {
        $productIndex[$p['id']] = $p;
    }

    $itemsByKey = [];
    foreach ($product_ids as $idx => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$idx] ?? 0);
        $isLuckyLine = isset($luckyFlags[$idx]) && (string)$luckyFlags[$idx] === '1' ? 1 : 0;
        if ($pid > 0 && $qty > 0 && isset($productIndex[$pid])) {
            $prod = $productIndex[$pid];
            $unitCost = (float)$prod['cost'];
            $key = $pid . '_' . $isLuckyLine;
            if (!isset($itemsByKey[$key])) {
                $itemsByKey[$key] = [
                    'product_id' => $prod['id'],
                    'name'       => $prod['name'],
                    'unit_cost'  => $unitCost,
                    'quantity'   => $qty,
                    'line_total' => $unitCost * $qty,
                    'is_lucky_box' => $isLuckyLine,
                ];
            } else {
                $itemsByKey[$key]['quantity'] = (int)$itemsByKey[$key]['quantity'] + $qty;
                $itemsByKey[$key]['line_total'] = $itemsByKey[$key]['unit_cost'] * $itemsByKey[$key]['quantity'];
            }
        }
    }

    $items = array_values($itemsByKey);
    foreach ($items as $it) {
        $totalProducts += (float)($it['line_total'] ?? 0);
    }

    if (!$items) {
        $errors[] = 'Please add at least one product.';
    }

    if ($status === 'paid' && $paid_note === '') {
        $errors[] = 'Paid confirmation note is required when status is Paid.';
    }

    if ($status === 'paid' && $payment_method === '') {
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

    if (!$errors) {
        // If order is printed and admin is editing, compute deltas.
        // We will validate stock for increases and restock for decreases.
        $deltas = [];
        $increaseNeeds = [];
        $decreaseNeeds = [];
        // For product sets (bundles)
        $setIncreaseNeeds = [];
        $setDecreaseNeeds = [];
        if ($isPrinted && $canEditAnyOrder) {
            // Build old qty map from existingItems
            $oldQty = [];
            foreach ($existingItems as $it) {
                $pid = (int)$it['product_id'];
                if (!isset($oldQty[$pid])) {
                    $oldQty[$pid] = 0;
                }
                $oldQty[$pid] += (int)$it['quantity'];
            }
            // Build new qty map from merged items (same totals as POST after collapsing duplicate lines)
            $newQty = [];
            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity'];
                if (!isset($newQty[$pid])) {
                    $newQty[$pid] = 0;
                }
                $newQty[$pid] += $qty;
            }
            // Compute deltas
            $allPids = array_unique(array_merge(array_keys($oldQty), array_keys($newQty)));
            foreach ($allPids as $pid) {
                $before = (int)($oldQty[$pid] ?? 0);
                $after  = (int)($newQty[$pid] ?? 0);
                $delta  = $after - $before;
                if ($delta !== 0) {
                    $deltas[$pid] = $delta;
                }
            }
            // Prepare default location and product names for both increase and decrease processing
            // Get default storage location
            $locStmt = $pdo->prepare('SELECT id, location_name FROM storage_locations WHERE is_default = 1 LIMIT 1');
            $locStmt->execute();
            $defaultLocation = $locStmt->fetch(PDO::FETCH_ASSOC);
            if (!$defaultLocation) {
                $errors[] = 'No default storage location configured. Please set a default location in Storage Locations.';
            } else {
                // Build product id -> name map for inventory lookup
                $pidList = array_keys($deltas);
                if ($pidList) {
                    $placeholders = implode(',', array_fill(0, count($pidList), '?'));
                    $pstmt = $pdo->prepare("SELECT id, name FROM products WHERE id IN ($placeholders)");
                    $pstmt->execute($pidList);
                    $pnames = $pstmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => name
                    // Also load product types for set detection
                    $ptypeStmt = $pdo->prepare("SELECT id, COALESCE(product_type,'normal') AS pt FROM products WHERE id IN ($placeholders)");
                    $ptypeStmt->execute($pidList);
                    $ptypes = [];
                    foreach ($ptypeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $ptypes[(int)$row['id']] = (string)$row['pt']; }
                    // Preload product_sets info by set_name for any set products
                    $setNames = [];
                    foreach ($deltas as $pid => $_d) {
                        if (($ptypes[$pid] ?? 'normal') === 'set') {
                            if (isset($pnames[$pid])) { $setNames[] = $pnames[$pid]; }
                        }
                    }
                    $setInfoByName = [];
                    if ($setNames) {
                        $placeSet = implode(',', array_fill(0, count($setNames), '?'));
                        $sstmt = $pdo->prepare("SELECT id, set_name, available_stock FROM product_sets WHERE set_name IN ($placeSet)");
                        $sstmt->execute($setNames);
                        foreach ($sstmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                            $setInfoByName[(string)$sr['set_name']] = [
                                'id' => (int)$sr['id'],
                                'available_stock' => (float)$sr['available_stock'],
                            ];
                        }
                    }
                    foreach ($deltas as $pid => $delta) {
                        $pname = $pnames[$pid] ?? null;
                        if ($pname === null) { $errors[] = 'Product not found for stock validation.'; break; }
                        $ptype = $ptypes[$pid] ?? 'normal';
                        if ($ptype === 'set') {
                            if ($delta > 0) {
                                $sinfo = $setInfoByName[$pname] ?? ['id' => 0, 'available_stock' => 0.0];
                                $setIncreaseNeeds[] = [
                                    'product_id' => $pid,
                                    'set_id' => (int)$sinfo['id'],
                                    'set_name' => $pname,
                                    'delta' => (int)$delta,
                                    'available' => (float)$sinfo['available_stock'],
                                ];
                            } elseif ($delta < 0) {
                                $setDecreaseNeeds[] = [
                                    'product_id' => $pid,
                                    'set_name' => $pname,
                                    'delta' => (int)abs($delta),
                                ];
                            }
                        } else {
                            if ($delta > 0) {
                                // Sum all rows at default location (duplicates); deduct FIFO when applying
                                $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_on_hand), 0) FROM current_inventory WHERE item_name = ? AND storage_location_id = ?');
                                $sumStmt->execute([$pname, $defaultLocation['id']]);
                                $available = (float)$sumStmt->fetchColumn();
                                if ($available < $delta) {
                                    $errors[] = "Insufficient stock for {$pname} at {$defaultLocation['location_name']}: need {$delta}, available {$available}.";
                                    break;
                                }
                                $increaseNeeds[] = [
                                    'product_id' => $pid,
                                    'product_name' => $pname,
                                    'delta' => (int)$delta,
                                    'location_id' => (int)$defaultLocation['id'],
                                    'location_name' => $defaultLocation['location_name']
                                ];
                            } elseif ($delta < 0) {
                                // Decrease (restock inbound) for normal products
                                $abs = (int)abs($delta);
                                $decreaseNeeds[] = [
                                    'product_id' => $pid,
                                    'product_name' => $pname,
                                    'delta' => $abs,
                                    'location_id' => (int)$defaultLocation['id'],
                                    'location_name' => $defaultLocation['location_name']
                                ];
                            }
                        }
                    }
                }
            }
        }
        try {
            $pdo->beginTransaction();

            // Fetch delivery cost amount to include in total
            $deliveryAmount = 0;
            if ($delivery_cost_id > 0) {
                $cstmt = $pdo->prepare('SELECT amount FROM delivery_costs WHERE id = ?');
                $cstmt->execute([$delivery_cost_id]);
                $row = $cstmt->fetch();
                if ($row && $row['amount'] !== null) {
                    $deliveryAmount = (float)$row['amount'];
                }
            }

            $grandTotal = $totalProducts + $deliveryAmount - $discount;
            if ($grandTotal < 0) {
                $grandTotal = 0;
            }

            $stmt = $pdo->prepare('UPDATE orders
                SET customer_name = ?, phone = ?, location = ?, page_id = ?, delivery_type_id = ?, delivery_cost_id = ?, status = ?, is_paid = ?, payment_method = ?, paid_note = ?, payment_date = ?, discount = ?, total_amount = ?
                WHERE id = ?' . ($canEditAnyOrder ? '' : ' AND seller_id = ?'));
            $params = [
                $customer_name,
                $phone,
                $location,
                $page_id ?: null,
                $delivery_type_id ?: null,
                $delivery_cost_id ?: null,
                $status,
                ($status === 'paid') ? 1 : 0,
                $payment_method ?: null,
                $paid_note ?: null,
                ($status === 'paid' && $payment_date) ? $payment_date : null,
                $discount,
                $grandTotal,
                $order_id,
            ];
            if (!$canEditAnyOrder) {
                $params[] = $user['id'];
            }
            $stmt->execute($params);

            // If printed and admin with changes, apply stock movements now (before items replace to ensure atomicity)
            if ($isPrinted && $canEditAnyOrder && !$errors) {
                // Ensure default storage location for set operations
                $locStmt2 = $pdo->prepare('SELECT id, location_name FROM storage_locations WHERE is_default = 1 LIMIT 1');
                $locStmt2->execute();
                $defLoc = $locStmt2->fetch(PDO::FETCH_ASSOC);
                if (!$defLoc) { throw new Exception('No default storage location configured.'); }

                // Handle product sets first
                if (!empty($setIncreaseNeeds)) {
                    foreach ($setIncreaseNeeds as $need) {
                        $delta = (int)$need['delta'];
                        $setName = $need['set_name'];
                        // Fetch current set row
                        $sinfoStmt = $pdo->prepare('SELECT id, available_stock FROM product_sets WHERE ' . ($need['set_id'] ? 'id = ?' : 'set_name = ?') . ' LIMIT 1');
                        $sinfoStmt->execute([$need['set_id'] ?: $setName]);
                        $sinfoCur = $sinfoStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$sinfoCur) { throw new Exception("Product set '{$setName}' not found"); }
                        $available = (float)($sinfoCur['available_stock'] ?? 0);
                        $missing = 0;
                        if ($available < $delta) {
                            $missing = $delta - $available;
                            // Auto-create only product set units by consuming required components.
                            $componentsStmt = $pdo->prepare('SELECT psi.quantity, p.name AS product_name FROM product_set_items psi JOIN products p ON psi.product_id = p.id WHERE psi.product_set_id = ?');
                            $componentsStmt->execute([(int)$sinfoCur['id']]);
                            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                            if (empty($components)) { throw new Exception("Cannot save/print '{$setName}': no components configured for this set."); }
                            // Validate component availability first; if not enough, block flow.
                            foreach ($components as $component) {
                                $reqQty = (float)$component['quantity'] * $missing;
                                $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_on_hand),0) FROM current_inventory WHERE item_name = ? AND storage_location_id = ?');
                                $sumStmt->execute([$component['product_name'], $defLoc['id']]);
                                $availComp = (float)$sumStmt->fetchColumn();
                                if ($availComp < $reqQty) {
                                    throw new Exception("Cannot save/print '{$setName}': component '{$component['product_name']}' is not enough (need {$reqQty}, have {$availComp}).");
                                }
                            }
                            // Consume components (FIFO)
                            foreach ($components as $component) {
                                $reqQty = (float)$component['quantity'] * $missing;
                                $fifoStmt = $pdo->prepare('SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 ORDER BY last_updated ASC');
                                $fifoStmt->execute([$component['product_name'], $defLoc['id']]);
                                $rows = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
                                $remain = $reqQty;
                                foreach ($rows as $row) {
                                    if ($remain <= 0) break;
                                    $reduce = min($remain, (float)$row['quantity_on_hand']);
                                    if ($reduce <= 0) continue;
                                    $upd = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand - ?, last_updated = NOW() WHERE id = ?');
                                    $upd->execute([$reduce, $row['id']]);
                                    $logComp = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'set_auto_creation_component_out', ?, 'product_set', ?, ?, ?)");
                                    $logComp->execute([$defLoc['id'], $reduce, (int)$sinfoCur['id'], "Auto-created set component usage for {$setName} - {$component['product_name']}", $user['id']]);
                                    $remain -= $reduce;
                                }
                            }
                            // Create missing set units only after successful component validation/consumption.
                            $incSet = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock + ?, total_created = total_created + ?, updated_at = NOW() WHERE id = ?');
                            $incSet->execute([$missing, $missing, (int)$sinfoCur['id']]);
                            $logSetCreate = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'set_auto_created', ?, 'product_set', ?, ?, ?)");
                            $logSetCreate->execute([$defLoc['id'], $missing, (int)$sinfoCur['id'], "Auto-created missing set stock during order edit for {$setName}", $user['id']]);
                            $available += $missing;
                        }
                        // Outbound set units
                        $outSet = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock - ?, updated_at = NOW() WHERE id = ? AND available_stock >= ?');
                        $outSet->execute([$delta, (int)$sinfoCur['id'], $delta]);
                        if ($outSet->rowCount() == 0) { throw new Exception("Insufficient set stock for '{$setName}': Required {$delta}"); }
                        $logSetOut = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'set_outbound', ?, 'order', ?, ?, ?)");
                        $logSetOut->execute([$defLoc['id'], $delta, $order_id, "Product set sold: {$setName}", $user['id']]);
                    }
                }
                if (!empty($setDecreaseNeeds)) {
                    foreach ($setDecreaseNeeds as $need) {
                        $setName = $need['set_name'];
                        $delta = (int)$need['delta'];
                        $setStmt = $pdo->prepare('SELECT id FROM product_sets WHERE set_name = ? LIMIT 1');
                        $setStmt->execute([$setName]);
                        $setId = (int)($setStmt->fetchColumn() ?: 0);
                        if ($setId <= 0) {
                            throw new Exception("Product set '{$setName}' not found");
                        }

                        $componentsStmt = $pdo->prepare('
                            SELECT psi.product_id, psi.quantity, p.name AS product_name
                            FROM product_set_items psi
                            JOIN products p ON psi.product_id = p.id
                            WHERE psi.product_set_id = ?
                        ');
                        $componentsStmt->execute([$setId]);
                        $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                        if (empty($components)) {
                            throw new Exception("Cannot return components for '{$setName}': no components configured for this set.");
                        }

                        foreach ($components as $component) {
                            $returnQty = (float)$component['quantity'] * $delta;
                            if ($returnQty <= 0) {
                                continue;
                            }

                            $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? LIMIT 1');
                            $invStmt->execute([$component['product_name'], $defLoc['id']]);
                            $invId = $invStmt->fetchColumn();
                            if ($invId) {
                                $updInv = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() WHERE id = ?');
                                $updInv->execute([$returnQty, $invId]);
                            } else {
                                $insInv = $pdo->prepare('INSERT INTO current_inventory (item_name, storage_location_id, quantity_on_hand, last_updated) VALUES (?, ?, ?, NOW())');
                                $insInv->execute([$component['product_name'], $defLoc['id'], $returnQty]);
                            }

                            $logSetComponentIn = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'inbound', ?, 'order', ?, ?, ?)");
                            $logSetComponentIn->execute([
                                (int)$component['product_id'],
                                $defLoc['id'],
                                $returnQty,
                                $order_id,
                                "Order revised set component return: {$setName} - {$component['product_name']}",
                                $user['id']
                            ]);
                        }
                    }
                }
                // Outbound for increases (FIFO across duplicate inventory rows)
                if (!empty($increaseNeeds)) {
                    foreach ($increaseNeeds as $need) {
                        $remain = (float)$need['delta'];
                        $fifoStmt = $pdo->prepare('SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 ORDER BY last_updated ASC, id ASC');
                        $fifoStmt->execute([$need['product_name'], $need['location_id']]);
                        $invRows = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($invRows as $invRow) {
                            if ($remain <= 0) {
                                break;
                            }
                            $rowQty = (float)($invRow['quantity_on_hand'] ?? 0);
                            if ($rowQty <= 0) {
                                continue;
                            }
                            $reduce = min($remain, $rowQty);
                            $updInv = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand - ?, last_updated = NOW() WHERE id = ?');
                            $updInv->execute([$reduce, $invRow['id']]);
                            $remain -= $reduce;
                        }
                        if ($remain > 0.00001) {
                            throw new Exception('Insufficient inventory for ' . $need['product_name'] . ' during apply (stock may have changed).');
                        }
                        $logStmt = $pdo->prepare('INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, \'outbound\', ?, \'order\', ?, ?, ?)');
                        $logStmt->execute([
                            $need['product_id'],
                            $need['location_id'],
                            $need['delta'],
                            $order_id,
                            'Order revised - ' . $need['product_name'],
                            $user['id']
                        ]);
                    }
                }
                // Inbound for decreases (restock)
                if (!empty($decreaseNeeds)) {
                    foreach ($decreaseNeeds as $need) {
                        // Find or create inventory row for inbound
                        $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? LIMIT 1');
                        $invStmt->execute([$need['product_name'], $need['location_id']]);
                        $invId = $invStmt->fetchColumn();
                        if ($invId) {
                            $updInv = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() WHERE id = ?');
                            $updInv->execute([$need['delta'], $invId]);
                        } else {
                            // Create new inventory row
                            $insInv = $pdo->prepare('INSERT INTO current_inventory (item_name, storage_location_id, quantity_on_hand, last_updated) VALUES (?, ?, ?, NOW())');
                            $insInv->execute([$need['product_name'], $need['location_id'], $need['delta']]);
                        }
                        // Log inbound
                        $logStmt2 = $pdo->prepare('INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, \'inbound\', ?, \'order\', ?, ?, ?)');
                        $logStmt2->execute([
                            $need['product_id'],
                            $need['location_id'],
                            $need['delta'],
                            $order_id,
                            'Order revised return - ' . $need['product_name'],
                            $user['id']
                        ]);
                    }
                }
            }

            // Write audit entry for this edit (capture deltas old->new)
            try {
                // Ensure audit table exists
                $pdo->exec("CREATE TABLE IF NOT EXISTS order_edit_audit (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    order_id INT NOT NULL,
                    user_id INT NULL,
                    user_name VARCHAR(255) NULL,
                    action VARCHAR(100) NOT NULL,
                    details TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Build change details
                $auditChanges = [];
                // Build $oldQtyMap from existingItems
                $oldQtyMap = [];
                foreach ($existingItems as $it) {
                    $pid = (int)$it['product_id'];
                    if (!isset($oldQtyMap[$pid])) {
                        $oldQtyMap[$pid] = 0;
                    }
                    $oldQtyMap[$pid] += (int)$it['quantity'];
                }
                // Build $newQtyMap from the newly posted items list
                $newQtyMap = [];
                foreach ($items as $it) { $pid = (int)$it['product_id']; $q=(int)$it['quantity']; if (!isset($newQtyMap[$pid])) $newQtyMap[$pid]=0; $newQtyMap[$pid]+=$q; }
                $allAuditPids = array_unique(array_merge(array_keys($oldQtyMap), array_keys($newQtyMap)));
                // Resolve product names
                $pnamesAudit = [];
                if ($allAuditPids) {
                    $ph = implode(',', array_fill(0, count($allAuditPids), '?'));
                    $pnStmt = $pdo->prepare("SELECT id, name FROM products WHERE id IN ($ph)");
                    $pnStmt->execute($allAuditPids);
                    $pnamesAudit = $pnStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                }
                foreach ($allAuditPids as $pid) {
                    $before = (int)($oldQtyMap[$pid] ?? 0);
                    $after  = (int)($newQtyMap[$pid] ?? 0);
                    $delta  = $after - $before;
                    if ($delta !== 0) {
                        $auditChanges[] = [
                            'product_id' => $pid,
                            'product_name' => $pnamesAudit[$pid] ?? '',
                            'old_qty' => $before,
                            'new_qty' => $after,
                            'delta' => $delta
                        ];
                    }
                }
                $actionStr = $isPrinted && $canEditAnyOrder ? 'order_edit_printed_admin' : 'order_edit';
                $detailsJson = json_encode([
                    'printed' => $isPrinted,
                    'changes' => $auditChanges,
                    'totals' => [ 'grand_total' => $grandTotal ]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $auditStmt = $pdo->prepare('INSERT INTO order_edit_audit (order_id, user_id, user_name, action, details) VALUES (?, ?, ?, ?, ?)');
                $auditStmt->execute([
                    $order_id,
                    $user['id'] ?? null,
                    $user['name'] ?? null,
                    $actionStr,
                    $detailsJson
                ]);
            } catch (Throwable $ignoreAudit) {
                // Do not block order save if audit fails
            }

            // Replace items
            $delStmt = $pdo->prepare('DELETE FROM order_items WHERE order_id = ?');
            $delStmt->execute([$order_id]);

            $itemsStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_cost, line_total, is_lucky_box) VALUES (?,?,?,?,?,?)');
            foreach ($items as $item) {
                $itemsStmt->execute([
                    $order_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['line_total'],
                    !empty($item['is_lucky_box']) ? 1 : 0,
                ]);
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            $det = user_activity_seller_order_log_details([
                'order_id' => $order_id,
                'code' => $order['order_code'] ?? '',
                'customer' => $customer_name,
                'phone' => $phone,
                'status' => $status,
                'total' => $grandTotal,
                'printed' => $isPrinted ? 'yes' : 'no',
                'by' => $canEditAnyOrder ? 'admin_or_mgmt' : 'seller',
            ], $items);
            user_activity_log_module_mutation($user, 'seller', 'update', __FILE__, $det !== '' ? $det : 'order id ' . $order_id);

            // Send updated order to Telegram as reply (if original exists)
            send_order_to_telegram_async($pdo, $order_id);

            if ($canEditAnyOrder) {
                header('Location: ../admin/order_management.php?updated=1');
            } else {
                header('Location: orders.php?updated=1');
            }
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to update order: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="row flex-grow-1">
    <div class="col-12 col-lg-8 mx-auto py-3">
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div id="friendlyErrorCard" class="card border-danger shadow-sm mb-3">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-x-circle-fill me-2"></i>Cannot Save Order</span>
                    <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="document.getElementById('friendlyErrorCard').style.display='none'"></button>
                </div>
                <div class="card-body">
                    <div class="text-danger fw-semibold mb-2">Please resolve the following issue(s):</div>
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?>
                            <li class="text-danger"><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <script>
            setTimeout(function(){
                var c = document.getElementById('friendlyErrorCard');
                if (c) c.style.display = 'none';
            }, 12000);
            </script>
        <?php endif; ?>

        <form method="post" id="orderForm">
            <?php if ($fromOrderManagement): ?>
                <input type="hidden" name="return_to" value="order_management">
            <?php endif; ?>
            <div class="seller-panel seller-panel-yellow">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <div class="fw-bold" style="font-size:1rem;">Edit order <?= htmlspecialchars($order['order_code']) ?></div>
                        <div class="text-muted" style="font-size:0.85rem;">Update customer details and products</div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Customer Name</div>
                        <input type="text" name="customer_name" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($customer_name) ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Seller</div>
                        <input type="text" class="form-control form-control-lg seller-input" value="<?= htmlspecialchars($order['seller_name']) ?>" disabled>
                    </div>
                </div>

                <div class="seller-label mb-1">Product Order</div>
                <div class="mb-2">
                    <span class="badge bg-info" style="font-size: 11px;">Costs: <?= date('F Y', strtotime($current_month . '-01')) ?></span>
                </div>
                <div id="productRows" class="d-flex flex-column gap-2 mb-2"></div>
                <button type="button" class="btn btn-light btn-add-more mt-1" id="addProductRow" data-bs-toggle="modal" data-bs-target="#pickProductModal">Add product</button>
                <p class="small text-muted mt-2 mb-0">Use <strong>Add product</strong> for <strong>General</strong> (full catalog) or <strong>Lucky box</strong> (admin-marked sets).</p>
            </div>

            <div class="seller-panel seller-panel-pink">
                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Delivery type</div>
                        <select name="delivery_type_id" class="form-select form-select-lg seller-select">
                            <option value="">Select type</option>
                            <?php foreach ($types as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $delivery_type_id === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="seller-label mb-1">Delivery Cost</div>
                        <select name="delivery_cost_id" class="form-select form-select-lg seller-select">
                            <option value="">Select cost</option>
                            <?php foreach ($costs as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" data-amount="<?= htmlspecialchars((string)$c['amount']) ?>" <?= $delivery_cost_id === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
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
                            <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
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
                        <a href="<?= htmlspecialchars($cancel_href) ?>" class="btn btn-cancel-soft btn-lg">Cancel</a>
                        <button type="submit" class="btn btn-next btn-lg">Save</button>
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
                <div id="pickStepProduct" style="display:none;">
                    <button type="button" class="btn btn-link text-decoration-none ps-0 mb-2" id="pickBackBtn">&larr; Back</button>
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
    const products = <?php echo json_encode($pickProductsGeneral, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const luckyProducts = <?php echo json_encode($pickProductsLucky, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const existingItems = <?php echo json_encode($existingItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const rowsContainer  = document.getElementById('productRows');
    const totalEl        = document.getElementById('totalAmount');
    const deliverySelect = document.querySelector('select[name="delivery_cost_id"]');
    const statusSelect   = document.getElementById('statusSelect');
    const paidFieldsGroup = document.getElementById('paidFieldsGroup');
    const discountInput  = document.getElementById('discountInput');
    const orderForm      = document.getElementById('orderForm');
    const pickModalEl    = document.getElementById('pickProductModal');

    const pickStepChoose = document.getElementById('pickStepChoose');
    const pickStepProduct = document.getElementById('pickStepProduct');
    const pickGeneralBtn = document.getElementById('pickGeneralBtn');
    const pickLuckyBtn = document.getElementById('pickLuckyBtn');
    const pickBackBtn = document.getElementById('pickBackBtn');
    const pickProductSelect = document.getElementById('pickProductSelect');
    const pickQtyInput = document.getElementById('pickQtyInput');
    const pickConfirmBtn = document.getElementById('pickConfirmBtn');
    const pickLuckyEmpty = document.getElementById('pickLuckyEmpty');
    const pickProductFields = document.getElementById('pickProductFields');

    let pickMode = null;

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function resetPickModal() {
        pickMode = null;
        pickStepChoose.style.display = '';
        pickStepProduct.style.display = 'none';
        pickProductSelect.innerHTML = '';
        pickQtyInput.value = '1';
        pickLuckyEmpty.classList.add('d-none');
        pickProductFields.classList.remove('d-none');
    }

    function fillPickSelect(arr) {
        const opts = ['<option value="">Select product</option>'].concat(
            arr.map(p => '<option value="' + p.id + '" data-cost="' + p.cost + '" data-has-costs="' + p.has_costs + '">' + escapeHtml(p.name) + '</option>')
        );
        pickProductSelect.innerHTML = opts.join('');
    }

    if (pickModalEl) {
        pickModalEl.addEventListener('show.bs.modal', resetPickModal);
    }

    if (pickGeneralBtn) pickGeneralBtn.addEventListener('click', () => {
        pickMode = 'general';
        pickStepChoose.style.display = 'none';
        pickStepProduct.style.display = '';
        pickLuckyEmpty.classList.add('d-none');
        pickProductFields.classList.remove('d-none');
        fillPickSelect(products);
    });

    if (pickLuckyBtn) pickLuckyBtn.addEventListener('click', () => {
        pickMode = 'lucky';
        pickStepChoose.style.display = 'none';
        pickStepProduct.style.display = '';
        if (!luckyProducts.length) {
            pickLuckyEmpty.classList.remove('d-none');
            pickProductFields.classList.add('d-none');
        } else {
            pickLuckyEmpty.classList.add('d-none');
            pickProductFields.classList.remove('d-none');
            fillPickSelect(luckyProducts);
        }
    });

    if (pickBackBtn) pickBackBtn.addEventListener('click', () => {
        pickStepChoose.style.display = '';
        pickStepProduct.style.display = 'none';
        pickMode = null;
        pickLuckyEmpty.classList.add('d-none');
        pickProductFields.classList.remove('d-none');
    });

    if (pickConfirmBtn) pickConfirmBtn.addEventListener('click', () => {
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
        if (typeof bootstrap !== 'undefined' && pickModalEl) {
            const modalInst = bootstrap.Modal.getOrCreateInstance(pickModalEl);
            modalInst.hide();
        }
    });

    function optionsHtmlForList(list, selectedId) {
        const sid = String(selectedId || '');
        return list.map(p => {
            const isSelected = String(p.id) === sid ? ' selected' : '';
            return '<option value="' + p.id + '" data-cost="' + p.cost + '" data-has-costs="' + p.has_costs + '"' + isSelected + '>' + escapeHtml(p.name) + '</option>';
        }).join('');
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
        const luckyHidden = mode === 'lucky' ? '1' : '0';
        row.innerHTML = `
            <input type="hidden" name="is_lucky_box[]" value="${luckyHidden}">
            <div class="col-12 col-md-2 col-lg-2">
                <span class="badge ${badgeClass} line-mode-pill">${badgeLabel}</span>
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
        mergeDuplicateProductRows();
    }

    function rowLineKey(row) {
        const sel = row.querySelector('.product-select');
        const luckyH = row.querySelector('input[name="is_lucky_box[]"]');
        const pid = sel && sel.value;
        if (!pid) {
            return null;
        }
        const mode = luckyH && luckyH.value === '1' ? 'lucky' : 'general';
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
            const luckyH = currentRow ? currentRow.querySelector('input[name="is_lucky_box[]"]') : null;
            const rowMode = luckyH && luckyH.value === '1' ? 'lucky' : 'general';
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

    if (existingItems && existingItems.length) {
        existingItems.forEach(function(item){
            const lm = parseInt(item.is_lucky_box, 10) === 1 ? 'lucky' : 'general';
            createRow(item.product_id || '', item.quantity || 1, lm);
        });
    } else {
        createRow('', 1, 'general');
    }
    mergeDuplicateProductRows();
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
