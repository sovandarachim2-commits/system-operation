<?php

function offline_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sale_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_code VARCHAR(50) NOT NULL UNIQUE,
            customer_name VARCHAR(255) NULL,
            phone VARCHAR(100) NULL,
            location_id INT NOT NULL,
            sale_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            payment_method VARCHAR(100) NULL,
            paid_note VARCHAR(255) NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            cancel_note VARCHAR(255) NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_location_id (location_id),
            INDEX idx_sale_date (sale_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sale_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_id (order_id),
            INDEX idx_product_id (product_id),
            CONSTRAINT fk_offline_sale_items_order FOREIGN KEY (order_id) REFERENCES offline_sale_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sale_purchase_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            item_condition VARCHAR(30) NOT NULL,
            reason VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchase_order_id (order_id),
            INDEX idx_purchase_product_id (product_id),
            CONSTRAINT fk_offline_purchase_items_order FOREIGN KEY (order_id) REFERENCES offline_sale_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    offline_ensure_storage_location_flag($pdo);
    offline_ensure_sellers_schema($pdo);
    offline_ensure_stock_movement_reference_types($pdo);
    offline_ensure_payments_schema($pdo);
    offline_ensure_order_logs_schema($pdo);
    offline_ensure_auto_increment_ids($pdo);
}

/**
 * Repair tables that lost AUTO_INCREMENT (INSERT then uses id=0 and hits Duplicate entry '0').
 */
