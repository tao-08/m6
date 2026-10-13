<?php
/**
 * =====================================================================
 *  setting_sheet.php?band=バンドID — セッティングシート（ステージプロット）を作る
 * =====================================================================
 *  会場から渡される PDF に、バンド名・メンバー・セットリスト・セット図を書き込んで、
 *  書き込み済みの PDF をダウンロードできるようにするページ。
 *
 *  役割分担:
 *    このファイル（PHP）            … DB から「初期値」を集めて、画面（フォーム）を出すだけ
 *    lib/setting_sheet.php          … 会場ごとの PDF と座標（型紙）
 *    assets/setting_sheet.js        … セット図のドラッグ編集と、PDF への書き込み（ブラウザの中で行う）
 *    assets/setting_sheet.css       … このページだけの見た目
 *
 *  なぜ PDF をサーバー（PHP）ではなくブラウザ（JS）で作るのか:
 *    ・PHP で PDF に書き込むには FPDI などのライブラリを Composer で入れる必要があり、レンタルサーバーだと手間
 *    ・ブラウザで作ればサーバーは PDF とフォントを配るだけ。サーバーの負担もゼロ
 *    ・pdf-lib（JS のライブラリ）は「既存の PDF に書き足す」のが得意で、よく使われている
 *
 *  ここでは DB に何も保存しない（POST を受けない）ので CSRF 対策のトークンは不要。
 *  入力途中の内容は、ブラウザの localStorage（端末ごとの保存場所）に JS が自動で保存する。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/setting_sheet.php';
require_once __DIR__ . '/lib/repository.php'; // band_setlist_visibility()
require_login(); // ログインしていなければログイン画面へ

$pdo = db();
$bandId = (int)($_GET['band'] ?? 0); // (int) で数字に変換。"abc" などは 0 になるので、SQL に変な値が入らない

// ---- 1. バンド・日程・会場（JOIN で1回の SQL にまとめて取る）----
//   LEFT JOIN: 会場が未登録（venue_id が NULL）の日程でもバンドを取りこぼさない
//   ? はプレースホルダ。値は execute() で別に渡すので SQL インジェクションにならない
$st = $pdo->prepare('SELECT b.band_id, b.name, b.play_order, d.live_id, d.live_day_id, d.label, d.held_on,
        l.name AS live_name, v.name AS venue_name
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    LEFT JOIN venue v ON v.venue_id = d.venue_id
    WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    http_response_code(404); // 「見つからない」をブラウザや検索エンジンに正しく伝える
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">バンドが見つかりません</p><a class="btn" href="./">一覧へ戻る</a></div>';
    render_footer();
    exit;
}

// ---- 2. メンバーとパート（1人が Vo と Gt なら「Vo/Gt」にまとめる）----
//   ORDER BY i.sort_order: 楽器マスタの並び順（Vo → Gt → Ba → Dr → Key …）で並べる
$st = $pdo->prepare('SELECT m.member_id, m.name, i.short_name
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id = ?
    ORDER BY i.sort_order, m.name_kana, m.name');
$st->execute([$bandId]);
$members = []; // member_id => ['part' => 'Vo/Gt', 'name' => '...']
foreach ($st as $r) {
    $id = (int)$r['member_id'];
    if (!isset($members[$id])) {
        // 初めて出てきた人。最初の楽器の順番で並ぶ（連想配列は「入れた順」を覚えている）
        $members[$id] = ['part' => $r['short_name'], 'name' => $r['name']];
    } else {
        $members[$id]['part'] .= '/' . $r['short_name']; // 2つ目以降の楽器は / でつなぐ
    }
}
$members = array_values($members); // キー（member_id）を捨てて 0,1,2… の配列にする（JSON で「配列」になるように）

// ---- 3. セットリスト（曲が登録されていれば初期値にする）----
$st = $pdo->prepare('SELECT title FROM song WHERE band_id = ? ORDER BY track_no');
$st->execute([$bandId]);
// fetchAll(PDO::FETCH_COLUMN): 1列目だけの配列 ['曲A', '曲B', ...] で受け取る
$songTitles = $st->fetchAll(PDO::FETCH_COLUMN);
// 出演まで非公開で、この人には見せられないときは、登録済みの曲を初期値に使わない（曲名がバレるので）
if (band_setlist_visibility($pdo, $bandId)['hidden']) {
    $songTitles = [];
}

// ---- 4. 型紙を選ぶ（会場名から自動。合わなければ1つ目）----
$autoKey = sheet_template_for_venue($band['venue_name']);
$templateKey = $autoKey ?? array_key_first(SHEET_TEMPLATES); // ?? : 左が null なら右を使う（null 合体演算子）

// ---- 5. JS に渡すデータ ----
//   「初期値（DB の内容）」と「型紙」を1つの JSON にまとめて <script type="application/json"> に入れる。
//   JS は JSON.parse() で読むだけ。HTML の属性にバラバラに書くより扱いやすい（業界でもよく使う渡し方）
$initial = [
    'band'    => $band['name'],
    'kana'    => '',
    'date'    => $band['held_on'] ? date('Y/n/j', strtotime($band['held_on'])) : '',
    'order'   => (string)$band['play_order'],
    'members' => $members,
    // 曲名の配列。JS が1曲ずつの入力欄にする
    'songs'   => $songTitles,
];
$sheetData = [
    'bandId'      => $bandId,
    'templateKey' => $templateKey,
    'templates'   => SHEET_TEMPLATES,
    'choiceLabels'=> SHEET_CHOICE_LABELS,
    'initial'     => $initial,
    // プレビュー用の pdf.js（Mozilla。Apache License 2.0）
    'pdfjs'       => [
        'lib'    => 'assets/vendor/pdfjs/pdf.min.js',
        'worker' => 'assets/vendor/pdfjs/pdf.worker.min.js',
        'cmaps'  => 'assets/vendor/pdfjs/cmaps/',
        'fonts'  => 'assets/vendor/pdfjs/standard_fonts/',
    ],
    'fontUrl'     => 'assets/fonts/NotoSansJP-Regular.ttf', // サイトと同じ Noto Sans JP（assets/fonts/README.md）
    // pdf-lib（PDF に書き込む）と fontkit（日本語フォントを読む）。どちらも自分のサーバーに置く（CDN が止まっても動く）
    'vendor'      => ['assets/vendor/pdf-lib.min.js', 'assets/vendor/fontkit-shim.min.js'],
    'dbSongs'     => $songTitles, // DB に登録済みの曲（シートと同じなら「登録しますか？」を聞かない）
    'fileBase'    => ($band['held_on'] ? date('Ymd', strtotime($band['held_on'])) . '_' : '') . $band['name'],
];
/*
 * json_encode のフラグ:
 *   JSON_UNESCAPED_UNICODE : 日本語を あ にせずそのまま出す（読みやすい）
 *   JSON_HEX_TAG           : < > を < > にする。
 *                            曲名に「</script>」と書かれても <script> タグが途中で閉じない（XSS 対策）。
 *                            <script> の中に JSON を埋めるときは必須
 *   JSON_HEX_AMP / APOS / QUOT : & ' " も同様に安全な形にする
 *   JSON_THROW_ON_ERROR    : 失敗したら false を返すのではなく例外にする（見逃さない）
 */
