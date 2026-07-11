<?php

$runtimeSettingsPath = __DIR__ . '/runtime_settings.php';
if (file_exists($runtimeSettingsPath)) {
    require_once $runtimeSettingsPath;
}

if (!function_exists('get_runtime_settings_defaults')) {
    function get_runtime_settings_defaults() {
        return [
            'site' => [
                'base_url' => 'https://license.muyanshidai.com/',
            ],
            'account_levels' => [
                'free' => [
                    'label' => '免费订阅',
                    'days' => 0,
                    'daily_limit' => 3,
                ],
                'monthly' => [
                    'label' => '月订阅',
                    'days' => 30,
                    'daily_limit' => 100,
                ],
                'semiannual' => [
                    'label' => '半年订阅',
                    'days' => 183,
                    'daily_limit' => -1,
                ],
                'annual' => [
                    'label' => '年订阅',
                    'days' => 365,
                    'daily_limit' => -1,
                ],
            ],
        ];
    }
}

if (!function_exists('normalize_runtime_settings')) {
    function normalize_runtime_settings($settings, $defaults) {
        if (!is_array($settings)) {
            return $defaults;
        }

        if (!isset($settings['site']) || !is_array($settings['site'])) {
            $settings['site'] = $defaults['site'];
        }
        if (!isset($settings['site']['base_url']) || !is_string($settings['site']['base_url']) || trim($settings['site']['base_url']) === '') {
            $settings['site']['base_url'] = $defaults['site']['base_url'];
        }

        if (!isset($settings['account_levels']) || !is_array($settings['account_levels'])) {
            $settings['account_levels'] = $defaults['account_levels'];
        }

        foreach ($defaults['account_levels'] as $level => $defaultConfig) {
            if (!isset($settings['account_levels'][$level]) || !is_array($settings['account_levels'][$level])) {
                $settings['account_levels'][$level] = $defaultConfig;
                continue;
            }

            $config = $settings['account_levels'][$level];
            if (!isset($config['label']) || !is_string($config['label']) || trim($config['label']) === '') {
                $config['label'] = $defaultConfig['label'];
            }
            if (!isset($config['days']) || !is_numeric($config['days'])) {
                $config['days'] = $defaultConfig['days'];
            } else {
                $config['days'] = intval($config['days']);
            }
            if (!isset($config['daily_limit']) || !is_numeric($config['daily_limit'])) {
                $config['daily_limit'] = $defaultConfig['daily_limit'];
            } else {
                $config['daily_limit'] = intval($config['daily_limit']);
            }

            $settings['account_levels'][$level] = $config;
        }

        return $settings;
    }
}

if (!function_exists('get_runtime_settings')) {
    function get_runtime_settings() {
        static $settings = null;
        if ($settings !== null) {
            return $settings;
        }

        $defaults = get_runtime_settings_defaults();

        /*
         * 配置优先级说明：
         * 1. account_helpers.php 中的 defaults 是兜底默认配置。
         * 2. 项目根目录 runtime_settings.json 如果存在，会作为运行时覆盖配置。
         * 3. JSON 缺失字段或字段类型异常时，会回退到 defaults 对应字段。
         *
         * 如果 runtime_settings.json 存在，其配置会覆盖 defaults。
         * 注意：线上如果存在 runtime_settings.json，只修改 defaults 不会覆盖
         * JSON 中已有的同名配置；需要同步修改 runtime_settings.json。
         */
        $settingsPath = dirname(__DIR__) . '/runtime_settings.json';
        if (!file_exists($settingsPath)) {
            $settings = normalize_runtime_settings($defaults, $defaults);
            return $settings;
        }

        $raw = file_get_contents($settingsPath);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $settings = normalize_runtime_settings($defaults, $defaults);
            return $settings;
        }

        $merged = array_replace_recursive($defaults, $decoded);
        $settings = normalize_runtime_settings($merged, $defaults);
        return $settings;
    }
}

if (!function_exists('get_runtime_site_value')) {
    function get_runtime_site_value($key, $default = null) {
        $settings = get_runtime_settings();
        $site = $settings['site'] ?? [];
        return $site[$key] ?? $default;
    }
}

if (!function_exists('get_account_level_runtime_config')) {
    function get_account_level_runtime_config($level) {
        $settings = get_runtime_settings();
        $levels = $settings['account_levels'] ?? [];
        return $levels[$level] ?? ($levels['free'] ?? []);
    }
}

function normalize_account_level($level) {
    $allowed = ['free', 'monthly', 'semiannual', 'annual'];
    return in_array($level, $allowed, true) ? $level : 'free';
}

