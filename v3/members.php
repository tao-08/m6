<?php
/**
 * =====================================================================
 *  members.php — メンバー一覧（出演回数ランキング + マイアルバム上位5枚）
 * =====================================================================
 *  「トリ」= その日の play_order が一番大きいバンド。
 *  日程ごとの最大の play_order をサブクエリ（last = HEADLINER_SQL）で先に求めておき、JOIN して比べている。
 *  総バンド数まで登録されていない日程は last に出てこない（トリなし）ので LEFT JOIN。
 *
 *  上の「学年」タブで表示するメンバーを絞り込める（assets/app.js の setupGradeSlot）
 *    全学年    … 入学年度が不明な人も含む
 *    上下3学年 … ログイン中ユーザーの入学年度 ±3（自分の学年も含む）
 *    学年別    … 学年スロット（縦ドラッグ）で選んだ入学年度の人 / 不明 … 入学年度が不明な人
 *  全員ぶんの行を出しておき、JS で隠すだけ（ページを読み直さない）。順位・人数も JS で数え直す。
 *  ?who=all|near|grade&entry=入学年度|none は「最初にどの段を選んでおくか」にだけ使う
 *  （JS が切り替えのたびに URL に書くので、再読み込み・並べ替えしても学年が戻らない）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
$user = require_login();
$pdo = db();
$thisYear = current_fiscal_year();

// ---- 学年の絞り込み ----
$e = my_entry_year();
$myEntry = grade_of($e) !== null ? $e : null; // 学年が出せる（入学年度が未来になっていない）ときだけ使う
$canNear = $myEntry !== null;

// 学年スロットに出す入学年度（実際にいるメンバーの年度だけ）。新しい順 = 学年の小さい順
$entryYears = array_map('intval', $pdo->query('SELECT DISTINCT entry_year FROM member
    WHERE entry_year IS NOT NULL ORDER BY entry_year DESC')->fetchAll(PDO::FETCH_COLUMN));
$hasUnknown = (bool)$pdo->query('SELECT 1 FROM member WHERE entry_year IS NULL LIMIT 1')->fetchColumn();

$who = $_GET['who'] ?? 'all';
if (!in_array($who, ['all', 'near', 'grade'], true) || ($who === 'near' && !$canNear)) {
    $who = 'all';
}
// 学年スロットで最初に選んでおく段（入学年度 / 'none'）: ?entry= → 自分の学年 → 一番新しい学年
$entryParam = (string)($_GET['entry'] ?? '');
$slotValue = match (true) {
    $entryParam === 'none' && $hasUnknown         => 'none',
    in_array((int)$entryParam, $entryYears, true) => (string)(int)$entryParam,
    in_array($myEntry, $entryYears, true)         => (string)$myEntry,
    default                                       => (string)($entryYears[0] ?? 'none'),
};

/** 入学年度 → 「4年（2023年度入学）」のような表示 */
function entry_option_label(int $entryYear): string
{
    $grade = grade_of($entryYear);
    return ($grade !== null ? grade_label($grade) . '（' : '（') . $entryYear . '年度入学）';
}

/** 学年スロットの1段。$value が今の段なら is-current を付ける（app.js はそこから始める） */
function grade_slot_item(string $value, string $labelHtml, string $current, string $attrs = ''): string
{
    return '<div class="year-slot__item' . ($value === $current ? ' is-current' : '') . '" data-value="' . h($value) . '"'
        . $attrs . '>' . $labelHtml . '</div>';
}

$st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year,
        COUNT(DISTINCT bm.band_id) AS bands,
        COUNT(DISTINCT CASE WHEN b.play_order = last.max_order THEN b.band_id END) AS headliners,
        COUNT(DISTINCT ld.live_id) AS lives
    FROM member m
    JOIN (' . MEMBERSHIP_SQL . ') bm ON bm.member_id = m.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    LEFT JOIN (' . HEADLINER_SQL . ') last
        ON last.live_day_id = b.live_day_id
    GROUP BY m.member_id
    ORDER BY bands DESC, headliners DESC, m.name');
$st->execute();
$rows = $st->fetchAll();

