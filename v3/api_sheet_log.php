<?php
/**
 * =====================================================================
 *  api_sheet_log.php — セッティングシートの PDF を作ったことを記録する API（JSON を返す）
 * =====================================================================
 *  PDF はブラウザの中で作る（setting_sheet.js）ので、作れたあとに fetch() で知らせてもらう。
 *  管理者は sheet_log.php で見る。
 *
 *  リクエスト（POST, JSON）: {band_id, band, date, template}
 *  レスポンス              : {"ok": true} / {"error": "..."}（401 / 405）
 *  記録の失敗は PDF 作成の邪魔にならないよう、JS 側で握りつぶす。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';

header('Content-Type: application/json; charset=utf-8');

$user = api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

$body = api_body();
$body = is_array($body) ? $body : [];
$text = static fn(mixed $v, int $max): string => mb_substr(is_string($v) ? trim($v) : '', 0, $max);

$pdo = db();
// band_id は実在するときだけ入れる（外部キーに存在しない値を入れて失敗しないように）
$bandId = is_int($body['band_id'] ?? null) ? $body['band_id'] : 0;
if ($bandId > 0) {
    $st = $pdo->prepare('SELECT 1 FROM band WHERE band_id = ?');
    $st->execute([$bandId]);
    if (!$st->fetchColumn()) {
        $bandId = 0;
    }
}

$pdo->prepare('INSERT INTO sheet_log (user_id, band_id, band_name, held_on, template_key, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
    ->execute([
        (int)$user['user_id'],
        $bandId > 0 ? $bandId : null,
        $text($body['band'] ?? '', 100),
        $text($body['date'] ?? '', 30),
        $text($body['template'] ?? '', 100),
    ]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
