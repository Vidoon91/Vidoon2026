<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/include/payment_helpers.php';

$conn = get_db_connection();
$schemaError = null;
ensure_payment_schema($conn, $schemaError);
$orderNo = trim((string)($_GET['order_no'] ?? ''));
$stmt = $conn->prepare("
    SELECT status, expire_at, subscription_after
    FROM payment_orders
    WHERE order_no = ?
    LIMIT 1
");
$stmt->bind_param('s', $orderNo);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    http_response_code(404);
    echo json_encode(['status' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($order['status'] === 'pending' && strtotime((string)$order['expire_at']) < time()) {
    $update = $conn->prepare("UPDATE payment_orders SET status = 'expired', updated_at = NOW() WHERE order_no = ? AND status = 'pending'");
    $update->bind_param('s', $orderNo);
    $update->execute();
    $order['status'] = 'expired';
}
echo json_encode([
    'status' => $order['status'],
    'subscription_after' => $order['subscription_after'] ?? '',
], JSON_UNESCAPED_UNICODE);
