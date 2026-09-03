<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role_or_permission(['admin'], 'orders.view');
require_once __DIR__ . '/../config.php';

$pdo = get_db_connection();

// Add payment_date column if missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_date', $cols)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_date DATE NULL AFTER payment_method");
    }
} catch (Throwable $e) {}

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
            throw new Exception("Insufficient inventory to reverse return for {$productName}");
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

function applyReturnedOrderInventory(PDO $pdo, $orderId, $userId, $isReversal = false) {
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

    if (!$isReversal && (int)$orderRow['is_returned'] === 1) {
        throw new Exception('Order is already marked as returned');
    }

    if ($isReversal && (int)$orderRow['is_returned'] !== 1) {
        throw new Exception('Order is not marked as returned');
    }

    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.quantity AS order_quantity, p.name AS product_name, COALESCE(p.product_type, 'normal') AS product_type
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $multiplier = $isReversal ? -1 : 1;

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
                $componentQuantity = (float)$component['quantity'] * $orderedQuantity * $multiplier;
                upsertInventoryQuantity($pdo, $component['product_id'], $component['product_name'], $componentQuantity, $locationId, $userId);

                $logStmt = $pdo->prepare("
                    INSERT INTO stock_operations
                    (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
                    VALUES (?, ?, ?, 'order_return', ?, ?, ?)
                ");
                $logStmt->execute([
                    $locationId,
                    $isReversal ? 'return_component_out' : 'return_component_in',
                    abs($componentQuantity),
                    $orderId,
                    ($isReversal ? 'Reversed returned set component for order ' : 'Returned set component restored for order ') . $orderRow['order_code'] . ' - ' . $component['product_name'],
                    $userId
                ]);
            }

            continue;
        }

        $restoreQuantity = $orderedQuantity * $multiplier;
        upsertInventoryQuantity($pdo, $item['product_id'], $item['product_name'], $restoreQuantity, $locationId, $userId);

        $logStmt = $pdo->prepare("
            INSERT INTO stock_operations
            (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
            VALUES (?, ?, ?, 'order_return', ?, ?, ?)
        ");
        $logStmt->execute([
            $locationId,
            $isReversal ? 'return_reversal_out' : 'return_in',
            abs($restoreQuantity),
            $orderId,
            ($isReversal ? 'Reversed returned product for order ' : 'Returned product restored for order ') . $orderRow['order_code'] . ' - ' . $item['product_name'],
            $userId
        ]);
    }
}

// Handle form submissions
// Handle AJAX update requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['ajax'])) {
    $action = $_POST['action'] ?? '';
    $order_id = $_POST['order_id'] ?? '';
    
    header('Content-Type: application/json');
    
    if ($action && $order_id) {
        require_role_or_permission(['admin'], 'orders.update');
        $current_user = current_user();
        $user_id = $current_user['id'];
        
        try {
            $pdo->beginTransaction();
            
            switch ($action) {
                case 'mark_paid':
                    $payment_method = $_POST['payment_method'] ?? '';
                    $paid_note = $_POST['paid_note'] ?? '';
                    $payment_date = trim($_POST['payment_date'] ?? '');
                    if (empty($payment_date)) {
                        echo json_encode(['success' => false, 'message' => 'Payment date is required when marking as paid.']);
                        exit;
                    }
                    $stmt = $pdo->prepare("UPDATE orders SET is_paid = 1, status = 'paid', payment_method = ?, paid_note = ?, payment_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$payment_method, $paid_note, $payment_date, $user_id, $order_id]);
                    $message = "Order marked as paid successfully!";
                    break;
                    
                case 'mark_unpaid':
                    $stmt = $pdo->prepare("UPDATE orders SET is_paid = 0, status = 'unpaid', payment_method = NULL, paid_note = NULL, payment_date = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id, $order_id]);
                    $message = "Order marked as unpaid successfully!";
                    break;
                    
                case 'cancel_order':
                    $cancel_note = $_POST['cancel_note'] ?? '';
                    $stmt = $pdo->prepare("UPDATE orders SET is_cancelled = 1, status = 'cancelled', is_paid = 0, payment_method = NULL, paid_note = NULL, payment_date = NULL, cancel_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$cancel_note, $user_id, $order_id]);
                    $message = "Order cancelled successfully!";
                    break;
                    
                case 'uncancel_order':
                    $stmt = $pdo->prepare("UPDATE orders SET is_cancelled = 0, status = 'unpaid', cancel_note = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id, $order_id]);
                    $message = "Order cancellation removed successfully!";
                    break;
                    
                case 'return_order':
                    $return_note = $_POST['return_note'] ?? '';
                    applyReturnedOrderInventory($pdo, $order_id, $user_id, false);
                    $stmt = $pdo->prepare("UPDATE orders SET is_returned = 1, return_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$return_note, $user_id, $order_id]);
                    $message = "Order returned successfully!";
                    break;
                    
                case 'update_payment_note':
                    $payment_method = $_POST['payment_method'] ?? '';
                    $paid_note = $_POST['paid_note'] ?? '';
                    $stmt = $pdo->prepare("UPDATE orders SET payment_method = ?, paid_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$payment_method, $paid_note, $user_id, $order_id]);
                    $message = "Payment details updated successfully!";
                    break;
                    
                case 'update_cancel_note':
                    $note = $_POST['note'] ?? '';
                    $stmt = $pdo->prepare("UPDATE orders SET cancel_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$note, $user_id, $order_id]);
                    $message = "Cancellation note updated successfully!";
                    break;
                    
                case 'update_payment_note':
                    $payment_method = $_POST['payment_method'] ?? '';
                    $paid_note = $_POST['paid_note'] ?? '';
                    $stmt = $pdo->prepare("UPDATE orders SET payment_method = ?, paid_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$payment_method, $paid_note, $user_id, $order_id]);
                    $message = "Payment details updated successfully!";
                    break;
                    
                case 'update_return_note':
                    $note = $_POST['note'] ?? '';
                    $stmt = $pdo->prepare("UPDATE orders SET return_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$note, $user_id, $order_id]);
                    $message = "Return note updated successfully!";
                    break;
                    
                case 'cancel_return':
                    applyReturnedOrderInventory($pdo, $order_id, $user_id, true);
                    $stmt = $pdo->prepare("UPDATE orders SET is_returned = 0, return_note = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id, $order_id]);
                    $message = "Return cancelled successfully!";
                    break;
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => "Error: " . $e->getMessage()]);
            exit;
        }
    }
}

// Handle non-AJAX POST requests (fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $order_id = $_POST['order_id'] ?? '';
    
    if ($action && $order_id) {
        require_role_or_permission(['admin'], 'orders.update');
        $current_user = current_user();
        $user_id = $current_user['id'];
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            switch ($action) {
                case 'mark_paid':
                    $payment_method = $_POST['payment_method'] ?? '';
                    $paid_note = $_POST['paid_note'] ?? '';
                    $payment_date = trim($_POST['payment_date'] ?? '');
                    if (empty($payment_date)) {
                        throw new Exception('Payment date is required when marking as paid.');
                    }
                    $stmt = $pdo->prepare("UPDATE orders SET is_paid = 1, status = 'paid', payment_method = ?, paid_note = ?, payment_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$payment_method, $paid_note, $payment_date, $user_id, $order_id]);
                    $success_message = "Order marked as paid successfully!";
                    break;
                    
                case 'mark_unpaid':
                    $stmt = $pdo->prepare("UPDATE orders SET is_paid = 0, status = 'unpaid', payment_method = NULL, paid_note = NULL, payment_date = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id, $order_id]);
                    $success_message = "Order marked as unpaid successfully!";
                    break;
                    
                case 'cancel_order':
                    $cancel_note = $_POST['cancel_note'] ?? '';
                    $stmt = $pdo->prepare("UPDATE orders SET is_cancelled = 1, status = 'cancelled', is_paid = 0, payment_method = NULL, paid_note = NULL, payment_date = NULL, cancel_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$cancel_note, $user_id, $order_id]);
                    $success_message = "Order cancelled successfully!";
                    break;
                    
                case 'uncancel_order':
                    $stmt = $pdo->prepare("UPDATE orders SET is_cancelled = 0, status = 'unpaid', cancel_note = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id, $order_id]);
                    $success_message = "Order cancellation removed successfully!";
                    break;
                    
                case 'return_order':
                    $return_note = $_POST['return_note'] ?? '';
                    applyReturnedOrderInventory($pdo, $order_id, $user_id, false);
                    $stmt = $pdo->prepare("UPDATE orders SET is_returned = 1, return_note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$return_note, $user_id, $order_id]);
                    $success_message = "Order returned successfully!";
                    break;

                case 'cancel_return':
                    applyReturnedOrderInventory($pdo, $order_id, $user_id, true);
                    $stmt = $pdo->prepare("UPDATE orders SET is_returned = 0, return_note = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$user_id, $order_id]);
                    $success_message = "Return cancelled successfully!";
                    break;
            }
            
            $pdo->commit();
            
            // Redirect to avoid form resubmission and improve performance
            $params = $_GET;
            unset($params['action'], $params['order_id']);
            $redirect_url = 'order_management.php?' . http_build_query($params);
            if ($success_message) {
                $redirect_url .= '&success=' . urlencode($success_message);
            }
            header("Location: " . $redirect_url);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$payment_method_filter = $_GET['payment_method'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$delivery_by_filter = $_GET['delivery_by'] ?? '';
$scan_from_date = $_GET['scan_from_date'] ?? '';
$scan_to_date = $_GET['scan_to_date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1)); // Current page (minimum 1)
$per_page = 50; // Orders per page - optimized for performance

// Get success message from URL
$success_message = $_GET['success'] ?? '';

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

// Get unique delivery_by values for filter dropdown
$deliveryByOptions = [];
$stmtDelivery = $pdo->query("SELECT DISTINCT delivery_by FROM out_items WHERE delivery_by IS NOT NULL AND delivery_by != '' ORDER BY delivery_by");
if ($stmtDelivery) {
    $deliveryByOptions = $stmtDelivery->fetchAll(PDO::FETCH_COLUMN);
}

$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(o.order_code LIKE ? OR o.id LIKE ? OR u.name LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
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
        case 'printed':
            $where_conditions[] = "EXISTS (SELECT 1 FROM print_jobs pj WHERE pj.order_id = o.id)";
            break;
        case 'not_printed':
            $where_conditions[] = "NOT EXISTS (SELECT 1 FROM print_jobs pj WHERE pj.order_id = o.id)";
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

if ($delivery_by_filter) {
    if ($delivery_by_filter === 'not_delivered') {
        $where_conditions[] = "NOT EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.delivery_by IS NOT NULL AND oi.delivery_by != '')";
    } else {
        $where_conditions[] = "EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.delivery_by = ?)";
        $params[] = $delivery_by_filter;
    }
}

if ($scan_from_date) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.date_time >= ?)";
    $params[] = $scan_from_date . ' 00:00:00';
}

if ($scan_to_date) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM out_items oi WHERE oi.inv = o.order_code AND oi.date_time <= ?)";
    $params[] = $scan_to_date . ' 23:59:59';
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Calculate pagination
$offset = ($page - 1) * $per_page;
$limit_clause = "LIMIT $per_page OFFSET $offset";

// Always use pagination for performance - no more unlimited queries!

$stmt = $pdo->prepare("
    SELECT
        o.id, o.order_code, o.created_at, o.updated_at, o.customer_name, o.phone,
        o.total_amount, o.discount, o.is_paid, o.is_cancelled, o.is_returned, o.payment_method, o.paid_note,
        o.cancel_note, o.return_note, o.status, o.seller_id, o.updated_by,
        u.name as seller_name,
        updater.name as updated_by_name
    FROM orders o
    LEFT JOIN users u ON o.seller_id = u.id
    LEFT JOIN users updater ON o.updated_by = updater.id
    $where_clause
    ORDER BY o.created_at DESC
    $limit_clause
");

$stmt->execute($params);
$orders = $stmt->fetchAll();

// Enrich current-page orders with delivery and print info using small, indexed lookups
$orderIds = [];
$orderCodes = [];
foreach ($orders as $o) {
    $orderIds[] = (int)$o['id'];
    $orderCodes[] = (string)$o['order_code'];
}

$deliveryMap = [];
if (!empty($orderCodes)) {
    $placeholders = implode(',', array_fill(0, count($orderCodes), '?'));
    $stmtDeliveryBy = $pdo->prepare("SELECT inv, delivery_by FROM out_items WHERE inv IN ($placeholders)");
    $stmtDeliveryBy->execute($orderCodes);
    foreach ($stmtDeliveryBy->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deliveryMap[(string)$row['inv']] = (string)($row['delivery_by'] ?? '');
    }
}

$printMap = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtPrinted = $pdo->prepare("SELECT order_id, printed_at FROM print_jobs WHERE order_id IN ($placeholders)");
    $stmtPrinted->execute($orderIds);
    foreach ($stmtPrinted->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $printMap[(int)$row['order_id']] = $row['printed_at'];
    }
}

$productMap = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmtProducts = $pdo->prepare("
        SELECT oi.order_id, p.name AS product_name, oi.quantity
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.id ASC
    ");
    $stmtProducts->execute($orderIds);
    foreach ($stmtProducts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = (int)$row['order_id'];
        $productMap[$oid][] = [
            'name' => (string)($row['product_name'] ?? ''),
            'quantity' => (float)($row['quantity'] ?? 0),
        ];
    }
}

foreach ($orders as &$o) {
    $code = (string)$o['order_code'];
    $oid = (int)$o['id'];
    $o['delivery_by'] = $deliveryMap[$code] ?? '';
    $o['is_printed'] = array_key_exists($oid, $printMap) ? 1 : 0;
    $o['printed_at'] = $printMap[$oid] ?? null;
    $o['products'] = $productMap[$oid] ?? [];
}
unset($o);

// Get total count of orders for pagination
$count_sql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN users u ON o.seller_id = u.id LEFT JOIN users updater ON o.updated_by = updater.id $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_orders = $count_stmt->fetch()['total'];

// Calculate pagination info
$total_pages = ceil($total_orders / $per_page);
$start_item = ($page - 1) * $per_page + 1;
$end_item = min($page * $per_page, $total_orders);

$page_title = "Order Management";
$current = 'order_management.php';
$canUpdateOrders = has_permission('orders.update');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Order System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .modal-body textarea {
            min-height: 100px;
        }
        .order-row:hover {
            background-color: #f8f9fa;
            
            
            
            
            
            
        }
        .table-responsive {
            overflow: visible;
        }
        .dropdown-menu {
            max-height: 300px;
            overflow-y: auto;
        }
        .clickable-note {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .clickable-note:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .note-modal .modal-dialog {
            max-width: 600px;
        }
        .note-content {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #0d6efd;
        }
        .note-content.danger {
            border-left-color: #dc3545;
        }
        .note-content.warning {
            border-left-color: #ffc107;
        }
        .note-content.info {
            border-left-color: #0dcaf0;
        }
        </style>
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
                            <i class="bi bi-gear me-2"></i>
                            Order Management
                        </h5>
                        <div class="badge bg-white text-primary fs-6">
                            <i class="bi bi-list-ol me-1"></i>
                            <?= number_format($total_orders) ?> Orders
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Success/Error Messages (only when non-empty; $success_message is always defined from ?success=) -->
                    <?php if (isset($success_message) && $success_message !== ''): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($success_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message) && $error_message !== ''): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Clean Filter Section -->
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body">
                            <form method="GET">
                                <div class="row g-3 align-items-end">
                                    <!-- Search -->
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-search me-1"></i>Search Orders
                                        </label>
                                        <input type="text" class="form-control" name="search" placeholder="Order code, customer, seller..." value="<?= htmlspecialchars($search) ?>">
                                    </div>
                                    
                                    <!-- Status Filter -->
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-funnel me-1"></i>Status
                                        </label>
                                        <select class="form-select" name="status">
                                            <option value="">All Status</option>
                                            <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>> Paid</option>
                                            <option value="unpaid" <?= $status_filter === 'unpaid' ? 'selected' : '' ?>> Unpaid</option>
                                            <option value="printed" <?= $status_filter === 'printed' ? 'selected' : '' ?>> Printed</option>
                                            <option value="not_printed" <?= $status_filter === 'not_printed' ? 'selected' : '' ?>> Not Printed</option>
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
                                    
                                    <!-- Delivery By Filter -->
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
                                    
                                    <!-- Date Range -->
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-calendar-range me-1"></i>From
                                        </label>
                                        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-calendar-range me-1"></i>To
                                        </label>
                                        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                                    </div>
                                    
                                    <!-- Scan Date Range -->
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-qr-code-scan me-1"></i>Scan From
                                        </label>
                                        <input type="date" class="form-control" name="scan_from_date" value="<?= htmlspecialchars($scan_from_date) ?>">
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-qr-code-scan me-1"></i>Scan To
                                        </label>
                                        <input type="date" class="form-control" name="scan_to_date" value="<?= htmlspecialchars($scan_to_date) ?>">
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="col-md-2">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search me-1"></i>Search
                                            </button>
                                            <a href="order_management.php" class="btn btn-outline-secondary">
                                                <i class="bi bi-arrow-clockwise me-1"></i>Reset
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Active Filters Display -->
                                <?php if ($search || $status_filter || $payment_method_filter || $date_from || $date_to || $delivery_by_filter || $scan_from_date || $scan_to_date): ?>
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span class="text-muted fw-semibold">Active Filters:</span>
                                                <?php if ($search): ?>
                                                    <span class="badge bg-primary">
                                                        <i class="bi bi-search me-1"></i><?= htmlspecialchars($search) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['search' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($status_filter): ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-funnel me-1"></i><?= ucfirst($status_filter) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['status' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($payment_method_filter): ?>
                                                    <span class="badge bg-dark">
                                                        <i class="bi bi-credit-card me-1"></i><?= $payment_method_filter === '__empty__' ? 'No Payment Method' : htmlspecialchars($payment_method_filter) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['payment_method' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($delivery_by_filter): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="bi bi-truck me-1"></i><?= $delivery_by_filter === 'not_delivered' ? 'Not Delivered' : htmlspecialchars($delivery_by_filter) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['delivery_by' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($date_from): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-calendar me-1"></i>From: <?= htmlspecialchars($date_from) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['date_from' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($date_to): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="bi bi-calendar me-1"></i>To: <?= htmlspecialchars($date_to) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['date_to' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($scan_from_date): ?>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-qr-code-scan me-1"></i>Scan From: <?= htmlspecialchars($scan_from_date) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['scan_from_date' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($scan_to_date): ?>
                                                    <span class="badge bg-secondary">
                                                        <i class="bi bi-qr-code-scan me-1"></i>Scan To: <?= htmlspecialchars($scan_to_date) ?>
                                                        <a href="?<?= http_build_query(array_merge($_GET, ['scan_to_date' => ''])) ?>" class="text-white text-decoration-none">×</a>
                                                    </span>
                                                <?php endif; ?>
                                                <a href="order_management.php" class="btn btn-sm btn-outline-danger">Clear All</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <?php if ($canUpdateOrders): ?>
                    <div class="row mb-3" id="bulkActions" style="display: none;">
                        <div class="col-12">
                            <div class="alert alert-info d-flex align-items-center">
                                <span class="me-3">
                                    <span id="selectedCount">0</span> order(s) selected on this page
                                    <?php if ($total_pages > 1): ?>
                                        <br><small class="text-warning">
                                            <i class="bi bi-exclamation-triangle"></i> 
                                            Bulk actions only affect orders on the current page (<?= $per_page ?> per page)
                                        </small>
                                    <?php endif; ?>
                                </span>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-success btn-sm" onclick="bulkMarkPaid()">
                                        <i class="bi bi-check-circle"></i> Mark as Paid
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="bulkMarkUnpaid()">
                                        <i class="bi bi-x-circle"></i> Mark as Unpaid
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="bulkCancel()">
                                        <i class="bi bi-x-square"></i> Cancel Orders
                                    </button>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm ms-auto" onclick="clearSelection()">
                                    <i class="bi bi-x"></i> Clear Selection
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Orders Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <?php if ($canUpdateOrders): ?><th>
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                    </th><?php endif; ?>
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
                                    <th>Payment</th>
                                    <th>Note</th>
                                    <th>Updated Date</th>
                                    <th>Updated By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($orders as $order): ?>
                                    <tr class="order-row <?= ($order['is_cancelled'] || $order['is_returned']) ? 'table-danger' : '' ?>">
                                        <?php if ($canUpdateOrders): ?><td>
                                            <input type="checkbox" class="form-check-input order-checkbox" value="<?= $order['id'] ?>">
                                        </td><?php endif; ?>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($order['order_code']) ?></strong>
                                        </td>
                                        <td>
                                            <?= date('M j, Y H:i', strtotime($order['created_at'])) ?>
                                        </td>
                                        <td><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></td>
                                        <td>
                                            <?= htmlspecialchars($order['phone'] ?: 'N/A') ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['products'])): ?>
                                                <?php foreach ($order['products'] as $product): ?>
                                                    <div class="small mb-1">
                                                        <?= htmlspecialchars($product['name']) ?>
                                                        <span class="text-muted">x<?= rtrim(rtrim(number_format((float)$product['quantity'], 2, '.', ''), '0'), '.') ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">No products</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($order['seller_name'] ?: 'N/A') ?></td>
                                        <td>
                                            <?php 
                                            // Display delivery by if exists from out_items
                                            $deliveryBy = $order['delivery_by'] ?? '';
                                            if (!empty($deliveryBy)): ?>
                                                <span class="badge bg-info"><?= htmlspecialchars($deliveryBy) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not delivered</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong>$<?= number_format($order['total_amount'], 2) ?></strong>
                                            <?php if ($order['discount'] > 0): ?>
                                                <br><small class="text-muted">Discount: $<?= number_format($order['discount'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($order['is_printed']): ?>
                                                <span class="badge bg-success status-badge">Printed</span>
                                                <?php if ($order['printed_at']): ?>
                                                    <br><small class="text-muted"><?= date('M j, H:i', strtotime($order['printed_at'])) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary status-badge">Not Printed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            // Show both order status and payment status
                                            if ($order['is_cancelled']) {
                                                echo '<span class="badge bg-danger status-badge">❌ Cancelled</span>';
                                                if ($order['is_paid']) {
                                                    echo ' <span class="badge bg-success status-badge">✅ Paid</span>';
                                                } else {
                                                    echo ' <span class="badge bg-warning status-badge">⚠️ Unpaid</span>';
                                                }
                                            } elseif ($order['is_returned']) {
                                                echo '<span class="badge bg-warning status-badge">↩️ Returned</span>';
                                                if ($order['is_paid']) {
                                                    echo ' <span class="badge bg-success status-badge">✅ Paid</span>';
                                                } else {
                                                    echo ' <span class="badge bg-warning status-badge">⚠️ Unpaid</span>';
                                                }
                                            } else {
                                                if ($order['is_paid']) {
                                                    echo '<span class="badge bg-success status-badge">✅ Paid</span>';
                                                } else {
                                                    echo '<span class="badge bg-warning status-badge">⚠️ Unpaid</span>';
                                                }
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // Display the most recent note (last action taken)
                                            $note = '';
                                            $noteType = '';
                                            
                                            // Priority: Return > Cancel > Payment (most recent action)
                                            if ($order['is_returned'] && !empty($order['return_note'])) {
                                                $note = htmlspecialchars($order['return_note']);
                                                $noteType = 'return';
                                                $displayNote = strlen($note) > 30 ? substr($note, 0, 30) . '...' : $note;
                                                echo '<span class="badge bg-warning clickable-note" onclick="showFullNote(\'' . $note . '\', \'Return Note\', \'warning\', ' . $order['id'] . ', \'return\')" title="' . $note . '">' . $displayNote . '</span>';
                                            } elseif ($order['is_cancelled'] && !empty($order['cancel_note'])) {
                                                $note = htmlspecialchars($order['cancel_note']);
                                                $noteType = 'cancel';
                                                $displayNote = strlen($note) > 30 ? substr($note, 0, 30) . '...' : $note;
                                                echo '<span class="badge bg-danger clickable-note" onclick="showFullNote(\'' . $note . '\', \'Cancellation Note\', \'danger\', ' . $order['id'] . ', \'cancel\')" title="' . $note . '">' . $displayNote . '</span>';
                                            } elseif ($order['is_paid'] && (!empty($order['payment_method']) || !empty($order['paid_note']))) {
                                                // Combine payment method and paid note for display
                                                $paymentMethod = !empty($order['payment_method']) ? htmlspecialchars($order['payment_method']) : '';
                                                $paidNote = !empty($order['paid_note']) ? htmlspecialchars($order['paid_note']) : '';
                                                
                                                if (!empty($paymentMethod) && !empty($paidNote)) {
                                                    $fullNote = $paymentMethod . ': ' . $paidNote;
                                                } elseif (!empty($paymentMethod)) {
                                                    $fullNote = $paymentMethod;
                                                } elseif (!empty($paidNote)) {
                                                    $fullNote = $paidNote;
                                                } else {
                                                    $fullNote = '-';
                                                }
                                                
                                                $displayNote = strlen($fullNote) > 50 ? substr($fullNote, 0, 50) . '...' : $fullNote;
                                                echo '<span class="text-muted small clickable-note" onclick="showFullNote(\'' . $fullNote . '\', \'Payment Details\', \'info\', ' . $order['id'] . ', \'payment\')" title="' . $fullNote . '">' . $displayNote . '</span>';
                                            } else {
                                                echo '<span class="text-muted small">-</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?= date('M j, Y H:i', strtotime($order['updated_at'])) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($order['updated_by_name'] ?: 'N/A') ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <!-- View Receipt Button -->
                                                <a href="../receipt.php?id=<?= $order['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info" title="View Receipt">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                                <?php if ($canUpdateOrders): ?>
                                                <!-- Edit Order Button -->
                                                <a href="../seller/order_edit.php?id=<?= $order['id'] ?>&amp;from=order_management" class="btn btn-sm btn-outline-warning" title="Edit Order">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                
                                                <!-- Actions Dropdown -->
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="bi bi-gear"></i> Actions
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <!-- Show actions for all orders including cancelled ones -->
                                                        <?php if ($order['is_cancelled']): ?>
                                                            <li><button type="button" class="dropdown-item" onclick="markPaid(<?= $order['id'] ?>)">
                                                                <i class="bi bi-check-circle text-success"></i> Mark as Paid
                                                            </button></li>
                                                            <li><button type="button" class="dropdown-item" onclick="markUnpaid(<?= $order['id'] ?>)">
                                                                <i class="bi bi-x-circle text-warning"></i> Mark as Unpaid
                                                            </button></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><button type="button" class="dropdown-item" onclick="uncancelOrder(<?= $order['id'] ?>)">
                                                                <i class="bi bi-arrow-counterclockwise text-primary"></i> Uncancel Order
                                                            </button></li>
                                                            <?php if (!(int)$order['is_printed']): ?>
                                                                <li><button type="button" class="dropdown-item" onclick="returnOrder(<?= $order['id'] ?>)">
                                                                    <i class="bi bi-arrow-return-left text-warning"></i> Return Order
                                                                </button></li>
                                                            <?php endif; ?>
                                                        <?php elseif ($order['is_returned']): ?>
                                                            <li><button type="button" class="dropdown-item" onclick="markPaid(<?= $order['id'] ?>)">
                                                                <i class="bi bi-check-circle text-success"></i> Mark as Paid
                                                            </button></li>
                                                            <li><button type="button" class="dropdown-item" onclick="markUnpaid(<?= $order['id'] ?>)">
                                                                <i class="bi bi-x-circle text-warning"></i> Mark as Unpaid
                                                            </button></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <?php if (!(int)$order['is_printed']): ?>
                                                                <li><button type="button" class="dropdown-item" onclick="cancelOrder(<?= $order['id'] ?>)">
                                                                    <i class="bi bi-x-square text-danger"></i> Cancel Order
                                                                </button></li>
                                                            <?php endif; ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><button type="button" class="dropdown-item" onclick="unreturnOrder(<?= $order['id'] ?>)">
                                                                <i class="bi bi-arrow-counterclockwise text-info"></i> Unreturn
                                                            </button></li>
                                                        <?php else: ?>
                                                            <!-- Active orders - show all actions -->
                                                            <?php if ($order['is_paid']): ?>
                                                                <li><button type="button" class="dropdown-item" onclick="markUnpaid(<?= $order['id'] ?>)">
                                                                    <i class="bi bi-x-circle text-warning"></i> Mark as Unpaid
                                                                </button></li>
                                                            <?php else: ?>
                                                                <li><button type="button" class="dropdown-item" onclick="markPaid(<?= $order['id'] ?>)">
                                                                    <i class="bi bi-check-circle text-success"></i> Mark as Paid
                                                                </button></li>
                                                            <?php endif; ?>
                                                            
                                                            <?php if (!(int)$order['is_printed']): ?>
                                                                <li><button type="button" class="dropdown-item" onclick="cancelOrder(<?= $order['id'] ?>)">
                                                                    <i class="bi bi-x-square text-danger"></i> Cancel Order
                                                                </button></li>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ((int)$order['is_printed']): ?>
                                                                <li><button type="button" class="dropdown-item" onclick="returnOrder(<?= $order['id'] ?>)">
                                                                    <i class="bi bi-arrow-return-left text-warning"></i> Return Order
                                                                </button></li>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <nav aria-label="Order pagination">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <!-- Results info -->
                                        <div class="text-muted small">
                                            Showing <?= number_format($start_item) ?> to <?= number_format($end_item) ?> of <?= number_format($total_orders) ?> orders
                                            <?php if ($total_orders > $per_page): ?>
                                                (Page <?= $page ?> of <?= $total_pages ?>)
                                            <?php endif; ?>
                                        </div>

                                        <!-- Pagination controls -->
                                        <ul class="pagination pagination-sm mb-0">
                                            <!-- Previous button -->
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

                                            <!-- Page numbers -->
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);

                                            // Show first page if not in range
                                            if ($start_page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                                                </li>
                                                <?php if ($start_page > 2): ?>
                                                    <li class="page-item disabled">
                                                        <span class="page-link">...</span>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <!-- Page range -->
                                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                        <?= $i ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <!-- Show last page if not in range -->
                                            <?php if ($end_page < $total_pages): ?>
                                                <?php if ($end_page < $total_pages - 1): ?>
                                                    <li class="page-item disabled">
                                                        <span class="page-link">...</span>
                                                    </li>
                                                <?php endif; ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                                        <?= $total_pages ?>
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <!-- Next button -->
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

<!-- Bulk Mark Paid Modal -->
<div class="modal fade" id="bulkPaidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="bulkPaidForm">
                <input type="hidden" name="action" value="bulk_mark_paid">
                <input type="hidden" name="order_ids" id="bulk_order_ids">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Selected Orders as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You are about to mark <strong><span id="bulkOrderCount">0</span> order(s)</strong> as paid with the same payment information.
                    </div>
                    <div class="mb-3">
                        <label for="bulk_payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="bulk_payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="bulk_payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_payment_method" name="payment_method" required>
                            <option value="">Select payment method...</option>
                            <?php foreach ($paymentNoteOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option['option_text']) ?>">
                                    <?= htmlspecialchars($option['option_text']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="bulk_paid_note" class="form-label">Paid Confirmation <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="bulk_paid_note" name="paid_note" placeholder="Enter payment confirmation details for all selected orders..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark All as Paid</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Paid Modal -->
<div class="modal fade" id="paidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="paidForm">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="order_id" id="paid_order_id">
                <div class="modal-header">
                    <h5 class="modal-title">Mark Order as Paid</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="">Select payment method...</option>
                            <?php foreach ($paymentNoteOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option['option_text']) ?>">
                                    <?= htmlspecialchars($option['option_text']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="paid_note" class="form-label">Paid Confirmation <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="paid_note" name="paid_note" placeholder="Enter payment confirmation details..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Paid</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="cancelForm">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" id="cancel_order_id">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="cancel_note" class="form-label">Cancellation Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancel_note" name="cancel_note" required placeholder="Enter reason for cancellation..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Cancel Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Return Order Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="returnForm">
                <input type="hidden" name="action" value="return_order">
                <input type="hidden" name="order_id" id="return_order_id">
                <div class="modal-header">
                    <h5 class="modal-title">Return Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="return_note" class="form-label">Return Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="return_note" name="return_note" required placeholder="Enter reason for return..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Return Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Full Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog note-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="noteModalTitle">Note Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="note-content" id="noteContent">
                    <!-- Note content will be inserted here -->
                </div>
                <div id="noteEditSection" style="display: none; margin-top: 15px;">
                    <hr>
                    <div class="mb-3">
                        <label for="editPaymentMethod" class="form-label">Payment Method:</label>
                        <select class="form-select" id="editPaymentMethod">
                            <option value="">Select payment method...</option>
                            <?php foreach ($paymentNoteOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option['option_text']) ?>">
                                    <?= htmlspecialchars($option['option_text']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="editPaidNote" class="form-label">Paid Confirmation:</label>
                        <textarea class="form-control" id="editPaidNote" rows="3" placeholder="Enter paid confirmation details..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="editNoteBtn" onclick="toggleEditMode()">
                    <i class="bi bi-pencil me-1"></i>Edit Note
                </button>
                <button type="button" class="btn btn-primary" id="saveNoteBtn" style="display: none;" onclick="saveNoteUpdate()">
                    <i class="bi bi-check-lg me-1"></i>Save Update
                </button>
                <button type="button" class="btn btn-info" onclick="copyNoteToClipboard()">
                    <i class="bi bi-clipboard me-1"></i>Copy Note
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX update functions (ultra-fast)
function updateOrder(action, orderId, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('order_id', orderId);
    formData.append('ajax', 'true');
    
    // Add additional data
    Object.keys(data).forEach(key => {
        formData.append(key, data[key]);
    });
    
    fetch('order_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // Show instant success message
            showAlert(result.message, 'success');
            // Immediate refresh - no delay
            window.location.reload();
        } else {
            showAlert(result.message, 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('An error occurred while updating the order', 'danger');
    });
}

// Show ultra-fast alert message
function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.2s ease-out;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    // Auto-remove after 2 seconds (faster)
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.style.animation = 'slideOut 0.2s ease-in';
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 200);
        }
    }, 2000);
}

// Add slide animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

function markPaid(orderId) {
    document.getElementById('paid_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('paidModal')).show();
}

function markUnpaid(orderId) {
    if (confirm('Are you sure you want to mark this order as unpaid?')) {
        updateOrder('mark_unpaid', orderId);
    }
}

function cancelOrder(orderId) {
    document.getElementById('cancel_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

function returnOrder(orderId) {
    document.getElementById('return_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('returnModal')).show();
}

function uncancelOrder(orderId) {
    if (confirm('Are you sure you want to remove the cancellation status from this order? This will reactivate the order.')) {
        updateOrder('uncancel_order', orderId);
    }
}

// Global variables for note editing
let currentOrderId = null;
let currentNoteType = null;

// Show full note in modal
function showFullNote(note, title, type, orderId, noteType) {
    currentOrderId = orderId;
    currentNoteType = noteType;
    
    document.getElementById('noteModalTitle').textContent = title;
    const noteContent = document.getElementById('noteContent');
    noteContent.textContent = note;
    
    // Remove all color classes
    noteContent.classList.remove('danger', 'warning', 'info');
    // Add appropriate color class
    noteContent.classList.add(type);
    
    // Reset edit mode
    document.getElementById('noteEditSection').style.display = 'none';
    document.getElementById('editNoteBtn').style.display = 'inline-block';
    document.getElementById('saveNoteBtn').style.display = 'none';
    
    // Show modal
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}

// Toggle edit mode
function toggleEditMode() {
    const noteContent = document.getElementById('noteContent');
    const editSection = document.getElementById('noteEditSection');
    const editBtn = document.getElementById('editNoteBtn');
    const saveBtn = document.getElementById('saveNoteBtn');
    
    // Show edit section
    editSection.style.display = 'block';
    editBtn.style.display = 'none';
    saveBtn.style.display = 'inline-block';
    
    // Parse current note to extract payment method and paid note
    const currentNote = noteContent.textContent.trim();
    let paymentMethod = '';
    let paidNote = '';
    
    if (currentNote && currentNote !== '-') {
        // Check if note contains ":"
        if (currentNote.includes(':')) {
            const parts = currentNote.split(':');
            paymentMethod = parts[0] ? parts[0].trim() : '';
            paidNote = parts[1] ? parts[1].trim() : '';
        } else {
            // Old format - treat as paid note only
            paidNote = currentNote;
        }
    }
    
    // Set current values in form fields
    document.getElementById('editPaymentMethod').value = paymentMethod;
    document.getElementById('editPaidNote').value = paidNote;
    
    // Focus on payment method field
    document.getElementById('editPaymentMethod').focus();
}

// Save note update
function saveNoteUpdate() {
    const updatedPaymentMethod = document.getElementById('editPaymentMethod').value.trim();
    const updatedPaidNote = document.getElementById('editPaidNote').value.trim();
    
    if (!updatedPaymentMethod && !updatedPaidNote) {
        showAlert('Payment method or paid confirmation is required', 'danger');
        return;
    }
    
    if (!currentOrderId || !currentNoteType) {
        showAlert('Missing order information', 'danger');
        return;
    }
    
    let action = '';
    let field = '';
    switch (currentNoteType) {
        case 'cancel':
            action = 'update_cancel_note';
            field = 'cancel_note';
            break;
        case 'return':
            action = 'update_return_note';
            field = 'return_note';
            break;
        case 'payment':
            action = 'update_payment_note';
            field = 'paid_note';
            break;
    }
    
    const formData = new FormData();
    formData.append('action', action);
    formData.append('order_id', currentOrderId);
    formData.append('payment_method', updatedPaymentMethod);
    formData.append('paid_note', updatedPaidNote);
    formData.append('ajax', 'true');
    
    fetch('order_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showAlert('Payment details updated successfully', 'success');
            
            // Update the displayed note
            const noteContent = document.getElementById('noteContent');
            let newDisplayNote = '';
            if (updatedPaymentMethod && updatedPaidNote) {
                newDisplayNote = updatedPaymentMethod + ': ' + updatedPaidNote;
            } else if (updatedPaymentMethod) {
                newDisplayNote = updatedPaymentMethod;
            } else if (updatedPaidNote) {
                newDisplayNote = updatedPaidNote;
            } else {
                newDisplayNote = '-';
            }
            
            const displayNote = newDisplayNote.length > 50 ? newDisplayNote.substring(0, 50) + '...' : newDisplayNote;
            noteContent.textContent = displayNote;
            noteContent.setAttribute('title', newDisplayNote);
            
            // Exit edit mode
            document.getElementById('noteEditSection').style.display = 'none';
            document.getElementById('editNoteBtn').style.display = 'inline-block';
            document.getElementById('saveNoteBtn').style.display = 'none';
            
            // Reload page after delay to show updated data
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showAlert('Error updating payment details: ' + (result.message || 'Unknown error'), 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Error updating payment details', 'danger');
    });
}

// Copy note to clipboard
function copyNoteToClipboard() {
    const noteText = document.getElementById('noteContent').textContent;
    navigator.clipboard.writeText(noteText).then(() => {
        showAlert('Note copied to clipboard', 'success');
    }).catch(err => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = noteText;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showAlert('Note copied to clipboard', 'success');
    });
}

// Show custom note field
function showCustomNoteField() {
    document.getElementById('customNoteField').style.display = 'block';
    document.getElementById('paid_note').removeAttribute('required');
    document.getElementById('custom_paid_note').setAttribute('required', 'required');
}

// Handle modal form submissions with AJAX
document.getElementById('paidForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const orderId = document.getElementById('paid_order_id').value;
    
    const paymentDate = document.getElementById('payment_date').value;
    const paymentMethod = document.getElementById('payment_method').value;
    const paidNote = document.getElementById('paid_note').value;
    
    if (!paymentDate) {
        showAlert('Please select a payment date', 'danger');
        return;
    }
    if (!paymentMethod.trim()) {
        showAlert('Please select a payment method', 'danger');
        return;
    }
    if (!paidNote.trim()) {
        showAlert('Please enter paid confirmation details', 'danger');
        return;
    }
    
    updateOrder('mark_paid', orderId, { payment_date: paymentDate, payment_method: paymentMethod, paid_note: paidNote });
    bootstrap.Modal.getInstance(document.getElementById('paidModal')).hide();
    
    // Reset form fields
    document.getElementById('payment_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('payment_method').value = '';
    document.getElementById('paid_note').value = '';
    document.getElementById('custom_paid_note').value = '';
});

document.getElementById('cancelForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const orderId = document.getElementById('cancel_order_id').value;
    const cancelNote = document.getElementById('cancel_note').value;
    
    updateOrder('cancel_order', orderId, { cancel_note: cancelNote });
    bootstrap.Modal.getInstance(document.getElementById('cancelModal')).hide();
});

// Handle bulk paid form submission
document.getElementById('bulkPaidForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const orderIds = document.getElementById('bulk_order_ids').value;
    const paymentDate = document.getElementById('bulk_payment_date').value;
    const paymentMethod = document.getElementById('bulk_payment_method').value;
    const paidNote = document.getElementById('bulk_paid_note').value;
    
    if (!paymentDate) {
        showAlert('Please select a payment date', 'danger');
        return;
    }
    if (!paymentMethod.trim()) {
        showAlert('Please select a payment method', 'danger');
        return;
    }
    if (!paidNote.trim()) {
        showAlert('Please enter paid confirmation details', 'danger');
        return;
    }
    
    // Update all selected orders in parallel for maximum speed
    const orderIdArray = orderIds.split(',');
    let completed = 0;
    let total = orderIdArray.length;
    let hasError = false;
    
    // Disable submit button to prevent double submission
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-spinner fa-spin me-2"></i>Processing...';
    
    // Process all orders simultaneously
    const updatePromises = orderIdArray.map(orderId => {
        const formData = new FormData();
        formData.append('action', 'mark_paid');
        formData.append('order_id', orderId.trim());
        formData.append('payment_date', paymentDate);
        formData.append('payment_method', paymentMethod);
        formData.append('paid_note', paidNote);
        formData.append('ajax', '1');
        
        return fetch('order_management.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update row in table to show it's paid
                const row = document.querySelector(`.order-checkbox[value="${orderId.trim()}"]`).closest('tr');
                if (row) {
                    const paymentCell = row.querySelector('td:nth-child(10)'); // Payment column
                    if (paymentCell) {
                        paymentCell.innerHTML = '<span class="badge bg-success">Paid</span>';
                    }
                }
            } else {
                hasError = true;
                showAlert(`Error updating order ${orderId.trim()}: ${data.message}`, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            hasError = true;
            showAlert(`Error updating order ${orderId.trim()}`, 'danger');
        });
    });
    
    // Wait for all requests to complete
    Promise.all(updatePromises).then(() => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Mark All as Paid';
        
        if (!hasError) {
            // All orders processed successfully
            bootstrap.Modal.getInstance(document.getElementById('bulkPaidModal')).hide();
            clearSelection();
            
            // Reset form fields
            document.getElementById('bulk_payment_date').value = new Date().toISOString().split('T')[0];
            document.getElementById('bulk_payment_method').value = '';
            document.getElementById('bulk_paid_note').value = '';
            
            showAlert(`${total} order(s) successfully marked as paid`, 'success');
        } else {
            showAlert('Some orders failed to update. Please check the errors above.', 'warning');
        }
    });
});

document.getElementById('returnForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const orderId = document.getElementById('return_order_id').value;
    const returnNote = document.getElementById('return_note').value;
    
    updateOrder('return_order', orderId, { return_note: returnNote });
    bootstrap.Modal.getInstance(document.getElementById('returnModal')).hide();
});


// Handle search form
document.querySelector('form').addEventListener('submit', function(e) {
    // Allow main filter form to submit normally
    if (e.target.classList.contains('table-responsive')) return;
    
    // Check if this is the main filter form (not modal forms)
    if (!e.target.querySelector('input[name="search"]')) return;
    
    const formData = new FormData(e.target);
    const params = new URLSearchParams();
    
    for (let [key, value] of formData.entries()) {
        if (value) params.append(key, value);
    }
    
    window.location.href = `order_management.php?${params.toString()}`;
    e.preventDefault();
});
</script>
</body>
</html>

<?php include __DIR__ . '/../layout/footer.php'; ?>

<script>
function markPaid(orderId) {
    document.getElementById('paid_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('paidModal')).show();
}

function markUnpaid(orderId) {
    if (confirm('Are you sure you want to mark this order as unpaid?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="mark_unpaid">
            <input type="hidden" name="order_id" value="${orderId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function cancelOrder(orderId) {
    document.getElementById('cancel_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

function returnOrder(orderId) {
    document.getElementById('return_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('returnModal')).show();
}

function uncancelOrder(orderId) {
    if (confirm('Are you sure you want to remove the cancellation status from this order? This will reactivate the order.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="uncancel_order">
            <input type="hidden" name="order_id" value="${orderId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}


// Handle search form (adminRunWhenReady: required when this page is loaded via admin AJAX)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    const searchForm = document.querySelector('form');
    if (searchForm && !searchForm.classList.contains('table-responsive')) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const params = new URLSearchParams();
            
            for (let [key, value] of formData.entries()) {
                if (value) params.append(key, value);
            }
            
            window.location.href = `order_management.php?${params.toString()}`;
        });
    }
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

function unreturnOrder(orderId) {
    if (confirm('Are you sure you want to unreturn this order? This will reactivate the order and remove the return note.')) {
        updateOrder('cancel_return', orderId);
    }
}

// Checkbox functionality (adminRunWhenReady: required when loaded via admin AJAX)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    const selectAll = document.getElementById('selectAll');
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            orderCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
    }

    orderCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActions);
    });

    function updateBulkActions() {
        const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
        const count = checkedBoxes.length;
        if (selectedCount) selectedCount.textContent = count;
        if (bulkActions) bulkActions.style.display = count > 0 ? 'block' : 'none';
    }
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

function clearSelection() {
    const selectAll = document.getElementById('selectAll');
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    if (selectAll) selectAll.checked = false;
    orderCheckboxes.forEach(checkbox => { checkbox.checked = false; });
    if (bulkActions) bulkActions.style.display = 'none';
    if (selectedCount) selectedCount.textContent = '0';
}

function getSelectedOrderIds() {
    const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
    return Array.from(checkedBoxes).map(checkbox => checkbox.value);
}

function bulkMarkPaid() {
    const orderIds = getSelectedOrderIds();
    if (orderIds.length === 0) {
        showAlert('Please select at least one order', 'warning');
        return;
    }
    
    // Show bulk paid modal
    document.getElementById('bulk_order_ids').value = orderIds.join(',');
    document.getElementById('bulkOrderCount').textContent = orderIds.length;
    new bootstrap.Modal(document.getElementById('bulkPaidModal')).show();
}

function bulkMarkUnpaid() {
    const orderIds = getSelectedOrderIds();
    if (orderIds.length === 0) {
        showAlert('Please select at least one order', 'warning');
        return;
    }
    
    if (confirm(`Mark ${orderIds.length} order(s) as unpaid?`)) {
        orderIds.forEach(orderId => {
            updateOrder('mark_unpaid', orderId);
        });
        clearSelection();
    }
}

function bulkCancel() {
    const orderIds = getSelectedOrderIds();
    if (orderIds.length === 0) {
        showAlert('Please select at least one order', 'warning');
        return;
    }
    
    const reason = prompt(`Enter cancellation reason for ${orderIds.length} order(s):`);
    if (reason !== null && reason.trim() !== '') {
        orderIds.forEach(orderId => {
            updateOrder('cancel_order', orderId, { cancel_note: reason });
        });
        clearSelection();
    }
}
</script>
</body>
</html>
