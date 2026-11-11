<?php
/**
 * =====================================================================
 *  api_board_like.php — 掲示板の書き込みのお気に入り（❤）を付ける / 外す API（JSON を返す）
 * =====================================================================
 *  api_band_like.php と同じ作り。JavaScript（assets/app.js の setupLikes）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"post_id": 12}
 *  レスポンス（JSON）      :  {"liked": true, "count": 5}（押すたびに 付ける ⇔ 外す が入れ替わる）
 *  エラー                 :  {"error": "..."}（400 / 401 / 404 / 405）
 *
 *  見られないスレ・削除済みのスレ / 書き込みには付けられない（あること自体を隠すので 404）。
 *  画面に出すのは数だけ。誰が付けたかは返さない（匿名）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/board.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$body = api_body();
$postId = is_int($body['post_id'] ?? null) ? $body['post_id'] : 0;
if ($postId <= 0) {
    fail(400, '書き込みの指定がおかしいです');
}
$userId = (int)$user['user_id'];

$pdo = db();
// 書き込みとそのスレを取り、見られる人だけ通す
$st = $pdo->prepare('SELECT t.*, p.deleted_at AS post_deleted_at FROM board_post p
    JOIN board_thread t ON t.thread_id = p.thread_id WHERE p.post_id = ?');
$st->execute([$postId]);
$row = $st->fetch();
if (!$row || $row['post_deleted_at'] !== null || !board_can_view($user, $row)) {
    fail(404, '書き込みが見つかりません');
}
try {
    $pdo->beginTransaction();
    [$liked, $count] = api_toggle_count($pdo, 'board_post_like', 'post_id', $postId, $userId); // 付ける ⇔ 外す（lib/api.php）
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : 'お気に入りを保存できませんでした');
}

echo json_encode(['liked' => $liked, 'count' => $count], JSON_UNESCAPED_UNICODE);
