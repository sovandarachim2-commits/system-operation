<?php
ini_set('display_errors', 1); // Show errors in development
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.create');
require_once __DIR__ . '/../user_activity_lib.php';
require_once 'config.php';

// Get POST data directly (no escaping needed for prepared statements)
$inv       = $_POST["inv"] ?? "";
$phone     = $_POST["phone"] ?? "";
$status    = $_POST["status"] ?? "";
$amount    = floatval($_POST["amount"] ?? 0);
$set_type  = trim($_POST["set_type"] ?? "");
$set_qr    = $_POST["set_qr"] ?? "";
$sub_names = $_POST["sub_names"] ?? ""; // A JSON string
$user      = $_POST["user"] ?? "default_user";
$date_time = $_POST["date_time"] ?? date("Y-m-d H:i:s");

// --- Block cancelled offline sale orders ---
$invTrimCheck = trim($inv);
if ($invTrimCheck !== '') {
    $offCancelStmt = $conn->prepare("SELECT status FROM offline_sale_orders WHERE order_code = ? LIMIT 1");
    if ($offCancelStmt) {
        $offCancelStmt->bind_param("s", $invTrimCheck);
        $offCancelStmt->execute();
        $offCancelRow = $offCancelStmt->get_result()->fetch_assoc();
        $offCancelStmt->close();
        if ($offCancelRow && in_array(strtolower((string)($offCancelRow['status'] ?? '')), ['cancelled', 'canceled'])) {
            echo json_encode(["result" => "error", "message" => "Order is CANCELLED. Cannot save prepare items for this invoice."]);
            $conn->close();
            exit;
        }
    }
}

// --- Non-Set not allowed when order has lucky box line(s) ---
if ($set_type === 'non-set') {
    $invTrimNs = trim($inv);
    if ($invTrimNs !== '') {
        $ordNs = $conn->prepare("SELECT id FROM orders WHERE order_code = ? LIMIT 1");
        if ($ordNs) {
            $ordNs->bind_param("s", $invTrimNs);
            $ordNs->execute();
            $ordRowNs = $ordNs->get_result()->fetch_assoc();
            $ordNs->close();
            if ($ordRowNs) {
                $oidNs = (int)$ordRowNs['id'];
                $luckyNs = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND COALESCE(is_lucky_box, 0) = 1");
                if ($luckyNs) {
                    $luckyNs->bind_param("i", $oidNs);
                    $luckyNs->execute();
                    $rowNs = $luckyNs->get_result()->fetch_row();
                    $luckyNs->close();
                    $luckyQtyNs = (int)($rowNs[0] ?? 0);
                    if ($luckyQtyNs >= 1) {
                        echo json_encode([
                            "result" => "error",
                            "message" => "This order has lucky box (qty " . $luckyQtyNs . "). Non-Set is not allowed.",
                        ]);
                        $conn->close();
                        exit;
                    }
                }
            }
        }
    }
}

