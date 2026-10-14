<?php
/**
 * =====================================================================
 *  api_track_info.php — 紐付けた曲1つの情報（JSON を返す）
 * =====================================================================
 *  セッティングシート（assets/setting_sheet.js）が、DB で紐付け済みの曲の「分数」（曲の長さ）を埋めるのに呼ぶ。
 *  曲の長さは DB に保存していないので、Spotify / iTunes に聞き直す。
 *
 *  リクエスト（GET）: ?key=spotify:xxxx
 *  レスポンス（JSON）: {"title": "...", "artist_name": "...", "artwork_url": "https://...", "duration": 秒 / null}
 *                     失敗したら {"error": "..."}
 *
 *  中身を読むだけで何も変えないので GET（CSRF 対策のトークンは要らない）。
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

// キーの形を確かめる（ブラウザから来た値は信用しない）
$key = album_parse_key($_GET['key'] ?? null);
if ($key === null) {
    http_response_code(400);
    echo json_encode(['error' => '曲の指定がおかしいです'], JSON_UNESCAPED_UNICODE);
    exit;
}

$t = track_lookup($key[0], $key[1]);
if ($t === null) {
    http_response_code(502); // 502 = この先のサーバー（Spotify / iTunes）がうまく答えなかった
    echo json_encode(['error' => '曲の情報を取れませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'title'       => $t['title'],
    'artist_name' => $t['artist_name'],
    'artwork_url' => $t['artwork_url'],
    'duration'    => isset($t['duration_ms']) ? (int)round($t['duration_ms'] / 1000) : null,
], JSON_UNESCAPED_UNICODE);
