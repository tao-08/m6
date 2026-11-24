<?php
/**
 * =====================================================================
 *  board_reports.php — 掲示板の通報と、匿名・名義の本名の開示請求（管理者のみ）
 * =====================================================================
 *  通報が2件になった匿名・名義の書き込みは、開示請求（board_post.disclosure = 1）としてここに出る。
 *    許可 … disclosure = 2。その書き込みの本名が全員に出る（board_thread.php）。書き込んだ人にお知らせする。取り消せない
 *    却下 … disclosure = 3。匿名のまま
 *  通報した人と理由は管理者にだけ見える（捨て垢の通報を見分けるため）。値は全部 h() を通して出す。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // fmt_datetime()
require __DIR__ . '/lib/board.php';
$admin = require_admin();
$pdo = db();

if (is_post()) {
    verify_csrf();
    $postId = (int)($_POST['post_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['approve', 'reject'], true)) {
        // 請求中（disclosure = 1）のものだけ。二重に押されても1回しか通らない
        $st = $pdo->prepare('UPDATE board_post SET disclosure = ? WHERE post_id = ? AND disclosure = 1');
        $st->execute([$action === 'approve' ? 2 : 3, $postId]);
        if ($st->rowCount() && $action === 'approve') {
            $st = $pdo->prepare('SELECT p.user_id, p.thread_id, t.title FROM board_post p JOIN board_thread t ON t.thread_id = p.thread_id WHERE p.post_id = ?');
            $st->execute([$postId]);
            if (($p = $st->fetch()) && $p['user_id'] !== null) {
                $pdo->prepare('INSERT INTO notice (user_id, target_user_id, category_id, is_important, title, body, link_url) VALUES (?, ?, ?, 1, ?, ?, ?)')
                    ->execute([(int)$admin['user_id'], (int)$p['user_id'], board_notice_category(), 'あなたの投稿の名前が開示されました',
                        '[[board_thread?id=' . (int)$p['thread_id'] . '|' . $p['title'] . ']] のあなたの書き込みは、通報により名前が開示されました',
                        'board_thread?id=' . (int)$p['thread_id'] . '&page=' . board_post_page((int)$p['thread_id'], $postId) . '#p' . $postId]);
            }
        }
        flash($st->rowCount() ? ($action === 'approve' ? '開示しました' : '却下しました') : '処理できませんでした', $st->rowCount() ? 'success' : 'error');
    }
    redirect('board_reports');
}

// 請求中を先に、そのあと処理済み（新しい通報の順。50件まで）
$st = $pdo->query('SELECT p.post_id, p.thread_id, p.body, p.anonymous, p.display_name, p.disclosure, p.deleted_at, t.title, u.name AS author,
        (SELECT MAX(r.created_at) FROM board_report r WHERE r.post_id = p.post_id) AS last_report
    FROM board_post p
    JOIN board_thread t ON t.thread_id = p.thread_id
    LEFT JOIN user_account u ON u.user_id = p.user_id
    WHERE p.disclosure > 0
    ORDER BY (p.disclosure = 1) DESC, last_report DESC LIMIT 50');
$rows = $st->fetchAll();
$reports = [];
if ($rows) {
    $in = implode(',', array_fill(0, count($rows), '?'));
    $st = $pdo->prepare("SELECT r.post_id, r.reason, r.created_at, u.name FROM board_report r LEFT JOIN user_account u ON u.user_id = r.user_id
        WHERE r.post_id IN ($in) ORDER BY r.created_at");
    $st->execute(array_map(static fn($r) => (int)$r['post_id'], $rows));
    foreach ($st->fetchAll() as $r) {
        $reports[(int)$r['post_id']][] = $r;
    }
}
const DISCLOSURE_LABELS = [1 => '請求中', 2 => '開示済み', 3 => '却下'];

render_header('通報の管理');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Admin</p>
        <h1 class="display">通報の管理</h1>
    </div>
</section>

<?php if (!$rows): ?>
    <div class="empty card"><p class="empty__title">開示請求はありません</p></div>
<?php else: ?>
    <div class="board-posts">
    <?php foreach ($rows as $r): ?>
        <?php $link = 'board_thread?id=' . (int)$r['thread_id'] . '&page=' . board_post_page((int)$r['thread_id'], (int)$r['post_id']) . '#p' . (int)$r['post_id']; ?>
        <article class="card board-post">
            <div class="muted small board-post__meta">
                <span class="tag"><?= h(DISCLOSURE_LABELS[(int)$r['disclosure']]) ?></span>
                <a href="<?= h($link) ?>"><?= h($r['title']) ?></a>
                <strong><?= h($r['anonymous'] ? '匿名' : (string)$r['display_name']) ?></strong>
                <span>→ <?= h($r['author'] ?? '退会ユーザー') ?></span>
                <?php if ($r['deleted_at'] !== null): ?><span>削除済み</span><?php endif; ?>
            </div>
            <p class="board-post__body"><?= nl2br(h(mb_substr((string)$r['body'], 0, 300))) ?></p>
            <ul class="modal__list small">
                <?php foreach ($reports[(int)$r['post_id']] ?? [] as $rep): ?>
                    <li><?= h($rep['name'] ?? '退会ユーザー') ?> <span class="muted"><?= h(BOARD_REPORT_REASONS[(int)$rep['reason']] ?? '') ?>　<?= h(fmt_datetime($rep['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if ((int)$r['disclosure'] === 1): ?>
            <div class="form-actions">
                <form method="post" onsubmit="return confirm('名前を全員に開示します。取り消せません。開示しますか？')">
                    <?= csrf_field() ?><input type="hidden" name="post_id" value="<?= (int)$r['post_id'] ?>"><input type="hidden" name="action" value="approve">
                    <button class="btn btn--primary btn--sm" type="submit">許可</button>
                </form>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="post_id" value="<?= (int)$r['post_id'] ?>"><input type="hidden" name="action" value="reject">
                    <button class="btn btn--ghost btn--sm" type="submit">却下</button>
                </form>
            </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php render_footer();
