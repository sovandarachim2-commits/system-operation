<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'logos.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();

// Ensure table columns match React report
try {
    $logoColsResult = $pdo->query("SHOW COLUMNS FROM logos");
    $logoCols = $logoColsResult->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('type', $logoCols, true)) {
        $pdo->exec("ALTER TABLE logos ADD COLUMN type VARCHAR(100) DEFAULT 'Receipt Header' AFTER name");
    }
    if (!in_array('width', $logoCols, true)) {
        $pdo->exec("ALTER TABLE logos ADD COLUMN width VARCHAR(20) DEFAULT '240px' AFTER file_path");
    }
    if (!in_array('height', $logoCols, true)) {
        $pdo->exec("ALTER TABLE logos ADD COLUMN height VARCHAR(20) DEFAULT '80px' AFTER width");
    }
    if (!in_array('status', $logoCols, true)) {
        $pdo->exec("ALTER TABLE logos ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
    }
} catch (PDOException $e) {
    // Table might not exist or other DB error
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? 'Receipt Header');
        $width = trim($_POST['width'] ?? '240px');
        $height = trim($_POST['height'] ?? '80px');
        $status = $_POST['status'] ?? 'active';

        if ($name === '') {
            $errors[] = 'Logo name is required.';
        } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo file is required.';
        } else {
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $errors[] = 'Only PNG and JPG images are allowed.';
            } else {
                try {
                    $filename = uniqid('logo_', true) . '.' . $ext;
                    $path = upload_store_uploaded_file($file, 'logos', $filename, null, (string)($file['type'] ?? ''));
                    if ($path !== '') {
                        $stmt = $pdo->prepare('INSERT INTO logos (name, type, file_path, width, height, status) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$name, $type, $path, $width, $height, $status]);
                        $success = 'Logo uploaded.';
                    } else {
                        $errors[] = 'Failed to move uploaded file.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Failed to upload logo.';
                }
            }
        }
    } elseif ($action === 'set_default') {
        require_role_or_permission(['admin'], 'logos.update');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->exec('UPDATE logos SET is_default = 0');
            $stmt = $pdo->prepare('UPDATE logos SET is_default = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Default logo updated.';
        }
    } elseif ($action === 'delete') {
        require_role_or_permission(['admin'], 'logos.delete');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT file_path FROM logos WHERE id = ?');
            $stmt->execute([$id]);
            $logo = $stmt->fetch();
            if ($logo) {
                upload_delete_local_file($logo['file_path'] ?? null, 'logos');
            }
            $stmt = $pdo->prepare('DELETE FROM logos WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Logo deleted.';
        }
    }
}

$stmt = $pdo->query('SELECT * FROM logos ORDER BY id DESC');
$logos = $stmt->fetchAll();

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Logos</h1>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#uploadLogoModal">+ Upload Logo</button>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <div class="row g-3">
        <?php if (!$logos): ?>
            <div class="col-12"><div class="card shadow-sm p-4 text-center">No logos uploaded yet.</div></div>
        <?php else: ?>
            <?php foreach ($logos as $logo): ?>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="card shadow-sm h-100">
                    <div class="card-img-top text-center p-3" style="background:#fff;">
                        <img src="<?= htmlspecialchars(uploaded_file_url($logo['file_path'], 'logos')) ?>" alt="Logo" class="img-fluid" style="max-height:120px; object-fit:contain;">
                    </div>
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title mb-1"><?= htmlspecialchars($logo['name']) ?></h5>
                        <p class="small mb-1">Type: <span class="badge bg-info text-dark"><?= htmlspecialchars($logo['type'] ?? 'N/A') ?></span></p>
                        <p class="small mb-2">Size: <?= htmlspecialchars($logo['width'] ?? '240px') ?> x <?= htmlspecialchars($logo['height'] ?? '80px') ?></p>
                        <div class="mb-2">
                            <span class="badge bg-<?= ($logo['status'] ?? 'active') === 'active' ? 'success' : 'secondary' ?>">
                                <?= ucfirst($logo['status'] ?? 'active') ?>
                            </span>
                            <?php if ($logo['is_default']): ?>
                                <span class="badge bg-primary ms-1">Default</span>
                            <?php endif; ?>
                        </div>
                        <div class="mt-auto d-flex flex-wrap gap-2">
                            <?php if (!$logo['is_default']): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="set_default">
                                <input type="hidden" name="id" value="<?= (int)$logo['id'] ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm">Set Default</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('Delete this logo?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$logo['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Upload Logo Modal -->
<div class="modal fade" id="uploadLogoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Logo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <input type="hidden" name="action" value="upload">
                    <div>
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control form-control-lg" required>
                    </div>
                    <div>
                        <label class="form-label">Logo Type / Category</label>
                        <select name="type" class="form-select form-select-lg">
                            <option value="Receipt Header">Receipt Header</option>
                            <option value="Invoice Footer">Invoice Footer</option>
                            <option value="Storefront Branding">Storefront Branding</option>
                            <option value="Report Header">Report Header</option>
                            <option value="Portal Branding">Portal Branding</option>
                            <option value="Thermal Receipt">Thermal Receipt</option>
                            <option value="Invoice Header">Invoice Header</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Image (PNG/JPG)</label>
                        <input type="file" name="file" class="form-control form-control-lg" accept="image/png,image/jpeg" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Display Width</label>
                            <input type="text" name="width" class="form-control form-control-lg" value="240px">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Display Height</label>
                            <input type="text" name="height" class="form-control form-control-lg" value="80px">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-lg">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
