<?php
// includes/functions.php
// Reusable helpers (expects session started in config.php)

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                // fallback
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        $token = csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
            header("Location: index.php");
            exit;
        }
    }
}

if (!function_exists('flash_set')) {
    function flash_set($key, $msg) {
        if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];
        $_SESSION['flash'][$key] = $msg;
    }
}

if (!function_exists('flash_get')) {
    function flash_get($key) {
        if (isset($_SESSION['flash'][$key])) {
            $m = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $m;
        }
        return null;
    }
}

if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// Save uploaded proof file for transactions
if (!function_exists('save_transaction_proof')) {
    function save_transaction_proof($file, $config) {
        // $config expects $transaction_proofs_dir and $transaction_proofs_webpath keys (from config.php)
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return null;

        $allowed = ['image/jpeg','image/png','image/jpg','application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            return ['error' => 'Only JPG/PNG/PDF are allowed.'];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['error' => 'File too large (max 5MB).'];
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $name = time() . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
        $destDir = $config['transaction_proofs_dir'] ?? (__DIR__ . '/../uploads/transactions/');
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        $dest = $destDir . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['error' => 'Failed to move uploaded file.'];
        }

        return ['path' => ($config['transaction_proofs_webpath'] ?? 'uploads/transactions/') . $name, 'name' => $name];
    }
}

// Send notification after checkout (email fallback, optional Twilio SMS/WhatsApp)
if (!function_exists('send_checkout_notification')) {
    function send_checkout_notification($pdo, $user, $transaction, $config) {
        // $user = ['id'=>..., 'name'=>..., 'email'=>..., 'phone'=>...]
        // $transaction: associative array with fields including id, total_amount, payment_method, gcash_reference, gcash_proof_path, created_at
        // $config is globals from config.php (twilio & admin email)
        $adminEmail = $config['notify_admin_email'] ?? 'admin@fuzzy.com';

        // Build message body
        $body = "New transaction placed:\n";
        $body .= "Transaction ID: " . ($transaction['id'] ?? '') . "\n";
        $body .= "User: " . ($user['name'] ?? '') . " (" . ($user['email'] ?? '') . ")\n";
        $body .= "Amount: $" . number_format($transaction['total_amount'] ?? 0, 2) . "\n";
        $body .= "Method: " . ($transaction['payment_method'] ?? '') . "\n";
        if (($transaction['payment_method'] ?? '') === 'GCash') {
            $body .= "GCash Number (website): " . ($transaction['gcash_number'] ?? '') . "\n";
            $body .= "Reference: " . ($transaction['gcash_reference'] ?? '') . "\n";
            if (!empty($transaction['gcash_proof_path'])) $body .= "Proof: " . $transaction['gcash_proof_path'] . "\n";
        } else {
            $body .= "COD Address: " . ($transaction['cod_address'] ?? '') . "\n";
        }

        $subject = "New Order #" . ($transaction['id'] ?? '');

        // Send email to admin
        @mail($adminEmail, $subject, $body);

        // Send email to user as confirmation
        @mail($user['email'], "Order received: #" . ($transaction['id'] ?? ''), "Hi " . ($user['name'] ?? '') . ",\n\nThank you! Your order has been recorded. We will notify you when admin approves it.\n\n" . $body);

        // Optionally send via Twilio (SMS & WhatsApp) if configured
        $enableTwilio = $config['enable_twilio'] ?? false;
        $twilioSid = $config['twilio_sid'] ?? '';
        $twilioToken = $config['twilio_token'] ?? '';
        $twilioFrom = $config['twilio_from'] ?? '';
        $twilioWhatsappFrom = $config['twilio_whatsapp_from'] ?? '';

        if ($enableTwilio && $twilioSid && $twilioToken && ($twilioFrom || $twilioWhatsappFrom)) {
            // Send SMS to admin (if twilio_from set)
            $adminPhone = $config['admin_phone'] ?? null; // optional admin phone in config
            $msgText = "New order #" . ($transaction['id'] ?? '') . " - $" . number_format($transaction['total_amount'] ?? 0, 2);
            // Prepare endpoints
            $baseUrl = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";

            // Helper to POST to Twilio
            $postToTwilio = function($to, $from, $body) use ($baseUrl, $twilioSid, $twilioToken) {
                $data = http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $baseUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, $twilioSid . ':' . $twilioToken);
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return ['code' => $httpCode, 'result' => $result];
            };

            if (!empty($adminPhone) && !empty($twilioFrom)) {
                @call_user_func($postToTwilio, $adminPhone, $twilioFrom, $msgText);
            }

            // Send WhatsApp to admin (if whatsapp from configured)
            if (!empty($adminPhone) && !empty($twilioWhatsappFrom)) {
                $waTo = "whatsapp:" . $adminPhone;
                @call_user_func($postToTwilio, $waTo, $twilioWhatsappFrom, $msgText);
            }

            // Optionally send WhatsApp or SMS to user if phone present
            if (!empty($user['phone'])) {
                $userPhone = $user['phone'];
                if (!empty($twilioWhatsappFrom)) {
                    $waTo = "whatsapp:" . $userPhone;
                    @call_user_func($postToTwilio, $waTo, $twilioWhatsappFrom, "Hi " . ($user['name'] ?? '') . ", your order #" . ($transaction['id'] ?? '') . " has been received. Total: $" . number_format($transaction['total_amount'] ?? 0, 2));
                } elseif (!empty($twilioFrom)) {
                    @call_user_func($postToTwilio, $userPhone, $twilioFrom, "Hi " . ($user['name'] ?? '') . ", your order #" . ($transaction['id'] ?? '') . " has been received.");
                }
            }
        }
    }
}
?>
<?php
// ------------------- Notifications helpers -------------------
// Usage:
// create_notification($pdo, $userId, $title, $message = '', $link = null);
// notify_admins($pdo, $title, $message = '', $link = null, $excludeUserId = null);
// notify_users_with_product_interest($pdo, $productId, $title, $message = '', $link = null, $excludeUserId = null);
// get_notifications($pdo, $userId, $limit = 10);
// get_unread_notification_count($pdo, $userId);
// mark_notification_read($pdo, $notificationId, $userId);

