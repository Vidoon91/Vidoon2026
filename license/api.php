<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/functions.php';
require_once __DIR__ . '/include/account_helpers.php';
require_once __DIR__ . '/config.php';

$conn = get_db_connection();

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
    // 历史兼容：email / phone 设了唯一索引时，空字符串 '' 会导致后续注册撞唯一索引。
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
$action = strtolower(safe_trim($data['action'] ?? 'validate'));
$license = safe_trim($data['license_key'] ?? '');
$machine = safe_trim($data['machine_code'] ?? '');
$identifier = safe_trim($data['identifier'] ?? '');
$email = nullable_trim($data['email'] ?? null);
$phone = nullable_trim($data['phone'] ?? null);
$password = (string)($data['password'] ?? '');
$deviceName = safe_trim($data['device_name'] ?? '');
$token = safe_trim($data['token'] ?? '');
$urlCount = max(1, intval($data['url_count'] ?? 1));

if ($action === 'heartbeat') {
    json_err('heartbeat_disabled');
}

if ($action === 'register') {
    cleanup_user_empty_unique_fields($conn);

    // 注册方式明确分离：邮箱注册只写 email，phone 永远 NULL；手机注册只写 phone，email 永远 NULL。
    // 兼容旧客户端：如果没有传 register_type，则根据 email / phone 自动识别。
    $registerType = strtolower(safe_trim($data['register_type'] ?? ''));

    if ($registerType === '') {
        if ($email !== null && $phone === null) {
            $registerType = 'email';
        } elseif ($phone !== null && $email === null) {
            $registerType = 'phone';
        } else {
            json_err('register_type_required');
        }
    }

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
        $emailValue = $email;
        $phoneValue = null;
        $lookupIdentifier = $emailValue;
    } elseif ($registerType === 'phone') {
        if ($phone === null) {
            json_err('phone_required');
        }
        if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            json_err('invalid_phone');
        }
        $emailValue = null;
        $phoneValue = $phone;
        $lookupIdentifier = $phoneValue;
    } else {
        json_err('invalid_register_type');
    }

    $exists = find_user_by_identifier($conn, $lookupIdentifier);
    if ($exists) {
        json_err('account_exists');
    }

    $displayName = safe_trim($data['display_name'] ?? '');
    if ($displayName === '') {
        $displayName = $registerType === 'email' ? explode('@', $emailValue)[0] : $phoneValue;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        INSERT INTO users (
            email, phone, display_name, password_hash, account_level,
            max_devices, expire_at, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'free', 1, NULL, 1, NOW(), NOW())
    ");
    if (!$stmt) {
        json_db_err('prepare_register_failed');
    }

    $stmt->bind_param("ssss", $emailValue, $phoneValue, $displayName, $passwordHash);
    if (!$stmt->execute()) {
        json_db_err('register_failed', $stmt);
    }

    json_ok([
        'status' => 'ok',
        'msg' => 'register_success',
        'register_type' => $registerType,
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

    $level = normalize_account_level($user['account_level'] ?? 'free');
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

    $level = normalize_account_level($row['account_level'] ?? 'free');
    if ($level !== 'free' && !is_user_subscription_active($row)) {
        json_ok([
            'status' => 'expired',
            'valid' => false,
            'msg' => 'subscription_expired',
            'expire_date' => $row['expire_at'] ?? '',
        ]);
    }

    touch_session($conn, intval($row['session_id']), $deviceName);
    bind_user_device($conn, intval($row['id']), $machine, $deviceName);

    json_ok(array_merge([
        'status' => 'ok',
        'valid' => true,
        'msg' => 'account_valid',
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
    if ($level !== 'free' && !is_user_subscription_active($row)) {
        json_ok([
            'status' => 'expired',
            'valid' => false,
            'msg' => 'subscription_expired',
            'expire_date' => $row['expire_at'] ?? '',
        ]);
    }

    $dailyLimit = account_level_daily_limit($level);
    if ($dailyLimit >= 0) {
        $todayCount = get_user_today_download_count($conn, intval($row['id']));
        if (($todayCount + $urlCount) > $dailyLimit) {
            json_ok([
                'status' => 'quota_exceeded',
                'valid' => false,
                'msg' => 'daily_quota_exceeded',
                'today_download_count' => $todayCount,
                'today_download_remaining' => max(0, $dailyLimit - $todayCount),
                'free_daily_limit' => $dailyLimit,
            ]);
        }
    }

    $ins = $conn->prepare("
        INSERT INTO user_download_logs (user_id, machine_code, url_count, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $ins->bind_param("isi", $row['id'], $machine, $urlCount);
    if (!$ins->execute()) {
        json_db_err('download_log_write_failed', $ins);
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

if ($action === 'trial') {
    if (!$machine) {
        json_err('missing_machine_code');
    }

    $stmt = $conn->prepare(
        "SELECT license_key, expire_date, is_trial
         FROM licenses WHERE machine_code=? LIMIT 1"
    );
    $stmt->bind_param("s", $machine);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        json_ok([
            'status' => 'ok',
            'msg' => 'trial_exists',
            'license_key' => $row['license_key'],
            'expire_date' => $row['expire_date'],
            'is_trial' => intval($row['is_trial']),
        ]);
    }

    $key = 'TRIAL-' . generate_license_key(16);
    $expire = date('Y-m-d H:i:s', time() + 86400);

    $stmt = $conn->prepare(
        "INSERT INTO licenses
        (license_key, machine_code, expire_date, active, is_trial, created_at)
        VALUES (?, ?, ?, 1, 1, NOW())"
    );
    $stmt->bind_param("sss", $key, $machine, $expire);
    $stmt->execute();

    json_ok([
        'status' => 'ok',
        'msg' => 'trial_created',
        'license_key' => $key,
        'expire_date' => $expire,
        'is_trial' => 1,
    ]);
}

if ($action === 'validate') {
    if (!$license || !$machine) {
        json_err('missing_validate_params');
    }

    $stmt = $conn->prepare(
        "SELECT license_key, machine_code, expire_date, active, is_trial
         FROM licenses WHERE license_key=? LIMIT 1"
    );
    $stmt->bind_param("s", $license);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        json_err('invalid_license');
    }
    if (!$row['active']) {
        json_err('license_disabled');
    }

    if (!empty($row['expire_date']) && strtotime($row['expire_date']) < time()) {
        json_ok([
            'status' => 'expired',
            'msg' => 'license_expired',
            'expire_date' => $row['expire_date'],
        ]);
    }

    if (!empty($row['machine_code']) && $row['machine_code'] !== $machine) {
        json_err('license_bound_to_other_device');
    }

    if (empty($row['machine_code'])) {
        $upd = $conn->prepare(
            "UPDATE licenses SET machine_code=?, updated_at=NOW()
             WHERE license_key=?"
        );
        $upd->bind_param("ss", $machine, $license);
        $upd->execute();
    }

    json_ok([
        'status' => 'ok',
        'msg' => 'license_valid',
        'expire_date' => $row['expire_date'],
        'is_trial' => intval($row['is_trial']),
    ]);
}

json_err('unknown_action');
