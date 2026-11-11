<?php
/**
 * =====================================================================
 *  api_instrument.php — 楽器を追加する API（JSON を返す）
 * =====================================================================
 *  名簿の取り込み（import.php）で「etc」を選んだときのモーダルから、
 *  JavaScript（assets/app.js の setupPicks）が fetch() で呼ぶ。
 *
 *  リクエスト（POST, JSON）:  {"short_name": "Tp", "name": "トランペット"}
 *  レスポンス（JSON）      :  {"instrument_id": 11, "short_name": "Tp", "name": "トランペット", "existed": false}
 *                             （同じ略称がもうあれば、新しく作らずにそれを返す。existed = true）
 *  エラー                 :  {"error": "略称を入力してください"}（400）
 *
 *  ログインしている人なら誰でも追加できる。間違えて追加したものは、管理者が instruments.php（楽器の管理）で変更・削除する。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/repository.php';

header('Content-Type: application/json; charset=utf-8');

api_user(); // 未ログイン・POST 以外・CSRF 不一致はここで JSON のエラーを返して終わる

// php://input = リクエストの本文そのもの。JSON は $_POST に入らないのでここから読む
$body = api_body();
$shortName = trim(tt_width(is_string($body['short_name'] ?? null) ? $body['short_name'] : '')); // 全角英数字は半角にそろえる
$name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');

// 長さは DB の列（short_name VARCHAR(10) / name VARCHAR(30)）に合わせる
if ($shortName === '' || mb_strlen($shortName) > 10) {
    fail(400, '略称を10文字以内で入力してください');
}
if ($name === '' || mb_strlen($name) > 30) {
    fail(400, '楽器名を30文字以内で入力してください');
}
if (mb_strtolower($shortName) === 'etc') {
    fail(400, '「etc」は「その他」として使っているので、別の略称にしてください');
}

try {
    echo json_encode(create_instrument(db(), $shortName, $name), JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    // instrument_id は TINYINT（最大255）なので、楽器が増えすぎるとここに来る
    fail(500, config('debug') ? $e->getMessage() : '楽器を追加できませんでした');
}
