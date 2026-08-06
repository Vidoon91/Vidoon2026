<?php

function render_site_header($active = '') {
    $active = strtolower(trim((string)$active));
    $links = [
        ['key' => 'features', 'href' => 'features.php', 'label' => '产品功能'],
        ['key' => 'download', 'href' => 'download.php', 'label' => '软件下载'],
        ['key' => 'subscribe', 'href' => 'subscribe.php', 'label' => '订阅套餐'],
        ['key' => 'steps', 'href' => 'index.php#steps', 'label' => '使用流程'],
        ['key' => 'register', 'href' => 'register.php', 'label' => '会员注册'],
    ];
    ?>
    <style>
    .site-header-shell{position:sticky;top:0;z-index:1000;width:min(1180px,calc(100% - 36px));margin:0 auto;background:rgba(247,251,252,.92);box-shadow:0 10px 30px rgba(34,67,88,.08);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
    .site-header{display:flex;align-items:center;justify-content:space-between;min-height:72px;border-bottom:1px solid rgba(16,33,60,.10)}
    .site-brand{display:flex;align-items:center;gap:11px;color:#10213c!important;font-size:18px;font-weight:900;text-decoration:none!important}
    .site-brand-mark{display:grid;width:42px;height:42px;place-items:center;border-radius:14px;color:#fff;background:linear-gradient(145deg,#2867ad 0%,#173f72 42%,#0c203c 100%);box-shadow:0 12px 25px rgba(20,63,114,.24)}
    .site-brand-copy{display:block;line-height:1.2}.site-brand-copy small{display:block;margin-top:3px;color:#6c8094;font-size:11px;font-weight:700;letter-spacing:.08em}
    .site-nav-links{display:flex;align-items:center;gap:26px;color:#3f586f;font-size:14px;font-weight:800}
    .site-nav-links a{color:inherit;text-decoration:none}.site-nav-links a:hover,.site-nav-links a.is-active{color:#0788ae}
    .site-nav-links .site-nav-cta{border-radius:12px;padding:11px 19px;color:#fff!important;background:#0788ae;box-shadow:0 8px 18px rgba(7,136,174,.18)}
    @media(max-width:860px){.site-nav-links{gap:14px}.site-nav-links a:not(.site-nav-cta){display:none}}
    @media(max-width:520px){.site-header-shell{width:min(100% - 22px,1180px)}.site-brand-copy small{display:none}.site-nav-links .site-nav-cta{padding:10px 14px}}
    </style>
    <header class="site-header-shell">
        <div class="site-header">
            <a class="site-brand" href="index.php" title="返回首页">
                <span class="site-brand-mark">V</span>
                <span class="site-brand-copy">Vidoon<small>VIDEO WORKSPACE</small></span>
            </a>
            <nav class="site-nav-links" aria-label="官网导航">
                <?php foreach ($links as $link): ?>
                    <a
                        href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= $active === $link['key'] ? 'is-active' : '' ?>"
                        <?= $active === $link['key'] ? 'aria-current="page"' : '' ?>
                    ><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
                <a class="site-nav-cta<?= $active === 'subscribe' ? ' is-active' : '' ?>" href="subscribe.php">立即订阅</a>
            </nav>
        </div>
    </header>
    <?php
}
