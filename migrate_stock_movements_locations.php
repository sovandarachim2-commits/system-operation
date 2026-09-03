<?php
/**
 * Run once: add from_storage_location_id / to_storage_location_id to stock_movements
 * and backfill from notes patterns [Location:N] and [From:X To:Y] (with optional spaces).
 *
 * Usage: php migrate_stock_movements_locations.php
 * Or open in browser once (then delete or protect this file).
 */
require_once __DIR__ . '/db.php';

function parseTransferLocations(string $notes): ?array {
    if (preg_match('/\[From:\s*(\d+)\s+To:\s*(\d+)\]/', $notes, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    if (preg_match('/\[From:(\d+) To:(\d+)\]/', $notes, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return null;
}

function parseSingleLocation(string $notes): ?int {
    if (preg_match('/\[Location:\s*(\d+)\]/', $notes, $m)) {
        return (int)$m[1];
    }
    return null;
}

try {
    $pdo = get_db_connection();
    $cols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN);
    $colsLc = array_map('strtolower', array_map('strval', $cols));

    if (!in_array('from_storage_location_id', $colsLc, true)) {
        $pdo->exec('ALTER TABLE stock_movements ADD COLUMN from_storage_location_id INT UNSIGNED NULL DEFAULT NULL AFTER notes');
        echo "Added column from_storage_location_id.\n";
    } else {
        echo "Column from_storage_location_id already exists.\n";
    }

    if (!in_array('to_storage_location_id', $colsLc, true)) {
        $pdo->exec('ALTER TABLE stock_movements ADD COLUMN to_storage_location_id INT UNSIGNED NULL DEFAULT NULL AFTER from_storage_location_id');
        echo "Added column to_storage_location_id.\n";
    } else {
        echo "Column to_storage_location_id already exists.\n";
    }

    $stmt = $pdo->query('SELECT id, movement_type, notes FROM stock_movements');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare('UPDATE stock_movements SET from_storage_location_id = ?, to_storage_location_id = ? WHERE id = ?');
    $backfilled = 0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $mt = strtolower(trim((string)($row['movement_type'] ?? '')));
        $notes = (string)($row['notes'] ?? '');
        $from = null;
        $to = null;

        if ($mt === 'transfer') {
            $pair = parseTransferLocations($notes);
            if ($pair !== null) {
                [$from, $to] = $pair;
            }
        } else {
            $loc = parseSingleLocation($notes);
            if ($loc !== null && $loc > 0) {
                if ($mt === 'in') {
                    $to = $loc;
                } elseif (in_array($mt, ['out', 'adjustment'], true)) {
                    $from = $loc;
                } else {
                    $from = $loc;
                }
            }
        }

        if ($from !== null || $to !== null) {
            $upd->execute([$from, $to, $id]);
            $backfilled++;
        }
    }

    echo "Backfill updated {$backfilled} row(s) where notes contained location markers.\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
