<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once 'config.php';

// GROUP BY date and delivery, SUM amount for each group
$sql = "
    SELECT 
        DATE(o.date_time) AS date,
        o.delivery_by,
        SUM(IFNULL(p.amount, 0)) AS total
    FROM out_items o
    LEFT JOIN product_entries p ON o.inv = p.inv
    GROUP BY DATE(o.date_time), o.delivery_by
    ORDER BY DATE(o.date_time) DESC, o.delivery_by ASC
";

$res = $conn->query($sql);

$pivot = [];
$deliveries = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $date = $row['date'];
        $delivery = $row['delivery_by'];
        $value = floatval($row['total']);
        if (!isset($pivot[$date])) {
            $pivot[$date] = [];
            $pivot[$date]['total'] = 0;
        }
        $pivot[$date][$delivery] = $value;
        $pivot[$date]['total'] += $value;
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
