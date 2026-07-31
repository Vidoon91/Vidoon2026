<?php
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/member_auth.php';

member_session_start();
$rewardWatchMode = defined('VIDOON_REWARD_WATCH_MODE') && VIDOON_REWARD_WATCH_MODE;
$conn = get_db_connection();
sync_user_statuses_by_expiry($conn);
$config = get_ad_config($conn);
$ready = ad_reward_is_ready($config);
$memberUser = member_current_user($conn);
$token = trim((string)($_COOKIE['vidoon_free_reward'] ?? ''));
$session = null;
$pageState = 'missing';
$pageError = '';
$pageDetails = [];

function reward_set_cookie($token) {
    setcookie('vidoon_free_reward', $token, [
        'expires' => time() + 600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['vidoon_free_reward'] = $token;
}

function reward_load_session(mysqli $conn, $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $tokenHash = ad_reward_token_hash($token);
    $stmt = $conn->prepare("
        SELECT s.*, u.email, u.phone, u.display_name, u.account_level
        FROM ad_reward_sessions s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.reward_token_hash = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

if ($token !== '') {
    $session = reward_load_session($conn, $token);
    if ($session) {
        $pageState = (
            $session['status'] === 'pending'
            && strtotime($session['expires_at']) < time()
        ) ? 'expired' : $session['status'];
    }
}

if ($session && $pageState === 'expired' && $memberUser) {
    $session = null;
    $token = '';
}

if (
    $session
    && normalize_account_level($session['account_level'] ?? 'free') !== 'free'
) {
    $pageState = 'blocked';
    $pageError = 'paid_subscription_not_eligible';
}

if (!$session && $memberUser) {
    $createError = null;
    $created = create_ad_reward_session(
        $conn,
        intval($memberUser['id']),
        'web',
        $createError,
        $pageDetails
    );
    if ($created) {
        $token = $created['reward_token'];
        reward_set_cookie($token);
        $session = reward_load_session($conn, $token);
        $pageState = 'pending';
    } else {
        $pageState = 'blocked';
        $pageError = $createError ?: 'ad_reward_session_create_failed';
    }
}

if (!$session && !$memberUser && $pageState !== 'blocked') {
    header('Location: member_login.php?return=reward');
    exit;
}

$rewardCount = intval($session['reward_count'] ?? $config['reward_count']);
$accountLabel = mask_account_identifier(
    $session['email'] ?? $memberUser['email'] ?? '',
    $session['phone'] ?? $memberUser['phone'] ?? ''
);
$publisherId = trim((string)$config['publisher_id']);
if (preg_match('/^(?:ca-)?(pub-\d+)$/', $publisherId, $publisherMatch)) {
    $publisherId = 'ca-' . $publisherMatch[1];
} else {
    $publisherId = '';
}
$offerwallConfigured = $publisherId !== '';

function reward_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= $rewardWatchMode ? '观看广告领取下载次数' : '免费获取下载次数' ?> - Vidoon</title>
<?php if ($rewardWatchMode && $offerwallConfigured): ?>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= reward_e($publisherId) ?>" crossorigin="anonymous"></script>
<?php endif; ?>
<style>
:root{--ink:#10213c;--ocean:#0788ae;--mint:#16b989;--line:#dce8ec;--muted:#6c7f93;--danger:#c83f50}
*{box-sizing:border-box}body{margin:0;min-width:320px;color:var(--ink);font:14px/1.7 "Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:radial-gradient(circle at 8% 8%,rgba(7,136,174,.14),transparent 25rem),linear-gradient(145deg,#f9fcfd,#edf6f8)}
a{color:inherit;text-decoration:none}.top{width:min(1080px,calc(100% - 32px));min-height:74px;margin:auto;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{display:flex;align-items:center;gap:12px;font-size:18px;font-weight:900}.logo{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,#2867ad,#10213c)}.top-links{display:flex;align-items:center;gap:18px;color:#506a7e;font-weight:800}.top-links a:hover{color:var(--ocean)}
.page{min-height:calc(100vh - 75px);display:grid;place-items:center;padding:44px 16px 70px}.card{width:min(650px,100%);border-radius:29px;background:#fff;padding:36px;box-shadow:0 24px 70px rgba(16,33,60,.12);border:1px solid rgba(220,232,236,.9)}.eyebrow{font-size:12px;font-weight:900;letter-spacing:.18em;color:var(--ocean)}h1{font-size:34px;line-height:1.2;margin:13px 0 10px}.lead{color:var(--muted);line-height:1.8;font-size:15px}
.account{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:22px 0 0;padding:13px 16px;border:1px solid var(--line);border-radius:14px;background:#f7fbfc}.account strong{font-size:15px}.account span{color:var(--muted);font-size:12px}
.reward{margin:16px 0 24px;padding:20px;border-radius:20px;background:#10213c;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:18px}.reward strong{font-size:30px}.reward span{font-size:13px;color:#b8c8d8}.button{width:100%;border:0;border-radius:15px;padding:15px;background:var(--ocean);color:#fff;font-size:16px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;text-align:center}.button:disabled{cursor:not-allowed;background:#a8bbc4}
.status{margin-top:16px;border-radius:14px;padding:13px 15px;background:#eef6f8;color:#49677a;font-size:13px}.status.ok{background:#eafaf4;color:#087758}.status.error{background:#fff0ee;color:var(--danger)}.rules{margin-top:22px;padding-top:18px;border-top:1px solid var(--line);display:grid;gap:9px;color:#60788b;font-size:13px}.rules span::before{content:"✓";color:var(--mint);font-weight:900;margin-right:8px}
@media(max-width:620px){.card{padding:25px 20px}h1{font-size:28px}.top-links span{display:none}.reward{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<header class="top">
    <a class="brand" href="index.php"><span class="logo">V</span><span>Vidoon 免费订阅</span></a>
    <div class="top-links">
        <span><?= reward_e($accountLabel) ?></span>
        <?php if ($memberUser): ?><a href="member_logout.php">退出网页账号</a><?php endif; ?>
        <?php if ($rewardWatchMode): ?><a href="reward.php">返回领取说明</a><?php endif; ?>
        <a href="index.php">返回官网</a>
    </div>
</header>
<main class="page">
    <section class="card">
        <div class="eyebrow"><?= $rewardWatchMode ? 'ADSENSE OFFERWALL' : 'FREE DOWNLOAD CREDITS' ?></div>
        <?php if ($rewardWatchMode): ?>
            <h1>观看激励广告<br>免费获取下载次数</h1>
            <p class="lead">即将由 Google AdSense 展示积分墙。完成其中的激励广告后，本页面会恢复操作，再点击下方按钮把额度发送到当前 Vidoon 账号。</p>
        <?php else: ?>
            <h1>免费领取<br>Vidoon 下载次数</h1>
            <p class="lead">确认下方账号和领取规则后，由你主动进入 Google AdSense 积分墙。完整观看激励广告，随后即可把下载次数发送到当前 Vidoon 账号。</p>
        <?php endif; ?>
        <div class="account">
            <div><span>额度接收账号</span><br><strong><?= reward_e($accountLabel ?: '当前 Vidoon 账号') ?></strong></div>
            <span><?= $memberUser ? '网页登录' : '客户端验证' ?></span>
        </div>
        <div class="reward">
            <div><span>本次可获得</span><br><strong>+<?= $rewardCount ?> 次</strong></div>
            <div>仅限当前账号使用<br>客户端自动同步</div>
        </div>

        <?php if (!$ready): ?>
            <button class="button" disabled>免费领取功能暂未开放</button>
            <div class="status error">管理员当前关闭了免费额度领取。</div>
        <?php elseif (!$offerwallConfigured): ?>
            <button class="button" disabled>积分墙尚未配置完成</button>
            <div class="status error">后台缺少有效的 AdSense 发布商 ID，请管理员完成配置。</div>
        <?php elseif ($pageState === 'granted'): ?>
            <button class="button" disabled>额度已经到账</button>
            <div class="status ok">额度已发送到账号，重新打开或刷新客户端账号信息即可同步。</div>
        <?php elseif ($pageState !== 'pending'): ?>
            <button class="button" disabled>本次暂时无法领取</button>
            <div class="status error" id="server-error" data-code="<?= reward_e($pageError ?: $pageState) ?>"></div>
        <?php elseif (!$rewardWatchMode): ?>
            <a class="button" href="reward_watch.php">观看广告并领取 <?= $rewardCount ?> 次</a>
            <div class="status">点击后才会进入 Google 积分墙；当前说明页面不会自动弹出广告。</div>
        <?php else: ?>
            <button class="button" id="claim-button">我已完成广告，领取 <?= $rewardCount ?> 次</button>
            <div class="status" id="reward-status">如果积分墙尚未出现，请不要点击领取；可刷新页面或稍后再试。</div>
        <?php endif; ?>

        <div class="rules">
            <span>只有完成 Google 积分墙激励广告后才可领取</span>
            <span>同一凭证只能成功使用一次，并受每日上限限制</span>
            <span>网页领取和客户端领取都会同步到同一个账号</span>
        </div>
    </section>
</main>

<script>
const errorMessages = {
    paid_subscription_not_eligible: '有效付费订阅用户不能参加免费额度领取。订阅到期并回归免费订阅后可再次参加。',
    ad_reward_daily_limit_reached: '今天的免费领取次数已经用完，请明天再来。',
    ad_reward_cooldown_active: '刚刚已经领取过，请稍后再试。',
    ad_reward_session_expired: '本次领取凭证已过期，请刷新页面重新申请。',
    free_reward_disabled: '免费领取功能暂未开放。',
    invalid_reward_token: '领取凭证无效，请刷新页面重新申请。',
    ad_reward_session_create_failed: '领取请求创建失败，请稍后重试。'
};
const serverError = document.getElementById('server-error');
if (serverError) {
    serverError.textContent = errorMessages[serverError.dataset.code] || '暂时无法领取，请稍后重新尝试。';
}
</script>

<?php if ($rewardWatchMode && $ready && $offerwallConfigured && $pageState === 'pending'): ?>
<script>
const claimButton = document.getElementById('claim-button');
const statusBox = document.getElementById('reward-status');
let claiming = false;

function setStatus(message, kind = '') {
    statusBox.textContent = message;
    statusBox.className = 'status' + (kind ? ' ' + kind : '');
}

async function requestReward(action) {
    const response = await fetch('claim_ad_reward.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({action})
    });
    return response.json();
}

function startCountdown(seconds) {
    let remaining = Math.max(1, Number(seconds) || 1);
    claimButton.disabled = true;
    claimButton.textContent = `正在核验，剩余 ${remaining} 秒`;
    const timer = window.setInterval(async () => {
        remaining -= 1;
        if (remaining > 0) {
            claimButton.textContent = `正在核验，剩余 ${remaining} 秒`;
            return;
        }
        window.clearInterval(timer);
        claimButton.textContent = '正在发送额度...';
        try {
            const data = await requestReward('claim');
            if (data.status === 'wait') {
                startCountdown(Math.max(1, Number(data.wait_seconds) || 1));
                return;
            }
            if (data.status !== 'ok') throw new Error(data.message || 'reward_failed');
            claimButton.textContent = '额度已经到账';
            setStatus('额度已发送到账号，重新打开或刷新客户端账号信息即可同步。', 'ok');
        } catch (error) {
            claiming = false;
            claimButton.disabled = false;
            claimButton.textContent = '重新领取免费额度';
            setStatus(errorMessages[error.message] || '额度发放失败，请稍后重试。', 'error');
        }
    }, 1000);
}

claimButton.addEventListener('click', async () => {
    if (claiming) return;
    claiming = true;
    claimButton.disabled = true;
    setStatus('正在校验本次领取请求...');
    try {
        const data = await requestReward('start');
        if (data.status !== 'ok') throw new Error(data.message || 'reward_start_failed');
        startCountdown(Math.max(1, Number(data.wait_seconds) || 5));
    } catch (error) {
        claiming = false;
        claimButton.disabled = false;
        setStatus(errorMessages[error.message] || '领取请求失败，请稍后重试。', 'error');
    }
});
</script>
<?php endif; ?>
</body>
</html>
