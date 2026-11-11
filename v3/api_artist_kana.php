<?php
/**
 * =====================================================================
 *  api_artist_kana.php — アーティスト名のふりがなを AI に考えてもらう API（JSON を返す）
 * =====================================================================
 *  artist.php の編集欄の「AI で読みを入れる」ボタンから、JavaScript（assets/app.js の setupKanaAi）が呼ぶ。
 *  ここでは DB に保存しない。入力欄に入れるだけで、管理者が確かめて「名前・ふりがなを変更」で保存する
 *  （AI の読み間違いがそのまま DB に入らないように）。
 *
 *  リクエスト（POST, JSON）:  {"name": "米津玄師"}
 *  レスポンス（JSON）      :  {"kana": "よねづけんし"} / {"error": "..."}
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/claude.php';

header('Content-Type: application/json; charset=utf-8');

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
// AI はお金がかかるので管理者だけ
if (current_user() === null || !is_admin()) {
    fail(403, '管理者のみ使えます');
}
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$body = api_body();
$name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
if ($name === '' || mb_strlen($name) > 100) {
    fail(400, '名前を入力してください');
}

if (artist_kana_style($name) === 'none') {
    fail(400, 'ひらがな・カタカナだけの名前には、ふりがなは要りません');
}
try {
    $kana = claude_artist_kana([$name])[$name] ?? null;
} catch (RuntimeException $e) {
    fail(502, $e->getMessage());
}
if ($kana === null) {
    fail(502, 'AI が読みを返しませんでした。手で入力してください');
}
echo json_encode(['kana' => $kana], JSON_UNESCAPED_UNICODE);
