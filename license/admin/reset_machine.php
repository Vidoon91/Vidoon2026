<?php
session_start();
if (!isset($_SESSION['login'])) exit('未登录');

require_once '../include/db.php';
$conn = get_db_connection();

if(!isset($_POST['id'])) exit('参数错误');

$id = intval($_POST['id']);

$stmt = $conn->prepare("UPDATE licenses SET machine_code='' WHERE id=?");
$stmt->bind_param("i", $id);
if($stmt->execute()){
    echo "绑定重置成功！";
}else{
    echo "重置失败！";
}
