<?php
/**
 * =====================================================================
 *  users.php — ユーザー管理（管理者のみ）
 * =====================================================================
 *  ・管理者権限の付け外し
 *  ・メンバーとの紐付け解除
 *  ・アカウント削除
 *  「最後の管理者」が自分の権限を外してしまうと誰も管理できなくなるので、それだけは止める。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$me = require_admin();

$pdo = db();

if (is_post()) {
    verify_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM user_index WHERE is_admin = 1')->fetchColumn();
    $st = $pdo->prepare('SELECT * FROM user_index WHERE user_id = ?');
    $st->execute([$userId]);
    $target = $st->fetch();

    if (!$target) {
        flash('ユーザーが見つかりません', 'error');
    } elseif ($action === 'toggle_admin') {
        if ($target['is_admin'] && $adminCount <= 1) {
            flash('最後の管理者は外せません（先に別の人を管理者にしてください）', 'error');
        } else {
            $pdo->prepare('UPDATE user_index SET is_admin = ? WHERE user_id = ?')->execute([$target['is_admin'] ? 0 : 1, $userId]);
            if ($userId === $me['user_id']) {
                $_SESSION['user']['admin'] = !$target['is_admin'];
            }
            flash("{$target['name']} さんの権限を変更しました");
        }
    } elseif ($action === 'unlink') {
        $pdo->prepare('UPDATE user_index SET member_id = NULL WHERE user_id = ?')->execute([$userId]);
        flash("{$target['name']} さんのメンバー紐付けを外しました");
    } elseif ($action === 'delete') {
        if ($userId === $me['user_id']) {
            flash('自分自身は削除できません', 'error');
        } else {
            // アカウントを消すだけ。member（出演記録）は消えない
            $pdo->prepare('DELETE FROM user_index WHERE user_id = ?')->execute([$userId]);
            flash("{$target['name']} さんのアカウントを削除しました");
        }
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT u.user_id, u.login_id, u.name, u.is_admin, u.member_id, m.name AS member_name
    FROM user_index u LEFT JOIN member m ON m.member_id = u.member_id ORDER BY u.is_admin DESC, u.user_id')->fetchAll();

render_header('ユーザー管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">ユーザー管理</h1>
        <?php if (!config('invite_code')): ?>
            <p class="muted">⚠ 今は誰でも新規登録できます。config.php の <code>invite_code</code> を設定すると、合言葉を知っている人だけが登録できるようになります。</p>
        <?php endif; ?>
    </div>
    <dl class="stats"><div><dt>ユーザー</dt><dd><?= count($users) ?></dd></div></dl>
</section>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table">
        <thead><tr><th>名前</th><th>ログインID</th><th>メンバー</th><th>権限</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr<?= (int)$u['user_id'] === $me['user_id'] ? ' class="is-me"' : '' ?>>
                <td class="strong"><?= h($u['name']) ?></td>
                <td class="muted"><?= h($u['login_id']) ?></td>
                <td>
                    <?php if ($u['member_id']): ?>
                        <a href="member.php?id=<?= (int)$u['member_id'] ?>"><?= h($u['member_name']) ?></a>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><input type="hidden" name="action" value="unlink"><button class="linkbtn muted small" type="submit">解除</button></form>
                    <?php else: ?><span class="muted small">未紐付け</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><input type="hidden" name="action" value="toggle_admin">
                        <button class="btn btn--sm<?= $u['is_admin'] ? ' btn--primary' : '' ?>" type="submit"><?= $u['is_admin'] ? '管理者' : '一般' ?></button>
                    </form>
                </td>
                <td class="num">
                    <?php if ((int)$u['user_id'] !== $me['user_id']): ?>
                        <form method="post" class="inline-form" data-confirm="<?= h($u['name']) ?> さんのアカウントを削除します（出演記録は残ります）。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><input type="hidden" name="action" value="delete">
                            <button class="btn btn--ghost btn--danger btn--sm" type="submit">削除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php render_footer();