if (!function_exists('create_notification')) {
    function create_notification(PDO $pdo, ?int $userId, string $title, string $message = '', ?string $link = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            $stmt->execute([$userId, $title, $message, $link]);
            return (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            // Don't break the application if notifications fail
            error_log("create_notification failed: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notify_admins')) {
    function notify_admins(PDO $pdo, string $title, string $message = '', ?string $link = null, ?int $excludeUserId = null) {
        try {
            $q = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
            $q->execute();
            $admins = $q->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $aid) {
                if ($excludeUserId !== null && (int)$aid === (int)$excludeUserId) continue;
                create_notification($pdo, (int)$aid, $title, $message, $link);
            }
        } catch (Exception $e) {
            error_log("notify_admins failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('notify_users_with_product_interest')) {
    /**
     * Notify users who have the product in their cart or have purchased it in the past.
     *
     * @param PDO $pdo
     * @param int $productId
     * @param string $title
     * @param string $message
     * @param string|null $link
     * @param int|null $excludeUserId
     */
    function notify_users_with_product_interest(PDO $pdo, int $productId, string $title, string $message = '', ?string $link = null, ?int $excludeUserId = null) {
        try {
            $userIds = [];

            // Users with product in cart
            $c = $pdo->prepare("SELECT DISTINCT user_id FROM cart WHERE product_id = ?");
            $c->execute([$productId]);
            $rows = $c->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $u) if (!empty($u)) $userIds[(int)$u] = true;

            // Users who purchased this product before
            $p = $pdo->prepare("SELECT DISTINCT t.user_id FROM transaction_items ti JOIN transactions t ON ti.transaction_id = t.id WHERE ti.product_id = ?");
            $p->execute([$productId]);
            $rows2 = $p->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows2 as $u) if (!empty($u)) $userIds[(int)$u] = true;

            foreach (array_keys($userIds) as $uid) {
                if ($excludeUserId !== null && (int)$uid === (int)$excludeUserId) continue;
                create_notification($pdo, (int)$uid, $title, $message, $link);
            }
        } catch (Exception $e) {
            error_log("notify_users_with_product_interest failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('get_notifications')) {
    function get_notifications(PDO $pdo, int $userId, int $limit = 10) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
            // bind as INT for limit
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("get_notifications failed: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_unread_notification_count')) {
    function get_unread_notification_count(PDO $pdo, int $userId) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read(PDO $pdo, int $notificationId, int $userId) {
        try {
            $u = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $u->execute([$notificationId, $userId]);
            return $u->rowCount() > 0;
        } catch (Exception $e) {
            error_log("mark_notification_read failed: " . $e->getMessage());
            return false;
        }
    }
}

// ------------------- Low stock notification helper -------------------
if (!function_exists('notify_low_stock_if_needed')) {
    /**
     * Check a product's stock against its low_stock_threshold and notify admins (once)
     * if the stock is less than or equal to the threshold.
     *
     * Uses notifications table to avoid sending duplicate unread alerts for the same product.
     *
     * @param PDO $pdo
     * @param int $productId
     * @param int $dedupeWindowHours Optional: only suppress duplicate unread notices within this window (default 24h)
     * @return void
     */
    function notify_low_stock_if_needed(PDO $pdo, int $productId, int $dedupeWindowHours = 24) {
        try {
            $stmt = $pdo->prepare("SELECT id, name, stock, COALESCE(low_stock_threshold, 10) AS low_stock_threshold FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) return;

            $stock = (int)$p['stock'];
            $threshold = (int)$p['low_stock_threshold'];

            if ($stock > $threshold) {
                // not low
                return;
            }

            $title = "Low stock: " . ($p['name'] ?? 'Product #' . $productId);
            $message = "Stock for \"" . ($p['name'] ?? 'product') . "\" is low: {$stock} item(s) remaining. Threshold: {$threshold}.";
            $link = "admin_product_edit.php?id=" . (int)$productId;

            // Avoid duplicate unread notifications for the same product in the recent window.
            // Check for existing unread notification with same link/title in last $dedupeWindowHours hours.
            $check = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE link = ? AND title = ? AND is_read = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)");
            $check->execute([$link, $title, $dedupeWindowHours]);
            $cnt = (int)$check->fetchColumn();
            if ($cnt > 0) {
                // already notified recently and still unread
                return;
            }

            // Use notify_admins to create a notification for each admin (function exists earlier in this file)
            if (function_exists('notify_admins')) {
                notify_admins($pdo, $title, $message, $link, null);
            } else {
                // fallback: create a notification row (best-effort)
                if (function_exists('create_notification')) {
                    // create a generic admin notification with null user id (admins will pick it up via notify_admins in normal flow)
                    create_notification($pdo, null, $title, $message, $link);
                }
            }
        } catch (Exception $e) {
            error_log("notify_low_stock_if_needed failed for product {$productId}: " . $e->getMessage());
        }
    }
}
// ------------------- end low stock helper -------------------

// ------------------- end notifications helpers -------------------
?>
<?php
// ==================================================================
// Rider / Delivery helpers (Proof of Delivery + Tracking feature)
// ==================================================================

if (!function_exists('require_rider')) {
    function require_rider() {
        if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'rider') {
            header("Location: index.php");
            exit;
        }
    }
}

if (!function_exists('require_admin_or_rider')) {
    function require_admin_or_rider() {
        $role = $_SESSION['role'] ?? '';
        if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'rider'], true)) {
            header("Location: index.php");
            exit;
        }
    }
}

// Generic safe file saver used by delivery proof uploads (photo + signature).
// $file is a single element from $_FILES. Returns ['path'=>webpath,'name'=>name] or ['error'=>msg].
if (!function_exists('save_delivery_proof_file')) {
    function save_delivery_proof_file($file, array $allowedMimes, int $maxBytes) {
        if (!isset($file) || !is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'No file uploaded or upload error.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes, true)) {
            return ['error' => 'Unsupported file type: ' . $mime];
        }
        if ($file['size'] > $maxBytes) {
            return ['error' => 'File too large (max ' . round($maxBytes / (1024 * 1024), 1) . 'MB).'];
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
        ];
        $safeExt = $extMap[$mime] ?? 'bin';

        $destDir = __DIR__ . '/../uploads/delivery_proofs/';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
            // Block directory listing
            @file_put_contents($destDir . 'index.html', '');
        }

        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $safeExt;
        $dest = $destDir . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['error' => 'Failed to save uploaded file.'];
        }

        return ['path' => 'uploads/delivery_proofs/' . $name, 'name' => $name];
    }
}

// Save a delivery photo (recipient / package photo). Max 5MB, jpg/png.
if (!function_exists('save_delivery_photo')) {
    function save_delivery_photo($file) {
        return save_delivery_proof_file($file, ['image/jpeg', 'image/jpg', 'image/png'], 5 * 1024 * 1024);
    }
}

// Save a signature capture (PNG, converted from canvas dataURL to Blob client-side). Max 1MB.
if (!function_exists('save_delivery_signature')) {
    function save_delivery_signature($file) {
        return save_delivery_proof_file($file, ['image/png'], 1 * 1024 * 1024);
    }
}

// Save a user's profile picture (rider avatar etc). Max 3MB, jpg/png.
if (!function_exists('save_profile_picture')) {
    function save_profile_picture($file) {
        if (!isset($file) || !is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'No file uploaded or upload error.'];
        }

        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png'];
        $maxBytes = 3 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes, true)) {
            return ['error' => 'Only JPG/PNG images are allowed.'];
        }
        if ($file['size'] > $maxBytes) {
            return ['error' => 'Image too large (max 3MB).'];
        }

        $extMap = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png'];
        $safeExt = $extMap[$mime] ?? 'jpg';

        $destDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
            @file_put_contents($destDir . 'index.html', '');
        }

        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $safeExt;
        $dest = $destDir . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['error' => 'Failed to save uploaded image.'];
        }

        return ['path' => 'uploads/avatars/' . $name, 'name' => $name];
    }
}

