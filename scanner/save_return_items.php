<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.create');
require_once __DIR__ . '/../user_activity_lib.php';
require_once 'config.php';
require_once __DIR__ . '/../db.php';

@set_time_limit(120);

function getDefaultStorageLocationId(PDO $pdo) {
    $stmt = $pdo->query("SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1");
    return (int)($stmt->fetchColumn() ?: 0);
}

function upsertInventoryQuantity(PDO $pdo, $productId, $productName, $quantityDelta, $locationId, $userId) {
    $inventoryStmt = $pdo->prepare("
        SELECT id, quantity_on_hand
        FROM current_inventory
        WHERE item_name = ? AND storage_location_id = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $inventoryStmt->execute([$productName, $locationId]);
    $inventoryRow = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

    if ($inventoryRow) {
        $newQuantity = (float)$inventoryRow['quantity_on_hand'] + (float)$quantityDelta;
        if ($newQuantity < 0) {
            throw new Exception("Insufficient inventory for {$productName}");
        }

        $updateStmt = $pdo->prepare("
            UPDATE current_inventory
            SET quantity_on_hand = ?,
                last_updated = NOW(),
                updated_by = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$newQuantity, $userId, $inventoryRow['id']]);
        return;
    }

    if ($quantityDelta < 0) {
        throw new Exception("Inventory record not found for {$productName}");
    }

    $productStmt = $pdo->prepare("SELECT sku, cost FROM products WHERE id = ? LIMIT 1");
    $productStmt->execute([$productId]);
    $productRow = $productStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $insertStmt = $pdo->prepare("
        INSERT INTO current_inventory (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $productName,
        $productRow['sku'] ?? null,
        $locationId,
        $quantityDelta,
        $productRow['cost'] ?? 0,
        $userId
    ]);
}

