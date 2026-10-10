<?php
 $host = 'localhost';
 $db   = 'fuzzy_db';
 $user = 'root';
 $pass = ''; // Default XAMPP password is empty
 $charset = 'utf8mb4';

 $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
 $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Start Session globally (only if not already started)
if (session_status() === PHP_SESSION_NONE) {
    // If you want nicer cookie settings you can adopt session_set_cookie_params(...) here
    session_start();
}

// --- Add this: base URL for generated links (adjust to your hosting path) ---
// Example for local dev where project is served at http://localhost/fuzzy_db
// In production set to your domain, e.g. 'https://example.com' or 'https://example.com/subfolder'
$base_url = 'http://localhost/fuzzy_db';

// Notification / uploads configuration
// Admin email for notifications (used by default)
$notify_admin_email = 'admin@fuzzy.com';

// Twilio configuration (optional). If left empty, Twilio sends are skipped and email fallback is used.
// To enable Twilio, set $enable_twilio = true and fill the SID, TOKEN and FROM values.
$enable_twilio = false;
$twilio_sid = '';              // e.g. 'ACxxxxx'
$twilio_token = '';            // auth token
$twilio_from = '';             // e.g. '+1234567890' (for SMS)
$twilio_whatsapp_from = '';    // e.g. 'whatsapp:+1415xxxxxxx' (for WhatsApp)

// Website GCash number used for all GCash transactions
$website_gcash = '09716614886';

// Upload path for transaction proofs (make sure folder exists and is writable)
// Fixed path: use project's uploads directory (not parent)
$transaction_proofs_dir = __DIR__ . '/uploads/transactions/';
$transaction_proofs_webpath = 'uploads/transactions/'; // relative to project root for links
?>