// --- Set type: must match order lucky box qty + printed label codes ---
if ($set_type === 'set') {
    $invTrim = trim($inv);
    if ($invTrim === '') {
        echo json_encode(["result" => "error", "message" => "Invoice / order code required for Set type."]);
        $conn->close();
        exit;
    }
    $ordStmt = $conn->prepare("SELECT id FROM orders WHERE order_code = ? LIMIT 1");
    if (!$ordStmt) {
        echo json_encode(["result" => "error", "message" => "Database error (order)."]);
        $conn->close();
        exit;
    }
    $ordStmt->bind_param("s", $invTrim);
    $ordStmt->execute();
    $ordRow = $ordStmt->get_result()->fetch_assoc();
    $ordStmt->close();
    if (!$ordRow) {
        echo json_encode(["result" => "error", "message" => "Order not found for this invoice code."]);
        $conn->close();
        exit;
    }
    $orderId = (int)$ordRow['id'];

    $luckySumStmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND COALESCE(is_lucky_box, 0) = 1");
    if (!$luckySumStmt) {
        echo json_encode(["result" => "error", "message" => "Database error (lucky sum)."]);
        $conn->close();
        exit;
    }
    $luckySumStmt->bind_param("i", $orderId);
    $luckySumStmt->execute();
    $luckySumRow = $luckySumStmt->get_result()->fetch_row();
    $luckySumStmt->close();
    $luckyQty = (int)($luckySumRow[0] ?? 0);

    if ($luckyQty < 1) {
        echo json_encode(["result" => "error", "message" => "This order has no lucky box line. Use Non-Set."]);
        $conn->close();
        exit;
    }

    $namesStmt = $conn->prepare(
        "SELECT DISTINCT pr.name FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
         WHERE oi.order_id = ? AND COALESCE(oi.is_lucky_box, 0) = 1 AND TRIM(COALESCE(pr.name, '')) <> ''"
    );
    if (!$namesStmt) {
        echo json_encode(["result" => "error", "message" => "Database error (lucky names)."]);
        $conn->close();
        exit;
    }
    $namesStmt->bind_param("i", $orderId);
    $namesStmt->execute();
    $namesResult = $namesStmt->get_result();
    $allowedNames = [];
    while ($n = $namesResult->fetch_assoc()) {
        $allowedNames[(string)$n['name']] = true;
    }
    $namesStmt->close();
    if (!$allowedNames) {
        echo json_encode(["result" => "error", "message" => "No lucky set products linked on this order."]);
        $conn->close();
        exit;
    }

    $codes = [];
    $sq = trim($set_qr);
    if ($sq !== '') {
        $codes[] = $sq;
    }
    $subsDecoded = json_decode((string)$sub_names, true);
    if (is_array($subsDecoded)) {
        foreach ($subsDecoded as $s) {
            $t = trim((string)$s);
            if ($t !== '') {
                $codes[] = $t;
            }
        }
    }

    if (count($codes) !== $luckyQty) {
        echo json_encode([
            "result" => "error",
            "message" => "Lucky box on this order is qty " . $luckyQty . ". Scan exactly " . $luckyQty . " set QR label(s). You sent " . count($codes) . ".",
        ]);
        $conn->close();
        exit;
    }

    if (count($codes) !== count(array_unique($codes))) {
        echo json_encode(["result" => "error", "message" => "Duplicate set QR scans are not allowed."]);
        $conn->close();
        exit;
    }

    $histStmt = $conn->prepare("SELECT set_name FROM product_set_qr_label_print_history WHERE label_code = ? LIMIT 1");
    if (!$histStmt) {
        echo json_encode(["result" => "error", "message" => "Database error (label history)."]);
        $conn->close();
        exit;
    }
    $prepStmt = $conn->prepare(
        "SELECT ps1.`set` AS set_registered
         FROM prepare_set ps1
         INNER JOIN (
             SELECT inv, MAX(id) AS max_id
             FROM prepare_set
             GROUP BY inv
         ) ps2 ON ps1.inv = ps2.inv AND ps1.id = ps2.max_id
         WHERE ps1.inv = ?
         LIMIT 1"
    );
    if (!$prepStmt) {
        $histStmt->close();
        echo json_encode(["result" => "error", "message" => "Database error (prepare_set)."]);
        $conn->close();
        exit;
    }
    foreach ($codes as $code) {
        $histStmt->bind_param("s", $code);
        $histStmt->execute();
        $hrow = $histStmt->get_result()->fetch_assoc();
        if (!$hrow) {
            $histStmt->close();
            $prepStmt->close();
            echo json_encode(["result" => "error", "message" => "Unknown set QR label (not in print history): " . $code]);
            $conn->close();
            exit;
        }
        $setNameFromLabel = (string)($hrow['set_name'] ?? '');
        if ($setNameFromLabel === '' || !isset($allowedNames[$setNameFromLabel])) {
            $histStmt->close();
            $prepStmt->close();
            echo json_encode([
                "result" => "error",
                "message" => "QR label belongs to set \"" . $setNameFromLabel . "\" which does not match this order's lucky box product(s).",
            ]);
            $conn->close();
            exit;
        }
        $prepStmt->bind_param("s", $code);
        $prepStmt->execute();
        $prepRow = $prepStmt->get_result()->fetch_assoc();
        if (!$prepRow) {
            $histStmt->close();
            $prepStmt->close();
            echo json_encode([
                "result" => "error",
                "message" => "Set QR is not in Prepare Set. Register this code in Prepare Set first: " . $code,
            ]);
            $conn->close();
            exit;
        }
        $setRegistered = (string)($prepRow['set_registered'] ?? '');
        if ($setRegistered !== $setNameFromLabel) {
            $histStmt->close();
            $prepStmt->close();
            echo json_encode([
                "result" => "error",
                "message" => "Prepare Set has QR \"" . $code . "\" as \"" . $setRegistered . "\" but the label is for \"" . $setNameFromLabel . "\".",
            ]);
            $conn->close();
            exit;
        }
    }
    $histStmt->close();
    $prepStmt->close();
}

