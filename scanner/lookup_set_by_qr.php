<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
require_once 'config.php';

function scanner_normalize_set_qr_code(string $raw): string
{
    $code = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
    $code = preg_replace('/[[:cntrl:]]+/', '', $code) ?? $code;

    if (preg_match('/(?:^|\b)(?:code|qr|data)\s*[:=]\s*([A-Za-z0-9_-]+)/i', $code, $matches)) {
        return trim($matches[1]);
    }

    $parts = parse_url($code);
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
        foreach (['data', 'qr', 'code', 'label_code'] as $key) {
            if (!empty($query[$key]) && is_scalar($query[$key])) {
                return trim((string)$query[$key]);
            }
        }
    }

    return $code;
}

$qrCode = scanner_normalize_set_qr_code((string)($_GET['qr'] ?? $_POST['qr'] ?? ''));
if ($qrCode === '') {
    echo json_encode([
        'success' => false,
        'message' => 'No QR code provided.',
    ]);
    $conn->close();
    exit;
}

$stmt = $conn->prepare('
    SELECT product_set_id, set_name, label_code
    FROM product_set_qr_label_print_history
    WHERE label_code = ? OR UPPER(label_code) = UPPER(?)
    ORDER BY printed_at DESC, id DESC
    LIMIT 1
');
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error.',
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param('ss', $qrCode, $qrCode);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode([
        'success' => false,
        'message' => 'This QR code is not in label print history.',
    ]);
    $conn->close();
    exit;
}

echo json_encode([
    'success' => true,
    'set_id' => (int)($row['product_set_id'] ?? 0),
    'set_name' => (string)($row['set_name'] ?? ''),
    'label_code' => (string)($row['label_code'] ?? ''),
]);

$conn->close();
?>
