<?php
/**
 * =====================================================================
 *  api_studio_ai.php — スタジオ情報を AI（Claude + ウェブ検索）で調べて、提案を返す API（JSON を返す）
 * =====================================================================
 *  studio_info.php の「✨AIで入力」から、assets/studio_info.js が fetch() で呼ぶ。
 *  ここでは保存しない。提案はセッションに置き、人がプレビューで項目を選んでから studio_info.php（apply_ai）が反映する。
 *  仕組みは lib/studio_ai.php。
 *
 *  リクエスト（POST, JSON）: {"studio_id": 3}   ヘッダー X-CSRF-Token に CSRF トークン
 *  レスポンス: {"ok":true, "proposal":{…}, "yen":12.3}
 *  エラー: {"error":"..."}（400 / 401 / 403 / 404 / 405 / 502 / 503）
 *
 *  ■ HTTP ステータスコード（Web API の約束ごと）
 *    200 成功 / 400 送り方がおかしい / 401 ログインしていない / 403 権限が無い / 404 見つからない
 *    405 そのメソッド（GET など）は使えない / 502 先の相手（AI）で失敗 / 503 機能が使えない状態
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/studio_ai.php';

header('Content-Type: application/json; charset=utf-8');

/** エラーを JSON で返して終わる（never = この関数からは戻ってこない、という型） */
function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) {
    fail(401, 'ログインしてください');
}
if (!is_executive()) {
    fail(403, '幹部のみ利用できます'); // AI はお金がかかるので、編集できる人（幹部・管理者）だけ
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // fetch() からは X-CSRF-Token ヘッダーで送る
if (!claude_enabled()) {
    fail(503, 'AI は設定されていません');
}

$body = json_decode((string)file_get_contents('php://input'), true);
$studioId = is_array($body) && is_int($body['studio_id'] ?? null) ? $body['studio_id'] : 0;
$st = db()->prepare('SELECT studio_id, name, website_url, address, access FROM studio WHERE studio_id = ?');
$st->execute([$studioId]);
$studio = $st->fetch();
if (!$studio) {
    fail(404, 'スタジオが見つかりません');
}

// セッションはこの処理の間ロックされる（同じ人の別タブの読み込みが待たされる）。
// AI の返事は数十秒かかるので、いったん書き込みを終えてロックを外し、終わってから開き直す
session_write_close();
try {
    $proposal = studio_ai_suggest($studio);
} catch (RuntimeException $e) {
    fail(502, $e->getMessage());
}
session_start();
$_SESSION['studio_ai'][$studioId] = $proposal; // 反映（studio_info.php の apply_ai）はこの提案だけを使う

echo json_encode([
    'ok' => true,
    'proposal' => $proposal,
    'yen' => claude_last_usage()['yen'] ?? null, // 今回かかった金額の目安
], JSON_UNESCAPED_UNICODE);
