<?php
/**
 * =====================================================================
 *  venue_list.php — 会場リスト（tools.php から入る）
 * =====================================================================
 *  会場ごとに「使った回数（live_day の行数）」と最寄り駅（徒歩がいちばん短い1つ）を出す。
 *  詳細・編集は venue.php。並びは masters.php と同じ venue.sort_order。
 *  表示するだけ（POST を受けない）ので CSRF 対策は不要。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();
$pdo = db();

// 使った回数は相関サブクエリで1回の SQL にまとめる（会場ごとに SQL を投げない）
$venues = $pdo->query('SELECT v.venue_id, v.name,
        (SELECT COUNT(*) FROM live_day d WHERE d.venue_id = v.venue_id) AS used
    FROM venue v ORDER BY v.sort_order, v.venue_id')->fetchAll();

// 最寄り駅: 全会場分をまとめて取り、徒歩が短い順に並べて最初の1つだけ使う
$nearest = [];
foreach ($pdo->query('SELECT venue_id, station_name, walk_minutes FROM venue_station
    ORDER BY walk_minutes, sort_order, venue_station_id') as $s) {
    $nearest[(int)$s['venue_id']] ??= $s;
}

render_header('会場リスト', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/venue.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a></nav>
<h1 class="display display--sm">会場リスト</h1>
<?php if (!$venues): ?>
    <div class="empty card"><p class="empty__title">会場がありません</p></div>
<?php else: ?>
<ul class="venue-list">
    <?php foreach ($venues as $v): $n = $nearest[(int)$v['venue_id']] ?? null; ?>
        <li>
            <a class="card venue-list__item" href="venue?id=<?= (int)$v['venue_id'] ?>">
                <span class="venue-list__name"><?= h($v['name']) ?></span>
                <span class="muted small venue-list__meta">
                    <?php if ($n): ?><span><?= icon('train') ?> <?= h($n['station_name']) ?>駅 徒歩<?= (int)$n['walk_minutes'] ?>分</span><?php endif; ?>
                    <span title="使った回数"><?= icon('event') ?> <?= (int)$v['used'] ?>回</span>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php render_footer();
