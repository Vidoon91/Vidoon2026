<?php

function default_public_version_config() {
    return [
        'version' => '0.0',
        'notes' => '',
    ];
}

function file_public_version_config() {
    $path = dirname(__DIR__) . '/version.json';
    if (!is_file($path)) {
        return default_public_version_config();
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data)
        ? array_merge(default_public_version_config(), $data)
        : default_public_version_config();
}

function load_public_version_config(mysqli $conn, &$errorMessage = null) {
    $fallback = file_public_version_config();
    if (!ensure_app_settings_table($conn, $errorMessage)) {
        return $fallback;
    }

    return [
        'version' => get_app_setting($conn, 'update_version', (string)$fallback['version']),
        'notes' => get_app_setting($conn, 'update_notes', (string)$fallback['notes']),
    ];
}

function save_public_version_config(mysqli $conn, array $config, &$errorMessage = null) {
    return ensure_app_settings_table($conn, $errorMessage)
        && set_app_setting($conn, 'update_version', (string)$config['version'], $errorMessage)
        && set_app_setting($conn, 'update_notes', (string)$config['notes'], $errorMessage);
}

