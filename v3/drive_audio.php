<?php
/**
 * drive_audio.php?band=バンドID&f=ファイルID — バンドに紐付けたドライブの音源を、こちらのサーバー経由で流す
 *   ページ内の自前プレーヤー（lib/audio_player.php）の <audio src>。シーク（Range）に答える。
 *   &dl=1 はドライブのダウンロードへ飛ばす（元の音質。サーバーには再生用の 160 だけ置く。lib/audio_stream.php）。&from=バイト位置 を付けると、Range なしで（200 で）その位置から先を流す。WAV は作り直したヘッダー付き（lib/wav.php）
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
    $st = db()->prepare('SELECT mime_type, size, video_id FROM band_drive_file WHERE band_id = ? AND file_id = ?');
    $st->execute([$bandId, $fileId]);
    $row = $st->fetch();
    $mime = (string)($row['mime_type'] ?? '');
    $size = (int)($row['size'] ?? 0);
    $mix = $row && $row['video_id'] === null;
}
if (!str_starts_with($mime, 'audio/')) {
    http_response_code(404);
    exit;
}
if (isset($_GET['dl'])) { // 保存は元の音質のドライブから（サーバーには再生用の 160 だけ置く）
    header('Location: https://drive.google.com/uc?export=download&id=' . rawurlencode($fileId));
    exit;
}
if (isset($_GET['state']) && is_admin()) { // 管理者だけ: いまドライブから落としているか、変換しているか（player.js）
    header('Content-Type: application/json');
    echo json_encode(audio_prep_state(drive_cache_path($fileId), $size));
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
if ($mix && is_file(audio_mark($dat)) && !is_file(audio_quiet_mark($dat))) { // 前に 160k で作った MIX: 160 から作ると高音が戻らないので、元（256k）を落とし直して作り直す
    foreach ([$dat, ...drive_cache_derived($dat)] as $f) {
        @unlink($f);
    }
}
if ($err = drive_cache_ensure($fileId, $size, $miss)) {
    if ($err[2] === 503) {
        header('Retry-After: 5');
    }
    $fail($err[0], $err[1], $err[2]);
}
header('X-Cache: ' . ($miss ? 'MISS' : 'HIT'));
audio_stream_file($dat, $mime, null, $mix); // MIX は音量を下げる（lib/audio_stream.php）
