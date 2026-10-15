<?php
/**
 * =====================================================================
 *  notices.php — お知らせ（管理者が書き、全員が読む）
 * =====================================================================
 *  開いた時点で、表示した分を既読にする（notice_read に行を足す。行がある＝既読）。
 *  投稿・削除は管理者だけ（require_admin 相当を POST の中で確認）。
 *  既読にするのは「このページを開いたとき」なので、新着だけ NEW の目印を出してから既読にする。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$pdo = db();

if (is_post()) {
    verify_csrf();
    if (!$user['admin']) {
        http_response_code(403);
        exit('管理者のみ実行できます。');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM notice WHERE notice_id = ?')->execute([(int)($_POST['notice_id'] ?? 0)]);
        flash('削除しました');
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100 || $body === '' || mb_strlen($body) > 2000) {
            flash('タイトルは1〜100文字、本文は1〜2000文字で入力してください', 'error');
        } else {
            $pdo->prepare('INSERT INTO notice (user_id, title, body) VALUES (?, ?, ?)')
                ->execute([$user['user_id'], $title, $body]);
            flash('投稿しました');
        }
    }
    redirect('notices');
}

// 未読を先に調べてから既読にする（NEW の目印用）。行が無いものが未読
$st = $pdo->prepare(
    'SELECT n.notice_id, n.title, n.body, n.created_at, (r.user_id IS NULL) AS is_new
       FROM notice n
       LEFT JOIN notice_read r ON r.notice_id = n.notice_id AND r.user_id = ?
      ORDER BY n.notice_id DESC LIMIT 100'
);
$st->execute([$user['user_id']]);
$notices = $st->fetchAll();

// 表示した分だけ既読にする（101件目以降まで勝手に既読にしない）
$mark = $pdo->prepare('INSERT IGNORE INTO notice_read (user_id, notice_id) VALUES (?, ?)');
foreach ($notices as $n) {
    $mark->execute([$user['user_id'], (int)$n['notice_id']]);
}

render_header('お知らせ', 'notices');
?>
<h1 class="display display--sm"><?= icon('campaign') ?> お知らせ</h1>

<?php if ($user['admin']): ?>
<form method="post" class="card form-card"<?= unsaved_attr() ?>>
    <?= csrf_field() ?>
    <label class="field"><span>タイトル</span>
        <input type="text" name="title" maxlength="100" required>
    </label>
    <label class="field"><span>本文</span>
        <textarea name="body" rows="5" maxlength="2000" required></textarea>
    </label>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?> 投稿</button></div>
</form>
<?php endif; ?>

<?php if (!$notices): ?>
    <p class="muted">お知らせはありません</p>
<?php endif; ?>
<?php foreach ($notices as $n): ?>
    <article class="card form-card">
        <h2 class="section-title section-title--card">
            <?= h($n['title']) ?>
            <?= $n['is_new'] ? '<span class="tag">NEW</span>' : '' ?>
        </h2>
        <p class="muted small"><?= h(substr($n['created_at'], 0, 16)) ?></p>
        <p><?= nl2br(h($n['body'])) ?></p>
        <?php if ($user['admin']): ?>
        <form method="post" onsubmit="return confirm('削除しますか？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notice_id" value="<?= (int)$n['notice_id'] ?>">
            <button class="btn btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('delete') ?></button>
        </form>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
<?php render_footer();
