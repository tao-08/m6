<?php
/**
 * =====================================================================
 *  manifest_link.php?id=リンクID — YouTube の練習プレイヤー（tab?link=）用のアプリ設定（Web App Manifest）
 * =====================================================================
 *  練習プレイヤーのページから <link rel="manifest" crossorigin="use-credentials"> で読ませる。
 *  ホーム画面に追加すると、このアイコンから練習プレイヤーが直接アプリとして開く（id / start_url がリンクごとに違うので、動画ごとに別のアプリ）。
 *  ログインしていない・管理人でない・リンクが無いときは 404（曲名を外に出さない）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tabs.php';

$base = json_decode((string)file_get_contents(__DIR__ . '/manifest.json'), true);
$title = null;
if (current_user() !== null && tab_allowed()) {
    try {
        $st = db()->prepare('SELECT label, title FROM song_link WHERE link_id = ? AND site = \'youtube\'');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $row = $st->fetch();
        $title = $row ? trim((string)($row['label'] ?: $row['title'])) : null;
    } catch (PDOException) {
        $title = null;
    }
}
if (!is_array($base) || $title === null) {
    http_response_code(404);
    exit;
}

$start = 'tab?link=' . (int)$_GET['id'];
$name = mb_substr($title, 0, 45);
$manifest = array_merge($base, [
    'name'       => $name,
    'short_name' => mb_substr($name, 0, 12),
    'id'         => $start,
    'start_url'  => $start,
]);
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, no-store');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
