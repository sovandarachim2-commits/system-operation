<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.update');
require_once __DIR__ . '/../user_activity_lib.php';
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['result' => 'error', 'message' => 'Missing ID or Data']);
    exit;
}

$id = $conn->real_escape_string($data['id']);
$inv = $conn->real_escape_string($data['inv']);
$delivery_by = $conn->real_escape_string($data['delivery_by']);
$reason = $conn->real_escape_string($data['reason']);
$username = $conn->real_escape_string($data['username']); // ← CHANGED from user_phone to username
$date_time = $conn->real_escape_string($data['date_time']);

// ← REMOVED user_phone from UPDATE query
$sql = "UPDATE return_items SET inv='$inv', delivery_by='$delivery_by', reason='$reason', username='$username', date_time='$date_time' WHERE id='$id'";

if ($conn->query($sql) === TRUE) {
    if ($conn->affected_rows > 0) {
        $det = user_activity_details_compact([
            'id' => $data['id'] ?? '',
            'inv' => $data['inv'] ?? '',
            'delivery_by' => $data['delivery_by'] ?? '',
            'reason' => $data['reason'] ?? '',
            'username' => $data['username'] ?? '',
            'date_time' => $data['date_time'] ?? '',
        ]);
        user_activity_log_scanner_mutation(function_exists('current_user') ? current_user() : null, 'update', __FILE__, $det !== '' ? $det : 'inv ' . (string)$inv);
    }
    echo json_encode(['result'=>'success']);
} else {
    echo json_encode(['result'=>'error', 'message'=>'Edit failed']);
}
$conn->close();
?>
