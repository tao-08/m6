<?php
/**
 * =====================================================================
 *  band_survey.php — バンド希望調査（卒業ライブのバンドの熱量。見られる人は lib/band_survey.php の band_survey_can_view）
 * =====================================================================
 *  band_survey        … 一覧 + アップロード
 *  band_survey?id=12  … 集計の表
 *  POST（すべて CSRF チェック）: create（CSV を読んで暗号化して保存）/ delete
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/band_survey.php';
$user = require_login();
if (!band_survey_can_view()) {
    error_page(403, 'このページは見られません');
}
$pdo = db();

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $file = $_FILES['csv'] ?? null;
        if ($title === '' || mb_strlen($title) > 100 || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
            flash('調査名は100字まで、CSV は2MBまでで指定してください', 'error');
            redirect('band_survey');
        }
        $text = (string)file_get_contents($file['tmp_name']);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'SJIS-win'); // Excel の「CSV」で保存したもの
        }
        $res = band_survey_parse_csv($text);
        if (!$res['rows'] && !$res['errors']) {
            $res['errors'][] = '行がありません';
        }
        if ($res['errors']) {
            flash(implode(' / ', array_slice($res['errors'], 0, 5)) . (count($res['errors']) > 5 ? ' ほか' . (count($res['errors']) - 5) . '件' : ''), 'error');
            redirect('band_survey');
        }
        redirect('band_survey?id=' . band_survey_save($pdo, $title, $res['rows'], (int)$user['user_id']));
    }
    if ($action === 'delete') {
        band_survey_delete($pdo, (int)($_POST['id'] ?? 0));
        flash('削除しました');
    }
    redirect('band_survey');
}

$id = (int)($_GET['id'] ?? 0);

/* ==================== 集計 ==================== */
if ($id > 0) {
    $survey = band_survey_load($pdo, $id);
    if ($survey === null) {
        error_page(404);
    }
    render_header($survey['title'] . ' — バンド希望調査', 'tools');
    ?>
    <nav class="crumbs"><a href="tools?tab=admin">ツール</a><span>/</span><a href="band_survey">バンド希望調査</a></nav>
    <h1 class="display display--sm"><?= h($survey['title']) ?></h1>
    <?php if ($survey['rows'] === null): ?>
        <p class="card">読み込めませんでした</p>
    <?php else: $bands = band_survey_summarize($survey['rows']); ?>
        <div class="card table-card">
            <div class="table-scroll table-scroll--flush">
            <table class="table">
                <thead><tr>
                    <th>バンド</th>
                    <th>熱量 <button type="button" class="info-tip" data-tip="1位を1、最下位を0にそろえた平均" aria-label="補足を表示"><?= icon('info') ?></button></th>
                    <th>回答</th>
                    <th>全員が下位</th>
                </tr></thead>
                <tbody>
                <?php foreach ($bands as $b): ?>
                    <tr<?= $b['low_all'] ? ' class="muted"' : '' ?>>
                        <td>
                            <details>
                                <summary class="strong"><?= h($b['band']) ?></summary>
                                <ul>
                                <?php foreach ($b['members'] as $m): ?>
                                    <li><?= h($m['member']) ?> <?= $m['rank'] === null ? '—' : (int)$m['rank'] . '/' . (int)$m['n'] ?></li>
                                <?php endforeach; ?>
                                </ul>
                            </details>
                        </td>
                        <td>
                            <?php if ($b['heat'] !== null): ?>
                                <div class="row-form">
                                    <span class="bar-track"><span class="bar" style="--w: <?= round($b['heat'] * 100) ?>%"></span></span>
                                    <span class="bar-num"><?= number_format($b['heat'], 2) ?></span>
                                </div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="num"><?= (int)$b['answered'] ?>/<?= (int)$b['total'] ?></td>
                        <td><?= $b['low_all'] ? icon('check') : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
    <form method="post" data-confirm="「<?= h($survey['title']) ?>」を削除します。元に戻せません。よろしいですか？">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$survey['survey_id'] ?>">
        <button class="btn btn--danger" type="submit"><?= icon('delete') ?> 削除</button>
    </form>
    <?php
    render_footer();
    exit;
}

/* ==================== 一覧 ==================== */
render_header('バンド希望調査', 'tools');
?>
<nav class="crumbs"><a href="tools?tab=admin">ツール</a></nav>
<h1 class="display display--sm">バンド希望調査</h1>
<form method="post" enctype="multipart/form-data" class="card form-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label class="field">調査名<input type="text" name="title" maxlength="100" required></label>
    <label class="field">CSV<input type="file" name="csv" accept=".csv,text/csv" required></label>
    <button class="btn btn--primary" type="submit">追加</button>
</form>
<div class="tool-list">
    <?php foreach (band_survey_list($pdo) as $s): ?>
        <a class="card tool-card" href="band_survey?id=<?= (int)$s['survey_id'] ?>">
            <span class="tool-card__icon"><?= icon('poll') ?></span>
            <span class="tool-card__body">
                <span class="tool-card__name"><?= h($s['title']) ?></span>
                <span class="muted small"><?= h(fmt_date($s['created_at'])) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php render_footer();
