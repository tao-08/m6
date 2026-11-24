<?php
/**
 * =====================================================================
 *  special.php — スペシャルコンテンツ（年度・ライブに付けるリンクとファイル）
 * =====================================================================
 *  ・ログインしていれば誰でも見られて、誰でも追加できる
 *  ・削除は追加した人と管理者だけ
 *  ・付けたものは、ライブ一覧の年度の見出し・ライブのカード・ライブページにアイコンで出る（lib/special.php）
 *  ・ファイルは lib/live_image.php で検査して storage/live_images/ に保存（画像は縮小、PDF はそのまま、その他はダウンロードだけ）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/attach_image.php';
require_once __DIR__ . '/lib/special.php';
require_once __DIR__ . '/lib/youtube.php';
$user = require_login();
$pdo = db();

// 付け先の候補: 年度 → その年度のライブ（新しい順）
// ライブは年度内で開催日の早い順（開催日が無いものは最後）
$lives = $pdo->query('SELECT l.live_id, l.fiscal_year, l.name FROM live l
    LEFT JOIN live_day d ON d.live_id = l.live_id
    GROUP BY l.live_id, l.fiscal_year, l.name
    ORDER BY l.fiscal_year DESC, MIN(d.held_on) IS NULL, MIN(d.held_on), l.live_id')->fetchAll();
$liveById = array_column($lives, null, 'live_id');
$years = array_unique(array_merge([current_fiscal_year()], array_map('intval', array_column($lives, 'fiscal_year'))));
rsort($years);

if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $st = $pdo->prepare('SELECT * FROM special_content WHERE special_id = ?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        $row = $st->fetch();
        if ($row && ((int)$row['user_id'] === (int)$user['user_id'] || is_admin())) {
            $pdo->prepare('DELETE FROM special_content WHERE special_id = ?')->execute([(int)$row['special_id']]);
            if ($row['file_name'] !== null) {
                live_image_unlink((string)$row['file_name']);
            }
            flash('削除しました');
        }
        redirect('special');
    }

    // 編集（タイトルと作者。追加した人と管理者だけ）
    if (($_POST['action'] ?? '') === 'edit') {
        $title = mb_substr(trim(mb_scrub((string)($_POST['title'] ?? ''))), 0, 100);
        $author = mb_substr(trim(mb_scrub((string)($_POST['author'] ?? ''))), 0, 50) ?: null;
        $st = $pdo->prepare('SELECT user_id FROM special_content WHERE special_id = ?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        $owner = $st->fetchColumn();
        if ($title === '') {
            flash('タイトルを入力してください', 'error');
        } elseif ($owner !== false && ((int)$owner === (int)$user['user_id'] || is_admin())) {
            $pdo->prepare('UPDATE special_content SET title = ?, author = ? WHERE special_id = ?')->execute([$title, $author, (int)$_POST['id']]);
            flash('保存しました');
        }
        redirect('special');
    }

    // 追加
    $title = mb_substr(trim(mb_scrub((string)($_POST['title'] ?? ''))), 0, 100);
    $author = mb_substr(trim(mb_scrub((string)($_POST['author'] ?? ''))), 0, 50) ?: null;
    $target = (string)($_POST['target'] ?? '');
    $url = trim((string)($_POST['url'] ?? ''));
    $file = $_FILES['file'] ?? null;
    $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $year = 0;
    $liveId = null;
    if (preg_match('/^y(\d{4})$/', $target, $m) && in_array((int)$m[1], $years, true)) {
        $year = (int)$m[1];
    } elseif (preg_match('/^l(\d+)$/', $target, $m) && isset($liveById[(int)$m[1]])) {
        $liveId = (int)$m[1];
        $year = (int)$liveById[$liveId]['fiscal_year'];
    }
    // URL は http(s) だけ（javascript: などを href に入れさせない）
    $urlOk = $url !== '' && strlen($url) <= 500 && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url);
    $error = match (true) {
        $title === '' => 'タイトルを入力してください',
        $year === 0 => '年度かライブを選んでください',
        $hasFile === ($url !== '') => 'URL かファイルのどちらかを指定してください',
        $url !== '' && !$urlOk => 'URL が正しくありません',
        default => null,
    };
    if ($error === null) {
        try {
            $fileName = $hasFile ? live_attach_store('special', $file)['file_name'] : null;
            $pdo->prepare('INSERT INTO special_content (fiscal_year, live_id, title, author, url, file_name, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$year, $liveId, $title, $author, $hasFile ? null : $url, $fileName, (int)$user['user_id']]);
            flash('追加しました');
            redirect('special');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
    flash($error, 'error');
    redirect('special');
}

// 一覧: 年度ごと（新しい順）。消えたライブに付いていたものは出さない（履歴から戻すと出る）
$rows = $pdo->query('SELECT s.*, l.name AS live_name FROM special_content s
    LEFT JOIN live l ON l.live_id = s.live_id
    WHERE s.live_id IS NULL OR l.live_id IS NOT NULL
    ORDER BY s.fiscal_year DESC, s.live_id IS NOT NULL, s.live_id DESC, s.special_id')->fetchAll();
$byYear = [];
foreach ($rows as $r) {
    $byYear[(int)$r['fiscal_year']][] = $r;
}

render_header('スペシャルコンテンツ', 'tools');
$fileKind = static fn(array $r): string => match (true) {
    $r['file_name'] === null => $r['url'] !== null && youtube_video_id((string)$r['url']) !== null ? 'yt' : 'link',
    live_file_is_pdf((string)$r['file_name']) => 'pdf',
    live_file_is_other((string)$r['file_name']) => 'other',
    default => 'image',
};
?>
<nav class="crumbs"><a href="tools">ツール</a></nav>
<h1 class="display display--sm">スペシャルコンテンツ</h1>

<div class="toolbar">
    <?php // 年度スロット・検索は index.php（ライブ一覧）と同じ。assets/app_list.js が section[data-year] と [data-filter] を動かす ?>
    <div class="year-slot year-slot--live" data-year-slot tabindex="0" role="spinbutton" aria-label="年度で絞り込み" title="上下にドラッグで年度を切り替え">
        <div class="year-slot__reel">
            <div class="year-slot__item" data-value="">すべて</div>
            <?php foreach (array_keys($byYear) as $y): ?>
                <div class="year-slot__item" data-value="<?= (int)$y ?>" data-label="<?= (int)$y ?>年度"><span class="year-slot__full"><?= (int)$y ?><small>年度</small></span><span class="year-slot__short"><?= sprintf("'%02d", $y % 100) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <input type="search" autocomplete="off" class="search" placeholder="タイトル・ライブ名・作者で検索" data-filter=".special-card" aria-label="検索">
    <button type="button" class="btn btn--primary" data-special-add><?= icon('add') ?> 追加</button>
</div>

<?= render_suggest_datalist('member-names', array_keys($names = member_name_choices($pdo)), $names) ?>
<dialog class="modal" data-special-add-dialog aria-label="スペシャルコンテンツを追加">
    <form method="post" enctype="multipart/form-data" class="modal__body form-card"<?= unsaved_attr() ?>>
        <?= csrf_field() ?>
        <h2 class="modal__title">追加</h2>
        <label class="field"><span>タイトル</span>
            <input type="text" name="title" maxlength="100" required>
        </label>
        <label class="field"><span>作者</span>
            <input type="text" name="author" maxlength="50" data-suggest-list="member-names" autocomplete="off">
        </label>
        <label class="field"><span>年度 / ライブ</span>
            <select name="target" required>
                <?php foreach ($years as $y): ?>
                    <option value="y<?= (int)$y ?>"><?= h(fmt_year($y)) ?></option>
                    <?php foreach ($lives as $l): if ((int)$l['fiscal_year'] !== $y) continue; ?>
                        <option value="l<?= (int)$l['live_id'] ?>">&emsp;<?= h($l['name']) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>URL</span>
            <input type="url" name="url" maxlength="500" placeholder="https://">
        </label>
        <label class="field"><span>ファイル</span>
            <input type="file" name="file">
        </label>
        <div class="form-actions">
            <button class="btn btn--ghost btn--sm" type="button" data-special-add-close>キャンセル</button>
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('add') ?> 追加</button>
        </div>
    </form>
</dialog>

<?php // 編集（削除もここ）。1つの <dialog> を、押したカードの値で使い回す（assets/special.js） ?>
<dialog class="modal" data-special-edit-dialog aria-label="編集">
    <div class="modal__body form-card">
        <form method="post" class="form-card"<?= unsaved_attr() ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="">
            <h2 class="modal__title">編集</h2>
            <label class="field"><span>タイトル</span>
                <input type="text" name="title" maxlength="100" required>
            </label>
            <label class="field"><span>作者</span>
                <input type="text" name="author" maxlength="50" data-suggest-list="member-names" autocomplete="off">
            </label>
            <div class="form-actions">
                <button class="btn btn--ghost btn--sm" type="button" data-special-edit-close>キャンセル</button>
                <button class="btn btn--primary btn--sm" type="submit">保存</button>
            </div>
        </form>
        <form method="post" onsubmit="return confirm('削除しますか？')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="">
            <button type="submit" class="btn btn--ghost btn--sm" aria-label="削除" title="削除"><?= icon('delete') ?> 削除</button>
        </form>
    </div>
</dialog>

<dialog class="modal special-modal" data-special-modal>
    <iframe title="" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen hidden data-special-frame></iframe>
    <img alt="" hidden data-special-img>
    <p class="live-modal__bar">
        <span class="special-modal__title" data-special-title></span>
        <a class="btn btn--sm" href="#" download data-special-dl aria-label="ダウンロード" title="ダウンロード"><?= icon('download') ?></a>
        <button type="button" class="btn btn--ghost btn--sm" data-special-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
    </p>
</dialog>

<?php foreach ($byYear as $y => $list): ?>
<section class="special-year" data-year="<?= (int)$y ?>">
    <h2><?= h(fmt_year($y)) ?></h2>
    <div class="grid special-grid">
        <?php foreach ($list as $r):
            $kind = $fileKind($r);
            $src = special_href($r);
            $vid = $kind === 'yt' ? youtube_video_id((string)$r['url']) : null;
            $thumb = $vid !== null ? 'https://i.ytimg.com/vi/' . rawurlencode($vid) . '/mqdefault.jpg'
                : ($r['file_name'] !== null ? $src . '&t=1' : null);
            $sub = implode(' · ', array_filter([$r['live_name'], $r['author']], 'is_string'));
            $popup = in_array($kind, ['yt', 'image', 'pdf'], true); ?>
            <div class="card special-card" data-text="<?= h($r['title'] . ' ' . $sub) ?>">
                <?php // 動画・画像・PDF は押すとポップアップで開く。ファイルはダウンロード、リンクは別タブ ?>
                <?php if ($popup): ?>
                    <button type="button" class="special-card__open" data-special-open data-kind="<?= h($kind) ?>"
                        data-src="<?= h($vid !== null ? 'https://www.youtube.com/embed/' . rawurlencode($vid) . '?autoplay=1&playsinline=1&rel=0' : $src) ?>"
                        data-dl="<?= h($r['file_name'] !== null ? $src . '&dl=1' : (string)$r['url']) ?>" data-title="<?= h($r['title']) ?>">
                <?php else: ?>
                    <a class="special-card__open" href="<?= h($kind === 'other' ? $src . '&dl=1' : $src) ?>"<?= $kind === 'link' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?php endif; ?>
                    <span class="special-card__thumb"><?= $thumb !== null ? '<img src="' . h($thumb) . '" alt="" loading="lazy">' : icon('link') ?></span>
                    <span class="special-card__title"><?= h($r['title']) ?></span>
                    <?php if ($sub !== ''): ?><span class="muted small"><?= h($sub) ?></span><?php endif; ?>
                <?= $popup ? '</button>' : '</a>' ?>
                <?php if ((int)$r['user_id'] === (int)$user['user_id'] || is_admin()): ?>
                    <button type="button" class="btn btn--ghost btn--sm special-card__edit" data-special-edit data-id="<?= (int)$r['special_id'] ?>"
                        data-title="<?= h($r['title']) ?>" data-author="<?= h((string)$r['author']) ?>" aria-label="編集" title="編集"><?= icon('edit') ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<script src="<?= asset_url('assets/special.js') ?>" defer></script>
<?php render_footer();
