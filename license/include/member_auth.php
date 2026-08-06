<?php

require_once __DIR__ . '/db.php';

function member_session_start() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function member_login_session($userId, $identifier = '') {
    member_session_start();
    session_regenerate_id(true);
    $_SESSION['member_user_id'] = intval($userId);
    $_SESSION['member_identifier'] = trim((string)$identifier);
    $_SESSION['member_login_at'] = time();
}

function member_logout_session() {
    member_session_start();
    unset(
        $_SESSION['member_user_id'],
        $_SESSION['member_identifier'],
        $_SESSION['member_login_at']
    );
    session_regenerate_id(true);
}

function member_current_user(mysqli $conn) {
    member_session_start();
    $userId = intval($_SESSION['member_user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM users
        WHERE id = ? AND status = 1
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        member_logout_session();
        return null;
    }
    return $user;
}

function member_safe_return($value, $default = 'reward.php') {
    $allowed = [
        'reward' => 'reward.php',
        'reward_watch' => 'reward_watch.php',
        'subscribe' => 'subscribe.php',
        'home' => 'index.php',
    ];
    $key = strtolower(trim((string)$value));
    return $allowed[$key] ?? $default;
}

function member_return_key($value, $default = 'reward') {
    $allowed = ['reward', 'reward_watch', 'subscribe', 'home'];
    $key = strtolower(trim((string)$value));
    return in_array($key, $allowed, true) ? $key : $default;
}
