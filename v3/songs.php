<?php
/**
 * =====================================================================
 *  songs.php — 楽曲データベース（管理人だけ。tools.php から入る）
 * =====================================================================
 *  セットリストに出てきた曲を「曲ごと」にまとめた一覧。曲ごとの TAB 譜（tab.php）への入口。
 *  一覧に載るのは次の3つを、曲キー（song_key。形は lib/tabs.php の tab_song_key）でまとめたもの:
 *    1. セットリストの曲（song。SE は除く）  … 自動。演奏回数・最終演奏日・演奏したバンドも出す
 *    2. 手動で登録した曲（song_catalog）      … セットリストに無い曲にも TAB を付けられるように
 *    3. TAB だけある曲（song_tab）            … 上の2つのどちらにも無いキー（元の曲が消えたなど）
 *
 *  手動登録は2通り（どちらも POST・管理人だけ・CSRF 対策あり）:
 *    楽曲情報を検索 … 🔍で Spotify / iTunes の曲を探して選ぶ。曲名・アーティスト名はブラウザから来た値を信用せず、
 *                   曲のキーだけ受け取ってサーバーが取り直す（track_lookup）
 *    連携なし     … 曲名 + アーティストマスタ（artist）にいるアーティスト。
 *                   自由入力のアーティストを許すと、"t:a<artist_id>|…" とは別の形のキーが要り、
 *                   表記ゆれで同じ曲が別のキーになってしまうので、必ず既存のアーティストから選ばせる
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/tabs.php';
require_login();
if (!is_admin()) {
    error_page(403, '管理人のみ利用できます');
}
$user = current_user();
$musicApp = member_music_app(db(), $user['member_id'] ?? null); // 見ている人の音楽アプリ（ジャケット・アルバム名のリンク先）
$pdo = db();

/** アーティスト名（またはその別名）から、マスタのアーティストを探す。無ければ null */
function songs_find_artist(PDO $pdo, string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT artist_id, name FROM artist WHERE name = ?');
    $st->execute([$name]);
    $row = $st->fetch();
    if (!$row) {
        $st = $pdo->prepare('SELECT a.artist_id, a.name FROM artist_alias al JOIN artist a ON a.artist_id = al.artist_id WHERE al.name = ?');
        $st->execute([$name]);
        $row = $st->fetch();
    }
    return $row ?: null;
}

/** song_catalog に1行入れる。同じ曲キーがすでにあれば false（UNIQUE 違反） */
function songs_catalog_insert(PDO $pdo, string $key, string $title, ?string $artistName, ?int $artistId, ?string $source, ?string $trackId, int $userId): bool
{
    // CHECK 制約は FK（SET NULL）の列に付けられないので、片方だけ入った行はここで弾く
    if (($source === null) !== ($trackId === null)) {
        throw new InvalidArgumentException('track_source と track_id は両方指定するか、両方 NULL にする');
    }
    try {
        $pdo->prepare('INSERT INTO song_catalog (song_key, title, artist_name, artist_id, track_source, track_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$key, $title, $artistName, $artistId, $source, $trackId, $userId]);
        return true;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return false;
        }
        throw $e;
    }
}

