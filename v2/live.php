<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$liveId = (int)($_GET['id'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT * FROM live_master WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">ライブが見つかりません</p><a class="btn" href="index.php">一覧へ戻る</a></div>';
    render_footer();
    exit;
}

$st = $pdo->prepare('SELECT ld.*, v.venue_name FROM live_detail ld
    LEFT JOIN venue v ON v.venue_id = ld.venue_id WHERE ld.live_id = ? ORDER BY ld.day_no');
$st->execute([$liveId]);
$days = $st->fetchAll();

$st = $pdo->prepare('SELECT b.* FROM band_master b JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    WHERE ld.live_id = ? ORDER BY b.play_order');
$st->execute([$liveId]);
$bandsByDay = [];
foreach ($st as $b) {
    $bandsByDay[$b['live_detail_id']][] = $b;
}

$st = $pdo->prepare('SELECT bm.band_id, bm.part, m.member_id, m.member_name FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band_master b ON b.band_id = bm.band_id
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    WHERE ld.live_id = ?');
$st->execute([$liveId]);
$membersByBand = [];
foreach ($st as $m) {
    $membersByBand[$m['band_id']][$m['part']][] = $m;
}
foreach ($membersByBand as &$parts) {
    uksort($parts, static fn($a, $b) => (PART_ORDER[$a] ?? 9) <=> (PART_ORDER[$b] ?? 9));
}
unset($parts);

$totalBands = array_sum(array_map('count', $bandsByDay));
render_header($live['live_name'], 'lives');
?>
<nav class="crumbs"><a href="index.php">ライブ</a><span>/</span><?= (int)$live['year'] ?>年度</nav>
<section class="hero hero--live">
    <div>
        <p class="eyebrow"><?= (int)$live['year'] ?> · <?= count($days) ?> DAYS · <?= $totalBands ?> BANDS</p>
        <h1 class="display"><?= h($live['live_name']) ?></h1>
    </div>
</section>

<?php if (count($days) > 1): ?>
<div class="tabs" role="tablist">
    <?php foreach ($days as $i => $d): ?>
        <a href="#day-<?= (int)$d['day_no'] ?>" class="tab<?= $i === 0 ? ' is-active' : '' ?>" data-tab>
            DAY <?= (int)$d['day_no'] ?><small><?= h(fmt_date($d['live_date'])) ?></small>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($days as $i => $d):
    $bands = $bandsByDay[$d['live_detail_id']] ?? [];
    $songs = array_sum(array_map(static fn($b) => (int)$b['song_count'], $bands)); ?>
<section class="day" id="day-<?= (int)$d['day_no'] ?>" <?= $i > 0 && count($days) > 1 ? 'data-hidden' : '' ?>>
    <div class="day__info card">
        <div>
            <p class="eyebrow">DAY <?= (int)$d['day_no'] ?></p>
            <h2><?= h(fmt_date($d['live_date']) ?: '日付未設定') ?></h2>
        </div>
        <dl class="facts">
            <div><dt>会場</dt><dd><?= h($d['venue_name'] ?? '—') ?></dd></div>
            <div><dt>集合</dt><dd><?= h(fmt_time($d['meeting_time']) ?: '—') ?></dd></div>
            <div><dt>バンド</dt><dd><?= count($bands) ?></dd></div>
            <div><dt>曲数</dt><dd><?= $songs ?></dd></div>
        </dl>
        <?php if (is_admin()): ?>
            <form method="post" action="live_delete.php" class="day__danger" data-confirm="DAY <?= (int)$d['day_no'] ?> のデータを削除します。元に戻せません。よろしいですか？">
                <?= csrf_field() ?>
                <input type="hidden" name="live_detail_id" value="<?= (int)$d['live_detail_id'] ?>">
                <button class="btn btn--ghost btn--danger btn--sm" type="submit">この日を削除</button>
            </form>
        <?php endif; ?>
    </div>

    <ol class="timeline">
        <?php $prevEnd = null;
        foreach ($bands as $bi => $b):
            if ($prevEnd && $b['start_time'] && $b['start_time'] > $prevEnd): ?>
                <li class="slot slot--break">
                    <div class="slot__time"><?= h(fmt_time($prevEnd)) ?><span><?= h(fmt_time($b['start_time'])) ?></span></div>
                    <div class="slot__body"><span class="break-label">BREAK</span></div>
                </li>
            <?php endif;
            $prevEnd = $b['end_time'] ?: $prevEnd;
            $members = $membersByBand[$b['band_id']] ?? [];
            $isMine = $user['member_id'] && in_array($user['member_id'], array_column(array_merge(...array_values($members) ?: [[]]), 'member_id'));
            $isLast = $bi === count($bands) - 1; ?>
            <li class="slot<?= $isLast ? ' slot--headliner' : '' ?><?= $isMine ? ' slot--mine' : '' ?>">
                <div class="slot__time"><?= h(fmt_time($b['start_time'])) ?><span><?= h(fmt_time($b['end_time'])) ?></span></div>
                <div class="slot__body">
                    <div class="slot__head">
                        <span class="slot__order"><?= sprintf('%02d', (int)$b['play_order']) ?></span>
                        <h3 class="slot__name"><?= h($b['band_name']) ?></h3>
                        <?php if ($isLast): ?><span class="tag tag--accent">トリ</span><?php endif; ?>
                        <?php if ($isMine): ?><span class="tag">出演</span><?php endif; ?>
                        <a class="slot__edit" href="band_edit.php?id=<?= (int)$b['band_id'] ?>" aria-label="<?= h($b['band_name']) ?> を編集">編集</a>
                    </div>
                    <?php if ($members): ?>
                        <ul class="lineup">
                            <?php foreach ($members as $part => $list): ?>
                                <li><span class="part part--<?= h(strtolower($part)) ?>"><?= h(part_label($part)) ?></span>
                                    <?php foreach ($list as $m): ?>
                                        <a class="chip<?= (int)$m['member_id'] === $user['member_id'] ? ' chip--me' : '' ?>" href="member.php?id=<?= (int)$m['member_id'] ?>"><?= h($m['member_name']) ?></a>
                                    <?php endforeach; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted small">メンバー未登録</p>
                    <?php endif; ?>
                    <p class="slot__meta">
                        <?php if ($b['song_count'] !== null): ?><span><?= (int)$b['song_count'] ?>曲</span><?php endif; ?>
                        <?php if ($b['member_count'] !== null): ?><span><?= (int)$b['member_count'] ?>人</span><?php endif; ?>
                        <?php if ($b['key_note'] !== ''): ?><span class="keynote">🎹 <?= h($b['key_note']) ?></span><?php endif; ?>
                    </p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if (!$bands): ?><p class="muted">バンドが登録されていません</p><?php endif; ?>
</section>
<?php endforeach; ?>
<?php render_footer();
