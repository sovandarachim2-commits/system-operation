<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../upload_paths.php';

function purchase_api_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function purchase_api_date(?string $value, ?string $fallback = null): string
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    return $fallback ?? date('Y-m-d');
}

function purchase_api_num(mixed $value): float
{
    $number = (float)$value;
    return is_finite($number) ? $number : 0.0;
}

function purchase_api_str(mixed $value): string
{
    return trim((string)$value);
}

function purchase_api_user_id(): int
{
    $user = current_user();
    return (int)($user['id'] ?? 0);
}

function purchase_api_stored_files(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value)));
    }
    $text = trim((string)$value);
    if ($text === '') {
        return [];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }
    return [$text];
}

function purchase_api_ensure_text_column(PDO $pdo, string $table, string $column): void
{
    $allowed = [
        'purchase_orders.reference_files' => true,
        'purchase_receiving.reference_files' => true,
    ];
    if (empty($allowed[$table . '.' . $column])) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $stmt->execute([$column]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD `' . $column . '` TEXT NULL');
        }
    } catch (Throwable $e) {
        // Keep older or locked schemas from breaking the purchase workflow.
    }
}

function purchase_api_save_reference_files(array $files, string $category, ?string $date = null, string $label = 'Reference image'): array
{
    $saved = [];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

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
            api_error($label . ' is too large. Maximum is 5MB per image.', 422);
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true) || !in_array($type, $allowedTypes, true)) {
            api_error('Invalid image type. Use JPG, PNG, GIF, or WebP only.', 422);
        }
        $binary = base64_decode($data, true);
        if ($binary === false) {
            api_error('Could not read uploaded image.', 422);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'purchase_ref_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to prepare image upload.');
        }
        file_put_contents($tmp, $binary);
        $filename = 'image_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        try {
            $saved[] = upload_store_file_path($tmp, $category, $filename, $date, $type, false);
        } finally {
            @unlink($tmp);
        }
    }

    return $saved;
}

function purchase_api_merge_reference_files(array $current, array $keep, array $remove, array $newFiles, string $category, ?string $date = null): array
{
    $current = purchase_api_stored_files($current);
    $keep = purchase_api_stored_files($keep);
    $remove = purchase_api_stored_files($remove);
    $base = $keep ?: $current;
    $removeLookup = array_flip($remove);
    $merged = [];
    foreach ($base as $path) {
        if (!isset($removeLookup[$path])) {
            $merged[] = $path;
        } else {
            upload_delete_local_file($path, $category);
        }
    }
    $merged = array_merge($merged, purchase_api_save_reference_files($newFiles, $category, $date));
    return array_values(array_unique(array_filter($merged)));
}

function purchase_api_reference_file_urls(array $files): array
{
    return array_map(static function ($path) {
        return uploaded_file_url((string)$path);
    }, purchase_api_stored_files($files));
}

function purchase_recalc_payment_status(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare('
        SELECT total_amount, COALESCE(total_paid, 0) AS total_paid
        FROM purchase_orders
        WHERE id = ?
    ');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $total = (float)$row['total_amount'];
    $paid = (float)$row['total_paid'];
    $status = 'unpaid';
    if ($paid > 0 && $paid + 0.0001 < $total) {
        $status = 'partial';
    } elseif ($paid + 0.0001 >= $total && $total > 0) {
        $status = 'paid';
    }
    $pdo->prepare('UPDATE purchase_orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$status, $orderId]);
}
