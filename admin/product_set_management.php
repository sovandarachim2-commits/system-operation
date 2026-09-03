<?php
/**
 * Product Set Management
 * Create and manage product bundles/packages
 */

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_sets.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/product_set_location.php';

$pdo = get_db_connection();
$errors = [];
$success = '';

// ============================================================================
// DATE SELECTION AND VALIDATION
// ============================================================================
// Get selected month from GET parameter, default to current month
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_month_display = date('F Y', strtotime($selected_month . '-01'));

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
    $selected_month_display = date('F Y');
}

// ============================================================================
// DATABASE SETUP - Create tables and add columns if needed
// ============================================================================
// Create audit log table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_set_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_set_id INT NOT NULL,
            action_type VARCHAR(50) NOT NULL COMMENT 'created, updated, stock_added, deleted',
            user_id INT,
            user_name VARCHAR(255),
            action_details TEXT,
            old_values JSON,
            new_values JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_set_id) REFERENCES product_sets(id) ON DELETE CASCADE
        )
    ");
    error_log("Product set audit log table created successfully");
} catch (PDOException $e) {
    error_log("Error creating audit log table: " . $e->getMessage());
}

// Create product sets table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_sets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            set_name VARCHAR(255) NOT NULL,
            set_description TEXT,
            total_cost DECIMAL(10,2) DEFAULT 0,
            selling_price DECIMAL(10,2) DEFAULT 0,
            profit_margin DECIMAL(5,2) DEFAULT 0,
            commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT 'Commission percentage',
            commission_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Commission amount per set',
            available_stock INT DEFAULT 0 COMMENT 'Number of complete sets available',
            is_active BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_set_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_set_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity DECIMAL(8,2) DEFAULT 1,
            unit_cost DECIMAL(10,2) DEFAULT 0,
            total_cost DECIMAL(10,2) DEFAULT 0,
            FOREIGN KEY (product_set_id) REFERENCES product_sets(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            UNIQUE KEY unique_set_product (product_set_id, product_id)
        )
    ");

    error_log("Product sets tables created successfully");
} catch (PDOException $e) {
    error_log("Error creating product sets tables: " . $e->getMessage());
    $errors[] = "Database setup error: " . $e->getMessage();
}

// Add commission columns to product_sets table if they don't exist
try {
    // Check if commission_rate column exists
    $check_commission_rate = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'commission_rate'");
    $check_commission_amount = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'commission_amount'");

    if ($check_commission_rate->rowCount() == 0) {
        $pdo->exec("ALTER TABLE product_sets ADD COLUMN commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT 'Commission percentage' AFTER profit_margin");
    }
    if ($check_commission_amount->rowCount() == 0) {
        $pdo->exec("ALTER TABLE product_sets ADD COLUMN commission_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Commission amount per set' AFTER commission_rate");
    }
} catch (PDOException $e) {
    error_log("Error adding commission columns: " . $e->getMessage());
}

// Add available_stock column to product_sets table if it doesn't exist
try {
    $check_stock_column = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'available_stock'");
    if ($check_stock_column->rowCount() == 0) {
        $pdo->exec("ALTER TABLE product_sets ADD COLUMN available_stock INT DEFAULT 0 COMMENT 'Number of complete sets available' AFTER commission_amount");
    }
} catch (PDOException $e) {
    // Log error but continue working
}

// Add total_created column to product_sets table if it doesn't exist
try {
    $check_total_created_column = $pdo->query("SHOW COLUMNS FROM product_sets LIKE 'total_created'");
    if ($check_total_created_column->rowCount() == 0) {
        $pdo->exec("ALTER TABLE product_sets ADD COLUMN total_created INT DEFAULT 0 COMMENT 'Total quantity ever created for this set' AFTER available_stock");
    }
} catch (PDOException $e) {
    error_log("Error adding total_created column: " . $e->getMessage());
}

/**
 * Repair tables that lost AUTO_INCREMENT (INSERT then uses id=0 -> Duplicate entry '0').
 */
