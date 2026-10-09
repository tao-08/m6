<?php
/**
 * =====================================================================
 *  band.php?id=バンドID — バンド詳細（メンバー・セットリスト）
 * =====================================================================
 *  ライブページ（live.php）はタイムテーブルの一覧に集中させて、
 *  セットリストや「編集」ボタンはこのページに置く。ライブページのバンド名からここへ来る。
 *
 *  SQL は4回（どれも WHERE band_id = ? で1バンド分だけ）:
 *    1. バンド本体（+ 日程・ライブ・会場・アーティスト）
 *    2. メンバーと楽器（band_member）
 *    3. 曲（song）+ 紐付けた曲（track）+ 曲ごとのアーティスト（オムニバス用）
 *    4. 曲ごとの演奏者（song_performer）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/repository.php';
$user = require_login();

$bandId = (int)($_GET['id'] ?? 0);
$pdo = db();

// ---- 1. バンド本体 ----
$st = $pdo->prepare('SELECT b.*, a.name AS artist_name, d.live_id, d.label, d.held_on, d.total_bands, l.name AS live_name, l.fiscal_year,
        v.name AS venue_name
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    LEFT JOIN venue v ON v.venue_id = d.venue_id
    LEFT JOIN artist a ON a.artist_id = b.artist_id
    WHERE b.band_id = ?');
$st->execute([$bandId]);
$band = $st->fetch();
if (!$band) {
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">バンドが見つかりません</p><a class="btn" href="./">一覧へ戻る</a></div>';
    render_footer();
    exit;
}
$liveUrl = 'live?id=' . (int)$band['live_id'] . '#day-' . (int)$band['live_day_id'];

// 🚩「楽器の確認」の「確認した」ボタン（ログインしていれば誰でも外せる）
if (is_post() && ($_POST['action'] ?? '') === 'checked') {
    verify_csrf();
    $pdo->prepare('UPDATE band SET needs_check = 0 WHERE band_id = ?')->execute([$bandId]);
    flash('確認済みにしました');
    redirect('band?id=' . $bandId);
}

// トリ = その日程で出演順が一番うしろ。組数（COUNT）は「7/14」の分母に使う（MAX だと欠番があるとずれる）
//   総バンド数（live_day.total_bands）が入っていれば分母はそちら。登録済みが総バンド数に足りない日程は、
//   登録済みの最後が本当の最後ではないのでトリにしない
$st = $pdo->prepare('SELECT MAX(play_order), COUNT(*) FROM band WHERE live_day_id = ?');
$st->execute([$band['live_day_id']]);
[$maxOrder, $registered] = $st->fetch(PDO::FETCH_NUM);
$bandCount = max((int)$registered, (int)$band['total_bands']);
$isLast = (int)$maxOrder === (int)$band['play_order'] && (int)$registered >= $bandCount;

// 「← 前のバンド / 次のバンド →」: 同じライブのバンドを live.php と同じ順（日程 → 出演順）に並べて、前後を取る
//   日程をまたぐ（1日目のトリの次 = 2日目の1番目）。1ライブ数十組なので全部取ってきて PHP で探す
$st = $pdo->prepare('SELECT b.band_id, b.name, d.label
    FROM band b JOIN live_day d ON d.live_day_id = b.live_day_id
    WHERE d.live_id = ?
    ORDER BY d.held_on IS NULL, d.held_on, d.live_day_id, b.play_order');
$st->execute([$band['live_id']]);
$siblings = $st->fetchAll();
$pos = array_search($bandId, array_map('intval', array_column($siblings, 'band_id')), true);
$prevBand = $pos !== false && $pos > 0 ? $siblings[$pos - 1] : null;
$nextBand = $pos !== false && $pos < count($siblings) - 1 ? $siblings[$pos + 1] : null;

// ---- 2. メンバー（楽器ごとにまとめる。live.php と同じ形） ----
$st = $pdo->prepare('SELECT bm.band_id, m.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id = ?
    ORDER BY i.sort_order, m.name');
$st->execute([$bandId]);
$rows = $st->fetchAll();
$lineup = lineup_by_part($rows); // [並び順] = ['short' => 'Vo/Gt', 'title' => 'ギターボーカル', 'segments' => [Vo, Gt], 'members' => [...]]
$memberIds = array_map('intval', array_column($rows, 'member_id'));
$isMine = $user['member_id'] && in_array($user['member_id'], $memberIds, true);

// ---- 3. 曲 ----
// オムニバスのときだけ曲ごとのアーティストを出す。song.artist_id が NULL = バンドのアーティストと同じ
$st = $pdo->prepare('SELECT s.song_id, s.track_no, s.title, a.name AS artist_name,
        t.source, t.track_id, t.title AS track_title, t.artist_name AS track_artist, t.artwork_url
    FROM song s
    LEFT JOIN artist a ON a.artist_id = s.artist_id
    LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
    WHERE s.band_id = ?
    ORDER BY s.track_no');
$st->execute([$bandId]);
$songs = [];
foreach ($st as $s) {
    $songs[$s['song_id']] = $s + ['players' => []];
}

// ---- 4. 曲ごとの演奏者 ----
$st = $pdo->prepare('SELECT sp.song_id, sp.member_id, m.name, i.short_name FROM song_performer sp
    JOIN song s ON s.song_id = sp.song_id
    JOIN member m ON m.member_id = sp.member_id
    JOIN instrument i ON i.instrument_id = sp.instrument_id
    WHERE s.band_id = ?
    ORDER BY i.sort_order'); // 楽器の並び順（Vo が先頭）。下の song_notes で「Vo/Gt」の順に並べるため
$st->execute([$bandId]);
foreach ($st as $r) {
    $songs[$r['song_id']]['players'][(int)$r['member_id']][] = $r;
}

/**
 * 曲ごとの「いつもと違うところ」だけを短い文にする。
 *
 * 「いつも」= そのバンドの曲の中で一番多い楽器の組み合わせ（最頻値）。
 *   例: 4曲中3曲 Gt、1曲だけ Key → その1曲にだけ「鈴木: Key」と出す。
 * band_member（バンドでの担当）と比べないのは、曲で持ち替えた楽器も band_member に足されるため
 * （Gt と Key の両方が担当になり、どの曲も「いつもと違う」になってしまう）。
 */
