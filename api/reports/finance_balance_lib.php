<?php
declare(strict_types=1);

function finance_balance_sum(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function finance_balance_ensure_note_default(PDO $pdo): void
{
    if (!function_exists('api_table_exists') || !api_table_exists($pdo, 'note_options')) {
        return;
    }
    $cols = array_column(api_table_columns($pdo, 'note_options'), 'name');
    if (!in_array('is_finance_default', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE note_options ADD COLUMN is_finance_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function finance_balance_default_bank(PDO $pdo): string
{
    if (!function_exists('api_table_exists') || !api_table_exists($pdo, 'note_options')) {
        return '';
    }
    finance_balance_ensure_note_default($pdo);
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

/**
 * Available finance spending balance as of a date.
 * Matches admin/add_spending.php get_finance_available_balance().
 */
function finance_available_balance(PDO $pdo, string $toDate, ?string $bank = null): array
{
    $toDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) ? $toDate : date('Y-m-d');
    $bank = trim((string)($bank ?? ''));
    if ($bank === '') {
        $bank = finance_balance_default_bank($pdo);
    }
    if ($bank === '') {
        return [
            'balance' => 0.0,
            'bank' => '',
            'as_of' => $toDate,
            'is_today' => $toDate === date('Y-m-d'),
        ];
    }

    $ordersIn = function_exists('api_table_exists') && api_table_exists($pdo, 'orders')
        ? finance_balance_sum($pdo, "
            SELECT COALESCE(SUM(total_amount), 0)
            FROM orders
            WHERE status = 'paid'
              AND is_cancelled = 0
              AND is_returned = 0
              AND COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND COALESCE(payment_date, DATE(created_at)) <= ?
        ", [$bank, $toDate])
        : 0.0;

    $cashflowTopupIn = function_exists('api_table_exists') && api_table_exists($pdo, 'cashflow_topups')
        ? finance_balance_sum($pdo, "
            SELECT COALESCE(SUM(amount), 0)
            FROM cashflow_topups
            WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND topup_date <= ?
        ", [$bank, $toDate])
        : 0.0;

    $financeTopupIn = 0.0;
    if (function_exists('api_table_exists') && api_table_exists($pdo, 'finance_topups')) {
        $topupColumns = array_column(api_table_columns($pdo, 'finance_topups'), 'name');
        $topupStatusSql = in_array('status', $topupColumns, true)
            ? " AND LOWER(COALESCE(NULLIF(TRIM(status), ''), 'completed')) IN ('approved', 'completed')"
            : '';
        $financeTopupIn = finance_balance_sum($pdo, "
            SELECT COALESCE(SUM(amount), 0)
            FROM finance_topups
            WHERE COALESCE(NULLIF(TRIM(source), ''), '(No method)') = ?
              AND DATE(topup_date) <= ?
              {$topupStatusSql}
        ", [$bank, $toDate]);
    }

    $cashflowSpendingOut = function_exists('api_table_exists') && api_table_exists($pdo, 'cashflow_spending')
        ? finance_balance_sum($pdo, "
            SELECT COALESCE(SUM(amount), 0)
            FROM cashflow_spending
            WHERE COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') = ?
              AND spending_date <= ?
        ", [$bank, $toDate])
        : 0.0;

    $financeSpendingOut = 0.0;
    if (function_exists('api_table_exists') && api_table_exists($pdo, 'finance_spending')) {
        $defaultBank = finance_balance_default_bank($pdo);
        $financeColumns = array_column(api_table_columns($pdo, 'finance_spending'), 'name');
        $hasPaymentMethod = in_array(
            'payment_method',
            $financeColumns,
            true
        );
        $statusSql = in_array('status', $financeColumns, true)
            ? " AND LOWER(COALESCE(status, '')) IN ('approved', 'completed')"
            : '';
        if ($hasPaymentMethod) {
            // Old/blank payment_method rows belong to the default finance bank (ABA-Shadow),
            // not to whichever bank is currently selected.
            $fallbackBank = $defaultBank !== '' ? $defaultBank : $bank;
            $financeSpendingOut = finance_balance_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_spending
                WHERE DATE(spending_date) <= ?
                  {$statusSql}
                  AND COALESCE(NULLIF(TRIM(payment_method), ''), ?) = ?
            ", [$toDate, $fallbackBank, $bank]);
        } elseif ($defaultBank !== '' && strcasecmp($bank, $defaultBank) === 0) {
            $financeSpendingOut = finance_balance_sum($pdo, "
                SELECT COALESCE(SUM(amount), 0)
                FROM finance_spending
                WHERE DATE(spending_date) <= ?
                  {$statusSql}
            ", [$toDate]);
        }
    }

    $transferIn = function_exists('api_table_exists') && api_table_exists($pdo, 'bank_transfers')
        ? finance_balance_sum($pdo, "
            SELECT COALESCE(SUM(amount), 0)
            FROM bank_transfers
            WHERE to_bank = ? AND transfer_date <= ?
        ", [$bank, $toDate])
        : 0.0;

    $transferOut = function_exists('api_table_exists') && api_table_exists($pdo, 'bank_transfers')
        ? finance_balance_sum($pdo, "
            SELECT COALESCE(SUM(amount), 0)
            FROM bank_transfers
            WHERE from_bank = ? AND transfer_date <= ?
        ", [$bank, $toDate])
        : 0.0;

    $balance = $ordersIn + $cashflowTopupIn + $financeTopupIn - $cashflowSpendingOut - $financeSpendingOut + $transferIn - $transferOut;

    return [
        'balance' => $balance,
        'bank' => $bank,
        'as_of' => $toDate,
        'is_today' => $toDate === date('Y-m-d'),
        'parts' => [
            'orders_in' => $ordersIn,
            'cashflow_topup_in' => $cashflowTopupIn,
            'finance_topup_in' => $financeTopupIn,
            'cashflow_spending_out' => $cashflowSpendingOut,
            'finance_spending_out' => $financeSpendingOut,
            'transfer_in' => $transferIn,
            'transfer_out' => $transferOut,
        ],
    ];
}

function finance_bank_names(PDO $pdo): array
{
    $banks = [];
    $push = static function (string $value) use (&$banks): void {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $banks[$value] = true;
    };

    if (function_exists('api_table_exists') && api_table_exists($pdo, 'note_options')) {
        finance_balance_ensure_note_default($pdo);
        $hasSort = in_array('sort_order', array_column(api_table_columns($pdo, 'note_options'), 'name'), true);
        $sql = $hasSort
            ? "SELECT option_text AS value FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text"
            : "SELECT option_text AS value FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY option_text";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $push((string)($row['value'] ?? ''));
        }
    }

    $names = array_keys($banks);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

/**
 * Dashboard balances for each payment method / bank as of a date.
 */
function finance_dashboard_balances(PDO $pdo, string $asOf): array
{
    $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    $defaultBank = finance_balance_default_bank($pdo);
    $banks = [];
    $total = 0.0;

    foreach (finance_bank_names($pdo) as $bankName) {
        $row = finance_available_balance($pdo, $asOf, $bankName);
        $balance = (float)($row['balance'] ?? 0);
        $total += $balance;
        $banks[] = [
            'bank' => $bankName,
            'balance' => $balance,
            'is_default' => $defaultBank !== '' && strcasecmp($bankName, $defaultBank) === 0,
        ];
    }

    usort($banks, static function (array $a, array $b): int {
        if (!empty($a['is_default']) && empty($b['is_default'])) {
            return -1;
        }
        if (empty($a['is_default']) && !empty($b['is_default'])) {
            return 1;
        }
        return strcasecmp((string)$a['bank'], (string)$b['bank']);
    });

    return [
        'as_of' => $asOf,
        'is_today' => $asOf === date('Y-m-d'),
        'default_bank' => $defaultBank,
        'total_balance' => $total,
        'banks' => $banks,
    ];
}
