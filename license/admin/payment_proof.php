<?php
session_start();
if (!isset($_SESSION['login'])) {
    http_response_code(403);
    exit('Forbidden');
}
require_once '../include/payment_helpers.php';

$conn = get_db_connection();
$orderNo = trim((string)($_GET['order_no'] ?? ''));
$stmt = $conn->prepare("SELECT manual_proof_file FROM payment_orders WHERE order_no = ? LIMIT 1");
$stmt->bind_param('s', $orderNo);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$fileName = basename((string)($row['manual_proof_file'] ?? ''));
$filePath = dirname(dirname(__DIR__)) . '/license_private/payment_proofs/' . $fileName;
if ($fileName === '' || !is_file($filePath)) {
    http_response_code(404);
    exit('Not found');
}

$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
if (!isset($mimeMap[$extension])) {
    http_response_code(415);
    exit('Unsupported');
}
header('Content-Type: ' . $mimeMap[$extension]);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($filePath);
