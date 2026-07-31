<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}
require_once '../include/payment_helpers.php';

$conn = get_db_connection();
$error = null;
ensure_payment_schema($conn, $error);
$conn->query("
    UPDATE payment_orders
    SET status = 'expired', updated_at = NOW()
    WHERE status = 'pending' AND expire_at IS NOT NULL AND expire_at < NOW()
");
if (empty($_SESSION['order_review_csrf'])) {
    $_SESSION['order_review_csrf'] = bin2hex(random_bytes(24));
}
$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$where = "WHERE 1=1";
if ($q !== '') {
    $safe = $conn->real_escape_string($q);
    $where .= " AND (order_no LIKE '%{$safe}%' OR user_email LIKE '%{$safe}%'
        OR provider_trade_no LIKE '%{$safe}%' OR manual_trade_no LIKE '%{$safe}%')";
}
$statusOptions = [
    'pending' => '待付款',
    'reviewing' => '待审核',
    'paid' => '已支付',
    'rejected' => '已驳回',
    'failed' => '失败',
    'expired' => '已过期',
    'refunded' => '已退款',
];
if (isset($statusOptions[$status])) {
    $where .= " AND status='" . $conn->real_escape_string($status) . "'";
}
$orders = $conn->query("SELECT * FROM payment_orders {$where} ORDER BY id DESC LIMIT 200");
$stats = [];
foreach (['pending', 'reviewing', 'paid', 'rejected', 'failed', 'expired'] as $item) {
    $result = $conn->query("SELECT COUNT(*) AS c FROM payment_orders WHERE status='{$item}'");
    $stats[$item] = intval(($result->fetch_assoc()['c'] ?? 0));
}
$message = trim((string)($_GET['message'] ?? ''));
$pageError = trim((string)($_GET['error'] ?? ''));

