<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/db.php';
require_once '../include/account_helpers.php';

$conn = get_db_connection();
ensure_user_subscription_columns($conn);
sync_user_statuses_by_expiry($conn);

$q = trim($_GET['q'] ?? '');
$level = trim($_GET['level'] ?? '');
$status = trim($_GET['status'] ?? '');
$siteSettingsError = null;
$siteSettingsReady = ensure_app_settings_table($conn, $siteSettingsError);
$subscriptionUrl = $siteSettingsReady
    ? get_app_setting($conn, 'subscription_url', 'https://www.muyanshidai.com/')
    : 'https://www.muyanshidai.com/';
$defaultDownloadUrl = 'https://github.com/Vidoon91/vidoon-update/releases/download/latest/Vidoon2026_latest.zip';
$downloadUrl = $siteSettingsReady
    ? get_app_setting($conn, 'download_url', $defaultDownloadUrl)
    : $defaultDownloadUrl;
$baiduDownloadUrl = $siteSettingsReady
    ? get_app_setting($conn, 'download_baidu_url', '')
    : '';
$quarkDownloadUrl = $siteSettingsReady
    ? get_app_setting($conn, 'download_quark_url', '')
    : '';
$siteSaved = ($_GET['site_saved'] ?? '') === '1';
$siteError = trim((string)($_GET['site_error'] ?? ''));

$condition = " WHERE 1=1 ";
if ($q !== '') {
    $safeQ = $conn->real_escape_string($q);
    $condition .= " AND (email LIKE '%{$safeQ}%' OR phone LIKE '%{$safeQ}%' OR display_name LIKE '%{$safeQ}%') ";
}
if ($level === 'free') {
    $condition .= " AND account_level = 'free' ";
} elseif ($level === 'paid') {
    $condition .= " AND account_level <> 'free' ";
}
if ($status === '1' || $status === '0') {
    $condition .= " AND status = " . intval($status) . " ";
}

$perPage = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$countSql = "SELECT COUNT(*) AS total FROM users {$condition}";
$total = intval(($conn->query($countSql)->fetch_assoc()['total'] ?? 0));
$totalPages = max(1, intval(ceil($total / $perPage)));
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        u.*,
        (SELECT COUNT(*) FROM user_devices d WHERE d.user_id = u.id AND d.status = 1) AS active_device_count,
        (SELECT COALESCE(SUM(l.url_count), 0) FROM user_download_logs l WHERE l.user_id = u.id AND DATE(l.created_at) = CURDATE()) AS today_download_count,
        (SELECT COALESCE(SUM(l2.url_count), 0) FROM user_download_logs l2 WHERE l2.user_id = u.id) AS total_download_count
    FROM users u
    {$condition}
    ORDER BY u.id DESC
    LIMIT {$offset}, {$perPage}
";
$res = $conn->query($sql);

