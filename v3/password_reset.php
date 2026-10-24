<?php
/**
 * =====================================================================
 *  password_reset.php — パスワードの再設定（ログイン不要）
 * =====================================================================
 *  ログインID と 招待コード（新規登録の合言葉。users.php で管理者が決める）を入力して、新しいパスワードを決める。
 *  招待コードを知っている = サークルの人、という前提で本人確認の代わりにしている。
 *    ⚠ つまり招待コードを知っている人なら、誰のアカウント（管理者も）でも再設定できる。
 *      招待コードが空のときは「ログインIDだけで誰でも再設定できる」ことになるので、この画面ごと止める。
 *  招待コードの総当たりは register.php と同じ仕組みで止める（失敗は '#invite' に数える）。
 *  再設定すると password_changed_at が変わるので、ログインしたままの他の端末もすべてログアウトになる（require_login）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) {
    redirect('./'); // ログイン中の人はアカウント設定で変える
}

$invite = invite_code();
$error = '';
$loginId = '';
if ($invite !== '' && is_post()) {
    verify_csrf();
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $new = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if (strlen($new) < 8) {
        $error = 'パスワードは8文字以上にしてください';
    } elseif ($new !== $confirm) {
        $error = '確認用パスワードが一致しません';
    } elseif (too_many_failures('#invite', INVITE_MAX)) {
        $error = '失敗が続いたので、しばらく受け付けません。' . LOGIN_WINDOW_MINUTES . '分ほどたってからもう一度試してください';
    } else {
        $st = db()->prepare('SELECT user_id, login_id FROM user_account WHERE login_id = ?');
        $st->execute([$loginId]);
        $account = $st->fetch();
        // 招待コードが違うのか ID が無いのかは教えない（ID を探られないように）。どちらも同じ失敗として数える
        if (!hash_equals($invite, (string)($_POST['invite_code'] ?? '')) || !$account) {
            record_failure('#invite');
            $error = 'ログインIDまたは招待コードが違います';
        } else {
            set_password((int)$account['user_id'], $new);
            clear_failures($account['login_id']); // 忘れて何度も間違えた記録で、新しいパスワードまで止まらないように
            flash('パスワードを再設定しました。新しいパスワードでログインしてください');
            redirect('login');
        }
    }
}

render_header('パスワード再設定');
?>
<section class="auth">
    <div class="auth__hero">
        <h1 class="auth__logo">
            <img src="assets/online.png" alt="AbbeyRoad.online" class="brand__logo--light" width="146" height="40">
            <img src="assets/logo-dark.png" alt="" class="brand__logo--dark" width="146" height="40">
        </h1>
    </div>
    <?php if ($invite === ''): ?>
        <!-- 招待コードが空だと本人確認になる物が無い。管理者が users.php で招待コードを決めるまで使えない -->
        <div class="card auth__card">
            <h2>パスワード再設定</h2>
            <div class="flash flash--error">現在は利用できません。管理者に連絡してください</div>
            <p class="muted small center"><a href="login">ログイン</a></p>
        </div>
    <?php else: ?>
        <form method="post" action="password_reset" class="card auth__card" novalidate>
            <h2>パスワード再設定</h2>
            <?= csrf_field() ?>
            <?php if ($error): ?><div class="flash flash--error"><?= h($error) ?></div><?php endif; ?>
            <label class="field">
                <span>ログインID</span>
                <input type="text" name="login_id" value="<?= h($loginId) ?>" autocomplete="username" maxlength="25" required autofocus>
            </label>
            <label class="field">
                <span>招待コード</span>
                <input type="text" name="invite_code" autocomplete="off" required>
            </label>
            <label class="field">
                <span>新しいパスワード（8文字以上）</span>
                <input type="password" name="new" autocomplete="new-password" minlength="8" required>
            </label>
            <label class="field">
                <span>新しいパスワード（確認）</span>
                <input type="password" name="confirm" autocomplete="new-password" minlength="8" required>
            </label>
            <button class="btn btn--primary btn--block" type="submit">再設定</button>
            <p class="muted small center"><a href="login">ログイン</a></p>
        </form>
    <?php endif; ?>
</section>
<?php render_footer();
