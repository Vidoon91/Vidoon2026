<?php
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/member_auth.php';
require_once __DIR__ . '/include/site_header.php';

member_session_start();
$rewardWatchMode = defined('VIDOON_REWARD_WATCH_MODE') && VIDOON_REWARD_WATCH_MODE;
if (!$rewardWatchMode) {
    header('Location: index.php#free-reward');
    exit;
}
$conn = get_db_connection();
sync_user_statuses_by_expiry($conn);
$config = get_ad_config($conn);
$adDisplayEnabled = ad_display_is_enabled($config);
$ready = ad_reward_is_ready($config);
$memberUser = member_current_user($conn);
if (!$memberUser) {
    setcookie('vidoon_free_reward', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: member_login.php?return=reward_watch');
    exit;
}
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

function reward_load_session(mysqli $conn, $token, $userId) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $tokenHash = ad_reward_token_hash($token);
    $stmt = $conn->prepare("
        SELECT s.*, u.email, u.phone, u.display_name, u.account_level
        FROM ad_reward_sessions s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.reward_token_hash = ? AND s.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $tokenHash, $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

if ($token !== '') {
    $session = reward_load_session($conn, $token, intval($memberUser['id']));
    if ($session) {
        $pageState = (
            $session['status'] === 'pending'
            && strtotime($session['expires_at']) < time()
        ) ? 'expired' : $session['status'];
    }
}

if ($session && $pageState === 'expired') {
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
        $session = reward_load_session($conn, $token, intval($memberUser['id']));
        $pageState = 'pending';
    } else {
        $pageState = 'blocked';
        $pageError = $createError ?: 'ad_reward_session_create_failed';
    }
}

$rewardCount = intval($session['reward_count'] ?? $config['reward_count']);
$accountLabel = mask_account_identifier(
    $session['email'] ?? $memberUser['email'] ?? '',
    $session['phone'] ?? $memberUser['phone'] ?? ''
);
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
<title>免费获取下载次数 - Vidoon</title>
<?php render_ad_publisher_loader($config); ?>
<style>
:root{--ink:#10213c;--ocean:#0788ae;--mint:#16b989;--line:#dce8ec;--muted:#6c7f93;--danger:#c83f50}
*{box-sizing:border-box}body{margin:0;min-width:320px;color:var(--ink);font:14px/1.7 "Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:radial-gradient(circle at 8% 8%,rgba(7,136,174,.14),transparent 25rem),linear-gradient(145deg,#f9fcfd,#edf6f8)}
a{color:inherit;text-decoration:none}.top{width:min(1080px,calc(100% - 32px));min-height:74px;margin:auto;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{display:flex;align-items:center;gap:12px;font-size:18px;font-weight:900}.logo{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,#2867ad,#10213c)}.top-links{display:flex;align-items:center;gap:18px;color:#506a7e;font-weight:800}.top-links a:hover{color:var(--ocean)}
.page-ad-layout{width:min(1480px,calc(100% - 24px));margin:0 auto;display:grid;grid-template-columns:minmax(180px,260px) minmax(0,760px) minmax(180px,260px);justify-content:center;gap:clamp(24px,3vw,48px);align-items:start}.page{min-height:calc(100vh - 75px);display:grid;place-items:center;padding:44px 0 70px}.card{width:min(650px,100%);border-radius:29px;background:#fff;padding:36px;box-shadow:0 24px 70px rgba(16,33,60,.12);border:1px solid rgba(220,232,236,.9)}.eyebrow{font-size:12px;font-weight:900;letter-spacing:.18em;color:var(--ocean)}h1{font-size:34px;line-height:1.2;margin:13px 0 10px}.lead{color:var(--muted);line-height:1.8;font-size:15px}
.side-ad{position:relative;width:100%;min-height:600px;margin-top:44px;overflow:hidden;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.74);padding:12px}.side-ad::before,.flow-bottom-ad::before{content:"广告";position:absolute;top:7px;left:12px;color:#90a1ae;font-size:10px}.side-ad .adsbygoogle{display:block!important;width:100%!important;min-height:560px}.flow-bottom-ad{position:relative;width:min(1080px,calc(100% - 32px));min-height:110px;margin:12px auto 48px;overflow:hidden;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.74);padding:12px}.flow-bottom-ad .adsbygoogle{display:block!important;width:100%!important;min-height:80px}.flow-ad.ad-empty,.flow-ad.unfilled{visibility:hidden}
.account{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:22px 0 0;padding:13px 16px;border:1px solid var(--line);border-radius:14px;background:#f7fbfc}.account strong{font-size:15px}.account span{color:var(--muted);font-size:12px}
.reward{margin:16px 0 24px;padding:20px;border-radius:20px;background:#10213c;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:18px}.reward strong{font-size:30px}.reward span{font-size:13px;color:#b8c8d8}.button{width:100%;border:0;border-radius:15px;padding:15px;background:var(--ocean);color:#fff;font-size:16px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;text-align:center}.button:disabled{cursor:not-allowed;background:#a8bbc4}
.status{margin-top:16px;border-radius:14px;padding:13px 15px;background:#eef6f8;color:#49677a;font-size:13px}.status.ok{background:#eafaf4;color:#087758}.status.error{background:#fff0ee;color:var(--danger)}.rules{margin-top:22px;padding-top:18px;border-top:1px solid var(--line);display:grid;gap:9px;color:#60788b;font-size:13px}.rules span::before{content:"✓";color:var(--mint);font-weight:900;margin-right:8px}
@media(max-width:1180px){.page-ad-layout{width:min(760px,calc(100% - 32px));grid-template-columns:minmax(0,1fr)}.side-ad{display:none}}@media(max-width:620px){.card{padding:25px 20px}h1{font-size:28px}.top-links span{display:none}.reward{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php render_site_header('reward'); ?>
<div class="page-ad-layout">
<aside class="flow-ad side-ad<?= !$adDisplayEnabled || trim((string)$config['home_left_code']) === '' ? ' ad-empty' : '' ?>" aria-label="领取页左侧广告">
    <?php if ($adDisplayEnabled) { echo $config['home_left_code']; } ?>
</aside>
<main class="page">
    <section class="card">
        <div class="eyebrow">FREE DOWNLOAD CREDITS</div>
        <h1>免费领取<br>Vidoon 下载次数</h1>
        <p class="lead">确认当前账号和领取规则后，点击领取并等待10秒核对。10分钟内未领取过，即可把免费额度发送到账号。</p>
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
        <?php elseif ($pageState === 'granted'): ?>
            <button class="button" disabled>额度已经到账</button>
            <div class="status ok">额度已发放到账号，网页登录状态已退出，重启客户端即可同步。</div>
        <?php elseif ($pageState !== 'pending'): ?>
            <button class="button" disabled>本次暂时无法领取</button>
            <div
                class="status error"
                id="server-error"
                data-code="<?= reward_e($pageError ?: $pageState) ?>"
                data-cooldown="<?= intval($pageDetails['cooldown_remaining'] ?? 0) ?>"
            ></div>
        <?php else: ?>
            <button class="button" id="claim-button">领取 <?= $rewardCount ?> 次免费下载额度</button>
            <div class="status" id="reward-status">点击后等待10秒核对领取规则，请勿重复提交。</div>
        <?php endif; ?>

        <div class="rules">
            <span>仅免费订阅账号可以领取免费额度</span>
            <span>同一凭证只能成功使用一次，领取成功后进入冷却时间</span>
            <span>额度发放到网页登录账号，重启客户端即可同步</span>
        </div>
    </section>
</main>
<aside class="flow-ad side-ad<?= !$adDisplayEnabled || trim((string)$config['home_right_code']) === '' ? ' ad-empty' : '' ?>" aria-label="领取页右侧广告">
    <?php if ($adDisplayEnabled) { echo $config['home_right_code']; } ?>
</aside>
</div>
<aside class="flow-ad flow-bottom-ad<?= !$adDisplayEnabled || trim((string)$config['home_bottom_code']) === '' ? ' ad-empty' : '' ?>" aria-label="领取页底部广告">
    <?php if ($adDisplayEnabled) { echo $config['home_bottom_code']; } ?>
</aside>

<script>
const errorMessages = {
    member_login_required: '网页登录状态已失效，请返回登录页面重新登录。',
    reward_account_mismatch: '领取凭证与当前网页登录账号不一致，请刷新页面后重试。',
    paid_subscription_not_eligible: '有效付费订阅用户不能参加免费额度领取。订阅到期并回归免费订阅后可再次参加。',
    ad_reward_daily_limit_reached: '今天的免费领取次数已经用完，请明天再来。',
    ad_reward_cooldown_active: '本次额度已经领取，请等待冷却结束后再次领取。',
    ad_reward_session_expired: '本次领取凭证已过期，请刷新页面重新申请。',
    free_reward_rule_check_not_started: '请先开始领取规则核对。',
    free_reward_rule_check_active: '领取规则仍在核对中，请稍候。',
    free_reward_disabled: '免费领取功能暂未开放。',
    invalid_reward_token: '领取凭证无效，请刷新页面重新申请。',
    ad_reward_session_create_failed: '领取请求创建失败，请稍后重试。'
};
const serverError = document.getElementById('server-error');
if (serverError) {
    let message = errorMessages[serverError.dataset.code] || '暂时无法领取，请稍后重新尝试。';
    const cooldown = Number(serverError.dataset.cooldown || 0);
    if (serverError.dataset.code === 'ad_reward_cooldown_active' && cooldown > 0) {
        message += `还需等待 ${Math.floor(cooldown / 60)} 分 ${cooldown % 60} 秒。`;
    }
    serverError.textContent = message;
}
</script>

<?php if ($rewardWatchMode && $ready && $pageState === 'pending'): ?>
<script>
const claimButton = document.getElementById('claim-button');
const statusBox = document.getElementById('reward-status');
let claiming = false;
let countdownTimer = null;

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

function cooldownText(seconds) {
    const remaining = Math.max(0, Number(seconds || 0));
    return remaining > 0
        ? `还需等待 ${Math.floor(remaining / 60)} 分 ${remaining % 60} 秒。`
        : '';
}

async function finishClaim() {
    try {
        const data = await requestReward('claim');
        if (data.status === 'wait') {
            startCountdown(Number(data.wait_seconds || 1));
            return;
        }
        if (data.status !== 'ok') {
            const error = new Error(data.message || 'reward_failed');
            error.payload = data;
            throw error;
        }
        claimButton.textContent = '额度已经到账';
        setStatus('额度已发放到账号，网页登录状态已退出，重启客户端即可。', 'ok');
    } catch (error) {
        claiming = false;
        claimButton.disabled = false;
        claimButton.textContent = '重新领取免费额度';
        setStatus(
            (errorMessages[error.message] || '额度发放失败，请稍后重试。')
                + cooldownText(error.payload?.cooldown_remaining),
            'error'
        );
    }
}

function startCountdown(seconds) {
    let remaining = Math.max(0, Math.ceil(seconds));
    if (countdownTimer) {
        window.clearInterval(countdownTimer);
    }
    const update = () => {
        if (remaining <= 0) {
            window.clearInterval(countdownTimer);
            countdownTimer = null;
            claimButton.textContent = '正在发放额度...';
            setStatus('规则核对完成，正在把额度发送到当前账号...');
            finishClaim();
            return;
        }
        claimButton.textContent = `正在核对领取规则（${remaining}秒）`;
        setStatus(`正在核对账号和10分钟领取间隔，请等待 ${remaining} 秒。`);
        remaining -= 1;
    };
    update();
    if (remaining >= 0) {
        countdownTimer = window.setInterval(update, 1000);
    }
}

claimButton.addEventListener('click', async () => {
    if (claiming) return;
    claiming = true;
    claimButton.disabled = true;
    claimButton.textContent = '正在开始核对...';
    setStatus('正在确认当前账号的领取资格...');
    try {
        const data = await requestReward('start');
        if (data.status !== 'ok') {
            const error = new Error(data.message || 'reward_failed');
            error.payload = data;
            throw error;
        }
        startCountdown(Number(data.wait_seconds ?? 10));
    } catch (error) {
        claiming = false;
        claimButton.disabled = false;
        claimButton.textContent = '重新领取免费额度';
        setStatus(
            (errorMessages[error.message] || '领取规则核对失败，请稍后重试。')
                + cooldownText(error.payload?.cooldown_remaining),
            'error'
        );
    }
});
</script>
<?php endif; ?>
<?php if ($adDisplayEnabled && ($config['home_left_code'] !== '' || $config['home_right_code'] !== '' || $config['home_bottom_code'] !== '')): ?>
<script>
window.setTimeout(() => {
    document.querySelectorAll('.flow-ad .adsbygoogle').forEach(ad => {
        if (ad.getAttribute('data-ad-status') === 'unfilled') {
            ad.closest('.flow-ad')?.classList.add('unfilled');
        }
    });
}, 8000);
</script>
<?php endif; ?>
</body>
</html>
