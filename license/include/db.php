<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/account_helpers.php';

function get_db_connection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("数据库连接失败: " . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');

    $schemaError = null;
    if (!ensure_account_schema($conn, $schemaError)) {
        die("账号数据表初始化失败: " . $schemaError);
    }

    return $conn;
}
?>
