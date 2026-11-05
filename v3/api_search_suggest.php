<?php
/**
 * =====================================================================
 *  api_search_suggest.php — 検索語の補完 API（JSON を返す）
 * =====================================================================
 *  検索ページ（search.php）の入力欄から、JavaScript（assets/app.js の setupSearchSuggest）が呼ぶ。
 *
 *  リクエスト（GET）: ?q=入力中の文字
 *  レスポンス（JSON）: {"items": [{"text": "検索語", "kind": "member|band|artist|live|venue|role|faculty|song"}, ...]}
 *  並び順: 先頭が一致するものを先に、足りなければ途中が一致するものを足す。最大 MAX 件
 *
 *  読むだけなので GET（CSRF 対策のトークンは要らない）。ログイン必須。
 *  曲名は setlist_hidden_sql() で、出演まで非公開のバンドの曲を除く（search.php と同じ扱い）。
 *  ⚠ LIKE の % と _ は escape_like() で普通の文字にしてから使う（検索ページと同じ）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30'); // 同じ文字の問い合わせはブラウザが少しのあいだ覚える

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしてください']);
    exit;
}

const SUGGEST_MAX = 8;      // 全部で何件まで返すか
const SUGGEST_PER_KIND = 3; // 種類ごとの上限（1つの種類で埋まらないように）

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 50));
if ($q === '') {
    echo json_encode(['items' => []]);
    exit;
}

$esc = addcslashes($q, '\\%_');
$pdo = db();
$COL = 'COLLATE utf8mb4_unicode_520_ci';

/**
 * 各種類のクエリ。? に前方一致（'語%'）か部分一致（'%語%'）を渡す。
 * 返すのは検索語にする文字（1列目）だけ。種類ごとに SUGGEST_PER_KIND 件まで。
 * 並びは「よく出るもの」を上に（人数・回数が多い順）。
 */
$kinds = [
    'member' => ["SELECT m.name FROM member m
                  WHERE m.name $COL LIKE ? OR m.name_kana $COL LIKE ?
                  GROUP BY m.member_id ORDER BY (SELECT COUNT(*) FROM band_member x WHERE x.member_id = m.member_id) DESC, m.name"],
    'band' => ["SELECT b.name FROM band b
                WHERE b.name $COL LIKE ?
                GROUP BY b.name ORDER BY COUNT(*) DESC, b.name"],
    'artist' => ['SELECT a.name FROM artist a
                  ' . (is_admin() ? 'LEFT ' : '') . 'JOIN (' . artist_plays_sql() . ") p ON p.artist_id = a.artist_id
                  WHERE a.name $COL LIKE ? OR a.name_kana $COL LIKE ?
                     OR EXISTS (SELECT 1 FROM artist_alias al WHERE al.artist_id = a.artist_id AND al.name $COL LIKE ?)
                  GROUP BY a.artist_id ORDER BY COUNT(p.band_id) DESC, a.name"],
    'live' => ["SELECT lm.name FROM live lm WHERE lm.name $COL LIKE ? ORDER BY lm.fiscal_year DESC"],
    'venue' => ["SELECT v.name FROM venue v WHERE v.name $COL LIKE ? ORDER BY v.name"],
    'role' => ["SELECT r.name FROM role r WHERE r.name $COL LIKE ? ORDER BY r.sort_order, r.name"],
    'song' => ["SELECT s.title AS name FROM song s JOIN band b ON b.band_id = s.band_id
                WHERE s.is_se = 0 AND s.title <> '' AND NOT " . setlist_hidden_sql('b') . " AND s.title $COL LIKE ?
                GROUP BY s.title ORDER BY COUNT(*) DESC, s.title"],
];

/** 全角英数・大文字小文字・カタカナ/ひらがなの違いを無視して同じ語か比べるための形 */
$norm = static fn(string $s): string => mb_strtolower(mb_convert_kana($s, 'KVrnas'), 'UTF-8');

$items = [];
$seen = []; // 同じ文字を2回出さない（バンド名とアーティスト名が同じ、など）
$q_norm = $norm($q);

// 前方一致 → 部分一致の順に集める。前方一致で埋まったら部分一致は引かない（DB への問い合わせを減らす）
foreach (['' => $esc . '%', 'any' => '%' . $esc . '%'] as $mode => $pattern) {
    if (count($items) >= SUGGEST_MAX) {
        break;
    }
    // 学部は DB ではなく定数なので PHP で当てる
    foreach (FACULTIES as $f) {
        $hit = $mode === '' ? str_starts_with($f, $q) : (mb_strpos($f, $q) !== false);
        if ($hit && !isset($seen[$norm($f)]) && count($items) < SUGGEST_MAX) {
            $seen[$norm($f)] = true;
            $items[] = ['text' => $f, 'kind' => 'faculty'];
        }
    }
    foreach ($kinds as $kind => [$sql]) {
        $perKind = 0;
        $st = $pdo->prepare($sql . ' LIMIT ' . (SUGGEST_PER_KIND + 4)); // 重複で捨てる分の余裕
        $st->execute(array_fill(0, substr_count($sql, '?'), $pattern)); // ? の数だけ同じ値を渡す
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = (string)$name;
            $key = $norm($name);
            if ($name === '' || $key === $q_norm || isset($seen[$key])) {
                continue; // 打った文字そのままの候補は出さない
            }
            $seen[$key] = true;
            $items[] = ['text' => $name, 'kind' => $kind];
            if (++$perKind >= SUGGEST_PER_KIND || count($items) >= SUGGEST_MAX) {
                break;
            }
        }
        if (count($items) >= SUGGEST_MAX) {
            break;
        }
    }
}

echo json_encode(['items' => array_slice($items, 0, SUGGEST_MAX)], JSON_UNESCAPED_UNICODE);
