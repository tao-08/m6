<?php
/**
 * =====================================================================
 *  search.php?q=キーワード — ライブ・バンド・メンバーの横断検索
 *  search.php?faculty=文学部 / ?role=係ID — その学部・係のメンバー一覧（メンバーページから飛んでくる）
 * =====================================================================
 *  LIKE '%キーワード%' で部分一致検索している。バンドのメモ（band.note）・日程のメモ（live_day.note）も対象。
 *
 *  ⚠ LIKE の落とし穴: ユーザーが「%」や「_」を入力すると、それ自体が
 *    「何でも一致」の記号として働いてしまう。escape_like() で \% \_ に変えてから使う。
 *    （SQL インジェクションはプリペアドステートメントで防げているが、これは別の問題）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/albums.php';
require_once __DIR__ . '/lib/tabs.php';
$user = require_login();

/** LIKE で特別な意味を持つ文字（\ % _）を、ただの文字として扱わせる */
function escape_like(string $s): string
{
    return addcslashes($s, '\\%_');
}

const BANDS_PER_PAGE = 20;

$q = trim((string)($_GET['q'] ?? ''));
// 何ページ目まで出すか。変な値（0、マイナス、文字）は 1 に、大きすぎる値は 50 に丸める
$bandPage = min(50, max(1, (int)($_GET['bp'] ?? 1)));
$lives = $bands = $members = $artists = $matchedFaculties = $matchedRoles = [];
$albums = ['albums' => [], 'people' => 0]; // マイアルバム（アーティスト名で当たったもの）
$bandTotal = 0;
$songs = $songAlbums = []; // 曲名・アルバム（管理人のみ。楽曲データベースへのリンク）

// 学部・係で絞る（完全一致）。学部は決まった一覧にあるものだけ、係は DB にある ID だけ受け付ける
$faculty = (string)($_GET['faculty'] ?? '');
if (!in_array($faculty, FACULTIES, true)) {
    $faculty = '';
}
$roleId = (int)($_GET['role'] ?? 0);
$roleName = null;
$filterLabel = null; // 「学部: 文学部」など。null なら普通のキーワード検索
if ($roleId > 0) {
    $st = db()->prepare('SELECT name FROM role WHERE role_id = ?');
    $st->execute([$roleId]);
    $roleName = $st->fetchColumn() ?: null;
}

