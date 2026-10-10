<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

require_admin();

// Load categories for the "Add Product" form and filter dropdown
try {
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    // If table does not exist, fall back to default hard-coded list
    $categories = [
        ['name' => 'Roses'],
        ['name' => 'Lilies'],
        ['name' => 'Custom'],
        ['name' => 'Succulents']
    ];
}

// --- Transaction counts for admin dashboard tiles (including Cancelled and both pending statuses) ---
try {
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status IN ('pending', 'pending_payment')")->fetchColumn();
} catch (Exception $e) { $pendingCount = 0; }
try {
    $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'approved'")->fetchColumn();
} catch (Exception $e) { $approvedCount = 0; }
try {
    $ofdCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'out_for_delivery'")->fetchColumn();
} catch (Exception $e) { $ofdCount = 0; }
try {
    $receivedCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'received'")->fetchColumn();
} catch (Exception $e) { $receivedCount = 0; }
try {
    $canceledCountAdmin = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'canceled'")->fetchColumn();
} catch (Exception $e) { $canceledCountAdmin = 0; }

// --- Low stock count (new) ---
try {
    $lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock <= COALESCE(low_stock_threshold, 10)")->fetchColumn();
} catch (Exception $e) {
    $lowStockCount = 0;
}

// --- Feedback counts ---
try {
    $pendingFeedbackCount = (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) { $pendingFeedbackCount = 0; }

try {
    $stmt = $pdo->prepare("
        SELECT f.*, u.name FROM feedback f 
        JOIN users u ON f.user_id = u.id 
        WHERE f.status = 'pending' 
        ORDER BY f.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $pendingFeedback = $stmt->fetchAll();
} catch (Exception $e) { $pendingFeedback = []; }

// Handle Add Product (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($token)) {
        $err = "Invalid request.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $price = trim($_POST['price'] ?? '');
        // We store category as string for backward compatibility.
        $category = trim($_POST['category'] ?? '');
        $stock = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;

        // Basic validation
        if ($name === '' || !is_numeric($price) || $price <= 0 || $stock < 0) {
            $err = "Please provide valid product name, price and stock.";
        } elseif (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $err = "Please upload a product image.";
        } else {
            // Validate image type/size
            $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed)) {
                $err = "Only JPG/PNG images are allowed.";
            } elseif ($_FILES['image']['size'] > 2 * 1024 * 1024) {
                $err = "Image too large (max 2MB).";
            } else {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $imageName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $target = __DIR__ . "/uploads/" . $imageName;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    $err = "Failed to upload image.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category, image_path, stock, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    // default low_stock_threshold = 10
                    $stmt->execute([$name, $desc, $price, $category, $imageName, $stock, 10]);

                    // Notifications: inform current admin and other admins
                    try {
                        $insertedId = (int)$pdo->lastInsertId();
                        $actorName = $_SESSION['name'] ?? 'Admin';
                        // Create a confirmation notification for the actor (optional - useful if admin uses multiple browsers)
                        if (function_exists('create_notification')) {
                            create_notification($pdo, $_SESSION['user_id'] ?? null, 'Product added', "You added product: {$name}", "admin_product_edit.php?id=" . $insertedId);
                        }
                        // Notify other admins (exclude current admin)
                        if (function_exists('notify_admins')) {
                            notify_admins($pdo, 'Product added', "{$actorName} added product: {$name}", "admin_product_edit.php?id=" . $insertedId, $_SESSION['user_id'] ?? null);
                        }
                    } catch (Exception $e) {
                        // ignore notification failures
                    }

                    flash_set('success', 'Product added successfully!');
                    header("Location: admin.php");
                    exit;
                }
            }
        }
    }
}

// Handle Delete Product (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product_id'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($token)) {
        $err = "Invalid request.";
    } else {
        $delId = (int)$_POST['delete_product_id'];
        // Optionally remove image file; fetch path first
        $stmt = $pdo->prepare("SELECT image_path, name FROM products WHERE id = ?");
        $stmt->execute([$delId]);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['image_path'])) {
                @unlink(__DIR__ . "/uploads/" . $row['image_path']);
            }
            $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$delId]);

            // Notify admins and interested users
            try {
                $actorName = $_SESSION['name'] ?? 'Admin';
                if (function_exists('notify_admins')) {
                    notify_admins($pdo, 'Product deleted', "{$actorName} deleted product: " . ($row['name'] ?? $delId), null, $_SESSION['user_id'] ?? null);
                }
                if (function_exists('notify_users_with_product_interest')) {
                    notify_users_with_product_interest($pdo, $delId, 'Product removed', "A product you had interest in was removed by admin: " . ($row['name'] ?? ''), 'index.php');
                }
            } catch (Exception $e) {
                // ignore
            }

            header("Location: admin.php");
            exit;
        } else {
            $err = "Product not found.";
        }
    }
}

// --- Inventory filtering (category + search) using GET params ---
$catFilter = isset($_GET['category']) ? trim($_GET['category']) : '';
$searchQ  = isset($_GET['q']) ? trim($_GET['q']) : '';

