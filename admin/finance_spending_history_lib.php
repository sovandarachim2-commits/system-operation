<?php
declare(strict_types=1);

function finance_spending_history_ensure(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_spending_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spending_id INT NOT NULL,
        spending_code VARCHAR(100) NULL,
        action VARCHAR(40) NOT NULL,
        details TEXT NULL,
        user_id INT NULL,
        user_name VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fsh_spending (spending_id),
        INDEX idx_fsh_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function finance_spending_history_user_name(?array $user): string
{
    if (!$user) {
        return 'System';
    }
    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $username = trim((string)($user['username'] ?? ''));
    return $username !== '' ? $username : 'System';
}

function finance_spending_history_log(
    PDO $pdo,
    int $spendingId,
    string $action,
    string $details = '',
    ?array $user = null,
    string $spendingCode = ''
): void {
    if ($spendingId <= 0) {
        return;
    }
    try {
        finance_spending_history_ensure($pdo);
        if (!$user && function_exists('current_user')) {
            $user = current_user();
        }
        $userId = ($user && isset($user['id'])) ? (int)$user['id'] : null;
        $userName = finance_spending_history_user_name($user);
        $stmt = $pdo->prepare("
            INSERT INTO finance_spending_history
                (spending_id, spending_code, action, details, user_id, user_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $spendingId,
            $spendingCode !== '' ? $spendingCode : null,
            trim($action) !== '' ? trim($action) : 'Updated',
            $details !== '' ? $details : null,
            $userId,
            $userName,
        ]);
    } catch (Throwable $e) {
        error_log('finance_spending_history_log failed: ' . $e->getMessage());
    }
}

function finance_spending_history_list(PDO $pdo, int $spendingId): array
{
    if ($spendingId <= 0) {
        return [];
    }
    try {
        finance_spending_history_ensure($pdo);
        $stmt = $pdo->prepare("
            SELECT
                h.id,
                h.spending_id,
                h.spending_code,
                h.action,
                h.details,
                h.user_id,
                COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), NULLIF(h.user_name, ''), 'System') AS user_name,
                h.created_at
            FROM finance_spending_history h
            LEFT JOIN users u ON u.id = h.user_id
            WHERE h.spending_id = ?
            ORDER BY h.created_at ASC, h.id ASC
        ");
        $stmt->execute([$spendingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'spending_id' => (int)($row['spending_id'] ?? 0),
                'spending_code' => (string)($row['spending_code'] ?? ''),
                'action' => (string)($row['action'] ?? ''),
                'details' => (string)($row['details'] ?? ''),
                'user' => (string)($row['user_name'] ?? 'System'),
                'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
                'date' => (string)($row['created_at'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('finance_spending_history_list failed: ' . $e->getMessage());
        return [];
    }
}

function finance_spending_history_bootstrap(PDO $pdo, array $spending): array
{
    $spendingId = (int)($spending['id'] ?? 0);
    if ($spendingId <= 0) {
        return [];
    }
    finance_spending_history_ensure($pdo);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM finance_spending_history WHERE spending_id = ?');
    $countStmt->execute([$spendingId]);
    $count = (int)$countStmt->fetchColumn();
    if ($count > 0) {
        return finance_spending_history_list($pdo, $spendingId);
    }

    $code = (string)($spending['spending_code'] ?? '');
    $createdAt = (string)($spending['created_at'] ?? date('Y-m-d H:i:s'));
    $createdName = trim((string)($spending['created_by_name'] ?? $spending['created_by'] ?? 'System'));
    $createdId = is_numeric($spending['created_by'] ?? null) ? (int)$spending['created_by'] : null;

    $pdo->prepare("
        INSERT INTO finance_spending_history
            (spending_id, spending_code, action, details, user_id, user_name, created_at)
        VALUES (?, ?, 'Created', 'Expense record created', ?, ?, ?)
    ")->execute([$spendingId, $code !== '' ? $code : null, $createdId, $createdName !== '' ? $createdName : 'System', $createdAt]);

    $updatedAt = (string)($spending['updated_at'] ?? '');
    if ($updatedAt !== '' && $updatedAt !== $createdAt) {
        $updatedName = trim((string)($spending['updated_by_name'] ?? ''));
        $updatedId = isset($spending['updated_by']) && is_numeric($spending['updated_by']) ? (int)$spending['updated_by'] : null;
        if ($updatedName === '') {
            $updatedName = $createdName !== '' ? $createdName : 'System';
        }
        $pdo->prepare("
            INSERT INTO finance_spending_history
                (spending_id, spending_code, action, details, user_id, user_name, created_at)
            VALUES (?, ?, 'Updated', 'Expense record updated', ?, ?, ?)
        ")->execute([
            $spendingId,
            $code !== '' ? $code : null,
            $updatedId,
            $updatedName,
            $updatedAt,
        ]);
    }

    return finance_spending_history_list($pdo, $spendingId);
}
