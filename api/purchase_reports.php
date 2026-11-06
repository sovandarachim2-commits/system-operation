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

    if ($orders && api_table_exists($pdo, 'purchase_order_items')) {
        $poIds = array_map(static fn(array $r): int => (int)$r['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($poIds), '?'));
        $poiStmt = $pdo->prepare("
            SELECT purchase_order_id,
                   COALESCE(NULLIF(TRIM(item_name), ''), 'Item') AS item_name,
                   COALESCE(SUM(quantity_ordered), 0) AS qty,
                   COALESCE(SUM(CASE WHEN line_total > 0 THEN line_total ELSE quantity_ordered * unit_price END), 0) AS cost
            FROM purchase_order_items
            WHERE purchase_order_id IN ($placeholders)
            GROUP BY purchase_order_id, COALESCE(NULLIF(TRIM(item_name), ''), 'Item')
        ");
        $poiStmt->execute($poIds);
        $itemsByPo = [];
        foreach ($poiStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $itemsByPo[(int)$it['purchase_order_id']][(string)$it['item_name']] = [
                'qty' => (float)$it['qty'],
                'cost' => (float)$it['cost'],
            ];
        }
        foreach ($orders as &$order) {
            $order['supplier'] = $order['vendor_name'] ?? '';
            $order['po_no'] = $order['order_number'] ?? '';
            $order['products'] = $itemsByPo[(int)$order['id']] ?? [];
        }
        unset($order);
    }

    $receiving = [];
    if (api_table_exists($pdo, 'purchase_receiving') && api_table_exists($pdo, 'purchase_receiving_items')) {
        $recSql = "
            SELECT pr.id, DATE(pr.receiving_date) AS receiving_date,
                   po.order_number AS po_no, COALESCE(pv.name, '') AS supplier,
                   COALESCE(u.name, '') AS received_by,
                   COALESCE(items.total_qty, 0) AS total_qty,
                   COALESCE(items.total_cost, 0) AS total_cost
            FROM purchase_receiving pr
            JOIN purchase_orders po ON po.id = pr.purchase_order_id
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            LEFT JOIN users u ON u.id = pr.received_by
            LEFT JOIN (
                SELECT receiving_id, SUM(quantity_received) AS total_qty,
                       SUM(total_cost) AS total_cost
                FROM purchase_receiving_items GROUP BY receiving_id
            ) items ON items.receiving_id = pr.id
            WHERE DATE(pr.receiving_date) >= ? AND DATE(pr.receiving_date) <= ?
        ";
        $recParams = [$from, $to];
        if ($vendorId > 0) {
            $recSql .= " AND po.vendor_id = ?";
            $recParams[] = $vendorId;
        }
        $recSql .= " ORDER BY pr.receiving_date DESC, pr.id DESC";
        $recStmt = $pdo->prepare($recSql);
        $recStmt->execute($recParams);
        $receiving = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($receiving) {
            $recIds = array_map(static fn(array $r): int => (int)$r['id'], $receiving);
            $recPlaceholders = implode(',', array_fill(0, count($recIds), '?'));
            $recItemStmt = $pdo->prepare("
                SELECT pri.receiving_id, COALESCE(NULLIF(TRIM(poi.item_name), ''), 'Item') AS item_name,
                       SUM(pri.quantity_received) AS qty, SUM(pri.total_cost) AS cost
                FROM purchase_receiving_items pri
                JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
                WHERE pri.receiving_id IN ($recPlaceholders)
                GROUP BY pri.receiving_id, COALESCE(NULLIF(TRIM(poi.item_name), ''), 'Item')
            ");
            $recItemStmt->execute($recIds);
            $productsByReceipt = [];
            foreach ($recItemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $productsByReceipt[(int)$it['receiving_id']][(string)$it['item_name']] = [
                    'qty' => (float)$it['qty'],
                    'cost' => (float)$it['cost'],
                ];
            }
            foreach ($receiving as &$rRow) {
                $rRow['products'] = $productsByReceipt[(int)$rRow['id']] ?? [];
            }
            unset($rRow);
        }
    }

    $returns = [];
    if (api_table_exists($pdo, 'purchase_returns') && api_table_exists($pdo, 'purchase_return_items')) {
        $retSql = "
            SELECT pr.id, DATE(pr.return_date) AS return_date, pr.return_number,
                   COALESCE(pv.name, '') AS supplier, COALESCE(u.name, '') AS returned_by,
                   COALESCE(NULLIF(TRIM(poi.item_name), ''), 'Item') AS item_name,
                   COALESCE(SUM(pri.quantity_returned), 0) AS qty,
                   COALESCE(SUM(pri.total_cost), 0) AS cost
            FROM purchase_returns pr
            LEFT JOIN purchase_vendors pv ON pv.id = pr.vendor_id
            LEFT JOIN users u ON u.id = pr.created_by
            LEFT JOIN purchase_return_items pri ON pri.purchase_return_id = pr.id
            LEFT JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            WHERE DATE(pr.return_date) >= ? AND DATE(pr.return_date) <= ?
              AND LOWER(COALESCE(pr.status, '')) NOT IN ('cancel','cancelled','canceled')
        ";
        $retParams = [$from, $to];
        if ($vendorId > 0) {
            $retSql .= " AND pr.vendor_id = ?";
            $retParams[] = $vendorId;
        }
        $retSql .= "
            GROUP BY pr.id, DATE(pr.return_date), pr.return_number, pv.name, u.name,
                     COALESCE(NULLIF(TRIM(poi.item_name), ''), 'Item')
            ORDER BY pr.return_date DESC, pr.id DESC
        ";
        $retStmt = $pdo->prepare($retSql);
        $retStmt->execute($retParams);
        $retItems = $retStmt->fetchAll(PDO::FETCH_ASSOC);

        $retMap = [];
        foreach ($retItems as $it) {
            $id = (int)$it['id'];
            if (!isset($retMap[$id])) {
                $retMap[$id] = [
                    'id' => $id,
                    'return_date' => $it['return_date'],
                    'return_number' => $it['return_number'],
                    'supplier' => $it['supplier'],
                    'returned_by' => $it['returned_by'],
                    'products' => [],
                ];
            }
            $retMap[$id]['products'][(string)$it['item_name']] = [
                'qty' => (float)$it['qty'],
                'cost' => (float)$it['cost'],
            ];
        }
        $returns = array_values($retMap);
    }

    api_json([
        'success' => true,
        'filters' => ['from' => $from, 'to' => $to, 'vendor_id' => $vendorId, 'status' => $status],
        'summary' => $summary,
        'orders' => $orders,
        'vendors' => $vendors,
        'products' => $products,
        'supplier_options' => $supplierOptions,
        'receiving' => $receiving,
        'returns' => $returns,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
