<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account_helpers.php';

function ad_config_defaults() {
    return [
        'display_enabled' => false,
        'reward_enabled' => true,
        'left_code' => '',
        'right_code' => '',
        'home_left_code' => '',
        'home_right_code' => '',
        'home_download_code' => '',
        'home_bottom_code' => '',
        'publisher_id' => '',
        'ad_manager_network_code' => '',
        'rewarded_ad_unit_path' => '',
        'reward_count' => 3,
        'daily_view_limit' => 5,
        'cooldown_seconds' => 120,
    ];
}

function get_ad_config(mysqli $conn) {
    $defaults = ad_config_defaults();
    $error = null;
    if (!ensure_app_settings_table($conn, $error)) {
        return $defaults;
    }

    $legacyEnabled = get_app_setting($conn, 'ads_enabled', '0');

    return [
        // Preserve the existing ordinary-ad state while keeping rewarded ads off
        // until they are explicitly enabled after demand becomes available.
        'display_enabled' => get_app_setting($conn, 'ads_display_enabled', $legacyEnabled) === '1',
        'reward_enabled' => get_app_setting($conn, 'free_reward_enabled', '1') === '1',
        'left_code' => get_app_setting($conn, 'ads_left_code', ''),
        'right_code' => get_app_setting($conn, 'ads_right_code', ''),
        'home_left_code' => get_app_setting(
            $conn,
            'ads_home_left_code',
            get_app_setting($conn, 'ads_home_top_code', '')
        ),
        'home_right_code' => get_app_setting(
            $conn,
            'ads_home_right_code',
            get_app_setting($conn, 'ads_home_middle_code', '')
        ),
        'home_download_code' => get_app_setting($conn, 'ads_home_download_code', ''),
        'home_bottom_code' => get_app_setting($conn, 'ads_home_bottom_code', ''),
        'publisher_id' => get_app_setting($conn, 'ads_publisher_id', ''),
        'ad_manager_network_code' => get_app_setting($conn, 'ads_manager_network_code', ''),
        'rewarded_ad_unit_path' => get_app_setting($conn, 'ads_rewarded_unit_path', ''),
        'reward_count' => max(1, min(100, intval(get_app_setting(
            $conn,
            'ads_reward_count',
            (string)$defaults['reward_count']
        )))),
        'daily_view_limit' => max(1, min(50, intval(get_app_setting(
            $conn,
            'ads_daily_view_limit',
            (string)$defaults['daily_view_limit']
        )))),
        'cooldown_seconds' => max(30, min(86400, intval(get_app_setting(
            $conn,
            'ads_cooldown_seconds',
            (string)$defaults['cooldown_seconds']
        )))),
    ];
}

function ad_display_is_enabled(array $config) {
    return !empty($config['display_enabled']);
}

function ad_reward_is_ready(array $config) {
    return !empty($config['reward_enabled']);
}

function ad_reward_public_url($token) {
    $baseUrl = rtrim((string)get_runtime_site_value(
        'base_url',
        'https://license.muyanshidai.com/'
    ), '/');
    return $baseUrl . '/reward_entry.php?token=' . rawurlencode((string)$token);
}

function ad_reward_user_is_eligible(mysqli $conn, $userId, &$accountLevel = null) {
    $userId = intval($userId);
    $accountLevel = 'free';
    if ($userId <= 0) {
        return false;
    }
    sync_user_statuses_by_expiry($conn);
    $stmt = $conn->prepare("
        SELECT account_level, status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || intval($user['status']) !== 1) {
        return false;
    }
    $accountLevel = normalize_account_level($user['account_level'] ?? 'free');
    return $accountLevel === 'free';
}

function create_ad_reward_session(
    mysqli $conn,
    $userId,
    $machineCode = '',
    &$errorCode = null,
    &$details = null
) {
    $errorCode = null;
    $details = [];
    $userId = intval($userId);
    $machineCode = substr(trim((string)$machineCode), 0, 128);
    if ($userId <= 0) {
        $errorCode = 'account_not_found';
        return null;
    }
    $accountLevel = 'free';
    if (!ad_reward_user_is_eligible($conn, $userId, $accountLevel)) {
        $errorCode = $accountLevel === 'free'
            ? 'account_not_found'
            : 'paid_subscription_not_eligible';
        $details = ['account_level' => $accountLevel];
        return null;
    }

    $config = get_ad_config($conn);
    if (!ad_reward_is_ready($config)) {
        $errorCode = 'free_reward_disabled';
        return null;
    }

    $todayClaimCount = ad_reward_today_claim_count($conn, $userId);
    $dailyLimit = intval($config['daily_view_limit']);
    if ($todayClaimCount >= $dailyLimit) {
        $errorCode = 'ad_reward_daily_limit_reached';
        $details = [
            'today_reward_views' => $todayClaimCount,
            'daily_reward_view_limit' => $dailyLimit,
        ];
        return null;
    }

    $lastGrantedAt = ad_reward_last_granted_at($conn, $userId);
    $cooldownRemaining = 0;
    if ($lastGrantedAt !== '') {
        $cooldownRemaining = max(
            0,
            intval($config['cooldown_seconds']) - (time() - strtotime($lastGrantedAt))
        );
    }
    if ($cooldownRemaining > 0) {
        $errorCode = 'ad_reward_cooldown_active';
        $details = ['cooldown_remaining' => $cooldownRemaining];
        return null;
    }

    $conn->query("
        UPDATE ad_reward_sessions
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'pending' AND expires_at < NOW()
    ");

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = ad_reward_token_hash($rawToken);
    $rewardCount = intval($config['reward_count']);
    $requestIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $stmt = $conn->prepare("
        INSERT INTO ad_reward_sessions (
            reward_token_hash, user_id, machine_code, reward_count, status,
            request_ip, expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW())
    ");
    if (!$stmt) {
        $errorCode = 'ad_reward_session_create_failed';
        return null;
    }
    $stmt->bind_param(
        'sisiss',
        $tokenHash,
        $userId,
        $machineCode,
        $rewardCount,
        $requestIp,
        $expiresAt
    );
    if (!$stmt->execute()) {
        $errorCode = 'ad_reward_session_create_failed';
        return null;
    }

    return [
        'reward_token' => $rawToken,
        'reward_url' => ad_reward_public_url($rawToken),
        'reward_count' => $rewardCount,
        'expires_at' => $expiresAt,
    ];
}

function ad_reward_token_hash($token) {
    return hash('sha256', trim((string)$token));
}

function ad_reward_today_claim_count(mysqli $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM ad_reward_claims
        WHERE user_id = ? AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return max(0, intval($row['total'] ?? 0));
}

function ad_reward_last_granted_at(mysqli $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT MAX(created_at) AS granted_at
        FROM ad_reward_claims
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return trim((string)($row['granted_at'] ?? ''));
}
