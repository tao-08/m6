<?php
/**
 * =====================================================================
 *  live.php?id=ライブID — ライブ詳細（日程ごとのタイムテーブル）
 * =====================================================================
 *  SQL を3回に分けている:
 *    1. 日程（live_detail）一覧
 *    2. その全日程のバンド（band）
 *    3. その全バンドのメンバーと楽器
 *  「バンドごとに SQL を1回ずつ投げる」と、バンド30組なら30回になって遅い（N+1問題）。
 *  まとめて取ってから PHP の配列で振り分けるほうが速い。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$liveId = (int)($_GET['id'] ?? 0); // (int) で数字以外が来ても 0 になる
$pdo = db();
$st = $pdo->prepare('SELECT * FROM live_master WHERE live_id = ?');
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
$st = $pdo->prepare('SELECT ld.*, v.name AS venue_name FROM live_detail ld
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    WHERE ld.live_id = ? ORDER BY ld.date, ld.live_detail_id');
$st->execute([$liveId]);
$days = $st->fetchAll();

// ---- 2. バンド（日程ごとに振り分け） ----
$st = $pdo->prepare('SELECT b.* FROM band b JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    WHERE ld.live_id = ? ORDER BY b.play_order, b.band_id');
$st->execute([$liveId]);
$bandsByDay = [];
foreach ($st as $b) { // PDOStatement はそのまま foreach で1行ずつ回せる
    $bandsByDay[$b['live_detail_id']][] = $b;
}

// ---- 3. メンバー（バンドごと → 楽器ごとに振り分け） ----
// band_member_instrument を LEFT JOIN しているので、楽器が登録されていない人も「?」として出る
$st = $pdo->prepare('SELECT bm.band_id, m.member_id, m.name, i.instrument_id, i.instrument_short, i.instrument_name
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_detail ld ON ld.live_detail_id = b.live_detail_id
    LEFT JOIN band_member_instrument bmi ON bmi.band_id = bm.band_id AND bmi.member_id = bm.member_id
    LEFT JOIN instrument i ON i.instrument_id = bmi.instrument_id
    WHERE ld.live_id = ?');
$st->execute([$liveId]);
$lineups = []; // [band_id][楽器の並び順キー] = ['short' => 'Vo.', 'members' => [...]]
foreach ($st as $m) {
    $slot = &$lineups[$m['band_id']][instrument_sort_key($m['instrument_id'] === null ? null : (int)$m['instrument_id'])];
    $slot['short'] = $m['instrument_short'] ?? '?';
    $slot['title'] = $m['instrument_name'] ?? '楽器未登録';
    $slot['members'][] = $m;
    unset($slot);
}
foreach ($lineups as &$parts) {
    ksort($parts); // 楽器の並び順キーで並べる（Vo → Gt → Ba → Dr → Key）
}
unset($parts);

$totalBands = array_sum(array_map('count', $bandsByDay));
render_header($live['name'], 'lives');
?>
<nav class="crumbs"><a href="index.php">ライブ</a><span>/</span><?= h(fmt_year($live['year'])) ?></nav>
<section class="hero hero--live">
    <div>
        <p class="eyebrow"><?= h(fmt_year($live['year'])) ?> · <?= count($days) ?> DAYS · <?= $totalBands ?> BANDS</p>
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
        <a href="#day-<?= (int)$d['live_detail_id'] ?>" class="tab<?= $i === 0 ? ' is-active' : '' ?>" data-tab>
            <?= h($d['label'] ?: 'DAY ' . ($i + 1)) ?><small><?= h(fmt_date($d['date'])) ?></small>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($days as $i => $d):
    $bands = $bandsByDay[$d['live_detail_id']] ?? [];
    $songs = array_sum(array_map(static fn($b) => (int)$b['song_count'], $bands)); ?>
<section class="day" id="day-<?= (int)$d['live_detail_id'] ?>" <?= $i > 0 && count($days) > 1 ? 'data-hidden' : '' ?>>
    <div class="day__info card">
        <div>
            <p class="eyebrow"><?= h($d['label'] ?: 'DAY ' . ($i + 1)) ?></p>
            <h2><?= h(fmt_date($d['date']) ?: '日付未設定') ?></h2>
        </div>
        <dl class="facts">
            <div><dt>会場</dt><dd><?= h($d['venue_name'] ?? '—') ?></dd></div>
            <div><dt>バンド</dt><dd><?= count($bands) ?></dd></div>
            <div><dt>曲数</dt><dd><?= $songs ?></dd></div>
            <?php if ($d['note']): ?><div><dt>メモ</dt><dd><?= h($d['note']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if (is_admin()): ?>
            <form method="post" action="live_delete.php" class="day__danger no-print" data-confirm="<?= h(($d['label'] ?: 'この日程') . ' のデータを削除します。元に戻せません。よろしいですか？') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="live_detail_id" value="<?= (int)$d['live_detail_id'] ?>">
                <button class="btn btn--ghost btn--danger btn--sm" type="submit">この日程を削除</button>
            </form>
        <?php endif; ?>
    </div>

    <ol class="timeline">
        <?php foreach ($bands as $bi => $b):
            $lineup = $lineups[$b['band_id']] ?? [];
            // 自分（ログイン中のユーザーと紐付いたメンバー）が出ているバンドか
            $memberIds = [];
            foreach ($lineup as $part) {
                foreach ($part['members'] as $m) {
                    $memberIds[] = (int)$m['member_id'];
                }
            }
            $isMine = $user['member_id'] && in_array($user['member_id'], $memberIds, true);
            $isLast = $bi === count($bands) - 1; // 最後 = トリ ?>
            <li class="slot<?= $isLast ? ' slot--headliner' : '' ?><?= $isMine ? ' slot--mine' : '' ?>">
                <div class="slot__order-col"><?= sprintf('%02d', (int)$b['play_order']) ?></div>
                <div class="slot__body">
                    <div class="slot__head">
                        <h3 class="slot__name"><?= h($b['name']) ?></h3>
                        <?php if ($isLast): ?><span class="tag tag--accent">トリ</span><?php endif; ?>
                        <?php if ($isMine): ?><span class="tag">出演</span><?php endif; ?>
                        <a class="slot__edit no-print" href="band_edit.php?id=<?= (int)$b['band_id'] ?>" aria-label="<?= h($b['name']) ?> を編集">編集</a>
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
                        <span><?= (int)$b['song_count'] ?>曲</span>
                        <?php if ($memberIds): ?><span><?= count(array_unique($memberIds)) ?>人</span><?php endif; ?>
                        <?php if ($b['note']): ?><span class="keynote">🎹 <?= h($b['note']) ?></span><?php endif; ?>
                    </p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if (!$bands): ?><p class="muted">バンドが登録されていません</p><?php endif; ?>
    <p class="no-print add-band"><a class="btn btn--ghost btn--sm" href="band_edit.php?day=<?= (int)$d['live_detail_id'] ?>">＋ バンドを追加</a></p>
</section>
<?php endforeach; ?>
<?php render_footer();
