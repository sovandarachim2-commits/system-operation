<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'stock_operations.view');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$message = '';
$messageType = '';
$currentUser = current_user();
$stockMovementCreatedBy = trim((string)($currentUser['username'] ?? ''));
if ($stockMovementCreatedBy === '') {
    $stockMovementCreatedBy = trim((string)($currentUser['name'] ?? ''));
}
if ($stockMovementCreatedBy === '') {
    $stockMovementCreatedBy = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : 'system';
}

if (isset($_SESSION['stock_operations_flash']) && is_array($_SESSION['stock_operations_flash'])) {
    $message = (string)($_SESSION['stock_operations_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['stock_operations_flash']['type'] ?? '');
    unset($_SESSION['stock_operations_flash']);
}

$locations_stmt = $pdo->query("SELECT id, location_code, location_name FROM storage_locations WHERE is_active = 1 ORDER BY location_code");
$locations = $locations_stmt->fetchAll(PDO::FETCH_ASSOC);
$locationLabels = [];
foreach ($locations as $location) {
    $locationId = (int)($location['id'] ?? 0);
    if ($locationId > 0) {
        $locationLabels[$locationId] = '#' . $locationId . ' - ' . ($location['location_code'] ?? '') . ' - ' . ($location['location_name'] ?? '');
    }
}

function getStockProductInfo(PDO $pdo, int $itemId): ?array {
    $stmt = $pdo->prepare("SELECT p.id, p.name, p.product_type, ps.available_stock as set_stock FROM products p LEFT JOIN product_sets ps ON p.name = ps.set_name WHERE p.id = ?");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getInventoryRow(PDO $pdo, int $locationId, string $itemName, ?string $sku = null): ?array {
    if ($sku !== null && $sku !== '') {
        $stmt = $pdo->prepare("SELECT * FROM current_inventory WHERE storage_location_id = ? AND item_name = ? AND sku = ? ORDER BY id ASC");
        $stmt->execute([$locationId, $itemName, $sku]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $base = $rows[0];
            $totalQuantity = 0.0;
            $extraIds = [];
            foreach ($rows as $index => $row) {
                $totalQuantity += (float)($row['quantity_on_hand'] ?? 0);
                if ($index > 0 && isset($row['id'])) {
                    $extraIds[] = (int)$row['id'];
                }
            }
            $base['quantity_on_hand'] = $totalQuantity;
            $base['duplicate_ids'] = $extraIds;
            return $base;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM current_inventory WHERE storage_location_id = ? AND item_name = ? ORDER BY id ASC");
    $stmt->execute([$locationId, $itemName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return null;
    }

    $base = $rows[0];
    $totalQuantity = 0.0;
    $extraIds = [];
    foreach ($rows as $index => $row) {
        $totalQuantity += (float)($row['quantity_on_hand'] ?? 0);
        if ($index > 0 && isset($row['id'])) {
            $extraIds[] = (int)$row['id'];
        }
    }

    $base['quantity_on_hand'] = $totalQuantity;
    $base['duplicate_ids'] = $extraIds;
    return $base;
}

function cleanupDuplicateInventoryRows(PDO $pdo, int $keeperId, array $duplicateIds): void {
    $duplicateIds = array_values(array_filter(array_map('intval', $duplicateIds), fn($id) => $id > 0 && $id !== $keeperId));
    if (empty($duplicateIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
    $params = array_merge([$keeperId], $duplicateIds);
    $stmt = $pdo->prepare("DELETE FROM current_inventory WHERE id <> ? AND id IN ($placeholders)");
    $stmt->execute($params);
}

function consolidateAllInventoryDuplicates(PDO $pdo): int {
    $stmt = $pdo->query("
        SELECT storage_location_id, item_name, COALESCE(sku, '') as sku
        FROM current_inventory
        GROUP BY storage_location_id, item_name, COALESCE(sku, '')
        HAVING COUNT(*) > 1
    ");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($groups)) {
        return 0;
    }

    $fixedGroups = 0;
    foreach ($groups as $group) {
        $locationId = (int)($group['storage_location_id'] ?? 0);
        $itemName = (string)($group['item_name'] ?? '');
        $sku = (string)($group['sku'] ?? '');
        if ($locationId <= 0 || $itemName === '') {
            continue;
        }

        $row = getInventoryRow($pdo, $locationId, $itemName, $sku !== '' ? $sku : null);
        if (!$row || empty($row['duplicate_ids'])) {
            continue;
        }

        $keeperId = (int)$row['id'];
        $stmtUpdate = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, unit_cost = ?, sku = ?, last_updated = NOW() WHERE id = ?");
        $stmtUpdate->execute([
            (float)($row['quantity_on_hand'] ?? 0),
            (float)($row['unit_cost'] ?? 0),
            $row['sku'] ?? null,
            $keeperId,
        ]);
        cleanupDuplicateInventoryRows($pdo, $keeperId, (array)$row['duplicate_ids']);
        $fixedGroups++;
    }

    return $fixedGroups;
}

function getFallbackInventoryMeta(PDO $pdo, string $itemName): array {
    $stmt = $pdo->prepare("SELECT sku, unit_cost FROM current_inventory WHERE item_name = ? ORDER BY last_updated DESC, id DESC LIMIT 1");
    $stmt->execute([$itemName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'sku' => $row['sku'] ?? null,
        'unit_cost' => isset($row['unit_cost']) ? (float)$row['unit_cost'] : 0.0,
    ];
}

function saveInventoryRow(PDO $pdo, int $locationId, string $itemName, float $quantity, float $unitCost, ?string $sku, ?int $existingId = null): int {
    if ($existingId) {
        $stmt = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, unit_cost = ?, sku = ?, last_updated = NOW() WHERE id = ?");
        $stmt->execute([$quantity, $unitCost, $sku, $existingId]);
        return $existingId;
    }
    $existing = getInventoryRow($pdo, $locationId, $itemName, $sku);
    if ($existing) {
        $keeperId = (int)$existing['id'];
        $stmt = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ?, unit_cost = ?, sku = ?, last_updated = NOW() WHERE id = ?");
        $stmt->execute([$quantity, $unitCost, $sku, $keeperId]);
        cleanupDuplicateInventoryRows($pdo, $keeperId, (array)($existing['duplicate_ids'] ?? []));
        return $keeperId;
    }
    $stmt = $pdo->prepare("INSERT INTO current_inventory (storage_location_id, item_name, sku, quantity_on_hand, unit_cost, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$locationId, $itemName, $sku, $quantity, $unitCost]);
    return (int)$pdo->lastInsertId();
}

function insertStockMovement(PDO $pdo, int $itemId, string $movementType, float $quantity, float $previousStock, float $newStock, string $referenceType, string $referenceId, string $notes, float $unitCost, string $createdBy, ?int $fromStorageLocationId = null, ?int $toStorageLocationId = null): void {
    static $hasMovementLocationCols = null;
    if ($hasMovementLocationCols === null) {
        $mc = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN);
        $mcLc = array_map('strtolower', array_map('strval', $mc));
        $hasMovementLocationCols = in_array('from_storage_location_id', $mcLc, true) && in_array('to_storage_location_id', $mcLc, true);
    }
    $totalCost = abs($unitCost * $quantity);
    if ($hasMovementLocationCols) {
        $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by, from_storage_location_id, to_storage_location_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$itemId, $movementType, $quantity, $previousStock, $newStock, $referenceType, $referenceId, $notes, $unitCost, $totalCost, $createdBy, $fromStorageLocationId, $toStorageLocationId]);
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$itemId, $movementType, $quantity, $previousStock, $newStock, $referenceType, $referenceId, $notes, $unitCost, $totalCost, $createdBy]);
}

function ensureStockDeliverySlipHistory(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_delivery_slip_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slip_code VARCHAR(40) NOT NULL UNIQUE,
            receiver_name VARCHAR(255) NOT NULL,
            receiver_phone VARCHAR(80) NOT NULL,
            transfer_to VARCHAR(255) NULL,
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
}

function stockDeliverySlipCode(): string {
    return 'DSL' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function stockOperationMovementLabel(string $type): string {
    return match ($type) {
        'in' => 'Stock In',
        'out' => 'Stock Out',
        'adjustment' => 'Adjustment',
        'transfer' => 'Transfer',
        default => ucfirst($type),
    };
}

function stockOperationFormatQty(float $value): string {
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function stockOperationTelegramLocation(array $movement): string {
    if (($movement['movement_type'] ?? '') === 'transfer') {
        return '#' . (int)($movement['from_location_id'] ?? 0) . ' -> #' . (int)($movement['to_location_id'] ?? 0);
    }
    return '#' . (int)($movement['location_id'] ?? 0);
}

function stockOperationTelegramConfig(): array {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;
    global $STOCK_MOVEMENT_TELEGRAM_BOT_TOKEN, $STOCK_MOVEMENT_TELEGRAM_CHAT_ID, $STOCK_MOVEMENT_TELEGRAM_TARGETS;

    $botToken = trim((string)($STOCK_MOVEMENT_TELEGRAM_BOT_TOKEN ?? ''));
    if ($botToken === '') {
        $botToken = trim((string)($TELEGRAM_BOT_TOKEN ?? ''));
    }
    if ($botToken === '') {
        return ['bot_token' => '', 'targets' => [], 'error' => 'Stock movement Telegram bot token is not configured.'];
    }

    $targets = [];
    if (!empty($STOCK_MOVEMENT_TELEGRAM_TARGETS) && is_array($STOCK_MOVEMENT_TELEGRAM_TARGETS)) {
        $targets = $STOCK_MOVEMENT_TELEGRAM_TARGETS;
    } elseif (!empty($STOCK_MOVEMENT_TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => $STOCK_MOVEMENT_TELEGRAM_CHAT_ID, 'thread_id' => null]];
    } elseif (!empty($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) {
        $targets = $TELEGRAM_TARGETS;
    } elseif (!empty($TELEGRAM_CHAT_ID)) {
        $targets = [['chat_id' => $TELEGRAM_CHAT_ID, 'thread_id' => null]];
    }

    if (empty($targets)) {
        return ['bot_token' => $botToken, 'targets' => [], 'error' => 'Stock movement Telegram target is not configured.'];
    }

    return ['bot_token' => $botToken, 'targets' => $targets, 'error' => ''];
}

function sendStockOperationTelegramText(string $text): array {
    $config = stockOperationTelegramConfig();
    $botToken = (string)($config['bot_token'] ?? '');
    $targets = is_array($config['targets'] ?? null) ? $config['targets'] : [];
    if ($botToken === '' || empty($targets)) {
        return [[
            'ok' => false,
            'chat_id' => '',
            'thread_id' => null,
            'error' => (string)($config['error'] ?? 'Stock movement Telegram is not configured.'),
        ]];
    }

    $results = [];
    foreach ($targets as $target) {
        $chatId = trim((string)($target['chat_id'] ?? ''));
        if ($chatId === '') {
            continue;
        }
        $threadId = $target['thread_id'] ?? null;
        $threadId = ($threadId !== null && $threadId !== '') ? (int)$threadId : null;
        $result = telegram_send_message_request($botToken, $chatId, $text, $threadId);
        $results[] = [
            'ok' => !empty($result['ok']),
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'error' => (string)($result['error'] ?? ''),
        ];
        if (empty($result['ok'])) {
            $error = (string)($result['error'] ?? 'unknown error');
            error_log('Stock movement Telegram send failed for chat ' . $chatId . ($threadId !== null ? ' topic ' . $threadId : '') . ': ' . $error);
        }
    }

    return $results;
}

function sendStockOperationTelegramPhoto(string $imageUrl, string $caption): array {
    $config = stockOperationTelegramConfig();
    $botToken = (string)($config['bot_token'] ?? '');
    $targets = is_array($config['targets'] ?? null) ? $config['targets'] : [];
    if ($botToken === '' || empty($targets)) {
        return [[
            'ok' => false,
            'chat_id' => '',
            'thread_id' => null,
            'error' => (string)($config['error'] ?? 'Stock movement Telegram is not configured.'),
        ]];
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";
    $caption = function_exists('mb_substr') ? mb_substr($caption, 0, 1000) : substr($caption, 0, 1000);
    $results = [];
    foreach ($targets as $target) {
        $chatId = trim((string)($target['chat_id'] ?? ''));
        if ($chatId === '') {
            continue;
        }
        $threadId = $target['thread_id'] ?? null;
        $threadId = ($threadId !== null && $threadId !== '') ? (int)$threadId : null;
        $data = [
            'chat_id' => $chatId,
            'photo' => $imageUrl,
            'caption' => $caption,
        ];
        if ($threadId !== null) {
            $data['message_thread_id'] = $threadId;
        }

        $raw = telegram_http_post_form($url, $data);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $ok = is_array($decoded) && !empty($decoded['ok']);
        $error = '';
        if (!$ok) {
            $error = is_array($decoded) && isset($decoded['description'])
                ? (string)$decoded['description']
                : 'Telegram photo API error or no response';
            error_log('Stock movement Telegram photo failed for chat ' . $chatId . ($threadId !== null ? ' topic ' . $threadId : '') . ': ' . $error);
        }
        $results[] = [
            'ok' => $ok,
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'error' => $error,
        ];
    }

    return $results;
}

function sendStockOperationTelegramNotification(array $movements, string $createdBy, ?string $imageUrl = null): void {
    if (empty($movements)) {
        return;
    }

    try {
        $lines = [];
        $lines[] = 'Stock Movement Recorded';
        $lines[] = 'By: ' . ($createdBy !== '' ? $createdBy : 'System');
        $lines[] = 'Time: ' . date('Y-m-d H:i:s');
        $lines[] = 'Items: ' . count($movements);
        $lines[] = '';

        foreach (array_slice($movements, 0, 12) as $index => $movement) {
            $lines[] = ($index + 1) . '. ' . stockOperationMovementLabel((string)($movement['movement_type'] ?? ''));
            $lines[] = 'Product: ' . (string)($movement['product_name'] ?? '');
            $lines[] = 'Qty: ' . stockOperationFormatQty((float)($movement['quantity'] ?? 0));
            $lines[] = 'Location: ' . stockOperationTelegramLocation($movement);
            $lines[] = 'Stock: ' . stockOperationFormatQty((float)($movement['previous_stock'] ?? 0)) . ' -> ' . stockOperationFormatQty((float)($movement['new_stock'] ?? 0));
            if (!empty($movement['reference_type']) || !empty($movement['reference_id'])) {
                $ref = trim((string)($movement['reference_type'] ?? '') . ' ' . (string)($movement['reference_id'] ?? ''));
                $lines[] = 'Reference: ' . $ref;
            }
            if (!empty($movement['notes'])) {
                $lines[] = 'Note: ' . (string)$movement['notes'];
            }
            $lines[] = '';
        }

        if (count($movements) > 12) {
            $lines[] = '...and ' . (count($movements) - 12) . ' more row(s).';
        }

        $message = trim(implode("\n", $lines));
        if ($imageUrl !== null && $imageUrl !== '') {
            $photoResults = sendStockOperationTelegramPhoto($imageUrl, $message);
            $sentPhoto = count(array_filter($photoResults, fn($result) => !empty($result['ok']))) > 0;
            if (!$sentPhoto) {
                sendStockOperationTelegramText($message . "\nImage: " . $imageUrl);
            }
            return;
        }

        sendStockOperationTelegramText($message);
    } catch (Throwable $e) {
        error_log('Stock operation Telegram notification failed: ' . $e->getMessage());
    }
}

function storeStockMovementTelegramImage(?array $file): ?string {
    if (!$file || (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please choose another image.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Uploaded image is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be 5MB or smaller.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $mime = $tmpName !== '' ? (mime_content_type($tmpName) ?: '') : '';
    if (strpos($mime, 'image/') !== 0) {
        throw new RuntimeException('Please upload an image file.');
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    $filename = 'stock_movement_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
    $storedPath = upload_store_uploaded_file($file, 'stock_movement_images', $filename, null, $mime);
    return $storedPath !== '' ? uploaded_file_url($storedPath, 'stock_movement_images') : null;
}

function processStockOperation(PDO $pdo, array $payload, string $createdBy): array {
    $itemId = (int)($payload['item_id'] ?? 0);
    $movementType = trim((string)($payload['movement_type'] ?? ''));
    $quantity = (float)($payload['quantity'] ?? 0);
    $referenceType = trim((string)($payload['reference_type'] ?? 'adjustment'));
    $referenceId = trim((string)($payload['reference_id'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));
    $unitCost = (float)($payload['unit_cost'] ?? 0);
    $locationId = (int)($payload['location_id'] ?? 0);
    $fromLocationId = (int)($payload['from_location_id'] ?? 0);
    $toLocationId = (int)($payload['to_location_id'] ?? 0);

    if (!$itemId || $movementType === '') {
        return ['ok' => false, 'error' => 'Item and type are required.'];
    }

    $productInfo = getStockProductInfo($pdo, $itemId);
    if (!$productInfo) {
        return ['ok' => false, 'error' => 'Product not found.'];
    }

    $productName = $productInfo['name'];
    $isProductSet = ($productInfo['product_type'] ?? '') === 'set';

    if ($movementType === 'transfer') {
        if ($fromLocationId <= 0 || $toLocationId <= 0) {
            return ['ok' => false, 'error' => 'Transfer requires both from and to locations.'];
        }
        if ($fromLocationId === $toLocationId) {
            return ['ok' => false, 'error' => 'From and to locations must be different.'];
        }
        if ($quantity <= 0) {
            return ['ok' => false, 'error' => 'Transfer quantity must be greater than 0.'];
        }

        $source = getInventoryRow($pdo, $fromLocationId, $productName);
        if (!$source) {
            return ['ok' => false, 'error' => 'Product not found in source location.'];
        }
        $sourceQty = (float)$source['quantity_on_hand'];
        if ($sourceQty < $quantity) {
            return ['ok' => false, 'error' => 'Insufficient stock in source location.'];
        }

        $dest = getInventoryRow($pdo, $toLocationId, $productName, $source['sku'] ?? null);
        $effectiveCost = $unitCost > 0 ? $unitCost : (float)($source['unit_cost'] ?? 0);
        $sku = $source['sku'] ?? null;
        $sourceNew = $sourceQty - $quantity;
        $destPrev = $dest ? (float)$dest['quantity_on_hand'] : 0.0;
        $destNew = $destPrev + $quantity;
        $detail = trim($notes . ' [From:' . $fromLocationId . ' To:' . $toLocationId . ']');

        $pdo->beginTransaction();
        try {
            saveInventoryRow($pdo, $fromLocationId, $productName, $sourceNew, (float)($source['unit_cost'] ?? $effectiveCost), $sku, (int)$source['id']);
            // getInventoryRow() merges duplicate rows into one total; updating only the keeper leaves
            // other rows unchanged and inflates the source location (looks like stock increased).
            cleanupDuplicateInventoryRows($pdo, (int)$source['id'], (array)($source['duplicate_ids'] ?? []));

            saveInventoryRow($pdo, $toLocationId, $productName, $destNew, $dest ? (float)$dest['unit_cost'] : $effectiveCost, $sku, $dest ? (int)$dest['id'] : null);
            if ($dest) {
                cleanupDuplicateInventoryRows($pdo, (int)$dest['id'], (array)($dest['duplicate_ids'] ?? []));
            }

            insertStockMovement($pdo, $itemId, 'transfer', $quantity, $sourceQty, $sourceNew, $referenceType !== '' ? $referenceType : 'transfer', $referenceId, $detail, $effectiveCost, $createdBy, $fromLocationId, $toLocationId);
            if ($isProductSet) {
                $stmt = $pdo->prepare("UPDATE product_sets SET available_stock = available_stock WHERE set_name = ?");
                $stmt->execute([$productName]);
            }
            $pdo->commit();
            return [
                'ok' => true,
                'movement' => [
                    'product_name' => $productName,
                    'movement_type' => 'transfer',
                    'quantity' => $quantity,
                    'previous_stock' => $sourceQty,
                    'new_stock' => $sourceNew,
                    'reference_type' => $referenceType !== '' ? $referenceType : 'transfer',
                    'reference_id' => $referenceId,
                    'notes' => $notes,
                    'from_location_id' => $fromLocationId,
                    'to_location_id' => $toLocationId,
                ],
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    if ($locationId <= 0) {
        return ['ok' => false, 'error' => 'Storage location is required.'];
    }

    if ($movementType === 'adjustment') {
        if ($quantity == 0.0) {
            return ['ok' => false, 'error' => 'Adjustment quantity cannot be zero.'];
        }
    } elseif ($quantity <= 0) {
        return ['ok' => false, 'error' => 'Quantity must be greater than 0.'];
    }

    $existing = getInventoryRow($pdo, $locationId, $productName);
    $existingQty = $existing ? (float)$existing['quantity_on_hand'] : 0.0;
    $delta = 0.0;
    if ($movementType === 'in') {
        $delta = $quantity;
    } elseif ($movementType === 'out') {
        $delta = -$quantity;
    } elseif ($movementType === 'adjustment') {
        $delta = $quantity;
    } else {
        return ['ok' => false, 'error' => 'Unsupported movement type.'];
    }

    $newQty = $existingQty + $delta;
    if ($newQty < 0) {
        return ['ok' => false, 'error' => 'Cannot reduce stock below zero.'];
    }

    if (!$existing && $newQty > 0) {
        $fallback = getFallbackInventoryMeta($pdo, $productName);
        $effectiveCost = $unitCost > 0 ? $unitCost : (float)$fallback['unit_cost'];
        $sku = $fallback['sku'];
    } else {
        $effectiveCost = $unitCost > 0 ? $unitCost : (float)($existing['unit_cost'] ?? 0);
        $sku = $existing['sku'] ?? null;
    }

    $detail = trim($notes . ' [Location:' . $locationId . ']');

    $pdo->beginTransaction();
    try {
        saveInventoryRow($pdo, $locationId, $productName, $newQty, $effectiveCost, $sku, $existing ? (int)$existing['id'] : null);
        if ($existing) {
            cleanupDuplicateInventoryRows($pdo, (int)$existing['id'], (array)($existing['duplicate_ids'] ?? []));
        }
        $fromLoc = null;
        $toLoc = null;
        if ($movementType === 'in') {
            $toLoc = $locationId;
        } elseif ($movementType === 'out' || $movementType === 'adjustment') {
            $fromLoc = $locationId;
        }
        insertStockMovement($pdo, $itemId, $movementType, $quantity, $existingQty, $newQty, $referenceType, $referenceId, $detail, $effectiveCost, $createdBy, $fromLoc, $toLoc);
        if ($isProductSet) {
            $stmt = $pdo->prepare("UPDATE product_sets SET available_stock = available_stock + ? WHERE set_name = ?");
            $stmt->execute([$delta, $productName]);
            if ($movementType === 'in') {
                $stmt = $pdo->prepare("UPDATE product_sets SET total_created = total_created + ? WHERE set_name = ?");
                $stmt->execute([$quantity, $productName]);
            }
        }
        $pdo->commit();
        return [
            'ok' => true,
            'movement' => [
                'product_name' => $productName,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'previous_stock' => $existingQty,
                'new_stock' => $newQty,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'location_id' => $locationId,
            ],
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

ensureStockDeliverySlipHistory($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_delivery_slip_history') {
    require_role_or_permission(['admin'], 'stock_operations.view');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $receiverName = trim((string)($_POST['receiver_name'] ?? ''));
        $receiverPhone = trim((string)($_POST['receiver_phone'] ?? ''));
        $transferTo = trim((string)($_POST['transfer_to'] ?? ''));
        $slipTitle = trim((string)($_POST['slip_title'] ?? 'STOCK MOVEMENT SLIP'));
        $movementTypeLabel = trim((string)($_POST['movement_type_label'] ?? 'Mixed Types'));
        $locationLabel = trim((string)($_POST['location_label'] ?? 'Mixed Locations'));
        $filterLabel = trim((string)($_POST['filter_label'] ?? ''));
        $movementIds = json_decode((string)($_POST['movement_ids'] ?? '[]'), true);
        $items = json_decode((string)($_POST['items_json'] ?? '[]'), true);
        $qrPayload = (string)($_POST['qr_payload'] ?? '');
        $itemCount = max(0, (int)($_POST['item_count'] ?? 0));
        $totalQty = (float)($_POST['total_qty'] ?? 0);
        $totalIn = (float)($_POST['total_in'] ?? 0);
        $totalOut = (float)($_POST['total_out'] ?? 0);

        if ($receiverName === '' || $receiverPhone === '') {
            throw new RuntimeException('Name and phone number are required.');
        }
        if (!is_array($movementIds) || empty($movementIds)) {
            throw new RuntimeException('Please select at least one stock movement.');
        }
        if (!is_array($items)) {
            $items = [];
        }

        $cleanMovementIds = [];
        foreach ($movementIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $cleanMovementIds[] = $id;
            }
        }
        $cleanMovementIds = array_values(array_unique($cleanMovementIds));
        if (empty($cleanMovementIds)) {
            throw new RuntimeException('Selected stock movements are invalid.');
        }

        $slipCode = stockDeliverySlipCode();
        if ($qrPayload !== '') {
            $qrPayload = str_replace('Slip: pending', 'Slip: ' . $slipCode, $qrPayload);
        }
        $userId = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
        $createdByName = trim((string)($currentUser['name'] ?? ''));
        if ($createdByName === '') {
            $createdByName = $stockMovementCreatedBy;
        }

        $stmt = $pdo->prepare("
            INSERT INTO stock_delivery_slip_history
            (slip_code, receiver_name, receiver_phone, transfer_to, slip_title, movement_type_label, location_label, filter_label,
             item_count, total_qty, total_in, total_out, movement_ids, items_json, qr_payload, created_by_user_id, created_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $slipCode,
            $receiverName,
            $receiverPhone,
            $transferTo !== '' ? $transferTo : null,
            $slipTitle,
            $movementTypeLabel,
            $locationLabel,
            $filterLabel !== '' ? $filterLabel : null,
            $itemCount,
            $totalQty,
            $totalIn,
            $totalOut,
            json_encode($cleanMovementIds),
            json_encode($items, JSON_UNESCAPED_UNICODE),
            $qrPayload,
            $userId,
            $createdByName,
        ]);

        echo json_encode([
            'success' => true,
            'slip_code' => $slipCode,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery_slip_receiver') {
    require_role_or_permission(['admin'], 'stock_operations.view');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $slipCode = trim((string)($_POST['slip_code'] ?? ''));
        $receiverName = trim((string)($_POST['receiver_name'] ?? ''));
        $receiverPhone = trim((string)($_POST['receiver_phone'] ?? ''));
        $transferTo = trim((string)($_POST['transfer_to'] ?? ''));
        $qrPayload = (string)($_POST['qr_payload'] ?? '');

        if ($slipCode === '') {
            throw new RuntimeException('Delivery slip code is required.');
        }
        if ($receiverName === '' || $receiverPhone === '') {
            throw new RuntimeException('Name and phone number are required.');
        }

        $stmt = $pdo->prepare("
            UPDATE stock_delivery_slip_history
            SET receiver_name = ?, receiver_phone = ?, transfer_to = ?, qr_payload = ?
            WHERE slip_code = ?
        ");
        $stmt->execute([
            $receiverName,
            $receiverPhone,
            $transferTo !== '' ? $transferTo : null,
            $qrPayload,
            $slipCode,
        ]);

        echo json_encode([
            'success' => true,
            'slip_code' => $slipCode,
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_stock_movement_telegram_test') {
    require_role_or_permission(['admin'], 'stock_operations.view');
    header('Content-Type: application/json; charset=utf-8');

    try {
        $testText = "Stock Movement Telegram Test\n"
            . 'By: ' . ($stockMovementCreatedBy !== '' ? $stockMovementCreatedBy : 'System') . "\n"
            . 'Time: ' . date('Y-m-d H:i:s') . "\n"
            . 'Page: Stock Operations';
        $results = sendStockOperationTelegramText($testText);
        $sentCount = count(array_filter($results, fn($result) => !empty($result['ok'])));

        echo json_encode([
            'success' => $sentCount > 0,
            'sent_count' => $sentCount,
            'targets' => $results,
            'message' => $sentCount > 0 ? 'Stock movement Telegram test sent.' : 'Stock movement Telegram test failed.',
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'targets' => [],
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stockMovementImageUrl = null;
    $stockMovementUploadError = '';
    if (isset($_POST['add_movements']) || isset($_POST['add_movement'])) {
        try {
            $stockMovementImageUrl = storeStockMovementTelegramImage($_FILES['stock_movement_image'] ?? null);
        } catch (Throwable $e) {
            $stockMovementUploadError = $e->getMessage();
        }
    }

    if (isset($_POST['add_movements']) && isset($_POST['movements']) && is_array($_POST['movements'])) {
        $notesAll = trim((string)($_POST['movements']['__notes_all__'] ?? ''));
        $errors = [];
        $successCount = 0;
        $telegramMovements = [];
        if ($stockMovementUploadError !== '') {
            $message = $stockMovementUploadError;
            $messageType = 'danger';
        } else {
            foreach ($_POST['movements'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (($row['notes'] ?? '') === '' && $notesAll !== '') {
                    $row['notes'] = $notesAll;
                }
                $result = processStockOperation($pdo, $row, $stockMovementCreatedBy);
                if ($result['ok']) {
                    $successCount++;
                    if (!empty($result['movement']) && is_array($result['movement'])) {
                        $telegramMovements[] = $result['movement'];
                    }
                } else {
                    $errors[] = $result['error'];
                }
            }
            if ($successCount > 0) {
                $message = $successCount . ' stock movement(s) recorded successfully!';
                $messageType = empty($errors) ? 'success' : 'warning';
                sendStockOperationTelegramNotification($telegramMovements, $stockMovementCreatedBy, $stockMovementImageUrl);
            } else {
                $message = !empty($errors) ? implode(' ', array_unique($errors)) : 'No valid rows to record.';
                $messageType = 'danger';
            }
        }
    } elseif (isset($_POST['add_movement'])) {
        if ($stockMovementUploadError !== '') {
            $message = $stockMovementUploadError;
            $messageType = 'danger';
        } else {
            $result = processStockOperation($pdo, $_POST, $stockMovementCreatedBy);
            if ($result['ok']) {
                $message = 'Stock movement recorded successfully!';
                $messageType = 'success';
                $telegramMovements = [];
                if (!empty($result['movement']) && is_array($result['movement'])) {
                    $telegramMovements[] = $result['movement'];
                }
                sendStockOperationTelegramNotification($telegramMovements, $stockMovementCreatedBy, $stockMovementImageUrl);
            } else {
                $message = $result['error'];
                $messageType = 'danger';
            }
        }
    }

    $_SESSION['stock_operations_flash'] = [
        'message' => $message,
        'type' => $messageType,
    ];

    $redirectParams = [];
    foreach (['item_filter', 'movement_filter', 'product_type_filter', 'date_from', 'date_to'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $redirectParams[$key] = (string)$_GET[$key];
        }
    }
    $redirectUrl = $_SERVER['PHP_SELF'] . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Get filter parameters
$item_filter = $_GET['item_filter'] ?? '';
$movement_filter = $_GET['movement_filter'] ?? '';
$product_type_filter = $_GET['product_type_filter'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build movements query
$query = "
    SELECT sm.*, p.name as item_name, CONCAT('PROD-', p.id) as item_code, p.name as unit, COALESCE(p.product_type, 'normal') as product_type,
           COALESCE(
               NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE ux.username COLLATE utf8mb4_unicode_ci = sm.created_by COLLATE utf8mb4_unicode_ci LIMIT 1), ''),
               NULLIF((SELECT ux.name COLLATE utf8mb4_unicode_ci FROM users ux WHERE CAST(ux.id AS CHAR) COLLATE utf8mb4_unicode_ci = sm.created_by COLLATE utf8mb4_unicode_ci LIMIT 1), ''),
               sm.created_by COLLATE utf8mb4_unicode_ci
           ) as created_by_name
    FROM stock_movements sm
    JOIN products p ON sm.item_id = p.id
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
";

$params = [$date_from, $date_to];

if (!empty($item_filter)) {
    $query .= " AND sm.item_id = ?";
    $params[] = $item_filter;
}

if (!empty($movement_filter)) {
    $query .= " AND sm.movement_type = ?";
    $params[] = $movement_filter;
}

if (!empty($product_type_filter)) {
    $query .= " AND COALESCE(p.product_type, 'normal') = ?";
    $params[] = $product_type_filter;
}

$query .= " ORDER BY sm.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get items for dropdown - normal products only
$stmt = $pdo->query("
    SELECT
        p.id,
        p.name as item_name,
        CONCAT('PROD-', p.id) as item_code
    FROM products p
    WHERE p.active = 1
      AND COALESCE(p.product_type, 'normal') = 'normal'
    ORDER BY p.name
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$totalMovements = count($movements);
$stockInMovements = array_filter($movements, function($m) { return $m['movement_type'] === 'in'; });
$stockOutMovements = array_filter($movements, function($m) { return $m['movement_type'] === 'out'; });
$totalStockIn = array_sum(array_column($stockInMovements, 'quantity'));
$totalStockOut = array_sum(array_column($stockOutMovements, 'quantity'));
$stockPrintLogo = get_default_logo($pdo);
$stockPrintLogoUrl = $stockPrintLogo && !empty($stockPrintLogo['file_path'])
    ? uploaded_file_url($stockPrintLogo['file_path'], 'logos')
    : rtrim($BASE_URL ?? '', '/') . '/public/image.png';

require_once __DIR__ . '/../layout/header.php';
?>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-arrow-left-right me-2"></i>Stock Operations</h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-success" id="stockMovementTelegramTestBtn">
                            <i class="bi bi-telegram me-1"></i>Telegram Test
                        </button>
                        <a href="delivery_slip_history.php" class="btn btn-outline-primary">
                            <i class="bi bi-clock-history me-1"></i>Delivery Slip History
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMovementModal">
                            <i class="bi bi-plus-lg me-1"></i>Add Movement
                        </button>
                    </div>
                </div>
                <div class="small mb-3 d-none" id="stockMovementTelegramTestStatus"></div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mb-1"><?= number_format($totalMovements) ?></h3>
                                <p class="text-muted mb-0">Total Movements</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mb-1">+<?= number_format($totalStockIn, 2) ?></h3>
                                <p class="text-muted mb-0">Stock Added</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mb-1">-<?= number_format($totalStockOut, 2) ?></h3>
                                <p class="text-muted mb-0">Stock Removed</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info mb-1">$<?= number_format(array_sum(array_column($movements, 'total_cost')), 2) ?></h3>
                                <p class="text-muted mb-0">Total Value</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Product</label>
                                <select name="item_filter" class="form-control">
                                    <option value="">All Products</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= $item['id'] ?>" <?= ($item_filter == $item['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Movement Type</label>
                                <select name="movement_filter" class="form-control">
                                    <option value="">All Types</option>
                                    <option value="in" <?= ($movement_filter === 'in') ? 'selected' : '' ?>>Stock In</option>
                                    <option value="out" <?= ($movement_filter === 'out') ? 'selected' : '' ?>>Stock Out</option>
                                    <option value="adjustment" <?= ($movement_filter === 'adjustment') ? 'selected' : '' ?>>Adjustment</option>
                                    <option value="transfer" <?= ($movement_filter === 'transfer') ? 'selected' : '' ?>>Transfer</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Product Type</label>
                                <select name="product_type_filter" class="form-control">
                                    <option value="">All Types</option>
                        <option value="normal" <?= ($product_type_filter === 'normal') ? 'selected' : '' ?>>Item</option>
                                    <option value="set" <?= ($product_type_filter === 'set') ? 'selected' : '' ?>>Set</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search me-1"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Movements Table -->
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0">Stock Movements (<?= count($movements) ?>)</h5>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="stockPrintSelectedCount">0 selected</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="stockPrintClearBtn" disabled>
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </button>
                            <button type="button" class="btn btn-sm btn-success" id="stockPrintBtn" disabled>
                                <i class="bi bi-printer me-1"></i>Print Selected
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="movementsTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:44px;" class="text-center">
                                            <input type="checkbox" class="form-check-input" id="stockPrintSelectAll" aria-label="Select all stock movements">
                                        </th>
                                        <th style="width:64px;">No</th>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th>Product Type</th>
                                        <th>Location</th>
                                        <th>Quantity</th>
                                        <th>Previous</th>
                                        <th>New Stock</th>
                                        <th>Reference</th>
                                        <th>Cost</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($movements)): ?>
                                        <tr>
                                            <td colspan="13" class="text-center py-4">
                                                <i class="bi bi-info-circle text-muted fs-1 mb-2"></i>
                                                <p class="text-muted mb-0">No stock movements found.</p>
                                                <p class="text-muted small">Click "Add Movement" to record your first stock movement.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($movements as $movementIndex => $movement): ?>
                                            <?php
                                            $notesText = (string)($movement['notes'] ?? '');
                                            $stockPrintLocationText = '-';
                                            if ($movement['movement_type'] === 'transfer') {
                                                if (preg_match('/\[From:(\d+)\s+To:(\d+)\]/', $notesText, $m)) {
                                                    $fromId = (int)$m[1];
                                                    $toId = (int)$m[2];
                                                    $fromLabel = $locationLabels[$fromId] ?? ('#' . $fromId);
                                                    $toLabel = $locationLabels[$toId] ?? ('#' . $toId);
                                                    $stockPrintLocationText = $fromLabel . ' -> ' . $toLabel;
                                                }
                                            } elseif (preg_match('/\[Location:(\d+)\]/', $notesText, $m)) {
                                                $locationId = (int)$m[1];
                                                $stockPrintLocationText = $locationLabels[$locationId] ?? ('#' . $locationId);
                                            }
                                            $stockPrintTypeText = match ((string)$movement['movement_type']) {
                                                'in' => 'Stock In',
                                                'out' => 'Stock Out',
                                                'adjustment' => 'Adjustment',
                                                'transfer' => 'Transfer',
                                                default => ucfirst((string)$movement['movement_type']),
                                            };
                                            $stockPrintQtyValue = (float)($movement['quantity'] ?? 0);
                                            $stockPrintQtyText = rtrim(rtrim(number_format($stockPrintQtyValue, 2, '.', ''), '0'), '.');
                                            if ($stockPrintQtyText === '') {
                                                $stockPrintQtyText = '0';
                                            }
                                            $stockPrintRefTypeText = match ((string)($movement['reference_type'] ?? '')) {
                                                'purchase' => 'Purchase',
                                                'sale' => 'Sale',
                                                'return' => 'Return',
                                                'transfer' => 'Transfer',
                                                default => 'Adjustment',
                                            };
                                            $stockPrintReferenceText = $stockPrintRefTypeText;
                                            if (!empty($movement['reference_id'])) {
                                                $stockPrintReferenceText .= ' #' . (string)$movement['reference_id'];
                                            }
                                            $stockPrintUserText = trim((string)($movement['created_by_name'] ?? $movement['created_by'] ?? ''));
                                            ?>
                                            <tr
                                                data-stock-print-row="1"
                                                data-movement-id="<?= (int)$movement['id'] ?>"
                                                data-product="<?= htmlspecialchars((string)$movement['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-qty="<?= htmlspecialchars($stockPrintQtyText, ENT_QUOTES, 'UTF-8') ?>"
                                                data-qty-value="<?= htmlspecialchars((string)$stockPrintQtyValue, ENT_QUOTES, 'UTF-8') ?>"
                                                data-type="<?= htmlspecialchars($stockPrintTypeText, ENT_QUOTES, 'UTF-8') ?>"
                                                data-location="<?= htmlspecialchars($stockPrintLocationText, ENT_QUOTES, 'UTF-8') ?>"
                                                data-date="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($movement['created_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                                data-reference="<?= htmlspecialchars($stockPrintReferenceText, ENT_QUOTES, 'UTF-8') ?>"
                                                data-user="<?= htmlspecialchars($stockPrintUserText !== '' ? $stockPrintUserText : 'Unknown User', ENT_QUOTES, 'UTF-8') ?>">
                                                <td class="text-center">
                                                    <input type="checkbox" class="form-check-input stock-print-checkbox" aria-label="Select stock movement <?= (int)$movementIndex + 1 ?>">
                                                </td>
                                                <td class="fw-semibold"><?= (int)$movementIndex + 1 ?></td>
                                                <td>
                                                    <div class="small">
                                                        <div class="fw-bold"><?= date('M j, Y', strtotime($movement['created_at'])) ?></div>
                                                        <div class="text-muted"><?= date('g:i A', strtotime($movement['created_at'])) ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong class="text-primary"><?= htmlspecialchars($movement['item_name']) ?></strong>
                                                        <br><small class="text-muted"><?= htmlspecialchars($movement['item_code']) ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $typeBadge = '';
                                                    switch ($movement['movement_type']) {
                                                        case 'in':
                                                            $typeBadge = '<span class="badge bg-success">Stock In</span>';
                                                            break;
                                                        case 'out':
                                                            $typeBadge = '<span class="badge bg-danger">Stock Out</span>';
                                                            break;
                                                        case 'adjustment':
                                                            $typeBadge = '<span class="badge bg-warning text-dark">Adjustment</span>';
                                                            break;
                                                        case 'transfer':
                                                            $typeBadge = '<span class="badge bg-info">Transfer</span>';
                                                            break;
                                                    }
                                                    echo $typeBadge;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $productType = strtolower((string)($movement['product_type'] ?? 'normal'));
                                                    if ($productType === 'set') {
                                                        echo '<span class="badge bg-secondary">Set</span>';
                                                    } else {
                                            echo '<span class="badge bg-light text-dark border">Item</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php
                                                        $locationDisplay = '<span class="text-muted">-</span>';
                                                        if ($movement['movement_type'] === 'transfer') {
                                                            if (preg_match('/\[From:(\d+)\s+To:(\d+)\]/', $notesText, $m)) {
                                                                $fromId = (int)$m[1];
                                                                $toId = (int)$m[2];
                                                                $fromLabel = $locationLabels[$fromId] ?? ('#' . $fromId);
                                                                $toLabel = $locationLabels[$toId] ?? ('#' . $toId);
                                                                $locationDisplay = '<span>' . htmlspecialchars($fromLabel) . ' <i class="bi bi-arrow-right mx-1"></i> ' . htmlspecialchars($toLabel) . '</span>';
                                                            }
                                                        } else {
                                                            if (preg_match('/\[Location:(\d+)\]/', $notesText, $m)) {
                                                                $locationId = (int)$m[1];
                                                                $locationDisplay = '<span>' . htmlspecialchars($locationLabels[$locationId] ?? ('#' . $locationId)) . '</span>';
                                                            }
                                                        }
                                                        echo $locationDisplay;
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $quantityDisplay = '';
                                                    if ($movement['movement_type'] === 'in' || $movement['movement_type'] === 'adjustment') {
                                                        $quantityDisplay = '<span class="text-success">+' . number_format($movement['quantity'], 2) . '</span>';
                                                    } else {
                                                        $quantityDisplay = '<span class="text-danger">-' . number_format($movement['quantity'], 2) . '</span>';
                                                    }
                                                    echo $quantityDisplay . ' ' . htmlspecialchars($movement['unit']);
                                                    ?>
                                                </td>
                                                <td><?= number_format($movement['previous_stock'], 2) ?> <?= htmlspecialchars($movement['unit']) ?></td>
                                                <td><strong><?= number_format($movement['new_stock'], 2) ?> <?= htmlspecialchars($movement['unit']) ?></strong></td>
                                                <td>
                                                    <div class="small">
                                                        <?php
                                                        $refTypeDisplay = '';
                                                        switch ($movement['reference_type']) {
                                                            case 'purchase':
                                                                $refTypeDisplay = 'Purchase';
                                                                break;
                                                            case 'sale':
                                                                $refTypeDisplay = 'Sale';
                                                                break;
                                                            case 'return':
                                                                $refTypeDisplay = 'Return';
                                                                break;
                                                            case 'transfer':
                                                                $refTypeDisplay = 'Transfer';
                                                                break;
                                                            default:
                                                                $refTypeDisplay = 'Adjustment';
                                                        }
                                                        echo '<strong>' . $refTypeDisplay . '</strong>';
                                                        if ($movement['reference_id']) {
                                                            echo '<br><code>' . htmlspecialchars($movement['reference_id']) . '</code>';
                                                        }
                                                        if ($notesText !== '') {
                                                            $cleanNotes = preg_replace('/\s*\[(Location:\d+|From:\d+\s+To:\d+)\]\s*/', ' ', $notesText);
                                                            $cleanNotes = trim((string)$cleanNotes);
                                                            if ($cleanNotes !== '') {
                                                                echo '<br><span class="text-muted small">' . htmlspecialchars($cleanNotes) . '</span>';
                                                            }
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($movement['unit_cost'] > 0): ?>
                                                        <div class="small">
                                                            <div>$<?= number_format($movement['unit_cost'], 2) ?>/unit</div>
                                                            <div class="text-muted">$<?= number_format($movement['total_cost'], 2) ?> total</div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php $displayUser = trim((string)($movement['created_by_name'] ?? $movement['created_by'] ?? '')); ?>
                                                    <div class="small">
                                                        <div class="d-flex align-items-center gap-1">
                                                            <i class="bi bi-person-circle"></i>
                                                            <span class="fw-semibold"><?= htmlspecialchars($displayUser !== '' ? $displayUser : 'Unknown User') ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Add Movement Modal -->
<div class="modal fade" id="addMovementModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Stock Movement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body" style="max-height:80vh; overflow:auto;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Movements</h6>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addMovementRow()"><i class="bi bi-plus-lg me-1"></i>Add Row</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addManyRows(5)"><i class="bi bi-plus-square-dotted me-1"></i>Add 5 Rows</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:20%">Product</th>
                                    <th style="width:11%">Type</th>
                                    <th style="width:14%">Location</th>
                                    <th style="width:15%">From</th>
                                    <th style="width:15%">To</th>
                                    <th style="width:9%">Qty<br><small class="text-muted">Adj can be negative</small></th>
                                    <th style="width:10%">Ref Type</th>
                                    <th style="width:11%">Ref ID</th>
                                    <th style="width:10%">Cost</th>
                                    <th style="width:4%"></th>
                                </tr>
                            </thead>
                            <tbody id="mvRows"></tbody>
                        </table>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Notes (applies to all rows)</label>
                        <textarea name="movements[__notes_all__]" class="form-control" rows="2" placeholder="Optional notes that apply to all rows"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="stockMovementImage">Image for Telegram</label>
                        <input type="file" name="stock_movement_image" id="stockMovementImage" class="form-control" accept="image/*">
                        <div class="form-text">Optional. Image uploads to Cloudflare R2 and the public URL is included in Telegram.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_movements" class="btn btn-primary">Record Movements</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="stockSlipHistoryModal" tabindex="-1" aria-labelledby="stockSlipHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="stockSlipHistoryModalLabel">
                        <i class="bi bi-receipt me-2"></i>Delivery Slip
                    </h5>
                    <div class="text-muted small" id="stockSlipHistorySubtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="border rounded p-3 bg-light mb-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Receiver</div>
                            <div class="fw-semibold" id="stockSlipHistoryReceiver">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Phone</div>
                            <div class="fw-semibold" id="stockSlipHistoryPhone">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Transfer To</div>
                            <div class="fw-semibold" id="stockSlipHistoryTransferTo">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Type</div>
                            <div class="fw-semibold" id="stockSlipHistoryType">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Location</div>
                            <div class="fw-semibold" id="stockSlipHistoryLocation">-</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Created By</div>
                            <div class="fw-semibold" id="stockSlipHistoryCreatedBy">-</div>
                        </div>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="border rounded p-2 text-center">
                            <div class="text-muted small">Items</div>
                            <div class="fw-bold" id="stockSlipHistoryItemCount">0</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2 text-center">
                            <div class="text-muted small">Total In</div>
                            <div class="fw-bold text-success" id="stockSlipHistoryTotalIn">0</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2 text-center">
                            <div class="text-muted small">Total Out</div>
                            <div class="fw-bold text-danger" id="stockSlipHistoryTotalOut">0</div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:52px;">No</th>
                                <th>Product</th>
                                <th class="text-center">Qty</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody id="stockSlipHistoryItems"></tbody>
                    </table>
                </div>
                <div>
                    <div class="text-muted small mb-1">QR Data</div>
                    <pre class="border rounded bg-light p-2 small mb-0" id="stockSlipHistoryQrPayload" style="white-space:pre-wrap;"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="stockSlipHistoryEditBtn">
                    <i class="bi bi-pencil-square me-1"></i>Edit
                </button>
                <button type="button" class="btn btn-success" id="stockSlipHistoryReprintBtn">
                    <i class="bi bi-printer me-1"></i>Reprint
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="stockPrintReceiverModal" tabindex="-1" aria-labelledby="stockPrintReceiverModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="stockPrintReceiverForm">
            <div class="modal-header">
                <h5 class="modal-title" id="stockPrintReceiverModalLabel">
                    <i class="bi bi-truck me-2"></i>Transfer Receiver
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="stockPrintReceiverName">Name Receive</label>
                    <input type="text" class="form-control form-control-lg" id="stockPrintReceiverName" autocomplete="name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="stockPrintReceiverPhone">Phone Number</label>
                    <input type="tel" class="form-control form-control-lg" id="stockPrintReceiverPhone" autocomplete="tel" required>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="stockPrintReceiverPlace">Location</label>
                    <input type="text" class="form-control form-control-lg" id="stockPrintReceiverPlace" placeholder="Branch, shop, warehouse, or customer place">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="stockPrintReceiverSubmitBtn">
                    <i class="bi bi-printer me-1"></i>Print Receipt
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const stockPrintLogoUrl = <?= json_encode($stockPrintLogoUrl, JSON_UNESCAPED_SLASHES) ?>;
const stockPrintFilterLabel = <?= json_encode(date('M j, Y', strtotime($date_from)) . ' - ' . date('M j, Y', strtotime($date_to))) ?>;
let stockSlipHistoryCurrent = null;
let stockPrintReceiverMode = 'selected';
let stockPrintEditingSlip = null;

function stockPrintEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function stockPrintFormatQty(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function stockSlipHistoryFormatDate(value) {
    if (!value) return '';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleString();
}

function stockSlipNormalizeData(slip) {
    const data = slip || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const rows = items.map((item) => ({
        movementId: Number(item.movement_id || 0),
        product: item.product || '',
        qty: item.qty || '0',
        qtyValue: Number(item.qty || 0),
        type: item.type || data.movement_type_label || '',
        location: item.location || data.location_label || '',
        date: item.date || '',
        reference: item.reference || ''
    }));
    const totalQty = Number(data.total_qty || rows.reduce((sum, row) => sum + (Number.isFinite(row.qtyValue) ? row.qtyValue : 0), 0));

    return {
        ...data,
        items,
        rows,
        receiver: {
            name: data.receiver_name || '',
            phone: data.receiver_phone || '',
            place: data.transfer_to || ''
        },
        title: data.slip_title || data.movement_type_label || 'TRANSFER SLIP',
        totalQty,
        slipCode: data.slip_code || '',
        createdAt: data.created_at || ''
    };
}

function stockSlipHistoryBuildQrPayload(data) {
    const slip = stockSlipNormalizeData(data);
    return [
        slip.title,
        'Slip: ' + slip.slipCode,
        slip.createdAt ? 'Date: ' + slip.createdAt : '',
        slip.location_label ? 'Stock Location: ' + slip.location_label : '',
        slip.movement_type_label ? 'Type: ' + slip.movement_type_label : '',
        'Name Receive: ' + slip.receiver.name,
        'Phone Number: ' + slip.receiver.phone,
        slip.receiver.place ? 'Location: ' + slip.receiver.place : '',
        'Items: ' + (slip.item_count || slip.rows.length),
        'In: ' + stockPrintFormatQty(slip.total_in || 0),
        'Out: ' + stockPrintFormatQty(slip.total_out || 0)
    ].filter(Boolean).join('\n');
}

function stockSlipHistoryOpen(slip) {
    const data = stockSlipNormalizeData(slip);
    stockSlipHistoryCurrent = data;
    document.getElementById('stockSlipHistoryModalLabel').innerHTML =
        '<i class="bi bi-receipt me-2"></i>Delivery Slip ' + stockPrintEscapeHtml(data.slip_code || '');
    document.getElementById('stockSlipHistorySubtitle').textContent = stockSlipHistoryFormatDate(data.created_at || '');
    document.getElementById('stockSlipHistoryReceiver').textContent = data.receiver_name || '-';
    document.getElementById('stockSlipHistoryPhone').textContent = data.receiver_phone || '-';
    document.getElementById('stockSlipHistoryTransferTo').textContent = data.transfer_to || '-';
    document.getElementById('stockSlipHistoryType').textContent = data.movement_type_label || '-';
    document.getElementById('stockSlipHistoryLocation').textContent = data.location_label || '-';
    document.getElementById('stockSlipHistoryCreatedBy').textContent = data.created_by_name || '-';
    document.getElementById('stockSlipHistoryItemCount').textContent = String(data.item_count || (data.items || []).length || 0);
    document.getElementById('stockSlipHistoryTotalIn').textContent = stockPrintFormatQty(data.total_in || 0);
    document.getElementById('stockSlipHistoryTotalOut').textContent = stockPrintFormatQty(data.total_out || 0);
    document.getElementById('stockSlipHistoryQrPayload').textContent = data.qr_payload || '';

    const itemsBody = document.getElementById('stockSlipHistoryItems');
    const items = Array.isArray(data.items) ? data.items : [];
    if (!items.length) {
        itemsBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No item detail saved.</td></tr>';
    } else {
        itemsBody.innerHTML = items.map((item, index) => `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td>${stockPrintEscapeHtml(item.product || '')}</td>
                <td class="text-center">${stockPrintEscapeHtml(item.qty || '')}</td>
                <td>${stockPrintEscapeHtml(item.reference || '-')}</td>
            </tr>
        `).join('');
    }

    const modalEl = document.getElementById('stockSlipHistoryModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function stockSlipHistorySlipFromButton(button) {
    return stockSlipNormalizeData(JSON.parse(button.getAttribute('data-slip') || '{}'));
}

function stockSlipHistoryBindViewButton(button) {
    if (!button || button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', function() {
        try {
            stockSlipHistoryOpen(stockSlipHistorySlipFromButton(button));
        } catch (error) {
            alert('Unable to open delivery slip detail.');
        }
    });
}

function stockSlipHistoryBindEditButton(button) {
    if (!button || button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', function() {
        try {
            stockPrintOpenReceiverModal({ mode: 'history-edit', slip: stockSlipHistorySlipFromButton(button) });
        } catch (error) {
            alert('Unable to edit delivery slip.');
        }
    });
}

function stockSlipHistoryBindReprintButton(button) {
    if (!button || button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', function() {
        try {
            stockPrintReprintHistorySlip(stockSlipHistorySlipFromButton(button));
        } catch (error) {
            alert('Unable to reprint delivery slip.');
        }
    });
}

function stockSlipHistoryBindButtons(root = document) {
    root.querySelectorAll('.stock-slip-view-btn').forEach(stockSlipHistoryBindViewButton);
    root.querySelectorAll('.stock-slip-edit-btn').forEach(stockSlipHistoryBindEditButton);
    root.querySelectorAll('.stock-slip-reprint-btn').forEach(stockSlipHistoryBindReprintButton);
}

function stockPrintCheckedBoxes() {
    return Array.from(document.querySelectorAll('.stock-print-checkbox:checked'));
}

function stockPrintUpdateToolbar() {
    const checked = stockPrintCheckedBoxes();
    const total = document.querySelectorAll('.stock-print-checkbox').length;
    const countEl = document.getElementById('stockPrintSelectedCount');
    const printBtn = document.getElementById('stockPrintBtn');
    const clearBtn = document.getElementById('stockPrintClearBtn');
    const selectAll = document.getElementById('stockPrintSelectAll');
    if (countEl) countEl.textContent = checked.length + ' selected';
    if (printBtn) printBtn.disabled = checked.length === 0;
    if (clearBtn) clearBtn.disabled = checked.length === 0;
    if (selectAll) {
        selectAll.checked = total > 0 && checked.length === total;
        selectAll.indeterminate = checked.length > 0 && checked.length < total;
    }
}

function stockPrintMovementRows() {
    return stockPrintCheckedBoxes().map((cb) => {
        const tr = cb.closest('tr[data-stock-print-row]');
        return {
            movementId: Number(tr?.dataset.movementId || '0'),
            product: tr?.dataset.product || '',
            qty: tr?.dataset.qty || '0',
            qtyValue: Number(tr?.dataset.qtyValue || '0'),
            type: tr?.dataset.type || '',
            location: tr?.dataset.location || '',
            date: tr?.dataset.date || '',
            reference: tr?.dataset.reference || '',
            user: tr?.dataset.user || ''
        };
    });
}

function stockPrintTitleFor(rows) {
    const types = Array.from(new Set(rows.map((row) => row.type).filter(Boolean)));
    if (types.length === 1) {
        return types[0].toUpperCase() + ' SLIP';
    }
    return 'STOCK MOVEMENT SLIP';
}

function stockPrintCommonValue(rows, key, fallback) {
    const values = Array.from(new Set(rows.map((row) => row[key]).filter(Boolean)));
    return values.length === 1 ? values[0] : fallback;
}

function stockPrintReceiverDetails() {
    return {
        name: document.getElementById('stockPrintReceiverName')?.value.trim() || '',
        phone: document.getElementById('stockPrintReceiverPhone')?.value.trim() || '',
        place: document.getElementById('stockPrintReceiverPlace')?.value.trim() || ''
    };
}

function stockPrintSetReceiverSubmit(isEditing) {
    const submitBtn = document.getElementById('stockPrintReceiverSubmitBtn');
    const titleEl = document.getElementById('stockPrintReceiverModalLabel');
    if (titleEl) {
        titleEl.innerHTML = isEditing
            ? '<i class="bi bi-pencil-square me-2"></i>Edit Receiver'
            : '<i class="bi bi-truck me-2"></i>Transfer Receiver';
    }
    if (submitBtn) {
        submitBtn.className = isEditing ? 'btn btn-primary' : 'btn btn-success';
        submitBtn.innerHTML = isEditing
            ? '<i class="bi bi-check2-circle me-1"></i>Save Changes'
            : '<i class="bi bi-printer me-1"></i>Print Receipt';
    }
}

function stockPrintOpenReceiverModal(options = {}) {
    const mode = options.mode || 'selected';
    if (mode === 'selected' && !stockPrintCheckedBoxes().length) return;

    stockPrintReceiverMode = mode;
    stockPrintEditingSlip = mode === 'history-edit' ? stockSlipNormalizeData(options.slip || {}) : null;

    const modalEl = document.getElementById('stockPrintReceiverModal');
    const nameInput = document.getElementById('stockPrintReceiverName');
    const phoneInput = document.getElementById('stockPrintReceiverPhone');
    const placeInput = document.getElementById('stockPrintReceiverPlace');
    if (!modalEl || typeof bootstrap === 'undefined') {
        alert('Unable to open receiver form. Please refresh the page and try again.');
        return;
    }

    stockPrintSetReceiverSubmit(mode === 'history-edit');
    if (stockPrintEditingSlip) {
        if (nameInput) nameInput.value = stockPrintEditingSlip.receiver.name || '';
        if (phoneInput) phoneInput.value = stockPrintEditingSlip.receiver.phone || '';
        if (placeInput) placeInput.value = stockPrintEditingSlip.receiver.place || '';
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    if (mode === 'history-edit') {
        const detailModalEl = document.getElementById('stockSlipHistoryModal');
        if (detailModalEl) {
            bootstrap.Modal.getOrCreateInstance(detailModalEl).hide();
        }
    }
    modalEl.addEventListener('shown.bs.modal', function focusNameOnce() {
        modalEl.removeEventListener('shown.bs.modal', focusNameOnce);
        if (nameInput) nameInput.focus();
    });
    modal.show();
}

async function stockSlipHistorySaveReceiverEdit() {
    if (!stockPrintEditingSlip || !stockPrintEditingSlip.slipCode) {
        throw new Error('Delivery slip is not selected.');
    }

    const receiver = stockPrintReceiverDetails();
    const updatedSlip = stockSlipNormalizeData({
        ...stockPrintEditingSlip,
        receiver_name: receiver.name,
        receiver_phone: receiver.phone,
        transfer_to: receiver.place
    });
    updatedSlip.qr_payload = stockSlipHistoryBuildQrPayload(updatedSlip);

    const body = new URLSearchParams();
    body.set('action', 'update_delivery_slip_receiver');
    body.set('slip_code', updatedSlip.slipCode);
    body.set('receiver_name', receiver.name);
    body.set('receiver_phone', receiver.phone);
    body.set('transfer_to', receiver.place);
    body.set('qr_payload', updatedSlip.qr_payload);

    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || !result.success) {
        throw new Error((result && result.message) ? result.message : 'Unable to update delivery slip.');
    }

    stockSlipHistoryUpdateSlip(updatedSlip);
    stockSlipHistoryOpen(updatedSlip);
}

async function stockPrintSaveDeliverySlipHistory(payload) {
    const body = new URLSearchParams();
    body.set('action', 'save_delivery_slip_history');
    body.set('receiver_name', payload.receiver.name);
    body.set('receiver_phone', payload.receiver.phone);
    body.set('transfer_to', payload.receiver.place);
    body.set('slip_title', payload.title);
    body.set('movement_type_label', payload.type);
    body.set('location_label', payload.location);
    body.set('filter_label', stockPrintFilterLabel);
    body.set('item_count', String(payload.rows.length));
    body.set('total_qty', String(payload.totalQty));
    body.set('total_in', String(payload.totalIn));
    body.set('total_out', String(payload.totalOut));
    body.set('movement_ids', JSON.stringify(payload.rows.map((row) => row.movementId).filter((id) => id > 0)));
    body.set('items_json', JSON.stringify(payload.rows.map((row) => ({
        movement_id: row.movementId,
        product: row.product,
        qty: row.qty,
        type: row.type,
        location: row.location,
        date: row.date,
        reference: row.reference
    }))));
    body.set('qr_payload', payload.qrPayload);

    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || !result.success) {
        throw new Error((result && result.message) ? result.message : 'Unable to save delivery slip history.');
    }
    return result;
}

function stockSlipHistoryUpdateSlip(updatedSlip) {
    const slip = stockSlipNormalizeData(updatedSlip);
    const slipCode = slip.slipCode || slip.slip_code || '';
    if (!slipCode) return;

    const storedSlip = {
        ...slip,
        slip_code: slipCode,
        receiver_name: slip.receiver.name,
        receiver_phone: slip.receiver.phone,
        transfer_to: slip.receiver.place,
        qr_payload: slip.qr_payload || stockSlipHistoryBuildQrPayload(slip)
    };
    const serialized = JSON.stringify(storedSlip);

    document.querySelectorAll('[data-slip]').forEach((button) => {
        try {
            const buttonSlip = JSON.parse(button.getAttribute('data-slip') || '{}');
            if ((buttonSlip.slip_code || '') !== slipCode) return;
            button.setAttribute('data-slip', serialized);
            const row = button.closest('tr');
            if (row) {
                const receiverName = row.querySelector('.stock-slip-receiver-name');
                const receiverPhone = row.querySelector('.stock-slip-receiver-phone');
                const transferCell = row.querySelector('.stock-slip-transfer-cell');
                if (receiverName) receiverName.textContent = slip.receiver.name || '-';
                if (receiverPhone) receiverPhone.textContent = slip.receiver.phone || '';
                if (transferCell) transferCell.textContent = slip.receiver.place || '-';
            }
        } catch (error) {}
    });

    if (stockSlipHistoryCurrent && (stockSlipHistoryCurrent.slipCode || stockSlipHistoryCurrent.slip_code) === slipCode) {
        stockSlipHistoryCurrent = storedSlip;
    }
}

function stockPrintPrintHtml(printHtml) {
    try {
        const printIframe = document.createElement('iframe');
        printIframe.style.position = 'fixed';
        printIframe.style.right = '0';
        printIframe.style.bottom = '0';
        printIframe.style.width = '0';
        printIframe.style.height = '0';
        printIframe.style.border = '0';
        printIframe.style.visibility = 'hidden';
        document.body.appendChild(printIframe);
        const win = printIframe.contentWindow;
        const doc = win ? win.document : (printIframe.contentDocument || printIframe);
        doc.open();
        doc.write(printHtml);
        doc.close();
        if (win) {
            win.focus && win.focus();
            setTimeout(() => {
                try {
                    win.print();
                } catch (err) {
                    console.error('Print failed on iframe window:', err);
                }
                setTimeout(() => { try { document.body.removeChild(printIframe); } catch (e) {} }, 800);
            }, 200);
        } else {
            const fallbackWin = window.open('', '_blank');
            if (!fallbackWin) {
                alert('Please allow popup windows to print selected stock.');
                try { document.body.removeChild(printIframe); } catch (e) {}
                return;
            }
            fallbackWin.document.open();
            fallbackWin.document.write(printHtml);
            fallbackWin.document.close();
        }
    } catch (e) {
        console.error('Printing failed:', e);
        alert('Printing failed. Please try allowing popups or try again.');
    }
}

function stockPrintReceiptHtml(payload) {
    const rows = payload.rows || [];
    const receiver = payload.receiver || {};
    const title = payload.title || 'TRANSFER SLIP';
    const slipCode = payload.slipCode || '';
    const createdText = payload.createdText || new Date().toLocaleString();
    const totalQty = Number(payload.totalQty || 0);
    const qrText = encodeURIComponent(slipCode);
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + qrText;
    const itemRows = rows.map((row, index) => `
        <tr>
            <td class="tc">${index + 1}</td>
            <td>${stockPrintEscapeHtml(row.product)}</td>
            <td class="tc">${stockPrintEscapeHtml(row.qty)}</td>
        </tr>
    `).join('');

    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${stockPrintEscapeHtml(title)}</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 10px; background: #e5e7eb; color: #000; font-family: Arial, "Noto Sans Khmer", sans-serif; }
        .slip { width: 80mm; max-width: 80mm; margin: 0 auto; background: #fff; padding: 4mm 3mm; border: 1px solid #e5e7eb; box-shadow: 0 18px 40px rgba(15,23,42,.18); }
        .logo { text-align: center; min-height: 24px; margin-bottom: 4px; }
        .logo img { max-width: 28mm; max-height: 18mm; object-fit: contain; }
        .title { text-align: center; font-size: 11px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 8px; }
        .kh { font-family: "Noto Sans Khmer", Arial, sans-serif; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 3mm; }
        .meta { flex: 1; min-width: 0; font-size: 16px; line-height: 1.45; font-weight: 600; overflow-wrap: anywhere; }
        .meta strong { font-weight: 900; }
        .qr { width: 20mm; height: 20mm; object-fit: contain; flex: 0 0 20mm; }
        .qr-wrap { flex: 0 0 20mm; text-align: center; }
        .qr-text { margin-top: 2px; font-size: 7px; font-weight: 800; line-height: 1.15; overflow-wrap: anywhere; }
        .divider { border-top: 1px solid #000; margin: 8px 0 6px; }
        table { width: 100%; border-collapse: collapse; font-family: "Noto Sans Khmer", Arial, sans-serif; }
        th, td { border: 1px solid #000; padding: 4px 3px; font-size: 10px; vertical-align: middle; line-height: 1.25; overflow-wrap: anywhere; }
        th { background: #d1d5db; text-align: center; font-weight: 800; line-height: 1.2; }
        th span { display: block; font-size: 8px; font-weight: 700; }
        .tc { text-align: center; }
        tfoot td { font-weight: 900; background: #fff; }
        .total-label { text-align: right; }
        .total-value { text-align: center; font-size: 12px; }
        .footer { border-top: 1px solid #000; margin-top: 7px; padding-top: 7px; font-size: 9px; font-weight: 700; line-height: 1.45; overflow-wrap: anywhere; }
        @media print {
            @page { size: 80mm auto; margin: 0; }
            html, body { width: 80mm; margin: 0; padding: 0; background: #fff; }
            .slip { width: 80mm; max-width: 80mm; margin: 0; box-shadow: none; border: 0; }
        }
    </style>
</head>
<body>
    <div class="slip">
        <div class="logo">${stockPrintLogoUrl ? `<img src="${stockPrintEscapeHtml(stockPrintLogoUrl)}" alt="Logo">` : ''}</div>
        <div class="title kh">&#x1794;&#x17D0;&#x178E;&#x17D2;&#x178E;&#x179F;&#x17D2;&#x178F;&#x17BB;&#x1780;</div>
        <div class="top">
            <div class="meta">
                <div><span class="kh">&#x17A2;&#x17D2;&#x1793;&#x1780;&#x1791;&#x1791;&#x17BD;&#x179B;</span>: <strong>${stockPrintEscapeHtml(receiver.name)}</strong></div>
                <div><span class="kh">&#x179B;&#x17C1;&#x1781;&#x1791;&#x17BC;&#x179A;&#x179F;&#x17D0;&#x1796;&#x17D2;&#x1791;</span>: <strong>${stockPrintEscapeHtml(receiver.phone)}</strong></div>
                ${receiver.place ? `<div><span class="kh">&#x1791;&#x17B8;&#x178F;&#x17B6;&#x17C6;&#x1784;</span>: <strong>${stockPrintEscapeHtml(receiver.place)}</strong></div>` : ''}
            </div>
            <div class="qr-wrap">
                <img class="qr" src="${qrUrl}" alt="QR">
                <div class="qr-text">${stockPrintEscapeHtml(slipCode)}</div>
            </div>
        </div>
        <div class="divider"></div>
        <table>
            <thead>
                <tr>
                    <th style="width:12%;">&#x179B;.&#x179A;<span>No</span></th>
                    <th style="width:62%;">&#x1798;&#x17BB;&#x1781;&#x1791;&#x17C6;&#x1793;&#x17B7;&#x1789;<span>Product Name</span></th>
                    <th style="width:26%;">&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;<span>Qty</span></th>
                </tr>
            </thead>
            <tbody>${itemRows}</tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="total-label kh">&#x179F;&#x179A;&#x17BB;&#x1794;&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;</td>
                    <td class="total-value">${stockPrintFormatQty(totalQty)}</td>
                </tr>
            </tfoot>
        </table>
        <div class="footer">
            <div>Created: ${stockPrintEscapeHtml(createdText)}</div>
            <div>Powered by : One Night Solution</div>
        </div>
    </div>
</body>
</html>`;
}

function stockPrintReprintHistorySlip(slip) {
    const data = stockSlipNormalizeData(slip);
    if (!data.slipCode) {
        alert('Delivery slip code is missing.');
        return;
    }
    const createdAt = data.createdAt ? new Date(String(data.createdAt).replace(' ', 'T')) : null;
    const createdText = createdAt && !Number.isNaN(createdAt.getTime()) ? createdAt.toLocaleString() : (data.createdAt || new Date().toLocaleString());
    stockPrintPrintHtml(stockPrintReceiptHtml({
        rows: data.rows,
        receiver: data.receiver,
        title: data.title,
        totalQty: data.totalQty,
        slipCode: data.slipCode,
        createdText
    }));
}

function stockPrintAddHistoryRow(saved, payload) {
    const tbody = document.getElementById('stockDeliverySlipHistoryBody');
    if (!tbody || !saved || !saved.slip_code) return;

    const emptyRow = tbody.querySelector('td[colspan="10"]');
    if (emptyRow) {
        tbody.innerHTML = '';
    }

    const createdAt = saved.created_at ? new Date(saved.created_at.replace(' ', 'T')) : new Date();
    const createdText = Number.isNaN(createdAt.getTime()) ? (saved.created_at || '') : createdAt.toLocaleString();
    const slipData = {
        slip_code: saved.slip_code,
        receiver_name: payload.receiver.name,
        receiver_phone: payload.receiver.phone,
        transfer_to: payload.receiver.place || '',
        slip_title: payload.title || '',
        movement_type_label: payload.type,
        location_label: payload.location,
        filter_label: stockPrintFilterLabel,
        item_count: payload.rows.length,
        total_qty: payload.totalQty,
        total_in: payload.totalIn,
        total_out: payload.totalOut,
        items: payload.rows.map((row) => ({
            movement_id: row.movementId,
            product: row.product,
            qty: row.qty,
            type: row.type,
            location: row.location,
            date: row.date,
            reference: row.reference
        })),
        qr_payload: payload.qrPayload ? payload.qrPayload.replace('Slip: pending', 'Slip: ' + saved.slip_code) : '',
        created_at: saved.created_at || '',
        created_by_name: payload.user
    };
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><code>${stockPrintEscapeHtml(saved.slip_code)}</code></td>
        <td class="stock-slip-receiver-cell">
            <div class="fw-semibold stock-slip-receiver-name">${stockPrintEscapeHtml(payload.receiver.name)}</div>
            <div class="text-muted small stock-slip-receiver-phone">${stockPrintEscapeHtml(payload.receiver.phone)}</div>
        </td>
        <td class="stock-slip-transfer-cell">${stockPrintEscapeHtml(payload.receiver.place || '-')}</td>
        <td>${stockPrintEscapeHtml(payload.type)}</td>
        <td class="small">${stockPrintEscapeHtml(payload.location)}</td>
        <td class="text-center">${stockPrintEscapeHtml(payload.rows.length)}</td>
        <td class="text-end fw-semibold">${stockPrintEscapeHtml(stockPrintFormatQty(payload.totalQty))}</td>
        <td class="small">${stockPrintEscapeHtml(createdText)}</td>
        <td>${stockPrintEscapeHtml(payload.user)}</td>
        <td class="text-end">
            <div class="btn-group btn-group-sm" role="group" aria-label="Delivery slip actions">
                <button type="button" class="btn btn-outline-primary stock-slip-view-btn">
                    <i class="bi bi-eye"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary stock-slip-edit-btn">
                    <i class="bi bi-pencil-square"></i>
                </button>
                <button type="button" class="btn btn-outline-success stock-slip-reprint-btn">
                    <i class="bi bi-printer"></i>
                </button>
            </div>
        </td>
    `;
    row.querySelectorAll('.stock-slip-view-btn, .stock-slip-edit-btn, .stock-slip-reprint-btn').forEach((button) => {
        button.setAttribute('data-slip', JSON.stringify(slipData));
    });
    stockSlipHistoryBindButtons(row);
    tbody.prepend(row);
}

async function stockPrintSelected() {
    const rows = stockPrintMovementRows();
    if (!rows.length) return;

    const receiver = stockPrintReceiverDetails();
    if (!receiver.name || !receiver.phone) {
        stockPrintOpenReceiverModal();
        return;
    }

    const totalQty = rows.reduce((sum, row) => sum + (Number.isFinite(row.qtyValue) ? row.qtyValue : 0), 0);
    const title = stockPrintTitleFor(rows);
    const location = stockPrintCommonValue(rows, 'location', 'Mixed Locations');
    const type = stockPrintCommonValue(rows, 'type', 'Mixed Types');
    const user = stockPrintCommonValue(rows, 'user', 'Mixed Users');
    const firstDate = rows[0]?.date || '';
    const totalIn = rows.reduce((sum, row) => (row.type || '').toLowerCase().includes('stock in') ? sum + (Number.isFinite(row.qtyValue) ? row.qtyValue : 0) : sum, 0);
    const totalOut = rows.reduce((sum, row) => (row.type || '').toLowerCase().includes('stock out') ? sum + (Number.isFinite(row.qtyValue) ? row.qtyValue : 0) : sum, 0);
    const qrPayload = [
        title,
        'Slip: pending',
        'Date: ' + firstDate,
        'Location: ' + location,
        'Type: ' + type,
        'Name Receive: ' + receiver.name,
        'Phone Number: ' + receiver.phone,
        receiver.place ? 'Location: ' + receiver.place : '',
        'Items: ' + rows.length,
        'In: ' + stockPrintFormatQty(totalIn),
        'Out: ' + stockPrintFormatQty(totalOut)
    ].filter(Boolean).join('\n');
    let slipCode = '';
    try {
        const saved = await stockPrintSaveDeliverySlipHistory({
            rows,
            receiver,
            title,
            location,
            type,
            totalQty,
            totalIn,
            totalOut,
            qrPayload
        });
        slipCode = saved.slip_code || '';
        stockPrintAddHistoryRow(saved, {
            rows,
            receiver,
            title,
            type,
            location,
            totalQty,
            totalIn,
            totalOut,
            qrPayload,
            user
        });
    } catch (error) {
        alert(error.message || 'Unable to save delivery slip history.');
        return;
    }
    const finalQrPayload = qrPayload.replace('Slip: pending', 'Slip: ' + slipCode);
    const qrText = encodeURIComponent(slipCode || finalQrPayload);
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + qrText;
    const itemRows = rows.map((row, index) => `
        <tr>
            <td class="tc">${index + 1}</td>
            <td>${stockPrintEscapeHtml(row.product)}</td>
            <td class="tc">${stockPrintEscapeHtml(row.qty)}</td>
        </tr>
    `).join('');

    const printHtml = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${stockPrintEscapeHtml(title)}</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 10px; background: #e5e7eb; color: #000; font-family: Arial, "Noto Sans Khmer", sans-serif; }
        .slip { width: 80mm; max-width: 80mm; margin: 0 auto; background: #fff; padding: 4mm 3mm; border: 1px solid #e5e7eb; box-shadow: 0 18px 40px rgba(15,23,42,.18); }
        .logo { text-align: center; min-height: 24px; margin-bottom: 4px; }
        .logo img { max-width: 28mm; max-height: 18mm; object-fit: contain; }
        .title { text-align: center; font-size: 11px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 8px; }
        .kh { font-family: "Noto Sans Khmer", Arial, sans-serif; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 3mm; }
        .meta { flex: 1; min-width: 0; font-size: 16px; line-height: 1.45; font-weight: 600; overflow-wrap: anywhere; }
        .meta strong { font-weight: 900; }
        .qr { width: 20mm; height: 20mm; object-fit: contain; flex: 0 0 20mm; }
        .qr-wrap { flex: 0 0 20mm; text-align: center; }
        .qr-text { margin-top: 2px; font-size: 7px; font-weight: 800; line-height: 1.15; overflow-wrap: anywhere; }
        .divider { border-top: 1px solid #000; margin: 8px 0 6px; }
        table { width: 100%; border-collapse: collapse; font-family: "Noto Sans Khmer", Arial, sans-serif; }
        th, td { border: 1px solid #000; padding: 4px 3px; font-size: 10px; vertical-align: middle; line-height: 1.25; overflow-wrap: anywhere; }
        th { background: #d1d5db; text-align: center; font-weight: 800; line-height: 1.2; }
        th span { display: block; font-size: 8px; font-weight: 700; }
        .tc { text-align: center; }
        tfoot td { font-weight: 900; background: #fff; }
        .total-label { text-align: right; }
        .total-value { text-align: center; font-size: 12px; }
        .footer { border-top: 1px solid #000; margin-top: 7px; padding-top: 7px; font-size: 9px; font-weight: 700; line-height: 1.45; overflow-wrap: anywhere; }
        @media print {
            @page { size: 80mm auto; margin: 0; }
            html, body { width: 80mm; margin: 0; padding: 0; background: #fff; }
            .slip { width: 80mm; max-width: 80mm; margin: 0; box-shadow: none; border: 0; }
        }
    </style>
</head>
<body>
    <div class="slip">
        <div class="logo">${stockPrintLogoUrl ? `<img src="${stockPrintEscapeHtml(stockPrintLogoUrl)}" alt="Logo">` : ''}</div>
        <div class="title kh">&#x1794;&#x17D0;&#x178E;&#x17D2;&#x178E;&#x179F;&#x17D2;&#x178F;&#x17BB;&#x1780;</div>
        <div class="top">
            <div class="meta">
                <div><span class="kh">&#x17A2;&#x17D2;&#x1793;&#x1780;&#x1791;&#x1791;&#x17BD;&#x179B;</span>: <strong>${stockPrintEscapeHtml(receiver.name)}</strong></div>
                <div><span class="kh">&#x179B;&#x17C1;&#x1781;&#x1791;&#x17BC;&#x179A;&#x179F;&#x17D0;&#x1796;&#x17D2;&#x1791;</span>: <strong>${stockPrintEscapeHtml(receiver.phone)}</strong></div>
                ${receiver.place ? `<div><span class="kh">&#x1791;&#x17B8;&#x178F;&#x17B6;&#x17C6;&#x1784;</span>: <strong>${stockPrintEscapeHtml(receiver.place)}</strong></div>` : ''}
            </div>
            <div class="qr-wrap">
                <img class="qr" src="${qrUrl}" alt="QR">
                <div class="qr-text">${stockPrintEscapeHtml(slipCode)}</div>
            </div>
        </div>
        <div class="divider"></div>
        <table>
            <thead>
                <tr>
                    <th style="width:12%;">&#x179B;.&#x179A;<span>No</span></th>
                    <th style="width:62%;">&#x1798;&#x17BB;&#x1781;&#x1791;&#x17C6;&#x1793;&#x17B7;&#x1789;<span>Product Name</span></th>
                    <th style="width:26%;">&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;<span>Qty</span></th>
                </tr>
            </thead>
            <tbody>${itemRows}</tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="total-label kh">&#x179F;&#x179A;&#x17BB;&#x1794;&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;</td>
                    <td class="total-value">${stockPrintFormatQty(totalQty)}</td>
                </tr>
            </tfoot>
        </table>
        <div class="footer">
            <div>Created: ${stockPrintEscapeHtml(new Date().toLocaleString())}</div>
            <div>Powered by : One Night Solution</div>
        </div>
    </div>
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 150);
        });
    <\/script>
</body>
</html>`;

    // Use a hidden iframe to avoid opening a new tab/window (bypass popup blockers)
    try {
        const printIframe = document.createElement('iframe');
        printIframe.style.position = 'fixed';
        printIframe.style.right = '0';
        printIframe.style.bottom = '0';
        printIframe.style.width = '0';
        printIframe.style.height = '0';
        printIframe.style.border = '0';
        printIframe.style.visibility = 'hidden';
        document.body.appendChild(printIframe);
        const win = printIframe.contentWindow;
        const doc = win ? win.document : (printIframe.contentDocument || printIframe);
        doc.open();
        doc.write(printHtml);
        doc.close();
        if (win) {
            win.focus && win.focus();
            // Give the browser a moment to render before printing
            setTimeout(() => {
                try {
                    win.print();
                } catch (err) {
                    console.error('Print failed on iframe window:', err);
                }
                setTimeout(() => { try { document.body.removeChild(printIframe); } catch (e) {} }, 800);
            }, 200);
        } else {
            // Fallback: open in new window if iframe approach isn't available
            const fallbackWin = window.open('', '_blank');
            if (!fallbackWin) {
                alert('Please allow popup windows to print selected stock.');
                try { document.body.removeChild(printIframe); } catch (e) {}
                return;
            }
            fallbackWin.document.open();
            fallbackWin.document.write(printHtml);
            fallbackWin.document.close();
        }
    } catch (e) {
        console.error('Printing failed:', e);
        alert('Printing failed. Please try allowing popups or try again.');
    }
}

function stockBindOnce(element, key, eventName, handler) {
    if (!element || element.dataset[key] === '1') return;
    element.dataset[key] = '1';
    element.addEventListener(eventName, handler);
}

function stockOperationsInit() {
    const selectAll = document.getElementById('stockPrintSelectAll');
    const printBtn = document.getElementById('stockPrintBtn');
    const clearBtn = document.getElementById('stockPrintClearBtn');
    const receiverForm = document.getElementById('stockPrintReceiverForm');
    const telegramTestBtn = document.getElementById('stockMovementTelegramTestBtn');
    const telegramTestStatus = document.getElementById('stockMovementTelegramTestStatus');

    stockSlipHistoryBindButtons();

    if (telegramTestBtn && telegramTestStatus) {
        stockBindOnce(telegramTestBtn, 'stockMovementTelegramTestBound', 'click', async function() {
            const originalHtml = telegramTestBtn.innerHTML;
            telegramTestBtn.disabled = true;
            telegramTestBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Sending...';
            telegramTestStatus.classList.remove('d-none', 'text-success', 'text-danger');
            telegramTestStatus.classList.add('text-muted');
            telegramTestStatus.textContent = 'Sending stock movement Telegram test...';

            try {
                const body = new URLSearchParams();
                body.set('action', 'send_stock_movement_telegram_test');
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body
                });
                const result = await response.json().catch(() => null);
                telegramTestStatus.classList.remove('text-muted', 'text-success', 'text-danger');
                if (response.ok && result && result.success) {
                    telegramTestStatus.classList.add('text-success');
                    telegramTestStatus.textContent = 'Telegram test sent to ' + (result.sent_count || 0) + ' target(s).';
                } else {
                    const targetErrors = result && Array.isArray(result.targets)
                        ? result.targets.map((target) => {
                            const label = target.chat_id ? ('Chat ' + target.chat_id + (target.thread_id ? ' topic ' + target.thread_id : '')) : 'No target';
                            return label + (target.error ? ': ' + target.error : '');
                        }).filter(Boolean).join(' | ')
                        : '';
                    telegramTestStatus.classList.add('text-danger');
                    telegramTestStatus.textContent = (result && result.message ? result.message : 'Telegram test failed.') + (targetErrors ? ' ' + targetErrors : '');
                }
            } catch (error) {
                telegramTestStatus.classList.remove('text-muted', 'text-success');
                telegramTestStatus.classList.add('text-danger');
                telegramTestStatus.textContent = 'Telegram test error: ' + (error.message || 'Unknown error');
            } finally {
                telegramTestBtn.disabled = false;
                telegramTestBtn.innerHTML = originalHtml;
            }
        });
    }

    const historyEditBtn = document.getElementById('stockSlipHistoryEditBtn');
    const historyReprintBtn = document.getElementById('stockSlipHistoryReprintBtn');
    if (historyEditBtn) {
        stockBindOnce(historyEditBtn, 'stockHistoryEditBound', 'click', function() {
            if (stockSlipHistoryCurrent) {
                stockPrintOpenReceiverModal({ mode: 'history-edit', slip: stockSlipHistoryCurrent });
            }
        });
    }
    if (historyReprintBtn) {
        stockBindOnce(historyReprintBtn, 'stockHistoryReprintBound', 'click', function() {
            if (stockSlipHistoryCurrent) {
                stockPrintReprintHistorySlip(stockSlipHistoryCurrent);
            }
        });
    }

    document.querySelectorAll('.stock-print-checkbox').forEach((cb) => {
        stockBindOnce(cb, 'stockPrintBound', 'change', stockPrintUpdateToolbar);
    });

    if (selectAll) {
        stockBindOnce(selectAll, 'stockPrintSelectAllBound', 'change', function() {
            document.querySelectorAll('.stock-print-checkbox').forEach((cb) => {
                cb.checked = selectAll.checked;
            });
            stockPrintUpdateToolbar();
        });
    }

    if (clearBtn) {
        stockBindOnce(clearBtn, 'stockPrintClearBound', 'click', function() {
            document.querySelectorAll('.stock-print-checkbox').forEach((cb) => { cb.checked = false; });
            stockPrintUpdateToolbar();
        });
    }

    if (printBtn) {
        stockBindOnce(printBtn, 'stockPrintOpenReceiverBound', 'click', stockPrintOpenReceiverModal);
    }

    if (receiverForm) {
        stockBindOnce(receiverForm, 'stockPrintReceiverSubmitBound', 'submit', async function(event) {
            event.preventDefault();
            if (!receiverForm.checkValidity()) {
                receiverForm.reportValidity();
                return;
            }
            const submitBtn = receiverForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving...';
            }
            const modalEl = document.getElementById('stockPrintReceiverModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            try {
                if (stockPrintReceiverMode === 'history-edit') {
                    await stockSlipHistorySaveReceiverEdit();
                } else {
                    await stockPrintSelected();
                }
            } catch (error) {
                alert(error.message || 'Unable to save delivery slip.');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    stockPrintSetReceiverSubmit(stockPrintReceiverMode === 'history-edit');
                }
                if (stockPrintReceiverMode !== 'history-edit') {
                    stockPrintSetReceiverSubmit(false);
                }
            }
        });
    }

    stockPrintUpdateToolbar();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', stockOperationsInit);
} else {
    stockOperationsInit();
}
window.addEventListener('pageshow', stockOperationsInit);

let mvIndex = 0;

function addMovementRow() {
    const tbody = document.getElementById('mvRows');
    const idx = mvIndex++;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="movements[${idx}][item_id]" class="form-control form-control-sm" required>
                <option value="">Select Product</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="movements[${idx}][movement_type]" class="form-control form-control-sm" required onchange="syncMovementRow(this)">
                <option value="">Type</option>
                <option value="in">Stock In</option>
                <option value="out">Stock Out</option>
                <option value="adjustment">Adjustment</option>
                <option value="transfer">Transfer</option>
            </select>
        </td>
        <td>
            <select name="movements[${idx}][location_id]" class="form-control form-control-sm location-field">
                <option value="">Location</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int)$location['id'] ?>"><?= htmlspecialchars('#' . $location['id'] . ' - ' . $location['location_code'] . ' - ' . $location['location_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="movements[${idx}][from_location_id]" class="form-control form-control-sm transfer-field d-none">
                <option value="">From</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int)$location['id'] ?>"><?= htmlspecialchars('#' . $location['id'] . ' - ' . $location['location_code'] . ' - ' . $location['location_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="movements[${idx}][to_location_id]" class="form-control form-control-sm transfer-field d-none">
                <option value="">To</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int)$location['id'] ?>"><?= htmlspecialchars('#' . $location['id'] . ' - ' . $location['location_code'] . ' - ' . $location['location_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="movements[${idx}][quantity]" class="form-control form-control-sm text-end qty-field" step="0.01" required>
        </td>
        <td>
            <select name="movements[${idx}][reference_type]" class="form-control form-control-sm">
                <option value="adjustment">Manual Adjustment</option>
                <option value="purchase">Purchase</option>
                <option value="sale">Sale</option>
                <option value="return">Return</option>
                <option value="transfer">Transfer</option>
            </select>
        </td>
        <td>
            <input type="text" name="movements[${idx}][reference_id]" class="form-control form-control-sm" placeholder="Order, invoice, etc.">
        </td>
        <td>
            <input type="number" name="movements[${idx}][unit_cost]" class="form-control form-control-sm text-end" step="0.01" min="0" placeholder="0.00" style="min-width:110px;">
        </td>
        <td class="text-end">
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button>
        </td>
    `;
    tbody.appendChild(tr);
}

function addManyRows(n) {
    for (let i = 0; i < n; i++) addMovementRow();
}

function syncMovementRow(sel) {
    const row = sel.closest('tr');
    const refType = row.querySelector('select[name*="[reference_type]"]');
    const locationField = row.querySelector('.location-field');
    const transferFields = row.querySelectorAll('.transfer-field');
    const qtyField = row.querySelector('.qty-field');
    const val = sel.value;
    if (!refType) return;
    if (val === 'in') refType.value = 'purchase';
    else if (val === 'out') refType.value = 'sale';
    else if (val === 'adjustment') refType.value = 'adjustment';
    else if (val === 'transfer') refType.value = 'transfer';

    if (val === 'transfer') {
        if (locationField) {
            locationField.classList.add('d-none');
            locationField.value = '';
        }
        transferFields.forEach(field => field.classList.remove('d-none'));
        if (qtyField) qtyField.min = '0.01';
    } else {
        if (locationField) {
            locationField.classList.remove('d-none');
        }
        transferFields.forEach(field => {
            field.classList.add('d-none');
            field.value = '';
        });
        if (qtyField) {
            qtyField.min = val === 'adjustment' ? '' : '0.01';
        }
    }
}

// Add one initial row on modal open
function stockMovementModalInit() {
    const modalEl = document.getElementById('addMovementModal');
    if (!modalEl || modalEl.dataset.stockMovementModalBound === '1') return;
    modalEl.dataset.stockMovementModalBound = '1';
    modalEl.addEventListener('shown.bs.modal', function () {
        const tbody = document.getElementById('mvRows');
        if (tbody) {
            while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
            addMovementRow();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', stockMovementModalInit);
} else {
    stockMovementModalInit();
}
window.addEventListener('pageshow', stockMovementModalInit);
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
