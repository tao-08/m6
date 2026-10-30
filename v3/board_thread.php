<?php
/**
 * =====================================================================
 *  board_thread.php?id=スレID — 掲示板のスレの中身と返信
 * =====================================================================
 *  見られる人の決まりは lib/board.php の board_can_view()。見られないスレは「見つかりません」にする（あること自体を隠す）。
 *  削除は本人と管理者。行は消さずに deleted_at / deleted_by を入れるだけ。
 *    一般の人には「削除されました」とだけ出し、管理者には中身と、誰がいつ消したかを出す。
 *  スレの削除は自由スレだけ（現役・同期スレは消せない）。立てた人と管理者。
 *  書き込みは100件ごとにページを分ける（?page=。無ければ最新ページ。番号はページをまたいで通し）。
 *  開いた時点で、開いているページの最後の書き込みまで既読にする（board_read）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/board.php';
require_once __DIR__ . '/lib/push.php'; // board_push_post()（アプリ通知）
require_once __DIR__ . '/lib/repository.php'; // like_button()
require_once __DIR__ . '/lib/attach_image.php'; // 書き込みの添付画像（1枚）
$user = require_login();
$pdo = db();
$uid = (int)$user['user_id'];

const REPLY_MAX = 10; // 1つの書き込みで返信できる書き込みの数

$id = (int)($_GET['id'] ?? $_POST['thread_id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, c.name AS category_name, c.color AS category_color, d.name AS deleted_by_name
    FROM board_thread t
    LEFT JOIN board_category c ON c.category_id = t.category_id
    LEFT JOIN user_account d ON d.user_id = t.deleted_by
    WHERE t.thread_id = ?');
$st->execute([$id]);
$thread = $st->fetch();
if (!$thread || !board_can_view($user, $thread)) {
    flash('スレッドが見つかりません', 'error');
    redirect('board');
}
$back = 'board_thread?id=' . $id;

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($thread['deleted_at'] !== null) {
        flash('削除済みのスレッドです', 'error');
    } elseif ($action === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        $uploads = array_slice(live_image_uploads($_FILES['images'] ?? null), 0, ATTACH_KINDS['board']['max']);
        if (($body === '' && !$uploads) || mb_strlen($body) > 2000) {
            flash('2000文字以内で入力してください（画像のみも可）', 'error');
        } elseif (($aliasError = posted_alias_error($user)) !== null) { // なりすまし防止（他の部員の名前の名義は不可。lib/repository.php）
            flash($aliasError, 'error');
        } else {
            // 返信先: このスレの書き込みだけ（HTML を書き換えて他のスレの ID を送られても入れない）。最大 REPLY_MAX 件
            $to = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['reply_to'] ?? [])), static fn($v) => $v > 0)));
            $to = array_slice($to, 0, REPLY_MAX);
            if ($to) {
                $in = implode(',', array_fill(0, count($to), '?'));
                $st = $pdo->prepare("SELECT post_id FROM board_post WHERE thread_id = ? AND post_id IN ($in)");
                $st->execute([$id, ...$to]);
                $to = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
            $newFiles = [];
            $pdo->beginTransaction();
            try {
                $postId = board_add_post($id, $uid, $body, $anonymous = ($_POST['anonymous'] ?? '') === '1', posted_display_name($anonymous, $user));
                $ins = $pdo->prepare('INSERT INTO board_post_reply (post_id, reply_to_post_id) VALUES (?, ?)');
                foreach ($to as $toId) {
                    $ins->execute([$postId, $toId]);
                }
                attach_add($pdo, 'board', $postId, $uploads, $uid, $newFiles);
                $pdo->commit();
            } catch (RuntimeException $e) { // 画像のエラー（形式・大きさなど）。書き込みごと取り消す
                $pdo->rollBack();
                array_map('live_image_unlink', $newFiles);
                flash($e->getMessage(), 'error');
                redirect($back);
            }
            board_push_post($id, $postId); // アプリ通知（lib/push.php）。応答を返し終わってから送る
            $back .= '&page=' . board_post_page($id, $postId) . '#p' . $postId; // 書き込みは最後のページに入る
        }
    } elseif ($action === 'delete_post') {
        // 本人か管理者だけ。条件を UPDATE の WHERE に入れて、他人の書き込みの ID を送られても消えないようにする
        $st = $pdo->prepare('UPDATE board_post SET deleted_at = NOW(), deleted_by = ?
            WHERE post_id = ? AND thread_id = ? AND deleted_at IS NULL AND (user_id = ? OR ? = 1)');
        $st->execute([$uid, (int)($_POST['post_id'] ?? 0), $id, $uid, $user['admin'] ? 1 : 0]);
        flash($st->rowCount() ? '削除しました' : '削除できませんでした', $st->rowCount() ? 'success' : 'error');
        if (($backPage = (int)($_POST['page'] ?? 0)) > 0) {
            $back .= '&page=' . $backPage; // 削除したページに戻る
        }
    } elseif ($action === 'delete_thread') {
        $st = $pdo->prepare("UPDATE board_thread SET deleted_at = NOW(), deleted_by = ?
            WHERE thread_id = ? AND kind = 'free' AND deleted_at IS NULL AND (user_id = ? OR ? = 1)");
        $st->execute([$uid, $id, $uid, $user['admin'] ? 1 : 0]);
        if ($st->rowCount()) {
            flash('削除しました');
            redirect('board');
        }
        flash('削除できませんでした', 'error');
    }
    redirect($back);
}

// 書き込み番号（post_id → 1 から数えた番号。削除された書き込みも数える。ページをまたいでも変わらない）
$st = $pdo->prepare('SELECT post_id FROM board_post WHERE thread_id = ? ORDER BY post_id');
$st->execute([$id]);
$numbers = [];
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $pid) {
    $numbers[(int)$pid] = $i + 1;
}
// ページ分け。?page= が無ければ最新（最後）のページ
$pages = max(1, (int)ceil(count($numbers) / BOARD_PER_PAGE));
$page = isset($_GET['page']) ? min(max(1, (int)$_GET['page']), $pages) : $pages;
$offset = ($page - 1) * BOARD_PER_PAGE;
$pageUrl = static fn(int $n): string => 'board_thread?id=' . $id . '&page=' . $n;

$st = $pdo->prepare('SELECT p.post_id, p.user_id, p.body, p.anonymous, p.display_name, p.created_at, p.deleted_at, u.name AS author, u.member_id AS author_member_id, d.name AS deleted_by_name
    FROM board_post p
    LEFT JOIN user_account u ON u.user_id = p.user_id
    LEFT JOIN user_account d ON d.user_id = p.deleted_by
    WHERE p.thread_id = ? ORDER BY p.post_id LIMIT ' . BOARD_PER_PAGE . ' OFFSET ' . $offset);
$st->execute([$id]);
$posts = $st->fetchAll();
$postImages = attach_images($pdo, 'board', array_map('intval', array_column($posts, 'post_id')));

/** ページ送り（2ページ以上あるときだけ）。現在のページの前後2つと、最初・最後を出す */
$pager = static function () use ($page, $pages, $pageUrl): string {
    if ($pages < 2) {
        return '';
    }
    $link = static fn(int $n, string $inner, string $label, bool $on): string => $on
        ? '<a class="btn btn--sm btn--ghost" href="' . h($pageUrl($n)) . '" aria-label="' . h($label) . '" title="' . h($label) . '">' . $inner . '</a>'
        : '<span class="btn btn--sm btn--ghost is-disabled" aria-hidden="true">' . $inner . '</span>';
    $html = '<nav class="board-pager" aria-label="ページ">'
        . $link(1, icon('first_page'), '最初', $page > 1)
        . $link($page - 1, icon('chevron_left'), '前', $page > 1);
    $prev = 0;
    for ($n = 1; $n <= $pages; $n++) {
        if ($n !== 1 && $n !== $pages && abs($n - $page) > 2) {
            continue;
        }
        if ($n - $prev > 1) {
            $html .= '<span class="muted">…</span>';
        }
        $html .= $n === $page
            ? '<span class="btn btn--sm btn--primary" aria-current="page">' . $n . '</span>'
            : '<a class="btn btn--sm btn--ghost" href="' . h($pageUrl($n)) . '">' . $n . '</a>';
        $prev = $n;
    }
    return $html
        . $link($page + 1, icon('chevron_right'), '次', $page < $pages)
        . $link($pages, icon('last_page'), '最新', $page < $pages)
        . '</nav>';
};

