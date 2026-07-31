<?php
session_start();
require_once __DIR__ . '/include/payment_helpers.php';
require_once __DIR__ . '/include/ad_helpers.php';

$conn = get_db_connection();
$schemaError = null;
$schemaReady = ensure_payment_schema($conn, $schemaError);
$paymentEnabled = $schemaReady && payment_get_setting($conn, 'payment_enabled', '0') === '1';
$alipayReady = $schemaReady && payment_channel_ready($conn, 'alipay');
$wechatReady = $schemaReady && payment_channel_ready($conn, 'wechat');
$manualWechatReady = $schemaReady && payment_channel_ready($conn, 'manual_wechat');
$manualAlipayReady = $schemaReady && payment_channel_ready($conn, 'manual_alipay');
$paymentAvailable = $alipayReady || $wechatReady || $manualWechatReady || $manualAlipayReady;
$adConfig = get_ad_config($conn);
$adDisplayEnabled = ad_display_is_enabled($adConfig);
$adRewardReady = ad_reward_is_ready($adConfig);
$manualPaymentAvailable = $manualWechatReady || $manualAlipayReady;
$planRows = [];

if ($schemaReady) {
    $plans = $conn->query(
         "SELECT * FROM subscription_plans
         WHERE status = 1 AND price_cents > 0 AND plan_code <> 'trial'
         ORDER BY sort_order ASC, id ASC"
    );
    if ($plans) {
        while ($plan = $plans->fetch_assoc()) {
            $planRows[] = $plan;
        }
    }
}

$_SESSION['checkout_csrf'] = bin2hex(random_bytes(24));
$error = trim((string)($_GET['error'] ?? ''));
$registered = trim((string)($_GET['registered'] ?? '')) === '1';
$prefillEmail = trim((string)($_GET['email'] ?? ''));
if (!filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
    $prefillEmail = '';
}

