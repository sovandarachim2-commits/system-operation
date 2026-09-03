<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_role_or_permission(['admin'], 'sr_month_end_closing.view', 'sr_income_statement.view', 'sr_bank_balances.view', 'sr_inventory_closing.view');

const MEC_STEPS = [
    'sales' => 'Sales Closing',
    'receivable' => 'Accounts Receivable',
    'purchase' => 'Purchase / Supplier Closing',
    'payable' => 'Accounts Payable',
    'cash_bank' => 'Cash & Bank Reconciliation',
    'inventory' => 'Inventory Reconciliation',
    'marketing_stock' => 'Marketing Stock Reconciliation',
    'adjustments' => 'Stock Adjustments',
    'expenses' => 'Expense Closing',
    'final_review' => 'Final Review',
];

function mec_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function mec_month(?string $value): string
{
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return substr($value, 0, 7);
    }
    return date('Y-m');
}

function mec_month_start(string $month): string
{
    return $month . '-01';
}

function mec_month_end(string $month): string
{
    [$year, $mon] = array_map('intval', explode('-', $month));
    return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $mon)));
}

function mec_current_user_id(): ?int
{
    $user = function_exists('current_user') ? (current_user() ?: []) : [];
    $id = (int)($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function mec_user_name(PDO $pdo, mixed $id): string
{
    $id = (int)$id;
    if ($id <= 0 || !api_table_exists($pdo, 'users')) return '';
    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(name, ''), username, CONCAT('User #', id)) FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: '');
}

function mec_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_fill_keys(array_map(static fn(array $row): string => (string)$row['name'], api_table_columns($pdo, $table)), true);
    }
    return $cache[$table];
}

function mec_has(PDO $pdo, string $table, string $column): bool
{
    $cols = mec_columns($pdo, $table);
    return isset($cols[$column]);
}

function mec_pick(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (mec_has($pdo, $table, $column)) return $column;
    }
    return null;
}

function mec_online_amount_expr(PDO $pdo): string
{
    $parts = [];
    foreach (['total_amount', 'grand_total', 'amount'] as $column) {
        if (mec_has($pdo, 'orders', $column)) {
            $parts[] = 'o.' . api_quote_identifier($column);
        }
    }
    if (!$parts) {
        return '0';
    }
    return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ', 0)';
}

function mec_date_expr(PDO $pdo, string $table, array $candidates): ?string
{
    $parts = [];
    foreach ($candidates as $column) {
        if (mec_has($pdo, $table, $column)) $parts[] = api_quote_identifier($column);
    }
    if (!$parts) return null;
    return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
}

function mec_sum_between(PDO $pdo, string $table, array $amountCandidates, array $dateCandidates, string $from, string $to, string $extraWhere = ''): float
{
    if (!api_table_exists($pdo, $table)) return 0.0;
    $amount = mec_pick($pdo, $table, $amountCandidates);
    $dateExpr = mec_date_expr($pdo, $table, $dateCandidates);
    if (!$amount || !$dateExpr) return 0.0;
    $where = "DATE($dateExpr) BETWEEN ? AND ?" . ($extraWhere !== '' ? " AND ($extraWhere)" : '');
    $sql = 'SELECT COALESCE(SUM(' . api_quote_identifier($amount) . '), 0) FROM ' . api_quote_identifier($table) . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    return (float)$stmt->fetchColumn();
}

function mec_count_between(PDO $pdo, string $table, array $dateCandidates, string $from, string $to, string $extraWhere = ''): int
{
    if (!api_table_exists($pdo, $table)) return 0;
    $dateExpr = mec_date_expr($pdo, $table, $dateCandidates);
    if (!$dateExpr) return 0;
    $where = "DATE($dateExpr) BETWEEN ? AND ?" . ($extraWhere !== '' ? " AND ($extraWhere)" : '');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . api_quote_identifier($table) . ' WHERE ' . $where);
    $stmt->execute([$from, $to]);
    return (int)$stmt->fetchColumn();
}

function mec_online_print_join_sql(PDO $pdo): string
{
    if (!api_table_exists($pdo, 'print_jobs')) {
        return '';
    }
    return 'LEFT JOIN (
        SELECT order_id, MAX(printed_at) AS printed_at
        FROM print_jobs
        GROUP BY order_id
    ) pj ON pj.order_id = o.id';
}

function mec_online_print_date_expr(PDO $pdo): string
{
    if (api_table_exists($pdo, 'print_jobs')) {
        return 'COALESCE(pj.printed_at, o.created_at)';
    }
    $expr = mec_date_expr($pdo, 'orders', ['payment_activity_date', 'payment_date', 'order_date', 'created_at']);
    if (!$expr) {
        return 'o.created_at';
    }
    return preg_replace('/`([^`]+)`/', 'o.`$1`', $expr);
}

function mec_online_status_in(array $statuses): string
{
    $quoted = array_map(static fn(string $status): string => "'" . strtolower($status) . "'", $statuses);
    return "LOWER(TRIM(COALESCE(o.status, ''))) IN (" . implode(',', $quoted) . ')';
}

function mec_sum_online_between(PDO $pdo, array $amountCandidates, string $from, string $to, string $extraWhere = ''): float
{
    if (!api_table_exists($pdo, 'orders')) return 0.0;
    $amount = mec_pick($pdo, 'orders', $amountCandidates);
    if (!$amount) return 0.0;
    $dateExpr = mec_online_print_date_expr($pdo);
    $join = mec_online_print_join_sql($pdo);
    $where = "DATE($dateExpr) BETWEEN ? AND ?" . ($extraWhere !== '' ? " AND ($extraWhere)" : '');
    $sql = 'SELECT COALESCE(SUM(o.' . api_quote_identifier($amount) . "), 0) FROM orders o $join WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    return (float)$stmt->fetchColumn();
}

function mec_count_online_between(PDO $pdo, string $from, string $to, string $extraWhere = ''): int
{
    if (!api_table_exists($pdo, 'orders')) return 0;
    $dateExpr = mec_online_print_date_expr($pdo);
    $join = mec_online_print_join_sql($pdo);
    $where = "DATE($dateExpr) BETWEEN ? AND ?" . ($extraWhere !== '' ? " AND ($extraWhere)" : '');
    $sql = 'SELECT COUNT(*) FROM orders o ' . $join . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    return (int)$stmt->fetchColumn();
}

function mec_status_in(PDO $pdo, string $column, array $statuses): string
{
    $quoted = array_map(static fn(string $status): string => $pdo->quote(strtolower($status)), $statuses);
    return 'LOWER(TRIM(COALESCE(' . api_quote_identifier($column) . ", ''))) IN (" . implode(',', $quoted) . ')';
}

function mec_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mec_location_name_expr(PDO $pdo, string $alias = 'sl'): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if (api_table_exists($pdo, 'storage_locations')) {
        $parts = [];
        foreach (['location_name', 'name', 'location_code', 'code'] as $column) {
            if (mec_has($pdo, 'storage_locations', $column)) {
                $parts[] = 'NULLIF(TRIM(' . $prefix . api_quote_identifier($column) . "), '')";
            }
        }
        if ($parts) {
            return 'COALESCE(' . implode(', ', $parts) . ", 'Default')";
        }
    }
    return "'Default'";
}

