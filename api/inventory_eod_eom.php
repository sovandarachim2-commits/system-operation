<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_common.php';
require_once __DIR__ . '/../includes/eod_eom_generate.php';
require_once __DIR__ . '/../user_activity_lib.php';

$pdo = get_db_connection();
eod_eom_ensure_physical_count_columns($pdo);
eod_eom_ensure_difference_review_columns($pdo);
eod_eom_ensure_eom_sheet_columns($pdo);
require_role_or_permission(
    ['admin'],
    'sr_inventory_closing.view',
    'inventory.view',
    'stock_reports.view',
    'stock_dashboard.view'
);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $isMultipart = str_contains($contentType, 'multipart/form-data');
    if ($isMultipart) {
        $payload = $_POST;
    } else {
        $raw = (string)file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
    }

    $action = strtolower(inventory_api_str($payload['action'] ?? ''));
    $currentUser = current_user();
    $userId = (int)($currentUser['id'] ?? ($_SESSION['user_id'] ?? 0)) ?: null;

    try {
        if ($action === 'generate_eod') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.create');
            $result = eod_eom_generate_eod(
                $pdo,
                inventory_api_date($payload['report_date'] ?? null, date('Y-m-d')),
                $userId
            );
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_create',
                'generated EOD report ' . inventory_api_str($result['report_date'] ?? $payload['report_date'] ?? ''),
                ['module' => 'inventory_closing', 'action' => 'generate_eod', 'report_id' => $result['report_id'] ?? null]
            );
            api_json(['success' => true] + $result);
        }

        if ($action === 'generate_eom') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.create');
            $month = inventory_api_str($payload['report_month'] ?? date('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = date('Y-m');
            }
            $reportDate = inventory_api_date(
                $payload['report_date'] ?? null,
                date('Y-m-t', strtotime($month . '-01'))
            );
            $result = eod_eom_generate_eom($pdo, $month, $reportDate, $userId);
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_create',
                'generated EOM report ' . inventory_api_str($result['report_month'] ?? $month),
                ['module' => 'inventory_closing', 'action' => 'generate_eom', 'report_id' => $result['report_id'] ?? null]
            );
            api_json(['success' => true] + $result);
        }

        if ($action === 'delete_report') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.delete');
            $result = eod_eom_delete_report(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($payload['report_id'] ?? 0)
            );
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_delete',
                'deleted ' . strtoupper(inventory_api_str($payload['report_type'] ?? 'eod')) . ' report #' . inventory_api_int($payload['report_id'] ?? 0),
                ['module' => 'inventory_closing', 'action' => 'delete_report', 'report_id' => inventory_api_int($payload['report_id'] ?? 0)]
            );
            api_json(['success' => true] + $result);
        }

        if ($action === 'finalize_report') {
            require_role_or_permission(
                ['admin'],
                'sr_inventory_closing.update',
                'sr_inventory_closing.create'
            );
            $files = isset($_FILES['attachments']) && is_array($_FILES['attachments'])
                ? eod_eom_normalize_uploaded_files($_FILES['attachments'])
                : [];
            $result = eod_eom_finalize_report(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($payload['report_id'] ?? 0),
                inventory_api_str($payload['notes'] ?? ''),
                $files,
                $userId
            );
            $locationMap = [];
            foreach (inventory_location_options($pdo) as $loc) {
                $locationMap[(int)$loc['value']] = (string)$loc['label'];
            }
            $notification = inventory_closing_send_telegram(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($result['report_id'] ?? $payload['report_id'] ?? 0),
                'Finalized',
                $locationMap,
                false,
                [
                    'manager_review_confirmed' => !empty($payload['manager_review_confirmed']),
                ]
            );
            $result['notification'] = $notification;
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_update',
                'finalized ' . strtoupper(inventory_api_str($payload['report_type'] ?? 'eod')) . ' report #' . inventory_api_int($result['report_id'] ?? $payload['report_id'] ?? 0),
                ['module' => 'inventory_closing', 'action' => 'finalize_report', 'report_id' => inventory_api_int($result['report_id'] ?? $payload['report_id'] ?? 0)]
            );
            api_json(['success' => true] + $result);
        }

        if ($action === 'save_telegram_settings') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.update');
            $settings = inventory_closing_save_telegram_settings($pdo, $payload);
            api_json([
                'success' => true,
                'settings' => $settings,
                'message' => 'Stock Closing notification settings saved.',
            ]);
        }

        if ($action === 'test_telegram_notification') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.update');
            $testReportType = strtolower(inventory_api_str($payload['report_type'] ?? 'eod'));
            if (!in_array($testReportType, ['eod', 'eom'], true)) {
                $testReportType = 'eod';
            }
            $testReportId = inventory_api_int($payload['report_id'] ?? 0);
            if ($testReportId <= 0) {
                $table = $testReportType === 'eom' ? 'eom_stock_reports' : 'eod_stock_reports';
                $orderColumn = $testReportType === 'eom' ? 'report_month' : 'report_date';
                $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY {$orderColumn} DESC, id DESC LIMIT 1");
                $testReportId = (int)($stmt->fetchColumn() ?: 0);
            }
            if ($testReportId <= 0) {
                throw new InvalidArgumentException('No stock closing report is available for test notification.');
            }
            $locationMap = [];
            foreach (inventory_location_options($pdo) as $loc) {
                $locationMap[(int)$loc['value']] = (string)$loc['label'];
            }
            $result = inventory_closing_send_telegram($pdo, $testReportType, $testReportId, 'Test Notification', $locationMap, true);
            api_json(['success' => !empty($result['ok']), 'notification' => $result, 'message' => $result['message'] ?? 'Test notification sent.']);
        }

        if ($action === 'approve_differences') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.approve', 'sr_inventory_closing.update');
            $result = eod_eom_approve_differences(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($payload['report_id'] ?? 0),
                inventory_api_str($payload['notes'] ?? ''),
                $userId
            );
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_update',
                'approved differences for ' . strtoupper(inventory_api_str($payload['report_type'] ?? 'eod')) . ' report #' . inventory_api_int($payload['report_id'] ?? 0),
                ['module' => 'inventory_closing', 'action' => 'approve_differences', 'report_id' => inventory_api_int($payload['report_id'] ?? 0)]
            );
            $reviewerId = (int)($result['difference_reviewed_by'] ?? 0);
            $result['difference_reviewed_by_id'] = $reviewerId;
            $result['difference_reviewed_by'] = inventory_closing_user_name($pdo, $reviewerId);
            api_json(['success' => true] + $result);
        }

        if ($action === 'reject_differences') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.approve', 'sr_inventory_closing.update');
            $result = eod_eom_reject_differences(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($payload['report_id'] ?? 0),
                inventory_api_str($payload['notes'] ?? ''),
                $userId
            );
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_update',
                'rejected differences for ' . strtoupper(inventory_api_str($payload['report_type'] ?? 'eod')) . ' report #' . inventory_api_int($payload['report_id'] ?? 0),
                ['module' => 'inventory_closing', 'action' => 'reject_differences', 'report_id' => inventory_api_int($payload['report_id'] ?? 0)]
            );
            $reviewerId = (int)($result['difference_reviewed_by'] ?? 0);
            $result['difference_reviewed_by_id'] = $reviewerId;
            $result['difference_reviewed_by'] = inventory_closing_user_name($pdo, $reviewerId);
            api_json(['success' => true] + $result);
        }

        if ($action === 'save_final_quantity') {
            require_role_or_permission(
                ['admin'],
                'sr_inventory_closing.update',
                'sr_inventory_closing.create'
            );
            $result = inventory_closing_save_final_quantity(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($payload['detail_id'] ?? 0),
                $payload['final_quantity'] ?? null,
                $userId,
                $payload['location_qty'] ?? null
            );
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_update',
                'saved physical count detail #' . inventory_api_int($payload['detail_id'] ?? 0),
                ['module' => 'inventory_closing', 'action' => 'save_final_quantity', 'detail_id' => inventory_api_int($payload['detail_id'] ?? 0)]
            );
            api_json(['success' => true] + $result);
        }

        if ($action === 'update_status') {
            require_role_or_permission(['admin'], 'sr_inventory_closing.update');
            $result = eod_eom_update_status(
                $pdo,
                inventory_api_str($payload['report_type'] ?? 'eod'),
                inventory_api_int($payload['report_id'] ?? 0),
                inventory_api_str($payload['status'] ?? ''),
                inventory_api_str($payload['notes'] ?? ''),
                $userId
            );
            user_activity_log(
                $pdo,
                $currentUser,
                'inventory_closing_update',
                'updated ' . strtoupper(inventory_api_str($payload['report_type'] ?? 'eod')) . ' report #' . inventory_api_int($payload['report_id'] ?? 0) . ' status to ' . inventory_api_str($payload['status'] ?? ''),
                ['module' => 'inventory_closing', 'action' => 'update_status', 'report_id' => inventory_api_int($payload['report_id'] ?? 0)]
            );
            api_json(['success' => true] + $result);
        }

        api_json([
            'success' => false,
            'message' => 'Unsupported action. Use generate_eod, generate_eom, delete_report, finalize_report, save_telegram_settings, test_telegram_notification, approve_differences, reject_differences, save_final_quantity, or update_status.',
        ], 400);
    } catch (InvalidArgumentException $e) {
        api_json(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (RuntimeException $e) {
        api_json(['success' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        api_json([
            'success' => false,
            'message' => 'Unable to process closing report: ' . $e->getMessage(),
        ], 500);
    }
}

$reportType = strtolower(inventory_api_str($_GET['report_type'] ?? 'eod'));
if (!in_array($reportType, ['eod', 'eom'], true)) {
    $reportType = 'eod';
}

$reportId = inventory_api_int($_GET['report_id'] ?? 0);
$locationId = inventory_api_int($_GET['location_id'] ?? 0);
$brandId = inventory_api_int($_GET['brand_id'] ?? 0);
$q = inventory_api_str($_GET['q'] ?? '');
$date = inventory_api_date($_GET['date'] ?? null, '');
$fromDate = inventory_api_date($_GET['from_date'] ?? null, '');
$toDate = inventory_api_date($_GET['to_date'] ?? null, '');
$month = inventory_api_str($_GET['month'] ?? '');
$fromMonth = inventory_api_str($_GET['from_month'] ?? '');
$toMonth = inventory_api_str($_GET['to_month'] ?? '');
$statusFilter = strtolower(inventory_api_str($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['draft', 'finalized'], true)) {
    $statusFilter = '';
}
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = '';
}
if (!preg_match('/^\d{4}-\d{2}$/', $fromMonth)) {
    $fromMonth = '';
}
if (!preg_match('/^\d{4}-\d{2}$/', $toMonth)) {
    $toMonth = '';
}
$limit = max(1, min(200, inventory_api_int($_GET['limit'] ?? 40)));
$listOnly = inventory_api_str($_GET['list_only'] ?? '') === '1';

$locationMap = [];
foreach (inventory_location_options($pdo) as $loc) {
    $locationMap[(int)$loc['value']] = (string)$loc['label'];
}

if (inventory_api_str($_GET['settings'] ?? '') === 'telegram') {
    require_role_or_permission(['admin'], 'sr_inventory_closing.update');
    api_json([
        'success' => true,
        'settings' => inventory_closing_telegram_settings($pdo),
    ]);
}

if (inventory_api_str($_GET['marketing_type_qty'] ?? '') === '1') {
    $typeMonth = inventory_api_str($_GET['month'] ?? $month);
    if (!preg_match('/^\d{4}-\d{2}$/', $typeMonth)) {
        $typeMonth = date('Y-m');
    }
    api_json(['success' => true] + eod_eom_marketing_type_breakdown($pdo, $typeMonth));
}

function eod_eom_marketing_type_breakdown(PDO $pdo, string $month): array
{
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $labels = [];
    try {
        $typeRows = $pdo->query('
            SELECT type_key, label
            FROM marketing_types
            ORDER BY sort_order ASC, label ASC
        ')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($typeRows as $row) {
            $key = trim((string)($row['type_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $labels[$key] = $label !== '' ? $label : $key;
        }
    } catch (Throwable $e) {
        // Keep going with keys from takes.
    }

    $items = [];
    $used = [];
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.name AS item_name,
                COALESCE(p.sku, '') AS sku,
                COALESCE(NULLIF(TRIM(mt.marketing_type), ''), 'other') AS marketing_type,
                SUM(mti.quantity_taken) AS qty
            FROM marketing_takes mt
            JOIN marketing_take_items mti ON mti.marketing_take_id = mt.id
            JOIN products p ON p.id = mti.product_id
            WHERE DATE(mt.approved_at) >= ? AND DATE(mt.approved_at) <= ?
              AND mt.approved_at IS NOT NULL
              AND COALESCE(mt.status, '') IN ('approved', 'pending', 'completed')
            GROUP BY p.name, p.sku, COALESCE(NULLIF(TRIM(mt.marketing_type), ''), 'other')
        ");
        $stmt->execute([$monthStart, $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $typeKey = trim((string)($row['marketing_type'] ?? 'other'));
            if ($typeKey === '') {
                $typeKey = 'other';
            }
            $qty = (float)($row['qty'] ?? 0);
            if (abs($qty) < 0.0005) {
                continue;
            }
            $used[$typeKey] = ($used[$typeKey] ?? 0) + $qty;
            $items[] = [
                'item_name' => (string)($row['item_name'] ?? ''),
                'sku' => (string)($row['sku'] ?? ''),
                'marketing_type' => $typeKey,
                'qty' => $qty,
            ];
        }
    } catch (Throwable $e) {
        return ['types' => [], 'rows' => []];
    }

    $types = [];
    foreach ($labels as $key => $label) {
        if (!isset($used[$key])) {
            continue;
        }
        $types[] = ['key' => $key, 'label' => $label];
    }
    foreach ($used as $key => $_qty) {
        if (isset($labels[$key])) {
            continue;
        }
        $types[] = ['key' => $key, 'label' => $key];
    }

    return ['types' => $types, 'rows' => $items];
}

function inventory_closing_ensure_settings_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function inventory_closing_get_setting(PDO $pdo, string $key, string $default = ''): string
{
    inventory_closing_ensure_settings_table($pdo);
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function inventory_closing_set_setting(PDO $pdo, string $key, string $value): void
{
    inventory_closing_ensure_settings_table($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO app_settings(`key`, `value`) VALUES(?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    $stmt->execute([$key, $value]);
}

function inventory_closing_telegram_settings(PDO $pdo): array
{
    return [
        'enabled' => inventory_closing_get_setting($pdo, 'inventory_closing_telegram_enabled', '0') === '1',
        'bot_token' => inventory_closing_get_setting($pdo, 'inventory_closing_telegram_bot_token', ''),
        'chat_id' => inventory_closing_get_setting($pdo, 'inventory_closing_telegram_chat_id', ''),
        'thread_id' => inventory_closing_get_setting($pdo, 'inventory_closing_telegram_thread_id', ''),
        'notify_finalize' => inventory_closing_get_setting($pdo, 'inventory_closing_telegram_notify_finalize', '1') !== '0',
    ];
}

function inventory_closing_save_telegram_settings(PDO $pdo, array $payload): array
{
    inventory_closing_set_setting($pdo, 'inventory_closing_telegram_enabled', !empty($payload['enabled']) ? '1' : '0');
    inventory_closing_set_setting($pdo, 'inventory_closing_telegram_bot_token', inventory_api_str($payload['bot_token'] ?? ''));
    inventory_closing_set_setting($pdo, 'inventory_closing_telegram_chat_id', inventory_api_str($payload['chat_id'] ?? ''));
    inventory_closing_set_setting($pdo, 'inventory_closing_telegram_thread_id', inventory_api_str($payload['thread_id'] ?? ''));
    inventory_closing_set_setting($pdo, 'inventory_closing_telegram_notify_finalize', array_key_exists('notify_finalize', $payload) && empty($payload['notify_finalize']) ? '0' : '1');
    return inventory_closing_telegram_settings($pdo);
}

function inventory_closing_telegram_bot_token(string $reportType, array $settings = []): string
{
    global $TELEGRAM_BOT_TOKEN, $EOD_TELEGRAM_BOT_TOKEN, $EOM_TELEGRAM_BOT_TOKEN;
    $moduleToken = trim((string)($settings['bot_token'] ?? ''));
    if ($moduleToken !== '') {
        return $moduleToken;
    }
    $token = $reportType === 'eom'
        ? trim((string)($EOM_TELEGRAM_BOT_TOKEN ?? ''))
        : trim((string)($EOD_TELEGRAM_BOT_TOKEN ?? ''));
    return $token !== '' ? $token : trim((string)($TELEGRAM_BOT_TOKEN ?? ''));
}

function inventory_closing_format_qty_for_message(mixed $value): string
{
    return number_format((int)round(inventory_api_num($value)), 0);
}

function inventory_closing_signed_qty_for_message(mixed $value): string
{
    $n = (int)round(inventory_api_num($value));
    return ($n > 0 ? '+' : '') . number_format($n, 0);
}

function inventory_closing_report_for_notification(PDO $pdo, string $reportType, int $reportId, array $locationMap): ?array
{
    if ($reportType === 'eom') {
        $stmt = $pdo->prepare('SELECT report_month AS period FROM eom_stock_reports WHERE id = ? LIMIT 1');
    } else {
        $stmt = $pdo->prepare('SELECT report_date AS period FROM eod_stock_reports WHERE id = ? LIMIT 1');
    }
    $stmt->execute([$reportId]);
    $period = inventory_api_str($stmt->fetchColumn() ?: '');
    if ($period === '') {
        return null;
    }

    $reports = $reportType === 'eom'
        ? inventory_closing_list_reports($pdo, $reportType, 300, '', '', $period, $period, '', $locationMap)
        : inventory_closing_list_reports($pdo, $reportType, 300, $period, $period, '', '', '', $locationMap);
    foreach ($reports as $report) {
        if ((int)($report['id'] ?? 0) === $reportId) {
            return $report;
        }
    }
    return null;
}

function inventory_closing_build_telegram_message(array $report, string $eventLabel, array $context = []): string
{
    $type = strtoupper((string)($report['report_type'] ?? 'EOD'));
    $period = (string)($report['period'] ?? $report['report_date'] ?? $report['report_month'] ?? ('#' . ($report['id'] ?? '')));
    $periodLabel = $type === 'EOM' ? '📅 ខែ' : '📅 ថ្ងៃទី';
    $lines = [
        "📦 របាយការណ៍បិទស្តុក {$type} {$eventLabel}",
        "{$periodLabel} : {$period}",
        "✅ ស្ថានភាព : " . (string)($report['status_label'] ?? $report['status'] ?? ''),
    ];
    if (!empty($context['manager_review_confirmed'])) {
        $lines[] = "☑️ បញ្ជាក់ : ស្តុកខុសគ្នា ត្រូវការអ្នកគ្រប់គ្រងពិនិត្យ";
    }
    $differenceLines = is_array($context['difference_lines'] ?? null) ? $context['difference_lines'] : [];
    $differenceCount = (int)($context['difference_count'] ?? count($differenceLines));
    if ($differenceLines) {
        $lines[] = '⚠️ ផលិតផលខុសគ្នា:';
        foreach ($differenceLines as $differenceLine) {
            $lines[] = '- ' . (string)$differenceLine;
        }
        $remaining = $differenceCount - count($differenceLines);
        if ($remaining > 0) {
            $lines[] = '- +' . $remaining . ' ទៀត';
        }
    }
    $lines[] = "📝 ចំណាំ :";
    if (!empty($report['difference_reviewed_by'])) {
        $lines[] = "👤 ពិនិត្យដោយ : " . (string)$report['difference_reviewed_by'];
    }
    if (!empty($report['finalized_by'])) {
        $lines[] = "👤 បិទដោយ : " . (string)$report['finalized_by'];
    }
    if (!empty($report['finalized_at'])) {
        $lines[] = "⏰ បិទនៅ : " . (string)$report['finalized_at'];
    }
    $attachments = is_array($context['attachments'] ?? null) ? $context['attachments'] : [];
    $attachmentLines = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $attachmentName = inventory_api_str($attachment['filename'] ?? '');
        if ($attachmentName === '') {
            continue;
        }
        $extension = strtolower(pathinfo($attachmentName, PATHINFO_EXTENSION));
        $isImage = !empty($attachment['is_image']) || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        $isExcel = in_array($extension, ['xls', 'xlsx', 'csv'], true);
        $attachmentLines[] = [
            'filename' => $attachmentName,
            'type' => $isExcel ? 'Excel' : ($isImage ? 'រូបភាព' : 'ឯកសារ'),
            'url' => inventory_api_str($attachment['url'] ?? ''),
        ];
    }
    $attachmentCount = count($attachmentLines);
    if ($attachmentCount > 0) {
        $lines[] = '📎 ឯកសារភ្ជាប់ : ' . $attachmentCount . ' file' . ($attachmentCount === 1 ? '' : 's');
        foreach ($attachmentLines as $index => $attachmentLine) {
            $lines[] = '📎 ឯកសារភ្ជាប់ ' . ($index + 1) . ': ' . $attachmentLine['type'] . ' - ' . $attachmentLine['filename'];
            if ($attachmentLine['url'] !== '') {
                $lines[] = '🔗 តំណភ្ជាប់ : ' . $attachmentLine['url'];
            }
        }
    }
    return implode("\n", $lines);
}

function inventory_closing_difference_message_context(PDO $pdo, string $reportType, int $reportId, array $locationMap, int $limit = 10): array
{
    $rows = inventory_closing_detail_rows($pdo, $reportType, $reportId, 0, 0, '', $locationMap);
    $lines = [];
    $count = 0;

    foreach ($rows as $row) {
        if (!array_key_exists('final_quantity', $row) || $row['final_quantity'] === null) {
            continue;
        }
        $systemQty = inventory_api_num($row['quantity_on_hand'] ?? $row['closing_quantity'] ?? 0);
        $physicalQty = inventory_api_num($row['final_quantity']);
        $difference = round($physicalQty) - round($systemQty);
        if (abs($difference) < 0.005) {
            continue;
        }

        $count++;
        if (count($lines) >= $limit) {
            continue;
        }

        $product = inventory_api_str($row['item_name'] ?? '') ?: 'Unknown product';
        $location = inventory_api_str($row['location_name'] ?? '');
        $locationText = $location !== '' ? ' (' . $location . ')' : '';
        $lines[] = $product . $locationText . ' - ' . inventory_closing_signed_qty_for_message($difference);
    }

    return ['difference_lines' => $lines, 'difference_count' => $count];
}

function inventory_closing_escape_html(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inventory_closing_telegram_caption(array $report, array $context = []): string
{
    $type = strtoupper((string)($report['report_type'] ?? 'EOD'));
    $period = inventory_closing_escape_html($report['period'] ?? $report['report_date'] ?? $report['report_month'] ?? '');
    $finalizedBy = inventory_closing_escape_html($report['finalized_by'] ?? 'Unknown');
    $finalizedAtRaw = inventory_api_str($report['finalized_at'] ?? '');
    $finalizedAt = $finalizedAtRaw !== '' ? date('d M Y H:i', strtotime($finalizedAtRaw)) : '';
    $notes = inventory_closing_escape_html($report['notes'] ?? '');
    $differenceLines = is_array($context['difference_lines'] ?? null) ? $context['difference_lines'] : [];
    $differenceCount = (int)($context['difference_count'] ?? count($differenceLines));

    $caption = '📦 <b>របាយការណ៍ស្តុក ' . $type . '</b>' . "\n"
        . '📅 ថ្ងៃទី : ' . $period . "\n"
        . '👤 បិទដោយ : ' . $finalizedBy . "\n"
        . '⏰ បិទនៅ : ' . inventory_closing_escape_html($finalizedAt);
    if (!empty($context['manager_review_confirmed'])) {
        $caption .= "\n" . '☑️ បញ្ជាក់ : ស្តុកខុសគ្នា ត្រូវការអ្នកគ្រប់គ្រងពិនិត្យ';
    }
    $caption .= "\n\n" . '📝 ចំណាំ : ' . $notes;
    if ($differenceLines) {
        $caption .= "\n\n" . '⚠️ ផលិតផលខុសគ្នា:';
        foreach ($differenceLines as $differenceLine) {
            $caption .= "\n" . '- ' . inventory_closing_escape_html($differenceLine);
        }
        $remaining = $differenceCount - count($differenceLines);
        if ($remaining > 0) {
            $caption .= "\n" . '- +' . $remaining . ' ទៀត';
        }
    }

    return $caption;
}

function inventory_closing_send_telegram_media(
    string $botToken,
    string $chatId,
    ?int $threadId,
    string $method,
    string $mediaField,
    mixed $media,
    string $caption,
    ?string $mimeType = null,
    ?string $fileName = null
): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL extension is not available.'];
    }

    $payload = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        $mediaField => $media,
    ];
    if ($threadId !== null) {
        $payload['message_thread_id'] = $threadId;
    }

    if (is_string($media) && is_file($media)) {
        $payload[$mediaField] = new CURLFile($media, $mimeType ?: (mime_content_type($media) ?: 'application/octet-stream'), $fileName ?: basename($media));
    }

    $ch = curl_init("https://api.telegram.org/bot{$botToken}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
    ]);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'Telegram media send failed.', 'http_code' => $httpCode];
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid Telegram response.', 'http_code' => $httpCode];
    }
    return ['ok' => !empty($decoded['ok']), 'decoded' => $decoded, 'error' => (string)($decoded['description'] ?? ''), 'http_code' => $httpCode];
}

function inventory_closing_send_telegram_html_message(string $botToken, string $chatId, ?int $threadId, string $text): array
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($threadId !== null) {
        $payload['message_thread_id'] = $threadId;
    }

    $raw = telegram_http_post_form("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Telegram message send failed.'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid Telegram response.'];
    }
    return ['ok' => !empty($decoded['ok']), 'decoded' => $decoded, 'error' => (string)($decoded['description'] ?? '')];
}

function inventory_closing_excel_cell(mixed $value, bool $header = false): string
{
    $style = $header
        ? ' style="font-weight:700;background:#e0f2fe;color:#0f172a;border:1px solid #94a3b8;padding:6px;"'
        : ' style="border:1px solid #cbd5e1;padding:5px;mso-number-format:\'\@\';"';
    return '<td' . $style . '>' . inventory_closing_escape_html($value) . '</td>';
}

function inventory_closing_build_excel_file(PDO $pdo, string $reportType, int $reportId, array $locationMap, array $report): array
{
    $rows = inventory_closing_detail_rows($pdo, $reportType, $reportId, 0, 0, '', $locationMap);
    if ($reportType === 'eod') {
        $rows = inventory_closing_attach_sales_to_rows(
            $pdo,
            $rows,
            inventory_api_str($report['report_date'] ?? $report['period'] ?? '')
        );
    }
    if (!$rows) {
        return ['success' => false, 'error' => 'No stock rows found for Excel export.'];
    }

    $type = strtoupper($reportType);
    $period = inventory_api_str($report['period'] ?? $report['report_date'] ?? $report['report_month'] ?? date('Y-m-d'));
    $safePeriod = preg_replace('/[^0-9A-Za-z_-]+/', '-', $period) ?: date('Ymd');
    $fileName = strtolower($type) . '_stock_report_' . $safePeriod . '.xls';
    $filePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('ordershadow_stock_', true) . '.xls';
    $activityColumns = $reportType === 'eod'
        ? [
            ['label' => 'Received', 'value' => static fn(array $row): float => inventory_api_num($row['daily_received'] ?? 0)],
            ['label' => 'Purchase Back', 'value' => static fn(array $row): float => inventory_api_num($row['offline_purchase_back'] ?? 0)],
            ['label' => 'Offline Sale Cancel', 'value' => static fn(array $row): float => inventory_api_num($row['cancelled_offline_sale'] ?? 0)],
            ['label' => 'Marketing Return', 'value' => static fn(array $row): float => inventory_api_num($row['marketing_return'] ?? 0)],
            ['label' => 'Return Qty', 'value' => static fn(array $row): float => inventory_api_num($row['return_quantity'] ?? 0)],
            ['label' => 'Stock In', 'value' => static fn(array $row): float => inventory_closing_row_stock_in_only($row)],
            ['label' => 'Transfer In', 'value' => static fn(array $row): float => inventory_api_num($row['transfer_in'] ?? 0)],
            ['label' => 'Return Vendor', 'value' => static fn(array $row): float => inventory_api_num($row['purchase_return_vendor'] ?? 0)],
            ['label' => 'Online Sold', 'value' => static fn(array $row): float => inventory_api_num($row['total_sold'] ?? 0)],
            ['label' => 'Offline Sold', 'value' => static fn(array $row): float => inventory_api_num($row['offline_sale'] ?? 0)],
            ['label' => 'Dealer Sold', 'value' => static fn(array $row): float => inventory_api_num($row['dealer_sold'] ?? 0)],
            ['label' => 'Offline Purchase Cancel', 'value' => static fn(array $row): float => inventory_api_num($row['offline_cancelled_purchase_back'] ?? 0)],
            ['label' => 'Marketing Out', 'value' => static fn(array $row): float => inventory_api_num($row['marketing_take_out'] ?? 0)],
            ['label' => 'Stock Out', 'value' => static fn(array $row): float => inventory_closing_row_stock_out_only($row)],
            ['label' => 'Transfer Out', 'value' => static fn(array $row): float => inventory_api_num($row['transfer_out'] ?? 0)],
        ]
        : [
            ['label' => 'In', 'value' => static fn(array $row): float => inventory_api_num($row['movements_in'] ?? 0)],
            ['label' => 'Out', 'value' => static fn(array $row): float => inventory_api_num($row['movements_out'] ?? 0)],
        ];
    $activityColumns = array_values(array_filter($activityColumns, static function (array $column) use ($rows): bool {
        $total = array_reduce($rows, static fn(float $sum, array $row): float => $sum + inventory_api_num($column['value']($row)), 0.0);
        return abs($total) >= 0.005;
    }));
    $headers = [
        'No',
        'Product',
        'Brand',
        'Type',
        'Location',
        'Opening',
        ...array_map(static fn(array $column): string => $column['label'], $activityColumns),
        'System Qty',
        'Physical Count',
        'Difference',
        'Result',
        'Remark',
    ];
    $tableRows = [];
    $tableRows[] = '<tr><td colspan="' . count($headers) . '" style="font-size:18px;font-weight:700;background:#0f172a;color:#ffffff;padding:8px;">' . inventory_closing_escape_html($type . ' Stock Report') . '</td></tr>';
    $tableRows[] = '<tr><td colspan="' . count($headers) . '" style="font-weight:600;background:#f8fafc;padding:6px;">Date: ' . inventory_closing_escape_html($period) . ' | Finalized by: ' . inventory_closing_escape_html($report['finalized_by'] ?? '') . ' | Finalized at: ' . inventory_closing_escape_html($report['finalized_at'] ?? '') . '</td></tr>';
    $tableRows[] = '<tr>' . implode('', array_map(static fn($header) => inventory_closing_excel_cell($header, true), $headers)) . '</tr>';

    $totals = [
        'opening' => 0.0,
        'activity' => array_fill(0, count($activityColumns), 0.0),
        'system' => 0.0,
        'physical' => 0.0,
        'difference' => 0.0,
    ];
    foreach ($rows as $index => $row) {
        $systemQty = inventory_api_num($row['quantity_on_hand'] ?? $row['closing_quantity'] ?? 0);
        $physical = array_key_exists('final_quantity', $row) && $row['final_quantity'] !== null ? inventory_api_num($row['final_quantity']) : '';
        $difference = $physical === '' ? '' : round($physical) - round($systemQty);
        $result = 'Pending';
        if ($difference !== '') {
            $result = $difference < -0.005 ? 'Missing' : ($difference > 0.005 ? 'Extra' : 'Match');
        }
        $activityValues = array_map(static fn(array $column) => $column['value']($row), $activityColumns);
        $totals['opening'] += inventory_api_num($row['opening_quantity'] ?? 0);
        foreach ($activityValues as $activityIndex => $activityValue) {
            $totals['activity'][$activityIndex] += inventory_api_num($activityValue);
        }
        $totals['system'] += $systemQty;
        if ($physical !== '') {
            $totals['physical'] += inventory_api_num($physical);
            $totals['difference'] += inventory_api_num($difference);
        }
        $values = [
            $index + 1,
            $row['item_name'] ?? '',
            $row['brand_name'] ?? '',
            $row['product_type_label'] ?? $row['product_type'] ?? '',
            $row['location_name'] ?? '',
            $row['opening_quantity'] ?? 0,
            ...$activityValues,
            $systemQty,
            $physical,
            $difference,
            $result,
            $row['notes'] ?? '',
        ];
        $tableRows[] = '<tr>' . implode('', array_map('inventory_closing_excel_cell', $values)) . '</tr>';
    }
    $totalValues = [
        '',
        'Total',
        '',
        '',
        '',
        $totals['opening'],
        ...$totals['activity'],
        $totals['system'],
        $totals['physical'],
        $totals['difference'],
        '',
        '',
    ];
    $tableRows[] = '<tr>' . implode('', array_map(static fn($value) => inventory_closing_excel_cell($value, true), $totalValues)) . '</tr>';

    $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'
        . '<table border="1" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px;">'
        . implode('', $tableRows)
        . '</table></body></html>';

    if (file_put_contents($filePath, $html) === false) {
        return ['success' => false, 'error' => 'Unable to create Excel export.'];
    }

    return ['success' => true, 'file_path' => $filePath, 'file_name' => $fileName, 'mime_type' => 'application/vnd.ms-excel'];
}

function inventory_closing_attachment_local_path(string $filePath): string
{
    return __DIR__ . '/../uploads/eod_eom_reports/' . ltrim(str_replace('\\', '/', $filePath), '/');
}

function inventory_closing_send_telegram(PDO $pdo, string $reportType, int $reportId, string $eventLabel, array $locationMap, bool $force = false, array $context = []): array
{
    $settings = inventory_closing_telegram_settings($pdo);
    if (!$force && (empty($settings['enabled']) || empty($settings['notify_finalize']))) {
        return ['ok' => false, 'skipped' => true, 'message' => 'Stock Closing Telegram notification is disabled.'];
    }
    $botToken = inventory_closing_telegram_bot_token($reportType, $settings);
    $chatId = trim((string)($settings['chat_id'] ?? ''));
    if ($botToken === '') {
        return ['ok' => false, 'message' => 'Telegram bot token is not configured.'];
    }
    if ($chatId === '') {
        return ['ok' => false, 'message' => 'Telegram chat ID is not configured.'];
    }

    $report = inventory_closing_report_for_notification($pdo, $reportType, $reportId, $locationMap);
    if (!$report) {
        return ['ok' => false, 'message' => 'Report not found for Telegram notification.'];
    }
    $context['attachments'] = inventory_closing_report_attachments($pdo, $reportType, $reportId);
    $context += inventory_closing_difference_message_context($pdo, $reportType, $reportId, $locationMap);
    $threadRaw = trim((string)($settings['thread_id'] ?? ''));
    $threadId = $threadRaw !== '' ? (int)$threadRaw : null;
    $sendResults = [];
    $excel = inventory_closing_build_excel_file($pdo, $reportType, $reportId, $locationMap, $report);
    if (!empty($excel['success'])) {
        $sendResults[] = inventory_closing_send_telegram_media(
            $botToken,
            $chatId,
            $threadId,
            'sendDocument',
            'document',
            (string)$excel['file_path'],
            inventory_closing_telegram_caption($report, $context),
            (string)$excel['mime_type'],
            (string)$excel['file_name']
        ) + ['item' => 'excel'];
        if (is_file((string)$excel['file_path'])) {
            @unlink((string)$excel['file_path']);
        }
    } else {
        $sendResults[] = telegram_send_message_request(
            $botToken,
            $chatId,
            inventory_closing_build_telegram_message($report, $eventLabel, $context),
            $threadId,
            null,
            ['disable_web_page_preview' => true]
        ) + ['item' => 'message'];
    }

    foreach ($context['attachments'] as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $filename = inventory_api_str($attachment['filename'] ?? '');
        if ($filename === '') {
            continue;
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isImage = !empty($attachment['is_image']) || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        $caption = '📎 ឯកសារភ្ជាប់របាយការណ៍: ' . inventory_closing_escape_html($filename);
        if ($isImage) {
            $imageUrl = inventory_api_str($attachment['url'] ?? '');
            if ($imageUrl !== '') {
                $imageMessage = $caption . "\n"
                    . '🖼️ រូបភាព: <a href="' . inventory_closing_escape_html($imageUrl) . '">បើករូបភាព</a>';
                $sendResults[] = inventory_closing_send_telegram_html_message($botToken, $chatId, $threadId, $imageMessage) + ['item' => 'image', 'filename' => $filename];
            }
            continue;
        }
        $localPath = inventory_closing_attachment_local_path(inventory_api_str($attachment['file_path'] ?? ''));
        $media = is_file($localPath) ? $localPath : inventory_api_str($attachment['url'] ?? '');
        if ($media !== '') {
            $sendResults[] = inventory_closing_send_telegram_media($botToken, $chatId, $threadId, 'sendDocument', 'document', $media, $caption, inventory_api_str($attachment['mime_type'] ?? ''), $filename) + ['item' => 'attachment', 'filename' => $filename];
        }
    }

    $failed = array_values(array_filter($sendResults, static fn($result) => empty($result['ok'])));
    if ($failed) {
        $firstError = (string)($failed[0]['error'] ?? 'Unknown error');
        return ['ok' => false, 'message' => 'Telegram send failed: ' . $firstError, 'results' => $sendResults];
    }
    return ['ok' => true, 'message' => 'Telegram notification sent.', 'results' => $sendResults];
}

function inventory_closing_user_name(PDO $pdo, ?int $userId): string
{
    if (!$userId) {
        return '';
    }
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(TRIM(name), \'\'), username, \'\') AS label FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return inventory_api_str($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function inventory_closing_report_attachments(PDO $pdo, string $reportType, int $reportId): array
{
    if ($reportId <= 0) {
        return [];
    }

    try {
        if (!api_table_exists($pdo, 'eod_eom_report_attachments')) {
            return [];
        }
        $stmt = $pdo->prepare('
            SELECT id, file_path, original_filename, file_size, mime_type, uploaded_at, uploaded_by
            FROM eod_eom_report_attachments
            WHERE report_id = ? AND report_type = ?
            ORDER BY uploaded_at ASC, id ASC
        ');
        $stmt->execute([$reportId, $reportType]);
        $attachments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $filename = inventory_api_str($row['original_filename'] ?? '');
            $mimeType = inventory_api_str($row['mime_type'] ?? '');
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isImage = str_starts_with(strtolower($mimeType), 'image/')
                || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
            $attachments[] = [
                'id' => (int)($row['id'] ?? 0),
                'filename' => $filename,
                'file_path' => inventory_api_str($row['file_path'] ?? ''),
                'file_size' => (int)($row['file_size'] ?? 0),
                'mime_type' => $mimeType,
                'uploaded_at' => inventory_api_str($row['uploaded_at'] ?? ''),
                'uploaded_by' => inventory_closing_user_name($pdo, (int)($row['uploaded_by'] ?? 0)),
                'url' => uploaded_file_url(inventory_api_str($row['file_path'] ?? ''), 'eod_eom_reports'),
                'is_image' => $isImage,
            ];
        }
        return $attachments;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Online sold qty by date (printed orders), same source as OrderShadow EOD Sold.
 *
 * @param list<string> $dates
 * @return array<string, float> date => qty
 */
function inventory_closing_sold_totals_by_date(PDO $pdo, array $dates): array
{
    $dates = array_values(array_unique(array_filter(array_map('strval', $dates))));
    $out = [];
    foreach ($dates as $date) {
        $out[$date] = 0.0;
    }
    if ($dates === []) {
        return $out;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $stmt = $pdo->prepare("
            SELECT
                DATE(pj.printed_at) AS sold_date,
                SUM(
                    CASE
                        WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                        THEN oi.quantity * COALESCE(psi.quantity, 0)
                        ELSE oi.quantity
                    END
                ) AS total_sold
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            LEFT JOIN product_sets ps
                ON ps.set_name = p.name
               AND COALESCE(p.product_type, 'normal') = 'set'
            LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
            LEFT JOIN products component_product ON component_product.id = psi.product_id
            WHERE DATE(pj.printed_at) IN ($placeholders)
              AND COALESCE(o.is_cancelled, 0) = 0
              AND COALESCE(o.is_returned, 0) = 0
            GROUP BY DATE(pj.printed_at)
        ");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = inventory_api_str($row['sold_date'] ?? '');
            if ($date !== '') {
                $out[$date] = inventory_api_num($row['total_sold'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Keep report usable if sold tables are unavailable.
    }

    return $out;
}

/**
 * Extra EOD operation totals by date (same sources as OrderShadow EOD columns).
 *
 * @param list<string> $dates
 * @return array<string, array<string, float>>
 */
function inventory_closing_eod_ops_totals_by_date(PDO $pdo, array $dates): array
{
    $dates = array_values(array_unique(array_filter(array_map('strval', $dates))));
    $blank = [
        'total_sold' => 0.0,
        'dealer_sold' => 0.0,
        'offline_sale' => 0.0,
        'offline_purchase_back' => 0.0,
        'cancelled_offline_sale' => 0.0,
        'offline_cancelled_purchase_back' => 0.0,
        'marketing_take_out' => 0.0,
        'marketing_return' => 0.0,
        'purchase_return_vendor' => 0.0,
    ];
    $out = [];
    foreach ($dates as $date) {
        $out[$date] = $blank;
    }
    if ($dates === []) {
        return $out;
    }

    $placeholders = implode(',', array_fill(0, count($dates), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(sm.created_at) AS op_date,
                   SUM(CASE WHEN sm.reference_type = 'offline_sale' THEN ABS(sm.quantity) ELSE 0 END) AS offline_sale,
                   SUM(CASE WHEN sm.reference_type = 'dealer_order' OR (sm.movement_type = 'out' AND sm.notes LIKE 'Dealer order confirmed:%') THEN ABS(sm.quantity) ELSE 0 END) AS dealer_sold,
                   SUM(CASE WHEN sm.reference_type = 'offline_customer_purchase' THEN ABS(sm.quantity) ELSE 0 END) AS offline_purchase_back,
                   SUM(CASE WHEN sm.reference_type IN ('offline_sale_cancel', 'offline_sale_edit') THEN ABS(sm.quantity) ELSE 0 END) AS cancelled_offline_sale,
                   SUM(CASE WHEN sm.reference_type IN ('offline_customer_purchase_cancel', 'offline_purchase_edit') THEN ABS(sm.quantity) ELSE 0 END) AS offline_cancelled_purchase_back
            FROM stock_movements sm
            WHERE DATE(sm.created_at) IN ($placeholders)
              AND (
                  sm.reference_type IN (
                      'offline_sale', 'offline_customer_purchase', 'offline_sale_cancel',
                      'offline_sale_edit', 'offline_customer_purchase_cancel', 'offline_purchase_edit',
                      'dealer_order'
                  )
                  OR (sm.movement_type = 'out' AND sm.notes LIKE 'Dealer order confirmed:%')
              )
            GROUP BY DATE(sm.created_at)
        ");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = inventory_api_str($row['op_date'] ?? '');
            if ($date === '' || !isset($out[$date])) {
                continue;
            }
            $out[$date]['offline_sale'] = inventory_api_num($row['offline_sale'] ?? 0);
            $out[$date]['dealer_sold'] = inventory_api_num($row['dealer_sold'] ?? 0);
            $out[$date]['offline_purchase_back'] = inventory_api_num($row['offline_purchase_back'] ?? 0);
            $out[$date]['cancelled_offline_sale'] = inventory_api_num($row['cancelled_offline_sale'] ?? 0);
            $out[$date]['offline_cancelled_purchase_back'] = inventory_api_num($row['offline_cancelled_purchase_back'] ?? 0);
        }
    } catch (Throwable $e) {
        // optional
    }

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(pr.return_date) AS op_date, SUM(pri.quantity_returned) AS purchase_return_vendor
            FROM purchase_returns pr
            JOIN purchase_return_items pri ON pri.purchase_return_id = pr.id
            WHERE DATE(pr.return_date) IN ($placeholders)
              AND COALESCE(pr.status, 'completed') <> 'deleted'
            GROUP BY DATE(pr.return_date)
        ");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = inventory_api_str($row['op_date'] ?? '');
            if ($date !== '' && isset($out[$date])) {
                $out[$date]['purchase_return_vendor'] = inventory_api_num($row['purchase_return_vendor'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // optional
    }

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(mt.approved_at) AS op_date, SUM(mti.quantity_taken) AS marketing_take_out
            FROM marketing_takes mt
            JOIN marketing_take_items mti ON mti.marketing_take_id = mt.id
            WHERE DATE(mt.approved_at) IN ($placeholders)
              AND mt.approved_at IS NOT NULL
              AND COALESCE(mt.status, '') IN ('approved', 'pending', 'completed')
            GROUP BY DATE(mt.approved_at)
        ");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = inventory_api_str($row['op_date'] ?? '');
            if ($date !== '' && isset($out[$date])) {
                $out[$date]['marketing_take_out'] = inventory_api_num($row['marketing_take_out'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // optional
    }

    try {
        $stmt = $pdo->prepare("
            SELECT DATE(so.created_at) AS op_date, SUM(ABS(so.quantity)) AS marketing_return
            FROM stock_operations so
            WHERE DATE(so.created_at) IN ($placeholders)
              AND so.reference_type = 'marketing_take'
              AND so.operation_type = 'marketing_return'
            GROUP BY DATE(so.created_at)
        ");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = inventory_api_str($row['op_date'] ?? '');
            if ($date !== '' && isset($out[$date])) {
                $out[$date]['marketing_return'] = inventory_api_num($row['marketing_return'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // optional
    }

    $soldByDate = inventory_closing_sold_totals_by_date($pdo, $dates);
    foreach ($soldByDate as $date => $qty) {
        if (isset($out[$date])) {
            $out[$date]['total_sold'] = inventory_api_num($qty);
        }
    }

    return $out;
}

/**
 * Attach OrderShadow EOD operation columns onto detail rows.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function inventory_closing_attach_sales_to_rows(PDO $pdo, array $rows, string $reportDate): array
{
    if ($rows === [] || $reportDate === '') {
        return $rows;
    }

    $extraFields = [
        'total_sold',
        'dealer_sold',
        'offline_sale',
        'offline_purchase_back',
        'cancelled_offline_sale',
        'offline_cancelled_purchase_back',
        'marketing_take_out',
        'marketing_return',
        'purchase_return_vendor',
    ];
    foreach ($rows as $index => $row) {
        foreach ($extraFields as $field) {
            $rows[$index][$field] = 0.0;
        }
    }

    $defaultLocationId = null;
    try {
        $defaultLocationId = $pdo->query('SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1')->fetchColumn();
        $defaultLocationId = $defaultLocationId === false ? null : (int)$defaultLocationId;
    } catch (Throwable $e) {
        $defaultLocationId = null;
    }

    $rowKey = static function (array $row): array {
        $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
        $locKey = (($row['storage_location_id'] ?? null) === null || (int)$row['storage_location_id'] === 0)
            ? 'null'
            : (string)(int)$row['storage_location_id'];
        return [$itemName, $locKey];
    };

    try {
        $stmt = $pdo->prepare("
            SELECT
                CASE
                    WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                    THEN component_product.name
                    ELSE p.name
                END AS item_name,
                ? AS storage_location_id,
                SUM(
                    CASE
                        WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                        THEN oi.quantity * COALESCE(psi.quantity, 0)
                        ELSE oi.quantity
                    END
                ) AS total_sold
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            LEFT JOIN product_sets ps
                ON ps.set_name = p.name
               AND COALESCE(p.product_type, 'normal') = 'set'
            LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
            LEFT JOIN products component_product ON component_product.id = psi.product_id
            WHERE DATE(pj.printed_at) = ?
              AND COALESCE(o.is_cancelled, 0) = 0
              AND COALESCE(o.is_returned, 0) = 0
            GROUP BY item_name, storage_location_id
        ");
        $stmt->execute([$defaultLocationId, $reportDate]);
        $soldLookup = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            if ($itemName === '') {
                continue;
            }
            $locKey = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $soldLookup[$itemName . '|' . $locKey] = inventory_api_num($row['total_sold'] ?? 0);
        }
        foreach ($rows as $index => $row) {
            [$itemName, $locKey] = $rowKey($row);
            $rows[$index]['total_sold'] = $soldLookup[$itemName . '|' . $locKey] ?? 0.0;
        }
    } catch (Throwable $e) {
        // keep zeros
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.name AS item_name,
                CASE
                    WHEN sm.reference_type IN ('offline_sale', 'dealer_order', 'offline_customer_purchase_cancel', 'offline_purchase_edit')
                        OR (sm.movement_type = 'out' AND sm.notes LIKE 'Dealer order confirmed:%')
                        THEN sm.from_storage_location_id
                    ELSE sm.to_storage_location_id
                END AS storage_location_id,
                SUM(CASE WHEN sm.reference_type = 'offline_sale' THEN ABS(sm.quantity) ELSE 0 END) AS offline_sale,
                SUM(CASE WHEN sm.reference_type = 'dealer_order' OR (sm.movement_type = 'out' AND sm.notes LIKE 'Dealer order confirmed:%') THEN ABS(sm.quantity) ELSE 0 END) AS dealer_sold,
                SUM(CASE WHEN sm.reference_type = 'offline_customer_purchase' THEN ABS(sm.quantity) ELSE 0 END) AS offline_purchase_back,
                SUM(CASE WHEN sm.reference_type IN ('offline_sale_cancel', 'offline_sale_edit') THEN ABS(sm.quantity) ELSE 0 END) AS cancelled_offline_sale,
                SUM(CASE WHEN sm.reference_type IN ('offline_customer_purchase_cancel', 'offline_purchase_edit') THEN ABS(sm.quantity) ELSE 0 END) AS offline_cancelled_purchase_back
            FROM stock_movements sm
            JOIN products p ON p.id = sm.item_id
            WHERE DATE(sm.created_at) = ?
              AND (
                  sm.reference_type IN (
                      'offline_sale', 'offline_customer_purchase', 'offline_sale_cancel',
                      'offline_sale_edit', 'offline_customer_purchase_cancel', 'offline_purchase_edit',
                      'dealer_order'
                  )
                  OR (sm.movement_type = 'out' AND sm.notes LIKE 'Dealer order confirmed:%')
              )
            GROUP BY p.name, storage_location_id
        ");
        $stmt->execute([$reportDate]);
        $offlineLookup = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            if ($itemName === '') {
                continue;
            }
            $locKey = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $offlineLookup[$itemName . '|' . $locKey] = $row;
        }
        foreach ($rows as $index => $row) {
            [$itemName, $locKey] = $rowKey($row);
            $hit = $offlineLookup[$itemName . '|' . $locKey] ?? null;
            if (!$hit) {
                continue;
            }
            $rows[$index]['offline_sale'] = inventory_api_num($hit['offline_sale'] ?? 0);
            $rows[$index]['dealer_sold'] = inventory_api_num($hit['dealer_sold'] ?? 0);
            $rows[$index]['offline_purchase_back'] = inventory_api_num($hit['offline_purchase_back'] ?? 0);
            $rows[$index]['cancelled_offline_sale'] = inventory_api_num($hit['cancelled_offline_sale'] ?? 0);
            $rows[$index]['offline_cancelled_purchase_back'] = inventory_api_num($hit['offline_cancelled_purchase_back'] ?? 0);
        }
    } catch (Throwable $e) {
        // keep zeros
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(poi.item_name), '')) AS item_name,
                pri.storage_location_id,
                SUM(pri.quantity_returned) AS purchase_return_vendor
            FROM purchase_returns pr
            JOIN purchase_return_items pri ON pri.purchase_return_id = pr.id
            JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            LEFT JOIN products p ON p.id = poi.product_id
            WHERE DATE(pr.return_date) = ?
              AND COALESCE(pr.status, 'completed') <> 'deleted'
            GROUP BY item_name, pri.storage_location_id
        ");
        $stmt->execute([$reportDate]);
        $returnLookup = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            if ($itemName === '') {
                continue;
            }
            $locKey = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $returnLookup[$itemName . '|' . $locKey] = inventory_api_num($row['purchase_return_vendor'] ?? 0);
        }
        foreach ($rows as $index => $row) {
            [$itemName, $locKey] = $rowKey($row);
            $rows[$index]['purchase_return_vendor'] = $returnLookup[$itemName . '|' . $locKey] ?? 0.0;
        }
    } catch (Throwable $e) {
        // keep zeros
    }

    try {
        $hasProductId = (bool)$pdo->query("SHOW COLUMNS FROM stock_operations LIKE 'product_id'")->fetchColumn();
        $hasProductName = (bool)$pdo->query("SHOW COLUMNS FROM stock_operations LIKE 'product_name'")->fetchColumn();
        $itemNameParts = [];
        if ($hasProductId) {
            $itemNameParts[] = "NULLIF(TRIM(p.name), '')";
        }
        if ($hasProductName) {
            $itemNameParts[] = "NULLIF(TRIM(so.product_name), '')";
        }
        $itemNameParts[] = "NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(so.notes, ' | Note:', 1), ': ', -1)), '')";
        $itemNameExpr = 'COALESCE(' . implode(', ', $itemNameParts) . ')';
        $productJoin = $hasProductId ? 'LEFT JOIN products p ON p.id = so.product_id' : '';

        $stmt = $pdo->prepare("
            SELECT
                marketing.item_name,
                marketing.storage_location_id,
                SUM(marketing.marketing_take_out) AS marketing_take_out,
                SUM(marketing.marketing_return) AS marketing_return
            FROM (
                SELECT
                    p.name AS item_name,
                    mt.storage_location_id,
                    SUM(mti.quantity_taken) AS marketing_take_out,
                    0 AS marketing_return
                FROM marketing_takes mt
                JOIN marketing_take_items mti ON mti.marketing_take_id = mt.id
                JOIN products p ON p.id = mti.product_id
                WHERE DATE(mt.approved_at) = ?
                  AND mt.approved_at IS NOT NULL
                  AND COALESCE(mt.status, '') IN ('approved', 'pending', 'completed')
                GROUP BY p.name, mt.storage_location_id

                UNION ALL

                SELECT
                    {$itemNameExpr} AS item_name,
                    so.storage_location_id,
                    0 AS marketing_take_out,
                    SUM(ABS(so.quantity)) AS marketing_return
                FROM stock_operations so
                {$productJoin}
                WHERE DATE(so.created_at) = ?
                  AND so.reference_type = 'marketing_take'
                  AND so.operation_type = 'marketing_return'
                GROUP BY item_name, so.storage_location_id
            ) marketing
            WHERE marketing.item_name IS NOT NULL
            GROUP BY marketing.item_name, marketing.storage_location_id
        ");
        $stmt->execute([$reportDate, $reportDate]);
        $marketingLookup = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            if ($itemName === '') {
                continue;
            }
            $locKey = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $marketingLookup[$itemName . '|' . $locKey] = [
                'marketing_take_out' => inventory_api_num($row['marketing_take_out'] ?? 0),
                'marketing_return' => inventory_api_num($row['marketing_return'] ?? 0),
            ];
        }
        foreach ($rows as $index => $row) {
            [$itemName, $locKey] = $rowKey($row);
            $hit = $marketingLookup[$itemName . '|' . $locKey] ?? null;
            if (!$hit) {
                continue;
            }
            $rows[$index]['marketing_take_out'] = $hit['marketing_take_out'];
            $rows[$index]['marketing_return'] = $hit['marketing_return'];
        }
    } catch (Throwable $e) {
        // keep zeros
    }

    return inventory_closing_dedupe_movement_columns($rows);
}

/**
 * Stock In/Out columns should only show manual stock-operation movements.
 * Offline and other typed flows already have their own detail columns.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function inventory_closing_dedupe_movement_columns(array $rows): array
{
    foreach ($rows as $index => $row) {
        $moveIn = inventory_api_num($row['movements_in'] ?? 0);
        $moveOut = inventory_api_num($row['movements_out'] ?? 0);
        // Received qty is its own column; legacy EOD rows may also include it inside movements_in.
        $received = inventory_api_num($row['daily_received'] ?? 0);
        if ($received > 0.005 && $moveIn > 0.005) {
            $moveIn -= min($received, $moveIn);
        }
        $moveIn -= inventory_api_num($row['offline_purchase_back'] ?? 0);
        $moveIn -= inventory_api_num($row['cancelled_offline_sale'] ?? 0);
        $moveOut -= inventory_api_num($row['offline_sale'] ?? 0);
        $moveOut -= inventory_api_num($row['dealer_sold'] ?? 0);
        $moveOut -= inventory_api_num($row['offline_cancelled_purchase_back'] ?? 0);
        $rows[$index]['movements_in'] = $moveIn > 0.005 ? $moveIn : 0.0;
        $rows[$index]['movements_out'] = $moveOut > 0.005 ? $moveOut : 0.0;
    }

    return $rows;
}

function inventory_closing_row_adjustment_in(array $row): float
{
    $adjustment = inventory_api_num($row['adjustments'] ?? 0);
    return $adjustment > 0.005 ? $adjustment : 0.0;
}

function inventory_closing_row_adjustment_out(array $row): float
{
    $adjustment = inventory_api_num($row['adjustments'] ?? 0);
    return $adjustment < -0.005 ? abs($adjustment) : 0.0;
}

function inventory_closing_row_stock_in_only(array $row): float
{
    $moveIn = inventory_api_num($row['movements_in'] ?? 0);
    $moveIn -= inventory_api_num($row['offline_purchase_back'] ?? 0);
    $moveIn -= inventory_api_num($row['cancelled_offline_sale'] ?? 0);
    return $moveIn > 0.005 ? $moveIn : 0.0;
}

function inventory_closing_row_stock_out_only(array $row): float
{
    $moveOut = inventory_api_num($row['movements_out'] ?? 0);
    $moveOut -= inventory_api_num($row['offline_sale'] ?? 0);
    $moveOut -= inventory_api_num($row['dealer_sold'] ?? 0);
    $moveOut -= inventory_api_num($row['offline_cancelled_purchase_back'] ?? 0);
    return $moveOut > 0.005 ? $moveOut : 0.0;
}

function inventory_closing_row_movement_in(array $row, string $reportType): float
{
    if ($reportType !== 'eod') {
        return inventory_api_num($row['movements_in'] ?? 0);
    }

    return inventory_api_num($row['daily_received'] ?? 0)
        + inventory_api_num($row['offline_purchase_back'] ?? 0)
        + inventory_api_num($row['cancelled_offline_sale'] ?? 0)
        + inventory_api_num($row['marketing_return'] ?? 0)
        + inventory_api_num($row['return_quantity'] ?? 0)
        + inventory_closing_row_stock_in_only($row)
        + inventory_api_num($row['transfer_in'] ?? 0);
}

function inventory_closing_row_movement_out(array $row, string $reportType): float
{
    if ($reportType !== 'eod') {
        return inventory_api_num($row['movements_out'] ?? 0);
    }

    return inventory_api_num($row['purchase_return_vendor'] ?? 0)
        + inventory_api_num($row['total_sold'] ?? 0)
        + inventory_api_num($row['offline_sale'] ?? 0)
        + inventory_api_num($row['dealer_sold'] ?? 0)
        + inventory_api_num($row['offline_cancelled_purchase_back'] ?? 0)
        + inventory_api_num($row['marketing_take_out'] ?? 0)
        + inventory_closing_row_stock_out_only($row)
        + inventory_api_num($row['transfer_out'] ?? 0);
}

function inventory_closing_report_movement_totals(PDO $pdo, string $reportType, int $reportId, string $period, array $locationMap): array
{
    $totals = ['in' => 0.0, 'out' => 0.0];
    if ($reportId <= 0) {
        return $totals;
    }

    $rows = inventory_closing_detail_rows($pdo, $reportType, $reportId, 0, 0, '', $locationMap);
    if ($reportType === 'eod') {
        $rows = inventory_closing_attach_sales_to_rows($pdo, $rows, $period);
    }
    foreach ($rows as $row) {
        $totals['in'] += inventory_closing_row_movement_in($row, $reportType);
        $totals['out'] += inventory_closing_row_movement_out($row, $reportType);
    }
    return $totals;
}

function inventory_closing_save_final_quantity(PDO $pdo, string $reportType, int $detailId, mixed $finalQuantity, ?int $userId, mixed $locationQty = null): array
{
    $reportType = strtolower(trim($reportType));
    if (!in_array($reportType, ['eod', 'eom'], true) || $detailId <= 0) {
        throw new InvalidArgumentException('Invalid product row.');
    }

    if (is_string($locationQty) && trim($locationQty) !== '') {
        $decoded = json_decode($locationQty, true);
        $locationQty = is_array($decoded) ? $decoded : null;
    }

    $locationMap = is_array($locationQty) && $locationQty !== [] ? $locationQty : null;
    $locationJson = null;
    $value = null;

    if ($locationMap !== null) {
        $complete = true;
        $sum = 0.0;
        $clean = [];
        foreach ($locationMap as $id => $qty) {
            $locId = trim((string)$id);
            if ($locId === '') {
                continue;
            }
            $text = is_scalar($qty) ? trim((string)$qty) : '';
            if ($text === '') {
                $complete = false;
                continue;
            }
            if (!is_numeric($text)) {
                throw new InvalidArgumentException('Physical Count must be a number.');
            }
            $n = round((float)$text);
            if ($n < 0) {
                throw new InvalidArgumentException('Physical Count cannot be negative.');
            }
            $clean[$locId] = $n;
            $sum += $n;
        }
        $locationJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $value = $complete ? $sum : null;
    } else {
        $valueText = trim((string)$finalQuantity);
        if ($valueText !== '') {
            if (!is_numeric($valueText)) {
                throw new InvalidArgumentException('Physical Count must be a number.');
            }
            $value = round((float)$valueText);
            if ($value < 0) {
                throw new InvalidArgumentException('Physical Count cannot be negative.');
            }
        }
    }

    eod_eom_ensure_physical_count_columns($pdo);
    eod_eom_ensure_eom_sheet_columns($pdo);

    $detailTable = eod_eom_report_detail_table($reportType);
    $detailFk = eod_eom_report_detail_fk($reportType);
    $reportTable = $reportType === 'eom' ? 'eom_stock_reports' : 'eod_stock_reports';
    $systemQtyColumn = $reportType === 'eom' ? 'closing_quantity' : 'quantity_on_hand';

    $hasLocationCol = false;
    if ($reportType === 'eom') {
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'final_location_qty_json'");
            $hasLocationCol = (bool)($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasLocationCol = false;
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.`{$detailFk}` AS report_id,
            d.`{$systemQtyColumn}` AS system_quantity,
            r.status
        FROM `{$detailTable}` d
        JOIN `{$reportTable}` r ON r.id = d.`{$detailFk}`
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$detailId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Product row not found.');
    }
    if (strtolower((string)($row['status'] ?? 'draft')) !== 'draft') {
        throw new RuntimeException('Physical Count can only be edited on draft reports.');
    }

    if ($hasLocationCol && $locationMap !== null) {
        $stmt = $pdo->prepare("
            UPDATE `{$detailTable}`
            SET final_quantity = ?, final_counted_by = ?, final_counted_at = NOW(), final_location_qty_json = ?
            WHERE id = ?
        ");
        $stmt->execute([$value, $userId, $locationJson, $detailId]);
    } elseif ($value === null) {
        $stmt = $pdo->prepare("
            UPDATE `{$detailTable}`
            SET final_quantity = NULL, final_counted_by = NULL, final_counted_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$detailId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE `{$detailTable}`
            SET final_quantity = ?, final_counted_by = ?, final_counted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$value, $userId, $detailId]);
    }

    $systemQty = inventory_api_num($row['system_quantity'] ?? 0);
    return [
        'detail_id' => $detailId,
        'report_id' => (int)($row['report_id'] ?? 0),
        'report_type' => $reportType,
        'system_quantity' => $systemQty,
        'final_quantity' => $value,
        'final_location_qty' => $locationMap !== null ? eod_eom_decode_team_map($locationJson) : null,
        'difference' => $value === null ? null : $value - $systemQty,
        'message' => $value === null ? 'Physical Count saved. Finish every location to complete this product.' : 'Physical Count saved.',
    ];
}

function inventory_closing_list_reports(
    PDO $pdo,
    string $reportType,
    int $limit,
    string $fromDate = '',
    string $toDate = '',
    string $fromMonth = '',
    string $toMonth = '',
    string $statusFilter = '',
    array $locationMap = []
): array {
    $params = [];
    $where = [];

    if ($statusFilter !== '') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }

    if ($reportType === 'eom') {
        if ($fromMonth !== '') {
            $where[] = 'report_month >= ?';
            $params[] = $fromMonth;
        }
        if ($toMonth !== '') {
            $where[] = 'report_month <= ?';
            $params[] = $toMonth;
        }
        $sql = '
            SELECT id, report_month, report_date, created_at, created_by, total_items, total_quantity, total_value,
                   status, notes, finalized_at, finalized_by, difference_reviewed_by, difference_reviewed_at, difference_review_notes, difference_review_status
            FROM eom_stock_reports
        ';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY report_month DESC, id DESC LIMIT ' . (int)$limit;
    } else {
        if ($fromDate !== '') {
            $where[] = 'report_date >= ?';
            $params[] = $fromDate;
        }
        if ($toDate !== '') {
            $where[] = 'report_date <= ?';
            $params[] = $toDate;
        }
        $sql = '
            SELECT id, report_date, created_at, created_by, total_items, total_quantity, total_value,
                   status, notes, finalized_at, finalized_by, difference_reviewed_by, difference_reviewed_at, difference_review_notes, difference_review_status
            FROM eod_stock_reports
        ';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY report_date DESC, id DESC LIMIT ' . (int)$limit;
    }

    try {
        if ($params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $openingById = [];
    $closingById = [];
    $finalById = [];
    $finalDifferenceById = [];
    $missingFinalById = [];
    $missingQtyById = [];
    $extraQtyById = [];
    $stockInById = [];
    $stockOutById = [];
    $attachmentsById = [];
    $attachmentCountsById = [];
    if ($ids) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($reportType === 'eom') {
                $sumSql = "
                    SELECT eom_report_id AS report_id,
                           COALESCE(SUM(opening_quantity), 0) AS opening_stock,
                           COALESCE(SUM(closing_quantity), 0) AS closing_stock,
                           COALESCE(SUM(final_quantity), 0) AS final_stock,
                           COALESCE(SUM(CASE WHEN final_quantity IS NULL THEN 1 ELSE 0 END), 0) AS missing_final_count,
                           COALESCE(SUM(CASE WHEN final_quantity IS NOT NULL AND final_quantity < closing_quantity THEN closing_quantity - final_quantity ELSE 0 END), 0) AS missing_qty,
                           COALESCE(SUM(CASE WHEN final_quantity IS NOT NULL AND final_quantity > closing_quantity THEN final_quantity - closing_quantity ELSE 0 END), 0) AS extra_qty,
                           COALESCE(SUM(CASE WHEN final_quantity IS NOT NULL THEN final_quantity - closing_quantity ELSE 0 END), 0) AS final_difference,
                           COALESCE(SUM(movements_in), 0) AS stock_in,
                           COALESCE(SUM(movements_out), 0) AS stock_out
                    FROM eom_stock_report_details
                    WHERE eom_report_id IN ($placeholders)
                    GROUP BY eom_report_id
                ";
            } else {
                // Closing = current on-hand snapshot
                // Base Movement In/Out from stored detail columns; extra ops added below.
                $sumSql = "
                    SELECT eod_report_id AS report_id,
                           COALESCE(SUM(opening_quantity), 0) AS opening_stock,
                           COALESCE(SUM(quantity_on_hand), 0) AS closing_stock,
                           COALESCE(SUM(CASE WHEN storage_location_id = (SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1) THEN final_quantity ELSE 0 END), 0) AS final_stock,
                           COALESCE(SUM(CASE WHEN storage_location_id = (SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1) AND final_quantity IS NULL THEN 1 ELSE 0 END), 0) AS missing_final_count,
                           COALESCE(SUM(CASE WHEN storage_location_id = (SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1) AND final_quantity IS NOT NULL AND final_quantity < quantity_on_hand THEN quantity_on_hand - final_quantity ELSE 0 END), 0) AS missing_qty,
                           COALESCE(SUM(CASE WHEN storage_location_id = (SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1) AND final_quantity IS NOT NULL AND final_quantity > quantity_on_hand THEN final_quantity - quantity_on_hand ELSE 0 END), 0) AS extra_qty,
                           COALESCE(SUM(CASE WHEN storage_location_id = (SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1) AND final_quantity IS NOT NULL THEN final_quantity - quantity_on_hand ELSE 0 END), 0) AS final_difference,
                           COALESCE(SUM(
                               COALESCE(daily_received, 0)
                               + COALESCE(movements_in, 0)
                               + COALESCE(transfer_in, 0)
                               + COALESCE(return_quantity, 0)
                           ), 0) AS stock_in,
                           COALESCE(SUM(
                               COALESCE(movements_out, 0)
                               + COALESCE(transfer_out, 0)
                           ), 0) AS stock_out
                    FROM eod_stock_report_details
                    WHERE eod_report_id IN ($placeholders)
                    GROUP BY eod_report_id
                ";
            }
            $sumStmt = $pdo->prepare($sumSql);
            $sumStmt->execute($ids);
            foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $sumRow) {
                $rid = (int)($sumRow['report_id'] ?? 0);
                $openingById[$rid] = inventory_api_num($sumRow['opening_stock'] ?? 0);
                $closingById[$rid] = inventory_api_num($sumRow['closing_stock'] ?? 0);
                $finalById[$rid] = inventory_api_num($sumRow['final_stock'] ?? 0);
                $finalDifferenceById[$rid] = inventory_api_num($sumRow['final_difference'] ?? 0);
                $missingFinalById[$rid] = (int)($sumRow['missing_final_count'] ?? 0);
                $missingQtyById[$rid] = inventory_api_num($sumRow['missing_qty'] ?? 0);
                $extraQtyById[$rid] = inventory_api_num($sumRow['extra_qty'] ?? 0);
                $stockInById[$rid] = inventory_api_num($sumRow['stock_in'] ?? 0);
                $stockOutById[$rid] = inventory_api_num($sumRow['stock_out'] ?? 0);
            }
        } catch (Throwable $e) {
            // Keep list usable even if detail aggregates fail.
        }

        try {
            if (api_table_exists($pdo, 'eod_eom_report_attachments')) {
                $attachmentSql = "
                    SELECT id, report_id, file_path, original_filename, file_size, mime_type, uploaded_at, uploaded_by
                    FROM eod_eom_report_attachments
                    WHERE report_type = ? AND report_id IN ($placeholders)
                    ORDER BY uploaded_at ASC, id ASC
                ";
                $attachmentStmt = $pdo->prepare($attachmentSql);
                $attachmentStmt->execute(array_merge([$reportType], $ids));
                foreach ($attachmentStmt->fetchAll(PDO::FETCH_ASSOC) as $attachmentRow) {
                    $rid = (int)($attachmentRow['report_id'] ?? 0);
                    if ($rid <= 0) {
                        continue;
                    }
                    $attachmentCountsById[$rid] = ($attachmentCountsById[$rid] ?? 0) + 1;
                    if (isset($attachmentsById[$rid])) {
                        continue;
                    }
                    $filename = inventory_api_str($attachmentRow['original_filename'] ?? '');
                    $mimeType = inventory_api_str($attachmentRow['mime_type'] ?? '');
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $isImage = str_starts_with(strtolower($mimeType), 'image/')
                        || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                    $attachmentsById[$rid] = [
                        'id' => (int)($attachmentRow['id'] ?? 0),
                        'filename' => $filename,
                        'file_size' => (int)($attachmentRow['file_size'] ?? 0),
                        'mime_type' => $mimeType,
                        'uploaded_at' => inventory_api_str($attachmentRow['uploaded_at'] ?? ''),
                        'uploaded_by' => inventory_closing_user_name($pdo, (int)($attachmentRow['uploaded_by'] ?? 0)),
                        'url' => uploaded_file_url(inventory_api_str($attachmentRow['file_path'] ?? ''), 'eod_eom_reports'),
                        'is_image' => $isImage,
                    ];
                }
            }
        } catch (Throwable $e) {
            // Attachment previews are optional for the list.
        }

    }

    $opsByDate = [];
    if ($reportType === 'eod' && $rows) {
        $dates = [];
        foreach ($rows as $row) {
            $d = inventory_api_str($row['report_date'] ?? '');
            if ($d !== '') {
                $dates[] = $d;
            }
        }
        $opsByDate = inventory_closing_eod_ops_totals_by_date($pdo, $dates);
    }

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $status = strtolower(inventory_api_str($row['status'] ?? 'draft')) ?: 'draft';
        $period = $reportType === 'eom'
            ? inventory_api_str($row['report_month'] ?? '')
            : inventory_api_str($row['report_date'] ?? '');
        $closingStock = $closingById[$id] ?? inventory_api_num($row['total_quantity'] ?? 0);
        $reportDate = inventory_api_str($row['report_date'] ?? '');
        $ops = $opsByDate[$reportDate] ?? [];
        $extraIn = inventory_api_num($ops['offline_purchase_back'] ?? 0)
            + inventory_api_num($ops['cancelled_offline_sale'] ?? 0)
            + inventory_api_num($ops['marketing_return'] ?? 0);
        $extraOut = inventory_api_num($ops['total_sold'] ?? 0)
            + inventory_api_num($ops['offline_sale'] ?? 0)
            + inventory_api_num($ops['dealer_sold'] ?? 0)
            + inventory_api_num($ops['offline_cancelled_purchase_back'] ?? 0)
            + inventory_api_num($ops['marketing_take_out'] ?? 0)
            + inventory_api_num($ops['purchase_return_vendor'] ?? 0);
        $stockIn = ($stockInById[$id] ?? 0.0) + $extraIn;
        $stockOut = ($stockOutById[$id] ?? 0.0) + $extraOut;
        if ($reportType === 'eod') {
            $movementTotals = inventory_closing_report_movement_totals($pdo, $reportType, $id, $reportDate, $locationMap);
            $stockIn = $movementTotals['in'];
            $stockOut = $movementTotals['out'];
        }
        $out[] = [
            'id' => $id,
            'report_type' => $reportType,
            'period' => $period,
            'report_date' => $reportDate,
            'report_month' => inventory_api_str($row['report_month'] ?? ''),
            'status' => $status,
            'status_label' => $status === 'finalized' ? 'Finalized' : 'Draft',
            'total_items' => inventory_api_num($row['total_items'] ?? 0),
            'opening_stock' => $openingById[$id] ?? 0.0,
            'stock_in' => $stockIn,
            'stock_out' => $stockOut,
            'total_sold' => inventory_api_num($ops['total_sold'] ?? 0),
            'offline_sale' => inventory_api_num($ops['offline_sale'] ?? 0),
            'dealer_sold' => inventory_api_num($ops['dealer_sold'] ?? 0),
            'offline_purchase_back' => inventory_api_num($ops['offline_purchase_back'] ?? 0),
            'cancelled_offline_sale' => inventory_api_num($ops['cancelled_offline_sale'] ?? 0),
            'offline_cancelled_purchase_back' => inventory_api_num($ops['offline_cancelled_purchase_back'] ?? 0),
            'marketing_take_out' => inventory_api_num($ops['marketing_take_out'] ?? 0),
            'marketing_return' => inventory_api_num($ops['marketing_return'] ?? 0),
            'purchase_return_vendor' => inventory_api_num($ops['purchase_return_vendor'] ?? 0),
            'closing_stock' => $closingStock,
            'final_stock' => $finalById[$id] ?? 0.0,
            'missing_final_count' => $missingFinalById[$id] ?? 0,
            'final_difference' => $finalDifferenceById[$id] ?? 0.0,
            'missing_qty' => $missingQtyById[$id] ?? 0.0,
            'extra_qty' => $extraQtyById[$id] ?? 0.0,
            'total_quantity' => inventory_api_num($row['total_quantity'] ?? 0),
            'total_value' => inventory_api_num($row['total_value'] ?? 0),
            'created_at' => inventory_api_str($row['created_at'] ?? ''),
            'created_by' => inventory_closing_user_name($pdo, (int)($row['created_by'] ?? 0)),
            'finalized_at' => inventory_api_str($row['finalized_at'] ?? ''),
            'finalized_by' => inventory_closing_user_name($pdo, (int)($row['finalized_by'] ?? 0)),
            'difference_reviewed_at' => inventory_api_str($row['difference_reviewed_at'] ?? ''),
            'difference_reviewed_by' => inventory_closing_user_name($pdo, (int)($row['difference_reviewed_by'] ?? 0)),
            'difference_reviewed_by_id' => (int)($row['difference_reviewed_by'] ?? 0),
            'difference_review_notes' => inventory_api_str($row['difference_review_notes'] ?? ''),
            'difference_review_status' => inventory_api_str($row['difference_review_status'] ?? ''),
            'attachment_count' => $attachmentCountsById[$id] ?? 0,
            'attachment_preview' => $attachmentsById[$id] ?? null,
            'notes' => inventory_api_str($row['notes'] ?? ''),
            'label' => strtoupper($reportType) . ' Report - ' . ($period !== '' ? $period : ('#' . $id)),
        ];
    }
    return $out;
}

function inventory_closing_find_report_id(PDO $pdo, string $reportType, int $reportId, string $date, string $month): int
{
    if ($reportId > 0) {
        return $reportId;
    }

    try {
        if ($reportType === 'eom') {
            if ($month === '') {
                $month = date('Y-m');
            }
            $stmt = $pdo->prepare('SELECT id FROM eom_stock_reports WHERE report_month = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$month]);
            return (int)($stmt->fetchColumn() ?: 0);
        }

        if ($date === '') {
            $date = date('Y-m-d');
        }
        $stmt = $pdo->prepare('SELECT id FROM eod_stock_reports WHERE report_date = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$date]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function inventory_closing_get_report(PDO $pdo, string $reportType, int $reportId): ?array
{
    if ($reportId <= 0) {
        return null;
    }

    try {
        if ($reportType === 'eom') {
            $stmt = $pdo->prepare('
                SELECT id, report_month, report_date, created_at, created_by, total_items, total_quantity, total_value,
                       status, notes, finalized_at, finalized_by, difference_reviewed_by, difference_reviewed_at, difference_review_notes, difference_review_status
                FROM eom_stock_reports
                WHERE id = ?
                LIMIT 1
            ');
        } else {
            $stmt = $pdo->prepare('
                SELECT id, report_date, created_at, created_by, total_items, total_quantity, total_value,
                       status, notes, finalized_at, finalized_by, difference_reviewed_by, difference_reviewed_at, difference_review_notes, difference_review_status
                FROM eod_stock_reports
                WHERE id = ?
                LIMIT 1
            ');
        }
        $stmt->execute([$reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $status = strtolower(inventory_api_str($row['status'] ?? 'draft')) ?: 'draft';
        $period = $reportType === 'eom'
            ? inventory_api_str($row['report_month'] ?? '')
            : inventory_api_str($row['report_date'] ?? '');

        return [
            'id' => (int)$row['id'],
            'report_type' => $reportType,
            'period' => $period,
            'report_date' => inventory_api_str($row['report_date'] ?? ''),
            'report_month' => inventory_api_str($row['report_month'] ?? ''),
            'status' => $status,
            'status_label' => $status === 'finalized' ? 'Finalized' : 'Draft',
            'total_items' => inventory_api_num($row['total_items'] ?? 0),
            'total_quantity' => inventory_api_num($row['total_quantity'] ?? 0),
            'total_value' => inventory_api_num($row['total_value'] ?? 0),
            'created_at' => inventory_api_str($row['created_at'] ?? ''),
            'created_by' => inventory_closing_user_name($pdo, (int)($row['created_by'] ?? 0)),
            'finalized_at' => inventory_api_str($row['finalized_at'] ?? ''),
            'finalized_by' => inventory_closing_user_name($pdo, (int)($row['finalized_by'] ?? 0)),
            'difference_reviewed_at' => inventory_api_str($row['difference_reviewed_at'] ?? ''),
            'difference_reviewed_by' => inventory_closing_user_name($pdo, (int)($row['difference_reviewed_by'] ?? 0)),
            'difference_reviewed_by_id' => (int)($row['difference_reviewed_by'] ?? 0),
            'difference_review_notes' => inventory_api_str($row['difference_review_notes'] ?? ''),
            'difference_review_status' => inventory_api_str($row['difference_review_status'] ?? ''),
            'attachments' => inventory_closing_report_attachments($pdo, $reportType, $reportId),
            'notes' => inventory_api_str($row['notes'] ?? ''),
            'label' => strtoupper($reportType) . ' Report - ' . ($period !== '' ? $period : ('#' . (int)$row['id'])),
            'sheet_format' => $reportType === 'eom',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function inventory_closing_eom_teams(PDO $pdo, int $reportId, array $rows = []): array
{
    $teams = [];
    try {
        $stmt = $pdo->prepare('SELECT teams_json FROM eom_stock_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $decoded = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $team) {
                $name = trim((string)($team['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $teams[] = [
                    'id' => (int)($team['id'] ?? 0),
                    'name' => $name,
                ];
            }
        }
    } catch (Throwable $e) {
        $teams = [];
    }
    if ($teams === []) {
        $teams = eod_eom_offline_team_list($pdo);
    }
    $known = [];
    foreach ($teams as $team) {
        $known[(string)$team['id']] = true;
    }
    foreach ($rows as $row) {
        foreach (array_merge(
            array_keys($row['buy_back_by_team'] ?? []),
            array_keys($row['offline_sales_by_team'] ?? [])
        ) as $teamId) {
            $sid = (string)$teamId;
            if (isset($known[$sid])) {
                continue;
            }
            $id = (int)$teamId;
            $teams[] = ['id' => $id, 'name' => $id === 0 ? 'Unassigned' : ('Team #' . $id)];
            $known[$sid] = true;
        }
    }
    return $teams;
}

function inventory_closing_normalize_eom_sheet_rows(PDO $pdo, int $reportId, array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $setKeys = eod_eom_eom_set_keys($pdo, eod_eom_eom_component_map($pdo));
    $grouped = [];
    $sheetSum = 0.0;
    foreach ($rows as $row) {
        $key = mb_strtolower(trim((string)($row['item_name'] ?? '')));
        if ($key === '') {
            continue;
        }
        if (
            eod_eom_eom_is_set((string)($row['item_name'] ?? ''), (string)($row['sku'] ?? ''), $setKeys)
            || strtolower((string)($row['product_type'] ?? '')) === 'set'
        ) {
            continue;
        }
        $row['buy_back_by_team'] = eod_eom_row_team_map($row, 'buy_back');
        $row['offline_sales_by_team'] = eod_eom_row_team_map($row, 'offline_sales');
        $sheetSum += inventory_api_num($row['purchase_received'] ?? 0)
            + inventory_api_num($row['online_sales'] ?? 0)
            + inventory_api_num($row['buy_back_rung'] ?? 0)
            + inventory_api_num($row['offline_sales_rung'] ?? 0)
            + inventory_api_num($row['dealer_sales'] ?? 0)
            + inventory_api_num($row['return_previous_month'] ?? 0)
            + eod_eom_team_map_sum($row['buy_back_by_team'])
            + eod_eom_team_map_sum($row['offline_sales_by_team']);
        if (!isset($grouped[$key])) {
            $grouped[$key] = $row;
            continue;
        }
        $current = $grouped[$key];
        foreach ([
            'opening_quantity', 'closing_quantity', 'quantity_on_hand', 'purchase_received',
            'buy_back_rung', 'buy_back_banha', 'buy_back_van', 'online_sales',
            'offline_sales_rung', 'offline_sales_banha', 'offline_sales_van',
            'dealer_sales', 'marketing_stock_out', 'return_previous_month',
        ] as $field) {
            $current[$field] = inventory_api_num($current[$field] ?? 0) + inventory_api_num($row[$field] ?? 0);
        }
        $current['buy_back_by_team'] = eod_eom_merge_team_maps(
            $current['buy_back_by_team'] ?? [],
            $row['buy_back_by_team'] ?? []
        );
        $current['offline_sales_by_team'] = eod_eom_merge_team_maps(
            $current['offline_sales_by_team'] ?? [],
            $row['offline_sales_by_team'] ?? []
        );
        if (($current['sku'] ?? '') === '' && ($row['sku'] ?? '') !== '') {
            $current['sku'] = $row['sku'];
        }
        $curFinal = $current['final_quantity'];
        $rowFinal = $row['final_quantity'];
        if ($curFinal === null && $rowFinal === null) {
            $current['final_quantity'] = null;
        } else {
            $current['final_quantity'] = inventory_api_num($curFinal ?? 0) + inventory_api_num($rowFinal ?? 0);
        }
        $current['final_location_qty'] = eod_eom_merge_team_maps(
            $current['final_location_qty'] ?? [],
            $row['final_location_qty'] ?? []
        );
        $current['system_location_qty'] = eod_eom_merge_team_maps(
            $current['system_location_qty'] ?? [],
            $row['system_location_qty'] ?? []
        );
        $grouped[$key] = $current;
    }

    $out = array_values($grouped);
    $status = '';
    $month = '';
    try {
        $stmt = $pdo->prepare('SELECT status, report_month FROM eom_stock_reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $meta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = strtolower(trim((string)($meta['status'] ?? '')));
        $month = (string)($meta['report_month'] ?? '');
    } catch (Throwable $e) {
        $status = '';
        $month = '';
    }
    $lookups = [];
    $needLookups = preg_match('/^\d{4}-\d{2}$/', $month)
        && ($sheetSum < 0.0005 || $status !== 'finalized');
    if ($needLookups) {
        $lookups = eod_eom_eom_sheet_lookups($pdo, $month . '-01', date('Y-m-t', strtotime($month . '-01')));
    }
    if ($lookups && $sheetSum < 0.0005) {
        foreach ($out as $index => $row) {
            $key = mb_strtolower(trim((string)($row['item_name'] ?? '')));
            $hit = $lookups[$key] ?? null;
            if (!$hit) {
                continue;
            }
            $out[$index]['purchase_received'] = (float)($hit['purchase_received'] ?? 0);
            $out[$index]['buy_back_by_team'] = eod_eom_decode_team_map($hit['buy_back_by_team'] ?? []);
            $out[$index]['offline_sales_by_team'] = eod_eom_decode_team_map($hit['offline_sales_by_team'] ?? []);
            $out[$index]['online_sales'] = (float)($hit['online_sales'] ?? 0);
            $out[$index]['dealer_sales'] = (float)($hit['dealer_sales'] ?? 0);
            $out[$index]['marketing_stock_out'] = (float)($hit['marketing_stock_out'] ?? 0);
            $out[$index]['return_previous_month'] = (float)($hit['return_previous_month'] ?? 0);
            $out[$index]['adjustments'] = (float)($hit['adjustments'] ?? 0);
            if (inventory_api_num($out[$index]['opening_quantity'] ?? 0) < 0.0005) {
                $out[$index]['opening_quantity'] = (float)($hit['opening'] ?? 0);
            }
        }
    }

    $teams = inventory_closing_eom_teams($pdo, $reportId, $out);
    foreach ($out as $index => $row) {
        $buyBack = eod_eom_row_team_map($row, 'buy_back', $teams);
        $offline = eod_eom_row_team_map($row, 'offline_sales', $teams);
        $sheet = [
            'opening' => inventory_api_num($row['opening_quantity'] ?? 0),
            'purchase_received' => inventory_api_num($row['purchase_received'] ?? 0),
            'buy_back_by_team' => $buyBack,
            'online_sales' => inventory_api_num($row['online_sales'] ?? 0),
            'offline_sales_by_team' => $offline,
            'dealer_sales' => inventory_api_num($row['dealer_sales'] ?? 0),
            'marketing_stock_out' => inventory_api_num($row['marketing_stock_out'] ?? 0),
            'return_previous_month' => inventory_api_num($row['return_previous_month'] ?? 0),
            'adjustments' => inventory_api_num($row['adjustments'] ?? 0),
        ];
        $available = eod_eom_sheet_total_available($sheet);
        $system = eod_eom_sheet_system_closing($sheet);
        $physical = $row['final_quantity'];
        $out[$index]['buy_back_by_team'] = $buyBack;
        $out[$index]['offline_sales_by_team'] = $offline;
        $out[$index]['adjustments'] = inventory_api_num($row['adjustments'] ?? 0);
        $out[$index]['total_available'] = $available;
        $out[$index]['system_closing'] = $system;
        $out[$index]['closing_quantity'] = $system;
        $out[$index]['quantity_on_hand'] = $system;
        $out[$index]['difference'] = $physical === null ? null : inventory_api_num($physical) - $system;
        $out[$index]['sheet_format'] = true;
    }

    $eodSnapshot = preg_match('/^\d{4}-\d{2}$/', $month)
        ? eod_eom_eod_stock_snapshot($pdo, date('Y-m-t', strtotime($month . '-01')))
        : ['items' => [], 'locations' => [], 'report_id' => 0];
    $openingSnapshot = preg_match('/^\d{4}-\d{2}$/', $month)
        ? eod_eom_eod_stock_snapshot($pdo, date('Y-m-t', strtotime($month . '-01 -1 month')))
        : ['items' => [], 'locations' => [], 'report_id' => 0];
    $liveStock = $eodSnapshot['items'] ?: eod_eom_current_stock_by_item($pdo);
    $liveByLoc = $eodSnapshot['locations'] ?: eod_eom_current_stock_by_item_location($pdo);
    foreach ($out as $index => $row) {
        $key = mb_strtolower(trim((string)($row['item_name'] ?? '')));
        $eodCurrent = array_key_exists($key, $eodSnapshot['items'] ?? [])
            ? (float)$eodSnapshot['items'][$key]
            : null;
        $stored = array_key_exists('current_stock', $row) && $row['current_stock'] !== null
            ? inventory_api_num($row['current_stock'])
            : null;
        $live = (float)($liveStock[$key] ?? 0);
        $current = ($eodCurrent !== null) ? $eodCurrent : (($stored !== null) ? $stored : $live);
        $out[$index]['current_stock'] = $current;
        $out[$index]['final_closing_stock'] = $current;
        $systemLoc = eod_eom_decode_team_map($row['system_location_qty'] ?? $row['system_location_qty_json'] ?? []);
        $storedLoc = eod_eom_decode_team_map($row['final_location_qty'] ?? $row['final_location_qty_json'] ?? []);
        $openingLoc = $openingSnapshot['locations'][$key] ?? [];
        $out[$index]['location_qty'] = !empty($systemLoc) ? $systemLoc : ($liveByLoc[$key] ?? []);
        $out[$index]['opening_location_qty'] = $openingLoc;
        $out[$index]['final_location_qty'] = $storedLoc;
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
    });
    return eod_eom_apply_product_codes($pdo, $out);
}

function inventory_closing_detail_rows(PDO $pdo, string $reportType, int $reportId, int $locationId, int $brandId, string $q, array $locationMap): array
{
    if ($reportId <= 0) {
        return [];
    }

    $rows = [];
    try {
        if ($reportType === 'eom') {
            $sheetCols = '';
            if (eod_eom_eom_has_sheet_columns($pdo)) {
                $sheetCols = ',
                    d.purchase_received,
                    d.buy_back_rung,
                    d.buy_back_banha,
                    d.buy_back_van,
                    d.online_sales,
                    d.offline_sales_rung,
                    d.offline_sales_banha,
                    d.offline_sales_van,
                    d.dealer_sales,
                    d.marketing_stock_out,
                    d.return_previous_month';
                if (eod_eom_eom_has_json_columns($pdo)) {
                    $sheetCols .= ',
                    d.buy_back_json,
                    d.offline_sales_json';
                }
            }
            try {
                $curCol = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'current_stock'");
                if ($curCol && $curCol->fetch(PDO::FETCH_ASSOC)) {
                    $sheetCols .= ',
                    d.current_stock';
                }
            } catch (Throwable $e) {
                // ignore
            }
            try {
                $locCol = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'final_location_qty_json'");
                if ($locCol && $locCol->fetch(PDO::FETCH_ASSOC)) {
                    $sheetCols .= ',
                    d.final_location_qty_json';
                }
            } catch (Throwable $e) {
                // ignore
            }
            try {
                $locCol = $pdo->query("SHOW COLUMNS FROM eom_stock_report_details LIKE 'system_location_qty_json'");
                if ($locCol && $locCol->fetch(PDO::FETCH_ASSOC)) {
                    $sheetCols .= ',
                    d.system_location_qty_json';
                }
            } catch (Throwable $e) {
                // ignore
            }
            $sql = '
                SELECT
                    d.id,
                    d.item_name,
                    d.sku,
                    COALESCE(pmeta.product_type, \'normal\') AS product_type,
                    COALESCE(pmeta.brand_id, 0) AS brand_id,
                    COALESCE(pmeta.brand_name, \'\') AS brand_name,
                    d.storage_location_id,
                    d.opening_quantity,
                    d.closing_quantity,
                    d.average_quantity,
                    d.movements_in,
                    d.movements_out,
                    d.unit_cost,
                    d.opening_value,
                    d.closing_value,
                    d.average_value,
                    d.notes,
                    d.final_quantity,
                    d.final_counted_by,
                    d.final_counted_at,
                    sl.location_name,
                    sl.location_code,
                    sl.is_default AS location_is_default
                    ' . $sheetCols . '
                FROM eom_stock_report_details d
                LEFT JOIN storage_locations sl ON sl.id = d.storage_location_id
                LEFT JOIN (
                    SELECT
                        p.name,
                        MIN(LOWER(COALESCE(NULLIF(TRIM(p.product_type), \'\'), \'normal\'))) AS product_type,
                        COALESCE(MAX(p.brand_id), 0) AS brand_id,
                        COALESCE(MAX(NULLIF(TRIM(b.name), \'\')), \'\') AS brand_name
                    FROM products p
                    LEFT JOIN brands b ON b.id = p.brand_id
                    GROUP BY p.name
                ) pmeta ON pmeta.name = d.item_name
                WHERE d.eom_report_id = ?
            ';
            $params = [$reportId];
            if ($locationId > 0) {
                $sql .= ' AND d.storage_location_id = ?';
                $params[] = $locationId;
            }
            if ($brandId > 0) {
                $sql .= ' AND COALESCE(pmeta.brand_id, 0) = ?';
                $params[] = $brandId;
            }
            if ($q !== '') {
                $sql .= ' AND (d.item_name LIKE ? OR d.sku LIKE ? OR COALESCE(pmeta.brand_name, \'\') LIKE ? OR COALESCE(pmeta.product_type, \'\') LIKE ?)';
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $sql .= ' ORDER BY sl.location_name ASC, d.item_name ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $locId = (int)($row['storage_location_id'] ?? 0);
                $opening = inventory_api_num($row['opening_quantity'] ?? 0);
                $closing = inventory_api_num($row['closing_quantity'] ?? 0);
                $rows[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'item_name' => inventory_api_str($row['item_name'] ?? ''),
                    'sku' => inventory_api_str($row['sku'] ?? ''),
                    'product_type' => inventory_api_str($row['product_type'] ?? 'normal'),
                    'product_type_label' => inventory_product_type_label(inventory_api_str($row['product_type'] ?? 'normal')),
                    'brand_id' => (int)($row['brand_id'] ?? 0),
                    'brand_name' => inventory_api_str($row['brand_name'] ?? ''),
                    'storage_location_id' => $locId,
                    'location_name' => inventory_api_str($row['location_name'] ?? '') ?: ($locationMap[$locId] ?? ($locId ? ('Location #' . $locId) : 'Unassigned')),
                    'location_code' => inventory_api_str($row['location_code'] ?? ''),
                    'location_is_default' => (int)($row['location_is_default'] ?? 0) === 1,
                    'opening_quantity' => $opening,
                    'closing_quantity' => $closing,
                    'quantity_on_hand' => $closing,
                    'average_quantity' => inventory_api_num($row['average_quantity'] ?? 0),
                    'movements_in' => inventory_api_num($row['movements_in'] ?? 0),
                    'movements_out' => inventory_api_num($row['movements_out'] ?? 0),
                    'transfer_in' => 0.0,
                    'transfer_out' => 0.0,
                    'daily_received' => 0.0,
                    'return_quantity' => 0.0,
                    'adjustments' => 0.0,
                    'unit_cost' => inventory_api_num($row['unit_cost'] ?? 0),
                    'opening_value' => inventory_api_num($row['opening_value'] ?? 0),
                    'closing_value' => inventory_api_num($row['closing_value'] ?? 0),
                    'total_value' => inventory_api_num($row['closing_value'] ?? 0),
                    'notes' => inventory_api_str($row['notes'] ?? ''),
                    'final_quantity' => $row['final_quantity'] === null ? null : inventory_api_num($row['final_quantity'] ?? 0),
                    'final_counted_by' => inventory_closing_user_name($pdo, (int)($row['final_counted_by'] ?? 0)),
                    'final_counted_at' => inventory_api_str($row['final_counted_at'] ?? ''),
                    'purchase_received' => inventory_api_num($row['purchase_received'] ?? 0),
                    'buy_back_rung' => inventory_api_num($row['buy_back_rung'] ?? 0),
                    'buy_back_banha' => inventory_api_num($row['buy_back_banha'] ?? 0),
                    'buy_back_van' => inventory_api_num($row['buy_back_van'] ?? 0),
                    'online_sales' => inventory_api_num($row['online_sales'] ?? 0),
                    'offline_sales_rung' => inventory_api_num($row['offline_sales_rung'] ?? 0),
                    'offline_sales_banha' => inventory_api_num($row['offline_sales_banha'] ?? 0),
                    'offline_sales_van' => inventory_api_num($row['offline_sales_van'] ?? 0),
                    'dealer_sales' => inventory_api_num($row['dealer_sales'] ?? 0),
                    'marketing_stock_out' => inventory_api_num($row['marketing_stock_out'] ?? 0),
                    'return_previous_month' => inventory_api_num($row['return_previous_month'] ?? $row['return_quantity'] ?? 0),
                    'buy_back_json' => $row['buy_back_json'] ?? null,
                    'offline_sales_json' => $row['offline_sales_json'] ?? null,
                    'buy_back_by_team' => eod_eom_decode_team_map($row['buy_back_json'] ?? []),
                    'offline_sales_by_team' => eod_eom_decode_team_map($row['offline_sales_json'] ?? []),
                    'current_stock' => array_key_exists('current_stock', $row) ? inventory_api_num($row['current_stock'] ?? 0) : null,
                    'system_location_qty' => eod_eom_decode_team_map($row['system_location_qty_json'] ?? []),
                    'final_location_qty' => eod_eom_decode_team_map($row['final_location_qty_json'] ?? []),
                ];
            }
            return inventory_closing_normalize_eom_sheet_rows($pdo, $reportId, $rows);
        }

        $sql = '
            SELECT
                d.id,
                d.item_name,
                d.sku,
                COALESCE(pmeta.product_type, \'normal\') AS product_type,
                COALESCE(pmeta.brand_id, 0) AS brand_id,
                COALESCE(pmeta.brand_name, \'\') AS brand_name,
                d.storage_location_id,
                d.quantity_on_hand,
                d.available_quantity,
                d.opening_quantity,
                d.daily_received,
                d.return_quantity,
                d.movements_in,
                d.movements_out,
                d.transfer_in,
                d.transfer_out,
                d.adjustments,
                d.unit_cost,
                d.total_value,
                d.notes,
                d.final_quantity,
                d.final_counted_by,
                d.final_counted_at,
                sl.location_name,
                sl.location_code,
                sl.is_default AS location_is_default
            FROM eod_stock_report_details d
            LEFT JOIN storage_locations sl ON sl.id = d.storage_location_id
            LEFT JOIN (
                SELECT
                    p.name,
                    MIN(LOWER(COALESCE(NULLIF(TRIM(p.product_type), \'\'), \'normal\'))) AS product_type,
                    COALESCE(MAX(p.brand_id), 0) AS brand_id,
                    COALESCE(MAX(NULLIF(TRIM(b.name), \'\')), \'\') AS brand_name
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id
                GROUP BY p.name
            ) pmeta ON pmeta.name = d.item_name
            WHERE d.eod_report_id = ?
        ';
        $params = [$reportId];
        if ($locationId > 0) {
            $sql .= ' AND d.storage_location_id = ?';
            $params[] = $locationId;
        }
        if ($brandId > 0) {
            $sql .= ' AND COALESCE(pmeta.brand_id, 0) = ?';
            $params[] = $brandId;
        }
        if ($q !== '') {
            $sql .= ' AND (d.item_name LIKE ? OR d.sku LIKE ? OR COALESCE(pmeta.brand_name, \'\') LIKE ? OR COALESCE(pmeta.product_type, \'\') LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY sl.location_name ASC, d.item_name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locId = (int)($row['storage_location_id'] ?? 0);
            $onHand = inventory_api_num($row['quantity_on_hand'] ?? 0);
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'item_name' => inventory_api_str($row['item_name'] ?? ''),
                'sku' => inventory_api_str($row['sku'] ?? ''),
                'product_type' => inventory_api_str($row['product_type'] ?? 'normal'),
                'product_type_label' => inventory_product_type_label(inventory_api_str($row['product_type'] ?? 'normal')),
                'brand_id' => (int)($row['brand_id'] ?? 0),
                'brand_name' => inventory_api_str($row['brand_name'] ?? ''),
                'storage_location_id' => $locId,
                'location_name' => inventory_api_str($row['location_name'] ?? '') ?: ($locationMap[$locId] ?? ($locId ? ('Location #' . $locId) : 'Unassigned')),
                'location_code' => inventory_api_str($row['location_code'] ?? ''),
                'location_is_default' => (int)($row['location_is_default'] ?? 0) === 1,
                'opening_quantity' => inventory_api_num($row['opening_quantity'] ?? 0),
                'closing_quantity' => $onHand,
                'quantity_on_hand' => $onHand,
                'available_quantity' => inventory_api_num($row['available_quantity'] ?? $onHand),
                'average_quantity' => 0.0,
                'daily_received' => inventory_api_num($row['daily_received'] ?? 0),
                'return_quantity' => inventory_api_num($row['return_quantity'] ?? 0),
                'movements_in' => inventory_api_num($row['movements_in'] ?? 0),
                'movements_out' => inventory_api_num($row['movements_out'] ?? 0),
                'transfer_in' => inventory_api_num($row['transfer_in'] ?? 0),
                'transfer_out' => inventory_api_num($row['transfer_out'] ?? 0),
                'adjustments' => inventory_api_num($row['adjustments'] ?? 0),
                'total_sold' => 0.0,
                'offline_sale' => 0.0,
                'unit_cost' => inventory_api_num($row['unit_cost'] ?? 0),
                'opening_value' => 0.0,
                'closing_value' => inventory_api_num($row['total_value'] ?? 0),
                'total_value' => inventory_api_num($row['total_value'] ?? 0),
                'notes' => inventory_api_str($row['notes'] ?? ''),
                'final_quantity' => $row['final_quantity'] === null ? null : inventory_api_num($row['final_quantity'] ?? 0),
                'final_counted_by' => inventory_closing_user_name($pdo, (int)($row['final_counted_by'] ?? 0)),
                'final_counted_at' => inventory_api_str($row['final_counted_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }

    if ($reportType === 'eod') {
        $rows = eod_eom_apply_product_codes($pdo, $rows);
    }

    return $rows;
}

try {
    $reports = inventory_closing_list_reports(
        $pdo,
        $reportType,
        $limit,
        $fromDate,
        $toDate,
        $fromMonth,
        $toMonth,
        $statusFilter,
        $locationMap
    );
} catch (Throwable $e) {
    api_json([
        'success' => false,
        'message' => 'Unable to load closing reports. EOD/EOM tables may be missing.',
        'reports' => [],
        'rows' => [],
    ], 500);
}

if ($listOnly) {
    api_json([
        'success' => true,
        'report_type' => $reportType,
        'reports' => $reports,
        'filters' => [
            'report_type' => $reportType,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'from_month' => $fromMonth,
            'to_month' => $toMonth,
            'status' => $statusFilter,
        ],
        'location_options' => inventory_location_options($pdo),
        'brand_options' => inventory_brand_options($pdo),
    ]);
}

$resolvedId = inventory_closing_find_report_id($pdo, $reportType, $reportId, $date, $month);
// Only fall back to latest when no specific report/date/month was requested.
$askedSpecific = $reportId > 0
    || ($reportType === 'eom' && $month !== '')
    || ($reportType === 'eod' && $date !== '');
if ($resolvedId <= 0 && !$askedSpecific && $reports) {
    $resolvedId = (int)($reports[0]['id'] ?? 0);
}

$report = inventory_closing_get_report($pdo, $reportType, $resolvedId);
$rows = $report ? inventory_closing_detail_rows($pdo, $reportType, $resolvedId, $locationId, $brandId, $q, $locationMap) : [];
if ($report && $reportType === 'eod') {
    $rows = inventory_closing_attach_sales_to_rows(
        $pdo,
        $rows,
        inventory_api_str($report['report_date'] ?? $report['period'] ?? '')
    );
}
$teams = ($report && $reportType === 'eom') ? inventory_closing_eom_teams($pdo, $resolvedId, $rows) : [];
if ($report && $reportType === 'eom') {
    $report['teams'] = $teams;
}

$summary = [
    'row_count' => count($rows),
    'total_qty' => 0.0,
    'final_qty' => 0.0,
    'missing_qty' => 0.0,
    'extra_qty' => 0.0,
    'net_difference' => 0.0,
    'missing_final_count' => 0,
    'total_value' => 0.0,
    'transfer_in' => 0.0,
    'transfer_out' => 0.0,
    'movements_in' => 0.0,
    'movements_out' => 0.0,
    'total_sold' => 0.0,
    'dealer_sold' => 0.0,
    'offline_sale' => 0.0,
    'opening_stock' => 0.0,
    'purchase_received' => 0.0,
    'buy_back' => 0.0,
    'total_available' => 0.0,
    'online_sales' => 0.0,
    'offline_sales' => 0.0,
    'marketing_stock_out' => 0.0,
    'return_previous_month' => 0.0,
];
foreach ($rows as $row) {
    $systemQty = inventory_api_num($row['system_closing'] ?? $row['quantity_on_hand'] ?? $row['closing_quantity'] ?? 0);
    $summary['total_qty'] += $systemQty;
    if (($row['final_quantity'] ?? null) === null) {
        $summary['missing_final_count']++;
    } else {
        $finalQty = inventory_api_num($row['final_quantity'] ?? 0);
        $difference = $finalQty - $systemQty;
        $summary['final_qty'] += $finalQty;
        $summary['net_difference'] += $difference;
        if ($difference < 0) {
            $summary['missing_qty'] += abs($difference);
        } elseif ($difference > 0) {
            $summary['extra_qty'] += $difference;
        }
    }
    $summary['total_value'] += inventory_api_num($row['total_value'] ?? $row['closing_value'] ?? 0);
    $summary['transfer_in'] += inventory_api_num($row['transfer_in'] ?? 0);
    $summary['transfer_out'] += inventory_api_num($row['transfer_out'] ?? 0);
    $summary['movements_in'] += inventory_api_num($row['movements_in'] ?? 0);
    $summary['movements_out'] += inventory_api_num($row['movements_out'] ?? 0);
    $summary['total_sold'] += inventory_api_num($row['total_sold'] ?? $row['online_sales'] ?? 0);
    $summary['dealer_sold'] += inventory_api_num($row['dealer_sold'] ?? $row['dealer_sales'] ?? 0);
    $buyBackSum = eod_eom_team_map_sum($row['buy_back_by_team'] ?? []);
    if (abs($buyBackSum) < 0.0005) {
        $buyBackSum = inventory_api_num($row['buy_back_rung'] ?? 0)
            + inventory_api_num($row['buy_back_banha'] ?? 0)
            + inventory_api_num($row['buy_back_van'] ?? 0);
    }
    $offlineSum = eod_eom_team_map_sum($row['offline_sales_by_team'] ?? []);
    if (abs($offlineSum) < 0.0005) {
        $offlineSum = inventory_api_num($row['offline_sales_rung'] ?? 0)
            + inventory_api_num($row['offline_sales_banha'] ?? 0)
            + inventory_api_num($row['offline_sales_van'] ?? 0);
    }
    $summary['offline_sale'] += inventory_api_num($row['offline_sale'] ?? 0) + $offlineSum;
    $summary['opening_stock'] += inventory_api_num($row['opening_quantity'] ?? 0);
    $summary['purchase_received'] += inventory_api_num($row['purchase_received'] ?? 0);
    $summary['buy_back'] += $buyBackSum;
    $summary['total_available'] += inventory_api_num($row['total_available'] ?? 0);
    $summary['online_sales'] += inventory_api_num($row['online_sales'] ?? 0);
    $summary['offline_sales'] += $offlineSum;
    $summary['marketing_stock_out'] += inventory_api_num($row['marketing_stock_out'] ?? 0);
    $summary['return_previous_month'] += inventory_api_num($row['return_previous_month'] ?? 0);
}

api_json([
    'success' => true,
    'report_type' => $reportType,
    'report_id' => $resolvedId,
    'report' => $report,
    'reports' => $reports,
    'teams' => $teams,
    'summary' => $summary,
    'rows' => $rows,
    'filters' => [
        'report_type' => $reportType,
        'report_id' => $resolvedId,
        'location_id' => $locationId,
        'brand_id' => $brandId,
        'q' => $q,
        'date' => $date,
        'month' => $month,
    ],
    'location_options' => inventory_location_options($pdo),
    'brand_options' => inventory_brand_options($pdo),
]);
