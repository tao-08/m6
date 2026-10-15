<?php
/**
 * =====================================================================
 *  notices.php — お知らせ（管理者が書き、全員が読む）
 * =====================================================================
 *  カテゴリ（notice_category）と「重要」の区分がある。
 *    ・重要なお知らせは一覧の先頭に固定して目立たせる（ホームにもバナーが出る。index.php）
 *    ・上のタブでカテゴリを絞り込める（?cat=カテゴリID）
 *  開いた時点で、表示した分だけ既読にする（notice_read に行を足す。行がある＝既読）。
 *  投稿・削除・カテゴリの管理は管理者だけ（POST の中で確認）。
 *  カテゴリの削除は、使われているものは止める（notice.category_id が ON DELETE RESTRICT）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$pdo = db();

$categories = $pdo->query('SELECT c.category_id, c.name, (SELECT COUNT(*) FROM notice n WHERE n.category_id = c.category_id) AS used
    FROM notice_category c ORDER BY c.category_id')->fetchAll();
$categoryNames = array_column($categories, 'name', 'category_id');

if (is_post()) {
    verify_csrf();
    if (!$user['admin']) {
        http_response_code(403);
        exit('管理者のみ実行できます。');
    }
    $action = (string)($_POST['action'] ?? '');
    $back = 'notices';
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM notice WHERE notice_id = ?')->execute([(int)($_POST['notice_id'] ?? 0)]);
        flash('削除しました');
    } elseif ($action === 'category_add' || $action === 'category_rename') {
        $name = trim((string)($_POST['name'] ?? ''));
        $id = (int)($_POST['category_id'] ?? 0);
        $dup = $pdo->prepare('SELECT 1 FROM notice_category WHERE name = ? AND category_id <> ?');
        $dup->execute([$name, $id]);
        if ($name === '' || mb_strlen($name) > 20) {
            flash('カテゴリ名は1〜20文字で入力してください', 'error');
        } elseif ($dup->fetchColumn()) {
            flash("「{$name}」はすでにあります", 'error');
        } elseif ($action === 'category_add') {
            $pdo->prepare('INSERT INTO notice_category (name) VALUES (?)')->execute([$name]);
            flash('追加しました');
        } elseif (isset($categoryNames[$id])) {
            $pdo->prepare('UPDATE notice_category SET name = ? WHERE category_id = ?')->execute([$name, $id]);
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
            flash("{$used} 件のお知らせで使われているので削除できません", 'error');
        } else {
            $pdo->prepare('DELETE FROM notice_category WHERE category_id = ?')->execute([$id]);
            flash('削除しました');
        }
        $back .= '#categories';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $important = ($_POST['is_important'] ?? '') === '1' ? 1 : 0;
        if (!isset($categoryNames[$categoryId])) {
            flash('カテゴリを選択してください', 'error');
        } elseif ($title === '' || mb_strlen($title) > 100 || $body === '' || mb_strlen($body) > 2000) {
            flash('タイトルは1〜100文字、本文は1〜2000文字で入力してください', 'error');
        } else {
            $pdo->prepare('INSERT INTO notice (user_id, category_id, is_important, title, body) VALUES (?, ?, ?, ?, ?)')
                ->execute([$user['user_id'], $categoryId, $important, $title, $body]);
            flash('投稿しました');
        }
    }
    redirect($back);
}

// 絞り込み（存在しないカテゴリ ID は「すべて」に戻す）
$cat = (int)($_GET['cat'] ?? 0);
if (!isset($categoryNames[$cat])) {
    $cat = 0;
}

// 未読を先に調べてから既読にする（NEW の目印用）。行が無いものが未読。重要を先頭に固定する
$st = $pdo->prepare(
    'SELECT n.notice_id, n.category_id, n.is_important, n.title, n.body, n.created_at, (r.user_id IS NULL) AS is_new
       FROM notice n
       LEFT JOIN notice_read r ON r.notice_id = n.notice_id AND r.user_id = ?
      WHERE (? = 0 OR n.category_id = ?)
      ORDER BY n.is_important DESC, n.notice_id DESC LIMIT 100'
);
$st->execute([$user['user_id'], $cat, $cat]);
$notices = $st->fetchAll();

// 表示した分だけ既読にする（絞り込みで見えていない分や101件目以降を勝手に既読にしない）
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
    <label class="field"><span>カテゴリ</span>
        <select name="category_id" required>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['category_id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field"><span>タイトル</span>
        <input type="text" name="title" maxlength="100" required>
    </label>
    <label class="field"><span>本文</span>
        <textarea name="body" rows="5" maxlength="2000" required></textarea>
    </label>
    <label class="check"><input type="checkbox" name="is_important" value="1"> 重要</label>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?> 投稿</button></div>
</form>
<?php endif; ?>

<nav class="tabs tabs--static" aria-label="カテゴリ">
    <a class="tab<?= $cat === 0 ? ' is-active' : '' ?>" href="notices"<?= $cat === 0 ? ' aria-current="page"' : '' ?>>すべて</a>
    <?php foreach ($categories as $c): ?>
        <a class="tab<?= $cat === (int)$c['category_id'] ? ' is-active' : '' ?>" href="notices?cat=<?= (int)$c['category_id'] ?>"<?= $cat === (int)$c['category_id'] ? ' aria-current="page"' : '' ?>><?= h($c['name']) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$notices): ?>
    <p class="muted">お知らせはありません</p>
<?php endif; ?>
<?php foreach ($notices as $n): ?>
    <article class="card form-card<?= $n['is_important'] ? ' notice--important' : '' ?>">
        <h2 class="section-title section-title--card">
            <?= $n['is_important'] ? icon('priority_high', 'notice__mark') : '' ?>
            <?= h($n['title']) ?>
            <?= $n['is_new'] ? '<span class="tag">NEW</span>' : '' ?>
        </h2>
        <p class="muted small"><?= h(substr($n['created_at'], 0, 16)) ?> · <span class="tag"><?= h($categoryNames[$n['category_id']] ?? '') ?></span></p>
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

<?php if ($user['admin']): ?>
<section class="card form-card" id="categories">
    <h2 class="section-title section-title--card">カテゴリ管理 <span class="tag">管理者</span></h2>
    <?php foreach ($categories as $c): ?>
        <div class="notice-cat">
            <form method="post" class="notice-cat__rename">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="category_rename">
                <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                <input type="text" name="name" value="<?= h($c['name']) ?>" maxlength="20" required aria-label="カテゴリ名">
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
        <button class="btn btn--sm" type="submit" aria-label="追加" title="追加"><?= icon('add') ?></button>
    </form>
</section>
<?php endif; ?>
<?php render_footer();
