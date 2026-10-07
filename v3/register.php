<?php
/**
 * =====================================================================
 *  register.php — 新規登録
 * =====================================================================
 *  入力チェック → login_id の重複チェック → password_hash() して INSERT → そのままログイン状態にする。
 *
 *  入学年度は必須。保存先は user_account ではなく member.entry_year（学年の計算は member 側で一元管理する）。
 *    ・名前が一致するメンバーがいる → そのメンバーに紐付けて、入学年度を入力値で上書き
 *    ・いない                     → member を新しく作って紐付ける
 *    ・一致するメンバーが別のアカウントに紐付き済み → 登録エラー（なりすまし・二重登録の防止）
 *
 *  ⚠ パスワードは絶対に平文（そのまま）で保存しない。必ず password_hash() を通す。
 *    DB が流出しても、ハッシュからは元のパスワードを復元できない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';

if (current_user()) {
    redirect('index.php');
}

// 今年度（4月始まり）。入学年度の選択肢の上限に使う
$thisYear = current_fiscal_year();
$entryYears = range($thisYear, $thisYear - 30); // セレクトの選択肢（新しい順）

$errors = [];
$v = ['login_id' => '', 'name' => '', 'entry_year' => '']; // 入力値（エラー時にフォームへ戻す用）
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
    // 入学年度: 選択肢にある年度だけ受け付ける（改造されたリクエストで 9999 などを送られても弾く）
    $entryYear = filter_var($v['entry_year'], FILTER_VALIDATE_INT);
    if (!is_int($entryYear) || !in_array($entryYear, $entryYears, true)) {
        $errors[] = '入学年度を選んでください';
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
        $st = db()->prepare('SELECT 1 FROM user_account WHERE login_id = ?');
        $st->execute([$v['login_id']]);
        if ($st->fetchColumn()) {
            $errors[] = 'そのログインIDは使われています';
        }
    }

    // 同じ名前のメンバーが、もう別のアカウントに紐付いていないか
    //   user_account.member_id は UNIQUE なので、1人のメンバーに2つのアカウントは紐付けられない。
    //   ここで先に確認しておかないと、INSERT した後で紐付けに失敗して「入学年度がどこにも保存されない」状態になる。
    $memberIndex = [];
    $matched = null;
    if (!$errors) {
        $memberIndex = load_member_index(db());
        $matched = $memberIndex[member_key($v['name'])] ?? null; // 表記ゆれ（髙/高、空白）を吸収して探す
        if ($matched) {
            $st = db()->prepare('SELECT 1 FROM user_account WHERE member_id = ?');
            $st->execute([$matched['id']]);
            if ($st->fetchColumn()) {
                $errors[] = '「' . $matched['name'] . '」さんは別のアカウントで登録済みです（心当たりがなければ管理者に連絡してください）';
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        // 管理者がまだ1人もいなければ、この人を管理者にする（最初の1人だけ）
        $hasAdmin = (bool)$pdo->query('SELECT 1 FROM user_account WHERE is_admin LIMIT 1')->fetchColumn();
        $admin = !$hasAdmin && config('first_user_is_admin') ? 1 : 0;

        // アカウント作成・メンバー作成・入学年度の保存は「全部成功」か「全部なし」にしたいのでトランザクションにする。
        // 途中で失敗したら rollBack() で、アカウントだけできてメンバーが無い…という中途半端な状態を残さない。
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO user_account (login_id, name, password_hash, is_admin) VALUES (?, ?, ?, ?)')
                ->execute([$v['login_id'], $v['name'], password_hash($password, PASSWORD_DEFAULT), $admin]);
            $userId = (int)$pdo->lastInsertId();

            // 名前が一致するメンバーがいればそれ、いなければ新しく作る → マイページや集計の学年が使えるようになる
            $memberId = $matched ? $matched['id'] : find_or_create_member($pdo, $memberIndex, $v['name']);
            // 入学年度は入力値で上書きする（本人の申告を正とする）
            $pdo->prepare('UPDATE member SET entry_year = ? WHERE member_id = ?')->execute([$entryYear, $memberId]);
            $pdo->prepare('UPDATE user_account SET member_id = ? WHERE user_id = ?')->execute([$memberId, $userId]);
            // 表記ゆれで既存メンバーと一致したときは、アカウント名をメンバー名にそろえる
            sync_account_names($pdo, $memberId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e; // エラーはそのまま上に投げる（bootstrap のエラー表示に任せる）
        }

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
        <p class="muted">名前を名簿と同じ表記にすると、自分の出演履歴と自動でつながります。入学年度は集計ページの学年の判定に使います。</p>
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
        <label class="field"><span>入学年度</span>
            <select name="entry_year" required>
                <option value="">選んでください</option>
                <?php foreach ($entryYears as $y): ?>
                    <option value="<?= $y ?>"<?= (string)$y === $v['entry_year'] ? ' selected' : '' ?>><?= $y ?>年度</option>
                <?php endforeach; ?>
            </select></label>
        <label class="field"><span>パスワード（8文字以上）</span>
            <input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
        <label class="field"><span>パスワード（確認）</span>
            <input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required></label>
        <button class="btn btn--primary btn--block" type="submit">登録する</button>
        <p class="muted small center">登録済み？ <a href="login.php">ログイン</a></p>
    </form>
</section>
<?php render_footer();