// =====================================================================
//  POST: 手動登録・削除
// =====================================================================
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $userId = (int)$user['user_id'];
    $key = null; // 登録できたら、その曲の TAB 一覧へ

    if ($action === 'add_linked') {
        $parsed = album_parse_key($_POST['track'] ?? null); // ブラウザから来たキーは形を確かめる
        $info = $parsed === null ? null : track_lookup($parsed[0], $parsed[1]); // 曲名などはサーバーが取り直す
        if ($info === null) {
            flash('曲を取得できませんでした', 'error');
        } else {
            $key = album_key($info['source'], $info['track_id']);
            $artist = songs_find_artist($pdo, (string)$info['artist_name']);
            $pdo->beginTransaction();
            save_track($pdo, $info); // song_catalog から外部キーで指すので、先に track に入れる
            $ok = songs_catalog_insert($pdo, $key, mb_substr((string)$info['title'], 0, 100), mb_substr((string)$info['artist_name'], 0, 255),
                $artist ? (int)$artist['artist_id'] : null, $info['source'], $info['track_id'], $userId);
            $pdo->commit();
            if (!$ok) {
                flash('登録済みです', 'error');
                $key = null;
            }
        }
    } elseif ($action === 'add_free') {
        $title = trim((string)($_POST['title'] ?? ''));
        $artist = songs_find_artist($pdo, (string)($_POST['artist'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $title)) {
            flash('曲名は1〜100文字で入力してください', 'error');
        } elseif ($artist === null) {
            flash('アーティストは一覧から選択してください', 'error');
        } else {
            $k = tab_song_key(null, null, (int)$artist['artist_id'], 0, $title, $artist['name']);
            if (str_ends_with($k, '|')) { // 曲名が記号だけなどで、キーの曲名の部分が空になった
                flash('曲名がおかしいです', 'error');
            } elseif (!songs_catalog_insert($pdo, $k, $title, $artist['name'], (int)$artist['artist_id'], null, null, $userId)) {
                flash('登録済みです', 'error');
            } else {
                $key = $k;
            }
        }
    } elseif ($action === 'delete') {
        // 手動登録の削除。TAB は曲キーで結び付いているので消えない
        $pdo->prepare('DELETE FROM song_catalog WHERE catalog_id = ?')->execute([(int)($_POST['catalog_id'] ?? 0)]);
        flash('削除しました');
    }
    redirect($key !== null ? 'tab?key=' . rawurlencode($key) : 'songs');
}

// =====================================================================
//  一覧のデータ
// =====================================================================
$groups = []; // song_key => 1曲ぶん

