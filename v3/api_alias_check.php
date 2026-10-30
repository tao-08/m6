<?php
/**
 * =====================================================================
 *  api_alias_check.php — 名義のなりすましチェック API（JSON を返す）
 * =====================================================================
 *  掲示板・コメントの名義欄に入力するたびに、JavaScript（assets/app.js の setupAliasField）が fetch() で呼ぶ。
 *  送信前に「この名義は使えない」と出して、本文を書き直しにならないようにするため。
 *
 *  リクエスト（POST, JSON）:  {"name": "山田太郎"}
 *  レスポンス（JSON）      :  {"error": null}（使える） / {"error": "「山田太郎」は…"}（使えない）
 *
 *  ※ これは「親切な案内」用。本当の守りは投稿処理側の posted_alias_error()（JS は簡単に外せるため）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // alias_impersonation_error()

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if ($user === null) {
    http_response_code(401); // 401 = ログインしていない
    echo json_encode(['error' => 'ログインしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_post()) {
    http_response_code(405); // 405 = そのメソッド（GET など）は使えない
    echo json_encode(['error' => 'POST only']);
    exit;
}
verify_csrf(); // CSRF 対策: JS から X-CSRF-Token ヘッダーで送られてくるトークンを確かめる

// php://input = リクエストの本文そのもの。JSON は $_POST に入らないのでここから読む
$body = json_decode((string)file_get_contents('php://input'), true);
$name = is_string($body['name'] ?? null) ? mb_substr($body['name'], 0, 30) : ''; // 名義欄と同じく30文字まで

// エラーメッセージは JS 側で textContent に入れる（innerHTML にしない＝XSS 対策）
echo json_encode(['error' => alias_impersonation_error($name, $user)], JSON_UNESCAPED_UNICODE);
