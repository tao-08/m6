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
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$body = api_body();
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
    [$liked, $count] = api_toggle_count($pdo, 'band_like', 'band_id', $bandId, $userId); // 付ける ⇔ 外す（lib/api.php）
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : 'お気に入りを保存できませんでした');
}

echo json_encode(['liked' => $liked, 'count' => $count], JSON_UNESCAPED_UNICODE);
