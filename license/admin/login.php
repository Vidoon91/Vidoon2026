<?php
session_start();
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['login'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = "用户名或密码错误";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>授权系统管理员登录</title>
<style>
body {
    margin: 0;
    font-family: "Microsoft YaHei", Arial, sans-serif;
    background: linear-gradient(135deg, #1e272e 0%, #2f3640 100%);
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}

.login-container {
    background-color: #fff;
    padding: 40px 50px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    width: 360px;
    text-align: center;
    animation: fadeIn 0.8s ease;
}

@keyframes fadeIn {
    from {opacity: 0; transform: translateY(-20px);}
    to {opacity: 1; transform: translateY(0);}
}

h2 {
    margin-bottom: 25px;
    font-size: 22px;
    color: #2f3640;
    letter-spacing: 1px;
}

input {
    width: 100%;
    padding: 12px 14px;
    margin: 8px 0;
    border: 1px solid #dcdde1;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
    transition: border 0.3s;
}

input:focus {
    border-color: #0984e3;
}

button {
    width: 100%;
    background: linear-gradient(135deg, #00a8ff, #0097e6);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 12px;
    margin-top: 10px;
    cursor: pointer;
    font-size: 15px;
    font-weight: bold;
    transition: background 0.3s, transform 0.1s;
}

button:hover {
    background: linear-gradient(135deg, #0097e6, #00a8ff);
    transform: scale(1.02);
}

.error {
    color: #e84118;
    margin-bottom: 12px;
    font-size: 14px;
    background: #ffe6e6;
    padding: 6px 10px;
    border-radius: 4px;
}

.footer {
    margin-top: 20px;
    font-size: 12px;
    color: #888;
}
</style>
</head>
<body>
<div class="login-container">
    <h2>授权系统后台登录</h2>
    <?php if(!empty($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="text" name="username" placeholder="用户名" required>
        <input type="password" name="password" placeholder="密码" required>
        <button type="submit">登录</button>
    </form>
    <div class="footer">© <?= date('Y') ?> 授权管理系统</div>
</div>
</body>
</html>
