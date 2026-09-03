<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_orders.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
$code = generate_purchase_order_code($pdo);
echo json_encode(['success' => true, 'order_number' => $code]);
