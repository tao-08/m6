<?php
/**
 * =====================================================================
 *  lib/intro.php — イントロクイズの出題・点数・ランキング（intro.php と api_intro.php で共有）
 * =====================================================================
 *  出題する曲 = サークルのライブで演奏された曲のうち、track に紐付いていて、試聴（track_preview.preview_url）がある曲。
 *    ・同じ track を複数のバンドがやっていても 1 曲として数える（DISTINCT）
 *    ・SE（song.is_se = 1）は出さない
 *    ・「出演まで非公開」のバンドの曲は出さない（setlist_hidden_sql。曲名がバレるため）
 *  絞り込みは 年度・ライブ・アーティスト（3つとも任意で AND）。
 *    アーティスト = COALESCE(song.artist_id, band.artist_id)（オムニバスのバンドは曲ごとのアーティスト）
 *  点数は 1 問 100〜1000 点（速いほど高い）、10 問で満点 10000。
 *  ランキングは月間（今月の自己ベスト）とベストスコア（月をまたいだ自己ベスト）。表は intro_best（migrations/125）。
 *  並べ方・順位・先月の1位は lib/ranking.php（ブロックパズルと共通）。ランキングに載るのは、絞り込みなしで 10 問遊んだときだけ。
 * =====================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/repository.php'; // setlist_hidden_sql
require_once __DIR__ . '/tracks.php';     // track_preview_find, album_key
require_once __DIR__ . '/ranking.php';

const INTRO_QUESTIONS  = 10;  // 1 ラウンドの問題数
const INTRO_CHOICES    = 4;   // 選択肢の数
const INTRO_SECONDS    = 10;  // 1 問の制限時間（秒）
const INTRO_GRACE      = 0.5; // 通信の遅れを見込む猶予（秒）。制限時間 + これを過ぎた回答は 0 点
const INTRO_MIN_SONGS  = 4;   // 出題できる最小の曲数（正解 + ダミー 3 つ）
const INTRO_LOOKUPS    = 2;   // ラウンドを始めるときに、試聴を探しに行く曲の数の上限（iTunes を叩きすぎない）
const INTRO_BROKEN_MAX = 3;   // 音源が鳴らなかった問を差し替えられる回数（1 ラウンドあたり）
const INTRO_BAND_MAX   = 20;  // 答え合わせに出すバンドの数の上限

/**
 * 点数。正解したときの 100〜1000 点（速いほど高い）。猶予も過ぎたら 0 点。
 *   下がり方は放物線: 100 + 900 × (1 − t/10)²。最初に急に下がり、後半はゆっくり（即答した人ほど得をする）
 *   例: 0秒 1000 / 2秒 676 / 4秒 424 / 6秒 244 / 8秒 136 / 10秒 100
 */
function intro_points(float $elapsed): int
{
    if ($elapsed > INTRO_SECONDS + INTRO_GRACE) {
        return 0;
    }
    $left = max(0.0, 1 - max(0.0, $elapsed) / INTRO_SECONDS); // 残り時間の割合（1 → 0）
    return (int)round(100 + 900 * $left ** 2);
}

/**
 * ブラウザから来た絞り込みを整える。['year' => ?int, 'live' => ?int, 'artist' => ?int]。
 * 整数か null だけ通す（"2024" や 1.5 は不正 = null を返す）
 */
function intro_filters_clean(mixed $body): ?array
{
    if (!is_array($body)) {
        return null;
    }
    $f = [];
    foreach (['year', 'live', 'artist'] as $k) {
        $v = $body[$k] ?? null;
        if ($v !== null && (!is_int($v) || $v < 1)) {
            return null;
        }
        $f[$k] = $v;
    }
    return $f;
}

/** 絞り込みなしか */
function intro_filters_none(array $f): bool
{
    return $f['year'] === null && $f['live'] === null && $f['artist'] === null;
}

/**
 * 出題の元になる FROM 〜 WHERE。[SQL の断片, パラメータ] を返す（別名: s=song, b=band, d=live_day, l=live, t=track）
 *   $joins = SELECT したい列のために足す JOIN（WHERE より前に入れる）
 */
function intro_from(array $f, string $joins = ''): array
{
    $sql = ' FROM song s
        JOIN band b ON b.band_id = s.band_id
        JOIN live_day d ON d.live_day_id = b.live_day_id
        JOIN live l ON l.live_id = d.live_id
        JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id ' . $joins . '
        WHERE s.is_se = 0 AND NOT ' . setlist_hidden_sql('b');
    $params = [];
    if ($f['year'] !== null) {
        $sql .= ' AND l.fiscal_year = ?';
        $params[] = $f['year'];
    }
    if ($f['live'] !== null) {
        $sql .= ' AND l.live_id = ?';
        $params[] = $f['live'];
    }
    if ($f['artist'] !== null) {
        $sql .= ' AND COALESCE(s.artist_id, b.artist_id) = ?';
        $params[] = $f['artist'];
    }
    return [$sql, $params];
}

