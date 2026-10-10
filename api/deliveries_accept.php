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
$action = $_POST['action'] ?? '';

if (!in_array($action, ['accept', 'decline'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

if ($deliveryId <= 0 || !rider_owns_delivery($pdo, $deliveryId, $riderId)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Delivery not found.']);
    exit;
}

$stmt = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
$stmt->execute([$deliveryId]);
$current = $stmt->fetchColumn();

if ($current !== 'pending') {
    echo json_encode(['status' => 'error', 'message' => 'This delivery is no longer awaiting your response (' . $current . ').']);
    exit;
}

try {
    if ($action === 'accept') {
        $upd = $pdo->prepare("UPDATE deliveries SET status = 'assigned', accepted_at = NOW(), approved_at = NOW(), assigned_at = COALESCE(assigned_at, NOW()) WHERE id = ?");
        $upd->execute([$deliveryId]);

        // Reflect on the linked order right away: approved -> rider assigned -> rider accepted
        // is the trigger for "Out for Delivery", not the later "Start Delivery" tap.
        sync_transaction_status_for_delivery($pdo, $deliveryId, 'out_for_delivery');

        try {
            if (function_exists('notify_admins')) {
                notify_admins($pdo, 'Rider accepted delivery', "Delivery #{$deliveryId} was accepted by the rider and is ready to start.", 'admin_deliveries.php?status=assigned');
            }
        } catch (Exception $e) {
            // ignore notification failures
        }

        try {
            $txStmt = $pdo->prepare("SELECT transaction_id FROM deliveries WHERE id = ?");
            $txStmt->execute([$deliveryId]);
            $txId = $txStmt->fetchColumn();
            if (!empty($txId) && function_exists('notify_customer_transaction_status')) {
                notify_customer_transaction_status($pdo, (int)$txId, 'out_for_delivery');
            }
        } catch (Exception $e) {
            // ignore notification failures
        }

        echo json_encode([
            'status' => 'ok',
            'delivery_id' => $deliveryId,
            'new_status' => 'assigned',
            'message' => 'Delivery accepted. You can start it now.',
        ]);
    } else {
        $upd = $pdo->prepare("UPDATE deliveries SET status = 'declined' WHERE id = ?");
        $upd->execute([$deliveryId]);

        try {
            if (function_exists('notify_admins')) {
                notify_admins($pdo, 'Rider declined delivery', "Delivery #{$deliveryId} was declined by the rider and needs to be reassigned.", 'admin_deliveries.php?status=declined');
            }
        } catch (Exception $e) {
            // ignore notification failures
        }

        echo json_encode([
            'status' => 'ok',
            'delivery_id' => $deliveryId,
            'new_status' => 'declined',
            'message' => 'Delivery declined.',
        ]);
    }
} catch (Exception $e) {
    error_log('deliveries_accept failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not update this delivery.']);
}