function product_set_ensure_auto_increment_ids(PDO $pdo): void
{
    $tables = [
        'product_sets',
        'product_set_items',
        'product_set_audit_log',
        'product_set_history',
        'stock_operations',
    ];

    try {
        foreach ($tables as $table) {
            $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
            if (!$exists) {
                continue;
            }

            $zeroCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE id = 0")->fetchColumn();
            if ($zeroCount > 0) {
                $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`")->fetchColumn();
                if ($nextId <= 0) {
                    $nextId = 1;
                }
                while ($zeroCount > 0) {
                    $pdo->exec("UPDATE `{$table}` SET id = {$nextId} WHERE id = 0 LIMIT 1");
                    $nextId++;
                    $zeroCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE id = 0")->fetchColumn();
                }
            }

            $column = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'id'")->fetch(PDO::FETCH_ASSOC) ?: [];
            $extra = strtolower((string)($column['Extra'] ?? ''));
            if (!str_contains($extra, 'auto_increment')) {
                $autoStmt = $pdo->query("SHOW COLUMNS FROM `{$table}` WHERE Extra LIKE '%auto_increment%'");
                $autoColumn = $autoStmt ? $autoStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($autoColumn && strtolower((string)($autoColumn['Field'] ?? '')) !== 'id') {
                    continue;
                }

                $indexStmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Column_name = 'id'");
                if (!$indexStmt || $indexStmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
                }

                $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
            }

            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
            $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . ($maxId + 1));
        }
    } catch (Throwable $e) {
        error_log('product_set_ensure_auto_increment_ids: ' . $e->getMessage());
    }
}

/**
 * Stock operation types used by product sets (and marketing) are not in the original ENUM.
 * Without this, MySQL stores a blank type and the next insert can also hit id=0.
 */
function product_set_ensure_stock_operations_columns(PDO $pdo): void
{
    try {
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote('stock_operations'))->fetchColumn();
        if (!$exists) {
            return;
        }

        $op = $pdo->query("SHOW COLUMNS FROM stock_operations LIKE 'operation_type'")->fetch(PDO::FETCH_ASSOC);
        $opType = strtolower((string)($op['Type'] ?? ''));
        if (str_starts_with($opType, 'enum(')) {
            $pdo->exec("ALTER TABLE stock_operations MODIFY operation_type VARCHAR(80) NOT NULL");
        }

        $ref = $pdo->query("SHOW COLUMNS FROM stock_operations LIKE 'reference_type'")->fetch(PDO::FETCH_ASSOC);
        $refType = strtolower((string)($ref['Type'] ?? ''));
        if (str_starts_with($refType, 'enum(')) {
            $pdo->exec("ALTER TABLE stock_operations MODIFY reference_type VARCHAR(80) NULL DEFAULT 'manual'");
        }
    } catch (Throwable $e) {
        error_log('product_set_ensure_stock_operations_columns: ' . $e->getMessage());
    }
}

product_set_ensure_auto_increment_ids($pdo);
product_set_ensure_stock_operations_columns($pdo);
product_set_ensure_schema($pdo);

// JSON endpoints must run before page HTML is rendered.
if (isset($_GET['action']) && $_GET['action'] === 'get_set_details') {
    header('Content-Type: application/json; charset=utf-8');
    $set_id = (int)($_GET['set_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("
            SELECT ps.*
            FROM product_sets ps
            WHERE ps.id = ?
        ");
        $stmt->execute([$set_id]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($set) {
            $stmt = $pdo->prepare("
                SELECT
                    psi.*,
                    p.name,
                    p.cost
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                WHERE psi.product_set_id = ?
                ORDER BY p.name
            ");
            $stmt->execute([$set_id]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'set' => $set,
                'products' => $products
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product set not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_product_sets') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->prepare("
            SELECT
                ps.id,
                ps.set_name,
                ps.set_description,
                ps.total_cost,
                ps.selling_price,
                ps.commission_rate,
                ps.commission_amount,
                ps.available_stock,
                COUNT(psi.id) as product_count
            FROM product_sets ps
            LEFT JOIN product_set_items psi ON ps.id = psi.product_set_id
            WHERE ps.is_active = 1
            GROUP BY ps.id, ps.set_name, ps.set_description, ps.total_cost, ps.selling_price, ps.commission_rate, ps.commission_amount, ps.available_stock
            ORDER BY ps.set_name
        ");
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================================
// FORM SUBMISSION HANDLING
// ============================================================================

// Handle success messages from redirects
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $success = "Product set created successfully!";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_set') {
        require_role_or_permission(['admin'], 'product_sets.create');
        $set_name = trim($_POST['set_name'] ?? '');
        $set_description = trim($_POST['set_description'] ?? '');
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        $commission_rate = floatval($_POST['commission_rate'] ?? 0);
        $commission_amount = floatval($_POST['commission_amount'] ?? 0);
        $available_stock = intval($_POST['available_stock'] ?? 0);
        $source_location_id = (int)($_POST['source_location_id'] ?? 0);
        $product_ids = $_POST['product_ids'] ?? [];
        $quantities = $_POST['quantities'] ?? [];

        if (empty($set_name)) {
            $errors[] = "Product set name is required";
        } elseif (empty($product_ids)) {
            $errors[] = "At least one product must be selected";
        } elseif ($selling_price <= 0) {
            $errors[] = "Selling price must be greater than 0";
        } elseif ($available_stock < 0) {
            $errors[] = "Initial stock cannot be negative";
        } elseif ($source_location_id <= 0) {
            $errors[] = "Please select a source storage location";
        } else {
            try {
                $pdo->beginTransaction();

                // Calculate total cost
                $total_cost = 0;
                $product_details = [];

                foreach ($product_ids as $index => $product_id) {
                    if (!empty($product_id)) {
                        $quantity = floatval($quantities[$index] ?? 1);
                        $stmt = $pdo->prepare("SELECT name, cost FROM products WHERE id = ?");
                        $stmt->execute([$product_id]);
                        $product = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($product) {
                            $unit_cost = $product['cost'];
                            $item_total = $quantity * $unit_cost;
                            $total_cost += $item_total;

                            // Check stock in the location selected on the create form
                            $stock_check = $pdo->prepare("
                                SELECT SUM(quantity_on_hand) as total_available
                                FROM current_inventory 
                                WHERE item_name = ? AND storage_location_id = ?
                            ");
                            $stock_check->execute([$product['name'], $source_location_id]);
                            $stock_info = $stock_check->fetch(PDO::FETCH_ASSOC);
                            $total_available = $stock_info['total_available'] ?? 0;
                            $required_quantity = $quantity * $available_stock;

                            if ($total_available < $required_quantity) {
                                $shortage = $required_quantity - $total_available;
                                $errors[] = [
                                    'type' => 'stock_shortage',
                                    'product' => $product['name'],
                                    'available' => $total_available,
                                    'required' => $required_quantity,
                                    'shortage' => $shortage,
                                    'per_set' => $quantity,
                                    'sets_to_create' => $available_stock,
                                    'location_issue' => false
                                ];
                            }

                            $product_details[] = [
                                'product_id' => $product_id,
                                'quantity' => $quantity,
                                'unit_cost' => $unit_cost,
                                'total_cost' => $item_total
                            ];
                        }
                    }
                }

                // Only proceed if no stock errors
                if (!empty($errors)) {
                    $pdo->rollBack();
                } else {
                    if ($total_cost <= 0) {
                        $errors[] = "Total cost must be greater than 0";
                        $pdo->rollBack();
                    } else {
                    // Calculate final commission values
                    // If commission amount is provided directly, use it; otherwise calculate from rate
                    if ($commission_amount > 0) {
                        // User provided commission amount directly
                        $final_commission_amount = $commission_amount;
                        $final_commission_rate = $selling_price > 0 ? ($commission_amount / $selling_price) * 100 : 0;
                    } else {
                        // Calculate from commission rate
                        $final_commission_amount = $selling_price * ($commission_rate / 100);
                        $final_commission_rate = $commission_rate;
                    }

                    // Create product set
                    $profit_margin = ($selling_price - $total_cost - $final_commission_amount) / $selling_price * 100;

                    $stmt = $pdo->prepare("
                        INSERT INTO product_sets (set_name, set_description, total_cost, selling_price, profit_margin, commission_rate, commission_amount, available_stock, total_created, storage_location_id, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$set_name, $set_description, $total_cost, $selling_price, $profit_margin, $final_commission_rate, $final_commission_amount, $available_stock, $available_stock, $source_location_id, $_SESSION['user_id'] ?? null]);
                    $set_id = $pdo->lastInsertId();

                    // Add product items
                    foreach ($product_details as $detail) {
                        $stmt = $pdo->prepare("
                            INSERT INTO product_set_items (product_set_id, product_id, quantity, unit_cost, total_cost)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $set_id,
                            $detail['product_id'],
                            $detail['quantity'],
                            $detail['unit_cost'],
                            $detail['total_cost']
                        ]);
                    }

                    // Create product record for the set
                    $stmt = $pdo->prepare("
                        INSERT INTO products (name, cost, product_type) 
                        VALUES (?, ?, 'set')
                    ");
                    $stmt->execute([$set_name, $selling_price]);
                    $product_id = $pdo->lastInsertId();

                    // Create cost record for current month
                    $selected_month_varchar = date('Y-m');
                    $stmt = $pdo->prepare("
                        INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, commission_rate, commission_amount, notes, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$product_id, $selected_month_varchar, $selling_price, 0, 0, 0, 0, $commission_rate, $commission_amount, '', $_SESSION['user_id'] ?? null]);

                    product_set_upsert_inventory(
                        $pdo,
                        $set_name,
                        (int)$set_id,
                        (float)$available_stock,
                        (float)$selling_price,
                        $_SESSION['user_id'] ?? null,
                        $source_location_id
                    );

                    // Log the creation action
                    $current_user_id = $_SESSION['user_id'] ?? null;
                    $current_user_name = '';
                    if ($current_user_id) {
                        $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                        $user_stmt->execute([$current_user_id]);
                        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        $current_user_name = $user_data['name'] ?? 'Unknown User';
                    }
                    
                    // Reduce component inventory only when physical sets are created.
                    // A zero-stock set is a valid catalog definition and consumes no inventory.
                    if ($available_stock > 0) {
                        foreach ($product_details as $detail) {
                        // Get product name for inventory update
                        $product_name_stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                        $product_name_stmt->execute([$detail['product_id']]);
                        $product_name = $product_name_stmt->fetchColumn();
                        
                        if ($product_name) {
                            $total_to_reduce = $detail['quantity'] * $available_stock;
                            
                            // Find inventory records and reduce stock from selected location
                            $inventory_stmt = $pdo->prepare("
                                SELECT id, quantity_on_hand, storage_location_id 
                                FROM current_inventory 
                                WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0
                                ORDER BY last_updated ASC
                            ");
                            $inventory_stmt->execute([$product_name, $source_location_id]);
                            $inventory_items = $inventory_stmt->fetchAll();
                            
                            if (empty($inventory_items)) {
                                $errors[] = [
                                    'type' => 'stock_shortage',
                                    'product' => $product_name,
                                    'available' => 0,
                                    'required' => $total_to_reduce,
                                    'shortage' => $total_to_reduce,
                                    'per_set' => $detail['quantity'],
                                    'sets_to_create' => $available_stock,
                                    'location_issue' => true
                                ];
                                break;
                            }
                            
                            $remaining_to_reduce = $total_to_reduce;
                            foreach ($inventory_items as $inventory_item) {
                                if ($remaining_to_reduce <= 0) break;
                                
                                $reduce_amount = min($remaining_to_reduce, $inventory_item['quantity_on_hand']);
                                
                                // Update inventory
                                $update_stmt = $pdo->prepare("
                                    UPDATE current_inventory 
                                    SET quantity_on_hand = quantity_on_hand - ?, 
                                        last_updated = NOW()
                                    WHERE id = ?
                                ");
                                $update_stmt->execute([$reduce_amount, $inventory_item['id']]);
                                
                                // Log stock operation
                                $log_stmt = $pdo->prepare("
                                    INSERT INTO stock_operations 
                                    (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
                                    VALUES (?, 'set_creation', ?, 'product_set', ?, 'Used for product set creation', ?)
                                ");
                                $log_stmt->execute([
                                    $inventory_item['storage_location_id'], 
                                    $reduce_amount, 
                                    $set_id, 
                                    $_SESSION['user_id'] ?? null
                                ]);
                                
                                $remaining_to_reduce -= $reduce_amount;
                            }
                        }
                        }
                    }

                    logUserAction($pdo, $set_id, 'created', $current_user_id, $current_user_name, 
                        "Product set '$set_name' created with $available_stock sets, selling price \$$selling_price (storage_location_id:$source_location_id)");

                    $pdo->commit();
                    // Redirect to prevent form resubmission on refresh
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?month=' . urlencode($selected_month) . '&created=1');
                    exit;
                    }
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error creating product set: " . $e->getMessage();
            }
        }
    } elseif ($action === 'update_set_item') {
        $product_id = (int)$_POST['product_id'];
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit_cost = floatval($_POST['unit_cost'] ?? 0);

        if ($product_id > 0 && $quantity >= 0 && $unit_cost >= 0) {
            try {
                // Update product set item
                $stmt = $pdo->prepare("
                    UPDATE product_set_items 
                    SET quantity = ?, unit_cost = ?, total_cost = quantity * unit_cost
                    WHERE product_id = ?
                ");
                $result = $stmt->execute([$quantity, $unit_cost, $product_id]);
                
                if ($result) {
                    // Update product set total cost
                    $update_set_stmt = $pdo->prepare("
                        UPDATE product_sets 
                        SET total_cost = (
                            SELECT SUM(quantity * unit_cost) 
                            FROM product_set_items 
                            WHERE product_set_id = (
                                SELECT product_set_id FROM product_set_items 
                                WHERE product_id = ?
                            )
                        )
                        WHERE id = ?
                    ");
                    $update_set_stmt->execute([$product_id]);
                    
                    $success = "Product item updated successfully!";
                } else {
                    $errors[] = "Error updating product item";
                }
            } catch (Exception $e) {
                $errors[] = "Error updating product item: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid product data";
        }
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            } else {
                echo json_encode(['success' => true, 'message' => $success]);
            }
            exit;
        }
    } elseif ($action === 'edit_set') {
        require_role_or_permission(['admin'], 'product_sets.update');
        $set_id = (int)($_POST['set_id'] ?? 0);
        $set_name = trim($_POST['set_name'] ?? '');
        $set_description = trim($_POST['set_description'] ?? '');
        $source_location_id = (int)($_POST['source_location_id'] ?? 0);
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        $commission_rate = floatval($_POST['commission_rate'] ?? 0);
        $commission_amount = floatval($_POST['commission_amount'] ?? 0);

        if ($set_id > 0 && !empty($set_name) && $selling_price > 0 && $source_location_id > 0) {
            try {
                // Get current values before update
                $stmt = $pdo->prepare("SELECT set_name, set_description, selling_price, commission_rate, commission_amount, total_cost FROM product_sets WHERE id = ?");
                $stmt->execute([$set_id]);
                $current_set = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($current_set) {
                    // Store old values for logging
                    $old_values = [
                        'set_name' => $current_set['set_name'],
                        'set_description' => $current_set['set_description'],
                        'selling_price' => floatval($current_set['selling_price']),
                        'commission_rate' => floatval($current_set['commission_rate']),
                        'commission_amount' => floatval($current_set['commission_amount'])
                    ];
                    
                    $total_cost = floatval($current_set['total_cost']);
                    $profit_margin = $selling_price > 0 ? (($selling_price - $total_cost - $commission_amount) / $selling_price) * 100 : 0;

                    // If commission amount is provided directly, use it; otherwise calculate from rate
                    if ($commission_amount > 0) {
                        // User provided commission amount directly
                        $final_commission_amount = $commission_amount;
                        $final_commission_rate = $selling_price > 0 ? ($commission_amount / $selling_price) * 100 : 0;
                    } else {
                        // Calculate from commission rate
                        $final_commission_amount = $selling_price * ($commission_rate / 100);
                        $final_commission_rate = $commission_rate;
                    }

                    // Update product set
                    $stmt = $pdo->prepare("
                        UPDATE product_sets
                        SET set_name = ?, set_description = ?, selling_price = ?, profit_margin = ?, commission_rate = ?, commission_amount = ?, storage_location_id = ?
                        WHERE id = ?
                    ");
                    $result = $stmt->execute([$set_name, $set_description, $selling_price, $profit_margin, $final_commission_rate, $final_commission_amount, $source_location_id, $set_id]);

                    if ($result) {
                        // Update the product record name as well
                        $stmt = $pdo->prepare("
                            UPDATE products
                            SET name = ?, cost = ?
                            WHERE name = (SELECT set_name FROM product_sets WHERE id = ?) AND product_type = 'set'
                        ");
                        $stmt->execute([$set_name, $selling_price, $set_id]);

                        // Store new values for logging
                        $new_values = [
                            'set_name' => $set_name,
                            'set_description' => $set_description,
                            'selling_price' => $selling_price,
                            'commission_rate' => $final_commission_rate,
                            'commission_amount' => $final_commission_amount
                        ];

                        // Get current user info
                        $current_user_id = $_SESSION['user_id'] ?? null;
                        $current_user_name = '';
                        if ($current_user_id) {
                            $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                            $user_stmt->execute([$current_user_id]);
                            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                            $current_user_name = $user_data['name'] ?? 'Unknown User';
                        }

                        // Build detailed change description
                        $changes = [];
                        if ($old_values['set_name'] !== $new_values['set_name']) {
                            $changes[] = "Name: '{$old_values['set_name']}' → '{$new_values['set_name']}'";
                        }
                        if ($old_values['set_description'] !== $new_values['set_description']) {
                            $changes[] = "Description updated";
                        }
                        if ($old_values['selling_price'] !== $new_values['selling_price']) {
                            $changes[] = "Selling Price: \${$old_values['selling_price']} → \${$new_values['selling_price']}";
                        }
                        if ($old_values['commission_rate'] !== $new_values['commission_rate']) {
                            $changes[] = "Commission Rate: {$old_values['commission_rate']}% → {$new_values['commission_rate']}%";
                        }
                        if ($old_values['commission_amount'] !== $new_values['commission_amount']) {
                            $changes[] = "Commission Amount: \${$old_values['commission_amount']} → \${$new_values['commission_amount']}";
                        }

                        $change_details = !empty($changes) ? implode(', ', $changes) : 'No changes detected';

                        // Log the edit action
                        logUserAction($pdo, $set_id, 'updated', $current_user_id, $current_user_name, 
                            "Product set updated: $change_details (storage_location_id:$source_location_id)", $old_values, $new_values);

                        $success = "Product set updated successfully!";
                    } else {
                        $errors[] = "Error updating product set";
                    }
                } else {
                    $errors[] = "Product set not found";
                }
            } catch (Exception $e) {
                $errors[] = "Error updating product set: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid set data";
        }

        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            } else {
                echo json_encode(['success' => true, 'message' => $success]);
            }
            exit;
        }
    } elseif ($action === 'add_more_sets') {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        
        // Debug logging
        error_log("=== ADD MORE SETS DEBUG START ===");
        error_log("POST Data: " . json_encode($_POST));
        error_log("SERVER Data: " . json_encode($_SERVER));
        error_log("SESSION Data: " . json_encode($_SESSION ?? []));
        
        $set_name = trim($_POST['set_name'] ?? '');
        $additional_quantity = intval($_POST['additional_quantity'] ?? 0);
        
        error_log("Set Name: '$set_name'");
        error_log("Additional Quantity: '$additional_quantity'");

        // Validate inputs
        if (empty($set_name)) {
            error_log("ERROR: Set name is empty");
            $errors[] = "Set name is required";
        }
        
        if ($additional_quantity <= 0) {
            error_log("ERROR: Invalid quantity: $additional_quantity");
            $errors[] = "Quantity must be greater than 0";
        }

        if (!empty($errors)) {
            error_log("VALIDATION ERRORS: " . json_encode($errors));
        }

        if (!empty($set_name) && $additional_quantity > 0) {
            try {
                // Get set info before update
                $stmt = $pdo->prepare("SELECT id, available_stock, total_created, selling_price, storage_location_id FROM product_sets WHERE set_name = ?");
                $stmt->execute([$set_name]);
                $set_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($set_info) {
                    error_log("Set Found: ID={$set_info['id']}, Current Stock={$set_info['available_stock']}");
                    
                    $old_stock = $set_info['available_stock'];
                    $old_total_created = $set_info['total_created'];
                    
                    // Get set components to check stock availability
                    $stmt = $pdo->prepare("
                        SELECT psi.product_id, psi.quantity, p.name as product_name
                        FROM product_set_items psi
                        JOIN products p ON psi.product_id = p.id
                        WHERE psi.product_set_id = ?
                    ");
                    $stmt->execute([$set_info['id']]);
                    $set_components = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    error_log("Components Found: " . count($set_components));
                    
                    if (empty($set_components)) {
                        $errors[] = "No components found for this product set";
                        error_log("ERROR: No components found for set ID {$set_info['id']}");
                    } else {
                        $set_location_id = (int)($set_info['storage_location_id'] ?? 0);
                        if ($set_location_id <= 0) {
                            $set_location_id = product_set_default_location_id($pdo);
                        }
                        
                        error_log("Set Location ID: " . ($set_location_id ?: 'NULL'));
                        
                        $stock_issues = [];
                        
                        // Check stock availability for each component in the set's selected location
                        foreach ($set_components as $index => $component) {
                            $required_quantity = $component['quantity'] * $additional_quantity;
                            
                            error_log("Component " . ($index + 1) . ": {$component['product_name']} - Required: $required_quantity");
                            
                            if ($set_location_id) {
                                $stock_check = $pdo->prepare("
                                    SELECT SUM(quantity_on_hand) as total_available
                                    FROM current_inventory 
                                    WHERE item_name = ? AND storage_location_id = ?
                                ");
                                $stock_check->execute([$component['product_name'], $set_location_id]);
                                $stock_info = $stock_check->fetch(PDO::FETCH_ASSOC);
                                $total_available = $stock_info['total_available'] ?? 0;
                                
                                error_log("  Available in selected location: $total_available");
                            } else {
                                $stock_check = $pdo->prepare("
                                    SELECT SUM(quantity_on_hand) as total_available
                                    FROM current_inventory 
                                    WHERE item_name = ?
                                ");
                                $stock_check->execute([$component['product_name']]);
                                $stock_info = $stock_check->fetch(PDO::FETCH_ASSOC);
                                $total_available = $stock_info['total_available'] ?? 0;
                                
                                error_log("  Available in all locations: $total_available");
                            }
                            
                            if ($total_available < $required_quantity) {
                                $shortage = $required_quantity - $total_available;
                                $stock_issues[] = [
                                    'product' => $component['product_name'],
                                    'available' => $total_available,
                                    'required' => $required_quantity,
                                    'shortage' => $shortage,
                                    'per_set' => $component['quantity'],
                                    'sets_to_add' => $additional_quantity
                                ];
                                
                                error_log("  SHORTAGE: Need $shortage more of {$component['product_name']}");
                            } else {
                                error_log("  OK: Sufficient stock for {$component['product_name']}");
                            }
                        }
                        
                        error_log("Stock Issues Count: " . count($stock_issues));
                        
                        if (empty($stock_issues)) {
                            error_log("SUCCESS: All components have sufficient stock");
                        } else {
                            error_log("FAILED: Stock shortages detected");
                        }
                        
                        if (!empty($stock_issues)) {
                            // Show stock shortage message
                            $error_message = "Cannot add more sets. Insufficient stock in " . ($set_location_id ? "the selected storage location" : "inventory") . ":\n";
                            foreach ($stock_issues as $issue) {
                                $error_message .= "- {$issue['product']}: Available: {$issue['available']}, Required: {$issue['required']} ({$issue['per_set']} × {$issue['sets_to_add']} sets)\n";
                            }
                            $errors[] = $error_message;
                        } else {
                            // Proceed with adding more sets
                            $pdo->beginTransaction();
                            
                            // Update available stock and total created
                            $stmt = $pdo->prepare("
                                UPDATE product_sets 
                                SET available_stock = available_stock + ?,
                                    total_created = total_created + ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$additional_quantity, $additional_quantity, $set_info['id']]);

                            product_set_upsert_inventory(
                                $pdo,
                                $set_name,
                                (int)$set_info['id'],
                                (float)$old_stock + (float)$additional_quantity,
                                (float)($set_info['selling_price'] ?? 0),
                                $_SESSION['user_id'] ?? null,
                                $set_location_id
                            );
                            
                            // Reduce stock for each component from the set's selected location
                            foreach ($set_components as $component) {
                                $total_to_reduce = $component['quantity'] * $additional_quantity;
                                
                                if ($set_location_id) {
                                    $inventory_stmt = $pdo->prepare("
                                        SELECT id, storage_location_id, quantity_on_hand 
                                        FROM current_inventory 
                                        WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 
                                        ORDER BY last_updated ASC
                                    ");
                                    $inventory_stmt->execute([$component['product_name'], $set_location_id]);
                                } else {
                                    $inventory_stmt = $pdo->prepare("
                                        SELECT id, storage_location_id, quantity_on_hand 
                                        FROM current_inventory 
                                        WHERE item_name = ? AND quantity_on_hand > 0 
                                        ORDER BY last_updated ASC
                                    ");
                                    $inventory_stmt->execute([$component['product_name']]);
                                }
                                
                                $inventory_items = $inventory_stmt->fetchAll(PDO::FETCH_ASSOC);
                                $remaining_to_reduce = $total_to_reduce;
                                
                                foreach ($inventory_items as $inventory_item) {
                                    if ($remaining_to_reduce <= 0) break;
                                    
                                    $reduce_amount = min($remaining_to_reduce, $inventory_item['quantity_on_hand']);
                                    
                                    // Update inventory
                                    $update_stmt = $pdo->prepare("
                                        UPDATE current_inventory 
                                        SET quantity_on_hand = quantity_on_hand - ?, 
                                            last_updated = NOW()
                                        WHERE id = ?
                                    ");
                                    $update_stmt->execute([$reduce_amount, $inventory_item['id']]);
                                    
                                    // Log stock operation
                                    $log_stmt = $pdo->prepare("
                                        INSERT INTO stock_operations 
                                        (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
                                        VALUES (?, 'set_addition', ?, 'product_set', ?, 'Added to product set stock', ?)
                                    ");
                                    $log_stmt->execute([
                                        $inventory_item['storage_location_id'] ?: $set_location_id,
                                        $reduce_amount, 
                                        $set_info['id'], 
                                        $_SESSION['user_id'] ?? null
                                    ]);
                                    
                                    $remaining_to_reduce -= $reduce_amount;
                                }
                                
                                if ($remaining_to_reduce > 0) {
                                    // This shouldn't happen since we checked stock beforehand, but just in case
                                    $pdo->rollBack();
                                    $errors[] = "Unexpected stock shortage for {$component['product_name']}";
                                    break;
                                }
                            }
                            
                            if (empty($errors)) {
                                // Resolve current user for logging
                                $current_user_id = $_SESSION['user_id'] ?? null;
                                $current_user_name = '';
                                if ($current_user_id) {
                                    $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                                    $user_stmt->execute([$current_user_id]);
                                    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                                    $current_user_name = $user_data['name'] ?? 'Unknown User';
                                }

                                // Log the action
                                logUserAction($pdo, $set_info['id'], 'stock_added', $current_user_id, $current_user_name, 
                                    "Added {$additional_quantity} more sets to stock. New total: " . ($old_stock + $additional_quantity));
                                
                                $pdo->commit();
                                $success = "Successfully added {$additional_quantity} more sets to '{$set_name}'. New available stock: " . ($old_stock + $additional_quantity);
                            }
                        }
                    }
                } else {
                    $errors[] = "Product set not found";
                }
            } catch (Exception $e) {
                $errors[] = "Error adding more sets: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid set name or quantity";
        }

        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            // Catch any PHP errors and return them as JSON
            try {
                header('Content-Type: application/json');
                if (!empty($errors)) {
                    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
                } else {
                    echo json_encode(['success' => true, 'message' => $success]);
                }
            } catch (Exception $e) {
                // Catch JSON encoding errors
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            }
            exit;
        }
    } elseif ($action === 'delete_set') {
        require_role_or_permission(['admin'], 'product_sets.delete');
        $set_id = (int)($_POST['set_id'] ?? 0);

        if ($set_id > 0) {
            try {
                $pdo->beginTransaction();

                // Get set info before deletion for logging
                $stmt = $pdo->prepare("SELECT set_name, available_stock, total_created FROM product_sets WHERE id = ?");
                $stmt->execute([$set_id]);
                $set_info = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($set_info) {
                    // Get current user info
                    $current_user_id = $_SESSION['user_id'] ?? null;
                    $current_user_name = '';
                    if ($current_user_id) {
                        $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                        $user_stmt->execute([$current_user_id]);
                        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        $current_user_name = $user_data['name'] ?? 'Unknown User';
                    }

                    // Log the deletion before actually deleting
                    logUserAction($pdo, $set_id, 'deleted', $current_user_id, $current_user_name, 
                        "Product set '{$set_info['set_name']}' deleted. Had {$set_info['available_stock']} sets available, {$set_info['total_created']} total created");
                }

                // First, get the product record ID for this set
                $stmt = $pdo->prepare("SELECT id FROM products WHERE name = (SELECT set_name FROM product_sets WHERE id = ?) AND product_type = 'set'");
                $stmt->execute([$set_id]);
                $product_record = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($product_record) {
                    // Delete cost records
                    $stmt = $pdo->prepare("DELETE FROM product_costs WHERE product_id = ?");
                    $stmt->execute([$product_record['id']]);

                    // Delete product record
                    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->execute([$product_record['id']]);
                }

                // Delete product set items
                $stmt = $pdo->prepare("DELETE FROM product_set_items WHERE product_set_id = ?");
                $stmt->execute([$set_id]);

                // Delete product set
                $stmt = $pdo->prepare("DELETE FROM product_sets WHERE id = ?");
                $stmt->execute([$set_id]);

                $pdo->commit();
                $success = "Product set and all related records deleted successfully!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error deleting product set: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid set ID";
        }

        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            } else {
                echo json_encode(['success' => true, 'message' => $success]);
            }
            exit;
        }
    } elseif ($action === 'get_set_products') {
        $set_name = trim($_POST['set_name'] ?? '');

        if (!empty($set_name)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        psi.product_id,
                        p.name as product_name,
                        psi.quantity,
                        psi.unit_cost,
                        psi.total_cost
                    FROM product_set_items psi
                    JOIN products p ON psi.product_id = p.id
                    JOIN product_sets ps ON psi.product_set_id = ps.id
                    WHERE ps.set_name = ?
                    ORDER BY p.name
                ");
                $stmt->execute([$set_name]);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'products' => $products]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading set products: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid set name']);
        }
        exit;
    } elseif ($action === 'get_available_products') {
        try {
            $set_name = trim($_POST['set_name'] ?? '');

            if ($set_name !== '') {
                // When editing a specific set: exclude only products already in THIS set
                $stmt = $pdo->prepare("
                    SELECT p.id, p.name, p.cost
                    FROM products p
                    WHERE p.product_type != 'set'
                      AND p.active = 1
                      AND p.id NOT IN (
                          SELECT psi.product_id
                          FROM product_set_items psi
                          JOIN product_sets ps ON psi.product_set_id = ps.id
                          WHERE ps.set_name = ?
                      )
                    ORDER BY p.name
                ");
                $stmt->execute([$set_name]);
            } else {
                // Fallback: exclude products that are in any set (used when creating new sets)
                $stmt = $pdo->query("
                    SELECT p.id, p.name, p.cost
                    FROM products p
                    WHERE p.product_type != 'set' 
                      AND p.active = 1
                      AND p.id NOT IN (
                          SELECT DISTINCT psi.product_id 
                          FROM product_set_items psi
                      )
                    ORDER BY p.name
                ");
            }

            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'products' => $products]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading available products: ' . $e->getMessage()]);
        }
        exit;
    } elseif ($action === 'add_product_to_set') {
        $set_name = trim($_POST['set_name'] ?? '');
        $product_id = (int)($_POST['product_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);

        if (!empty($set_name) && $product_id > 0 && $quantity > 0) {
            try {
                // Get set ID
                $stmt = $pdo->prepare("SELECT id FROM product_sets WHERE set_name = ?");
                $stmt->execute([$set_name]);
                $set = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($set) {
                    // Get product cost and name
                    $stmt = $pdo->prepare("SELECT cost, name FROM products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($product) {
                        $unit_cost = floatval($product['cost']);
                        $total_cost = $unit_cost * $quantity;
                        $product_name = $product['name'];

                        // Check if product already exists in set
                        $stmt = $pdo->prepare("
                            SELECT id, quantity, total_cost 
                            FROM product_set_items 
                            WHERE product_set_id = ? AND product_id = ?
                        ");
                        $stmt->execute([$set['id'], $product_id]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                        $action_details = '';
                        if ($existing) {
                            // Update existing quantity and cost
                            $old_quantity = $existing['quantity'];
                            $new_quantity = $old_quantity + $quantity;
                            $new_total_cost = $unit_cost * $new_quantity;

                            $stmt = $pdo->prepare("
                                UPDATE product_set_items 
                                SET quantity = ?, total_cost = ?
                                WHERE product_set_id = ? AND product_id = ?
                            ");
                            $stmt->execute([$new_quantity, $new_total_cost, $set['id'], $product_id]);

                            $action_details = "Added $quantity more units of '$product_name' to '$set_name' (total: $old_quantity → $new_quantity)";
                        } else {
                            // Add new product to set
                            $stmt = $pdo->prepare("
                                INSERT INTO product_set_items (product_set_id, product_id, quantity, unit_cost, total_cost)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$set['id'], $product_id, $quantity, $unit_cost, $total_cost]);

                            $action_details = "Added '$product_name' ($quantity units @ \$$unit_cost each) to '$set_name'";
                        }

                        // Update set total cost
                        updateSetTotalCost($pdo, $set['id']);

                        // Get current user info
                        $current_user_id = $_SESSION['user_id'] ?? null;
                        $current_user_name = '';
                        if ($current_user_id) {
                            $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                            $user_stmt->execute([$current_user_id]);
                            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                            $current_user_name = $user_data['name'] ?? 'Unknown User';
                        }

                        // Log the action
                        logUserAction($pdo, $set['id'], 'product_added', $current_user_id, $current_user_name, $action_details);

                        // Update product set costs automatically
                        updateProductSetCosts($pdo, $set['id']);

                        $success = "Product added to set successfully!";
                    } else {
                        $errors[] = "Product not found";
                    }
                } else {
                    $errors[] = "Product set not found";
                }
            } catch (Exception $e) {
                $errors[] = "Error adding product to set: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid parameters";
        }

        // Return JSON response
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            } else {
                echo json_encode(['success' => true, 'message' => $success]);
            }
            exit;
        }
    } elseif ($action === 'update_product_quantity') {
        $set_name = trim($_POST['set_name'] ?? '');
        $product_id = (int)($_POST['product_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);

        if (!empty($set_name) && $product_id > 0 && $quantity > 0) {
            try {
                // Get set ID
                $stmt = $pdo->prepare("SELECT id FROM product_sets WHERE set_name = ?");
                $stmt->execute([$set_name]);
                $set = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($set) {
                    // Get product info and current quantity
                    $stmt = $pdo->prepare("
                        SELECT p.cost, psi.quantity as old_quantity 
                        FROM products p 
                        JOIN product_set_items psi ON p.id = psi.product_id 
                        WHERE p.id = ? AND psi.product_set_id = ?
                    ");
                    $stmt->execute([$product_id, $set['id']]);
                    $product_info = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($product_info) {
                        $old_quantity = $product_info['old_quantity'];
                        $unit_cost = floatval($product_info['cost']);
                        $total_cost = $unit_cost * $quantity;

                        // Update quantity and total cost
                        $stmt = $pdo->prepare("
                            UPDATE product_set_items 
                            SET quantity = ?, total_cost = ?
                            WHERE product_set_id = ? AND product_id = ?
                        ");
                        $stmt->execute([$quantity, $total_cost, $set['id'], $product_id]);

                        // Update set total cost
                        updateSetTotalCost($pdo, $set['id']);

                        // Get current user info
                        $current_user_id = $_SESSION['user_id'] ?? null;
                        $current_user_name = '';
                        if ($current_user_id) {
                            $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                            $user_stmt->execute([$current_user_id]);
                            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                            $current_user_name = $user_data['name'] ?? 'Unknown User';
                        }

                        // Get product name for logging
                        $product_name_stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                        $product_name_stmt->execute([$product_id]);
                        $product_name_data = $product_name_stmt->fetch(PDO::FETCH_ASSOC);
                        $product_name = $product_name_data['name'] ?? 'Unknown Product';

                        // Log the quantity update
                        logUserAction($pdo, $set['id'], 'product_quantity_updated', $current_user_id, $current_user_name, 
                            "Updated quantity for '$product_name' in '$set_name': $old_quantity → $quantity units");

                        // Update product set costs automatically
                        updateProductSetCosts($pdo, $set['id']);

                        $success = "Product quantity updated successfully!";
                    } else {
                        $errors[] = "Product not found in set";
                    }
                } else {
                    $errors[] = "Product set not found";
                }
            } catch (Exception $e) {
                $errors[] = "Error updating product quantity: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid parameters";
        }

        // Return JSON response
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            } else {
                echo json_encode(['success' => true, 'message' => $success]);
            }
            exit;
        }
    } elseif ($action === 'remove_product_from_set') {
        $set_name = trim($_POST['set_name'] ?? '');
        $product_id = (int)($_POST['product_id'] ?? 0);

        if (!empty($set_name) && $product_id > 0) {
            try {
                // Get set ID and product info before removal
                $stmt = $pdo->prepare("SELECT id FROM product_sets WHERE set_name = ?");
                $stmt->execute([$set_name]);
                $set = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($set) {
                    // Get product info before removal for logging
                    $stmt = $pdo->prepare("
                        SELECT p.name, psi.quantity, psi.unit_cost, psi.total_cost
                        FROM products p
                        JOIN product_set_items psi ON p.id = psi.product_id
                        WHERE p.id = ? AND psi.product_set_id = ?
                    ");
                    $stmt->execute([$product_id, $set['id']]);
                    $product_info = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($product_info) {
                        $product_name = $product_info['name'];
                        $quantity = $product_info['quantity'];
                        $unit_cost = $product_info['unit_cost'];
                        $total_cost = $product_info['total_cost'];

                        // Remove product from set
                        $stmt = $pdo->prepare("
                            DELETE FROM product_set_items 
                            WHERE product_set_id = ? AND product_id = ?
                        ");
                        $stmt->execute([$set['id'], $product_id]);

                        // Update set total cost
                        updateSetTotalCost($pdo, $set['id']);

                        // Get current user info
                        $current_user_id = $_SESSION['user_id'] ?? null;
                        $current_user_name = '';
                        if ($current_user_id) {
                            $user_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                            $user_stmt->execute([$current_user_id]);
                            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                            $current_user_name = $user_data['name'] ?? 'Unknown User';
                        }

                        // Log the removal action
                        logUserAction($pdo, $set['id'], 'product_removed', $current_user_id, $current_user_name, 
                            "Removed '$product_name' ($quantity units @ \$$unit_cost each, total \$$total_cost) from '$set_name'");

                        // Update product set costs automatically
                        updateProductSetCosts($pdo, $set['id']);

                        $success = "Product removed from set successfully!";
                    } else {
                        $errors[] = "Product not found in set";
                    }
                } else {
                    $errors[] = "Product set not found";
                }
            } catch (Exception $e) {
                $errors[] = "Error removing product from set: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid parameters";
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            } else {
                echo json_encode(['success' => true, 'message' => $success]);
            }
            exit;
        }
    } elseif ($action === 'get_edit_history') {
        $set_id = (int)($_POST['set_id'] ?? 0);

        if ($set_id > 0) {
            $stmt = $pdo->prepare("
                SELECT id, set_name
                FROM product_sets
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$set_id]);
            $set_info = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($set_info) {
                $stmt = $pdo->prepare("
                    SELECT action_type, user_name, action_details, created_at
                    FROM product_set_audit_log
                    WHERE product_set_id = ?
                    ORDER BY created_at DESC
                ");
                $stmt->execute([$set_id]);
                $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $autoStmt = $pdo->prepare("
                    SELECT so.operation_type AS action_type,
                           COALESCE(u.name, CONCAT('User #', so.created_by)) AS user_name,
                           so.notes AS action_details,
                           so.created_at
                    FROM stock_operations so
                    LEFT JOIN users u ON u.id = so.created_by
                    WHERE so.reference_type = 'product_set'
                      AND so.reference_id = ?
                      AND so.operation_type = 'set_auto_created'
                    ORDER BY so.created_at DESC
                ");
                $autoStmt->execute([$set_id]);
                $auto_logs = $autoStmt->fetchAll(PDO::FETCH_ASSOC);

                $combined_logs = array_merge($audit_logs, $auto_logs);
                usort($combined_logs, function($a, $b) {
                    return strcmp($b['created_at'], $a['created_at']);
                });

                $history = [];

                foreach ($combined_logs as $log) {
                    $action_display = ucfirst(str_replace('_', ' ', $log['action_type']));
                    $action_details = $log['action_details'];
                    $location_name = '';

                    if (preg_match('/storage_location_id:([0-9]+)/', (string)$action_details, $matches)) {
                        $locId = (int)$matches[1];
                        if ($locId > 0) {
                            try {
                                $locStmt = $pdo->prepare("SELECT location_name FROM storage_locations WHERE id = ? LIMIT 1");
                                $locStmt->execute([$locId]);
                                $location_name = $locStmt->fetchColumn() ?: '';
                            } catch (Exception $e) {
                            }
                        }
                    }

                    if (!empty($location_name)) {
                        $action_details = str_replace($matches[0] ?? '', 'storage location: ' . $location_name, (string)$action_details);
                    }

                    $history[] = [
                        'action' => $action_display,
                        'user' => !empty($log['user_name']) ? $log['user_name'] : 'Unknown User',
                        'timestamp' => $log['created_at'],
                        'details' => $action_details
                    ];
                }

                if (empty($history)) {
                    $stmt = $pdo->prepare("
                        SELECT ps.created_at, ps.total_created, ps.created_by,
                               u.name as created_by_name
                        FROM product_sets ps
                        LEFT JOIN users u ON ps.created_by = u.id
                        WHERE ps.id = ?
                    ");
                    $stmt->execute([$set_id]);
                    $basic_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($basic_info) {
                        $created_by_user = !empty($basic_info['created_by_name']) ? $basic_info['created_by_name'] : 'Unknown User';
                        $history[] = [
                            'action' => 'Created',
                            'user' => $created_by_user,
                            'timestamp' => $basic_info['created_at'],
                            'details' => 'Product set was created with initial quantity: ' . ($basic_info['total_created'] ?? 0) . ' sets'
                        ];
                    }
                }

                echo json_encode(['success' => true, 'history' => $history, 'set_name' => $set_info['set_name']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Product set not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid set ID']);
        }
        exit;
    }
}

// Helper function to log user actions
function logUserAction($pdo, $product_set_id, $action_type, $user_id, $user_name, $action_details, $old_values = null, $new_values = null) {
    // Resolve a user-friendly name even if caller passed empty
    $resolvedUserName = $user_name;

    if (empty($resolvedUserName) && $user_id) {
        try {
            $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $fetchedName = $stmt->fetchColumn();
            if (!empty($fetchedName)) {
                $resolvedUserName = $fetchedName;
            }
        } catch (Exception $e) {
            error_log("Error resolving user name for audit log: " . $e->getMessage());
        }
    }

    if (empty($resolvedUserName)) {
        $resolvedUserName = 'Unknown User';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO product_set_audit_log 
            (product_set_id, action_type, user_id, user_name, action_details, old_values, new_values)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $product_set_id,
            $action_type,
            $user_id,
            $resolvedUserName,
            $action_details,
            $old_values ? json_encode($old_values) : null,
            $new_values ? json_encode($new_values) : null
        ]);
    } catch (Exception $e) {
        error_log("Error logging user action: " . $e->getMessage());
    }
}

// Function to automatically update product set costs when individual products are modified
function updateProductSetCosts($pdo, $set_id) {
    try {
        // Calculate new total cost from all items
        $stmt = $pdo->prepare("
            SELECT SUM(total_cost) as total_cost 
            FROM product_set_items 
            WHERE product_set_id = ?
        ");
        $stmt->execute([$set_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_cost = floatval($result['total_cost'] ?? 0);

        // Update set total cost
        $stmt = $pdo->prepare("UPDATE product_sets SET total_cost = ? WHERE id = ?");
        $stmt->execute([$total_cost, $set_id]);

        // Get set details for updating products table
        $stmt = $pdo->prepare("
            SELECT set_name, selling_price, commission_rate 
            FROM product_sets 
            WHERE id = ?
        ");
        $stmt->execute([$set_id]);
        $set_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($set_data) {
            $selling_price = floatval($set_data['selling_price']);
            $commission_rate = floatval($set_data['commission_rate']);
            $commission_amount = $selling_price * ($commission_rate / 100);

            // Update products table with new cost
            $stmt = $pdo->prepare("
                UPDATE products 
                SET cost = ? 
                WHERE name = ? AND product_type = 'set'
            ");
            $stmt->execute([$selling_price, $set_data['set_name']]);

            // Update commission amount in product_sets
            $stmt = $pdo->prepare("
                UPDATE product_sets 
                SET commission_amount = ? 
                WHERE id = ?
            ");
            $stmt->execute([$commission_amount, $set_id]);

            // Update product_costs table for current month
            $current_month = date('Y-m');
            $stmt = $pdo->prepare("
                UPDATE product_costs 
                SET original_cost = ?
                WHERE product_id = (SELECT id FROM products WHERE name = ? AND product_type = 'set')
                AND month_year = ?
            ");
            $stmt->execute([$total_cost, $set_data['set_name'], $current_month]);
        }

        return $total_cost;
    } catch (Exception $e) {
        error_log("Error updating product set costs: " . $e->getMessage());
        return false;
    }
}

// Helper function to update set total cost
function updateSetTotalCost($pdo, $set_id) {
    // Calculate new total cost from all items
    $stmt = $pdo->prepare("
        SELECT SUM(total_cost) as total_cost 
        FROM product_set_items 
        WHERE product_set_id = ?
    ");
    $stmt->execute([$set_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_cost = floatval($result['total_cost'] ?? 0);

    // Update set total cost
    $stmt = $pdo->prepare("UPDATE product_sets SET total_cost = ? WHERE id = ?");
    $stmt->execute([$total_cost, $set_id]);

    // Update selling price in products table if needed
    $stmt = $pdo->prepare("
        SELECT selling_price, commission_rate 
        FROM product_sets 
        WHERE id = ?
    ");
    $stmt->execute([$set_id]);
    $set_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($set_data) {
        $selling_price = floatval($set_data['selling_price']);
        $commission_rate = floatval($set_data['commission_rate']);
        $commission_amount = $selling_price * ($commission_rate / 100);

        // Update products table
        $stmt = $pdo->prepare("
            UPDATE products 
            SET cost = ? 
            WHERE name = (SELECT set_name FROM product_sets WHERE id = ?) 
            AND product_type = 'set'
        ");
        $stmt->execute([$selling_price, $set_id]);

        // Update commission amount in product_sets
        $stmt = $pdo->prepare("
            UPDATE product_sets 
            SET commission_amount = ? 
            WHERE id = ?
        ");
        $stmt->execute([$commission_amount, $set_id]);
    }
}

// Get all product sets
try {
    $stmt = $pdo->query("
        SELECT ps.*,
               COUNT(psi.id) as product_count,
               GROUP_CONCAT(p.name SEPARATOR ', ') as product_names,
               u.name as created_by_name
        FROM product_sets ps
        LEFT JOIN product_set_items psi ON ps.id = psi.product_set_id
        LEFT JOIN products p ON psi.product_id = p.id
        LEFT JOIN users u ON ps.created_by = u.id
        WHERE ps.is_active = 1
        GROUP BY ps.id
        ORDER BY ps.created_at DESC
    ");
    $product_sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $product_sets = [];
    $errors[] = "Error loading product sets: " . $e->getMessage();
}

// Get products available in default storage location for product set creation
try {
    // Get default storage location first
    $default_location_stmt = $pdo->prepare("SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1");
    $default_location_stmt->execute();
    $default_location_id = $default_location_stmt->fetchColumn();
    
    if ($default_location_id) {
        // Get products that have stock in default location
        $stmt = $pdo->query("
            SELECT DISTINCT p.id, p.name,
                   COALESCE(pc.original_cost, p.cost, 0) as cost
            FROM products p
            LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = DATE_FORMAT(NOW(), '%Y-%m')
            INNER JOIN current_inventory ci ON p.name = ci.item_name AND ci.storage_location_id = $default_location_id AND ci.quantity_on_hand > 0
            WHERE p.active = 1
            ORDER BY p.name
        ");
    } else {
        // No default location, show all products
        $stmt = $pdo->query("
            SELECT p.id, p.name,
                   COALESCE(pc.original_cost, p.cost, 0) as cost
            FROM products p
            LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = DATE_FORMAT(NOW(), '%Y-%m')
            WHERE p.active = 1
            ORDER BY p.name
        ");
    }
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

require_once __DIR__ . '/../layout/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-collection me-2"></i>Product Set Management</h2>
            <small class="text-muted">Create and manage product bundles/packages</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php" class="btn btn-outline-primary">
                <i class="bi bi-qr-code me-2"></i>QR Labels
            </a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSetModal">
                <i class="bi bi-plus-circle me-2"></i>Create Product Set
            </button>
        </div>
    </div>

    <!-- Month Selection -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Target Month for Cost Records</label>
                    <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($selected_month) ?>" required>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calendar me-1"></i>Set Month
                    </button>
                </div>
                <div class="col-md-auto">
                    <div class="alert alert-info mb-0 py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Target:</strong> <?= htmlspecialchars($selected_month_display) ?>
                        <?php if ($selected_month !== date('Y-m')): ?>
                            <span class="badge bg-warning ms-2">Future/Past Month</span>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <?php if (is_array($error) && $error['type'] === 'stock_shortage'): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-box-seam me-3 fs-4 text-warning"></i>
                    <div class="flex-grow-1">
                        <h6 class="alert-heading mb-2">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?= $error['location_issue'] ? 'Insufficient Stock in Selected Location' : 'Insufficient Stock for Product Set Creation' ?>
                        </h6>
                        <div class="mb-2">
                            <strong>Product:</strong> <?= htmlspecialchars($error['product']) ?>
                            <?php if ($error['location_issue']): ?>
                                <div class="mt-2 text-danger">
                                    <i class="bi bi-geo-alt me-1"></i>
                                    <strong>Location Issue:</strong> No inventory found for this product in the selected storage location. Please check if the product exists in that location or select a different location.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="badge bg-danger me-2">
                                        <i class="bi bi-x-circle me-1"></i>
                                        Available: <?= number_format($error['available']) ?>
                                    </span>
                                    <span class="badge bg-warning me-2">
                                        <i class="bi bi-arrow-right me-1"></i>
                                        Required: <?= number_format($error['required']) ?>
                                    </span>
                                    <span class="badge bg-info">
                                        <i class="bi bi-dash-circle me-1"></i>
                                        Shortage: <?= number_format($error['shortage']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-muted">
                                    <div><i class="bi bi-info-circle me-1"></i> Calculation: <?= number_format($error['per_set']) ?> × <?= number_format($error['sets_to_create']) ?> sets</div>
                                    <div class="mt-2">
                                        <?php if ($error['location_issue']): ?>
                                            <i class="bi bi-lightbulb me-1"></i>
                                            <strong>Solution:</strong> Select a different storage location that has this product in stock, or add the product to the selected location first.
                                        <?php else: ?>
                                            <i class="bi bi-lightbulb me-1"></i>
                                            <strong>Solution:</strong> Add <?= number_format($error['shortage']) ?> more units to inventory or reduce the number of sets to create.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php else: ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars(is_string($error) ? $error : 'An error occurred') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Info Alert -->
    <div class="alert alert-info">
        <h6><i class="bi bi-info-circle me-2"></i>How Product Sets Work:</h6>
        <ul class="mb-0">
            <li><strong>Product Sets</strong> are bundles of multiple products sold together</li>
            <li><strong>Total Cost</strong> is automatically calculated from individual product costs</li>
            <li><strong>Set Selling Price</strong> determines the profit margin for the bundle</li>
            <li><strong>Target Month</strong> determines which month the cost record will be created in Product Costs</li>
            <li><strong>Inventory</strong> is tracked per individual product in the set</li>
        </ul>
        <div class="mt-2">
            <small><i class="bi bi-lightbulb me-1"></i>
            <strong>Tip:</strong> Set the target month to create cost records for future planning or historical data entry.
            Product sets will appear in Product Costs under the selected month.
        </small>
        </div>
    </div>

    <!-- Product Set Details Table -->
    <div class="card mt-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">
                <i class="bi bi-boxes me-2"></i>Product Set Details
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" style="font-size: 14px;">
                    <thead class="table-light">
                        <tr>
                            <th>Set Name</th>
                            <th>Products</th>
                            <th>Total Products</th>
                            <th>Total Cost</th>
                            <th>Selling Price</th>
                            <th>Profit Margin</th>
                            <th>Commission</th>
                            <th>Source Location</th>
                            <th>Current Stock</th>
                            <th>Total Qty Created</th>
                            <th>Created By</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Get all product sets with consolidated item details
                        $stmt = $pdo->query("
                            SELECT 
                                ps.id,
                                ps.set_name,
                                ps.available_stock,
                                ps.total_created,
                                ps.total_cost as set_total_cost,
                                ps.selling_price,
                                ps.profit_margin,
                                ps.commission_rate,
                                ps.commission_amount,
                                ps.created_at,
                                COUNT(psi.id) as total_products,
                                GROUP_CONCAT(
                                    CONCAT(p.name, ' (', psi.quantity, 'x @ $', FORMAT(psi.unit_cost, 2), ')')
                                    ORDER BY p.name
                                    SEPARATOR ', '
                                ) as product_breakdown,
                                SUM(psi.total_cost) as calculated_total_cost,
                                u.name as created_by_name,
                                COALESCE(sl.location_code, 'N/A') as source_location,
                                COALESCE(sl.location_name, 'N/A') as source_location_name,
                                ps.storage_location_id as source_location_id
                            FROM product_sets ps
                            LEFT JOIN product_set_items psi ON ps.id = psi.product_set_id
                            LEFT JOIN products p ON psi.product_id = p.id
                            LEFT JOIN users u ON ps.created_by = u.id
                            LEFT JOIN storage_locations sl ON sl.id = ps.storage_location_id
                            GROUP BY ps.id, ps.set_name, ps.available_stock, ps.total_created, ps.total_cost, ps.selling_price, ps.profit_margin, ps.commission_rate, ps.commission_amount, ps.created_at, created_by_name, sl.location_code, sl.location_name, ps.storage_location_id
                            ORDER BY ps.set_name
                        ");
                        $set_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (empty($set_items)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted">
                                    <i class="bi bi-info-circle me-2"></i>
                                    No product sets found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($set_items as $set): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($set['set_name']) ?></strong>
                                    </td>
                                    <td style="max-width: 300px;">
                                        <small class="text-muted">
                                            <?= htmlspecialchars($set['product_breakdown'] ?? 'No products') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?= number_format($set['total_products'] ?? 0) ?> items
                                        </span>
                                    </td>
                                    <td>$<?= number_format($set['set_total_cost'] ?? 0, 2) ?></td>
                                    <td>$<?= number_format($set['selling_price'] ?? 0, 2) ?></td>
                                    <td>
                                        <?php 
                                        $margin = $set['profit_margin'] ?? 0;
                                        $margin_class = $margin > 20 ? 'success' : ($margin > 10 ? 'warning' : ($margin > 0 ? 'info' : 'danger'));
                                        ?>
                                        <span class="badge bg-<?= $margin_class ?>">
                                            <?= number_format($margin, 1) ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-center">
                                            <div class="fw-bold">$<?= number_format($set['commission_amount'] ?? 0, 2) ?></div>
                                            <small class="text-muted"><?= number_format($set['commission_rate'] ?? 0, 1) ?>%</small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <i class="bi bi-geo-alt me-1"></i>
                                            <?= htmlspecialchars($set['source_location'] ?? 'N/A') ?>
                                        </span>
                                        <?php if (!empty($set['source_location_name'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($set['source_location_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $stock = $set['available_stock'] ?? 0;
                                        
                                        // Determine stock level and color
                                        if ($stock == 0) {
                                            $stock_status = 'danger';
                                            $stock_text = 'Out of Stock';
                                            $suggestion = 'Create More Sets';
                                        } elseif ($stock <= 5) {
                                            $stock_status = 'warning';
                                            $stock_text = 'Low Stock';
                                            $suggestion = 'Consider Adding More';
                                        } else {
                                            $stock_status = 'success';
                                            $stock_text = 'Good Stock';
                                            $suggestion = '';
                                        }
                                        ?>
                                        <div class="d-flex flex-column align-items-start">
                                            <span class="badge bg-<?= $stock_status ?> me-1 mb-1" style="font-size: 10px;">
                                                <?= number_format($stock, 0) ?> (<?= $stock_text ?>)
                                            </span>
                                            <?php if (!empty($suggestion)): ?>
                                                <small class="text-<?= $stock_status ?> fw-bold" style="font-size: 9px;">
                                                    <i class="bi bi-exclamation-triangle me-1"></i><?= $suggestion ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= number_format($set['total_created'] ?? 0) ?></td>
                                    <td><?php if (!empty($set['created_by_name'])): ?><?= htmlspecialchars($set['created_by_name']) ?><?php else: ?>-<?php endif; ?></td>
                                    <td><?= date('M j, Y', strtotime($set['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editSetDetails(<?= $set['id'] ?>, '<?= htmlspecialchars($set['set_name']) ?>', '<?= htmlspecialchars($set['product_breakdown'] ?? '') ?>', <?= $set['selling_price'] ?? 0 ?>, <?= (int)($set['source_location_id'] ?? 0) ?>)">
                                            <i class="bi bi-pencil-square me-1"></i>Edit
                                        </button>
                                        <button class="btn btn-sm btn-outline-info me-1" onclick="showEditHistory(<?= $set['id'] ?>, '<?= htmlspecialchars($set['set_name']) ?>')">
                                            <i class="bi bi-clock-history me-1"></i>History
                                        </button>
                                        <button class="btn btn-sm btn-outline-success" onclick="addMoreSets('<?= htmlspecialchars($set['set_name']) ?>', <?= $set['available_stock'] ?? 0 ?>)">
                                            <i class="bi bi-plus-circle me-1"></i>Add More
                                        </button>
                                        <a class="btn btn-sm btn-outline-dark mt-1"
                                           href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php?<?= http_build_query(['set_id' => (int)$set['id'], 'quantity_mode' => 'available']) ?>">
                                            <i class="bi bi-qr-code me-1"></i>QR Qty
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Note:</strong> This table shows a summary of each product set with all components listed in one row. 
                    Use this to get an overview of your product sets and their profitability.
                </small>
            </div>
        </div>
    </div>

</div>

<!-- Create Product Set Modal -->
<div class="modal fade" id="createSetModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>Create Product Set
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createSetForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_set">

                    <!-- Set Basic Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="setName" class="form-label">
                                <strong>Set Name *</strong>
                            </label>
                            <input type="text" class="form-control" id="setName" name="set_name"
                                   placeholder="e.g., Office Starter Kit" required>
                        </div>
                        <div class="col-md-6">
                            <label for="setDescription" class="form-label">
                                <strong>Description</strong>
                            </label>
                            <input type="text" class="form-control" id="setDescription" name="set_description"
                                   placeholder="Optional description">
                        </div>
                    </div>

                    <!-- Storage Location Selection -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <label for="sourceLocation" class="form-label">
                                <strong>🏪 Source Storage Location *</strong>
                            </label>
                            <select class="form-select" id="sourceLocation" name="source_location_id" required>
                                <option value="">Select storage location to take stock from...</option>
                                <?php 
                                $locations_stmt = $pdo->query("SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code");
                                $locations = $locations_stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($locations as $location): ?>
                                    <option value="<?= $location['id'] ?>" data-location="<?= htmlspecialchars($location['location_code']) ?>">
                                        <?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['location_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong>Important:</strong> Stock for all product components will be reduced from this location. Make sure it has sufficient inventory for all components.
                            </div>
                        </div>
                    </div>

                    <!-- Product Selection -->
                    <div class="mb-4">
                        <h6><i class="bi bi-box-seam me-2"></i>Select Products for Set</h6>
                        <div id="productList">
                            <div class="product-item mb-3 p-3 border rounded">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <select class="form-select product-select" name="product_ids[]" required>
                                            <option value="">Choose Product...</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?= $product['id'] ?>" data-cost="<?= $product['cost'] ?>">
                                                    <?= htmlspecialchars($product['name']) ?> ($<?= number_format($product['cost'], 2) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control quantity-input" name="quantities[]"
                                               placeholder="Qty" value="1" min="0.1" step="0.1" required>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control cost-display" readonly
                                               placeholder="Cost">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control total-display" readonly
                                               placeholder="Total">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-product">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="addProductBtn">
                            <i class="bi bi-plus me-1"></i>Add Another Product
                        </button>
                    </div>

                    <!-- Cost Summary -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="bi bi-calculator me-2"></i>Cost Summary & Pricing
                            </h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Total Products:</strong> <span id="totalProducts">0</span></p>
                                    <p class="mb-1"><strong>Total Cost:</strong> $<span id="totalCost">0.00</span></p>
                                </div>
                                <div class="col-md-6">
                                    <!-- Selling Price Field -->
                                    <div class="mb-2">
                                        <label for="setSellingPrice" class="form-label">
                                            <strong>Selling Price *</strong>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="setSellingPrice" name="selling_price"
                                                   step="0.01" min="0" required onchange="calculateProfitMargin()">
                                        </div>
                                    </div>
                                    
                                    <!-- Commission Rate Field -->
                                    <div class="mb-2">
                                        <label for="setCommissionRate" class="form-label">
                                            <strong>Commission Rate (%)</strong>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control" id="setCommissionRate" name="commission_rate"
                                                   step="0.1" min="0" max="100" value="0" onchange="calculateCommission()">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Commission Amount Field -->
                                    <div class="mb-2">
                                        <label for="setCommissionAmount" class="form-label">
                                            <strong>Commission Amount ($)</strong>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="setCommissionAmount" name="commission_amount"
                                                   step="0.01" min="0" value="0.00" onchange="calculateCommission()">
                                        </div>
                                    </div>
                                    
                                    <!-- Available Stock Field -->
                                    <div class="mb-2">
                                        <label for="availableStock" class="form-label">
                                            <strong>📦 Total Quantity to Create</strong>
                                        </label>
                                        <div class="input-group input-group-sm">
                                             <input type="number" class="form-control" id="availableStock" name="available_stock"
                                                   step="1" min="0" value="0" required>
                                            <span class="input-group-text">sets</span>
                                        </div>
                                        <div class="form-text">
                                            <i class="bi bi-info-circle me-1"></i>Enter 0 to create the product set without using component stock. You can add completed sets later.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Profit Margin Display -->
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="alert alert-success">
                                        <h6 class="mb-2"><i class="bi bi-graph-up me-2"></i>Profit Analysis</h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Profit Margin:</strong> <span id="profitMargin">0.0%</span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Profit per Set:</strong> $<span id="profitPerSet">0.00</span>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Final Price:</strong> $<span id="finalPrice">0.00</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Product Set</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add More Sets Modal -->
<div class="modal fade" id="addMoreSetsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>Add More Sets to Stock
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addMoreSetsForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_more_sets">
                    <input type="hidden" name="set_name" id="addMoreSetName">

                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle me-2"></i>Current Stock Information</h6>
                        <p class="mb-1"><strong>Product Set:</strong> <span id="addMoreSetDisplayName"></span></p>
                        <p class="mb-0"><strong>Current Available Stock:</strong> <span id="addMoreCurrentStock"></span> sets</p>
                    </div>

                    <div class="mb-3">
                        <label for="additionalQuantity" class="form-label">
                            <strong>Additional Quantity to Add *</strong>
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="additionalQuantity" name="additional_quantity"
                                   value="1" min="1" step="1" required>
                            <span class="input-group-text">sets</span>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-lightbulb me-1"></i>
                            This will increase the available stock for sale. You can always add more later.
                        </div>
                    </div>

                    <div class="alert alert-success">
                        <small>
                            <strong>New Total Stock:</strong> <span id="newTotalStock">0</span> sets
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="addMoreSetsBtn">
                        <i class="bi bi-plus-circle me-1"></i>
                        <span class="btn-text">Add to Stock</span>
                        <span class="spinner-border spinner-border-sm d-none" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set Price Modal -->
<div class="modal fade" id="setPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>Set Selling Price
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_set">
                    <input type="hidden" name="set_id" id="priceSetId">

                    <div class="mb-3">
                        <label class="form-label">
                            <strong>Product Set:</strong> <span id="priceSetName"></span>
                        </label>
                    </div>

                    <div class="mb-3">
                        <label for="sellingPrice" class="form-label">
                            <strong>Selling Price *</strong>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="sellingPrice" name="selling_price"
                                   step="0.01" min="0" required>
                        </div>
                        <div class="form-text">
                            Price at which this product set will be sold
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <small>
                            <strong>Total Cost:</strong> $<span id="priceTotalCost">0.00</span><br>
                            <strong>Profit Margin:</strong> <span id="priceMargin">0.0%</span><br>
                            <strong>Profit per Set:</strong> $<span id="priceProfit">0.00</span>
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Price</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Set Details Modal -->
<div class="modal fade" id="editSetModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>Edit Product Set
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editSetForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_set">
                    <input type="hidden" name="set_id" id="editSetId">

                    <!-- Basic Information -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="editSetName" class="form-label">
                                <strong>Set Name *</strong>
                            </label>
                            <input type="text" class="form-control" id="editSetName" name="set_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="editSetDescription" class="form-label">
                                <strong>Description</strong>
                            </label>
                            <textarea class="form-control" id="editSetDescription" name="set_description" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Storage Location Selection -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <label for="editSourceLocation" class="form-label">
                                <strong>🏪 Source Storage Location *</strong>
                            </label>
                            <select class="form-select" id="editSourceLocation" name="source_location_id" required>
                                <option value="">Select storage location to take stock from...</option>
                                <?php 
                                $locations_stmt = $pdo->query("SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code");
                                $locations = $locations_stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($locations as $location): ?>
                                    <option value="<?= $location['id'] ?>" data-location="<?= htmlspecialchars($location['location_code']) ?>">
                                        <?= htmlspecialchars($location['location_code']) ?> - <?= htmlspecialchars($location['location_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                <strong>Important:</strong> Stock for all product components will be reduced from this location. Make sure it has sufficient inventory for all components.
                            </div>
                        </div>
                    </div>

                    <!-- Product Selection Section -->
                    <div class="border rounded p-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">
                                <i class="bi bi-boxes me-2"></i>Product Selection
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="addProductToSet()">
                                <i class="bi bi-plus-circle me-1"></i>Add Product
                            </button>
                        </div>

                        <div id="editSetProducts">
                            <!-- Products will be loaded here -->
                        </div>

                        <div class="mt-3 p-3 bg-light rounded">
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Total Products: <span id="editTotalProducts">0</span></strong>
                                </div>
                                <div class="col-md-4">
                                    <strong>Total Cost: $<span id="editTotalCost">0.00</span></strong>
                                </div>
                                <div class="col-md-4">
                                    <strong>Calculated Profit: $<span id="editCalculatedProfit">0.00</span></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Section -->
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="editSetSellingPrice" class="form-label">
                                <strong>Selling Price *</strong>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="editSetSellingPrice" name="selling_price"
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="editSetCommissionRate" class="form-label">
                                <strong>Commission Rate (%)</strong>
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="editSetCommissionRate" name="commission_rate"
                                       step="0.1" min="0" max="100" value="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="editSetCommissionAmount" class="form-label">
                                <strong>Commission Amount</strong>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="editSetCommissionAmount" name="commission_amount"
                                       step="0.01" min="0" value="0.00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Update Set
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit History Modal -->
<div class="modal fade" id="editHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-clock-history me-2"></i>Edit History
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <strong>Product Set:</strong> <span id="historySetName"></span>
                </div>
                <div id="editHistoryContent">
                    <div class="text-center">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        Loading edit history...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Product selection and calculation (adminRunWhenReady: required when page is injected via admin AJAX)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    const productList = document.getElementById('productList');
    const addProductBtn = document.getElementById('addProductBtn');

    // Add product button
    if (addProductBtn) {
        addProductBtn.addEventListener('click', function() {
            addProductRow();
        });
    }

    // Initial product row setup
    setupProductRows();

    function setupProductRows() {
        document.querySelectorAll('.product-item').forEach(function(item) {
            setupProductRow(item);
        });
    }

    function setupProductRow(row) {
        const select = row.querySelector('.product-select');
        const quantityInput = row.querySelector('.quantity-input');
        const costDisplay = row.querySelector('.cost-display');
        const totalDisplay = row.querySelector('.total-display');
        const removeBtn = row.querySelector('.remove-product');

        // Product change handler
        if (select) {
            select.addEventListener('change', function() {
                const selectedOption = select.options[select.selectedIndex];
                const cost = selectedOption.getAttribute('data-cost') || 0;
                if (costDisplay) {
                    costDisplay.value = '$' + parseFloat(cost).toFixed(2);
                }
                calculateTotal();
            });
        }

        // Quantity change handler
        if (quantityInput) {
            quantityInput.addEventListener('input', calculateTotal);
        }

        // Remove button handler
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                if (document.querySelectorAll('.product-item').length > 1) {
                    row.remove();
                    calculateTotal();
                }
            });
        }

        calculateTotal();
    }

    function addProductRow() {
        if (!productList) return;
        
        const firstRow = productList.querySelector('.product-item');
        if (!firstRow) return;
        
        const template = firstRow.cloneNode(true);
        
        // Reset the cloned row
        const select = template.querySelector('.product-select');
        const quantityInput = template.querySelector('.quantity-input');
        const costDisplay = template.querySelector('.cost-display');
        const totalDisplay = template.querySelector('.total-display');
        
        if (select) select.selectedIndex = 0;
        if (quantityInput) quantityInput.value = '1';
        if (costDisplay) costDisplay.value = '';
        if (totalDisplay) totalDisplay.value = '';

        productList.appendChild(template);
        setupProductRow(template);
    }

    function calculateTotal() {
        let totalCost = 0;
        let totalProducts = 0;

        document.querySelectorAll('.product-item').forEach(function(item) {
            const select = item.querySelector('.product-select');
            const quantityInput = item.querySelector('.quantity-input');
            const totalDisplay = item.querySelector('.total-display');

            if (select && quantityInput && totalDisplay) {
                if (select.value && quantityInput.value) {
                    const selectedOption = select.options[select.selectedIndex];
                    const cost = parseFloat(selectedOption.getAttribute('data-cost') || 0);
                    const quantity = parseFloat(quantityInput.value || 0);
                    const total = cost * quantity;

                    totalDisplay.value = '$' + total.toFixed(2);
                    totalCost += total;
                    totalProducts++;
                } else {
                    totalDisplay.value = '$0.00';
                }
            }
        });

        const totalCostElement = document.getElementById('totalCost');
        const totalProductsElement = document.getElementById('totalProducts');
        
        if (totalCostElement) {
            totalCostElement.textContent = totalCost.toFixed(2);
        }
        if (totalProductsElement) {
            totalProductsElement.textContent = totalProducts;
        }
        
        // Update calculations when cost changes
        calculateCommission();
        calculateProfitMargin();
    }
    
    // Calculate commission amount
    function calculateCommission() {
        const totalCost = parseFloat(document.getElementById('totalCost').textContent || 0);
        const commissionRate = parseFloat(document.getElementById('setCommissionRate').value || 0);
        const commissionAmount = totalCost * (commissionRate / 100);
        
        const commissionAmountElement = document.getElementById('setCommissionAmount');
        if (commissionAmountElement) {
            commissionAmountElement.value = commissionAmount.toFixed(2);
        }
        
        calculateProfitMargin();
    }
    
    // Calculate profit margin and final price
    function calculateProfitMargin() {
        const totalCost = parseFloat(document.getElementById('totalCost').textContent || 0);
        const sellingPrice = parseFloat(document.getElementById('setSellingPrice').value || 0);
        const commissionAmount = parseFloat(document.getElementById('setCommissionAmount').value || 0);
        
        const finalPrice = sellingPrice;
        const profitPerSet = finalPrice - totalCost - commissionAmount;
        const profitMargin = finalPrice > 0 ? (profitPerSet / finalPrice) * 100 : 0;
        
        // Update display elements
        const profitMarginElement = document.getElementById('profitMargin');
        const profitPerSetElement = document.getElementById('profitPerSet');
        const finalPriceElement = document.getElementById('finalPrice');
        
        if (profitMarginElement) {
            profitMarginElement.textContent = profitMargin.toFixed(1) + '%';
        }
        if (profitPerSetElement) {
            profitPerSetElement.textContent = profitPerSet.toFixed(2);
        }
        if (finalPriceElement) {
            finalPriceElement.textContent = finalPrice.toFixed(2);
        }
        
        // Update profit margin color based on value
        if (profitMarginElement) {
            if (profitMargin > 20) {
                profitMarginElement.style.color = '#28a745'; // Green
            } else if (profitMargin > 10) {
                profitMarginElement.style.color = '#ffc107'; // Yellow
            } else if (profitMargin > 0) {
                profitMarginElement.style.color = '#fd7e14'; // Orange
            } else {
                profitMarginElement.style.color = '#dc3545'; // Red
            }
        }
    }
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

// Handle edit set form submission (adminRunWhenReady + no top-level const — script re-runs on each admin AJAX visit)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    var editSetForm = document.getElementById('editSetForm');
    if (!editSetForm) return;
    editSetForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch('product_set_management.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editSetModal'));
                modal.hide();

                showSuccessMessage(data.message);

                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showErrorMessage(data.message || 'Error updating product set');
            }
        })
        .catch(error => {
            console.error('Error updating product set:', error);
            showErrorMessage('Network error occurred while updating product set');
        });
    });
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

// Handle product set creation via AJAX (adminRunWhenReady: required when page is injected via admin AJAX)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    const createSetForm = document.querySelector('#createSetModal form');
    
    if (createSetForm) {
        createSetForm.addEventListener('submit', function(e) {
            // Check if we should use AJAX (default) or fallback
            const useAjax = !createSetForm.hasAttribute('data-no-ajax');
            
            if (useAjax) {
                e.preventDefault();
                
                console.log('AJAX form submission started...');
                
                const formData = new FormData(this);
                
                // Log form data for debugging
                console.log('Form data:');
                for (let [key, value] of formData.entries()) {
                    console.log(key + ':', value);
                }
                
                fetch(this.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    console.log('Response headers:', response.headers);
                    
                    // Check if it's a redirect (302) which indicates success
                    if (response.redirected) {
                        console.log('Redirect detected - product set created successfully');
                        // Close modal
                        const modal = bootstrap.Modal.getInstance(document.getElementById('createSetModal'));
                        modal.hide();
                        
                        // Reset form
                        this.reset();
                        
                        // Show success message
                        showSuccessMessage('Product set created successfully!');
                        
                        // Refresh the page to show new set
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                        return; // Exit here to prevent further processing
                    }
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    return response.json();
                })
                .then(data => {
                    // This will only run if not redirected
                    if (data) {
                        console.log('Response data:', data);
                        
                        if (data.success) {
                            // Close modal
                            const modal = bootstrap.Modal.getInstance(document.getElementById('createSetModal'));
                            modal.hide();
                            
                            // Reset form
                            this.reset();
                            
                            // Show success message
                            showSuccessMessage(data.message);
                            
                            // Notify product costs page (if open)
                            notifyProductCostsPage(data);
                            
                            // Refresh the page to show new set
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showErrorMessage(data.message || 'Error creating product set');
                        }
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    console.error('Error details:', error.message);
                    
                    // Show more detailed error message
                    let errorMessage = 'Network error occurred';
                    if (error.message.includes('HTTP error')) {
                        errorMessage = `Server error: ${error.message}`;
                    } else if (error.message.includes('Failed to fetch')) {
                        errorMessage = 'Connection failed - please check your internet connection';
                    } else if (error.message.includes('JSON')) {
                        errorMessage = 'Invalid server response - please try again';
                    }
                    
                    showErrorMessage(errorMessage + '. Falling back to normal submission...');
                    
                    // Fallback to normal form submission
                    console.log('Attempting fallback submission...');
                    this.removeAttribute('data-no-ajax');
                    this.submit();
                });
            } else {
                // Normal form submission (fallback)
                console.log('Using normal form submission');
            }
        });
    }
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

// Function to notify product costs page about new product set
function notifyProductCostsPage(newSetData) {
    try {
        // Try to notify via localStorage (for same browser tabs)
        localStorage.setItem('newProductSet', JSON.stringify(newSetData));
        
        // Trigger storage event for other tabs
        window.dispatchEvent(new StorageEvent('storage', {
            key: 'newProductSet',
            newValue: JSON.stringify(newSetData)
        }));
        
        console.log('Notified product costs page about new product set:', newSetData);
    } catch (error) {
        console.error('Error notifying product costs page:', error);
    }
}

// Function to show success message
function showSuccessMessage(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-3" style="font-size: 1.2rem;"></i>
            <div class="flex-grow-1">
                <strong>Success!</strong><br>
                <small>${message}</small>
            </div>
            <button type="button" class="btn-close ms-3" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    const container = document.querySelector('.container-fluid') || document.body;
    container.appendChild(alertDiv);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.classList.remove('show');
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 150);
        }
    }, 5000);
}

// Function to show error message
function showErrorMessage(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed';
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 350px; max-width: 500px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    
    // Format message with line breaks for better readability
    const formattedMessage = message.replace(/\n/g, '<br>');
    
    alertDiv.innerHTML = `
        <div class="d-flex align-items-start">
            <i class="bi bi-exclamation-triangle-fill me-3 mt-1" style="font-size: 1.2rem; color: #dc3545;"></i>
            <div class="flex-grow-1">
                <strong>Failed!</strong><br>
                <div class="error-details">${formattedMessage}</div>
                <small class="text-muted d-block mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Please check component stock availability and try again.
                </small>
            </div>
            <button type="button" class="btn-close ms-3" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    const container = document.querySelector('.container-fluid') || document.body;
    container.appendChild(alertDiv);
    
    // Auto-dismiss after 8 seconds (longer for error messages)
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.classList.remove('show');
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 150);
        }
    }, 8000);
}

