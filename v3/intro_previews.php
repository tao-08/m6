<?php
/**
 * =====================================================================
 *  intro_previews.php — 試聴の一括調査（管理者のみ。tools.php の運営ツールから入る）
 * =====================================================================
 *  セトリの曲のうち、試聴をまだ一度も調べていない曲（イントロクイズの数に入っていない曲）を、
 *  1 曲ずつ iTunes で探して覚える（track_preview）。調べ終えた曲は、試聴があればイントロクイズで出る。
 *  画面は 2 つのカードの並び:
 *    未調査         … 「調べる」で上から順にではなくランダムに 1 曲ずつ調べる。見つかったら緑のチェック、無ければ下の並びへ移る
 *    見つからない曲 … リマスター・別バージョンの試聴で探し直せる。検索語を直して候補を聴き、選んだ曲の試聴を使う
 *  動き: assets/intro_previews.js が api_intro_previews.php を呼ぶ。この画面を開いている間だけ進む。
 *  この画面自体は表示するだけ（POST を受けない）。曲名は h() でエスケープする。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tracks.php';
require_admin();

const PREVIEW_CARDS_MAX = 100; // 1 つの並びに出すカードの上限（残りは調べるたびに入れ替わる）

$pdo = db();
$pending = track_preview_pending_count($pdo);
$missing = track_preview_missing_count($pdo);

/** カード 1 枚。$state = 'pending' / 'missing'。検索語の初期値は曲名 + アーティスト名 */
function preview_card(array $t, string $state): void
{
    $key = album_key($t['source'], $t['track_id']);
    ?>
    <li class="preview-card" data-key="<?= h($key) ?>" data-query="<?= h(track_search_query($t)) ?>">
        <div class="preview-card__row">
            <?php if (str_starts_with((string)$t['artwork_url'], 'https://')): ?>
                <img class="preview-card__art" src="<?= h($t['artwork_url']) ?>" alt="" width="48" height="48" loading="lazy">
            <?php endif; ?>
            <div class="preview-card__text">
                <p class="preview-card__title"><?= h($t['title']) ?></p>
                <p class="preview-card__artist muted small"><?= h($t['artist_name']) ?></p>
            </div>
            <span class="preview-card__ok" hidden aria-label="試聴あり"><?= icon('check_circle') ?></span>
            <button type="button" class="btn btn--ghost btn--sm preview-card__retry" data-act="retry-open" aria-expanded="false"<?= $state === 'pending' ? ' hidden' : '' ?>><?= icon('search') ?> 探し直す</button>
        </div>
    </li>
    <?php
}

render_header('試聴の一括調査', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/intro_previews.css') ?>">
<h1>試聴の一括調査</h1>
<div class="card" id="intro-previews">
    <div class="preview-head">
        <p>未調査 <b data-remaining><?= number_format($pending) ?></b>曲</p>
        <div class="form-actions">
            <button type="button" class="btn btn--primary" data-act="start"<?= $pending === 0 ? ' disabled' : '' ?>><?= icon('play_arrow') ?> 調べる</button>
            <button type="button" class="btn" data-act="stop" hidden><?= icon('stop') ?> 止める</button>
        </div>
    </div>
    <p class="muted small" data-log aria-live="polite"></p>

    <h2 class="preview-sub">未調査</h2>
    <ul class="preview-list" data-list="pending">
        <?php foreach (track_preview_cards($pdo, 'pending', PREVIEW_CARDS_MAX) as $t): preview_card($t, 'pending'); endforeach; ?>
    </ul>
    <?php if ($pending > PREVIEW_CARDS_MAX): ?><p class="muted small">ほか <?= number_format($pending - PREVIEW_CARDS_MAX) ?>曲</p><?php endif; ?>

    <h2 class="preview-sub">見つからない曲 <span class="muted small"><b data-missing><?= number_format($missing) ?></b>曲</span></h2>
    <ul class="preview-list" data-list="missing">
        <?php foreach (track_preview_cards($pdo, 'missing', PREVIEW_CARDS_MAX) as $t): preview_card($t, 'missing'); endforeach; ?>
    </ul>
    <?php if ($missing > PREVIEW_CARDS_MAX): ?><p class="muted small">ほか <?= number_format($missing - PREVIEW_CARDS_MAX) ?>曲</p><?php endif; ?>
</div>
<script src="<?= asset_url('assets/intro_previews.js') ?>" defer></script>
<?php render_footer();
