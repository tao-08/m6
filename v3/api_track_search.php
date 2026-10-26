<?php
/**
 * =====================================================================
 *  api_track_search.php — 曲の検索 API（JSON を返す）
 * =====================================================================
 *  曲の編集画面（songs_edit.php）の🔍ボタンから、JavaScript（assets/app.js の setupTrackSearch）が呼ぶ。
 *
 *  リクエスト（GET）: ?q=曲名 アーティスト名
 *  レスポンス（JSON）: {"tracks": [{"key": "spotify:xxxx", "title": "...", "artist_name": "...",
 *                                   "album_title": "...", "artwork_url": "https://...",
 *                                   "preview": true（試聴あり）/ false（無し）/ null（わからない）,
 *                                   "duration": 曲の長さ（秒）/ null}, ...]}
 *                     検索できなかったら {"error": "..."}
 *  並び順: 検索の関連度順を基本に、同じ曲の版違いの中ではオリジナル・アルバムの版を上にする（lib/tracks.php の track_sort_by_version）
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
require_once __DIR__ . '/lib/tabs.php';
if (current_user() === null && !guest_take('#g_track', 30)) { // 未ログインも使える（練習プレイヤーの曲登録）。外部 API を使うので1時間あたりの回数制限つき
    http_response_code(429);
    echo json_encode(['error' => '検索が多すぎます。しばらくたってからもう一度お試しください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if ($q === '') {
    echo json_encode(['tracks' => []]);
    exit;
}

// 画面に出すのは10件だが、並べ替えの前に20件取る。
//   オリジナル・アルバムの版が検索結果の11〜20件目に埋もれていることがあり、10件だけだと拾えないため。
//   （Spotify は10件ずつしか取れないので、20件 = 通信2回。少し遅くなる代わりに、メインの版が見つかりやすくなる）
['tracks' => $tracks] = track_search($q, 20);
if ($tracks === null) {
    http_response_code(502); // 502 = この先のサーバー（Spotify / iTunes）がうまく答えなかった
    echo json_encode(['error' => '今は検索できません。時間をおいてもう一度試してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 同じ曲の中で「オリジナル・アルバム → シングル → ベスト・ライブ等」の順に並べ替えてから、上から10件だけ返す
//   array_slice(配列, 開始位置, 件数): 配列の一部を切り出す
$tracks = array_slice(track_sort_by_version($tracks), 0, 10);
$previews = track_preview_marks(db(), $tracks, $q); // 試聴があるか（true / false / null = わからない）

echo json_encode(['tracks' => array_map(static fn($t, $preview) => [
    'key'         => album_key($t['source'], $t['track_id']),
    'title'       => $t['title'],
    'artist_name' => $t['artist_name'],
    'album_title' => $t['album_title'],
    'artwork_url' => $t['artwork_url'],
    'preview'     => $preview,
    'duration'    => isset($t['duration_ms']) ? (int)round($t['duration_ms'] / 1000) : null, // 曲の長さ（秒）。セッティングシートの分数用
], $tracks, $previews)], JSON_UNESCAPED_UNICODE);