function song_notes(array $songs, array $lineup): array
{
    $names = [];
    foreach ($lineup as $part) {
        foreach ($part['members'] as $m) {
            $names[(int)$m['member_id']] = $m['name'];
        }
    }
    // メンバーごとに「楽器の組み合わせ → 何曲あったか」を数える
    $counts = [];
    $sets = [];
    foreach ($songs as $songId => $song) {
        foreach ($song['players'] as $memberId => $rows) {
            $shorts = array_column($rows, 'short_name'); // 楽器の並び順で入っている（Vo/Gt）
            $sets[$songId][$memberId] = implode('/', $shorts);
            $counts[$memberId][$sets[$songId][$memberId]] = ($counts[$memberId][$sets[$songId][$memberId]] ?? 0) + 1;
        }
    }
    $usual = array_map(static function ($c) {
        arsort($c);              // 多い順に並べて
        return array_key_first($c); // 一番多い組み合わせ
    }, $counts);

    $notes = [];
    foreach ($songs as $songId => $song) {
        $parts = [];
        $absent = array_diff_key($names, $song['players']);
        if ($absent) {
            $parts[] = implode('・', $absent) . ' は不参加';
        }
        foreach ($sets[$songId] ?? [] as $memberId => $set) {
            if ($set !== ($usual[$memberId] ?? $set)) {
                $parts[] = $song['players'][$memberId][0]['name'] . ': ' . $set;
            }
        }
        $notes[$songId] = implode('、', $parts);
    }
    return $notes;
}
$notes = song_notes($songs, $lineup);

// 紐付けた曲の「聴く」リンク。見ている人の音楽アプリに合わせる（member.php のアルバムと同じ考え方）
$viewerApp = member_music_app($pdo, $user['member_id']);
$trackKeys = [];
foreach ($songs as $s) {
    if ($s['source'] !== null) {
        $trackKeys[] = album_key($s['source'], $s['track_id']);
    }
}
$linkCache = track_link_cache_for($pdo, $viewerApp, $trackKeys);

