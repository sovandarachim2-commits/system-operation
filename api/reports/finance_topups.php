<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../upload_paths.php';

function ft_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function ft_is_admin(array $user): bool
{
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $username = strtolower(trim((string)($user['username'] ?? '')));
    return $role === 'admin' || $username === 'admin';
}

function ft_can(array $user, string $action): bool
{
    if (ft_is_admin($user)) {
        return true;
    }
    $map = [
        'view' => ['sr_expense_topup.view', 'finance_dashboard.view', 'sr_expense_reports.view', 'sr_cashflow.view'],
        'create' => ['sr_expense_topup.create', 'finance_dashboard.create'],
        'update' => ['sr_expense_topup.update', 'finance_dashboard.update'],
        'delete' => ['sr_expense_topup.delete', 'finance_dashboard.delete'],
    ];
    foreach ($map[$action] ?? [] as $perm) {
        if (function_exists('has_permission') && has_permission($perm)) {
            return true;
        }
    }
    return false;
}

function ft_option_rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function ft_ensure_note_finance_default(PDO $pdo): void
{
    if (!api_table_exists($pdo, 'note_options')) {
        return;
    }
    $cols = array_column(api_table_columns($pdo, 'note_options'), 'name');
    if (!in_array('is_finance_default', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE note_options ADD COLUMN is_finance_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        } catch (Throwable $e) {
            // Ignore race / permission issues; source list still works without the column.
        }
    }
}

