<?php
/**
 * =====================================================================
 *  api_band_like.php — バンドのお気に入り（❤）を付ける / 外す API（JSON を返す）
 * =====================================================================
 *  live.php のタイムテーブル・band.php のバンド名の右の ❤ を押すと、
 *  JavaScript（assets/app.js の setupLikes）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"band_id": 12}
 *  レスポンス（JSON）      :  {"liked": true, "count": 5}
 *                             押すたびに 付ける ⇔ 外す が入れ替わる（トグル）
 *  エラー                 :  {"error": "..."}（400 / 401 / 404 / 405）
 *
 *  画面に出すのは数だけ。誰が付けたかは返さない（匿名）。
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

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
$user = current_user();
if ($user === null) {
    fail(401, 'ログインしてください');
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$body = json_decode((string)file_get_contents('php://input'), true);
$bandId = is_int($body['band_id'] ?? null) ? $body['band_id'] : 0;
if ($bandId <= 0) {
    fail(400, 'バンドの指定がおかしいです');
}
$userId = (int)$user['user_id'];

$pdo = db();
try {
    $pdo->beginTransaction();
    // バンドがあるか（無い band_id は外部キーでも弾かれるが、分かりやすいエラーにするため先に見る）
    $st = $pdo->prepare('SELECT 1 FROM band WHERE band_id = ?');
    $st->execute([$bandId]);
    if (!$st->fetchColumn()) {
        $pdo->rollBack();
        fail(404, 'バンドが見つかりません');
    }
    // まず消してみる。1行消えた = 付いていた → 外した。0行 = 付いていなかった → 付ける
    //   「SELECT してから INSERT / DELETE」より、連打しても食い違わない
    $del = $pdo->prepare('DELETE FROM band_like WHERE band_id = ? AND user_id = ?');
    $del->execute([$bandId, $userId]);
    $liked = $del->rowCount() === 0;
    if ($liked) {
        // INSERT IGNORE: 同時に2回届いても主キーで1行だけになる
        $pdo->prepare('INSERT IGNORE INTO band_like (band_id, user_id) VALUES (?, ?)')->execute([$bandId, $userId]);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM band_like WHERE band_id = ?');
    $st->execute([$bandId]);
    $count = (int)$st->fetchColumn();
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : 'お気に入りを保存できませんでした');
}

echo json_encode(['liked' => $liked, 'count' => $count], JSON_UNESCAPED_UNICODE);