// ---- 1. セットリストの曲。キーの計算は band.php / tab.php（?song=）と同じ（アーティストは曲の指定 → バンドの順）----
$st = $pdo->query('SELECT s.song_id, s.title, s.band_id, s.track_source, s.track_id, COALESCE(s.artist_id, b.artist_id) AS artist_id,
        a.name AS artist_name, t.title AS track_title, t.artist_name AS track_artist, t.artwork_url, t.album_title, t.release_year,
        b.name AS band_name, l.live_id, l.fiscal_year, l.name AS live_name, d.live_day_id, d.label AS day_label, d.held_on
    FROM song s
    JOIN band b ON b.band_id = s.band_id
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    LEFT JOIN artist a ON a.artist_id = COALESCE(s.artist_id, b.artist_id)
    LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
    WHERE s.is_se = 0
    ORDER BY d.held_on IS NULL, d.held_on DESC, s.song_id DESC'); // 新しい順（最初に出てきた行 = 一番最近の演奏の表記を使う）
foreach ($st as $r) {
    $key = tab_song_key($r['track_source'], $r['track_id'], $r['artist_id'] === null ? null : (int)$r['artist_id'],
        (int)$r['band_id'], $r['title'], $r['artist_name']);
    if (!isset($groups[$key])) {
        $linked = $r['track_source'] !== null;
        $groups[$key] = [
            'key' => $key, 'title' => $linked ? $r['track_title'] : $r['title'], 'artist' => $linked ? $r['track_artist'] : $r['artist_name'],
            'art' => $r['artwork_url'], 'album' => $r['album_title'], 'year' => $r['release_year'], 'src' => $r['track_source'], 'tid' => $r['track_id'],
            'plays' => 0, 'last' => null, 'bands' => [], 'song_id' => (int)$r['song_id'],
            'catalog_id' => null, 'tabs' => 0, 'artist_id' => $r['artist_id'] === null ? null : (int)$r['artist_id'],
        ];
    }
    $g = &$groups[$key];
    $g['plays']++;
    if ($r['held_on'] !== null && ($g['last'] === null || $r['held_on'] > $g['last'])) {
        $g['last'] = $r['held_on'];
    }
    if (!isset($g['bands'][$r['band_id']])) {
        $g['bands'][$r['band_id']] = ['id' => (int)$r['band_id'], 'name' => $r['band_name'], 'live' => $r['live_name'], 'live_id' => (int)$r['live_id'], 'day_id' => (int)$r['live_day_id'], 'day' => $r['day_label'], 'fy' => $r['fiscal_year'], 'date' => $r['held_on'], 'n' => 0];
    }
    $g['bands'][$r['band_id']]['n']++; // 同じバンドが同じ曲を2回以上やったとき（セットリストに2回入っている）に「（×2）」を出す
    unset($g);
}

// ---- 2. 手動登録の曲 ----
$st = $pdo->query('SELECT c.catalog_id, c.artist_id, c.song_key, c.title, c.artist_name, t.title AS track_title, t.artist_name AS track_artist, t.artwork_url, t.album_title, t.release_year, c.track_source, c.track_id
    FROM song_catalog c LEFT JOIN track t ON t.source = c.track_source AND t.track_id = c.track_id');
foreach ($st as $r) {
    if (!isset($groups[$r['song_key']])) {
        $groups[$r['song_key']] = [
            'key' => $r['song_key'], 'title' => $r['track_title'] ?? $r['title'], 'artist' => $r['track_artist'] ?? $r['artist_name'],
            'art' => $r['artwork_url'], 'album' => $r['album_title'], 'year' => $r['release_year'], 'src' => $r['track_source'], 'tid' => $r['track_id'],
            'plays' => 0, 'last' => null, 'bands' => [], 'song_id' => null, 'catalog_id' => null, 'tabs' => 0,
            'artist_id' => $r['artist_id'] === null ? null : (int)$r['artist_id'],
        ];
    }
    $groups[$r['song_key']]['catalog_id'] = (int)$r['catalog_id']; // 手動登録の印（削除ボタン）
}

// ---- 3. TAB の数（曲ごと）。上の2つに無いキー（TAB だけある曲）も一覧に足す ----
$st = $pdo->query('SELECT t.song_key, COUNT(*) AS n, MAX(t.title) AS title, MAX(t.artist_name) AS artist_name, MAX(k.artwork_url) AS art, MAX(k.album_title) AS album, MAX(k.release_year) AS year, MAX(t.track_source) AS src, MAX(t.track_id) AS tid
    FROM song_tab t LEFT JOIN track k ON k.source = t.track_source AND k.track_id = t.track_id
    GROUP BY t.song_key');
foreach ($st as $r) {
    if (!isset($groups[$r['song_key']])) {
        $groups[$r['song_key']] = [
            'key' => $r['song_key'], 'title' => $r['title'], 'artist' => $r['artist_name'], 'art' => $r['art'], 'album' => $r['album'], 'year' => $r['year'], 'src' => $r['src'], 'tid' => $r['tid'],
            'plays' => 0, 'last' => null, 'bands' => [], 'song_id' => null, 'catalog_id' => null, 'tabs' => 0, 'artist_id' => null,
        ];
    }
    $groups[$r['song_key']]['tabs'] = (int)$r['n'];
}

// ---- 絞り込み・並べ替え（GET の値は決まった値だけ通す）----
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$tabFilter = in_array($_GET['tab'] ?? '', ['has', 'none'], true) ? $_GET['tab'] : '';
$sort = in_array($_GET['sort'] ?? '', ['title', 'artist', 'plays', 'last'], true) ? $_GET['sort'] : 'artist';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : (in_array($sort, ['title', 'artist'], true) ? 'asc' : 'desc'));
$page = max(1, (int)($_GET['p'] ?? 1));
const SONGS_PER_PAGE = 100;

$rows = array_values(array_filter($groups, static function (array $g) use ($q, $tabFilter): bool {
    if ($q !== '' && mb_stripos($g['title'] . ' ' . ($g['artist'] ?? ''), $q) === false) {
        return false;
    }
    return $tabFilter === '' || ($tabFilter === 'has') === ($g['tabs'] > 0);
}));
usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
    $sign = $dir === 'asc' ? 1 : -1;
    $byTitle = mb_strtolower($a['title']) <=> mb_strtolower($b['title']);
    if ($sort === 'title') {
        return $sign * $byTitle;
    }
    if ($sort === 'artist') {
        // アーティスト不明の曲は、昇順・降順どちらでも最後
        $aa = mb_strtolower((string)($a['artist'] ?? ''));
        $ba = mb_strtolower((string)($b['artist'] ?? ''));
        if (($aa === '') !== ($ba === '')) {
            return $aa === '' ? 1 : -1;
        }
        return ($sign * ($aa <=> $ba)) ?: $byTitle;
    }
    if ($sort === 'plays') {
        return ($sign * ($a['plays'] <=> $b['plays'])) ?: $byTitle;
    }
    // 最終演奏日: 日付が無い曲（手動登録など）は、昇順・降順どちらでも最後
    if (($a['last'] === null) !== ($b['last'] === null)) {
        return $a['last'] === null ? 1 : -1;
    }
    return ($sign * strcmp((string)$a['last'], (string)$b['last'])) ?: $byTitle;
});
$total = count($rows);
$pages = max(1, (int)ceil($total / SONGS_PER_PAGE));
$page = min($page, $pages);
$rows = array_slice($rows, ($page - 1) * SONGS_PER_PAGE, SONGS_PER_PAGE);

