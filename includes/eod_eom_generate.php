<?php
declare(strict_types=1);

/**
 * Shared EOD/EOM draft report generators for admin UI and report API.
 */

/**
 * Repair EOD/EOM tables that lost AUTO_INCREMENT (INSERT then uses id=0 -> Duplicate entry '0').
 */
function eod_eom_ensure_auto_increment_ids(PDO $pdo): void
{
    $tables = [
        'eod_stock_reports',
        'eod_stock_report_details',
        'eom_stock_reports',
        'eom_stock_report_details',
        'eod_eom_report_attachments',
    ];

    try {
        foreach ($tables as $table) {
            $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
            if (!$exists) {
                continue;
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
                $autoStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` WHERE Extra LIKE '%auto_increment%'");
                $autoColumn = $autoStmt ? $autoStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($autoColumn && strtolower((string)($autoColumn['Field'] ?? '')) !== 'id') {
                    continue;
                }

                $indexStmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Column_name = 'id'");
                if (!$indexStmt || $indexStmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
                }

                $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
            }

            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
            $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . ($maxId + 1));
        }
    } catch (Throwable $e) {
        error_log('eod_eom_ensure_auto_increment_ids: ' . $e->getMessage());
    }
}

function eod_eom_ensure_physical_count_columns(PDO $pdo): void
{
    $tables = ['eod_stock_report_details', 'eom_stock_report_details'];

    try {
        foreach ($tables as $table) {
            $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
            if (!$exists) {
                continue;
            }

            $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            $columnSet = array_fill_keys(array_map('strtolower', $columns ?: []), true);

            if (!isset($columnSet['final_quantity'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `final_quantity` DECIMAL(15,2) DEFAULT NULL AFTER `notes`");
            }
            if (!isset($columnSet['final_counted_by'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `final_counted_by` INT NULL AFTER `final_quantity`");
            }
            if (!isset($columnSet['final_counted_at'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `final_counted_at` DATETIME NULL AFTER `final_counted_by`");
            }
        }
    } catch (Throwable $e) {
        error_log('eod_eom_ensure_physical_count_columns: ' . $e->getMessage());
    }
}

function eod_eom_ensure_difference_review_columns(PDO $pdo): void
{
    $tables = ['eod_stock_reports', 'eom_stock_reports'];

    try {
        foreach ($tables as $table) {
            $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
            if (!$exists) {
                continue;
            }

            $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            $columnSet = array_fill_keys(array_map('strtolower', $columns ?: []), true);

            if (!isset($columnSet['difference_reviewed_by'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `difference_reviewed_by` INT NULL AFTER `finalized_by`");
            }
            if (!isset($columnSet['difference_reviewed_at'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `difference_reviewed_at` DATETIME NULL AFTER `difference_reviewed_by`");
            }
            if (!isset($columnSet['difference_review_notes'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `difference_review_notes` TEXT NULL AFTER `difference_reviewed_at`");
            }
            if (!isset($columnSet['difference_review_status'])) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `difference_review_status` VARCHAR(20) NULL AFTER `difference_review_notes`");
            }
        }
    } catch (Throwable $e) {
        error_log('eod_eom_ensure_difference_review_columns: ' . $e->getMessage());
    }
}

function eod_eom_report_detail_table(string $reportType): string
{
    return strtolower(trim($reportType)) === 'eom'
        ? 'eom_stock_report_details'
        : 'eod_stock_report_details';
}

function eod_eom_report_detail_fk(string $reportType): string
{
    return strtolower(trim($reportType)) === 'eom'
        ? 'eom_report_id'
        : 'eod_report_id';
}

function eod_eom_default_storage_location_id(PDO $pdo): int
{
    try {
        return (int)($pdo->query('SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1')->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('eod_eom_default_storage_location_id: ' . $e->getMessage());
        return 0;
    }
}

function eod_eom_missing_final_quantity_count(PDO $pdo, string $reportType, int $reportId, bool $defaultLocationOnly = false): int
{
    if ($reportId <= 0) {
        return 0;
    }

    eod_eom_ensure_physical_count_columns($pdo);

    $table = eod_eom_report_detail_table($reportType);
    $fk = eod_eom_report_detail_fk($reportType);
    try {
        $params = [$reportId];
        $locationSql = '';
        if ($defaultLocationOnly) {
            $defaultLocationId = eod_eom_default_storage_location_id($pdo);
            if ($defaultLocationId > 0) {
                $locationSql = ' AND storage_location_id = ?';
                $params[] = $defaultLocationId;
            }
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$fk}` = ?{$locationSql} AND final_quantity IS NULL");
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('eod_eom_missing_final_quantity_count: ' . $e->getMessage());
        return 0;
    }
}

function eod_eom_difference_summary(PDO $pdo, string $reportType, int $reportId, bool $defaultLocationOnly = false): array
{
    if ($reportId <= 0) {
        return ['missing_qty' => 0.0, 'extra_qty' => 0.0, 'net_difference' => 0.0, 'has_difference' => false];
    }

    eod_eom_ensure_physical_count_columns($pdo);

    $table = eod_eom_report_detail_table($reportType);
    $fk = eod_eom_report_detail_fk($reportType);
    $systemColumn = strtolower(trim($reportType)) === 'eom' ? 'closing_quantity' : 'quantity_on_hand';

    try {
        $params = [$reportId];
        $locationSql = '';
        if ($defaultLocationOnly) {
            $defaultLocationId = eod_eom_default_storage_location_id($pdo);
            if ($defaultLocationId > 0) {
                $locationSql = ' AND storage_location_id = ?';
                $params[] = $defaultLocationId;
            }
        }
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN final_quantity IS NOT NULL AND final_quantity < {$systemColumn} THEN {$systemColumn} - final_quantity ELSE 0 END), 0) AS missing_qty,
                COALESCE(SUM(CASE WHEN final_quantity IS NOT NULL AND final_quantity > {$systemColumn} THEN final_quantity - {$systemColumn} ELSE 0 END), 0) AS extra_qty,
                COALESCE(SUM(CASE WHEN final_quantity IS NOT NULL THEN final_quantity - {$systemColumn} ELSE 0 END), 0) AS net_difference
            FROM `{$table}`
            WHERE `{$fk}` = ?{$locationSql}
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $missing = (float)($row['missing_qty'] ?? 0);
        $extra = (float)($row['extra_qty'] ?? 0);
        $net = (float)($row['net_difference'] ?? 0);
        return [
            'missing_qty' => $missing,
            'extra_qty' => $extra,
            'net_difference' => $net,
            'has_difference' => abs($net) > 0.005 || $missing > 0.005 || $extra > 0.005,
        ];
    } catch (Throwable $e) {
        error_log('eod_eom_difference_summary: ' . $e->getMessage());
        return ['missing_qty' => 0.0, 'extra_qty' => 0.0, 'net_difference' => 0.0, 'has_difference' => false];
    }
}

function eod_eom_returned_orders_sql(PDO $pdo): string
{
    try {
        $pdo->query('SELECT 1 FROM return_items LIMIT 1');
    } catch (Throwable $e) {
        return "(
            SELECT
                o.order_code as inv,
                o.updated_at as return_date
            FROM orders o
            WHERE o.is_returned = 1
        )";
    }

    return "(
        SELECT
            ri.inv,
            ri.date_time as return_date
        FROM return_items ri

        UNION ALL

        SELECT
            o.order_code as inv,
            o.updated_at as return_date
        FROM orders o
        WHERE o.is_returned = 1
          AND NOT EXISTS (
              SELECT 1
              FROM return_items ri2
              WHERE ri2.inv = o.order_code
          )
    )";
}

