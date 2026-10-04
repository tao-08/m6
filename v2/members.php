<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

// 出演数・トリ回数（その日の最後の出演順 = トリ）
$rows = db()->query('SELECT m.member_id, m.member_name,
        COUNT(DISTINCT bm.band_id) AS bands,
        COUNT(DISTINCT CASE WHEN b.play_order = last.max_order THEN b.band_id END) AS headliners,
        COUNT(DISTINCT ld.live_id) AS lives,
        MAX(ld.live_date) AS last_date
    FROM member m
    JOIN band_member bm ON bm.member_id = m.member_id
    JOIN band_master b ON b.band_id = bm.band_id
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    JOIN (SELECT live_detail_id, MAX(play_order) AS max_order FROM band_master GROUP BY live_detail_id) last
        ON last.live_detail_id = b.live_detail_id
    GROUP BY m.member_id
    ORDER BY bands DESC, headliners DESC, m.member_name')->fetchAll();

$max = $rows ? max(array_column($rows, 'bands')) : 1;
render_header('メンバー', 'members');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Members</p>
        <h1 class="display">メンバー</h1>
    </div>
    <dl class="stats"><div><dt>出演者</dt><dd><?= count($rows) ?></dd></div></dl>
</section>

<?php if (!$rows): ?>
    <div class="empty card"><p class="empty__title">まだ出演データがありません</p></div>
<?php else: ?>
    <div class="toolbar">
        <input type="search" class="search" placeholder="名前で検索" data-filter=".member-row" aria-label="名前で検索">
    </div>
    <div class="card table-card">
        <table class="table">
            <thead><tr><th class="num">#</th><th>名前</th><th>出演</th><th class="num hide-sm">ライブ</th><th class="num">トリ</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr class="member-row<?= (int)$r['member_id'] === $user['member_id'] ? ' is-me' : '' ?>" data-text="<?= h($r['member_name']) ?>">
                    <td class="num muted"><?= $i + 1 ?></td>
                    <td><a href="member.php?id=<?= (int)$r['member_id'] ?>" class="strong"><?= h($r['member_name']) ?></a></td>
                    <td><div class="bar-cell">
                        <span class="bar-track"><span class="bar" style="--w: <?= round((int)$r['bands'] / $max * 100) ?>%"></span></span>
                        <span class="bar-num"><?= (int)$r['bands'] ?></span>
                    </div></td>
                    <td class="num hide-sm"><?= (int)$r['lives'] ?></td>
                    <td class="num"><?= (int)$r['headliners'] ?: '<span class="muted">0</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
