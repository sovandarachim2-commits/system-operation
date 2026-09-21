<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'sr_sales_dashboard.view', 'sr_daily_offline_sale.view', 'sr_offline_buy_report.view', 'offline_team_performance.view', 'offline_sellers.manage');
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

function report_payment_status(string $key): string
{
    $value = strtolower(trim((string)($_GET[$key] ?? '')));
    return in_array($value, ['paid', 'partial', 'unpaid'], true) ? $value : '';
}

function report_offline_paid_expr(): string
{
    $paymentTotal = "(SELECT COALESCE(SUM(COALESCE(osp.amount, 0)), 0) FROM offline_sale_payments osp WHERE osp.order_id = o.id)";
    return "CASE
        WHEN {$paymentTotal} > 0 THEN LEAST({$paymentTotal}, COALESCE(o.total_amount, 0))
        WHEN COALESCE(o.received_amount, 0) > 0 THEN LEAST(COALESCE(o.received_amount, 0), COALESCE(o.total_amount, 0))
        WHEN LOWER(COALESCE(o.status, '')) = 'paid' THEN COALESCE(o.total_amount, 0)
        ELSE 0
    END";
}

function report_offline_payment_filter_sql(string $status): string
{
    $paid = report_offline_paid_expr();
    if ($status === 'paid') {
        return "(COALESCE(o.total_amount, 0) <= 0 OR {$paid} >= COALESCE(o.total_amount, 0) - 0.009)";
    }
    if ($status === 'partial') {
        return "(COALESCE(o.total_amount, 0) > 0 AND {$paid} > 0 AND {$paid} < COALESCE(o.total_amount, 0) - 0.009)";
    }
    if ($status === 'unpaid') {
        return "(COALESCE(o.total_amount, 0) > 0 AND {$paid} <= 0)";
    }
    return '';
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
    $paymentStatus = report_payment_status('payment_status');
    $sellerId = report_int('seller_id');

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
    if ($sellerId !== null) {
        $where[] = 'o.offline_seller_id = ?';
        $params[] = $sellerId;
    }
    $paymentFilter = report_offline_payment_filter_sql($paymentStatus);
    if ($paymentFilter !== '') {
        $where[] = $paymentFilter;
    }

    $whereSql = implode(' AND ', $where);
    $paidExpr = report_offline_paid_expr();

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) AS order_count,
            COUNT(DISTINCT o.team_id) AS team_count,
            COUNT(DISTINCT o.offline_seller_id) AS seller_count,
            COALESCE(SUM(o.subtotal), 0) AS total_sales,
            COALESCE(SUM(o.purchase_total), 0) AS total_purchase,
            COALESCE(SUM(o.discount), 0) AS total_discount,
            COALESCE(SUM(GREATEST(COALESCE(o.purchase_total, 0) + COALESCE(o.discount, 0) - COALESCE(o.subtotal, 0), 0)), 0) AS shop_pay_back_total,
            COALESCE(SUM(o.total_amount), 0) AS net_total,
            COALESCE(SUM({$paidExpr}), 0) AS paid_total,
            COALESCE(SUM(GREATEST(o.total_amount - {$paidExpr}, 0)), 0) AS unpaid_total
        FROM offline_sale_orders o
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['gross_profit'] = (float)($summary['total_sales'] ?? 0) - (float)($summary['total_purchase'] ?? 0);
    $summary['collection_rate'] = (float)($summary['net_total'] ?? 0) > 0
        ? round(((float)($summary['paid_total'] ?? 0) / (float)($summary['net_total'] ?? 0)) * 100, 2)
        : 0.0;

    $teamRowsStmt = $pdo->prepare("
        SELECT
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            t.code AS team_code,
            t.area_route AS area_route,
            leader.id AS leader_id,
            leader.name AS leader_name,
            COUNT(DISTINCT o.id) AS order_count,
            COUNT(DISTINCT o.offline_seller_id) AS active_sellers,
            (
                SELECT COUNT(*) FROM offline_sellers ms
                WHERE ms.team_id = t.id AND ms.is_active = 1
            ) AS total_members,
            COALESCE(SUM(o.subtotal), 0) AS total_sales,
            COALESCE(SUM(o.purchase_total), 0) AS total_purchase,
            COALESCE(SUM(o.discount), 0) AS total_discount,
            COALESCE(SUM(GREATEST(COALESCE(o.purchase_total, 0) + COALESCE(o.discount, 0) - COALESCE(o.subtotal, 0), 0)), 0) AS shop_pay_back,
            COALESCE(SUM(o.total_amount), 0) AS net_total,
            COALESCE(SUM({$paidExpr}), 0) AS paid_total,
            COALESCE(SUM(GREATEST(o.total_amount - {$paidExpr}, 0)), 0) AS unpaid_total,
            MAX(DATE(o.sale_date)) AS last_order_date,
            MAX(o.updated_at) AS last_update
        FROM offline_sale_orders o
        LEFT JOIN offline_teams t ON t.id = o.team_id
        LEFT JOIN offline_sellers leader ON leader.id = t.leader_id
        WHERE {$whereSql}
        GROUP BY
            COALESCE(t.id, 0),
            COALESCE(t.name, 'Unassigned'),
            t.code,
            t.area_route,
            leader.id,
            leader.name
        ORDER BY net_total DESC, team_name ASC
    ");
    $teamRowsStmt->execute($params);
    $teamRows = $teamRowsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teamRows as &$team) {
        $sales = (float)($team['total_sales'] ?? 0);
        $purchase = (float)($team['total_purchase'] ?? 0);
        $net = (float)($team['net_total'] ?? 0);
        $paid = (float)($team['paid_total'] ?? 0);
        $team['gross_profit'] = $sales - $purchase;
        $team['avg_order_value'] = (int)($team['order_count'] ?? 0) > 0 ? $net / (int)($team['order_count'] ?? 0) : 0;
        $team['collection_rate'] = $net > 0 ? round(($paid / $net) * 100, 2) : 0;
    }
    unset($team);

    $sellerRowsStmt = $pdo->prepare("
        SELECT
            s.id AS seller_id,
            s.name AS seller_name,
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            s.is_active AS is_active,
            COALESCE(leader.id = s.id, 0) AS is_leader,
            COUNT(DISTINCT o.id) AS order_count,
            COALESCE(SUM(o.subtotal), 0) AS total_sales,
            COALESCE(SUM(o.purchase_total), 0) AS total_purchase,
            COALESCE(SUM(o.discount), 0) AS total_discount,
            COALESCE(SUM(GREATEST(COALESCE(o.purchase_total, 0) + COALESCE(o.discount, 0) - COALESCE(o.subtotal, 0), 0)), 0) AS shop_pay_back,
            COALESCE(SUM(o.total_amount), 0) AS net_total,
            COALESCE(SUM({$paidExpr}), 0) AS paid_total,
            COALESCE(SUM(GREATEST(o.total_amount - {$paidExpr}, 0)), 0) AS unpaid_total,
            MAX(DATE(o.sale_date)) AS last_order_date,
            MAX(o.updated_at) AS last_update
        FROM offline_sale_orders o
        LEFT JOIN offline_sellers s ON s.id = o.offline_seller_id
        LEFT JOIN offline_teams t ON t.id = o.team_id OR t.id = s.team_id
        LEFT JOIN offline_sellers leader ON leader.id = t.leader_id
        WHERE {$whereSql}
        GROUP BY
            s.id,
            s.name,
            COALESCE(t.id, 0),
            COALESCE(t.name, 'Unassigned'),
            s.is_active,
            COALESCE(leader.id = s.id, 0)
        UNION ALL
        SELECT
            s.id AS seller_id,
            s.name AS seller_name,
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            s.is_active AS is_active,
            COALESCE(leader.id = s.id, 0) AS is_leader,
            0 AS order_count,
            0 AS total_sales,
            0 AS total_purchase,
            0 AS total_discount,
            0 AS shop_pay_back,
            0 AS net_total,
            0 AS paid_total,
            0 AS unpaid_total,
            NULL AS last_order_date,
            NULL AS last_update
        FROM offline_sellers s
        LEFT JOIN offline_teams t ON t.id = s.team_id
        LEFT JOIN offline_sellers leader ON leader.id = t.leader_id
        WHERE s.is_active = 1
          AND NOT EXISTS (
              SELECT 1 FROM offline_sale_orders o_match
              WHERE o_match.offline_seller_id = s.id
                AND DATE(o_match.sale_date) >= ?
                AND DATE(o_match.sale_date) <= ?
                AND LOWER(COALESCE(o_match.status, '')) NOT IN ('cancelled', 'canceled')
          )
        ORDER BY net_total DESC, seller_name ASC
    ");
    $sellerParams = $params;
    $sellerParams[] = $from;
    $sellerParams[] = $to;
    $sellerRowsStmt->execute($sellerParams);
    $sellerRowsByKey = [];
    foreach ($sellerRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)($row['seller_id'] ?? 0);
        if ($key <= 0) {
            continue;
        }
        if (!isset($sellerRowsByKey[$key])) {
            $sellerRowsByKey[$key] = $row;
        } else {
            $prev = $sellerRowsByKey[$key];
            $merged = $row;
            foreach (['order_count' => 'int', 'total_sales' => 'float', 'total_purchase' => 'float', 'total_discount' => 'float', 'shop_pay_back' => 'float', 'net_total' => 'float', 'paid_total' => 'float', 'unpaid_total' => 'float'] as $field => $cast) {
                $a = $cast === 'int' ? (int)($prev[$field] ?? 0) : (float)($prev[$field] ?? 0);
                $b = $cast === 'int' ? (int)($row[$field] ?? 0) : (float)($row[$field] ?? 0);
                $merged[$field] = $a + $b;
            }
            $merged['last_order_date'] = $row['last_order_date'] ?? $prev['last_order_date'] ?? null;
            $merged['last_update'] = $row['last_update'] ?? $prev['last_update'] ?? null;
            $sellerRowsByKey[$key] = $merged;
        }
    }
    $sellerRows = array_values($sellerRowsByKey);
    foreach ($sellerRows as &$seller) {
        $sales = (float)($seller['total_sales'] ?? 0);
        $purchase = (float)($seller['total_purchase'] ?? 0);
        $net = (float)($seller['net_total'] ?? 0);
        $paid = (float)($seller['paid_total'] ?? 0);
        $orders = (int)($seller['order_count'] ?? 0);
        $seller['gross_profit'] = $sales - $purchase;
        $seller['avg_order_value'] = $orders > 0 ? $net / $orders : 0;
        $seller['collection_rate'] = $net > 0 ? round(($paid / $net) * 100, 2) : 0;
        $seller['has_activity'] = $orders > 0 || $net > 0;
    }
    unset($seller);
    usort($sellerRows, static function (array $a, array $b): int {
        $netA = (float)($a['net_total'] ?? 0);
        $netB = (float)($b['net_total'] ?? 0);
        if (abs($netA - $netB) > 0.009) {
            return $netA < $netB ? 1 : -1;
        }
        return strcasecmp((string)($a['seller_name'] ?? ''), (string)($b['seller_name'] ?? ''));
    });

    $teams = $pdo->query("SELECT id AS value, name AS label FROM offline_teams WHERE is_active = 1 ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $sellers = $pdo->query("
        SELECT
            s.id AS value,
            CONCAT(s.name, ' (', COALESCE(t.name, 'Unassigned'), ')') AS label
        FROM offline_sellers s
        LEFT JOIN offline_teams t ON t.id = s.team_id
        WHERE s.is_active = 1
        ORDER BY s.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $locations = $pdo->query("
        SELECT id AS value, COALESCE(location_name, location_code) AS label
        FROM storage_locations
        WHERE is_active = 1
        ORDER BY is_offline_location DESC, location_code, location_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'success' => true,
        'filters' => [
            'from' => $from,
            'to' => $to,
            'team_id' => $teamId,
            'team_ids' => $teamIds,
            'location_id' => $locationId,
            'payment_status' => $paymentStatus,
            'seller_id' => $sellerId,
        ],
        'summary' => $summary,
        'team_rows' => $teamRows,
        'seller_rows' => $sellerRows,
        'options' => [
            'teams' => $teams,
            'sellers' => $sellers,
            'locations' => $locations,
        ],
    ]);
} catch (Throwable $e) {
    error_log('offline_team_performance API error: ' . $e->getMessage());
    api_error('Unable to load offline team performance report.', 500);
}
