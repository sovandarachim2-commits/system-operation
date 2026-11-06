<?php
declare(strict_types=1);

function pc_ensure_auto_increment_id(PDO $pdo, string $table): void
{
    $allowed = ['product_costs', 'product_cost_history'];
    if (!in_array($table, $allowed, true)) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'id'");
    $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!$column) {
        return;
    }

    $extra = strtolower((string)($column['Extra'] ?? ''));
    if (!str_contains($extra, 'auto_increment')) {
        $zeroStmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = 0");
        if ($zeroStmt && (int)$zeroStmt->fetchColumn() > 0) {
            $maxStmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM {$table} WHERE id <> 0");
            $nextId = $maxStmt ? max(1, (int)$maxStmt->fetchColumn()) : 1;
            $update = $pdo->prepare("UPDATE {$table} SET id = ? WHERE id = 0");
            $update->execute([$nextId]);
        }

        $autoStmt = $pdo->query("SHOW COLUMNS FROM {$table} WHERE Extra LIKE '%auto_increment%'");
        $autoColumn = $autoStmt ? $autoStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($autoColumn && strtolower((string)($autoColumn['Field'] ?? '')) !== 'id') {
            return;
        }

        $indexStmt = $pdo->query("SHOW INDEX FROM {$table} WHERE Column_name = 'id'");
        if (!$indexStmt || $indexStmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE {$table} ADD INDEX idx_{$table}_id (id)");
        }

        $pdo->exec("ALTER TABLE {$table} MODIFY id INT NOT NULL AUTO_INCREMENT");
    }
}

function pc_history_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_cost_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            month_year VARCHAR(7) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'online',
            action VARCHAR(20) NOT NULL DEFAULT 'update',
            selling_price DECIMAL(10,2) DEFAULT 0,
            original_cost DECIMAL(10,2) DEFAULT 0,
            supplier_cost DECIMAL(10,2) DEFAULT 0,
            shipping_cost DECIMAL(10,2) DEFAULT 0,
            other_costs DECIMAL(10,2) DEFAULT 0,
            marketing_cost DECIMAL(10,2) DEFAULT 0,
            commission_amount DECIMAL(10,2) DEFAULT 0,
            notes TEXT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pch_product_month (product_id, month_year),
            INDEX idx_pch_created (created_at),
            INDEX idx_pch_channel (channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    pc_ensure_auto_increment_id($pdo, 'product_cost_history');

    $columns = [
        'marketing_cost' => "ALTER TABLE product_cost_history ADD COLUMN marketing_cost DECIMAL(10,2) DEFAULT 0 AFTER other_costs",
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM product_cost_history LIKE " . $pdo->quote($column));
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec($sql);
        }
    }
}

function pc_history_exists(PDO $pdo, int $productId, string $month): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM product_costs WHERE product_id = ? AND month_year = ? LIMIT 1');
    $stmt->execute([$productId, $month]);
    return (bool)$stmt->fetchColumn();
}

function pc_history_log(
    PDO $pdo,
    int $productId,
    string $month,
    string $channel,
    string $action,
    float $selling,
    float $original,
    float $supplier,
    float $shipping,
    float $other,
    float $marketing,
    float $commission,
    string $notes,
    ?int $userId
): void {
    pc_history_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO product_cost_history
            (product_id, month_year, channel, action, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, marketing_cost, commission_amount, notes, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $productId,
        $month,
        in_array($channel, ['online', 'offline', 'dealer'], true) ? $channel : 'online',
        $action === 'create' ? 'create' : 'update',
        $selling,
        $original,
        $supplier,
        $shipping,
        $other,
        $marketing,
        $commission,
        $notes !== '' ? $notes : null,
        $userId,
    ]);
}

/**
 * @param list<int> $productIds
 * @return array<int, list<array<string, mixed>>>
 */
