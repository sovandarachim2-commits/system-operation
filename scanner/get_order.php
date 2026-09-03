<?php
// /Order/scanner/get_order.php

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');

header('Content-Type: application/json');

// Use the same database connection as the main system
require_once __DIR__ . '/../db.php';

$inv = trim($_GET['inv'] ?? '');

if ($inv === '') {
    echo json_encode(['result' => 'error', 'message' => 'No invoice']);
    exit;
}

try {
    $pdo = get_db_connection();

    $stmt = $pdo->prepare(
        "SELECT id, order_code, phone, status, total_amount
         FROM orders
         WHERE order_code = ?
         LIMIT 1"
    );

    $stmt->execute([$inv]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
        // Fall back to offline_sale_orders
        $offlineStmt = $pdo->prepare(
            "SELECT order_code, phone, status, total_amount
             FROM offline_sale_orders
             WHERE order_code = ?
             LIMIT 1"
        );
        $offlineStmt->execute([$inv]);
        $offlineRes = $offlineStmt->fetch(PDO::FETCH_ASSOC);

        if (!$offlineRes) {
            echo json_encode(['result' => 'not_found']);
            exit;
        }

        echo json_encode([
            'result' => 'success',
            'data'   => [
                'inv'             => $offlineRes['order_code'],
                'phone'           => $offlineRes['phone'] ?? '',
                'status'          => $offlineRes['status'] ?? '',
                'amount'          => (float)$offlineRes['total_amount'],
                'lucky_box_qty'   => 0,
                'lucky_set_names' => [],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orderId = (int)$res['id'];
    $luckyQty = 0;
    $luckySetNames = [];

    try {
        $luckyStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(oi.quantity), 0) AS lucky_qty
             FROM order_items oi
             WHERE oi.order_id = ?
             AND COALESCE(oi.is_lucky_box, 0) = 1"
        );
        $luckyStmt->execute([$orderId]);
        $luckyQty = (int)($luckyStmt->fetchColumn() ?: 0);

        $namesStmt = $pdo->prepare(
            "SELECT DISTINCT pr.name
             FROM order_items oi
             INNER JOIN products pr ON pr.id = oi.product_id
             WHERE oi.order_id = ?
             AND COALESCE(oi.is_lucky_box, 0) = 1
             AND TRIM(COALESCE(pr.name, '')) <> ''
             ORDER BY pr.name ASC"
        );
        $namesStmt->execute([$orderId]);
        $luckySetNames = $namesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        // e.g. is_lucky_box missing — treat as no lucky lines
        $luckyQty = 0;
        $luckySetNames = [];
    }

    echo json_encode([
        'result' => 'success',
        'data'   => [
            'inv'               => $res['order_code'],
            'phone'             => $res['phone'],
            'status'            => $res['status'],          // 'paid' or 'unpaid'
            'amount'            => (float)$res['total_amount'],
            'lucky_box_qty'      => $luckyQty,
            'lucky_set_names'   => array_values($luckySetNames),
        ],
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode(['result' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['result' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
?>