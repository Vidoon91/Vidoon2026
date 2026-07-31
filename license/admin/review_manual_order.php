<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}
require_once '../include/payment_helpers.php';

function review_redirect($message = '', $success = false) {
    $key = $success ? 'message' : 'error';
    header('Location: orders.php?' . $key . '=' . urlencode($message));
    exit;
}

if (!hash_equals((string)($_SESSION['order_review_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    review_redirect('页面已过期，请刷新后重试');
}

$orderNo = trim((string)($_POST['order_no'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));
$reviewNote = trim((string)($_POST['review_note'] ?? ''));
if ($orderNo === '' || !in_array($action, ['approve', 'reject'], true)) {
    review_redirect('审核参数不正确');
}

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    review_redirect('支付服务初始化失败');
}
$stmt = $conn->prepare("SELECT * FROM payment_orders WHERE order_no = ? LIMIT 1");
$stmt->bind_param('s', $orderNo);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order || !payment_is_manual_channel($order['payment_channel'])) {
    review_redirect('人工收款订单不存在');
}
if ($order['status'] !== 'reviewing') {
    review_redirect('该订单已经处理，请勿重复操作');
}

$reviewedBy = trim((string)($_SESSION['username'] ?? $_SESSION['login'] ?? 'admin'));
$reviewedBy = payment_text_substr($reviewedBy, 100);
if ($action === 'reject') {
    if ($reviewNote === '') {
        $reviewNote = '付款信息未能通过核对，请检查后重新提交';
    }
    $reviewNote = payment_text_substr($reviewNote, 255);
    $update = $conn->prepare("
        UPDATE payment_orders
        SET status = 'rejected', review_note = ?, reviewed_at = NOW(),
            reviewed_by = ?, updated_at = NOW()
        WHERE id = ? AND status = 'reviewing'
    ");
    $orderId = intval($order['id']);
    $update->bind_param('ssi', $reviewNote, $reviewedBy, $orderId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        review_redirect('驳回订单失败，请重试');
    }
    payment_send_configured_email(
        $conn,
        (string)$order['user_email'],
        'Vidoon 付款审核未通过',
        "您的付款信息暂未通过核对。\n\n"
        . "订单号：" . $orderNo . "\n"
        . "订阅套餐：" . ($order['plan_name'] ?? '') . "\n"
        . "未通过原因：" . $reviewNote . "\n\n"
        . "请返回原人工付款订单页面，核对后重新提交付款信息。"
    );
    review_redirect('订单已驳回，用户可以修改付款信息后重新提交', true);
}

$fulfillResult = null;
$fulfillError = null;
$manualTradeNo = trim((string)$order['manual_trade_no']);
if ($manualTradeNo === '') {
    review_redirect('订单缺少付款交易单号，无法确认');
}
if (!payment_fulfill_order(
    $conn,
    $orderNo,
    'MANUAL-' . $manualTradeNo,
    intval($order['amount_cents']),
    $fulfillResult,
    $fulfillError
)) {
    review_redirect('确认开通失败：' . (string)$fulfillError);
}

$reviewNote = $reviewNote !== '' ? payment_text_substr($reviewNote, 255) : '人工核对到账';
$reviewUpdate = $conn->prepare("
    UPDATE payment_orders
    SET review_note = ?, reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
    WHERE order_no = ?
");
$reviewUpdate->bind_param('sss', $reviewNote, $reviewedBy, $orderNo);
$reviewUpdate->execute();

if (empty($fulfillResult['already_paid'])) {
    payment_send_order_emails($conn, $fulfillResult);
}
review_redirect('收款已确认，账号订阅已经开通', true);
