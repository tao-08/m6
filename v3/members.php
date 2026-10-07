<?php
/**
 * =====================================================================
 *  members.php — メンバー一覧（出演回数ランキング + マイアルバム上位5枚）
 * =====================================================================
 *  「トリ」= その日の play_order が一番大きいバンド。
 *  日程ごとの最大の play_order をサブクエリ（last）で先に求めておき、JOIN して比べている。
 *
 *  上の「学年」タブで表示するメンバーを絞り込める（?who=）
 *    all   … 全学年（入学年度が不明な人も含む）
 *    near  … 上下3学年（ログイン中ユーザーの入学年度 ±3。自分の学年も含む）
 *    grade … 学年別（?entry=入学年度。?entry=none で入学年度が不明な人）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$pdo = db();
$thisYear = current_fiscal_year();

// ---- 学年の絞り込み ----
$e = my_entry_year();
$myEntry = grade_of($e) !== null ? $e : null; // 学年が出せる（入学年度が未来になっていない）ときだけ使う
$canNear = $myEntry !== null;

// 学年別のセレクトに出す入学年度（実際にいるメンバーの年度だけ）。新しい順 = 学年の小さい順
$entryYears = array_map('intval', $pdo->query('SELECT DISTINCT entry_year FROM member
    WHERE entry_year IS NOT NULL ORDER BY entry_year DESC')->fetchAll(PDO::FETCH_COLUMN));
$hasUnknown = (bool)$pdo->query('SELECT 1 FROM member WHERE entry_year IS NULL LIMIT 1')->fetchColumn();

$who = $_GET['who'] ?? 'all';
if (!in_array($who, ['all', 'near', 'grade'], true) || ($who === 'near' && !$canNear)) {
    $who = 'all';
}
// 学年別の初期値: 自分の学年 → いなければ一番新しい学年
$entryParam = (string)($_GET['entry'] ?? '');
if ($entryParam === 'none' && $hasUnknown) {
    $entry = 'none';
} elseif (in_array((int)$entryParam, $entryYears, true)) {
    $entry = (int)$entryParam;
} else {
    $entry = in_array($myEntry, $entryYears, true) ? $myEntry : ($entryYears[0] ?? 'none');
}

// 絞り込みの WHERE 部品とパラメータ（値は ? で渡す）
[$filterSql, $filterParams] = match (true) {
    $who === 'near'                       => [' AND m.entry_year BETWEEN ? AND ?', [$myEntry - 3, $myEntry + 3]],
    $who === 'grade' && $entry === 'none' => [' AND m.entry_year IS NULL', []],
    $who === 'grade'                      => [' AND m.entry_year = ?', [$entry]],
    default                               => ['', []],
};

/** 入学年度 → 「4年（2023年度入学）」のような表示 */
function entry_option_label(int $entryYear): string
{
    $grade = grade_of($entryYear);
    return ($grade !== null ? grade_label($grade) . '（' : '（') . $entryYear . '年度入学）';
}

$st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year,
        COUNT(DISTINCT bm.band_id) AS bands,
        COUNT(DISTINCT CASE WHEN b.play_order = last.max_order THEN b.band_id END) AS headliners,
        COUNT(DISTINCT ld.live_id) AS lives
    FROM member m
    JOIN (' . MEMBERSHIP_SQL . ') bm ON bm.member_id = m.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN (SELECT live_day_id, MAX(play_order) AS max_order FROM band GROUP BY live_day_id) last
        ON last.live_day_id = b.live_day_id
    WHERE 1 = 1' . $filterSql . '
    GROUP BY m.member_id
    ORDER BY bands DESC, headliners DESC, m.name');
$st->execute($filterParams);
$rows = $st->fetchAll();

// 一度も出演していないメンバー（名簿にだけいる人）も下に出す
$st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year FROM member m
    WHERE m.member_id NOT IN (SELECT member_id FROM (' . MEMBERSHIP_SQL . ') x)' . $filterSql . '
    ORDER BY m.name');
$st->execute($filterParams);
$idle = $st->fetchAll();
$totalMembers = (int)$pdo->query('SELECT COUNT(*) FROM member')->fetchColumn(); // 絞り込み前の人数（空表示の判定用）

// マイアルバムの上位5枚（member_id => [アルバム, ...]）。
//   sort_order は削除で番号が飛ぶことがあるので、ROW_NUMBER() で「その人の中で何番目か」を振り直して5番目までを取る。
//   1人ずつ SELECT すると人数ぶんクエリが飛ぶ（N+1 問題）ので、全員ぶんを1回で取ってから PHP で振り分ける。
$topAlbums = [];
$st = $pdo->query('SELECT member_id, title, artist_name, artwork_url FROM (
        SELECT member_id, sort_order, title, artist_name, artwork_url,
            ROW_NUMBER() OVER (PARTITION BY member_id ORDER BY sort_order) AS rn
        FROM member_favorite_album) x
    WHERE rn <= 5
    ORDER BY member_id, sort_order');
