<?php
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/member_auth.php';

$conn = get_db_connection();
$adConfig = get_ad_config($conn);
$adDisplayEnabled = ad_display_is_enabled($adConfig);
$returnKey = member_return_key($_GET['return'] ?? 'subscribe', 'subscribe');
$returnUrl = member_safe_return($returnKey, 'subscribe.php');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="description" content="注册 Vidoon 会员账号，注册后可直接登录客户端并购买订阅套餐。">
<title>Vidoon 会员注册</title>
<style>
:root {
    --ink: #10213c;
    --ocean: #0788ae;
    --ocean-dark: #056985;
    --mint: #17b98a;
    --paper: #f4fafb;
    --line: #d9e7eb;
    --muted: #6c8094;
    --danger: #d64052;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-width: 320px;
    color: var(--ink);
    font: 14px/1.6 "Microsoft YaHei UI", "Microsoft YaHei", "PingFang SC", sans-serif;
    background:
        radial-gradient(circle at 10% 5%, rgba(7,136,174,.17), transparent 28rem),
        radial-gradient(circle at 92% 82%, rgba(23,185,138,.11), transparent 26rem),
        linear-gradient(145deg, #fbfdfd, #edf6f8 60%, #fff);
}
button, input { font: inherit; }
a { color: inherit; text-decoration: none; }
.shell { width: min(1080px, calc(100% - 32px)); margin: 0 auto; }
.page-ad-layout {
    width: min(1736px, calc(100% - 24px));
    margin: 0 auto;
    display: grid;
    grid-template-columns: 280px minmax(0, 1080px) 280px;
    gap: 48px;
    align-items: start;
}
.page-ad-layout .page { width: 100%; }
.side-ad {
    position: relative;
    width: 280px;
    min-height: 600px;
    margin-top: 50px;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: rgba(255,255,255,.74);
    padding: 12px;
}
.side-ad::before {
    content: "广告";
    position: absolute;
    top: 7px;
    left: 12px;
    color: #90a1ae;
    font-size: 10px;
}
.side-ad .adsbygoogle { display: block !important; width: 100% !important; min-height: 560px; }
.side-ad.ad-empty { visibility: hidden; }
.side-ad.unfilled { visibility: hidden; }
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 74px;
    border-bottom: 1px solid rgba(16,33,60,.10);
}
.brand { display: flex; align-items: center; gap: 11px; font-size: 18px; font-weight: 900; }
.mark {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(145deg, #2867ad, #173f72 45%, #0c203c);
    box-shadow: 0 12px 25px rgba(20,63,114,.24);
}
.brand small { display: block; margin-top: -2px; color: var(--muted); font-size: 11px; letter-spacing: .08em; }
.back { color: #4f667a; font-weight: 800; }
.back:hover { color: var(--ocean); }
.page {
    display: grid;
    grid-template-columns: .9fr 1.1fr;
    align-items: center;
    gap: 70px;
    min-height: calc(100vh - 75px);
    padding: 50px 0 70px;
}
.intro-label { color: var(--ocean-dark); font-size: 12px; font-weight: 900; letter-spacing: .16em; }
.intro h1 { margin: 14px 0 0; font-size: clamp(38px, 5vw, 58px); line-height: 1.1; letter-spacing: -.05em; }
.intro h1 em { color: var(--ocean); font-style: normal; }
.intro > p { margin: 19px 0 0; color: var(--muted); font-size: 16px; line-height: 1.8; }
.benefits { display: grid; gap: 11px; margin-top: 28px; }
.benefit { display: flex; gap: 10px; align-items: center; color: #405a70; font-weight: 800; }
.benefit::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--mint); box-shadow: 0 0 0 5px rgba(23,185,138,.10); }
.card {
    border: 1px solid rgba(16,33,60,.10);
    border-radius: 26px;
    background: rgba(255,255,255,.91);
    padding: 31px;
    box-shadow: 0 28px 70px rgba(45,76,96,.16);
}
.card h2 { margin: 0; font-size: 26px; }
.card-subtitle { margin: 6px 0 23px; color: var(--muted); }
.field { margin-top: 15px; }
.field label { display: block; margin-bottom: 7px; font-weight: 900; }
.field input {
    width: 100%;
    height: 46px;
    border: 1px solid #cbdde3;
    border-radius: 12px;
    outline: none;
    background: #fbfdfe;
    padding: 0 14px;
    color: var(--ink);
}
.field input:focus { border-color: #6eb8ca; box-shadow: 0 0 0 4px rgba(7,136,174,.08); background: #fff; }
.code-row { display: grid; grid-template-columns: 1fr 124px; gap: 9px; }
.code-button {
    height: 46px;
    margin-top: 31px;
    border: 1px solid #a9d6e1;
    border-radius: 12px;
    color: var(--ocean-dark);
    background: #eefafd;
    cursor: pointer;
    font-weight: 900;
}
.code-button:disabled { color: #8ba0ae; background: #edf2f4; border-color: #d9e2e6; cursor: not-allowed; }
.submit {
    width: 100%;
    height: 48px;
    margin-top: 23px;
    border: 0;
    border-radius: 13px;
    color: #fff;
    background: linear-gradient(135deg, var(--ocean), var(--ocean-dark));
    box-shadow: 0 12px 25px rgba(7,136,174,.22);
    cursor: pointer;
    font-weight: 900;
}
.submit:disabled { opacity: .6; cursor: wait; }
.message { display: none; margin-top: 15px; border-radius: 12px; padding: 11px 13px; font-weight: 800; }
.message.show { display: block; }
.message.info { color: #11627a; background: #eaf8fb; border: 1px solid #bde3ec; }
.message.success { color: #087758; background: #ecfbf5; border: 1px solid #a6e2ce; }
.message.error { color: var(--danger); background: #fff0f2; border: 1px solid #ffc6cd; }
.fine-print { margin: 14px 0 0; color: var(--muted); font-size: 12px; text-align: center; }
@media (max-width: 820px) {
    .page { grid-template-columns: 1fr; gap: 35px; padding-top: 36px; }
    .intro { text-align: center; }
    .benefits { width: max-content; max-width: 100%; margin-inline: auto; text-align: left; }
}
@media (max-width: 1500px) {
    .page-ad-layout { width: min(1080px, calc(100% - 32px)); grid-template-columns: minmax(0, 1fr); }
    .side-ad { display: none; }
}
@media (max-width: 520px) {
    .shell { width: min(100% - 20px, 1080px); }
    .topbar { min-height: 66px; }
    .brand small { display: none; }
    .page { min-height: auto; }
    .intro h1 { font-size: 35px; }
    .card { padding: 23px 18px; border-radius: 21px; }
    .code-row { grid-template-columns: 1fr 108px; }
}
</style>
</head>
<body>
<header class="shell topbar">
    <a class="brand" href="index.php">
        <span class="mark">V</span>
        <span>Vidoon<small>VIDEO WORKSPACE</small></span>
    </a>
    <a class="back" href="index.php">返回首页</a>
</header>

<div class="page-ad-layout">
<aside class="side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_left_code']) === '' ? ' ad-empty' : '' ?>" aria-label="注册页左侧广告">
    <?php if ($adDisplayEnabled) { echo $adConfig['home_left_code']; } ?>
</aside>
<main class="page">
    <section class="intro">
        <div class="intro-label">VIDOON MEMBERSHIP</div>
        <h1>一个账号，连接<em>客户端与订阅</em></h1>
        <p>使用邮箱验证码注册会员账号。注册完成后，同一邮箱和密码可直接登录 Vidoon 客户端，并在官网购买订阅套餐。</p>
        <div class="benefits">
            <div class="benefit">官网与客户端共用同一账号</div>
            <div class="benefit">订阅权益自动同步到客户端</div>
            <div class="benefit">订单和账号安全消息发送至注册邮箱</div>
        </div>
    </section>

    <section class="card">
        <h2>注册会员账号</h2>
        <p class="card-subtitle">目前支持邮箱验证码注册</p>
        <form id="register-form" novalidate>
            <div class="field">
                <label for="email">邮箱</label>
                <input id="email" type="email" autocomplete="email" placeholder="请输入常用邮箱" required>
            </div>
            <div class="code-row">
                <div class="field">
                    <label for="code">邮箱验证码</label>
                    <input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6 位验证码" required>
                </div>
                <button class="code-button" id="send-code" type="button">获取验证码</button>
            </div>
            <div class="field">
                <label for="password">登录密码</label>
                <input id="password" type="password" autocomplete="new-password" minlength="6" placeholder="至少 6 位字符" required>
            </div>
            <div class="field">
                <label for="confirm-password">确认密码</label>
                <input id="confirm-password" type="password" autocomplete="new-password" minlength="6" placeholder="再次输入密码" required>
            </div>
            <button class="submit" id="submit" type="submit"><?= $returnKey === 'reward' ? '注册并领取免费额度' : '注册并选择订阅套餐' ?></button>
            <div class="message" id="message" role="status" aria-live="polite"></div>
            <p class="fine-print">注册即表示你同意使用该邮箱接收验证码、订单和账号安全通知。</p>
            <p class="fine-print">已经注册？<a href="member_login.php?return=<?= htmlspecialchars($returnKey, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--ocean);font-weight:900">直接登录</a></p>
        </form>
    </section>
</main>
<aside class="side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_right_code']) === '' ? ' ad-empty' : '' ?>" aria-label="注册页右侧广告">
    <?php if ($adDisplayEnabled) { echo $adConfig['home_right_code']; } ?>
</aside>
</div>

<script>
const form = document.getElementById('register-form');
const emailInput = document.getElementById('email');
const codeInput = document.getElementById('code');
const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm-password');
const sendButton = document.getElementById('send-code');
const submitButton = document.getElementById('submit');
const messageBox = document.getElementById('message');
const returnKey = <?= json_encode($returnKey, JSON_UNESCAPED_UNICODE) ?>;
const returnUrl = <?= json_encode($returnUrl, JSON_UNESCAPED_UNICODE) ?>;
let countdownTimer = null;

const messages = {
    invalid_email: '邮箱格式不正确，请检查后重新输入。',
    account_exists: '该邮箱已经注册，可直接登录客户端或购买订阅。',
    password_too_short: '密码至少需要 6 位字符。',
    verification_code_sent: '验证码已发送，请检查收件箱和垃圾邮件。',
    verification_code_too_frequent: '发送过于频繁，请稍后再试。',
    verification_code_hourly_limit: '该邮箱发送次数过多，请一小时后重试。',
    verification_code_ip_limit: '当前网络发送次数过多，请稍后重试。',
    verification_email_send_failed: '验证码邮件发送失败，请稍后重试。',
    invalid_verification_code: '邮箱验证码错误，请重新输入。',
    verification_code_not_found: '请先获取邮箱验证码。',
    verification_code_expired: '验证码已过期，请重新获取。',
    verification_code_attempts_exceeded: '验证码错误次数过多，请重新获取。',
    verification_code_already_used: '验证码已经使用，请重新获取。',
    register_success: returnKey === 'reward' ? '注册成功，正在进入免费额度页面。' : '注册成功，正在进入订阅中心。',
    register_failed: '注册失败，请稍后重试。'
};

function showMessage(text, type = 'info') {
    messageBox.textContent = text;
    messageBox.className = 'message show ' + type;
}

function validEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

async function callApi(payload) {
    const response = await fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    });
    let data;
    try {
        data = await response.json();
    } catch (error) {
        throw new Error('服务器返回异常，请稍后重试。');
    }
    if (!response.ok || data.status !== 'ok') {
        const code = data.msg || data.message || 'register_failed';
        throw new Error(messages[code] || '操作失败，请稍后重试。');
    }
    return data;
}

function beginCountdown(seconds) {
    let remaining = Number(seconds) || 60;
    clearInterval(countdownTimer);
    sendButton.disabled = true;
    sendButton.textContent = `${remaining} 秒后重发`;
    countdownTimer = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
            clearInterval(countdownTimer);
            sendButton.disabled = false;
            sendButton.textContent = '重新发送';
            return;
        }
        sendButton.textContent = `${remaining} 秒后重发`;
    }, 1000);
}

sendButton.addEventListener('click', async () => {
    const email = emailInput.value.trim().toLowerCase();
    if (!validEmail(email)) {
        showMessage('请先输入正确的邮箱地址。', 'error');
        emailInput.focus();
        return;
    }
    sendButton.disabled = true;
    sendButton.textContent = '正在发送';
    try {
        const data = await callApi({
            action: 'send_email_code',
            purpose: 'register',
            email
        });
        showMessage(messages.verification_code_sent, 'success');
        beginCountdown(data.retry_after || 60);
        codeInput.focus();
    } catch (error) {
        sendButton.disabled = false;
        sendButton.textContent = '获取验证码';
        showMessage(error.message, 'error');
    }
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const email = emailInput.value.trim().toLowerCase();
    const code = codeInput.value.trim();
    const password = passwordInput.value;
    if (!validEmail(email)) {
        showMessage('请输入正确的邮箱地址。', 'error');
        return;
    }
    if (!/^\d{6}$/.test(code)) {
        showMessage('请输入邮件中的 6 位验证码。', 'error');
        return;
    }
    if (password.length < 6) {
        showMessage('密码至少需要 6 位字符。', 'error');
        return;
    }
    if (password !== confirmInput.value) {
        showMessage('两次输入的密码不一致。', 'error');
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = '正在注册';
    try {
        await callApi({
            action: 'register',
            register_type: 'email',
            email,
            password,
            verification_code: code,
            web_context: 1
        });
        showMessage(messages.register_success, 'success');
        window.setTimeout(() => {
            if (returnKey === 'reward') {
                window.location.href = returnUrl;
                return;
            }
            window.location.href = returnUrl + '?registered=1&email=' + encodeURIComponent(email);
        }, 700);
    } catch (error) {
        submitButton.disabled = false;
        submitButton.textContent = returnKey === 'reward' ? '注册并领取免费额度' : '注册并选择订阅套餐';
        showMessage(error.message, 'error');
    }
});
</script>
<?php if ($adDisplayEnabled && ($adConfig['home_left_code'] !== '' || $adConfig['home_right_code'] !== '')): ?>
<script>
window.setTimeout(() => {
    document.querySelectorAll('.side-ad .adsbygoogle').forEach(ad => {
        if (ad.getAttribute('data-ad-status') === 'unfilled') {
            ad.closest('.side-ad')?.classList.add('unfilled');
        }
    });
}, 8000);
</script>
<?php endif; ?>
</body>
</html>
