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

$pdo = db();

/**
 * プレビューのフォームは入力欄が多い（数百個）。
 * PHP には「1回の POST で受け取れる項目数」の上限（php.ini の max_input_vars、XAMPP では 1000）があり、
 * 超えた分は「エラーも出さずに捨てられる」。名簿が大きいと登録内容が欠けてしまう。
 *
 * 対策: JavaScript が送信直前に全項目を JSON 1個（payload）にまとめて送る（assets/app.js）。
 * ここで JSON を元の $_POST と同じ形の配列に戻す。
 * JavaScript が動かないときは payload が無いので、普通の $_POST をそのまま使う。
 */
function read_form_input(): array
{
    $payload = $_POST['payload'] ?? null;
    if (!is_string($payload) || $payload === '') {
        return $_POST;
    }
    $pairs = json_decode($payload, true);
    if (!is_array($pairs)) {
        return $_POST;
    }
    // $pairs は [["tt[0][s][3][name]", "King Gnu"], ["action", "commit"], ...] の形
    $input = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1])) {
            continue;
        }
        // "tt[0][s][3][name]" → ['tt', '0', 's', '3', 'name'] に分解
        if (!preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/', $pair[0], $m)) {
            continue;
        }
        preg_match_all('/\[([^\[\]]*)\]/', $m[2], $sub);
        $keys = array_merge([$m[1]], $sub[1]);

        // $input['tt']['0']['s']['3']['name'] = 'King Gnu' を、キーの数がいくつでも動くように書いたもの
        // $ref は「今いる場所」を指す参照。1段ずつ奥へ進んでいく
        $ref = &$input;
        foreach ($keys as $k) {
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref = &$ref[$k];
        }
        $ref = $pair[1];
        unset($ref); // 参照を切っておかないと、次のループで上書き事故が起きる
    }
    return $input;
}