function eod_eom_generate_eod(PDO $pdo, string $reportDate, ?int $userId): array
{
    $reportDate = trim($reportDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        throw new InvalidArgumentException('Invalid EOD report date.');
    }

    $stmt = $pdo->prepare('SELECT id FROM eod_stock_reports WHERE report_date = ? LIMIT 1');
    $stmt->execute([$reportDate]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        throw new RuntimeException("EOD report for $reportDate already exists");
    }

    eod_eom_ensure_auto_increment_ids($pdo);
    eod_eom_ensure_physical_count_columns($pdo);

    $returnedOrdersSql = eod_eom_returned_orders_sql($pdo);
    $inventorySql = <<<SQL

                    SELECT
                        combined.item_name,
                        combined.sku,
                        combined.quantity_on_hand,
                        combined.available_quantity,
                        combined.unit_cost,
                        combined.total_value,
                        combined.storage_location_id,
                        combined.last_updated,
                        COALESCE(prev.opening_quantity, 0) as opening_quantity,
                        COALESCE(received.daily_received, 0) as daily_received,
                        COALESCE(returns.return_quantity, 0) as return_quantity,
                        COALESCE(moves.movements_in, 0) as movements_in,
                        COALESCE(moves.movements_out, 0) as movements_out,
                        COALESCE(moves.transfer_in, 0) as transfer_in,
                        COALESCE(moves.transfer_out, 0) as transfer_out,
                        COALESCE(moves.adjustments, 0) as adjustments
                    FROM (
                        SELECT
                            raw.item_name,
                            raw.sku,
                            raw.storage_location_id,
                            SUM(raw.quantity_on_hand) as quantity_on_hand,
                            SUM(raw.available_quantity) as available_quantity,
                            SUM(raw.total_value) as total_value,
                            CASE
                                WHEN SUM(raw.quantity_on_hand) > 0 THEN SUM(raw.total_value) / SUM(raw.quantity_on_hand)
                                ELSE 0
                            END as unit_cost,
                            MAX(raw.last_updated) as last_updated
                        FROM (
                            SELECT
                                ci.item_name,
                                ci.sku,
                                ci.quantity_on_hand,
                                ci.quantity_on_hand as available_quantity,
                                ci.total_value,
                                ci.storage_location_id,
                                ci.last_updated
                            FROM current_inventory ci
                            LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
                            WHERE ps.id IS NULL

                            UNION ALL

                            SELECT
                                ps.set_name as item_name,
                                CONCAT('SET-', ps.id) as sku,
                                ps.available_stock as quantity_on_hand,
                                ps.available_stock as available_quantity,
                                (ps.available_stock * ps.selling_price) as total_value,
                                loc_finder.location_id as storage_location_id,
                                ps.created_at as last_updated
                            FROM product_sets ps
                            LEFT JOIN (
                                SELECT DISTINCT product_set_id,
                                       CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) as location_id
                                FROM product_set_audit_log
                                WHERE action_type IN ('created', 'updated')
                                  AND action_details LIKE '%storage_location_id:%'
                            ) loc_finder ON ps.id = loc_finder.product_set_id
                            WHERE ps.is_active = 1
                        ) raw
                        GROUP BY raw.item_name, raw.sku, raw.storage_location_id
                    ) combined
                    LEFT JOIN (
                        SELECT
                            esrd.item_name,
                            esrd.sku,
                            esrd.storage_location_id,
                            esrd.quantity_on_hand as opening_quantity
                        FROM eod_stock_report_details esrd
                        JOIN eod_stock_reports esr ON esr.id = esrd.eod_report_id
                        JOIN (
                            SELECT
                                latest_esrd.item_name,
                                latest_esrd.sku,
                                latest_esrd.storage_location_id,
                                MAX(latest_esr.report_date) as latest_report_date
                            FROM eod_stock_report_details latest_esrd
                            JOIN eod_stock_reports latest_esr ON latest_esr.id = latest_esrd.eod_report_id
                            WHERE latest_esr.status = 'finalized'
                              AND latest_esr.report_date < ?
                            GROUP BY latest_esrd.item_name, latest_esrd.sku, latest_esrd.storage_location_id
                        ) latest_prev
                            ON latest_prev.item_name = esrd.item_name
                           AND latest_prev.sku <=> esrd.sku
                           AND latest_prev.storage_location_id <=> esrd.storage_location_id
                           AND latest_prev.latest_report_date = esr.report_date
                        WHERE esr.status = 'finalized'
                    ) prev
                        ON prev.item_name = combined.item_name
                       AND prev.sku <=> combined.sku
                       AND prev.storage_location_id <=> combined.storage_location_id
                    LEFT JOIN (
                        SELECT
                            received_lines.item_name,
                            received_lines.sku,
                            received_lines.storage_location_id,
                            SUM(received_lines.daily_received) AS daily_received
                        FROM (
                            SELECT
                                sri.item_name,
                                sri.sku,
                                sri.storage_location_id,
                                SUM(sri.quantity_received) AS daily_received
                            FROM storage_receipt_items sri
                            JOIN storage_receipts sr ON sri.receipt_id = sr.id
                            INNER JOIN purchase_receiving pr ON pr.id = sr.receiving_id
                            WHERE pr.receiving_date = ?
                            GROUP BY sri.item_name, sri.sku, sri.storage_location_id

                            UNION ALL

                            SELECT
                                COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(poi.item_name), '')) AS item_name,
                                COALESCE(NULLIF(TRIM(poi.sku), ''), NULLIF(TRIM(p.sku), ''), '') AS sku,
                                NULL AS storage_location_id,
                                SUM(pri.quantity_received) AS daily_received
                            FROM purchase_receiving_items pri
                            INNER JOIN purchase_receiving pr ON pr.id = pri.receiving_id
                            INNER JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
                            LEFT JOIN products p ON p.id = poi.product_id
                            WHERE pr.receiving_date = ?
                              AND NOT EXISTS (
                                  SELECT 1 FROM storage_receipts sr WHERE sr.receiving_id = pr.id
                              )
                            GROUP BY item_name, sku, storage_location_id

                            UNION ALL

                            SELECT
                                ps.set_name AS item_name,
                                CONCAT('SET-', ps.id) AS sku,
                                loc_finder.location_id AS storage_location_id,
                                ps.total_created AS daily_received
                            FROM product_sets ps
                            LEFT JOIN (
                                SELECT DISTINCT product_set_id,
                                       CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) AS location_id
                                FROM product_set_audit_log
                                WHERE action_type IN ('created', 'updated')
                                  AND action_details LIKE '%storage_location_id:%'
                            ) loc_finder ON ps.id = loc_finder.product_set_id
                            WHERE DATE(ps.created_at) = ?
                            GROUP BY ps.id, ps.set_name, loc_finder.location_id
                        ) received_lines
                        GROUP BY received_lines.item_name, received_lines.sku, received_lines.storage_location_id
                    ) received ON received.item_name = combined.item_name
                              AND received.sku <=> combined.sku
                              AND received.storage_location_id <=> combined.storage_location_id
                    LEFT JOIN (
                        SELECT
                            restored.item_name,
                            SUM(restored.return_quantity) as return_quantity
                        FROM (
                            SELECT
                                p.name as item_name,
                                SUM(oi.quantity) as return_quantity
                            FROM {$returnedOrdersSql} returned_orders
                            JOIN orders o ON o.order_code = returned_orders.inv
                            JOIN order_items oi ON oi.order_id = o.id
                            JOIN products p ON p.id = oi.product_id
                            WHERE DATE(returned_orders.return_date) = ?
                              AND COALESCE(p.product_type, 'normal') <> 'set'
                            GROUP BY p.name

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                SUM(psi.quantity * oi.quantity) as return_quantity
                            FROM {$returnedOrdersSql} returned_orders
                            JOIN orders o ON o.order_code = returned_orders.inv
                            JOIN order_items oi ON oi.order_id = o.id
                            JOIN products set_product ON set_product.id = oi.product_id
                            JOIN product_sets ps ON ps.set_name = set_product.name
                            JOIN product_set_items psi ON psi.product_set_id = ps.id
                            JOIN products p ON p.id = psi.product_id
                            WHERE DATE(returned_orders.return_date) = ?
                              AND COALESCE(set_product.product_type, 'normal') = 'set'
                            GROUP BY p.name
                        ) restored
                        GROUP BY restored.item_name
                    ) returns ON returns.item_name = combined.item_name
                             AND combined.storage_location_id = (
                                 SELECT id
                                 FROM storage_locations
                                 WHERE is_default = 1
                                 LIMIT 1
                             )
                    LEFT JOIN (
                        SELECT
                            mm.item_name,
                            mm.storage_location_id,
                                SUM(mm.movements_in) as movements_in,
                                SUM(mm.movements_out) as movements_out,
                                SUM(mm.transfer_in) as transfer_in,
                                SUM(mm.transfer_out) as transfer_out,
                                SUM(mm.adjustments) as adjustments
                        FROM (
                            SELECT
                                p.name as item_name,
                                COALESCE(
                                    CASE
                                        WHEN sm.movement_type = 'out' THEN sm.from_storage_location_id
                                        WHEN sm.movement_type = 'in' THEN sm.to_storage_location_id
                                        ELSE NULL
                                    END,
                                    CAST(
                                        SUBSTRING(sm.notes, LOCATE('[Location:', sm.notes) + LENGTH('[Location:'), LOCATE(']', sm.notes, LOCATE('[Location:', sm.notes)) - (LOCATE('[Location:', sm.notes) + LENGTH('[Location:')))
                                        AS UNSIGNED
                                    )
                                ) as storage_location_id,
                                SUM(CASE
                                    WHEN sm.movement_type = 'in'
                                     AND COALESCE(sm.reference_type, '') NOT IN (
                                         'offline_customer_purchase', 'offline_sale_cancel', 'offline_sale_edit',
                                         'purchase', 'purchase_in'
                                     )
                                    THEN sm.quantity ELSE 0 END) as movements_in,
                                SUM(CASE
                                    WHEN sm.movement_type = 'out'
                                     AND COALESCE(sm.reference_type, '') NOT IN (
                                         'offline_sale', 'offline_customer_purchase_cancel', 'offline_purchase_edit'
                                     )
                                    THEN sm.quantity ELSE 0 END) as movements_out,
                                0 as transfer_in,
                                0 as transfer_out,
                                SUM(CASE WHEN sm.movement_type = 'adjustment' THEN sm.quantity ELSE 0 END) as adjustments
                            FROM stock_movements sm
                            JOIN products p ON sm.item_id = p.id
                            WHERE DATE(sm.created_at) = ?
                              AND (
                                  sm.from_storage_location_id IS NOT NULL
                                  OR sm.to_storage_location_id IS NOT NULL
                                  OR sm.notes LIKE '%[Location:%]'
                              )
                              AND sm.movement_type IN ('in', 'out', 'adjustment')
                            GROUP BY p.name, storage_location_id

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                COALESCE(
                                    sm.to_storage_location_id,
                                    CAST(
                                        SUBSTRING(sm.notes, LOCATE('To:', sm.notes) + LENGTH('To:'), LOCATE(']', sm.notes, LOCATE('To:', sm.notes)) - (LOCATE('To:', sm.notes) + LENGTH('To:')))
                                        AS UNSIGNED
                                    )
                                ) as storage_location_id,
                                0 as movements_in,
                                0 as movements_out,
                                SUM(sm.quantity) as transfer_in,
                                0 as transfer_out,
                                0 as adjustments
                            FROM stock_movements sm
                            JOIN products p ON sm.item_id = p.id
                            WHERE DATE(sm.created_at) = ?
                              AND sm.movement_type = 'transfer'
                              AND (
                                  sm.to_storage_location_id IS NOT NULL
                                  OR sm.notes LIKE '%[From:%To:%]'
                              )
                            GROUP BY p.name, storage_location_id

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                COALESCE(
                                    sm.from_storage_location_id,
                                    CAST(
                                        SUBSTRING(sm.notes, LOCATE('[From:', sm.notes) + LENGTH('[From:'), LOCATE(' ', sm.notes, LOCATE('[From:', sm.notes)) - (LOCATE('[From:', sm.notes) + LENGTH('[From:')))
                                        AS UNSIGNED
                                    )
                                ) as storage_location_id,
                                0 as movements_in,
                                0 as movements_out,
                                0 as transfer_in,
                                SUM(sm.quantity) as transfer_out,
                                0 as adjustments
                            FROM stock_movements sm
                            JOIN products p ON sm.item_id = p.id
                            WHERE DATE(sm.created_at) = ?
                              AND sm.movement_type = 'transfer'
                              AND (
                                  sm.from_storage_location_id IS NOT NULL
                                  OR sm.notes LIKE '%[From:%To:%]'
                              )
                            GROUP BY p.name, storage_location_id
                        ) mm
                        WHERE mm.storage_location_id IS NOT NULL
                        GROUP BY mm.item_name, mm.storage_location_id
                    ) moves
                        ON moves.item_name = combined.item_name
                       AND moves.storage_location_id <=> combined.storage_location_id
                    ORDER BY combined.item_name, combined.sku
                