/** 今の絞り込み・並びを保ったまま、一部だけ変えた一覧の URL（初期値は URL に出さない） */
function songs_url(array $over): string
{
    global $q, $tabFilter, $sort, $dir, $page;
    $p = array_merge(['q' => $q, 'tab' => $tabFilter, 'sort' => $sort, 'dir' => $dir, 'p' => $page], $over);
    if (($over['sort'] ?? null) !== null && !isset($over['p'])) {
        $p['p'] = 1; // 並びや絞り込みを変えたら1ページ目から
    }
    $query = array_filter([
        'q' => $p['q'], 'tab' => $p['tab'],
        'sort' => $p['sort'] !== 'artist' ? $p['sort'] : '',
        'dir' => in_array($p['sort'], ['title', 'artist'], true) ? ($p['dir'] === 'desc' ? 'desc' : '') : ($p['dir'] === 'asc' ? 'asc' : ''), // 並びごとの初期の向きと同じなら出さない
        'p' => $p['p'] > 1 ? (string)$p['p'] : '',
    ], static fn($v) => $v !== '' && $v !== null);
    return 'songs' . ($query ? '?' . http_build_query($query) : '');
}

// 連携なし登録のアーティスト候補（datalist）
$artistNames = $pdo->query('SELECT name FROM artist ORDER BY name_kana IS NULL, name_kana, name')->fetchAll(PDO::FETCH_COLUMN);

render_header('楽曲データベース', 'tools');
?>
<link rel="stylesheet" href="<?= asset_url('assets/tab.css') ?>">
<link rel="stylesheet" href="<?= asset_url('assets/songs.css') ?>">
<nav class="crumbs"><a href="tools">ツール</a></nav>
<h1 class="display display--sm">楽曲データベース</h1>

