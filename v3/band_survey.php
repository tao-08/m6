<?php
/**
 * =====================================================================
 *  band_survey.php — バンド希望調査（卒業ライブのバンドの熱量。見られる人は lib/band_survey.php の band_survey_can_view）
 * =====================================================================
 *  band_survey        … 一覧 + 追加（名簿のファイル + LINE の回答）。照合した下書きがセッションにあれば確認画面
 *  band_survey?id=12  … 集計の表
 *  POST（すべて CSRF チェック）: read（Claude で照合して下書きに）/ save（直した順位を暗号化して保存）/ reset / delete
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
    if ($action === 'read') {
        $title = trim((string)($_POST['title'] ?? ''));
        $answers = trim((string)($_POST['answers'] ?? ''));
        $file = $_FILES['roster'] ?? null;
        if ($title === '' || mb_strlen($title) > 100 || $answers === '' || mb_strlen($answers) > 50000
            || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            flash('調査名は100字まで、名簿は5MBまでで指定してください', 'error');
            redirect('band_survey');
        }
        try {
            $roster = band_survey_read_roster($file['tmp_name'], (string)$file['name']);
        } catch (Throwable $e) {
            flash('名簿: ' . $e->getMessage(), 'error');
            redirect('band_survey');
        }
        $m = band_survey_match($roster, $answers);
        if (is_string($m)) {
            flash($m, 'error');
            redirect('band_survey');
        }
        $_SESSION['band_survey_draft'] = ['title' => $title, 'roster' => $roster] + $m;
        redirect('band_survey');
    }
    $draft = $_SESSION['band_survey_draft'] ?? null;
    if ($action === 'save' && $draft) {
        // 入力は r[メンバーの番号][バンドの番号]。保存する行は名簿の順に [バンド, メンバー, 順位|null]
        $draft['ranks'] = [];
        foreach (band_survey_draft_members($draft['roster']) as $mi => [$member, $bands]) {
            foreach ($bands as $bi => $band) {
                $v = trim((string)($_POST['r'][$mi][$bi] ?? ''));
                if (ctype_digit($v) && (int)$v > 0) {
                    $draft['ranks'][$member][$band] = (int)$v;
                }
            }
        }
        $rows = [];
        foreach ($draft['roster'] as $b) {
            foreach ($b['members'] as $member) {
                $rows[] = [$b['band'], $member, $draft['ranks'][$member][$b['band']] ?? null];
            }
        }
        $_SESSION['band_survey_draft'] = $draft;
        $errors = band_survey_check_ranks($rows);
        if ($errors) {
            flash(implode(' / ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' ほか' . (count($errors) - 5) . '件' : ''), 'error');
            redirect('band_survey');
        }
        unset($_SESSION['band_survey_draft']);
        redirect('band_survey?id=' . band_survey_save($pdo, $draft['title'], $rows, (int)$user['user_id']));
    }
    if ($action === 'reset') {
        unset($_SESSION['band_survey_draft']);
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

/* ==================== 確認（照合の結果を直して保存） ==================== */
$draft = $_SESSION['band_survey_draft'] ?? null;
if ($draft) {
    render_header('確認 — バンド希望調査', 'tools');
    ?>
    <nav class="crumbs"><a href="tools?tab=admin">ツール</a><span>/</span><a href="band_survey">バンド希望調査</a></nav>
    <h1 class="display display--sm"><?= h($draft['title']) ?></h1>
    <?php if ($draft['issues']): ?>
        <div class="flash flash--warn"><ul><?php foreach ($draft['issues'] as $is): ?><li><?= h($is) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">
        <div class="card table-card"><div class="table-scroll table-scroll--flush">
        <table class="table table--edit">
            <thead><tr><th>メンバー</th><th>バンド</th><th>順位</th></tr></thead>
            <tbody>
            <?php foreach (band_survey_draft_members($draft['roster']) as $mi => [$member, $bands]): ?>
                <?php foreach ($bands as $bi => $band): $written = $draft['written'][$member][$band] ?? null; ?>
                    <tr>
                        <?php if ($bi === 0): ?>
                            <td rowspan="<?= count($bands) ?>"><span class="strong"><?= h($member) ?></span>
                                <?php if (isset($draft['sources'][$member])): ?><br><span class="muted small">LINE「<?= h($draft['sources'][$member]) ?>」</span><?php endif; ?></td>
                        <?php endif; ?>
                        <td><?php if ($written !== null && $written !== $band): ?><span class="survey-written" <?= tip_attr('回答: ' . $written) ?>><?= h($band) ?></span><?php else: ?><?= h($band) ?><?php endif; ?></td>
                        <td><input type="number" class="input-num" min="1" name="r[<?= $mi ?>][<?= $bi ?>]" value="<?= h((string)($draft['ranks'][$member][$band] ?? '')) ?>" aria-label="<?= h($member . ' ' . $band) ?>の順位"></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div></div>
        <button class="btn btn--primary" type="submit">保存</button>
    </form>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset">
        <button class="btn" type="submit">やり直す</button>
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
    <?= csrf_field() ?><input type="hidden" name="action" value="read">
    <label class="field">調査名<input type="text" name="title" maxlength="100" required></label>
    <label class="field">名簿<input type="file" name="roster" accept=".pdf,.xlsx,.xlsm,.csv" required></label>
    <label class="field">LINE の回答<textarea name="answers" rows="8" maxlength="50000" required></textarea></label>
    <button class="btn btn--primary" type="submit">照合</button>
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