// Function to edit set details (name, description, selling price)
function editSetDetails(setId, setName, setDescription, sellingPrice, sourceLocationId) {
    // Set the set ID properly
    document.getElementById('editSetId').value = setId;
    document.getElementById('editSetName').value = setName;
    document.getElementById('editSetDescription').value = setDescription || '';
    document.getElementById('editSetSellingPrice').value = sellingPrice;

    // Preselect source location
    const sourceSelect = document.getElementById('editSourceLocation');
    if (sourceSelect) {
        sourceSelect.value = sourceLocationId || '';
    }

    // Load products for this set
    loadSetProducts(setName);

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editSetModal'));
    modal.show();
}

// Function to load products for a set
function loadSetProducts(setName) {
    const productsContainer = document.getElementById('editSetProducts');
    productsContainer.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Loading products...</div>';

    // Fetch current products in the set
    fetch('product_set_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_set_products&set_name=${encodeURIComponent(setName)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displaySetProducts(data.products);
        } else {
            productsContainer.innerHTML = '<div class="alert alert-warning">Could not load products for this set.</div>';
        }
    })
    .catch(error => {
        console.error('Error loading set products:', error);
        productsContainer.innerHTML = '<div class="alert alert-danger">Error loading products.</div>';
    });
}

// Function to display products in the edit modal
function displaySetProducts(products) {
    const productsContainer = document.getElementById('editSetProducts');

    if (!products || products.length === 0) {
        productsContainer.innerHTML = '<div class="alert alert-info">No products in this set yet. Click "Add Product" to get started.</div>';
        updateEditTotals();
        return;
    }

    let html = '<div class="row g-2">';
    products.forEach((product, index) => {
        html += `
            <div class="col-12">
                <div class="card card-body p-2">
                    <div class="row align-items-center g-2">
                        <div class="col-md-5">
                            <strong>${product.product_name}</strong>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Qty</span>
                                <input type="number" class="form-control product-quantity"
                                       value="${product.quantity}" min="1" step="1"
                                       data-product-id="${product.product_id}"
                                       onchange="updateProductQuantity(this)">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <span class="fw-bold">$<span class="product-unit-cost">${parseFloat(product.unit_cost).toFixed(2)}</span></span>
                        </div>
                        <div class="col-md-2">
                            <span class="fw-bold text-success">$<span class="product-total">${parseFloat(product.total_cost).toFixed(2)}</span></span>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="removeProductFromSet('${product.product_id}', this)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    html += '</div>';

    productsContainer.innerHTML = html;
    updateEditTotals();
}

// Function to add a product to the set
function addProductToSet() {
    // Get available products (excluding those already in the set)
    const currentSetNameInput = document.getElementById('editSetName');
    const currentSetName = currentSetNameInput ? currentSetNameInput.value.trim() : '';

    const bodyParams = new URLSearchParams();
    bodyParams.append('action', 'get_available_products');
    if (currentSetName) {
        bodyParams.append('set_name', currentSetName);
    }

    fetch('product_set_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: bodyParams.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.products.length > 0) {
            showProductSelectionModal(data.products);
        } else {
            alert('No available products to add.');
        }
    })
    .catch(error => {
        console.error('Error fetching available products:', error);
        alert('Error loading available products.');
    });
}

// Function to show product selection modal
function showProductSelectionModal(availableProducts) {
    // Create a temporary modal for product selection
    const modalHtml = `
        <div class="modal fade" id="productSelectionModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Product to Set</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="productSelect" class="form-label">Select Product</label>
                            <select class="form-select" id="productSelect">
                                <option value="">Choose a product...</option>
                                ${availableProducts.map(p => `<option value="${p.id}" data-cost="${p.cost}">${p.name}</option>`).join('')}
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="productQuantity" class="form-label">Quantity</label>
                            <input type="number" class="form-control" id="productQuantity" value="1" min="1" step="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit Cost: $<span id="selectedProductCost">0.00</span></label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Total Cost: $<span id="selectedProductTotal">0.00</span></label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="confirmAddProduct()">Add to Set</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if present
    const existingModal = document.getElementById('productSelectionModal');
    if (existingModal) {
        existingModal.remove();
    }

    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('productSelectionModal'));
    modal.show();

    // Handle product selection change
    document.getElementById('productSelect').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const cost = parseFloat(selectedOption.getAttribute('data-cost') || 0);
        const quantity = parseInt(document.getElementById('productQuantity').value || 1);

        document.getElementById('selectedProductCost').textContent = cost.toFixed(2);
        document.getElementById('selectedProductTotal').textContent = (cost * quantity).toFixed(2);
    });

    // Handle quantity change
    document.getElementById('productQuantity').addEventListener('input', function() {
        const cost = parseFloat(document.getElementById('selectedProductCost').textContent || 0);
        const quantity = parseInt(this.value || 1);

        document.getElementById('selectedProductTotal').textContent = (cost * quantity).toFixed(2);
    });
}

