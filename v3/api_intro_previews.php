<?php
/**
 * =====================================================================
 *  api_intro_previews.php — 試聴の一括調査の API（JSON を返す。管理者のみ）
 * =====================================================================
 *  intro_previews.php（試聴の一括調査）から、assets/intro_previews.js が呼ぶ。
 *  リクエスト（POST, JSON）。CSRF は X-CSRF-Token ヘッダー（api_user が確かめる）。action で動きが変わる:
 *
 *  ① {"action": "next"}（本文なしでもこれ）… 試聴を調べていない曲を 1 曲だけ調べて覚える。数秒おきに繰り返し呼ばれる
 *     → {"remaining": 残り, "key": "itunes:123", "title": 曲名, "found": true/false} / {"remaining": 0, "done": true}
 *     1 回で 1 曲: iTunes の回数制限に引っかからないよう、間隔は呼ぶ側（JS）が空ける
 *  ② {"action": "search", "q": "検索語"} … 見つからない曲の探し直し。iTunes の曲の候補（試聴があるものだけ）を返す
 *     → {"candidates": [{"id": "123", "title", "artist", "album", "artwork", "preview_url"}, …]}
 *  ③ {"action": "pick", "key": "itunes:123", "candidate": "456"} … 候補の試聴を、その曲（key）の試聴として覚える
 *     → {"ok": true}。リマスター・別バージョンの曲の試聴でも、元の曲の試聴として使える
 *  エラー: {"error": "..."}（400 / 401 / 403 / 404 / 405 / 502 = iTunes に繋がらなかった）
 *
 *  ★ 調べる曲は DB から選ぶ（①）。② ③は管理者だけ。
 *    ③は送られた URL を信用せず、候補の曲 ID から iTunes に聞き直して、Apple の配信サーバーの URL だけを覚える
 *    （itunes_lookup_track → itunes_preview_url が https と Apple のホストだけを通す）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます');

$pdo = db();
$body = api_body();
$action = is_array($body) && is_string($body['action'] ?? null) ? $body['action'] : 'next';

if ($action === 'search') {
    $q = trim(is_string($body['q'] ?? null) ? $body['q'] : '');
    if ($q === '' || mb_strlen($q) > 100) {
        fail(400, '検索語は1〜100文字で入力してください');
    }
    $found = itunes_search_tracks($q, 15);
    if ($found === null) {
        fail(502, 'iTunes に接続できませんでした: ' . itunes_why());
    }
    $candidates = [];
    foreach ($found as $t) {
        if ($t['preview_url'] === null) {
            continue; // 試聴が無い候補は、選んでも使えないので出さない
        }
        $candidates[] = [
            'id'          => $t['track_id'],
            'title'       => $t['title'],
            'artist'      => $t['artist_name'],
            'album'       => $t['album_title'],
            'artwork'     => $t['artwork_url'],
            'preview_url' => $t['preview_url'],
        ];
    }
    api_ok(['candidates' => $candidates]);
}

if ($action === 'pick') {
    $key = album_parse_key($body['key'] ?? null);
    $candidateId = is_string($body['candidate'] ?? null) ? $body['candidate'] : '';
    if ($key === null || !itunes_valid_id($candidateId)) {
        fail(400, '送信内容がおかしいです');
    }
    // セトリで使われている曲だけ（でたらめなキーで track_preview を増やさない）
    $st = $pdo->prepare('SELECT 1 FROM song WHERE track_source = ? AND track_id = ? LIMIT 1');
    $st->execute($key);
    if (!$st->fetchColumn()) {
        fail(404, '曲が見つかりません');
    }
    $candidate = itunes_lookup_track($candidateId);
    if ($candidate === null || $candidate['preview_url'] === null) {
        fail(404, 'その曲の試聴が見つかりません');
    }
    $pdo->prepare('INSERT INTO track_preview (source, track_id, preview_url) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE preview_url = VALUES(preview_url), checked_at = CURRENT_TIMESTAMP')
        ->execute([$key[0], $key[1], $candidate['preview_url']]);
    api_ok(['ok' => true]);
}

// ① 未調査を 1 曲だけ調べる
$track = track_preview_pending($pdo, 1)[0] ?? null;
if ($track === null) {
    api_ok(['remaining' => 0, 'done' => true]);
}
$url = track_preview_remember($pdo, $track); // 探して覚える。false = 通信できなかった（覚えない）
if ($url === false) {
    fail(502, 'iTunes に接続できませんでした: ' . itunes_why());
}
api_ok([
    'remaining' => track_preview_pending_count($pdo),
    'key'       => album_key($track['source'], $track['track_id']),
    'title'     => $track['title'],
    'found'     => $url !== null,
]);