// ---- 演奏したアーティストをマイアルバムに入れているメンバー（artist.php と同じ部品。lib/albums.php） ----
//   ふつうはバンドのアーティスト1組。オムニバスなら曲ごとのアーティスト全部
$omnibus = $band['is_omnibus'] ? (omnibus_artists_by_band($pdo, [$bandId])[$bandId] ?? []) : [];
$fanArtists = $omnibus ? array_column($omnibus, 'name', 'artist_id')
    : ($band['artist_id'] !== null ? [(int)$band['artist_id'] => $band['artist_name']] : []);
$fans = fan_albums($pdo, array_keys($fanArtists));

render_header($band['name'], 'lives');
?>
<nav class="crumbs"><a href="./">ライブ</a><span>/</span><a href="<?= h($liveUrl) ?>"><?= h($band['live_name']) ?></a></nav>
<section class="hero">
    <div>
        <h1 class="display"><?= h($band['name']) ?></h1>
        <p class="band-meta">
            <?php if ($band['held_on']): ?><span><?= icon('event') ?> <?= h(fmt_date($band['held_on'])) ?></span><?php endif; ?>
            <a class="band-meta__live" href="<?= h($liveUrl) ?>"><?= h($band['live_name']) ?></a>
            <?php if ($band['label']): ?><span><?= h($band['label']) ?></span><?php endif; ?>
            <span class="band-meta__order" title="出演順"><?= (int)$band['play_order'] ?>/<?= (int)$bandCount ?></span>
            <?php if ($band['venue_name']): ?><span><?= icon('location_on') ?> <?= h($band['venue_name']) ?></span><?php endif; ?>
        </p>
        <p class="band-tags">
            <?php if ($isLast): ?><span class="tag tag--tori" aria-label="トリ" title="トリ">🐦️</span><?php endif; ?>
            <?php if ($isMine): ?><span class="tag">出演</span><?php endif; ?>
            <?= band_artist_links($band, $band['is_omnibus'] ? omnibus_artists_by_band($pdo, [(int)$band['band_id']]) : []) // オムニバスなら曲のアーティストを全部 ?>
        </p>
    </div>
    <div class="hero__actions no-print">
        <?php if ($band['youtube_url'] !== null && youtube_url_valid($band['youtube_url'])): // 表示の前にもう一度チェック（DB を直接いじられても変なリンクを出さない） ?>
            <a class="btn btn--sm btn--youtube" href="<?= h($band['youtube_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube で見る" title="YouTube で見る"><?= youtube_icon() ?></a>
        <?php endif; ?>
        <a class="btn btn--sm" href="band_edit?id=<?= $bandId ?>"><?= icon('edit') ?> バンドを編集</a>
        <a class="btn btn--sm" href="songs_edit?band=<?= $bandId ?>"><?= icon('queue_music') ?> 曲を<?= $songs ? '編集' : '登録' ?></a>
    </div>
</section>

<?php if ($band['needs_check']): ?>
    <!-- 🚩 取り込みで「楽器があやしい」と印が付いたバンド。直したら「確認した」で外す（バンド編集で保存しても外れる） -->
    <form method="post" class="flash flash--warn check-flag no-print">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="checked">
        <span><?= icon('flag', 'icon--fill flag-icon') ?> メンバーの楽器の登録が正しいか確認の依頼が届いています。 （特にボーカルかギターボーカルかどうか）</span>
        <button class="btn btn--sm" type="submit">確認済み</button>
    </form>
<?php endif; ?>

<section class="card band-section">
    <h2 class="section-title section-title--card">メンバー<?php if ($memberIds): ?> <small class="muted"><?= count(array_unique($memberIds)) ?>人</small><?php endif; ?></h2>
    <?php if ($lineup): ?>
        <ul class="lineup">
            <?php foreach ($lineup as $part): ?>
                <li><?= part_badge($part) ?>
                    <?php foreach ($part['members'] as $m): ?>
                        <a class="chip<?= (int)$m['member_id'] === $user['member_id'] ? ' chip--me' : '' ?>" href="member?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a>
                    <?php endforeach; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted small">メンバー未登録</p>
    <?php endif; ?>
    <?php if ($band['note']): ?><p class="keynote"><?= icon('piano') ?> <?= h($band['note']) ?></p><?php endif; ?>
