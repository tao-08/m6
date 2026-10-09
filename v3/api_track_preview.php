<?php
/**
 * =====================================================================
 *  api_track_preview.php — 曲の30秒試聴の音源 URL を返す API（JSON）
 * =====================================================================
 *  バンドページのセットリストの ▶ ボタンから、JavaScript（assets/app.js の setupPreview）が呼ぶ。
 *  ページを開いたときではなく、▶ が押されたときだけ探す（開くたびに iTunes を何回も叩かないため）。
 *  一度探した結果は track_preview に覚えておくので、2回目からは band.php が HTML に入れて出す（ここは呼ばれない）。
 *
 *  リクエスト（GET）: ?track=spotify:xxxx              … 紐付けてある曲のキー
 *                     &refresh=1（あれば）              … 覚えてある URL で鳴らなかったので探し直す
 *  レスポンス（JSON）: {"url": "https://audio-ssl.itunes.apple.com/..."} / {"url": null}（試聴が無い）
 *                     探せなかったら {"error": "..."}
 *
 *  ★ 受け取るのは曲のキーだけ。曲名・アーティスト名は DB の track から読む（song_go.php と同じ）。
 *    紐付けてある曲しか探さないので、でたらめな曲名で iTunes を叩かせることはできない。
 *  ★ 覚え書きを書き換えるが、中身は「iTunes で調べた結果」だけで、誰が押しても同じになるので GET のまま
 *    （CSRF で押させても困ることが起きない）。
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

$key = album_parse_key($_GET['track'] ?? null);
if ($key === null) {
    http_response_code(400);
    echo json_encode(['error' => '曲の指定がおかしいです'], JSON_UNESCAPED_UNICODE);
    exit;
}
[$source, $trackId] = $key;

$pdo = db();
$st = $pdo->prepare('SELECT t.source, t.track_id, t.title, t.artist_name, p.preview_url, p.checked_at,
        p.checked_at > NOW() - INTERVAL 10 MINUTE AS fresh,
        p.checked_at > NOW() - INTERVAL ' . ALBUM_NOT_FOUND_RETRY_DAYS . ' DAY AS recent
    FROM track t
    LEFT JOIN track_preview p ON p.source = t.source AND p.track_id = t.track_id
    WHERE t.source = ? AND t.track_id = ?');
$st->execute([$source, $trackId]);
$track = $st->fetch();
if (!$track) {
    http_response_code(404);
    echo json_encode(['error' => '曲が見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 覚えてある結果を使うとき:
//   ふつう   … 見つかった URL はずっと、「無かった」は30日だけ（その後は探し直す）
//   refresh … 鳴らなかった URL でも、10分以内に探したばかりなら探し直さない（何回も押して iTunes を叩かせない）
if ($track['checked_at'] !== null) {
    $useCache = !empty($_GET['refresh'])
        ? (bool)$track['fresh']
        : ($track['preview_url'] !== null || $track['recent']);
    if ($useCache) {
        echo json_encode(['url' => $track['preview_url']]);
        exit;
    }
}

$url = track_preview_find($track);
if ($url === false) {
    http_response_code(502); // 502 = この先のサーバー（iTunes）がうまく答えなかった
    echo json_encode(['error' => '今は試聴を探せません。時間をおいてもう一度試してください'], JSON_UNESCAPED_UNICODE);
    exit;
}
// 同時に2人が押しても、ON DUPLICATE KEY UPDATE でエラーにしない
$pdo->prepare('INSERT INTO track_preview (source, track_id, preview_url) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE preview_url = VALUES(preview_url), checked_at = CURRENT_TIMESTAMP')
    ->execute([$source, $trackId, $url]);
echo json_encode(['url' => $url]);