/**
 * 「Vo | Gt/Vo | ⋯」「Key | Vn | ⋯」のような切り替えボタン（中身はラジオボタン）の HTML を作る。
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
            redirect('import.php');
        }
        if ($count > MAX_FILES) {
            flash('一度にアップロードできるのは ' . MAX_FILES . ' ファイルまでです', 'error');
            redirect('import.php');
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
            if (!in_array($ext, IMPORT_EXTENSIONS, true)) {
                flash("{$name}: CSV / Excel(.xlsx) / PDF のどれかを選んでください", 'error');
                continue;
            }
            $files[] = ['path' => $up['tmp_name'][$i], 'name' => $name];
        }
        if ($files) {
            $plan = build_import_plan($files);
            if (!$plan['timetables']) {
                $plan['errors'][] = 'タイムテーブルが1つも含まれていません。タイムテーブル（時間・バンド名の表）も一緒にアップロードしてください。';
            }
            // ファイル自体は保存しない。読み取った結果（配列）だけセッションに置く
            $_SESSION['import_plan'] = $plan;
            unset($_SESSION['import_form']);
        }
        redirect('import.php');
    }

    // ---------- やり直し ----------
    if ($action === 'reset') {
        unset($_SESSION['import_plan'], $_SESSION['import_form']);
        redirect('import.php');
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
                redirect('import.php');
            }
            flash('取り込みが完了しました！');
            redirect(count($liveIds) === 1 ? 'live.php?id=' . $liveIds[0] : 'index.php');
        } catch (Throwable $e) {
            // 失敗したら入力内容をセッションに残して、プレビューに戻ったとき復元する
            $_SESSION['import_form'] = $input;
            // RuntimeException は自分で書いたエラー文なので見せて良い。それ以外（DBエラー等）は中身を隠す
            flash('登録できませんでした: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'データベースエラー'), 'error');
            if (config('debug') && !$e instanceof RuntimeException) {
                flash($e->getMessage(), 'error');
            }
            redirect('import.php');
        }
    }
    redirect('import.php');
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
<form method="post" enctype="multipart/form-data" class="card upload">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <label class="dropzone" data-dropzone>
        <input type="file" name="files[]" accept=".csv,.xlsx,.xlsm,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple required data-file-input>
        <span class="dropzone__icon" aria-hidden="true">↑</span>
        <span class="dropzone__title">ここにファイルをドロップ</span>
        <span class="muted small">またはクリックして選択 · CSV / Excel / PDF · 最大<?= MAX_FILES ?>ファイル</span>
        <ul class="dropzone__list" data-file-list></ul>
    </label>
    <button class="btn btn--primary btn--block" type="submit">読み込んでプレビュー</button>
</form>

<section class="guide">
    <div class="card">
        <h3><span class="step">1</span>タイムテーブル</h3>
        <p class="muted small">1ファイル（Excel なら1シート）= ライブ1日分。「時間 / 持ち時間 / バンド名 / 曲数 / 人数 / key」の列と、表の上の「○○ライブ2日目 / 会場 / △△」を読み取ります。何日分でもまとめてOK。</p>
    </div>
    <div class="card">
        <h3><span class="step">2</span>名簿</h3>
        <p class="muted small">「バンド名 / Vo(Gt.) / Gt.1 / Gt.2 / Ba. / Dr. / Key.」の列を持つ表。バンド名でタイムテーブルと突き合わせ、名前が DB にいるかを色で表示します。</p>
    </div>
    <div class="card">
        <h3><span class="step">3</span>Excel / PDF について</h3>
        <p class="muted small">Excel（.xlsx）はそのままアップロードOK。シートが複数あれば1枚ずつ読み、「バンド名」の見出しが無いシートは飛ばします。古い .xls は .xlsx で保存し直してください。Excel から書き出した「文字を選択できる」PDF に対応。写真やスキャンの PDF は読めないので Excel か CSV にしてください。</p>
    </div>
</section>

<?php else: // ==================== プレビュー画面 ====================

    // ---- 入力欄の候補（datalist）用に、既存のライブ名・会場を取っておく ----
    $liveNames = $pdo->query('SELECT DISTINCT name FROM live ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    // 会場はプルダウンで選ばせる（表記ゆれ防止）。FETCH_KEY_PAIR で [venue_id => name] の形になる
    $venues = $pdo->query('SELECT venue_id, name FROM venue ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

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

    // タイムテーブルのどの枠にも対応していない名簿のバンド（チェックは付けておくが、枠が無いので登録されない）
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

<!-- data-pack: 送信時に JS が全項目を JSON 1個にまとめる（read_form_input() の説明参照） -->
<form method="post" class="import-form" data-pack>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commit">

    <datalist id="dl-live-names"><?php foreach ($liveNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
    <!-- 名簿の検索欄の候補。data-key は JS が「名簿」側の表示を更新するのに使う -->
    <datalist id="dl-roster"><?php foreach ($rosterChoices as $label => $ref): ?><option value="<?= h($label) ?>" data-key="<?= h($ref) ?>"><?php endforeach; ?></datalist>
    <datalist id="dl-labels"><?php foreach (['1日目', '2日目', '3日目', '4日目', '教室ライブ'] as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>

    <h2 class="section-title">① タイムテーブル</h2>

    <?php foreach ($plan['timetables'] as $ti => $tt):
        $f = $form['tt'][$ti] ?? []; // 失敗して戻ってきたときの入力値
        // 「入力値があればそれ、無ければファイルから読んだ値」を返す小さな関数
        $val = static fn(string $k, $default) => $f[$k] ?? $default;

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

        // 同じ日程がもう DB にあるか（あれば上書きの注意を出す）
        $exists = false;
        if ($year !== null) {
            $st = $pdo->prepare('SELECT 1 FROM live l JOIN live_day d ON d.live_id = l.live_id
                WHERE l.fiscal_year = ? AND l.name = ? AND d.label = ?');
            $st->execute([$year, $val('live_name', $tt['live_name']), $val('label', $tt['label'])]);
            $exists = (bool)$st->fetchColumn();
        }
        $bandSlots = array_filter($tt['slots'], static fn($s) => $s['is_band']); ?>
        <section class="card import-day" data-timetable="<?= $ti ?>">
            <header class="import-day__head">
                <div>
                    <p class="file-name">📄 <?= h($tt['file']) ?></p>
                    <h3 class="import-day__title"><?= h($tt['title'] ?: '（タイトルなし）') ?></h3>
                </div>
                <div class="import-day__badges">
                    <span class="pill"><?= count($bandSlots) ?> バンド</span>
                    <span class="pill" data-unmatched-badge></span>
                </div>
            </header>

            <?php if ($exists): ?>
                <div class="flash flash--warn">この日程は登録済みです。「上書き」にチェックすると、今のデータを消して置き換えます。</div>
            <?php endif; ?>

            <!-- name="tt[0][date]" のように書くと、PHP では $_POST['tt'][0]['date'] で受け取れる -->
            <div class="form-grid">
                <label class="field field--wide"><span>ライブ名</span>
                    <input name="tt[<?= $ti ?>][live_name]" value="<?= h($val('live_name', $tt['live_name'])) ?>" list="dl-live-names" maxlength="50" placeholder="例: 文化祭ライブ" required></label>
                <label class="field"><span>日程</span>
                    <input name="tt[<?= $ti ?>][label]" value="<?= h($val('label', $tt['label'])) ?>" list="dl-labels" maxlength="50" required></label>
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
                            <td><button type="button" class="drag-handle" data-drag-handle aria-label="ドラッグで並び替え（↑↓キーでも動く）" title="ドラッグで並び替え">≡</button>
                                <input type="hidden" name="tt[<?= $ti ?>][s][<?= $si ?>][at]" value="<?= (int)$timeSi ?>" data-at></td>
                            <td><input type="checkbox" name="tt[<?= $ti ?>][s][<?= $si ?>][include]" value="1"<?= $include ? ' checked' : '' ?> data-include aria-label="取り込む"></td>
                            <td class="mono nowrap"><span data-order-text><?= $orderNo ?></span>
                                <input type="hidden" name="tt[<?= $ti ?>][s][<?= $si ?>][order]" value="<?= $orderNo ?>" data-order></td>
                            <td class="mono nowrap muted" data-time><?= h($ts['start_time']) ?><?= $ts['end_time'] ? '–' . h($ts['end_time']) : '' ?></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][name]" value="<?= h($fs['name'] ?? $s['band_name']) ?>" maxlength="100" data-band-name aria-label="バンド名"></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][roster]" value="<?= h($roster) ?>" list="dl-roster"
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
        <span class="muted small">セルにマウスを乗せる（スマホはタップ）と理由が出ます。1つのセルには1人。
            名簿で1つのセルに2人以上書かれていたら、2人目からは右端の「Other」列に移してあります（楽器は名前の下のボタンで選ぶ）。
            人が足りないときは「＋ 列を追加」。Vo. 欄の下の「Vo / Gt/Vo / …」で兼任を選べます（「⋯」にマウスを乗せる・タップすると Ba/Vo / Key/Vo / Dr/Vo が出ます）。</span>
    </div>

    <!-- 「＋ 列を追加」で JS が複製するプルダウン（全部の楽器から選べる。初期値はキーボード） -->
    <?php
    // 楽器の切り替えボタン: 「Key」「Vn」だけ見せて、残りは「⋯」の中
    $instShown = array_map('strval', array_filter([default_instrument_id('Key'), default_instrument_id('Vn')]));
    $allInstOptions = instrument_pick_options(instruments());       // 右端の追加列用（全部の楽器）
    $freeInstOptions = instrument_pick_options(extra_instruments()); // Key./Other 列用（Vo / Gt / Ba / Dr 以外）
    ?>
    <!-- 「＋ 列を追加」で JS が複製する楽器の切り替えボタン（name は JS が付け直す。初期値はキーボード） -->
    <template id="tpl-extra-instrument"><?= render_pick('tpl', $allInstOptions, $instShown, (string)default_instrument_id('Key'), 'この人の楽器', true, 'inst') ?></template>

    <?php foreach ($plan['rosters'] as $ri => $roster): ?>
        <section class="card import-day">
            <header class="import-day__head">
                <div>
                    <p class="file-name">📄 <?= h($roster['file']) ?></p>
                    <h3 class="import-day__title">名簿 <?= count($roster['bands']) ?> バンド</h3>
                </div>
                <button type="button" class="btn btn--ghost btn--sm" data-add-roster-col>＋ 列を追加</button>
            </header>
            <div class="table-scroll">
                <!-- data-extra-cols: 今ある追加列の数。JS が列を足すときの番号に使う -->
                <table class="table table--edit table--roster" data-roster-table="<?= $ri ?>" data-extra-cols="<?= $extraCols[$ri] ?>">
                    <thead><tr>
                        <th title="チェックしたバンドだけメンバーを登録">登録</th>
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
                        // 登録のチェック: 初回は全部 ON（タイムテーブルに無いバンドも ON にして、⚠ で知らせる）
                        $rbForm = $form['rb'][$ri][$bi] ?? null; // 失敗して戻ってきたときの入力値
                        $on = $rbForm ? !empty($rbForm['on']) : true;
                        $missing = $on && !isset($usedBy["$ri:$bi"]); ?>
                        <tr class="<?= $on ? '' : 'is-excluded' ?>" data-roster-key="<?= h("$ri:$bi") ?>">
                            <!-- タイムテーブル側の「取込」を外すと、JS がこちらも外す -->
                            <td><input type="checkbox" name="rb[<?= $ri ?>][<?= $bi ?>][on]" value="1"<?= $on ? ' checked' : '' ?> data-roster-on aria-label="このバンドのメンバーを登録する"></td>
                            <td class="strong nowrap"><?= h($band['band_name']) ?>
                                <!-- どの出演枠もこの名簿を選んでいなければ出す（JS がタイムテーブルの変更に合わせて出し入れする） -->
                                <span class="status status--warn" title="タイムテーブルの「名簿ファイル内バンド名」でこのバンドを選ぶと登録されます" data-roster-missing<?= $missing ? '' : ' hidden' ?>>⚠ タイムテーブルにないため登録されません</span>
                            </td>
                            <?php foreach ($roster['columns'] as $col => $c):
                                $k = "$ri-$bi-$col";
                                $stt = $cellStatus[$k] ?? ['status' => '', 'hint' => '']; ?>
                                <td>
                                    <input name="rb[<?= $ri ?>][<?= $bi ?>][c][<?= $col ?>]" value="<?= h($cellTexts[$k]) ?>"
                                           class="name-input<?= $stt['status'] ? ' is-' . h($stt['status']) : '' ?>"
                                           title="<?= h($stt['hint']) ?>" data-name-cell aria-label="メンバー"<?= suggest_attr($stt) ?>>
                                    <?php if ($c['part'] === 'Vo'):
                                        // Vo. 欄: 単体 / ギター / ベースボーカルを切り替える（初期値は名簿の「山田(Gt)」の書き方から）
                                        $vr = vocal_role($form['rb'][$ri][$bi]['vr'][$col] ?? $band['vo_roles'][$col] ?? null); ?>
                                        <!-- よく使う「Vo」「Gt/Vo」だけ見せて、残り（Ba/Vo / Key/Vo / Dr/Vo）は「⋯」の中にしまう -->
                                        <?= render_pick("rb[$ri][$bi][vr][$col]", VOCAL_ROLES, ['vo', 'gt'], $vr, 'ボーカルの形', trim($cellTexts[$k]) === '') ?>
                                    <?php elseif (is_free_part($c['part'])):
                                        // Key./Other の列だけ、人（セル）ごとに楽器を選べる。
                                        // 初期値: 名前の「(Vn)」→ 列の見出し（Cho. など）→ それ以外はキーボード（「その他」は初期値にしない）
                                        $ci = (string)(int)($form['rb'][$ri][$bi]['ci'][$col] ?? $band['cell_insts'][$col] ?? $c['instrument_id'] ?? 0); ?>
                                        <!-- 名前が空なら畳んでおく（入力されたら JS が開く） -->
                                        <?= render_pick("rb[$ri][$bi][ci][$col]", $freeInstOptions, $instShown, $ci, 'この人の楽器', trim($cellTexts[$k]) === '', 'inst') ?>
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
                                           title="<?= h($stt['hint']) ?>" data-name-cell aria-label="メンバー"<?= suggest_attr($stt) ?>>
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
        <!-- 名簿の重複があるあいだは JS が「登録する」を押せなくして、この文を出す -->
        <span class="sticky-actions__note" data-submit-block hidden>⚠ 名簿の重複を直すと登録できます</span>
        <button class="btn btn--primary" type="submit"<?= $plan['timetables'] ? '' : ' disabled' ?> data-submit>登録する</button>
    </div>
</form>
<!-- 「やり直す」は別のフォーム。form="reset-form" 属性でボタンだけ上のフォームの中に置いている -->
<form method="post" id="reset-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"></form>

<?php if ($missingBands): ?>
<!-- タイムテーブルに無い名簿のバンドがあるとき、プレビューを開いた直後に1回だけ出す注意（JS の setupImportPreview が開く） -->
<dialog class="modal" data-roster-missing-dialog aria-labelledby="roster-missing-title">
    <form method="dialog" class="modal__body">
        <h3 id="roster-missing-title" class="modal__title">⚠ タイムテーブルにないため登録されません</h3>
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
            <button type="submit" class="btn btn--primary">追加する</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php endif;
render_footer();
