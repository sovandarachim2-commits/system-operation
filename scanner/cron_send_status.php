<?php
// --- CONFIGURATION ---
$token = "8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk";
$chat_id = "-5055882974";

// --- Connect to your database ---
require_once 'config.php'; // Make sure this points to your db settings

date_default_timezone_set('Asia/Phnom_Penh');
$today = date('Y-m-d');

// --- Fetch today's status data (adjust these queries for your schema) ---
$sql = "
    SELECT 
        SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as paid,
        SUM(CASE WHEN status='unpaid' THEN amount ELSE 0 END) as unpaid,
        COUNT(*) as total_count
    FROM product_entries
    WHERE DATE(datetime) = '$today'
";
$res = $conn->query($sql);
$row = $res ? $res->fetch_assoc() : null;
$paid = $row ? floatval($row['paid']) : 0;
$unpaid = $row ? floatval($row['unpaid']) : 0;
$total = $paid + $unpaid;

// --- Amount of delivery ---
$countSQL = "
    SELECT COUNT(*) as delivery_count
    FROM product_entries p
    INNER JOIN out_items o ON o.inv = p.inv
    WHERE DATE(p.datetime) = '$today'
";
$countRes = $conn->query($countSQL);
$countRow = $countRes ? $countRes->fetch_assoc() : null;
$delivery_count = $countRow ? intval($countRow['delivery_count']) : 0;

$conn->close();

// --- Create message ---
if ($countRow && ($paid > 0 || $unpaid > 0 || $delivery_count > 0)) {
    $message = "សួស្ដី​ @everyone នេះជារបាយការណ៍ LAKAMO \n";
    $message .= "ថ្ងៃ៖ $today\n";
    $message .= "ចំនួនដឹកជញ្ជូន៖ $delivery_count កញ្ចប់\n";
    $message .= "Paid : $paid$\n";
    $message .= "Unpaid : $unpaid$\n";
    $message .= "Total Payment : $total$\n";
} else {
    $message = "Date: $today\nNo report Today";
}

// --- Send to Telegram ---
$url = "https://api.telegram.org/bot$token/sendMessage";
$params = [
    'chat_id' => $chat_id,
    'text' => $message
];

$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type:application/x-www-form-urlencoded",
        'content' => http_build_query($params)
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

echo "Sent to Telegram: $response\n";
?>
