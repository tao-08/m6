<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/import/planner.php';
require_login();

const MAX_FILES = 10;
const MAX_FILE_SIZE = 5 * 1024 * 1024;

$pdo = db();

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $files = [];
        $up = $_FILES['files'] ?? null;
        $count = is_array($up['name'] ?? null) ? count($up['name']) : 0;
        if ($count === 0 || ($count === 1 && $up['error'][0] === UPLOAD_ERR_NO_FILE)) {
            flash('ファイルを選んでください', 'error');
            redirect('import.php');
        }
        if ($count > MAX_FILES) {
            flash('一度にアップロードできるのは ' . MAX_FILES . ' ファイルまでです', 'error');
            redirect('import.php');
        }
        for ($i = 0; $i < $count; $i++) {
            $name = basename((string)$up['name'][$i]);
            if ($up['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$i])) {
                flash("{$name}: アップロードに失敗しました", 'error');
                continue;
            }
            if ($up['size'][$i] > MAX_FILE_SIZE) {
                flash("{$name}: 5MB を超えるファイルは読み込めません", 'error');
                continue;
            }
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'pdf'], true)) {
                flash("{$name}: CSV か PDF を選んでください", 'error');
                continue;
            }
            $files[] = ['path' => $up['tmp_name'][$i], 'name' => $name];
        }
        if ($files) {
            $plan = build_import_plan($pdo, $files);
            if (!$plan['timetables']) {
                $plan['errors'][] = 'タイムテーブルが1つも含まれていません。タイムテーブル（時間・バンド名の表）を一緒にアップロードしてください。';
            }
            $_SESSION['import_plan'] = $plan;
        }
        redirect('import.php');
    }

    if ($action === 'reset') {
        unset($_SESSION['import_plan']);
        redirect('import.php');
    }

    if ($action === 'commit' && isset($_SESSION['import_plan'])) {
        try {
            $liveIds = commit_import_plan($pdo, $_SESSION['import_plan'], $_POST);
            unset($_SESSION['import_plan']);
            $st = $pdo->prepare('SELECT member_id FROM user_index WHERE user_auto_id = ?');
            $st->execute([$_SESSION['user']['auto_id']]);
            $mid = $st->fetchColumn();
            $_SESSION['user']['member_id'] = $mid ? (int)$mid : null;
            if (!$liveIds) {
                flash('取り込む日程がありませんでした（すべてスキップ）', 'info');
                redirect('import.php');
            }
            flash('取り込みが完了しました！');
            redirect(count($liveIds) === 1 ? 'live.php?id=' . $liveIds[0] : 'index.php');
        } catch (Throwable $e) {
            $_SESSION['import_form'] = $_POST;
            flash('取り込みに失敗しました: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'データベースエラー'), 'error');
            if (config('debug')) {
                flash($e->getMessage(), 'error');
            }
            redirect('import.php');
        }
    }
    redirect('import.php');
}

$plan = $_SESSION['import_plan'] ?? null;
$form = $_SESSION['import_form'] ?? [];
unset($_SESSION['import_form']);

render_header('取り込み', 'import');

if ($plan === null): ?>
<section class="hero">
    <div>
        <p class="eyebrow">Import</p>
        <h1 class="display">タイムテーブルを取り込む</h1>
        <p class="muted">タイムテーブルとメンバー表をまとめて放り込めばOK。中身を見て自動で判別・照合します。</p>
    </div>
</section>

<form method="post" enctype="multipart/form-data" class="card upload">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label class="dropzone" data-dropzone>
        <input type="file" name="files[]" accept=".csv,.pdf,text/csv,application/pdf" multiple required data-file-input>
        <span class="dropzone__icon" aria-hidden="true">⤒</span>
        <span class="dropzone__title">ここにファイルをドロップ</span>
        <span class="muted small">またはクリックして選択 · CSV / PDF · 最大<?= MAX_FILES ?>ファイル</span>
        <ul class="dropzone__list" data-file-list></ul>
    </label>
    <button class="btn btn--primary btn--block" type="submit">読み込んでプレビュー</button>
</form>

