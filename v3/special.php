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

    // 追加
    $title = mb_substr(trim(mb_scrub((string)($_POST['title'] ?? ''))), 0, 100);
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
            $pdo->prepare('INSERT INTO special_content (fiscal_year, live_id, title, url, file_name, user_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$year, $liveId, $title, $hasFile ? null : $url, $fileName, (int)$user['user_id']]);
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
$rows = $pdo->query('SELECT s.*, l.name AS live_name, u.name AS user_name FROM special_content s
    LEFT JOIN live l ON l.live_id = s.live_id LEFT JOIN user_account u ON u.user_id = s.user_id
    WHERE s.live_id IS NULL OR l.live_id IS NOT NULL
    ORDER BY s.fiscal_year DESC, s.live_id IS NOT NULL, s.live_id DESC, s.special_id')->fetchAll();
$byYear = [];
foreach ($rows as $r) {
    $byYear[(int)$r['fiscal_year']][] = $r;
}

render_header('スペシャルコンテンツ', 'tools');
?>
<nav class="crumbs"><a href="tools">ツール</a></nav>
<h1 class="display display--sm">スペシャルコンテンツ</h1>

<form method="post" enctype="multipart/form-data" class="card form-card"<?= unsaved_attr() ?>>
    <?= csrf_field() ?>
    <label class="field"><span>タイトル</span>
        <input type="text" name="title" maxlength="100" required>
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
    <div class="form-actions"><button class="btn btn--primary btn--sm" type="submit"><?= icon('add') ?> 追加</button></div>
</form>

<?php foreach ($byYear as $y => $list): ?>
<section class="special-year">
    <h2><?= h(fmt_year($y)) ?></h2>
    <ul class="special-rows">
        <?php foreach ($list as $r): ?>
            <li>
                <?= special_link_html($r) ?>
                <span class="muted small"><?= h(implode(' · ', array_filter([$r['live_name'], $r['user_name']], 'is_string'))) ?></span>
                <?php if ((int)$r['user_id'] === (int)$user['user_id'] || is_admin()): ?>
                    <form method="post" onsubmit="return confirm('削除しますか？')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['special_id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm" aria-label="削除" title="削除"><?= icon('delete') ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>
<?php render_footer();