function order_status_class($status) {
    $classes = [
        'paid' => 'bg-emerald-100 text-emerald-700',
        'reviewing' => 'bg-amber-100 text-amber-800',
        'rejected' => 'bg-rose-100 text-rose-700',
        'failed' => 'bg-rose-100 text-rose-700',
        'expired' => 'bg-slate-200 text-slate-600',
        'pending' => 'bg-sky-100 text-sky-700',
    ];
    return $classes[$status] ?? 'bg-slate-100 text-slate-600';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>支付订单管理</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:"Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:linear-gradient(145deg,#eff8fa,#fff)}input,select,button{font-family:inherit}</style>
</head>
<body class="min-h-screen text-slate-800">
<main class="mx-auto max-w-[1580px] px-5 py-7">
<header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
    <div><h1 class="text-2xl font-black text-[#10213c]">支付订单</h1><p class="mt-1 text-xs text-slate-500">自动支付订单与人工收款审核统一管理</p></div>
    <nav class="flex gap-2"><a href="users.php" class="rounded-xl border bg-white px-4 py-2 text-sm font-bold">账号管理</a><a href="payment_settings.php" class="rounded-xl bg-[#087ea4] px-4 py-2 text-sm font-bold text-white">支付配置</a><a href="ad_settings.php" class="rounded-xl border bg-white px-4 py-2 text-sm font-bold">广告配置</a></nav>
</header>

<?php if ($message !== ''): ?><div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($pageError !== ''): ?><div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700"><?= htmlspecialchars($pageError) ?></div><?php endif; ?>

<section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
<?php foreach (['pending'=>'待付款','reviewing'=>'待审核','paid'=>'已支付','rejected'=>'已驳回','failed'=>'失败','expired'=>'已过期'] as $key=>$label): ?>
<div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><div class="text-xs text-slate-500"><?= $label ?></div><div class="mt-2 text-2xl font-black text-[#10213c]"><?= $stats[$key] ?></div></div>
<?php endforeach; ?>
</section>

<form class="mt-5 grid gap-2 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:grid-cols-[1fr_170px_auto]">
<input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="订单号 / 邮箱 / 付款交易号" class="rounded-xl border border-slate-200 px-4 py-2 text-sm">
<select name="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm"><option value="">全部状态</option><?php foreach($statusOptions as $k=>$v): ?><option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
<button class="rounded-xl bg-[#10213c] px-5 py-2 text-sm font-bold text-white">筛选</button>
</form>

<div class="mt-5 overflow-x-auto rounded-3xl bg-white shadow-xl ring-1 ring-slate-200">
<table class="w-full min-w-[1450px] text-left text-sm">
<thead class="bg-[#10213c] text-xs text-white"><tr><th class="px-5 py-4">订单号</th><th>账号/套餐</th><th>金额/渠道</th><th>状态</th><th>付款信息</th><th>付款凭证</th><th>创建/支付时间</th><th>开通后到期</th><th class="pr-5">审核操作</th></tr></thead>
<tbody class="divide-y divide-slate-100">
<?php while($row=$orders->fetch_assoc()): ?>
<?php $isManual = payment_is_manual_channel($row['payment_channel']); ?>
<tr class="align-top hover:bg-slate-50">
    <td class="px-5 py-4 font-mono text-xs"><?= htmlspecialchars($row['order_no']) ?></td>
    <td class="py-4"><div class="font-semibold"><?= htmlspecialchars($row['user_email']) ?></div><div class="mt-1 text-xs text-slate-400"><?= htmlspecialchars($row['plan_name']) ?> · <?= intval($row['duration_days']) ?> 天</div></td>
    <td class="py-4 font-bold">¥<?= number_format(intval($row['amount_cents'])/100,2) ?><div class="mt-1 text-xs font-normal text-slate-400"><?= htmlspecialchars(payment_channel_label($row['payment_channel'])) ?></div></td>
    <td class="py-4"><span class="rounded-full px-3 py-1 text-xs font-bold <?= order_status_class($row['status']) ?>"><?= htmlspecialchars($statusOptions[$row['status']] ?? $row['status']) ?></span><?php if ((string)$row['review_note'] !== ''): ?><div class="mt-2 max-w-44 text-xs text-slate-500"><?= htmlspecialchars($row['review_note']) ?></div><?php endif; ?></td>
    <td class="py-4 text-xs">
        <?php if ($isManual): ?>
            <div>付款人：<?= htmlspecialchars((string)$row['manual_payer']) ?></div>
            <div class="mt-1">交易号：<?= htmlspecialchars((string)$row['manual_trade_no']) ?></div>
            <div class="mt-1 text-slate-400"><?= htmlspecialchars((string)$row['manual_payment_time']) ?></div>
            <?php if ((string)$row['manual_note'] !== ''): ?><div class="mt-1 text-slate-400">备注：<?= htmlspecialchars($row['manual_note']) ?></div><?php endif; ?>
        <?php else: ?>
            <div class="max-w-44 break-all"><?= htmlspecialchars((string)$row['provider_trade_no']) ?></div>
        <?php endif; ?>
    </td>
    <td class="py-4"><?php if ($isManual && (string)$row['manual_proof_file'] !== ''): ?><a href="payment_proof.php?order_no=<?= urlencode($row['order_no']) ?>" target="_blank" class="inline-block rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-bold text-cyan-700">查看截图</a><?php else: ?><span class="text-xs text-slate-400">-</span><?php endif; ?></td>
    <td class="py-4 text-xs"><?= htmlspecialchars($row['created_at']) ?><div class="mt-1 text-emerald-600"><?= htmlspecialchars((string)$row['paid_at']) ?></div></td>
    <td class="py-4 text-xs font-semibold"><?= htmlspecialchars((string)$row['subscription_after']) ?></td>
    <td class="py-4 pr-5">
        <?php if ($isManual && $row['status'] === 'reviewing'): ?>
            <form action="review_manual_order.php" method="post" class="w-52 space-y-2">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['order_review_csrf']) ?>">
                <input type="hidden" name="order_no" value="<?= htmlspecialchars($row['order_no']) ?>">
                <input name="review_note" maxlength="255" placeholder="审核备注（选填）" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs">
                <div class="grid grid-cols-2 gap-2">
                    <button name="action" value="approve" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white" onclick="return confirm('确认已在收款账单核实到账，并立即开通订阅？')">确认开通</button>
                    <button name="action" value="reject" class="rounded-lg bg-rose-500 px-3 py-2 text-xs font-bold text-white" onclick="return confirm('确认驳回这次付款信息？')">驳回</button>
                </div>
            </form>
        <?php elseif ($isManual && (string)$row['reviewed_at'] !== ''): ?>
            <div class="text-xs text-slate-400"><?= htmlspecialchars($row['reviewed_at']) ?><br><?= htmlspecialchars((string)$row['reviewed_by']) ?></div>
        <?php else: ?><span class="text-xs text-slate-400">无需人工审核</span><?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
</tbody></table></div>
</main></body></html>