foreach ($st as $a) {
    $topAlbums[(int)$a['member_id']][] = $a;
}

// 担当楽器（member_id => ['Gt', 'Vo', ...]）。ライブで弾いたバンド数の多い順、同数なら楽器マスタの順。
//   マイアルバムと同じく、全員ぶんを1回で取ってから PHP で振り分ける（N+1 を避ける）
$instruments = [];
$st = $pdo->query('SELECT bm.member_id, i.short_name, i.name, COUNT(*) AS times
    FROM band_member bm
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    GROUP BY bm.member_id, i.instrument_id
    ORDER BY bm.member_id, times DESC, i.sort_order');
foreach ($st as $ins) {
    $instruments[(int)$ins['member_id']][] = $ins;
}

/** メンバー1人ぶんのジャケット（最大5枚）。無ければ「—」 */
function album_thumbs(array $albums): string
{
    if (!$albums) {
        return '<span class="muted small">—</span>';
    }
    // マウスを乗せたときのポップアップは app.js（data-album-tip）が data-tip-◯◯ を読んで出す
    $html = '<div class="thumbs" data-album-tip>';
    foreach ($albums as $a) {
        $html .= '<img class="thumbs__img" src="' . h($a['artwork_url']) . '" alt="' . h($a['title'] . ' / ' . $a['artist_name'])
            . '" data-tip-title="' . h($a['title']) . '" data-tip-artist="' . h($a['artist_name'])
            . '" tabindex="0" loading="lazy" width="40" height="40">';
    }
    return $html . '</div>';
}

// ---- 並べ替え（?sort=列&dir=asc|desc）----
//   列の見出しはただのリンクなので、JavaScript が無くても並べ替えられる。
//   値はホワイトリスト（SORT_COLUMNS のキー）にあるものだけ使う。ブラウザから来た値は信用しない。
const SORT_COLUMNS = [ // 列 => 最初にクリックしたときの向き
    'name'       => 'asc',
    'entry'      => 'desc', // 新しい年度（下の学年）から
    'bands'      => 'desc',
    'lives'      => 'desc',
    'headliners' => 'desc',
];
$sort = $_GET['sort'] ?? 'bands';
if (!is_string($sort) || !isset(SORT_COLUMNS[$sort])) {
    $sort = 'bands';
}
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : SORT_COLUMNS[$sort]);

/** 名前を並べるときの読み。ふりがながあればそれ、無ければ名前。カタカナはひらがなにそろえる（'c'） */
function name_reading(array $r): string
{
    return mb_convert_kana(($r['name_kana'] ?? '') ?: $r['name'], 'c');
}

// 出演ありと出演なしを1つのリストにまとめる。# は出演回数の順位のまま（並べ替えても変えない）
$list = [];
foreach ($rows as $i => $r) {
    $list[] = $r + ['rank' => $i + 1];
}
foreach ($idle as $r) {
    $list[] = $r + ['bands' => 0, 'lives' => 0, 'headliners' => 0, 'rank' => null];
}
// usort(配列, 比べる関数): 関数が負なら a が前、正なら b が前。<=> は「宇宙船演算子」で -1 / 0 / 1 を返す
usort($list, static function (array $a, array $b) use ($sort, $dir): int {
    if ($sort === 'entry') {
        // 入学年度が不明な人は、向きに関係なく一番下
        $na = $a['entry_year'] === null;
        $nb = $b['entry_year'] === null;
        if ($na !== $nb) {
            return $na <=> $nb;
        }
    }
    $cmp = match ($sort) {
        'name'  => strcmp(name_reading($a), name_reading($b)),
        'entry' => (int)$a['entry_year'] <=> (int)$b['entry_year'],
        default => (int)$a[$sort] <=> (int)$b[$sort],
    };
    if ($dir === 'desc') {
        $cmp = -$cmp;
    }
    // 同じ値なら、出演の順位 → ふりがなの順
    return $cmp ?: (($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX)) ?: strcmp(name_reading($a), name_reading($b));
});

/** 並べ替えできる列の見出し。今の並びの列には ▲▼ を付け、もう一度押すと逆向きになる */
function sort_th(string $col, string $label, string $sort, string $dir, string $class = ''): string
{
    $next = $col === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : SORT_COLUMNS[$col];
    // 学年の絞り込み（who / entry）はそのまま引き継ぐ
    $query = http_build_query(array_intersect_key($_GET, ['who' => 1, 'entry' => 1]) + ['sort' => $col, 'dir' => $next]);
    $aria = $col === $sort ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
    $arrow = $col === $sort ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<th class="' . h(trim('sortable ' . $class)) . '"' . $aria . '><a href="?' . h($query) . '">' . h($label)
        . '<span class="sortable__arrow">' . $arrow . '</span></a></th>';
}
render_header('メンバー', 'members');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Members</p>
        <h1 class="display">メンバー</h1>
    </div>
    <dl class="stats"><div><dt>出演者</dt><dd><?= count($rows) ?></dd></div><div><dt>登録</dt><dd><?= count($rows) + count($idle) ?></dd></div></dl>
