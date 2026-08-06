<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/version_helpers.php';
require_once '../include/db.php';
require_once '../include/account_helpers.php';

$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['version_settings_csrf']) || !hash_equals((string)$_SESSION['version_settings_csrf'], $csrf)) {
    header('Location: users.php?version_error=invalid_request');
    exit;
}

$version = trim((string)($_POST['version'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

function version_admin_redirect_error($error) {
    header('Location: users.php?version_error=' . rawurlencode($error));
    exit;
}

if (!preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
    version_admin_redirect_error('invalid_version');
}

if (strlen($notes) > 3000) {
    version_admin_redirect_error('notes_too_long');
}

$saveError = null;
$conn = get_db_connection();
$saved = save_public_version_config($conn, [
    'version' => $version,
    'notes' => $notes,
], $saveError);
if (!$saved) {
    version_admin_redirect_error($saveError ?: 'version_save_failed');
}

header('Location: users.php?version_saved=1');
exit;