// Render a small circular avatar: the user's uploaded picture if present, otherwise
// a colored initials badge derived from their name. Used anywhere a rider (or other
// user) needs a face next to their name — dashboards, delivery lists, transactions.
if (!function_exists('avatar_img')) {
    function avatar_img(?string $name, ?string $picturePath, int $size = 36, string $class = '') {
        $name = $name ?? '';
        $initials = mb_strtoupper(mb_substr(trim($name), 0, 1));
        $words = preg_split('/\s+/', trim($name));
        if (count($words) > 1 && $words[1] !== '') {
            $initials .= mb_strtoupper(mb_substr($words[1], 0, 1));
        }
        $sizeStyle = "width:{$size}px;height:{$size}px;min-width:{$size}px;";

        if (!empty($picturePath)) {
            return '<img src="' . e($picturePath) . '" alt="' . e($name) . '" class="rider-avatar-img ' . e($class) . '" '
                 . 'style="' . $sizeStyle . 'border-radius:50%;object-fit:cover;border:1px solid rgba(0,0,0,.08);">';
        }

        $fontSize = max(10, (int)round($size * 0.4));
        return '<span class="rider-avatar-fallback ' . e($class) . '" style="' . $sizeStyle
             . 'border-radius:50%;display:inline-flex;align-items:center;justify-content:center;'
             . 'background:linear-gradient(135deg,#8b5cf6,#b26ef0);color:#fff;font-weight:700;'
             . 'font-size:' . $fontSize . 'px;line-height:1;">' . e($initials ?: '?') . '</span>';
    }
}