function offline_ensure_auto_increment_ids(PDO $pdo): void
{
    $tables = [
        'offline_sale_orders',
        'offline_sale_order_items',
        'offline_sale_purchase_items',
        'offline_sale_payments',
        'offline_sale_order_logs',
        'stock_movements',
    ];

    try {
        // Reassign orphan order id=0 before enabling AUTO_INCREMENT.
        $zeroOrder = $pdo->query("SELECT id FROM offline_sale_orders WHERE id = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($zeroOrder) {
            $nextOrderId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM offline_sale_orders")->fetchColumn();
            if ($nextOrderId <= 0) {
                $nextOrderId = 1;
            }
            $pdo->prepare("UPDATE offline_sale_order_items SET order_id = ? WHERE order_id = 0")->execute([$nextOrderId]);
            $pdo->prepare("UPDATE offline_sale_purchase_items SET order_id = ? WHERE order_id = 0")->execute([$nextOrderId]);
            $pdo->prepare("UPDATE offline_sale_payments SET order_id = ? WHERE order_id = 0")->execute([$nextOrderId]);
            $pdo->prepare("UPDATE offline_sale_order_logs SET order_id = ? WHERE order_id = 0")->execute([$nextOrderId]);
            $pdo->prepare("UPDATE offline_sale_orders SET id = ? WHERE id = 0")->execute([$nextOrderId]);
        }

        foreach ($tables as $table) {
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
            if (!$exists) {
                continue;
            }

            $zeroCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE id = 0")->fetchColumn();
            if ($zeroCount > 0) {
                $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`")->fetchColumn();
                if ($nextId <= 0) {
                    $nextId = 1;
                }
                // Only one id=0 is possible under PRIMARY KEY, but loop safely.
                while ($zeroCount > 0) {
                    $pdo->exec("UPDATE `{$table}` SET id = {$nextId} WHERE id = 0 LIMIT 1");
                    $nextId++;
                    $zeroCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE id = 0")->fetchColumn();
                }
            }

            $column = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'id'")->fetch(PDO::FETCH_ASSOC) ?: [];
            $extra = strtolower((string)($column['Extra'] ?? ''));
            if (!str_contains($extra, 'auto_increment')) {
                $autoStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` WHERE Extra LIKE '%auto_increment%'");
                $autoColumn = $autoStmt ? $autoStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($autoColumn && strtolower((string)($autoColumn['Field'] ?? '')) !== 'id') {
                    continue;
                }

                $indexStmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Column_name = 'id'");
                if (!$indexStmt || $indexStmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_id` (`id`)");
                }

                $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
            }

            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
            $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($maxId + 1));
        }
    } catch (Throwable $e) {
        error_log('offline_ensure_auto_increment_ids: ' . $e->getMessage());
    }
}

function offline_ensure_stock_movement_reference_types(PDO $pdo): void
{
    try {
        $column = $pdo->query("SHOW COLUMNS FROM stock_movements LIKE 'reference_type'")->fetch(PDO::FETCH_ASSOC);
        $type = strtolower((string)($column['Type'] ?? ''));
        if (str_starts_with($type, 'enum(') && !str_contains($type, 'offline_customer_purchase')) {
            $pdo->exec("
                ALTER TABLE stock_movements
                MODIFY reference_type ENUM(
                    'purchase','sale','adjustment','transfer','return','initial',
                    'offline_sale','offline_customer_purchase','offline_sale_edit',
                    'offline_purchase_edit','offline_sale_cancel','offline_customer_purchase_cancel'
                ) NULL DEFAULT 'adjustment'
            ");
        }

        $pdo->exec("
            UPDATE stock_movements
            SET reference_type = CASE
                WHEN notes LIKE 'Purchased from customer %' THEN 'offline_customer_purchase'
                WHEN notes LIKE 'Offline sale cancelled %' THEN 'offline_sale_cancel'
                WHEN notes LIKE 'Customer purchase cancelled %' THEN 'offline_customer_purchase_cancel'
                WHEN notes LIKE 'Offline sale edit restore %' THEN 'offline_sale_edit'
                WHEN notes LIKE 'Customer purchase edit reversal %' THEN 'offline_purchase_edit'
                WHEN notes LIKE 'Offline sale %' THEN 'offline_sale'
                ELSE reference_type
            END
            WHERE reference_type IS NULL OR reference_type = ''
        ");
    } catch (Throwable $e) {
        error_log('offline_ensure_stock_movement_reference_types: ' . $e->getMessage());
    }
}

function offline_ensure_sellers_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sellers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NULL,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $teamCols = array_column($pdo->query("SHOW COLUMNS FROM offline_teams")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('code',        $teamCols)) $pdo->exec("ALTER TABLE offline_teams ADD COLUMN code VARCHAR(50) NULL AFTER name");
        if (!in_array('leader_id',   $teamCols)) $pdo->exec("ALTER TABLE offline_teams ADD COLUMN leader_id INT NULL AFTER code");
        if (!in_array('area_route',  $teamCols)) $pdo->exec("ALTER TABLE offline_teams ADD COLUMN area_route VARCHAR(255) NULL AFTER leader_id");
        if (!in_array('description', $teamCols)) $pdo->exec("ALTER TABLE offline_teams ADD COLUMN description TEXT NULL AFTER area_route");
        if (!in_array('location_id', $teamCols)) $pdo->exec("ALTER TABLE offline_teams ADD COLUMN location_id INT NULL AFTER description");

        $hasTeamId = (bool)$pdo->query("SHOW COLUMNS FROM offline_sellers LIKE 'team_id'")->fetchColumn();
        if (!$hasTeamId) {
            $pdo->exec("ALTER TABLE offline_sellers ADD COLUMN team_id INT NULL AFTER id");
        }
        $hasSellerId = (bool)$pdo->query("SHOW COLUMNS FROM offline_sale_orders LIKE 'seller_id'")->fetchColumn();
        if (!$hasSellerId) {
            $pdo->exec("ALTER TABLE offline_sale_orders ADD COLUMN seller_id INT NULL AFTER location_id");
            $pdo->exec("ALTER TABLE offline_sale_orders ADD INDEX idx_seller_id (seller_id)");
        }
        $hasTeamIdInOrders = (bool)$pdo->query("SHOW COLUMNS FROM offline_sale_orders LIKE 'team_id'")->fetchColumn();
        if (!$hasTeamIdInOrders) {
            $pdo->exec("ALTER TABLE offline_sale_orders ADD COLUMN team_id INT NULL AFTER seller_id");
            $pdo->exec("ALTER TABLE offline_sale_orders ADD INDEX idx_order_team_id (team_id)");
        }
        $hasReceivedAmount = (bool)$pdo->query("SHOW COLUMNS FROM offline_sale_orders LIKE 'received_amount'")->fetchColumn();
        if (!$hasReceivedAmount) {
            $pdo->exec("ALTER TABLE offline_sale_orders ADD COLUMN received_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
        }
        $hasCustomerLocation = (bool)$pdo->query("SHOW COLUMNS FROM offline_sale_orders LIKE 'customer_location'")->fetchColumn();
        if (!$hasCustomerLocation) {
            $pdo->exec("ALTER TABLE offline_sale_orders ADD COLUMN customer_location VARCHAR(255) NULL AFTER phone");
        }
        $hasPaymentDate = (bool)$pdo->query("SHOW COLUMNS FROM offline_sale_orders LIKE 'payment_date'")->fetchColumn();
        if (!$hasPaymentDate) {
            $pdo->exec("ALTER TABLE offline_sale_orders ADD COLUMN payment_date DATE NULL AFTER received_amount");
        }
        $hasPurchaseTotal = (bool)$pdo->query("SHOW COLUMNS FROM offline_sale_orders LIKE 'purchase_total'")->fetchColumn();
        if (!$hasPurchaseTotal) {
            $pdo->exec("ALTER TABLE offline_sale_orders ADD COLUMN purchase_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount");
        }
    } catch (Throwable $e) {
        error_log('offline_ensure_sellers_schema: ' . $e->getMessage());
    }

    // Sync is_offline_location flag: 1 only for locations assigned to an active team
    try {
        $pdo->exec("
            UPDATE storage_locations sl
            SET sl.is_offline_location = (
                SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                FROM offline_teams ot
                WHERE ot.location_id = sl.id AND ot.is_active = 1
            )
        ");
    } catch (Throwable $e) {
        error_log('offline_sync_location_flag: ' . $e->getMessage());
    }
}

function offline_ensure_storage_location_flag(PDO $pdo): void
{
    try {
        $exists = (bool)$pdo->query("SHOW COLUMNS FROM storage_locations LIKE 'is_offline_location'")->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE storage_locations ADD COLUMN is_offline_location TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default");
            $pdo->exec("ALTER TABLE storage_locations ADD INDEX idx_offline_location (is_offline_location)");
        }
    } catch (Throwable $e) {
        error_log('offline_ensure_storage_location_flag: ' . $e->getMessage());
    }
}

function offline_current_user_label(?array $user): string
{
    $label = trim((string)($user['username'] ?? ''));
    if ($label === '') {
        $label = trim((string)($user['name'] ?? ''));
    }
    return $label !== '' ? $label : (string)($user['id'] ?? 'system');
}

function offline_locations(PDO $pdo, bool $offlineOnly = true): array
{
    offline_ensure_storage_location_flag($pdo);
    $where = $offlineOnly ? 'WHERE is_active = 1 AND is_offline_location = 1' : 'WHERE is_active = 1';
    return $pdo->query("SELECT id, location_code, location_name, is_default, is_offline_location FROM storage_locations {$where} ORDER BY is_offline_location DESC, location_code")->fetchAll(PDO::FETCH_ASSOC);
}

function offline_products(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            p.id,
            p.name,
            p.sku,
            p.barcode,
            COALESCE(NULLIF(p.unit, ''), 'Pcs') AS unit,
            COALESCE(pc.selling_price, p.cost, 0) AS selling_price,
            COALESCE(pc.total_cost, pc.original_cost, p.cost, 0) AS unit_cost,
            COALESCE(p.brand_id, 0) AS brand_id
        FROM products p
        LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = DATE_FORMAT(CURDATE(), '%Y-%m')
        WHERE p.active = 1
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function offline_location_products_with_stock(PDO $pdo, int $locationId, array $allProducts): array
{
    if ($locationId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT item_name, SUM(quantity_on_hand) AS qty
        FROM current_inventory
        WHERE storage_location_id = ?
        GROUP BY item_name
    ");
    $stmt->execute([$locationId]);
    $inventory = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inventory[$row['item_name']] = (float)$row['qty'];
    }

    $products = [];
    foreach ($allProducts as $product) {
        $entry = $product;
        $entry['available_stock'] = (float)($inventory[$product['name']] ?? 0.0);
        $products[] = $entry;
    }

    return offline_sort_location_products($products);
}

function offline_build_location_products_map(PDO $pdo, array $allProducts, array $locationIds): array
{
    $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds))));
    if (!$locationIds) {
        return [];
    }

    $inventory = [];
    $ph = implode(',', array_fill(0, count($locationIds), '?'));
    $stmt = $pdo->prepare("
        SELECT storage_location_id, item_name, SUM(quantity_on_hand) AS qty
        FROM current_inventory
        WHERE storage_location_id IN ($ph)
        GROUP BY storage_location_id, item_name
    ");
    $stmt->execute($locationIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inventory[(int)$row['storage_location_id']][$row['item_name']] = (float)$row['qty'];
    }

    $map = [];
    foreach ($locationIds as $locId) {
        $locProds = [];
        foreach ($allProducts as $product) {
            $entry = $product;
            $entry['available_stock'] = (float)($inventory[$locId][$product['name']] ?? 0.0);
            $locProds[] = $entry;
        }
        $map[$locId] = offline_sort_location_products($locProds);
    }

    return $map;
}

function offline_sort_location_products(array $products): array
{
    usort($products, static function (array $a, array $b): int {
        $stockA = (float)($a['available_stock'] ?? 0);
        $stockB = (float)($b['available_stock'] ?? 0);
        if ($stockA > 0 && $stockB <= 0) {
            return -1;
        }
        if ($stockB > 0 && $stockA <= 0) {
            return 1;
        }
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $products;
}

function offline_inventory_row(PDO $pdo, int $locationId, string $itemName): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM current_inventory WHERE storage_location_id = ? AND item_name = ? ORDER BY id ASC");
    $stmt->execute([$locationId, $itemName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return null;
    }
    $base = $rows[0];
    $qty = 0.0;
    $extra = [];
    foreach ($rows as $i => $row) {
        $qty += (float)($row['quantity_on_hand'] ?? 0);
        if ($i > 0) {
            $extra[] = (int)$row['id'];
        }
    }
    $base['quantity_on_hand'] = $qty;
    $base['duplicate_ids'] = $extra;
    return $base;
}

function offline_save_inventory(PDO $pdo, int $locationId, string $itemName, float $quantity, float $unitCost = 0.0, ?string $sku = null, ?int $existingId = null): int
{
    if ($existingId) {
        $stmt = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, unit_cost = ?, sku = ?, last_updated = NOW() WHERE id = ?");
        $stmt->execute([$quantity, $unitCost, $sku, $existingId]);
        return $existingId;
    }
    $row = offline_inventory_row($pdo, $locationId, $itemName);
    if ($row) {
        $stmt = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, unit_cost = ?, sku = ?, last_updated = NOW() WHERE id = ?");
        $stmt->execute([$quantity, $unitCost ?: (float)($row['unit_cost'] ?? 0), $sku ?? ($row['sku'] ?? null), (int)$row['id']]);
        return (int)$row['id'];
    }
    $stmt = $pdo->prepare("INSERT INTO current_inventory (storage_location_id, item_name, sku, quantity_on_hand, unit_cost, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$locationId, $itemName, $sku, $quantity, $unitCost]);
    return (int)$pdo->lastInsertId();
}

function offline_cleanup_inventory_duplicates(PDO $pdo, int $keeperId, array $duplicateIds): void
{
    $duplicateIds = array_values(array_filter(array_map('intval', $duplicateIds), static fn($id) => $id > 0 && $id !== $keeperId));
    if (!$duplicateIds) {
        return;
    }
    $ph = implode(',', array_fill(0, count($duplicateIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM current_inventory WHERE id <> ? AND id IN ($ph)");
    $stmt->execute(array_merge([$keeperId], $duplicateIds));
}

function offline_insert_stock_movement(PDO $pdo, int $productId, string $movementType, float $quantity, float $previousStock, float $newStock, string $referenceType, string $referenceId, string $notes, float $unitCost, string $createdBy, ?int $fromLocationId = null, ?int $toLocationId = null): void
{
    $cols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN));
    $hasLocCols = in_array('from_storage_location_id', $cols, true) && in_array('to_storage_location_id', $cols, true);
    $totalCost = abs($unitCost * $quantity);
    if ($hasLocCols) {
        $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by, from_storage_location_id, to_storage_location_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$productId, $movementType, $quantity, $previousStock, $newStock, $referenceType, $referenceId, $notes, $unitCost, $totalCost, $createdBy, $fromLocationId, $toLocationId]);
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$productId, $movementType, $quantity, $previousStock, $newStock, $referenceType, $referenceId, $notes, $unitCost, $totalCost, $createdBy]);
}

function offline_transfer_stock(PDO $pdo, int $productId, int $fromLocationId, int $toLocationId, float $quantity, string $movementType, string $notes, array $user): void
{
    if ($productId <= 0 || $fromLocationId <= 0 || $toLocationId <= 0 || $fromLocationId === $toLocationId || $quantity <= 0) {
        throw new RuntimeException('Product, locations, and quantity are required.');
    }
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }

    $source = offline_inventory_row($pdo, $fromLocationId, (string)$product['name']);
    if (!$source || (float)$source['quantity_on_hand'] < $quantity) {
        throw new RuntimeException('Insufficient stock in source location.');
    }

    $dest = offline_inventory_row($pdo, $toLocationId, (string)$product['name']);
    $sourcePrev = (float)$source['quantity_on_hand'];
    $sourceNew = $sourcePrev - $quantity;
    $destPrev = $dest ? (float)$dest['quantity_on_hand'] : 0.0;
    $destNew = $destPrev + $quantity;
    $unitCost = (float)($source['unit_cost'] ?? 0);
    $sku = $source['sku'] ?? null;
    $createdBy = offline_current_user_label($user);
    $detail = trim($notes . ' [From:' . $fromLocationId . ' To:' . $toLocationId . ']');

    $pdo->beginTransaction();
    try {
        offline_save_inventory($pdo, $fromLocationId, (string)$product['name'], $sourceNew, $unitCost, $sku, (int)$source['id']);
        offline_cleanup_inventory_duplicates($pdo, (int)$source['id'], (array)($source['duplicate_ids'] ?? []));
        offline_save_inventory($pdo, $toLocationId, (string)$product['name'], $destNew, $dest ? (float)($dest['unit_cost'] ?? $unitCost) : $unitCost, $sku, $dest ? (int)$dest['id'] : null);
        if ($dest) {
            offline_cleanup_inventory_duplicates($pdo, (int)$dest['id'], (array)($dest['duplicate_ids'] ?? []));
        }
        offline_insert_stock_movement($pdo, $productId, 'transfer', $quantity, $sourcePrev, $sourceNew, $movementType, '', $detail, $unitCost, $createdBy, $fromLocationId, $toLocationId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function offline_adjust_stock(PDO $pdo, int $productId, int $locationId, float $quantity, string $notes, array $user): void
{
    if ($productId <= 0 || $locationId <= 0 || $quantity == 0.0) {
        throw new RuntimeException('Product, offline location, and quantity are required.');
    }
    $stmt = $pdo->prepare("SELECT id, name, cost FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    $existing = offline_inventory_row($pdo, $locationId, (string)$product['name']);
    $prev = $existing ? (float)$existing['quantity_on_hand'] : 0.0;
    $new = $prev + $quantity;
    if ($new < 0) {
        throw new RuntimeException('Cannot adjust below zero.');
    }
    $unitCost = $existing ? (float)($existing['unit_cost'] ?? 0) : (float)($product['cost'] ?? 0);
    $createdBy = offline_current_user_label($user);
    $movementType = $quantity >= 0 ? 'in' : 'out';
    $refType = $quantity >= 0 ? 'offline_adjustment_in' : 'offline_adjustment_out';
    $detail = trim($notes . ' [Location:' . $locationId . ']');

    $pdo->beginTransaction();
    try {
        offline_save_inventory($pdo, $locationId, (string)$product['name'], $new, $unitCost, $existing['sku'] ?? null, $existing ? (int)$existing['id'] : null);
        if ($existing) {
            offline_cleanup_inventory_duplicates($pdo, (int)$existing['id'], (array)($existing['duplicate_ids'] ?? []));
        }
        offline_insert_stock_movement($pdo, $productId, $movementType, abs($quantity), $prev, $new, $refType, '', $detail, $unitCost, $createdBy, $quantity < 0 ? $locationId : null, $quantity > 0 ? $locationId : null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function offline_next_order_code(PDO $pdo): string
{
    $prefix = 'OFF-' . date('Ymd');
    $stmt = $pdo->prepare("SELECT order_code FROM offline_sale_orders WHERE order_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;
    if ($last !== '') {
        $next = ((int)substr($last, strlen($prefix))) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function offline_ensure_payments_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sale_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(100) NULL,
            paid_note VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_id (order_id),
            INDEX idx_payment_date (payment_date),
            CONSTRAINT fk_offline_sale_payments_order FOREIGN KEY (order_id) REFERENCES offline_sale_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $pdo->exec("
            INSERT INTO offline_sale_payments (order_id, payment_date, amount, payment_method, paid_note, created_by)
            SELECT
                o.id,
                COALESCE(o.payment_date, o.sale_date),
                CASE
                    WHEN o.received_amount > 0 THEN o.received_amount
                    WHEN o.status = 'paid' THEN o.total_amount
                    ELSE 0
                END,
                o.payment_method,
                o.paid_note,
                o.created_by
            FROM offline_sale_orders o
            WHERE (o.received_amount > 0 OR o.status = 'paid')
              AND NOT EXISTS (SELECT 1 FROM offline_sale_payments p WHERE p.order_id = o.id)
        ");
    } catch (Throwable $e) {
        error_log('offline_payments_backfill: ' . $e->getMessage());
    }
}

function offline_ensure_order_logs_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sale_order_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            description TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_log_order (order_id),
            INDEX idx_order_log_created (created_at),
            CONSTRAINT fk_offline_order_logs_order FOREIGN KEY (order_id) REFERENCES offline_sale_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $pdo->exec("
            INSERT INTO offline_sale_order_logs (order_id, action, description, created_by, created_at)
            SELECT o.id, 'created', 'Order created', o.created_by, o.created_at
            FROM offline_sale_orders o
            WHERE NOT EXISTS (SELECT 1 FROM offline_sale_order_logs l WHERE l.order_id = o.id AND l.action = 'created')
        ");
        $pdo->exec("
            INSERT INTO offline_sale_order_logs (order_id, action, description, created_by, created_at)
            SELECT
                p.order_id,
                'payment',
                CONCAT('Payment $', FORMAT(p.amount, 2), ' on ', p.payment_date,
                    IF(p.payment_method IS NOT NULL AND p.payment_method <> '', CONCAT(' (', p.payment_method, ')'), ''),
                    IF(p.paid_note IS NOT NULL AND p.paid_note <> '', CONCAT(' â€” ', p.paid_note), '')),
                p.created_by,
                COALESCE(p.created_at, NOW())
            FROM offline_sale_payments p
            WHERE NOT EXISTS (
                SELECT 1 FROM offline_sale_order_logs l
                WHERE l.order_id = p.order_id AND l.action = 'payment'
                  AND l.created_at = COALESCE(p.created_at, NOW())
                  AND l.description LIKE CONCAT('%', FORMAT(p.amount, 2), '%')
            )
        ");
    } catch (Throwable $e) {
        error_log('offline_order_logs_backfill: ' . $e->getMessage());
    }
}

function offline_log_order_activity(PDO $pdo, int $orderId, string $action, string $description, ?int $userId): void
{
    if ($orderId <= 0) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO offline_sale_order_logs (order_id, action, description, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$orderId, $action, $description ?: null, $userId]);
}

function offline_logs_for_orders(PDO $pdo, array $orderIds): array
{
    $orderIds = array_values(array_filter(array_map('intval', $orderIds), static fn($id) => $id > 0));
    if (!$orderIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("
        SELECT l.*, u.name AS created_by_name
        FROM offline_sale_order_logs l
        LEFT JOIN users u ON u.id = l.created_by
        WHERE l.order_id IN ($placeholders)
        ORDER BY l.created_at ASC, l.id ASC
    ");
    $stmt->execute($orderIds);
    $byOrder = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byOrder[(int)$row['order_id']][] = $row;
    }
    return $byOrder;
}

function offline_payments_for_orders(PDO $pdo, array $orderIds): array
{
    $orderIds = array_values(array_filter(array_map('intval', $orderIds), static fn($id) => $id > 0));
    if (!$orderIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("
        SELECT p.*, u.name AS created_by_name
        FROM offline_sale_payments p
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.order_id IN ($placeholders)
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmt->execute($orderIds);
    $byOrder = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byOrder[(int)$row['order_id']][] = $row;
    }
    return $byOrder;
}

function offline_sync_order_payment_totals(PDO $pdo, int $orderId, ?int $updatedBy = null): void
{
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_paid, MAX(payment_date) AS last_payment_date
        FROM offline_sale_payments
        WHERE order_id = ?
    ");
    $sumStmt->execute([$orderId]);
    $sums = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalPaid = (float)($sums['total_paid'] ?? 0);
    $lastPaymentDate = $sums['last_payment_date'] ?? null;

    $latestStmt = $pdo->prepare("
        SELECT payment_method, paid_note
        FROM offline_sale_payments
        WHERE order_id = ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 1
    ");
    $latestStmt->execute([$orderId]);
    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $orderStmt = $pdo->prepare("SELECT total_amount, status FROM offline_sale_orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$orderId]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (in_array(strtolower((string)($orderRow['status'] ?? '')), ['cancelled', 'canceled'], true)) {
        return;
    }
    $totalAmount = (float)($orderRow['total_amount'] ?? 0);
    $receivedAmount = min($totalAmount, $totalPaid);

    if ($totalAmount <= 0.009) {
        $status = 'paid';
    } elseif ($receivedAmount <= 0) {
        $status = 'unpaid';
    } elseif ($receivedAmount < $totalAmount) {
        $status = 'partial';
    } else {
        $status = 'paid';
    }

    $update = $pdo->prepare("
        UPDATE offline_sale_orders
        SET status = ?, received_amount = ?, payment_date = ?, payment_method = ?, paid_note = ?, updated_at = NOW()" . ($updatedBy !== null ? ', updated_by = ?' : '') . "
        WHERE id = ?
    ");
    $params = [
        $status,
        $receivedAmount,
        $lastPaymentDate ?: null,
        $latest['payment_method'] ?? null,
        $latest['paid_note'] ?? null,
    ];
    if ($updatedBy !== null) {
        $params[] = $updatedBy;
    }
    $params[] = $orderId;
    $update->execute($params);
}

function offline_add_order_payment(PDO $pdo, int $orderId, string $paymentDate, float $amount, ?string $paymentMethod, ?string $paidNote, ?int $userId): void
{
    if ($orderId <= 0 || $amount <= 0) {
        throw new RuntimeException('Invalid payment.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
        throw new RuntimeException('Invalid payment date.');
    }
    $stmt = $pdo->prepare("
        INSERT INTO offline_sale_payments (order_id, payment_date, amount, payment_method, paid_note, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$orderId, $paymentDate, $amount, $paymentMethod ?: null, $paidNote ?: null, $userId]);
    $notePart = $paidNote ? ' â€” ' . $paidNote : '';
    offline_log_order_activity(
        $pdo,
        $orderId,
        'payment',
        sprintf(
            'Payment $%s on %s%s%s',
            number_format($amount, 2),
            $paymentDate,
            $paymentMethod ? ' (' . $paymentMethod . ')' : '',
            $notePart
        ),
        $userId
    );
    offline_sync_order_payment_totals($pdo, $orderId, $userId);

    $refresh = $pdo->prepare("SELECT status, received_amount, total_amount FROM offline_sale_orders WHERE id = ? LIMIT 1");
    $refresh->execute([$orderId]);
    $row = $refresh->fetch(PDO::FETCH_ASSOC) ?: [];
    offline_log_order_activity(
        $pdo,
        $orderId,
        'updated',
        sprintf(
            'Order updated â€” status: %s, paid $%s of $%s',
            (string)($row['status'] ?? ''),
            number_format((float)($row['received_amount'] ?? 0), 2),
            number_format((float)($row['total_amount'] ?? 0), 2)
        ),
        $userId
    );
}

function offline_order_paid_from_payments(array $payments, array $order): float
{
    if ($payments) {
        $sum = 0.0;
        foreach ($payments as $payment) {
            $sum += (float)($payment['amount'] ?? 0);
        }
        return min($sum, (float)($order['total_amount'] ?? 0));
    }
    return offline_order_paid_amount_legacy($order);
}

function offline_order_display_status(array $order, array $payments = []): string
{
    $storedStatus = strtolower(trim((string)($order['status'] ?? '')));
    if (in_array($storedStatus, ['cancelled', 'canceled'], true)) {
        return 'cancelled';
    }
    $total = (float)($order['total_amount'] ?? 0);
    $paid = offline_order_paid_from_payments($payments, $order);
    if ($total <= 0 || $paid >= $total - 0.009) {
        return 'paid';
    }
    if ($paid > 0) {
        return 'partial';
    }
    return 'unpaid';
}

function offline_parse_filter_date(?string $value, ?string $default = null): ?string
{
    $value = trim((string)($value ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return $default;
}

function offline_optional_filter_date(?string $value): ?string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return null;
}

function offline_orders_list_query(array $filters): string
{
    $params = [];
    foreach (['date_from', 'date_to', 'search', 'status'] as $key) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    $query = 'offline_sale_orders.php';
    if ($params) {
        $query .= '?' . http_build_query($params);
    }

    return $query;
}

function offline_status_badge_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid' => 'Paid',
        'partial' => 'Partially Paid',
        'unpaid' => 'Unpaid',
        'cancelled', 'canceled' => 'Cancelled',
        default => ucfirst($status),
    };
}
function offline_status_badge_icon_class(string $status): string
{
    return match (strtolower(trim($status))) {
        'paid' => 'bi-check-lg',
        'partial' => 'bi-pie-chart-fill',
        'unpaid' => 'bi-exclamation-triangle-fill',
        'cancelled', 'canceled' => 'bi-x-lg',
        default => 'bi-circle-fill',
    };
}

function offline_status_badge_html(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'canceled') {
        $status = 'cancelled';
    }
    if (!in_array($status, ['paid', 'unpaid', 'partial', 'cancelled'], true)) {
        $status = 'unpaid';
    }
    $label = offline_status_badge_label($status);
    $icon = offline_status_badge_icon_class($status);

    return sprintf(
        '<span class="offline-status-badge offline-status-%1$s" role="status">'
        . '<span class="offline-status-icon" aria-hidden="true"><i class="bi %2$s"></i></span>'
        . '<span class="offline-status-text">%3$s</span></span>',
        htmlspecialchars($status, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}

function offline_status_badge_styles(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    echo <<<'CSS'
<style>
.offline-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.4rem 0.85rem 0.4rem 0.5rem;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.9rem;
    line-height: 1;
    color: #fff;
    white-space: nowrap;
    vertical-align: middle;
}
.offline-status-badge .offline-status-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1rem;
    line-height: 1;
}
.offline-status-badge .offline-status-text {
    letter-spacing: 0.01em;
}
.offline-status-paid {
    background: #1a7a3c;
}
.offline-status-paid .offline-status-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: #5ecf76;
    color: #fff;
    font-size: 0.95rem;
}
.offline-status-unpaid {
    background: #f0a500;
    color: #fff;
}
.offline-status-unpaid .offline-status-icon {
    color: #1a1a1a;
    font-size: 1.15rem;
}
.offline-status-partial {
    background: #e8740a;
    color: #fff;
}
.offline-status-partial .offline-status-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.28);
    color: #fff;
    font-size: 0.85rem;
}
.offline-status-cancelled {
    background: #dc3545;
    color: #fff;
}
.offline-status-cancelled .offline-status-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.22);
    color: #fff;
    font-size: 0.85rem;
}
</style>
CSS;
}

function offline_order_paid_amount_legacy(array $order): float
{
    $total = (float)($order['total_amount'] ?? 0);
    $received = (float)($order['received_amount'] ?? 0);
    if ($received > 0) {
        return min($received, $total);
    }
    if (($order['status'] ?? '') === 'paid') {
        return $total;
    }
    return 0.0;
}

/**
 * @param array<int, array<string, mixed>> $items keyed by product id
 */
function offline_update_sale_order(
    PDO $pdo,
    int $orderId,
    string $customerName,
    ?string $phone,
    ?string $customerLocation,
    int $locationId,
    ?int $teamId,
    string $saleDate,
    float $discount,
    array $items,
    array $purchaseItems,
    float $targetReceivedAmount,
    array $user,
    ?string $extraPaymentMethod = null,
    ?string $extraPaymentNote = null
): void {
    $orderStmt = $pdo->prepare("SELECT * FROM offline_sale_orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $orderCode = (string)($order['order_code'] ?? '');
    $oldLocationId = (int)($order['location_id'] ?? 0);

    $oldItemStmt = $pdo->prepare("
        SELECT product_id, product_name, quantity, unit_price, unit_cost
        FROM offline_sale_order_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $oldItemStmt->execute([$orderId]);
    $oldRows = $oldItemStmt->fetchAll(PDO::FETCH_ASSOC);
    $oldPurchaseStmt = $pdo->prepare("
        SELECT product_id, product_name, quantity, unit_price, item_condition, reason
        FROM offline_sale_purchase_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $oldPurchaseStmt->execute([$orderId]);
    $oldPurchaseRows = $oldPurchaseStmt->fetchAll(PDO::FETCH_ASSOC);

    $oldQtyByProduct = [];
    foreach ($oldRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $oldQtyByProduct[$pid] = ($oldQtyByProduct[$pid] ?? 0.0) + (float)($row['quantity'] ?? 0);
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float)$item['selling_price'] * (float)$item['quantity'];
    }
    $purchaseTotal = 0.0;
    foreach ($purchaseItems as $item) {
        $purchaseTotal += (float)$item['purchase_price'] * (float)$item['quantity'];
    }
    $total = max(0, $subtotal - $discount - $purchaseTotal);

    $sameLocation = $oldLocationId > 0 && $oldLocationId === $locationId;
    foreach ($items as $pid => $item) {
        $qty = (float)$item['quantity'];
        $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
        $onHand = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
        $credit = $sameLocation ? (float)($oldQtyByProduct[$pid] ?? 0.0) : 0.0;
        if ($onHand + $credit + 0.009 < $qty) {
            throw new RuntimeException('Insufficient offline stock for ' . $item['name']);
        }
    }

    $payments = offline_payments_for_orders($pdo, [$orderId])[$orderId] ?? [];
    $alreadyPaid = 0.0;
    foreach ($payments as $payment) {
        $alreadyPaid += (float)($payment['amount'] ?? 0);
    }
    if ($targetReceivedAmount + 0.009 < $alreadyPaid) {
        throw new RuntimeException(
            'Received amount cannot be less than payments already recorded ($' . number_format($alreadyPaid, 2) . ').'
        );
    }

    $userLabel = offline_current_user_label($user);
    $userId = isset($user['id']) ? (int)$user['id'] : null;

    $pdo->beginTransaction();
    try {
        foreach ($oldRows as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (float)($row['quantity'] ?? 0);
            if ($qty <= 0 || $oldLocationId <= 0) {
                continue;
            }
            $name = (string)$row['product_name'];
            $inv = offline_inventory_row($pdo, $oldLocationId, $name);
            $prev = (float)($inv['quantity_on_hand'] ?? 0);
            $new = $prev + $qty;
            $unitCost = (float)($row['unit_cost'] ?? ($inv['unit_cost'] ?? 0));
            if ($inv) {
                offline_save_inventory($pdo, $oldLocationId, $name, $new, $unitCost, $inv['sku'] ?? null, (int)$inv['id']);
                offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            } else {
                offline_save_inventory($pdo, $oldLocationId, $name, $new, $unitCost, null, null);
            }
            if ($pid > 0) {
                offline_insert_stock_movement(
                    $pdo,
                    $pid,
                    'in',
                    $qty,
                    $prev,
                    $new,
                    'offline_sale_edit',
                    $orderCode,
                    'Offline sale edit restore ' . $orderCode . ' [Location:' . $oldLocationId . ']',
                    $unitCost,
                    $userLabel,
                    null,
                    $oldLocationId
                );
            }
        }

        foreach ($oldPurchaseRows as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (float)($row['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $oldLocationId <= 0) {
                continue;
            }
            $name = (string)$row['product_name'];
            $inv = offline_inventory_row($pdo, $oldLocationId, $name);
            $prev = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
            if (!$inv || $prev + 0.009 < $qty) {
                throw new RuntimeException('Cannot edit because purchased stock for ' . $name . ' is no longer available.');
            }
            $new = $prev - $qty;
            $unitCost = (float)($row['unit_price'] ?? ($inv['unit_cost'] ?? 0));
            offline_save_inventory($pdo, $oldLocationId, $name, $new, (float)($inv['unit_cost'] ?? $unitCost), $inv['sku'] ?? null, (int)$inv['id']);
            offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            offline_insert_stock_movement($pdo, $pid, 'out', $qty, $prev, $new, 'offline_purchase_edit', $orderCode, 'Customer purchase edit reversal ' . $orderCode . ' [Location:' . $oldLocationId . ']', $unitCost, $userLabel, $oldLocationId, null);
        }

        $pdo->prepare("DELETE FROM offline_sale_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM offline_sale_purchase_items WHERE order_id = ?")->execute([$orderId]);

        $itemStmt = $pdo->prepare("
            INSERT INTO offline_sale_order_items (order_id, product_id, product_name, quantity, unit_price, line_total, unit_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $pid => $item) {
            $qty = (float)$item['quantity'];
            $inv = offline_inventory_row($pdo, $locationId, (string)$item['name']);
            if (!$inv) {
                throw new RuntimeException('Insufficient offline stock for ' . $item['name']);
            }
            $prev = (float)$inv['quantity_on_hand'];
            $new = $prev - $qty;
            $unitCost = (float)($inv['unit_cost'] ?? $item['unit_cost'] ?? 0);
            offline_save_inventory($pdo, $locationId, (string)$item['name'], $new, $unitCost, $inv['sku'] ?? null, (int)$inv['id']);
            offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            $lineTotal = (float)$item['selling_price'] * $qty;
            $itemStmt->execute([$orderId, $pid, $item['name'], $qty, $item['selling_price'], $lineTotal, $unitCost]);
            offline_insert_stock_movement(
                $pdo,
                (int)$pid,
                'out',
                $qty,
                $prev,
                $new,
                'offline_sale',
                $orderCode,
                'Offline sale ' . $orderCode . ' [Location:' . $locationId . ']',
                $unitCost,
                $userLabel,
                $locationId,
                null
            );
        }

        $purchaseStmt = $pdo->prepare("
            INSERT INTO offline_sale_purchase_items
                (order_id, product_id, product_name, quantity, unit_price, line_total, item_condition, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($purchaseItems as $item) {
            $pid = (int)$item['id'];
            $qty = (float)$item['quantity'];
            $price = (float)$item['purchase_price'];
            $name = (string)$item['name'];
            $inv = offline_inventory_row($pdo, $locationId, $name);
            $prev = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
            $new = $prev + $qty;
            $sku = $inv['sku'] ?? ($item['sku'] ?? null);
            $oldCost = $inv ? (float)($inv['unit_cost'] ?? 0) : 0.0;
            $weightedCost = $new > 0 ? (($prev * $oldCost) + ($qty * $price)) / $new : $price;
            offline_save_inventory($pdo, $locationId, $name, $new, $weightedCost, $sku, $inv ? (int)$inv['id'] : null);
            if ($inv) {
                offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            }
            $purchaseStmt->execute([$orderId, $pid, $name, $qty, $price, $price * $qty, $item['condition'], $item['reason']]);
            offline_insert_stock_movement($pdo, $pid, 'in', $qty, $prev, $new, 'offline_customer_purchase', $orderCode, 'Purchased from customer ' . $orderCode . ' - ' . $item['condition'] . ' / ' . $item['reason'] . ' [Location:' . $locationId . ']', $price, $userLabel, null, $locationId);
        }

        $upd = $pdo->prepare("
            UPDATE offline_sale_orders
            SET customer_name = ?, phone = ?, customer_location = ?, location_id = ?, team_id = ?,
                sale_date = ?, subtotal = ?, discount = ?, purchase_total = ?, total_amount = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([
            $customerName ?: null,
            $phone ?: null,
            $customerLocation ?: null,
            $locationId,
            $teamId,
            $saleDate,
            $subtotal,
            $discount,
            $purchaseTotal,
            $total,
            $userId,
            $orderId,
        ]);

        $extraPayment = max(0, $targetReceivedAmount - $alreadyPaid);
        if ($extraPayment > 0.009) {
            offline_add_order_payment($pdo, $orderId, $saleDate, $extraPayment, $extraPaymentMethod, $extraPaymentNote, $userId);
        } else {
            offline_sync_order_payment_totals($pdo, $orderId, $userId);
        }

        offline_log_order_activity(
            $pdo,
            $orderId,
            'updated',
            sprintf(
                'Order updated â€” %d product(s), discount $%s, total $%s',
                count($items),
                number_format($discount, 2),
                number_format($total, 2)
            ),
            $userId
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function offline_cancel_sale_order(PDO $pdo, int $orderId, ?string $cancelNote, array $user): void
{
    $orderStmt = $pdo->prepare("SELECT * FROM offline_sale_orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $status = strtolower(trim((string)($order['status'] ?? '')));
    if (in_array($status, ['cancelled', 'canceled'], true)) {
        throw new RuntimeException('Order is already cancelled.');
    }

    $itemStmt = $pdo->prepare("
        SELECT product_id, product_name, quantity, unit_cost
        FROM offline_sale_order_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    $purchaseStmt = $pdo->prepare("
        SELECT product_id, product_name, quantity, unit_price
        FROM offline_sale_purchase_items
        WHERE order_id = ?
        ORDER BY id
    ");
    $purchaseStmt->execute([$orderId]);
    $purchaseItems = $purchaseStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        throw new RuntimeException('No order items found to cancel.');
    }

    $locationId = (int)($order['location_id'] ?? 0);
    if ($locationId <= 0) {
        throw new RuntimeException('Order stock location is missing.');
    }

    $orderCode = (string)($order['order_code'] ?? '');
    $userLabel = offline_current_user_label($user);
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $note = trim((string)($cancelNote ?? ''));

    $pdo->beginTransaction();
    try {
        foreach ($items as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (float)($row['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $name = (string)$row['product_name'];
            $inv = offline_inventory_row($pdo, $locationId, $name);
            $prev = $inv ? (float)($inv['quantity_on_hand'] ?? 0) : 0.0;
            $new = $prev + $qty;
            $unitCost = (float)($row['unit_cost'] ?? ($inv['unit_cost'] ?? 0));
            $sku = $inv['sku'] ?? null;
            offline_save_inventory($pdo, $locationId, $name, $new, $unitCost, $sku, $inv ? (int)$inv['id'] : null);
            if ($inv) {
                offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            }
            offline_insert_stock_movement(
                $pdo,
                $pid,
                'in',
                $qty,
                $prev,
                $new,
                'offline_sale_cancel',
                $orderCode,
                'Offline sale cancelled ' . $orderCode . ' [Location:' . $locationId . ']' . ($note !== '' ? ' - ' . $note : ''),
                $unitCost,
                $userLabel,
                null,
                $locationId
            );
        }

        foreach ($purchaseItems as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (float)($row['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $name = (string)$row['product_name'];
            $inv = offline_inventory_row($pdo, $locationId, $name);
            $prev = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
            if (!$inv || $prev + 0.009 < $qty) {
                throw new RuntimeException('Cannot cancel because purchased stock for ' . $name . ' is no longer available.');
            }
            $new = $prev - $qty;
            $unitCost = (float)($row['unit_price'] ?? ($inv['unit_cost'] ?? 0));
            offline_save_inventory($pdo, $locationId, $name, $new, (float)($inv['unit_cost'] ?? $unitCost), $inv['sku'] ?? null, (int)$inv['id']);
            offline_cleanup_inventory_duplicates($pdo, (int)$inv['id'], (array)($inv['duplicate_ids'] ?? []));
            offline_insert_stock_movement($pdo, $pid, 'out', $qty, $prev, $new, 'offline_customer_purchase_cancel', $orderCode, 'Customer purchase cancelled ' . $orderCode . ' [Location:' . $locationId . ']' . ($note !== '' ? ' - ' . $note : ''), $unitCost, $userLabel, $locationId, null);
        }

        $upd = $pdo->prepare("
            UPDATE offline_sale_orders
            SET status = 'cancelled', cancel_note = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$note !== '' ? $note : null, $userId, $orderId]);
        offline_log_order_activity($pdo, $orderId, 'cancelled', 'Order cancelled' . ($note !== '' ? ' - ' . $note : ''), $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

