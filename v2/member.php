<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$memberId = (int)($_GET['id'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT * FROM member WHERE member_id = ?');
$st->execute([$memberId]);
$member = $st->fetch();
if (!$member) {
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">メンバーが見つかりません</p><a class="btn" href="members.php">一覧へ戻る</a></div>';
    render_footer();
    exit;
}

// 出演履歴
$st = $pdo->prepare('SELECT b.band_id, b.band_name, b.play_order, b.start_time,
        GROUP_CONCAT(DISTINCT bm.part ORDER BY FIELD(bm.part, \'Vo\', \'Gt\', \'Ba\', \'Dr\', \'Key\', \'Other\') SEPARATOR \'/\') AS parts,
        ld.day_no, ld.live_date, lm.live_id, lm.year, lm.live_name, v.venue_name,
        (b.play_order = (SELECT MAX(b2.play_order) FROM band_master b2 WHERE b2.live_detail_id = b.live_detail_id)) AS is_last
    FROM band_member bm
    JOIN band_master b ON b.band_id = bm.band_id
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    JOIN live_master lm ON lm.live_id = ld.live_id
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    WHERE bm.member_id = ?
    GROUP BY b.band_id
    ORDER BY lm.year DESC, ld.live_date DESC, lm.live_id DESC, ld.day_no DESC, b.play_order');
$st->execute([$memberId]);
$history = $st->fetchAll();

// パート内訳
$st = $pdo->prepare('SELECT part, COUNT(*) AS n FROM band_member WHERE member_id = ? GROUP BY part ORDER BY n DESC');
$st->execute([$memberId]);
$parts = $st->fetchAll();

// よく組むメンバー
$st = $pdo->prepare('SELECT m.member_id, m.member_name, COUNT(DISTINCT other.band_id) AS n
    FROM band_member mine
    JOIN band_member other ON other.band_id = mine.band_id AND other.member_id <> mine.member_id
    JOIN member m ON m.member_id = other.member_id
    WHERE mine.member_id = ?
    GROUP BY m.member_id
    ORDER BY n DESC, m.member_name
    LIMIT 12');
$st->execute([$memberId]);
$partners = $st->fetchAll();

$headliners = count(array_filter($history, static fn($h) => (int)$h['is_last'] === 1));
$liveCount = count(array_unique(array_column($history, 'live_id')));

render_header($member['member_name'], 'members');
?>
<nav class="crumbs"><a href="members.php">メンバー</a><span>/</span><?= h($member['member_name']) ?></nav>
<section class="hero">
    <div>
        <p class="eyebrow"><?= (int)$member['member_id'] === $user['member_id'] ? 'My Page' : 'Member' ?></p>
        <h1 class="display"><?= h($member['member_name']) ?></h1>
        <div class="partbar">
            <?php foreach ($parts as $p): ?>
                <span class="part part--<?= h(strtolower($p['part'])) ?>"><?= h(part_label($p['part'])) ?> × <?= (int)$p['n'] ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <dl class="stats">
        <div><dt>出演バンド</dt><dd><?= count($history) ?></dd></div>
        <div><dt>ライブ</dt><dd><?= $liveCount ?></dd></div>
        <div><dt>トリ</dt><dd><?= $headliners ?></dd></div>
    </dl>
</section>

<div class="split">
    <section>
        <h2 class="section-title">出演履歴</h2>
        <ol class="history">
            <?php foreach ($history as $h): ?>
                <li class="card history__item<?= $h['is_last'] ? ' is-last' : '' ?>">
                    <div class="history__when">
                        <span><?= (int)$h['year'] ?>年度</span>
                        <span class="muted"><?= h(fmt_date($h['live_date'])) ?></span>
                    </div>
                    <div class="history__what">
                        <a href="live.php?id=<?= (int)$h['live_id'] ?>#day-<?= (int)$h['day_no'] ?>" class="muted small"><?= h($h['live_name']) ?> DAY<?= (int)$h['day_no'] ?><?= $h['venue_name'] ? ' · ' . h($h['venue_name']) : '' ?></a>
                        <strong><?= h($h['band_name']) ?></strong>
                    </div>
                    <div class="history__tags">
                        <span class="pill"><?= h($h['parts']) ?></span>
                        <?php if ($h['is_last']): ?><span class="tag tag--accent">トリ</span><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if (!$history): ?><p class="muted">出演データがありません</p><?php endif; ?>
    </section>
    <aside>
        <h2 class="section-title">よく組むメンバー</h2>
        <div class="card">
            <?php if (!$partners): ?><p class="muted">まだいません</p><?php endif; ?>
            <ol class="ranking">
                <?php foreach ($partners as $p): ?>
                    <li><a href="member.php?id=<?= (int)$p['member_id'] ?>"><?= h($p['member_name']) ?></a><span class="pill"><?= (int)$p['n'] ?>回</span></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </aside>
</div>
<?php render_footer();
