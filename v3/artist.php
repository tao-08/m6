<?php
/**
 * =====================================================================
 *  artist.php?id=アーティストID — そのアーティストをコピーしたバンドの歴代一覧
 * =====================================================================
 *  artist テーブルを分けたから作れるページ（v3 で追加）。
 *  「ヨルシカ（安田）」「ヨルシカ（八木）」も artist_id が同じなので、WHERE 1つで全部出る。
 *  バンド名の文字列で LIKE 検索するより正確（「ヨルシカ」を含む別称のバンドを拾わない）。
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
// よく演奏するメンバーの名前の右の「Gt × 3」: 同じ行をもう一度読み、1パート1行（Vo と Gt は「Vo/Gt」）にして人ごとに数える（member.php のよく組むメンバーと同じ）
//   Gt/Cho は Gt として数える（Cho はコーラスだけで出たときだけ数える）
$st->execute([$artistId]);
$playerParts = []; // [member_id] = [パート, ...]
foreach (lineup_parts_by_band($st, true, 'drop') as $p) {
    $playerParts[(int)$p['member_id']][] = $p;
}
$playerTally = array_map(static fn($parts) => sort_tally_by_count(tally_parts($parts)), $playerParts);

// よく演奏するメンバー（上位15位）: このアーティストのバンドに何回入っていたか（同じバンドで2パートやっても1回）。
//   最初は上位5人だけ見せて、残りは「さらに表示」（member.php のよく組むメンバーと同じ JS: setupPartnerBox）
$st = $pdo->prepare('SELECT m.member_id, m.name, COUNT(DISTINCT b.band_id) AS n
    FROM band b
    JOIN band_member bm ON bm.band_id = b.band_id
    JOIN member m ON m.member_id = bm.member_id
    WHERE ' . $bandWhere . '
    GROUP BY m.member_id
    ORDER BY n DESC, m.name
    LIMIT 15');
$st->execute([$artistId]);
$topPlayers = $st->fetchAll();

// セットリスト: バンドごとに曲順で全曲。オムニバスのバンドはこのアーティストの曲だけ少し強調する（mine）
$setlists = []; // [band_id] = [['title' => 曲名, 'mine' => 強調するか], ...]
$st = $pdo->prepare('SELECT s.band_id, s.title, (b.is_omnibus = 1 AND s.artist_id = ?) AS mine
    FROM band b
    JOIN song s ON s.band_id = b.band_id
    WHERE ' . $bandWhere . '
    ORDER BY s.track_no');
$st->execute([$artistId, $artistId]);
foreach ($st as $s) {
    $setlists[(int)$s['band_id']][] = ['title' => $s['title'], 'mine' => (bool)$s['mine']];
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
            // このアーティストを、別のアーティストに付け替えてから消す（lib/repository.php の merge_artist）
            $to = (int)($_POST['to'] ?? 0);
            if ($to !== $artistId) {
                $pdo->beginTransaction();
                merge_artist($pdo, $artistId, $to); // バンド・オムニバスの曲・別称を付け替えてから消す
                $pdo->commit();
                flash('アーティストを統合しました');
                redirect('artist?id=' . $to);
            }
        } elseif ($action === 'alias_add') {
            // 別称: マイアルバムのアーティスト名が別の表記（オアシス など）でも、このアーティストとして拾うため
            $alias = trim((string)($_POST['alias'] ?? ''));
            // 本名・ほかのアーティストの名前と同じ別称は付けない（どのアーティストか分からなくなる）
            $st = $pdo->prepare('SELECT 1 FROM artist WHERE name = ?');
            $st->execute([$alias]);
            $takenByArtist = (bool)$st->fetchColumn();
            $st = $pdo->prepare('SELECT 1 FROM artist_alias WHERE name = ?');
            $st->execute([$alias]);
            $takenByAlias = (bool)$st->fetchColumn();
            if ($alias === '' || mb_strlen($alias) > 255 || album_match_key($alias) === '') {
                flash('別称を入力してください', 'error');
            } elseif ($takenByArtist || $takenByAlias) {
                flash('「' . $alias . '」は既にアーティスト名か別称として使われています', 'error');
            } else {
                $pdo->prepare('INSERT INTO artist_alias (name, artist_id) VALUES (?, ?)')->execute([$alias, $artistId]);
                flash('別称「' . $alias . '」を追加しました');
            }
        } elseif ($action === 'alias_delete') {
            // artist_id も条件に入れる: 別のアーティストの別称を、このページから消せないように
            $pdo->prepare('DELETE FROM artist_alias WHERE name = ? AND artist_id = ?')
                ->execute([(string)($_POST['alias'] ?? ''), $artistId]);
            flash('別称を削除しました');
        }
        redirect('artist?id=' . $artistId);
    }
    $others = $pdo->query('SELECT artist_id, name FROM artist ORDER BY name')->fetchAll();
    // 別称の入力候補: マイアルバムに出てくるアーティスト名（表記をそのまま選べるように）
    $albumArtistNames = $pdo->query('SELECT DISTINCT artist_name FROM member_favorite_album ORDER BY artist_name')
        ->fetchAll(PDO::FETCH_COLUMN);
}

// ---- 別称（オアシス など） ----
$st = $pdo->prepare('SELECT name FROM artist_alias WHERE artist_id = ? ORDER BY name');
$st->execute([$artistId]);
$aliases = $st->fetchAll(PDO::FETCH_COLUMN);

// ---- このアーティストのアルバムをマイアルバムに入れているメンバー（本名と別称で探す。lib/albums.php の fan_albums） ----
$fans = fan_albums($pdo, [$artistId]);
$viewerApp = member_music_app($pdo, $user['member_id']);

render_header($artist['name'], 'artists');
?>
<nav class="crumbs"><a href="stats">集計</a><span>/</span>アーティスト</nav>
<section class="hero">
    <div>
        <p class="eyebrow">Artist</p>
        <h1 class="display"><?= h($artist['name']) ?></h1>
        <p class="muted">サークルで <?= count($bands) ?> 回演奏されました</p>
        <?php if ($aliases): ?><p class="muted small">別称: <?= h(implode('、', $aliases)) ?></p><?php endif; ?>
    </div>
</section>

<?= fan_albums_html($fans, 'マイアルバムに入れているメンバー', $viewerApp) // lib/albums.php ?>

<!-- よく演奏するメンバー: 最初は上位5人だけ。見出しの ▸ か「さらに表示」で15位まで（JS: setupPartnerBox） -->
<?php if ($topPlayers): ?>
<section id="top-players" class="card album-box partner-box top-players" data-partner-box data-shown="5" data-more-label="さらに表示">
    <div class="album-head">
        <button type="button" class="album-box__toggle" data-partner-toggle aria-expanded="false" aria-controls="top-players">
            <span class="album-box__chevron" aria-hidden="true">▸</span>
            よく演奏するメンバー
        </button>
    </div>
    <?php $playerRanks = tie_ranks($topPlayers, static fn($p) => (int)$p['n']); // 同じ回数は同じ順位 ?>
    <ol class="ranking">
        <?php foreach ($topPlayers as $k => $p): ?>
            <li data-rank="<?= $playerRanks[$k] ?>">
                <a href="member?id=<?= (int)$p['member_id'] ?>"><?= h($p['name']) ?></a>
                <!-- このアーティストのバンドで、その人が何を何回やったか -->
                <?= part_marks($playerTally[(int)$p['member_id']] ?? [], true, 'partbar--partner') ?>
                <span class="pill"><?= (int)$p['n'] ?>回</span>
            </li>
        <?php endforeach; ?>
    </ol>
    <button type="button" class="btn btn--ghost btn--sm album-box__more" data-partner-more hidden>さらに表示</button>
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
                <td class="nowrap"><a href="live?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><span class="live-year"><?= h(fmt_year($b['fiscal_year'])) ?> </span><?= h($b['live_name']) ?></a>
                    <div class="muted small"><?= h($b['label']) ?> <?= h(fmt_date($b['held_on'])) ?></div></td>
                <td class="strong"><a href="band?id=<?= (int)$b['band_id'] ?>"><?= h($b['name']) ?></a></td>
                <td>
                    <?php if (!empty($lineups[(int)$b['band_id']])): ?>
                        <ul class="artist-lineup">
                            <?php foreach ($lineups[(int)$b['band_id']] as $m): ?>
                                <li><?= part_badge($m) ?>
                                    <a href="member?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($setlists[(int)$b['band_id']])): ?>
                        <ol class="artist-setlist">
                            <?php foreach ($setlists[(int)$b['band_id']] as $song): ?>
                                <li<?= $song['mine'] ? ' class="is-mine"' : '' ?>><?= h($song['title']) ?></li>
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
    <!-- 別称: マイアルバムのアーティスト名がこの表記でも、このアーティストのアルバムとして上に出す -->
    <div class="edit-box__form">
        <p class="muted small">別称（マイアルバムで「オアシス」のように別の表記になっているときに登録）</p>
        <?php foreach ($aliases as $al): ?>
            <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0 12px 6px 0">
                <?= csrf_field() ?><input type="hidden" name="action" value="alias_delete">
                <input type="hidden" name="alias" value="<?= h($al) ?>">
                <span><?= h($al) ?></span>
                <button class="btn btn--ghost btn--sm" type="submit" aria-label="別称「<?= h($al) ?>」を削除">×</button>
            </form>
        <?php endforeach; ?>
    </div>
    <form method="post" class="form-grid edit-box__form">
        <?= csrf_field() ?><input type="hidden" name="action" value="alias_add">
        <!-- list="...": 下の datalist（マイアルバムに出てくるアーティスト名）を入力候補に出す -->
        <label class="field field--wide"><span>別称を追加</span><input name="alias" maxlength="255" list="album-artist-names" required></label>
        <datalist id="album-artist-names">
            <?php foreach ($albumArtistNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?>
        </datalist>
        <div class="form-actions field--wide"><button class="btn btn--sm" type="submit">別称を追加</button></div>
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