// Function to confirm adding a product
function confirmAddProduct() {
    const productSelect = document.getElementById('productSelect');
    const quantity = parseInt(document.getElementById('productQuantity').value || 1);
    const productId = productSelect.value;
    const productName = productSelect.options[productSelect.selectedIndex].text;

    if (!productId) {
        alert('Please select a product.');
        return;
    }

    if (quantity < 1) {
        alert('Quantity must be at least 1.');
        return;
    }

    // Add product to the current set (this would need backend implementation)
    const setName = document.getElementById('editSetName').value;

    fetch('product_set_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=add_product_to_set&set_name=${encodeURIComponent(setName)}&product_id=${productId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal and reload products
            const modal = bootstrap.Modal.getInstance(document.getElementById('productSelectionModal'));
            modal.hide();
            loadSetProducts(setName);
            showSuccessMessage(`Added ${quantity}x ${productName} to the set!`);
        } else {
            alert(data.message || 'Error adding product to set.');
        }
    })
    .catch(error => {
        console.error('Error adding product:', error);
        alert('Error adding product to set.');
    });
}

// Function to update product quantity
function updateProductQuantity(input) {
    const productId = input.getAttribute('data-product-id');
    const newQuantity = parseInt(input.value);
    const setName = document.getElementById('editSetName').value;

    if (newQuantity < 1) {
        alert('Quantity must be at least 1.');
        return;
    }

    // Update quantity (would need backend implementation)
    fetch('product_set_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=update_product_quantity&set_name=${encodeURIComponent(setName)}&product_id=${productId}&quantity=${newQuantity}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the total cost for this product
            const card = input.closest('.card');
            const unitCost = parseFloat(card.querySelector('.product-unit-cost').textContent);
            const totalElement = card.querySelector('.product-total');
            totalElement.textContent = (unitCost * newQuantity).toFixed(2);

            updateEditTotals();
            showSuccessMessage('Product quantity updated!');
        } else {
            alert(data.message || 'Error updating quantity.');
            // Reload products to revert changes
            loadSetProducts(setName);
        }
    })
    .catch(error => {
        console.error('Error updating quantity:', error);
        alert('Error updating product quantity.');
        loadSetProducts(setName);
    });
}

