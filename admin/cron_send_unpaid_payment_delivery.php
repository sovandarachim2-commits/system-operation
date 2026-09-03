<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../sold_products_report_lib.php';

date_default_timezone_set('Asia/Bangkok');

function cron_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function cron_bot_token(): string
{
    $token = trim((string)($GLOBALS['REMINDER_TELEGRAM_BOT_TOKEN'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    return trim((string)($GLOBALS['TELEGRAM_BOT_TOKEN'] ?? ''));
}

function cron_send_document(string $botToken, string $chatId, ?int $threadId, string $filePath, string $fileName, string $caption): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension is not available'];
    }
    if (!is_file($filePath)) {
        return ['ok' => false, 'error' => 'Report file not found'];
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
    $params = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'document' => new CURLFile(
            $filePath,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $fileName
        ),
    ];
    if ($threadId !== null && $threadId > 0) {
        $params['message_thread_id'] = $threadId;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'Failed to send document'];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return ['ok' => false, 'error' => (string)($decoded['description'] ?? 'Telegram API error')];
    }
    return ['ok' => true];
}

function cron_create_unpaid_xlsx(string $delivery, string $bucketLabel, array $orders): array
{
    $safeDelivery = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $delivery);
    $safeDelivery = trim((string)$safeDelivery, '_');
    if ($safeDelivery === '') {
        $safeDelivery = 'delivery';
    }

    $fileName = 'unpaid_' . $safeDelivery . '_' . date('Ymd_His') . '.xlsx';
    $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('unpaid_payment_', true) . '.xlsx';

    $rows = [];
    $rowStyles = [];
    $rows[] = ['Unpaid Payment Report', '', '', '', '', '', '', '', ''];
    $rowStyles[0] = 'title';
    $rows[] = ['Delivery By', $delivery, 'Bucket', $bucketLabel, 'Generated', date('Y-m-d H:i:s'), '', '', ''];
    $rows[] = ['No', 'Order Code', 'Customer', 'Phone', 'Location', 'Delivery By', 'Overdue Days', 'Status', 'Amount'];
    $rowStyles[2] = 'header';

    $total = 0.0;
    foreach ($orders as $idx => $order) {
        $amount = (float)($order['total_amount'] ?? 0);
        $total += $amount;
        $rows[] = [
            $idx + 1,
            (string)($order['order_code'] ?? ''),
            (string)($order['customer_name'] ?? ''),
            (string)($order['phone'] ?? ''),
            (string)($order['location'] ?? ''),
            (string)($order['delivery_by'] ?? ''),
            (int)($order['overdue_days'] ?? 0),
            'Unpaid',
            number_format($amount, 2, '.', ''),
        ];
    }

    $rows[] = ['', '', '', '', '', '', '', 'Total', number_format($total, 2, '.', '')];
    $rowStyles[count($rows) - 1] = 'section';

    sprlCreateSimpleXlsxFile(
        $filePath,
        $rows,
        [6, 16, 24, 16, 18, 18, 12, 12, 14],
        $rowStyles
    );

    return ['file_path' => $filePath, 'file_name' => $fileName];
}

function cron_fetch_unpaid_orders_by_days(PDO $pdo, array $exactDays, ?int $minDays): array
{
    $exactDays = array_values(array_unique(array_map('intval', $exactDays)));
    $exactDays = array_filter($exactDays, fn($d) => $d > 0);
    if (empty($exactDays) && ($minDays === null || $minDays <= 0)) {
        return [];
    }
    $whereParts = [];
    $params = [];
    if (!empty($exactDays)) {
        $placeholders = implode(',', array_fill(0, count($exactDays), '?'));
        $whereParts[] = "DATEDIFF(CURDATE(), DATE(o.created_at)) IN ($placeholders)";
        foreach ($exactDays as $d) {
            $params[] = $d;
        }
    }
    if ($minDays !== null && $minDays > 0) {
        $whereParts[] = "DATEDIFF(CURDATE(), DATE(o.created_at)) >= ?";
        $params[] = $minDays;
    }
    $whereExpr = implode(' OR ', $whereParts);

    $sql = "
        SELECT
            o.id,
            o.order_code,
            o.customer_name,
            o.phone,
            o.total_amount,
            DATEDIFF(CURDATE(), DATE(o.created_at)) AS overdue_days,
            (
                SELECT delivery_by
                FROM out_items oi
                WHERE oi.inv = o.order_code
                  AND oi.delivery_by IS NOT NULL
                  AND oi.delivery_by != ''
                ORDER BY oi.id DESC
                LIMIT 1
            ) AS delivery_by,
            (
                SELECT location
                FROM out_items oi
                WHERE oi.inv = o.order_code
                ORDER BY oi.id DESC
                LIMIT 1
            ) AS location
        FROM orders o
        WHERE o.is_paid = 0
          AND o.is_cancelled = 0
          AND o.is_returned = 0
          AND ($whereExpr)
        ORDER BY delivery_by ASC, o.created_at ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $delivery = trim((string)($row['delivery_by'] ?? ''));
        if ($delivery === '') {
            continue;
        }
        $grouped[$delivery][] = $row;
    }
    return $grouped;
}

