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
        $channel === 'offline' ? 'offline' : 'online',
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