function ft_load_options(PDO $pdo): array
{
    $sources = [];
    if (api_table_exists($pdo, 'note_options')) {
        ft_ensure_note_finance_default($pdo);
        $hasFinanceDefault = in_array('is_finance_default', array_column(api_table_columns($pdo, 'note_options'), 'name'), true);
        $orderBy = $hasFinanceDefault
            ? 'COALESCE(is_finance_default, 0) DESC, sort_order, option_text'
            : 'sort_order, option_text';
        $sources = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['value'] ?? '')),
            ft_option_rows($pdo, "
                SELECT option_text AS value
                FROM note_options
                WHERE is_active = 1 AND is_admin_active = 1
                ORDER BY {$orderBy}
                LIMIT 200
            ")
        )));
    }
    if (!$sources && api_table_exists($pdo, 'finance_topups')) {
        $sources = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['value'] ?? '')),
            ft_option_rows($pdo, "
                SELECT DISTINCT source AS value
                FROM finance_topups
                WHERE source IS NOT NULL AND source <> ''
                ORDER BY source
                LIMIT 200
            ")
        )));
    }

    $users = [];
    if (api_table_exists($pdo, 'users')) {
        foreach (ft_option_rows($pdo, "
            SELECT COALESCE(NULLIF(name, ''), username) AS value
            FROM users
            WHERE active = 1
            ORDER BY value
            LIMIT 500
        ") as $row) {
            $value = trim((string)($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $users[] = ['value' => $value, 'label' => $value];
        }
    }

    return [
        'sources' => array_map(static fn(string $value): array => ['value' => $value, 'label' => $value], $sources),
        'users' => $users,
    ];
}

function ft_receipt_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    return uploaded_file_url($path, 'receipts');
}

function ft_default_finance_bank(PDO $pdo): string
{
    if (!api_table_exists($pdo, 'note_options')) {
        return '';
    }
    ft_ensure_note_finance_default($pdo);
    $hasFinanceDefault = in_array('is_finance_default', array_column(api_table_columns($pdo, 'note_options'), 'name'), true);
    if ($hasFinanceDefault) {
        $bank = trim((string)$pdo->query("
            SELECT option_text
            FROM note_options
            WHERE is_active = 1 AND is_admin_active = 1 AND is_finance_default = 1
            ORDER BY id ASC
            LIMIT 1
        ")->fetchColumn());
        if ($bank !== '') {
            return $bank;
        }
    }
    return trim((string)$pdo->query("
        SELECT option_text
        FROM note_options
        WHERE is_active = 1 AND is_admin_active = 1
        ORDER BY sort_order, option_text
        LIMIT 1
    ")->fetchColumn());
}

function ft_bank_names(PDO $pdo): array
{
    $banks = [];
    $push = static function (string $value) use (&$banks): void {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $banks[$value] = true;
    };

    if (api_table_exists($pdo, 'note_options')) {
        foreach (ft_option_rows($pdo, "
            SELECT option_text AS value
            FROM note_options
            WHERE is_active = 1 AND is_admin_active = 1
            ORDER BY sort_order, option_text
        ") as $row) {
            $push((string)($row['value'] ?? ''));
        }
    }
    if (api_table_exists($pdo, 'orders')) {
        foreach (ft_option_rows($pdo, "
            SELECT DISTINCT payment_method AS value
            FROM orders
            WHERE payment_method IS NOT NULL AND payment_method <> ''
            LIMIT 300
        ") as $row) {
            $push((string)($row['value'] ?? ''));
        }
    }
    if (api_table_exists($pdo, 'finance_topups')) {
        foreach (ft_option_rows($pdo, "
            SELECT DISTINCT source AS value
            FROM finance_topups
            WHERE source IS NOT NULL AND source <> ''
            LIMIT 300
        ") as $row) {
            $push((string)($row['value'] ?? ''));
        }
    }
    if (api_table_exists($pdo, 'cashflow_topups')) {
        foreach (ft_option_rows($pdo, "
            SELECT DISTINCT payment_method AS value
            FROM cashflow_topups
            WHERE payment_method IS NOT NULL AND payment_method <> ''
            LIMIT 300
        ") as $row) {
            $push((string)($row['value'] ?? ''));
        }
    }

    $names = array_keys($banks);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function ft_sum(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function ft_ensure_finance_topup_status(PDO $pdo): bool
{
    if (!api_table_exists($pdo, 'finance_topups')) {
        return false;
    }
    $cols = array_column(api_table_columns($pdo, 'finance_topups'), 'name');
    if (!in_array('status', $cols, true)) {
        $pdo->exec("ALTER TABLE finance_topups ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER person_name");
        return true;
    }
    return true;
}

function ft_topup_money_condition(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "LOWER(COALESCE(NULLIF(TRIM({$prefix}status), ''), 'completed')) IN ('approved', 'completed')";
}

function ft_topup_status(string $value): string
{
    $status = strtolower(trim($value));
    return in_array($status, ['pending', 'approved', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function ft_finance_spending_money_condition(PDO $pdo, string $alias = ''): string
{
    if (!api_table_exists($pdo, 'finance_spending')) {
        return '';
    }
    $cols = array_column(api_table_columns($pdo, 'finance_spending'), 'name');
    if (!in_array('status', $cols, true)) {
        return '';
    }
    $prefix = $alias !== '' ? $alias . '.' : '';
    return " AND LOWER(COALESCE(NULLIF(TRIM({$prefix}status), ''), '')) IN ('approved', 'completed')";
}

/**
 * Cashflow closing balance as of $toDate (inclusive).
 * - $bank = null  => all banks combined
 * - $bank = name  => one bank (finance spending by payment_method; blank rows on default bank)
 */
function ft_cashflow_balance_as_of(PDO $pdo, string $toDate, ?string $bank = null): array
{
    $bank = $bank !== null ? trim($bank) : null;
    $defaultFinanceBank = ft_default_finance_bank($pdo);
    $bankFilter = $bank !== null && $bank !== '';

    $ordersIn = 0.0;
    if (api_table_exists($pdo, 'orders')) {
        if ($bankFilter) {
            $ordersIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(total_amount), 0)
                FROM orders
                WHERE status = 'paid'
                  AND is_cancelled = 0
                  AND is_returned = 0
                  AND COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
                  AND COALESCE(payment_date, DATE(created_at)) <= ?
            ", [$bank, $toDate]);
        } else {
            $ordersIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(total_amount), 0)
                FROM orders
                WHERE status = 'paid'
                  AND is_cancelled = 0
                  AND is_returned = 0
                  AND COALESCE(payment_date, DATE(created_at)) <= ?
            ", [$toDate]);
        }
    }

    $cashflowTopupIn = 0.0;
    if (api_table_exists($pdo, 'cashflow_topups')) {
        if ($bankFilter) {
            $cashflowTopupIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM cashflow_topups
                WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
                  AND topup_date <= ?
            ", [$bank, $toDate]);
        } else {
            $cashflowTopupIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM cashflow_topups
                WHERE topup_date <= ?
            ", [$toDate]);
        }
    }

    $financeTopupIn = 0.0;
    if (api_table_exists($pdo, 'finance_topups')) {
        $hasTopupStatus = in_array('status', array_column(api_table_columns($pdo, 'finance_topups'), 'name'), true);
        $topupStatusSql = $hasTopupStatus ? ' AND ' . ft_topup_money_condition() : '';
        if ($bankFilter) {
            $financeTopupIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_topups
                WHERE COALESCE(NULLIF(TRIM(source), ''), '(No method)') = ?
                  AND DATE(topup_date) <= ?
                  {$topupStatusSql}
            ", [$bank, $toDate]);
        } else {
            $financeTopupIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_topups
                WHERE DATE(topup_date) <= ?
                  {$topupStatusSql}
            ", [$toDate]);
        }
    }

    $cashflowSpendingOut = 0.0;
    if (api_table_exists($pdo, 'cashflow_spending')) {
        if ($bankFilter) {
            $cashflowSpendingOut = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM cashflow_spending
                WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
                  AND spending_date <= ?
            ", [$bank, $toDate]);
        } else {
            $cashflowSpendingOut = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM cashflow_spending
                WHERE spending_date <= ?
            ", [$toDate]);
        }
    }

    $financeSpendingOut = 0.0;
    if (api_table_exists($pdo, 'finance_spending')) {
        $spendingStatusSql = ft_finance_spending_money_condition($pdo);
        $hasPaymentMethod = in_array(
            'payment_method',
            array_column(api_table_columns($pdo, 'finance_spending'), 'name'),
            true
        );
        if ($bankFilter) {
            if ($hasPaymentMethod) {
                // Blank/old rows belong to the default finance bank only.
                $fallbackBank = $defaultFinanceBank !== '' ? $defaultFinanceBank : $bank;
                $financeSpendingOut = ft_sum($pdo, "
                    SELECT COALESCE(SUM(amount), 0)
                    FROM finance_spending
                    WHERE DATE(spending_date) <= ?
                      AND COALESCE(NULLIF(TRIM(payment_method), ''), ?) = ?
                      {$spendingStatusSql}
                ", [$toDate, $fallbackBank, $bank]);
            } elseif ($defaultFinanceBank !== '' && strcasecmp($bank, $defaultFinanceBank) === 0) {
                $financeSpendingOut = ft_sum($pdo, "
                    SELECT COALESCE(SUM(amount), 0)
                    FROM finance_spending
                    WHERE DATE(spending_date) <= ?
                      {$spendingStatusSql}
                ", [$toDate]);
            }
        } else {
            $financeSpendingOut = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_spending
                WHERE DATE(spending_date) <= ?
                  {$spendingStatusSql}
            ", [$toDate]);
        }
    }

    $transferIn = 0.0;
    $transferOut = 0.0;
    if (api_table_exists($pdo, 'bank_transfers')) {
        if ($bankFilter) {
            $transferIn = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM bank_transfers
                WHERE to_bank = ? AND transfer_date <= ?
            ", [$bank, $toDate]);
            $transferOut = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM bank_transfers
                WHERE from_bank = ? AND transfer_date <= ?
            ", [$bank, $toDate]);
        }
        // All-bank transfers net to zero, so skip when no bank filter.
    }

    $balance = $ordersIn
        + $cashflowTopupIn
        + $financeTopupIn
        - $cashflowSpendingOut
        - $financeSpendingOut
        + $transferIn
        - $transferOut;

    return [
        'balance' => $balance,
        'orders_in' => $ordersIn,
        'cashflow_topup_in' => $cashflowTopupIn,
        'finance_topup_in' => $financeTopupIn,
        'cashflow_spending_out' => $cashflowSpendingOut,
        'finance_spending_out' => $financeSpendingOut,
        'transfer_in' => $transferIn,
        'transfer_out' => $transferOut,
    ];
}

function ft_day_before(string $date): string
{
    $timestamp = strtotime($date . ' -1 day');
    return $timestamp ? date('Y-m-d', $timestamp) : $date;
}

function ft_serialize_row(array $item): array
{
    $receipt = (string)($item['receipt_image'] ?? '');
    return [
        'id' => (string)($item['id'] ?? ''),
        'amount' => (float)($item['amount'] ?? 0),
        'source' => (string)($item['source'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'person_name' => (string)($item['person_name'] ?? ''),
        'status' => ft_topup_status((string)($item['status'] ?? 'completed')),
        'topup_date' => (string)($item['topup_date'] ?? ''),
        'receipt_image' => $receipt,
        'receipt_url' => ft_receipt_url($receipt),
        'created_by' => (string)($item['created_by'] ?? ''),
        'created_by_label' => (string)($item['created_by_name'] ?? $item['created_by'] ?? ''),
        'created_at' => (string)($item['created_at'] ?? ''),
        'updated_at' => (string)($item['updated_at'] ?? ''),
    ];
}

function ft_handle_receipt_upload(string $topupDate): ?string
{
    if (!isset($_FILES['receipt_image']) || ($_FILES['receipt_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES['receipt_image'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;
    if (!in_array((string)($file['type'] ?? ''), $allowed, true) || (int)($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException('Receipt must be an image under 5MB.');
    }
    $filename = 'receipt_' . time() . '_' . uniqid('', true) . '.' . pathinfo((string)$file['name'], PATHINFO_EXTENSION);
    $stored = upload_store_uploaded_file($file, 'receipts', $filename, $topupDate, (string)($file['type'] ?? ''));
    return $stored !== '' ? $stored : null;
}

try {
    $user = current_user(true) ?: [];
    if (!$user || !ft_can($user, 'view')) {
        api_error('Access denied.', 403);
    }

    $pdo = get_db_connection();
    if (!api_table_exists($pdo, 'finance_topups')) {
        api_json([
            'success' => true,
            'rows' => [],
            'summary' => [
                'total_rows' => 0,
                'total_amount' => 0,
                'unique_sources' => 0,
                'unique_persons' => 0,
                'opening_balance' => 0,
                'period_balance' => 0,
            ],
            'options' => ['sources' => [], 'users' => []],
            'permissions' => [
                'canCreate' => false,
                'canUpdate' => false,
                'canDelete' => false,
            ],
        ]);
    }
    $hasTopupStatus = ft_ensure_finance_topup_status($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = trim((string)($_POST['action'] ?? 'create'));
        if ($action === 'create') {
            if (!ft_can($user, 'create')) {
                api_error('You do not have permission to create top-ups.', 403);
            }
            $amount = (float)($_POST['amount'] ?? 0);
            $source = trim((string)($_POST['source'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $personName = trim((string)($_POST['person_name'] ?? ''));
            $status = ft_topup_status((string)($_POST['status'] ?? 'pending'));
            $topupDate = ft_date($_POST['topup_date'] ?? null, date('Y-m-d'));
            if ($amount <= 0) {
                api_error('Amount must be greater than 0.', 422);
            }
            if ($source === '') {
                api_error('Source is required.', 422);
            }
            if ($personName === '') {
                api_error('Person name is required.', 422);
            }
            $receipt = ft_handle_receipt_upload($topupDate);
            $createdBy = (int)($user['id'] ?? 0) ?: 'Admin';
            $stmt = $pdo->prepare('INSERT INTO finance_topups
                (amount, source, description, topup_date, receipt_image, person_name, status, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)');
            $stmt->execute([$amount, $source, $description, $topupDate, $receipt, $personName, $status, $createdBy]);
            api_json(['success' => true, 'message' => 'Top-up saved.', 'id' => (string)$pdo->lastInsertId()]);
        }

        if ($action === 'update') {
            if (!ft_can($user, 'update')) {
                api_error('You do not have permission to update top-ups.', 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $source = trim((string)($_POST['source'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $personName = trim((string)($_POST['person_name'] ?? ''));
            $status = ft_topup_status((string)($_POST['status'] ?? 'pending'));
            $topupDate = ft_date($_POST['topup_date'] ?? null, date('Y-m-d'));
            if ($id <= 0) {
                api_error('Invalid top-up ID.', 422);
            }
            if ($amount <= 0) {
                api_error('Amount must be greater than 0.', 422);
            }
            if ($source === '') {
                api_error('Source is required.', 422);
            }
            if ($personName === '') {
                api_error('Person name is required.', 422);
            }
            $stmt = $pdo->prepare('SELECT receipt_image FROM finance_topups WHERE id = ?');
            $stmt->execute([$id]);
            $oldReceipt = $stmt->fetchColumn();
            if ($oldReceipt === false) {
                api_error('Top-up record not found.', 404);
            }
            $receipt = ft_handle_receipt_upload($topupDate);
            $fields = ['amount = ?', 'source = ?', 'description = ?', 'topup_date = ?', 'person_name = ?', 'status = ?', 'updated_at = CURRENT_TIMESTAMP'];
            $params = [$amount, $source, $description, $topupDate, $personName, $status];
            if ($receipt) {
                $fields[] = 'receipt_image = ?';
                $params[] = $receipt;
                upload_delete_local_file($oldReceipt ?: null, 'receipts');
            }
            $params[] = $id;
            $pdo->prepare('UPDATE finance_topups SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            api_json(['success' => true, 'message' => 'Top-up updated.']);
        }

        if ($action === 'delete') {
            if (!ft_can($user, 'delete')) {
                api_error('You do not have permission to delete top-ups.', 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                api_error('Invalid top-up ID.', 422);
            }
            $stmt = $pdo->prepare('SELECT * FROM finance_topups WHERE id = ?');
            $stmt->execute([$id]);
            $topup = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$topup) {
                api_error('Top-up record not found.', 404);
            }
            $topupStatusSql = $hasTopupStatus ? ' WHERE ' . ft_topup_money_condition() : '';
            $totalTopups = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM finance_topups' . $topupStatusSql)->fetchColumn();
            $spendingStatusSql = ft_finance_spending_money_condition($pdo);
            $totalSpending = api_table_exists($pdo, 'finance_spending')
                ? (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE 1=1' . $spendingStatusSql)->fetchColumn()
                : 0.0;
            $deletedTopupAmount = in_array(ft_topup_status((string)($topup['status'] ?? 'completed')), ['approved', 'completed'], true)
                ? (float)($topup['amount'] ?? 0)
                : 0.0;
            $newBalance = ($totalTopups - $deletedTopupAmount) - $totalSpending;
            if ($newBalance < -1000) {
                api_error('Cannot delete this top-up because the balance would go below -$1,000.', 422);
            }
            upload_delete_local_file($topup['receipt_image'] ?? null, 'receipts');
            $pdo->prepare('DELETE FROM finance_topups WHERE id = ?')->execute([$id]);
            api_json(['success' => true, 'message' => 'Top-up deleted.']);
        }

        api_error('Unsupported action.', 422);
    }

    $from = ft_date($_GET['from'] ?? $_GET['from_date'] ?? $_GET['start_date'] ?? null, date('Y-m-01'));
    $to = ft_date($_GET['to'] ?? $_GET['to_date'] ?? $_GET['end_date'] ?? null, date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $sourceFilter = trim((string)($_GET['source'] ?? ''));

    $where = ['DATE(ft.topup_date) BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($sourceFilter !== '') {
        $where[] = 'ft.source = ?';
        $params[] = $sourceFilter;
    }
    $whereSql = implode(' AND ', $where);
    $moneyWhere = $where;
    $moneyParams = $params;
    if ($hasTopupStatus) {
        $moneyWhere[] = ft_topup_money_condition('ft');
    }
    $moneyWhereSql = implode(' AND ', $moneyWhere);

    $stmt = $pdo->prepare("
        SELECT ft.*
        FROM finance_topups ft
        WHERE {$whereSql}
        ORDER BY ft.topup_date DESC, ft.id DESC
    ");
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userMap = [];
    if (api_table_exists($pdo, 'users')) {
        foreach (ft_option_rows($pdo, 'SELECT id, name, username FROM users') as $userRow) {
            $name = trim((string)($userRow['name'] ?? '')) ?: trim((string)($userRow['username'] ?? ''));
            if ($name === '') {
                continue;
            }
            $userMap[(string)($userRow['id'] ?? '')] = $name;
            $userMap[strtolower(trim((string)($userRow['username'] ?? '')))] = $name;
            $userMap[strtolower($name)] = $name;
        }
    }

    $rows = [];
    foreach ($rawRows as $item) {
        $createdBy = trim((string)($item['created_by'] ?? ''));
        $createdLabel = $userMap[$createdBy]
            ?? $userMap[strtolower($createdBy)]
            ?? $createdBy;
        $item['created_by_name'] = $createdLabel;
        $rows[] = ft_serialize_row($item);
    }

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(amount), 0) AS total_amount,
            COUNT(DISTINCT NULLIF(source, '')) AS unique_sources,
            COUNT(DISTINCT NULLIF(person_name, '')) AS unique_persons
        FROM finance_topups ft
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $moneyStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS effective_amount
        FROM finance_topups ft
        WHERE {$moneyWhereSql}
    ");
    $moneyStmt->execute($moneyParams);
    $periodTopups = (float)$moneyStmt->fetchColumn();

    // Balances always use all banks unless a source/bank filter is selected.
    $balanceBank = $sourceFilter !== '' ? $sourceFilter : null;
    $openingAsOf = ft_day_before($from);
    $openingParts = ft_cashflow_balance_as_of($pdo, $openingAsOf, $balanceBank);
    $closingParts = ft_cashflow_balance_as_of($pdo, $to, $balanceBank);
    $openingBalance = (float)$openingParts['balance'];
    $periodBalance = (float)$closingParts['balance'];

    $bankRows = [];
    foreach (ft_bank_names($pdo) as $bankName) {
        if ($sourceFilter !== '' && strcasecmp($bankName, $sourceFilter) !== 0) {
            continue;
        }
        $open = ft_cashflow_balance_as_of($pdo, $openingAsOf, $bankName);
        $close = ft_cashflow_balance_as_of($pdo, $to, $bankName);
        $topupIn = (float)$close['finance_topup_in'] - (float)$open['finance_topup_in'];
        $expenseOut = ((float)$close['finance_spending_out'] + (float)$close['cashflow_spending_out'])
            - ((float)$open['finance_spending_out'] + (float)$open['cashflow_spending_out']);
        $bankRows[] = [
            'bank' => $bankName,
            'opening_balance' => (float)$open['balance'],
            'period_balance' => (float)$close['balance'],
            'finance_topup_in' => $topupIn,
            'expense_out' => $expenseOut,
        ];
    }

    $periodSpending = 0.0;
    if (api_table_exists($pdo, 'finance_spending')) {
        $spendingStatusSql = ft_finance_spending_money_condition($pdo);
        $defaultFinanceBank = ft_default_finance_bank($pdo);
        $hasPaymentMethod = in_array(
            'payment_method',
            array_column(api_table_columns($pdo, 'finance_spending'), 'name'),
            true
        );
        if ($sourceFilter === '') {
            $periodSpending = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_spending
                WHERE DATE(spending_date) BETWEEN ? AND ?
                  {$spendingStatusSql}
            ", [$from, $to]);
        } elseif ($hasPaymentMethod) {
            $fallbackBank = $defaultFinanceBank !== '' ? $defaultFinanceBank : $sourceFilter;
            $periodSpending = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_spending
                WHERE DATE(spending_date) BETWEEN ? AND ?
                  AND COALESCE(NULLIF(TRIM(payment_method), ''), ?) = ?
                  {$spendingStatusSql}
            ", [$from, $to, $fallbackBank, $sourceFilter]);
        } elseif ($defaultFinanceBank !== '' && strcasecmp($sourceFilter, $defaultFinanceBank) === 0) {
            $periodSpending = ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_spending
                WHERE DATE(spending_date) BETWEEN ? AND ?
                  {$spendingStatusSql}
            ", [$from, $to]);
        }
    }

    // Include cashflow spending in period expense when filtered/all.
    if (api_table_exists($pdo, 'cashflow_spending')) {
        if ($sourceFilter === '') {
            $periodSpending += ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM cashflow_spending
                WHERE spending_date BETWEEN ? AND ?
            ", [$from, $to]);
        } else {
            $periodSpending += ft_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM cashflow_spending
                WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
                  AND spending_date BETWEEN ? AND ?
            ", [$sourceFilter, $from, $to]);
        }
    }

    api_json([
        'success' => true,
        'rows' => $rows,
        'summary' => [
            'from' => $from,
            'to' => $to,
            'total_rows' => (int)($summary['total_count'] ?? count($rows)),
            'total_amount' => $periodTopups,
            'listed_amount' => (float)($summary['total_amount'] ?? 0),
            'unique_sources' => (int)($summary['unique_sources'] ?? 0),
            'unique_persons' => (int)($summary['unique_persons'] ?? 0),
            'opening_balance' => $openingBalance,
            'period_balance' => $periodBalance,
            'period_spending' => $periodSpending,
            'balance_scope' => $balanceBank ? 'bank' : 'all_banks',
            'balance_bank' => $balanceBank,
            'opening_as_of' => $openingAsOf,
            'closing_as_of' => $to,
            'opening_parts' => $openingParts,
            'closing_parts' => $closingParts,
        ],
        'banks' => $bankRows,
        'options' => ft_load_options($pdo),
        'permissions' => [
            'canCreate' => ft_can($user, 'create'),
            'canUpdate' => ft_can($user, 'update'),
            'canDelete' => ft_can($user, 'delete'),
        ],
    ]);
} catch (Throwable $e) {
    error_log('finance_topups API error: ' . $e->getMessage());
    api_error($e->getMessage() ?: 'Unable to load top-up report.', 500);
}