// Function to remove product from set
function removeProductFromSet(productId, buttonElement) {
    const setName = document.getElementById('editSetName').value;

    if (confirm('Are you sure you want to remove this product from the set?')) {
        fetch('product_set_management.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `action=remove_product_from_set&set_name=${encodeURIComponent(setName)}&product_id=${productId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the product card from UI
                const card = buttonElement.closest('.col-12');
                card.remove();

                updateEditTotals();
                showSuccessMessage('Product removed from set!');
            } else {
                alert(data.message || 'Error removing product from set.');
            }
        })
        .catch(error => {
            console.error('Error removing product:', error);
            alert('Error removing product from set.');
        });
    }
}

// Function to update totals in edit modal
function updateEditTotals() {
    const productCards = document.querySelectorAll('#editSetProducts .card');
    let totalProducts = 0;
    let totalCost = 0;

    productCards.forEach(card => {
        const quantityInput = card.querySelector('.product-quantity');
        const totalElement = card.querySelector('.product-total');

        if (quantityInput && totalElement) {
            const quantity = parseInt(quantityInput.value) || 0;
            const total = parseFloat(totalElement.textContent) || 0;

            totalProducts += quantity;
            totalCost += total;
        }
    });

    // Update display
    document.getElementById('editTotalProducts').textContent = totalProducts;
    document.getElementById('editTotalCost').textContent = totalCost.toFixed(2);

    // Calculate profit (selling price - total cost - commission)
    const sellingPrice = parseFloat(document.getElementById('editSetSellingPrice').value) || 0;
    const commissionAmount = parseFloat(document.getElementById('editSetCommissionAmount').value) || 0;
    const profit = sellingPrice - totalCost - commissionAmount;

    document.getElementById('editCalculatedProfit').textContent = profit.toFixed(2);
}

