<?php
/**
 * =====================================================================
 *  contact_guest.php — ログインできない人の問い合わせ（ログイン不要）
 * =====================================================================
 *  パスワード再設定で ログインID・招待コードがわからない人が、管理者に連絡するための入口。
 *  氏名と返信先（メールアドレスなど）を書いてもらい、inquiry に保存して管理者にメールを送る（contact.php と同じ）。
 *  ログインしていない人から誰でも送れてしまうので、いたずら・連投で管理者のメールが埋まらないように
 *    同じ IP から 1時間に GUEST_PER_HOUR 件までにする（回数は login_attempt に '#contact' として記録して数える）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/inquiry.php';

if (current_user()) {
    redirect('contact'); // ログイン中の人は通常の問い合わせへ
}

const GUEST_PER_HOUR = 3;
const GUEST_CATEGORY = 'ログイン不可';

$pdo = db();
$name = '';
$contact = '';
$body = '';
$error = '';
if (is_post()) {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));

    $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempt WHERE login_id = '#contact' AND ip = ? AND attempted_at > NOW() - INTERVAL 1 HOUR");
    $st->execute([client_ip()]);

    if ($name === '' || mb_strlen($name) > 50) {
        $error = '氏名は1〜50文字で入力してください';
    } elseif ($contact === '' || mb_strlen($contact) > 100) {
        $error = '連絡先は1〜100文字で入力してください';
    } elseif ($body === '' || mb_strlen($body) > 1000) {
        $error = '内容は1〜1000文字で入力してください';
    } elseif ((int)$st->fetchColumn() >= GUEST_PER_HOUR) {
        $error = '送信回数が多すぎます。しばらくたってからもう一度送ってください';
    } else {
        $pdo->prepare('INSERT INTO inquiry (user_id, sender_name, contact, category, body) VALUES (NULL, ?, ?, ?, ?)')
            ->execute([$name, $contact, GUEST_CATEGORY, $body]);
        $inquiryId = (int)$pdo->lastInsertId();
        record_failure('#contact'); // 送信回数の記録（失敗ではないが、IP ごとの数え上げに login_attempt を借りる）

        if (send_inquiry_mail($pdo, "{$name}（連絡先: {$contact}）", GUEST_CATEGORY, $body)) {
            $pdo->prepare('UPDATE inquiry SET mailed = 1 WHERE inquiry_id = ?')->execute([$inquiryId]);
        }
        flash('送信しました。管理者からの連絡をお待ちください');
        redirect('login');
    }
}

render_header('問い合わせ');
?>
<section class="auth">
    <div class="auth__hero">
        <h1 class="auth__logo">
            <img src="assets/online.png" alt="AbbeyRoad.online" class="brand__logo--light" width="146" height="40">
            <img src="assets/logo-dark.png" alt="" class="brand__logo--dark" width="146" height="40">
        </h1>
    </div>
    <form method="post" action="contact_guest" class="card auth__card" novalidate>
        <h2>問い合わせ</h2>
        <?= csrf_field() ?>
        <?php if ($error): ?><div class="flash flash--error"><?= h($error) ?></div><?php endif; ?>
        <label class="field">
            <span>氏名</span>
            <input autocomplete="off" type="text" name="name" value="<?= h($name) ?>" maxlength="50" required autofocus>
        </label>
        <label class="field">
            <span>連絡先（メールアドレスなど）</span>
            <input autocomplete="off" type="text" name="contact" value="<?= h($contact) ?>" maxlength="100" required>
        </label>
        <label class="field">
            <span>内容（1000文字まで）</span>
            <textarea name="body" rows="6" maxlength="1000" required><?= h($body) ?></textarea>
        </label>
        <button class="btn btn--primary btn--block" type="submit">送信</button>
        <p class="muted small center"><a href="password_reset">パスワード再設定</a></p>
    </form>
</section>
<?php render_footer();
