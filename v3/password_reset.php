<?php
/**
 * =====================================================================
 *  password_reset.php — パスワードの再設定（ログイン不要）
 * =====================================================================
 *  管理者が users.php で発行した URL（?token=64文字）から来る。
 *  password_reset には token の sha256 しか無いので、届いた token を同じ方法で変換して探す。
 *  有効期限（PASSWORD_RESET_MINUTES 分）内で、まだ使っていないものだけ受け付ける。使ったら行を消す（1回限り）。
 *  再設定すると password_changed_at が変わるので、ログインしたままの他の端末もすべてログアウトになる（require_login）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// URL にトークンが入っているページなので、外部へのリクエストに URL を一切付けない（念のため。既定でもパスは送らない）
header('Referrer-Policy: no-referrer');

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$account = false;
// 形が違うものは DB に聞くまでもなく無効（配列で送られてきた場合もここで弾く）
if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token)) {
    $st = db()->prepare('SELECT u.user_id, u.login_id FROM password_reset r
        JOIN user_account u ON u.user_id = r.user_id
        WHERE r.token_hash = ? AND r.expires_at > NOW()');
    $st->execute([hash('sha256', $token)]);
    $account = $st->fetch();
}

$error = '';
if ($account && is_post()) {
    verify_csrf();
    $new = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if (strlen($new) < 8) {
        $error = 'パスワードは8文字以上にしてください';
    } elseif ($new !== $confirm) {
        $error = '確認用パスワードが一致しません';
    } else {
        set_password((int)$account['user_id'], $new);
        db()->prepare('DELETE FROM password_reset WHERE user_id = ?')->execute([$account['user_id']]); // 1回限り
        clear_failures($account['login_id']); // 忘れて何度も間違えた記録で、新しいパスワードまで止まらないように
        // この端末で別の人がログインしていても、いったん全部捨ててログイン画面からやり直してもらう
        $_SESSION = [];
        session_regenerate_id(true);
        flash('パスワードを再設定しました。新しいパスワードでログインしてください');
        redirect('login');
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
    <?php if (!$account): ?>
        <!-- 期限切れ・使用済み・発行し直しで古くなった、のどれかは教えない -->
        <div class="card auth__card">
            <h2>パスワード再設定</h2>
            <div class="flash flash--error">URLが無効か期限切れです。管理者に再発行を依頼してください</div>
            <p class="muted small center"><a href="login">ログイン</a></p>
        </div>
    <?php else: ?>
        <form method="post" action="password_reset" class="card auth__card" novalidate>
            <h2>パスワード再設定</h2>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <?php if ($error): ?><div class="flash flash--error"><?= h($error) ?></div><?php endif; ?>
            <!-- ログインIDも忘れている人がいるので見せる。autocomplete="username" でパスワード管理アプリにも ID ごと保存させる -->
            <label class="field">
                <span>ログインID</span>
                <input type="text" value="<?= h($account['login_id']) ?>" autocomplete="username" readonly>
            </label>
            <label class="field">
                <span>新しいパスワード（8文字以上）</span>
                <input type="password" name="new" autocomplete="new-password" minlength="8" required autofocus>
            </label>
            <label class="field">
                <span>新しいパスワード（確認）</span>
                <input type="password" name="confirm" autocomplete="new-password" minlength="8" required>
            </label>
            <button class="btn btn--primary btn--block" type="submit">再設定</button>
        </form>
    <?php endif; ?>
</section>
<?php render_footer();
