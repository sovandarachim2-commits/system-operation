<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/upload_paths.php';

function product_type_display_label(?string $productType): string
{
    $type = strtolower(trim((string)($productType ?? 'normal')));
    if ($type === '' || $type === 'normal' || $type === 'general') {
        return 'Item';
    }

    return ucfirst(str_replace('_', ' ', $type));
}

function get_qr_effective_date(PDO $pdo): ?DateTimeImmutable
{
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute(['qr_effective_date']);
    $val = $stmt->fetchColumn();
    if (!$val) {
        return null;
    }
    try {
        return new DateTimeImmutable($val);
    } catch (Throwable $e) {
        return null;
    }
}

function set_qr_effective_date(PDO $pdo, DateTimeImmutable $date): void
{
    $formatted = $date->format('Y-m-d');
    $stmt = $pdo->prepare('INSERT INTO app_settings(`key`, `value`) VALUES(?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['qr_effective_date', $formatted]);
}

function generate_order_code(PDO $pdo): string
{
    $today = new DateTimeImmutable('today');
    $effective = get_qr_effective_date($pdo);

    if ($effective === null) {
        $useDate = $today;
    } else {
        // Never allow yesterday or older: clamp minimum to today
        if ($effective < $today) {
            $useDate = $today;
        } else {
            $useDate = $effective;
        }
    }

    $prefixDate = $useDate->format('Ymd');
    $prefix = 'E-SHA-' . $prefixDate;

    $stmt = $pdo->prepare("SELECT order_code FROM orders WHERE order_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last) {
        $suffix = substr($last, strlen($prefix));
        $nextNumber = (int)$suffix + 1;
    }

    return $prefix . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate marketing take code. Format: MTYYMMDD###.
 */
function generate_marketing_take_code(PDO $pdo): string
{
    $prefix = 'MT' . date('ymd');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM marketing_takes WHERE take_code = ?");
    for ($i = 0; $i < 20; $i++) {
        $code = $prefix . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $code;
        }
    }

    do {
        $code = $prefix . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $stmt->execute([$code]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $code;
}

/**
 * Generate next purchase order number. Format: PO-YYYYMMDD-0001
 */
function generate_purchase_order_code(PDO $pdo): string
{
    $prefix = 'PO-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT order_number FROM purchase_orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last) {
        $suffix = substr($last, strlen($prefix));
        $nextNumber = (int)$suffix + 1;
    }

    return $prefix . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Ensure order_items can store lucky-box lines (seller picker).
 */
function ensure_order_items_lucky_box_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'is_lucky_box'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN is_lucky_box TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=sold via seller lucky box' AFTER line_total");
        }
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

/**
 * Merge lucky box lines into one receipt row; general lines pass through.
 *
 * @param array $items Rows with product_name, quantity, line_total, is_lucky_box (0|1)
 * @return list<array{display_kind:string,product_name:string,quantity:int,line_total:float,lucky_detail_code?:string,lucky_detail_names?:string,lucky_product_lines?:list<array{product_name:string,quantity:int}>}>
 */
function receipt_normalize_items_for_display(array $items, string $orderCode): array
{
    $general = [];
    $lucky = [];
    foreach ($items as $item) {
        $isLucky = !empty($item['is_lucky_box']);
        if ($isLucky) {
            $lucky[] = $item;
        } else {
            $general[] = $item;
        }
    }

    $out = [];
    foreach ($general as $g) {
        $out[] = [
            'display_kind' => 'general',
            'product_name' => (string)($g['product_name'] ?? ''),
            'quantity' => (int)($g['quantity'] ?? 0),
            'line_total' => (float)($g['line_total'] ?? 0),
        ];
    }

    if ($lucky) {
        $totalQty = 0;
        $totalAmt = 0.0;
        $luckyQtyByName = [];
        foreach ($lucky as $L) {
            $q = (int)($L['quantity'] ?? 0);
            $totalQty += $q;
            $totalAmt += (float)($L['line_total'] ?? 0);
            $n = trim((string)($L['product_name'] ?? ''));
            if ($n !== '') {
                $luckyQtyByName[$n] = ($luckyQtyByName[$n] ?? 0) + max(0, $q);
            }
        }
        $luckyProductLines = [];
        foreach ($luckyQtyByName as $name => $qty) {
            $luckyProductLines[] = [
                'product_name' => $name,
                'quantity' => $qty,
            ];
        }
        $out[] = [
            'display_kind' => 'lucky_merged',
            'product_name' => 'Lucky box',
            'quantity' => $totalQty,
            'line_total' => $totalAmt,
            'lucky_detail_code' => $orderCode,
            'lucky_detail_names' => implode(', ', array_column($luckyProductLines, 'product_name')),
            'lucky_product_lines' => $luckyProductLines,
        ];
    }

    return $out;
}

function validate_customer_phones(string $phone): ?string
{
    $phone = trim($phone);
    if ($phone === '') {
        return 'Phone number is required.';
    }

    $parts = array_filter(array_map('trim', explode('/', $phone)), static function ($p) {
        return $p !== '';
    });
    if (!$parts) {
        return 'Phone number is required.';
    }

    $starPrefixes = ['076', '096', '031', '071', '088', '097', '038', '018'];

    foreach ($parts as $p) {
        if (!ctype_digit($p)) {
            return 'Phone numbers must contain digits only and use / to separate multiple numbers.';
        }
        if (strlen($p) < 3) {
            return 'Phone number ' . $p . ' is too short.';
        }
        $prefix = substr($p, 0, 3);
        $len    = strlen($p);

        if (in_array($prefix, $starPrefixes, true)) {
            if ($len !== 10) {
                return 'Phone number ' . $p . ' must have 10 digits.';
            }
        } else {
            if ($len !== 9) {
                return 'Phone number ' . $p . ' must have 9 digits.';
            }
        }
    }

    return null;
}

function send_telegram_message(string $text): void
{
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;

    if (empty($TELEGRAM_BOT_TOKEN)) {
        return; // Telegram not configured
    }

    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";

    // If TELEGRAM_TARGETS is defined and non-empty, send to each target.
    $targets = [];
    if (!empty($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) {
        $targets = $TELEGRAM_TARGETS;
    } elseif (!empty($TELEGRAM_CHAT_ID)) {
        $targets = [ ['chat_id' => $TELEGRAM_CHAT_ID, 'thread_id' => null] ];
    } else {
        return;
    }

    foreach ($targets as $t) {
        if (empty($t['chat_id'])) {
            continue;
        }
        $data = [
            'chat_id' => $t['chat_id'],
            'text'    => $text,
        ];
        if (isset($t['thread_id']) && $t['thread_id'] !== null && $t['thread_id'] !== '') {
            $data['message_thread_id'] = (int)$t['thread_id'];
        }

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 5,
            ],
        ];

        @file_get_contents($url, false, stream_context_create($options));
    }
}

function telegram_http_post_form(string $url, array $data): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response !== false) {
            return $response;
        }
        if ($curlError !== '') {
            error_log('Telegram curl error: ' . $curlError);
        }
    }

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ];

    $raw = @file_get_contents($url, false, stream_context_create($options));
    return $raw === false ? null : $raw;
}

