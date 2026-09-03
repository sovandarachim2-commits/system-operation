<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db_connection();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_payment_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            old_payment_method VARCHAR(100) NULL,
            new_payment_method VARCHAR(100) NULL,
            old_payment_date DATE NULL,
            new_payment_date DATE NULL,
            old_is_paid TINYINT(1) NULL,
            new_is_paid TINYINT(1) NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            note TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_payment_events_order (order_id),
            INDEX idx_order_payment_events_date (created_at),
            INDEX idx_order_payment_events_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "SUCCESS: order_payment_events table is ready.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
