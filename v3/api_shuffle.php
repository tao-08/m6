<?php
/**
 * =====================================================================
 *  api_shuffle.php — シャッフル再生する曲の一覧（JSON）。player.php の外枠が呼ぶ。YouTube の曲は管理者だけ（準備中）
 * =====================================================================
 *  MIX の無い曲は、バンドの YouTube（song.video_start = 曲の始まりの秒・video_no = 何本目の動画。migrations/145・150）から流す: video = 動画 ID、src = "yt:動画 ID"、
 *    終わり = 同じ動画で次に始まる曲（無ければ動画の最後）。曲の始まりが1つも無い動画は流さない（動画まるごとは長いため）
 *    動画にチャプター（audio_key 'y:動画ID'。video_chapters.php）があれば、video_start の代わりに MIX と同じ区切り（MC・通し）で流す。曲は video_chapter_songs
 *    YouTube の区分の音源（band_audio / band_drive_file の video_id。migrations/153）があれば、video を付けずにその音源を MIX と同じに流す（yt = true。絞り込みは YouTube 側）
 *  リクエスト（GET）: ?scope=all | ?scope=live&id=ライブID | ?scope=band&id=バンドID | ?scope=member&id=メンバーID（その人が演奏した曲） | ?scope=artist&id=アーティストID（そのアーティストの曲）
 *  レスポンス: {"items": [{song_id, title, artist_id, artist, art, album, band_id, band, live_id, live, year, players, src, size, start_ms, end_ms, through_end_ms, mc_ms}, ...]}（並びはライブ・出演順・時間順 = シャッフルしないときの順。混ぜるのは JS）
 *  曲の区切りは音源のチャプター（band_audio_chapter。説明は migrations/130）:
 *    曲チャプターの n 番目 = SE を除いた n 番目の曲（band.php のプレーヤーと同じ数え方）。終わり = 次のチャプター（MC も含む）。最後は null（ファイルの最後まで）
 *    MC のチャプターから始まる区間は流さない（MC は自動で飛ぶ）
 *  同じ曲が2つの音源にあるときは先のものだけ。大きさがわからない音源（ドライブの size が NULL）は途中から流せないので除く
 *  art = 紐付けた曲のジャケット（無ければ null）。album = そのアルバム名（無ければ null）。artist = コピー元のアーティスト（オムニバスのバンドは曲ごと。オリジナルなどで無ければ null）
 *  players = 演奏者 [{id, name, parts: [{s: 楽器の略称, c: instrument_class, t: 楽器名}]}]（楽器の並び順）。曲ごとの登録が無い曲はバンドのメンバー全員
 *  読むだけなので GET（CSRF のトークンは要らない）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/live_image.php'; // LIVE_IMAGE_DIR・live_file_disk_name
require_once __DIR__ . '/lib/youtube.php';    // band_video_urls・youtube_video_id

header('Content-Type: application/json; charset=utf-8');
if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$scope = (string)($_GET['scope'] ?? 'all');
$id = (int)($_GET['id'] ?? 0);
$where = match ($scope) {
    'live' => 'd.live_id = ?',
    'band' => 'b.band_id = ?',
    'member' => 'b.band_id IN (SELECT band_id FROM band_member WHERE member_id = ?)',
    'artist' => '(b.artist_id = ? OR b.band_id IN (SELECT band_id FROM song WHERE artist_id = ?))', // 曲ごとに絞るのは下
    default => '1 = 1',
};
$params = match ($scope) {
    'live', 'band', 'member' => [$id],
    'artist' => [$id, $id],
    default => [],
};

$st = $pdo->prepare("SELECT c.band_id, c.audio_key, c.kind, c.at_ms, b.name AS band, l.live_id, l.name AS live, l.fiscal_year, d.held_on
    FROM band_audio_chapter c
    JOIN band b ON b.band_id = c.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    WHERE $where
    ORDER BY l.fiscal_year, l.live_id, d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order, b.band_id, c.audio_key, c.at_ms, c.chapter_id"); // 出演順（live.php と同じ）→ 時間順
$st->execute($params);
$groups = []; // "band_id|audio_key" => 時間順のチャプター
foreach ($st as $r) {
    $groups[$r['band_id'] . '|' . $r['audio_key']][] = $r;
}
// 範囲のバンド（出演順）。YouTube の曲の情報と、MIX と YouTube を混ぜて並べる順番に使う
$st = $pdo->prepare("SELECT b.band_id, b.name AS band, b.youtube_url, b.artist_id, a.name AS artist, l.live_id, l.name AS live, l.fiscal_year, d.held_on
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id AND b.is_omnibus = 0
    WHERE $where
    ORDER BY l.fiscal_year, l.live_id, d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order, b.band_id");
$st->execute($params);
$bandRows = [];
foreach ($st as $r) {
    $bandRows[(int)$r['band_id']] = $r + ['rank' => count($bandRows)];
}
$videoUrls = band_video_urls($pdo, array_values($bandRows)); // band_id => [URL, ...]（リンクのあるバンドだけ）
$bandIds = array_values(array_unique([...array_map(static fn($g) => (int)$g[0]['band_id'], $groups), ...array_keys($videoUrls)]));
if (!$bandIds) {
    echo json_encode(['items' => []]);
    exit;
}
$in = implode(',', array_fill(0, count($bandIds), '?'));

// セトリ（SE 以外・セトリ順）
$songs = [];
$st = $pdo->prepare("SELECT s.song_id, s.band_id, s.title, s.is_se, s.track_no, s.video_start, s.video_no, t.artwork_url, t.album_title, a.artist_id, a.name AS artist
    FROM song s
    JOIN band b ON b.band_id = s.band_id
    LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
    LEFT JOIN artist a ON a.artist_id = IF(b.is_omnibus = 1, s.artist_id, b.artist_id)
    WHERE s.is_se = 0 AND s.band_id IN ($in) ORDER BY s.band_id, s.track_no");
$st->execute($bandIds);
foreach ($st as $r) {
    $songs[(int)$r['band_id']][] = $r;
}

// 演奏者: 曲ごと（song_performer）と、無いときに使うバンドのメンバー（band_member）。楽器の並び順（Vo が先頭）
$addPlayer = static function (?array &$list, array $r): void { // ?array: 初めての曲・バンドは null で来る
    $list[(int)$r['member_id']] ??= ['id' => (int)$r['member_id'], 'name' => (string)$r['name'], 'parts' => []];
    $list[(int)$r['member_id']]['parts'][] = ['s' => (string)$r['short_name'], 'c' => instrument_class((string)$r['short_name']), 't' => (string)$r['inst']];
};
$songPlayers = [];
$st = $pdo->prepare("SELECT sp.song_id, m.member_id, m.name, i.short_name, i.name AS inst FROM song_performer sp
    JOIN member m ON m.member_id = sp.member_id JOIN instrument i ON i.instrument_id = sp.instrument_id
    WHERE sp.band_id IN ($in) ORDER BY i.sort_order, m.member_id");
$st->execute($bandIds);
foreach ($st as $r) {
    $addPlayer($songPlayers[(int)$r['song_id']], $r);
}
$bandPlayers = [];
$st = $pdo->prepare("SELECT bm.band_id, m.member_id, m.name, i.short_name, i.name AS inst FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id IN ($in) ORDER BY i.sort_order, m.member_id");
$st->execute($bandIds);
foreach ($st as $r) {
    $addPlayer($bandPlayers[(int)$r['band_id']], $r);
}

// 音源の URL と大きさ。バンドに紐付いたまま残っているものだけ
$src = []; // "band_id|audio_key" => [url, size]
$ytFile = []; // "band_id|y:動画ID" => [url, size]（YouTube の区分の音源。先のものだけ）
$st = $pdo->prepare("SELECT image_id, band_id, file_name, video_id FROM band_audio WHERE band_id IN ($in) ORDER BY sort_order, image_id");
$st->execute($bandIds);
foreach ($st as $r) {
    $path = LIVE_IMAGE_DIR . '/' . live_file_disk_name((string)$r['file_name']);
    if (is_file($path)) {
        $s = ['attach_image?k=band_audio&id=' . (int)$r['image_id'], (int)filesize($path)];
        if ($r['video_id'] !== null) {
            $ytFile[$r['band_id'] . '|y:' . $r['video_id']] ??= $s;
        } else {
            $src[$r['band_id'] . '|a:' . $r['image_id']] = $s;
        }
    }
}
$st = $pdo->prepare("SELECT band_id, file_id, size, video_id FROM band_drive_file WHERE band_id IN ($in) AND mime_type LIKE 'audio/%' AND size IS NOT NULL ORDER BY sort_order");
$st->execute($bandIds);
foreach ($st as $r) {
    $s = ['drive_audio?band=' . (int)$r['band_id'] . '&f=' . rawurlencode((string)$r['file_id']), (int)$r['size']];
    if ($r['video_id'] !== null) {
        $ytFile[$r['band_id'] . '|y:' . $r['video_id']] ??= $s;
    } else {
        $src[$r['band_id'] . '|d:' . $r['file_id']] = $s;
    }
}

// YouTube のチャプター（'y:動画ID'）: [動画 ID, 何本目, 全部で何本, YouTube の区分の音源があるか]
$ytKeys = [];
foreach ($videoUrls as $bandId => $urls) {
    foreach ($urls as $no => $url) {
        $vid = youtube_video_id($url);
        if ($vid !== null) {
            $src[$bandId . '|y:' . $vid] = $ytFile[$bandId . '|y:' . $vid] ?? ['yt:' . $vid, 0];
            $ytKeys[$bandId . '|y:' . $vid] = [$vid, $no, count($urls), isset($ytFile[$bandId . '|y:' . $vid])];
        }
    }
}

$items = [];
$seen = [];
foreach ($groups as $key => $chs) {
    if (!isset($src[$key])) {
        continue;
    }
    $bandId = (int)$chs[0]['band_id'];
    $yt = $ytKeys[$key] ?? null;
    $list = $yt ? video_chapter_songs($songs[$bandId] ?? [], $yt[1], $yt[2]) : $songs[$bandId] ?? [];
    $n = 0;
    foreach ($chs as $i => $c) {
        if ($c['kind'] !== 'song') {
            continue;
        }
        $song = $list[$n++] ?? null;
        if ($song === null || isset($seen[$song['song_id']])) {
            continue; // チャプターが曲数より多い分・別の音源で出た曲は流さない
        }
        $players = $songPlayers[(int)$song['song_id']] ?? $bandPlayers[$bandId] ?? [];
        if ($scope === 'member' && !isset($players[$id])) {
            continue; // その人が演奏していない曲
        }
        if ($scope === 'artist' && (int)$song['artist_id'] !== $id) {
            continue; // 別のアーティストの曲（オムニバスのバンドなど）
        }
        $seen[$song['song_id']] = true;
        // 通し（曲間も流す）の終わり = 次の曲のチャプター（間の「終わり」・MC は越える）。後ろに曲が無ければ最初の「終わり」、それも無ければファイルの最後（null）
        // mc = 次の曲までの最初の MC のチャプター（その位置から曲名を「MC」に）。無ければ null
        $thru = null;
        $firstEnd = null;
        $mc = null;
        for ($j = $i + 1; isset($chs[$j]); $j++) {
            $k = $chs[$j]['kind'];
            if ($k === 'song') { $thru = (int)$chs[$j]['at_ms']; break; }
            if ($k === 'mc') $mc ??= (int)$chs[$j]['at_ms'];
            else $firstEnd ??= (int)$chs[$j]['at_ms'];
        }
        if ($thru === null) {
            $thru = $firstEnd;
            if ($mc !== null && $thru !== null && $mc >= $thru) $mc = null; // 最後の曲の「終わり」より後の MC は流さない
        }
        $items[] = [
            'song_id'  => (int)$song['song_id'],
            'title'    => (string)$song['title'],
            'artist_id' => $song['artist_id'] !== null ? (int)$song['artist_id'] : null,
            'artist'   => $song['artist'] !== null ? (string)$song['artist'] : null,
            'art'      => $song['artwork_url'] ? preg_replace('/\d+x\d+bb/', '300x300bb', (string)$song['artwork_url']) : null, // band.php のセトリと同じ 300px 版
            'album'    => $song['album_title'] !== null ? (string)$song['album_title'] : null,
            'band_id'  => $bandId,
            'band'     => (string)$c['band'],
            'live_id'  => (int)$c['live_id'],
            'live'     => (string)$c['live'],
            'players'  => array_values($players),
            'year'     => (int)$c['fiscal_year'],
            'date'     => $c['held_on'], // 出演日（絞り込みでライブを日付順に並べる）
            'src'      => $src[$key][0],
            'size'     => $src[$key][1],
            'start_ms' => (int)$c['at_ms'],
            'end_ms'   => isset($chs[$i + 1]) ? (int)$chs[$i + 1]['at_ms'] : null,
            'through_end_ms' => $thru,
            'mc_ms'    => $mc,
            '_o'       => [$bandRows[$bandId]['rank'] ?? 0, (int)$song['track_no']],
        ] + ($yt ? ($yt[3] ? ['yt' => true] : ['video' => $yt[0]]) : []);
    }
}

// YouTube（MIX で出なかった曲）
foreach ($videoUrls as $bandId => $urls) {
    $b = $bandRows[$bandId];
    foreach ($urls as $no => $url) {
        $vid = youtube_video_id($url);
        if ($vid === null || isset($groups[$bandId . '|y:' . $vid])) {
            continue; // チャプターのある動画は上で出した
        }
        $file = $ytFile[$bandId . '|y:' . $vid] ?? null;
        $base = ['art' => null, 'album' => null, 'band_id' => $bandId, 'band' => (string)$b['band'], 'live_id' => (int)$b['live_id'], 'live' => (string)$b['live'],
            'year' => (int)$b['fiscal_year'], 'date' => $b['held_on'], 'mc_ms' => null]
            + ($file ? ['src' => $file[0], 'size' => $file[1], 'yt' => true] : ['src' => 'yt:' . $vid, 'video' => $vid, 'size' => 0]);
        $inVideo = array_values(array_filter($songs[$bandId] ?? [], static fn($s) => $s['video_start'] !== null && (int)$s['video_no'] === $no));
        usort($inVideo, static fn($x, $y) => (int)$x['video_start'] <=> (int)$y['video_start']);
        foreach ($inVideo as $k => $song) {
            $players = $songPlayers[(int)$song['song_id']] ?? $bandPlayers[$bandId] ?? [];
            if (isset($seen[$song['song_id']]) || ($scope === 'member' && !isset($players[$id])) || ($scope === 'artist' && (int)$song['artist_id'] !== $id)) {
                continue;
            }
            $seen[$song['song_id']] = true;
            $end = isset($inVideo[$k + 1]) ? (int)$inVideo[$k + 1]['video_start'] * 1000 : null;
            $items[] = $base + [
                'song_id' => (int)$song['song_id'], 'title' => (string)$song['title'],
                'artist_id' => $song['artist_id'] !== null ? (int)$song['artist_id'] : null, 'artist' => $song['artist'] !== null ? (string)$song['artist'] : null,
                'art' => $song['artwork_url'] ? preg_replace('/\d+x\d+bb/', '300x300bb', (string)$song['artwork_url']) : null,
                'album' => $song['album_title'] !== null ? (string)$song['album_title'] : null,
                'players' => array_values($players), 'start_ms' => (int)$song['video_start'] * 1000, 'end_ms' => $end, 'through_end_ms' => $end,
                '_o' => [$b['rank'], (int)$song['track_no']],
            ];
        }
    }
}
// 出演順・セトリ順に（MIX と YouTube が混ざるため並べ直す）
if (!is_admin()) { // YouTube は準備中: 管理者以外は MIX だけ
    $items = array_values(array_filter($items, static fn($it) => empty($it['video']) && empty($it['yt'])));
}
usort($items, static fn($x, $y) => $x['_o'] <=> $y['_o']);
foreach ($items as &$it) {
    unset($it['_o']);
}
unset($it);
echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
