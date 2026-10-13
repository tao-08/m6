<?php
/**
 * =====================================================================
 *  partials/tutorial.php — 初回ログインのチュートリアル（数ページのスライド）
 * =====================================================================
 *  partials/footer.php がログイン中の全ページで require する（$tutorialAuto = まだ見ていない → 自動で開く）。
 *  ページ送り・閉じたときの記録は assets/app.js の setupTutorial。
 *  画像は assets/tutorial/ に置く。ファイル名は中身の名前（setlist.jpg など）にしておくと、並べ替えてもリネームしなくて済む。
 *  同じ名前のまま画像を差し替えたら ?v= の数字を上げる（ブラウザが古い画像を覚えているので）。
 * =====================================================================
 */
$slides = [
    ['img' => 'lives.jpg', 'title' => 'AbbeyRoad.online へようこそ',
     'text'  => 'アビーロードのライブの記録を、みんなで残していくサイトです。トップページには年度別でライブ一覧が表示されます。'],
    ['img' => 'timetable.jpg', 'title' => 'ライブ・バンドを見る',
     'text'  => 'ライブ詳細を開くとタイムテーブル、バンドを開くとメンバーやセットリストが見られます。'],
    ['img' => 'setlist.jpg', 'title' => 'バンドページでセットリストを登録',
     'text'  => 'バンドページ右上の「曲を編集」から、演奏した曲を登録できます。曲ごとに、コーラス・楽器の持ち替え・出演の有無が記録できます。'],
    ['img' => 'member.jpg', 'title' => 'メンバーとプロフィール',
     'text'  => 'メンバーのページでは、その人が出たバンドや演奏した楽器が確認できます。マイアルバムを登録すると自分が好きなアルバムをみんなに紹介できます。'],
    ['img' => 'stats.jpg', 'title' => '集計で振り返る',
     'text'  => '「集計」では、よくコピーされるアーティストや出演回数などのランキングが見られます。'],
];
$last = count($slides) - 1;
?>
<dialog class="modal tutorial" data-tutorial-dialog<?= $tutorialAuto ? ' data-tutorial-auto' : '' ?> aria-labelledby="tutorial-title-0">
    <div class="tutorial__track" data-tutorial-track>
        <?php foreach ($slides as $i => $s): ?>
        <section class="tutorial__slide" data-tutorial-slide <?= $i === 0 ? '' : 'hidden' ?>>
            <div class="tutorial__img">
                <img src="assets/tutorial/<?= h($s['img']) ?>?v=2" alt="" loading="lazy"><!-- 開くまで読み込まない（全ページに置いているので）。開いたら JS が全部読み込ませる -->
            </div>
            <div class="tutorial__text">
                <p class="tutorial__step"><?= $i + 1 ?> / <?= $last + 1 ?></p>
                <h2 class="modal__title" id="tutorial-title-<?= $i ?>"><?= h($s['title']) ?></h2>
                <p class="muted"><?= h($s['text']) ?></p>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <div class="tutorial__nav">
        <button type="button" class="btn btn--ghost" data-tutorial-skip>スキップ</button>
        <div class="tutorial__dots" role="tablist" aria-label="ページ">
            <?php foreach ($slides as $i => $s): ?>
            <button type="button" class="tutorial__dot" data-tutorial-dot="<?= $i ?>" aria-label="<?= $i + 1 ?>ページ目"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
            <?php endforeach; ?>
        </div>
        <div class="tutorial__btns">
            <button type="button" class="btn" data-tutorial-prev hidden><?= icon('chevron_left') ?>戻る</button>
            <button type="button" class="btn btn--primary" data-tutorial-next>次へ<?= icon('chevron_right') ?></button>
        </div>
    </div>
</dialog>
