<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/db.php';
require_once '../include/account_helpers.php';

$conn = get_db_connection();
ensure_user_subscription_columns($conn);

$id = intval($_POST['id'] ?? 0);
$expireInput = trim((string)($_POST['expire_at'] ?? ''));
$maxDevices = max(1, intval($_POST['max_devices'] ?? 1));
$status = intval($_POST['status'] ?? 1) === 1 ? 1 : 0;
$resetDevices = intval($_POST['reset_devices'] ?? 0) === 1;
$deleteAccount = intval($_POST['delete_account'] ?? 0) === 1;

if ($id <= 0) {
    die('用户 ID 无效');
}

$stmt = $conn->prepare("SELECT account_level, expire_at, created_at, subscription_months, subscription_start_at FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die('用户不存在');
}

if ($deleteAccount) {
    $deleteUser = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
    $deleteUser->bind_param("i", $id);
    $deleteUser->execute();

    header('Location: users.php');
    exit;
}

$expireAt = null;
if ($expireInput !== '') {
    $timestamp = strtotime($expireInput);
    if ($timestamp === false) {
        die('到期时间格式无效');
    }
    $expireAt = date('Y-m-d H:i:s', $timestamp);
}

$accountLevel = $expireAt ? 'monthly' : 'free';
$subscriptionStartAt = $expireAt ? (infer_user_subscription_start_at($user) ?: date('Y-m-d H:i:s')) : null;
$subscriptionMonths = 0;

$upd = $conn->prepare("
    UPDATE users
    SET account_level = ?, max_devices = ?, status = ?, expire_at = ?, subscription_months = ?, subscription_start_at = ?, updated_at = NOW()
    WHERE id = ?
");
$upd->bind_param("siisisi", $accountLevel, $maxDevices, $status, $expireAt, $subscriptionMonths, $subscriptionStartAt, $id);
$upd->execute();

if ($resetDevices) {
    $delDevices = $conn->prepare("DELETE FROM user_devices WHERE user_id = ?");
    $delDevices->bind_param("i", $id);
    $delDevices->execute();

    $disableSessions = $conn->prepare("UPDATE user_sessions SET status = 0, updated_at = NOW() WHERE user_id = ?");
    $disableSessions->bind_param("i", $id);
    $disableSessions->execute();
}

header('Location: users.php');
exit;
