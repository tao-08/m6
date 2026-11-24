<?php
/**
 * =====================================================================
 *  api_notice_read.php — ベルのポップアップで開いたタブの直近 NOTIF_POPUP_LIMIT 件を既読にする API（JSON を返す）
 * =====================================================================
 *  partials/header.php のベルを開いたとき・タブを切り替えたとき、assets/app.js が fetch() で呼ぶ。
 *  どのお知らせを既読にするかは JS から受け取らず、サーバーが決める（ポップアップと同じ条件）。
 *
 *  重要なお知らせはまとめて既読にしない。本文を開く・項目を押すと notice_id を付けて1件ずつ既読にする。
 *
 *  リクエスト（POST）:  kind=notif（通知のタブ）/ notice（お知らせのタブ）、または notice_id=1件だけ既読
 *  レスポンス（JSON）:  {"ok": true, "tab": そのタブの残りの未読数, "unread": 全体の残りの未読数}
 *  エラー           :  {"error": "..."}（401 / 405）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$pdo = db();
$uid = (int)$user['user_id'];
$mark = $pdo->prepare('INSERT IGNORE INTO notice_read (user_id, notice_id) VALUES (?, ?)');
$system = ($_POST['kind'] ?? '') !== 'notice'; // 開いているタブ（notif = 通知 / notice = お知らせ）
$one = (int)($_POST['notice_id'] ?? 0);
if ($one > 0) {
    // 重要なお知らせを1件開いた／押したとき（data-notice-read）。自分に見える公開済みのものだけ
    $pdo->prepare('INSERT IGNORE INTO notice_read (user_id, notice_id) SELECT ?, notice_id FROM notice
        WHERE notice_id = ? AND publish_at <= NOW() AND (target_user_id IS NULL OR target_user_id = ?)')->execute([$uid, $one, $uid]);
} else {
    foreach (notice_recent($uid, $system) as $n) {
        if (!$n['is_important']) { // 重要なものは開くまで未読のまま
            $mark->execute([$uid, (int)$n['notice_id']]);
        }
    }
}

$unreadNotif = notice_unread_count($uid, true);
$unreadNotice = notice_unread_count($uid, false);
echo json_encode(['ok' => true, 'tab' => $system ? $unreadNotif : $unreadNotice, 'unread' => $unreadNotif + $unreadNotice], JSON_UNESCAPED_UNICODE);
