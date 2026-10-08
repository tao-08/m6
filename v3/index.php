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
//   GROUP_CONCAT(b.note): その日のバンドのメモを1つの文字列にまとめる（絞り込み用。NULL は飛ばされる）
//     ※ group_concat_max_len（初期値 1024 バイト）を超えた分は切れる。絞り込みに使うだけなので気にしない
$rows = $pdo->query('SELECT l.live_id, l.fiscal_year AS year, l.name AS live_name, l.youtube_url,
        d.live_day_id, d.label, d.held_on AS date, d.note, v.name AS venue_name, COUNT(b.band_id) AS band_count,
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
}
// 表示は「古い順」がデフォルト（並び替えボタンで新しい順にできる）
// 年度は古い順。年度未設定（0）は一番後ろ
uksort($years, static fn($a, $b) => [$a === 0, $a] <=> [$b === 0, $b]);
// 年度の中は「開催日が古い順」。日付なしは後ろ
foreach ($years as &$lives) {
    uasort($lives, static fn($a, $b) => [!isset($a['first_date']), $a['first_date'] ?? ''] <=> [!isset($b['first_date']), $b['first_date'] ?? '']);
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

<?php if ($flagged): ?>
    <section class="card check-list">
        <h2 class="section-title section-title--card"><?= icon('flag', 'icon--fill flag-icon') ?> 確認依頼が届いています</h2>
        <p class="muted small">出演者に楽器の登録が正しいかの確認依頼が届いているバンドです。バンドページを開いて確認をお願いします。</p>
        <ul>
            <?php foreach ($flagged as $f): ?>
                <li><a href="band?id=<?= (int)$f['band_id'] ?>"><?= h($f['name']) ?></a>
                    <span class="muted small"><?= (int)$f['fiscal_year'] ?>年度 <?= h($f['live_name']) ?> <?= h($f['label']) ?></span></li>
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
        <div class="year-slot" data-year-slot tabindex="0" role="spinbutton" aria-label="年度で絞り込み" title="上下にドラッグで年度を切り替え">
            <div class="year-slot__reel">
                <div class="year-slot__item" data-value="">すべて</div>
                <?php foreach ($slotYears as $year): ?>
                    <div class="year-slot__item" data-value="<?= (int)$year ?>"><?= $year > 0 ? (int)$year . '<small>年度</small>' : '未設定' ?></div>
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
         最初は古い順なので aria-pressed="true"（app.js は aria-pressed が true = 古い順 として扱う） -->
    <button type="button" class="btn btn--ghost btn--sm sorted-list__btn" data-sort-toggle="#live-list" data-sort-items=".grid" aria-pressed="true">古い順 ↑</button>
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
                            <h3><a class="live-card__link" href="live?id=<?= (int)$liveId ?>"><?= h($live['name']) ?></a></h3>
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
