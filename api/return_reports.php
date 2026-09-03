<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../scanner/storage.php';

$pdo = get_db_connection();
require_role_or_permission(
    ['admin'],
    'sr_return_report.view',
    'sr_product_return_report.view',
    'return_report.view',
    'product_return_report.view'
);

function return_report_date(mixed $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function return_report_optional_date(mixed $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function return_report_str(mixed $value): string
{
    return trim((string)$value);
}

function return_report_scanner_photo_url(mixed $value): string
{
    $path = trim((string)$value);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $resolved = function_exists('scanner_storage_resolve_public_url')
        ? scanner_storage_resolve_public_url($path)
        : $path;
    $resolved = trim(str_replace('\\', '/', $resolved));
    if ($resolved === '' || preg_match('#^https?://#i', $resolved) === 1 || str_starts_with($resolved, '/')) {
        return $resolved;
    }
    return '/OrderShadow/scanner/' . ltrim($resolved, '/');
}

function return_report_apply_search(array $rows, string $q): array
{
    if ($q === '') {
        return $rows;
    }
    $needle = strtolower($q);
    return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
        $text = implode(' ', [
            $row['inv'] ?? '',
            $row['customer_name'] ?? '',
            $row['phone'] ?? '',
            $row['delivery_by'] ?? '',
            $row['seller_name'] ?? '',
            $row['username'] ?? '',
            $row['printed_at'] ?? '',
            $row['printed_by_name'] ?? '',
            $row['inv_photo'] ?? '',
            $row['full_photo'] ?? '',
            $row['reason'] ?? '',
            $row['product_items'] ?? '',
        ]);
        return strpos(strtolower($text), $needle) !== false;
    }));
}

