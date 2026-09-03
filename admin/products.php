<!--<?php-->
<!--require_once __DIR__ . '/../auth.php';-->
require_role_or_permission(['admin'], 'inventory.view');

<!--$pdo = get_db_connection();-->

<!--$errors = [];-->
<!--$success = '';-->

<!--if ($_SERVER['REQUEST_METHOD'] === 'POST') {-->
<!--    $action = $_POST['action'] ?? '';-->

<!--    if ($action === 'create') {-->
<!--        $name = trim($_POST['name'] ?? '');-->
<!--        $cost = trim($_POST['cost'] ?? '0');-->

<!--        if ($name === '') {-->
<!--            $errors[] = 'Product name is required.';-->
<!--        } else {-->
<!--            $stmt = $pdo->prepare('INSERT INTO products (name, cost) VALUES (?, ?)');-->
<!--            $stmt->execute([$name, $cost]);-->
<!--            $success = 'Product added.';-->
<!--        }-->
<!--    } elseif ($action === 'update') {-->
<!--        $id   = (int)($_POST['id'] ?? 0);-->
<!--        $name = trim($_POST['name'] ?? '');-->
<!--        $cost = trim($_POST['cost'] ?? '0');-->

<!--        if ($id > 0 && $name !== '') {-->
<!--            $stmt = $pdo->prepare('UPDATE products SET name = ?, cost = ? WHERE id = ?');-->
<!--            $stmt->execute([$name, $cost, $id]);-->
<!--            $success = 'Product updated.';-->
<!--        }-->
<!--    } elseif ($action === 'delete') {-->
<!--        $id = (int)($_POST['id'] ?? 0);-->
<!--        if ($id > 0) {-->
<!--            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');-->
<!--            $stmt->execute([$id]);-->
<!--            $success = 'Product deleted.';-->
<!--        }-->
<!--    }-->
<!--}-->

<!--$search = trim($_GET['search'] ?? '');-->
<!--$params = [];-->
<!--$sql = 'SELECT * FROM products WHERE 1=1';-->
<!--if ($search !== '') {-->
<!--    $sql .= ' AND name LIKE ?';-->
<!--    $params[] = "%{$search}%";-->
<!--}-->
<!--$sql .= ' ORDER BY id DESC';-->
<!--$stmt = $pdo->prepare($sql);-->
<!--$stmt->execute($params);-->
<!--$products = $stmt->fetchAll();-->

<!--include __DIR__ . '/../layout/header.php';-->
<!--?>-->
<!--<div class="d-flex flex-column h-100">-->
<!--    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">-->
<!--        <h1 class="h4 mb-0">Products</h1>-->
<!--        <div class="d-flex flex-wrap gap-2">-->
<!--            <form class="d-flex" method="get">-->
<!--                <input type="text" name="search" class="form-control form-control-lg me-2" placeholder="Search products" value="<?= htmlspecialchars($search) ?>">-->
<!--                <button class="btn btn-outline-primary btn-lg" type="submit">Search</button>-->
<!--            </form>-->
<!--            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addProductModal">+ Add Product</button>-->
<!--        </div>-->
<!--    </div>-->

<!--    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>-->
<!--    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>-->

