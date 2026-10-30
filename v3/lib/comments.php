<?php
/**
 * =====================================================================
 *  lib/comments.php — ライブ・バンドのコメント（掲示板と同じ作り：返信 >>N・❤・削除）
 * =====================================================================
 *  使い方（live.php / band.php）:
 *    1. 画面を出す前に  comments_handle_post('live', $liveId, 'live?id=' . $liveId);
 *    2. 出したい場所で  comments_render('live', $liveId, $user);
 *  普段は閉じたトグルカード（<details>）で、見出しに「コメント（5）」と件数を出す。
 *  削除は本人と管理者。行は消さずに deleted_at を入れるだけ（管理者には中身も見える）。
 *  返信先・❤は中間テーブル（comment_reply / comment_like）。
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/repository.php'; // like_button()

const COMMENT_MAX = 1000;       // 1コメントの文字数
const COMMENT_REPLY_MAX = 10;   // 1コメントで返信できる数

/** 'live' / 'band' → 対象の列名。それ以外は例外（SQL に入る文字列なので固定の2択だけ通す） */
function comment_col(string $kind): string
{
    return match ($kind) {
        'live' => 'live_id',
        'band' => 'band_id',
        default => throw new InvalidArgumentException('comment kind'),
    };
}

/**
 * バンドにコメントが付いたら、そのバンドの出演者（アカウントがある人）にお知らせを出す（カテゴリ「コメント」）。
 *   コメントした本人には出さない。お知らせは宛先つき（notice.target_user_id）なので、本人以外には見えない。
 *   バンド名・本文は通知のときの文字を保存する（あとでコメントを消しても通知は残る。表示側で h() する）
 */
