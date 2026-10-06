<?php
/**
 * =====================================================================
 *  member.php?id=メンバーID — メンバーの個人ページ
 * =====================================================================
 *  出演履歴 / 楽器の内訳 / よく組むメンバー を出す。
 *  「よく組むメンバー」は band_member を自分自身と JOIN（自己結合）して、
 *  同じ band_id にいる「自分以外の人」を数えている。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/itunes.php';
$user = require_login();

$memberId = (int)($_GET['id'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT * FROM member WHERE member_id = ?');
$st->execute([$memberId]);
$member = $st->fetch();
if (!$member) {
    http_response_code(404);
    render_header('見つかりません');
    echo '<div class="empty card"><p class="empty__title">メンバーが見つかりません</p><a class="btn" href="members.php">一覧へ戻る</a></div>';
    render_footer();
    exit;
}

// ---- 出演履歴（新しい順） ----
//   GROUP_CONCAT: 複数行の値を1つの文字列につなげる（Vo.と Ba.を兼任なら「Vo./Ba.」）
//   is_last: その日の最大 play_order と同じなら 1（トリ）
$st = $pdo->prepare('SELECT b.band_id, b.name AS band_name, b.play_order,
        GROUP_CONCAT(DISTINCT i.short_name ORDER BY i.sort_order SEPARATOR \'/\') AS parts,
        ld.live_day_id, ld.label, ld.held_on AS date, lm.live_id, lm.fiscal_year AS year, lm.name AS live_name, v.name AS venue_name,
        (b.play_order = (SELECT MAX(b2.play_order) FROM band b2 WHERE b2.live_day_id = b.live_day_id)) AS is_last
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    JOIN band_member bmi ON bmi.band_id = b.band_id AND bmi.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bmi.instrument_id
    WHERE bm.member_id = ?
    GROUP BY b.band_id
    ORDER BY lm.fiscal_year DESC, ld.held_on IS NULL, ld.held_on DESC, ld.live_day_id DESC, b.play_order');
$st->execute([$memberId]);
$history = $st->fetchAll();

// ---- 楽器の内訳 ----
$st = $pdo->prepare('SELECT i.short_name, i.name AS instrument_name, COUNT(*) AS n
    FROM band_member bm JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.member_id = ? GROUP BY i.instrument_id ORDER BY n DESC');
$st->execute([$memberId]);
$parts = $st->fetchAll();

// ---- よく組むメンバー（自己結合） ----
$st = $pdo->prepare('SELECT m.member_id, m.name, COUNT(DISTINCT other.band_id) AS n
    FROM (' . MEMBERSHIP_SQL . ') mine
    JOIN (' . MEMBERSHIP_SQL . ') other ON other.band_id = mine.band_id AND other.member_id <> mine.member_id
    JOIN member m ON m.member_id = other.member_id
    WHERE mine.member_id = ?
    GROUP BY m.member_id
    ORDER BY n DESC, m.name
    LIMIT 12');
$st->execute([$memberId]);
$partners = $st->fetchAll();

$headliners = count(array_filter($history, static fn($h) => (int)$h['is_last'] === 1));
$liveCount = count(array_unique(array_column($history, 'live_id')));
$isMe = $memberId === $user['member_id'];

// ---- 好きなアルバム（登録順） ----
//   登録時に保存した内容（スナップショット）を出すだけなので、ここでは iTunes に通信しない
$st = $pdo->prepare('SELECT sort_order, title, artist_name, artwork_url, release_year
    FROM member_favorite_album WHERE member_id = ? ORDER BY sort_order');
$st->execute([$memberId]);
$albums = $st->fetchAll();

// ---- アルバム検索（本人が検索欄に入力して送信したときだけ） ----
//   検索は「データを読むだけ」なので GET（URL に ?album_q=... が付く）。
//   データを変える「追加・削除」は POST + CSRF（member_album_save.php）。← 業界の基本ルール
$albumQuery = $isMe ? trim((string)($_GET['album_q'] ?? '')) : '';
$albumResults = null;  // null = まだ検索していない
$albumSearchFailed = false;
if ($albumQuery !== '') {
    $albumQuery = mb_substr($albumQuery, 0, 100); // 長すぎる入力は切る
    $albumResults = itunes_search_albums($albumQuery);
    if ($albumResults === null) {
        $albumSearchFailed = true; // iTunes に繋がらなかった
        $albumResults = [];
    }
    // 登録済みのアルバムは「追加」ボタンの代わりに「登録済み」と出したいので、ID の一覧を作る
    $st = $pdo->prepare('SELECT itunes_collection_id FROM member_favorite_album WHERE member_id = ?');
    $st->execute([$memberId]);
    // array_flip: [値 => 番号] に入れ替える → isset($registered[ID]) で「あるか」を一瞬で調べられる
    $registered = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
}

render_header($member['name'], 'members');
?>
<nav class="crumbs"><a href="members.php">メンバー</a><span>/</span><?= h($member['name']) ?></nav>
<section class="hero">
    <div>
        <p class="eyebrow"><?= $isMe ? 'My Page' : 'Member' ?></p>
        <h1 class="display"><?= h($member['name']) ?></h1>
        <p class="muted small">
            <?= h($member['name_kana'] ?? '') ?>
            <?= (int)$member['entry_year'] > 0 ? ' · ' . (int)$member['entry_year'] . '年度入部' : '' ?>
        </p>
        <div class="partbar">
            <?php foreach ($parts as $p): ?>
                <span class="part part--<?= h(instrument_class($p['short_name'])) ?>" title="<?= h($p['instrument_name']) ?>"><?= h($p['short_name']) ?> × <?= (int)$p['n'] ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <dl class="stats">
        <div><dt>出演バンド</dt><dd><?= count($history) ?></dd></div>
        <div><dt>ライブ</dt><dd><?= $liveCount ?></dd></div>
        <div><dt>トリ</dt><dd><?= $headliners ?></dd></div>
    </dl>
</section>

<?php if ($isMe || is_admin()): ?>
<!-- 本人か管理者だけに見える編集フォーム。<details> なので普段は閉じている -->
<details class="card edit-box">
    <summary>✎ プロフィールを編集</summary>
    <form method="post" action="member_edit.php" class="form-grid edit-box__form">
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= $memberId ?>">
        <label class="field field--wide"><span>名前</span><input name="name" value="<?= h($member['name']) ?>" maxlength="50" required></label>
        <label class="field"><span>ふりがな</span><input name="name_kana" value="<?= h($member['name_kana']) ?>" maxlength="50"></label>
        <label class="field"><span>入部年度</span><input type="number" name="entry_year" min="1950" max="2100" value="<?= (int)$member['entry_year'] ?: '' ?>"></label>
        <div class="form-actions field--wide"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
</details>
<?php endif; ?>

<!-- ===== 好きなアルバム ===== -->
<section id="albums">
    <h2 class="section-title">好きなアルバム <span class="muted small"><?= count($albums) ?> / <?= FAVORITE_ALBUM_LIMIT ?></span></h2>

    <?php if ($albums): ?>
        <ul class="albums">
            <?php foreach ($albums as $a): ?>
                <li class="album">
                    <!-- loading="lazy": 画面に近づくまで画像を読み込まない（30枚あっても最初の表示が重くならない） -->
                    <!-- alt: 画像が出ないときや読み上げソフト用の説明文。img には必ず付けるのがマナー -->
                    <img class="album__art" src="<?= h($a['artwork_url']) ?>" alt="<?= h($a['title']) ?> のジャケット" loading="lazy" width="600" height="600">
                    <div class="album__meta">
                        <strong class="album__title"><?= h($a['title']) ?></strong>
                        <span class="muted small"><?= h($a['artist_name']) ?><?= $a['release_year'] ? ' · ' . (int)$a['release_year'] : '' ?></span>
                    </div>
                    <?php if ($isMe || is_admin()): ?>
                        <form method="post" action="member_album_save.php" class="album__delete" data-confirm="「<?= h($a['title']) ?>」を好きなアルバムから外します。よろしいですか？">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="member_id" value="<?= $memberId ?>">
                            <input type="hidden" name="sort_order" value="<?= (int)$a['sort_order'] ?>">
                            <button type="submit" class="btn btn--danger btn--sm" title="削除" aria-label="削除">×</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted"><?= $isMe ? 'まだ登録されていません。下の「アルバムを追加」から探してみよう' : 'まだ登録されていません' ?></p>
    <?php endif; ?>

    <?php if ($isMe): ?>
    <!-- 検索した直後（album_q がある）は開いた状態で表示する -->
    <details class="card edit-box album-search" <?= $albumQuery !== '' ? 'open' : '' ?>>
        <summary>＋ アルバムを追加</summary>
        <?php if (count($albums) >= FAVORITE_ALBUM_LIMIT): ?>
            <p class="muted small">上限の<?= FAVORITE_ALBUM_LIMIT ?>枚に達しています。追加するにはどれかを削除してください。</p>
        <?php else: ?>
            <!-- 検索は GET。送信すると member.php?id=..&album_q=.. に移動し、上の PHP が iTunes を検索する -->
            <form method="get" action="member.php#albums" class="album-search__form">
                <input type="hidden" name="id" value="<?= $memberId ?>">
                <input class="search" type="search" name="album_q" value="<?= h($albumQuery) ?>" placeholder="アルバム名やアーティスト名で検索" maxlength="100" required>
                <button class="btn btn--primary btn--sm" type="submit">検索</button>
            </form>

            <?php if ($albumSearchFailed): ?>
                <p class="muted small">iTunes に接続できませんでした。時間をおいてもう一度試してください。</p>
            <?php elseif ($albumResults === []): ?>
                <p class="muted small">「<?= h($albumQuery) ?>」に一致するアルバムが見つかりませんでした。</p>
            <?php elseif ($albumResults): ?>
                <ul class="albums albums--pick">
                    <?php foreach ($albumResults as $r): ?>
                        <li class="album">
                            <img class="album__art" src="<?= h($r['artwork_url']) ?>" alt="<?= h($r['title']) ?> のジャケット" loading="lazy" width="600" height="600">
                            <div class="album__meta">
                                <strong class="album__title"><?= h($r['title']) ?></strong>
                                <span class="muted small"><?= h($r['artist_name']) ?><?= $r['release_year'] ? ' · ' . (int)$r['release_year'] : '' ?></span>
                            </div>
                            <?php if (isset($registered[$r['collection_id']])): ?>
                                <span class="pill">登録済み</span>
                            <?php else: ?>
                                <!-- 送るのは collection_id だけ。タイトルや画像URLはサーバー側で取り直す -->
                                <form method="post" action="member_album_save.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="member_id" value="<?= $memberId ?>">
                                    <input type="hidden" name="collection_id" value="<?= (int)$r['collection_id'] ?>">
                                    <button type="submit" class="btn btn--primary btn--sm">追加</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <!-- iTunes の画像を使うので、出典を明記しておく -->
                <p class="muted small">検索結果・ジャケット画像: iTunes Search API</p>
            <?php endif; ?>
        <?php endif; ?>
    </details>
    <?php endif; ?>
</section>

<div class="split">
    <section>
        <h2 class="section-title">出演履歴</h2>
        <ol class="history">
            <?php foreach ($history as $hi): ?>
                <li class="card history__item<?= $hi['is_last'] ? ' is-last' : '' ?>">
                    <div class="history__when">
                        <span><?= h(fmt_year($hi['year'])) ?></span>
                        <span class="muted"><?= h(fmt_date($hi['date'])) ?></span>
                    </div>
                    <div class="history__what">
                        <a href="live.php?id=<?= (int)$hi['live_id'] ?>#day-<?= (int)$hi['live_day_id'] ?>" class="muted small"><?= h($hi['live_name']) ?> <?= h($hi['label']) ?><?= $hi['venue_name'] ? ' · ' . h($hi['venue_name']) : '' ?></a>
                        <strong><?= h($hi['band_name']) ?></strong>
                    </div>
                    <div class="history__tags">
                        <?php if ($hi['parts']): ?><span class="pill"><?= h($hi['parts']) ?></span><?php endif; ?>
                        <?php if ($hi['is_last']): ?><span class="tag tag--accent">トリ</span><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if (!$history): ?><p class="muted">出演データがありません</p><?php endif; ?>
    </section>
    <aside>
        <h2 class="section-title">よく組むメンバー</h2>
        <div class="card">
            <?php if (!$partners): ?><p class="muted">まだいません</p><?php endif; ?>
            <ol class="ranking">
                <?php foreach ($partners as $p): ?>
                    <li><a href="member.php?id=<?= (int)$p['member_id'] ?>"><?= h($p['name']) ?></a><span class="pill"><?= (int)$p['n'] ?>回</span></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </aside>
</div>
<?php render_footer();
