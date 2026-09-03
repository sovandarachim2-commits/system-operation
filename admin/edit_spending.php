<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'spending.view');
require_once __DIR__ . '/../db.php';

$pdo = get_db_connection();

// Get spending ID from URL
$spending_id = $_GET['id'] ?? '';

if (empty($spending_id)) {
    header('Location: finance_dashboard.php?error=Missing spending ID');
    exit;
}

// Get spending record
$stmt = $pdo->prepare('SELECT * FROM finance_spending WHERE id = ?');
$stmt->execute([$spending_id]);
$spending = $stmt->fetch();

if (!$spending) {
    header('Location: finance_dashboard.php?error=Spending record not found');
    exit;
}

// Get all main categories from database
$stmt = $pdo->query("SELECT * FROM finance_categories WHERE type = 'main' ORDER BY name");
$main_categories = $stmt->fetchAll();

// Get all subcategories from database
$stmt = $pdo->query("SELECT * FROM finance_categories WHERE type = 'sub' ORDER BY parent_category, name");
$all_subcategories = $stmt->fetchAll();

// Organize subcategories by parent category
$subcategories_by_parent = [];
foreach ($all_subcategories as $subcat) {
    $subcategories_by_parent[$subcat['parent_category']][] = $subcat;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role_or_permission(['admin'], 'spending.update');
    $spending_code = $_POST['spending_code'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $paid_by = $_POST['paid_by'] ?? '';
    $receive_by = $_POST['receive_by'] ?? '';
    $spending_date = $_POST['spending_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? '';
    $spend_to = $_POST['spend_to'] ?? '';
    $sub_category = $_POST['sub_category'] ?? '';
    $note = $_POST['note'] ?? '';
    
    // Validation
    $errors = [];
    if (empty($spending_code)) $errors[] = 'Spending code is required';
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0';
    if (empty($paid_by)) $errors[] = 'Paid by is required';
    if (empty($receive_by)) $errors[] = 'Receive by is required';
    if (empty($status)) $errors[] = 'Status is required';
    if (empty($spend_to)) $errors[] = 'Spend to is required';
    if (empty($sub_category)) $errors[] = 'Sub category is required';
    
    if (empty($errors)) {
        try {
            // Update spending record
            $stmt = $pdo->prepare('UPDATE finance_spending 
                SET spending_code = ?, amount = ?, paid_by = ?, receive_by = ?, 
                    spending_date = ?, status = ?, category = ?, sub_category = ?, note = ?
                WHERE id = ?');
            
            $result = $stmt->execute([
                $spending_code,
                $amount,
                $paid_by,
                $receive_by,
                $spending_date,
                $status,
                $spend_to,
                $sub_category,
                $note,
                $spending_id
            ]);
            
            if ($result) {
                header('Location: finance_dashboard.php?success=Spending updated successfully');
                exit;
            } else {
                $errors[] = 'Failed to update spending record';
            }
            
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get users for dropdowns
$stmt = $pdo->query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
$users = $stmt->fetchAll();

require_once __DIR__ . '/../layout/header.php';
?>

<div class="container-fluid py-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-pencil me-2"></i>Edit Spending</h2>
            <p class="text-muted">Update spending record: <?= htmlspecialchars($spending['spending_code']) ?></p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong><br>
            <?php foreach ($errors as $error): ?>
                <?= htmlspecialchars($error) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" class="row g-3">
                <!-- Spending Code -->
                <div class="col-md-6">
                    <label class="form-label">Spending Code *</label>
                    <input type="text" name="spending_code" class="form-control" 
                           value="<?= htmlspecialchars($_POST['spending_code'] ?? $spending['spending_code']) ?>" 
                           placeholder="Spending code" required>
                </div>

                <!-- Amount -->
                <div class="col-md-6">
                    <label class="form-label">Amount of Payment *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" class="form-control" 
                               value="<?= htmlspecialchars($_POST['amount'] ?? $spending['amount']) ?>" 
                               step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>

                <!-- Paid By -->
                <div class="col-md-6">
                    <label class="form-label">Paid By *</label>
                    <select name="paid_by" class="form-select" required>
                        <option value="">Select who paid</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['name']) ?>" 
                                    <?= (($_POST['paid_by'] ?? '') === $user['name'] || $spending['paid_by'] === $user['name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Receive By -->
                <div class="col-md-6">
                    <label class="form-label">Receive By *</label>
                    <select name="receive_by" class="form-select" required>
                        <option value="">Select who received</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['name']) ?>" 
                                    <?= (($_POST['receive_by'] ?? '') === $user['name'] || $spending['receive_by'] === $user['name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date -->
                <div class="col-md-6">
                    <label class="form-label">Date *</label>
                    <input type="date" name="spending_date" class="form-control" 
                           value="<?= htmlspecialchars($_POST['spending_date'] ?? $spending['spending_date']) ?>" 
                           required>
                </div>

                <!-- Status -->
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select name="status" class="form-select" required>
                        <option value="">Select status</option>
                        <option value="pending" <?= (($_POST['status'] ?? '') === 'pending' || $spending['status'] === 'pending') ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= (($_POST['status'] ?? '') === 'approved' || $spending['status'] === 'approved') ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= (($_POST['status'] ?? '') === 'completed' || $spending['status'] === 'completed') ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= (($_POST['status'] ?? '') === 'cancelled' || $spending['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>

                <!-- Spend To (Main Category) -->
                <div class="col-md-6">
                    <label class="form-label">Spend To *</label>
                    <select name="spend_to" class="form-select" id="spendToSelect" required>
                        <option value="">Select main category</option>
                        <option value="company" <?= (($_POST['spend_to'] ?? '') === 'company' || $spending['category'] === 'company') ? 'selected' : '' ?>>Company Spending</option>
                        <option value="marketing" <?= (($_POST['spend_to'] ?? '') === 'marketing' || $spending['category'] === 'marketing') ? 'selected' : '' ?>>Marketing Spending</option>
                        <option value="employee" <?= (($_POST['spend_to'] ?? '') === 'employee' || $spending['category'] === 'employee') ? 'selected' : '' ?>>Employee Spending</option>
                        <option value="boost" <?= (($_POST['spend_to'] ?? '') === 'boost' || $spending['category'] === 'boost') ? 'selected' : '' ?>>Boost Spending</option>
                    </select>
                </div>

                <!-- Sub Category (Dynamic based on main category) -->
                <div class="col-md-6">
                    <label class="form-label">Sub Category *</label>
                    <select name="sub_category" class="form-select" id="subCategorySelect" required>
                        <option value="">Select sub category</option>
                    </select>
                </div>

                <!-- Note -->
                <div class="col-12">
                    <label class="form-label">Note</label>
                    <textarea name="note" class="form-control" rows="3" 
                              placeholder="Add any additional notes or details..."><?= htmlspecialchars($_POST['note'] ?? $spending['note']) ?></textarea>
                </div>

                <!-- Form Actions -->
                <div class="col-12">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Update Spending
                        </button>
                        <a href="finance_dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Sub categories for each main category
const subCategories = {
    company: [
        'Office Rent',
        'Utilities',
        'Supplies',
        'Equipment',
        'Maintenance',
        'Insurance',
        'Legal Fees',
        'Bank Fees',
        'Software Licenses',
        'Other Company'
    ],
    marketing: [
        'Advertising',
        'Social Media',
        'Content Creation',
        'Events',
        'Promotions',
        'Branding',
        'Market Research',
        'Website Development',
        'Email Marketing',
        'Other Marketing'
    ],
    employee: [
        'Salaries',
        'Bonuses',
        'Training',
        'Benefits',
        'Travel Expenses',
        'Meals',
        'Transportation',
        'Uniforms',
        'Equipment',
        'Other Employee'
    ],
    boost: [
        'Sales Incentives',
        'Performance Bonuses',
        'Team Rewards',
        'Customer Bonuses',
        'Referral Programs',
        'Contest Prizes',
        'Recognition Programs',
        'Motivation Events',
        'Team Building',
        'Other Boost'
    ]
};

// Update sub categories when main category changes
document.getElementById('spendToSelect').addEventListener('change', function() {
    const mainCategory = this.value;
    const subCategorySelect = document.getElementById('subCategorySelect');
    
    // Clear current options
    subCategorySelect.innerHTML = '<option value="">Select sub category</option>';
    
    if (mainCategory && subCategories[mainCategory]) {
        subCategories[mainCategory].forEach(function(subCat) {
            const option = document.createElement('option');
            option.value = subCat.toLowerCase().replace(/\s+/g, '_');
            option.textContent = subCat;
            if (subCat.toLowerCase().replace(/\s+/g, '_') === '<?= $spending['sub_category'] ?>') {
                option.selected = true;
            }
            subCategorySelect.appendChild(option);
        });
    }
});

// Initialize sub categories on page load
window.addEventListener('load', function() {
    const mainCategory = document.getElementById('spendToSelect').value;
    if (mainCategory) {
        document.getElementById('spendToSelect').dispatchEvent(new Event('change'));
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
