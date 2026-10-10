<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'rider') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf($token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request token.']);
    exit;
}

$riderId = (int)$_SESSION['user_id'];
$deliveryId = (int)($_POST['delivery_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($deliveryId <= 0 || !rider_owns_delivery($pdo, $deliveryId, $riderId)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Delivery not found.']);
    exit;
}

if ($reason === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please describe the issue (damaged, returned, could not deliver, etc.).']);
    exit;
}

$stmt = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
$stmt->execute([$deliveryId]);
$current = $stmt->fetchColumn();

if (!in_array($current, ['assigned', 'in_transit'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'This delivery is already ' . $current . '.']);
    exit;
}

try {
    $upd = $pdo->prepare("UPDATE deliveries SET status = 'failed', recipient_note = ?, completed_at = NOW() WHERE id = ?");
    $upd->execute([$reason, $deliveryId]);

    // Reflect on the linked order so admin can see it needs follow-up.
    sync_transaction_status_for_delivery($pdo, $deliveryId, 'canceled');

    try {
        if (function_exists('notify_admins')) {
            notify_admins($pdo, 'Delivery issue reported', "Delivery #{$deliveryId} could not be completed: {$reason}", "admin_delivery_view.php?id=" . $deliveryId);
        }
    } catch (Exception $e) {
        // ignore notification failures
    }

    // Let the customer know their order didn't go through, and why — this bypasses
    // admin_transactions.php's status-change handler, so it needs its own notification here.
    try {
        $txStmt = $pdo->prepare("SELECT transaction_id FROM deliveries WHERE id = ?");
        $txStmt->execute([$deliveryId]);
        $txId = $txStmt->fetchColumn();
        if (!empty($txId) && function_exists('notify_customer_transaction_status')) {
            notify_customer_transaction_status($pdo, (int)$txId, 'canceled', "Your order #{$txId} could not be delivered: {$reason}. Please contact us for next steps.");
        }
    } catch (Exception $e) {
        // ignore notification failures
    }

    echo json_encode(['status' => 'ok', 'delivery_id' => $deliveryId, 'new_status' => 'failed', 'message' => 'Delivery marked as failed and reported to admin.']);
} catch (Exception $e) {
    error_log('deliveries_fail failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not report this delivery.']);
}