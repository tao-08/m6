<?php
/**
 * =====================================================================
 *  login.php — ログイン
 * =====================================================================
 *  user_account から login_id で1件探し、password_verify() でパスワードを確認する。
 *  パスワードは DB に「ハッシュ」（元に戻せない変換結果）でしか保存していないので、
 *  入力されたパスワードを同じ方法で変換して一致するかを password_verify() が見てくれる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // link_users_to_members() を使う

if (current_user()) {
    redirect('index.php'); // ログイン済みならトップへ
}

$error = '';
$loginId = '';
if (is_post()) {
    verify_csrf();
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $st = db()->prepare('SELECT * FROM user_account WHERE login_id = ?');
    $st->execute([$loginId]);
    $row = $st->fetch(); // 見つからなければ false

    if ($row && password_verify($password, $row['password_hash'])) {
        // ハッシュの方式が古ければ（PHP が新しい方式を推奨していれば）作り直して保存
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE user_account SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        }
        // まだメンバーと紐付いていなければ、名前が同じメンバーと紐付ける
        if ($row['member_id'] === null) {
            link_users_to_members(db());
            $st->execute([$loginId]);
            $row = $st->fetch();
        }

        // セッション固定攻撃対策: ログインの瞬間にセッションIDを新しくする
        // （ログイン前に盗まれた/仕込まれたセッションIDを使えなくする）
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'user_id'   => (int)$row['user_id'],
            'login_id'  => $row['login_id'],
            'name'      => $row['name'],
            'admin'     => (bool)$row['is_admin'],
            'member_id' => $row['member_id'] === null ? null : (int)$row['member_id'],
        ];
        $next = $_SESSION['after_login'] ?? 'index.php';
        unset($_SESSION['after_login']);
        // オープンリダイレクト対策: 「/」で始まる同じサイト内の URL だけ許可する
        // （//evil.com のような「別サイトへ飛ぶ URL」を弾く）
        redirect(preg_match('#^/(?![/\\\\])#', $next) ? $next : 'index.php');
    }
    // IDが無いのかパスワードが違うのかは教えない（存在するIDを探られないように）
    $error = 'ログインIDまたはパスワードが違います';
}

render_header('ログイン');
?>
<section class="auth">
    <div class="auth__hero">
        <h1 class="auth__logo">
            <img src="assets/online.png" alt="AbbeyRoad.online" class="brand__logo--light" width="146" height="40">
            <img src="assets/logo-dark.png" alt="" class="brand__logo--dark" width="146" height="40">
        </h1>
        <p class="muted">サークルのライブとコピーバンドの記録をひとつに。</p>
    </div>
    <form method="post" class="card auth__card" novalidate>
        <h2>ログイン</h2>
        <?= csrf_field() ?>
        <?php if ($error): ?><div class="flash flash--error"><?= h($error) ?></div><?php endif; ?>
        <label class="field">
            <span>ログインID</span>
            <input type="text" name="login_id" value="<?= h($loginId) ?>" autocomplete="username" required autofocus>
        </label>
        <label class="field">
            <span>パスワード</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn btn--primary btn--block" type="submit">ログイン</button>
        <p class="muted small center"><a href="register.php">新規登録</a></p>
    </form>
</section>
<?php render_footer();
