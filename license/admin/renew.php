<?php
session_start();
if (!isset($_SESSION['login'])) exit('未登录');

require_once '../include/db.php';
$conn = get_db_connection();

if(!isset($_POST['id'], $_POST['expire_date'])) exit('参数错误');

$id = intval($_POST['id']);
$expire_date = $_POST['expire_date'];

$stmt = $conn->prepare("UPDATE licenses SET expire_date=? WHERE id=?");
$stmt->bind_param("si", $expire_date, $id);
if($stmt->execute()){
    echo "续期成功！";
}else{
    echo "续期失败！";
}
