<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.update');
require_once __DIR__ . '/../user_activity_lib.php';
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

// Use invoice number instead of id
if (!$data || !isset($data['inv'])) {
    echo json_encode(['result' => 'error', 'message' => 'Missing Invoice Number']);
    exit;
}

$inv = $conn->real_escape_string($data['inv']);
$phone = $conn->real_escape_string($data['phone']);
$status = $conn->real_escape_string($data['status']);
$amount = $conn->real_escape_string($data['amount']);
$user = $conn->real_escape_string($data['user']);
$datetime = $conn->real_escape_string($data['datetime']);

$sql = "UPDATE product_entries SET phone='$phone', status='$status', amount='$amount', user='$user', datetime='$datetime' WHERE inv='$inv'";

if ($conn->query($sql) === TRUE) {
    if ($conn->affected_rows > 0) {
        $sub = isset($data['sub_names']) ? (string)$data['sub_names'] : '';
        if (strlen($sub) > 80) {
            $sub = substr($sub, 0, 80) . '…';
        }
        $det = user_activity_details_compact([
            'inv' => $data['inv'] ?? '',
            'phone' => $data['phone'] ?? '',
            'status' => $data['status'] ?? '',
            'amount' => $data['amount'] ?? '',
            'user' => $data['user'] ?? '',
            'datetime' => $data['datetime'] ?? '',
            'sub_names' => $sub,
        ]);
        user_activity_log_scanner_mutation(function_exists('current_user') ? current_user() : null, 'update', __FILE__, $det !== '' ? $det : 'inv ' . (string)$inv);
    }
    echo json_encode(['result'=>'success']);
} else {
    echo json_encode(['result'=>'error', 'message'=>'Edit failed']);
}
$conn->close();
?>
