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
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/venue_ai.php';

header('Content-Type: application/json; charset=utf-8');

api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる
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
$venue['extra'] = ai_extra_clean($_POST['extra'] ?? ''); // 追加指示（任意・500文字まで）

$images = api_ai_images(); // 1枚10MB・AI_MAX_IMAGES 枚まで（lib/api.php）

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
