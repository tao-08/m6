<?php
/**
 * =====================================================================
 *  intro_previews.php — 試聴の一括調査（管理者のみ。tools.php の運営ツールから入る）
 * =====================================================================
 *  セトリの曲のうち、試聴をまだ一度も調べていない曲（イントロクイズの数に入っていない曲）を、
 *  1 曲ずつ iTunes で探して覚える（track_preview）。調べ終えた曲は、試聴があればイントロクイズで出る。
 *  動き: assets/intro_previews.js が api_intro_previews.php を数秒おきに呼ぶ。この画面を開いている間だけ進む。
 *  この画面自体は表示するだけ（POST を受けない）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tracks.php';
require_admin();

$pending = track_preview_pending_count(db());

render_header('試聴の一括調査', 'tools');
?>
<h1>試聴の一括調査</h1>
<div class="card" id="intro-previews">
    <p>未調査 <b data-remaining><?= number_format($pending) ?></b>曲</p>
    <p class="muted small" data-log aria-live="polite"></p>
    <div class="form-actions">
        <button type="button" class="btn btn--primary" data-act="start"<?= $pending === 0 ? ' disabled' : '' ?>><?= icon('play_arrow') ?> 調べる</button>
        <button type="button" class="btn" data-act="stop" hidden><?= icon('stop') ?> 止める</button>
    </div>
</div>
<script src="<?= asset_url('assets/intro_previews.js') ?>" defer></script>
<?php render_footer();
