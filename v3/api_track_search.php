<?php
/**
 * =====================================================================
 *  api_track_search.php — 曲の検索 API（JSON を返す）
 * =====================================================================
 *  曲の編集画面（songs_edit.php）の🔍ボタンから、JavaScript（assets/app.js の setupTrackSearch）が呼ぶ。
 *
 *  リクエスト（GET）: ?q=曲名 アーティスト名
 *  レスポンス（JSON）: {"tracks": [{"key": "spotify:xxxx", "title": "...", "artist_name": "...",
 *                                   "album_title": "...", "artwork_url": "https://..."}, ...]}
 *                     検索できなかったら {"error": "..."}
 *
 *  中身を読むだけで何も変えないので GET（CSRF 対策のトークンは要らない）。
 *  ここで返した key は、保存するときにサーバーが lookup で取り直す（ブラウザから来た曲名や画像URLは信用しない）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tracks.php';

header('Content-Type: application/json; charset=utf-8');

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if ($q === '') {
    echo json_encode(['tracks' => []]);
    exit;
}

['tracks' => $tracks] = track_search($q);
if ($tracks === null) {
    http_response_code(502); // 502 = この先のサーバー（Spotify / iTunes）がうまく答えなかった
    echo json_encode(['error' => '今は検索できません。時間をおいてもう一度試してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['tracks' => array_map(static fn($t) => [
    'key'         => album_key($t['source'], $t['track_id']),
    'title'       => $t['title'],
    'artist_name' => $t['artist_name'],
    'album_title' => $t['album_title'],
    'artwork_url' => $t['artwork_url'],
], array_slice($tracks, 0, 10))], JSON_UNESCAPED_UNICODE);
