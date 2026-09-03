<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_return_report.view');
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$delivery_filter = $_GET['delivery_filter'] ?? '';

// Quick range presets (compute highlights and provide shortcuts)
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-d', strtotime('-29 days'));

$isToday = ($start_date === $today && $end_date === $today);
$isYesterday = ($start_date === $yesterday && $end_date === $yesterday);
$isWeek = ($start_date === $weekStart && $end_date === $today);
$isMonth = ($start_date === $monthStart && $end_date === $today);

// Build delivery subquery for filtering
$deliveryJoin = "";
$deliveryWhere = "";
$params = [':start' => $start_date, ':end' => $end_date];

if ($delivery_filter !== '') {
    // Join latest/non-empty delivery_by per inv
    $deliveryJoin = "LEFT JOIN (
        SELECT inv, MAX(delivery_by) AS delivery_by
        FROM out_items
        WHERE delivery_by IS NOT NULL AND delivery_by != ''
        GROUP BY inv
    ) outi ON outi.inv = o.order_code";
    $deliveryWhere = " AND COALESCE(outi.delivery_by, '') = :delivery_by";
    $params[':delivery_by'] = $delivery_filter;
}

// Product return list (order-level) for detail/debug tables below.
$sql = "
SELECT *
FROM (
    SELECT
        CONCAT('scanner_', ri.id) AS row_id,
        ri.id,
        ri.inv,
        o.customer_name,
        ri.delivery_by,
        ri.reason,
        ri.username,
        ri.inv_photo,
        ri.full_photo,
        ri.date_time,
        o.phone,
        o.total_amount,
        o.status as order_status,
        'scanner' AS return_source,
        (
            SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR '\n')
            FROM orders o2
            JOIN order_items oi ON oi.order_id = o2.id
            JOIN products p ON p.id = oi.product_id
            WHERE o2.order_code = ri.inv
        ) AS product_items,
        seller.name AS seller_name
    FROM return_items ri
    LEFT JOIN orders o ON ri.inv = o.order_code
    LEFT JOIN users seller ON seller.id = o.seller_id

    UNION ALL

    SELECT
        CONCAT('order_', o.id) AS row_id,
        NULL AS id,
        o.order_code AS inv,
        o.customer_name,
        COALESCE(outi.delivery_by, '') AS delivery_by,
        o.return_note AS reason,
        COALESCE(updater.name, '') AS username,
        '' AS inv_photo,
        '' AS full_photo,
        o.updated_at AS date_time,
        o.phone,
        o.total_amount,
        o.status as order_status,
        'order_management' AS return_source,
        (
            SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR '\n')
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = o.id
        ) AS product_items,
        seller.name AS seller_name
    FROM orders o
    LEFT JOIN (
        SELECT inv, MAX(delivery_by) AS delivery_by
        FROM out_items
        WHERE delivery_by IS NOT NULL
          AND delivery_by != ''
        GROUP BY inv
    ) outi ON outi.inv = o.order_code
    LEFT JOIN users updater ON updater.id = o.updated_by
    LEFT JOIN users seller ON seller.id = o.seller_id
    WHERE o.is_returned = 1
      AND NOT EXISTS (
          SELECT 1
          FROM return_items ri2
          WHERE ri2.inv = o.order_code
      )
) returns_combined
WHERE DATE(date_time) BETWEEN :start AND :end
";

$params = [':start' => $start_date, ':end' => $end_date];
if (!empty($delivery_filter)) {
    $sql .= " AND delivery_by = :delivery";
    $params[':delivery'] = $delivery_filter;
    $params[':delivery_by'] = $delivery_filter; // used by daily/detail $deliveryWhere
}

