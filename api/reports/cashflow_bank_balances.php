<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_role_or_permission(['admin'], 'sr_bank_balances.view', 'sr_cashflow.view', 'daily_summary.view');

function bank_balance_date(?string $value): ?string
{
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function bank_balance_json_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function bank_balance_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cashflow_bank_balances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            balance_date DATE NOT NULL,
            balance_type VARCHAR(20) NOT NULL DEFAULT 'ending',
            bank_name VARCHAR(150) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            note TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cashflow_bank_balance (balance_date, balance_type, bank_name),
            INDEX idx_cashflow_bank_balances_date (balance_date),
            INDEX idx_cashflow_bank_balances_type (balance_type),
            INDEX idx_cashflow_bank_balances_bank (bank_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = array_fill_keys(array_map(static fn(array $row): string => (string)$row['name'], api_table_columns($pdo, 'cashflow_bank_balances')), true);
    if (!isset($columns['balance_type'])) {
        $pdo->exec("ALTER TABLE cashflow_bank_balances ADD COLUMN balance_type VARCHAR(20) NOT NULL DEFAULT 'ending' AFTER balance_date");
        $pdo->exec("UPDATE cashflow_bank_balances SET balance_type = 'ending' WHERE balance_type IS NULL OR balance_type = ''");
    }

    $indexColumns = [];
    try {
        $indexStmt = $pdo->query("SHOW INDEX FROM cashflow_bank_balances WHERE Key_name = 'uniq_cashflow_bank_balance'");
        foreach ($indexStmt->fetchAll(PDO::FETCH_ASSOC) as $indexRow) {
            $indexColumns[(int)$indexRow['Seq_in_index']] = (string)$indexRow['Column_name'];
        }
        ksort($indexColumns);
    } catch (Throwable $e) {
        $indexColumns = [];
    }
    if (array_values($indexColumns) !== ['balance_date', 'balance_type', 'bank_name']) {
        try {
            $pdo->exec("ALTER TABLE cashflow_bank_balances DROP INDEX uniq_cashflow_bank_balance");
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec("ALTER TABLE cashflow_bank_balances ADD UNIQUE KEY uniq_cashflow_bank_balance (balance_date, balance_type, bank_name)");
        } catch (Throwable $e) {
        }
    }
    try {
        $pdo->exec("ALTER TABLE cashflow_bank_balances ADD INDEX idx_cashflow_bank_balances_type (balance_type)");
    } catch (Throwable $e) {
    }
}

function bank_balance_options(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT value, value AS label
        FROM (
            SELECT DISTINCT bank_name AS value
            FROM cashflow_bank_balances
            WHERE bank_name IS NOT NULL AND bank_name <> ''
            UNION
            SELECT DISTINCT payment_method AS value
            FROM orders
            WHERE payment_method IS NOT NULL AND payment_method <> ''
            UNION
            SELECT DISTINCT option_text AS value
            FROM note_options
            WHERE option_text IS NOT NULL AND option_text <> '' AND is_active = 1 AND is_admin_active = 1
        ) bank_options
        ORDER BY value
        LIMIT 200
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $pdo = get_db_connection();
    bank_balance_ensure_table($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = bank_balance_json_payload();
        $action = trim((string)($payload['action'] ?? 'save'));
        $user = function_exists('current_user') ? (current_user() ?: []) : [];
        $userId = isset($user['id']) ? (int)$user['id'] : null;

        if ($action === 'delete') {
            $id = (int)($payload['id'] ?? 0);
            if ($id <= 0) {
                api_error('Balance row is required.', 422);
            }

            $stmt = $pdo->prepare("DELETE FROM cashflow_bank_balances WHERE id = ?");
            $stmt->execute([$id]);

            api_json([
                'success' => true,
                'message' => 'Balance deleted.',
            ]);
        }

        if ($action === 'bulk_save') {
            $balanceDate = bank_balance_date($payload['balance_date'] ?? null);
            $balances = is_array($payload['balances'] ?? null) ? $payload['balances'] : [];

            if ($balanceDate === null) {
                api_error('Balance date is required.', 422);
            }

            $stmt = $pdo->prepare("
                INSERT INTO cashflow_bank_balances
                    (balance_date, balance_type, bank_name, amount, note, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    amount = VALUES(amount),
                    note = VALUES(note),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ");

            $saved = 0;
            foreach ($balances as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $bankName = trim((string)($row['bank_name'] ?? ''));
                $rawAmount = $row['amount'] ?? null;
                $note = trim((string)($row['note'] ?? ''));
                $isBlank = $bankName === '' && trim((string)$rawAmount) === '' && $note === '';
                if ($isBlank) {
                    continue;
                }

                $amount = filter_var($rawAmount, FILTER_VALIDATE_FLOAT);
                if ($bankName === '') {
                    api_error('Bank account is required on row ' . ($index + 1) . '.', 422);
                }
                if ($amount === false || $amount === null) {
                    api_error('Balance amount is required on row ' . ($index + 1) . '.', 422);
                }

                $stmt->execute([$balanceDate, 'ending', $bankName, (float)$amount, $note !== '' ? $note : null, $userId, $userId]);
                $saved++;
            }

            if ($saved <= 0) {
                api_error('At least one bank balance is required.', 422);
            }

            api_json([
                'success' => true,
                'message' => $saved . ' ending balance' . ($saved === 1 ? '' : 's') . ' saved.',
                'saved' => $saved,
            ]);
        }

        $balanceDate = bank_balance_date($payload['balance_date'] ?? null);
        $bankName = trim((string)($payload['bank_name'] ?? ''));
        $amount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $note = trim((string)($payload['note'] ?? ''));

        if ($balanceDate === null) {
            api_error('Balance date is required.', 422);
        }
        if ($bankName === '') {
            api_error('Bank account is required.', 422);
        }
        if ($amount === false || $amount === null) {
            api_error('Summary amount is required.', 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO cashflow_bank_balances
                (balance_date, balance_type, bank_name, amount, note, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                amount = VALUES(amount),
                note = VALUES(note),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$balanceDate, 'ending', $bankName, (float)$amount, $note !== '' ? $note : null, $userId, $userId]);

        api_json([
            'success' => true,
            'message' => 'Balance saved.',
        ]);
    }

    $balanceDate = bank_balance_date($_GET['date'] ?? null) ?: date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.balance_date,
            b.balance_type,
            b.bank_name,
            b.amount,
            b.note,
            b.created_at,
            b.updated_at,
            COALESCE(NULLIF(creator.name, ''), creator.username) AS created_by_name,
            COALESCE(NULLIF(updater.name, ''), updater.username) AS updated_by_name
        FROM cashflow_bank_balances b
        LEFT JOIN users creator ON creator.id = b.created_by
        LEFT JOIN users updater ON updater.id = b.updated_by
        WHERE b.balance_date = ? AND b.balance_type = 'ending'
        ORDER BY b.bank_name ASC
    ");
    $stmt->execute([$balanceDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $historyStmt = $pdo->query("
        SELECT balance_date, COUNT(*) AS account_count, SUM(amount) AS total_amount
        FROM cashflow_bank_balances
        WHERE balance_type = 'ending'
        GROUP BY balance_date
        ORDER BY balance_date DESC
        LIMIT 60
    ");
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    api_json([
        'success' => true,
        'date' => $balanceDate,
        'rows' => $rows,
        'history_rows' => $historyRows,
        'bank_options' => bank_balance_options($pdo),
        'summary' => [
            'account_count' => count($rows),
            'total_amount' => array_reduce($rows, static fn(float $sum, array $row): float => $sum + (float)($row['amount'] ?? 0), 0.0),
        ],
    ]);
} catch (Throwable $e) {
    error_log('cashflow_bank_balances API error: ' . $e->getMessage());
    api_error('Unable to load bank balances.', 500);
}
