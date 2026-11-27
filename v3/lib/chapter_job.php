<?php
/**
 * lib/chapter_job.php — YouTube のチャプターを裏で自動で付ける（Gemini。下書きを作るだけ）
 *   動画ごとに1ファイル（公開フォルダの外 .abbey-ytdlp/chapter-動画ID.json）。lib/gemini_setlist.php の setlist_job_* と同じ作り
 *     {state: queued|running|done|error, band_id, at: 状態が変わった時刻, draft: [{kind, ms}]|error: 理由}
 *   queued … まとめて解析（api_chapter_bulk.php）の順番待ち。running … 解析中。done … 下書きあり（チャプター編集を開くと出る）
 *   下書きは「保存」（replace）か「やめる」（discard）で消える（api_audio_chapter.php）
 */
require_once __DIR__ . '/ytdlp.php';           // ytdlp_dir・ytdlp_audio
require_once __DIR__ . '/gemini_chapters.php'; // gemini_audio_chapters

/** 裏で待つ上限。これを過ぎても running / queued のままなら、裏の処理が止まったとみなす */
const CHAPTER_JOB_STALE = 1800;
const CHAPTER_QUEUE_STALE = 21600; // まとめて解析の順番待ち（1本数分 × 本数）

function chapter_job_path(string $videoId): string
{
    return ytdlp_dir() . '/chapter-' . $videoId . '.json';
}

/** 途中経過。無ければ null。止まったものは error にして返す */
function chapter_job_get(string $videoId): ?array
{
    $job = json_decode((string)@file_get_contents(chapter_job_path($videoId)), true);
    if (!is_array($job)) {
        return null;
    }
    $limit = ['running' => CHAPTER_JOB_STALE, 'queued' => CHAPTER_QUEUE_STALE][$job['state'] ?? ''] ?? null;
    if ($limit !== null && time() - (int)($job['at'] ?? 0) > $limit) {
        return ['state' => 'error', 'band_id' => $job['band_id'] ?? 0, 'at' => $job['at'] ?? 0, 'error' => '解析が止まりました。もう一度試してください'];
    }
    return $job;
}

/** 今、裏で解析している数（1つの裏の処理が同時に running にするのは1本だけなので、裏の処理の数と同じ） */
function chapter_job_running_count(): int
{
    $n = 0;
    foreach (glob(ytdlp_dir() . '/chapter-*.json') ?: [] as $file) {
        if (preg_match('/chapter-([A-Za-z0-9_-]{11})\.json$/', $file, $m) && (chapter_job_get($m[1])['state'] ?? '') === 'running') {
            $n++;
        }
    }
    return $n;
}

/** 同時に裏で解析する数の上限（サーバーの同時に処理できる数をふさがないように。setlist の SETLIST_JOB_MAX と同じ考え） */
const CHAPTER_JOB_MAX = 2;

function chapter_job_put(string $videoId, ?array $job): void
{
    if ($job === null) {
        @unlink(chapter_job_path($videoId));
        return;
    }
    if (!is_dir(ytdlp_dir())) {
        @mkdir(ytdlp_dir(), 0700, true);
    }
    file_put_contents(chapter_job_path($videoId), json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Gemini の設定（自動チャプター）。無ければ null */
function chapter_job_config(): ?array
{
    $gemini = (array)(config('gemini') ?? []);
    $key = trim((string)($gemini['api_key'] ?? ''));
    $model = trim((string)($gemini['chapter_model'] ?? $gemini['lyrics_search_model'] ?? 'gemini-3.8-flash'));
    return $key !== '' && preg_match('/^gemini-[a-zA-Z0-9.-]+$/', $model) ? [$key, $model] : null;
}

/**
 * 1本を解析して、結果を書く。途中で「中止」「やめる」で消されていたら（running でなくなったら）書かない
 *   呼ぶ前に chapter_job_put で running にしておく（$at はそのときの時刻）
 */
function chapter_job_run(string $videoId, int $bandId, int $at, array $cfg): void
{
    try {
        $audio = ytdlp_audio($videoId, $why);
        if ($audio === null) {
            $job = ['state' => 'error', 'error' => 'YouTube から音声を取得できませんでした'];
        } else {
            try {
                $found = gemini_audio_chapters($audio, 'audio/aac', $cfg[0], $cfg[1]); // m4a（AAC）をそのまま聴かせる
            } finally {
                @unlink($audio);
            }
            $job = is_string($found) ? ['state' => 'error', 'error' => $found]
                : ($found ? ['state' => 'done', 'draft' => $found] : ['state' => 'error', 'error' => '区切りを見つけられませんでした']);
        }
    } catch (Throwable $e) {
        error_log('chapter job: ' . $e->getMessage());
        $job = ['state' => 'error', 'error' => '解析に失敗しました'];
    }
    $now = chapter_job_get($videoId);
    if (($now['state'] ?? '') === 'running' && (int)($now['at'] ?? 0) === $at) {
        chapter_job_put($videoId, $job + ['band_id' => $bandId, 'at' => time()]);
    }
}
