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

if ($deliveryId <= 0 || !rider_owns_delivery($pdo, $deliveryId, $riderId)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Delivery not found.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
    $stmt->execute([$deliveryId]);
    $current = $stmt->fetchColumn();

    if ($current !== 'assigned') {
        echo json_encode(['status' => 'error', 'message' => 'Delivery cannot be started from its current state (' . $current . ').']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE deliveries SET status = 'in_transit', started_at = NOW(), assigned_at = COALESCE(assigned_at, NOW()) WHERE id = ?");
    $upd->execute([$deliveryId]);

    // Note: the linked order was already flipped to 'out_for_delivery' when the rider
    // accepted the offer (see deliveries_accept.php). Starting the run doesn't change
    // the order-level status — it's still "out for delivery" either way.

    echo json_encode(['status' => 'ok', 'delivery_id' => $deliveryId, 'new_status' => 'in_transit']);
} catch (Exception $e) {
    error_log('deliveries_start failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not start delivery.']);
}