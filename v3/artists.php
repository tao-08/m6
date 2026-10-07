<?php
/**
 * =====================================================================
 *  artists.php — アーティスト一覧（演奏回数 + 最後に演奏したライブ）
 * =====================================================================
 *  「演奏回数」= そのアーティストを演奏したバンドの数。
 *    バンドまるごとのコピー（band.artist_id）に加えて、
 *    オムニバスのバンドで曲ごとに付いたアーティスト（song.artist_id）も数える。
 *    同じバンドが同じアーティストを何曲やっても 1回（UNION が重複を消す）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$pdo = db();

// 「アーティスト × 演奏したバンド」の組。UNION（ALL なし）なので同じ組は1つにまとまる
const PLAYED_SQL = 'SELECT artist_id, band_id FROM band WHERE artist_id IS NOT NULL
    UNION
    SELECT artist_id, band_id FROM song WHERE artist_id IS NOT NULL';

$rows = $pdo->query('SELECT a.artist_id, a.name, COUNT(p.band_id) AS plays
    FROM artist a
    LEFT JOIN (' . PLAYED_SQL . ') p ON p.artist_id = a.artist_id
    GROUP BY a.artist_id
    ORDER BY plays DESC, a.name')->fetchAll();

// 最後に演奏したライブ（artist_id => 行）。
//   ROW_NUMBER() でアーティストごとに新しい順の番号を振り、1番だけ取る。
//   アーティストごとに SELECT すると人数ぶんクエリが飛ぶ（N+1）ので、全員ぶんを1回で取る
$lastLive = [];
$st = $pdo->query('SELECT artist_id, live_id, fiscal_year, name, held_on FROM (
        SELECT p.artist_id, l.live_id, l.fiscal_year, l.name, d.held_on,
            ROW_NUMBER() OVER (PARTITION BY p.artist_id ORDER BY d.held_on DESC, l.fiscal_year DESC) AS rn
        FROM (' . PLAYED_SQL . ') p
        JOIN band b ON b.band_id = p.band_id
        JOIN live_day d ON d.live_day_id = b.live_day_id
        JOIN live l ON l.live_id = d.live_id) x
    WHERE rn = 1');
foreach ($st as $r) {
    $lastLive[(int)$r['artist_id']] = $r;
}

// 別名（artist_id => [別名, ...]）
$aliases = [];
foreach ($pdo->query('SELECT artist_id, name FROM artist_alias ORDER BY name') as $r) {
    $aliases[(int)$r['artist_id']][] = $r['name'];
}

// ---- 並べ替え（?sort=列&dir=asc|desc）。members.php と同じ作り ----
//   値はホワイトリスト（SORT_COLUMNS のキー）にあるものだけ使う
const SORT_COLUMNS = [ // 列 => 最初にクリックしたときの向き
    'name'  => 'asc',
    'plays' => 'desc',
    'last'  => 'desc', // 最近演奏されたものから
];
$sort = $_GET['sort'] ?? 'plays';
if (!is_string($sort) || !isset(SORT_COLUMNS[$sort])) {
    $sort = 'plays';
}
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : SORT_COLUMNS[$sort]);

// # は演奏回数の順位のまま（並べ替えても変えない）。0回の人は順位なし
$list = [];
foreach ($rows as $i => $r) {
    $list[] = $r + ['rank' => (int)$r['plays'] > 0 ? $i + 1 : null];
}
usort($list, static function (array $a, array $b) use ($sort, $dir, $lastLive): int {
    $la = $lastLive[(int)$a['artist_id']] ?? null;
    $lb = $lastLive[(int)$b['artist_id']] ?? null;
    if ($sort === 'last' && ($la === null) !== ($lb === null)) {
        return ($la === null) <=> ($lb === null); // 演奏されていないものは、向きに関係なく一番下
    }
    $cmp = match ($sort) {
        'name'  => strcmp(mb_convert_kana($a['name'], 'c'), mb_convert_kana($b['name'], 'c')),
        'last'  => [(string)$la['held_on'], (int)$la['fiscal_year']] <=> [(string)$lb['held_on'], (int)$lb['fiscal_year']],
        default => (int)$a['plays'] <=> (int)$b['plays'],
    };
    if ($dir === 'desc') {
        $cmp = -$cmp;
    }
    return $cmp ?: (($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX)) ?: strcmp($a['name'], $b['name']);
});

/** 並べ替えできる列の見出し。今の並びの列には ▲▼ を付け、もう一度押すと逆向きになる */
function sort_th(string $col, string $label, string $sort, string $dir, string $class = ''): string
{
    $next = $col === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : SORT_COLUMNS[$col];
    $query = http_build_query(['sort' => $col, 'dir' => $next]);
    $aria = $col === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
    $arrow = $col === $sort ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<th class="' . h(trim('sortable ' . $class)) . '"' . $aria . '><a href="?' . h($query) . '">' . h($label)
        . '<span class="sortable__arrow">' . $arrow . '</span></a></th>';
}

render_header('アーティスト', 'artists');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Artists</p>
        <h1 class="display">アーティスト</h1>
    </div>
    <dl class="stats"><div><dt>総アーティスト数</dt><dd><?= count($rows) ?></dd></div></dl>
</section>

<?php if (!$list): ?>
    <div class="empty card"><p class="empty__title">まだアーティストがいません</p></div>
<?php else: ?>
    <div class="toolbar">
        <!-- 名前の絞り込みは members.php と同じ（app.js の data-filter が data-text を見て行を隠す） -->
        <input type="search" class="search" placeholder="アーティスト名で検索" data-filter=".artist-row" aria-label="アーティスト名で検索">
    </div>
    <div class="card table-card">
        <table class="table">
            <thead><tr>
                <th class="num">#</th>
                <?= sort_th('name', 'アーティスト', $sort, $dir) ?>
                <?= sort_th('plays', '演奏回数', $sort, $dir, 'num') ?>
                <?= sort_th('last', '最後に演奏したライブ', $sort, $dir) ?>
            </tr></thead>
            <tbody>
            <?php foreach ($list as $r): $id = (int)$r['artist_id']; $al = $aliases[$id] ?? []; $ll = $lastLive[$id] ?? null; ?>
                <tr class="artist-row" data-text="<?= h($r['name'] . ' ' . implode(' ', $al)) ?>">
                    <td class="num muted"><?= $r['rank'] ?? '—' ?></td>
                    <td>
                        <a href="artist.php?id=<?= $id ?>"<?= $r['rank'] ? ' class="strong"' : '' ?>><?= h($r['name']) ?></a>
                        <?php if ($al): ?><div class="muted small">別名: <?= h(implode('、', $al)) ?></div><?php endif; ?>
                    </td>
                    <td class="num<?= $r['rank'] ? ' strong' : ' muted' ?>"><?= (int)$r['plays'] ?></td>
                    <td>
                        <?php if ($ll): ?>
                            <a href="live.php?id=<?= (int)$ll['live_id'] ?>"><span class="muted"><?= (int)$ll['fiscal_year'] ?>年度</span> <?= h($ll['name']) ?></a>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
