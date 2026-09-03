<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once 'config.php';

// Query summary: count by date and delivery
$sql = "
    SELECT DATE(date_time) as date, delivery_by, COUNT(*) as total
    FROM out_items
    GROUP BY DATE(date_time), delivery_by
    ORDER BY DATE(date_time) DESC, delivery_by ASC
";

$res = $conn->query($sql);

$pivot = [];
$deliveries = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $date = $row['date'];
        $delivery = $row['delivery_by'];
        $total = intval($row['total']);
        if (!isset($pivot[$date])) $pivot[$date] = [];
        $pivot[$date][$delivery] = $total;
        $deliveries[$delivery] = true;
    }
    echo json_encode([
        "pivot" => $pivot,
        "deliveries" => array_keys($deliveries)
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Query failed: " . $conn->error]);
}
$conn->close();
?>
