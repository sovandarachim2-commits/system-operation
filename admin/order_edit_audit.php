<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'order_audit.view');
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();

// Filters
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$user_name = trim($_GET['user_name'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');

// Build query
$params = [];
$sql = 'SELECT a.id, a.order_id, a.user_id, a.user_name, a.action, a.details, a.created_at,
               o.order_code, o.customer_name, o.phone, u.name AS seller_name
        FROM order_edit_audit a
        LEFT JOIN orders o ON o.id = a.order_id
        LEFT JOIN users u ON u.id = o.seller_id';
$where = [];
if ($order_id > 0) { $where[] = 'order_id = ?'; $params[] = $order_id; }
if ($user_name !== '') { $where[] = 'user_name LIKE ?'; $params[] = "%$user_name%"; }
if ($start_date !== '') { $where[] = 'DATE(created_at) >= ?'; $params[] = $start_date; }
if ($end_date !== '') { $where[] = 'DATE(created_at) <= ?'; $params[] = $end_date; }
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Order Edit Audit</h1>
  </div>

  <form method="get" class="card shadow-sm mb-3">
    <div class="card-body row g-3 align-items-end">
      <div class="col-12 col-md-2">
        <label class="form-label">Order ID</label>
        <input type="number" name="order_id" class="form-control form-control-lg" value="<?= htmlspecialchars((string)$order_id) ?>" />
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">User Name</label>
        <input type="text" name="user_name" class="form-control form-control-lg" value="<?= htmlspecialchars($user_name) ?>" />
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Start Date</label>
        <input type="date" name="start_date" class="form-control form-control-lg" value="<?= htmlspecialchars($start_date) ?>" />
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">End Date</label>
        <input type="date" name="end_date" class="form-control form-control-lg" value="<?= htmlspecialchars($end_date) ?>" />
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button type="submit" class="btn btn-outline-primary btn-lg">Filter</button>
      </div>
    </div>
  </form>

  <div class="card shadow-sm flex-grow-1 d-flex flex-column">
    <div class="card-body p-3 pb-0">
      <div class="row g-2 mb-2">
        <div class="col-12 col-md-4 ms-auto">
          <input id="auditQuickSearch" type="text" class="form-control form-control-sm" placeholder="Quick search (Order, User, Action)..." />
        </div>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive" style="max-height: 65vh; overflow: auto;">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
            <tr>
              <th style="width: 70px;">ID</th>
              <th style="width: 110px;">Order</th>
              <th>Customer</th>
              <th>Seller</th>
              <th>Phone</th>
              <th>User</th>
              <th style="width: 120px;">Action</th>
              <th style="width: 180px;">When</th>
              <th style="width: 90px;">Changes</th>
              <th style="width: 90px;">Details</th>
            </tr>
          </thead>
          <tbody id="auditTableBody">
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center py-4">No audit records found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php 
                $details = json_decode($r['details'] ?? '[]', true);
                $printed = $details['printed'] ?? null;
                $changes = $details['changes'] ?? [];
                $totals = $details['totals']['grand_total'] ?? null;
                $collapseId = 'd_' . (int)$r['id'];
                $changeCount = is_array($changes) ? count($changes) : 0;
              ?>
              <tr data-rowtext="<?= htmlspecialchars('#' . (int)$r['order_id'] . ' ' . ($r['order_code'] ?? '') . ' ' . ($r['customer_name'] ?? '') . ' ' . ($r['seller_name'] ?? '') . ' ' . ($r['phone'] ?? '') . ' ' . ($r['user_name'] ?? '') . ' ' . ($r['action'] ?? '')) ?>">
                <td class="text-muted"><?= (int)$r['id'] ?></td>
                <td>
                  <a href="<?= htmlspecialchars($BASE_URL) ?>/receipt.php?id=<?= (int)$r['order_id'] ?>" target="_blank">#<?= (int)$r['order_id'] ?></a>
                  <?php if (!empty($r['order_code'])): ?>
                    <span class="ms-1 badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($r['order_code']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['customer_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['seller_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['user_name'] ?? 'Unknown') ?></td>
                <td>
                  <span class="badge <?= strpos((string)$r['action'],'printed') !== false ? 'bg-primary' : 'bg-secondary' ?>">
                    <?= htmlspecialchars($r['action']) ?>
                  </span>
                </td>
                <td><span class="small text-nowrap"><?= htmlspecialchars($r['created_at']) ?></span></td>
                <td>
                  <span class="badge bg-dark-subtle text-dark border border-1"><?= (int)$changeCount ?></span>
                </td>
                <td>
                  <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="false">View</button>
                </td>
              </tr>
              <tr class="collapse" id="<?= $collapseId ?>">
                <td colspan="9">
                  <div class="p-3">
                    <?php if ($printed !== null): ?>
                      <div class="mb-2"><strong>Printed:</strong> <?= $printed ? 'Yes' : 'No' ?></div>
                    <?php endif; ?>
                    <?php if ($totals !== null): ?>
                      <div class="mb-2"><strong>Grand Total:</strong> $<?= number_format((float)$totals, 2) ?></div>
                    <?php endif; ?>
                    <?php if ($changes): ?>
                      <div class="table-responsive">
                        <table class="table table-sm mb-0">
                          <thead>
                            <tr>
                              <th>Product</th>
                              <th class="text-end">Old</th>
                              <th class="text-end">New</th>
                              <th class="text-end">Delta</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($changes as $c): ?>
                              <?php $delta = (int)($c['delta'] ?? 0); ?>
                              <tr>
                                <td><?= htmlspecialchars($c['product_name'] ?? ('ID ' . ($c['product_id'] ?? ''))) ?></td>
                                <td class="text-end text-muted"><?= (int)($c['old_qty'] ?? 0) ?></td>
                                <td class="text-end"><?= (int)($c['new_qty'] ?? 0) ?></td>
                                <td class="text-end">
                                  <span class="badge <?= $delta > 0 ? 'bg-danger' : ($delta < 0 ? 'bg-success' : 'bg-secondary') ?>"><?= $delta ?></span>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <div class="text-muted">No line changes captured.</div>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var input = document.getElementById('auditQuickSearch');
      if (!input) return;
      var tbody = document.getElementById('auditTableBody');
      if (!tbody) return;
      var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
      input.addEventListener('input', function(){
        var q = (input.value || '').toLowerCase();
        var visibleMain = 0;
        for (var i = 0; i < rows.length; i+=2) {
          var main = rows[i];
          var detail = rows[i+1];
          if (!main) break;
          var text = (main.getAttribute('data-rowtext') || '').toLowerCase();
          var show = q === '' || text.indexOf(q) !== -1;
          main.style.display = show ? '' : 'none';
          if (detail) detail.style.display = show ? '' : 'none';
          if (show) visibleMain++;
        }
        if (visibleMain === 0) {
          tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No audit records match your search.</td></tr>';
        } else {
          // Rebuild not needed; rows preserved.
        }
      });
    })();
  </script>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
