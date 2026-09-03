<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';

$pdo = get_db_connection();
$inventoryOpsIsCli = PHP_SAPI === 'cli';
$inventoryOpsPostPayload = null;
if (!$inventoryOpsIsCli) {
    $inventoryViewPerms = [
        'sr_inventory_movements.view',
        'sr_inventory_adjustment.view',
        'sr_inventory_transfer.view',
        'sr_inventory_delivery_notes.view',
        'inventory.view',
        'stock_operations.view',
        'stock_dashboard.view',
        'stock_reports.view',
    ];
    $dealerReadPerms = ['sr_dealer_orders.view', 'sr_dealer_orders.create', 'sr_dealer_orders.update'];
    $dealerWritePerms = ['sr_dealer_orders.create', 'sr_dealer_orders.update'];
    $methodEarly = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($methodEarly === 'POST') {
        $rawEarly = (string)file_get_contents('php://input');
        $decodedEarly = json_decode($rawEarly ?: '{}', true);
        $inventoryOpsPostPayload = is_array($decodedEarly) ? $decodedEarly : $_POST;
        $referenceType = strtolower(trim((string)($inventoryOpsPostPayload['reference_type'] ?? '')));
        $movementType = strtolower(trim((string)($inventoryOpsPostPayload['movement_type'] ?? '')));
        $isDealerStock = in_array($referenceType, ['dealer_order', 'dealer_order_reverse'], true)
            && in_array($movementType, ['in', 'out'], true);
        if ($isDealerStock) {
            require_role_or_permission(['admin'], ...$inventoryViewPerms, ...$dealerWritePerms);
        } else {
            require_role_or_permission(['admin'], ...$inventoryViewPerms);
        }
    } else {
        $getActionEarly = strtolower(trim((string)($_GET['action'] ?? '')));
        $isDealerCatalog = in_array($getActionEarly, ['', 'current_stock', 'location_stock', 'default_logo'], true);
        if ($isDealerCatalog) {
            require_role_or_permission(['admin'], ...$inventoryViewPerms, ...$dealerReadPerms);
        } else {
            require_role_or_permission(['admin'], ...$inventoryViewPerms);
        }
    }
}

function inventory_ops_user_label(): string
{
    $user = current_user();
    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }
    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : 'system';
}