function cron_fetch_reminder_targets(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT name, telegram_group, telegram_thread_id, auto_overdue_days, auto_send_time
        FROM telegram_bot_reminders
        WHERE status = 1 AND telegram = 1
          AND telegram_group IS NOT NULL AND telegram_group != ''
    ");
    $targets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $targets[$name] = [
            'chat_id' => trim((string)$row['telegram_group']),
            'thread_id' => ($row['telegram_thread_id'] === null || $row['telegram_thread_id'] === '')
                ? null
                : (int)$row['telegram_thread_id'],
            'auto_overdue_days' => trim((string)($row['auto_overdue_days'] ?? '1+')),
            'auto_send_time' => !empty($row['auto_send_time']) ? substr((string)$row['auto_send_time'], 0, 5) : '',
        ];
    }
    return $targets;
}

function cron_parse_days(string $daysCsv): array
{
    $parts = preg_split('/\s*,\s*/', trim($daysCsv), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || empty($parts)) {
        return ['exact' => [], 'min' => 1, 'label' => '1+ days'];
    }
    $exact = [];
    $min = null;
    foreach ($parts as $part) {
        $token = trim($part);
        if ($token === '') {
            continue;
        }
        if (preg_match('/^(\d+)\+$/', $token, $m)) {
            $n = (int)$m[1];
            if ($n > 0) {
                $min = $min === null ? $n : min($min, $n);
            }
            continue;
        }
        $n = (int)$token;
        if ($n > 0) {
            $exact[] = $n;
        }
    }
    $exact = array_values(array_unique($exact));
    if (empty($exact) && ($min === null || $min <= 0)) {
        return ['exact' => [], 'min' => 1, 'label' => '1+ days'];
    }

    $labelParts = [];
    if (!empty($exact)) {
        $labelParts[] = 'day ' . implode(',', $exact);
    }
    if ($min !== null && $min > 0) {
        $labelParts[] = $min . '+ days';
    }

    return [
        'exact' => $exact,
        'min' => $min,
        'label' => implode(' + ', $labelParts),
    ];
}

function cron_time_matches(string $configTime): bool
{
    $configTime = trim($configTime);
    if ($configTime === '') {
        return true;
    }
    $now = date('H:i');
    return $now === $configTime;
}

try {
    if (!cron_is_cli()) {
        header('Content-Type: application/json');
    }

    $pdo = get_db_connection();
    $botToken = cron_bot_token();
    if ($botToken === '') {
        throw new RuntimeException('Reminder Telegram bot token is not configured.');
    }

    $targets = cron_fetch_reminder_targets($pdo);
    if (empty($targets)) {
        throw new RuntimeException('No active telegram_bot_reminders targets found.');
    }

    $summary = ['sent' => [], 'skipped' => [], 'failed' => []];

    foreach ($targets as $delivery => $target) {
        if (!cron_time_matches((string)$target['auto_send_time'])) {
            $summary['skipped'][] = $delivery . ' : not scheduled time (' . ($target['auto_send_time'] !== '' ? $target['auto_send_time'] : 'any') . ')';
            continue;
        }

        $dayConfig = cron_parse_days((string)$target['auto_overdue_days']);
        $grouped = cron_fetch_unpaid_orders_by_days($pdo, $dayConfig['exact'], $dayConfig['min']);
        $orders = $grouped[$delivery] ?? [];
        if (empty($orders)) {
            $summary['skipped'][] = $delivery . ' : no unpaid orders for ' . $dayConfig['label'];
            continue;
        }

        $bucketLabel = 'Unpaid: ' . $dayConfig['label'];
        $export = cron_create_unpaid_xlsx($delivery, $bucketLabel, $orders);
        $totalUnpaidAmount = 0.0;
        foreach ($orders as $orderRow) {
            $totalUnpaidAmount += (float)($orderRow['total_amount'] ?? 0);
        }
        $caption = "💵🚨 Unpaid Payment Report\n"
            . "📦 Bucket: {$bucketLabel}\n"
            . "🚚 Delivery By: {$delivery}\n"
            . "🧾 Total Orders: " . count($orders) . "\n"
            . "💰 Total Unpaid Amount: $" . number_format($totalUnpaidAmount, 2) . "\n\n"
            . "📝 Note: Help check and clear balance ❤️\n\n"
            . "📅 " . date('Y-m-d H:i');

        $send = cron_send_document(
            $botToken,
            $target['chat_id'],
            $target['thread_id'],
            $export['file_path'],
            $export['file_name'],
            $caption
        );
        @unlink($export['file_path']);

        if ($send['ok']) {
            $summary['sent'][] = $bucketLabel . ' | ' . $delivery . ' (' . count($orders) . ')';
        } else {
            $summary['failed'][] = $bucketLabel . ' | ' . $delivery . ' : ' . (string)$send['error'];
        }
    }

    if (cron_is_cli()) {
        echo "Auto unpaid payment report completed.\n";
        echo 'Sent: ' . count($summary['sent']) . "\n";
        echo 'Skipped: ' . count($summary['skipped']) . "\n";
        echo 'Failed: ' . count($summary['failed']) . "\n";
        if (!empty($summary['failed'])) {
            echo "Failures:\n- " . implode("\n- ", $summary['failed']) . "\n";
        }
    } else {
        echo json_encode(['success' => true, 'summary' => $summary]);
    }
} catch (Throwable $e) {
    if (cron_is_cli()) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

