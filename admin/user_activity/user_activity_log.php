<?php
require_once __DIR__ . '/../../auth.php';
require_role_or_permission(['admin'], 'users_activity.view', 'sr_user_activity.view');
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../user_activity_lib.php';

$pdo = get_db_connection();
user_activity_ensure_table($pdo);

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$action_q = trim($_GET['action'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$quick_range = strtolower(trim((string)($_GET['quick_range'] ?? '')));
$quick_range_labels = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'last7' => 'Last 7 days',
];
$quick_range_valid = ['today', 'yesterday', 'last7', 'month'];
if (!in_array($quick_range, $quick_range_valid, true)) {
    $quick_range = '';
}
$filter_month = (int)($_GET['filter_month'] ?? 0);
$filter_year = (int)($_GET['filter_year'] ?? 0);
if ($filter_month < 1 || $filter_month > 12) {
    $filter_month = (int)date('n');
}
if ($filter_year < 2000 || $filter_year > 2100) {
    $filter_year = (int)date('Y');
}
$month_period_label = '';
if ($quick_range !== '') {
    $today = date('Y-m-d');
    switch ($quick_range) {
        case 'today':
            $start_date = $today;
            $end_date = $today;
            break;
        case 'yesterday':
            $d = date('Y-m-d', strtotime('-1 day'));
            $start_date = $d;
            $end_date = $d;
            break;
        case 'last7':
            $end_date = $today;
            $start_date = date('Y-m-d', strtotime('-6 days'));
            break;
        case 'month':
            $start_date = sprintf('%04d-%02d-01', $filter_year, $filter_month);
            $end_date = date('Y-m-t', strtotime($start_date));
            $month_period_label = date('M Y', mktime(0, 0, 0, $filter_month, 1, $filter_year));
            break;
    }
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 100);
if (!in_array($perPage, [50, 100, 200, 500], true)) {
    $perPage = 100;
}

$log_type = strtolower(trim((string)($_GET['log_type'] ?? '')));
if ($log_type !== '' && !in_array($log_type, user_activity_log_type_keys(), true)) {
    $log_type = '';
}

$params = [];
$sqlFrom = ' FROM user_activity_log';
$where = [];
if ($user_id > 0) {
    $where[] = 'user_id = ?';
    $params[] = $user_id;
}
if ($action_q !== '') {
    $where[] = 'action LIKE ?';
    $params[] = '%' . $action_q . '%';
}
[$logTypeSql, $logTypeParams] = user_activity_log_type_sql($log_type);
if ($logTypeSql !== '') {
    $where[] = '(' . $logTypeSql . ')';
    foreach ($logTypeParams as $lp) {
        $params[] = $lp;
    }
}
if ($start_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $where[] = 'created_at >= ?';
    $params[] = $start_date . ' 00:00:00';
}
if ($end_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $endTs = strtotime($end_date . ' 23:59:59');
    if ($endTs !== false) {
        $where[] = 'created_at <= ?';
        $params[] = date('Y-m-d H:i:s', $endTs);
    }
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countSql = 'SELECT COUNT(*)' . $sqlFrom . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT user_id, user_name, action, details, context, ip_address, device, device_name, device_model, user_agent, request_uri, created_at'
    . $sqlFrom . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$userPickerList = $pdo->query('SELECT id, name, username FROM users ORDER BY name ASC, username ASC')->fetchAll(PDO::FETCH_ASSOC);
$userFilterBadgeLabel = '';
if ($user_id > 0) {
    foreach ($userPickerList as $uu) {
        if ((int)$uu['id'] === $user_id) {
            $nm = trim((string)($uu['name'] ?? ''));
            $userFilterBadgeLabel = ($nm !== '' ? $nm : (string)$uu['username']) . ' (@' . (string)$uu['username'] . ')';
            break;
        }
    }
    if ($userFilterBadgeLabel === '') {
        $userFilterBadgeLabel = 'User #' . $user_id;
    }
}

$qsBase = [];
if ($user_id > 0) {
    $qsBase['user_id'] = $user_id;
}
if ($action_q !== '') {
    $qsBase['action'] = $action_q;
}
if ($start_date !== '') {
    $qsBase['start_date'] = $start_date;
}
if ($end_date !== '') {
    $qsBase['end_date'] = $end_date;
}
if ($quick_range !== '') {
    $qsBase['quick_range'] = $quick_range;
}
if ($quick_range === 'month') {
    $qsBase['filter_month'] = $filter_month;
    $qsBase['filter_year'] = $filter_year;
}
if ($perPage !== 100) {
    $qsBase['per_page'] = $perPage;
}
if ($log_type !== '') {
    $qsBase['log_type'] = $log_type;
}
$buildPageUrl = static function (int $p) use ($qsBase): string {
    $q = array_merge($qsBase, ['page' => $p]);
    return 'user_activity_log.php?' . http_build_query($q);
};

$start_item = $totalRows === 0 ? 0 : ($page - 1) * $perPage + 1;
$end_item = min($page * $perPage, $totalRows);

$activityQueryMerge = static function (array $override) use ($qsBase): string {
    return http_build_query(array_merge($qsBase, $override));
};

include __DIR__ . '/../../layout/header.php';
?>
<div class="container-fluid flex-grow-1 py-4">
  <div class="row">
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">
              <i class="bi bi-journal-text me-2"></i>
              User activity log
            </h5>
            <div class="badge bg-white text-primary fs-6">
              <i class="bi bi-list-ol me-1"></i>
              <?= number_format($totalRows) ?> <?= $totalRows === 1 ? 'row' : 'rows' ?>
            </div>
          </div>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-4">Login, logout, <strong>page_view</strong>, and other events. For very large logs, use a <strong>date range</strong> or filters.</p>
          <p class="text-muted small mb-4 border-start border-3 border-secondary ps-3"><strong>Model Name</strong> is the same idea as iPhone <strong>Settings → General → About → Model Name</strong> (e.g. iPhone 12) when we can infer it. <strong>Name</strong> (the device nickname in About) is never sent to websites, so we cannot show it. <strong>Model code</strong> comes from the browser (e.g. iPhone13,2); it is not Apple’s <strong>Model Number</strong> (SKU like MGEW3LL/A). On <strong>computers</strong>, Model Name is broad (e.g. Windows PC, Mac, Linux PC); browsers do not send exact PC model (e.g. Dell XPS) or MacBook variant. Model code may show OS kernel hints (e.g. WinNT 10.0, Mac (Intel) vs Mac (Apple silicon)).</p>

          <div class="card bg-light border-0 mb-4">
            <div class="card-body">
              <form method="get">
                <input type="hidden" name="page" value="1" />
                <div class="row g-3 align-items-end">
                  <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-person me-1"></i>User
                    </label>
                    <select name="user_id" class="form-select">
                      <option value="">All users</option>
                      <?php foreach ($userPickerList as $uu): ?>
                        <?php
                        $uidPick = (int)$uu['id'];
                        $disp = trim((string)($uu['name'] ?? ''));
                        $disp = $disp !== '' ? $disp . ' (@' . $uu['username'] . ')' : (string)$uu['username'];
                        ?>
                        <option value="<?= $uidPick ?>" <?= $user_id === $uidPick ? 'selected' : '' ?>><?= htmlspecialchars($disp) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-funnel me-1"></i>Log type
                    </label>
                    <select name="log_type" class="form-select">
                      <?php foreach (user_activity_log_type_options() as $val => $lbl): ?>
                        <option value="<?= htmlspecialchars((string)$val) ?>" <?= $log_type === (string)$val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-lightning me-1"></i>Action
                    </label>
                    <input type="text" name="action" class="form-control" value="<?= htmlspecialchars($action_q) ?>" placeholder="Contains… e.g. login" />
                  </div>
                  <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar-week me-1"></i>Quick period
                    </label>
                    <select name="quick_range" class="form-select">
                      <option value="" <?= $quick_range === '' ? 'selected' : '' ?>>Custom range</option>
                      <?php foreach ($quick_range_labels as $qk => $ql): ?>
                        <option value="<?= htmlspecialchars($qk) ?>" <?= $quick_range === $qk ? 'selected' : '' ?>><?= htmlspecialchars($ql) ?></option>
                      <?php endforeach; ?>
                      <option value="month" <?= $quick_range === 'month' ? 'selected' : '' ?>>Month</option>
                    </select>
                  </div>
                  <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar3 me-1"></i>Month
                    </label>
                    <select name="filter_month" class="form-select">
                      <?php for ($m = 1; $m <= 12; $m++): ?>
                        <?php $mLabel = date('M', mktime(0, 0, 0, $m, 1)); ?>
                        <option value="<?= $m ?>" <?= $filter_month === $m ? 'selected' : '' ?>><?= htmlspecialchars($mLabel) ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                  <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar3 me-1"></i>Year
                    </label>
                    <select name="filter_year" class="form-select">
                      <?php
                      $yMax = (int)date('Y');
                      $yMin = $yMax - 10;
                      for ($y = $yMax; $y >= $yMin; $y--):
                      ?>
                        <option value="<?= $y ?>" <?= $filter_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                  <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar-range me-1"></i>From
                    </label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" />
                  </div>
                  <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar-range me-1"></i>To
                    </label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" />
                  </div>
                  <div class="col-12 col-md-4 col-lg-1">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-layout-three-columns me-1"></i>Per page
                    </label>
                    <select name="per_page" class="form-select">
                      <?php foreach ([50, 100, 200, 500] as $n): ?>
                        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="row g-3 mt-1">
                  <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                      <i class="bi bi-search me-1"></i>Search
                    </button>
                    <a href="user_activity_log.php" class="btn btn-outline-secondary">
                      <i class="bi bi-arrow-clockwise me-1"></i>Reset
                    </a>
                  </div>
                </div>

                <?php if ($user_id > 0 || $log_type !== '' || $action_q !== '' || $quick_range !== '' || $start_date !== '' || $end_date !== '' || $perPage !== 100): ?>
                <div class="row mt-3">
                  <div class="col-12">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <span class="text-muted fw-semibold">Active filters:</span>
                      <?php if ($user_id > 0): ?>
                        <span class="badge bg-primary"><?= htmlspecialchars($userFilterBadgeLabel) ?>
                          <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['user_id' => '', 'page' => 1])) ?>" class="text-white text-decoration-none ms-1">×</a>
                        </span>
                      <?php endif; ?>
                      <?php if ($log_type !== ''): ?>
                        <span class="badge bg-dark"><?= htmlspecialchars((string)(user_activity_log_type_options()[$log_type] ?? $log_type)) ?>
                          <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['log_type' => '', 'page' => 1])) ?>" class="text-white text-decoration-none ms-1">×</a>
                        </span>
                      <?php endif; ?>
                      <?php if ($action_q !== ''): ?>
                        <span class="badge bg-info text-dark">Action: <?= htmlspecialchars($action_q) ?>
                          <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['action' => '', 'page' => 1])) ?>" class="text-dark text-decoration-none ms-1">×</a>
                        </span>
                      <?php endif; ?>
                      <?php if ($quick_range !== ''): ?>
                        <span class="badge bg-success"><?= htmlspecialchars($quick_range === 'month' ? $month_period_label : ($quick_range_labels[$quick_range] ?? $quick_range)) ?>
                          <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['quick_range' => '', 'start_date' => '', 'end_date' => '', 'filter_month' => '', 'filter_year' => '', 'page' => 1])) ?>" class="text-white text-decoration-none ms-1">×</a>
                        </span>
                      <?php else: ?>
                        <?php if ($start_date !== ''): ?>
                          <span class="badge bg-success">From <?= htmlspecialchars($start_date) ?>
                            <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['start_date' => '', 'page' => 1])) ?>" class="text-white text-decoration-none ms-1">×</a>
                          </span>
                        <?php endif; ?>
                        <?php if ($end_date !== ''): ?>
                          <span class="badge bg-warning text-dark">To <?= htmlspecialchars($end_date) ?>
                            <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['end_date' => '', 'page' => 1])) ?>" class="text-dark text-decoration-none ms-1">×</a>
                          </span>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if ($perPage !== 100): ?>
                        <span class="badge bg-secondary"><?= (int)$perPage ?> / page
                          <a href="user_activity_log.php?<?= htmlspecialchars($activityQueryMerge(['per_page' => 100, 'page' => 1])) ?>" class="text-white text-decoration-none ms-1">×</a>
                        </span>
                      <?php endif; ?>
                      <a href="user_activity_log.php" class="btn btn-sm btn-outline-danger">Clear all</a>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>When</th>
                  <th>User</th>
                  <th>Log type</th>
                  <th>Action</th>
                  <th title="Short summary of what changed (e.g. invoice/barcode, delivery, IDs). Long values are truncated.">Details</th>
                  <th>IP</th>
                  <th title="Summary: model + OS version">Device</th>
                  <th title="Like Settings → About → Model Name (e.g. iPhone 12). Not the device Name nickname.">Model Name</th>
                  <th title="From User-Agent / hints (e.g. iPhone13,2). Not Apple Model Number (MGEW3LL/A).">Model code</th>
                  <th>URI</th>
                  <th title="Which website the user was on when this action was logged.">Source</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="12" class="text-center text-muted py-5">
                      <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                      <?= $totalRows === 0 ? 'No rows match your filters (or the log is empty yet).' : 'No rows on this page.' ?>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php $no = $start_item; foreach ($rows as $r): ?>
                    <tr>
                      <td class="text-muted small"><?= (int)$no++ ?></td>
                      <td class="small text-nowrap"><?= htmlspecialchars(date('M j, Y H:i', strtotime((string)$r['created_at']))) ?></td>
                      <td>
                        <?php
                        $actorName = trim((string)($r['user_name'] ?? ''));
                        echo $actorName !== '' ? htmlspecialchars($actorName) : '<span class="text-muted">—</span>';
                        ?>
                      </td>
                      <td>
                        <?php
                        $ltKey = user_activity_log_type_from_action((string)($r['action'] ?? ''));
                        $ltLabel = user_activity_log_type_label($ltKey);
                        if ($ltKey === 'create') {
                            $ltClass = 'bg-success';
                        } elseif ($ltKey === 'edit') {
                            $ltClass = 'bg-primary';
                        } elseif ($ltKey === 'delete') {
                            $ltClass = 'bg-danger';
                        } elseif ($ltKey === 'login') {
                            $ltClass = 'bg-warning text-dark';
                        } elseif ($ltKey === 'lockout') {
                            $ltClass = 'bg-dark';
                        } elseif ($ltKey === 'view') {
                            $ltClass = 'bg-info text-dark';
                        } else {
                            $ltClass = 'bg-secondary';
                        }
                        ?>
                        <span class="badge <?= $ltClass ?> fw-normal"><?= htmlspecialchars($ltLabel) ?></span>
                      </td>
                      <td><span class="badge bg-dark fw-normal"><?= htmlspecialchars((string)$r['action']) ?></span></td>
                      <td class="small"><?= htmlspecialchars((string)($r['details'] ?? '')) ?></td>
                      <td class="small font-monospace"><?php
                        $ip = (string)($r['ip_address'] ?? '');
                        echo $ip === '::1' ? '127.0.0.1' : htmlspecialchars($ip);
                      ?></td>
                      <td class="small"><?php
                        $dev = trim((string)($r['device'] ?? ''));
                        echo $dev !== '' ? htmlspecialchars($dev) : '<span class="text-muted">—</span>';
                      ?></td>
                      <td class="small"><?php
                        $dname = user_activity_display_device_name(
                            isset($r['device_name']) ? (string)$r['device_name'] : null,
                            isset($r['device']) ? (string)$r['device'] : null
                        );
                        echo $dname !== '' ? htmlspecialchars($dname) : '<span class="text-muted">—</span>';
                      ?></td>
                      <td class="small font-monospace"><?php
                        $dmod = user_activity_display_device_model(
                            isset($r['device_model']) ? (string)$r['device_model'] : null,
                            isset($r['user_agent']) ? (string)$r['user_agent'] : null
                        );
                        echo $dmod !== '' ? htmlspecialchars($dmod) : '<span class="text-muted">—</span>';
                      ?></td>
                      <?php
                        $uri = (string)($r['request_uri'] ?? '');
                        $uriShort = preg_replace('#^https?://[^/]+#', '', $uri);
                        $uriShort = mb_strlen($uriShort) > 30 ? mb_substr($uriShort, 0, 30) . '...' : $uriShort;
                      ?><td class="small" style="cursor:pointer; text-decoration:underline dotted #3b82f6; color:#3b82f6;" onclick="showDetailCard('URI', this.dataset.full)" data-full="<?= htmlspecialchars($uri) ?>"><?= htmlspecialchars($uriShort ?: '—') ?></td>
                      <td class="small"><?php
                        $furl = trim((string)($r['frontend_url'] ?? ''));
                        if ($furl !== '') {
                          $furlShort = preg_replace('#^https?://[^/]+#', '', $furl);
                          $furlShort = mb_strlen($furlShort) > 30 ? mb_substr($furlShort, 0, 30) . '...' : $furlShort;
                          echo '<span style="cursor:pointer; text-decoration:underline dotted #3b82f6; color:#3b82f6;" onclick="showDetailCard(\'Source\', this.dataset.full)" data-full="' . htmlspecialchars($furl) . '">' . htmlspecialchars($furlShort) . '</span>';
                        } else {
                          echo '<span class="text-muted">—</span>';
                        }
                      ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
          <div class="row mt-4">
            <div class="col-12">
              <nav aria-label="Activity pagination">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div class="text-muted small">
                    Showing <?= number_format($start_item) ?> to <?= number_format($end_item) ?> of <?= number_format($totalRows) ?> rows
                    <span class="text-nowrap">(Page <?= $page ?> of <?= $totalPages ?>)</span>
                  </div>
                  <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                      <li class="page-item">
                        <a class="page-link" href="<?= htmlspecialchars($buildPageUrl($page - 1)) ?>"><i class="bi bi-chevron-left"></i> Previous</a>
                      </li>
                    <?php else: ?>
                      <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i> Previous</span></li>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($totalPages, $page + 2);
                    if ($start_page > 1): ?>
                      <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($buildPageUrl(1)) ?>">1</a></li>
                      <?php if ($start_page > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars($buildPageUrl($i)) ?>"><?= $i ?></a>
                      </li>
                    <?php endfor; ?>

                    <?php if ($end_page < $totalPages): ?>
                      <?php if ($end_page < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                      <?php endif; ?>
                      <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($buildPageUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                      <li class="page-item">
                        <a class="page-link" href="<?= htmlspecialchars($buildPageUrl($page + 1)) ?>">Next <i class="bi bi-chevron-right"></i></a>
                      </li>
                    <?php else: ?>
                      <li class="page-item disabled"><span class="page-link">Next <i class="bi bi-chevron-right"></i></span></li>
                    <?php endif; ?>
                  </ul>
                </div>
              </nav>
            </div>
          </div>
          <?php elseif ($totalRows > 0): ?>
          <div class="text-muted small mt-3">
            Showing <?= number_format($start_item) ?> to <?= number_format($end_item) ?> of <?= number_format($totalRows) ?> rows
          </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>
</div>

<div id="detail-card-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:999; background:rgba(0,0,0,0.3);" onclick="hideDetailCard()">
  <div id="detail-card-box" style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#1e293b; border:1px solid #334155; border-radius:8px; padding:18px 22px; z-index:1000; min-width:400px; max-width:600px; box-shadow:0 8px 30px rgba(0,0,0,0.4);" onclick="event.stopPropagation()">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
      <div id="detail-card-label" style="font-size:12px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:1px;"></div>
      <button type="button" onclick="hideDetailCard()" style="background:transparent; color:#94a3b8; border:none; font-size:18px; cursor:pointer; padding:0 4px; line-height:1;">×</button>
    </div>
    <div id="detail-card-full" style="font-size:13px; color:#e2e8f0; word-break:break-all; line-height:1.6; font-family:monospace; background:#0f172a; border-radius:6px; padding:12px 14px; border:1px solid #1e293b;"></div>
  </div>
</div>
<script>
function showDetailCard(label, full) {
  document.getElementById('detail-card-label').textContent = label;
  document.getElementById('detail-card-full').textContent = full || '\u2014';
  document.getElementById('detail-card-overlay').style.display = 'block';
}
function hideDetailCard() {
  document.getElementById('detail-card-overlay').style.display = 'none';
}
</script>
<?php include __DIR__ . '/../../layout/footer.php'; ?>
