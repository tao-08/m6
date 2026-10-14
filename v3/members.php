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
 *    現役      … 1〜4年（入学年度が 今年度-3 〜 今年度）
 *    卒業生    … 5年目以上（OB。入学年度が 今年度-4 以前）。入学年度が不明な人はどちらにも入れない
 *    上下3学年 … ログイン中ユーザーの入学年度 ±3（自分の学年も含む）
 *    学年別    … 学年スロット（縦ドラッグ）で選んだ入学年度の人 / 不明 … 入学年度が不明な人
 *  「退会した人を表示しない」にチェック（初期値）すると、member.retired = 1 の人も隠す（?retired=show で外した状態）
 *  全員ぶんの行を出しておき、JS で隠すだけ（ページを読み直さない）。順位・人数も JS で数え直す。
 *  ?who=all|active|alumni|near|grade&entry=入学年度|none は「最初にどの段を選んでおくか」にだけ使う
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

// ?who= が無いときの最初のタブ: 自分が現役（1〜4年）なら「現役」、卒業生・入学年度が不明なら「全学年」
$myGrade = grade_of($myEntry);
$who = $_GET['who'] ?? ($myGrade !== null && $myGrade <= 4 ? 'active' : 'all');
if (!in_array($who, ['all', 'active', 'alumni', 'near', 'grade'], true) || ($who === 'near' && !$canNear)) {
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
// 退会した人を隠すか。初期値は隠す。チェックを外すと JS が URL に ?retired=show を書く
$hideRetired = ($_GET['retired'] ?? '') !== 'show';
$hasRetired = (bool)$pdo->query('SELECT 1 FROM member WHERE retired = 1 LIMIT 1')->fetchColumn();

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

$st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year, m.retired,
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
$st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year, m.retired FROM member m
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
//   Gt/Cho（弾きながらコーラス）は Gt として数えて、Cho は出さない（コーラスだけで出たバンドは Cho）
//   マイアルバムと同じく、全員ぶんを1回で取ってから PHP で振り分ける（N+1 を避ける）
$st = $pdo->query('SELECT bm.band_id, bm.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    ORDER BY i.sort_order');
$partsByMember = [];
foreach (lineup_parts_by_band($st, true, 'drop') as $p) {
    $partsByMember[$p['member_id']][] = $p;
}
$instruments = array_map(static fn($parts) => sort_tally_by_count(tally_parts($parts)), $partsByMember);
// 担当楽器フィルターの選択肢と、行ごとの楽器（data-insts）
$instSegs = [];
$instsByMember = [];
foreach ($partsByMember as $mid => $parts) {
    foreach ($parts as $p) {
        foreach ($p['segments'] as $sg) {
            $instSegs[$sg['class']] = [$sg['short'], $p['order']];
            $instsByMember[$mid][$sg['class']] = true;
        }
    }
}
uasort($instSegs, static fn($a, $b) => $a[1] <=> $b[1]);
$instSegs = array_map(static fn($v) => $v[0], $instSegs);

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
//   開いたときは PHP がこの GET で並べる。見出しを押したときは JS が再読み込みなしで並べ替える（data-pos。lib/bootstrap.php の sort_positions）
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
// 表を右端まで動かした状態で開くか: ランキングの並び、またはその列の並べ替えを解除して来たとき（?scroll=end）
$scrollEnd = $showRank || ($_GET['scroll'] ?? '') === 'end';
$rankCol = $showRank ? $sort : 'bands';
const RANK_COLUMNS = ['bands', 'lives', 'headliners'];
// 3つの列それぞれの順位（JS で並べ替えたときに、# の列をその列の順位に書き換える）
$ranksBy = [];
foreach (RANK_COLUMNS as $col) {
    $scores = array_map(static fn($r) => (int)$r[$col], $rows);
    foreach ($scores as $i => $mine) {
        $ranksBy[$i][$col] = 1 + count(array_filter($scores, static fn($n) => $n > $mine)); // 自分より多い人の数 + 1
    }
}
$list = [];
foreach ($rows as $i => $r) {
    $list[] = $r + ['rank' => $ranksBy[$i][$rankCol], 'ranks' => $ranksBy[$i]];
}
foreach ($idle as $r) {
    $list[] = $r + ['bands' => 0, 'lives' => 0, 'headliners' => 0, 'rank' => null, 'ranks' => null];
}
// usort(配列, 比べる関数): 関数が負なら a が前、正なら b が前。<=> は「宇宙船演算子」で -1 / 0 / 1 を返す
$memberCmp = static function (array $a, array $b, string $sort, string $dir) use ($myId): int {
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
        return ((int)$a['entry_year'] <=> (int)$b['entry_year']) ?: strcmp(name_reading($a), name_reading($b))
            ?: (int)$a['member_id'] <=> (int)$b['member_id'];
    }
    $cmp = match ($sort) {
        'name'  => strcmp(name_reading($a), name_reading($b)),
        'entry' => (int)$a['entry_year'] <=> (int)$b['entry_year'],
        default => (int)$a[$sort] <=> (int)$b[$sort],
    };
    if ($dir === 'desc') {
        $cmp = -$cmp;
    }
    // 同じ値なら、順位（ランキングの列ならその列、ほかは出演）→ ふりがなの順
    $rc = in_array($sort, RANK_COLUMNS, true) ? $sort : 'bands';
    // 最後に member_id（読みまで同じ人がいても、どこから並べても同じ順になるように。sort_positions の並びと GET の並びをそろえる）
    return $cmp ?: (($a['ranks'][$rc] ?? PHP_INT_MAX) <=> ($b['ranks'][$rc] ?? PHP_INT_MAX)) ?: strcmp(name_reading($a), name_reading($b))
        ?: (int)$a['member_id'] <=> (int)$b['member_id'];
};
usort($list, static fn($a, $b) => $memberCmp($a, $b, $sort, $dir));
$positions = sort_positions($list, sort_keys(SORT_COLUMNS, true), $memberCmp);

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
                <?php $fy = current_fiscal_year(); // data-min / data-max: この入学年度の範囲の人だけ出す（app.js の setupGradeSlot。片方だけなら上限・下限なし） ?>
                <label class="tab"><input type="radio" name="who" value="active"<?= $who === 'active' ? ' checked' : '' ?> data-min="<?= $fy - 3 ?>" data-max="<?= $fy ?>">現役</label>
                <label class="tab"><input type="radio" name="who" value="alumni"<?= $who === 'alumni' ? ' checked' : '' ?> data-max="<?= $fy - 4 ?>">卒業生</label>
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
            <?php if ($hasRetired): // 退会した人が1人もいなければ、押しても何も変わらないので出さない ?>
                <!-- data-hide-retired: 切り替えたら JS が行を隠し直す（setupGradeSlot） -->
                <label class="stats-filter__extra small"><input type="checkbox" data-hide-retired<?= $hideRetired ? ' checked' : '' ?>> 退会者を非表示</label>
            <?php endif; ?>
        </fieldset>
    </div>
    <div class="toolbar">
        <input type="search" class="search" placeholder="名前で検索" data-filter=".member-row" aria-label="名前で検索">
        <a class="btn btn--primary" href="member_new">＋ 新規追加</a>
    </div>
    <div class="empty card" data-grade-empty hidden><p class="empty__title">この学年のメンバーはいません</p></div>
    <!-- data-scroll-end: ランキングで並べたとき・その並べ替えを解除したときは、表を右端まで動かした状態で開く
         （スマホで出演の数字と右端の名前・# がすぐ見えるように） -->
    <div class="card table-card" data-grade-table<?= $scrollEnd ? ' data-scroll-end' : '' ?>>
        <!-- data-sort-clear: 見出しを押すたびに 最初の向き → 逆の向き → 解除（いつもの並び）。is-ranking: 出演・ライブ・トリで並べているとき（# に順位を出す） -->
        <table class="table table--list table--members<?= $showRank ? ' is-ranking' : '' ?>" data-client-sort data-sort-clear data-sort-current="<?= h($sort) ?>" data-dir-current="<?= h($sort === '' ? '' : $dir) ?>">
            <thead><tr>
                <th class="num" data-rank-head><?= $showRank ? '#' : '' ?></th>
                <?= sort_button_th('name', '名前', $sort, $dir, SORT_COLUMNS['name']) ?>
                <?= sort_button_th('entry', '入学', $sort, $dir, SORT_COLUMNS['entry'], 'num') ?>
                <th><span class="th-inst"><?= inst_filter($instSegs, '.table--members', false, '.member-row') ?>担当楽器</span></th>
                <th>マイアルバム Top5</th>
                <?php // 右側の列（出演・ライブ・トリ）の並べ替えを解除したときは、再読み込みしても表を右端に置いておく（?scroll=end） ?>
                <?= sort_button_th('bands', '出演', $sort, $dir, SORT_COLUMNS['bands'], 'num', ' data-sort-clear-scroll') ?>
                <?= sort_button_th('lives', 'ライブ', $sort, $dir, SORT_COLUMNS['lives'], 'num hide-sm', ' data-sort-clear-scroll') ?>
                <?= sort_button_th('headliners', 'トリ', $sort, $dir, SORT_COLUMNS['headliners'], 'num', ' data-sort-clear-scroll') ?>
                <th class="name-end">名前</th><!-- スマホだけ右端にも名前（横にスクロールして数字を見ているときに、誰の数字かわかるように） -->
                <th class="num rank-end">#</th><!-- スマホでランキングのときだけ（is-ranking）、右端の名前のさらに右にも # -->
            </tr></thead>
            <tbody>
            <?php foreach ($list as $i => $r): ?>
                <!-- is-me: 自分の行はいつも背景を変える（一番上に固定するのは、いつもの並びのときだけ）
                     data-entry / data-bands / data-score: 学年の絞り込みと順位の付け直しに使う（入学年度が不明なら空。data-score は順位を付ける列の値）
                     data-retired: 退会済みの人だけ付ける（「退会した人を表示しない」で隠す） -->
                <tr class="member-row<?= (int)$r['member_id'] === $myId ? ' is-me' : '' ?>" data-text="<?= h($r['name'] . ' ' . ($r['name_kana'] ?? '')) ?>"
                    data-entry="<?= $r['entry_year'] !== null ? (int)$r['entry_year'] : '' ?>" data-bands="<?= (int)$r['bands'] ?>" data-score="<?= (int)$r[$rankCol] ?>"
                    data-score-bands="<?= (int)$r['bands'] ?>" data-score-lives="<?= (int)$r['lives'] ?>" data-score-headliners="<?= (int)$r['headliners'] ?>"
                    data-pos="<?= h(json_encode($positions[$i])) ?>"<?= (int)$r['retired'] === 1 ? ' data-retired' : '' ?> data-insts="<?= h(implode(' ', array_keys($instsByMember[(int)$r['member_id']] ?? []))) ?>">
                    <!-- data-rank: 学年で絞り込んだとき・並べ替えたとき JS が順位を書き直す目印。ランキングでないときは空 -->
                    <td class="num muted" data-rank><?= $showRank ? ($r['rank'] ?? '—') : '' ?></td>
                    <td><a href="member?id=<?= (int)$r['member_id'] ?>"<?= $r['rank'] ? ' class="strong"' : '' ?>><?= h($r['name']) ?></a><?= (int)$r['retired'] === 1 ? ' <span class="muted small">退会</span>' : '' ?></td>
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
                    <td class="num muted rank-end" data-rank><?= $showRank ? ($r['rank'] ?? '—') : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
