<?php
session_start();
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

require_once '../include/payment_helpers.php';

$conn = get_db_connection();
$schemaError = null;
if (!ensure_payment_schema($conn, $schemaError)) {
    die('支付数据表初始化失败：' . htmlspecialchars((string)$schemaError));
}

function payment_admin_value(mysqli $conn, $key, $default = '') {
    return htmlspecialchars(payment_get_setting($conn, $key, $default));
}

$paymentEnabled = payment_get_setting($conn, 'payment_enabled', '0') === '1';
$manualEnabled = payment_get_setting($conn, 'manual_enabled', '0') === '1';
$manualWechatEnabled = payment_get_setting($conn, 'manual_wechat_enabled', '0') === '1';
$manualAlipayEnabled = payment_get_setting($conn, 'manual_alipay_enabled', '0') === '1';
$alipayEnabled = payment_get_setting($conn, 'alipay_enabled', '0') === '1';
$wechatEnabled = payment_get_setting($conn, 'wechat_enabled', '0') === '1';
$manualWechatReady = payment_channel_ready($conn, 'manual_wechat');
$manualAlipayReady = payment_channel_ready($conn, 'manual_alipay');
$manualWechatHasQr = payment_get_setting($conn, 'manual_wechat_qr') !== '';
$manualAlipayHasQr = payment_get_setting($conn, 'manual_alipay_qr') !== '';
$alipayReady = payment_channel_ready($conn, 'alipay');
$wechatReady = payment_channel_ready($conn, 'wechat');
$orderEmailConfig = payment_get_order_email_config($conn);
$orderEmailEnabled = !empty($orderEmailConfig['enabled']);
$orderEmailReady = payment_order_email_ready($conn);
$plans = $conn->query("SELECT * FROM subscription_plans WHERE plan_code <> 'trial' ORDER BY sort_order ASC, id ASC");
$saved = ($_GET['saved'] ?? '') === '1';
$emailTest = trim((string)($_GET['email_test'] ?? ''));
$emailError = trim((string)($_GET['email_error'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$errorLabels = [
    'manual_wechat_qr_file_upload_failed' => '微信收款码上传失败',
    'manual_wechat_qr_file_too_large' => '微信收款码超过 2MB',
    'manual_wechat_qr_file_invalid_image' => '微信收款码不是有效的 PNG、JPG 或 WebP 图片',
    'manual_alipay_qr_file_upload_failed' => '支付宝收款码上传失败',
    'manual_alipay_qr_file_too_large' => '支付宝收款码超过 2MB',
    'manual_alipay_qr_file_invalid_image' => '支付宝收款码不是有效的 PNG、JPG 或 WebP 图片',
    'order_smtp_config_invalid' => 'SMTP 端口或加密方式不正确',
    'order_email_address_invalid' => 'SMTP账号、发件邮箱或管理员通知邮箱格式不正确',
];
$emailErrorLabels = [
    'admin_notify_email_missing' => '请先填写并保存管理员通知邮箱',
    'order_email_disabled' => '订单邮件开关尚未启用',
    'smtp_recipient_invalid' => '管理员通知邮箱格式不正确',
    'smtp_openssl_missing' => '服务器 PHP 未启用 OpenSSL 扩展',
    'smtp_config_incomplete' => 'SMTP 主机、端口、发件账号或授权码不完整',
    'smtp_connect_failed' => '服务器无法连接 SMTP 主机，请检查端口和防火墙',
    'smtp_tls_failed' => 'SMTP 加密连接失败，请检查 SSL/TLS 与端口是否匹配',
    'smtp_auth_failed' => 'SMTP 登录认证失败，请检查邮箱账号和授权码',
    'smtp_sender_rejected' => '发件邮箱被 SMTP 服务器拒绝，通常需要与登录账号一致',
    'smtp_recipient_rejected' => '收件邮箱被 SMTP 服务器拒绝',
    'smtp_send_failed' => 'SMTP 在发送邮件内容时返回错误',
];
if (empty($_SESSION['payment_csrf'])) {
    $_SESSION['payment_csrf'] = bin2hex(random_bytes(24));
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>支付与套餐配置</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config={theme:{extend:{colors:{ink:'#10213c',ocean:'#087ea4',mist:'#edf6f8',coral:'#f06b4f'}}}};
</script>
<style>
body{font-family:"Microsoft YaHei UI","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#eef7fa,#f8fbfc 52%,#fff5f1)}
.field{width:100%;border:1px solid #dbe7ec;border-radius:12px;background:#f8fbfc;padding:10px 12px;font-size:13px;outline:none}
.field:focus{border-color:#38bdf8;box-shadow:0 0 0 4px rgba(56,189,248,.12);background:#fff}
</style>
</head>
<body class="min-h-screen text-slate-800">
<main class="mx-auto max-w-[1380px] px-5 py-7">
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-5">
        <div>
            <a href="../index.php" title="返回首页" class="flex items-center gap-3">
                <div class="grid h-11 w-11 place-items-center rounded-2xl bg-gradient-to-br from-[#2867ad] via-[#173f72] to-[#0c203c] text-lg font-black text-white shadow-lg shadow-blue-900/20">V</div>
                <div>
                    <h1 class="text-2xl font-black text-ink">支付与套餐配置</h1>
                    <p class="mt-1 text-xs text-slate-500">资料保存在服务器数据库中，支付通道默认关闭</p>
                </div>
            </a>
        </div>
        <nav class="flex gap-2">
            <a href="users.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">账号管理</a>
            <a href="orders.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">订单管理</a>
            <a href="ad_settings.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600">广告配置</a>
            <a href="../subscribe.php" target="_blank" class="rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2 text-sm font-semibold text-ocean">预览订阅页</a>
            <a href="logout.php" class="rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-white">退出登录</a>
        </nav>
    </header>

    <?php if ($saved): ?>
        <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">支付配置已保存。</div>
    <?php elseif ($emailTest === 'success'): ?>
        <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">测试邮件发送成功，请检查管理员通知邮箱。</div>
    <?php elseif ($emailTest === 'failed'): ?>
        <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">测试邮件发送失败：<?= htmlspecialchars($emailErrorLabels[$emailError] ?? '未知错误，请检查服务器邮件日志') ?></div>
    <?php elseif ($error !== ''): ?>
        <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">保存失败：<?= htmlspecialchars($errorLabels[$error] ?? $error) ?></div>
    <?php endif; ?>

    <form action="save_payment_settings.php" method="post" enctype="multipart/form-data" class="mt-5 space-y-5">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['payment_csrf']) ?>">
        <section class="grid gap-4 rounded-3xl bg-ink p-5 text-white shadow-xl lg:grid-cols-[1fr_auto] lg:items-center">
            <div>
                <div class="text-lg font-black">支付总开关</div>
                <p class="mt-1 text-sm text-slate-300">关闭时订阅页面可以访问，但不会创建真实支付订单。</p>
            </div>
            <label class="flex items-center gap-3 rounded-2xl bg-white/10 px-5 py-3">
                <input type="checkbox" name="payment_enabled" value="1" class="h-5 w-5" <?= $paymentEnabled ? 'checked' : '' ?>>
                <span class="font-bold">启用在线支付</span>
            </label>
        </section>

        <section class="rounded-3xl bg-white p-5 shadow-lg shadow-slate-200/60 ring-1 ring-slate-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black text-ink">人工收款</h2>
                    <p class="mt-1 text-xs text-slate-500">适用于个人收款码。启用人工收款后会自动开启支付总开关。</p>
                </div>
                <label class="flex items-center gap-2 rounded-xl bg-cyan-50 px-4 py-2 text-sm font-bold text-ocean">
                    <input type="checkbox" name="manual_enabled" value="1" <?= $manualEnabled ? 'checked' : '' ?>>
                    启用人工收款
                </label>
            </div>
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <strong class="text-sm text-ink">微信个人收款码</strong>
                        <span class="rounded-full px-3 py-1 text-xs font-bold <?= $manualWechatReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= $manualWechatReady ? '可用' : '未完成' ?></span>
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="manual_wechat_enabled" value="1" <?= $manualWechatEnabled ? 'checked' : '' ?>>显示微信人工收款</label>
                    <label class="mt-3 block text-xs font-semibold text-slate-600">收款人名称<input class="field mt-1.5" name="manual_wechat_name" value="<?= payment_admin_value($conn, 'manual_wechat_name') ?>" placeholder="用户核对收款方时显示"></label>
                    <label class="mt-3 block text-xs font-semibold text-slate-600">上传微信收款码<input class="field mt-1.5" type="file" name="manual_wechat_qr_file" accept="image/png,image/jpeg,image/webp"></label>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-[11px]">
                        <span class="rounded-lg px-2 py-2 <?= $manualEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">人工总开关：<?= $manualEnabled ? '已开' : '未开' ?></span>
                        <span class="rounded-lg px-2 py-2 <?= $manualWechatEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">微信通道：<?= $manualWechatEnabled ? '已开' : '未开' ?></span>
                        <span class="rounded-lg px-2 py-2 <?= $manualWechatHasQr ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">收款码：<?= $manualWechatHasQr ? '已保存' : '未保存' ?></span>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <strong class="text-sm text-ink">支付宝个人收款码</strong>
                        <span class="rounded-full px-3 py-1 text-xs font-bold <?= $manualAlipayReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= $manualAlipayReady ? '可用' : '未完成' ?></span>
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="manual_alipay_enabled" value="1" <?= $manualAlipayEnabled ? 'checked' : '' ?>>显示支付宝人工收款</label>
                    <label class="mt-3 block text-xs font-semibold text-slate-600">收款人名称<input class="field mt-1.5" name="manual_alipay_name" value="<?= payment_admin_value($conn, 'manual_alipay_name') ?>" placeholder="用户核对收款方时显示"></label>
                    <label class="mt-3 block text-xs font-semibold text-slate-600">上传支付宝收款码<input class="field mt-1.5" type="file" name="manual_alipay_qr_file" accept="image/png,image/jpeg,image/webp"></label>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-[11px]">
                        <span class="rounded-lg px-2 py-2 <?= $manualEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">人工总开关：<?= $manualEnabled ? '已开' : '未开' ?></span>
                        <span class="rounded-lg px-2 py-2 <?= $manualAlipayEnabled ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">支付宝通道：<?= $manualAlipayEnabled ? '已开' : '未开' ?></span>
                        <span class="rounded-lg px-2 py-2 <?= $manualAlipayHasQr ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">收款码：<?= $manualAlipayHasQr ? '已保存' : '未保存' ?></span>
                    </div>
                </div>
            </div>
            <label class="mt-4 block text-xs font-semibold text-slate-600">付款说明
                <textarea class="field mt-1.5 min-h-20 resize-y" name="manual_instructions" placeholder="例如：请按订单金额付款，付款后填写交易单号并上传截图。"><?= payment_admin_value($conn, 'manual_instructions', '请按订单显示金额付款，付款完成后填写付款信息并上传付款截图。') ?></textarea>
            </label>
            <p class="mt-3 text-xs font-semibold text-rose-500">人工收款不会自动识别到账。请务必对照微信或支付宝账单后再点击确认开通。</p>
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <div class="rounded-3xl bg-white p-5 shadow-lg shadow-slate-200/60 ring-1 ring-slate-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-black text-ink">支付宝当面付</h2>
                        <p class="mt-1 text-xs text-slate-500">RSA2 密钥模式，生成扫码支付二维码</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= $alipayReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= $alipayReady ? '配置完整' : '等待配置' ?></span>
                </div>
                <label class="mt-4 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="alipay_enabled" value="1" <?= $alipayEnabled ? 'checked' : '' ?>>启用支付宝</label>
                <div class="mt-4 grid gap-4">
                    <label class="text-xs font-semibold text-slate-600">支付宝 APPID<input class="field mt-1.5" name="alipay_app_id" value="<?= payment_admin_value($conn, 'alipay_app_id') ?>" placeholder="请输入开放平台 APPID"></label>
                    <label class="text-xs font-semibold text-slate-600">应用私钥文件（PEM/TXT）<input class="field mt-1.5" type="file" name="alipay_private_key_file" accept=".pem,.txt,.key"></label>
                    <label class="text-xs font-semibold text-slate-600">支付宝公钥文件（PEM/TXT）<input class="field mt-1.5" type="file" name="alipay_public_key_file" accept=".pem,.txt,.key"></label>
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div class="rounded-xl bg-slate-50 p-3">应用私钥：<?= payment_get_setting($conn, 'alipay_private_key', '', true) !== '' ? '已上传' : '未上传' ?></div>
                        <div class="rounded-xl bg-slate-50 p-3">支付宝公钥：<?= payment_get_setting($conn, 'alipay_public_key', '', true) !== '' ? '已上传' : '未上传' ?></div>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl bg-white p-5 shadow-lg shadow-slate-200/60 ring-1 ring-slate-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-black text-ink">微信 Native 支付</h2>
                        <p class="mt-1 text-xs text-slate-500">微信支付 API v3 商户直连模式</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= $wechatReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= $wechatReady ? '配置完整' : '等待配置' ?></span>
                </div>
                <label class="mt-4 flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="wechat_enabled" value="1" <?= $wechatEnabled ? 'checked' : '' ?>>启用微信支付</label>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-600">商户号<input class="field mt-1.5" name="wechat_mch_id" value="<?= payment_admin_value($conn, 'wechat_mch_id') ?>"></label>
                    <label class="text-xs font-semibold text-slate-600">APPID<input class="field mt-1.5" name="wechat_app_id" value="<?= payment_admin_value($conn, 'wechat_app_id') ?>"></label>
                    <label class="text-xs font-semibold text-slate-600">商户证书序列号<input class="field mt-1.5" name="wechat_serial_no" value="<?= payment_admin_value($conn, 'wechat_serial_no') ?>"></label>
                    <label class="text-xs font-semibold text-slate-600">API v3 密钥<input class="field mt-1.5" type="password" name="wechat_api_v3_key" placeholder="留空则保留原密钥"></label>
                    <label class="text-xs font-semibold text-slate-600">商户私钥 apiclient_key.pem<input class="field mt-1.5" type="file" name="wechat_private_key_file" accept=".pem,.key"></label>
                    <label class="text-xs font-semibold text-slate-600">微信支付平台证书 PEM<input class="field mt-1.5" type="file" name="wechat_platform_cert_file" accept=".pem,.crt,.cer"></label>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-2 text-[11px]">
                    <div class="rounded-xl bg-slate-50 p-3">API v3：<?= payment_get_setting($conn, 'wechat_api_v3_key', '', true) !== '' ? '已保存' : '未保存' ?></div>
                    <div class="rounded-xl bg-slate-50 p-3">商户私钥：<?= payment_get_setting($conn, 'wechat_private_key', '', true) !== '' ? '已上传' : '未上传' ?></div>
                    <div class="rounded-xl bg-slate-50 p-3">平台证书：<?= payment_get_setting($conn, 'wechat_platform_cert', '', true) !== '' ? '已上传' : '未上传' ?></div>
                </div>
            </div>
        </section>

        <section class="rounded-3xl bg-white p-5 shadow-lg shadow-slate-200/60 ring-1 ring-slate-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black text-ink">订单邮件通知</h2>
                    <p class="mt-1 text-xs text-slate-500">订单一经创建，无论最终是否付款，立即通知下单账号邮箱和管理员邮箱；付款成功后再次发送开通结果。</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= $orderEmailReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= $orderEmailReady ? '邮件配置完整' : '等待配置' ?></span>
                    <a href="test_order_email.php?csrf=<?= urlencode($_SESSION['payment_csrf']) ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600">发送测试邮件</a>
                    <label class="flex items-center gap-2 rounded-xl bg-cyan-50 px-4 py-2 text-sm font-bold text-ocean">
                        <input type="checkbox" name="order_email_enabled" value="1" <?= $orderEmailEnabled ? 'checked' : '' ?>>
                        启用订单邮件
                    </label>
                </div>
            </div>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="text-xs font-semibold text-slate-600">SMTP 主机<input class="field mt-1.5" name="order_smtp_host" value="<?= htmlspecialchars($orderEmailConfig['host']) ?>" placeholder="smtp.qq.com"></label>
                <label class="text-xs font-semibold text-slate-600">SMTP 端口<input class="field mt-1.5" type="number" min="1" max="65535" name="order_smtp_port" value="<?= intval($orderEmailConfig['port']) ?>"></label>
                <label class="text-xs font-semibold text-slate-600">加密方式
                    <select class="field mt-1.5" name="order_smtp_secure">
                        <option value="ssl" <?= $orderEmailConfig['secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= $orderEmailConfig['secure'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                    </select>
                </label>
                <label class="text-xs font-semibold text-slate-600">SMTP 发件账号<input class="field mt-1.5" type="email" name="order_smtp_username" value="<?= htmlspecialchars($orderEmailConfig['username']) ?>" placeholder="your-email@qq.com"></label>
                <label class="text-xs font-semibold text-slate-600">SMTP 授权码<input class="field mt-1.5" type="password" name="order_smtp_password" placeholder="<?= $orderEmailConfig['password'] !== '' ? '已配置，留空保留原授权码' : '请输入邮箱SMTP授权码' ?>"></label>
                <label class="text-xs font-semibold text-slate-600">发件邮箱<input class="field mt-1.5" type="email" name="order_smtp_from_email" value="<?= htmlspecialchars($orderEmailConfig['from_email']) ?>" placeholder="默认与发件账号相同"></label>
                <label class="text-xs font-semibold text-slate-600">发件名称<input class="field mt-1.5" name="order_smtp_from_name" value="<?= htmlspecialchars($orderEmailConfig['from_name']) ?>" placeholder="Vidoon"></label>
                <label class="text-xs font-semibold text-slate-600">管理员通知邮箱<input class="field mt-1.5" type="email" name="admin_notify_email" value="<?= payment_admin_value($conn, 'admin_notify_email') ?>" placeholder="每个订单同时通知此邮箱"></label>
            </div>
            <p class="mt-3 text-xs text-slate-500">QQ邮箱通常使用 smtp.qq.com、端口 465、SSL；密码位置填写邮箱生成的 SMTP 授权码，不是QQ登录密码。请先保存，再发送测试邮件。</p>
        </section>

        <section class="rounded-3xl bg-white p-5 shadow-lg shadow-slate-200/60 ring-1 ring-slate-200">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="text-lg font-black text-ink">订阅套餐</h2><p class="mt-1 text-xs text-slate-500">价格单位为人民币元；价格必须大于 0 才能启用。</p></div>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <?php while ($plan = $plans->fetch_assoc()): ?>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <input type="hidden" name="plans[<?= intval($plan['id']) ?>][id]" value="<?= intval($plan['id']) ?>">
                        <label class="text-xs font-semibold">套餐名称<input class="field mt-1.5" name="plans[<?= intval($plan['id']) ?>][name]" value="<?= htmlspecialchars($plan['plan_name']) ?>"></label>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <label class="text-xs font-semibold">有效天数<input class="field mt-1.5" type="number" min="1" name="plans[<?= intval($plan['id']) ?>][days]" value="<?= intval($plan['duration_days']) ?>"></label>
                            <label class="text-xs font-semibold">价格（元）<input class="field mt-1.5" type="number" min="0.01" step="0.01" name="plans[<?= intval($plan['id']) ?>][price]" value="<?= number_format(intval($plan['price_cents']) / 100, 2, '.', '') ?>"></label>
                        </div>
                        <label class="mt-3 block text-xs font-semibold">套餐说明<input class="field mt-1.5" name="plans[<?= intval($plan['id']) ?>][description]" value="<?= htmlspecialchars($plan['description']) ?>"></label>
                        <label class="mt-3 flex items-center gap-2 text-sm font-bold text-emerald-700"><input type="checkbox" name="plans[<?= intval($plan['id']) ?>][status]" value="1" <?= intval($plan['status']) === 1 ? 'checked' : '' ?>>上架套餐</label>
                    </div>
                <?php endwhile; ?>
            </div>
        </section>

        <div class="sticky bottom-4 flex justify-end">
            <button class="rounded-2xl bg-ocean px-8 py-3 text-sm font-black text-white shadow-xl shadow-cyan-900/20 hover:bg-cyan-700">保存全部配置</button>
        </div>
    </form>
</main>
</body>
</html>