$sql .= " ORDER BY date_time DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawReturns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aggregate by component product (sets expanded) — same rules as Sold Stock Online Qty Return
$roSub = "(
  SELECT o.id AS order_id, ri.date_time AS return_at, o.order_code
  FROM return_items ri
  JOIN orders o ON o.order_code = ri.inv
  UNION ALL
  SELECT o.id AS order_id, o.updated_at AS return_at, o.order_code
  FROM orders o
  WHERE COALESCE(o.is_returned, 0) = 1
    AND NOT EXISTS (SELECT 1 FROM return_items ri2 WHERE ri2.inv = o.order_code)
)";

$productAggSql = "
SELECT
  COALESCE(cp.id, p.id) AS product_id,
  COALESCE(cp.name, p.name) AS product_name,
  MAX(COALESCE(cp.product_type, p.product_type, 'normal')) AS product_type,
  SUM(oi.quantity * COALESCE(psi.quantity, 1)) AS total_qty,
  COUNT(DISTINCT ro.order_id) AS returns_count,
  MAX(DATE(ro.return_at)) AS last_return_date
FROM {$roSub} ro
JOIN order_items oi ON oi.order_id = ro.order_id
JOIN products p ON p.id = oi.product_id
LEFT JOIN product_sets ps
  ON ps.set_name = p.name
 AND COALESCE(p.product_type, 'normal') = 'set'
LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
LEFT JOIN products cp ON cp.id = psi.product_id
JOIN orders o ON o.id = ro.order_id
WHERE DATE(ro.return_at) BETWEEN :start AND :end
";
$productAggParams = [':start' => $start_date, ':end' => $end_date];
if (!empty($delivery_filter)) {
    $productAggSql .= " AND COALESCE((
        SELECT MAX(outi.delivery_by)
        FROM out_items outi
        WHERE outi.inv = o.order_code
          AND outi.delivery_by IS NOT NULL
          AND outi.delivery_by != ''
    ), '') = :delivery";
    $productAggParams[':delivery'] = $delivery_filter;
}
$productAggSql .= "
GROUP BY COALESCE(cp.id, p.id), COALESCE(cp.name, p.name)
ORDER BY total_qty DESC, product_name ASC";

$productAggStmt = $pdo->prepare($productAggSql);
$productAggStmt->execute($productAggParams);
$rows = array_map(static function (array $row): array {
    return [
        'product_name' => (string)($row['product_name'] ?? ''),
        'product_type' => (string)($row['product_type'] ?? 'normal'),
        'total_qty' => (float)($row['total_qty'] ?? 0),
        'returns_count' => (int)($row['returns_count'] ?? 0),
        'last_return_date' => (string)($row['last_return_date'] ?? ''),
    ];
}, $productAggStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

// Totals for product aggregation table
$grandQty = 0.0;
$grandReturns = 0;
foreach ($rows as $r) {
    $grandQty += (float)($r['total_qty'] ?? 0);
    $grandReturns += (int)($r['returns_count'] ?? 0);
}

// Daily summary grouped by date of return
$dailySql = "
SELECT
  DATE(ro.date_time) AS return_date,
  SUM(oi.quantity) AS total_qty,
  COUNT(DISTINCT ro.order_id) AS returns_count
FROM (
  -- Returns from scanner entries that matched an order
  SELECT o.id AS order_id, ri.date_time, o.order_code
  FROM return_items ri
  JOIN orders o ON o.order_code = ri.inv
  UNION ALL
  -- Returns marked in order management (exclude those already in return_items)
  SELECT o.id AS order_id, o.updated_at AS date_time, o.order_code
  FROM orders o
  WHERE o.is_returned = 1
    AND NOT EXISTS (SELECT 1 FROM return_items ri2 WHERE ri2.inv = o.order_code)
) ro
JOIN order_items oi ON oi.order_id = ro.order_id
JOIN orders o ON o.id = ro.order_id
$deliveryJoin
WHERE DATE(ro.date_time) BETWEEN :start AND :end
$deliveryWhere
GROUP BY DATE(ro.date_time)
ORDER BY return_date DESC
";

$dailyStmt = $pdo->prepare($dailySql);
$dailyStmt->execute($params);
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

// Totals for daily summary table
$dailyGrandQty = 0.0;
$dailyGrandReturns = 0;
foreach ($dailyRows as $dr) {
    $dailyGrandQty += (float)($dr['total_qty'] ?? 0);
    $dailyGrandReturns += (int)($dr['returns_count'] ?? 0);
}

// Detailed product list: expand sets into their component products
$roSub = "(
  SELECT o.id AS order_id, ri.date_time, o.order_code
  FROM return_items ri
  JOIN orders o ON o.order_code = ri.inv
  UNION ALL
  SELECT o.id AS order_id, o.updated_at AS date_time, o.order_code
  FROM orders o
  WHERE o.is_returned = 1
    AND NOT EXISTS (SELECT 1 FROM return_items ri2 WHERE ri2.inv = o.order_code)
)";

