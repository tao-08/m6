<?php
/**
 * =====================================================================
 *  api_instrument_save.php — 楽器の色・略称・正式名称を1項目ずつ保存する API（JSON を返す）
 * =====================================================================
 *  instruments.php（楽器の管理）の入力欄から、JavaScript（assets/app.js の setupInstrumentAutosave）が
 *  「入力が止まって約0.8秒後」に fetch() で呼ぶ。保存ボタンは無い。
 *
 *  リクエスト（POST, JSON）:
 *    {"instrument_id": 12, "field": "color",      "value": "#1d6f96"}
 *    {"instrument_id": 12, "field": "short_name", "value": "Perc"}
 *    {"instrument_id": 12, "field": "name",       "value": "パーカッション"}
 *  レスポンス（JSON）:  {"value": "保存した値（整えたあと）"}
 *  エラー            :  {"error": "..."}（400 / 404 / 409）
 *
 *  1項目ずつ送る理由: 色だけ直したのに、略称・名前まで古い値で上書きする事故を防ぐため。
 *  Vo / Gt / Ba / Dr / Key / Cho / etc（LOCKED_INSTRUMENTS）は何も変えられない。管理者だけ使える。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/repository.php'; // tt_width など

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます');

$body = api_body();
$id = (int)($body['instrument_id'] ?? 0);
$field = is_string($body['field'] ?? null) ? $body['field'] : '';
$value = trim(is_string($body['value'] ?? null) ? $body['value'] : '');

$pdo = db();
$st = $pdo->prepare('SELECT short_name, name FROM instrument WHERE instrument_id = ?');
$st->execute([$id]);
$target = $st->fetch();
if (!$target) {
    fail(404, '楽器が見つかりません');
}
// 画面ではロック中の楽器に入力欄を出していないが、リクエストを直接送られても止める（サーバー側でも必ず確かめる）
if (instrument_locked($target['short_name'])) {
    fail(403, "「{$target['name']}」は変更できません");
}

if ($field === 'short_name') {
    $value = trim(tt_width($value)); // 全角英数字は半角にそろえる（名簿の取り込みで追加するときと同じ）
    if ($value === '' || mb_strlen($value) > 10) {
        fail(400, '略称は1〜10文字で入力してください');
    }
    if (instrument_locked($value)) { // Vo などと同じ略称にすると、プログラムがどちらを探しているか分からなくなる
        fail(400, "略称「{$value}」は使えません");
    }
    $dup = $pdo->prepare('SELECT 1 FROM instrument WHERE short_name = ? AND instrument_id <> ?');
    $dup->execute([$value, $id]);
    if ($dup->fetchColumn()) {
        fail(409, "略称「{$value}」の楽器はすでにあります");
    }
} elseif ($field === 'name') {
    if ($value === '' || mb_strlen($value) > 30) {
        fail(400, '正式名称は1〜30文字で入力してください');
    }
} elseif ($field === 'color') {
    $value = strtolower($value);
    if (!is_hex_color($value)) { // <style> に直接書く値なので、'#rrggbb' の形だけを通す（XSS 対策）
        fail(400, '色が正しくありません');
    }
} else {
    fail(400, '保存する項目が不明です');
}

// $field は上の if で決まった3つのどれかだけ（入力値をそのまま列名に使わない）なので、SQL に直接入れても安全
$column = ['short_name' => 'short_name', 'name' => 'name', 'color' => 'color'][$field];
$pdo->prepare("UPDATE instrument SET {$column} = ? WHERE instrument_id = ?")->execute([$value, $id]);
api_ok(['value' => $value]);
