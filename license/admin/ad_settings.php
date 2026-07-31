<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/ad_helpers.php';

$conn = get_db_connection();
$config = get_ad_config($conn);
$rewardShareUrl = rtrim((string)get_runtime_site_value(
    'base_url',
    'https://license.muyanshidai.com/'
), '/') . '/reward.php';
$saved = ($_GET['saved'] ?? '') === '1';
$error = trim((string)($_GET['error'] ?? ''));
if (empty($_SESSION['ads_csrf'])) {
    $_SESSION['ads_csrf'] = bin2hex(random_bytes(24));
}

function ads_admin_e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>广告与免费额度配置</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config={theme:{extend:{colors:{ink:'#10213c',ocean:'#087ea4',mist:'#edf6f8',coral:'#f06b4f'}}}};
</script>
<style>
body{font-family:"Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#eef7fa,#f8fbfc 52%,#fff5f1)}
.field{width:100%;border:1px solid #dbe7ec;border-radius:12px;background:#f8fbfc;padding:10px 12px;font-size:13px;outline:none}
.field:focus{border-color:#38bdf8;box-shadow:0 0 0 4px rgba(56,189,248,.12);background:#fff}
textarea.field{min-height:180px;resize:vertical;font-family:Consolas,monospace;line-height:1.5}
</style>
</head>
<body class="min-h-screen text-slate-800">
<main class="mx-auto max-w-[1380px] px-5 py-7">
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
        <a href="../index.php" title="返回首页" class="flex items-center gap-3">
            <div class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-[#2867ad] via-[#173f72] to-[#0c203c] text-lg font-black text-white shadow-lg shadow-blue-900/20">V</div>
            <div>
                <h1 class="text-2xl font-black text-ink">广告与免费额度</h1>
                <p class="mt-1 text-xs text-slate-500">配置网页普通广告位和免费额度领取规则</p>
            </div>
        </a>
        <nav class="flex flex-wrap gap-2">
            <a href="users.php" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">账号管理</a>
            <a href="payment_settings.php" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">支付配置</a>
            <a href="../reward.php" target="_blank" class="rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2 text-sm font-semibold text-ocean">预览领取页</a>
            <a href="logout.php" class="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white">退出登录</a>
        </nav>
    </header>

    <?php if ($saved): ?>
        <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">广告配置已保存，新页面请求立即生效。</div>
    <?php elseif ($error !== ''): ?>
        <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">保存失败：<?= ads_admin_e($error) ?></div>
    <?php endif; ?>

    <form action="save_ad_settings.php" method="post" class="mt-5 space-y-5">
        <input type="hidden" name="csrf" value="<?= ads_admin_e($_SESSION['ads_csrf']) ?>">

        <section class="grid gap-4 rounded-3xl bg-ink p-5 text-white shadow-xl lg:grid-cols-[1fr_auto_auto] lg:items-center">
            <div>
                <div class="text-lg font-black">广告功能开关</div>
                <p class="mt-1 text-sm text-slate-300">普通广告和激励领取分别控制，关闭激励不会影响网站普通广告展示。</p>
            </div>
            <label class="flex items-center gap-3 rounded-2xl bg-white/10 px-5 py-3">
                <input type="checkbox" name="display_enabled" value="1" class="h-5 w-5" <?= $config['display_enabled'] ? 'checked' : '' ?>>
                <span class="font-bold">启用普通广告</span>
            </label>
            <label class="flex items-center gap-3 rounded-2xl bg-white/10 px-5 py-3">
                <input type="checkbox" name="reward_enabled" value="1" class="h-5 w-5" <?= $config['reward_enabled'] ? 'checked' : '' ?>>
                <span class="font-bold">启用免费额度领取</span>
            </label>
        </section>

        <section class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
            <div>
                <h2 class="text-lg font-black text-ink">AdSense 发布商信息</h2>
                <p class="mt-1 text-xs text-slate-500">发布商 ID 同时用于普通广告和 AdSense 积分墙，请在 AdSense 后台把积分墙定向到 reward.php。</p>
            </div>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">AdSense 发布商 ID
                    <input class="field mt-1.5" name="publisher_id" value="<?= ads_admin_e($config['publisher_id']) ?>" placeholder="ca-pub-1234567890123456">
                </label>
            </div>
            <div class="mt-4">
                <label class="text-xs font-semibold text-slate-600">对外分享的免费领取链接
                    <input class="field mt-1.5" value="<?= ads_admin_e($rewardShareUrl) ?>" readonly onclick="this.select()">
                </label>
                <p class="mt-1.5 text-xs text-slate-500">可直接发送给用户；未登录用户会先进入会员登录或注册，登录后自动返回领取页。</p>
            </div>
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-ink">左侧普通广告代码</h2>
                <p class="mt-1 text-xs text-slate-500">粘贴完整 AdSense 响应式广告代码。该广告不参与次数奖励。</p>
                <textarea class="field mt-4" name="left_code" spellcheck="false" placeholder="<script>...</script>"><?= ads_admin_e($config['left_code']) ?></textarea>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-ink">右侧普通广告代码</h2>
                <p class="mt-1 text-xs text-slate-500">粘贴完整 AdSense 响应式广告代码。建议与领取按钮保持明显间距。</p>
                <textarea class="field mt-4" name="right_code" spellcheck="false" placeholder="<script>...</script>"><?= ads_admin_e($config['right_code']) ?></textarea>
            </div>
        </section>

        <section class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-ink">官网首页左侧广告代码</h2>
                <p class="mt-1 text-xs text-slate-500">显示在 280px 宽的左侧栏，请选择纵向或响应式普通 AdSense 广告位。</p>
                <textarea class="field mt-4" name="home_left_code" spellcheck="false" placeholder="<script>...</script>"><?= ads_admin_e($config['home_left_code']) ?></textarea>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-ink">官网首页右侧广告代码</h2>
                <p class="mt-1 text-xs text-slate-500">显示在 280px 宽的右侧栏，建议使用独立响应式广告单元。</p>
                <textarea class="field mt-4" name="home_right_code" spellcheck="false" placeholder="<script>...</script>"><?= ads_admin_e($config['home_right_code']) ?></textarea>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-ink">首页下载模块下方广告代码</h2>
                <p class="mt-1 text-xs text-slate-500">显示在 Windows 与 macOS 下载卡片正下方，建议使用横向或响应式普通 AdSense 广告位。</p>
                <textarea class="field mt-4" name="home_download_code" spellcheck="false" placeholder="<script>...</script>"><?= ads_admin_e($config['home_download_code']) ?></textarea>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-ink">官网首页底部广告代码</h2>
                <p class="mt-1 text-xs text-slate-500">显示在首页主要内容下方，建议使用横向或响应式普通 AdSense 广告位。</p>
                <textarea class="field mt-4" name="home_bottom_code" spellcheck="false" placeholder="<script>...</script>"><?= ads_admin_e($config['home_bottom_code']) ?></textarea>
            </div>
        </section>

        <section class="rounded-3xl bg-white p-5 shadow-lg ring-1 ring-slate-200">
            <h2 class="text-lg font-black text-ink">免费次数规则</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <label class="text-xs font-semibold text-slate-600">每次奖励下载次数
                    <input class="field mt-1.5" type="number" min="1" max="100" name="reward_count" value="<?= intval($config['reward_count']) ?>">
                </label>
                <label class="text-xs font-semibold text-slate-600">每账号每日最多领取
                    <input class="field mt-1.5" type="number" min="1" max="50" name="daily_view_limit" value="<?= intval($config['daily_view_limit']) ?>">
                </label>
                <label class="text-xs font-semibold text-slate-600">两次领取间隔（秒）
                    <input class="field mt-1.5" type="number" min="30" max="86400" name="cooldown_seconds" value="<?= intval($config['cooldown_seconds']) ?>">
                </label>
            </div>
            <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">
                客户端可携带账号凭证直接进入领取页；网页分享链接会先要求会员登录或注册。用户完成 AdSense 积分墙激励广告并解锁页面后，再由服务器校验每日上限、领取间隔和一次性凭证并发放额度。
            </div>
        </section>

        <div class="sticky bottom-4 flex justify-end">
            <button class="rounded-2xl bg-ocean px-8 py-3 text-sm font-black text-white shadow-xl hover:bg-cyan-700">保存广告配置</button>
        </div>
    </form>
</main>
</body>
</html>
