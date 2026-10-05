<?php
/**
 * =====================================================================
 *  members.php — メンバー一覧（出演回数ランキング）
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

$st = $pdo->prepare('SELECT m.member_id, m.name, m.entry_year,
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
$st = $pdo->prepare('SELECT m.member_id, m.name, m.entry_year FROM member m
    WHERE m.member_id NOT IN (SELECT member_id FROM (' . MEMBERSHIP_SQL . ') x)' . $filterSql . '
    ORDER BY m.name');
$st->execute($filterParams);
$idle = $st->fetchAll();
$totalMembers = (int)$pdo->query('SELECT COUNT(*) FROM member')->fetchColumn(); // 絞り込み前の人数（空表示の判定用）

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
                    <td><a href="member.php?id=<?= (int)$r['member_id'] ?>"><?= h($r['name']) ?></a>
                        <?php if ((int)$r['entry_year'] > 0): ?><span class="muted small"> <?= (int)$r['entry_year'] ?>入部</span><?php endif; ?></td>
                    <td class="muted small">出演データなし</td><td class="hide-sm"></td><td></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer();
