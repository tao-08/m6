<?php
/**
 * =====================================================================
 *  band_survey.php — バンド希望調査（卒業ライブのバンドの優先度。見られる人は lib/band_survey.php の band_survey_can_view）
 * =====================================================================
 *  band_survey              … 一覧 + 追加（名簿のファイル）。読んだ名簿がセッションにあれば名前の確認画面
 *  band_survey?id=12        … 名簿の形の表（優先度で色分け）+ 1人分の LINE の回答を貼る欄。照合した回答がセッションにあれば確認画面
 *  band_survey?id=12&sort=heat … 優先度の高い順
 *  POST（すべて CSRF チェック）:
 *    roster（名簿を読む）/ create（名前を直して調査を作る）/ reset（名簿を読み直す）
 *    match（1人分の回答を照合）/ pick（回答した人を選び直す）/ answer（順位を足す）/ cancel / delete
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
    $id = (int)($_POST['id'] ?? 0);
    $back = $id > 0 ? 'band_survey?id=' . $id : 'band_survey';

    if ($action === 'roster') {
        $title = trim((string)($_POST['title'] ?? ''));
        $file = $_FILES['roster'] ?? null;
        if ($title === '' || mb_strlen($title) > 100 || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            flash('調査名は100字まで、名簿は5MBまでで指定してください', 'error');
            redirect('band_survey');
        }
        $year = (int)($_POST['year'] ?? 0);
        try {
            $roster = band_survey_read_roster($file['tmp_name'], (string)$file['name']);
            $_SESSION['band_survey_roster'] = ['title' => $title, 'year' => $year, 'roster' => $roster, 'targets' => band_survey_default_targets($pdo, $roster, $year)];
        } catch (Throwable $e) {
            flash('名簿: ' . $e->getMessage(), 'error');
        }
        redirect('band_survey');
    }
    if ($action === 'create' && isset($_SESSION['band_survey_roster'])) {
        $draft = $_SESSION['band_survey_roster'];
        $map = [];
        $targets = [];
        foreach (band_survey_people($draft['roster']) as $mi => [$member]) {
            $name = (string)($_POST['n'][$mi] ?? '');
            $name = mb_check_encoding($name, 'UTF-8') ? mb_substr(trim($name), 0, 50) : ''; // 文字化けは元の名前のまま
            if ($name !== '' && $name !== $member) {
                $map[$member] = $name;
            }
            if (!empty($_POST['t'][$mi])) {
                $targets[] = $name !== '' ? $name : $member;
            }
        }
        unset($_SESSION['band_survey_roster']);
        $roster = band_survey_rename($draft['roster'], $map);
        $targets = array_values(array_unique($targets));
        $old = isset($draft['survey_id']) ? band_survey_load($pdo, (int)$draft['survey_id']) : null;
        if ($old !== null && $old['data'] !== null) { // 名簿の読み直し: 人とバンドが同じ回答を残す
            band_survey_update($pdo, $old['survey_id'], band_survey_merge($old['data'], $roster, $targets));
            flash('名簿を更新しました');
            redirect('band_survey?id=' . $old['survey_id']);
        }
        $data = ['roster' => $roster, 'targets' => $targets, 'ranks' => [], 'year' => (int)($draft['year'] ?? 0)];
        redirect('band_survey?id=' . band_survey_save($pdo, $draft['title'], $data, (int)$user['user_id']));
    }
    if ($action === 'reset') {
        unset($_SESSION['band_survey_roster']);
        redirect('band_survey');
    }
    if ($action === 'delete') {
        band_survey_delete($pdo, $id);
        unset($_SESSION['band_survey_answer']);
        flash('削除しました');
        redirect('band_survey');
    }

    // ここから下は1つの調査への回答
    $survey = band_survey_load($pdo, $id);
    if ($survey === null || $survey['data'] === null) {
        redirect('band_survey');
    }
    $roster = $survey['data']['roster'];
    if ($action === 'cell') {
        if (!mb_check_encoding((string)($_POST['new'] ?? ''), 'UTF-8') || !mb_check_encoding((string)($_POST['old'] ?? ''), 'UTF-8')) {
            redirect($back); // 文字化けした名前は保存しない（暗号化の前の JSON にできない）
        }
        $new = mb_substr(trim((string)($_POST['new'] ?? '')), 0, 50);
        if (($_POST['op'] ?? '') === 'remove') {
            $new = '';
        }
        $data = band_survey_set_cell($survey['data'], (int)($_POST['bi'] ?? -1), (int)($_POST['ci'] ?? -1), (string)($_POST['old'] ?? ''), $new, !empty($_POST['t']));
        band_survey_update($pdo, $id, $data);
        redirect($back . (($_POST['sort'] ?? '') === 'heat' ? '&sort=heat' : ''));
    }
    if ($action === 'reroster') {
        $file = $_FILES['roster'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            flash('名簿は5MBまでで指定してください', 'error');
            redirect($back);
        }
        try {
            $new = band_survey_read_roster($file['tmp_name'], (string)$file['name']);
        } catch (Throwable $e) {
            flash('名簿: ' . $e->getMessage(), 'error');
            redirect($back);
        }
        // 対象の初期値: 前の対象の人 + 調査の学年に入部した人
        $year = (int)($survey['data']['year'] ?? 0);
        $targets = array_merge($survey['data']['targets'], $year > 0 ? band_survey_default_targets($pdo, $new, $year) : []);
        $_SESSION['band_survey_roster'] = ['title' => $survey['title'], 'survey_id' => $id, 'roster' => $new, 'targets' => array_values(array_unique($targets))];
        redirect('band_survey');
    }
    if ($action === 'match') {
        $text = trim((string)($_POST['answer'] ?? ''));
        if ($text === '' || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 5000) {
            flash('回答は5000字までで貼ってください', 'error');
            redirect($back);
        }
        $ai = band_survey_match($roster, $text);
        if (is_string($ai)) {
            flash($ai, 'error');
            redirect($back);
        }
        $_SESSION['band_survey_answer'] = ['id' => $id, 'ai' => $ai, 'text' => $text] + band_survey_apply($roster, $ai, null, $survey['data']['targets']);
        redirect($back);
    }
    $draft = $_SESSION['band_survey_answer'] ?? null;
    if ($draft === null || $draft['id'] !== $id) {
        redirect($back);
    }
    if ($action === 'pick') {
        $_SESSION['band_survey_answer'] = ['id' => $id, 'ai' => $draft['ai'], 'text' => $draft['text']] + band_survey_apply($roster, $draft['ai'], (string)($_POST['member'] ?? ''), $survey['data']['targets']);
        redirect($back);
    }
    if ($action === 'answer') {
        $people = array_column(band_survey_people($roster), 1, 0);
        $member = (string)($_POST['member'] ?? '');
        if (!isset($people[$member]) || !in_array($member, $survey['data']['targets'], true)) {
            redirect($back);
        }
        $ranks = [];
        foreach ($people[$member] as $bi => $band) {
            $v = trim((string)($_POST['r'][$bi] ?? ''));
            if (ctype_digit($v) && (int)$v > 0) {
                $ranks[$band] = (int)$v;
            }
        }
        $_SESSION['band_survey_answer']['ranks'] = $ranks;
        $why = band_survey_check_ranks($ranks);
        if ($why !== null) {
            flash($why, 'error');
            redirect($back);
        }
        $data = $survey['data'];
        if ($ranks) {
            $data['ranks'][$member] = $ranks;
        } else {
            unset($data['ranks'][$member]);
        }
        band_survey_update($pdo, $id, $data);
        unset($_SESSION['band_survey_answer']);
        flash($member . ' の回答を追加しました');
        redirect($back);
    }
    if ($action === 'cancel') {
        unset($_SESSION['band_survey_answer']);
    }
    redirect($back);
}

$id = (int)($_GET['id'] ?? 0);

/* ==================== 調査（名簿の形の表） ==================== */
if ($id > 0) {
    $survey = band_survey_load($pdo, $id);
    if ($survey === null) {
        error_page(404);
    }
    render_header($survey['title'] . ' — バンド希望調査', 'tools');
    ?>
    <nav class="crumbs"><a href="tools?tab=admin">ツール</a><span>/</span><a href="band_survey">バンド希望調査</a></nav>
    <h1 class="display display--sm"><?= h($survey['title']) ?></h1>
    <?php if ($survey['data'] === null): ?>
        <p class="card">読み込めませんでした</p>
    <?php else:
        $data = $survey['data'];
        $roster = $data['roster'];
        $people = array_column(band_survey_people($roster), 1, 0);
        $isTarget = array_flip($data['targets']);
        $byHeat = ($_GET['sort'] ?? '') === 'heat';
        $n = array_map('count', $data['ranks']); // その人が順位を付けた数
        $sum = array_column(band_survey_summarize(band_survey_rows($data)), null, 'band');
        $draft = ($_SESSION['band_survey_answer']['id'] ?? 0) === $id ? $_SESSION['band_survey_answer'] : null;
        ?>

        <?php if ($draft): ?>
            <!-- 照合した1人分の確認 -->
            <section class="card form-card">
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="pick"><input type="hidden" name="id" value="<?= $id ?>">
                    <label class="field">回答した人
                        <select name="member" data-autosubmit>
                            <option value="">選んでください</option>
                            <?php foreach ($data['targets'] as $m): $m = (string)$m; ?>
                                <option value="<?= h($m) ?>"<?= $m === $draft['member'] ? ' selected' : '' ?>><?= h($m) ?><?= isset($data['ranks'][$m]) ? ' ✓' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
                <?php if ($draft['ai']['line_name'] !== ''): ?><p class="muted small">LINE「<?= h($draft['ai']['line_name']) ?>」</p><?php endif; ?>
                <?php if ($draft['issues']): ?>
                    <div class="flash flash--warn"><ul><?php foreach ($draft['issues'] as $is): ?><li><?= h($is) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <details><summary class="muted small">貼った回答</summary><p class="survey-text"><?= nl2br(h($draft['text'])) ?></p></details>
                <?php if ($draft['member'] !== ''): ?>
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="member" value="<?= h($draft['member']) ?>">
                        <table class="table table--edit survey-edit">
                            <thead><tr><th>バンド</th><th>順位</th></tr></thead>
                            <tbody>
                            <?php foreach ($people[$draft['member']] as $bi => $band): $written = $draft['written'][$band] ?? null; ?>
                                <tr>
                                    <td><?php if ($written !== null && $written !== $band): ?><span class="survey-written" <?= tip_attr('回答: ' . $written) ?>><?= h($band) ?></span><?php else: ?><?= h($band) ?><?php endif; ?></td>
                                    <td><input type="number" class="input-num" min="1" name="r[<?= $bi ?>]" value="<?= h((string)($draft['ranks'][$band] ?? '')) ?>" aria-label="<?= h($band) ?>の順位"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button class="btn btn--primary" type="submit">追加</button>
                    </form>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn" type="submit">やめる</button>
                </form>
            </section>
        <?php else: ?>
            <!-- 1人分の回答を貼る -->
            <form method="post" class="card form-card" data-loading>
                <?= csrf_field() ?><input type="hidden" name="action" value="match"><input type="hidden" name="id" value="<?= $id ?>">
                <label class="field">LINE の回答<textarea name="answer" rows="5" maxlength="5000" required placeholder="1人分の回答を貼る"></textarea></label>
                <div class="loading" data-loading-box hidden role="status" aria-live="polite">
                    <span class="loading__spinner" aria-hidden="true"></span>
                    <p class="loading__title" data-loading-title>照合中…</p>
                    <p class="muted small" data-loading-sub>このままお待ちください</p>
                </div>
                <button class="btn btn--primary" type="submit">照合</button>
            </form>
        <?php endif; ?>

        <?php
        $bands = $roster['bands'];
        if ($byHeat) {
            $order = array_flip(array_keys($sum)); // summarize は優先度の高い順
            uasort($bands, static fn($a, $b) => ($order[$a['band']] ?? PHP_INT_MAX) <=> ($order[$b['band']] ?? PHP_INT_MAX)); // 対象の人がいないバンドは最後
        }
        ?>
        <?php
        $memberNames = member_name_choices($pdo);
        echo render_suggest_datalist('member-names', array_keys($memberNames), $memberNames);
        // 表の1セル: 押すと「入れ替える / 外す」（空欄・未定は「追加」）のフォームが開く
        $cellForm = static function (int $bi, int $ci, string $old, string $label, bool $target) use ($id, $byHeat): string {
            $person = $old !== '' && $old !== BAND_SURVEY_TBD;
            return '<details class="survey-cell"><summary>' . $label . '</summary>'
                . '<form method="post" class="survey-cell-form">' . csrf_field()
                . '<input type="hidden" name="action" value="cell"><input type="hidden" name="id" value="' . $id . '">'
                . '<input type="hidden" name="bi" value="' . $bi . '"><input type="hidden" name="ci" value="' . $ci . '">'
                . '<input type="hidden" name="old" value="' . h($old) . '"><input type="hidden" name="sort" value="' . ($byHeat ? 'heat' : '') . '">'
                . '<input name="new" maxlength="50" value="' . ($person ? h($old) : '') . '" data-suggest-list="member-names" autocomplete="off" aria-label="名前" required>'
                . '<label class="survey-cell-form__check"><input type="checkbox" name="t" value="1"' . ($target ? ' checked' : '') . '>対象</label>'
                . '<button class="btn btn--sm btn--primary" type="submit">' . ($person ? '変更' : '追加') . '</button>'
                . ($person ? '<button class="btn btn--sm btn--danger" type="submit" name="op" value="remove" formnovalidate>外す</button>' : '')
                . '</form></details>';
        };
        ?>
        <p class="muted small">回答 <?= count(array_intersect_key($data['ranks'], $isTarget)) ?>/<?= count($data['targets']) ?>人</p>
        <div class="tabs">
            <a class="tab<?= $byHeat ? '' : ' is-active' ?>" href="band_survey?id=<?= $id ?>">名簿の順</a>
            <a class="tab<?= $byHeat ? ' is-active' : '' ?>" href="band_survey?id=<?= $id ?>&amp;sort=heat">優先度の順</a>
        </div>
        <div class="card table-card">
            <div class="table-scroll table-scroll--flush">
            <table class="table survey-map">
                <caption class="survey-legend"><span class="survey-chip" style="--heat: 1">1位</span><span class="survey-chip" style="--heat: 0">最下位</span><span class="survey-chip is-none">未回答</span><span class="survey-chip is-out">対象外</span></caption>
                <thead><tr>
                    <th>バンド</th>
                    <th>優先度 <button type="button" class="info-tip" data-tip="1位を1、最下位を0にそろえた平均。名前の色は濃いほど上位" aria-label="補足を表示"><?= icon('info') ?></button></th>
                    <?php foreach ($roster['columns'] as $title): ?><th><?= h($title) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($bands as $bi => $b): $s = $sum[$b['band']] ?? ['heat' => null, 'low_all' => false]; ?>
                    <tr>
                        <td class="strong"><?= h($b['band']) ?><?php if ($s['low_all']): ?> <span class="survey-low" <?= tip_attr('全員が下位') ?>><?= icon('arrow_downward', 'icon--sm') ?></span><?php endif; ?></td>
                        <td class="num"><?= $s['heat'] === null ? '—' : number_format($s['heat'], 2) ?></td>
                        <?php foreach ($b['cells'] as $ci => $cell): ?>
                            <td><?php foreach ($cell as $m):
                                $m = (string)$m;
                                if ($m === BAND_SURVEY_TBD) {
                                    continue;
                                }
                                $rank = isset($isTarget[$m]) ? ($data['ranks'][$m][$b['band']] ?? null) : null;
                                $score = band_survey_score($rank, $n[$m] ?? 0);
                                $chip = '<span class="survey-chip' . (isset($isTarget[$m]) ? ($score === null ? ' is-none' : '') : ' is-out') . '"'
                                    . ($score === null ? '' : ' style="--heat: ' . round($score, 2) . '"') . '>' . h($m)
                                    . ($rank !== null ? ' <b>' . (int)$rank . '/' . (int)$n[$m] . '</b>' : '') . '</span>';
                                echo $cellForm($bi, $ci, $m, $chip, isset($isTarget[$m]));
                            endforeach;
                            if (!array_diff($cell, [BAND_SURVEY_TBD])) { // 空欄と未定には人を足せる
                                echo $cellForm($bi, $ci, in_array(BAND_SURVEY_TBD, $cell, true) ? BAND_SURVEY_TBD : '',
                                    in_array(BAND_SURVEY_TBD, $cell, true) ? '<span class="survey-tbd">未定</span>' : '<span class="survey-add" aria-label="追加">＋</span>', true);
                            } ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="card form-card" data-loading>
            <?= csrf_field() ?><input type="hidden" name="action" value="reroster"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="field">名簿を読み直す<input type="file" name="roster" accept=".pdf,.xlsx,.xlsm,.csv" required></label>
            <div class="loading" data-loading-box hidden role="status" aria-live="polite">
                <span class="loading__spinner" aria-hidden="true"></span>
                <p class="loading__title" data-loading-title>読み込み中…</p>
                <p class="muted small" data-loading-sub></p>
            </div>
            <button class="btn" type="submit">読み込む</button>
        </form>
    <?php endif; ?>
    <form method="post" data-confirm="「<?= h($survey['title']) ?>」を削除します。元に戻せません。よろしいですか？">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$survey['survey_id'] ?>">
        <button class="btn btn--danger" type="submit"><?= icon('delete') ?> 削除</button>
    </form>
    <?php
    render_footer();
    exit;
}