function inventory_ops_ensure_settings_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(191) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function inventory_ops_get_setting(PDO $pdo, string $key, string $default = ''): string
{
    inventory_ops_ensure_settings_table($pdo);
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function inventory_ops_notification_module(string $movementType): ?array
{
    if ($movementType === 'transfer') {
        return [
            'prefix' => 'notification_stock_transfer_telegram',
            'label' => 'Stock Transfer',
            'event_key' => 'notify_create',
        ];
    }
    if (in_array($movementType, ['in', 'out', 'adjustment'], true)) {
        return [
            'prefix' => 'notification_stock_movement_telegram',
            'label' => 'Stock Movement',
            'event_key' => 'notify_create',
        ];
    }
    return null;
}

function inventory_ops_format_qty_for_message(mixed $value): string
{
    $number = inventory_api_num($value);
    return rtrim(rtrim(number_format($number, 2), '0'), '.');
}

function inventory_ops_notification_message(array $module, array $result, array $payload): string
{
    $movementType = strtolower(trim((string)($payload['movement_type'] ?? ($result['movement']['movement_type'] ?? ''))));
    $documentCode = (string)($result['document_code'] ?? $result['transfer_code'] ?? '');
    $lines = [
        $module['label'] . ' Created',
        'Type: ' . ($movementType !== '' ? ucfirst($movementType) : $module['label']),
    ];
    if ($documentCode !== '') {
        $lines[] = 'Document: ' . $documentCode;
    }
    if (isset($result['lines'])) {
        $lines[] = 'Lines: ' . (string)$result['lines'];
    }

    $movement = is_array($result['movement'] ?? null) ? $result['movement'] : null;
    if ($movement) {
        $lines[] = 'Product: ' . (string)($movement['product_name'] ?? '');
        $lines[] = 'Qty: ' . inventory_ops_format_qty_for_message($movement['quantity'] ?? 0);
    } elseif (!empty($result['movements']) && is_array($result['movements'])) {
        $names = [];
        $qtyTotal = 0.0;
        foreach ($result['movements'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (count($names) < 3 && !empty($row['product_name'])) {
                $names[] = (string)$row['product_name'];
            }
            $qtyTotal += abs(inventory_api_num($row['quantity'] ?? 0));
        }
        if ($names) {
            $lines[] = 'Products: ' . implode(', ', $names) . (count($result['movements']) > count($names) ? '...' : '');
        }
        $lines[] = 'Total Qty: ' . inventory_ops_format_qty_for_message($qtyTotal);
    }

    $createdBy = inventory_ops_user_label();
    if ($createdBy !== '') {
        $lines[] = 'Created By: ' . $createdBy;
    }
    $lines[] = 'Created At: ' . date('Y-m-d H:i:s');
    return implode("\n", array_filter($lines, static fn($line): bool => trim((string)$line) !== ''));
}

function inventory_ops_send_notification(PDO $pdo, array $result, array $payload): array
{
    $movementType = strtolower(trim((string)($payload['movement_type'] ?? ($result['movement']['movement_type'] ?? ''))));
    $module = inventory_ops_notification_module($movementType);
    if (!$module) {
        return ['ok' => false, 'skipped' => true, 'message' => 'No Telegram notification module for this stock action.'];
    }

    $prefix = $module['prefix'];
    $eventKey = $module['event_key'];
    $enabled = inventory_ops_get_setting($pdo, $prefix . '_enabled', '0') === '1';
    $notifyCreate = inventory_ops_get_setting($pdo, $prefix . '_' . $eventKey, '1') !== '0';
    if (!$enabled || !$notifyCreate) {
        return ['ok' => false, 'skipped' => true, 'message' => $module['label'] . ' Telegram notification is disabled.'];
    }

    global $TELEGRAM_BOT_TOKEN;
    $moduleToken = trim(inventory_ops_get_setting($pdo, $prefix . '_bot_token', ''));
    $botToken = $moduleToken !== '' ? $moduleToken : trim((string)($TELEGRAM_BOT_TOKEN ?? ''));
    $chatId = trim(inventory_ops_get_setting($pdo, $prefix . '_chat_id', ''));
    if ($botToken === '') {
        return ['ok' => false, 'message' => 'Telegram bot token is not configured.'];
    }
    if ($chatId === '') {
        return ['ok' => false, 'message' => 'Telegram chat ID is not configured.'];
    }

    $threadRaw = trim(inventory_ops_get_setting($pdo, $prefix . '_thread_id', ''));
    $threadId = $threadRaw !== '' ? (int)$threadRaw : null;
    $send = telegram_send_message_request(
        $botToken,
        $chatId,
        inventory_ops_notification_message($module, $result, $payload),
        $threadId
    );
    if (empty($send['ok'])) {
        return ['ok' => false, 'message' => 'Telegram send failed: ' . (string)($send['error'] ?? 'Unknown error')];
    }
    return ['ok' => true, 'message' => 'Telegram notification sent.'];
}

function inventory_ops_code_kind(string $movementType): string
{
    $type = strtolower(trim($movementType));
    return match ($type) {
        'transfer', 'transfer_out', 'transfer_in' => 'TRF',
        'out', 'sale_out' => 'OUT',
        'adjustment' => 'ADJ',
        default => 'IN',
    };
}

function inventory_ops_code_movement_types(string $kind): array
{
    return match (strtoupper($kind)) {
        'TRF' => ['transfer', 'transfer_out', 'transfer_in'],
        'OUT' => ['out', 'sale_out'],
        'ADJ' => ['adjustment'],
        default => ['in', 'purchase_in'],
    };
}

function inventory_ops_normalize_movement_type(string $movementType): string
{
    $type = strtolower(trim($movementType));
    return match ($type) {
        'out', 'sale_out' => 'out',
        'adjustment' => 'adjustment',
        'transfer', 'transfer_out', 'transfer_in' => 'transfer',
        default => 'in',
    };
}

function inventory_ops_next_document_code(PDO $pdo, string $movementType, ?string $forDate = null): string
{
    $kind = inventory_ops_code_kind($movementType);
    $stamp = $forDate ? date('ym', strtotime((string)$forDate)) : date('ym');
    if (!preg_match('/^\d{4}$/', $stamp)) {
        $stamp = date('ym');
    }
    $prefix = $kind . '-' . $stamp;
    $pattern = '/^' . preg_quote($kind, '/') . '-\d{4}(\d+)$/i';
    $types = inventory_ops_code_movement_types($kind);
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $max = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT reference_id
            FROM stock_movements
            WHERE movement_type IN ({$placeholders})
              AND reference_id LIKE ?
        ");
        $stmt->execute([...$types, $prefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (preg_match($pattern, (string)$code, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT reference_no
            FROM inventory_movements
            WHERE movement_type IN ({$placeholders})
              AND reference_no LIKE ?
        ");
        $stmt->execute([...$types, $prefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (preg_match($pattern, (string)$code, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

function inventory_ops_next_transfer_code(PDO $pdo, ?string $forDate = null): string
{
    return inventory_ops_next_document_code($pdo, 'transfer', $forDate);
}

function inventory_ops_next_adjustment_code(PDO $pdo, ?string $forDate = null): string
{
    $stamp = $forDate ? date('Ymd', strtotime((string)$forDate)) : date('Ymd');
    if (!preg_match('/^\d{8}$/', $stamp)) {
        $stamp = date('Ymd');
    }
    $prefix = 'ADJ-' . $stamp;
    $pattern = '/^ADJ-\d{8}(\d+)$/i';
    $max = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT reference_id
            FROM stock_movements
            WHERE movement_type = 'adjustment'
              AND reference_id LIKE ?
        ");
        $stmt->execute([$prefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (preg_match($pattern, (string)$code, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT reference_no
            FROM inventory_movements
            WHERE movement_type IN ('adjustment')
              AND reference_no LIKE ?
        ");
        $stmt->execute([$prefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (preg_match($pattern, (string)$code, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function inventory_ops_document_code(PDO $pdo, string $movementType, ?string $forDate = null): string
{
    if (inventory_ops_code_kind($movementType) === 'ADJ') {
        return inventory_ops_next_adjustment_code($pdo, $forDate);
    }
    return inventory_ops_next_document_code($pdo, $movementType, $forDate);
}

function inventory_ops_code_exists(PDO $pdo, string $code, array $types): bool
{
    $code = trim($code);
    if ($code === '' || !$types) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM stock_movements
            WHERE movement_type IN ({$placeholders})
              AND reference_id = ?
            LIMIT 1
        ");
        $stmt->execute([...$types, $code]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM inventory_movements
            WHERE movement_type IN ({$placeholders})
              AND reference_no = ?
            LIMIT 1
        ");
        $stmt->execute([...$types, $code]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return false;
}

function inventory_ops_is_external_stock_reference(string $referenceType, string $referenceId): bool
{
    $referenceType = strtolower(trim($referenceType));
    $referenceId = trim($referenceId);
    if ($referenceId === '') {
        return false;
    }
    if (in_array($referenceType, ['dealer_order', 'dealer_order_reverse'], true)) {
        return true;
    }
    return (bool)preg_match('/^DO\d+/i', $referenceId);
}

function inventory_ops_reference_already_posted(PDO $pdo, string $movementType, string $referenceId): bool
{
    $types = inventory_ops_code_movement_types(inventory_ops_code_kind($movementType));
    return inventory_ops_code_exists($pdo, $referenceId, $types);
}

function inventory_ops_acquire_reference_lock(PDO $pdo, string $key)
{
    $name = 'invref:' . substr(hash('sha256', $key), 0, 48);
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 20)');
        $stmt->execute([$name]);
        $got = $stmt->fetchColumn();
        if ($got === 1 || $got === '1') {
            return $name;
        }
        return false;
    } catch (Throwable $e) {
        return null;
    }
}

function inventory_ops_release_reference_lock(PDO $pdo, $name): void
{
    if (!is_string($name) || $name === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    } catch (Throwable $e) {
        // ignore
    }
}

function inventory_ops_guard_external_reference(PDO $pdo, string $movementType, string $referenceType, string $referenceId): array
{
    if (!inventory_ops_is_external_stock_reference($referenceType, $referenceId)) {
        return [
            'lock' => null,
            'reference_id' => inventory_ops_resolve_document_code($pdo, $movementType, $referenceId),
        ];
    }
    $lock = inventory_ops_acquire_reference_lock($pdo, strtolower($movementType) . ':' . $referenceId);
    if ($lock === false) {
        return [
            'error' => [
                'ok' => false,
                'success' => false,
                'message' => 'This dealer order is still saving. Wait, then try once.',
            ],
        ];
    }
    if (inventory_ops_reference_already_posted($pdo, $movementType, $referenceId)) {
        inventory_ops_release_reference_lock($pdo, $lock);
        return [
            'already' => [
                'ok' => true,
                'success' => true,
                'already_posted' => true,
                'message' => 'Stock movement already posted for this reference.',
                'document_code' => $referenceId,
                'lines' => 0,
                'movements' => [],
            ],
        ];
    }
    return ['lock' => $lock, 'reference_id' => $referenceId];
}

function inventory_ops_resolve_document_code(PDO $pdo, string $movementType, string $requested): string
{
    $requested = trim($requested);
    $types = inventory_ops_code_movement_types(inventory_ops_code_kind($movementType));
    if ($requested !== '' && !inventory_ops_code_exists($pdo, $requested, $types)) {
        return $requested;
    }
    return inventory_ops_next_document_code($pdo, $movementType);
}

function inventory_ops_has_real_transfer_code(?string $value): bool
{
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }
    if (in_array(strtolower($value), ['transfer', 'adjustment', 'in', 'out'], true)) {
        return false;
    }
    return (bool)preg_match('/^TRF-\d{4}\d+$/i', $value);
}

function inventory_ops_has_real_movement_code(?string $value, string $movementType = ''): bool
{
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }
    if (in_array(strtolower($value), ['transfer', 'adjustment', 'in', 'out'], true)) {
        return false;
    }
    $kind = $movementType !== '' ? inventory_ops_code_kind($movementType) : '(IN|OUT|ADJ)';
    if (strtoupper($kind) === 'ADJ' || $kind === '(IN|OUT|ADJ)') {
        if (preg_match('/^ADJ-(\d{8}\d{4}|\d{4}\d+)$/i', $value)) {
            return true;
        }
        if (strtoupper($kind) === 'ADJ') {
            return false;
        }
    }
    return (bool)preg_match('/^' . $kind . '-\d{4}\d+$/i', $value);
}

function inventory_ops_ensure_delivery_slip_history(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_delivery_slip_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slip_code VARCHAR(40) NOT NULL UNIQUE,
            receiver_name VARCHAR(255) NOT NULL,
            receiver_phone VARCHAR(80) NOT NULL,
            transfer_to VARCHAR(255) NULL,
            delivery_date DATE NULL,
            note TEXT NULL,
            slip_title VARCHAR(120) NOT NULL,
            movement_type_label VARCHAR(120) NOT NULL,
            location_label VARCHAR(255) NOT NULL,
            filter_label VARCHAR(120) NULL,
            item_count INT NOT NULL DEFAULT 0,
            total_qty DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_in DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_out DECIMAL(15,2) NOT NULL DEFAULT 0,
            movement_ids TEXT NULL,
            items_json LONGTEXT NULL,
            qr_payload LONGTEXT NULL,
            created_by_user_id INT NULL,
            created_by_name VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_receiver_phone (receiver_phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_delivery_slip_history LIKE 'delivery_date'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE stock_delivery_slip_history ADD delivery_date DATE NULL AFTER transfer_to');
        }
    } catch (Throwable $e) {
        // keep older schemas working
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_delivery_slip_history LIKE 'note'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE stock_delivery_slip_history ADD note TEXT NULL AFTER delivery_date');
        }
    } catch (Throwable $e) {
        // keep older schemas working
    }
}

function inventory_ops_parse_delivery_date(mixed $value, bool $required = false): array
{
    $text = inventory_api_str($value);
    if ($text === '') {
        if ($required) {
            return ['ok' => false, 'date' => null, 'message' => 'Delivery date is required.'];
        }
        return ['ok' => true, 'date' => date('Y-m-d')];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return ['ok' => false, 'date' => null, 'message' => 'Delivery date is invalid.'];
        }
        return ['ok' => true, 'date' => "{$m[1]}-{$m[2]}-{$m[3]}"];
    }
    $ts = strtotime($text);
    if ($ts === false) {
        return ['ok' => false, 'date' => null, 'message' => 'Delivery date is invalid.'];
    }
    return ['ok' => true, 'date' => date('Y-m-d', $ts)];
}

function inventory_ops_delivery_slip_code(PDO $pdo): string
{
    inventory_ops_ensure_delivery_slip_history($pdo);
    $prefix = 'DSL-' . date('ymd');
    $max = 0;
    $stmt = $pdo->prepare("
        SELECT slip_code
        FROM stock_delivery_slip_history
        WHERE slip_code LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/^DSL-\d{6}(\d+)$/', (string)$code, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

function inventory_ops_delivery_slip_note(array $row, array $items): string
{
    $note = inventory_api_str($row['note'] ?? '');
    if ($note !== '') {
        return $note;
    }
    $found = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemNote = inventory_api_str($item['note'] ?? '');
        if ($itemNote !== '' && !in_array($itemNote, $found, true)) {
            $found[] = $itemNote;
        }
    }
    return implode(' · ', $found);
}

function inventory_ops_delivery_slip_payload(array $row): array
{
    $items = json_decode((string)($row['items_json'] ?? '[]'), true);
    if (!is_array($items)) {
        $items = [];
    }
    return [
        'id' => (int)($row['id'] ?? 0),
        'slip_code' => inventory_api_str($row['slip_code'] ?? ''),
        'receiver_name' => inventory_api_str($row['receiver_name'] ?? ''),
        'receiver_phone' => inventory_api_str($row['receiver_phone'] ?? ''),
        'transfer_to' => inventory_api_str($row['transfer_to'] ?? ''),
        'delivery_date' => inventory_api_str($row['delivery_date'] ?? ''),
        'note' => inventory_ops_delivery_slip_note($row, $items),
        'slip_title' => inventory_api_str($row['slip_title'] ?? ''),
        'movement_type_label' => inventory_api_str($row['movement_type_label'] ?? ''),
        'location_label' => inventory_api_str($row['location_label'] ?? ''),
        'filter_label' => inventory_api_str($row['filter_label'] ?? ''),
        'item_count' => (int)($row['item_count'] ?? 0),
        'total_qty' => (float)($row['total_qty'] ?? 0),
        'total_in' => (float)($row['total_in'] ?? 0),
        'total_out' => (float)($row['total_out'] ?? 0),
        'movement_ids' => json_decode((string)($row['movement_ids'] ?? '[]'), true) ?: [],
        'items' => $items,
        'qr_payload' => inventory_api_str($row['qr_payload'] ?? ''),
        'created_at' => inventory_api_str($row['created_at'] ?? ''),
        'created_by_name' => inventory_api_str($row['created_by_name'] ?? ''),
    ];
}

function inventory_ops_default_logo_url(PDO $pdo): string
{
    $fallback = rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/') . '/public/image.png';
    try {
        if (!function_exists('get_default_logo') || !function_exists('uploaded_file_url')) {
            return $fallback;
        }
        $logo = get_default_logo($pdo);
        if (!$logo || empty($logo['file_path'])) {
            return $fallback;
        }
        $url = trim((string)uploaded_file_url((string)$logo['file_path'], 'logos'));
        return $url !== '' ? $url : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function inventory_ops_company_profile(PDO $pdo): array
{
    $profile = [
        'logo_url' => inventory_ops_default_logo_url($pdo),
        'company_name' => 'Shadow Group Co., Ltd.',
        'company_phone' => '',
        'company_email' => '',
    ];
    try {
        if (!function_exists('get_invoice_settings')) {
            return $profile;
        }
        $settings = get_invoice_settings($pdo);
        $name = trim((string)($settings['company_name'] ?? ''));
        if ($name !== '' && strcasecmp($name, 'My Company') !== 0) {
            $profile['company_name'] = $name;
        }
        $profile['company_phone'] = trim((string)($settings['company_phone'] ?? ''));
        $profile['company_email'] = trim((string)($settings['company_email'] ?? ''));
    } catch (Throwable $e) {
        // keep defaults
    }
    return $profile;
}

function inventory_ops_delivery_slip_history(PDO $pdo, array $payload = []): array
{
    inventory_ops_ensure_delivery_slip_history($pdo);
    $from = inventory_api_str($payload['from'] ?? '');
    $to = inventory_api_str($payload['to'] ?? '');
    $sql = '
        SELECT *
        FROM stock_delivery_slip_history
        WHERE 1 = 1
    ';
    $params = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND COALESCE(delivery_date, DATE(created_at)) >= ?';
        $params[] = $from;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND COALESCE(delivery_date, DATE(created_at)) <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY COALESCE(delivery_date, DATE(created_at)) DESC, id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('inventory_ops_delivery_slip_payload', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function inventory_ops_save_delivery_slip_history(PDO $pdo, array $payload): array
{
    inventory_ops_ensure_delivery_slip_history($pdo);

    $receiverName = inventory_api_str($payload['receiver_name'] ?? '');
    $receiverPhone = inventory_api_str($payload['receiver_phone'] ?? '');
    $transferTo = inventory_api_str($payload['transfer_to'] ?? '');
    $deliveryDateParsed = inventory_ops_parse_delivery_date($payload['delivery_date'] ?? '');
    if (empty($deliveryDateParsed['ok'])) {
        return ['ok' => false, 'success' => false, 'message' => $deliveryDateParsed['message'] ?? 'Delivery date is invalid.'];
    }
    $deliveryDate = $deliveryDateParsed['date'];
    $note = inventory_api_str($payload['note'] ?? ($payload['notes'] ?? ''));
    $slipTitle = inventory_api_str($payload['slip_title'] ?? 'DELIVERY NOTE');
    $movementTypeLabel = inventory_api_str($payload['movement_type_label'] ?? 'Transfer');
    $locationLabel = inventory_api_str($payload['location_label'] ?? 'Mixed Locations');
    $filterLabel = inventory_api_str($payload['filter_label'] ?? '');
    $movementIds = is_array($payload['movement_ids'] ?? null) ? $payload['movement_ids'] : [];
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $qrPayload = inventory_api_str($payload['qr_payload'] ?? '');
    $itemCount = max(0, (int)($payload['item_count'] ?? count($items)));
    $totalQty = inventory_api_num($payload['total_qty'] ?? 0);
    $totalIn = inventory_api_num($payload['total_in'] ?? 0);
    $totalOut = inventory_api_num($payload['total_out'] ?? $totalQty);

    if ($receiverName === '' || $receiverPhone === '') {
        return ['ok' => false, 'success' => false, 'message' => 'Name and phone number are required.'];
    }

    $cleanMovementIds = [];
    foreach ($movementIds as $id) {
        if (is_string($id) && preg_match('/(\d+)$/', $id, $m)) {
            $id = (int)$m[1];
        } else {
            $id = (int)$id;
        }
        if ($id > 0) {
            $cleanMovementIds[] = $id;
        }
    }
    $cleanMovementIds = array_values(array_unique($cleanMovementIds));
    if (!$cleanMovementIds) {
        return ['ok' => false, 'success' => false, 'message' => 'Please select at least one stock movement.'];
    }

    $slipCode = inventory_ops_delivery_slip_code($pdo);
    // QR encodes the delivery slip code only (same as stock operations reprint).
    if ($qrPayload === '' || stripos($qrPayload, 'Slip: pending') !== false) {
        $qrPayload = $slipCode;
    } else {
        $qrPayload = str_replace('Slip: pending', 'Slip: ' . $slipCode, $qrPayload);
    }
    $currentUser = current_user();
    $userId = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
    $createdByName = inventory_api_str($currentUser['name'] ?? '') ?: inventory_ops_user_label();

    $stmt = $pdo->prepare("
        INSERT INTO stock_delivery_slip_history
        (slip_code, receiver_name, receiver_phone, transfer_to, delivery_date, note, slip_title, movement_type_label, location_label, filter_label,
         item_count, total_qty, total_in, total_out, movement_ids, items_json, qr_payload, created_by_user_id, created_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $slipCode,
        $receiverName,
        $receiverPhone,
        $transferTo !== '' ? $transferTo : null,
        $deliveryDate,
        $note !== '' ? $note : null,
        $slipTitle !== '' ? $slipTitle : 'DELIVERY NOTE',
        $movementTypeLabel !== '' ? $movementTypeLabel : 'Transfer',
        $locationLabel !== '' ? $locationLabel : 'Mixed Locations',
        $filterLabel !== '' ? $filterLabel : null,
        $itemCount,
        $totalQty,
        $totalIn,
        $totalOut,
        json_encode($cleanMovementIds),
        json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $qrPayload,
        $userId,
        $createdByName,
    ]);

    return [
        'ok' => true,
        'success' => true,
        'slip_code' => $slipCode,
        'created_at' => date('Y-m-d H:i:s'),
        'delivery_slip' => [
            'slip_code' => $slipCode,
            'receiver_name' => $receiverName,
            'receiver_phone' => $receiverPhone,
            'transfer_to' => $transferTo,
            'delivery_date' => $deliveryDate,
            'note' => $note,
            'slip_title' => $slipTitle !== '' ? $slipTitle : 'DELIVERY NOTE',
            'movement_type_label' => $movementTypeLabel !== '' ? $movementTypeLabel : 'Transfer',
            'location_label' => $locationLabel !== '' ? $locationLabel : 'Mixed Locations',
            'filter_label' => $filterLabel,
            'item_count' => $itemCount,
            'total_qty' => $totalQty,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'movement_ids' => $cleanMovementIds,
            'items' => array_values($items),
            'qr_payload' => $qrPayload,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by_name' => $createdByName,
        ],
    ];
}

function inventory_ops_update_delivery_slip_history(PDO $pdo, array $payload): array
{
    inventory_ops_ensure_delivery_slip_history($pdo);

    $slipCode = inventory_api_str($payload['slip_code'] ?? '');
    $receiverName = inventory_api_str($payload['receiver_name'] ?? '');
    $receiverPhone = inventory_api_str($payload['receiver_phone'] ?? '');
    $transferTo = inventory_api_str($payload['transfer_to'] ?? '');
    $deliveryDateParsed = inventory_ops_parse_delivery_date($payload['delivery_date'] ?? '', true);
    if (empty($deliveryDateParsed['ok'])) {
        return ['ok' => false, 'success' => false, 'message' => $deliveryDateParsed['message'] ?? 'Delivery date is required.'];
    }
    $deliveryDate = $deliveryDateParsed['date'];
    $note = inventory_api_str($payload['note'] ?? ($payload['notes'] ?? ''));
    $qrPayload = inventory_api_str($payload['qr_payload'] ?? '');

    if ($slipCode === '') {
        return ['ok' => false, 'success' => false, 'message' => 'Delivery slip code is required.'];
    }
    if ($receiverName === '' || $receiverPhone === '') {
        return ['ok' => false, 'success' => false, 'message' => 'Name and phone number are required.'];
    }
    if ($transferTo === '') {
        return ['ok' => false, 'success' => false, 'message' => 'Address is required.'];
    }

    $find = $pdo->prepare('SELECT * FROM stock_delivery_slip_history WHERE slip_code = ? LIMIT 1');
    $find->execute([$slipCode]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return ['ok' => false, 'success' => false, 'message' => 'Delivery slip not found.'];
    }

    if ($qrPayload === '') {
        $qrPayload = "Slip: {$slipCode}\nReceiver: {$receiverName}\nPhone: {$receiverPhone}\nAddress: {$transferTo}";
    }

    $stmt = $pdo->prepare("
        UPDATE stock_delivery_slip_history
        SET receiver_name = ?, receiver_phone = ?, transfer_to = ?, delivery_date = ?, note = ?, qr_payload = ?
        WHERE slip_code = ?
    ");
    $stmt->execute([
        $receiverName,
        $receiverPhone,
        $transferTo,
        $deliveryDate,
        $note !== '' ? $note : null,
        $qrPayload,
        $slipCode,
    ]);

    $find->execute([$slipCode]);
    $updated = $find->fetch(PDO::FETCH_ASSOC) ?: $existing;
    $updated['receiver_name'] = $receiverName;
    $updated['receiver_phone'] = $receiverPhone;
    $updated['transfer_to'] = $transferTo;
    $updated['delivery_date'] = $deliveryDate;
    $updated['note'] = $note;
    $updated['qr_payload'] = $qrPayload;

    return [
        'ok' => true,
        'success' => true,
        'slip_code' => $slipCode,
        'delivery_slip' => inventory_ops_delivery_slip_payload($updated),
        'message' => 'Delivery note updated.',
    ];
}

function inventory_ops_delete_delivery_slip_history(PDO $pdo, array $payload): array
{
    inventory_ops_ensure_delivery_slip_history($pdo);

    $slipCode = inventory_api_str($payload['slip_code'] ?? '');
    if ($slipCode === '') {
        return ['ok' => false, 'success' => false, 'message' => 'Delivery slip code is required.'];
    }

    $find = $pdo->prepare('SELECT id FROM stock_delivery_slip_history WHERE slip_code = ? LIMIT 1');
    $find->execute([$slipCode]);
    if (!$find->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'success' => false, 'message' => 'Delivery slip not found.'];
    }

    $stmt = $pdo->prepare('DELETE FROM stock_delivery_slip_history WHERE slip_code = ?');
    $stmt->execute([$slipCode]);

    return [
        'ok' => true,
        'success' => true,
        'slip_code' => $slipCode,
        'message' => 'Delivery note deleted.',
    ];
}

function inventory_ops_datetime_group_key(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'unknown';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('Y-m-d H:i', $ts);
}

function inventory_ops_pick_group_transfer_code(PDO $pdo, array $bucket): string
{
    $codes = $bucket['codes'] ?? [];
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static function (string $code): bool {
        return inventory_ops_has_real_transfer_code($code);
    })));
    sort($codes, SORT_STRING);
    if ($codes) {
        return $codes[0];
    }
    return inventory_ops_next_transfer_code($pdo, $bucket['for_date'] ?? null);
}

function inventory_ops_apply_transfer_code_buckets(PDO $pdo, array $buckets, string $table, string $codeColumn): array
{
    $updated = 0;
    $groups = 0;
    $unchanged = 0;
    $stmt = $pdo->prepare("UPDATE {$table} SET {$codeColumn} = ? WHERE id = ?");

    foreach ($buckets as $bucket) {
        $ids = $bucket['ids'] ?? [];
        if (!$ids) {
            continue;
        }
        $code = inventory_ops_pick_group_transfer_code($pdo, $bucket);
        $currentById = $bucket['current_by_id'] ?? [];
        $changedInGroup = 0;
        foreach ($ids as $id) {
            $current = inventory_api_str($currentById[$id] ?? '');
            if (strcasecmp($current, $code) === 0) {
                $unchanged++;
                continue;
            }
            $stmt->execute([$code, $id]);
            $updated++;
            $changedInGroup++;
        }
        if ($changedInGroup > 0) {
            $groups++;
        }
    }

    return ['updated' => $updated, 'groups' => $groups, 'unchanged' => $unchanged];
}

function inventory_ops_backfill_transfer_codes(PDO $pdo): array
{
    $updated = 0;
    $groups = 0;
    $unchanged = 0;

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN);
        $colsLc = array_map('strtolower', array_map('strval', $cols));
        $dateCol = in_array('created_at', $colsLc, true) ? 'created_at' : (in_array('movement_date', $colsLc, true) ? 'movement_date' : 'id');
        $hasRefId = in_array('reference_id', $colsLc, true);
        $hasFrom = in_array('from_storage_location_id', $colsLc, true);
        $hasTo = in_array('to_storage_location_id', $colsLc, true);
        if (!$hasRefId) {
            return ['ok' => false, 'message' => 'stock_movements.reference_id column not found.', 'updated' => 0];
        }

        $selectExtra = '';
        if ($hasFrom) {
            $selectExtra .= ', from_storage_location_id';
        }
        if ($hasTo) {
            $selectExtra .= ', to_storage_location_id';
        }

        $sql = "
            SELECT id, reference_id, {$dateCol} AS move_date {$selectExtra}
            FROM stock_movements
            WHERE movement_type IN ('transfer', 'transfer_out')
            ORDER BY {$dateCol} ASC, id ASC
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $current = inventory_api_str($row['reference_id'] ?? '');
            $timeKey = inventory_ops_datetime_group_key((string)($row['move_date'] ?? ''));
            $fromKey = $hasFrom ? (string)(int)($row['from_storage_location_id'] ?? 0) : '0';
            $toKey = $hasTo ? (string)(int)($row['to_storage_location_id'] ?? 0) : '0';
            $groupKey = $timeKey . '|' . $fromKey . '|' . $toKey;
            if (!isset($buckets[$groupKey])) {
                $buckets[$groupKey] = [
                    'for_date' => inventory_api_str($row['move_date'] ?? '') ?: date('Y-m-d'),
                    'ids' => [],
                    'codes' => [],
                    'current_by_id' => [],
                ];
            }
            $buckets[$groupKey]['ids'][] = $id;
            $buckets[$groupKey]['current_by_id'][$id] = $current;
            if (inventory_ops_has_real_transfer_code($current)) {
                $buckets[$groupKey]['codes'][] = $current;
            }
        }

        $result = inventory_ops_apply_transfer_code_buckets($pdo, $buckets, 'stock_movements', 'reference_id');
        $updated += $result['updated'];
        $groups += $result['groups'];
        $unchanged += $result['unchanged'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'updated' => $updated, 'groups' => $groups, 'unchanged' => $unchanged];
    }

    try {
        $sql = "
            SELECT id, reference_no, movement_date, created_at, from_location_id, to_location_id
            FROM inventory_movements
            WHERE movement_type = 'transfer_out'
            ORDER BY COALESCE(created_at, movement_date) ASC, id ASC
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $buckets = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $current = inventory_api_str($row['reference_no'] ?? '');
            $moveAt = inventory_api_str($row['created_at'] ?? '') ?: inventory_api_str($row['movement_date'] ?? '');
            $timeKey = inventory_ops_datetime_group_key($moveAt);
            $groupKey = $timeKey . '|' . (int)($row['from_location_id'] ?? 0) . '|' . (int)($row['to_location_id'] ?? 0);
            if (!isset($buckets[$groupKey])) {
                $buckets[$groupKey] = [
                    'for_date' => $moveAt !== '' ? $moveAt : date('Y-m-d'),
                    'ids' => [],
                    'codes' => [],
                    'current_by_id' => [],
                ];
            }
            $buckets[$groupKey]['ids'][] = $id;
            $buckets[$groupKey]['current_by_id'][$id] = $current;
            if (inventory_ops_has_real_transfer_code($current)) {
                $buckets[$groupKey]['codes'][] = $current;
            }
        }
        $result = inventory_ops_apply_transfer_code_buckets($pdo, $buckets, 'inventory_movements', 'reference_no');
        $updated += $result['updated'];
        $groups += $result['groups'];
        $unchanged += $result['unchanged'];
    } catch (Throwable $e) {
        // optional / schema differences (created_at may be missing)
        try {
            $sql = "
                SELECT id, reference_no, movement_date, from_location_id, to_location_id
                FROM inventory_movements
                WHERE movement_type = 'transfer_out'
                ORDER BY movement_date ASC, id ASC
            ";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $buckets = [];
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $current = inventory_api_str($row['reference_no'] ?? '');
                $moveAt = inventory_api_str($row['movement_date'] ?? '');
                $timeKey = inventory_ops_datetime_group_key($moveAt);
                $groupKey = $timeKey . '|' . (int)($row['from_location_id'] ?? 0) . '|' . (int)($row['to_location_id'] ?? 0);
                if (!isset($buckets[$groupKey])) {
                    $buckets[$groupKey] = [
                        'for_date' => $moveAt !== '' ? $moveAt : date('Y-m-d'),
                        'ids' => [],
                        'codes' => [],
                        'current_by_id' => [],
                    ];
                }
                $buckets[$groupKey]['ids'][] = $id;
                $buckets[$groupKey]['current_by_id'][$id] = $current;
                if (inventory_ops_has_real_transfer_code($current)) {
                    $buckets[$groupKey]['codes'][] = $current;
                }
            }
            $result = inventory_ops_apply_transfer_code_buckets($pdo, $buckets, 'inventory_movements', 'reference_no');
            $updated += $result['updated'];
            $groups += $result['groups'];
            $unchanged += $result['unchanged'];
        } catch (Throwable $inner) {
            // optional table
        }
    }

    return [
        'ok' => true,
        'success' => true,
        'updated' => $updated,
        'groups' => $groups,
        'unchanged' => $unchanged,
        'message' => $updated > 0
            ? ("Updated {$updated} row(s) across {$groups} group(s). Same date/time (+ from/to) now share one code.")
            : 'All transfer groups already share matching codes.',
    ];
}

function inventory_ops_apply_movement_code_buckets(PDO $pdo, array $buckets, string $table, string $codeColumn): array
{
    $updated = 0;
    $groups = 0;
    $unchanged = 0;
    $assigned = [];
    $stmt = $pdo->prepare("UPDATE {$table} SET {$codeColumn} = ? WHERE id = ?");

    foreach ($buckets as $bucket) {
        $ids = $bucket['ids'] ?? [];
        if (!$ids) {
            continue;
        }
        $type = inventory_ops_normalize_movement_type((string)($bucket['movement_type'] ?? 'in'));
        $codes = $bucket['codes'] ?? [];
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes), static function (string $code) use ($type): bool {
            return inventory_ops_has_real_movement_code($code, $type);
        })));
        sort($codes, SORT_STRING);

        $code = '';
        foreach ($codes as $candidate) {
            $key = strtoupper($candidate);
            if (!isset($assigned[$key])) {
                $code = $candidate;
                break;
            }
        }
        if ($code === '') {
            $code = inventory_ops_next_document_code($pdo, $type, $bucket['for_date'] ?? null);
        }
        $assigned[strtoupper($code)] = true;

        $currentById = $bucket['current_by_id'] ?? [];
        $changedInGroup = 0;
        foreach ($ids as $id) {
            $current = inventory_api_str($currentById[$id] ?? '');
            if (strcasecmp($current, $code) === 0) {
                $unchanged++;
                continue;
            }
            $stmt->execute([$code, $id]);
            $updated++;
            $changedInGroup++;
        }
        if ($changedInGroup > 0) {
            $groups++;
        }
    }

    return ['updated' => $updated, 'groups' => $groups, 'unchanged' => $unchanged];
}

function inventory_ops_backfill_movement_codes(PDO $pdo): array
{
    $updated = 0;
    $groups = 0;
    $unchanged = 0;

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN);
        $colsLc = array_map('strtolower', array_map('strval', $cols));
        $dateCol = in_array('created_at', $colsLc, true) ? 'created_at' : (in_array('movement_date', $colsLc, true) ? 'movement_date' : 'id');
        $hasRefId = in_array('reference_id', $colsLc, true);
        $hasFrom = in_array('from_storage_location_id', $colsLc, true);
        $hasTo = in_array('to_storage_location_id', $colsLc, true);
        $hasCreatedBy = in_array('created_by', $colsLc, true);
        if (!$hasRefId) {
            return ['ok' => false, 'message' => 'stock_movements.reference_id column not found.', 'updated' => 0];
        }

        $selectExtra = '';
        if ($hasFrom) {
            $selectExtra .= ', from_storage_location_id';
        }
        if ($hasTo) {
            $selectExtra .= ', to_storage_location_id';
        }
        if ($hasCreatedBy) {
            $selectExtra .= ', created_by';
        }

        $sql = "
            SELECT id, movement_type, reference_id, {$dateCol} AS move_date {$selectExtra}
            FROM stock_movements
            WHERE movement_type IN ('in', 'out', 'adjustment')
            ORDER BY {$dateCol} ASC, id ASC
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $type = inventory_ops_normalize_movement_type((string)($row['movement_type'] ?? 'in'));
            $current = inventory_api_str($row['reference_id'] ?? '');
            $timeKey = inventory_ops_datetime_group_key((string)($row['move_date'] ?? ''));
            $fromKey = $hasFrom ? (string)(int)($row['from_storage_location_id'] ?? 0) : '0';
            $toKey = $hasTo ? (string)(int)($row['to_storage_location_id'] ?? 0) : '0';
            $userKey = $hasCreatedBy ? inventory_api_str($row['created_by'] ?? '') : '';
            $groupKey = $type . '|' . $timeKey . '|' . $fromKey . '|' . $toKey . '|' . $userKey;
            if (!isset($buckets[$groupKey])) {
                $buckets[$groupKey] = [
                    'movement_type' => $type,
                    'for_date' => inventory_api_str($row['move_date'] ?? '') ?: date('Y-m-d'),
                    'ids' => [],
                    'codes' => [],
                    'current_by_id' => [],
                ];
            }
            $buckets[$groupKey]['ids'][] = $id;
            $buckets[$groupKey]['current_by_id'][$id] = $current;
            if (inventory_ops_has_real_movement_code($current, $type)) {
                $buckets[$groupKey]['codes'][] = $current;
            }
        }

        $result = inventory_ops_apply_movement_code_buckets($pdo, $buckets, 'stock_movements', 'reference_id');
        $updated += $result['updated'];
        $groups += $result['groups'];
        $unchanged += $result['unchanged'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'updated' => $updated, 'groups' => $groups, 'unchanged' => $unchanged];
    }

    try {
        $sql = "
            SELECT id, movement_type, reference_no, movement_date, created_at, from_location_id, to_location_id
            FROM inventory_movements
            WHERE movement_type IN ('in', 'out', 'adjustment', 'purchase_in', 'sale_out')
            ORDER BY COALESCE(created_at, movement_date) ASC, id ASC
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $buckets = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $type = inventory_ops_normalize_movement_type((string)($row['movement_type'] ?? 'in'));
            $current = inventory_api_str($row['reference_no'] ?? '');
            $moveAt = inventory_api_str($row['created_at'] ?? '') ?: inventory_api_str($row['movement_date'] ?? '');
            $timeKey = inventory_ops_datetime_group_key($moveAt);
            $groupKey = $type . '|' . $timeKey . '|' . (int)($row['from_location_id'] ?? 0) . '|' . (int)($row['to_location_id'] ?? 0);
            if (!isset($buckets[$groupKey])) {
                $buckets[$groupKey] = [
                    'movement_type' => $type,
                    'for_date' => $moveAt !== '' ? $moveAt : date('Y-m-d'),
                    'ids' => [],
                    'codes' => [],
                    'current_by_id' => [],
                ];
            }
            $buckets[$groupKey]['ids'][] = $id;
            $buckets[$groupKey]['current_by_id'][$id] = $current;
            if (inventory_ops_has_real_movement_code($current, $type)) {
                $buckets[$groupKey]['codes'][] = $current;
            }
        }
        $result = inventory_ops_apply_movement_code_buckets($pdo, $buckets, 'inventory_movements', 'reference_no');
        $updated += $result['updated'];
        $groups += $result['groups'];
        $unchanged += $result['unchanged'];
    } catch (Throwable $e) {
        try {
            $sql = "
                SELECT id, movement_type, reference_no, movement_date, from_location_id, to_location_id
                FROM inventory_movements
                WHERE movement_type IN ('in', 'out', 'adjustment', 'purchase_in', 'sale_out')
                ORDER BY movement_date ASC, id ASC
            ";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $buckets = [];
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $type = inventory_ops_normalize_movement_type((string)($row['movement_type'] ?? 'in'));
                $current = inventory_api_str($row['reference_no'] ?? '');
                $moveAt = inventory_api_str($row['movement_date'] ?? '');
                $timeKey = inventory_ops_datetime_group_key($moveAt);
                $groupKey = $type . '|' . $timeKey . '|' . (int)($row['from_location_id'] ?? 0) . '|' . (int)($row['to_location_id'] ?? 0);
                if (!isset($buckets[$groupKey])) {
                    $buckets[$groupKey] = [
                        'movement_type' => $type,
                        'for_date' => $moveAt !== '' ? $moveAt : date('Y-m-d'),
                        'ids' => [],
                        'codes' => [],
                        'current_by_id' => [],
                    ];
                }
                $buckets[$groupKey]['ids'][] = $id;
                $buckets[$groupKey]['current_by_id'][$id] = $current;
                if (inventory_ops_has_real_movement_code($current, $type)) {
                    $buckets[$groupKey]['codes'][] = $current;
                }
            }
            $result = inventory_ops_apply_movement_code_buckets($pdo, $buckets, 'inventory_movements', 'reference_no');
            $updated += $result['updated'];
            $groups += $result['groups'];
            $unchanged += $result['unchanged'];
        } catch (Throwable $inner) {
            // optional table
        }
    }

    return [
        'ok' => true,
        'success' => true,
        'updated' => $updated,
        'groups' => $groups,
        'unchanged' => $unchanged,
        'message' => $updated > 0
            ? ("Updated {$updated} row(s) across {$groups} group(s). Same date/time (+ location) now share one unique code.")
            : 'All movement groups already share matching codes.',
    ];
}

function inventory_ops_location_label(PDO $pdo, int $locationId): string
{
    if ($locationId <= 0) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(location_name, ''), location_code, CONCAT('Location #', id)) AS label
            FROM storage_locations
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$locationId]);
        $label = trim((string)($stmt->fetchColumn() ?: ''));
        return $label !== '' ? $label : ('Location #' . $locationId);
    } catch (Throwable $e) {
        return 'Location #' . $locationId;
    }
}

