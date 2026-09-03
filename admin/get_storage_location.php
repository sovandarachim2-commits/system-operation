<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'storage_locations.view');

header('Content-Type: application/json; charset=utf-8');
$pdo = get_db_connection();

try {
    $exists = (bool)$pdo->query("SHOW COLUMNS FROM storage_locations LIKE 'is_offline_location'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE storage_locations ADD COLUMN is_offline_location TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default");
    }
} catch (Throwable $e) {
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid location ID']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM storage_locations WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$location = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$location) {
    echo json_encode(['success' => false, 'message' => 'Location not found']);
    exit;
}

echo json_encode(['success' => true, 'location' => $location], JSON_UNESCAPED_SLASHES);