function pc_history_for_products(PDO $pdo, array $productIds, string $month): array
{
    pc_history_ensure_schema($pdo);
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
    if (!$productIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            h.id,
            h.product_id,
            h.month_year,
            h.channel,
            h.action,
            h.selling_price,
            h.original_cost,
            h.supplier_cost,
            h.shipping_cost,
            h.other_costs,
            COALESCE(h.marketing_cost, 0) AS marketing_cost,
            h.commission_amount,
            COALESCE(h.notes, '') AS notes,
            h.updated_by,
            h.created_at,
            COALESCE(u.name, u.username, '') AS updated_by_name
        FROM product_cost_history h
        LEFT JOIN users u ON u.id = h.updated_by
        WHERE h.product_id IN ($placeholders)
          AND h.month_year = ?
        ORDER BY h.created_at DESC, h.id DESC
    ");
    $stmt->execute([...$productIds, $month]);

    $byProduct = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $byProduct[$pid][] = [
            'id' => (int)($row['id'] ?? 0),
            'product_id' => $pid,
            'month_year' => (string)($row['month_year'] ?? ''),
            'channel' => (string)($row['channel'] ?? 'online'),
            'action' => (string)($row['action'] ?? 'update'),
            'selling_price' => (float)($row['selling_price'] ?? 0),
            'original_cost' => (float)($row['original_cost'] ?? 0),
            'supplier_cost' => (float)($row['supplier_cost'] ?? 0),
            'shipping_cost' => (float)($row['shipping_cost'] ?? 0),
            'other_costs' => (float)($row['other_costs'] ?? 0),
            'marketing_cost' => (float)($row['marketing_cost'] ?? 0),
            'commission_amount' => (float)($row['commission_amount'] ?? 0),
            'notes' => (string)($row['notes'] ?? ''),
            'updated_by' => isset($row['updated_by']) ? (int)$row['updated_by'] : null,
            'updated_by_name' => (string)($row['updated_by_name'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    return $byProduct;
}

function pc_product_menu_selling_price(PDO $pdo, int $productId, string $month): float
{
    $existing = $pdo->prepare('SELECT selling_price FROM product_costs WHERE product_id = ? AND month_year = ? LIMIT 1');
    $existing->execute([$productId, $month]);
    $saved = $existing->fetchColumn();
    if ($saved !== false && $saved !== null) {
        return max(0, (float)$saved);
    }
    $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(selling_price, 0), cost, 0) FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    return max(0, (float)($stmt->fetchColumn() ?: 0));
}

function pc_previous_unit_cost(PDO $pdo, int $productId, string $month, string $channel): ?array
{
    $commissionColumn = [
        'online' => 'commission_amount',
        'offline' => 'offline_commission_amount',
        'dealer' => 'dealer_commission_amount',
    ][$channel] ?? '';
    if ($commissionColumn === '' || $productId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT month_year,
               COALESCE(original_cost, 0) AS original_cost,
               COALESCE(supplier_cost, 0) AS supplier_cost,
               COALESCE(shipping_cost, 0) AS shipping_cost,
               COALESCE(other_costs, 0) AS other_costs,
               COALESCE(marketing_cost, 0) AS marketing_cost,
               COALESCE({$commissionColumn}, 0) AS commission_amount
        FROM product_costs
        WHERE product_id = ?
          AND month_year < ?
          AND (
            ABS(COALESCE(original_cost, 0)) > 0.0001
            OR ABS(COALESCE(supplier_cost, 0)) > 0.0001
            OR ABS(COALESCE(shipping_cost, 0)) > 0.0001
            OR ABS(COALESCE(other_costs, 0)) > 0.0001
            OR ABS(COALESCE(marketing_cost, 0)) > 0.0001
            OR ABS(COALESCE({$commissionColumn}, 0)) > 0.0001
          )
        ORDER BY month_year DESC
        LIMIT 1
    ");
    $stmt->execute([$productId, $month]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return null;
    }

    $original = (float)$item['original_cost'];
    $supplier = (float)$item['supplier_cost'];
    $shipping = (float)$item['shipping_cost'];
    $other = (float)$item['other_costs'];
    $marketing = (float)$item['marketing_cost'];
    $commission = (float)$item['commission_amount'];

    return [
        'month_year' => (string)$item['month_year'],
        'original_cost' => $original,
        'supplier_cost' => $supplier,
        'shipping_cost' => $shipping,
        'other_costs' => $other,
        'marketing_cost' => $marketing,
        'commission_amount' => $commission,
        'unit_cost' => $original + $supplier + $shipping + $other + $marketing + $commission,
    ];
}

function pc_previous_cost_respond(PDO $pdo, string $channel): void
{
    if ((string)($_GET['action'] ?? '') !== 'previous_cost') {
        return;
    }

    $month = trim((string)($_GET['month_year'] ?? ''));
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || $productId <= 0) {
        api_error('Choose a product and month first.', 422);
    }

    $previous = pc_previous_unit_cost($pdo, $productId, $month, $channel);
    if (!$previous) {
        api_error('No unit cost was saved in a previous month.', 404);
    }

    api_json(['success' => true, 'previous' => $previous]);
}

function pc_brand_ids(mixed $value): array
{
    $parts = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
    $ids = [];
    foreach ($parts ?: [] as $part) {
        $id = filter_var($part, FILTER_VALIDATE_INT);
        if ($id !== false && (int)$id > 0) {
            $ids[] = (int)$id;
        }
    }
    return array_values(array_unique($ids));
}

function pc_bucket_payment_status(string $raw, float $received = 0, float $total = 0, bool $isPaid = false): string
{
    $status = strtolower(trim($raw));
    if ($isPaid || $status === 'paid' || ($total > 0.004 && $received >= $total - 0.009)) {
        return 'paid';
    }
    if ($status === 'partial' || ($received > 0.004 && ($total <= 0.004 || $received < $total - 0.009))) {
        return 'partial';
    }
    return 'unpaid';
}

function pc_payment_statuses(): ?array
{
    if (!array_key_exists('payment_statuses', $_GET)) {
        return null;
    }
    $raw = strtolower(trim((string)$_GET['payment_statuses']));
    if ($raw === '' || $raw === 'none') {
        return [];
    }
    $selected = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if (in_array($part, ['paid', 'unpaid', 'partial'], true)) {
            $selected[$part] = true;
        }
    }
    $selected = array_keys($selected);
    return count($selected) >= 3 ? null : $selected;
}

function pc_payment_status_allowed(string $status): bool
{
    $selected = pc_payment_statuses();
    return $selected === null || in_array($status, $selected, true);
}

function pc_note_payment_order(array &$ordersByStatus, string $status, int $orderId): void
{
    if ($orderId <= 0 || !in_array($status, ['paid', 'unpaid', 'partial'], true)) {
        return;
    }
    $ordersByStatus[$status][$orderId] = true;
}

function pc_add_product_payment_net(array &$byProduct, int $productId, string $status, float $amount): void
{
    if ($productId <= 0 || $amount == 0.0 || !in_array($status, ['paid', 'unpaid', 'partial'], true)) {
        return;
    }
    if (!isset($byProduct[$productId])) {
        $byProduct[$productId] = ['paid' => 0.0, 'unpaid' => 0.0, 'partial' => 0.0];
    }
    $byProduct[$productId][$status] += $amount;
}

function pc_add_keyed_payment_net(array &$byKey, string $key, string $status, float $amount): void
{
    if ($key === '' || $amount == 0.0 || !in_array($status, ['paid', 'unpaid', 'partial'], true)) {
        return;
    }
    if (!isset($byKey[$key])) {
        $byKey[$key] = ['paid' => 0.0, 'unpaid' => 0.0, 'partial' => 0.0];
    }
    $byKey[$key][$status] += $amount;
}

function pc_payment_amount_fields(?array $bucket): array
{
    return [
        'paid_net_sales' => (float)($bucket['paid'] ?? 0),
        'unpaid_net_sales' => (float)($bucket['unpaid'] ?? 0) + (float)($bucket['partial'] ?? 0),
    ];
}

function pc_payment_fields_from_transactions(array $transactions, float $netSales): array
{
    $paid = 0.0;
    $other = 0.0;
    $seen = false;
    foreach ($transactions as $item) {
        if (!is_array($item)) {
            continue;
        }
        $seen = true;
        $net = (float)($item['net_sales'] ?? 0);
        if (strtolower(trim((string)($item['payment_status'] ?? ''))) === 'paid') {
            $paid += $net;
        } else {
            $other += $net;
        }
    }
    if (!$seen) {
        return [
            'paid_net_sales' => 0.0,
            'unpaid_net_sales' => $netSales,
        ];
    }
    $gap = $netSales - ($paid + $other);
    if (abs($gap) > 0.05) {
        $other += $gap;
    }
    return [
        'paid_net_sales' => $paid,
        'unpaid_net_sales' => $other,
    ];
}

function pc_summary_payment_nets(array $rows): array
{
    $paid = 0.0;
    $balance = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $paid += (float)($row['paid_net_sales'] ?? 0);
        $balance += (float)($row['unpaid_net_sales'] ?? 0);
    }
    return [
        'paid_net_sales' => $paid,
        'balance_net_sales' => $balance,
    ];
}

function pc_scope_product_ids(array $soldProductIds, array $paymentNetByProduct): array
{
    $ids = [];
    foreach ($soldProductIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    foreach (array_keys($paymentNetByProduct) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function pc_sum_payment_nets(array $byProduct, array $productRows, array $brandIds): array
{
    $allowedBrands = $brandIds ? array_fill_keys(array_map('intval', $brandIds), true) : null;
    $allowedProducts = [];
    foreach ($productRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $brandId = (int)($row['brand_id'] ?? 0);
        if ($allowedBrands !== null && !isset($allowedBrands[$brandId])) {
            continue;
        }
        $allowedProducts[(int)($row['product_id'] ?? 0)] = true;
    }
    $paid = 0.0;
    $balance = 0.0;
    foreach ($byProduct as $productId => $bucket) {
        if (!is_array($bucket) || !isset($allowedProducts[(int)$productId])) {
            continue;
        }
        $paid += (float)($bucket['paid'] ?? 0);
        $balance += (float)($bucket['unpaid'] ?? 0) + (float)($bucket['partial'] ?? 0);
    }
    return [
        'paid_net_sales' => $paid,
        'balance_net_sales' => $balance,
    ];
}

function pc_with_counts(array $options, array $counts): array
{
    foreach ($options as &$option) {
        if (!is_array($option)) {
            continue;
        }
        $key = (string)($option['value'] ?? '');
        $option['count'] = (int)($counts[$key] ?? $counts[(int)$key] ?? 0);
    }
    unset($option);
    return $options;
}

function pc_payment_options(array $ordersByStatus): array
{
    return pc_with_counts([
        ['value' => 'paid', 'label' => 'Paid'],
        ['value' => 'unpaid', 'label' => 'Unpaid'],
        ['value' => 'partial', 'label' => 'Partial'],
    ], [
        'paid' => count($ordersByStatus['paid'] ?? []),
        'unpaid' => count($ordersByStatus['unpaid'] ?? []),
        'partial' => count($ordersByStatus['partial'] ?? []),
    ]);
}

function pc_count_brands(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $brandId = (int)($row['brand_id'] ?? 0);
        if ($brandId > 0) {
            $counts[$brandId] = ($counts[$brandId] ?? 0) + 1;
        }
    }
    return $counts;
}

function pc_filter_rows_by_brand(array $rows, array $brandIds): array
{
    if (!$brandIds) {
        return $rows;
    }
    $allowed = array_fill_keys($brandIds, true);
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => isset($allowed[(int)($row['brand_id'] ?? 0)])
    ));
}

function pc_checkbox_options(array $brands, array $brandCounts, array $paymentOrders, array $groups = []): array
{
    $options = [
        'brands' => pc_with_counts($brands, $brandCounts),
        'payments' => pc_payment_options($paymentOrders),
    ];
    foreach ($groups as $key => $group) {
        $options[$key] = pc_with_counts($group['rows'] ?? [], $group['counts'] ?? []);
    }
    return $options;
}

function pc_include_unsold(): bool
{
    $value = strtolower(trim((string)($_GET['include_unsold'] ?? '')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function pc_lock_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_cost_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(20) NOT NULL,
            month_year VARCHAR(7) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'LOCKED',
            period_from DATE NULL,
            period_to DATE NULL,
            snapshot_json LONGTEXT NULL,
            note TEXT NULL,
            locked_by INT NULL,
            locked_by_name VARCHAR(120) NULL,
            locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            unlocked_by INT NULL,
            unlocked_by_name VARCHAR(120) NULL,
            unlocked_at TIMESTAMP NULL,
            UNIQUE KEY uk_cost_lock_channel_month (channel, month_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function pc_lock_month_value(mixed $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : '';
}

function pc_lock_date_value(mixed $value): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function pc_lock_user_label(?array $user): string
{
    $user = $user ?: [];
    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return trim((string)($user['username'] ?? ''));
}

function pc_lock_row(PDO $pdo, string $channel, string $month): ?array
{
    pc_lock_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM product_cost_locks WHERE channel = ? AND month_year = ? LIMIT 1');
    $stmt->execute([$channel, $month]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pc_lock_pending_request(PDO $pdo, string $channel, string $month): ?array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_cost_unlock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel VARCHAR(20) NOT NULL,
        month_year VARCHAR(7) NOT NULL,
        reason TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        requested_by INT NULL,
        requester_name VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewer_name VARCHAR(120) NULL,
        reviewed_at TIMESTAMP NULL,
        KEY idx_product_cost_unlock_report (channel, month_year, status),
        KEY idx_product_cost_unlock_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->prepare("SELECT id, status, reason AS note FROM product_cost_unlock_requests WHERE channel = ? AND month_year = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$channel, $month]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function pc_lock_is_locked(?array $row): bool
{
    return $row && strtoupper((string)($row['status'] ?? '')) === 'LOCKED';
}

function pc_lock_assert_open(PDO $pdo, string $channel, string $month): void
{
    $row = pc_lock_row($pdo, $channel, $month);
    if (pc_lock_is_locked($row)) {
        api_error('This month is locked. Product costs are frozen until it is unlocked.', 409);
    }
}

function pc_lock_snapshot(array $row): array
{
    $decoded = json_decode((string)($row['snapshot_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function pc_lock_filter_rows(array $rows): array
{
    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    $brandIds = [];
    $brandRaw = $_GET['brand_ids'] ?? $_GET['brand_id'] ?? '';
    $parts = is_array($brandRaw) ? $brandRaw : preg_split('/\s*,\s*/', trim((string)$brandRaw), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts ?: [] as $part) {
        $id = filter_var($part, FILTER_VALIDATE_INT);
        if ($id !== false && (int)$id > 0) {
            $brandIds[] = (int)$id;
        }
    }
    $teamIds = pc_brand_ids($_GET['team_ids'] ?? $_GET['team_id'] ?? '');
    $dealerIds = pc_brand_ids($_GET['dealer_ids'] ?? $_GET['dealer_id'] ?? '');
    $includeUnsold = pc_include_unsold();
    $paymentStatuses = pc_payment_statuses();

    return array_values(array_filter($rows, static function ($row) use ($q, $brandIds, $teamIds, $dealerIds, $includeUnsold, $paymentStatuses): bool {
        if (!is_array($row)) {
            return false;
        }
        if (!$includeUnsold && (float)($row['sold_qty'] ?? 0) <= 0) {
            return false;
        }
        if ($brandIds && !in_array((int)($row['brand_id'] ?? 0), $brandIds, true)) {
            return false;
        }
        if ($teamIds && !in_array((int)($row['team_id'] ?? 0), $teamIds, true)) {
            return false;
        }
        if ($dealerIds && !in_array((int)($row['dealer_id'] ?? 0), $dealerIds, true)) {
            return false;
        }
        if ($paymentStatuses !== null) {
            $matched = false;
            foreach (($row['transactions'] ?? []) as $tx) {
                if (in_array((string)($tx['payment_status'] ?? ''), $paymentStatuses, true)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        if ($q === '') {
            return true;
        }
        $haystack = strtolower(implode(' ', [
            (string)($row['product_name'] ?? ''),
            (string)($row['sku'] ?? ''),
            (string)($row['brand_name'] ?? ''),
            (string)($row['team_name'] ?? ''),
            (string)($row['dealer_name'] ?? ''),
        ]));
        return str_contains($haystack, $q);
    }));
}

function pc_lock_summary(array $stored, array $rows, bool $filtered): array
{
    if (!$filtered) {
        return $stored;
    }
    $paymentSummary = pc_summary_payment_nets($rows);
    $summary = $stored;
    $summary['products'] = count($rows);
    $summary['missing_cost'] = count(array_filter($rows, static fn(array $row): bool => (bool)($row['missing_cost'] ?? false)));
    $summary['sold_qty'] = array_sum(array_map(static fn(array $row): float => (float)($row['sold_qty'] ?? 0), $rows));
    $summary['net_sales'] = array_sum(array_map(static fn(array $row): float => (float)($row['net_sales'] ?? 0), $rows));
    $summary['paid_net_sales'] = $paymentSummary['paid_net_sales'];
    $summary['balance_net_sales'] = $paymentSummary['balance_net_sales'];
    $summary['total_cost'] = array_sum(array_map(static fn(array $row): float => (float)($row['total_cost'] ?? 0), $rows));
    $summary['gross_profit'] = array_sum(array_map(static fn(array $row): float => (float)($row['gross_profit'] ?? 0), $rows));
    return $summary;
}

function pc_snapshot_facet_counts(array $rows): array
{
    $brands = [];
    $teams = [];
    $payments = ['paid' => [], 'unpaid' => [], 'partial' => []];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $brandId = (int)($row['brand_id'] ?? 0);
        if ($brandId > 0) {
            $brands[$brandId] = ($brands[$brandId] ?? 0) + 1;
        }
        $teamId = (int)($row['team_id'] ?? 0);
        if ($teamId > 0) {
            $teams[$teamId] = ($teams[$teamId] ?? 0) + 1;
        }
        $productId = (int)($row['product_id'] ?? 0);
        $statuses = [];
        foreach (($row['transactions'] ?? []) as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $status = (string)($tx['payment_status'] ?? '');
            if (in_array($status, ['paid', 'unpaid', 'partial'], true)) {
                $statuses[$status] = true;
            }
        }
        foreach (array_keys($statuses) as $status) {
            pc_note_payment_order($payments, $status, $productId);
        }
    }
    return ['brands' => $brands, 'teams' => $teams, 'payments' => $payments];
}

function pc_lock_options(PDO $pdo, string $channel, array $rows = []): array
{
    $brands = $pdo->query("
        SELECT id AS value, name AS label, color AS brand_color
        FROM brands
        WHERE active = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $options = ['brands' => $brands];
    if ($channel === 'offline') {
        $options['teams'] = $pdo->query("
            SELECT id AS value, name AS label
            FROM offline_teams
            WHERE is_active = 1
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($channel === 'dealer') {
        $options['dealers'] = $pdo->query("
            SELECT id AS value, name AS label
            FROM dealers
            WHERE LOWER(COALESCE(status, 'active')) = 'active'
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    $counts = pc_snapshot_facet_counts($rows);
    $options['brands'] = pc_with_counts($options['brands'], $counts['brands']);
    if (isset($options['teams'])) {
        $options['teams'] = pc_with_counts($options['teams'], $counts['teams']);
    }
    if (isset($options['dealers'])) {
        $options['dealers'] = pc_with_counts($options['dealers'], []);
    }
    $options['payments'] = pc_payment_options($counts['payments']);
    return $options;
}

function pc_lock_public(?array $row): ?array
{
    if (!pc_lock_is_locked($row)) {
        return null;
    }
    return [
        'status' => 'LOCKED',
        'channel' => (string)($row['channel'] ?? ''),
        'month' => (string)($row['month_year'] ?? ''),
        'from' => (string)($row['period_from'] ?? ''),
        'to' => (string)($row['period_to'] ?? ''),
        'note' => (string)($row['note'] ?? ''),
        'locked_by' => (string)($row['locked_by_name'] ?? ''),
        'locked_at' => (string)($row['locked_at'] ?? ''),
    ];
}

function pc_lock_respond_if_locked(
    PDO $pdo,
    string $channel,
    string $month,
    bool $summaryOnly = false,
    ?string $from = null,
    ?string $to = null
): void {
    // If request is in date filter mode (or custom date range), do not serve frozen snapshot -> keep LIVE!
    $filterMode = strtolower(trim((string)($_GET['filter_mode'] ?? '')));
    $isDateFilter = (string)($_GET['is_date_filter'] ?? '') === '1' || $filterMode === 'date';
    if ($isDateFilter) {
        return;
    }

    // If custom date range does not match the full month bounds, query is LIVE
    if ($from !== null && $to !== null && $month !== '') {
        $expectedFrom = $month . '-01';
        $expectedTo = date('Y-m-t', strtotime($expectedFrom) ?: time());
        if ($from !== $expectedFrom || $to !== $expectedTo) {
            return;
        }
    }

    $row = pc_lock_row($pdo, $channel, $month);
    if (!pc_lock_is_locked($row)) {
        return;
    }
    $snapshot = pc_lock_snapshot($row);
    $storedRows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
    $storedSummary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
    $filteredRows = pc_lock_filter_rows($storedRows);
    $filtered = count($filteredRows) !== count($storedRows) || pc_payment_statuses() !== null || !pc_include_unsold();
    api_json([
        'success' => true,
        'locked' => true,
        'lock' => pc_lock_public($row),
        'unlock_request' => pc_lock_pending_request($pdo, $channel, $month),
        'month' => $month,
        'from' => (string)($row['period_from'] ?? ($snapshot['from'] ?? '')),
        'to' => (string)($row['period_to'] ?? ($snapshot['to'] ?? '')),
        'summary' => pc_lock_summary($storedSummary, $filteredRows, $filtered),
        'rows' => $summaryOnly ? [] : $filteredRows,
        'options' => $summaryOnly ? [] : pc_lock_options($pdo, $channel, $storedRows),
    ]);
}

function pc_lock_handle_action(PDO $pdo, string $channel, array $input): void
{
    $action = trim((string)($input['action'] ?? ''));
    if (!in_array($action, ['lock', 'unlock'], true)) {
        return;
    }
    pc_lock_ensure_schema($pdo);
    $month = pc_lock_month_value($input['month_year'] ?? null);
    if ($month === '') {
        api_error('A valid month is required.', 422);
    }
    $user = current_user() ?: [];
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $userName = pc_lock_user_label($user);
    $note = trim((string)($input['note'] ?? ''));

    if ($action === 'unlock') {
        api_error('Unlocks must be submitted as approval requests.', 403);
    }


    if ($note === '') {
        api_error('Lock note is required.', 422);
    }
    $rows = $input['rows'] ?? null;
    $summary = $input['summary'] ?? null;
    if (!is_array($rows) || !is_array($summary)) {
        api_error('Lock snapshot is missing.', 422);
    }
    $from = pc_lock_date_value($input['from'] ?? null);
    $to = pc_lock_date_value($input['to'] ?? null);
    if ($from === '' || $to === '') {
        api_error('Lock period is required.', 422);
    }
    $snapshot = json_encode([
        'from' => $from,
        'to' => $to,
        'summary' => $summary,
        'rows' => array_values($rows),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($snapshot === false) {
        api_error('Unable to freeze product costing.', 500);
    }
    $stmt = $pdo->prepare("
        INSERT INTO product_cost_locks
            (channel, month_year, status, period_from, period_to, snapshot_json, note, locked_by, locked_by_name, locked_at, unlocked_by, unlocked_by_name, unlocked_at)
        VALUES (?, ?, 'LOCKED', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL, NULL, NULL)
        ON DUPLICATE KEY UPDATE
            status = 'LOCKED',
            period_from = VALUES(period_from),
            period_to = VALUES(period_to),
            snapshot_json = VALUES(snapshot_json),
            note = VALUES(note),
            locked_by = VALUES(locked_by),
            locked_by_name = VALUES(locked_by_name),
            locked_at = CURRENT_TIMESTAMP,
            unlocked_by = NULL,
            unlocked_by_name = NULL,
            unlocked_at = NULL
    ");
    $stmt->execute([$channel, $month, $from, $to, $snapshot, $note, $userId, $userName]);
    $saved = pc_lock_row($pdo, $channel, $month);
    api_json([
        'success' => true,
        'message' => 'This month is frozen. The report is not live.',
        'locked' => true,
        'lock' => pc_lock_public($saved),
    ]);
}