// Confirm a delivery belongs to the given rider (authorization check for rider endpoints).
if (!function_exists('rider_owns_delivery')) {
    function rider_owns_delivery(PDO $pdo, int $deliveryId, int $riderId): bool {
        $stmt = $pdo->prepare("SELECT id FROM deliveries WHERE id = ? AND rider_id = ?");
        $stmt->execute([$deliveryId, $riderId]);
        return (bool) $stmt->fetch();
    }
}

// Record a rider's GPS location, rate-limited to 1 update per 5 seconds per rider.
if (!function_exists('record_rider_location')) {
    function record_rider_location(PDO $pdo, int $riderId, float $lat, float $lng, ?float $accuracy) {
        $chk = $pdo->prepare("SELECT recorded_at FROM rider_locations WHERE rider_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $chk->execute([$riderId]);
        $last = $chk->fetchColumn();
        if ($last !== false) {
            $lastTs = strtotime($last);
            if ($lastTs !== false && (time() - $lastTs) < 5) {
                return false; // rate limited, silently ignore
            }
        }
        $ins = $pdo->prepare("INSERT INTO rider_locations (rider_id, latitude, longitude, accuracy, recorded_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->execute([$riderId, $lat, $lng, $accuracy]);
        return true;
    }
}

// Latest known location + basic info for every active (non-banned) rider.
if (!function_exists('get_rider_locations')) {
    function get_rider_locations(PDO $pdo): array {
        $sql = "SELECT u.id AS rider_id, u.name AS rider_name, u.motorcycle_plate,
                       rl.latitude, rl.longitude, rl.accuracy, rl.recorded_at
                FROM users u
                INNER JOIN (
                    SELECT rider_id, MAX(recorded_at) AS max_time
                    FROM rider_locations
                    GROUP BY rider_id
                ) latest ON latest.rider_id = u.id
                INNER JOIN rider_locations rl ON rl.rider_id = latest.rider_id AND rl.recorded_at = latest.max_time
                WHERE u.role = 'rider' AND u.status != 'banned'
                ORDER BY u.name ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Keep the linked order/transaction's status in sync with the delivery lifecycle.
// Safe no-op if the delivery has no transaction_id, or if the transactions table/status differs.
if (!function_exists('sync_transaction_status_for_delivery')) {
    function sync_transaction_status_for_delivery(PDO $pdo, int $deliveryId, string $transactionStatus) {
        try {
            $stmt = $pdo->prepare("SELECT transaction_id FROM deliveries WHERE id = ?");
            $stmt->execute([$deliveryId]);
            $txnId = $stmt->fetchColumn();

            if (empty($txnId)) {
                return false; // no linked order to sync
            }

            $upd = $pdo->prepare("UPDATE transactions SET status = ? WHERE id = ?");
            $upd->execute([$transactionStatus, (int)$txnId]);
            return true;
        } catch (Exception $e) {
            error_log("sync_transaction_status_for_delivery failed: " . $e->getMessage());
            return false;
        }
    }
}

// Given a delivery, work out what its linked transaction's status *should* be right now, and
// push that onto the transaction. Used when a delivery is (re)linked to an order after the fact —
// e.g. an in_transit delivery that had no transaction_id gets linked, and the order should
// immediately reflect "out for delivery" rather than waiting for the next status change.
if (!function_exists('sync_transaction_status_from_current_delivery')) {
    function sync_transaction_status_from_current_delivery(PDO $pdo, int $deliveryId): bool {
        try {
            $stmt = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
            $stmt->execute([$deliveryId]);
            $deliveryStatus = $stmt->fetchColumn();
            if ($deliveryStatus === false) {
                return false;
            }

            // pending/declined/canceled deliveries don't map onto an order-level status change here —
            // pending just means "still waiting on a rider", declined/canceled are handled at the
            // point they happen (see admin_deliveries.php cancel_delivery).
            $map = [
                'assigned'   => 'out_for_delivery',
                'in_transit' => 'out_for_delivery',
                'delivered'  => 'received',
                'failed'     => 'canceled',
            ];
            if (!isset($map[$deliveryStatus])) {
                return false;
            }

            return sync_transaction_status_for_delivery($pdo, $deliveryId, $map[$deliveryStatus]);
        } catch (Exception $e) {
            error_log('sync_transaction_status_from_current_delivery failed: ' . $e->getMessage());
            return false;
        }
    }
}

// Bootstrap badge class for a delivery status. Kept in one place so every page renders statuses consistently.
if (!function_exists('delivery_status_badge_class')) {
    function delivery_status_badge_class(string $status): string {
        switch ($status) {
            case 'delivered':
                return 'success';
            case 'in_transit':
                return 'info text-dark';
            case 'assigned':
                return 'primary';
            case 'accepted':
                return 'info text-dark';
            case 'pending':
                return 'warning text-dark';
            case 'declined':
                return 'dark';
            case 'failed':
            case 'canceled':
                return 'danger';
            default:
                return 'secondary';
        }
    }
}

// Human-friendly label for a delivery status (used where the raw enum value would be confusing, e.g. "pending" vs "assigned").
if (!function_exists('delivery_status_label')) {
    function delivery_status_label(string $status): string {
        $labels = [
            'pending'    => 'Awaiting rider',
            'accepted'   => 'Awaiting approval',
            'assigned'   => 'Approved',
            'in_transit' => 'In transit',
            'delivered'  => 'Delivered',
            'declined'   => 'Declined',
            'failed'     => 'Failed',
            'canceled'   => 'Canceled',
        ];
        return $labels[$status] ?? $status;
    }
}

// Active (non-banned) riders with their latest ping and current workload, for the admin "who's available" panel.
// A rider counts as "active" if they've sent a location update within $minutesThreshold minutes.
if (!function_exists('get_active_riders')) {
    function get_active_riders(PDO $pdo, int $minutesThreshold = 10): array {
        $sql = "SELECT u.id, u.name, u.motorcycle_plate, u.profile_picture,
                       rl.recorded_at AS last_seen,
                       CASE WHEN rl.recorded_at IS NOT NULL AND rl.recorded_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                            THEN 1 ELSE 0 END AS is_active,
                       COALESCE(wl.active_count, 0) AS active_deliveries
                FROM users u
                LEFT JOIN (
                    SELECT rider_id, MAX(recorded_at) AS recorded_at
                    FROM rider_locations
                    GROUP BY rider_id
                ) rl ON rl.rider_id = u.id
                LEFT JOIN (
                    SELECT rider_id, COUNT(*) AS active_count
                    FROM deliveries
                    WHERE status IN ('pending','accepted','assigned','in_transit')
                    GROUP BY rider_id
                ) wl ON wl.rider_id = u.id
                WHERE u.role = 'rider' AND u.status != 'banned'
                ORDER BY is_active DESC, active_deliveries ASC, u.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$minutesThreshold]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Automatically create a pending delivery offer for an approved order, picking the best
// available rider (prefers riders who are currently online, then whoever has the lightest
// current load). No-op if a delivery already exists for this transaction, or if there are
// no riders to assign (admin can still assign manually from admin_deliveries.php).
//
// Call this right after a transaction's status is flipped to "approved" (or whatever status
// means the order is ready to go out), e.g.:
//
//   $pdo->prepare("UPDATE transactions SET status = 'approved' WHERE id = ?")->execute([$tid]);
//   auto_create_delivery_for_transaction($pdo, $tid);
//
// Returns the new delivery ID, or null if nothing was created.
if (!function_exists('auto_create_delivery_for_transaction')) {
    function auto_create_delivery_for_transaction(PDO $pdo, int $transactionId): ?int {
        try {
            // Don't create a second delivery for the same order.
            $chk = $pdo->prepare("SELECT id FROM deliveries WHERE transaction_id = ? AND status NOT IN ('canceled', 'declined') LIMIT 1");
            $chk->execute([$transactionId]);
            if ($chk->fetch()) {
                return null;
            }

            // Best-effort recipient prefill from the order + its user. Column names are guesses
            // (name/phone/cod_address) — adjust once we confirm the real schema.
            $recipientName = null;
            $recipientPhone = null;
            $recipientAddress = null;
            $custLat = null;
            $custLng = null;
            try {
                $txStmt = $pdo->prepare("SELECT t.*, u.name AS user_name, u.phone AS user_phone FROM transactions t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
                $txStmt->execute([$transactionId]);
                $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
                if ($tx) {
                    $recipientName = $tx['user_name'] ?? null;
                    $recipientPhone = $tx['user_phone'] ?? null;
                    $recipientAddress = $tx['cod_address'] ?? null;
                    // Customer dropped a pin on the checkout map — this is more accurate
                    // than anything text-geocoding can derive from the address string.
                    if (isset($tx['cust_lat'], $tx['cust_lng']) && $tx['cust_lat'] !== null && $tx['cust_lng'] !== null) {
                        $custLat = (float)$tx['cust_lat'];
                        $custLng = (float)$tx['cust_lng'];
                    }
                }
            } catch (Exception $e) {
                // proceed without recipient prefill
            }

            // get_active_riders() already sorts online-first, then lightest load, then name.
            $riders = get_active_riders($pdo, 10);
            if (empty($riders)) {
                return null;
            }
            $riderId = (int)$riders[0]['id'];

            $ins = $pdo->prepare("INSERT INTO deliveries (transaction_id, rider_id, assigned_at, status, recipient_name, recipient_phone, recipient_address, created_at) VALUES (?, ?, NOW(), 'pending', ?, ?, ?, NOW())");
            $ins->execute([$transactionId, $riderId, $recipientName, $recipientPhone, $recipientAddress]);
            $deliveryId = (int)$pdo->lastInsertId();

            // Resolve the parcel's map coordinates right away so the tracking map has them
            // as soon as the rider accepts, instead of waiting on a later page load.
            try {
                if ($custLat !== null && $custLng !== null) {
                    // Customer pinned an exact spot on the checkout map — trust that over
                    // geocoding the address text.
                    set_delivery_destination_pin($pdo, $deliveryId, $custLat, $custLng);
                } else {
                    ensure_delivery_geocoded($pdo, $deliveryId);
                }
            } catch (Exception $e) {
                // ignore geocoding failures
            }

            try {
                if (function_exists('create_notification')) {
                    create_notification($pdo, $riderId, 'New delivery offer', "Delivery #{$deliveryId} is waiting for you to accept or decline.", 'rider_delivery.php?id=' . $deliveryId);
                }
            } catch (Exception $e) {
                // ignore notification failures
            }

            return $deliveryId;
        } catch (Exception $e) {
            error_log('auto_create_delivery_for_transaction failed: ' . $e->getMessage());
            return null;
        }
    }
}

// Turn a free-text address into lat/lng using OpenStreetMap's Nominatim geocoder.
// Returns ['lat' => float, 'lng' => float] on success, or null if it couldn't be resolved.
// Best-effort only: never throws, so callers can safely fire-and-forget this.
if (!function_exists('geocode_address')) {
    function geocode_address(string $address): ?array {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        try {
            $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['User-Agent: FuzzyFlowersDelivery/1.0 (admin contact via site)'],
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false || $err) {
                error_log('geocode_address curl failed: ' . $err);
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
                return null;
            }
            return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
        } catch (Exception $e) {
            error_log('geocode_address failed: ' . $e->getMessage());
            return null;
        }
    }
}

// Make sure a delivery's destination has lat/lng saved, geocoding its recipient_address
// the first time it's needed. Falls back to recipient_note for older deliveries created
// before the dedicated address field existed. No-op if already geocoded, or if there's
// no address text to work with. Safe to call on every page load.
if (!function_exists('ensure_delivery_geocoded')) {
    function ensure_delivery_geocoded(PDO $pdo, int $deliveryId): void {
        try {
            $stmt = $pdo->prepare("SELECT recipient_address, recipient_note, dest_lat, dest_lng FROM deliveries WHERE id = ?");
            $stmt->execute([$deliveryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            if ($row['dest_lat'] !== null && $row['dest_lng'] !== null) {
                return; // already geocoded
            }
            $address = trim($row['recipient_address'] ?? '');
            if ($address === '') {
                $address = trim($row['recipient_note'] ?? ''); // legacy fallback
            }
            if ($address === '') {
                return; // nothing to geocode
            }

            $coords = geocode_address($address);
            if ($coords === null) {
                return; // couldn't resolve; try again next time
            }

            $upd = $pdo->prepare("UPDATE deliveries SET dest_lat = ?, dest_lng = ?, dest_geocoded_at = NOW() WHERE id = ?");
            $upd->execute([$coords['lat'], $coords['lng'], $deliveryId]);
        } catch (Exception $e) {
            error_log('ensure_delivery_geocoded failed: ' . $e->getMessage());
        }
    }
}

// Set/replace a delivery's address text and immediately (re-)resolve its map coordinates.
// Clears any previously-geocoded pin so a corrected address doesn't keep pointing at the
// old spot. Returns true if the address was geocoded successfully.
if (!function_exists('set_delivery_address')) {
    function set_delivery_address(PDO $pdo, int $deliveryId, string $address): bool {
        $address = trim($address);
        $upd = $pdo->prepare("UPDATE deliveries SET recipient_address = ?, dest_lat = NULL, dest_lng = NULL, dest_geocoded_at = NULL WHERE id = ?");
        $upd->execute([$address !== '' ? $address : null, $deliveryId]);

        if ($address === '') {
            return false;
        }

        ensure_delivery_geocoded($pdo, $deliveryId);

        $chk = $pdo->prepare("SELECT dest_lat, dest_lng FROM deliveries WHERE id = ?");
        $chk->execute([$deliveryId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        return $row && $row['dest_lat'] !== null && $row['dest_lng'] !== null;
    }
}

// Manually pin a delivery's destination coordinates — used when the rider taps a spot on
// the map themselves because auto-geocoding couldn't resolve the address text (or there
// was no address text at all). Overwrites any previous pin.
if (!function_exists('set_delivery_destination_pin')) {
    function set_delivery_destination_pin(PDO $pdo, int $deliveryId, float $lat, float $lng): bool {
        try {
            $upd = $pdo->prepare("UPDATE deliveries SET dest_lat = ?, dest_lng = ?, dest_geocoded_at = NOW() WHERE id = ?");
            return $upd->execute([$lat, $lng, $deliveryId]);
        } catch (Exception $e) {
            error_log('set_delivery_destination_pin failed: ' . $e->getMessage());
            return false;
        }
    }
}

// Most relevant active delivery (and its rider) for a given order/transaction — used to show
// "Rider: <name>" on the admin transactions list and in the customer's order history/details.
// Ignores canceled/declined deliveries so a dead delivery attempt never displays a stale rider.
// Returns null if the order has no live delivery record (e.g. not assigned yet).
if (!function_exists('get_delivery_for_transaction')) {
    function get_delivery_for_transaction(PDO $pdo, int $transactionId): ?array {
        try {
            $stmt = $pdo->prepare("SELECT d.id, d.status AS delivery_status, d.rider_id,
                                           u.name AS rider_name, u.motorcycle_plate, u.profile_picture AS rider_picture
                                    FROM deliveries d
                                    JOIN users u ON u.id = d.rider_id
                                    WHERE d.transaction_id = ? AND d.status NOT IN ('canceled', 'declined')
                                    ORDER BY d.id DESC LIMIT 1");
            $stmt->execute([$transactionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('get_delivery_for_transaction failed: ' . $e->getMessage());
            return null;
        }
    }
}

// Tell the customer their order's status changed, with wording that matches what actually
// happened. Centralized here so every place that changes a transaction's status (admin approving/
// rejecting/cancelling, or the delivery lifecycle marking an order out-for-delivery/received)
// notifies the customer the same, accurate way instead of drifting out of sync.
if (!function_exists('notify_customer_transaction_status')) {
    function notify_customer_transaction_status(PDO $pdo, int $transactionId, string $newStatus, ?string $messageOverride = null) {
        try {
            $stmt = $pdo->prepare("SELECT user_id FROM transactions WHERE id = ?");
            $stmt->execute([$transactionId]);
            $userId = $stmt->fetchColumn();
            if (empty($userId)) {
                return;
            }

            $copy = [
                'approved'         => ['Order Approved', "Your order #{$transactionId} has been approved and is being prepared."],
                'rejected'         => ['Order Rejected', "Your order #{$transactionId} was rejected. Please contact us if you have questions."],
                'canceled'         => ['Order Canceled', "Your order #{$transactionId} has been canceled."],
                'out_for_delivery' => ['Order Out for Delivery', "Your order #{$transactionId} is on its way to you."],
                'received'         => ['Order Completed', "Your order #{$transactionId} has been marked as received. View your ticket/receipt."],
                'pending'          => ['Order Pending', "Your order #{$transactionId} is pending review."],
            ];
            [$title, $message] = $copy[$newStatus] ?? ['Order Updated', "Your order #{$transactionId} status changed to " . $newStatus . "."];
            if ($messageOverride !== null) {
                $message = $messageOverride;
            }

            $link = $newStatus === 'received' ? "receipt.php?order_id={$transactionId}" : "dashboard.php";

            if (function_exists('create_notification')) {
                create_notification($pdo, (int)$userId, $title, $message, $link);
            }
        } catch (Exception $e) {
            error_log('notify_customer_transaction_status failed: ' . $e->getMessage());
        }
    }
}

// Deliveries currently in progress (assigned/in_transit) with rider name + latest location for map markers.
if (!function_exists('get_active_deliveries_with_location')) {
    function get_active_deliveries_with_location(PDO $pdo): array {
        $sql = "SELECT d.id, d.status, d.rider_id, u.name AS rider_name,
                       d.recipient_name, d.recipient_phone, d.recipient_note, d.recipient_address, d.transaction_id,
                       d.dest_lat, d.dest_lng,
                       rl.latitude, rl.longitude, rl.recorded_at AS location_updated_at
                FROM deliveries d
                JOIN users u ON u.id = d.rider_id
                LEFT JOIN (
                    SELECT rider_id, MAX(recorded_at) AS max_time
                    FROM rider_locations
                    GROUP BY rider_id
                ) latest ON latest.rider_id = d.rider_id
                LEFT JOIN rider_locations rl ON rl.rider_id = latest.rider_id AND rl.recorded_at = latest.max_time
                WHERE d.status IN ('assigned','in_transit')
                ORDER BY d.updated_at DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Best-effort: geocode any destination addresses we haven't resolved yet, so the
        // admin tracking map can plot the parcel's destination alongside the rider.
        foreach ($rows as &$row) {
            $addressToGeocode = $row['recipient_address'] ?: $row['recipient_note'];
            if ($row['dest_lat'] === null && !empty($addressToGeocode)) {
                ensure_delivery_geocoded($pdo, (int)$row['id']);
                $refetch = $pdo->prepare("SELECT dest_lat, dest_lng FROM deliveries WHERE id = ?");
                $refetch->execute([(int)$row['id']]);
                if ($fresh = $refetch->fetch(PDO::FETCH_ASSOC)) {
                    $row['dest_lat'] = $fresh['dest_lat'];
                    $row['dest_lng'] = $fresh['dest_lng'];
                }
            }
        }
        unset($row);

        return $rows;
    }
}