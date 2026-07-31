<?php
require_once __DIR__ . '/include/payment_gateway.php';

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    echo 'failure';
    exit;
}
$payload = $_POST;
$notificationId = trim((string)($payload['notify_id'] ?? hash('sha256', json_encode($payload))));
$orderNo = trim((string)($payload['out_trade_no'] ?? ''));
$valid = alipay_verify_notification($conn, $payload)
    && hash_equals(payment_get_setting($conn, 'alipay_app_id'), (string)($payload['app_id'] ?? ''))
    && in_array((string)($payload['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);

$log = $conn->prepare("
    INSERT IGNORE INTO payment_notifications
        (payment_channel, notification_id, order_no, signature_valid, payload, processed, created_at)
    VALUES ('alipay', ?, ?, ?, ?, 0, NOW())
");
$rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$validInt = $valid ? 1 : 0;
$log->bind_param('ssis', $notificationId, $orderNo, $validInt, $rawPayload);
$log->execute();

if (!$valid) {
    echo 'failure';
    exit;
}
$amountCents = intval(round(floatval($payload['total_amount'] ?? 0) * 100));
$result = null;
$error = null;
$fulfilled = payment_fulfill_order(
    $conn,
    $orderNo,
    (string)($payload['trade_no'] ?? ''),
    $amountCents,
    $result,
    $error
);
$update = $conn->prepare("
    UPDATE payment_notifications
    SET processed = ?, error_message = ?
    WHERE payment_channel = 'alipay' AND notification_id = ?
");
$processed = $fulfilled ? 1 : 0;
$errorText = $fulfilled ? '' : (string)$error;
$update->bind_param('iss', $processed, $errorText, $notificationId);
$update->execute();
if ($fulfilled && empty($result['already_paid'])) {
    payment_send_order_emails($conn, $result);
}
echo $fulfilled ? 'success' : 'failure';
