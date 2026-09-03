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
        pe.*,
        ps_latest.`set` AS set_name
    FROM product_entries pe
    LEFT JOIN (
        SELECT ps1.inv, ps1.`set`
        FROM prepare_set ps1
        INNER JOIN (
            SELECT inv, MAX(id) AS max_id
            FROM prepare_set
            GROUP BY inv
        ) ps2 ON ps1.inv = ps2.inv AND ps1.id = ps2.max_id
    ) ps_latest ON ps_latest.inv = COALESCE(NULLIF(pe.set_qr, ''), pe.inv)
    ORDER BY pe.id DESC
";

$result = $conn->query($sql);

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['inv_photo'] = scanner_storage_resolve_public_url((string)($row['inv_photo'] ?? ''));
    $row['full_photo'] = scanner_storage_resolve_public_url((string)($row['full_photo'] ?? ''));
    if (!empty($row['sub_name_json'])) {
        $row['sub_names'] = json_decode($row['sub_name_json'], true);
        if (!is_array($row['sub_names'])) $row['sub_names'] = [];
    } else {
        $row['sub_names'] = [];
    }

    $row['sub_set_names'] = [];
    foreach ($row['sub_names'] as $subInv) {
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
