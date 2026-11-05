<?php
/**
 * =====================================================================
 *  api_venue_ai.php — 会場情報を AI（Claude + ウェブ検索 / 画像）で調べて、提案を返す API（JSON を返す）
 * =====================================================================
 *  venue.php の「AIで入力」から、assets/venue_ai.js が fetch() で呼ぶ。api_studio_ai.php の会場版。
 *  ここでは保存しない。提案はセッションに置き、人がプレビューで項目を選んでから venue.php（apply_ai）が反映する。
 *
 *  リクエスト（POST, multipart/form-data）
 *    venue_id  … 会場の id
 *    model     … 使うモデル（studio_ai_models() にあるものだけ。省略なら初期のモデル）
 *    sections[] … 調べる項目（info / stations / equipment）。省略・空なら全部
 *    source    … 'official' なら検索を公式サイトのドメインだけにする
 *    images[]  … 画像ファイル（任意・最大 AI_MAX_IMAGES 枚）。あれば画像だけを読む
 *    ヘッダー X-CSRF-Token に CSRF トークン
 *  レスポンス: {"ok":true, "proposal":{…}, "yen":12.3} / エラー: {"error":"..."}
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/venue_ai.php';

header('Content-Type: application/json; charset=utf-8');

function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) {
    fail(401, 'ログインしてください');
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf();
if (!claude_enabled()) {
    fail(503, 'AI は設定されていません');
}

$venueId = (int)($_POST['venue_id'] ?? 0);
$model = (string)($_POST['model'] ?? '');
if ($model !== '' && !isset(studio_ai_models()[$model])) {
    fail(400, 'モデルが正しくありません');
}
$st = db()->prepare('SELECT venue_id, name, website_url, address FROM venue WHERE venue_id = ?');
$st->execute([$venueId]);
$venue = $st->fetch();
if (!$venue) {
    fail(404, '会場が見つかりません');
}
$sections = array_values(array_intersect(array_keys(VENUE_AI_SECTIONS), array_map('strval', (array)($_POST['sections'] ?? []))));
$officialOnly = ($_POST['source'] ?? '') === 'official';

$images = [];
$f = $_FILES['images'] ?? null;
if (is_array($f) && is_array($f['name'])) {
    foreach ($f['name'] as $i => $name) {
        $err = (int)$f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            fail(400, in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '画像が大きすぎます' : '画像を受け取れませんでした');
        }
        if (!is_uploaded_file($f['tmp_name'][$i]) || $f['size'][$i] > 10 * 1024 * 1024) {
            fail(400, '画像は1枚10MBまでです');
        }
        $images[] = ['path' => $f['tmp_name'][$i], 'name' => basename((string)$name)];
    }
}
if (count($images) > AI_MAX_IMAGES) {
    fail(400, '画像は一度に ' . AI_MAX_IMAGES . ' 枚までです');
}

// AI の返事は数十秒かかるので、いったんセッションのロックを外す（同じ人の別タブが待たされないように）
session_write_close();
try {
    $proposal = venue_ai_suggest($venue, $images, $model, $sections, $officialOnly);
} catch (RuntimeException $e) {
    fail(502, $e->getMessage());
}
session_start();
$_SESSION['venue_ai'][$venueId] = $proposal; // 反映（venue.php の apply_ai）はこの提案だけを使う

echo json_encode([
    'ok' => true,
    'proposal' => $proposal,
    'yen' => claude_last_usage()['yen'] ?? null,
], JSON_UNESCAPED_UNICODE);