// Function to show edit history
function showEditHistory(setId, setName) {
    // Set the set name in modal
    document.getElementById('historySetName').textContent = setName;
    
    // Reset content
    document.getElementById('editHistoryContent').innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Loading edit history...</div>';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editHistoryModal'));
    modal.show();
    
    // Fetch edit history
    fetch('product_set_management.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_edit_history&set_id=${setId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayEditHistory(data.history);
        } else {
            document.getElementById('editHistoryContent').innerHTML = `<div class="alert alert-danger">${data.message || 'Error loading edit history'}</div>`;
        }
    })
    .catch(error => {
        console.error('Error loading edit history:', error);
        document.getElementById('editHistoryContent').innerHTML = '<div class="alert alert-danger">Error loading edit history. Please try again.</div>';
    });
}

// Function to display edit history
function displayEditHistory(history) {
    const contentDiv = document.getElementById('editHistoryContent');
    
    if (!history || history.length === 0) {
        contentDiv.innerHTML = '<div class="alert alert-info">No edit history available for this product set.</div>';
        return;
    }
    
    let html = '<div class="timeline">';
    
    history.forEach((item, index) => {
        const date = new Date(item.timestamp);
        const formattedDate = date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        // Determine icon and color based on action
        let iconClass = 'bi-info-circle';
        let colorClass = 'primary';
        
        switch(item.action) {
            case 'Created':
                iconClass = 'bi-plus-circle';
                colorClass = 'success';
                break;
            case 'Stock Added':
                iconClass = 'bi-box-seam';
                colorClass = 'info';
                break;
            case 'Updated':
                iconClass = 'bi-pencil-square';
                colorClass = 'warning';
                break;
            case 'Deleted':
                iconClass = 'bi-trash';
                colorClass = 'danger';
                break;
        }
        
        html += `
            <div class="timeline-item mb-3">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <div class="bg-${colorClass} text-white rounded-circle p-2" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi ${iconClass}"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="card">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title mb-0">${item.action}</h6>
                                    <small class="text-muted">${formattedDate}</small>
                                </div>
                                <p class="card-text small mb-1">
                                    <strong>User:</strong> ${item.user}
                                </p>
                                <p class="card-text small mb-0">
                                    ${item.details}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    contentDiv.innerHTML = html;
}

// Function to add more sets to existing product set
function addMoreSets(setName, currentStock) {
    // Set modal values
    document.getElementById('addMoreSetName').value = setName;
    document.getElementById('addMoreSetDisplayName').textContent = setName;
    document.getElementById('addMoreCurrentStock').textContent = currentStock;
    document.getElementById('additionalQuantity').value = 1;
    updateNewTotalStock();

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('addMoreSetsModal'));
    modal.show();
}

// Function to update new total stock display
function updateNewTotalStock() {
    const currentStock = parseInt(document.getElementById('addMoreCurrentStock').textContent) || 0;
    const additionalQuantity = parseInt(document.getElementById('additionalQuantity').value) || 0;
    const newTotal = currentStock + additionalQuantity;
    document.getElementById('newTotalStock').textContent = newTotal;
}

// Handle add more sets form submission (adminRunWhenReady + no top-level const — script re-runs on each admin AJAX visit)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
    var addMoreSetsForm = document.getElementById('addMoreSetsForm');
    if (!addMoreSetsForm) return;
    addMoreSetsForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('addMoreSetsBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const spinner = submitBtn.querySelector('.spinner-border');
        
        // Show loading state
        submitBtn.disabled = true;
        btnText.textContent = 'Adding...';
        spinner.classList.remove('d-none');

        // First test server connection
        fetch('product_set_management.php', {
            method: 'HEAD',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            console.log('Server connection test - Status:', response.status);
            
            if (!response.ok) {
                throw new Error(`Server responded with status: ${response.status}`);
            }
            
            // If server is reachable, proceed with the actual request
            return fetch('product_set_management.php', {
                method: 'POST',
                body: new FormData(this),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        })
        .then(response => {
            console.log('Main request status:', response.status);
            console.log('Response headers:', response.headers);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Response received:', data);
            
            // Reset loading state
            submitBtn.disabled = false;
            btnText.textContent = 'Add to Stock';
            spinner.classList.add('d-none');
            
            if (data.success) {
                // Close modal
                const modalElement = document.getElementById('addMoreSetsModal');
                const modal = bootstrap.Modal.getInstance(modalElement);
                console.log('Modal found:', modal);
                
                if (modal) {
                    modal.hide();
                    console.log('Modal hidden');
                } else {
                    // Fallback: manually hide modal
                    modalElement.classList.remove('show');
                    modalElement.style.display = 'none';
                    document.body.classList.remove('modal-open');
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                }

                // Show success message
                showSuccessMessage(data.message);

                // Refresh the page to show updated stock
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showErrorMessage(data.message || 'Error adding more sets');
            }
        })
        .catch(error => {
            console.error('Error details:', error);
            console.error('Error message:', error.message);
            
            // Reset loading state
            submitBtn.disabled = false;
            btnText.textContent = 'Add to Stock';
            spinner.classList.add('d-none');
            
            // Show specific error message based on error type
            let errorMessage = 'Network error occurred while adding more sets. Please try again.';
            
            if (error.message.includes('Failed to fetch')) {
                errorMessage = '❌ Server connection failed! Please check:\n• Internet connection\n• Server is running\n• Try refreshing the page';
            } else if (error.message.includes('HTTP error')) {
                errorMessage = '⚠️ Server error occurred! Please:\n• Refresh the page\n• Check server logs\n• Contact administrator';
            } else if (error.message.includes('timeout')) {
                errorMessage = '⏱️ Request timed out! Please:\n• Check your connection\n• Try again\n• Reduce quantity if large';
            } else if (error.message.includes('Server responded with status')) {
                errorMessage = `🔧 Server issue detected: ${error.message}\nPlease refresh the page and try again.`;
            }
            
            showErrorMessage(errorMessage);
        });
    });
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

// Set price modal functions
function editSetPrice(setId, setName, currentPrice) {
    if (!document.getElementById('priceSetId') || !document.getElementById('priceSetName') || !document.getElementById('sellingPrice')) {
        console.error('Modal elements not found');
        return;
    }
    
    document.getElementById('priceSetId').value = setId;
    document.getElementById('priceSetName').textContent = setName;
    document.getElementById('sellingPrice').value = currentPrice;

    // Get total cost for calculations
    fetch(`?action=get_set_cost&set_id=${setId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const priceTotalCostElement = document.getElementById('priceTotalCost');
                if (priceTotalCostElement) {
                    priceTotalCostElement.textContent = data.total_cost;
                }
                calculateMargin();
            }
        })
        .catch(error => {
            console.error('Error fetching set cost:', error);
        });

    const modal = new bootstrap.Modal(document.getElementById('setPriceModal'));
    modal.show();
}

