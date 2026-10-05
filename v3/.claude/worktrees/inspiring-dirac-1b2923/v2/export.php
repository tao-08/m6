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
$st = $pdo->prepare('SELECT * FROM live_master WHERE live_id = ?');
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
        ld.live_detail_id, ld.label, ld.date, v.name AS venue
    FROM band b
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    JOIN venue v ON v.venue_id = ld.venue_id
    WHERE ld.live_id = ?
    ORDER BY ld.date, ld.live_detail_id, b.play_order');
$st->execute([$liveId]);
$bands = $st->fetchAll();

$st = $pdo->prepare('SELECT bmi.band_id, bmi.instrument_id, m.name FROM band_member_instrument bmi
    JOIN member m ON m.member_id = bmi.member_id
    JOIN band b ON b.band_id = bmi.band_id
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    WHERE ld.live_id = ? ORDER BY m.name');
$st->execute([$liveId]);
$cells = []; // [band_id][instrument_id] = ['名前', ...]
foreach ($st as $r) {
    $cells[$r['band_id']][$r['instrument_id']][] = $r['name'];
}

// ---- ダウンロードさせるためのヘッダー ----
$filename = sprintf('%s_%s.csv', (int)$live['year'] ?: 'year', $live['name']);
header('Content-Type: text/csv; charset=UTF-8');
// filename* は日本語のファイル名を正しく渡すための書き方（RFC 5987）
header("Content-Disposition: attachment; filename=\"live.csv\"; filename*=UTF-8''" . rawurlencode($filename));

$out = fopen('php://output', 'w'); // php://output = 画面（レスポンス）に書き出す
fwrite($out, "\xEF\xBB\xBF");      // BOM
fputcsv($out, array_merge(['日程', '日付', '会場', '出演順', 'バンド名', '曲数', 'メモ'], array_column($instruments, 'instrument_short')), ',', '"', '');
foreach ($bands as $b) {
    $row = [$b['label'], str_starts_with($b['date'], '0000') ? '' : $b['date'], $b['venue'], $b['play_order'], $b['name'], $b['song_count'], $b['note']];
    foreach ($instruments as $ins) {
        $row[] = implode('、', $cells[$b['band_id']][$ins['instrument_id']] ?? []);
    }
    fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
}
fclose($out);
