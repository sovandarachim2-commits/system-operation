<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'cashflow.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Quick month filter: ?month=YYYY-MM sets from_date and to_date to that month
$monthParam = trim($_GET['month'] ?? '');
if ($monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $from_date = $monthParam . '-01';
    $to_date   = date('Y-m-t', strtotime($from_date)); // last day of month
} else {
    $from_date = $_GET['from_date'] ?? date('Y-m-01');
    $to_date   = $_GET['to_date'] ?? date('Y-m-d');
}

// Total money IN (from orders - paid, non-cancelled, non-returned)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) 
    FROM orders 
    WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0 
    AND (COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?)
");
$stmt->execute([$from_date, $to_date]);
$totalMoney = (float) $stmt->fetchColumn();

// Total spending OUT (cashflow_spending)
$totalSpending = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_spending WHERE spending_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalSpending = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Finance spending totals (also used in closing balance logic).
$totalFinanceSpending = 0;
$openingFinanceSpending = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalFinanceSpending = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_spending WHERE DATE(spending_date) < ?");
    $stmt->execute([$from_date]);
    $openingFinanceSpending = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}

$totalSpendingForBalance = $totalSpending + $totalFinanceSpending;

// Total Top Up IN (cashflow_topups)
$totalTopup = 0;
$openingTopup = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_topups WHERE topup_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalTopup = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_topups WHERE topup_date < ?");
    $stmt->execute([$from_date]);
    $openingTopup = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Finance top-ups are also part of balance.
$totalFinanceTopup = 0;
$openingFinanceTopup = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE DATE(topup_date) BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $totalFinanceTopup = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_topups WHERE DATE(topup_date) < ?");
    $stmt->execute([$from_date]);
    $openingFinanceTopup = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}

$totalTopup += $totalFinanceTopup;
$openingTopup += $openingFinanceTopup;

// Opening Balance = cumulative (Money + Topup - Spending) BEFORE the period (carried from previous)
$openingMoney = 0;
$openingSpending = 0;
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0 AND (COALESCE(payment_date, DATE(created_at)) < ?)");
$stmt->execute([$from_date]);
$openingMoney = (float) $stmt->fetchColumn();
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cashflow_spending WHERE spending_date < ?");
    $stmt->execute([$from_date]);
    $openingSpending = (float) $stmt->fetchColumn();
} catch (PDOException $e) {}
$openingSpending += $openingFinanceSpending;
$openingBalance = $openingMoney + $openingTopup - $openingSpending;

// Period balance and Closing Balance (carries to next month)
$periodBalance = $totalMoney + $totalTopup - $totalSpendingForBalance;
$closingBalance = $openingBalance + $periodBalance;

// Order count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0 AND (COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?)");
$stmt->execute([$from_date, $to_date]);
$orderCount = (int) $stmt->fetchColumn();

// Spending count
$spendingCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cashflow_spending WHERE spending_date BETWEEN ? AND ?");
    $stmt->execute([$from_date, $to_date]);
    $spendingCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {}

// Get note options (payment methods)
$noteOptions = $pdo->query("SELECT id, option_text, sort_order, is_finance_default FROM note_options WHERE is_active = 1 AND is_admin_active = 1 ORDER BY sort_order, option_text")->fetchAll(PDO::FETCH_ASSOC);
$defaultFinanceBank = '';
foreach ($noteOptions as $opt) {
    if (!empty($opt['is_finance_default'])) {
        $defaultFinanceBank = trim((string)$opt['option_text']);
        break;
    }
}
if ($defaultFinanceBank === '' && !empty($noteOptions)) {
    $defaultFinanceBank = trim((string)$noteOptions[0]['option_text']);
}

// Money IN by payment method (from orders)
$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') AS payment_method,
           COUNT(*) AS order_count,
           COALESCE(SUM(total_amount), 0) AS total_amount
    FROM orders
    WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0
    AND (COALESCE(payment_date, DATE(created_at)) BETWEEN ? AND ?)
    GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)')
");
$stmt->execute([$from_date, $to_date]);
$moneyByMethod = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $moneyByMethod[$r['payment_method']] = ['orders' => (int)$r['order_count'], 'amount' => (float)$r['total_amount']];
}

