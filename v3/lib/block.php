<?php
/**
 * =====================================================================
 *  lib/block.php — ブロックパズルのランキング（block.php と api_block_score.php で共有）
 * =====================================================================
 *  表は block_best（部員ごとの自己ベスト、1人1行。migrations/104）。
 *  順位は「自分より点数が高い人の数 + 1」。同点は同じ順位になる（1位, 2位, 2位, 4位…）。
 *  `lines` は MySQL の予約語なので、SQL の中ではバッククォートで囲む。
 * =====================================================================
 */
declare(strict_types=1);

const BLOCK_RANK_SHOW  = 10;       // 畳んだ状態で見せる人数（これより下は、自分以外「すべて表示」を押すまで隠す）
const BLOCK_MAX_SCORE  = 99999999; // 点数の上限（INT UNSIGNED に十分収まる。ありえない値を弾く）
const BLOCK_MAX_LINES  = 9999;     // ライン数の上限（SMALLINT UNSIGNED に収まる）

/** ライン数から決まるレベル（10 ライン毎に +1、上限 20）。ゲーム側（assets/block.js）と同じ式 */
function block_level_for_lines(int $lines): int
{
    return min(20, 1 + intdiv($lines, 10));
}

/**
 * ランキングを取る。[ranking, mine] を返す。
 *   ranking = 記録のある全員 [['rank'=>1, 'name'=>'…', 'score'=>…, 'lines'=>…, 'level'=>…, 'me'=>bool, 'fold'=>bool], …]
 *     fold = 畳んだとき隠す行（BLOCK_RANK_SHOW 人目より後ろで、自分ではない人）。画面は fold の行にだけ class を付ける
 *   mine    = 自分の記録 ['rank'=>…, 'score'=>…, 'lines'=>…, 'level'=>…]。まだ記録が無ければ null
 * 名前は生のまま返す（画面に出すときは h()、JSON は textContent で入れるのでそのままでよい）
 */
function block_ranking(PDO $pdo, int $userId): array
{
    // 同点は先に出した人（updated_at が古い人）を上に、それでも同じなら user_id 順（並びを毎回同じにする）
    $rows = $pdo->query('SELECT b.user_id, u.name, b.score, b.`lines` AS line_count, b.level
        FROM block_best b JOIN user_account u ON u.user_id = b.user_id
        WHERE b.score > 0
        ORDER BY b.score DESC, b.updated_at ASC, b.user_id ASC')->fetchAll(); // LIMIT なし = 記録のある全員分

    $ranking = [];
    $prevScore = null;
    $prevRank = 0;
    foreach ($rows as $i => $r) {
        $score = (int)$r['score'];
        $isMe = (int)$r['user_id'] === $userId;
        $rank = $score === $prevScore ? $prevRank : $i + 1; // 同点は前の人と同じ順位
        $ranking[] = [
            'rank'  => $rank,
            'name'  => (string)$r['name'],
            'score' => $score,
            'lines' => (int)$r['line_count'],
            'level' => (int)$r['level'],
            'me'    => $isMe,
            // 10 人目までと自分は常に見せる。それ以外は畳む（判定をここ 1 か所にして、PHP の表示と JSON で食い違わないようにする）
            'fold'  => $i >= BLOCK_RANK_SHOW && !$isMe,
        ];
        $prevScore = $score;
        $prevRank = $rank;
    }

    $st = $pdo->prepare('SELECT score, `lines` AS line_count, level FROM block_best WHERE user_id = ? AND score > 0');
    $st->execute([$userId]);
    $m = $st->fetch();
    $mine = null;
    if ($m) {
        $st = $pdo->prepare('SELECT COUNT(*) + 1 FROM block_best WHERE score > ?');
        $st->execute([(int)$m['score']]);
        $mine = [
            'rank'  => (int)$st->fetchColumn(),
            'score' => (int)$m['score'],
            'lines' => (int)$m['line_count'],
            'level' => (int)$m['level'],
        ];
    }
    return [$ranking, $mine];
}
