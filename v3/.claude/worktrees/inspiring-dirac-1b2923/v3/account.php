<?php
/**
 * =====================================================================
 *  account.php — アカウント設定（元の m6 の user_compile.php に当たるページ）
 * =====================================================================
 *  1つのページに3つのフォームがあるので、hidden の action で「どのフォームか」を見分けている。
 *    action=profile  … 名前
 *    action=password … パスワード変更（今のパスワードの確認つき）
 *    action=member   … 自分がどのメンバーか（マイページの紐付け）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$pdo = db();
$st = $pdo->prepare('SELECT * FROM user_account WHERE user_id = ?');
$st->execute([$user['user_id']]);
$account = $st->fetch();

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            flash('名前は1〜50文字で入力してください', 'error');
        } else {
            $pdo->prepare('UPDATE user_account SET name = ? WHERE user_id = ?')->execute([$name, $user['user_id']]);
            $_SESSION['user']['name'] = $name; // ヘッダーの表示も変わるようにセッションも更新
            flash('プロフィールを更新しました');
        }
    }

    if ($action === 'password') {
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        // 他人がログインしたままの画面を使ってパスワードを変えられないよう、今のパスワードを確認する
        if (!password_verify($current, $account['password_hash'])) {
            flash('今のパスワードが違います', 'error');
        } elseif (strlen($new) < 8) {
            flash('新しいパスワードは8文字以上にしてください', 'error');
        } elseif ($new !== $confirm) {
            flash('確認用パスワードが一致しません', 'error');
        } else {
            $pdo->prepare('UPDATE user_account SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
            session_regenerate_id(true);
            flash('パスワードを変更しました');
        }
    }

    if ($action === 'member') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId === 0) {
            $pdo->prepare('UPDATE user_account SET member_id = NULL WHERE user_id = ?')->execute([$user['user_id']]);
            $_SESSION['user']['member_id'] = null;
            flash('メンバーとの紐付けを外しました');
        } else {
            // 他のアカウントがすでに使っているメンバーは選べない
            $st = $pdo->prepare('SELECT 1 FROM member m WHERE m.member_id = ?
                AND NOT EXISTS (SELECT 1 FROM user_account u WHERE u.member_id = m.member_id AND u.user_id <> ?)');
            $st->execute([$memberId, $user['user_id']]);
            if (!$st->fetchColumn()) {
                flash('そのメンバーは選べません（別のアカウントが紐付いています）', 'error');
            } else {
                $pdo->prepare('UPDATE user_account SET member_id = ? WHERE user_id = ?')->execute([$memberId, $user['user_id']]);
                $_SESSION['user']['member_id'] = $memberId;
                flash('メンバーと紐付けました。マイページが使えます');
            }
        }
    }
    redirect('account.php');
}

// 紐付けできるメンバー（誰のアカウントにも紐付いていない人 + 今の自分）
$st = $pdo->prepare('SELECT m.member_id, m.name FROM member m
    WHERE NOT EXISTS (SELECT 1 FROM user_account u WHERE u.member_id = m.member_id AND u.user_id <> ?)
    ORDER BY m.name');
$st->execute([$user['user_id']]);
$linkable = $st->fetchAll();

render_header('アカウント設定');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Account</p>
        <h1 class="display">アカウント設定</h1>
        <p class="muted">ログインID: <strong><?= h($account['login_id']) ?></strong><?= $account['is_admin'] ? ' · <span class="tag">管理者</span>' : '' ?></p>
    </div>
</section>

<div class="settings">
    <form method="post" class="card form-card">
        <h2 class="section-title section-title--card">プロフィール</h2>
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <label class="field"><span>名前</span><input name="name" value="<?= h($account['name']) ?>" maxlength="50" required></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>

    <form method="post" class="card form-card">
        <h2 class="section-title section-title--card">自分はどのメンバー？</h2>
        <p class="muted small">選ぶと、ヘッダーに「マイページ」が出て、自分の出演バンドが強調表示されます。</p>
        <?= csrf_field() ?><input type="hidden" name="action" value="member">
        <label class="field"><span>メンバー</span>
            <select name="member_id">
                <option value="0">紐付けない</option>
                <?php foreach ($linkable as $m): ?>
                    <option value="<?= (int)$m['member_id'] ?>"<?= (int)$m['member_id'] === (int)$account['member_id'] ? ' selected' : '' ?>><?= h($m['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>

    <form method="post" class="card form-card">
        <h2 class="section-title section-title--card">パスワード変更</h2>
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <label class="field"><span>今のパスワード</span><input type="password" name="current" autocomplete="current-password" required></label>
        <label class="field"><span>新しいパスワード（8文字以上）</span><input type="password" name="new" autocomplete="new-password" minlength="8" required></label>
        <label class="field"><span>新しいパスワード（確認）</span><input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">変更</button></div>
    </form>
</div>
<?php render_footer();
