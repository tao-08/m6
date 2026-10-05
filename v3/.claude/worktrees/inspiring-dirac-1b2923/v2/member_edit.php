<?php
/**
 * =====================================================================
 *  member_edit.php — メンバーのプロフィール（名前・ふりがな・入部年度）を更新する
 * =====================================================================
 *  member.php の「プロフィールを編集」フォームの送信先。画面は持たない（処理して戻るだけ）。
 *  編集できるのは「本人（アカウントと紐付いている人）」か「管理者」だけ。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

if (!is_post()) {
    redirect('members.php');
}
verify_csrf();

$memberId = (int)($_POST['member_id'] ?? 0);
$back = 'member.php?id=' . $memberId;
if (!is_admin() && $user['member_id'] !== $memberId) {
    http_response_code(403);
    exit('自分のプロフィールか、管理者だけが編集できます');
}

$name = trim((string)($_POST['name'] ?? ''));
$kana = trim((string)($_POST['name_kana'] ?? ''));
$entry = trim((string)($_POST['entry_year'] ?? ''));

$pdo = db();
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

$pdo->prepare('UPDATE member SET name = ?, name_kana = ?, entry_year = ? WHERE member_id = ?')
    ->execute([$name, $kana, $entry === '' ? null : (int)$entry, $memberId]);
flash('プロフィールを更新しました');
redirect($back);
