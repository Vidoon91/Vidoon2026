<?php

require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/runtime_settings.php';

function payment_base_url() {
    return rtrim((string)get_runtime_site_value('base_url', 'https://license.muyanshidai.com/'), '/');
}

function payment_http_post($url, $body, array $headers, &$errorMessage = null) {
    if (!function_exists('curl_init')) {
        $errorMessage = 'php_curl_missing';
        return null;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($curl);
    $httpCode = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    if ($response === false) {
        $errorMessage = curl_error($curl) ?: 'payment_http_failed';
        curl_close($curl);
        return null;
    }
    curl_close($curl);
    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMessage = 'payment_http_' . $httpCode . ':' . substr((string)$response, 0, 300);
        return null;
    }
    return (string)$response;
}

function payment_pem_key($content, $type) {
    $content = trim((string)$content);
    if (strpos($content, '-----BEGIN') !== false) {
        return $content;
    }
    $label = $type === 'private' ? 'PRIVATE KEY' : 'PUBLIC KEY';
    return "-----BEGIN {$label}-----\n"
        . chunk_split(preg_replace('/\s+/', '', $content), 64, "\n")
        . "-----END {$label}-----";
}

function alipay_create_native_order(mysqli $conn, array $order, &$errorMessage = null) {
    $appId = payment_get_setting($conn, 'alipay_app_id');
    $privateKey = payment_pem_key(
        payment_get_setting($conn, 'alipay_private_key', '', true),
        'private'
    );
    $params = [
        'app_id' => $appId,
        'method' => 'alipay.trade.precreate',
        'format' => 'JSON',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'notify_url' => payment_base_url() . '/payment_notify_alipay.php',
        'biz_content' => json_encode([
            'out_trade_no' => $order['order_no'],
            'total_amount' => number_format(intval($order['amount_cents']) / 100, 2, '.', ''),
            'subject' => 'Vidoon ' . $order['plan_name'],
            'timeout_express' => '15m',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    ksort($params);
    $content = urldecode(http_build_query($params));
    $signature = '';
    if (!openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        $errorMessage = 'alipay_sign_failed';
        return null;
    }
    $params['sign'] = base64_encode($signature);
    $response = payment_http_post(
        'https://openapi.alipay.com/gateway.do',
        http_build_query($params),
        ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
        $errorMessage
    );
    if ($response === null) {
        return null;
    }
    $decoded = json_decode($response, true);
    $result = $decoded['alipay_trade_precreate_response'] ?? [];
    if (($result['code'] ?? '') !== '10000' || empty($result['qr_code'])) {
        $errorMessage = $result['sub_msg'] ?? $result['msg'] ?? 'alipay_precreate_failed';
        return null;
    }
    return (string)$result['qr_code'];
}

function wechat_authorization_header(mysqli $conn, $method, $path, $body, &$errorMessage = null) {
    $mchId = payment_get_setting($conn, 'wechat_mch_id');
    $serialNo = payment_get_setting($conn, 'wechat_serial_no');
    $privateKey = payment_pem_key(
        payment_get_setting($conn, 'wechat_private_key', '', true),
        'private'
    );
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
    $signature = '';
    if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        $errorMessage = 'wechat_sign_failed';
        return null;
    }
    return 'WECHATPAY2-SHA256-RSA2048 '
        . 'mchid="' . $mchId . '",'
        . 'nonce_str="' . $nonce . '",'
        . 'timestamp="' . $timestamp . '",'
        . 'serial_no="' . $serialNo . '",'
        . 'signature="' . base64_encode($signature) . '"';
}

function wechat_create_native_order(mysqli $conn, array $order, &$errorMessage = null) {
    $path = '/v3/pay/transactions/native';
    $body = json_encode([
        'appid' => payment_get_setting($conn, 'wechat_app_id'),
        'mchid' => payment_get_setting($conn, 'wechat_mch_id'),
        'description' => 'Vidoon ' . $order['plan_name'],
        'out_trade_no' => $order['order_no'],
        'time_expire' => date(DATE_RFC3339, time() + 900),
        'notify_url' => payment_base_url() . '/payment_notify_wechat.php',
        'amount' => [
            'total' => intval($order['amount_cents']),
            'currency' => 'CNY',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $authorization = wechat_authorization_header($conn, 'POST', $path, $body, $errorMessage);
    if ($authorization === null) {
        return null;
    }
    $response = payment_http_post(
        'https://api.mch.weixin.qq.com' . $path,
        $body,
        [
            'Authorization: ' . $authorization,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: VidoonPayment/1.0',
        ],
        $errorMessage
    );
    if ($response === null) {
        return null;
    }
    $decoded = json_decode($response, true);
    if (empty($decoded['code_url'])) {
        $errorMessage = $decoded['message'] ?? 'wechat_native_failed';
        return null;
    }
    return (string)$decoded['code_url'];
}

function payment_create_provider_order(mysqli $conn, array $order, &$errorMessage = null) {
    if ($order['payment_channel'] === 'alipay') {
        return alipay_create_native_order($conn, $order, $errorMessage);
    }
    if ($order['payment_channel'] === 'wechat') {
        return wechat_create_native_order($conn, $order, $errorMessage);
    }
    $errorMessage = 'unsupported_payment_channel';
    return null;
}

function alipay_verify_notification(mysqli $conn, array $payload) {
    $signature = base64_decode((string)($payload['sign'] ?? ''), true);
    if ($signature === false) {
        return false;
    }
    unset($payload['sign'], $payload['sign_type']);
    $payload = array_filter($payload, static fn($value) => $value !== '' && $value !== null);
    ksort($payload);
    $content = urldecode(http_build_query($payload));
    $publicKey = payment_pem_key(
        payment_get_setting($conn, 'alipay_public_key', '', true),
        'public'
    );
    return openssl_verify($content, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
}

function wechat_verify_notification(mysqli $conn, $timestamp, $nonce, $body, $signature) {
    if (abs(time() - intval($timestamp)) > 300) {
        return false;
    }
    $decodedSignature = base64_decode($signature, true);
    if ($decodedSignature === false) {
        return false;
    }
    $cert = payment_get_setting($conn, 'wechat_platform_cert', '', true);
    $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
    return openssl_verify(
        $message,
        $decodedSignature,
        $cert,
        OPENSSL_ALGO_SHA256
    ) === 1;
}

function wechat_decrypt_resource(mysqli $conn, array $resource, &$errorMessage = null) {
    $key = payment_get_setting($conn, 'wechat_api_v3_key', '', true);
    $ciphertext = base64_decode((string)($resource['ciphertext'] ?? ''), true);
    $nonce = (string)($resource['nonce'] ?? '');
    $associatedData = (string)($resource['associated_data'] ?? '');
    if (strlen($key) !== 32 || $ciphertext === false || strlen($ciphertext) < 17) {
        $errorMessage = 'wechat_resource_invalid';
        return null;
    }
    $tag = substr($ciphertext, -16);
    $encrypted = substr($ciphertext, 0, -16);
    $plain = openssl_decrypt(
        $encrypted,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        $associatedData
    );
    if ($plain === false) {
        $errorMessage = 'wechat_resource_decrypt_failed';
        return null;
    }
    return json_decode($plain, true);
}
