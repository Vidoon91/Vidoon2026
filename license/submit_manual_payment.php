<?php
session_start();
require_once __DIR__ . '/include/payment_helpers.php';

function manual_submit_fail($orderNo, $message) {
    header('Location: manual_payment.php?order_no=' . urlencode($orderNo) . '&error=' . urlencode($message));
    exit;
}

$orderNo = trim((string)($_POST['order_no'] ?? ''));
$sessionToken = (string)($_SESSION['manual_order_tokens'][$orderNo] ?? '');
if ($orderNo === '' || $sessionToken === ''
    || !hash_equals($sessionToken, (string)($_POST['csrf'] ?? ''))) {
    manual_submit_fail($orderNo, '页面已过期，请刷新后重新提交');
}

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    manual_submit_fail($orderNo, '订单服务初始化失败');
}

$stmt = $conn->prepare("SELECT * FROM payment_orders WHERE order_no = ? LIMIT 1");
$stmt->bind_param('s', $orderNo);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order || !payment_is_manual_channel($order['payment_channel'])) {
    manual_submit_fail($orderNo, '订单不存在');
}
if (!in_array($order['status'], ['pending', 'rejected'], true)) {
    manual_submit_fail($orderNo, '该订单当前不能重复提交');
}
if (strtotime((string)$order['expire_at']) < time()) {
    $expired = $conn->prepare("UPDATE payment_orders SET status = 'expired', updated_at = NOW() WHERE id = ?");
    $orderId = intval($order['id']);
    $expired->bind_param('i', $orderId);
    $expired->execute();
    manual_submit_fail($orderNo, '订单已过期，请重新下单');
}

$payer = trim((string)($_POST['payer'] ?? ''));
$tradeNo = trim((string)($_POST['trade_no'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));
$paymentTimeInput = trim((string)($_POST['payment_time'] ?? ''));
$paymentTimestamp = strtotime($paymentTimeInput);
if ($payer === '' || payment_text_length($payer) > 100) {
    manual_submit_fail($orderNo, '请填写正确的付款人信息');
}
if (strlen($tradeNo) < 4 || strlen($tradeNo) > 100) {
    manual_submit_fail($orderNo, '请填写正确的付款交易单号');
}
if ($paymentTimestamp === false || $paymentTimestamp > time() + 600 || $paymentTimestamp < time() - 604800) {
    manual_submit_fail($orderNo, '付款时间不正确');
}
if (payment_text_length($note) > 255) {
    manual_submit_fail($orderNo, '备注内容过长');
}

$duplicate = $conn->prepare("
    SELECT id FROM payment_orders
    WHERE payment_channel = ? AND manual_trade_no = ?
      AND id <> ? AND status IN ('reviewing', 'paid')
    LIMIT 1
");
$channel = (string)$order['payment_channel'];
$orderId = intval($order['id']);
$duplicate->bind_param('ssi', $channel, $tradeNo, $orderId);
$duplicate->execute();
if ($duplicate->get_result()->fetch_assoc()) {
    manual_submit_fail($orderNo, '该付款交易单号已经提交过');
}

if (!isset($_FILES['proof']) || intval($_FILES['proof']['error']) !== UPLOAD_ERR_OK) {
    manual_submit_fail($orderNo, '请上传付款截图');
}
if (intval($_FILES['proof']['size']) <= 0 || intval($_FILES['proof']['size']) > 5242880) {
    manual_submit_fail($orderNo, '付款截图大小必须在 5MB 以内');
}
$imageInfo = @getimagesize($_FILES['proof']['tmp_name']);
$mimeMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$mime = (string)($imageInfo['mime'] ?? '');
if (!$imageInfo || !isset($mimeMap[$mime])) {
    manual_submit_fail($orderNo, '付款截图格式仅支持 PNG、JPG 或 WebP');
}

$storageDir = dirname(__DIR__) . '/license_private/payment_proofs';
if (!is_dir($storageDir) && !mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
    manual_submit_fail($orderNo, '服务器无法保存付款截图');
}
$fileName = date('Ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $mimeMap[$mime];
$targetPath = $storageDir . '/' . $fileName;
if (!move_uploaded_file($_FILES['proof']['tmp_name'], $targetPath)) {
    manual_submit_fail($orderNo, '付款截图保存失败');
}

$oldProof = basename((string)$order['manual_proof_file']);
$paymentTime = date('Y-m-d H:i:s', $paymentTimestamp);
$update = $conn->prepare("
    UPDATE payment_orders
    SET status = 'reviewing', manual_payer = ?, manual_trade_no = ?,
        manual_payment_time = ?, manual_proof_file = ?, manual_note = ?,
        review_note = '', reviewed_at = NULL, reviewed_by = '', updated_at = NOW()
    WHERE id = ? AND status IN ('pending', 'rejected')
");
$update->bind_param('sssssi', $payer, $tradeNo, $paymentTime, $fileName, $note, $orderId);
if (!$update->execute() || $update->affected_rows !== 1) {
    @unlink($targetPath);
    manual_submit_fail($orderNo, '付款信息提交失败，请稍后重试');
}
if ($oldProof !== '' && $oldProof !== $fileName) {
    $oldPath = $storageDir . '/' . $oldProof;
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

$order['status'] = 'reviewing';
$order['manual_payer'] = $payer;
$order['manual_trade_no'] = $tradeNo;
payment_send_manual_review_email($conn, $order);
header('Location: manual_payment.php?order_no=' . urlencode($orderNo) . '&submitted=1');
exit;