function subscribe_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Vidoon 订阅中心</title>
<style>
:root {
    --ink: #10213c;
    --ink-soft: #1c3455;
    --ocean: #0788ae;
    --ocean-dark: #046b8b;
    --mint: #16b989;
    --coral: #ef6a4f;
    --paper: #f5fafb;
    --line: #dce8ec;
    --muted: #6c7f93;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    margin: 0;
    min-width: 320px;
    color: var(--ink);
    font-family: "Microsoft YaHei UI", "Microsoft YaHei", "PingFang SC", sans-serif;
    background:
        radial-gradient(circle at 8% 5%, rgba(7,136,174,.16), transparent 27rem),
        radial-gradient(circle at 96% 34%, rgba(239,106,79,.10), transparent 25rem),
        linear-gradient(145deg, #f9fcfd 0%, #edf6f8 58%, #fff8f3 100%);
}
button, input { font: inherit; }
.shell { width: min(1180px, calc(100% - 36px)); margin: 0 auto; }
.page-ad-layout {
    width: min(1836px, calc(100% - 24px));
    margin: 0 auto;
    display: grid;
    grid-template-columns: 280px minmax(0, 1180px) 280px;
    gap: 48px;
    align-items: start;
}
.page-ad-layout > main { width: 100%; }
.side-ad {
    position: relative;
    width: 280px;
    min-height: 600px;
    margin-top: 44px;
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
.brand { display: flex; align-items: center; gap: 12px; color: var(--ink); font-weight: 900; text-decoration: none; }
.brand-mark {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border-radius: 14px;
    color: #fff;
    font-size: 19px;
    background: linear-gradient(145deg, #2867ad 0%, #173f72 42%, #0c203c 100%);
    box-shadow: 0 12px 24px rgba(20,63,114,.24);
}
.brand-copy strong { display: block; font-size: 18px; letter-spacing: -.02em; }
.brand-copy span { display: block; margin-top: 2px; color: var(--muted); font-size: 11px; font-weight: 600; }
.secure-note { color: var(--muted); font-size: 12px; font-weight: 700; }
.hero { padding: 44px 0 28px; text-align: center; }
.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 13px;
    color: var(--ocean-dark);
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .13em;
}
.eyebrow::before, .eyebrow::after { content: ""; width: 24px; height: 1px; background: currentColor; opacity: .45; }
.hero h1 { margin: 0; font-size: clamp(30px, 4vw, 46px); line-height: 1.1; letter-spacing: -.05em; }
.hero h1 em { color: var(--ocean); font-style: normal; }
.hero p { max-width: 620px; margin: 14px auto 0; color: var(--muted); font-size: 13px; line-height: 1.75; }
.trust-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 17px; }
.trust-row span { border: 1px solid rgba(7,136,174,.15); border-radius: 999px; background: rgba(255,255,255,.62); padding: 6px 10px; color: #446076; font-size: 11px; font-weight: 700; }
.notice {
    max-width: 760px;
    margin: 0 auto 22px;
    border: 1px solid #f0d49f;
    border-radius: 16px;
    background: #fff9e9;
    padding: 13px 18px;
    color: #8a5c11;
    text-align: center;
    font-size: 13px;
    font-weight: 700;
}
.notice.error { border-color: #f4c1c1; background: #fff1f1; color: #b23333; }
.plans { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.plan {
    position: relative;
    display: block;
    min-height: 268px;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: rgba(255,255,255,.94);
    padding: 20px;
    cursor: pointer;
    box-shadow: 0 16px 38px rgba(51,82,99,.09);
    transition: transform .24s ease, border-color .24s ease, box-shadow .24s ease;
}
.plan:hover { transform: translateY(-5px); border-color: #92cede; box-shadow: 0 22px 48px rgba(51,82,99,.14); }
.plan.selected { border-color: var(--ocean); box-shadow: 0 0 0 4px rgba(7,136,174,.10), 0 22px 48px rgba(51,82,99,.14); }
.plan.recommended::before {
    content: "推荐";
    position: absolute;
    right: -35px;
    top: 15px;
    width: 108px;
    padding: 5px 0;
    transform: rotate(38deg);
    color: #fff;
    background: var(--coral);
    text-align: center;
    font-size: 11px;
    font-weight: 900;
}
.plan input { position: absolute; opacity: 0; pointer-events: none; }
.plan-code { color: var(--ocean); font-size: 9px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
.plan-name { margin-top: 7px; font-size: 18px; font-weight: 900; letter-spacing: -.02em; }
.price { display: flex; align-items: flex-start; gap: 3px; margin-top: 15px; }
.price small { margin-top: 6px; font-size: 13px; font-weight: 900; }
.price strong { font-size: 35px; line-height: 1; letter-spacing: -.05em; }
.duration { margin-top: 7px; color: var(--muted); font-size: 11px; font-weight: 700; }
.description { min-height: 34px; margin: 15px 0 0; color: #546a7e; font-size: 11px; line-height: 1.6; }
.benefits { display: grid; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #edf2f4; }
.benefits span { color: #334e64; font-size: 13px; font-weight: 700; }
.benefits span::before { content: "✓"; margin-right: 8px; color: var(--mint); font-weight: 900; }
.empty { grid-column: 1 / -1; border: 1px dashed #b8cbd3; border-radius: 24px; background: rgba(255,255,255,.75); padding: 48px 24px; color: var(--muted); text-align: center; }
.checkout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
    gap: 20px;
    margin: 20px 0 52px;
    border: 1px solid var(--line);
    border-radius: 22px;
    background: rgba(255,255,255,.95);
    padding: 21px;
    box-shadow: 0 22px 55px rgba(51,82,99,.11);
}
.section-title { margin: 0; font-size: 16px; font-weight: 900; }
.section-tip { margin: 5px 0 16px; color: var(--muted); font-size: 11px; }
.field-label { display: block; margin-bottom: 8px; color: #40586e; font-size: 12px; font-weight: 900; }
.email-field {
    width: 100%;
    height: 43px;
    border: 1px solid #cfdee4;
    border-radius: 14px;
    outline: none;
    background: #f8fbfc;
    padding: 0 15px;
    color: var(--ink);
    font-size: 14px;
    transition: .2s ease;
}
.email-field:focus { border-color: var(--ocean); background: #fff; box-shadow: 0 0 0 4px rgba(7,136,174,.09); }
.channels { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 19px; }
.channel {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 43px;
    border: 1px solid #d7e3e8;
    border-radius: 14px;
    background: #fff;
    color: #334e64;
    font-size: 12px;
    font-weight: 900;
    cursor: pointer;
}
.channel:has(input:checked) { border-color: var(--ocean); background: #edfafd; color: var(--ocean-dark); box-shadow: 0 0 0 3px rgba(7,136,174,.08); }
.channel.disabled { opacity: .4; cursor: not-allowed; }
.channel input { margin-right: 7px; accent-color: var(--ocean); }
.summary { display: flex; flex-direction: column; justify-content: space-between; border-radius: 17px; background: var(--ink); padding: 18px; color: #fff; }
.summary-label { color: #9eb1c7; font-size: 11px; font-weight: 800; }
.summary-plan { margin-top: 5px; font-size: 17px; font-weight: 900; }
.summary-price { margin-top: 10px; color: #fff; font-size: 28px; font-weight: 900; letter-spacing: -.04em; }
.buy-button {
    width: 100%;
    min-height: 43px;
    margin-top: 18px;
    border: 0;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(135deg, #09a0c8, #087ba1);
    font-weight: 900;
    cursor: pointer;
    box-shadow: 0 10px 22px rgba(0,0,0,.18);
}
.buy-button:hover { filter: brightness(1.06); }
.buy-button:disabled { color: #c2ced9; background: #40546d; box-shadow: none; cursor: not-allowed; }
.summary-foot { margin-top: 11px; color: #9eb1c7; font-size: 10px; line-height: 1.6; text-align: center; }
.service { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: -26px 0 48px; }
.service div { border-top: 1px solid #cbdce2; padding-top: 16px; }
.service strong { display: block; font-size: 13px; }
.service span { display: block; margin-top: 5px; color: var(--muted); font-size: 11px; line-height: 1.6; }
footer { border-top: 1px solid rgba(16,33,60,.10); padding: 22px 0 30px; color: var(--muted); font-size: 11px; text-align: center; }
@media (max-width: 1020px) {
    .plans { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1500px) {
    .page-ad-layout { width: min(1180px, calc(100% - 36px)); grid-template-columns: minmax(0, 1fr); }
    .side-ad { display: none; }
}
@media (max-width: 820px) {
    .hero { padding-top: 43px; }
    .checkout { grid-template-columns: 1fr; }
    .service { grid-template-columns: 1fr; }
}
@media (max-width: 620px) {
    .plans { grid-template-columns: 1fr; }
    .plan { min-height: auto; }
}
@media (max-width: 520px) {
    .shell { width: min(100% - 22px, 1180px); }
    .topbar { min-height: 64px; }
    .brand-copy span, .secure-note { display: none; }
    .hero { padding: 34px 0 27px; }
    .hero p { font-size: 13px; }
    .plan, .checkout { border-radius: 20px; padding: 20px; }
    .channels { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<header class="shell topbar">
    <a class="brand" href="index.php" title="返回首页">
        <div class="brand-mark">V</div>
        <div class="brand-copy">
            <strong>Vidoon 订阅中心</strong>
            <span>账号订阅与设备权益服务</span>
        </div>
    </a>
    <div class="secure-note">安全支付 · 自动开通 · 到期透明</div>
</header>

<div class="page-ad-layout">
<aside class="side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_left_code']) === '' ? ' ad-empty' : '' ?>" aria-label="订阅页左侧广告">
    <?php if ($adDisplayEnabled) { echo $adConfig['home_left_code']; } ?>
</aside>
<main>
    <section class="hero">
        <div class="eyebrow">VIDOON MEMBERSHIP</div>
        <h1>选择套餐，解锁<em>完整体验</em></h1>
        <p><?= $manualPaymentAvailable
            ? '使用会员注册邮箱购买订阅。扫码付款并提交付款信息后，管理员核对真实账单，确认无误后开通订阅。'
            : '使用会员注册邮箱购买订阅。支付完成后系统自动到账并延长有效期，无需上传付款截图，也无需等待人工处理。' ?></p>
        <div class="trust-row">
            <span><?= $manualPaymentAvailable ? '真实账单人工核对' : '支付后自动开通' ?></span>
            <span>原到期时间上续期</span>
            <span>订单邮件实时通知</span>
        </div>
    </section>

    <?php if ($registered): ?>
        <div class="notice" style="border-color:#9edfc9;background:#ecfbf5;color:#087758;">
            会员注册成功。注册邮箱已自动填写，可直接选择套餐购买订阅。
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= subscribe_e($error) ?></div>
    <?php elseif (!$paymentAvailable): ?>
        <div class="notice">当前为套餐预览，在线支付通道尚未启用。套餐设置完成后，可在后台开启正式购买。</div>
    <?php endif; ?>

    <form action="create_payment_order.php" method="post" id="checkout-form">
        <input type="hidden" name="csrf" value="<?= subscribe_e($_SESSION['checkout_csrf']) ?>">
        <section class="plans" aria-label="订阅套餐">
            <<?= $adRewardReady ? 'a' : 'div' ?> class="plan"<?= $adRewardReady ? ' href="reward.php"' : '' ?> style="text-decoration:none;color:inherit">
                <div class="plan-code">FREE</div>
                <div class="plan-name">免费订阅</div>
                <div class="price"><strong style="font-size:31px">免费</strong></div>
                <div class="duration">注册账号即可使用</div>
                <p class="description"><?= $adRewardReady ? '通过指定激励广告免费获取下载次数' : '注册后可使用首次赠送的下载额度' ?></p>
                <div class="benefits">
                    <span>每次只下载 1 个视频</span>
                    <span>首次注册赠送 3 次下载额度</span>
                    <span><?= $adRewardReady ? '用完可看广告免费领取' : '更多额度可选择付费订阅' ?></span>
                </div>
            </<?= $adRewardReady ? 'a' : 'div' ?>>
            <?php foreach ($planRows as $index => $plan): ?>
                <?php
                $selected = $index === 0;
                $recommended = (string)$plan['plan_code'] === 'semiannual';
                $price = number_format(intval($plan['price_cents']) / 100, 2);
                $limits = account_level_download_limits((string)$plan['plan_code']);
                ?>
                <label class="plan<?= $selected ? ' selected' : '' ?><?= $recommended ? ' recommended' : '' ?>"
                       data-name="<?= subscribe_e($plan['plan_name']) ?>"
                       data-price="<?= subscribe_e($price) ?>">
                    <input type="radio" name="plan_id" value="<?= intval($plan['id']) ?>" <?= $selected ? 'checked' : '' ?> required>
                    <div class="plan-code"><?= subscribe_e($plan['plan_code']) ?></div>
                    <div class="plan-name"><?= subscribe_e($plan['plan_name']) ?></div>
                    <div class="price"><small>¥</small><strong><?= subscribe_e($price) ?></strong></div>
                    <div class="duration">有效期 <?= intval($plan['duration_days']) ?> 天</div>
                    <p class="description"><?= subscribe_e($plan['description']) ?></p>
                    <div class="benefits">
                        <span>单次最多下载 <?= intval($limits['per_task_limit']) ?> 个视频</span>
                        <span>每天最多下载 <?= intval($limits['daily_limit']) ?> 个视频</span>
                        <span>支付成功自动开通</span>
                    </div>
                </label>
            <?php endforeach; ?>
            <?php if (count($planRows) === 0): ?>
                <div class="empty">暂时没有已上架的订阅套餐，请稍后再来。</div>
            <?php endif; ?>
        </section>

        <?php if (count($planRows) > 0): ?>
            <section class="checkout">
                <div>
                    <h2 class="section-title">填写订阅信息</h2>
                    <p class="section-tip">请填写会员注册邮箱；还没有账号？<a href="register.php" style="color:var(--ocean);font-weight:900;">立即注册</a></p>
                    <label class="field-label" for="email">订阅账号邮箱</label>
                    <input class="email-field" id="email" type="email" name="email" required
                           autocomplete="email" placeholder="例如：name@example.com"
                           value="<?= subscribe_e($prefillEmail) ?>"
                           <?= $paymentAvailable ? '' : 'disabled' ?>>

                    <div class="channels">
                        <?php if ($manualWechatReady): ?>
                            <label class="channel">
                                <input type="radio" name="channel" value="manual_wechat" required>
                                微信支付（人工核对）
                            </label>
                        <?php endif; ?>
                        <?php if ($manualAlipayReady): ?>
                            <label class="channel">
                                <input type="radio" name="channel" value="manual_alipay" required>
                                支付宝（人工核对）
                            </label>
                        <?php endif; ?>
                        <?php if ($wechatReady): ?>
                            <label class="channel">
                                <input type="radio" name="channel" value="wechat" required>
                                微信商户支付
                            </label>
                        <?php endif; ?>
                        <?php if ($alipayReady): ?>
                            <label class="channel">
                                <input type="radio" name="channel" value="alipay" required>
                                支付宝商户支付
                            </label>
                        <?php endif; ?>
                        <?php if (!$paymentAvailable): ?>
                            <label class="channel disabled"><input type="radio" disabled>微信支付</label>
                            <label class="channel disabled"><input type="radio" disabled>支付宝</label>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="summary">
                    <div>
                        <div class="summary-label">当前选择</div>
                        <div class="summary-plan" id="summary-plan"><?= subscribe_e($planRows[0]['plan_name']) ?></div>
                        <div class="summary-price" id="summary-price">¥<?= number_format(intval($planRows[0]['price_cents']) / 100, 2) ?></div>
                    </div>
                    <div>
                        <button class="buy-button" type="submit" <?= $paymentAvailable ? '' : 'disabled' ?>>
                            <?= $paymentAvailable ? '立即支付并开通' : '支付通道暂未开放' ?>
                        </button>
                        <div class="summary-foot">提交订单即表示您已确认账号和套餐信息，实际金额以订单为准。</div>
                    </div>
                </aside>
            </section>
        <?php endif; ?>
    </form>

    <section class="service">
        <div><strong>自动到账</strong><span>支付平台回调成功后，系统自动更新账号订阅状态。</span></div>
        <div><strong>安全校验</strong><span>订单金额、套餐和支付结果均由服务器验证，避免错误开通。</span></div>
        <div><strong>邮件通知</strong><span>订单支付成功后，订阅账号和管理员均可收到结果通知。</span></div>
    </section>
</main>
<aside class="side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_right_code']) === '' ? ' ad-empty' : '' ?>" aria-label="订阅页右侧广告">
    <?php if ($adDisplayEnabled) { echo $adConfig['home_right_code']; } ?>
</aside>
</div>

<footer>© <?= date('Y') ?> Vidoon · 订阅服务由官方服务器提供</footer>

<script>
const cards = document.querySelectorAll('.plan');
const summaryPlan = document.getElementById('summary-plan');
const summaryPrice = document.getElementById('summary-price');

cards.forEach((card) => {
    card.addEventListener('change', () => {
        cards.forEach((item) => item.classList.remove('selected'));
        card.classList.add('selected');
        summaryPlan.textContent = card.dataset.name;
        summaryPrice.textContent = '¥' + card.dataset.price;
    });
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