function telegram_send_message_request(string $botToken, string $chatId, string $text, ?int $threadId = null, ?int $replyToId = null, array $options = []): array
{
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
    ];

    if ($replyToId !== null) {
        $data['reply_to_message_id'] = $replyToId;
    }
    if ($threadId !== null) {
        $data['message_thread_id'] = $threadId;
    }
    if (!empty($options['disable_web_page_preview'])) {
        $data['disable_web_page_preview'] = 'true';
    }

    $raw = telegram_http_post_form($url, $data);
    if ($raw === null) {
        return ['ok' => false, 'decoded' => null];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'decoded' => null];
    }

    return ['ok' => !empty($decoded['ok']), 'decoded' => $decoded];
}

/**
 * Chat/topic for seller order Telegram messages.
 * Primary: per-seller telegram_chat_id + telegram_thread_id (Admin → Users).
 * Fallback: config.php $TELEGRAM_TARGETS / $TELEGRAM_CHAT_ID when seller Chat ID is empty.
 *
 * @return array{chat_id: string, thread_id: int|null}|null
 */
function telegram_resolve_order_target(?string $sellerChatId, $sellerThreadId): ?array
{
    global $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;

    $sellerChatId = trim((string)$sellerChatId);
    $sellerThread = ($sellerThreadId !== null && $sellerThreadId !== '') ? (int)$sellerThreadId : null;

    if ($sellerChatId !== '') {
        return ['chat_id' => $sellerChatId, 'thread_id' => $sellerThread];
    }

    $groupChatId = null;
    $defaultThreadId = null;

    if (!empty($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) {
        foreach ($TELEGRAM_TARGETS as $t) {
            $chatId = trim((string)($t['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }
            $groupChatId = $chatId;
            $tid = $t['thread_id'] ?? null;
            $defaultThreadId = ($tid !== null && $tid !== '') ? (int)$tid : null;
            break;
        }
    }

    if ($groupChatId === null || $groupChatId === '') {
        $groupChatId = trim((string)($TELEGRAM_CHAT_ID ?? ''));
    }
    if ($groupChatId === '') {
        return null;
    }

    return [
        'chat_id' => $groupChatId,
        'thread_id' => $defaultThreadId,
    ];
}

function telegram_send_order_message(string $botToken, string $text, ?array $target, ?int $replyToId = null): array
{
    if ($target === null || ($target['chat_id'] ?? '') === '') {
        return ['ok' => false, 'decoded' => null];
    }

    $chatId = (string)$target['chat_id'];
    $threadInt = $target['thread_id'] ?? null;
    $result = telegram_send_message_request($botToken, $chatId, $text, $threadInt, $replyToId);
    $decoded = $result['decoded'];

    if (!$result['ok'] && $threadInt !== null && is_array($decoded)
        && isset($decoded['description'])
        && stripos((string)$decoded['description'], 'message thread not found') !== false
    ) {
        $result = telegram_send_message_request($botToken, $chatId, $text, null, $replyToId);
    }

    return $result;
}

/**
 * Send a test Telegram message for a user (saved chat/topic or global default).
 *
 * @return array{ok: bool, message: string}
 */
function telegram_send_user_test_message(string $userLabel, ?string $sellerChatId, $sellerThreadId): array
{
    global $TELEGRAM_BOT_TOKEN;

    if (empty($TELEGRAM_BOT_TOKEN)) {
        return ['ok' => false, 'message' => 'Telegram bot token is not configured.'];
    }

    $target = telegram_resolve_order_target($sellerChatId, $sellerThreadId);
    if ($target === null) {
        return ['ok' => false, 'message' => 'Set Telegram Chat ID on this user (Admin → Users), or configure $TELEGRAM_TARGETS in config.php.'];
    }

    $usesConfigFallback = trim((string)$sellerChatId) === '';
    $targetNote = $usesConfigFallback ? 'config.php fallback' : 'user profile';
    $text = "Test message from LuckyBox Users\n"
        . 'User: ' . $userLabel . "\n"
        . 'Target: ' . $targetNote . "\n"
        . 'Chat: ' . $target['chat_id'];
    if (!empty($target['thread_id'])) {
        $text .= "\nTopic: " . $target['thread_id'];
    }
    $text .= "\nTime: " . date('Y-m-d H:i:s');

    $result = telegram_send_order_message($TELEGRAM_BOT_TOKEN, $text, $target);
    $decoded = $result['decoded'];

    if ($result['ok']) {
        $msg = 'Message sent successfully.';
        if ($usesConfigFallback) {
            $msg .= ' Used config.php (seller has no Chat ID).';
        }
        return ['ok' => true, 'message' => $msg];
    }

    $apiMessage = '';
    if (is_array($decoded) && isset($decoded['description']) && is_string($decoded['description'])) {
        $apiMessage = trim($decoded['description']);
    }

    return ['ok' => false, 'message' => $apiMessage !== '' ? $apiMessage : 'Telegram API returned an error.'];
}

function get_order_before_snapshot(PDO $pdo, int $order_id): array
{
    $stmt = $pdo->prepare('
        SELECT o.customer_name, o.phone, o.location, o.status, o.total_amount,
               p.name  AS page_name,
               dt.name AS delivery_type_name,
               dc.label AS delivery_cost_label
        FROM orders o
        LEFT JOIN pages p         ON o.page_id          = p.id
        LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
        LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
        WHERE o.id = ?
    ');
    $stmt->execute([$order_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];

    $is = $pdo->prepare('SELECT pr.name AS product_name, oi.quantity FROM order_items oi JOIN products pr ON oi.product_id = pr.id WHERE oi.order_id = ? ORDER BY pr.name');
    $is->execute([$order_id]);
    $row['items'] = $is->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}

/**
 * @return array{ok: bool, error: string, chat_id: string, thread_id: int|null}
 */
function send_order_to_telegram(PDO $pdo, int $order_id, array $before = []): array
{
    global $TELEGRAM_BOT_TOKEN;

    $fail = static function (string $error, string $chatId = '', ?int $threadId = null): array {
        return ['ok' => false, 'error' => $error, 'chat_id' => $chatId, 'thread_id' => $threadId];
    };

    if (empty($TELEGRAM_BOT_TOKEN)) {
        return $fail('Telegram bot token is not configured in config.php');
    }

    // Load order with related info, including any existing telegram_message_id and seller-specific telegram settings
    $stmt = $pdo->prepare('SELECT o.*, u.name AS seller_name, u.telegram_chat_id AS seller_telegram_chat_id, u.telegram_thread_id AS seller_telegram_thread_id, p.name AS page_name, dt.name AS delivery_type_name, dc.label AS delivery_cost_label, dc.amount AS delivery_cost_amount
                           FROM orders o
                           JOIN users u ON o.seller_id = u.id
                           LEFT JOIN pages p ON o.page_id = p.id
                           LEFT JOIN delivery_types dt ON o.delivery_type_id = dt.id
                           LEFT JOIN delivery_costs dc ON o.delivery_cost_id = dc.id
                           WHERE o.id = ?');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) {
        return $fail('Order not found');
    }

    ensure_order_items_lucky_box_column($pdo);

    $itemsStmt = $pdo->prepare('SELECT oi.*, pr.name AS product_name FROM order_items oi JOIN products pr ON oi.product_id = pr.id WHERE oi.order_id = ?');
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll();

    $telegramItems = receipt_normalize_items_for_display($items, (string)($order['order_code'] ?? ''));

    $hasOriginalMessage = !empty($order['telegram_message_id']);
    $replyToId = null;
    if (!empty($order['telegram_last_message_id'])) {
        $replyToId = (int)$order['telegram_last_message_id'];
    } elseif ($hasOriginalMessage) {
        $replyToId = (int)$order['telegram_message_id'];
    }

    $lines   = [];
    $lines[] = $hasOriginalMessage
        ? 'Updated order: ' . ($order['order_code'] ?? ('#' . $order_id))
        : 'New order: ' . ($order['order_code'] ?? ('#' . $order_id));
    if (!empty($order['seller_name'])) {
        $lines[] = 'Seller: ' . $order['seller_name'];
    }
    if (!empty($order['customer_name'])) {
        $lines[] = 'Customer: ' . $order['customer_name'];
    }
    if (!empty($order['phone'])) {
        $lines[] = 'Phone: ' . $order['phone'];
    }
    if (!empty($order['location'])) {
        $lines[] = 'Location: ' . $order['location'];
    }
    if (!empty($order['page_name'])) {
        $lines[] = 'Page: ' . $order['page_name'];
    }
    if (!empty($order['delivery_type_name'])) {
        $lines[] = 'Delivery Type: ' . $order['delivery_type_name'];
    }
    if (!empty($order['delivery_cost_label'])) {
        $lines[] = 'Delivery Cost: ' . $order['delivery_cost_label'];
    } elseif (isset($order['delivery_cost_amount']) && $order['delivery_cost_amount'] !== null) {
        $lines[] = 'Delivery Cost: $' . number_format($order['delivery_cost_amount'], 2);
    }

    if ($telegramItems) {
        $lines[] = 'Items:';
        foreach ($telegramItems as $item) {
            if (($item['display_kind'] ?? '') === 'lucky_merged') {
                $lines[] = '- Lucky box x ' . (int)$item['quantity'] . ' = $' . number_format((float)$item['line_total'], 2);
                $lines[] = '  Order code: ' . ($item['lucky_detail_code'] ?? '');
                foreach ($item['lucky_product_lines'] ?? [] as $pl) {
                    $lines[] = '  · ' . ($pl['product_name'] ?? '') . ' · QTY ' . (int)($pl['quantity'] ?? 0);
                }
            } else {
                $lines[] = '- ' . $item['product_name'] . ' x ' . (int)$item['quantity'] . ' = $' . number_format((float)$item['line_total'], 2);
            }
        }
    }

    if (isset($order['discount']) && (float)$order['discount'] > 0) {
        $lines[] = 'Discount: $' . number_format((float)$order['discount'], 2);
    }

    if (isset($order['total_amount'])) {
        $lines[] = 'Total: $' . number_format($order['total_amount'], 2);
    }
    if (!empty($order['status'])) {
        $lines[] = 'Status: ' . strtoupper($order['status']);
    }
    if ($order['status'] === 'paid' && !empty($order['paid_note'])) {
        $lines[] = 'Paid Note: ' . $order['paid_note'];
    }

    // Build before/after diff section for updates
    if (!empty($before) && $hasOriginalMessage) {
        $changes = [];
        $labelMap = [
            'status'              => 'Status',
            'customer_name'       => 'Customer',
            'phone'               => 'Phone',
            'location'            => 'Location',
            'page_name'           => 'Page',
            'delivery_type_name'  => 'Delivery Type',
            'delivery_cost_label' => 'Delivery Cost',
        ];
        foreach ($labelMap as $field => $label) {
            $bv = (string)($before[$field] ?? '');
            $av = (string)($order[$field]  ?? '');
            if ($bv !== $av) {
                $bv = $field === 'status' ? strtoupper($bv) : $bv;
                $av = $field === 'status' ? strtoupper($av) : $av;
                $changes[] = "  {$label}: {$bv} → {$av}";
            }
        }
        $bt = (float)($before['total_amount'] ?? 0);
        $at = (float)($order['total_amount']  ?? 0);
        if (abs($bt - $at) > 0.001) {
            $changes[] = '  Total: $' . number_format($bt, 2) . ' → $' . number_format($at, 2);
        }
        // Item-level diff
        $bItems = array_column($before['items'] ?? [], 'quantity', 'product_name');
        $aItems = [];
        foreach ($items as $it) {
            $aItems[$it['product_name']] = (int)$it['quantity'];
        }
        foreach ($aItems as $name => $qty) {
            if (!isset($bItems[$name])) {
                $changes[] = "  + Added: {$name} x {$qty}";
            } elseif ((int)$bItems[$name] !== $qty) {
                $changes[] = "  {$name}: {$bItems[$name]} → {$qty}";
            }
        }
        foreach ($bItems as $name => $qty) {
            if (!isset($aItems[$name])) {
                $changes[] = "  - Removed: {$name} x {$qty}";
            }
        }
        if (!empty($changes)) {
            array_splice($lines, 1, 0, array_merge(["\nChanges:"], $changes, ['']));
        }
    }

    $text = implode("\n", $lines);

    $target = telegram_resolve_order_target(
        $order['seller_telegram_chat_id'] ?? null,
        $order['seller_telegram_thread_id'] ?? null
    );
    if ($target === null) {
        error_log('Telegram: no chat target for order ' . $order_id . ' (set seller Telegram Chat ID in Users, or TELEGRAM_TARGETS in config.php)');
        return $fail('No Telegram target: set seller Chat ID in Admin → Users, or $TELEGRAM_TARGETS in config.php');
    }

    $result = telegram_send_order_message($TELEGRAM_BOT_TOKEN, $text, $target, $replyToId);
    $decoded = $result['decoded'];
    $threadId = $target['thread_id'] ?? null;

    if (!$result['ok'] || !is_array($decoded)) {
        $err = is_array($decoded) && isset($decoded['description'])
            ? (string)$decoded['description']
            : 'Telegram API error or no response (check bot is in the group and can post in the topic)';
        error_log('Telegram send failed for order ' . $order_id . ' → chat ' . $target['chat_id']
            . ($threadId !== null ? ' topic ' . $threadId : '') . ': ' . $err);
        return $fail($err, $target['chat_id'], $threadId);
    }

    if (isset($decoded['result']['message_id'])) {
        $msgId = (int)$decoded['result']['message_id'];
        // telegram_message_id is set only once (first message); telegram_last_message_id is always the most recent
        $upd   = $pdo->prepare('UPDATE orders SET telegram_message_id = COALESCE(telegram_message_id, ?), telegram_last_message_id = ? WHERE id = ?');
        $upd->execute([$msgId, $msgId, $order_id]);
    }

    return ['ok' => true, 'error' => '', 'chat_id' => $target['chat_id'], 'thread_id' => $threadId];
}

/**
 * Queue send_order_to_telegram() to run at end of the HTTP request.
 * Uses a fresh DB connection so it still works after redirect.
 * (Windows/XAMPP background popen with PHP_BINARY is unreliable and was skipping sends.)
 */
function send_order_to_telegram_async(PDO $pdo, int $order_id): void
{
    $orderId = (int)$order_id;
    if ($orderId <= 0) {
        return;
    }

    $deliver = static function () use ($orderId): void {
        try {
            $pdo = get_db_connection();
            $result = send_order_to_telegram($pdo, $orderId);
            if (empty($result['ok'])) {
                error_log('Telegram async send failed (order ' . $orderId . '): ' . ($result['error'] ?? 'unknown'));
            }
        } catch (Throwable $e) {
            error_log('Telegram order send exception (order ' . $orderId . '): ' . $e->getMessage());
        }
    };

    register_shutdown_function(static function () use ($deliver): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $deliver();
    });
}

function send_order_cancel_telegram(PDO $pdo, int $order_id, string $reason = ''): void
{
    global $TELEGRAM_BOT_TOKEN;

    if (empty($TELEGRAM_BOT_TOKEN)) {
        return; // Telegram not configured
    }

    $stmt = $pdo->prepare('SELECT o.*, u.name AS seller_name, u.telegram_chat_id AS seller_telegram_chat_id, u.telegram_thread_id AS seller_telegram_thread_id FROM orders o JOIN users u ON o.seller_id = u.id WHERE o.id = ?');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    if (!$order) {
        return;
    }

    $hasOriginalMessage = !empty($order['telegram_message_id']);
    $replyToId = null;
    if (!empty($order['telegram_last_message_id'])) {
        $replyToId = (int)$order['telegram_last_message_id'];
    } elseif ($hasOriginalMessage) {
        $replyToId = (int)$order['telegram_message_id'];
    }

    $lines   = [];
    $lines[] = 'Order updated to: CANCELLED';
    $lines[] = 'Code: ' . ($order['order_code'] ?? ('#' . $order_id));
    if (!empty($order['seller_name'])) {
        $lines[] = 'Seller: ' . $order['seller_name'];
    }
    if (!empty($order['customer_name'])) {
        $lines[] = 'Customer: ' . $order['customer_name'];
    }
    if ($reason !== '') {
        $lines[] = 'Reason: ' . $reason;
    }

    $text = implode("\n", $lines);

    $target = telegram_resolve_order_target(
        $order['seller_telegram_chat_id'] ?? null,
        $order['seller_telegram_thread_id'] ?? null
    );
    if ($target === null) {
        error_log('Telegram cancel: no chat target for order ' . $order_id . ' (set seller Chat ID in Users or config.php)');
        return;
    }

    $result = telegram_send_order_message($TELEGRAM_BOT_TOKEN, $text, $target, $replyToId);
    $decoded = $result['decoded'];

    if (!$result['ok']) {
        $err = is_array($decoded) && isset($decoded['description'])
            ? (string)$decoded['description']
            : 'Telegram API error';
        error_log('Telegram cancel failed for order ' . $order_id . ': ' . $err);
        return;
    }

    if (isset($decoded['result']['message_id'])) {
        $msgId = (int)$decoded['result']['message_id'];
        $upd   = $pdo->prepare('UPDATE orders SET telegram_message_id = COALESCE(telegram_message_id, ?), telegram_last_message_id = ? WHERE id = ?');
        $upd->execute([$msgId, $msgId, $order_id]);
    }
}

function get_default_logo(PDO $pdo)
{
    $stmt = $pdo->query('SELECT * FROM logos WHERE is_default = 1 ORDER BY id DESC LIMIT 1');
    $logo = $stmt->fetch();
    if ($logo) {
        return $logo;
    }
    $stmt = $pdo->query('SELECT * FROM logos ORDER BY id DESC LIMIT 1');
    return $stmt->fetch();
}

/**
 * Get invoice settings (company name, address, payment URL, etc.).
 * Falls back to defaults if table doesn't exist or is empty.
 */
function get_invoice_settings(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT * FROM invoice_settings ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (PDOException $e) {
        // Table may not exist yet
    }
    return [
        'company_name' => 'My Company',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
        'contact_person' => '',
        'payment_url' => '',
        'logo_id' => null,
        'logo_width' => 80,
        'logo_height' => 70,
    ];
}

/**
 * Get logo for invoice: from invoice_settings.logo_id if set, else default logo.
 */
function get_invoice_logo(PDO $pdo): ?array
{
    $settings = get_invoice_settings($pdo);
    $logoId = isset($settings['logo_id']) && $settings['logo_id'] ? (int) $settings['logo_id'] : null;
    if ($logoId) {
        $stmt = $pdo->prepare('SELECT * FROM logos WHERE id = ?');
        $stmt->execute([$logoId]);
        $logo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($logo) {
            return $logo;
        }
    }
    return get_default_logo($pdo);
}

/**
 * Web path for profile image (relative to BASE_URL), or empty string if none.
 */
function user_profile_image_url(?array $user): string
{
    if (!$user || empty($user['profile_image'])) {
        return '';
    }
    $rel = str_replace('\\', '/', trim((string) $user['profile_image']));
    if ($rel === '') {
        return '';
    }
    if (function_exists('uploaded_file_url')) {
        return uploaded_file_url($rel, 'profile_images');
    }
    global $BASE_URL;
    $base = rtrim($BASE_URL ?? '', '/');
    return $base . '/' . ltrim($rel, '/');
}
