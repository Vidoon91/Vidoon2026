<?php
require_once __DIR__ . '/include/member_auth.php';

member_session_start();
$conn = get_db_connection();
$returnKey = member_return_key($_GET['return'] ?? $_POST['return'] ?? 'reward');
$returnUrl = member_safe_return($returnKey);
$currentUser = member_current_user($conn);
if ($currentUser) {
    header('Location: ' . $returnUrl);
    exit;
}

if (empty($_SESSION['member_login_csrf'])) {
    $_SESSION['member_login_csrf'] = bin2hex(random_bytes(24));
}

$error = '';
$identifier = trim((string)($_POST['identifier'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (!hash_equals((string)$_SESSION['member_login_csrf'], $csrf)) {
        $error = '页面已过期，请刷新后重新登录。';
    } elseif ($identifier === '' || $password === '') {
        $error = '请输入注册邮箱和密码。';
    } else {
        $user = find_user_by_identifier($conn, $identifier);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            usleep(250000);
            $error = '账号或密码不正确。';
        } elseif (intval($user['status']) !== 1) {
            $error = '该账号当前不可用，请联系管理员。';
        } else {
            $userId = intval($user['id']);
            $update = $conn->prepare("
                UPDATE users
                SET last_login_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $update->bind_param('i', $userId);
            $update->execute();
            member_login_session($userId, $user['email'] ?? $user['phone'] ?? '');
            unset($_SESSION['member_login_csrf']);
            header('Location: ' . $returnUrl);
            exit;
        }
    }
}

function member_login_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Vidoon 会员登录</title>
<style>
:root{--ink:#10213c;--ocean:#0788ae;--line:#d8e6eb;--muted:#6c8094;--danger:#c83f50}
*{box-sizing:border-box}body{margin:0;min-width:320px;color:var(--ink);font:14px/1.6 "Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:radial-gradient(circle at 12% 8%,rgba(7,136,174,.17),transparent 28rem),linear-gradient(145deg,#fbfdfd,#edf6f8)}
a{color:inherit;text-decoration:none}.top{width:min(1080px,calc(100% - 32px));min-height:74px;margin:auto;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}.brand{display:flex;align-items:center;gap:11px;font-size:18px;font-weight:900}.mark{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,#2867ad,#10213c)}
.page{min-height:calc(100vh - 75px);display:grid;place-items:center;padding:42px 16px}.card{width:min(440px,100%);padding:32px;border:1px solid rgba(16,33,60,.1);border-radius:26px;background:rgba(255,255,255,.94);box-shadow:0 26px 70px rgba(45,76,96,.16)}
.eyebrow{color:var(--ocean);font-size:12px;font-weight:900;letter-spacing:.16em}h1{margin:10px 0 6px;font-size:29px}.lead{margin:0 0 22px;color:var(--muted)}label{display:block;margin:14px 0 7px;font-weight:900}input{width:100%;height:47px;border:1px solid #cbdde3;border-radius:12px;padding:0 14px;outline:none;font:inherit}input:focus{border-color:#68b5c8;box-shadow:0 0 0 4px rgba(7,136,174,.08)}
button{width:100%;height:49px;margin-top:22px;border:0;border-radius:13px;color:#fff;background:var(--ocean);font:900 15px inherit;cursor:pointer}.error{margin-top:15px;padding:11px 13px;border:1px solid #ffc6cd;border-radius:12px;color:var(--danger);background:#fff0f2}.links{display:flex;justify-content:space-between;gap:18px;margin-top:17px;color:#4f667a;font-weight:800}.links a:hover{color:var(--ocean)}
</style>
</head>
<body>
<header class="top">
    <a class="brand" href="index.php"><span class="mark">V</span><span>Vidoon</span></a>
    <a href="index.php">返回官网</a>
</header>
<main class="page">
    <section class="card">
        <div class="eyebrow">MEMBER LOGIN</div>
        <h1>登录后领取免费额度</h1>
        <p class="lead">网页账号与 Vidoon 客户端使用同一邮箱和密码。</p>
        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= member_login_e($_SESSION['member_login_csrf']) ?>">
            <input type="hidden" name="return" value="<?= member_login_e($returnKey) ?>">
            <label for="identifier">邮箱或手机号</label>
            <input id="identifier" name="identifier" value="<?= member_login_e($identifier) ?>" autocomplete="username" required>
            <label for="password">登录密码</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">登录并继续</button>
        </form>
        <?php if ($error !== ''): ?><div class="error"><?= member_login_e($error) ?></div><?php endif; ?>
        <div class="links">
            <a href="register.php?return=<?= member_login_e($returnKey) ?>">没有账号，立即注册</a>
            <a href="index.php">暂不领取</a>
        </div>
    </section>
</main>
</body>
</html>
