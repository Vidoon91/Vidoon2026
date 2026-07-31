<?php
session_start();
require_once __DIR__ . '/include/payment_gateway.php';

function checkout_fail($message) {
    header('Location: subscribe.php?error=' . urlencode($message));
    exit;
}

if (!hash_equals((string)($_SESSION['checkout_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    checkout_fail('页面已过期，请重新选择套餐');
}

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    checkout_fail('支付服务初始化失败');
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$planId = intval($_POST['plan_id'] ?? 0);
$channel = strtolower(trim((string)($_POST['channel'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    checkout_fail('请输入正确的注册邮箱');
}
if (!in_array($channel, ['wechat', 'alipay', 'manual_wechat', 'manual_alipay'], true)
    || !payment_channel_ready($conn, $channel)) {
    checkout_fail('所选支付通道尚未启用');
}

$user = payment_find_user_by_email($conn, $email);
if (!$user) {
    checkout_fail('该邮箱尚未注册会员账号，请先完成会员注册');
}

$planStmt = $conn->prepare("SELECT * FROM subscription_plans WHERE id = ? AND status = 1 AND price_cents > 0 LIMIT 1");
$planStmt->bind_param('i', $planId);
$planStmt->execute();
$plan = $planStmt->get_result()->fetch_assoc();
if (!$plan) {
    checkout_fail('订阅套餐不存在或已下架');
}

$clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$rateStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payment_orders
    WHERE client_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
");
$rateStmt->bind_param('s', $clientIp);
$rateStmt->execute();
if (intval(($rateStmt->get_result()->fetch_assoc()['total'] ?? 0)) >= 5) {
    checkout_fail('下单过于频繁，请一分钟后重试');
}

$orderNo = payment_generate_order_no();
$isManual = payment_is_manual_channel($channel);
$orderExpire = date('Y-m-d H:i:s', time() + ($isManual ? 86400 : 900));
$userId = intval($user['id']);
$planCode = (string)$plan['plan_code'];
$planName = (string)$plan['plan_name'];
$durationDays = intval($plan['duration_days']);
$amountCents = intval($plan['price_cents']);
$insert = $conn->prepare("
    INSERT INTO payment_orders (
        order_no, user_id, user_email, plan_id, plan_code, plan_name,
        duration_days, amount_cents, payment_channel, status, expire_at,
        client_ip, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())
");
$insert->bind_param(
    'sisissiisss',
    $orderNo,
    $userId,
    $email,
    $planId,
    $planCode,
    $planName,
    $durationDays,
    $amountCents,
    $channel,
    $orderExpire,
    $clientIp
);
if (!$insert->execute()) {
    checkout_fail('订单创建失败，请稍后重试');
}

$order = [
    'order_no' => $orderNo,
    'user_email' => $email,
    'plan_name' => $planName,
    'duration_days' => $durationDays,
    'amount_cents' => $amountCents,
    'payment_channel' => $channel,
    'expire_at' => $orderExpire,
];
unset($_SESSION['checkout_csrf']);
if ($isManual) {
    if (!isset($_SESSION['manual_order_tokens']) || !is_array($_SESSION['manual_order_tokens'])) {
        $_SESSION['manual_order_tokens'] = [];
    }
    $_SESSION['manual_order_tokens'][$orderNo] = bin2hex(random_bytes(24));
    payment_send_order_created_emails($conn, $order);
    header('Location: manual_payment.php?order_no=' . urlencode($orderNo));
    exit;
}

$gatewayError = null;
$codeUrl = payment_create_provider_order($conn, $order, $gatewayError);
if ($codeUrl === null) {
    $failed = $conn->prepare("UPDATE payment_orders SET status = 'failed', updated_at = NOW() WHERE order_no = ?");
    $failed->bind_param('s', $orderNo);
    $failed->execute();
    payment_send_order_created_emails($conn, $order, 'failed');
    checkout_fail('支付通道下单失败，请检查后台配置');
}

$update = $conn->prepare("UPDATE payment_orders SET code_url = ?, updated_at = NOW() WHERE order_no = ?");
$update->bind_param('ss', $codeUrl, $orderNo);
$update->execute();
payment_send_order_created_emails($conn, $order);

header('Location: payment.php?order_no=' . urlencode($orderNo));
exit;
