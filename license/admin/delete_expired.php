<?php

session_start();

if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

require_once '../include/db.php';
$conn = get_db_connection();

$id = intval($_GET['id']);

$res = $conn->query("SELECT expire_date FROM licenses WHERE id=$id");

if ($row = $res->fetch_assoc()) {

    $exp = trim($row['expire_date'] ?? '');

    // 空日期 / 无效日期 / 过期 = 可删除
    if (
        $exp === '' ||
        $exp === '0000-00-00 00:00:00' ||
        strtotime($exp) < time()
    ) {
        $conn->query("DELETE FROM licenses WHERE id=$id");
    }
}

header('Location: index.php');
exit;
?>
