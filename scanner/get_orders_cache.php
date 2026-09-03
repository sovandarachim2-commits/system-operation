<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

try {
    $pdo = get_db_connection();

    // Online orders
    $stmt = $pdo->query("
        SELECT
            o.order_code AS inv,
            o.phone,
            o.status,
            CAST(o.total_amount AS DECIMAL(15,2)) AS amount,
            COALESCE(SUM(CASE WHEN COALESCE(oi.is_lucky_box, 0) = 1 THEN oi.quantity ELSE 0 END), 0) AS lucky_box_qty,
            GROUP_CONCAT(
                DISTINCT CASE
                    WHEN COALESCE(oi.is_lucky_box, 0) = 1 AND TRIM(COALESCE(p.name, '')) != ''
                    THEN p.name
                END
                ORDER BY p.name SEPARATOR '|||'
            ) AS lucky_names_raw
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON p.id = oi.product_id
        GROUP BY o.id, o.order_code, o.phone, o.status, o.total_amount
        ORDER BY o.id DESC
        LIMIT 1000
    ");
    $onlineRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Offline orders
    $offlineRows = [];
    try {
        $offlineStmt = $pdo->query("
            SELECT order_code AS inv, phone, status, CAST(total_amount AS DECIMAL(15,2)) AS amount
            FROM offline_sale_orders
            ORDER BY id DESC
            LIMIT 500
        ");
        $offlineRows = $offlineStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table may not exist yet
    }

    $result = [];

    foreach ($onlineRows as $row) {
        $luckyNames = [];
        if (!empty($row['lucky_names_raw'])) {
            $luckyNames = array_values(array_filter(explode('|||', $row['lucky_names_raw'])));
        }
        $result[] = [
            'inv'             => $row['inv'],
            'phone'           => $row['phone'] ?? '',
            'status'          => $row['status'] ?? '',
            'amount'          => (float)$row['amount'],
            'lucky_box_qty'   => (int)$row['lucky_box_qty'],
            'lucky_set_names' => $luckyNames,
        ];
    }

    foreach ($offlineRows as $row) {
        $result[] = [
            'inv'             => $row['inv'],
            'phone'           => $row['phone'] ?? '',
            'status'          => $row['status'] ?? '',
            'amount'          => (float)$row['amount'],
            'lucky_box_qty'   => 0,
            'lucky_set_names' => [],
        ];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
