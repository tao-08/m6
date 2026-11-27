<?php
/**
 * lib/chapter_job.php — 音源のチャプターを裏で自動で付ける（Gemini）。終わったらそのまま保存する（画面を閉じても続く）
 *   音源ごとに1ファイル（公開フォルダの外 .abbey-ytdlp/chapter-<ID>.json）。lib/gemini_setlist.php の setlist_job_* と同じ作り
 *     ID … YouTube は動画 ID、アップロードした MP3 は a-<band_audio の image_id>、ドライブの音源は d-<ファイル ID>（chapter_job_id）
 *     {state: queued|running|saved|error, band_id, at: 状態が変わった時刻, error?: 理由}
 *   queued … まとめて解析（api_chapter_bulk.php）の順番待ち。running … 解析中。saved … 保存した（band_audio_chapter を置き換えた）
 *   「中止」（discard）で消す（api_audio_chapter.php）。前の作りの下書き（done）は、読んだときに保存する
 */
require_once __DIR__ . '/ytdlp.php';           // ytdlp_dir・ytdlp_audio
require_once __DIR__ . '/gemini_chapters.php'; // gemini_audio_chapters

/** 音源（audio_key: y:動画ID / a:N / d:ファイルID）の解析の ID。ファイル名に使える文字だけ */
function chapter_job_id(string $key): string
{
    return str_starts_with($key, 'y:') ? substr($key, 2) : str_replace(':', '-', $key);
}

function chapter_job_key(string $id): string
{
    return preg_match('/^[ad]-/', $id) ? substr($id, 0, 1) . ':' . substr($id, 2) : 'y:' . $id;
}

/** チャプターを置き換える（api_audio_chapter.php の replace と同じ。1つのトランザクションで） */
function chapter_job_save(int $bandId, string $key, array $found): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM band_audio_chapter WHERE band_id = ? AND audio_key = ?')->execute([$bandId, $key]);
        $ins = $pdo->prepare('INSERT INTO band_audio_chapter (band_id, audio_key, kind, at_ms) VALUES (?, ?, ?, ?)');
        foreach ($found as $c) {
            $ins->execute([$bandId, $key, $c['kind'], $c['ms']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 裏で待つ上限。これを過ぎても running / queued のままなら、裏の処理が止まったとみなす */
const CHAPTER_JOB_STALE = 1800;
const CHAPTER_QUEUE_STALE = 21600; // まとめて解析の順番待ち（1本数分 × 本数）

function chapter_job_path(string $id): string
{
    return ytdlp_dir() . '/chapter-' . $id . '.json';
}

/** 途中経過。無ければ null。止まったものは error にして返す */
function chapter_job_get(string $id): ?array
{
    $job = json_decode((string)@file_get_contents(chapter_job_path($id)), true);
    if (!is_array($job)) {
        return null;
    }
    if (($job['state'] ?? '') === 'done' && is_array($job['draft'] ?? null)) { // 前の作りの下書き: 保存する
        chapter_job_save((int)($job['band_id'] ?? 0), chapter_job_key($id), $job['draft']);
        chapter_job_put($id, $job = ['state' => 'saved', 'band_id' => $job['band_id'] ?? 0, 'at' => time()]);
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
        if (preg_match('/chapter-([A-Za-z0-9_-]+)\.json$/', $file, $m) && (chapter_job_get($m[1])['state'] ?? '') === 'running') {
            $n++;
        }
    }
    return $n;
}

/** 同時に裏で解析する数の上限（サーバーの同時に処理できる数をふさがないように。setlist の SETLIST_JOB_MAX と同じ考え） */
const CHAPTER_JOB_MAX = 2;

function chapter_job_put(string $id, ?array $job): void
{
    if ($job === null) {
        @unlink(chapter_job_path($id));
        return;
    }
    if (!is_dir(ytdlp_dir())) {
        @mkdir(ytdlp_dir(), 0700, true);
    }
    file_put_contents(chapter_job_path($id), json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX);
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
 * 1本を解析して、見つけた区切りで保存する。途中で「中止」で消されていたら（running でなくなったら）書かない
 *   呼ぶ前に chapter_job_put で running にしておく（$at はそのときの時刻）
 */
function chapter_job_run(string $id, int $bandId, int $at, array $cfg): void
{
    $key = chapter_job_key($id);
    $found = '解析に失敗しました';
    try {
        if (str_starts_with($key, 'y:')) {
            $audio = ytdlp_audio($id, $why);
            if ($audio === null) {
                $found = 'YouTube から音声を取得できませんでした';
            } else {
                try {
                    $found = gemini_audio_chapters($audio, 'audio/aac', $cfg[0], $cfg[1]); // m4a（AAC）をそのまま聴かせる
                } finally {
                    @unlink($audio);
                }
            }
        } elseif (str_starts_with($key, 'a:')) { // アップロードした MP3
            require_once __DIR__ . '/live_image.php';
            $st = db()->prepare('SELECT file_name FROM band_audio WHERE image_id = ? AND band_id = ?');
            $st->execute([(int)substr($key, 2), $bandId]);
            $path = LIVE_IMAGE_DIR . '/' . live_file_disk_name((string)$st->fetchColumn());
            $found = is_file($path) ? gemini_audio_chapters($path, 'audio/mpeg', $cfg[0], $cfg[1]) : '音源が見つかりません';
        } else { // ドライブの音源（まだ落としていなければここで落とす）
            require_once __DIR__ . '/drive.php';
            require_once __DIR__ . '/drive_cache.php';
            $st = db()->prepare('SELECT mime_type, size FROM band_drive_file WHERE band_id = ? AND file_id = ?');
            $st->execute([$bandId, substr($key, 2)]);
            $row = $st->fetch();
            $size = (int)($row['size'] ?? 0);
            if (!$row || drive_cache_ensure(substr($key, 2), $size)) {
                $found = '音源を読み込めませんでした';
            } else {
                $path = drive_cache_path(substr($key, 2));
                $mime = audio_orig_size($path) > filesize($path) ? 'audio/mpeg' : (string)$row['mime_type']; // 160 の MP3 に置き換え済み（lib/audio_stream.php）
                $found = gemini_audio_chapters($path, $mime, $cfg[0], $cfg[1]);
            }
        }
    } catch (Throwable $e) {
        error_log('chapter job: ' . $e->getMessage());
    }
    $now = chapter_job_get($id);
    if (($now['state'] ?? '') !== 'running' || (int)($now['at'] ?? 0) !== $at) {
        return;
    }
    if (is_array($found) && $found) {
        try {
            chapter_job_save($bandId, $key, $found);
            $job = ['state' => 'saved'];
        } catch (Throwable $e) {
            error_log('chapter job save: ' . $e->getMessage());
            $job = ['state' => 'error', 'error' => '保存できませんでした'];
        }
    } else {
        $job = ['state' => 'error', 'error' => is_string($found) ? $found : '区切りを見つけられませんでした'];
    }
    chapter_job_put($id, $job + ['band_id' => $bandId, 'at' => time()]);
}
