<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.delete');
require_once __DIR__ . '/../user_activity_lib.php';
require_once 'config.php';

$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['result'=>'error', 'message'=>'Missing ID']);
    exit;
}

$id = $conn->real_escape_string($id);
$invLog = '';
$sel = $conn->query("SELECT inv FROM return_items WHERE id='$id' LIMIT 1");
if ($sel && ($row = $sel->fetch_assoc())) {
    $invLog = (string)($row['inv'] ?? '');
}
$sql = "DELETE FROM return_items WHERE id='$id'";

if ($conn->query($sql) === TRUE) {
    if ($conn->affected_rows > 0) {
        $det = user_activity_details_compact(['id' => $id, 'inv' => $invLog]);
        user_activity_log_scanner_mutation(function_exists('current_user') ? current_user() : null, 'delete', __FILE__, $det !== '' ? $det : 'id ' . (string)$id);
    }
    echo json_encode(['result'=>'success']);
} else {
    echo json_encode(['result'=>'error', 'message'=>'Delete failed']);
}
$conn->close();
?>
