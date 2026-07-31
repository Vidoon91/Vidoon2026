<?php
require_once __DIR__ . '/include/ad_helpers.php';

$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: index.php#free-reward');
    exit;
}

$conn = get_db_connection();
$tokenHash = ad_reward_token_hash($token);
$stmt = $conn->prepare("
    SELECT id
    FROM ad_reward_sessions
    WHERE reward_token_hash = ?
      AND status = 'pending'
      AND expires_at >= NOW()
    LIMIT 1
");
$stmt->bind_param('s', $tokenHash);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    header('Location: index.php?reward=invalid#free-reward');
    exit;
}

setcookie('vidoon_free_reward', $token, [
    'expires' => time() + 600,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Cache-Control: no-store');
header('Location: reward.php');
exit;
