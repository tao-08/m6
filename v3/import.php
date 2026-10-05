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
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'pdf'], true)) {
                flash("{$name}: CSV か PDF を選んでください", 'error');
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
        <input type="file" name="files[]" accept=".csv,.pdf,text/csv,application/pdf" multiple required data-file-input>
        <span class="dropzone__icon" aria-hidden="true">↑</span>
        <span class="dropzone__title">ここにファイルをドロップ</span>
        <span class="muted small">またはクリックして選択 · CSV / PDF · 最大<?= MAX_FILES ?>ファイル</span>
        <ul class="dropzone__list" data-file-list></ul>
    </label>
    <button class="btn btn--primary btn--block" type="submit">読み込んでプレビュー</button>
</form>

<section class="guide">
    <div class="card">
        <h3><span class="step">1</span>タイムテーブル</h3>
        <p class="muted small">1ファイル = ライブ1日分。「時間 / 持ち時間 / バンド名 / 曲数 / 人数 / key」の列と、表の上の「○○ライブ2日目 / 会場 / △△」を読み取ります。何日分でもまとめてOK。</p>
    </div>
    <div class="card">
        <h3><span class="step">2</span>名簿</h3>
        <p class="muted small">「バンド名 / Vo(Gt.) / Gt.1 / Gt.2 / Ba. / Dr. / Key.」の列を持つ表。バンド名でタイムテーブルと突き合わせ、名前が DB にいるかを色で表示します。</p>
    </div>
    <div class="card">
        <h3><span class="step">3</span>PDF について</h3>
        <p class="muted small">Excel から書き出した「文字を選択できる」PDF に対応。写真やスキャンの PDF は読めないので CSV にしてください。</p>
    </div>
</section>

<?php else: // ==================== プレビュー画面 ====================

    // ---- 入力欄の候補（datalist）用に、既存のライブ名・会場を取っておく ----
    $liveNames = $pdo->query('SELECT DISTINCT name FROM live ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    // 会場はプルダウンで選ばせる（表記ゆれ防止）。FETCH_KEY_PAIR で [venue_id => name] の形になる
    $venues = $pdo->query('SELECT venue_id, name FROM venue ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

    // ---- 名簿の全セルを1回でまとめて色分け判定（1セルずつ SQL を投げると遅いので） ----
    $cellTexts = [];
    foreach ($plan['rosters'] as $ri => $roster) {
        foreach ($roster['bands'] as $bi => $band) {
            foreach ($roster['columns'] as $col => $_) {
                $cellTexts["$ri-$bi-$col"] = (string)($form['rb'][$ri][$bi]['c'][$col] ?? $band['cells'][$col] ?? '');
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
                <label class="field"><span>集合</span>
                    <input type="time" name="tt[<?= $ti ?>][meeting_time]" value="<?= h($val('meeting_time', $tt['meeting_time'] ?? '')) ?>"></label>
            </div>
            <div class="checks">
                <label class="check"><input type="checkbox" name="tt[<?= $ti ?>][overwrite]" value="1"<?= !empty($f['overwrite']) ? ' checked' : '' ?>> 登録済みなら上書き</label>
                <label class="check"><input type="checkbox" name="tt[<?= $ti ?>][skip]" value="1"<?= !empty($f['skip']) ? ' checked' : '' ?>> この日程は取り込まない</label>
            </div>

            <div class="table-scroll">
                <table class="table table--edit">
                    <thead><tr>
                        <th title="チェックした行だけ登録">取込</th><th>順</th><th>時間</th><th>バンド名</th><th>曲数</th>
                        <th title="名簿ファイルのバンド名で検索して選ぶ">名簿</th><th title="バンド全体の補足事項">メモ</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tt['slots'] as $si => $s):
                        $fs = $f['s'][$si] ?? null;               // 失敗して戻ってきたときの入力値
                        $include = $fs ? !empty($fs['include']) : $s['include'];
                        $key = "$ti:$si";
                        $roster = $slotRoster[$key];
                        $users = isset($rosterChoices[$roster]) ? ($usedBy[$rosterChoices[$roster]] ?? []) : [];
                        // 緑 = 名簿あり / 黄 = 同じ名簿を他の枠でも選んでいる / 赤 = 名簿なし
                        $rosterClass = !isset($rosterChoices[$roster]) ? ($include ? 'is-new' : '')
                            : ($include && count($users) > 1 ? 'is-similar' : 'is-ok');
                        $rosterHint = $rosterClass === 'is-similar' ? '同じ名簿を ' . count($users) . ' つの枠で選んでいます: ' . implode(' / ', $users) : ''; ?>
                        <tr class="<?= $include ? '' : 'is-excluded' ?>" data-slot="<?= h($key) ?>">
                            <td><input type="checkbox" name="tt[<?= $ti ?>][s][<?= $si ?>][include]" value="1"<?= $include ? ' checked' : '' ?> data-include aria-label="取り込む"></td>
                            <td><input type="number" class="input-num" name="tt[<?= $ti ?>][s][<?= $si ?>][order]" value="<?= h($fs['order'] ?? $s['order'] ?? '') ?>" min="1" aria-label="出演順"></td>
                            <td class="mono nowrap muted"><?= h($s['start_time']) ?><?= $s['end_time'] ? '–' . h($s['end_time']) : '' ?></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][name]" value="<?= h($fs['name'] ?? $s['band_name']) ?>" maxlength="100" data-band-name aria-label="バンド名"></td>
                            <td><input type="number" class="input-num" name="tt[<?= $ti ?>][s][<?= $si ?>][songs]" value="<?= h($fs['songs'] ?? $s['song_count'] ?? '') ?>" min="0" aria-label="曲数"></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][roster]" value="<?= h($roster) ?>" list="dl-roster"
                                       class="name-input <?= $rosterClass ?>" title="<?= h($rosterHint) ?>" placeholder="名簿から検索" data-roster-input aria-label="名簿のバンド"></td>
                            <td><input name="tt[<?= $ti ?>][s][<?= $si ?>][note]" value="<?= h($fs['note'] ?? '') ?>" maxlength="255" placeholder="補足事項" aria-label="メモ"></td>
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
        <span class="muted small">セルにマウスを乗せる（スマホはタップ）と理由が出ます。1つのセルに2人なら「、」で区切る。
            Key/その他の列は下のセレクトで楽器（キーボード・ヴァイオリン・サックス…）を選べます。1人ずつ変えたいときは「丸野友多郎(Sax)」のように名前の後ろに書く。</span>
    </div>

    <?php foreach ($plan['rosters'] as $ri => $roster): ?>
        <section class="card import-day">
            <header class="import-day__head">
                <div>
                    <p class="file-name">📄 <?= h($roster['file']) ?></p>
                    <h3 class="import-day__title">名簿 <?= count($roster['bands']) ?> バンド</h3>
                </div>
            </header>
            <div class="table-scroll">
                <table class="table table--edit table--roster">
                    <thead><tr>
                        <th>使っている出演枠</th>
                        <th>名簿のバンド名</th>
                        <?php foreach ($roster['columns'] as $col => $c):
                            $selected = (int)($form['inst'][$ri][$col] ?? $c['instrument_id'] ?? 0); ?>
                            <th>
                                <div class="col-head"><?= h($c['title']) ?></div>
                                <!-- この列の人を何の楽器として登録するか（instrument テーブルから選ぶ） -->
                                <select name="inst[<?= $ri ?>][<?= $col ?>]" class="select-sm" aria-label="<?= h($c['title']) ?> の楽器">
                                    <?php foreach (instruments() as $ins): ?>
                                        <option value="<?= (int)$ins['instrument_id'] ?>"<?= $selected === (int)$ins['instrument_id'] ? ' selected' : '' ?>><?= h($ins['short_name']) ?> <?= h($ins['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($roster['bands'] as $bi => $band): ?>
                        <tr data-roster-key="<?= h("$ri:$bi") ?>">
                            <!-- どの出演枠がこの名簿を使っているか。タイムテーブルの「名簿」欄で選ぶと JS が書き換える -->
                            <td class="nowrap" data-roster-used>
                                <?php if (count($usedBy["$ri:$bi"] ?? []) > 1): ?>
                                    <span class="status status--warn">⚠ <?= count($usedBy["$ri:$bi"]) ?>枠で重複: <?= h(implode(' / ', $usedBy["$ri:$bi"])) ?></span>
                                <?php elseif (isset($usedBy["$ri:$bi"])): ?>
                                    <span class="status status--ok">✓ <?= h(implode(' / ', $usedBy["$ri:$bi"])) ?></span>
                                <?php else: ?>
                                    <span class="status status--new">未使用</span>
                                <?php endif; ?>
                            </td>
                            <td class="strong nowrap"><?= h($band['band_name']) ?></td>
                            <?php foreach ($roster['columns'] as $col => $c):
                                $k = "$ri-$bi-$col";
                                $stt = $cellStatus[$k] ?? ['status' => '', 'hint' => '']; ?>
                                <td>
                                    <input name="rb[<?= $ri ?>][<?= $bi ?>][c][<?= $col ?>]" value="<?= h($cellTexts[$k]) ?>"
                                           class="name-input<?= $stt['status'] ? ' is-' . h($stt['status']) : '' ?>"
                                           title="<?= h($stt['hint']) ?>" data-name-cell aria-label="メンバー">
                                    <?php if (in_array($c['part'], ['Key', 'Other'], true)):
                                        // Key/その他の列だけ、人（セル）ごとに楽器を選べる。初期値は「列の楽器」
                                        $ci = (int)($form['rb'][$ri][$bi]['ci'][$col] ?? 0); ?>
                                        <select name="rb[<?= $ri ?>][<?= $bi ?>][ci][<?= $col ?>]" class="select-sm cell-instrument" aria-label="この人の楽器">
                                            <option value="">列の楽器</option>
                                            <?php foreach (extra_instruments() as $ins): ?>
                                                <option value="<?= (int)$ins['instrument_id'] ?>"<?= $ci === (int)$ins['instrument_id'] ? ' selected' : '' ?>><?= h($ins['short_name']) ?> <?= h($ins['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
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
        <button class="btn btn--primary" type="submit"<?= $plan['timetables'] ? '' : ' disabled' ?>>登録する</button>
    </div>
</form>
<!-- 「やり直す」は別のフォーム。form="reset-form" 属性でボタンだけ上のフォームの中に置いている -->
<form method="post" id="reset-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"></form>
<?php endif;
render_footer();
