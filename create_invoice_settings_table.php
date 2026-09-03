<?php
/**
 * Run once to create invoice settings table.
 * Usage: php create_invoice_settings_table.php
 * Or open in browser: /Purchase/create_invoice_settings_table.php
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoice_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(200) DEFAULT 'My Company',
            company_address TEXT,
            company_phone VARCHAR(50),
            company_email VARCHAR(100),
            contact_person VARCHAR(100),
            payment_url VARCHAR(500) COMMENT 'URL for QR code payment scan',
            logo_id INT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $stmt = $pdo->query('SELECT COUNT(*) FROM invoice_settings');
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO invoice_settings (company_name, company_address, company_phone, company_email, contact_person) VALUES ('My Company', '123 Business Street', '+66-2-123-4567', 'billing@company.com', '')");
    }
    foreach (['logo_width INT DEFAULT 80', 'logo_height INT DEFAULT 70'] as $col) {
        $c = explode(' ', $col)[0];
        try {
            $pdo->exec("ALTER TABLE invoice_settings ADD COLUMN $col");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
        }
    }
    echo "Invoice settings table ready.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
    exit(1);
}
