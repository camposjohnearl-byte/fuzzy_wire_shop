<?php
// Minimal JSON endpoint for admin analytics.
// Place alongside admin_analytics.php and update DB connection / queries as needed.
//
// IMPORTANT:
// - This script uses output buffering to prevent stray HTML or PHP notices being sent.
// - Replace the sample DB/query logic with your actual queries. The shape returned:
//   {
//     "totalSales": { "labels":[...], "values":[...] },
//     "category":   { "labels":[...], "values":[...] },
//     "kpis":       { "totalSales":1234.56, "orders":12, "avgOrder":102.88, "conversion":3.5 },
//     "sparks":     { "totalSales":{labels:[], values:[]}, ... }
//   }

session_start();

// Force JSON output and stop any accidental HTML
header('Content-Type: application/json; charset=utf-8');
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

// AUTH CHECK (adjust according to your auth/session variable)
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['error' => 'unauthorized', 'message' => 'Authentication required']);
    exit;
}

// Input: range in days (sanitize)
$range = isset($_GET['range']) ? (int)$_GET['range'] : 30;
if ($range <= 0) $range = 30;

try {
    // ===== Replace this placeholder block with your real DB queries =====
    // Example placeholder timeseries (last $range days)
    $labels = [];
    $values = [];
    for ($i = $range - 1; $i >= 0; $i--) {
        $labels[] = date('M j', strtotime("-{$i} days"));
        // placeholder values - replace with DB query aggregate per day
        $values[] = rand(0, 3000);
    }

    // Example category data (replace with your DB query)
    $categoryLabels = ['Roses', 'Tulips', 'Lilies', 'Succulents'];
    $categoryValues = [1200, 800, 450, 300];

    // Example KPIs - replace with real aggregates
    $kpis = [
        'totalSales' => array_sum($values),
        'orders'     => rand(0, 120),
        'avgOrder'   => count($values) ? round(array_sum($values) / max(1, rand(1, 10)), 2) : 0,
        'conversion' => round(mt_rand(10, 400) / 10, 1) // percent
    ];

    // Example sparkline series (you can pass same labels and smaller samples)
    $sparks = [
        'totalSales' => ['labels' => $labels, 'values' => $values],
        'orders'     => ['labels' => $labels, 'values' => array_map(function() { return rand(0,5); }, $labels)],
        'avgOrder'   => ['labels' => $labels, 'values' => array_map(function() { return rand(10,200); }, $labels)],
        'conversion' => ['labels' => $labels, 'values' => array_map(function() { return round(mt_rand(10,300)/10,1); }, $labels)]
    ];
    // ===== End placeholder block =====

    ob_end_clean(); // discard any accidental buffered HTML/text
    echo json_encode([
        'totalSales' => ['labels' => $labels, 'values' => $values],
        'category'   => ['labels' => $categoryLabels, 'values' => $categoryValues],
        'kpis'       => $kpis,
        'sparks'     => $sparks
    ]);
    exit;
} catch (Throwable $e) {
    // On unexpected server error, return JSON with 500 status
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
    exit;
}