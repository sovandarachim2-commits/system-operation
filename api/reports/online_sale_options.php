<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';

require_role_or_permission(['admin', 'seller'], 'seller_orders.create', 'sr_orders.create');

function online_sale_option_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $pdo = get_db_connection();
    $currentMonth = date('Y-m');

    api_json([
        'success' => true,
        'options' => [
            'products' => online_sale_option_rows($pdo, "
                SELECT
                    p.id AS value,
                    CONCAT(p.name, CASE WHEN p.sku IS NULL OR p.sku = '' THEN '' ELSE CONCAT(' (', p.sku, ')') END) AS label,
                    COALESCE(pc.selling_price, p.cost, 0) AS price,
                    CASE
                        WHEN COALESCE(pc.original_cost, 0) > 0
                          OR COALESCE(pc.supplier_cost, 0) > 0
                          OR COALESCE(pc.shipping_cost, 0) > 0
                          OR COALESCE(pc.other_costs, 0) > 0
                        THEN 1 ELSE 0
                    END AS has_costs,
                    COALESCE(p.brand_id, 0) AS brand_id
                FROM products p
                LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = ?
                WHERE p.active = 1
                ORDER BY p.name
                LIMIT 1000
            ", [$currentMonth]),
            'pages' => online_sale_option_rows($pdo, "
                SELECT id AS value, name AS label
                FROM pages
                ORDER BY name
                LIMIT 300
            "),
            'brands' => online_sale_option_rows($pdo, "
                SELECT id AS value, name AS label, color AS brand_color
                FROM brands
                WHERE active = 1
                ORDER BY name
                LIMIT 300
            "),
            'delivery_types' => online_sale_option_rows($pdo, "
                SELECT id AS value, name AS label
                FROM delivery_types
                ORDER BY name
                LIMIT 300
            "),
            'delivery_costs' => online_sale_option_rows($pdo, "
                SELECT id AS value, CONCAT(label, ' - $', FORMAT(amount, 2)) AS label, amount
                FROM delivery_costs
                ORDER BY amount, label
                LIMIT 300
            "),
            'payment_methods' => online_sale_option_rows($pdo, "
                SELECT option_text AS value, option_text AS label
                FROM note_options
                WHERE is_active = 1 AND (is_seller_active = 1 OR is_admin_active = 1)
                ORDER BY sort_order, option_text
                LIMIT 200
            "),
        ],
    ]);
} catch (Throwable $e) {
    error_log('online_sale_options API error: ' . $e->getMessage());
    api_error('Unable to load online sale options.', 500);
}
