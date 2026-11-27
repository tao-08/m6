<?php
/**
 * =====================================================================
 *  api_shuffle.php — シャッフル再生する曲の一覧（JSON）。player.php の外枠が呼ぶ。管理者だけ
 * =====================================================================
 *  リクエスト（GET）: ?scope=all | ?scope=live&id=ライブID | ?scope=band&id=バンドID
 *  レスポンス: {"items": [{song_id, title, artist, art, band_id, band, live, year, src, size, start_ms, end_ms}, ...]}（並びはバンド・時間順。混ぜるのは JS）
 *  曲の区切りは音源のチャプター（band_audio_chapter。説明は migrations/130）:
 *    曲チャプターの n 番目 = SE を除いた n 番目の曲（band.php のプレーヤーと同じ数え方）。終わり = 次のチャプター（MC も含む）。最後は null（ファイルの最後まで）
 *    MC のチャプターから始まる区間は流さない（MC は自動で飛ぶ）
 *  同じ曲が2つの音源にあるときは先のものだけ。大きさがわからない音源（ドライブの size が NULL）は途中から流せないので除く
 *  art = 紐付けた曲のジャケット（無ければ null）。artist = オムニバスのバンドの曲のアーティスト（それ以外は null）
 *  読むだけなので GET（CSRF のトークンは要らない）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/live_image.php'; // LIVE_IMAGE_DIR・live_file_disk_name

header('Content-Type: application/json; charset=utf-8');
if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => '管理者のみ実行できます'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$scope = (string)($_GET['scope'] ?? 'all');
$id = (int)($_GET['id'] ?? 0);
$where = match ($scope) {
    'live' => 'd.live_id = ?',
    'band' => 'b.band_id = ?',
    default => '1 = 1',
};
$params = in_array($scope, ['live', 'band'], true) ? [$id] : [];

$st = $pdo->prepare("SELECT c.band_id, c.audio_key, c.kind, c.at_ms, b.name AS band, l.name AS live, l.fiscal_year
    FROM band_audio_chapter c
    JOIN band b ON b.band_id = c.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    WHERE $where
    ORDER BY c.band_id, c.audio_key, c.at_ms, c.chapter_id");
$st->execute($params);
$groups = []; // "band_id|audio_key" => 時間順のチャプター
foreach ($st as $r) {
    $groups[$r['band_id'] . '|' . $r['audio_key']][] = $r;
}
$bandIds = array_values(array_unique(array_map(static fn($g) => (int)$g[0]['band_id'], $groups)));
if (!$bandIds) {
    echo json_encode(['items' => []]);
    exit;
}
$in = implode(',', array_fill(0, count($bandIds), '?'));

// セトリ（SE 以外・セトリ順）
$songs = [];
$st = $pdo->prepare("SELECT s.song_id, s.band_id, s.title, t.artwork_url, IF(b.is_omnibus = 1, a.name, NULL) AS artist
    FROM song s
    JOIN band b ON b.band_id = s.band_id
    LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
    LEFT JOIN artist a ON a.artist_id = s.artist_id
    WHERE s.is_se = 0 AND s.band_id IN ($in) ORDER BY s.band_id, s.track_no");
$st->execute($bandIds);
foreach ($st as $r) {
    $songs[(int)$r['band_id']][] = $r;
}

// 音源の URL と大きさ。バンドに紐付いたまま残っているものだけ
$src = []; // "band_id|audio_key" => [url, size]
$st = $pdo->prepare("SELECT image_id, band_id, file_name FROM band_audio WHERE band_id IN ($in)");
$st->execute($bandIds);
foreach ($st as $r) {
    $path = LIVE_IMAGE_DIR . '/' . live_file_disk_name((string)$r['file_name']);
    if (is_file($path)) {
        $src[$r['band_id'] . '|a:' . $r['image_id']] = ['attach_image?k=band_audio&id=' . (int)$r['image_id'], (int)filesize($path)];
    }
}
$st = $pdo->prepare("SELECT band_id, file_id, size FROM band_drive_file WHERE band_id IN ($in) AND mime_type LIKE 'audio/%' AND size IS NOT NULL");
$st->execute($bandIds);
foreach ($st as $r) {
    $src[$r['band_id'] . '|d:' . $r['file_id']] = ['drive_audio?band=' . (int)$r['band_id'] . '&f=' . rawurlencode((string)$r['file_id']), (int)$r['size']];
}

$items = [];
$seen = [];
foreach ($groups as $key => $chs) {
    if (!isset($src[$key])) {
        continue;
    }
    $bandId = (int)$chs[0]['band_id'];
    $n = 0;
    foreach ($chs as $i => $c) {
        if ($c['kind'] !== 'song') {
            continue;
        }
        $song = $songs[$bandId][$n++] ?? null;
        if ($song === null || isset($seen[$song['song_id']])) {
            continue; // チャプターが曲数より多い分・別の音源で出た曲は流さない
        }
        $seen[$song['song_id']] = true;
        $items[] = [
            'song_id'  => (int)$song['song_id'],
            'title'    => (string)$song['title'],
            'artist'   => $song['artist'] !== null ? (string)$song['artist'] : null,
            'art'      => $song['artwork_url'] ? preg_replace('/\d+x\d+bb/', '300x300bb', (string)$song['artwork_url']) : null, // band.php のセトリと同じ 300px 版
            'band_id'  => $bandId,
            'band'     => (string)$c['band'],
            'live'     => (string)$c['live'],
            'year'     => (int)$c['fiscal_year'],
            'src'      => $src[$key][0],
            'size'     => $src[$key][1],
            'start_ms' => (int)$c['at_ms'],
            'end_ms'   => isset($chs[$i + 1]) ? (int)$chs[$i + 1]['at_ms'] : null,
        ];
    }
}
echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
