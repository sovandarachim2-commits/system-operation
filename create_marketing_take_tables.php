<?php
/**
 * Run once to create Marketing Take tables.
 * Visit: /Test Report/create_marketing_take_tables.php (when logged in as admin)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$pdo = get_db_connection();
try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS marketing_takes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            take_code VARCHAR(50) DEFAULT NULL UNIQUE,
            event_name VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            location VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(80) DEFAULT NULL,
            print_delivery_note TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('pending_approval','approved','rejected','pending','completed') NOT NULL DEFAULT 'pending_approval',
            notes TEXT DEFAULT NULL,
            storage_location_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_by INT DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            reconciled_at DATETIME DEFAULT NULL,
            FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_event_date (event_date),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "Table marketing_takes created.<br>\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS marketing_take_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            marketing_take_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity_taken DECIMAL(15,4) NOT NULL DEFAULT 0,
            quantity_returned DECIMAL(15,4) NOT NULL DEFAULT 0,
            quantity_not_returned DECIMAL(15,4) NOT NULL DEFAULT 0,
            FOREIGN KEY (marketing_take_id) REFERENCES marketing_takes(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_marketing_take_id (marketing_take_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "Table marketing_take_items created.<br>\n";

    try {
        $hasCode = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'take_code'")->fetch();
        if (!$hasCode) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN take_code VARCHAR(50) DEFAULT NULL UNIQUE AFTER id");
            echo "Column take_code added to marketing_takes.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'reject_reason'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER notes");
            echo "Column reject_reason added.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'approve_note'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN approve_note TEXT DEFAULT NULL AFTER reject_reason");
            echo "Column approve_note added.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'updated_by'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN updated_by INT DEFAULT NULL AFTER created_at, ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER updated_by");
            echo "Columns updated_by and updated_at added.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'reconciled_by'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN reconciled_by INT DEFAULT NULL AFTER reconciled_at");
            echo "Column reconciled_by added.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'phone'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN phone VARCHAR(80) DEFAULT NULL AFTER location");
            echo "Column phone added to marketing_takes.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'print_delivery_note'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN print_delivery_note TINYINT(1) NOT NULL DEFAULT 0 AFTER phone");
            echo "Column print_delivery_note added to marketing_takes.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM marketing_takes LIKE 'telegram_message_id'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE marketing_takes ADD COLUMN telegram_message_id BIGINT NULL AFTER reconciled_by, ADD COLUMN telegram_chat_id VARCHAR(50) NULL AFTER telegram_message_id, ADD COLUMN telegram_thread_id INT NULL AFTER telegram_chat_id");
            echo "Columns telegram_message_id, telegram_chat_id, telegram_thread_id added.<br>\n";
        }
    } catch (PDOException $e) {
        echo "Note: " . $e->getMessage() . "<br>\n";
    }

    echo "<strong>Done.</strong> Marketing Take tables are ready.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