if ($faculty !== '' || $roleName !== null) {
    $where = $params = [];
    if ($faculty !== '') {
        $where[] = 'm.faculty = ?';
        $params[] = $faculty;
    }
    if ($roleName !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM member_role mr WHERE mr.member_id = m.member_id AND mr.role_id = ?)';
        $params[] = $roleId;
    }
    $st = db()->prepare('SELECT m.member_id, m.name, m.name_kana, m.entry_year,
            (SELECT COUNT(DISTINCT x.band_id) FROM band_member x WHERE x.member_id = m.member_id) AS bands
        FROM member m
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY m.entry_year DESC, m.name_kana, m.name');
    $st->execute($params);
    $members = $st->fetchAll();
    $filterLabel = implode(' · ', array_filter([
        $faculty !== '' ? '学部: ' . $faculty : null,
        $roleName !== null ? '係: ' . $roleName : null,
    ]));
} elseif ($q !== '') {
    $pdo = db();
    $like = '%' . escape_like(mb_substr($q, 0, 50)) . '%';
    // 「トリ」「🐔」「🐦」で検索したら、各日程のトリ（play_order が一番大きいバンド）も出す。🐦️ の後ろの U+FE0F は外して比べる
    $tori = in_array(str_replace("\u{FE0F}", '', $q), ['トリ', '🐔', '🐦'], true) ? 1 : 0;
    $toriSql = ' OR (? = 1 AND b.play_order = (SELECT MAX(x.play_order) FROM band x WHERE x.live_day_id = b.live_day_id))';

    // ---- ライブ（ライブ名・会場名・日程名・日程のメモ） ----
    $st = $pdo->prepare('SELECT DISTINCT lm.live_id, lm.fiscal_year AS year, lm.name
        FROM live lm
        JOIN live_day ld ON ld.live_id = lm.live_id
        LEFT JOIN venue v ON v.venue_id = ld.venue_id
        WHERE lm.name COLLATE utf8mb4_unicode_520_ci LIKE ? OR v.name COLLATE utf8mb4_unicode_520_ci LIKE ? OR ld.label COLLATE utf8mb4_unicode_520_ci LIKE ? OR ld.note COLLATE utf8mb4_unicode_520_ci LIKE ?
        ORDER BY lm.fiscal_year DESC LIMIT 30');
    $st->execute([$like, $like, $like, $like]);
    $lives = $st->fetchAll();

    // 見つかったライブの日程（日程名・日付・会場）。会場で当たった日以外も、そのライブの日程は全部出す
    //   MariaDB は IN (サブクエリ) の中で LIMIT が使えないので、ID を取ってから2本目のクエリで引く
    $daysByLive = []; // [live_id] = [['live_day_id', 'label', 'held_on', 'venue'], ...]
    if ($lives) {
        $ids = array_map('intval', array_column($lives, 'live_id'));
        $st = $pdo->prepare('SELECT ld.live_id, ld.live_day_id, ld.label, ld.held_on, ld.note, v.name AS venue, ld.venue_id
            FROM live_day ld
            LEFT JOIN venue v ON v.venue_id = ld.venue_id
            WHERE ld.live_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY ld.held_on, ld.label');
        $st->execute($ids);
        foreach ($st as $d) {
            $daysByLive[(int)$d['live_id']][] = $d;
        }
    }

    // ---- バンド（同じアーティストを何回コピーしたかも分かる） ----
    //   メンバーとセトリまで出すと1組が縦に長いので、最初は BANDS_PER_PAGE 組だけ。
    //   「もっと見る」を押すと ?bp=2, 3… になり、その分だけ多く出す（URL に残るので戻る・共有しても同じ表示）
    //   バンド名だけでなくメモ（鍵盤の私物/貸出 など）でも当てる。COUNT と本体の WHERE は必ず同じにする（「もっと見る」の残り数がずれるので）
    $st = $pdo->prepare('SELECT COUNT(*) FROM band b WHERE b.name COLLATE utf8mb4_unicode_520_ci LIKE ? OR b.note COLLATE utf8mb4_unicode_520_ci LIKE ?' . $toriSql);
    $st->execute([$like, $like, $tori]);
    $bandTotal = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT b.band_id, b.name, b.song_count, b.note, ld.live_day_id, ld.label, ld.held_on AS date,
            lm.live_id, lm.fiscal_year AS year, lm.name AS live_name
        FROM band b
        JOIN live_day ld ON ld.live_day_id = b.live_day_id
        JOIN live lm ON lm.live_id = ld.live_id
        WHERE b.name COLLATE utf8mb4_unicode_520_ci LIKE ? OR b.note COLLATE utf8mb4_unicode_520_ci LIKE ?' . $toriSql . '
        ORDER BY ld.held_on DESC, b.play_order LIMIT ?');
    $st->bindValue(1, $like);
    $st->bindValue(2, $like);
    $st->bindValue(3, $tori, PDO::PARAM_INT);
    $st->bindValue(4, BANDS_PER_PAGE * $bandPage, PDO::PARAM_INT); // LIMIT は数値として渡す（文字列 '20' だとエラーになることがある）
    $st->execute();
    $bands = $st->fetchAll();

    // 見つかったバンドのメンバーとセットリスト（アーティストページと同じ見せ方）
    $lineups = $setlists = [];
    if ($bands) {
        $ids = array_map('intval', array_column($bands, 'band_id'));
        $in = implode(',', array_fill(0, count($ids), '?')); // ? を ID の数だけ並べる（値は execute で渡す）
        $st = $pdo->prepare("SELECT bm.band_id, m.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
            FROM band_member bm
            JOIN member m ON m.member_id = bm.member_id
            JOIN instrument i ON i.instrument_id = bm.instrument_id
            WHERE bm.band_id IN ($in)
            ORDER BY i.sort_order, m.name");
        $st->execute($ids);
        $lineups = member_lineups_by_band($st);

        // 出演まで非公開のバンドの曲は出さない（lib/repository.php の setlist_hidden_sql）
        $st = $pdo->prepare("SELECT s.band_id, s.title FROM song s JOIN band b ON b.band_id = s.band_id
            WHERE s.band_id IN ($in) AND NOT " . setlist_hidden_sql('b') . " ORDER BY s.track_no");
        $st->execute($ids);
        foreach ($st as $s) {
            $setlists[(int)$s['band_id']][] = $s['title'];
        }
    }

    // ---- アーティスト ----
    // 回数はアーティストページと同じ数え方（オムニバスは曲に付いたアーティストを1バンド1回。artist_plays_sql()）
    // 演奏0回のアーティストは出さない（artists.php と同じ）。管理者には出す（重複して登録されたアーティストを見つけて統合できるように）
    // 別称（artist_alias。エルレ → ELLEGARDEN など）でも当てる。別称だけで当たったときは、どの別称で当たったかも出す（alias_hit）
    $st = $pdo->prepare('SELECT a.artist_id, a.name, COUNT(p.band_id) AS n,
            (SELECT al.name FROM artist_alias al WHERE al.artist_id = a.artist_id AND al.name COLLATE utf8mb4_unicode_520_ci LIKE ? LIMIT 1) AS alias_hit
        FROM artist a
        ' . (is_admin() ? 'LEFT ' : '') . 'JOIN (' . artist_plays_sql() . ') p ON p.artist_id = a.artist_id
        WHERE a.name COLLATE utf8mb4_unicode_520_ci LIKE ? OR a.name_kana COLLATE utf8mb4_unicode_520_ci LIKE ? OR EXISTS (SELECT 1 FROM artist_alias al WHERE al.artist_id = a.artist_id AND al.name COLLATE utf8mb4_unicode_520_ci LIKE ?)
        GROUP BY a.artist_id ORDER BY n DESC LIMIT 30');
    $st->execute([$like, $like, $like, $like]);
    $artists = $st->fetchAll();

    // ---- マイアルバム（アーティスト名。マイアルバムのアーティスト名を押すとここに来る） ----
    //   当たったアーティストの本名・別称の表記のアルバムも足す（「エルレ」で ELLEGARDEN のアルバムも。fan_albums）
    $albums = merge_fan_albums(albums_by_artist_name($pdo, $like), fan_albums($pdo, array_column($artists, 'artist_id')));

    // ---- 曲名・アルバム（楽曲データベースは管理人だけなので、管理人にだけ出す） ----
    //   曲名 → songs?open=曲キー（その曲のモーダルが開く）/ アルバム → songs?q=アルバム名（楽曲データベースの検索）
    if (is_admin()) {
        $st = $pdo->prepare('SELECT s.title, s.band_id, s.track_source, s.track_id, COALESCE(s.artist_id, b.artist_id) AS artist_id,
                a.name AS artist_name, t.title AS track_title, t.artist_name AS track_artist
            FROM song s
            JOIN band b ON b.band_id = s.band_id
            LEFT JOIN artist a ON a.artist_id = COALESCE(s.artist_id, b.artist_id)
            LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
            WHERE s.is_se = 0 AND NOT ' . setlist_hidden_sql('b') . '
              AND (s.title COLLATE utf8mb4_unicode_520_ci LIKE ? OR t.title COLLATE utf8mb4_unicode_520_ci LIKE ?)
            ORDER BY s.song_id DESC LIMIT 300');
        $st->execute([$like, $like]);
        foreach ($st as $r) {
            $key = tab_song_key($r['track_source'], $r['track_id'], $r['artist_id'] === null ? null : (int)$r['artist_id'],
                (int)$r['band_id'], $r['title'], $r['artist_name']);
            $linked = $r['track_source'] !== null;
            $songs[$key] ??= ['title' => $linked ? $r['track_title'] : $r['title'], 'artist' => $linked ? $r['track_artist'] : $r['artist_name']];
            if (count($songs) >= 30) {
                break;
            }
        }
        $st = $pdo->prepare('SELECT album_title, artist_name, COUNT(*) AS n FROM track
            WHERE album_title COLLATE utf8mb4_unicode_520_ci LIKE ?
            GROUP BY album_title, artist_name ORDER BY n DESC LIMIT 30');
        $st->execute([$like]);
        $songAlbums = $st->fetchAll();
    }

    // ---- 学部・係（名前で当たったら、その学部・係の一覧ページへのリンクを出す） ----
    $matchedFaculties = array_values(array_filter(FACULTIES, fn($f) => mb_strpos($f, mb_substr($q, 0, 50)) !== false));
    $st = $pdo->prepare('SELECT role_id, name FROM role WHERE name COLLATE utf8mb4_unicode_520_ci LIKE ? ORDER BY sort_order, name');
    $st->execute([$like]);
    $matchedRoles = $st->fetchAll();

    // ---- メンバー（名前・ふりがな・学部・係） ----
    $st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana,
            (SELECT COUNT(DISTINCT x.band_id) FROM band_member x WHERE x.member_id = m.member_id) AS bands
        FROM member m
        WHERE m.name COLLATE utf8mb4_unicode_520_ci LIKE ? OR m.name_kana COLLATE utf8mb4_unicode_520_ci LIKE ? OR m.faculty COLLATE utf8mb4_unicode_520_ci LIKE ?
           OR EXISTS (SELECT 1 FROM member_role mr JOIN role r ON r.role_id = mr.role_id
                      WHERE mr.member_id = m.member_id AND r.name COLLATE utf8mb4_unicode_520_ci LIKE ?)
        ORDER BY bands DESC, m.name LIMIT 60');
    $st->execute([$like, $like, $like, $like]);
    $members = $st->fetchAll();
}

render_header($filterLabel ?? ($q !== '' ? "「{$q}」の検索結果" : '検索'), 'search');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Search</p>
        <h1 class="display">検索</h1>
    </div>
</section>

<!-- GET で送るので、検索結果の URL をそのまま共有・ブックマークできる -->
<form method="get" class="toolbar">
    <input type="search" name="q" value="<?= h($q) ?>" class="search" placeholder="バンド名・メンバー名・アーティスト・ライブ名・会場 etc..." autofocus aria-label="検索ワード">
    <button class="btn btn--primary" type="submit">検索</button>
</form>

<?php if ($filterLabel !== null): ?>
    <section>
        <h2 class="section-title"><?= h($filterLabel) ?> <span class="muted"><?= count($members) ?>人</span></h2>
        <?php if ($members): ?>
            <div class="chip-list">
                <?php foreach ($members as $m): ?>
                    <a class="chip chip--lg<?= (int)$m['member_id'] === $user['member_id'] ? ' chip--me' : '' ?>" href="member?id=<?= (int)$m['member_id'] ?>">
                        <?= h($m['name']) ?><?php if ((int)$m['entry_year'] > 0): ?> <span class="muted small"><?= (int)$m['entry_year'] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">該当するメンバーはいません</p>
        <?php endif; ?>
    </section>
<?php elseif ($q === ''): ?>
    <p class="muted">例: 「ヨルシカ」で過去にヨルシカをコピーしたバンドが全部出ます。</p>
<?php elseif (!$lives && !$bands && !$members && !$artists && !$matchedFaculties && !$matchedRoles && !$albums['albums'] && !$songs && !$songAlbums): ?>
    <div class="empty card"><p class="empty__title">見つかりませんでした</p><p class="muted">表記を変えて試してみてください（全角/半角、スペースの有無など）</p></div>
<?php else: ?>
    <div class="search-results">
        <?php if ($matchedFaculties || $matchedRoles): ?>
        <section>
            <h2 class="section-title">学部・係</h2>
            <div class="chip-list">
                <?php foreach ($matchedFaculties as $f): ?>
                    <a class="chip chip--lg" href="search?<?= h(http_build_query(['faculty' => $f])) ?>">学部: <?= h($f) ?></a>
                <?php endforeach; ?>
                <?php foreach ($matchedRoles as $r): ?>
                    <a class="chip chip--lg" href="search?role=<?= (int)$r['role_id'] ?>">係: <?= h($r['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($members): ?>
        <section>
            <h2 class="section-title">メンバー <span class="muted"><?= count($members) ?></span></h2>
            <div class="chip-list">
                <?php foreach ($members as $m): ?>
                    <a class="chip chip--lg<?= (int)$m['member_id'] === $user['member_id'] ? ' chip--me' : '' ?>" href="member?id=<?= (int)$m['member_id'] ?>">
                        <?= h($m['name']) ?> <span class="muted small"><?= (int)$m['bands'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($artists): ?>
        <section>
            <h2 class="section-title">アーティスト <span class="muted"><?= count($artists) ?></span></h2>
            <div class="chip-list">
                <?php foreach ($artists as $a): ?>
                    <a class="chip chip--lg" href="artist?id=<?= (int)$a['artist_id'] ?>"><?= h($a['name']) ?>
                        <?php if ($a['alias_hit'] !== null && mb_stripos($a['name'], mb_substr($q, 0, 50)) === false): // 別称だけで当たったとき ?><span class="muted small">（<?= h($a['alias_hit']) ?>）</span><?php endif; ?>
                        <span class="muted small"><?= (int)$a['n'] ?>回</span></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?= fan_albums_html($albums, 'マイアルバム', member_music_app(db(), $user['member_id']), true) // アーティスト名で当たったアルバムと、入れている人 ?>

        <?php if ($songs): ?>
        <section>
            <h2 class="section-title">曲名 <span class="muted"><?= count($songs) ?></span></h2>
            <div class="chip-list">
                <?php foreach ($songs as $key => $s): ?>
                    <a class="chip chip--lg" href="songs?<?= h(http_build_query(['open' => $key])) ?>"><?= h((string)$s['title']) ?>
                        <?php if ($s['artist']): ?><span class="muted small"><?= h((string)$s['artist']) ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($songAlbums): ?>
        <section>
            <h2 class="section-title">アルバム <span class="muted"><?= count($songAlbums) ?></span></h2>
            <div class="chip-list">
                <?php foreach ($songAlbums as $al): ?>
                    <a class="chip chip--lg" href="songs?<?= h(http_build_query(['q' => $al['album_title']])) ?>"><?= h($al['album_title']) ?>
                        <?php if ($al['artist_name']): ?><span class="muted small"><?= h($al['artist_name']) ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($bands): ?>
        <section>
            <h2 class="section-title">バンド<span class="muted"><?= $bandTotal ?></span></h2>
            <div class="card table-card">
                <div class="table-scroll table-scroll--flush">
                <table class="table">
                    <thead><tr><th>バンド名</th><th>ライブ</th><th>メンバー</th><th>セットリスト</th></tr></thead>
                    <tbody>
                    <?php foreach ($bands as $i => $b): $bandId = (int)$b['band_id']; ?>
                        <tr id="band-n<?= $i + 1 ?>">
                            <td class="strong"><a href="band?id=<?= $bandId ?>"><?= h($b['name']) ?></a>
                                <?php if ($b['note']): // メモで当たったときに理由が分かるように（live.php と同じ見た目） ?><div><span class="band-note"><?= icon('sticky_note_2') ?> <?= h($b['note']) ?></span></div><?php endif; ?></td>
                            <td class="nowrap"><a href="live?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><?= h(fmt_year($b['year'])) ?> <?= h($b['live_name']) ?></a>
                                <div class="muted small"><?= h($b['label']) ?> <?= h(fmt_date($b['date'])) ?></div></td>
                            <td>
                                <?php if (!empty($lineups[$bandId])): ?>
                                    <ul class="artist-lineup">
                                        <?php foreach ($lineups[$bandId] as $m): ?>
                                            <li><?= part_badge($m) ?>
                                                <a href="member?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?><span class="muted">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($setlists[$bandId])): ?>
                                    <ol class="artist-setlist">
                                        <?php foreach ($setlists[$bandId] as $title): ?>
                                            <li><?= h($title) ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?><span class="muted">—</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php if (count($bands) < $bandTotal && $bandPage < 50): ?>
                <!-- 次のページは「今の続きの行」に飛ぶ（ページの一番上に戻されない） -->
                <p class="more-link"><a class="btn btn--sm" href="search?<?= h(http_build_query(['q' => $q, 'bp' => $bandPage + 1])) ?>#band-n<?= count($bands) + 1 ?>">
                    もっと見る（残り <?= $bandTotal - count($bands) ?> 組）</a></p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($lives): ?>
        <section>
            <h2 class="section-title">ライブ <span class="muted"><?= count($lives) ?></span></h2>
            <div class="card table-card">
                <div class="table-scroll table-scroll--flush">
                <table class="table">
                    <thead><tr><th>ライブ</th><th>日程</th><th>日付</th><th>会場</th></tr></thead>
                    <tbody>
                    <?php foreach ($lives as $l):
                        $liveId = (int)$l['live_id'];
                        $days = $daysByLive[$liveId] ?? [];
                        foreach ($days as $i => $d): ?>
                        <tr>
                            <?php if ($i === 0): // ライブ名は日程の数だけ縦に結合して1回だけ出す ?>
                                <td class="strong nowrap" rowspan="<?= count($days) ?>"><a href="live?id=<?= $liveId ?>"><?= h(fmt_year($l['year'])) ?> <?= h($l['name']) ?></a></td>
                            <?php endif; ?>
                            <td class="nowrap"><a href="live?id=<?= $liveId ?>#day-<?= (int)$d['live_day_id'] ?>"><?= h($d['label']) ?></a>
                                <?php if ($d['note']): ?><div class="muted small"><?= h($d['note']) ?></div><?php endif; ?></td>
                            <td class="muted nowrap"><?= $d['held_on'] !== null ? h(fmt_date($d['held_on'])) : '—' ?></td>
                            <td><?= $d['venue'] !== null ? '<a href="venue?id=' . (int)$d['venue_id'] . '">' . h($d['venue']) . '</a>' : '<span class="muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php render_footer();
