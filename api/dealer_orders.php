<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function dealer_api_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function dealer_api_num(mixed $value): float
{
    $next = (float)$value;
    return is_finite($next) ? $next : 0.0;
}

function dealer_api_str(mixed $value): string
{
    return trim((string)$value);
}

function dealer_api_date(mixed $value): string
{
    $value = dealer_api_str($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        return substr($value, 0, 10);
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

function dealer_api_datetime(mixed $value): ?string
{
    $value = dealer_api_str($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return $value;
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function dealer_api_ensure_auto_increment(PDO $pdo, string $table): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'id'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $extra = strtolower((string)($column['Extra'] ?? ''));
        if (str_contains($extra, 'auto_increment')) {
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
    } catch (Throwable $e) {
        error_log("dealer_orders auto_increment {$table}: " . $e->getMessage());
    }
}

function dealer_api_user_name(PDO $pdo, mixed $userId): string
{
    $id = (int)$userId;
    if ($id <= 0) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), CONCAT('User #', id)) FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function dealer_api_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dealer_code VARCHAR(40) NULL,
            name VARCHAR(160) NOT NULL,
            phone VARCHAR(60) NULL,
            contact_person VARCHAR(160) NULL,
            telegram VARCHAR(160) NULL,
            address TEXT NULL,
            price_level VARCHAR(80) NULL DEFAULT 'Dealer',
            credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_terms VARCHAR(80) NULL DEFAULT 'Cash',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_dealers_status (status),
            KEY idx_dealers_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dealer_id INT NOT NULL,
            action VARCHAR(40) NOT NULL,
            description TEXT NULL,
            created_by INT NULL,
            created_by_name VARCHAR(160) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dealer_logs_dealer (dealer_id),
            KEY idx_dealer_logs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'dealer_code' => "ALTER TABLE dealers ADD COLUMN dealer_code VARCHAR(40) NULL",
        'phone' => "ALTER TABLE dealers ADD COLUMN phone VARCHAR(60) NULL",
        'contact_person' => "ALTER TABLE dealers ADD COLUMN contact_person VARCHAR(160) NULL",
        'telegram' => "ALTER TABLE dealers ADD COLUMN telegram VARCHAR(160) NULL",
        'address' => "ALTER TABLE dealers ADD COLUMN address TEXT NULL",
        'price_level' => "ALTER TABLE dealers ADD COLUMN price_level VARCHAR(80) NULL DEFAULT 'Dealer'",
        'credit_limit' => "ALTER TABLE dealers ADD COLUMN credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0",
        'opening_balance' => "ALTER TABLE dealers ADD COLUMN opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0",
        'payment_terms' => "ALTER TABLE dealers ADD COLUMN payment_terms VARCHAR(80) NULL DEFAULT 'Cash'",
        'status' => "ALTER TABLE dealers ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'",
        'notes' => "ALTER TABLE dealers ADD COLUMN notes TEXT NULL",
        'created_at' => "ALTER TABLE dealers ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE dealers ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
        'created_by' => "ALTER TABLE dealers ADD COLUMN created_by INT NULL",
        'updated_by' => "ALTER TABLE dealers ADD COLUMN updated_by INT NULL",
    ] as $column => $sql) {
        dealer_api_ensure_column($pdo, 'dealers', $column, $sql);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_code VARCHAR(50) NOT NULL,
            dealer_id INT NULL,
            dealer_name VARCHAR(160) NOT NULL,
            phone VARCHAR(60) NULL,
            address TEXT NULL,
            order_date DATE NOT NULL,
            default_warehouse_id INT NULL,
            sales_person VARCHAR(160) NULL,
            payment_method VARCHAR(80) NULL,
            payment_reference VARCHAR(160) NULL,
            payment_note TEXT NULL,
            payment_date DATE NULL,
            delivery_method VARCHAR(80) NULL DEFAULT 'Pickup',
            delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'confirmed',
            payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            change_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
            stock_deducted_at DATETIME NULL,
            stock_reference_id VARCHAR(80) NULL,
            stock_documents TEXT NULL,
            stock_reversed TINYINT(1) NOT NULL DEFAULT 0,
            stock_reversed_at DATETIME NULL,
            cancel_note TEXT NULL,
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            created_by INT NULL,
            updated_by INT NULL,
            UNIQUE KEY uq_dealer_orders_code (order_code),
            KEY idx_dealer_orders_date (order_date),
            KEY idx_dealer_orders_status (status),
            KEY idx_dealer_orders_dealer (dealer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id VARCHAR(80) NULL,
            movement_item_id INT NULL,
            product_name VARCHAR(220) NOT NULL,
            warehouse_id INT NULL,
            warehouse_name VARCHAR(160) NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            dealer_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dealer_order_items_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_order_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            method VARCHAR(80) NULL,
            reference VARCHAR(160) NULL,
            note TEXT NULL,
            paid_date DATE NULL,
            paid_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dealer_order_payments_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_order_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            action VARCHAR(60) NOT NULL,
            description TEXT NULL,
            created_by INT NULL,
            created_by_name VARCHAR(160) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dealer_order_logs_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach (['dealers', 'dealer_logs', 'dealer_orders', 'dealer_order_items', 'dealer_order_payments', 'dealer_order_logs'] as $table) {
        dealer_api_ensure_auto_increment($pdo, $table);
    }
}

function dealer_api_ensure_column(PDO $pdo, string $table, string $column, string $sql): void
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($sql);
        }
    } catch (Throwable $e) {
        error_log("dealer_orders schema {$table}.{$column}: " . $e->getMessage());
    }
}

