<?php
/**
 * =====================================================================
 *  api_lives.php — ライブ一覧のデータを JSON で返す API
 * =====================================================================
 *  index.php（ライブ一覧）は、この JSON を端末の DB（IndexedDB）に保存して使う。
 *    2回目以降は「端末に保存済みのデータで先に描画 → 裏でこの API に最新か聞く」ので、
 *    重い集計クエリ（GROUP BY）の完了を待たずに画面が出る。
 *    端末側のしくみは assets/local_cache.js（保存）と assets/live_list.js（描画）。
 *
 *  リクエスト : GET api_lives.php
 *              （端末が前回の ETag を If-None-Match ヘッダーで送ってくる）
 *  レスポンス : 200 + JSON  … データが前回と変わっている（または初回）
 *              304（本文なし）… 前回と同じ。端末は保存済みのデータをそのまま使う
 *              401 + {"error": ...} … ログインしていない
 *
 *  ※ ETag = 「このデータの指紋」。中身が1文字でも変われば別の値になる。
 *     HTTP の標準の条件付きリクエスト（RFC 9110）なので、ブラウザ・CDN・プロキシが共通で理解できる。
 *  ※ 誰が見ても同じ内容なので、ユーザー別の情報（確認依頼のお知らせなど）は入れない。
 *     入れるなら、端末の保存データがアカウントをまたいで混ざらないよう、キャッシュのキーを分ける必要がある。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/** エラーの JSON を返して終わる（api_instrument.php と同じ書き方） */
function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// API なのでログイン画面へのリダイレクトではなく、401 の JSON を返す
//   （リダイレクトだと fetch() が「ログイン画面の HTML」を受け取ってしまい、JSON として読めずに壊れる）
if (current_user() === null) {
    fail(401, 'ログインしてください');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, 'GET only');
}

$pdo = db();

// ---- 日程1行ごとに、そのライブの情報とバンド数をくっつけて取ってくる（index.php にあったものをそのまま移した） ----
//   LEFT JOIN: 相手が無くても行を残す（バンドが0組の日程も出すため）
//   GROUP_CONCAT(b.note): その日のバンドのメモを1つの文字列にまとめる（端末側の絞り込み用）
$rows = $pdo->query('SELECT l.live_id, l.fiscal_year AS year, l.name AS live_name, l.youtube_url,
        d.live_day_id, d.label, d.held_on AS date, d.note, v.name AS venue_name, COUNT(b.band_id) AS band_count,
        GROUP_CONCAT(b.note SEPARATOR \' \') AS band_notes
    FROM live l
    JOIN live_day d ON d.live_id = l.live_id
    LEFT JOIN venue v ON v.venue_id = d.venue_id
    LEFT JOIN band b ON b.live_day_id = d.live_day_id
    GROUP BY d.live_day_id
    ORDER BY l.fiscal_year, d.held_on, d.live_day_id')->fetchAll();

// ---- [年度][live_id] の形に組み直す ----
$years = [];
foreach ($rows as $r) {
    // & は参照。$live を書き換えると $years の中身が直接書き換わる
    $live = &$years[(int)$r['year']][(int)$r['live_id']];
    $live['name'] = $r['live_name'];
    $live['youtube_url'] = $r['youtube_url'];
    $live['days'][] = $r;
    $live['bands'] = ($live['bands'] ?? 0) + (int)$r['band_count'];
    // 一番早い開催日（並べ替え用）。NULL は日付なし
    if ($r['date'] !== null && (!isset($live['first_date']) || $r['date'] < $live['first_date'])) {
        $live['first_date'] = $r['date'];
    }
    unset($live); // 参照を切る（次のループで別の場所を指させるため）
}
// 年度は新しい順。年度未設定（0）は一番後ろ
uksort($years, static fn($a, $b) => [$a === 0, $b] <=> [$b === 0, $a]);
// 年度の中は「開催日が新しい順」。日付なしは後ろ
foreach ($years as &$lives) {
    uasort($lives, static fn($a, $b) => [!isset($a['first_date']), $b['first_date'] ?? ''] <=> [!isset($b['first_date']), $a['first_date'] ?? '']);
}
unset($lives);

// ---- 端末に渡す形（JSON）に整える ----
//   ・並びは PHP で決め終わっているので、配列の順番がそのまま表示順（JSON の配列は順番を保つ）
//   ・「予定」かどうかは持たせない。保存したあと日が経つと変わってしまうので、日付（last_date）だけ渡して端末が毎回判定する
//   ・YouTube の URL は、ここで youtube_url_valid() を通ったものだけ渡す（端末側でももう一度確認する）
$yearList = [];
foreach ($years as $year => $lives) {
    $liveList = [];
    foreach ($lives as $liveId => $live) {
        $days = [];
        $dates = [];
        foreach ($live['days'] as $d) {
            $days[] = [
                'label' => $d['label'],
                'date'  => $d['date'],        // 'YYYY-MM-DD' または null
                'venue' => $d['venue_name'],  // 会場未設定は null
            ];
            if ($d['date'] !== null) {
                $dates[] = $d['date'];
            }
        }
        // 絞り込みで当てる文字: 日程のメモ → バンドのメモ の順（空は捨てる。元の index.php と同じ並び）
        //   array_column = 配列の中から1つの列だけ取り出す / array_filter = 空・NULL を除く / array_values = 添字を 0,1,2… に振り直す
        //   （添字が飛ぶと json_encode が配列ではなくオブジェクトにしてしまうため、振り直す）
        $notes = array_values(array_filter(
            array_merge(array_column($live['days'], 'note'), array_column($live['days'], 'band_notes')),
            static fn($n) => $n !== null && $n !== ''
        ));
        $yt = $live['youtube_url'];
        $liveList[] = [
            'id'        => (int)$liveId,
            'name'      => $live['name'],
            'days'      => $days,
            'bands'     => (int)$live['bands'],
            'last_date' => $dates ? max($dates) : null,
            'notes'     => $notes,
            'youtube'   => ($yt !== null && youtube_url_valid($yt)) ? $yt : null,
        ];
    }
    $yearList[] = ['year' => (int)$year, 'lives' => $liveList];
}

// 上の数字（ライブ数・バンド数・出演者数）
$stats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM live) AS lives,
    (SELECT COUNT(*) FROM band) AS bands,
    (SELECT COUNT(DISTINCT member_id) FROM band_member) AS members')->fetch();

$json = json_encode([
    // schema: JSON の形のバージョン。形を変えたら数字を上げる。
    //   端末の保存データの schema と違えば、端末は古い形のデータを捨てて取り直す（assets/local_cache.js）
    'schema' => 1,
    'stats'  => ['lives' => (int)$stats['lives'], 'bands' => (int)$stats['bands'], 'members' => (int)$stats['members']],
    'years'  => $yearList,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ---- ETag（データの指紋）で「前回と同じなら本文を送らない」----
//   md5 は暗号の用途ではなく、ただの指紋として使っている（安全性は関係ない）
//   ETag の値は "..." のダブルクォートで囲む決まり
$etag = '"' . md5($json) . '"';
header('ETag: ' . $etag);
// no-cache = 「保存はしてよいが、使う前に必ずサーバーに確認すること」（キャッシュしない、という意味ではない）
// private  = ログインした本人のブラウザだけが保存してよい（共有プロキシには保存させない）
header('Cache-Control: private, no-cache');
header('Vary: Cookie'); // ログイン状態（Cookie）によって応答が変わる

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304); // Not Modified: 本文なし。端末は保存済みのデータをそのまま使う
    exit;
}
echo $json;
