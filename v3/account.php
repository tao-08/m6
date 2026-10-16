<?php
/**
 * =====================================================================
 *  account.php — アカウント設定（元の m6 の user_compile.php に当たるページ）
 * =====================================================================
 *  hidden の action で「どのフォームか」を見分けている。
 *    action=profile  … 名前（メンバーと紐付いていない人だけ）
 *    action=password … パスワード変更（今のパスワードの確認つき）
 *    action=email    … 問い合わせの通知先メールアドレス（管理者だけ。contact.php）
 *    action=suggest  … 退会した人をメンバー名の候補（サジェスト）に出さないか（member_name_choices()）
 *    action=back_button … 左下の戻るボタンを出すか（assets/app.js の setupBackButton）
 *    action=push_prefs  … アプリ通知（プッシュ）で受け取る種類: 掲示板 / 重要なお知らせ / その他のお知らせ（lib/push.php）
 *  メンバーと紐付いている人は、名前の代わりに「プロフィール」（送信先は member_edit.php）。
 *    名前の正は member.name で、アカウント名はそれに自動でそろう（sync_account_names()）。
 *  どのメンバーと紐付けるかは、管理者がユーザー管理（users.php）で選ぶ。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/albums.php'; // MUSIC_APPS（選べる音楽アプリの一覧）
require_once __DIR__ . '/lib/repository.php'; // profile_faculty_role_fields()
require_once __DIR__ . '/lib/push.php'; // push_enabled() / push_public_key()（アプリ通知）
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

    // 一般ユーザーのメールアドレスは使い道がないので集めない。HTML を書き換えて送られても管理者以外は弾く
    if ($action === 'email' && $account['is_admin']) {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            flash('メールアドレスの形が正しくありません', 'error');
        } else {
            $pdo->prepare('UPDATE user_account SET email = ? WHERE user_id = ?')
                ->execute([$email === '' ? null : $email, $user['user_id']]);
            flash($email === '' ? '通知先のメールアドレスを消しました' : '通知先のメールアドレスを保存しました');
        }
    }

    // チェックを外すと何も送られないので、フォームに hidden の 0 を先に置いている（チェックすると 1 で上書き）
    // チェックボックスは hidden の 0 → チェックで 1
    if ($action === 'prefs') {
        $pdo->prepare('UPDATE user_account SET hide_retired_suggest = ?, show_back_button = ?, count_upcoming = ? WHERE user_id = ?')
            ->execute([
                (int)(($_POST['hide_retired'] ?? '') === '1'),
                (int)(($_POST['show_back_button'] ?? '') === '1'),
                (int)(($_POST['count_upcoming'] ?? '') === '1'),
                $user['user_id'],
            ]);
        flash('設定を保存しました');
    }

    // アプリ通知（プッシュ通知）で受け取る種類。この端末で受け取るかどうかは、フォームではなくスイッチ（assets/app.js の setupPush）
    if ($action === 'push_prefs') {
        $pdo->prepare('UPDATE user_account SET push_board = ?, push_important = ?, push_notice = ? WHERE user_id = ?')
            ->execute([
                (int)(($_POST['push_board'] ?? '') === '1'),
                (int)(($_POST['push_important'] ?? '') === '1'),
                (int)(($_POST['push_notice'] ?? '') === '1'),
                $user['user_id'],
            ]);
        flash('通知設定を保存しました');
    }

    redirect('account');
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
    <form method="post" class="card form-card" data-unsaved>
        <h2 class="section-title section-title--card">プロフィール</h2>
        <p class="muted small">まだメンバーと紐付いていません。管理者に依頼してください。（紐付くとマイページが使用できます）。</p>
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <label class="field"><span>名前</span><input name="name" value="<?= h($account['name']) ?>" maxlength="50" required></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
    <?php else: ?>
    <form method="post" action="member_edit" class="card form-card" id="member-profile" data-unsaved>
        <h2 class="section-title section-title--card">プロフィール</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= (int)$myMember['member_id'] ?>">
        <input type="hidden" name="return" value="account">
        <label class="field"><span>名前</span><input name="name" value="<?= h($myMember['name']) ?>" maxlength="50" required></label>
        <label class="field"><span>ふりがな</span><input name="name_kana" value="<?= h($myMember['name_kana']) ?>" maxlength="50"></label>
        <label class="field"><span>入部年度</span><input type="number" name="entry_year" min="1950" max="2100" value="<?= (int)$myMember['entry_year'] ?: '' ?>"<?= $account['is_admin'] ? '' : ' disabled title="管理者のみ変更可"' ?>></label>
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

    <form method="post" class="card form-card" data-unsaved>
        <h2 class="section-title section-title--card">パスワード変更</h2>
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <label class="field"><span>今のパスワード</span><input type="password" name="current" autocomplete="current-password" required></label>
        <label class="field"><span>新しいパスワード（8文字以上）</span><input type="password" name="new" autocomplete="new-password" minlength="8" required></label>
        <label class="field"><span>新しいパスワード（確認）</span><input type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">変更</button></div>
    </form>
    <?php if ($account['is_admin']): ?>
    <form method="post" class="card form-card" data-unsaved>
        <h2 class="section-title section-title--card">問い合わせの通知先 <span class="tag">管理者</span></h2>
        <p class="muted small">「問い合わせ・要望」から送られた内容が、このアドレスにメールで届きます。空にすると届かなくなります（サイトの中には残ります）。</p>
        <?= csrf_field() ?><input type="hidden" name="action" value="email">
        <label class="field"><span>メールアドレス</span><input type="email" name="email" value="<?= h($account['email'] ?? '') ?>" maxlength="254" autocomplete="email"></label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
    <?php endif; ?>
    <?php if (push_enabled()): ?>
    <div class="card form-card push-card">
        <h2 class="section-title section-title--card"><?= icon('notifications_active') ?> アプリ通知</h2>
        <p class="muted small push-card__hint">Webアプリ化していない場合は、右上のメニューの「ホーム画面に追加する」からWebアプリとしてインストールすると利用できます。</p>
        <!-- この端末で受け取る / 受け取らない。押した瞬間に登録・解除する（assets/app.js の setupPush）。下の保存ボタンとは別 -->
        <label class="check" data-push="<?= h(push_public_key()) ?>" title="この端末でアプリ通知を受け取る" hidden>
            <input type="checkbox" data-push-toggle> <?= icon('smartphone') ?> アプリ通知オン
        </label>
        <form method="post" data-unsaved data-push-form>
            <?= csrf_field() ?><input type="hidden" name="action" value="push_prefs">
            <label class="check" title="掲示板の通知設定で通知される書き込み"><input type="hidden" name="push_board" value="0"><input type="checkbox" name="push_board" value="1"<?= $account['push_board'] ? ' checked' : '' ?>> <?= icon('forum') ?> 掲示板</label>
            <label class="check"><input type="hidden" name="push_important" value="0"><input type="checkbox" name="push_important" value="1"<?= $account['push_important'] ? ' checked' : '' ?>> <?= icon('priority_high') ?> 重要なお知らせ</label>
            <label class="check"><input type="hidden" name="push_notice" value="0"><input type="checkbox" name="push_notice" value="1"<?= $account['push_notice'] ? ' checked' : '' ?>> <?= icon('campaign') ?> その他のお知らせ</label>
            <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
        </form>
    </div>
    <?php endif; ?>
    <form method="post" class="card form-card" data-unsaved>
        <h2 class="section-title section-title--card">表示・集計</h2>
        <?= csrf_field() ?><input type="hidden" name="action" value="prefs">
        <label class="check"><input type="hidden" name="hide_retired" value="0"><input type="checkbox" name="hide_retired" value="1"<?= $account['hide_retired_suggest'] ? ' checked' : '' ?>> 退会した人を入力時の候補に出さない</label>
        <p class="muted small">名前の編集時に出る候補から退会した人を表示しません。</p>
        <label class="check"><input type="hidden" name="show_back_button" value="0"><input type="checkbox" name="show_back_button" value="1"<?= $account['show_back_button'] ? ' checked' : '' ?>> 戻るボタンを表示する</label>
        <p class="muted small">画面左下の戻るボタンを表示します。</p>
        <label class="check"><input type="hidden" name="count_upcoming" value="0"><input type="checkbox" name="count_upcoming" value="1"<?= $account['count_upcoming'] ? ' checked' : '' ?>> 予定されているライブを集計に含む</label>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
</div>
<?php render_footer();