$detailSql = "
SELECT 
  d.return_date,
  d.product_name,
  d.product_code,
  SUM(d.qty) AS qty,
  COUNT(DISTINCT d.order_code) AS orders_count
FROM (
  -- Expanded components for set products
  SELECT
    DATE(ro.date_time) AS return_date,
    o.order_code,
    pc.name AS product_name,
    CONCAT('PID-', pc.id) AS product_code,
    (oi.quantity * psi.quantity) AS qty
  FROM $roSub ro
  JOIN order_items oi ON oi.order_id = ro.order_id
  JOIN products p ON p.id = oi.product_id
  JOIN orders o ON o.id = ro.order_id
  $deliveryJoin
  JOIN product_sets ps ON ps.set_name = p.name
  JOIN product_set_items psi ON psi.product_set_id = ps.id
  JOIN products pc ON pc.id = psi.product_id
  WHERE DATE(ro.date_time) BETWEEN :start AND :end
  $deliveryWhere

  UNION ALL

  -- Normal products (non-set)
  SELECT
    DATE(ro.date_time) AS return_date,
    o.order_code,
    p.name AS product_name,
    CONCAT('PID-', p.id) AS product_code,
    oi.quantity AS qty
  FROM $roSub ro
  JOIN order_items oi ON oi.order_id = ro.order_id
  JOIN products p ON p.id = oi.product_id
  JOIN orders o ON o.id = ro.order_id
  $deliveryJoin
  WHERE DATE(ro.date_time) BETWEEN :start AND :end
  $deliveryWhere
    AND COALESCE(p.product_type, 'normal') <> 'set'
) d
GROUP BY d.return_date, d.product_code, d.product_name
ORDER BY d.return_date DESC, d.product_name ASC
";

$detailStmt = $pdo->prepare($detailSql);
$detailStmt->execute($params);
$detailRows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$detailTotalQty = 0.0;
$detailTotalOrders = 0;
foreach ($detailRows as $dr) {
    $detailTotalQty += (float)($dr['qty'] ?? 0);
    $detailTotalOrders += (int)($dr['orders_count'] ?? 0);
}

// === DEBUG: Show raw rows being included ===
$debugSql = "
SELECT
  ri.inv,
  ri.date_time AS scanner_date,
  o.id AS order_id,
  o.order_code,
  o.updated_at AS order_mgmt_date,
  o.is_returned,
  ri.delivery_by
FROM return_items ri
LEFT JOIN orders o ON o.order_code = ri.inv
WHERE DATE(ri.date_time) BETWEEN :start AND :end
UNION ALL
SELECT
  o.order_code AS inv,
  NULL AS scanner_date,
  o.id AS order_id,
  o.order_code,
  o.updated_at AS order_mgmt_date,
  o.is_returned,
  '' AS delivery_by
FROM orders o
WHERE o.is_returned = 1
  AND DATE(o.updated_at) BETWEEN :start AND :end
  AND NOT EXISTS (
      SELECT 1 FROM return_items ri2 WHERE ri2.inv = o.order_code
  )
