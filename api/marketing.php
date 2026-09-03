<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../upload_paths.php';
require_once __DIR__ . '/../../admin/marketing_take_functions.php';

function marketing_payload(): array
{
    if (!empty($_POST) || !empty($_FILES)) {
        $data = $_POST;
        foreach (['items', 'keep_images'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $decoded = json_decode($data[$key], true);
                if (is_array($decoded)) {
                    $data[$key] = $decoded;
                }
            }
        }
        return $data;
    }
    $raw = (string)file_get_contents('php://input');
    $data = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function marketing_qty($value): int
{
    return (int)round((float)$value);
}

function marketing_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function marketing_default_types(): array
{
    return [
        'ugc_sample' => 'UGC Sample',
        'influencer_kol' => 'Influencer / KOL',
        'event' => 'Event',
        'giveaway' => 'Giveaway',
        'promotion' => 'Promotion',
        'sponsorship' => 'Sponsorship',
        'internal_use' => 'Internal Use',
        'other' => 'Other',
    ];
}

function marketing_types(?PDO $pdo = null, bool $activeOnly = true): array
{
    if (!$pdo) {
        return marketing_default_types();
    }
    try {
        marketing_ensure_columns($pdo);
        $where = $activeOnly ? 'WHERE active = 1' : '';
        $rows = marketing_rows($pdo, "SELECT type_key, label FROM marketing_types {$where} ORDER BY sort_order ASC, label ASC");
        $types = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['type_key'] ?? ''));
            $label = trim((string)($row['label'] ?? ''));
            if ($key !== '' && $label !== '') {
                $types[$key] = $label;
            }
        }
        return $types ?: marketing_default_types();
    } catch (Throwable $e) {
        error_log('marketing_types: ' . $e->getMessage());
        return marketing_default_types();
    }
}

function marketing_type_label(?string $value, ?PDO $pdo = null): string
{
    $value = trim((string)$value);
    $types = marketing_types($pdo, false);
    return $types[$value] ?? ($value !== '' ? $value : '-');
}

function marketing_type_key(string $label, string $fallback = ''): string
{
    $key = strtolower(trim($fallback !== '' ? $fallback : $label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim((string)$key, '_');
    return substr($key !== '' ? $key : 'marketing_type', 0, 60);
}

function marketing_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function marketing_flag($value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) !== 0 ? 1 : 0;
    }
    $raw = strtolower(trim((string)$value));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function marketing_phone($value): string
{
    return substr(trim((string)$value), 0, 80);
}

function marketing_ensure_columns(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'marketing_type'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN marketing_type VARCHAR(40) NULL AFTER event_name");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'phone'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN phone VARCHAR(80) NULL AFTER location");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'print_delivery_note'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN print_delivery_note TINYINT(1) NOT NULL DEFAULT 0 AFTER phone");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'delivery_type_id'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN delivery_type_id INT NULL AFTER print_delivery_note");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'images'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN images TEXT NULL AFTER notes");
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketing_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type_key VARCHAR(60) NOT NULL UNIQUE,
                label VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $sort = 10;
        $stmt = $pdo->prepare("INSERT IGNORE INTO marketing_types (type_key, label, sort_order, active) VALUES (?, ?, ?, 1)");
        foreach (marketing_default_types() as $key => $label) {
            $stmt->execute([$key, $label, $sort]);
            $sort += 10;
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM marketing_types LIKE 'image_path'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE marketing_types ADD COLUMN image_path VARCHAR(255) NULL AFTER label");
        }
    } catch (Throwable $e) {
        error_log('marketing_ensure_columns: ' . $e->getMessage());
    }
}

function marketing_parse_images($value): array
{
    if (is_array($value)) {
        $paths = $value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $paths = is_array($decoded) ? $decoded : [$raw];
    }
    $clean = [];
    foreach ($paths as $path) {
        $item = trim((string)$path);
        if ($item !== '' && !isset($clean[$item])) {
            $clean[$item] = $item;
        }
    }
    return array_values($clean);
}

function marketing_image_url(string $path): string
{
    if ($path === '' || !function_exists('uploaded_file_url')) {
        return '';
    }
    return trim((string)uploaded_file_url($path, 'marketing_images'));
}

function marketing_image_urls($stored): array
{
    $urls = [];
    foreach (marketing_parse_images($stored) as $path) {
        $url = marketing_image_url($path);
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    return $urls;
}

function marketing_upload_files(string $field, ?string $date = null): array
{
    $files = $_FILES[$field] ?? null;
    if (!$files) {
        return [];
    }
    $list = [];
    if (is_array($files['name'] ?? null)) {
        foreach ($files['name'] as $index => $name) {
            $list[] = [
                'name' => (string)$name,
                'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
                'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($files['size'][$index] ?? 0),
                'type' => (string)($files['type'][$index] ?? ''),
            ];
        }
    } else {
        $list[] = $files;
    }

    $saved = [];
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    foreach ($list as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true) || (int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
            continue;
        }
        $filename = 'mt_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $path = upload_store_uploaded_file($file, 'marketing_images', $filename, $date, (string)($file['type'] ?? ''));
        if ($path !== '') {
            $saved[] = $path;
        }
    }
    return $saved;
}