/**
 * 範囲内の曲（1 曲 1 行）。[['key' => 'itunes:123', 'source', 'track_id', 'title', 'artist_name', 'preview' => ?string, 'lookup' => bool], …]
 *   preview = 覚えてある試聴の URL（無ければ null）
 *   lookup  = 試聴を探しに行ってよい曲（まだ調べていない、または「無い」と覚えてから ALBUM_NOT_FOUND_RETRY_DAYS 日過ぎた）
 */
function intro_pool(PDO $pdo, array $f): array
{
    [$from, $params] = intro_from($f, 'LEFT JOIN track_preview p ON p.source = t.source AND p.track_id = t.track_id');
    $st = $pdo->prepare('SELECT DISTINCT t.source, t.track_id, t.title, t.artist_name, p.preview_url,
        (p.checked_at IS NULL OR (p.preview_url IS NULL AND p.checked_at <= NOW() - INTERVAL ' . ALBUM_NOT_FOUND_RETRY_DAYS . ' DAY)) AS need_lookup'
        . $from);
    $st->execute($params);
    $pool = [];
    foreach ($st->fetchAll() as $r) {
        $pool[] = [
            'key'         => album_key($r['source'], $r['track_id']),
            'source'      => $r['source'],
            'track_id'    => $r['track_id'],
            'title'       => $r['title'],
            'artist_name' => $r['artist_name'],
            'preview'     => $r['preview_url'],
            'lookup'      => (bool)$r['need_lookup'],
        ];
    }
    return $pool;
}

/**
 * 試聴をまだ調べていない曲を、最大 INTRO_LOOKUPS 曲だけ iTunes で探して覚える（api_track_preview.php と同じ保存）。
 *   $pool は参照で受け取り、見つかった URL を書き込む。通信に失敗した曲（false）は覚えない
 */
function intro_lookup_previews(PDO $pdo, array &$pool): void
{
    $todo = array_keys(array_filter($pool, static fn($t) => $t['lookup']));
    shuffle($todo);
    foreach (array_slice($todo, 0, INTRO_LOOKUPS) as $i) {
        $url = track_preview_find($pool[$i]);
        if ($url === false) {
            continue;
        }
        $pdo->prepare('INSERT INTO track_preview (source, track_id, preview_url) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE preview_url = VALUES(preview_url), checked_at = CURRENT_TIMESTAMP')
            ->execute([$pool[$i]['source'], $pool[$i]['track_id'], $url]);
        $pool[$i]['preview'] = $url;
        $pool[$i]['lookup'] = false;
    }
}

