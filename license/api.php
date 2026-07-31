<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/account_helpers.php';
require_once __DIR__ . '/include/smtp_mailer.php';
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/member_auth.php';
require_once __DIR__ . '/config.php';

function json_ok($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $extra = []) {
    echo json_encode(array_merge(['status' => 'error', 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_db_err($msg, $stmt = null) {
    $detail = '';
    if ($stmt && isset($stmt->error) && $stmt->error) {
        $detail = $stmt->error;
    }
    echo json_encode([
        'status' => 'error',
        'msg' => $msg,
        'db_error' => $detail,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function read_input_data() {
    $raw = file_get_contents('php://input');
    $data = $_POST;

    if (!$data && $raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    }

    return is_array($data) ? $data : [];
}

function nullable_trim($value) {
    $value = trim((string)($value ?? ''));
    return $value === '' ? null : $value;
}

function safe_trim($value) {
    return trim((string)($value ?? ''));
}

function cleanup_user_empty_unique_fields(mysqli $conn) {
    // 唯一索引允许多个 NULL，但不允许多个空字符串。
    // MySQL 唯一索引允许多个 NULL，但不允许多个 ''。
    try {
        $conn->query("UPDATE users SET email = NULL WHERE email = ''");
        $conn->query("UPDATE users SET phone = NULL WHERE phone = ''");
    } catch (Throwable $e) {
        // 不阻断接口；如果后续 INSERT/UPDATE 失败，会通过 json_db_err 返回真实数据库错误。
    }
}

function resolve_user_from_token(mysqli $conn, $token) {
    $stmt = $conn->prepare("
        SELECT
            s.id AS session_id,
            s.session_token,
            s.machine_code AS session_machine_code,
            s.status AS session_status,
            s.expires_at,
            u.*
        FROM user_sessions s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.session_token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function touch_session(mysqli $conn, $sessionId, $deviceName = '') {
    $stmt = $conn->prepare("
        UPDATE user_sessions
        SET last_seen_at = NOW(), updated_at = NOW(), device_name = ?, status = 1
        WHERE id = ?
    ");
    $stmt->bind_param("si", $deviceName, $sessionId);
    $stmt->execute();
}

$data = read_input_data();
$action = strtolower(safe_trim($data['action'] ?? ''));
$machine = safe_trim($data['machine_code'] ?? '');
$identifier = safe_trim($data['identifier'] ?? '');
$email = nullable_trim($data['email'] ?? null);
$phone = nullable_trim($data['phone'] ?? null);
$password = (string)($data['password'] ?? '');
$newPassword = (string)($data['new_password'] ?? '');
$verificationCode = safe_trim($data['verification_code'] ?? '');
$verificationPurpose = strtolower(safe_trim($data['purpose'] ?? ''));
$deviceName = safe_trim($data['device_name'] ?? '');
$token = safe_trim($data['token'] ?? '');
$urlCount = max(1, intval($data['url_count'] ?? 1));
$reservationToken = safe_trim($data['reservation_token'] ?? '');
$rewardToken = safe_trim($data['reward_token'] ?? '');
$settledCount = max(1, intval($data['settled_count'] ?? 1));
$successCount = max(0, intval($data['success_count'] ?? 0));
$webContext = intval($data['web_context'] ?? 0) === 1;

if ($action === 'get_public_config') {
    $publicConn = get_db_connection();
    $settingsError = null;
    $subscriptionUrl = 'https://www.muyanshidai.com/';
    if (ensure_app_settings_table($publicConn, $settingsError)) {
        $subscriptionUrl = get_app_setting(
            $publicConn,
            'subscription_url',
            $subscriptionUrl
        );
    }
    $adConfig = get_ad_config($publicConn);
    json_ok([
        'status' => 'ok',
        'subscription_url' => $subscriptionUrl,
        'ad_reward_enabled' => ad_reward_is_ready($adConfig),
        'ad_reward_count' => intval($adConfig['reward_count']),
    ]);
}

if ($action === 'heartbeat') {
    json_err('heartbeat_disabled');
}

$conn = get_db_connection();
sync_user_statuses_by_expiry($conn);

if ($action === 'send_email_code') {
    $emailValue = normalize_verification_email($email ?? $identifier);
    if (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        json_err('invalid_email');
    }
    if (!in_array($verificationPurpose, ['register', 'reset_password'], true)) {
        json_err('invalid_verification_purpose');
    }

    $existingUser = find_user_by_identifier($conn, $emailValue);
    if ($verificationPurpose === 'register' && $existingUser) {
        json_err('account_exists');
    }
    if ($verificationPurpose === 'reset_password' && !$existingUser) {
        usleep(250000);
        json_ok([
            'status' => 'ok',
            'msg' => 'verification_code_sent',
            'retry_after' => 60,
        ]);
    }

    $createError = null;
    $verification = create_email_verification_code(
        $conn,
        $emailValue,
        $verificationPurpose,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $createError
    );
    if (!$verification) {
        json_err($createError ?: 'verification_code_create_failed');
    }

    $mailError = null;
    if (!smtp_send_verification_email($emailValue, $verification['code'], $verificationPurpose, $mailError)) {
        discard_email_verification_code($conn, intval($verification['id']));
        json_err($mailError ?: 'verification_email_send_failed');
    }

    json_ok([
        'status' => 'ok',
        'msg' => 'verification_code_sent',
        'retry_after' => 60,
    ]);
}

if ($action === 'register') {
    cleanup_user_empty_unique_fields($conn);

    $registerType = strtolower(safe_trim($data['register_type'] ?? ''));
    if (strlen($password) < 6) {
        json_err('password_too_short');
    }

    $emailValue = null;
    $phoneValue = null;
    $lookupIdentifier = '';

    if ($registerType === 'email') {
        if ($email === null) {
            json_err('email_required');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_err('invalid_email');
        }
        $emailValue = normalize_verification_email($email);
        $lookupIdentifier = $emailValue;
    } elseif ($registerType === 'phone') {
        json_err('phone_verification_not_available');
    } else {
        json_err('email_verification_only');
    }

    $exists = find_user_by_identifier($conn, $lookupIdentifier);
    if ($exists) {
        json_err('account_exists');
    }

    $verifyError = null;
    $verificationId = verify_email_verification_code(
        $conn,
        $emailValue,
        'register',
        $verificationCode,
        $verifyError
    );
    if ($verificationId <= 0) {
        json_err($verifyError ?: 'invalid_verification_code');
    }

    $displayName = safe_trim($data['display_name'] ?? '');
    if ($displayName === '') {
        $displayName = $emailValue !== null ? explode('@', $emailValue)[0] : $phoneValue;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        INSERT INTO users (
            email, phone, display_name, password_hash, account_level,
            max_devices, free_credit_balance, expire_at, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'free', 1, 3, NULL, 1, NOW(), NOW())
    ");
    if (!$stmt) {
        json_db_err('prepare_register_failed');
    }

    $stmt->bind_param("ssss", $emailValue, $phoneValue, $displayName, $passwordHash);
    if (!$stmt->execute()) {
        json_db_err('register_failed', $stmt);
    }
    if (!consume_email_verification_code($conn, $verificationId)) {
        $deleteUser = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
        $newUserId = intval($stmt->insert_id);
        $deleteUser->bind_param('i', $newUserId);
        $deleteUser->execute();
        json_err('verification_code_already_used');
    }

    $newUserId = intval($stmt->insert_id);
    if ($webContext) {
        member_login_session($newUserId, $emailValue ?? $phoneValue ?? '');
    }

    json_ok([
        'status' => 'ok',
        'msg' => 'register_success',
        'web_authenticated' => $webContext,
    ]);
}

if ($action === 'reset_password') {
    $emailValue = normalize_verification_email($email ?? $identifier);
    if (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
        json_err('invalid_email');
    }
    if (strlen($newPassword) < 6) {
        json_err('password_too_short');
    }

    $user = find_user_by_identifier($conn, $emailValue);
    if (!$user || normalize_verification_email($user['email'] ?? '') !== $emailValue) {
        json_err('account_not_found');
    }

    $verifyError = null;
    $verificationId = verify_email_verification_code(
        $conn,
        $emailValue,
        'reset_password',
        $verificationCode,
        $verifyError
    );
    if ($verificationId <= 0) {
        json_err($verifyError ?: 'invalid_verification_code');
    }

    $conn->begin_transaction();
    try {
        if (!consume_email_verification_code($conn, $verificationId)) {
            throw new RuntimeException('verification_code_already_used');
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updatePassword = $conn->prepare("
            UPDATE users
            SET password_hash = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $userId = intval($user['id']);
        $updatePassword->bind_param('si', $passwordHash, $userId);
        if (!$updatePassword->execute()) {
            throw new RuntimeException('password_reset_failed');
        }

        $disableSessions = $conn->prepare("
            UPDATE user_sessions
            SET status = 0, updated_at = NOW()
            WHERE user_id = ?
        ");
        $disableSessions->bind_param('i', $userId);
        $disableSessions->execute();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_err($e->getMessage() ?: 'password_reset_failed');
    }

    json_ok([
        'status' => 'ok',
        'msg' => 'password_reset_success',
    ]);
}

if ($action === 'login') {
    if ($identifier === '' || $password === '' || $machine === '') {
        json_err('missing_login_params');
    }

    cleanup_user_empty_unique_fields($conn);

    $user = find_user_by_identifier($conn, $identifier);
    if (!$user) {
        json_err('account_not_found');
    }
    if (!password_verify($password, $user['password_hash'])) {
        json_err('invalid_password');
    }
    if (intval($user['status']) !== 1) {
        json_err('account_disabled');
    }

    // A machine can only keep one account active at a time.
    deactivate_machine_for_other_users($conn, $machine, intval($user['id']));

    $stmt = $conn->prepare("
        SELECT id
        FROM user_devices
        WHERE user_id = ? AND machine_code = ? AND status = 1
        LIMIT 1
    ");
    $stmt->bind_param("is", $user['id'], $machine);
    $stmt->execute();
    $deviceRow = $stmt->get_result()->fetch_assoc();

    if (!$deviceRow) {
        $activeDeviceCount = count_active_user_devices($conn, intval($user['id']));
        if ($activeDeviceCount >= intval($user['max_devices'])) {
            json_err('device_limit_reached');
        }
    }

    bind_user_device($conn, intval($user['id']), $machine, $deviceName);

    $sessionToken = generate_api_token(64);
    $sessionExpire = date('Y-m-d H:i:s', strtotime('+30 days'));
    $ins = $conn->prepare("
        INSERT INTO user_sessions (
            user_id, session_token, machine_code, device_name, status,
            expires_at, created_at, updated_at, last_seen_at
        ) VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW(), NOW())
    ");
    $ins->bind_param("issss", $user['id'], $sessionToken, $machine, $deviceName, $sessionExpire);
    $ins->execute();

    $upd = $conn->prepare("UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?");
    $upd->bind_param("i", $user['id']);
    $upd->execute();

    $subscriptionActive = is_user_subscription_active($user);

    json_ok(array_merge([
        'status' => 'ok',
        'msg' => $subscriptionActive ? 'login_success' : 'subscription_expired',
        'valid' => $subscriptionActive,
    ], build_user_auth_payload($conn, $user, $sessionToken)));
}

if ($action === 'validate_account') {
    if ($token === '' || $machine === '') {
        json_err('missing_token_or_machine');
    }

    $row = resolve_user_from_token($conn, $token);
    if (!$row) {
        json_err('invalid_token', ['status' => 'invalid_token']);
    }
    if (intval($row['session_status']) !== 1 || intval($row['status']) !== 1) {
        json_err('account_disabled', ['status' => 'disabled']);
    }
    if ($row['session_machine_code'] !== $machine) {
        json_err('device_mismatch', [
            'status' => 'device_mismatch',
            'msg' => '该设备已切换到其他账号登录',
        ]);
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        json_err('session_expired', ['status' => 'session_expired']);
    }

    touch_session($conn, intval($row['session_id']), $deviceName);
    bind_user_device($conn, intval($row['id']), $machine, $deviceName);

    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'msg' => 'account_valid',
    ], build_user_auth_payload($conn, $row, $token)));
}

if ($action === 'create_ad_reward') {
    if ($token === '' || $machine === '') {
        json_err('missing_token_or_machine');
    }
    $row = resolve_user_from_token($conn, $token);
    if (!$row) {
        json_err('invalid_token', ['status' => 'invalid_token']);
    }
    if (intval($row['session_status']) !== 1 || intval($row['status']) !== 1) {
        json_err('account_disabled', ['status' => 'disabled']);
    }
    if ($row['session_machine_code'] !== $machine) {
        json_err('device_mismatch', ['status' => 'device_mismatch']);
    }
    $userId = intval($row['id']);
    $rewardError = null;
    $rewardDetails = [];
    $rewardSession = create_ad_reward_session(
        $conn,
        $userId,
        $machine,
        $rewardError,
        $rewardDetails
    );
    if (!$rewardSession) {
        $statusMap = [
            'free_reward_disabled' => 'ad_reward_unavailable',
            'ad_reward_daily_limit_reached' => 'ad_reward_daily_limit',
            'ad_reward_cooldown_active' => 'ad_reward_cooldown',
            'paid_subscription_not_eligible' => 'paid_subscription_not_eligible',
        ];
        json_ok(array_merge([
            'status' => $statusMap[$rewardError] ?? 'ad_reward_error',
            'valid' => false,
            'msg' => $rewardError ?: 'ad_reward_session_create_failed',
        ], $rewardDetails));
    }

    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'msg' => 'ad_reward_session_created',
    ], $rewardSession));
}

if ($action === 'ad_reward_status') {
    if ($token === '' || $machine === '' || $rewardToken === '') {
        json_err('missing_ad_reward_status_params');
    }
    $row = resolve_user_from_token($conn, $token);
    if (!$row || $row['session_machine_code'] !== $machine) {
        json_err('invalid_token', ['status' => 'invalid_token']);
    }
    $rewardTokenHash = ad_reward_token_hash($rewardToken);
    $userId = intval($row['id']);
    $accountLevel = normalize_account_level($row['account_level'] ?? 'free');
    if ($accountLevel !== 'free') {
        json_ok([
            'status' => 'paid_subscription_not_eligible',
            'valid' => false,
            'msg' => 'paid_subscription_not_eligible',
            'account_level' => $accountLevel,
        ]);
    }
    $stmt = $conn->prepare("
        SELECT status, reward_count, expires_at, granted_at
        FROM ad_reward_sessions
        WHERE reward_token_hash = ? AND user_id = ? AND machine_code = ?
        LIMIT 1
    ");
    $stmt->bind_param('sis', $rewardTokenHash, $userId, $machine);
    $stmt->execute();
    $rewardSession = $stmt->get_result()->fetch_assoc();
    if (!$rewardSession) {
        json_err('ad_reward_session_not_found');
    }
    if ($rewardSession['status'] === 'pending' && strtotime($rewardSession['expires_at']) < time()) {
        $expire = $conn->prepare("
            UPDATE ad_reward_sessions
            SET status = 'expired', updated_at = NOW()
            WHERE reward_token_hash = ?
        ");
        $expire->bind_param('s', $rewardTokenHash);
        $expire->execute();
        $rewardSession['status'] = 'expired';
    }

    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'reward_status' => $rewardSession['status'],
        'reward_count' => intval($rewardSession['reward_count']),
        'granted_at' => $rewardSession['granted_at'] ?? '',
    ], build_user_auth_payload($conn, $row, $token)));
}

if ($action === 'reserve_download') {
    if ($token === '' || $machine === '') {
        json_err('missing_token_or_machine');
    }

    $row = resolve_user_from_token($conn, $token);
    if (!$row) {
        json_err('invalid_token', ['status' => 'invalid_token']);
    }
    if (intval($row['session_status']) !== 1 || intval($row['status']) !== 1) {
        json_err('account_disabled', ['status' => 'disabled']);
    }
    if ($row['session_machine_code'] !== $machine) {
        json_err('device_mismatch', [
            'status' => 'device_mismatch',
            'msg' => '该设备已切换到其他账号登录',
        ]);
    }

    $level = normalize_account_level($row['account_level'] ?? 'free');
    $userId = intval($row['id']);
    $quotaMode = $level === 'free' ? 'free' : 'paid';
    $perTaskLimit = account_level_per_task_limit($level);
    $dailyLimit = account_level_daily_limit($level);
    $todayAdRewardCount = get_user_today_ad_reward_count($conn, $userId);
    $freeCreditBalance = max(0, intval($row['free_credit_balance'] ?? 3));
    $effectiveDailyLimit = $level === 'free'
        ? $freeCreditBalance
        : $dailyLimit;
    if ($perTaskLimit >= 0 && $urlCount > $perTaskLimit) {
        json_ok([
            'status' => 'task_limit_exceeded',
            'valid' => false,
            'msg' => 'task_download_limit_exceeded',
            'requested_url_count' => $urlCount,
            'per_task_limit' => $perTaskLimit,
            'daily_download_limit' => $dailyLimit,
            'effective_daily_download_limit' => $effectiveDailyLimit,
            'free_daily_limit' => $dailyLimit,
        ]);
    }

    $newReservationToken = bin2hex(random_bytes(32));
    $reservationExpiresAt = date('Y-m-d H:i:s', strtotime('+12 hours'));
    $conn->begin_transaction();
    try {
        // Lock the account so concurrent clients cannot reserve beyond the daily limit.
        $lock = $conn->prepare("SELECT id, free_credit_balance FROM users WHERE id = ? FOR UPDATE");
        $lock->bind_param("i", $userId);
        if (!$lock->execute() || !($lockedUser = $lock->get_result()->fetch_assoc())) {
            throw new RuntimeException('account_not_found');
        }

        $todayCount = get_user_today_download_count($conn, $userId);
        $activeReservedCount = get_user_active_reserved_download_count($conn, $userId, $quotaMode);
        if ($level === 'free') {
            $freeCreditBalance = max(0, intval($lockedUser['free_credit_balance'] ?? 0));
            $effectiveDailyLimit = $freeCreditBalance;
            $quotaExceeded = ($activeReservedCount + $urlCount) > $freeCreditBalance;
        } else {
            $quotaExceeded = $effectiveDailyLimit >= 0
                && ($todayCount + $activeReservedCount + $urlCount) > $effectiveDailyLimit;
        }
        if ($quotaExceeded) {
            $conn->rollback();
            json_ok([
                'status' => 'quota_exceeded',
                'valid' => false,
                'msg' => $level === 'free' ? 'free_credit_exhausted' : 'daily_quota_exceeded',
                'requested_url_count' => $urlCount,
                'per_task_limit' => $perTaskLimit,
                'today_download_count' => $todayCount,
                'today_download_reserved' => $activeReservedCount,
                'today_download_remaining' => $level === 'free'
                    ? max(0, $freeCreditBalance - $activeReservedCount)
                    : max(0, $effectiveDailyLimit - $todayCount - $activeReservedCount),
                'daily_download_limit' => $dailyLimit,
                'effective_daily_download_limit' => $effectiveDailyLimit,
                'free_credit_balance' => $freeCreditBalance,
                'quota_mode' => $level === 'free' ? 'credit' : 'daily',
                'today_ad_reward_count' => $todayAdRewardCount,
                'free_daily_limit' => $dailyLimit,
            ]);
        }

        $ins = $conn->prepare("
            INSERT INTO user_download_reservations (
                reservation_token, user_id, machine_code, reserved_count,
                settled_count, success_count, quota_mode, status, expires_at,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, 0, 0, ?, 'pending', ?, NOW(), NOW())
        ");
        $ins->bind_param(
            "sisiss",
            $newReservationToken,
            $userId,
            $machine,
            $urlCount,
            $quotaMode,
            $reservationExpiresAt
        );
        if (!$ins->execute()) {
            throw new RuntimeException('download_reservation_write_failed');
        }
        $row['free_credit_balance'] = $freeCreditBalance;
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_err($e->getMessage() ?: 'download_reservation_write_failed');
    }

    touch_session($conn, intval($row['session_id']), $deviceName);
    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'msg' => 'download_quota_reserved',
        'reservation_token' => $newReservationToken,
        'reserved_count' => $urlCount,
        'reservation_expires_at' => $reservationExpiresAt,
    ], build_user_auth_payload($conn, $row, $token)));
}

if ($action === 'settle_download') {
    if ($token === '' || $machine === '' || $reservationToken === '') {
        json_err('missing_download_settlement_params');
    }
    if ($successCount > $settledCount) {
        json_err('invalid_download_settlement_counts');
    }

    $row = resolve_user_from_token($conn, $token);
    if (!$row) {
        json_err('invalid_token', ['status' => 'invalid_token']);
    }
    if ($row['session_machine_code'] !== $machine) {
        json_err('device_mismatch', [
            'status' => 'device_mismatch',
            'msg' => '该设备已切换到其他账号登录',
        ]);
    }

    $userId = intval($row['id']);
    $newSettledCount = 0;
    $newSuccessCount = 0;
    $reservationStatus = 'pending';
    $conn->begin_transaction();
    try {
        $userLock = $conn->prepare("
            SELECT id, free_credit_balance
            FROM users
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $userLock->bind_param("i", $userId);
        $userLock->execute();
        $lockedUser = $userLock->get_result()->fetch_assoc();
        if (!$lockedUser) {
            throw new RuntimeException('account_not_found');
        }

        $reservationStmt = $conn->prepare("
            SELECT id, reserved_count, settled_count, success_count,
                   quota_mode, status, expires_at
            FROM user_download_reservations
            WHERE reservation_token = ? AND user_id = ? AND machine_code = ?
            LIMIT 1
            FOR UPDATE
        ");
        $reservationStmt->bind_param("sis", $reservationToken, $userId, $machine);
        $reservationStmt->execute();
        $reservation = $reservationStmt->get_result()->fetch_assoc();
        if (!$reservation) {
            throw new RuntimeException('download_reservation_not_found');
        }
        if ($reservation['status'] === 'expired' || strtotime($reservation['expires_at']) < time()) {
            $expireStmt = $conn->prepare("
                UPDATE user_download_reservations
                SET status = 'expired', updated_at = NOW()
                WHERE id = ?
            ");
            $reservationId = intval($reservation['id']);
            $expireStmt->bind_param("i", $reservationId);
            $expireStmt->execute();
            $conn->commit();
            json_err('download_reservation_expired', ['status' => 'reservation_expired']);
        }

        $reservedCount = intval($reservation['reserved_count']);
        $previousSettledCount = intval($reservation['settled_count']);
        $previousSuccessCount = intval($reservation['success_count']);
        $remainingReservationCount = max(0, $reservedCount - $previousSettledCount);
        if ($settledCount > $remainingReservationCount) {
            throw new RuntimeException('download_reservation_settlement_exceeded');
        }

        if ($successCount > 0) {
            if (($reservation['quota_mode'] ?? 'paid') === 'free') {
                $creditStmt = $conn->prepare("
                    UPDATE users
                    SET free_credit_balance = free_credit_balance - ?,
                        updated_at = NOW()
                    WHERE id = ? AND free_credit_balance >= ?
                ");
                $creditStmt->bind_param("iii", $successCount, $userId, $successCount);
                $creditStmt->execute();
                if ($creditStmt->affected_rows !== 1) {
                    throw new RuntimeException('free_credit_exhausted');
                }
                $row['free_credit_balance'] = max(
                    0,
                    intval($lockedUser['free_credit_balance'] ?? 0) - $successCount
                );
            }

            $logStmt = $conn->prepare("
                INSERT INTO user_download_logs (user_id, machine_code, url_count, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $logStmt->bind_param("isi", $userId, $machine, $successCount);
            if (!$logStmt->execute()) {
                throw new RuntimeException('download_log_write_failed');
            }
        }

        $newSettledCount = $previousSettledCount + $settledCount;
        $newSuccessCount = $previousSuccessCount + $successCount;
        $reservationStatus = $newSettledCount >= $reservedCount ? 'completed' : 'pending';
        $updateStmt = $conn->prepare("
            UPDATE user_download_reservations
            SET settled_count = ?, success_count = ?, status = ?,
                settled_at = CASE WHEN ? = 'completed' THEN NOW() ELSE settled_at END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $reservationId = intval($reservation['id']);
        $updateStmt->bind_param(
            "iissi",
            $newSettledCount,
            $newSuccessCount,
            $reservationStatus,
            $reservationStatus,
            $reservationId
        );
        if (!$updateStmt->execute()) {
            throw new RuntimeException('download_reservation_update_failed');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_err($e->getMessage() ?: 'download_settlement_failed');
    }

    touch_session($conn, intval($row['session_id']), $deviceName);
    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'msg' => $successCount > 0 ? 'download_success_recorded' : 'download_failure_released',
        'reservation_status' => $reservationStatus,
        'reservation_settled_count' => $newSettledCount,
        'reservation_success_count' => $newSuccessCount,
    ], build_user_auth_payload($conn, $row, $token)));
}

if ($action === 'consume_download') {
    if ($token === '' || $machine === '') {
        json_err('missing_token_or_machine');
    }

    $row = resolve_user_from_token($conn, $token);
    if (!$row) {
        json_err('invalid_token', ['status' => 'invalid_token']);
    }
    if (intval($row['session_status']) !== 1 || intval($row['status']) !== 1) {
        json_err('account_disabled');
    }
    if ($row['session_machine_code'] !== $machine) {
        json_err('device_mismatch', [
            'status' => 'device_mismatch',
            'msg' => '该设备已切换到其他账号登录',
        ]);
    }

    $level = normalize_account_level($row['account_level'] ?? 'free');
    $userId = intval($row['id']);
    $quotaMode = $level === 'free' ? 'free' : 'paid';
    $perTaskLimit = account_level_per_task_limit($level);
    $dailyLimit = account_level_daily_limit($level);
    $todayAdRewardCount = get_user_today_ad_reward_count($conn, $userId);
    $freeCreditBalance = max(0, intval($row['free_credit_balance'] ?? 3));
    $effectiveDailyLimit = $level === 'free'
        ? $freeCreditBalance
        : $dailyLimit;

    if ($perTaskLimit >= 0 && $urlCount > $perTaskLimit) {
        json_ok([
            'status' => 'task_limit_exceeded',
            'valid' => false,
            'msg' => 'task_download_limit_exceeded',
            'requested_url_count' => $urlCount,
            'per_task_limit' => $perTaskLimit,
            'daily_download_limit' => $dailyLimit,
            'effective_daily_download_limit' => $effectiveDailyLimit,
            'free_daily_limit' => $dailyLimit,
        ]);
    }

    $conn->begin_transaction();
    try {
        // Serialize quota consumption for one account to prevent parallel bypass.
        $lock = $conn->prepare("SELECT id, free_credit_balance FROM users WHERE id = ? FOR UPDATE");
        $lock->bind_param("i", $userId);
        if (!$lock->execute() || !($lockedUser = $lock->get_result()->fetch_assoc())) {
            throw new RuntimeException('account_not_found');
        }

        $todayCount = get_user_today_download_count($conn, $userId);
        $activeReservedCount = get_user_active_reserved_download_count($conn, $userId, $quotaMode);
        if ($level === 'free') {
            $freeCreditBalance = max(0, intval($lockedUser['free_credit_balance'] ?? 0));
            $effectiveDailyLimit = $freeCreditBalance;
            $quotaExceeded = ($activeReservedCount + $urlCount) > $freeCreditBalance;
        } else {
            $quotaExceeded = $effectiveDailyLimit >= 0
                && ($todayCount + $activeReservedCount + $urlCount) > $effectiveDailyLimit;
        }
        if ($quotaExceeded) {
            $conn->rollback();
            json_ok([
                'status' => 'quota_exceeded',
                'valid' => false,
                'msg' => $level === 'free' ? 'free_credit_exhausted' : 'daily_quota_exceeded',
                'requested_url_count' => $urlCount,
                'per_task_limit' => $perTaskLimit,
                'today_download_count' => $todayCount,
                'today_download_reserved' => $activeReservedCount,
                'today_download_remaining' => $level === 'free'
                    ? max(0, $freeCreditBalance - $activeReservedCount)
                    : max(0, $effectiveDailyLimit - $todayCount - $activeReservedCount),
                'daily_download_limit' => $dailyLimit,
                'effective_daily_download_limit' => $effectiveDailyLimit,
                'free_credit_balance' => $freeCreditBalance,
                'quota_mode' => $level === 'free' ? 'credit' : 'daily',
                'today_ad_reward_count' => $todayAdRewardCount,
                'free_daily_limit' => $dailyLimit,
            ]);
        }

        if ($level === 'free') {
            $creditStmt = $conn->prepare("
                UPDATE users
                SET free_credit_balance = free_credit_balance - ?,
                    updated_at = NOW()
                WHERE id = ? AND free_credit_balance >= ?
            ");
            $creditStmt->bind_param("iii", $urlCount, $userId, $urlCount);
            $creditStmt->execute();
            if ($creditStmt->affected_rows !== 1) {
                throw new RuntimeException('free_credit_exhausted');
            }
            $freeCreditBalance -= $urlCount;
            $row['free_credit_balance'] = $freeCreditBalance;
        }

        $ins = $conn->prepare("
            INSERT INTO user_download_logs (user_id, machine_code, url_count, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $ins->bind_param("isi", $userId, $machine, $urlCount);
        if (!$ins->execute()) {
            throw new RuntimeException('download_log_write_failed');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        json_err($e->getMessage() ?: 'download_log_write_failed');
    }

    touch_session($conn, intval($row['session_id']), $deviceName);

    $payload = build_user_auth_payload($conn, $row, $token);
    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'msg' => $dailyLimit >= 0 ? 'download_count_recorded' : 'subscription_ok',
    ], $payload));
}

if ($action === 'logout_account') {
    if ($token === '') {
        json_err('missing_token');
    }

    $stmt = $conn->prepare("UPDATE user_sessions SET status = 0, updated_at = NOW() WHERE session_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();

    json_ok([
        'status' => 'ok',
        'msg' => 'logout_success',
    ]);
}

json_err('unknown_action');
