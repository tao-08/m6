<?php
/**
 * =====================================================================
 *  sheet_templates.php — セッティングシートの型紙の登録・編集・削除（管理者のみ）
 * =====================================================================
 *  ここで登録した型紙は sheet_template テーブルに入り、lib/setting_sheet.php の all_sheet_templates() が
 *  コードの型紙（SHEET_TEMPLATES）と合わせてセッティングシート作成の画面に出す。
 *
 *  ?id=3 … その型紙の編集。無ければ新規登録のフォーム
 *
 *  座標（config）は今は JSON を直接書く。あとで「PDF の上で枠をドラッグして置く」エディタに置き換える予定。
 *  書き方は lib/setting_sheet.php の上のコメントと、「コピー元」に選んだ型紙の中身を見本にする。
 *
 *  PDF のアップロードで確かめていること（どれか1つでもダメなら保存しない）:
 *    ・ちゃんとアップロードされたファイルか（is_uploaded_file）
 *    ・大きさ（SHEET_PDF_MAX_BYTES まで）
 *    ・中身が本当に PDF か（拡張子ではなく、ファイルの中身から種類を調べる finfo と、先頭の「%PDF-」）
 *    ・保存する名前はこちらで決める（ランダム）。送られてきたファイル名は使わない
 *      → 「../../index.php」のような名前で、ほかのファイルを上書きされない
 *  セット図の背景（stage_image）は、エディタ（assets/sheet_editor.js）が PDF のセット図の枠を切り抜いた PNG を
 *  stage_png（data URL）で送ってくる。これも中身が本当に PNG か確かめてから保存し、config.stage_image に入れる
 *  保存先 assets/sheets/uploads/ の .htaccess で、PDF と PNG 以外は開けないようにしてある
 *  （万一 PHP のファイルが置かれても、実行させない）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/setting_sheet.php';
$user = require_admin();

const SHEET_PDF_MAX_BYTES = 5 * 1024 * 1024; // 5MB。会場のシートは1〜2ページなので十分
const SHEET_PNG_MAX_BYTES = 3 * 1024 * 1024; // セット図の背景の PNG
const SHEET_UPLOAD_DIR = 'assets/sheets/uploads';
// JS（assets/setting_sheet.js）が必ず読むキー。無いとセッティングシートの画面が動かない
const SHEET_REQUIRED_KEYS = ['fields', 'members', 'stage', 'setlist'];

$pdo = db();

/**
 * アップロードされた PDF を確かめて保存し、保存先のパスを返す。
 * ダメなら理由の文字列を投げる（RuntimeException）。ファイルが選ばれていなければ null
 */
function save_sheet_pdf(array $file): ?string
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('PDF が大きすぎます（5MB まで）');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('PDF のアップロードに失敗しました。もう一度選んでください');
    }
    if ($file['size'] > SHEET_PDF_MAX_BYTES) {
        throw new RuntimeException('PDF が大きすぎます（5MB まで）');
    }
    // ファイル名の「.pdf」は誰でも付けられるので信用しない。中身から種類を調べる
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') {
        throw new RuntimeException('PDF ファイルを選んでください');
    }
    $dir = __DIR__ . '/' . SHEET_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('保存先のフォルダを作れませんでした');
    }
    $name = bin2hex(random_bytes(8)) . '.pdf'; // 名前はこちらで決める（推測されにくく、ぶつからない）
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('PDF を保存できませんでした');
    }
    return SHEET_UPLOAD_DIR . '/' . $name;
}

/**
 * エディタが送ってきたセット図の背景（data:image/png;base64,...）を確かめて保存し、パスを返す。
 * 送られていなければ null
 */
