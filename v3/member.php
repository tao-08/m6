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
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/albums.php';
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
//   is_last: その日の最大 play_order と同じなら 1（トリ）
//   setlist_count: 登録済みの曲数（song_count と一致すれば「セットリスト登録済」）
$st = $pdo->prepare('SELECT b.band_id, b.name AS band_name, b.play_order, b.song_count,
        (SELECT COUNT(*) FROM song s WHERE s.band_id = b.band_id) AS setlist_count,
        ld.live_day_id, ld.label, ld.held_on AS date, lm.live_id, lm.fiscal_year AS year, lm.name AS live_name, v.name AS venue_name,
        (b.play_order = (SELECT MAX(b2.play_order) FROM band b2 WHERE b2.live_day_id = b.live_day_id)) AS is_last
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN band b ON b.band_id = bm.band_id
    JOIN live_day ld ON ld.live_day_id = b.live_day_id
    JOIN live lm ON lm.live_id = ld.live_id
    LEFT JOIN venue v ON v.venue_id = ld.venue_id
    WHERE bm.member_id = ?
    GROUP BY b.band_id
    ORDER BY lm.fiscal_year DESC, ld.held_on IS NULL, ld.held_on DESC, ld.live_day_id DESC, b.play_order');
$st->execute([$memberId]);
$history = $st->fetchAll();

// ---- 楽器（バンドごと）と、その内訳 ----
//   Vo と Gt を両方やったバンドは「Vo/Gt」1つにまとめる（lineup_parts_by_band）。内訳もその単位で数える
$st = $pdo->prepare('SELECT bm.band_id, bm.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.member_id = ? ORDER BY i.sort_order');
$st->execute([$memberId]);
$partsByBand = []; // [band_id] = [パート, ...]（出演履歴のマークに使う）
$myParts = lineup_parts_by_band($st);
foreach ($myParts as $p) {
    $partsByBand[$p['band_id']][] = $p;
}
$parts = sort_tally_by_count(tally_parts($myParts)); // 「Vo/Gt × 3」「Gt × 2」…

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

// ---- 出演履歴を「同じ日程（live_day）」ごとにまとめる ----
//   SQL は1バンド1行で返ってくる。並び順は日程ごとに固まっているので、
//   live_day_id をキーにした配列に入れていけば、順番を保ったままグループにできる
$historyDays = [];
foreach ($history as $hi) {
    $dayId = (int)$hi['live_day_id'];
    if (!isset($historyDays[$dayId])) {
        $historyDays[$dayId] = $hi + ['bands' => [], 'has_last' => false];
    }
    $historyDays[$dayId]['bands'][] = $hi;
    if ((int)$hi['is_last'] === 1) {
        $historyDays[$dayId]['has_last'] = true;
    }
}

$headliners = count(array_filter($history, static fn($h) => (int)$h['is_last'] === 1));
$liveCount = count(array_unique(array_column($history, 'live_id')));
$isMe = $memberId === $user['member_id'];

// ---- マイアルバム（登録順） ----
//   登録時に保存した内容（スナップショット）を出すだけなので、ここでは Spotify / iTunes に通信しない
$st = $pdo->prepare('SELECT source, album_id, title, artist_name, artwork_url, release_year
    FROM member_favorite_album WHERE member_id = ? ORDER BY sort_order');
$st->execute([$memberId]);
$albums = $st->fetchAll();

// ---- マイアルバムのリンクを、見ている人（ログイン中の人）の音楽アプリで開く ----
//   $viewerApp: プロフィール編集で選んだアプリ。選んでいなければ null（登録元のページを開く）
//   $linkCache: Spotify ⇔ Apple Music をまたぐときに、前に album_go.php が探した結果。
//     アルバムごとに SQL を投げると30回になるので、このページのアルバムの分を1回でまとめて読む。
//     キー → URL（見つからなかったなら null）。キーが無い = まだ探していない
$viewerApp = member_music_app($pdo, $user['member_id']);
$linkCache = [];
if ($viewerApp !== null && $albums) {
    $st = $pdo->prepare('SELECT CONCAT(c.source, ":", c.album_id) AS album_key, c.url
        FROM album_link_cache c
        JOIN member_favorite_album f ON f.source = c.source AND f.album_id = c.album_id
        WHERE f.member_id = ? AND c.app = ?
          AND (c.url IS NOT NULL OR c.checked_at > NOW() - INTERVAL ' . ALBUM_NOT_FOUND_RETRY_DAYS . ' DAY)');
    // ↑ 「見つからなかった」は期限切れなら読まない → リンクが album_go.php に戻り、押されたときに探し直す
    $st->execute([$memberId, $viewerApp]);
    foreach ($st->fetchAll() as $row) {
        $linkCache[$row['album_key']] = $row['url'];
    }
}
// どのアプリで開くか（リンクに乗せたときのツールチップ用）
$listenLabel = static fn(array $a): string => MUSIC_APPS[$viewerApp] ?? ($a['source'] === 'spotify' ? 'Spotify' : 'Apple Music');

// ---- アルバム検索（本人が検索欄に入力して送信したときだけ） ----
//   検索は「データを読むだけ」なので GET（URL に ?album_q=... が付く）。
//   データを変える「追加・削除」は POST + CSRF（member_album_save.php）。← 業界の基本ルール
$albumQuery = $isMe ? trim((string)($_GET['album_q'] ?? '')) : '';
$albumResults = null;  // null = まだ検索していない
$albumSearchFailed = false;
$albumSource = null;   // 実際に検索に使ったサービス（'spotify' / 'itunes'）
$albumFellBack = false; // Spotify が失敗して iTunes で探し直したか
if ($albumQuery !== '') {
    $albumQuery = mb_substr($albumQuery, 0, 100); // 長すぎる入力は切る
    // Spotify か iTunes かは lib/albums.php が決める（Spotify のキーが無い・失敗したら iTunes）
    ['albums' => $albumResults, 'source' => $albumSource, 'fell_back' => $albumFellBack] = album_search($albumQuery);
    if ($albumResults === null) {
        $albumSearchFailed = true; // どのサービスにも繋がらなかった
        $albumResults = [];
    }
    // 登録済みのアルバムは「追加」ボタンの代わりに「登録済み」と出したいので、キーの一覧を作る
    $st = $pdo->prepare('SELECT CONCAT(source, ":", album_id) FROM member_favorite_album WHERE member_id = ?');
    $st->execute([$memberId]);
    // array_flip: [値 => 番号] に入れ替える → isset($registered[キー]) で「あるか」を一瞬で調べられる
    $registered = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
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
            <?= (int)$member['entry_year'] > 0 ? ' · ' . (int)$member['entry_year'] . '年入学' : '' ?>
        </p>
        <?php if ($parts): ?><?= part_marks($parts, true) ?><?php endif; ?>
    </div>
    <dl class="stats">
        <div><dt>出演バンド</dt><dd><?= count($history) ?></dd></div>
        <div><dt>ライブ</dt><dd><?= $liveCount ?></dd></div>
        <div><dt>トリ</dt><dd><?= $headliners ?></dd></div>
    </dl>
</section>

<?php if ($isMe): ?>
<!-- 自分のプロフィールはアカウント設定でまとめて編集する -->
<a class="card edit-box edit-box--link" href="account.php#member-profile"><?= icon('edit') ?> プロフィールを編集（アカウント設定）</a>
<?php else: ?>
<?php $canEditAll = is_admin(); ?>
<!-- 他の人のページの編集フォーム。<details> なので普段は閉じている。
     管理者は全部の項目、それ以外のログイン中の人は「ふりがな」だけ編集できる -->
<details class="card edit-box">
    <summary><?= icon('edit') ?> <?= $canEditAll ? 'プロフィールを編集' : 'ふりがなを編集' ?></summary>
    <form method="post" action="member_edit.php" class="form-grid edit-box__form">
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= $memberId ?>">
        <?php if ($canEditAll): ?>
            <label class="field field--wide"><span>名前</span><input name="name" value="<?= h($member['name']) ?>" maxlength="50" required></label>
        <?php endif; ?>
        <label class="field"><span>ふりがな</span><input name="name_kana" value="<?= h($member['name_kana']) ?>" maxlength="50"></label>
        <?php if ($canEditAll): ?>
            <label class="field"><span>入部年度</span><input type="number" name="entry_year" min="1950" max="2100" value="<?= (int)$member['entry_year'] ?: '' ?>"></label>
            <label class="field field--wide"><span>使っている音楽アプリ（マイアルバムのリンクをこのアプリで開きます）</span>
                <select name="music_app">
                    <option value="">未選択</option>
                    <?php foreach (MUSIC_APPS as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= $member['music_app'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <div class="form-actions field--wide"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
</details>
<?php endif; ?>

<!-- ===== マイアルバム ===== -->
<!--
    最初は先頭5枚だけ見せて、「さらに表示」で全部（＋自分のページなら一番下に「アルバムを追加」）を出す。
    開け閉めは JS（assets/app.js の setupAlbumBox）が is-open クラスを付け外しする。
    <details> を使わないのは、閉じると中身が全部隠れてしまい「5枚だけ見せる」ができないため。
    JS が無いときは何も隠さない（JS が js-collapsible クラスを付けたときだけ CSS が隠す）。
    検索した直後・自分のページでまだ0枚のときは、最初から開いておく。
-->
<section id="albums" class="card album-box<?= ($albumQuery !== '' || ($isMe && !$albums)) ? ' is-open' : '' ?>" data-album-box>
    <div class="album-head">
        <!-- 見出しを押しても開け閉めできる（プロフィール編集の欄と同じ操作感）。aria-expanded: 開いているかを読み上げソフトに伝える -->
        <button type="button" class="album-box__toggle" data-album-toggle aria-expanded="false" aria-controls="albums">
            <span class="album-box__chevron" aria-hidden="true">▸</span>
            マイアルバム
            <!-- data-album-count: 追加したとき JS が数を書き換える -->
            <span class="muted small" data-album-count><?= count($albums) ?> / <?= FAVORITE_ALBUM_LIMIT ?></span>
        </button>
        <?php if ($isMe): ?>
            <!-- 並び替えは JS で動く（assets/app.js の setupAlbumSort）。JS が無いと並び替えられないので、案内は JS が出す -->
            <span class="album-sort__status muted small" data-album-sort-status role="status"></span>
        <?php endif; ?>
    </div>

    <?php if ($albums || $isMe): ?>
        <!-- data-album-sort: 本人だけドラッグで並び替えできる。data-id は保存のときに送るキー（"spotify:ID" など） -->
        <!-- 本人のページでは0枚でも空の <ul> を置いておく（ページ移動なしで追加したカードを入れる場所。空なら CSS で隠す） -->
        <ul class="albums"<?= $isMe ? ' data-album-sort data-member-id="' . $memberId . '"' : '' ?>>
            <?php foreach ($albums as $i => $a): ?>
                <li class="album" data-id="<?= h(album_key($a['source'], $a['album_id'])) ?>">
                    <span class="album__rank" aria-hidden="true"><?= $i + 1 ?></span>
                    <!-- loading="lazy": 画面に近づくまで画像を読み込まない（30枚あっても最初の表示が重くならない） -->
                    <!-- alt: 画像が出ないときや読み上げソフト用の説明文。img には必ず付けるのがマナー -->
                    <?php $k = album_key($a['source'], $a['album_id']); ?>
                    <!-- array_key_exists: キーがあれば値が null でも true（isset は null だと false になるので、ここでは使えない） -->
                    <?php $listenUrl = album_listen_url($viewerApp, $a, array_key_exists($k, $linkCache) ? $linkCache[$k] : false); ?>
                    <?php if ($isMe): ?>
                        <!-- 本人のページ: ジャケットはドラッグで並び替えるためのつかむ場所なので、リンクにしない -->
                        <img class="album__art" src="<?= h($a['artwork_url']) ?>" alt="<?= h($a['title']) ?> のジャケット" loading="lazy" width="600" height="600">
                    <?php else: ?>
                        <!-- 他の人のページ: 並び替えがないので、ジャケットも聴くページへのリンクにする -->
                        <!-- 下の文字リンクと行き先が同じなので、tabindex="-1" と aria-hidden でキーボード・読み上げでは1つ分にまとめる -->
                        <a class="album__art-link" href="<?= h($listenUrl) ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
                            <img class="album__art" src="<?= h($a['artwork_url']) ?>" alt="" loading="lazy" width="600" height="600">
                        </a>
                    <?php endif; ?>
                    <!-- 文字の部分（タイトル・アーティスト・発売年）はまとめて1つのリンク。元のサービス（Spotify / Apple Music）のページへ飛ぶ -->
                    <!-- target="_blank" は新しいタブで開く。rel="noopener": 開いた先のページから、このページを操作されないようにする（セットで付ける） -->
                    <!-- 本人のページでドラッグで並び替えられるのはジャケットの部分だけ（リンクの上で押してもドラッグは始まらない。assets/app.js） -->
                    <a class="album__meta" href="<?= h($listenUrl) ?>" target="_blank" rel="noopener" title="<?= h($listenLabel($a)) ?> で聴く">
                        <span class="album__title"><?= h($a['title']) ?></span>
                        <span class="muted small"><?= h($a['artist_name']) ?><?= $a['release_year'] ? ' · ' . (int)$a['release_year'] : '' ?></span>
                    </a>
                    <?php if ($isMe || is_admin()): ?>
                        <form method="post" action="member_album_save.php" class="album__delete" data-confirm="「<?= h($a['title']) ?>」をマイアルバムから外します。よろしいですか？">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="member_id" value="<?= $memberId ?>">
                            <input type="hidden" name="album" value="<?= h(album_key($a['source'], $a['album_id'])) ?>">
                            <button type="submit" class="btn btn--danger btn--sm" title="削除" aria-label="削除">
                                <?= icon('close', 'icon--sm') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!$albums): ?>
        <!-- data-album-empty: 1枚目を追加したとき JS が消す -->
        <p class="muted" data-album-empty><?= $isMe ? 'まだ登録されていません。下の「アルバムを追加」から探してみよう' : 'まだ登録されていません' ?></p>
    <?php endif; ?>

    <!-- 「さらに表示」/「閉じる」。文字と、出すかどうかは JS が決める（JS が無いときは全部見えているので要らない → hidden） -->
    <button type="button" class="btn btn--ghost btn--sm album-box__more" data-album-more hidden>さらに表示</button>

    <?php if ($isMe): ?>
    <!-- アルバムを追加: 自分のページだけ。マイアルバムを開いたときの一番下に出る -->
    <div class="album-search">
        <h3 class="album-search__title">アルバムを追加</h3>
        <?php if (count($albums) >= FAVORITE_ALBUM_LIMIT): ?>
            <p class="muted small">上限の<?= FAVORITE_ALBUM_LIMIT ?>枚に達しています。追加するにはどれかを削除してください。</p>
        <?php else: ?>
            <!-- 検索は GET。送信すると member.php?id=..&album_q=.. に移動し、上の PHP が検索する -->
            <!-- data-album-search: JS が動くときは移動せず、下の data-album-results の中身だけ差し替える（assets/app.js の setupAlbumSearch） -->
            <form method="get" action="member.php#albums" class="album-search__form" data-album-search>
                <input type="hidden" name="id" value="<?= $memberId ?>">
                <input class="search" type="search" name="album_q" value="<?= h($albumQuery) ?>" placeholder="アルバム名やアーティスト名で検索" maxlength="100" required>
                <button class="btn btn--primary btn--sm" type="submit">検索</button>
            </form>

            <div data-album-results>
            <?php if ($albumSearchFailed): ?>
                <p class="muted small">検索サービスに接続できませんでした。時間をおいてもう一度試してください。</p>
            <?php elseif ($albumResults === []): ?>
                <p class="muted small">「<?= h($albumQuery) ?>」に一致するアルバムが見つかりませんでした。</p>
            <?php elseif ($albumResults): ?>
                <?php if ($albumFellBack): ?>
                    <p class="muted small">Spotify に接続できなかったので、iTunes で探しました（アルバム名がローマ字のことがあります）。</p>
                <?php endif; ?>
                <ul class="albums albums--pick">
                    <?php foreach ($albumResults as $r): ?>
                        <li class="album">
                            <img class="album__art" src="<?= h($r['artwork_url']) ?>" alt="<?= h($r['title']) ?> のジャケット" loading="lazy" width="600" height="600">
                            <div class="album__meta">
                                <strong class="album__title"><?= h($r['title']) ?></strong>
                                <span class="muted small"><?= h($r['artist_name']) ?><?= $r['release_year'] ? ' · ' . (int)$r['release_year'] : '' ?></span>
                            </div>
                            <?php if (isset($registered[album_key($r['source'], $r['album_id'])])): ?>
                                <span class="pill">登録済み</span>
                            <?php else: ?>
                                <!-- 送るのはキー（どのサービスの何番か）だけ。タイトルや画像URLはサーバー側で取り直す -->
                                <!-- data-album-add: JS が動くときはページ移動せずに追加する（assets/app.js の setupAlbumAdd） -->
                                <form method="post" action="member_album_save.php" data-album-add>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="member_id" value="<?= $memberId ?>">
                                    <input type="hidden" name="album" value="<?= h(album_key($r['source'], $r['album_id'])) ?>">
                                    <button type="submit" class="btn btn--primary btn--sm">追加</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <!-- 外部サービスの画像を使うので、出典を明記しておく -->
                <p class="muted small">検索結果・ジャケット画像: <?= $albumSource === 'spotify' ? 'Spotify' : 'iTunes Search API' ?></p>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<div class="split">
    <section>
        <h2 class="section-title">出演履歴</h2>
        <ol class="history">
            <?php foreach ($historyDays as $day): ?>
                <li class="card history__item<?= $day['has_last'] ? ' is-last' : '' ?>">
                    <div class="history__when">
                        <span><?= h(fmt_year($day['year'])) ?></span>
                        <span class="muted"><?= h(fmt_date($day['date'])) ?></span>
                    </div>
                    <div class="history__what">
                        <!-- ライブ名の行。右端に1つ目のバンドの「セットリスト登録済」（楽器ラベルの真上に来る） -->
                        <div class="history__head">
                            <a href="live.php?id=<?= (int)$day['live_id'] ?>#day-<?= (int)$day['live_day_id'] ?>" class="muted small"><?= h($day['live_name']) ?> <?= h($day['label']) ?><?= $day['venue_name'] ? ' · ' . h($day['venue_name']) : '' ?></a>
                            <?= setlist_badge((int)$day['bands'][0]['setlist_count'], (int)$day['bands'][0]['song_count']) ?>
                        </div>
                        <!-- その日に出たバンドを出演順に並べる。バンド名からバンド詳細へ -->
                        <ul class="history__bands">
                            <?php foreach ($day['bands'] as $bi => $b):
                                // 2つ目以降のバンドは、ライブ名の行が使えないのでバンドの行の上に右寄せで出す
                                $badge = $bi > 0 ? setlist_badge((int)$b['setlist_count'], (int)$b['song_count']) : ''; ?>
                                <li class="history__band">
                                    <?php if ($badge !== ''): ?><div class="history__badge"><?= $badge ?></div><?php endif; ?>
                                    <!-- バンド名と、トリならその横に小さな🐦️ -->
                                    <span class="history__name">
                                        <a href="band.php?id=<?= (int)$b['band_id'] ?>"><?= h($b['band_name']) ?></a>
                                        <?php if ($b['is_last']): ?><span class="tori-badge" aria-label="トリ" title="トリ">🐦️</span><?php endif; ?>
                                    </span>
                                    <span class="history__tags">
                                        <!-- そのバンドでのパート（Vo と Gt なら「Vo/Gt」1つ） -->
                                        <?php foreach ($partsByBand[(int)$b['band_id']] ?? [] as $p): ?><?= part_badge($p) ?><?php endforeach; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
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