function mec_inventory_snapshot_rows(PDO $pdo, string $month, string $to): array
{
    $locationExpr = mec_location_name_expr($pdo, 'sl');

    if (api_table_exists($pdo, 'eom_stock_reports') && api_table_exists($pdo, 'eom_stock_report_details')) {
        $eomMarketingExpr = mec_has($pdo, 'eom_stock_report_details', 'marketing_stock_out') ? 'COALESCE(d.marketing_stock_out, 0)' : '0';
        $eomAdjustmentExpr = mec_has($pdo, 'eom_stock_report_details', 'adjustments') ? 'COALESCE(d.adjustments, 0)' : '0';
        $eomSystemExpr = mec_has($pdo, 'eom_stock_report_details', 'current_stock') ? 'COALESCE(d.current_stock, d.closing_quantity, 0)' : 'COALESCE(d.closing_quantity, 0)';
        $report = mec_fetch_rows($pdo, "SELECT id, status FROM eom_stock_reports WHERE report_month = ? ORDER BY CASE LOWER(status) WHEN 'finalized' THEN 0 WHEN 'reviewed' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END, id DESC LIMIT 1", [$month]);
        $reportId = (int)($report[0]['id'] ?? 0);
        if ($reportId > 0) {
            $rows = mec_fetch_rows($pdo, "
                SELECT
                    d.item_name AS product,
                    COALESCE(NULLIF(TRIM(d.sku), ''), '') AS sku,
                    {$locationExpr} AS location,
                    COALESCE(d.opening_quantity, 0) AS opening,
                    COALESCE(d.movements_in, 0) AS received,
                    COALESCE(d.movements_out, 0) AS sales,
                    0 AS returns,
                    {$eomMarketingExpr} AS marketing,
                    {$eomAdjustmentExpr} AS adjustment,
                    COALESCE(d.closing_quantity, 0) AS expected,
                    {$eomSystemExpr} AS system,
                    CASE WHEN d.final_quantity IS NULL THEN '' ELSE d.final_quantity END AS physical,
                    'EOM' AS source,
                    ? AS source_period
                FROM eom_stock_report_details d
                LEFT JOIN storage_locations sl ON sl.id = d.storage_location_id
                WHERE d.eom_report_id = ?
                ORDER BY location ASC, d.item_name ASC
                LIMIT 300
            ", [$month, $reportId]);
            if ($rows) return $rows;
        }
    }

    if (api_table_exists($pdo, 'eod_stock_reports') && api_table_exists($pdo, 'eod_stock_report_details')) {
        $report = mec_fetch_rows($pdo, "SELECT id, status FROM eod_stock_reports WHERE report_date = ? ORDER BY CASE LOWER(status) WHEN 'finalized' THEN 0 WHEN 'reviewed' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END, id DESC LIMIT 1", [$to]);
        $reportId = (int)($report[0]['id'] ?? 0);
        if ($reportId > 0) {
            $rows = mec_fetch_rows($pdo, "
                SELECT
                    d.item_name AS product,
                    COALESCE(NULLIF(TRIM(d.sku), ''), '') AS sku,
                    {$locationExpr} AS location,
                    COALESCE(d.opening_quantity, 0) AS opening,
                    COALESCE(d.daily_received, 0) AS received,
                    COALESCE(d.quantity_on_hand, 0) AS expected,
                    COALESCE(d.quantity_on_hand, 0) AS system,
                    COALESCE(d.return_quantity, 0) AS returns,
                    0 AS marketing,
                    COALESCE(d.adjustments, 0) AS adjustment,
                    CASE WHEN d.final_quantity IS NULL THEN '' ELSE d.final_quantity END AS physical,
                    'EOD' AS source,
                    ? AS source_period
                FROM eod_stock_report_details d
                LEFT JOIN storage_locations sl ON sl.id = d.storage_location_id
                WHERE d.eod_report_id = ?
                ORDER BY location ASC, d.item_name ASC
                LIMIT 300
            ", [$to, $reportId]);
            if ($rows) return $rows;
        }
    }

    if (!api_table_exists($pdo, 'current_inventory')) {
        return [];
    }

    return mec_fetch_rows($pdo, "
        SELECT
            item_name AS product,
            COALESCE(NULLIF(TRIM(ci.sku), ''), '') AS sku,
            {$locationExpr} AS location,
            0 AS opening,
            0 AS received,
            0 AS sales,
            0 AS returns,
            0 AS marketing,
            0 AS adjustment,
            COALESCE(quantity_on_hand, 0) AS expected,
            COALESCE(quantity_on_hand, 0) AS system,
            '' AS physical,
            'LIVE' AS source,
            '' AS source_period
        FROM current_inventory ci
        LEFT JOIN storage_locations sl ON sl.id = ci.storage_location_id
        ORDER BY item_name ASC
        LIMIT 120
    ");
}

function mec_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS month_end_closings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        closing_month DATE NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'OPEN',
        closing_version INT NOT NULL DEFAULT 1,
        final_approval_status VARCHAR(24) NOT NULL DEFAULT 'Pending',
        final_note TEXT NULL,
        reports_generated_at DATETIME NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        closed_by INT NULL,
        closed_at DATETIME NULL,
        reopened_by INT NULL,
        reopened_at DATETIME NULL,
        reopen_reason TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_month_end_closing_month (closing_month),
        INDEX idx_month_end_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS month_end_closing_steps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        closing_id INT NOT NULL,
        step_key VARCHAR(40) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
        system_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        issue_count INT NOT NULL DEFAULT 0,
        note TEXT NULL,
        actual_json LONGTEXT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_month_step (closing_id, step_key),
        INDEX idx_month_step_key (step_key),
        CONSTRAINT fk_month_step_closing FOREIGN KEY (closing_id) REFERENCES month_end_closings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS month_end_closing_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        closing_id INT NOT NULL,
        report_key VARCHAR(80) NOT NULL,
        report_label VARCHAR(160) NOT NULL,
        generated_by INT NULL,
        generated_at DATETIME NOT NULL,
        snapshot_json LONGTEXT NULL,
        UNIQUE KEY uniq_month_report (closing_id, report_key),
        CONSTRAINT fk_month_report_closing FOREIGN KEY (closing_id) REFERENCES month_end_closings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS month_end_closing_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        closing_id INT NOT NULL,
        step_key VARCHAR(40) NULL,
        action VARCHAR(80) NOT NULL,
        note TEXT NULL,
        payload_json LONGTEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_month_log_closing (closing_id),
        CONSTRAINT fk_month_log_closing FOREIGN KEY (closing_id) REFERENCES month_end_closings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mec_get_closing(PDO $pdo, string $month): array
{
    $closingMonth = mec_month_start($month);
    $userId = mec_current_user_id();
    $stmt = $pdo->prepare("INSERT INTO month_end_closings (closing_month, created_by, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at");
    $stmt->execute([$closingMonth, $userId, $userId]);
    $stmt = $pdo->prepare('SELECT * FROM month_end_closings WHERE closing_month = ? LIMIT 1');
    $stmt->execute([$closingMonth]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function mec_log(PDO $pdo, int $closingId, ?string $stepKey, string $action, string $note = '', array $payload = []): void
{
    $stmt = $pdo->prepare("INSERT INTO month_end_closing_logs (closing_id, step_key, action, note, payload_json, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$closingId, $stepKey, $action, $note !== '' ? $note : null, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, mec_current_user_id()]);
}

function mec_step_logs(PDO $pdo, int $closingId): array
{
    $rows = mec_fetch_rows(
        $pdo,
        "SELECT l.step_key, l.action, l.note, l.created_at, COALESCE(NULLIF(u.name, ''), u.username, '') AS created_by
         FROM month_end_closing_logs l
         LEFT JOIN users u ON u.id = l.created_by
         WHERE l.closing_id = ?
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 240",
        [$closingId]
    );
    $grouped = [];
    foreach ($rows as $row) {
        $key = (string)($row['step_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $grouped[$key][] = [
            'action' => (string)($row['action'] ?? ''),
            'note' => (string)($row['note'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'created_by' => (string)($row['created_by'] ?? ''),
        ];
    }
    return $grouped;
}

function mec_scalar(PDO $pdo, string $sql, array $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : $value;
    } catch (Throwable $e) {
        return 0;
    }
}

function mec_purchase_closing_data(PDO $pdo, string $from, string $to): array
{
    if (!api_table_exists($pdo, 'purchase_orders')) {
        return [
            'summary' => [
                'order_count' => 0,
                'total_amount' => 0.0,
                'total_paid' => 0.0,
                'balance_amount' => 0.0,
                'receiving_count' => 0,
                'received_qty' => 0.0,
                'return_count' => 0,
                'return_amount' => 0.0,
                'incomplete_count' => 0,
                'incomplete_amount' => 0.0,
            ],
            'review' => [
                ['Purchase Orders', 0, 'Matched', 0.0],
                ['Receiving', 0, 'Matched', 0.0],
                ['Returns', 0, 'Matched', 0.0],
                ['Incomplete / Missing Records', 0, 'Matched', 0.0],
            ],
        ];
    }

    $purchaseDate = 'DATE(COALESCE(po.order_date, po.created_at))';
    $activePo = "LOWER(COALESCE(po.status,'')) NOT IN ('cancel','cancelled','canceled')";

    $orderCount = (int)mec_scalar($pdo, "SELECT COUNT(*) FROM purchase_orders po WHERE $purchaseDate BETWEEN ? AND ? AND $activePo", [$from, $to]);
    $purchaseTotal = (float)mec_scalar($pdo, "SELECT COALESCE(SUM(po.total_amount), 0) FROM purchase_orders po WHERE $purchaseDate BETWEEN ? AND ? AND $activePo", [$from, $to]);
    $purchasePaid = (float)mec_scalar($pdo, "SELECT COALESCE(SUM(po.total_paid), 0) FROM purchase_orders po WHERE $purchaseDate BETWEEN ? AND ? AND $activePo", [$from, $to]);

    $receivingCount = 0;
    $receivedQty = 0.0;
    if (api_table_exists($pdo, 'purchase_receiving')) {
        $receivingCount = (int)mec_scalar($pdo, 'SELECT COUNT(DISTINCT pr.id) FROM purchase_receiving pr WHERE DATE(pr.receiving_date) BETWEEN ? AND ?', [$from, $to]);
        if (api_table_exists($pdo, 'purchase_receiving_items')) {
            $receivedQty = (float)mec_scalar(
                $pdo,
                'SELECT COALESCE(SUM(pri.quantity_received), 0)
                 FROM purchase_receiving_items pri
                 JOIN purchase_receiving pr ON pr.id = pri.receiving_id
                 WHERE DATE(pr.receiving_date) BETWEEN ? AND ?',
                [$from, $to]
            );
        }
    }

    $returnCount = 0;
    $returnAmount = 0.0;
    if (api_table_exists($pdo, 'purchase_returns')) {
        $returnCount = (int)mec_scalar($pdo, 'SELECT COUNT(*) FROM purchase_returns WHERE DATE(return_date) BETWEEN ? AND ?', [$from, $to]);
        $returnAmount = (float)mec_scalar($pdo, 'SELECT COALESCE(SUM(total_amount), 0) FROM purchase_returns WHERE DATE(return_date) BETWEEN ? AND ?', [$from, $to]);
    }

    $incompleteStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT po.id), COALESCE(SUM(po.total_amount), 0)
         FROM purchase_orders po
         WHERE $purchaseDate BETWEEN ? AND ?
           AND $activePo
           AND (
             LOWER(COALESCE(po.status, '')) IN ('pending', 'partial', 'draft', 'sent')
             OR EXISTS (
               SELECT 1 FROM purchase_order_items poi
               WHERE poi.purchase_order_id = po.id
                 AND COALESCE(poi.quantity_received, 0) + 0.004 < COALESCE(poi.quantity_ordered, 0)
             )
           )"
    );
    $incompleteStmt->execute([$from, $to]);
    $incompleteRow = $incompleteStmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $incompleteCount = (int)($incompleteRow[0] ?? 0);
    $incompleteAmount = (float)($incompleteRow[1] ?? 0);
    $incompleteStatus = $incompleteCount > 0 ? 'Needs Review' : 'Matched';

    return [
        'summary' => [
            'order_count' => $orderCount,
            'total_amount' => $purchaseTotal,
            'total_paid' => $purchasePaid,
            'balance_amount' => max(0, $purchaseTotal - $purchasePaid),
            'receiving_count' => $receivingCount,
            'received_qty' => $receivedQty,
            'return_count' => $returnCount,
            'return_amount' => $returnAmount,
            'incomplete_count' => $incompleteCount,
            'incomplete_amount' => $incompleteAmount,
        ],
        'review' => [
            ['Purchase Orders', $orderCount, 'Checked', $purchaseTotal],
            ['Receiving', $receivingCount, $receivingCount > 0 ? 'Checked' : 'Matched', $receivedQty],
            ['Returns', $returnCount, $returnCount > 0 ? 'Checked' : 'Matched', $returnAmount],
            ['Incomplete / Missing Records', $incompleteCount, $incompleteStatus, $incompleteAmount],
        ],
    ];
}

function mec_receivable_payment_status(float $paid, float $outstanding, string $explicit = ''): string
{
    $explicit = strtolower(trim($explicit));
    if ($explicit === 'partial') {
        return 'Partial';
    }
    if (in_array($explicit, ['unpaid', 'credit'], true)) {
        return 'Unpaid';
    }
    if ($outstanding <= 0.004) {
        return 'Paid';
    }
    if ($paid > 0.004) {
        return 'Partial';
    }
    return 'Unpaid';
}

function mec_receivable_ar_status(string $dueDate, float $outstanding): string
{
    if ($outstanding <= 0.004) {
        return 'Paid';
    }
    if (mec_days_overdue($dueDate) > 0) {
        return 'Overdue';
    }
    return 'Open';
}

function mec_online_active_sql(): string
{
    return "COALESCE(o.is_cancelled, 0) = 0 AND COALESCE(o.is_returned, 0) = 0 AND LOWER(COALESCE(o.status, '')) NOT IN ('cancel', 'cancelled', 'canceled', 'return', 'returned', 'draft')";
}

function mec_online_pending_statuses(): array
{
    return ['pending', 'incomplete', 'draft', 'processing', 'awaiting', 'awaiting processing', 'awaiting delivery', 'hold', 'on hold', 'unknown'];
}

function mec_online_is_pending_sql(PDO $pdo): string
{
    $pendingMatch = mec_online_status_in(mec_online_pending_statuses());
    $emptyStatus = "TRIM(COALESCE(o.status, '')) = ''";
    if (api_table_exists($pdo, 'print_jobs')) {
        return "($pendingMatch OR ($emptyStatus AND NOT EXISTS (
            SELECT 1 FROM print_jobs pj_pending
            WHERE pj_pending.order_id = o.id AND pj_pending.printed_at IS NOT NULL
        )))";
    }
    return "($pendingMatch OR $emptyStatus)";
}

function mec_online_unpaid_filter_sql(PDO $pdo): string
{
    $onlineActive = mec_online_active_sql();
    return "$onlineActive
        AND NOT (COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid')
        AND NOT " . mec_online_status_in(['partial']) . "
        AND NOT (" . mec_online_is_pending_sql($pdo) . ")";
}

function mec_online_receivable_filter_sql(PDO $pdo): string
{
    $onlineActive = mec_online_active_sql();
    return "$onlineActive AND (
        " . mec_online_status_in(['partial']) . "
        OR (" . mec_online_unpaid_filter_sql($pdo) . ")
    )";
}

function mec_offline_receivable_filter_sql(PDO $pdo): string
{
    $active = "LOWER(COALESCE(o.status, '')) NOT IN ('cancel', 'cancelled', 'canceled', 'return', 'returned', 'draft')";
    $paid = "(LOWER(COALESCE(o.status, '')) = 'paid' OR COALESCE(o.received_amount, 0) >= COALESCE(o.total_amount, 0))";
    $partial = "(LOWER(COALESCE(o.status, '')) = 'partial' OR (COALESCE(o.received_amount, 0) > 0 AND COALESCE(o.received_amount, 0) < COALESCE(o.total_amount, 0)))";
    $unpaidCredit = "(COALESCE(o.total_amount, 0) > 0 AND COALESCE(o.received_amount, 0) <= 0 AND " . mec_status_in($pdo, 'status', ['unpaid', 'credit']) . ')';
    return "$active AND NOT $paid AND ($partial OR $unpaidCredit)";
}

function mec_dealer_receivable_filter_sql(PDO $pdo): string
{
    $active = "LOWER(COALESCE(o.status, '')) NOT IN ('cancel', 'cancelled', 'canceled', 'return', 'returned', 'draft')";
    $partial = mec_status_in($pdo, 'payment_status', ['partial']);
    $unpaidCredit = mec_status_in($pdo, 'payment_status', ['unpaid', 'credit']);
    $partialByAmount = '(COALESCE(o.paid_amount, 0) > 0.004 AND COALESCE(o.paid_amount, 0) < COALESCE(o.grand_total, 0))';
    return "$active AND ($partial OR $unpaidCredit OR $partialByAmount)";
}

function mec_sum_receivable_outstanding(PDO $pdo, string $from, string $to, bool $includePrevious = false): array
{
    $totals = ['online' => 0.0, 'offline' => 0.0, 'dealer' => 0.0];

    if (api_table_exists($pdo, 'orders')) {
        $onlineDateExpr = mec_online_print_date_expr($pdo);
        $onlineJoin = mec_online_print_join_sql($pdo);
        $onlineAmountExpr = mec_online_amount_expr($pdo);
        $outstandingExpr = "CASE WHEN COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid' THEN 0 ELSE $onlineAmountExpr END";
        $dateClause = $includePrevious ? "DATE($onlineDateExpr) <= ?" : "DATE($onlineDateExpr) BETWEEN ? AND ?";
        $params = $includePrevious ? [$to] : [$from, $to];
        $sql = "SELECT COALESCE(SUM($outstandingExpr), 0) FROM orders o $onlineJoin WHERE $dateClause AND " . mec_online_receivable_filter_sql($pdo);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $totals['online'] = (float)$stmt->fetchColumn();
    }

    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $offlineDateExpr = mec_date_expr($pdo, 'offline_sale_orders', ['sale_date', 'order_date', 'created_at']) ?: 'created_at';
        $outstandingExpr = 'GREATEST(COALESCE(o.total_amount, 0) - COALESCE(o.received_amount, 0), 0)';
        $dateClause = $includePrevious ? "DATE($offlineDateExpr) <= ?" : "DATE($offlineDateExpr) BETWEEN ? AND ?";
        $params = $includePrevious ? [$to] : [$from, $to];
        $sql = "SELECT COALESCE(SUM($outstandingExpr), 0) FROM offline_sale_orders o WHERE $dateClause AND " . mec_offline_receivable_filter_sql($pdo) . " AND $outstandingExpr > 0.004";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $totals['offline'] = (float)$stmt->fetchColumn();
    }

    if (api_table_exists($pdo, 'dealer_orders')) {
        $outstandingExpr = 'GREATEST(COALESCE(o.balance_amount, COALESCE(o.grand_total, 0) - COALESCE(o.paid_amount, 0)), 0)';
        $dateClause = $includePrevious ? "DATE(o.order_date) <= ?" : "DATE(o.order_date) BETWEEN ? AND ?";
        $params = $includePrevious ? [$to] : [$from, $to];
        $sql = "SELECT COALESCE(SUM($outstandingExpr), 0) FROM dealer_orders o WHERE $dateClause AND " . mec_dealer_receivable_filter_sql($pdo) . " AND $outstandingExpr > 0.004";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $totals['dealer'] = (float)$stmt->fetchColumn();
    }

    return $totals;
}

function mec_receivable_summary(array $rows): array
{
    $unpaid = 0;
    $partial = 0;
    $overdueCount = 0;
    $totalOutstanding = 0.0;
    $grossOriginal = 0.0;
    $overdueAmount = 0.0;
    $onlineCount = 0;
    $offlineCount = 0;
    $dealerCount = 0;
    foreach ($rows as $row) {
        $out = (float)($row['outstanding'] ?? 0);
        $orig = (float)($row['original_amount'] ?? $out);
        $totalOutstanding += $out;
        $grossOriginal += $orig;
        $paymentStatus = (string)($row['payment_status'] ?? '');
        if ($paymentStatus === 'Unpaid') {
            $unpaid++;
        }
        if ($paymentStatus === 'Partial') {
            $partial++;
        }
        if (strtolower((string)($row['status'] ?? '')) === 'overdue') {
            $overdueCount++;
            $overdueAmount += $out;
        }
        $source = (string)($row['source'] ?? '');
        if ($source === 'Online') {
            $onlineCount++;
        } elseif ($source === 'Offline') {
            $offlineCount++;
        } elseif ($source === 'Dealer') {
            $dealerCount++;
        }
    }
    return [
        'invoice_count' => count($rows),
        'unpaid_count' => $unpaid,
        'partial_count' => $partial,
        'total_outstanding' => $totalOutstanding,
        'gross_original' => $grossOriginal,
        'overdue_count' => $overdueCount,
        'overdue_amount' => $overdueAmount,
        'online_count' => $onlineCount,
        'offline_count' => $offlineCount,
        'dealer_count' => $dealerCount,
    ];
}

function mec_receivable_rows(PDO $pdo, string $from, string $to, bool $includePrevious = false): array
{
    $rows = [];
    $perSourceLimit = 150;

    if (api_table_exists($pdo, 'dealer_orders')) {
        $dealerRows = mec_fetch_rows(
            $pdo,
            "SELECT
                'Dealer' AS source,
                COALESCE(NULLIF(d.name, ''), NULLIF(o.dealer_name, ''), CONCAT('Dealer #', o.dealer_id)) AS customer,
                o.order_code AS invoice,
                DATE(o.order_date) AS invoice_date,
                DATE_ADD(DATE(o.order_date), INTERVAL 30 DAY) AS due_date,
                COALESCE(o.grand_total, 0) AS original_amount,
                COALESCE(o.paid_amount, 0) AS paid,
                GREATEST(COALESCE(o.balance_amount, COALESCE(o.grand_total, 0) - COALESCE(o.paid_amount, 0)), 0) AS outstanding,
                COALESCE(o.payment_status, '') AS payment_status_raw
            FROM dealer_orders o
            LEFT JOIN dealers d ON d.id = o.dealer_id
            WHERE " . ($includePrevious ? "DATE(o.order_date) <= ?" : "DATE(o.order_date) BETWEEN ? AND ?") . "
              AND " . mec_dealer_receivable_filter_sql($pdo) . "
            HAVING outstanding > 0.004
            ORDER BY due_date ASC
            LIMIT $perSourceLimit",
            $includePrevious ? [$to] : [$from, $to]
        );
        foreach ($dealerRows as $row) {
            $outstanding = (float)$row['outstanding'];
            $paid = (float)$row['paid'];
            $rows[] = [
                'source' => 'Dealer',
                'customer' => (string)$row['customer'],
                'invoice' => (string)$row['invoice'],
                'invoice_date' => (string)$row['invoice_date'],
                'due_date' => (string)$row['due_date'],
                'original_amount' => (float)$row['original_amount'],
                'paid' => $paid,
                'outstanding' => $outstanding,
                'payment_status' => mec_receivable_payment_status($paid, $outstanding, (string)$row['payment_status_raw']),
                'status' => mec_receivable_ar_status((string)$row['due_date'], $outstanding),
            ];
        }
    }

    if (api_table_exists($pdo, 'offline_sale_orders')) {
        $offlineDateExpr = mec_date_expr($pdo, 'offline_sale_orders', ['sale_date', 'order_date', 'created_at']) ?: 'created_at';
        $offlineRows = mec_fetch_rows(
            $pdo,
            "SELECT
                'Offline' AS source,
                COALESCE(NULLIF(o.customer_name, ''), 'Walk-in Customer') AS customer,
                COALESCE(o.order_code, CONCAT('OFF-', o.id)) AS invoice,
                DATE($offlineDateExpr) AS invoice_date,
                DATE_ADD(DATE($offlineDateExpr), INTERVAL 30 DAY) AS due_date,
                COALESCE(o.total_amount, 0) AS original_amount,
                COALESCE(o.received_amount, 0) AS paid,
                GREATEST(COALESCE(o.total_amount, 0) - COALESCE(o.received_amount, 0), 0) AS outstanding,
                COALESCE(o.status, '') AS payment_status_raw
            FROM offline_sale_orders o
            WHERE " . ($includePrevious ? "DATE($offlineDateExpr) <= ?" : "DATE($offlineDateExpr) BETWEEN ? AND ?") . "
              AND " . mec_offline_receivable_filter_sql($pdo) . "
            HAVING outstanding > 0.004
            ORDER BY due_date ASC
            LIMIT $perSourceLimit",
            $includePrevious ? [$to] : [$from, $to]
        );
        foreach ($offlineRows as $row) {
            $outstanding = (float)$row['outstanding'];
            $paid = (float)$row['paid'];
            $rows[] = [
                'source' => 'Offline',
                'customer' => (string)$row['customer'],
                'invoice' => (string)$row['invoice'],
                'invoice_date' => (string)$row['invoice_date'],
                'due_date' => (string)$row['due_date'],
                'original_amount' => (float)$row['original_amount'],
                'paid' => $paid,
                'outstanding' => $outstanding,
                'payment_status' => mec_receivable_payment_status($paid, $outstanding, (string)$row['payment_status_raw']),
                'status' => mec_receivable_ar_status((string)$row['due_date'], $outstanding),
            ];
        }
    }

    if (api_table_exists($pdo, 'orders')) {
        $onlineDateExpr = mec_online_print_date_expr($pdo);
        $onlineJoin = mec_online_print_join_sql($pdo);
        $onlineReceivableFilter = mec_online_receivable_filter_sql($pdo);
        $onlineAmountExpr = mec_online_amount_expr($pdo);
        $onlineRows = mec_fetch_rows(
            $pdo,
            "SELECT
                'Online' AS source,
                COALESCE(NULLIF(o.customer_name, ''), NULLIF(o.phone, ''), 'Online Customer') AS customer,
                COALESCE(o.order_code, CONCAT('ON-', o.id)) AS invoice,
                DATE($onlineDateExpr) AS invoice_date,
                DATE_ADD(DATE($onlineDateExpr), INTERVAL 30 DAY) AS due_date,
                $onlineAmountExpr AS original_amount,
                CASE
                    WHEN COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid' THEN $onlineAmountExpr
                    ELSE 0
                END AS paid,
                CASE
                    WHEN COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid' THEN 0
                    ELSE $onlineAmountExpr
                END AS outstanding,
                COALESCE(o.status, '') AS payment_status_raw
            FROM orders o
            $onlineJoin
            WHERE " . ($includePrevious ? "DATE($onlineDateExpr) <= ?" : "DATE($onlineDateExpr) BETWEEN ? AND ?") . "
              AND $onlineReceivableFilter
            HAVING outstanding > 0.004
            ORDER BY invoice_date DESC, due_date ASC
            LIMIT $perSourceLimit",
            $includePrevious ? [$to] : [$from, $to]
        );
        foreach ($onlineRows as $row) {
            $outstanding = (float)$row['outstanding'];
            $paid = (float)$row['paid'];
            $rows[] = [
                'source' => 'Online',
                'customer' => (string)$row['customer'],
                'invoice' => (string)$row['invoice'],
                'invoice_date' => (string)$row['invoice_date'],
                'due_date' => (string)$row['due_date'],
                'original_amount' => (float)$row['original_amount'],
                'paid' => $paid,
                'outstanding' => $outstanding,
                'payment_status' => mec_receivable_payment_status($paid, $outstanding, (string)$row['payment_status_raw']),
                'status' => mec_receivable_ar_status((string)$row['due_date'], $outstanding),
            ];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $sourceOrder = ['Online' => 0, 'Offline' => 1, 'Dealer' => 2];
        $sourceA = $sourceOrder[(string)($a['source'] ?? '')] ?? 9;
        $sourceB = $sourceOrder[(string)($b['source'] ?? '')] ?? 9;
        if ($sourceA !== $sourceB) {
            return $sourceA <=> $sourceB;
        }
        return strcmp((string)($b['invoice_date'] ?? ''), (string)($a['invoice_date'] ?? ''));
    });
    return $rows;
}

function mec_calculated_data(PDO $pdo, string $month, bool $includePreviousAr = false): array
{
    $from = mec_month_start($month);
    $to = mec_month_end($month);

    $onlineSales = mec_sum_online_between($pdo, ['total_amount', 'grand_total', 'amount'], $from, $to, 'COALESCE(o.is_cancelled, 0) = 0');
    $discounts = mec_sum_online_between($pdo, ['discount', 'discount_amount'], $from, $to, 'COALESCE(o.is_cancelled, 0) = 0');
    $cancelled = mec_count_online_between($pdo, $from, $to, 'COALESCE(o.is_cancelled, 0) = 1 OR LOWER(COALESCE(o.status, \'\')) = \'cancelled\'');
    $returned = mec_count_online_between($pdo, $from, $to, 'COALESCE(o.is_returned, 0) = 1 OR LOWER(COALESCE(o.status, \'\')) LIKE \'%return%\'');
    $offlineSales = mec_sum_between($pdo, 'offline_sale_orders', ['total_amount', 'grand_total', 'amount'], ['order_date', 'sale_date', 'created_at'], $from, $to);
    $dealerSales = mec_sum_between($pdo, 'dealer_orders', ['total_amount', 'grand_total', 'amount'], ['order_date', 'created_at'], $from, $to);

    $operationalPendingStatuses = mec_online_pending_statuses();
    $onlineActive = "COALESCE(o.is_cancelled, 0) = 0 AND COALESCE(o.is_returned, 0) = 0 AND LOWER(COALESCE(o.status, '')) NOT IN ('cancel', 'cancelled', 'canceled', 'return', 'returned', 'draft')";
    $onlinePendingSql = mec_online_is_pending_sql($pdo);
    $onlineOperationalPending = mec_count_online_between($pdo, $from, $to, $onlineActive . ' AND (' . $onlinePendingSql . ')');
    $onlineCompleted = mec_count_online_between($pdo, $from, $to, $onlineActive . ' AND NOT (' . $onlinePendingSql . ')');
    $onlinePaid = mec_count_online_between($pdo, $from, $to, $onlineActive . " AND (COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid')");
    $onlinePartial = mec_count_online_between($pdo, $from, $to, $onlineActive . ' AND ' . mec_online_status_in(['partial']));
    $onlineUnpaid = mec_count_online_between($pdo, $from, $to, mec_online_unpaid_filter_sql($pdo));

    $offlineActive = "LOWER(COALESCE(status,'')) NOT IN ('cancel','cancelled','canceled','return','returned')";
    $offlineOperationalPending = mec_count_between($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at'], $from, $to, $offlineActive . ' AND (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $offlineCompleted = mec_count_between($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at'], $from, $to, $offlineActive . ' AND NOT (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $offlinePaid = mec_count_between($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at'], $from, $to, $offlineActive . " AND (LOWER(COALESCE(status,''))='paid' OR COALESCE(received_amount,0) >= COALESCE(total_amount,0))");
    $offlinePartial = mec_count_between($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at'], $from, $to, $offlineActive . " AND (LOWER(COALESCE(status,''))='partial' OR (COALESCE(received_amount,0) > 0 AND COALESCE(received_amount,0) < COALESCE(total_amount,0)))");
    $offlineUnpaid = mec_count_between($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at'], $from, $to, $offlineActive . " AND COALESCE(total_amount,0) > 0 AND COALESCE(received_amount,0) <= 0 AND " . mec_status_in($pdo, 'status', ['unpaid', 'credit']));

    $dealerActive = "LOWER(COALESCE(status,'')) NOT IN ('cancel','cancelled','canceled','return','returned')";
    $dealerOperationalPending = mec_count_between($pdo, 'dealer_orders', ['order_date', 'created_at'], $from, $to, $dealerActive . ' AND (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $dealerCompleted = mec_count_between($pdo, 'dealer_orders', ['order_date', 'created_at'], $from, $to, $dealerActive . ' AND NOT (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $dealerPaid = mec_count_between($pdo, 'dealer_orders', ['order_date', 'created_at'], $from, $to, $dealerActive . ' AND ' . mec_status_in($pdo, 'payment_status', ['paid']));
    $dealerPartial = mec_count_between($pdo, 'dealer_orders', ['order_date', 'created_at'], $from, $to, $dealerActive . ' AND ' . mec_status_in($pdo, 'payment_status', ['partial']));
    $dealerUnpaid = mec_count_between($pdo, 'dealer_orders', ['order_date', 'created_at'], $from, $to, $dealerActive . ' AND ' . mec_status_in($pdo, 'payment_status', ['unpaid', 'credit']));

    $amountFields = ['total_amount', 'grand_total', 'amount'];
    $offlineDateFields = ['order_date', 'sale_date', 'created_at'];
    $dealerDateFields = ['order_date', 'created_at'];

    $onlineCancelledAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, 'COALESCE(o.is_cancelled, 0) = 1 OR LOWER(COALESCE(o.status, \'\')) = \'cancelled\'');
    $onlineReturnedAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, 'COALESCE(o.is_returned, 0) = 1 OR LOWER(COALESCE(o.status, \'\')) LIKE \'%return%\'');
    $onlineCompletedAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, $onlineActive . ' AND NOT (' . $onlinePendingSql . ')');
    $onlinePendingAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, $onlineActive . ' AND (' . $onlinePendingSql . ')');
    $onlinePaidAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, $onlineActive . " AND (COALESCE(o.is_paid, 0) = 1 OR LOWER(COALESCE(o.status, '')) = 'paid')");
    $onlinePartialAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, $onlineActive . ' AND ' . mec_online_status_in(['partial']));
    $onlineUnpaidAmount = mec_sum_online_between($pdo, $amountFields, $from, $to, mec_online_unpaid_filter_sql($pdo));

    $offlineCompletedAmount = mec_sum_between($pdo, 'offline_sale_orders', $amountFields, $offlineDateFields, $from, $to, $offlineActive . ' AND NOT (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $offlinePendingAmount = mec_sum_between($pdo, 'offline_sale_orders', $amountFields, $offlineDateFields, $from, $to, $offlineActive . ' AND (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $offlinePaidAmount = mec_sum_between($pdo, 'offline_sale_orders', $amountFields, $offlineDateFields, $from, $to, $offlineActive . " AND (LOWER(COALESCE(status,''))='paid' OR COALESCE(received_amount,0) >= COALESCE(total_amount,0))");
    $offlinePartialAmount = mec_sum_between($pdo, 'offline_sale_orders', $amountFields, $offlineDateFields, $from, $to, $offlineActive . " AND (LOWER(COALESCE(status,''))='partial' OR (COALESCE(received_amount,0) > 0 AND COALESCE(received_amount,0) < COALESCE(total_amount,0)))");
    $offlineUnpaidAmount = mec_sum_between($pdo, 'offline_sale_orders', $amountFields, $offlineDateFields, $from, $to, $offlineActive . " AND COALESCE(total_amount,0) > 0 AND COALESCE(received_amount,0) <= 0 AND " . mec_status_in($pdo, 'status', ['unpaid', 'credit']));

    $dealerCompletedAmount = mec_sum_between($pdo, 'dealer_orders', $amountFields, $dealerDateFields, $from, $to, $dealerActive . ' AND NOT (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $dealerPendingAmount = mec_sum_between($pdo, 'dealer_orders', $amountFields, $dealerDateFields, $from, $to, $dealerActive . ' AND (' . mec_status_in($pdo, 'status', $operationalPendingStatuses) . " OR TRIM(COALESCE(status,''))='')");
    $dealerPaidAmount = mec_sum_between($pdo, 'dealer_orders', $amountFields, $dealerDateFields, $from, $to, $dealerActive . ' AND ' . mec_status_in($pdo, 'payment_status', ['paid']));
    $dealerPartialAmount = mec_sum_between($pdo, 'dealer_orders', $amountFields, $dealerDateFields, $from, $to, $dealerActive . ' AND ' . mec_status_in($pdo, 'payment_status', ['partial']));
    $dealerUnpaidAmount = mec_sum_between($pdo, 'dealer_orders', $amountFields, $dealerDateFields, $from, $to, $dealerActive . ' AND ' . mec_status_in($pdo, 'payment_status', ['unpaid', 'credit']));

    $completedAmount = $onlineCompletedAmount + $offlineCompletedAmount + $dealerCompletedAmount;
    $pendingAmount = $onlinePendingAmount + $offlinePendingAmount + $dealerPendingAmount;
    $paidAmount = $onlinePaidAmount + $offlinePaidAmount + $dealerPaidAmount;
    $partialAmount = $onlinePartialAmount + $offlinePartialAmount + $dealerPartialAmount;
    $unpaidCreditAmount = $onlineUnpaidAmount + $offlineUnpaidAmount + $dealerUnpaidAmount;

    $onlineOrderCount = mec_count_online_between($pdo, $from, $to, $onlineActive);
    $offlineOrderCount = mec_count_between($pdo, 'offline_sale_orders', ['order_date', 'sale_date', 'created_at'], $from, $to, $offlineActive);
    $dealerOrderCount = mec_count_between($pdo, 'dealer_orders', ['order_date', 'created_at'], $from, $to, $dealerActive);
    $activeOrderCount = $onlineOrderCount + $offlineOrderCount + $dealerOrderCount;

    $completedOrders = $onlineCompleted + $offlineCompleted + $dealerCompleted;
    $pending = $onlineOperationalPending + $offlineOperationalPending + $dealerOperationalPending;
    $paidOrders = $onlinePaid + $offlinePaid + $dealerPaid;
    $partialOrders = $onlinePartial + $offlinePartial + $dealerPartial;
    $unpaidCreditOrders = $onlineUnpaid + $offlineUnpaid + $dealerUnpaid;

    $receivableRows = mec_receivable_rows($pdo, $from, $to, $includePreviousAr);
    $receivableOutstanding = mec_sum_receivable_outstanding($pdo, $from, $to, $includePreviousAr);
    $receivableSummary = mec_receivable_summary($receivableRows);
    $receivableSummary['online_count'] = $onlinePartial + $onlineUnpaid;
    $receivableSummary['offline_count'] = $offlinePartial + $offlineUnpaid;
    $receivableSummary['dealer_count'] = $dealerPartial + $dealerUnpaid;
    $receivableSummary['online_outstanding'] = $receivableOutstanding['online'];
    $receivableSummary['offline_outstanding'] = $receivableOutstanding['offline'];
    $receivableSummary['dealer_outstanding'] = $receivableOutstanding['dealer'];
    $receivableSummary['invoice_count'] = $receivableSummary['online_count'] + $receivableSummary['offline_count'] + $receivableSummary['dealer_count'];
    $receivableSummary['total_outstanding'] = $receivableOutstanding['online'] + $receivableOutstanding['offline'] + $receivableOutstanding['dealer'];
    $receivableSummary['unpaid_count'] = $onlineUnpaid + $offlineUnpaid + $dealerUnpaid;
    $receivableSummary['partial_count'] = $onlinePartial + $offlinePartial + $dealerPartial;

    $purchaseItemAgg = api_table_exists($pdo, 'purchase_order_items')
        ? 'LEFT JOIN (
            SELECT purchase_order_id,
                   COUNT(id) AS total_items,
                   COALESCE(SUM(CASE WHEN COALESCE(quantity_received, 0) >= quantity_ordered THEN 1 ELSE 0 END), 0) AS completed_items
            FROM purchase_order_items
            GROUP BY purchase_order_id
          ) item_agg ON item_agg.purchase_order_id = po.id'
        : '';
    $purchaseItemSelect = api_table_exists($pdo, 'purchase_order_items')
        ? 'COALESCE(item_agg.total_items, 0) AS total_items, COALESCE(item_agg.completed_items, 0) AS completed_items'
        : '0 AS total_items, 0 AS completed_items';
    $purchaseIncompleteCheck = api_table_exists($pdo, 'purchase_order_items')
        ? 'OR EXISTS (
            SELECT 1 FROM purchase_order_items poi
            WHERE poi.purchase_order_id = po.id
              AND COALESCE(poi.quantity_received, 0) + 0.004 < COALESCE(poi.quantity_ordered, 0)
          )'
        : '';
    $purchaseRows = api_table_exists($pdo, 'purchase_orders') ? mec_fetch_rows(
        $pdo,
        "SELECT COALESCE(po.order_number, CONCAT('PO-', po.id)) AS po_no,
                COALESCE(pv.name, '') AS supplier,
                COALESCE(po.status, '') AS po_status,
                $purchaseItemSelect,
                COALESCE(po.total_amount, 0) AS amount,
                CASE WHEN LOWER(COALESCE(po.status,'')) IN ('pending','partial','draft','sent')
                      $purchaseIncompleteCheck
                     THEN 'Needs Review'
                     ELSE 'Reviewed'
                END AS status
         FROM purchase_orders po
         LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id
         $purchaseItemAgg
         WHERE DATE(COALESCE(po.order_date, po.created_at)) BETWEEN ? AND ?
           AND LOWER(COALESCE(po.status,'')) NOT IN ('cancel','cancelled','canceled')
         ORDER BY po.order_date DESC, po.id DESC
         LIMIT 80",
        [$from, $to]
    ) : [];
    $purchaseClosing = mec_purchase_closing_data($pdo, $from, $to);

    $payableRows = api_table_exists($pdo, 'purchase_orders') ? mec_fetch_rows($pdo, "SELECT COALESCE(pv.name, '') AS supplier, COALESCE(po.order_number, CONCAT('PO-', po.id)) AS invoice, COALESCE(po.total_amount, 0) AS amount, COALESCE((SELECT SUM(pp.payment_amount) FROM purchase_payments pp WHERE pp.purchase_order_id = po.id), 0) AS paid, GREATEST(COALESCE(po.total_amount,0) - COALESCE((SELECT SUM(pp.payment_amount) FROM purchase_payments pp WHERE pp.purchase_order_id = po.id), 0), 0) AS balance, DATE_ADD(DATE(COALESCE(po.order_date, po.created_at)), INTERVAL 30 DAY) AS due_date, CASE WHEN DATE_ADD(DATE(COALESCE(po.order_date, po.created_at)), INTERVAL 30 DAY) < CURDATE() AND GREATEST(COALESCE(po.total_amount,0) - COALESCE((SELECT SUM(pp.payment_amount) FROM purchase_payments pp WHERE pp.purchase_order_id = po.id), 0), 0) > 0 THEN 'Overdue' WHEN GREATEST(COALESCE(po.total_amount,0) - COALESCE((SELECT SUM(pp.payment_amount) FROM purchase_payments pp WHERE pp.purchase_order_id = po.id), 0), 0) > 0 THEN 'Open' ELSE 'Paid' END AS status FROM purchase_orders po LEFT JOIN purchase_vendors pv ON pv.id = po.vendor_id WHERE DATE(COALESCE(po.order_date, po.created_at)) BETWEEN ? AND ? HAVING balance > 0 ORDER BY due_date ASC LIMIT 80", [$from, $to]) : [];

    $bankRows = [];
    $bankNames = [];
    if (api_table_exists($pdo, 'orders') && mec_has($pdo, 'orders', 'payment_method')) {
        $bankNames = array_merge($bankNames, array_column(mec_fetch_rows($pdo, "SELECT DISTINCT payment_method AS name FROM orders WHERE payment_method IS NOT NULL AND payment_method <> '' LIMIT 40"), 'name'));
    }
    if (api_table_exists($pdo, 'finance_spending') && mec_has($pdo, 'finance_spending', 'payment_method')) {
        $bankNames = array_merge($bankNames, array_column(mec_fetch_rows($pdo, "SELECT DISTINCT payment_method AS name FROM finance_spending WHERE payment_method IS NOT NULL AND payment_method <> '' LIMIT 40"), 'name'));
    }
    $bankNames = array_values(array_unique(array_filter(array_map('trim', $bankNames)))) ?: ['ABA', 'AC', 'Cash'];
    foreach ($bankNames as $name) {
        $in = mec_sum_online_between($pdo, ['total_amount', 'grand_total', 'amount'], $from, $to, 'o.payment_method = ' . $pdo->quote($name) . ' AND COALESCE(o.is_cancelled, 0) = 0');
        $topup = mec_sum_between($pdo, 'finance_topups', ['amount'], ['topup_date', 'created_at'], $from, $to, mec_has($pdo, 'finance_topups', 'source') ? "source = " . $pdo->quote($name) : '');
        $out = mec_sum_between($pdo, 'finance_spending', ['amount'], ['spending_date', 'created_at'], $from, $to, mec_has($pdo, 'finance_spending', 'payment_method') ? "payment_method = " . $pdo->quote($name) : '');
        $bankRows[] = ['account' => $name, 'system' => $in + $topup - $out, 'actual' => '', 'reason' => '', 'statementDate' => $to, 'attachment' => ''];
    }

    $inventoryRows = mec_inventory_snapshot_rows($pdo, $month, $to);

    $marketingRows = api_table_exists($pdo, 'marketing_takes') ? mec_fetch_rows($pdo, "SELECT COALESCE(request_code, CONCAT('MR-', id)) AS request_no, COALESCE(type, marketing_type, 'Marketing') AS type, COALESCE(product_name, item_name, '') AS product, COALESCE(quantity, issued_quantity, 0) AS issued, COALESCE(used_quantity, 0) AS used, COALESCE(returned_quantity, 0) AS returned, GREATEST(COALESCE(quantity, issued_quantity, 0) - COALESCE(used_quantity,0) - COALESCE(returned_quantity,0), 0) AS not_returned, CASE WHEN GREATEST(COALESCE(quantity, issued_quantity, 0) - COALESCE(used_quantity,0) - COALESCE(returned_quantity,0), 0) > 0 THEN 'Needs Review' ELSE 'Matched' END AS status FROM marketing_takes WHERE DATE(COALESCE(take_date, created_at)) BETWEEN ? AND ? LIMIT 120", [$from, $to]) : [];

    $adjustmentRows = api_table_exists($pdo, 'inventory_movements') ? mec_fetch_rows($pdo, "SELECT COALESCE(reference_no, CONCAT('ADJ-', id)) AS adjustment_no, item_name AS product, COALESCE(sl.name, '') AS location, 0 AS system_qty, 0 AS physical_qty, COALESCE(quantity, 0) AS difference_qty, COALESCE(reason, movement_type, 'Correction') AS reason, COALESCE(u.name, u.username, '') AS created_by, 'Approved' AS approval_status, COALESCE(u.name, u.username, '') AS approved_by FROM inventory_movements im LEFT JOIN storage_locations sl ON sl.id = COALESCE(im.to_location_id, im.from_location_id) LEFT JOIN users u ON u.id = im.user_id WHERE LOWER(COALESCE(movement_type,'')) LIKE '%adjust%' AND DATE(COALESCE(movement_date, created_at)) BETWEEN ? AND ? ORDER BY im.id DESC LIMIT 120", [$from, $to]) : [];

    $expenses = [];
    if (api_table_exists($pdo, 'finance_spending')) {
        $expenseRows = mec_fetch_rows($pdo, "SELECT COALESCE(category, 'Other') AS category, COALESCE(SUM(amount), 0) AS amount FROM finance_spending WHERE DATE(COALESCE(spending_date, created_at)) BETWEEN ? AND ? GROUP BY COALESCE(category, 'Other') ORDER BY amount DESC LIMIT 40", [$from, $to]);
        foreach ($expenseRows as $row) $expenses[] = [$row['category'], (float)$row['amount']];
    }

    return [
        'sales' => [
            'summary' => [
                ['Online Sales', $onlineSales],
                ['Offline Sales', $offlineSales],
                ['Dealer Sales', $dealerSales],
                ['Online Discounts', abs($discounts)],
                ['Total Sales', $onlineSales + $offlineSales + $dealerSales],
            ],
            'issues' => [
                ['Cancelled Orders', $cancelled, $cancelled > 0 ? 'Checked' : 'Matched'],
                ['Returned Orders', $returned, $returned > 0 ? 'Checked' : 'Matched'],
                ['Pending / Incomplete Orders', $pending, $pending > 0 ? 'Needs Review' : 'Matched'],
            ],
            'orderReview' => [
                ['Completed Orders', $completedOrders, 'Checked', $completedAmount],
                ['Cancelled Orders', $cancelled, $cancelled > 0 ? 'Checked' : 'Matched', $onlineCancelledAmount],
                ['Returned Orders', $returned, $returned > 0 ? 'Checked' : 'Matched', $onlineReturnedAmount],
                ['Pending / Incomplete Orders', $pending, $pending > 0 ? 'Needs Review' : 'Matched', $pendingAmount],
            ],
            'paymentReview' => [
                ['Paid Orders', $paidOrders, 'Checked', $paidAmount],
                ['Partial Orders', $partialOrders, $partialOrders > 0 ? 'A/R' : 'Matched', $partialAmount],
                ['Unpaid Credit Orders', $unpaidCreditOrders, $unpaidCreditOrders > 0 ? 'A/R' : 'Matched', $unpaidCreditAmount],
            ],
            'paymentBreakdown' => [
                'online' => ['partial' => $onlinePartial, 'unpaid' => $onlineUnpaid],
                'offline' => ['partial' => $offlinePartial, 'unpaid' => $offlineUnpaid],
                'dealer' => ['partial' => $dealerPartial, 'unpaid' => $dealerUnpaid],
            ],
            'sourcePerformance' => [
                ['key' => 'online', 'label' => 'Online', 'orders' => $onlineOrderCount, 'amount' => $onlineSales],
                ['key' => 'offline', 'label' => 'Offline', 'orders' => $offlineOrderCount, 'amount' => $offlineSales],
                ['key' => 'dealer', 'label' => 'Dealer Order', 'orders' => $dealerOrderCount, 'amount' => $dealerSales],
                ['key' => 'total', 'label' => 'Total', 'orders' => $activeOrderCount, 'amount' => $onlineSales + $offlineSales + $dealerSales],
            ],
        ],
        'receivable' => $receivableRows,
        'receivable_summary' => $receivableSummary,
        'purchase' => $purchaseRows,
        'purchase_closing' => $purchaseClosing,
        'payable' => $payableRows,
        'cashBank' => $bankRows,
        'inventory' => $inventoryRows,
        'marketing' => $marketingRows,
        'adjustments' => $adjustmentRows,
        'expenses' => $expenses,
    ];
}

function mec_merge_actuals(array $rows, array $steps): array
{
    foreach ($steps as $step) {
        $key = (string)$step['step_key'];
        $actual = json_decode((string)($step['actual_json'] ?? ''), true);
        if (!is_array($actual)) continue;
        if ($key === 'cash_bank' && isset($actual['cashBank']) && is_array($actual['cashBank'])) $rows['cashBank'] = $actual['cashBank'];
        if ($key === 'inventory' && isset($actual['inventory']) && is_array($actual['inventory'])) $rows['inventory'] = $actual['inventory'];
    }
    return $rows;
}

function mec_step_records(PDO $pdo, int $closingId, array $rows): array
{
    $existingRows = mec_fetch_rows($pdo, 'SELECT * FROM month_end_closing_steps WHERE closing_id = ?', [$closingId]);
    $existing = [];
    foreach ($existingRows as $row) $existing[(string)$row['step_key']] = $row;

    $result = [];
    foreach (MEC_STEPS as $key => $title) {
        $systemValue = mec_system_value($key, $rows);
        $issueCount = mec_issue_count($key, $rows);
        $row = $existing[$key] ?? [];
        $status = (string)($row['status'] ?? 'PENDING');
        if ($key === 'final_review' && empty($row)) $status = 'LOCKED';
        if ($key === 'cash_bank' && empty($row) && $issueCount > 0) $status = 'NEEDS_REVIEW';
        $result[] = [
            'step_key' => $key,
            'status' => $status,
            'system_value' => $systemValue,
            'issue_count' => $issueCount,
            'note' => (string)($row['note'] ?? ''),
            'reviewed_by' => isset($row['reviewed_by']) ? mec_user_name($pdo, $row['reviewed_by']) : '',
            'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
        ];
    }
    return $result;
}

function mec_issue_count(string $key, array $rows): int
{
    if ($key === 'sales') return count(array_filter($rows['sales']['orderReview'] ?? $rows['sales']['issues'] ?? [], static fn($row) => ($row[2] ?? '') === 'Needs Review'));
    if ($key === 'receivable') return count(array_filter($rows['receivable'] ?? [], static fn($row) => strtolower((string)($row['status'] ?? '')) === 'overdue'));
    if ($key === 'purchase') {
        $review = $rows['purchase_closing']['review'] ?? [];
        if ($review) {
            return count(array_filter($review, static fn($row) => ($row[2] ?? '') === 'Needs Review'));
        }
        return count(array_filter($rows['purchase'] ?? [], static fn($row) => ($row['status'] ?? '') === 'Needs Review'));
    }
    if ($key === 'payable') return count(array_filter($rows['payable'] ?? [], static fn($row) => ($row['status'] ?? '') === 'Overdue'));
    if ($key === 'cash_bank') return count(array_filter($rows['cashBank'] ?? [], static fn($row) => isset($row['actual']) && (string)$row['actual'] !== '' && abs((float)$row['actual'] - (float)($row['system'] ?? 0)) > 0.004 && trim((string)($row['reason'] ?? '')) === ''));
    if ($key === 'inventory') return count(array_filter($rows['inventory'] ?? [], static fn($row) => isset($row['physical']) && (string)$row['physical'] !== '' && abs((float)$row['physical'] - mec_expected_inventory($row)) > 0.004));
    if ($key === 'marketing_stock') return count(array_filter($rows['marketing'] ?? [], static fn($row) => (float)($row['not_returned'] ?? 0) > 0));
    if ($key === 'adjustments') return count(array_filter($rows['adjustments'] ?? [], static fn($row) => !in_array((string)($row['approval_status'] ?? ''), ['Approved', 'approved'], true)));
    return 0;
}

function mec_expected_inventory(array $row): float
{
    if (array_key_exists('expected', $row) && $row['expected'] !== null && $row['expected'] !== '') {
        return (float)$row['expected'];
    }
    $movementExpected = (float)($row['opening'] ?? 0) + (float)($row['received'] ?? 0) + (float)($row['returns'] ?? 0) - (float)($row['sales'] ?? 0) - (float)($row['marketing'] ?? 0) + (float)($row['adjustment'] ?? 0);
    $hasMovementBreakdown = abs((float)($row['opening'] ?? 0)) > 0.0005
        || abs((float)($row['received'] ?? 0)) > 0.0005
        || abs((float)($row['returns'] ?? 0)) > 0.0005
        || abs((float)($row['sales'] ?? 0)) > 0.0005
        || abs((float)($row['marketing'] ?? 0)) > 0.0005
        || abs((float)($row['adjustment'] ?? 0)) > 0.0005;
    return $hasMovementBreakdown ? $movementExpected : (float)($row['system'] ?? 0);
}

function mec_system_value(string $key, array $rows): float
{
    if ($key === 'sales') {
        foreach (($rows['sales']['summary'] ?? []) as $row) if (($row[0] ?? '') === 'Total Sales') return (float)($row[1] ?? 0);
    }
    if ($key === 'receivable') return array_reduce($rows['receivable'] ?? [], static fn($sum, $row) => $sum + (float)($row['outstanding'] ?? 0), 0.0);
    if ($key === 'purchase') return array_reduce($rows['purchase'] ?? [], static fn($sum, $row) => $sum + (float)($row['amount'] ?? 0), 0.0);
    if ($key === 'payable') return array_reduce($rows['payable'] ?? [], static fn($sum, $row) => $sum + (float)($row['balance'] ?? 0), 0.0);
    if ($key === 'cash_bank') return array_reduce($rows['cashBank'] ?? [], static fn($sum, $row) => $sum + (float)($row['system'] ?? 0), 0.0);
    if ($key === 'inventory') return array_reduce($rows['inventory'] ?? [], static fn($sum, $row) => $sum + mec_expected_inventory($row), 0.0);
    if ($key === 'marketing_stock') return array_reduce($rows['marketing'] ?? [], static fn($sum, $row) => $sum + (float)($row['issued'] ?? 0), 0.0);
    if ($key === 'adjustments') return array_reduce($rows['adjustments'] ?? [], static fn($sum, $row) => $sum + abs((float)($row['difference_qty'] ?? 0)), 0.0);
    if ($key === 'expenses') return array_reduce($rows['expenses'] ?? [], static fn($sum, $row) => $sum + (float)($row[1] ?? 0), 0.0);
    return 0.0;
}

function mec_find_amount(array $rows, string $label): float
{
    foreach ($rows as $row) {
        if (is_array($row) && (string)($row[0] ?? '') === $label) return (float)($row[1] ?? 0);
    }
    return 0.0;
}

function mec_sum_assoc(array $rows, string $key): float
{
    return array_reduce($rows, static fn(float $sum, array $row): float => $sum + (float)($row[$key] ?? 0), 0.0);
}

function mec_sum_index(array $rows, int $index): float
{
    return array_reduce($rows, static fn(float $sum, array $row): float => $sum + (float)($row[$index] ?? 0), 0.0);
}

function mec_days_overdue(?string $date): int
{
    $date = trim((string)$date);
    if ($date === '') return 0;
    $due = strtotime($date);
    if (!$due) return 0;
    $days = (int)floor((time() - $due) / 86400);
    return max(0, $days);
}

function mec_inventory_value(PDO $pdo): float
{
    if (!api_table_exists($pdo, 'current_inventory')) return 0.0;
    $qty = mec_pick($pdo, 'current_inventory', ['quantity_on_hand', 'quantity', 'qty']);
    $cost = mec_pick($pdo, 'current_inventory', ['unit_cost', 'cost', 'average_cost']);
    if (!$qty || !$cost) return 0.0;
    $sql = 'SELECT COALESCE(SUM(' . api_quote_identifier($qty) . ' * ' . api_quote_identifier($cost) . '), 0) FROM current_inventory';
    return (float)$pdo->query($sql)->fetchColumn();
}

function mec_month_cogs(PDO $pdo, string $month): float
{
    $from = mec_month_start($month);
    $to = mec_month_end($month);
    $total = 0.0;

    if (api_table_exists($pdo, 'order_items') && api_table_exists($pdo, 'orders')) {
        $qty = mec_pick($pdo, 'order_items', ['quantity', 'qty']);
        $cost = mec_pick($pdo, 'order_items', ['unit_cost', 'cost', 'purchase_price']);
        $dateExpr = mec_date_expr($pdo, 'orders', ['payment_activity_date', 'payment_date', 'order_date', 'created_at']);
        if ($qty && $cost && $dateExpr) {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(oi.' . api_quote_identifier($qty) . ' * oi.' . api_quote_identifier($cost) . "), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE DATE($dateExpr) BETWEEN ? AND ? AND COALESCE(o.is_cancelled,0)=0 AND COALESCE(o.is_returned,0)=0");
            $stmt->execute([$from, $to]);
            $total += (float)$stmt->fetchColumn();
        }
    }

    $total += mec_sum_between($pdo, 'offline_sale_orders', ['purchase_total'], ['order_date', 'sale_date', 'created_at'], $from, $to, "LOWER(COALESCE(status,'')) NOT IN ('cancel','cancelled','canceled','return','returned')");
    return $total;
}

function mec_report_payload(PDO $pdo, string $month, array $closing, array $rows, array $steps, array $reports): array
{
    $salesSummary = $rows['sales']['summary'] ?? [];
    $orderReview = $rows['sales']['orderReview'] ?? $rows['sales']['issues'] ?? [];
    $paymentReview = $rows['sales']['paymentReview'] ?? [];
    $cashRows = $rows['cashBank'] ?? [];
    $inventoryRows = $rows['inventory'] ?? [];
    $marketingRows = $rows['marketing'] ?? [];
    $adjustmentRows = $rows['adjustments'] ?? [];
    $expenseRows = $rows['expenses'] ?? [];

    $onlineSales = mec_find_amount($salesSummary, 'Online Sales');
    $offlineSales = mec_find_amount($salesSummary, 'Offline Sales');
    $dealerSales = mec_find_amount($salesSummary, 'Dealer Sales');
    $discounts = abs(mec_find_amount($salesSummary, 'Online Discounts'));
    if ($discounts === 0.0) {
        $discounts = abs(mec_find_amount($salesSummary, 'Discounts'));
    }
    $netSales = $onlineSales + $offlineSales + $dealerSales;
    $grossSales = $netSales + $discounts;
    $expenses = mec_system_value('expenses', $rows);
    $cogs = mec_month_cogs($pdo, $month);
    $grossProfit = $netSales - $cogs;
    $netProfit = $grossProfit - $expenses;
    $cashSystem = mec_sum_assoc($cashRows, 'system');
    $cashActual = array_reduce($cashRows, static function (float $sum, array $row): float {
        return $sum + (((string)($row['actual'] ?? '') !== '') ? (float)$row['actual'] : (float)($row['system'] ?? 0));
    }, 0.0);
    $inventoryExpected = array_reduce($inventoryRows, static fn(float $sum, array $row): float => $sum + mec_expected_inventory($row), 0.0);
    $inventoryPhysical = array_reduce($inventoryRows, static fn(float $sum, array $row): float => $sum + (((string)($row['physical'] ?? '') !== '') ? (float)$row['physical'] : mec_expected_inventory($row)), 0.0);
    $marketingIssued = mec_sum_assoc($marketingRows, 'issued');
    $marketingUsed = mec_sum_assoc($marketingRows, 'used');
    $marketingReturned = mec_sum_assoc($marketingRows, 'returned');
    $marketingNotReturned = mec_sum_assoc($marketingRows, 'not_returned');
    $criticalIssues = mec_issue_count('cash_bank', $rows) + mec_issue_count('inventory', $rows) + mec_issue_count('adjustments', $rows);
    $openIssues = array_reduce(array_keys(MEC_STEPS), static fn(int $sum, string $key): int => $sum + mec_issue_count($key, $rows), 0);

    return [
        'title' => 'Month-End Closing Report',
        'period' => date('F Y', strtotime($month . '-01')),
        'month' => $month,
        'period_from' => mec_month_start($month),
        'period_to' => mec_month_end($month),
        'online_date_basis' => api_table_exists($pdo, 'print_jobs') ? 'print_date' : 'order_date',
        'status' => ((string)($closing['status'] ?? 'OPEN')) === 'CLOSED' ? 'FINAL / CLOSED' : 'DRAFT / IN PROGRESS',
        'closing_version' => (int)($closing['closing_version'] ?? 1),
        'generated_at' => date('Y-m-d H:i:s'),
        'prepared_by' => mec_user_name($pdo, $closing['created_by'] ?? 0),
        'approved_by' => mec_user_name($pdo, $closing['approved_by'] ?? 0),
        'closed_by' => mec_user_name($pdo, $closing['closed_by'] ?? 0),
        'closed_at' => (string)($closing['closed_at'] ?? ''),
        'kpis' => [
            'net_sales' => $netSales,
            'net_profit' => $netProfit,
            'receivable' => mec_system_value('receivable', $rows),
            'payable' => mec_system_value('payable', $rows),
            'cash_bank' => $cashActual,
            'inventory_value' => mec_inventory_value($pdo),
        ],
        'sales' => [
            'online_sales' => $onlineSales,
            'offline_sales' => $offlineSales,
            'dealer_sales' => $dealerSales,
            'gross_sales' => $grossSales,
            'discounts' => $discounts,
            'sales_returns' => 0.0,
            'net_sales' => $netSales,
            'order_review' => $orderReview,
            'payment_review' => $paymentReview,
        ],
        'receivable' => [
            'customer_receivable' => 0.0,
            'dealer_receivable' => mec_system_value('receivable', $rows),
            'outstanding' => mec_system_value('receivable', $rows),
            'overdue' => array_reduce($rows['receivable'] ?? [], static fn(float $sum, array $row): float => $sum + (mec_days_overdue((string)($row['due_date'] ?? '')) > 0 ? (float)($row['outstanding'] ?? 0) : 0.0), 0.0),
        ],
        'purchase' => [
            'orders' => (int)($rows['purchase_closing']['summary']['order_count'] ?? count($rows['purchase'] ?? [])),
            'gross_purchases' => mec_system_value('purchase', $rows),
            'purchase_returns' => (float)($rows['purchase_closing']['summary']['return_amount'] ?? 0),
            'net_purchases' => max(0, mec_system_value('purchase', $rows) - (float)($rows['purchase_closing']['summary']['return_amount'] ?? 0)),
            'incomplete_receiving' => (int)($rows['purchase_closing']['summary']['incomplete_count'] ?? mec_issue_count('purchase', $rows)),
            'received_qty' => (float)($rows['purchase_closing']['summary']['received_qty'] ?? 0),
            'review' => $rows['purchase_closing']['review'] ?? [],
        ],
        'payable' => [
            'supplier_invoices' => mec_system_value('payable', $rows) + mec_sum_assoc($rows['payable'] ?? [], 'paid'),
            'supplier_payments' => mec_sum_assoc($rows['payable'] ?? [], 'paid'),
            'outstanding' => mec_system_value('payable', $rows),
            'overdue' => array_reduce($rows['payable'] ?? [], static fn(float $sum, array $row): float => $sum + (($row['status'] ?? '') === 'Overdue' ? (float)($row['balance'] ?? 0) : 0.0), 0.0),
        ],
        'cash_bank' => [
            'rows' => $cashRows,
            'system_balance' => $cashSystem,
            'actual_balance' => $cashActual,
            'variance' => $cashActual - $cashSystem,
            'status' => abs($cashActual - $cashSystem) < 0.005 ? 'RECONCILED' : 'NEEDS REVIEW',
        ],
        'inventory' => [
            'expected_closing_stock' => $inventoryExpected,
            'system_closing_stock' => mec_sum_assoc($inventoryRows, 'system'),
            'physical_count' => $inventoryPhysical,
            'variance' => $inventoryPhysical - $inventoryExpected,
            'approved_adjustment' => 0.0,
            'final_reconciled_stock' => $inventoryPhysical,
            'inventory_value' => mec_inventory_value($pdo),
        ],
        'marketing' => [
            'issued' => $marketingIssued,
            'used' => $marketingUsed,
            'returned' => $marketingReturned,
            'not_returned' => $marketingNotReturned,
            'unresolved' => mec_issue_count('marketing_stock', $rows),
        ],
        'adjustments' => [
            'count' => count($adjustmentRows),
            'quantity' => mec_sum_assoc($adjustmentRows, 'difference_qty'),
            'approved' => count(array_filter($adjustmentRows, static fn(array $row): bool => in_array((string)($row['approval_status'] ?? ''), ['Approved', 'approved'], true))),
            'pending' => mec_issue_count('adjustments', $rows),
        ],
        'expenses' => [
            'rows' => $expenseRows,
            'operating_expenses' => $expenses,
        ],
        'profit_loss' => [
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $expenses,
            'net_profit' => $netProfit,
            'gross_margin' => $netSales > 0 ? ($grossProfit / $netSales) * 100 : 0,
            'net_margin' => $netSales > 0 ? ($netProfit / $netSales) * 100 : 0,
        ],
        'cash_flow' => [
            'opening_cash_bank' => 0.0,
            'cash_in' => $netSales,
            'cash_out' => $expenses + mec_sum_assoc($rows['payable'] ?? [], 'paid'),
            'net_movement' => $netSales - $expenses - mec_sum_assoc($rows['payable'] ?? [], 'paid'),
            'closing_cash_bank' => $cashActual,
        ],
        'control' => [
            'steps' => $steps,
            'critical_issues' => $criticalIssues,
            'warnings' => max(0, $openIssues - $criticalIssues),
            'reports_generated' => count($reports),
            'final_approval_status' => (string)($closing['final_approval_status'] ?? 'Pending'),
        ],
    ];
}

function mec_save_step(PDO $pdo, int $closingId, string $key, string $status, string $note, array $actuals, array $rows): void
{
    if (!isset(MEC_STEPS[$key])) api_error('Invalid closing step.', 422);
    $allowed = ['PENDING', 'IN_PROGRESS', 'NEEDS_REVIEW', 'REVIEWED', 'LOCKED'];
    if (!in_array($status, $allowed, true)) $status = 'IN_PROGRESS';
    $userId = mec_current_user_id();
    $reviewedBySql = $status === 'REVIEWED' ? ', reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at)' : ', reviewed_by = NULL, reviewed_at = NULL';
    $stmt = $pdo->prepare("INSERT INTO month_end_closing_steps (closing_id, step_key, status, system_value, issue_count, note, actual_json, reviewed_by, reviewed_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), system_value = VALUES(system_value), issue_count = VALUES(issue_count), note = VALUES(note), actual_json = VALUES(actual_json), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP {$reviewedBySql}");
    $stmt->execute([$closingId, $key, $status, mec_system_value($key, $rows), mec_issue_count($key, $rows), $note !== '' ? $note : null, $actuals ? json_encode($actuals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $status === 'REVIEWED' ? $userId : null, $status === 'REVIEWED' ? date('Y-m-d H:i:s') : null, $userId]);
    mec_log($pdo, $closingId, $key, strtolower($status), $note, $actuals);
}

function mec_response(PDO $pdo, string $month): void
{
    $closing = mec_get_closing($pdo, $month);
    $includePreviousAr = !empty($_GET['include_previous_ar']);
    $rows = mec_calculated_data($pdo, $month, $includePreviousAr);
    $savedSteps = mec_fetch_rows($pdo, 'SELECT * FROM month_end_closing_steps WHERE closing_id = ?', [(int)$closing['id']]);
    $rows = mec_merge_actuals($rows, $savedSteps);
    $steps = mec_step_records($pdo, (int)$closing['id'], $rows);
    $reports = mec_fetch_rows($pdo, 'SELECT report_key, report_label, generated_at FROM month_end_closing_reports WHERE closing_id = ? ORDER BY report_label', [(int)$closing['id']]);
    $reportPayload = mec_report_payload($pdo, $month, $closing, $rows, $steps, $reports);
    $stepLogs = mec_step_logs($pdo, (int)$closing['id']);
    api_json([
        'success' => true,
        'month' => $month,
        'closing' => [
            'id' => (int)$closing['id'],
            'period_status' => (string)$closing['status'],
            'closing_version' => (int)$closing['closing_version'],
            'final_approval_status' => (string)$closing['final_approval_status'],
            'reports_generated_at' => (string)($closing['reports_generated_at'] ?? ''),
            'approved_by' => mec_user_name($pdo, $closing['approved_by'] ?? 0),
            'approved_at' => (string)($closing['approved_at'] ?? ''),
            'closed_by' => mec_user_name($pdo, $closing['closed_by'] ?? 0),
            'closed_at' => (string)($closing['closed_at'] ?? ''),
            'final_note' => (string)($closing['final_note'] ?? ''),
        ],
        'steps' => $steps,
        'step_logs' => $stepLogs,
        'rows' => $rows,
        'reports' => $reports,
        'report' => $reportPayload,
    ]);
}

try {
    $pdo = get_db_connection();
    mec_ensure_schema($pdo);
    $payload = mec_payload();
    $month = mec_month($_GET['month'] ?? $payload['month'] ?? null);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $closing = mec_get_closing($pdo, $month);
        $closingId = (int)$closing['id'];
        $action = trim((string)($payload['action'] ?? 'save_step'));
        $userId = mec_current_user_id();
        $rows = mec_calculated_data($pdo, $month);
        $savedSteps = mec_fetch_rows($pdo, 'SELECT * FROM month_end_closing_steps WHERE closing_id = ?', [$closingId]);
        $rows = mec_merge_actuals($rows, $savedSteps);

        if ((string)$closing['status'] === 'CLOSED' && !in_array($action, ['reopen'], true)) {
            api_error(date('F Y', strtotime($month . '-01')) . ' is closed. Transactions for this accounting period cannot be modified.', 409);
        }

        if ($action === 'save_step' || $action === 'mark_review' || $action === 'mark_reviewed') {
            $key = trim((string)($payload['step_key'] ?? ''));
            $note = trim((string)($payload['note'] ?? ''));
            $actuals = is_array($payload['actuals'] ?? null) ? $payload['actuals'] : [];
            if ($key === 'cash_bank' && isset($actuals['cashBank'])) $rows['cashBank'] = $actuals['cashBank'];
            if ($key === 'inventory' && isset($actuals['inventory'])) $rows['inventory'] = $actuals['inventory'];
            $status = $action === 'mark_review' ? 'NEEDS_REVIEW' : ($action === 'mark_reviewed' ? 'REVIEWED' : (string)($payload['status'] ?? 'IN_PROGRESS'));
            if ($status === 'REVIEWED' && $key !== 'receivable' && mec_issue_count($key, $rows) > 0) {
                api_error('Resolve or explain critical issues before marking this step reviewed.', 422);
            }
            mec_save_step($pdo, $closingId, $key, $status, $note, $actuals, $rows);
            mec_response($pdo, $month);
        }

        if ($action === 'approve_final') {
            $stmt = $pdo->prepare("UPDATE month_end_closings SET final_approval_status = 'Approved', approved_by = ?, approved_at = NOW(), updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$userId, $userId, $closingId]);
            mec_save_step($pdo, $closingId, 'final_review', 'REVIEWED', trim((string)($payload['note'] ?? '')), [], $rows);
            mec_response($pdo, $month);
        }

        if ($action === 'reject_final') {
            $stmt = $pdo->prepare("UPDATE month_end_closings SET final_approval_status = 'Rejected', reports_generated_at = NULL, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$userId, $closingId]);
            mec_save_step($pdo, $closingId, 'final_review', 'NEEDS_REVIEW', trim((string)($payload['note'] ?? '')), [], $rows);
            mec_response($pdo, $month);
        }

        if ($action === 'generate_reports') {
            $labels = is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO month_end_closing_reports (closing_id, report_key, report_label, generated_by, generated_at, snapshot_json) VALUES (?, ?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE report_label = VALUES(report_label), generated_by = VALUES(generated_by), generated_at = NOW(), snapshot_json = VALUES(snapshot_json)");
            $reportSnapshot = mec_report_payload($pdo, $month, $closing, $rows, mec_step_records($pdo, $closingId, $rows), []);
            $stmt->execute([$closingId, 'month_end_closing_summary', 'Month-End Closing Summary', $userId, json_encode($reportSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            foreach ($labels as $item) {
                if (!is_array($item)) continue;
                $key = trim((string)($item['key'] ?? ''));
                $label = trim((string)($item['label'] ?? $key));
                if ($key === '') continue;
                $stmt->execute([$closingId, $key, $label, $userId, json_encode(['month' => $month, 'system_value' => mec_system_value('sales', $rows)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $pdo->prepare('UPDATE month_end_closings SET reports_generated_at = NOW(), updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId, $closingId]);
            $pdo->commit();
            mec_log($pdo, $closingId, null, 'generate_reports', '', ['reports' => $labels]);
            mec_response($pdo, $month);
        }

        if ($action === 'close') {
            $note = trim((string)($payload['note'] ?? ''));
            if ($note === '') api_error('Closing note is required.', 422);
            $stmt = $pdo->prepare("UPDATE month_end_closings SET status = 'CLOSED', final_note = ?, closed_by = ?, closed_at = NOW(), updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$note, $userId, $userId, $closingId]);
            $pdo->prepare("UPDATE month_end_closing_steps SET status = 'LOCKED', updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE closing_id = ?")->execute([$userId, $closingId]);
            mec_log($pdo, $closingId, null, 'close', $note);
            mec_response($pdo, $month);
        }

        if ($action === 'reopen') {
            $reason = trim((string)($payload['reason'] ?? ''));
            if ($reason === '') api_error('Reopen reason is required.', 422);
            $stmt = $pdo->prepare("UPDATE month_end_closings SET status = 'REOPENED', closing_version = closing_version + 1, final_approval_status = 'Pending', reports_generated_at = NULL, reopened_by = ?, reopened_at = NOW(), reopen_reason = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$userId, $reason, $userId, $closingId]);
            $pdo->prepare("UPDATE month_end_closing_steps SET status = CASE WHEN step_key = 'final_review' THEN 'LOCKED' ELSE 'NEEDS_REVIEW' END, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE closing_id = ?")->execute([$userId, $closingId]);
            mec_log($pdo, $closingId, null, 'reopen', $reason);
            mec_response($pdo, $month);
        }

        api_error('Unknown action.', 422);
    }

    mec_response($pdo, $month);
} catch (Throwable $e) {
    error_log('month_end_closing API error: ' . $e->getMessage());
    api_error('Unable to process month-end closing.', 500);
}
