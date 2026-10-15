<?php
/**
 * =====================================================================
 *  api_board_mute.php — 掲示板のスレのミュートを付ける / 外す API（JSON を返す）
 * =====================================================================
 *  board_thread.php のスレ名の右のベルを押すと、JavaScript（assets/app.js の setupBoardMute）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"thread_id": 12}
 *  レスポンス（JSON）      :  {"muted": true}（押すたびに 付ける ⇔ 外す が入れ替わる）
 *  エラー                 :  {"error": "..."}（400 / 401 / 404 / 405）
 *
 *  見られないスレ・削除済みのスレは 404（あること自体を隠す）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/board.php';

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

$body = json_decode((string)file_get_contents('php://input'), true);
$threadId = is_int($body['thread_id'] ?? null) ? $body['thread_id'] : 0;
if ($threadId <= 0) {
    fail(400, 'スレッドの指定がおかしいです');
}
$userId = (int)$user['user_id'];

$pdo = db();
$st = $pdo->prepare('SELECT * FROM board_thread WHERE thread_id = ?');
$st->execute([$threadId]);
$thread = $st->fetch();
if (!$thread || $thread['deleted_at'] !== null || !board_can_view($user, $thread)) {
    fail(404, 'スレッドが見つかりません');
}
try {
    // まず消してみる。1行消えた = ミュート中だった → 外した。0行 → 付ける（連打しても食い違わない）
    $del = $pdo->prepare('DELETE FROM board_thread_mute WHERE user_id = ? AND thread_id = ?');
    $del->execute([$userId, $threadId]);
    $muted = $del->rowCount() === 0;
    if ($muted) {
        $pdo->prepare('INSERT IGNORE INTO board_thread_mute (user_id, thread_id) VALUES (?, ?)')->execute([$userId, $threadId]);
    }
} catch (PDOException $e) {
    fail(500, config('debug') ? $e->getMessage() : 'ミュートを保存できませんでした');
}

echo json_encode(['muted' => $muted], JSON_UNESCAPED_UNICODE);
