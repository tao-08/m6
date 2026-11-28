<?php
/**
 * =====================================================================
 *  api_chapter_bulk.php — ライブの未解析の YouTube に、裏でまとめてチャプターを付ける API（JSON）。管理者だけ
 * =====================================================================
 *  setlist_jobs.php の「チャプター」タブの「未解析を解析」が fetch() で呼ぶ。
 *  リクエスト（POST, JSON）: {"live_id": 3}
 *  未解析 = チャプターが1つも無く、解析中・順番待ちでもない動画（失敗したものはやり直す）。その動画の MP3（YouTube の区分）がある動画だけ
 *  全部を順番待ち（queued）にして、すぐ {"count": 本数} を返し、裏で出演順に1本ずつ解析する（lib/chapter_job.php）。
 *  終わったものから保存する
 *  エラー: {"error": "..."}（400 / 401 / 403 / 404 / 405 / 429 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/youtube.php';
require_once __DIR__ . '/lib/chapter_job.php';

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', '管理者のみ使えます');

$body = api_body();
$liveId = is_array($body) && is_int($body['live_id'] ?? null) ? $body['live_id'] : 0;
$pdo = db();
$st = $pdo->prepare('SELECT b.band_id, b.youtube_url FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order, b.band_id'); // 出演順（live.php と同じ）
$st->execute([$liveId]);
$bands = $st->fetchAll();
if (!$bands) {
    fail(404, 'ライブが見つかりません');
}
$done = []; // "band_id|動画ID" => true（チャプターがある）
$st = $pdo->prepare("SELECT DISTINCT c.band_id, c.audio_key FROM band_audio_chapter c JOIN band b ON b.band_id = c.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id WHERE d.live_id = ? AND c.audio_key LIKE 'y:%'");
$st->execute([$liveId]);
foreach ($st as $r) {
    $done[$r['band_id'] . '|' . substr((string)$r['audio_key'], 2)] = true;
}
$todo = []; // [[動画ID, band_id], ...]
foreach (band_video_urls($pdo, $bands) as $bandId => $urls) {
    foreach ($urls as $url) {
        $vid = youtube_video_id($url);
        if ($vid !== null && !isset($done[$bandId . '|' . $vid]) && in_array(chapter_job_get($vid)['state'] ?? 'error', ['error', 'edited'], true) && band_video_mp3($bandId, $vid) !== null) { // MP3 の無い動画は聴かせられない
            $todo[$vid] = [$vid, $bandId]; // 同じ動画を2つのバンドが持つときは1回だけ
        }
    }
}
if (!$todo) {
    api_ok(['count' => 0]);
}
$cfg = chapter_job_config() ?? fail(503, '自動チャプターは未設定です');
if (chapter_job_running_count() >= CHAPTER_JOB_MAX) {
    fail(429, 'ほかの動画を解析中です。終わってから試してください');
}
foreach ($todo as [$vid, $bandId]) {
    chapter_job_put($vid, ['state' => 'queued', 'band_id' => $bandId, 'at' => time()]);
}
api_detach(['count' => count($todo)]);

foreach ($todo as [$vid, $bandId]) {
    if ((chapter_job_get($vid)['state'] ?? '') !== 'queued') {
        continue; // 待っている間に「中止」・1本ずつの解析が始まった
    }
    chapter_job_put($vid, ['state' => 'running', 'band_id' => $bandId, 'at' => $at = time()]);
    chapter_job_run($vid, $bandId, $at, $cfg);
}
