<?php
require_once 'config.php';

$sql = "SELECT inv_number, phone_number, action, input_value, created_at FROM confirm_items ORDER BY created_at DESC";
$result = $conn->query($sql);

$data = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}
echo json_encode($data);
$conn->close();
?>
