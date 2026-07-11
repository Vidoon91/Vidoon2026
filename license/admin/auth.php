<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/db.php';
require_once '../include/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $license_key = $_POST['license_key'] ?? generate_license_key();
    $expire_date = $_POST['expire_date'] ?? null;

    // 新增字段
    $start_date = date('Y-m-d H:i:s');
    $machine_code = ""; // 默认空

    $conn = get_db_connection();

    $stmt = $conn->prepare("
        INSERT INTO licenses (license_key, machine_code, start_date, expire_date)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param("ssss",
        $license_key,
        $machine_code,
        $start_date,
        $expire_date
    );

    if (!$stmt->execute()) {
        die("数据库写入失败：" . $stmt->error);
    }
}

header('Location: index.php');
exit;
?>
