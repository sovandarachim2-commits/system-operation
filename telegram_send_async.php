<?php
// Background CLI worker — spawned by send_order_to_telegram_async().
// Called as: php telegram_send_async.php <order_id>
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$orderId = (int)($argv[1] ?? 0);
if ($orderId <= 0) {
    exit(1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$pdo = get_db_connection();
send_order_to_telegram($pdo, $orderId);
