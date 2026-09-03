<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'sr_sales_dashboard.view', 'sr_daily_offline_sale.view', 'sr_offline_buy_report.view', 'sr_income_statement.view', 'offline_daily_report.view');
require_once __DIR__ . '/../../admin/offline_lib.php';

function report_date(string $key, ?string $fallback = null): string
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    return $fallback ?: date('Y-m-d');
}

function report_int(string $key): ?int
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '') {
        return null;
    }
    $intValue = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $intValue === false ? null : (int)$intValue;
}

function report_int_list(string $key): array
{
    $raw = $_GET[$key] ?? '';
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/\s*,\s*/', trim((string)$raw)) ?: [];
    }
    $values = [];
    foreach ($parts as $part) {
        $intValue = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($intValue !== false) {
            $values[(int)$intValue] = (int)$intValue;
        }
    }
    return array_values($values);
}

try {
    $pdo = get_db_connection();
    offline_ensure_schema($pdo);

    $from = report_date('from');
    $to = report_date('to', $from);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $teamIds = report_int_list('team_ids');
    $teamId = $teamIds === [] ? report_int('team_id') : null;
    $locationId = report_int('location_id');
    $brandId = report_int('brand_id');

    $where = [
        'DATE(o.sale_date) >= ?',
        'DATE(o.sale_date) <= ?',
        "LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled')",
    ];
    $params = [$from, $to];

    if ($teamId !== null) {
        $where[] = 'o.team_id = ?';
        $params[] = $teamId;
    } elseif ($teamIds !== []) {
        $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
        $where[] = "o.team_id IN ($teamPlaceholders)";
        array_push($params, ...$teamIds);
    }
    if ($locationId !== null) {
        $where[] = 'o.location_id = ?';
        $params[] = $locationId;
    }
    if ($brandId !== null) {
        $where[] = "(
            EXISTS (
                SELECT 1
                FROM offline_sale_order_items oi_filter
                INNER JOIN products p_filter ON p_filter.id = oi_filter.product_id
                WHERE oi_filter.order_id = o.id AND p_filter.brand_id = ?
            )
            OR EXISTS (
                SELECT 1
                FROM offline_sale_purchase_items pi_filter
                INNER JOIN products pp_filter ON pp_filter.id = pi_filter.product_id
                WHERE pi_filter.order_id = o.id AND pp_filter.brand_id = ?
            )
        )";
        $params[] = $brandId;
        $params[] = $brandId;
    }

    $whereSql = implode(' AND ', $where);
    $itemWhereSql = $whereSql;
    $itemParams = $params;
    if ($brandId !== null) {
        $itemWhereSql .= ' AND p.brand_id = ?';
        $itemParams[] = $brandId;
    }

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) AS order_count,
            COALESCE(SUM(o.subtotal), 0) AS total_sales,
            COALESCE(SUM(o.purchase_total), 0) AS total_purchase,
            COALESCE(SUM(o.discount), 0) AS total_discount,
            COALESCE(SUM(GREATEST(COALESCE(o.purchase_total, 0) + COALESCE(o.discount, 0) - COALESCE(o.subtotal, 0), 0)), 0) AS shop_pay_back_total,
            COALESCE(SUM(o.total_amount), 0) AS net_total,
            COALESCE(SUM(o.received_amount), 0) AS paid_total,
            COALESCE(SUM(GREATEST(o.total_amount - o.received_amount, 0)), 0) AS unpaid_total
        FROM offline_sale_orders o
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rowsStmt = $pdo->prepare("
        SELECT
            oi.product_id,
            COALESCE(p.name, oi.product_name) AS product_name,
            COALESCE(b.name, '-') AS brand_name,
            COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            SUM(oi.quantity) AS qty_sold,
            SUM(oi.line_total) AS sales_amount,
            SUM(
                CASE
                    WHEN COALESCE(o.subtotal, 0) > 0
                    THEN COALESCE(o.discount, 0) * (oi.line_total / o.subtotal)
                    ELSE 0
                END
            ) AS total_discount,
            COUNT(DISTINCT o.id) AS order_count,
            MAX(DATE(o.sale_date)) AS last_sold,
            MAX(o.updated_at) AS last_sold_at
        FROM offline_sale_order_items oi
        INNER JOIN offline_sale_orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN offline_teams t ON t.id = o.team_id
        WHERE {$itemWhereSql}
        GROUP BY
            oi.product_id,
            COALESCE(p.name, oi.product_name),
            COALESCE(b.name, '-'),
            COALESCE(NULLIF(b.color, ''), '#6b7280'),
            COALESCE(t.id, 0),
            COALESCE(t.name, 'Unassigned')
        ORDER BY product_name ASC, team_name ASC
    ");
    $rowsStmt->execute($itemParams);
    $salesRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $purchaseRowsStmt = $pdo->prepare("
        SELECT
            pi.product_id,
            COALESCE(p.name, pi.product_name) AS product_name,
            COALESCE(b.name, '-') AS brand_name,
            COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            SUM(pi.quantity) AS qty_purchased,
            SUM(pi.line_total) AS purchase_amount,
            COUNT(DISTINCT o.id) AS purchase_order_count,
            MAX(DATE(o.sale_date)) AS last_purchased,
            MAX(o.updated_at) AS last_purchased_at
        FROM offline_sale_purchase_items pi
        INNER JOIN offline_sale_orders o ON o.id = pi.order_id
        LEFT JOIN products p ON p.id = pi.product_id
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN offline_teams t ON t.id = o.team_id
        WHERE {$itemWhereSql}
        GROUP BY
            pi.product_id,
            COALESCE(p.name, pi.product_name),
            COALESCE(b.name, '-'),
            COALESCE(NULLIF(b.color, ''), '#6b7280'),
            COALESCE(t.id, 0),
            COALESCE(t.name, 'Unassigned')
    ");
    $purchaseRowsStmt->execute($itemParams);
    $purchaseRows = $purchaseRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $rowsByKey = [];
    foreach ($salesRows as $row) {
        $key = (int)($row['product_id'] ?? 0) . '|' . (int)($row['team_id'] ?? 0);
        $row['qty_purchased'] = 0.0;
        $row['purchase_amount'] = 0.0;
        $row['purchase_order_count'] = 0;
        $row['last_purchased'] = null;
        $row['last_purchased_at'] = null;
        $rowsByKey[$key] = $row;
    }
    foreach ($purchaseRows as $row) {
        $key = (int)($row['product_id'] ?? 0) . '|' . (int)($row['team_id'] ?? 0);
        if (!isset($rowsByKey[$key])) {
            $rowsByKey[$key] = array_merge($row, [
                'qty_sold' => 0.0,
                'sales_amount' => 0.0,
                'total_discount' => 0.0,
                'order_count' => 0,
                'last_sold' => null,
                'last_sold_at' => null,
            ]);
        } else {
            $rowsByKey[$key]['qty_purchased'] = (float)($row['qty_purchased'] ?? 0);
            $rowsByKey[$key]['purchase_amount'] = (float)($row['purchase_amount'] ?? 0);
            $rowsByKey[$key]['purchase_order_count'] = (int)($row['purchase_order_count'] ?? 0);
            $rowsByKey[$key]['last_purchased'] = $row['last_purchased'] ?? null;
            $rowsByKey[$key]['last_purchased_at'] = $row['last_purchased_at'] ?? null;
        }
    }
    $rows = array_values($rowsByKey);
    foreach ($rows as &$row) {
        $qtySold = (float)($row['qty_sold'] ?? 0);
        $qtyPurchased = (float)($row['qty_purchased'] ?? 0);
        $salesAmount = (float)($row['sales_amount'] ?? 0);
        $purchaseAmount = (float)($row['purchase_amount'] ?? 0);
        $discount = (float)($row['total_discount'] ?? 0);
        $netAfterBuyBack = $salesAmount - $purchaseAmount - $discount;
        $row['net_qty'] = $qtySold - $qtyPurchased;
        $row['net_sales'] = $salesAmount - $purchaseAmount;
        $row['shop_pay_back'] = max(0, -$netAfterBuyBack);
        $row['final_amount'] = max(0, $netAfterBuyBack);
        $row['last_update'] = $row['last_sold_at'] ?: ($row['last_purchased_at'] ?? null);
    }
    unset($row);
    usort($rows, static function (array $a, array $b): int {
        $nameCompare = strcasecmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
        return $nameCompare !== 0 ? $nameCompare : strcasecmp((string)($a['team_name'] ?? ''), (string)($b['team_name'] ?? ''));
    });

    $productRowsStmt = $pdo->prepare("
        SELECT
            oi.product_id,
            COALESCE(p.name, oi.product_name) AS product_name,
            COALESCE(b.name, '-') AS brand_name,
            COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
            SUM(oi.quantity) AS qty_sold,
            COUNT(DISTINCT o.id) AS order_count,
            SUM(oi.line_total) AS sales_amount,
            SUM(
                CASE
                    WHEN COALESCE(o.subtotal, 0) > 0
                    THEN COALESCE(o.discount, 0) * (oi.line_total / o.subtotal)
                    ELSE 0
                END
            ) AS total_discount,
            MAX(DATE(o.sale_date)) AS last_sold,
            MAX(o.updated_at) AS last_sold_at
        FROM offline_sale_order_items oi
        INNER JOIN offline_sale_orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE {$itemWhereSql}
        GROUP BY
            oi.product_id,
            COALESCE(p.name, oi.product_name),
            COALESCE(b.name, '-'),
            COALESCE(NULLIF(b.color, ''), '#6b7280')
        ORDER BY sales_amount DESC, product_name ASC
    ");
    $productRowsStmt->execute($itemParams);
    $productSalesRows = $productRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $purchaseProductRowsStmt = $pdo->prepare("
        SELECT
            pi.product_id,
            COALESCE(p.name, pi.product_name) AS product_name,
            COALESCE(b.name, '-') AS brand_name,
            COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
            SUM(pi.quantity) AS qty_purchased,
            SUM(pi.line_total) AS purchase_amount,
            COUNT(DISTINCT o.id) AS purchase_order_count,
            MAX(DATE(o.sale_date)) AS last_purchased,
            MAX(o.updated_at) AS last_purchased_at
        FROM offline_sale_purchase_items pi
        INNER JOIN offline_sale_orders o ON o.id = pi.order_id
        LEFT JOIN products p ON p.id = pi.product_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE {$itemWhereSql}
        GROUP BY
            pi.product_id,
            COALESCE(p.name, pi.product_name),
            COALESCE(b.name, '-'),
            COALESCE(NULLIF(b.color, ''), '#6b7280')
        ORDER BY purchase_amount DESC, product_name ASC
    ");
    $purchaseProductRowsStmt->execute($itemParams);
    $productPurchaseRows = $purchaseProductRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $productRowsById = [];
    foreach ($productSalesRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $row['qty_purchased'] = 0.0;
        $row['purchase_amount'] = 0.0;
        $row['purchase_order_count'] = 0;
        $row['last_purchased'] = null;
        $row['last_purchased_at'] = null;
        $productRowsById[$pid] = $row;
    }
    foreach ($productPurchaseRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if (!isset($productRowsById[$pid])) {
            $productRowsById[$pid] = array_merge($row, [
                'qty_sold' => 0.0,
                'order_count' => 0,
                'sales_amount' => 0.0,
                'total_discount' => 0.0,
                'last_sold' => null,
                'last_sold_at' => null,
            ]);
        } else {
            $productRowsById[$pid]['qty_purchased'] = (float)($row['qty_purchased'] ?? 0);
            $productRowsById[$pid]['purchase_amount'] = (float)($row['purchase_amount'] ?? 0);
            $productRowsById[$pid]['purchase_order_count'] = (int)($row['purchase_order_count'] ?? 0);
            $productRowsById[$pid]['last_purchased'] = $row['last_purchased'] ?? null;
            $productRowsById[$pid]['last_purchased_at'] = $row['last_purchased_at'] ?? null;
        }
    }
    $productRows = array_values($productRowsById);
    foreach ($productRows as &$row) {
        $qtySold = (float)($row['qty_sold'] ?? 0);
        $qtyPurchased = (float)($row['qty_purchased'] ?? 0);
        $salesAmount = (float)($row['sales_amount'] ?? 0);
        $purchaseAmount = (float)($row['purchase_amount'] ?? 0);
        $discount = (float)($row['total_discount'] ?? 0);
        $netAfterBuyBack = $salesAmount - $purchaseAmount - $discount;
        $row['net_qty'] = $qtySold - $qtyPurchased;
        $row['net_sales'] = $salesAmount - $purchaseAmount;
        $row['shop_pay_back'] = max(0, -$netAfterBuyBack);
        $row['final_amount'] = max(0, $netAfterBuyBack);
        $row['last_update'] = $row['last_sold_at'] ?: ($row['last_purchased_at'] ?? null);
    }
    unset($row);
    usort($productRows, static function (array $a, array $b): int {
        $netA = (float)($a['final_amount'] ?? 0);
        $netB = (float)($b['final_amount'] ?? 0);
        if (abs($netA - $netB) > 0.009) {
            return $netA < $netB ? 1 : -1;
        }
        return strcasecmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
    });

    $orderRowsStmt = $pdo->prepare("
        SELECT
            o.id,
            o.order_code,
            o.sale_date,
            COALESCE(o.customer_name, '') AS customer_name,
            COALESCE(o.phone, '') AS phone,
            COALESCE(o.customer_location, '') AS customer_location,
            o.status,
            o.subtotal,
            o.discount,
            o.purchase_total,
            GREATEST(COALESCE(o.purchase_total, 0) + COALESCE(o.discount, 0) - COALESCE(o.subtotal, 0), 0) AS shop_pay_back,
            o.total_amount,
            o.received_amount,
            o.payment_date,
            COALESCE(o.payment_method, '') AS payment_method,
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            SUM(oi.quantity) AS qty_sold,
            GROUP_CONCAT(
                CONCAT('SALE: ', oi.product_name, ' x ', FORMAT(oi.quantity, 0), ' - $', FORMAT(oi.line_total, 2))
                ORDER BY oi.id
                SEPARATOR '\n'
            ) AS product_lines
        FROM offline_sale_order_items oi
        INNER JOIN offline_sale_orders o ON o.id = oi.order_id
        LEFT JOIN offline_teams t ON t.id = o.team_id
        WHERE {$whereSql}
        GROUP BY
            o.id,
            o.order_code,
            o.sale_date,
            o.customer_name,
            o.phone,
            o.customer_location,
            o.status,
            o.subtotal,
            o.discount,
            o.purchase_total,
            GREATEST(COALESCE(o.purchase_total, 0) + COALESCE(o.discount, 0) - COALESCE(o.subtotal, 0), 0),
            o.total_amount,
            o.received_amount,
            o.payment_date,
            o.payment_method,
            COALESCE(t.id, 0),
            COALESCE(t.name, 'Unassigned')
        ORDER BY o.sale_date DESC, o.id DESC
    ");
    $orderRowsStmt->execute($params);
    $orderRows = $orderRowsStmt->fetchAll(PDO::FETCH_ASSOC);
    $orderPaymentsByOrder = $orderRows
        ? offline_payments_for_orders($pdo, array_map(static fn(array $row): int => (int)$row['id'], $orderRows))
        : [];

    if ($orderRows) {
        $orderIds = array_map(static fn(array $row): int => (int)$row['id'], $orderRows);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $orderPurchaseStmt = $pdo->prepare("
            SELECT order_id, product_name, quantity, line_total
            FROM offline_sale_purchase_items
            WHERE order_id IN ($placeholders)
            ORDER BY order_id, id
        ");
        $orderPurchaseStmt->execute($orderIds);
        $orderPurchaseItemsByOrder = [];
        foreach ($orderPurchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $purchaseRow) {
            $orderPurchaseItemsByOrder[(int)$purchaseRow['order_id']][] = $purchaseRow;
        }

        $deliveryByMap = [];
        $orderCodes = [];
        foreach ($orderRows as $orderRow) {
            $code = trim((string)($orderRow['order_code'] ?? ''));
            if ($code !== '') {
                $orderCodes[$code] = $code;
            }
        }
        $orderCodes = array_values($orderCodes);
        if ($orderCodes) {
            try {
                $codePlaceholders = implode(',', array_fill(0, count($orderCodes), '?'));
                $deliveryStmt = $pdo->prepare("
                    SELECT oi.inv, oi.delivery_by
                    FROM out_items oi
                    INNER JOIN (
                        SELECT inv, MAX(id) AS mid
                        FROM out_items
                        WHERE inv IN ($codePlaceholders)
                          AND delivery_by IS NOT NULL
                          AND TRIM(delivery_by) <> ''
                        GROUP BY inv
                    ) latest ON latest.mid = oi.id
                ");
                $deliveryStmt->execute($orderCodes);
                foreach ($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $deliveryRow) {
                    $inv = trim((string)($deliveryRow['inv'] ?? ''));
                    $name = trim((string)($deliveryRow['delivery_by'] ?? ''));
                    if ($inv !== '' && $name !== '') {
                        $deliveryByMap[$inv] = $name;
                    }
                }
            } catch (Throwable $e) {
                error_log('offline_daily_sales delivery_by lookup: ' . $e->getMessage());
            }
        }

        foreach ($orderRows as &$orderRow) {
            $orderId = (int)$orderRow['id'];
            $purchaseLines = [];
            foreach ($orderPurchaseItemsByOrder[$orderId] ?? [] as $purchaseRow) {
                $purchaseLines[] = 'PURCHASE: ' . (string)$purchaseRow['product_name']
                    . ' x ' . rtrim(rtrim(number_format((float)$purchaseRow['quantity'], 2, '.', ''), '0'), '.')
                    . ' -$' . number_format((float)$purchaseRow['line_total'], 2);
            }
            if ($purchaseLines) {
                $existingLines = trim((string)($orderRow['product_lines'] ?? ''));
                $orderRow['product_lines'] = $existingLines !== ''
                    ? $existingLines . "\n" . implode("\n", $purchaseLines)
                    : implode("\n", $purchaseLines);
            }
            $orderRow['delivery_by'] = $deliveryByMap[trim((string)($orderRow['order_code'] ?? ''))] ?? '';
            $payments = $orderPaymentsByOrder[$orderId] ?? [];
            $paid = offline_order_paid_from_payments($payments, $orderRow);
            $orderRow['paid_amount'] = $paid;
            $orderRow['balance_amount'] = max(0, (float)($orderRow['total_amount'] ?? 0) - $paid);
            $orderRow['display_status'] = offline_order_display_status($orderRow, $payments);
            $orderRow['payments'] = array_map(static function (array $payment): array {
                return [
                    'id' => (int)($payment['id'] ?? 0),
                    'payment_date' => (string)($payment['payment_date'] ?? ''),
                    'amount' => (float)($payment['amount'] ?? 0),
                    'payment_method' => (string)($payment['payment_method'] ?? ''),
                    'paid_note' => (string)($payment['paid_note'] ?? ''),
                    'created_by_name' => (string)($payment['created_by_name'] ?? ''),
                ];
            }, $payments);
        }
        unset($orderRow);
    }

    $teams = $pdo->query("SELECT id AS value, name AS label FROM offline_teams WHERE is_active = 1 ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $locations = $pdo->query("
        SELECT id AS value, COALESCE(location_name, location_code) AS label
        FROM storage_locations
        WHERE is_active = 1
        ORDER BY is_offline_location DESC, location_code, location_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $brands = $pdo->query("
        SELECT id AS value, name AS label, color AS brand_color
        FROM brands
        WHERE active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $deliveryByOptions = [];
    try {
        $deliveryByOptions = $pdo->query("
            SELECT name AS value, name AS label
            FROM scanner_out_items_delivery_by
            WHERE is_active = 1
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $deliveryByOptions = [];
    }

    $summary['gross_profit'] = (float)($summary['total_sales'] ?? 0) - (float)($summary['total_purchase'] ?? 0);

    api_json([
        'success' => true,
        'filters' => [
            'from' => $from,
            'to' => $to,
            'team_id' => $teamId,
            'team_ids' => $teamIds,
            'location_id' => $locationId,
            'brand_id' => $brandId,
        ],
        'summary' => $summary,
        'totals' => $summary,
        'rows' => $rows,
        'product_rows' => $productRows,
        'order_rows' => $orderRows,
        'options' => [
            'teams' => $teams,
            'locations' => $locations,
            'brands' => $brands,
            'delivery_by' => $deliveryByOptions,
        ],
    ]);
} catch (Throwable $e) {
    error_log('offline_daily_sales API error: ' . $e->getMessage());
    api_error('Unable to load offline daily sales report.', 500);
}