function save_stage_png(string $dataUrl): ?string
{
    if ($dataUrl === '') {
        return null;
    }
    $prefix = 'data:image/png;base64,';
    if (!str_starts_with($dataUrl, $prefix) || strlen($dataUrl) > SHEET_PNG_MAX_BYTES * 4 / 3 + 100) {
        throw new RuntimeException('セット図の画像が読めません。PDF を選び直してください');
    }
    $bin = base64_decode(substr($dataUrl, strlen($prefix)), true); // true: base64 以外の文字があれば false
    // getimagesizefromstring: 画像として読めるか・種類は何か（中身から調べる）
    $info = $bin === false ? false : getimagesizefromstring($bin);
    if ($info === false || $info[2] !== IMAGETYPE_PNG) {
        throw new RuntimeException('セット図の画像が読めません。PDF を選び直してください');
    }
    $dir = __DIR__ . '/' . SHEET_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('保存先のフォルダを作れませんでした');
    }
    $name = bin2hex(random_bytes(8)) . '.png';
    if (file_put_contents($dir . '/' . $name, $bin) === false) {
        throw new RuntimeException('セット図の画像を保存できませんでした');
    }
    return SHEET_UPLOAD_DIR . '/' . $name;
}

/** config の JSON を確かめて、配列にして返す。ダメなら RuntimeException */
function parse_sheet_config(string $json): array
{
    $config = json_decode($json, true);
    if (!is_array($config) || array_is_list($config)) {
        throw new RuntimeException('座標（JSON）が読めません。{ } で囲んだ形で書いてください（' . json_last_error_msg() . '）');
    }
    $missing = array_diff(SHEET_REQUIRED_KEYS, array_keys($config));
    if ($missing) {
        throw new RuntimeException('座標（JSON）に ' . implode(' / ', $missing) . ' がありません');
    }
    // label / venue_words / venues / pdf は上の欄で、stage_image はエディタの切り抜きで決めるので、JSON の中にあっても捨てる
    unset($config['label'], $config['venue_words'], $config['venues'], $config['pdf'], $config['stage_image']);
    return $config;
}

/** アップロードした PDF・PNG を消す（uploads の中のものだけ。コードの型紙のファイルは消さない） */
function delete_sheet_pdf(?string $path): void
{
    if ($path !== null && str_starts_with($path, SHEET_UPLOAD_DIR . '/') && basename($path) === substr($path, strlen(SHEET_UPLOAD_DIR) + 1)) {
        @unlink(__DIR__ . '/' . $path); // @: 既に無くてもエラーにしない
    }
}

// ---- 編集する型紙（?id=） ----
$id = (int)($_GET['id'] ?? $_POST['template_id'] ?? 0);
$editing = null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM sheet_template WHERE template_id = ?');
    $st->execute([$id]);
    $editing = $st->fetch() ?: null;
    if ($editing === null) {
        flash('その型紙は見つかりません（削除された可能性があります）', 'error');
        redirect('sheet_templates');
    }
}

