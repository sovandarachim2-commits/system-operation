<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.create');
require_once 'config.php';

// Set timezone for Cambodia
date_default_timezone_set('Asia/Phnom_Penh');
$now = date('Y-m-d H:i:s');

// Get POST data
$data = json_decode(file_get_contents("php://input"), true);

$inv_number = $data['inv_number'] ?? null;
$phone_number = $data['phone_number'] ?? null;
$action = $data['action'] ?? null;
$input_value = $data['input_value'] ?? null;
$user = $data['user'] ?? 'unknown';

if (!$inv_number || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Check if record exists
$check_sql = "SELECT id FROM confirm_items WHERE inv_number = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("s", $inv_number);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows > 0) {
    // UPDATE existing
    $update_sql = "UPDATE confirm_items
        SET action = ?, input_value = ?, updated_at = ?, updated_by = ?
        WHERE inv_number = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sssss", $action, $input_value, $now, $user, $inv_number);

    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update error: ' . $update_stmt->error]);
    }
    $update_stmt->close();
} else {
    // INSERT new
    $insert_sql = "INSERT INTO confirm_items (
        inv_number, phone_number, action, input_value,
        created_at, updated_at, created_by, updated_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("ssssssss", $inv_number, $phone_number, $action, $input_value, $now, $now, $user, $user);

    if ($insert_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Saved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Insert error: ' . $insert_stmt->error]);
    }
    $insert_stmt->close();
}

$check_stmt->close();
$conn->close();
?>
