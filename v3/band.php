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
$user = require_login();

$bandId = (int)($_GET['id'] ?? 0);
$pdo = db();

// ---- 1. バンド本体 ----
$st = $pdo->prepare('SELECT b.*, a.name AS artist_name, d.live_id, d.label, d.held_on, l.name AS live_name, l.fiscal_year,
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
    echo '<div class="empty card"><p class="empty__title">バンドが見つかりません</p><a class="btn" href="index.php">一覧へ戻る</a></div>';
    render_footer();
    exit;
}
$liveUrl = 'live.php?id=' . (int)$band['live_id'] . '#day-' . (int)$band['live_day_id'];

// トリ = その日程で出演順が一番うしろ
$st = $pdo->prepare('SELECT MAX(play_order) FROM band WHERE live_day_id = ?');
$st->execute([$band['live_day_id']]);
$isLast = (int)$st->fetchColumn() === (int)$band['play_order'];

// ---- 2. メンバー（楽器ごとにまとめる。live.php と同じ形） ----
$st = $pdo->prepare('SELECT m.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id = ?
    ORDER BY i.sort_order, m.name');
$st->execute([$bandId]);
$lineup = []; // [sort_order] = ['short' => 'Vo', 'title' => 'ボーカル', 'members' => [...]]
$memberIds = [];
foreach ($st as $m) {
    $part = &$lineup[(int)$m['sort_order']];
    $part['short'] = $m['short_name'];
    $part['title'] = $m['instrument_name'];
    $part['members'][] = $m;
    unset($part);
    $memberIds[] = (int)$m['member_id'];
}
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
    WHERE s.band_id = ?');
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
            $shorts = array_column($rows, 'short_name');
            sort($shorts);
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

$when = array_filter([
    $band['label'],
    fmt_date($band['held_on']),
    sprintf('%02d', (int)$band['play_order']),
    $band['start_time'] ? fmt_time($band['start_time']) . '–' . fmt_time($band['end_time']) : '',
]);
render_header($band['name'], 'lives');
?>
<nav class="crumbs"><a href="index.php">ライブ</a><span>/</span><a href="<?= h($liveUrl) ?>"><?= h($band['live_name']) ?></a></nav>
<section class="hero">
    <div>
        <p class="eyebrow"><?= h(implode(' · ', $when)) ?></p>
        <h1 class="display"><?= h($band['name']) ?></h1>
        <p class="band-tags">
            <?php if ($isLast): ?><span class="tag tag--tori" aria-label="トリ" title="トリ"><?= icon('flutter_dash') ?></span><?php endif; ?>
            <?php if ($isMine): ?><span class="tag">出演</span><?php endif; ?>
            <?php if ($band['artist_id']): ?>
                <a href="artist.php?id=<?= (int)$band['artist_id'] ?>"><?= icon('search') ?> <?= h($band['artist_name']) ?></a>
            <?php endif; ?>
            <?php if ($band['venue_name']): ?><span class="muted"><?= h($band['venue_name']) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="hero__actions no-print">
        <a class="btn btn--sm" href="band_edit.php?id=<?= $bandId ?>"><?= icon('edit') ?> バンドを編集</a>
        <a class="btn btn--sm" href="songs_edit.php?band=<?= $bandId ?>"><?= icon('queue_music') ?> 曲を<?= $songs ? '編集' : '登録' ?></a>
    </div>
</section>

<section class="card band-section">
    <h2 class="section-title section-title--card">メンバー<?php if ($memberIds): ?> <small class="muted"><?= count(array_unique($memberIds)) ?>人</small><?php endif; ?></h2>
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
    <?php if ($band['note']): ?><p class="keynote"><?= icon('piano') ?> <?= h($band['note']) ?></p><?php endif; ?>
</section>

<section class="card band-section">
    <h2 class="section-title section-title--card">セットリスト <small class="muted"><?= $songs ? count($songs) : (int)$band['song_count'] ?>曲</small></h2>
    <?php if ($songs): ?>
        <div class="setlist"><ol>
            <?php foreach ($songs as $songId => $song):
                // オムニバスのときだけ、曲名の横にその曲のアーティスト（未設定ならバンドのアーティスト）
                $songArtist = $band['is_omnibus'] ? ($song['artist_name'] ?? $band['artist_name']) : null; ?>
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
        <p class="no-print"><a class="btn btn--ghost btn--sm" href="songs_edit.php?band=<?= $bandId ?>"><?= icon('queue_music') ?> 曲を登録</a></p>
    <?php endif; ?>
</section>
<?php render_footer();
