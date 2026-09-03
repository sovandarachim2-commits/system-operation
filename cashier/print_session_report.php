<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'print_history.view');
require_once __DIR__ . '/../db.php';

$pdo  = get_db_connection();
$printed_at = $_GET['printed_at'] ?? '';
$cashier_id = $_GET['cashier_id'] ?? '';

if (!$printed_at || !$cashier_id) {
    echo '<p>Invalid session data.</p>';
    exit;
}

$stmtCashier = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
$stmtCashier->execute([(int)$cashier_id]);
$cashierName = $stmtCashier->fetchColumn() ?: 'Unknown';

// Original session details
$detailQuery = "SELECT p.name AS product_name,
                       SUM(oi.quantity) AS total_quantity,
                       COUNT(DISTINCT pj.order_id) AS order_count
                FROM print_jobs pj
                JOIN order_items oi ON oi.order_id = pj.order_id
                JOIN products p ON p.id = oi.product_id
                WHERE pj.printed_at = ? AND pj.cashier_id = ?
                GROUP BY p.id, p.name
                ORDER BY total_quantity DESC, p.name ASC";
$detailStmt = $pdo->prepare($detailQuery);
$detailStmt->execute([$printed_at, (int)$cashier_id]);
$sessionDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$totalQuantity = 0;
$totalOrders = 0;
foreach ($sessionDetails as $item) {
    $totalQuantity += (float)$item['total_quantity'];
    $totalOrders += (int)$item['order_count'];
}

// Expanded product items (set converted)
$orderIdsStmt = $pdo->prepare("SELECT DISTINCT pj.order_id FROM print_jobs pj WHERE pj.printed_at = ? AND pj.cashier_id = ?");
$orderIdsStmt->execute([$printed_at, (int)$cashier_id]);
$orderIds = array_column($orderIdsStmt->fetchAll(PDO::FETCH_ASSOC), 'order_id');

$productItems = [];
$setComponents = [];
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.quantity, p.name AS product_name, COALESCE(p.product_type, 'normal') AS product_type, ps.id AS product_set_id
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'normal') = 'set'
        WHERE oi.order_id IN ($placeholders)
    ");
    $itemsStmt->execute($orderIds);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        if ($item['product_type'] === 'set' && $item['product_set_id']) {
            // Expand set into its components
            $componentsStmt = $pdo->prepare("
                SELECT psi.product_id, psi.quantity, p.name AS component_name
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                WHERE psi.product_set_id = ?
            ");
            $componentsStmt->execute([$item['product_set_id']]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($components as $comp) {
                $key = $comp['product_id'];
                if (!isset($setComponents[$key])) {
                    $setComponents[$key] = [
                        'product_id' => $comp['product_id'],
                        'product_name' => $comp['component_name'],
                        'quantity' => 0
                    ];
                }
                $setComponents[$key]['quantity'] += $comp['quantity'] * $item['quantity'];
            }
        } else {
            $key = $item['product_id'];
            if (!isset($productItems[$key])) {
                $productItems[$key] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => 0
                ];
            }
            $productItems[$key]['quantity'] += $item['quantity'];
        }
    }
}
// Merge set components into product items
foreach ($setComponents as $key => $comp) {
    if (!isset($productItems[$key])) {
        $productItems[$key] = $comp;
    } else {
        $productItems[$key]['quantity'] += $comp['quantity'];
    }
}
$productItems = array_values($productItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Session Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        h2 { margin-bottom: 0; }
        .meta { margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #333; padding: 8px 12px; text-align: left; }
        th { background: #f0f0f0; }
        tfoot td { font-weight: bold; }
        .totals { margin-top: 20px; }
        @media print {
            button { display: none; }
        }
    </style>
</head>
<body>
    <h2>Print Session Report</h2>
    <div class="meta">
        <div><strong>Printed At:</strong> <?= htmlspecialchars($printed_at) ?></div>
        <div><strong>Cashier:</strong> <?= htmlspecialchars($cashierName) ?></div>
    </div>
    <!-- Removed original product summary table as requested -->
    <h3>Product Items (បូករួមItemsនៅក្នុងSet) </h3>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Product Name</th>
                <th>Quantity</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $totalProductQty = 0;
            if (!$productItems): ?>
                <tr><td colspan="3" style="text-align:center;">No product items found.</td></tr>
            <?php else: ?>
                <?php foreach ($productItems as $i => $item): 
                    $totalProductQty += (float)$item['quantity'];
                ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= (float)$item['quantity'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td><?= $totalProductQty ?></td>
            </tr>
        </tfoot>
    </table>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