<!--    <div class="card shadow-sm flex-grow-1 d-flex flex-column">-->
<!--        <div class="card-body d-flex flex-column p-0">-->
<!--            <div class="table-responsive table-responsive-full">-->
<!--                <table class="table table-hover align-middle mb-0">-->
<!--                    <thead class="table-light">-->
<!--                        <tr>-->
<!--                            <th>ID</th>-->
<!--                            <th>Name</th>-->
<!--                            <th>Cost</th>-->
<!--                            <th>Actions</th>-->
<!--                        </tr>-->
<!--                    </thead>-->
<!--                    <tbody>-->
<!--                    <?php if (!$products): ?>-->
<!--                        <tr><td colspan="4" class="text-center py-4">No products found.</td></tr>-->
<!--                    <?php else: ?>-->
<!--                        <?php foreach ($products as $p): ?>-->
<!--                        <tr>-->
<!--                            <td><?= (int)$p['id'] ?></td>-->
<!--                            <td><?= htmlspecialchars($p['name']) ?></td>-->
<!--                            <td>$<?= number_format((float)$p['cost'], 2) ?></td>-->
<!--                            <td>-->
<!--                                <div class="d-flex flex-wrap gap-2">-->
<!--                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editProductModal<?= (int)$p['id'] ?>">Edit</button>-->
<!--                                    <form method="post" onsubmit="return confirm('Delete this product?');">-->
<!--                                        <input type="hidden" name="action" value="delete">-->
<!--                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">-->
<!--                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>-->
<!--                                    </form>-->
<!--                                </div>-->
<!--                            </td>-->
<!--                        </tr>-->

                        <!-- Edit Product Modal -->
<!--                        <div class="modal fade" id="editProductModal<?= (int)$p['id'] ?>" tabindex="-1">-->
<!--                            <div class="modal-dialog modal-dialog-centered">-->
<!--                                <div class="modal-content">-->
<!--                                    <form method="post">-->
<!--                                        <div class="modal-header">-->
<!--                                            <h5 class="modal-title">Edit Product</h5>-->
<!--                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>-->
<!--                                        </div>-->
<!--                                        <div class="modal-body d-flex flex-column gap-3">-->
<!--                                            <input type="hidden" name="action" value="update">-->
<!--                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">-->
<!--                                            <div>-->
<!--                                                <label class="form-label">Name</label>-->
<!--                                                <input type="text" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($p['name']) ?>" required>-->
<!--                                            </div>-->
<!--                                            <div>-->
<!--                                                <label class="form-label">Cost</label>-->
<!--                                                <input type="number" step="0.01" name="cost" class="form-control form-control-lg" value="<?= htmlspecialchars($p['cost']) ?>" required>-->
<!--                                            </div>-->
<!--                                        </div>-->
<!--                                        <div class="modal-footer">-->
<!--                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>-->
<!--                                            <button type="submit" class="btn btn-primary btn-lg">Save Changes</button>-->
<!--                                        </div>-->
<!--                                    </form>-->
<!--                                </div>-->
<!--                            </div>-->
<!--                        </div>-->

<!--                        <?php endforeach; ?>-->
<!--                    <?php endif; ?>-->
<!--                    </tbody>-->
<!--                </table>-->
<!--            </div>-->
<!--        </div>-->
<!--    </div>-->
<!--</div>-->

<!-- Add Product Modal -->
<!--<div class="modal fade" id="addProductModal" tabindex="-1">-->
<!--    <div class="modal-dialog modal-dialog-centered">-->
<!--        <div class="modal-content">-->
<!--            <form method="post">-->
<!--                <div class="modal-header">-->
<!--                    <h5 class="modal-title">Add Product</h5>-->
<!--                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>-->
<!--                </div>-->
<!--                <div class="modal-body d-flex flex-column gap-3">-->
<!--                    <input type="hidden" name="action" value="create">-->
<!--                    <div>-->
<!--                        <label class="form-label">Name</label>-->
<!--                        <input type="text" name="name" class="form-control form-control-lg" required>-->
<!--                    </div>-->
<!--                    <div>-->
<!--                        <label class="form-label">Cost</label>-->
<!--                        <input type="number" step="0.01" name="cost" class="form-control form-control-lg" required>-->
<!--                    </div>-->
<!--                </div>-->
<!--                <div class="modal-footer">-->
<!--                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>-->
<!--                    <button type="submit" class="btn btn-primary btn-lg">Save Product</button>-->
<!--                </div>-->
<!--            </form>-->
<!--        </div>-->
<!--    </div>-->
<!--</div>-->

<!--<?php include __DIR__ . '/../layout/footer.php'; ?>-->
