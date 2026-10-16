<?php
/**
 * =====================================================================
 *  board.php — 掲示板のスレ一覧（スレの中身は board_thread.php）
 * =====================================================================
 *  上に固定で「現役」「自分の同期（◯年度入部）」のスレ。見られる人の決まりは lib/board.php。
 *  その下に誰でも立てられる自由スレ（カテゴリで絞り込み。?cat=カテゴリID）。新しく書き込まれた順。
 *  未読のあるスレには NEW（board_read の last_post_id より新しい書き込みがある）。
 *  カテゴリの管理は管理者だけ（使われているカテゴリは削除できない。ON DELETE RESTRICT）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/board.php';
require_once __DIR__ . '/lib/push.php'; // board_push_post()（アプリ通知）
$user = require_login();
$pdo = db();
board_ensure_fixed();

$categories = $pdo->query('SELECT c.category_id, c.name, c.color, (SELECT COUNT(*) FROM board_thread t WHERE t.category_id = c.category_id) AS used
    FROM board_category c ORDER BY c.category_id')->fetchAll();
$categoryNames = array_column($categories, 'name', 'category_id');
$categoryColors = array_column($categories, 'color', 'category_id');

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if (str_starts_with($action, 'category_') && !$user['admin']) {
        http_response_code(403);
        exit('管理者のみ実行できます。');
    }
    $back = 'board';
    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        if (!isset($categoryNames[$categoryId])) {
            flash('カテゴリを選択してください', 'error');
        } elseif ($title === '' || mb_strlen($title) > 100 || $body === '' || mb_strlen($body) > 2000) {
            flash('タイトルは1〜100文字、本文は1〜2000文字で入力してください', 'error');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO board_thread (kind, category_id, user_id, title) VALUES ('free', ?, ?, ?)")
                ->execute([$categoryId, $user['user_id'], $title]);
            $threadId = (int)$pdo->lastInsertId();
            $postId = board_add_post($threadId, (int)$user['user_id'], $body);
            $pdo->commit();
            board_push_post($threadId, $postId); // アプリ通知（lib/push.php）。応答を返し終わってから送る
            flash('作成しました');
            $back = 'board_thread?id=' . $threadId;
        }
    } elseif ($action === 'notify') {
        // 通知設定。カテゴリは実在するものだけ（HTML を書き換えて送られた ID は捨てる）
        $picked = array_values(array_intersect(array_map('intval', (array)($_POST['notify_cat'] ?? [])), array_keys($categoryNames)));
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO board_notify (user_id, cohort_active, participating) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE cohort_active = VALUES(cohort_active), participating = VALUES(participating)')
            ->execute([$user['user_id'], (int)(($_POST['notify_fixed'] ?? '') === '1'), (int)(($_POST['notify_part'] ?? '') === '1')]);
        $pdo->prepare('DELETE FROM board_notify_category WHERE user_id = ?')->execute([$user['user_id']]);
        $ins = $pdo->prepare('INSERT INTO board_notify_category (user_id, category_id) VALUES (?, ?)');
        foreach ($picked as $cid) {
            $ins->execute([$user['user_id'], $cid]);
        }
        $pdo->commit();
        flash('通知設定を保存しました');
        $back .= '#notify';
    } elseif ($action === 'category_add' || $action === 'category_rename') {
        $name = trim((string)($_POST['name'] ?? ''));
        $id = (int)($_POST['category_id'] ?? 0);
        $color = (string)($_POST['color'] ?? 'gray');
        $dup = $pdo->prepare('SELECT 1 FROM board_category WHERE name = ? AND category_id <> ?');
        $dup->execute([$name, $id]);
        if ($name === '' || mb_strlen($name) > 20) {
            flash('カテゴリ名は1〜20文字で入力してください', 'error');
        } elseif (!isset(NOTICE_COLORS[$color])) {
            flash('色を選択してください', 'error');
        } elseif ($dup->fetchColumn()) {
            flash("「{$name}」はすでにあります", 'error');
        } elseif ($action === 'category_add') {
            $pdo->prepare('INSERT INTO board_category (name, color) VALUES (?, ?)')->execute([$name, $color]);
            flash('追加しました');
        } elseif (isset($categoryNames[$id])) {
            $pdo->prepare('UPDATE board_category SET name = ?, color = ? WHERE category_id = ?')->execute([$name, $color, $id]);
            flash('変更しました');
        }
        $back .= '#categories';
    } elseif ($action === 'category_delete') {
        $id = (int)($_POST['category_id'] ?? 0);
        $used = 0;
        foreach ($categories as $c) {
            if ((int)$c['category_id'] === $id) {
                $used = (int)$c['used'];
            }
        }
        if (!isset($categoryNames[$id])) {
            flash('カテゴリが見つかりません', 'error');
        } elseif ($used > 0) {
            flash("{$used} 件のスレで使われているので削除できません", 'error');
        } else {
            $pdo->prepare('DELETE FROM board_category WHERE category_id = ?')->execute([$id]);
            flash('削除しました');
        }
        $back .= '#categories';
    }
    redirect($back);
}

$cat = (int)($_GET['cat'] ?? 0);
if (!isset($categoryNames[$cat])) {
    $cat = 0;
}

[$where, $params] = board_visible_sql($user);
$st = $pdo->prepare("SELECT t.thread_id, t.kind, t.entry_year, t.category_id, t.title, t.last_post_id, t.last_posted_at, t.deleted_at,
        u.name AS author,
        (SELECT COUNT(*) FROM board_post p WHERE p.thread_id = t.thread_id AND p.deleted_at IS NULL) AS posts,
        (t.last_post_id > COALESCE(r.last_post_id, 0)) AS is_new
    FROM board_thread t
    LEFT JOIN user_account u ON u.user_id = t.user_id
    LEFT JOIN board_read r ON r.thread_id = t.thread_id AND r.user_id = ?
    WHERE $where
    ORDER BY t.kind = 'free', t.kind = 'cohort', t.entry_year DESC, t.last_posted_at DESC, t.thread_id DESC
    LIMIT 300");
$st->execute([$user['user_id'], ...$params]);
$fixed = [];
$free = [];
foreach ($st->fetchAll() as $t) {
    if ($t['kind'] !== 'free') {
        $fixed[] = $t;
    } elseif ($cat === 0 || (int)$t['category_id'] === $cat) {
        $free[] = $t;
    }
}
$myYear = board_scope($user)['entry_year'];
$pref = board_notify_pref((int)$user['user_id']);
// 管理者はすべての年度の同期スレが見える。自分の年度以外はトグルにまとめる（現役・自分の同期スレだけ上に出す）
$others = [];
if (!empty($user['admin'])) {
    foreach ($fixed as $i => $t) {
        if ($t['kind'] === 'cohort' && (int)$t['entry_year'] !== $myYear) {
            $others[] = $t;
            unset($fixed[$i]);
        }
    }
}

/** スレ一覧の1行 */
$row = function (array $t) use ($categoryNames, $categoryColors, $myYear, $cat): string {
    $icon = match ($t['kind']) { 'active' => 'groups', 'cohort' => 'school', default => 'forum' };
    $tag = '';
    // カテゴリで絞り込んでいる（$cat <> 0）ときは、どれも同じカテゴリなのでラベルは出さない
    if ($t['kind'] === 'free' && $cat === 0 && isset($categoryNames[$t['category_id']])) {
        $tag = '<span class="tag tag--c-' . h($categoryColors[$t['category_id']]) . '">' . h($categoryNames[$t['category_id']]) . '</span>';
    } elseif ($t['kind'] === 'cohort' && (int)$t['entry_year'] === $myYear) {
        $tag = '<span class="tag">同期</span>';
    }
    $meta = ($t['last_post_id'] ? h(substr($t['last_posted_at'], 0, 16)) : '') . ($t['author'] !== null ? ' · ' . h($t['author']) : '');
    return '<a class="board-row' . ($t['deleted_at'] !== null ? ' is-deleted' : '') . '" href="board_thread?id=' . (int)$t['thread_id'] . '">'
        . icon($icon, 'board-row__icon')
        . '<span class="board-row__main"><span class="board-row__title"><span class="board-row__name">' . h($t['title']) . '</span>' . $tag
        . ($t['is_new'] && $t['deleted_at'] === null ? ' <span class="tag tag--plan">NEW</span>' : '')
        . ($t['deleted_at'] !== null ? ' <span class="tag tag--c-red">削除済み</span>' : '') . '</span>'
        . '<span class="muted small">' . $meta . '</span></span>'
        . '<span class="board-row__count muted small">' . icon('chat_bubble') . (int)$t['posts'] . '</span></a>';
};

