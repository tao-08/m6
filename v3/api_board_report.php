<?php
/**
 * =====================================================================
 *  api_board_report.php — 掲示板の書き込みを通報する / 通報を取り消す API（JSON を返す）
 * =====================================================================
 *  リクエスト（POST, JSON）:  通報  {"post_id": 12, "reason": 2}（reason は lib/board.php の BOARD_REPORT_REASONS のキー）
 *                              取消  {"post_id": 12, "cancel": true}
 *  レスポンス（JSON）      :  {"count": 2, "mine": true}
 *  エラー                 :  {"error": "..."}（400 / 401 / 403 / 404 / 405）
 *
 *  見られないスレ・削除済みの書き込みは 404（あること自体を隠す）。自分の書き込みは通報できない（403）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/board.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user();
$body = api_body();
$postId = is_int($body['post_id'] ?? null) ? $body['post_id'] : 0;
$cancel = ($body['cancel'] ?? false) === true;
$reason = is_int($body['reason'] ?? null) ? $body['reason'] : 0;
if ($postId <= 0 || (!$cancel && !isset(BOARD_REPORT_REASONS[$reason]))) {
    fail(400, '通報の指定がおかしいです');
}
$userId = (int)$user['user_id'];

$pdo = db();
// t.* の user_id はスレを立てた人。書き込んだ人は post_user_id で別に取る
$st = $pdo->prepare('SELECT t.*, p.post_id, p.user_id AS post_user_id, p.anonymous, p.display_name, p.deleted_at AS post_deleted_at
    FROM board_post p JOIN board_thread t ON t.thread_id = p.thread_id WHERE p.post_id = ?');
$st->execute([$postId]);
$row = $st->fetch();
if (!$row || $row['post_deleted_at'] !== null || !board_can_view($user, $row)) {
    fail(404, '書き込みが見つかりません');
}
if ($row['post_user_id'] !== null && (int)$row['post_user_id'] === $userId) {
    fail(403, '自分の書き込みは通報できません');
}
try {
    $pdo->beginTransaction();
    $count = $cancel
        ? board_report_cancel($pdo, $postId, $userId)
        : board_report_add($pdo, ['user_id' => $row['post_user_id']] + $row, $userId, $reason);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail(500, config('debug') ? $e->getMessage() : '通報を保存できませんでした');
}
echo json_encode(['count' => $count, 'mine' => !$cancel], JSON_UNESCAPED_UNICODE);
