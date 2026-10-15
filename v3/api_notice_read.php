<?php
/**
 * =====================================================================
 *  api_notice_read.php — ベルのポップアップに出した直近3件を既読にする API（JSON を返す）
 * =====================================================================
 *  partials/header.php のベルを開いたとき、assets/app.js が fetch() で呼ぶ。
 *  どのお知らせを既読にするかは JS から受け取らず、サーバーが「直近3件」を決める（ポップアップと同じ条件）。
 *
 *  リクエスト（POST）:  本文なし
 *  レスポンス（JSON）:  {"ok": true, "unread": 残りの未読数}
 *  エラー           :  {"error": "..."}（401 / 405）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

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
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$pdo = db();
$uid = (int)$user['user_id'];
$ids = $pdo->query('SELECT notice_id FROM notice WHERE publish_at <= NOW() ORDER BY is_important DESC, publish_at DESC, notice_id DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
$mark = $pdo->prepare('INSERT IGNORE INTO notice_read (user_id, notice_id) VALUES (?, ?)');
foreach ($ids as $id) {
    $mark->execute([$uid, (int)$id]);
}
$st = $pdo->prepare('SELECT COUNT(*) FROM notice n LEFT JOIN notice_read r ON r.notice_id = n.notice_id AND r.user_id = ? WHERE r.user_id IS NULL AND n.publish_at <= NOW()');
$st->execute([$uid]);

echo json_encode(['ok' => true, 'unread' => (int)$st->fetchColumn()], JSON_UNESCAPED_UNICODE);
