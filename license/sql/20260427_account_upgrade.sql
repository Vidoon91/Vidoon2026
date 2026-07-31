CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) DEFAULT NULL UNIQUE,
    phone VARCHAR(32) DEFAULT NULL UNIQUE,
    display_name VARCHAR(80) DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
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
);

CREATE TABLE IF NOT EXISTS user_devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    machine_code VARCHAR(128) NOT NULL,
    device_name VARCHAR(120) DEFAULT '',
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_user_machine (user_id, machine_code),
    KEY idx_user_devices_user (user_id),
    CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_sessions (
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
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_download_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    machine_code VARCHAR(128) NOT NULL,
    url_count INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_download_logs_user_created (user_id, created_at),
    CONSTRAINT fk_user_download_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_download_reservations (
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
    KEY idx_download_reservation_user_status (user_id, status, expires_at),
    CONSTRAINT fk_download_reservation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ad_reward_sessions (
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
);

CREATE TABLE IF NOT EXISTS ad_reward_claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_session_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reward_count INT NOT NULL DEFAULT 3,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ad_reward_session_claim (reward_session_id),
    KEY idx_ad_reward_claim_user_created (user_id, created_at)
);
