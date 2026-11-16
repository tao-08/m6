<?php
/**
 * =====================================================================
 *  partials/ical_help.php — 「カレンダー連携のやり方」の説明モーダル（マイスケジュールの ⓘ から開く）
 * =====================================================================
 *  sched_me.php の #ical セクションの直後で require する。見た目・動きは初回チュートリアル（partials/tutorial.php）と同じ部品。
 *  開くのは [data-tutorial-open="ical-help"]、ページ送り・分岐は assets/app.js の setupTutorial。
 *  最初のページで端末（Google / iPhone）を選ぶと、その端末の手順だけを送る（data-tutorial-branch）。画像は使わず、文字とアイコンだけ。
 *  手順の文言は、Google・iCloud 側の画面が変わったら直す。
 * =====================================================================
 */
// 分岐ごとの手順。html は信頼できる固定の文字列だけ（ユーザーの入力は入れない）ので、そのまま出す
$noteTimed = '<p class="muted small">予定のタイトルは本人にのみ表示され、他のユーザーからは「予定あり」と表示されます。</p>';
$branches = [
    'google' => [
        ['title' => 'PC版サイトで開く', 'html' =>
            '<ol>'
            . '<li><a href="https://calendar.google.com" target="_blank" rel="noopener">Google カレンダー</a>をブラウザで開く（アプリでは取得不可）</li>'
            . '<li>スマートフォンの場合は、ブラウザのメニューから「PC版サイト」に切り替え</li>'
            . '</ol>'],
        ['title' => '設定を開く', 'html' =>
            '<ol>'
            . '<li>右上の' . icon('settings', 'icon--sm') . ' →「設定」</li>'
            . '<li>左の「マイカレンダーの設定」で、自分のカレンダーを選択</li>'
            . '</ol>'],
        ['title' => '非公開アドレスをコピー', 'html' =>
            '<ol>'
            . '<li>「カレンダーの統合」の「iCal 形式の非公開アドレス」をコピー' . icon('content_copy', 'icon--sm') . '</li>'
            . '<li>この画面の欄に貼り付け</li>'
            . '</ol>'
            . $noteTimed],
    ],
    'icloud' => [
        ['title' => '公開カレンダーをオン', 'html' =>
            '<ol>'
            . '<li>iPhone の「カレンダー」アプリ → 下の「カレンダー」</li>'
            . '<li>取り込むカレンダーの' . icon('info', 'icon--sm') . ' →「公開カレンダー」をオン</li>'
            . '</ol>'],
        ['title' => 'リンクをコピー', 'html' =>
            '<ol>'
            . '<li>「リンクを共有」→「コピー」</li>'
            . '<li>この画面の欄に貼り付け</li>'
            . '</ol>'
            . $noteTimed],
    ],
];
$sid = 0; // 見出しの id の連番（aria-labelledby 用。ダイアログごとに重ならないよう ical-help- を付ける）
?>
<dialog class="modal tutorial" id="ical-help" data-tutorial-dialog data-tutorial-done="閉じる">
    <div class="tutorial__track" data-tutorial-track>
        <!-- 最初のページ（branch なし）: 端末を選ぶ。選ぶまで「次へ」は出ない -->
        <section class="tutorial__slide" data-tutorial-slide>
            <div class="tutorial__text">
                <h2 class="modal__title" id="ical-help-title-<?= $sid++ ?>">カレンダー連携のやり方</h2>
                <p class="muted">使っているカレンダーを選択</p>
                <div class="tutorial__choose">
                    <button type="button" class="btn" data-tutorial-choose="google"><?= icon('event') ?>Google カレンダー</button>
                    <button type="button" class="btn" data-tutorial-choose="icloud"><?= icon('smartphone') ?>iPhone（iCloud）</button>
                </div>
            </div>
        </section>
        <?php foreach ($branches as $branch => $pages): ?>
            <?php foreach ($pages as $i => $p): ?>
        <section class="tutorial__slide" data-tutorial-slide data-tutorial-branch="<?= h($branch) ?>" hidden>
            <div class="tutorial__text">
                <p class="tutorial__step"><?= $i + 1 ?> / <?= count($pages) ?></p>
                <h2 class="modal__title" id="ical-help-title-<?= $sid++ ?>"><?= h($p['title']) ?></h2>
                <?= $p['html'] ?>
            </div>
        </section>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <div class="tutorial__nav tutorial__nav--plain">
        <div class="tutorial__btns">
            <button type="button" class="btn" data-tutorial-prev hidden><?= icon('chevron_left') ?>戻る</button>
            <button type="button" class="btn btn--primary" data-tutorial-next hidden>次へ<?= icon('chevron_right') ?></button>
        </div>
    </div>
</dialog>
