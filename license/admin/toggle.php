<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['login'])) {
    echo json_encode(['status' => 'error', 'msg' => '未登录']);
    exit;
}

require_once '../include/db.php';
$conn = get_db_connection();

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'msg' => 'ID 无效']);
    exit;
}

// 获取当前 active 状态
$stmt = $conn->prepare("SELECT active FROM licenses WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['status' => 'error', 'msg' => '授权不存在']);
    exit;
}

$new_active = $row['active'] ? 0 : 1;

$upd = $conn->prepare("UPDATE licenses SET active=?, updated_at=NOW() WHERE id=?");
$upd->bind_param("ii", $new_active, $id);
$upd->execute();

echo json_encode([
    'status' => 'ok',
    'msg'    => $new_active ? '已启用' : '已禁用',
    'active' => $new_active
]);
exit;
?>
