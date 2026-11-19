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
$count = intro_count($pdo, ['year' => null, 'live' => null, 'artist' => null]); // 「すべて」で出題できる曲の数

/** タイトル画面の自分の記録（点数と順位）。まだ記録が無ければ「—」。assets/intro.js の mineHtml と同じ形 */
function intro_mine_html(?array $mine): string
{
    if ($mine === null) {
        return '—';
    }
    return number_format($mine['score']) . '<small>' . (int)$mine['rank'] . '位</small>';
}

render_header('イントロクイズ', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/ranking.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/intro.css') ?>">
<h1 class="intro-sr">イントロクイズ</h1>
<div class="intro-layout">
    <div class="intro-app" id="intro-app">
        <!-- 開始前: タイトル画面（ロゴ・自分の記録・開始・範囲） -->
        <div class="intro-screen intro-title card" data-screen="ready">
            <!-- 動くイコライザー（飾り）。--d = 何本目か（動きをずらす） -->
            <div class="intro-eq" aria-hidden="true"><?php for ($i = 0; $i < 9; $i++): ?><span style="--d: <?= $i ?>"></span><?php endfor; ?></div>
            <p class="intro-logo" aria-hidden="true"><small class="intro-logo__sub">(ほぼ)</small>イントロクイズ</p>
            <!-- 自分の記録。ゲームが終わったら assets/intro.js が書き換える -->
            <dl class="intro-mine">
                <div><dt>今月</dt><dd data-mine="month"><?= intro_mine_html($rankingData['month_mine']) ?></dd></div>
                <div><dt>ベスト</dt><dd data-mine="best"><?= intro_mine_html($rankingData['mine']) ?></dd></div>
            </dl>
            <button type="button" class="btn btn--primary intro-big intro-start" data-act="start"<?= $count < INTRO_MIN_SONGS ? ' disabled' : '' ?>><?= icon('play_arrow') ?> 開始</button>
            <!-- 範囲の小窓を開く。押すと小窓が開くだけなので操作ボタン扱い（data-tip は付けない） -->
            <button type="button" class="btn btn--ghost btn--sm intro-scope" data-act="scope" aria-haspopup="dialog">
                <?= icon('tune') ?><span data-scope-label>すべて</span><span class="intro-scope__count"><span data-count><?= number_format($count) ?></span>曲</span>
            </button>
            <!-- 範囲ボタンの下の吹き出し。補足なので role="note"（見た目は assets/intro.css の .intro-hint） -->
            <p class="intro-hint" role="note">セットリストを登録すると、選べる曲が増えるよ</p>
            <p class="intro-error" data-error hidden></p>
            <p class="intro-note muted small" data-note hidden>絞り込み中はランキングに載りません</p>
        </div>

        <!-- 範囲（年度・ライブ・アーティスト）の小窓 -->
        <dialog class="modal intro-modal" id="intro-scope" aria-labelledby="intro-scope-title">
            <div class="modal__body">
                <div class="intro-modal__head">
                    <h2 class="modal__title" id="intro-scope-title">範囲</h2>
                    <button type="button" class="icon-btn" data-act="scope-close" aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
                </div>
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
                <p class="intro-note muted small" data-note hidden>絞り込み中はランキングに載りません</p>
                <div class="form-actions">
                    <button type="button" class="btn btn--ghost btn--sm" data-act="scope-reset"><?= icon('restart_alt') ?> すべてに戻す</button>
                    <button type="button" class="btn btn--primary btn--sm" data-act="scope-close">決定</button>
                </div>
            </div>
        </dialog>

        <!-- プレイ中: 問番号・合計点・残り時間・選択肢 -->
        <div class="intro-screen card" data-screen="play" hidden>
            <div class="intro-stats">
                <span class="intro-stat"><b data-stat="n">1</b>/<span data-stat="total">10</span></span>
                <span class="intro-stat intro-stat--score"><b data-stat="score">0</b></span>
            </div>
            <div class="intro-timer" role="progressbar" aria-label="残り時間"><span class="intro-timer__bar" data-timer></span></div>
            <!-- 問の前の Ready。赤いリングが 1 秒で一周し、音源がまだ読めなければ回り続ける（assets/intro.js の present） -->
            <div class="intro-ready" data-loading role="status" aria-label="読み込み中">
                <svg class="intro-ready__ring" viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="intro-ready__track" cx="60" cy="60" r="54"/>
                    <circle class="intro-ready__arc" cx="60" cy="60" r="54" pathLength="100"/>
                </svg>
                <span class="intro-ready__text">Ready</span>
            </div>
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
