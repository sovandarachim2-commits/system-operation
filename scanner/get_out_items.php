<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
require_once 'config.php';
header('Content-Type: application/json');

$result = $conn->query("SELECT * FROM out_items ORDER BY id DESC");
$items = [];
while ($row = $result->fetch_assoc()) {
    $row['inv_photo'] = scanner_storage_resolve_public_url((string)($row['inv_photo'] ?? ''));
    $row['full_photo'] = scanner_storage_resolve_public_url((string)($row['full_photo'] ?? ''));
    $items[] = $row;
}
echo json_encode($items);
$conn->close();
?>
