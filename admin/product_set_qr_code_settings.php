<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'product_set_qr_code_settings.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();
$user = current_user();
$isAdminUser = (($user['role'] ?? '') === 'admin');
$canCreateQrSetting = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_code_settings.create'));
$canUpdateQrSetting = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_code_settings.update'));
$canViewQrLabels = $isAdminUser || (function_exists('has_permission') && has_permission('product_set_qr_labels.view'));

$success = '';
$errors = [];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS product_set_qr_code_settings (
        product_set_id INT PRIMARY KEY,
        code_prefix VARCHAR(80) NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_code_prefix (code_prefix)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
try {
    $hasCreatedBy = (bool)$pdo->query("SHOW COLUMNS FROM product_set_qr_code_settings LIKE 'created_by'")->fetchColumn();
    if (!$hasCreatedBy) {
        $pdo->exec("ALTER TABLE product_set_qr_code_settings ADD COLUMN created_by INT NULL AFTER code_prefix");
    }
    $hasCreatedAt = (bool)$pdo->query("SHOW COLUMNS FROM product_set_qr_code_settings LIKE 'created_at'")->fetchColumn();
    if (!$hasCreatedAt) {
        $pdo->exec("ALTER TABLE product_set_qr_code_settings ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by");
        $pdo->exec("UPDATE product_set_qr_code_settings SET created_at = updated_at WHERE created_at IS NULL");
    }
} catch (Throwable $e) {
    // Keep the page usable if audit columns are already present or cannot be changed.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setId = (int)($_POST['product_set_id'] ?? 0);
    $codePrefix = strtoupper(trim((string)($_POST['code_prefix'] ?? '')));
    $codePrefix = preg_replace('/[^A-Z0-9-]/', '', $codePrefix);

    if ($setId <= 0) {
        $errors[] = 'Product set is required.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_sets WHERE id = ? AND is_active = 1');
        $stmt->execute([$setId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = 'Product set not found.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_set_qr_code_settings WHERE product_set_id = ?');
            $stmt->execute([$setId]);
            $settingExists = ((int)$stmt->fetchColumn() > 0);

            if ($settingExists && !$canUpdateQrSetting) {
                $errors[] = 'No edit permission (product_set_qr_code_settings.update).';
            } elseif (!$settingExists && $codePrefix !== '' && !$canCreateQrSetting) {
                $errors[] = 'No create permission (product_set_qr_code_settings.create).';
            } elseif ($codePrefix === '') {
                if ($settingExists) {
                    $stmt = $pdo->prepare('DELETE FROM product_set_qr_code_settings WHERE product_set_id = ?');
                    $stmt->execute([$setId]);
                }
                $success = 'Custom code removed. This set will use the default SKU code.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO product_set_qr_code_settings (product_set_id, code_prefix, created_by, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        code_prefix = VALUES(code_prefix),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$setId, $codePrefix, $_SESSION['user_id'] ?? null, $_SESSION['user_id'] ?? null]);
                $success = 'Custom QR code saved.';
            }
        }
    }
}

