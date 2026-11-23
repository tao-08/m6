<?php
/**
 * =====================================================================
 *  partials/ical_help.php — 「カレンダーの連携方法」の説明モーダル（マイスケジュールの ⓘ から開く）
 * =====================================================================
 *  sched_me.php の #ical セクションの直後で require する。見た目・動きは初回チュートリアル（partials/tutorial.php）と同じ部品。
 *  開くのは [data-tutorial-open="ical-help"]、ページ送り・分岐は assets/app_tutorial.js の setupTutorial。
 *  最初のページで端末（Google / iPhone / その他）を選ぶと、その端末の手順だけを送る（data-tutorial-branch）。画像は使わず、文字とアイコンだけ。
 *  手順の文言は、Google・iCloud 側の画面が変わったら直す。
 * =====================================================================
 */
// 分岐ごとの手順。html は信頼できる固定の文字列だけ（ユーザーの入力は入れない）ので、そのまま出す
$noteTimed = '<p class="muted small">予定のタイトルは本人にのみ表示され、他のユーザーからは「予定あり」と表示されます。</p>';
$branches = [
    // Google カレンダーは「Google で連携」だけ（iCal の URL での登録はやめた）。連携が使えないサーバーでは、そう出す（sched_me.php が lib/sched_google.php を読み込み済み）
    'google' => [
        ['title' => 'Google で連携', 'html' => sched_google_available()
            ? '<ol>'
              . '<li>「Google で連携」を押す</li>'
              . '<li>Google アカウントを選んで、カレンダーへのアクセスを許可</li>'
              . '</ol>'
              . '<p class="muted small">決定したスタジオ練習も自動でカレンダーに入ります。</p>'
              . $noteTimed
            : '<p class="muted">現在使えません（管理者の設定待ち）</p>'],
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
            . '<li>この画面の欄に貼り付けて「連携」</li>'
            . '</ol>'
            . $noteTimed],
        ['title' => '練習を自動で追加', 'html' =>
            '<ol>'
            . '<li>連携のあと、カレンダーの購読画面が開く</li>'
            . '<li>「購読」を押す</li>'
            . '</ol>'
            . '<p class="muted small">以降、決定したスタジオ練習は自動でカレンダーに入ります。開かないときは「決定したスタジオ練習を追加」の iPhone を押してください。</p>'],
    ],
    // Google・iCloud 以外（TimeTree・Outlook・カレンダー未使用など）。取り込みの URL は Google・iCloud だけ（lib/sched_ical.php）なので、
    //   同期できる人は Google / iPhone へ戻ってもらい、できない人は「画像から読込」（週表示のスクショ）と購読 URL のコピーで済ませる
    'other' => [
        ['title' => 'Google / iPhone と連携できるか確認', 'html' =>
            '<ul>'
            . '<li>カレンダーアプリから Google / iCloud を経由して取り込めることがあります</li>'
            . '<li>カレンダーアプリで Google / iCloud と連携させて、予定をアカウントに保存<br>→ 戻って Google / iPhone の説明を選択</li>'
            . '</ul>'
            . '<p class="muted small">連携できない人は次へ</p>'],
        ['title' => '画像から読込', 'html' =>
            '<ol>'
            . '<li>カレンダーアプリを<strong>週表示</strong>にしてスクリーンショット</li>'
            . '<li>マイスケジュールの「' . icon('add_photo_alternate', 'icon--sm') . '画像から読込」で選択（複数枚可）</li>'
            . '<li>読み取った予定を確認して「追加」</li>'
            . '</ol>'
            . '<p class="muted small">週表示が最も正確に読み取れます。予定が変わったら、もう一度読み込んでください。</p>'],
        ['title' => '練習を自動で追加', 'html' =>
            '<ol>'
            . '<li>「決定したスタジオ練習を追加」の「' . icon('content_copy', 'icon--sm') . 'URL をコピー」</li>'
            . '<li>使っているアプリの「URL でカレンダーを追加」に貼り付け</li>'
            . '</ol>'
            . '<p class="muted small">購読できないアプリは、日程調整の決定画面から 1 件ずつ追加できます。</p>'],
    ],
];
$sid = 0; // 見出しの id の連番（aria-labelledby 用。ダイアログごとに重ならないよう ical-help- を付ける）
?>
<dialog class="modal tutorial" id="ical-help" data-tutorial-dialog data-tutorial-done="閉じる">
    <div class="tutorial__track" data-tutorial-track>
        <!-- 最初のページ（branch なし）: 端末を選ぶ。選ぶまで「次へ」は出ない -->
        <section class="tutorial__slide" data-tutorial-slide>
            <div class="tutorial__text">
                <h2 class="modal__title" id="ical-help-title-<?= $sid++ ?>">カレンダーの連携方法</h2>
                <p class="muted">使っているカレンダーを選択</p>
                <div class="tutorial__choose">
                    <button type="button" class="btn" data-tutorial-choose="google"><?= icon('event') ?>Google カレンダー</button>
                    <button type="button" class="btn" data-tutorial-choose="icloud"><?= icon('smartphone') ?>iPhone（iCloud）</button>
                    <button type="button" class="btn" data-tutorial-choose="other"><?= icon('more_horiz') ?>その他</button>
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
