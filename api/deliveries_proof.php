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

$stmt = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
$stmt->execute([$deliveryId]);
$current = $stmt->fetchColumn();

if (!in_array($current, ['assigned', 'in_transit'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'This delivery is already ' . $current . '.']);
    exit;
}

$recipientName = trim($_POST['recipient_name'] ?? '');
$recipientPhone = trim($_POST['recipient_phone'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$acc = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? filter_var($_POST['accuracy'], FILTER_VALIDATE_FLOAT) : null;

if ($recipientName === '') {
    echo json_encode(['status' => 'error', 'message' => 'Recipient name is required.']);
    exit;
}
if ($lat === false || $lng === false || $lat === null || $lng === null) {
    echo json_encode(['status' => 'error', 'message' => 'GPS location is required.']);
    exit;
}
if (!isset($_FILES['photo'])) {
    echo json_encode(['status' => 'error', 'message' => 'A photo is required.']);
    exit;
}
if (!isset($_FILES['signature'])) {
    echo json_encode(['status' => 'error', 'message' => 'A signature is required.']);
    exit;
}

$photoResult = save_delivery_photo($_FILES['photo']);
if (isset($photoResult['error'])) {
    echo json_encode(['status' => 'error', 'message' => 'Photo: ' . $photoResult['error']]);
    exit;
}

$sigResult = save_delivery_signature($_FILES['signature']);
if (isset($sigResult['error'])) {
    echo json_encode(['status' => 'error', 'message' => 'Signature: ' . $sigResult['error']]);
    exit;
}

try {
    $pdo->beginTransaction();

    $upd = $pdo->prepare("UPDATE deliveries SET recipient_name = ?, recipient_phone = ?, recipient_note = ?, status = 'delivered', completed_at = NOW() WHERE id = ?");
    $upd->execute([$recipientName, $recipientPhone, $notes, $deliveryId]);

    $insProof = $pdo->prepare("INSERT INTO delivery_proofs (delivery_id, proof_type, file_path, latitude, longitude, accuracy, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $insProof->execute([$deliveryId, 'photo', $photoResult['path'], $lat, $lng, $acc !== null && $acc !== false ? $acc : null]);
    $photoProofId = (int)$pdo->lastInsertId();

    $insProof->execute([$deliveryId, 'signature', $sigResult['path'], $lat, $lng, $acc !== null && $acc !== false ? $acc : null]);
    $sigProofId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // Reflect on the linked order so admin's "Received" tile picks it up automatically.
    sync_transaction_status_for_delivery($pdo, $deliveryId, 'received');

    // Best-effort notification to admins
    try {
        if (function_exists('notify_admins')) {
            notify_admins($pdo, 'Delivery completed', "Delivery #{$deliveryId} was marked delivered to {$recipientName}.", "admin_delivery_history.php?id=" . $deliveryId);
        }
    } catch (Exception $e) {
        // ignore notification failures
    }

    // Let the customer know their order actually arrived — this bypasses admin_transactions.php's
    // status-change handler (the rider set it directly), so it needs its own notification here.
    try {
        $txStmt = $pdo->prepare("SELECT transaction_id FROM deliveries WHERE id = ?");
        $txStmt->execute([$deliveryId]);
        $txId = $txStmt->fetchColumn();
        if (!empty($txId) && function_exists('notify_customer_transaction_status')) {
            notify_customer_transaction_status($pdo, (int)$txId, 'received');
        }
    } catch (Exception $e) {
        // ignore notification failures
    }

    echo json_encode([
        'status' => 'ok',
        'delivery_id' => $deliveryId,
        'proofs' => [
            ['id' => $photoProofId, 'type' => 'photo', 'path' => $photoResult['path']],
            ['id' => $sigProofId, 'type' => 'signature', 'path' => $sigResult['path']],
        ],
        'message' => 'Proof saved, delivery completed.',
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('deliveries_proof failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not save proof of delivery.']);
}