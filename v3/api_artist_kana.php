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
require_once __DIR__ . '/lib/claude.php';

header('Content-Type: application/json; charset=utf-8');

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
// AI はお金がかかるので管理者だけ
if (current_user() === null || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => '管理者のみ使えます'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_post()) {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

$body = json_decode((string)file_get_contents('php://input'), true);
$name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
if ($name === '' || mb_strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['error' => '名前を入力してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (artist_kana_style($name) === 'none') {
    http_response_code(400);
    echo json_encode(['error' => 'ひらがな・カタカナだけの名前には、ふりがなは要りません'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $kana = claude_artist_kana([$name])[$name] ?? null;
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($kana === null) {
    http_response_code(502);
    echo json_encode(['error' => 'AI が読みを返しませんでした。手で入力してください'], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['kana' => $kana], JSON_UNESCAPED_UNICODE);
