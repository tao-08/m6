<?php
/**
 * =====================================================================
 *  api_studio_ai.php — スタジオ情報を AI（Claude + ウェブ検索）で調べて、提案を返す API（JSON を返す）
 * =====================================================================
 *  studio_info.php の「✨AIで入力」から、assets/studio_info.js が fetch() で呼ぶ。
 *  ここでは保存しない。提案はセッションに置き、人がプレビューで項目を選んでから studio_info.php（apply_ai）が反映する。
 *  仕組みは lib/studio_ai.php。
 *
 *  リクエスト（POST, multipart/form-data = FormData。画像ファイルを送れる形式）
 *    studio_id … スタジオの id
 *    model     … 使うモデル（lib/studio_ai.php の studio_ai_models() にあるものだけ。省略・不明なら初期のモデル）
 *    images[]  … 画像ファイル（任意・最大 AI_MAX_IMAGES 枚）。あれば画像だけを読む（ウェブ検索なし）。無ければウェブ検索
 *    ヘッダー X-CSRF-Token に CSRF トークン
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
if (!can_edit_studio()) {
    fail(403, '利用できません'); // AI はお金がかかるので、スタジオを編集できる人だけ（今はログインしていれば誰でも）
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // fetch() からは X-CSRF-Token ヘッダーで送る
if (!claude_enabled()) {
    fail(503, 'AI は設定されていません');
}

$studioId = (int)($_POST['studio_id'] ?? 0);
$model = (string)($_POST['model'] ?? '');
if ($model !== '' && !isset(studio_ai_models()[$model])) {
    fail(400, 'モデルが正しくありません'); // 一覧に無いモデル名は API に流さない
}
$st = db()->prepare('SELECT studio_id, name, website_url, address, access FROM studio WHERE studio_id = ?');
$st->execute([$studioId]);
$studio = $st->fetch();
if (!$studio) {
    fail(404, 'スタジオが見つかりません');
}

// ---- アップロードされた画像 ----
//   $_FILES['images'] は複数ファイルのとき「name[] / tmp_name[] / error[] …」と項目ごとの配列になる（PHP の仕様）
$images = [];
$f = $_FILES['images'] ?? null;
if (is_array($f) && is_array($f['name'])) {
    foreach ($f['name'] as $i => $name) {
        $err = (int)$f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            // INI_SIZE / FORM_SIZE = サーバーの上限（php.ini）を超えた
            fail(400, in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? '画像が大きすぎます' : '画像を受け取れませんでした');
        }
        // is_uploaded_file = 本当に今の POST でアップロードされた一時ファイルか（サーバー上の別のファイルのパスを偽装されない）
        if (!is_uploaded_file($f['tmp_name'][$i]) || $f['size'][$i] > 10 * 1024 * 1024) {
            fail(400, '画像は1枚10MBまでです');
        }
        $images[] = ['path' => $f['tmp_name'][$i], 'name' => basename((string)$name)];
    }
}
if (count($images) > AI_MAX_IMAGES) {
    fail(400, '画像は一度に ' . AI_MAX_IMAGES . ' 枚までです');
}

// セッションはこの処理の間ロックされる（同じ人の別タブの読み込みが待たされる）。
// AI の返事は数十秒かかるので、いったん書き込みを終えてロックを外し、終わってから開き直す
session_write_close();
try {
    $proposal = studio_ai_suggest($studio, $images, $model);
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
