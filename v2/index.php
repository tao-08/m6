<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$pdo = db();
$rows = $pdo->query('SELECT lm.live_id, lm.year, lm.live_name,
        ld.live_detail_id, ld.day_no, ld.live_date, v.venue_name, COUNT(b.band_id) AS band_count
    FROM live_master lm
    JOIN live_detail ld ON ld.live_id = lm.live_id
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    LEFT JOIN band_master b ON b.live_detail_id = ld.live_detail_id
    GROUP BY ld.live_detail_id
    ORDER BY lm.year DESC, lm.live_id, ld.day_no')->fetchAll();

$years = [];
foreach ($rows as $r) {
    $live = &$years[$r['year']][$r['live_id']];
    $live['name'] = $r['live_name'];
    $live['days'][] = $r;
    $live['bands'] = ($live['bands'] ?? 0) + (int)$r['band_count'];
    $dates = array_filter([$live['first_date'] ?? null, $r['live_date']]);
    $live['first_date'] = $dates ? min($dates) : null;
    unset($live);
}
// 年度内は開催日の新しい順（日付不明は後ろ）
foreach ($years as &$lives) {
    uasort($lives, static fn($a, $b) => [$b['first_date'] !== null, $b['first_date']] <=> [$a['first_date'] !== null, $a['first_date']]);
}
unset($lives);

$stats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM live_master) AS lives,
    (SELECT COUNT(*) FROM band_master) AS bands,
    (SELECT COUNT(DISTINCT member_id) FROM band_member) AS members')->fetch();

render_header('ライブ一覧', 'lives');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Live Archive</p>
        <h1 class="display">ライブ</h1>
    </div>
    <dl class="stats">
        <div><dt>ライブ</dt><dd><?= (int)$stats['lives'] ?></dd></div>
        <div><dt>バンド</dt><dd><?= (int)$stats['bands'] ?></dd></div>
        <div><dt>出演者</dt><dd><?= (int)$stats['members'] ?></dd></div>
    </dl>
</section>

<?php if (!$years): ?>
    <div class="empty card">
        <p class="empty__title">まだライブが登録されていません</p>
        <p class="muted">タイムテーブルとメンバー表（CSV / PDF）をアップロードして登録しよう。</p>
        <a class="btn btn--primary" href="import.php">タイムテーブルを取り込む</a>
    </div>
<?php else: ?>
    <div class="toolbar">
        <input type="search" class="search" placeholder="ライブ名・会場で絞り込み" data-filter=".live-card" aria-label="絞り込み">
        <a class="btn btn--primary" href="import.php">＋ 取り込み</a>
    </div>
    <?php foreach ($years as $year => $lives): ?>
        <section class="year">
            <h2 class="year__title"><?= (int)$year ?><small>年度</small></h2>
            <div class="grid">
                <?php foreach ($lives as $liveId => $live):
                    $venues = array_unique(array_filter(array_column($live['days'], 'venue_name'))); ?>
                    <a class="card live-card" href="live.php?id=<?= (int)$liveId ?>"
                       data-text="<?= h($live['name'] . ' ' . implode(' ', $venues)) ?>">
                        <div class="live-card__head">
                            <h3><?= h($live['name']) ?></h3>
                            <span class="pill"><?= count($live['days']) ?>日間</span>
                        </div>
                        <ul class="live-card__days">
                            <?php foreach ($live['days'] as $d): ?>
                                <li>
                                    <span class="day-no">DAY <?= (int)$d['day_no'] ?></span>
                                    <span><?= h(fmt_date($d['live_date']) ?: '日付未設定') ?></span>
                                    <span class="muted ellipsis"><?= h($d['venue_name'] ?? '') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="live-card__foot"><strong><?= (int)$live['bands'] ?></strong> バンド出演 <span class="arrow">→</span></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php render_footer();
