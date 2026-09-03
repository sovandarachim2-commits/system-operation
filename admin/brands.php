<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'brands.view');
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

$errors  = [];
$success = '';

// Ensure brands table exists
try {
    $pdo->query("SELECT 1 FROM brands LIMIT 1");
} catch (Throwable $e) {
    die('<div style="padding:2rem;font-family:sans-serif"><h2>Brands table not found</h2><p>Please run <a href="/LuckyBox/migrate_add_brands.php">migrate_add_brands.php</a> first.</p></div>');
}

// Auto-add columns if missing
try {
    $col = $pdo->query("SHOW COLUMNS FROM brands LIKE 'logo_path'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE brands ADD COLUMN logo_path VARCHAR(255) NULL DEFAULT NULL");
    }
} catch (Throwable $e) {}

try {
    $col = $pdo->query("SHOW COLUMNS FROM brands LIKE 'created_at'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE brands ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Throwable $e) {}

function saveBrandLogo(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) return null;
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return null;
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'brand_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    $storedPath = upload_store_uploaded_file($file, 'brands', $filename, null, $mime);
    return $storedPath !== '' ? $storedPath : null;
}

function deleteBrandLogo(?string $logoPath): void {
    upload_delete_local_file($logoPath, 'brands');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#6c757d');
        if ($name === '') {
            $errors[] = 'Brand name is required.';
        } else {
            $logoFile = saveBrandLogo($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '', 'name' => '']);
            $stmt = $pdo->prepare('INSERT INTO brands (name, color, logo_path, active) VALUES (?, ?, ?, 1)');
            $stmt->execute([$name, $color, $logoFile]);
            $success = 'Brand "' . htmlspecialchars($name) . '" added.';
        }
    } elseif ($action === 'update') {
        $id    = (int)($_POST['id']    ?? 0);
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#6c757d');
        if ($id > 0 && $name !== '') {
            $existing = $pdo->prepare('SELECT logo_path FROM brands WHERE id = ?');
            $existing->execute([$id]);
            $oldLogo = $existing->fetchColumn();

            $newLogo = saveBrandLogo($_FILES['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '', 'name' => '']);

            if ($newLogo) {
                deleteBrandLogo($oldLogo ?: null);
                $pdo->prepare('UPDATE brands SET name = ?, color = ?, logo_path = ? WHERE id = ?')->execute([$name, $color, $newLogo, $id]);
            } else {
                $pdo->prepare('UPDATE brands SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
            }
            $success = 'Brand updated.';
        }
    } elseif ($action === 'remove_logo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $existing = $pdo->prepare('SELECT logo_path FROM brands WHERE id = ?');
            $existing->execute([$id]);
            $oldLogo = $existing->fetchColumn();
            deleteBrandLogo($oldLogo ?: null);
            $pdo->prepare('UPDATE brands SET logo_path = NULL WHERE id = ?')->execute([$id]);
            $success = 'Logo removed.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE brands SET active = 1 - active WHERE id = ?')->execute([$id]);
            $success = 'Brand status toggled.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $existing = $pdo->prepare('SELECT logo_path FROM brands WHERE id = ?');
            $existing->execute([$id]);
            $oldLogo = $existing->fetchColumn();
            deleteBrandLogo($oldLogo ?: null);
            $pdo->prepare('UPDATE products SET brand_id = NULL WHERE brand_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
            $success = 'Brand deleted.';
        }
    }
}

// Summary stats
$totalBrands        = (int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn();
$activeBrands       = (int)$pdo->query('SELECT COUNT(*) FROM brands WHERE active = 1')->fetchColumn();
$inactiveBrands     = $totalBrands - $activeBrands;
$totalProducts      = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE brand_id IS NOT NULL')->fetchColumn();
$brandsWithProducts = (int)$pdo->query('SELECT COUNT(DISTINCT brand_id) FROM products WHERE brand_id IS NOT NULL')->fetchColumn();

// Pagination
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$perPage    = in_array($perPageRaw, [5, 10, 20, 50]) ? $perPageRaw : 10;
$totalPages = max(1, (int)ceil($totalBrands / $perPage));
$page       = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare('SELECT * FROM brands ORDER BY name ASC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
$stmt->execute();
$brands = $stmt->fetchAll();

// Count products per brand
$countMap = [];
$rows = $pdo->query('SELECT brand_id, COUNT(*) AS cnt FROM products WHERE brand_id IS NOT NULL GROUP BY brand_id')->fetchAll();
foreach ($rows as $r) {
    $countMap[(int)$r['brand_id']] = (int)$r['cnt'];
}

$showingFrom = $totalBrands > 0 ? $offset + 1 : 0;
$showingTo   = min($offset + $perPage, $totalBrands);

$buildPageUrl = function (int $p) use ($perPage): string {
    $params = ['page' => $p, 'per_page' => $perPage];
    return 'brands.php?' . http_build_query($params);
};

include __DIR__ . '/../layout/header.php';
?>

<style>
.brands-page { background:#f3f4f6; min-height:100%; padding:1.5rem; }
.stat-card {
    background:#fff; border-radius:14px; padding:1.1rem 1.4rem;
    display:flex; align-items:center; gap:1rem;
    box-shadow:0 1px 4px rgba(0,0,0,0.06); border:1px solid #ebebeb;
}
.stat-icon {
    width:52px; height:52px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:1.3rem; flex-shrink:0;
}
.stat-icon.purple { background:#f0ebff; color:#7c3aed; }
.stat-icon.green  { background:#e6f9ef; color:#16a34a; }
.stat-icon.orange { background:#fff4e6; color:#ea6c00; }
.stat-icon.blue   { background:#e8f0ff; color:#2563eb; }
.stat-icon.pink   { background:#fde8f4; color:#db2777; }
.brands-card {
    background:#fff; border-radius:14px;
    box-shadow:0 1px 4px rgba(0,0,0,0.06); border:1px solid #ebebeb;
    overflow:hidden;
}
.brands-table th {
    font-weight:600; font-size:13px; color:#374151;
    background:#fff; border-bottom:2px solid #f0f0f0;
    padding:13px 16px; white-space:nowrap;
}
.brands-table td {
    padding:14px 16px; border-bottom:1px solid #f5f5f5;
    vertical-align:middle; font-size:14px;
}
.brands-table tbody tr:last-child td { border-bottom:none; }
.brands-table tbody tr:hover td { background:#fafafa; }
.brand-logo-box {
    width:62px; height:62px; border-radius:10px;
    border:1px solid #e5e7eb; background:#f9fafb;
    display:flex; align-items:center; justify-content:center; overflow:hidden;
}
.brand-logo-box img { width:100%; height:100%; object-fit:contain; }
.color-dot {
    width:12px; height:12px; border-radius:50%;
    display:inline-block; border:1px solid rgba(0,0,0,0.12); flex-shrink:0;
}
.btn-edit   { color:#db2777; border:1px solid #db2777; background:#fff; font-size:13px; padding:5px 12px; border-radius:7px; }
.btn-edit:hover   { background:#fdf2f8; color:#db2777; }
.btn-toggle { color:#6b7280; border:1px solid #d1d5db; background:#fff; font-size:13px; padding:5px 12px; border-radius:7px; }
.btn-toggle:hover { background:#f9fafb; color:#374151; }
.btn-del    { color:#dc2626; border:1px solid #dc2626; background:#fff; font-size:13px; padding:5px 12px; border-radius:7px; }
.btn-del:hover    { background:#fef2f2; color:#dc2626; }
.btn-add-brand { background:#e91e8c; border-color:#e91e8c; color:#fff; border-radius:8px; font-weight:600; font-size:14px; padding:9px 22px; }
.btn-add-brand:hover { background:#c9177a; border-color:#c9177a; color:#fff; }
.page-btn {
    width:32px; height:32px; border:1px solid #e5e7eb;
    background:#fff; border-radius:6px; display:inline-flex;
    align-items:center; justify-content:center; font-size:13px;
    cursor:pointer; color:#374151; text-decoration:none; transition:all .15s;
    line-height:1;
}
.page-btn:hover { border-color:#7c3aed; color:#7c3aed; }
.page-btn.active { border-color:#7c3aed; color:#7c3aed; font-weight:600; }
.page-btn.disabled { opacity:.38; pointer-events:none; }
.per-page-sel {
    border:1px solid #e5e7eb; border-radius:7px;
    padding:5px 10px; font-size:13px; color:#374151; background:#fff; cursor:pointer;
}
</style>

<div class="brands-page">

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
        <div>
            <h1 style="font-size:1.65rem;font-weight:700;color:#111827;margin-bottom:4px;">Brands</h1>
            <p class="mb-0" style="color:#6b7280;font-size:14px;">
                Manage your product brands. Assign brands to products in
                <a href="product_costs.php" style="color:#2563eb;">Product Cost</a>.
            </p>
        </div>
        <button class="btn btn-add-brand" data-bs-toggle="modal" data-bs-target="#addBrandModal">
            + Add Brand
        </button>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($e) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-sm-4 col-lg">
            <div class="stat-card">
                <div class="stat-icon purple"><i class="bi bi-tags"></i></div>
                <div>
                    <div style="font-size:1.65rem;font-weight:700;color:#111827;line-height:1.1;"><?= $totalBrands ?></div>
                    <div style="font-size:13px;color:#6b7280;">Total Brands</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg">
            <div class="stat-card">
                <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div style="font-size:1.65rem;font-weight:700;color:#111827;line-height:1.1;"><?= $activeBrands ?></div>
                    <div style="font-size:13px;color:#6b7280;">Active Brands</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg">
            <div class="stat-card">
                <div class="stat-icon orange"><i class="bi bi-eye-slash"></i></div>
                <div>
                    <div style="font-size:1.65rem;font-weight:700;color:#111827;line-height:1.1;"><?= $inactiveBrands ?></div>
                    <div style="font-size:13px;color:#6b7280;">Inactive Brands</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="bi bi-box-seam"></i></div>
                <div>
                    <div style="font-size:1.65rem;font-weight:700;color:#111827;line-height:1.1;"><?= $totalProducts ?></div>
                    <div style="font-size:13px;color:#6b7280;">Total Products</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-sm-4 col-lg">
            <div class="stat-card">
                <div class="stat-icon pink"><i class="bi bi-tag-fill"></i></div>
                <div>
                    <div style="font-size:1.65rem;font-weight:700;color:#111827;line-height:1.1;"><?= $brandsWithProducts ?></div>
                    <div style="font-size:13px;color:#6b7280;">Brands with Products</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="brands-card">
        <div class="table-responsive">
            <table class="table brands-table mb-0">
                <thead>
                    <tr>
                        <th style="width:52px;">No</th>
                        <th style="width:88px;">Logo</th>
                        <th style="width:170px;">Color</th>
                        <th>Name</th>
                        <th style="min-width:210px;">Products</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$brands): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-bookmark-x" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.25;"></i>
                            No brands yet. Click <strong>+ Add Brand</strong> to create one.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($brands as $i => $b):
                        $cnt        = $countMap[(int)$b['id']] ?? 0;
                        $createdAt  = !empty($b['created_at']) ? date('M j, Y', strtotime($b['created_at'])) : '';
                        $colorHex   = strtoupper($b['color']);
                    ?>
                    <tr>
                        <td style="color:#9ca3af;font-weight:500;"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="brand-logo-box">
                                <?php if (!empty($b['logo_path'])): ?>
                                    <img src="<?= htmlspecialchars(uploaded_file_url($b['logo_path'], 'brands')) ?>"
                                         alt="<?= htmlspecialchars($b['name']) ?>">
                                <?php else: ?>
                                    <i class="bi bi-image" style="font-size:1.4rem;color:#d1d5db;"></i>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span style="font-family:monospace;font-size:13px;color:#374151;"><?= htmlspecialchars($colorHex) ?></span>
                                <span class="color-dot" style="background:<?= htmlspecialchars($b['color']) ?>;"></span>
                            </div>
                        </td>
                        <td>
                            <span class="badge rounded-pill" style="background:<?= htmlspecialchars($b['color']) ?>;font-size:.875rem;padding:6px 16px;font-weight:600;">
                                <?= htmlspecialchars($b['name']) ?>
                            </span>
                            <?php if ($createdAt): ?>
                                <div style="font-size:12px;color:#9ca3af;margin-top:5px;">Created: <?= $createdAt ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="text-muted"><?= $cnt ?> product(s)</span>
                        </td>
                        <td>
                            <?php if ($b['active']): ?>
                                <span class="badge" style="background:#16a34a;font-size:12px;padding:5px 14px;border-radius:20px;font-weight:600;">Active</span>
                            <?php else: ?>
                                <span class="badge" style="background:#6b7280;font-size:12px;padding:5px 14px;border-radius:20px;font-weight:600;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-edit"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editBrandModal<?= (int)$b['id'] ?>">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </button>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button type="submit" class="btn btn-toggle">
                                        <?php if ($b['active']): ?>
                                            <i class="bi bi-eye-slash me-1"></i>Disable
                                        <?php else: ?>
                                            <i class="bi bi-eye me-1"></i>Enable
                                        <?php endif; ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Delete brand \'<?= htmlspecialchars(addslashes($b['name'])) ?>\'? Products assigned to this brand will be unlinked.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button type="submit" class="btn btn-del">
                                        <i class="bi bi-trash me-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <!-- Edit Brand Modal -->
                    <div class="modal fade" id="editBrandModal<?= (int)$b['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
                                <form method="post" enctype="multipart/form-data">
                                    <div class="modal-header border-0 px-4 py-3" style="background:#f9fafb;">
                                        <h5 class="modal-title fw-bold" style="color:#111827;">Edit Brand</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body px-4 py-3 d-flex flex-column gap-3">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <div>
                                            <label class="form-label fw-semibold small">Brand Name</label>
                                            <input type="text" name="name" class="form-control"
                                                   value="<?= htmlspecialchars($b['name']) ?>" required>
                                        </div>
                                        <div>
                                            <label class="form-label fw-semibold small">Color</label>
                                            <div class="d-flex align-items-center gap-3">
                                                <input type="color" name="color" class="form-control form-control-color"
                                                       value="<?= htmlspecialchars($b['color']) ?>" style="width:50px;height:40px;">
                                                <span class="text-muted small">Used as badge color in orders &amp; reports.</span>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label fw-semibold small">Brand Logo</label>
                                            <?php if (!empty($b['logo_path'])): ?>
                                                <div class="mb-2 d-flex align-items-center gap-3">
                                                    <img src="<?= htmlspecialchars(uploaded_file_url($b['logo_path'], 'brands')) ?>"
                                                         alt="Current logo"
                                                         style="width:54px;height:54px;object-fit:contain;border-radius:8px;border:1px solid #e5e7eb;">
                                                    <div>
                                                        <div class="text-muted small mb-1">Current logo</div>
                                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                                                onclick="removeLogo(<?= (int)$b['id'] ?>, this)">Remove logo</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="logo" class="form-control" accept="image/*">
                                            <div class="form-text">JPEG, PNG, GIF, WebP or SVG. Upload to replace current logo.</div>
                                        </div>
                                    </div>
                                    <div class="modal-footer border-0 px-4 py-3">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-sm fw-semibold" style="background:#e91e8c;border-color:#e91e8c;color:#fff;border-radius:7px;padding:6px 18px;">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Footer -->
        <div class="d-flex flex-wrap justify-content-between align-items-center px-4 py-3 border-top" style="background:#fff;gap:12px;">
            <div style="color:#6b7280;font-size:13px;">
                Showing <?= $showingFrom ?> to <?= $showingTo ?> of <?= $totalBrands ?> brands
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= $buildPageUrl($page - 1) ?>"
                   class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&#8249;</a>
                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                if ($start > 1): ?>
                    <a href="<?= $buildPageUrl(1) ?>" class="page-btn">1</a>
                    <?php if ($start > 2): ?><span style="color:#9ca3af;font-size:13px;">…</span><?php endif; ?>
                <?php endif;
                for ($p = $start; $p <= $end; $p++): ?>
                    <a href="<?= $buildPageUrl($p) ?>"
                       class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor;
                if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span style="color:#9ca3af;font-size:13px;">…</span><?php endif; ?>
                    <a href="<?= $buildPageUrl($totalPages) ?>" class="page-btn"><?= $totalPages ?></a>
                <?php endif; ?>
                <a href="<?= $buildPageUrl($page + 1) ?>"
                   class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">&#8250;</a>

                <form method="get" class="ms-1">
                    <select name="per_page" class="per-page-sel" onchange="this.form.submit()">
                        <?php foreach ([5, 10, 20, 50] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $opt === $perPage ? 'selected' : '' ?>><?= $opt ?> / page</option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Brand Modal -->
<div class="modal fade" id="addBrandModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header border-0 px-4 py-3" style="background:#f9fafb;">
                    <h5 class="modal-title fw-bold" style="color:#111827;">Add Brand</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3 d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="form-label fw-semibold small">Brand Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Nike" required>
                    </div>
                    <div>
                        <label class="form-label fw-semibold small">Color</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" name="color" class="form-control form-control-color"
                                   value="#0d6efd" style="width:50px;height:40px;">
                            <span class="text-muted small">Used as badge color in orders &amp; reports.</span>
                        </div>
                    </div>
                    <div>
                        <label class="form-label fw-semibold small">Brand Logo <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">JPEG, PNG, GIF, WebP or SVG. Max recommended: 512 KB.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm fw-semibold" style="background:#e91e8c;border-color:#e91e8c;color:#fff;border-radius:7px;padding:6px 18px;">Save Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden remove-logo form -->
<form id="removeLogoForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="remove_logo">
    <input type="hidden" name="id" id="removeLogoId">
</form>

<script>
function removeLogo(brandId, btn) {
    if (!confirm('Remove the logo for this brand?')) return;
    document.getElementById('removeLogoId').value = brandId;
    document.getElementById('removeLogoForm').submit();
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
