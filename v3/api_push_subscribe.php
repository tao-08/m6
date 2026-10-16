<?php
/**
 * =====================================================================
 *  api_push_subscribe.php — この端末のプッシュ通知を 登録 / 解除 する API（JSON を返す）
 * =====================================================================
 *  board.php の通知設定のスイッチを押すと、JavaScript（assets/app.js の setupPush）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:
 *    登録: {"action": "subscribe",   "endpoint": "https://...", "p256dh": "...", "auth": "..."}
 *    解除: {"action": "unsubscribe", "endpoint": "https://..."}
 *  レスポンス（JSON）      :  {"ok": true}
 *  エラー                 :  {"error": "..."}（400 / 401 / 405 / 503）
 *
 *  同じ端末（endpoint）は 1 行だけ。ほかの人が使っていた端末でログインし直したら、持ち主を今の人に付け替える。
 *  解除できるのは自分の端末だけ（user_id も条件に入れる）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/push.php';

header('Content-Type: application/json; charset=utf-8');

/** エラーの JSON を返して終わる */
function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if ($user === null) {
    fail(401, 'ログインしてください');
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf();
if (!push_enabled()) {
    fail(503, 'プッシュ通知はまだ使えません');
}

$body = json_decode((string)file_get_contents('php://input'), true);
$action = is_array($body) && is_string($body['action'] ?? null) ? $body['action'] : '';
$endpoint = is_array($body) && is_string($body['endpoint'] ?? null) ? $body['endpoint'] : '';
if (!push_endpoint_allowed($endpoint)) {
    fail(400, '通知の送り先がおかしいです');
}
$userId = (int)$user['user_id'];
$hash = hash('sha256', $endpoint);
$pdo = db();

try {
    if ($action === 'subscribe') {
        $p256dh = is_string($body['p256dh'] ?? null) ? $body['p256dh'] : '';
        $auth = is_string($body['auth'] ?? null) ? $body['auth'] : '';
        // 端末の鍵は base64url（英数字と - _）。長さも枠に収める
        if (!preg_match('/^[A-Za-z0-9_-]{16,255}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $auth)) {
            fail(400, '通知の鍵がおかしいです');
        }
        $pdo->prepare(
            'INSERT INTO push_subscription (user_id, endpoint, endpoint_hash, p256dh, auth) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)'
        )->execute([$userId, $endpoint, $hash, $p256dh, $auth]);
    } elseif ($action === 'unsubscribe') {
        $pdo->prepare('DELETE FROM push_subscription WHERE endpoint_hash = ? AND user_id = ?')->execute([$hash, $userId]);
    } else {
        fail(400, '操作がおかしいです');
    }
} catch (PDOException $e) {
    fail(500, config('debug') ? $e->getMessage() : '通知の設定を保存できませんでした');
}

echo json_encode(['ok' => true]);
