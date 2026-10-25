<?php
/**
 * =====================================================================
 *  api_practice_pin.php — 練習プレイヤーの曲をピン留め / 解除する API（JSON を返す）
 * =====================================================================
 *  リクエスト（POST, JSON）:  {"key": 曲キー, "title": "曲名", "artist": "アーティスト", "on": true|false}
 *  レスポンス（JSON）      :  {"ok": true}   エラー: {"error": "..."}（400 / 401 / 403 / 405）
 *  権限: ログインしていれば誰でも（practice.php と同じ）。ピン留めは本人用
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tabs.php';

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
if (!is_post()) {
    fail(405, 'POST only');
}
verify_csrf();

$body = json_decode((string)file_get_contents('php://input'), true);
$key = is_string($body['key'] ?? null) ? $body['key'] : '';
if (!tab_key_valid($key)) {
    fail(400, '曲が正しくありません');
}
$pdo = db();
try {
if (($body['on'] ?? false) === true) {
    $title = mb_substr(trim((string)($body['title'] ?? '')), 0, 100);
    $artist = mb_substr(trim((string)($body['artist'] ?? '')), 0, 255);
    if ($title === '') {
        fail(400, '曲名がありません');
    }
    $pdo->prepare('INSERT INTO practice_pin (user_id, song_key, title, artist_name) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), artist_name = VALUES(artist_name)')
        ->execute([(int)$user['user_id'], $key, $title, $artist === '' ? null : $artist]);
} else {
    $pdo->prepare('DELETE FROM practice_pin WHERE user_id = ? AND song_key = ?')->execute([(int)$user['user_id'], $key]);
}
} catch (PDOException) {
    fail(500, 'ピン留めの保存先がありません（マイグレーション 077 が未実行）');
}
echo json_encode(['ok' => true]);
