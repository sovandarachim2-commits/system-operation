<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'sr_sales_dashboard.view', 'sr_financial_summary.view', 'sr_income_statement.view', 'sr_orders.view', 'sr_sold_products.view', 'financial_summary.view', 'daily_summary.view');

function sales_api_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function sales_api_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

try {
    $pdo = get_db_connection();
    $limit = sales_api_int('limit', 100, 1, 1000000);
    $offset = sales_api_int('offset', 0, 0, 1000000);
    $q = trim((string)($_GET['q'] ?? ''));
    $from = sales_api_date($_GET['from'] ?? null);
    $to = sales_api_date($_GET['to'] ?? null);
    $branch = trim((string)($_GET['branch'] ?? ''));
    $sellerId = filter_var($_GET['seller_id'] ?? null, FILTER_VALIDATE_INT);
    $customer = trim((string)($_GET['customer'] ?? ''));
    $payment = strtolower(trim((string)($_GET['payment'] ?? '')));
    $paymentMethod = trim((string)($_GET['payment_method'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $deliveryBy = trim((string)($_GET['delivery_by'] ?? ''));
    $productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
    $brandId = filter_var($_GET['brand_id'] ?? null, FILTER_VALIDATE_INT);
    $dateBasis = strtolower(trim((string)($_GET['date_basis'] ?? 'order')));
    if ($dateBasis === 'payment') {
        $dateColumn = "CASE
            WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid'
                THEN COALESCE(o.payment_date, DATE(o.updated_at), DATE(pj.printed_at), DATE(o.created_at))
            ELSE COALESCE(DATE(pj.printed_at), DATE(o.created_at))
        END";
    } elseif (in_array($dateBasis, ['printed', 'activity'], true)) {
        $dateColumn = 'COALESCE(pj.printed_at, o.created_at)';
    } else {
        $dateColumn = 'o.created_at';
    }
    $sort = (string)($_GET['sort'] ?? 'order_date');
    $direction = strtolower((string)($_GET['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $sortMap = [
        'order_date' => $dateColumn,
        'order_code' => 'o.order_code',
        'customer' => 'o.customer_name',
        'seller' => 'seller',
        'payment_method' => 'o.payment_method',
        'payment_status' => 'payment_status',
        'print_status' => 'print_status',
        'paid_date' => 'paid_at',
        'discount' => 'o.discount',
        'total_amount' => 'o.total_amount',
        'status' => 'order_status',
    ];
    $orderBy = $sortMap[$sort] ?? 'o.created_at';

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(o.order_code LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR o.payment_method LIKE ? OR o.location LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($from !== null) {
        $where[] = "DATE({$dateColumn}) >= ?";
        $params[] = $from;
    }
    if ($to !== null) {
        $where[] = "DATE({$dateColumn}) <= ?";
        $params[] = $to;
    }
    if ($branch !== '') {
        $where[] = 'o.location = ?';
        $params[] = $branch;
    }
    if ($sellerId !== false && $sellerId !== null) {
        $where[] = 'o.seller_id = ?';
        $params[] = (int)$sellerId;
    }
    if ($customer !== '') {
        $where[] = 'o.customer_name LIKE ?';
        $params[] = '%' . $customer . '%';
    }
    if (in_array($payment, ['paid', 'unpaid'], true)) {
        $where[] = $payment === 'paid'
            ? '(COALESCE(o.is_paid, 0) = 1 OR o.status = "paid")'
            : 'NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid")';
    }
    if ($paymentMethod !== '') {
        if ($paymentMethod === '__empty__') {
            $where[] = "(o.payment_method IS NULL OR o.payment_method = '')";
        } else {
            $where[] = 'o.payment_method = ?';
            $params[] = $paymentMethod;
        }
    }
    if ($status === 'cancelled') {
        $where[] = 'COALESCE(o.is_cancelled, 0) = 1';
    } elseif ($status === 'returned') {
        $where[] = 'COALESCE(o.is_returned, 0) = 1';
    } elseif ($status === 'paid') {
        $where[] = 'COALESCE(o.is_cancelled, 0) = 0 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid")';
    } elseif ($status === 'unpaid') {
        $where[] = 'COALESCE(o.is_cancelled, 0) = 0 AND NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid")';
    } elseif ($status === 'printed') {
        $where[] = 'pj.printed_at IS NOT NULL';
    } elseif ($status === 'not_printed') {
        $where[] = 'pj.printed_at IS NULL';
    } elseif ($status === 'active') {
        $where[] = 'COALESCE(o.is_cancelled, 0) = 0';
    }
    if ($deliveryBy !== '') {
        if ($deliveryBy === 'not_delivered') {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM out_items oi_delivery
                WHERE oi_delivery.inv = o.order_code
                  AND oi_delivery.delivery_by IS NOT NULL
                  AND oi_delivery.delivery_by != ''
            )";
        } else {
            $where[] = "EXISTS (
                SELECT 1
                FROM out_items oi_delivery
                WHERE oi_delivery.inv = o.order_code
                  AND oi_delivery.delivery_by = ?
            )";
            $params[] = $deliveryBy;
        }
    }
    if ($productId !== false && $productId !== null) {
        $where[] = 'EXISTS (
            SELECT 1
            FROM order_items filter_oi
            WHERE filter_oi.order_id = o.id
              AND filter_oi.product_id = ?
        )';
        $params[] = (int)$productId;
    }
    if ($brandId !== false && $brandId !== null) {
        $where[] = 'EXISTS (
            SELECT 1
            FROM order_items filter_oi
            JOIN products filter_p ON filter_p.id = filter_oi.product_id
            WHERE filter_oi.order_id = o.id
              AND filter_p.brand_id = ?
        )';
        $params[] = (int)$brandId;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $printJoinSql = "
        LEFT JOIN (
            SELECT order_id, MAX(printed_at) AS printed_at
            FROM print_jobs
            GROUP BY order_id
        ) pj ON pj.order_id = o.id
    ";
    $sellerJoinSql = "LEFT JOIN users u ON u.id = o.seller_id";

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN COALESCE(o.is_cancelled, 0) = 0 THEN o.total_amount ELSE 0 END), 0) AS total_revenue,
            SUM(CASE WHEN COALESCE(o.is_cancelled, 0) = 0 AND (COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid') THEN 1 ELSE 0 END) AS paid_orders,
            SUM(CASE WHEN COALESCE(o.is_cancelled, 0) = 1 THEN 1 ELSE 0 END) AS cancelled_orders
        FROM orders o
        {$sellerJoinSql}
        {$printJoinSql}
        {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o {$sellerJoinSql} {$printJoinSql} {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_edit_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $auditTableError) {
        error_log('sales_orders audit table warning: ' . $auditTableError->getMessage());
    }

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.created_at AS order_date,
            o.order_code,
            o.seller_id AS created_by,
            COALESCE(o.customer_name, '') AS customer_name,
            COALESCE(o.phone, '') AS customer_phone,
            COALESCE(o.location, '') AS branch,
            COALESCE(NULLIF(u.name, ''), u.username, 'Shadow Shop') AS seller,
            COALESCE(NULLIF(u.name, ''), u.username, 'Shadow Shop') AS created_by_name,
            COALESCE(dt.name, '') AS delivery_type_name,
            COALESCE(dc.label, '') AS delivery_cost_label,
            COALESCE(dc.amount, 0) AS delivery_cost_amount,
            COALESCE((
                SELECT MAX(oi2.delivery_by)
                FROM out_items oi2
                WHERE oi2.inv = o.order_code
                  AND oi2.delivery_by IS NOT NULL
                  AND oi2.delivery_by != ''
            ), '') AS delivery_by,
            COALESCE(o.payment_method, '') AS payment_method,
            o.payment_date,
            CASE
                WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN COALESCE(
                    CASE
                        WHEN o.payment_date IS NOT NULL AND o.updated_at IS NOT NULL THEN CONCAT(o.payment_date, ' ', TIME(o.updated_at))
                        ELSE NULL
                    END,
                    o.payment_date,
                    o.updated_at
                )
                ELSE NULL
            END AS paid_at,
            CASE
                WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid'
                    THEN COALESCE(o.payment_date, DATE(o.updated_at), DATE(pj.printed_at), DATE(o.created_at))
                ELSE COALESCE(DATE(pj.printed_at), DATE(o.created_at))
            END AS payment_activity_date,
            COALESCE(o.discount, 0) AS discount,
            COALESCE(o.total_amount, 0) AS total_amount,
            COALESCE(oi_agg.item_count, 0) AS item_count,
            COALESCE(oi_agg.total_items, 0) AS total_items,
            COALESCE(oi_agg.product_names, '') AS product_names,
            COALESCE(oi_agg.product_lines, '') AS product_lines,
            CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN 'Paid' ELSE 'Unpaid' END AS payment_status,
            CASE
                WHEN COALESCE(o.is_cancelled, 0) = 1 THEN 'Cancel'
                WHEN COALESCE(o.is_returned, 0) = 1 THEN 'Return'
                WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN 'Paid'
                ELSE 'Unpaid'
            END AS order_status,
            CASE WHEN pj.printed_at IS NULL THEN 'Not Printed' ELSE 'Printed' END AS print_status,
            pj.printed_at,
            COALESCE(pj.printed_at, o.created_at) AS activity_date,
            o.updated_at,
            o.updated_by,
            COALESCE(NULLIF(updater.name, ''), updater.username, '') AS updated_by_name,
            COALESCE(audit_latest.action, '') AS latest_audit_action,
            COALESCE(audit_latest.details, '') AS latest_audit_details,
            audit_latest.created_at AS latest_audit_at,
            COALESCE(NULLIF(audit_latest.user_name, ''), NULLIF(audit_user.name, ''), audit_user.username, '') AS latest_audit_by_name,
            COALESCE(o.paid_note, '') AS paid_note,
            COALESCE(o.cancel_note, '') AS cancel_note,
            COALESCE(o.return_note, '') AS return_note,
            COALESCE(o.is_paid, 0) AS is_paid,
            COALESCE(o.is_cancelled, 0) AS is_cancelled,
            COALESCE(o.is_returned, 0) AS is_returned
        FROM orders o
        LEFT JOIN users u ON u.id = o.seller_id
        LEFT JOIN delivery_types dt ON dt.id = o.delivery_type_id
        LEFT JOIN delivery_costs dc ON dc.id = o.delivery_cost_id
        LEFT JOIN (
            SELECT
                oi.order_id,
                COUNT(*) AS item_count,
                SUM(
                    CASE
                        WHEN COALESCE(p.product_type, 'normal') = 'set'
                            THEN oi.quantity * COALESCE(psi.quantity, 1)
                        ELSE oi.quantity
                    END
                ) AS total_items,
                GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS product_names,
                GROUP_CONCAT(
                    DISTINCT CONCAT(p.name, ' x', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(oi.quantity, 2))))
                    ORDER BY p.name
                    SEPARATOR '||'
                ) AS product_lines
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_sets ps
                ON ps.set_name = p.name
               AND COALESCE(p.product_type, 'normal') = 'set'
            LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
            GROUP BY oi.order_id
        ) oi_agg ON oi_agg.order_id = o.id
        {$printJoinSql}
        LEFT JOIN users updater ON updater.id = o.updated_by
        LEFT JOIN (
            SELECT a.*
            FROM order_edit_audit a
            INNER JOIN (
                SELECT order_id, MAX(id) AS max_id
                FROM order_edit_audit
                GROUP BY order_id
            ) latest ON latest.max_id = a.id
        ) audit_latest ON audit_latest.order_id = o.id
        LEFT JOIN users audit_user ON audit_user.id = audit_latest.user_id
        {$whereSql}
        ORDER BY {$orderBy} {$direction}, o.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);

    api_json([
        'success' => true,
        'summary' => [
            'total_orders' => (int)($summary['total_orders'] ?? 0),
            'total_revenue' => (float)($summary['total_revenue'] ?? 0),
            'paid_orders' => (int)($summary['paid_orders'] ?? 0),
            'cancelled_orders' => (int)($summary['cancelled_orders'] ?? 0),
        ],
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total_rows' => $totalRows,
            'has_more' => ($offset + $limit) < $totalRows,
        ],
    ]);
} catch (Throwable $e) {
    error_log('sales_orders API error: ' . $e->getMessage());
    api_error('Unable to load sales orders.', 500);
}
