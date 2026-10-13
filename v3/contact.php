<?php
/**
 * =====================================================================
 *  contact.php — 問い合わせ・要望
 * =====================================================================
 *  送られた内容は inquiry テーブルに保存して、通知先メールアドレスを登録している管理者全員にメールで送る。
 *    通知先は管理者だけがアカウント設定（account.php）で登録できる。
 *    メールが届かなくても（XAMPP は初期状態では送れない・アドレス未登録など）、DB には残るので
 *    管理者はこのページの下の一覧で読める。
 *  名前を出すか匿名かは送る人が選ぶ。匿名なら user_id も保存しない（DB を見ても誰かわからない）。
 *  送り過ぎ防止: 1時間に CONTACT_PER_HOUR 件まで（いたずらで管理者のメールが埋まらないように）。
 *    匿名だと DB で数えられないので、セッションに送った時刻を覚えて数える
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$pdo = db();

const CONTACT_PER_HOUR = 5;
const CONTACT_CATEGORIES = ['要望', '不具合の報告', 'データの間違い', 'その他'];

$category = '';
$body = '';
$anonymous = false;
if (is_post()) {
    verify_csrf();
    $category = (string)($_POST['category'] ?? '');
    $body = trim((string)($_POST['body'] ?? ''));
    $anonymous = ($_POST['anonymous'] ?? '') === '1';

    // 1時間より前のものは捨ててから数える。名前を出して送った分は DB でも数える（ログアウトでセッションを消されても効くように）
    $recent = array_filter($_SESSION['contact_sent'] ?? [], fn($t) => $t > time() - 3600);
    $st = $pdo->prepare('SELECT COUNT(*) FROM inquiry WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $st->execute([$user['user_id']]);
    $sentCount = max(count($recent), (int)$st->fetchColumn());

    if (!in_array($category, CONTACT_CATEGORIES, true)) {
        flash('種類を選んでください', 'error');
    } elseif ($body === '' || mb_strlen($body) > 2000) {
        flash('内容は1〜2000文字で入力してください', 'error');
    } elseif ($sentCount >= CONTACT_PER_HOUR) {
        flash('送信回数が多すぎます。しばらくたってからもう一度送ってください', 'error');
    } else {
        $pdo->prepare('INSERT INTO inquiry (user_id, category, body) VALUES (?, ?, ?)')
            ->execute([$anonymous ? null : $user['user_id'], $category, $body]);
        $inquiryId = (int)$pdo->lastInsertId();
        $recent[] = time();
        $_SESSION['contact_sent'] = array_values($recent);

        if (send_inquiry_mail($pdo, $anonymous ? null : $user, $category, $body)) {
            $pdo->prepare('UPDATE inquiry SET mailed = 1 WHERE inquiry_id = ?')->execute([$inquiryId]);
        }
        flash('送信しました。お寄せいただきありがとうございます！');
        redirect('contact');
    }
}

/**
 * 通知先を登録している管理者全員にメールを送る。1人以上に送れたら true
 *   件名・差出人は固定の文字だけで作る（ユーザーの入力をヘッダーに入れると、改行を混ぜて
 *   宛先を勝手に足される「メールヘッダーインジェクション」ができてしまう）。入力は本文にだけ入れる
 *   $user が null なら匿名（名前を書かない）
 */
function send_inquiry_mail(PDO $pdo, ?array $user, string $category, string $body): bool
{
    $from = (string)(config('mail_from') ?? '');
    if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $to = $pdo->query('SELECT email FROM user_account WHERE is_admin = 1 AND email IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    if (!$to) {
        return false;
    }

    $subject = '【AbbeyRoad.online】問い合わせ・要望が届きました';
    $text = "種類: {$category}\n"
        . '送信元: ' . ($user ? "{$user['name']}（ログインID: {$user['login_id']}）" : '匿名') . "\n"
        . '日時: ' . date('Y-m-d H:i') . "\n\n"
        . $body . "\n";
    mb_language('uni'); // UTF-8 で送る
    $sent = false;
    foreach ($to as $addr) {
        // 1人ずつ送る（To に全員並べると、管理者同士のアドレスが見えてしまう）
        $sent = @mb_send_mail($addr, $subject, $text, 'From: ' . $from) || $sent;
    }
    return $sent;
}

// 管理者には、届いた問い合わせの一覧も出す（メールが届かなかったときの控え）
$inquiries = [];
if ($user['admin']) {
    $inquiries = $pdo->query(
        'SELECT i.*, u.name FROM inquiry i LEFT JOIN user_account u ON u.user_id = i.user_id
         ORDER BY i.inquiry_id DESC LIMIT 50'
    )->fetchAll();
}

render_header('問い合わせ・要望');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Contact</p>
        <h1 class="display">問い合わせ・要望</h1>
        <p class="muted">サイトへの要望や不具合、データの間違いなどを匿名で管理者に送信できます。</p>
    </div>
</section>

<div class="settings">
    <form method="post" class="card form-card">
        <?= csrf_field() ?>
        <label class="field"><span>種類</span>
            <select name="category" required>
                <option value="">選んでください</option>
                <?php foreach (CONTACT_CATEGORIES as $c): ?>
                    <option value="<?= h($c) ?>"<?= $category === $c ? ' selected' : '' ?>><?= h($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>内容（2000文字まで）</span>
            <textarea name="body" rows="8" maxlength="2000" required><?= h($body) ?></textarea>
        </label>
        <div class="field">
            <span>送信元の表示</span>
            <div class="checks">
            <label class="check"><input type="radio" name="anonymous" value="0"<?= $anonymous ? '' : ' checked' ?>> 送信元を通知する（<?= h($user['name']) ?>）</label>
            <label class="check"><input type="radio" name="anonymous" value="1"<?= $anonymous ? ' checked' : '' ?>> 匿名</label>
            </div>
        </div>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit">送信</button></div>
    </form>

    <?php if ($user['admin']): ?>
    <div class="card form-card">
        <h2 class="section-title section-title--card">届いた問い合わせ <span class="tag">管理者</span></h2>
        <?php if (!$inquiries): ?>
            <p class="muted small">まだありません</p>
        <?php endif; ?>
        <?php foreach ($inquiries as $q): ?>
            <div class="inquiry">
                <p class="muted small"><?= h(substr($q['created_at'], 0, 16)) ?> · <?= $q['name'] === null ? '匿名' : h($q['name']) ?> · <span class="tag"><?= h($q['category']) ?></span><?= $q['mailed'] ? '' : ' · メール未送信' ?></p>
                <p><?= nl2br(h($q['body'])) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php render_footer();
