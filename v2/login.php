<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$userId = '';
if (is_post()) {
    verify_csrf();
    $userId = trim((string)($_POST['user_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $st = db()->prepare('SELECT * FROM user_index WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();

    if ($row && password_verify($password, $row['user_password'])) {
        if (password_needs_rehash($row['user_password'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE user_index SET user_password = ? WHERE user_auto_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_auto_id']]);
        }
        session_regenerate_id(true); // セッション固定攻撃対策
        $_SESSION['user'] = [
            'auto_id'   => (int)$row['user_auto_id'],
            'id'        => $row['user_id'],
            'name'      => $row['user_name'],
            'admin'     => (bool)$row['user_admin'],
            'member_id' => $row['member_id'] === null ? null : (int)$row['member_id'],
        ];
        $next = $_SESSION['after_login'] ?? 'index.php';
        unset($_SESSION['after_login']);
        // オープンリダイレクト防止: 同一サイト内のパスだけ許可
        redirect(preg_match('#^/(?![/\\\\])#', $next) ? $next : 'index.php');
    }
    $error = 'ユーザーIDまたはパスワードが違います';
}

render_header('ログイン');
?>
<section class="auth">
    <div class="auth__hero">
        <p class="eyebrow">Live Database</p>
        <h1 class="display">誰と、どこで、<br>何を鳴らしたか。</h1>
        <p class="muted">サークルのライブとコピーバンドの記録をひとつに。</p>
    </div>
    <form method="post" class="card auth__card" novalidate>
        <h2>ログイン</h2>
        <?= csrf_field() ?>
        <?php if ($error): ?><div class="flash flash--error"><?= h($error) ?></div><?php endif; ?>
        <label class="field">
            <span>ユーザーID</span>
            <input type="text" name="user_id" value="<?= h($userId) ?>" autocomplete="username" required autofocus>
        </label>
        <label class="field">
            <span>パスワード</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn btn--primary btn--block" type="submit">ログイン</button>
        <p class="muted small center">アカウントがない？ <a href="register.php">新規登録</a></p>
    </form>
</section>
<?php render_footer();
