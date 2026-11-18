<?php
/**
 * =====================================================================
 *  intro.php — イントロクイズ（tools.php から入る）
 * =====================================================================
 *  サークルのライブで演奏された曲の試聴（30 秒）を聴いて、4 択で曲名を当てる。10 問・1 問 10 秒・速いほど高得点（1 問 100〜1000 点）。
 *  出題・採点は api_intro.php（正解はブラウザに渡さない）、画面の動きは assets/intro.js。ここでは枠・絞り込み・ランキングを出す。
 *  絞り込み（年度・ライブ・アーティスト）をしたときはランキングに載らない。ライブの選択肢は JS が年度に合わせて絞る。
 *  ランキング（月間・ベストスコア）は partials/ranking_card.php（ブロックパズルと共通）。
 *  この画面自体は表示するだけ（POST を受けない）。名前は h() でエスケープする。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/intro.php';
$user = require_login();

$pdo = db();
$rankingData = intro_rankings($pdo, (int)$user['user_id']);
[$lives, $artists] = intro_filter_options($pdo);
$years = array_values(array_unique(array_map(static fn($l) => (int)$l['fiscal_year'], $lives))); // 新しい年度から

render_header('イントロクイズ', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/ranking.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/intro.css') ?>">
<h1 class="intro-sr">イントロクイズ</h1>
<div class="intro-layout">
    <div class="intro-app" id="intro-app">
        <!-- 開始前: 絞り込みと開始ボタン -->
        <div class="intro-screen card" data-screen="ready">
            <div class="intro-filters">
                <label class="field"><span>年度</span>
                    <select data-filter="year">
                        <option value="">すべて</option>
                        <?php foreach ($years as $y): ?><option value="<?= $y ?>"><?= $y ?></option><?php endforeach; ?>
                    </select>
                </label>
                <!-- data-year = そのライブの年度。assets/intro.js が年度の選択に合わせて絞る -->
                <label class="field"><span>ライブ</span>
                    <select data-filter="live">
                        <option value="">すべて</option>
                        <?php foreach ($lives as $l): ?><option value="<?= (int)$l['live_id'] ?>" data-year="<?= (int)$l['fiscal_year'] ?>"><?= h($l['fiscal_year'] . ' ' . $l['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span>アーティスト</span>
                    <select data-filter="artist">
                        <option value="">すべて</option>
                        <?php foreach ($artists as $a): ?><option value="<?= (int)$a['artist_id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="intro-error" data-error hidden></p>
            <button type="button" class="btn btn--primary intro-big" data-act="start"><?= icon('play_arrow') ?> 開始</button>
            <p class="intro-note muted small" data-note hidden>絞り込み中はランキングに載りません</p>
        </div>

        <!-- プレイ中: 問番号・合計点・残り時間・選択肢 -->
        <div class="intro-screen card" data-screen="play" hidden>
            <div class="intro-stats">
                <span class="intro-stat"><b data-stat="n">1</b>/<span data-stat="total">10</span></span>
                <span class="intro-stat intro-stat--score"><b data-stat="score">0</b></span>
            </div>
            <div class="intro-timer" role="progressbar" aria-label="残り時間"><span class="intro-timer__bar" data-timer></span></div>
            <p class="intro-loading" data-loading aria-label="読み込み中"><?= icon('headphones') ?></p>
            <div class="intro-choices" data-choices hidden></div>
            <p class="intro-error" data-error hidden></p>
            <button type="button" class="btn intro-big" data-act="retry" hidden><?= icon('restart_alt') ?> 再送信</button>

            <!-- 答え合わせ -->
            <div class="intro-answer" data-answer hidden>
                <p class="intro-answer__verdict" data-verdict><span data-verdict-icon></span><b data-points></b></p>
                <div class="intro-track">
                    <img class="intro-track__art" data-art alt="" width="96" height="96">
                    <div class="intro-track__text">
                        <p class="intro-track__title" data-title></p>
                        <p class="intro-track__artist muted" data-artist></p>
                    </div>
                </div>
                <ul class="intro-bands" data-bands></ul>
                <button type="button" class="btn btn--primary intro-big" data-act="next">次へ <?= icon('arrow_forward') ?></button>
            </div>
        </div>

        <!-- 終了 -->
        <div class="intro-screen card" data-screen="over" hidden>
            <p class="intro-result__score" data-result="score">0</p>
            <p class="intro-result__best" data-result="best" hidden></p>
            <p class="intro-result__rank" data-result="rank"></p>
            <button type="button" class="btn btn--primary intro-big" data-act="again"><?= icon('restart_alt') ?> もう一度</button>
        </div>
    </div>

    <?php $rankingId = 'intro-ranking'; require __DIR__ . '/partials/ranking_card.php'; ?>
</div>
<script src="<?= asset_url('assets/ranking.js') ?>" defer></script>
<script src="<?= asset_url('assets/intro.js') ?>" defer></script>
<?php render_footer();
