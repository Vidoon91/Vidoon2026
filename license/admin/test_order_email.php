<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}
require_once '../include/payment_helpers.php';

if (!hash_equals((string)($_SESSION['payment_csrf'] ?? ''), (string)($_GET['csrf'] ?? ''))) {
    header('Location: payment_settings.php?email_test=failed');
    exit;
}

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    header('Location: payment_settings.php?email_test=failed');
    exit;
}
$recipient = trim(payment_get_setting($conn, 'admin_notify_email'));
$mailError = null;
if ($recipient === '') {
    $success = false;
    $mailError = 'admin_notify_email_missing';
} else {
    require_once '../include/smtp_mailer.php';
    $smtpConfig = payment_get_order_email_config($conn);
    $smtpConfig['enabled'] = true;
    $success = smtp_send_text_email_with_config(
        $recipient,
        'Vidoon 订单邮件测试',
        "这是一封 Vidoon 订单通知测试邮件。\n\n发送时间：" . date('Y-m-d H:i:s') . "\n如果您收到此邮件，说明订单邮件配置可正常使用。",
        $smtpConfig,
        $mailError
    );
}
$query = $success
    ? 'email_test=success'
    : 'email_test=failed&email_error=' . urlencode((string)$mailError);
header('Location: payment_settings.php?' . $query);
exit;