// Spending OUT by payment method (from cashflow_spending)
$spendingByMethod = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') AS payment_method,
               COUNT(*) AS spend_count,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM cashflow_spending
        WHERE spending_date BETWEEN ? AND ?
        GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)')
    ");
    $stmt->execute([$from_date, $to_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $spendingByMethod[$r['payment_method']] = ['count' => (int)$r['spend_count'], 'amount' => (float)$r['total_amount']];
    }
} catch (PDOException $e) {}


// Bank transfers: transfer_in (to_bank) and transfer_out (from_bank) per payment method
$transferInByBank = [];
$transferOutByBank = [];
$transferRows = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM bank_transfers WHERE transfer_date BETWEEN ? AND ? ORDER BY transfer_date DESC, created_at DESC");
    $stmt->execute([$from_date, $to_date]);
    $transferRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($transferRows as $r) {
        $amt = (float)$r['amount'];
        $from = trim($r['from_bank']);
        $to = trim($r['to_bank']);
        if ($from) $transferOutByBank[$from] = ($transferOutByBank[$from] ?? 0) + $amt;
        if ($to) $transferInByBank[$to] = ($transferInByBank[$to] ?? 0) + $amt;
    }
} catch (PDOException $e) {}

$totalTransferIn = array_sum($transferInByBank);
$totalTransferOut = array_sum($transferOutByBank);

// Top up by bank (cashflow_topups)
$topupByMethod = [];
$openingTopupByMethod = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') AS payment_method,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM cashflow_topups WHERE topup_date BETWEEN ? AND ?
        GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)')
    ");
    $stmt->execute([$from_date, $to_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $topupByMethod[$r['payment_method']] = (float)$r['total_amount'];
    }
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') AS payment_method,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM cashflow_topups WHERE topup_date < ?
        GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)')
    ");
    $stmt->execute([$from_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $openingTopupByMethod[$r['payment_method']] = (float)$r['total_amount'];
    }
} catch (PDOException $e) {}
// Add finance topups grouped by source as payment_method.
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(source), ''), '(No method)') AS payment_method,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM finance_topups
        WHERE DATE(topup_date) BETWEEN ? AND ?
        GROUP BY COALESCE(NULLIF(TRIM(source), ''), '(No method)')
    ");
    $stmt->execute([$from_date, $to_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = $r['payment_method'];
        $topupByMethod[$m] = ($topupByMethod[$m] ?? 0) + (float)$r['total_amount'];
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(source), ''), '(No method)') AS payment_method,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM finance_topups
        WHERE DATE(topup_date) < ?
        GROUP BY COALESCE(NULLIF(TRIM(source), ''), '(No method)')
    ");
    $stmt->execute([$from_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = $r['payment_method'];
        $openingTopupByMethod[$m] = ($openingTopupByMethod[$m] ?? 0) + (float)$r['total_amount'];
    }
} catch (PDOException $e) {}

// Opening per bank (before from_date): money_in + topup - spending_out + transfer_in - transfer_out
$openingMoneyByMethod = [];
$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') AS payment_method,
           COALESCE(SUM(total_amount), 0) AS total_amount
    FROM orders
    WHERE status = 'paid' AND is_cancelled = 0 AND is_returned = 0 AND (COALESCE(payment_date, DATE(created_at)) < ?)
    GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)')
