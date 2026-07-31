<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/db.php';
require_once '../include/account_helpers.php';

$subscriptionUrl = trim((string)($_POST['subscription_url'] ?? ''));
$downloadUrl = trim((string)($_POST['download_url'] ?? ''));
$baiduDownloadUrl = trim((string)($_POST['download_baidu_url'] ?? ''));
$quarkDownloadUrl = trim((string)($_POST['download_quark_url'] ?? ''));

function admin_valid_public_url($url) {
    $validUrl = filter_var($url, FILTER_VALIDATE_URL);
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return $validUrl && in_array($scheme, ['http', 'https'], true);
}

if (!admin_valid_public_url($subscriptionUrl)) {
    header('Location: users.php?site_error=invalid_subscription_url');
    exit;
}
if (!admin_valid_public_url($downloadUrl)) {
    header('Location: users.php?site_error=invalid_download_url');
    exit;
}
if ($baiduDownloadUrl !== '' && !admin_valid_public_url($baiduDownloadUrl)) {
    header('Location: users.php?site_error=invalid_baidu_download_url');
    exit;
}
if ($quarkDownloadUrl !== '' && !admin_valid_public_url($quarkDownloadUrl)) {
    header('Location: users.php?site_error=invalid_quark_download_url');
    exit;
}

$conn = get_db_connection();
$saveError = null;
if (
    !ensure_app_settings_table($conn, $saveError)
    || !set_app_setting($conn, 'subscription_url', $subscriptionUrl, $saveError)
    || !set_app_setting($conn, 'download_url', $downloadUrl, $saveError)
    || !set_app_setting($conn, 'download_baidu_url', $baiduDownloadUrl, $saveError)
    || !set_app_setting($conn, 'download_quark_url', $quarkDownloadUrl, $saveError)
) {
    header('Location: users.php?site_error=database_failed');
    exit;
}

header('Location: users.php?site_saved=1');
exit;
