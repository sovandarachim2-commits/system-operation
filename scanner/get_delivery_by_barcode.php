<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'config.php';

$barcode = $_GET['barcode'] ?? null;
if (!$barcode) {
    echo json_encode(['success' => false, 'message' => 'Missing barcode']);
    exit;
}

$barcode = $conn->real_escape_string($barcode);

// Query out_items table to get delivery information for the barcode
$sql = "SELECT delivery_by FROM out_items WHERE inv = '$barcode' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'delivery_by' => $row['delivery_by']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Barcode not found in out_items'
    ]);
}

$conn->close();
?>
