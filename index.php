<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Load categories for the filter dropdown (fallback to hard-coded list if table missing)
try {
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $categories = [
        ['name' => 'Roses'],
        ['name' => 'Lilies'],
        ['name' => 'Custom'],
        ['name' => 'Succulents']
    ];
}

// Read filters from GET
$catFilter = isset($_GET['category']) ? trim($_GET['category']) : '';
$searchQ   = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build product query with optional filters (prepared)
try {
    $sql = "SELECT * FROM products";
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
        $sql .= " WHERE " . implode(" AND ", $conds);
    }
    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    // On error, show all products as fallback
    $products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
}
?>
<?php require_once 'includes/header.php'; ?>

<div class="text-center mb-4">
    <h1 class="fw-bold">Fleur de Ruby Flower Collection</h1>
    <p class="text-muted">Handcrafted art for your home.</p>
</div>

<!-- Filters: category + search -->
<div class="card p-3 mb-4">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-auto" style="min-width:220px;">
            <select name="category" class="form-select">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <?php $cname = $c['name']; ?>
                    <option value="<?= e($cname) ?>" <?= ($catFilter === $cname) ? 'selected' : '' ?>><?= e($cname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col">
            <input type="search" name="q" class="form-control" placeholder="Search product name..." value="<?= e($searchQ) ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-light">Clear</a>
        </div>
    </form>
</div>

<div class="row">
    <?php if (empty($products)): ?>
        <div class="col-12">
            <div class="alert alert-info">No products found. Try a different category or search term.</div>
        </div>
    <?php endif; ?>

    <?php foreach($products as $product): ?>
    <?php $stock = (int)($product['stock'] ?? 0); $low = (int)($product['low_stock_threshold'] ?? 10); ?>
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <img src="uploads/<?php echo e($product['image_path']); ?>" class="card-img-top" alt="<?php echo e($product['name']); ?>" style="height: 250px; object-fit: cover;">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title"><?php echo e($product['name']); ?></h5>
                <div class="mb-2">
                    <a href="index.php?category=<?= urlencode($product['category']) ?>" class="badge bg-light text-dark text-decoration-none"><?= e($product['category']) ?></a>
                    <?php if($stock <= 0): ?>
                        <span class="badge bg-danger ms-2">Out of stock</span>
                    <?php elseif($stock <= $low): ?>
                        <span class="badge bg-warning text-dark ms-2">Low stock (<?php echo $stock; ?>)</span>
                    <?php endif; ?>

                    <?php if ($stock > 0): ?>
                        <div class="small text-muted mt-1">Stock: <?= (int)$stock ?> available</div>
                    <?php endif; ?>
                </div>
                <p class="card-text flex-grow-1"><?php echo e($product['description']); ?></p>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <h5 class="text-primary">₱<?php echo number_format($product['price'], 2); ?></h5>
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <?php if($stock <= 0): ?>
                            <button class="btn btn-secondary" disabled>Out of Stock</button>
                        <?php else: ?>
                            <form method="POST" action="cart.php" class="add-to-cart-form m-0" data-product-id="<?php echo (int)$product['id']; ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="add" value="<?php echo (int)$product['id']; ?>">
                                <button type="submit" class="btn btn-fuzzy">Add to Cart</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-fuzzy">Login to Buy</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once 'includes/footer.php'; ?>