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

$st = $pdo->prepare('SELECT b.band_id, b.name, d.live_day_id, d.label, d.held_on, l.live_id, l.fiscal_year, l.name AS live_name
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    WHERE b.artist_id = ?
    ORDER BY d.held_on DESC, l.fiscal_year DESC');
$st->execute([$artistId]);
$bands = $st->fetchAll();

// メンバー: バンドごとに「楽器ラベル + 名前」を1人1行（楽器の並び順 → 名前順）
//   1人で Vo と Gt を持つ人は「Gt/Vo」の1行にまとめる。並び位置は最初のパート（Vo）の位置
$lineups = []; // [band_id][member_id] = ['member_id', 'name', 'parts' => [['short', 'title'], ...]]
$st = $pdo->prepare('SELECT bm.band_id, m.member_id, m.name, i.short_name, i.name AS instrument_name
    FROM band b
    JOIN band_member bm ON bm.band_id = b.band_id
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE b.artist_id = ?
    ORDER BY i.sort_order, m.name');
$st->execute([$artistId]);
foreach ($st as $m) {
    $person = &$lineups[(int)$m['band_id']][(int)$m['member_id']];
    $person['member_id'] = (int)$m['member_id'];
    $person['name'] = $m['name'];
    $person['parts'][] = ['short' => $m['short_name'], 'title' => $m['instrument_name']];
    unset($person);
}
// ラベルは「Gt/Vo」「Ba/Cho」のように、歌うパート（Vo / Cho）を後ろへ。色は先頭のパート（Gt/Vo ならギターの色）
foreach ($lineups as &$people) {
    foreach ($people as &$person) {
        $sing = static fn(array $p): int => in_array(instrument_class($p['short']), ['vo', 'cho'], true) ? 1 : 0;
        usort($person['parts'], static fn($a, $b) => $sing($a) <=> $sing($b)); // usort は同じ値の順番を保つ（PHP 8）
        $person['label'] = implode('/', array_column($person['parts'], 'short'));
        $person['title'] = implode('/', array_column($person['parts'], 'title'));
        $person['class'] = instrument_class($person['parts'][0]['short']);
    }
    unset($person);
}
unset($people);

// セットリスト: バンドごとに曲順で
$setlists = []; // [band_id] = [曲名, ...]
$st = $pdo->prepare('SELECT s.band_id, s.title
    FROM band b
    JOIN song s ON s.band_id = b.band_id
    WHERE b.artist_id = ?
    ORDER BY s.track_no');
$st->execute([$artistId]);
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
                $pdo->prepare('DELETE FROM artist WHERE artist_id = ?')->execute([$artistId]);
                $pdo->commit();
                flash('アーティストを統合しました');
                redirect('artist.php?id=' . $to);
            }
        }
        redirect('artist.php?id=' . $artistId);
    }
    $others = $pdo->query('SELECT artist_id, name FROM artist ORDER BY name')->fetchAll();
}

render_header($artist['name']);
?>
<nav class="crumbs"><a href="stats.php">集計</a><span>/</span>アーティスト</nav>
<section class="hero">
    <div>
        <p class="eyebrow">Artist</p>
        <h1 class="display"><?= h($artist['name']) ?></h1>
        <p class="muted">サークルで <?= count($bands) ?> 回コピーされました</p>
    </div>
</section>

<div class="card table-card">
    <div class="table-scroll table-scroll--flush">
    <table class="table">
        <thead><tr><th>ライブ</th><th>バンド名</th><th>メンバー</th><th>セットリスト</th></tr></thead>
        <tbody>
        <?php foreach ($bands as $b): ?>
            <tr>
                <td class="nowrap"><a href="live.php?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><?= h(fmt_year($b['fiscal_year'])) ?> <?= h($b['live_name']) ?></a>
                    <div class="muted small"><?= h($b['label']) ?> <?= h(fmt_date($b['held_on'])) ?></div></td>
                <td class="strong"><a href="band.php?id=<?= (int)$b['band_id'] ?>"><?= h($b['name']) ?></a></td>
                <td>
                    <?php if (!empty($lineups[(int)$b['band_id']])): ?>
                        <ul class="artist-lineup">
                            <?php foreach ($lineups[(int)$b['band_id']] as $m): ?>
                                <li><span class="part part--<?= h($m['class']) ?>" title="<?= h($m['title']) ?>"><?= h($m['label']) ?></span>
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
