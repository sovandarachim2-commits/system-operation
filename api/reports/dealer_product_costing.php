<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/product_costing_lib.php';
require_login();

function dpc_month(?string $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function dpc_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function dpc_request_range(): array
{
    $month = dpc_month($_GET['month'] ?? null);
    $defaultFrom = $month . '-01';
    $defaultTo = date('Y-m-t', strtotime($defaultFrom) ?: time());
    $from = dpc_date($_GET['from'] ?? null, $defaultFrom);
    $to = dpc_date($_GET['to'] ?? null, $defaultTo);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $costMonth = dpc_month($_GET['month'] ?? substr($to, 0, 7));
    return [$from, $to, $costMonth];
}

function dpc_brand_ids(mixed $value): array
{
    $parts = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
    $ids = [];
    foreach ($parts as $part) {
        $id = filter_var($part, FILTER_VALIDATE_INT);
        if ($id !== false && (int)$id > 0) {
            $ids[] = (int)$id;
        }
    }
    return array_values(array_unique($ids));
}

function dpc_num(mixed $value): float
{
    $number = (float)$value;
    return is_finite($number) ? $number : 0.0;
}

function dpc_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function dpc_ensure_schema(PDO $pdo): void
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
        'dealer_commission_amount' => "ALTER TABLE product_costs ADD COLUMN dealer_commission_amount DECIMAL(10,2) DEFAULT 0",
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
                if ($column === 'offline_commission_amount') {
                    $pdo->exec("ALTER TABLE product_costs ADD COLUMN offline_commission_amount DECIMAL(10,2) DEFAULT 0");
                } else {
                    throw $e;
                }
            }
        }
    }
}