$stmt = $pdo->query("
    SELECT
        ps.id,
        ps.set_name,
        CONCAT('SET-', ps.id) AS default_code,
        ps.available_stock,
        qcs.code_prefix,
        qcs.created_at,
        qcs.updated_at,
        creator.name AS created_by_name,
        updater.name AS updated_by_name
    FROM product_sets ps
    LEFT JOIN product_set_qr_code_settings qcs ON qcs.product_set_id = ps.id
    LEFT JOIN users creator ON creator.id = qcs.created_by
    LEFT JOIN users updater ON updater.id = qcs.updated_by
    WHERE ps.is_active = 1
    ORDER BY ps.set_name
");
$sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalRows = count($sets);
$customRows = count(array_filter($sets, static fn($set) => trim((string)($set['code_prefix'] ?? '')) !== ''));
$defaultRows = $totalRows - $customRows;
$totalStock = array_sum(array_map(static fn($set) => (float)($set['available_stock'] ?? 0), $sets));

require_once __DIR__ . '/../layout/header.php';
?>

<style>
    :root {
        --qr-page-bg: #f4f6f8;
        --qr-card: #ffffff;
        --qr-border: #e8edf2;
        --qr-title: #1f2a37;
        --qr-muted: #6b7280;
    }
    .qr-settings-page {
        background: var(--qr-page-bg);
    }
    .qr-settings-page .report-title {
        color: var(--qr-title);
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .qr-settings-page .report-subtitle {
        color: var(--qr-muted);
        font-size: 0.92rem;
    }
    .qr-settings-page .report-card {
        background: var(--qr-card);
        border: 1px solid var(--qr-border);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 6px 20px rgba(30, 41, 59, 0.06);
    }
    .qr-settings-page .report-card-header {
        background: linear-gradient(180deg, #f9fafb 0%, #f4f6f9 100%);
        border-bottom: 1px solid var(--qr-border);
    }
    .qr-settings-page .metric-card {
        border: none;
        border-radius: 14px;
        color: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.16);
    }
    .qr-settings-page .metric-primary { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
    .qr-settings-page .metric-success { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); }
    .qr-settings-page .metric-info { background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%); }
    .qr-settings-page .metric-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .qr-settings-page .metric-label {
        font-size: 0.9rem;
        opacity: 0.92;
        margin-bottom: 0.35rem;
    }
    .qr-settings-page .metric-value {
        margin: 0;
        font-weight: 700;
    }
    .qr-settings-page .chart-meta {
        color: #64748b;
        font-size: 0.82rem;
    }
    .qr-settings-page .table-clean thead th {
        background: #2f855a;
        color: #f4fff8;
        border-bottom: 0;
        border-color: transparent;
        font-weight: 600;
        white-space: nowrap;
        font-size: 1.02rem;
    }
    .qr-settings-page .table-clean tbody td {
        border-color: #edf2f7;
        font-size: 0.96rem;
        vertical-align: middle;
        color: #000;
    }
    .qr-settings-page .table-clean tbody tr:hover {
        background: #fdecc8 !important;
    }
    .qr-settings-page .table-clean .fw-semibold {
        color: #000;
    }
    .qr-settings-page .btn-soft {
        border-radius: 10px;
        font-weight: 600;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
    }
</style>

<div class="d-flex flex-column min-vh-100">
    <div class="container-fluid py-4 flex-grow-1 qr-settings-page">
        <div class="report-topbar d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h2 class="h4 mb-1 report-title"><i class="bi bi-pencil-square me-2"></i>Set QR Custom Code</h2>
                <div class="report-subtitle">Set a custom QR code prefix for product set labels</div>
            </div>
            <div class="d-flex gap-2 report-actions">
            <?php if ($canViewQrLabels): ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php" class="btn btn-outline-primary btn-soft">
                <i class="bi bi-qr-code me-1"></i>QR Labels
            </a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_management.php" class="btn btn-outline-secondary btn-soft">
                <i class="bi bi-arrow-left me-1"></i>Product Sets
            </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center metric-card metric-primary">
                    <div class="card-body">
                        <i class="bi bi-collection fs-3 mb-2"></i>
                        <div class="metric-label">Product Sets</div>
                        <h3 class="metric-value"><?= number_format($totalRows) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center metric-card metric-success">
                    <div class="card-body">
                        <i class="bi bi-pencil-square fs-3 mb-2"></i>
                        <div class="metric-label">Custom Codes</div>
                        <h3 class="metric-value"><?= number_format($customRows) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center metric-card metric-info">
                    <div class="card-body">
                        <i class="bi bi-upc-scan fs-3 mb-2"></i>
                        <div class="metric-label">Default Codes</div>
                        <h3 class="metric-value"><?= number_format($defaultRows) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center metric-card metric-warning">
                    <div class="card-body">
                        <i class="bi bi-boxes fs-3 mb-2"></i>
                        <div class="metric-label">Available Sets</div>
                        <h3 class="metric-value"><?= number_format($totalStock, 0) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="card report-card">
            <div class="card-header report-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-table me-2 text-primary"></i>Set QR Custom Codes</h5>
                <small class="chart-meta">Rows: <?= number_format($totalRows) ?></small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle table-clean">
                        <thead>
                    <tr>
                        <th style="width: 60px;">No.</th>
                        <th>Product Set</th>
                        <th>Default Code</th>
                        <th>Custom Code</th>
                        <th class="text-end">Stock Qty</th>
                        <th>Created By</th>
                        <th>Created At</th>
                        <th>Updated By</th>
                        <th>Updated At</th>
                        <th style="width: 120px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sets)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No active product sets found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sets as $index => $set): ?>
                            <?php
                                $hasCustomCode = trim((string)($set['code_prefix'] ?? '')) !== '';
                                $canSaveQrCode = $hasCustomCode ? $canUpdateQrSetting : $canCreateQrSetting;
                                $saveTitle = $hasCustomCode
                                    ? ($canSaveQrCode ? 'Edit custom QR code' : 'No edit permission')
                                    : ($canSaveQrCode ? 'Create custom QR code' : 'No create permission');
                                $saveIcon = $hasCustomCode ? 'bi-pencil-square' : 'bi-plus-circle';
                                $saveText = $hasCustomCode ? 'Edit' : 'Create';
                                $saveClass = $hasCustomCode ? 'btn-outline-secondary' : 'btn-outline-primary';
                            ?>
                            <tr>
                                <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars((string)$set['set_name']) ?></td>
                                <td><code><?= htmlspecialchars((string)$set['default_code']) ?></code></td>
                                <td>
                                    <?php if ($hasCustomCode): ?>
                                        <code><?= htmlspecialchars((string)$set['code_prefix']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format((float)$set['available_stock'], 0) ?></td>
                                <td>
                                    <?= htmlspecialchars((string)($set['created_by_name'] ?: 'System')) ?>
                                </td>
                                <td>
                                    <?= !empty($set['created_at']) ? htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)$set['created_at']))) : '-' ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($set['updated_by_name'] ?: 'System')) ?>
                                </td>
                                <td>
                                    <?php if (!empty($set['updated_at'])): ?>
                                        <?= htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)$set['updated_at']))) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <button type="button"
                                                class="btn btn-sm <?= $canSaveQrCode ? $saveClass : 'btn-outline-secondary' ?> w-100"
                                                title="<?= htmlspecialchars($saveTitle) ?>"
                                                data-set-id="<?= (int)$set['id'] ?>"
                                                data-set-name="<?= htmlspecialchars((string)$set['set_name'], ENT_QUOTES) ?>"
                                                data-default-code="<?= htmlspecialchars((string)$set['default_code'], ENT_QUOTES) ?>"
                                                data-code-prefix="<?= htmlspecialchars((string)($set['code_prefix'] ?? ''), ENT_QUOTES) ?>"
                                                data-mode="<?= htmlspecialchars($saveText, ENT_QUOTES) ?>"
                                                <?= $canSaveQrCode ? '' : 'disabled' ?>>
                                            <?php if ($canSaveQrCode): ?>
                                                <i class="bi <?= htmlspecialchars($saveIcon) ?> me-1"></i><?= htmlspecialchars($saveText) ?>
                                            <?php else: ?>
                                                <i class="bi bi-lock me-1"></i><?= htmlspecialchars($saveText) ?>
                                            <?php endif; ?>
                                        </button>
                                        <?php if ($canViewQrLabels): ?>
                                        <a class="btn btn-sm btn-outline-primary w-100" href="<?= htmlspecialchars($BASE_URL) ?>/admin/product_set_qr_labels.php?<?= http_build_query(['set_id' => (int)$set['id'], 'batch' => date('Y')]) ?>">
                                            <i class="bi bi-qr-code me-1"></i>Labels
                                        </a>
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
    </div>
