<?php
/**
 * drive_image.php?band=バンドID&f=ファイルID[&dl=1] — バンドに紐付けたドライブの写真・PDF を、こちらのサーバー経由で流す
 *   ページ内のモーダル（attach_modal_html）の <img src>。ドライブへ飛ばさずに表示する。
 *   drive_audio.php と同じ作り: ログインしている人だけ・band_drive_file に紐付いた写真（image/*）と PDF だけ・storage/drive_cache/ に1回だけ落とす
 *   SVG は流さない（同じオリジンで script が動くため）
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
$dlName = '';
if ($bandId > 0 && drive_id_valid($fileId)) {
    $st = db()->prepare('SELECT mime_type, size, name, title FROM band_drive_file WHERE band_id = ? AND file_id = ?');
    $st->execute([$bandId, $fileId]);
    $row = $st->fetch();
    $mime = (string)($row['mime_type'] ?? '');
    $size = (int)($row['size'] ?? 0);
    $dlName = $row ? drive_file_title($row) . '.' . (preg_match('/\.([A-Za-z0-9]{1,5})$/', (string)$row['name'], $m) ? $m[1] : 'bin') : '';
}
if (!(str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') && $mime !== 'application/pdf') {
    http_response_code(404);
    exit;
}
if ($mime === 'application/pdf') { // モーダルの iframe に入れるので、このサイト内の枠だけ許す
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}
session_write_close(); // 落としている間、ほかの画面が待たされないように

$miss = false;
if ($err = drive_cache_ensure($fileId, $size, $miss)) {
    http_response_code($err[2]);
    exit;
}
audio_stream_file(drive_cache_path($fileId), $mime, isset($_GET['dl']) ? $dlName : null);
