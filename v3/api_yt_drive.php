<?php
/**
 * =====================================================================
 *  api_yt_drive.php — ドライブのフォルダの「動画ID.mp3」を、その動画を貼っているバンドに YouTube の区分でまとめて紐付ける API（JSON）。管理者だけ
 * =====================================================================
 *  setlist_jobs.php の「チャプター」タブのフォームが fetch() で呼ぶ（assets/app_admin.js の setupYtDrive）
 *  MP3 は scripts/takeout_to_mp3.ps1 で作り、「リンクを知っている全員」に共有したフォルダに置く
 *  リクエスト（POST, JSON）: {"url": "フォルダのリンク"}
 *  返す: {"count": 紐付けた数, "unmatched": [当たらなかったファイル名, ...]}
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
$folderId = drive_folder_id(is_array($body) ? (string)($body['url'] ?? '') : '') ?? fail(400, 'ドライブのフォルダのリンクを入れてください');
try {
    $files = drive_folder_tree($folderId);
} catch (RuntimeException $e) {
    fail(502, $e->getMessage());
}
$pdo = db();
$bands = $pdo->query('SELECT band_id, youtube_url FROM band WHERE youtube_url IS NOT NULL')->fetchAll();
$videos = array_map(static fn($urls) => array_values(array_filter(array_map('youtube_video_id', $urls))), band_video_urls($pdo, $bands));
$match = drive_yt_match($files, $videos);
$pdo->beginTransaction();
foreach ($match['bands'] as $bandId => $list) {
    drive_add_yt($pdo, $bandId, $list);
}
$pdo->commit();
api_ok(['count' => array_sum(array_map('count', $match['bands'])), 'unmatched' => $match['unmatched']]);
