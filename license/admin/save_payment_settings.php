<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/payment_helpers.php';

if (!hash_equals((string)($_SESSION['payment_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    header('Location: payment_settings.php?error=csrf_invalid');
    exit;
}
$conn = get_db_connection();
$error = null;
if (!ensure_payment_schema($conn, $error)) {
    header('Location: payment_settings.php?error=' . urlencode((string)$error));
    exit;
}

function save_plain_setting(mysqli $conn, $key, $value, &$error) {
    return payment_set_setting($conn, $key, trim((string)$value), false, $error);
}

function save_secret_input(mysqli $conn, $key, $value, &$error) {
    $value = trim((string)$value);
    if ($value === '') {
        return true;
    }
    return payment_set_setting($conn, $key, $value, true, $error);
}

function save_uploaded_secret(mysqli $conn, $field, $key, &$error) {
    if (!isset($_FILES[$field]) || intval($_FILES[$field]['error']) === UPLOAD_ERR_NO_FILE) {
        return true;
    }
    if (intval($_FILES[$field]['error']) !== UPLOAD_ERR_OK) {
        $error = $field . '_upload_failed';
        return false;
    }
    if (intval($_FILES[$field]['size']) > 131072) {
        $error = $field . '_too_large';
        return false;
    }
    $content = file_get_contents($_FILES[$field]['tmp_name']);
    if ($content === false || trim($content) === '') {
        $error = $field . '_empty';
        return false;
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_pkey_get_private')) {
        $error = 'php_openssl_missing';
        return false;
    }
    if (strpos($key, 'private_key') !== false && openssl_pkey_get_private(payment_pem_key_for_admin($content, 'private')) === false) {
        $error = $field . '_invalid_private_key';
        return false;
    }
    if ($key === 'alipay_public_key' && openssl_pkey_get_public(payment_pem_key_for_admin($content, 'public')) === false) {
        $error = $field . '_invalid_public_key';
        return false;
    }
    if ($key === 'wechat_platform_cert' && openssl_pkey_get_public($content) === false) {
        $error = $field . '_invalid_certificate';
        return false;
    }
    return payment_set_setting($conn, $key, trim($content), true, $error);
}

function save_uploaded_qr(mysqli $conn, $field, $key, &$error) {
    if (!isset($_FILES[$field]) || intval($_FILES[$field]['error']) === UPLOAD_ERR_NO_FILE) {
        return true;
    }
    if (intval($_FILES[$field]['error']) !== UPLOAD_ERR_OK) {
        $error = $field . '_upload_failed';
        return false;
    }
    if (intval($_FILES[$field]['size']) > 2097152) {
        $error = $field . '_too_large';
        return false;
    }
    $imageInfo = @getimagesize($_FILES[$field]['tmp_name']);
    $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
    $mime = (string)($imageInfo['mime'] ?? '');
    if (!$imageInfo || !in_array($mime, $allowedMimes, true)) {
        $error = $field . '_invalid_image';
        return false;
    }
    $content = file_get_contents($_FILES[$field]['tmp_name']);
    if ($content === false || $content === '') {
        $error = $field . '_empty';
        return false;
    }
    return payment_set_setting(
        $conn,
        $key,
        'data:' . $mime . ';base64,' . base64_encode($content),
        false,
        $error
    );
}

function payment_pem_key_for_admin($content, $type) {
    $content = trim((string)$content);
    if (strpos($content, '-----BEGIN') !== false) {
        return $content;
    }
    $label = $type === 'private' ? 'PRIVATE KEY' : 'PUBLIC KEY';
    return "-----BEGIN {$label}-----\n"
        . chunk_split(preg_replace('/\s+/', '', $content), 64, "\n")
        . "-----END {$label}-----";
}

$manualEnabledInput = isset($_POST['manual_enabled']);
$orderEmailEnabledInput = isset($_POST['order_email_enabled']);
$smtpSecure = strtolower(trim((string)($_POST['order_smtp_secure'] ?? 'ssl')));
$smtpPort = intval($_POST['order_smtp_port'] ?? 465);
if (!in_array($smtpSecure, ['ssl', 'tls'], true) || $smtpPort < 1 || $smtpPort > 65535) {
    header('Location: payment_settings.php?error=order_smtp_config_invalid');
    exit;
}
$smtpUsername = trim((string)($_POST['order_smtp_username'] ?? ''));
$smtpFromEmail = trim((string)($_POST['order_smtp_from_email'] ?? ''));
$adminNotifyEmail = trim((string)($_POST['admin_notify_email'] ?? ''));
if ($orderEmailEnabledInput
    && (!filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)
        || ($smtpFromEmail !== '' && !filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL))
        || ($adminNotifyEmail !== '' && !filter_var($adminNotifyEmail, FILTER_VALIDATE_EMAIL)))) {
    header('Location: payment_settings.php?error=order_email_address_invalid');
    exit;
}
$plainSettings = [
    // Enabling manual collection must make the checkout available immediately.
    'payment_enabled' => (isset($_POST['payment_enabled']) || $manualEnabledInput) ? '1' : '0',
    'manual_enabled' => $manualEnabledInput ? '1' : '0',
    'manual_wechat_enabled' => isset($_POST['manual_wechat_enabled']) ? '1' : '0',
    'manual_alipay_enabled' => isset($_POST['manual_alipay_enabled']) ? '1' : '0',
    'manual_wechat_name' => $_POST['manual_wechat_name'] ?? '',
    'manual_alipay_name' => $_POST['manual_alipay_name'] ?? '',
    'manual_instructions' => $_POST['manual_instructions'] ?? '',
    'order_email_enabled' => $orderEmailEnabledInput ? '1' : '0',
    'order_smtp_host' => $_POST['order_smtp_host'] ?? '',
    'order_smtp_port' => (string)$smtpPort,
    'order_smtp_secure' => $smtpSecure,
    'order_smtp_username' => $smtpUsername,
    'order_smtp_from_email' => $smtpFromEmail,
    'order_smtp_from_name' => $_POST['order_smtp_from_name'] ?? 'Vidoon',
    'alipay_enabled' => isset($_POST['alipay_enabled']) ? '1' : '0',
    'wechat_enabled' => isset($_POST['wechat_enabled']) ? '1' : '0',
    'alipay_app_id' => $_POST['alipay_app_id'] ?? '',
    'wechat_mch_id' => $_POST['wechat_mch_id'] ?? '',
    'wechat_app_id' => $_POST['wechat_app_id'] ?? '',
    'wechat_serial_no' => $_POST['wechat_serial_no'] ?? '',
    'admin_notify_email' => $adminNotifyEmail,
];

foreach ($plainSettings as $key => $value) {
    if (!save_plain_setting($conn, $key, $value, $error)) {
        header('Location: payment_settings.php?error=' . urlencode((string)$error));
        exit;
    }
}

$qrUploads = [
    ['manual_wechat_qr_file', 'manual_wechat_qr'],
    ['manual_alipay_qr_file', 'manual_alipay_qr'],
];
foreach ($qrUploads as [$field, $key]) {
    if (!save_uploaded_qr($conn, $field, $key, $error)) {
        header('Location: payment_settings.php?error=' . urlencode((string)$error));
        exit;
    }
}

$orderSmtpPassword = trim((string)($_POST['order_smtp_password'] ?? ''));
if (!save_secret_input($conn, 'order_smtp_password', $orderSmtpPassword, $error)) {
    header('Location: payment_settings.php?error=' . urlencode((string)$error));
    exit;
}

$wechatApiV3Key = trim((string)($_POST['wechat_api_v3_key'] ?? ''));
if ($wechatApiV3Key !== '' && strlen($wechatApiV3Key) !== 32) {
    header('Location: payment_settings.php?error=wechat_api_v3_key_must_be_32_bytes');
    exit;
}
if (!save_secret_input($conn, 'wechat_api_v3_key', $wechatApiV3Key, $error)) {
    header('Location: payment_settings.php?error=' . urlencode((string)$error));
    exit;
}

$uploads = [
    ['alipay_private_key_file', 'alipay_private_key'],
    ['alipay_public_key_file', 'alipay_public_key'],
    ['wechat_private_key_file', 'wechat_private_key'],
    ['wechat_platform_cert_file', 'wechat_platform_cert'],
];
foreach ($uploads as [$field, $key]) {
    if (!save_uploaded_secret($conn, $field, $key, $error)) {
        header('Location: payment_settings.php?error=' . urlencode((string)$error));
        exit;
    }
}

foreach (($_POST['plans'] ?? []) as $planInput) {
    $id = intval($planInput['id'] ?? 0);
    $name = trim((string)($planInput['name'] ?? ''));
    $days = max(1, intval($planInput['days'] ?? 1));
    $priceCents = intval(round(floatval($planInput['price'] ?? 0) * 100));
    $description = trim((string)($planInput['description'] ?? ''));
    $status = isset($planInput['status']) && $priceCents > 0 ? 1 : 0;
    if ($id <= 0 || $name === '') {
        continue;
    }
    $stmt = $conn->prepare("
        UPDATE subscription_plans
        SET plan_name = ?, duration_days = ?, price_cents = ?,
            description = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('siisii', $name, $days, $priceCents, $description, $status, $id);
    $stmt->execute();
}

header('Location: payment_settings.php?saved=1');
exit;