function marketing_store_images($existing, $keep, ?string $date = null): string
{
    $current = marketing_parse_images($existing);
    $keepPaths = marketing_parse_images($keep === null || $keep === '' ? $current : $keep);
    $keepSet = array_fill_keys($keepPaths, true);
    foreach ($current as $path) {
        if (!isset($keepSet[$path])) {
            upload_delete_local_file($path, 'marketing_images');
        }
    }
    $merged = array_values(array_unique(array_merge($keepPaths, marketing_upload_files('images', $date))));
    return $merged ? json_encode($merged, JSON_UNESCAPED_SLASHES) : '';
}

function marketing_attach_images(array $row): array
{
    $paths = marketing_parse_images($row['images'] ?? '');
    $urls = marketing_image_urls($paths);
    $row['images'] = $paths;
    $row['image_urls'] = $urls;
    $row['image_url'] = $urls[0] ?? '';
    return $row;
}

function marketing_permissions(string $ability = 'read'): array
{
    $map = [
        'read' => [
            'marketing_take.view',
            'marketing_take.create',
            'marketing_take_report.view',
            'marketing_take_approve.view',
            'marketing_take_reconcile.view',
            'sr_marketing_suggest_report.view',
            'sr_marketing_create_take.view',
            'sr_marketing_approve_take.view',
            'sr_marketing_reconcile_take.view',
            'sr_marketing_type.view',
        ],
        'create' => ['marketing_take.create', 'sr_marketing_create_take.create', 'sr_marketing_create_take.view'],
        'approve' => ['marketing_take_approve.view', 'sr_marketing_approve_take.approve', 'sr_marketing_approve_take.view'],
        'reconcile' => ['marketing_take_reconcile.view', 'sr_marketing_reconcile_take.update', 'sr_marketing_reconcile_take.view'],
        'type_read' => ['sr_marketing_type.view', 'sr_marketing_create_take.view', 'sr_marketing_suggest_report.view'],
        'type_create' => ['sr_marketing_type.create', 'sr_marketing_type.view'],
        'type_update' => ['sr_marketing_type.update', 'sr_marketing_type.view'],
        'type_delete' => ['sr_marketing_type.delete', 'sr_marketing_type.view'],
        'view_all' => [
            'marketing_take_view_all.view',
            'sr_marketing_suggest_report.view',
            'sr_marketing_approve_take.view',
            'sr_marketing_reconcile_take.view',
        ],
    ];
    return $map[$ability] ?? $map['read'];
}

function marketing_can(string $ability): bool
{
    if (!function_exists('has_permission')) {
        return false;
    }
    foreach (marketing_permissions($ability) as $key) {
        if (has_permission($key)) {
            return true;
        }
    }
    return false;
}

function marketing_require(string $ability = 'read'): void
{
    require_role_or_permission(['admin'], ...marketing_permissions($ability));
}

function marketing_user_context(PDO $pdo): array
{
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401, ['error' => 'session_expired']);
    }
    $roles = user_role_names($pdo, $user);
    $isAdmin = in_array('admin', $roles, true) || (($user['username'] ?? '') === 'admin') || (($user['role'] ?? '') === 'admin');
    return [$user, (int)($user['id'] ?? 0), $isAdmin || marketing_can('view_all'), $isAdmin];
}

function marketing_status_label(string $status): string
{
    return [
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'pending' => 'In Marketing',
        'completed' => 'Completed',
    ][$status] ?? ucwords(str_replace('_', ' ', $status));
}

function marketing_selected_types($value): array
{
    $parts = is_array($value) ? $value : preg_split('/[,\|]+/', trim((string)$value));
    $types = [];
    foreach ($parts ?: [] as $part) {
        $key = trim((string)$part);
        if ($key !== '') {
            $types[$key] = $key;
        }
    }
    return array_values($types);
}

function marketing_status_counts(PDO $pdo, string $from, string $to, int $userId, bool $canViewAll, array $marketingTypes = [], int $productId = 0, int $createdBy = 0): array
{
    $params = [$from, $to];
    $where = 'event_date BETWEEN ? AND ?';
    if (!$canViewAll && $userId > 0) {
        $where .= ' AND created_by = ?';
        $params[] = $userId;
    }
    if ($marketingTypes) {
        $where .= ' AND marketing_type IN (' . implode(',', array_fill(0, count($marketingTypes), '?')) . ')';
        array_push($params, ...$marketingTypes);
    }
    if ($createdBy > 0) {
        $where .= ' AND created_by = ?';
        $params[] = $createdBy;
    }
    if ($productId > 0) {
        $where .= ' AND EXISTS (SELECT 1 FROM marketing_take_items mti WHERE mti.marketing_take_id = marketing_takes.id AND mti.product_id = ?)';
        $params[] = $productId;
    }
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM marketing_takes WHERE {$where} GROUP BY status");
    $stmt->execute($params);
    $counts = [
        'pending_approval' => 0,
        'approved' => 0,
        'rejected' => 0,
        'pending' => 0,
        'completed' => 0,
    ];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[(string)$row['status']] = (int)$row['cnt'];
    }
    return $counts;
}

