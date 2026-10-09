<?php
/**
 * =====================================================================
 *  import.php — タイムテーブル & 名簿の取り込み（「新規追加」ページの「ファイルから取り込む」タブ）
 * =====================================================================
 *  画面の流れ（1つのファイルで3つの状態を切り替えている）
 *
 *    [アップロード画面] --(POST action=upload)--> 計画をセッションに保存 --> [プレビュー画面]
 *    [プレビュー画面]   --(POST action=commit)--> DBに登録 --> ライブ詳細へ
 *                       --(POST action=reset)---> 計画を捨てる --> [アップロード画面]
 *
 *    名簿だけでタイムテーブルのファイルが無いとき:
 *    [アップロード画面] --(upload)--> [タイムテーブル手入力画面] --(POST action=manual_tt)--> [プレビュー画面]
 *    [プレビュー画面]   --(POST action=edit_tt)--> [タイムテーブル手入力画面]（手入力したときだけ）
 *
 *  POST の後は必ず redirect() している（PRG パターン: Post → Redirect → Get）。
 *  → 登録後にブラウザの「再読み込み」を押しても、二重登録されない。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/import/planner.php';
require_login();

const MAX_FILES = 10;
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const MAX_MANUAL_ROWS = 200;            // タイムテーブル手入力の行数の上限

$pdo = db();

/**
 * 「Vo | Vo/Gt | ⋯」「Key | Vn | ⋯」のような切り替えボタン（中身はラジオボタン）の HTML を作る。
 *   $options … [値 => ['label' => 表示, 'title' => 正式名, 'add' => true なら「選ぶと楽器を追加するモーダルが開く」], ...]（「⋯」の中はこの順）
 *   $shown   … 「⋯」にしまわずに見せておく値（この順で並ぶ）
 *   $kind    … 'inst' なら、モーダルで楽器を追加したとき JS がこのボタンにも新しい楽器を足す
 * 開け閉めとモーダルは assets/app.js の setupPicks。
 */
function render_pick(string $name, array $options, array $shown, string $selected, string $ariaLabel, bool $collapsed, string $kind = ''): string
{
    if (!isset($options[$selected])) {
        $selected = $shown[0] ?? ''; // 選択肢に無い値（消された楽器など）なら先頭を選んでおく
    }
    $radio = static function (string $value, array $o) use ($name, $selected): string {
        return '<label title="' . h($o['title']) . '"><input type="radio" name="' . h($name) . '" value="' . h($value) . '"'
            . ($value === $selected ? ' checked' : '') . (!empty($o['add']) ? ' data-pick-add' : '')
            . ' aria-label="' . h($o['title']) . '"><span>' . h($o['label']) . '</span></label>';
    };
    $front = '';
    foreach ($shown as $value) {
        if (isset($options[$value])) {
            $front .= $radio($value, $options[$value]);
        }
    }
    $menu = '';
    foreach ($options as $value => $o) {
        if (!in_array((string)$value, $shown, true)) {
            $menu .= $radio((string)$value, $o);
        }
    }
    // しまってある選択肢を選んでいれば、「⋯」の代わりにその名前を出す。空なら CSS が「⋯」を描く（空白も入れないこと）
    $toggleText = in_array($selected, $shown, true) ? '' : ($options[$selected]['label'] ?? '');
    return '<div class="cell-instrument pick' . ($collapsed ? ' is-collapsed' : '') . '" role="radiogroup" aria-label="' . h($ariaLabel) . '" data-pick'
        . ($kind !== '' ? ' data-pick-kind="' . h($kind) . '"' : '') . '>'
        . $front
        . '<div class="pick__more" data-pick-more>'
        . '<button type="button" class="pick__toggle" aria-expanded="false" aria-label="ほかの選択肢" data-pick-toggle>' . h($toggleText) . '</button>'
        . '<div class="pick__menu">' . $menu . '</div>'
        . '</div></div>';
}

/**
 * 名前欄が黄色（似た人がいる）のときの「もしかして」の候補を data-suggest 属性にする（JSON）。
 * 名前欄をクリックすると、assets/app.js の showHint が「もしかして ◯◯？」を出す。
 */
function suggest_attr(array $status): string
{
    return !empty($status['suggest'])
        ? ' data-suggest="' . h(json_encode($status['suggest'], JSON_UNESCAPED_UNICODE)) . '"'
        : '';
}

/**
 * タイムテーブル手入力画面の1行。上からの並びがそのまま出演順。
 *   日程の区切りの行（$r['divider'] = '2日目' など）… この行より下のバンドがその日程になる。区切りも ≡ で動かせる
 *     日程名の候補（$dayLabels）に無い名前は「新しい日程名」なので、名前を打てる入力欄にする
 *   バンドの行（$r['pick'], $r['songs']）          … ≡ / 順番（JS が日程ごとに振る）/ 名簿のバンドか休憩 / 曲数
 * $rosterChoices は roster_choices() の結果。
 */
function manual_row_html(string $i, array $r, array $rosterChoices, array $dayLabels): string
{
    $handle = '<td><button type="button" class="drag-handle" data-drag-handle aria-label="ドラッグで並び替え（↑↓キーでも動く）" title="ドラッグで並び替え">'
        . icon('drag_indicator') . '</button></td>';
    if (isset($r['divider'])) {
        $label = (string)$r['divider'];
        // 決まった日程名（'__day__' は JS が置きかえるひな形）は文字だけ、新しい日程名は入力欄
        $field = $label === '__day__' || in_array($label, $dayLabels, true)
            ? '<input type="hidden" name="r[' . $i . '][divider]" value="' . h($label) . '"><span class="manual-divider__label">' . h($label) . '</span>'
            : '<input class="manual-divider__input" name="r[' . $i . '][divider]" value="' . h($label) . '" maxlength="50" placeholder="新しい日程名（例: 野外ライブ）"'
                . ' aria-label="新しい日程名" required data-manual-new-day>';
        return '<tr class="manual-divider" data-manual-divider>' . $handle
            . '<td colspan="3"><div class="manual-divider__inner">' . $field
            . '<button type="button" class="btn btn--ghost btn--sm" data-manual-remove aria-label="この区切りを消す">×</button></div></td></tr>';
    }
    $pick = (string)($r['pick'] ?? '');
    $sel = static fn(string $v, string $cur) => $v === $cur ? ' selected' : '';
    $opts = '<option value="">— 使わない —</option><optgroup label="名簿のバンド">';
    foreach ($rosterChoices as $label => $ref) {
        $opts .= '<option value="' . h($ref) . '"' . $sel($ref, $pick) . '>' . h((string)$label) . '</option>';
    }
    $opts .= '</optgroup><optgroup label="バンド以外">';
    foreach (MANUAL_BREAKS as $b) {
        $opts .= '<option value="' . h("break:$b") . '"' . $sel("break:$b", $pick) . '>' . h($b) . '</option>';
    }
    $opts .= '</optgroup>';
    return '<tr' . ($pick === '' ? ' class="is-excluded"' : '') . '>' . $handle
        . '<td class="mono nowrap" data-manual-no></td>'
        . '<td><select name="r[' . $i . '][pick]" aria-label="バンド" data-manual-pick>' . $opts . '</select></td>'
        . '<td><input type="number" class="input-num" min="0" max="255" name="r[' . $i . '][songs]" value="' . h((string)($r['songs'] ?? '')) . '" aria-label="曲数"></td>'
        . '</tr>';
}

