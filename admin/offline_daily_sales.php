<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'offline_daily_report.view');
require_once __DIR__ . '/offline_lib.php';

$pdo = get_db_connection();
offline_ensure_schema($pdo);

[$dateFrom, $dateTo, $quickRange] = offline_daily_sales_resolve_date_range($_GET);
$teamId = (int)($_GET['team_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);

$params = [$dateFrom, $dateTo];
$where = [
    'DATE(oso.sale_date) >= ?',
    'DATE(oso.sale_date) <= ?',
    "LOWER(COALESCE(oso.status, '')) NOT IN ('cancelled', 'canceled')",
];

if ($teamId > 0) {
    $where[] = 'oso.team_id = ?';
    $params[] = $teamId;
}
if ($productId > 0) {
    $where[] = 'osoi.product_id = ?';
    $params[] = $productId;
}

$whereSql = implode(' AND ', $where);
$orderDetailParams = [$dateFrom, $dateTo];
$orderDetailWhere = [
    'DATE(oso.sale_date) >= ?',
    'DATE(oso.sale_date) <= ?',
];
if ($teamId > 0) {
    $orderDetailWhere[] = 'oso.team_id = ?';
    $orderDetailParams[] = $teamId;
}
if ($productId > 0) {
    $orderDetailWhere[] = "(
        EXISTS (
            SELECT 1
            FROM offline_sale_order_items osi_filter
            WHERE osi_filter.order_id = oso.id AND osi_filter.product_id = ?
        )
        OR EXISTS (
            SELECT 1
            FROM offline_sale_purchase_items ospi_filter
            WHERE ospi_filter.order_id = oso.id AND ospi_filter.product_id = ?
        )
    )";
    $orderDetailParams[] = $productId;
    $orderDetailParams[] = $productId;
}
$orderDetailWhereSql = implode(' AND ', $orderDetailWhere);

$rowsStmt = $pdo->prepare("
    SELECT
        osoi.product_id,
        COALESCE(p.name, osoi.product_name) AS product_name,
        COALESCE(b.name, '—') AS brand_name,
        COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
        COALESCE(ot.id, 0) AS team_id,
        COALESCE(ot.name, 'Unassigned') AS team_name,
        SUM(osoi.quantity) AS qty_sold,
        COUNT(DISTINCT oso.id) AS order_count,
        SUM(osoi.line_total) AS sales_amount,
        SUM(
            CASE
                WHEN COALESCE(oso.subtotal, 0) > 0
                THEN COALESCE(oso.discount, 0) * (osoi.line_total / oso.subtotal)
                ELSE 0
            END
        ) AS total_discount,
        MAX(DATE(oso.sale_date)) AS last_sold,
        MAX(oso.updated_at) AS last_sold_at
    FROM offline_sale_order_items osoi
    INNER JOIN offline_sale_orders oso ON oso.id = osoi.order_id
    LEFT JOIN products p ON p.id = osoi.product_id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN offline_teams ot ON ot.id = oso.team_id
    WHERE {$whereSql}
    GROUP BY
        osoi.product_id,
        COALESCE(p.name, osoi.product_name),
        COALESCE(b.name, '—'),
        COALESCE(NULLIF(b.color, ''), '#6b7280'),
        COALESCE(ot.id, 0),
        COALESCE(ot.name, 'Unassigned')
    ORDER BY last_sold DESC, product_name ASC, team_name ASC
");
$rowsStmt->execute($params);
$allRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$purchaseParams = [$dateFrom, $dateTo];
$purchaseWhere = [
    'DATE(oso.sale_date) >= ?',
    'DATE(oso.sale_date) <= ?',
    "LOWER(COALESCE(oso.status, '')) NOT IN ('cancelled', 'canceled')",
];
if ($teamId > 0) {
    $purchaseWhere[] = 'oso.team_id = ?';
    $purchaseParams[] = $teamId;
}
if ($productId > 0) {
    $purchaseWhere[] = 'ospi.product_id = ?';
    $purchaseParams[] = $productId;
}
$purchaseWhereSql = implode(' AND ', $purchaseWhere);

$purchaseRowsStmt = $pdo->prepare("
    SELECT
        ospi.product_id,
        COALESCE(p.name, ospi.product_name) AS product_name,
        COALESCE(b.name, '—') AS brand_name,
        COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
        COALESCE(ot.id, 0) AS team_id,
        COALESCE(ot.name, 'Unassigned') AS team_name,
        SUM(ospi.quantity) AS qty_purchased,
        SUM(ospi.line_total) AS purchase_amount,
        COUNT(DISTINCT oso.id) AS purchase_order_count,
        MAX(DATE(oso.sale_date)) AS last_purchased,
        MAX(oso.updated_at) AS last_purchased_at
    FROM offline_sale_purchase_items ospi
    INNER JOIN offline_sale_orders oso ON oso.id = ospi.order_id
    LEFT JOIN products p ON p.id = ospi.product_id
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN offline_teams ot ON ot.id = oso.team_id
    WHERE {$purchaseWhereSql}
    GROUP BY
        ospi.product_id,
        COALESCE(p.name, ospi.product_name),
        COALESCE(b.name, '—'),
        COALESCE(NULLIF(b.color, ''), '#6b7280'),
        COALESCE(ot.id, 0),
        COALESCE(ot.name, 'Unassigned')
");
$purchaseRowsStmt->execute($purchaseParams);
$purchaseRows = $purchaseRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$combinedRowsByKey = [];
foreach ($allRows as $row) {
    $key = (int)$row['product_id'] . '|' . (int)$row['team_id'];
    $row['qty_purchased'] = 0.0;
    $row['purchase_amount'] = 0.0;
    $row['purchase_order_count'] = 0;
    $row['last_purchased'] = null;
    $row['last_purchased_at'] = null;
    $combinedRowsByKey[$key] = $row;
}
foreach ($purchaseRows as $row) {
    $key = (int)$row['product_id'] . '|' . (int)$row['team_id'];
    if (!isset($combinedRowsByKey[$key])) {
        $combinedRowsByKey[$key] = array_merge($row, [
            'qty_sold' => 0.0,
            'order_count' => 0,
            'sales_amount' => 0.0,
            'total_discount' => 0.0,
            'last_sold' => null,
            'last_sold_at' => null,
        ]);
    } else {
        $combinedRowsByKey[$key]['qty_purchased'] = (float)$row['qty_purchased'];
        $combinedRowsByKey[$key]['purchase_amount'] = (float)$row['purchase_amount'];
        $combinedRowsByKey[$key]['purchase_order_count'] = (int)$row['purchase_order_count'];
        $combinedRowsByKey[$key]['last_purchased'] = $row['last_purchased'];
        $combinedRowsByKey[$key]['last_purchased_at'] = $row['last_purchased_at'];
    }
}
$allRows = array_values($combinedRowsByKey);
usort($allRows, static function (array $a, array $b): int {
    $nameCompare = strcasecmp((string)$a['product_name'], (string)$b['product_name']);
    return $nameCompare !== 0 ? $nameCompare : strcasecmp((string)$a['team_name'], (string)$b['team_name']);
});

$productRowsStmt = $pdo->prepare("
    SELECT
        osoi.product_id,
        COALESCE(p.name, osoi.product_name) AS product_name,
        COALESCE(b.name, '—') AS brand_name,
        COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
        SUM(osoi.quantity) AS qty_sold,
        COUNT(DISTINCT oso.id) AS order_count,
        SUM(osoi.line_total) AS sales_amount,
        SUM(
            CASE
                WHEN COALESCE(oso.subtotal, 0) > 0
                THEN COALESCE(oso.discount, 0) * (osoi.line_total / oso.subtotal)
                ELSE 0
            END
        ) AS total_discount,
        MAX(DATE(oso.sale_date)) AS last_sold,
        MAX(oso.updated_at) AS last_sold_at
    FROM offline_sale_order_items osoi
    INNER JOIN offline_sale_orders oso ON oso.id = osoi.order_id
    LEFT JOIN products p ON p.id = osoi.product_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE {$whereSql}
    GROUP BY
        osoi.product_id,
        COALESCE(p.name, osoi.product_name),
        COALESCE(b.name, '—'),
        COALESCE(NULLIF(b.color, ''), '#6b7280')
    ORDER BY sales_amount DESC, product_name ASC
");
$productRowsStmt->execute($params);
$productRows = $productRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$purchaseProductRowsStmt = $pdo->prepare("
    SELECT
        ospi.product_id,
        COALESCE(p.name, ospi.product_name) AS product_name,
        COALESCE(b.name, '—') AS brand_name,
        COALESCE(NULLIF(b.color, ''), '#6b7280') AS brand_color,
        SUM(ospi.quantity) AS qty_purchased,
        SUM(ospi.line_total) AS purchase_amount,
        COUNT(DISTINCT oso.id) AS purchase_order_count,
        MAX(DATE(oso.sale_date)) AS last_purchased,
        MAX(oso.updated_at) AS last_purchased_at
    FROM offline_sale_purchase_items ospi
    INNER JOIN offline_sale_orders oso ON oso.id = ospi.order_id
    LEFT JOIN products p ON p.id = ospi.product_id
    LEFT JOIN brands b ON b.id = p.brand_id
    WHERE {$purchaseWhereSql}
    GROUP BY
        ospi.product_id,
        COALESCE(p.name, ospi.product_name),
        COALESCE(b.name, '—'),
        COALESCE(NULLIF(b.color, ''), '#6b7280')
    ORDER BY purchase_amount DESC, product_name ASC
");
$purchaseProductRowsStmt->execute($purchaseParams);
$purchaseProductRows = $purchaseProductRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$productRowsById = [];
foreach ($productRows as $row) {
    $pid = (int)($row['product_id'] ?? 0);
    $row['qty_purchased'] = 0.0;
    $row['purchase_amount'] = 0.0;
    $row['purchase_order_count'] = 0;
    $row['last_purchased'] = null;
    $row['last_purchased_at'] = null;
    $productRowsById[$pid] = $row;
}
foreach ($purchaseProductRows as $row) {
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
        $productRowsById[$pid]['qty_purchased'] = (float)$row['qty_purchased'];
        $productRowsById[$pid]['purchase_amount'] = (float)$row['purchase_amount'];
        $productRowsById[$pid]['purchase_order_count'] = (int)$row['purchase_order_count'];
        $productRowsById[$pid]['last_purchased'] = $row['last_purchased'];
        $productRowsById[$pid]['last_purchased_at'] = $row['last_purchased_at'];
    }
}
$productRows = array_values($productRowsById);
usort($productRows, static function (array $a, array $b): int {
    $netA = (float)($a['sales_amount'] ?? 0) - (float)($a['purchase_amount'] ?? 0) - (float)($a['total_discount'] ?? 0);
    $netB = (float)($b['sales_amount'] ?? 0) - (float)($b['purchase_amount'] ?? 0) - (float)($b['total_discount'] ?? 0);
    if (abs($netA - $netB) > 0.009) {
        return $netA < $netB ? 1 : -1;
    }
    return strcasecmp((string)$a['product_name'], (string)$b['product_name']);
});

$orderRowsStmt = $pdo->prepare("
    SELECT
        oso.id,
        oso.order_code,
        oso.sale_date,
        oso.customer_name,
        oso.phone,
        oso.customer_location,
        oso.status,
        oso.subtotal,
        oso.discount,
        oso.purchase_total,
        oso.total_amount,
        COALESCE(ot.name, 'Unassigned') AS team_name,
        SUM(osoi.quantity) AS qty_sold,
        GROUP_CONCAT(
            CONCAT('SALE: ', osoi.product_name, ' x ', FORMAT(osoi.quantity, 0), ' - $', FORMAT(osoi.line_total, 2))
            ORDER BY osoi.id
            SEPARATOR '\n'
        ) AS product_lines
    FROM offline_sale_order_items osoi
    INNER JOIN offline_sale_orders oso ON oso.id = osoi.order_id
    LEFT JOIN offline_teams ot ON ot.id = oso.team_id
    WHERE {$orderDetailWhereSql}
    GROUP BY
        oso.id,
        oso.order_code,
        oso.sale_date,
        oso.customer_name,
        oso.phone,
        oso.customer_location,
        oso.status,
        oso.subtotal,
        oso.discount,
        oso.purchase_total,
        oso.total_amount,
        COALESCE(ot.name, 'Unassigned')
    ORDER BY oso.sale_date DESC, oso.id DESC
");
$orderRowsStmt->execute($orderDetailParams);
$orderRows = $orderRowsStmt->fetchAll(PDO::FETCH_ASSOC);
$orderPaymentsByOrder = $orderRows
    ? offline_payments_for_orders($pdo, array_map(static fn($row) => (int)$row['id'], $orderRows))
    : [];
$orderPurchaseItemsByOrder = [];
if ($orderRows) {
    $orderIds = array_map(static fn($row) => (int)$row['id'], $orderRows);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $orderPurchaseStmt = $pdo->prepare("
        SELECT order_id, product_name, quantity, line_total
        FROM offline_sale_purchase_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_id, id
    ");
    $orderPurchaseStmt->execute($orderIds);
    foreach ($orderPurchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $purchaseRow) {
        $orderPurchaseItemsByOrder[(int)$purchaseRow['order_id']][] = $purchaseRow;
    }
    foreach ($orderRows as &$orderRow) {
        $purchaseLines = [];
        foreach ($orderPurchaseItemsByOrder[(int)$orderRow['id']] ?? [] as $purchaseRow) {
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
    }
    unset($orderRow);
}

$productSummaryQty = 0.0;
$productSummarySales = 0.0;
$productSummaryPurchaseQty = 0.0;
$productSummaryPurchase = 0.0;
$productSummaryOrders = 0;
foreach ($productRows as $productRow) {
    $productSummaryQty += (float)($productRow['qty_sold'] ?? 0);
    $productSummarySales += (float)($productRow['sales_amount'] ?? 0);
    $productSummaryPurchaseQty += (float)($productRow['qty_purchased'] ?? 0);
    $productSummaryPurchase += (float)($productRow['purchase_amount'] ?? 0);
    $productSummaryOrders += (int)($productRow['order_count'] ?? 0);
}

$totalSales = 0.0;
$totalQty = 0.0;
$totalPurchase = 0.0;
$totalPurchaseQty = 0.0;
$totalOrders = 0;
$productIds = [];
$teamIds = [];
foreach ($allRows as $row) {
    $totalQty += (float)($row['qty_sold'] ?? 0);
    $totalSales += (float)($row['sales_amount'] ?? 0);
    $totalPurchaseQty += (float)($row['qty_purchased'] ?? 0);
    $totalPurchase += (float)($row['purchase_amount'] ?? 0);
    $totalOrders += (int)($row['order_count'] ?? 0);
    $productIds[(int)$row['product_id']] = true;
    if ((int)($row['team_id'] ?? 0) > 0) {
        $teamIds[(int)$row['team_id']] = true;
    }
}

$orderCountParams = [$dateFrom, $dateTo];
$orderCountWhere = [
    'DATE(oso.sale_date) >= ?',
    'DATE(oso.sale_date) <= ?',
    "LOWER(COALESCE(oso.status, '')) NOT IN ('cancelled', 'canceled')",
];
if ($teamId > 0) {
    $orderCountWhere[] = 'oso.team_id = ?';
    $orderCountParams[] = $teamId;
}
if ($productId > 0) {
    $orderCountWhere[] = "(
        EXISTS (
            SELECT 1
            FROM offline_sale_order_items osoi_count
            WHERE osoi_count.order_id = oso.id AND osoi_count.product_id = ?
        )
        OR EXISTS (
            SELECT 1
            FROM offline_sale_purchase_items ospi_count
            WHERE ospi_count.order_id = oso.id AND ospi_count.product_id = ?
        )
    )";
    $orderCountParams[] = $productId;
    $orderCountParams[] = $productId;
}
$orderCountWhereSql = implode(' AND ', $orderCountWhere);
$orderCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT oso.id)
    FROM offline_sale_orders oso
    WHERE {$orderCountWhereSql}
");
$orderCountStmt->execute($orderCountParams);
$uniqueOrders = (int)($orderCountStmt->fetchColumn() ?: 0);

$summary = [
    'products_sold' => count($productIds),
    'total_sales' => $totalSales,
    'total_purchase' => $totalPurchase,
    'net_sales' => $totalSales - $totalPurchase,
    'total_qty' => $totalQty,
    'purchase_qty' => $totalPurchaseQty,
    'net_qty' => $totalQty - $totalPurchaseQty,
    'teams_active' => count($teamIds),
    'total_orders' => $uniqueOrders,
];

$dateRangeLabel = $dateFrom === $dateTo
    ? date('M j, Y', strtotime($dateFrom))
    : date('M j, Y', strtotime($dateFrom)) . ' – ' . date('M j, Y', strtotime($dateTo));

$teams = $pdo->query("SELECT id, name FROM offline_teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT id, name FROM products WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$exportDateSlug = preg_replace('/[^0-9_-]/', '', $dateFrom . '_to_' . $dateTo) ?: 'export';

function offline_daily_sales_resolve_date_range(array $get): array
{
    $today = date('Y-m-d');
    $quickRange = strtolower(trim((string)($get['quick_range'] ?? '')));
    $validQuick = ['today', 'yesterday', 'this_month', 'last_month'];

    $dateFrom = offline_optional_filter_date($get['date_from'] ?? null);
    $dateTo = offline_optional_filter_date($get['date_to'] ?? null);

    if (!$dateFrom && !$dateTo) {
        $legacy = offline_optional_filter_date($get['date'] ?? null);
        if ($legacy) {
            $dateFrom = $legacy;
            $dateTo = $legacy;
        }
    }

    if (in_array($quickRange, $validQuick, true)) {
        switch ($quickRange) {
            case 'today':
                $dateFrom = $today;
                $dateTo = $today;
                break;
            case 'yesterday':
                $dateFrom = date('Y-m-d', strtotime('-1 day'));
                $dateTo = $dateFrom;
                break;
            case 'this_month':
                $dateFrom = date('Y-m-01');
                $dateTo = date('Y-m-t');
                break;
            case 'last_month':
                $dateFrom = date('Y-m-01', strtotime('first day of last month'));
                $dateTo = date('Y-m-t', strtotime('last day of last month'));
                break;
        }
    } else {
        $quickRange = '';
        $hasRequest = isset($get['date_from']) || isset($get['date_to']) || isset($get['team_id'])
            || isset($get['product_id']) || isset($get['action']);
        if (!$dateFrom && !$dateTo && !$hasRequest) {
            $dateFrom = $today;
            $dateTo = $today;
            $quickRange = 'today';
        } elseif (!$dateFrom && !$dateTo) {
            $dateFrom = $today;
            $dateTo = $today;
        } elseif ($dateFrom && !$dateTo) {
            $dateTo = $dateFrom;
        } elseif (!$dateFrom && $dateTo) {
            $dateFrom = $dateTo;
        }
    }

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    if ($quickRange === '') {
        $quickRange = offline_daily_sales_detect_quick_range($dateFrom, $dateTo);
    }

    return [$dateFrom, $dateTo, $quickRange];
}

function offline_daily_sales_detect_quick_range(string $dateFrom, string $dateTo): string
{
    $today = date('Y-m-d');
    if ($dateFrom === $today && $dateTo === $today) {
        return 'today';
    }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($dateFrom === $yesterday && $dateTo === $yesterday) {
        return 'yesterday';
    }
    if ($dateFrom === date('Y-m-01') && $dateTo === date('Y-m-t')) {
        return 'this_month';
    }
    $lastFrom = date('Y-m-01', strtotime('first day of last month'));
    $lastTo = date('Y-m-t', strtotime('last day of last month'));
    if ($dateFrom === $lastFrom && $dateTo === $lastTo) {
        return 'last_month';
    }

    return '';
}

function offline_daily_sales_team_badge_class(int $teamId): string
{
    $classes = ['primary', 'info', 'success', 'warning', 'secondary'];
    return $classes[$teamId % count($classes)];
}

function offline_daily_sales_filter_url(array $overrides = []): string
{
    global $dateFrom, $dateTo, $teamId, $productId;
    $params = array_filter([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'team_id' => $teamId > 0 ? (string)$teamId : '',
        'product_id' => $productId > 0 ? (string)$productId : '',
    ], static fn($v) => $v !== '' && $v !== '0');
    $params = array_merge($params, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    unset($params['quick_range']);

    return 'offline_daily_sales.php' . ($params ? '?' . http_build_query($params) : '');
}

function offline_daily_sales_quick_url(string $quickRange): string
{
    global $teamId, $productId;
    $params = array_filter([
        'quick_range' => $quickRange,
        'team_id' => $teamId > 0 ? (string)$teamId : '',
        'product_id' => $productId > 0 ? (string)$productId : '',
    ], static fn($v) => $v !== '' && $v !== '0');

    return 'offline_daily_sales.php?' . http_build_query($params);
}

function offline_qty_pcs(float $qty): string
{
    $formatted = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    return $formatted . ' pcs';
}

function offline_daily_sales_format_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y', $ts) : '—';
}

function offline_daily_sales_format_datetime(?string $value): string
{
    if (!$value) {
        return 'â€”';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : 'â€”';
}

function offline_daily_sales_display_datetime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : '-';
}

include __DIR__ . '/../layout/header.php';
offline_status_badge_styles();
?>
<style>
.offline-daily-sales-page { --ods-pink: #e91e63; --ods-pink-dark: #c2185b; }
.offline-daily-sales-page .btn-ods { background: var(--ods-pink); border-color: var(--ods-pink); color: #fff; }
.offline-daily-sales-page .btn-ods:hover { background: var(--ods-pink-dark); border-color: var(--ods-pink-dark); color: #fff; }
.offline-daily-sales-page .stat-card { border: 0; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.offline-daily-sales-page .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem; }
.offline-daily-sales-page .product-thumb { width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #f8f9fa, #e9ecef); display: inline-flex; align-items: center; justify-content: center; color: var(--ods-pink); font-weight: 700; }
.offline-daily-sales-page .brand-badge {
    --brand-color: #6b7280;
    display: inline-block;
    border: 1px solid color-mix(in srgb, var(--brand-color) 30%, transparent);
    background: color-mix(in srgb, var(--brand-color) 12%, white);
    color: var(--brand-color);
    border-radius: 8px;
    font-weight: 600;
    padding: .35rem .55rem;
    font-size: .875rem;
}
.offline-daily-sales-page .order-products {
    min-width: 240px;
    white-space: pre-line;
}
.offline-daily-sales-page .offline-status-badge {
    gap: .3rem;
    padding: .2rem .45rem .2rem .3rem;
    border-radius: 6px;
    font-size: .72rem;
}
.offline-daily-sales-page .offline-status-badge .offline-status-icon {
    font-size: .75rem;
}
.offline-daily-sales-page .order-row-cancelled,
.offline-daily-sales-page .table-hover > tbody > tr.order-row-cancelled:hover > * {
    background-color: #f8d7da;
}
.offline-daily-sales-page .order-row-cancelled:hover > * {
    background-color: #f5c2c7;
}
@media print {
    .offline-daily-sales-page .no-print { display: none !important; }
    .offline-daily-sales-page .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
</style>
<div class="container-fluid py-4 offline-daily-sales-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 fw-bold mb-1">Offline Daily Product Sold</h1>
            <p class="text-muted mb-0">View summary of products sold offline for <strong><?= htmlspecialchars($dateRangeLabel) ?></strong>.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#printLayoutModal">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <button type="button" class="btn btn-outline-success"
                    onclick="offlineDailySalesExportAllTables()">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-box-seam"></i></span>
                    <div>
                        <div class="text-muted small">Products Sold</div>
                        <div class="fs-4 fw-bold"><?= number_format($summary['products_sold']) ?></div>
                        <div class="text-muted small">Different products</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-currency-dollar"></i></span>
                    <div>
                        <div class="text-muted small">Net Sales</div>
                        <div class="fs-4 fw-bold">$<?= number_format($summary['net_sales'], 2) ?></div>
                        <div class="text-muted small">Sold $<?= number_format($summary['total_sales'], 2) ?> - Purchase $<?= number_format($summary['total_purchase'], 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-bag-check"></i></span>
                    <div>
                        <div class="text-muted small">Quantity Net</div>
                        <div class="fs-4 fw-bold"><?= number_format($summary['net_qty'], 0) ?></div>
                        <div class="text-muted small">Sold <?= number_format($summary['total_qty'], 0) ?> - Purchase <?= number_format($summary['purchase_qty'], 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-currency-dollar"></i></span>
                    <div>
                        <div class="text-muted small">Gross Sold</div>
                        <div class="fs-4 fw-bold">$<?= number_format($summary['total_sales'], 2) ?></div>
                        <div class="text-muted small">Total sales amount</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-people"></i></span>
                    <div>
                        <div class="text-muted small">Teams Active</div>
                        <div class="fs-4 fw-bold"><?= number_format($summary['teams_active']) ?></div>
                        <div class="text-muted small">Teams with sales</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon text-white" style="background: var(--ods-pink);"><i class="bi bi-receipt"></i></span>
                    <div>
                        <div class="text-muted small">Total Orders</div>
                        <div class="fs-4 fw-bold"><?= number_format($summary['total_orders']) ?></div>
                        <div class="text-muted small">Offline orders</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4 no-print">
        <div class="card-body">
            <div class="row g-2 mb-3">
                <?php
                $quickPresets = [
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'this_month' => 'This Month',
                    'last_month' => 'Last Month',
                ];
                foreach ($quickPresets as $presetKey => $presetLabel):
                    $isActive = $quickRange === $presetKey;
                ?>
                <div class="col-6 col-md-3 col-lg">
                    <a href="<?= htmlspecialchars(offline_daily_sales_quick_url($presetKey)) ?>"
                       class="btn w-100 <?= $isActive ? 'btn-ods' : 'btn-outline-secondary' ?>"><?= htmlspecialchars($presetLabel) ?></a>
                </div>
                <?php endforeach; ?>
            </div>
            <form method="get" class="row g-3 align-items-end">
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>" required>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>" required>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">Team</label>
                    <select name="team_id" class="form-select">
                        <option value="0">All Teams</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>" <?= $teamId === (int)$team['id'] ? 'selected' : '' ?>><?= htmlspecialchars($team['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-3">
                    <label class="form-label fw-semibold">Product</label>
                    <select name="product_id" class="form-select">
                        <option value="0">All Products</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int)$product['id'] ?>" <?= $productId === (int)$product['id'] ? 'selected' : '' ?>><?= htmlspecialchars($product['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-2">
                    <button type="submit" class="btn btn-ods w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
                <h5 class="fw-bold mb-0">Product Sales Details <span class="text-muted fw-normal fs-6">— <?= htmlspecialchars($dateRangeLabel) ?></span></h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="offlineDailySalesPrintTable('offlineSalesDetailsTable', 'Product Sales Details')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="offlineDailySalesExportTable('offlineSalesDetailsTable', 'offline_product_sales_details_<?= htmlspecialchars($exportDateSlug, ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="offlineSalesDetailsTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:50px;">No</th>
                            <th>Product Name</th>
                            <th>Brand</th>
                            <th>Team</th>
                            <th class="text-end">Sold Qty</th>
                            <th class="text-end">Sold Amount</th>
                            <th class="text-end">Purchased Qty</th>
                            <th class="text-end">Purchase Amount</th>
                            <th class="text-end">Net Qty</th>
                            <th class="text-end">Net Sales</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Final Amount</th>
                            <th>Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($allRows): ?>
                        <?php foreach ($allRows as $index => $row): ?>
                            <?php
                            $rowNum    = $index + 1;
                            $teamBadge = offline_daily_sales_team_badge_class((int)($row['team_id'] ?? 0));
                            $qtySold   = (float)($row['qty_sold'] ?? 0);
                            $salesAmt  = (float)($row['sales_amount'] ?? 0);
                            $qtyPurchased = (float)($row['qty_purchased'] ?? 0);
                            $purchaseAmt = (float)($row['purchase_amount'] ?? 0);
                            $discount  = (float)($row['total_discount'] ?? 0);
                            $netQty = $qtySold - $qtyPurchased;
                            $netSales = $salesAmt - $purchaseAmt;
                            $finalAmt = $netSales - $discount;
                            $lastUpdate = (string)($row['last_sold_at'] ?: ($row['last_purchased_at'] ?? ''));
                            ?>
                            <tr>
                                <td class="text-center text-muted"><?= $rowNum ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string)$row['product_name']) ?></td>
                                <td>
                                    <?php if ((string)($row['brand_name'] ?? '') !== '—'): ?>
                                        <span class="brand-badge" style="--brand-color: <?= htmlspecialchars((string)($row['brand_color'] ?? '#6b7280')) ?>"><?= htmlspecialchars((string)$row['brand_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?= $teamBadge ?>"><?= htmlspecialchars((string)$row['team_name']) ?></span></td>
                                <td class="text-end"><?= offline_qty_pcs($qtySold) ?></td>
                                <td class="text-end text-success fw-semibold">$<?= number_format($salesAmt, 2) ?></td>
                                <td class="text-end"><?= offline_qty_pcs($qtyPurchased) ?></td>
                                <td class="text-end <?= $purchaseAmt > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                    <?= $purchaseAmt > 0 ? '-$' . number_format($purchaseAmt, 2) : '$0.00' ?>
                                </td>
                                <td class="text-end <?= $netQty < 0 ? 'text-danger' : 'text-muted' ?>"><?= offline_qty_pcs($netQty) ?></td>
                                <td class="text-end <?= $netSales < 0 ? 'text-danger' : 'text-primary' ?> fw-semibold">$<?= number_format($netSales, 2) ?></td>
                                <td class="text-end <?= $discount > 0 ? 'text-danger' : 'text-muted' ?>">
                                    <?= $discount > 0 ? '-$' . number_format($discount, 2) : '$0.00' ?>
                                </td>
                                <td class="text-end fw-bold <?= $finalAmt < 0 ? 'text-danger' : 'text-primary' ?>">$<?= number_format($finalAmt, 2) ?></td>
                                <td><?= htmlspecialchars(offline_daily_sales_display_datetime($lastUpdate)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="13" class="text-center text-muted py-5">No sales found for this date range and filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($allRows):
                        $t1TotalQty = 0.0; $t1TotalDisc = 0.0; $t1TotalSales = 0.0;
                        $t1TotalPurchaseQty = 0.0; $t1TotalPurchase = 0.0;
                        foreach ($allRows as $r) {
                            $t1TotalQty         += (float)($r['qty_sold'] ?? 0);
                            $t1TotalDisc        += (float)($r['total_discount'] ?? 0);
                            $t1TotalSales       += (float)($r['sales_amount'] ?? 0);
                            $t1TotalPurchaseQty += (float)($r['qty_purchased'] ?? 0);
                            $t1TotalPurchase    += (float)($r['purchase_amount'] ?? 0);
                        }
                        $t1NetQty = $t1TotalQty - $t1TotalPurchaseQty;
                        $t1NetSales = $t1TotalSales - $t1TotalPurchase;
                        $t1FinalAmount = $t1NetSales - $t1TotalDisc;
                    ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">Total</th>
                            <th class="text-end"><?= offline_qty_pcs($t1TotalQty) ?></th>
                            <th class="text-end text-success">$<?= number_format($t1TotalSales, 2) ?></th>
                            <th class="text-end"><?= offline_qty_pcs($t1TotalPurchaseQty) ?></th>
                            <th class="text-end <?= $t1TotalPurchase > 0 ? 'text-warning-emphasis' : '' ?>">
                                <?= $t1TotalPurchase > 0 ? '-$' . number_format($t1TotalPurchase, 2) : '$0.00' ?>
                            </th>
                            <th class="text-end <?= $t1NetQty < 0 ? 'text-danger' : '' ?>"><?= offline_qty_pcs($t1NetQty) ?></th>
                            <th class="text-end <?= $t1NetSales < 0 ? 'text-danger' : 'text-primary' ?>">$<?= number_format($t1NetSales, 2) ?></th>
                            <th class="text-end <?= $t1TotalDisc > 0 ? 'text-danger' : '' ?>">
                                <?= $t1TotalDisc > 0 ? '-$' . number_format($t1TotalDisc, 2) : '$0.00' ?>
                            </th>
                            <th class="text-end <?= $t1FinalAmount < 0 ? 'text-danger' : 'text-primary' ?>">$<?= number_format($t1FinalAmount, 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
                <div>
                    <h5 class="fw-bold mb-1">Product Order Details <span class="text-muted fw-normal fs-6">— <?= htmlspecialchars($dateRangeLabel) ?></span></h5>
                    <p class="text-muted small mb-0">All products combined — one row per product (no team split).</p>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="offlineDailySalesPrintTable('offlineProductOrdersTable', 'Product Order Details')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="offlineDailySalesExportTable('offlineProductOrdersTable', 'offline_product_order_details_<?= htmlspecialchars($exportDateSlug, ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="offlineProductOrdersTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:50px;">No</th>
                            <th>Product Name</th>
                            <th>Brand</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Sale Orders</th>
                            <th class="text-end">Sold Amount</th>
                            <th class="text-end">Purchased Qty</th>
                            <th class="text-end">Purchase Amount</th>
                            <th class="text-end">Net Qty</th>
                            <th class="text-end">Net Sales</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Final Amount</th>
                            <th>Last Update</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($productRows): ?>
                        <?php
                        $productSummaryDiscount = 0.0;
                        foreach ($productRows as $index => $row):
                            $pQty      = (float)($row['qty_sold'] ?? 0);
                            $pSales    = (float)($row['sales_amount'] ?? 0);
                            $pPurchaseQty = (float)($row['qty_purchased'] ?? 0);
                            $pPurchase = (float)($row['purchase_amount'] ?? 0);
                            $pDiscount = (float)($row['total_discount'] ?? 0);
                            $pNetQty = $pQty - $pPurchaseQty;
                            $pNetSales = $pSales - $pPurchase;
                            $pNet      = $pNetSales - $pDiscount;
                            $lastUpdate = (string)($row['last_sold_at'] ?: ($row['last_purchased_at'] ?? ''));
                            $productSummaryDiscount += $pDiscount;
                        ?>
                            <tr>
                                <td class="text-center text-muted"><?= $index + 1 ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string)$row['product_name']) ?></td>
                                <td>
                                    <?php if ((string)($row['brand_name'] ?? '') !== '—'): ?>
                                        <span class="brand-badge" style="--brand-color: <?= htmlspecialchars((string)($row['brand_color'] ?? '#6b7280')) ?>"><?= htmlspecialchars((string)$row['brand_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= offline_qty_pcs($pQty) ?></td>
                                <td class="text-end"><?= number_format((int)$row['order_count']) ?></td>
                                <td class="text-end text-success fw-semibold">$<?= number_format($pSales, 2) ?></td>
                                <td class="text-end"><?= offline_qty_pcs($pPurchaseQty) ?></td>
                                <td class="text-end <?= $pPurchase > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                    <?= $pPurchase > 0 ? '-$' . number_format($pPurchase, 2) : '$0.00' ?>
                                </td>
                                <td class="text-end <?= $pNetQty < 0 ? 'text-danger' : 'text-muted' ?>"><?= offline_qty_pcs($pNetQty) ?></td>
                                <td class="text-end <?= $pNetSales < 0 ? 'text-danger' : 'text-primary' ?> fw-semibold">$<?= number_format($pNetSales, 2) ?></td>
                                <td class="text-end <?= $pDiscount > 0 ? 'text-danger' : 'text-muted' ?>">
                                    <?= $pDiscount > 0 ? '-$' . number_format($pDiscount, 2) : '$0.00' ?>
                                </td>
                                <td class="text-end fw-bold <?= $pNet < 0 ? 'text-danger' : 'text-primary' ?>">$<?= number_format($pNet, 2) ?></td>
                                <td><?= htmlspecialchars(offline_daily_sales_display_datetime($lastUpdate)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="13" class="text-center text-muted py-5">No product orders found for this date range and filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($productRows): ?>
                    <?php
                        $productSummaryNetQty = $productSummaryQty - $productSummaryPurchaseQty;
                        $productSummaryNetSales = $productSummarySales - $productSummaryPurchase;
                        $productSummaryFinal = $productSummaryNetSales - $productSummaryDiscount;
                    ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Total</th>
                            <th class="text-end"><?= offline_qty_pcs($productSummaryQty) ?></th>
                            <th class="text-end"><?= number_format($productSummaryOrders) ?></th>
                            <th class="text-end text-success">$<?= number_format($productSummarySales, 2) ?></th>
                            <th class="text-end"><?= offline_qty_pcs($productSummaryPurchaseQty) ?></th>
                            <th class="text-end <?= $productSummaryPurchase > 0 ? 'text-warning-emphasis' : '' ?>">
                                <?= $productSummaryPurchase > 0 ? '-$' . number_format($productSummaryPurchase, 2) : '$0.00' ?>
                            </th>
                            <th class="text-end <?= $productSummaryNetQty < 0 ? 'text-danger' : '' ?>"><?= offline_qty_pcs($productSummaryNetQty) ?></th>
                            <th class="text-end <?= $productSummaryNetSales < 0 ? 'text-danger' : 'text-primary' ?>">$<?= number_format($productSummaryNetSales, 2) ?></th>
                            <th class="text-end <?= $productSummaryDiscount > 0 ? 'text-danger' : '' ?>">
                                <?= $productSummaryDiscount > 0 ? '-$' . number_format($productSummaryDiscount, 2) : '$0.00' ?>
                            </th>
                            <th class="text-end <?= $productSummaryFinal < 0 ? 'text-danger' : 'text-primary' ?>">$<?= number_format($productSummaryFinal, 2) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
                <div>
                    <h5 class="fw-bold mb-1">Order Details <span class="text-muted fw-normal fs-6">— <?= htmlspecialchars($dateRangeLabel) ?></span></h5>
                    <p class="text-muted small mb-0">All offline sale orders in this report period. Cancelled orders are shown in red but not included in sold totals.</p>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="offlineDailySalesPrintTable('offlineOrderDetailsTable', 'Order Details')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="offlineDailySalesExportTable('offlineOrderDetailsTable', 'offline_order_details_<?= htmlspecialchars($exportDateSlug, ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="offlineOrderDetailsTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:50px;">No</th>
                            <th>Order Code</th>
                            <th>Sale Date</th>
                            <th>Customer</th>
                            <th>Location</th>
                            <th>Phone</th>
                            <th>Team</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Sold Amount</th>
                            <th class="text-end">Purchase Amount</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Final Amount</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($orderRows): ?>
                        <?php foreach ($orderRows as $index => $orderRow): ?>
                            <?php
                            $orderId = (int)$orderRow['id'];
                            $payments = $orderPaymentsByOrder[$orderId] ?? [];
                            $paid = offline_order_paid_from_payments($payments, $orderRow);
                            $balance = max(0, (float)($orderRow['total_amount'] ?? 0) - $paid);
                            $status = offline_order_display_status($orderRow, $payments);
                            $isCancelledOrder = $status === 'cancelled';
                            $orderSoldAmount = (float)($orderRow['subtotal'] ?? 0);
                            $orderDiscount = (float)($orderRow['discount'] ?? 0);
                            $orderPurchaseTotal = (float)($orderRow['purchase_total'] ?? 0);
                            ?>
                            <tr class="<?= $isCancelledOrder ? 'order-row-cancelled' : '' ?>">
                                <td class="text-center text-muted"><?= $index + 1 ?></td>
                                <td class="fw-semibold text-nowrap"><?= htmlspecialchars((string)$orderRow['order_code']) ?></td>
                                <td class="text-nowrap"><?= htmlspecialchars(offline_daily_sales_format_date((string)$orderRow['sale_date'])) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)($orderRow['customer_name'] ?: 'N/A')) ?></div>
                                </td>
                                <td><?= htmlspecialchars((string)($orderRow['customer_location'] ?: '-')) ?></td>
                                <td class="text-nowrap"><?= htmlspecialchars((string)($orderRow['phone'] ?: '—')) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars((string)$orderRow['team_name']) ?></span></td>
                                <td class="order-products"><?= htmlspecialchars((string)($orderRow['product_lines'] ?: '—')) ?></td>
                                <td><?= offline_status_badge_html($status) ?></td>
                                <td class="text-end"><?= offline_qty_pcs((float)$orderRow['qty_sold']) ?></td>
                                <td class="text-end fw-semibold">$<?= number_format($orderSoldAmount, 2) ?></td>
                                <td class="text-end <?= $orderPurchaseTotal > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' ?>">
                                    <?= $orderPurchaseTotal > 0 ? '-$' . number_format($orderPurchaseTotal, 2) : '$0.00' ?>
                                </td>
                                <td class="text-end <?= $orderDiscount > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                    <?= $orderDiscount > 0 ? '-$' . number_format($orderDiscount, 2) : '$0.00' ?>
                                </td>
                                <td class="text-end fw-semibold">$<?= number_format((float)$orderRow['total_amount'], 2) ?></td>
                                <td class="text-end text-success fw-semibold">$<?= number_format($paid, 2) ?></td>
                                <td class="text-end <?= $balance > 0 ? 'text-danger' : 'text-success' ?> fw-semibold">$<?= number_format($balance, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="16" class="text-center text-muted py-5">No orders found for this date range and filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($orderRows):
                        $t3TotalQty = 0.0; $t3TotalSold = 0.0; $t3TotalAmt = 0.0; $t3TotalDisc = 0.0; $t3TotalPurchase = 0.0;
                        $t3TotalPaid = 0.0; $t3TotalBal = 0.0;
                        foreach ($orderRows as $oRow) {
                            $oId = (int)$oRow['id'];
                            $oPmts = $orderPaymentsByOrder[$oId] ?? [];
                            $oPaid = offline_order_paid_from_payments($oPmts, $oRow);
                            $oBal  = max(0, (float)($oRow['total_amount'] ?? 0) - $oPaid);
                            $t3TotalQty  += (float)($oRow['qty_sold'] ?? 0);
                            $t3TotalSold += (float)($oRow['subtotal'] ?? 0);
                            $t3TotalAmt  += (float)($oRow['total_amount'] ?? 0);
                            $t3TotalDisc += (float)($oRow['discount'] ?? 0);
                            $t3TotalPurchase += (float)($oRow['purchase_total'] ?? 0);
                            $t3TotalPaid += $oPaid;
                            $t3TotalBal  += $oBal;
                        }
                    ?>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="9" class="text-end">Total</th>
                            <th class="text-end"><?= offline_qty_pcs($t3TotalQty) ?></th>
                            <th class="text-end">$<?= number_format($t3TotalSold, 2) ?></th>
                            <th class="text-end <?= $t3TotalPurchase > 0 ? 'text-warning-emphasis' : '' ?>">
                                <?= $t3TotalPurchase > 0 ? '-$' . number_format($t3TotalPurchase, 2) : '$0.00' ?>
                            </th>
                            <th class="text-end <?= $t3TotalDisc > 0 ? 'text-danger' : '' ?>">
                                <?= $t3TotalDisc > 0 ? '-$' . number_format($t3TotalDisc, 2) : '$0.00' ?>
                            </th>
                            <th class="text-end">$<?= number_format($t3TotalAmt, 2) ?></th>
                            <th class="text-end text-success">$<?= number_format($t3TotalPaid, 2) ?></th>
                            <th class="text-end <?= $t3TotalBal > 0 ? 'text-danger' : 'text-success' ?>">$<?= number_format($t3TotalBal, 2) ?></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Print Layout Modal -->
<div class="modal fade" id="printLayoutModal" tabindex="-1" aria-labelledby="printLayoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="printLayoutModalLabel">
                    <i class="bi bi-printer me-2"></i>Print Layout
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Choose print settings:</strong> Select tables and adjust paper, layout, margins, and scale before printing.
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">
                        <i class="bi bi-table me-1"></i>Tables to Include
                    </label>
                    <div class="d-flex flex-column gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="plTable1" value="offlineSalesDetailsTable" checked>
                            <label class="form-check-label" for="plTable1">Product Sales Details</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="plTable2" value="offlineProductOrdersTable" checked>
                            <label class="form-check-label" for="plTable2">Product Order Details</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="plTable3" value="offlineOrderDetailsTable" checked>
                            <label class="form-check-label" for="plTable3">Order Details</label>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="plPaperSize" class="form-label fw-semibold">
                            <i class="bi bi-file-earmark me-1"></i>Paper Size
                        </label>
                        <select class="form-select form-select-lg" id="plPaperSize">
                            <option value="letter" selected>Letter</option>
                            <option value="A4">A4</option>
                            <option value="A5">A5</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="plLayout" class="form-label fw-semibold">
                            <i class="bi bi-window me-1"></i>Layout
                        </label>
                        <select class="form-select form-select-lg" id="plLayout">
                            <option value="landscape" selected>Landscape</option>
                            <option value="portrait">Portrait</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="plMargins" class="form-label fw-semibold">
                            <i class="bi bi-border-style me-1"></i>Margins
                        </label>
                        <select class="form-select form-select-lg" id="plMargins">
                            <option value="0" selected>None</option>
                            <option value="6mm">Small</option>
                            <option value="10mm">Normal</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="plScale" class="form-label fw-semibold">
                            <i class="bi bi-zoom-in me-1"></i>Scale
                        </label>
                        <div class="input-group input-group-lg">
                            <input type="number" class="form-control" id="plScale" min="40" max="120" step="5" value="70">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="offlineDailySalesPrintWithLayout()">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const offlineDailySalesAllSections = [
    { tableId: 'offlineSalesDetailsTable', title: 'Product Sales Details' },
    { tableId: 'offlineProductOrdersTable', title: 'Product Order Details' },
    { tableId: 'offlineOrderDetailsTable', title: 'Order Details' },
];

function offlineDailySalesEscapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function offlineDailySalesBuildStyledTableHtml(table) {
    const clone = table.cloneNode(true);
    clone.querySelectorAll('.ods-export-skip').forEach((cell) => cell.remove());
    clone.style.width = '100%';
    clone.style.borderCollapse = 'collapse';
    clone.style.tableLayout = 'fixed';
    clone.style.fontSize = '12px';
    clone.style.border = '1px solid #000000';

    clone.querySelectorAll('thead tr').forEach((tr) => {
        tr.querySelectorAll('th').forEach((th) => {
            th.style.backgroundColor = '#efefef';
            th.style.color = '#111111';
            th.style.fontWeight = '700';
            th.style.border = '1px solid #000000';
            th.style.padding = '7px 8px';
        });
    });

    clone.querySelectorAll('tbody tr').forEach((tr) => {
        tr.querySelectorAll('th, td').forEach((cell) => {
            cell.style.border = '1px solid #000000';
            cell.style.padding = '6px 8px';
            cell.style.backgroundColor = '#ffffff';
        });
    });

    clone.querySelectorAll('tfoot tr').forEach((tr) => {
        tr.querySelectorAll('th, td').forEach((cell) => {
            cell.style.backgroundColor = '#d9f2e4';
            cell.style.fontWeight = '700';
            cell.style.border = '1px solid #000000';
            cell.style.padding = '7px 8px';
        });
    });

    clone.querySelectorAll('.text-end').forEach((cell) => { cell.style.textAlign = 'right'; });
    clone.querySelectorAll('.text-center').forEach((cell) => { cell.style.textAlign = 'center'; });

    return clone.outerHTML;
}

function offlineDailySalesBuildCombinedTablesHtml(sections, showSectionLabel = true) {
    return sections.map(({ tableId, title }) => {
        const table = document.getElementById(tableId);
        if (!table) return '';
        return `
            <div class="report-section">
                ${showSectionLabel ? `<h3>${offlineDailySalesEscapeHtml(title)}</h3>` : ''}
                ${offlineDailySalesBuildStyledTableHtml(table)}
            </div>`;
    }).join('');
}

function offlineDailySalesBuildPrintDocument(title, sections, options = {}) {
    const paper = options.paper || 'letter';
    const orient = options.orient || 'landscape';
    const margin = options.margin || '0';
    const scale = Math.min(1.2, Math.max(0.4, Number(options.scale || 70) / 100));
    const showSectionLabel = options.showSectionLabel !== false;
    const dateRange = <?= json_encode($dateRangeLabel, JSON_UNESCAPED_UNICODE) ?>;
    const generatedText = new Date().toLocaleString();
    const tablesHtml = offlineDailySalesBuildCombinedTablesHtml(sections, showSectionLabel);
    const fontSize = paper === 'A5' ? '10px' : '12px';

    return `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>${offlineDailySalesEscapeHtml(title)}</title>
    <style>
        @page { size: ${paper} ${orient}; margin: ${margin}; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; padding: 0; margin: 0; color: #111; font-size: ${fontSize}; }
        .print-page { padding: ${margin === '0' ? '8mm' : '0'}; transform: scale(${scale}); transform-origin: top left; width: ${100 / scale}%; }
        .report-header { text-align: center; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #111; }
        h2 { margin: 0 0 4px; font-size: 20px; font-weight: 700; }
        .meta { margin-top: 4px; color: #444; font-size: 11px; }
        .report-section { margin-top: 20px; page-break-inside: avoid; }
        .report-section:first-of-type { margin-top: 0; }
        .report-section h3 { margin: 0 0 8px; font-size: 14px; font-weight: 700; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
    </style>
</head>
<body>
    <div class="print-page">
        <div class="report-header">
            <h2>${offlineDailySalesEscapeHtml(title)}</h2>
            <div class="meta">Period: ${offlineDailySalesEscapeHtml(dateRange)}</div>
            <div class="meta">Generated: ${offlineDailySalesEscapeHtml(generatedText)}</div>
        </div>
        ${tablesHtml}
    </div>
</body>
</html>`;
}

function offlineDailySalesOpenPrintDocument(title, sections, options = {}) {
    const popup = window.open('', '_blank');
    if (!popup) return;
    popup.document.write(offlineDailySalesBuildPrintDocument(title, sections, options));
    popup.document.close();
    popup.focus();
    popup.print();
}

function offlineDailySalesPrintTable(tableId, title) {
    // single-table: no section label (title already in h2)
    offlineDailySalesOpenPrintDocument(title || 'Report', [{ tableId, title: title || 'Report' }], { showSectionLabel: false });
}

function offlineDailySalesPrintAllTables() {
    offlineDailySalesOpenPrintDocument('Offline Daily Product Sold', offlineDailySalesAllSections);
}

function offlineDailySalesExportDocument(title, sections, filename) {
    const tablesHtml = offlineDailySalesBuildCombinedTablesHtml(sections);
    const excelHtml = `
        <html>
            <head><meta charset="UTF-8" /><title>${offlineDailySalesEscapeHtml(title)}</title></head>
            <body>${tablesHtml}</body>
        </html>`;

    const blob = new Blob([excelHtml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = (filename || 'export') + '.xls';
    link.click();
    URL.revokeObjectURL(url);
}

function offlineDailySalesExportTable(tableId, filename) {
    const section = offlineDailySalesAllSections.find((item) => item.tableId === tableId);
    offlineDailySalesExportDocument(
        section ? section.title : 'Report',
        [{ tableId, title: section ? section.title : 'Report' }],
        filename
    );
}

function offlineDailySalesPrintWithLayout() {
    // Collect selected tables
    const checkboxes = document.querySelectorAll('#printLayoutModal .form-check-input[type=checkbox]:checked');

    if (!checkboxes.length) {
        alert('Please select at least one table to print.');
        return;
    }

    const sections = [];
    checkboxes.forEach(cb => {
        const sec = offlineDailySalesAllSections.find(s => s.tableId === cb.value);
        if (sec) sections.push(sec);
    });

    const scaleInput = document.getElementById('plScale');
    const printOptions = {
        paper: document.getElementById('plPaperSize')?.value || 'letter',
        orient: document.getElementById('plLayout')?.value || 'landscape',
        margin: document.getElementById('plMargins')?.value || '0',
        scale: scaleInput ? scaleInput.value : 70,
        showSectionLabel: true,
    };

    offlineDailySalesOpenPrintDocument('Offline Daily Product Sold', sections, printOptions);
    bootstrap.Modal.getInstance(document.getElementById('printLayoutModal'))?.hide();
}


function offlineDailySalesExportAllTables() {
    offlineDailySalesExportDocument(
        'Offline Daily Product Sold',
        offlineDailySalesAllSections,
        'offline_daily_product_sold_<?= htmlspecialchars($exportDateSlug, ENT_QUOTES, 'UTF-8') ?>'
    );
}
</script>
<?php include __DIR__ . '/../layout/footer.php'; ?>