SQL;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO eod_stock_reports (report_date, created_by, status) VALUES (?, ?, 'draft')");
        $stmt->execute([$reportDate, $userId]);
        $reportId = (int)$pdo->lastInsertId();
        if ($reportId <= 0) {
            throw new RuntimeException('Failed to create EOD report header. Database id sequence may need repair.');
        }

        $inventoryStmt = $pdo->prepare($inventorySql);
        $placeholderCount = substr_count($inventorySql, '?');
        $inventoryParams = array_fill(0, $placeholderCount, $reportDate);
        $inventoryStmt->execute($inventoryParams);
        $inventoryData = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalItems = 0;
        $totalQuantity = 0.0;
        $totalValue = 0.0;

        $detailStmt = $pdo->prepare('
            INSERT INTO eod_stock_report_details
            (eod_report_id, item_name, sku, storage_location_id, quantity_on_hand,
             available_quantity, opening_quantity, daily_received, return_quantity,
             movements_in, movements_out, transfer_in, transfer_out, adjustments,
             unit_cost, total_value, last_movement_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $codeCatalog = eod_eom_product_code_catalog($pdo);
        foreach ($inventoryData as $item) {
            $openingStock = (float)($item['opening_quantity'] ?? 0);
            $closingQuantity = (float)($item['quantity_on_hand'] ?? 0);
            $detailStmt->execute([
                $reportId,
                $item['item_name'],
                eod_eom_resolve_product_code($item, $codeCatalog),
                $item['storage_location_id'],
                $closingQuantity,
                $item['available_quantity'],
                $openingStock,
                $item['daily_received'],
                $item['return_quantity'],
                $item['movements_in'],
                $item['movements_out'],
                $item['transfer_in'],
                $item['transfer_out'],
                $item['adjustments'],
                $item['unit_cost'],
                $item['total_value'],
                $item['last_updated'],
            ]);
            $totalItems++;
            $totalQuantity += $closingQuantity;
            $totalValue += (float)($item['total_value'] ?? 0);
        }

        $stmt = $pdo->prepare('UPDATE eod_stock_reports SET total_items = ?, total_quantity = ?, total_value = ? WHERE id = ?');
        $stmt->execute([$totalItems, $totalQuantity, $totalValue, $reportId]);
        $pdo->commit();

        return [
            'report_id' => $reportId,
            'report_type' => 'eod',
            'report_date' => $reportDate,
            'total_items' => $totalItems,
            'total_quantity' => $totalQuantity,
            'total_value' => $totalValue,
            'status' => 'draft',
            'message' => "EOD report generated for $reportDate ($totalItems items). Finalize in OrderShadow when ready.",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function eod_eom_sheet_key(string $name): string
{
    return mb_strtolower(trim($name));
}

function eod_eom_default_product_code(int $productId): string
{
    if ($productId <= 0) {
        return '';
    }
    return 'PROD-' . str_pad((string)$productId, 4, '0', STR_PAD_LEFT);
}

function eod_eom_product_name_key(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $base = trim((string)preg_replace('/\s+\(.*\)$/u', '', $name));
    return mb_strtolower($base !== '' ? $base : $name);
}

function eod_eom_product_code_catalog(PDO $pdo): array
{
    $byBrandName = [];
    $byName = [];
    try {
        $barcodeSelect = "'' AS barcode";
        try {
            $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'");
            if ($col && $col->fetch(PDO::FETCH_ASSOC)) {
                $barcodeSelect = 'p.barcode';
            }
        } catch (Throwable $e) {
            $barcodeSelect = "'' AS barcode";
        }
        $sql = "
            SELECT
                p.id,
                p.name,
                p.sku,
                {$barcodeSelect},
                COALESCE(NULLIF(TRIM(b.name), ''), '') AS brand_name
            FROM products p
            LEFT JOIN brands b ON b.id = p.brand_id
        ";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['id'] ?? 0);
            $name = eod_eom_product_name_key((string)($row['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku === '') {
                $sku = trim((string)($row['barcode'] ?? ''));
            }
            if ($sku === '') {
                $sku = eod_eom_default_product_code($id);
            }
            $brand = mb_strtolower(trim((string)($row['brand_name'] ?? '')));
            $entry = [
                'sku' => $sku,
                'id' => $id,
                'brand_name' => trim((string)($row['brand_name'] ?? '')),
            ];
            if ($brand !== '') {
                $byBrandName[$brand . "\0" . $name] = $entry;
            }
            if (!isset($byName[$name])) {
                $byName[$name] = $entry;
            }
        }
    } catch (Throwable $e) {
        return ['byBrandName' => [], 'byName' => []];
    }
    return ['byBrandName' => $byBrandName, 'byName' => $byName];
}

function eod_eom_resolve_product_code(array $row, array $catalog): string
{
    $fullName = eod_eom_sheet_key((string)($row['item_name'] ?? $row['name'] ?? ''));
    $baseName = eod_eom_product_name_key((string)($row['item_name'] ?? $row['name'] ?? ''));
    $brand = mb_strtolower(trim((string)($row['brand_name'] ?? '')));
    $stored = trim((string)($row['sku'] ?? ''));
    if (preg_match('/^SET-/i', $stored)) {
        return $stored;
    }
    $tryKeys = array_values(array_unique(array_filter([$fullName, $baseName], static fn(string $key): bool => $key !== '')));

    if ($brand !== '') {
        foreach ($tryKeys as $key) {
            $hit = $catalog['byBrandName'][$brand . "\0" . $key] ?? null;
            if ($hit && trim((string)($hit['sku'] ?? '')) !== '') {
                return trim((string)$hit['sku']);
            }
        }
    }
    foreach ($tryKeys as $key) {
        $hit = $catalog['byName'][$key] ?? null;
        if ($hit && trim((string)($hit['sku'] ?? '')) !== '') {
            return trim((string)$hit['sku']);
        }
    }
    return $stored;
}

function eod_eom_apply_product_codes(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }
    $catalog = eod_eom_product_code_catalog($pdo);
    foreach ($rows as $index => $row) {
        $code = eod_eom_resolve_product_code($row, $catalog);
        $rows[$index]['sku'] = $code;
        $rows[$index]['product_code'] = $code;
    }
    return $rows;
}

function eod_eom_offline_team_list(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT id, name FROM offline_teams WHERE COALESCE(is_active, 1) = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $name = trim((string)($row['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function eod_eom_decode_team_map(mixed $value): array
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $qty) {
            $out[(string)$key] = (float)$qty;
        }
        return $out;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return eod_eom_decode_team_map(is_array($decoded) ? $decoded : []);
}

function eod_eom_team_map_sum(array $map): float
{
    $sum = 0.0;
    foreach ($map as $qty) {
        $sum += (float)$qty;
    }
    return $sum;
}

function eod_eom_ensure_eom_sheet_columns(PDO $pdo): void
{
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'eom_stock_report_details'")->fetchColumn();
        if (!$exists) {
            return;
        }
        $columns = $pdo->query('SHOW COLUMNS FROM eom_stock_report_details')->fetchAll(PDO::FETCH_COLUMN);
        $columnSet = array_fill_keys(array_map('strtolower', $columns ?: []), true);
        $add = [
            'purchase_received' => 'DECIMAL(15,2) DEFAULT 0',
            'buy_back_rung' => 'DECIMAL(15,2) DEFAULT 0',
            'buy_back_banha' => 'DECIMAL(15,2) DEFAULT 0',
            'buy_back_van' => 'DECIMAL(15,2) DEFAULT 0',
            'online_sales' => 'DECIMAL(15,2) DEFAULT 0',
            'offline_sales_rung' => 'DECIMAL(15,2) DEFAULT 0',
            'offline_sales_banha' => 'DECIMAL(15,2) DEFAULT 0',
            'offline_sales_van' => 'DECIMAL(15,2) DEFAULT 0',
            'dealer_sales' => 'DECIMAL(15,2) DEFAULT 0',
            'marketing_stock_out' => 'DECIMAL(15,2) DEFAULT 0',
            'return_previous_month' => 'DECIMAL(15,2) DEFAULT 0',
            'current_stock' => 'DECIMAL(15,2) DEFAULT 0',
            'buy_back_json' => 'TEXT NULL',
            'offline_sales_json' => 'TEXT NULL',
            'system_location_qty_json' => 'TEXT NULL',
            'final_location_qty_json' => 'TEXT NULL',
        ];
        foreach ($add as $column => $definition) {
            if (!isset($columnSet[$column])) {
                $pdo->exec("ALTER TABLE eom_stock_report_details ADD COLUMN `{$column}` {$definition}");
            }
        }
        $headerExists = $pdo->query("SHOW TABLES LIKE 'eom_stock_reports'")->fetchColumn();
        if ($headerExists) {
            $headerCols = $pdo->query('SHOW COLUMNS FROM eom_stock_reports')->fetchAll(PDO::FETCH_COLUMN);
            $headerSet = array_fill_keys(array_map('strtolower', $headerCols ?: []), true);
            if (!isset($headerSet['teams_json'])) {
                $pdo->exec('ALTER TABLE eom_stock_reports ADD COLUMN teams_json TEXT NULL');
            }
        }
    } catch (Throwable $e) {
        error_log('eod_eom_ensure_eom_sheet_columns: ' . $e->getMessage());
    }
}

function eod_eom_eom_has_sheet_columns(PDO $pdo): bool
{
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'eom_stock_report_details'")->fetchColumn();
        if (!$exists) {
            return false;
        }
        $columns = $pdo->query('SHOW COLUMNS FROM eom_stock_report_details')->fetchAll(PDO::FETCH_COLUMN);
        $columnSet = array_fill_keys(array_map('strtolower', $columns ?: []), true);
        return isset($columnSet['purchase_received'], $columnSet['online_sales'], $columnSet['return_previous_month']);
    } catch (Throwable $e) {
        return false;
    }
}

function eod_eom_eom_has_json_columns(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'buy_back_json'");
        return $stmt && $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        return false;
    }
}

function eod_eom_merge_team_maps(array ...$maps): array
{
    $out = [];
    foreach ($maps as $map) {
        foreach (eod_eom_decode_team_map($map) as $id => $qty) {
            $key = (string)$id;
            $out[$key] = (float)($out[$key] ?? 0) + (float)$qty;
        }
    }
    return $out;
}

function eod_eom_legacy_team_slot(string $name): string
{
    $n = mb_strtolower(trim($name));
    if ($n === '') {
        return '';
    }
    if (preg_match('/ážšáž»áž„|rung/u', $n)) {
        return 'rung';
    }
    if (preg_match('/áž”áž‰áŸ’áž‰áž¶|banha/u', $n)) {
        return 'banha';
    }
    if (preg_match('/ážœáŸ‰áž¶áž“áŸ‹|\bvan\b/u', $n)) {
        return 'van';
    }
    return '';
}

function eod_eom_legacy_team_qty_map(array $teams, array $row, string $prefix): array
{
    $legacy = [
        'rung' => (float)($row[$prefix . '_rung'] ?? 0),
        'banha' => (float)($row[$prefix . '_banha'] ?? 0),
        'van' => (float)($row[$prefix . '_van'] ?? 0),
    ];
    if (abs($legacy['rung']) + abs($legacy['banha']) + abs($legacy['van']) < 0.0005) {
        return [];
    }
    $out = [];
    $usedSlots = [];
    foreach ($teams as $team) {
        $id = (string)($team['id'] ?? '');
        $slot = eod_eom_legacy_team_slot((string)($team['name'] ?? ''));
        if ($slot === '' || isset($usedSlots[$slot]) || abs($legacy[$slot]) < 0.0005) {
            continue;
        }
        $out[$id] = $legacy[$slot];
        $usedSlots[$slot] = true;
    }
    return $out;
}

function eod_eom_row_team_map(array $row, string $kind, array $teams = []): array
{
    $byKey = $kind === 'buy_back' ? 'buy_back_by_team' : 'offline_sales_by_team';
    $jsonKey = $kind === 'buy_back' ? 'buy_back_json' : 'offline_sales_json';
    $map = eod_eom_decode_team_map($row[$byKey] ?? $row[$jsonKey] ?? []);
    if ($map === [] && $teams !== []) {
        $map = eod_eom_legacy_team_qty_map($teams, $row, $kind === 'buy_back' ? 'buy_back' : 'offline_sales');
    }
    return $map;
}

function eod_eom_freeze_sheet_teams(PDO $pdo, array $products): array
{
    $teams = eod_eom_offline_team_list($pdo);
    $known = [];
    foreach ($teams as $team) {
        $known[(string)$team['id']] = true;
    }
    $used = [];
    foreach ($products as $item) {
        foreach (array_keys($item['buy_back_by_team'] ?? []) as $teamId) {
            $used[(string)$teamId] = true;
        }
        foreach (array_keys($item['offline_sales_by_team'] ?? []) as $teamId) {
            $used[(string)$teamId] = true;
        }
    }
    $missing = [];
    foreach ($used as $id => $_) {
        if (!isset($known[$id])) {
            $missing[] = (int)$id;
        }
    }
    if ($missing === []) {
        return $teams;
    }
    $ids = array_values(array_unique(array_filter($missing, static fn(int $id): bool => $id > 0)));
    $found = [];
    if ($ids !== []) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name FROM offline_teams WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)($row['id'] ?? 0);
                $name = trim((string)($row['name'] ?? ''));
                if ($id <= 0) {
                    continue;
                }
                $found[$id] = true;
                $teams[] = ['id' => $id, 'name' => $name !== '' ? $name : ('Team #' . $id)];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (isset($used['0']) && !isset($known['0'])) {
        $teams[] = ['id' => 0, 'name' => 'Unassigned'];
        $known['0'] = true;
    }
    foreach ($missing as $id) {
        if ($id > 0 && !isset($found[$id]) && !isset($known[(string)$id])) {
            $teams[] = ['id' => $id, 'name' => 'Team #' . $id];
            $known[(string)$id] = true;
        }
    }
    return $teams;
}

function eod_eom_sheet_bump(array &$map, string $name, float $qty, string $sku = ''): void
{
    $key = eod_eom_sheet_key($name);
    if ($key === '' || abs($qty) < 0.0005) {
        return;
    }
    if (!isset($map[$key])) {
        $map[$key] = ['qty' => 0.0, 'sku' => $sku, 'name' => trim($name)];
    }
    $map[$key]['qty'] += $qty;
    if ($sku !== '' && ($map[$key]['sku'] ?? '') === '') {
        $map[$key]['sku'] = $sku;
    }
    if (trim($name) !== '' && ($map[$key]['name'] ?? '') === '') {
        $map[$key]['name'] = trim($name);
    }
}

function eod_eom_eom_purchase_received_rows(PDO $pdo, string $monthStart, string $monthEnd): array
{
    try {
        $stmt = $pdo->prepare('
            SELECT
                COALESCE(NULLIF(TRIM(p.name), \'\'), NULLIF(TRIM(poi.item_name), \'\')) AS item_name,
                COALESCE(NULLIF(TRIM(poi.sku), \'\'), NULLIF(TRIM(p.sku), \'\'), \'\') AS sku,
                SUM(pri.quantity_received) AS qty
            FROM purchase_receiving_items pri
            INNER JOIN purchase_receiving pr ON pr.id = pri.receiving_id
            INNER JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            LEFT JOIN products p ON p.id = poi.product_id
            WHERE pr.receiving_date >= ?
              AND pr.receiving_date <= ?
            GROUP BY item_name, sku
        ');
        $stmt->execute([$monthStart, $monthEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return eod_eom_sheet_movement_rows($pdo, $monthStart, $monthEnd, ['purchase']);
    }
}

function eod_eom_sheet_movement_rows(PDO $pdo, string $monthStart, string $monthEnd, array $referenceTypes): array
{
    if (!$referenceTypes) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($referenceTypes), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('item-', sm.item_id)) AS item_name,
                COALESCE(p.sku, '') AS sku,
                SUM(
                    CASE
                        WHEN sm.movement_type IN ('out', 'sale_out') THEN -ABS(sm.quantity)
                        WHEN sm.movement_type IN ('in', 'purchase_in') THEN ABS(sm.quantity)
                        WHEN sm.movement_type = 'adjustment' THEN sm.quantity
                        ELSE 0
                    END
                ) AS qty
            FROM stock_movements sm
            LEFT JOIN products p ON p.id = sm.item_id
            WHERE DATE(sm.created_at) >= ? AND DATE(sm.created_at) <= ?
              AND sm.reference_type IN ($placeholders)
            GROUP BY item_name, sku
        ");
        $stmt->execute(array_merge([$monthStart, $monthEnd], array_values($referenceTypes)));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function eod_eom_sheet_team_bump(array &$map, string $name, int $teamId, string $teamName, float $qty, string $sku = ''): void
{
    $key = eod_eom_sheet_key($name);
    if ($key === '' || abs($qty) < 0.0005) {
        return;
    }
    if (!isset($map[$key])) {
        $map[$key] = ['qty' => [], 'sku' => $sku, 'name' => trim($name)];
    }
    $slot = (string)$teamId;
    $map[$key]['qty'][$slot] = (float)($map[$key]['qty'][$slot] ?? 0) + $qty;
    if ($sku !== '' && ($map[$key]['sku'] ?? '') === '') {
        $map[$key]['sku'] = $sku;
    }
    if (trim($name) !== '' && ($map[$key]['name'] ?? '') === '') {
        $map[$key]['name'] = trim($name);
    }
}

function eod_eom_eom_component_map(PDO $pdo): array
{
    $out = [];
    $queries = [
        "
            SELECT ps.set_name AS set_name, p.name AS item_name,
                   COALESCE(NULLIF(TRIM(p.sku), ''), '') AS sku, COALESCE(psi.quantity, 0) AS qty
            FROM product_sets ps
            JOIN product_set_items psi ON psi.product_set_id = ps.id
            JOIN products p ON p.id = psi.product_id
        ",
        "
            SELECT p.name AS set_name, cp.name AS item_name,
                   COALESCE(NULLIF(TRIM(cp.sku), ''), '') AS sku, COALESCE(psi.quantity, 0) AS qty
            FROM products p
            JOIN product_sets ps ON ps.set_name = p.name
            JOIN product_set_items psi ON psi.product_set_id = ps.id
            JOIN products cp ON cp.id = psi.product_id
            WHERE LOWER(COALESCE(p.product_type, 'normal')) = 'set'
        ",
    ];
    foreach ($queries as $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = eod_eom_sheet_key((string)($row['set_name'] ?? ''));
                $name = trim((string)($row['item_name'] ?? ''));
                if ($key === '' || $name === '') {
                    continue;
                }
                $out[$key][] = [
                    'item_name' => $name,
                    'sku' => trim((string)($row['sku'] ?? '')),
                    'qty' => (float)($row['qty'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return $out;
}

function eod_eom_eom_set_keys(PDO $pdo, array $componentMap = []): array
{
    $keys = [];
    $add = static function (string $value) use (&$keys): void {
        $key = eod_eom_sheet_key($value);
        if ($key !== '') {
            $keys[$key] = true;
        }
        $base = eod_eom_product_name_key($value);
        if ($base !== '') {
            $keys[$base] = true;
        }
    };
    foreach (array_keys($componentMap) as $key) {
        $keys[(string)$key] = true;
    }
    try {
        foreach ($pdo->query('SELECT set_name FROM product_sets')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add((string)($row['set_name'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $stmt = $pdo->query("SELECT name FROM products WHERE LOWER(COALESCE(product_type, 'normal')) = 'set'");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $add((string)($row['name'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $sql = "
            SELECT
                ps.set_name,
                CONCAT('SET-', ps.id) AS default_sku,
                COALESCE(NULLIF(TRIM(qcs.code_prefix), ''), CONCAT('SET-', ps.id)) AS sku
            FROM product_sets ps
            LEFT JOIN product_set_qr_code_settings qcs ON qcs.product_set_id = ps.id
        ";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add((string)($row['set_name'] ?? ''));
            foreach (['default_sku', 'sku'] as $field) {
                $sku = strtolower(trim((string)($row[$field] ?? '')));
                if ($sku !== '') {
                    $keys['sku:' . $sku] = true;
                }
            }
        }
    } catch (Throwable $e) {
        try {
            foreach ($pdo->query("SELECT set_name, CONCAT('SET-', id) AS sku FROM product_sets")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $add((string)($row['set_name'] ?? ''));
                $sku = strtolower(trim((string)($row['sku'] ?? '')));
                if ($sku !== '') {
                    $keys['sku:' . $sku] = true;
                }
            }
        } catch (Throwable $e2) {
            // ignore
        }
    }
    return $keys;
}

function eod_eom_eom_is_set(string $name, string $sku = '', array $setKeys = []): bool
{
    $sku = trim($sku);
    if ($sku !== '') {
        if (preg_match('/^SET-/i', $sku)) {
            return true;
        }
        if (isset($setKeys['sku:' . mb_strtolower($sku)])) {
            return true;
        }
    }
    if ($setKeys === []) {
        return false;
    }
    foreach ([eod_eom_sheet_key($name), eod_eom_product_name_key($name)] as $key) {
        if ($key !== '' && isset($setKeys[$key])) {
            return true;
        }
    }
    return false;
}

function eod_eom_eom_expand_qty(string $name, string $sku, float $qty, array $componentMap, array $setKeys): array
{
    if (abs($qty) < 0.0005) {
        return [];
    }
    $components = $componentMap[eod_eom_sheet_key($name)]
        ?? $componentMap[eod_eom_product_name_key($name)]
        ?? [];
    if ($components !== []) {
        $lines = [];
        foreach ($components as $component) {
            $lineQty = $qty * (float)($component['qty'] ?? 0);
            if (abs($lineQty) < 0.0005) {
                continue;
            }
            $lines[] = [
                'item_name' => (string)($component['item_name'] ?? ''),
                'sku' => (string)($component['sku'] ?? ''),
                'qty' => $lineQty,
            ];
        }
        return $lines;
    }
    if (eod_eom_eom_is_set($name, $sku, $setKeys)) {
        return [];
    }
    return [['item_name' => $name, 'sku' => $sku, 'qty' => $qty]];
}

function eod_eom_eom_sheet_lookups(PDO $pdo, string $monthStart, string $monthEnd): array
{
    $opening = [];
    $received = [];
    $buyBack = [];
    $offline = [];
    $online = [];
    $dealer = [];
    $marketing = [];
    $returnPrev = [];
    $adjustments = [];
    $skus = [];
    $names = [];
    $componentMap = eod_eom_eom_component_map($pdo);
    $setKeys = eod_eom_eom_set_keys($pdo, $componentMap);

    $remember = static function (string $name, string $sku = '') use (&$skus, &$names, $setKeys): string {
        if (eod_eom_eom_is_set($name, $sku, $setKeys)) {
            return '';
        }
        $key = eod_eom_sheet_key($name);
        if ($key === '') {
            return '';
        }
        if (trim($name) !== '') {
            $names[$key] = trim($name);
        }
        if ($sku !== '' && ($skus[$key] ?? '') === '') {
            $skus[$key] = $sku;
        }
        return $key;
    };

    $prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
    $prevStart = $prevMonth . '-01';
    $prevEnd = date('Y-m-t', strtotime($prevStart));

    try {
        $stmt = $pdo->prepare('
            SELECT d.item_name, d.sku,
                   SUM(COALESCE(d.final_quantity, d.closing_quantity, 0)) AS qty
            FROM eom_stock_report_details d
            JOIN eom_stock_reports r ON r.id = d.eom_report_id
            WHERE r.report_month = ?
            GROUP BY d.item_name, d.sku
        ');
        $stmt->execute([$prevMonth]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['item_name'] ?? '');
            $sku = (string)($row['sku'] ?? '');
            if (eod_eom_eom_is_set($name, $sku, $setKeys)) {
                continue;
            }
            $remember($name, $sku);
            eod_eom_sheet_bump($opening, $name, (float)($row['qty'] ?? 0), $sku);
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($opening === []) {
        try {
            $stmt = $pdo->prepare('
                SELECT d.item_name, d.sku,
                       SUM(COALESCE(d.final_quantity, d.quantity_on_hand, 0)) AS qty
                FROM eod_stock_report_details d
                JOIN eod_stock_reports r ON r.id = d.eod_report_id
                WHERE r.report_date = (
                    SELECT MAX(report_date) FROM eod_stock_reports
                    WHERE report_date >= ? AND report_date <= ?
                )
                GROUP BY d.item_name, d.sku
            ');
            $stmt->execute([$prevStart, $prevEnd]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string)($row['item_name'] ?? '');
                $sku = (string)($row['sku'] ?? '');
                if (eod_eom_eom_is_set($name, $sku, $setKeys)) {
                    continue;
                }
                $remember($name, $sku);
                eod_eom_sheet_bump($opening, $name, (float)($row['qty'] ?? 0), $sku);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    foreach (eod_eom_eom_purchase_received_rows($pdo, $monthStart, $monthEnd) as $row) {
        $name = (string)($row['item_name'] ?? '');
        $sku = (string)($row['sku'] ?? '');
        $qty = (float)($row['qty'] ?? 0);
        if (eod_eom_eom_is_set($name, $sku, $setKeys) || $qty <= 0.0005) {
            continue;
        }
        $remember($name, $sku);
        eod_eom_sheet_bump($received, $name, $qty, $sku);
    }

    foreach (eod_eom_sheet_movement_rows($pdo, $monthStart, $monthEnd, ['adjustment']) as $row) {
        $name = (string)($row['item_name'] ?? '');
        $sku = (string)($row['sku'] ?? '');
        $qty = (float)($row['qty'] ?? 0);
        if (eod_eom_eom_is_set($name, $sku, $setKeys)) {
            continue;
        }
        $remember($name, $sku);
        eod_eom_sheet_bump($adjustments, $name, $qty, $sku);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(i.product_name), '')) AS item_name,
                COALESCE(NULLIF(TRIM(p.sku), ''), '') AS sku,
                COALESCE(t.id, 0) AS team_id,
                COALESCE(NULLIF(TRIM(t.name), ''), 'Unassigned') AS team_name,
                SUM(CASE WHEN LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END) AS qty
            FROM offline_sale_order_items i
            JOIN offline_sale_orders o ON o.id = i.order_id
            LEFT JOIN products p ON p.id = i.product_id
            LEFT JOIN offline_teams t ON t.id = o.team_id
            WHERE o.sale_date >= ? AND o.sale_date <= ?
            GROUP BY item_name, sku, team_id, team_name
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['item_name'] ?? '');
            $sku = (string)($row['sku'] ?? '');
            $qty = (float)($row['qty'] ?? 0);
            foreach (eod_eom_eom_expand_qty($name, $sku, $qty, $componentMap, $setKeys) as $line) {
                $remember($line['item_name'], $line['sku']);
                eod_eom_sheet_team_bump($offline, $line['item_name'], (int)($row['team_id'] ?? 0), (string)($row['team_name'] ?? ''), $line['qty'], $line['sku']);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(i.product_name), '')) AS item_name,
                COALESCE(NULLIF(TRIM(p.sku), ''), '') AS sku,
                COALESCE(t.id, 0) AS team_id,
                COALESCE(NULLIF(TRIM(t.name), ''), 'Unassigned') AS team_name,
                SUM(CASE WHEN LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'canceled') THEN COALESCE(i.quantity, 0) ELSE 0 END) AS qty
            FROM offline_sale_purchase_items i
            JOIN offline_sale_orders o ON o.id = i.order_id
            LEFT JOIN products p ON p.id = i.product_id
            LEFT JOIN offline_teams t ON t.id = o.team_id
            WHERE o.sale_date >= ? AND o.sale_date <= ?
            GROUP BY item_name, sku, team_id, team_name
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['item_name'] ?? '');
            $sku = (string)($row['sku'] ?? '');
            $qty = (float)($row['qty'] ?? 0);
            foreach (eod_eom_eom_expand_qty($name, $sku, $qty, $componentMap, $setKeys) as $line) {
                $remember($line['item_name'], $line['sku']);
                eod_eom_sheet_team_bump($buyBack, $line['item_name'], (int)($row['team_id'] ?? 0), (string)($row['team_name'] ?? ''), $line['qty'], $line['sku']);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                CASE
                    WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                    THEN component_product.name
                    ELSE p.name
                END AS item_name,
                CASE
                    WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                    THEN COALESCE(component_product.sku, '')
                    ELSE COALESCE(p.sku, '')
                END AS sku,
                SUM(
                    CASE
                        WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                        THEN oi.quantity * COALESCE(psi.quantity, 0)
                        ELSE oi.quantity
                    END
                ) AS qty
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            LEFT JOIN product_sets ps
                ON ps.set_name = p.name
               AND COALESCE(p.product_type, 'normal') = 'set'
            LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
            LEFT JOIN products component_product ON component_product.id = psi.product_id
            WHERE DATE(pj.printed_at) >= ? AND DATE(pj.printed_at) <= ?
              AND COALESCE(o.is_cancelled, 0) = 0
              AND COALESCE(o.is_returned, 0) = 0
            GROUP BY item_name, sku
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['item_name'] ?? '');
            $remember($name, (string)($row['sku'] ?? ''));
            eod_eom_sheet_bump($online, $name, (float)($row['qty'] ?? 0), (string)($row['sku'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    foreach (eod_eom_sheet_movement_rows($pdo, $monthStart, $monthEnd, ['dealer_order', 'dealer_order_reverse']) as $row) {
        $name = (string)($row['item_name'] ?? '');
        $sku = (string)($row['sku'] ?? '');
        $qty = -1 * (float)($row['qty'] ?? 0);
        if (eod_eom_eom_is_set($name, $sku, $setKeys)) {
            continue;
        }
        foreach (eod_eom_eom_expand_qty($name, $sku, $qty, $componentMap, $setKeys) as $line) {
            $remember($line['item_name'], $line['sku']);
            eod_eom_sheet_bump($dealer, $line['item_name'], $line['qty'], $line['sku']);
        }
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.name AS item_name, COALESCE(p.sku, '') AS sku, SUM(mti.quantity_taken) AS qty
            FROM marketing_takes mt
            JOIN marketing_take_items mti ON mti.marketing_take_id = mt.id
            JOIN products p ON p.id = mti.product_id
            WHERE DATE(mt.approved_at) >= ? AND DATE(mt.approved_at) <= ?
              AND mt.approved_at IS NOT NULL
              AND COALESCE(mt.status, '') IN ('approved', 'pending', 'completed')
            GROUP BY p.name, p.sku
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['item_name'] ?? '');
            $sku = (string)($row['sku'] ?? '');
            $qty = (float)($row['qty'] ?? 0);
            foreach (eod_eom_eom_expand_qty($name, $sku, $qty, $componentMap, $setKeys) as $line) {
                $remember($line['item_name'], $line['sku']);
                eod_eom_sheet_bump($marketing, $line['item_name'], $line['qty'], $line['sku']);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $returnedOrdersSql = eod_eom_returned_orders_sql($pdo);
        $stmt = $pdo->prepare("
            SELECT restored.item_name, restored.sku, SUM(restored.qty) AS qty
            FROM (
                SELECT
                    p.name AS item_name,
                    COALESCE(p.sku, '') AS sku,
                    SUM(oi.quantity) AS qty
                FROM {$returnedOrdersSql} returned_orders
                JOIN orders o ON o.order_code = returned_orders.inv
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                JOIN (
                    SELECT order_id, MAX(printed_at) AS printed_at
                    FROM print_jobs
                    WHERE printed_at IS NOT NULL
                    GROUP BY order_id
                ) pj ON pj.order_id = o.id
                WHERE DATE(returned_orders.return_date) >= ?
                  AND DATE(returned_orders.return_date) <= ?
                  AND DATE(pj.printed_at) >= ?
                  AND DATE(pj.printed_at) <= ?
                  AND COALESCE(p.product_type, 'normal') <> 'set'
                GROUP BY p.name, p.sku

                UNION ALL

                SELECT
                    p.name AS item_name,
                    COALESCE(p.sku, '') AS sku,
                    SUM(psi.quantity * oi.quantity) AS qty
                FROM {$returnedOrdersSql} returned_orders
                JOIN orders o ON o.order_code = returned_orders.inv
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products set_product ON set_product.id = oi.product_id
                JOIN product_sets ps ON ps.set_name = set_product.name
                JOIN product_set_items psi ON psi.product_set_id = ps.id
                JOIN products p ON p.id = psi.product_id
                JOIN (
                    SELECT order_id, MAX(printed_at) AS printed_at
                    FROM print_jobs
                    WHERE printed_at IS NOT NULL
                    GROUP BY order_id
                ) pj ON pj.order_id = o.id
                WHERE DATE(returned_orders.return_date) >= ?
                  AND DATE(returned_orders.return_date) <= ?
                  AND DATE(pj.printed_at) >= ?
                  AND DATE(pj.printed_at) <= ?
                  AND COALESCE(set_product.product_type, 'normal') = 'set'
                GROUP BY p.name, p.sku
            ) restored
            GROUP BY restored.item_name, restored.sku
        ");
        $stmt->execute([$monthStart, $monthEnd, $prevStart, $prevEnd, $monthStart, $monthEnd, $prevStart, $prevEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['item_name'] ?? '');
            $remember($name, (string)($row['sku'] ?? ''));
            eod_eom_sheet_bump($returnPrev, $name, (float)($row['qty'] ?? 0), (string)($row['sku'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->query("
            SELECT ci.item_name, ci.sku
            FROM current_inventory ci
            LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
            WHERE ps.id IS NULL
            GROUP BY ci.item_name, ci.sku
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $remember((string)($row['item_name'] ?? ''), (string)($row['sku'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    $qtyOf = static function (array $map, string $key): float {
        return (float)($map[$key]['qty'] ?? 0);
    };

    $products = [];
    foreach ($names as $key => $name) {
        if (eod_eom_eom_is_set($name, (string)($skus[$key] ?? ''), $setKeys)) {
            continue;
        }
        $bb = $buyBack[$key]['qty'] ?? [];
        $off = $offline[$key]['qty'] ?? [];
        $products[$key] = [
            'item_name' => $name,
            'sku' => (string)($skus[$key] ?? $opening[$key]['sku'] ?? $received[$key]['sku'] ?? ''),
            'opening' => $qtyOf($opening, $key),
            'purchase_received' => $qtyOf($received, $key),
            'buy_back_by_team' => $bb,
            'offline_sales_by_team' => $off,
            'online_sales' => $qtyOf($online, $key),
            'dealer_sales' => $qtyOf($dealer, $key),
            'marketing_stock_out' => $qtyOf($marketing, $key),
            'return_previous_month' => $qtyOf($returnPrev, $key),
            'adjustments' => $qtyOf($adjustments, $key),
        ];
    }

    return $products;
}

function eod_eom_sheet_total_available(array $row): float
{
    $buyBack = eod_eom_decode_team_map($row['buy_back_by_team'] ?? $row['buy_back_json'] ?? []);
    if ($buyBack === []) {
        $buyBackSum = (float)($row['buy_back_rung'] ?? 0)
            + (float)($row['buy_back_banha'] ?? 0)
            + (float)($row['buy_back_van'] ?? 0);
    } else {
        $buyBackSum = eod_eom_team_map_sum($buyBack);
    }
    return (float)($row['opening'] ?? $row['opening_quantity'] ?? 0)
        + (float)($row['purchase_received'] ?? 0)
        + $buyBackSum;
}

function eod_eom_sheet_system_closing(array $row): float
{
    $offline = eod_eom_decode_team_map($row['offline_sales_by_team'] ?? $row['offline_sales_json'] ?? []);
    if ($offline === []) {
        $offlineSum = (float)($row['offline_sales_rung'] ?? 0)
            + (float)($row['offline_sales_banha'] ?? 0)
            + (float)($row['offline_sales_van'] ?? 0);
    } else {
        $offlineSum = eod_eom_team_map_sum($offline);
    }
    return eod_eom_sheet_total_available($row)
        - (float)($row['online_sales'] ?? 0)
        - $offlineSum
        - (float)($row['dealer_sales'] ?? 0)
        - (float)($row['marketing_stock_out'] ?? 0)
        + (float)($row['return_previous_month'] ?? 0);
}

function eod_eom_current_stock_by_item(PDO $pdo): array
{
    $out = [];
    try {
        $stmt = $pdo->query("
            SELECT
                TRIM(ci.item_name) AS item_name,
                SUM(COALESCE(ci.quantity_on_hand, 0)) AS qty
            FROM current_inventory ci
            LEFT JOIN product_sets ps ON TRIM(ci.item_name) = TRIM(ps.set_name)
            WHERE ps.id IS NULL
            GROUP BY TRIM(ci.item_name)
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower(trim((string)($row['item_name'] ?? '')));
            if ($key === '') {
                continue;
            }
            $out[$key] = (float)($row['qty'] ?? 0);
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function eod_eom_current_stock_by_item_location(PDO $pdo): array
{
    $out = [];
    try {
        $stmt = $pdo->query("
            SELECT
                TRIM(ci.item_name) AS item_name,
                ci.storage_location_id,
                SUM(COALESCE(ci.quantity_on_hand, 0)) AS qty
            FROM current_inventory ci
            LEFT JOIN product_sets ps ON TRIM(ci.item_name) = TRIM(ps.set_name)
            WHERE ps.id IS NULL
            GROUP BY TRIM(ci.item_name), ci.storage_location_id
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower(trim((string)($row['item_name'] ?? '')));
            $locId = (string)(int)($row['storage_location_id'] ?? 0);
            if ($key === '' || $locId === '0') {
                continue;
            }
            $out[$key][$locId] = (float)($row['qty'] ?? 0);
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function eod_eom_eod_stock_snapshot(PDO $pdo, string $reportDate): array
{
    $out = [
        'items' => [],
        'locations' => [],
        'report_id' => 0,
    ];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        return $out;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM eod_stock_reports
            WHERE report_date = ?
            ORDER BY CASE LOWER(status) WHEN 'finalized' THEN 0 WHEN 'reviewed' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END, id DESC
            LIMIT 1
        ");
        $stmt->execute([$reportDate]);
        $reportId = (int)($stmt->fetchColumn() ?: 0);
        if ($reportId <= 0) {
            return $out;
        }
        $out['report_id'] = $reportId;

        $stmt = $pdo->prepare("
            SELECT
                TRIM(item_name) AS item_name,
                storage_location_id,
                SUM(COALESCE(quantity_on_hand, 0)) AS qty
            FROM eod_stock_report_details
            WHERE eod_report_id = ?
            GROUP BY TRIM(item_name), storage_location_id
        ");
        $stmt->execute([$reportId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower(trim((string)($row['item_name'] ?? '')));
            $locId = (string)(int)($row['storage_location_id'] ?? 0);
            if ($key === '') {
                continue;
            }
            $qty = (float)($row['qty'] ?? 0);
            $out['items'][$key] = (float)($out['items'][$key] ?? 0) + $qty;
            if ($locId !== '0') {
                $out['locations'][$key][$locId] = (float)(($out['locations'][$key][$locId] ?? 0) + $qty);
            }
        }
    } catch (Throwable $e) {
        return [
            'items' => [],
            'locations' => [],
            'report_id' => 0,
        ];
    }

    return $out;
}

function eod_eom_generate_eom(PDO $pdo, string $reportMonth, string $reportDate, ?int $userId): array
{
    $reportMonth = trim($reportMonth);
    $reportDate = trim($reportDate);
    if (!preg_match('/^\d{4}-\d{2}$/', $reportMonth)) {
        throw new InvalidArgumentException('Invalid EOM report month.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        $reportDate = date('Y-m-t', strtotime($reportMonth . '-01'));
    }

    $stmt = $pdo->prepare('SELECT id FROM eom_stock_reports WHERE report_month = ? LIMIT 1');
    $stmt->execute([$reportMonth]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        throw new RuntimeException("EOM report for $reportMonth already exists");
    }

    eod_eom_ensure_auto_increment_ids($pdo);
    eod_eom_ensure_physical_count_columns($pdo);
    eod_eom_ensure_eom_sheet_columns($pdo);

    $monthStart = $reportMonth . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $products = eod_eom_eom_sheet_lookups($pdo, $monthStart, $monthEnd);
    if ($products === []) {
        throw new RuntimeException('No products found for this month.');
    }
    $teams = eod_eom_freeze_sheet_teams($pdo, $products);
    $codeCatalog = eod_eom_product_code_catalog($pdo);
    foreach ($products as $key => $item) {
        $products[$key]['sku'] = eod_eom_resolve_product_code($item, $codeCatalog);
    }
    if (!eod_eom_eom_has_json_columns($pdo)) {
        throw new RuntimeException('EOM team columns are missing. Refresh the page and try Generate again.');
    }

    $hasTeamsJson = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM eom_stock_reports LIKE 'teams_json'");
        $hasTeamsJson = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        $hasTeamsJson = false;
    }

    $pdo->beginTransaction();
    try {
        if ($hasTeamsJson) {
            $stmt = $pdo->prepare("INSERT INTO eom_stock_reports (report_month, report_date, created_by, status, teams_json) VALUES (?, ?, ?, 'draft', ?)");
            $stmt->execute([$reportMonth, $reportDate, $userId, json_encode($teams, JSON_UNESCAPED_UNICODE)]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO eom_stock_reports (report_month, report_date, created_by, status) VALUES (?, ?, ?, 'draft')");
            $stmt->execute([$reportMonth, $reportDate, $userId]);
        }
        $reportId = (int)$pdo->lastInsertId();
        if ($reportId <= 0) {
            throw new RuntimeException('Failed to create EOM report header. Database id sequence may need repair.');
        }

        $eodSnapshot = eod_eom_eod_stock_snapshot($pdo, $monthEnd);
        $currentStockMap = $eodSnapshot['items'] ?: eod_eom_current_stock_by_item($pdo);
        $locationStockMap = $eodSnapshot['locations'] ?: eod_eom_current_stock_by_item_location($pdo);
        $hasCurrentStockCol = false;
        $hasSystemLocationCol = false;
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'current_stock'");
            $hasCurrentStockCol = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
        } catch (Throwable $e) {
            $hasCurrentStockCol = false;
        }
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'system_location_qty_json'");
            $hasSystemLocationCol = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
        } catch (Throwable $e) {
            $hasSystemLocationCol = false;
        }

        $detailStmt = $pdo->prepare($hasCurrentStockCol && $hasSystemLocationCol
            ? 'INSERT INTO eom_stock_report_details
            (eom_report_id, item_name, sku, storage_location_id, opening_quantity, closing_quantity,
             purchase_received, online_sales, dealer_sales, marketing_stock_out, return_previous_month, return_quantity,
             current_stock, system_location_qty_json, buy_back_json, offline_sales_json)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            : ($hasCurrentStockCol
            ? 'INSERT INTO eom_stock_report_details
            (eom_report_id, item_name, sku, storage_location_id, opening_quantity, closing_quantity,
             purchase_received, online_sales, dealer_sales, marketing_stock_out, return_previous_month, return_quantity,
             current_stock, buy_back_json, offline_sales_json)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO eom_stock_report_details
            (eom_report_id, item_name, sku, storage_location_id, opening_quantity, closing_quantity,
             purchase_received, online_sales, dealer_sales, marketing_stock_out, return_previous_month, return_quantity,
             buy_back_json, offline_sales_json)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )
        );

        $totalItems = 0;
        $totalQuantity = 0.0;
        $setKeys = eod_eom_eom_set_keys($pdo, eod_eom_eom_component_map($pdo));
        foreach ($products as $item) {
            if (eod_eom_eom_is_set((string)($item['item_name'] ?? ''), (string)($item['sku'] ?? ''), $setKeys)) {
                continue;
            }
            $systemClosing = eod_eom_sheet_system_closing($item);
            $buyBackSum = eod_eom_team_map_sum($item['buy_back_by_team'] ?? []);
            $offlineSum = eod_eom_team_map_sum($item['offline_sales_by_team'] ?? []);
            $itemKey = mb_strtolower(trim((string)($item['item_name'] ?? '')));
            $currentStock = (float)($currentStockMap[$itemKey] ?? 0);
            $systemLocationQty = $locationStockMap[$itemKey] ?? [];
            $hasActivity = abs((float)$item['opening']) > 0.0005
                || abs((float)$item['purchase_received']) > 0.0005
                || abs($buyBackSum) > 0.0005
                || abs((float)$item['online_sales']) > 0.0005
                || abs($offlineSum) > 0.0005
                || abs((float)$item['dealer_sales']) > 0.0005
                || abs((float)$item['marketing_stock_out']) > 0.0005
                || abs((float)$item['return_previous_month']) > 0.0005
                || abs((float)($item['adjustments'] ?? 0)) > 0.0005
                || abs($systemClosing) > 0.0005
                || abs($currentStock) > 0.0005;
            if (!$hasActivity) {
                continue;
            }
            $payload = [
                $reportId,
                $item['item_name'],
                $item['sku'],
                $item['opening'],
                $systemClosing,
                $item['purchase_received'],
                $item['online_sales'],
                $item['dealer_sales'],
                $item['marketing_stock_out'],
                $item['return_previous_month'],
                $item['return_previous_month'],
            ];
            if ($hasCurrentStockCol) {
                $payload[] = $currentStock;
            }
            if ($hasCurrentStockCol && $hasSystemLocationCol) {
                $payload[] = json_encode((object)$systemLocationQty, JSON_UNESCAPED_UNICODE);
            }
            $payload[] = json_encode((object)($item['buy_back_by_team'] ?? []), JSON_UNESCAPED_UNICODE);
            $payload[] = json_encode((object)($item['offline_sales_by_team'] ?? []), JSON_UNESCAPED_UNICODE);
            $detailStmt->execute($payload);
            $totalItems++;
            $totalQuantity += $systemClosing;
        }

        if ($totalItems <= 0) {
            throw new RuntimeException('No stock activity found for this month.');
        }

        $stmt = $pdo->prepare('UPDATE eom_stock_reports SET total_items = ?, total_quantity = ?, total_value = 0 WHERE id = ?');
        $stmt->execute([$totalItems, $totalQuantity, $reportId]);
        $pdo->commit();

        return [
            'report_id' => $reportId,
            'report_type' => 'eom',
            'report_month' => $reportMonth,
            'report_date' => $reportDate,
            'total_items' => $totalItems,
            'total_quantity' => $totalQuantity,
            'total_value' => 0,
            'status' => 'draft',
            'sheet_format' => true,
            'message' => "EOM Closing generated for $reportMonth ($totalItems products).",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function eod_eom_delete_report(PDO $pdo, string $reportType, int $reportId): array
{
    $reportType = strtolower(trim($reportType));
    if (!in_array($reportType, ['eod', 'eom'], true) || $reportId <= 0) {
        throw new InvalidArgumentException('Invalid report.');
    }

    if (!function_exists('upload_delete_local_file')) {
        require_once __DIR__ . '/../upload_paths.php';
    }

    $pdo->beginTransaction();
    try {
        if ($reportType === 'eod') {
            $stmt = $pdo->prepare('SELECT id, status, report_date AS label FROM eod_stock_reports WHERE id = ? LIMIT 1');
        } else {
            $stmt = $pdo->prepare('SELECT id, status, report_month AS label FROM eom_stock_reports WHERE id = ? LIMIT 1');
        }
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            throw new RuntimeException('Report not found.');
        }
        if (strtolower((string)($report['status'] ?? '')) !== 'draft') {
            throw new RuntimeException('Only draft reports can be deleted.');
        }

        $stmt = $pdo->prepare('SELECT id, file_path FROM eod_eom_report_attachments WHERE report_id = ? AND report_type = ?');
        $stmt->execute([$reportId, $reportType]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
            if (!empty($att['file_path'])) {
                upload_delete_local_file((string)$att['file_path'], 'eod_eom_reports');
            }
            $thumb = __DIR__ . '/../uploads/eod_eom_reports/thumbnails/thumb_' . (int)$att['id'] . '.jpg';
            if (is_file($thumb)) {
                @unlink($thumb);
            }
        }
        $stmt = $pdo->prepare('DELETE FROM eod_eom_report_attachments WHERE report_id = ? AND report_type = ?');
        $stmt->execute([$reportId, $reportType]);

        if ($reportType === 'eod') {
            $stmt = $pdo->prepare('DELETE FROM eod_stock_report_details WHERE eod_report_id = ?');
            $stmt->execute([$reportId]);
            $stmt = $pdo->prepare('DELETE FROM eod_stock_reports WHERE id = ?');
            $stmt->execute([$reportId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM eom_stock_report_details WHERE eom_report_id = ?');
            $stmt->execute([$reportId]);
            $stmt = $pdo->prepare('DELETE FROM eom_stock_reports WHERE id = ?');
            $stmt->execute([$reportId]);
        }

        $pdo->commit();
        $label = (string)($report['label'] ?? ('#' . $reportId));
        return [
            'report_id' => $reportId,
            'report_type' => $reportType,
            'message' => 'Draft report deleted: ' . $label,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<int, array{name?:string,tmp_name?:string,type?:string,error?:int,size?:int}> $files
 */
function eod_eom_finalize_report(PDO $pdo, string $reportType, int $reportId, string $notes, array $files, ?int $userId): array
{
    $reportType = strtolower(trim($reportType));
    $notes = trim($notes);
    if (!in_array($reportType, ['eod', 'eom'], true) || $reportId <= 0) {
        throw new InvalidArgumentException('Invalid report.');
    }
    if ($notes === '') {
        throw new InvalidArgumentException('Notes are required to finalize the report.');
    }
    eod_eom_ensure_auto_increment_ids($pdo);
    eod_eom_ensure_physical_count_columns($pdo);
    eod_eom_ensure_difference_review_columns($pdo);

    if (!function_exists('upload_store_uploaded_file')) {
        require_once __DIR__ . '/../upload_paths.php';
    }

    if ($reportType === 'eod') {
        $stmt = $pdo->prepare('SELECT id, status, difference_reviewed_by, difference_reviewed_at, difference_review_status FROM eod_stock_reports WHERE id = ? LIMIT 1');
    } else {
        $stmt = $pdo->prepare('SELECT id, status, difference_reviewed_by, difference_reviewed_at, difference_review_status FROM eom_stock_reports WHERE id = ? LIMIT 1');
    }
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        throw new RuntimeException('Report not found.');
    }
    if (strtolower((string)($report['status'] ?? '')) === 'finalized') {
        throw new RuntimeException('Report is already finalized.');
    }
    $existingAttachmentCount = 0;
    if (function_exists('api_table_exists') && api_table_exists($pdo, 'eod_eom_report_attachments')) {
        $attachmentStmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM eod_eom_report_attachments
            WHERE report_id = ? AND report_type = ?
        ');
        $attachmentStmt->execute([$reportId, $reportType]);
        $existingAttachmentCount = (int)$attachmentStmt->fetchColumn();
    }
    if ($files === [] && $existingAttachmentCount <= 0) {
        throw new InvalidArgumentException('At least one attachment is required to finalize the report.');
    }
    $missingFinalCount = eod_eom_missing_final_quantity_count($pdo, $reportType, $reportId, $reportType === 'eod');
    if ($missingFinalCount > 0) {
        $scope = $reportType === 'eod' ? 'default location product(s)' : 'product(s)';
        throw new InvalidArgumentException("Cannot finalize: {$missingFinalCount} {$scope} still missing Physical Count.");
    }
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];
    $uploaded = [];
    foreach ($files as $file) {
        $name = (string)($file['name'] ?? '');
        $tmp = (string)($file['tmp_name'] ?? '');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($file['size'] ?? 0);
        $type = (string)($file['type'] ?? 'application/octet-stream');
        if ($name === '' || $error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new InvalidArgumentException("Invalid file type for {$name}. Allowed: PDF, Word, Excel, Images.");
        }
        if ($size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException("File {$name} is too large (max 10MB).");
        }
        $unique = uniqid('eod_eom_' . $reportType . '_', true) . '.' . $ext;
        $storedPath = upload_store_uploaded_file(
            [
                'name' => $name,
                'type' => $type,
                'tmp_name' => $tmp,
                'error' => $error,
                'size' => $size,
            ],
            'eod_eom_reports',
            $unique,
            null,
            $type
        );
        $storedFilename = preg_replace('#^uploads/eod_eom_reports/#', '', (string)$storedPath);
        $uploaded[] = [
            'original_name' => $name,
            'file_path' => $storedFilename,
            'file_size' => $size,
            'mime_type' => $type,
        ];
    }

    if ($uploaded === [] && $existingAttachmentCount <= 0) {
        throw new InvalidArgumentException('No valid files were uploaded.');
    }

    $pdo->beginTransaction();
    try {
        $finalizedAt = date('Y-m-d H:i:s');
        if ($reportType === 'eod') {
            $stmt = $pdo->prepare("
                UPDATE eod_stock_reports
                SET status = 'finalized', notes = ?, finalized_at = ?, finalized_by = ?
                WHERE id = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                UPDATE eom_stock_reports
                SET status = 'finalized', notes = ?, finalized_at = ?, finalized_by = ?
                WHERE id = ?
            ");
        }
        $stmt->execute([$notes, $finalizedAt, $userId, $reportId]);

        if ($uploaded) {
            $stmt = $pdo->prepare('
                INSERT INTO eod_eom_report_attachments
                (report_id, report_type, file_path, original_filename, file_size, mime_type, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            foreach ($uploaded as $fileInfo) {
                $stmt->execute([
                    $reportId,
                    $reportType,
                    $fileInfo['file_path'],
                    $fileInfo['original_name'],
                    $fileInfo['file_size'],
                    $fileInfo['mime_type'],
                    $userId,
                ]);
            }
        }

        $pdo->commit();
        return [
            'report_id' => $reportId,
            'report_type' => $reportType,
            'status' => 'finalized',
            'attachment_count' => $existingAttachmentCount + count($uploaded),
            'message' => 'Report finalized with ' . ($existingAttachmentCount + count($uploaded)) . ' attachment(s).',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function eod_eom_approve_differences(PDO $pdo, string $reportType, int $reportId, string $notes, ?int $userId): array
{
    $reportType = strtolower(trim($reportType));
    $notes = trim($notes);
    if (!in_array($reportType, ['eod', 'eom'], true) || $reportId <= 0) {
        throw new InvalidArgumentException('Invalid report.');
    }
    if (!$userId) {
        throw new InvalidArgumentException('Reviewer is required.');
    }

    eod_eom_ensure_difference_review_columns($pdo);

    $table = $reportType === 'eod' ? 'eod_stock_reports' : 'eom_stock_reports';
    $stmt = $pdo->prepare("SELECT id, status FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        throw new RuntimeException('Report not found.');
    }
    if (strtolower((string)($report['status'] ?? 'draft')) === 'finalized') {
        throw new RuntimeException('Report is already finalized.');
    }

    $missingFinalCount = eod_eom_missing_final_quantity_count($pdo, $reportType, $reportId, true);
    if ($missingFinalCount > 0) {
        throw new InvalidArgumentException("Cannot review yet: {$missingFinalCount} default location product(s) still missing Physical Count.");
    }

    $differenceSummary = eod_eom_difference_summary($pdo, $reportType, $reportId, true);
    if (empty($differenceSummary['has_difference'])) {
        throw new InvalidArgumentException('No stock differences need review.');
    }

    $detailTable = eod_eom_report_detail_table($reportType);
    $detailFk = eod_eom_report_detail_fk($reportType);
    $systemColumn = $reportType === 'eom' ? 'closing_quantity' : 'quantity_on_hand';
    $defaultLocationId = eod_eom_default_storage_location_id($pdo);
    $params = [$reportId, $userId];
    $locationSql = '';
    if ($defaultLocationId > 0) {
        $locationSql = ' AND storage_location_id = ?';
        $params[] = $defaultLocationId;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM `{$detailTable}`
        WHERE `{$detailFk}` = ?
          AND final_counted_by = ?
          AND final_quantity IS NOT NULL
          AND ABS(final_quantity - {$systemColumn}) > 0.005
          {$locationSql}
    ");
    $stmt->execute($params);
    if ((int)($stmt->fetchColumn() ?: 0) > 0) {
        throw new InvalidArgumentException('A different reviewer is required for stock differences you counted.');
    }

    $reviewedAt = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE `{$table}`
        SET difference_reviewed_by = ?, difference_reviewed_at = ?, difference_review_notes = ?, difference_review_status = 'approved'
        WHERE id = ?
    ");
    $stmt->execute([$userId, $reviewedAt, $notes !== '' ? $notes : null, $reportId]);

    return [
        'report_id' => $reportId,
        'report_type' => $reportType,
        'difference_reviewed_by' => $userId,
        'difference_reviewed_at' => $reviewedAt,
        'difference_review_notes' => $notes,
        'difference_review_status' => 'approved',
        'message' => 'Stock differences approved.',
    ];
}

function eod_eom_reject_differences(PDO $pdo, string $reportType, int $reportId, string $notes, ?int $userId): array
{
    $reportType = strtolower(trim($reportType));
    $notes = trim($notes);
    if (!in_array($reportType, ['eod', 'eom'], true) || $reportId <= 0) {
        throw new InvalidArgumentException('Invalid report.');
    }
    if (!$userId) {
        throw new InvalidArgumentException('Reviewer is required.');
    }

    eod_eom_ensure_difference_review_columns($pdo);

    $table = $reportType === 'eod' ? 'eod_stock_reports' : 'eom_stock_reports';
    $stmt = $pdo->prepare("SELECT id, status FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        throw new RuntimeException('Report not found.');
    }
    if (strtolower((string)($report['status'] ?? 'draft')) === 'finalized') {
        throw new RuntimeException('Report is already finalized.');
    }

    $differenceSummary = eod_eom_difference_summary($pdo, $reportType, $reportId, true);
    if (empty($differenceSummary['has_difference'])) {
        throw new InvalidArgumentException('No stock differences need review.');
    }

    $reviewedAt = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE `{$table}`
        SET difference_reviewed_by = ?, difference_reviewed_at = ?, difference_review_notes = ?, difference_review_status = 'rejected'
        WHERE id = ?
    ");
    $stmt->execute([$userId, $reviewedAt, $notes !== '' ? $notes : null, $reportId]);

    return [
        'report_id' => $reportId,
        'report_type' => $reportType,
        'difference_reviewed_by' => $userId,
        'difference_reviewed_at' => $reviewedAt,
        'difference_review_notes' => $notes,
        'difference_review_status' => 'rejected',
        'message' => 'Stock differences rejected.',
    ];
}

function eod_eom_update_status(PDO $pdo, string $reportType, int $reportId, string $status, string $notes, ?int $userId): array
{
    $reportType = strtolower(trim($reportType));
    $status = strtolower(trim($status));
    $notes = trim($notes);
    if (!in_array($reportType, ['eod', 'eom'], true) || $reportId <= 0) {
        throw new InvalidArgumentException('Invalid report.');
    }
    if (!in_array($status, ['draft', 'finalized'], true)) {
        throw new InvalidArgumentException('Status must be draft or finalized.');
    }

    eod_eom_ensure_difference_review_columns($pdo);

    if ($reportType === 'eod') {
        $stmt = $pdo->prepare('SELECT id, status, notes, difference_reviewed_by, difference_reviewed_at, difference_review_status FROM eod_stock_reports WHERE id = ? LIMIT 1');
    } else {
        $stmt = $pdo->prepare('SELECT id, status, notes, difference_reviewed_by, difference_reviewed_at, difference_review_status FROM eom_stock_reports WHERE id = ? LIMIT 1');
    }
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        throw new RuntimeException('Report not found.');
    }

    $current = strtolower((string)($report['status'] ?? 'draft')) ?: 'draft';
    if ($current === $status) {
        return [
            'report_id' => $reportId,
            'report_type' => $reportType,
            'status' => $status,
            'message' => 'Status is already ' . $status . '.',
        ];
    }

    if ($status === 'finalized' && $notes === '' && trim((string)($report['notes'] ?? '')) === '') {
        throw new InvalidArgumentException('Notes are required when setting status to finalized.');
    }
    if ($status === 'finalized') {
        $missingFinalCount = eod_eom_missing_final_quantity_count($pdo, $reportType, $reportId, true);
        if ($missingFinalCount > 0) {
            throw new InvalidArgumentException("Cannot finalize: {$missingFinalCount} default location product(s) still missing Physical Count.");
        }
    }

    $finalNotes = $notes !== '' ? $notes : trim((string)($report['notes'] ?? ''));
    if ($status === 'finalized') {
        $sql = $reportType === 'eod'
            ? "UPDATE eod_stock_reports SET status = 'finalized', notes = ?, finalized_at = ?, finalized_by = ? WHERE id = ?"
            : "UPDATE eom_stock_reports SET status = 'finalized', notes = ?, finalized_at = ?, finalized_by = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$finalNotes, date('Y-m-d H:i:s'), $userId, $reportId]);
    } else {
        $sql = $reportType === 'eod'
            ? "UPDATE eod_stock_reports SET status = 'draft', notes = ?, finalized_at = NULL, finalized_by = NULL WHERE id = ?"
            : "UPDATE eom_stock_reports SET status = 'draft', notes = ?, finalized_at = NULL, finalized_by = NULL WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$finalNotes !== '' ? $finalNotes : null, $reportId]);
    }

    return [
        'report_id' => $reportId,
        'report_type' => $reportType,
        'status' => $status,
        'status_label' => $status === 'finalized' ? 'Finalized' : 'Draft',
        'message' => 'Status updated to ' . $status . '.',
    ];
}

/**
 * Normalize $_FILES['attachments'] (single or multi) into a list of file arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function eod_eom_normalize_uploaded_files(array $filesField): array
{
    if (!isset($filesField['name'])) {
        return [];
    }
    if (!is_array($filesField['name'])) {
        return [[
            'name' => $filesField['name'] ?? '',
            'type' => $filesField['type'] ?? '',
            'tmp_name' => $filesField['tmp_name'] ?? '',
            'error' => $filesField['error'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesField['size'] ?? 0,
        ]];
    }

    $out = [];
    foreach ($filesField['name'] as $index => $name) {
        $out[] = [
            'name' => $name,
            'type' => $filesField['type'][$index] ?? '',
            'tmp_name' => $filesField['tmp_name'][$index] ?? '',
            'error' => $filesField['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesField['size'][$index] ?? 0,
        ];
    }
    return $out;
}

