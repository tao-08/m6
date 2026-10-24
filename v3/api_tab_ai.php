<?php
/**
 * =====================================================================
 *  api_tab_ai.php — 画像・テキストの TAB 譜を AI に読ませて、TAB の形にして返す API（JSON を返す）
 * =====================================================================
 *  tab.php の編集モードの「インポート」から、assets/tab.js が fetch() で呼ぶ。保存はしない（人がプレビューで確認して置換・追加する）。
 *  仕組み・回数の上限は lib/tab_ai.php。
 *
 *  リクエスト（POST, JSON）:
 *    {"tab_id": 12, "image_id": 34}                 … その TAB に添付した画像（サーバーが自分でファイルを読む）
 *    {"tab_id": 12, "text": "e|--3--5-|\n…"}        … 貼り付けたテキスト
 *    ほかに  "beats_per_bar", "steps_per_beat"（今の編集画面の拍子・分割。このマス数に丸めて返す）
 *  レスポンス: {"ok":true, "data":{"bars":[…]}, "bpm":120|null, "tuning":"E A D G B E"|null, "notes":["…"]}
 *  エラー: {"error":"..."}（400 / 401 / 403 / 404 / 405 / 429 / 502 / 503）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tab_ai.php';
require_once __DIR__ . '/lib/live_image.php'; // LIVE_IMAGE_DIR

header('Content-Type: application/json; charset=utf-8');

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
if (!tab_allowed()) {
    fail(403, '管理人のみ利用できます'); // 今は管理人だけ（lib/tabs.php の tab_allowed）
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf();
if (!claude_enabled()) {
    fail(503, 'AI は設定されていません');
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    fail(400, '送られた内容がおかしいです');
}
$pdo = db();
$tabId = tab_int($body['tab_id'] ?? null) ?? 0;
$st = $pdo->prepare('SELECT tuning, kind FROM song_tab WHERE tab_id = ?');
$st->execute([$tabId]);
$tab = $st->fetch();
if (!$tab) {
    fail(404, 'TAB が見つかりません');
}
if ($tab['kind'] === 'drum') {
    fail(400, 'ドラム譜は AI の読み取りに対応していません');
}
$beats = tab_int($body['beats_per_bar'] ?? 4) ?? 0;
$steps = tab_int($body['steps_per_beat'] ?? 4) ?? 0;
if ($beats < 1 || $beats > 16 || !in_array($steps, TAB_STEPS, true)) {
    fail(400, '拍子・分割がおかしいです');
}

// ---- 入力: 画像かテキストのどちらか ----
$image = null;
$text = null;
if (isset($body['image_id'])) {
    // 画像は DB で調べた「この TAB の画像」だけ。ファイル名は DB の値（形も確かめる）。クライアントから来た URL・パスは使わない
    $st = $pdo->prepare('SELECT file_name FROM song_tab_image WHERE image_id = ? AND tab_id = ?');
    $st->execute([tab_int($body['image_id']) ?? 0, $tabId]);
    $name = (string)$st->fetchColumn();
    $path = LIVE_IMAGE_DIR . '/' . $name;
    if (!preg_match('/^[0-9a-f]{16}\.jpg$/', $name) || !is_file($path)) {
        fail(404, '画像が見つかりません');
    }
    $image = ['path' => $path, 'name' => 'TAB'];
} elseif (isset($body['text'])) {
    $text = $body['text'];
    if (!is_string($text) || trim($text) === '' || mb_strlen($text) > TAB_AI_TEXT_MAX) {
        fail(400, 'テキストは1〜' . TAB_AI_TEXT_MAX . '文字で入力してください');
    }
} else {
    fail(400, '画像かテキストを指定してください');
}

// ---- 1日の回数の上限（AI は1回ごとにお金がかかる）----
if (!tab_ai_take($pdo, (int)$user['user_id'])) {
    fail(429, '今日の回数の上限（' . TAB_AI_DAILY_LIMIT . '回）に達しました。明日また使えます');
}

try {
    $r = tab_ai_read($image, $text, tab_string_count($tab['tuning']), $tab['tuning'], $beats, $steps);
} catch (RuntimeException $e) {
    fail(502, $e->getMessage());
}
echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
