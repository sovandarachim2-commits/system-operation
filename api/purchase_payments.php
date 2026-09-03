<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_common.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = purchase_api_str($_GET['action'] ?? ($method === 'POST' ? '' : 'list'));

function purchase_payment_normalize_receipts(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter($value));
    }
    $text = trim((string)$value);
    if ($text === '') {
        return [];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return array_values(array_filter($decoded));
    }
    return [$text];
}

function purchase_payment_save_reference_files(array $files, string $paymentDate): array
{
    $saved = [];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $type = strtolower(trim((string)($file['type'] ?? 'application/octet-stream')));
        $size = (int)($file['size'] ?? 0);
        $data = (string)($file['data'] ?? '');
        if ($name === '' || $data === '' || $size <= 0) {
            continue;
        }
        if ($size > 5 * 1024 * 1024) {
            api_error('Reference file is too large. Maximum is 5MB per file.', 422);
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true) || !in_array($type, $allowedTypes, true)) {
            api_error('Invalid reference file type. Use image or PDF only.', 422);
        }
        $binary = base64_decode($data, true);
        if ($binary === false) {
            api_error('Could not read reference file.', 422);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pay_ref_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to prepare reference file upload.');
        }
        file_put_contents($tmp, $binary);
        $filename = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            $storedPath = upload_store_file_path($tmp, 'payment_receipts', $filename, $paymentDate, $type, false);
            $saved[] = preg_replace('#^uploads/payment_receipts/#', '', $storedPath);
        } finally {
            @unlink($tmp);
        }
    }

    return $saved;
}

function purchase_payment_receipt_urls(array $files): array
{
    return array_map(static function ($path) {
        return uploaded_file_url((string)$path, 'payment_receipts');
    }, $files);
}

