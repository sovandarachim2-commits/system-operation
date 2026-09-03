<?php
declare(strict_types=1);

require_once __DIR__ . '/purchase_common.php';

$pdo = get_db_connection();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = purchase_api_str($_GET['action'] ?? ($method === 'POST' ? '' : 'list'));

try {
    if ($method === 'GET' && ($action === 'list' || $action === '')) {
        require_role_or_permission(['admin'], 'sr_purchase_suppliers.view', 'purchase_vendors.view');
        $q = purchase_api_str($_GET['q'] ?? '');
        $sql = '
            SELECT
                v.*,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.vendor_id = v.id) AS order_count
            FROM purchase_vendors v
            WHERE 1=1
        ';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (v.name LIKE ? OR v.contact_person LIKE ? OR v.phone LIKE ? OR v.email LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like];
        }
        $sql .= ' ORDER BY v.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        api_json(['success' => true, 'suppliers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method !== 'POST') {
        api_error('Unsupported method.', 405);
    }

    $input = purchase_api_input();
    $action = purchase_api_str($input['action'] ?? $action);

    if ($action === 'create') {
        require_role_or_permission(['admin'], 'sr_purchase_suppliers.create', 'purchase_vendors.create');
        $name = purchase_api_str($input['name'] ?? '');
        $contact = purchase_api_str($input['contact_person'] ?? '');
        $phone = purchase_api_str($input['phone'] ?? '');
        if ($name === '' || $contact === '' || $phone === '') {
            api_error('Name, contact person, and phone are required.');
        }
        $stmt = $pdo->prepare('
            INSERT INTO purchase_vendors
            (name, contact_person, phone, email, address, payment_terms, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $name,
            $contact,
            $phone,
            purchase_api_str($input['email'] ?? ''),
            purchase_api_str($input['address'] ?? ''),
            purchase_api_str($input['payment_terms'] ?? ''),
            purchase_api_str($input['notes'] ?? ''),
            purchase_api_user_id(),
        ]);
        api_json(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Supplier created.']);
    }

    if ($action === 'update') {
        require_role_or_permission(['admin'], 'sr_purchase_suppliers.update', 'purchase_vendors.update');
        $id = (int)($input['id'] ?? 0);
        $name = purchase_api_str($input['name'] ?? '');
        if ($id <= 0 || $name === '') {
            api_error('Supplier id and name are required.');
        }
        $stmt = $pdo->prepare('
            UPDATE purchase_vendors
            SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, payment_terms = ?, notes = ?, is_active = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $name,
            purchase_api_str($input['contact_person'] ?? ''),
            purchase_api_str($input['phone'] ?? ''),
            purchase_api_str($input['email'] ?? ''),
            purchase_api_str($input['address'] ?? ''),
            purchase_api_str($input['payment_terms'] ?? ''),
            purchase_api_str($input['notes'] ?? ''),
            !empty($input['is_active']) ? 1 : 0,
            $id,
        ]);
        api_json(['success' => true, 'message' => 'Supplier updated.']);
    }

    if ($action === 'delete') {
        require_role_or_permission(['admin'], 'sr_purchase_suppliers.delete', 'purchase_vendors.delete');
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            api_error('Supplier id is required.');
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE vendor_id = ?');
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            api_error('Cannot delete supplier with existing purchase orders.');
        }
        $pdo->prepare('DELETE FROM purchase_vendors WHERE id = ?')->execute([$id]);
        api_json(['success' => true, 'message' => 'Supplier deleted.']);
    }

    api_error('Unknown action.');
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
