<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'purchase_reports.view');

header('Content-Type: application/json');

$pdo = get_db_connection();
$q = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$result = [];
if (strlen($q) >= 1) {
    $sql = "SELECT DISTINCT po.order_number FROM purchase_orders po WHERE po.order_date BETWEEN ? AND ? AND po.order_number LIKE ? ORDER BY po.order_number DESC LIMIT 20";
    $params = [$from, $to, '%' . $q . '%'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[] = $row['order_number'];
    }
}
echo json_encode($result);