function calculateMargin() {
    const sellingPriceElement = document.getElementById('sellingPrice');
    const priceTotalCostElement = document.getElementById('priceTotalCost');
    const priceProfitElement = document.getElementById('priceProfit');
    const priceMarginElement = document.getElementById('priceMargin');
    
    if (!sellingPriceElement || !priceTotalCostElement || !priceProfitElement || !priceMarginElement) {
        return;
    }
    
    const sellingPrice = parseFloat(sellingPriceElement.value) || 0;
    const totalCost = parseFloat(priceTotalCostElement.textContent) || 0;

    const profit = sellingPrice - totalCost;
    const margin = sellingPrice > 0 ? (profit / sellingPrice) * 100 : 0;

    priceProfitElement.textContent = profit.toFixed(2);
    priceMarginElement.textContent = margin.toFixed(1) + '%';
}

// Live fields: delegate on #mainPageContent (remove old handler so admin AJAX re-visits do not stack listeners)
(function (run) {
    (typeof window.adminRunWhenReady === 'function' ? window.adminRunWhenReady : run)(function () {
        var mc = document.getElementById('mainPageContent');
        if (!mc) return;
        if (mc._productSetInputDelegate) {
            mc.removeEventListener('input', mc._productSetInputDelegate);
        }
        mc._productSetInputDelegate = function (e) {
            if (e.target.id === 'additionalQuantity') {
                if (typeof updateNewTotalStock === 'function') updateNewTotalStock();
            }
            if (e.target.id === 'sellingPrice') {
                if (typeof calculateMargin === 'function') calculateMargin();
            }
        };
        mc.addEventListener('input', mc._productSetInputDelegate);
    });
})(function (fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
});

