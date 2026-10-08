<?php
/**
 * =====================================================================
 *  artists.php — アーティスト一覧（演奏回数 + 最多演奏の人 + 最後に演奏したライブ）
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

// 「アーティスト × 演奏したバンド」の組（lib/bootstrap.php の ARTIST_PLAYS_SQL。アーティストページ・集計・検索と同じ数え方）
const PLAYED_SQL = ARTIST_PLAYS_SQL;

// 演奏0回のアーティストは出さない（JOIN = 演奏したバンドがあるものだけ）。
//   オムニバスにしてコピー元アーティストを外したバンドの「ボカロバンド」のような、どこからも使われなくなった名前を一覧に残さないため
//   （行は消さないので、artist.php?id= で直接開くことはできる）
$rows = $pdo->query('SELECT a.artist_id, a.name, COUNT(p.band_id) AS plays
    FROM artist a
    JOIN (' . PLAYED_SQL . ') p ON p.artist_id = a.artist_id
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

// 最多演奏（artist_id => [['member_id' => .., 'name' => ..], ...]）。
//   「アーティスト × 人」ごとに演奏したバンド数を数え、RANK() でアーティストごとの順位を付けて 1位だけ取る。
//   ROW_NUMBER() だと同じ回数でも 1, 2 と番号が分かれてしまうが、RANK() は同率なら全員 1 になる → 同率1位を全員拾える
$topPlayers = [];
$st = $pdo->query('SELECT artist_id, member_id, name, n FROM (
        SELECT p.artist_id, m.member_id, m.name, COUNT(*) AS n,
            RANK() OVER (PARTITION BY p.artist_id ORDER BY COUNT(*) DESC) AS rk
        FROM (' . PLAYED_SQL . ') p
        JOIN (' . MEMBERSHIP_SQL . ') bm ON bm.band_id = p.band_id
        JOIN member m ON m.member_id = bm.member_id
        GROUP BY p.artist_id, m.member_id) x
    WHERE rk = 1
    ORDER BY artist_id, name');
foreach ($st as $r) {
    $topPlayers[(int)$r['artist_id']][] = $r;
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
    'top'   => 'desc', // 最多演奏の人の回数が多いものから
];
$sort = $_GET['sort'] ?? 'plays';
if (!is_string($sort) || !isset(SORT_COLUMNS[$sort])) {
    $sort = 'plays';
}
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : SORT_COLUMNS[$sort]);

// # は演奏回数の順位のまま（並べ替えても変えない）。0回の人は順位なし
//   同じ演奏回数なら同じ順位（lib/bootstrap.php の tie_ranks）
$ranks = tie_ranks($rows, static fn($r) => (int)$r['plays']);
$list = [];
foreach ($rows as $i => $r) {
    $list[] = $r + ['rank' => (int)$r['plays'] > 0 ? $ranks[$i] : null];
}
usort($list, static function (array $a, array $b) use ($sort, $dir, $lastLive, $topPlayers): int {
    $la = $lastLive[(int)$a['artist_id']] ?? null;
    $lb = $lastLive[(int)$b['artist_id']] ?? null;
    if ($sort === 'last' && ($la === null) !== ($lb === null)) {
        return ($la === null) <=> ($lb === null); // 演奏されていないものは、向きに関係なく一番下
    }
    $ta = $topPlayers[(int)$a['artist_id']][0] ?? null;
    $tb = $topPlayers[(int)$b['artist_id']][0] ?? null;
    if ($sort === 'top' && ($ta === null) !== ($tb === null)) {
        return ($ta === null) <=> ($tb === null); // 最多演奏の人がいないもの（メンバー未登録のバンドだけ）は一番下
    }
    $cmp = match ($sort) {
        'name'  => strcmp(mb_convert_kana($a['name'], 'c'), mb_convert_kana($b['name'], 'c')),
        'last'  => [(string)$la['held_on'], (int)$la['fiscal_year']] <=> [(string)$lb['held_on'], (int)$lb['fiscal_year']],
        // 1位の人の演奏回数 → 同じ回数なら同率1位の人数（1人で独占しているほうを上に）
        'top'   => [(int)($ta['n'] ?? 0), -count($topPlayers[(int)$a['artist_id']] ?? [])] <=> [(int)($tb['n'] ?? 0), -count($topPlayers[(int)$b['artist_id']] ?? [])],
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
    <dl class="stats"><div><dt>総演奏アーティスト数</dt><dd><?= count($rows) ?></dd></div></dl>
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
                <?= sort_th('top', '最多演奏', $sort, $dir) ?>
            </tr></thead>
            <tbody>
            <?php foreach ($list as $r): $id = (int)$r['artist_id']; $al = $aliases[$id] ?? []; $ll = $lastLive[$id] ?? null; ?>
                <tr class="artist-row" data-text="<?= h($r['name'] . ' ' . implode(' ', $al)) ?>">
                    <td class="num muted"><?= $r['rank'] ?? '—' ?></td>
                    <td>
                        <a href="artist?id=<?= $id ?>"<?= $r['rank'] ? ' class="strong"' : '' ?>><?= h($r['name']) ?></a>
                        <?php if ($al): ?><div class="muted small">別名: <?= h(implode('、', $al)) ?></div><?php endif; ?>
                    </td>
                    <td class="num<?= $r['rank'] ? ' strong' : ' muted' ?>"><?= (int)$r['plays'] ?></td>
                    <td>
                        <?php if ($ll): ?>
                            <a href="live?id=<?= (int)$ll['live_id'] ?>"><span class="muted"><?= (int)$ll['fiscal_year'] ?>年度</span> <?= h($ll['name']) ?></a>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($tp = $topPlayers[$id] ?? []): ?>
                            <?php // 同率1位が多いとごちゃつくので、名前は3人まで。残りは「ほか◯人」にまとめ、乗せる（スマホは押す）と名前を出す（app.js の setupSetlistTip を使い回す）
                            $shown = array_slice($tp, 0, 3);
                            $rest = array_column(array_slice($tp, 3), 'name'); ?>
                            <?php foreach ($shown as $i => $m): ?><?= $i > 0 ? ' ' : '' ?><a href="member?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a><?php endforeach; ?>
                            <?php if ($rest): ?><button type="button" class="more-names" data-setlist-tip="<?= h(implode(' ', $rest)) ?>" aria-label="<?= h('ほか: ' . implode(' ', $rest)) ?>">ほか<?= count($rest) ?>人</button><?php endif; ?>
                            <span class="muted small"><?= (int)$tp[0]['n'] ?>回</span>
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