// 返信先の一覧（post_id → [返信先の post_id, ...]）
$replyTo = [];
$st = $pdo->prepare('SELECT r.post_id, r.reply_to_post_id FROM board_post_reply r
    JOIN board_post p ON p.post_id = r.post_id WHERE p.thread_id = ? ORDER BY r.reply_to_post_id');
$st->execute([$id]);
foreach ($st->fetchAll() as $r) {
    $replyTo[(int)$r['post_id']][] = (int)$r['reply_to_post_id'];
}
$st = $pdo->prepare('SELECT 1 FROM board_thread_mute WHERE user_id = ? AND thread_id = ?');
$st->execute([$uid, $id]);
$muted = (bool)$st->fetchColumn();

// 書き込みごとの ❤（数と、自分が付けているか）
$likes = [];
$st = $pdo->prepare('SELECT l.post_id, COUNT(*) AS cnt, SUM(l.user_id = ?) AS mine FROM board_post_like l
    JOIN board_post p ON p.post_id = l.post_id WHERE p.thread_id = ? GROUP BY l.post_id');
$st->execute([$uid, $id]);
foreach ($st->fetchAll() as $r) {
    $likes[(int)$r['post_id']] = ['count' => (int)$r['cnt'], 'liked' => (int)$r['mine'] > 0];
}
$canPost = $thread['deleted_at'] === null;

// 既読にするのは、開いているページの最後の書き込みまで（古いページを開いただけで、新着まで既読にしない）
if ($thread['deleted_at'] === null && $posts) {
    board_mark_read($uid, $id, (int)end($posts)['post_id']);
}

$canDeleteThread = $thread['kind'] === 'free' && $thread['deleted_at'] === null && ($user['admin'] || (int)$thread['user_id'] === $uid);

render_header($thread['title'], 'board');
?>
<nav class="crumbs"><a href="board">アビーボード</a><span>/</span><?= h($thread['title']) ?></nav>
<h1 class="display display--sm notice-title">
    <?= icon(match ($thread['kind']) { 'active' => 'groups', 'cohort' => 'school', default => 'forum' }) ?>
    <?= h($thread['title']) ?>
    <?php if ($thread['category_name'] !== null): ?><span class="tag board-title-tag tag--c-<?= h($thread['category_color']) ?>"><?= h($thread['category_name']) ?></span><?php endif; ?>
    <!-- ミュート（トグル）。押すと api_board_mute に送る（assets/app.js の setupBoardMute） -->
    <button type="button" class="icon-btn icon-btn--sm board-mute<?= $muted ? ' is-active' : '' ?>" data-board-mute="<?= $id ?>" aria-pressed="<?= $muted ? 'true' : 'false' ?>" aria-label="ミュート" title="ミュート"><?= icon($muted ? 'notifications_off' : 'notifications') ?></button>
</h1>
<?php if ($thread['deleted_at'] !== null): ?>
    <p class="flash flash--error"><?= icon('admin_panel_settings') ?> <?= h(substr($thread['deleted_at'], 0, 16)) ?> に <?= h($thread['deleted_by_name'] ?? '不明') ?> が削除</p>
<?php endif; ?>

<?php if (!$posts): ?><p class="muted">書き込みはありません</p><?php endif; ?>
<?= $pager() ?>
<div class="board-posts">
<?php foreach ($posts as $p): ?>
    <?php $deleted = $p['deleted_at'] !== null; $no = $numbers[(int)$p['post_id']]; ?>
    <article class="card board-post<?= $deleted ? ' is-deleted' : '' ?><?= (int)$p['user_id'] === $uid ? ' is-mine' : '' ?>" id="p<?= (int)$p['post_id'] ?>">
        <div class="muted small board-post__meta">
            <span class="board-post__no"><?= $no ?></span>
            <strong><?= author_html((bool)$p['anonymous'], $p['display_name'], $p['author'], $p['author_member_id'] !== null ? (int)$p['author_member_id'] : null, sees_real_names($user)) ?></strong>
            <span><?= h(substr($p['created_at'], 0, 16)) ?></span>
            <?php if ($canPost && !$deleted): ?>
            <button type="button" class="icon-btn icon-btn--sm board-post__reply" data-board-reply="<?= (int)$p['post_id'] ?>" data-no="<?= $no ?>" aria-label="返信" title="返信" aria-pressed="false"><?= icon('reply') ?></button>
            <?php endif; ?>
            <?php if (!$deleted && $thread['deleted_at'] === null && ($user['admin'] || (int)$p['user_id'] === $uid)): ?>
            <form method="post" class="board-post__del" onsubmit="return confirm('削除しますか？')">
                <?= csrf_field() ?>
                <input type="hidden" name="thread_id" value="<?= $id ?>">
                <input type="hidden" name="page" value="<?= $page ?>">
                <input type="hidden" name="action" value="delete_post">
                <input type="hidden" name="post_id" value="<?= (int)$p['post_id'] ?>">
                <button class="icon-btn icon-btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('delete') ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (!$deleted && !empty($replyTo[(int)$p['post_id']])): ?>
            <p class="small board-post__to"><?= icon('subdirectory_arrow_right') ?>
                <?php foreach ($replyTo[(int)$p['post_id']] as $toId): ?>
                    <?php $toNo = (int)($numbers[$toId] ?? 0); $toPage = max(1, (int)ceil($toNo / BOARD_PER_PAGE)); ?>
                    <a href="<?= $toPage === $page ? '' : h($pageUrl($toPage)) ?>#p<?= $toId ?>">&gt;&gt;<?= $toNo ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if (!$deleted): ?>
            <?php if ($p['body'] !== ''): ?><p class="board-post__body"><?= linkify_body($p['body']) ?></p><?php endif; ?>
            <?= attach_thumbs_html('board', $postImages[(int)$p['post_id']] ?? []) ?>
            <div class="board-post__foot"><?= like_button((int)$p['post_id'], $likes[(int)$p['post_id']] ?? null, '', 'api_board_like') ?></div>
        <?php elseif ($user['admin']): ?>
            <p class="small board-post__deleted"><?= icon('admin_panel_settings') ?> <?= h(substr($p['deleted_at'], 0, 16)) ?> に <?= h($p['deleted_by_name'] ?? '不明') ?> が削除</p>
            <p class="board-post__body muted"><?= nl2br(h($p['body'])) ?></p>
            <?= attach_thumbs_html('board', $postImages[(int)$p['post_id']] ?? []) ?>
        <?php else: ?>
            <p class="muted small">削除されました</p>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
<?= $pager() ?>

<?php if ($thread['deleted_at'] === null): ?>
<form method="post" enctype="multipart/form-data" class="card board-reply"<?= unsaved_attr() ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="thread_id" value="<?= $id ?>">
    <input type="hidden" name="action" value="reply">
    <!-- 返信先（各書き込みの返信ボタンで追加・解除。assets/app.js の setupBoardReply）。選んだ数だけ hidden の reply_to[] が入る -->
    <div class="board-reply__to" data-board-reply-to hidden></div>
    <?= alias_field_html((string)$user['name']) ?>
    <textarea class="board-textarea" name="body" rows="3" maxlength="2000" aria-label="返信"></textarea>
    <div class="form-actions"><?= attach_field_html('board') ?><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?> 送信</button></div>
</form>
<?php endif; ?>

<?php if ($canDeleteThread): ?>
<form method="post" onsubmit="return confirm('スレッドを削除しますか？')">
    <?= csrf_field() ?>
    <input type="hidden" name="thread_id" value="<?= $id ?>">
    <input type="hidden" name="action" value="delete_thread">
    <button class="btn btn--sm" type="submit"><?= icon('delete') ?> スレッド削除</button>
</form>
<?php endif; ?>
<?php render_footer();
