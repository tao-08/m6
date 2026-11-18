<?php
/**
 * =====================================================================
 *  lib/ranking.php — 月間ランキングの共通部品（イントロクイズ lib/intro.php・ブロックパズル lib/block.php）
 * =====================================================================
 *  月は毎月1日 0 時（Asia/Tokyo。bootstrap で設定済み）で切り替わる。
 *  月ごとの表（intro_best・block_month_best）は month_start（その月の1日）を持つ。月が変わると新しい行になるので、
 *  前の月の記録はそのまま残り、「先月の1位」に使える。
 *  「月間」は同じ month_start の行、「ベストスコア」は1人の一番高い記録。どちらも同じ形の一覧にして、画面（partials/ranking_card.php）と
 *  JS（assets/ranking.js）で同じ部品を使う。
 *  順位は「自分より点数が高い人の数 + 1」。同点は同じ順位になる（1位, 2位, 2位, 4位…）。
 *  $table は SQL に直接入れるので、呼び出し側のコードの定数だけを渡すこと（入力値は渡さない）。
 * =====================================================================
 */
declare(strict_types=1);

const RANKING_SHOW = 10; // 畳んだ状態で見せる人数（これより下は、自分以外「すべて表示」を押すまで隠す）

/** 今月の1日（'2026-11-01' の形） */
function ranking_month_start(): string
{
    return date('Y-m-01');
}

/** 先月の1日。date('Y-m-01', strtotime('-1 month')) は 3/31 のような日に 3月 になってしまうので、月の1日から引く */
function ranking_prev_month_start(): string
{
    return (new DateTimeImmutable(ranking_month_start()))->modify('-1 month')->format('Y-m-d');
}

/**
 * 点数の高い順に並んだ行から、順位つきの一覧を作る。
 *   $rows = [['user_id'=>…, 'name'=>…, 'score'=>…, ほかの列…], …]（呼び出し側が ORDER BY 済み）
 *   戻り値 = 行に 'rank', 'me', 'fold' を足したもの。ほかの列は整数に直して残す（lines・level・correct など）
 *   fold = 畳んだとき隠す行（RANKING_SHOW 人目より後ろで、自分ではない人）。user_id は返さない
 *   名前は生のまま（画面は h()、JSON は textContent で入れる）
 */
function ranking_rank_rows(array $rows, int $userId): array
{
    $ranking = [];
    $prevScore = null;
    $prevRank = 0;
    foreach ($rows as $i => $r) {
        $score = (int)$r['score'];
        $isMe = (int)$r['user_id'] === $userId;
        $rank = $score === $prevScore ? $prevRank : $i + 1; // 同点は前の人と同じ順位
        $extra = array_map('intval', array_diff_key($r, ['user_id' => 1, 'name' => 1, 'score' => 1]));
        $ranking[] = ['rank' => $rank, 'name' => (string)$r['name'], 'score' => $score, 'me' => $isMe,
            // 10 人目までと自分は常に見せる。それ以外は畳む（判定をここ 1 か所にして、PHP の表示と JSON で食い違わないようにする）
            'fold' => $i >= RANKING_SHOW && !$isMe] + $extra;
        $prevScore = $score;
        $prevRank = $rank;
    }
    return $ranking;
}

/**
 * ランキングを取る。[ranking, mine] を返す。
 *   $table = 自己ベストの表（block_best / block_month_best / intro_best）。コードの定数だけを渡す
 *   $cols  = 表に出さないが返したい列（例: 'b.`lines`, b.level'）。b は $table の別名
 *   $monthStart = その月の1日。null なら月を問わない（月ごとの表なら、1人につき一番高い記録 = ベストスコア）
 *   同点は先に出した人（updated_at が古い人）を上に、それでも同じなら user_id 順（並びを毎回同じにする）
 *   mine = 自分の行（rank, score, 追加の列）。記録が無ければ null
 */
function ranking_fetch(PDO $pdo, string $table, string $cols, ?string $monthStart, int $userId): array
{
    $st = $pdo->prepare("SELECT b.user_id, u.name, b.score, $cols
        FROM $table b JOIN user_account u ON u.user_id = b.user_id
        WHERE b.score > 0" . ($monthStart !== null ? ' AND b.month_start = ?' : '') . '
        ORDER BY b.score DESC, b.updated_at ASC, b.user_id ASC');
    $st->execute($monthStart !== null ? [$monthStart] : []);
    $rows = [];
    $seen = [];
    foreach ($st->fetchAll() as $r) { // 月を問わないとき、同じ人の2行目以降（低い記録）は捨てる
        if (!isset($seen[$r['user_id']])) {
            $seen[$r['user_id']] = true;
            $rows[] = $r;
        }
    }
    $ranking = ranking_rank_rows($rows, $userId);
    $mine = null;
    foreach ($ranking as $r) {
        if ($r['me']) {
            $mine = array_diff_key($r, ['name' => 1, 'me' => 1, 'fold' => 1]);
        }
    }
    return [$ranking, $mine];
}

/**
 * 先月の1位。同点なら全員。記録が無ければ null
 *   戻り値 = ['score' => 点数, 'names' => ['名前', …]]
 */
function ranking_last_top(PDO $pdo, string $table): ?array
{
    $prev = ranking_prev_month_start();
    $st = $pdo->prepare("SELECT u.name, b.score FROM $table b JOIN user_account u ON u.user_id = b.user_id
        WHERE b.month_start = ? AND b.score > 0
          AND b.score = (SELECT MAX(m.score) FROM $table m WHERE m.month_start = ?)
        ORDER BY b.updated_at ASC, b.user_id ASC");
    $st->execute([$prev, $prev]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return null;
    }
    return ['score' => (int)$rows[0]['score'], 'names' => array_map(static fn($r) => (string)$r['name'], $rows)];
}

/**
 * 画面と API が返す、2つのランキングと先月の1位をまとめて作る（キー名は両方のゲームで同じ）
 *   $bestTable  = ベストスコア用の表 / $monthTable = 月間用の表（イントロクイズは同じ表で、月を問うか問わないかだけ違う）
 *   戻り値 = ['ranking' => ベスト, 'mine' => 自分のベスト, 'month_ranking' => 今月, 'month_mine' => 自分の今月, 'last_month_top' => 先月の1位 | null]
 */
function ranking_payload(PDO $pdo, string $bestTable, string $monthTable, string $cols, int $userId): array
{
    [$ranking, $mine] = ranking_fetch($pdo, $bestTable, $cols, null, $userId);
    [$monthRanking, $monthMine] = ranking_fetch($pdo, $monthTable, $cols, ranking_month_start(), $userId);
    return ['ranking' => $ranking, 'mine' => $mine, 'month_ranking' => $monthRanking, 'month_mine' => $monthMine,
        'last_month_top' => ranking_last_top($pdo, $monthTable)];
}

/** 「先月の1位: 名前 12,300」の文。同点なら名前を「、」でつなぐ。記録が無ければ空（assets/ranking.js の lastText と同じ形） */
function ranking_last_text(?array $top): string
{
    return $top === null ? '' : '先月の1位: ' . implode('、', $top['names']) . ' ' . number_format($top['score']);
}
