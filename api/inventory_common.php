<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/product_set_location.php';

function inventory_api_date(?string $value, ?string $fallback = null): string
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    return $fallback ?? date('Y-m-d');
}

function inventory_api_str(mixed $value): string
{
    return trim((string)$value);
}

function inventory_api_num(mixed $value): float
{
    $number = (float)$value;
    return is_finite($number) ? $number : 0.0;
}

function inventory_api_int(mixed $value): int
{
    return (int)$value;
}

function inventory_location_options(PDO $pdo): array
{
    try {
        return $pdo->query('
            SELECT
                id AS value,
                COALESCE(NULLIF(location_name, \'\'), location_code, CONCAT(\'Location #\', id)) AS label,
                location_code,
                location_type,
                is_active
            FROM storage_locations
            WHERE is_active = 1
            ORDER BY location_code, location_name
        ')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function inventory_brand_options(PDO $pdo): array
{
    try {
        return $pdo->query('
            SELECT
                id AS value,
                name AS label
            FROM brands
            WHERE COALESCE(active, 1) = 1
            ORDER BY name ASC
        ')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try {
            return $pdo->query('
                SELECT id AS value, name AS label
                FROM brands
                ORDER BY name ASC
            ')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $inner) {
            return [];
        }
    }
}

function inventory_copy_product_skus(PDO $pdo): array
{
    $updated = 0;
    $errors = [];

    $run = static function (PDO $pdo, string $sql) use (&$updated, &$errors): void {
        try {
            $updated += (int)$pdo->exec($sql);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    };

    $nameKey = static function (string $column): string {
        return "LOWER(TRIM(REPLACE(REPLACE(REPLACE({$column}, '  ', ' '), '  ', ' '), '  ', ' ')))";
    };

    $inventoryName = $nameKey('ci.item_name');
    $inventoryBaseName = $nameKey("SUBSTRING_INDEX(ci.item_name, ' (', 1)");

    $run($pdo, "
        UPDATE products p
        LEFT JOIN products taken
            ON taken.id <> p.id
           AND TRIM(taken.sku) = CONCAT('PROD-', LPAD(p.id, 4, '0'))
        SET p.sku = CONCAT('PROD-', LPAD(p.id, 4, '0'))
        WHERE (p.sku IS NULL OR TRIM(p.sku) = '')
          AND taken.id IS NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN (
            SELECT {$nameKey('name')} AS name_key, MIN(NULLIF(TRIM(sku), '')) AS sku
            FROM products
            WHERE NULLIF(TRIM(sku), '') IS NOT NULL
            GROUP BY {$nameKey('name')}
        ) p ON p.name_key = {$inventoryName}
        SET ci.sku = p.sku
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND p.sku IS NOT NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN (
            SELECT {$nameKey('name')} AS name_key, MIN(NULLIF(TRIM(sku), '')) AS sku
            FROM products
            WHERE NULLIF(TRIM(sku), '') IS NOT NULL
            GROUP BY {$nameKey('name')}
        ) p ON p.name_key = {$inventoryBaseName}
        SET ci.sku = p.sku
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND ci.item_name LIKE '% (%'
          AND p.sku IS NOT NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN (
            SELECT {$nameKey('name')} AS name_key, MIN(NULLIF(TRIM(barcode), '')) AS sku
            FROM products
            WHERE NULLIF(TRIM(sku), '') IS NULL
              AND NULLIF(TRIM(barcode), '') IS NOT NULL
            GROUP BY {$nameKey('name')}
        ) p ON p.name_key = {$inventoryName}
        SET ci.sku = p.sku
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND p.sku IS NOT NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN (
            SELECT {$nameKey('item_name')} AS name_key,
                   MIN(NULLIF(TRIM(item_code), '')) AS sku
            FROM stock_items
            WHERE NULLIF(TRIM(item_code), '') IS NOT NULL
            GROUP BY {$nameKey('item_name')}
        ) si ON si.name_key = {$inventoryName}
        SET ci.sku = si.sku
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND si.sku IS NOT NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN stock_items si ON si.product_id IS NOT NULL
        INNER JOIN products p ON p.id = si.product_id
        SET ci.sku = COALESCE(NULLIF(TRIM(p.sku), ''), NULLIF(TRIM(si.item_code), ''), NULLIF(TRIM(si.barcode), ''))
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND {$nameKey('si.item_name')} = {$inventoryName}
          AND COALESCE(NULLIF(TRIM(p.sku), ''), NULLIF(TRIM(si.item_code), ''), NULLIF(TRIM(si.barcode), '')) IS NOT NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN (
            SELECT {$nameKey('item_name')} AS name_key, MIN(NULLIF(TRIM(sku), '')) AS sku
            FROM inventory_movements
            WHERE NULLIF(TRIM(sku), '') IS NOT NULL
            GROUP BY {$nameKey('item_name')}
        ) mv ON mv.name_key = {$inventoryName}
        SET ci.sku = mv.sku
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND mv.sku IS NOT NULL
    ");

    $run($pdo, "
        UPDATE current_inventory ci
        INNER JOIN (
            SELECT {$nameKey('item_name')} AS name_key, MIN(NULLIF(TRIM(sku), '')) AS sku
            FROM current_inventory
            WHERE NULLIF(TRIM(sku), '') IS NOT NULL
            GROUP BY {$nameKey('item_name')}
        ) src ON src.name_key = {$inventoryName}
        SET ci.sku = src.sku
        WHERE (ci.sku IS NULL OR TRIM(ci.sku) = '')
          AND src.sku IS NOT NULL
    ");

    if ($updated === 0 && $errors) {
        return [
            'ok' => false,
            'success' => false,
            'updated' => 0,
            'message' => 'Unable to copy SKU: ' . $errors[0],
        ];
    }

    return [
        'ok' => true,
        'success' => true,
        'updated' => $updated,
        'message' => $updated > 0
            ? ('Copied SKU to ' . $updated . ' inventory row' . ($updated === 1 ? '' : 's') . ', including old products.')
            : 'No empty SKU rows to copy. Old products still need a SKU, barcode, or item code on the product.',
    ];
}

function inventory_onhand_base_sql(PDO $pdo): string
{
    product_set_ensure_schema($pdo);

    return '
        SELECT
            item_name,
            sku,
            item_type,
            product_type,
            brand_id,
            brand_name,
            storage_location_id,
            location_code,
            location_name,
            SUM(quantity_on_hand) AS quantity_on_hand,
            SUM(reserved_quantity) AS reserved_quantity,
            SUM(quantity_on_hand) - SUM(reserved_quantity) AS available_quantity,
            SUM(total_value) AS total_value,
            CASE
                WHEN SUM(quantity_on_hand) > 0 THEN SUM(total_value) / SUM(quantity_on_hand)
                ELSE 0
            END AS unit_cost,
            MAX(last_updated) AS last_updated
        FROM (
            SELECT
                ci.item_name,
                COALESCE(NULLIF(TRIM(ci.sku), \'\'), NULLIF(TRIM(p.sku), \'\')) AS sku,
                \'inventory\' AS item_type,
                LOWER(COALESCE(NULLIF(TRIM(p.product_type), \'\'), \'normal\')) AS product_type,
                COALESCE(p.brand_id, 0) AS brand_id,
                COALESCE(NULLIF(TRIM(b.name), \'\'), \'\') AS brand_name,
                ci.storage_location_id,
                sl.location_code,
                sl.location_name,
                ci.quantity_on_hand,
                COALESCE(ci.reserved_quantity, 0) AS reserved_quantity,
                COALESCE(ci.total_value, ci.quantity_on_hand * COALESCE(ci.unit_cost, 0), 0) AS total_value,
                ci.last_updated
            FROM current_inventory ci
            LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
            LEFT JOIN (
                SELECT
                    TRIM(name) AS name_key,
                    MIN(NULLIF(TRIM(sku), \'\')) AS sku,
                    MIN(brand_id) AS brand_id,
                    MIN(product_type) AS product_type
                FROM products
                GROUP BY TRIM(name)
            ) p ON p.name_key COLLATE utf8mb4_unicode_ci = TRIM(ci.item_name) COLLATE utf8mb4_unicode_ci
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
            WHERE ps.id IS NULL

            UNION ALL

            SELECT
                ps.set_name AS item_name,
                CONCAT(\'SET-\', ps.id) AS sku,
                \'set\' AS item_type,
                \'set\' AS product_type,
                0 AS brand_id,
                \'\' AS brand_name,
                COALESCE(ps.storage_location_id, 0) AS storage_location_id,
                sl.location_code,
                sl.location_name,
                COALESCE(ps.available_stock, 0) AS quantity_on_hand,
                0 AS reserved_quantity,
                (COALESCE(ps.available_stock, 0) * COALESCE(ps.selling_price, 0)) AS total_value,
                COALESCE(ps.updated_at, ps.created_at, NOW()) AS last_updated
            FROM product_sets ps
            LEFT JOIN storage_locations sl ON sl.id = ps.storage_location_id
            WHERE ps.is_active = 1
        ) inv
        GROUP BY item_name, sku, item_type, product_type, brand_id, brand_name, storage_location_id, location_code, location_name
    ';
}

function inventory_product_type_options(PDO $pdo): array
{
    $options = [
        ['value' => 'normal', 'label' => 'Normal'],
        ['value' => 'general', 'label' => 'General'],
        ['value' => 'set', 'label' => 'Set'],
    ];
    try {
        $rows = $pdo->query("
            SELECT DISTINCT LOWER(TRIM(product_type)) AS product_type
            FROM products
            WHERE product_type IS NOT NULL AND TRIM(product_type) <> ''
            ORDER BY product_type
        ")->fetchAll(PDO::FETCH_COLUMN);
        $map = [];
        foreach ($options as $opt) {
            $map[$opt['value']] = $opt;
        }
        foreach ($rows as $type) {
            $key = strtolower(trim((string)$type));
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $map[$key] = [
                'value' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
            ];
        }
        return array_values($map);
    } catch (Throwable $e) {
        return $options;
    }
}

function inventory_product_type_label(string $type): string
{
    $key = strtolower(trim($type));
    if ($key === '' || $key === 'inventory') {
        return 'Normal';
    }
    if ($key === 'general') {
        return 'General';
    }
    if ($key === 'set') {
        return 'Set';
    }
    if ($key === 'normal') {
        return 'Normal';
    }
    return ucwords(str_replace('_', ' ', $key));
}

function inventory_movement_type_label(string $type): string
{
    static $labels = [
        'in' => 'Stock In',
        'out' => 'Stock Out',
        'transfer' => 'Transfer',
        'adjustment' => 'Adjustment',
        'purchase_in' => 'Received',
        'transfer_out' => 'Transfer Out',
        'transfer_in' => 'Transfer In',
        'sale_out' => 'Sale Out',
        'return_in' => 'Return In',
        'damage_out' => 'Damage Out',
        'purchase' => 'Purchase',
        'sale' => 'Sale',
        'return' => 'Return',
        'offline_sale' => 'Offline Sale',
        'offline_customer_purchase' => 'Offline Purchase',
        'initial' => 'Initial',
    ];
    $key = strtolower(trim($type));
    return $labels[$key] ?? ($key !== '' ? ucwords(str_replace('_', ' ', $key)) : 'Movement');
}

function inventory_user_display_label(?string $username, ?string $name = null): string
{
    $name = trim((string)$name);
    if ($name !== '') {
        return $name;
    }
    return trim((string)$username);
}

function inventory_user_name_map(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = ['by_username' => [], 'by_id' => []];
    try {
        $rows = $pdo->query('SELECT id, username, name FROM users')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $username = strtolower(trim((string)($row['username'] ?? '')));
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['username'] ?? ''));
            }
            if ($id > 0 && $name !== '') {
                $cache['by_id'][(string)$id] = $name;
            }
            if ($username !== '' && $name !== '') {
                $cache['by_username'][$username] = $name;
            }
        }
    } catch (Throwable $e) {
        // optional
    }
    return $cache;
}

function inventory_resolve_user_name(PDO $pdo, ?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $map = inventory_user_name_map($pdo);
    $key = strtolower($raw);
    if (isset($map['by_username'][$key])) {
        return $map['by_username'][$key];
    }
    if (isset($map['by_id'][$raw])) {
        return $map['by_id'][$raw];
    }
    return $raw;
}

function inventory_notes_display(string $notes, array $locationMap = []): string
{
    $notes = trim($notes);
    if ($notes === '') {
        return '';
    }

    $notes = preg_replace_callback(
        '/\[From:(\d+)\s+To:(\d+)\]/i',
        static function (array $m) use ($locationMap): string {
            $fromId = (int)$m[1];
            $toId = (int)$m[2];
            $from = $locationMap[$fromId] ?? ('Location #' . $fromId);
            $to = $locationMap[$toId] ?? ('Location #' . $toId);
            return $from . ' -> ' . $to;
        },
        $notes
    ) ?? $notes;

    $notes = preg_replace_callback(
        '/\[Location:(\d+)\]/i',
        static function (array $m) use ($locationMap): string {
            $id = (int)$m[1];
            return $locationMap[$id] ?? ('Location #' . $id);
        },
        $notes
    ) ?? $notes;

    return trim(preg_replace('/\s{2,}/', ' ', $notes) ?? $notes);
}

function inventory_api_stored_files(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value)));
    }
    $text = trim((string)$value);
    if ($text === '') {
        return [];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }
    return [$text];
}

function inventory_api_reference_file_urls(array $files): array
{
    return array_map(static function ($path) {
        return uploaded_file_url((string)$path);
    }, inventory_api_stored_files($files));
}

function inventory_api_ensure_text_column(PDO $pdo, string $table, string $column): void
{
    $allowed = [
        'stock_movements.reference_files' => true,
    ];
    if (empty($allowed[$table . '.' . $column])) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $stmt->execute([$column]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD `' . $column . '` TEXT NULL');
        }
    } catch (Throwable $e) {
        // keep older schemas working
    }
}

function inventory_api_save_reference_files(array $files, string $category = 'inventory_movements', ?string $date = null): array
{
    $saved = [];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $type = strtolower(trim((string)($file['type'] ?? 'application/octet-stream')));
        $size = (int)($file['size'] ?? 0);
        $data = (string)($file['data'] ?? '');
        if ($name === '' || $data === '' || $size <= 0) {
            continue;
        }
        if ($size > 5 * 1024 * 1024) {
            api_error('Image is too large. Maximum is 5MB per image.', 422);
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true) || !in_array($type, $allowedTypes, true)) {
            api_error('Invalid image type. Use JPG, PNG, GIF, or WebP only.', 422);
        }
        $binary = base64_decode($data, true);
        if ($binary === false) {
            api_error('Could not read uploaded image.', 422);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'inv_ref_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to prepare image upload.');
        }
        file_put_contents($tmp, $binary);
        $filename = 'image_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            $saved[] = upload_store_file_path($tmp, $category, $filename, $date, $type, false);
        } finally {
            @unlink($tmp);
        }
    }

    return $saved;
}
