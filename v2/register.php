<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/import/text.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$v = ['user_id' => '', 'user_name' => '', 'user_ruby' => ''];
if (is_post()) {
    verify_csrf();
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_\-]{3,32}$/', $v['user_id'])) {
        $errors[] = 'ユーザーIDは半角英数字・_・- の3〜32文字にしてください';
    }
    if ($v['user_name'] === '' || mb_strlen($v['user_name']) > 64) {
        $errors[] = '名前を入力してください（64文字以内）';
    }
    if (mb_strlen($v['user_ruby']) > 64) {
        $errors[] = 'ふりがなは64文字以内にしてください';
    }
    if (strlen($password) < 8) {
        $errors[] = 'パスワードは8文字以上にしてください';
    }
    if ($password !== $confirm) {
        $errors[] = '確認用パスワードが一致しません';
    }
    if (!$errors) {
        $st = db()->prepare('SELECT 1 FROM user_index WHERE user_id = ?');
        $st->execute([$v['user_id']]);
        if ($st->fetchColumn()) {
            $errors[] = 'そのユーザーIDは使われています';
        }
    }

    if (!$errors) {
        $pdo = db();
        $isFirst = (int)$pdo->query('SELECT COUNT(*) FROM user_index')->fetchColumn() === 0;
        $admin = $isFirst && config('first_user_is_admin') ? 1 : 0;

        // 同じ名前のメンバーがいて、まだ誰のアカウントにも紐付いていなければ自動で紐付ける
        $memberId = null;
        $key = member_key($v['user_name']);
        $rows = $pdo->query('SELECT m.member_id, m.member_name FROM member m
            LEFT JOIN user_index u ON u.member_id = m.member_id WHERE u.user_auto_id IS NULL')->fetchAll();
        foreach ($rows as $row) {
            if (member_key($row['member_name']) === $key) {
                $memberId = (int)$row['member_id'];
                break;
            }
        }

        $pdo->prepare('INSERT INTO user_index (user_id, user_name, user_ruby, user_password, user_admin, member_id)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$v['user_id'], $v['user_name'], $v['user_ruby'], password_hash($password, PASSWORD_DEFAULT), $admin, $memberId]);

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'auto_id' => (int)$pdo->lastInsertId(), 'id' => $v['user_id'], 'name' => $v['user_name'],
            'admin' => (bool)$admin, 'member_id' => $memberId,
        ];
        flash('登録しました。ようこそ！' . ($admin ? '（最初のユーザーなので管理者になりました）' : ''));
        redirect('index.php');
    }
}

render_header('新規登録');
?>
<section class="auth">
    <div class="auth__hero">
        <p class="eyebrow">Join</p>
        <h1 class="display">アカウントを<br>作成する。</h1>
        <p class="muted">名前をメンバー表と同じ表記にすると、自分の出演履歴と自動でつながります。</p>
    </div>
    <form method="post" class="card auth__card" novalidate>
        <h2>新規登録</h2>
        <?= csrf_field() ?>
        <?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>
        <label class="field"><span>ユーザーID</span>
            <input type="text" name="user_id" value="<?= h($v['user_id']) ?>" autocomplete="username" pattern="[A-Za-z0-9_\-]{3,32}" required></label>
        <label class="field"><span>名前（フルネーム）</span>
            <input type="text" name="user_name" value="<?= h($v['user_name']) ?>" autocomplete="name" required></label>
        <label class="field"><span>ふりがな</span>
            <input type="text" name="user_ruby" value="<?= h($v['user_ruby']) ?>"></label>
        <label class="field"><span>パスワード（8文字以上）</span>
            <input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
        <label class="field"><span>パスワード（確認）</span>
            <input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required></label>
        <button class="btn btn--primary btn--block" type="submit">登録する</button>
        <p class="muted small center">登録済み？ <a href="login.php">ログイン</a></p>
    </form>
</section>
<?php render_footer();
