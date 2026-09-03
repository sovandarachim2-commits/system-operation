<?php
require_once __DIR__ . "/../auth.php";
require_role_or_permission(['admin'], 'purchase_orders.view');

$pdo = get_db_connection();

// Get vendors
$vendors = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM purchase_vendors WHERE is_active = 1 ORDER BY name");
    $vendors = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error getting vendors: " . $e->getMessage());
}

// Get products with your actual structure (exclude product sets)
$products = [];
try {
    $stmt = $pdo->query("SELECT id, name, cost FROM products WHERE product_type != 'set' OR product_type IS NULL ORDER BY name");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error getting products: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_order"])) {
    try {
        $vendor_id = (int)$_POST["vendor_id"];
        $markup = (float)($_POST["markup"] ?? 20);
        $order_date = $_POST["order_date"] ?? date("Y-m-d");
        $expected_date = $_POST["expected_date"] ?? date("Y-m-d", strtotime("+7 days"));
        
        if ($vendor_id <= 0) {
            throw new Exception("Please select a vendor");
        }
        
        $pdo->beginTransaction();
        
        // Create purchase order
        $order_number = "PO-" . date("Y-m-d") . "-" . str_pad(rand(1, 999), 3, "0", STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders 
            (order_number, vendor_id, order_date, expected_date, status, subtotal, total_amount, created_by, created_at) 
            VALUES (?, ?, ?, ?, 'draft', 0, 0, 1, NOW())
        ");
        $stmt->execute([$order_number, $vendor_id, $order_date, $expected_date]);
        $purchase_order_id = $pdo->lastInsertId();
        
        $subtotal = 0;
        $items_added = 0;
        
        // Add products with optimized pricing
        foreach ($products as $product) {
            $purchase_price = $product["cost"] * (1 + $markup / 100);
            $quantity = 10; // Default quantity
            
            $line_total = $purchase_price * $quantity;
            $subtotal += $line_total;
            
            $stmt = $pdo->prepare("
                INSERT INTO purchase_order_items 
                (purchase_order_id, product_id, item_name, sku, quantity_ordered, unit_price, line_total, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $purchase_order_id,
                $product["id"],
                $product["name"],
                "PROD-" . str_pad($product["id"], 4, "0", STR_PAD_LEFT),
                $quantity,
                $purchase_price,
                $line_total
            ]);
            
            $items_added++;
        }
        
        // Update totals
        $tax = $subtotal * 0.10;
        $total = $subtotal + $tax;
        
        $stmt = $pdo->prepare("
            UPDATE purchase_orders 
            SET subtotal = ?, tax_amount = ?, total_amount = ? 
            WHERE id = ?
        ");
        $stmt->execute([$subtotal, $tax, $total, $purchase_order_id]);
        
        $pdo->commit();
        
        echo "<div class=\"success\">";
        echo "✅ Purchase Order Created!<br>";
        echo "Order: $order_number<br>";
        echo "Items: $items_added<br>";
        echo "Total: $" . number_format($total, 2);
        echo "</div>";
        
        echo "<a href=\"purchase_orders.php\" class=\"btn btn-primary\">View Orders</a>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class=\"error\">❌ Error: " . $e->getMessage() . "</div>";
    }
} else {
    // Show form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Create Purchase Order</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-4">
            <h2>🛒 Create Purchase Order</h2>
            
            <form method="post" class="card p-4">
                <input type="hidden" name="create_order" value="1">
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Vendor *</label>
                        <select name="vendor_id" class="form-select" required>
                            <option value="">Select Vendor</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?= $vendor["id"] ?>"><?= htmlspecialchars($vendor["name"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Markup %</label>
                        <input type="number" name="markup" class="form-control" value="20" step="0.1">
                        <small>Markup on product cost</small>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Order Date</label>
                        <input type="date" name="order_date" class="form-control" value="<?= date("Y-m-d") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expected Date</label>
                        <input type="date" name="expected_date" class="form-control" value="<?= date("Y-m-d", strtotime("+7 days")) ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <h5>Products to Include (<?= count($products) ?> items)</h5>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Cost</th>
                                    <th>Purchase Price</th>
                                    <th>Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($product["name"]) ?></td>
                                        <td>$<?= number_format($product["cost"], 2) ?></td>
                                        <td class="purchase-price" data-cost="<?= $product["cost"] ?>">$<?= number_format($product["cost"] * 1.20, 2) ?></td>
                                        <td>10</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success btn-lg">🛒 Create Purchase Order</button>
                <a href="purchase_orders.php" class="btn btn-secondary btn-lg">Cancel</a>
            </form>
        </div>
        
        <script>
        document.querySelector("input[name=\"markup\"]").addEventListener("input", function() {
            const markup = parseFloat(this.value) / 100;
            document.querySelectorAll(".purchase-price").forEach(elem => {
                const cost = parseFloat(elem.dataset.cost);
                const price = cost * (1 + markup);
                elem.textContent = "$" + price.toFixed(2);
            });
        });
        </script>
    </body>
    </html>
    <?php
}
?>