function account_level_label($level) {
    $config = get_account_level_runtime_config(normalize_account_level($level));
    return $config['label'] ?? '免费订阅';
}

function account_level_days($level) {
    $config = get_account_level_runtime_config(normalize_account_level($level));
    return intval($config['days'] ?? 0);
}

function account_level_daily_limit($level) {
    $config = get_account_level_runtime_config(normalize_account_level($level));
    $defaults = get_runtime_settings_defaults();
    return intval($config['daily_limit'] ?? $defaults['account_levels']['free']['daily_limit']);
}

function normalize_subscription_months($months) {
    return max(0, intval($months));
}

function account_level_by_subscription_months($months) {
    $months = normalize_subscription_months($months);
    if ($months <= 0) {
        return 'free';
    }
    if ($months >= 12) {
        return 'annual';
    }
    if ($months >= 6) {
        return 'semiannual';
    }
    return 'monthly';
}

function default_subscription_months_for_level($level) {
    $level = normalize_account_level($level);
    if ($level === 'annual') {
        return 12;
    }
    if ($level === 'semiannual') {
        return 6;
    }
    if ($level === 'monthly') {
        return 1;
    }
    return 0;
}

function compute_user_expire_at_by_months($months, $baseDate = null) {
    $months = normalize_subscription_months($months);
    if ($months <= 0) {
        return null;
    }

    $base = $baseDate ? strtotime($baseDate) : time();
    return date('Y-m-d H:i:s', strtotime("+{$months} months", $base));
}

function infer_user_subscription_months($user) {
    $months = intval($user['subscription_months'] ?? 0);
    if ($months > 0) {
        return $months;
    }

    return default_subscription_months_for_level($user['account_level'] ?? 'free');
}

function infer_user_subscription_start_at($user) {
    $startAt = trim((string)($user['subscription_start_at'] ?? ''));
    if ($startAt !== '' && $startAt !== '0000-00-00 00:00:00') {
        return $startAt;
    }

    $expireAt = trim((string)($user['expire_at'] ?? ''));
    $months = infer_user_subscription_months($user);
    if ($expireAt !== '' && $expireAt !== '0000-00-00 00:00:00' && $months > 0) {
        return date('Y-m-d H:i:s', strtotime("-{$months} months", strtotime($expireAt)));
    }

    return null;
}

function ensure_user_subscription_columns(mysqli $conn) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $hasMonths = false;
    $res = $conn->query("SHOW COLUMNS FROM users LIKE 'subscription_months'");
    if ($res && $res->num_rows > 0) {
        $hasMonths = true;
    }

    if (!$hasMonths) {
        $conn->query("ALTER TABLE users ADD COLUMN subscription_months INT NOT NULL DEFAULT 0 AFTER expire_at");
    }

    $hasStartAt = false;
    $res = $conn->query("SHOW COLUMNS FROM users LIKE 'subscription_start_at'");
    if ($res && $res->num_rows > 0) {
        $hasStartAt = true;
    }

    if (!$hasStartAt) {
        $conn->query("ALTER TABLE users ADD COLUMN subscription_start_at DATETIME DEFAULT NULL AFTER subscription_months");
    }

    $ensured = true;
}

