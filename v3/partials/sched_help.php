<?php
/**
 * =====================================================================
 *  partials/sched_help.php — 「マイスケジュールの使い方」の説明モーダル（見出しの右の ⓘ から開く）
 * =====================================================================
 *  sched_me.php の最後で require する。見た目・動きは初回チュートリアル（partials/tutorial.php）と同じ部品（assets/app.js の setupTutorial）。
 *  自動では開かない（ⓘ を押したときだけ）。
 *  画像は assets/tutorial/sched_*.jpg（スマホの画面の切り抜き。縦長 742×1000 = 371×500 の2倍）。同じ名前のまま差し替えたら ?v= の数字を上げる。
 *  縦長なので .tutorial--tall（assets/sched.css）で、画像の枠を横長に固定せず、画面の高さに収まる大きさで出す。
 * =====================================================================
 */
$schedHelp = [
    ['img' => 'sched_paint.jpg', 'title' => 'なぞって予定を入力',
     'text'  => '日付をタップするとその日のすべての時間帯が予定ありになります。'],
    ['img' => 'sched_ignore.jpg', 'title' => '予定をタップして無視',
     'text'  => '自動で入った予定をタップして「この予定を無視」にすると、その時間もスタジオ練習の候補になります。'],
    ['img' => 'sched_calendar.jpg', 'title' => 'カレンダー連携',
     'text'  => 'Google カレンダーや iPhone のカレンダーと連携すると、予定が自動で入ります。'],
    ['img' => 'sched_station.jpg', 'title' => '時間割と最寄り駅',
     'text'  => '時間割・キャンパス・最寄り駅を設定すると、スタジオを選ぶときの所要時間と運賃に反映されます。'],
];
?>
<dialog class="modal tutorial tutorial--tall" id="sched-help" data-tutorial-dialog data-tutorial-done="閉じる" aria-labelledby="sched-help-title-0">
    <div class="tutorial__track" data-tutorial-track>
        <?php foreach ($schedHelp as $i => $s): ?>
        <section class="tutorial__slide" data-tutorial-slide <?= $i === 0 ? '' : 'hidden' ?>>
            <div class="tutorial__img">
                <img src="assets/tutorial/<?= h($s['img']) ?>?v=2" alt="" loading="lazy">
            </div>
            <div class="tutorial__text">
                <p class="tutorial__step"><?= $i + 1 ?> / <?= count($schedHelp) ?></p>
                <h2 class="modal__title" id="sched-help-title-<?= $i ?>"><?= h($s['title']) ?></h2>
                <p class="muted"><?= h($s['text']) ?></p>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <div class="tutorial__nav">
        <button type="button" class="btn btn--ghost" data-tutorial-skip>閉じる</button>
        <div class="tutorial__dots" role="tablist" aria-label="ページ">
            <?php foreach ($schedHelp as $i => $s): ?>
            <button type="button" class="tutorial__dot" data-tutorial-dot="<?= $i ?>" aria-label="<?= $i + 1 ?>ページ目"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
            <?php endforeach; ?>
        </div>
        <div class="tutorial__btns">
            <button type="button" class="btn" data-tutorial-prev hidden><?= icon('chevron_left') ?>戻る</button>
            <button type="button" class="btn btn--primary" data-tutorial-next>次へ<?= icon('chevron_right') ?></button>
        </div>
    </div>
</dialog>