");
$stmt->execute([$from_date]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $openingMoneyByMethod[$r['payment_method']] = (float)$r['total_amount'];
}
$openingSpendingByMethod = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)') AS payment_method,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM cashflow_spending WHERE spending_date < ?
        GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), '(No method)')
    ");
    $stmt->execute([$from_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $openingSpendingByMethod[$r['payment_method']] = (float)$r['total_amount'];
    }
} catch (PDOException $e) {}
// Finance spending opening is assigned to default finance bank for opening consistency.
if ($defaultFinanceBank !== '') {
    $openingSpendingByMethod[$defaultFinanceBank] = ($openingSpendingByMethod[$defaultFinanceBank] ?? 0) + $openingFinanceSpending;
}
$openingTransferInByBank = [];
$openingTransferOutByBank = [];
try {
    $stmt = $pdo->prepare("SELECT from_bank, to_bank, amount FROM bank_transfers WHERE transfer_date < ?");
    $stmt->execute([$from_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['amount'];
        $from = trim($r['from_bank'] ?? '');
        $to = trim($r['to_bank'] ?? '');
        if ($from) $openingTransferOutByBank[$from] = ($openingTransferOutByBank[$from] ?? 0) + $amt;
        if ($to) $openingTransferInByBank[$to] = ($openingTransferInByBank[$to] ?? 0) + $amt;
    }
} catch (PDOException $e) {}

// Helper: opening per bank = money_in + topup - spending_out + transfer_in - transfer_out (all before period)
$openingPerBank = function($bank) use ($openingMoneyByMethod, $openingSpendingByMethod, $openingTransferInByBank, $openingTransferOutByBank, $openingTopupByMethod) {
    $mi = $openingMoneyByMethod[$bank] ?? 0;
    $topup = $openingTopupByMethod[$bank] ?? 0;
    $so = $openingSpendingByMethod[$bank] ?? 0;
    $ti = $openingTransferInByBank[$bank] ?? 0;
    $to = $openingTransferOutByBank[$bank] ?? 0;
    return $mi + $topup - $so + $ti - $to;
};

// Build display rows: each note option + any extras from actual data + transfer banks
$paymentRows = [];
foreach ($noteOptions as $opt) {
    $text = trim($opt['option_text'] ?? '');
    if ($text === '') continue;
    $paymentRows[$text] = [
        'payment_method' => $text,
        'opening' => $openingPerBank($text),
        'money_in'  => $moneyByMethod[$text]['amount'] ?? 0,
        'topup' => $topupByMethod[$text] ?? 0,
        'order_count' => $moneyByMethod[$text]['orders'] ?? 0,
        'spending_out' => $spendingByMethod[$text]['amount'] ?? 0,
        'finance_spending_total' => 0,
        'spend_count'  => $spendingByMethod[$text]['count'] ?? 0,
        'transfer_in'  => $transferInByBank[$text] ?? 0,
        'transfer_out' => $transferOutByBank[$text] ?? 0,
    ];
}
// Add any methods from orders/spending/topups not in note_options
foreach (array_keys($moneyByMethod + $spendingByMethod + $topupByMethod) as $m) {
    if ($m === '(No method)' || isset($paymentRows[$m])) continue;
    $paymentRows[$m] = [
        'payment_method' => $m,
        'opening' => $openingPerBank($m),
        'money_in'  => $moneyByMethod[$m]['amount'] ?? 0,
        'topup' => $topupByMethod[$m] ?? 0,
        'order_count' => $moneyByMethod[$m]['orders'] ?? 0,
        'spending_out' => $spendingByMethod[$m]['amount'] ?? 0,
        'finance_spending_total' => 0,
        'spend_count'  => $spendingByMethod[$m]['count'] ?? 0,
        'transfer_in'  => $transferInByBank[$m] ?? 0,
        'transfer_out' => $transferOutByBank[$m] ?? 0,
    ];
}
// Add banks that only appear in transfers or topups
foreach (array_keys($transferInByBank + $transferOutByBank + $topupByMethod) as $m) {
    if (isset($paymentRows[$m])) continue;
    $paymentRows[$m] = [
        'payment_method' => $m,
        'opening' => $openingPerBank($m),
        'money_in'  => $moneyByMethod[$m]['amount'] ?? 0,
        'topup' => $topupByMethod[$m] ?? 0,
        'order_count' => $moneyByMethod[$m]['orders'] ?? 0,
        'spending_out' => $spendingByMethod[$m]['amount'] ?? 0,
        'finance_spending_total' => 0,
        'spend_count'  => $spendingByMethod[$m]['count'] ?? 0,
        'transfer_in'  => $transferInByBank[$m] ?? 0,
        'transfer_out' => $transferOutByBank[$m] ?? 0,
    ];
}
if (isset($moneyByMethod['(No method)']) || isset($spendingByMethod['(No method)'])) {
    $paymentRows['(No method)'] = [
        'payment_method' => '(No method)',
        'opening' => $openingPerBank('(No method)'),
        'money_in'  => $moneyByMethod['(No method)']['amount'] ?? 0,
        'topup' => $topupByMethod['(No method)'] ?? 0,
        'order_count' => $moneyByMethod['(No method)']['orders'] ?? 0,
        'spending_out' => $spendingByMethod['(No method)']['amount'] ?? 0,
        'finance_spending_total' => 0,
        'spend_count'  => $spendingByMethod['(No method)']['count'] ?? 0,
        'transfer_in'  => 0,
        'transfer_out' => 0,
    ];
}

// Put finance spending total on default finance bank row for visibility.
if ($defaultFinanceBank !== '' && isset($paymentRows[$defaultFinanceBank])) {
    $paymentRows[$defaultFinanceBank]['finance_spending_total'] = $totalFinanceSpending;
}

// Export to Excel (styled HTML, opens in Excel with colors)
if (isset($_GET['export']) && ($_GET['export'] === 'excel' || $_GET['export'] === 'csv')) {
    $filename = 'cashflow_summary_' . $from_date . '_to_' . $to_date . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"><style>
      body{font-family:Arial,sans-serif;font-size:11px;margin:8px}
      table{border-collapse:collapse;width:100%;border:1px solid #999}
      th,td{border:1px solid #999;padding:6px 8px;vertical-align:middle}
      .thead{background:#0d9488!important;color:#fff!important;font-weight:bold;text-align:center}
      .tfoot{background:#e5e7eb!important;font-weight:bold}
      .num{text-align:right}
    </style></head><body>';
    echo '<table><tr><td colspan="2" style="background:#0d9488;color:#fff;font-size:14px;font-weight:bold;padding:10px">Cash Flow Summary</td></tr>';
    echo '<tr><td style="background:#f3f4f6;width:180px">Period</td><td>' . $h(date('M d, Y', strtotime($from_date)) . ' - ' . date('M d, Y', strtotime($to_date))) . '</td></tr>';
    echo '<tr><td style="background:#f3f4f6">Generated</td><td>' . $h(date('M d, Y H:i')) . '</td></tr></table><br/>';

    echo '<table><tr><td colspan="2" style="background:#6366f1;color:#fff;font-weight:bold;padding:8px">Summary</td></tr>';
    echo '<tr><td style="background:#e0e7ff;width:180px">Opening Balance</td><td class="num" style="font-weight:bold;color:#4f46e5">$' . number_format($openingBalance, 2) . '</td></tr>';
    echo '<tr><td style="background:#d1fae5;width:180px">Money In (Orders)</td><td class="num" style="font-weight:bold;color:#059669">$' . number_format($totalMoney, 2) . '</td></tr>';
    echo '<tr><td style="background:#e0f2fe;width:180px">Top Up (Period)</td><td class="num" style="font-weight:bold;color:#0284c7">$' . number_format($totalTopup, 2) . '</td></tr>';
    echo '<tr><td style="background:#fee2e2;width:180px">Spending (Period)</td><td class="num" style="font-weight:bold;color:#dc2626">$' . number_format($totalSpending, 2) . '</td></tr>';
    echo '<tr><td style="background:#ccfbf1;width:180px">Closing Balance</td><td class="num" style="font-weight:bold;color:#0d9488">$' . number_format($closingBalance, 2) . '</td></tr></table><br/>';

    echo '<table><tr><td colspan="12" style="background:#0d9488;color:#fff;font-weight:bold;padding:8px">By Payment Method</td></tr>';
    echo '<tr class="thead"><th>Payment Method</th><th class="num">Opening</th><th class="num">Money In</th><th class="num">Top Up</th><th class="num">Orders</th><th class="num">Transfer In</th><th class="num">Transfer Out</th><th class="num">Spending Out</th><th class="num">Finance Spending Total</th><th class="num">Spending</th><th class="num">Net</th><th class="num">Closing</th></tr>';
    foreach ($paymentRows as $row) {
        $opening = (float)($row['opening'] ?? 0);
        $topupAmt = (float)($row['topup'] ?? 0);
        $net = ($row['money_in'] ?? 0) + $topupAmt + ($row['transfer_in'] ?? 0) - ($row['transfer_out'] ?? 0) - ($row['spending_out'] ?? 0) - ($row['finance_spending_total'] ?? 0);
        $closing = $opening + $net;
        echo '<tr>';
        echo '<td><strong>' . $h($row['payment_method']) . '</strong></td>';
        echo '<td class="num">$' . number_format($opening, 2) . '</td>';
        echo '<td class="num" style="color:#059669;font-weight:600">$' . number_format($row['money_in'] ?? 0, 2) . '</td>';
        echo '<td class="num" style="color:#0284c7;font-weight:600">$' . number_format($topupAmt, 2) . '</td>';
        echo '<td class="num">' . (int)($row['order_count'] ?? 0) . '</td>';
        echo '<td class="num">$' . number_format($row['transfer_in'] ?? 0, 2) . '</td>';
        echo '<td class="num" style="color:#dc2626;font-weight:600">$' . number_format($row['transfer_out'] ?? 0, 2) . '</td>';
        echo '<td class="num" style="color:#dc2626;font-weight:600">$' . number_format($row['spending_out'] ?? 0, 2) . '</td>';
        echo '<td class="num" style="color:#b91c1c;font-weight:600">$' . number_format($row['finance_spending_total'] ?? 0, 2) . '</td>';
        echo '<td class="num">' . (int)($row['spend_count'] ?? 0) . '</td>';
        echo '<td class="num"><strong>$' . number_format($net, 2) . '</strong></td>';
        echo '<td class="num" style="color:#0d9488;font-weight:bold">$' . number_format($closing, 2) . '</td>';
        echo '</tr>';
    }
    echo '<tr class="tfoot"><td>Total</td><td class="num">$' . number_format($openingBalance, 2) . '</td><td class="num" style="color:#059669;font-weight:600">$' . number_format($totalMoney, 2) . '</td><td class="num" style="color:#0284c7;font-weight:600">$' . number_format($totalTopup, 2) . '</td><td class="num">' . $orderCount . '</td><td class="num">$' . number_format($totalTransferIn ?? 0, 2) . '</td><td class="num" style="color:#dc2626;font-weight:600">$' . number_format($totalTransferOut ?? 0, 2) . '</td><td class="num" style="color:#dc2626;font-weight:600">$' . number_format($totalSpending, 2) . '</td><td class="num" style="color:#b91c1c;font-weight:600">$' . number_format($totalFinanceSpending, 2) . '</td><td class="num">' . $spendingCount . '</td><td class="num">$' . number_format($periodBalance, 2) . '</td><td class="num" style="color:#0d9488;font-weight:bold">$' . number_format($closingBalance, 2) . '</td></tr>';
    echo '</table><br/>';

    echo '<table><tr><td colspan="5" style="background:#0d9488;color:#fff;font-weight:bold;padding:8px">Bank Transfers</td></tr>';
    echo '<tr class="thead"><th>Date</th><th class="num">Amount</th><th>From Bank</th><th>To Bank</th><th>Note</th></tr>';
    if (empty($transferRows)) {
        echo '<tr><td colspan="5" style="text-align:center;color:#6b7280">No bank transfers in this period</td></tr>';
    } else {
        foreach ($transferRows as $r) {
            echo '<tr><td>' . $h($r['transfer_date']) . '</td><td class="num"><strong>$' . number_format((float)$r['amount'], 2) . '</strong></td><td>' . $h($r['from_bank']) . '</td><td>' . $h($r['to_bank']) . '</td><td>' . $h($r['note'] ?? '') . '</td></tr>';
        }
    }
    echo '</table></body></html>';
    exit;
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
.cf-summary-banner { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25); }
.cf-summary-card { border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.cf-summary-card.money { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: white; }
.cf-summary-card.spending { background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); color: white; }
.cf-summary-card.balance { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color: white; }
.cf-summary-card .label { font-size: 0.9rem; opacity: 0.95; }
.cf-summary-card .amount { font-size: 2rem; font-weight: 700; }
.cf-summary-card .sub { font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem; }
@media (max-width: 768px) { .cf-summary-card .amount { font-size: 1.6rem; } }
</style>
<div class="container-fluid py-4">
    <div class="cf-summary-banner d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Cash Flow Summary</h1>
            <p class="mb-0 mt-1 opacity-90">Total money, total spending, and balance</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="cashflow_summary.php?export=excel&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?><?= !empty($monthParam) ? '&month=' . urlencode($monthParam) : '' ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
            </a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow.php" class="btn btn-light btn-sm">By Payment Method</a>
            <?php if (function_exists('has_permission') && has_permission('cashflow_topup.view')): ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_topup_add.php" class="btn btn-light btn-sm"><i class="bi bi-plus-circle me-1"></i>Top Up</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/bank_transfer_add.php" class="btn btn-light btn-sm">Transfer to Bank</a>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/cashflow_add_spending.php" class="btn btn-light btn-sm">Add Spending</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end" id="cfFilterForm">
                <div class="col-md-2">
                    <?php 
                    $isFullMonth = (preg_match('/^\d{4}-\d{2}-01$/', $from_date) && $to_date === date('Y-m-t', strtotime($from_date)));
                    $monthValue = $isFullMonth ? substr($from_date, 0, 7) : '';
                    ?>
                    <label class="form-label"><i class="bi bi-calendar-month me-1"></i>Quick: Month</label>
                    <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($monthValue) ?>" title="Select month for quick filter (or use From/To for custom range)">
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="cashflow_summary.php" class="btn btn-outline-secondary ms-2">Clear</a>
                </div>
            </form>
            <p class="text-muted small mb-0 mt-2">Use <strong>Month</strong> for a full month (auto-applies), or pick custom <strong>From/To</strong> dates.</p>
            <script>
            document.querySelector('[name=month]')?.addEventListener('change', function() {
                if (this.value) this.form.submit();
            });
            </script>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-6 col-md-3">
            <div class="cf-summary-card" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white;">
                <div class="label"><i class="bi bi-box-arrow-in-right me-1"></i>Opening Balance</div>
                <div class="amount">$<?= number_format($openingBalance, 2) ?></div>
                <div class="sub">Before <?= date('M j', strtotime($from_date)) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="cf-summary-card money">
                <div class="label"><i class="bi bi-arrow-down-circle me-1"></i>Money In (Period)</div>
                <div class="amount">$<?= number_format($totalMoney, 2) ?></div>
                <div class="sub"><?= $orderCount ?> paid orders</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="cf-summary-card" style="background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 100%); color: white;">
                <div class="label"><i class="bi bi-wallet2 me-1"></i>Top Up (Period)</div>
                <div class="amount">$<?= number_format($totalTopup, 2) ?></div>
                <div class="sub">Capital injection</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="cf-summary-card spending">
                <div class="label"><i class="bi bi-arrow-up-circle me-1"></i>Spending (Period)</div>
                <div class="amount">$<?= number_format($totalSpending, 2) ?></div>
                <div class="sub"><?= $spendingCount ?> records</div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="cf-summary-card balance">
                <div class="label"><i class="bi bi-box-arrow-up-right me-1"></i>Closing Balance</div>
                <div class="amount">$<?= number_format($closingBalance, 2) ?></div>
                <div class="sub">Orders + Top Up − Spending</div>
            </div>
        </div>
    </div>

    <!-- Opening Balance by Bank -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card shadow-sm border-primary">
                <div class="card-header py-3 bg-primary bg-opacity-10">
                    <h5 class="mb-0"><i class="bi bi-bank2 me-2"></i>Opening Balance by Bank</h5>
                    <small class="text-muted">Balance in each bank before <?= date('M j, Y', strtotime($from_date)) ?></small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Bank / Payment Method</th><th class="text-end">Opening Balance</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentRows as $row): 
                                    $op = (float)($row['opening'] ?? 0);
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['payment_method']) ?></strong></td>
                                        <td class="text-end fw-semibold">$<?= number_format($op, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($paymentRows)): ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">No payment methods configured. Add banks in Note Options.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold"><td>Total</td><td class="text-end">$<?= number_format($openingBalance, 2) ?></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-credit-card-2-front me-2"></i>By Payment Method</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Payment Method</th>
                                    <th class="text-end">Opening</th>
                                    <th class="text-end">Money In (Orders)</th>
                                    <th class="text-end">Top Up</th>
                                    <th class="text-end">Orders</th>
                                    <th class="text-end">Transfer In</th>
                                    <th class="text-end">Transfer Out</th>
                                    <th class="text-end">Spending Out</th>
                                    <th class="text-end">Finance Spending Total</th>
                                    <th class="text-end">Spending</th>
                                    <th class="text-end">Net</th>
                                    <th class="text-end">Closing</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentRows as $row): 
                                    $opening = (float)($row['opening'] ?? 0);
                                    $topupAmt = (float)($row['topup'] ?? 0);
                                    $net = ($row['money_in'] ?? 0) + $topupAmt + ($row['transfer_in'] ?? 0) - ($row['transfer_out'] ?? 0) - ($row['spending_out'] ?? 0) - ($row['finance_spending_total'] ?? 0);
                                    $closing = $opening + $net;
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['payment_method']) ?></strong></td>
                                        <td class="text-end">$<?= number_format($opening, 2) ?></td>
                                        <td class="text-end text-success">$<?= number_format($row['money_in'], 2) ?></td>
                                        <td class="text-end text-info">$<?= number_format($topupAmt, 2) ?></td>
                                        <td class="text-end"><?= $row['order_count'] ?></td>
                                        <td class="text-end">$<?= number_format($row['transfer_in'] ?? 0, 2) ?></td>
                                        <td class="text-end">$<?= number_format($row['transfer_out'] ?? 0, 2) ?></td>
                                        <td class="text-end text-danger">$<?= number_format($row['spending_out'], 2) ?></td>
                                        <td class="text-end text-danger">$<?= number_format($row['finance_spending_total'] ?? 0, 2) ?></td>
                                        <td class="text-end"><?= $row['spend_count'] ?></td>
                                        <td class="text-end fw-semibold">$<?= number_format($net, 2) ?></td>
                                        <td class="text-end fw-bold">$<?= number_format($closing, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td>Total</td>
                                    <td class="text-end">$<?= number_format($openingBalance, 2) ?></td>
                                    <td class="text-end text-success">$<?= number_format($totalMoney, 2) ?></td>
                                    <td class="text-end text-info">$<?= number_format($totalTopup, 2) ?></td>
                                    <td class="text-end"><?= $orderCount ?></td>
                                    <td class="text-end">$<?= number_format($totalTransferIn ?? 0, 2) ?></td>
                                    <td class="text-end">$<?= number_format($totalTransferOut ?? 0, 2) ?></td>
                                    <td class="text-end text-danger">$<?= number_format($totalSpending, 2) ?></td>
                                    <td class="text-end text-danger">$<?= number_format($totalFinanceSpending, 2) ?></td>
                                    <td class="text-end"><?= $spendingCount ?></td>
                                    <td class="text-end">$<?= number_format($periodBalance, 2) ?></td>
                                    <td class="text-end">$<?= number_format($closingBalance, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Transfers (display only - does not affect balance) -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-bank me-2"></i>Bank Transfers</h5>
                    <span class="badge bg-secondary">Informational only</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($transferRows)): ?>
                        <div class="p-4 text-center text-muted">No bank transfers in this period.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Date</th><th>Amount</th><th>From Bank</th><th>To Bank</th><th>Note</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transferRows as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['transfer_date']) ?></td>
                                            <td class="fw-semibold">$<?= number_format((float)$r['amount'], 2) ?></td>
                                            <td><?= htmlspecialchars($r['from_bank']) ?></td>
                                            <td><?= htmlspecialchars($r['to_bank']) ?></td>
                                            <td class="text-truncate small" style="max-width: 150px;"><?= htmlspecialchars($r['note'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>Total transferred</td>
                                        <td>$<?= number_format($totalTransferIn ?? 0, 2) ?></td>
                                        <td colspan="3" class="small text-muted">Transfers move money between accounts; they do not affect the balance above.</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card bg-light border-0">
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><strong>Date range:</strong> <?= htmlspecialchars($from_date) ?> to <?= htmlspecialchars($to_date) ?></p>
                    <p class="mb-0 small">Opening = cumulative (Money In − Spending + Transfer In − Transfer Out) before period, per bank. Net = Money In + Transfer In − Transfer Out − Spending Out (period). Closing = Opening + Net per bank.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
