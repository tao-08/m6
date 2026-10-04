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

$st = $pdo->prepare('SELECT b.band_id, b.name, b.song_count, d.live_day_id, d.label, d.held_on, l.live_id, l.fiscal_year, l.name AS live_name,
        GROUP_CONCAT(DISTINCT m.name ORDER BY i.sort_order SEPARATOR \'、\') AS members
    FROM band b
    JOIN live_day d ON d.live_day_id = b.live_day_id
    JOIN live l ON l.live_id = d.live_id
    LEFT JOIN band_member bm ON bm.band_id = b.band_id
    LEFT JOIN member m ON m.member_id = bm.member_id
    LEFT JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE b.artist_id = ?
    GROUP BY b.band_id
    ORDER BY d.held_on DESC, l.fiscal_year DESC');
$st->execute([$artistId]);
$bands = $st->fetchAll();

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
        <thead><tr><th>ライブ</th><th>バンド名</th><th>メンバー</th></tr></thead>
        <tbody>
        <?php foreach ($bands as $b): ?>
            <tr>
                <td class="nowrap"><a href="live.php?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><?= h(fmt_year($b['fiscal_year'])) ?> <?= h($b['live_name']) ?></a>
                    <div class="muted small"><?= h($b['label']) ?> <?= h(fmt_date($b['held_on'])) ?></div></td>
                <td class="strong"><?= h($b['name']) ?></td>
                <td class="small"><?= h($b['members'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if (is_admin()): ?>
<details class="card edit-box" style="margin-top:18px">
    <summary>✎ アーティストを編集（管理者）</summary>
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
