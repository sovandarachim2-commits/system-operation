<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_costs.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

$errors = [];
$success = (string)($_GET['success'] ?? '');

/**
 * Parse storage locations string into readable format
 */
function parseStorageLocations($storage_locations) {
    if (empty($storage_locations)) {
        return '<span class="pc-muted">No locations</span>';
    }
    
    $locations = explode('|', $storage_locations);
    $badges = [];
    
    foreach ($locations as $location) {
        if (!empty($location)) {
            // Safely parse location string
            $parts = explode(':', $location);
            if (count($parts) >= 2) {
                list($code, $qty) = $parts;
                $state = $qty > 0 ? 'ok' : 'empty';
                $badges[] = '<span class="pc-loc pc-loc-' . $state . '">' .
                           htmlspecialchars($code) . ': ' . number_format($qty, 1) . '</span>';
            }
        }
    }
    
    return implode('', $badges);
}

// Create products table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            cost DECIMAL(10,2) DEFAULT 0,
            product_type ENUM('normal', 'set', 'General') DEFAULT 'General',
            blocked_month VARCHAR(7) NULL COMMENT 'YYYY-MM month to hide product from monthly auto-creation',
            blocked_reason VARCHAR(255) NULL COMMENT 'Reason for closing product for blocked month',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    error_log("Products table created/verified successfully");
} catch (PDOException $e) {
    error_log("Error creating products table: " . $e->getMessage());
    $errors[] = "Database error creating products table: " . $e->getMessage();
}

// Update existing "General" values to be treated as "normal" in queries
// Also ensure the ENUM includes "General" for existing data
try {
    // Check if product_type column exists and update ENUM if needed
    $check_column = $pdo->query("SHOW COLUMNS FROM products LIKE 'product_type'");
    $column_info = $check_column->fetch(PDO::FETCH_ASSOC);
    
    if ($column_info && strpos($column_info['Type'], 'General') === false) {
        // Add "General" to the ENUM if it doesn't exist
        $pdo->exec("ALTER TABLE products MODIFY COLUMN product_type ENUM('normal', 'set', 'General') DEFAULT 'General'");
        error_log("Updated product_type ENUM to include 'General'");
    }
} catch (PDOException $e) {
    error_log("Error updating product_type column: " . $e->getMessage());
}

// Create product_costs table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            month_year VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format',
            selling_price DECIMAL(10,2) DEFAULT 0,
            original_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            supplier_cost DECIMAL(10,2) DEFAULT NULL,
            shipping_cost DECIMAL(10,2) DEFAULT NULL,
            other_costs DECIMAL(10,2) DEFAULT NULL,
            commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT 'Commission percentage',
            commission_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Commission amount per unit',
            total_cost DECIMAL(10,2) GENERATED ALWAYS AS (original_cost + COALESCE(supplier_cost, 0) + COALESCE(shipping_cost, 0) + COALESCE(other_costs, 0)) STORED,
            cost_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT DEFAULT NULL,
            notes TEXT,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uk_product_month (product_id, month_year)
        )
    ");
    error_log("Product_costs table created/verified successfully");
} catch (PDOException $e) {
    error_log("Error creating product_costs table: " . $e->getMessage());
    $errors[] = "Database error creating product_costs table: " . $e->getMessage();
}

// Add commission columns to existing table if they don't exist
try {
    // Check if commission_rate column exists
    $check_column = $pdo->query("SHOW COLUMNS FROM product_costs LIKE 'commission_rate'");
    if ($check_column->rowCount() == 0) {
        $pdo->exec("ALTER TABLE product_costs ADD COLUMN commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT 'Commission percentage' AFTER other_costs");
        $pdo->exec("ALTER TABLE product_costs ADD COLUMN commission_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Commission amount per unit' AFTER commission_rate");
        error_log("Commission columns added to product_costs table");
    }
} catch (PDOException $e) {
    error_log("Error adding commission columns: " . $e->getMessage());
}

// Add product_type column if it doesn't exist
try {
    $check_column = $pdo->query("SHOW COLUMNS FROM products LIKE 'product_type'");
    if ($check_column->rowCount() == 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN product_type ENUM('normal', 'set') DEFAULT 'normal' AFTER cost");
        error_log("product_type column added to products table");
    }
} catch (PDOException $e) {
    error_log("Error adding product_type column: " . $e->getMessage());
}

// Add blocked_month column if it doesn't exist
try {
    $check_blocked_month = $pdo->query("SHOW COLUMNS FROM products LIKE 'blocked_month'");
    if ($check_blocked_month->rowCount() === 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN blocked_month VARCHAR(7) NULL COMMENT 'YYYY-MM month to hide product from monthly auto-creation' AFTER product_type");
        error_log("blocked_month column added to products table");
    }
} catch (PDOException $e) {
    error_log("Error adding blocked_month column: " . $e->getMessage());
}

// Add blocked_reason column if it doesn't exist
try {
    $check_blocked_reason = $pdo->query("SHOW COLUMNS FROM products LIKE 'blocked_reason'");
    if ($check_blocked_reason->rowCount() === 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN blocked_reason VARCHAR(255) NULL COMMENT 'Reason for closing product for blocked month' AFTER blocked_month");
        error_log("blocked_reason column added to products table");
    }
} catch (PDOException $e) {
    error_log("Error adding blocked_reason column: " . $e->getMessage());
}

// Ensure products.id / product_costs.id stay AUTO_INCREMENT (missing AI causes id=0 inserts)
try {
    foreach (['products', 'product_costs'] as $aiTable) {
        $idCol = $pdo->query("SHOW COLUMNS FROM `{$aiTable}` LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if (!$idCol) {
            continue;
        }

        $needsRepair = false;
        $zeroRow = $pdo->query("SELECT id FROM `{$aiTable}` WHERE id = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($zeroRow) {
            $newId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$aiTable}`")->fetchColumn();
            if ($aiTable === 'products') {
                $pdo->exec("UPDATE products SET id = {$newId} WHERE id = 0");
                $pdo->exec("UPDATE product_costs SET product_id = {$newId} WHERE product_id = 0");
            } else {
                $pdo->exec("UPDATE `{$aiTable}` SET id = {$newId} WHERE id = 0");
            }
            error_log("Reassigned {$aiTable}.id 0 -> {$newId}");
            $needsRepair = true;
        }

        if (stripos((string)($idCol['Extra'] ?? ''), 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE `{$aiTable}` MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
            error_log("Restored AUTO_INCREMENT on {$aiTable}.id");
            $needsRepair = true;
        }

        if ($needsRepair) {
            $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$aiTable}`")->fetchColumn();
            $pdo->exec("ALTER TABLE `{$aiTable}` AUTO_INCREMENT = " . ($maxId + 1));
        }
    }
} catch (PDOException $e) {
    error_log("Error ensuring AUTO_INCREMENT on products/product_costs: " . $e->getMessage());
}

// Check if products table exists and has data
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $result = $stmt->fetch();
    error_log("Products table exists with " . $result['count'] . " records");
} catch (PDOException $e) {
    error_log("Error checking products table: " . $e->getMessage());
    $errors[] = "Cannot access products table: " . $e->getMessage();
}

// Get selected month from GET parameter, default to current month
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_month_display = date('F Y', strtotime($selected_month . '-01'));

// Get selected product type from GET parameter, default to 'all'
$selected_product_type = $_GET['product_type'] ?? 'all';

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
    $selected_month_display = date('F Y');
}

$current_month = date('Y-m');
$current_month_display = date('F Y', strtotime($current_month . '-01'));

if ($selected_month > $current_month) {
    $errors[] = 'Future month is not allowed. Showing current month instead.';
    $selected_month = $current_month;
    $selected_month_display = $current_month_display;
}

$is_future_month = $selected_month > $current_month;
$is_past_month = $selected_month < $current_month;
$next_month = date('Y-m', strtotime($current_month . '-01 +1 month'));

function product_costs_is_ajax_request(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function product_costs_redirect_with_success(string $month, string $productType, string $message): void
{
    $params = http_build_query([
        'month' => $month,
        'product_type' => $productType,
        'success' => $message,
    ]);
    $url = 'product_costs.php?' . $params;

    if (product_costs_is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'url' => $url,
        ]);
        exit;
    }

    header('Location: ' . $url);
    exit;
}

