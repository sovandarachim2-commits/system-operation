<?php
require_once 'config.php';

$sql = "
SELECT
    p.inv AS inv_number,
    p.phone AS phone_number,
    p.status AS paid_unpaid,
    p.amount,
    p.inv_photo AS inv_photo_prepare,
    o.inv_photo AS inv_photo_out,
    p.full_photo AS photo_full_prepare,
    o.full_photo AS photo_full_out,
    o.delivery_by,
    o.date_time AS date_delivery_out,
    p.datetime AS date_prepare
FROM product_entries p
LEFT JOIN out_items o ON o.inv = p.inv
ORDER BY o.id DESC
";

$result = $conn->query($sql);
$rows = [];
while($row = $result->fetch_assoc()) {
    $row['inv_photo_prepare'] = scanner_storage_resolve_public_url((string)($row['inv_photo_prepare'] ?? ''));
    $row['inv_photo_out'] = scanner_storage_resolve_public_url((string)($row['inv_photo_out'] ?? ''));
    $row['photo_full_prepare'] = scanner_storage_resolve_public_url((string)($row['photo_full_prepare'] ?? ''));
    $row['photo_full_out'] = scanner_storage_resolve_public_url((string)($row['photo_full_out'] ?? ''));
    if (is_null($row['delivery_by'])) {
        $row['delivery_by'] = "អត់ទាន់ចេញ";
        $row['date_delivery_out'] = "អត់ទាន់ចេញ";
    }
    $rows[] = $row;
}
echo json_encode($rows);
$conn->close();
?>
