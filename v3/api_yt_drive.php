<?php
/**
 * =====================================================================
 *  api_yt_drive.php — ドライブのフォルダの「動画ID.mp3」を、その動画を貼っているバンドに YouTube の区分でまとめて紐付ける API（JSON）。管理者だけ
 * =====================================================================
 *  setlist_jobs.php の「チャプター」タブのフォームが fetch() で呼ぶ（assets/app_admin.js の setupYtDrive）
 *  MP3 は scripts/takeout_to_mp3.ps1 で作り、「リンクを知っている全員」に共有したフォルダに置く
 *  リクエスト（POST, JSON）: {"url": "フォルダのリンク", "old": "編集前のフォルダ ID（編集のときだけ）"}
 *    old があれば、古いフォルダを解除してから新しいフォルダを紐付ける（URL の編集）
 *  返す: {"count": 紐付けた数, "folder": フォルダ ID, "unmatched": [{"name": ファイル名, "video_id": 動画 ID か null, "title": 動画のタイトルか null}, ...]}
 *    unmatched はバンドにもスペシャルコンテンツにも無い動画だけ。タイトルは youtube_title（無ければ oEmbed で取って保存）
 *  紐付けの解除: {"remove": "フォルダ ID"} → {"count": 外したファイルの数}（drive_remove_yt_folder）
 *  紐付けたフォルダは yt_drive_folder に残り、setlist_jobs.php のチャプタータブの下に並ぶ（migrations/158）
 *  もう紐付けてあるファイルは区分だけ直すので、何回押してもよい（バンドに YouTube を貼ってから押し直せば入る）
 *  エラー: {"error": "..."}（400 / 401 / 403 / 405 / 502）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/drive.php';

header('Content-Type: application/json; charset=utf-8');
api_user('is_admin', '管理者のみ使えます');

$body = api_body();
$body = is_array($body) ? $body : [];
$pdo = db();
if (isset($body['remove'])) {
    $remove = (string)$body['remove'];
    drive_id_valid($remove) || fail(400, 'フォルダが正しくありません');
    $pdo->beginTransaction();
    $n = drive_remove_yt_folder($pdo, $remove);
    $pdo->commit();
    api_ok(['count' => $n]);
}
$folderId = drive_folder_id((string)($body['url'] ?? '')) ?? fail(400, 'ドライブのフォルダのリンクを入れてください');
$old = (string)($body['old'] ?? '');
if ($old !== '' && !drive_id_valid($old)) {
    fail(400, 'フォルダが正しくありません');
}
try {
    $files = drive_folder_tree($folderId);
} catch (RuntimeException $e) {
    fail(502, $e->getMessage());
}
if (!$files) { // 共有していないフォルダは、エラーではなく空で返ってくる
    fail(400, 'フォルダの中を読めません。「リンクを知っている全員」に共有してください');
}
$bands = $pdo->query('SELECT band_id, youtube_url FROM band WHERE youtube_url IS NOT NULL')->fetchAll();
$videos = array_map(static fn($urls) => array_values(array_filter(array_map('youtube_video_id', $urls))), band_video_urls($pdo, $bands));
$match = drive_yt_match($files, $videos);
$pdo->beginTransaction();
if ($old !== '' && $old !== $folderId) { // 新しいフォルダを読めてから外す（読めなければ古いまま残る）
    drive_remove_yt_folder($pdo, $old);
}
$pdo->prepare('INSERT IGNORE INTO yt_drive_folder (folder_id) VALUES (?)')->execute([$folderId]);
foreach ($match['bands'] as $bandId => $list) {
    drive_add_yt($pdo, $bandId, $list, $folderId);
}
$pdo->commit();
$special = []; // スペシャルコンテンツの動画 ID => true
foreach ($pdo->query('SELECT url FROM special_content WHERE url IS NOT NULL') as $r) {
    if (($v = youtube_video_id((string)$r['url'])) !== null) {
        $special[$v] = true;
    }
}
$miss = [];
foreach ($match['unmatched'] as $name) {
    $vid = preg_match('/^([A-Za-z0-9_-]{11})\.mp3$/i', $name, $m) ? $m[1] : null;
    if ($vid === null || !isset($special[$vid])) {
        $miss[] = ['name' => $name, 'video_id' => $vid];
    }
}
$urls = array_map(static fn($m) => youtube_watch_url($m['video_id']), array_filter($miss, static fn($m) => $m['video_id'] !== null));
@set_time_limit(0); // タイトルを初めて取るときは1本ずつ oEmbed に聞く
youtube_titles_fetch($pdo, $urls);
$titles = youtube_titles($pdo, $urls);
$miss = array_map(static fn($m) => $m + ['title' => $titles[$m['video_id']] ?? null], $miss);
api_ok(['count' => array_sum(array_map('count', $match['bands'])), 'folder' => $folderId, 'unmatched' => $miss]);
