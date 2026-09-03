<?php

function stock_print_get_default_location($pdo) {
    $stmt = $pdo->prepare("SELECT id, location_code, location_name FROM storage_locations WHERE is_default = 1 LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function stock_print_fetch_order_items($pdo, $orderIds) {
    if (empty($orderIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            oi.order_id AS reference_id,
            oi.product_id,
            oi.quantity,
            p.name AS product_name,
            COALESCE(p.product_type, 'normal') AS product_type,
            CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS effective_product_type,
            COALESCE(ps.id, 0) AS product_set_id
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'normal') = 'set'
        WHERE oi.order_id IN ($placeholders)
    ");
    $stmt->execute($orderIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function stock_print_fetch_receipt_items($pdo, $receiptOrderId) {
    $stmt = $pdo->prepare("
        SELECT
            ? AS reference_id,
            roi.product_id,
            SUM(roi.quantity) AS quantity,
            p.name AS product_name,
            COALESCE(p.product_type, 'normal') AS product_type,
            CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS effective_product_type,
            COALESCE(ps.id, 0) AS product_set_id
        FROM receipt_order_items roi
        JOIN products p ON roi.product_id = p.id
        LEFT JOIN product_sets ps ON p.name = ps.set_name AND COALESCE(p.product_type, 'normal') = 'set'
        WHERE roi.receipt_order_id = ?
        GROUP BY roi.product_id, p.name, p.product_type, ps.id
        ORDER BY p.name
    ");
    $stmt->execute([$receiptOrderId, $receiptOrderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function stock_print_sum_inventory($pdo, $itemName, $locationId) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_on_hand), 0)
        FROM current_inventory
        WHERE item_name = ? AND storage_location_id = ?
    ");
    $stmt->execute([$itemName, $locationId]);
    return (float)$stmt->fetchColumn();
}

function stock_print_fetch_inventory_rows($pdo, $itemName, $locationId) {
    $stmt = $pdo->prepare("
        SELECT id, quantity_on_hand
        FROM current_inventory
        WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0
        ORDER BY last_updated ASC, id ASC
    ");
    $stmt->execute([$itemName, $locationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function stock_print_log_operation($pdo, $payload) {
    $productId = $payload['product_id'] ?? null;
    $productName = (string)($payload['product_name'] ?? '');
    $locationId = (int)$payload['storage_location_id'];
    $operationType = (string)$payload['operation_type'];
    $quantity = (float)$payload['quantity'];
    $referenceType = (string)$payload['reference_type'];
    $referenceId = $payload['reference_id'] ?? null;
    $notes = (string)($payload['notes'] ?? '');
    $userId = $payload['user_id'] ?? null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_operations
            (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $productId,
            $locationId,
            $operationType,
            $quantity,
            $referenceType,
            $referenceId,
            $notes,
            $userId
        ]);
    } catch (PDOException $primaryEx) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO stock_operations
                (product_name, operation_type, quantity, storage_location_id, reference_type, reference_id, notes, performed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $productName,
                $operationType,
                $quantity,
                $locationId,
                $referenceType,
                $referenceId,
                $notes,
                $userId
            ]);
        } catch (PDOException $fallbackEx) {
            error_log("Stock operation log failed: " . $fallbackEx->getMessage());
        }
    }
}

function stock_print_ensure_product_set_qr_label_history($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_set_qr_label_print_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_set_id INT NOT NULL,
            set_name VARCHAR(255) NOT NULL,
            set_sku VARCHAR(100) NOT NULL,
            label_code VARCHAR(150) NOT NULL,
            batch_code VARCHAR(50) NOT NULL,
            printed_by INT NULL,
            printed_by_name VARCHAR(255) NULL,
            printed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_label_code (label_code),
            INDEX idx_product_set_id (product_set_id),
            INDEX idx_printed_at (printed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $hasPrintStatus = (bool)$pdo->query("SHOW COLUMNS FROM product_set_qr_label_print_history LIKE 'print_status'")->fetchColumn();
    if (!$hasPrintStatus) {
        $pdo->exec("ALTER TABLE product_set_qr_label_print_history ADD COLUMN print_status VARCHAR(20) NOT NULL DEFAULT 'printed' AFTER printed_by_name");
        $pdo->exec("ALTER TABLE product_set_qr_label_print_history ADD INDEX idx_print_status (print_status)");
        $pdo->exec("
            UPDATE product_set_qr_label_print_history h
            SET h.print_status = 'pending'
            WHERE (
                SELECT pal.action_type
                FROM product_set_audit_log pal
                WHERE pal.product_set_id = h.product_set_id
                  AND pal.action_type IN ('stock_added', 'auto_created', 'created')
                  AND pal.created_at <= h.printed_at
                ORDER BY pal.created_at DESC
                LIMIT 1
            ) = 'auto_created'
        ");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_set_qr_code_settings (
            product_set_id INT PRIMARY KEY,
            code_prefix VARCHAR(80) NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_code_prefix (code_prefix)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function stock_print_normalize_set_qr_prefix($prefix) {
    $prefix = strtoupper(trim((string)$prefix));
    return rtrim($prefix, '-');
}

function stock_print_label_sequence_from_code($labelCode, $normalizedPrefix, $batchCode) {
    $code = strtoupper(trim((string)$labelCode));
    $normalizedPrefix = strtoupper((string)$normalizedPrefix);
    $batchCode = strtoupper((string)$batchCode);
    $legacyPrefix = $normalizedPrefix . '-';

    if (strpos($code, $normalizedPrefix . $batchCode) === 0) {
        $suffix = substr($code, strlen($normalizedPrefix . $batchCode));
    } elseif (strpos($code, $legacyPrefix . $batchCode) === 0) {
        $suffix = substr($code, strlen($legacyPrefix . $batchCode));
    } else {
        return 0;
    }

    return ctype_digit($suffix) ? (int)$suffix : 0;
}

function stock_print_record_auto_created_set_qr_labels($pdo, $productSetId, $quantity, $user) {
    $quantity = (int)ceil((float)$quantity);
    $productSetId = (int)$productSetId;
    if ($productSetId <= 0 || $quantity <= 0) {
        return;
    }

    $setStmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.set_name,
            COALESCE(NULLIF(qcs.code_prefix, ''), CONCAT('SET-', ps.id)) AS set_sku
        FROM product_sets ps
        LEFT JOIN product_set_qr_code_settings qcs ON qcs.product_set_id = ps.id
        WHERE ps.id = ?
        LIMIT 1
    ");
    $setStmt->execute([$productSetId]);
    $set = $setStmt->fetch(PDO::FETCH_ASSOC);
    if (!$set) {
        return;
    }

    $batchCode = date('Y');
    $setSku = stock_print_normalize_set_qr_prefix($set['set_sku']);
    $prefix = $setSku . $batchCode;
    $legacyPrefix = $setSku . '-' . $batchCode;

    $existingStmt = $pdo->prepare("
        SELECT label_code
        FROM product_set_qr_label_print_history
        WHERE product_set_id = ?
          AND batch_code = ?
          AND (label_code LIKE ? OR label_code LIKE ?)
    ");
    $existingStmt->execute([$productSetId, $batchCode, $prefix . '%', $legacyPrefix . '%']);
    $usedCodes = [];
    $maxSequence = 0;
    foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $usedCodes[(string)$code] = true;
        $maxSequence = max($maxSequence, stock_print_label_sequence_from_code($code, $setSku, $batchCode));
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO product_set_qr_label_print_history
        (product_set_id, set_name, set_sku, label_code, batch_code, printed_by, printed_by_name, print_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $created = 0;
    $sequence = $maxSequence + 1;
    while ($created < $quantity) {
        $labelCode = $prefix . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
        $sequence++;
        if (isset($usedCodes[$labelCode])) {
            continue;
        }

        $insertStmt->execute([
            (int)$set['id'],
            (string)$set['set_name'],
            $setSku,
            $labelCode,
            $batchCode,
            $user['id'] ?? null,
            $user['name'] ?? ($user['username'] ?? null),
            'pending',
        ]);
        $usedCodes[$labelCode] = true;
        $created++;
    }
}

function stock_print_check_items($pdo, $items, $defaultLocation) {
    $insufficientItems = [];
    foreach ($items as $item) {
        $requiredQty = (float)($item['quantity'] ?? 0);
        if ($requiredQty <= 0) {
            continue;
        }

        if (($item['effective_product_type'] ?? 'normal') === 'set') {
            $setStmt = $pdo->prepare("SELECT available_stock FROM product_sets WHERE id = ? LIMIT 1");
            $setStmt->execute([(int)$item['product_set_id']]);
            $availableSetStock = (float)($setStmt->fetchColumn() ?: 0);
            if ($availableSetStock >= $requiredQty) {
                continue;
            }

            $missingQty = $requiredQty - $availableSetStock;
            $componentsStmt = $pdo->prepare("
                SELECT psi.quantity, p.name AS product_name
                FROM product_set_items psi
                JOIN products p ON psi.product_id = p.id
                WHERE psi.product_set_id = ?
            ");
            $componentsStmt->execute([(int)$item['product_set_id']]);
            $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($components)) {
                $insufficientItems[] = [
                    'product_name' => (string)$item['product_name'],
                    'required' => $requiredQty,
                    'available' => $availableSetStock,
                    'shortage' => max(0, $requiredQty - $availableSetStock),
                    'component_details' => [],
                ];
                continue;
            }

            $componentBlocked = false;
            $componentDetails = [];
            foreach ($components as $component) {
                $requiredComponentQty = (float)$component['quantity'] * $missingQty;
                $availableComponentQty = stock_print_sum_inventory($pdo, $component['product_name'], $defaultLocation['id']);
                $componentShortage = max(0, $requiredComponentQty - $availableComponentQty);
                $componentDetails[] = [
                    'product_name' => (string)$component['product_name'],
                    'required' => $requiredComponentQty,
                    'available' => $availableComponentQty,
                    'shortage' => $componentShortage,
                ];
                if ($availableComponentQty < $requiredComponentQty) {
                    $insufficientItems[] = [
                        'product_name' => (string)$item['product_name'],
                        'required' => $requiredQty,
                        'available' => $availableSetStock,
                        'shortage' => max(0, $requiredQty - $availableSetStock),
                        'component_details' => $componentDetails,
                    ];
                    $componentBlocked = true;
                    break;
                }
            }

            if ($componentBlocked) {
                continue;
            }
            continue;
        }

        $availableQty = stock_print_sum_inventory($pdo, $item['product_name'], $defaultLocation['id']);
        if ($availableQty < $requiredQty) {
            $insufficientItems[] = [
                'product_name' => (string)$item['product_name'],
                'required' => $requiredQty,
                'available' => $availableQty,
                'shortage' => max(0, $requiredQty - $availableQty),
                'component_details' => [],
            ];
        }
    }

    return [
        'can_print' => empty($insufficientItems),
        'insufficient_items' => $insufficientItems,
    ];
}

function stock_print_reduce_items_strict($pdo, $items, $defaultLocation, $user, $referenceType) {
    $errors = [];
    $userId = $user['id'] ?? null;
    $userName = $user['name'] ?? 'Unknown User';
    $prefix = $referenceType === 'receipt' ? 'Receipt printed' : 'Order printed';

    try {
        stock_print_ensure_product_set_qr_label_history($pdo);
        $pdo->beginTransaction();
        foreach ($items as $item) {
            $requiredQty = (float)($item['quantity'] ?? 0);
            if ($requiredQty <= 0) {
                continue;
            }

            if (($item['effective_product_type'] ?? 'normal') === 'set') {
                $setInfoStmt = $pdo->prepare("SELECT id, available_stock FROM product_sets WHERE id = ? LIMIT 1");
                $setInfoStmt->execute([(int)$item['product_set_id']]);
                $setInfo = $setInfoStmt->fetch(PDO::FETCH_ASSOC);
                if (!$setInfo) {
                    $errors[] = "Product set '{$item['product_name']}' not found.";
                    continue;
                }

                $availableSetStock = (float)($setInfo['available_stock'] ?? 0);
                if ($availableSetStock < $requiredQty) {
                    $missingQty = $requiredQty - $availableSetStock;
                    $componentsStmt = $pdo->prepare("
                        SELECT psi.quantity, p.name AS product_name
                        FROM product_set_items psi
                        JOIN products p ON psi.product_id = p.id
                        WHERE psi.product_set_id = ?
                    ");
                    $componentsStmt->execute([(int)$item['product_set_id']]);
                    $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($components)) {
                        $errors[] = "Cannot print '{$item['product_name']}': no components configured for this set.";
                        continue;
                    }

                    $componentRequirements = [];
                    $canAutoCreate = true;
                    foreach ($components as $component) {
                        $requiredComponentQty = (float)$component['quantity'] * $missingQty;
                        $rows = stock_print_fetch_inventory_rows($pdo, $component['product_name'], $defaultLocation['id']);
                        $availableComponentQty = 0;
                        foreach ($rows as $row) {
                            $availableComponentQty += (float)($row['quantity_on_hand'] ?? 0);
                        }
                        if ($availableComponentQty < $requiredComponentQty) {
                            $errors[] = "Cannot print '{$item['product_name']}': component '{$component['product_name']}' is not enough (need {$requiredComponentQty}, have {$availableComponentQty}).";
                            $canAutoCreate = false;
                            break;
                        }
                        $componentRequirements[] = [
                            'product_name' => (string)$component['product_name'],
                            'required_quantity' => $requiredComponentQty,
                            'rows' => $rows,
                        ];
                    }

                    if (!$canAutoCreate) {
                        continue;
                    }

                    foreach ($componentRequirements as $componentRequirement) {
                        $remainingToReduce = (float)$componentRequirement['required_quantity'];
                        foreach ($componentRequirement['rows'] as $row) {
                            if ($remainingToReduce <= 0) {
                                break;
                            }
                            $rowQty = (float)($row['quantity_on_hand'] ?? 0);
                            if ($rowQty <= 0) {
                                continue;
                            }
                            $reduceAmount = min($remainingToReduce, $rowQty);
                            $upd = $pdo->prepare("
                                UPDATE current_inventory
                                SET quantity_on_hand = quantity_on_hand - ?, last_updated = NOW()
                                WHERE id = ?
                            ");
                            $upd->execute([$reduceAmount, $row['id']]);

                            stock_print_log_operation($pdo, [
                                'storage_location_id' => (int)$defaultLocation['id'],
                                'operation_type' => 'set_auto_creation_component_out',
                                'quantity' => $reduceAmount,
                                'reference_type' => 'product_set',
                                'reference_id' => (int)$item['product_set_id'],
                                'notes' => "Auto-created set component usage for {$item['product_name']} - {$componentRequirement['product_name']}",
                                'user_id' => $userId,
                                'product_name' => $componentRequirement['product_name'],
                            ]);

                            $remainingToReduce -= $reduceAmount;
                        }
                    }

                    $createSetStmt = $pdo->prepare("
                        UPDATE product_sets
                        SET available_stock = available_stock + ?,
                            total_created = total_created + ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $createSetStmt->execute([$missingQty, $missingQty, (int)$item['product_set_id']]);

                    stock_print_log_operation($pdo, [
                        'storage_location_id' => (int)$defaultLocation['id'],
                        'operation_type' => 'set_auto_created',
                        'quantity' => $missingQty,
                        'reference_type' => 'product_set',
                        'reference_id' => (int)$item['product_set_id'],
                        'notes' => "Auto-created missing set stock during print for {$item['product_name']}",
                        'user_id' => $userId,
                        'product_name' => (string)$item['product_name'],
                    ]);

                    $auditLogStmt = $pdo->prepare("
                        INSERT INTO product_set_audit_log
                        (product_set_id, action_type, user_id, user_name, action_details)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $auditLogStmt->execute([
                        (int)$item['product_set_id'],
                        'auto_created',
                        $userId,
                        $userName,
                        "Auto-created {$missingQty} sets during print for {$item['product_name']} (storage_location_id:{$defaultLocation['id']})"
                    ]);

                    stock_print_record_auto_created_set_qr_labels($pdo, (int)$item['product_set_id'], $missingQty, $user);
                }

                $reduceSetStmt = $pdo->prepare("
                    UPDATE product_sets
                    SET available_stock = available_stock - ?, updated_at = NOW()
                    WHERE id = ? AND available_stock >= ?
                ");
                $reduceSetStmt->execute([$requiredQty, (int)$item['product_set_id'], $requiredQty]);
                if ($reduceSetStmt->rowCount() === 0) {
                    $errors[] = "Insufficient stock for product set '{$item['product_name']}': Required {$requiredQty}";
                    continue;
                }

                stock_print_log_operation($pdo, [
                    'product_id' => (int)$item['product_id'],
                    'product_name' => (string)$item['product_name'],
                    'storage_location_id' => (int)$defaultLocation['id'],
                    'operation_type' => 'set_outbound',
                    'quantity' => $requiredQty,
                    'reference_type' => $referenceType,
                    'reference_id' => (int)$item['reference_id'],
                    'notes' => "{$prefix} - Product set sold: {$item['product_name']}",
                    'user_id' => $userId,
                ]);
                continue;
            }

            $inventoryRows = stock_print_fetch_inventory_rows($pdo, $item['product_name'], $defaultLocation['id']);
            if (empty($inventoryRows)) {
                $errors[] = "Product '{$item['product_name']}' not found in default location ({$defaultLocation['location_name']}).";
                continue;
            }

            $availableQty = 0;
            foreach ($inventoryRows as $row) {
                $availableQty += (float)($row['quantity_on_hand'] ?? 0);
            }
            if ($availableQty < $requiredQty) {
                $errors[] = "Insufficient stock for {$item['product_name']} in default location ({$defaultLocation['location_name']}): Required {$requiredQty}, Available {$availableQty}";
                continue;
            }

            $remainingToReduce = $requiredQty;
            foreach ($inventoryRows as $row) {
                if ($remainingToReduce <= 0) {
                    break;
                }
                $rowQty = (float)($row['quantity_on_hand'] ?? 0);
                if ($rowQty <= 0) {
                    continue;
                }

                $reduceAmount = min($remainingToReduce, $rowQty);
                $upd = $pdo->prepare("
                    UPDATE current_inventory
                    SET quantity_on_hand = quantity_on_hand - ?, last_updated = NOW()
                    WHERE id = ?
                ");
                $upd->execute([$reduceAmount, $row['id']]);

                stock_print_log_operation($pdo, [
                    'product_id' => (int)$item['product_id'],
                    'product_name' => (string)$item['product_name'],
                    'storage_location_id' => (int)$defaultLocation['id'],
                    'operation_type' => 'outbound',
                    'quantity' => $reduceAmount,
                    'reference_type' => $referenceType,
                    'reference_id' => (int)$item['reference_id'],
                    'notes' => "{$prefix} - {$item['product_name']}",
                    'user_id' => $userId,
                ]);

                $remainingToReduce -= $reduceAmount;
            }
        }

        if (!empty($errors)) {
            $pdo->rollBack();
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $pdo->commit();
        return [
            'success' => true,
            'errors' => [],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'errors' => [$e->getMessage()],
        ];
    }
}

function stock_print_check_orders($pdo, $orderIds) {
    $defaultLocation = stock_print_get_default_location($pdo);
    if (!$defaultLocation) {
        return [
            'can_print' => false,
            'error' => 'No default storage location configured. Please set a default location in Storage Locations.',
            'insufficient_items' => [],
            'location' => null,
        ];
    }

    $items = stock_print_fetch_order_items($pdo, $orderIds);
    $check = stock_print_check_items($pdo, $items, $defaultLocation);
    if (!$check['can_print']) {
        $errorMsg = "Insufficient stock in default location ({$defaultLocation['location_name']}):\n\n";
        foreach ($check['insufficient_items'] as $item) {
            $errorMsg .= "- {$item['product_name']}: Required {$item['required']}, Available {$item['available']}\n";
        }
        $errorMsg .= "\nPlease ensure sufficient stock before printing orders.";
        return [
            'can_print' => false,
            'error' => $errorMsg,
            'insufficient_items' => $check['insufficient_items'],
            'location' => $defaultLocation,
        ];
    }

    return [
        'can_print' => true,
        'location' => $defaultLocation,
        'insufficient_items' => [],
    ];
}

function stock_print_reduce_orders($pdo, $orderIds, $user) {
    $defaultLocation = stock_print_get_default_location($pdo);
    if (!$defaultLocation) {
        return [
            'success' => false,
            'errors' => ['No default storage location configured for stock reduction.'],
        ];
    }
    $items = stock_print_fetch_order_items($pdo, $orderIds);
    return stock_print_reduce_items_strict($pdo, $items, $defaultLocation, $user, 'order');
}

function stock_print_check_receipt($pdo, $receiptOrderId) {
    $defaultLocation = stock_print_get_default_location($pdo);
    if (!$defaultLocation) {
        return [
            'can_print' => false,
            'error' => 'No default storage location configured. Please set a default storage location in Storage Locations management.',
            'insufficient_items' => [],
            'location' => null,
        ];
    }

    $items = stock_print_fetch_receipt_items($pdo, $receiptOrderId);
    if (empty($items)) {
        return [
            'can_print' => false,
            'error' => 'No items found in this receipt order.',
            'insufficient_items' => [],
            'location' => $defaultLocation,
        ];
    }

    $check = stock_print_check_items($pdo, $items, $defaultLocation);
    if (!$check['can_print']) {
        $errorMessage = "Cannot print receipt - insufficient stock in default location '{$defaultLocation['location_name']}' ({$defaultLocation['location_code']}):\n";
        foreach ($check['insufficient_items'] as $item) {
            $errorMessage .= "\n- {$item['product_name']}: Required {$item['required']}, Available {$item['available']}";
        }
        $errorMessage .= "\n\nPlease restock items or select a different storage location.";

        return [
            'can_print' => false,
            'error' => $errorMessage,
            'insufficient_items' => $check['insufficient_items'],
            'location' => $defaultLocation,
        ];
    }

    return [
        'can_print' => true,
        'default_location' => $defaultLocation,
        'items_checked' => count($items),
        'insufficient_items' => [],
    ];
}

function stock_print_reduce_receipt($pdo, $receiptOrderId, $user) {
    $defaultLocation = stock_print_get_default_location($pdo);
    if (!$defaultLocation) {
        return [
            'success' => false,
            'errors' => ['No default storage location found for stock reduction.'],
        ];
    }

    $items = stock_print_fetch_receipt_items($pdo, $receiptOrderId);
    if (empty($items)) {
        return [
            'success' => false,
            'errors' => ['No items found in this receipt order.'],
        ];
    }

    return stock_print_reduce_items_strict($pdo, $items, $defaultLocation, $user, 'receipt');
}
