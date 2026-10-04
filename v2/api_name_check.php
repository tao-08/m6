<?php
/**
 * =====================================================================
 *  api_name_check.php — 名簿プレビューの色分け用 API（JSON を返す）
 * =====================================================================
 *  元の m6 の preview_name_check.php と同じ役割。
 *  プレビューで名前を書き換えるたびに、JavaScript（assets/app.js）が fetch() でここを呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"cells": ["遠藤翔吾", "村田侑斗、丸野友多郎", ...]}
 *  レスポンス（JSON）      :  [{"status": "ok", "hint": ""}, {"status": "new", "hint": "..."}, ...]
 *
 *  1人ずつではなく「画面の全セル」をまとめて送ってもらう。
 *  → 「今回の取り込みの中での表記ゆれ」も見つけられる & 通信が1回で済む。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/import/planner.php';

header('Content-Type: application/json; charset=utf-8');

// API なのでログイン画面へのリダイレクトではなく、エラーの JSON を返す
if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインしてください']);
    exit;
}
if (!is_post()) {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
verify_csrf(); // JS から X-CSRF-Token ヘッダーで送られてくる

// php://input = リクエストの本文そのもの。JSON は $_POST に入らないのでここから読む
$body = json_decode((string)file_get_contents('php://input'), true);
$cells = is_array($body['cells'] ?? null) ? array_slice($body['cells'], 0, 2000) : [];
$cells = array_map(static fn($c) => is_string($c) ? mb_substr($c, 0, 200) : '', $cells);

echo json_encode(classify_cells(db(), $cells), JSON_UNESCAPED_UNICODE);
