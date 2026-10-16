<?php
/**
 * =====================================================================
 *  notices.php — お知らせ（管理者が書き、全員が読む）
 * =====================================================================
 *  カテゴリ（notice_category）と「重要」の区分がある。
 *    ・重要なお知らせは一覧の先頭に固定して目立たせる（ホームにもバナーが出る。index.php）
 *    ・上のタブでカテゴリを絞り込める。画面は移動せず、全部を一度に出して JS で出し分ける（assets/app.js の setupNoticeTabs）。
 *      ?cat=カテゴリID は最初に開くタブを選ぶだけ（URL を共有できるように）
 *  開いた時点で、表示した分だけ既読にする（notice_read に行を足す。行がある＝既読）。
 *  予約: 公開日時（publish_at）が未来のお知らせは、管理者の一覧にだけ「予約」タグ付きで出る。
 *        過去の日時を入れると、その日付ですぐ公開される。予約中のものだけ編集できる（公開済みは編集不可）。「予約」のチェックを外して保存すると、その場で公開する。
 *        一般ユーザー・未読数・ベル・ホームには、公開日時になるまで出ない（cron は使わず、表示時に publish_at <= NOW() で出し分ける）。
 *  投稿・削除・カテゴリの管理は管理者だけ（POST の中で確認）。
 *  カテゴリの削除は、使われているものは止める（notice.category_id が ON DELETE RESTRICT）。
 *  「コメント」「掲示板」は自動で出す通知専用（NOTICE_SYSTEM_CATEGORIES）。名前・色の変更、削除、手動投稿での選択はできない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$pdo = db();

$categories = $pdo->query('SELECT c.category_id, c.name, c.color, (SELECT COUNT(*) FROM notice n WHERE n.category_id = c.category_id) AS used
    FROM notice_category c ORDER BY c.category_id')->fetchAll();
$categoryNames = array_column($categories, 'name', 'category_id');
$categoryColors = array_column($categories, 'color', 'category_id');
// 自動で出す通知専用のカテゴリ（NOTICE_SYSTEM_CATEGORIES）は、変更・削除・手動投稿での選択ができない
$systemIds = array_keys(array_intersect($categoryNames, NOTICE_SYSTEM_CATEGORIES));
$manualCategories = array_values(array_filter($categories, static fn($c) => !in_array((int)$c['category_id'], $systemIds, true)));

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
        $color = (string)($_POST['color'] ?? 'gray');
        $dup = $pdo->prepare('SELECT 1 FROM notice_category WHERE name = ? AND category_id <> ?');
        $dup->execute([$name, $id]);
        if (in_array($id, $systemIds, true) || in_array($name, NOTICE_SYSTEM_CATEGORIES, true)) {
            flash('このカテゴリは変更できません', 'error');
        } elseif ($name === '' || mb_strlen($name) > 20) {
            flash('カテゴリ名は1〜20文字で入力してください', 'error');
        } elseif (!isset(NOTICE_COLORS[$color])) {
            flash('色を選択してください', 'error');
        } elseif ($dup->fetchColumn()) {
            flash("「{$name}」はすでにあります", 'error');
        } elseif ($action === 'category_add') {
            $pdo->prepare('INSERT INTO notice_category (name, color) VALUES (?, ?)')->execute([$name, $color]);
            flash('追加しました');
        } elseif (isset($categoryNames[$id])) {
            $pdo->prepare('UPDATE notice_category SET name = ?, color = ? WHERE category_id = ?')->execute([$name, $color, $id]);
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
        } elseif (in_array($id, $systemIds, true)) {
            flash('このカテゴリは削除できません', 'error');
        } elseif ($used > 0) {
            flash("{$used} 件のお知らせで使われているので削除できません", 'error');
        } else {
            $pdo->prepare('DELETE FROM notice_category WHERE category_id = ?')->execute([$id]);
            flash('削除しました');
        }
        $back .= '#categories';
    } else {
        $editId = $action === 'update' ? (int)($_POST['notice_id'] ?? 0) : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $important = ($_POST['is_important'] ?? '') === '1' ? 1 : 0;
        // 予約: チェックが付いているときだけ日時を見る。過去の日時も受け付ける（その日付で、すぐ公開されるお知らせになる）
        $publishAt = null;
        $scheduleError = false;
        if (($_POST['schedule'] ?? '') === '1') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string)($_POST['publish_at'] ?? ''));
            if ($dt === false) {
                $scheduleError = true;
            } else {
                $publishAt = $dt->format('Y-m-d H:i:s');
            }
        }
        if (!isset($categoryNames[$categoryId]) || in_array($categoryId, $systemIds, true)) {
            flash('カテゴリを選択してください', 'error');
        } elseif ($scheduleError) {
            flash('日時の形式が正しくありません', 'error');
        } elseif ($title === '' || mb_strlen($title) > 100 || $body === '' || mb_strlen($body) > 2000) {
            flash('タイトルは1〜100文字、本文は1〜2000文字で入力してください', 'error');
        } elseif ($action === 'update') {
            // 編集できるのは予約中だけ。公開された後に開いたままの画面から送られても、ここで止める
            //   （UPDATE の WHERE にも同じ条件を入れて、確認から更新までの間に公開されても書きかわらないようにする）
            $st = $pdo->prepare('SELECT 1 FROM notice WHERE notice_id = ? AND publish_at > NOW()');
            $st->execute([$editId]);
            if (!$st->fetchColumn()) {
                flash('公開済みまたは存在しないお知らせは編集できません', 'error');
            } else {
                $pdo->prepare('UPDATE notice SET category_id = ?, is_important = ?, title = ?, body = ?, publish_at = COALESCE(?, NOW())
                    WHERE notice_id = ? AND publish_at > NOW()')
                    ->execute([$categoryId, $important, $title, $body, $publishAt, $editId]);
                flash($publishAt === null || $publishAt <= date('Y-m-d H:i:s') ? '公開しました' : '変更しました');
            }
        } else {
            // publish_at が NULL のときは DEFAULT（現在）にしたいので COALESCE を使う
            $pdo->prepare('INSERT INTO notice (user_id, category_id, is_important, title, body, publish_at) VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()))')
                ->execute([$user['user_id'], $categoryId, $important, $title, $body, $publishAt]);
            flash($publishAt === null || $publishAt <= date('Y-m-d H:i:s') ? '投稿しました' : '予約しました');
        }
    }
    redirect($back);
}

// 最初に開くタブ（存在しないカテゴリ ID は「すべて」に戻す）
$cat = (int)($_GET['cat'] ?? 0);
if (!isset($categoryNames[$cat])) {
    $cat = 0;
}

// 未読を先に調べてから既読にする（NEW の目印用）。行が無いものが未読。重要を先頭に固定する
$st = $pdo->prepare(
    'SELECT n.notice_id, n.category_id, n.is_important, n.title, n.body, n.publish_at, n.link_url, (r.user_id IS NULL) AS is_new,
            (n.publish_at > NOW()) AS is_scheduled
       FROM notice n
       LEFT JOIN notice_read r ON r.notice_id = n.notice_id AND r.user_id = ?
      WHERE (n.publish_at <= NOW() OR ? = 1) AND (n.target_user_id IS NULL OR n.target_user_id = ?)
      ORDER BY (n.is_important = 1 AND n.publish_at > NOW() - INTERVAL 4 DAY) DESC, n.publish_at DESC, n.notice_id DESC LIMIT 100'
);
$st->execute([$user['user_id'], $user['admin'] ? 1 : 0, $user['user_id']]);
$notices = $st->fetchAll();

// 表示した分だけ既読にする（101件目以降を勝手に既読にしない）。管理者にだけ見えている予約中のものは除く
$mark = $pdo->prepare('INSERT IGNORE INTO notice_read (user_id, notice_id) VALUES (?, ?)');
foreach ($notices as $n) {
    if ($n['is_scheduled']) {
        continue; // 予約中のものは読んだことにしない（公開されたとき、ほかの人と同じく未読から始める）
    }
    $mark->execute([$user['user_id'], (int)$n['notice_id']]);
}

render_header('お知らせ', 'notices');
?>
<h1 class="display display--sm"><?= icon('campaign') ?> お知らせ</h1>

<?php if ($user['admin']): ?>
<details class="card togglecard">
<summary><?= icon('admin_panel_settings') ?> <span class="togglecard__title">新規投稿</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
<form method="post" class="togglecard__body"<?= unsaved_attr() ?>>
    <?= csrf_field() ?>
    <label class="field"><span>カテゴリ</span>
        <select name="category_id" required>
            <?php foreach ($manualCategories as $c): ?>
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
    <div class="notice-schedule">
        <label class="check"><input type="checkbox" name="schedule" value="1" data-notice-schedule> <?= icon('schedule') ?> 予約</label>
        <input type="datetime-local" name="publish_at" aria-label="予約日時" data-notice-schedule-at hidden disabled>
    </div>
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?> 投稿</button></div>
</form>
</details>
<?php endif; ?>

<nav class="tabs tabs--static" aria-label="カテゴリ" data-notice-tabs>
    <a class="tab<?= $cat === 0 ? ' is-active' : '' ?>" href="notices" data-cat="0"<?= $cat === 0 ? ' aria-current="page"' : '' ?>>すべて</a>
    <?php foreach ($categories as $c): ?>
        <a class="tab<?= $cat === (int)$c['category_id'] ? ' is-active' : '' ?>" href="notices?cat=<?= (int)$c['category_id'] ?>" data-cat="<?= (int)$c['category_id'] ?>"<?= $cat === (int)$c['category_id'] ? ' aria-current="page"' : '' ?>><?= h($c['name']) ?></a>
    <?php endforeach; ?>
</nav>

<p class="muted" data-notice-empty<?= $notices ? ' hidden' : '' ?>>お知らせはありません</p>
<?php foreach ($notices as $n): ?>
    <details class="card togglecard notice-item<?= $n['is_important'] ? ' notice--important' : '' ?>" data-notice-cat="<?= (int)$n['category_id'] ?>"<?= $cat !== 0 && $cat !== (int)$n['category_id'] ? ' hidden' : '' ?>>
        <summary>
            <span class="notice-summary">
                <span class="section-title section-title--card notice-title">
                    <?= $n['is_important'] ? icon('priority_high', 'notice__mark') : '' ?>
                    <?= h($n['title']) ?>
                    <?= $n['is_scheduled'] ? '<span class="tag tag--plan">予約</span>' : ($n['is_new'] ? '<span class="tag tag--plan">NEW</span>' : '') ?>
                </span>
                <span class="muted small notice-meta"><?= h(substr($n['publish_at'], 0, 16)) ?><?= $n['is_scheduled'] ? ' に公開' : '' ?> <span>·</span> <span class="tag tag--c-<?= h($categoryColors[$n['category_id']] ?? 'gray') ?>"><?= h($categoryNames[$n['category_id']] ?? '') ?></span></span>
            </span>
            <?= icon('expand_more', 'togglecard__chevron') ?>
        </summary>
        <div class="notice-body">
        <p><?= notice_body_html($n['body']) ?></p>
        <?php if ($n['link_url'] !== null): ?><p><a class="btn btn--sm" href="<?= h($n['link_url']) ?>" aria-label="開く" title="開く"><?= icon('open_in_new') ?></a></p><?php endif; ?>
        <?php if ($user['admin'] && $n['is_scheduled']): ?>
        <details class="togglecard togglecard--inline">
            <summary><?= icon('edit') ?> <span class="togglecard__title">予約を編集</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
            <form method="post" class="togglecard__body"<?= unsaved_attr() ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="notice_id" value="<?= (int)$n['notice_id'] ?>">
                <label class="field"><span>カテゴリ</span>
                    <select name="category_id" required>
                        <?php foreach ($manualCategories as $c): ?>
                            <option value="<?= (int)$c['category_id'] ?>"<?= (int)$c['category_id'] === (int)$n['category_id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>タイトル</span>
                    <input type="text" name="title" maxlength="100" required value="<?= h($n['title']) ?>">
                </label>
                <label class="field"><span>本文</span>
                    <textarea name="body" rows="5" maxlength="2000" required><?= h($n['body']) ?></textarea>
                </label>
                <label class="check"><input type="checkbox" name="is_important" value="1"<?= $n['is_important'] ? ' checked' : '' ?>> 重要</label>
                <div class="notice-schedule">
                    <label class="check"><input type="checkbox" name="schedule" value="1" data-notice-schedule checked> <?= icon('schedule') ?> 予約</label>
                    <input type="datetime-local" name="publish_at" aria-label="予約日時" data-notice-schedule-at value="<?= h(str_replace(' ', 'T', substr($n['publish_at'], 0, 16))) ?>">
                </div>
                <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('check') ?> 保存</button></div>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($user['admin']): ?>
        <form method="post" onsubmit="return confirm('削除しますか？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notice_id" value="<?= (int)$n['notice_id'] ?>">
            <button class="btn btn--sm" type="submit" aria-label="削除" title="削除"><?= icon('delete') ?></button>
        </form>
        <?php endif; ?>
        </div>
    </details>
<?php endforeach; ?>

<?php if ($user['admin']): ?>
<details class="card togglecard" id="categories">
<summary><?= icon('admin_panel_settings') ?> <span class="togglecard__title">カテゴリ管理</span><?= icon('expand_more', 'togglecard__chevron') ?></summary>
<div class="togglecard__body">
    <?php foreach ($categories as $c): ?>
        <?php if (in_array((int)$c['category_id'], $systemIds, true)): ?>
        <!-- 自動で出す通知専用: 変更・削除できない -->
        <div class="notice-cat">
            <span class="notice-cat__rename"><?= icon('lock') ?> <span class="tag tag--c-<?= h($c['color']) ?>"><?= h($c['name']) ?></span></span>
            <span class="muted small"><?= (int)$c['used'] ?> 件</span>
        </div>
        <?php continue; endif; ?>
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
                        <option value="<?= h($k) ?>"<?= 'gray' === $k ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
        <button class="btn btn--sm" type="submit" aria-label="追加" title="追加"><?= icon('add') ?></button>
    </form>
</div>
</details>
<?php endif; ?>
<?php render_footer();
