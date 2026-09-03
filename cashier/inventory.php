<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['cashier', 'admin'], 'inventory.view');
require_once __DIR__ . '/../admin/inventory.php';