// 一度も出演していないメンバー（名簿にだけいる人）も下に出す
$st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year FROM member m
    WHERE m.member_id NOT IN (SELECT member_id FROM (' . MEMBERSHIP_SQL . ') x)
    ORDER BY m.name');
$st->execute();
$idle = $st->fetchAll();

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

// 担当楽器（member_id => tally_parts() の結果）。ライブで弾いたバンド数の多い順、同数なら楽器の並び順。
//   Vo と Gt を両方やったバンドは「Vo/Gt」として数える（lineup_parts_by_band）。
//   マイアルバムと同じく、全員ぶんを1回で取ってから PHP で振り分ける（N+1 を避ける）
$st = $pdo->query('SELECT bm.band_id, bm.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    ORDER BY i.sort_order');
$partsByMember = [];
foreach (lineup_parts_by_band($st) as $p) {
    $partsByMember[$p['member_id']][] = $p;
}
$instruments = array_map(static fn($parts) => sort_tally_by_count(tally_parts($parts)), $partsByMember);

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
//   値はホワイトリスト（SORT_COLUMNS）にあるものだけ使う。ブラウザから来た値は信用しない。
//   見出しを押すたびに 最初の向き → 逆の向き → 解除（いつもの並び）。最初の向きは名前だけ昇順（あ→わ）、ほかは降順
//   いつもの並び（$sort === ''）: 自分が一番上 → 学年の降順（上の学年から。入学年度が不明な人は一番下）→ 名前の昇順
const SORT_COLUMNS = [ // 列 => 最初に押したときの向き
    'name'       => 'asc',
    'entry'      => 'desc',
    'bands'      => 'desc',
    'lives'      => 'desc',
    'headliners' => 'desc',
];
$sort = $_GET['sort'] ?? '';
if (!is_string($sort) || !isset(SORT_COLUMNS[$sort])) {
    $sort = '';
}
$dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : (SORT_COLUMNS[$sort] ?? 'desc');
$myId = $user['member_id'];

/** 名前を並べるときの読み。ふりがながあればそれ、無ければ名前。カタカナはひらがなにそろえる（'c'） */
function name_reading(array $r): string
{
    return mb_convert_kana(($r['name_kana'] ?? '') ?: $r['name'], 'c');
}

