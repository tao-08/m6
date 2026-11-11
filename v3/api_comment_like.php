<?php
/**
 * =====================================================================
 *  api_comment_like.php — ライブ・バンドのコメントの ❤ を付ける / 外す API（JSON を返す）
 * =====================================================================
 *  api_board_like.php と同じ作り。assets/app.js の setupLikes が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"comment_id": 12}
 *  レスポンス（JSON）      :  {"liked": true, "count": 5}（押すたびに 付ける ⇔ 外す が入れ替わる）
 *  エラー                 :  {"error": "..."}（400 / 401 / 404 / 405）
 *  削除済みのコメントには付けられない（404）。誰が付けたかは返さない（匿名）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$body = api_body();
$commentId = is_int($body['comment_id'] ?? null) ? $body['comment_id'] : 0;
if ($commentId <= 0) {
    fail(400, 'コメントの指定がおかしいです');
}
$userId = (int)$user['user_id'];

$pdo = db();
$st = $pdo->prepare('SELECT 1 FROM comment WHERE comment_id = ? AND deleted_at IS NULL');
$st->execute([$commentId]);
if (!$st->fetchColumn()) {
    fail(404, 'コメントが見つかりません');
}
try {
    $pdo->beginTransaction();
    [$liked, $count] = api_toggle_count($pdo, 'comment_like', 'comment_id', $commentId, $userId); // 付ける ⇔ 外す（lib/api.php）
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : 'お気に入りを保存できませんでした');
}

echo json_encode(['liked' => $liked, 'count' => $count], JSON_UNESCAPED_UNICODE);
