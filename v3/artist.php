<?php
/**
 * =====================================================================
 *  artist.php?id=アーティストID — そのアーティストをコピーしたバンドの歴代一覧
 * =====================================================================
 *  artist テーブルを分けたから作れるページ（v3 で追加）。
 *  「ヨルシカ（安田）」「ヨルシカ（八木）」も artist_id が同じなので、WHERE 1つで全部出る。
 *  バンド名の文字列で LIKE 検索するより正確（「ヨルシカ」を含む別名のバンドを拾わない）。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/albums.php';
$user = require_login();

$pdo = db();
$artistId = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM artist WHERE artist_id = ?');
$st->execute([$artistId]);
$artist = $st->fetch();
if (!$artist) {
    http_response_code(404);
    exit('アーティストが見つかりません');
}

// このアーティストのバンド（数え方は lib/bootstrap.php の ARTIST_PLAYS_SQL。一覧・集計・検索と同じ）
//   ふつうのバンド    : band.artist_id がこのアーティスト
//   オムニバスのバンド: band.artist_id は見ない。セットリストにこのアーティストの曲（song.artist_id）が1曲でもあれば入る
//     2曲やっていてもバンドは1回だけ（= 1回コピーした、と数える）
$bandWhere = 'b.band_id IN (SELECT p.band_id FROM (' . ARTIST_PLAYS_SQL . ') p WHERE p.artist_id = ?)';
$st = $pdo->prepare('SELECT b.band_id, b.name, d.live_day_id, d.label, d.held_on, l.live_id, l.fiscal_year, l.name AS live_name
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    WHERE ' . $bandWhere . '
    ORDER BY d.held_on DESC, l.fiscal_year DESC');
$st->execute([$artistId]);
$bands = $st->fetchAll();

// メンバー: バンドごとに「楽器ラベル + 名前」を1人1行（楽器の並び順 → 名前順。member_lineups_by_band）
$st = $pdo->prepare('SELECT bm.band_id, m.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band b
    JOIN band_member bm ON bm.band_id = b.band_id
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE ' . $bandWhere . '
    ORDER BY i.sort_order, m.name');
$st->execute([$artistId]);
$lineups = member_lineups_by_band($st);

// セットリスト: バンドごとに曲順で。オムニバスのバンドはこのアーティストの曲だけ
$setlists = []; // [band_id] = [曲名, ...]
$st = $pdo->prepare('SELECT s.band_id, s.title
    FROM band b
    JOIN song s ON s.band_id = b.band_id
    WHERE ' . $bandWhere . ' AND (b.is_omnibus = 0 OR s.artist_id = ?)
    ORDER BY s.track_no');
$st->execute([$artistId, $artistId]);
foreach ($st as $s) {
    $setlists[(int)$s['band_id']][] = $s['title'];
}

// 管理者はアーティスト名を直せる（表記ゆれで2つできたときは名前をそろえれば…ではなく、下の統合を使う）
$others = [];
if (is_admin()) {
    if (is_post()) {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'rename') {
            $name = trim((string)($_POST['name'] ?? ''));
            $st = $pdo->prepare('SELECT 1 FROM artist WHERE name = ? AND artist_id <> ?');
            $st->execute([$name, $artistId]);
            if ($name === '' || mb_strlen($name) > 100 || $st->fetchColumn()) {
                flash('名前が空か、同じ名前のアーティストが既にあります（その場合は統合してください）', 'error');
            } else {
                $pdo->prepare('UPDATE artist SET name = ? WHERE artist_id = ?')->execute([$name, $artistId]);
                flash('アーティスト名を変更しました');
            }
        } elseif ($action === 'merge') {
            // このアーティストのバンドを、別のアーティストに付け替えてから消す
            $to = (int)($_POST['to'] ?? 0);
            if ($to !== $artistId) {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE band SET artist_id = ? WHERE artist_id = ?')->execute([$to, $artistId]);
                // 別名も統合先へ（先に付け替えないと、下の DELETE の CASCADE で消えてしまう）
                $pdo->prepare('UPDATE artist_alias SET artist_id = ? WHERE artist_id = ?')->execute([$to, $artistId]);
                // 消える側の名前も統合先の別名にしておく（その表記のマイアルバムを拾い続けるため）。
                //   INSERT IGNORE: 既に同じ別名があれば何もしない
                $pdo->prepare('INSERT IGNORE INTO artist_alias (name, artist_id) VALUES (?, ?)')->execute([$artist['name'], $to]);
                $pdo->prepare('DELETE FROM artist WHERE artist_id = ?')->execute([$artistId]);
                $pdo->commit();
                flash('アーティストを統合しました');
                redirect('artist.php?id=' . $to);
            }
        } elseif ($action === 'alias_add') {
            // 別名: マイアルバムのアーティスト名が別の表記（オアシス など）でも、このアーティストとして拾うため
            $alias = trim((string)($_POST['alias'] ?? ''));
            // 本名・ほかのアーティストの名前と同じ別名は付けない（どのアーティストか分からなくなる）
            $st = $pdo->prepare('SELECT 1 FROM artist WHERE name = ?');
            $st->execute([$alias]);
            $takenByArtist = (bool)$st->fetchColumn();
            $st = $pdo->prepare('SELECT 1 FROM artist_alias WHERE name = ?');
            $st->execute([$alias]);
            $takenByAlias = (bool)$st->fetchColumn();
            if ($alias === '' || mb_strlen($alias) > 255 || album_match_key($alias) === '') {
                flash('別名を入力してください', 'error');
            } elseif ($takenByArtist || $takenByAlias) {
                flash('「' . $alias . '」は既にアーティスト名か別名として使われています', 'error');
            } else {
                $pdo->prepare('INSERT INTO artist_alias (name, artist_id) VALUES (?, ?)')->execute([$alias, $artistId]);
                flash('別名「' . $alias . '」を追加しました');
            }
        } elseif ($action === 'alias_delete') {
            // artist_id も条件に入れる: 別のアーティストの別名を、このページから消せないように
            $pdo->prepare('DELETE FROM artist_alias WHERE name = ? AND artist_id = ?')
                ->execute([(string)($_POST['alias'] ?? ''), $artistId]);
            flash('別名を削除しました');
        }
        redirect('artist.php?id=' . $artistId);
    }
    $others = $pdo->query('SELECT artist_id, name FROM artist ORDER BY name')->fetchAll();
    // 別名の入力候補: マイアルバムに出てくるアーティスト名（表記をそのまま選べるように）
    $albumArtistNames = $pdo->query('SELECT DISTINCT artist_name FROM member_favorite_album ORDER BY artist_name')
        ->fetchAll(PDO::FETCH_COLUMN);
}

// ---- 別名（オアシス など） ----
$st = $pdo->prepare('SELECT name FROM artist_alias WHERE artist_id = ? ORDER BY name');
$st->execute([$artistId]);
$aliases = $st->fetchAll(PDO::FETCH_COLUMN);

// ---- このアーティストのアルバムをマイアルバムに入れているメンバー ----
//   マイアルバムには artist_id が無く、Spotify / iTunes のアーティスト名（文字列）しか無い。
//   album_match_key で表記ゆれ（大文字小文字・全角半角・記号）をそろえて、本名か別名と一致する行を拾う。
//   SQL の = では表記ゆれと「A, B」（Spotify の複数アーティスト）を拾えないので、PHP で絞る
//   （行数は 人数 × 最大30枚 なので全部読んでも軽い）
$artistKeys = [];
foreach (array_merge([$artist['name']], $aliases) as $n) {
    $artistKeys[album_match_key($n)] = true;
}
$fanAlbums = []; // [アルバムのキー] = ['album' => 行, 'members' => [[member_id, name], ...]]
$fanIds = [];    // 入れている人の member_id（人数を数える用。1人が何枚入れていても1人）
$rows = $pdo->query('SELECT f.source, f.album_id, f.title, f.artist_name, f.artwork_url, f.release_year,
        m.member_id, m.name
    FROM member_favorite_album f
    JOIN member m ON m.member_id = f.member_id
    ORDER BY f.release_year, m.name')->fetchAll();
foreach ($rows as $r) {
    $hit = false;
    // 名前まるごと（"Crosby, Stills, Nash & Young" のように名前自体に ", " がある人用）＋ ", " で分けた1人ずつ
    foreach (array_merge([$r['artist_name']], explode(', ', $r['artist_name'])) as $one) {
        if (isset($artistKeys[album_match_key($one)])) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        continue;
    }
    $k = album_key($r['source'], $r['album_id']);
    $fanAlbums[$k] ??= ['album' => $r, 'members' => []]; // ??=: まだ無ければ入れる
    $fanAlbums[$k]['members'][] = ['member_id' => (int)$r['member_id'], 'name' => $r['name']];
    $fanIds[(int)$r['member_id']] = true;
}
// 入れている人が多いアルバムを先に（同じ人数なら発売年順のまま。usort は PHP 8 から安定ソート）
usort($fanAlbums, static fn(array $a, array $b): int => count($b['members']) <=> count($a['members']));
$viewerApp = member_music_app($pdo, $user['member_id']);

render_header($artist['name'], 'artists');
?>
<nav class="crumbs"><a href="stats.php">集計</a><span>/</span>アーティスト</nav>
<section class="hero">
    <div>
        <p class="eyebrow">Artist</p>
        <h1 class="display"><?= h($artist['name']) ?></h1>
        <p class="muted">サークルで <?= count($bands) ?> 回コピーされました</p>
        <?php if ($aliases): ?><p class="muted small">別名: <?= h(implode('、', $aliases)) ?></p><?php endif; ?>
    </div>
</section>

<?php if ($fanAlbums): ?>
<!-- マイアルバムにこのアーティストのアルバムを入れているメンバー。見た目は member.php のマイアルバムと同じ（.albums。スマホでは横に3枚） -->
<section class="card album-box">
    <div class="album-head">
        <h2 class="section-title section-title--card" style="margin:0">マイアルバムに入れているメンバー</h2>
        <span class="muted small"><?= count($fanIds) ?>人</span>
    </div>
    <ul class="albums">
        <?php foreach ($fanAlbums as $fa): $a = $fa['album']; ?>
            <li class="album">
                <img class="album__art" src="<?= h($a['artwork_url']) ?>" alt="<?= h($a['title']) ?> のジャケット" loading="lazy" width="600" height="600">
                <!-- 見ている人の音楽アプリで開く（member.php と同じ。アプリをまたぐときは album_go.php が押されたときに探す） -->
                <a class="album__meta" href="<?= h(album_listen_url($viewerApp, $a)) ?>" target="_blank" rel="noopener">
                    <span class="album__title"><?= h($a['title']) ?></span>
                    <?php if ($a['release_year']): ?><span class="muted small album__year"><?= (int)$a['release_year'] ?></span><?php endif; ?>
                </a>
                <span class="small">
                    <?php foreach ($fa['members'] as $i => $m): ?><?= $i > 0 ? '・' : '' ?><a href="member.php?id=<?= $m['member_id'] ?>#albums"><?= h($m['name']) ?></a><?php endforeach; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<h2 class="section-title">演奏履歴</h2>
<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table">
        <thead><tr><th>ライブ</th><th>バンド名</th><th>メンバー</th><th>セットリスト</th></tr></thead>
        <tbody>
        <?php foreach ($bands as $b): ?>
            <tr>
                <td class="nowrap"><a href="live.php?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><span class="live-year"><?= h(fmt_year($b['fiscal_year'])) ?> </span><?= h($b['live_name']) ?></a>
                    <div class="muted small"><?= h($b['label']) ?> <?= h(fmt_date($b['held_on'])) ?></div></td>
                <td class="strong"><a href="band.php?id=<?= (int)$b['band_id'] ?>"><?= h($b['name']) ?></a></td>
                <td>
                    <?php if (!empty($lineups[(int)$b['band_id']])): ?>
                        <ul class="artist-lineup">
                            <?php foreach ($lineups[(int)$b['band_id']] as $m): ?>
                                <li><?= part_badge($m) ?>
                                    <a href="member.php?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($setlists[(int)$b['band_id']])): ?>
                        <ol class="artist-setlist">
                            <?php foreach ($setlists[(int)$b['band_id']] as $title): ?>
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

<?php if (is_admin()): ?>
<details class="card edit-box" style="margin-top:18px">
    <summary><?= icon('edit') ?> アーティストを編集（管理者）</summary>
    <form method="post" class="form-grid edit-box__form">
        <?= csrf_field() ?><input type="hidden" name="action" value="rename">
        <label class="field field--wide"><span>名前</span><input name="name" value="<?= h($artist['name']) ?>" maxlength="100" required></label>
        <div class="form-actions field--wide"><button class="btn btn--sm" type="submit">名前を変更</button></div>
    </form>
    <!-- 別名: マイアルバムのアーティスト名がこの表記でも、このアーティストのアルバムとして上に出す -->
    <div class="edit-box__form">
        <p class="muted small">別名（マイアルバムで「オアシス」のように別の表記になっているときに登録）</p>
        <?php foreach ($aliases as $al): ?>
            <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0 12px 6px 0">
                <?= csrf_field() ?><input type="hidden" name="action" value="alias_delete">
                <input type="hidden" name="alias" value="<?= h($al) ?>">
                <span><?= h($al) ?></span>
                <button class="btn btn--ghost btn--sm" type="submit" aria-label="別名「<?= h($al) ?>」を削除">×</button>
            </form>
        <?php endforeach; ?>
    </div>
    <form method="post" class="form-grid edit-box__form">
        <?= csrf_field() ?><input type="hidden" name="action" value="alias_add">
        <!-- list="...": 下の datalist（マイアルバムに出てくるアーティスト名）を入力候補に出す -->
        <label class="field field--wide"><span>別名を追加</span><input name="alias" maxlength="255" list="album-artist-names" required></label>
        <datalist id="album-artist-names">
            <?php foreach ($albumArtistNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?>
        </datalist>
        <div class="form-actions field--wide"><button class="btn btn--sm" type="submit">別名を追加</button></div>
    </form>
    <form method="post" class="form-grid edit-box__form" data-confirm="このアーティストのバンドを選んだアーティストに付け替えて、このアーティストを消します。">
        <?= csrf_field() ?><input type="hidden" name="action" value="merge">
        <label class="field field--wide"><span>表記ゆれで重複している場合: 統合先</span>
            <select name="to" required>
                <option value="">選択</option>
                <?php foreach ($others as $o): if ((int)$o['artist_id'] === $artistId) continue; ?>
                    <option value="<?= (int)$o['artist_id'] ?>"><?= h($o['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="form-actions field--wide"><button class="btn btn--sm btn--danger" type="submit">統合する</button></div>
    </form>
</details>
<?php endif; ?>
<?php render_footer();
