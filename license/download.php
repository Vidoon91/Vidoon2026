<?php
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/site_header.php';

$conn = get_db_connection();
$defaultDownloadUrl = 'https://github.com/Vidoon91/Vidoon2026/releases/latest/download/Vidoon2026_latest.zip';
$downloadUrl = $defaultDownloadUrl;
$baiduDownloadUrl = '';
$quarkDownloadUrl = '';
$settingsError = null;
if (ensure_app_settings_table($conn, $settingsError)) {
    $downloadUrl = get_app_setting($conn, 'download_url', $defaultDownloadUrl);
    $baiduDownloadUrl = get_app_setting($conn, 'download_baidu_url', '');
    $quarkDownloadUrl = get_app_setting($conn, 'download_quark_url', '');
}
$downloadUrl = str_replace(
    'https://github.com/Vidoon91/vidoon-update/releases/download/latest/Vidoon2026_latest.zip',
    $defaultDownloadUrl,
    $downloadUrl
);
$adConfig = get_ad_config($conn);
$adDisplayEnabled = ad_display_is_enabled($adConfig);

function download_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="description" content="下载 Vidoon Windows 桌面版，支持官方下载、百度网盘和夸克网盘。">
<?php render_ad_publisher_loader($adConfig); ?>
<title>软件下载 - Vidoon</title>
<style>
:root{--ink:#10213c;--ocean:#0788ae;--line:#d9e7eb;--muted:#687f93}
*{box-sizing:border-box}body{margin:0;min-width:320px;color:var(--ink);font:14px/1.7 "Microsoft YaHei UI","Microsoft YaHei","PingFang SC",sans-serif;background:radial-gradient(circle at 8% 8%,rgba(7,136,174,.15),transparent 28rem),radial-gradient(circle at 94% 45%,rgba(237,108,81,.10),transparent 25rem),linear-gradient(145deg,#fbfdfd,#edf6f8 58%,#fff8f4)}a{color:inherit;text-decoration:none}
.download-layout{width:min(1480px,calc(100% - 24px));margin:0 auto;display:grid;grid-template-columns:minmax(180px,260px) minmax(0,880px) minmax(180px,260px);justify-content:center;gap:clamp(24px,3vw,48px);align-items:start}.download-content{width:100%;padding:54px 0 30px}
.page-ad{position:relative;overflow:hidden;border:1px solid var(--line);border-radius:20px;background:rgba(255,255,255,.76);padding:12px}.side-ad{width:100%;min-height:600px;margin-top:54px}.side-ad::before,.bottom-ad::before{content:"广告";position:absolute;top:7px;left:12px;color:#90a1ae;font-size:10px}.side-ad .adsbygoogle{display:block!important;width:100%!important;min-height:560px}.bottom-ad{width:min(1080px,calc(100% - 32px));min-height:110px;margin:16px auto 54px}.bottom-ad .adsbygoogle{display:block!important;width:100%!important;min-height:80px}.page-ad.ad-empty,.page-ad.unfilled{visibility:hidden}
.hero{position:relative;overflow:hidden;border-radius:30px;padding:44px 48px;color:#fff;background:linear-gradient(125deg,#10213c 0%,#153d5b 64%,#0788ae 100%);box-shadow:0 26px 65px rgba(16,33,60,.18)}.hero::after{content:"";position:absolute;right:-50px;bottom:-100px;width:280px;height:280px;border:44px solid rgba(255,255,255,.06);border-radius:50%}.eyebrow{position:relative;z-index:1;color:#65d9f1;font-size:12px;font-weight:900;letter-spacing:.18em}.hero h1{position:relative;z-index:1;margin:12px 0 0;font-size:clamp(35px,5vw,52px);line-height:1.1;letter-spacing:-.05em}.hero p{position:relative;z-index:1;max-width:650px;margin:17px 0 0;color:#cbdce7;font-size:16px;line-height:1.8}
.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:24px}.card{overflow:hidden;border:1px solid var(--line);border-radius:22px;background:rgba(255,255,255,.9);box-shadow:0 16px 38px rgba(50,80,98,.08)}.card[open]{border-color:#a7d7e2;box-shadow:0 20px 45px rgba(50,80,98,.12)}.card summary{display:flex;align-items:center;gap:15px;padding:21px;cursor:pointer;list-style:none;user-select:none}.card summary::-webkit-details-marker{display:none}.os-mark{display:grid;width:47px;height:47px;flex:0 0 47px;place-items:center;border-radius:15px;color:#fff;background:linear-gradient(145deg,#2867ad,#173f72 50%,#0c203c);font-size:12px;font-weight:900}.os-mark.mac{color:var(--ink);background:linear-gradient(145deg,#fff,#dce8ed);box-shadow:inset 0 0 0 1px #d5e2e7}.os-title{flex:1}.os-title strong{display:block;font-size:18px}.os-title span{display:block;margin-top:4px;color:var(--muted);font-size:14px}.state{border-radius:999px;padding:6px 9px;color:#087758;background:#e6f8f1;font-size:12px;font-weight:900}.state.soon{color:#7c5b16;background:#fff5d9}.chevron{width:9px;height:9px;border-right:2px solid #75899b;border-bottom:2px solid #75899b;transform:rotate(45deg);transition:transform .2s}.card[open] .chevron{transform:rotate(225deg)}.card-body{border-top:1px solid #e4edef;padding:20px 21px 22px}.requirements{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.requirement{border-radius:11px;background:#f2f7f9;padding:10px}.requirement b{display:block;color:#718596;font-size:12px;letter-spacing:.08em}.requirement span{display:block;margin-top:5px;font-size:14px;font-weight:900}.note{margin:14px 0 0;color:var(--muted);font-size:14px;line-height:1.7}.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:16px}.button{display:inline-flex;min-height:42px;align-items:center;justify-content:center;border-radius:11px;padding:0 17px;color:#fff;background:var(--ocean);font-size:14px;font-weight:900;box-shadow:0 9px 20px rgba(7,136,174,.18)}.button.baidu{background:#2468f2}.button.quark{background:#14a67a}.button.is-starting{opacity:.72;pointer-events:none}.button.disabled{color:#8193a1;background:#e3ecef;box-shadow:none;cursor:not-allowed}
.guide{margin-top:20px;border:1px solid var(--line);border-radius:20px;padding:24px;background:rgba(255,255,255,.82)}.guide h2{margin:0;font-size:22px}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:17px}.step{border-radius:14px;padding:15px;background:#f2f7f9}.step b{color:var(--ocean);font-size:12px}.step span{display:block;margin-top:5px;color:#486175}.download-ad{min-height:110px;margin-top:22px}.download-ad .adsbygoogle{display:block!important;width:100%!important;min-height:80px}.toast{position:fixed;z-index:2000;top:82px;left:50%;width:min(440px,calc(100% - 32px));transform:translate(-50%,-16px);border:1px solid #9edbe8;border-radius:14px;padding:13px 17px;color:#075b72;background:rgba(239,252,255,.97);box-shadow:0 18px 45px rgba(15,50,68,.2);font-weight:800;opacity:0;visibility:hidden;transition:.2s}.toast.show{transform:translate(-50%,0);opacity:1;visibility:visible}
footer{display:flex;justify-content:space-between;width:min(880px,calc(100% - 32px));min-height:76px;margin:0 auto;align-items:center;border-top:1px solid rgba(16,33,60,.1);color:var(--muted)}
@media(max-width:1180px){.download-layout{width:min(880px,calc(100% - 32px));grid-template-columns:minmax(0,1fr)}.side-ad{display:none}}@media(max-width:720px){.download-content{padding-top:32px}.hero{padding:34px 24px;border-radius:24px}.cards,.steps{grid-template-columns:1fr}.requirements{grid-template-columns:1fr}footer{align-items:flex-start;flex-direction:column;justify-content:center;gap:6px}}
</style>
</head>
<body>
<?php render_site_header('download'); ?>
<main class="download-layout">
    <aside class="page-ad side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_left_code']) === '' ? ' ad-empty' : '' ?>" aria-label="软件下载页左侧广告"><?php if ($adDisplayEnabled) { echo $adConfig['home_left_code']; } ?></aside>
    <div class="download-content">
        <section class="hero">
            <div class="eyebrow">VIDOON DESKTOP DOWNLOAD</div>
            <h1>下载 Vidoon 桌面版</h1>
            <p>Windows 版现已开放下载。请选择适合的下载渠道，解压到独立文件夹后即可使用。</p>
        </section>
        <section class="cards" aria-label="软件下载版本">
            <details class="card" open>
                <summary><span class="os-mark">WIN</span><span class="os-title"><strong>Windows 版</strong><span>适用于 Windows 10 / 11 64 位系统</span></span><span class="state">可下载</span><i class="chevron"></i></summary>
                <div class="card-body">
                    <div class="requirements"><div class="requirement"><b>系统</b><span>Windows 10 / 11</span></div><div class="requirement"><b>架构</b><span>64 位</span></div><div class="requirement"><b>安装方式</b><span>解压后使用</span></div></div>
                    <p class="note">下载完成后解压到独立文件夹，直接使用新版本；不要覆盖旧目录，确认正常后再删除旧版本。</p>
                    <div class="actions">
                        <a class="button js-direct-download" href="<?= download_e($downloadUrl) ?>" target="_blank" rel="noopener noreferrer" title="通过 GitHub 直接下载 Windows 版">GitHub</a>
                        <?php if ($baiduDownloadUrl !== ''): ?><a class="button baidu" href="<?= download_e($baiduDownloadUrl) ?>" target="_blank" rel="noopener noreferrer">百度网盘</a><?php endif; ?>
                        <?php if ($quarkDownloadUrl !== ''): ?><a class="button quark" href="<?= download_e($quarkDownloadUrl) ?>" target="_blank" rel="noopener noreferrer">夸克网盘</a><?php endif; ?>
                    </div>
                </div>
            </details>
            <details class="card">
                <summary><span class="os-mark mac">macOS</span><span class="os-title"><strong>macOS 版</strong><span>面向 Apple 芯片与 Intel Mac 的规划版本</span></span><span class="state soon">即将推出</span><i class="chevron"></i></summary>
                <div class="card-body"><div class="requirements"><div class="requirement"><b>系统</b><span>macOS</span></div><div class="requirement"><b>架构</b><span>Apple / Intel</span></div><div class="requirement"><b>当前状态</b><span>正在适配</span></div></div><p class="note">当前尚未提供 macOS 安装包，完成兼容性测试后将在此开放下载。</p><span class="button disabled" aria-disabled="true">macOS 版即将推出</span></div>
            </details>
        </section>
        <section class="guide"><h2>更新与使用说明</h2><div class="steps"><div class="step"><b>01 下载</b><span>选择官方下载或网盘渠道。</span></div><div class="step"><b>02 解压</b><span>解压到新的独立文件夹。</span></div><div class="step"><b>03 使用</b><span>确认新版正常后删除旧版本。</span></div></div></section>
        <?php if ($adDisplayEnabled && trim((string)$adConfig['home_download_code']) !== ''): ?><aside class="page-ad download-ad" aria-label="软件下载页内容广告"><?= $adConfig['home_download_code'] ?></aside><?php endif; ?>
    </div>
    <aside class="page-ad side-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_right_code']) === '' ? ' ad-empty' : '' ?>" aria-label="软件下载页右侧广告"><?php if ($adDisplayEnabled) { echo $adConfig['home_right_code']; } ?></aside>
</main>
<aside class="page-ad bottom-ad<?= !$adDisplayEnabled || trim((string)$adConfig['home_bottom_code']) === '' ? ' ad-empty' : '' ?>" aria-label="软件下载页底部广告"><?php if ($adDisplayEnabled) { echo $adConfig['home_bottom_code']; } ?></aside>
<div class="toast" id="download-toast" role="status" aria-live="polite"></div>
<footer><span>© <?= date('Y') ?> Vidoon</span><span>请仅处理您有权下载和使用的内容</span></footer>
<script>
(() => {
    const button = document.querySelector('.js-direct-download');
    const toast = document.getElementById('download-toast');
    if (!button || !toast) return;
    let timer = null;
    button.addEventListener('click', event => {
        event.preventDefault();
        if (button.classList.contains('is-starting')) return;
        button.classList.add('is-starting');
        button.setAttribute('aria-busy', 'true');
        toast.textContent = '正在开始下载，请稍候查看浏览器下载列表。若未自动开始，可使用百度网盘或夸克网盘。';
        toast.classList.add('show');
        const frame = document.createElement('iframe');
        frame.title = '软件下载'; frame.setAttribute('aria-hidden', 'true');
        Object.assign(frame.style, {position:'fixed',left:'-9999px',top:'-9999px',width:'1px',height:'1px',border:'0',opacity:'0'});
        frame.src = button.href; document.body.appendChild(frame);
        window.clearTimeout(timer); timer = window.setTimeout(() => toast.classList.remove('show'), 4500);
        window.setTimeout(() => { button.classList.remove('is-starting'); button.removeAttribute('aria-busy'); }, 2500);
        window.setTimeout(() => frame.remove(), 60000);
    });
})();
window.setTimeout(() => document.querySelectorAll('.page-ad .adsbygoogle').forEach(ad => {
    if (ad.getAttribute('data-ad-status') === 'unfilled') ad.closest('.page-ad')?.classList.add('unfilled');
}), 8000);
</script>
</body>
</html>