function applyReturnedOrderInventory(PDO $pdo, $orderId, $userId) {
    $locationId = getDefaultStorageLocationId($pdo);
    if ($locationId <= 0) {
        throw new Exception('Default storage location is required for order returns');
    }

    $orderStmt = $pdo->prepare("SELECT order_code, is_returned FROM orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$orderId]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderRow) {
        throw new Exception('Order not found');
    }

    if ((int)$orderRow['is_returned'] === 1) {
        throw new Exception('Order is already marked as returned');
    }

    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.quantity AS order_quantity, p.name AS product_name, COALESCE(p.product_type, 'normal') AS product_type
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $orderedQuantity = (float)$item['order_quantity'];

        if ($item['product_type'] === 'set') {
            $setStmt = $pdo->prepare("SELECT id FROM product_sets WHERE set_name = ? LIMIT 1");
            $setStmt->execute([$item['product_name']]);
            $setId = (int)($setStmt->fetchColumn() ?: 0);

            if ($setId <= 0) {
                throw new Exception("Product set not found for {$item['product_name']}");
            }

            $componentsStmt = $pdo->prepare("
                SELECT psi.product_id, psi.quantity, p.name AS product_name
                FROM product_set_items psi
                JOIN products p ON p.id = psi.product_id
                WHERE psi.product_set_id = ?
            ");
            $componentsStmt->execute([$setId]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($components)) {
                throw new Exception("No components found for product set {$item['product_name']}");
            }

            foreach ($components as $component) {
                $componentQuantity = (float)$component['quantity'] * $orderedQuantity;
                upsertInventoryQuantity($pdo, $component['product_id'], $component['product_name'], $componentQuantity, $locationId, $userId);

                $logStmt = $pdo->prepare("
                    INSERT INTO stock_operations
                    (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
                    VALUES (?, 'return_component_in', ?, 'order_return', ?, ?, ?)
                ");
                $logStmt->execute([
                    $locationId,
                    abs($componentQuantity),
                    $orderId,
                    'Returned set component restored for order ' . $orderRow['order_code'] . ' - ' . $component['product_name'],
                    $userId
                ]);
            }

            continue;
        }

        upsertInventoryQuantity($pdo, $item['product_id'], $item['product_name'], $orderedQuantity, $locationId, $userId);

        $logStmt = $pdo->prepare("
            INSERT INTO stock_operations
            (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
            VALUES (?, 'return_in', ?, 'order_return', ?, ?, ?)
        ");
        $logStmt->execute([
            $locationId,
            abs($orderedQuantity),
            $orderId,
            'Returned product restored for order ' . $orderRow['order_code'] . ' - ' . $item['product_name'],
            $userId
        ]);
    }
}

$inv         = $_POST["inv"] ?? "";
$delivery_by = $_POST["delivery_by"] ?? "";
$reason      = $_POST["reason"] ?? "";
$username    = $_POST["username"] ?? "default_user";
$date_time   = $_POST["date_time"] ?? date("Y-m-d H:i:s");

// Escaped copies used only for MySQLi string-interpolation queries
$inv_esc         = $conn->real_escape_string($inv);
$date_time_esc   = $conn->real_escape_string($date_time);

// --- Duplicate check ---
$check = $conn->query("SELECT id FROM return_items WHERE inv='$inv_esc' LIMIT 1");
if ($check && $check->num_rows > 0) {
    echo json_encode(["result" => "duplicate", "message" => "Barcode already exists."]);
    $conn->close();
    exit;
}
$check->close();

// --- Validate item exists in out_items ---
$validate = $conn->query("SELECT id FROM out_items WHERE inv='$inv_esc' LIMIT 1");
if (!$validate || $validate->num_rows === 0) {
    echo json_encode(["result" => "error", "message" => "Cannot return item. Barcode not found in out_items (item was never shipped)."]);
    $conn->close();
    exit;
}
$validate->close();

// Handle inv_photo upload
$inv_photo = '';
$full_photo = '';
try {
    $inv_photo = isset($_FILES['inv_photo'])
        ? scanner_storage_store_uploaded_file($_FILES['inv_photo'], 'inv_', $date_time_esc)
        : '';

    // Handle full_photo upload
    $full_photo = isset($_FILES['full_photo'])
        ? scanner_storage_store_uploaded_file($_FILES['full_photo'], 'full_', $date_time_esc)
        : '';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["result" => "error", "message" => $e->getMessage()]);
    $conn->close();
    exit;
}

$pdo = get_db_connection();

try {
    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare("
        INSERT INTO return_items (inv, delivery_by, reason, username, inv_photo, full_photo, date_time)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([$inv, $delivery_by, $reason, $username, $inv_photo, $full_photo, $date_time]);

    $userIdStmt = $pdo->prepare("SELECT id FROM users WHERE name = ? LIMIT 1");
    $userIdStmt->execute([$username]);
    $resolvedUserId = $userIdStmt->fetchColumn();
    $userId = $resolvedUserId !== false ? (int)$resolvedUserId : null;

    $orderStmt = $pdo->prepare("SELECT id FROM orders WHERE order_code = ? LIMIT 1");
    $orderStmt->execute([$inv]);
    $orderId = $orderStmt->fetchColumn();

    if ($orderId !== false) {
        applyReturnedOrderInventory($pdo, (int)$orderId, $userId);

        $updateStmt = $pdo->prepare("
            UPDATE orders
            SET is_returned = 1,
                return_note = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$reason, $userId, (int)$orderId]);
    }

    $pdo->commit();
    $det = user_activity_details_compact([
        'inv' => $_POST['inv'] ?? '',
        'delivery_by' => $_POST['delivery_by'] ?? '',
        'reason' => $_POST['reason'] ?? '',
        'username' => $_POST['username'] ?? '',
        'date_time' => $_POST['date_time'] ?? '',
    ]);
    user_activity_log_scanner_mutation(function_exists('current_user') ? current_user() : null, 'create', __FILE__, $det !== '' ? $det : 'inv ' . (string)$inv);
    echo json_encode(["result" => "success"]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (strpos($e->getMessage(), "Duplicate entry") !== false) {
        echo json_encode(["result" => "duplicate", "message" => "Barcode already exists."]);
    } else {
        http_response_code(500);
        echo json_encode(["result" => "error", "message" => $e->getMessage()]);
    }
}

$conn->close();
?>
