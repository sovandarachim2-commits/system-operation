<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../admin/offline_lib.php';
require_once __DIR__ . '/product_costing_lib.php';

function opc_month(?string $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function opc_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function opc_request_range(): array
{
    $month = opc_month($_GET['month'] ?? null);
    $defaultFrom = $month . '-01';
    $defaultTo = date('Y-m-t', strtotime($defaultFrom) ?: time());
    $from = opc_date($_GET['from'] ?? null, $defaultFrom);
    $to = opc_date($_GET['to'] ?? null, $defaultTo);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $costMonth = opc_month($_GET['month'] ?? substr($to, 0, 7));
    return [$from, $to, $costMonth];
}

function opc_num(mixed $value): float
{
    $number = (float)$value;
    return is_finite($number) ? $number : 0.0;
}

function opc_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function opc_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            month_year VARCHAR(7) NOT NULL,
            selling_price DECIMAL(10,2) DEFAULT 0,
            original_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            supplier_cost DECIMAL(10,2) DEFAULT NULL,
            shipping_cost DECIMAL(10,2) DEFAULT NULL,
            other_costs DECIMAL(10,2) DEFAULT NULL,
            marketing_cost DECIMAL(10,2) DEFAULT 0,
            commission_rate DECIMAL(5,2) DEFAULT 0,
            commission_amount DECIMAL(10,2) DEFAULT 0,
            total_cost DECIMAL(10,2) GENERATED ALWAYS AS (original_cost + COALESCE(supplier_cost, 0) + COALESCE(shipping_cost, 0) + COALESCE(other_costs, 0) + COALESCE(marketing_cost, 0)) STORED,
            cost_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT DEFAULT NULL,
            notes TEXT,
            UNIQUE KEY uk_product_month (product_id, month_year)
        )
    ");
    pc_ensure_auto_increment_id($pdo, 'product_costs');

    $columns = [
        'selling_price' => "ALTER TABLE product_costs ADD COLUMN selling_price DECIMAL(10,2) DEFAULT 0 AFTER month_year",
        'original_cost' => "ALTER TABLE product_costs ADD COLUMN original_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER selling_price",
        'supplier_cost' => "ALTER TABLE product_costs ADD COLUMN supplier_cost DECIMAL(10,2) DEFAULT NULL AFTER original_cost",
        'shipping_cost' => "ALTER TABLE product_costs ADD COLUMN shipping_cost DECIMAL(10,2) DEFAULT NULL AFTER supplier_cost",
        'other_costs' => "ALTER TABLE product_costs ADD COLUMN other_costs DECIMAL(10,2) DEFAULT NULL AFTER shipping_cost",
        'marketing_cost' => "ALTER TABLE product_costs ADD COLUMN marketing_cost DECIMAL(10,2) DEFAULT 0 AFTER other_costs",
        'commission_rate' => "ALTER TABLE product_costs ADD COLUMN commission_rate DECIMAL(5,2) DEFAULT 0 AFTER marketing_cost",
        'commission_amount' => "ALTER TABLE product_costs ADD COLUMN commission_amount DECIMAL(10,2) DEFAULT 0 AFTER commission_rate",
        'offline_commission_amount' => "ALTER TABLE product_costs ADD COLUMN offline_commission_amount DECIMAL(10,2) DEFAULT 0 AFTER commission_amount",
        'total_cost' => "ALTER TABLE product_costs ADD COLUMN total_cost DECIMAL(10,2) GENERATED ALWAYS AS (original_cost + COALESCE(supplier_cost, 0) + COALESCE(shipping_cost, 0) + COALESCE(other_costs, 0) + COALESCE(marketing_cost, 0)) STORED AFTER commission_amount",
        'cost_updated_at' => "ALTER TABLE product_costs ADD COLUMN cost_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'updated_by' => "ALTER TABLE product_costs ADD COLUMN updated_by INT DEFAULT NULL",
        'notes' => "ALTER TABLE product_costs ADD COLUMN notes TEXT",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM product_costs LIKE " . $pdo->quote($column));
        if ($stmt && $stmt->rowCount() === 0) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // Generated total_cost may already exist after commission_amount; still add offline commission.
                if ($column === 'offline_commission_amount') {
                    $pdo->exec("ALTER TABLE product_costs ADD COLUMN offline_commission_amount DECIMAL(10,2) DEFAULT 0");
                } else {
                    throw $e;
                }
            }
        }
    }
}

