<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role_or_permission(['admin'], 'orders.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sold_products_report_lib.php';

header('Content-Type: application/json');

function reminder_bot_token(): string
{
    $token = trim((string)($GLOBALS['REMINDER_TELEGRAM_BOT_TOKEN'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    return trim((string)($GLOBALS['TELEGRAM_BOT_TOKEN'] ?? ''));
}

function send_telegram_message(string $botToken, string $chatId, ?int $threadId, string $text): array
{
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
    ];
    if ($threadId !== null && $threadId > 0) {
        $payload['message_thread_id'] = $threadId;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'Request failed'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        $desc = is_array($decoded) ? (string)($decoded['description'] ?? 'Unknown Telegram error') : 'Invalid Telegram response';
        return ['ok' => false, 'error' => $desc];
    }

    return ['ok' => true];
}

function create_excel_file_for_orders(string $delivery, array $orders): array
{
    $safeDelivery = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $delivery);
    $safeDelivery = trim((string)$safeDelivery, '_');
    if ($safeDelivery === '') {
        $safeDelivery = 'delivery';
    }

    $fileName = 'payment_orders_' . $safeDelivery . '_' . date('Ymd_His') . '.xlsx';
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('payment_orders_', true) . '.xlsx';

    $rows = [];
    $rowStyles = [];
    $rows[] = ['Payment Orders', '', '', '', '', '', '', ''];
    $rowStyles[0] = 'title';
    $rows[] = ['Delivery By', $delivery, 'Generated', date('Y-m-d H:i:s'), '', '', '', ''];
    $rows[] = ['No', 'Order code', 'Customer', 'Phone', 'Location', 'Delivery By', 'Status', 'Amount'];
    $rowStyles[2] = 'header';

    $total = 0.0;
    $paid = 0.0;
    $unpaid = 0.0;
    foreach ($orders as $idx => $order) {
        $amount = (float)($order['total_amount'] ?? 0);
        $status = ((int)($order['is_paid'] ?? 0) === 1) ? 'Paid' : 'Unpaid';
        $total += $amount;
        if ($status === 'Paid') {
            $paid += $amount;
        } else {
            $unpaid += $amount;
        }
        $rows[] = [
            $idx + 1,
            (string)($order['order_code'] ?? ''),
            (string)($order['customer_name'] ?? ''),
            (string)($order['phone'] ?? ''),
            (string)($order['location'] ?? ''),
            (string)($order['delivery_by'] ?? ''),
            $status,
            number_format($amount, 2, '.', ''),
        ];
    }

    $rows[] = ['', '', '', '', '', '', 'Paid', number_format($paid, 2, '.', '')];
    $rows[] = ['', '', '', '', '', '', 'Unpaid', number_format($unpaid, 2, '.', '')];
    $rows[] = ['', '', '', '', '', '', 'Total', number_format($total, 2, '.', '')];

    try {
        sprlCreateSimpleXlsxFile(
            $tmpPath,
            $rows,
            [6, 18, 24, 16, 20, 18, 12, 14],
            $rowStyles
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to create XLSX file: ' . $e->getMessage()];
    }

    return ['ok' => true, 'path' => $tmpPath, 'name' => $fileName];
}

function send_telegram_document(string $botToken, string $chatId, ?int $threadId, string $filePath, string $fileName, string $caption): array
{
    if (!is_file($filePath)) {
        return ['ok' => false, 'error' => 'Excel file was not generated.'];
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
    $payload = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'document' => new CURLFile($filePath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $fileName),
    ];
    if ($threadId !== null && $threadId > 0) {
        $payload['message_thread_id'] = $threadId;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $error !== '' ? $error : 'Document request failed'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        $desc = is_array($decoded) ? (string)($decoded['description'] ?? 'Unknown Telegram error') : 'Invalid Telegram response';
        return ['ok' => false, 'error' => $desc];
    }

    return ['ok' => true];
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $orderIds = $data['orderIds'] ?? [];
    if (!is_array($orderIds) || empty($orderIds)) {
        echo json_encode(['success' => false, 'error' => 'No orders selected.']);
        exit;
    }

    $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
    $orderIds = array_filter($orderIds, fn($id) => $id > 0);
    if (empty($orderIds)) {
        echo json_encode(['success' => false, 'error' => 'Invalid order IDs.']);
        exit;
    }

    $botToken = reminder_bot_token();
    if ($botToken === '') {
        echo json_encode(['success' => false, 'error' => 'Reminder Telegram bot token is not configured.']);
        exit;
    }

    $pdo = get_db_connection();

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $sql = "
        SELECT
            o.id,
            o.order_code,
            o.customer_name,
            o.phone,
            (
                SELECT location
                FROM out_items oi
                WHERE oi.inv = o.order_code
                ORDER BY oi.id DESC
                LIMIT 1
            ) AS location,
            o.total_amount,
            o.is_paid,
            (
                SELECT delivery_by
                FROM out_items oi
                WHERE oi.inv = o.order_code
                  AND oi.delivery_by IS NOT NULL
                  AND oi.delivery_by != ''
                ORDER BY oi.id DESC
                LIMIT 1
            ) AS delivery_by
        FROM orders o
        WHERE o.id IN ($placeholders)
        ORDER BY o.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($orderIds);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        echo json_encode(['success' => false, 'error' => 'Selected orders were not found.']);
        exit;
    }

    $deliveryNames = [];
    foreach ($orders as $order) {
        $delivery = trim((string)($order['delivery_by'] ?? ''));
        if ($delivery !== '') {
            $deliveryNames[$delivery] = true;
        }
    }
    if (empty($deliveryNames)) {
        echo json_encode(['success' => false, 'error' => 'Selected orders do not have Delivery By values.']);
        exit;
    }

    $deliveryKeys = array_keys($deliveryNames);
    $deliveryPlaceholders = implode(',', array_fill(0, count($deliveryKeys), '?'));
    $mapStmt = $pdo->prepare("
        SELECT name, telegram, telegram_group, telegram_thread_id, status
        FROM telegram_bot_reminders
        WHERE name IN ($deliveryPlaceholders)
    ");
    $mapStmt->execute($deliveryKeys);
    $reminderRows = $mapStmt->fetchAll(PDO::FETCH_ASSOC);

    $routing = [];
    foreach ($reminderRows as $row) {
        $routing[(string)$row['name']] = $row;
    }

    $groupedOrders = [];
    foreach ($orders as $order) {
        $delivery = trim((string)($order['delivery_by'] ?? ''));
        if ($delivery === '') {
            continue;
        }
        $groupedOrders[$delivery][] = $order;
    }

    $sent = [];
    $failed = [];
    $skipped = [];

    foreach ($groupedOrders as $delivery => $items) {
        $cfg = $routing[$delivery] ?? null;
        if (!$cfg) {
            $skipped[] = "{$delivery}: no reminder config";
            continue;
        }
        if ((int)($cfg['status'] ?? 0) !== 1) {
            $skipped[] = "{$delivery}: reminder disabled";
            continue;
        }
        if ((int)($cfg['telegram'] ?? 0) !== 1) {
            $skipped[] = "{$delivery}: telegram disabled";
            continue;
        }

        $chatId = trim((string)($cfg['telegram_group'] ?? ''));
        if ($chatId === '') {
            $skipped[] = "{$delivery}: telegram group not set";
            continue;
        }
        $threadRaw = trim((string)($cfg['telegram_thread_id'] ?? ''));
        $threadId = $threadRaw === '' ? null : (int)$threadRaw;

        $fileResult = create_excel_file_for_orders($delivery, $items);
        if (!$fileResult['ok']) {
            $failed[] = "{$delivery}: " . (string)$fileResult['error'];
            continue;
        }

        $caption = "💵🚨 Reminder Payment Report\n"
            . "🚚 Delivery By: {$delivery}\n"
            . "🧾 Total Orders: " . count($items) . "\n"
            . "💰 Total Amount: " . number_format(array_sum(array_map(function($o){return (float)($o['total_amount']??0);}, $items)), 2) . "\n\n"
            . "📝 Note : Help Check and Clear b.❤️❤️❤️\n\n"
            . "📅 " . date('Y-m-d H:i');

        $result = send_telegram_document(
            $botToken,
            $chatId,
            $threadId,
            (string)$fileResult['path'],
            (string)$fileResult['name'],
            $caption
        );

        @unlink((string)$fileResult['path']);

        if ($result['ok']) {
            $sent[] = $delivery;
        } else {
            $failed[] = "{$delivery}: " . (string)$result['error'];
        }
    }

    if (empty($sent) && !empty($failed) && empty($skipped)) {
        echo json_encode(['success' => false, 'error' => implode(' | ', $failed)]);
        exit;
    }

    echo json_encode([
        'success' => !empty($sent),
        'sent_groups' => $sent,
        'failed_groups' => $failed,
        'skipped_groups' => $skipped,
        'message' => 'Sent to ' . count($sent) . ' group(s).',
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Unexpected error: ' . $e->getMessage(),
    ]);
}

