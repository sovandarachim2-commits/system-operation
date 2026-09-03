<?php
require_once __DIR__ . '/db.php';

$pdo = get_db_connection();

$set_name = 'Set A2';
$set_id = 6;
$month_year = '2026-03';
$selling_price = 15.00;
$total_cost = 15.00;
$commission_rate = 0.00;
$commission_amount = 0.00;

// Check if product exists
$stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND product_type = 'set'");
$stmt->execute([$set_name]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    // Insert into products
    $stmt = $pdo->prepare("INSERT INTO products (name, cost, product_type) VALUES (?, ?, 'set')");
    $stmt->execute([$set_name, $selling_price]);
    $product_id = $pdo->lastInsertId();
    echo "Inserted into products: $product_id\n";
} else {
    $product_id = $product['id'];
    echo "Product exists: $product_id\n";
}

// Check if product_costs exists for this month
$stmt = $pdo->prepare("SELECT id FROM product_costs WHERE product_id = ? AND month_year = ?");
$stmt->execute([$product_id, $month_year]);
$cost_record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cost_record) {
    // Insert into product_costs
    $stmt = $pdo->prepare("
        INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, commission_rate, commission_amount, notes, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$product_id, $month_year, $selling_price, $total_cost, 0, 0, 0, $commission_rate, $commission_amount, '', 1]);
    echo "Inserted into product_costs\n";
} else {
    echo "Cost record exists\n";
}

echo "Done.";
?>
