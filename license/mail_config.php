<?php

// Keep real credentials in mail_config.local.php so code updates do not overwrite them.
$localMailConfig = __DIR__ . '/mail_config.local.php';
if (file_exists($localMailConfig)) {
    require_once $localMailConfig;
}

if (!defined('SMTP_ENABLED')) {
    define('SMTP_ENABLED', false);
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.qq.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 465);
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', 'ssl');
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', '');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', '');
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', '');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'Vidoon');
}

