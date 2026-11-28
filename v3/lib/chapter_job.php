<?php
/**
 * lib/chapter_job.php — 音源のチャプターを裏で自動で付ける（Gemini）。終わったらそのまま保存する（画面を閉じても続く）
 *   音源ごとに1ファイル（公開フォルダの外 .abbey-ytdlp/chapter-<ID>.json）。lib/gemini_setlist.php の setlist_job_* と同じ作り
 *     ID … YouTube は動画 ID、アップロードした MP3 は a-<band_audio の image_id>、ドライブの音源は d-<ファイル ID>（chapter_job_id）
 *     {state: queued|running|saved|edited|error, band_id, at: 状態が変わった時刻, error?: 理由}
 *   queued … 順番待ち（api_audio_chapter.php・api_chapter_bulk.php が積み、cron の lib/cron_worker.php が動かす）。running … 解析中。saved … 保存した（band_audio_chapter を置き換えた）
 *   edited … そのあと人が直した（api_audio_chapter.php。setlist_jobs.php で「編集済み」）
 *   「中止」（discard）で消す（api_audio_chapter.php）。前の作りの下書き（done）は、読んだときに保存する
 */
require_once __DIR__ . '/ytdlp.php';           // ytdlp_dir
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
        // 人が確認するまでシャッフル再生に流さない（band.php の「確認済みにする」で外す。migrations/160）
        $pdo->prepare('INSERT IGNORE INTO band_audio_unconfirmed (band_id, audio_key) VALUES (?, ?)')->execute([$bandId, $key]);
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

/** 順番待ちの解析（曲の検出＋チャプター）を同時に動かす本数（lib/cron_worker.php）。1本が数分〜30分、数百MB のメモリを使う */
const BG_WORKERS = 3;

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

/** 人がチャプターを直した印（edited）を付ける。解析中・順番待ちは、終わると置き換わるので付けない */
function chapter_job_edited(string $key, int $bandId): void
{
    $id = chapter_job_id($key);
    if (!in_array(chapter_job_get($id)['state'] ?? '', ['queued', 'running'], true)) {
        chapter_job_put($id, ['state' => 'edited', 'band_id' => $bandId, 'at' => time()]);
    }
}

/**
 * migrations/160 の続き: 160 より前に人が直した（edited）YouTube の動画を確認済みにする。1回だけ（済んだら印のファイルを置く）
 *   shortcut: setlist_jobs?tab=chapter を開いたときに走る。本番で一度開いたら、この関数と呼び出しは消してよい
 */
function chapter_unconfirmed_migrate(): void
{
    $mark = ytdlp_dir() . '/unconfirmed-160.done';
    if (is_file($mark)) {
        return;
    }
    $del = db()->prepare('DELETE FROM band_audio_unconfirmed WHERE band_id = ? AND audio_key = ?');
    foreach (glob(ytdlp_dir() . '/chapter-*.json') ?: [] as $file) {
        if (preg_match('/chapter-([A-Za-z0-9_-]+)\.json$/', $file, $m) && !preg_match('/^[ad]-/', $m[1])
            && ($job = chapter_job_get($m[1])) !== null && $job['state'] === 'edited') {
            $del->execute([(int)($job['band_id'] ?? 0), 'y:' . $m[1]]);
        }
    }
    @touch($mark);
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
 * バンドの音源（a:N = アップロードした MP3 / d:ファイルID = ドライブ）の手元のファイル。ドライブはまだ落としていなければここで落とす
 * @return array{0: string, 1: string}|string [パス, MIME]。無ければ画面に出せる理由
 */
function band_mix_file(int $bandId, string $key): array|string
{
    if (str_starts_with($key, 'a:')) {
        require_once __DIR__ . '/live_image.php';
        $st = db()->prepare('SELECT file_name FROM band_audio WHERE image_id = ? AND band_id = ?');
        $st->execute([(int)substr($key, 2), $bandId]);
        $name = $st->fetchColumn();
        $path = LIVE_IMAGE_DIR . '/' . live_file_disk_name((string)$name);
        return $name !== false && is_file($path) ? [$path, 'audio/mpeg'] : '音源が見つかりません';
    }
    require_once __DIR__ . '/drive.php';
    require_once __DIR__ . '/drive_cache.php';
    $st = db()->prepare("SELECT mime_type, size FROM band_drive_file WHERE band_id = ? AND file_id = ? AND mime_type LIKE 'audio/%'");
    $st->execute([$bandId, substr($key, 2)]);
    $row = $st->fetch();
    $size = (int)($row['size'] ?? 0);
    if (!$row || !drive_id_valid(substr($key, 2)) || drive_cache_ensure(substr($key, 2), $size)) {
        return '音源を読み込めませんでした';
    }
    $path = drive_cache_path(substr($key, 2));
    return [$path, audio_orig_size($path) > filesize($path) ? 'audio/mpeg' : (string)$row['mime_type']]; // 160 の MP3 に置き換え済み（lib/audio_stream.php）
}

/**
 * バンドの YouTube の動画の音声（区分が YouTube の MP3。migrations/153）の音源キー（a:N / d:ファイルID）。無ければ null
 *   動画と同じ長さの前提（始まりの秒をそのまま動画の秒として使う）
 */
function band_video_mp3(int $bandId, string $videoId): ?string
{
    $st = db()->prepare("SELECT CONCAT('a:', image_id) FROM band_audio WHERE band_id = ? AND video_id = ?
        UNION ALL SELECT CONCAT('d:', file_id) FROM band_drive_file WHERE band_id = ? AND video_id = ? AND mime_type LIKE 'audio/%' LIMIT 1");
    $st->execute([$bandId, $videoId, $bandId, $videoId]);
    $key = $st->fetchColumn();
    return $key === false ? null : (string)$key;
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
        // セトリの SE 名（あれば Gemini に SE も探させる）。shortcut: YouTube が2本以上でも動画ごとに絞らずバンドの SE 全部を渡す。取り違えが出たら video_no で絞る
        $st = db()->prepare('SELECT title FROM song WHERE band_id = ? AND is_se = 1 ORDER BY track_no');
        $st->execute([$bandId]);
        $ses = $st->fetchAll(PDO::FETCH_COLUMN);
        // YouTube は、その動画の MP3 を聴かせる（YouTube からは落とさない）
        $src = str_starts_with($key, 'y:') ? band_video_mp3($bandId, $id) : $key;
        $file = $src === null ? 'MP3 がありません' : band_mix_file($bandId, $src);
        $found = is_string($file) ? $file : gemini_audio_chapters($file[0], $file[1], $cfg[0], $cfg[1], $ses);
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
