<?php
/**
 * =====================================================================
 *  stats.php — 集計ページ
 * =====================================================================
 *  README に書いてあった「特に多い組み合わせ」「出演回数が多い人」「トリが多い人」を見える化する。
 *
 *  絞り込みは2軸で独立している:
 *    ■ メンバー（?who=）… 人で絞る。個人ランキング・ペア・トリ回数に効く
 *        all    … 全メンバー
 *        active … 現役（入学年度が 今年度-3 〜 今年度）
 *        near   … 上下3学年（ログイン中ユーザーの入学年度 ±3）
 *        custom … セルフフィルター（?efrom= 〜 ?eto= の入学年度）
 *    ■ 期間（?period=）… ライブの年度で絞る。全部の集計に効く
 *        all    … 全期間
 *        year   … 単年度（?year=）
 *        range  … ユーザーフィルター（?pfrom= 〜 ?pto= の年度）
 *
 *  ?who= が無いときは、ログイン中ユーザーの学年でデフォルトを決める:
 *    1〜4年生 → 現役 / OB（5年目以降）→ 上下3学年 / 入学年度が不明 → 全メンバー
 *
 *  ポイント: 絞り込みを全部の SQL で使い回すため、
 *  WHERE の部品（$yearSql / $memberSql）とパラメータ（$yearParams / $memberParams）を変数にしておいて SQL に足している。
 *  （値そのものを SQL 文字列に埋め込まず、? で渡しているので安全）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_login();

$pdo = db();
$years = array_map('intval', $pdo->query('SELECT DISTINCT fiscal_year FROM live ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN));

$thisYear = current_fiscal_year(); // 今年度（lib/bootstrap.php）

/** $_GET から整数を取り出す。無い・数字じゃない → null */
function get_int(string $key): ?int
{
    // filter_var(..., FILTER_VALIDATE_INT) は「整数として正しい文字列」なら int、ダメなら false を返す
    $v = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    return is_int($v) ? $v : null;
}

// ログイン中ユーザーの入学年度と学年（入学年度が不明なら両方 null）
$myGrade = grade_of(my_entry_year());
$myEntry = $myGrade !== null ? $thisYear - $myGrade + 1 : null; // 学年から逆算（DB を2回引かない）

// =====================================================================
//  メンバーの絞り込み（?who=）
// =====================================================================
$activeFrom = $thisYear - 3;
$canNear = $myEntry !== null && $myGrade !== null;

$defaultWho = match (true) {
    $myGrade === null => 'all',
    $myGrade <= 4     => 'active',
    default           => 'near',
};
$who = $_GET['who'] ?? $defaultWho;
if (!in_array($who, ['all', 'active', 'near', 'custom'], true) || ($who === 'near' && !$canNear)) {
    $who = $defaultWho;
}

// セルフフィルター用の入学年度範囲。未指定なら「現役」と同じ範囲を初期値にする
$entryMin = $thisYear - 15; // セレクトに出す一番古い入学年度（必要なら増やす）
$efrom = get_int('efrom') ?? $activeFrom;
$eto   = get_int('eto')   ?? $thisYear;
if ($efrom > $eto) {
    [$efrom, $eto] = [$eto, $efrom]; // 逆に選ばれたら入れ替える
}

// who ごとの「入学年度の範囲」。all は範囲なし（null）
[$entryFrom, $entryTo] = match ($who) {
    'active' => [$activeFrom, $thisYear],
    'near'   => [$myEntry - 3, $myEntry + 3],
    'custom' => [$efrom, $eto],
    default  => [null, null],
};
// 入学年度が未登録（entry_year が NULL）の人も含めるか（?unknown=）。
//   入学年度はアカウント登録時に入るので、アカウントに紐付いていない名簿の人はほぼ全員 NULL。
//   NULL は BETWEEN が成立せず消えてしまうので、初期値は「含める」にしている。
//   チェックボックスは外すと何も送られないため、フォーム側で hidden の unknown=0 を先に置いている。
$includeUnknown = ($_GET['unknown'] ?? '1') !== '0';

$memberSql = match (true) {
    $entryFrom === null => '',
    $includeUnknown     => ' AND (m.entry_year BETWEEN ? AND ? OR m.entry_year IS NULL)',
    default             => ' AND m.entry_year BETWEEN ? AND ?',
};
$memberParams = $entryFrom !== null ? [$entryFrom, $entryTo] : [];

