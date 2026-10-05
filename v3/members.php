<?php
/**
 * =====================================================================
 *  members.php — メンバー一覧（出演回数ランキング）
 * =====================================================================
 *  「トリ」= その日の play_order が一番大きいバンド。
 *  日程ごとの最大の play_order をサブクエリ（last）で先に求めておき、JOIN して比べている。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$rows = db()->query('SELECT m.member_id, m.name, m.entry_year,
        COUNT(DISTINCT bm.band_id) AS bands,
        COUNT(DISTINCT CASE WHEN b.play_order = last.max_order THEN b.band_id END) AS headliners,
        COUNT(DISTINCT ld.live_id) AS lives
    FROM member m
    JOIN (' . MEMBERSHIP_SQL . ') bm ON bm.member_id = m.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN (SELECT live_day_id, MAX(play_order) AS max_order FROM band GROUP BY live_day_id) last
        ON last.live_day_id = b.live_day_id
    GROUP BY m.member_id
    ORDER BY bands DESC, headliners DESC, m.name')->fetchAll();

// 一度も出演していないメンバー（名簿にだけいる人）も下に出す
$idle = db()->query('SELECT m.member_id, m.name FROM member m
    WHERE m.member_id NOT IN (SELECT member_id FROM (' . MEMBERSHIP_SQL . ') x)
    ORDER BY m.name')->fetchAll();

$max = $rows ? max(array_column($rows, 'bands')) : 1; // 棒グラフの長さの基準
render_header('メンバー', 'members');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Members</p>
        <h1 class="display">メンバー</h1>
    </div>
    <dl class="stats"><div><dt>出演者</dt><dd><?= count($rows) ?></dd></div><div><dt>登録</dt><dd><?= count($rows) + count($idle) ?></dd></div></dl>
</section>

<?php if (!$rows && !$idle): ?>
    <div class="empty card">
        <p class="empty__title">まだメンバーがいません</p>
        <a class="btn btn--primary" href="member_new.php">＋ メンバーを追加</a>
    </div>
<?php else: ?>
    <div class="toolbar">
        <input type="search" class="search" placeholder="名前で検索" data-filter=".member-row" aria-label="名前で検索">
        <a class="btn btn--primary" href="member_new.php">＋ 新規追加</a>
    </div>
    <div class="card table-card">
        <table class="table">
            <thead><tr><th class="num">#</th><th>名前</th><th>出演</th><th class="num hide-sm">ライブ</th><th class="num">トリ</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr class="member-row<?= (int)$r['member_id'] === $user['member_id'] ? ' is-me' : '' ?>" data-text="<?= h($r['name']) ?>">
                    <td class="num muted"><?= $i + 1 ?></td>
                    <td><a href="member.php?id=<?= (int)$r['member_id'] ?>" class="strong"><?= h($r['name']) ?></a>
                        <?php if ((int)$r['entry_year'] > 0): ?><span class="muted small"> <?= (int)$r['entry_year'] ?>入部</span><?php endif; ?></td>
                    <td><div class="bar-cell">
                        <!-- 棒の長さは CSS 変数 --w で渡す（style 属性に数字だけ入れるので XSS の心配なし） -->
                        <span class="bar-track"><span class="bar" style="--w: <?= round((int)$r['bands'] / $max * 100) ?>%"></span></span>
                        <span class="bar-num"><?= (int)$r['bands'] ?></span>
                    </div></td>
                    <td class="num hide-sm"><?= (int)$r['lives'] ?></td>
                    <td class="num"><?= (int)$r['headliners'] ?: '<span class="muted">0</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($idle as $r): ?>
                <tr class="member-row" data-text="<?= h($r['name']) ?>">
                    <td class="num muted">—</td>
                    <td><a href="member.php?id=<?= (int)$r['member_id'] ?>"><?= h($r['name']) ?></a></td>
                    <td class="muted small">出演データなし</td><td class="hide-sm"></td><td></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
