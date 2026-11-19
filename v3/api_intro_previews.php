<?php
/**
 * =====================================================================
 *  api_intro_previews.php — 試聴を調べていない曲を 1 曲だけ調べる API（JSON を返す。管理者のみ）
 * =====================================================================
 *  intro_previews.php（試聴の一括調査）の「調べる」から、assets/intro_previews.js が数秒おきに繰り返し呼ぶ。
 *  1 回で 1 曲だけ: iTunes の回数制限に引っかからないよう、間隔は呼ぶ側（JS）が空ける。サーバーは 1 回ごとに終わる。
 *
 *  リクエスト（POST, 本文なし）。CSRF は X-CSRF-Token ヘッダー（api_user が確かめる）
 *  レスポンス（JSON）:
 *    {"remaining": 残りの曲数, "title": 今調べた曲名, "found": true（試聴あり）/ false（iTunes に無かった）}
 *    {"remaining": 0, "done": true}（調べる曲が残っていない）
 *  エラー: {"error": "..."}（401 / 403 / 405 / 502 = iTunes に繋がらなかった。その曲は覚えず、次も調べ直せる）
 *
 *  ★ 調べる曲は DB から選ぶ（track_preview_pending）。リクエストで曲を指定させないので、
 *    任意の曲名で iTunes を叩かせることはできない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/tracks.php';

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます');

$pdo = db();
$track = track_preview_pending($pdo, 1)[0] ?? null;
if ($track === null) {
    api_ok(['remaining' => 0, 'done' => true]);
}

$url = track_preview_remember($pdo, $track); // 探して覚える。false = 通信できなかった（覚えない）
if ($url === false) {
    fail(502, 'iTunes に接続できませんでした');
}
api_ok([
    'remaining' => track_preview_pending_count($pdo),
    'title'     => $track['title'],
    'found'     => $url !== null,
]);
