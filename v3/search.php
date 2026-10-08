<?php
/**
 * =====================================================================
 *  search.php?q=キーワード — ライブ・バンド・メンバーの横断検索
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
$lives = $bands = $members = $artists = [];
$bandTotal = 0;

if ($q !== '') {
    $pdo = db();
    $like = '%' . escape_like(mb_substr($q, 0, 50)) . '%';

    // ---- ライブ（ライブ名・会場名・日程名・日程のメモ） ----
    $st = $pdo->prepare('SELECT DISTINCT lm.live_id, lm.fiscal_year AS year, lm.name
        FROM live lm
        JOIN live_day ld ON ld.live_id = lm.live_id
        LEFT JOIN venue v ON v.venue_id = ld.venue_id
        WHERE lm.name LIKE ? OR v.name LIKE ? OR ld.label LIKE ? OR ld.note LIKE ?
        ORDER BY lm.fiscal_year DESC LIMIT 30');
    $st->execute([$like, $like, $like, $like]);
    $lives = $st->fetchAll();

    // 見つかったライブの日程（日程名・日付・会場）。会場で当たった日以外も、そのライブの日程は全部出す
    //   MariaDB は IN (サブクエリ) の中で LIMIT が使えないので、ID を取ってから2本目のクエリで引く
    $daysByLive = []; // [live_id] = [['live_day_id', 'label', 'held_on', 'venue'], ...]
    if ($lives) {
        $ids = array_map('intval', array_column($lives, 'live_id'));
        $st = $pdo->prepare('SELECT ld.live_id, ld.live_day_id, ld.label, ld.held_on, ld.note, v.name AS venue
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
    $st = $pdo->prepare('SELECT COUNT(*) FROM band b WHERE b.name LIKE ? OR b.note LIKE ?');
    $st->execute([$like, $like]);
    $bandTotal = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT b.band_id, b.name, b.song_count, b.note, ld.live_day_id, ld.label, ld.held_on AS date,
            lm.live_id, lm.fiscal_year AS year, lm.name AS live_name
        FROM band b
        JOIN live_day ld ON ld.live_day_id = b.live_day_id
        JOIN live lm ON lm.live_id = ld.live_id
        WHERE b.name LIKE ? OR b.note LIKE ?
        ORDER BY ld.held_on DESC, b.play_order LIMIT ?');
    $st->bindValue(1, $like);
    $st->bindValue(2, $like);
    $st->bindValue(3, BANDS_PER_PAGE * $bandPage, PDO::PARAM_INT); // LIMIT は数値として渡す（文字列 '20' だとエラーになることがある）
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

        $st = $pdo->prepare("SELECT band_id, title FROM song WHERE band_id IN ($in) ORDER BY track_no");
        $st->execute($ids);
        foreach ($st as $s) {
            $setlists[(int)$s['band_id']][] = $s['title'];
        }
    }

    // ---- アーティスト ----
    // 回数はアーティストページと同じ数え方（オムニバスは曲に付いたアーティストを1バンド1回。ARTIST_PLAYS_SQL）
    $st = $pdo->prepare('SELECT a.artist_id, a.name, COUNT(p.band_id) AS n FROM artist a
        LEFT JOIN (' . ARTIST_PLAYS_SQL . ') p ON p.artist_id = a.artist_id
        WHERE a.name LIKE ? GROUP BY a.artist_id ORDER BY n DESC LIMIT 30');
    $st->execute([$like]);
    $artists = $st->fetchAll();

    // ---- メンバー（名前・ふりがな） ----
    $st = $pdo->prepare('SELECT m.member_id, m.name, m.name_kana,
            (SELECT COUNT(DISTINCT x.band_id) FROM band_member x WHERE x.member_id = m.member_id) AS bands
        FROM member m
        WHERE m.name LIKE ? OR m.name_kana LIKE ?
        ORDER BY bands DESC, m.name LIMIT 60');
    $st->execute([$like, $like]);
    $members = $st->fetchAll();
}

render_header($q !== '' ? "「{$q}」の検索結果" : '検索', 'search');
?>
<section class="hero">
    <div>
        <p class="eyebrow">Search</p>
        <h1 class="display">検索</h1>
    </div>
</section>

<!-- GET で送るので、検索結果の URL をそのまま共有・ブックマークできる -->
<form method="get" class="toolbar">
    <input type="search" name="q" value="<?= h($q) ?>" class="search" placeholder="バンド名・メンバー名・ライブ名・会場・メモ" autofocus aria-label="検索ワード">
    <button class="btn btn--primary" type="submit">検索</button>
</form>

<?php if ($q === ''): ?>
    <p class="muted">例: 「ヨルシカ」で過去にヨルシカをコピーしたバンドが全部出ます。</p>
<?php elseif (!$lives && !$bands && !$members && !$artists): ?>
    <div class="empty card"><p class="empty__title">見つかりませんでした</p><p class="muted">表記を変えて試してみてください（全角/半角、スペースの有無など）</p></div>
<?php else: ?>
    <div class="search-results">
        <?php if ($members): ?>
        <section>
            <h2 class="section-title">メンバー <span class="muted"><?= count($members) ?></span></h2>
            <div class="chip-list">
                <?php foreach ($members as $m): ?>
                    <a class="chip chip--lg<?= (int)$m['member_id'] === $user['member_id'] ? ' chip--me' : '' ?>" href="member.php?id=<?= (int)$m['member_id'] ?>">
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
                    <a class="chip chip--lg" href="artist.php?id=<?= (int)$a['artist_id'] ?>"><?= h($a['name']) ?> <span class="muted small"><?= (int)$a['n'] ?>回</span></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($bands): ?>
        <section>
            <h2 class="section-title">バンド <span class="muted"><?= $bandTotal ?></span></h2>
            <div class="card table-card">
                <div class="table-scroll table-scroll--flush">
                <table class="table">
                    <thead><tr><th>バンド名</th><th>ライブ</th><th>メンバー</th><th>セットリスト</th></tr></thead>
                    <tbody>
                    <?php foreach ($bands as $i => $b): $bandId = (int)$b['band_id']; ?>
                        <tr id="band-n<?= $i + 1 ?>">
                            <td class="strong"><a href="band.php?id=<?= $bandId ?>"><?= h($b['name']) ?></a>
                                <?php if ($b['note']): // メモで当たったときに理由が分かるように（live.php と同じ見た目） ?><div><span class="keynote"><?= icon('piano') ?> <?= h($b['note']) ?></span></div><?php endif; ?></td>
                            <td class="nowrap"><a href="live.php?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><?= h(fmt_year($b['year'])) ?> <?= h($b['live_name']) ?></a>
                                <div class="muted small"><?= h($b['label']) ?> <?= h(fmt_date($b['date'])) ?></div></td>
                            <td>
                                <?php if (!empty($lineups[$bandId])): ?>
                                    <ul class="artist-lineup">
                                        <?php foreach ($lineups[$bandId] as $m): ?>
                                            <li><?= part_badge($m) ?>
                                                <a href="member.php?id=<?= (int)$m['member_id'] ?>"><?= h($m['name']) ?></a></li>
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
                <p class="more-link"><a class="btn btn--sm" href="search.php?<?= h(http_build_query(['q' => $q, 'bp' => $bandPage + 1])) ?>#band-n<?= count($bands) + 1 ?>">
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
                                <td class="strong nowrap" rowspan="<?= count($days) ?>"><a href="live.php?id=<?= $liveId ?>"><?= h(fmt_year($l['year'])) ?> <?= h($l['name']) ?></a></td>
                            <?php endif; ?>
                            <td class="nowrap"><a href="live.php?id=<?= $liveId ?>#day-<?= (int)$d['live_day_id'] ?>"><?= h($d['label']) ?></a>
                                <?php if ($d['note']): ?><div class="muted small"><?= h($d['note']) ?></div><?php endif; ?></td>
                            <td class="muted nowrap"><?= $d['held_on'] !== null ? h(fmt_date($d['held_on'])) : '—' ?></td>
                            <td><?= $d['venue'] !== null ? h($d['venue']) : '<span class="muted">—</span>' ?></td>
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