// Delete product set
function deleteProductSet(setId, setName) {
    if (confirm(`Are you sure you want to delete the product set "${setName}"? This will also remove it from Product Costs and cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_set">
            <input type="hidden" name="set_id" value="${setId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Function to edit product set item
function editSetItem(productId, productName, quantity, unitCost) {
    // Create a simple prompt for editing
    const newQuantity = prompt(`Edit quantity for "${productName}" (current: ${quantity}):`, quantity);
    const newUnitCost = prompt(`Edit unit cost for "${productName}" (current: $${unitCost}):`, unitCost);
    
    if (newQuantity !== null && newUnitCost !== null) {
        // Send AJAX request to update
        fetch('product_set_management.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `action=update_set_item&product_id=${productId}&quantity=${newQuantity}&unit_cost=${newUnitCost}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessMessage(data.message);
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showErrorMessage(data.message || 'Error updating product item');
            }
        })
        .catch(error => {
            console.error('Error updating product item:', error);
            showErrorMessage('Network error occurred while updating product item');
        });
    }
}

// Function to notify product costs page about deleted product set
function notifyProductCostsPageOfDeletion(setId, setName) {
    try {
        // Send deletion notification
        const deletionData = {
            action: 'delete',
            set_id: setId,
            set_name: setName,
            timestamp: new Date().toISOString()
        };
        
        localStorage.setItem('deletedProductSet', JSON.stringify(deletionData));
        
        // Trigger storage event for other tabs
        window.dispatchEvent(new StorageEvent('storage', {
            key: 'deletedProductSet',
            newValue: JSON.stringify(deletionData)
        }));
        
        console.log('Notified product costs page about deleted product set:', deletionData);
    } catch (error) {
        console.error('Error notifying product costs page about deletion:', error);
    }
}

// View set details
function viewSetDetails(setId) {
    // Redirect to a detailed view - you can implement this
    window.location.href = `?action=view_set&set_id=${setId}`;
}
</script>

<?php
// Handle get_set_details request
if (isset($_GET['action']) && $_GET['action'] === 'get_set_details') {
    $set_id = (int)$_GET['set_id'];

    try {
        // Get set details with product information
        $stmt = $pdo->prepare("
            SELECT 
                ps.*,
                COUNT(psi.id) as product_count
            FROM product_sets ps
            LEFT JOIN product_set_items psi ON ps.id = psi.product_set_id
            WHERE ps.id = ? AND ps.is_active = 1
            GROUP BY ps.id
        ");
        $stmt->execute([$set_id]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($set) {
            // Get individual products in the set
            $stmt = $pdo->prepare("
                SELECT 
                    psi.quantity,
                    psi.unit_cost,
                    psi.total_cost,
                    p.name,
                    p.item_code,
                    p.cost as product_cost
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                WHERE psi.product_set_id = ?
                ORDER BY p.name
            ");
            $stmt->execute([$set_id]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'set' => $set,
                'products' => $products
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product set not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'get_product_sets') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ps.id,
                ps.set_name,
                ps.set_description,
                ps.total_cost,
                ps.selling_price,
                ps.profit_margin,
                ps.is_active,
                COUNT(psi.id) as product_count
            FROM product_sets ps
            LEFT JOIN product_set_items psi ON ps.id = psi.product_set_id
            WHERE ps.is_active = 1
            GROUP BY ps.id
            ORDER BY ps.set_name
        ");
        $stmt->execute();
        $productSets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($productSets);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle get_set_cost request
if (isset($_GET['action']) && $_GET['action'] === 'get_set_cost') {
    $set_id = (int)$_GET['set_id'];

    try {
        $stmt = $pdo->prepare("SELECT total_cost FROM product_sets WHERE id = ?");
        $stmt->execute([$set_id]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($set) {
            echo json_encode(['success' => true, 'total_cost' => number_format($set['total_cost'], 2)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product set not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>

</body>
</html>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