function return_report_combined_sql(): string
{
    return "
        SELECT *
        FROM (
            SELECT
                CONCAT('scanner_', ri.id) AS row_id,
                ri.id,
                o.id AS order_id,
                ri.inv,
                o.customer_name,
                ri.delivery_by,
                ri.reason,
                COALESCE(scanner_user.name, ri.username) AS username,
                ri.inv_photo,
                ri.full_photo,
                '' AS inv_photo_url,
                '' AS full_photo_url,
                ri.date_time,
                pj.printed_at,
                pj.printed_by_name,
                o.phone,
                o.total_amount,
                o.status AS order_status,
                'scanner' AS return_source,
                (
                    SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR '\n')
                    FROM orders o2
                    JOIN order_items oi ON oi.order_id = o2.id
                    JOIN products p ON p.id = oi.product_id
                    WHERE o2.order_code = ri.inv
                ) AS product_items,
                seller.name AS seller_name
            FROM return_items ri
            LEFT JOIN orders o ON ri.inv = o.order_code
            LEFT JOIN users scanner_user
              ON CONVERT(scanner_user.username USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(ri.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
              OR CONVERT(scanner_user.name USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(ri.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
              OR CONVERT(CAST(scanner_user.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(ri.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LEFT JOIN (
                SELECT
                    pjx.order_id,
                    MAX(pjx.printed_at) AS printed_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(COALESCE(u.name, u.username, CONCAT('User #', pjx.cashier_id)) ORDER BY pjx.printed_at DESC, pjx.id DESC SEPARATOR '\n'),
                        '\n',
                        1
                    ) AS printed_by_name
                FROM print_jobs pjx
                LEFT JOIN users u ON u.id = pjx.cashier_id
                WHERE pjx.printed_at IS NOT NULL
                GROUP BY pjx.order_id
            ) pj ON pj.order_id = o.id
            LEFT JOIN users seller ON seller.id = o.seller_id

            UNION ALL

            SELECT
                CONCAT('order_', o.id) AS row_id,
                NULL AS id,
                o.id AS order_id,
                o.order_code AS inv,
                o.customer_name,
                COALESCE(outi.delivery_by, '') AS delivery_by,
                o.return_note AS reason,
                COALESCE(updater.name, '') AS username,
                '' AS inv_photo,
                '' AS full_photo,
                '' AS inv_photo_url,
                '' AS full_photo_url,
                o.updated_at AS date_time,
                pj.printed_at,
                pj.printed_by_name,
                o.phone,
                o.total_amount,
                o.status AS order_status,
                'order_management' AS return_source,
                (
                    SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR '\n')
                    FROM order_items oi
                    JOIN products p ON p.id = oi.product_id
                    WHERE oi.order_id = o.id
                ) AS product_items,
                seller.name AS seller_name
            FROM orders o
            LEFT JOIN (
                SELECT inv, MAX(delivery_by) AS delivery_by
                FROM out_items
                WHERE delivery_by IS NOT NULL
                  AND delivery_by != ''
                GROUP BY inv
            ) outi ON outi.inv = o.order_code
            LEFT JOIN (
                SELECT
                    pjx.order_id,
                    MAX(pjx.printed_at) AS printed_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(COALESCE(u.name, u.username, CONCAT('User #', pjx.cashier_id)) ORDER BY pjx.printed_at DESC, pjx.id DESC SEPARATOR '\n'),
                        '\n',
                        1
                    ) AS printed_by_name
                FROM print_jobs pjx
                LEFT JOIN users u ON u.id = pjx.cashier_id
                WHERE pjx.printed_at IS NOT NULL
                GROUP BY pjx.order_id
            ) pj ON pj.order_id = o.id
            LEFT JOIN users updater ON updater.id = o.updated_by
            LEFT JOIN users seller ON seller.id = o.seller_id
            WHERE o.is_returned = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM return_items ri2
                  WHERE ri2.inv = o.order_code
              )
        ) returns_combined
        WHERE DATE(date_time) BETWEEN :start AND :end
    ";
}

$from = return_report_date($_GET['from'] ?? $_GET['start_date'] ?? null, date('Y-m-01'));
$to = return_report_date($_GET['to'] ?? $_GET['end_date'] ?? null, date('Y-m-d'));
$printFrom = return_report_optional_date($_GET['print_from'] ?? $_GET['printed_from'] ?? null);
$printTo = return_report_optional_date($_GET['print_to'] ?? $_GET['printed_to'] ?? null);
$delivery = return_report_str($_GET['delivery_by'] ?? $_GET['delivery_filter'] ?? '');
$q = return_report_str($_GET['q'] ?? '');

try {
    $params = [':start' => $from, ':end' => $to];
    $sql = return_report_combined_sql();
    if ($delivery !== '') {
        $sql .= ' AND delivery_by = :delivery';
        $params[':delivery'] = $delivery;
    }
    if ($printFrom !== '') {
        $sql .= ' AND printed_at IS NOT NULL AND DATE(printed_at) >= :print_from';
        $params[':print_from'] = $printFrom;
    }
    if ($printTo !== '') {
        $sql .= ' AND printed_at IS NOT NULL AND DATE(printed_at) <= :print_to';
        $params[':print_to'] = $printTo;
    }
    $sql .= ' ORDER BY date_time DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $returns = return_report_apply_search($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $q);
    foreach ($returns as &$returnRow) {
        $returnRow['inv_photo_url'] = return_report_scanner_photo_url($returnRow['inv_photo'] ?? '');
        $returnRow['full_photo_url'] = return_report_scanner_photo_url($returnRow['full_photo'] ?? '');
    }
    unset($returnRow);

    $summary = [
        'total_returns' => count($returns),
        'total_value' => 0.0,
        'scanner_returns' => 0,
        'order_management_returns' => 0,
    ];
    foreach ($returns as $row) {
        $summary['total_value'] += (float)($row['total_amount'] ?? 0);
        if (($row['return_source'] ?? '') === 'scanner') {
            $summary['scanner_returns']++;
        } else {
            $summary['order_management_returns']++;
        }
    }

    $roSub = "(
        SELECT o.id AS order_id, ri.date_time AS return_at, o.order_code, pj.printed_at
        FROM return_items ri
        JOIN orders o ON o.order_code = ri.inv
        LEFT JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            WHERE printed_at IS NOT NULL
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
        UNION ALL
        SELECT o.id AS order_id, o.updated_at AS return_at, o.order_code, pj.printed_at
        FROM orders o
        LEFT JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            WHERE printed_at IS NOT NULL
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
        WHERE COALESCE(o.is_returned, 0) = 1
          AND NOT EXISTS (SELECT 1 FROM return_items ri2 WHERE ri2.inv = o.order_code)
    )";

    $productParams = [':start' => $from, ':end' => $to];
    $productSql = "
        SELECT
          COALESCE(cp.id, p.id) AS product_id,
          COALESCE(cp.name, p.name) AS product_name,
          MAX(COALESCE(cp.product_type, p.product_type, 'normal')) AS product_type,
          SUM(oi.quantity * COALESCE(psi.quantity, 1)) AS total_qty,
          COUNT(DISTINCT ro.order_id) AS returns_count,
          MAX(DATE(ro.return_at)) AS last_return_date
        FROM {$roSub} ro
        JOIN order_items oi ON oi.order_id = ro.order_id
        JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_sets ps
          ON ps.set_name = p.name
         AND COALESCE(p.product_type, 'normal') = 'set'
        LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
        LEFT JOIN products cp ON cp.id = psi.product_id
        JOIN orders o ON o.id = ro.order_id
        WHERE DATE(ro.return_at) BETWEEN :start AND :end
    ";
    if ($delivery !== '') {
        $productSql .= " AND COALESCE((
            SELECT MAX(outi.delivery_by)
            FROM out_items outi
            WHERE outi.inv = o.order_code
              AND outi.delivery_by IS NOT NULL
              AND outi.delivery_by != ''
        ), '') = :delivery";
        $productParams[':delivery'] = $delivery;
    }
    if ($printFrom !== '') {
        $productSql .= ' AND ro.printed_at IS NOT NULL AND DATE(ro.printed_at) >= :print_from';
        $productParams[':print_from'] = $printFrom;
    }
    if ($printTo !== '') {
        $productSql .= ' AND ro.printed_at IS NOT NULL AND DATE(ro.printed_at) <= :print_to';
        $productParams[':print_to'] = $printTo;
    }
    $productSql .= "
        GROUP BY COALESCE(cp.id, p.id), COALESCE(cp.name, p.name)
        HAVING product_name LIKE :product_q
        ORDER BY total_qty DESC, product_name ASC
        LIMIT 500
    ";
    $productParams[':product_q'] = '%' . $q . '%';
    $productStmt = $pdo->prepare($productSql);
    $productStmt->execute($productParams);
    $products = $productStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dailyParams = [':start' => $from, ':end' => $to];
    $dailySql = "
        SELECT
          DATE(ro.return_at) AS return_date,
          SUM(oi.quantity) AS total_qty,
          COUNT(DISTINCT ro.order_id) AS returns_count
        FROM {$roSub} ro
        JOIN order_items oi ON oi.order_id = ro.order_id
        JOIN orders o ON o.id = ro.order_id
        WHERE DATE(ro.return_at) BETWEEN :start AND :end
    ";
    if ($delivery !== '') {
        $dailySql .= " AND COALESCE((
            SELECT MAX(outi.delivery_by)
            FROM out_items outi
            WHERE outi.inv = o.order_code
              AND outi.delivery_by IS NOT NULL
              AND outi.delivery_by != ''
        ), '') = :delivery";
        $dailyParams[':delivery'] = $delivery;
    }
    if ($printFrom !== '') {
        $dailySql .= ' AND ro.printed_at IS NOT NULL AND DATE(ro.printed_at) >= :print_from';
        $dailyParams[':print_from'] = $printFrom;
    }
    if ($printTo !== '') {
        $dailySql .= ' AND ro.printed_at IS NOT NULL AND DATE(ro.printed_at) <= :print_to';
        $dailyParams[':print_to'] = $printTo;
    }
    $dailySql .= ' GROUP BY DATE(ro.return_at) ORDER BY return_date DESC';
    $dailyStmt = $pdo->prepare($dailySql);
    $dailyStmt->execute($dailyParams);
    $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $deliveryOptions = $pdo->query("
        SELECT DISTINCT delivery_by AS value, delivery_by AS label
        FROM (
            SELECT delivery_by FROM return_items WHERE delivery_by IS NOT NULL AND delivery_by <> ''
            UNION
            SELECT delivery_by FROM out_items WHERE delivery_by IS NOT NULL AND delivery_by <> ''
        ) delivery_options
        ORDER BY delivery_by
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    api_json([
        'success' => true,
        'filters' => ['from' => $from, 'to' => $to, 'print_from' => $printFrom, 'print_to' => $printTo, 'delivery_by' => $delivery, 'q' => $q],
        'summary' => $summary,
        'returns' => $returns,
        'products' => $products,
        'daily' => $daily,
        'delivery_options' => $deliveryOptions,
    ]);
} catch (Throwable $e) {
    error_log('return_reports API error: ' . $e->getMessage());
    api_error('Unable to load return reports.', 500);
}
