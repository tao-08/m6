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
 *    sections[] … 調べる項目（info / rooms / discounts / rates / packs / rentals / equipment）。省略・空なら全部
 *    source    … 'official' なら検索を公式サイト・予約ページのドメインだけにする（省略なら制限なし）
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
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/studio_ai.php';

header('Content-Type: application/json; charset=utf-8');

// AI はお金がかかるので、スタジオを編集できる人だけ（今はログインしていれば誰でも）
api_user(can_edit_studio(...), '利用できません');
if (!claude_enabled()) {
    fail(503, 'AI は設定されていません');
}

$studioId = (int)($_POST['studio_id'] ?? 0);
$model = (string)($_POST['model'] ?? '');
if ($model !== '' && !isset(studio_ai_models()[$model])) {
    fail(400, 'モデルが正しくありません'); // 一覧に無いモデル名は API に流さない
}
$st = db()->prepare('SELECT studio_id, name, website_url, reserve_url, address, access FROM studio WHERE studio_id = ?');
$st->execute([$studioId]);
$studio = $st->fetch();
if (!$studio) {
    fail(404, 'スタジオが見つかりません');
}
// 調べる項目（sections[]）と検索元（source）。項目は決められたキーだけ通す。空 = 全部
$sections = array_values(array_intersect(array_keys(STUDIO_AI_SECTIONS), array_map('strval', (array)($_POST['sections'] ?? []))));
$officialOnly = ($_POST['source'] ?? '') === 'official';
$studio['extra'] = ai_extra_clean($_POST['extra'] ?? ''); // 追加指示（任意・500文字まで）
// すでに登録されている部屋・割引区分の名前を AI に教える（同じ表記で返させて、別の部屋・区分が増えないように）
foreach (['known_rooms' => 'SELECT name FROM studio_room WHERE studio_id = ? ORDER BY sort_order, studio_room_id',
          'known_discounts' => 'SELECT name FROM studio_discount WHERE studio_id = ? ORDER BY sort_order, studio_discount_id'] as $key => $sql) {
    $q = db()->prepare($sql);
    $q->execute([$studioId]);
    $studio[$key] = $q->fetchAll(PDO::FETCH_COLUMN);
}

// ---- アップロードされた画像 ----
//   $_FILES['images'] は複数ファイルのとき「name[] / tmp_name[] / error[] …」と項目ごとの配列になる（PHP の仕様）
$images = api_ai_images(); // 1枚10MB・AI_MAX_IMAGES 枚まで（lib/api.php）

// セッションはこの処理の間ロックされる（同じ人の別タブの読み込みが待たされる）。
// AI の返事は数十秒かかるので、いったん書き込みを終えてロックを外し、終わってから開き直す
session_write_close();
try {
    $proposal = studio_ai_suggest($studio, $images, $model, $sections, $officialOnly);
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
