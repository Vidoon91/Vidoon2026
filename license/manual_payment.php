<?php
session_start();
require_once __DIR__ . '/include/payment_helpers.php';
require_once __DIR__ . '/include/ad_helpers.php';
require_once __DIR__ . '/include/site_header.php';

$conn = get_db_connection();
$adConfig = get_ad_config($conn);
$schemaError = null;
ensure_payment_schema($conn, $schemaError);
$orderNo = trim((string)($_GET['order_no'] ?? ''));
$stmt = $conn->prepare("SELECT * FROM payment_orders WHERE order_no = ? LIMIT 1");
$stmt->bind_param('s', $orderNo);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order || !payment_is_manual_channel($order['payment_channel'])) {
    http_response_code(404);
    die('订单不存在');
}

if (!isset($_SESSION['manual_order_tokens']) || !is_array($_SESSION['manual_order_tokens'])) {
    $_SESSION['manual_order_tokens'] = [];
}
if (empty($_SESSION['manual_order_tokens'][$orderNo])) {
    $_SESSION['manual_order_tokens'][$orderNo] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['manual_order_tokens'][$orderNo];
$isWechat = $order['payment_channel'] === 'manual_wechat';
$prefix = $isWechat ? 'manual_wechat' : 'manual_alipay';
$qrImage = payment_get_setting($conn, $prefix . '_qr');
$payeeName = payment_get_setting($conn, $prefix . '_name');
$instructions = payment_get_setting(
    $conn,
    'manual_instructions',
    '请按订单显示金额付款，付款完成后填写付款信息并上传付款截图。'
);
$status = (string)$order['status'];
$canSubmit = in_array($status, ['pending', 'rejected'], true)
    && strtotime((string)$order['expire_at']) >= time();
$error = trim((string)($_GET['error'] ?? ''));
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>人工付款确认 - Vidoon</title>
<?php render_ad_publisher_loader($adConfig); ?>
<style>
:root{--ink:#10213c;--ocean:#0788ae;--muted:#708397;--line:#dbe7ec;--green:#0e9f73;--red:#d9485f}
*{box-sizing:border-box}body{margin:0;color:var(--ink);font-family:"Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:radial-gradient(circle at 8% 5%,rgba(7,136,174,.16),transparent 27rem),linear-gradient(145deg,#f8fcfd,#edf6f8 60%,#fff7f2)}
.page{width:min(980px,calc(100% - 28px));margin:0 auto;padding:32px 0 50px}.brand{display:flex;align-items:center;gap:11px;color:var(--ink);font-size:18px;font-weight:900;text-decoration:none}.mark{display:grid;width:42px;height:42px;place-items:center;border-radius:14px;background:linear-gradient(145deg,#2867ad 0%,#173f72 42%,#0c203c 100%);box-shadow:0 12px 24px rgba(20,63,114,.24);color:#fff}
.title{margin:38px 0 7px;font-size:30px;font-weight:900;letter-spacing:-.04em}.tip{margin:0;color:var(--muted);font-size:13px;line-height:1.7}
.grid{display:grid;grid-template-columns:390px 1fr;gap:22px;margin-top:24px}.card{border:1px solid var(--line);border-radius:24px;background:rgba(255,255,255,.96);padding:24px;box-shadow:0 18px 45px rgba(48,78,96,.11)}
.pay-card{text-align:center}.channel{font-size:14px;font-weight:900}.amount{margin-top:12px;font-size:42px;font-weight:900;letter-spacing:-.05em}.qr{display:block;width:230px;height:230px;object-fit:contain;margin:18px auto 12px;border:1px solid var(--line);border-radius:18px;background:#fff;padding:8px}.payee{color:var(--muted);font-size:12px}.order{margin-top:17px;border-radius:13px;background:#f2f7f9;padding:11px;font-family:Consolas,monospace;font-size:11px;word-break:break-all}
h2{margin:0;font-size:18px}.notice{margin:14px 0;border-radius:13px;padding:11px 13px;background:#fff7df;color:#845900;font-size:12px;line-height:1.7}.notice.good{background:#eaf9f3;color:#087a58}.notice.bad{background:#fff0f2;color:#a52d42}
label{display:block;margin-top:14px;color:#40586e;font-size:12px;font-weight:900}.field{display:block;width:100%;height:44px;margin-top:7px;border:1px solid #cfdee4;border-radius:12px;background:#f8fbfc;padding:0 13px;outline:none}.field:focus{border-color:var(--ocean);box-shadow:0 0 0 4px rgba(7,136,174,.09)}textarea.field{height:70px;padding-top:11px;resize:vertical}
.submit{width:100%;height:46px;margin-top:18px;border:0;border-radius:13px;background:var(--ocean);color:#fff;font-weight:900;cursor:pointer}.submit:disabled{background:#9babb8;cursor:not-allowed}.foot{margin-top:12px;color:var(--muted);font-size:11px;line-height:1.6;text-align:center}.back{display:inline-block;margin-top:18px;color:var(--ocean);font-size:12px;font-weight:900;text-decoration:none}
@media(max-width:760px){.page{padding-top:20px}.title{margin-top:28px}.grid{grid-template-columns:1fr}.card{padding:19px}.qr{width:210px;height:210px}}
</style>
</head>
<body>
<?php render_site_header('subscribe'); ?>
<main class="page">
    <h1 class="title">扫码付款并提交核对</h1>
    <p class="tip">这是人工收款订单。付款后需要管理员核对账单，确认无误后系统才会开通订阅。</p>

    <div class="grid">
        <section class="card pay-card">
            <div class="channel"><?= $isWechat ? '微信支付' : '支付宝' ?></div>
            <div class="amount">¥<?= number_format(intval($order['amount_cents']) / 100, 2) ?></div>
            <?php if ($qrImage !== ''): ?>
                <img class="qr" src="<?= htmlspecialchars($qrImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $isWechat ? '微信' : '支付宝' ?>收款二维码">
            <?php else: ?>
                <div class="notice bad">收款码暂时不可用，请联系管理员。</div>
            <?php endif; ?>
            <?php if ($payeeName !== ''): ?><div class="payee">请核对收款方：<?= htmlspecialchars($payeeName) ?></div><?php endif; ?>
            <div class="order">订单号：<?= htmlspecialchars($orderNo) ?></div>
        </section>

        <section class="card">
            <h2>付款信息</h2>
            <div class="notice"><?= nl2br(htmlspecialchars($instructions)) ?></div>

            <?php if ($error !== ''): ?>
                <div class="notice bad"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($status === 'reviewing'): ?>
                <div class="notice good">付款信息已提交，正在等待管理员核对。请勿重复付款。</div>
            <?php elseif ($status === 'paid'): ?>
                <div class="notice good">付款已经确认，订阅已开通至 <?= htmlspecialchars((string)$order['subscription_after']) ?>。</div>
            <?php elseif ($status === 'rejected'): ?>
                <div class="notice bad">上次提交未通过：<?= htmlspecialchars((string)$order['review_note']) ?>。您可以核对后重新提交。</div>
            <?php elseif (!$canSubmit): ?>
                <div class="notice bad">订单已过期，请返回套餐页面重新下单。</div>
            <?php endif; ?>

            <?php if ($canSubmit): ?>
                <form action="submit_manual_payment.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="order_no" value="<?= htmlspecialchars($orderNo) ?>">
                    <label>付款人姓名或付款账号昵称
                        <input class="field" name="payer" maxlength="100" required placeholder="用于核对收款账单">
                    </label>
                    <label>付款交易单号
                        <input class="field" name="trade_no" maxlength="100" required placeholder="请填写账单中的交易单号">
                    </label>
                    <label>付款时间
                        <input class="field" type="datetime-local" name="payment_time" required value="<?= date('Y-m-d\TH:i') ?>">
                    </label>
                    <label>付款截图
                        <input class="field" type="file" name="proof" accept="image/png,image/jpeg,image/webp" required>
                    </label>
                    <label>备注（选填）
                        <textarea class="field" name="note" maxlength="255" placeholder="其他需要说明的信息"></textarea>
                    </label>
                    <button class="submit" type="submit">提交付款信息，等待审核</button>
                    <div class="foot">提交截图不代表付款成功。管理员必须在收款账单中核实到账后才会开通订阅。</div>
                </form>
            <?php endif; ?>
            <a class="back" href="subscribe.php">返回套餐页面</a>
        </section>
    </div>
</main>
</body>
</html>
