<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function op_print_date(?string $value): ?string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function op_print_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function op_print_yesterday(): string
{
    return date('Y-m-d', strtotime('-1 day'));
}

function op_print_is_eod_finalized(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eod_stock_reports WHERE report_date = ? AND status = 'finalized'");
    $stmt->execute([op_print_yesterday()]);
    return (int)$stmt->fetchColumn() > 0;
}

function op_print_payload(): array
{
    $raw = (string)file_get_contents('php://input');
    $data = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function op_print_active_sellers(PDO $pdo, bool $telegramOnly = false): array
{
    $where = "role = 'seller' AND active = 1";
    if ($telegramOnly) {
        $where .= " AND telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''";
    }
    return op_print_rows($pdo, "
        SELECT id AS value, COALESCE(NULLIF(name, ''), username, CONCAT('Seller #', id)) AS label
        FROM users
        WHERE {$where}
        ORDER BY label
    ");
}

function op_print_normalize_ids(mixed $ids): array
{
    if (!is_array($ids)) {
        $ids = explode(',', (string)$ids);
    }
    return array_values(array_unique(array_filter(array_map(static fn($id) => (int)$id, $ids), static fn($id) => $id > 0)));
}

try {
    $pdo = get_db_connection();
    $action = strtolower(trim((string)($_GET['action'] ?? 'orders')));

    if ($action === 'options') {
        require_role_or_permission(['cashier', 'admin'], 'print_orders.view', 'print_history.view');
        api_json([
            'success' => true,
            'options' => [
                'delivery_types' => op_print_rows($pdo, 'SELECT id AS value, name AS label FROM delivery_types ORDER BY name'),
                'brands' => op_print_rows($pdo, 'SELECT id AS value, name AS label, color AS brand_color FROM brands WHERE active = 1 ORDER BY name'),
                'cashiers' => op_print_rows($pdo, '
                    SELECT DISTINCT u.id AS value, u.name AS label
                    FROM users u
                    JOIN print_jobs pj ON pj.cashier_id = u.id
                    ORDER BY u.name
                '),
            ],
        ]);
    }

    if ($action === 'broadcast_options') {
        require_role_or_permission(['cashier', 'admin'], 'broadcast.view');
        api_json([
            'success' => true,
            'default_message' => 'បិទស្តុកថ្ងៃទី ' . date('Y-m-d'),
            'sellers' => op_print_active_sellers($pdo, true),
        ]);
    }

    if ($action === 'orders') {
        require_role_or_permission(['cashier', 'admin'], 'sr_sales_dashboard.view', 'print_orders.view');
        $from = op_print_date($_GET['from'] ?? null);
        $to = op_print_date($_GET['to'] ?? null);
        $printed = strtolower(trim((string)($_GET['printed'] ?? 'no')));
        $deliveryTypeId = filter_var($_GET['delivery_type_id'] ?? null, FILTER_VALIDATE_INT);
        $paymentStatus = strtolower(trim((string)($_GET['payment_status'] ?? '')));
        $brandId = filter_var($_GET['brand_id'] ?? null, FILTER_VALIDATE_INT);
        $q = trim((string)($_GET['q'] ?? ''));

        $where = ['COALESCE(o.is_cancelled, 0) = 0'];
        $params = [];
        if ($from) {
            $where[] = 'DATE(o.created_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[] = 'DATE(o.created_at) <= ?';
            $params[] = $to;
        }
        if ($printed === 'no') {
            $where[] = 'pj.order_id IS NULL';
        } elseif ($printed === 'yes') {
            $where[] = 'pj.order_id IS NOT NULL';
        }
        if ($deliveryTypeId !== false && $deliveryTypeId !== null) {
            $where[] = 'o.delivery_type_id = ?';
            $params[] = (int)$deliveryTypeId;
        }
        if (in_array($paymentStatus, ['paid', 'unpaid'], true)) {
            $where[] = $paymentStatus === 'paid'
                ? '(COALESCE(o.is_paid, 0) = 1 OR o.status = "paid")'
                : 'NOT (COALESCE(o.is_paid, 0) = 1 OR o.status = "paid")';
        }
        if ($brandId !== false && $brandId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM order_items filter_oi
                JOIN products filter_p ON filter_p.id = filter_oi.product_id
                WHERE filter_oi.order_id = o.id
                  AND filter_p.brand_id = ?
            )';
            $params[] = (int)$brandId;
        }
        if ($q !== '') {
            $where[] = '(o.order_code LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR u.name LIKE ? OR dt.name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = op_print_rows($pdo, "
            SELECT
                o.id,
                o.created_at,
                o.order_code,
                COALESCE(o.customer_name, '') AS customer_name,
                COALESCE(o.phone, '') AS phone,
                COALESCE(NULLIF(u.name, ''), u.username, '') AS seller_name,
                COALESCE(dt.name, '') AS delivery_type_name,
                CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN 'paid' ELSE 'unpaid' END AS payment_status,
                COALESCE(o.total_amount, 0) AS total_amount,
                CASE WHEN pj.order_id IS NULL THEN 0 ELSE 1 END AS is_printed,
                pj.printed_at,
                COALESCE(items.product_lines, '') AS product_lines,
                COALESCE(items.brand_names, '') AS brand_names
            FROM orders o
            LEFT JOIN users u ON u.id = o.seller_id
            LEFT JOIN delivery_types dt ON dt.id = o.delivery_type_id
            LEFT JOIN (
                SELECT order_id, MAX(printed_at) AS printed_at
                FROM print_jobs
                GROUP BY order_id
            ) pj ON pj.order_id = o.id
            LEFT JOIN (
                SELECT
                    oi.order_id,
                    GROUP_CONCAT(CONCAT(p.name, ' x', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(oi.quantity, 2)))) ORDER BY oi.id SEPARATOR '||') AS product_lines,
                    GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR ', ') AS brand_names
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                GROUP BY oi.order_id
            ) items ON items.order_id = o.id
            {$whereSql}
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 500
        ", $params);

        $paid = 0;
        $unpaid = 0;
        foreach ($rows as $row) {
            if (($row['payment_status'] ?? '') === 'paid') {
                $paid++;
            } else {
                $unpaid++;
            }
        }

        api_json([
            'success' => true,
            'summary' => [
                'total_orders' => count($rows),
                'paid_orders' => $paid,
                'unpaid_orders' => $unpaid,
                'printed_orders' => count(array_filter($rows, static fn($row) => (int)($row['is_printed'] ?? 0) === 1)),
            ],
            'rows' => $rows,
        ]);
    }

    if ($action === 'print_guard') {
        require_role_or_permission(['cashier', 'admin'], 'sr_sales_dashboard.view', 'print_orders.view');
        $idsParam = trim((string)($_GET['ids'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_map(static fn($id) => (int)trim((string)$id), explode(',', $idsParam)), static fn($id) => $id > 0)));
        if (!$ids) {
            api_error('Select at least one order before printing.', 422);
        }

        $canPrint = op_print_is_eod_finalized($pdo);
        api_json([
            'success' => true,
            'can_print' => $canPrint,
            'eod_date' => op_print_yesterday(),
            'title' => $canPrint ? '' : 'Cannot Print Orders',
            'message' => $canPrint ? '' : 'End of Day (EOD) is not finalized for yesterday. Please finalize EOD before printing.',
        ]);
    }

    if ($action === 'send_message') {
        require_role_or_permission(['cashier', 'admin'], 'broadcast.view');
        require_once __DIR__ . '/../../helpers.php';
        require_once __DIR__ . '/../../sold_products_report_lib.php';
        require_once __DIR__ . '/../../user_activity_lib.php';

        $payload = op_print_payload();
        $message = trim((string)($payload['message'] ?? ''));
        $targetMode = (string)($payload['target_mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
        $targetIds = op_print_normalize_ids($payload['target_ids'] ?? []);

        if ($message === '') {
            api_error('Message is required.', 422);
        }
        if ($targetMode === 'selected' && !$targetIds) {
            api_error('Please select at least one seller.', 422);
        }

        global $TELEGRAM_BOT_TOKEN;
        if (empty($TELEGRAM_BOT_TOKEN)) {
            api_error('Telegram bot is not configured.', 422);
        }

        if (stripos($message, '#stop') !== false) {
            $stmtChk = $pdo->prepare(
                "SELECT pe.inv, pe.datetime
                 FROM product_entries pe
                 LEFT JOIN out_items oi ON oi.inv = pe.inv
                 LEFT JOIN orders o ON o.order_code = pe.inv
                 WHERE oi.id IS NULL
                   AND TIMESTAMPDIFF(HOUR, pe.datetime, NOW()) >= 24
                   AND pe.datetime >= ?
                   AND (o.id IS NULL OR o.is_returned = 0)
                 ORDER BY pe.datetime ASC
                 LIMIT 1000"
            );
            $stmtChk->execute(['2026-03-13 00:00:00']);
            $overdue = $stmtChk->fetchAll(PDO::FETCH_ASSOC);
            if ($overdue) {
                api_error('Cannot send #stop: Some prepared items exceed 24h without Out scan.', 409, ['overdue_items' => $overdue]);
            }

            $stmtMissing = $pdo->prepare(
                "SELECT o.order_code AS inv, pj.printed_at
                 FROM print_jobs pj
                 JOIN orders o ON o.id = pj.order_id
                 LEFT JOIN product_entries pe ON pe.inv = o.order_code
                 WHERE pj.printed_at <= (NOW() - INTERVAL 24 HOUR)
                   AND pj.printed_at >= ?
                   AND o.is_cancelled = 0
                   AND o.is_returned = 0
                   AND pe.id IS NULL
                 ORDER BY pj.printed_at ASC
                 LIMIT 1000"
            );
            $stmtMissing->execute(['2026-03-16 00:00:00']);
            $missingPrepared = $stmtMissing->fetchAll(PDO::FETCH_ASSOC);
            if ($missingPrepared) {
                api_error('Cannot send #stop: Some printed orders are missing in Prepared Items.', 409, ['missing_prepared_orders' => $missingPrepared]);
            }

            $today = new DateTimeImmutable('today');
            $current = get_qr_effective_date($pdo);
            $newDate = ($current === null || $current <= $today) ? $today->modify('+1 day') : $current;
            set_qr_effective_date($pdo, $newDate);

            try {
                $reportDate = date('Y-m-d');
                sprlSendSoldProductsReport($pdo, $reportDate, $reportDate);
            } catch (Throwable $e) {
                error_log('operation send_message #stop report error: ' . $e->getMessage());
            }
        }

        $where = "role = 'seller' AND active = 1 AND telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''";
        $params = [];
        if ($targetMode === 'selected') {
            $where .= ' AND id IN (' . implode(',', array_fill(0, count($targetIds), '?')) . ')';
            $params = $targetIds;
        }

        $stmt = $pdo->prepare("SELECT id, telegram_chat_id, telegram_thread_id FROM users WHERE {$where} ORDER BY name");
        $stmt->execute($params);
        $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$sellers) {
            api_error('No sellers have Telegram chat IDs configured for this selection.', 422);
        }

        $user = current_user();
        $sender = trim((string)($user['name'] ?? $user['username'] ?? 'Report System'));
        $textToSend = '[' . $sender . '] ' . $message;
        $sent = 0;
        $failed = 0;
        $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";

        foreach ($sellers as $seller) {
            $data = [
                'chat_id' => (string)$seller['telegram_chat_id'],
                'text' => $textToSend,
            ];
            if (($seller['telegram_thread_id'] ?? '') !== '') {
                $data['message_thread_id'] = (int)$seller['telegram_thread_id'];
            }
            $context = stream_context_create([
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($data),
                    'timeout' => 5,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            $ok = is_string($result) && ($decoded = json_decode($result, true)) && !empty($decoded['ok']);
            $ok ? $sent++ : $failed++;
        }

        user_activity_log_module_mutation(
            $user,
            'cashier',
            'create',
            __FILE__,
            ($targetMode === 'selected' ? 'selected' : 'all') . ' · ' . $sent . ' seller(s)'
        );

        api_json([
            'success' => $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'message' => $failed > 0
                ? "Message sent to {$sent} seller(s), {$failed} failed."
                : "Message sent to {$sent} seller(s).",
        ]);
    }

    if ($action === 'sessions') {
        require_role_or_permission(['cashier', 'admin'], 'print_history.view');
        $from = op_print_date($_GET['from'] ?? null);
        $to = op_print_date($_GET['to'] ?? null);
        $cashierId = filter_var($_GET['cashier_id'] ?? null, FILTER_VALIDATE_INT);
        $where = ['pj.printed_at IS NOT NULL'];
        $params = [];
        if ($from) {
            $where[] = 'DATE(pj.printed_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[] = 'DATE(pj.printed_at) <= ?';
            $params[] = $to;
        }
        if ($cashierId !== false && $cashierId !== null) {
            $where[] = 'pj.cashier_id = ?';
            $params[] = (int)$cashierId;
        }
        $whereSql = implode(' AND ', $where);
        $sessions = op_print_rows($pdo, "
            SELECT
                pj.printed_at,
                pj.cashier_id,
                COALESCE(NULLIF(u.name, ''), u.username, '') AS cashier_name,
                COUNT(DISTINCT pj.order_id) AS orders_count,
                COALESCE(SUM(oi.quantity), 0) AS items_count
            FROM print_jobs pj
            JOIN users u ON u.id = pj.cashier_id
            LEFT JOIN order_items oi ON oi.order_id = pj.order_id
            WHERE {$whereSql}
            GROUP BY pj.printed_at, pj.cashier_id, u.name, u.username
            ORDER BY pj.printed_at DESC
            LIMIT 500
        ", $params);
        api_json([
            'success' => true,
            'summary' => [
                'total_sessions' => count($sessions),
                'total_orders' => array_sum(array_map(static fn($row) => (int)($row['orders_count'] ?? 0), $sessions)),
                'total_items' => array_sum(array_map(static fn($row) => (float)($row['items_count'] ?? 0), $sessions)),
            ],
            'rows' => $sessions,
        ]);
    }

    if ($action === 'printed_orders') {
        require_role_or_permission(['cashier', 'admin'], 'print_history.view');
        $from = op_print_date($_GET['from'] ?? null);
        $to = op_print_date($_GET['to'] ?? null);
        $cashierId = filter_var($_GET['cashier_id'] ?? null, FILTER_VALIDATE_INT);
        $q = trim((string)($_GET['q'] ?? ''));

        $where = ['latest.printed_at IS NOT NULL'];
        $params = [];
        if ($from) {
            $where[] = 'DATE(latest.printed_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[] = 'DATE(latest.printed_at) <= ?';
            $params[] = $to;
        }
        if ($cashierId !== false && $cashierId !== null) {
            $where[] = 'latest.cashier_id = ?';
            $params[] = (int)$cashierId;
        }
        if ($q !== '') {
            $where[] = '(o.order_code LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR cashier.name LIKE ? OR seller.name LIKE ? OR dt.name LIKE ? OR items.product_lines LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = op_print_rows($pdo, "
            SELECT
                o.id,
                o.created_at,
                o.order_code,
                COALESCE(o.customer_name, '') AS customer_name,
                COALESCE(o.phone, '') AS phone,
                COALESCE(NULLIF(seller.name, ''), seller.username, '') AS seller_name,
                COALESCE(dt.name, '') AS delivery_type_name,
                CASE WHEN COALESCE(o.is_paid, 0) = 1 OR o.status = 'paid' THEN 'paid' ELSE 'unpaid' END AS payment_status,
                COALESCE(o.total_amount, 0) AS total_amount,
                latest.printed_at,
                COALESCE(NULLIF(cashier.name, ''), cashier.username, '') AS cashier_name,
                COALESCE(items.product_lines, '') AS product_lines
            FROM (
                SELECT pj.order_id, pj.printed_at, MIN(pj.cashier_id) AS cashier_id
                FROM print_jobs pj
                JOIN (
                    SELECT order_id, MAX(printed_at) AS printed_at
                    FROM print_jobs
                    WHERE printed_at IS NOT NULL
                    GROUP BY order_id
                ) latest_print ON latest_print.order_id = pj.order_id AND latest_print.printed_at = pj.printed_at
                WHERE pj.printed_at IS NOT NULL
                GROUP BY pj.order_id, pj.printed_at
            ) latest
            JOIN orders o ON o.id = latest.order_id
            LEFT JOIN users cashier ON cashier.id = latest.cashier_id
            LEFT JOIN users seller ON seller.id = o.seller_id
            LEFT JOIN delivery_types dt ON dt.id = o.delivery_type_id
            LEFT JOIN (
                SELECT
                    oi.order_id,
                    GROUP_CONCAT(CONCAT(p.name, ' x', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(oi.quantity, 2)))) ORDER BY oi.id SEPARATOR '||') AS product_lines
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                GROUP BY oi.order_id
            ) items ON items.order_id = o.id
            {$whereSql}
            ORDER BY latest.printed_at DESC, o.id DESC
            LIMIT 500
        ", $params);

        $paid = 0;
        $unpaid = 0;
        $totalAmount = 0.0;
        foreach ($rows as $row) {
            if (($row['payment_status'] ?? '') === 'paid') {
                $paid++;
            } else {
                $unpaid++;
            }
            $totalAmount += (float)($row['total_amount'] ?? 0);
        }

        api_json([
            'success' => true,
            'summary' => [
                'printed_orders' => count($rows),
                'paid_orders' => $paid,
                'unpaid_orders' => $unpaid,
                'total_amount' => $totalAmount,
            ],
            'rows' => $rows,
        ]);
    }

    if ($action === 'session_detail') {
        require_role_or_permission(['cashier', 'admin'], 'print_history.view');
        $printedAt = trim((string)($_GET['printed_at'] ?? ''));
        $cashierId = filter_var($_GET['cashier_id'] ?? null, FILTER_VALIDATE_INT);
        if ($printedAt === '') {
            api_error('Printed time is required.');
        }
        $where = 'pj.printed_at = ?';
        $params = [$printedAt];
        if ($cashierId !== false && $cashierId !== null) {
            $where .= ' AND pj.cashier_id = ?';
            $params[] = (int)$cashierId;
        }
        $rows = op_print_rows($pdo, "
            SELECT
                p.name AS product_name,
                SUM(oi.quantity) AS quantity,
                COUNT(DISTINCT oi.order_id) AS order_count
            FROM print_jobs pj
            JOIN order_items oi ON oi.order_id = pj.order_id
            JOIN products p ON p.id = oi.product_id
            WHERE {$where}
            GROUP BY p.id, p.name
            ORDER BY p.name
        ", $params);
        api_json(['success' => true, 'rows' => $rows]);
    }

    api_error('Unknown operation print action.', 404);
} catch (Throwable $e) {
    error_log('operation_print API error: ' . $e->getMessage());
    api_error('Unable to load operation print data.', 500);
}
