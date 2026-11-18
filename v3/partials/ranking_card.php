<?php
/**
 * =====================================================================
 *  partials/ranking_card.php — ランキングのカード（ブロックパズル・イントロクイズ共通）
 * =====================================================================
 *  「月間」「ベストスコア」のタブと、それぞれの一覧。切り替え・畳む・描き直しは assets/ranking.js、見た目は assets/ranking.css。
 *  読み込む前に用意する変数:
 *    $rankingId   … カードの id
 *    $rankingData … lib/ranking.php の ranking_payload の戻り値
 *  先月の1位は月間の下に小さく出す。名前は h() でエスケープする。
 * =====================================================================
 */
$rankingPanels = ['month' => ['月間', $rankingData['month_ranking']], 'best' => ['ベストスコア', $rankingData['ranking']]];
?>
<section class="game-ranking card" id="<?= h($rankingId) ?>" aria-label="ランキング" data-ranking>
    <div class="tabs tabs--static game-ranking__tabs" role="tablist">
        <?php foreach ($rankingPanels as $key => [$label]): ?>
            <button type="button" class="tab<?= $key === 'month' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $key === 'month' ? 'true' : 'false' ?>" data-rank-tab="<?= h($key) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($rankingPanels as $key => [, $list]): ?>
        <div class="game-ranking__panel" role="tabpanel" data-rank-panel="<?= h($key) ?>"<?= $key === 'month' ? '' : ' hidden' ?>>
            <!-- is-collapsed の間は .is-fold の行を CSS で隠す。ボタンで is-collapsed を付け外しする（assets/ranking.js） -->
            <ol class="game-ranking__list is-collapsed" data-rank-list>
                <?php foreach ($list as $r): ?>
                    <li class="game-ranking__row<?= $r['me'] ? ' is-me' : '' ?><?= $r['fold'] ? ' is-fold' : '' ?>">
                        <span class="game-ranking__rank"><?= (int)$r['rank'] ?></span>
                        <span class="game-ranking__name"><?= h($r['name']) ?></span>
                        <span class="game-ranking__score"><?= number_format($r['score']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="game-ranking__empty muted small" data-rank-empty<?= $list ? ' hidden' : '' ?>>記録がありません</p>
            <!-- 押すと全員表示 / 畳むを切り替える。操作ボタンなので data-tip ではなく title と aria-label（CLAUDE.md の基準）。畳む行が無いときは隠す -->
            <button type="button" class="icon-btn game-ranking__toggle" data-rank-toggle aria-expanded="false" aria-label="すべて表示" title="すべて表示"<?= array_filter($list, fn($r) => $r['fold']) ? '' : ' hidden' ?>><?= icon('expand_more') ?></button>
            <?php if ($key === 'month'): ?>
                <p class="game-ranking__last muted small" data-rank-last<?= $rankingData['last_month_top'] ? '' : ' hidden' ?>><?= h(ranking_last_text($rankingData['last_month_top'])) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
