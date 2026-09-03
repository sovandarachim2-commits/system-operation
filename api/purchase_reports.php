<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_common.php';

$pdo = get_db_connection();
require_role_or_permission(['admin'], 'sr_purchase_reports.view', 'purchase_reports.view');

$from = purchase_api_date($_GET['from'] ?? null, date('Y-m-01'));
$to = purchase_api_date($_GET['to'] ?? null, date('Y-m-d'));
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$status = purchase_api_str($_GET['status'] ?? '');

function purchase_reports_filters(string &$sql, array &$params, string $from, string $to, int $vendorId, string $status, string $alias = 'po'): void
{
    $sql .= " AND {$alias}.order_date >= ? AND {$alias}.order_date <= ?";
    $params[] = $from;
    $params[] = $to;
    if ($vendorId > 0) {
        $sql .= " AND {$alias}.vendor_id = ?";
        $params[] = $vendorId;
    }
    if ($status !== '') {
        $sql .= " AND {$alias}.status = ?";
        $params[] = $status;
    }
}

try {
    $orderSql = '
        SELECT
            po.*,
            pv.name AS vendor_name,
            u.name AS created_by_name,
            COUNT(poi.id) AS item_count,
            COALESCE(SUM(poi.quantity_ordered), 0) AS total_quantity,
            COALESCE(SUM(poi.quantity_received), 0) AS total_received
        FROM purchase_orders po
        LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
        LEFT JOIN users u ON u.id = po.created_by
        LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
        WHERE 1=1
    ';
    $orderParams = [];
    purchase_reports_filters($orderSql, $orderParams, $from, $to, $vendorId, $status);
    $orderSql .= ' GROUP BY po.id ORDER BY po.order_date DESC, po.id DESC';
    $orderStmt = $pdo->prepare($orderSql);
    $orderStmt->execute($orderParams);
    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'orders' => count($orders),
        'total_amount' => 0.0,
        'total_paid' => 0.0,
        'total_ordered_qty' => 0.0,
        'total_received_qty' => 0.0,
        'by_status' => [],
    ];
    foreach ($orders as $order) {
        $summary['total_amount'] += (float)$order['total_amount'];
        $summary['total_paid'] += (float)$order['total_paid'];
        $summary['total_ordered_qty'] += (float)$order['total_quantity'];
        $summary['total_received_qty'] += (float)$order['total_received'];
        $key = (string)$order['status'];
        $summary['by_status'][$key] = ($summary['by_status'][$key] ?? 0) + 1;
    }
    $summary['balance_amount'] = max(0, $summary['total_amount'] - $summary['total_paid']);

    $vendorSql = '
        SELECT
            pv.id,
            pv.name,
            COUNT(po.id) AS order_count,
            COALESCE(SUM(po.total_amount), 0) AS total_spent,
            COALESCE(SUM(po.total_paid), 0) AS total_paid,
            COALESCE(SUM(item_agg.total_items), 0) AS total_items,
            COALESCE(SUM(item_agg.total_received), 0) AS total_received
        FROM purchase_vendors pv
        INNER JOIN purchase_orders po ON pv.id = po.vendor_id
        LEFT JOIN (
            SELECT purchase_order_id, SUM(quantity_ordered) AS total_items, SUM(quantity_received) AS total_received
            FROM purchase_order_items
            GROUP BY purchase_order_id
        ) item_agg ON item_agg.purchase_order_id = po.id
        WHERE 1=1
    ';
    $vendorParams = [];
    purchase_reports_filters($vendorSql, $vendorParams, $from, $to, $vendorId, $status);
    $vendorSql .= ' GROUP BY pv.id, pv.name ORDER BY total_spent DESC';
    $vendorStmt = $pdo->prepare($vendorSql);
    $vendorStmt->execute($vendorParams);
    $vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

    $productSql = '
        SELECT
            poi.item_name,
            poi.sku,
            SUM(poi.quantity_ordered) AS total_ordered,
            SUM(poi.quantity_received) AS total_received,
            SUM(poi.line_total) AS total_cost
        FROM purchase_order_items poi
        JOIN purchase_orders po ON po.id = poi.purchase_order_id
        WHERE 1=1
    ';
    $productParams = [];
    purchase_reports_filters($productSql, $productParams, $from, $to, $vendorId, $status);
    $productSql .= ' GROUP BY poi.item_name, poi.sku ORDER BY total_ordered DESC LIMIT 100';
    $productStmt = $pdo->prepare($productSql);
    $productStmt->execute($productParams);
    $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

    $supplierOptions = $pdo->query('SELECT id AS value, name AS label FROM purchase_vendors ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'success' => true,
        'filters' => ['from' => $from, 'to' => $to, 'vendor_id' => $vendorId, 'status' => $status],
        'summary' => $summary,
        'orders' => $orders,
        'vendors' => $vendors,
        'products' => $products,
        'supplier_options' => $supplierOptions,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
