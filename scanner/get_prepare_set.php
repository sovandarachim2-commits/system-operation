<?php
require_once 'config.php';

$result = $conn->query("SELECT * FROM prepare_set ORDER BY id DESC");
$items = [];
while ($row = $result->fetch_assoc()) {
    $row['photo'] = scanner_storage_resolve_public_url((string)($row['photo'] ?? ''));
    $items[] = $row;
}
echo json_encode($items);
$conn->close();
?>
