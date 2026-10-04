<?php
/**
 * =====================================================================
 *  stats.php?year=2025 — 集計ページ
 * =====================================================================
 *  README に書いてあった「特に多い組み合わせ」「出演回数が多い人」「トリが多い人」を見える化する。
 *  ?year= を付けるとその年度だけ、付けなければ全期間。
 *
 *  ポイント: 年度での絞り込みを全部の SQL で使い回すため、
 *  WHERE の部品（$yearSql）とパラメータ（$yearParams）を変数にしておいて SQL に足している。
 *  （値そのものを SQL 文字列に埋め込まず、? で渡しているので安全）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$pdo = db();
$years = array_map('intval', $pdo->query('SELECT DISTINCT fiscal_year FROM live ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));
$year = (int)($_GET['year'] ?? 0);
if (!in_array($year, $years, true)) {
    $year = 0; // 0 = 全期間
}
$yearSql = $year ? ' AND lm.fiscal_year = ?' : '';
$yearParams = $year ? [$year] : [];

/** SQL を実行して全行返す小さなヘルパー */
function rows(PDO $pdo, string $sql, array $params): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// ---- 年度ごとの概要 ----
$overview = rows($pdo, 'SELECT lm.fiscal_year AS year,
        COUNT(DISTINCT lm.live_id) AS lives, COUNT(DISTINCT ld.live_day_id) AS days,
        COUNT(DISTINCT b.band_id) AS bands, COALESCE(SUM(b.song_count), 0) AS songs,
        (SELECT COUNT(DISTINCT bm.member_id) FROM (' . MEMBERSHIP_SQL . ') bm
            JOIN band b2 ON b2.band_id = bm.band_id JOIN live_day ld2 ON ld2.live_day_id = b2.live_day_id
            JOIN live lm2 ON lm2.live_id = ld2.live_id WHERE lm2.fiscal_year = lm.fiscal_year) AS members
    FROM live lm
    JOIN live_day ld ON ld.live_id = lm.live_id
    LEFT JOIN band b ON b.live_day_id = ld.live_day_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY lm.fiscal_year ORDER BY lm.fiscal_year DESC', $yearParams);

// ---- よく組むペア ----
//   同じ表を2回 JOIN（自己結合）して、同じバンドにいた2人の組を数える。
//   a.member_id < b.member_id にすると (A,B) と (B,A) の重複が消える。
$pairs = rows($pdo, 'SELECT ma.member_id AS a_id, ma.name AS a_name, mb.member_id AS b_id, mb.name AS b_name, COUNT(*) AS n
    FROM (' . MEMBERSHIP_SQL . ') a
    JOIN (' . MEMBERSHIP_SQL . ') b ON b.band_id = a.band_id AND a.member_id < b.member_id
    JOIN member ma ON ma.member_id = a.member_id
    JOIN member mb ON mb.member_id = b.member_id
    JOIN band bd ON bd.band_id = a.band_id
    JOIN live_day ld ON ld.live_day_id = bd.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY a.member_id, b.member_id
    HAVING n >= 2
    ORDER BY n DESC, a_name LIMIT 15', $yearParams);

// ---- トリ回数 ----
$headliners = rows($pdo, 'SELECT m.member_id, m.name, COUNT(DISTINCT b.band_id) AS n
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN (SELECT live_day_id, MAX(play_order) AS max_order FROM band GROUP BY live_day_id) last
        ON last.live_day_id = b.live_day_id AND last.max_order = b.play_order
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY m.member_id ORDER BY n DESC, m.name LIMIT 10', $yearParams);

// ---- よくコピーされるアーティスト ----
//   artist テーブルがあるので GROUP BY a.artist_id だけで数えられる
//   （v2 では「ヨルシカ（安田）」の括弧を PHP で外してから数える必要があった）
$artists = rows($pdo, 'SELECT a.artist_id, a.name, COUNT(*) AS n
    FROM band b
    JOIN artist a ON a.artist_id = b.artist_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY a.artist_id
    HAVING n >= 2
    ORDER BY n DESC, a.name LIMIT 20', $yearParams);

// ---- 楽器別 ----
$instrumentStats = rows($pdo, 'SELECT i.short_name, i.name AS instrument_name,
        COUNT(DISTINCT bm.member_id) AS people, COUNT(*) AS slots
    FROM band_member bm
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY i.instrument_id ORDER BY slots DESC', $yearParams);

// ---- 会場 ----
$venues = rows($pdo, 'SELECT v.name, COUNT(DISTINCT ld.live_day_id) AS days, COUNT(b.band_id) AS bands
    FROM venue v
    JOIN live_day ld ON ld.venue_id = v.venue_id
    JOIN live lm ON lm.live_id = ld.live_id
    LEFT JOIN band b ON b.live_day_id = ld.live_day_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY v.venue_id ORDER BY days DESC, bands DESC', $yearParams);

$maxArtist = $artists ? max(array_column($artists, 'n')) : 1;
$maxSlots = $instrumentStats ? max(array_column($instrumentStats, 'slots')) : 1;

render_header('集計', 'stats');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Stats</p>
        <h1 class="display">集計</h1>
    </div>
    <!-- 年度の切り替え。onchange で JS がフォームを送信（JS なしでも「表示」ボタンで送れる） -->
    <form method="get" class="year-filter">
        <select name="year" aria-label="年度" data-autosubmit>
            <option value="0">全期間</option>
            <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?>年度</option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn--sm" type="submit">表示</button></noscript>
    </form>
</section>

<div class="card table-card">
    <table class="table">
        <thead><tr><th>年度</th><th class="num">ライブ</th><th class="num hide-sm">日程</th><th class="num">バンド</th><th class="num hide-sm">曲</th><th class="num">出演者</th></tr></thead>
        <tbody>
        <?php foreach ($overview as $o): ?>
            <tr>
                <td class="strong"><?= h(fmt_year($o['year'])) ?></td>
                <td class="num"><?= (int)$o['lives'] ?></td><td class="num hide-sm"><?= (int)$o['days'] ?></td>
                <td class="num"><?= (int)$o['bands'] ?></td><td class="num hide-sm"><?= (int)$o['songs'] ?></td>
                <td class="num"><?= (int)$o['members'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="stats-grid">
    <section class="card">
        <h2 class="section-title section-title--card">🤝 よく組むペア</h2>
        <?php if (!$pairs): ?><p class="muted small">2回以上組んだペアはまだいません</p><?php endif; ?>
        <ol class="ranking">
            <?php foreach ($pairs as $p): ?>
                <li><span><a href="member.php?id=<?= (int)$p['a_id'] ?>"><?= h($p['a_name']) ?></a> × <a href="member.php?id=<?= (int)$p['b_id'] ?>"><?= h($p['b_name']) ?></a></span><span class="pill"><?= (int)$p['n'] ?>回</span></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="card">
        <h2 class="section-title section-title--card">👑 トリ回数</h2>
        <?php if (!$headliners): ?><p class="muted small">データがありません</p><?php endif; ?>
        <ol class="ranking">
            <?php foreach ($headliners as $hd): ?>
                <li><a href="member.php?id=<?= (int)$hd['member_id'] ?>"><?= h($hd['name']) ?></a><span class="pill"><?= (int)$hd['n'] ?>回</span></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="card">
        <h2 class="section-title section-title--card">🎸 よくコピーされるアーティスト</h2>
        <?php if (!$artists): ?><p class="muted small">2回以上コピーされたアーティストはまだいません</p><?php endif; ?>
        <ul class="bars">
            <?php foreach ($artists as $a): ?>
                <li>
                    <a href="artist.php?id=<?= (int)$a['artist_id'] ?>" class="bars__label"><?= h($a['name']) ?></a>
                    <span class="bar-track"><span class="bar" style="--w: <?= round($a['n'] / $maxArtist * 100) ?>%"></span></span>
                    <span class="bar-num"><?= (int)$a['n'] ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <h2 class="section-title section-title--card">🥁 楽器別</h2>
        <ul class="bars">
            <?php foreach ($instrumentStats as $i): ?>
                <li>
                    <span class="bars__label"><span class="part part--<?= h(instrument_class($i['short_name'])) ?>"><?= h($i['short_name']) ?></span> <?= h($i['instrument_name']) ?></span>
                    <span class="bar-track"><span class="bar" style="--w: <?= round($i['slots'] / $maxSlots * 100) ?>%"></span></span>
                    <span class="bar-num" title="のべ出演数 / 人数"><?= (int)$i['slots'] ?><small class="muted"> / <?= (int)$i['people'] ?>人</small></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <h2 class="section-title section-title--card">📍 会場</h2>
        <ol class="ranking">
            <?php foreach ($venues as $v): ?>
                <li><a href="search.php?q=<?= urlencode($v['name']) ?>"><?= h($v['name']) ?></a><span class="pill"><?= (int)$v['days'] ?>日 · <?= (int)$v['bands'] ?>組</span></li>
            <?php endforeach; ?>
        </ol>
    </section>
</div>
<?php render_footer();