/** 範囲内で出題できる曲の数（試聴がある曲。同じ曲は 1 曲）。タイトル画面に出す */
function intro_count(PDO $pdo, array $f): int
{
    [$from, $params] = intro_from($f, 'JOIN track_preview p ON p.source = t.source AND p.track_id = t.track_id AND p.preview_url IS NOT NULL');
    $st = $pdo->prepare('SELECT COUNT(DISTINCT t.source, t.track_id)' . $from);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** 範囲内の曲名（重複を除く。試聴の有無は問わない）。ダミーの選択肢の元 */
function intro_titles(PDO $pdo, array $f): array
{
    [$from, $params] = intro_from($f);
    $st = $pdo->prepare('SELECT DISTINCT t.title' . $from);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** 曲名を比べるためのキー（大文字小文字・前後の空白を無視） */
function intro_title_key(string $title): string
{
    return mb_strtolower(trim($title));
}

/**
 * 4 択を作る。[選択肢の曲名 4 つ（並べ替え済み）, 正解の位置] を返す。作れなければ null。
 *   ダミー 3 つ = 同じ範囲の曲名から。正解と同じ曲名・互いに同じ曲名は除く。足りなければ $allTitles()（範囲外を含む全曲）から補う
 *   $allTitles = 全曲の曲名を返す関数（足りないときだけ呼ぶ）
 */
function intro_choices(string $correct, array $scopeTitles, callable $allTitles): ?array
{
    $seen = [intro_title_key($correct) => true];
    $dummies = [];
    $lists = [$scopeTitles, null]; // null = 足りないときに $allTitles() で取る
    foreach ($lists as $list) {
        if (count($dummies) >= INTRO_CHOICES - 1) {
            break;
        }
        $list ??= $allTitles();
        shuffle($list);
        foreach ($list as $t) {
            $k = intro_title_key($t);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $dummies[] = $t;
            if (count($dummies) >= INTRO_CHOICES - 1) {
                break;
            }
        }
    }
    if (count($dummies) < INTRO_CHOICES - 1) {
        return null;
    }
    $choices = array_merge([$correct], $dummies);
    shuffle($choices);
    return [$choices, (int)array_search($correct, $choices, true)];
}

/**
 * 答え合わせに出す情報。['title', 'artist', 'artwork', 'bands' => [['id', 'name', 'live'], …]]
 *   bands = その曲を演奏したバンド（「出演まで非公開」は出さない。絞り込み中はその範囲だけ）。新しい年度から
 *   名前は生のまま（JS は textContent で入れる）
 */
function intro_track_info(PDO $pdo, array $f, string $source, string $trackId): array
{
    $st = $pdo->prepare('SELECT title, artist_name, artwork_url FROM track WHERE source = ? AND track_id = ?');
    $st->execute([$source, $trackId]);
    $t = $st->fetch() ?: ['title' => '', 'artist_name' => '', 'artwork_url' => ''];

    [$from, $params] = intro_from($f);
    $st = $pdo->prepare('SELECT DISTINCT b.band_id, b.name, l.fiscal_year, l.name AS live_name, d.held_on, b.play_order' . $from
        . ' AND t.source = ? AND t.track_id = ?
        ORDER BY l.fiscal_year DESC, d.held_on DESC, b.play_order
        LIMIT ' . INTRO_BAND_MAX);
    $st->execute([...$params, $source, $trackId]);
    $bands = [];
    foreach ($st->fetchAll() as $r) {
        $bands[] = ['id' => (int)$r['band_id'], 'name' => $r['name'], 'live' => $r['fiscal_year'] . ' ' . $r['live_name']];
    }
    return ['title' => $t['title'], 'artist' => $t['artist_name'], 'artwork' => $t['artwork_url'], 'bands' => $bands];
}

/**
 * 自己ベストを保存する（ランキングに載るラウンドの最後だけ呼ぶ）。[ベストスコアを更新したか, 今月の自己ベストを更新したか] を返す
 *   0 点は記録しない。自分の行をロックして読んでから書く（同時に 2 回届いても、片方ずつ順に処理される）。
 *   ベスト = 月をまたいだ最高点より高いとき、今月 = 今月の記録より高い（または今月の記録がまだ無い）とき。
 *   呼び出し側で PDOException を受ける。トランザクションはここで張る
 */
function intro_save_best(PDO $pdo, int $userId, int $score, int $correct): array
{
    if ($score <= 0) {
        return [false, false];
    }
    $month = ranking_month_start();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT month_start, score FROM intro_best WHERE user_id = ? FOR UPDATE');
        $st->execute([$userId]);
        $allMax = 0;
        $monthOld = null;
        foreach ($st->fetchAll() as $r) {
            $allMax = max($allMax, (int)$r['score']);
            if ($r['month_start'] === $month) {
                $monthOld = (int)$r['score'];
            }
        }
        $best = $score > $allMax;
        $monthBest = $monthOld === null || $score > $monthOld;
        if ($monthBest) {
            // 行が無ければ追加、あれば更新。score は SET の一番最後に書く（先に変えると、後ろの列の比較が新しい値になってしまう）
            $pdo->prepare('INSERT INTO intro_best (user_id, month_start, score, correct, updated_at) VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    correct = IF(VALUES(score) > score, VALUES(correct), correct),
                    updated_at = IF(VALUES(score) > score, VALUES(updated_at), updated_at),
                    score = IF(VALUES(score) > score, VALUES(score), score)')
                ->execute([$userId, $month, $score, $correct]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [$best, $monthBest];
}

/** ランキング（ベストスコア・今月・先月の1位）。キーは lib/ranking.php の ranking_payload */
function intro_rankings(PDO $pdo, int $userId): array
{
    return ranking_payload($pdo, 'intro_best', 'intro_best', 'b.correct', $userId);
}

/** 絞り込みの候補。[ライブ一覧（年度の新しい順）, アーティスト一覧（読み順）]。紐付け済みの曲があるものだけ */
function intro_filter_options(PDO $pdo): array
{
    $none = ['year' => null, 'live' => null, 'artist' => null];
    [$from] = intro_from($none);
    $lives = $pdo->query('SELECT DISTINCT l.live_id, l.fiscal_year, l.name' . $from . ' ORDER BY l.fiscal_year DESC, l.name')->fetchAll();
    [$from] = intro_from($none, 'JOIN artist ar ON ar.artist_id = COALESCE(s.artist_id, b.artist_id)');
    $artists = $pdo->query('SELECT DISTINCT ar.artist_id, ar.name, ar.name_kana' . $from)->fetchAll();
    // 読み順（かながあればそれ。カタカナはひらがなにそろえる）。artists.php と同じ
    usort($artists, static fn($a, $b) => strcmp(mb_convert_kana($a['name_kana'] ?? $a['name'], 'c'), mb_convert_kana($b['name_kana'] ?? $b['name'], 'c')));
    return [$lives, $artists];
}
