<?php
/**
 * =====================================================================
 *  member_edit.php — メンバーのプロフィール（名前・ふりがな・入部年度・音楽アプリ）を更新する
 * =====================================================================
 *  member.php の「プロフィールを編集」フォームの送信先。画面は持たない（処理して戻るだけ）。
 *  全項目を編集できるのは「本人（アカウントと紐付いている人）」か「管理者」だけ。
 *  それ以外のログイン中の人は「ふりがな」だけ編集できる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/albums.php'; // MUSIC_APPS（選べる音楽アプリの一覧）
$user = require_login();

if (!is_post()) {
    redirect('members.php');
}
verify_csrf();

$memberId = (int)($_POST['member_id'] ?? 0);
$back = 'member.php?id=' . $memberId;
$pdo = db();

$kana = trim((string)($_POST['name_kana'] ?? ''));

// 本人でも管理者でもない人は「ふりがな」だけ更新できる。
// ※ フォームで欄を隠しているだけでは、HTML を書き換えて name などを送られたら通ってしまう。
//   なので、ここ（サーバー側）で name_kana 以外は一切読まないようにしている
if (!is_admin() && $user['member_id'] !== $memberId) {
    $st = $pdo->prepare('SELECT 1 FROM member WHERE member_id = ?');
    $st->execute([$memberId]);
    if (!$st->fetchColumn()) {
        redirect('members.php');
    }
    if (mb_strlen($kana) > 50) {
        flash('ふりがなは50文字以内にしてください', 'error');
        redirect($back);
    }
    $pdo->prepare('UPDATE member SET name_kana = ? WHERE member_id = ?')
        ->execute([$kana !== '' ? $kana : null, $memberId]);
    flash('ふりがなを更新しました');
    redirect($back);
}

$name = trim((string)($_POST['name'] ?? ''));
$entry = trim((string)($_POST['entry_year'] ?? ''));
$musicApp = (string)($_POST['music_app'] ?? ''); // '' = 選ばない

$errors = [];
if ($name === '' || mb_strlen($name) > 50) {
    $errors[] = '名前は1〜50文字で入力してください';
}
if (mb_strlen($kana) > 50) {
    $errors[] = 'ふりがなは50文字以内にしてください';
}
if ($entry !== '' && (!ctype_digit($entry) || (int)$entry < 1950 || (int)$entry > 2100)) {
    $errors[] = '入部年度は西暦4桁で入力してください';
}
// 選択肢に無い値（HTML を書き換えて送られてきた値など）は受け付けない
if ($musicApp !== '' && !isset(MUSIC_APPS[$musicApp])) {
    $errors[] = '音楽アプリの選び方がおかしいです';
}
// member.name は UNIQUE。別の人と同じ名前にはできない（同一人物なら「メンバーの統合」を使う）
$st = $pdo->prepare('SELECT 1 FROM member WHERE name = ? AND member_id <> ?');
$st->execute([$name, $memberId]);
if ($st->fetchColumn()) {
    $errors[] = "「{$name}」は既に別のメンバーとして登録されています（同じ人なら管理者に統合を依頼してください）";
}

if ($errors) {
    foreach ($errors as $e) {
        flash($e, 'error');
    }
    redirect($back);
}

// 空欄は NULL（「分からない」）で保存する
$pdo->prepare('UPDATE member SET name = ?, name_kana = ?, entry_year = ?, music_app = ? WHERE member_id = ?')
    ->execute([$name, $kana !== '' ? $kana : null, $entry === '' ? null : (int)$entry, $musicApp !== '' ? $musicApp : null, $memberId]);
flash('プロフィールを更新しました');
redirect($back);