function purchase_payment_method_options(PDO $pdo): array
{
    $fallback = [
        ['value' => 'cash', 'label' => 'Cash'],
        ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
        ['value' => 'cheque', 'label' => 'Cheque'],
        ['value' => 'card', 'label' => 'Card'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    try {
        $paymentParts = [];
        if (function_exists('api_table_exists') && api_table_exists($pdo, 'orders')) {
            $paymentParts[] = "
                SELECT DISTINCT payment_method AS value
                FROM orders
                WHERE payment_method IS NOT NULL AND payment_method <> ''
            ";
        }
        if (function_exists('api_table_exists') && api_table_exists($pdo, 'note_options')) {
            $paymentParts[] = "
                SELECT DISTINCT option_text AS value
                FROM note_options
                WHERE option_text IS NOT NULL AND option_text <> '' AND is_active = 1 AND is_admin_active = 1
            ";
        }
        if ($paymentParts) {
            $stmt = $pdo->query("
                SELECT value, value AS label
                FROM (" . implode(' UNION ', $paymentParts) . ") payment_options
                ORDER BY value
                LIMIT 150
            ");
            $methods = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($methods) {
                return $methods;
            }
        }
        if (function_exists('api_table_exists') && api_table_exists($pdo, 'payment_methods')) {
            $stmt = $pdo->query('
                SELECT method_code AS value, method_name AS label
                FROM payment_methods
                WHERE is_active = TRUE
                ORDER BY sort_order, method_name
            ');
            $methods = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($methods) {
                return $methods;
            }
        }
    } catch (Throwable $e) {
        error_log('purchase_payment_method_options: ' . $e->getMessage());
    }

    return $fallback;
}

try {
    $pdo->exec('ALTER TABLE purchase_payments MODIFY receipt_image TEXT NULL');
} catch (Throwable $e) {
    // Older databases may already be compatible or may not allow runtime DDL.
}

try {
    if ($method === 'GET' && $action === 'options') {
        require_role_or_permission(['admin'], 'sr_purchase_payments.view', 'purchase_payments.view');
        $orders = $pdo->query("
            SELECT
                po.id AS value,
                CONCAT(po.order_number, ' · ', COALESCE(pv.name, 'Supplier'), ' · due ', FORMAT(GREATEST(po.total_amount - COALESCE(po.total_paid, 0), 0), 2)) AS label,
                po.total_amount,
                COALESCE(po.total_paid, 0) AS total_paid,
                GREATEST(po.total_amount - COALESCE(po.total_paid, 0), 0) AS balance_amount
            FROM purchase_orders po
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            WHERE po.status != 'cancelled'
            ORDER BY po.order_date DESC, po.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        api_json([
            'success' => true,
            'orders' => $orders,
            'methods' => purchase_payment_method_options($pdo),
        ]);
    }

    if ($method === 'GET' && ($action === 'list' || $action === '')) {
        require_role_or_permission(['admin'], 'sr_purchase_payments.view', 'purchase_payments.view');
        $from = purchase_api_str($_GET['from'] ?? '');
        $to = purchase_api_str($_GET['to'] ?? '');
        $orderId = (int)($_GET['purchase_order_id'] ?? 0);
        $sql = '
            SELECT
                pp.*,
                po.order_number,
                pv.name AS vendor_name,
                u.name AS paid_by_name
            FROM purchase_payments pp
            JOIN purchase_orders po ON po.id = pp.purchase_order_id
            LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
            LEFT JOIN users u ON u.id = pp.paid_by
            WHERE 1=1
        ';
        $params = [];
        if ($orderId > 0) {
            $sql .= ' AND pp.purchase_order_id = ?';
            $params[] = $orderId;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND pp.payment_date >= ?';
            $params[] = $from;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND pp.payment_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY pp.payment_date DESC, pp.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payments as &$payment) {
            $files = purchase_payment_normalize_receipts($payment['receipt_image'] ?? '');
            $payment['receipt_files'] = $files;
            $payment['receipt_file_urls'] = purchase_payment_receipt_urls($files);
        }
        unset($payment);
        api_json(['success' => true, 'payments' => $payments]);
    }

    if ($method !== 'POST') {
        api_error('Unsupported method.', 405);
    }

    $input = purchase_api_input();
    $action = purchase_api_str($input['action'] ?? $action);

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'sr_purchase_payments.create', 'purchase_payments.create');
        $orderId = (int)($input['purchase_order_id'] ?? 0);
        $amount = purchase_api_num($input['payment_amount'] ?? 0);
        if ($orderId <= 0 || $amount <= 0) {
            api_error('Purchase order and payment amount are required.');
        }
        $paymentNumber = 'PAY-' . date('Y') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $paymentDate = purchase_api_date($input['payment_date'] ?? null);
        $receiptFiles = purchase_payment_save_reference_files(is_array($input['reference_files'] ?? null) ? $input['reference_files'] : [], $paymentDate);
        $pdo->beginTransaction();
        $pdo->prepare('
            INSERT INTO purchase_payments
            (purchase_order_id, payment_number, payment_date, payment_method, payment_amount, payment_status, reference_number, notes, receipt_image, paid_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $orderId,
            $paymentNumber,
            $paymentDate,
            purchase_api_str($input['payment_method'] ?? 'bank_transfer') ?: 'bank_transfer',
            $amount,
            'completed',
            purchase_api_str($input['reference_number'] ?? ''),
            purchase_api_str($input['notes'] ?? ''),
            json_encode($receiptFiles),
            purchase_api_user_id(),
        ]);
        $pdo->prepare('UPDATE purchase_orders SET total_paid = COALESCE(total_paid, 0) + ? WHERE id = ?')
            ->execute([$amount, $orderId]);
        purchase_recalc_payment_status($pdo, $orderId);
        $pdo->commit();
        api_json(['success' => true, 'payment_number' => $paymentNumber, 'message' => "Payment $paymentNumber recorded."]);
    }

    if ($action === 'update') {
        require_role_or_permission(['admin'], 'sr_purchase_payments.update', 'purchase_payments.update');
        $id = (int)($input['id'] ?? 0);
        $orderId = (int)($input['purchase_order_id'] ?? 0);
        $amount = purchase_api_num($input['payment_amount'] ?? 0);
        if ($id <= 0 || $orderId <= 0 || $amount <= 0) {
            api_error('Purchase order and payment amount are required.');
        }
        $stmt = $pdo->prepare('SELECT * FROM purchase_payments WHERE id = ?');
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            api_error('Payment not found.', 404);
        }

        $paymentDate = purchase_api_date($input['payment_date'] ?? null);
        $existingFiles = purchase_payment_normalize_receipts($payment['receipt_image'] ?? '');
        $newFiles = purchase_payment_save_reference_files(is_array($input['reference_files'] ?? null) ? $input['reference_files'] : [], $paymentDate);
        $receiptFiles = array_values(array_filter(array_merge($existingFiles, $newFiles)));
        $oldOrderId = (int)$payment['purchase_order_id'];
        $oldAmount = (float)$payment['payment_amount'];

        $pdo->beginTransaction();
        $pdo->prepare('
            UPDATE purchase_payments
            SET purchase_order_id = ?, payment_date = ?, payment_method = ?, payment_amount = ?, reference_number = ?, notes = ?, receipt_image = ?
            WHERE id = ?
        ')->execute([
            $orderId,
            $paymentDate,
            purchase_api_str($input['payment_method'] ?? 'bank_transfer') ?: 'bank_transfer',
            $amount,
            purchase_api_str($input['reference_number'] ?? ''),
            purchase_api_str($input['notes'] ?? ''),
            json_encode($receiptFiles),
            $id,
        ]);
        if ($oldOrderId === $orderId) {
            $pdo->prepare('UPDATE purchase_orders SET total_paid = GREATEST(COALESCE(total_paid, 0) - ? + ?, 0) WHERE id = ?')
                ->execute([$oldAmount, $amount, $orderId]);
            purchase_recalc_payment_status($pdo, $orderId);
        } else {
            $pdo->prepare('UPDATE purchase_orders SET total_paid = GREATEST(COALESCE(total_paid, 0) - ?, 0) WHERE id = ?')
                ->execute([$oldAmount, $oldOrderId]);
            $pdo->prepare('UPDATE purchase_orders SET total_paid = COALESCE(total_paid, 0) + ? WHERE id = ?')
                ->execute([$amount, $orderId]);
            purchase_recalc_payment_status($pdo, $oldOrderId);
            purchase_recalc_payment_status($pdo, $orderId);
        }
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Payment updated.']);
    }

    if ($action === 'delete') {
        require_role_or_permission(['admin'], 'sr_purchase_payments.delete', 'purchase_payments.delete');
        $id = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM purchase_payments WHERE id = ?');
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            api_error('Payment not found.', 404);
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM purchase_payments WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE purchase_orders SET total_paid = GREATEST(COALESCE(total_paid, 0) - ?, 0) WHERE id = ?')
            ->execute([(float)$payment['payment_amount'], (int)$payment['purchase_order_id']]);
        purchase_recalc_payment_status($pdo, (int)$payment['purchase_order_id']);
        $pdo->commit();
        api_json(['success' => true, 'message' => 'Payment deleted.']);
    }

    api_error('Unknown action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e->getMessage(), 500);
}