try {
    $baseSql = "SELECT * FROM products";
    $conds = [];
    $params = [];

    if ($catFilter !== '') {
        $conds[] = "category = ?";
        $params[] = $catFilter;
    }
    if ($searchQ !== '') {
        $conds[] = "name LIKE ?";
        $params[] = '%' . $searchQ . '%';
    }
    if (!empty($conds)) {
        $baseSql .= " WHERE " . implode(" AND ", $conds);
    }
    $baseSql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    // Fallback to full list on error
    $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
}
?>
<?php require_once 'includes/header.php'; ?>

<?php if (!empty($lowStockCount) && (int)$lowStockCount > 0): ?>
    <div class="alert alert-danger d-flex justify-content-between align-items-center" role="alert" id="lowStockAlert" style="border-left:5px solid #dc3545;">
        <div>
            <strong>Low stock alert:</strong>
            <?= e($lowStockCount) ?> product<?= $lowStockCount > 1 ? 's' : '' ?> are at or below their low-stock threshold.
            <a href="admin_low_stock.php" class="alert-link">Review low-stock items</a>.
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <a href="admin_low_stock.php" class="btn btn-sm btn-outline-light text-danger">View</a>
            <button class="btn btn-sm btn-light" id="dismissLowStockAlert" aria-label="Dismiss low stock alert">Dismiss</button>
        </div>
    </div>

    <script>
    (function(){
        // Dismiss button hides the alert (client-side only)
        var btn = document.getElementById('dismissLowStockAlert');
        if (btn) btn.addEventListener('click', function(){ var a = document.getElementById('lowStockAlert'); if (a) a.style.display = 'none'; });

        // Gentle pulse to draw attention (one-time)
        var alertEl = document.getElementById('lowStockAlert');
        if (alertEl) {
            alertEl.style.transition = 'box-shadow 260ms ease, transform 260ms ease';
            alertEl.animate([
                { boxShadow: '0 0 0 rgba(0,0,0,0)', transform: 'translateY(0px)' },
                { boxShadow: '0 12px 40px rgba(220,53,69,0.12)', transform: 'translateY(-4px)' },
                { boxShadow: '0 0 0 rgba(0,0,0,0)', transform: 'translateY(0px)' }
            ], { duration: 900, easing: 'ease-out' });
        }
    })();
    </script>
<?php endif; ?>