ORDER BY coalesce(scanner_date, order_mgmt_date) DESC
";
$debugStmt = $pdo->prepare($debugSql);
$debugStmt->execute([':start' => $start_date, ':end' => $end_date]);
$debugRows = $debugStmt->fetchAll(PDO::FETCH_ASSOC);

// Build a product-only aggregation (no date) for Excel export of expanded list
$expandedByCode = [];
foreach ($detailRows as $dr) {
    $code = (string)($dr['product_code'] ?? '');
    if ($code === '') { continue; }
    if (!isset($expandedByCode[$code])) {
        $expandedByCode[$code] = [
            'product_code' => $code,
            'product_name' => (string)($dr['product_name'] ?? ''),
            'qty' => 0.0,
            'orders_count' => 0,
        ];
    }
    $expandedByCode[$code]['qty'] += (float)($dr['qty'] ?? 0);
    $expandedByCode[$code]['orders_count'] += (int)($dr['orders_count'] ?? 0);
}
// Sort by qty desc then code asc
usort($expandedByCode, function($a, $b){
    $dq = ($b['qty'] <=> $a['qty']);
    return $dq !== 0 ? $dq : strcmp($a['product_code'], $b['product_code']);
});

// Delivery options for filter dropdown
$deliveryOptions = $pdo->query("SELECT DISTINCT delivery_by FROM out_items WHERE delivery_by IS NOT NULL AND delivery_by != '' ORDER BY delivery_by")
                    ->fetchAll(PDO::FETCH_COLUMN) ?: [];

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=product_return_report_' . $start_date . '_to_' . $end_date . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Product', 'Type', 'Total Qty Returned', 'Returns Count', 'Last Return Date']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['product_name'],
            $r['product_type'] ?? 'normal',
            (float)$r['total_qty'],
            (int)$r['returns_count'],
            $r['last_return_date'],
        ]);
    }
    fclose($out);
    exit;
}