// Validate product type
$valid_product_types = ['all', 'normal', 'set'];
if (!in_array($selected_product_type, $valid_product_types)) {
    $selected_product_type = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_cost') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $month_year = trim($_POST['month_year'] ?? '');
        $product_name = trim($_POST['product_name'] ?? '');
        $selling_price = (float)($_POST['selling_price'] ?? 0);
        $brand_id = ($_POST['brand_id'] ?? '') === '' ? null : (int)$_POST['brand_id'];

        // Convert month format to proper VARCHAR format (YYYY-MM)
        if (!empty($month_year) && preg_match('/^\d{4}-\d{2}$/', $month_year)) {
            $month_year_varchar = $month_year;
        } else {
            $month_year_varchar = '';
        }

        if ($product_id > 0 && !empty($month_year_varchar) && $month_year_varchar <= $current_month && $product_name !== '' && $brand_id !== null) {
            $user_id = $_SESSION['user_id'] ?? null;

            $pdo->prepare("UPDATE products SET name = ?, brand_id = ? WHERE id = ?")
                ->execute([$product_name, $brand_id, $product_id]);

            // Only update selling price / identity fields — keep existing cost breakdown intact
            $stmt = $pdo->prepare("
                INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, updated_by)
                VALUES (?, ?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE
                selling_price = VALUES(selling_price),
                updated_by = VALUES(updated_by),
                cost_updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$product_id, $month_year_varchar, $selling_price, $user_id]);

            if ($month_year_varchar === $current_month) {
                $pdo->prepare("UPDATE products SET cost = ? WHERE id = ?")->execute([$selling_price, $product_id]);
            }

            product_costs_redirect_with_success($month_year_varchar, $selected_product_type, 'Product updated successfully.');
        } else {
            $errors[] = 'Product name, selling price, and brand are required. Future month is not allowed.';
        }
    } elseif ($action === 'bulk_update') {
        $month_year = trim($_POST['month_year'] ?? $selected_month);
        $product_ids = $_POST['product_ids'] ?? [];
        $original_costs = $_POST['original_costs'] ?? [];

        if (!preg_match('/^\d{4}-\d{2}$/', $month_year) || $month_year > $current_month) {
            $errors[] = 'Bulk update blocked: future month is not allowed.';
            $month_year = $current_month;
        }
        
        $updated_count = 0;
        foreach ($product_ids as $index => $product_id) {
            $product_id = (int)$product_id;
            // Handle both numeric and associative array keys
            $original_cost = (float)(is_numeric($index) ? ($original_costs[$index] ?? 0) : ($original_costs[$product_id] ?? 0));
            
            if ($product_id > 0 && $original_cost >= 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO product_costs (product_id, month_year, original_cost)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    original_cost = VALUES(original_cost),
                    cost_updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$product_id, $month_year, $original_cost]);
                $updated_count++;
            }
        }
        product_costs_redirect_with_success($month_year, $selected_product_type, "Updated costs for $updated_count products.");
    } elseif ($action === 'add_product') {
        $product_type     = trim($_POST['product_type'] ?? 'normal');
        $product_set_id   = (int)($_POST['product_set_id'] ?? 0);
        $product_name     = trim($_POST['product_name'] ?? '');
        $selling_price    = (float)($_POST['selling_price'] ?? 0);
        $original_cost    = (float)($_POST['original_cost'] ?? 0);
        $supplier_cost    = (float)($_POST['supplier_cost'] ?? 0);
        $shipping_cost    = (float)($_POST['shipping_cost'] ?? 0);
        $other_costs      = (float)($_POST['other_costs'] ?? 0);
        $commission_rate  = (float)($_POST['commission_rate'] ?? 0);
        $commission_amount = (float)($_POST['commission_amount'] ?? 0);
        $notes            = trim($_POST['notes'] ?? '');
        $brand_id         = ($_POST['brand_id'] ?? '') === '' ? null : (int)$_POST['brand_id'];

        if ($product_type === 'set') {
            // Handle product set
            if ($product_set_id > 0 && $selling_price >= 0) {
                try {
                    // Get product set details
                    $stmt = $pdo->prepare("SELECT * FROM product_sets WHERE id = ?");
                    $stmt->execute([$product_set_id]);
                    $product_set = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product_set) {
                        // Check if product already exists in products table
                        $check_stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND COALESCE(product_type, 'General') = 'set'");
                        $check_stmt->execute([$product_set['set_name']]);
                        $existing_product = $check_stmt->fetch();
                        
                        if ($existing_product) {
                            $product_id = $existing_product['id'];
                            $pdo->prepare("UPDATE products SET brand_id = ? WHERE id = ?")->execute([$brand_id, $product_id]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO products (name, cost, product_type, brand_id) VALUES (?, ?, 'set', ?)");
                            $result = $stmt->execute([$product_set['set_name'], $selling_price, $brand_id]);
                            $product_id = $pdo->lastInsertId();
                        }
                        
                        if ($product_id > 0) {
                            // Add cost record for selected month (like normal products)
                            $selected_month_varchar = $selected_month;
                            $stmt = $pdo->prepare("
                                INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, commission_rate, commission_amount, notes, updated_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                selling_price = VALUES(selling_price),
                                original_cost = VALUES(original_cost),
                                supplier_cost = VALUES(supplier_cost),
                                shipping_cost = VALUES(shipping_cost),
                                other_costs = VALUES(other_costs),
                                commission_rate = VALUES(commission_rate),
                                commission_amount = VALUES(commission_amount),
                                notes = VALUES(notes),
                                updated_by = VALUES(updated_by),
                                cost_updated_at = CURRENT_TIMESTAMP
                            ");
                            $result = $stmt->execute([$product_id, $selected_month_varchar, $selling_price, $original_cost, $supplier_cost, $shipping_cost, $other_costs, $commission_rate, $commission_amount, $notes, $_SESSION['user_id']]);
                            
                            if ($result) {
                                product_costs_redirect_with_success($selected_month_varchar, $selected_product_type, "Product set '{$product_set['set_name']}' added successfully with monthly cost records.");
                            } else {
                                $errors[] = "Failed to create cost record for product set.";
                            }
                        } else {
                            $errors[] = "Failed to create product set record.";
                        }
                    } else {
                        $errors[] = "Product set not found.";
                    }
                } catch (PDOException $e) {
                    error_log("PDOException in add product set: " . $e->getMessage());
                    $errors[] = "Database error: " . $e->getMessage();
                }
            } else {
                $errors[] = "Please select a product set and provide a valid selling price.";
            }
        } else {
            // Handle normal product
            if (!empty($product_name) && $selling_price >= 0 && $brand_id !== null) {
                $user_id = $_SESSION['user_id'] ?? null;
                
                try {
                    $check_stmt = $pdo->prepare("
                        SELECT id
                        FROM products
                        WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                          AND COALESCE(product_type, 'General') IN ('normal', 'General')
                        LIMIT 1
                    ");
                    $check_stmt->execute([$product_name]);
                    $existing_product = $check_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing_product) {
                        $product_id = (int)$existing_product['id'];
                        $pdo->prepare("UPDATE products SET brand_id = ? WHERE id = ?")->execute([$brand_id, $product_id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO products (name, cost, product_type, brand_id) VALUES (?, ?, 'normal', ?)");
                        $stmt->execute([$product_name, $selling_price, $brand_id]);
                        $product_id = (int)$pdo->lastInsertId();
                        // Recover if AUTO_INCREMENT was broken and lastInsertId returned 0
                        if ($product_id <= 0) {
                            $lookup = $pdo->prepare("
                                SELECT id FROM products
                                WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                                  AND COALESCE(product_type, 'General') IN ('normal', 'General')
                                ORDER BY id DESC
                                LIMIT 1
                            ");
                            $lookup->execute([$product_name]);
                            $product_id = (int)($lookup->fetchColumn() ?: 0);
                        }
                    }
                    
                    if ($product_id > 0) {
                        // Add cost record for selected month
                        $selected_month_varchar = $selected_month;
                        $stmt = $pdo->prepare("
                            INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, commission_rate, commission_amount, notes, updated_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                            selling_price = VALUES(selling_price),
                            original_cost = VALUES(original_cost),
                            supplier_cost = VALUES(supplier_cost),
                            shipping_cost = VALUES(shipping_cost),
                            other_costs = VALUES(other_costs),
                            commission_rate = VALUES(commission_rate),
                            commission_amount = VALUES(commission_amount),
                            notes = VALUES(notes),
                            updated_by = VALUES(updated_by),
                            cost_updated_at = CURRENT_TIMESTAMP
                        ");
                        $result = $stmt->execute([$product_id, $selected_month_varchar, $selling_price, $original_cost, $supplier_cost, $shipping_cost, $other_costs, $commission_rate, $commission_amount, $notes, $user_id]);

                        if ($selected_month_varchar === $current_month) {
                            $stmt = $pdo->prepare("UPDATE products SET cost = ? WHERE id = ?");
                            $stmt->execute([$selling_price, $product_id]);
                        }
                        
                        if ($existing_product) {
                            product_costs_redirect_with_success($selected_month_varchar, $selected_product_type, "Product '$product_name' already exists. Monthly cost record updated.");
                        }
                        product_costs_redirect_with_success($selected_month_varchar, $selected_product_type, "New product '$product_name' added successfully with monthly cost records.");
                    } else {
                        $errors[] = "Failed to create product. Please try again.";
                    }
                } catch (PDOException $e) {
                    error_log("PDOException in add product: " . $e->getMessage());
                    $errors[] = "Database error: " . $e->getMessage();
                }
            } else {
                $errors[] = "Product name, selling price, and brand are required.";
            }
        }
    } elseif ($action === 'edit_cost') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $month_year = trim($_POST['month_year'] ?? '');
        $product_name = trim($_POST['product_name'] ?? '');
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        $original_cost = floatval($_POST['original_cost'] ?? 0);
        $supplier_cost = floatval($_POST['supplier_cost'] ?? 0);
        $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
        $other_costs = floatval($_POST['other_costs'] ?? 0);
        $commission_rate = floatval($_POST['commission_rate'] ?? 0);
        $commission_amount = floatval($_POST['commission_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($product_id > 0 && preg_match('/^\d{4}-\d{2}$/', $month_year) && $month_year <= $current_month) {
            try {
                // Calculate total cost and profit
                $total_cost = $original_cost + $supplier_cost + $shipping_cost + $other_costs;
                $profit_per_unit = $selling_price - $total_cost - $commission_amount;
                $profit_percentage = $selling_price > 0 ? ($profit_per_unit / $selling_price) * 100 : 0;

                // Update product costs
                $stmt = $pdo->prepare("
                    UPDATE product_costs 
                    SET selling_price = ?, original_cost = ?, supplier_cost = ?, 
                        shipping_cost = ?, other_costs = ?, commission_rate = ?, 
                        commission_amount = ?, notes = ?, 
                        cost_updated_at = NOW()
                    WHERE product_id = ? AND month_year = ?
                ");
                $result = $stmt->execute([
                    $selling_price, $original_cost, $supplier_cost,
                    $shipping_cost, $other_costs, $commission_rate,
                    $commission_amount, $notes,
                    $product_id, $month_year
                ]);

                if ($result) {
                    $brand_id_edit = ($_POST['brand_id'] ?? '') === '' ? null : (int)$_POST['brand_id'];
                    $pdo->prepare("UPDATE products SET name = ?, brand_id = ? WHERE id = ?")->execute([$product_name, $brand_id_edit, $product_id]);

                    product_costs_redirect_with_success($month_year, $selected_product_type, 'Product cost updated successfully!');
                } else {
                    $errors[] = "Failed to update product cost.";
                }
            } catch (PDOException $e) {
                error_log("PDOException in edit cost: " . $e->getMessage());
                $errors[] = "Database error: " . $e->getMessage();
            }
        } else {
            $errors[] = "Invalid product ID or month year. Future month is not allowed.";
        }
    }

    if (product_costs_is_ajax_request() && in_array($action, ['update_cost', 'bulk_update', 'add_product', 'edit_cost'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => !empty($errors) ? implode("\n", $errors) : 'Unable to save product cost.',
        ]);
        exit;
    }
}

// Handle brand assignment (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_brand'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $brand_id   = ($_POST['brand_id'] === '' || $_POST['brand_id'] === '0') ? null : (int)$_POST['brand_id'];
    header('Content-Type: application/json');
    if ($product_id > 0) {
        try {
            $pdo->prepare('UPDATE products SET brand_id = ? WHERE id = ?')->execute([$brand_id, $product_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    }
    exit;
}

// Handle product status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_product_status'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $active = (int)($_POST['active'] ?? 0);
    
    header('Content-Type: application/json');
    
    if ($product_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE products SET active = ? WHERE id = ?");
            $result = $stmt->execute([$active, $product_id]);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Product status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update product status']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    }
    exit;
}

// Handle "close for next month" toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_next_month_close'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $close_for_next_month = (int)($_POST['close_for_next_month'] ?? 0);
    $close_reason = trim((string)($_POST['close_reason'] ?? ''));

    header('Content-Type: application/json');

    if ($product_id > 0) {
        try {
            $next_month_for_block = date('Y-m', strtotime(date('Y-m') . '-01 +1 month'));
            if ($close_for_next_month && $close_reason === '') {
                echo json_encode(['success' => false, 'message' => 'Reason is required when closing for next month.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE products SET blocked_month = ?, blocked_reason = ? WHERE id = ?");
            $result = $stmt->execute([
                $close_for_next_month ? $next_month_for_block : null,
                $close_for_next_month ? mb_substr($close_reason, 0, 255) : null,
                $product_id
            ]);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => $close_for_next_month ? 'Product closed for next month.' : 'Product re-opened for next month.',
                    'blocked_month' => $close_for_next_month ? $next_month_for_block : null,
                    'blocked_reason' => $close_for_next_month ? mb_substr($close_reason, 0, 255) : null,
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update next-month closure status']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    }
    exit;
}

// Load brands for inline assignment
$brandsForAssign = [];
try {
    $brandsForAssign = $pdo->query("SELECT id, name, color FROM brands WHERE active = 1 ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $brandsForAssign = [];
}

// Use VARCHAR format for queries
$selected_month_varchar = $selected_month;

// Get products for selected month only with inventory data
// Build the WHERE clause based on product type filter
$where_clause = "";
if ($selected_product_type !== 'all') {
    if ($selected_product_type === 'normal') {
        // Include both 'normal' and 'General' for normal products
        $where_clause = " AND COALESCE(p.product_type, 'General') IN ('normal', 'General')";
    } else {
        // For 'set' type, only include 'set'
        $where_clause = " AND COALESCE(p.product_type, 'General') = ?";
    }
}

// Query to show products for selected month with proper month filtering
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name as product_name,
        p.name,
        p.brand_id,
        CASE
            WHEN COALESCE(p.product_type, 'General') = 'set' THEN 'set'
            ELSE 'normal'
        END as product_type,
        COALESCE(pc.selling_price, p.cost) as selling_price,
        -- Calculate original cost for product sets by summing individual product ORIGINAL costs
        CASE 
            WHEN COALESCE(p.product_type, 'General') = 'set' THEN 
                COALESCE(
                    (
                        SELECT SUM(pc.original_cost * psi.quantity)
                        FROM product_set_items psi
                        JOIN product_costs pc ON psi.product_id = pc.product_id 
                            AND pc.month_year = ?
                        WHERE psi.product_set_id = ps.id
                    ), 
                    0
                )
            ELSE 
                COALESCE(pc.original_cost, 0)
        END as original_cost,
        COALESCE(pc.supplier_cost, 0) as supplier_cost,
        COALESCE(pc.shipping_cost, 0) as shipping_cost,
        COALESCE(pc.other_costs, 0) as other_costs,
        COALESCE(pc.commission_rate, 0) as commission_rate,
        COALESCE(pc.commission_amount, 0) as commission_amount,
        COALESCE(pc.total_cost, 0) as total_cost,
        p.active,
        p.blocked_month,
        p.blocked_reason,
        pc.cost_updated_at,
        COALESCE(pc.notes, '') as notes,
        u.name as updated_by_name,
        ? as cost_month,
        (COALESCE(pc.selling_price, p.cost) - COALESCE(
            CASE 
                WHEN COALESCE(p.product_type, 'General') = 'set' THEN 
                    COALESCE(
                        (
                            SELECT SUM(psi.total_cost)
                            FROM product_set_items psi
                            JOIN products p2 ON psi.product_id = p2.id
                            WHERE psi.product_set_id = ps.id
                        ), 
                        0
                    )
                ELSE 
                    COALESCE(pc.total_cost, 0)
            END, 0) - COALESCE(pc.commission_amount, 0)) as profit_per_unit,
        CASE 
            WHEN COALESCE(pc.selling_price, p.cost) > 0 THEN 
                ROUND(((COALESCE(pc.selling_price, p.cost) - COALESCE(
                    CASE 
                        WHEN COALESCE(p.product_type, 'General') = 'set' THEN 
                            COALESCE(
                                (
                                    SELECT SUM(psi.total_cost)
                                    FROM product_set_items psi
                                    JOIN products p2 ON psi.product_id = p2.id
                                    WHERE psi.product_set_id = ps.id
                                ), 
                                0
                            )
                        ELSE 
                            COALESCE(pc.total_cost, 0)
                    END, 0) - COALESCE(pc.commission_amount, 0)) / COALESCE(pc.selling_price, p.cost)) * 100, 2)
            ELSE 0 
        END as profit_percentage,
        -- Add inventory data (for normal products) or set stock (for product sets)
        CASE 
            WHEN COALESCE(p.product_type, 'General') = 'set' THEN COALESCE(ps.available_stock, 0)
            ELSE COALESCE(inv.total_quantity, 0)
        END as available_stock,
        COALESCE(inv.total_value, 0) as inventory_value,
        -- Add storage location breakdown (for regular products)
        CASE 
            WHEN COALESCE(p.product_type, 'General') = 'set' THEN 
                COALESCE(ps_locations.location_info, 'No location set')
            ELSE inv.storage_locations
        END as storage_locations,
        -- Add product set items breakdown
        psi.set_items
    FROM products p
    LEFT JOIN product_costs pc ON p.id = pc.product_id AND pc.month_year = ?
    LEFT JOIN users u ON pc.updated_by = u.id
    LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'General') = 'set'
    LEFT JOIN (
        SELECT 
            ps_inner.id as product_set_id,
            CONCAT(sl.location_code, ':', ps_inner.available_stock) as location_info
        FROM product_sets ps_inner
        LEFT JOIN (
            SELECT DISTINCT product_set_id,
                   CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) as location_id
            FROM product_set_audit_log
            WHERE action_type IN ('created', 'updated')
              AND action_details LIKE '%storage_location_id:%'
        ) loc_finder ON ps_inner.id = loc_finder.product_set_id
        LEFT JOIN storage_locations sl ON loc_finder.location_id = sl.id
        WHERE ps_inner.available_stock > 0 AND ps_inner.is_active = 1
    ) ps_locations ON ps.id = ps_locations.product_set_id
    LEFT JOIN (
        SELECT 
            ci.item_name,
            SUM(ci.quantity_on_hand) as total_quantity,
            SUM(ci.total_value) as total_value,
            GROUP_CONCAT(
                CONCAT(sl.location_code, ':', ci.quantity_on_hand) 
                ORDER BY sl.location_code
                SEPARATOR '|'
            ) as storage_locations
        FROM current_inventory ci
        LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
        GROUP BY ci.item_name
    ) inv ON inv.item_name = p.name
    LEFT JOIN (
        SELECT 
            psi.product_set_id,
            GROUP_CONCAT(
                CONCAT(prod.name, ' (', psi.quantity, 'x @ $', FORMAT(psi.unit_cost, 2), ')')
                ORDER BY prod.name
                SEPARATOR '; '
            ) as set_items
        FROM product_set_items psi
        LEFT JOIN products prod ON psi.product_id = prod.id
        GROUP BY psi.product_set_id
    ) psi ON ps.id = psi.product_set_id
    WHERE (
        -- Include normal products with cost records for this month
        (COALESCE(p.product_type, 'General') != 'set' AND pc.product_id IS NOT NULL)
        OR
        -- Include product sets with cost records for this month (like normal products)
        (COALESCE(p.product_type, 'General') = 'set' AND pc.product_id IS NOT NULL)
    )
    $where_clause
    ORDER BY p.name
");

// Prepare parameters for execution
// Parameters: [selling_price_month, subquery_month, where_month]
$params = [$selected_month_varchar, $selected_month_varchar, $selected_month_varchar];
if ($selected_product_type !== 'all' && $selected_product_type !== 'normal') {
    $params[] = $selected_product_type;
}

// Execute query
$stmt->execute($params);
$products = $stmt->fetchAll();

// Debug logging to see what products were found
error_log("=== PRODUCT QUERY DEBUG ===");
error_log("Selected Month: $selected_month_varchar");
error_log("Total products found: " . count($products));
foreach ($products as $product) {
    error_log("Product: " . $product['product_name'] . " - Type: " . $product['product_type']);
}
error_log("=== END PRODUCT QUERY DEBUG ===");

// If no products found (no cost records for this month), get all products
if (empty($products)) {
    $fallback_stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name as product_name,
            p.name,
            p.brand_id,
            CASE
                WHEN COALESCE(p.product_type, 'General') = 'set' THEN 'set'
                ELSE 'normal'
            END as product_type,
            p.cost as selling_price,
            0 as original_cost,
            0 as supplier_cost,
            0 as shipping_cost,
            0 as other_costs,
            0 as commission_rate,
            0 as commission_amount,
            0 as total_cost,
            p.active,
            p.blocked_month,
            p.blocked_reason,
            NULL as cost_updated_at,
            '' as notes,
            NULL as updated_by_name,
            ? as cost_month,
            p.cost as profit_per_unit,
            0 as profit_percentage,
            CASE 
                WHEN COALESCE(p.product_type, 'General') = 'set' THEN COALESCE(ps.available_stock, 0)
                ELSE COALESCE(inv.total_quantity, 0)
            END as available_stock,
            COALESCE(inv.total_value, 0) as inventory_value,
            CASE 
                WHEN COALESCE(p.product_type, 'General') = 'set' THEN 
                    COALESCE(ps_locations.location_info, 'No location set')
                ELSE COALESCE(inv.storage_locations, 'No locations set')
            END as storage_locations,
            psi.set_items
        FROM products p
        LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'General') = 'set'
        LEFT JOIN (
            SELECT 
                psi.product_set_id,
                GROUP_CONCAT(
                    CONCAT(prod.name, ' (', psi.quantity, 'x @ $', FORMAT(psi.unit_cost, 2), ')')
                    ORDER BY prod.name
                    SEPARATOR '; '
                ) as set_items
            FROM product_set_items psi
            LEFT JOIN products prod ON psi.product_id = prod.id
            GROUP BY psi.product_set_id
        ) psi ON ps.id = psi.product_set_id
        LEFT JOIN (
            SELECT 
                ci.item_name,
                SUM(ci.quantity_on_hand) as total_quantity,
                SUM(ci.total_value) as total_value,
                GROUP_CONCAT(
                    CONCAT(sl.location_code, ':', ci.quantity_on_hand) 
                    ORDER BY sl.location_code
                    SEPARATOR '|'
                ) as storage_locations
            FROM current_inventory ci
            LEFT JOIN storage_locations sl ON ci.storage_location_id = sl.id
            GROUP BY ci.item_name
        ) inv ON inv.item_name = p.name
        LEFT JOIN (
            SELECT 
                ps_inner.id as product_set_id,
                CONCAT(sl.location_code, ':', ps_inner.available_stock) as location_info
            FROM product_sets ps_inner
            LEFT JOIN (
                SELECT DISTINCT product_set_id,
                       CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) as location_id
                FROM product_set_audit_log
                WHERE action_type IN ('created', 'updated')
                  AND action_details LIKE '%storage_location_id:%'
            ) loc_finder ON ps_inner.id = loc_finder.product_set_id
            LEFT JOIN storage_locations sl ON loc_finder.location_id = sl.id
            WHERE ps_inner.available_stock > 0 AND ps_inner.is_active = 1
            AND (ps_inner.created_at IS NULL OR DATE(ps_inner.created_at) <= ?)
        ) ps_locations ON ps.id = ps_locations.product_set_id
        WHERE (
            -- Include normal products with cost records for this month
            (COALESCE(p.product_type, 'General') != 'set' AND EXISTS(SELECT 1 FROM product_costs pc WHERE pc.product_id = p.id AND pc.month_year = ?))
            OR
            -- Include product sets with cost records for this month (like normal products)
            (COALESCE(p.product_type, 'General') = 'set' AND EXISTS(SELECT 1 FROM product_costs pc WHERE pc.product_id = p.id AND pc.month_year = ?))
        )
        $where_clause
        ORDER BY p.name
    ");
    
    // Prepare parameters for fallback query
    // Parameters: [cost_month, ps_locations_filter, normal_exists_filter, set_exists_filter]
    $fallback_params = [$selected_month_varchar, $selected_month_varchar, $selected_month_varchar, $selected_month_varchar];
    if ($selected_product_type !== 'all' && $selected_product_type !== 'normal') {
        $fallback_params[] = $selected_product_type;
    }
    
    $fallback_stmt->execute($fallback_params);
    $products = $fallback_stmt->fetchAll();
}

// Check if we need to copy products from previous month
if ($selected_month !== date('Y-m')) {
    // Debug logging
    error_log("=== COPY LOGIC DEBUG ===");
    error_log("Selected Month: $selected_month_varchar");
    error_log("Current Month: " . date('Y-m'));
    
    // Check if selected month already has any rows
    $check_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM product_costs WHERE month_year = ?");
    $check_stmt->execute([$selected_month_varchar]);
    $month_count = $check_stmt->fetch()['count'];
    
    error_log("Month Count (normal products with costs): $month_count");
    
    // Always ensure selected month has rows for all products.
    // This also fixes partially initialized future months.
    if ($month_count >= 0) {
        error_log("Ensuring selected month has records for all products");
        
        // Get previous month
        $previous_month = date('Y-m', strtotime($selected_month . '-01 - 1 month'));
        error_log("Previous Month: $previous_month");
        
        // Insert any missing rows for the selected month.
        try {
            $copy_stmt = $pdo->prepare("
                INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, notes, updated_by)
                SELECT 
                    p.id,
                    ?,
                    p.cost,
                    0,  -- Reset original cost to 0
                    0,  -- Reset supplier cost to 0
                    0,  -- Reset shipping cost to 0
                    0,  -- Reset other costs to 0
                    'Copied from previous month', -- Notes
                    NULL
                FROM products p
                WHERE p.id NOT IN (
                    SELECT product_id FROM product_costs WHERE month_year = ?
                )
                AND (p.blocked_month IS NULL OR p.blocked_month <> ?)
            ");
            $copy_result = $copy_stmt->execute([$selected_month_varchar, $selected_month_varchar, $selected_month_varchar]);
        } catch (PDOException $e) {
            error_log("Error copying cost records for selected month: " . $e->getMessage());
            $errors[] = "Could not auto-create some monthly cost records: " . $e->getMessage();
            $copy_result = false;
        }
        
        error_log("Ensure selected month records for $selected_month_varchar: " . ($copy_result ? 'SUCCESS' : 'FAILED'));
        
        // Check how many products were copied
        if ($copy_result) {
            $count_stmt = $pdo->prepare("SELECT COUNT(*) as copied FROM product_costs WHERE month_year = ? AND notes = 'Copied from previous month'");
            $count_stmt->execute([$selected_month_varchar]);
            $copied_count = $count_stmt->fetch()['copied'];
            error_log("Products copied: $copied_count");
        }
        
        // Re-fetch products after ensuring missing rows
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        error_log("Products after ensure: " . count($products));
    }
    error_log("=== END COPY DEBUG ===");
} else {
    // For current month, ensure all products have cost records
    error_log("=== CURRENT MONTH CHECK ===");
    error_log("Current Month: $selected_month_varchar");
    
    // Check if any products (normal + set) are missing cost records for current month
    $missing_stmt = $pdo->prepare("
        SELECT COUNT(*) as missing_count
        FROM products p
        WHERE p.id NOT IN (
            SELECT product_id FROM product_costs WHERE month_year = ?
        )
        AND (p.blocked_month IS NULL OR p.blocked_month <> ?)
    ");
    $missing_stmt->execute([$selected_month_varchar, $selected_month_varchar]);
    $missing_count = $missing_stmt->fetch()['missing_count'];
    
    error_log("Missing cost records for current month: $missing_count");
    
    // If products are missing cost records, create them
    if ($missing_count > 0) {
        error_log("Creating missing cost records for current month");
        
        try {
            $create_stmt = $pdo->prepare("
                INSERT INTO product_costs (product_id, month_year, selling_price, original_cost, supplier_cost, shipping_cost, other_costs, notes, updated_by)
                SELECT 
                    p.id,
                    ?,
                    p.cost,
                    0,  -- Reset original cost to 0
                    0,  -- Reset supplier cost to 0
                    0,  -- Reset shipping cost to 0
                    0,  -- Reset other costs to 0
                    'Auto-created for current month (normal/set)', -- Notes
                    NULL
                FROM products p
                WHERE p.id NOT IN (
                    SELECT product_id FROM product_costs WHERE month_year = ?
                )
                AND (p.blocked_month IS NULL OR p.blocked_month <> ?)
            ");
            $create_result = $create_stmt->execute([$selected_month_varchar, $selected_month_varchar, $selected_month_varchar]);
            error_log("Create missing cost records: " . ($create_result ? 'SUCCESS' : 'FAILED'));
        } catch (PDOException $e) {
            error_log("Error creating missing cost records: " . $e->getMessage());
            $errors[] = "Could not auto-create some monthly cost records: " . $e->getMessage();
        }
        
        // Re-fetch products after creating missing records
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        error_log("Products after creating missing records: " . count($products));
    }
    error_log("=== END CURRENT MONTH CHECK ===");
}

// Calculate summary statistics
$total_products = count($products);
$products_with_costs = 0;
$total_original_cost = 0;
$total_selling_value = 0;
$total_profit = 0;
$total_available_stock = 0;
$total_inventory_value = 0;
$total_commission = 0;

foreach ($products as $product) {
    if ($product['total_cost'] > 0) {
        $products_with_costs++;
    }
    $total_original_cost += $product['total_cost'];
    $total_selling_value += $product['selling_price'];
    // Profit calculation excludes commission
    $total_profit += ($product['selling_price'] - $product['total_cost'] - $product['commission_amount']);
    $total_available_stock += $product['available_stock'];
    $total_inventory_value += $product['inventory_value'];
    $total_commission += $product['commission_amount'];
}

$avg_profit_margin = $total_selling_value > 0 ? round(($total_profit / $total_selling_value) * 100, 2) : 0;

    include __DIR__ . '/../layout/header.php';

$month_badge_class = $is_future_month ? 'pc-badge-future' : ($is_past_month ? 'pc-badge-past' : 'pc-badge-current');
$month_badge_label = $is_future_month ? 'Future' : ($is_past_month ? 'Historical' : 'Current');
$type_filter_label = $selected_product_type === 'normal' ? 'Items' : ($selected_product_type === 'set' ? 'Product Sets' : 'All Products');
?>
<style>
.product-costs-page {
    --pc-accent: #db2777;
    --pc-accent-dark: #be185d;
    --pc-ink: #1f2937;
    --pc-muted: #6b7280;
    --pc-border: #e5e7eb;
    --pc-surface: #ffffff;
    --pc-soft: #fdf2f8;
}
.product-costs-page .pc-page-title { color: var(--pc-ink); letter-spacing: -0.02em; }
.product-costs-page .pc-btn-accent {
    background: var(--pc-accent);
    border-color: var(--pc-accent);
    color: #fff;
}
.product-costs-page .pc-btn-accent:hover,
.product-costs-page .pc-btn-accent:focus {
    background: var(--pc-accent-dark);
    border-color: var(--pc-accent-dark);
    color: #fff;
}
.product-costs-page .pc-btn-outline {
    color: var(--pc-accent);
    border-color: color-mix(in srgb, var(--pc-accent) 45%, white);
    background: #fff;
}
.product-costs-page .pc-btn-outline:hover,
.product-costs-page .pc-btn-outline:focus {
    background: var(--pc-soft);
    border-color: var(--pc-accent);
    color: var(--pc-accent-dark);
}
.product-costs-page .pc-stat-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(17, 24, 39, 0.06);
    background: var(--pc-surface);
}
.product-costs-page .pc-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.product-costs-page .pc-panel {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(17, 24, 39, 0.06);
    overflow: hidden;
}
.product-costs-page .pc-panel .card-header {
    background: #fff;
    border-bottom: 1px solid var(--pc-border);
    padding: 1rem 1.25rem;
}
.product-costs-page .pc-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border-radius: 999px;
    padding: .35rem .7rem;
    font-size: .78rem;
    font-weight: 600;
    background: #f3f4f6;
    color: var(--pc-muted);
}
.product-costs-page .pc-badge-current { background: #ecfdf5; color: #047857; }
.product-costs-page .pc-badge-past { background: #eff6ff; color: #1d4ed8; }
.product-costs-page .pc-badge-future { background: #fef3c7; color: #b45309; }
.product-costs-page .pc-help {
    border: 1px solid color-mix(in srgb, var(--pc-accent) 18%, white);
    background: linear-gradient(180deg, #fff, var(--pc-soft));
    border-radius: 14px;
}
.product-costs-page .pc-help summary {
    cursor: pointer;
    list-style: none;
    padding: .9rem 1.1rem;
    font-weight: 600;
    color: var(--pc-ink);
}
.product-costs-page .pc-help summary::-webkit-details-marker { display: none; }
.product-costs-page .pc-help[open] summary { border-bottom: 1px solid var(--pc-border); }
.product-costs-page .pc-help .pc-help-body { padding: .85rem 1.1rem 1.1rem; color: var(--pc-muted); }
.product-costs-page .pc-help ul { margin: 0; padding-left: 1.1rem; }
.product-costs-page .pc-filter-label {
    font-size: .78rem;
    font-weight: 600;
    color: var(--pc-muted);
    margin-bottom: .35rem;
}
.product-costs-page .pc-table {
    font-size: .875rem;
    --bs-table-hover-bg: #fdf2f8;
}
.product-costs-page .pc-table thead th {
    background: #f9fafb;
    color: #4b5563;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    border-bottom-width: 1px;
    white-space: nowrap;
    padding: .85rem .75rem;
    vertical-align: middle;
}
.product-costs-page .pc-table tbody td,
.product-costs-page .pc-table tfoot td {
    padding: .9rem .75rem;
    vertical-align: middle;
    border-color: #f3f4f6;
}
.product-costs-page .pc-table tbody tr:last-child td { border-bottom: 0; }
.product-costs-page .pc-row-no {
    color: var(--pc-muted);
    font-weight: 600;
    text-align: center;
    width: 52px;
}
.product-costs-page .pc-product-name {
    font-weight: 600;
    color: var(--pc-ink);
    display: flex;
    align-items: center;
    gap: .5rem;
}
.product-costs-page .pc-type {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    border-radius: 8px;
    padding: .28rem .55rem;
    font-size: .72rem;
    font-weight: 600;
}
.product-costs-page .pc-type-set { background: #eff6ff; color: #1d4ed8; }
.product-costs-page .pc-type-item { background: #f3f4f6; color: #4b5563; }
.product-costs-page .pc-brand {
    --brand-color: #6b7280;
    display: inline-block;
    border: 1px solid color-mix(in srgb, var(--brand-color) 30%, transparent);
    background: color-mix(in srgb, var(--brand-color) 12%, white);
    color: var(--brand-color);
    border-radius: 8px;
    font-weight: 600;
    padding: .3rem .55rem;
    font-size: .8rem;
}
.product-costs-page .pc-stock {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 3rem;
    border-radius: 8px;
    padding: .28rem .55rem;
    font-size: .78rem;
    font-weight: 700;
}
.product-costs-page .pc-stock-ok { background: #ecfdf5; color: #047857; }
.product-costs-page .pc-stock-out { background: #fef2f2; color: #b91c1c; }
.product-costs-page .pc-loc {
    display: inline-flex;
    margin: 2px 4px 2px 0;
    border-radius: 6px;
    padding: .18rem .4rem;
    font-size: .7rem;
    font-weight: 600;
}
.product-costs-page .pc-loc-ok { background: #ecfdf5; color: #047857; }
.product-costs-page .pc-loc-empty { background: #f3f4f6; color: #6b7280; }
.product-costs-page .pc-muted { color: var(--pc-muted); font-size: .75rem; }
.product-costs-page .pc-price { font-weight: 700; color: var(--pc-ink); font-variant-numeric: tabular-nums; }
.product-costs-page .pc-user { color: var(--pc-accent); font-weight: 600; font-size: .8rem; }
.product-costs-page .pc-edit-btn {
    border-radius: 8px;
    font-weight: 600;
    min-width: 72px;
}
.product-costs-page .pc-table tfoot td {
    background: #f9fafb;
    font-weight: 700;
    font-size: .9rem;
}
.product-costs-page .pc-total-label { color: var(--pc-muted); letter-spacing: .04em; }
.product-costs-page .pc-total-price { color: var(--pc-accent); }
.product-costs-page .pc-empty {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--pc-muted);
}
.product-costs-page .pc-empty i { font-size: 2.25rem; color: #f9a8d4; display: block; margin-bottom: .75rem; }
.product-costs-page .modal-content { border: 0; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 45px rgba(17, 24, 39, 0.18); }
.product-costs-page .pc-modal-header {
    background: linear-gradient(135deg, #db2777, #9d174d);
    color: #fff;
    border: 0;
}
.product-costs-page .pc-modal-header-edit {
    background: linear-gradient(135deg, #db2777, #9d174d);
}
.product-costs-page .pc-modal-header-info {
    background: linear-gradient(135deg, #0891b2, #0e7490);
}
.product-costs-page .pc-modal-header-warn {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}
.product-costs-page .pc-field-card {
    background: #fff;
    border: 1px solid var(--pc-border);
    border-radius: 12px;
    padding: 1rem 1.1rem;
}
.product-costs-page .pc-field-card + .pc-field-card { margin-top: .85rem; }
.product-costs-page .pc-section-title {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-weight: 700;
    color: var(--pc-ink);
    margin-bottom: .85rem;
}
.product-costs-page .pc-section-title i { color: var(--pc-accent); }
.product-costs-page .pc-total-box {
    background: linear-gradient(180deg, #fff, var(--pc-soft));
    border: 1px solid color-mix(in srgb, var(--pc-accent) 22%, white);
    border-radius: 12px;
    padding: .95rem 1.1rem;
}
.product-costs-page .pc-total-box strong { color: var(--pc-accent); }
.product-costs-page #editCostModal .form-control:read-only {
    background: #f9fafb;
    color: #4b5563;
}
.product-costs-page #editCostModal .input-group-text {
    border-color: #e5e7eb;
}
.status-switch {
    position: relative;
    display: inline-block;
    width: 56px;
    height: 30px;
    cursor: pointer;
    margin: 0;
}
.status-switch input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.status-switch-track {
    position: absolute;
    inset: 0;
    background: #dc3545;
    border-radius: 999px;
    transition: background-color 0.2s ease;
    box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.08);
}
.status-switch-knob {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 24px;
    height: 24px;
    background: #fff;
    border-radius: 50%;
    transition: transform 0.2s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
}
.status-switch-text {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: #fff;
    user-select: none;
    pointer-events: none;
}
.status-switch-text-on { left: 8px; opacity: 0; transition: opacity 0.2s ease; }
.status-switch-text-off { right: 7px; opacity: 1; transition: opacity 0.2s ease; }
.status-switch input:checked + .status-switch-track { background: #198754; }
.status-switch input:checked + .status-switch-track .status-switch-knob { transform: translateX(26px); }
.status-switch input:checked + .status-switch-track .status-switch-text-on { opacity: 1; }
.status-switch input:checked + .status-switch-track .status-switch-text-off { opacity: 0; }
.status-switch input:focus-visible + .status-switch-track {
    outline: 2px solid rgba(219, 39, 119, 0.45);
    outline-offset: 2px;
}
.status-switch-next .status-switch-track { background: #6c757d; }
.status-switch-next input:checked + .status-switch-track { background: #fd7e14; }
.status-switch-next .status-switch-text-on { left: 6px; font-size: 9px; }
.status-switch-next .status-switch-text-off { right: 6px; font-size: 9px; }
.product-close-reason {
    color: #a16207;
    background: #fff7d6;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 6px 8px;
}
#nextMonthCloseReasonInput { resize: vertical; min-height: 110px; }
@media print {
    .product-costs-page .no-print { display: none !important; }
    .product-costs-page .pc-panel { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
</style>
<div class="container-fluid py-4 product-costs-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 fw-bold mb-1 pc-page-title">Product Cost Management</h1>
            <p class="text-muted mb-0">
                Manage monthly selling prices and costs for
                <strong><?= htmlspecialchars($selected_month_display) ?></strong>
                <span class="pc-chip <?= $month_badge_class ?> ms-1"><?= $month_badge_label ?></span>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn pc-btn-outline" onclick="printProductCosts()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <button type="button" class="btn pc-btn-accent" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-circle me-1"></i>Add Product
            </button>
        </div>
    </div>

    <details class="pc-help mb-4 no-print">
        <summary>
            <i class="bi bi-info-circle text-danger me-1"></i>
            How this system works
        </summary>
        <div class="pc-help-body">
            <ul>
                <li><strong>Add products in the current month</strong> with selling price and costs</li>
                <li><strong>Next month auto-carries products</strong> with selling price; costs reset to $0</li>
                <li><strong>Update costs each month</strong> — data is month-specific</li>
                <li><strong>Needs update</strong> when costs are still $0</li>
            </ul>
        </div>
    </details>

    <div class="card pc-panel mb-4 no-print">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3 col-lg-2">
                    <label class="pc-filter-label">Month</label>
                    <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($selected_month) ?>" max="<?= htmlspecialchars($current_month) ?>" required>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="pc-filter-label">Product Type</label>
                    <select name="product_type" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $selected_product_type === 'all' ? 'selected' : '' ?>>All Products</option>
                        <option value="normal" <?= $selected_product_type === 'normal' ? 'selected' : '' ?>>Items</option>
                        <option value="set" <?= $selected_product_type === 'set' ? 'selected' : '' ?>>Product Sets</option>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn pc-btn-accent">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                </div>
                <div class="col-md-auto ms-md-auto">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="pc-chip <?= $month_badge_class ?>">
                            <i class="bi bi-calendar3"></i><?= htmlspecialchars($selected_month_display) ?>
                        </span>
                        <span class="pc-chip">
                            <i class="bi bi-funnel"></i><?= htmlspecialchars($type_filter_label) ?>
                        </span>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($e) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card pc-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="pc-stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-box-seam"></i></span>
                    <div>
                        <div class="text-muted small">Total Products</div>
                        <div class="fs-4 fw-bold"><?= number_format($total_products) ?></div>
                        <div class="text-muted small"><?= number_format($products_with_costs) ?> with costs</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card pc-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="pc-stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-stack"></i></span>
                    <div>
                        <div class="text-muted small">Available Stock</div>
                        <div class="fs-4 fw-bold"><?= number_format($total_available_stock, 2) ?></div>
                        <div class="text-muted small">Total units available</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card pc-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="pc-stat-icon" style="background: color-mix(in srgb, var(--pc-accent) 12%, white); color: var(--pc-accent);"><i class="bi bi-currency-dollar"></i></span>
                    <div>
                        <div class="text-muted small">Selling Value</div>
                        <div class="fs-4 fw-bold">$<?= number_format($total_selling_value, 2) ?></div>
                        <div class="text-muted small">Sum of selling prices</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card pc-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="pc-stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-graph-up-arrow"></i></span>
                    <div>
                        <div class="text-muted small">Avg Margin</div>
                        <div class="fs-4 fw-bold"><?= number_format($avg_profit_margin, 1) ?>%</div>
                        <div class="text-muted small">Estimated profit margin</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card pc-panel flex-grow-1 d-flex flex-column">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 no-print">
            <div>
                <h5 class="mb-0 fw-bold">Product Cost Details</h5>
                <small class="text-muted">
                    Add products and manage monthly costs
                    <?php if ($selected_product_type !== 'all'): ?>
                        · Showing <?= htmlspecialchars($selected_product_type === 'normal' ? 'items' : 'product sets') ?> only
                    <?php endif; ?>
                </small>
            </div>
            <button type="button" class="btn btn-sm pc-btn-accent" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-circle me-1"></i>Add Product
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 pc-table" id="productsTable">
                    <thead>
                        <tr>
                            <th class="text-center">No</th>
                            <th>Product</th>
                            <th class="text-center">Type</th>
                            <th>Brand</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Next Month</th>
                            <th class="text-center">Available</th>
                            <th>Storage</th>
                            <th class="text-end">Selling Price</th>
                            <th>Created By</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="11">
                                    <div class="pc-empty">
                                        <i class="bi bi-inbox"></i>
                                        <div class="fw-semibold text-dark mb-1">No products for this month</div>
                                        <div>Try another month, or add a new product.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                        <?php $row_no = 1; foreach ($products as $product): ?>
                            <tr>
                                <td class="pc-row-no"><?= $row_no++ ?></td>
                                <td>
                                    <div class="pc-product-name">
                                        <?php if (($product['product_type'] ?? 'normal') === 'set'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info py-0 px-1 no-print" onclick="showSetDetailsModal(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name']) ?>', '<?= htmlspecialchars(addslashes($product['set_items'] ?? '')) ?>')" title="View Set Details">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($product['name']) ?></span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $product_type = $product['product_type'] ?? 'normal';
                                    $type_icon = $product_type === 'set' ? 'collection' : 'box';
                                    $type_text = $product_type === 'set' ? 'Product Set' : product_type_display_label($product_type);
                                    $type_mod = $product_type === 'set' ? 'pc-type-set' : 'pc-type-item';
                                    ?>
                                    <span class="pc-type <?= $type_mod ?>">
                                        <i class="bi bi-<?= $type_icon ?>"></i><?= $type_text ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $rowBrand = null;
                                    foreach ($brandsForAssign as $br) {
                                        if ((int)$br['id'] === (int)($product['brand_id'] ?? 0)) { $rowBrand = $br; break; }
                                    }
                                    if ($rowBrand): ?>
                                        <span class="pc-brand" style="--brand-color:<?= htmlspecialchars($rowBrand['color']) ?>">
                                            <?= htmlspecialchars($rowBrand['name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="pc-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <label class="status-switch" for="active_<?= $product['id'] ?>">
                                        <input type="checkbox" id="active_<?= $product['id'] ?>"
                                               <?= $product['active'] ? 'checked' : '' ?>
                                               onchange="toggleProductStatus(<?= $product['id'] ?>, this.checked)">
                                        <span class="status-switch-track">
                                            <span class="status-switch-text status-switch-text-on">ON</span>
                                            <span class="status-switch-text status-switch-text-off">OFF</span>
                                            <span class="status-switch-knob"></span>
                                        </span>
                                    </label>
                                </td>
                                <td class="text-center">
                                    <?php $is_closed_next_month = (($product['blocked_month'] ?? '') === $next_month); ?>
                                    <label class="status-switch status-switch-next" for="next_month_close_<?= $product['id'] ?>">
                                        <input type="checkbox" id="next_month_close_<?= $product['id'] ?>"
                                               <?= $is_closed_next_month ? 'checked' : '' ?>
                                               data-close-reason="<?= htmlspecialchars((string)($product['blocked_reason'] ?? ''), ENT_QUOTES) ?>"
                                               data-product-name="<?= htmlspecialchars((string)$product['name'], ENT_QUOTES) ?>"
                                               onchange="toggleNextMonthClose(<?= $product['id'] ?>, this.checked)">
                                        <span class="status-switch-track">
                                            <span class="status-switch-text status-switch-text-on">CLOSE</span>
                                            <span class="status-switch-text status-switch-text-off">OPEN</span>
                                            <span class="status-switch-knob"></span>
                                        </span>
                                    </label>
                                </td>
                                <td class="text-center">
                                    <?php if ($product['available_stock'] > 0): ?>
                                        <span class="pc-stock pc-stock-ok"><?= number_format($product['available_stock'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="pc-stock pc-stock-out">Out</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap"><?= parseStorageLocations($product['storage_locations'] ?? '') ?></div>
                                </td>
                                <td class="text-end pc-price">$<?= number_format($product['selling_price'], 2) ?></td>
                                <td>
                                    <?php if (!empty($product['updated_by_name'])): ?>
                                        <span class="pc-user"><?= htmlspecialchars($product['updated_by_name']) ?></span>
                                    <?php else: ?>
                                        <span class="pc-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="no-print">
                                    <button type="button" class="btn btn-sm btn-primary pc-edit-btn" onclick="editCost(<?= $product['id'] ?>, '<?= htmlspecialchars($product['product_name'], ENT_QUOTES) ?>', <?= (float)$product['selling_price'] ?>, <?= (int)($product['brand_id'] ?? 0) ?>)">
                                        <i class="bi bi-pencil-square me-1"></i>Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($products)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="pc-total-label">TOTALS</td>
                            <td class="text-center text-muted"><?= number_format($total_available_stock, 2) ?></td>
                            <td></td>
                            <td class="text-end pc-total-price">$<?= number_format($total_selling_value, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="nextMonthReasonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header pc-modal-header-warn">
                    <h5 class="modal-title d-flex align-items-center gap-2">
                        <i class="bi bi-chat-left-text"></i>
                        <span>Close Product For Next Month</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="small text-muted mb-1">Product</div>
                        <div class="fw-semibold" id="nextMonthReasonProductName">-</div>
                    </div>
                    <div class="alert alert-warning py-2 d-flex align-items-start gap-2 mb-3">
                        <i class="bi bi-info-circle mt-1"></i>
                        <div>
                            This product will be skipped when creating data for <strong><?= htmlspecialchars(date('F Y', strtotime($next_month . '-01'))) ?></strong>.
                        </div>
                    </div>
                    <label for="nextMonthCloseReasonInput" class="form-label fw-semibold">
                        <i class="bi bi-pencil-square me-1"></i>Reason
                    </label>
                    <textarea id="nextMonthCloseReasonInput" class="form-control" rows="4" maxlength="255" placeholder="Write the reason for closing this product next month..."></textarea>
                    <div class="form-text">Reason is required and will be shown on the product row.</div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="saveNextMonthReasonBtn">
                        <i class="bi bi-check2-circle me-1"></i>Save Reason
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Set Details Modal -->
    <div class="modal fade" id="setDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header pc-modal-header-info">
                    <h5 class="modal-title">
                        <i class="bi bi-info-circle me-2"></i>
                        <span id="setDetailsTitle">Product Set Details</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="text-muted mb-3 fw-semibold">
                        <i class="bi bi-boxes me-1"></i>Individual products in this set
                    </h6>
                    <div id="setDetailsContent" class="pc-field-card bg-light">
                        <!-- Content will be populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" id="addProductForm">
                    <input type="hidden" name="action" value="add_product">
                    <input type="hidden" name="product_type" value="normal">

                    <div class="modal-header pc-modal-header">
                        <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                            <i class="bi bi-plus-circle"></i>
                            Add New Product &ndash; <?= htmlspecialchars($selected_month_display) ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body" style="background:#fafafa;">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-tag text-danger"></i></span>
                                <input type="text" name="product_name" id="addProductName"
                                       class="form-control form-control-lg"
                                       placeholder="Enter product name..." required maxlength="255">
                            </div>
                            <div class="form-text">Choose a clear, descriptive name</div>
                        </div>

                        <div class="pc-field-card mb-0">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <i class="bi bi-tag-fill text-danger"></i>
                                <span class="fw-bold">Product Information</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Selling Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-currency-dollar text-success"></i></span>
                                        <input type="number" step="0.01" name="selling_price" id="addSellingPrice"
                                               class="form-control form-control-lg" required min="0" value="0.00">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Brand <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-bookmark text-primary"></i></span>
                                        <select name="brand_id" id="addBrandId" class="form-select form-select-lg" required>
                                            <option value="">— No brand —</option>
                                            <option value="" selected disabled>Select brand</option>
                                            <?php foreach ($brandsForAssign as $br): ?>
                                                <option value="<?= (int)$br['id'] ?>" data-color="<?= htmlspecialchars($br['color']) ?>">
                                                    <?= htmlspecialchars($br['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="original_cost" id="addOriginalCost" value="0.00">
                        <input type="hidden" name="supplier_cost" id="addSupplierCost" value="0.00">
                        <input type="hidden" name="shipping_cost" id="addShippingCost" value="0.00">
                        <input type="hidden" name="other_costs" id="addOtherCosts" value="0.00">
                        <input type="hidden" name="commission_rate" id="addCommissionRate" value="0.00">
                        <input type="hidden" name="commission_amount" id="addCommissionAmount" value="0.00">
                        <input type="hidden" name="notes" id="addNotes" value="">
                        <span id="addTotalCostDisplay" class="d-none">0.00</span>
                    </div>

                    <div class="modal-footer border-0 gap-2">
                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn pc-btn-accent px-4">
                            <i class="bi bi-plus-circle me-1"></i>Add Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

    <!-- Edit Cost Modal -->
    <div class="modal fade product-costs-page" id="editCostModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form method="post" id="editCostForm">
                    <input type="hidden" name="action" value="update_cost">
                    <input type="hidden" name="product_id" id="editProductId">
                    <input type="hidden" name="month_year" id="editMonthYear">

                    <div class="modal-header pc-modal-header">
                        <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                            <i class="bi bi-pencil-square"></i>
                            Edit Product &ndash; <?= htmlspecialchars($selected_month_display) ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body" style="background:#fafafa;">
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="editProductName">Product Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-tag text-danger"></i></span>
                                <input type="text" name="product_name" id="editProductName"
                                       class="form-control form-control-lg"
                                       placeholder="Enter product name..." required maxlength="255">
                            </div>
                        </div>

                        <div class="pc-field-card mb-0">
                            <div class="pc-section-title">
                                <i class="bi bi-tag-fill"></i>
                                <span>Product Information</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Selling Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-currency-dollar text-success"></i></span>
                                        <input type="number" step="0.01" name="selling_price" id="editSellingPrice"
                                               class="form-control form-control-lg" required min="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Brand <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-bookmark text-primary"></i></span>
                                        <select name="brand_id" id="editBrandId" class="form-select form-select-lg" required>
                                            <option value="">— No brand —</option>
                                            <option value="" disabled>Select brand</option>
                                            <?php foreach ($brandsForAssign as $br): ?>
                                                <option value="<?= (int)$br['id'] ?>" data-color="<?= htmlspecialchars($br['color']) ?>">
                                                    <?= htmlspecialchars($br['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 gap-2">
                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn pc-btn-accent px-4">
                            <i class="bi bi-check2-circle me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
// Product set data
var productSets = window.productSets || [];

function productCostsRunWhenReady(fn) {
    if (window.adminRunWhenReady) {
        window.adminRunWhenReady(fn);
        return;
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
}

// Load product sets when page loads or when this page is injected by the admin shell.
productCostsRunWhenReady(function() {
    loadProductSets();
    
    // Add event listeners for real-time total cost updates
    ['editOriginalCost', 'editSupplierCost', 'editShippingCost', 'editOtherCosts', 'editCommissionAmount'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', updateTotalCost);
        }
    });
    
    // Add modal fields
    ['addOriginalCost', 'addSupplierCost', 'addShippingCost', 'addOtherCosts', 'addCommissionAmount'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', updateAddTotalCost);
        }
    });

    bindProductCostAjaxForms();
});

if (!window.productCostsContentLoadedBound) {
    window.productCostsContentLoadedBound = true;
    document.addEventListener('admin:content-loaded', function(event) {
        const url = event && event.detail ? String(event.detail.url || '') : '';
        if (!url.includes('product_costs.php')) return;
        cleanupProductCostModalState();
        bindProductCostAjaxForms();
        loadProductSets();
    });
}

// Load product sets from database
function loadProductSets() {
    console.log('Loading product sets...');
    
    fetch('product_set_management.php?action=get_product_sets')
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error('Expected JSON, got: ' + text.slice(0, 80));
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Product sets data:', data);
            productSets = Array.isArray(data) ? data : [];
            window.productSets = productSets;
            updateProductSetDropdown();
        })
        .catch(error => {
            console.error('Error loading product sets:', error);
        });
}

function fetchJson(url, options) {
    return fetch(url, options)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return response.text().then(text => {
                    throw new Error('Expected JSON, got: ' + text.slice(0, 80));
                });
            }
            return response.json();
        })
}

// Update product set dropdown with basic info (working version)
function updateProductSetDropdown() {
    const select = document.getElementById('addProductSetId');
    if (!select) {
        console.error('Product set dropdown not found!');
        return;
    }
    
    console.log('Updating dropdown with product sets:', productSets);
    
    select.innerHTML = '<option value="">Select a product set...</option>';
    
    if (productSets && productSets.length > 0) {
        productSets.forEach(set => {
            const option = document.createElement('option');
            option.value = set.id;
            
            // Create basic text with set info
            let displayText = `${set.set_name}`;
            
            // Add product count and cost info
            const productCount = set.product_count || 0;
            const totalCost = set.total_cost || 0;
            displayText += ` (${productCount} items, Cost: $${totalCost.toFixed(2)})`;
            
            option.textContent = displayText;
            select.appendChild(option);
        });
        console.log('Dropdown updated successfully with', productSets.length, 'sets');
    } else {
        console.log('No product sets available');
    }
}

// Toggle between normal product and product set
function toggleProductType() {
    const productType = document.getElementById('addProductType').value;
    const normalFields = document.getElementById('normalProductFields');
    const setFields    = document.getElementById('setProductFields');

    if (productType === 'set') {
        normalFields.style.display = 'none';
        setFields.style.display    = 'block';
        document.getElementById('addProductSetId').required = true;
        document.getElementById('addProductName').required  = false;
    } else {
        normalFields.style.display = 'block';
        setFields.style.display    = 'none';
        document.getElementById('addProductName').required  = true;
        document.getElementById('addProductSetId').required = false;
        // Clear set info when switching back to normal
        document.getElementById('setProductInfo').innerHTML = '<small class="text-muted">Select a product set to view details...</small>';
    }
}

// Update product set information when selection changes
productCostsRunWhenReady(function() {
    const setSelect = document.getElementById('addProductSetId');
    if (setSelect) {
        setSelect.addEventListener('change', function() {
            const setId = this.value;
            if (setId) {
                const selectedSet = productSets.find(set => set.id == setId);
                if (selectedSet) {
                    updateSetProductInfo(selectedSet);
                }
            } else {
                document.getElementById('setProductInfo').innerHTML = 'Select a product set to view details...';
            }
        });
    }
});

// Update product set information display
function updateSetProductInfo(productSet) {
    const infoDiv = document.getElementById('setProductInfo');
    if (!infoDiv) return;
    
    // Fetch detailed product set information including individual products
    fetchJson(`product_set_management.php?action=get_set_details&set_id=${productSet.id}`)
        .then(data => {
            if (data.success) {
                displaySetDetails(data.set, data.products);
                autoCalculatePricing(data.products);
            } else {
                // Fallback to basic info if detailed fetch fails
                displayBasicSetInfo(productSet);
            }
        })
        .catch(error => {
            console.error('Error fetching set details:', error);
            displayBasicSetInfo(productSet);
        });
}

function displaySetDetails(productSet, products) {
    const infoDiv = document.getElementById('setProductInfo');
    if (!infoDiv) return;
    
    // Calculate totals from individual products
    let totalOriginalCost = 0;
    let productDetails = '';
    
    products.forEach((product, index) => {
        const itemTotal = parseFloat(product.unit_cost || 0) * parseFloat(product.quantity || 1);
        totalOriginalCost += itemTotal;
        
        productDetails += `
            <div class="small mb-2 p-2 bg-light rounded">
                <strong>${index + 1}. ${product.name}</strong><br>
                <small>
                    Cost: $${parseFloat(product.unit_cost || 0).toFixed(2)} × ${product.quantity || 1} = $${itemTotal.toFixed(2)}
                </small>
            </div>
        `;
    });
    
    // Auto-calculate pricing
    const commissionRate = parseFloat(productSet.commission_rate || 0);
    const commissionAmount = totalOriginalCost * (commissionRate / 100);
    const originalCost = totalOriginalCost;
    const sellingPrice = originalCost + commissionAmount;
    const profitMargin = originalCost > 0 ? ((sellingPrice - originalCost) / sellingPrice) * 100 : 0;
    
    infoDiv.innerHTML = `
        <div class="mb-3">
            <h6><i class="bi bi-collection me-2"></i>${productSet.set_name}</h6>
            ${productSet.set_description ? `<p class="text-muted small">${productSet.set_description}</p>` : ''}
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Products (${products.length}):</strong><br>
                ${productDetails}
            </div>
                <div class="col-md-6">
                    <strong>Cost Breakdown:</strong><br>
                    <small>
                        Original Cost: $${totalOriginalCost.toFixed(2)}<br>
                        Commission (${commissionRate}%): $${commissionAmount.toFixed(2)}<br>
                        <strong>Total Cost:</strong> $${sellingPrice.toFixed(2)}
                    </small>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            <h6><i class="bi bi-calculator me-2"></i>Auto-Calculated Pricing</h6>
            <div class="row">
                <div class="col-md-6">
                    <strong>Original Cost:</strong> $${totalOriginalCost.toFixed(2)}<br>
                    <strong>Commission Rate:</strong> ${commissionRate}%<br>
                    <strong>Commission Amount:</strong> $${commissionAmount.toFixed(2)}
                </div>
                <div class="col-md-6">
                    <strong>Selling Price:</strong> $${sellingPrice.toFixed(2)}<br>
                    <strong>Profit Margin:</strong> ${profitMargin.toFixed(1)}%<br>
                    <strong>Profit per Set:</strong> $${(sellingPrice - totalOriginalCost).toFixed(2)}
                </div>
            </div>
        </div>
    `;
    
    // Auto-populate form fields
    autoPopulateCostFields(totalOriginalCost, sellingPrice, commissionRate, commissionAmount);
}

function displayBasicSetInfo(productSet) {
    const infoDiv = document.getElementById('setProductInfo');
    if (!infoDiv) return;
    
    const totalCost = parseFloat(productSet.total_cost || 0);
    const sellingPrice = parseFloat(productSet.selling_price || 0);
    const profitMargin = sellingPrice > 0 ? ((sellingPrice - totalCost) / sellingPrice) * 100 : 0;
    
    infoDiv.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <strong>Set Name:</strong> ${productSet.set_name}<br>
                <strong>Total Cost:</strong> $${totalCost.toFixed(2)}<br>
                <strong>Products:</strong> ${productSet.product_count || 0}<br>
                <strong>Current Price:</strong> $${sellingPrice.toFixed(2)}
            </div>
            <div class="col-md-6">
                <strong>Profit Margin:</strong> ${profitMargin.toFixed(1)}%<br>
                <strong>Profit per Set:</strong> $${(sellingPrice - totalCost).toFixed(2)}<br>
                <strong>Status:</strong> <span class="badge bg-success">Active</span>
            </div>
        </div>
    `;
    
    // Auto-populate form fields
    autoPopulateCostFields(totalCost, sellingPrice, 0, 0);
}

function autoPopulateCostFields(originalCost, sellingPrice, commissionRate, commissionAmount) {
    // Auto-populate cost fields with calculated values
    const originalCostInput = document.getElementById('addOriginalCost');
    const sellingPriceInput = document.getElementById('addSellingPrice');
    const commissionRateInput = document.getElementById('addCommissionRate');
    
    if (originalCostInput) {
        originalCostInput.value = originalCost.toFixed(2);
    }
    
    if (sellingPriceInput) {
        sellingPriceInput.value = sellingPrice.toFixed(2);
    }
    
    if (commissionRateInput) {
        commissionRateInput.value = commissionRate.toFixed(1);
    }
    
    // Trigger cost calculation to update all related fields
    updateAddTotalCost();
}

function autoCalculatePricing(products) {
    // Calculate totals from individual products
    let totalOriginalCost = 0;
    
    products.forEach(product => {
        const itemTotal = parseFloat(product.unit_cost || 0) * parseFloat(product.quantity || 1);
        totalOriginalCost += itemTotal;
    });
    
    // Get commission rate from set or use default
    const commissionRate = 10; // Default 10% commission
    
    // Calculate pricing
    const commissionAmount = totalOriginalCost * (commissionRate / 100);
    const sellingPrice = totalOriginalCost + commissionAmount;
    const profitMargin = totalOriginalCost > 0 ? ((sellingPrice - totalOriginalCost) / sellingPrice) * 100 : 0;
    
    // Update form fields
    autoPopulateCostFields(totalOriginalCost, sellingPrice, commissionRate, commissionAmount);
}

var selectedMonth = '<?= htmlspecialchars($selected_month) ?>';
var selectedMonthDisplay = '<?= htmlspecialchars($selected_month_display) ?>';

function editCost(productId, productName, sellingPrice, brandId) {
    document.getElementById('editProductId').value = productId;
    document.getElementById('editProductName').value = productName;
    document.getElementById('editSellingPrice').value = sellingPrice;
    document.getElementById('editMonthYear').value = selectedMonth;

    const editBrandSel = document.getElementById('editBrandId');
    if (editBrandSel) editBrandSel.value = brandId || '';

    const editTitle = document.querySelector('#editCostModal .modal-title');
    if (editTitle) {
        editTitle.innerHTML = '<i class="bi bi-pencil-square"></i> Edit Product &ndash; ' + selectedMonthDisplay;
    }

    const modal = new bootstrap.Modal(document.getElementById('editCostModal'));
    modal.show();
}

function updateTotalCost() {
    const originalEl = document.getElementById('editOriginalCost');
    const totalEl = document.getElementById('totalCostDisplay');
    if (!originalEl || !totalEl) return;

    const original = parseFloat(originalEl.value) || 0;
    const supplier = parseFloat(document.getElementById('editSupplierCost')?.value) || 0;
    const shipping = parseFloat(document.getElementById('editShippingCost')?.value) || 0;
    const other = parseFloat(document.getElementById('editOtherCosts')?.value) || 0;
    // Commission is excluded from total cost calculation
    const total = original + supplier + shipping + other;

    totalEl.value = total.toFixed(2);
}

function clearAddProductForm() {
    document.getElementById('addProductName').value = '';
    document.getElementById('addSellingPrice').value = '0.00';
    document.getElementById('addOriginalCost').value = '0.00';
    document.getElementById('addSupplierCost').value = '0.00';
    document.getElementById('addShippingCost').value = '0.00';
    document.getElementById('addOtherCosts').value = '0.00';
    document.getElementById('addCommissionRate').value = '0.00';
    document.getElementById('addCommissionAmount').value = '0.00';
    document.getElementById('addNotes').value = '';
    const brandSel = document.getElementById('addBrandId');
    if (brandSel) {
        const placeholderIndex = Array.from(brandSel.options).findIndex(option => option.textContent.trim() === 'Select brand');
        brandSel.selectedIndex = placeholderIndex >= 0 ? placeholderIndex : 0;
    }
    updateAddTotalCost();
}

function updateAddProductTotals() {
    updateAddTotalCost();
}

function updateAddTotalCost() {
    const original = parseFloat(document.getElementById('addOriginalCost').value) || 0;
    const supplier = parseFloat(document.getElementById('addSupplierCost').value) || 0;
    const shipping = parseFloat(document.getElementById('addShippingCost').value) || 0;
    const other = parseFloat(document.getElementById('addOtherCosts').value) || 0;
    // Commission is excluded from total cost calculation
    const total = original + supplier + shipping + other;
    
    document.getElementById('addTotalCostDisplay').textContent = total.toFixed(2);
}

function bindProductCostAjaxForms() {
    ['addProductForm', 'editCostForm'].forEach(function(formId) {
        const form = document.getElementById(formId);
        if (!form || form.dataset.ajaxBound === '1') return;
        form.dataset.ajaxBound = '1';

        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
            }
            // Let the browser submit normally. The PHP handler redirects back with a success message,
            // which is the same simple "save, update page, keep going" flow users expect here.
        });
    });
}

function cleanupProductCostModalState() {
    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
}

function exportToExcel() {
    const table = document.getElementById('productsTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        for (let j = 0; j < cols.length; j++) { 
            const col = cols[j];
            const text = col.textContent || col.innerText;
            rowData.push('"' + text.trim().replace(/"/g, '""') + '"');
        }
        
        csv.push(rowData.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'product_costs_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function toggleProductStatus(productId, isActive) {
    fetch('product_costs.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `toggle_product_status=1&product_id=${productId}&active=${isActive ? 1 : 0}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Show success message
            showToast(isActive ? 'Product activated' : 'Product deactivated', 'success');
        } else {
            // Revert the toggle on error
            document.getElementById(`active_${productId}`).checked = !isActive;
            showToast('Error updating product status', 'error');
        }
    })
    .catch(error => {
        console.error('toggleProductStatus error:', error);
        // Revert the toggle on error
        document.getElementById(`active_${productId}`).checked = !isActive;
        showToast('Error updating product status', 'error');
    });
}

var pendingNextMonthClose = null;

function toggleNextMonthClose(productId, isClosed) {
    const checkbox = document.getElementById(`next_month_close_${productId}`);
    if (isClosed) {
        pendingNextMonthClose = { productId, checkbox };
        const modalEl = document.getElementById('nextMonthReasonModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('nextMonthReasonProductName').textContent = checkbox.dataset.productName || `Product #${productId}`;
        document.getElementById('nextMonthCloseReasonInput').value = checkbox.dataset.closeReason || '';
        modal.show();
        return;
    }

    submitNextMonthClose(productId, false, '', checkbox);
}

function submitNextMonthClose(productId, isClosed, closeReason, checkbox) {
    const encodedReason = encodeURIComponent(closeReason || '');

    fetch('product_costs.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `toggle_next_month_close=1&product_id=${productId}&close_for_next_month=${isClosed ? 1 : 0}&close_reason=${encodedReason}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            checkbox.dataset.closeReason = data.blocked_reason || '';
            showToast(isClosed ? 'Closed for next month' : 'Opened for next month', 'success');
            window.location.reload();
        } else {
            checkbox.checked = !isClosed;
            showToast(data.message || 'Error updating next month status', 'error');
        }
    })
    .catch(error => {
        console.error('toggleNextMonthClose error:', error);
        checkbox.checked = !isClosed;
        showToast('Error updating next month status', 'error');
    });
}

productCostsRunWhenReady(function () {
    const modalEl = document.getElementById('nextMonthReasonModal');
    const saveBtn = document.getElementById('saveNextMonthReasonBtn');
    const reasonInput = document.getElementById('nextMonthCloseReasonInput');

    if (saveBtn && modalEl && reasonInput) {
        saveBtn.addEventListener('click', function () {
            if (!pendingNextMonthClose) {
                return;
            }

            const closeReason = reasonInput.value.trim();
            if (!closeReason) {
                reasonInput.focus();
                showToast('Reason is required to close for next month', 'error');
                return;
            }

            const { productId, checkbox } = pendingNextMonthClose;
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            submitNextMonthClose(productId, true, closeReason, checkbox);
            pendingNextMonthClose = null;
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (pendingNextMonthClose) {
                pendingNextMonthClose.checkbox.checked = false;
                pendingNextMonthClose = null;
            }
            reasonInput.value = '';
        });
    }
});

function showToast(message, type = 'info') {
    // Create toast container if it doesn't exist
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
        document.body.appendChild(toastContainer);
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show`;
    toast.style.cssText = 'margin-bottom: 10px; min-width: 250px;';
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    toastContainer.appendChild(toast);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
    }, 3000);
}


function printProductCosts() {
    const printWindow = window.open('', '_blank');
    const table = document.getElementById('productsTable');
    const monthDisplay = '<?= htmlspecialchars($selected_month_display) ?>';
    const visibleColumnCount = 10;
    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const cellText = (cell, index) => {
        if (!cell) return '';
        if (index === 4) {
            return cell.querySelector('input')?.checked ? 'ON' : 'OFF';
        }
        if (index === 5) {
            return cell.querySelector('input')?.checked ? 'CLOSE' : 'OPEN';
        }
        if (index === 1) {
            return cell.querySelector('div')?.textContent?.trim() || cell.textContent.trim();
        }
        return cell.textContent.trim().replace(/\s+/g, ' ');
    };

    let tableHTML = '<table><thead><tr>';
    Array.from(table.querySelectorAll('thead th')).slice(0, visibleColumnCount).forEach((cell) => {
        tableHTML += `<th>${escapeHtml(cell.textContent.trim())}</th>`;
    });
    tableHTML += '</tr></thead><tbody>';

    table.querySelectorAll('tbody tr').forEach((row) => {
        tableHTML += '<tr>';
        Array.from(row.children).slice(0, visibleColumnCount).forEach((cell, index) => {
            const alignClass = cell.classList.contains('text-end') ? ' class="text-end"' : (cell.classList.contains('text-center') ? ' class="text-center"' : '');
            tableHTML += `<td${alignClass}>${escapeHtml(cellText(cell, index))}</td>`;
        });
        tableHTML += '</tr>';
    });

    table.querySelectorAll('tfoot tr').forEach((row) => {
        tableHTML += '<tr class="total-row">';
        Array.from(row.children).slice(0, visibleColumnCount).forEach((cell) => {
            const colspan = cell.colSpan > 1 ? ` colspan="${cell.colSpan}"` : '';
            tableHTML += `<td${colspan}>${escapeHtml(cell.textContent.trim().replace(/\s+/g, ' '))}</td>`;
        });
        tableHTML += '</tr>';
    });

    tableHTML += '</tbody></table>';
    
    let html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Product Cost Details - ${monthDisplay}</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    font-size: 12px;
                }
                h2 {
                    text-align: center;
                    margin-bottom: 20px;
                    color: #333;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                th, td {
                    border: 1px solid #000 !important;
                    padding: 8px;
                    text-align: left;
                    vertical-align: top;
                }
                th {
                    background-color: #f0f0f0 !important;
                    font-weight: bold;
                    text-align: center;
                }
                .text-end {
                    text-align: right !important;
                }
                .text-center {
                    text-align: center !important;
                }
                .fw-bold {
                    font-weight: bold !important;
                }
                .table-light {
                    background-color: #f8f9fa !important;
                }
                @media print {
                    @page {
                        margin: 1cm;
                        orientation: landscape;
                    }
                }
            </style>
        </head>
        <body>
            <h2>Product Cost Details - ${monthDisplay}</h2>
            ${tableHTML}
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
}

// Update total cost when cost inputs change
productCostsRunWhenReady(function() {
    // Edit modal inputs
    ['editOriginalCost', 'editSupplierCost', 'editShippingCost', 'editOtherCosts'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', updateTotalCost);
        }
    });

    // Add modal event listeners
    const addModal = document.getElementById('addProductModal');
    if (addModal) {
        addModal.addEventListener('show.bs.modal', function() {
            // Clear all form fields when modal opens
            clearAddProductForm();
        });
    }

    // Add modal cost input listeners
    ['addOriginalCost', 'addSupplierCost', 'addShippingCost', 'addOtherCosts', 'addCommissionAmount'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', updateAddProductTotals);
        }
    });

    // Commission rate change affects amount and vice versa
    document.getElementById('addCommissionRate')?.addEventListener('input', function() {
        const rate = parseFloat(this.value) || 0;
        const sellingPrice = parseFloat(document.getElementById('addSellingPrice').value) || 0;
        const amount = sellingPrice * (rate / 100);
        document.getElementById('addCommissionAmount').value = amount.toFixed(2);
        updateAddProductTotals();
    });

    document.getElementById('addCommissionAmount')?.addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;
        const sellingPrice = parseFloat(document.getElementById('addSellingPrice').value) || 0;
        const rate = sellingPrice > 0 ? (amount / sellingPrice) * 100 : 0;
        document.getElementById('addCommissionRate').value = rate.toFixed(2);
        updateAddProductTotals();
    });
    
    // Add modal inputs
    ['addOriginalCost', 'addSupplierCost', 'addShippingCost', 'addOtherCosts'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', updateAddTotalCost);
        }
    });
    
    // Load product sets when page loads
    loadProductSets();
    bindProductCostAjaxForms();
    
    // Listen for new product set notifications
    window.addEventListener('storage', function(e) {
        if (e.key === 'newProductSet' && e.newValue) {
            try {
                const newSetData = JSON.parse(e.newValue);
                console.log('Received new product set notification:', newSetData);
                
                // Add new set to productSets array
                if (productSets) {
                    productSets.push({
                        id: newSetData.set_id,
                        set_name: newSetData.set_name,
                        total_cost: newSetData.total_cost,
                        product_count: newSetData.product_count,
                        is_active: 1
                    });
                    
                    // Update dropdown
                    updateProductSetDropdown();
                    
                    // Show notification
                    showNewSetNotification(newSetData);
                }
            } catch (error) {
                console.error('Error parsing new product set data:', error);
            }
        }
    });
    
    // Check for any pending new product sets on load
    const pendingNewSet = localStorage.getItem('newProductSet');
    if (pendingNewSet) {
        try {
            const newSetData = JSON.parse(pendingNewSet);
            console.log('Found pending new product set:', newSetData);
            
            // Clear the pending notification
            localStorage.removeItem('newProductSet');
            
            // Add to product sets if not already present
            if (productSets && !productSets.find(set => set.id == newSetData.set_id)) {
                productSets.push({
                    id: newSetData.set_id,
                    set_name: newSetData.set_name,
                    total_cost: newSetData.total_cost,
                    product_count: newSetData.product_count,
                    is_active: 1
                });
                
                updateProductSetDropdown();
                showNewSetNotification(newSetData);
            }
        } catch (error) {
            console.error('Error processing pending new product set:', error);
            localStorage.removeItem('newProductSet');
        }
    }
    
    // Listen for deleted product set notifications
    window.addEventListener('storage', function(e) {
        if (e.key === 'deletedProductSet' && e.newValue) {
            try {
                const deletionData = JSON.parse(e.newValue);
                console.log('Received product set deletion notification:', deletionData);
                
                // Remove from product sets array
                if (productSets) {
                    productSets = productSets.filter(set => set.id != deletionData.set_id);
                    updateProductSetDropdown();
                    showDeletionNotification(deletionData);
                }
                
                // If we're on a page that shows the deleted set, refresh the page
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } catch (error) {
                console.error('Error processing deletion notification:', error);
            }
        }
    });
    
    // Check for any pending deletion notifications on load
    const pendingDeletion = localStorage.getItem('deletedProductSet');
    if (pendingDeletion) {
        try {
            const deletionData = JSON.parse(pendingDeletion);
            console.log('Found pending deletion notification:', deletionData);
            
            // Clear the pending notification
            localStorage.removeItem('deletedProductSet');
            
            // Remove from product sets if present
            if (productSets) {
                productSets = productSets.filter(set => set.id != deletionData.set_id);
                updateProductSetDropdown();
                showDeletionNotification(deletionData);
            }
        } catch (error) {
            console.error('Error processing pending deletion notification:', error);
            localStorage.removeItem('deletedProductSet');
        }
    }
});

// Function to show notification about new product set
function showNewSetNotification(newSetData) {
    const notificationDiv = document.createElement('div');
    notificationDiv.className = 'alert alert-success alert-dismissible fade show';
    notificationDiv.innerHTML = `
        <i class="bi bi-check-circle me-2"></i>
        <strong>New Product Set Available!</strong> "${newSetData.set_name}" (${newSetData.product_count} items, Cost: $${newSetData.total_cost.toFixed(2)})
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    if (container) {
        container.insertBefore(notificationDiv, container.firstChild);
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (notificationDiv.parentNode) {
                notificationDiv.remove();
            }
        }, 5000);
    }
}

// Function to show notification about deleted product set
function showDeletionNotification(deletionData) {
    const notificationDiv = document.createElement('div');
    notificationDiv.className = 'alert alert-warning alert-dismissible fade show';
    notificationDiv.innerHTML = `
        <i class="bi bi-trash me-2"></i>
        <strong>Product Set Deleted!</strong> "${deletionData.set_name}" has been removed from the system.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid');
    if (container) {
        container.insertBefore(notificationDiv, container.firstChild);
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (notificationDiv.parentNode) {
                notificationDiv.remove();
            }
        }, 5000);
    }
}

// Function to toggle product set details
function toggleSetDetails(productId) {
    const detailsRow = document.getElementById(`set-details-${productId}`);
    const chevronIcon = document.getElementById(`chevron-${productId}`);
    
    if (detailsRow.style.display === 'none') {
        detailsRow.style.display = '';
        chevronIcon.className = 'bi bi-chevron-up';
    } else {
        detailsRow.style.display = 'none';
        chevronIcon.className = 'bi bi-chevron-down';
    }
}

// Function to show product set details modal
function showSetDetailsModal(productId, setName, setItems) {
    // Set modal title
    document.getElementById('setDetailsTitle').textContent = setName;
    
    // Populate modal content
    const contentDiv = document.getElementById('setDetailsContent');
    
    if (setItems && setItems.trim()) {
        const items = setItems.split('; ');
        let html = '<div class="row g-2">';
        
        items.forEach(item => {
            if (item.trim()) {
                html += `
                    <div class="col-md-6">
                        <div class="d-flex align-items-center gap-2 p-2 bg-white border rounded-3">
                            <i class="bi bi-dot text-primary fs-4"></i>
                            <strong class="text-dark">${item.trim()}</strong>
                        </div>
                    </div>
                `;
            }
        });
        
        html += '</div>';
        contentDiv.innerHTML = html;
    } else {
        contentDiv.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                <p class="mb-0">No product details available for this set.</p>
            </div>
        `;
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('setDetailsModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
