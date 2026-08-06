<?php
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/site_header.php';

$conn = get_db_connection();
$adConfig = get_ad_config($conn);
$adDisplayEnabled = ad_display_is_enabled($adConfig);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="description" content="了解 Vidoon 视频下载工具的四平台下载、主页提取、批量任务、Cookie 检查、失败重试、封面预览和账号权益功能。">
<?php render_ad_publisher_loader($adConfig); ?>
<title>产品功能 - Vidoon</title>
<style>
:root{--ink:#10213c;--ink-soft:#1d3657;--ocean:#0788ae;--ocean-dark:#056985;--mint:#16b989;--coral:#ed6c51;--line:#d9e7eb;--muted:#687f93}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;min-width:320px;color:var(--ink);font:14px/1.7 "Microsoft YaHei UI","Microsoft YaHei","PingFang SC",sans-serif;background:radial-gradient(circle at 8% 8%,rgba(7,136,174,.15),transparent 28rem),radial-gradient(circle at 94% 45%,rgba(237,108,81,.10),transparent 25rem),linear-gradient(145deg,#fbfdfd,#edf6f8 58%,#fff8f4)}
a{color:inherit;text-decoration:none}.feature-layout{width:min(1480px,calc(100% - 24px));margin:0 auto;display:grid;grid-template-columns:minmax(180px,260px) minmax(0,880px) minmax(180px,260px);justify-content:center;gap:clamp(24px,3vw,48px);align-items:start}.feature-content{width:100%;padding:54px 0 24px}.side-ad,.bottom-ad{position:relative;overflow:hidden;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.76);padding:12px}.side-ad{width:100%;min-height:600px;margin-top:54px}.side-ad::before,.bottom-ad::before{content:"广告";position:absolute;top:7px;left:12px;color:#90a1ae;font-size:10px}.side-ad .adsbygoogle{display:block!important;width:100%!important;min-height:560px}.bottom-ad{width:min(1080px,calc(100% - 32px));min-height:110px;margin:16px auto 54px}.bottom-ad .adsbygoogle{display:block!important;width:100%!important;min-height:80px}.page-ad.ad-empty,.page-ad.unfilled{visibility:hidden}
.hero{position:relative;overflow:hidden;border-radius:30px;padding:48px;background:linear-gradient(125deg,#10213c 0%,#153d5b 64%,#0788ae 100%);color:#fff;box-shadow:0 26px 65px rgba(16,33,60,.18)}.hero::after{content:"";position:absolute;right:-70px;bottom:-100px;width:300px;height:300px;border:48px solid rgba(255,255,255,.06);border-radius:50%}.eyebrow{position:relative;z-index:1;color:#65d9f1;font-size:12px;font-weight:900;letter-spacing:.18em}.hero h1{position:relative;z-index:1;max-width:680px;margin:14px 0 0;font-size:clamp(36px,5vw,56px);line-height:1.08;letter-spacing:-.05em}.hero h1 em{color:#71e3f7;font-style:normal}.hero p{position:relative;z-index:1;max-width:680px;margin:20px 0 0;color:#cbdce7;font-size:16px;line-height:1.8}.hero-actions{position:relative;z-index:1;display:flex;flex-wrap:wrap;gap:11px;margin-top:28px}.button{display:inline-flex;min-height:44px;align-items:center;justify-content:center;border-radius:12px;padding:0 19px;font-weight:900}.button.primary{background:#fff;color:var(--ink)}.button.secondary{border:1px solid rgba(255,255,255,.28);color:#fff;background:rgba(255,255,255,.08)}
.section-head{display:flex;align-items:end;justify-content:space-between;gap:30px;margin:58px 0 22px}.section-head h2{margin:0;font-size:32px;letter-spacing:-.04em}.section-head p{max-width:430px;margin:0;color:var(--muted);font-size:14px}.feature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.feature-card{position:relative;min-height:225px;border:1px solid rgba(16,33,60,.10);border-radius:22px;padding:25px;background:rgba(255,255,255,.88);box-shadow:0 15px 38px rgba(48,78,96,.08)}.feature-number{color:var(--ocean);font:italic 900 20px Georgia,serif}.feature-card h3{margin:17px 0 0;font-size:19px}.feature-card p{margin:9px 0 0;color:var(--muted);font-size:14px;line-height:1.75}.feature-tags{display:flex;flex-wrap:wrap;gap:7px;margin-top:17px}.feature-tags span{border-radius:999px;padding:5px 9px;color:#476478;background:#edf6f8;font-size:11px;font-weight:800}
.workflow{display:grid;grid-template-columns:repeat(3,1fr);margin-top:18px;border:1px solid var(--line);border-radius:23px;overflow:hidden;background:rgba(255,255,255,.82)}.workflow article{padding:25px}.workflow article+article{border-left:1px solid var(--line)}.workflow b{color:var(--ocean);font-size:12px;letter-spacing:.12em}.workflow h3{margin:10px 0 0;font-size:18px}.workflow p{margin:7px 0 0;color:var(--muted)}.notice{margin-top:18px;border:1px solid #f0d7a3;border-radius:17px;padding:17px 19px;color:#76591d;background:#fff9e9;font-size:13px;line-height:1.75}.closing{display:flex;align-items:center;justify-content:space-between;gap:30px;margin:48px 0 32px;border-radius:24px;padding:30px;background:linear-gradient(135deg,#e6f7fa,#fff);box-shadow:0 16px 38px rgba(48,78,96,.08)}.closing h2{margin:0;font-size:25px}.closing p{margin:7px 0 0;color:var(--muted)}.closing .button{flex:0 0 auto;color:#fff;background:var(--ocean)}
@media(max-width:1180px){.feature-layout{width:min(880px,calc(100% - 32px));grid-template-columns:minmax(0,1fr)}.side-ad{display:none}}@media(max-width:720px){.feature-content{padding-top:32px}.hero{padding:34px 24px;border-radius:24px}.feature-grid,.workflow{grid-template-columns:1fr}.workflow article+article{border-left:0;border-top:1px solid var(--line)}.section-head,.closing{align-items:flex-start;flex-direction:column}.feature-card{min-height:auto}.closing .button{width:100%}}
</style>
</head>
<body>
<?php render_site_header('features'); ?>
<main class="feature-layout">
    <aside class="page-ad side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_left_code']) === '' ? ' ad-empty' : '' ?>" aria-label="产品功能页左侧广告">
        <?php if ($adDisplayEnabled) { echo $adConfig['home_left_code']; } ?>
    </aside>
    <div class="feature-content">
        <section class="hero">
            <div class="eyebrow">VIDOON PRODUCT FEATURES</div>
            <h1>把视频下载需要的步骤，<em>集中到一个工作台</em></h1>
            <p>面向短视频创作者、内容运营和日常素材归档场景，集中处理链接识别、主页提取、批量任务、下载进度、失败重试和本地结果管理。</p>
            <div class="hero-actions">
                <a class="button primary" href="download.php">下载 Windows 版</a>
                <a class="button secondary" href="subscribe.php">查看订阅套餐</a>
            </div>
        </section>

        <div class="section-head">
            <h2>用户实际可以使用的功能</h2>
            <p>围绕“找到链接、完成下载、检查结果”设计，避免堆叠大多数用户用不到的复杂选项。</p>
        </div>

        <section class="feature-grid">
            <article class="feature-card">
                <div class="feature-number">01</div>
                <h3>四个平台链接下载</h3>
                <p>识别 YouTube、TikTok、Instagram 与 Twitter/X 的常见视频链接，并按平台调用对应下载策略。</p>
                <div class="feature-tags"><span>YouTube</span><span>TikTok</span><span>Instagram</span><span>Twitter/X</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">02</div>
                <h3>单条与批量任务</h3>
                <p>支持粘贴多条链接、TXT 文件导入和任务列表处理；下载进度、速度、成功与失败结果集中显示。</p>
                <div class="feature-tags"><span>多链接</span><span>TXT 导入</span><span>批量下载</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">03</div>
                <h3>主页视频提取</h3>
                <p>提取支持平台主页或列表中的视频条目，可按当前页选择、忽略已下载内容，并依次执行下载。</p>
                <div class="feature-tags"><span>主页列表</span><span>分页选择</span><span>重复过滤</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">04</div>
                <h3>最佳可用画质</h3>
                <p>优先选择当前链接能够获取的最佳可用视频与音频组合，并在成功结果中显示实际画质信息。</p>
                <div class="feature-tags"><span>画质选择</span><span>音视频合并</span><span>画质记录</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">05</div>
                <h3>Cookie 状态检查</h3>
                <p>集中配置四个平台 Cookie 文件，启动后检查文件状态、健康度与可识别的有效期，减少反复排查。</p>
                <div class="feature-tags"><span>健康检查</span><span>到期提示</span><span>平台隔离</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">06</div>
                <h3>失败重试与详细日志</h3>
                <p>遇到临时网络、Cookie 或解析问题时按策略重试，并保留实时进度、速度和错误原因，方便定位问题。</p>
                <div class="feature-tags"><span>自动重试</span><span>实时速度</span><span>错误日志</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">07</div>
                <h3>封面、预览与本地结果</h3>
                <p>主页提取任务可同步保存视频封面；下载完成后记录文件路径，并支持从结果列表进行本地预览。</p>
                <div class="feature-tags"><span>封面保存</span><span>视频预览</span><span>路径记录</span></div>
            </article>
            <article class="feature-card">
                <div class="feature-number">08</div>
                <h3>账号权益同步</h3>
                <p>客户端与服务器同步订阅等级、到期状态、设备数量和下载额度；免费用户也可按官网规则领取额度。</p>
                <div class="feature-tags"><span>订阅同步</span><span>设备授权</span><span>额度管理</span></div>
            </article>
        </section>

        <div class="section-head">
            <h2>从链接到本地文件</h2>
            <p>把重复步骤集中在清晰的任务流程中，保留必要信息，不增加无关操作。</p>
        </div>
        <section class="workflow">
            <article><b>STEP 01</b><h3>添加链接</h3><p>粘贴链接、导入 TXT，或从主页提取视频列表。</p></article>
            <article><b>STEP 02</b><h3>执行下载</h3><p>选择任务后查看当前视频进度、速度和重试状态。</p></article>
            <article><b>STEP 03</b><h3>核对结果</h3><p>确认实际画质、保存路径、封面和可预览文件。</p></article>
        </section>
        <div class="notice">实际下载结果取决于平台规则、链接状态、网络环境、Cookie 有效性以及源视频提供的清晰度。软件不会提升原始画质，也不能保证所有链接始终可用。</div>

        <section class="closing">
            <div><h2>先用真实链接验证是否适合</h2><p>下载软件并注册免费账号，体验完整的链接识别和任务流程。</p></div>
            <a class="button" href="download.php">前往软件下载</a>
        </section>
    </div>
    <aside class="page-ad side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_right_code']) === '' ? ' ad-empty' : '' ?>" aria-label="产品功能页右侧广告">
        <?php if ($adDisplayEnabled) { echo $adConfig['home_right_code']; } ?>
    </aside>
</main>
<aside class="page-ad bottom-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_bottom_code']) === '' ? ' ad-empty' : '' ?>" aria-label="产品功能页底部广告">
    <?php if ($adDisplayEnabled) { echo $adConfig['home_bottom_code']; } ?>
</aside>
<?php if ($adDisplayEnabled && ($adConfig['home_left_code'] !== '' || $adConfig['home_right_code'] !== '' || $adConfig['home_bottom_code'] !== '')): ?>
<script>
window.setTimeout(() => {
    document.querySelectorAll('.page-ad .adsbygoogle').forEach(ad => {
        if (ad.getAttribute('data-ad-status') === 'unfilled') {
            ad.closest('.page-ad')?.classList.add('unfilled');
        }
    });
}, 8000);
</script>
<?php endif; ?>
</body>
</html>
