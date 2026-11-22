<?php
/**
 * api_lyrics_search.php — 歌詞フレーズから曲を検索（管理者限定）
 *
 * Musixmatch から曲名・アーティスト名のみ取得し、iTunes で試聴 URL を探す。
 * 歌詞本文は取得・表示・保存しない。
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', '歌詞検索は管理者のみ利用できます');

$body = api_body();
$q = trim(is_array($body) && is_string($body['q'] ?? null) ? $body['q'] : '');
if (mb_strlen($q) < 3 || mb_strlen($q) > 100) {
    fail(400, '検索語は3〜100文字で入力してください');
}

$apiKey = trim((string)(config('musixmatch')['api_key'] ?? ''));
if ($apiKey === '') {
    fail(503, '歌詞検索は未設定です。config.php に Musixmatch API キーを設定してください');
}

$url = 'https://api.musixmatch.com/ws/1.1/track.search?' . http_build_query([
    'apikey' => $apiKey,
    'q_lyrics' => $q,
    'page' => 1,
    'page_size' => 20,
    'f_has_lyrics' => 1,
    's_track_rating' => 'desc',
    'country' => 'jp',
]);
$response = album_http($url);
$header = $response['json']['message']['header'] ?? [];
if ($response === null || $response['status'] !== 200 || (int)($header['status_code'] ?? 0) !== 200) {
    error_log('Musixmatch track.search failed: HTTP ' . (int)($response['status'] ?? 0) . ', API ' . (int)($header['status_code'] ?? 0));
    fail(502, '歌詞検索サービスに接続できませんでした');
}

$list = $response['json']['message']['body']['track_list'] ?? [];
if (!is_array($list)) {
    fail(502, '歌詞検索サービスから不正な応答がありました');
}

$tracks = [];
$seen = [];
foreach ($list as $item) {
    $source = is_array($item) ? ($item['track'] ?? []) : [];
    if (!is_array($source)) {
        continue;
    }
    $title = trim((string)($source['track_name'] ?? ''));
    $artist = trim((string)($source['artist_name'] ?? ''));
    if ($title === '' || $artist === '') {
        continue;
    }

    // iTunes 側でタイトル・アーティストを照合し、Apple の試聴 URL を取得する。
    $found = itunes_search_tracks($title . ' ' . $artist, 10);
    if ($found === null || $found === []) {
        continue;
    }
    $chosen = null;
    $titleKey = album_match_key(song_title_core($title, $artist));
    $artistKey = album_match_key($artist);
    foreach ($found as $candidate) {
        if ($candidate['preview_url'] === null) {
            continue;
        }
        $candidateTitle = album_match_key(song_title_core($candidate['title'], $candidate['artist_name']));
        $candidateArtists = array_map('album_match_key', explode(', ', $candidate['artist_name']));
        if ($candidateTitle === $titleKey && in_array($artistKey, $candidateArtists, true)) {
            $chosen = $candidate;
            break;
        }
    }
    // 厳密一致しない候補を誤った曲として提示しない。
    if ($chosen === null || isset($seen[$chosen['track_id']])) {
        continue;
    }
    $seen[$chosen['track_id']] = true;
    $tracks[] = [
        'track_id' => $chosen['track_id'],
        'title' => $chosen['title'],
        'artist_name' => $chosen['artist_name'],
        'album_title' => $chosen['album_title'],
        'artwork_url' => $chosen['artwork_url'],
        'preview_url' => $chosen['preview_url'],
    ];
    if (count($tracks) >= 5) {
        break;
    }
}
api_ok(['tracks' => $tracks]);
