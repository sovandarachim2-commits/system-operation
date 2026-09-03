<?php
/**
 * Product sets keep the storage location chosen at create/edit time.
 */

function product_set_ensure_table_auto_increment(PDO $pdo, string $table): void
{
    $allowed = ['storage_locations', 'current_inventory'];
    if (!in_array($table, $allowed, true)) {
        return;
    }

    try {
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            return;
        }

        $zeroCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE id = 0")->fetchColumn();
        if ($zeroCount > 0) {
            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`")->fetchColumn();
            if ($nextId <= 0) {
                $nextId = 1;
            }
            while ($zeroCount > 0) {
                $pdo->exec("UPDATE `{$table}` SET id = {$nextId} WHERE id = 0 LIMIT 1");
                $nextId++;
                $zeroCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE id = 0")->fetchColumn();
            }
        }

        $column = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'id'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $extra = strtolower((string)($column['Extra'] ?? ''));
        if (!str_contains($extra, 'auto_increment')) {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
        }

        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
        $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . ($maxId + 1));
    } catch (Throwable $e) {
        error_log('product_set_ensure_table_auto_increment ' . $table . ': ' . $e->getMessage());
    }
}

function product_set_ensure_schema(PDO $pdo): void
{
    try {
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote('product_sets'))->fetchColumn();
        if (!$exists) {
            return;
        }

        $column = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'storage_location_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $pdo->exec("ALTER TABLE product_sets ADD COLUMN storage_location_id INT NULL AFTER available_stock");
        }

        $pdo->exec("
            UPDATE product_sets ps
            INNER JOIN (
                SELECT product_set_id, MAX(location_id) AS location_id
                FROM (
                    SELECT
                        product_set_id,
                        CAST(SUBSTRING(
                            action_details,
                            LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'),
                            255
                        ) AS UNSIGNED) AS location_id
                    FROM product_set_audit_log
                    WHERE action_type IN ('created', 'updated')
                      AND action_details LIKE '%storage_location_id:%'
                ) parsed
                WHERE location_id > 0
                GROUP BY product_set_id
            ) loc ON loc.product_set_id = ps.id
            SET ps.storage_location_id = loc.location_id
            WHERE COALESCE(ps.storage_location_id, 0) = 0
        ");

        $pdo->exec("
            UPDATE current_inventory ci
            INNER JOIN product_sets ps
                ON TRIM(ci.item_name) = TRIM(ps.set_name)
                OR TRIM(ci.sku) = CONCAT('SET-', ps.id)
            SET ci.storage_location_id = ps.storage_location_id,
                ci.sku = CONCAT('SET-', ps.id)
            WHERE ps.storage_location_id IS NOT NULL
              AND ps.storage_location_id > 0
              AND (ci.storage_location_id IS NULL OR ci.storage_location_id <> ps.storage_location_id)
        ");

        $pdo->exec("
            UPDATE storage_locations
            SET is_active = 0, updated_at = CURRENT_TIMESTAMP
            WHERE location_code = 'SET'
              AND location_name IN ('Product Set', 'Product Sets', 'Product Sets Storage')
              AND COALESCE(is_default, 0) = 0
        ");
    } catch (Throwable $e) {
        error_log('product_set_ensure_schema: ' . $e->getMessage());
    }
}

function product_set_default_location_id(PDO $pdo): int
{
    try {
        $id = (int)$pdo->query("
            SELECT id FROM storage_locations
            WHERE is_active = 1 AND COALESCE(is_default, 0) = 1
            ORDER BY id ASC
            LIMIT 1
        ")->fetchColumn();
        return $id > 0 ? $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function product_set_upsert_inventory(
    PDO $pdo,
    string $setName,
    int $setId,
    float $quantity,
    float $unitCost,
    mixed $userId = null,
    int $locationId = 0
): void {
    $setName = trim($setName);
    if ($setName === '' || $setId <= 0) {
        return;
    }

    if ($locationId <= 0) {
        try {
            $stmt = $pdo->prepare("SELECT storage_location_id FROM product_sets WHERE id = ?");
            $stmt->execute([$setId]);
            $locationId = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $locationId = 0;
        }
    }
    if ($locationId <= 0) {
        $locationId = product_set_default_location_id($pdo);
    }
    if ($locationId <= 0) {
        return;
    }

    product_set_ensure_table_auto_increment($pdo, 'current_inventory');
    $sku = 'SET-' . $setId;
    $qty = max(0, $quantity);
    $cost = max(0, $unitCost);
    $uid = $userId !== null && $userId !== '' ? (int)$userId : null;

    try {
        $find = $pdo->prepare("
            SELECT id
            FROM current_inventory
            WHERE sku = ?
               OR (item_name = ? AND storage_location_id = ?)
            ORDER BY id DESC
            LIMIT 1
        ");
        $find->execute([$sku, $setName, $locationId]);
        $inventoryId = (int)$find->fetchColumn();

        if ($inventoryId > 0) {
            $pdo->prepare("
                UPDATE current_inventory
                SET item_name = ?,
                    sku = ?,
                    storage_location_id = ?,
                    quantity_on_hand = ?,
                    unit_cost = ?,
                    updated_by = ?,
                    last_updated = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$setName, $sku, $locationId, $qty, $cost, $uid, $inventoryId]);
            return;
        }

        $pdo->prepare("
            INSERT INTO current_inventory
                (item_name, sku, storage_location_id, quantity_on_hand, unit_cost, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$setName, $sku, $locationId, $qty, $cost, $uid]);
    } catch (Throwable $e) {
        error_log('product_set_upsert_inventory: ' . $e->getMessage());
    }
}
