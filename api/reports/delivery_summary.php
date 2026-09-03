<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(
    ['admin'],
    'sr_delivery_summary.view',
    'sr_sales_dashboard.view',
    'daily_summary.view',
    'order_management.view',
    'payment_management.view'
);

function delivery_summary_date(?string $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
}

try {
    $pdo = get_db_connection();

    $from = delivery_summary_date($_GET['from'] ?? '');
    $to   = delivery_summary_date($_GET['to'] ?? $from);

    $deliveredStmt = $pdo->prepare('
        SELECT
            o.delivery_by AS delivery_by,
            COUNT(DISTINCT o.inv) AS order_count,
            COUNT(*) AS item_count
        FROM out_items o
        WHERE DATE(o.date_time) BETWEEN ? AND ?
            AND o.delivery_by IS NOT NULL
            AND o.delivery_by != ""
        GROUP BY o.delivery_by
        ORDER BY order_count DESC, delivery_by
    ');
    $deliveredStmt->execute([$from, $to]);
    $delivered = $deliveredStmt->fetchAll(PDO::FETCH_ASSOC);

    $notDeliveredStmt = $pdo->prepare('
        SELECT
            COUNT(DISTINCT p.inv) AS order_count
        FROM product_entries p
        LEFT JOIN (
            SELECT inv, MAX(id) AS mid
            FROM out_items
            GROUP BY inv
        ) o_latest ON o_latest.inv = p.inv
        LEFT JOIN out_items o ON o.id = o_latest.mid
        WHERE DATE(p.datetime) BETWEEN ? AND ?
            AND (o.delivery_by IS NULL OR o.delivery_by = "")
    ');
    $notDeliveredStmt->execute([$from, $to]);
    $notDelivered = (int)($notDeliveredStmt->fetchColumn() ?: 0);

    $deliveredTotal = (int)array_sum(array_column($delivered, 'order_count'));

    api_json([
        'success' => true,
        'range' => ['from' => $from, 'to' => $to],
        'delivery' => $delivered,
        'not_delivered' => $notDelivered,
        'total' => $deliveredTotal + $notDelivered,
    ]);
} catch (Throwable $e) {
    api_json(['success' => false, 'error' => 'Unable to load delivery summary.'], 500);
}
