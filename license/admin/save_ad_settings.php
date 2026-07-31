<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}
if (!hash_equals((string)($_SESSION['ads_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    header('Location: ad_settings.php?error=csrf_invalid');
    exit;
}

require_once '../include/ad_helpers.php';

$conn = get_db_connection();
$error = null;
$publisherId = strtolower(preg_replace('/\s+/', '', (string)($_POST['publisher_id'] ?? '')));
if (preg_match('/^pub-\d{10,24}$/', $publisherId)) {
    $publisherId = 'ca-' . $publisherId;
}
$networkCode = trim((string)($_POST['network_code'] ?? get_app_setting($conn, 'ads_manager_network_code', '')));
$rewardedUnitPath = trim((string)($_POST['rewarded_unit_path'] ?? get_app_setting($conn, 'ads_rewarded_unit_path', '')));
$leftCode = trim((string)($_POST['left_code'] ?? ''));
$rightCode = trim((string)($_POST['right_code'] ?? ''));
$homeLeftCode = trim((string)($_POST['home_left_code'] ?? ''));
$homeRightCode = trim((string)($_POST['home_right_code'] ?? ''));
$homeDownloadCode = trim((string)($_POST['home_download_code'] ?? ''));
$homeBottomCode = trim((string)($_POST['home_bottom_code'] ?? ''));
$rewardCount = max(1, min(100, intval($_POST['reward_count'] ?? 3)));
$dailyViewLimit = max(1, min(50, intval($_POST['daily_view_limit'] ?? 5)));
$cooldownSeconds = max(30, min(86400, intval($_POST['cooldown_seconds'] ?? 120)));

if ($publisherId !== '' && !preg_match('/^ca-pub-\d{10,24}$/', $publisherId)) {
    header('Location: ad_settings.php?error=invalid_publisher_id');
    exit;
}
if ($networkCode !== '' && !preg_match('/^\d{4,24}$/', $networkCode)) {
    header('Location: ad_settings.php?error=invalid_network_code');
    exit;
}
if ($rewardedUnitPath !== '' && !preg_match('#^/[A-Za-z0-9_./-]{3,180}$#', $rewardedUnitPath)) {
    header('Location: ad_settings.php?error=invalid_rewarded_unit_path');
    exit;
}
if (
    strlen($leftCode) > 200000
    || strlen($rightCode) > 200000
    || strlen($homeLeftCode) > 200000
    || strlen($homeRightCode) > 200000
    || strlen($homeDownloadCode) > 200000
    || strlen($homeBottomCode) > 200000
) {
    header('Location: ad_settings.php?error=ad_code_too_large');
    exit;
}

$settings = [
    'ads_enabled' => isset($_POST['display_enabled']) ? '1' : '0',
    'ads_display_enabled' => isset($_POST['display_enabled']) ? '1' : '0',
    'free_reward_enabled' => isset($_POST['reward_enabled']) ? '1' : '0',
    'ads_publisher_id' => $publisherId,
    'ads_manager_network_code' => $networkCode,
    'ads_rewarded_unit_path' => $rewardedUnitPath,
    'ads_left_code' => $leftCode,
    'ads_right_code' => $rightCode,
    'ads_home_left_code' => $homeLeftCode,
    'ads_home_right_code' => $homeRightCode,
    'ads_home_download_code' => $homeDownloadCode,
    'ads_home_bottom_code' => $homeBottomCode,
    'ads_reward_count' => (string)$rewardCount,
    'ads_daily_view_limit' => (string)$dailyViewLimit,
    'ads_cooldown_seconds' => (string)$cooldownSeconds,
];

$conn->begin_transaction();
try {
    foreach ($settings as $key => $value) {
        if (!set_app_setting($conn, $key, $value, $error)) {
            throw new RuntimeException($error ?: 'save_failed');
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    header('Location: ad_settings.php?error=' . urlencode($e->getMessage() ?: 'save_failed'));
    exit;
}

header('Location: ad_settings.php?saved=1');
exit;
