<?php
/**
 * =====================================================================
 *  account.php — アカウント設定（元の m6 の user_compile.php に当たるページ）
 * =====================================================================
 *  hidden の action で「どのフォームか」を見分けている。
 *    action=profile  … 名前（メンバーと紐付いていない人だけ）
 *    action=password … パスワード変更（今のパスワードの確認つき）
 *  メンバーと紐付いている人は、名前の代わりに「プロフィール」（送信先は member_edit.php）。
 *    名前の正は member.name で、アカウント名はそれに自動でそろう（sync_account_names()）。
 *  どのメンバーと紐付けるかは、管理者がユーザー管理（users.php）で選ぶ。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/albums.php'; // MUSIC_APPS（選べる音楽アプリの一覧）
require_once __DIR__ . '/lib/repository.php'; // profile_faculty_role_fields()
$user = require_login();

$pdo = db();
$st = $pdo->prepare('SELECT * FROM user_account WHERE user_id = ?');
$st->execute([$user['user_id']]);
$account = $st->fetch();

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 紐付いている人の名前はメンバー名で決まるので、ここでは変えさせない（HTML を書き換えて送られても弾く）
    if ($action === 'profile' && $account['member_id'] === null) {
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

    redirect('account.php');
}

// 紐付いているメンバーのプロフィール（マイページの「プロフィールを編集」をここに統合した）。
// 保存は member_edit.php に任せる（検証を1か所にまとめるため。return=account でここに戻ってくる）
$myMember = null;
if ($account['member_id'] !== null) {
    $st = $pdo->prepare('SELECT * FROM member WHERE member_id = ?');
    $st->execute([$account['member_id']]);
    $myMember = $st->fetch() ?: null;
}

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
    <?php if (!$myMember): ?>
    <form method="post" class="card form-card">
        <h2 class="section-title section-title--card">プロフィール</h2>
        <p class="muted small">まだメンバーと紐付いていません。管理者に依頼してください。（紐付くとマイページが使用できます）。</p>
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <label class="field"><span>名前</span><input name="name" value="<?= h($account['name']) ?>" maxlength="50" required></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
    <?php else: ?>
    <form method="post" action="member_edit.php" class="card form-card" id="member-profile">
        <h2 class="section-title section-title--card">プロフィール</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= (int)$myMember['member_id'] ?>">
        <input type="hidden" name="return" value="account">
        <label class="field"><span>名前</span><input name="name" value="<?= h($myMember['name']) ?>" maxlength="50" required></label>
        <label class="field"><span>ふりがな</span><input name="name_kana" value="<?= h($myMember['name_kana']) ?>" maxlength="50"></label>
        <label class="field"><span>入部年度</span><input type="number" name="entry_year" min="1950" max="2100" value="<?= (int)$myMember['entry_year'] ?: '' ?>"></label>
        <?= profile_faculty_role_fields($pdo, $myMember) ?>
        <label class="field"><span>対応するリンクを開く音楽アプリ</span>
            <select name="music_app">
                <option value="">未選択</option>
                <?php foreach (MUSIC_APPS as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $myMember['music_app'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
    <?php endif; ?>

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