/* ==================== 名簿の名前の確認（直してから調査を作る） ==================== */
$draft = $_SESSION['band_survey_roster'] ?? null;
if ($draft) {
    require_once __DIR__ . '/lib/import/planner.php'; // classify_cells
    $people = band_survey_people($draft['roster']);
    $names = array_column($people, 0);
    $status = $names ? classify_cells($pdo, $names) : [];
    $memberNames = member_name_choices($pdo);
    render_header('名簿の確認 — バンド希望調査', 'tools');
    ?>
    <nav class="crumbs"><a href="tools?tab=admin">ツール</a><span>/</span><a href="band_survey">バンド希望調査</a></nav>
    <h1 class="display display--sm"><?= h($draft['title']) ?></h1>
    <?= render_suggest_datalist('member-names', array_keys($memberNames), $memberNames) ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="card table-card"><div class="table-scroll table-scroll--flush">
        <table class="table table--edit survey-edit">
            <thead><tr><th>対象</th><th>メンバー</th><th>バンド</th></tr></thead>
            <tbody>
            <?php foreach ($people as $mi => [$member, $bands]): $st = $status[$mi]; ?>
                <tr>
                    <td><input type="checkbox" name="t[<?= $mi ?>]" value="1"<?= in_array($member, $draft['targets'] ?? [], true) ? ' checked' : '' ?> aria-label="<?= h($member) ?>を対象にする"></td>
                    <td><input name="n[<?= $mi ?>]" value="<?= h($member) ?>" maxlength="50" required
                        class="name-input<?= $st['status'] ? ' is-' . h($st['status']) : '' ?>" title="<?= h($st['hint']) ?>"
                        data-name-cell data-suggest-list="member-names" autocomplete="off" aria-label="メンバー"<?= suggest_attr($st) ?>></td>
                    <td class="muted small"><?= h(implode('、', $bands)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div></div>
        <button class="btn btn--primary" type="submit"><?= isset($draft['survey_id']) ? '更新' : '作成' ?></button>
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
    <?= csrf_field() ?><input type="hidden" name="action" value="roster">
    <label class="field">調査名<input type="text" name="title" maxlength="100" required></label>
    <label class="field">対象の学年
        <select name="year">
            <?php $fy = current_fiscal_year(); for ($y = $fy; $y >= $fy - 5; $y--): ?>
                <option value="<?= $y ?>"<?= $y === $fy - 3 ? ' selected' : '' ?>><?= h(grade_label($fy - $y + 1)) ?> / <?= $y ?>年入部</option>
            <?php endfor; ?>
        </select>
    </label>
    <label class="field">名簿<input type="file" name="roster" accept=".pdf,.xlsx,.xlsm,.csv" required></label>
    <button class="btn btn--primary" type="submit">読み込む</button>
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