render_header('アビーボード', 'board');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Abbey Board</p>
        <h1 class="display">アビーボード</h1>
        <p class="muted">アビーの何でも掲示板</p>
    </div>
    <!-- スレッド作成・通知設定は、アイコンを押すと下のカードが開く（assets/app.js の setupBoardPanels） -->
    <div class="hero__actions">
        <button type="button" class="icon-btn" data-board-panel-open="create" aria-label="スレッド作成" title="スレッド作成"><?= icon('add_comment') ?></button>
        <button type="button" class="icon-btn" data-board-panel-open="notify" aria-label="通知設定" title="通知設定"><?= icon('settings') ?></button>
    </div>
</section>

<div class="card togglecard" id="create" data-board-panel hidden>
<form method="post" class="togglecard__body"<?= unsaved_attr() ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label class="field"><span>カテゴリ</span>
        <select name="category_id" required>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['category_id'] ?>"<?= (int)$c['category_id'] === $cat ? ' selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field"><span>タイトル</span>
        <input type="text" name="title" maxlength="100" required>
    </label>
    <label class="field"><span>本文</span>
        <textarea class="board-textarea" name="body" rows="4" maxlength="2000" required></textarea>
    </label>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?> 作成</button></div>
</form>
</div>

<div class="card togglecard" id="notify" data-board-panel hidden>
<form method="post" class="togglecard__body">
    <h2 class="section-title section-title--card board-panel__title">アプリ内通知設定</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="notify">
    <label class="check"><input type="hidden" name="notify_fixed" value="0"><input type="checkbox" name="notify_fixed" value="1"<?= $pref['cohort_active'] ? ' checked' : '' ?>>
        <?= icon('groups') ?><?= icon('school') ?> 現役・同期スレッドの新着</label>
    <label class="check" title="48時間以内に自分が書き込んだスレの新着"><input type="hidden" name="notify_part" value="0"><input type="checkbox" name="notify_part" value="1"<?= $pref['participating'] ? ' checked' : '' ?>>
        <?= icon('edit_note') ?> 48時間以内に書き込んだスレッドの新着</label>
    <h3 class="board-panel__sub">新規スレッドの書き込みを通知</h3>
    <div class="checks">
        <?php foreach ($categories as $c): ?>
            <label class="check"><input type="checkbox" name="notify_cat[]" value="<?= (int)$c['category_id'] ?>"<?= $pref['categories'] === null || in_array((int)$c['category_id'], $pref['categories'], true) ? ' checked' : '' ?>>
                <span class="tag tag--c-<?= h($c['color']) ?>"><?= h($c['name']) ?></span></label>
        <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit" aria-label="保存" title="保存"><?= icon('check') ?></button></div>