function marketing_type_counts(PDO $pdo, string $from, string $to, int $userId, bool $canViewAll, string $mode = 'all', string $status = '', int $productId = 0, int $createdBy = 0): array
{
    $params = [$from, $to];
    $where = 'event_date BETWEEN ? AND ?';
    if (!$canViewAll && $userId > 0) {
        $where .= ' AND created_by = ?';
        $params[] = $userId;
    }
    if ($mode === 'approval') {
        $where .= " AND status = 'pending_approval'";
    } elseif ($mode === 'reconcile') {
        $where .= " AND status = 'pending'";
    } elseif ($status !== '') {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    if ($createdBy > 0) {
        $where .= ' AND created_by = ?';
        $params[] = $createdBy;
    }
    if ($productId > 0) {
        $where .= ' AND EXISTS (SELECT 1 FROM marketing_take_items mti WHERE mti.marketing_take_id = marketing_takes.id AND mti.product_id = ?)';
        $params[] = $productId;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(marketing_type, '') AS marketing_type, COUNT(*) AS cnt FROM marketing_takes WHERE {$where} GROUP BY marketing_type");
    $stmt->execute($params);
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = trim((string)$row['marketing_type']);
        if ($key !== '') {
            $counts[$key] = (int)$row['cnt'];
        }
    }
    return $counts;
}

function marketing_list(PDO $pdo, array $filters, int $userId, bool $canViewAll, string $mode = 'all'): array
{
    $from = marketing_date($filters['from'] ?? null, date('Y-m-d', strtotime('-30 days')));
    $to = marketing_date($filters['to'] ?? null, date('Y-m-d'));
    $status = trim((string)($filters['status'] ?? ''));
    $marketingTypes = marketing_selected_types($filters['marketing_type'] ?? null);
    $productId = (int)($filters['product_id'] ?? 0);
    $createdBy = (int)($filters['created_by'] ?? 0);
    $q = trim((string)($filters['q'] ?? ''));

    $params = [$from, $to];
    $where = ['mt.event_date BETWEEN ? AND ?'];
    if (!$canViewAll && $userId > 0) {
        $where[] = 'mt.created_by = ?';
        $params[] = $userId;
    }
    if ($mode === 'approval') {
        $where[] = "mt.status = 'pending_approval'";
    } elseif ($mode === 'reconcile') {
        $where[] = "mt.status = 'pending'";
    } elseif ($status !== '') {
        $where[] = 'mt.status = ?';
        $params[] = $status;
    }
    if ($marketingTypes) {
        $where[] = 'mt.marketing_type IN (' . implode(',', array_fill(0, count($marketingTypes), '?')) . ')';
        array_push($params, ...$marketingTypes);
    }
    if ($createdBy > 0) {
        $where[] = 'mt.created_by = ?';
        $params[] = $createdBy;
    }
    if ($productId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM marketing_take_items mti_filter WHERE mti_filter.marketing_take_id = mt.id AND mti_filter.product_id = ?)';
        $params[] = $productId;
    }
    if ($q !== '') {
        $where[] = '(mt.take_code LIKE ? OR mt.event_name LIKE ? OR mt.marketing_type LIKE ? OR mt.location LIKE ? OR mt.phone LIKE ? OR u1.name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $rows = marketing_rows($pdo, "
        SELECT
            mt.*,
            COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) AS display_code,
            COALESCE(u1.name, u1.username, '-') AS created_by_name,
            COALESCE(u2.name, u2.username, '-') AS approved_by_name,
            COALESCE(u3.name, u3.username, '-') AS reconciled_by_name,
            sl.location_code,
            sl.location_name,
            dt.name AS delivery_type_name,
            COALESCE(items.item_count, 0) AS item_count,
            COALESCE(items.total_taken, 0) AS total_taken,
            COALESCE(items.total_returned, 0) AS total_returned,
            COALESCE(items.total_not_returned, 0) AS total_not_returned,
            COALESCE(items.reconciled_count, 0) AS reconciled_count,
            COALESCE(items.in_progress_count, 0) AS in_progress_count
        FROM marketing_takes mt
        LEFT JOIN users u1 ON mt.created_by = u1.id
        LEFT JOIN users u2 ON mt.approved_by = u2.id
        LEFT JOIN users u3 ON mt.reconciled_by = u3.id
        LEFT JOIN storage_locations sl ON mt.storage_location_id = sl.id
        LEFT JOIN delivery_types dt ON mt.delivery_type_id = dt.id
        LEFT JOIN (
            SELECT
                marketing_take_id,
                COUNT(*) AS item_count,
                SUM(quantity_taken) AS total_taken,
                SUM(quantity_returned) AS total_returned,
                SUM(quantity_not_returned) AS total_not_returned,
                SUM(CASE WHEN ABS((quantity_returned + quantity_not_returned) - quantity_taken) < 0.0001 THEN 1 ELSE 0 END) AS reconciled_count,
                SUM(CASE WHEN (quantity_returned + quantity_not_returned) > 0.0001 THEN 1 ELSE 0 END) AS in_progress_count
            FROM marketing_take_items
            GROUP BY marketing_take_id
        ) items ON items.marketing_take_id = mt.id
        {$whereSql}
        ORDER BY mt.event_date DESC, mt.id DESC
        LIMIT 500
    ", $params);

    foreach ($rows as &$row) {
        $row['item_count'] = marketing_qty($row['item_count'] ?? 0);
        $row['total_taken'] = marketing_qty($row['total_taken'] ?? 0);
        $row['total_returned'] = marketing_qty($row['total_returned'] ?? 0);
        $row['total_not_returned'] = marketing_qty($row['total_not_returned'] ?? 0);
        $row['status_label'] = marketing_status_label((string)($row['status'] ?? ''));
        $row['marketing_type_label'] = marketing_type_label($row['marketing_type'] ?? '', $pdo);
        $row['remaining_qty'] = max(0, $row['total_taken'] - $row['total_returned'] - $row['total_not_returned']);
        $row = marketing_attach_images($row);
    }
    unset($row);

    $productWhereSql = $whereSql;
    $productParams = $params;
    if ($status !== 'rejected') {
        $productWhereSql .= " AND mt.status <> 'rejected'";
    }
    if ($productId > 0) {
        $productWhereSql .= ' AND p.id = ?';
        $productParams[] = $productId;
    }

    $products = marketing_rows($pdo, "
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            p.sku,
            COUNT(DISTINCT mt.id) AS event_count,
            SUM(CASE WHEN mt.status = 'pending_approval' THEN mti.quantity_taken ELSE 0 END) AS pending_qty,
            SUM(CASE WHEN mt.status IN ('pending', 'completed') THEN mti.quantity_taken ELSE 0 END) AS total_taken,
            SUM(CASE WHEN mt.status IN ('pending', 'completed') THEN mti.quantity_returned ELSE 0 END) AS total_returned,
            SUM(CASE WHEN mt.status IN ('pending', 'completed') THEN mti.quantity_not_returned ELSE 0 END) AS total_not_returned
        FROM marketing_take_items mti
        JOIN marketing_takes mt ON mt.id = mti.marketing_take_id
        JOIN products p ON p.id = mti.product_id
        LEFT JOIN users u1 ON mt.created_by = u1.id
        {$productWhereSql}
        GROUP BY p.id, p.name, p.sku
        ORDER BY p.name ASC
    ", $productParams);

    foreach ($products as &$product) {
        $pending = (float)($product['pending_qty'] ?? 0);
        $taken = (float)($product['total_taken'] ?? 0);
        $returned = (float)($product['total_returned'] ?? 0);
        $notReturned = (float)($product['total_not_returned'] ?? 0);
        $product['pending_qty'] = $pending;
        $product['remaining_qty'] = max(0, $taken - $returned - $notReturned);
        if ($product['remaining_qty'] > 0.0001) {
            $product['status'] = 'open';
            $product['status_label'] = 'In Marketing';
        } elseif ($pending > 0.0001) {
            $product['status'] = 'pending';
            $product['status_label'] = 'Pending';
        } else {
            $product['status'] = 'done';
            $product['status_label'] = 'Done';
        }
    }
    unset($product);

    return [
        'from' => $from,
        'to' => $to,
        'status' => $status,
        'marketing_type' => implode(',', $marketingTypes),
        'product_id' => $productId,
        'created_by' => $createdBy,
        'rows' => $rows,
        'products' => $products,
        'counts' => marketing_status_counts($pdo, $from, $to, $userId, $canViewAll, $marketingTypes, $productId, $createdBy),
        'type_counts' => marketing_type_counts($pdo, $from, $to, $userId, $canViewAll, $mode, $status, $productId, $createdBy),
    ];
}

function marketing_detail(PDO $pdo, int $id, int $userId, bool $canViewAll): array
{
    $stmt = $pdo->prepare("
        SELECT mt.*, COALESCE(mt.take_code, CONCAT('MT-#', mt.id)) AS display_code,
               COALESCE(u1.name, u1.username, '-') AS created_by_name,
               COALESCE(u2.name, u2.username, '-') AS approved_by_name,
               COALESCE(u3.name, u3.username, '-') AS reconciled_by_name,
               sl.location_code, sl.location_name,
               dt.name AS delivery_type_name
        FROM marketing_takes mt
        LEFT JOIN users u1 ON mt.created_by = u1.id
        LEFT JOIN users u2 ON mt.approved_by = u2.id
        LEFT JOIN users u3 ON mt.reconciled_by = u3.id
        LEFT JOIN storage_locations sl ON mt.storage_location_id = sl.id
        LEFT JOIN delivery_types dt ON mt.delivery_type_id = dt.id
        WHERE mt.id = ?
    ");
    $stmt->execute([$id]);
    $take = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$take) {
        api_error('Marketing request not found.', 404);
    }
    if (!$canViewAll && $userId > 0 && (int)($take['created_by'] ?? 0) !== $userId) {
        api_error('You cannot view this marketing request.', 403);
    }

    $items = marketing_rows($pdo, "
        SELECT mti.*, p.name AS product_name, p.sku,
               (mti.quantity_taken - mti.quantity_returned - mti.quantity_not_returned) AS remaining_qty
        FROM marketing_take_items mti
        JOIN products p ON mti.product_id = p.id
        WHERE mti.marketing_take_id = ?
        ORDER BY p.name
    ", [$id]);

    foreach ($items as &$item) {
        $item['quantity_taken'] = marketing_qty($item['quantity_taken'] ?? 0);
        $item['quantity_returned'] = marketing_qty($item['quantity_returned'] ?? 0);
        $item['quantity_not_returned'] = marketing_qty($item['quantity_not_returned'] ?? 0);
        $item['remaining_qty'] = max(0, marketing_qty($item['remaining_qty'] ?? 0));
    }
    unset($item);

    $take['status_label'] = marketing_status_label((string)($take['status'] ?? ''));
    $take['marketing_type_label'] = marketing_type_label($take['marketing_type'] ?? '', $pdo);
    $take['phone'] = (string)($take['phone'] ?? '');
    $take['print_delivery_note'] = marketing_flag($take['print_delivery_note'] ?? 0);
    $take = marketing_attach_images($take);
    return ['take' => $take, 'items' => $items] + marketing_invoice_profile($pdo);
}

function marketing_invoice_profile(PDO $pdo): array
{
    $settings = function_exists('get_invoice_settings') ? get_invoice_settings($pdo) : [];
    $logo = function_exists('get_invoice_logo') ? get_invoice_logo($pdo) : null;
    $filePath = trim((string)($logo['file_path'] ?? ''));
    $logoUrl = '';
    if ($filePath !== '' && function_exists('uploaded_file_url')) {
        $logoUrl = trim((string)uploaded_file_url($filePath, 'logos'));
    }
    if ($logoUrl === '') {
        $logoUrl = rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/') . '/public/image.png';
    }
    return [
        'logo_url' => $logoUrl,
        'settings' => [
            'company_name' => $settings['company_name'] ?? 'My Company',
            'company_address' => $settings['company_address'] ?? '',
            'company_phone' => $settings['company_phone'] ?? '',
            'company_email' => $settings['company_email'] ?? '',
            'contact_person' => $settings['contact_person'] ?? '',
            'payment_url' => $settings['payment_url'] ?? '',
            'logo_width' => (int)($settings['logo_width'] ?? 80),
            'logo_height' => (int)($settings['logo_height'] ?? 70),
        ],
    ];
}

function marketing_stock_operation_has_product(PDO $pdo): bool
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM stock_operations')->fetchAll(PDO::FETCH_COLUMN);
        return in_array('product_id', $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

function marketing_type_rows(PDO $pdo): array
{
    try {
        $rows = marketing_rows($pdo, 'SELECT id, type_key, label, image_path, sort_order, active, created_at, updated_at FROM marketing_types ORDER BY sort_order ASC, label ASC');
    } catch (Throwable $e) {
        $rows = marketing_rows($pdo, 'SELECT id, type_key, label, sort_order, active, created_at, updated_at FROM marketing_types ORDER BY sort_order ASC, label ASC');
    }
    foreach ($rows as &$row) {
        $row['active'] = (int)($row['active'] ?? 1);
        $row['sort_order'] = (int)($row['sort_order'] ?? 0);
        $row['image_path'] = trim((string)($row['image_path'] ?? ''));
        $row['image_url'] = marketing_image_url($row['image_path']);
    }
    unset($row);
    return $rows;
}

function marketing_type_payload(PDO $pdo, bool $isAdmin = false): array
{
    return [
        'success' => true,
        'types' => marketing_type_rows($pdo),
        'canCreate' => $isAdmin || marketing_can('type_create'),
        'canUpdate' => $isAdmin || marketing_can('type_update'),
        'canDelete' => $isAdmin || marketing_can('type_delete'),
    ];
}

try {
    $pdo = get_db_connection();
    marketing_ensure_columns($pdo);
    [$currentUser, $userId, $canViewAll, $isAdmin] = marketing_user_context($pdo);
    $action = strtolower(trim((string)($_GET['action'] ?? 'report')));

    $readActions = ['options', 'report', 'list', 'detail', 'types'];
    if (in_array($action, $readActions, true)) {
        marketing_require('read');
    }

    if ($action === 'types') {
        marketing_require('type_read');
        api_json(marketing_type_payload($pdo, $isAdmin));
    }

    if ($action === 'options') {
        $typeRows = array_values(array_filter(marketing_type_rows($pdo), static fn(array $row): bool => (int)($row['active'] ?? 1) === 1));
        api_json([
            'success' => true,
            'products' => marketing_rows($pdo, "SELECT id AS value, name AS label, sku FROM products WHERE COALESCE(product_type, 'normal') != 'set' ORDER BY name"),
            'creators' => marketing_rows($pdo, "SELECT id AS value, COALESCE(NULLIF(name, ''), username, CONCAT('User #', id)) AS label FROM users ORDER BY label"),
            'locations' => marketing_rows($pdo, "SELECT id AS value, TRIM(CONCAT(COALESCE(location_code, ''), CASE WHEN COALESCE(location_code, '') <> '' AND COALESCE(location_name, '') <> '' THEN ' - ' ELSE '' END, COALESCE(location_name, ''))) AS label, is_default FROM storage_locations WHERE is_active = 1 ORDER BY is_default DESC, location_code ASC, location_name ASC"),
            'delivery_types' => marketing_rows($pdo, "SELECT id AS value, name AS label FROM delivery_types WHERE active = 1 ORDER BY name"),
            'marketing_types' => array_map(
                static fn(array $row): array => [
                    'value' => (string)$row['type_key'],
                    'label' => (string)$row['label'],
                    'image_url' => (string)($row['image_url'] ?? ''),
                ],
                $typeRows
            ),
        ]);
    }

    if ($action === 'report' || $action === 'list') {
        $mode = (string)($_GET['mode'] ?? 'all');
        $data = marketing_list($pdo, $_GET, $userId, $canViewAll, in_array($mode, ['approval', 'reconcile'], true) ? $mode : 'all');
        api_json(['success' => true] + $data);
    }

    if ($action === 'detail') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) api_error('Invalid marketing request.', 422);
        api_json(['success' => true] + marketing_detail($pdo, (int)$id, $userId, $canViewAll));
    }

    $payload = marketing_payload();

    if ($action === 'add_type' || $action === 'update_type') {
        marketing_require($action === 'add_type' ? 'type_create' : 'type_update');
        $id = (int)($payload['id'] ?? 0);
        $label = trim((string)($payload['label'] ?? ''));
        $typeKey = marketing_type_key($label, (string)($payload['type_key'] ?? ''));
        $sortOrder = (int)($payload['sort_order'] ?? 0);
        $active = !empty($payload['active']) ? 1 : 0;
        $removeImage = marketing_flag($payload['remove_image'] ?? 0) === 1;
        if ($label === '') api_error('Marketing type name is required.', 422);
        if ($action === 'add_type') {
            $typeImage = marketing_upload_files('image')[0] ?? '';
            $stmt = $pdo->prepare('INSERT INTO marketing_types (type_key, label, image_path, sort_order, active) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$typeKey, $label, $typeImage !== '' ? $typeImage : null, $sortOrder, $active]);
            api_json(marketing_type_payload($pdo, $isAdmin) + ['message' => 'Marketing type created.']);
        }
        if ($id <= 0) api_error('Invalid marketing type.', 422);
        $current = $pdo->prepare('SELECT image_path FROM marketing_types WHERE id = ?');
        $current->execute([$id]);
        $currentPath = trim((string)($current->fetchColumn() ?: ''));
        $uploaded = marketing_upload_files('image');
        $nextPath = $currentPath;
        if ($uploaded) {
            if ($currentPath !== '') {
                upload_delete_local_file($currentPath, 'marketing_images');
            }
            $nextPath = $uploaded[0];
        } elseif ($removeImage && $currentPath !== '') {
            upload_delete_local_file($currentPath, 'marketing_images');
            $nextPath = '';
        }
        $stmt = $pdo->prepare('UPDATE marketing_types SET label = ?, image_path = ?, sort_order = ?, active = ? WHERE id = ?');
        $stmt->execute([$label, $nextPath !== '' ? $nextPath : null, $sortOrder, $active, $id]);
        api_json(marketing_type_payload($pdo, $isAdmin) + ['message' => 'Marketing type updated.']);
    }

    if ($action === 'delete_type') {
        marketing_require('type_delete');
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) api_error('Invalid marketing type.', 422);
        $stmt = $pdo->prepare('SELECT type_key, label FROM marketing_types WHERE id = ?');
        $stmt->execute([$id]);
        $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$type) api_error('Marketing type not found.', 404);
        $used = $pdo->prepare('SELECT COUNT(*) FROM marketing_takes WHERE marketing_type = ?');
        $used->execute([(string)$type['type_key']]);
        if ((int)$used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE marketing_types SET active = 0 WHERE id = ?')->execute([$id]);
            api_json(marketing_type_payload($pdo, $isAdmin) + ['message' => 'Marketing type is used already, so it was set inactive.']);
        }
        $pdo->prepare('DELETE FROM marketing_types WHERE id = ?')->execute([$id]);
        api_json(marketing_type_payload($pdo, $isAdmin) + ['message' => 'Marketing type deleted.']);
    }

    if ($action === 'create') {
        marketing_require('create');
        $eventName = trim((string)($payload['event_name'] ?? ''));
        $marketingType = trim((string)($payload['marketing_type'] ?? ''));
        $eventDate = marketing_date($payload['event_date'] ?? null, '');
        $location = trim((string)($payload['location'] ?? ''));
        $phone = marketing_phone($payload['phone'] ?? '');
        $deliveryTypeId = (int)($payload['delivery_type_id'] ?? 0);
        $printDeliveryNote = marketing_flag($payload['print_delivery_note'] ?? 0);
        $notes = trim((string)($payload['notes'] ?? ''));
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($eventName === '' || $eventDate === '') api_error('Event name and date are required.', 422);
        if (!array_key_exists($marketingType, marketing_types($pdo))) api_error('Marketing type is required.', 422);
        if ($printDeliveryNote && $phone === '') api_error('Phone number is required to print a delivery note.', 422);
        $validItems = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = marketing_qty($item['quantity'] ?? 0);
            if ($productId > 0 && $qty > 0) $validItems[] = ['product_id' => $productId, 'quantity' => $qty];
        }
        if (!$validItems) api_error('Add at least one product with quantity.', 422);
        $imagesJson = marketing_store_images([], $payload['keep_images'] ?? [], $eventDate ?: null);

        $itemsForTelegram = [];
        $createdByName = $currentUser['name'] ?? $currentUser['username'] ?? 'User';
        $pdo->beginTransaction();
        try {
            $takeCode = generate_marketing_take_code($pdo);
            $stmt = $pdo->prepare("INSERT INTO marketing_takes (take_code, event_name, marketing_type, event_date, location, phone, print_delivery_note, delivery_type_id, notes, images, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval')");
            $stmt->execute([$takeCode, $eventName, $marketingType, $eventDate, $location, $phone, $printDeliveryNote, $deliveryTypeId > 0 ? $deliveryTypeId : null, $notes, $imagesJson, $userId]);
            $takeId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO marketing_take_items (marketing_take_id, product_id, quantity_taken) VALUES (?, ?, ?)');
            foreach ($validItems as $item) {
                $itemStmt->execute([$takeId, $item['product_id'], $item['quantity']]);
            }
            $itemsForTelegram = marketing_rows($pdo, 'SELECT p.name AS product_name, mti.quantity_taken FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?', [$takeId]);
            $pdo->commit();
            send_marketing_suggest_to_telegram($pdo, $takeId, $takeCode, $eventName, $eventDate, $location ?: null, $notes ?: null, $itemsForTelegram, $createdByName);
            api_json(['success' => true, 'message' => 'Marketing request created.', 'id' => $takeId, 'take_code' => $takeCode]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'update') {
        marketing_require('create');
        $id = (int)($payload['id'] ?? 0);
        $eventName = trim((string)($payload['event_name'] ?? ''));
        $marketingType = trim((string)($payload['marketing_type'] ?? ''));
        $eventDate = marketing_date($payload['event_date'] ?? null, '');
        $location = trim((string)($payload['location'] ?? ''));
        $phone = marketing_phone($payload['phone'] ?? '');
        $deliveryTypeId = (int)($payload['delivery_type_id'] ?? 0);
        $printDeliveryNote = marketing_flag($payload['print_delivery_note'] ?? 0);
        $notes = trim((string)($payload['notes'] ?? ''));
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($id <= 0) api_error('Invalid marketing request.', 422);
        if ($eventName === '' || $eventDate === '') api_error('Event name and date are required.', 422);
        if (!array_key_exists($marketingType, marketing_types($pdo))) api_error('Marketing type is required.', 422);
        if ($printDeliveryNote && $phone === '') api_error('Phone number is required to print a delivery note.', 422);
        $validItems = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = marketing_qty($item['quantity'] ?? 0);
            if ($productId > 0 && $qty > 0) $validItems[] = ['product_id' => $productId, 'quantity' => $qty];
        }

        $stmt = $pdo->prepare('SELECT * FROM marketing_takes WHERE id = ?');
        $stmt->execute([$id]);
        $take = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$take) api_error('Marketing request not found.', 404);
        if (!$canViewAll && $userId > 0 && (int)($take['created_by'] ?? 0) !== $userId) {
            api_error('You cannot edit this marketing request.', 403);
        }
        $status = (string)($take['status'] ?? '');
        $canEditItems = in_array($status, ['pending_approval', 'rejected'], true);
        if ($canEditItems && !$validItems) {
            api_error('Add at least one product with quantity.', 422);
        }
        $imagesJson = marketing_store_images($take['images'] ?? '', array_key_exists('keep_images', $payload) ? $payload['keep_images'] : null, $eventDate ?: null);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE marketing_takes SET event_name = ?, marketing_type = ?, event_date = ?, location = ?, phone = ?, print_delivery_note = ?, delivery_type_id = ?, notes = ?, images = ? WHERE id = ?')
                ->execute([$eventName, $marketingType, $eventDate, $location, $phone, $printDeliveryNote, $deliveryTypeId > 0 ? $deliveryTypeId : null, $notes, $imagesJson, $id]);
            if ($canEditItems) {
                $pdo->prepare('DELETE FROM marketing_take_items WHERE marketing_take_id = ?')->execute([$id]);
                $itemStmt = $pdo->prepare('INSERT INTO marketing_take_items (marketing_take_id, product_id, quantity_taken) VALUES (?, ?, ?)');
                foreach ($validItems as $item) {
                    $itemStmt->execute([$id, $item['product_id'], $item['quantity']]);
                }
            }
            $pdo->commit();
            api_json(['success' => true, 'message' => 'Marketing request updated.', 'id' => $id, 'take_code' => $take['take_code'] ?? null]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'approve' || $action === 'reject') {
        marketing_require('approve');
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) api_error('Invalid marketing request.', 422);
        $stmt = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status = 'pending_approval'");
        $stmt->execute([$id]);
        $take = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$take) api_error('Request not found or already processed.', 404);
        $items = marketing_rows($pdo, 'SELECT mti.*, p.name AS product_name FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?', [$id]);

        if ($action === 'reject') {
            $reason = trim((string)($payload['reason'] ?? ''));
            if ($reason === '') api_error('Reject reason is required.', 422);
            $pdo->prepare('UPDATE marketing_takes SET status = ?, approved_by = ?, approved_at = NOW(), reject_reason = ? WHERE id = ?')->execute(['rejected', $userId, $reason, $id]);
            send_marketing_approve_reply_to_telegram($pdo, $id, false, $currentUser['name'] ?? $currentUser['username'] ?? 'User', $reason);
            api_json(['success' => true, 'message' => 'Marketing request rejected.']);
        }

        $locationId = (int)($payload['storage_location_id'] ?? 0);
        $note = trim((string)($payload['note'] ?? ''));
        if ($locationId <= 0) api_error('Storage location is required.', 422);
        if ($note === '') api_error('Approval note is required.', 422);
        foreach ($items as $item) {
            $available = getInventoryQuantity($pdo, (string)$item['product_name'], $locationId);
            if ($available < (float)$item['quantity_taken']) {
                api_error("Insufficient stock for {$item['product_name']} (need {$item['quantity_taken']}, have {$available}).", 422);
            }
        }

        $hasProductId = marketing_stock_operation_has_product($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $qty = (float)$item['quantity_taken'];
                $prodId = (int)$item['product_id'];
                $prodName = (string)$item['product_name'];
                upsertInventoryQuantity($pdo, $prodId, $prodName, -$qty, $locationId, $userId);
                $notes = 'Marketing take ' . ($take['take_code'] ?? 'MT-#' . $id) . " for {$take['event_name']}: {$prodName}";
                if ($hasProductId) {
                    $stmt = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_outbound', ?, 'marketing_take', ?, ?, ?)");
                    $stmt->execute([$prodId, $locationId, $qty, $id, $notes, $userId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_outbound', ?, 'marketing_take', ?, ?, ?)");
                    $stmt->execute([$locationId, $qty, $id, $notes, $userId]);
                }
            }
            $pdo->prepare("UPDATE marketing_takes SET status = 'pending', storage_location_id = ?, approved_by = ?, approved_at = NOW(), approve_note = ? WHERE id = ?")->execute([$locationId, $userId, $note, $id]);
            $pdo->commit();
            send_marketing_approve_reply_to_telegram($pdo, $id, true, $currentUser['name'] ?? $currentUser['username'] ?? 'User', $note);
            api_json(['success' => true, 'message' => 'Marketing request approved and stock moved out.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'reconcile') {
        marketing_require('reconcile');
        $id = (int)($payload['id'] ?? 0);
        $returnLocationId = (int)($payload['return_location_id'] ?? 0);
        $note = trim((string)($payload['note'] ?? ''));
        $inputItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($id <= 0) api_error('Invalid marketing request.', 422);
        if ($note === '') api_error('Reconcile note is required.', 422);
        $stmt = $pdo->prepare("SELECT * FROM marketing_takes WHERE id = ? AND status IN ('pending', 'completed')");
        $stmt->execute([$id]);
        $take = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$take) api_error('Request not found or not ready for reconciliation.', 404);
        $items = marketing_rows($pdo, 'SELECT mti.*, p.name AS product_name FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?', [$id]);
        $byItemId = [];
        foreach ($inputItems as $item) {
            $byItemId[(int)($item['item_id'] ?? 0)] = $item;
        }
        $hasAny = false;
        $hasReturned = false;
        foreach ($items as $item) {
            $itemId = (int)$item['id'];
            $remaining = (float)$item['quantity_taken'] - (float)$item['quantity_returned'] - (float)$item['quantity_not_returned'];
            if ($remaining < 0.0001) continue;
            $next = $byItemId[$itemId] ?? [];
            $returned = (float)($next['returned'] ?? 0);
            $notReturned = (float)($next['not_returned'] ?? 0);
            if ($returned > 0.0001) $hasReturned = true;
            if ($returned > 0.0001 || $notReturned > 0.0001) $hasAny = true;
            if ($returned < 0 || $notReturned < 0 || $returned + $notReturned > $remaining + 0.0001) {
                api_error("{$item['product_name']}: returned + not returned cannot exceed remaining ({$remaining}).", 422);
            }
        }
        if (!$hasAny) api_error('Enter at least one returned or not returned quantity.', 422);
        if ($hasReturned && $returnLocationId <= 0) api_error('Return location is required when returned quantity is entered.', 422);

        $sourceLocationId = (int)($take['storage_location_id'] ?? 0);
        if ($sourceLocationId <= 0) $sourceLocationId = getDefaultStorageLocationId($pdo);
        $hasProductId = marketing_stock_operation_has_product($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $itemId = (int)$item['id'];
                $remaining = (float)$item['quantity_taken'] - (float)$item['quantity_returned'] - (float)$item['quantity_not_returned'];
                if ($remaining < 0.0001) continue;
                $next = $byItemId[$itemId] ?? [];
                $returned = (float)($next['returned'] ?? 0);
                $notReturned = (float)($next['not_returned'] ?? 0);
                if ($returned < 0.0001 && $notReturned < 0.0001) continue;
                $pdo->prepare('UPDATE marketing_take_items SET quantity_returned = quantity_returned + ?, quantity_not_returned = quantity_not_returned + ? WHERE id = ?')->execute([$returned, $notReturned, $itemId]);
                $prodId = (int)$item['product_id'];
                $prodName = (string)$item['product_name'];
                if ($returned > 0) {
                    upsertInventoryQuantity($pdo, $prodId, $prodName, $returned, $returnLocationId, $userId);
                    $notes = 'Marketing return (partial) ' . ($take['take_code'] ?? 'MT-#' . $id) . " for {$take['event_name']}: {$prodName} | Note: {$note}";
                    if ($hasProductId) {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_return', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$prodId, $returnLocationId, $returned, $id, $notes, $userId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_return', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$returnLocationId, $returned, $id, $notes, $userId]);
                    }
                }
                if ($notReturned > 0) {
                    $notes = 'Marketing write-off (partial) ' . ($take['take_code'] ?? 'MT-#' . $id) . " for {$take['event_name']}: {$prodName} (not returned) | Note: {$note}";
                    if ($hasProductId) {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'marketing_writeoff', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$prodId, $sourceLocationId, -$notReturned, $id, $notes, $userId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'marketing_writeoff', ?, 'marketing_take', ?, ?, ?)");
                        $stmt->execute([$sourceLocationId, -$notReturned, $id, $notes, $userId]);
                    }
                }
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM marketing_take_items WHERE marketing_take_id = ? AND ABS((quantity_returned + quantity_not_returned) - quantity_taken) > 0.0001');
            $stmt->execute([$id]);
            $completed = (int)$stmt->fetchColumn() === 0;
            if ($completed) {
                $pdo->prepare("UPDATE marketing_takes SET status = 'completed', reconciled_at = NOW(), reconciled_by = ? WHERE id = ?")->execute([$userId, $id]);
            }
            $pdo->commit();
            if ($completed) {
                $itemsForTg = marketing_rows($pdo, 'SELECT p.name AS product_name, mti.quantity_taken, mti.quantity_returned, mti.quantity_not_returned FROM marketing_take_items mti JOIN products p ON mti.product_id = p.id WHERE mti.marketing_take_id = ?', [$id]);
                send_marketing_reconcile_to_telegram($pdo, $id, $take['take_code'] ?? 'MT-#' . $id, $take['event_name'], $itemsForTg, $currentUser['name'] ?? $currentUser['username'] ?? 'User', null, $note);
            }
            api_json(['success' => true, 'message' => $completed ? 'Marketing request reconciled and completed.' : 'Partial reconciliation saved.', 'completed' => $completed]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    api_error('Unknown marketing action.', 404);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
