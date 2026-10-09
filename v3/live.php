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
require_once __DIR__ . '/lib/repository.php';
$user = require_login();

$liveId = (int)($_GET['id'] ?? 0); // (int) で数字以外が来ても 0 になる
$pdo = db();
$st = $pdo->prepare('SELECT * FROM live WHERE live_id = ?');
$st->execute([$liveId]);
$live = $st->fetch();
if (!$live) {
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">ライブが見つかりません</p><a class="btn" href="./">一覧へ戻る</a></div>';
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
//   setlist_count: 登録済みの曲数（セトリ登録済バッジ用）。相関サブクエリなので SQL の回数は増えない
$st = $pdo->prepare('SELECT b.*, a.name AS artist_name,
        (SELECT COUNT(*) FROM song s WHERE s.band_id = b.band_id) AS setlist_count
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE d.live_id = ? ORDER BY b.play_order');
$st->execute([$liveId]);
$bandsByDay = [];
$allBandIds = [];
$omnibusIds = []; // オムニバスのバンドだけ、あとで曲のアーティストをまとめて取る（バンドごとに SQL を投げない）
foreach ($st as $b) { // PDOStatement はそのまま foreach で1行ずつ回せる
    $bandsByDay[$b['live_day_id']][] = $b;
    $allBandIds[] = (int)$b['band_id'];
    if ($b['is_omnibus']) {
        $omnibusIds[] = (int)$b['band_id'];
    }
}
$omnibusArtists = omnibus_artists_by_band($pdo, $omnibusIds);
// お気に入り（❤）の数と自分が付けたか。全バンド分を1回の SQL で取る
$likes = band_likes($pdo, $allBandIds, (int)$user['user_id']);
// 休憩・転換など（timetable_edit.php で登録したもの / 取り込みで「バンドではない枠」だったもの）
$breaksByDay = load_breaks_by_day($pdo, $liveId);

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
// 「Vo/Gt」のまとめは同じバンドの中だけで考える（別のバンドで Vo と Gt をやった人をまとめないように、先にバンドごとに分ける）
$rowsByBand = [];
foreach ($st as $m) {
    $rowsByBand[$m['band_id']][] = $m;
}
$lineups = array_map('lineup_by_part', $rowsByBand); // [band_id][並び順] = ['short' => 'Vo/Gt', 'segments' => [Vo, Gt], 'members' => [...]]

// 日程ごとの組数: 総バンド数（live_day.total_bands）が入っていればそちら（タイムテーブルが一部しか無い日程用）
//   総バンド数は登録済みより少なくならない（live_edit.php で止める / band_edit.php で追いつかせる）が、念のため max
$dayTotal = static fn(array $d): int => max(count($bandsByDay[$d['live_day_id']] ?? []), (int)$d['total_bands']);
$totalBands = array_sum(array_map($dayTotal, $days));

// 「← 前のライブ / 次のライブ →」: ライブを開催順（年度 → 最初の日程の日付）に並べて、前後を取る（band.php と同じやり方）
//   ライブは多くても数百件なので全部取ってきて PHP で探す。日付未設定のライブはその年度の最後
$siblings = $pdo->query('SELECT l.live_id, l.name,
        (SELECT MIN(d.held_on) FROM live_day d WHERE d.live_id = l.live_id) AS first_day
    FROM live l
    ORDER BY l.fiscal_year, first_day IS NULL, first_day, l.live_id')->fetchAll();
$pos = array_search($liveId, array_map('intval', array_column($siblings, 'live_id')), true);
$prevLive = $pos !== false && $pos > 0 ? $siblings[$pos - 1] : null;
$nextLive = $pos !== false && $pos < count($siblings) - 1 ? $siblings[$pos + 1] : null;
render_header($live['name'], 'lives');
?>
<nav class="crumbs"><a href="./">ライブ</a><span>/</span><?= h(fmt_year($live['fiscal_year'])) ?></nav>
<section class="hero hero--live">
    <div>
        <p class="eyebrow"><?= h(fmt_year($live['fiscal_year'])) ?> · <?= count($days) ?> DAYS · <?= $totalBands ?> BANDS</p>
        <h1 class="display"><?= h($live['name']) ?></h1>
    </div>
    <!-- このライブでできること（YouTube・編集）。印刷時は CSS で隠す -->
    <div class="hero__actions no-print">
        <?php if ($live['youtube_url'] !== null && youtube_url_valid($live['youtube_url'])): // 表示の前にもう一度チェック（DB を直接いじられても変なリンクを出さない） ?>
            <a class="btn btn--sm btn--youtube" href="<?= h($live['youtube_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube で見る" title="YouTube で見る"><?= youtube_icon() ?></a>
        <?php endif; ?>
        <a class="btn btn--sm" href="live_edit?id=<?= (int)$liveId ?>"><?= icon('edit') ?> <span>ライブ<span class="pc-only">を</span>編集</span></a>
        <?php // スマホでは「を」を消して「ライブ編集」「タイムテーブル編集」にし、YouTube と3つを1段に収める ?>
        <a class="btn btn--sm" href="timetable_edit?id=<?= (int)$liveId ?>"><?= icon('schedule') ?> <span>タイムテーブル<span class="pc-only">を</span>編集</span></a>
    </div>
</section>

<?php if (count($days) > 1): ?>
<!-- 日程タブ。JS が動けばタブ切り替え、動かなければ全日程が縦に並ぶ -->
<div class="tabs tabs--days no-print" role="tablist">
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
                    <a href="<?= h($d['website_url']) ?>" target="_blank" rel="noopener"><?= h($d['venue_name']) ?> <?= icon('open_in_new', 'icon--sm') ?></a>
                <?php else: ?><?= h($d['venue_name'] ?? '—') ?><?php endif; ?>
            </dd></div>
            <!-- 総バンド数より登録が少ない日程は「登録済み / 総バンド数」 -->
            <div><dt>バンド</dt><dd><?= count($bands) ?><?= $dayTotal($d) > count($bands) ? ' / ' . $dayTotal($d) : '' ?></dd></div>
            <div><dt>曲数</dt><dd><?= $songs ?></dd></div>
            <?php if ($d['note']): ?><div><dt>メモ</dt><dd><?= h($d['note']) ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <ol class="timeline">
        <?php
        // 休憩が1つも登録されていない日程は、前のバンドの終了から次のバンドの開始までの空きを休憩として出す
        $breaks = $breaksByDay[(int)$d['live_day_id']] ?? gap_breaks($bands, 'BREAK');
        // 同じ名前の休憩が続くとき（休憩・休憩など）は1つにまとめて出す
        foreach (merge_same_breaks(timetable_rows($bands, $breaks)) as $r):
            if ($r['type'] === 'break'):
                $k = $r['row']; ?>
                <li class="slot slot--break">
                    <div class="slot__time"><?= h(fmt_time($k['start_time'])) ?><span><?= h(fmt_time($k['end_time'])) ?></span></div>
                    <div class="slot__body"><span class="break-label"><?= h($k['name']) ?></span></div>
                </li>
            <?php continue;
            endif;
            $bi = $r['key'];
            $b = $r['row'];
            $lineup = $lineups[$b['band_id']] ?? [];
            $memberIds = [];
            foreach ($lineup as $part) {
                foreach ($part['members'] as $m) {
                    $memberIds[] = (int)$m['member_id'];
                }
            }
            $isMine = $user['member_id'] && in_array($user['member_id'], $memberIds, true);
            // 最後 = トリ。ただし総バンド数まで登録されていない日程は、登録済みの最後が本当の最後ではないのでトリにしない
            $isLast = $bi === count($bands) - 1 && count($bands) >= $dayTotal($d); ?>
            <li class="slot<?= $isLast ? ' slot--headliner' : '' ?><?= $isMine ? ' slot--mine' : '' ?>">
                <div class="slot__time">
                    <?php if ($b['start_time']): ?><?= h(fmt_time($b['start_time'])) ?><span><?= h(fmt_time($b['end_time'])) ?></span>
                    <?php else: ?><span class="slot__no"><?= sprintf('%02d', (int)$b['play_order']) ?></span><?php endif; ?>
                </div>
                <div class="slot__body">
                    <div class="slot__head">
                        <span class="slot__order"><?= sprintf('%02d', (int)$b['play_order']) ?></span>
                        <h3 class="slot__name"><a href="band?id=<?= (int)$b['band_id'] ?>"><?= h($b['name']) ?></a></h3>
                        <?php if ($isLast): ?><span class="tag tag--tori" aria-label="トリ" title="トリ">🐦️</span><?php endif; ?>
                        <?php if ($b['needs_check']): ?><a class="tag tag--flag no-print" href="band?id=<?= (int)$b['band_id'] ?>" title="楽器の確認待ち" aria-label="楽器の確認待ち"><?= icon('flag', 'icon--fill') ?></a><?php endif; ?>
                        <?php if ($isMine): ?><span class="tag">出演</span><?php endif; ?>
                        <?php if ($b['youtube_url'] !== null && youtube_url_valid($b['youtube_url'])): ?>
                            <a class="slot__yt no-print" href="<?= h($b['youtube_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="「<?= h($b['name']) ?>」を YouTube で見る" title="YouTube で見る"><?= youtube_icon() ?></a>
                        <?php endif; ?>
                        <?= like_button((int)$b['band_id'], $likes[(int)$b['band_id']] ?? null, 'slot__like no-print') // 行の右端（CSS の margin-left: auto） ?>
                    </div>
                    <?php if ($lineup): ?>
                        <?= lineup_html($lineup, $user['member_id']) // サポート（1曲だけ出た人）は最後の「サポート」枠 ?>
                    <?php else: ?>
                        <p class="muted small">メンバー未登録</p>
                    <?php endif; ?>
                    <p class="slot__meta">
                        <?= band_artist_links($b, $omnibusArtists) // オムニバスなら曲のアーティストを全部 ?>
                        <?php if ($memberIds): ?><span><?= count(array_unique($memberIds)) ?>名</span><?php endif; ?>
                        <?php if ($b['song_count'] !== null): // NULL = 曲数不明なら出さない ?><span><?= (int)$b['song_count'] ?>曲</span><?php endif; ?>
                        <?= setlist_badge((int)$b['setlist_count'], (int)$b['song_count']) ?>
                        <?php if ($b['note']): ?><span class="keynote"><?= icon('piano') ?> <?= h($b['note']) ?></span><?php endif; ?>
                    </p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if (!$bands): ?><p class="muted">バンドが登録されていません</p><?php endif; ?>
    <p class="no-print add-band"><a class="btn btn--ghost btn--sm" href="band_edit?day=<?= (int)$d['live_day_id'] ?>">＋ バンドを追加</a></p>
</section>
<?php endforeach; ?>

<?php if ($prevLive || $nextLive): ?>
    <!-- 前後のライブ。見た目は band.php の前後のバンドと同じ（band-pager） -->
    <nav class="band-pager no-print" aria-label="前後のライブ">
        <?php foreach ([['prev', $prevLive, 'chevron_left', '前のライブ'], ['next', $nextLive, 'chevron_right', '次のライブ']] as [$dir, $l, $arrow, $label]): ?>
            <?php if ($l): ?>
                <a class="band-pager__link band-pager__link--<?= $dir ?>" href="live?id=<?= (int)$l['live_id'] ?>" rel="<?= $dir ?>">
                    <?= icon($arrow) ?>
                    <span class="band-pager__text">
                        <span class="band-pager__label"><?= $label ?><?= $l['first_day'] ? '（' . h(fmt_date($l['first_day'])) . '）' : '' ?></span>
                        <span class="band-pager__name"><?= h($l['name']) ?></span>
                    </span>
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php render_footer();
