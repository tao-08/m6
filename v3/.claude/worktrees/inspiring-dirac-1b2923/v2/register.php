<?php
/**
 * =====================================================================
 *  register.php — 新規登録
 * =====================================================================
 *  入力チェック → login_id の重複チェック → password_hash() して INSERT → そのままログイン状態にする。
 *
 *  ⚠ パスワードは絶対に平文（そのまま）で保存しない。必ず password_hash() を通す。
 *    DB が流出しても、ハッシュからは元のパスワードを復元できない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/import/planner.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$v = ['login_id' => '', 'name' => '', 'name_kana' => '']; // 入力値（エラー時にフォームへ戻す用）
if (is_post()) {
    verify_csrf();
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    // ---- 入力チェック（文字数は DB の varchar の長さに合わせる） ----
    if (!preg_match('/^[A-Za-z0-9_\-]{3,25}$/', $v['login_id'])) {   // login_id varchar(25)
        $errors[] = 'ログインIDは半角英数字・_・- の3〜25文字にしてください';
    }
    if ($v['name'] === '' || mb_strlen($v['name']) > 50) {           // name varchar(50)
        $errors[] = '名前を入力してください（50文字以内）';
    }
    if (mb_strlen($v['name_kana']) > 50) {                            // name_kana varchar(50)
        $errors[] = 'ふりがなは50文字以内にしてください';
    }
    if (strlen($password) < 8) {
        $errors[] = 'パスワードは8文字以上にしてください';
    }
    if ($password !== $confirm) {
        $errors[] = '確認用パスワードが一致しません';
    }
    // 招待コード（config.php の invite_code が空でなければ必須）
    // サークル外の人が勝手に登録して、メンバーの実名を見られないようにするため
    $invite = (string)config('invite_code');
    if ($invite !== '' && !hash_equals($invite, (string)($_POST['invite_code'] ?? ''))) {
        $errors[] = '招待コードが違います（サークルの管理者に聞いてください）';
    }
    if (!$errors) {
        $st = db()->prepare('SELECT 1 FROM user_index WHERE login_id = ?');
        $st->execute([$v['login_id']]);
        if ($st->fetchColumn()) {
            $errors[] = 'そのログインIDは使われています';
        }
    }

    if (!$errors) {
        $pdo = db();
        // 管理者がまだ1人もいなければ、この人を管理者にする（最初の1人だけ）
        $hasAdmin = (bool)$pdo->query('SELECT 1 FROM user_index WHERE is_admin = 1 LIMIT 1')->fetchColumn();
        $admin = !$hasAdmin && config('first_user_is_admin') ? 1 : 0;

        $pdo->prepare('INSERT INTO user_index (login_id, name, name_kana, password_hash, is_admin) VALUES (?, ?, ?, ?, ?)')
            ->execute([$v['login_id'], $v['name'], $v['name_kana'], password_hash($password, PASSWORD_DEFAULT), $admin]);
        $userId = (int)$pdo->lastInsertId();

        // 同じ名前のメンバーがいれば自動で紐付け → マイページが使えるようになる
        link_users_to_members($pdo);
        $st = $pdo->prepare('SELECT member_id FROM user_index WHERE user_id = ?');
        $st->execute([$userId]);
        $memberId = $st->fetchColumn();

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'user_id' => $userId, 'login_id' => $v['login_id'], 'name' => $v['name'],
            'admin' => (bool)$admin, 'member_id' => $memberId ? (int)$memberId : null,
        ];
        flash('登録しました。ようこそ！' . ($admin ? '（管理者がいなかったので管理者になりました）' : ''));
        redirect('index.php');
    }
}

render_header('新規登録');
?>
<section class="auth">
    <div class="auth__hero">
        <p class="eyebrow">Join</p>
        <h1 class="display">アカウントを<br>作成する。</h1>
        <p class="muted">名前を名簿と同じ表記にすると、自分の出演履歴と自動でつながります。</p>
    </div>
    <form method="post" class="card auth__card" novalidate>
        <h2>新規登録</h2>
        <?= csrf_field() ?>
        <?php foreach ($errors as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>
        <?php if ((string)config('invite_code') !== ''): ?>
        <label class="field"><span>招待コード</span>
            <input type="text" name="invite_code" autocomplete="off" required></label>
        <?php endif; ?>
        <label class="field"><span>ログインID（半角英数字）</span>
            <input type="text" name="login_id" value="<?= h($v['login_id']) ?>" autocomplete="username" maxlength="25" required></label>
        <label class="field"><span>名前（フルネーム）</span>
            <input type="text" name="name" value="<?= h($v['name']) ?>" autocomplete="name" maxlength="50" required></label>
        <label class="field"><span>ふりがな</span>
            <input type="text" name="name_kana" value="<?= h($v['name_kana']) ?>" maxlength="50"></label>
        <label class="field"><span>パスワード（8文字以上）</span>
            <input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
        <label class="field"><span>パスワード（確認）</span>
            <input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required></label>
        <button class="btn btn--primary btn--block" type="submit">登録する</button>
        <p class="muted small center">登録済み？ <a href="login.php">ログイン</a></p>
    </form>
</section>
<?php render_footer();