$json = json_encode($sheetData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

$bandUrl = 'band?id=' . $bandId;
render_header('セッティングシート', 'lives');
?>
<!-- このページだけの CSS。<link> は <body> の中に書いてもよい（HTML の仕様で許されている） -->
<link rel="stylesheet" href="assets/setting_sheet.css?v=15">

<nav class="crumbs"><a href="./">ライブ</a><span>/</span><a href="<?= h($bandUrl) ?>"><?= h($band['name']) ?></a></nav>
<h1 class="display display--sm"><?= icon('description') ?> セッティングシート</h1>

<!-- JS へ渡すデータ。type="application/json" はブラウザに「実行しないで」と伝える（ただのデータ置き場になる） -->
<script type="application/json" id="sheet-data"><?= $json ?></script>

<div class="sheet" data-sheet>
    <!-- ===== 会場（型紙） ===== -->
    <section class="card sheet-card">
        <label class="field">会場のシート
            <select data-template>
                <?php foreach (SHEET_TEMPLATES as $key => $tpl): ?>
                    <option value="<?= h($key) ?>"<?= $key === $templateKey ? ' selected' : '' ?>><?= h($tpl['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($band['venue_name'] !== null && $autoKey === null): ?>
            <p class="muted small">会場「<?= h($band['venue_name']) ?>」のシートは未登録なので、仮で上のシートを選んでいます。</p>
        <?php endif; ?>
        <p class="muted small sheet-saved" data-saved-note hidden>
            前回の入力を復元しました。<button type="button" class="linkbtn" data-reset>DB の内容に戻す</button>
        </p>
    </section>

    <!-- ===== 基本情報 ===== -->
    <section class="card sheet-card">
        <h2 class="sheet-card__title">基本</h2>
        <div class="sheet-grid">
            <label class="field sheet-grid__wide">バンド名 <input type="text" data-f="band" maxlength="100"></label>
            <!-- data-field: シートにその欄が無い会場では JS が隠す（例: サークル共通シートには出演日が無い） -->
            <label class="field sheet-grid__wide" data-field="kana">フリガナ <input type="text" data-f="kana" maxlength="100" placeholder="バンド名の読み（カタカナ）"></label>
            <label class="field" data-field="date">出演日 <input type="text" data-f="date" maxlength="20" placeholder="2026/10/12"></label>
            <label class="field" data-field="order">出演順 <input type="text" data-f="order" maxlength="3" inputmode="numeric"></label>
        </div>
    </section>

    <!-- ===== メンバー ===== -->
    <section class="card sheet-card">
        <h2 class="sheet-card__title">メンバー <span class="muted small" data-members-hint></span></h2>
        <!-- 行は JS が作る。会場のシートによって「パートを自由に書く表」か「パートが印刷済みの表」になる -->
        <div class="sheet-members" data-members></div>
    </section>

    <!-- ===== セット図 ===== -->
    <section class="card sheet-card">
        <h2 class="sheet-card__title">セット図 <span class="muted small">ドラッグで移動・タップで選択</span></h2>
        <div class="stage-tools">
            <button type="button" class="btn btn--sm" data-add="mic"><?= icon('mic') ?> マイク</button>
            <button type="button" class="btn btn--sm" data-add="circle"><?= icon('circle') ?> 丸</button>
            <button type="button" class="btn btn--sm" data-add="box"><?= icon('crop_square') ?> 四角</button>
            <button type="button" class="btn btn--sm" data-add="text"><?= icon('title') ?> 文字</button>
            <!-- 描画: オンの間は、セット図を指でなぞると線が描ける。もう一度押すとオフ -->
            <button type="button" class="btn btn--sm" data-draw aria-pressed="false"><?= icon('draw') ?> 描画</button>
            <button type="button" class="btn btn--sm btn--ghost" data-auto title="メンバーのパートから、マイクとアンプを並べる"><?= icon('auto_awesome') ?> 自動配置</button>
            <button type="button" class="btn btn--sm btn--ghost" data-stage-reset title="セット図の部品を全部消す"><?= icon('restart_alt') ?> リセット</button>
        </div>
        <!-- 背景はシートのセット図の枠を切り抜いた画像。上下＝客席の反対側（奥）が上 -->
        <div class="stage" data-stage></div>
        <!-- 1つ戻す・やり直す（セット図の部品だけが対象。アイコンだけなので aria-label で読み上げ用の名前を付ける） -->
        <div class="stage-history">
            <button type="button" class="btn btn--sm btn--ghost" data-undo aria-label="1つ戻す" title="1つ戻す（Ctrl+Z）" disabled><?= icon('undo') ?></button>
            <button type="button" class="btn btn--sm btn--ghost" data-redo aria-label="やり直す" title="やり直す（Ctrl+Y）" disabled><?= icon('redo') ?></button>
        </div>
        <p class="muted small stage-hint" data-stage-hint>下が客席側です。何もないところをタップすると選択が外れます。</p>

        <!-- 選んだ部品の設定（何も選んでいないときは隠す） -->
        <div class="stage-panel" data-panel hidden>
            <div class="stage-panel__row" data-only="box circle" data-part-btns></div>
            <label class="field" data-only="mic circle box text">文字 <textarea rows="1" data-p="label" maxlength="40"></textarea></label>
            <div class="stage-panel__row">
                <label class="field">大きさ <input type="range" min="0.5" max="2.5" step="0.1" data-p="scale"></label>
                <label class="field" data-only="box circle">横長さ <input type="range" min="0.5" max="3" step="0.1" data-p="stretch"></label>
            </div>
            <div class="stage-panel__row">
                <button type="button" class="btn btn--sm" data-act="rotL"><?= icon('rotate_left') ?> 左に回す</button>
                <button type="button" class="btn btn--sm" data-act="rotR"><?= icon('rotate_right') ?> 右に回す</button>
                <button type="button" class="btn btn--sm" data-act="dup"><?= icon('content_copy') ?> 複製</button>
                <button type="button" class="btn btn--sm btn--danger" data-act="del"><?= icon('delete') ?> 削除</button>
            </div>
        </div>
    </section>

    <!-- ===== セットリスト ===== -->
    <section class="card sheet-card">
        <h2 class="sheet-card__title">セットリスト</h2>
        <!-- 追加ボタン。押すとリストの一番下に足される（並び替えは ≡ をドラッグ） -->
        <div class="setlist-tools">
            <button type="button" class="btn btn--sm" data-song-add><?= icon('add') ?> 曲</button>
            <button type="button" class="btn btn--sm" data-mc-add><?= icon('add') ?> MC</button>
        </div>
        <!-- 1曲 = 1行。中身は JS が state.songs から作る -->
        <ol class="setlist" data-songs></ol>
        <p class="muted small">≡ を掴んで上下にドラッグで並び替え。<?= icon('tune') ?> で音響・照明への要望を書けます。</p>
        <p class="small" data-rows-note></p>
    </section>

    <!-- ===== その他の項目（会場のシートごとに違う。中身は JS が型紙の texts / choices から作る）===== -->
    <section class="card sheet-card" data-extras-card>
        <h2 class="sheet-card__title" data-extras-title>その他の項目</h2>
        <div class="sheet-texts" data-texts></div>
        <!-- SE 有/なし などの○囲み -->
        <div class="sheet-choices" data-choices></div>
    </section>

    <!-- ===== 出力（画面の下に貼り付く）=====
         sticky-actions は app.css の共通部品（曲の編集画面の保存ボタンと同じ）。スマホでは下のタブバーの上に出る -->
    <div class="sticky-actions sheet-actions">
        <p class="small sheet-status" data-status role="status"></p>
        <!-- プレビューの開閉。スマホでは PDF ボタンの左、PC では上（assets/setting_sheet.css の .sheet-actions） -->
        <button type="button" class="btn sheet-preview-btn" data-preview-fab aria-expanded="false">
            <?= icon('visibility') ?><span data-fab-text>プレビュー</span>
        </button>
        <button type="button" class="btn btn--primary" data-make><?= icon('picture_as_pdf') ?> PDF を作る</button>
        <button type="button" class="btn" data-share hidden><?= icon('ios_share') ?> 共有</button>
    </div>
</div>

<!-- ===== プレビュー =====
     下に貼り付く出力ボタンの中の「プレビュー」で開閉する -->
<!-- data-preview は app.js の曲の試聴ボタンの目印と同じ名前でぶつかるので data-sheet-preview にしている -->
<div class="sheet-preview" data-sheet-preview hidden>
    <p class="sheet-preview__note small" data-preview-note role="status"></p>
    <canvas aria-label="完成した PDF のプレビュー"></canvas>
</div>

<!-- PDF を作ったあとに「DB に登録しますか？」と聞く（JS が開く）。
     「はい」で下の隠しフォームに曲名を入れて songs_edit.php へ POST する（曲の紐付け・演奏者の確認はそちらの画面で） -->
<dialog class="modal" data-register-dialog aria-labelledby="register-title">
    <form method="dialog" class="modal__body">
        <h3 id="register-title" class="modal__title"><?= icon('queue_music') ?> セットリストを DB に登録しますか？</h3>
        <p class="muted small">次の画面で曲を検索してサブスクに紐づけ、演奏者を確認してから保存します。</p>
        <ol class="modal__list" data-register-list></ol>
        <div class="form-actions">
            <button type="submit" class="btn btn--ghost" value="no">いいえ</button>
            <button type="submit" class="btn btn--primary" value="yes">はい</button>
        </div>
    </form>
</dialog>
<!-- songs_edit.php は POST を受けるページなので、CSRF の合言葉（csrf_field）を付けて送る -->
<form method="post" action="songs_edit?band=<?= $bandId ?>" data-register-form hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="band_id" value="<?= $bandId ?>">
    <input type="hidden" name="from_sheet" value="1">
</form>

<script src="assets/setting_sheet.js?v=25" defer></script>
<?php render_footer();
