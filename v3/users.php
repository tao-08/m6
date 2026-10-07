<?php
/**
 * =====================================================================
 *  users.php — ユーザー管理（管理者のみ）
 * =====================================================================
 *  ・管理者権限の付け外し
 *  ・メンバーとの紐付け（どのアカウントがどのメンバーか。選び直し・解除もここ）
 *    紐付けると、アカウント名はメンバー名に自動でそろう（sync_account_names()）
 *  ・アカウント削除
 *  「最後の管理者」が自分の権限を外してしまうと誰も管理できなくなるので、それだけは止める。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // sync_account_names()
$me = require_admin();

$pdo = db();

if (is_post()) {
    verify_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM user_account WHERE is_admin')->fetchColumn();
    $st = $pdo->prepare('SELECT * FROM user_account WHERE user_id = ?');
    $st->execute([$userId]);
    $target = $st->fetch();

    if (!$target) {
        flash('ユーザーが見つかりません', 'error');
    } elseif ($action === 'toggle_admin') {
        if ($target['is_admin'] && $adminCount <= 1) {
            flash('最後の管理者は外せません（先に別の人を管理者にしてください）', 'error');
        } else {
            $pdo->prepare('UPDATE user_account SET is_admin = ? WHERE user_id = ?')->execute([$target['is_admin'] ? 0 : 1, $userId]);
            if ($userId === $me['user_id']) {
                $_SESSION['user']['admin'] = !$target['is_admin'];
            }
            flash("{$target['name']} さんの権限を変更しました");
        }
    } elseif ($action === 'link') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId === 0) {
            $pdo->prepare('UPDATE user_account SET member_id = NULL WHERE user_id = ?')->execute([$userId]);
            flash("{$target['name']} さんのメンバー紐付けを外しました");
        } else {
            // 存在するメンバーで、しかも他のアカウントがまだ使っていないこと（member_id は UNIQUE）
            $st = $pdo->prepare('SELECT m.name FROM member m WHERE m.member_id = ?
                AND NOT EXISTS (SELECT 1 FROM user_account u WHERE u.member_id = m.member_id AND u.user_id <> ?)');
            $st->execute([$memberId, $userId]);
            $memberName = $st->fetchColumn();
            if ($memberName === false) {
                flash('そのメンバーは選べません（別のアカウントが紐付いています）', 'error');
            } else {
                $pdo->prepare('UPDATE user_account SET member_id = ? WHERE user_id = ?')->execute([$memberId, $userId]);
                sync_account_names($pdo, $memberId); // アカウント名をメンバー名にそろえる
                flash("{$target['name']} さんを「{$memberName}」と紐付けました");
            }
        }
    } elseif ($action === 'delete') {
        if ($userId === $me['user_id']) {
            flash('自分自身は削除できません', 'error');
        } else {
            // アカウントを消すだけ。member（出演記録）は消えない
            $pdo->prepare('DELETE FROM user_account WHERE user_id = ?')->execute([$userId]);
            flash("{$target['name']} さんのアカウントを削除しました");
        }
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT u.user_id, u.login_id, u.name, u.is_admin, u.member_id, m.name AS member_name
    FROM user_account u LEFT JOIN member m ON m.member_id = u.member_id ORDER BY u.is_admin DESC, u.user_id')->fetchAll();

// プルダウンの選択肢: まだ誰とも紐付いていないメンバー（各行では、その行の今のメンバーも足して出す）
$freeMembers = $pdo->query('SELECT m.member_id, m.name FROM member m
    WHERE NOT EXISTS (SELECT 1 FROM user_account u WHERE u.member_id = m.member_id)
    ORDER BY m.name')->fetchAll();

render_header('ユーザー管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">ユーザー管理</h1>
        <?php if (!config('invite_code')): ?>
            <p class="muted"><?= icon('warning') ?> 今は誰でも新規登録できます。config.php の <code>invite_code</code> を設定すると、合言葉を知っている人だけが登録できるようになります。</p>
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
                    <!-- プルダウンで選んで「保存」。「紐付けない」を選ぶと解除 -->
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><input type="hidden" name="action" value="link">
                        <select name="member_id" aria-label="<?= h($u['name']) ?> さんのメンバー">
                            <option value="0">紐付けない</option>
                            <?php if ($u['member_id']): ?>
                                <option value="<?= (int)$u['member_id'] ?>" selected><?= h($u['member_name']) ?></option>
                            <?php endif; ?>
                            <?php foreach ($freeMembers as $m): ?>
                                <option value="<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn--sm" type="submit">保存</button>
                    </form>
                    <?php if ($u['member_id']): ?>
                        <a class="muted small" href="member.php?id=<?= (int)$u['member_id'] ?>">マイページ</a>
                    <?php endif; ?>
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