function dpc_load(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_dealer_product_costing.view', 'product_costs.view');
    pc_history_ensure_schema($pdo);

    [$from, $to, $month] = dpc_request_range();
    $q = trim((string)($_GET['q'] ?? ''));
    $brandIds = dpc_brand_ids($_GET['brand_ids'] ?? $_GET['brand_id'] ?? '');

    $brands = $pdo->query("
        SELECT id AS value, name AS label, color AS brand_color
        FROM brands
        WHERE active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $soldStmt = $pdo->prepare("
        SELECT oi.order_id, o.order_code,
            COALESCE(NULLIF(d.name, ''), o.dealer_name, '') AS customer_name,
            COALESCE(NULLIF(o.sales_person, ''), u.name, u.username, '') AS seller_name,
            p.id AS product_id, p.name AS product_name,
            COALESCE(p.product_type, 'normal') AS product_type,
            oi.quantity,
            (COALESCE(oi.line_total, 0) + COALESCE(oi.discount, 0)) AS line_total,
            COALESCE(oi.discount, 0) AS line_discount,
            CASE WHEN COALESCE(totals.net_total, 0) > 0
                THEN COALESCE(oi.line_total, 0) / totals.net_total * COALESCE(o.delivery_fee, 0)
                WHEN COALESCE(totals.qty_total, 0) > 0
                THEN oi.quantity / totals.qty_total * COALESCE(o.delivery_fee, 0)
                ELSE 0 END AS line_delivery,
            COALESCE(o.payment_status, 'unpaid') AS payment_status,
            o.order_date AS printed_at
        FROM dealer_order_items oi
        JOIN dealer_orders o ON o.id = oi.order_id
        JOIN products p ON p.id = COALESCE(NULLIF(oi.movement_item_id, 0), CAST(oi.product_id AS UNSIGNED))
        LEFT JOIN dealers d ON d.id = o.dealer_id
        LEFT JOIN users u ON u.id = o.created_by
        LEFT JOIN (SELECT order_id, SUM(line_total) AS net_total, SUM(quantity) AS qty_total FROM dealer_order_items GROUP BY order_id) totals ON totals.order_id = o.id
        WHERE LOWER(COALESCE(o.status, '')) NOT IN ('draft', 'cancel', 'cancelled', 'canceled', 'return', 'returned')
          AND DATE(o.order_date) BETWEEN ? AND ?
        ORDER BY o.order_date DESC, oi.order_id DESC, oi.id ASC
    ");
    $soldStmt->execute([$from, $to]);
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

    $soldByProduct = [];
    $addSold = static function (array $source, float $qty, float $sales, float $discount, float $delivery, array $context = []) use (&$soldByProduct): void {
        $pid = (int)($source['product_id'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            return;
        }
        if (!isset($soldByProduct[$pid])) {
            $soldByProduct[$pid] = [
                'sold_qty' => 0.0,
                'sold_amount' => 0.0,
                'discount_amount' => 0.0,
                'delivery_amount' => 0.0,
                'transactions' => [],
            ];
        }
        $soldByProduct[$pid]['sold_qty'] += $qty;
        $soldByProduct[$pid]['sold_amount'] += $sales;
        $soldByProduct[$pid]['discount_amount'] += $discount;
        $soldByProduct[$pid]['delivery_amount'] += $delivery;
        $soldByProduct[$pid]['transactions'][] = [
            'order_id' => (int)($context['order_id'] ?? 0),
            'order_code' => (string)($context['order_code'] ?? ''),
            'customer_name' => (string)($context['customer_name'] ?? ''),
            'seller_name' => (string)($context['seller_name'] ?? ''),
            'sold_at' => (string)($context['printed_at'] ?? ''),
            'payment_status' => (string)($context['payment_status'] ?? 'unpaid'),
            'source_product' => (string)($context['source_product'] ?? ($source['product_name'] ?? '')),
            'source_type' => (string)($context['source_type'] ?? 'item'),
            'qty' => $qty,
            'gross_sales' => $sales,
            'discount' => $discount,
            'delivery' => $delivery,
            'net_sales' => max(0, $sales - $discount + $delivery),
        ];
    };

    foreach ($soldItems as $row) {
        $qty = (float)($row['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $lineTotal = (float)($row['line_total'] ?? 0);
        $lineDiscount = (float)($row['line_discount'] ?? 0);
        $lineDelivery = (float)($row['line_delivery'] ?? 0);
        $rawStatus = strtolower(trim((string)($row['payment_status'] ?? 'unpaid')));
        $paymentStatus = in_array($rawStatus, ['paid', 'partial', 'credit'], true) ? $rawStatus : 'unpaid';
        $context = [
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_code' => (string)($row['order_code'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'seller_name' => (string)($row['seller_name'] ?? ''),
            'printed_at' => (string)($row['printed_at'] ?? ''),
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
                    $addSold($component, $qty * $componentQty, $lineTotal * $ratio, $lineDiscount * $ratio, $lineDelivery * $ratio, $context);
                }
                continue;
            }
        }

        $addSold($row, $qty, $lineTotal, $lineDiscount, $lineDelivery, $context);
    }

    $soldProductIds = array_keys($soldByProduct);
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
            'options' => ['brands' => $brands],
        ]);
    }

    $productWhere = [
        'p.id IN (' . implode(',', array_fill(0, count($soldProductIds), '?')) . ')',
        'COALESCE(p.active, 1) = 1',
        "LOWER(COALESCE(p.product_type, 'normal')) <> 'set'",
    ];
    $productParams = array_merge([$month], $soldProductIds);
    if ($q !== '') {
        $productWhere[] = '(p.name LIKE ? OR COALESCE(p.sku, \'\') LIKE ? OR COALESCE(b.name, \'\') LIKE ?)';
        $like = '%' . $q . '%';
        array_push($productParams, $like, $like, $like);
    }
    if ($brandIds) {
        $productWhere[] = 'p.brand_id IN (' . implode(',', array_fill(0, count($brandIds), '?')) . ')';
        array_push($productParams, ...$brandIds);
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
            COALESCE(pc.selling_price, p.cost, 0) AS selling_price,
            COALESCE(pc.original_cost, 0) AS original_cost,
            COALESCE(pc.supplier_cost, 0) AS supplier_cost,
            COALESCE(pc.shipping_cost, 0) AS shipping_cost,
            COALESCE(pc.other_costs, 0) AS other_costs,
            COALESCE(pc.marketing_cost, 0) AS marketing_cost,
            COALESCE(pc.dealer_commission_amount, 0) AS commission_amount,
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = array_map(static function (array $row) use ($soldByProduct): array {
        $sold = $soldByProduct[(int)$row['product_id']] ?? ['sold_qty' => 0, 'sold_amount' => 0, 'discount_amount' => 0, 'delivery_amount' => 0];
        $unitCost = (float)$row['unit_cost'] + (float)$row['commission_amount'];
        $soldQty = (float)$sold['sold_qty'];
        $soldAmount = (float)$sold['sold_amount'];
        $discount = (float)$sold['discount_amount'];
        $delivery = (float)($sold['delivery_amount'] ?? 0);
        $netSales = max(0, $soldAmount - $discount + $delivery);
        $row['sold_qty'] = $soldQty;
        $row['sold_amount'] = $soldAmount;
        $row['discount_amount'] = $discount;
        $row['transactions'] = $sold['transactions'] ?? [];
        $totalCost = $soldQty * $unitCost;
        $grossProfit = $netSales - $totalCost;
        $row['net_sales'] = $netSales;
        $row['total_cost'] = $totalCost;
        $row['gross_profit'] = $grossProfit;
        $row['profit_margin'] = $netSales > 0 ? ($grossProfit / $netSales) * 100 : 0;
        $row['missing_cost'] = $unitCost <= 0;
        return $row;
    }, $rows);

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

    $summary = [
        'products' => count($rows),
        'missing_cost' => count(array_filter($rows, static fn(array $row): bool => (bool)$row['missing_cost'])),
        'sold_qty' => array_sum(array_map(static fn(array $row): float => (float)$row['sold_qty'], $rows)),
        'net_sales' => array_sum(array_map(static fn(array $row): float => (float)$row['net_sales'], $rows)),
        'total_cost' => array_sum(array_map(static fn(array $row): float => (float)$row['total_cost'], $rows)),
        'gross_profit' => array_sum(array_map(static fn(array $row): float => (float)$row['gross_profit'], $rows)),
    ];

    api_json([
        'success' => true,
        'month' => $month,
        'from' => $from,
        'to' => $to,
        'summary' => $summary,
        'rows' => $rows,
        'options' => ['brands' => $brands],
    ]);
}

function dpc_save(PDO $pdo): void
{
    require_role_or_permission(['admin'], 'sr_dealer_product_costing.update', 'product_costs.update');

    $input = dpc_input();
    $month = dpc_month($input['month_year'] ?? null);
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0) {
        api_error('Invalid product.', 422);
    }

    $selling = max(0, dpc_num($input['selling_price'] ?? 0));
    $original = max(0, dpc_num($input['original_cost'] ?? 0));
    $supplier = max(0, dpc_num($input['supplier_cost'] ?? 0));
    $shipping = max(0, dpc_num($input['shipping_cost'] ?? 0));
    $other = max(0, dpc_num($input['other_costs'] ?? 0));
    $marketing = max(0, dpc_num($input['marketing_cost'] ?? 0));
    $commission = max(0, dpc_num($input['commission_amount'] ?? 0));
    $notes = trim((string)($input['notes'] ?? ''));
    $user = current_user() ?: [];
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $action = pc_history_exists($pdo, $productId, $month) ? 'update' : 'create';

    $stmt = $pdo->prepare("
        INSERT INTO product_costs
            (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, marketing_cost, dealer_commission_amount, notes, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            selling_price = VALUES(selling_price),
            original_cost = VALUES(original_cost),
            supplier_cost = VALUES(supplier_cost),
            shipping_cost = VALUES(shipping_cost),
            other_costs = VALUES(other_costs),
            marketing_cost = VALUES(marketing_cost),
            dealer_commission_amount = VALUES(dealer_commission_amount),
            notes = VALUES(notes),
            updated_by = VALUES(updated_by),
            cost_updated_at = CURRENT_TIMESTAMP
    ");
    // Dealer save preserves online and offline commission values.
    $stmt->execute([$productId, $month, $selling, $original, $supplier, $shipping, $other, $marketing, $commission, $notes, $userId]);

    pc_history_log($pdo, $productId, $month, 'dealer', $action, $selling, $original, $supplier, $shipping, $other, $marketing, $commission, $notes, $userId);

    api_json(['success' => true, 'message' => 'Product cost saved.']);
}

try {
    $pdo = get_db_connection();
    require_role_or_permission(['admin'], $_SERVER['REQUEST_METHOD'] === 'POST' ? 'sr_dealer_product_costing.update' : 'sr_dealer_product_costing.view', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'product_costs.update' : 'product_costs.view');
    dpc_ensure_schema($pdo);
    pc_history_ensure_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        dpc_save($pdo);
    }

    dpc_load($pdo);
} catch (Throwable $e) {
    error_log('dealer_product_costing API error: ' . $e->getMessage());
    api_error('Unable to load dealer product costing.', 500);
}
