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

$lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$acc = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? filter_var($_POST['accuracy'], FILTER_VALIDATE_FLOAT) : null;

if ($lat === false || $lng === false || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Latitude and longitude are required.']);
    exit;
}

try {
    $riderId = (int)$_SESSION['user_id'];
    $saved = record_rider_location($pdo, $riderId, (float)$lat, (float)$lng, $acc !== null && $acc !== false ? (float)$acc : null);
    echo json_encode(['status' => 'ok', 'saved' => $saved]);
} catch (Exception $e) {
    error_log('rider_location failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not save location.']);
}