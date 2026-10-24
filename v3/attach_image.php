<?php
/**
 * attach_image.php?k=board|notice|band|venue&id=画像ID[&t=1][&dl=1] — 掲示板・お知らせ・バンドの添付画像を出す
 *   ログインしている人のうち、その書き込み・お知らせ・バンドを見てよい人だけ。t=1 サムネイル / dl=1 ダウンロード
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/board.php';
require_once __DIR__ . '/lib/attach_image.php';
$user = require_login();
$pdo = db();

$kind = (string)($_GET['k'] ?? '');
$img = null;
if (isset(ATTACH_KINDS[$kind])) {
    $id = (int)($_GET['id'] ?? 0);
    if ($kind === 'board') {
        $st = $pdo->prepare('SELECT i.*, p.deleted_at AS post_deleted, t.* FROM board_post_image i
            JOIN board_post p ON p.post_id = i.post_id JOIN board_thread t ON t.thread_id = p.thread_id WHERE i.image_id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        // 見てよいスレで、削除されていない書き込みの画像だけ（管理者は削除済みも）
        if ($row && board_can_view($user, $row) && ($row['post_deleted'] === null || $user['admin'])) {
            $img = $row + ['title' => $row['title']];
        }
    } elseif ($kind === 'notice') {
        $st = $pdo->prepare('SELECT i.*, n.title FROM notice_image i JOIN notice n ON n.notice_id = i.notice_id
            WHERE i.image_id = ? AND (n.publish_at <= NOW() OR ? = 1) AND (n.target_user_id IS NULL OR n.target_user_id = ?)');
        $st->execute([$id, $user['admin'] ? 1 : 0, $user['user_id']]);
        $img = $st->fetch() ?: null;
    } elseif ($kind === 'song_tab' || $kind === 'song_meta') {
        // TAB 譜の画像: TAB 譜を使ってよい人（今は管理人）だけ。lib/tabs.php の tab_allowed()
        require_once __DIR__ . '/lib/tabs.php';
        if (tab_allowed()) {
            $st = $kind === 'song_tab'
                ? $pdo->prepare('SELECT i.*, \'TAB\' AS title FROM song_tab_image i WHERE i.image_id = ?')
                : $pdo->prepare('SELECT i.*, \'song\' AS title FROM song_meta_image i WHERE i.image_id = ?');
            $st->execute([$id]);
            $img = $st->fetch() ?: null;
        }
    } elseif ($kind === 'venue') {
        $st = $pdo->prepare('SELECT i.*, v.name AS title FROM venue_image i JOIN venue v ON v.venue_id = i.venue_id WHERE i.image_id = ?');
        $st->execute([$id]);
        $img = $st->fetch() ?: null;
    } else {
        $st = $pdo->prepare('SELECT i.*, b.name AS title FROM band_image i JOIN band b ON b.band_id = i.band_id WHERE i.image_id = ?');
        $st->execute([$id]);
        $img = $st->fetch() ?: null;
    }
}
$name = $img ? (isset($_GET['t']) ? 't_' : '') . $img['file_name'] : '';
$path = LIVE_IMAGE_DIR . '/' . $name;
if (!$img || !preg_match('/^(t_)?[0-9a-f]{16}\.jpg$/', $name) || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
if (isset($_GET['dl'])) {
    $base = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string)$img['title']) . '_' . (int)$img['image_id'] . '.jpg';
    header("Content-Disposition: attachment; filename=\"image.jpg\"; filename*=UTF-8''" . rawurlencode($base));
}
readfile($path);
