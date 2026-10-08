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

// 🚩 自分が出たバンドのうち、取り込みで「楽器を確認して」と印が付いたもの（アカウントがメンバーと紐付いている人だけ）
$flagged = [];
if ($user['member_id']) {
    $st = $pdo->prepare('SELECT DISTINCT b.band_id, b.name, b.play_order, l.fiscal_year, l.name AS live_name, d.label, d.held_on
        FROM band b
        JOIN band_member bm ON bm.band_id = b.band_id
        JOIN live_day d ON d.live_day_id = b.live_day_id
        JOIN live l ON l.live_id = d.live_id
        WHERE b.needs_check = 1 AND bm.member_id = ?
        ORDER BY l.fiscal_year DESC, d.held_on DESC, b.play_order');
    $st->execute([$user['member_id']]);
    $flagged = $st->fetchAll();
}

// 日程1行ごとに、そのライブの情報とバンド数をくっつけて取ってくる
//   LEFT JOIN: 相手が無くても行を残す（バンドが0組の日程も表示するため）
//   GROUP BY d.live_day_id: 日程ごとに1行にまとめて COUNT(b.band_id) でバンド数を数える
$rows = $pdo->query('SELECT l.live_id, l.fiscal_year AS year, l.name AS live_name,
        d.live_day_id, d.label, d.held_on AS date, v.name AS venue_name, COUNT(b.band_id) AS band_count
    FROM live l
    JOIN live_day d ON d.live_id = l.live_id
    LEFT JOIN venue v ON v.venue_id = d.venue_id
    LEFT JOIN band b ON b.live_day_id = d.live_day_id
    GROUP BY d.live_day_id
    ORDER BY l.fiscal_year DESC, d.held_on, d.live_day_id')->fetchAll();

// ---- [年度][live_id] の形に組み直す ----
$years = [];
foreach ($rows as $r) {
    // & は参照。$live を書き換えると $years の中身が直接書き換わる
    $live = &$years[(int)$r['year']][$r['live_id']];
    $live['name'] = $r['live_name'];
    $live['days'][] = $r;
    $live['bands'] = ($live['bands'] ?? 0) + (int)$r['band_count'];
    // 一番早い開催日（並べ替え用）。NULL は日付なし
    if ($r['date'] !== null && (!isset($live['first_date']) || $r['date'] < $live['first_date'])) {
        $live['first_date'] = $r['date'];
    }
    unset($live); // 参照を切る（次のループで別の場所を指させるため）
}
// 年度の中は「開催日が新しい順」。日付なしは後ろ
foreach ($years as &$lives) {
    uasort($lives, static fn($a, $b) => [isset($b['first_date']), $b['first_date'] ?? ''] <=> [isset($a['first_date']), $a['first_date'] ?? '']);
}
unset($lives);

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

<?php if ($flagged): ?>
    <section class="card check-list">
        <h2 class="section-title section-title--card"><?= icon('flag', 'icon--fill flag-icon') ?> 楽器の確認をお願いします</h2>
        <p class="muted small">取り込みのときに楽器があやしいと印が付いたバンドです。開いて確認し、違っていたら直してください。</p>
        <ul>
            <?php foreach ($flagged as $f): ?>
                <li><a href="band.php?id=<?= (int)$f['band_id'] ?>"><?= h($f['name']) ?></a>
                    <span class="muted small"><?= (int)$f['fiscal_year'] ?>年度 <?= h($f['live_name']) ?> <?= h($f['label']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (!$years): ?>
    <div class="empty card">
        <p class="empty__title">まだライブが登録されていません</p>
        <p class="muted">タイムテーブルと名簿（CSV / PDF）を取り込むか、手入力で登録しよう。</p>
        <a class="btn btn--primary" href="import.php">＋ 新規追加</a>
    </div>
<?php else: ?>
    <div class="toolbar">
        <!-- data-year-slot: 縦にドラッグ（ホイール・↑↓キー）で年度を切り替えて、その年度の .year だけ表示する（assets/app.js） -->
        <div class="year-slot" data-year-slot tabindex="0" role="spinbutton" aria-label="年度で絞り込み" title="上下にドラッグで年度を切り替え">
            <div class="year-slot__reel">
                <div class="year-slot__item" data-value="">すべて</div>
                <?php foreach (array_keys($years) as $year): ?>
                    <div class="year-slot__item" data-value="<?= (int)$year ?>"><?= $year > 0 ? (int)$year . '<small>年度</small>' : '未設定' ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- data-filter: 入力すると .live-card の data-text で絞り込む（assets/app.js） -->
        <input type="search" class="search" placeholder="ライブ名・会場で絞り込み" data-filter=".live-card" aria-label="絞り込み">
        <a class="btn btn--primary" href="import.php">＋ 新規追加</a>
    </div>
    <!-- .sorted-list: 並び替えボタンを、一番上の年度の見出しの右に重ねて置く（member.php の出演履歴と同じ） -->
    <div class="sorted-list sorted-list--live">
    <!-- 並び替え: 押すたびに新しい順 ⇔ 古い順。年度の順と、年度の中のカード（.grid の中）の順を逆にする（assets/app.js の setupSortToggle） -->
    <button type="button" class="btn btn--ghost btn--sm sorted-list__btn" data-sort-toggle="#live-list" data-sort-items=".grid" aria-pressed="false">新しい順 ↓</button>
    <div id="live-list">
    <?php foreach ($years as $year => $lives): ?>
        <section class="year" data-year="<?= (int)$year ?>">
            <h2 class="year__title"><?= $year > 0 ? (int)$year . '<small>年度</small>' : '年度未設定' ?></h2>
            <div class="grid">
                <?php foreach ($lives as $liveId => $live):
                    $venues = array_unique(array_filter(array_column($live['days'], 'venue_name'))); ?>
                    <a class="card live-card" href="live.php?id=<?= (int)$liveId ?>"
                       data-text="<?= h($live['name'] . ' ' . implode(' ', $venues)) ?>">
                        <div class="live-card__head">
                            <h3><?= h($live['name']) ?></h3>
                            <span class="pill"><?= count($live['days']) ?>日程</span>
                        </div>
                        <ul class="live-card__days">
                            <?php foreach ($live['days'] as $d): ?>
                                <li>
                                    <span class="day-no"><?= h($d['label'] ?: '—') ?></span>
                                    <span><?= h(fmt_date($d['date']) ?: '日付未設定') ?></span>
                                    <span class="muted ellipsis"><?= h($d['venue_name'] ?? '') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="live-card__foot"><strong><?= (int)$live['bands'] ?></strong> バンド出演 <span class="arrow"><?= icon('arrow_forward') ?></span></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    </div>
    </div>
<?php endif; ?>
<?php render_footer();
