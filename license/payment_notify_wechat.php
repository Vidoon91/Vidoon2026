<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/include/payment_gateway.php';

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    http_response_code(500);
    echo json_encode(['code' => 'FAIL', 'message' => 'schema failed']);
    exit;
}
$body = file_get_contents('php://input') ?: '';
$timestamp = (string)($_SERVER['HTTP_WECHATPAY_TIMESTAMP'] ?? '');
$nonce = (string)($_SERVER['HTTP_WECHATPAY_NONCE'] ?? '');
$signature = (string)($_SERVER['HTTP_WECHATPAY_SIGNATURE'] ?? '');
$notification = json_decode($body, true) ?: [];
$notificationId = trim((string)($notification['id'] ?? hash('sha256', $body)));
$valid = wechat_verify_notification($conn, $timestamp, $nonce, $body, $signature);
$decryptError = null;
$transaction = $valid
    ? wechat_decrypt_resource($conn, $notification['resource'] ?? [], $decryptError)
    : null;
$orderNo = trim((string)($transaction['out_trade_no'] ?? ''));
$valid = $valid
    && is_array($transaction)
    && ($transaction['trade_state'] ?? '') === 'SUCCESS'
    && hash_equals(payment_get_setting($conn, 'wechat_mch_id'), (string)($transaction['mchid'] ?? ''))
    && hash_equals(payment_get_setting($conn, 'wechat_app_id'), (string)($transaction['appid'] ?? ''));

$log = $conn->prepare("
    INSERT IGNORE INTO payment_notifications
        (payment_channel, notification_id, order_no, signature_valid, payload, processed, error_message, created_at)
    VALUES ('wechat', ?, ?, ?, ?, 0, ?, NOW())
");
$validInt = $valid ? 1 : 0;
$initialError = $valid ? '' : ($decryptError ?: 'signature_invalid');
$log->bind_param('ssiss', $notificationId, $orderNo, $validInt, $body, $initialError);
$log->execute();

if (!$valid) {
    http_response_code(401);
    echo json_encode(['code' => 'FAIL', 'message' => 'invalid notification']);
    exit;
}
$amountCents = intval($transaction['amount']['total'] ?? 0);
$result = null;
$error = null;
$fulfilled = payment_fulfill_order(
    $conn,
    $orderNo,
    (string)($transaction['transaction_id'] ?? ''),
    $amountCents,
    $result,
    $error
);
$update = $conn->prepare("
    UPDATE payment_notifications
    SET processed = ?, error_message = ?
    WHERE payment_channel = 'wechat' AND notification_id = ?
");
$processed = $fulfilled ? 1 : 0;
$errorText = $fulfilled ? '' : (string)$error;
$update->bind_param('iss', $processed, $errorText, $notificationId);
$update->execute();
if (!$fulfilled) {
    http_response_code(500);
    echo json_encode(['code' => 'FAIL', 'message' => 'fulfillment failed']);
    exit;
}
if (empty($result['already_paid'])) {
    payment_send_order_emails($conn, $result);
}
echo json_encode(['code' => 'SUCCESS', 'message' => '成功']);
