CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) DEFAULT NULL UNIQUE,
    phone VARCHAR(32) DEFAULT NULL UNIQUE,
    display_name VARCHAR(80) DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    account_level ENUM('free', 'monthly', 'semiannual', 'annual') NOT NULL DEFAULT 'free',
    max_devices INT NOT NULL DEFAULT 1,
    free_daily_limit INT NOT NULL DEFAULT 3,
    expire_at DATETIME DEFAULT NULL,
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
