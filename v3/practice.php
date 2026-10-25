<?php
/**
 * =====================================================================
 *  practice.php — 練習プレイヤーの入口（ツールから入る。ログインしていれば誰でも = practice_allowed）
 * =====================================================================
 *  曲名・アーティストで楽曲データベースの曲を探し、その曲の YouTube リンク（song_link）の練習プレイヤー（tab?link=）へ飛ぶ。
 *    ・リンクがある曲 … ▶ で直接プレイヤーへ
 *    ・リンクが無い曲 … 動画検索（api_tab_save の yt_link_search）→ 選ぶと link_add で曲全体のカードに登録してプレイヤーへ
 *    ・曲が DB に無い … 楽曲情報を検索（api_track_search）→ songs.php の add_linked で登録して ?key= に戻る
 *  practice?key=曲キー … その曲だけを出す（登録直後の戻り先）
 *
 *  このページは表示するだけ（POST を受けない）。登録は api_tab_save / songs.php が CSRF を確かめる。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php'; // setlist_hidden_sql
require_once __DIR__ . '/lib/tabs.php';
$user = current_user(); // 未ログインでも検索と再生はできる。ピン留め・登録・動画検索・練習中・ライブはログイン者だけ
// TAB 譜は管理人だけ（tab_allowed）
$pdo = db();

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$onlyKey = is_string($_GET['key'] ?? null) && tab_key_valid($_GET['key']) ? $_GET['key'] : null;

// ---- 曲（song_key => 曲名・アーティスト）。楽曲データベース（songs.php）と同じ3つの出どころ + リンクだけある曲 ----
$songs = [];
$add = static function (string $key, ?string $title, ?string $artist, ?int $songId = null) use (&$songs): void {
    $songs[$key] ??= ['key' => $key, 'title' => (string)$title, 'artist' => (string)$artist, 'song_id' => $songId, 'links' => [], 'tabs' => 0];
};
if ($q !== '' || $onlyKey !== null) {
    $st = $pdo->query('SELECT s.song_id, s.title, s.band_id, s.track_source, s.track_id, COALESCE(s.artist_id, b.artist_id) AS artist_id,
            a.name AS artist_name, t.title AS track_title, t.artist_name AS track_artist
        FROM song s
        JOIN band b ON b.band_id = s.band_id
        LEFT JOIN artist a ON a.artist_id = COALESCE(s.artist_id, b.artist_id)
        LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
        WHERE s.is_se = 0 AND NOT ' . setlist_hidden_sql('b') . '
        ORDER BY s.song_id DESC'); // 非公開のセットリストの曲は出さない（api_tab_save の tab_resolve_song と同じ条件。出すと未ログインの動画検索が「曲が見つかりません」になる）
    foreach ($st as $r) {
        $key = tab_song_key($r['track_source'], $r['track_id'], $r['artist_id'] === null ? null : (int)$r['artist_id'],
            (int)$r['band_id'], $r['title'], $r['artist_name']);
        $linked = $r['track_source'] !== null;
        $add($key, $linked ? $r['track_title'] : $r['title'], $linked ? $r['track_artist'] : $r['artist_name'], (int)$r['song_id']);
    }
    foreach ($pdo->query('SELECT c.song_key, COALESCE(t.title, c.title) AS title, COALESCE(t.artist_name, c.artist_name) AS artist
        FROM song_catalog c LEFT JOIN track t ON t.source = c.track_source AND t.track_id = c.track_id') as $r) {
        $add($r['song_key'], $r['title'], $r['artist']);
    }
    foreach ($pdo->query('SELECT song_key, MAX(title) AS title, MAX(artist_name) AS artist FROM song_tab GROUP BY song_key') as $r) {
        $add($r['song_key'], $r['title'], $r['artist']);
    }
    foreach ($pdo->query('SELECT song_key, MAX(title) AS title, MAX(artist_name) AS artist FROM song_link GROUP BY song_key') as $r) {
        $add($r['song_key'], $r['title'], $r['artist']);
    }

    if ($onlyKey !== null) {
        $songs = isset($songs[$onlyKey]) ? [$onlyKey => $songs[$onlyKey]] : [];
    } else {
        // 空白で区切った語が全部、曲名かアーティスト名のどこかに入っている曲
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $songs = array_filter($songs, static function (array $s) use ($words): bool {
            $hay = $s['title'] . ' ' . $s['artist'];
            foreach ($words as $w) {
                if (mb_stripos($hay, $w) === false) {
                    return false;
                }
            }
            return true;
        });
    }
}

// ---- 見つかった曲の YouTube リンク ----
if ($songs) {
    $keys = array_keys($songs);
    $st = $pdo->prepare('SELECT link_id, song_key, part, url, label FROM song_link WHERE site = \'youtube\' AND song_key IN ('
        . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY part, link_id');
    $st->execute($keys);
    foreach ($st as $r) {
        $songs[$r['song_key']]['links'][] = $r;
    }
    // TAB の数 = 自作 TAB + リンク（MV は除く）。楽曲データベース（songs.php）の「TAB ◯」と同じ数え方
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT song_key, COUNT(*) FROM song_tab WHERE song_key IN ($in) GROUP BY song_key
        UNION ALL SELECT song_key, COUNT(*) FROM song_link WHERE site <> 'mv' AND song_key IN ($in) GROUP BY song_key");
    $st->execute([...$keys, ...$keys]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) {
        $songs[$k]['tabs'] += (int)$n;
    }
    // リンクがある曲を先に、あとは曲名順
    uasort($songs, static fn($a, $b) => [!$a['links'], mb_strtolower($a['title'])] <=> [!$b['links'], mb_strtolower($b['title'])]);
    $songs = array_slice($songs, 0, 30, true);
}

// ---- 入力中の候補（?suggest=1&q=…）: 上と同じ検索の結果を JSON で返す。リンクがあれば先頭の動画へ直接 ----
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['items' => array_map(static fn($s) => [
        'title' => $s['title'], 'artist' => $s['artist'], 'links' => count($s['links']),
        'url' => $s['links'] ? 'tab?link=' . (int)$s['links'][0]['link_id'] : 'practice?key=' . rawurlencode($s['key']),
    ], array_values(array_slice($songs, 0, 8)))], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 検索していないとき: 自分が練習したことのある動画（練習の設定 song_link_practice がある） ----
$mine = [];
if ($user !== null && $q === '' && $onlyKey === null) {
    try { // practicing 列はマイグレーション 078。まだなら全部を出す
        $st = $pdo->prepare('SELECT l.link_id, l.title, l.artist_name, l.label, l.part FROM song_link_practice p
            JOIN song_link l ON l.link_id = p.link_id WHERE p.user_id = ? AND p.practicing = 1 AND l.site = \'youtube\' ORDER BY l.link_id DESC LIMIT 20');
        $st->execute([(int)$user['user_id']]);
    } catch (PDOException) {
        $st = $pdo->prepare('SELECT l.link_id, l.title, l.artist_name, l.label, l.part FROM song_link_practice p
            JOIN song_link l ON l.link_id = p.link_id WHERE p.user_id = ? AND l.site = \'youtube\' ORDER BY l.link_id DESC LIMIT 20');
        $st->execute([(int)$user['user_id']]);
    }
    $mine = $st->fetchAll();
}

// ---- 検索していないとき: 自分が出演する直近のライブ（開催日が今日以降）のセットリスト。リンクがあれば先頭の動画へ ----
$liveName = null;
$liveSongs = [];
if ($user !== null && $q === '' && $onlyKey === null) {
    $st = $pdo->prepare('SELECT d.live_id, l.name FROM live_day d JOIN live l ON l.live_id = d.live_id
        JOIN band b ON b.live_day_id = d.live_day_id JOIN band_member bm ON bm.band_id = b.band_id
        WHERE bm.member_id = ? AND d.held_on >= CURDATE() ORDER BY d.held_on LIMIT 1');
    $st->execute([(int)$user['member_id']]);
    if ($live = $st->fetch()) {
        $liveName = (string)$live['name'];
        $st = $pdo->prepare('SELECT DISTINCT s.song_id, sp.instrument_id, s.title, s.band_id, s.track_source, s.track_id, COALESCE(s.artist_id, b.artist_id) AS artist_id,
                a.name AS artist_name, t.title AS track_title, t.artist_name AS track_artist
            FROM song s
            JOIN band b ON b.band_id = s.band_id
            JOIN live_day d ON d.live_day_id = b.live_day_id
            JOIN song_performer sp ON sp.song_id = s.song_id AND sp.member_id = ?
            LEFT JOIN artist a ON a.artist_id = COALESCE(s.artist_id, b.artist_id)
            LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
            WHERE d.live_id = ? AND s.is_se = 0
            ORDER BY d.held_on, b.play_order, s.track_no');
        $st->execute([(int)$user['member_id'], (int)$live['live_id']]);
        foreach ($st as $r) {
            $linked = $r['track_source'] !== null;
            $key = tab_song_key($r['track_source'], $r['track_id'], $r['artist_id'] === null ? null : (int)$r['artist_id'],
                (int)$r['band_id'], $r['title'], $r['artist_name']);
            $liveSongs[$key] ??= ['key' => $key, 'link_id' => null, 'inst' => [],
                'title' => (string)($linked ? $r['track_title'] : $r['title']), 'artist' => (string)($linked ? $r['track_artist'] : $r['artist_name'])];
            $liveSongs[$key]['inst'][(int)$r['instrument_id']] = true; // 自分がその曲で担当する楽器
        }
        if ($liveSongs) {
            // 動画は、自分の担当楽器のパート（song_part.instrument_id）のものを先に。無ければ最初に登録された動画
            $keys = array_keys($liveSongs);
            $st = $pdo->prepare('SELECT l.song_key, l.link_id, p.instrument_id FROM song_link l
                LEFT JOIN song_part p ON p.song_key = l.song_key AND p.part = l.part
                WHERE l.site = \'youtube\' AND l.song_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY l.link_id');
            $st->execute($keys);
            foreach ($st as $r) {
                $s = &$liveSongs[$r['song_key']];
                $own = $r['instrument_id'] !== null && isset($s['inst'][(int)$r['instrument_id']]);
                if ($s['link_id'] === null || ($own && empty($s['own']))) {
                    $s['link_id'] = (int)$r['link_id'];
                    $s['own'] = $own;
                }
                unset($s);
            }
        }
    }
}

// ---- ピン留め（practice_pin。マイグレーション 077 がまだなら無いものとして扱う） ----
$pins = [];
if ($user !== null) {
    try {
        $st = $pdo->prepare('SELECT song_key, title, artist_name FROM practice_pin WHERE user_id = ? ORDER BY created_at DESC');
        $st->execute([(int)$user['user_id']]);
        foreach ($st as $r) {
            $pins[$r['song_key']] = ['key' => $r['song_key'], 'title' => $r['title'], 'artist' => (string)$r['artist_name'], 'link_id' => null];
        }
    } catch (PDOException) {
        $pins = [];
    }
}
if ($pins && $q === '' && $onlyKey === null) {
    $keys = array_keys($pins);
    $st = $pdo->prepare('SELECT song_key, MIN(link_id) FROM song_link WHERE site = \'youtube\' AND song_key IN ('
        . implode(',', array_fill(0, count($keys), '?')) . ') GROUP BY song_key');
    $st->execute($keys);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $id]) {
        $pins[$k]['link_id'] = (int)$id;
    }
}
/** ピン留めの切り替えボタン（assets/practice.js が api_practice_pin に送る） */
$pinBtn = static fn(string $key, string $title, string $artist): string =>
    '<button type="button" class="icon-btn prac-pin' . (isset($pins[$key]) ? ' is-on' : '') . '" data-pin data-key="' . h($key)
    . '" data-title="' . h($title) . '" data-artist="' . h($artist) . '" aria-pressed="' . (isset($pins[$key]) ? 'true' : 'false')
    . '" title="ピン留め" aria-label="ピン留め">' . icon('push_pin') . '</button>';

