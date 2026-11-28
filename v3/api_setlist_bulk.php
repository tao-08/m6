<?php
/**
 * =====================================================================
 *  api_setlist_bulk.php — ライブのセトリの無いバンドの曲を、裏でまとめて検出する API（JSON）。管理者だけ
 * =====================================================================
 *  setlist_jobs.php の「チャプター」タブの「未解析を解析」が、api_chapter_bulk.php と一緒に fetch() で呼ぶ（チャプターとは別の裏の処理で並んで進む）
 *  リクエスト（POST, JSON）: {"live_id": 3}
 *  対象 = 曲が1つも無く、動画が全部 MP3（YouTube の区分）を持ち、検出中・確認待ちでもないバンド（失敗したものはやり直す）
 *  すぐ {"count": バンド数} を返し、裏で出演順に1バンドずつ検出する（通常 = Flex）。結果は確認待ち（songs_edit.php で開く）
 *  全部終わったら、押した人の端末に「◯◯の曲の検出が終わりました」とプッシュ通知を1回送る
 *  エラー: {"error": "..."}（401 / 403 / 404 / 405 / 429 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/youtube.php';
require_once __DIR__ . '/lib/gemini_setlist.php';
require_once __DIR__ . '/lib/chapter_job.php'; // band_video_mp3
require_once __DIR__ . '/lib/push.php';

header('Content-Type: application/json; charset=utf-8');
$user = api_user('is_admin', '管理者のみ使えます');

$body = api_body();
$liveId = is_array($body) && is_int($body['live_id'] ?? null) ? $body['live_id'] : 0;
$pdo = db();
$st = $pdo->prepare('SELECT name FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$liveName = $st->fetchColumn();
if ($liveName === false) {
    fail(404, 'ライブが見つかりません');
}
$st = $pdo->prepare('SELECT b.band_id, b.name, b.youtube_url, b.song_count, IF(b.is_omnibus = 1, NULL, a.name) AS artist
    FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? AND NOT EXISTS (SELECT 1 FROM song s WHERE s.band_id = b.band_id)
    ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order, b.band_id'); // 出演順（api_chapter_bulk.php と同じ）
$st->execute([$liveId]);
$bands = $st->fetchAll();
$todo = []; // [[band, 何本目 => 音源キー, 動画の本数], ...]
foreach (band_video_urls($pdo, $bands) as $bandId => $urls) {
    $ids = array_map('youtube_video_id', $urls);
    $mp3s = array_map(static fn($v) => band_video_mp3($bandId, $v), array_filter($ids));
    if ($mp3s && !in_array(null, $mp3s, true) && (setlist_job_get($bandId)['state'] ?? 'error') === 'error') { // 全部そろうまで検出しない（api_setlist_detect.php と同じ）
        $band = current(array_filter($bands, static fn($b) => (int)$b['band_id'] === $bandId));
        $todo[] = [$band, $mp3s, count($ids)];
    }
}
if (!$todo) {
    api_ok(['count' => 0]);
}
$cfg = gemini_setlist_config() ?? fail(503, '曲の検出は使えません');
if (setlist_job_running_count() >= SETLIST_JOB_MAX) {
    fail(429, 'ほかに' . SETLIST_JOB_MAX . 'バンドを検出中です。終わってから試してください');
}
api_detach(['count' => count($todo)]);

$done = 0;
foreach ($todo as [$band, $mp3s, $videos]) {
    $bandId = (int)$band['band_id'];
    if (in_array(setlist_job_get($bandId)['state'] ?? 'error', ['running', 'done'], true)) {
        continue; // 待っている間に、バンドの画面から検出を始めた
    }
    setlist_job_put($bandId, ['state' => 'running', 'at' => $at = time(), 'flex' => true]);
    try {
        $job = setlist_detect_videos($bandId, $band, $mp3s, $videos, $cfg, true);
    } catch (Throwable $e) {
        error_log('setlist bulk: ' . $e->getMessage());
        $job = ['state' => 'error', 'error' => '解析に失敗しました'];
    }
    setlist_job_put($bandId, $job + ['at' => $at, 'flex' => true]);
    $done++;
}

if ($done) {
    $subs = $pdo->prepare('SELECT subscription_id, endpoint, p256dh, auth FROM push_subscription WHERE user_id = ?');
    $subs->execute([$user['user_id']]);
    push_send($subs->fetchAll(), [
        'title' => $liveName . 'の曲の検出が終わりました',
        'body' => $done . 'バンド',
        'url' => 'setlist_jobs', // 確認待ちが並ぶ
        'tag' => 'setlist-bulk-' . $liveId,
    ]);
}
