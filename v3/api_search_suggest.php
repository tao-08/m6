<?php
/**
 * =====================================================================
 *  api_search_suggest.php — 検索語の補完 API（JSON を返す）
 * =====================================================================
 *  検索ページ（search.php）の入力欄から、JavaScript（assets/app_list.js の setupSearchSuggest）が呼ぶ。
 *
 *  リクエスト（GET）: ?q=入力中の文字
 *  レスポンス（JSON）: {"items": [{"text": "検索語", "kind": "member|band|artist|live|venue|studio|special|equip|role|faculty|song"}, ...]}
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
    'member' => ["SELECT m.name, m.name_kana FROM member m
                  WHERE m.name $COL LIKE ? OR m.name_kana $COL LIKE ?
                  GROUP BY m.member_id ORDER BY (SELECT COUNT(*) FROM band_member x WHERE x.member_id = m.member_id) DESC, m.name"],
    // バンドはオムニバスだけ。コピーバンドはコピー元のアーティストが候補に出る
    'band' => ["SELECT b.name FROM band b
                WHERE b.is_omnibus = 1 AND b.name $COL LIKE ?
                GROUP BY b.name ORDER BY COUNT(*) DESC, b.name"],
    'artist' => ['SELECT a.name, a.name_kana FROM artist a
                  LEFT JOIN (' . artist_plays_sql() . ") p ON p.artist_id = a.artist_id
                  WHERE (a.name $COL LIKE ? OR REPLACE(a.name_kana, ' ', '') $COL LIKE ?
                     OR EXISTS (SELECT 1 FROM artist_alias al WHERE al.artist_id = a.artist_id AND al.name $COL LIKE ?))
                    AND " . artist_searchable_sql() . "
                  GROUP BY a.artist_id ORDER BY COUNT(p.band_id) DESC, a.name"],
    'live' => ["SELECT lm.name FROM live lm WHERE lm.name $COL LIKE ? ORDER BY lm.fiscal_year DESC"],
    'venue' => ["SELECT v.name FROM venue v WHERE v.name $COL LIKE ? ORDER BY v.name"],
    'studio' => ["SELECT s.name FROM studio s WHERE s.name $COL LIKE ? OR s.short_name $COL LIKE ? ORDER BY s.name"],
    'special' => ["SELECT sc.title FROM special_content sc LEFT JOIN live l ON l.live_id = sc.live_id
                   WHERE (sc.live_id IS NULL OR l.live_id IS NOT NULL) AND (sc.title $COL LIKE ? OR sc.author $COL LIKE ?)
                   ORDER BY sc.fiscal_year DESC, sc.special_id DESC"],
    // 機材名（会場・スタジオ）。? には equip_like() の値を渡す（2つ目の true。表記ゆれを吸収）
    'equip' => ["SELECT e.label FROM (SELECT CONCAT_WS(' ', NULLIF(maker, ''), NULLIF(model_name, '')) AS label FROM venue_equipment UNION ALL SELECT CONCAT_WS(' ', NULLIF(maker, ''), NULLIF(model_name, '')) FROM studio_equipment) e
                 WHERE " . equip_match_sql('e.label') . ' GROUP BY e.label ORDER BY COUNT(*) DESC, e.label', true],
    'role' => ["SELECT r.name FROM role r WHERE r.name $COL LIKE ? ORDER BY r.sort_order, r.name"],
    // セットリストの曲 + 楽曲データベースの曲（手動登録・TAB・リンク）。曲名のふりがな（song_kana）でも当てる
    'song' => ["SELECT u.name, MAX(k.kana) FROM (
                  SELECT s.title AS name FROM song s JOIN band b ON b.band_id = s.band_id
                  WHERE s.is_se = 0 AND NOT " . setlist_hidden_sql('b') . " AND (s.title $COL LIKE ? OR s.title IN (SELECT title FROM song_kana WHERE REPLACE(kana, ' ', '') $COL LIKE ?))
                  UNION ALL SELECT x.title FROM (" . song_db_extra_sql() . ") x WHERE x.title $COL LIKE ? OR x.title IN (SELECT title FROM song_kana WHERE REPLACE(kana, ' ', '') $COL LIKE ?)
                ) u LEFT JOIN song_kana k ON k.title = u.name WHERE u.name <> '' GROUP BY u.name ORDER BY COUNT(*) DESC, u.name"],
];

/** 全角英数・大文字小文字・カタカナ/ひらがなの違いを無視して同じ語か比べるための形 */
$norm = static fn(string $s): string => mb_strtolower(mb_convert_kana($s, 'KVrnas'), 'UTF-8');

/** ふりがなの照合用（上の $norm に加えて、カタカナもひらがなにそろえる） */
//   濁点・半濁点・小さい字も外す（DB の照合 utf8mb4_unicode_520_ci は「が」と「か」を同じに見て当てるので、こちらもそろえないとふりがなが出ない）
$kanaFold = array_combine(mb_str_split('がぎぐげござじずぜぞだぢづでどばびぶべぼぱぴぷぺぽゔぁぃぅぇぉっゃゅょゎゕゖ'), mb_str_split('かきくけこさしすせそたちつてとはひふへほはひふへほうあいうえおつやゆよわかけ'));
$nk = static fn(string $s): string => strtr(str_replace(' ', '', mb_convert_kana($norm($s), 'cs')), $kanaFold); // 「ザ ビートルズ」の空白は無視

$items = [];
$seen = []; // 同じ文字を2回出さない（バンド名とアーティスト名が同じ、など）
$q_norm = $norm($q);

// 前方一致 → 部分一致の順に集める。前方一致で埋まったら部分一致は引かない（DB への問い合わせを減らす）
foreach (['' => $esc . '%', 'any' => '%' . $esc . '%'] as $mode => $pattern) {
    $eqPattern = equip_like($q, $mode === '');
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
    foreach ($kinds as $kind => $def) {
        [$sql, $isEquip] = $def + [1 => false];
        $perKind = 0;
        $st = $pdo->prepare($sql . ' LIMIT ' . (SUGGEST_PER_KIND + 4)); // 重複で捨てる分の余裕
        $st->execute(array_fill(0, substr_count($sql, '?'), $isEquip ? $eqPattern : $pattern)); // ? の数だけ同じ値を渡す
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = (string)$row[0];
            $kana = (string)($row[1] ?? ''); // メンバー・アーティスト・曲だけ。ふりがなで当たったときに一緒に出す
            $key = $isEquip ? str_replace(['-', ' '], '', $norm($name)) : $norm($name); // 機材は JC-120 / JC120 を1つにまとめる
            if ($name === '' || $key === $q_norm || isset($seen[$key])) {
                continue; // 打った文字そのままの候補は出さない
            }
            $seen[$key] = true;
            $item = ['text' => $name, 'kind' => $kind];
            if ($kana !== '' && mb_strpos($nk($name), $nk($q)) === false && mb_strpos($nk($kana), $nk($q)) !== false) {
                $item['kana'] = preg_replace('/\s+/u', '', $kana); // 名前では当たらず、ふりがなで当たった
            }
            $items[] = $item;
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
