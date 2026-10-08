<?php
/**
 * =====================================================================
 *  member_edit.php — メンバーのプロフィール（名前・ふりがな・入部年度・学部・係・音楽アプリ）を更新する
 * =====================================================================
 *  送信元は2つ。画面は持たない（処理して戻るだけ）。
 *    ・account.php の「メンバープロフィール」（自分のプロフィール。return=account が付いてくる）
 *    ・member.php の「プロフィールを編集」（他の人のページ。管理者だけ）
 *  編集できるのは「本人（アカウントと紐付いている人）」か「管理者」だけ。
 *  それ以外の人は、ふりがなも含めて何も編集できない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/albums.php'; // MUSIC_APPS（選べる音楽アプリの一覧）
require_once __DIR__ . '/lib/repository.php'; // sync_account_names(), find_or_create_role()
$user = require_login();

if (!is_post()) {
    redirect('members');
}
verify_csrf();

$memberId = (int)($_POST['member_id'] ?? 0);
// 戻り先。URL をそのまま POST で受け取ると、外部サイトに飛ばされる（オープンリダイレクト）ので、
// 「account」という決まった値のときだけアカウント設定に戻す
$back = ($_POST['return'] ?? '') === 'account' ? 'account#member-profile' : 'member?id=' . $memberId;
$pdo = db();

// 本人でも管理者でもない人は何も更新できない。
// ※ member.php でフォームを出していないだけでは、POST を直接送られたら通ってしまう。
//   なので、ここ（サーバー側）で必ず止める
if (!is_admin() && $user['member_id'] !== $memberId) {
    flash('このメンバーのプロフィールは編集できません', 'error');
    redirect($back);
}

$kana = trim((string)($_POST['name_kana'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$entry = trim((string)($_POST['entry_year'] ?? ''));
$musicApp = (string)($_POST['music_app'] ?? ''); // '' = 選ばない
$faculty = (string)($_POST['faculty'] ?? '');    // '' = 選ばない
// 係: チェックした今ある係（role_id の配列）＋ 新しく打った係（1つ。空欄なら作らない）
$roleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['roles'] ?? [])), static fn($id) => $id > 0)));
$newRole = trim((string)($_POST['new_role'] ?? ''));

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
// 学部も選択肢（FACULTIES）に無い値は受け付けない。DB の CHECK 制約でも止まるが、先にメッセージで返す
if ($faculty !== '' && !in_array($faculty, FACULTIES, true)) {
    $errors[] = '学部の選び方がおかしいです';
}
if (mb_strlen($newRole) > 30) {
    $errors[] = '新しい係は30文字以内にしてください';
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

// プロフィールと係は「両方保存」か「両方なし」にしたいのでトランザクションにする
$pdo->beginTransaction();
try {
    // 空欄は NULL（「分からない」）で保存する
    $pdo->prepare('UPDATE member SET name = ?, name_kana = ?, entry_year = ?, faculty = ?, music_app = ? WHERE member_id = ?')
        ->execute([$name, $kana !== '' ? $kana : null, $entry === '' ? null : (int)$entry,
            $faculty !== '' ? $faculty : null, $musicApp !== '' ? $musicApp : null, $memberId]);

    // 係は「全部消して、チェックされたものを入れ直す」。差分を計算するより単純で間違えにくい
    //   送られてきた role_id は HTML を書き換えられているかもしれないので、role に実在するものだけ使う
    $keep = [];
    if ($roleIds) {
        $in = implode(',', array_fill(0, count($roleIds), '?')); // IN (?, ?, ?) … 個数分のプレースホルダ
        $st = $pdo->prepare("SELECT role_id FROM role WHERE role_id IN ($in)");
        $st->execute($roleIds);
        $keep = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($newRole !== '') {
        $keep[] = find_or_create_role($pdo, $newRole);
    }
    $pdo->prepare('DELETE FROM member_role WHERE member_id = ?')->execute([$memberId]);
    $ins = $pdo->prepare('INSERT IGNORE INTO member_role (member_id, role_id) VALUES (?, ?)');
    foreach (array_unique($keep) as $roleId) {
        $ins->execute([$memberId, $roleId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
// 紐付いているアカウントの名前も合わせる（ヘッダーの表示は次のページで require_login() が DB から読み直す）
sync_account_names($pdo, $memberId);
flash('プロフィールを更新しました');
redirect($back);