// ---- 会場の一覧（プルダウン）と、この型紙につながっている会場 ----
$venues = $pdo->query('SELECT venue_id, name FROM venue ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$linked = [];
if ($editing) {
    $st = $pdo->prepare('SELECT venue_id FROM sheet_template_venue WHERE template_id = ?');
    $st->execute([$id]);
    $linked = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// フォームの初期値（エラーで戻ったときは入力したものを残す）
$oldConfig = $editing ? (json_decode($editing['config'], true) ?: []) : [];
$oldStage = $oldConfig['stage_image'] ?? null; // 今のセット図の背景（差し替えたら消す）
unset($oldConfig['stage_image']); // JSON の欄には出さない（エディタが作るので、人が書くものではない）
$form = [
    'label'       => $editing['label'] ?? '',
    'venue_ids'   => $linked,
    'config'      => $editing ? json_encode($oldConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
];
$error = null;

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete' && $editing) {
        $pdo->prepare('DELETE FROM sheet_template WHERE template_id = ?')->execute([$id]);
        delete_sheet_pdf($editing['pdf_path']);
        delete_sheet_pdf($oldStage);
        flash('型紙「' . $editing['label'] . '」を削除しました');
        redirect('sheet_templates');
    }

    if ($action === 'save') {
        $form['label'] = trim((string)($_POST['label'] ?? ''));
        // venue_ids[] … 選んだ会場の ID。一覧に無い ID（消された会場・書き換えられた値）は捨てる。重複も1つに
        $form['venue_ids'] = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['venue_ids'] ?? [])),
            fn($v) => isset($venues[$v])
        )));
        $form['config'] = (string)($_POST['config'] ?? '');
        $newPdf = null;
        $newPng = null;
        try {
            if ($form['label'] === '' || mb_strlen($form['label']) > 100) {
                throw new RuntimeException('名前を 100 文字以内で入れてください');
            }
            $config = parse_sheet_config($form['config']);
            $newPdf = save_sheet_pdf($_FILES['pdf'] ?? []);
            if ($newPdf === null && !$editing) {
                throw new RuntimeException('会場の PDF を選んでください');
            }
            $newPng = save_stage_png((string)($_POST['stage_png'] ?? ''));
            $config['stage_image'] = $newPng ?? $oldStage; // 送られてこなければ今の画像のまま（新規で無ければ背景なし）
            if ($config['stage_image'] === null) {
                unset($config['stage_image']);
            }
            $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // 型紙の行と会場のつながりは、どちらかだけ保存されると困るのでまとめて（トランザクション）
            $pdo->beginTransaction();
            if ($editing) {
                $pdo->prepare('UPDATE sheet_template SET label = ?, pdf_path = ?, config = ? WHERE template_id = ?')
                    ->execute([$form['label'], $newPdf ?? $editing['pdf_path'], $configJson, $id]);
                $pdo->prepare('DELETE FROM sheet_template_venue WHERE template_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO sheet_template (label, pdf_path, config, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$form['label'], $newPdf, $configJson, $user['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO sheet_template_venue (template_id, venue_id) VALUES (?, ?)');
            foreach ($form['venue_ids'] as $vid) {
                $ins->execute([$id, $vid]);
            }
            $pdo->commit();
            if ($editing && $newPdf !== null) {
                delete_sheet_pdf($editing['pdf_path']); // 差し替えたら古い PDF は要らない（保存できてから消す）
            }
            if ($editing && $newPng !== null) {
                delete_sheet_pdf($oldStage);
            }
            flash('型紙「' . $form['label'] . '」を保存しました');
            redirect('sheet_templates?id=' . $id);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack(); // 途中で失敗したら、型紙の行も会場のつながりも保存しない
            }
            if ($newPdf !== null) {
                delete_sheet_pdf($newPdf); // 保存しなかったのに PDF だけ残らないように
            }
            delete_sheet_pdf($newPng);
            $error = $e->getMessage();
        }
    }
}