</form>
</div>

<?php if ($fixed): ?>
<div class="card board-list"><?php foreach ($fixed as $t) { echo $row($t); } ?></div>
<?php endif; ?>

<?php if ($others): ?>
<details class="card togglecard">
<summary><?= icon('admin_panel_settings') ?> <span class="togglecard__title">他の年度 <span class="muted small"><?= count($others) ?></span></span>
    <?php if (array_filter($others, static fn($t) => $t['is_new'] && $t['deleted_at'] === null)): ?><span class="tag tag--plan">NEW</span><?php endif; ?>
    <?= icon('expand_more', 'togglecard__chevron') ?></summary>
<div class="board-list board-list--in"><?php foreach ($others as $t) { echo $row($t); } ?></div>
</details>
<?php endif; ?>

<nav class="tabs tabs--static" aria-label="カテゴリ" data-board-tabs>
    <a class="tab<?= $cat === 0 ? ' is-active' : '' ?>" href="board"<?= $cat === 0 ? ' aria-current="page"' : '' ?>>すべて</a>
    <?php foreach ($categories as $c): ?>
        <a class="tab<?= $cat === (int)$c['category_id'] ? ' is-active' : '' ?>" href="board?cat=<?= (int)$c['category_id'] ?>"<?= $cat === (int)$c['category_id'] ? ' aria-current="page"' : '' ?>><?= h($c['name']) ?></a>
    <?php endforeach; ?>
</nav>

<!-- カテゴリのタブを押すと、ここだけ差し替える（assets/app.js の setupBoardTabs。選んでいるカテゴリは data-cat） -->
<div data-board-free data-cat="<?= $cat ?>">
<?php if ($free): ?>
<div class="card board-list"><?php foreach ($free as $t) { echo $row($t); } ?></div>
<?php else: ?>
<p class="muted">スレッドはありません</p>
<?php endif; ?>
</div>

<?php if ($user['admin']): ?>
<details class="card togglecard" id="categories">
<summary><?= icon('admin_panel_settings') ?> <span class="togglecard__title">カテゴリ管理</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
<div class="togglecard__body">
    <?php foreach ($categories as $c): ?>
        <div class="notice-cat">
            <form method="post" class="notice-cat__rename">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="category_rename">
                <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                <input type="text" name="name" value="<?= h($c['name']) ?>" maxlength="20" required aria-label="カテゴリ名">
                <select name="color" aria-label="色">
                    <?php foreach (NOTICE_COLORS as $k => $label): ?>
                        <option value="<?= h($k) ?>"<?= $c['color'] === $k ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn--sm" type="submit" aria-label="変更" title="変更"><?= icon('check') ?></button>
            </form>
            <span class="muted small"><?= (int)$c['used'] ?> 件</span>
            <form method="post" onsubmit="return confirm('削除しますか？')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="category_delete">
                <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                <button class="btn btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('delete') ?></button>
            </form>
        </div>
    <?php endforeach; ?>
    <form method="post" class="notice-cat__rename">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="category_add">
        <input type="text" name="name" maxlength="20" required placeholder="新規カテゴリ" aria-label="新規カテゴリ">
        <select name="color" aria-label="色">
            <?php foreach (NOTICE_COLORS as $k => $label): ?>
                <option value="<?= h($k) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn--sm" type="submit" aria-label="追加" title="追加"><?= icon('add') ?></button>
    </form>
</div>
</details>
<?php endif; ?>
<?php render_footer();