function inventory_ops_product(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare('
        SELECT p.id, p.name, p.sku, p.product_type, p.cost,
               ps.available_stock AS set_stock
        FROM products p
        LEFT JOIN product_sets ps ON p.name = ps.set_name
        WHERE p.id = ?
        LIMIT 1
    ');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function inventory_ops_inventory_row(PDO $pdo, int $locationId, string $itemName, ?string $sku = null, bool $forUpdate = false): ?array
{
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    if ($sku !== null && $sku !== '') {
        $stmt = $pdo->prepare('
            SELECT * FROM current_inventory
            WHERE storage_location_id = ? AND item_name = ? AND sku = ?
            ORDER BY id ASC' . $lock . '
        ');
        $stmt->execute([$locationId, $itemName, $sku]);
    } else {
        $stmt = $pdo->prepare('
            SELECT * FROM current_inventory
            WHERE storage_location_id = ? AND item_name = ?
            ORDER BY id ASC' . $lock . '
        ');
        $stmt->execute([$locationId, $itemName]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return null;
    }
    $base = $rows[0];
    $total = 0.0;
    $extraIds = [];
    foreach ($rows as $index => $row) {
        $total += (float)($row['quantity_on_hand'] ?? 0);
        if ($index > 0) {
            $extraIds[] = (int)$row['id'];
        }
    }
    $base['quantity_on_hand'] = $total;
    $base['duplicate_ids'] = $extraIds;
    return $base;
}

function inventory_ops_cleanup_duplicates(PDO $pdo, int $keeperId, array $duplicateIds): void
{
    $duplicateIds = array_values(array_filter(array_map('intval', $duplicateIds), static fn ($id) => $id > 0 && $id !== $keeperId));
    if (!$duplicateIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
    $params = array_merge([$keeperId], $duplicateIds);
    $stmt = $pdo->prepare("DELETE FROM current_inventory WHERE id <> ? AND id IN ($placeholders)");
    $stmt->execute($params);
}

function inventory_ops_save_inventory(PDO $pdo, int $locationId, string $itemName, float $quantity, float $unitCost, ?string $sku, ?int $existingId = null): int
{
    if ($existingId) {
        $stmt = $pdo->prepare('
            UPDATE current_inventory
            SET quantity_on_hand = ?, unit_cost = ?, sku = ?, last_updated = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$quantity, $unitCost, $sku, $existingId]);
        return $existingId;
    }
    $stmt = $pdo->prepare('
        INSERT INTO current_inventory (storage_location_id, item_name, sku, quantity_on_hand, unit_cost, last_updated)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([$locationId, $itemName, $sku, $quantity, $unitCost]);
    return (int)$pdo->lastInsertId();
}

function inventory_ops_ensure_dealer_reference_types(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_movements LIKE 'reference_type'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = strtolower((string)($column['Type'] ?? ''));
        if ($type === '' || strpos($type, 'enum(') !== 0 || strpos($type, "'dealer_order'") !== false) {
            return;
        }

        $pdo->exec("
            ALTER TABLE stock_movements
            MODIFY reference_type ENUM(
                'purchase','sale','adjustment','transfer','return','initial',
                'offline_sale','offline_customer_purchase','offline_sale_edit','offline_purchase_edit',
                'offline_sale_cancel','offline_customer_purchase_cancel',
                'dealer_order','dealer_order_reverse'
            ) NULL DEFAULT 'adjustment'
        ");
    } catch (Throwable $e) {
        // Older schemas still work; EOD can classify dealer rows by notes.
    }
}

function inventory_ops_insert_movement(
    PDO $pdo,
    int $itemId,
    string $movementType,
    float $quantity,
    float $previousStock,
    float $newStock,
    string $referenceType,
    string $referenceId,
    string $notes,
    float $unitCost,
    string $createdBy,
    ?int $fromStorageLocationId = null,
    ?int $toStorageLocationId = null,
    array $referenceFiles = []
): void {
    $totalCost = abs($quantity) * $unitCost;
    $filesJson = $referenceFiles ? json_encode(array_values($referenceFiles), JSON_UNESCAPED_SLASHES) : null;

    inventory_api_ensure_text_column($pdo, 'stock_movements', 'reference_files');
    if ($referenceType === 'dealer_order' || $referenceType === 'dealer_order_reverse') {
        inventory_ops_ensure_dealer_reference_types($pdo);
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO stock_movements
            (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by, from_storage_location_id, to_storage_location_id, reference_files)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $itemId, $movementType, $quantity, $previousStock, $newStock,
            $referenceType, $referenceId !== '' ? $referenceId : null, $notes,
            $unitCost, $totalCost, $createdBy, $fromStorageLocationId, $toStorageLocationId, $filesJson,
        ]);
        return;
    } catch (Throwable $e) {
        // fall through to schemas without reference_files / location columns
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO stock_movements
            (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by, from_storage_location_id, to_storage_location_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $itemId, $movementType, $quantity, $previousStock, $newStock,
            $referenceType, $referenceId !== '' ? $referenceId : null, $notes,
            $unitCost, $totalCost, $createdBy, $fromStorageLocationId, $toStorageLocationId,
        ]);
        return;
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('
            INSERT INTO stock_movements
            (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $itemId, $movementType, $quantity, $previousStock, $newStock,
            $referenceType, $referenceId !== '' ? $referenceId : null, $notes,
            $unitCost, $totalCost, $createdBy,
        ]);
    }
}

function inventory_ops_insert_inventory_movement(
    PDO $pdo,
    string $movementType,
    string $itemName,
    ?string $sku,
    float $quantity,
    float $unitCost,
    ?int $fromLocationId,
    ?int $toLocationId,
    string $referenceType,
    string $referenceId,
    string $notes,
    int $userId
): void {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO inventory_movements
            (movement_type, item_name, sku, quantity, unit_cost, total_cost, from_location_id, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ');
        $map = [
            'in' => 'purchase_in',
            'out' => 'sale_out',
            'adjustment' => 'adjustment',
            'transfer' => 'transfer_out',
        ];
        $imType = $map[$movementType] ?? 'adjustment';
        if ($movementType === 'transfer' && $toLocationId) {
            $outStmt = $pdo->prepare('
                INSERT INTO inventory_movements
                (movement_type, item_name, sku, quantity, unit_cost, total_cost, from_location_id, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ');
            $refIdNum = ctype_digit($referenceId) ? (int)$referenceId : null;
            $outStmt->execute([
                'transfer_out', $itemName, $sku, $quantity, $unitCost, $quantity * $unitCost,
                $fromLocationId, $toLocationId, $referenceType, $refIdNum,
                $referenceId, $notes, $userId,
            ]);
            $outStmt->execute([
                'transfer_in', $itemName, $sku, $quantity, $unitCost, $quantity * $unitCost,
                $fromLocationId, $toLocationId, $referenceType, $refIdNum,
                $referenceId, $notes, $userId,
            ]);
            return;
        }
        $refIdNum = ctype_digit($referenceId) ? (int)$referenceId : null;
        $stmt->execute([
            $imType, $itemName, $sku, $quantity, $unitCost, abs($quantity) * $unitCost,
            $fromLocationId, $toLocationId, $referenceType, $refIdNum,
            $referenceId, $notes, $userId,
        ]);
    } catch (Throwable $e) {
        // optional table / schema differences
    }
}

function inventory_ops_create(PDO $pdo, array $payload): array
{
    $itemId = (int)($payload['item_id'] ?? 0);
    $movementType = strtolower(trim((string)($payload['movement_type'] ?? '')));
    $quantity = inventory_api_num($payload['quantity'] ?? 0);
    $notes = inventory_api_str($payload['notes'] ?? '');
    $unitCost = inventory_api_num($payload['unit_cost'] ?? 0);
    $locationId = (int)($payload['location_id'] ?? 0);
    $fromLocationId = (int)($payload['from_location_id'] ?? 0);
    $toLocationId = (int)($payload['to_location_id'] ?? 0);
    $referenceType = inventory_api_str($payload['reference_type'] ?? 'adjustment');
    $referenceId = inventory_api_str($payload['reference_id'] ?? '');
    $createdBy = inventory_ops_user_label();
    $userId = (int)(current_user()['id'] ?? 0);
    $refLock = null;
    if ($movementType === 'transfer') {
        if ($referenceId === '') {
            $referenceId = inventory_ops_document_code($pdo, $movementType);
        }
    } else {
        $guard = inventory_ops_guard_external_reference($pdo, $movementType, $referenceType, $referenceId);
        if (isset($guard['error'])) {
            return $guard['error'];
        }
        if (isset($guard['already'])) {
            return $guard['already'];
        }
        $referenceId = (string)($guard['reference_id'] ?? $referenceId);
        $refLock = $guard['lock'] ?? null;
    }
    if ($movementType === 'transfer' && $referenceType === '') {
        $referenceType = 'transfer';
    }
    $referenceFiles = inventory_api_save_reference_files(
        is_array($payload['reference_files'] ?? null) ? $payload['reference_files'] : [],
        'inventory_movements',
        date('Y-m-d')
    );

    if ($itemId <= 0 || $movementType === '') {
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => 'Product and movement type are required.'];
    }
    if (!in_array($movementType, ['in', 'out', 'adjustment', 'transfer'], true)) {
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => 'Unsupported movement type.'];
    }

    $product = inventory_ops_product($pdo, $itemId);
    if (!$product) {
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => 'Product not found.'];
    }
    $productName = (string)$product['name'];
    $isSet = strtolower((string)($product['product_type'] ?? '')) === 'set';
    $sku = $product['sku'] ?? null;

    if ($movementType === 'transfer') {
        if ($fromLocationId <= 0 || $toLocationId <= 0) {
            return ['ok' => false, 'message' => 'Transfer requires from and to locations.'];
        }
        if ($fromLocationId === $toLocationId) {
            return ['ok' => false, 'message' => 'From and to locations must be different.'];
        }
        if ($quantity <= 0) {
            return ['ok' => false, 'message' => 'Transfer quantity must be greater than 0.'];
        }

        $source = inventory_ops_inventory_row($pdo, $fromLocationId, $productName, $sku);
        if (!$source) {
            return ['ok' => false, 'message' => 'Product not found in source location.'];
        }
        $sourceQty = (float)$source['quantity_on_hand'];
        if ($sourceQty < $quantity) {
            return ['ok' => false, 'message' => 'Insufficient stock in source location.'];
        }

        $dest = inventory_ops_inventory_row($pdo, $toLocationId, $productName, $source['sku'] ?? $sku);
        $effectiveCost = $unitCost > 0 ? $unitCost : (float)($source['unit_cost'] ?? $product['cost'] ?? 0);
        $moveSku = $source['sku'] ?? $sku;
        $sourceNew = $sourceQty - $quantity;
        $destPrev = $dest ? (float)$dest['quantity_on_hand'] : 0.0;
        $destNew = $destPrev + $quantity;
        $fromLabel = inventory_ops_location_label($pdo, $fromLocationId);
        $toLabel = inventory_ops_location_label($pdo, $toLocationId);
        $detail = $notes !== ''
            ? $notes
            : ('Transfer: ' . $fromLabel . ' → ' . $toLabel);

        $pdo->beginTransaction();
        try {
            inventory_ops_save_inventory($pdo, $fromLocationId, $productName, $sourceNew, (float)($source['unit_cost'] ?? $effectiveCost), $moveSku, (int)$source['id']);
            inventory_ops_cleanup_duplicates($pdo, (int)$source['id'], (array)($source['duplicate_ids'] ?? []));
            inventory_ops_save_inventory($pdo, $toLocationId, $productName, $destNew, $dest ? (float)$dest['unit_cost'] : $effectiveCost, $moveSku, $dest ? (int)$dest['id'] : null);
            if ($dest) {
                inventory_ops_cleanup_duplicates($pdo, (int)$dest['id'], (array)($dest['duplicate_ids'] ?? []));
            }
            inventory_ops_insert_movement(
                $pdo, $itemId, 'transfer', $quantity, $sourceQty, $sourceNew,
                $referenceType !== '' ? $referenceType : 'transfer', $referenceId, $detail, $effectiveCost, $createdBy,
                $fromLocationId, $toLocationId, $referenceFiles
            );
            inventory_ops_insert_inventory_movement(
                $pdo, 'transfer', $productName, $moveSku, $quantity, $effectiveCost,
                $fromLocationId, $toLocationId, $referenceType !== '' ? $referenceType : 'transfer', $referenceId, $notes, $userId
            );
            $pdo->commit();
            return [
                'ok' => true,
                'success' => true,
                'message' => 'Transfer saved.',
                'transfer_code' => $referenceId,
                'document_code' => $referenceId,
                'movement' => [
                    'product_name' => $productName,
                    'movement_type' => 'transfer',
                    'quantity' => $quantity,
                    'from_location_id' => $fromLocationId,
                    'to_location_id' => $toLocationId,
                    'transfer_code' => $referenceId,
                ],
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    if ($locationId <= 0) {
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => 'Storage location is required.'];
    }
    if ($movementType === 'adjustment') {
        if ($quantity == 0.0) {
            inventory_ops_release_reference_lock($pdo, $refLock);
            return ['ok' => false, 'message' => 'Adjustment quantity cannot be zero.'];
        }
    } elseif ($quantity <= 0) {
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => 'Quantity must be greater than 0.'];
    }

    $existing = inventory_ops_inventory_row($pdo, $locationId, $productName, $sku);
    $existingQty = $existing ? (float)$existing['quantity_on_hand'] : 0.0;
    $delta = $movementType === 'out' ? -$quantity : $quantity;
    $newQty = $existingQty + $delta;
    if ($newQty < 0) {
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => 'Cannot reduce stock below zero.'];
    }

    $effectiveCost = $unitCost > 0
        ? $unitCost
        : (float)($existing['unit_cost'] ?? $product['cost'] ?? 0);
    $moveSku = $existing['sku'] ?? $sku;
    $locationLabel = inventory_ops_location_label($pdo, $locationId);
    $detail = $notes !== ''
        ? $notes
        : ('Location: ' . $locationLabel);

    $pdo->beginTransaction();
    try {
        inventory_ops_save_inventory(
            $pdo,
            $locationId,
            $productName,
            $newQty,
            $effectiveCost,
            $moveSku,
            $existing ? (int)$existing['id'] : null
        );
        if ($existing) {
            inventory_ops_cleanup_duplicates($pdo, (int)$existing['id'], (array)($existing['duplicate_ids'] ?? []));
        }

        $fromLoc = ($movementType === 'out' || $movementType === 'adjustment') ? $locationId : null;
        $toLoc = $movementType === 'in' ? $locationId : null;
        inventory_ops_insert_movement(
            $pdo, $itemId, $movementType, $quantity, $existingQty, $newQty,
            $referenceType !== '' ? $referenceType : 'adjustment', $referenceId, $detail, $effectiveCost, $createdBy,
            $fromLoc, $toLoc, $referenceFiles
        );
        inventory_ops_insert_inventory_movement(
            $pdo, $movementType, $productName, $moveSku, $quantity, $effectiveCost,
            $fromLoc, $toLoc, $referenceType !== '' ? $referenceType : 'adjustment', $referenceId, $notes, $userId
        );

        if ($isSet) {
            try {
                $stmt = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock + ? WHERE set_name = ?');
                $stmt->execute([$delta, $productName]);
            } catch (Throwable $e) {
                // optional
            }
        }

        $pdo->commit();
        inventory_ops_release_reference_lock($pdo, $refLock);
        return [
            'ok' => true,
            'success' => true,
            'message' => 'Movement saved.',
            'document_code' => $referenceId,
            'movement' => [
                'product_name' => $productName,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'previous_stock' => $existingQty,
                'new_stock' => $newQty,
                'location_id' => $locationId,
                'document_code' => $referenceId,
            ],
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        inventory_ops_release_reference_lock($pdo, $refLock);
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function inventory_ops_create_movement_batch(PDO $pdo, array $payload): array
{
    $movementType = strtolower(trim((string)($payload['movement_type'] ?? '')));
    $notes = inventory_api_str($payload['notes'] ?? '');
    $locationId = (int)($payload['location_id'] ?? 0);
    $referenceType = inventory_api_str($payload['reference_type'] ?? 'adjustment');
    $referenceId = inventory_api_str($payload['reference_id'] ?? '');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $createdBy = inventory_ops_user_label();
    $userId = (int)(current_user()['id'] ?? 0);

    if (!in_array($movementType, ['in', 'out', 'adjustment'], true)) {
        return ['ok' => false, 'message' => 'Unsupported movement type.'];
    }
    if ($locationId <= 0) {
        return ['ok' => false, 'message' => 'Storage location is required.'];
    }
    if (!$items) {
        return ['ok' => false, 'message' => 'Add at least one product line.'];
    }
    $guard = inventory_ops_guard_external_reference($pdo, $movementType, $referenceType, $referenceId);
    if (isset($guard['error'])) {
        return $guard['error'];
    }
    if (isset($guard['already'])) {
        return $guard['already'];
    }
    $referenceId = (string)($guard['reference_id'] ?? $referenceId);
    $refLock = $guard['lock'] ?? null;

    try {
    $referenceFiles = inventory_api_save_reference_files(
        is_array($payload['reference_files'] ?? null) ? $payload['reference_files'] : [],
        'inventory_movements',
        date('Y-m-d')
    );

    $locationLabel = inventory_ops_location_label($pdo, $locationId);
    $detail = $notes !== '' ? $notes : ('Location: ' . $locationLabel);
    $moved = [];

    $pdo->beginTransaction();
    try {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid product line.');
            }
            $itemId = (int)($item['item_id'] ?? 0);
            $quantity = inventory_api_num($item['quantity'] ?? 0);
            $unitCost = inventory_api_num($item['unit_cost'] ?? 0);
            if ($itemId <= 0) {
                throw new RuntimeException('Each line needs a product.');
            }
            if ($movementType === 'adjustment') {
                if ($quantity == 0.0) {
                    throw new RuntimeException('Adjustment quantity cannot be zero.');
                }
            } elseif ($quantity <= 0) {
                throw new RuntimeException('Each line quantity must be greater than 0.');
            }

            $product = inventory_ops_product($pdo, $itemId);
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $productName = (string)$product['name'];
            $isSet = strtolower((string)($product['product_type'] ?? '')) === 'set';
            $sku = $product['sku'] ?? null;
            $existing = inventory_ops_inventory_row($pdo, $locationId, $productName, $sku);
            $existingQty = $existing ? (float)$existing['quantity_on_hand'] : 0.0;
            $delta = $movementType === 'out' ? -$quantity : $quantity;
            $newQty = $existingQty + $delta;
            if ($newQty < 0) {
                throw new RuntimeException('Cannot reduce stock below zero for ' . $productName . '.');
            }

            $effectiveCost = $unitCost > 0
                ? $unitCost
                : (float)($existing['unit_cost'] ?? $product['cost'] ?? 0);
            $moveSku = $existing['sku'] ?? $sku;
            $fromLoc = ($movementType === 'out' || $movementType === 'adjustment') ? $locationId : null;
            $toLoc = $movementType === 'in' ? $locationId : null;
            $lineFiles = $index === 0 ? $referenceFiles : [];

            inventory_ops_save_inventory(
                $pdo,
                $locationId,
                $productName,
                $newQty,
                $effectiveCost,
                $moveSku,
                $existing ? (int)$existing['id'] : null
            );
            if ($existing) {
                inventory_ops_cleanup_duplicates($pdo, (int)$existing['id'], (array)($existing['duplicate_ids'] ?? []));
            }

            inventory_ops_insert_movement(
                $pdo, $itemId, $movementType, $quantity, $existingQty, $newQty,
                $referenceType !== '' ? $referenceType : 'adjustment', $referenceId, $detail, $effectiveCost, $createdBy,
                $fromLoc, $toLoc, $lineFiles
            );
            inventory_ops_insert_inventory_movement(
                $pdo, $movementType, $productName, $moveSku, $quantity, $effectiveCost,
                $fromLoc, $toLoc, $referenceType !== '' ? $referenceType : 'adjustment', $referenceId, $notes, $userId
            );

            if ($isSet) {
                try {
                    $stmt = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock + ? WHERE set_name = ?');
                    $stmt->execute([$delta, $productName]);
                } catch (Throwable $e) {
                    // optional
                }
            }

            $moved[] = [
                'item_id' => $itemId,
                'product_name' => $productName,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'previous_stock' => $existingQty,
                'new_stock' => $newQty,
                'location_id' => $locationId,
            ];
        }
        $pdo->commit();
        return [
            'ok' => true,
            'success' => true,
            'message' => count($moved) === 1 ? 'Movement saved.' : 'Movements saved.',
            'document_code' => $referenceId,
            'lines' => count($moved),
            'movements' => $moved,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    } finally {
        inventory_ops_release_reference_lock($pdo, $refLock);
    }
}

function inventory_ops_create_transfer_batch(PDO $pdo, array $payload): array
{
    $fromLocationId = (int)($payload['from_location_id'] ?? 0);
    $toLocationId = (int)($payload['to_location_id'] ?? 0);
    $notes = inventory_api_str($payload['notes'] ?? '');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $createdBy = inventory_ops_user_label();
    $userId = (int)(current_user()['id'] ?? 0);
    $referenceType = 'transfer';
    $referenceId = inventory_api_str($payload['reference_id'] ?? '');
    if ($referenceId === '') {
        $referenceId = inventory_ops_document_code($pdo, 'transfer');
    }

    if ($fromLocationId <= 0 || $toLocationId <= 0) {
        return ['ok' => false, 'message' => 'Transfer requires from and to locations.'];
    }
    if ($fromLocationId === $toLocationId) {
        return ['ok' => false, 'message' => 'From and to locations must be different.'];
    }
    if (!$items) {
        return ['ok' => false, 'message' => 'Add at least one product line.'];
    }

    $referenceFiles = inventory_api_save_reference_files(
        is_array($payload['reference_files'] ?? null) ? $payload['reference_files'] : [],
        'inventory_movements',
        date('Y-m-d')
    );

    $fromLabel = inventory_ops_location_label($pdo, $fromLocationId);
    $toLabel = inventory_ops_location_label($pdo, $toLocationId);
    $detail = $notes !== '' ? $notes : ('Transfer: ' . $fromLabel . ' → ' . $toLabel);
    $moved = [];

    $pdo->beginTransaction();
    try {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid transfer line.');
            }
            $itemId = (int)($item['item_id'] ?? 0);
            $quantity = inventory_api_num($item['quantity'] ?? 0);
            $unitCost = inventory_api_num($item['unit_cost'] ?? 0);
            if ($itemId <= 0) {
                throw new RuntimeException('Each line needs a product.');
            }
            if ($quantity <= 0) {
                throw new RuntimeException('Each line quantity must be greater than 0.');
            }

            $product = inventory_ops_product($pdo, $itemId);
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $productName = (string)$product['name'];
            $sku = $product['sku'] ?? null;
            $source = inventory_ops_inventory_row($pdo, $fromLocationId, $productName, $sku);
            if (!$source) {
                throw new RuntimeException($productName . ' not found in source location.');
            }
            $sourceQty = (float)$source['quantity_on_hand'];
            if ($sourceQty < $quantity) {
                throw new RuntimeException('Insufficient stock for ' . $productName . '.');
            }

            $dest = inventory_ops_inventory_row($pdo, $toLocationId, $productName, $source['sku'] ?? $sku);
            $effectiveCost = $unitCost > 0 ? $unitCost : (float)($source['unit_cost'] ?? $product['cost'] ?? 0);
            $moveSku = $source['sku'] ?? $sku;
            $sourceNew = $sourceQty - $quantity;
            $destPrev = $dest ? (float)$dest['quantity_on_hand'] : 0.0;
            $destNew = $destPrev + $quantity;
            $lineFiles = $index === 0 ? $referenceFiles : [];

            inventory_ops_save_inventory($pdo, $fromLocationId, $productName, $sourceNew, (float)($source['unit_cost'] ?? $effectiveCost), $moveSku, (int)$source['id']);
            inventory_ops_cleanup_duplicates($pdo, (int)$source['id'], (array)($source['duplicate_ids'] ?? []));
            inventory_ops_save_inventory($pdo, $toLocationId, $productName, $destNew, $dest ? (float)$dest['unit_cost'] : $effectiveCost, $moveSku, $dest ? (int)$dest['id'] : null);
            if ($dest) {
                inventory_ops_cleanup_duplicates($pdo, (int)$dest['id'], (array)($dest['duplicate_ids'] ?? []));
            }
            inventory_ops_insert_movement(
                $pdo, $itemId, 'transfer', $quantity, $sourceQty, $sourceNew,
                $referenceType, $referenceId, $detail, $effectiveCost, $createdBy,
                $fromLocationId, $toLocationId, $lineFiles
            );
            inventory_ops_insert_inventory_movement(
                $pdo, 'transfer', $productName, $moveSku, $quantity, $effectiveCost,
                $fromLocationId, $toLocationId, $referenceType, $referenceId, $notes, $userId
            );
            $moved[] = [
                'item_id' => $itemId,
                'product_name' => $productName,
                'quantity' => $quantity,
                'previous_stock' => $sourceQty,
                'new_stock' => $sourceNew,
            ];
        }
        $pdo->commit();
        return [
            'ok' => true,
            'success' => true,
            'message' => 'Transfer saved.',
            'transfer_code' => $referenceId,
            'document_code' => $referenceId,
            'lines' => count($moved),
            'movements' => $moved,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function inventory_ops_adjustment_reasons(): array
{
    return [
        'Physical Count Correction',
        'Missing Stock',
        'Damaged Product',
        'Expired Product',
        'Found Extra Stock',
        'Wrong Previous Entry',
        'Other',
    ];
}

function inventory_ops_location_stock(PDO $pdo, int $locationId): array
{
    if ($locationId <= 0) {
        return [];
    }
    $sql = '
        SELECT
            p.id AS value,
            CONCAT(
                p.name,
                CASE
                    WHEN p.sku IS NOT NULL AND TRIM(p.sku) <> \'\' THEN CONCAT(\' (\', p.sku, \')\')
                    ELSE \'\'
                END
            ) AS label,
            p.name,
            p.sku,
            COALESCE(p.cost, 0) AS unit_cost,
            p.product_type,
            SUM(COALESCE(ci.quantity_on_hand, 0)) AS available_stock
        FROM current_inventory ci
        INNER JOIN products p
            ON p.name = ci.item_name
           AND COALESCE(NULLIF(TRIM(p.sku), \'\'), \'\') = COALESCE(NULLIF(TRIM(ci.sku), \'\'), \'\')
           AND COALESCE(p.active, 1) = 1
        WHERE ci.storage_location_id = ?
        GROUP BY p.id, p.name, p.sku, p.cost, p.product_type
        HAVING SUM(COALESCE(ci.quantity_on_hand, 0)) <> 0
        ORDER BY p.name ASC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$locationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows) {
        return $rows;
    }

    $fallbackSql = '
        SELECT
            p.id AS value,
            CONCAT(
                p.name,
                CASE
                    WHEN p.sku IS NOT NULL AND TRIM(p.sku) <> \'\' THEN CONCAT(\' (\', p.sku, \')\')
                    ELSE \'\'
                END
            ) AS label,
            p.name,
            p.sku,
            COALESCE(p.cost, 0) AS unit_cost,
            p.product_type,
            SUM(COALESCE(ci.quantity_on_hand, 0)) AS available_stock
        FROM current_inventory ci
        INNER JOIN products p
            ON p.name = ci.item_name
           AND COALESCE(p.active, 1) = 1
        WHERE ci.storage_location_id = ?
        GROUP BY p.id, p.name, p.sku, p.cost, p.product_type
        HAVING SUM(COALESCE(ci.quantity_on_hand, 0)) <> 0
        ORDER BY p.name ASC
    ';
    $fallback = $pdo->prepare($fallbackSql);
    $fallback->execute([$locationId]);
    return $fallback->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function inventory_ops_create_physical_adjustment(PDO $pdo, array $payload): array
{
    $locationId = (int)($payload['location_id'] ?? 0);
    $reason = inventory_api_str($payload['reason'] ?? '');
    $note = inventory_api_str($payload['notes'] ?? ($payload['note'] ?? ''));
    $createdBy = inventory_ops_user_label();
    $userId = (int)(current_user()['id'] ?? 0);
    $allowedReasons = inventory_ops_adjustment_reasons();
    $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    if (!$rawItems && (int)($payload['item_id'] ?? 0) > 0) {
        $rawItems = [[
            'item_id' => $payload['item_id'],
            'actual_qty' => $payload['actual_qty'] ?? ($payload['actual_quantity'] ?? 0),
        ]];
    }

    if ($locationId <= 0) {
        return ['ok' => false, 'message' => 'Storage location is required.'];
    }
    if ($reason === '' || !in_array($reason, $allowedReasons, true)) {
        return ['ok' => false, 'message' => 'Select a valid adjustment reason.'];
    }

    $seen = [];
    $items = [];
    foreach ($rawItems as $row) {
        if (!is_array($row)) {
            continue;
        }
        $itemId = (int)($row['item_id'] ?? 0);
        $actualQty = (int)round(inventory_api_num($row['actual_qty'] ?? ($row['actual_quantity'] ?? 0)));
        if ($itemId <= 0) {
            continue;
        }
        if (isset($seen[$itemId])) {
            return ['ok' => false, 'message' => 'Each product can only appear once in an adjustment.'];
        }
        if ($actualQty < 0) {
            return ['ok' => false, 'message' => 'Actual physical quantity cannot be negative.'];
        }
        $seen[$itemId] = true;
        $items[] = ['item_id' => $itemId, 'actual_qty' => $actualQty];
    }
    if (!$items) {
        return ['ok' => false, 'message' => 'Add at least one product.'];
    }

    $locationLabel = inventory_ops_location_label($pdo, $locationId);
    $detail = 'Reason: ' . $reason . ($note !== '' ? "\nNote: " . $note : '');

    $pdo->beginTransaction();
    try {
        $referenceId = inventory_ops_next_adjustment_code($pdo);
        $moved = [];

        foreach ($items as $row) {
            $itemId = $row['item_id'];
            $actualQty = $row['actual_qty'];
            $product = inventory_ops_product($pdo, $itemId);
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }
            $productName = (string)$product['name'];
            $isSet = strtolower((string)($product['product_type'] ?? '')) === 'set';
            $sku = $product['sku'] ?? null;
            $existing = inventory_ops_inventory_row($pdo, $locationId, $productName, $sku, true);
            $previousQty = $existing ? (int)round((float)$existing['quantity_on_hand']) : 0;
            $difference = $actualQty - $previousQty;
            if ($difference === 0) {
                continue;
            }
            $newQty = $actualQty;
            $unitCost = (float)($existing['unit_cost'] ?? $product['cost'] ?? 0);
            $moveSku = $existing['sku'] ?? $sku;

            inventory_ops_insert_movement(
                $pdo,
                $itemId,
                'adjustment',
                $difference,
                $previousQty,
                $newQty,
                'adjustment',
                $referenceId,
                $detail,
                $unitCost,
                $createdBy,
                $locationId,
                null,
                []
            );
            inventory_ops_insert_inventory_movement(
                $pdo,
                'adjustment',
                $productName,
                $moveSku,
                $difference,
                $unitCost,
                $locationId,
                null,
                'adjustment',
                $referenceId,
                $detail,
                $userId
            );
            inventory_ops_save_inventory(
                $pdo,
                $locationId,
                $productName,
                $newQty,
                $unitCost,
                $moveSku,
                $existing ? (int)$existing['id'] : null
            );
            if ($existing) {
                inventory_ops_cleanup_duplicates($pdo, (int)$existing['id'], (array)($existing['duplicate_ids'] ?? []));
            }
            if ($isSet) {
                try {
                    $stmt = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock + ? WHERE set_name = ?');
                    $stmt->execute([$difference, $productName]);
                } catch (Throwable $e) {
                    // optional
                }
            }

            $moved[] = [
                'item_id' => $itemId,
                'product_name' => $productName,
                'previous_qty' => $previousQty,
                'actual_qty' => $actualQty,
                'difference' => $difference,
                'new_qty' => $newQty,
            ];
        }

        if (!$moved) {
            throw new RuntimeException('No adjustment. Counted quantities match system quantity.');
        }

        $pdo->commit();
        $first = $moved[0] ?? [];
        return [
            'ok' => true,
            'success' => true,
            'message' => count($moved) === 1 ? 'Adjustment saved.' : 'Adjustment saved for ' . count($moved) . ' products.',
            'document_code' => $referenceId,
            'location' => $locationLabel,
            'reason' => $reason,
            'notes' => $note,
            'lines' => count($moved),
            'items' => $moved,
            'movements' => array_map(static fn(array $row): array => [
                'product_name' => $row['product_name'],
                'quantity' => $row['difference'],
            ], $moved),
            'product_name' => $first['product_name'] ?? '',
            'previous_qty' => $first['previous_qty'] ?? 0,
            'actual_qty' => $first['actual_qty'] ?? 0,
            'difference' => $first['difference'] ?? 0,
            'new_qty' => $first['new_qty'] ?? 0,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!empty($inventoryOpsIsCli)) {
    $action = strtolower(trim((string)($argv[1] ?? '')));
    if ($action === 'backfill_transfer_codes') {
        $result = inventory_ops_backfill_transfer_codes($pdo);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(!empty($result['ok']) ? 0 : 1);
    }
    if ($action === 'backfill_movement_codes') {
        $result = inventory_ops_backfill_movement_codes($pdo);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(!empty($result['ok']) ? 0 : 1);
    }
    if ($action === 'backfill_skus') {
        $result = inventory_copy_product_skus($pdo);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(!empty($result['ok']) ? 0 : 1);
    }
    fwrite(STDERR, "Usage: php inventory_ops.php backfill_transfer_codes|backfill_movement_codes|backfill_skus\n");
    exit(1);
}

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($action === 'delivery_slip_history') {
        $company = inventory_ops_company_profile($pdo);
        api_json([
            'success' => true,
            'delivery_slips' => inventory_ops_delivery_slip_history($pdo, $_GET),
            'logo_url' => $company['logo_url'],
            'company_name' => $company['company_name'],
            'company_phone' => $company['company_phone'],
            'company_email' => $company['company_email'],
        ]);
    }

    if ($action === 'default_logo') {
        $company = inventory_ops_company_profile($pdo);
        api_json([
            'success' => true,
            'logo_url' => $company['logo_url'],
            'company_name' => $company['company_name'],
            'company_phone' => $company['company_phone'],
            'company_email' => $company['company_email'],
        ]);
    }

    if ($action === 'current_stock') {
        $itemId = inventory_api_int($_GET['item_id'] ?? 0);
        $locationId = inventory_api_int($_GET['location_id'] ?? 0);
        if ($itemId <= 0 || $locationId <= 0) {
            api_json(['success' => false, 'message' => 'Product and location are required.'], 400);
        }
        $product = inventory_ops_product($pdo, $itemId);
        if (!$product) {
            api_json(['success' => false, 'message' => 'Product not found.'], 400);
        }
        $row = inventory_ops_inventory_row($pdo, $locationId, (string)$product['name'], $product['sku'] ?? null);
        api_json([
            'success' => true,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'product_name' => (string)$product['name'],
            'sku' => $product['sku'] ?? '',
            'system_qty' => $row ? (int)round((float)$row['quantity_on_hand']) : 0,
        ]);
    }

    if ($action === 'location_stock') {
        $locationId = inventory_api_int($_GET['location_id'] ?? 0);
        if ($locationId <= 0) {
            api_json(['success' => false, 'message' => 'Location is required.'], 400);
        }
        $products = inventory_ops_location_stock($pdo, $locationId);
        api_json([
            'success' => true,
            'location_id' => $locationId,
            'products' => $products,
            'count' => count($products),
            'location_options' => inventory_location_options($pdo),
        ]);
    }

    $q = inventory_api_str($_GET['q'] ?? '');
    $requestedMovementType = strtolower(trim((string)($_GET['movement_type'] ?? 'in')));
    if (!in_array($requestedMovementType, ['in', 'out', 'adjustment'], true)) {
        $requestedMovementType = 'in';
    }
    $productLocationId = max(0, inventory_api_int($_GET['from_location_id'] ?? ($_GET['location_id'] ?? 0)));
    $inventoryLocationWhere = $productLocationId > 0
        ? 'WHERE storage_location_id = ' . $productLocationId
        : '';
    $productSql = '
        SELECT p.id AS value,
               CONCAT(p.name, CASE WHEN p.sku IS NOT NULL AND p.sku <> \'\' THEN CONCAT(\' (\', p.sku, \')\') ELSE \'\' END) AS label,
               p.name,
               p.sku,
               COALESCE(p.cost, 0) AS unit_cost,
               p.product_type,
               COALESCE(
                   CASE
                       WHEN COALESCE(NULLIF(TRIM(p.sku), \'\'), \'\') = \'\' THEN inv_name.available_stock
                       ELSE inv_exact.available_stock
                   END,
                   0
               ) AS available_stock
        FROM products p
        LEFT JOIN (
            SELECT item_name,
                   COALESCE(NULLIF(TRIM(sku), \'\'), \'\') AS sku_key,
                   SUM(COALESCE(quantity_on_hand, 0)) AS available_stock
            FROM current_inventory
            ' . $inventoryLocationWhere . '
            GROUP BY item_name, COALESCE(NULLIF(TRIM(sku), \'\'), \'\')
        ) inv_exact ON inv_exact.item_name = p.name
             AND inv_exact.sku_key = COALESCE(NULLIF(TRIM(p.sku), \'\'), \'\')
        LEFT JOIN (
            SELECT item_name,
                   SUM(COALESCE(quantity_on_hand, 0)) AS available_stock
            FROM current_inventory
            ' . $inventoryLocationWhere . '
            GROUP BY item_name
        ) inv_name ON inv_name.item_name = p.name
        WHERE COALESCE(p.active, 1) = 1
    ';
    $params = [];
    if ($q !== '') {
        $productSql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $productSql .= ' ORDER BY p.name ASC LIMIT 300';
    $stmt = $pdo->prepare($productSql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'success' => true,
        'products' => $products,
        'location_options' => inventory_location_options($pdo),
        'next_movement_code' => inventory_ops_document_code($pdo, $requestedMovementType),
        'next_transfer_code' => inventory_ops_document_code($pdo, 'transfer'),
        'movement_type_options' => [
            ['value' => 'in', 'label' => 'Stock In'],
            ['value' => 'out', 'label' => 'Stock Out'],
            ['value' => 'adjustment', 'label' => 'Adjustment (+/-)'],
            ['value' => 'transfer', 'label' => 'Transfer'],
        ],
    ]);
}

if ($method === 'POST') {
    $payload = is_array($inventoryOpsPostPayload) ? $inventoryOpsPostPayload : [];
    if (!$payload) {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '{}', true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
    }

    $action = strtolower(trim((string)($payload['action'] ?? '')));
    if ($action === 'backfill_transfer_codes') {
        $result = inventory_ops_backfill_transfer_codes($pdo);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to backfill transfer codes.',
            ], 400);
        }
        api_json($result);
    }

    if ($action === 'backfill_movement_codes') {
        $result = inventory_ops_backfill_movement_codes($pdo);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to backfill movement codes.',
            ], 400);
        }
        api_json($result);
    }

    if ($action === 'backfill_skus') {
        $result = inventory_copy_product_skus($pdo);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to copy SKU.',
            ], 400);
        }
        api_json($result);
    }

    if ($action === 'create_stock_adjustment') {
        $result = inventory_ops_create_physical_adjustment($pdo, $payload);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to save adjustment.',
            ], 400);
        }
        try {
            $notification = inventory_ops_send_notification($pdo, $result, array_merge($payload, ['movement_type' => 'adjustment']));
            if (empty($notification['skipped'])) {
                $result['notification'] = $notification;
            }
        } catch (Throwable $e) {
            $result['notification'] = [
                'ok' => false,
                'message' => 'Telegram notification failed: ' . $e->getMessage(),
            ];
        }
        api_json($result);
    }

    if ($action === 'save_delivery_slip_history') {
        require_role_or_permission(['admin'], 'sr_inventory_transfer.create', 'sr_inventory_delivery_notes.update');
        $result = inventory_ops_save_delivery_slip_history($pdo, $payload);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to save delivery slip history.',
            ], 400);
        }
        api_json($result);
    }

    if ($action === 'update_delivery_slip_history') {
        require_role_or_permission(['admin'], 'sr_inventory_delivery_notes.update');
        $result = inventory_ops_update_delivery_slip_history($pdo, $payload);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to update delivery slip.',
            ], 400);
        }
        api_json($result);
    }

    if ($action === 'delete_delivery_slip_history') {
        require_role_or_permission(['admin'], 'sr_inventory_delivery_notes.delete');
        $result = inventory_ops_delete_delivery_slip_history($pdo, $payload);
        if (empty($result['ok'])) {
            api_json([
                'success' => false,
                'ok' => false,
                'message' => $result['message'] ?? 'Unable to delete delivery slip.',
            ], 400);
        }
        api_json($result);
    }

    $hasItems = !empty($payload['items']) && is_array($payload['items']);
    $payloadMovementType = strtolower(trim((string)($payload['movement_type'] ?? '')));
    $isTransferBatch = $payloadMovementType === 'transfer'
        && !empty($payload['items'])
        && is_array($payload['items']);
    $result = $isTransferBatch
        ? inventory_ops_create_transfer_batch($pdo, $payload)
        : ($hasItems && in_array($payloadMovementType, ['in', 'out', 'adjustment'], true)
            ? inventory_ops_create_movement_batch($pdo, $payload)
            : inventory_ops_create($pdo, $payload));
    if (empty($result['ok'])) {
        api_json([
            'success' => false,
            'ok' => false,
            'message' => $result['message'] ?? 'Unable to save movement.',
        ], 400);
    }
    try {
        $notification = inventory_ops_send_notification($pdo, $result, $payload);
        if (empty($notification['skipped'])) {
            $result['notification'] = $notification;
        }
    } catch (Throwable $e) {
        $result['notification'] = [
            'ok' => false,
            'message' => 'Telegram notification failed: ' . $e->getMessage(),
        ];
    }
    api_json($result);
}

api_json(['success' => false, 'message' => 'Method not allowed.'], 405);
