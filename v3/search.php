<?php
/**
 * =====================================================================
 *  search.php?q=キーワード — ライブ・バンド・メンバーの横断検索
 * =====================================================================
 *  LIKE '%キーワード%' で部分一致検索している。
 *
 *  ⚠ LIKE の落とし穴: ユーザーが「%」や「_」を入力すると、それ自体が
 *    「何でも一致」の記号として働いてしまう。escape_like() で \% \_ に変えてから使う。
 *    （SQL インジェクションはプリペアドステートメントで防げているが、これは別の問題）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

/** LIKE で特別な意味を持つ文字（\ % _）を、ただの文字として扱わせる */
function escape_like(string $s): string
{
    return addcslashes($s, '\\%_');
}

$q = trim((string)($_GET['q'] ?? ''));
$lives = $bands = $members = $artists = [];

if ($q !== '') {
    $pdo = db();
    $like = '%' . escape_like(mb_substr($q, 0, 50)) . '%';

    // ---- ライブ（ライブ名・会場名） ----
    $st = $pdo->prepare('SELECT DISTINCT lm.live_id, lm.fiscal_year AS year, lm.name
        FROM live lm
        JOIN live_day ld ON ld.live_id = lm.live_id
        LEFT JOIN venue v ON v.venue_id = ld.venue_id
        WHERE lm.name LIKE ? OR v.name LIKE ? OR ld.label LIKE ?
        ORDER BY lm.fiscal_year DESC LIMIT 30');
    $st->execute([$like, $like, $like]);
    $lives = $st->fetchAll();

    // ---- バンド（同じアーティストを何回コピーしたかも分かる） ----
    $st = $pdo->prepare('SELECT b.band_id, b.name, b.song_count, ld.live_day_id, ld.label, ld.held_on AS date,
            lm.live_id, lm.fiscal_year AS year, lm.name AS live_name
        FROM band b
        JOIN live_day ld ON ld.live_day_id = b.live_day_id
        JOIN live lm ON lm.live_id = ld.live_id
        WHERE b.name LIKE ?
        ORDER BY ld.held_on DESC, b.play_order LIMIT 100');
    $st->execute([$like]);
    $bands = $st->fetchAll();

    // ---- アーティスト ----
    $st = $pdo->prepare('SELECT a.artist_id, a.name, COUNT(b.band_id) AS n FROM artist a
        LEFT JOIN band b ON b.artist_id = a.artist_id
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
    <input type="search" name="q" value="<?= h($q) ?>" class="search" placeholder="バンド名・メンバー名・ライブ名・会場" autofocus aria-label="検索ワード">
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
            <h2 class="section-title">バンド <span class="muted"><?= count($bands) ?></span></h2>
            <div class="card table-card">
                <table class="table">
                    <thead><tr><th>バンド名</th><th>ライブ</th><th class="hide-sm">日付</th></tr></thead>
                    <tbody>
                    <?php foreach ($bands as $b): ?>
                        <tr>
                            <td><a class="strong" href="live.php?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><?= h($b['name']) ?></a></td>
                            <td><?= h(fmt_year($b['year'])) ?> <?= h($b['live_name']) ?> <span class="muted"><?= h($b['label']) ?></span></td>
                            <td class="hide-sm muted nowrap"><?= h(fmt_date($b['date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($lives): ?>
        <section>
            <h2 class="section-title">ライブ <span class="muted"><?= count($lives) ?></span></h2>
            <div class="chip-list">
                <?php foreach ($lives as $l): ?>
                    <a class="chip chip--lg" href="live.php?id=<?= (int)$l['live_id'] ?>"><?= h(fmt_year($l['year'])) ?> <?= h($l['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php render_footer();