<!-- 手動登録: セットリストに無い曲にも TAB を付けられる -->
<details class="card songs-add">
    <summary><?= icon('add') ?> 登録</summary>
    <div class="songs-add__forms">
        <form method="post" class="songs-add__form" data-song-link>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_linked">
            <input type="hidden" name="track" value="" data-song-track>
            <h2 class="songs-add__title"><?= icon('library_music') ?> 楽曲情報を検索</h2>
            <div class="songs-add__search">
                <input type="search" class="search" maxlength="100" placeholder="曲名 アーティスト名" data-song-q aria-label="曲を検索">
                <button type="button" class="icon-btn" data-song-search aria-label="検索" title="検索"><?= icon('search') ?></button>
            </div>
            <ul class="songs-add__results" data-song-results></ul>
            <p class="songs-add__chosen small" data-song-chosen hidden></p>
            <button type="submit" class="btn btn--primary" data-song-submit disabled><?= icon('add') ?> 登録</button>
        </form>
        <form method="post" class="songs-add__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_free">
            <h2 class="songs-add__title"><?= icon('music_note') ?> 連携なし</h2>
            <label class="field">曲名 <input type="text" name="title" maxlength="100" required></label>
            <label class="field">アーティスト
                <input type="text" name="artist" list="songs-artists" maxlength="100" required autocomplete="off">
            </label>
            <datalist id="songs-artists">
                <?php foreach ($artistNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?>
            </datalist>
            <button type="submit" class="btn btn--primary"><?= icon('add') ?> 登録</button>
        </form>
    </div>
</details>

<!-- 検索・絞り込み・並べ替え -->
<form method="get" class="songs-filter">
    <input type="hidden" name="sort" value="<?= h($sort) ?>">
    <input type="hidden" name="dir" value="<?= h($dir) ?>">
    <input type="search" class="search" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="曲名・アーティスト" aria-label="曲名・アーティスト">
    <select name="tab" aria-label="TAB" onchange="this.form.submit()">
        <option value="">TAB すべて</option>
        <option value="has"<?= $tabFilter === 'has' ? ' selected' : '' ?>>TAB あり</option>
        <option value="none"<?= $tabFilter === 'none' ? ' selected' : '' ?>>TAB なし</option>
    </select>
    <button type="submit" class="icon-btn" aria-label="検索" title="検索"><?= icon('search') ?></button>
</form>
<div class="songs-sort">
    <span class="muted small"><?= number_format($total) ?>曲</span>
    <?php foreach (['title' => '曲名', 'artist' => 'アーティスト', 'plays' => '演奏回数', 'last' => '最終演奏日'] as $col => $label):
        $on = $sort === $col;
        $next = $on ? ($dir === 'asc' ? 'desc' : 'asc') : (in_array($col, ['title', 'artist'], true) ? 'asc' : 'desc'); ?>
        <a class="tab-chip<?= $on ? ' is-active' : '' ?>" href="<?= h(songs_url(['sort' => $col, 'dir' => $next])) ?>"<?= $on ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><?= h($label) ?><?= $on ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
    <div class="empty card"><p class="empty__title">曲がありません</p></div>
<?php else: ?>
<ul class="songs-list">
    <?php foreach ($rows as $g):
        // セットリストの曲なら代表の曲ID（tab?song=）、それ以外は曲キー（tab?key=）で TAB の画面へ
        $tabUrl = $g['song_id'] !== null ? 'tab?song=' . $g['song_id'] : 'tab?key=' . rawurlencode($g['key']); ?>
        <li class="songs-item">
            <div class="songs-row">
                <?php if ($g['art']): ?>
                    <img class="songs-row__art" src="<?= h($g['art']) ?>" alt="" width="44" height="44" loading="lazy">
                <?php else: ?>
                    <span class="songs-row__art songs-row__art--none"><?= icon('music_note') ?></span>
                <?php endif; ?>
                <div class="songs-row__main">
                    <button type="button" class="songs-row__open" data-song-open aria-haspopup="dialog">
                        <span class="songs-row__title"><?= h($g['title']) ?></span><?php if ($g['artist']): ?><span class="songs-row__artist muted small"><?= h($g['artist']) ?></span><?php endif; ?><?php if ($g['catalog_id'] !== null): ?><span class="tag">手動</span><?php endif; ?>
                    </button>
                </div>
                <a class="btn btn--sm songs-row__tab<?= $g['tabs'] > 0 ? ' is-on' : '' ?>" href="<?= h($tabUrl) ?>" aria-label="TAB 譜" title="TAB 譜"><?= icon('library_music', $g['tabs'] > 0 ? 'icon--fill' : '') ?> <?= (int)$g['tabs'] ?></a>
                <?php if ($g['catalog_id'] !== null): ?>
                    <form method="post" class="inline-form" data-confirm="手動登録を削除します（TAB 譜は残ります）。よろしいですか？">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="catalog_id" value="<?= (int)$g['catalog_id'] ?>">
                        <button type="submit" class="icon-btn icon-btn--sm" aria-label="削除" title="削除"><?= icon('delete') ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <dialog class="songs-modal" data-song-modal aria-label="<?= h($g['title']) ?>">
                <div class="songs-modal__head">
                    <button type="button" class="icon-btn icon-btn--sm songs-modal__close" data-song-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
                    <?php // ジャケット → その曲（サブスク）。アルバム名 → アルバムの検索（曲にはアルバムの ID が無いので検索ページ）
                    $trackUrl = $g['src'] !== null ? track_listen_url($musicApp, ['source' => $g['src'], 'track_id' => $g['tid'], 'title' => $g['title'], 'artist_name' => (string)$g['artist']]) : null;
                    $albumUrl = $g['album'] ? album_search_url(listen_app($musicApp, (string)$g['src']), ['title' => $g['album'], 'artist_name' => (string)$g['artist']]) : null; ?>
                    <div class="songs-modal__cover">
                        <?php $art = $g['art']
                            ? '<img class="songs-modal__art" src="' . h($g['art']) . '" alt="" width="200" height="200" loading="lazy">'
                            : '<span class="songs-modal__art songs-row__art--none">' . icon('music_note') . '</span>'; ?>
                        <?php if ($trackUrl !== null): ?><a href="<?= h($trackUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="サブスクで開く" title="サブスクで開く"><?= $art ?></a><?php else: ?><?= $art ?><?php endif; ?>
                        <?php if ($g['album']): ?>
                            <a class="songs-modal__album small" href="<?= h($albumUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($g['album']) ?><?= $g['year'] ? ' · ' . (int)$g['year'] : '' ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="songs-modal__names">
                        <strong class="songs-row__title"><?= h($g['title']) ?></strong>
                        <?php if ($g['artist']): ?>
                            <?php if ($g['artist_id'] !== null): ?><a class="small" href="artist?id=<?= (int)$g['artist_id'] ?>"><?= h($g['artist']) ?></a>
                            <?php else: ?><span class="muted small"><?= h($g['artist']) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="songs-row__meta muted small">
                    <span>演奏回数 <?= (int)$g['plays'] ?></span>
                    <span title="最終演奏日"><?= icon('event') ?> <?= $g['last'] !== null ? h(fmt_date($g['last'])) : '-' ?></span>
                    <span title="TAB 譜"><?= icon('library_music') ?> <?= (int)$g['tabs'] ?></span>
                </div>
                <?php if ($g['bands']): ?>
                    <ul class="songs-bands">
                        <?php foreach ($g['bands'] as $b): ?>
                            <li><a href="band?id=<?= (int)$b['id'] ?>"><?= h($b['name']) ?></a>
                                <span class="muted small"><a href="live?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['day_id'] ?>"><?= h(fmt_year($b['fy'])) ?> <?= h($b['live']) ?></a><?= $b['n'] >= 2 ? '（×' . (int)$b['n'] . '）' : '' ?><?= (string)$b['day'] !== '' ? ' · ' . h($b['day']) : '' ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted small">演奏履歴なし</p>
                <?php endif; ?>
            </dialog>
        </li>
    <?php endforeach; ?>
</ul>
<?php if ($pages > 1): ?>
    <nav class="history-pager" aria-label="ページ">
        <?= $page > 1 ? '<a class="btn btn--sm" href="' . h(songs_url(['p' => $page - 1])) . '" rel="prev" aria-label="前">' . icon('chevron_left') . '</a>' : '<span></span>' ?>
        <span class="muted small"><?= $page ?> / <?= $pages ?></span>
        <?= $page < $pages ? '<a class="btn btn--sm" href="' . h(songs_url(['p' => $page + 1])) . '" rel="next" aria-label="次">' . icon('chevron_right') . '</a>' : '<span></span>' ?>
    </nav>
<?php endif; ?>
<?php endif; ?>

<script src="<?= asset_url('assets/songs.js') ?>" defer></script>
<?php render_footer();
