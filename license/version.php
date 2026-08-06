<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/account_helpers.php';
require_once __DIR__ . '/include/version_helpers.php';

$conn = get_db_connection();
$error = null;
$config = load_public_version_config($conn, $error);

echo json_encode([
    'version' => (string)$config['version'],
    'notes' => (string)$config['notes'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

