<?php
/**
 * drive_audio.php?band=バンドID&f=ファイルID — バンドに紐付けたドライブの音源を、こちらのサーバー経由で流す
 *   ページ内の自前プレーヤー（lib/audio_player.php）の <audio src>。シーク（Range）に答える。
 *   &from=バイト位置 を付けると、Range なしで（200 で）その位置から先を流す。WAV は作り直したヘッダー付き（lib/wav.php）
 *   ドライブから落とすのは1ファイルにつき1回だけ。storage/drive_cache/ に保存して、そこから流す（lib/drive_cache.php）
 *   ログインしている人だけ。band_drive_file に紐付いている音源（audio/*）だけ流す（任意のドライブの ID は中継しない）
 *   API キーは drive_api_key()。「リンクを知っている全員」に共有されたファイルだけ読める
 *   ドライブが答えなかったら 502 に X-Upstream-Status / X-Upstream-Reason を付ける（原因を調べるため。鍵・URL は載せない）
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/audio_stream.php';
require_login();

$bandId = (int)($_GET['band'] ?? 0);
$fileId = (string)($_GET['f'] ?? '');
$mime = '';
$size = 0;
if ($bandId > 0 && drive_id_valid($fileId)) {
    $st = db()->prepare('SELECT mime_type, size FROM band_drive_file WHERE band_id = ? AND file_id = ?');
    $st->execute([$bandId, $fileId]);
    $row = $st->fetch();
    $mime = (string)($row['mime_type'] ?? '');
    $size = (int)($row['size'] ?? 0);
}
if (!str_starts_with($mime, 'audio/')) {
    http_response_code(404);
    exit;
}
// 長く待つので、先にセッションを手放す（ほかの画面が待たされない）。時間制限も外す
session_write_close();
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$fail = static function (int|string $code, string $reason, int $http = 502): never {
    http_response_code($http);
    header('X-Upstream-Status: ' . $code);
    header('X-Upstream-Reason: ' . $reason);
    exit;
};

$dat = drive_cache_path($fileId);
$miss = false;
$lock = null;
$deadline = time() + 90;
// shortcut: $size は最初に読んだ DB の値。待っている間に DB の大きさが直されても、この回は古いままで比べる（次の回から合う）
while (!drive_cache_ok($dat, $size)) {
    $lock ??= @fopen(drive_cache_dir() . '/' . $fileId . '.lock', 'c');
    if (!$lock) {
        $fail('fs', 'cannot create cache');
    }
    if (flock($lock, LOCK_EX | LOCK_NB)) { // 落とすのは1つだけ。ほかの同時のリクエストは、下で終わるのを待つ
        clearstatcache();
        if (!drive_cache_ok($dat, $size)) {
            $part = $dat . '.part'; // 最後まで落とせて大きさが合ってから .dat にする
            if ($err = drive_download($fileId, $part, $size)) {
                @unlink($part);
                $fail($err[0], $err[1]);
            }
            $got = (int)filesize($part);
            rename($part, $dat);
            if ($got !== $size) { // DB の大きさが違う（ドライブ側で差し替わった）。直さないと毎回落とし直しになる
                db()->prepare('UPDATE band_drive_file SET size = ? WHERE file_id = ?')->execute([$got, $fileId]);
            }
            drive_cache_trim($dat);
            $miss = true;
        }
        flock($lock, LOCK_UN);
        break;
    }
    if (time() >= $deadline) {
        header('Retry-After: 5');
        $fail('busy', 'download in progress', 503);
    }
    usleep(500000);
    clearstatcache();
}
@touch($dat); // 使った印（上限を超えたとき、使われていない順に消す）
header('X-Cache: ' . ($miss ? 'MISS' : 'HIT'));
audio_stream_file($dat, $mime);