$statTotal = intval(($conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'] ?? 0));
$statFree = intval(($conn->query("SELECT COUNT(*) AS c FROM users WHERE account_level='free'")->fetch_assoc()['c'] ?? 0));
$statPaid = intval(($conn->query("SELECT COUNT(*) AS c FROM users WHERE account_level<>'free'")->fetch_assoc()['c'] ?? 0));
$statToday = intval(($conn->query("SELECT COUNT(*) AS c FROM users WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0));

$params = [];
if ($q !== '') {
    $params['q'] = $q;
}
if ($level !== '') {
    $params['level'] = $level;
}
if ($status !== '') {
    $params['status'] = $status;
}
$baseQ = http_build_query($params);

function admin_datetime_local_value($value) {
    $value = trim((string)($value ?? ''));
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d\TH:i', $timestamp) : '';
}

function admin_subscription_label($row) {
    return ($row['account_level'] ?? 'free') === 'free' ? '免费订阅' : '付费订阅';
}

function admin_subscription_badge_class($row) {
    return ($row['account_level'] ?? 'free') === 'free'
        ? 'bg-slate-100 text-slate-700'
        : 'bg-emerald-100 text-emerald-700';
}

function admin_account_status_label($row) {
    return intval($row['status'] ?? 0) === 1 ? '已启用' : '已禁用';
}

function admin_account_status_class($row, $withBackground = false) {
    if (intval($row['status'] ?? 0) === 1) {
        return $withBackground ? 'bg-emerald-50 text-emerald-600' : 'text-emerald-600';
    }
    return $withBackground ? 'bg-rose-50 text-rose-600' : 'text-rose-600';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>账号用户管理</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                ink: '#10213c',
                ocean: '#087ea4',
                mist: '#edf6f8',
                coral: '#f06b4f',
            },
            boxShadow: {
                soft: '0 18px 48px rgba(27, 74, 94, 0.09)',
                card: '0 8px 24px rgba(27, 74, 94, 0.07)',
            }
        }
    }
};
</script>
<style>
:root {
    --ink: #10213c;
    --ocean: #087ea4;
    --line: #dce9ed;
}
* { box-sizing: border-box; }
body {
    font-family: "HarmonyOS Sans SC", "Microsoft YaHei UI", "Microsoft YaHei", sans-serif;
    background:
        radial-gradient(circle at 7% 4%, rgba(8, 126, 164, .11), transparent 25rem),
        radial-gradient(circle at 94% 18%, rgba(240, 107, 79, .08), transparent 24rem),
        linear-gradient(180deg, #f7fbfc 0%, #eef6f8 100%);
}
body::before {
    content: "";
    position: fixed;
    inset: 0;
    pointer-events: none;
    opacity: .28;
    background-image: linear-gradient(rgba(16,33,60,.035) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(16,33,60,.035) 1px, transparent 1px);
    background-size: 32px 32px;
    mask-image: linear-gradient(to bottom, black, transparent 72%);
}
.page-enter { animation: pageEnter .55s cubic-bezier(.2,.8,.2,1) both; }
.stagger-card { animation: cardEnter .55s cubic-bezier(.2,.8,.2,1) both; }
.stagger-card:nth-child(2) { animation-delay: .06s; }
.stagger-card:nth-child(3) { animation-delay: .12s; }
.stagger-card:nth-child(4) { animation-delay: .18s; }
@keyframes pageEnter { from { opacity: 0; transform: translateY(10px); } }
@keyframes cardEnter { from { opacity: 0; transform: translateY(14px); } }
input, select, button, a { -webkit-tap-highlight-color: transparent; }
input[type="datetime-local"]::-webkit-calendar-picker-indicator { opacity: .55; }
</style>
<script>
function confirmAccountAction(form) {
    const select = form.elements.namedItem('delete_account');
    if (select && select.value === '1') {
        return window.confirm('确认删除该账号吗？删除后该账号及关联设备、登录会话、下载记录将被清空。');
    }
    return true;
}
</script>
</head>
<body class="min-h-screen text-slate-800">
<div class="page-enter relative z-10 mx-auto max-w-[1480px] px-4 py-5 sm:px-6 lg:px-8 lg:py-8">
    <header class="grid grid-cols-1 gap-4 border-b border-slate-200/80 pb-4 xl:grid-cols-[auto_minmax(0,1fr)_auto] xl:items-center">
        <a href="../index.php" title="返回首页" class="flex items-center gap-4">
            <div class="grid h-12 w-12 place-items-center rounded-[18px] bg-gradient-to-br from-[#2867ad] via-[#173f72] to-[#0c203c] text-xl font-black text-white shadow-lg shadow-blue-900/20">V</div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-black tracking-tight text-ink sm:text-2xl">Vidoon 账号管理</h1>
                    <span class="rounded-full bg-cyan-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-ocean ring-1 ring-cyan-100">Admin</span>
                </div>
                <p class="mt-1 text-xs text-slate-500 sm:text-sm">集中管理用户订阅、设备与下载额度</p>
            </div>
        </a>
        <form method="get" class="grid min-w-0 grid-cols-1 gap-1.5 rounded-2xl bg-white/75 p-2 shadow-sm ring-1 ring-slate-200/80 backdrop-blur md:grid-cols-[minmax(180px,1fr)_120px_110px_auto_auto]">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" aria-label="搜索用户" placeholder="邮箱 / 手机号 / 昵称" class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 text-xs outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70">
            <select name="level" aria-label="订阅类型" class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-2 text-xs outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70">
                <option value="">全部订阅</option>
                <option value="free" <?= $level === 'free' ? 'selected' : '' ?>>免费订阅</option>
                <option value="paid" <?= $level === 'paid' ? 'selected' : '' ?>>付费订阅</option>
            </select>
            <select name="status" aria-label="账号状态" class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-2.5 py-2 text-xs outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70">
                <option value="">全部状态</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>启用</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>禁用</option>
            </select>
            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-ocean px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-cyan-700">筛选</button>
            <a href="users.php" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">重置</a>
        </form>
        <div class="flex flex-wrap items-center justify-end gap-3">
            <a href="payment_settings.php" class="inline-flex items-center justify-center rounded-full border border-cyan-200 bg-cyan-50 px-4 py-2.5 text-sm font-semibold text-ocean shadow-sm transition hover:bg-cyan-100">支付配置</a>
            <a href="ad_settings.php" class="inline-flex items-center justify-center rounded-full border border-cyan-200 bg-cyan-50 px-4 py-2.5 text-sm font-semibold text-ocean shadow-sm transition hover:bg-cyan-100">广告配置</a>
            <a href="orders.php" class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50">订单管理</a>
            <div class="hidden rounded-full bg-white/75 px-4 py-2 text-xs text-slate-500 ring-1 ring-slate-200/80 md:block">
                <?= date('Y年m月d日') ?>
            </div>
            <a href="logout.php" class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600">退出登录</a>
        </div>
    </header>

    <section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        <div class="stagger-card relative overflow-hidden rounded-2xl bg-ink p-4 text-white shadow-soft">
            <div class="absolute -right-6 -top-6 h-20 w-20 rounded-full border-[14px] border-white/5"></div>
            <div class="text-xs font-semibold tracking-wide text-slate-300">全部账号</div>
            <div class="mt-1.5 text-2xl font-black tracking-tight sm:text-3xl"><?= $statTotal ?></div>
            <div class="mt-1.5 text-[11px] text-slate-400">平台累计注册用户</div>
        </div>
        <div class="stagger-card relative overflow-hidden rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-200/70">
            <div class="absolute right-4 top-4 h-2 w-2 rounded-full bg-sky-400 shadow-[0_0_0_5px_rgba(56,189,248,.12)]"></div>
            <div class="text-xs font-semibold tracking-wide text-slate-500">免费订阅</div>
            <div class="mt-1.5 text-2xl font-black tracking-tight text-ink sm:text-3xl"><?= $statFree ?></div>
            <div class="mt-1.5 text-[11px] text-slate-400">当前免费用户数量</div>
        </div>
        <div class="stagger-card relative overflow-hidden rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-200/70">
            <div class="absolute right-4 top-4 h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_0_5px_rgba(52,211,153,.12)]"></div>
            <div class="text-xs font-semibold tracking-wide text-slate-500">付费订阅</div>
            <div class="mt-1.5 text-2xl font-black tracking-tight text-emerald-600 sm:text-3xl"><?= $statPaid ?></div>
            <div class="mt-1.5 text-[11px] text-slate-400">当前付费用户数量</div>
        </div>
        <div class="stagger-card relative overflow-hidden rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-200/70">
            <div class="absolute right-4 top-4 h-2 w-2 rounded-full bg-coral shadow-[0_0_0_5px_rgba(240,107,79,.12)]"></div>
            <div class="text-xs font-semibold tracking-wide text-slate-500">今日新增</div>
            <div class="mt-1.5 text-2xl font-black tracking-tight text-coral sm:text-3xl"><?= $statToday ?></div>
            <div class="mt-1.5 text-[11px] text-slate-400">今日完成注册的用户</div>
        </div>
    </section>

    <div class="mt-5 grid gap-3 xl:grid-cols-[150px_minmax(0,1fr)] xl:items-end">
        <div>
            <h2 class="text-lg font-black tracking-tight text-ink">账号列表</h2>
            <div class="mt-1 text-xs text-slate-500">当前第 <?= $page ?> / <?= $totalPages ?> 页</div>
        </div>
        <form action="update_site_settings.php" method="post" class="grid min-w-0 gap-2 rounded-2xl bg-white/80 p-3 shadow-sm ring-1 ring-slate-200/80 md:grid-cols-2 xl:grid-cols-4">
            <label for="subscription_url" class="grid min-w-0 gap-1 text-xs font-bold text-ink">
                <span class="pl-1">官网链接</span>
                <input
                    id="subscription_url"
                    type="url"
                    name="subscription_url"
                    value="<?= htmlspecialchars($subscriptionUrl) ?>"
                    required
                    placeholder="https://example.com"
                    class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 text-xs font-normal outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70"
                >
            </label>
            <label for="download_url" class="grid min-w-0 gap-1 text-xs font-bold text-ink">
                <span class="pl-1">软件下载链接</span>
                <input
                    id="download_url"
                    type="url"
                    name="download_url"
                    value="<?= htmlspecialchars($downloadUrl) ?>"
                    required
                    placeholder="https://example.com/Vidoon.zip"
                    class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 text-xs font-normal outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70"
                >
            </label>
            <label for="download_baidu_url" class="grid min-w-0 gap-1 text-xs font-bold text-ink">
                <span class="pl-1">百度网盘链接</span>
                <input
                    id="download_baidu_url"
                    type="url"
                    name="download_baidu_url"
                    value="<?= htmlspecialchars($baiduDownloadUrl) ?>"
                    placeholder="https://pan.baidu.com/s/..."
                    class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 text-xs font-normal outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70"
                >
            </label>
            <label for="download_quark_url" class="grid min-w-0 gap-1 text-xs font-bold text-ink">
                <span class="pl-1">夸克网盘链接</span>
                <input
                    id="download_quark_url"
                    type="url"
                    name="download_quark_url"
                    value="<?= htmlspecialchars($quarkDownloadUrl) ?>"
                    placeholder="https://pan.quark.cn/s/..."
                    class="min-w-0 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 text-xs font-normal outline-none transition focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100/70"
                >
            </label>
            <div class="flex justify-end md:col-span-2 xl:col-span-4">
                <button type="submit" class="shrink-0 rounded-xl bg-ocean px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-cyan-700">保存下载配置</button>
            </div>
        </form>
    </div>
    <div class="mt-2 text-xs xl:text-right <?= $siteError !== '' ? 'text-rose-500' : ($siteSaved ? 'text-emerald-600' : 'text-slate-400') ?>">
        <?php if ($siteError === 'invalid_subscription_url'): ?>
            官网链接无效，请填写完整的 http:// 或 https:// 地址
        <?php elseif ($siteError === 'invalid_download_url'): ?>
            软件下载链接无效，请填写完整的 http:// 或 https:// 地址
        <?php elseif ($siteError === 'invalid_baidu_download_url'): ?>
            百度网盘链接无效，请填写完整的 http:// 或 https:// 地址
        <?php elseif ($siteError === 'invalid_quark_download_url'): ?>
            夸克网盘链接无效，请填写完整的 http:// 或 https:// 地址
        <?php elseif ($siteError === 'database_failed' || !$siteSettingsReady): ?>
            保存失败，请检查数据库 app_settings 表权限
        <?php elseif ($siteSaved): ?>
            官网链接和软件下载配置已保存，用户下次点击时立即使用
        <?php else: ?>
            直链、百度网盘和夸克网盘地址可独立替换；网盘链接留空时首页不显示
        <?php endif; ?>
    </div>

    <div class="mt-4 space-y-4 lg:hidden">
        <?php if ($res && $res->num_rows > 0): ?>
            <?php while ($row = $res->fetch_assoc()): ?>
                <?php
                $levelLabel = admin_subscription_label($row);
                $expireInputValue = admin_datetime_local_value($row['expire_at'] ?? '');
                $badgeClass = admin_subscription_badge_class($row);
                $perTaskLimit = account_level_per_task_limit($row['account_level']);
                $dailyLimit = account_level_daily_limit($row['account_level']);
                $todayCount = intval($row['today_download_count']);
                $totalCount = intval($row['total_download_count']);
                $remainingText = $dailyLimit < 0 ? '不限' : max(0, $dailyLimit - $todayCount) . ' 个';
                $limitText = $dailyLimit < 0 ? '不限' : $dailyLimit . ' 个/天';
                ?>
                <div class="overflow-hidden rounded-3xl bg-white/95 shadow-soft ring-1 ring-slate-200/70">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                            <div class="text-base font-black text-ink">账号 #<?= intval($row['id']) ?></div>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $badgeClass ?>"><?= htmlspecialchars($levelLabel) ?></span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600"><?= htmlspecialchars($levelLabel) ?></span>
                            <span class="rounded-full px-3 py-1 text-xs <?= admin_account_status_class($row, true) ?>">
                                <?= admin_account_status_label($row) ?>
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 px-5 py-4 text-sm">
                        <div class="rounded-2xl bg-mist/60 p-3">
                            <div class="text-xs text-slate-500">注册账号</div>
                            <div class="mt-1 break-all text-slate-800"><?= htmlspecialchars($row['email'] ?: '-') ?></div>
                            <div class="mt-1 text-slate-500"><?= htmlspecialchars($row['phone'] ?: '-') ?></div>
                        </div>
                        <div class="rounded-2xl bg-mist/60 p-3">
                            <div class="text-xs text-slate-500">设备限制</div>
                            <div class="mt-1 font-medium text-slate-900"><?= intval($row['active_device_count']) ?> / <?= intval($row['max_devices']) ?></div>
                            <div class="mt-1 text-xs text-slate-500">同时登录设备数</div>
                        </div>
                        <div class="rounded-2xl bg-mist/60 p-3">
                            <div class="text-xs text-slate-500">下载统计</div>
                            <div class="mt-1 text-slate-800">今日下载 <?= $todayCount ?> 个</div>
                            <div class="mt-1 text-slate-500">累计下载 <?= $totalCount ?> 个</div>
                        </div>
                        <div class="rounded-2xl bg-mist/60 p-3">
                            <div class="text-xs text-slate-500">额度信息</div>
                            <div class="mt-1 text-slate-800">单次上限 <?= $perTaskLimit ?> 个</div>
                            <div class="mt-1 text-slate-800">每日额度 <?= $limitText ?></div>
                            <div class="mt-1 text-slate-500">今日剩余 <?= $remainingText ?></div>
                        </div>
                        <div class="col-span-2 rounded-2xl bg-mist/60 p-3">
                            <div class="text-xs text-slate-500">注册时间</div>
                            <div class="mt-1 text-slate-800">注册时间 <?= htmlspecialchars($row['created_at']) ?></div>
                            <div class="mt-1 text-slate-500">
                                最近登录 <?= !empty($row['last_login_at']) ? htmlspecialchars($row['last_login_at']) : '从未登录' ?>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 px-5 py-4">
                        <form method="post" action="update_user.php" class="grid grid-cols-1 gap-3" onsubmit="return confirmAccountAction(this)">
                            <input type="hidden" name="id" value="<?= intval($row['id']) ?>">
                            <input type="hidden" name="action_scope" value="all">
                            <input type="datetime-local" name="expire_at" value="<?= htmlspecialchars($expireInputValue) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100" title="留空即免费订阅">
                            <input type="number" name="max_devices" min="1" max="20" value="<?= intval($row['max_devices']) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100" placeholder="设备数">
                            <select name="reset_devices" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                                <option value="0">保留设备</option>
                                <option value="1">清空设备</option>
                            </select>
                            <select name="delete_account" class="w-full rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 outline-none transition focus:border-rose-400 focus:bg-white focus:ring-4 focus:ring-rose-100">
                                <option value="0">保留账号</option>
                                <option value="1">删除账号</option>
                            </select>
                            <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-ocean px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-900/10 transition hover:bg-cyan-700">保存设置</button>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="rounded-3xl bg-white px-5 py-10 text-center text-sm text-slate-500 shadow-soft ring-1 ring-slate-200/70">没有找到符合条件的用户。</div>
        <?php endif; ?>
    </div>

    <?php
    $res->data_seek(0);
    ?>
    <div class="mt-4 hidden overflow-hidden rounded-3xl bg-white/95 shadow-soft ring-1 ring-slate-200/70 lg:block">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1120px] table-fixed text-sm">
                <colgroup>
                    <col style="width:8%">
                    <col style="width:18%">
                    <col style="width:18%">
                    <col style="width:20%">
                    <col style="width:14%">
                    <col style="width:13%">
                    <col style="width:9%">
                </colgroup>
                <thead class="bg-ink text-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold tracking-wide">账号 ID</th>
                        <th class="px-3 py-3 text-left text-xs font-bold tracking-wide">账号信息</th>
                        <th class="px-3 py-3 text-left text-xs font-bold tracking-wide">下载统计</th>
                        <th class="px-3 py-3 text-left text-xs font-bold tracking-wide">注册时间</th>
                        <th class="px-3 py-3 text-left text-xs font-bold tracking-wide">到期时间</th>
                        <th class="px-3 py-3 text-left text-xs font-bold tracking-wide">设备数量</th>
                        <th class="px-3 py-3 text-left text-xs font-bold tracking-wide">是否删除</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if ($res && $res->num_rows > 0): ?>
                    <?php while ($row = $res->fetch_assoc()): ?>
                        <?php
                        $levelLabel = admin_subscription_label($row);
                        $expireInputValue = admin_datetime_local_value($row['expire_at'] ?? '');
                        $badgeClass = admin_subscription_badge_class($row);
                        $perTaskLimit = account_level_per_task_limit($row['account_level']);
                        $dailyLimit = account_level_daily_limit($row['account_level']);
                        $todayCount = intval($row['today_download_count']);
                        $totalCount = intval($row['total_download_count']);
                        $remainingText = $dailyLimit < 0 ? '不限' : max(0, $dailyLimit - $todayCount) . ' 个';
                        $limitText = $dailyLimit < 0 ? '不限' : $dailyLimit . ' 个/天';
                        ?>
                        <tr class="group align-middle transition hover:bg-cyan-50/35">
                            <td class="whitespace-nowrap border-l-[3px] border-transparent px-4 py-3 align-top transition group-hover:border-ocean">
                                <div class="inline-flex items-center rounded-xl bg-ink px-3 py-1.5 text-sm font-bold text-white shadow-sm">#<?= intval($row['id']) ?></div>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="min-w-[165px]">
                                    <div class="truncate font-medium text-slate-900"><?= htmlspecialchars($row['email'] ?: '-') ?></div>
                                    <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars($row['phone'] ?: '-') ?></div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $badgeClass ?>"><?= htmlspecialchars($levelLabel) ?></span>
                                        <span class="text-xs font-semibold <?= admin_account_status_class($row) ?>">
                                            <?= admin_account_status_label($row) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="min-w-[125px] overflow-hidden rounded-xl bg-mist/70 px-3 py-1">
                                    <div class="flex items-center justify-between gap-3 py-1">
                                        <span class="text-xs text-slate-500">单次上限</span>
                                        <span class="text-xs font-semibold text-slate-700"><?= $perTaskLimit ?> 个</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 border-t border-slate-200/70 py-1">
                                        <span class="text-xs text-slate-500">今日下载</span>
                                        <span class="text-sm font-bold text-ink"><?= $todayCount ?> 个</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 border-t border-slate-200/70 py-1">
                                        <span class="text-xs text-slate-500">今日剩余</span>
                                        <span class="text-xs font-semibold text-ocean"><?= $remainingText ?></span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 border-t border-slate-200/70 py-1">
                                        <span class="text-xs text-slate-500">累计下载</span>
                                        <span class="text-xs font-semibold text-slate-700"><?= $totalCount ?> 个</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="min-w-0">
                                    <div class="text-xs text-slate-400">注册时间</div>
                                    <div class="mt-1 whitespace-nowrap text-sm font-medium text-slate-700"><?= htmlspecialchars($row['created_at']) ?></div>
                                    <div class="mt-2 text-xs text-slate-400">最近登录</div>
                                    <div class="mt-1 whitespace-nowrap text-xs text-slate-500">
                                        <?= !empty($row['last_login_at']) ? htmlspecialchars($row['last_login_at']) : '从未登录' ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-2 py-3 align-top">
                                <form method="post" action="update_user.php" class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-1.5 shadow-sm transition group-hover:bg-white">
                                    <input type="hidden" name="id" value="<?= intval($row['id']) ?>">
                                    <input type="hidden" name="action_scope" value="time">
                                    <input type="datetime-local" name="expire_at" value="<?= htmlspecialchars($expireInputValue) ?>" class="w-full min-w-0 rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100" title="留空保存即设为免费订阅">
                                    <button type="submit" class="ml-auto mt-1.5 flex items-center justify-center rounded-lg bg-ocean px-4 py-1.5 text-[11px] font-semibold text-white shadow-sm transition hover:bg-cyan-700">保存</button>
                                </form>
                            </td>
                            <td class="px-2 py-3 align-top">
                                <form method="post" action="update_user.php" class="grid grid-cols-[44px_1fr] gap-1.5 rounded-xl border border-slate-200/80 bg-slate-50/70 p-1.5 shadow-sm transition group-hover:bg-white">
                                    <input type="hidden" name="id" value="<?= intval($row['id']) ?>">
                                    <input type="hidden" name="action_scope" value="device">
                                    <input type="number" name="max_devices" min="1" max="20" value="<?= intval($row['max_devices']) ?>" class="w-full min-w-0 rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="数">
                                    <select name="reset_devices" class="w-full min-w-0 rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                                        <option value="0">保留设备</option>
                                        <option value="1">清空设备</option>
                                    </select>
                                    <button type="submit" class="col-span-2 ml-auto inline-flex items-center justify-center rounded-lg bg-ocean px-4 py-1.5 text-[11px] font-semibold text-white shadow-sm transition hover:bg-cyan-700">保存</button>
                                </form>
                            </td>
                            <td class="px-2 py-3 align-top">
                                <form method="post" action="update_user.php" class="flex flex-col gap-1.5 rounded-xl border border-slate-200/80 bg-slate-50/70 p-1.5 shadow-sm transition group-hover:bg-white" onsubmit="return confirmAccountAction(this)">
                                    <input type="hidden" name="id" value="<?= intval($row['id']) ?>">
                                    <input type="hidden" name="action_scope" value="delete">
                                    <select name="delete_account" class="w-full min-w-0 rounded-lg border border-rose-200 bg-rose-50 px-1.5 py-2 text-xs text-rose-700 outline-none transition focus:border-rose-400 focus:bg-white focus:ring-4 focus:ring-rose-100">
                                        <option value="0">保留账号</option>
                                        <option value="1">删除账号</option>
                                    </select>
                                    <button type="submit" class="self-end inline-flex items-center justify-center rounded-lg bg-rose-500 px-4 py-1.5 text-[11px] font-semibold text-white shadow-sm transition hover:bg-rose-600">执行</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-500">没有找到符合条件的用户。</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
        <?php
        $prefix = '?page=';
        $extra = $baseQ ? '&' . $baseQ : '';
        if ($page > 1) {
            echo '<a class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 shadow-sm transition hover:bg-slate-50" href="' . $prefix . ($page - 1) . $extra . '">上一页</a>';
        }
        for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
            if ($i === $page) {
                echo '<span class="rounded-2xl bg-ocean px-4 py-2 text-sm font-semibold text-white shadow-sm">' . $i . '</span>';
            } else {
                echo '<a class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 shadow-sm transition hover:bg-slate-50" href="' . $prefix . $i . $extra . '">' . $i . '</a>';
            }
        }
        if ($page < $totalPages) {
            echo '<a class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 shadow-sm transition hover:bg-slate-50" href="' . $prefix . ($page + 1) . $extra . '">下一页</a>';
        }
        ?>
    </div>
</div>
</body>
</html>
