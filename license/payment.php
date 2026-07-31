<?php
require_once __DIR__ . '/include/payment_helpers.php';

$conn = get_db_connection();
$schemaError = null;
ensure_payment_schema($conn, $schemaError);
$orderNo = trim((string)($_GET['order_no'] ?? ''));
$stmt = $conn->prepare("SELECT * FROM payment_orders WHERE order_no = ? LIMIT 1");
$stmt->bind_param('s', $orderNo);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    http_response_code(404);
    die('订单不存在');
}
$maskedEmail = preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', $order['user_email']);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>订单支付</title>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:"Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:linear-gradient(145deg,#eff8fa,#fff7f2)}#qrcode img{margin:auto}</style>
</head>
<body class="min-h-screen text-slate-800">
<main class="mx-auto flex min-h-screen max-w-xl items-center px-5 py-10">
    <section class="w-full rounded-[32px] bg-white p-7 text-center shadow-2xl shadow-slate-300/60 ring-1 ring-slate-200">
        <a href="index.php" title="返回首页" class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-[#2867ad] via-[#173f72] to-[#0c203c] text-xl font-black text-white shadow-lg shadow-blue-900/20">V</a>
        <h1 class="mt-4 text-2xl font-black text-[#10213c]"><?= htmlspecialchars($order['plan_name']) ?></h1>
        <div class="mt-2 text-4xl font-black text-[#087ea4]">¥<?= number_format(intval($order['amount_cents']) / 100, 2) ?></div>
        <div id="qrcode" class="mx-auto mt-6 grid h-[232px] w-[232px] place-items-center rounded-3xl border border-slate-200 bg-white p-4"></div>
        <div id="status" class="mt-5 rounded-2xl bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">请使用<?= $order['payment_channel'] === 'wechat' ? '微信' : '支付宝' ?>扫码支付</div>
        <div class="mt-4 space-y-1 text-xs text-slate-400">
            <div>订单号：<?= htmlspecialchars($order['order_no']) ?></div>
            <div>订阅账号：<?= htmlspecialchars($maskedEmail) ?></div>
            <div>二维码 15 分钟内有效，请勿重复付款</div>
        </div>
        <a href="subscribe.php" class="mt-6 inline-block text-sm font-bold text-slate-500">返回套餐页面</a>
    </section>
</main>
<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode($order['code_url'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    width: 200,
    height: 200,
    correctLevel: QRCode.CorrectLevel.M
});
const statusBox = document.getElementById("status");
let stopped = false;
async function poll() {
    if (stopped) return;
    try {
        const response = await fetch("payment_order_status.php?order_no=<?= rawurlencode($order['order_no']) ?>", {cache:"no-store"});
        const data = await response.json();
        if (data.status === "paid") {
            stopped = true;
            statusBox.className = "mt-5 rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700";
            statusBox.textContent = "支付成功，订阅已开通至 " + (data.subscription_after || "");
            return;
        }
        if (["closed","failed","expired"].includes(data.status)) {
            stopped = true;
            statusBox.className = "mt-5 rounded-2xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700";
            statusBox.textContent = "订单已失效，请返回重新下单";
            return;
        }
    } catch (_) {}
    setTimeout(poll, 2500);
}
poll();
</script>
</body>
</html>