// ---- 一覧 ----
// GROUP_CONCAT: つながっている会場の名前を「 / 」でつないで1つの文字にする（会場が無ければ NULL）
$rows = $pdo->query("SELECT t.template_id, t.label, t.updated_at, u.name AS creator,
                            GROUP_CONCAT(v.name ORDER BY v.sort_order, v.name SEPARATOR ' / ') AS venue_names
                       FROM sheet_template t
                       LEFT JOIN user_account u ON u.user_id = t.created_by
                       LEFT JOIN sheet_template_venue tv ON tv.template_id = t.template_id
                       LEFT JOIN venue v ON v.venue_id = tv.venue_id
                      GROUP BY t.template_id
                      ORDER BY t.label")->fetchAll();

// 「コピー元」の見本: 今ある型紙の座標（label などを除いたもの）。JS で JSON の欄に入れる
$samples = [];
foreach (all_sheet_templates() as $key => $tpl) {
    unset($tpl['label'], $tpl['venue_words'], $tpl['venues'], $tpl['pdf'], $tpl['stage_image']); // 背景の画像は会場ごとに違うので見本に含めない
    $samples[$key] = json_encode($tpl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
$sampleLabels = array_map(fn($t) => $t['label'], all_sheet_templates());

render_header('セッティングシートの型紙', 'tools');
?>
<nav class="crumbs"><a href="tools">ツール</a></nav>
<h1 class="display display--sm"><?= icon('description') ?> セッティングシートの型紙</h1>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table">
        <thead><tr><th>名前</th><th>自動で選ぶ会場名</th><th>登録した人</th><th>更新</th><th></th></tr></thead>
        <tbody>
        <?php foreach (SHEET_TEMPLATES as $key => $tpl): ?>
            <tr>
                <td><?= h($tpl['label']) ?></td>
                <td class="muted small"><?= h(implode(' / ', $tpl['venue_words'])) ?></td>
                <td class="muted small" colspan="3">最初から入っている型紙（コードで管理。ここでは変えられません）</td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($rows as $r): ?>
            <tr<?= (int)$r['template_id'] === $id ? ' class="is-active"' : '' ?>>
                <td><?= h($r['label']) ?></td>
                <td class="muted small"><?= h($r['venue_names'] ?? '') ?></td>
                <td class="small"><?= $r['creator'] === null ? '<span class="muted">削除済みのユーザー</span>' : h($r['creator']) ?></td>
                <td class="small"><?= h(fmt_date($r['updated_at'])) ?></td>
                <td><a class="btn btn--ghost btn--sm" href="sheet_templates?id=<?= (int)$r['template_id'] ?>">編集</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<section class="card">
    <h2><?= $editing ? '「' . h($editing['label']) . '」の編集' : '新しい型紙を登録' ?></h2>
    <?php if ($editing): ?>
        <p class="small"><a href="sheet_templates">＋ 新しい型紙を登録する</a> ・ <a href="<?= h($editing['pdf_path']) ?>" target="_blank" rel="noopener">今の PDF を見る</a></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="flash flash--error"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="sheet-tpl-form"<?= unsaved_attr() ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="template_id" value="<?= (int)$editing['template_id'] ?>"><?php endif; ?>
        <label class="field">名前（プルダウンに出る）
            <input name="label" value="<?= h($form['label']) ?>" maxlength="100" required placeholder="例: 下北沢 〇〇">
        </label>
        <div class="field" data-venue-picker>
            <span>自動で選ぶ会場（このライブ会場のバンドは、この型紙を最初から選ぶ。いくつでも）</span>
            <select data-venue-select aria-label="会場を選ぶ">
                <option value="">会場を選んで追加…</option>
                <?php foreach ($venues as $vid => $vname): ?>
                    <option value="<?= (int)$vid ?>"><?= h($vname) ?></option>
                <?php endforeach; ?>
            </select>
            <!-- 選んだ会場。× で外す。送るのは中の hidden の venue_ids[] -->
            <span class="chip-list" data-venue-chips>
                <?php foreach ($form['venue_ids'] as $vid): ?>
                    <span class="chip"><input type="hidden" name="venue_ids[]" value="<?= (int)$vid ?>"><?= h($venues[$vid]) ?>
                        <button type="button" class="chip__x" data-venue-remove aria-label="外す">×</button></span>
                <?php endforeach; ?>
            </span>
            <?php if (!$venues): ?><span class="muted small">会場がまだありません（<a href="masters">会場の管理</a>で追加）</span><?php endif; ?>
        </div>
        <label class="field">会場の PDF<?= $editing ? '（差し替えるときだけ選ぶ）' : '' ?>（5MB まで）
            <input type="file" name="pdf" accept="application/pdf,.pdf"<?= $editing ? '' : ' required' ?>>
        </label>
        <div class="field">
            <span>座標（どこに何を書くか）。似ている型紙を見本にして、PDF の上の枠をドラッグで合わせる</span>
            <span class="row">
                <select data-sample aria-label="見本にする型紙">
                    <option value="">見本にする型紙を選ぶ…</option>
                    <?php foreach ($sampleLabels as $key => $label): ?>
                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn--ghost btn--sm" data-sample-copy>この型紙の座標を入れる</button>
            </span>
        </div>

        <!-- 枠の配置エディタ（assets/sheet_editor.js） -->
        <div class="sheet-ed" data-sheet-editor>
            <div class="sheet-ed__bar">
                <label class="small">表示
                    <select data-ed-zoom>
                        <option value="1">幅に合わせる</option>
                        <option value="1.5">1.5 倍</option>
                        <option value="2">2 倍</option>
                        <option value="3">3 倍</option>
                    </select>
                </label>
                <button type="button" class="btn btn--ghost btn--sm" data-ed-add-text>＋ 自由記入欄を足す</button>
                <span class="muted small sheet-ed__cursor" data-ed-cursor></span>
            </div>
            <p class="muted small" data-ed-note></p>
            <div class="sheet-ed__list" data-ed-list></div>
            <div class="sheet-ed__panel" data-ed-panel></div>
            <div class="sheet-ed__scroll">
                <div class="sheet-ed__wrap" data-ed-wrap>
                    <canvas data-ed-canvas></canvas>
                    <svg data-ed-svg aria-label="型紙の枠"></svg>
                </div>
            </div>
            <input type="hidden" name="stage_png" value="">
        </div>

        <details class="sheet-ed__json"<?= $error !== null ? ' open' : '' ?>>
            <summary>JSON で直接書く（行・列・選択肢を増やすとき）</summary>
            <p class="muted small">単位は pt、ページの左上が 0。fields / members / stage / setlist は必ず書く。書き方は lib/setting_sheet.php の上のコメントを見る</p>
            <textarea name="config" rows="18" spellcheck="false" class="mono" data-config aria-label="座標（JSON）"><?= h($form['config']) ?></textarea>
        </details>
        <div class="row">
            <button class="btn btn--primary" type="submit">保存</button>
        </div>
    </form>
    <?php if ($editing): ?>
        <form method="post" data-confirm="型紙「<?= h($editing['label']) ?>」を削除しますか？（PDF も消えます）">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="template_id" value="<?= (int)$editing['template_id'] ?>">
            <button class="btn btn--danger btn--sm" type="submit">この型紙を削除</button>
        </form>
    <?php endif; ?>
</section>

<script type="application/json" id="sheet-samples"><?= json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
// 会場のプルダウン: 選ぶと下にチップを足す（同じ会場は2回足さない）・× で外す
(() => {
    const picker = document.querySelector('[data-venue-picker]');
    const select = picker.querySelector('[data-venue-select]');
    const chips = picker.querySelector('[data-venue-chips]');
    const touched = () => picker.closest('form').dispatchEvent(new Event('input', { bubbles: true })); // 「保存していない変更」の印
    select.addEventListener('change', () => {
        const opt = select.selectedOptions[0];
        if (opt && opt.value && !chips.querySelector(`input[value="${CSS.escape(opt.value)}"]`)) {
            const chip = document.createElement('span');
            chip.className = 'chip';
            const input = Object.assign(document.createElement('input'), { type: 'hidden', name: 'venue_ids[]', value: opt.value });
            const x = Object.assign(document.createElement('button'), { type: 'button', className: 'chip__x', textContent: '×' });
            x.dataset.venueRemove = '';
            x.setAttribute('aria-label', '外す');
            chip.append(input, opt.textContent, ' ', x); // 文字として入れるので、会場名に < があっても大丈夫
            chips.append(chip);
            touched();
        }
        select.value = '';
    });
    chips.addEventListener('click', (e) => {
        if (!e.target.closest('[data-venue-remove]')) return;
        e.target.closest('.chip').remove();
        touched();
    });
})();

// 「この型紙の座標を入れる」: 見本の JSON を欄に入れる（書いてあるものは確認してから上書き）
document.querySelector('[data-sample-copy]').addEventListener('click', () => {
    const key = document.querySelector('[data-sample]').value;
    const box = document.querySelector('[data-config]');
    const samples = JSON.parse(document.getElementById('sheet-samples').textContent);
    if (!key || !samples[key]) return;
    if (box.value.trim() !== '' && !confirm('今書いてある座標を上書きしますか？')) return;
    box.value = samples[key];
    box.dispatchEvent(new Event('input')); // エディタ（sheet_editor.js）に「書き換わった」と知らせる
});
</script>
<?php
// エディタに渡すもの: 今の PDF（編集のとき）・pdf.js の場所・選択肢のグループ名
$editorData = [
    'pdf'          => $editing['pdf_path'] ?? null,
    'choiceLabels' => SHEET_CHOICE_LABELS,
    'pdfjs'        => [
        'lib'    => 'assets/vendor/pdfjs/pdf.min.js',
        'worker' => 'assets/vendor/pdfjs/pdf.worker.min.js',
        'cmaps'  => 'assets/vendor/pdfjs/cmaps/',
        'fonts'  => 'assets/vendor/pdfjs/standard_fonts/',
    ],
];
?>
<script type="application/json" id="sheet-editor-data"><?= json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/sheet_editor.js?v=2" defer></script>
<?php render_footer();
