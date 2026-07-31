<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/include/ad_helpers.php';

function reward_claim_response($status, $message, $extra = []) {
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reward_claim_response('error', 'method_not_allowed');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
$token = trim((string)($data['token'] ?? ($_COOKIE['vidoon_free_reward'] ?? '')));
$claimAction = strtolower(trim((string)($data['action'] ?? 'claim')));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    reward_claim_response('error', 'invalid_reward_token');
}
if (!in_array($claimAction, ['start', 'claim'], true)) {
    reward_claim_response('error', 'invalid_claim_action');
}

$conn = get_db_connection();
$config = get_ad_config($conn);
if (!ad_reward_is_ready($config)) {
    reward_claim_response('error', 'free_reward_disabled');
}

$tokenHash = ad_reward_token_hash($token);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("
        SELECT *,
               GREATEST(0, 5 - TIMESTAMPDIFF(SECOND, claim_started_at, NOW())) AS wait_remaining
        FROM ad_reward_sessions
        WHERE reward_token_hash = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    if (!$session) {
        throw new RuntimeException('ad_reward_session_not_found');
    }
    if ($session['status'] === 'granted') {
        $conn->commit();
        reward_claim_response('ok', 'reward_already_granted', [
            'reward_count' => intval($session['reward_count']),
        ]);
    }
    if ($session['status'] !== 'pending' || strtotime($session['expires_at']) < time()) {
        $expire = $conn->prepare("
            UPDATE ad_reward_sessions
            SET status = 'expired', updated_at = NOW()
            WHERE id = ?
        ");
        $sessionId = intval($session['id']);
        $expire->bind_param('i', $sessionId);
        $expire->execute();
        $conn->commit();
        reward_claim_response('error', 'ad_reward_session_expired');
    }

    $userId = intval($session['user_id']);
    // Serialize rewards per account so parallel browser tabs cannot bypass limits.
    $userLock = $conn->prepare("
        SELECT id, account_level
        FROM users
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $userLock->bind_param('i', $userId);
    $userLock->execute();
    $lockedUser = $userLock->get_result()->fetch_assoc();
    if (!$lockedUser) {
        throw new RuntimeException('account_not_found');
    }
    if (normalize_account_level($lockedUser['account_level'] ?? 'free') !== 'free') {
        throw new RuntimeException('paid_subscription_not_eligible');
    }

    if ($claimAction === 'start') {
        $sessionId = intval($session['id']);
        if (empty($session['claim_started_at'])) {
            $start = $conn->prepare("
                UPDATE ad_reward_sessions
                SET claim_started_at = NOW(), updated_at = NOW()
                WHERE id = ? AND claim_started_at IS NULL
            ");
            $start->bind_param('i', $sessionId);
            $start->execute();
            $session['wait_remaining'] = 5;
        }
        $waitSeconds = max(0, intval($session['wait_remaining'] ?? 5));
        $conn->commit();
        reward_claim_response('ok', 'free_reward_countdown_started', [
            'wait_seconds' => $waitSeconds,
            'reward_count' => intval($session['reward_count']),
        ]);
    }

    $claimStartedAt = trim((string)($session['claim_started_at'] ?? ''));
    if ($claimStartedAt === '') {
        throw new RuntimeException('free_reward_countdown_not_started');
    }
    $waitRemaining = max(0, intval($session['wait_remaining'] ?? 0));
    if ($waitRemaining > 0) {
        $conn->commit();
        reward_claim_response('wait', 'free_reward_countdown_active', [
            'wait_seconds' => $waitRemaining,
        ]);
    }

    if (ad_reward_today_claim_count($conn, $userId) >= intval($config['daily_view_limit'])) {
        throw new RuntimeException('ad_reward_daily_limit_reached');
    }
    $lastGrantedAt = ad_reward_last_granted_at($conn, $userId);
    if (
        $lastGrantedAt !== ''
        && (time() - strtotime($lastGrantedAt)) < intval($config['cooldown_seconds'])
    ) {
        throw new RuntimeException('ad_reward_cooldown_active');
    }

    $rewardCount = intval($session['reward_count']);
    $sessionId = intval($session['id']);
    $claim = $conn->prepare("
        INSERT INTO ad_reward_claims (
            reward_session_id, user_id, reward_count, created_at
        ) VALUES (?, ?, ?, NOW())
    ");
    $claim->bind_param('iii', $sessionId, $userId, $rewardCount);
    if (!$claim->execute()) {
        throw new RuntimeException('ad_reward_claim_write_failed');
    }

    $credit = $conn->prepare("
        UPDATE users
        SET free_credit_balance = free_credit_balance + ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $credit->bind_param('ii', $rewardCount, $userId);
    if (!$credit->execute() || $credit->affected_rows !== 1) {
        throw new RuntimeException('ad_reward_credit_update_failed');
    }

    $update = $conn->prepare("
        UPDATE ad_reward_sessions
        SET status = 'granted', granted_at = NOW(), updated_at = NOW(),
            user_agent = ?
        WHERE id = ?
    ");
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $update->bind_param('si', $userAgent, $sessionId);
    if (!$update->execute()) {
        throw new RuntimeException('ad_reward_session_update_failed');
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    reward_claim_response('error', $e->getMessage() ?: 'ad_reward_claim_failed');
}

setcookie('vidoon_free_reward', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
reward_claim_response('ok', 'reward_granted', [
    'reward_count' => $rewardCount,
]);
