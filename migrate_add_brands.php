<?php
/**
 * One-time migration: create brands table + add brand_id to products.
 * Run once via browser: http://localhost/LuckyBox/migrate_add_brands.php
 * Delete this file after running.
 */
require_once __DIR__ . '/db.php';

$pdo = get_db_connection();
$steps = [];
$errors = [];

// 1. Create brands table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS brands (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            color      VARCHAR(20)  NOT NULL DEFAULT '#6c757d',
            active     TINYINT      NOT NULL DEFAULT 1,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $steps[] = '✅ brands table created (or already exists).';
} catch (Throwable $e) {
    $errors[] = '❌ brands table: ' . $e->getMessage();
}

// 2. Add brand_id column to products (safe — skips if already exists)
try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'brand_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN brand_id INT NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE products ADD CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL");
        $steps[] = '✅ brand_id column added to products table.';
    } else {
        $steps[] = '⚠️ brand_id already exists on products table — skipped.';
    }
} catch (Throwable $e) {
    $errors[] = '❌ brand_id column: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Migrate: Add Brands</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <h2 class="mb-4">Migration: Add Brands</h2>
    <?php foreach ($steps as $s): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($s) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php if (!$errors): ?>
        <div class="alert alert-info mt-3">
            Migration complete. <strong>Delete this file</strong> after confirming everything works.<br>
            <a href="/LuckyBox/admin/brands.php" class="btn btn-primary mt-2">Go to Brands Management →</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
