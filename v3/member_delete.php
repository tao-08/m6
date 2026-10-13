<?php
/**
 * =====================================================================
 *  member_delete.php — メンバーを削除する（管理者のみ）
 * =====================================================================
 *  送信元は member.php の「プロフィールを編集」の一番下と、users.php の ⚠「出演なし」の横（return=users）。
 *  画面は持たない（処理して戻るだけ）。
 *  ・出演記録（band_member）がある人は消さない。FOREIGN KEY も ON DELETE RESTRICT で止めるが、
 *    先に分かりやすいメッセージで返す。同じ人が2人いるなら「メンバーの統合」を使う
 *  ・係 / 確認依頼 / マイアルバムは ON DELETE CASCADE で一緒に消える
 *  ・紐付いているアカウントは ON DELETE SET NULL で「紐付けなし」になるだけ（アカウントは残る）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_admin();

if (!is_post()) {
    redirect('members');
}
verify_csrf();

$memberId = (int)($_POST['member_id'] ?? 0);
// 戻り先。users.php の ⚠ の横から来たら users に戻す（決まった値だけ受け付ける。オープンリダイレクト対策）
$fromUsers = ($_POST['return'] ?? '') === 'users';
$pdo = db();
$st = $pdo->prepare('SELECT name FROM member WHERE member_id = ?');
$st->execute([$memberId]);
$name = $st->fetchColumn();
if ($name === false) {
    flash('メンバーが見つかりません', 'error');
    redirect('members');
}

$st = $pdo->prepare('SELECT COUNT(DISTINCT band_id) FROM band_member WHERE member_id = ?');
$st->execute([$memberId]);
$bands = (int)$st->fetchColumn();
if ($bands > 0) {
    flash("「{$name}」は {$bands} バンドに出演しているので削除できません（同じ人が2人いるなら「メンバーの統合」を使ってください）", 'error');
    redirect($fromUsers ? 'users' : 'member?id=' . $memberId);
}

$pdo->prepare('DELETE FROM member WHERE member_id = ?')->execute([$memberId]);
// 自分のアカウントと紐付いていた人を消したら、セッションの member_id も外す
if (($_SESSION['user']['member_id'] ?? null) === $memberId) {
    $_SESSION['user']['member_id'] = null;
}
flash("「{$name}」を削除しました");
redirect($fromUsers ? 'users' : 'members');
