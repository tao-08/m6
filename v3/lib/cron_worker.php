<?php
/**
 * lib/cron_worker.php — 順番待ち（queued）の解析（曲の検出・チャプター）を動かす。cron が1分ごとに動かす
 *   cron: * * * * * php /…/v3/lib/cron_worker.php
 *   画面の PHP で Gemini を何十分も待つと、サーバーの同時に処理できる数（Entry Processes。上限10）がふさがって 508 で落ちた（2026-11）。
 *   cron のコマンドラインの PHP はその数に入らないので、待つのはここだけにする。API は順番待ちに積むだけ
 *     曲の検出: api_setlist_detect.php・api_setlist_bulk.php → setlist_job_run（lib/gemini_setlist.php）
 *     チャプター: api_audio_chapter.php・api_chapter_bulk.php → chapter_job_run（lib/chapter_job.php）
 *   同時に動くのは BG_WORKERS 本まで（worker-1〜N.lock の flock。空きが無ければすぐ終わる）。1本は順番待ちが無くなるまで古い順に続ける
 *   lib/ は .htaccess で外から開けない。念のため、コマンドライン以外では何もしない
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_NO_SESSION', true);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/chapter_job.php';
require_once __DIR__ . '/gemini_setlist.php';
@set_time_limit(0);
if (!is_dir(ytdlp_dir())) {
    @mkdir(ytdlp_dir(), 0700, true);
}

$slot = null; // 開いたままにしておく（このプロセスが終わるまで、その枠を持つ）
for ($i = 1; $i <= BG_WORKERS && $slot === null; $i++) {
    $fp = fopen(ytdlp_dir() . '/worker-' . $i . '.lock', 'c');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
        $slot = $fp;
    }
}
if ($slot === null) {
    exit; // もう BG_WORKERS 本が動いている
}

/** 一番古い順番待ちを1つ running にして返す。[種類, ID, 中身]。無ければ null。2本の worker が同じものを取らないよう claim.lock の中で */
function worker_claim(): ?array
{
    $lock = fopen(ytdlp_dir() . '/claim.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $best = null;
        foreach (array_merge(glob(ytdlp_dir() . '/detect-*.json') ?: [], glob(ytdlp_dir() . '/chapter-*.json') ?: []) as $file) {
            if (preg_match('/detect-(\d+)\.json$/', $file, $m)) {
                [$kind, $id, $job] = ['setlist', $m[1], setlist_job_get((int)$m[1])];
            } elseif (preg_match('/chapter-([A-Za-z0-9_-]+)\.json$/', $file, $m)) {
                [$kind, $id, $job] = ['chapter', $m[1], chapter_job_get($m[1])];
            } else {
                continue;
            }
            $q = (float)($job['q'] ?? $job['at'] ?? 0); // q = 積んだ順（まとめて解析は出演順）
            if (($job['state'] ?? '') === 'queued' && ($best === null || $q < $best[3])) {
                $best = [$kind, $id, $job, $q];
            }
        }
        if ($best === null) {
            return null;
        }
        [$kind, $id, $job] = $best;
        $job = ['state' => 'running', 'at' => time()] + $job;
        $kind === 'setlist' ? setlist_job_put((int)$id, $job) : chapter_job_put($id, $job);
        return [$kind, $id, $job];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

while (($next = worker_claim()) !== null) {
    [$kind, $id, $job] = $next;
    if ($kind === 'setlist') {
        setlist_job_run((int)$id, $job);
        continue;
    }
    $cfg = chapter_job_config();
    if ($cfg === null) {
        chapter_job_put($id, ['state' => 'error', 'band_id' => $job['band_id'] ?? 0, 'at' => time(), 'error' => '自動チャプターは未設定です']);
        continue;
    }
    chapter_job_run($id, (int)($job['band_id'] ?? 0), (int)$job['at'], $cfg);
}
