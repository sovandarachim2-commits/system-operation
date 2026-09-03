<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'logos.view');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$pdo = get_db_connection();
$errors = [];
$success = '';

$settings = get_invoice_settings($pdo);
$logos = $pdo->query('SELECT id, name, file_path FROM logos ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'logos.update');
    $companyName = trim($_POST['company_name'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $companyPhone = trim($_POST['company_phone'] ?? '');
    $companyEmail = trim($_POST['company_email'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $paymentUrl = trim($_POST['payment_url'] ?? '');
    $logoId = isset($_POST['logo_id']) && $_POST['logo_id'] !== '' ? (int) $_POST['logo_id'] : null;
    $logoWidth = max(40, min(200, (int)($_POST['logo_width'] ?? 80)));
    $logoHeight = max(40, min(200, (int)($_POST['logo_height'] ?? 70)));

    if ($companyName === '') {
        $errors[] = 'Company name is required.';
    }
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO invoice_settings (company_name, company_address, company_phone, company_email, contact_person, payment_url, logo_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    company_name = VALUES(company_name),
                    company_address = VALUES(company_address),
                    company_phone = VALUES(company_phone),
                    company_email = VALUES(company_email),
                    contact_person = VALUES(contact_person),
                    payment_url = VALUES(payment_url),
                    logo_id = VALUES(logo_id)
            ');
            // invoice_settings has single row - use INSERT ... ON DUPLICATE
            $stmt2 = $pdo->query('SELECT id FROM invoice_settings LIMIT 1');
            $row = $stmt2->fetch();
            if ($row) {
                $stmt = $pdo->prepare('
                    UPDATE invoice_settings SET
                        company_name = ?, company_address = ?, company_phone = ?,
                        company_email = ?, contact_person = ?, payment_url = ?, logo_id = ?,
                        logo_width = ?, logo_height = ?
                    WHERE id = ?
                ');
                $stmt->execute([$companyName, $companyAddress, $companyPhone, $companyEmail, $contactPerson, $paymentUrl, $logoId, $logoWidth, $logoHeight, $row['id']]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO invoice_settings (company_name, company_address, company_phone, company_email, contact_person, payment_url, logo_id, logo_width, logo_height)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$companyName, $companyAddress, $companyPhone, $companyEmail, $contactPerson, $paymentUrl, $logoId, $logoWidth, $logoHeight]);
            }
            $success = 'Invoice settings saved.';
            $settings = get_invoice_settings($pdo);
        } catch (PDOException $e) {
            $errors[] = 'Failed to save: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>
<div class="d-flex flex-column h-100">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Invoice Settings</h1>
    </div>
    <p class="text-muted mb-3">Customize company details and payment URL for order invoices and payment invoices. Logo and company name appear on invoices; payment URL is used for the QR code.</p>

    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" action="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" required placeholder="e.g. R&R PLUMBING">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($settings['contact_person'] ?? '') ?>" placeholder="e.g. John Wilson">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Company Address</label>
                        <textarea name="company_address" class="form-control" rows="2" placeholder="Street, City, State"><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="company_phone" class="form-control" value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>" placeholder="+1-555-123-4567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="company_email" class="form-control" value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>" placeholder="billing@company.com">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Payment URL (for QR code scan)</label>
                        <input type="url" name="payment_url" class="form-control" value="<?= htmlspecialchars($settings['payment_url'] ?? '') ?>" placeholder="https://your-domain.com/pay/...">
                        <small class="text-muted">Customers can scan the QR code to pay online. Leave blank to hide QR on invoice.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Invoice Logo</label>
                        <select name="logo_id" class="form-select">
                            <option value="">Default logo</option>
                            <?php foreach ($logos as $l): ?>
                                <option value="<?= (int) $l['id'] ?>" <?= (isset($settings['logo_id']) && (int) $settings['logo_id'] === (int) $l['id']) ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Use default logo or select one from <a href="<?= htmlspecialchars($BASE_URL) ?>/admin/logos.php">Logos</a>.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Logo Width (px)</label>
                        <input type="number" name="logo_width" class="form-control" value="<?= (int)($settings['logo_width'] ?? 80) ?>" min="40" max="200" placeholder="80">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Logo Height (px)</label>
                        <input type="number" name="logo_height" class="form-control" value="<?= (int)($settings['logo_height'] ?? 70) ?>" min="40" max="200" placeholder="70">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>