function dealer_api_next_dealer_code(PDO $pdo, string $requested = ''): string
{
    $code = dealer_api_str($requested);
    if ($code !== '') {
        return $code;
    }
    $prefix = 'DL' . date('Ymd');
    $stmt = $pdo->prepare("SELECT dealer_code FROM dealers WHERE dealer_code LIKE ? ORDER BY dealer_code DESC LIMIT 1");
    try {
        $stmt->execute([$prefix . '%']);
        $last = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $last = '';
    }
    $next = 1;
    if (str_starts_with($last, $prefix)) {
        $seq = (int)substr($last, strlen($prefix));
        $next = max(1, $seq + 1);
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function dealer_api_next_order_code(PDO $pdo, string $orderDate): string
{
    $date = dealer_api_date($orderDate);
    $prefix = 'DO' . date('ymd', strtotime($date));
    $stmt = $pdo->prepare("SELECT order_code FROM dealer_orders WHERE order_code LIKE ? ORDER BY order_code DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;
    if (str_starts_with($last, $prefix)) {
        $seq = (int)substr($last, strlen($prefix));
        $next = max(1, $seq + 1);
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function dealer_api_fetch(PDO $pdo): array
{
    $dealers = $pdo->query("
        SELECT
            d.*,
            COALESCE(uc.name, uc.username, '') AS created_by_name,
            COALESCE(uu.name, uu.username, '') AS updated_by_name
        FROM dealers d
        LEFT JOIN users uc ON uc.id = d.created_by
        LEFT JOIN users uu ON uu.id = d.updated_by
        ORDER BY d.name ASC, d.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $orders = $pdo->query("
        SELECT
            o.*,
            COALESCE(uc.name, uc.username, o.created_by_name, '') AS created_by_name,
            COALESCE(uu.name, uu.username, o.updated_by_name, '') AS updated_by_name
        FROM (
            SELECT dealer_orders.*, '' AS created_by_name, '' AS updated_by_name FROM dealer_orders
        ) o
        LEFT JOIN users uc ON uc.id = o.created_by
        LEFT JOIN users uu ON uu.id = o.updated_by
        ORDER BY o.order_date DESC, o.created_at DESC, o.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $dealerIds = array_map(static fn(array $row): int => (int)$row['id'], $dealers);
    $logsByDealer = [];
    if ($dealerIds) {
        $placeholders = implode(',', array_fill(0, count($dealerIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM dealer_logs WHERE dealer_id IN ($placeholders) ORDER BY dealer_id ASC, created_at ASC, id ASC");
        $stmt->execute($dealerIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $logsByDealer[(int)$row['dealer_id']][] = [
                'id' => (int)$row['id'],
                'action' => (string)($row['action'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'created_by_name' => (string)($row['created_by_name'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
    }
    $dealers = array_map(static function (array $dealer) use ($logsByDealer): array {
        $id = (int)$dealer['id'];
        $dealer['logs'] = $logsByDealer[$id] ?? [];
        return $dealer;
    }, $dealers);

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $orders);
    $itemsByOrder = [];
    $paymentsByOrder = [];
    $logsByOrder = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM dealer_order_items WHERE order_id IN ($placeholders) ORDER BY order_id ASC, id ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemsByOrder[(int)$row['order_id']][] = [
                'id' => (int)$row['id'],
                'product_id' => (string)($row['product_id'] ?? ''),
                'movement_item_id' => (int)($row['movement_item_id'] ?? 0),
                'product_name' => (string)($row['product_name'] ?? ''),
                'warehouse_id' => (string)($row['warehouse_id'] ?? ''),
                'warehouse_name' => (string)($row['warehouse_name'] ?? ''),
                'quantity' => (float)$row['quantity'],
                'dealer_price' => (float)$row['dealer_price'],
                'discount' => (float)$row['discount'],
                'line_total' => (float)$row['line_total'],
            ];
        }

        $stmt = $pdo->prepare("
            SELECT p.*, COALESCE(u.name, u.username, '') AS paid_by_name
            FROM dealer_order_payments p
            LEFT JOIN users u ON u.id = p.paid_by
            WHERE p.order_id IN ($placeholders)
            ORDER BY p.order_id ASC, p.paid_date ASC, p.id ASC
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paymentsByOrder[(int)$row['order_id']][] = [
                'id' => (int)$row['id'],
                'amount' => (float)$row['amount'],
                'method' => (string)($row['method'] ?? ''),
                'reference' => (string)($row['reference'] ?? ''),
                'note' => (string)($row['note'] ?? ''),
                'paid_note' => (string)($row['note'] ?? ''),
                'paid_date' => (string)($row['paid_date'] ?? ''),
                'paid_by_name' => (string)($row['paid_by_name'] ?? ''),
            ];
        }

        $stmt = $pdo->prepare("SELECT * FROM dealer_order_logs WHERE order_id IN ($placeholders) ORDER BY order_id ASC, created_at ASC, id ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $logsByOrder[(int)$row['order_id']][] = [
                'id' => (int)$row['id'],
                'action' => (string)$row['action'],
                'description' => (string)($row['description'] ?? ''),
                'created_by_name' => (string)($row['created_by_name'] ?? ''),
                'created_at' => (string)$row['created_at'],
            ];
        }
    }

    $orders = array_map(static function (array $order) use ($itemsByOrder, $paymentsByOrder, $logsByOrder): array {
        $id = (int)$order['id'];
        $order['id'] = $id;
        $order['dealer_id'] = (string)($order['dealer_id'] ?? '');
        $order['default_warehouse_id'] = (string)($order['default_warehouse_id'] ?? '');
        $order['delivery_fee'] = (float)$order['delivery_fee'];
        $order['subtotal'] = (float)$order['subtotal'];
        $order['discount_total'] = (float)$order['discount_total'];
        $order['grand_total'] = (float)$order['grand_total'];
        $order['paid_amount'] = (float)$order['paid_amount'];
        $order['balance_amount'] = (float)$order['balance_amount'];
        $order['change_amount'] = (float)$order['change_amount'];
        $order['stock_deducted'] = (int)$order['stock_deducted'] === 1;
        $order['stock_reversed'] = (int)$order['stock_reversed'] === 1;
        $order['stock_documents'] = json_decode((string)($order['stock_documents'] ?? '[]'), true) ?: [];
        $order['lines'] = $itemsByOrder[$id] ?? [];
        $order['payments'] = $paymentsByOrder[$id] ?? [];
        $order['logs'] = $logsByOrder[$id] ?? [];
        return $order;
    }, $orders);

    return [
        'dealers' => $dealers,
        'orders' => $orders,
        'products' => dealer_api_products($pdo),
        'warehouses' => dealer_api_warehouses($pdo),
    ];
}

function dealer_api_products(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT
                p.id,
                p.id AS value,
                p.id AS movement_item_id,
                p.name,
                p.name AS label,
                p.sku,
                p.barcode,
                COALESCE(pc.selling_price, p.cost, 0) AS selling_price,
                COALESCE(pc.selling_price, p.cost, 0) AS dealer_price,
                COALESCE(p.cost, 0) AS cost
            FROM products p
            LEFT JOIN product_costs pc ON pc.product_id = p.id AND pc.month_year = DATE_FORMAT(CURDATE(), '%Y-%m')
            WHERE COALESCE(p.active, 1) = 1
              AND COALESCE(p.product_type, 'normal') != 'set'
            ORDER BY p.name
        ")->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query("
                SELECT
                    id, id AS value, id AS movement_item_id, name, name AS label, sku, barcode,
                    COALESCE(cost, 0) AS selling_price, COALESCE(cost, 0) AS dealer_price, COALESCE(cost, 0) AS cost
                FROM products
                WHERE COALESCE(active, 1) = 1
                ORDER BY name
            ")->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $inner) {
            return [];
        }
    }
}

function dealer_api_warehouses(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT
                id,
                id AS value,
                COALESCE(NULLIF(location_name, ''), location_code, CONCAT('Location #', id)) AS label,
                COALESCE(NULLIF(location_name, ''), location_code, CONCAT('Location #', id)) AS name
            FROM storage_locations
            WHERE is_active = 1
            ORDER BY location_code, location_name
        ")->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function dealer_api_save_dealer(PDO $pdo, array $payload, array $user): void
{
    require_role_or_permission(
        ['admin'],
        'sr_dealers.view',
        'sr_dealers.create',
        'sr_dealers.update',
        'reports_data.view'
    );
    $dealer = is_array($payload['dealer'] ?? null) ? $payload['dealer'] : $payload;
    $id = (int)($dealer['id'] ?? 0);
    $userId = (int)($user['id'] ?? 0) ?: null;
    $name = dealer_api_str($dealer['name'] ?? '');
    if ($name === '') {
        api_error('Dealer name is required.', 422);
    }
    $action = $id > 0 ? 'updated' : 'created';
    $userName = dealer_api_user_name($pdo, $userId) ?: dealer_api_str($user['name'] ?? $user['username'] ?? 'System Admin') ?: 'System Admin';
    $dealerCode = dealer_api_next_dealer_code($pdo, (string)($dealer['dealer_code'] ?? ''));
    $values = [
        'dealer_code' => $dealerCode !== '' ? $dealerCode : null,
        'name' => $name,
        'phone' => dealer_api_str($dealer['phone'] ?? ''),
        'contact_person' => dealer_api_str($dealer['contact_person'] ?? ''),
        'telegram' => dealer_api_str($dealer['telegram'] ?? ''),
        'address' => dealer_api_str($dealer['address'] ?? ''),
        'price_level' => dealer_api_str($dealer['price_level'] ?? 'Dealer') ?: 'Dealer',
        'credit_limit' => dealer_api_num($dealer['credit_limit'] ?? 0),
        'opening_balance' => dealer_api_num($dealer['opening_balance'] ?? 0),
        'payment_terms' => dealer_api_str($dealer['payment_terms'] ?? 'Cash') ?: 'Cash',
        'status' => dealer_api_str($dealer['status'] ?? 'active') ?: 'active',
        'notes' => dealer_api_str($dealer['notes'] ?? ''),
        'updated_by' => $userId,
    ];

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE dealers SET dealer_code=?, name=?, phone=?, contact_person=?, telegram=?, address=?, price_level=?, credit_limit=?, opening_balance=?, payment_terms=?, status=?, notes=?, updated_by=? WHERE id=?");
            $stmt->execute([
                $values['dealer_code'],
                $values['name'],
                $values['phone'],
                $values['contact_person'],
                $values['telegram'],
                $values['address'],
                $values['price_level'],
                $values['credit_limit'],
                $values['opening_balance'],
                $values['payment_terms'],
                $values['status'],
                $values['notes'],
                $userId,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO dealers (dealer_code, name, phone, contact_person, telegram, address, price_level, credit_limit, opening_balance, payment_terms, status, notes, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $values['dealer_code'],
                $values['name'],
                $values['phone'],
                $values['contact_person'],
                $values['telegram'],
                $values['address'],
                $values['price_level'],
                $values['credit_limit'],
                $values['opening_balance'],
                $values['payment_terms'],
                $values['status'],
                $values['notes'],
                $userId,
                $userId,
            ]);
            $id = (int)$pdo->lastInsertId();
        }
        $description = $action === 'created' ? "Dealer profile created for $name." : "Dealer profile updated for $name.";
        try {
            $stmt = $pdo->prepare("INSERT INTO dealer_logs (dealer_id, action, description, created_by, created_by_name) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $action, $description, $userId, $userName]);
        } catch (Throwable $e) {
            error_log('dealer_logs insert: ' . $e->getMessage());
        }
        api_json(['success' => true, 'message' => 'Dealer saved.', 'id' => $id, ...dealer_api_fetch($pdo)]);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        error_log('dealer_api_save_dealer: ' . $message);
        if (stripos($message, 'duplicate') !== false || (int)$e->getCode() === 23000) {
            api_error('Dealer code already exists. Leave code empty to auto-generate, or use a different code.', 422);
        }
        if (stripos($message, 'unknown column') !== false) {
            dealer_api_ensure_schema($pdo);
            api_error('Dealer table was missing columns. Refresh and try Save again.', 500);
        }
        api_error('Unable to save dealer: ' . $message, 500);
    }
}

function dealer_api_delete_dealer(PDO $pdo, array $payload): void
{
    require_role_or_permission(
        ['admin'],
        'sr_dealers.view',
        'sr_dealers.delete',
        'reports_data.view'
    );
    $id = (int)($payload['id'] ?? ($payload['dealer_id'] ?? 0));
    if ($id <= 0) {
        api_error('Dealer is required.', 422);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM dealer_orders WHERE dealer_id = ?');
    $stmt->execute([$id]);
    $orderCount = (int)$stmt->fetchColumn();
    if ($orderCount > 0) {
        api_error('Cannot delete dealer with existing orders. Set inactive instead.', 422);
    }

    $stmt = $pdo->prepare('DELETE FROM dealers WHERE id = ?');
    $stmt->execute([$id]);
    api_json(['success' => true, 'message' => 'Dealer deleted.', ...dealer_api_fetch($pdo)]);
}

function dealer_api_save_order(PDO $pdo, array $payload, array $user): void
{
    $order = is_array($payload['order'] ?? null) ? $payload['order'] : $payload;
    $id = (int)($order['id'] ?? 0);
    if ($id > 0) {
        require_role_or_permission(['admin'], 'sr_dealer_orders.update');
    } else {
        require_role_or_permission(['admin'], 'sr_dealer_orders.create');
    }
    $userId = (int)($user['id'] ?? 0) ?: null;
    $orderDate = dealer_api_date($order['order_date'] ?? null);
    $orderCode = dealer_api_str($order['order_code'] ?? '') ?: dealer_api_next_order_code($pdo, $orderDate);
    $lines = is_array($order['lines'] ?? null) ? $order['lines'] : [];
    if (!$lines) {
        api_error('Add at least one product.', 422);
    }
    if ($id <= 0 && $orderCode !== '') {
        try {
            $found = $pdo->prepare('SELECT id FROM dealer_orders WHERE order_code = ? LIMIT 1');
            $found->execute([$orderCode]);
            $existingId = (int)($found->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $id = $existingId;
            }
        } catch (Throwable $e) {
            // keep insert path
        }
    }

    $pdo->beginTransaction();
    try {
        $stockDocuments = json_encode(array_values(is_array($order['stock_documents'] ?? null) ? $order['stock_documents'] : []), JSON_UNESCAPED_SLASHES);
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE dealer_orders SET
                    order_code=?, dealer_id=?, dealer_name=?, phone=?, address=?, order_date=?, default_warehouse_id=?,
                    sales_person=?, payment_method=?, payment_reference=?, payment_note=?, payment_date=?,
                    delivery_method=?, delivery_fee=?, status=?, payment_status=?, subtotal=?, discount_total=?,
                    grand_total=?, paid_amount=?, balance_amount=?, change_amount=?, stock_deducted=?,
                    stock_deducted_at=?, stock_reference_id=?, stock_documents=?, stock_reversed=?, stock_reversed_at=?,
                    cancel_note=?, cancelled_at=?, updated_by=?
                WHERE id=?
            ");
            $stmt->execute([
                $orderCode, (int)($order['dealer_id'] ?? 0) ?: null, dealer_api_str($order['dealer_name'] ?? ''), dealer_api_str($order['phone'] ?? ''),
                dealer_api_str($order['address'] ?? ''), $orderDate, (int)($order['default_warehouse_id'] ?? 0) ?: null,
                dealer_api_str($order['sales_person'] ?? ''), dealer_api_str($order['payment_method'] ?? ''), dealer_api_str($order['payment_reference'] ?? ''),
                dealer_api_str($order['payment_note'] ?? ''), dealer_api_str($order['payment_date'] ?? '') ?: null,
                dealer_api_str($order['delivery_method'] ?? 'Pickup'), dealer_api_num($order['delivery_fee'] ?? 0),
                dealer_api_str($order['status'] ?? 'confirmed') ?: 'confirmed', dealer_api_str($order['payment_status'] ?? 'unpaid') ?: 'unpaid',
                dealer_api_num($order['subtotal'] ?? 0), dealer_api_num($order['discount_total'] ?? 0), dealer_api_num($order['grand_total'] ?? 0),
                dealer_api_num($order['paid_amount'] ?? 0), dealer_api_num($order['balance_amount'] ?? 0), dealer_api_num($order['change_amount'] ?? 0),
                !empty($order['stock_deducted']) ? 1 : 0, dealer_api_datetime($order['stock_deducted_at'] ?? ''),
                dealer_api_str($order['stock_reference_id'] ?? ''), $stockDocuments, !empty($order['stock_reversed']) ? 1 : 0,
                dealer_api_datetime($order['stock_reversed_at'] ?? ''), dealer_api_str($order['cancel_note'] ?? ''),
                dealer_api_datetime($order['cancelled_at'] ?? ''), $userId, $id,
            ]);
            $pdo->prepare("DELETE FROM dealer_order_items WHERE order_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM dealer_order_payments WHERE order_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM dealer_order_logs WHERE order_id=?")->execute([$id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO dealer_orders
                (order_code, dealer_id, dealer_name, phone, address, order_date, default_warehouse_id, sales_person,
                 payment_method, payment_reference, payment_note, payment_date, delivery_method, delivery_fee, status,
                 payment_status, subtotal, discount_total, grand_total, paid_amount, balance_amount, change_amount,
                 stock_deducted, stock_deducted_at, stock_reference_id, stock_documents, stock_reversed, stock_reversed_at,
                 cancel_note, cancelled_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderCode, (int)($order['dealer_id'] ?? 0) ?: null, dealer_api_str($order['dealer_name'] ?? ''), dealer_api_str($order['phone'] ?? ''),
                dealer_api_str($order['address'] ?? ''), $orderDate, (int)($order['default_warehouse_id'] ?? 0) ?: null,
                dealer_api_str($order['sales_person'] ?? ''), dealer_api_str($order['payment_method'] ?? ''), dealer_api_str($order['payment_reference'] ?? ''),
                dealer_api_str($order['payment_note'] ?? ''), dealer_api_str($order['payment_date'] ?? '') ?: null,
                dealer_api_str($order['delivery_method'] ?? 'Pickup'), dealer_api_num($order['delivery_fee'] ?? 0),
                dealer_api_str($order['status'] ?? 'confirmed') ?: 'confirmed', dealer_api_str($order['payment_status'] ?? 'unpaid') ?: 'unpaid',
                dealer_api_num($order['subtotal'] ?? 0), dealer_api_num($order['discount_total'] ?? 0), dealer_api_num($order['grand_total'] ?? 0),
                dealer_api_num($order['paid_amount'] ?? 0), dealer_api_num($order['balance_amount'] ?? 0), dealer_api_num($order['change_amount'] ?? 0),
                !empty($order['stock_deducted']) ? 1 : 0, dealer_api_datetime($order['stock_deducted_at'] ?? ''),
                dealer_api_str($order['stock_reference_id'] ?? ''), $stockDocuments, !empty($order['stock_reversed']) ? 1 : 0,
                dealer_api_datetime($order['stock_reversed_at'] ?? ''), dealer_api_str($order['cancel_note'] ?? ''),
                dealer_api_datetime($order['cancelled_at'] ?? ''), $userId, $userId,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        $itemStmt = $pdo->prepare("INSERT INTO dealer_order_items (order_id, product_id, movement_item_id, product_name, warehouse_id, warehouse_name, quantity, dealer_price, discount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($lines as $line) {
            $itemStmt->execute([
                $id,
                dealer_api_str($line['product_id'] ?? ''),
                (int)($line['movement_item_id'] ?? $line['item_id'] ?? $line['product_id'] ?? 0) ?: null,
                dealer_api_str($line['product_name'] ?? ''),
                (int)($line['warehouse_id'] ?? 0) ?: null,
                dealer_api_str($line['warehouse_name'] ?? ''),
                dealer_api_num($line['quantity'] ?? 0),
                dealer_api_num($line['dealer_price'] ?? 0),
                dealer_api_num($line['discount'] ?? 0),
                dealer_api_num($line['line_total'] ?? 0),
            ]);
        }

        $paymentStmt = $pdo->prepare("INSERT INTO dealer_order_payments (order_id, amount, method, reference, note, paid_date, paid_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ((array)($order['payments'] ?? []) as $payment) {
            if (dealer_api_num($payment['amount'] ?? 0) <= 0) {
                continue;
            }
            $paymentStmt->execute([
                $id,
                dealer_api_num($payment['amount'] ?? 0),
                dealer_api_str($payment['method'] ?? $payment['payment_method'] ?? ''),
                dealer_api_str($payment['reference'] ?? ''),
                dealer_api_str($payment['note'] ?? $payment['paid_note'] ?? ''),
                dealer_api_str($payment['paid_date'] ?? $payment['payment_date'] ?? '') ?: $orderDate,
                $userId,
            ]);
        }

        $logStmt = $pdo->prepare("INSERT INTO dealer_order_logs (order_id, action, description, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ((array)($order['logs'] ?? []) as $log) {
            $logStmt->execute([
                $id,
                dealer_api_str($log['action'] ?? 'updated') ?: 'updated',
                dealer_api_str($log['description'] ?? ''),
                $userId,
                dealer_api_str($log['created_by_name'] ?? dealer_api_user_name($pdo, $userId)),
                dealer_api_datetime($log['created_at'] ?? '') ?: date('Y-m-d H:i:s'),
            ]);
        }

        $pdo->commit();
        api_json(['success' => true, 'message' => 'Dealer order saved.', 'id' => $id, 'order_code' => $orderCode, ...dealer_api_fetch($pdo)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        api_error($e->getMessage(), 500);
    }
}

try {
    $pdo = get_db_connection();
    dealer_api_ensure_schema($pdo);
    $user = current_user() ?: [];
    $action = (string)($_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'save_order' : 'options'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_dealer') {
        dealer_api_save_dealer($pdo, dealer_api_input(), $user);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_dealer') {
        dealer_api_delete_dealer($pdo, dealer_api_input());
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_order') {
        dealer_api_save_order($pdo, dealer_api_input(), $user);
    }

    require_role_or_permission(
        ['admin'],
        'sr_sales_dashboard.view',
        'sr_dealers.view',
        'sr_dealer_orders.view',
        'sr_dealer_payments.view',
        'sr_dealer_reports.view',
        'reports_data.view'
    );
    api_json(['success' => true, ...dealer_api_fetch($pdo)]);
} catch (Throwable $e) {
    error_log('dealer_orders API error: ' . $e->getMessage());
    api_error('Unable to process dealer orders: ' . $e->getMessage(), 500);
}