// # の順位: 出演・ライブ・トリで並べ替えたときだけ、その列の多い順のランキングを # の列に出す（$showRank）
//   それ以外（いつもの並び・名前・入学）は # の列を空にする（列の幅は残して、並びを変えても表がずれないように）。順位は同じ値のときの並びの決め手にだけ使う（出演の多い順）
//   昇順に並べても順位は「多い人が1位」のまま（表の上から # が大きい順に並ぶ）
//   同じ値なら同じ順位で、次はその人数分とばす（10, 8, 8, 5 → 1, 2, 2, 4）。出演なしの人は順位なし（—）
//   学年で絞り込んだときは JS が同じ決まりで、見えている人の中で付け直す（data-score）
$showRank = in_array($sort, ['bands', 'lives', 'headliners'], true);
$rankCol = $showRank ? $sort : 'bands';
$scores = array_map(static fn($r) => (int)$r[$rankCol], $rows);
$ranks = [];
foreach ($scores as $i => $mine) {
    $ranks[$i] = 1 + count(array_filter($scores, static fn($n) => $n > $mine)); // 自分より多い人の数 + 1
}
$list = [];
foreach ($rows as $i => $r) {
    $list[] = $r + ['rank' => $ranks[$i]];
}
foreach ($idle as $r) {
    $list[] = $r + ['bands' => 0, 'lives' => 0, 'headliners' => 0, 'rank' => null];
}
// usort(配列, 比べる関数): 関数が負なら a が前、正なら b が前。<=> は「宇宙船演算子」で -1 / 0 / 1 を返す
usort($list, static function (array $a, array $b) use ($sort, $dir, $myId): int {
    if ($sort === '' || $sort === 'entry') {
        if ($sort === '') {
            // いつもの並びでは自分が一番上（true <=> false で自分が前に来るよう、b と a を逆に比べる）
            $me = ((int)$b['member_id'] === $myId) <=> ((int)$a['member_id'] === $myId);
            if ($me !== 0) {
                return $me;
            }
        }
        // 入学年度が不明な人は、向きに関係なく一番下
        $na = $a['entry_year'] === null;
        $nb = $b['entry_year'] === null;
        if ($na !== $nb) {
            return $na <=> $nb;
        }
    }
    if ($sort === '') {
        // 学年の降順 = 入学年度の古い順。同じ学年なら名前の順
        return ((int)$a['entry_year'] <=> (int)$b['entry_year']) ?: strcmp(name_reading($a), name_reading($b));
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

/** 並べ替えできる列の見出し。今の並びの列には ▲▼ を付ける。押すたびに 最初の向き → 逆の向き → 解除 */
function sort_th(string $col, string $label, string $sort, string $dir, string $class = ''): string
{
    $first = SORT_COLUMNS[$col];
    $next = match (true) {
        $col !== $sort  => ['sort' => $col, 'dir' => $first],
        $dir === $first => ['sort' => $col, 'dir' => $first === 'asc' ? 'desc' : 'asc'],
        default         => [], // 解除 → いつもの並び
    };
    // 学年の絞り込み（who / entry）はそのまま引き継ぐ
    $query = http_build_query(array_intersect_key($_GET, ['who' => 1, 'entry' => 1]) + $next);
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
    <!-- data-grade-count: 学年を切り替えたら JS が数え直す -->
    <dl class="stats"><div><dt>出演者</dt><dd data-grade-count="played"><?= count($rows) ?></dd></div><div><dt>登録</dt><dd data-grade-count="all"><?= count($rows) + count($idle) ?></dd></div></dl>
</section>

<?php if (!$rows && !$idle): ?>
    <div class="empty card">
        <p class="empty__title">まだメンバーがいません</p>
        <a class="btn btn--primary" href="member_new">＋ メンバーを追加</a>
    </div>
<?php else: ?>
    <!-- 学年の絞り込み。見た目は集計ページと同じ部品（.stats-filter）。
         タブ・スロットを切り替えると、ページを読み直さずに JS が行を隠す（assets/app.js の setupGradeSlot） -->
    <div class="card stats-filter no-print" data-grade-filter>
        <fieldset class="stats-filter__row">
            <legend>学年</legend>
            <div class="tabs tabs--filter" role="radiogroup" aria-label="学年">
                <label class="tab"><input type="radio" name="who" value="all"<?= $who === 'all' ? ' checked' : '' ?>>全学年</label>
                <label class="tab<?= $canNear ? '' : ' is-disabled' ?>"<?= $canNear ? '' : ' title="アカウントがメンバーに紐付いていないか、入学年度が未登録のため使えません"' ?>>
                    <input type="radio" name="who" value="near"<?= $who === 'near' ? ' checked' : '' ?><?= $canNear ? ' data-min="' . ($myEntry - 3) . '" data-max="' . ($myEntry + 3) . '"' : ' disabled' ?>>上下3学年
                </label>
                <label class="tab"><input type="radio" name="who" value="grade"<?= $who === 'grade' ? ' checked' : '' ?>>学年別</label>
            </div>
            <!-- data-grade-slot: 縦にドラッグ（ホイール・↑↓キー）で学年を切り替える。部品はライブ一覧の年度スロットと同じ -->
            <div class="stats-filter__extra" data-show-when="who=grade">
                <div class="year-slot year-slot--grade" data-grade-slot tabindex="0" role="spinbutton" aria-label="学年" title="上下にドラッグで学年を切り替え">
                    <div class="year-slot__reel">
                        <!-- 学年の降順（上の学年 = 古い入学年度が上）。表のいつもの並びとそろえる -->
                        <?php foreach (array_reverse($entryYears) as $y): $grade = grade_of($y); ?>
                            <!-- 3年<small>'24</small> のように、学年と入学年度の下2桁 -->
                            <?= grade_slot_item((string)$y, ($grade !== null ? h(grade_label($grade)) : '') . sprintf("<small>'%02d</small>", $y % 100), $slotValue) ?>
                        <?php endforeach; ?>
                        <?php if ($hasUnknown): ?>
                            <?= grade_slot_item('none', '不明', $slotValue) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($canNear): ?>
                <span class="muted small" data-show-when="who=near"><?= h(entry_option_label($myEntry - 3)) ?> 〜 <?= h(entry_option_label($myEntry + 3)) ?></span>
            <?php endif; ?>
        </fieldset>
    </div>
    <div class="toolbar">
        <input type="search" class="search" placeholder="名前で検索" data-filter=".member-row" aria-label="名前で検索">
        <a class="btn btn--primary" href="member_new">＋ 新規追加</a>
    </div>
    <div class="empty card" data-grade-empty hidden><p class="empty__title">この学年のメンバーはいません</p></div>
    <div class="card table-card" data-grade-table>
        <table class="table table--list table--members">
            <thead><tr>
                <th class="num"><?= $showRank ? '#' : '' ?></th>
                <?= sort_th('name', '名前', $sort, $dir) ?>
                <?= sort_th('entry', '入学', $sort, $dir, 'num') ?>
                <th>担当楽器</th>
                <th>マイアルバム Top5</th>
                <?= sort_th('bands', '出演', $sort, $dir, 'num') ?>
                <?= sort_th('lives', 'ライブ', $sort, $dir, 'num hide-sm') ?>
                <?= sort_th('headliners', 'トリ', $sort, $dir, 'num') ?>
                <th class="name-end">名前</th><!-- スマホだけ右端にも名前（横にスクロールして数字を見ているときに、誰の数字かわかるように） -->
                <?php if ($showRank): ?><th class="num rank-end">#</th><?php endif; ?><!-- スマホでランキングのときだけ、右端の名前のさらに右にも # -->
            </tr></thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <!-- is-me: 自分の行はいつも背景を変える（一番上に固定するのは、いつもの並びのときだけ）
                     data-entry / data-bands / data-score: 学年の絞り込みと順位の付け直しに使う（入学年度が不明なら空。data-score は順位を付ける列の値） -->
                <tr class="member-row<?= (int)$r['member_id'] === $myId ? ' is-me' : '' ?>" data-text="<?= h($r['name'] . ' ' . ($r['name_kana'] ?? '')) ?>"
                    data-entry="<?= $r['entry_year'] !== null ? (int)$r['entry_year'] : '' ?>" data-bands="<?= (int)$r['bands'] ?>" data-score="<?= (int)$r[$rankCol] ?>">
                    <!-- data-rank: 学年で絞り込んだとき JS が順位を書き直す目印。ランキングでないときは付けない（空のまま） -->
                    <?php if ($showRank): ?><td class="num muted" data-rank><?= $r['rank'] ?? '—' ?></td><?php else: ?><td class="num"></td><?php endif; ?>
                    <td><a href="member?id=<?= (int)$r['member_id'] ?>"<?= $r['rank'] ? ' class="strong"' : '' ?>><?= h($r['name']) ?></a></td>
                    <!-- 2023 → '23（下2桁だけ。sprintf の %02d で 2005 → '05 のように0を残す） -->
                    <td class="num muted"><?= $r['entry_year'] !== null ? sprintf("'%02d", (int)$r['entry_year'] % 100) : '—' ?></td>
                    <td><?= part_marks($instruments[(int)$r['member_id']] ?? [], false, 'partbar--cell', 3) ?></td>
                    <td><?= album_thumbs($topAlbums[(int)$r['member_id']] ?? []) ?></td>
                    <?php if ($r['rank']): ?>
                        <td class="num strong"><?= (int)$r['bands'] ?></td>
                        <td class="num hide-sm"><?= (int)$r['lives'] ?></td>
                        <td class="num"><?= (int)$r['headliners'] ?: '<span class="muted">0</span>' ?></td>
                    <?php else: ?>
                        <td class="num muted small">—</td><td class="hide-sm"></td><td></td>
                    <?php endif; ?>
                    <td class="name-end"><a href="member?id=<?= (int)$r['member_id'] ?>"<?= $r['rank'] ? ' class="strong"' : '' ?>><?= h($r['name']) ?></a></td>
                    <?php if ($showRank): ?><td class="num muted rank-end" data-rank><?= $r['rank'] ?? '—' ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
