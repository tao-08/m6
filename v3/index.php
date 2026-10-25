<?php
/**
 * =====================================================================
 *  index.php — ライブ一覧（トップページ）
 * =====================================================================
 *  live（ライブ） 1 ─ 多 live_day（日程） を JOIN して、
 *  「年度 → ライブ → 日程」の3段の入れ子配列に組み直してから表示する。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$pdo = db();

// 🚩 確認依頼のお知らせ。出すのは次のどちらか（✕ で消したもの = check_dismiss にあるものは出さない）
//   ・自分が出たバンド（アカウントがメンバーと紐付いている人だけ）
//   ・メンバーが1人も登録されていないバンド → 知らせる相手がいないので、そのライブの年度に在籍していたはずの人
//     （入部年度がライブの年度の前後3年以内）に出す。ただしそのライブに OB（入部年度 <= ライブ年度-4）が出ているときは、
//     出演者の入部年度と同じ人に出す。NULL の入部年度は OB 判定にも一致にも使われない
//   about_me: 「誰について」に自分が選ばれているか / no_members: メンバーが1人もいないか
//   member_id が NULL（紐付いていない人）や入部年度が未登録の人は、「= NULL」「BETWEEN NULL」がどの行とも一致しないので、未登録バンドは出ない
$st = $pdo->prepare('SELECT entry_year FROM member WHERE member_id = ?');
$st->execute([$user['member_id']]);
$entryYear = $st->fetchColumn();
$entryYear = $entryYear === false || $entryYear === null ? null : (int)$entryYear;

$st = $pdo->prepare('SELECT b.band_id, b.name, b.needs_check, l.fiscal_year, l.name AS live_name, d.label,
        EXISTS (SELECT 1 FROM band_check_member c WHERE c.band_id = b.band_id AND c.member_id = ?) AS about_me,
        NOT EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id) AS no_members
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    WHERE b.needs_check > 0
      AND (EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id AND bm.member_id = ?)
           OR (NOT EXISTS (SELECT 1 FROM band_member bm WHERE bm.band_id = b.band_id)
               AND CASE WHEN EXISTS (SELECT 1 FROM live_day d2
                                         JOIN band b2 ON b2.live_day_id = d2.live_day_id
                                         JOIN band_member bm2 ON bm2.band_id = b2.band_id
                                         JOIN member m2 ON m2.member_id = bm2.member_id
                                         WHERE d2.live_id = l.live_id AND m2.entry_year <= l.fiscal_year - 4)
                        THEN EXISTS (SELECT 1 FROM live_day d3
                                         JOIN band b3 ON b3.live_day_id = d3.live_day_id
                                         JOIN band_member bm3 ON bm3.band_id = b3.band_id
                                         JOIN member m3 ON m3.member_id = bm3.member_id
                                         WHERE d3.live_id = l.live_id AND m3.entry_year = ?)
                        ELSE ? BETWEEN l.fiscal_year - 3 AND l.fiscal_year + 3 END))
      AND NOT EXISTS (SELECT 1 FROM check_dismiss x WHERE x.band_id = b.band_id AND x.user_id = ?)
    ORDER BY about_me DESC, no_members, l.fiscal_year DESC, d.held_on DESC, b.play_order');
$st->execute([$user['member_id'], $user['member_id'], $entryYear, $entryYear, $user['user_id']]);
$flagged = $st->fetchAll();

// 重要なお知らせ（未読のものだけ。notices.php やベルで読むと、notice_read に行が入って出なくなる）
$st = $pdo->prepare('SELECT n.notice_id, n.title FROM notice n
    LEFT JOIN notice_read r ON r.notice_id = n.notice_id AND r.user_id = ?
    WHERE n.is_important = 1 AND r.user_id IS NULL AND n.publish_at <= NOW() AND (n.target_user_id IS NULL OR n.target_user_id = ?) ORDER BY n.notice_id DESC LIMIT 3');
$st->execute([$user['user_id'], $user['user_id']]);
$importantNotices = $st->fetchAll();

// 日程1行ごとに、そのライブの情報とバンド数をくっつけて取ってくる
//   LEFT JOIN: 相手が無くても行を残す（バンドが0組の日程も表示するため）
//   GROUP BY d.live_day_id: 日程ごとに1行にまとめて COUNT(b.band_id) でバンド数を数える
//   GROUP_CONCAT(b.note): その日のバンドのメモを1つの文字列にまとめる（絞り込み用。NULL は飛ばされる）
//     ※ group_concat_max_len（初期値 1024 バイト）を超えた分は切れる。絞り込みに使うだけなので気にしない
$rows = $pdo->query('SELECT l.live_id, l.fiscal_year AS year, l.name AS live_name, l.youtube_url,
        d.live_day_id, d.label, d.held_on AS date, d.note, v.name AS venue_name, d.venue_id, COUNT(b.band_id) AS band_count,
        GROUP_CONCAT(b.note SEPARATOR \' \') AS band_notes
    FROM live l
    JOIN live_day d ON d.live_id = l.live_id
    LEFT JOIN venue v ON v.venue_id = d.venue_id
    LEFT JOIN band b ON b.live_day_id = d.live_day_id
    GROUP BY d.live_day_id
    ORDER BY l.fiscal_year, d.held_on, d.live_day_id')->fetchAll();

// ---- [年度][live_id] の形に組み直す ----
$years = [];
foreach ($rows as $r) {
    // & は参照。$live を書き換えると $years の中身が直接書き換わる
    $live = &$years[(int)$r['year']][$r['live_id']];
    $live['name'] = $r['live_name'];
    $live['youtube_url'] = $r['youtube_url'];
    $live['days'][] = $r;
    $live['bands'] = ($live['bands'] ?? 0) + (int)$r['band_count'];
    // 一番早い開催日（並べ替え用）。NULL は日付なし
    if ($r['date'] !== null && (!isset($live['first_date']) || $r['date'] < $live['first_date'])) {
        $live['first_date'] = $r['date'];
    }
    unset($live); // 参照を切る（次のループで別の場所を指させるため）
    // カウントダウン用: ライブごとに「開催日（当日を含む）以降で一番早い日程」を覚える。当日は is_upcoming が true
    if ($r['date'] !== null) {
        $dayDates[$r['live_id']][] = $r['date'];
    }
    if (is_upcoming($r['date']) && (!isset($upcoming[$r['live_id']]) || $r['date'] < $upcoming[$r['live_id']]['date'])) {
        $upcoming[$r['live_id']] = ['name' => $r['live_name'], 'date' => $r['date'], 'label' => $r['label']];
    }
}
// 近い順に並べて、残り日数を出す（日付どうしの差なので時刻のずれは出ない）
$upcoming ??= [];
uasort($upcoming, static fn($a, $b) => $a['date'] <=> $b['date']);
$today = new DateTimeImmutable('today');
foreach ($upcoming as $liveId => &$u) {
    $target = new DateTimeImmutable($u['date']);
    $u['days'] = $today->diff($target)->days;
    // 直前の日程（終わった日程）が2日以上前なら離れているので「2日目まであと」のように日程名をつける。
    // 連日（直前が前日）なら日程名は付けない。最初の日程（直前がない）にも付けない
    $prev = array_filter($dayDates[$liveId] ?? [], static fn($d) => $d < $u['date']);
    $u['show_label'] = $prev && $target->diff(new DateTimeImmutable(max($prev)))->days > 1;
}
unset($u);
// 自分が出るバンド（予定のライブだけ）。member_id が NULL（メンバーと紐付いていない人）は「= NULL」で何も当たらない
$myBands = [];
if ($upcoming && $user['member_id'] !== null) {
    $in = implode(',', array_fill(0, count($upcoming), '?'));
    $st = $pdo->prepare("SELECT d.live_id, b.band_id, b.name, b.start_time, d.label, d.held_on
        FROM band_member bm
        JOIN band b ON b.band_id = bm.band_id
        JOIN live_day d ON d.live_day_id = b.live_day_id
        WHERE bm.member_id = ? AND d.live_id IN ($in) AND d.held_on >= CURDATE()
        ORDER BY d.held_on, b.play_order");
    $st->execute([$user['member_id'], ...array_keys($upcoming)]);
    foreach ($st->fetchAll() as $b) {
        $myBands[$b['live_id']][$b['band_id']] = $b;
    }
}
// 表示は「新しい順」がデフォルト（並び替えボタンで古い順にできる）
// 年度は新しい順。年度未設定（0）は一番後ろ（$b と $a を入れ替えると降順になる。0 を後ろにする部分だけは $a 側のまま）
uksort($years, static fn($a, $b) => [$a === 0, $b] <=> [$b === 0, $a]);
// 年度の中は「開催日が新しい順」。日付なしは後ろ
foreach ($years as &$lives) {
    uasort($lives, static fn($a, $b) => [!isset($a['first_date']), $b['first_date'] ?? ''] <=> [!isset($b['first_date']), $a['first_date'] ?? '']);
}
unset($lives);
// 年度スロットは新しい年度が先（よく見るのは最近の年度なので）
$slotYears = array_keys($years);
rsort($slotYears);

// 上の数字（ライブ数・バンド数・出演者数）
$stats = $pdo->query('SELECT
    (SELECT COUNT(*) FROM live) AS lives,
    (SELECT COUNT(*) FROM band) AS bands,
    (SELECT COUNT(DISTINCT member_id) FROM band_member) AS members')->fetch();

render_header('ライブ一覧', 'lives');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Live Archive</p>
        <h1 class="display">ライブ</h1>
    </div>
    <dl class="stats">
        <div><dt>ライブ</dt><dd><?= (int)$stats['lives'] ?></dd></div>
        <div><dt>バンド</dt><dd><?= (int)$stats['bands'] ?></dd></div>
        <div><dt>出演者</dt><dd><?= (int)$stats['members'] ?></dd></div>
    </dl>
</section>

<?php foreach ($upcoming as $liveId => $u): ?>
    <div class="card countdown">
        <a class="countdown__main" href="live?id=<?= (int)$liveId ?>">
            <span class="countdown__name"><?= h($u['name']) ?><?= $u['show_label'] ? ' ' . h($u['label']) : '' ?></span>
            <?php if ($u['days'] === 0): ?>
                <strong class="countdown__days">当日</strong>
            <?php else: ?>
                <span class="countdown__days"><small>まであと</small><strong><?= (int)$u['days'] ?></strong><small>日</small></span>
            <?php endif; ?>
        </a>
        <?php if (!empty($myBands[$liveId])): ?>
            <div class="countdown__bands">
                <?php foreach ($myBands[$liveId] as $b): ?>
                    <?php // 出演時間が未入力なら日付を出す ?>
                    <a class="countdown__band" href="band?id=<?= (int)$b['band_id'] ?>"><span class="countdown__when"><?= h($b['label']) ?> <?= $b['start_time'] !== null ? h(substr($b['start_time'], 0, 5)) : h(fmt_date($b['held_on'])) ?></span><strong><?= h($b['name']) ?></strong></a>
                <?php endforeach; ?>
                <a class="btn btn--sm countdown__practice" href="practice"><?= icon('slow_motion_video') ?> 練習プレイヤーへ</a>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($importantNotices): ?>
    <a class="card notice-banner" href="notices">
        <?= icon('priority_high', 'notice__mark') ?>
        <span class="notice-banner__text"><?php foreach ($importantNotices as $n): ?><span><?= h($n['title']) ?></span><?php endforeach; ?></span>
        <?= icon('chevron_right') ?>
    </a>
<?php endif; ?>

<?php if ($flagged): ?>
    <section class="card check-list" data-check-list>
        <h2 class="section-title section-title--card section-title--tool"><?= icon('flag', 'icon--fill flag-icon') ?>確認依頼が届いています
            <button type="button" class="info-tip" data-setlist-tip="メンバーが未登録のバンドは、そのライブに関係する学年（現役の学年の前後3年、OB出演時は出演者の学年）の方に表示されています。情報をお持ちの方は情報提供にご協力お願いします。" aria-label="補足を表示"><?= icon('info') ?></button></h2>
        <ul>
            <?php foreach ($flagged as $f): ?>
                <li data-check-item><div class="check-list__text"><a href="band?id=<?= (int)$f['band_id'] ?>"><?= h($f['name']) ?></a>
                    <span class="muted small"><?= (int)$f['fiscal_year'] ?>年度 <?= h($f['live_name']) ?> <?= h($f['label']) ?></span>
                    <?php // 理由（楽器など）はここでは出さない。中身はバンドページで見てもらう ?>
                    <?php if ($f['no_members']): ?>
                        <span class="chip chip--warn chip--xs"><?= icon('person_off') ?>メンバー未登録</span>
                    <?php elseif ($f['about_me']): ?>
                        <span class="chip chip--me chip--xs">あなたについて</span>
                    <?php endif; ?></div>
                    <button type="button" class="check-list__close" data-check-dismiss="<?= (int)$f['band_id'] ?>" aria-label="「<?= h($f['name']) ?>」のお知らせを消す" title="消す"><?= icon('close') ?></button></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (!$years): ?>
    <div class="empty card">
        <p class="empty__title">まだライブが登録されていません</p>
        <p class="muted">タイムテーブルと名簿（CSV / PDF）を取り込むか、手入力で登録しよう。</p>
        <a class="btn btn--primary" href="import">＋ 新規追加</a>
    </div>
<?php else: ?>
    <div class="toolbar">
        <!-- data-year-slot: 縦にドラッグ（ホイール・↑↓キー）で年度を切り替えて、その年度の .year だけ表示する（assets/app.js） -->
        <div class="year-slot year-slot--live" data-year-slot tabindex="0" role="spinbutton" aria-label="年度で絞り込み" title="上下にドラッグで年度を切り替え">
            <div class="year-slot__reel">
                <div class="year-slot__item" data-value="">すべて</div>
                <?php foreach ($slotYears as $year): ?>
                    <div class="year-slot__item" data-value="<?= (int)$year ?>" data-label="<?= $year > 0 ? (int)$year . '年度' : '未設定' ?>"><?= $year > 0 ? '<span class="year-slot__full">' . (int)$year . '<small>年度</small></span><span class="year-slot__short">' . sprintf("'%02d", $year % 100) . '</span>' : '未設定' ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- data-filter: 入力すると .live-card の data-text で絞り込む（assets/app.js） -->
        <input type="search" class="search" placeholder="ライブ名・会場・メモで絞り込み" data-filter=".live-card" aria-label="絞り込み">
        <a class="btn btn--primary" href="import">＋ 新規追加</a>
    </div>
    <!-- .sorted-list: 並び替えボタンを、一番上の年度の見出しの右に重ねて置く（member.php の出演履歴と同じ） -->
    <div class="sorted-list sorted-list--live">
    <!-- 並び替え: 押すたびに古い順 ⇔ 新しい順。年度の順と、年度の中のカード（.grid の中）の順を逆にする（assets/app.js の setupSortToggle）
         最初は新しい順なので aria-pressed="false"（app.js は aria-pressed が true = 古い順 として扱う） -->
    <button type="button" class="btn btn--ghost btn--sm sorted-list__btn" data-sort-toggle="#live-list" data-sort-items=".grid" aria-pressed="false">新しい順 ↓</button>
    <div id="live-list">
    <?php foreach ($years as $year => $lives): ?>
        <section class="year" data-year="<?= (int)$year ?>">
            <h2 class="year__title"><?= $year > 0 ? (int)$year . '<small>年度</small>' : '年度未設定' ?></h2>
            <div class="grid">
                <?php foreach ($lives as $liveId => $live):
                    $venues = array_unique(array_filter(array_column($live['days'], 'venue_name')));
                    // 絞り込みで当てる文字: ライブ名・会場・日程のメモ・バンドのメモ
                    $notes = array_filter(array_merge(array_column($live['days'], 'note'), array_column($live['days'], 'band_notes'))); ?>
                    <!-- カードの中に YouTube のリンクを置くため、カード自体は <div> にする（<a> の中に <a> は入れられない）。
                         ライブページへのリンクはライブ名の <a> で、CSS の ::after でカード全体に広げて押せるようにしている -->
                    <div class="card live-card"
                       data-text="<?= h($live['name'] . ' ' . implode(' ', $venues) . ' ' . implode(' ', $notes)) ?>">
                        <div class="live-card__head">
                            <h3 class="live-card__title"><a class="live-card__link" href="live?id=<?= (int)$liveId ?>"><?= h($live['name']) ?></a>
                                <?= upcoming_tag(max(array_column($live['days'], 'date') ?: [null])) // 最後の日程がまだ来ていなければ「予定」 ?></h3>
                            <span class="pill"><?= count($live['days']) ?>日程</span>
                        </div>
                        <ul class="live-card__days">
                            <?php foreach ($live['days'] as $d): ?>
                                <li>
                                    <span class="day-no"><?= h($d['label'] ?: '—') ?></span>
                                    <span><?= h(fmt_date($d['date']) ?: '日付未設定') ?></span>
                                    <span class="muted ellipsis"><?php if ($d['venue_id']): ?><a class="live-card__venue" href="venue?id=<?= (int)$d['venue_id'] ?>"><?= h($d['venue_name']) ?></a><?php endif; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="live-card__foot"><strong><?= (int)$live['bands'] ?></strong> バンド出演
                            <?php if ($live['youtube_url'] !== null && youtube_url_valid($live['youtube_url'])): // 表示の前にもう一度チェック（live.php と同じ） ?>
                                <a class="live-card__yt" href="<?= h($live['youtube_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="「<?= h($live['name']) ?>」を YouTube で見る" title="YouTube で見る"><?= youtube_icon() ?></a>
                            <?php endif; ?>
                            <span class="arrow"><?= icon('arrow_forward') ?></span></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    </div>
    </div>
<?php endif; ?>
<?php render_footer();
