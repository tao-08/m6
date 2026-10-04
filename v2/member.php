<?php
/**
 * =====================================================================
 *  member.php?id=メンバーID — メンバーの個人ページ
 * =====================================================================
 *  出演履歴 / 楽器の内訳 / よく組むメンバー を出す。
 *  「よく組むメンバー」は band_member を自分自身と JOIN（自己結合）して、
 *  同じ band_id にいる「自分以外の人」を数えている。
 * =====================================================================
 */
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

// ---- 出演履歴（新しい順） ----
//   GROUP_CONCAT: 複数行の値を1つの文字列につなげる（Vo.と Ba.を兼任なら「Vo./Ba.」）
//   is_last: その日の最大 play_order と同じなら 1（トリ）
$st = $pdo->prepare('SELECT b.band_id, b.name AS band_name, b.play_order,
        GROUP_CONCAT(DISTINCT i.instrument_short ORDER BY i.instrument_id SEPARATOR \' \') AS parts,
        ld.live_detail_id, ld.label, ld.date, lm.live_id, lm.year, lm.name AS live_name, v.name AS venue_name,
        (b.play_order = (SELECT MAX(b2.play_order) FROM band b2 WHERE b2.live_detail_id = b.live_detail_id)) AS is_last
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    JOIN live_master lm ON lm.live_id = ld.live_id
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    LEFT JOIN band_member_instrument bmi ON bmi.band_id = b.band_id AND bmi.member_id = bm.member_id
    LEFT JOIN instrument i ON i.instrument_id = bmi.instrument_id
    WHERE bm.member_id = ?
    GROUP BY b.band_id
    ORDER BY lm.year DESC, ld.date DESC, ld.live_detail_id DESC, b.play_order');
$st->execute([$memberId]);
$history = $st->fetchAll();

// ---- 楽器の内訳 ----
$st = $pdo->prepare('SELECT i.instrument_short, i.instrument_name, COUNT(DISTINCT bmi.band_id) AS n
    FROM band_member_instrument bmi JOIN instrument i ON i.instrument_id = bmi.instrument_id
    WHERE bmi.member_id = ? GROUP BY i.instrument_id ORDER BY n DESC');
$st->execute([$memberId]);
$parts = $st->fetchAll();

// ---- よく組むメンバー（自己結合） ----
$st = $pdo->prepare('SELECT m.member_id, m.name, COUNT(DISTINCT other.band_id) AS n
    FROM (' . MEMBERSHIP_SQL . ') mine
    JOIN (' . MEMBERSHIP_SQL . ') other ON other.band_id = mine.band_id AND other.member_id <> mine.member_id
    JOIN member m ON m.member_id = other.member_id
    WHERE mine.member_id = ?
    GROUP BY m.member_id
    ORDER BY n DESC, m.name
    LIMIT 12');
$st->execute([$memberId]);
$partners = $st->fetchAll();

$headliners = count(array_filter($history, static fn($h) => (int)$h['is_last'] === 1));
$liveCount = count(array_unique(array_column($history, 'live_id')));
$isMe = $memberId === $user['member_id'];

render_header($member['name'], 'members');
?>
<nav class="crumbs"><a href="members.php">メンバー</a><span>/</span><?= h($member['name']) ?></nav>
<section class="hero">
    <div>
        <p class="eyebrow"><?= $isMe ? 'My Page' : 'Member' ?></p>
        <h1 class="display"><?= h($member['name']) ?></h1>
        <p class="muted small">
            <?= $member['name_kana'] !== '' ? h($member['name_kana']) : '' ?>
            <?= (int)$member['entry_year'] > 0 ? ' · ' . (int)$member['entry_year'] . '年度入部' : '' ?>
        </p>
        <div class="partbar">
            <?php foreach ($parts as $p): ?>
                <span class="part part--<?= h(instrument_class($p['instrument_short'])) ?>" title="<?= h($p['instrument_name']) ?>"><?= h($p['instrument_short']) ?> × <?= (int)$p['n'] ?></span>
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
            <?php foreach ($history as $hi): ?>
                <li class="card history__item<?= $hi['is_last'] ? ' is-last' : '' ?>">
                    <div class="history__when">
                        <span><?= h(fmt_year($hi['year'])) ?></span>
                        <span class="muted"><?= h(fmt_date($hi['date'])) ?></span>
                    </div>
                    <div class="history__what">
                        <a href="live.php?id=<?= (int)$hi['live_id'] ?>#day-<?= (int)$hi['live_detail_id'] ?>" class="muted small"><?= h($hi['live_name']) ?> <?= h($hi['label']) ?><?= $hi['venue_name'] ? ' · ' . h($hi['venue_name']) : '' ?></a>
                        <strong><?= h($hi['band_name']) ?></strong>
                    </div>
                    <div class="history__tags">
                        <?php if ($hi['parts']): ?><span class="pill"><?= h($hi['parts']) ?></span><?php endif; ?>
                        <?php if ($hi['is_last']): ?><span class="tag tag--accent">トリ</span><?php endif; ?>
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
                    <li><a href="member.php?id=<?= (int)$p['member_id'] ?>"><?= h($p['name']) ?></a><span class="pill"><?= (int)$p['n'] ?>回</span></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </aside>
</div>
<?php render_footer();
