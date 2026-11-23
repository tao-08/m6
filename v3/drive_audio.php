<?php
/**
 * drive_audio.php?band=バンドID&f=ファイルID — バンドに紐付けたドライブの音源を、こちらのサーバー経由で流す
 *   ページ内の自前プレーヤー（lib/audio_player.php）の <audio src>。シーク（Range）に答える。
 *   &dl=1 を付けると保存用（attachment）で流す。&from=バイト位置 を付けると、Range なしで（200 で）その位置から先を流す。WAV は作り直したヘッダー付き（lib/wav.php）
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
    $st = db()->prepare('SELECT mime_type, size, name, title FROM band_drive_file WHERE band_id = ? AND file_id = ?');
    $st->execute([$bandId, $fileId]);
    $row = $st->fetch();
    $mime = (string)($row['mime_type'] ?? '');
    $size = (int)($row['size'] ?? 0);
    $dlName = $row ? drive_file_title($row) . '.' . (preg_match('/\.([A-Za-z0-9]{1,5})$/', (string)$row['name'], $m) ? $m[1] : 'mp3') : '';
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

$miss = false;
$fail = static function (int|string $code, string $reason, int $http = 502): never {
    http_response_code($http);
    header('X-Upstream-Status: ' . $code);
    header('X-Upstream-Reason: ' . $reason);
    exit;
};

$dat = drive_cache_path($fileId);
if ($err = drive_cache_ensure($fileId, $size, $miss)) {
    if ($err[2] === 503) {
        header('Retry-After: 5');
    }
    $fail($err[0], $err[1], $err[2]);
}
header('X-Cache: ' . ($miss ? 'MISS' : 'HIT'));
audio_stream_file($dat, $mime, isset($_GET['dl']) ? $dlName : null);
