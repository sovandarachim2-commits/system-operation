<?php
require_once __DIR__ . '/db.php';

$pdo = get_db_connection();

try {
    // Create return_notes table
    $sql = 'CREATE TABLE IF NOT EXISTS return_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        return_note TEXT NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id),
        FOREIGN KEY (updated_by) REFERENCES users(id)
    )';
    
    $pdo->exec($sql);
    echo "Return notes table created successfully!";
    
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>