function comment_notify_band_members(int $bandId, int $commentId, int $authorId, ?int $authorMemberId, string $authorName, string $body, string $link, ?string $alias = null): void
{
    if ($alias !== null) { // 匿名・名義あり: 本名もリンクも通知に残さない
        $authorMemberId = null;
        $authorName = $alias;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT DISTINCT u.user_id FROM band_member bm
        JOIN user_account u ON u.member_id = bm.member_id
        WHERE bm.band_id = ? AND u.user_id <> ?');
    $st->execute([$bandId, $authorId]);
    $targets = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$targets) {
        return;
    }
    $st = $pdo->prepare('SELECT b.name AS band_name, l.live_id, l.name AS live_name FROM band b
        JOIN live_day d ON d.live_day_id = b.live_day_id
        JOIN live l ON l.live_id = d.live_id WHERE b.band_id = ?');
    $st->execute([$bandId]);
    $info = $st->fetch();
    if (!$info) {
        return;
    }

    // カテゴリ「コメント」（名前で探す。無ければ作る）
    $pdo->exec("INSERT IGNORE INTO notice_category (name, color) VALUES ('コメント', 'pink')");
    $cat = (int)$pdo->query("SELECT category_id FROM notice_category WHERE name = 'コメント'")->fetchColumn();

    // 1行目の [[リンク先|文字]] は notice_body_html()（lib/bootstrap.php）がリンクにする。3行目から先がコメント
    $who = $authorMemberId !== null ? '[[member?id=' . $authorMemberId . '|' . $authorName . ']]' : $authorName;
    $title = mb_substr($info['band_name'], 0, 70) . ' にコメントが追加されました';
    $text = '[[live?id=' . (int)$info['live_id'] . '|' . $info['live_name'] . ']] [[band?id=' . $bandId . '|' . $info['band_name'] . ']] に' . $who . 'がコメントしました'
        . "\n\n" . mb_substr($body, 0, 200) . (mb_strlen($body) > 200 ? '…' : '');
    $ins = $pdo->prepare('INSERT INTO notice (user_id, target_user_id, category_id, is_important, title, body, link_url)
        VALUES (?, ?, ?, 0, ?, ?, ?)');
    foreach ($targets as $uid) {
        $ins->execute([$alias !== null ? null : $authorId, (int)$uid, $cat, $title, $text, $link]);
    }
}

/** コメントの投稿・削除の POST を処理して、戻り先にリダイレクトする。対象外の POST なら何もしない */
function comments_handle_post(string $kind, int $targetId, string $backUrl): void
{
    $action = (string)($_POST['action'] ?? '');
    if (!is_post() || !in_array($action, ['comment_add', 'comment_delete'], true)) {
        return;
    }
    verify_csrf();
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    $uid = (int)$user['user_id'];
    $col = comment_col($kind);
    $pdo = db();

    if ($action === 'comment_add') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > COMMENT_MAX) {
            flash('1〜' . COMMENT_MAX . '文字で入力してください', 'error');
            redirect($backUrl . '#comments');
        }
        // 返信先: この対象のコメントだけ（HTML を書き換えて他のページの ID を送られても入れない）
        $to = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['reply_to'] ?? [])), static fn($v) => $v > 0)));
        $to = array_slice($to, 0, COMMENT_REPLY_MAX);
        if ($to) {
            $in = implode(',', array_fill(0, count($to), '?'));
            $st = $pdo->prepare("SELECT comment_id FROM comment WHERE $col = ? AND comment_id IN ($in)");
            $st->execute([$targetId, ...$to]);
            $to = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        // なりすまし防止: 他の部員の名前を名義にしていたら、保存せずに戻す（lib/repository.php）
        if (($aliasError = posted_alias_error($user)) !== null) {
            flash($aliasError, 'error');
            redirect($backUrl . '#comments');
        }
        $anonymous = ($_POST['anonymous'] ?? '') === '1';
        $displayName = posted_display_name($anonymous, $user); // 自分の名前を書いたときは本名扱い（null）
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO comment ($col, user_id, body, anonymous, display_name) VALUES (?, ?, ?, ?, ?)")->execute([$targetId, $uid, $body, (int)$anonymous, $displayName]);
            $commentId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO comment_reply (comment_id, reply_to_comment_id) VALUES (?, ?)');
            foreach ($to as $toId) {
                $ins->execute([$commentId, $toId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($kind === 'band') {
            comment_notify_band_members($targetId, $commentId, $uid, $user['member_id'] !== null ? (int)$user['member_id'] : null, (string)$user['name'], $body, $backUrl . '#c' . $commentId, $anonymous ? '匿名' : $displayName);
        }
        redirect($backUrl . '#c' . $commentId);
    }

    // 削除: 本人か管理者だけ。条件を UPDATE の WHERE に入れて、他人のコメントの ID を送られても消えないようにする
    $st = $pdo->prepare("UPDATE comment SET deleted_at = NOW(), deleted_by = ?
        WHERE comment_id = ? AND $col = ? AND deleted_at IS NULL AND (user_id = ? OR ? = 1)");
    $st->execute([$uid, (int)($_POST['comment_id'] ?? 0), $targetId, $uid, !empty($user['admin']) ? 1 : 0]);
    flash($st->rowCount() ? '削除しました' : '削除できませんでした', $st->rowCount() ? 'success' : 'error');
    redirect($backUrl . '#comments');
}

/** コメント欄（トグルカード）を出力する */
function comments_render(string $kind, int $targetId, array $user): void
{
    $col = comment_col($kind);
    $pdo = db();
    $uid = (int)$user['user_id'];
    $isAdmin = !empty($user['admin']);
    $seeReal = sees_real_names($user); // 匿名・名義の本名を併記するか（管理者の設定。削除権限の $isAdmin とは別）

    $st = $pdo->prepare("SELECT c.comment_id, c.user_id, c.body, c.anonymous, c.display_name, c.created_at, c.deleted_at, u.name AS author, u.member_id AS author_member_id, d.name AS deleted_by_name
        FROM comment c
        LEFT JOIN user_account u ON u.user_id = c.user_id
        LEFT JOIN user_account d ON d.user_id = c.deleted_by
        WHERE c.$col = ? ORDER BY c.comment_id");
    $st->execute([$targetId]);
    $comments = $st->fetchAll();

    $numbers = [];
    $alive = 0;
    foreach ($comments as $i => $c) {
        $numbers[(int)$c['comment_id']] = $i + 1;
        if ($c['deleted_at'] === null) {
            $alive++;
        }
    }
    $replyTo = [];
    $likes = [];
    if ($comments) {
        $st = $pdo->prepare("SELECT r.comment_id, r.reply_to_comment_id FROM comment_reply r
            JOIN comment c ON c.comment_id = r.comment_id WHERE c.$col = ? ORDER BY r.reply_to_comment_id");
        $st->execute([$targetId]);
        foreach ($st->fetchAll() as $r) {
            $replyTo[(int)$r['comment_id']][] = (int)$r['reply_to_comment_id'];
        }
        $st = $pdo->prepare("SELECT l.comment_id, COUNT(*) AS cnt, SUM(l.user_id = ?) AS mine FROM comment_like l
            JOIN comment c ON c.comment_id = l.comment_id WHERE c.$col = ? GROUP BY l.comment_id");
        $st->execute([$uid, $targetId]);
        foreach ($st->fetchAll() as $r) {
            $likes[(int)$r['comment_id']] = ['count' => (int)$r['cnt'], 'liked' => (int)$r['mine'] > 0];
        }
    }
    ?>
<details class="card togglecard comments no-print" id="comments" data-comments>
<summary><?= icon('chat_bubble') ?> <span class="togglecard__title">コメント（<?= $alive ?>）</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
<div class="togglecard__body comments__body">
    <?php if ($comments): ?>
    <div class="board-posts comments__list">
    <?php foreach ($comments as $i => $c):
        $cid = (int)$c['comment_id'];
        $deleted = $c['deleted_at'] !== null; ?>
        <article class="card board-post<?= $deleted ? ' is-deleted' : '' ?><?= (int)$c['user_id'] === $uid ? ' is-mine' : '' ?>" id="c<?= $cid ?>">
            <div class="muted small board-post__meta">
                <span class="board-post__no"><?= $i + 1 ?></span>
                <strong><?= author_html((bool)$c['anonymous'], $c['display_name'], $c['author'], $c['author_member_id'] !== null ? (int)$c['author_member_id'] : null, $seeReal) ?></strong>
                <span><?= h(substr($c['created_at'], 0, 16)) ?></span>
                <?php if (!$deleted): ?>
                <button type="button" class="icon-btn icon-btn--sm board-post__reply" data-comment-reply="<?= $cid ?>" data-no="<?= $i + 1 ?>" aria-label="返信" title="返信" aria-pressed="false"><?= icon('reply') ?></button>
                <?php endif; ?>
                <?php if (!$deleted && ($isAdmin || (int)$c['user_id'] === $uid)): ?>
                <form method="post" class="board-post__del" onsubmit="return confirm('削除しますか？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment_delete">
                    <input type="hidden" name="comment_id" value="<?= $cid ?>">
                    <button class="icon-btn icon-btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('delete') ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php if (!$deleted && !empty($replyTo[$cid])): ?>
                <p class="small board-post__to"><?= icon('subdirectory_arrow_right') ?>
                    <?php foreach ($replyTo[$cid] as $toId): ?><a href="#c<?= $toId ?>">&gt;&gt;<?= (int)($numbers[$toId] ?? 0) ?></a> <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if (!$deleted): ?>
                <p class="board-post__body"><?= linkify_body($c['body']) ?></p>
                <div class="board-post__foot"><?= like_button($cid, $likes[$cid] ?? null, '', 'api_comment_like') ?></div>
            <?php elseif ($isAdmin): ?>
                <p class="small board-post__deleted"><?= icon('admin_panel_settings') ?> <?= h(substr($c['deleted_at'], 0, 16)) ?> に <?= h($c['deleted_by_name'] ?? '不明') ?> が削除</p>
                <p class="board-post__body muted"><?= nl2br(h($c['body'])) ?></p>
            <?php else: ?>
                <p class="muted small">削除されました</p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="card board-reply comments__form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="comment_add">
        <!-- 返信先（各コメントの返信ボタンで追加・解除。assets/app.js の setupComments）。選んだ数だけ hidden の reply_to[] が入る -->
        <div class="board-reply__to" data-comment-reply-to hidden></div>
        <?= alias_field_html((string)$user['name']) ?>
        <textarea class="board-textarea" name="body" rows="3" maxlength="<?= COMMENT_MAX ?>" required aria-label="コメント"></textarea>
        <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?> 送信</button></div>
    </form>
</div>
</details>
<?php
}
