<?php
/**
 * =====================================================================
 *  api_notice_read.php — ベルのポップアップで開いたタブの直近 NOTIF_POPUP_LIMIT 件を既読にする API（JSON を返す）
 * =====================================================================
 *  partials/header.php のベルを開いたとき・タブを切り替えたとき、assets/app.js が fetch() で呼ぶ。
 *  どのお知らせを既読にするかは JS から受け取らず、サーバーが決める（ポップアップと同じ条件）。
 *
 *  リクエスト（POST）:  kind=notif（通知のタブ）/ notice（お知らせのタブ）
 *  レスポンス（JSON）:  {"ok": true, "tab": そのタブの残りの未読数, "unread": 全体の残りの未読数}
 *  エラー           :  {"error": "..."}（401 / 405）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/** エラーの JSON を返して終わる */
function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if ($user === null) {
    fail(401, 'ログインしてください');
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$pdo = db();
$uid = (int)$user['user_id'];
$mark = $pdo->prepare('INSERT IGNORE INTO notice_read (user_id, notice_id) VALUES (?, ?)');
$system = ($_POST['kind'] ?? '') !== 'notice'; // 開いているタブ（notif = 通知 / notice = お知らせ）
foreach (notice_recent($uid, $system) as $n) {
    $mark->execute([$uid, (int)$n['notice_id']]);
}

echo json_encode(['ok' => true, 'tab' => notice_unread_count($uid, $system), 'unread' => notice_unread_count($uid, true) + notice_unread_count($uid, false)], JSON_UNESCAPED_UNICODE);