</section>

<!-- 学年の絞り込み（GET）。見た目と動きは集計ページと同じ部品（.stats-filter / data-autosubmit） -->
<?php if ($totalMembers > 0): ?>
<form method="get" class="card stats-filter no-print">
    <fieldset class="stats-filter__row">
        <legend>学年</legend>
        <div class="tabs tabs--filter" role="radiogroup" aria-label="学年">
            <label class="tab"><input type="radio" name="who" value="all" data-autosubmit<?= $who === 'all' ? ' checked' : '' ?>>全学年</label>
            <label class="tab<?= $canNear ? '' : ' is-disabled' ?>"<?= $canNear ? '' : ' title="アカウントがメンバーに紐付いていないか、入学年度が未登録のため使えません"' ?>>
                <input type="radio" name="who" value="near" data-autosubmit<?= $who === 'near' ? ' checked' : '' ?><?= $canNear ? '' : ' disabled' ?>>上下3学年
            </label>
            <label class="tab"><input type="radio" name="who" value="grade" data-autosubmit<?= $who === 'grade' ? ' checked' : '' ?>>学年別</label>
        </div>
        <div class="stats-filter__extra" data-show-when="who=grade">
            <select name="entry" aria-label="学年" data-autosubmit>
                <?php foreach ($entryYears as $y): ?>
                    <option value="<?= $y ?>"<?= $entry === $y ? ' selected' : '' ?>><?= h(entry_option_label($y)) ?></option>
                <?php endforeach; ?>
                <?php if ($hasUnknown): ?><option value="none"<?= $entry === 'none' ? ' selected' : '' ?>>入学年度が不明</option><?php endif; ?>
            </select>
        </div>
        <?php if ($who === 'near'): ?>
            <span class="muted small"><?= h(entry_option_label($myEntry - 3)) ?> 〜 <?= h(entry_option_label($myEntry + 3)) ?></span>
        <?php endif; ?>
    </fieldset>
    <noscript><button class="btn btn--sm" type="submit">表示</button></noscript>
</form>
<?php endif; ?>

<?php if (!$rows && !$idle && $totalMembers > 0): ?>
    <div class="empty card"><p class="empty__title">この学年のメンバーはいません</p></div>
<?php elseif (!$rows && !$idle): ?>
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
            <thead><tr>
                <th class="num">#</th>
                <?= sort_th('name', '名前', $sort, $dir) ?>
                <?= sort_th('entry', '入学', $sort, $dir, 'num') ?>
                <th>担当楽器</th>
                <th>マイアルバム</th>
                <?= sort_th('bands', '出演', $sort, $dir, 'num') ?>
                <?= sort_th('lives', 'ライブ', $sort, $dir, 'num hide-sm') ?>
                <?= sort_th('headliners', 'トリ', $sort, $dir, 'num') ?>
            </tr></thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <tr class="member-row<?= (int)$r['member_id'] === $user['member_id'] ? ' is-me' : '' ?>" data-text="<?= h($r['name'] . ' ' . ($r['name_kana'] ?? '')) ?>">
                    <td class="num muted"><?= $r['rank'] ?? '—' ?></td>
                    <td><a href="member.php?id=<?= (int)$r['member_id'] ?>"<?= $r['rank'] ? ' class="strong"' : '' ?>><?= h($r['name']) ?></a></td>
                    <!-- 2023 → '23（下2桁だけ。sprintf の %02d で 2005 → '05 のように0を残す） -->
                    <td class="num muted"><?= $r['entry_year'] !== null ? sprintf("'%02d", (int)$r['entry_year'] % 100) : '—' ?></td>
                    <td><?= part_marks($instruments[(int)$r['member_id']] ?? [], false, 'partbar--cell') ?></td>
                    <td><?= album_thumbs($topAlbums[(int)$r['member_id']] ?? []) ?></td>
                    <?php if ($r['rank']): ?>
                        <td class="num strong"><?= (int)$r['bands'] ?></td>
                        <td class="num hide-sm"><?= (int)$r['lives'] ?></td>
                        <td class="num"><?= (int)$r['headliners'] ?: '<span class="muted">0</span>' ?></td>
                    <?php else: ?>
                        <td class="num muted small">—</td><td class="hide-sm"></td><td></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