<section class="guide">
    <div class="card">
        <h3><span class="step">1</span>タイムテーブル</h3>
        <p class="muted small">1ファイル = ライブ1日分。「時間 / 持ち時間 / バンド名 / 曲数 / 人数 / key」の列と、上の行の「○○ライブ2日目 / 会場 / △△」を読み取ります。複数日まとめてOK。</p>
    </div>
    <div class="card">
        <h3><span class="step">2</span>メンバー表</h3>
        <p class="muted small">「バンド名 / Vo / Gt / Ba / Dr / Key」の列を持つ表。バンド名でタイムテーブルと突き合わせます。「ヨルシカ(安田)」のような代表者付きの名前にも対応。</p>
    </div>
    <div class="card">
        <h3><span class="step">3</span>PDFについて</h3>
        <p class="muted small">Excel から書き出した「文字を選択できる」PDF に対応。スマホで撮った写真やスキャンのPDFは読めないので CSV にしてください。</p>
    </div>
</section>

<?php else:
    $rosterJson = array_map(static fn($r) => array_map(static fn($m) => $m['part'] . ' ' . $m['name'], $r['members']), $plan['roster']);
    $used = [];
    foreach ($plan['timetables'] as $tt) {
        foreach ($tt['slots'] as $s) {
            if ($s['roster_index'] !== null) {
                $used[$s['roster_index']] = true;
            }
        }
    }
    $issues = array_filter($plan['names'], static fn($n) => $n['suggest'] !== []);
    $newCount = count(array_filter($plan['names'], static fn($n) => $n['existing'] === null));
?>
<section class="hero">
    <div>
        <p class="eyebrow">Import · Preview</p>
        <h1 class="display">内容を確認</h1>
        <p class="muted">まだ保存されていません。内容を確認・修正して「登録する」を押してください。</p>
    </div>
    <dl class="stats">
        <div><dt>日程</dt><dd><?= count($plan['timetables']) ?></dd></div>
        <div><dt>メンバー表</dt><dd><?= count($plan['roster']) ?></dd></div>
        <div><dt>新メンバー</dt><dd><?= $newCount ?></dd></div>
    </dl>
</section>

