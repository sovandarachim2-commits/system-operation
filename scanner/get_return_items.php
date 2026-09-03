<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
require_once 'config.php';
header('Content-Type: application/json');

$prepareSetMap = [];
$prepareSetSql = "
    SELECT ps1.inv, ps1.`set`
    FROM prepare_set ps1
    INNER JOIN (
        SELECT inv, MAX(id) AS max_id
        FROM prepare_set
        GROUP BY inv
    ) ps2 ON ps1.inv = ps2.inv AND ps1.id = ps2.max_id
";
$prepareSetResult = $conn->query($prepareSetSql);
if ($prepareSetResult) {
    while ($setRow = $prepareSetResult->fetch_assoc()) {
        $prepareSetMap[$setRow['inv']] = $setRow['set'];
    }
}

$sql = "
    SELECT
        ri.*,
        ps_latest.`set` AS set_name,
        pe_latest.sub_name_json,
        COALESCE(pe_counts.prepare_item_count, 0) AS prepare_item_count,
        COALESCE(pe_counts.prepare_set_count, 0) AS prepare_set_count
    FROM return_items ri
    LEFT JOIN (
        SELECT
            pe1.inv,
            pe1.sub_name_json,
            COALESCE(NULLIF(pe1.set_qr, ''), pe1.inv) AS set_lookup_inv
        FROM product_entries pe1
        INNER JOIN (
            SELECT inv, MAX(id) AS max_id
            FROM product_entries
            GROUP BY inv
        ) pe2 ON pe1.inv = pe2.inv AND pe1.id = pe2.max_id
    ) pe_latest ON pe_latest.inv = ri.inv
    LEFT JOIN (
        SELECT ps1.inv, ps1.`set`
        FROM prepare_set ps1
        INNER JOIN (
            SELECT inv, MAX(id) AS max_id
            FROM prepare_set
            GROUP BY inv
        ) ps2 ON ps1.inv = ps2.inv AND ps1.id = ps2.max_id
    ) ps_latest ON ps_latest.inv = COALESCE(pe_latest.set_lookup_inv, ri.inv)
    LEFT JOIN (
        SELECT
            pe.inv,
            COUNT(*) AS prepare_item_count,
            SUM(
                CASE
                    WHEN COALESCE(pe.set_type, '') = 'set' OR COALESCE(pe.set_qr, '') <> '' THEN 1
                    ELSE 0
                END
            ) AS prepare_set_count
        FROM product_entries pe
        GROUP BY pe.inv
    ) pe_counts ON pe_counts.inv = ri.inv
    ORDER BY ri.id DESC
";

$result = $conn->query($sql);
$items = [];
while ($row = $result->fetch_assoc()) {
    $row['inv_photo'] = scanner_storage_resolve_public_url((string)($row['inv_photo'] ?? ''));
    $row['full_photo'] = scanner_storage_resolve_public_url((string)($row['full_photo'] ?? ''));
    $subInvList = [];
    if (!empty($row['sub_name_json'])) {
        $decoded = json_decode($row['sub_name_json'], true);
        if (is_array($decoded)) {
            $subInvList = $decoded;
        }
    }

    $row['sub_set_names'] = [];
    foreach ($subInvList as $subInv) {
        $subInvKey = (string)$subInv;
        if ($subInvKey !== '' && isset($prepareSetMap[$subInvKey])) {
            $row['sub_set_names'][] = $prepareSetMap[$subInvKey];
        }
    }

    $items[] = $row;
}
echo json_encode($items);
$conn->close();
?>