// Excel export (styled HTML table, opens in Excel)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=product_return_report_' . $start_date . '_to_' . $end_date . '.xls');
    echo '<html><head><meta charset="utf-8"><style>
      body{font-family:Arial, sans-serif;font-size:12px;margin:0;padding:8px}
      table{border-collapse:collapse;width:100%;border:1px solid #888}
      th,td{border:1px solid #888;padding:6px;background:#fff}
      thead th{background:#222;color:#fff}
      tfoot th, tfoot td{background:#fff3cd;font-weight:bold}
      .section{margin-top:6px}
      .meta{background:#e6f0ff;margin-bottom:6px}
      h3{margin:8px 0 6px 0;padding:0}
    </style></head><body>';
    // Meta
    echo '<div class="meta"><strong>Product Return Report</strong><br/>';
    echo 'Date range: ' . htmlspecialchars($start_date) . ' to ' . htmlspecialchars($end_date) . '<br/>';
    echo 'Delivery By: ' . ($delivery_filter !== '' ? htmlspecialchars($delivery_filter) : 'All') . '</div>';

    $section = $_GET['section'] ?? 'all';

    // Helper to render Product summary section
    $renderProduct = function() use ($rows, $grandQty, $grandReturns) {
        echo '<div class="section"><h3>Product Return Summary</h3>';
        echo '<table><thead><tr>';
        echo '<th>#</th><th>Product</th><th>Type</th><th>Total Qty Returned</th><th>Returns Count</th><th>Last Return Date</th>';
        echo '</tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="6" style="text-align:center;color:#666">No data</td></tr>';
        } else {
            foreach ($rows as $i => $r) {
                echo '<tr>';
                echo '<td>' . ($i + 1) . '</td>';
                echo '<td>' . htmlspecialchars($r['product_name']) . '</td>';
            echo '<td>' . htmlspecialchars(product_type_display_label($r['product_type'] ?? 'normal')) . '</td>';
                echo '<td>' . number_format((float)$r['total_qty'], 2) . '</td>';
                echo '<td>' . (int)$r['returns_count'] . '</td>';
                echo '<td>' . htmlspecialchars($r['last_return_date']) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody><tfoot><tr>';
        echo '<th colspan="3" style="text-align:right">Total</th>';
        echo '<th>' . number_format($grandQty, 2) . '</th>';
        echo '<th>' . (int)$grandReturns . '</th>';
        echo '<th></th>';
        echo '</tr></tfoot></table></div>';
    };

    // Helper to render Daily section
    $renderDaily = function() use ($dailyRows, $dailyGrandQty, $dailyGrandReturns) {
        echo '<div class="section"><h3>Daily Return Summary</h3>';
        echo '<table><thead><tr>';
        echo '<th>#</th><th>Date</th><th>Total Qty Returned</th><th>Returns Count</th>';
        echo '</tr></thead><tbody>';
        if (empty($dailyRows)) {
            echo '<tr><td colspan="4" style="text-align:center;color:#666">No data</td></tr>';
        } else {
            $i = 0; foreach ($dailyRows as $dr) { $i++; 
                echo '<tr>';
                echo '<td>' . $i . '</td>';
                echo '<td>' . htmlspecialchars($dr['return_date']) . '</td>';
                echo '<td>' . number_format((float)$dr['total_qty'], 2) . '</td>';
                echo '<td>' . (int)$dr['returns_count'] . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody><tfoot><tr>';
        echo '<th colspan="2" style="text-align:right">Total</th>';
        echo '<th>' . number_format($dailyGrandQty, 2) . '</th>';
        echo '<th>' . (int)$dailyGrandReturns . '</th>';
        echo '</tr></tfoot></table></div>';
    };

    // Helper to render Expanded list (grouped by product across date range)
    $renderExpanded = function() use ($expandedByCode) {
        $totalQty = 0.0; $totalOrders = 0;
        echo '<div class="section"><h3>Product List (Expanded Sets)</h3>';
        echo '<table><thead><tr>';
        echo '<th>#</th><th>Product</th><th>Code</th><th>Qty</th><th>Orders</th>';
        echo '</tr></thead><tbody>';
        if (empty($expandedByCode)) {
            echo '<tr><td colspan="5" style="text-align:center;color:#666">No data</td></tr>';
        } else {
            $i = 0; foreach ($expandedByCode as $row) { $i++;
                $totalQty += (float)($row['qty'] ?? 0);
                $totalOrders += (int)($row['orders_count'] ?? 0);
                echo '<tr>';
                echo '<td>' . $i . '</td>';
                echo '<td>' . htmlspecialchars($row['product_name'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($row['product_code'] ?? '') . '</td>';
                echo '<td>' . number_format((float)($row['qty'] ?? 0), 2) . '</td>';
                echo '<td>' . (int)($row['orders_count'] ?? 0) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody><tfoot><tr>';
        echo '<th colspan="3" style="text-align:right">Total</th>';
        echo '<th>' . number_format($totalQty, 2) . '</th>';
        echo '<th>' . (int)$totalOrders . '</th>';
        echo '</tr></tfoot></table></div>';
    };

    if ($section === 'product') {
        $renderProduct();
    } elseif ($section === 'daily') {
        $renderDaily();
    } elseif ($section === 'expanded') {
        $renderExpanded();
    } else { // all
        $renderProduct();
        $renderDaily();
        $renderExpanded();
    }

    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/../layout/header.php';
?>
<style>
  /* Frontend styling for better readability */
  #productReturnsTable thead th,
  #dailyReturnsTable thead th { position: sticky; top: 0; z-index: 1; }
  #productReturnsTable tfoot tr,
  #dailyReturnsTable tfoot tr { font-weight: 600; }
  .badge-type { text-transform: capitalize; font-weight: 600; }
  .badge-type.set { background-color: #ffe08a; color: #4a4a4a; }
  .badge-type.normal { background-color: #adb5bd; color: #212529; }
  @media print {
    #productReturnsTable thead th,
    #dailyReturnsTable thead th { position: static; }
  }
  /* Tighten number columns */
  #productReturnsTable td.text-end,
  #dailyReturnsTable td.text-end { white-space: nowrap; }
</style>
<div class="container-fluid py-4">
  <div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
      <h4 class="mb-0"><i class="bi bi-arrow-return-left me-2"></i>Product Return Summary</h4>
      <div>
        <a href="?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?><?= $delivery_filter!=='' ? '&delivery_filter=' . urlencode($delivery_filter) : '' ?>&export=csv" class="btn btn-outline-primary">
          <i class="bi bi-download me-1"></i>Export CSV
        </a>
        <a href="?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?><?= $delivery_filter!=='' ? '&delivery_filter=' . urlencode($delivery_filter) : '' ?>&export=excel" class="btn btn-success ms-2">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
        </a>
      </div>
    </div>
  </div>
  <form method="GET" class="row g-3 align-items-end mb-3">
    <div class="col-md-3">
      <label class="form-label">Start Date</label>
      <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">End Date</label>
      <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">Delivery By</label>
      <select name="delivery_filter" class="form-select">
        <option value="">All</option>
        <?php foreach ($deliveryOptions as $opt): ?>
          <option value="<?= htmlspecialchars($opt) ?>" <?= $delivery_filter===$opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-search me-1"></i>Filter
      </button>
    </div>
  </form>
  <?php $df = $delivery_filter !== '' ? '&delivery_filter=' . urlencode($delivery_filter) : ''; ?>
  <div class="mb-3 d-flex flex-wrap gap-2">
    <a class="btn <?= $isToday ? 'btn-primary' : 'btn-outline-primary' ?>" href="?start_date=<?= $today ?>&end_date=<?= $today ?><?= $df ?>">Today</a>
    <a class="btn <?= $isYesterday ? 'btn-primary' : 'btn-outline-primary' ?>" href="?start_date=<?= $yesterday ?>&end_date=<?= $yesterday ?><?= $df ?>">Yesterday</a>
    <a class="btn <?= $isWeek ? 'btn-primary' : 'btn-outline-primary' ?>" href="?start_date=<?= $weekStart ?>&end_date=<?= $today ?><?= $df ?>">Weekly</a>
    <a class="btn <?= $isMonth ? 'btn-primary' : 'btn-outline-primary' ?>" href="?start_date=<?= $monthStart ?>&end_date=<?= $today ?><?= $df ?>">Monthly</a>
  </div>

  <!-- Debug Info (collapsible) -->
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Debug: Raw Returns Included</strong>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#debugCollapse">
        <i class="bi bi-chevron-down"></i> Toggle
      </button>
    </div>
    <div class="collapse" id="debugCollapse">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead class="table-light">
              <tr>
                <th>Inv/Order Code</th>
                <th>Scanner Date</th>
                <th>Order Mgmt Date</th>
                <th>Is Returned</th>
                <th>Delivery By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rawReturns as $dr): ?>
                <tr>
                  <td><?= htmlspecialchars($dr['inv']) ?></td>
                  <td><?= $dr['return_source'] === 'scanner' ? htmlspecialchars($dr['date_time']) : '-' ?></td>
                  <td><?= $dr['return_source'] === 'order_management' ? htmlspecialchars($dr['date_time']) : '-' ?></td>
                  <td><?= ($dr['order_status'] === 'returned' || $dr['return_source'] === 'order_management') ? 'Yes' : 'No' ?></td>
                  <td><?= htmlspecialchars($dr['delivery_by'] ?: '-') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Results</strong>
      <a href="?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?><?= $delivery_filter!=='' ? '&delivery_filter=' . urlencode($delivery_filter) : '' ?>&export=excel&section=product" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
      </a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-striped align-middle mb-0" id="productReturnsTable">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Product</th>
              <th>Type</th>
              <th class="text-end">Total Qty Returned</th>
              <th class="text-end">Returns Count</th>
              <th>Last Return Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No data</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $i => $r): ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td><?= htmlspecialchars($r['product_name']) ?></td>
                  <td>
                    <?php $__t = strtolower($r['product_type'] ?? 'normal'); ?>
                                <span class="badge badge-type <?= $__t === 'set' ? 'set' : 'normal' ?>"><?= htmlspecialchars(product_type_display_label($__t)) ?></span>
                  </td>
                  <td class="text-end"><?= number_format((float)$r['total_qty'], 2) ?></td>
                  <td class="text-end"><?= (int)$r['returns_count'] ?></td>
                  <td><?= htmlspecialchars($r['last_return_date']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="table-secondary">
              <th class="text-center">#</th>
              <th colspan="2" class="text-end">Total</th>
              <th class="text-end"><?= number_format($grandQty, 2) ?></th>
              <th class="text-end"><?= (int)$grandReturns ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  
  <div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Daily Return Summary</strong>
      <a href="?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?><?= $delivery_filter!=='' ? '&delivery_filter=' . urlencode($delivery_filter) : '' ?>&export=excel&section=daily" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
      </a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-striped align-middle mb-0" id="dailyReturnsTable">
          <thead class="table-dark">
            <tr>
              <th>Date</th>
              <th class="text-end">Total Qty Returned</th>
              <th class="text-end">Returns Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($dailyRows)): ?>
              <tr><td colspan="3" class="text-center py-4 text-muted">No data</td></tr>
            <?php else: ?>
              <?php foreach ($dailyRows as $dr): ?>
                <tr>
                  <td><?= htmlspecialchars($dr['return_date']) ?></td>
                  <td class="text-end"><?= number_format((float)$dr['total_qty'], 2) ?></td>
                  <td class="text-end"><?= (int)$dr['returns_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="table-secondary">
              <th class="text-end">Total</th>
              <th><?= number_format($dailyGrandQty, 2) ?></th>
              <th><?= (int)$dailyGrandReturns ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
  
  <div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong>Product List (Expanded Sets)</strong>
        <small class="text-muted">All returned products with sets expanded into components</small>
      </div>
      <a href="?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?><?= $delivery_filter!=='' ? '&delivery_filter=' . urlencode($delivery_filter) : '' ?>&export=excel&section=expanded" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
      </a>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-striped align-middle mb-0" id="productListExpandedTable">
          <thead class="table-dark">
            <tr>
              <th class=\"text-center\" style=\"width:60px;\">No</th>
              <th>Return Date</th>
              <th>Product Name</th>
              <th style=\"width:140px;\">Code</th>
              <th class=\"text-end\" style=\"width:120px;\">Qty</th>
              <th class=\"text-end\" style=\"width:120px;\">Orders</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($detailRows)): ?>
              <tr><td colspan=\"6\" class=\"text-center py-4 text-muted\">No data</td></tr>
            <?php else: ?>
              <?php $__i=0; foreach ($detailRows as $row): $__i++; ?>
                <tr>
                  <td class=\"text-center\"><?= $__i ?></td>
                  <td><?= htmlspecialchars($row['return_date']) ?></td>
                  <td><?= htmlspecialchars($row['product_name']) ?></td>
                  <td><?= htmlspecialchars($row['product_code'] ?? '') ?></td>
                  <td class=\"text-end\"><?= number_format((float)$row['qty'], 2) ?></td>
                  <td class=\"text-end\"><?= number_format((int)($row['orders_count'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="table-secondary">
              <th class=\"text-center\">#</th>
              <th>Return Date</th>
              <th colspan=\"2\" class=\"text-end\">Total</th>
              <th class=\"text-end\"><?= number_format($detailTotalQty, 2) ?></th>
              <th class=\"text-end\"><?= number_format($detailTotalOrders) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