<?php foreach ($plan['errors'] as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="import-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commit">
    <script type="application/json" id="roster-data"><?= json_encode($rosterJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <?php foreach ($plan['timetables'] as $ti => $tt):
        $f = $form['tt'][$ti] ?? [];
        $val = static fn(string $k, $default) => $f[$k] ?? $default;
        $st = $pdo->prepare('SELECT 1 FROM live_master lm JOIN live_detail ld ON ld.live_id = lm.live_id
            WHERE lm.year = ? AND lm.live_name = ? AND ld.day_no = ?');
        $st->execute([$tt['year'], $tt['live_name'], $tt['day_no']]);
        $exists = (bool)$st->fetchColumn();
        $bands = array_filter($tt['slots'], static fn($s) => $s['is_band']);
        $unmatched = count(array_filter($bands, static fn($s) => $s['roster_index'] === null)); ?>
        <section class="card import-day">
            <header class="import-day__head">
                <div>
                    <p class="eyebrow">📄 <?= h($tt['file']) ?></p>
                    <h2><?= h($tt['label'] ?: '（ライブ名なし）') ?></h2>
                </div>
                <div class="import-day__badges">
                    <span class="pill"><?= count($bands) ?> バンド</span>
                    <?php if ($unmatched): ?><span class="pill pill--warn">メンバー未照合 <?= $unmatched ?></span><?php else: ?><span class="pill pill--ok">全バンド照合済み</span><?php endif; ?>
                </div>
            </header>

            <?php if ($exists): ?>
                <div class="flash flash--warn">この日程は登録済みです。「上書き」にチェックすると既存データを置き換えます。</div>
            <?php endif; ?>

            <div class="form-grid">
                <label class="field"><span>年度</span><input type="number" name="tt[<?= $ti ?>][year]" value="<?= h($val('year', $tt['year'])) ?>" min="1990" max="2100" required></label>
                <label class="field field--wide"><span>ライブ名</span><input name="tt[<?= $ti ?>][live_name]" value="<?= h($val('live_name', $tt['live_name'])) ?>" placeholder="例: 1月ライブ" required></label>
                <label class="field"><span>何日目</span><input type="number" name="tt[<?= $ti ?>][day_no]" value="<?= h($val('day_no', $tt['day_no'])) ?>" min="1" max="20"></label>
                <label class="field"><span>開催日</span><input type="date" name="tt[<?= $ti ?>][date]" value="<?= h($val('date', $tt['date'])) ?>"></label>
                <label class="field field--wide"><span>会場</span><input name="tt[<?= $ti ?>][venue]" value="<?= h($val('venue', $tt['venue'])) ?>"></label>
                <label class="field"><span>集合</span><input type="time" name="tt[<?= $ti ?>][meeting_time]" value="<?= h($val('meeting_time', $tt['meeting_time'] ?? '')) ?>"></label>
            </div>
            <div class="checks">
                <label class="check"><input type="checkbox" name="tt[<?= $ti ?>][overwrite]" value="1"<?= !empty($f['overwrite']) ? ' checked' : '' ?>> 登録済みなら上書き</label>
                <label class="check"><input type="checkbox" name="tt[<?= $ti ?>][skip]" value="1"<?= !empty($f['skip']) ? ' checked' : '' ?>> この日程は取り込まない</label>
            </div>

            <div class="table-scroll">
                <table class="table table--import">
                    <thead><tr><th>時間</th><th>バンド名</th><th class="num">曲/人</th><th>メンバー表との対応</th></tr></thead>
                    <tbody>
                    <?php foreach ($tt['slots'] as $si => $s):
                        if (!$s['is_band']): ?>
                            <tr class="row-break"><td class="mono"><?= h($s['start_time']) ?></td><td colspan="3" class="muted"><?= h($s['band_name']) ?></td></tr>
                        <?php continue; endif;
                        $pick = (string)($f['band'][$si] ?? ($s['roster_index'] ?? '')); ?>
                        <tr class="<?= $pick === '' ? 'is-unmatched' : '' ?>">
                            <td class="mono nowrap"><?= h($s['start_time']) ?><?= $s['end_time'] ? '–' . h($s['end_time']) : '' ?></td>
                            <td class="strong"><?= h($s['band_name']) ?><?php if ($s['key_note']): ?><div class="muted small">🎹 <?= h($s['key_note']) ?></div><?php endif; ?></td>
                            <td class="num nowrap"><?= h($s['song_count'] ?? '–') ?> / <?= h($s['member_count'] ?? '–') ?></td>
                            <td>
                                <select name="tt[<?= $ti ?>][band][<?= $si ?>]" data-roster-select>
                                    <option value="">— メンバー表なし —</option>
                                    <?php foreach ($plan['roster'] as $ri => $r): ?>
                                        <option value="<?= $ri ?>"<?= $pick === (string)$ri ? ' selected' : '' ?>><?= h($r['band_name']) ?>（<?= count($r['members']) ?>人 · <?= h($r['file']) ?>）</option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="roster-preview small" data-roster-preview></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($issues): ?>
        <section class="card">
            <h2 class="section-title">⚠️ 同一人物かもしれない名前</h2>
            <p class="muted small">表記ゆれ・誤字・苗字だけの記載の可能性があります。同一人物なら相手を選んでください。何もしなければ別人として新規登録します。</p>
            <div class="name-list">
                <?php foreach ($issues as $key => $n): ?>
                    <label class="name-item">
                        <span class="strong"><?= h($n['name']) ?></span>
                        <select name="name[<?= h($key) ?>]">
                            <option value="new">別人として新規登録</option>
                            <?php foreach ($n['suggest'] as $sg): ?>
                                <option value="<?= h($sg['value']) ?>"<?= ($form['name'][$key] ?? '') === $sg['value'] ? ' selected' : '' ?>>= <?= h($sg['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php $unused = array_diff_key($plan['roster'], $used);
    if ($plan['roster'] && $unused): ?>
        <details class="card">
            <summary>メンバー表にあるがタイムテーブルで自動照合されなかったバンド（<?= count($unused) ?>）</summary>
            <ul class="plain-list small">
                <?php foreach ($unused as $r): ?><li><?= h($r['band_name']) ?> <span class="muted">— <?= h($r['file']) ?></span></li><?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <div class="sticky-actions">
        <button class="btn btn--ghost" type="submit" form="reset-form">やり直す</button>
        <button class="btn btn--primary" type="submit"<?= $plan['timetables'] ? '' : ' disabled' ?>>登録する</button>
    </div>
</form>
<form method="post" id="reset-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"></form>
<?php endif;
render_footer();
