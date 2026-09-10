<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../helpers.php';

function invoice_settings_require_access(string $permission): void
{
    $user = current_user();
    if (!$user) {
        api_error('Please log in again.', 401);
    }

    $pdo = get_db_connection();
    $roles = user_role_names($pdo, $user);
    if (in_array('admin', $roles, true) || has_permission($permission)) {
        return;
    }

    api_error('You do not have permission to manage invoice settings.', 403);
}

function invoice_settings_json_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function invoice_settings_response(PDO $pdo): array
{
    $settings = get_invoice_settings($pdo);
    $logos = $pdo->query('SELECT id AS value, name AS label, file_path FROM logos ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logos as &$logo) {
        $logo['logo_url'] = uploaded_file_url($logo['file_path'] ?? '', 'logos');
        unset($logo['file_path']);
    }
    unset($logo);

    $invoiceLogo = get_invoice_logo($pdo);

    return [
        'settings' => $settings,
        'logos' => $logos,
        'logo_url' => uploaded_file_url($invoiceLogo['file_path'] ?? '', 'logos'),
    ];
}

try {
    $pdo = get_db_connection();
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'get'));
    invoice_settings_require_access($action === 'save' ? 'logos.update' : 'logos.view');

    if ($action === 'save') {
        $payload = invoice_settings_json_payload();
        $companyName = trim((string)($payload['company_name'] ?? ''));
        if ($companyName === '') {
            api_error('Company name is required.', 422);
        }

        $settings = get_invoice_settings($pdo);
        $values = [
            $companyName,
            trim((string)($payload['company_address'] ?? '')),
            trim((string)($payload['company_phone'] ?? '')),
            trim((string)($payload['company_email'] ?? '')),
            trim((string)($payload['contact_person'] ?? '')),
            trim((string)($payload['payment_url'] ?? '')),
            $payload['logo_id'] === null || ($payload['logo_id'] ?? '') === '' ? null : (int)$payload['logo_id'],
            max(40, min(200, (int)($payload['logo_width'] ?? 80))),
            max(40, min(200, (int)($payload['logo_height'] ?? 70))),
        ];

        if (!empty($settings['id'])) {
            $stmt = $pdo->prepare('UPDATE invoice_settings SET company_name = ?, company_address = ?, company_phone = ?, company_email = ?, contact_person = ?, payment_url = ?, logo_id = ?, logo_width = ?, logo_height = ? WHERE id = ?');
            $stmt->execute([...$values, (int)$settings['id']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO invoice_settings (company_name, company_address, company_phone, company_email, contact_person, payment_url, logo_id, logo_width, logo_height) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute($values);
        }

        api_json([
            'success' => true,
            'message' => 'Invoice settings saved.',
            ...invoice_settings_response($pdo),
        ]);
    }

    api_json(invoice_settings_response($pdo));
} catch (Throwable $e) {
    error_log('Invoice settings API error: ' . $e->getMessage());
    $message = $action === 'save' ? 'Unable to save invoice settings.' : 'Unable to load invoice settings.';
    api_error($message, 500);
}