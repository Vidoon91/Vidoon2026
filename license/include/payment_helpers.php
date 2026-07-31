<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account_helpers.php';

function payment_text_length($value) {
    $value = (string)$value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    if (preg_match_all('/./us', $value, $characters) !== false) {
        return count($characters[0]);
    }
    return strlen($value);
}

function payment_text_substr($value, $length) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    if (preg_match_all('/./us', $value, $characters) !== false) {
        return implode('', array_slice($characters[0], 0, $length));
    }
    return substr($value, 0, $length);
}

function ensure_payment_schema(mysqli $conn, &$errorMessage = null) {
    $queries = [
        "CREATE TABLE IF NOT EXISTS payment_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS subscription_plans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            plan_code VARCHAR(40) NOT NULL UNIQUE,
            plan_name VARCHAR(80) NOT NULL,
            duration_days INT NOT NULL,
            price_cents INT NOT NULL DEFAULT 0,
            description VARCHAR(255) NOT NULL DEFAULT '',
            status TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS payment_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_no VARCHAR(40) NOT NULL UNIQUE,
            user_id INT UNSIGNED NOT NULL,
            user_email VARCHAR(120) NOT NULL,
            plan_id INT UNSIGNED NOT NULL,
            plan_code VARCHAR(40) NOT NULL,
            plan_name VARCHAR(80) NOT NULL,
            duration_days INT NOT NULL,
            amount_cents INT NOT NULL,
            payment_channel VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            provider_trade_no VARCHAR(100) DEFAULT NULL,
            code_url TEXT DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            expire_at DATETIME DEFAULT NULL,
            subscription_before DATETIME DEFAULT NULL,
            subscription_after DATETIME DEFAULT NULL,
            client_ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_payment_orders_user (user_id),
            INDEX idx_payment_orders_status (status),
            INDEX idx_payment_orders_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS payment_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_channel VARCHAR(20) NOT NULL,
            notification_id VARCHAR(120) NOT NULL,
            order_no VARCHAR(40) NOT NULL DEFAULT '',
            signature_valid TINYINT(1) NOT NULL DEFAULT 0,
            payload MEDIUMTEXT NOT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            error_message VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_payment_notification (payment_channel, notification_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            $errorMessage = $conn->error ?: 'payment_schema_failed';
            return false;
        }
    }

    $orderColumns = [
        ['manual_payer', "VARCHAR(100) NOT NULL DEFAULT '' AFTER code_url"],
        ['manual_trade_no', "VARCHAR(100) NOT NULL DEFAULT '' AFTER manual_payer"],
        ['manual_payment_time', "DATETIME DEFAULT NULL AFTER manual_trade_no"],
        ['manual_proof_file', "VARCHAR(100) NOT NULL DEFAULT '' AFTER manual_payment_time"],
        ['manual_note', "VARCHAR(255) NOT NULL DEFAULT '' AFTER manual_proof_file"],
        ['review_note', "VARCHAR(255) NOT NULL DEFAULT '' AFTER manual_note"],
        ['reviewed_at', "DATETIME DEFAULT NULL AFTER review_note"],
        ['reviewed_by', "VARCHAR(100) NOT NULL DEFAULT '' AFTER reviewed_at"],
    ];
    $existingColumns = [];
    $columnResult = $conn->query("SHOW COLUMNS FROM payment_orders");
    if (!$columnResult) {
        $errorMessage = $conn->error ?: 'payment_schema_columns_failed';
        return false;
    }
    while ($columnRow = $columnResult->fetch_assoc()) {
        $existingColumns[(string)$columnRow['Field']] = true;
    }
    foreach ($orderColumns as [$column, $definition]) {
        if (isset($existingColumns[$column])) {
            continue;
        }
        if (!$conn->query("ALTER TABLE payment_orders ADD COLUMN `{$column}` {$definition}")
            && intval($conn->errno) !== 1060) {
            $errorMessage = $conn->error ?: 'payment_schema_column_failed';
            return false;
        }
    }

    $defaults = [
        ['trial', '免费订阅', 0, 0, '免费获取下载次数', 0, 5],
        ['monthly', '月度订阅', 30, 0, '适合短期使用', 0, 10],
        ['semiannual', '半年订阅', 183, 0, '适合长期稳定使用', 0, 20],
        ['annual', '年度订阅', 365, 0, '全年订阅方案', 0, 30],
    ];
    $stmt = $conn->prepare("
        INSERT IGNORE INTO subscription_plans
            (plan_code, plan_name, duration_days, price_cents, description, status, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        $errorMessage = $conn->error ?: 'prepare_default_plans_failed';
        return false;
    }
    foreach ($defaults as $plan) {
        [$code, $name, $days, $price, $description, $status, $sort] = $plan;
        $stmt->bind_param('ssiisii', $code, $name, $days, $price, $description, $status, $sort);
        if (!$stmt->execute()) {
            $errorMessage = $stmt->error ?: 'create_default_plans_failed';
            return false;
        }
    }
    return true;
}

function payment_secret_key() {
    $material = (defined('DB_PASS') ? DB_PASS : '')
        . '|vidoon-payment-v1';
    return hash('sha256', $material, true);
}

function payment_encrypt_secret($plainText) {
    $plainText = (string)$plainText;
    if ($plainText === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('php_openssl_missing');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        payment_secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($cipher === false) {
        throw new RuntimeException('payment_secret_encrypt_failed');
    }
    return base64_encode($iv . $tag . $cipher);
}

function payment_decrypt_secret($encryptedText) {
    $encryptedText = (string)$encryptedText;
    if ($encryptedText === '') {
        return '';
    }
    if (!function_exists('openssl_decrypt')) {
        return '';
    }
    $raw = base64_decode($encryptedText, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        payment_secret_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    return $plain === false ? '' : $plain;
}

function payment_get_setting(mysqli $conn, $key, $default = '', $secret = false) {
    $stmt = $conn->prepare("
        SELECT setting_value, is_secret
        FROM payment_settings
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
    if (!$row) {
        return $default;
    }
    if ($secret || intval($row['is_secret']) === 1) {
        $value = payment_decrypt_secret($row['setting_value']);
        return $value === '' ? $default : $value;
    }
    return (string)$row['setting_value'];
}

function payment_set_setting(mysqli $conn, $key, $value, $secret = false, &$errorMessage = null) {
    try {
        $storedValue = $secret ? payment_encrypt_secret($value) : (string)$value;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        return false;
    }
    $isSecret = $secret ? 1 : 0;
    $stmt = $conn->prepare("
        INSERT INTO payment_settings (setting_key, setting_value, is_secret, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            is_secret = VALUES(is_secret),
            updated_at = NOW()
    ");
    if (!$stmt) {
        $errorMessage = $conn->error ?: 'prepare_payment_setting_failed';
        return false;
    }
    $stmt->bind_param('ssi', $key, $storedValue, $isSecret);
    if (!$stmt->execute()) {
        $errorMessage = $stmt->error ?: 'save_payment_setting_failed';
        return false;
    }
    return true;
}

function payment_channel_ready(mysqli $conn, $channel) {
    if (payment_get_setting($conn, 'payment_enabled', '0') !== '1') {
        return false;
    }
    if (payment_get_setting($conn, $channel . '_enabled', '0') !== '1') {
        return false;
    }
    if ($channel === 'alipay') {
        return payment_get_setting($conn, 'alipay_app_id') !== ''
            && payment_get_setting($conn, 'alipay_private_key', '', true) !== ''
            && payment_get_setting($conn, 'alipay_public_key', '', true) !== '';
    }
    if ($channel === 'wechat') {
        return payment_get_setting($conn, 'wechat_mch_id') !== ''
            && payment_get_setting($conn, 'wechat_app_id') !== ''
            && payment_get_setting($conn, 'wechat_serial_no') !== ''
            && payment_get_setting($conn, 'wechat_api_v3_key', '', true) !== ''
            && payment_get_setting($conn, 'wechat_private_key', '', true) !== ''
            && payment_get_setting($conn, 'wechat_platform_cert', '', true) !== '';
    }
    if (in_array($channel, ['manual_wechat', 'manual_alipay'], true)) {
        $settingPrefix = $channel === 'manual_wechat' ? 'manual_wechat' : 'manual_alipay';
        return payment_get_setting($conn, 'manual_enabled', '0') === '1'
            && payment_get_setting($conn, $settingPrefix . '_enabled', '0') === '1'
            && payment_get_setting($conn, $settingPrefix . '_qr') !== '';
    }
    return false;
}

function payment_channel_label($channel) {
    $labels = [
        'wechat' => '微信商户支付',
        'alipay' => '支付宝商户支付',
        'manual_wechat' => '微信人工收款',
        'manual_alipay' => '支付宝人工收款',
    ];
    return $labels[$channel] ?? (string)$channel;
}

function payment_is_manual_channel($channel) {
    return in_array($channel, ['manual_wechat', 'manual_alipay'], true);
}

function payment_generate_order_no() {
    return 'VD' . date('YmdHis') . strtoupper(bin2hex(random_bytes(10)));
}

function payment_find_user_by_email(mysqli $conn, $email) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function payment_fulfill_order(
    mysqli $conn,
    $orderNo,
    $providerTradeNo,
    $paidAmountCents,
    &$result = null,
    &$errorMessage = null
) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM payment_orders WHERE order_no = ? FOR UPDATE");
        $stmt->bind_param('s', $orderNo);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('order_not_found');
        }
        if ($order['status'] === 'paid') {
            $conn->commit();
            $order['already_paid'] = true;
            $result = $order;
            return true;
        }
        if (!in_array($order['status'], ['pending', 'expired', 'reviewing'], true)) {
            throw new RuntimeException('order_not_payable');
        }
        if (intval($order['amount_cents']) !== intval($paidAmountCents)) {
            throw new RuntimeException('payment_amount_mismatch');
        }

        $userId = intval($order['user_id']);
        $userStmt = $conn->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        if (!$user) {
            throw new RuntimeException('account_not_found');
        }

        $now = new DateTimeImmutable();
        $currentExpire = trim((string)($user['expire_at'] ?? ''));
        $base = $now;
        if ($currentExpire !== '') {
            $candidate = new DateTimeImmutable($currentExpire);
            if ($candidate > $now) {
                $base = $candidate;
            }
        }
        $newExpire = $base->modify('+' . intval($order['duration_days']) . ' days');
        $beforeValue = $currentExpire !== '' ? $currentExpire : null;
        $afterValue = $newExpire->format('Y-m-d H:i:s');
        $level = in_array($order['plan_code'], ['trial', 'monthly', 'semiannual', 'annual'], true)
            ? $order['plan_code']
            : 'monthly';

        $updateUser = $conn->prepare("
            UPDATE users
            SET account_level = ?, expire_at = ?, status = 1,
                subscription_start_at = COALESCE(subscription_start_at, NOW()),
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateUser->bind_param('ssi', $level, $afterValue, $userId);
        if (!$updateUser->execute()) {
            throw new RuntimeException($updateUser->error ?: 'subscription_update_failed');
        }

        $paidAt = date('Y-m-d H:i:s');
        $updateOrder = $conn->prepare("
            UPDATE payment_orders
            SET status = 'paid', provider_trade_no = ?, paid_at = ?,
                subscription_before = ?, subscription_after = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $orderId = intval($order['id']);
        $updateOrder->bind_param(
            'ssssi',
            $providerTradeNo,
            $paidAt,
            $beforeValue,
            $afterValue,
            $orderId
        );
        if (!$updateOrder->execute()) {
            throw new RuntimeException($updateOrder->error ?: 'order_update_failed');
        }

        $conn->commit();
        $order['status'] = 'paid';
        $order['paid_at'] = $paidAt;
        $order['provider_trade_no'] = $providerTradeNo;
        $order['subscription_before'] = $beforeValue;
        $order['subscription_after'] = $afterValue;
        $order['already_paid'] = false;
        $result = $order;
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        $errorMessage = $e->getMessage();
        return false;
    }
}

function payment_get_order_email_config(mysqli $conn) {
    require_once __DIR__ . '/smtp_mailer.php';
    return [
        'enabled' => payment_get_setting(
            $conn,
            'order_email_enabled',
            defined('SMTP_ENABLED') && SMTP_ENABLED ? '1' : '0'
        ) === '1',
        'host' => payment_get_setting($conn, 'order_smtp_host', defined('SMTP_HOST') ? SMTP_HOST : ''),
        'port' => intval(payment_get_setting($conn, 'order_smtp_port', defined('SMTP_PORT') ? SMTP_PORT : 465)),
        'secure' => payment_get_setting($conn, 'order_smtp_secure', defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl'),
        'username' => payment_get_setting($conn, 'order_smtp_username', defined('SMTP_USERNAME') ? SMTP_USERNAME : ''),
        'password' => payment_get_setting(
            $conn,
            'order_smtp_password',
            defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
            true
        ),
        'from_email' => payment_get_setting($conn, 'order_smtp_from_email', defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : ''),
        'from_name' => payment_get_setting($conn, 'order_smtp_from_name', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Vidoon'),
    ];
}

function payment_order_email_ready(mysqli $conn) {
    $config = payment_get_order_email_config($conn);
    return !empty($config['enabled'])
        && $config['host'] !== ''
        && intval($config['port']) > 0
        && filter_var($config['username'], FILTER_VALIDATE_EMAIL)
        && $config['password'] !== ''
        && filter_var($config['from_email'] ?: $config['username'], FILTER_VALIDATE_EMAIL);
}

function payment_send_configured_email(mysqli $conn, $recipient, $subject, $body, &$errorMessage = null) {
    require_once __DIR__ . '/smtp_mailer.php';
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'smtp_recipient_invalid';
        return false;
    }
    $config = payment_get_order_email_config($conn);
    return smtp_send_text_email_with_config($recipient, $subject, $body, $config, $errorMessage);
}

function payment_send_user_and_admin_email(mysqli $conn, array $order, $subject, $body) {
    $userEmail = trim((string)($order['user_email'] ?? ''));
    if ($userEmail !== '') {
        payment_send_configured_email($conn, $userEmail, $subject, $body);
    }
    $adminEmail = trim(payment_get_setting($conn, 'admin_notify_email'));
    if ($adminEmail !== '' && strcasecmp($adminEmail, $userEmail) !== 0) {
        payment_send_configured_email($conn, $adminEmail, $subject, $body);
    }
}

function payment_send_order_created_emails(mysqli $conn, array $order, $state = 'pending') {
    $amount = number_format(intval($order['amount_cents'] ?? 0) / 100, 2);
    $isManual = payment_is_manual_channel($order['payment_channel'] ?? '');
    $subject = $state === 'failed'
        ? 'Vidoon 订阅订单创建失败'
        : 'Vidoon 订阅订单已创建';
    $nextStep = $state === 'failed'
        ? '支付通道未能成功创建付款，请返回订阅页面重新下单。'
        : ($isManual
            ? '请扫描页面中的个人收款码付款，并提交付款信息等待管理员核对。'
            : '请在订单有效期内扫码完成支付；未支付订单不会开通订阅。');
    $body = "您的订阅订单已经提交\n\n"
        . "订单号：" . ($order['order_no'] ?? '') . "\n"
        . "订阅账号：" . ($order['user_email'] ?? '') . "\n"
        . "订阅套餐：" . ($order['plan_name'] ?? '') . "\n"
        . "订单金额：¥" . $amount . "\n"
        . "支付方式：" . payment_channel_label($order['payment_channel'] ?? '') . "\n"
        . "订单状态：" . ($state === 'failed' ? '创建失败' : '等待付款') . "\n"
        . "有效期至：" . ($order['expire_at'] ?? '') . "\n\n"
        . $nextStep;
    payment_send_user_and_admin_email($conn, $order, $subject, $body);
}

function payment_send_order_emails(mysqli $conn, array $order) {
    $amount = number_format(intval($order['amount_cents'] ?? 0) / 100, 2);
    $subject = 'Vidoon 订阅订单支付成功';
    $body = "订单支付成功\n\n"
        . "订单号：" . ($order['order_no'] ?? '') . "\n"
        . "订阅账号：" . ($order['user_email'] ?? '') . "\n"
        . "订阅套餐：" . ($order['plan_name'] ?? '') . "\n"
        . "支付金额：¥" . $amount . "\n"
        . "支付时间：" . ($order['paid_at'] ?? '') . "\n"
        . "新的到期时间：" . ($order['subscription_after'] ?? '') . "\n";

    payment_send_user_and_admin_email($conn, $order, $subject, $body);
}

function payment_send_manual_review_email(mysqli $conn, array $order) {
    $adminEmail = trim(payment_get_setting($conn, 'admin_notify_email'));
    $amount = number_format(intval($order['amount_cents'] ?? 0) / 100, 2);
    $details = "订单号：" . ($order['order_no'] ?? '') . "\n"
        . "订阅账号：" . ($order['user_email'] ?? '') . "\n"
        . "套餐：" . ($order['plan_name'] ?? '') . "\n"
        . "金额：¥" . $amount . "\n"
        . "支付方式：" . payment_channel_label($order['payment_channel'] ?? '') . "\n"
        . "付款人：" . ($order['manual_payer'] ?? '') . "\n"
        . "付款交易号：" . ($order['manual_trade_no'] ?? '') . "\n";
    $userEmail = trim((string)($order['user_email'] ?? ''));
    if ($userEmail !== '') {
        payment_send_configured_email(
            $conn,
            $userEmail,
            'Vidoon 付款信息已提交',
            "您的付款信息已提交，当前正在等待管理员核对。\n\n{$details}\n请勿重复付款，审核通过后会再次发送开通通知。"
        );
    }
    if ($adminEmail !== '' && strcasecmp($adminEmail, $userEmail) !== 0) {
        payment_send_configured_email(
            $conn,
            $adminEmail,
            'Vidoon 人工收款订单待审核',
            "收到一笔待审核的人工付款订单\n\n{$details}\n请登录管理后台对照真实收款账单后确认开通。"
        );
    }
}
