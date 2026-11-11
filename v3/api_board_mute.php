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
require_once __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/board.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$body = api_body();
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
    $muted = api_toggle($pdo, 'board_thread_mute', 'thread_id', $threadId, $userId); // ミュート中なら外す、でなければ付ける（lib/api.php）
} catch (PDOException $e) {
    fail(500, config('debug') ? $e->getMessage() : 'ミュートを保存できませんでした');
}

echo json_encode(['muted' => $muted], JSON_UNESCAPED_UNICODE);
