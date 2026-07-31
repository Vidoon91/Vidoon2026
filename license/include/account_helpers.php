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
                'subscription_url' => 'https://www.muyanshidai.com/',
            ],
            'account_levels' => [
                'free' => [
                    'label' => '免费订阅',
                    'days' => 0,
                    'per_task_limit' => 1,
                    'daily_limit' => 3,
                ],
                'trial' => [
                    'label' => '免费订阅',
                    'days' => 0,
                    'per_task_limit' => 1,
                    'daily_limit' => 3,
                ],
                'monthly' => [
                    'label' => '月订阅',
                    'days' => 30,
                    'per_task_limit' => 50,
                    'daily_limit' => 100,
                ],
                'semiannual' => [
                    'label' => '半年订阅',
                    'days' => 183,
                    'per_task_limit' => 100,
                    'daily_limit' => 300,
                ],
                'annual' => [
                    'label' => '年订阅',
                    'days' => 365,
                    'per_task_limit' => 200,
                    'daily_limit' => 500,
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
        if (!isset($settings['site']['subscription_url']) || !is_string($settings['site']['subscription_url']) || trim($settings['site']['subscription_url']) === '') {
            $settings['site']['subscription_url'] = $defaults['site']['subscription_url'];
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
            if (!isset($config['per_task_limit']) || !is_numeric($config['per_task_limit'])) {
                $config['per_task_limit'] = $defaultConfig['per_task_limit'];
            } else {
                $config['per_task_limit'] = intval($config['per_task_limit']);
            }

            // Subscription quotas are server policy, not runtime JSON overrides.
            $config['daily_limit'] = $defaultConfig['daily_limit'];
            $config['per_task_limit'] = $defaultConfig['per_task_limit'];

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
    // Historical trial accounts now follow the permanent free subscription.
    if ($level === 'trial') {
        return 'free';
    }
    $allowed = ['free', 'trial', 'monthly', 'semiannual', 'annual'];
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

function account_level_per_task_limit($level) {
    $config = get_account_level_runtime_config(normalize_account_level($level));
    $defaults = get_runtime_settings_defaults();
    return intval($config['per_task_limit'] ?? $defaults['account_levels']['free']['per_task_limit']);
}

function account_level_download_limits($level) {
    $level = normalize_account_level($level);
    return [
        'per_task_limit' => account_level_per_task_limit($level),
        'daily_limit' => account_level_daily_limit($level),
    ];
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

function sync_user_statuses_by_expiry(mysqli $conn) {
    $conn->query("
        UPDATE users
        SET account_level = 'free',
            max_devices = 1,
            expire_at = NULL,
            subscription_months = 0,
            subscription_start_at = NULL,
            status = 1,
            updated_at = NOW()
        WHERE account_level NOT IN ('free', 'trial')
          AND (expire_at IS NULL OR expire_at < NOW())
    ");
    $conn->query("
        UPDATE users
        SET account_level = 'free',
            expire_at = NULL,
            subscription_months = 0,
            subscription_start_at = NULL,
            status = 1,
            updated_at = NOW()
        WHERE account_level = 'trial'
           OR (account_level = 'free' AND status <> 1)
    ");
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
                account_level ENUM('free', 'trial', 'monthly', 'semiannual', 'annual') NOT NULL DEFAULT 'free',
                max_devices INT NOT NULL DEFAULT 1,
                free_credit_balance INT NOT NULL DEFAULT 3,
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
        "account_level" => "ALTER TABLE users ADD COLUMN account_level ENUM('free', 'trial', 'monthly', 'semiannual', 'annual') NOT NULL DEFAULT 'free' AFTER password_hash",
        "max_devices" => "ALTER TABLE users ADD COLUMN max_devices INT NOT NULL DEFAULT 1 AFTER account_level",
        "free_credit_balance" => "ALTER TABLE users ADD COLUMN free_credit_balance INT NOT NULL DEFAULT 3 AFTER max_devices",
        "expire_at" => "ALTER TABLE users ADD COLUMN expire_at DATETIME DEFAULT NULL AFTER free_credit_balance",
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

    $accountLevelResult = $conn->query("SHOW COLUMNS FROM users LIKE 'account_level'");
    $accountLevelColumn = $accountLevelResult ? $accountLevelResult->fetch_assoc() : null;
    if ($accountLevelColumn && strpos((string)$accountLevelColumn['Type'], "'trial'") === false) {
        if (!$run(
            "ALTER TABLE users MODIFY account_level ENUM('free', 'trial', 'monthly', 'semiannual', 'annual') NOT NULL DEFAULT 'free'",
            'extend users.account_level for trial plan'
        )) {
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

    if (!$tableExists('user_download_reservations')) {
        if (!$run("
            CREATE TABLE user_download_reservations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reservation_token CHAR(64) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                machine_code VARCHAR(128) NOT NULL,
                reserved_count INT NOT NULL,
                settled_count INT NOT NULL DEFAULT 0,
                success_count INT NOT NULL DEFAULT 0,
                quota_mode ENUM('free', 'paid') NOT NULL DEFAULT 'paid',
                status ENUM('pending', 'completed', 'expired') NOT NULL DEFAULT 'pending',
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                settled_at DATETIME DEFAULT NULL,
                UNIQUE KEY uniq_download_reservation_token (reservation_token),
                KEY idx_download_reservation_user_status (user_id, status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create user_download_reservations table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }
    if (
        !$columnExists('user_download_reservations', 'quota_mode')
        && !$run(
            "ALTER TABLE user_download_reservations ADD COLUMN quota_mode ENUM('free', 'paid') NOT NULL DEFAULT 'paid' AFTER success_count",
            'add download reservation quota mode'
        )
    ) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (
        !$indexExists('user_download_reservations', 'uniq_download_reservation_token')
        && !$run(
            "ALTER TABLE user_download_reservations ADD UNIQUE KEY uniq_download_reservation_token (reservation_token)",
            'add download reservation token index'
        )
    ) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }
    if (
        !$indexExists('user_download_reservations', 'idx_download_reservation_user_status')
        && !$run(
            "ALTER TABLE user_download_reservations ADD KEY idx_download_reservation_user_status (user_id, status, expires_at)",
            'add download reservation user index'
        )
    ) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    if (!$tableExists('ad_reward_sessions')) {
        if (!$run("
            CREATE TABLE ad_reward_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reward_token_hash CHAR(64) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                machine_code VARCHAR(128) NOT NULL,
                reward_count INT NOT NULL DEFAULT 3,
                status ENUM('pending', 'granted', 'expired') NOT NULL DEFAULT 'pending',
                request_ip VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                expires_at DATETIME NOT NULL,
                claim_started_at DATETIME DEFAULT NULL,
                granted_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ad_reward_token (reward_token_hash),
                KEY idx_ad_reward_user_created (user_id, created_at),
                KEY idx_ad_reward_status_expiry (status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create ad_reward_sessions table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }

    if (
        !$columnExists('ad_reward_sessions', 'claim_started_at')
        && !$run(
            "ALTER TABLE ad_reward_sessions ADD COLUMN claim_started_at DATETIME DEFAULT NULL AFTER expires_at",
            'add free reward claim start time'
        )
    ) {
        $ensured = false;
        $errorMessage = $lastError;
        return false;
    }

    if (!$tableExists('ad_reward_claims')) {
        if (!$run("
            CREATE TABLE ad_reward_claims (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reward_session_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                reward_count INT NOT NULL DEFAULT 3,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ad_reward_session_claim (reward_session_id),
                KEY idx_ad_reward_claim_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create ad_reward_claims table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }

    if (!$tableExists('email_verification_codes')) {
        if (!$run("
            CREATE TABLE email_verification_codes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(191) NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                request_ip VARCHAR(45) NOT NULL DEFAULT '',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                used_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_email_codes_lookup (email, purpose, used_at, created_at),
                KEY idx_email_codes_ip_created (request_ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", 'create email verification codes table')) {
            $ensured = false;
            $errorMessage = $lastError;
            return false;
        }
    }

    $lastError = null;
    $errorMessage = null;
    $ensured = true;
    return true;
}

function normalize_verification_email($email) {
    return strtolower(trim((string)$email));
}

function verification_code_hash($email, $purpose, $code) {
    $secret = defined('DB_PASS') ? DB_PASS : __FILE__;
    return hash_hmac('sha256', $email . '|' . $purpose . '|' . $code, $secret);
}

function create_email_verification_code(mysqli $conn, $email, $purpose, $requestIp, &$errorMessage = null) {
    $email = normalize_verification_email($email);
    $requestIp = substr(trim((string)$requestIp), 0, 45);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'invalid_email';
        return null;
    }
    if (!in_array($purpose, ['register', 'reset_password'], true)) {
        $errorMessage = 'invalid_verification_purpose';
        return null;
    }

    $cooldown = $conn->prepare("
        SELECT id
        FROM email_verification_codes
        WHERE email = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
        LIMIT 1
    ");
    $cooldown->bind_param('ss', $email, $purpose);
    $cooldown->execute();
    if ($cooldown->get_result()->fetch_assoc()) {
        $errorMessage = 'verification_code_too_frequent';
        return null;
    }

    $hourly = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM email_verification_codes
        WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $hourly->bind_param('s', $email);
    $hourly->execute();
    if (intval($hourly->get_result()->fetch_assoc()['total'] ?? 0) >= 5) {
        $errorMessage = 'verification_code_hourly_limit';
        return null;
    }

    if ($requestIp !== '') {
        $ipHourly = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM email_verification_codes
            WHERE request_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $ipHourly->bind_param('s', $requestIp);
        $ipHourly->execute();
        if (intval($ipHourly->get_result()->fetch_assoc()['total'] ?? 0) >= 20) {
            $errorMessage = 'verification_code_ip_limit';
            return null;
        }
    }

    $code = (string)random_int(100000, 999999);
    $codeHash = verification_code_hash($email, $purpose, $code);
    $invalidate = $conn->prepare("
        UPDATE email_verification_codes
        SET used_at = NOW()
        WHERE email = ? AND purpose = ? AND used_at IS NULL
    ");
    $invalidate->bind_param('ss', $email, $purpose);
    $invalidate->execute();

    $insert = $conn->prepare("
        INSERT INTO email_verification_codes (
            email, purpose, code_hash, request_ip, attempts, expires_at, created_at
        ) VALUES (?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
    ");
    $insert->bind_param('ssss', $email, $purpose, $codeHash, $requestIp);
    if (!$insert->execute()) {
        $errorMessage = 'verification_code_create_failed';
        return null;
    }

    return [
        'id' => intval($insert->insert_id),
        'email' => $email,
        'code' => $code,
    ];
}

function discard_email_verification_code(mysqli $conn, $codeId) {
    $stmt = $conn->prepare("DELETE FROM email_verification_codes WHERE id = ?");
    $stmt->bind_param('i', $codeId);
    $stmt->execute();
}

function verify_email_verification_code(mysqli $conn, $email, $purpose, $code, &$errorMessage = null) {
    $email = normalize_verification_email($email);
    $code = trim((string)$code);
    if (!preg_match('/^\d{6}$/', $code)) {
        $errorMessage = 'invalid_verification_code';
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT id, code_hash, attempts, expires_at
        FROM email_verification_codes
        WHERE email = ? AND purpose = ? AND used_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('ss', $email, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        $errorMessage = 'verification_code_not_found';
        return 0;
    }
    if (strtotime($row['expires_at']) < time()) {
        $errorMessage = 'verification_code_expired';
        return 0;
    }
    if (intval($row['attempts']) >= 5) {
        $errorMessage = 'verification_code_attempts_exceeded';
        return 0;
    }

    $expectedHash = verification_code_hash($email, $purpose, $code);
    if (!hash_equals($row['code_hash'], $expectedHash)) {
        $codeId = intval($row['id']);
        $increment = $conn->prepare("UPDATE email_verification_codes SET attempts = attempts + 1 WHERE id = ?");
        $increment->bind_param('i', $codeId);
        $increment->execute();
        $errorMessage = 'invalid_verification_code';
        return 0;
    }

    return intval($row['id']);
}

function consume_email_verification_code(mysqli $conn, $codeId) {
    $stmt = $conn->prepare("
        UPDATE email_verification_codes
        SET used_at = NOW()
        WHERE id = ? AND used_at IS NULL
    ");
    $stmt->bind_param('i', $codeId);
    $stmt->execute();
    return $stmt->affected_rows === 1;
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
    // Paid expiry changes the plan, not the account's ability to log in.
    return intval($user['status'] ?? 0) === 1;
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

function get_user_today_ad_reward_count(mysqli $conn, $userId) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(reward_count), 0) AS total
        FROM ad_reward_claims
        WHERE user_id = ?
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return max(0, intval($row['total'] ?? 0));
}

function expire_download_reservations(mysqli $conn, $userId = 0) {
    if ($userId > 0) {
        $stmt = $conn->prepare("
            UPDATE user_download_reservations
            SET status = 'expired', updated_at = NOW()
            WHERE user_id = ? AND status = 'pending' AND expires_at < NOW()
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return;
    }

    $conn->query("
        UPDATE user_download_reservations
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'pending' AND expires_at < NOW()
    ");
}

function get_user_active_reserved_download_count(mysqli $conn, $userId, $quotaMode = '') {
    expire_download_reservations($conn, $userId);
    if ($quotaMode === 'free' || $quotaMode === 'paid') {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(reserved_count - settled_count), 0) AS total
            FROM user_download_reservations
            WHERE user_id = ? AND quota_mode = ?
              AND status = 'pending' AND expires_at >= NOW()
        ");
        $stmt->bind_param("is", $userId, $quotaMode);
    } else {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(reserved_count - settled_count), 0) AS total
            FROM user_download_reservations
            WHERE user_id = ? AND status = 'pending' AND expires_at >= NOW()
        ");
        $stmt->bind_param("i", $userId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return max(0, intval($row['total'] ?? 0));
}

function build_user_auth_payload(mysqli $conn, $user, $token = '') {
    $userId = intval($user['id']);
    $todayCount = get_user_today_download_count($conn, $userId);
    $todayAdRewardCount = get_user_today_ad_reward_count($conn, $userId);
    $level = normalize_account_level($user['account_level'] ?? 'free');
    $quotaMode = $level === 'free' ? 'free' : 'paid';
    $reservedCount = get_user_active_reserved_download_count($conn, $userId, $quotaMode);
    $perTaskLimit = account_level_per_task_limit($level);
    $dailyLimit = account_level_daily_limit($level);
    $freeCreditBalance = max(0, intval($user['free_credit_balance'] ?? 3));
    if ($level === 'free') {
        $effectiveDailyLimit = $freeCreditBalance;
        $remaining = max(0, $freeCreditBalance - $reservedCount);
    } else {
        $effectiveDailyLimit = $dailyLimit;
        $remaining = $effectiveDailyLimit < 0
            ? -1
            : max(0, $effectiveDailyLimit - $todayCount - $reservedCount);
    }

    return [
        'user_id' => intval($user['id']),
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'display_name' => $user['display_name'] ?? '',
        'account_level' => $level,
        'account_level_label' => account_level_label($level),
        'max_devices' => intval($user['max_devices'] ?? 1),
        'per_task_limit' => $perTaskLimit,
        'daily_download_limit' => $dailyLimit,
        'effective_daily_download_limit' => $effectiveDailyLimit,
        'free_daily_limit' => $dailyLimit,
        'quota_mode' => $level === 'free' ? 'credit' : 'daily',
        'free_credit_balance' => $freeCreditBalance,
        'today_ad_reward_count' => $todayAdRewardCount,
        'today_download_count' => $todayCount,
        'today_download_reserved' => $reservedCount,
        'today_download_remaining' => $remaining,
        'expire_date' => $level === 'free' ? '' : ($user['expire_at'] ?? ''),
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

function ensure_app_settings_table(mysqli $conn, &$errorMessage = null) {
    $sql = "
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if (!$conn->query($sql)) {
        $errorMessage = $conn->error ?: 'create_app_settings_failed';
        return false;
    }
    return true;
}

function get_app_setting(mysqli $conn, $key, $default = '') {
    $stmt = $conn->prepare("
        SELECT setting_value
        FROM app_settings
        WHERE setting_key = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        return $default;
    }
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (string)$row['setting_value'] : $default;
}

function set_app_setting(mysqli $conn, $key, $value, &$errorMessage = null) {
    $stmt = $conn->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    if (!$stmt) {
        $errorMessage = $conn->error ?: 'prepare_app_setting_failed';
        return false;
    }
    $stmt->bind_param('ss', $key, $value);
    if (!$stmt->execute()) {
        $errorMessage = $stmt->error ?: 'save_app_setting_failed';
        return false;
    }
    return true;
}
