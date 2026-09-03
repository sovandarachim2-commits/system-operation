<?php
/**
 * Run once to create purchase return tables.
 * Usage: php create_purchase_return_tables.php
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id INT NOT NULL,
            vendor_id INT NOT NULL,
            return_number VARCHAR(50) NOT NULL UNIQUE,
            return_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            reason VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            total_amount DECIMAL(15,2) DEFAULT 0,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
            FOREIGN KEY (vendor_id) REFERENCES purchase_vendors(id)
        )
    ");
    echo "Table purchase_returns created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_return_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_return_id INT NOT NULL,
            purchase_order_item_id INT NOT NULL,
            quantity_returned DECIMAL(15,4) NOT NULL,
            unit_cost DECIMAL(15,4) NOT NULL,
            total_cost DECIMAL(15,2) NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            storage_location_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
            FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id)
        )
    ");
    echo "Table purchase_return_items created.\n";

    try {
        $pdo->exec("ALTER TABLE purchase_return_items ADD COLUMN storage_location_id INT DEFAULT NULL");
        echo "Added storage_location_id to purchase_return_items.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
    }

    echo "Done.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
