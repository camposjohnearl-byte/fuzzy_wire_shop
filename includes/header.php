<?php
// includes/header.php
// Replaces/overrides previous header to provide improved UI with shop/cart/dashboard icons.
// If your project already provides a header with <head> region, merge the relevant parts instead of replacing.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Optional: $title may be set by pages
$title = $title ?? 'Fleur de Ruby';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Bootstrap 5 (CDN) - remove if your project already loads Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="" crossorigin="anonymous">

    <!-- Custom styles -->
    <link rel="stylesheet" href="css/style.css">

    <!-- Fav icon (simple emoji fallback) -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 36 36%22><text y=%2226%22 font-size=%2226%22>🌸</text></svg>">
</head>
<body class="app-body">

<nav class="navbar navbar-expand-lg navbar-light bg-glass shadow-sm mb-4">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <!-- Brand logo (SVG) -->
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 2C13.333 5 17 6 19 8s-1 5-1 5-3-2-7-2-7 2-7 2-3-3-1-5 5-3 7-6z"></path>
      </svg>
      <span class="brand-text">Fleur de Ruby</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item me-2">
          <a class="nav-link d-flex align-items-center gap-2" href="index.php">
            <!-- Shop icon -->
            <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 9l1-2h16l1 2"></path>
              <path d="M21 9v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9"></path>
              <path d="M7 13h10"></path>
            </svg>
            <span class="d-none d-lg-inline">Shop</span>
          </a>
        </li>

        <li class="nav-item me-2">
          <a class="nav-link d-flex align-items-center gap-2 position-relative" href="cart.php">
            <!-- Cart icon -->
            <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="9" cy="20" r="1"></circle>
              <circle cx="20" cy="20" r="1"></circle>
              <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
            </svg>
            <span class="d-none d-lg-inline">Cart</span>
            <?php
            // cart count badge (only if we have a PDO $pdo and user logged in)
            $cart_count = 0;
            if (isset($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
                try {
                    $q = $pdo->prepare("SELECT SUM(quantity) as cnt FROM cart WHERE user_id = ?");
                    $q->execute([ (int)$_SESSION['user_id'] ]);
                    $r = $q->fetch();
                    $cart_count = (int) ($r['cnt'] ?? 0);
                } catch (Exception $e) {
                    // ignore
                }
            }
            if ($cart_count > 0): ?>
              <span class="cart-badge"><?= $cart_count ?></span>
            <?php endif; ?>
          </a>
        </li>

        <?php if (!empty($_SESSION['user_id'])): ?>
          <!-- Feedback link (visible for logged-in users) -->
          <li class="nav-item me-2">
            <a class="nav-link d-flex align-items-center gap-2" href="feedback.php" title="Share Your Feedback">
              <!-- Feedback icon -->
              <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
              </svg>
              <span class="d-none d-lg-inline">Feedback</span>
            </a>
          </li>

          <?php if (($_SESSION['role'] ?? '') !== 'rider'): ?>
          <!-- Dashboard link -->
          <li class="nav-item me-2">
            <a class="nav-link d-flex align-items-center gap-2" href="dashboard.php">
              <!-- Dashboard inline icon -->
              <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="7" height="9"></rect>
                <rect x="14" y="3" width="7" height="5"></rect>
                <rect x="14" y="12" width="7" height="9"></rect>
                <rect x="3" y="13" width="7" height="7"></rect>
              </svg>
              <span class="d-none d-lg-inline">Dashboard</span>
            </a>
          </li>
          <?php endif; ?>

          <?php if (($_SESSION['role'] ?? '') === 'rider'): ?>
            <li class="nav-item me-2">
              <a class="nav-link d-flex align-items-center gap-2" href="rider_dashboard.php">
                <!-- Rider/moto icon -->
                <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="5" cy="18" r="3"></circle>
                  <circle cx="19" cy="18" r="3"></circle>
                  <path d="M5 18l3-8h6l3 5h2"></path>
                  <path d="M8 10V6h3"></path>
                </svg>
                <span class="d-none d-lg-inline">My Deliveries</span>
              </a>
            </li>
          <?php endif; ?>

          <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <li class="nav-item me-2">
              <a class="nav-link d-flex align-items-center gap-2" href="admin.php">
                <!-- Admin panel icon -->
                <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M3 12h18"></path>
                  <path d="M6 6h12"></path>
                  <path d="M6 18h12"></path>
                </svg>
                <span class="d-none d-lg-inline">Admin Panel</span>
              </a>
            </li>
            <li class="nav-item me-2">
              <a class="nav-link d-flex align-items-center gap-2" href="admin_delivery_tracking.php">
                <!-- Map pin icon -->
                <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"></path>
                  <circle cx="12" cy="10" r="3"></circle>
                </svg>
                <span class="d-none d-lg-inline">Delivery Tracking</span>
              </a>
            </li>
          <?php endif; ?>

          <?php
          // Notification bell (moved to be immediately before the user dropdown so it's adjacent)
          if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO && function_exists('get_unread_notification_count') && function_exists('get_notifications')):
              try {
                  $unread = get_unread_notification_count($pdo, (int)$_SESSION['user_id']);
                  $notes = get_notifications($pdo, (int)$_SESSION['user_id'], 5);
              } catch (Exception $e) {
                  $unread = 0;
                  $notes = [];
              }
          ?>
          <li class="nav-item me-2">
              <div class="nav-link dropdown">
                <a href="#" class="nav-link dropdown-toggle position-relative" id="notifMenu" data-bs-toggle="dropdown" aria-expanded="false" style="display:flex; align-items:center; gap:8px;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0v3l-2 3h16l-2-3z"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                  <?php if ($unread > 0): ?>
                    <span class="position-absolute" style="right:0; top:2px;">
                      <span class="badge bg-danger rounded-pill" style="font-size:10px;"><?= (int)$unread ?></span>
                    </span>
                  <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="notifMenu">
                  <li class="dropdown-header fw-bold">Notifications</li>
                  <?php if (empty($notes)): ?>
                    <li class="dropdown-item small text-muted">No notifications</li>
                  <?php else: ?>
                    <?php foreach ($notes as $n): ?>
                      <li class="dropdown-item" style="white-space:normal;">
                        <div class="d-flex justify-content-between align-items-start">
                          <div>
                            <div class="fw-semibold"><?= e($n['title']) ?></div>
                            <div class="small text-muted"><?= e(strlen($n['message']) > 120 ? substr($n['message'],0,120).'...' : $n['message']) ?></div>
                            <?php if (!empty($n['link'])): ?>
                              <div class="mt-1"><a href="<?= e($n['link']) ?>" class="small">View</a></div>
                            <?php endif; ?>
                          </div>
                          <div class="text-end">
                            <form method="POST" action="notifications.php" style="display:inline;">
                              <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                              <input type="hidden" name="mark_read_id" value="<?= (int)$n['id'] ?>">
                              <button class="btn btn-sm btn-outline-secondary" type="submit">Mark</button>
                            </form>
                            <div class="small text-muted mt-1"><?= e(date('M j', strtotime($n['created_at']))) ?></div>
                          </div>
                        </div>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <li class="text-center"><a href="notifications.php" class="small">View all</a></li>
                </ul>
              </div>
          </li>
          <?php endif; ?>

          <!-- Keep a compact user dropdown for logout (no dashboard/admin in this dropdown anymore) -->
          <li class="nav-item dropdown ms-1">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
              <!-- Small user icon (fallback) -->
              <svg class="nav-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
              <span class="d-none d-lg-inline">Hi, <?= htmlspecialchars($_SESSION['name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="POST" action="logout.php" class="m-0 px-3 py-2">
                  <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                  <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Logout</button>
                </form>
              </li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item ms-2">
            <a class="btn btn-fuzzy btn-sm" href="login.php">Login / Register</a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container mb-4">
<!-- Page content starts here -->