function ensure_account_schema(mysqli $conn, &$errorMessage = null) {
    static $ensured = null;
    static $lastError = null;

    if ($ensured !== null) {
        $errorMessage = $lastError;
        return $ensured;
    }

    $run = function ($sql, $context) use ($conn, &$lastError) {
        if ($conn->query($sql) === false) {
            $lastError = $context . ': ' . $conn->error;
            return false;
        }
        return true;
    };

    $tableExists = function ($tableName) use ($conn) {
        $safeName = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safeName}'");
        return $res && $res->num_rows > 0;
    };

    $columnExists = function ($tableName, $columnName) use ($conn) {
        $safeTable = $conn->real_escape_string($tableName);
        $safeColumn = $conn->real_escape_string($columnName);
        $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $res && $res->num_rows > 0;
    };

    $indexExists = function ($tableName, $indexName) use ($conn) {
        $safeTable = $conn->real_escape_string($tableName);
        $safeIndex = $conn->real_escape_string($indexName);
        $res = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
        return $res && $res->num_rows > 0;
    };

    if (!$tableExists('users')) {
        if (!$run("
            CREATE TABLE users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(120) DEFAULT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                display_name VARCHAR(80) DEFAULT '',
                password_hash VARCHAR(255) NOT NULL DEFAULT '',
                account_level ENUM('free', 'monthly', 'semiannual', 'annual') NOT NULL DEFAULT 'free',
                max_devices INT NOT NULL DEFAULT 1,
                expire_at DATETIME DEFAULT NULL,
                subscription_months INT NOT NULL DEFAULT 0,
                subscription_start_at DATETIME DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_login_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create users table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }

    $userColumns = [
        "email" => "ALTER TABLE users ADD COLUMN email VARCHAR(120) DEFAULT NULL AFTER id",
        "phone" => "ALTER TABLE users ADD COLUMN phone VARCHAR(32) DEFAULT NULL AFTER email",
        "display_name" => "ALTER TABLE users ADD COLUMN display_name VARCHAR(80) DEFAULT '' AFTER phone",
        "password_hash" => "ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER display_name",
        "account_level" => "ALTER TABLE users ADD COLUMN account_level ENUM('free', 'monthly', 'semiannual', 'annual') NOT NULL DEFAULT 'free' AFTER password_hash",
        "max_devices" => "ALTER TABLE users ADD COLUMN max_devices INT NOT NULL DEFAULT 1 AFTER account_level",
        "expire_at" => "ALTER TABLE users ADD COLUMN expire_at DATETIME DEFAULT NULL AFTER max_devices",
        "subscription_months" => "ALTER TABLE users ADD COLUMN subscription_months INT NOT NULL DEFAULT 0 AFTER expire_at",
        "subscription_start_at" => "ALTER TABLE users ADD COLUMN subscription_start_at DATETIME DEFAULT NULL AFTER subscription_months",
        "status" => "ALTER TABLE users ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1 AFTER subscription_start_at",
        "created_at" => "ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
        "updated_at" => "ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        "last_login_at" => "ALTER TABLE users ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER updated_at",
    ];

    foreach ($userColumns as $columnName => $sql) {
        if (!$columnExists('users', $columnName) && !$run($sql, "add users.{$columnName} column")) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }

    if (!$run("UPDATE users SET email = NULL WHERE email = ''", 'normalize users empty email')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (!$run("UPDATE users SET phone = NULL WHERE phone = ''", 'normalize users empty phone')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    if (!$indexExists('users', 'uniq_users_email') && !$run("ALTER TABLE users ADD UNIQUE KEY uniq_users_email (email)", 'add users email index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (!$indexExists('users', 'uniq_users_phone') && !$run("ALTER TABLE users ADD UNIQUE KEY uniq_users_phone (phone)", 'add users phone index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    if (!$tableExists('user_devices')) {
        if (!$run("
            CREATE TABLE user_devices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                machine_code VARCHAR(128) NOT NULL,
                device_name VARCHAR(120) DEFAULT '',
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_seen_at DATETIME DEFAULT NULL,
                UNIQUE KEY uniq_user_machine (user_id, machine_code),
                KEY idx_user_devices_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create user_devices table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }
    if (!$indexExists('user_devices', 'uniq_user_machine') && !$run("ALTER TABLE user_devices ADD UNIQUE KEY uniq_user_machine (user_id, machine_code)", 'add user_devices unique index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (!$indexExists('user_devices', 'idx_user_devices_user') && !$run("ALTER TABLE user_devices ADD KEY idx_user_devices_user (user_id)", 'add user_devices user index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    if (!$tableExists('user_sessions')) {
        if (!$run("
            CREATE TABLE user_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                session_token VARCHAR(128) NOT NULL,
                machine_code VARCHAR(128) NOT NULL,
                device_name VARCHAR(120) DEFAULT '',
                status TINYINT(1) NOT NULL DEFAULT 1,
                expires_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_seen_at DATETIME DEFAULT NULL,
                UNIQUE KEY uniq_session_token (session_token),
                KEY idx_user_sessions_user (user_id),
                KEY idx_user_sessions_machine (machine_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create user_sessions table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }
    if (!$indexExists('user_sessions', 'uniq_session_token') && !$run("ALTER TABLE user_sessions ADD UNIQUE KEY uniq_session_token (session_token)", 'add user_sessions token index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (!$indexExists('user_sessions', 'idx_user_sessions_user') && !$run("ALTER TABLE user_sessions ADD KEY idx_user_sessions_user (user_id)", 'add user_sessions user index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (!$indexExists('user_sessions', 'idx_user_sessions_machine') && !$run("ALTER TABLE user_sessions ADD KEY idx_user_sessions_machine (machine_code)", 'add user_sessions machine index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    if (!$tableExists('user_download_logs')) {
        if (!$run("
            CREATE TABLE user_download_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                machine_code VARCHAR(128) NOT NULL,
                url_count INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_download_logs_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create user_download_logs table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }
    if (!$indexExists('user_download_logs', 'idx_user_download_logs_user_created') && !$run("ALTER TABLE user_download_logs ADD KEY idx_user_download_logs_user_created (user_id, created_at)", 'add user_download_logs index')) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    $lastError = null;
    $errorMessage = null;
    $ensured = true;
    return true;
}

function generate_api_token($length = 64) {
    return bin2hex(random_bytes(intval($length / 2)));
}

function find_user_by_identifier(mysqli $conn, $identifier) {
    $identifier = trim((string)$identifier);
    if ($identifier === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM users
        WHERE email = ? OR phone = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function mask_account_identifier($email, $phone) {
    if (!empty($email)) {
        $parts = explode('@', $email, 2);
        if (count($parts) === 2) {
            $name = mb_substr($parts[0], 0, 2, 'UTF-8');
            return $name . '***@' . $parts[1];
        }
        return $email;
    }

    if (!empty($phone)) {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    return '';
}

function compute_user_expire_at($level, $baseDate = null) {
    $level = normalize_account_level($level);
    if ($level === 'free') {
        return null;
    }

    $days = account_level_days($level);
    if ($days <= 0) {
        return null;
    }

    $base = $baseDate ? strtotime($baseDate) : time();
    return date('Y-m-d H:i:s', strtotime("+{$days} days", $base));
}

function is_user_subscription_active($user) {
    $level = normalize_account_level($user['account_level'] ?? 'free');
    if (intval($user['status'] ?? 0) !== 1) {
        return false;
    }

    if ($level === 'free') {
        return true;
    }

    $expireAt = trim((string)($user['expire_at'] ?? ''));
    if ($expireAt === '' || $expireAt === '0000-00-00 00:00:00') {
        return false;
    }

    return strtotime($expireAt) >= time();
}

function get_user_today_download_count(mysqli $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(url_count), 0) AS total
        FROM user_download_logs
        WHERE user_id = ?
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return intval($row['total'] ?? 0);
}

function build_user_auth_payload(mysqli $conn, $user, $token = '') {
    $todayCount = get_user_today_download_count($conn, intval($user['id']));
    $level = normalize_account_level($user['account_level'] ?? 'free');
    $dailyLimit = account_level_daily_limit($level);
    $remaining = $dailyLimit < 0 ? -1 : max(0, $dailyLimit - $todayCount);

    return [
        'user_id' => intval($user['id']),
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'display_name' => $user['display_name'] ?? '',
        'account_level' => $level,
        'account_level_label' => account_level_label($level),
        'max_devices' => intval($user['max_devices'] ?? 1),
        'free_daily_limit' => $dailyLimit,
        'today_download_count' => $todayCount,
        'today_download_remaining' => $remaining,
        'expire_date' => $user['expire_at'] ?? '',
        'user_status' => intval($user['status'] ?? 0),
        'token' => $token,
    ];
}

function bind_user_device(mysqli $conn, $userId, $machineCode, $deviceName = '') {
    $stmt = $conn->prepare("
        SELECT id
        FROM user_devices
        WHERE user_id = ? AND machine_code = ?
        LIMIT 1
    ");
    $stmt->bind_param("is", $userId, $machineCode);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing) {
        $deviceId = intval($existing['id']);
        $upd = $conn->prepare("
            UPDATE user_devices
            SET device_name = ?, last_seen_at = NOW(), status = 1, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->bind_param("si", $deviceName, $deviceId);
        $upd->execute();
        return $deviceId;
    }

    $ins = $conn->prepare("
        INSERT INTO user_devices (user_id, machine_code, device_name, status, created_at, updated_at, last_seen_at)
        VALUES (?, ?, ?, 1, NOW(), NOW(), NOW())
    ");
    $ins->bind_param("iss", $userId, $machineCode, $deviceName);
    $ins->execute();
    return $ins->insert_id;
}

function count_active_user_devices(mysqli $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM user_devices
        WHERE user_id = ? AND status = 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return intval($row['total'] ?? 0);
}

function deactivate_machine_for_other_users(mysqli $conn, $machineCode, $currentUserId) {
    $machineCode = trim((string)$machineCode);
    if ($machineCode === '') {
        return;
    }

    $disableSessions = $conn->prepare("
        UPDATE user_sessions
        SET status = 0, updated_at = NOW()
        WHERE machine_code = ?
          AND user_id <> ?
          AND status = 1
    ");
    $disableSessions->bind_param("si", $machineCode, $currentUserId);
    $disableSessions->execute();

    $disableDevices = $conn->prepare("
        UPDATE user_devices
        SET status = 0, updated_at = NOW()
        WHERE machine_code = ?
          AND user_id <> ?
          AND status = 1
    ");
    $disableDevices->bind_param("si", $machineCode, $currentUserId);
    $disableDevices->execute();
}
