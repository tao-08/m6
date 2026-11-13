<?php
/**
 * =====================================================================
 *  block.php — ブロックパズル（tools.php から入る落ちものパズル）
 * =====================================================================
 *  ゲーム本体は assets/block.js（canvas に描く）。ここでは枠・ボタンとランキングを出す。
 *  ランキングは部員ごとの自己ベスト（block_best）。上位 10 人 + 自分の順位。
 *  点数は ゲームオーバー時に api_block_score.php へ POST される（CSRF は <meta name="csrf-token"> を JS が送る）。
 *  この画面自体は表示するだけ（POST を受けない）。名前は h() でエスケープする。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/block.php';
$user = require_login();

[$ranking, $mine] = block_ranking(db(), (int)$user['user_id']);

render_header('ブロックパズル', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/block.css') ?>">
<h1 class="block-sr">ブロックパズル</h1>
<div class="block-layout">
    <!-- data-best = 自己ベストの点数（結果の「自己ベスト更新」の比較用。0 = まだ記録なし） -->
    <div class="block-app" id="block-app" data-best="<?= $mine ? (int)$mine['score'] : 0 ?>">
        <div class="block-stats">
            <div class="block-stat"><span class="block-stat__k">SCORE</span><b class="block-stat__v" data-stat="score">0</b></div>
            <div class="block-stat"><span class="block-stat__k">LEVEL</span><b class="block-stat__v" data-stat="level">1</b></div>
            <div class="block-stat"><span class="block-stat__k">LINES</span><b class="block-stat__v" data-stat="lines">0</b></div>
            <button type="button" class="icon-btn block-gear" id="block-gear" aria-label="操作設定" title="操作設定"><?= icon('settings') ?></button>
        </div>

        <!-- 盤面エリア: ここでスマホのジェスチャーを受ける（touch-action: none） -->
        <div class="block-play" id="block-play">
            <div class="block-side block-side--hold">
                <span class="block-label">HOLD</span>
                <canvas id="block-hold" aria-label="HOLD"></canvas>
                <div class="block-flags">
                    <span class="tag tag--plan" data-flag="b2b" hidden>B2B</span>
                    <span class="tag" data-flag="ren" hidden></span>
                </div>
            </div>

            <div class="block-board-wrap">
                <canvas id="block-board" aria-label="盤面" role="img"></canvas>
                <div class="block-toast" id="block-toast" aria-live="polite"></div>

                <!-- 開始前 / 一時停止 / ゲームオーバーの表示（JS が hidden を切り替える） -->
                <div class="block-overlay" data-screen="ready">
                    <button type="button" class="btn btn--primary block-big" data-act="start"><?= icon('play_arrow') ?> 開始</button>
                </div>
                <div class="block-overlay" data-screen="paused" hidden>
                    <p class="block-overlay__title">一時停止</p>
                    <button type="button" class="btn btn--primary block-big" data-act="resume"><?= icon('play_arrow') ?> 再開</button>
                </div>
                <div class="block-overlay" data-screen="over" hidden>
                    <p class="block-overlay__title">終了</p>
                    <p class="block-result__score" data-result="score">0</p>
                    <p class="block-result__best" data-result="best" hidden>自己ベスト更新</p>
                    <p class="block-result__rank" data-result="rank"></p>
                    <p class="block-result__err" data-result="err" hidden></p>
                    <button type="button" class="btn block-big" data-act="retry" hidden><?= icon('restart_alt') ?> 再送信</button>
                    <button type="button" class="btn btn--primary block-big" data-act="restart"><?= icon('restart_alt') ?> 再開始</button>
                </div>
            </div>

            <div class="block-side block-side--next">
                <span class="block-label">NEXT</span>
                <canvas id="block-next" aria-label="NEXT"></canvas>
            </div>
        </div>

        <!-- 操作ボタン（スマホ用に大きめ）。押した瞬間に反応させるため JS は pointerdown で受ける -->
        <div class="block-controls">
            <button type="button" class="block-btn" data-slot="0" aria-label="左回転" title="左回転 (Z)"><?= icon('rotate_left') ?></button>
            <button type="button" class="block-btn block-btn--text" data-slot="1" aria-label="HOLD" title="HOLD (C)">HOLD</button>
            <button type="button" class="block-btn" data-slot="2" aria-label="一時停止" title="一時停止 (Esc)"><?= icon('pause') ?></button>
            <button type="button" class="block-btn" data-slot="3" aria-label="右回転" title="右回転 (↑)"><?= icon('rotate_right') ?></button>
        </div>
    </div>

    <section class="block-ranking card" id="block-ranking" aria-label="ランキング">
        <h2 class="block-ranking__title">ランキング</h2>
        <ol class="block-ranking__list" data-rank-list>
            <?php foreach ($ranking as $r): ?>
                <li class="block-ranking__row<?= $r['me'] ? ' is-me' : '' ?>">
                    <span class="block-ranking__rank"><?= (int)$r['rank'] ?></span>
                    <span class="block-ranking__name"><?= h($r['name']) ?></span>
                    <span class="block-ranking__score"><?= number_format($r['score']) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="block-ranking__empty muted small" data-rank-empty<?= $ranking ? ' hidden' : '' ?>>記録がありません</p>
        <!-- 自分が上位 10 人の外のときだけ出す自分の順位 -->
        <?php $inTop = (bool)array_filter($ranking, fn($r) => $r['me']); ?>
        <p class="block-ranking__mine" data-rank-mine<?= ($mine && !$inTop) ? '' : ' hidden' ?>>
            <?php if ($mine && !$inTop): ?>
                <span class="block-ranking__rank"><?= (int)$mine['rank'] ?></span>
                <span class="block-ranking__name">自己ベスト</span>
                <span class="block-ranking__score"><?= number_format($mine['score']) ?></span>
            <?php endif; ?>
        </p>
    </section>
</div>
<?php
// 操作設定（assets/block.js の setupSettings が開け閉めと保存をする）。選択肢の値は JS 側の許可リストと同じ
$gestureOpts = ['cw' => '右回転', 'ccw' => '左回転', 'hard' => 'ハードドロップ', 'hold' => 'HOLD', 'none' => 'なし'];
$buttonOpts = ['ccw' => '左回転', 'cw' => '右回転', 'hold' => 'HOLD', 'hard' => 'ハードドロップ', 'pause' => '一時停止', 'none' => 'なし'];
$gestureRows = ['tapL' => '左半分タップ', 'tapR' => '右半分タップ', 'flickDown' => '下フリック', 'flickUp' => '上フリック'];
?>
<dialog class="modal block-set" id="block-settings" aria-labelledby="block-set-title">
    <div class="modal__body">
        <div class="block-set__head">
            <h2 class="modal__title" id="block-set-title">操作設定</h2>
            <button type="button" class="icon-btn" data-set="close" aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
        </div>

        <h3 class="block-set__title">ジェスチャー</h3>
        <?php foreach ($gestureRows as $key => $label): ?>
            <label class="field block-set__row"><span><?= h($label) ?></span>
                <select data-set-gesture="<?= h($key) ?>">
                    <?php foreach ($gestureOpts as $v => $t): ?><option value="<?= h($v) ?>"><?= h($t) ?></option><?php endforeach; ?>
                </select>
            </label>
        <?php endforeach; ?>

        <h3 class="block-set__title">感度</h3>
        <label class="field block-set__row"><span>横移動</span>
            <input type="range" min="0.4" max="1.5" step="0.05" data-set-range="move" aria-label="横移動の感度">
        </label>
        <label class="field block-set__row"><span>フリック</span>
            <input type="range" min="0.15" max="0.8" step="0.05" data-set-range="flick" aria-label="フリックの感度">
        </label>

        <h3 class="block-set__title">ボタン</h3>
        <?php for ($i = 0; $i < 4; $i++): ?>
            <label class="field block-set__row"><span><?= $i + 1 ?></span>
                <select data-set-button="<?= $i ?>" aria-label="ボタン<?= $i + 1 ?>">
                    <?php foreach ($buttonOpts as $v => $t): ?><option value="<?= h($v) ?>"><?= h($t) ?></option><?php endforeach; ?>
                </select>
            </label>
        <?php endfor; ?>

        <div class="form-actions">
            <button type="button" class="btn btn--ghost btn--sm" data-set="reset"><?= icon('restart_alt') ?> 初期値に復元</button>
        </div>
    </div>
</dialog>
<script src="<?= asset_url('assets/block.js') ?>" defer></script>
<?php render_footer();
