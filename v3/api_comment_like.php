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
    // まず消してみる。1行消えた = 付いていた → 外した。0行 = 付いていなかった → 付ける
    $del = $pdo->prepare('DELETE FROM comment_like WHERE comment_id = ? AND user_id = ?');
    $del->execute([$commentId, $userId]);
    $liked = $del->rowCount() === 0;
    if ($liked) {
        $pdo->prepare('INSERT IGNORE INTO comment_like (comment_id, user_id) VALUES (?, ?)')->execute([$commentId, $userId]);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM comment_like WHERE comment_id = ?');
    $st->execute([$commentId]);
    $count = (int)$st->fetchColumn();
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : 'お気に入りを保存できませんでした');
}

echo json_encode(['liked' => $liked, 'count' => $count], JSON_UNESCAPED_UNICODE);
