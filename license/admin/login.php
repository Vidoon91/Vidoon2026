<?php
session_start();
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['login'] = true;
        header('Location: users.php');
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
<title>账号管理后台登录</title>
<style>
:root {
    --ink: #10213c;
    --ocean: #087ea4;
    --muted: #64748b;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    min-height: 100vh;
    font-family: "HarmonyOS Sans SC", "Microsoft YaHei UI", "Microsoft YaHei", sans-serif;
    color: var(--ink);
    background:
        radial-gradient(circle at 12% 12%, rgba(8,126,164,.16), transparent 28rem),
        radial-gradient(circle at 90% 84%, rgba(240,107,79,.10), transparent 25rem),
        linear-gradient(145deg, #f8fcfd, #eaf4f7);
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 24px;
    position: relative;
    overflow: hidden;
}

body::before {
    content: "";
    position: fixed;
    inset: 0;
    opacity: .32;
    background-image:
        linear-gradient(rgba(16,33,60,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(16,33,60,.04) 1px, transparent 1px);
    background-size: 34px 34px;
    mask-image: linear-gradient(to bottom, black, transparent 82%);
}

.login-container {
    position: relative;
    width: min(100%, 420px);
    padding: 38px;
    border: 1px solid rgba(255,255,255,.9);
    border-radius: 30px;
    background: rgba(255,255,255,.88);
    box-shadow: 0 28px 70px rgba(27,74,94,.15);
    backdrop-filter: blur(18px);
    animation: fadeIn .6s cubic-bezier(.2,.8,.2,1);
}

@keyframes fadeIn {
    from {opacity: 0; transform: translateY(16px) scale(.98);}
    to {opacity: 1; transform: translateY(0);}
}

.brand {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 30px;
    color: var(--ink);
    text-decoration: none;
}

.brand-mark {
    display: grid;
    place-items: center;
    width: 50px;
    height: 50px;
    border-radius: 18px;
    color: #fff;
    background: linear-gradient(145deg, #2867ad 0%, #173f72 42%, #0c203c 100%);
    box-shadow: 0 12px 24px rgba(20,63,114,.26);
    font-size: 21px;
    font-weight: 900;
}

h2 {
    margin: 0;
    font-size: 21px;
    line-height: 1.25;
    letter-spacing: -.4px;
}

.subtitle {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 12px;
}

label {
    display: block;
    margin: 14px 0 7px;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
}

input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid #dbe6ea;
    border-radius: 15px;
    background: #f7fafb;
    font-size: 14px;
    outline: none;
    transition: .2s ease;
}

input:focus {
    border-color: #38b6d7;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(56,182,215,.13);
}

button {
    width: 100%;
    margin-top: 22px;
    padding: 14px;
    border: 0;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg, #087ea4, #0a94b8);
    box-shadow: 0 12px 24px rgba(8,126,164,.18);
    cursor: pointer;
    font-size: 14px;
    font-weight: 700;
    transition: .2s ease;
}

button:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 30px rgba(8,126,164,.24);
}

.error {
    margin-bottom: 18px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 13px;
    color: #be123c;
    background: #fff1f2;
    font-size: 14px;
}

.footer {
    margin-top: 24px;
    text-align: center;
    font-size: 12px;
    color: #94a3b8;
}

@media (max-width: 480px) {
    .login-container { padding: 28px 24px; border-radius: 24px; }
}
</style>
</head>
<body>
<div class="login-container">
    <a class="brand" href="../index.php" title="返回首页">
        <div class="brand-mark">V</div>
        <div>
            <h2>Vidoon 账号管理</h2>
            <p class="subtitle">安全管理后台 · 仅限管理员访问</p>
        </div>
    </a>
    <?php if(!empty($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="username">管理员账号</label>
        <input id="username" type="text" name="username" placeholder="请输入管理员账号" autocomplete="username" required>
        <label for="password">登录密码</label>
        <input id="password" type="password" name="password" placeholder="请输入登录密码" autocomplete="current-password" required>
        <button type="submit">进入管理后台</button>
    </form>
    <div class="footer">© <?= date('Y') ?> Vidoon Account Console</div>
</div>
</body>
</html>
