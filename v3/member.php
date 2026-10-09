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
    echo '<div class="empty card"><p class="empty__title">メンバーが見つかりません</p><a class="btn" href="members">一覧へ戻る</a></div>';
    render_footer();
    exit;
}

// ---- 出演履歴（新しい順） ----
//   is_last: その日の最大 play_order と同じなら 1（トリ）。総バンド数まで登録されていない日程は 0（HEADLINER_SQL）
//   setlist_count: 登録済みの曲数（song_count と一致すれば「セットリスト登録済」）
$st = $pdo->prepare('SELECT b.band_id, b.name AS band_name, b.play_order, b.song_count,
        (SELECT COUNT(*) FROM song s WHERE s.band_id = b.band_id) AS setlist_count,
        ld.live_day_id, ld.label, ld.held_on AS date, lm.live_id, lm.fiscal_year AS year, lm.name AS live_name, v.name AS venue_name,
        (last.max_order IS NOT NULL) AS is_last
    FROM (' . MEMBERSHIP_SQL . ') bm
    JOIN band b ON b.band_id = bm.band_id
    LEFT JOIN (' . HEADLINER_SQL . ') last ON last.live_day_id = b.live_day_id AND last.max_order = b.play_order
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
$myRows = $st->fetchAll(); // 出演履歴のマーク（Gt/Cho にまとめる）と「Gt × 3」（Gt と Cho を別々に数える）の2通りに使う
$partsByBand = []; // [band_id] = [パート, ...]（出演履歴のマークに使う）
foreach (lineup_parts_by_band($myRows) as $p) {
    $partsByBand[$p['band_id']][] = $p;
}
// 1つのバンドで楽器が2つ以上なら、演奏した曲が多い順に（同じ曲数なら楽器の並び順のまま。usort は順番を保つ）
//   「Vo/Gt」と「Gt」の両方があるときは、Gt は「Vo なしで Gt だけ」の曲を数える（Vo/Gt の曲を二重に数えない）
foreach ($partsByBand as $bandId => &$bandParts) {
    if (count($bandParts) < 2) {
        continue;
    }
    $count = [];
    foreach ($bandParts as $i => $p) {
        $without = null;
        if (count($p['segments']) === 1) {
            foreach ($bandParts as $other) {
                if (count($other['segments']) > 1 && in_array($p['short'], part_play_shorts($other), true)) {
                    $without = array_values(array_diff(part_play_shorts($other), [$p['short']]))[0] ?? null;
                }
            }
        }
        $count[$i] = songs_played((int)$bandId, $memberId, part_play_shorts($p), $without);
    }
    $order = array_keys($bandParts);
    usort($order, static fn($a, $b) => $count[$b] <=> $count[$a]);
    $bandParts = array_map(static fn($i) => $bandParts[$i], $order);
}
unset($bandParts);
// 「Vo/Gt × 3」「Gt × 2」…。Gt/Cho は Gt と Cho に1回ずつ数える（'split'）
$parts = sort_tally_by_count(tally_parts(lineup_parts_by_band($myRows, true, 'split')));

// ---- よく組むメンバー（自己結合） ----
$st = $pdo->prepare('SELECT m.member_id, m.name, COUNT(DISTINCT other.band_id) AS n
    FROM (' . MEMBERSHIP_SQL . ') mine
    JOIN (' . MEMBERSHIP_SQL . ') other ON other.band_id = mine.band_id AND other.member_id <> mine.member_id
    JOIN member m ON m.member_id = other.member_id
    WHERE mine.member_id = ?
    GROUP BY m.member_id
    ORDER BY n DESC, m.name');
// ↑ LIMIT は付けずに全員取る。最初は上位5人だけ見せて、残りは「すべて表示」で出す（JS: setupPartnerBox）
$st->execute([$memberId]);
$partners = $st->fetchAll();

// ---- よく演奏するアーティスト（上位10位） ----
//   数え方はアーティスト一覧と同じ（ARTIST_PLAYS_SQL: オムニバスは曲に付いたアーティストを1バンド1回）。
//   そのアーティストを演奏したバンドのうち、この人が入っていたバンドの数。最初は上位3位だけ見せて、残りは「さらに表示」（JS: setupPartnerBox）
$st = $pdo->prepare('SELECT a.artist_id, a.name, COUNT(DISTINCT p.band_id) AS n
    FROM (' . ARTIST_PLAYS_SQL . ') p
    JOIN (' . MEMBERSHIP_SQL . ') mine ON mine.band_id = p.band_id
    JOIN artist a ON a.artist_id = p.artist_id
    WHERE mine.member_id = ?
    GROUP BY a.artist_id
    ORDER BY n DESC, a.name
    LIMIT 10');
$st->execute([$memberId]);
$topArtists = $st->fetchAll();

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
// さらに年度ごとにまとめる（年度の見出し・年度スロットで使う）。$history が年度の新しい順なので、この順のまま入る
$historyByYear = []; // [年度] = [日程, ...]
foreach ($historyDays as $day) {
    $historyByYear[(int)$day['year']][] = $day;
}

// ---- よく組むメンバーと「一緒に出たバンド」（名前の ▸ を開くと出す） ----
//   年度・ライブ名・日目は出演履歴（$history）にもう入っているので、ここで読むのは
//   「自分のバンドに、ほかに誰が何の楽器でいたか」だけ。SQL は1本で、相手ごとに投げない（21人で21回 = N+1 になる）。
//   並びは楽器の順 → 名前（lineup_parts_by_band が Vo と Gt を「Vo/Gt」にまとめるときにこの順を使う）
$st = $pdo->prepare('SELECT bm.band_id, bm.member_id, m.name, i.short_name, i.name AS instrument_name, i.sort_order
    FROM band_member bm
    JOIN member m ON m.member_id = bm.member_id
    JOIN instrument i ON i.instrument_id = bm.instrument_id
    WHERE bm.band_id IN (SELECT band_id FROM band_member WHERE member_id = ?) AND bm.member_id <> ?
    ORDER BY i.sort_order, m.name');
$st->execute([$memberId, $memberId]);
$partnerRows = $st->fetchAll();
$partnerParts = [];   // [相手の member_id][band_id] = [パート, ...]（開いた中身の、ライブ名の左の楽器ラベル。Gt/Cho にまとめる）
$partnerTally = [];   // [相手の member_id] = tally_parts の結果（名前の行の右の「Dr × 3」）
foreach (lineup_parts_by_band($partnerRows) as $p) {
    $partnerParts[(int)$p['member_id']][(int)$p['band_id']][] = $p;
}
// 「Dr × 3」は Gt/Cho を Gt として数える（Cho はコーラスだけで出たときだけ）
$partnerCount = [];
foreach (lineup_parts_by_band($partnerRows, true, 'drop') as $p) {
    $partnerCount[(int)$p['member_id']][] = $p;
}
foreach ($partnerCount as $pid => $countParts) {
    $partnerTally[$pid] = sort_tally_by_count(tally_parts($countParts));
}
// $history の並び（新しい順・出演順）のまま、相手ごと・年度ごとに振り分ける
$sharedBands = []; // [相手の member_id][年度] = [出演履歴の行, ...]
foreach ($history as $hi) {
    foreach ($partnerParts as $pid => $byBand) {
        if (isset($byBand[(int)$hi['band_id']])) {
            $sharedBands[$pid][$hi['year']][] = $hi;
        }
    }
}

$headliners = count(array_filter($history, static fn($h) => (int)$h['is_last'] === 1));
$liveCount = count(array_unique(array_column($history, 'live_id')));
$isMe = $memberId === $user['member_id'];

// ---- 見ている人（ログイン中の人）も一緒に出たバンド ----
//   出演履歴で、そのバンドがある日程のカードを赤枠にする（live.php の「自分が出たバンド」と同じ見た目）。
//   自分のページでは全部のカードが赤枠になって意味が無いので、他の人のページを見ているときだけ
$viewerBandIds = []; // [band_id] = true
if (!$isMe && $user['member_id']) {
    $st = $pdo->prepare('SELECT DISTINCT band_id FROM band_member WHERE member_id = ?');
    $st->execute([$user['member_id']]);
    $viewerBandIds = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

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
<nav class="crumbs"><a href="members">メンバー</a><span>/</span><?= h($member['name']) ?></nav>
<section class="hero">
    <div>
        <p class="eyebrow"><?= $isMe ? 'My Page' : 'Member' ?></p>
        <h1 class="display"><?= h($member['name']) ?></h1>
        <p class="muted small">
            <?= h($member['name_kana'] ?? '') ?>
            <?php if ((int)$member['entry_year'] > 0): ?> · <a class="meta-link" href="members?who=grade&amp;entry=<?= (int)$member['entry_year'] ?>"><?= (int)$member['entry_year'] ?>年入学</a><?php endif; ?>
            <?php if ($member['faculty'] !== null): ?> · <a class="meta-link" href="search?<?= h(http_build_query(['faculty' => $member['faculty']])) ?>"><?= h($member['faculty']) ?></a><?php endif; ?>
            <?php $roles = member_roles($pdo, $memberId); // [role_id => 名前] ?>
            <?php if ($roles): ?> ·
                <?php $i = 0; foreach ($roles as $rid => $rname): ?><?= $i++ ? '・' : '' ?><a class="meta-link" href="search?role=<?= (int)$rid ?>"><?= h($rname) ?></a><?php endforeach; ?>
            <?php endif; ?>
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
<a class="card edit-box edit-box--link" href="account#member-profile"><?= icon('edit') ?> プロフィールを編集（アカウント設定）</a>
<?php elseif (is_admin()): ?>
<!-- 他の人のページの編集フォーム（管理者だけ）。<details> なので普段は閉じている -->
<details class="card edit-box">
    <summary><?= icon('edit') ?> プロフィールを編集</summary>
    <form method="post" action="member_edit" class="form-grid edit-box__form">
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= $memberId ?>">
        <label class="field field--wide"><span>名前</span><input name="name" value="<?= h($member['name']) ?>" maxlength="50" required></label>
        <label class="field"><span>ふりがな</span><input name="name_kana" value="<?= h($member['name_kana']) ?>" maxlength="50"></label>
        <label class="field"><span>入部年度</span><input type="number" name="entry_year" min="1950" max="2100" value="<?= (int)$member['entry_year'] ?: '' ?>"></label>
        <?= profile_faculty_role_fields($pdo, $member) ?>
        <label class="field field--wide"><span>使っている音楽アプリ（マイアルバムのリンクをこのアプリで開きます）</span>
            <select name="music_app">
                <option value="">未選択</option>
                <?php foreach (MUSIC_APPS as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $member['music_app'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions field--wide"><button class="btn btn--primary btn--sm" type="submit">保存</button></div>
    </form>
</details>
<?php endif; ?>

<!-- ===== マイアルバム ===== -->
<!--
    最初は先頭5枚（スマホは4枚）だけ見せて、「さらに表示」で全部（＋自分のページなら一番下に「アルバムを追加」）を出す。
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
                        <span class="muted small"><?= h($a['artist_name']) ?><?php if ($a['release_year']): ?><span class="album__year"> · <?= (int)$a['release_year'] ?></span><?php endif; ?></span>
                    </a>
                    <?php if ($isMe || is_admin()): ?>
                        <form method="post" action="member_album_save" class="album__delete" data-confirm="「<?= h($a['title']) ?>」をマイアルバムから外します。よろしいですか？">
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
            <form method="get" action="member#albums" class="album-search__form" data-album-search>
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
                                <form method="post" action="member_album_save" data-album-add>
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
        <?php if ($history): ?>
        <div class="toolbar history-toolbar">
            <!-- 年度スロット: ライブ一覧（index.php）と同じ部品。data-year-slot の値 = 隠す対象（年度ごとの section） -->
            <div class="year-slot" data-year-slot="section[data-history-year]" tabindex="0" role="spinbutton" aria-label="年度で絞り込み" title="上下にドラッグで年度を切り替え">
                <div class="year-slot__reel">
                    <div class="year-slot__item" data-value="">すべて</div>
                    <?php foreach (array_keys($historyByYear) as $year): ?>
                        <div class="year-slot__item" data-value="<?= (int)$year ?>"><?= $year > 0 ? (int)$year . '<small>年度</small>' : '未設定' ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- 検索: .history__item の data-text で絞る（年度スロットは section、検索は li を隠すので、ぶつからない） -->
            <input type="search" class="search" placeholder="ライブ名・バンド名・会場" data-filter=".history__item" aria-label="出演履歴を絞り込み">
        </div>
        <?php endif; ?>
        <!-- .sorted-list: 並び替えボタンを、一番上の年度の見出しの右に重ねて置く（CSS の position: absolute）。
             ボタンを #history-list の中に入れると、並び替えのときに一緒に動いてしまうので外に置く -->
        <div class="sorted-list sorted-list--history">
        <?php if ($history): ?>
            <!-- 並び替え: 押すたびに新しい順 ⇔ 古い順（JS で並びを逆にするだけ。assets/app.js の setupSortToggle） -->
            <button type="button" class="btn btn--ghost btn--sm sorted-list__btn" data-sort-toggle="#history-list" aria-pressed="false">新しい順 ↓</button>
        <?php endif; ?>
        <div id="history-list">
        <?php foreach ($historyByYear as $year => $days): ?>
        <section class="history-year" data-history-year="<?= (int)$year ?>">
        <!-- 年度は、年度が変わるところに見出しで1回だけ出す -->
        <h3 class="history__year"><?= $year > 0 ? (int)$year . '<small>年度</small>' : '年度未設定' ?></h3>
        <ol class="history">
            <?php foreach ($days as $day): ?>
                <?php $dayMine = (bool)array_filter($day['bands'], static fn($b) => isset($viewerBandIds[(int)$b['band_id']])); ?>
                <!-- is-last: その日にトリをやった（左に青い線）/ is-mine: 見ている人も一緒に出た（赤枠）。live.php のバンドカードと同じ -->
                <li class="card history__item<?= $day['has_last'] ? ' is-last' : '' ?><?= $dayMine ? ' is-mine' : '' ?>"
                    data-text="<?= h($day['live_name'] . ' ' . $day['label'] . ' ' . ($day['venue_name'] ?? '') . ' ' . implode(' ', array_column($day['bands'], 'band_name'))) ?>">
                    <div class="history__when">
                        <!-- カードには年度ではなく、実際に開催した年（2025年度の3月なら 2026年） -->
                        <span><?= $day['date'] ? date('Y', strtotime($day['date'])) . '年' : '' ?></span>
                        <span class="muted"><?= h(fmt_date($day['date'])) ?></span>
                    </div>
                    <div class="history__what">
                        <a href="live?id=<?= (int)$day['live_id'] ?>#day-<?= (int)$day['live_day_id'] ?>" class="muted small"><?= h($day['live_name']) ?> <?= h($day['label']) ?><?= $day['venue_name'] ? ' · ' . h($day['venue_name']) : '' ?></a>
                        <!-- その日に出たバンドを出演順に並べる。バンド名からバンド詳細へ -->
                        <ul class="history__bands">
                            <?php foreach ($day['bands'] as $b): ?>
                                <li class="history__band">
                                    <!-- バンド名と、トリならその横に小さな🐦️ -->
                                    <span class="history__name">
                                        <a href="band?id=<?= (int)$b['band_id'] ?>"><?= h($b['band_name']) ?></a>
                                        <?php if ($b['is_last']): ?><span class="tori-badge" aria-label="トリ" title="トリ">🐦️</span><?php endif; ?>
                                    </span>
                                    <span class="history__tags">
                                        <!-- セットリスト登録済なら楽器ラベルの左に ✓、その後にそのバンドでのパート（Vo と Gt なら「Vo/Gt」1つ） -->
                                        <?= setlist_badge((int)$b['setlist_count'], (int)$b['song_count']) ?>
                                        <?php foreach ($partsByBand[(int)$b['band_id']] ?? [] as $p): ?><?= part_badge($p) ?><?php endforeach; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        </section>
        <?php endforeach; ?>
        </div>
        </div>
        <?php if (!$history): ?><p class="muted">出演データがありません</p><?php endif; ?>
    </section>
    <aside>
        <!-- よく組むメンバー: マイアルバムと同じく、最初は上位5人だけ。見出しか「すべて表示」で全員を出す（JS: setupPartnerBox） -->
        <section id="partners" class="card album-box partner-box" data-partner-box>
            <div class="album-head">
                <button type="button" class="album-box__toggle" data-partner-toggle aria-expanded="false" aria-controls="partners">
                    <span class="album-box__chevron" aria-hidden="true">▸</span>
                    よく組むメンバー
                    <span class="muted small"><?= count($partners) ?>人</span>
                </button>
            </div>
            <?php if (!$partners): ?><p class="muted">まだいません</p><?php endif; ?>
            <!-- 「1 [▸ 3回] 名前 … Dr × 3」（順位の数字は CSS）: ▸ と回数を押すと一緒に出たバンドを開け閉め、名前は今まで通り個人ページへのリンク
                 （<summary> の中のリンクを押したときは、開け閉めせずにリンク先へ飛ぶ） -->
            <?php $partnerRanks = tie_ranks($partners, static fn($p) => (int)$p['n']); // 同じ回数は同じ順位 ?>
            <ol class="ranking">
                <?php foreach ($partners as $k => $p): ?>
                    <li data-rank="<?= $partnerRanks[$k] ?>">
                        <details class="partner">
                            <summary>
                                <span class="pill"><span class="partner__chevron" aria-hidden="true">▸</span><?= (int)$p['n'] ?>回</span>
                                <a href="member?id=<?= (int)$p['member_id'] ?>"><?= h($p['name']) ?></a>
                                <!-- 一緒に組んだバンドで、その人が何を何回やったか -->
                                <?= part_marks($partnerTally[(int)$p['member_id']] ?? [], true, 'partbar--partner') ?>
                            </summary>
                            <dl class="partner__bands">
                                <?php foreach ($sharedBands[(int)$p['member_id']] ?? [] as $year => $bands): ?>
                                    <dt><?= h(fmt_year($year)) ?></dt>
                                    <?php foreach ($bands as $b): ?>
                                        <dd>
                                            <a href="band?id=<?= (int)$b['band_id'] ?>"><?= h($b['band_name']) ?></a>
                                            <span class="partner__live">
                                                <a class="muted small" href="live?id=<?= (int)$b['live_id'] ?>#day-<?= (int)$b['live_day_id'] ?>"><?= h($b['live_name']) ?> <?= h($b['label']) ?></a>
                                                <!-- そのバンドでその人がやった楽器（ライブ名の右） -->
                                                <?php foreach ($partnerParts[(int)$p['member_id']][(int)$b['band_id']] ?? [] as $pp): ?><?= part_badge($pp) ?><?php endforeach; ?>
                                            </span>
                                        </dd>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </dl>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ol>
            <button type="button" class="btn btn--ghost btn--sm album-box__more" data-partner-more hidden>すべて表示</button>
        </section>

        <!-- よく演奏するアーティスト: 最初は上位3位だけ。見出しか「さらに表示」で10位まで出す（よく組むメンバーと同じ JS: setupPartnerBox） -->
        <section id="top-artists" class="card album-box partner-box top-artists" data-partner-box data-shown="3" data-more-label="さらに表示">
            <div class="album-head">
                <button type="button" class="album-box__toggle" data-partner-toggle aria-expanded="false" aria-controls="top-artists">
                    <span class="album-box__chevron" aria-hidden="true">▸</span>
                    よく演奏するアーティスト
                </button>
            </div>
            <?php if (!$topArtists): ?><p class="muted">まだいません</p><?php endif; ?>
            <?php $artistRanks = tie_ranks($topArtists, static fn($a) => (int)$a['n']); // 同じ回数は同じ順位 ?>
            <ol class="ranking">
                <?php foreach ($topArtists as $k => $a): ?>
                    <li data-rank="<?= $artistRanks[$k] ?>">
                        <a href="artist?id=<?= (int)$a['artist_id'] ?>"><?= h($a['name']) ?></a>
                        <span class="pill"><?= (int)$a['n'] ?>回</span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <button type="button" class="btn btn--ghost btn--sm album-box__more" data-partner-more hidden>さらに表示</button>
        </section>
    </aside>
</div>
<?php render_footer();