// =====================================================================
//  期間の絞り込み（?period=）
// =====================================================================
$period = $_GET['period'] ?? 'all';
if (!in_array($period, ['all', 'year', 'range'], true)) {
    $period = 'all';
}
$year = get_int('year');
if ($year === null || !in_array($year, $years, true)) {
    $year = $years[0] ?? $thisYear; // 単年度の初期値は一番新しい年度
}
$oldest = $years ? min($years) : $thisYear;
$newest = $years ? max($years) : $thisYear;
$pfrom = get_int('pfrom') ?? $oldest;
$pto   = get_int('pto')   ?? $newest;
if ($pfrom > $pto) {
    [$pfrom, $pto] = [$pto, $pfrom];
}

[$yearSql, $yearParams] = match ($period) {
    'year'  => [' AND lm.fiscal_year = ?', [$year]],
    'range' => [' AND lm.fiscal_year BETWEEN ? AND ?', [$pfrom, $pto]],
    default => ['', []],
};

// 画面に出す「いま何で絞っているか」
$whoLabel = match ($who) {
    'active' => "現役（{$activeFrom}〜{$thisYear}年度入学）",
    'near'   => '上下3学年（' . ($myEntry - 3) . '〜' . ($myEntry + 3) . '年度入学）',
    'custom' => "{$efrom}〜{$eto}年度入学",
    default  => '全メンバー',
};
if ($who !== 'all' && $includeUnknown) {
    $whoLabel .= ' ＋ 入学年度未登録の人';
}
$periodLabel = match ($period) {
    'year'  => fmt_year($year),
    'range' => "{$pfrom}〜{$pto}年度",
    default => '全期間',
};

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
//   メンバー絞り込みは「2人とも対象メンバー」のペアだけ残す（ma と mb の両方に条件を付ける）。
//   ? が2セット出てくるので、パラメータも $memberParams を2回渡す。順番は SQL の ? の並び順と同じにすること！
$pairs = rows($pdo, 'SELECT ma.member_id AS a_id, ma.name AS a_name, mb.member_id AS b_id, mb.name AS b_name, COUNT(*) AS n
    FROM (' . MEMBERSHIP_SQL . ') a
    JOIN (' . MEMBERSHIP_SQL . ') b ON b.band_id = a.band_id AND a.member_id < b.member_id
    JOIN member ma ON ma.member_id = a.member_id
    JOIN member mb ON mb.member_id = b.member_id
    JOIN band bd ON bd.band_id = a.band_id
    JOIN live_day ld ON ld.live_day_id = bd.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . str_replace('m.', 'ma.', $memberSql) . str_replace('m.', 'mb.', $memberSql) . '
    GROUP BY a.member_id, b.member_id
    HAVING n >= 2
    ORDER BY n DESC, a_name LIMIT 15', array_merge($yearParams, $memberParams, $memberParams));

// ---- トリ回数 ----
$headliners = rows($pdo, 'SELECT m.member_id, m.name, COUNT(DISTINCT b.band_id) AS n
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN (SELECT live_day_id, MAX(play_order) AS max_order FROM band GROUP BY live_day_id) last
        ON last.live_day_id = b.live_day_id AND last.max_order = b.play_order
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . $memberSql . '
    GROUP BY m.member_id ORDER BY n DESC, m.name LIMIT 10', array_merge($yearParams, $memberParams));

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

// =====================================================================
//  個人ランキング
// =====================================================================

// ---- 最多出演（バンド数） ----
$topBands = rows($pdo, 'SELECT m.member_id, m.name, COUNT(DISTINCT bm.band_id) AS n
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . $memberSql . '
    GROUP BY m.member_id ORDER BY n DESC, m.name LIMIT 10', array_merge($yearParams, $memberParams));

// ---- 最多演奏曲数 ----
//   曲が登録されているバンド → その人が演奏した曲の数（song_performer）
//   曲が未登録のバンド       → バンドの曲数（band.song_count）で代用
//   CASE WHEN で「どちらを使うか」をバンドごとに切り替えている
$topSongs = rows($pdo, 'SELECT m.member_id, m.name,
        SUM(CASE WHEN sc.c IS NULL THEN b.song_count ELSE COALESCE(mine.n, 0) END) AS n
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    LEFT JOIN (SELECT band_id, COUNT(*) AS c FROM song GROUP BY band_id) sc ON sc.band_id = b.band_id
    LEFT JOIN (SELECT band_id, member_id, COUNT(DISTINCT song_id) AS n FROM song_performer GROUP BY band_id, member_id) mine
        ON mine.band_id = bm.band_id AND mine.member_id = bm.member_id
    WHERE 1 = 1' . $yearSql . $memberSql . '
    GROUP BY m.member_id ORDER BY n DESC, m.name LIMIT 10', array_merge($yearParams, $memberParams));

// ---- 楽器ごとの1位 ----
//   楽器 × 人 で数えて、PHP で楽器ごとに一番多い人だけ残す
$instrumentKings = [];
foreach (rows($pdo, 'SELECT i.instrument_id, i.short_name, i.name AS instrument_name, m.member_id, m.name, COUNT(DISTINCT bm.band_id) AS n
    FROM band_member bm
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . $memberSql . '
    GROUP BY i.instrument_id, m.member_id
    ORDER BY i.sort_order, n DESC, m.name', array_merge($yearParams, $memberParams)) as $r) {
    $instrumentKings[$r['instrument_id']] ??= $r; // ??= は「まだ無ければ入れる」→ 各楽器の最初の1行（=最多）だけ残る
}

// ---- よく演奏される曲（曲名 × アーティスト） ----
$topTitles = rows($pdo, 'SELECT s.title, a.artist_id, a.name AS artist_name, COUNT(*) AS n
    FROM song s
    JOIN band b ON b.band_id = s.band_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    WHERE 1 = 1' . $yearSql . '
    GROUP BY a.artist_id, s.title
    ORDER BY n DESC, s.title LIMIT 15', $yearParams);

$maxArtist = $artists ? max(array_column($artists, 'n')) : 1;
$maxSlots = $instrumentStats ? max(array_column($instrumentStats, 'slots')) : 1;

render_header('集計', 'stats');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Stats</p>
        <h1 class="display">集計</h1>
        <?php if ($myGrade !== null): ?>
            <p class="muted small">あなた: <?= h(grade_label($myGrade)) ?>（<?= (int)$myEntry ?>年度入学）</p>
        <?php endif; ?>
    </div>
</section>

<?php
/*
 * 絞り込みフォーム（GET）
 *   メンバーと期間を1つのフォームにまとめている → どちらを変えても、もう片方の選択が消えない。
 *   ラジオボタンを「タブ」の見た目にしているだけなので、JS なしでも「適用」ボタンで送れる。
 *   data-autosubmit を付けた入力は、変えた瞬間に送信される（assets/app.js）。
 */
$yearOptions = static function (int $selected, array $list): string {
    $html = '';
    foreach ($list as $y) {
        $html .= '<option value="' . (int)$y . '"' . ($y === $selected ? ' selected' : '') . '>' . (int)$y . '</option>';
    }
    return $html;
};
$entryYears = range($thisYear, $entryMin); // 新しい順
?>
<form method="get" class="card stats-filter no-print">
    <fieldset class="stats-filter__row">
        <legend>メンバー</legend>
        <div class="tabs tabs--filter" role="radiogroup" aria-label="メンバー">
            <label class="tab"><input type="radio" name="who" value="all" data-autosubmit<?= $who === 'all' ? ' checked' : '' ?>>全メンバー</label>
            <label class="tab"><input type="radio" name="who" value="active" data-autosubmit<?= $who === 'active' ? ' checked' : '' ?>>現役</label>
            <label class="tab<?= $canNear ? '' : ' is-disabled' ?>"<?= $canNear ? '' : ' title="アカウントがメンバーに紐付いていないか、入学年度が未登録のため使えません"' ?>>
                <input type="radio" name="who" value="near" data-autosubmit<?= $who === 'near' ? ' checked' : '' ?><?= $canNear ? '' : ' disabled' ?>>上下3学年
            </label>
            <label class="tab"><input type="radio" name="who" value="custom" data-autosubmit<?= $who === 'custom' ? ' checked' : '' ?>>セルフフィルター</label>
        </div>
        <div class="stats-filter__extra" data-show-when="who=custom">
            <select name="efrom" aria-label="入学年度（から）" data-autosubmit><?= $yearOptions($efrom, $entryYears) ?></select>
            <span>〜</span>
            <select name="eto" aria-label="入学年度（まで）" data-autosubmit><?= $yearOptions($eto, $entryYears) ?></select>
            <span class="muted small">年度入学</span>
        </div>
        <label class="stats-filter__extra small" data-hide-when="who=all" title="アカウント未登録の名簿メンバーは入学年度が空のことが多いです">
            <input type="hidden" name="unknown" value="0">
            <input type="checkbox" name="unknown" value="1" data-autosubmit<?= $includeUnknown ? ' checked' : '' ?>>
            入学年度が未登録の人も含める
        </label>
    </fieldset>

    <fieldset class="stats-filter__row">
        <legend>期間</legend>
        <div class="tabs tabs--filter" role="radiogroup" aria-label="期間">
            <label class="tab"><input type="radio" name="period" value="year" data-autosubmit<?= $period === 'year' ? ' checked' : '' ?>>単年度</label>
            <label class="tab"><input type="radio" name="period" value="all" data-autosubmit<?= $period === 'all' ? ' checked' : '' ?>>全期間</label>
            <label class="tab"><input type="radio" name="period" value="range" data-autosubmit<?= $period === 'range' ? ' checked' : '' ?>>ユーザーフィルター</label>
        </div>
        <div class="stats-filter__extra" data-show-when="period=year">
            <select name="year" aria-label="年度" data-autosubmit><?= $yearOptions($year, $years) ?></select>
            <span class="muted small">年度</span>
        </div>
        <div class="stats-filter__extra" data-show-when="period=range">
            <select name="pfrom" aria-label="期間（から）" data-autosubmit><?= $yearOptions($pfrom, $years) ?></select>
            <span>〜</span>
            <select name="pto" aria-label="期間（まで）" data-autosubmit><?= $yearOptions($pto, $years) ?></select>
            <span class="muted small">年度</span>
        </div>
    </fieldset>
    <noscript><button class="btn btn--sm" type="submit">適用</button></noscript>
</form>

<p class="muted small stats-scope">
    表示中: <strong><?= h($whoLabel) ?></strong> × <strong><?= h($periodLabel) ?></strong>
    — メンバーの絞り込みは「個人ランキング」「よく組むペア」「トリ回数」に効きます。それ以外は期間だけで集計しています。
</p>

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

<?php
/** ランキング1枚分のカードを出す小さな関数（同じ HTML を何回も書かないため） */
function ranking_card(string $title, array $rows, callable $label, string $unit, string $empty = 'データがありません'): void
{ ?>
    <section class="card">
        <h2 class="section-title section-title--card"><?= h($title) ?></h2>
        <?php if (!$rows): ?><p class="muted small"><?= h($empty) ?></p><?php endif; ?>
        <ol class="ranking">
            <?php foreach ($rows as $r): ?>
                <li><span><?= $label($r) /* $label は HTML を返す。中で必ず h() すること */ ?></span><span class="pill"><?= (int)$r['n'] ?><?= h($unit) ?></span></li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php }
$memberLink = static fn($r) => '<a href="member.php?id=' . (int)$r['member_id'] . '">' . h($r['name']) . '</a>';
?>

<h2 class="section-title">👤 個人ランキング</h2>
<div class="stats-grid">
    <?php ranking_card('🎤 最多出演（バンド数）', $topBands, $memberLink, '組'); ?>
    <?php ranking_card('🎵 最多演奏曲数', $topSongs, $memberLink, '曲'); ?>
    <section class="card">
        <h2 class="section-title section-title--card">🏅 楽器ごとの1位</h2>
        <ul class="ranking ranking--plain">
            <?php foreach ($instrumentKings as $k): ?>
                <li><span><span class="part part--<?= h(instrument_class($k['short_name'])) ?>"><?= h($k['short_name']) ?></span> <?= $memberLink($k) ?></span><span class="pill"><?= (int)$k['n'] ?>組</span></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<h2 class="section-title">🤝 組み合わせ・その他</h2>
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
        <h2 class="section-title section-title--card">🎸 コピーされたアーティスト ランキング</h2>
        <?php if (!$artists): ?><p class="muted small">データがありません</p><?php endif; ?>
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

    <?php ranking_card('💿 よく演奏される曲', $topTitles,
        static fn($r) => h($r['title']) . ($r['artist_name'] ? ' <a class="muted small" href="artist.php?id=' . (int)$r['artist_id'] . '">' . h($r['artist_name']) . '</a>' : ''),
        '回', '曲（セットリスト）がまだ登録されていません'); ?>

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
