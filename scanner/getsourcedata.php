<?php
require_once 'config.php';

// Query to get combined data from product_entries and out_items
$sql = "
SELECT
    o.id AS no,
    o.date_time AS date,
    o.inv AS inv_id,
    p.phone AS phone_number,
    p.amount,
    o.delivery_by,
    p.status AS paid_unpaid
FROM product_entries p
JOIN out_items o ON o.inv = p.inv
ORDER BY o.id DESC
";

$result = $conn->query($sql);
$rows = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}

echo json_encode($rows);
$conn->close();
?>
