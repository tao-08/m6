<?php
/**
 * =====================================================================
 *  api_check_dismiss.php — 🚩 確認依頼のお知らせを ✕ で消したと記録する API（JSON を返す）
 * =====================================================================
 *  トップページ（index.php）のお知らせの ✕ を押したとき、
 *  JavaScript（assets/app.js の setupCheckDismiss）が fetch() で呼ぶ。
 *
 *  リクエスト（POST）:  band_id=バンドID
 *  レスポンス（JSON）:  {"ok": true}
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

// INSERT ... SELECT: 本当にあるバンドだけ記録する（無い ID は 0 行になるだけ）。2回押しても主キーで1行のまま（IGNORE）
db()->prepare('INSERT IGNORE INTO check_dismiss (user_id, band_id) SELECT ?, band_id FROM band WHERE band_id = ?')
    ->execute([(int)$user['user_id'], (int)($_POST['band_id'] ?? 0)]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
