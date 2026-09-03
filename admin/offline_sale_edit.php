<?php
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    header('Location: offline_sale_new.php?id=' . $id);
    exit;
}
header('Location: offline_sale_orders.php');
exit;
