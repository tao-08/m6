<?php
/**
 * =====================================================================
 *  export.php?live=ライブID — ライブのデータを CSV でダウンロード
 * =====================================================================
 *  DB → CSV の「逆方向」。当日配る資料を作ったり、Excel で別の集計をしたりできる。
 *
 *  Excel で文字化けしないように:
 *    - 先頭に BOM（\xEF\xBB\xBF）を付ける → Excel が「これは UTF-8」と分かる
 *  CSV インジェクション対策:
 *    - セルが = + - @ で始まると、Excel が「数式」として実行してしまうことがある。
 *      先頭に ' を付けてただの文字にする（csv_safe）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$liveId = (int)($_GET['live'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    exit('ライブが見つかりません');
}

/** Excel に数式として解釈されないようにする */
function csv_safe(mixed $v): string
{
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// 楽器の列（instrument テーブルの順）
$instruments = instruments();

// バンドごとに1行。メンバーは楽器の列に「、」区切りで入れる
$st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, b.song_count, b.note,
        ld.live_day_id, ld.label, ld.held_on, v.name AS venue, b.start_time, b.end_time, a.name AS artist
    FROM band b
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE ld.live_id = ?
    ORDER BY ld.held_on IS NULL, ld.held_on, ld.live_day_id, b.play_order');
$st->execute([$liveId]);
$bands = $st->fetchAll();

$st = $pdo->prepare('SELECT bm.band_id, bm.instrument_id, m.name FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    WHERE ld.live_id = ? ORDER BY m.name');
$st->execute([$liveId]);
$cells = []; // [band_id][instrument_id] = ['名前', ...]
foreach ($st as $r) {
    $cells[$r['band_id']][$r['instrument_id']][] = $r['name'];
}

// ---- ダウンロードさせるためのヘッダー ----
$filename = sprintf('%d_%s.csv', $live['fiscal_year'], $live['name']);
header('Content-Type: text/csv; charset=UTF-8');
// filename* は日本語のファイル名を正しく渡すための書き方（RFC 5987）
header("Content-Disposition: attachment; filename=\"live.csv\"; filename*=UTF-8''" . rawurlencode($filename));

$out = fopen('php://output', 'w'); // php://output = 画面（レスポンス）に書き出す
fwrite($out, "\xEF\xBB\xBF");      // BOM
fputcsv($out, array_merge(['日程', '日付', '会場', '出演順', '開始', '終了', 'バンド名', 'アーティスト', '曲数', 'メモ'], array_column($instruments, 'short_name')), ',', '"', '');
foreach ($bands as $b) {
    $row = [$b['label'], $b['held_on'], $b['venue'], $b['play_order'], fmt_time($b['start_time']), fmt_time($b['end_time']), $b['name'], $b['artist'], $b['song_count'], $b['note']];
    foreach ($instruments as $ins) {
        $row[] = implode('、', $cells[$b['band_id']][$ins['instrument_id']] ?? []);
    }
    fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
}
fclose($out);
