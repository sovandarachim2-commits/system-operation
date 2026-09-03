<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.delete');
require_once 'config.php';

$data = json_decode(file_get_contents("php://input"), true);
$inv_number = $data['inv_number'] ?? null;

if (!$inv_number) {
    echo json_encode(['success' => false, 'message' => 'Missing inv_number']);
    exit;
}

$sql = "DELETE FROM confirm_items WHERE inv_number = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $inv_number);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}

$stmt->close();
$conn->close();
?>
