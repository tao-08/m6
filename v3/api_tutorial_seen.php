<?php
/**
 * =====================================================================
 *  api_tutorial_seen.php — チュートリアルを「見た」と記録する API（JSON を返す）
 * =====================================================================
 *  初回のチュートリアル（partials/tutorial.php）を閉じたとき、
 *  JavaScript（assets/app.js の setupTutorial）が fetch() で呼ぶ。
 *
 *  リクエスト（POST）:  本文なし
 *  レスポンス（JSON）:  {"ok": true}
 *  エラー           :  {"error": "..."}（401 / 405）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

// 2回目以降に閉じたとき（「もう一度見る」から）も、最初に見た日時は残す → COALESCE で NULL のときだけ入れる
db()->prepare('UPDATE user_account SET tutorial_seen_at = COALESCE(tutorial_seen_at, NOW()) WHERE user_id = ?')
    ->execute([(int)$user['user_id']]);
$_SESSION['user']['tutorial_seen'] = true;

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