<style>
.pending-feedback-section {
    background: linear-gradient(135deg, #fff1f6 0%, #ffe4f0 100%);
    border-radius: 12px;
    border: 1px solid rgba(255, 122, 182, 0.2);
    padding: 20px;
    margin-bottom: 24px;
}

.pending-feedback-section h5 {
    color: #c2185b;
    margin-bottom: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pending-feedback-section .badge {
    background: #ff7ab6;
    color: white;
}

.feedback-list-item {
    background: white;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-left: 4px solid #ff7ab6;
    transition: all 0.2s ease;
}

.feedback-list-item:hover {
    box-shadow: 0 4px 12px rgba(255, 122, 182, 0.15);
    transform: translateX(2px);
}

.feedback-info {
    flex: 1;
}

.feedback-user-name {
    font-weight: 600;
    color: #2d2437;
}

.feedback-title-preview {
    font-size: 0.9rem;
    color: #6c757d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.feedback-rating-mini {
    font-size: 14px;
    margin-top: 4px;
}
</style>

<!-- Admin quick tiles (including Cancelled and both pending statuses) -->
<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="admin_transactions.php?status=pending" class="btn btn-outline-warning">
        Pending <span class="badge bg-warning text-dark"><?= e($pendingCount) ?></span>
    </a>
    <a href="admin_transactions.php?status=approved" class="btn btn-outline-success">
        Approved <span class="badge bg-success"><?= e($approvedCount) ?></span>
    </a>
    <a href="admin_transactions.php?status=out_for_delivery" class="btn btn-outline-info">
        Out for Delivery <span class="badge bg-info text-dark"><?= e($ofdCount) ?></span>
    </a>
    <a href="admin_transactions.php?status=received" class="btn btn-outline-primary">
        Received <span class="badge bg-primary"><?= e($receivedCount) ?></span>
    </a>
    <a href="admin_transactions.php?status=canceled" class="btn btn-outline-secondary">
        Cancelled <span class="badge bg-secondary text-white"><?= e($canceledCountAdmin) ?></span>
    </a>
</div>

<!-- Add this with your other admin navigation buttons -->
<div class="mb-3">
    <a href="admin_users.php" class="btn btn-outline-primary me-2">Manage Users</a>
    <a href="admin_transactions.php" class="btn btn-outline-secondary me-2">Transactions</a>
    <a href="admin_categories.php" class="btn btn-outline-info me-2">Manage Categories</a>

    <!-- Low Stock button (new) -->
    <a href="admin_low_stock.php" class="btn btn-outline-danger me-2">
        Low Stock <span class="badge bg-danger text-white"><?= e($lowStockCount) ?></span>
    </a>

    <!-- Inventory Report button (new) -->
    <a href="admin_inventory_report.php" class="btn btn-outline-dark me-2">📦 Inventory Report</a>

    <a href="admin_feedback_list.php" class="btn btn-outline-danger me-2">📝 Customer Feedback</a>
    <a href="admin_analytics.php" class="btn btn-outline-dark me-2">📊 Analytics</a>

    <!-- Delivery management (new) -->
    <a href="admin_deliveries.php" class="btn btn-outline-info me-2">
        🛵 Deliveries
        <?php if ($approvedCount > 0): ?>
            <span class="badge bg-success"><?= e($approvedCount) ?></span>
        <?php endif; ?>
    </a>
    <a href="admin_delivery_tracking.php" class="btn btn-outline-info">📍 Delivery Tracking</a>
</div>

<!-- Pending Feedback Section -->
<?php if ($pendingFeedbackCount > 0): ?>
<div class="pending-feedback-section">
    <h5>
        📝 Pending Feedback Reviews
        <span class="badge"><?= $pendingFeedbackCount ?></span>
    </h5>
    
    <?php foreach ($pendingFeedback as $fb): ?>
        <div class="feedback-list-item">
            <div class="feedback-info">
                <div class="feedback-user-name">👤 <?= e($fb['name']) ?></div>
                <div class="feedback-title-preview"><?= e(mb_substr($fb['title'], 0, 60)) ?>...</div>
                <div class="feedback-rating-mini"><?= str_repeat('⭐', (int)$fb['rating']) ?> • <?= e(date('M j, g:i A', strtotime($fb['created_at']))) ?></div>
            </div>
            <a href="admin_feedback_review.php?id=<?= (int)$fb['id'] ?>" class="btn btn-sm btn-primary">
                Review & Reply
            </a>
        </div>
    <?php endforeach; ?>
    
    <div style="text-align: center; margin-top: 12px;">
        <a href="admin_feedback_list.php" class="btn btn-sm btn-outline-secondary">View All Feedback</a>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- Add Product Form -->
    <div class="col-md-4">
        <div class="card p-3 mb-4">
            <h5>Add New Product</h5>
            <?php if($m = flash_get('success')) echo "<div class='alert alert-success'>".e($m)."</div>"; ?>
            <?php if(isset($err)) echo "<div class='alert alert-danger'>".e($err)."</div>"; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_product">
                <div class="mb-2"><label>Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="mb-2"><label>Category</label>
                    <select name="category" class="form-select">
                        <?php foreach($categories as $c): ?>
                            <option value="<?php echo e($c['name']); ?>"><?php echo e($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2"><label>Price</label><input type="number" step="0.01" name="price" class="form-control" required></div>
                <div class="mb-2"><label>Stock</label><input type="number" name="stock" class="form-control" value="0" min="0" required></div>
                <div class="mb-2"><label>Description</label><textarea name="description" class="form-control"></textarea></div>
                <div class="mb-2"><label>Image</label><input type="file" name="image" class="form-control" accept="image/*" required></div>
                <button type="submit" class="btn btn-fuzzy w-100">Upload Product</button>
            </form>
        </div>
    </div>

    <!-- Product List & Filters -->
    <div class="col-md-8">
        <div class="card p-3 mb-3 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Inventory</h5>
                <form method="GET" class="d-flex" style="gap:8px; align-items:center;">
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All categories</option>
                        <?php foreach($categories as $c): ?>
                            <option value="<?= e($c['name']) ?>" <?= $catFilter === $c['name'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" name="q" class="form-control form-control-sm" placeholder="Search product name..." value="<?= e($searchQ) ?>" style="min-width:160px;">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
                    <a href="admin.php" class="btn btn-sm btn-outline-light">Clear</a>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Image</th><th>Name</th><th>Price</th><th>Stock</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($products as $p): ?>
                        <tr>
                            <td><img src="uploads/<?php echo e($p['image_path']); ?>" width="40" alt=""></td>
                            <td>
                                <?php echo e($p['name']); ?>
                                <div class="small text-muted"><?php echo e($p['category']); ?></div>
                            </td>
                            <td>₱<?php echo number_format($p['price'], 2); ?></td>
                            <td>
                                <?php $stock = (int)$p['stock']; $low = (int)$p['low_stock_threshold']; ?>
                                <?php if($stock <= 0): ?>
                                    <span class="badge bg-danger">Out of stock</span>
                                <?php elseif($stock <= $low): ?>
                                    <span class="badge bg-warning text-dark">Low (<?php echo $stock; ?>)</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><?php echo $stock; ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="admin_product_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>

                                <form method="POST" action="admin.php" onsubmit="return confirm('Delete product?');" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="delete_product_id" value="<?php echo (int)$p['id']; ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                </form>
                            </td>   
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                        <tr><td colspan="5" class="text-muted">No products found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>