$hasYoutubeKey = (string)(config('youtube')['api_key'] ?? '') !== ''; // キーが無いとき・API の枠を使い切ったときは、動画の URL を貼り付けて登録する

render_header('練習プレイヤー', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/tab.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/practice.css') ?>">
<?php if ($user !== null): ?><nav class="crumbs"><a href="tools">ツール</a></nav><?php endif; ?>
<h1 class="display display--sm">練習プレイヤー</h1>

<?php if ($user === null): // 未ログインのピン留めは端末に保存（assets/practice.js） ?>
    <script>window.PRAC_GUEST = true;</script>
<?php endif; ?>
<form method="get" class="prac-search" data-prac-suggest>
    <input type="search" class="search" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="曲名 アーティスト名" aria-label="曲名 アーティスト名" autocomplete="off" autofocus>
    <ul class="prac-suggest" data-prac-suggest-list hidden></ul>
    <button type="submit" class="btn btn--primary" aria-label="検索" title="検索"><?= icon('search') ?></button>
</form>

<?php if ($user === null && $q === '' && $onlyKey === null): ?>
    <h2 class="section-title" data-guest-pins-head hidden><?= icon('push_pin') ?> ピン留め</h2>
    <ul class="prac-list" data-guest-pins hidden></ul>
<?php endif; ?>

<?php if ($pins && $q === '' && $onlyKey === null): ?>
    <h2 class="section-title"><?= icon('push_pin') ?> ピン留め</h2>
    <ul class="prac-list">
        <?php foreach ($pins as $s): ?>
            <li class="card prac-item">
                <a class="prac-item__main" href="<?= $s['link_id'] ? 'tab?link=' . $s['link_id'] : 'practice?key=' . rawurlencode($s['key']) ?>">
                    <?= icon($s['link_id'] ? 'slow_motion_video' : 'search') ?>
                    <span class="prac-item__body">
                        <span class="prac-item__title"><?= h($s['title']) ?></span>
                        <span class="muted small"><?= h($s['artist']) ?></span>
                    </span>
                </a>
                <?= $pinBtn($s['key'], $s['title'], $s['artist']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($liveSongs): // 展開・収納できる（details） ?>
    <details class="card prac-live" open>
        <summary class="tab-summary"><?= icon('queue_music') ?> <?= h($liveName) ?> <span class="muted small"><?= count($liveSongs) ?></span> <?= icon('expand_more', 'tab-summary__chev') ?></summary>
        <ul class="prac-list">
            <?php foreach (array_values($liveSongs) as $i => $s): ?>
                <li class="card prac-item" style="--i: <?= min($i, 10) ?>">
                    <a class="prac-item__main" href="<?= $s['link_id'] ? 'tab?link=' . $s['link_id'] : 'practice?key=' . rawurlencode($s['key']) ?>">
                        <?= icon($s['link_id'] ? 'slow_motion_video' : 'search') ?>
                        <span class="prac-item__body">
                            <span class="prac-item__title"><?= h($s['title']) ?></span>
                            <span class="muted small"><?= h($s['artist']) ?></span>
                        </span>
                    </a>
                    <?= $pinBtn($s['key'], $s['title'], $s['artist']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<?php if ($mine): ?>
    <h2 class="section-title"><?= icon('history') ?> 練習中</h2>
    <ul class="prac-list">
        <?php foreach ($mine as $m): ?>
            <li class="prac-row">
                <a class="card prac-item prac-item--link" href="tab?link=<?= (int)$m['link_id'] ?>">
                    <?= icon('slow_motion_video') ?>
                    <span class="prac-item__body">
                        <span class="prac-item__title"><?= h($m['title']) ?></span>
                        <span class="muted small"><?= h((string)$m['artist_name']) ?><?= $m['label'] ? ' · ' . h($m['label']) : '' ?></span>
                    </span>
                </a>
                <button type="button" class="icon-btn prac-row__x" data-unpractice="<?= (int)$m['link_id'] ?>" title="練習中から外す" aria-label="練習中から外す"><?= icon('close') ?></button>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($q !== '' || $onlyKey !== null): ?>
    <?php if ($songs): ?>
        <ul class="prac-list">
            <?php foreach ($songs as $s): ?>
                <li class="card prac-item">
                    <span class="prac-item__body">
                        <span class="prac-item__title"><?= h($s['title']) ?></span>
                        <span class="muted small"><?= h($s['artist']) ?></span>
                    </span>
                    <span class="prac-item__acts">
                        <?= $pinBtn($s['key'], $s['title'], $s['artist']) ?>
                        <?php foreach ($s['links'] as $i => $l): // パートのリンクはパート名、曲全体のリンクが複数あれば番号 ?>
                            <a class="btn btn--sm btn--primary" href="tab?link=<?= (int)$l['link_id'] ?>" title="<?= h($l['label'] ?: $l['url']) ?>"><?= icon('play_arrow') ?><?= $l['part'] !== '' ? ' ' . h($l['part']) : (count($s['links']) > 1 ? ' ' . ($i + 1) : '') ?></a>
                        <?php endforeach; ?>
                        <?php // 動画検索ダイアログ（URL の貼り付けもここから） ?>
                            <button type="button" class="btn btn--sm" data-yts-open data-key="<?= h($s['key']) ?>"<?= $s['song_id'] !== null ? ' data-song-id="' . (int)$s['song_id'] . '"' : '' ?>
                                data-q="<?= h(mb_substr(trim($s['title'] . ' ' . $s['artist']), 0, 100)) ?>"
                                data-urls="<?= h(json_encode(array_column($s['links'], 'link_id', 'url'), JSON_UNESCAPED_SLASHES)) ?>"
                                <?= $hasYoutubeKey && $onlyKey !== null && !$s['links'] ? 'data-auto' : '' ?> title="動画検索"><?= icon('search') ?><?= $s['links'] ? '' : ' 動画検索' ?></button>
                        <?php if (tab_allowed()): // TAB 譜は管理人だけ ?><a class="btn btn--sm songs-row__tab<?= $s['tabs'] > 0 ? ' is-on' : '' ?>" href="<?= h($s['song_id'] !== null ? 'tab?song=' . $s['song_id'] : 'tab?key=' . rawurlencode($s['key'])) ?>" aria-label="TAB 譜" title="TAB 譜"><?= tab_icon($s['tabs'] > 0) ?> <?= $s['tabs'] ?></a><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($onlyKey !== null): ?>
        <div class="empty card"><p class="empty__title">曲が見つかりません</p></div>
    <?php endif; ?>

    <?php if ($onlyKey === null && $user === null && $q !== ''): // 未ログインは DB に登録せず、検索語のまま動画を探して練習する（設定は端末に保存） ?>
        <button type="button" class="btn" data-yts-open data-free data-q="<?= h($q) ?>" title="YouTube で探す"><?= icon('smart_display') ?> YouTube で探す</button>
    <?php endif; ?>

    <?php if ($onlyKey === null && $user !== null): // DB に無ければ、楽曲情報（Spotify / iTunes）から登録する ?>
        <details class="card prac-new"<?= $songs ? '' : ' open' ?> data-prac-new data-q="<?= h($q) ?>">
            <summary><?= icon('add') ?> 楽曲データベースに登録</summary>
            <ul class="prac-new__list" data-prac-new-list></ul>
            <form method="post" action="songs" data-prac-new-form hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_linked">
                <input type="hidden" name="to" value="practice">
                <input type="hidden" name="track" value="">
            </form>
        </details>
    <?php endif; ?>
<?php endif; ?>

<?php // 動画検索ダイアログ（見た目は tab.css の .yts を使い回す）。API が使えないときは URL を貼り付けて登録 ?>
<dialog class="yts" data-yts>
    <form class="yts__bar" data-yts-form>
        <input type="search" name="q" maxlength="100" aria-label="検索語" autocomplete="off">
        <button type="submit" class="btn btn--primary btn--sm" title="検索" aria-label="検索"><?= icon('search') ?></button>
        <button type="button" class="icon-btn" data-yts-close title="閉じる" aria-label="閉じる"><?= icon('close') ?></button>
    </form>
    <form class="yts__bar" data-yts-paste>
        <input type="url" name="url" maxlength="300" placeholder="https://www.youtube.com/watch?v=…" aria-label="動画の URL" autocomplete="off">
        <button type="submit" class="btn btn--sm" title="URL から追加" aria-label="URL から追加"><?= icon('link') ?></button>
    </form>
    <div class="yts__chips">
        <?php foreach (['ギター', 'ベース', 'ドラム', '弾いてみた', 'TAB', 'ライブ'] as $w): ?>
            <button type="button" class="tab-chip" data-yts-word="<?= h($w) ?>"><?= h($w) ?></button>
        <?php endforeach; ?>
    </div>
    <ul class="yts__list" data-yts-list></ul>
</dialog>
<script src="<?= asset_url('assets/practice.js') ?>" defer></script>
<?php render_footer();
