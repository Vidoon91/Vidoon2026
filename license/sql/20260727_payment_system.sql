CREATE TABLE IF NOT EXISTS payment_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT NOT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_plans (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_orders (
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
    manual_payer VARCHAR(100) NOT NULL DEFAULT '',
    manual_trade_no VARCHAR(100) NOT NULL DEFAULT '',
    manual_payment_time DATETIME DEFAULT NULL,
    manual_proof_file VARCHAR(100) NOT NULL DEFAULT '',
    manual_note VARCHAR(255) NOT NULL DEFAULT '',
    review_note VARCHAR(255) NOT NULL DEFAULT '',
    reviewed_at DATETIME DEFAULT NULL,
    reviewed_by VARCHAR(100) NOT NULL DEFAULT '',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO subscription_plans
    (plan_code, plan_name, duration_days, price_cents, description, status, sort_order)
VALUES
    ('trial', '免费订阅', 0, 0, '免费获取下载次数', 0, 5);
