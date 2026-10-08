<?php
/**
 * =====================================================================
 *  song_go.php — 曲を「見ている人の音楽アプリ」で開く（リダイレクトするだけ。album_go.php の曲版）
 * =====================================================================
 *  セットリストの曲のリンクのうち、Spotify ⇔ Apple Music をまたぐもの
 *  （例: Spotify で紐付けた曲を、Apple Music を使っている人が押した）がここに来る。
 *
 *    1. track_link_cache に「前に探した結果」があれば、それへ
 *    2. 無ければ、そのアプリで検索して、同じ曲らしい結果へ（lib/tracks.php の track_find_on）。結果は覚えておく
 *    3. 見つからなければ、そのアプリの検索ページへ
 *
 *  受け取る値（GET）: track … "spotify:xxxx" の形のキー
 *
 *  ★ リダイレクト先は、ここで組み立てた URL（Spotify / Apple Music などのドメイン）だけ。
 *    URL そのものを受け取って飛ばすと、悪いサイトへの踏み台にされる（オープンリダイレクト）ので、そうしていない。
 *  ★ 曲名・アーティスト名も URL からは受け取らず、DB の track から読む。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tracks.php';
$user = require_login();

$key = album_parse_key($_GET['track'] ?? null);
if ($key === null) {
    http_response_code(400);
    exit('曲の指定がおかしいです');
}
[$source, $trackId] = $key;

$pdo = db();
// 紐付けてある曲だけ（でたらめな ID で外部 API を叩かせないため）
$st = $pdo->prepare('SELECT source, track_id, title, artist_name FROM track WHERE source = ? AND track_id = ?');
$st->execute([$source, $trackId]);
$track = $st->fetch();
if (!$track) {
    http_response_code(404);
    exit('曲が見つかりません');
}

$app = member_music_app($pdo, $user['member_id']);
$found = false; // false = まだ調べていない（track_listen_url の $cached と同じ決まり）

if ($app === 'spotify' || $app === 'apple_music') {
    $cache = track_link_cache_for($pdo, $app, [album_key($source, $trackId)]);
    if ($cache) {
        $found = reset($cache); // 前に探した結果（見つからなかったなら null）
    } else {
        $found = track_find_on($app, $track);
        if ($found !== false) {
            // 見つかった / 見つからなかった を覚えておく（同時に2人が押しても、ON DUPLICATE KEY UPDATE でエラーにしない）
            $pdo->prepare('INSERT INTO track_link_cache (source, track_id, app, url) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE url = VALUES(url), checked_at = CURRENT_TIMESTAMP')
                ->execute([$source, $trackId, $app, $found]);
        } else {
            $found = null; // 検索できなかった（通信エラーなど）。覚えずに、今回は検索ページへ
        }
    }
}

// ここで false を渡すと song_go.php へのリンクが返ってきて堂々巡りになるので、上で必ず URL か null にしている
$url = track_listen_url($app, $track, $found);
// ?json=1 … スマホの app.js（setupSpotifyAppLinks）が飛び先だけを聞きに来る（album_go.php と同じ）
if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['url' => $url], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
redirect($url);
