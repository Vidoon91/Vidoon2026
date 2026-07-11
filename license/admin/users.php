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

$q = trim($_GET['q'] ?? '');
$level = trim($_GET['level'] ?? '');
$status = trim($_GET['status'] ?? '');

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
            boxShadow: {
                soft: '0 10px 30px rgba(15, 23, 42, 0.08)',
            }
        }
    }
};
</script>
<script>
function confirmAccountAction(form) {
    const select = form.querySelector('select[name="delete_account"]');
    if (select && select.value === '1') {
        return window.confirm('确认删除该账号吗？删除后该账号及关联设备、登录会话、下载记录将被清空。');
    }
    return true;
}
</script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
<div class="mx-auto max-w-7xl px-4 py-4 sm:px-5 lg:px-6">
    <div class="overflow-hidden rounded-2xl bg-gradient-to-r from-slate-900 via-sky-900 to-cyan-800 px-4 py-4 text-white shadow-soft sm:px-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="inline-flex items-center rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-medium tracking-wide text-sky-100">Admin Panel</div>
                <h1 class="mt-2 text-xl font-semibold sm:text-2xl">账号用户管理</h1>
                <p class="mt-1 max-w-2xl text-xs text-sky-100/85 sm:text-sm">查看用户状态、订阅类型、设备限制和下载额度。</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <a href="index.php" class="inline-flex items-center justify-center rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-sky-800 shadow-sm transition hover:bg-sky-50">旧授权管理</a>
                <a href="logout.php" class="inline-flex items-center justify-center rounded-xl border border-white/30 bg-white/10 px-3.5 py-2 text-sm font-medium text-white transition hover:bg-white/20">退出登录</a>
            </div>
        </div>
    </div>

    <div class="mt-4 rounded-2xl bg-white px-4 py-3 shadow-soft ring-1 ring-slate-200/70">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <div class="text-slate-600">总用户：<span class="font-semibold text-slate-900"><?= $statTotal ?></span></div>
            <div class="text-slate-600">免费用户：<span class="font-semibold text-sky-600"><?= $statFree ?></span></div>
            <div class="text-slate-600">订阅用户：<span class="font-semibold text-emerald-600"><?= $statPaid ?></span></div>
            <div class="text-slate-600">今日新增：<span class="font-semibold text-violet-600"><?= $statToday ?></span></div>
        </div>
    </div>

    <form method="get" class="mt-4 rounded-2xl bg-white p-3 shadow-soft ring-1 ring-slate-200/70 sm:p-4">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
            <div class="grid flex-1 grid-cols-1 gap-2.5 md:grid-cols-4">
                <label class="block md:col-span-2">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">搜索用户</span>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="邮箱 / 手机号 / 昵称" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">订阅类型</span>
                    <select name="level" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                        <option value="">全部订阅</option>
                        <option value="free" <?= $level === 'free' ? 'selected' : '' ?>>免费订阅</option>
                        <option value="paid" <?= $level === 'paid' ? 'selected' : '' ?>>付费订阅</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">状态</span>
                    <select name="status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                        <option value="">全部状态</option>
                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>启用</option>
                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>禁用</option>
                    </select>
                </label>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="inline-flex min-w-[110px] items-center justify-center rounded-xl bg-sky-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-sky-600">筛选用户</button>
                <a href="users.php" class="inline-flex min-w-[80px] items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">重置</a>
            </div>
        </div>
    </form>

    <div class="mt-3 flex items-center justify-between">
        <div class="text-xs text-slate-500">共 <?= $total ?> 条记录，当前第 <?= $page ?> / <?= $totalPages ?> 页</div>
    </div>

    <div class="mt-4 space-y-4 lg:hidden">
        <?php if ($res && $res->num_rows > 0): ?>
            <?php while ($row = $res->fetch_assoc()): ?>
                <?php
                $levelLabel = admin_subscription_label($row);
                $expireInputValue = admin_datetime_local_value($row['expire_at'] ?? '');
                $badgeClass = admin_subscription_badge_class($row);
                $dailyLimit = account_level_daily_limit($row['account_level']);
                $todayCount = intval($row['today_download_count']);
                $totalCount = intval($row['total_download_count']);
                $remainingText = $dailyLimit < 0 ? '不限' : max(0, $dailyLimit - $todayCount) . ' 次';
                $limitText = $dailyLimit < 0 ? '不限' : $dailyLimit . ' 次/天';
                ?>
                <div class="overflow-hidden rounded-3xl bg-white shadow-soft ring-1 ring-slate-200/70">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-base font-semibold text-slate-900">ID #<?= intval($row['id']) ?></div>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $badgeClass ?>"><?= htmlspecialchars($levelLabel) ?></span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600"><?= htmlspecialchars($levelLabel) ?></span>
                            <span class="rounded-full px-3 py-1 text-xs <?= intval($row['status']) === 1 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?>">
                                <?= intval($row['status']) === 1 ? '已启用' : '已禁用' ?>
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 px-5 py-4 text-sm">
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">注册账号</div>
                            <div class="mt-1 break-all text-slate-800"><?= htmlspecialchars($row['email'] ?: '-') ?></div>
                            <div class="mt-1 text-slate-500"><?= htmlspecialchars($row['phone'] ?: '-') ?></div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">设备限制</div>
                            <div class="mt-1 font-medium text-slate-900"><?= intval($row['active_device_count']) ?> / <?= intval($row['max_devices']) ?></div>
                            <div class="mt-1 text-xs text-slate-500">同时登录设备数</div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">下载统计</div>
                            <div class="mt-1 text-slate-800">今日 <?= $todayCount ?> 次</div>
                            <div class="mt-1 text-slate-500">累计 <?= $totalCount ?> 次</div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">额度信息</div>
                            <div class="mt-1 text-slate-800"><?= $limitText ?></div>
                            <div class="mt-1 text-slate-500">剩余 <?= $remainingText ?></div>
                        </div>
                        <div class="col-span-2 rounded-2xl bg-slate-50 p-3">
                            <div class="text-xs text-slate-500">注册信息</div>
                            <div class="mt-1 text-slate-800">注册时间 <?= htmlspecialchars($row['created_at']) ?></div>
                            <div class="mt-1 text-xs text-slate-400">最近登录 <?= htmlspecialchars($row['last_login_at'] ?: '-') ?></div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 px-5 py-4">
                        <form method="post" action="update_user.php" class="grid grid-cols-1 gap-3" onsubmit="return confirmAccountAction(this)">
                            <input type="hidden" name="id" value="<?= intval($row['id']) ?>">
                            <input type="datetime-local" name="expire_at" value="<?= htmlspecialchars($expireInputValue) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100" title="留空即免费订阅">
                            <input type="number" name="max_devices" min="1" max="20" value="<?= intval($row['max_devices']) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100" placeholder="设备数">
                            <div class="grid grid-cols-2 gap-3">
                                <select name="status" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                                    <option value="1" <?= intval($row['status']) === 1 ? 'selected' : '' ?>>启用</option>
                                    <option value="0" <?= intval($row['status']) === 0 ? 'selected' : '' ?>>禁用</option>
                                </select>
                                <select name="reset_devices" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                                    <option value="0">保留设备</option>
                                    <option value="1">清空设备</option>
                                </select>
                            </div>
                            <select name="delete_account" class="w-full rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 outline-none transition focus:border-rose-400 focus:bg-white focus:ring-4 focus:ring-rose-100">
                                <option value="0">保留账号</option>
                                <option value="1">删除账号</option>
                            </select>
                            <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-sky-500 px-4 py-3 text-sm font-medium text-white transition hover:bg-sky-600">保存设置</button>
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
    <div class="mt-4 hidden overflow-hidden rounded-3xl bg-white shadow-soft ring-1 ring-slate-200/70 lg:block">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-4 text-left font-semibold">账号 ID</th>
                        <th class="px-4 py-4 text-left font-semibold">账号信息</th>
                        <th class="px-4 py-4 text-left font-semibold">下载统计</th>
                        <th class="px-4 py-4 text-left font-semibold">设备</th>
                        <th class="px-4 py-4 text-left font-semibold">当前订阅</th>
                        <th class="px-4 py-4 text-left font-semibold">注册信息</th>
                        <th class="px-4 py-4 text-left font-semibold">管理</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if ($res && $res->num_rows > 0): ?>
                    <?php while ($row = $res->fetch_assoc()): ?>
                        <?php
                        $levelLabel = admin_subscription_label($row);
                        $expireInputValue = admin_datetime_local_value($row['expire_at'] ?? '');
                        $badgeClass = admin_subscription_badge_class($row);
                        $dailyLimit = account_level_daily_limit($row['account_level']);
                        $todayCount = intval($row['today_download_count']);
                        $totalCount = intval($row['total_download_count']);
                        $remainingText = $dailyLimit < 0 ? '不限' : max(0, $dailyLimit - $todayCount) . ' 次';
                        $limitText = $dailyLimit < 0 ? '不限' : $dailyLimit . ' 次/天';
                        ?>
                        <tr class="align-middle transition hover:bg-slate-50/80">
                            <td class="whitespace-nowrap px-4 py-4 align-top">
                                <div class="inline-flex items-center rounded-2xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm">#<?= intval($row['id']) ?></div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="min-w-[170px]">
                                    <div class="truncate font-medium text-slate-900"><?= htmlspecialchars($row['email'] ?: '-') ?></div>
                                    <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars($row['phone'] ?: '-') ?></div>
                                </div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="flex min-w-[130px] flex-col gap-2">
                                    <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2">
                                        <span class="text-xs text-slate-500">今日</span>
                                        <span class="text-sm font-semibold text-slate-900"><?= $todayCount ?> 次</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 text-xs text-slate-500">
                                        <span>累计 <?= $totalCount ?> 次</span>
                                        <span>剩余 <?= $remainingText ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="inline-flex items-center rounded-xl bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-900"><?= intval($row['active_device_count']) ?> / <?= intval($row['max_devices']) ?></div>
                                <div class="mt-1 text-xs text-slate-500">设备上限</div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="flex min-w-[120px] flex-col gap-2">
                                    <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold <?= $badgeClass ?>"><?= htmlspecialchars($levelLabel) ?></span>
                                    <div class="text-xs font-medium <?= intval($row['status']) === 1 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                        <?= intval($row['status']) === 1 ? '已启用' : '已禁用' ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="text-sm text-slate-700"><?= htmlspecialchars($row['created_at']) ?></div>
                                <div class="mt-1 text-xs text-slate-400">最近登录 <?= htmlspecialchars($row['last_login_at'] ?: '-') ?></div>
                            </td>
                            <td class="min-w-[420px] px-4 py-4 align-top">
                                <form method="post" action="update_user.php" class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3" onsubmit="return confirmAccountAction(this)">
                                    <input type="hidden" name="id" value="<?= intval($row['id']) ?>">
                                    <div class="flex flex-col gap-2">
                                        <div class="grid grid-cols-[1.5fr_0.65fr_0.8fr] gap-2">
                                            <input type="datetime-local" name="expire_at" value="<?= htmlspecialchars($expireInputValue) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100" title="留空即免费订阅">
                                            <input type="number" name="max_devices" min="1" max="20" value="<?= intval($row['max_devices']) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="设备">
                                            <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                                                <option value="1" <?= intval($row['status']) === 1 ? 'selected' : '' ?>>启用</option>
                                                <option value="0" <?= intval($row['status']) === 0 ? 'selected' : '' ?>>禁用</option>
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-[1fr_1fr_auto] items-center gap-2">
                                            <select name="reset_devices" class="min-w-[120px] rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                                                <option value="0">保留设备</option>
                                                <option value="1">清空设备</option>
                                            </select>
                                            <select name="delete_account" class="min-w-[120px] rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 outline-none transition focus:border-rose-400 focus:bg-white focus:ring-4 focus:ring-rose-100">
                                                <option value="0">保留账号</option>
                                                <option value="1">删除账号</option>
                                            </select>
                                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-sky-500 px-5 py-2 text-sm font-medium text-white transition hover:bg-sky-600">保存设置</button>
                                        </div>
                                    </div>
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
                echo '<span class="rounded-2xl bg-sky-500 px-4 py-2 text-sm font-medium text-white shadow-sm">' . $i . '</span>';
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
