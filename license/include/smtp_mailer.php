<?php

require_once __DIR__ . '/../mail_config.php';

function smtp_read_response($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtp_expect($socket, array $expectedCodes) {
    $response = smtp_read_response($socket);
    $code = intval(substr($response, 0, 3));
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }
    return $response;
}

function smtp_command($socket, $command, array $expectedCodes) {
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_send_verification_email($recipient, $code, $purpose, &$errorMessage = null) {
    if (!SMTP_ENABLED) {
        $errorMessage = 'smtp_not_configured';
        return false;
    }
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'invalid_email';
        return false;
    }
    if (!extension_loaded('openssl')) {
        $errorMessage = 'smtp_openssl_missing';
        return false;
    }

    $host = trim((string)SMTP_HOST);
    $port = intval(SMTP_PORT);
    $secure = strtolower(trim((string)SMTP_SECURE));
    $username = trim((string)SMTP_USERNAME);
    $password = (string)SMTP_PASSWORD;
    $fromEmail = trim((string)SMTP_FROM_EMAIL) ?: $username;
    $fromName = trim((string)SMTP_FROM_NAME) ?: 'Vidoon';

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'smtp_not_configured';
        return false;
    }

    $transportHost = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client(
        $transportHost . ':' . $port,
        $errorNumber,
        $errorString,
        15,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        $errorMessage = 'smtp_connect_failed';
        return false;
    }

    stream_set_timeout($socket, 15);
    try {
        smtp_expect($socket, [220]);
        $clientName = gethostname() ?: 'localhost';
        smtp_command($socket, 'EHLO ' . $clientName, [250]);

        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed');
            }
            smtp_command($socket, 'EHLO ' . $clientName, [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $purposeText = $purpose === 'reset_password' ? '重置密码' : '注册账号';
        $subject = 'Vidoon ' . $purposeText . '验证码';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $body = "您好：\r\n\r\n"
            . "您正在进行 Vidoon {$purposeText}操作，验证码为：{$code}\r\n\r\n"
            . "验证码 10 分钟内有效，请勿转发给他人。\r\n"
            . "如果不是您本人操作，请忽略此邮件。\r\n";

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $message = preg_replace('/^\./m', '..', $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        fclose($socket);
        $errorMessage = 'smtp_send_failed';
        error_log('SMTP verification email failed: ' . $e->getMessage());
        return false;
    }
}

function smtp_send_text_email($recipient, $subject, $body, &$errorMessage = null) {
    $config = [
        'enabled' => SMTP_ENABLED,
        'host' => SMTP_HOST,
        'port' => SMTP_PORT,
        'secure' => SMTP_SECURE,
        'username' => SMTP_USERNAME,
        'password' => SMTP_PASSWORD,
        'from_email' => SMTP_FROM_EMAIL,
        'from_name' => SMTP_FROM_NAME,
    ];
    return smtp_send_text_email_with_config($recipient, $subject, $body, $config, $errorMessage);
}

function smtp_send_text_email_with_config($recipient, $subject, $body, array $config, &$errorMessage = null) {
    if (empty($config['enabled'])) {
        $errorMessage = 'order_email_disabled';
        return false;
    }
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'smtp_recipient_invalid';
        return false;
    }
    if (!extension_loaded('openssl')) {
        $errorMessage = 'smtp_openssl_missing';
        return false;
    }
    $host = trim((string)($config['host'] ?? ''));
    $port = intval($config['port'] ?? 0);
    $secure = strtolower(trim((string)($config['secure'] ?? 'ssl')));
    $username = trim((string)($config['username'] ?? ''));
    $password = (string)($config['password'] ?? '');
    $fromEmail = trim((string)($config['from_email'] ?? '')) ?: $username;
    $fromName = trim((string)($config['from_name'] ?? '')) ?: 'Vidoon';
    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        $errorMessage = 'smtp_config_incomplete';
        return false;
    }

    $socket = @stream_socket_client(
        ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port,
        $errorNumber,
        $errorString,
        15,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        $errorMessage = 'smtp_connect_failed';
        return false;
    }
    stream_set_timeout($socket, 15);
    $stage = 'greeting';
    try {
        smtp_expect($socket, [220]);
        $stage = 'ehlo';
        smtp_command($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
        if ($secure === 'tls') {
            $stage = 'tls';
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed');
            }
            smtp_command($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
        }
        $stage = 'auth';
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        $stage = 'sender';
        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        $stage = 'recipient';
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        $stage = 'content';
        smtp_command($socket, 'DATA', [354]);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", trim($body));
        fwrite($socket, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        fclose($socket);
        $stageErrors = [
            'tls' => 'smtp_tls_failed',
            'auth' => 'smtp_auth_failed',
            'sender' => 'smtp_sender_rejected',
            'recipient' => 'smtp_recipient_rejected',
        ];
        $errorMessage = $stageErrors[$stage] ?? 'smtp_send_failed';
        error_log('SMTP order email failed: ' . $e->getMessage());
        return false;
    }
}
