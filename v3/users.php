<?php
/**
 * =====================================================================
 *  users.php — ユーザー管理（管理者のみ）
 * =====================================================================
 *  ・権限（一般 / 幹部 / 管理者）の変更
 *  ・メンバーとの紐付け（どのアカウントがどのメンバーか。選び直し・解除もここ）
 *    紐付けると、アカウント名はメンバー名に自動でそろう（sync_account_names()）
 *  ・アカウント削除
 *  ・招待コード（新規登録の合言葉）の変更（app_setting テーブル。lib/bootstrap.php の invite_code()）
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
    // 招待コードの変更は「どのユーザーか」が無いので、先に処理して終わる
    if (($_POST['action'] ?? '') === 'invite_code') {
        $code = trim((string)($_POST['invite_code'] ?? ''));
        if (mb_strlen($code) > 50) {
            flash('招待コードは50文字以内にしてください', 'error');
        } else {
            set_invite_code($code, $me['user_id']);
            clear_failures('#invite'); // 古いコードでの失敗の記録で、新しいコードまで止まらないように
            flash($code === '' ? '招待コードを無しにしました（誰でも新規登録できます）' : '招待コードを変更しました');
        }
        redirect(users_url($_GET)); // フォームは今の URL（並び順付き）に送られる
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM user_account WHERE is_admin')->fetchColumn();
    $st = $pdo->prepare('SELECT * FROM user_account WHERE user_id = ?');
    $st->execute([$userId]);
    $target = $st->fetch();

    if (!$target) {
        flash('ユーザーが見つかりません', 'error');
    } elseif ($action === 'set_role') {
        // 権限は 一般 / 幹部 / 管理者 の3段階（管理者は幹部の権限もすべて持つので、管理者のときは is_executive を 0 にそろえる）
        $role = (string)($_POST['role'] ?? '');
        if (!in_array($role, ['member', 'executive', 'admin'], true)) {
            flash('権限を選択してください', 'error');
        } elseif ($role !== 'admin' && $target['is_admin'] && $adminCount <= 1) {
            flash('最後の管理者は外せません（先に別の人を管理者にしてください）', 'error');
        } else {
            $pdo->prepare('UPDATE user_account SET is_admin = ?, is_executive = ? WHERE user_id = ?')
                ->execute([(int)($role === 'admin'), (int)($role === 'executive'), $userId]);
            if ($userId === $me['user_id']) {
                $_SESSION['user']['admin'] = $role === 'admin';
                $_SESSION['user']['executive'] = $role === 'executive';
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
    redirect(users_url($_GET)); // フォームは今の URL（並び順付き）に送られる
}

// 並び順: ?sort=entry|name&dir=asc|desc（決まった値だけ受け付ける）。並べ替えは PHP でやる（sort_positions と同じ比べ方にするため）
const USER_SORTS = ['entry' => 'asc', 'name' => 'asc']; // 列 => 最初に押したときの向き
$sort = is_string($_GET['sort'] ?? null) && isset(USER_SORTS[$_GET['sort']]) ? $_GET['sort'] : '';
$dir = $sort === '' ? '' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc');

// no_band: 紐付け先のメンバーが1バンドにも出ていない（紐付け間違いの疑い → ⚠ を出す）
// 名前の順は DB の並び（ORDER BY u.name）をそのまま使う（_i）
$users = $pdo->query('SELECT u.user_id, u.login_id, u.name, u.is_admin, u.is_executive, u.member_id, m.name AS member_name, m.entry_year,
        (u.member_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM band_member bm WHERE bm.member_id = u.member_id)) AS no_band
    FROM user_account u LEFT JOIN member m ON m.member_id = u.member_id ORDER BY u.name, u.user_id')->fetchAll();
foreach ($users as $i => &$u) {
    $u['_i'] = $i;
}
unset($u);
// いつもの並び（''）: 管理者が上 → 登録順。入学年: 不明な人は向きに関係なく一番下
$userCmp = static function (array $a, array $b, string $sort, string $dir): int {
    if ($sort === '') {
        return ((int)$b['is_admin'] <=> (int)$a['is_admin']) ?: ((int)$a['user_id'] <=> (int)$b['user_id']);
    }
    if ($sort === 'entry' && ($a['entry_year'] === null) !== ($b['entry_year'] === null)) {
        return ($a['entry_year'] === null) <=> ($b['entry_year'] === null);
    }
    $cmp = $sort === 'entry' ? (int)$a['entry_year'] <=> (int)$b['entry_year'] : $a['_i'] <=> $b['_i'];
    return ($dir === 'desc' ? -$cmp : $cmp) ?: ((int)$a['user_id'] <=> (int)$b['user_id']);
};
usort($users, static fn($a, $b) => $userCmp($a, $b, $sort, $dir));
$positions = sort_positions($users, sort_keys(USER_SORTS, true), $userCmp);

// プルダウンの選択肢: まだ誰とも紐付いていないメンバー（各行では、その行の今のメンバーも足して出す）
$freeMembers = $pdo->query('SELECT m.member_id, m.name FROM member m
    WHERE NOT EXISTS (SELECT 1 FROM user_account u WHERE u.member_id = m.member_id)
    ORDER BY m.name')->fetchAll();

$invite = invite_code();

render_header('ユーザー管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">ユーザー管理</h1>
        <?php if ($invite === ''): ?>
            <p class="muted"><?= icon('warning') ?> 今は誰でも新規登録できます。下の「招待コード」を設定すると、合言葉を知っている人だけが登録できるようになります。</p>
        <?php endif; ?>
    </div>
    <dl class="stats"><div><dt>ユーザー</dt><dd><?= count($users) ?></dd></div></dl>
</section>

<!-- 招待コード: 管理者はサークルの LINE などで共有するので、そのまま見えてよい（ログインのパスワードとは別物） -->
<form method="post" class="card form-card" style="margin-bottom:18px">
    <h2 class="section-title section-title--card">招待コード</h2>
    <p class="muted small">新規登録するときに必要な合言葉です。空にすると誰でも登録できます。変えても、登録済みの人はそのままログインできます。</p>
    <?= csrf_field() ?><input type="hidden" name="action" value="invite_code">
    <label class="field"><span>招待コード（50文字以内）</span><input name="invite_code" value="<?= h($invite) ?>" maxlength="50" autocomplete="off"></label>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
</form>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table table--users" data-client-sort data-sort-current="<?= h($sort) ?>" data-dir-current="<?= h($dir) ?>">
        <thead><tr><?= sort_button_th('entry', '入学年', $sort, $dir, 'asc') ?><?= sort_button_th('name', '名前', $sort, $dir, 'asc') ?><th>ログインID</th><th>メンバー</th><th>権限</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $i => $u): ?>
            <tr<?= (int)$u['user_id'] === $me['user_id'] ? ' class="is-me"' : '' ?> data-pos="<?= h(json_encode($positions[$i])) ?>">
                <td class="muted"><?= $u['entry_year'] !== null ? (int)$u['entry_year'] : '—' ?></td>
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
                        <a class="muted small" href="member?id=<?= (int)$u['member_id'] ?>">マイページ</a>
                        <?php if ($u['no_band']): ?>
                            <span class="status status--warn small" title="このメンバーは1バンドにも出ていません。紐付け先が合っているか確認してください"><?= icon('warning') ?> 出演なし</span>
                            <form method="post" action="member_delete" class="inline-form" data-keep-query data-confirm="メンバー「<?= h($u['member_name']) ?>」を削除します（<?= h($u['name']) ?> さんのアカウントは残り、紐付けなしになります）。元に戻せません。よろしいですか？"><?= csrf_field() ?><input type="hidden" name="member_id" value="<?= (int)$u['member_id'] ?>"><input type="hidden" name="return" value="users">
                                <button class="btn btn--ghost btn--danger btn--sm" type="submit">メンバー削除</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <!-- プルダウンを変えるとすぐ保存（data-autosubmit） -->
                    <?php $role = $u['is_admin'] ? 'admin' : ($u['is_executive'] ? 'executive' : 'member'); ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><input type="hidden" name="action" value="set_role">
                        <select name="role" data-autosubmit aria-label="<?= h($u['name']) ?> さんの権限">
                            <option value="member"<?= $role === 'member' ? ' selected' : '' ?>>一般</option>
                            <option value="executive"<?= $role === 'executive' ? ' selected' : '' ?>>幹部</option>
                            <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>>管理者</option>
                        </select>
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