</section>

<section class="card band-section">
    <?php // 曲が未登録のときは band.song_count（タイムテーブルの曲数）を「予定」として出す。登録済みの曲数と混ぜない ?>
    <h2 class="section-title section-title--card">セットリスト <small class="muted"><?= $songs ? count($songs) . '曲' : '' ?></small></h2>
    <?php if ($songs): ?>
        <div class="setlist"><ol>
            <?php foreach ($songs as $songId => $song):
                // オムニバスのときだけ、曲名の横にその曲のアーティスト（曲に付いていなければ出さない）
                $songArtist = $band['is_omnibus'] ? $song['artist_name'] : null; ?>
                <li>
                    <?php if ($song['source'] !== null):
                        // 紐付けた曲: 曲名はただの文字。ジャケットにマウスを乗せると音楽アプリのアイコンが重なって出て、押すと聴ける
                        $k = album_key($song['source'], $song['track_id']);
                        $track = ['source' => $song['source'], 'track_id' => $song['track_id'], 'title' => $song['track_title'], 'artist_name' => $song['track_artist']];
                        $listenApp = listen_app($viewerApp, $song['source']); ?>
                        <span class="setlist__song"><span class="setlist__artwrap"><img class="setlist__art" src="<?= h($song['artwork_url']) ?>" alt="" loading="lazy" width="56" height="56"><a class="setlist__listen setlist__listen--<?= h($listenApp) ?>" href="<?= h(track_listen_url($viewerApp, $track, array_key_exists($k, $linkCache) ? $linkCache[$k] : false)) ?>" target="_blank" rel="noopener" aria-label="<?= h(MUSIC_APPS[$listenApp]) ?> で聴く"><?= MUSIC_APP_ICONS[$listenApp] ?></a></span><?= h($song['title']) ?></span>
                    <?php else: ?>
                        <span class="setlist__song"><span class="setlist__art setlist__art--none"><?= icon('music_note') ?></span><?= h($song['title']) ?></span>
                    <?php endif; ?>
                    <?php if ($songArtist): ?><span class="setlist__artist"><?= h($songArtist) ?></span><?php endif; ?>
                    <?php if ($notes[$songId] !== ''): ?><span class="setlist__who"><?= h($notes[$songId]) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol></div>
    <?php else: ?>
        <p class="muted">まだ曲が登録されていません</p>
        <p class="no-print"><a class="btn btn--ghost btn--sm" href="songs_edit?band=<?= $bandId ?>"><?= icon('queue_music') ?> 曲を登録</a></p>
    <?php endif; ?>
</section>

<?= fan_albums_html($fans, 'マイアルバムに入れているメンバー', $viewerApp, count($fanArtists) > 1) // 見出しはアーティストページと同じ。何組もいるとき（オムニバス）はアーティスト名も ?>

<?php if ($prevBand || $nextBand): ?>
    <!-- 前後のバンド。日程が変わるときは「2日目」などを名前の上に出す -->
    <nav class="band-pager no-print" aria-label="前後のバンド">
        <?php foreach ([['prev', $prevBand, 'chevron_left', '前のバンド'], ['next', $nextBand, 'chevron_right', '次のバンド']] as [$dir, $b, $arrow, $label]): ?>
            <?php if ($b): ?>
                <a class="band-pager__link band-pager__link--<?= $dir ?>" href="band?id=<?= (int)$b['band_id'] ?>" rel="<?= $dir ?>">
                    <?= icon($arrow) ?>
                    <span class="band-pager__text">
                        <span class="band-pager__label"><?= $label ?><?= $b['label'] !== $band['label'] && $b['label'] ? '（' . h($b['label']) . '）' : '' ?></span>
                        <span class="band-pager__name"><?= h($b['name']) ?></span>
                    </span>
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php render_footer();
