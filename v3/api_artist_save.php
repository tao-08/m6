<?php
/**
 * =====================================================================
 *  api_artist_save.php — アーティストの名前・ふりがな・別称を1項目ずつ保存する API（JSON を返す）
 * =====================================================================
 *  masters.php（管理 > アーティスト）の入力欄から、JavaScript（assets/app_admin.js の setupArtistAutosave）が
 *  「入力が止まって約0.8秒後」に fetch() で呼ぶ。保存ボタンは無い。
 *
 *  リクエスト（POST, JSON）:
 *    {"artist_id": 12, "field": "name",      "value": "ヨルシカ"}
 *    {"artist_id": 12, "field": "name_kana", "value": "よるしか"}          ← 空文字なら NULL に戻す
 *    {"artist_id": 12, "field": "aliases",   "value": "Oasis、オアシス"}   ← 「、」か「,」区切り。この欄の中身で別称をまるごと置き換える
 *  レスポンス（JSON）:  {"value": "保存した値（整えたあと）"}
 *  エラー            :  {"error": "..."}（400 / 404 / 409）
 *
 *  なぜ「1項目ずつ」か: 名前だけ直したのに、ふりがなまで古い値で上書きしてしまう事故を防ぐため。
 *  管理者だけ使える（artist.php の編集欄・masters.php と同じ）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/albums.php'; // album_match_key（別称の表記ゆれ判定）

header('Content-Type: application/json; charset=utf-8');

api_user('is_admin', '管理者のみ使えます'); // 401（未ログイン）→ 403（管理者でない）→ 405（POST 以外）→ CSRF の順に止める

$body = api_body();
$artistId = (int)($body['artist_id'] ?? 0);
$field = is_string($body['field'] ?? null) ? $body['field'] : '';
$value = trim(is_string($body['value'] ?? null) ? $body['value'] : '');

$pdo = db();
$st = $pdo->prepare('SELECT name FROM artist WHERE artist_id = ?');
$st->execute([$artistId]);
$current = $st->fetchColumn(); // 見つからなければ false
if ($current === false) {
    fail(404, 'アーティストが見つかりません');
}

if ($field === 'name') {
    // ---- 名前: 1〜100文字。artist.name は UNIQUE なので、ほかのアーティストと同じ名前は不可（まとめるなら「統合」） ----
    if ($value === '' || mb_strlen($value) > 100) {
        fail(400, 'アーティスト名は1〜100文字で入力してください');
    }
    $dup = $pdo->prepare('SELECT 1 FROM artist WHERE name = ? AND artist_id <> ?');
    $dup->execute([$value, $artistId]);
    if ($dup->fetchColumn()) {
        fail(409, "「{$value}」というアーティストはすでにあります（まとめるときは「統合」を使ってください）");
    }
    $pdo->prepare('UPDATE artist SET name = ? WHERE artist_id = ?')->execute([$value, $artistId]);
    api_ok(['value' => $value]);
}

if ($field === 'name_kana') {
    // ---- ふりがな: 空なら NULL（artist.php・member_edit.php と同じ） ----
    if (mb_strlen($value) > 100) {
        fail(400, 'ふりがなは100文字以内にしてください');
    }
    $pdo->prepare('UPDATE artist SET name_kana = ? WHERE artist_id = ?')->execute([$value !== '' ? $value : null, $artistId]);
    api_ok(['value' => $value]);
}

if ($field === 'aliases') {
    // ---- 別称: 「、」「,」「，」で区切って複数。空欄ならすべて外す ----
    $list = [];
    foreach (preg_split('/[、,，]/u', $value) ?: [] as $alias) {
        $alias = trim($alias);
        if ($alias === '') {
            continue;
        }
        if (mb_strlen($alias) > 255 || album_match_key($alias) === '') { // 記号だけの別称は、マイアルバムと突き合わせても当たらない
            fail(400, '別称は記号だけにせず、255文字以内にしてください');
        }
        $list[album_match_key($alias)] ??= $alias; // 表記ゆれ（大文字小文字・全角半角）で同じものは先に書いた方だけ残す
    }
    $list = array_values($list);

    // 本名・ほかのアーティストの名前や別称と同じ別称は付けない（どのアーティストか分からなくなる。artist.php の alias_add と同じ決まり）
    $own = $pdo->prepare('SELECT a.artist_id, a.name FROM artist a WHERE a.name = ?
        UNION SELECT a.artist_id, a.name FROM artist_alias al JOIN artist a ON a.artist_id = al.artist_id WHERE al.name = ? LIMIT 1');
    foreach ($list as $alias) {
        $own->execute([$alias, $alias]);
        $owner = $own->fetch();
        if ($owner && (int)$owner['artist_id'] !== $artistId) {
            fail(409, "「{$alias}」はアーティスト「{$owner['name']}」の名前か別称として使われています（同じアーティストなら統合してください）");
        }
        if ($owner && $owner['name'] === $alias) { // 自分の本名と同じ別称は意味がない
            fail(400, "「{$alias}」はこのアーティストの名前と同じです");
        }
    }

    // 全部消してから入れ直す（途中で失敗しても元に戻るようにトランザクション）。主キー name は大文字小文字を区別しないので INSERT IGNORE
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM artist_alias WHERE artist_id = ?')->execute([$artistId]);
        $ins = $pdo->prepare('INSERT IGNORE INTO artist_alias (name, artist_id) VALUES (?, ?)');
        foreach ($list as $alias) {
            $ins->execute([$alias, $artistId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    api_ok(['value' => implode('、', $list)]);
}

fail(400, '保存する項目が不明です');