function opc_load(PDO $pdo): void
{
    [$from, $to, $month] = opc_request_range();
    $q = trim((string)($_GET['q'] ?? ''));
    $brandId = filter_var($_GET['brand_id'] ?? null, FILTER_VALIDATE_INT);
    $teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT);
    $summaryOnly = (string)($_GET['summary_only'] ?? '') === '1';
    if ($summaryOnly) {
        require_role_or_permission(['admin'], 'sr_offline_product_costing.view', 'sr_product_costing.view', 'sr_income_statement.view', 'product_costs.view');
    } else {
        require_role_or_permission(['admin'], 'sr_offline_product_costing.view', 'sr_product_costing.view', 'product_costs.view');
    }
    offline_ensure_schema($pdo);
    if (!$summaryOnly) {
        pc_history_ensure_schema($pdo);
    }

    $brands = $pdo->query("
        SELECT id AS value, name AS label, color AS brand_color
        FROM brands
        WHERE active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $teams = $pdo->query("
        SELECT id AS value, name AS label
        FROM offline_teams
        WHERE is_active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $soldWhere = [
        'DATE(o.sale_date) BETWEEN ? AND ?',
        "LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled')",
    ];
    $soldParams = [$from, $to];
    if ($teamId !== false && $teamId !== null) {
        $soldWhere[] = 'o.team_id = ?';
        $soldParams[] = (int)$teamId;
    }
    $soldWhereSql = implode(' AND ', $soldWhere);

    $soldStmt = $pdo->prepare("
        SELECT
            oi.order_id,
            o.order_code,
            COALESCE(o.customer_name, '') AS customer_name,
            COALESCE(t.id, 0) AS team_id,
            COALESCE(t.name, 'Unassigned') AS team_name,
            COALESCE(t.name, s.name, '') AS seller_name,
            oi.product_id,
            COALESCE(p.name, oi.product_name) AS product_name,
            COALESCE(p.product_type, 'normal') AS product_type,
            oi.quantity,
            oi.unit_price,
            oi.line_total,
            (
                CASE
                    WHEN COALESCE(o.subtotal, 0) > 0
                    THEN (oi.line_total / o.subtotal) * COALESCE(o.discount, 0)
                    ELSE 0
                END
            ) AS line_discount,
            COALESCE(o.status, 'unpaid') AS order_status,
            COALESCE(o.total_amount, 0) AS order_total_amount,
            COALESCE(o.received_amount, 0) AS order_received_amount,
            o.sale_date AS sold_at
        FROM offline_sale_order_items oi
        JOIN offline_sale_orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN offline_teams t ON t.id = o.team_id
        LEFT JOIN offline_sellers s ON s.id = o.seller_id
        WHERE {$soldWhereSql}
        ORDER BY o.sale_date DESC, oi.order_id DESC, oi.id ASC
    ");
    $soldStmt->execute($soldParams);
    $soldItems = $soldStmt->fetchAll(PDO::FETCH_ASSOC);

    $setNames = [];
    foreach ($soldItems as $row) {
        if (strtolower((string)($row['product_type'] ?? '')) === 'set' && !empty($row['product_name'])) {
            $setNames[(string)$row['product_name']] = true;
        }
    }

    $productSetIdsByName = [];
    $componentsBySetId = [];
    if (!empty($setNames)) {
        $setNameList = array_keys($setNames);
        $placeholders = implode(',', array_fill(0, count($setNameList), '?'));
        $stmtSets = $pdo->prepare("SELECT id, set_name FROM product_sets WHERE set_name IN ($placeholders)");
        $stmtSets->execute($setNameList);
        $setIds = [];
        foreach ($stmtSets->fetchAll(PDO::FETCH_ASSOC) as $setRow) {
            $sid = (int)($setRow['id'] ?? 0);
            $name = (string)($setRow['set_name'] ?? '');
            if ($sid > 0 && $name !== '') {
                $productSetIdsByName[$name] = $sid;
                $setIds[] = $sid;
            }
        }
        if ($setIds) {
            $placeholders = implode(',', array_fill(0, count($setIds), '?'));
            $stmtComponents = $pdo->prepare("
                SELECT
                    psi.product_set_id,
                    psi.quantity AS component_quantity,
                    p.id AS product_id,
                    p.name AS product_name,
                    COALESCE(p.cost, 0) AS unit_cost
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                WHERE psi.product_set_id IN ($placeholders)
            ");
            $stmtComponents->execute($setIds);
            foreach ($stmtComponents->fetchAll(PDO::FETCH_ASSOC) as $component) {
                $sid = (int)($component['product_set_id'] ?? 0);
                if ($sid > 0) {
                    $componentsBySetId[$sid][] = $component;
                }
            }
        }
    }

    $soldByKey = [];
    $addSold = static function (array $source, float $qty, float $sales, float $discount, array $context = []) use (&$soldByKey): void {
        $pid = (int)($source['product_id'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            return;
        }
        $teamIdValue = (int)($context['team_id'] ?? 0);
        $key = $pid . '|' . $teamIdValue;
        if (!isset($soldByKey[$key])) {
            $soldByKey[$key] = [
                'product_id' => $pid,
                'team_id' => $teamIdValue,
                'team_name' => (string)($context['team_name'] ?? 'Unassigned'),
                'sold_qty' => 0.0,
                'sold_amount' => 0.0,
                'discount_amount' => 0.0,
                'price_amount' => 0.0,
                'transactions' => [],
            ];
        }
        $soldByKey[$key]['sold_qty'] += $qty;
        $soldByKey[$key]['sold_amount'] += $sales;
        $soldByKey[$key]['discount_amount'] += $discount;
        $soldByKey[$key]['price_amount'] += $sales;
        $soldByKey[$key]['transactions'][] = [
            'order_id' => (int)($context['order_id'] ?? 0),
            'order_code' => (string)($context['order_code'] ?? ''),
            'customer_name' => (string)($context['customer_name'] ?? ''),
            'seller_name' => (string)($context['seller_name'] ?? ''),
            'team_name' => (string)($context['team_name'] ?? 'Unassigned'),
            'sold_at' => (string)($context['sold_at'] ?? ''),
            'payment_status' => (string)($context['payment_status'] ?? 'unpaid'),
            'source_product' => (string)($context['source_product'] ?? ($source['product_name'] ?? '')),
            'source_type' => (string)($context['source_type'] ?? 'item'),
            'qty' => $qty,
            'gross_sales' => $sales,
            'discount' => $discount,
            'net_sales' => max(0, $sales - $discount),
            'receipt_url' => !empty($context['order_id'])
                ? '/OrderShadow/admin/offline_sale_print.php?type=receipt&ids=' . (int)$context['order_id']
                : null,
        ];
    };

    foreach ($soldItems as $row) {
        $qty = (float)($row['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $lineTotal = (float)($row['line_total'] ?? 0);
        $lineDiscount = (float)($row['line_discount'] ?? 0);
        $paymentStatus = offline_order_display_status([
            'status' => (string)($row['order_status'] ?? 'unpaid'),
            'total_amount' => (float)($row['order_total_amount'] ?? 0),
            'received_amount' => (float)($row['order_received_amount'] ?? 0),
        ], []);
        $context = [
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_code' => (string)($row['order_code'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'seller_name' => (string)($row['seller_name'] ?? ''),
            'team_id' => (int)($row['team_id'] ?? 0),
            'team_name' => (string)($row['team_name'] ?? 'Unassigned'),
            'sold_at' => (string)($row['sold_at'] ?? ''),
            'payment_status' => $paymentStatus,
            'source_product' => (string)($row['product_name'] ?? ''),
            'source_type' => strtolower((string)($row['product_type'] ?? '')) === 'set' ? 'set' : 'item',
        ];

        if (strtolower((string)($row['product_type'] ?? '')) === 'set') {
            $setId = $productSetIdsByName[(string)($row['product_name'] ?? '')] ?? 0;
            $components = $setId > 0 ? ($componentsBySetId[$setId] ?? []) : [];
            if ($components) {
                $weightTotal = 0.0;
                foreach ($components as $component) {
                    $componentQty = (float)($component['component_quantity'] ?? 0);
                    $weightTotal += max($componentQty * (float)($component['unit_cost'] ?? 0), $componentQty);
                }
                $weightTotal = $weightTotal > 0 ? $weightTotal : 1.0;
                foreach ($components as $component) {
                    $componentQty = (float)($component['component_quantity'] ?? 0);
                    if ($componentQty <= 0) {
                        continue;
                    }
                    $weight = max($componentQty * (float)($component['unit_cost'] ?? 0), $componentQty);
                    $ratio = $weight / $weightTotal;
                    $addSold($component, $qty * $componentQty, $lineTotal * $ratio, $lineDiscount * $ratio, $context);
                }
                continue;
            }
        }

        $addSold($row, $qty, $lineTotal, $lineDiscount, $context);
    }

    $soldProductIds = array_values(array_unique(array_map(
        static fn(array $sold): int => (int)$sold['product_id'],
        $soldByKey
    )));
    if (!$soldProductIds) {
        api_json([
            'success' => true,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'products' => 0,
                'missing_cost' => 0,
                'sold_qty' => 0,
                'net_sales' => 0,
                'total_cost' => 0,
                'gross_profit' => 0,
            ],
            'rows' => [],
            'options' => ['brands' => $brands, 'teams' => $teams],
        ]);
    }

    $productWhere = [
        'p.id IN (' . implode(',', array_fill(0, count($soldProductIds), '?')) . ')',
        'COALESCE(p.active, 1) = 1',
        "LOWER(COALESCE(p.product_type, 'normal')) <> 'set'",
    ];
    $productParams = array_merge([$month], $soldProductIds);
    if ($brandId !== false && $brandId !== null) {
        $productWhere[] = 'p.brand_id = ?';
        $productParams[] = (int)$brandId;
    }
    $productWhereSql = 'WHERE ' . implode(' AND ', $productWhere);

    $stmt = $pdo->prepare("
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            COALESCE(p.sku, '') AS sku,
            p.brand_id,
            COALESCE(b.name, '') AS brand_name,
            COALESCE(b.color, '') AS brand_color,
            COALESCE(p.product_type, 'normal') AS product_type,
            COALESCE(pc.selling_price, p.cost, 0) AS catalog_selling_price,
            COALESCE(pc.original_cost, 0) AS original_cost,
            COALESCE(pc.supplier_cost, 0) AS supplier_cost,
            COALESCE(pc.shipping_cost, 0) AS shipping_cost,
            COALESCE(pc.other_costs, 0) AS other_costs,
            COALESCE(pc.marketing_cost, 0) AS marketing_cost,
            COALESCE(pc.offline_commission_amount, 0) AS commission_amount,
            (
                COALESCE(pc.original_cost, 0)
                + COALESCE(pc.supplier_cost, 0)
                + COALESCE(pc.shipping_cost, 0)
                + COALESCE(pc.other_costs, 0)
                + COALESCE(pc.marketing_cost, 0)
            ) AS unit_cost,
            COALESCE(pc.notes, '') AS notes,
            pc.cost_updated_at,
            COALESCE(u.name, u.username, '') AS updated_by_name
        FROM products p
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = ?
        LEFT JOIN users u ON u.id = pc.updated_by
        {$productWhereSql}
        ORDER BY p.name
    ");
    $stmt->execute($productParams);
    $productRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productsById = [];
    foreach ($productRows as $productRow) {
        $productsById[(int)$productRow['product_id']] = $productRow;
    }

    $rows = [];
    foreach ($soldByKey as $sold) {
        $product = $productsById[(int)$sold['product_id']] ?? null;
        if (!$product) {
            continue;
        }
        $row = $product;
        $unitCost = (float)$row['unit_cost'] + (float)$row['commission_amount'];
        $soldQty = (float)$sold['sold_qty'];
        $soldAmount = (float)$sold['sold_amount'];
        $discount = (float)$sold['discount_amount'];
        $netSales = max(0, $soldAmount - $discount);
        $avgSelling = $soldQty > 0
            ? ((float)$sold['price_amount'] / $soldQty)
            : (float)$row['catalog_selling_price'];

        $row['team_id'] = (int)$sold['team_id'];
        $row['team_name'] = (string)$sold['team_name'];
        $row['selling_price'] = $avgSelling;
        $row['sold_qty'] = $soldQty;
        $row['sold_amount'] = $soldAmount;
        $row['discount_amount'] = $discount;
        $row['transactions'] = $sold['transactions'] ?? [];
        $totalCost = $soldQty * $unitCost;
        $grossProfit = $netSales - $totalCost;
        $row['net_sales'] = $netSales;
        $row['total_original_cost'] = $soldQty * (float)$row['original_cost'];
        $row['total_supplier_cost'] = $soldQty * (float)$row['supplier_cost'];
        $row['total_shipping_cost'] = $soldQty * (float)$row['shipping_cost'];
        $row['total_other_costs'] = $soldQty * (float)$row['other_costs'];
        $row['total_marketing_cost'] = $soldQty * (float)$row['marketing_cost'];
        $row['total_commission_amount'] = $soldQty * (float)$row['commission_amount'];
        $row['total_cost'] = $totalCost;
        $row['gross_profit'] = $grossProfit;
        $row['profit_margin'] = $netSales > 0 ? ($grossProfit / $netSales) * 100 : 0;
        $row['missing_cost'] = $unitCost <= 0;
        unset($row['catalog_selling_price']);
        $rows[] = $row;
    }

    usort($rows, static function (array $a, array $b): int {
        $nameCompare = strcasecmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        return strcasecmp((string)($a['team_name'] ?? ''), (string)($b['team_name'] ?? ''));
    });

    if ($q !== '') {
        $needle = mb_strtolower($q);
        $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            $haystacks = [
                (string)($row['product_name'] ?? ''),
                (string)($row['sku'] ?? ''),
                (string)($row['brand_name'] ?? ''),
                (string)($row['team_name'] ?? ''),
            ];
            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                    return true;
                }
            }
            return false;
        }));
    }

    if (!$summaryOnly) {
        $historyByProduct = pc_history_for_products(
            $pdo,
            array_map(static fn(array $row): int => (int)$row['product_id'], $rows),
            $month
        );
        $rows = array_map(static function (array $row) use ($historyByProduct): array {
            $history = $historyByProduct[(int)$row['product_id']] ?? [];
            $row['update_history'] = $history;
            $row['update_count'] = count($history);
            return $row;
        }, $rows);
    }

    $summary = [
        'products' => count($rows),
        'missing_cost' => count(array_filter($rows, static fn(array $row): bool => (bool)$row['missing_cost'])),
        'sold_qty' => array_sum(array_map(static fn(array $row): float => (float)$row['sold_qty'], $rows)),
        'net_sales' => array_sum(array_map(static fn(array $row): float => (float)$row['net_sales'], $rows)),
        'total_original_cost' => array_sum(array_map(static fn(array $row): float => (float)$row['total_original_cost'], $rows)),
        'total_supplier_cost' => array_sum(array_map(static fn(array $row): float => (float)$row['total_supplier_cost'], $rows)),
        'total_shipping_cost' => array_sum(array_map(static fn(array $row): float => (float)$row['total_shipping_cost'], $rows)),
        'total_other_costs' => array_sum(array_map(static fn(array $row): float => (float)$row['total_other_costs'], $rows)),
        'total_marketing_cost' => array_sum(array_map(static fn(array $row): float => (float)$row['total_marketing_cost'], $rows)),
        'total_commission_amount' => array_sum(array_map(static fn(array $row): float => (float)$row['total_commission_amount'], $rows)),
        'total_cost' => array_sum(array_map(static fn(array $row): float => (float)$row['total_cost'], $rows)),
        'gross_profit' => array_sum(array_map(static fn(array $row): float => (float)$row['gross_profit'], $rows)),
    ];

    api_json([
        'success' => true,
        'month' => $month,
        'from' => $from,
        'to' => $to,
        'summary' => $summary,
        'rows' => $summaryOnly ? [] : $rows,
        'options' => $summaryOnly ? [] : ['brands' => $brands, 'teams' => $teams],
    ]);
}

function opc_save(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_offline_product_costing.update', 'sr_product_costing.update', 'product_costs.update');
    opc_ensure_schema($pdo);

    $input = opc_input();
    $month = opc_month($input['month_year'] ?? null);
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0) {
        api_error('Invalid product.', 422);
    }

    $selling = max(0, opc_num($input['selling_price'] ?? 0));
    $original = max(0, opc_num($input['original_cost'] ?? 0));
    $supplier = max(0, opc_num($input['supplier_cost'] ?? 0));
    $shipping = max(0, opc_num($input['shipping_cost'] ?? 0));
    $other = max(0, opc_num($input['other_costs'] ?? 0));
    $marketing = max(0, opc_num($input['marketing_cost'] ?? 0));
    $commission = max(0, opc_num($input['commission_amount'] ?? 0));
    $notes = trim((string)($input['notes'] ?? ''));
    $user = current_user() ?: [];
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $action = pc_history_exists($pdo, $productId, $month) ? 'update' : 'create';

    $stmt = $pdo->prepare("
        INSERT INTO product_costs
            (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, marketing_cost, offline_commission_amount, notes, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            selling_price = VALUES(selling_price),
            original_cost = VALUES(original_cost),
            supplier_cost = VALUES(supplier_cost),
            shipping_cost = VALUES(shipping_cost),
            other_costs = VALUES(other_costs),
            marketing_cost = VALUES(marketing_cost),
            offline_commission_amount = VALUES(offline_commission_amount),
            notes = VALUES(notes),
            updated_by = VALUES(updated_by),
            cost_updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$productId, $month, $selling, $original, $supplier, $shipping, $other, $marketing, $commission, $notes, $userId]);

    pc_history_log($pdo, $productId, $month, 'offline', $action, $selling, $original, $supplier, $shipping, $other, $marketing, $commission, $notes, $userId);

    $pdo->prepare('UPDATE products SET cost = ? WHERE id = ?')->execute([$selling, $productId]);

    api_json(['success' => true, 'message' => 'Product cost saved.']);
}

try {
    $pdo = get_db_connection();
    opc_ensure_schema($pdo);
    $summaryOnlyRequest = $_SERVER['REQUEST_METHOD'] !== 'POST' && (string)($_GET['summary_only'] ?? '') === '1';
    if (!$summaryOnlyRequest) {
        pc_history_ensure_schema($pdo);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        opc_save($pdo);
    }

    opc_load($pdo);
} catch (Throwable $e) {
    error_log('offline_product_costing API error: ' . $e->getMessage());
    api_error('Unable to load offline product costing.', 500);
}
