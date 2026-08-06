<?php
require_once __DIR__ . '/include/payment_helpers.php';
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/site_header.php';

$conn = get_db_connection();
$schemaError = null;
$schemaReady = ensure_payment_schema($conn, $schemaError);
$planRows = [];
$adConfig = get_ad_config($conn);
$adDisplayEnabled = ad_display_is_enabled($adConfig);
$adRewardReady = ad_reward_is_ready($adConfig);
if ($schemaReady) {
    $plans = $conn->query("
        SELECT plan_code, plan_name, duration_days, price_cents, description
        FROM subscription_plans
        WHERE status = 1 AND price_cents > 0 AND plan_code <> 'trial'
        ORDER BY sort_order ASC, id ASC
        LIMIT 3
    ");
    if ($plans) {
        while ($plan = $plans->fetch_assoc()) {
            $planRows[] = $plan;
        }
    }
}

function home_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Vidoon 视频素材管理工具，支持多平台素材提取、批量任务、Cookie 状态检查与账号订阅同步。">
<meta name="color-scheme" content="light">
<?php render_ad_publisher_loader($adConfig); ?>
<title>Vidoon 视频素材管理工具</title>
<style>
:root {
    --ink: #10213c;
    --ink-2: #1d3555;
    --ocean: #0788ae;
    --ocean-dark: #056985;
    --mint: #17b98a;
    --coral: #ef6c51;
    --paper: #f4fafb;
    --line: #d9e7eb;
    --muted: #6c8094;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; scroll-padding-top: 92px; }
body {
    margin: 0;
    min-width: 320px;
    color: var(--ink);
    font-size: 14px;
    font-family: "Microsoft YaHei UI", "Microsoft YaHei", "PingFang SC", sans-serif;
    background:
        radial-gradient(circle at 9% 7%, rgba(7,136,174,.14), transparent 29rem),
        radial-gradient(circle at 94% 30%, rgba(239,108,81,.10), transparent 25rem),
        linear-gradient(145deg, #fbfdfd, #edf6f8 58%, #fff8f4);
}
a { color: inherit; text-decoration: none; }
.shell { width: min(1180px, calc(100% - 36px)); margin: 0 auto; }
.nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 72px;
    border-bottom: 1px solid rgba(16,33,60,.10);
    background: rgba(247,251,252,.88);
    box-shadow: 0 10px 30px rgba(34,67,88,.08);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}
.brand { display: flex; align-items: center; gap: 11px; font-size: 18px; font-weight: 900; }
.brand-mark {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(145deg, #2867ad 0%, #173f72 42%, #0c203c 100%);
    box-shadow: 0 12px 25px rgba(20,63,114,.24);
}
.brand span small { display: block; margin-top: 2px; color: var(--muted); font-size: 12px; font-weight: 700; letter-spacing: .08em; }
.nav-links { display: flex; align-items: center; gap: 28px; color: #3f586f; font-size: 14px; font-weight: 800; }
.nav-links a:hover { color: var(--ocean); }
.nav-cta { border-radius: 12px; padding: 11px 19px; color: #fff !important; background: var(--ocean); font-size: 14px; box-shadow: 0 8px 18px rgba(7,136,174,.18); }
.hero { display: grid; grid-template-columns: 1.02fr .98fr; align-items: center; gap: 58px; min-height: 570px; padding: 58px 0 70px; }
.eyebrow { color: var(--ocean-dark); font-size: 12px; font-weight: 900; letter-spacing: .16em; }
.hero h1 { max-width: 620px; margin: 16px 0 0; font-size: clamp(38px, 5vw, 62px); line-height: 1.08; letter-spacing: -.055em; }
.hero h1 em { color: var(--ocean); font-style: normal; }
.hero-copy { max-width: 590px; margin: 20px 0 0; color: var(--muted); font-size: 16px; line-height: 1.8; }
.actions { display: flex; flex-wrap: wrap; gap: 11px; margin-top: 28px; }
.button { display: inline-flex; align-items: center; justify-content: center; min-height: 46px; border-radius: 13px; padding: 0 21px; font-size: 14px; font-weight: 900; }
.button.primary { color: #fff; background: var(--ocean); box-shadow: 0 12px 25px rgba(7,136,174,.20); }
.button.secondary { border: 1px solid #cbdde3; background: rgba(255,255,255,.72); }
.facts { display: flex; flex-wrap: wrap; gap: 17px; margin-top: 28px; color: #4f667a; font-size: 14px; font-weight: 800; }
.facts span::before { content: ""; display: inline-block; width: 6px; height: 6px; margin-right: 7px; border-radius: 50%; background: var(--mint); vertical-align: 1px; }
.home-layout {
    width: min(1836px, calc(100% - 24px));
    margin: 0 auto;
    display: grid;
    grid-template-columns: 280px minmax(0, 1180px) 280px;
    gap: 48px;
    align-items: start;
}
.home-content { min-width: 0; }
.home-content .shell { width: 100%; }
.home-ad {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: rgba(255,255,255,.74);
    padding: 18px;
}
.home-ad::before {
    content: "广告";
    position: absolute;
    top: 7px;
    left: 12px;
    color: #90a1ae;
    font-size: 10px;
    letter-spacing: .08em;
}
.home-side-ad {
    width: 280px;
    min-height: 600px;
    margin-top: 58px;
    padding: 12px;
}
.home-side-ad .adsbygoogle {
    display: block !important;
    width: 100% !important;
    min-height: 560px;
}
.home-bottom-ad { min-height: 110px; margin: 34px 0 0; }
.home-bottom-ad .adsbygoogle { display: block !important; width: 100% !important; min-height: 70px; }
.home-ad.ad-empty { visibility: hidden; }
.home-ad.unfilled { visibility: hidden; }
.product {
    position: relative;
    border: 1px solid rgba(16,33,60,.10);
    border-radius: 28px;
    background: rgba(255,255,255,.92);
    padding: 17px;
    box-shadow: 0 30px 70px rgba(45,76,96,.16);
    transform: rotate(1deg);
}
.product::before { content: ""; position: absolute; inset: -18px 42px auto; height: 60px; border-radius: 50%; background: rgba(7,136,174,.18); filter: blur(30px); z-index: -1; }
.window-bar { display: flex; align-items: center; justify-content: space-between; border-radius: 15px 15px 8px 8px; background: var(--ink); padding: 12px 14px; color: #fff; font-size: 13px; font-weight: 900; }
.window-dots { display: flex; gap: 5px; }.window-dots i { width: 7px; height: 7px; border-radius: 50%; background: #5a708b; }.window-dots i:first-child { background: var(--coral); }
.app-body { display: grid; grid-template-columns: 76px 1fr; min-height: 340px; margin-top: 8px; gap: 10px; }
.side { display: grid; align-content: start; gap: 7px; border-radius: 12px; background: #eef5f7; padding: 10px 7px; }
.side b { border-radius: 8px; padding: 9px 6px; color: #637689; font-size: 11px; text-align: center; }.side b.active { color: #fff; background: var(--ocean-dark); }
.workspace { border: 1px solid #e1ebee; border-radius: 12px; padding: 13px; }
.platforms { display: flex; flex-wrap: wrap; gap: 6px; }.platforms span { border: 1px solid #d8e5e9; border-radius: 8px; padding: 7px 9px; color: #50677b; font-size: 11px; font-weight: 900; }.platforms span.active { border-color: #87cadc; color: var(--ocean-dark); background: #effafd; }
.url-bar { margin-top: 12px; border-radius: 9px; background: #f1f6f8; padding: 10px; color: #8193a2; font-size: 11px; }
.task { display: grid; grid-template-columns: 1fr 72px; gap: 9px; margin-top: 12px; }
.task-list { display: grid; gap: 7px; }.task-row { border: 1px solid #e3ecef; border-radius: 9px; padding: 10px; }.task-row strong { display: block; font-size: 11px; }.task-row span { display: block; margin-top: 5px; color: #8294a4; font-size: 11px; }
.task-action { display: grid; place-items: center; border-radius: 10px; color: #fff; background: linear-gradient(155deg, var(--ocean), var(--ocean-dark)); font-size: 12px; font-weight: 900; text-align: center; }
.progress { height: 5px; margin-top: 8px; overflow: hidden; border-radius: 99px; background: #e7eff2; }.progress i { display: block; width: 72%; height: 100%; background: var(--mint); }
.intro-panel {
    display: grid;
    grid-template-columns: .72fr 1.28fr;
    gap: 38px;
    align-items: center;
    margin-top: -18px;
    border: 1px solid rgba(255,255,255,.11);
    border-radius: 25px;
    padding: 30px 34px;
    color: #fff;
    background:
        radial-gradient(circle at 88% 20%, rgba(43,196,157,.18), transparent 18rem),
        linear-gradient(135deg, #10233f, #173553);
    box-shadow: 0 22px 50px rgba(29,59,79,.16);
}
.intro-label { color: #60d1e8; font-size: 12px; font-weight: 900; letter-spacing: .16em; }
.intro-panel h2 { margin: 10px 0 0; font-size: clamp(25px, 3vw, 34px); line-height: 1.25; letter-spacing: -.035em; }
.intro-copy { margin: 0; color: #d6e1ec; font-size: 14px; line-height: 1.85; }
.intro-boundary { margin: 9px 0 0; color: #9fb3c6; font-size: 14px; line-height: 1.7; }
.intro-capabilities { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 16px; }
.intro-capability { border: 1px solid rgba(255,255,255,.10); border-radius: 12px; background: rgba(255,255,255,.06); padding: 11px 12px; }
.intro-capability b { display: block; color: #65d5ba; font-size: 11px; letter-spacing: .08em; }
.intro-capability span { display: block; margin-top: 5px; color: #fff; font-size: 14px; line-height: 1.5; }
.section { padding: 72px 0; }
.section-head { display: flex; align-items: end; justify-content: space-between; gap: 25px; margin-bottom: 28px; }
.section-head small { color: var(--ocean); font-size: 12px; font-weight: 900; letter-spacing: .15em; }
.section-head h2 { margin: 9px 0 0; font-size: clamp(27px, 4vw, 39px); letter-spacing: -.045em; }
.section-head p { max-width: 470px; margin: 0; color: var(--muted); font-size: 14px; line-height: 1.7; }
.features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.feature { border: 1px solid var(--line); border-radius: 20px; background: rgba(255,255,255,.82); padding: 23px; box-shadow: 0 13px 32px rgba(50,80,98,.07); }
.feature-index { color: var(--ocean); font-family: Georgia, serif; font-size: 28px; font-style: italic; }
.feature h3 { margin: 18px 0 0; font-size: 17px; }.feature p { margin: 9px 0 0; color: var(--muted); font-size: 14px; line-height: 1.75; }
.feature-conversion { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-top: 14px; border: 1px solid #cfe3e8; border-radius: 18px; background: rgba(255,255,255,.72); padding: 18px 20px; }
.feature-conversion strong { display: block; font-size: 17px; }
.feature-conversion p { margin: 5px 0 0; color: var(--muted); font-size: 14px; }
.feature-conversion .actions { flex: 0 0 auto; margin-top: 0; }
.plan-area { border-radius: 32px; background: var(--ink); padding: 48px; color: #fff; }
.plan-area .section-head { margin-bottom: 25px; }.plan-area .section-head p { color: #a9b8c9; }
.plans { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.plan { position: relative; border: 1px solid rgba(255,255,255,.13); border-radius: 18px; background: rgba(255,255,255,.07); padding: 20px; }
.plan.recommended { border-color: #4fc2df; background: #fff; color: var(--ink); transform: translateY(-5px); }
.plan-badge { position: absolute; top: 14px; right: 14px; border-radius: 99px; background: var(--coral); padding: 5px 8px; color: #fff; font-size: 11px; font-weight: 900; }
.plan-code { color: #5bc2dc; font-size: 11px; font-weight: 900; letter-spacing: .14em; text-transform: uppercase; }
.plan-name { margin-top: 9px; font-size: 18px; font-weight: 900; }.plan-price { margin-top: 18px; font-size: 32px; font-weight: 900; letter-spacing: -.04em; }.plan-price small { margin-right: 3px; font-size: 14px; }
.plan-meta { margin-top: 8px; color: #b8c5d3; font-size: 14px; }.plan.recommended .plan-meta { color: var(--muted); }
.plan-limits { display: grid; gap: 6px; margin-top: 14px; border-top: 1px solid rgba(255,255,255,.13); padding-top: 13px; }
.plan.recommended .plan-limits { border-top-color: #e2eaed; }
.plan-limits span { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: #dbe5ed; font-size: 13px; }
.plan.recommended .plan-limits span { color: #536a7d; }
.plan-limits b { color: #fff; font-size: 14px; }.plan.recommended .plan-limits b { color: var(--ink); }
.plan-description { min-height: 42px; margin-top: 16px; color: #c4ced9; font-size: 14px; line-height: 1.6; }.plan.recommended .plan-description { color: var(--muted); }
.plan-link { display: block; margin-top: 17px; border-radius: 10px; padding: 11px 10px; color: var(--ink); background: #fff; font-size: 14px; font-weight: 900; text-align: center; }.plan.recommended .plan-link { color: #fff; background: var(--ocean); }
.steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border: 1px solid var(--line); border-radius: 22px; background: rgba(255,255,255,.78); overflow: hidden; }
.step { padding: 26px; }.step + .step { border-left: 1px solid var(--line); }.step b { color: var(--ocean); font-size: 12px; letter-spacing: .12em; }.step h3 { margin: 12px 0 0; font-size: 18px; }.step p { margin: 8px 0 0; color: var(--muted); font-size: 14px; line-height: 1.7; }
.closing { display: flex; align-items: center; justify-content: space-between; gap: 30px; margin: 70px 0; border-radius: 25px; background: linear-gradient(135deg, #e7f7fa, #fff); padding: 35px; box-shadow: 0 17px 40px rgba(50,80,98,.08); }.closing h2 { margin: 0; font-size: 26px; }.closing p { margin: 8px 0 0; color: var(--muted); font-size: 14px; }
.free-reward-banner { position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between; gap: 32px; margin: 0 0 34px; border-radius: 26px; padding: 32px 36px; color: #fff; background: linear-gradient(120deg,#10213c 0%,#123c59 62%,#0788ae 100%); box-shadow: 0 20px 45px rgba(16,33,60,.18); }
.free-reward-banner::after { content: ""; position: absolute; right: 16%; bottom: -90px; width: 230px; height: 230px; border-radius: 50%; border: 40px solid rgba(255,255,255,.07); }
.free-reward-copy { position: relative; z-index: 1; }.free-reward-copy small { color: #67d9f2; font-size: 11px; font-weight: 900; letter-spacing: .16em; }.free-reward-copy h2 { margin: 9px 0 7px; font-size: 26px; }.free-reward-copy p { margin: 0; color: #c9d9e5; font-size: 14px; line-height: 1.7; }
.free-reward-button { position: relative; z-index: 1; flex: 0 0 auto; min-width: 176px; border-radius: 13px; padding: 14px 20px; color: var(--ink); background: #fff; font-size: 14px; font-weight: 900; text-align: center; box-shadow: 0 10px 22px rgba(0,0,0,.14); }
footer { display: flex; align-items: center; justify-content: space-between; min-height: 76px; border-top: 1px solid rgba(16,33,60,.10); color: var(--muted); font-size: 14px; }
@media (max-width: 960px) {
    .hero { grid-template-columns: 1fr; gap: 40px; padding-top: 45px; }
    .product { max-width: 620px; margin: 0 auto; }
    .intro-panel { grid-template-columns: 1fr; gap: 20px; margin-top: 0; }
    .features { grid-template-columns: 1fr; }
    .plans { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1500px) {
    .home-layout { width: min(1180px, calc(100% - 36px)); grid-template-columns: minmax(0, 1fr); }
    .home-side-ad { display: none; }
}
@media (max-width: 700px) {
    .nav-links a:not(.nav-cta) { display: none; }
    .hero { min-height: auto; padding-bottom: 50px; }
    .hero h1 { font-size: 38px; }
    .section { padding: 52px 0; }
    .section-head { align-items: start; flex-direction: column; }
    .feature-conversion { align-items: start; flex-direction: column; }
    .plan-area { margin-inline: -7px; padding: 28px 18px; border-radius: 23px; }
    .steps { grid-template-columns: 1fr; }
    .step + .step { border-left: 0; border-top: 1px solid var(--line); }
    .closing { align-items: start; flex-direction: column; margin: 50px 0; }
    .free-reward-banner { align-items: flex-start; flex-direction: column; padding: 28px 24px; }
    .free-reward-button { width: 100%; }
    .intro-capabilities { grid-template-columns: 1fr; }
}
@media (max-width: 520px) {
    .shell { width: min(100% - 22px, 1180px); }
    .brand span small { display: none; }
    .hero h1 { font-size: 33px; }
    .app-body { grid-template-columns: 58px 1fr; min-height: 300px; }
    .task { grid-template-columns: 1fr; }.task-action { min-height: 54px; }
    .plans { grid-template-columns: 1fr; }
    footer { align-items: start; flex-direction: column; justify-content: center; gap: 7px; }
}
</style>
</head>
<body>
<?php render_site_header('home'); ?>

<main class="home-layout">
    <aside class="home-ad home-side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_left_code']) === '' ? ' ad-empty' : '' ?>" aria-label="首页左侧广告">
        <?php if ($adDisplayEnabled) { echo $adConfig['home_left_code']; } ?>
    </aside>
    <div class="home-content">
    <section class="shell hero">
        <div>
            <div class="eyebrow">VIDOON VIDEO WORKSPACE · 2026</div>
            <h1>让视频素材整理，变得<em>清晰高效</em></h1>
            <p class="hero-copy">为短视频创作者打造的一站式素材工作台。集中处理多平台链接、批量下载任务、Cookie 状态和素材预览，让重复操作更少，让创作流程更顺畅。</p>
            <div class="actions">
                <a class="button primary" href="subscribe.php">查看订阅套餐</a>
                <a class="button secondary" href="features.php">了解产品功能</a>
            </div>
            <div class="facts"><span>多平台素材处理</span><span>批量任务管理</span><span>账号权益同步</span></div>
        </div>

        <div class="product" aria-label="Vidoon 软件界面示意">
            <div class="window-bar"><span>Vidoon 视频素材管理工具</span><span class="window-dots"><i></i><i></i><i></i></span></div>
            <div class="app-body">
                <div class="side"><b>设置</b><b>油管</b><b class="active">TikTok</b><b>Inst</b><b>Twitter</b><b>批量提取</b></div>
                <div class="workspace">
                    <div class="platforms"><span>YouTube</span><span class="active">TikTok</span><span>Instagram</span><span>Twitter</span></div>
                    <div class="url-bar">粘贴视频主页链接或批量视频链接...</div>
                    <div class="task">
                        <div class="task-list">
                            <div class="task-row"><strong>素材解析与最佳画质选择</strong><span>链接已识别 · 准备下载</span><div class="progress"><i></i></div></div>
                            <div class="task-row"><strong>Cookie 健康状态</strong><span>YouTube · Instagram · TikTok · Twitter</span></div>
                            <div class="task-row"><strong>保存路径与任务日志</strong><span>下载结果集中管理</span></div>
                        </div>
                        <div class="task-action">开始<br>提取</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="shell intro-panel" aria-labelledby="intro-title">
        <div>
            <div class="intro-label">SOFTWARE OVERVIEW</div>
            <h2 id="intro-title">面向视频素材收集与整理的桌面下载工具</h2>
        </div>
        <div>
            <p class="intro-copy">Vidoon 面向短视频创作者、内容运营和日常素材归档场景，将 YouTube、TikTok、Instagram 与 Twitter 的链接识别、批量提取、最佳可用画质下载、Cookie 状态检查、失败重试、进度日志和本地预览集中到一个 Windows 工作台中，减少多平台切换与重复操作。</p>
            <p class="intro-boundary">实际下载结果取决于平台规则、网络环境、Cookie 有效性以及源视频提供的清晰度；软件不会提升原始画质，也不能保证所有链接始终可用。请仅下载和使用您拥有相应权利的内容。</p>
            <div class="intro-capabilities">
                <div class="intro-capability"><b>输入方式</b><span>单条链接、TXT 导入、主页与列表</span></div>
                <div class="intro-capability"><b>下载处理</b><span>最佳可用画质、重试与解析兜底</span></div>
                <div class="intro-capability"><b>结果管理</b><span>本地保存、进度日志与视频预览</span></div>
            </div>
        </div>
    </section>

    <section class="shell section" id="features">
        <div class="section-head">
            <div><small>WHY VIDOON</small><h2>下载视频，不必反复折腾</h2></div>
            <p>把链接交给 Vidoon，平台识别、画质选择、批量调度和失败重试由软件完成；你只需要确认任务和保存结果。</p>
        </div>
        <div class="features">
            <article class="feature"><div class="feature-index">01</div><h3>粘贴链接，直接开始</h3><p>自动识别 YouTube、TikTok、Instagram 与 Twitter 链接，并优先下载源视频提供的最佳可用画质，减少逐项设置。</p></article>
            <article class="feature"><div class="feature-index">02</div><h3>一次处理更多视频</h3><p>支持单条链接、TXT 导入以及主页和列表提取。任务、进度与结果集中展示，把重复下载变成可管理的批量流程。</p></article>
            <article class="feature"><div class="feature-index">03</div><h3>失败时不再靠猜</h3><p>遇到临时网络、Cookie 或解析问题时自动重试并切换可用策略，同时保留清晰日志，方便快速判断和处理原因。</p></article>
        </div>
        <div class="feature-conversion">
            <div><strong>不确定是否适合？先从实际下载体验开始</strong><p><?= $adRewardReady ? '注册免费订阅，首次额度用完后还可到官网免费领取下载次数。' : '注册免费订阅可获得首次下载额度，体验后再决定是否长期订阅。' ?></p></div>
            <div class="actions">
                <a class="button secondary" href="download.php">先下载软件</a>
                <a class="button primary" href="subscribe.php">查看体验套餐</a>
            </div>
        </div>
    </section>

    <section class="shell plan-area" id="plans">
        <div class="section-head">
            <div><small>MEMBERSHIP</small><h2>选择适合你的订阅</h2></div>
            <p>订单和套餐以服务器记录为准。人工收款需管理员核对到账，商户支付则通过官方回调确认。</p>
        </div>
        <div class="plans">
            <article class="plan">
                <div class="plan-code">FREE</div>
                <div class="plan-name">免费订阅</div>
                <div class="plan-price" style="font-size:34px">免费</div>
                <div class="plan-meta">注册账号即可使用</div>
                <div class="plan-limits">
                    <span>单次任务上限 <b><?= intval(account_level_per_task_limit('free')) ?> 个</b></span>
                    <span>首次注册赠送 <b>3 次</b></span>
                    <span><?= $adRewardReady ? '每次免费领取 <b>' . intval($adConfig['reward_count']) . ' 次</b>' : '体验后可升级付费订阅' ?></span>
                </div>
                <div class="plan-description"><?= $adRewardReady ? '首次额度用完后，可从客户端进入官网免费领取下载次数。' : '首次额度用完后，可选择适合自己的付费订阅套餐。' ?></div>
                <a class="plan-link" href="<?= $adRewardReady ? '#free-reward' : 'register.php' ?>"><?= $adRewardReady ? '查看免费领取方式' : '注册免费体验' ?></a>
            </article>
            <?php if (count($planRows) > 0): ?>
                <?php foreach ($planRows as $plan): ?>
                    <?php
                    $recommended = (string)$plan['plan_code'] === 'semiannual';
                    $limits = account_level_download_limits((string)$plan['plan_code']);
                    ?>
                    <article class="plan<?= $recommended ? ' recommended' : '' ?>">
                        <?php if ($recommended): ?><span class="plan-badge">推荐</span><?php endif; ?>
                        <div class="plan-code"><?= home_e($plan['plan_code']) ?></div>
                        <div class="plan-name"><?= home_e($plan['plan_name']) ?></div>
                        <div class="plan-price"><small>¥</small><?= number_format(intval($plan['price_cents']) / 100, 2) ?></div>
                        <div class="plan-meta">有效期 <?= intval($plan['duration_days']) ?> 天</div>
                        <div class="plan-limits">
                            <span>单次任务上限 <b><?= intval($limits['per_task_limit']) ?> 个</b></span>
                            <span>每日下载上限 <b><?= intval($limits['daily_limit']) ?> 个</b></span>
                        </div>
                        <div class="plan-description"><?= home_e($plan['description']) ?></div>
                        <a class="plan-link" href="subscribe.php">选择套餐</a>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <article class="plan"><div class="plan-name">套餐正在配置</div><div class="plan-description">请稍后访问订阅中心查看最新套餐。</div><a class="plan-link" href="subscribe.php">进入订阅中心</a></article>
            <?php endif; ?>
        </div>
    </section>

    <section class="shell section" id="steps">
        <div class="section-head">
            <div><small>GET STARTED</small><h2>三步开始使用</h2></div>
            <p>注册、选择套餐、登录软件。订阅生效后，账号权益会自动同步到客户端。</p>
        </div>
        <div class="steps">
            <article class="step"><b>STEP 01</b><h3>注册会员账号</h3><p>在官网使用常用邮箱完成验证码注册，注册后可直接登录客户端并购买订阅。</p></article>
            <article class="step"><b>STEP 02</b><h3>选择合适的方案</h3><p><?= $adRewardReady ? '免费用户可在官网领取下载次数，也可以选择月度、半年或年度订阅。' : '使用首次赠送额度体验，也可以选择月度、半年或年度订阅。' ?></p></article>
            <article class="step"><b>STEP 03</b><h3>登录并同步权益</h3><p>订单确认后重新登录软件，即可同步订阅期限、设备数量与下载权益。</p></article>
        </div>
        <div class="closing">
            <div><h2>准备好整理你的素材工作流了吗？</h2><p>先注册免费订阅并获取下载次数，也可以直接选择适合长期使用的付费方案。</p></div>
            <a class="button primary" href="subscribe.php">进入订阅中心</a>
        </div>
    </section>
    <?php if ($adRewardReady): ?>
        <section class="shell free-reward-banner" id="free-reward">
            <div class="free-reward-copy">
                <small>FREE DOWNLOAD CREDITS</small>
                <h2>免费领取下载额度</h2>
                <p>点击后先登录或注册 Vidoon 账号，进入领取页等待 10 秒核对规则，符合 10 分钟领取间隔即可到账。</p>
            </div>
            <a class="free-reward-button" href="member_login.php?return=reward_watch">登录或注册后领取</a>
        </section>
    <?php endif; ?>
    <?php if ($adDisplayEnabled && trim((string)$adConfig['home_bottom_code']) !== ''): ?>
        <aside class="home-ad home-bottom-ad" aria-label="首页底部广告"><?= $adConfig['home_bottom_code'] ?></aside>
    <?php endif; ?>
    </div>
    <aside class="home-ad home-side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_right_code']) === '' ? ' ad-empty' : '' ?>" aria-label="首页右侧广告">
        <?php if ($adDisplayEnabled) { echo $adConfig['home_right_code']; } ?>
    </aside>
</main>

<footer class="shell">
    <span>© <?= date('Y') ?> Vidoon 视频素材管理工具</span>
    <span>请仅处理您有权下载和使用的内容</span>
</footer>
<?php if ($adDisplayEnabled && (
    $adConfig['home_left_code'] !== ''
    || $adConfig['home_right_code'] !== ''
    || $adConfig['home_bottom_code'] !== ''
)): ?>
<script>
window.setTimeout(() => {
    document.querySelectorAll('.home-ad .adsbygoogle').forEach(ad => {
        if (ad.getAttribute('data-ad-status') === 'unfilled') {
            ad.closest('.home-ad')?.classList.add('unfilled');
        }
    });
}, 8000);
</script>
<?php endif; ?>
</body>
</html>
