<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once 'config.php';

// Get all unique dates from product_entries to ensure all payment days are shown
$dateSQL = "SELECT DISTINCT DATE(datetime) as date FROM product_entries ORDER BY date DESC";
$dateRes = $conn->query($dateSQL);
$dates = [];
if ($dateRes) {
    while ($row = $dateRes->fetch_assoc()) {
        $dates[] = $row['date'];
    }
}

$output = [];
foreach ($dates as $date) {
    // Total count of deliveries for this payment date
    $countSQL = "SELECT COUNT(*) as delivery_count FROM out_items WHERE DATE(date_time) = '$date' AND delivery_by IS NOT NULL";
    $countRes = $conn->query($countSQL);
    $countRow = $countRes->fetch_assoc();
    $delivery_count = intval($countRow['delivery_count']);

    // Paid, unpaid, total in product_entries on this date
    $paySQL = "
        SELECT 
            SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as paid,
            SUM(CASE WHEN status='unpaid' THEN amount ELSE 0 END) as unpaid
        FROM product_entries
        WHERE DATE(datetime) = '$date'
    ";
    $payRes = $conn->query($paySQL);
    $payRow = $payRes->fetch_assoc();
    $paid = floatval($payRow['paid']);
    $unpaid = floatval($payRow['unpaid']);
    $total = $paid + $unpaid;

    // Output row for this date
    $output[] = [
        "date" => $date,
        "delivery_count" => $delivery_count,
        "paid" => $paid,
        "unpaid" => $unpaid,
        "total" => $total
    ];
}
echo json_encode($output, JSON_UNESCAPED_UNICODE);
$conn->close();
?>
