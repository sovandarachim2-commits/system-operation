<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../api/inventory_common.php';
require_once __DIR__ . '/../includes/eod_eom_generate.php';

$pdo = get_db_connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ordersToRepair = [
    [
        'order_id' => 8,
        'order_code' => 'DO261017001',
        'reference_id' => 'DO261017001-A261027153850',
        'created_at' => '2026-10-27 15:38:50',
        'created_by' => 'Kimhorng',
        'user_id' => 3,
    ],
    [
        'order_id' => 9,
        'order_code' => 'DO261031001',
        'reference_id' => 'DO261031001-A261031160924',
        'created_at' => '2026-10-31 16:09:24',
        'created_by' => 'Kimhorng',
        'user_id' => 3,
    ],
];

$pdo->beginTransaction();
try {
    // 1. Fix date typo on OFF-202610200010 (0007-10-17 -> 2026-10-17)
    $fixDateStmt = $pdo->prepare("
        UPDATE offline_sale_orders
        SET sale_date = '2026-10-17'
        WHERE order_code = 'OFF-202610200010' AND sale_date = '0007-10-17'
    ");
    $fixDateStmt->execute();
    echo "Updated OFF-202610200010 sale_date rows: " . $fixDateStmt->rowCount() . PHP_EOL;

    // 2. Repair missing OUT stock movements for edited Dealer Orders DO261017001 & DO261031001
    foreach ($ordersToRepair as $spec) {
        $orderId = $spec['order_id'];
        $refId = $spec['reference_id'];

        $chk = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE reference_id = ? AND movement_type = 'out'");
        $chk->execute([$refId]);
        if ((int)$chk->fetchColumn() > 0) {
            echo "Already repaired {$spec['order_code']} ({$refId})" . PHP_EOL;
            continue;
        }

        $ordStmt = $pdo->prepare("SELECT * FROM dealer_orders WHERE id = ? AND order_code = ?");
        $ordStmt->execute([$orderId, $spec['order_code']]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo "Skipped {$spec['order_code']}: order not found" . PHP_EOL;
            continue;
        }

        $lineStmt = $pdo->prepare("SELECT * FROM dealer_order_items WHERE order_id = ? ORDER BY id ASC");
        $lineStmt->execute([$orderId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        $notes = trim("Dealer order confirmed: {$order['order_code']} - {$order['dealer_name']}");

        foreach ($lines as $line) {
            $itemId = (int)($line['movement_item_id'] ?: $line['product_id']);
            $locId = (int)($line['warehouse_id'] ?: $order['default_warehouse_id']);
            $qty = (float)$line['quantity'];

            $prodStmt = $pdo->prepare("SELECT id, name, sku, COALESCE(cost, 0) AS cost FROM products WHERE id = ?");
            $prodStmt->execute([$itemId]);
            $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);
            $prodName = $prod ? $prod['name'] : $line['product_name'];
            $sku = $prod['sku'] ?? null;

            $invStmt = $pdo->prepare("
                SELECT id, quantity_on_hand, unit_cost, sku
                FROM current_inventory
                WHERE storage_location_id = ? AND TRIM(item_name) = TRIM(?)
                ORDER BY id ASC
                LIMIT 1
            ");
            $invStmt->execute([$locId, $prodName]);
            $inv = $invStmt->fetch(PDO::FETCH_ASSOC);

            $prevQty = $inv ? (float)$inv['quantity_on_hand'] : 0.0;
            $newQty = max(0.0, $prevQty - $qty);
            $unitCost = (float)($inv['unit_cost'] ?? $prod['cost'] ?? 0);
            $moveSku = $inv['sku'] ?? $sku;

            if ($inv) {
                $updInv = $pdo->prepare("UPDATE current_inventory SET quantity_on_hand = ? WHERE id = ?");
                $updInv->execute([$newQty, (int)$inv['id']]);
            }

            $insSm = $pdo->prepare("
                INSERT INTO stock_movements
                (item_id, movement_type, quantity, previous_stock, new_stock, reference_type, reference_id, notes, unit_cost, total_cost, created_by, created_at, from_storage_location_id, to_storage_location_id)
                VALUES (?, 'out', ?, ?, ?, 'dealer_order', ?, ?, ?, ?, ?, ?, ?, NULL)
            ");
            $insSm->execute([
                $itemId,
                $qty,
                $prevQty,
                $newQty,
                $refId,
                $notes,
                $unitCost,
                $qty * $unitCost,
                $spec['created_by'],
                $spec['created_at'],
                $locId,
            ]);

            $insIm = $pdo->prepare("
                INSERT INTO inventory_movements
                (movement_type, item_name, sku, quantity, unit_cost, total_cost, from_location_id, to_location_id, reference_type, reference_id, reference_no, reason, user_id, movement_date, created_at)
                VALUES ('sale_out', ?, ?, ?, ?, ?, ?, NULL, 'sale', NULL, ?, ?, ?, ?, ?)
            ");
            $insIm->execute([
                $prodName,
                $moveSku,
                $qty,
                $unitCost,
                $qty * $unitCost,
                $locId,
                $refId,
                $notes,
                $spec['user_id'],
                substr($spec['created_at'], 0, 10),
                $spec['created_at'],
            ]);
        }

        $updOrd = $pdo->prepare("
            UPDATE dealer_orders
            SET stock_deducted = 1, stock_reversed = 0, stock_reference_id = ?, stock_documents = ?
            WHERE id = ?
        ");
        $updOrd->execute([$refId, json_encode([$refId]), $orderId]);
        echo "Repaired {$spec['order_code']} with reference {$refId}" . PHP_EOL;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

// 3. Regenerate draft EOD (2026-10-31) and EOM (2026-10) reports
$eodId = (int)$pdo->query("SELECT id FROM eod_stock_reports WHERE report_date = '2026-10-31' AND status = 'draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($eodId > 0) {
    $pdo->exec("DELETE FROM eod_stock_report_details WHERE eod_report_id = {$eodId}");
    $pdo->exec("DELETE FROM eod_stock_reports WHERE id = {$eodId}");
}
$eodRes = eod_eom_generate_eod($pdo, '2026-10-31', 1);
echo "Regenerated EOD 2026-10-31: report_id=" . ($eodRes['report_id'] ?? '') . PHP_EOL;

$eomId = (int)$pdo->query("SELECT id FROM eom_stock_reports WHERE report_month = '2026-10' AND status = 'draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($eomId > 0) {
    $pdo->exec("DELETE FROM eom_stock_report_details WHERE eom_report_id = {$eomId}");
    $pdo->exec("DELETE FROM eom_stock_reports WHERE id = {$eomId}");
}
$eomRes = eod_eom_generate_eom($pdo, '2026-10', '2026-10-31', 1);
echo "Regenerated EOM 2026-10: report_id=" . ($eomRes['report_id'] ?? '') . PHP_EOL;

