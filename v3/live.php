<?php
/**
 * =====================================================================
 *  live.php?id=ライブID — ライブ詳細（日程ごとのタイムテーブル）
 * =====================================================================
 *  SQL を3回に分けている:
 *    1. 日程（live_day）一覧
 *    2. その全日程のバンド（band）
 *    3. その全バンドのメンバーと楽器（band_member）
 *  セットリストはバンド詳細（band.php）で見る。ここはタイムテーブルの一覧だけ。
 *  「バンドごとに SQL を1回ずつ投げる」と、バンド30組なら30回になって遅い（N+1問題）。
 *  まとめて取ってから PHP の配列で振り分けるほうが速い。
 *
 *  休憩は DB に保存していない。前のバンドの終了時刻と次の開始時刻に隙間があれば「BREAK」と表示する
 *  （計算で出せるものは保存しない = 正規化）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$liveId = (int)($_GET['id'] ?? 0); // (int) で数字以外が来ても 0 になる
$pdo = db();
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">ライブが見つかりません</p><a class="btn" href="index.php">一覧へ戻る</a></div>';
    render_footer();
    exit;
}

// ---- 1. 日程 ----
$st = $pdo->prepare('SELECT d.*, v.name AS venue_name, v.website_url FROM live_day d
    LEFT JOIN venue v ON v.venue_id = d.venue_id
    WHERE d.live_id = ? ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id');
//                       ↑ 日付が NULL の日程を最後にする（IS NULL は NULL なら 1、それ以外は 0）
$st->execute([$liveId]);
$days = $st->fetchAll();

// ---- 2. バンド（日程ごとに振り分け） ----
$st = $pdo->prepare('SELECT b.*, a.name AS artist_name FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? ORDER BY b.play_order');
$st->execute([$liveId]);
$bandsByDay = [];
foreach ($st as $b) { // PDOStatement はそのまま foreach で1行ずつ回せる
    $bandsByDay[$b['live_day_id']][] = $b;
}

// ---- 3. メンバー（バンドごと → 楽器ごとに振り分け） ----
// 楽器の並び順は instrument.sort_order（DB が持っている）で ORDER BY
$st = $pdo->prepare('SELECT bm.band_id, m.member_id, m.name, bm.instrument_id, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id
    WHERE d.live_id = ?
    ORDER BY i.sort_order, m.name');
$st->execute([$liveId]);
$lineups = []; // [band_id][sort_order] = ['short' => 'Vo', 'members' => [...]]
foreach ($st as $m) {
    $part = &$lineups[$m['band_id']][(int)$m['sort_order']];
    $part['short'] = $m['short_name'];
    $part['title'] = $m['instrument_name'];
    $part['members'][] = $m;
    unset($part);
}

$totalBands = array_sum(array_map('count', $bandsByDay));
render_header($live['name'], 'lives');
?>
<nav class="crumbs"><a href="index.php">ライブ</a><span>/</span><?= h(fmt_year($live['fiscal_year'])) ?></nav>
<section class="hero hero--live">
    <div>
        <p class="eyebrow"><?= h(fmt_year($live['fiscal_year'])) ?> · <?= count($days) ?> DAYS · <?= $totalBands ?> BANDS</p>
        <h1 class="display"><?= h($live['name']) ?></h1>
    </div>
    <!-- このライブでできること（編集・CSV・印刷）。印刷時は CSS で隠す -->
    <div class="hero__actions no-print">
        <a class="btn btn--sm" href="live_edit.php?id=<?= (int)$liveId ?>">✎ ライブを編集</a>
        <a class="btn btn--sm" href="export.php?live=<?= (int)$liveId ?>">⇩ CSV</a>
        <button class="btn btn--sm" type="button" data-print>🖨 印刷</button>
    </div>
</section>

<?php if (count($days) > 1): ?>
<!-- 日程タブ。JS が動けばタブ切り替え、動かなければ全日程が縦に並ぶ -->
<div class="tabs no-print" role="tablist">
    <?php foreach ($days as $i => $d): ?>
        <a href="#day-<?= (int)$d['live_day_id'] ?>" class="tab<?= $i === 0 ? ' is-active' : '' ?>" data-tab>
            <?= h($d['label']) ?><small><?= h(fmt_date($d['held_on'])) ?></small>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($days as $i => $d):
    $bands = $bandsByDay[$d['live_day_id']] ?? [];
    $songs = array_sum(array_map(static fn($b) => (int)$b['song_count'], $bands)); ?>
<section class="day" id="day-<?= (int)$d['live_day_id'] ?>" <?= $i > 0 && count($days) > 1 ? 'data-hidden' : '' ?>>
    <div class="day__info card">
        <div>
            <p class="eyebrow"><?= h($d['label']) ?></p>
            <h2><?= h(fmt_date($d['held_on']) ?: '日付未設定') ?></h2>
        </div>
        <dl class="facts">
            <div><dt>会場</dt><dd>
                <?php if ($d['venue_name'] && $d['website_url'] && preg_match('#^https?://#', $d['website_url'])): ?>
                    <!-- URL は https:// で始まるものだけリンクにする（javascript: などを埋め込まれないように） -->
                    <a href="<?= h($d['website_url']) ?>" target="_blank" rel="noopener"><?= h($d['venue_name']) ?> ↗</a>
                <?php else: ?><?= h($d['venue_name'] ?? '—') ?><?php endif; ?>
            </dd></div>
            <div><dt>バンド</dt><dd><?= count($bands) ?></dd></div>
            <div><dt>曲数</dt><dd><?= $songs ?></dd></div>
            <?php if ($d['note']): ?><div><dt>メモ</dt><dd><?= h($d['note']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if (is_admin()): ?>
            <form method="post" action="live_delete.php" class="day__danger no-print" data-confirm="<?= h($d['label'] . ' のデータを削除します。元に戻せません。よろしいですか？') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="live_day_id" value="<?= (int)$d['live_day_id'] ?>">
                <button class="btn btn--ghost btn--danger btn--sm" type="submit">この日程を削除</button>
            </form>
        <?php endif; ?>
    </div>

    <ol class="timeline">
        <?php $prevEnd = null;
        foreach ($bands as $bi => $b):
            // 前のバンドの終了より後に始まるなら、その間は休憩
            if ($prevEnd && $b['start_time'] && $b['start_time'] > $prevEnd): ?>
                <li class="slot slot--break">
                    <div class="slot__time"><?= h(fmt_time($prevEnd)) ?><span><?= h(fmt_time($b['start_time'])) ?></span></div>
                    <div class="slot__body"><span class="break-label">BREAK</span></div>
                </li>
            <?php endif;
            $prevEnd = $b['end_time'] ?: $prevEnd;
            $lineup = $lineups[$b['band_id']] ?? [];
            $memberIds = [];
            foreach ($lineup as $part) {
                foreach ($part['members'] as $m) {
                    $memberIds[] = (int)$m['member_id'];
                }
            }
            $isMine = $user['member_id'] && in_array($user['member_id'], $memberIds, true);
            $isLast = $bi === count($bands) - 1; // 最後 = トリ ?>
            <li class="slot<?= $isLast ? ' slot--headliner' : '' ?><?= $isMine ? ' slot--mine' : '' ?>">
                <div class="slot__time">
                    <?php if ($b['start_time']): ?><?= h(fmt_time($b['start_time'])) ?><span><?= h(fmt_time($b['end_time'])) ?></span>
                    <?php else: ?><span class="slot__no"><?= sprintf('%02d', (int)$b['play_order']) ?></span><?php endif; ?>
                </div>
                <div class="slot__body">
                    <div class="slot__head">
                        <span class="slot__order"><?= sprintf('%02d', (int)$b['play_order']) ?></span>
                        <h3 class="slot__name"><a href="band.php?id=<?= (int)$b['band_id'] ?>"><?= h($b['name']) ?></a></h3>
                        <?php if ($isLast): ?><span class="tag tag--accent" aria-label="トリ">🐦</span><?php endif; ?>
                        <?php if ($isMine): ?><span class="tag">出演</span><?php endif; ?>
                    </div>
                    <?php if ($lineup): ?>
                        <ul class="lineup">
                            <?php foreach ($lineup as $part): ?>
                                <li><span class="part part--<?= h(instrument_class($part['short'])) ?>" title="<?= h($part['title']) ?>"><?= h($part['short']) ?></span>
                                    <?php foreach ($part['members'] as $m): ?>
                                        <a class="chip<?= (int)$m['member_id'] === $user['member_id'] ? ' chip--me' : '' ?>" href="member.php?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a>
                                    <?php endforeach; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted small">メンバー未登録</p>
                    <?php endif; ?>
                    <p class="slot__meta">
                        <?php if ($b['artist_name'] && $b['artist_name'] !== $b['name']): ?>
                            <a href="artist.php?id=<?= (int)$b['artist_id'] ?>">♪ <?= h($b['artist_name']) ?></a>
                        <?php elseif ($b['artist_id']): ?>
                            <a href="artist.php?id=<?= (int)$b['artist_id'] ?>">♪ ほかのコピー</a>
                        <?php endif; ?>
                        <span><?= (int)$b['song_count'] ?>曲</span>
                        <?php if ($memberIds): ?><span><?= count(array_unique($memberIds)) ?>人</span><?php endif; ?>
                        <?php if ($b['note']): ?><span class="keynote">🎹 <?= h($b['note']) ?></span><?php endif; ?>
                    </p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if (!$bands): ?><p class="muted">バンドが登録されていません</p><?php endif; ?>
    <p class="no-print add-band"><a class="btn btn--ghost btn--sm" href="band_edit.php?day=<?= (int)$d['live_day_id'] ?>">＋ バンドを追加</a></p>
</section>
<?php endforeach; ?>
<?php render_footer();
