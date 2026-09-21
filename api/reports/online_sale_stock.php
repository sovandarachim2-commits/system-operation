<?php
declare(strict_types=1);

// Matches Order Management stock semantics, including bundle component returns.
// Caller must hold the order lock and commit/roll back the entire order transaction.
function online_sale_apply_stock(PDO $pdo, int $order_id, array $user, array $existingItems, array $items): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Order stock changes require a transaction.');
    }
    $isPrinted = true;
    $canEditAnyOrder = true;
    $errors = [];
    // If order is printed and admin is editing, compute deltas.
    // We will validate stock for increases and restock for decreases.
    $deltas = [];
    $increaseNeeds = [];
    $decreaseNeeds = [];
    // For product sets (bundles)
    $setIncreaseNeeds = [];
    $setDecreaseNeeds = [];
    if ($isPrinted && $canEditAnyOrder) {
        // Build old qty map from existingItems
        $oldQty = [];
        foreach ($existingItems as $it) {
            $pid = (int)$it['product_id'];
            if (!isset($oldQty[$pid])) {
                $oldQty[$pid] = 0;
            }
            $oldQty[$pid] += (int)$it['quantity'];
        }
        // Build new qty map from merged items (same totals as POST after collapsing duplicate lines)
        $newQty = [];
        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $qty = (int)$it['quantity'];
            if (!isset($newQty[$pid])) {
                $newQty[$pid] = 0;
            }
            $newQty[$pid] += $qty;
        }
        // Compute deltas
        $allPids = array_unique(array_merge(array_keys($oldQty), array_keys($newQty)));
        foreach ($allPids as $pid) {
            $before = (int)($oldQty[$pid] ?? 0);
            $after  = (int)($newQty[$pid] ?? 0);
            $delta  = $after - $before;
            if ($delta !== 0) {
                $deltas[$pid] = $delta;
            }
        }
        // Prepare default location and product names for both increase and decrease processing
        // Get default storage location
        $locStmt = $pdo->prepare('SELECT id, location_name FROM storage_locations WHERE is_default = 1 LIMIT 1');
        $locStmt->execute();
        $defaultLocation = $locStmt->fetch(PDO::FETCH_ASSOC);
        if (!$defaultLocation) {
            $errors[] = 'No default storage location configured. Please set a default location in Storage Locations.';
        } else {
            // Build product id -> name map for inventory lookup
            $pidList = array_keys($deltas);
            if ($pidList) {
                $placeholders = implode(',', array_fill(0, count($pidList), '?'));
                $pstmt = $pdo->prepare("SELECT id, name FROM products WHERE id IN ($placeholders)");
                $pstmt->execute($pidList);
                $pnames = $pstmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => name
                // Also load product types for set detection
                $ptypeStmt = $pdo->prepare("SELECT id, COALESCE(product_type,'normal') AS pt FROM products WHERE id IN ($placeholders)");
                $ptypeStmt->execute($pidList);
                $ptypes = [];
                foreach ($ptypeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $ptypes[(int)$row['id']] = (string)$row['pt']; }
                // Preload product_sets info by set_name for any set products
                $setNames = [];
                foreach ($deltas as $pid => $_d) {
                    if (($ptypes[$pid] ?? 'normal') === 'set') {
                        if (isset($pnames[$pid])) { $setNames[] = $pnames[$pid]; }
                    }
                }
                $setInfoByName = [];
                if ($setNames) {
                    $placeSet = implode(',', array_fill(0, count($setNames), '?'));
                    $sstmt = $pdo->prepare("SELECT id, set_name, available_stock FROM product_sets WHERE set_name IN ($placeSet)");
                    $sstmt->execute($setNames);
                    foreach ($sstmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                        $setInfoByName[(string)$sr['set_name']] = [
                            'id' => (int)$sr['id'],
                            'available_stock' => (float)$sr['available_stock'],
                        ];
                    }
                }
                foreach ($deltas as $pid => $delta) {
                    $pname = $pnames[$pid] ?? null;
                    if ($pname === null) { $errors[] = 'Product not found for stock validation.'; break; }
                    $ptype = $ptypes[$pid] ?? 'normal';
                    if ($ptype === 'set') {
                        if ($delta > 0) {
                            $sinfo = $setInfoByName[$pname] ?? ['id' => 0, 'available_stock' => 0.0];
                            $setIncreaseNeeds[] = [
                                'product_id' => $pid,
                                'set_id' => (int)$sinfo['id'],
                                'set_name' => $pname,
                                'delta' => (int)$delta,
                                'available' => (float)$sinfo['available_stock'],
                            ];
                        } elseif ($delta < 0) {
                            $setDecreaseNeeds[] = [
                                'product_id' => $pid,
                                'set_name' => $pname,
                                'delta' => (int)abs($delta),
                            ];
                        }
                    } else {
                        if ($delta > 0) {
                            // Sum all rows at default location (duplicates); deduct FIFO when applying
                            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_on_hand), 0) FROM current_inventory WHERE item_name = ? AND storage_location_id = ?');
                            $sumStmt->execute([$pname, $defaultLocation['id']]);
                            $available = (float)$sumStmt->fetchColumn();
                            if ($available < $delta) {
                                $errors[] = "Insufficient stock for {$pname} at {$defaultLocation['location_name']}: need {$delta}, available {$available}.";
                                break;
                            }
                            $increaseNeeds[] = [
                                'product_id' => $pid,
                                'product_name' => $pname,
                                'delta' => (int)$delta,
                                'location_id' => (int)$defaultLocation['id'],
                                'location_name' => $defaultLocation['location_name']
                            ];
                        } elseif ($delta < 0) {
                            // Decrease (restock inbound) for normal products
                            $abs = (int)abs($delta);
                            $decreaseNeeds[] = [
                                'product_id' => $pid,
                                'product_name' => $pname,
                                'delta' => $abs,
                                'location_id' => (int)$defaultLocation['id'],
                                'location_name' => $defaultLocation['location_name']
                            ];
                        }
                    }
                }
            }
        }
    }
    if ($errors) { throw new DomainException(implode(' ', $errors)); }
    // If printed and admin with changes, apply stock movements now (before items replace to ensure atomicity)
    if ($isPrinted && $canEditAnyOrder && !$errors) {
        // Ensure default storage location for set operations
        $locStmt2 = $pdo->prepare('SELECT id, location_name FROM storage_locations WHERE is_default = 1 LIMIT 1');
        $locStmt2->execute();
        $defLoc = $locStmt2->fetch(PDO::FETCH_ASSOC);
        if (!$defLoc) { throw new DomainException('No default storage location configured.'); }

        // Handle product sets first
        if (!empty($setIncreaseNeeds)) {
            foreach ($setIncreaseNeeds as $need) {
                $delta = (int)$need['delta'];
                $setName = $need['set_name'];
                // Fetch current set row
                $sinfoStmt = $pdo->prepare('SELECT id, available_stock FROM product_sets WHERE ' . ($need['set_id'] ? 'id = ?' : 'set_name = ?') . ' LIMIT 1 FOR UPDATE');
                $sinfoStmt->execute([$need['set_id'] ?: $setName]);
                $sinfoCur = $sinfoStmt->fetch(PDO::FETCH_ASSOC);
                if (!$sinfoCur) { throw new DomainException("Product set '{$setName}' not found"); }
                $available = (float)($sinfoCur['available_stock'] ?? 0);
                $missing = 0;
                if ($available < $delta) {
                    $missing = $delta - $available;
                    // Auto-create only product set units by consuming required components.
                    $componentsStmt = $pdo->prepare('SELECT psi.quantity, p.name AS product_name FROM product_set_items psi JOIN products p ON psi.product_id = p.id WHERE psi.product_set_id = ?');
                    $componentsStmt->execute([(int)$sinfoCur['id']]);
                    $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($components)) { throw new DomainException("Cannot save/print '{$setName}': no components configured for this set."); }
                    // Validate component availability first; if not enough, block flow.
                    foreach ($components as $component) {
                        $reqQty = (float)$component['quantity'] * $missing;
                        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity_on_hand),0) FROM current_inventory WHERE item_name = ? AND storage_location_id = ?');
                        $sumStmt->execute([$component['product_name'], $defLoc['id']]);
                        $availComp = (float)$sumStmt->fetchColumn();
                        if ($availComp < $reqQty) {
                            throw new DomainException("Cannot save/print '{$setName}': component '{$component['product_name']}' is not enough (need {$reqQty}, have {$availComp}).");
                        }
                    }
                    // Consume components (FIFO)
                    foreach ($components as $component) {
                        $reqQty = (float)$component['quantity'] * $missing;
                        $fifoStmt = $pdo->prepare('SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 ORDER BY last_updated ASC, id ASC FOR UPDATE');
                        $fifoStmt->execute([$component['product_name'], $defLoc['id']]);
                        $rows = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
                        $remain = $reqQty;
                        foreach ($rows as $row) {
                            if ($remain <= 0) break;
                            $reduce = min($remain, (float)$row['quantity_on_hand']);
                            if ($reduce <= 0) continue;
                            $upd = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand - ?, last_updated = NOW() WHERE id = ?');
                            $upd->execute([$reduce, $row['id']]);
                            $logComp = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'set_auto_creation_component_out', ?, 'product_set', ?, ?, ?)");
                            $logComp->execute([$defLoc['id'], $reduce, (int)$sinfoCur['id'], "Auto-created set component usage for {$setName} - {$component['product_name']}", $user['id']]);
                            $remain -= $reduce;
                        }
                if ($remain > 0.00001) { throw new DomainException("Insufficient component inventory for {$setName}."); }
                    }
                    // Create missing set units only after successful component validation/consumption.
                    $incSet = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock + ?, total_created = total_created + ?, updated_at = NOW() WHERE id = ?');
                    $incSet->execute([$missing, $missing, (int)$sinfoCur['id']]);
                    $logSetCreate = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'set_auto_created', ?, 'product_set', ?, ?, ?)");
                    $logSetCreate->execute([$defLoc['id'], $missing, (int)$sinfoCur['id'], "Auto-created missing set stock during order edit for {$setName}", $user['id']]);
                    $available += $missing;
                }
                // Outbound set units
                $outSet = $pdo->prepare('UPDATE product_sets SET available_stock = available_stock - ?, updated_at = NOW() WHERE id = ? AND available_stock >= ?');
                $outSet->execute([$delta, (int)$sinfoCur['id'], $delta]);
                if ($outSet->rowCount() == 0) { throw new DomainException("Insufficient set stock for '{$setName}': Required {$delta}"); }
                $logSetOut = $pdo->prepare("INSERT INTO stock_operations (storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, 'set_outbound', ?, 'order', ?, ?, ?)");
                $logSetOut->execute([$defLoc['id'], $delta, $order_id, "Product set sold: {$setName}", $user['id']]);
            }
        }
        if (!empty($setDecreaseNeeds)) {
            foreach ($setDecreaseNeeds as $need) {
                $setName = $need['set_name'];
                $delta = (int)$need['delta'];
                $setStmt = $pdo->prepare('SELECT id FROM product_sets WHERE set_name = ? LIMIT 1');
                $setStmt->execute([$setName]);
                $setId = (int)($setStmt->fetchColumn() ?: 0);
                if ($setId <= 0) {
                    throw new DomainException("Product set '{$setName}' not found");
                }

                $componentsStmt = $pdo->prepare('
                    SELECT psi.product_id, psi.quantity, p.name AS product_name
                    FROM product_set_items psi
                    JOIN products p ON psi.product_id = p.id
                    WHERE psi.product_set_id = ?
                ');
                $componentsStmt->execute([$setId]);
                $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($components)) {
                    throw new DomainException("Cannot return components for '{$setName}': no components configured for this set.");
                }

                foreach ($components as $component) {
                    $returnQty = (float)$component['quantity'] * $delta;
                    if ($returnQty <= 0) {
                        continue;
                    }

                    $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? LIMIT 1');
                    $invStmt->execute([$component['product_name'], $defLoc['id']]);
                    $invId = $invStmt->fetchColumn();
                    if ($invId) {
                        $updInv = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() WHERE id = ?');
                        $updInv->execute([$returnQty, $invId]);
                    } else {
                        $insInv = $pdo->prepare('INSERT INTO current_inventory (item_name, storage_location_id, quantity_on_hand, last_updated) VALUES (?, ?, ?, NOW())');
                        $insInv->execute([$component['product_name'], $defLoc['id'], $returnQty]);
                    }

                    $logSetComponentIn = $pdo->prepare("INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, 'inbound', ?, 'order', ?, ?, ?)");
                    $logSetComponentIn->execute([
                        (int)$component['product_id'],
                        $defLoc['id'],
                        $returnQty,
                        $order_id,
                        "Order revised set component return: {$setName} - {$component['product_name']}",
                        $user['id']
                    ]);
                }
            }
        }
        // Outbound for increases (FIFO across duplicate inventory rows)
        if (!empty($increaseNeeds)) {
            foreach ($increaseNeeds as $need) {
                $remain = (float)$need['delta'];
                $fifoStmt = $pdo->prepare('SELECT id, quantity_on_hand FROM current_inventory WHERE item_name = ? AND storage_location_id = ? AND quantity_on_hand > 0 ORDER BY last_updated ASC, id ASC FOR UPDATE');
                $fifoStmt->execute([$need['product_name'], $need['location_id']]);
                $invRows = $fifoStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($invRows as $invRow) {
                    if ($remain <= 0) {
                        break;
                    }
                    $rowQty = (float)($invRow['quantity_on_hand'] ?? 0);
                    if ($rowQty <= 0) {
                        continue;
                    }
                    $reduce = min($remain, $rowQty);
                    $updInv = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand - ?, last_updated = NOW() WHERE id = ?');
                    $updInv->execute([$reduce, $invRow['id']]);
                    $remain -= $reduce;
                }
                if ($remain > 0.00001) {
                    throw new DomainException('Insufficient inventory for ' . $need['product_name'] . ' during apply (stock may have changed).');
                }
                $logStmt = $pdo->prepare('INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, \'outbound\', ?, \'order\', ?, ?, ?)');
                $logStmt->execute([
                    $need['product_id'],
                    $need['location_id'],
                    $need['delta'],
                    $order_id,
                    'Order revised - ' . $need['product_name'],
                    $user['id']
                ]);
            }
        }
        // Inbound for decreases (restock)
        if (!empty($decreaseNeeds)) {
            foreach ($decreaseNeeds as $need) {
                // Find or create inventory row for inbound
                $invStmt = $pdo->prepare('SELECT id FROM current_inventory WHERE item_name = ? AND storage_location_id = ? LIMIT 1');
                $invStmt->execute([$need['product_name'], $need['location_id']]);
                $invId = $invStmt->fetchColumn();
                if ($invId) {
                    $updInv = $pdo->prepare('UPDATE current_inventory SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() WHERE id = ?');
                    $updInv->execute([$need['delta'], $invId]);
                } else {
                    // Create new inventory row
                    $insInv = $pdo->prepare('INSERT INTO current_inventory (item_name, storage_location_id, quantity_on_hand, last_updated) VALUES (?, ?, ?, NOW())');
                    $insInv->execute([$need['product_name'], $need['location_id'], $need['delta']]);
                }
                // Log inbound
                $logStmt2 = $pdo->prepare('INSERT INTO stock_operations (product_id, storage_location_id, operation_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?, ?, \'inbound\', ?, \'order\', ?, ?, ?)');
                $logStmt2->execute([
                    $need['product_id'],
                    $need['location_id'],
                    $need['delta'],
                    $order_id,
                    'Order revised return - ' . $need['product_name'],
                    $user['id']
                ]);
            }
        }
    }

}
