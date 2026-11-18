<?php
/**
 * =====================================================================
 *  api_block_score.php — ブロックパズルの点数を送って、ランキングを受け取る API（JSON を返す）
 * =====================================================================
 *  ゲームオーバーになったら、JavaScript（assets/block.js）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"score": 12345, "lines": 31, "level": 4}
 *  レスポンス（JSON）      :  {"best": true,                     ← ベストスコア（月を問わない自己ベスト）を更新したか
 *                              "month_best": true,               ← 今月の自己ベストを更新したか
 *                              "ranking": [{rank,name,score,lines,level,me,fold}, …],  ← ベストスコア。記録のある全員
 *                              "mine": {rank,score,lines,level} | null,                ← 自分のベストスコアと順位
 *                              "month_ranking": […], "month_mine": {…} | null,         ← 今月（同じ形）
 *                              "last_month_top": {"score": 1234, "names": ["…"]} | null} ← 先月の1位（同点なら全員）
 *  エラー                 :  {"error": "..."}（400 / 401 / 405 / 500）
 *
 *  検証: score / lines / level はすべて整数。level は lines から決まる値と一致しなければ 400。
 *  （ブラウザの JavaScript が出す点数なので、改ざんを完全には防げない。ありえない値を弾くだけ）
 *  ベストスコアも今月の自己ベストも、それぞれ高いときだけ更新する（同じトランザクションの中で）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/block.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$body = api_body();
if (!is_array($body)) {
    fail(400, '送信内容がおかしいです');
}
// is_int = 整数だけ通す（"100" や 1.5 や null は弾く）
$score = $body['score'] ?? null;
$lines = $body['lines'] ?? null;
$level = $body['level'] ?? null;
if (!is_int($score) || !is_int($lines) || !is_int($level)) {
    fail(400, '送信内容がおかしいです');
}
if ($score < 0 || $score > BLOCK_MAX_SCORE || $lines < 0 || $lines > BLOCK_MAX_LINES || $level !== block_level_for_lines($lines)) {
    fail(400, '送信内容がおかしいです');
}
$userId = (int)$user['user_id'];

$pdo = db();
$best = false;      // ベストスコア（block_best）を更新したか
$monthBest = false; // 今月の自己ベスト（block_month_best）を更新したか
$month = ranking_month_start(); // 今月の1日。月が変わると新しい行になる
try {
    $pdo->beginTransaction();
    if ($score > 0) { // 0 点は記録しない
        // 自分の行をロックして読む（同時に2回届いても、片方ずつ順に処理される）
        $st = $pdo->prepare('SELECT score FROM block_best WHERE user_id = ? FOR UPDATE');
        $st->execute([$userId]);
        $old = $st->fetchColumn();
        if ($old === false || $score > (int)$old) {
            // 行が無ければ追加、あれば更新。ON DUPLICATE KEY UPDATE = 主キー(user_id)が重なったら UPDATE に切り替わる
            //   SET は左から順に評価されるので、score は一番最後に書く（先に変えると、後ろの列の比較が新しい値になってしまう）
            $pdo->prepare('INSERT INTO block_best (user_id, score, `lines`, level, updated_at) VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    `lines` = IF(VALUES(score) > score, VALUES(`lines`), `lines`),
                    level = IF(VALUES(score) > score, VALUES(level), level),
                    updated_at = IF(VALUES(score) > score, VALUES(updated_at), updated_at),
                    score = IF(VALUES(score) > score, VALUES(score), score)')
                ->execute([$userId, $score, $lines, $level]);
            $best = true;
        }
        // 今月の自己ベストも同じ書き方（主キーが (user_id, month_start) なので、月が変われば別の行になる）
        $st = $pdo->prepare('SELECT score FROM block_month_best WHERE user_id = ? AND month_start = ? FOR UPDATE');
        $st->execute([$userId, $month]);
        $oldMonth = $st->fetchColumn();
        if ($oldMonth === false || $score > (int)$oldMonth) {
            $pdo->prepare('INSERT INTO block_month_best (user_id, month_start, score, `lines`, level, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    `lines` = IF(VALUES(score) > score, VALUES(`lines`), `lines`),
                    level = IF(VALUES(score) > score, VALUES(level), level),
                    updated_at = IF(VALUES(score) > score, VALUES(updated_at), updated_at),
                    score = IF(VALUES(score) > score, VALUES(score), score)')
                ->execute([$userId, $month, $score, $lines, $level]);
            $monthBest = true;
        }
    }
    $pdo->commit();
    $rankings = block_rankings($pdo, $userId);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : '記録を保存できませんでした');
}

api_ok(['best' => $best, 'month_best' => $monthBest] + $rankings);