/** 楽器の切り替えボタン用の選択肢。$list は instruments() か extra_instruments()。「etc」は選ぶと楽器追加のモーダルが開く */
function instrument_pick_options(array $list): array
{
    $options = [];
    foreach ($list as $ins) {
        $isEtc = $ins['short_name'] === 'etc';
        $options[(string)$ins['instrument_id']] = ['label' => $ins['short_name'], 'add' => $isEtc,
            'title' => $isEtc ? 'その他（ここに無い楽器を追加）' : $ins['name']];
    }
    return $options;
}

/* =====================================================================
 *  POST の処理
 * ===================================================================== */
if (is_post()) {
    verify_csrf();
    $input = read_form_input();
    $action = $input['action'] ?? '';

    // ---------- ファイルを受け取ってプレビューへ ----------
    if ($action === 'upload') {
        $files = [];
        $up = $_FILES['files'] ?? null;
        // <input type="file" name="files[]" multiple> だと $_FILES['files']['name'][0], [1]... の形で届く
        $count = is_array($up['name'] ?? null) ? count($up['name']) : 0;
        if ($count === 0 || ($count === 1 && $up['error'][0] === UPLOAD_ERR_NO_FILE)) {
            flash('ファイルを選んでください', 'error');
            redirect('import');
        }
        if ($count > MAX_FILES) {
            flash('一度にアップロードできるのは ' . MAX_FILES . ' ファイルまでです', 'error');
            redirect('import');
        }
        for ($i = 0; $i < $count; $i++) {
            $name = basename((string)$up['name'][$i]); // basename でパス部分（../ など）を除く
            // is_uploaded_file: 本当にフォームからアップロードされたファイルか確認（偽のパスを渡す攻撃対策）
            if ($up['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$i])) {
                flash("{$name}: アップロードに失敗しました", 'error');
                continue;
            }
            if ($up['size'][$i] > MAX_FILE_SIZE) {
                flash("{$name}: 5MB を超えるファイルは読み込めません", 'error');
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'xls') {
                flash("{$name}: 古い Excel 形式（.xls）は読めません。Excel で「.xlsx」として保存し直してください", 'error');
                continue;
            }
            if (in_array($ext, ['heic', 'heif'], true)) {
                flash("{$name}: iPhone の HEIC 形式は読めません。スクリーンショットにするか、カメラの設定を「互換性優先」にして撮り直してください", 'error');
                continue;
            }
            if (!in_array($ext, IMPORT_EXTENSIONS, true) && !(ai_reader_enabled() && in_array($ext, AI_IMAGE_EXTENSIONS, true))) {
                flash("{$name}: " . (ai_reader_enabled() ? 'CSV / Excel(.xlsx) / PDF / 画像（JPEG・PNG）' : 'CSV / Excel(.xlsx) / PDF') . ' のどれかを選んでください', 'error');
                continue;
            }
            $files[] = ['path' => $up['tmp_name'][$i], 'name' => $name];
        }
        if ($files) {
            $plan = build_import_plan($files);
            if (!$plan['timetables'] && $plan['rosters']) {
                // 名簿だけ → タイムテーブルを手入力する画面へ（名簿のバンドを1行ずつ並べた状態から始める）
                $plan['needs_timetable'] = true;
                $plan['manual_rows'] = [];
                foreach ($plan['rosters'] as $ri => $r) {
                    // 名簿が複数ファイルなら「1ファイル目 = 1日目、2ファイル目 = 2日目…」と仮に区切っておく（画面で動かせる）
                    // 1日目の区切りは表の見出しに固定で出すので、行としては作らない
                    if ($ri > 0 && $ri < 3) {
                        $plan['manual_rows'][] = ['divider' => DAY_LABELS[$ri]];
                    }
                    foreach ($r['bands'] as $bi => $b) {
                        $plan['manual_rows'][] = ['pick' => "$ri:$bi", 'songs' => (string)($b['song_count'] ?? '')]; // 曲数は名簿の値
                    }
                }
            } elseif (!$plan['timetables']) {
                $plan['errors'][] = 'タイムテーブルが1つも含まれていません。タイムテーブル（時間・バンド名の表）も一緒にアップロードしてください。';
            }
            // ファイル自体は保存しない。読み取った結果（配列）だけセッションに置く
            $_SESSION['import_plan'] = $plan;
            unset($_SESSION['import_form']);
        }
        redirect('import');
    }

    // ---------- タイムテーブルの手入力 → プレビューへ ----------
    if ($action === 'manual_tt' && isset($_SESSION['import_plan'])) {
        $plan = $_SESSION['import_plan'];
        // 入力は文字列だけ取り出して残す（エラーで戻ったとき・プレビューから書き直すとき用）
        $plan['manual_rows'] = [];
        foreach (array_slice(array_values((array)($input['r'] ?? [])), 0, MAX_MANUAL_ROWS) as $r) {
            $r = (array)$r;
            $str = static fn(string $k) => mb_substr(is_string($r[$k] ?? null) ? $r[$k] : '', 0, 20);
            if (isset($r['divider'])) {
                // 日程名は 50 文字まで（live_day.label）。チェックは build_manual_timetables で
                $plan['manual_rows'][] = ['divider' => trim(mb_substr(is_string($r['divider']) ? $r['divider'] : '', 0, 50))];
            } elseif ($str('pick') !== '') { // 「— 使わない —」の行は登録に関係ないので、書き直しの画面にも残さない
                $plan['manual_rows'][] = ['pick' => $str('pick'), 'songs' => $str('songs')];
            }
        }
        try {
            [$timetables, $refs] = build_manual_timetables($plan, $plan['manual_rows']);
        } catch (RuntimeException $e) {
            $_SESSION['import_plan'] = $plan;
            flash($e->getMessage(), 'error');
            redirect('import');
        }
        $plan['timetables'] = $timetables;
        $plan['needs_timetable'] = false;
        $plan = finish_import_plan($plan);
        // バンド名での自動の対応付けは使わず、画面で選んだ名簿のバンドをそのまま枠に対応させる
        foreach ($plan['rosters'] as $ri => $r) {
            foreach (array_keys($r['bands']) as $bi) {
                $plan['rosters'][$ri]['bands'][$bi]['slot'] = '';
            }
        }
        foreach ($refs as $slotKey => $ref) {
            [$ri, $bi] = array_map('intval', explode(':', $ref));
            $plan['rosters'][$ri]['bands'][$bi]['slot'] = $slotKey;
        }
        $_SESSION['import_plan'] = $plan;
        unset($_SESSION['import_form']);
        redirect('import');
    }

    // ---------- プレビューから手入力の画面に戻る（プレビューで直した内容は捨てる） ----------
    if ($action === 'edit_tt' && isset($_SESSION['import_plan']['manual_rows'])) {
        $_SESSION['import_plan']['needs_timetable'] = true;
        unset($_SESSION['import_form']);
        redirect('import');
    }

    // ---------- やり直し ----------
    if ($action === 'reset') {
        unset($_SESSION['import_plan'], $_SESSION['import_form']);
        redirect('import');
    }

    // ---------- 登録 ----------
    if ($action === 'commit' && isset($_SESSION['import_plan'])) {
        try {
            $liveIds = commit_import_plan($pdo, $_SESSION['import_plan'], $input);
            unset($_SESSION['import_plan'], $_SESSION['import_form']);

            // 今回の取り込みで自分のアカウントがメンバーと紐付いたかもしれないので、セッションも更新
            $st = $pdo->prepare('SELECT member_id FROM user_account WHERE user_id = ?');
            $st->execute([$_SESSION['user']['user_id']]);
            $mid = $st->fetchColumn();
            $_SESSION['user']['member_id'] = $mid ? (int)$mid : null;

            if (!$liveIds) {
                flash('取り込む日程がありませんでした（すべて「取り込まない」）', 'info');
                redirect('import');
            }
            flash('取り込みが完了しました！');
            redirect(count($liveIds) === 1 ? 'live?id=' . $liveIds[0] : './');
        } catch (Throwable $e) {
            // 失敗したら入力内容をセッションに残して、プレビューに戻ったとき復元する
            $_SESSION['import_form'] = $input;
            // RuntimeException は自分で書いたエラー文なので見せて良い。それ以外（DBエラー等）は中身を隠す
            flash('登録できませんでした: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'データベースエラー'), 'error');
            if (config('debug') && !$e instanceof RuntimeException) {
                flash($e->getMessage(), 'error');
            }
            redirect('import');
        }
    }
    redirect('import');
}

/* =====================================================================
 *  画面の表示
 * ===================================================================== */
$plan = $_SESSION['import_plan'] ?? null;
$form = $_SESSION['import_form'] ?? []; // 登録失敗で戻ってきたときの入力内容

render_header($plan === null ? '新規追加' : '取り込み', 'import');

if ($plan === null): // ==================== アップロード画面 ====================
    $addTab = 'file';
    require __DIR__ . '/partials/add_tabs.php';
?>

<!-- enctype="multipart/form-data" が無いとファイルが送られない -->
<form method="post" enctype="multipart/form-data" class="card upload" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label class="dropzone" data-dropzone>
        <input type="file" name="files[]" accept=".csv,.xlsx,.xlsm,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet<?= ai_reader_enabled() ? ',image/jpeg,image/png,image/webp,image/gif' : '' ?>" multiple required data-file-input>
        <span class="dropzone__icon" aria-hidden="true"><?= icon('upload_file') ?></span>
        <span class="dropzone__title">ここにファイルをドロップ</span>
        <span class="muted small">クリックしてファイルを選択 · CSV / Excel / PDF<?= ai_reader_enabled() ? ' / 画像' : '' ?> · 最大<?= MAX_FILES ?>ファイル</span>
        <ul class="dropzone__list" data-file-list></ul>
    </label>
    <!-- 送信したら JS（assets/app.js の setupLoading）が出す。画像があれば AI の読み取りで数十秒かかる -->
    <div class="loading" data-loading-box hidden role="status" aria-live="polite">
        <span class="loading__spinner" aria-hidden="true"></span>
        <p class="loading__title" data-loading-title>読み込み中…</p>
        <p class="muted small" data-loading-sub></p>
    </div>
    <button class="btn btn--primary btn--block" type="submit">読み込んでプレビュー</button>
</form>

<section class="guide">
    <div class="card">
        <h3><span class="step">1</span>タイムテーブル</h3>
        <p class="muted small">1ファイルにつきライブ1日分を読み込みます。<?php if (ai_reader_enabled()): ?>写真・スクリーンショット（画像は<?= AI_MAX_IMAGES ?>枚まで）は AI が読み取ります。読み間違いがあるので、プレビューで必ず確認してください。<?php else: ?>画像ファイルは読み込めないのでCSVファイルかPDFファイルに変換してください。<?php endif; ?></p>
    </div>
    <div class="card">
        <h3><span class="step">2</span>名簿</h3>
        <p class="muted small">タイムテーブルのバンドと演奏者を紐づけます。タイムテーブルのファイルが無ければ、名簿だけ選ぶと次の画面でタイムテーブルを手入力できます。</p>
    </div>
    <div class="card">
        <h3><span class="step">3</span>アップロード</h3>
        <p class="muted small">正しい形式の名簿とタイムテーブルのファイルをセットで選択してください。複数ライブ分まとめてアップロードできます。</p>
    </div>
</section>

<?php elseif (!empty($plan['needs_timetable'])): // ==================== タイムテーブル手入力画面 ====================
    $rosterChoices = roster_choices($plan); // 'ラベル' => 'ri:bi'。同じ名前のバンドは「（Vo ◯◯）」付きで区別されている
    // 空の行（「— 使わない —」）は最初は出さない。休憩などを入れたいときは「＋ 行を追加」
    $emptyRow = ['pick' => '', 'songs' => ''];
    $manualRows = $plan['manual_rows'];
    $dayLabels = day_labels($pdo); // 区切りのボタンに出す日程名（いつもの日程名 ＋ これまでに作られた日程名）
    // 1日目は見出しに固定（動かせない・消せない）。先頭の行が 1日目 の区切りなら、見出しと重なるので捨てる
    if (($manualRows[0]['divider'] ?? null) === DAY_LABELS[0]) {
        array_shift($manualRows);
    }
?>
<section class="hero">
    <div>
        <p class="eyebrow">Import · Timetable</p>
        <h1 class="display">タイムテーブルを手入力</h1>
        <p class="muted">名簿だけが読み込まれました。出演順に並べてください。区切りの行（2日目など）より下のバンドが、その日程になります。</p>
    </div>
    <dl class="stats">
        <div><dt>名簿のバンド</dt><dd><?= count($rosterChoices) ?></dd></div>
    </dl>
</section>

<?php foreach ($plan['errors'] as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!empty($plan['notes'])): ?>
<div class="flash flash--warn ai-notes">
    <p>AI からのおしらせ（<?= count($plan['notes']) ?>件）</p>
    <ul><?php foreach ($plan['notes'] as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" class="card manual-tt">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="manual_tt">
    <ul class="muted small">
        <li>左端の ≡ をドラッグ（または ≡ を選んで ↑↓キー）で並べ替え。2日目などの区切りの行も同じように動かせます（一番上の 1日目 は固定）</li>
        <li>2日以上あるときは「＋ 2日目」などで区切りを足し、その下にバンドを動かす。番号は日程ごとの出演順です</li>
        <li>同じ名前のバンドは「（Vo ◯◯）」で見分ける。休憩・転換は空の行で「バンド以外」から、出ないバンドは「— 使わない —」に</li>
        <li>曲数は名簿の値が入っています（空なら名簿の曲数を使います）。時間・ライブ名・開催日・会場はあとで入力できます</li>
    </ul>
    <div class="table-scroll">
        <table class="table table--edit">
            <thead>
                <tr><th aria-label="並び替え"></th><th>順</th><th>バンド</th><th>曲数</th></tr>
                <!-- 1日目は固定の区切り（見出しの中なので、ドラッグしてもこの上には行が入らない）。区切りより上の行は 1日目 になる -->
                <tr class="manual-divider manual-divider--fixed"><td></td><td colspan="3"><span class="manual-divider__label"><?= h(DAY_LABELS[0]) ?></span></td></tr>
            </thead>
            <!-- data-sortable: ≡ のドラッグで並び替え（assets/app.js の setupSlotSort。プレビューの表と同じ部品） -->
            <tbody data-sortable data-manual-rows>
            <?php foreach ($manualRows as $i => $r): ?><?= manual_row_html((string)$i, $r, $rosterChoices, $dayLabels) ?><?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- 「＋」で足す行のひな形。__i__ を JS が次の番号に、__day__ を日程名に置きかえる。
         新しい日程名の区切りは名前を打つ入力欄（divider を空にすると候補に無い名前 = 入力欄になる） -->
    <template data-manual-template><?= manual_row_html('__i__', $emptyRow, $rosterChoices, $dayLabels) ?></template>
    <template data-manual-divider-template><?= manual_row_html('__i__', ['divider' => '__day__'], $rosterChoices, $dayLabels) ?></template>
    <template data-manual-new-divider-template><?= manual_row_html('__i__', ['divider' => ''], $rosterChoices, $dayLabels) ?></template>
    <div class="manual-tt__add">
        <button type="button" class="btn btn--ghost btn--sm" data-manual-add>＋ 行を追加</button>
        <?php foreach (array_slice($dayLabels, 1) as $d): ?>
            <button type="button" class="btn btn--ghost btn--sm" data-manual-add-day="<?= h($d) ?>">＋ <?= h($d) ?></button>
        <?php endforeach; ?>
        <button type="button" class="btn btn--ghost btn--sm" data-manual-add-new-day>＋ 新しい日程名</button>
    </div>
    <div class="sticky-actions">
        <button class="btn btn--ghost" type="submit" form="reset-form">やり直す</button>
        <button class="btn btn--primary" type="submit">プレビューへ</button>
    </div>
</form>
<form method="post" id="reset-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"></form>
<script>
// タイムテーブル手入力の表（この画面でしか使わないのでここに書く）
//   ・「＋ 行を追加」「＋ 2日目」などで行を足す（下に足すので、≡ で好きな位置へ動かす）
//   ・区切りの × で区切りを消す / 「使わない」の行は薄く表示
//   ・左の番号は「日程ごとの出演順」。並び替え・追加・削除・バンドの選び直しのたびに振り直す
(() => {
    const body = document.querySelector('[data-manual-rows]');
    let next = body.rows.length;
    const add = (html) => {
        if (body.rows.length >= <?= MAX_MANUAL_ROWS ?>) return;
        body.insertAdjacentHTML('beforeend', html.replaceAll('__i__', String(next++)));
        body.dispatchEvent(new Event('slots:changed', { bubbles: true })); // 並び替えの部品に行が増えたことを知らせる
    };
    const renumber = () => {
        let n = 0;
        [...body.rows].forEach((tr) => {
            if (tr.hasAttribute('data-manual-divider')) { n = 0; return; }
            const pick = tr.querySelector('[data-manual-pick]').value;
            const isBand = pick !== '' && !pick.startsWith('break:'); // 休憩・使わない行には番号を付けない
            tr.querySelector('[data-manual-no]').textContent = isBand ? String(++n) : '';
        });
    };
    document.querySelector('[data-manual-add]').addEventListener('click', () => {
        add(document.querySelector('[data-manual-template]').innerHTML);
    });
    // 日程名は DB に保存された（誰かが作った）文字なので、HTML に埋め込む前にエスケープする（XSS 対策。PHP の h() と同じ）
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    document.querySelectorAll('[data-manual-add-day]').forEach((btn) => btn.addEventListener('click', () => {
        add(document.querySelector('[data-manual-divider-template]').innerHTML.replaceAll('__day__', esc(btn.dataset.manualAddDay)));
    }));
    document.querySelector('[data-manual-add-new-day]').addEventListener('click', () => {
        add(document.querySelector('[data-manual-new-divider-template]').innerHTML);
        body.querySelector('tr:last-child [data-manual-new-day]')?.focus();
    });
    body.addEventListener('click', (e) => {
        if (e.target.closest('[data-manual-remove]')) {
            e.target.closest('tr').remove();
            body.dispatchEvent(new Event('slots:changed', { bubbles: true }));
        }
    });
    body.addEventListener('change', (e) => {
        if (e.target.matches('[data-manual-pick]')) {
            e.target.closest('tr').classList.toggle('is-excluded', e.target.value === '');
            renumber();
        }
    });
    new MutationObserver(renumber).observe(body, { childList: true }); // 行が動いた・増えた・消えた
    renumber();
})();
</script>

<?php else: // ==================== プレビュー画面 ====================

    // ---- 入力欄の候補（datalist）用に、既存のライブ名・会場を取っておく ----
    $liveNames = $pdo->query('SELECT DISTINCT name FROM live ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $memberNames = member_name_choices($pdo); // 名簿のメンバー欄の候補 [名前 => ふりがな]（ふりがなでも探せる）
    $bandNames = band_name_choices($pdo);     // タイムテーブルのバンド名の候補（新しい順）
    // 「登録済みのライブと統合」のポップアップ用（登録済みの日程名つき）
    $lives = lives_with_labels($pdo);
    $dayLabels = day_labels($pdo); // 日程名のプルダウンの中身
    $livesById = array_column($lives, null, 'live_id');
    // 会場はプルダウンで選ばせる（表記ゆれ防止）。FETCH_KEY_PAIR で [venue_id => name] の形になる
    $venues = $pdo->query('SELECT venue_id, name FROM venue ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR);

    // ---- 名簿の全セルを1回でまとめて色分け判定（1セルずつ SQL を投げると遅いので） ----
    $cellTexts = [];   // "ri-bi-列番号" / "ri-bi-x追加列番号" => セルの文字
    $cellInsts = [];   // "ri-bi-x追加列番号" => 追加列のプルダウンの初期値（instrument_id）
    $extraCols = [];   // ri => 追加列の数
    foreach ($plan['rosters'] as $ri => $roster) {
        // 追加列の数 = 名簿から作った数と、前回（登録失敗で戻ってきた）の入力の列数の多いほう
        $extraCols[$ri] = (int)($roster['extra_cols'] ?? 0);
        foreach ($roster['bands'] as $bi => $band) {
            $extraCols[$ri] = max($extraCols[$ri], count((array)($form['rb'][$ri][$bi]['x'] ?? [])));
        }
        foreach ($roster['bands'] as $bi => $band) {
            $rb = $form['rb'][$ri][$bi] ?? null;
            foreach ($roster['columns'] as $col => $_) {
                $cellTexts["$ri-$bi-$col"] = (string)($rb['c'][$col] ?? $band['cells'][$col] ?? '');
            }
            for ($n = 0; $n < $extraCols[$ri]; $n++) {
                $extra = $band['extras'][$n] ?? ['name' => '', 'instrument_id' => null];
                $cellTexts["$ri-$bi-x$n"] = (string)($rb ? ($rb['x'][$n]['name'] ?? '') : $extra['name']);
                $cellInsts["$ri-$bi-x$n"] = (int)($rb ? ($rb['x'][$n]['inst'] ?? 0) : ($extra['instrument_id'] ?? 0));
            }
        }
    }
    $cellStatus = array_combine(array_keys($cellTexts), classify_cells($pdo, array_values($cellTexts)) ?: []) ?: [];

    // ---- タイムテーブルの「名簿」検索欄の候補と初期値 ----
    $rosterChoices = roster_choices($plan);          // 検索欄の文字 => 'ri:bi'
    $rosterLabels = array_flip($rosterChoices);      // 'ri:bi' => 検索欄の文字
    $autoRoster = [];                                // "ti:si" => 自動で対応付けた名簿のバンド（検索欄の文字）
    foreach ($plan['rosters'] as $ri => $roster) {
        foreach ($roster['bands'] as $bi => $band) {
            if ($band['slot'] !== '') {
                $autoRoster[$band['slot']] = $rosterLabels["$ri:$bi"];
            }
        }
    }
    // 各枠の「名簿」欄の値と、名簿のバンドごとに「どの枠が使っているか」を先に集めておく
    // （先に全部見ないと、1行目を描く時点では「後ろの行とかぶっているか」が分からないため）
    $slotRoster = []; // "ti:si" => 検索欄の文字
    $usedBy = [];     // 'ri:bi' => [その名簿を選んだ出演バンド名...]
    foreach ($plan['timetables'] as $ti => $tt) {
        foreach ($tt['slots'] as $si => $s) {
            $fs = $form['tt'][$ti]['s'][$si] ?? null; // 失敗して戻ってきたときの入力値
            $roster = (string)($fs['roster'] ?? $autoRoster["$ti:$si"] ?? '');
            $slotRoster["$ti:$si"] = $roster;
            $include = $fs ? !empty($fs['include']) : $s['include'];
            if ($include && isset($rosterChoices[$roster])) {
                $usedBy[$rosterChoices[$roster]][] = (string)($fs['name'] ?? $s['band_name']);
            }
        }
    }
    $rosterBandCount = array_sum(array_map(static fn($r) => count($r['bands']), $plan['rosters']));

    // タイムテーブルのどの枠にも対応していない名簿のバンド（枠が無いので登録されない）
    // 最初にプレビューを開いたときだけポップアップで知らせる（登録失敗で戻ってきたときは出さない）
    $missingBands = [];
    if (!$form) {
        foreach ($plan['rosters'] as $ri => $roster) {
            foreach ($roster['bands'] as $bi => $band) {
                if (!isset($usedBy["$ri:$bi"])) {
                    $missingBands[] = $band['band_name'];
                }
            }
        }
    }

    // ファイルの「12月6日」から年を推測して入れた開催日（年はファイルに無いので、間違っていることがある）
    // 最初にプレビューを開いたときだけ「年を確認してください」のポップアップで知らせる
    $guessedDates = [];
    if (!$form) {
        foreach ($plan['timetables'] as $tt) {
            if ($tt['date'] !== '' && empty($tt['year_known'])) { // 年がタイトルかファイル名にあったものは聞かない
                $ts = strtotime($tt['date']);
                $guessedDates[] = [
                    'title' => $tt['title'] !== '' ? $tt['title'] : $tt['file'],
                    // 曜日も出す。「土曜のはずなのに火曜」なら年が違うとすぐ気づける
                    'date' => date('Y年n月j日', $ts) . '（' . ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', $ts)] . '）',
                    'year' => academic_year((int)date('n', $ts), (int)date('Y', $ts)),
                ];
            }
        }
    }
?>
<section class="hero">
    <div>
        <p class="eyebrow">Import · Preview</p>
        <h1 class="display">内容を確認・修正</h1>
        <p class="muted">まだ保存されていません。表の中はすべて書き換えられます。直したら一番下の「登録する」へ。</p>
    </div>
    <dl class="stats">
        <div><dt>日程</dt><dd><?= count($plan['timetables']) ?></dd></div>
        <div><dt>名簿のバンド</dt><dd><?= $rosterBandCount ?></dd></div>
    </dl>
</section>

<?php foreach ($plan['errors'] as $e): ?><div class="flash flash--error"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!empty($plan['notes'])): ?>
<div class="flash flash--warn ai-notes">
    <p>AI からのおしらせ（<?= count($plan['notes']) ?>件）</p>
    <ul><?php foreach ($plan['notes'] as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- data-pack: 送信時に JS が全項目を JSON 1個にまとめる（read_form_input() の説明参照） -->
<form method="post" class="import-form" data-pack>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commit">

    <!-- 入力候補（data-suggest-list で使う。assets/app.js の setupSuggest） -->
    <?= render_suggest_datalist('dl-live-names', $liveNames) ?>
    <?= render_suggest_datalist('member-names', array_keys($memberNames), $memberNames) ?>
    <?= render_suggest_datalist('band-names', $bandNames) ?>
    <!-- 名簿の検索欄の候補。data-key は JS が「名簿」側の表示を更新するのに使う -->
    <datalist id="dl-roster"><?php foreach ($rosterChoices as $label => $ref): ?><option value="<?= h($label) ?>" data-key="<?= h($ref) ?>"><?php endforeach; ?></datalist>
    <!-- 「登録済みのライブと統合」のポップアップの中身（JS が開くたびに複製して使う） -->
    <?php require __DIR__ . '/partials/live_picker.php'; ?>

    <h2 class="section-title">① タイムテーブル</h2>

    <?php foreach ($plan['timetables'] as $ti => $tt):
        $f = $form['tt'][$ti] ?? []; // 失敗して戻ってきたときの入力値
        // 「入力値があればそれ、無ければファイルから読んだ値」を返す小さな関数
        $val = static fn(string $k, $default) => $f[$k] ?? $default;

        // 日程名: 失敗して戻ってきたならその選択（'__new__' なら打った新しい日程名）、初回はファイルから検知した日程
        $labelSel = (string)$val('label', $tt['label']);
        $labelNew = (string)($f['label_new'] ?? '');
        if ($labelSel !== '__new__' && !in_array($labelSel, $dayLabels, true)) {
            [$labelSel, $labelNew] = ['__new__', $labelSel]; // 手入力の区切りで新しく作った日程名
        }
        $label = $labelSel === '__new__' ? trim($labelNew) : $labelSel;

        // 年度は開催日から自動で決める（入力欄は無い）
        $date = (string)$val('date', $tt['date']);
        $year = fiscal_year_from_date($date);

        // 会場: 失敗して戻ってきたならその選択、初回はファイルの会場名に一番近い登録済みの会場
        if (isset($f['venue_id'])) {
            $venueSel = (string)$f['venue_id'];
            $venueNew = (string)($f['venue_new'] ?? '');
        } else {
            $guess = guess_venue($tt['venue'], $venues);
            // 近い会場が無く、ファイルに会場名があれば「新規作成」にしてその名前を入れておく
            $venueSel = $guess !== null ? (string)$guess : ($tt['venue'] !== '' ? 'new' : '');
            $venueNew = $guess === null ? $tt['venue'] : '';
        }

        // 「登録済みのライブと統合」: 失敗して戻ってきたときだけ ON の可能性がある
        $merge = !empty($f['merge']);
        $mergeLive = $livesById[(int)($f['live_id'] ?? 0)] ?? null;

        // 同じ日程がもう DB にあるか（あれば上書きの注意を出す）
        $exists = false;
        if ($merge) {
            if ($mergeLive !== null) {
                $exists = in_array($label, explode('・', (string)$mergeLive['labels']), true);
            }
        } elseif ($year !== null) {
            $st = $pdo->prepare('SELECT 1 FROM live l JOIN live_day d ON d.live_id = l.live_id
                WHERE l.fiscal_year = ? AND l.name = ? AND d.label = ?');
            $st->execute([$year, $val('live_name', $tt['live_name']), $label]);
            $exists = (bool)$st->fetchColumn();
        }
        $bandSlots = array_filter($tt['slots'], static fn($s) => $s['is_band']); ?>
        <section class="card import-day" data-timetable="<?= $ti ?>">
            <header class="import-day__head">
                <div>
                    <p class="file-name"><?= icon('description') ?> <?= h($tt['file']) ?></p>
                    <h3 class="import-day__title"><?= h($tt['title'] ?: '（タイトルなし）') ?></h3>
                </div>
                <div class="import-day__badges">
                    <span class="pill"><?= count($bandSlots) ?> バンド</span>
                    <span class="pill" data-unmatched-badge></span>
                </div>
            </header>

            <!-- 常に置いておき、ライブ名・日程・開催日・統合の切り替えに合わせて JS が出し入れする（setupMergeToggle） -->
            <div class="flash flash--warn" data-exists-flash<?= $exists ? '' : ' hidden' ?>>この日程は登録済みです。「上書き」にチェックすると、今のデータを消して置き換えます。</div>

            <!-- name="tt[0][date]" のように書くと、PHP では $_POST['tt'][0]['date'] で受け取れる -->
            <div class="form-grid">
                <!-- data-merge: 「登録済みのライブと統合」の切り替え（assets/app.js の setupMergeToggle） -->
                <div class="field field--wide" data-merge>
                    <span class="live-name-head">ライブ名
                        <button type="button" class="merge-toggle" aria-pressed="<?= $merge ? 'true' : 'false' ?>" data-merge-toggle><?= icon('merge') ?> 登録済みのライブと統合</button>
                    </span>
                    <input name="tt[<?= $ti ?>][live_name]" value="<?= h($val('live_name', $tt['live_name'])) ?>" data-suggest-list="dl-live-names" autocomplete="off" maxlength="50" placeholder="例: 文化祭ライブ" required data-live-name<?= $merge ? ' hidden disabled' : '' ?>>
                    <!-- disabled の欄は送信されない → OFF のときは merge / live_id を送らない -->
                    <input type="hidden" name="tt[<?= $ti ?>][merge]" value="1" data-merge-flag<?= $merge ? '' : ' disabled' ?>>
                    <input type="hidden" name="tt[<?= $ti ?>][live_id]" value="<?= $mergeLive ? (int)$mergeLive['live_id'] : '' ?>" data-live-id<?= $merge ? '' : ' disabled' ?>>
                    <div class="live-pick" data-live-pick-wrap<?= $merge ? '' : ' hidden' ?>>
                        <button type="button" class="live-pick__btn" aria-haspopup="listbox" aria-expanded="false" data-live-pick
                            data-year="<?= $mergeLive ? (int)$mergeLive['fiscal_year'] : '' ?>" data-labels="<?= $mergeLive ? h((string)$mergeLive['labels']) : '' ?>">
                            <span data-live-pick-text<?= $mergeLive ? '' : ' class="is-placeholder"' ?>><?= $mergeLive ? h($mergeLive['fiscal_year'] . '年度 ' . $mergeLive['name']) : 'ライブを選択' ?></span>
                            <?= icon('expand_more') ?>
                        </button>
                    </div>
                    <small class="merge-note" data-merge-note></small>
                </div>
                <div class="field"><span>日程</span>
                    <!-- 初期選択はタイムテーブルのタイトルから検知した日程（lib/import/parsers.php）。
                         「＋ 新しい日程名を作る」を選ぶと入力欄が出る（会場と同じ。lib/repository.php の day_label_control） -->
                    <?= day_label_control("tt[$ti]", $labelSel, $labelNew, $dayLabels) ?></div>
                <label class="field"><span>開催日 <small class="muted" data-fiscal-year><?= $year !== null ? "→ {$year}年度" : '' ?></small></span>
                    <input type="date" name="tt[<?= $ti ?>][date]" value="<?= h($date) ?>" required data-date-input></label>
                <div class="field field--wide"><span>会場<?php if ($tt['venue'] !== ''): ?> <small class="muted">（ファイルの表記: <?= h($tt['venue']) ?>）</small><?php endif; ?></span>
                    <select name="tt[<?= $ti ?>][venue_id]" aria-label="会場" data-venue-select>
                        <option value="">— 未設定 —</option>
                        <?php foreach ($venues as $id => $name): ?>
                            <option value="<?= (int)$id ?>"<?= $venueSel === (string)$id ? ' selected' : '' ?>><?= h($name) ?></option>
                        <?php endforeach; ?>
                        <option value="new"<?= $venueSel === 'new' ? ' selected' : '' ?>>＋ 新しい会場を作る</option>
                    </select>
                    <!-- 「新しい会場を作る」を選んだときだけ表示（JS で切り替え） -->
                    <input name="tt[<?= $ti ?>][venue_new]" value="<?= h($venueNew) ?>" maxlength="50" placeholder="新しい会場名" aria-label="新しい会場名" data-venue-new<?= $venueSel === 'new' ? '' : ' hidden' ?>>
                </div>
            </div>
            <div class="checks">
                <label class="check"><input type="checkbox" name="tt[<?= $ti ?>][overwrite]" value="1"<?= !empty($f['overwrite']) ? ' checked' : '' ?>> 登録済みなら上書き</label>
                <label class="check"><input type="checkbox" name="tt[<?= $ti ?>][skip]" value="1"<?= !empty($f['skip']) ? ' checked' : '' ?> data-skip> この日程は取り込まない</label>
            </div>

            <div class="table-scroll">
                <table class="table table--edit">
                    <thead><tr>
                        <th aria-label="並び替え"></th>
                        <th title="チェックした行だけ登録">取込</th><th>順</th><th title="並び替えても時間は動かない">時間</th><th>登録バンド名</th>
                        <th title="名簿ファイルのバンド名で検索して選ぶ">名簿ファイル内バンド名</th><th>曲数</th><th title="バンド全体の補足事項">メモ</th>
                    </tr></thead>
                    <!-- data-sortable: 左端の ≡ をドラッグで行を並び替える（assets/app.js の setupSlotSort）。時間の列は動かない -->
                    <tbody data-sortable>
                    <?php
                    // 時間は「表の何行目か」に固定。並び替えると、行（バンド）はその位置の枠の時間を使う
                    //   at = その行が使う時間の枠の番号（JS が並び替えのたびに書き換える）
                    // 並び替えて送った後に登録失敗で戻ってきたら、at の順に並べて元の並びを再現する
                    $slotKeys = array_keys($tt['slots']);
                    $rowOrder = $slotKeys;
                    usort($rowOrder, static fn($a, $b) => [(int)($f['s'][$a]['at'] ?? $a), $a] <=> [(int)($f['s'][$b]['at'] ?? $b), $b]);
                    $order = 0; // 出演順は入力させず、取込にチェックがある行を上から 1, 2, 3… と振る（並び替えたら JS が振り直す）
                    foreach ($rowOrder as $pos => $si):
                        $s = $tt['slots'][$si];
                        $timeSi = $slotKeys[$pos];                // この位置の時間の枠
                        $ts = $tt['slots'][$timeSi];
                        $fs = $f['s'][$si] ?? null;               // 失敗して戻ってきたときの入力値
                        $include = $fs ? !empty($fs['include']) : $s['include'];
                        $orderNo = $include ? (string)++$order : '';
                        $key = "$ti:$si";
                        $roster = $slotRoster[$key];
                        $users = isset($rosterChoices[$roster]) ? ($usedBy[$rosterChoices[$roster]] ?? []) : [];
                        // 緑 = 名簿あり / 黄 = 同じ名簿を他の枠でも選んでいる / 赤 = 名簿なし
                        $rosterClass = !isset($rosterChoices[$roster]) ? ($include ? 'is-new' : '')
                            : ($include && count($users) > 1 ? 'is-similar' : 'is-ok');
                        $rosterHint = $rosterClass === 'is-similar' ? '同じ名簿を ' . count($users) . ' つの枠で選んでいます: ' . implode(' / ', $users) : ''; ?>
                        <tr class="<?= $include ? '' : 'is-excluded' ?>" data-slot="<?= h($key) ?>">
                            <td><button type="button" class="drag-handle" data-drag-handle aria-label="ドラッグで並び替え（↑↓キーでも動く）" title="ドラッグで並び替え"><?= icon('drag_indicator') ?></button>
                                <input type="hidden" name="tt[<?= $ti ?>][s][<?= $si ?>][at]" value="<?= (int)$timeSi ?>" data-at></td>
                            <td><input type="checkbox" name="tt[<?= $ti ?>][s][<?= $si ?>][include]" value="1"<?= $include ? ' checked' : '' ?> data-include aria-label="取り込む"></td>
                            <td class="mono nowrap"><span data-order-text><?= $orderNo ?></span>
                                <input type="hidden" name="tt[<?= $ti ?>][s][<?= $si ?>][order]" value="<?= $orderNo ?>" data-order></td>
                            <td class="mono nowrap muted" data-time><?= h($ts['start_time']) ?><?= $ts['end_time'] ? '–' . h($ts['end_time']) : '' ?></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][name]" value="<?= h($fs['name'] ?? $s['band_name']) ?>" maxlength="100" data-suggest-list="band-names" autocomplete="off" data-band-name aria-label="バンド名"></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][roster]" value="<?= h($roster) ?>" data-suggest-list="dl-roster" data-suggest-seed="[data-band-name]" autocomplete="off"
							class="name-input <?= $rosterClass ?>" title="<?= h($rosterHint) ?>" placeholder="名簿から検索" data-roster-input aria-label="名簿のバンド"></td>
                            <td><input type="number" class="input-num" name="tt[<?= $ti ?>][s][<?= $si ?>][songs]" value="<?= h($fs['songs'] ?? $s['song_count'] ?? '') ?>" min="0" aria-label="曲数"></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][note]" value="<?= h($fs['note'] ?? '') ?>" maxlength="255" aria-label="メモ"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($plan['rosters']): ?>
    <h2 class="section-title">② 名簿</h2>
    <div class="legend">
        <span><i class="swatch swatch--ok"></i>DB に登録済み</span>
        <span><i class="swatch swatch--similar"></i>似た人がいる（書き間違い？）</span>
        <span><i class="swatch swatch--new"></i>新しいメンバーとして登録</span>
        <span><?= icon('flag', 'icon--fill flag-icon') ?> 楽器があやしいバンドに付けると、登録後にメンバーへ確認をお願いする</span>
        <span class="muted small">セルにマウスを乗せる（スマホはタップ）と理由が出ます。1つのセルには1人。
            名簿で1つのセルに2人以上書かれていたら、2人目からは右端の「Other」列に移してあります（楽器は名前の下のボタンで選ぶ）。
            人が足りないときは「＋ 列を追加」。Vo. 欄の下の「Vo / Vo/Gt / …」で兼任を選べます（「⋯」にマウスを乗せる・タップすると Vo/Ba / Vo/Key / Vo/Dr が出ます）。</span>
    </div>

    <!-- 「＋ 列を追加」で JS が複製するプルダウン（全部の楽器から選べる。初期値はキーボード） -->
    <?php
    // 楽器の切り替えボタン: 「Key」「Vn」だけ見せて、残りは「⋯」の中
    $instShown = array_map('strval', array_filter([default_instrument_id('Key'), default_instrument_id('Vn')]));
    $allInstOptions = instrument_pick_options(instruments()); // Key./Other 列・右端の追加列用（Vo / Gt / Ba / Dr も含む全部の楽器）
    ?>
    <!-- 「＋ 列を追加」で JS が複製する楽器の切り替えボタン（name は JS が付け直す。初期値はキーボード） -->
    <template id="tpl-extra-instrument"><?= render_pick('tpl', $allInstOptions, $instShown, (string)default_instrument_id('Key'), 'この人の楽器', true, 'inst') ?></template>

    <?php foreach ($plan['rosters'] as $ri => $roster): ?>
        <section class="card import-day">
            <header class="import-day__head">
                <div>
                    <p class="file-name"><?= icon('description') ?> <?= h($roster['file']) ?></p>
                    <h3 class="import-day__title">名簿 <?= count($roster['bands']) ?> バンド</h3>
                </div>
                <button type="button" class="btn btn--ghost btn--sm" data-add-roster-col>＋ 列を追加</button>
            </header>
            <div class="table-scroll">
                <!-- data-extra-cols: 今ある追加列の数。JS が列を足すときの番号に使う -->
                <table class="table table--edit table--roster" data-roster-table="<?= $ri ?>" data-extra-cols="<?= $extraCols[$ri] ?>">
                    <thead><tr>
                        <th title="旗を付けると、登録後にバンドのメンバーへ「楽器があってるか確認して」と知らせる">確認</th>
                        <th>名簿のバンド名</th>
                        <?php foreach ($roster['columns'] as $col => $c): ?>
                            <!-- 見出しは略称で固定。マウスを乗せると名簿ファイルの元の見出しが出る -->
                            <th title="名簿の見出し: <?= h($c['title']) ?>"><?= h(part_label($c['part'])) ?></th>
                        <?php endforeach; ?>
                        <?php for ($n = 0; $n < $extraCols[$ri]; $n++): ?>
                            <th title="右端に足した列。楽器は人ごとに選ぶ">Other</th>
                        <?php endfor; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($roster['bands'] as $bi => $band):
                        // 登録するかどうかはタイムテーブル側（取込 + 名簿ファイル内バンド名）だけで決まる。
                        // ここで選べるのは 🚩（登録後に「楽器があってるか確認して」とバンドのメンバーに知らせる）だけ
                        $flag = !empty($form['rb'][$ri][$bi]['flag']); // 失敗して戻ってきたときの入力値。初回は OFF
                        // どの出演枠にも選ばれていない = 登録されない → 薄くして ⚠ を出す
                        $unused = !isset($usedBy["$ri:$bi"]); ?>
                        <tr class="<?= $unused ? 'is-excluded' : '' ?>" data-roster-key="<?= h("$ri:$bi") ?>">
                            <td><label class="flag-toggle" title="楽器があってるか、バンドのメンバーに確認してもらう"><input type="checkbox" name="rb[<?= $ri ?>][<?= $bi ?>][flag]" value="1"<?= $flag ? ' checked' : '' ?> aria-label="楽器の確認をお願いする"><?= icon('flag') ?></label></td>
                            <!-- roster-band-cell: グレーアウト時もこの列の ⚠ だけは薄くしない（app.css） -->
                            <td class="strong nowrap roster-band-cell"><span class="roster-band-name"><?= h($band['band_name']) ?></span>
                                <!-- どの出演枠もこの名簿を選んでいなければ出す（JS がタイムテーブルの変更に合わせて出し入れする） -->
                                <span class="status status--warn" title="タイムテーブルの「名簿ファイル内バンド名」でこのバンドを選ぶと登録されます" data-roster-missing<?= $unused ? '' : ' hidden' ?>><?= icon('warning') ?> タイムテーブルにないため登録されません</span>
                            </td>
                            <?php foreach ($roster['columns'] as $col => $c):
                                $k = "$ri-$bi-$col";
                                $stt = $cellStatus[$k] ?? ['status' => '', 'hint' => '']; ?>
                                <td>
                                    <input name="rb[<?= $ri ?>][<?= $bi ?>][c][<?= $col ?>]" value="<?= h($cellTexts[$k]) ?>"
                                           class="name-input<?= $stt['status'] ? ' is-' . h($stt['status']) : '' ?>"
                                           title="<?= h($stt['hint']) ?>" data-name-cell data-suggest-list="member-names" autocomplete="off" aria-label="メンバー"<?= suggest_attr($stt) ?>>
                                    <?php if ($c['part'] === 'Vo'):
                                        // Vo. 欄: 単体 / ギター / ベースボーカルを切り替える（初期値は名簿の「山田(Gt)」の書き方から）
                                        $vr = vocal_role($form['rb'][$ri][$bi]['vr'][$col] ?? $band['vo_roles'][$col] ?? null); ?>
                                        <!-- よく使う「Vo」「Vo/Gt」だけ見せて、残り（Vo/Ba / Vo/Key / Vo/Dr）は「⋯」の中にしまう -->
                                        <?= render_pick("rb[$ri][$bi][vr][$col]", VOCAL_ROLES, ['vo', 'gt'], $vr, 'ボーカルの形', trim($cellTexts[$k]) === '') ?>
                                    <?php elseif (is_free_part($c['part'])):
                                        // Key./Other の列だけ、人（セル）ごとに楽器を選べる。
                                        // 初期値: 名前の「(Vn)」→ 列の見出し（Cho. など）→ それ以外はキーボード（「その他」は初期値にしない）
                                        $ci = (string)(int)($form['rb'][$ri][$bi]['ci'][$col] ?? $band['cell_insts'][$col] ?? $c['instrument_id'] ?? 0); ?>
                                        <!-- 名前が空なら畳んでおく（入力されたら JS が開く） -->
                                        <?= render_pick("rb[$ri][$bi][ci][$col]", $allInstOptions, $instShown, $ci, 'この人の楽器', trim($cellTexts[$k]) === '', 'inst') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php for ($n = 0; $n < $extraCols[$ri]; $n++):
                                // 右端の追加列: 1セル1人。楽器は全部の楽器から選べる
                                $k = "$ri-$bi-x$n";
                                $stt = $cellStatus[$k] ?? ['status' => '', 'hint' => '']; ?>
                                <td>
                                    <input name="rb[<?= $ri ?>][<?= $bi ?>][x][<?= $n ?>][name]" value="<?= h($cellTexts[$k]) ?>"
                                           class="name-input<?= $stt['status'] ? ' is-' . h($stt['status']) : '' ?>"
                                           title="<?= h($stt['hint']) ?>" data-name-cell data-suggest-list="member-names" autocomplete="off" aria-label="メンバー"<?= suggest_attr($stt) ?>>
                                    <?= render_pick("rb[$ri][$bi][x][$n][inst]", $allInstOptions, $instShown, (string)$cellInsts[$k], 'この人の楽器', trim($cellTexts[$k]) === '', 'inst') ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="sticky-actions">
        <button class="btn btn--ghost" type="submit" form="reset-form">やり直す</button>
        <?php if (isset($plan['manual_rows'])): ?>
            <button class="btn btn--ghost" type="submit" form="edit-tt-form">タイムテーブルを書き直す</button>
        <?php endif; ?>
        <!-- 名簿の重複があるあいだは JS が「登録する」を押せなくして、この文を出す -->
        <span class="sticky-actions__note" data-submit-block hidden><?= icon('warning') ?> <span data-submit-block-text>名簿の重複を直すと登録できます</span></span>
        <button class="btn btn--primary" type="submit"<?= $plan['timetables'] ? '' : ' disabled' ?> data-submit>登録する</button>
    </div>
</form>
<!-- 「やり直す」は別のフォーム。form="reset-form" 属性でボタンだけ上のフォームの中に置いている -->
<form method="post" id="reset-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"></form>
<form method="post" id="edit-tt-form"><?= csrf_field() ?><input type="hidden" name="action" value="edit_tt"></form>

<?php if ($guessedDates): ?>
<!-- 開催日の年を推測で入れたとき、プレビューを開いた直後に1回だけ出す注意（JS の setupImportPreview が開く） -->
<dialog class="modal" data-year-check-dialog aria-labelledby="year-check-title">
    <form method="dialog" class="modal__body">
        <h3 id="year-check-title" class="modal__title"><?= icon('event') ?> 開催年を確認してください</h3>
        <p class="muted small">ファイルに年の記載がないため現在の西暦が入力されています。古いライブを登録する際は開催年を修正してください。<?= count($guessedDates) > 1 ? '1つの年を直すと、ほかの日程も同じ年になります。' : '' ?></p>
        <ul class="modal__list">
            <?php foreach ($guessedDates as $g): ?>
                <li><?= h($g['title']) ?> → <strong><?= h($g['date']) ?></strong> <span class="muted">（<?= (int)$g['year'] ?>年度）</span></li>
            <?php endforeach; ?>
        </ul>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">確認する</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($missingBands): ?>
<!-- タイムテーブルに無い名簿のバンドがあるとき、プレビューを開いた直後に1回だけ出す注意（JS の setupImportPreview が開く） -->
<dialog class="modal" data-roster-missing-dialog aria-labelledby="roster-missing-title">
    <form method="dialog" class="modal__body">
        <h3 id="roster-missing-title" class="modal__title"><?= icon('warning') ?> タイムテーブルにないため登録されません</h3>
        <p class="muted small">名簿にある次のバンドは、タイムテーブルのどの枠にも対応していません。登録するには、タイムテーブルの「名簿ファイル内バンド名」でこのバンドを選んでください。</p>
        <ul class="modal__list">
            <?php foreach ($missingBands as $name): ?><li><?= h($name) ?></li><?php endforeach; ?>
        </ul>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">OK</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($plan['rosters']): ?>
<!--
    楽器の「etc」を選んだときに開くモーダル（<dialog> は HTML 標準のモーダル部品）。
    取り込みのフォームの「外」に置いている（フォームの中にフォームは入れられない & 取り込みの送信内容に混ざらないように）。
    「追加する」で api_instrument.php に送って楽器マスタに登録し、その楽器を選んだ状態にする（assets/app.js の setupPicks）。
    「etc のまま」は何も追加せず、etc を選んだ状態で閉じる。
-->
<dialog class="modal" data-new-instrument aria-labelledby="new-instrument-title">
    <form method="dialog" class="modal__body">
        <h3 id="new-instrument-title" class="modal__title">ここに無い楽器を追加</h3>
        <p class="muted small">追加した楽器は、ほかの人の欄でも選べるようになります。間違えて追加したものは、管理者が「楽器の管理」で消せます。</p>
        <div class="form-grid">
            <label class="field"><span>略称（例: Tp）</span><input name="short_name" maxlength="10" autocomplete="off" required></label>
            <label class="field"><span>楽器名（例: トランペット）</span><input name="name" maxlength="30" autocomplete="off" required></label>
        </div>
        <p class="flash flash--error" data-modal-error hidden></p>
        <div class="form-actions">
            <button type="button" class="btn btn--ghost" data-modal-cancel>キャンセル</button>
            <!-- 楽器名がわからない・追加するほどでもないとき。楽器は追加せず「etc」のまま選んでおく -->
            <button type="button" class="btn" data-modal-keep-etc>etc のまま</button>
            <button type="submit" class="btn btn--primary">追加する</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php endif;
render_footer();
