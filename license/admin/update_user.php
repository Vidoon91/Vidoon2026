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
$scope = trim((string)($_POST['action_scope'] ?? 'all'));
if (!in_array($scope, ['all', 'time', 'device', 'delete'], true)) {
    $scope = 'all';
}
$expireInput = trim((string)($_POST['expire_at'] ?? ''));
$maxDevices = max(1, intval($_POST['max_devices'] ?? 1));
$resetDevices = intval($_POST['reset_devices'] ?? 0) === 1;
$deleteAccount = intval($_POST['delete_account'] ?? 0) === 1;

if ($id <= 0) {
    die('用户 ID 无效');
}

$stmt = $conn->prepare("SELECT account_level, max_devices, expire_at, created_at, subscription_months, subscription_start_at FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die('用户不存在');
}

if (($scope === 'all' || $scope === 'delete') && $deleteAccount) {
    $deleteUser = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
    $deleteUser->bind_param("i", $id);
    $deleteUser->execute();

    header('Location: users.php');
    exit;
}

if ($scope === 'delete') {
    header('Location: users.php');
    exit;
}

if ($scope === 'time' || $scope === 'all') {
    $expireAt = null;
    if ($expireInput !== '') {
        $timestamp = strtotime($expireInput);
        if ($timestamp === false) {
            die('到期时间格式无效');
        }
        $expireAt = date('Y-m-d H:i:s', $timestamp);
    }

    $isExpired = $expireAt !== null && strtotime($expireAt) < time();
    $currentLevel = normalize_account_level($user['account_level'] ?? 'free');
    $accountLevel = ($expireAt === null || $isExpired)
        ? 'free'
        : (in_array($currentLevel, ['monthly', 'semiannual', 'annual'], true)
            ? $currentLevel
            : 'monthly');
    if ($accountLevel === 'free') {
        $expireAt = null;
    }
    $status = 1;
    $subscriptionStartAt = $accountLevel === 'monthly'
        ? (infer_user_subscription_start_at($user) ?: date('Y-m-d H:i:s'))
        : null;
    $subscriptionMonths = 0;
    $timeMaxDevices = $accountLevel === 'free'
        ? 1
        : max(1, intval($user['max_devices'] ?? 1));

    $updTime = $conn->prepare("
        UPDATE users
        SET account_level = ?, status = ?, expire_at = ?, subscription_months = ?,
            subscription_start_at = ?, max_devices = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updTime->bind_param(
        "sisisii",
        $accountLevel,
        $status,
        $expireAt,
        $subscriptionMonths,
        $subscriptionStartAt,
        $timeMaxDevices,
        $id
    );
    $updTime->execute();
}

if ($scope === 'device' || $scope === 'all') {
    if ($scope === 'all' && isset($accountLevel) && $accountLevel === 'free') {
        $maxDevices = 1;
    }
    $updDevice = $conn->prepare("UPDATE users SET max_devices = ?, updated_at = NOW() WHERE id = ?");
    $updDevice->bind_param("ii", $maxDevices, $id);
    $updDevice->execute();
}

if (($scope === 'device' || $scope === 'all') && $resetDevices) {
    $delDevices = $conn->prepare("DELETE FROM user_devices WHERE user_id = ?");
    $delDevices->bind_param("i", $id);
    $delDevices->execute();

    $disableSessions = $conn->prepare("UPDATE user_sessions SET status = 0, updated_at = NOW() WHERE user_id = ?");
    $disableSessions->bind_param("i", $id);
    $disableSessions->execute();
}

header('Location: users.php');
exit;