// --- Duplicate check ---
$dupStmt = $conn->prepare("SELECT id FROM product_entries WHERE inv = ? LIMIT 1");
if ($dupStmt) {
    $dupStmt->bind_param("s", $inv);
    $dupStmt->execute();
    $dupStmt->store_result();
    if ($dupStmt->num_rows > 0) {
        $dupStmt->close();
        echo json_encode(["result" => "duplicate", "message" => "Barcode already exists."]);
        $conn->close();
        exit;
    }
    $dupStmt->close();
}

// --- Handle inv_photo upload ---
$inv_photo = '';
$full_photo = '';
try {
    $inv_photo = isset($_FILES['inv_photo'])
        ? scanner_storage_store_uploaded_file($_FILES['inv_photo'], 'inv_', $date_time)
        : '';

    // --- Handle full_photo upload ---
    $full_photo = isset($_FILES['full_photo'])
        ? scanner_storage_store_uploaded_file($_FILES['full_photo'], 'full_', $date_time)
        : '';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["result" => "error", "message" => $e->getMessage()]);
    $conn->close();
    exit;
}

// --- Prepare query (column names must match your table: set_type, set_qr, sub_name_json) ---
$sql = "INSERT INTO product_entries (
    inv, phone, status, amount, inv_photo, full_photo, user, datetime, set_type, set_qr, sub_name_json
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sssdsssssss",
    $inv,
    $phone,
    $status,
    $amount,
    $inv_photo,
    $full_photo,
    $user,
    $date_time,
    $set_type,
    $set_qr,
    $sub_names // the JSON string from frontend
);

if ($stmt->execute()) {
    $sub = (string)$sub_names;
    if (strlen($sub) > 80) {
        $sub = substr($sub, 0, 80) . '…';
    }
    $det = user_activity_details_compact([
        'inv' => $inv,
        'phone' => $phone,
        'status' => $status,
        'amount' => (string)$amount,
        'user' => $user,
        'datetime' => $date_time,
        'set_type' => $set_type,
        'set_qr' => $set_qr,
        'sub_names' => $sub,
    ]);
    user_activity_log_scanner_mutation(function_exists('current_user') ? current_user() : null, 'create', __FILE__, $det !== '' ? $det : 'inv ' . (string)$inv);
    echo json_encode(["result" => "success"]);
} else {
    if (strpos($stmt->error, "Duplicate entry") !== false) {
        echo json_encode(["result" => "duplicate", "message" => "Barcode already exists."]);
    } else {
        http_response_code(500);
        echo json_encode(["result" => "error", "message" => $stmt->error]);
    }
}
$stmt->close();
$conn->close();
?>
