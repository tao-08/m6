<?php
/**
 * live_image.php?id=画像ID[&t=1][&dl=1] — ライブの添付画像を出す（ログインしている人だけ）
 *   t=1 … サムネイル / dl=1 … ダウンロード（保存するファイル名を付けて渡す）
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/live_image.php';
require_login();

$st = db()->prepare('SELECT i.*, l.name AS live_name FROM live_image i JOIN live l ON l.live_id = i.live_id WHERE i.image_id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$img = $st->fetch();
$name = $img ? (isset($_GET['t']) ? 't_' : '') . $img['file_name'] : '';
$path = LIVE_IMAGE_DIR . '/' . $name;
if (!$img || !preg_match('/^(t_)?[0-9a-f]{16}\.jpg$/', $name) || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400'); // 画像は差し替えず消すだけ（ファイル名が毎回変わる）ので長めに
if (isset($_GET['dl'])) {
    // ファイル名は「ライブ名_番号.jpg」。記号は _ にしてヘッダーを壊されないようにする
    $base = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $img['live_name']) . '_' . (int)$img['image_id'] . '.jpg';
    header("Content-Disposition: attachment; filename=\"image.jpg\"; filename*=UTF-8''" . rawurlencode($base));
}
readfile($path);