</div>

<div class="modal fade" id="qrCodeSettingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="qrCodeSettingModalForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2"></i><span id="qrCodeSettingModalTitle">Custom QR Code</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="card border-0 bg-light">
                        <div class="card-body">
                            <input type="hidden" name="product_set_id" id="qrCodeProductSetId">

                            <div class="mb-2 small text-muted">Product Set</div>
                            <div class="fw-semibold mb-3" id="qrCodeProductSetName">-</div>

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted">Default Code:</span>
                                <code id="qrCodeDefaultCode">-</code>
                            </div>

                            <label for="qrCodePrefixInput" class="form-label">Custom Code</label>
                            <input type="text"
                                   name="code_prefix"
                                   id="qrCodePrefixInput"
                                   class="form-control text-uppercase"
                                   maxlength="80"
                                   autocomplete="off"
                                   placeholder="Example: SET-13">
                            <div class="form-text">Use letters, numbers, and dash only. Leave empty to use default.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('qrCodeSettingModal');
    const setIdEl = document.getElementById('qrCodeProductSetId');
    const setNameEl = document.getElementById('qrCodeProductSetName');
    const defaultCodeEl = document.getElementById('qrCodeDefaultCode');
    const inputEl = document.getElementById('qrCodePrefixInput');
    const titleEl = document.getElementById('qrCodeSettingModalTitle');
    if (!modalEl || !setIdEl || !setNameEl || !defaultCodeEl || !inputEl || !titleEl) return;

    document.querySelectorAll('[data-set-id][data-default-code]').forEach(function(button) {
        button.addEventListener('click', function() {
            setIdEl.value = button.dataset.setId || '';
            setNameEl.textContent = button.dataset.setName || '-';
            defaultCodeEl.textContent = button.dataset.defaultCode || '-';
            inputEl.placeholder = button.dataset.defaultCode || 'Example: SET-13';
            inputEl.value = button.dataset.codePrefix || '';
            titleEl.textContent = (button.dataset.mode || 'Create') + ' Custom QR Code';

            new bootstrap.Modal(modalEl).show();
            setTimeout(function() {
                inputEl.focus();
                inputEl.select();
            }, 180);
        });
    });

    inputEl.addEventListener('input', function() {
        inputEl.value = inputEl.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
