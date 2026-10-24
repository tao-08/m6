<?php
/**
 * =====================================================================
 *  tab.php — 流れる TAB 譜（弾いてみた動画のように、TAB が音に合わせて流れる練習ページ）
 * =====================================================================
 *  2つのページを1ファイルに入れている（どちらもログインしていれば誰でも見られる・編集できる）:
 *    tab.php?song=曲ID / ?key=曲キー … その曲の TAB 一覧（パートごと）・削除した TAB の復元・新規作成フォーム
 *                                       曲は Spotify / iTunes に紐付けていなくても OK（曲キーの形は lib/tabs.php）
 *    tab.php?id=12              … 練習 + 編集
 *
 *  役割分担:
 *    このファイル（PHP）  … DB から読んで画面の枠を出す。新規作成の POST もここ
 *    api_tab_save.php     … 保存・削除・履歴・復元（JS が fetch() で呼ぶ）
 *    lib/tabs.php         … プリセット・データの検証
 *    assets/tab.js        … 再生・時計・canvas の描画・グリッド編集のすべて（ブラウザの中）
 *    assets/tab.css       … このページだけの見た目
 *
 *  音源はサーバーに置かない:
 *    ・YouTube の埋め込み … 動画の URL を保存するだけ
 *    ・手元の音声ファイル … ブラウザで開くだけ（URL.createObjectURL）。アップロードしないので著作権の心配が無い
 *    ・音源なし           … BPM だけで流れる
 *  歌詞は保存も表示もしない。小節に付けられるのは短いセクション名だけ。
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/albums.php';     // album_key / album_parse_key
require_once __DIR__ . '/lib/repository.php'; // fmt_datetime / band_setlist_visibility
require_once __DIR__ . '/lib/tabs.php';
require_once __DIR__ . '/lib/youtube.php';    // youtube_watch_url
require_once __DIR__ . '/lib/attach_image.php'; // 画像の添付（kind = song_tab / song_meta）
require_once __DIR__ . '/lib/claude.php';       // claude_enabled()（AI のボタンを出すか）
$user = require_login();
if (!tab_allowed()) {
    error_page(403, '管理人のみ利用できます'); // 今は管理人だけ（lib/tabs.php の tab_allowed）
}
$pdo = db();

// =====================================================================
//  A. 曲ごとの一覧 + 新規作成（?song=曲ID または ?key=曲キー）
// =====================================================================
//  ?song= … セットリストの曲から来る入口。紐付けの有無に関係なく、曲から「曲キー」を決める
//  ?key=  … 曲キーそのもの（プレイヤーからの戻り先）。曲キーの形は lib/tabs.php の tab_song_key()
if (isset($_GET['song']) || isset($_GET['key'])) {
    $song = null; // ['key', 'title', 'artist', 'source', 'track_id', 'art']
    if (isset($_GET['song'])) {
        $st = $pdo->prepare('SELECT s.title, s.is_se, s.band_id, s.track_source, s.track_id, COALESCE(s.artist_id, b.artist_id) AS artist_id,
                a.name AS artist_name, t.title AS track_title, t.artist_name AS track_artist, t.artwork_url
            FROM song s
            JOIN band b ON b.band_id = s.band_id
            LEFT JOIN artist a ON a.artist_id = COALESCE(s.artist_id, b.artist_id)
            LEFT JOIN track t ON t.source = s.track_source AND t.track_id = s.track_id
            WHERE s.song_id = ?');
        $st->execute([(int)$_GET['song']]);
        $r = $st->fetch();
        // SE は TAB の対象外。出演まで非公開のセットリストは、曲名が分かってしまうので入れない
        if ($r && !$r['is_se'] && !band_setlist_visibility($pdo, (int)$r['band_id'])['hidden']) {
            $linked = $r['track_source'] !== null;
            $title = $linked ? $r['track_title'] : $r['title'];
            $artist = $linked ? $r['track_artist'] : $r['artist_name'];
            $song = [
                'key'      => tab_song_key($r['track_source'], $r['track_id'], $r['artist_id'] === null ? null : (int)$r['artist_id'],
                    (int)$r['band_id'], $r['title'], $r['artist_name']),
                'title'    => mb_substr((string)$title, 0, 100),
                'artist'   => $artist,
                'source'   => $r['track_source'],
                'track_id' => $r['track_id'],
                'art'      => $r['artwork_url'],
            ];
        }
        $listUrl = 'tab?song=' . (int)$_GET['song'];
    } else {
        $key = is_string($_GET['key']) ? $_GET['key'] : '';
        if (tab_key_valid($key)) {
            $song = ['key' => $key, 'title' => null, 'artist' => null, 'source' => null, 'track_id' => null, 'art' => null];
            // Spotify / iTunes の曲なら track テーブルから
            if (($parsed = album_parse_key($key)) !== null) {
                $st = $pdo->prepare('SELECT title, artist_name, artwork_url FROM track WHERE source = ? AND track_id = ?');
                $st->execute($parsed);
                if ($t = $st->fetch()) {
                    [$song['source'], $song['track_id']] = $parsed;
                    [$song['title'], $song['artist'], $song['art']] = [$t['title'], $t['artist_name'], $t['artwork_url']];
                }
            }
            // 紐付けなし（または曲が消えた）: 今ある TAB か、削除された TAB の履歴に残っている曲名を使う
            if ($song['title'] === null) {
                $st = $pdo->prepare('SELECT title, artist_name FROM song_tab WHERE song_key = ? LIMIT 1');
                $st->execute([$key]);
                $t = $st->fetch();
                if (!$t) {
                    $st = $pdo->prepare('SELECT snapshot FROM song_tab_revision WHERE song_key = ? ORDER BY rev_id DESC LIMIT 1');
                    $st->execute([$key]);
                    $snap = json_decode((string)$st->fetchColumn(), true);
                    $t = is_array($snap) && isset($snap['title']) ? ['title' => $snap['title'], 'artist_name' => $snap['artist_name'] ?? null] : null;
                }
                if (!$t) {
                    // まだ TAB が無い手動登録の曲（曲データベース songs.php の song_catalog）
                    $st = $pdo->prepare('SELECT title, artist_name FROM song_catalog WHERE song_key = ?');
                    $st->execute([$key]);
                    $t = $st->fetch() ?: null;
                }
                if ($t) {
                    [$song['title'], $song['artist']] = [mb_substr((string)$t['title'], 0, 100), $t['artist_name']];
                } else {
                    $song = null;
                }
            }
        }
        $listUrl = 'tab?key=' . rawurlencode($key);
    }
    if ($song === null) {
        error_page(404, '曲が見つかりません');
    }

    // ---- 新規作成（POST）。曲名などは上で DB から決めた値を使い、ブラウザから来た値は信用しない ----
    if (is_post()) {
        verify_csrf();
        $part = trim((string)($_POST['part'] ?? ''));
        $preset = TAB_PRESETS[$_POST['preset'] ?? ''] ?? null; // 一覧にあるキーだけ（無ければ null）
        if ($part === '' || mb_strlen($part) > 20 || preg_match('/[\x00-\x1F\x7F]/u', $part)) {
            flash('パート名は1〜20文字で入力してください', 'error');
        } elseif ($preset === null) {
            flash('楽器を選択してください', 'error');
        } elseif ((int)($_POST['first_bar'] ?? 1) < 1 || (int)($_POST['first_bar'] ?? 1) > 999) {
            flash('開始小節は1〜999で入力してください', 'error');
        } else {
            // 部分 TAB（ギターソロだけ等）: 小節の番号が first_bar から始まる。部分でなければ 1 から
            $partial = !empty($_POST['partial']) ? 1 : 0;
            $firstBar = $partial ? (int)($_POST['first_bar'] ?? 1) : 1;
            try {
                $data = json_encode(tab_empty_data(8), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO song_tab (song_key, track_source, track_id, title, artist_name, part, instrument_id, kind, first_bar, is_partial, tuning, data, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$song['key'], $song['source'], $song['track_id'], $song['title'], $song['artist'], $part, $preset['instrument'],
                        $preset['kind'] ?? 'string', $firstBar, $partial, $preset['tuning'], $data, $user['user_id'], $user['user_id']]);
                $newId = (int)$pdo->lastInsertId();
                tab_record_revision($pdo, $newId, 'create', (int)$user['user_id']); // 作成も履歴に残す（同じトランザクション）
                $pdo->commit();
                redirect('tab?id=' . $newId . '&edit=1');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // 1062 = UNIQUE 違反（同じ曲に同じパート名がもうある）
                flash(($e->errorInfo[1] ?? 0) === 1062 ? '同じパート名がすでにあります' : (config('debug') ? $e->getMessage() : '作成できませんでした'), 'error');
            }
        }
        redirect($listUrl); // エラーはフラッシュで知らせて、一覧に戻る（再読み込みで二重送信されない）
    }

    // ---- 既存のパート（楽器の並び順 → パート名）----
    $st = $pdo->prepare('SELECT t.tab_id, t.part, t.tuning, t.kind, t.first_bar, t.is_partial, JSON_LENGTH(t.data, "$.bars") AS bar_count, t.updated_at, i.short_name
        FROM song_tab t JOIN instrument i ON i.instrument_id = t.instrument_id
        WHERE t.song_key = ?
        ORDER BY i.sort_order, t.part');
    $st->execute([$song['key']]);
    $parts = $st->fetchAll();

    // ---- 削除された TAB（最後の履歴が「削除」で、今は song_tab に行が無いもの）。復元できる ----
    $st = $pdo->prepare('SELECT r.rev_id, r.part, r.created_at, u.name
        FROM song_tab_revision r
        LEFT JOIN user_account u ON u.user_id = r.user_id
        WHERE r.song_key = ? AND r.action = \'delete\'
          AND r.rev_id = (SELECT MAX(r2.rev_id) FROM song_tab_revision r2 WHERE r2.tab_id = r.tab_id)
          AND NOT EXISTS (SELECT 1 FROM song_tab t WHERE t.tab_id = r.tab_id)
        ORDER BY r.rev_id DESC');
    $st->execute([$song['key']]);
    $deleted = $st->fetchAll();

    // 曲のメモと画像（song_meta。全パート共通。最初に保存したときに行ができる）
    $st = $pdo->prepare('SELECT meta_id, memo FROM song_meta WHERE song_key = ?');
    $st->execute([$song['key']]);
    $meta = $st->fetch() ?: ['meta_id' => 0, 'memo' => null];
    $songImageRows = attach_images($pdo, 'song_meta', [(int)$meta['meta_id']])[(int)$meta['meta_id']] ?? [];
    $songImageIds = array_map(static fn($im) => (int)$im['image_id'], $songImageRows);
    $songData = json_encode(['key' => $song['key'], 'images' => $songImageIds, 'pdfs' => tab_pdf_ids($songImageRows), 'files' => tab_file_ids($songImageRows)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

    render_header($song['title'] . ' TAB', 'lives');
    ?>
<link rel="stylesheet" href="<?= asset_url('assets/tab.css') ?>">
<nav class="crumbs"><a href="./">ライブ</a></nav>
<h1 class="display display--sm">TAB譜編集・練習</h1>
<section class="card tab-song">
    <?php if ($song['art']): ?>
        <img class="tab-song__art" src="<?= h($song['art']) ?>" alt="" width="72" height="72">
    <?php else: ?>
        <span class="tab-song__art tab-song__art--none"><?= icon('music_note') ?></span>
    <?php endif; ?>
    <div class="tab-song__text">
        <h2 class="tab-song__title"><?= h($song['title']) ?></h2> <?php // 見出し（h1）は上の「TAB譜編集・練習」。曲名はその下の見出し ?>
        <?php if ($song['artist']): ?><p class="muted"><?= h($song['artist']) ?></p><?php endif; ?>
    </div>
</section>

<!-- 曲のメモ（全パート共通）と画像。編集はこの画面。各パートのプレイヤーでは読むだけ -->
<section class="card tab-notes-edit" data-song-memo>
    <script type="application/json" id="song-data"><?= $songData ?></script>
    <?= attach_modal_html() ?>
    <label class="field"><?= icon('sticky_note_2') ?>
        <textarea rows="3" maxlength="<?= TAB_MEMO_MAX ?>" data-song-memo-text aria-label="曲のメモ"><?= h($meta['memo'] ?? '') ?></textarea>
    </label>
    <ul class="live-thumbs tab-imgs" data-song-images></ul>
    <div class="tab-notes-edit__actions">
        <label class="btn btn--ghost btn--sm" data-img-add title="ファイルを追加" aria-label="ファイルを追加"><?= icon('add_photo_alternate') ?>
            <input type="file" multiple hidden data-img-input></label>
        <button type="button" class="btn btn--primary btn--sm" data-song-memo-save><?= icon('save') ?> 保存</button>
    </div>
</section>

<section class="card tab-parts">
    <?php if ($parts): ?>
        <ul class="tab-parts__list">
            <?php foreach ($parts as $p): ?>
                <li><a class="tab-parts__item" href="tab?id=<?= (int)$p['tab_id'] ?>">
                    <span class="tab-parts__inst"><?= h($p['short_name']) ?></span>
                    <span class="tab-parts__name"><?= h($p['part']) ?></span>
                    <?php if ($p['is_partial']): ?><span class="tag" title="部分">部分 <?= (int)$p['first_bar'] ?>〜<?= (int)$p['first_bar'] + max(0, (int)$p['bar_count'] - 1) ?></span><?php endif; ?>
                    <?php if ($p['kind'] !== 'drum'): ?><span class="muted small"><?= (int)tab_string_count($p['tuning']) ?>弦</span><?php endif; ?>
                    <span class="muted small tab-parts__date"><?= h(fmt_datetime($p['updated_at'])) ?></span>
                    <?= icon('chevron_right') ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">まだ TAB がありません</p>
    <?php endif; ?>
</section>

<?php if ($deleted): ?>
<section class="card tab-parts tab-parts--deleted">
    <h2 class="tab-parts__heading"><?= icon('delete') ?> 削除</h2>
    <ul class="tab-parts__list">
        <?php foreach ($deleted as $d): ?>
            <li class="tab-parts__item">
                <span class="tab-parts__name"><?= h($d['part']) ?></span>
                <span class="muted small tab-parts__date"><?= h(fmt_datetime($d['created_at'])) ?> · <?= h($d['name'] ?? '（削除済み）') ?></span>
                <button type="button" class="btn btn--sm" data-restore="<?= (int)$d['rev_id'] ?>"><?= icon('restore') ?> 復元</button>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <form method="post" class="tab-new">
        <?= csrf_field() ?>
        <label class="field">パート <input type="text" name="part" maxlength="20" required placeholder="Gt1"></label>
        <label class="field">楽器
            <select name="preset">
                <?php foreach (TAB_PRESETS as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p['label']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="field tab-new__partial"><span class="check"><input type="checkbox" name="partial" value="1"> 部分</span>
            <input type="number" name="first_bar" min="1" max="999" value="1" aria-label="開始小節" title="開始小節"></label>
        <button type="submit" class="btn btn--primary"><?= icon('add') ?> 作成</button>
    </form>
</section>
<script src="<?= asset_url('assets/tab.js') ?>" defer></script><!-- 曲のメモの保存・画像・削除した TAB の復元 -->
<?php
    render_footer();
    exit;
}

// =====================================================================
//  B. 練習 + 編集（?id=）
// =====================================================================
$tabId = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, k.artwork_url, i.short_name
    FROM song_tab t
    LEFT JOIN track k ON k.source = t.track_source AND k.track_id = t.track_id
    JOIN instrument i ON i.instrument_id = t.instrument_id
    WHERE t.tab_id = ?');
$st->execute([$tabId]);
$tab = $st->fetch();
if (!$tab) {
    error_page(404, 'TAB が見つかりません');
}
$listUrl = 'tab?key=' . rawurlencode($tab['song_key']); // 曲の一覧へ戻るリンク
$data = json_decode($tab['data'], true);
if (!is_array($data) || !isset($data['bars'])) {
    $data = tab_empty_data(8); // DB を直接いじられて壊れていても、画面は開けるようにする
}

// 練習用マーカー（song_tab_practice）は、見ている本人の分だけ読む。壊れていたら空から始める
$st = $pdo->prepare('SELECT data FROM song_tab_practice WHERE tab_id = ? AND user_id = ?');
$st->execute([$tab['tab_id'], $user['user_id']]);
$practice = tab_sanitize_practice(json_decode((string)$st->fetchColumn(), true)) ?? ['m' => [], 'ab' => null, 'bpm' => null, 'metro' => null];

// 曲のメモ（読むだけ）と画像。TAB のメモ・画像は JS が扱う
$st = $pdo->prepare('SELECT meta_id, memo FROM song_meta WHERE song_key = ?');
$st->execute([$tab['song_key']]);
$meta = $st->fetch() ?: ['meta_id' => 0, 'memo' => null];
$songImages = attach_images($pdo, 'song_meta', [(int)$meta['meta_id']])[(int)$meta['meta_id']] ?? [];
$tabImageRows = attach_images($pdo, 'song_tab', [(int)$tab['tab_id']])[(int)$tab['tab_id']] ?? [];
$tabImageIds = array_map(static fn($im) => (int)$im['image_id'], $tabImageRows);


// JS に渡すデータ。setting_sheet.php と同じ <script type="application/json"> で渡す
//   JSON_HEX_TAG などは、セクション名に </script> と書かれても <script> が途中で閉じないようにするため（XSS 対策）
$tabData = [
    'tab_id'         => (int)$tab['tab_id'],
    'song_key'       => $tab['song_key'],
    'part'           => $tab['part'],
    'tuning'         => preg_split('/ +/', trim($tab['tuning'])) ?: [],
    'bpm'            => (float)$tab['bpm'],
    'beats_per_bar'  => (int)$tab['beats_per_bar'],
    'steps_per_beat' => (int)$tab['steps_per_beat'],
    'offset_ms'      => (int)$tab['offset_ms'],
    'youtube_id'     => $tab['youtube_id'],
    'youtube_url'    => $tab['youtube_id'] !== null ? youtube_watch_url($tab['youtube_id']) : '',
    'data'           => $data,
    'updated_at'     => $tab['updated_at'],
    'memo'           => $tab['memo'] ?? '',
    'images'         => $tabImageIds,
    'pdfs'           => tab_pdf_ids($tabImageRows),
    'files'          => tab_file_ids($tabImageRows),
    'ai'             => claude_enabled() && $tab['kind'] !== 'drum', // AI で TAB を読み取るボタンを出すか（lib/tab_ai.php）
    'chord_re'       => substr(TAB_CHORD_RE, 1, -2), // コード名の形（JS の new RegExp に渡す。PHP の検査と同じ式）
    'kind'           => $tab['kind'],            // string / drum
    'first_bar'      => (int)$tab['first_bar'],   // 小節番号の最初（部分 TAB）
    'is_partial'     => (bool)$tab['is_partial'],
    'capo'           => (int)$tab['capo'], // フレットはカポを基準に書く（カポ2なら、カポの位置が0フレット）
    'links'          => tab_sanitize_links(json_decode($tab['links'], true)) ?? [],
    'tuning_presets' => $tab['kind'] === 'drum' ? [] : (TAB_TUNINGS[tab_string_count($tab['tuning'])] ?? []), // 編集画面の select の候補（名前 => 音名）
    'practice'       => $practice, // 自分の練習用マーカー（他の人のは渡さない）
    'edit'           => isset($_GET['edit']),
];
$json = json_encode($tabData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

render_header($tab['title'] . ' ' . $tab['part'], 'lives');
?>
<link rel="stylesheet" href="<?= asset_url('assets/tab.css') ?>">
<nav class="crumbs"><a href="./">ライブ</a><span>/</span><a href="<?= h($listUrl) ?>"><?= h($tab['title']) ?></a></nav>

<!-- YouTube のリンクに付けるロゴ（JS が複製して使う） -->
<template data-yt-icon><?= youtube_icon() ?></template>
<script type="application/json" id="tab-data"><?= $json ?></script>

<div class="tab" data-tab>
    <header class="tab-head">
        <?php if ($tab['artwork_url']): ?>
            <img class="tab-head__art" src="<?= h($tab['artwork_url']) ?>" alt="" width="44" height="44">
        <?php else: ?>
            <span class="tab-head__art tab-head__art--none"><?= icon('music_note') ?></span>
        <?php endif; ?>
        <div class="tab-head__text">
            <h1 class="tab-head__title"><?= h($tab['title']) ?></h1>
            <p class="muted small"><?= $tab['artist_name'] !== null ? h($tab['artist_name']) . ' · ' : '' ?><span data-part-label><?= h($tab['part']) ?></span>
                · <span class="tag" data-partial-label hidden></span> <span data-capo-label></span><span data-tuning-label></span></p>
            <!-- 参考リンク（JS が作る。リンクの追加・削除は編集画面） -->
            <p class="tab-links" data-links></p>
        </div>
        <?php // ？ = 使い方のモーダル。中でボタン名の表示（アイコンの下に名前）を切り替える（assets/tab.js の setupHelp） ?>
        <button type="button" class="icon-btn" data-help-open aria-label="使い方" title="使い方"><?= icon('help') ?></button>
        <button type="button" class="icon-btn" data-history-open aria-label="履歴" title="履歴"><?= icon('history') ?></button>
        <button type="button" class="icon-btn" data-mode-toggle aria-pressed="false" aria-label="編集" title="編集"><?= icon('edit') ?></button>
    </header>

    <!-- メモと画像: 曲のメモ（全パート共通・読むだけ）→ この TAB のメモ・画像（編集は編集モード）。何も無ければ JS が隠す -->
    <?= attach_modal_html() ?>
    <details class="tab-notes" data-notes hidden>
        <summary><?= icon('sticky_note_2') ?></summary>
        <?php if ((string)$meta['memo'] !== ''): ?><div class="tab-notes__song"><?= icon('library_music') ?> <span class="tab-memo"><?= linkify_body($meta['memo']) ?></span></div><?php endif; ?>
        <?= attach_thumbs_html('song_meta', $songImages) ?>
        <div class="tab-memo" data-memo-view></div>
        <ul class="live-thumbs tab-imgs" data-tab-images></ul>
        <label class="btn btn--ghost btn--sm" data-img-add title="ファイルを追加" aria-label="ファイルを追加" hidden><?= icon('add_photo_alternate') ?>
            <input type="file" multiple hidden data-img-input></label>
    </details>

    <!-- 音源: YouTube の埋め込み / 手元のファイル / なし（アイコンで切り替え） -->
    <div class="tab-video" data-video hidden><div data-video-mount></div></div>

    <!-- 操作バー（アイコンのみ。意味は title / aria-label で伝える） -->
    <div class="tab-bar card">
        <div class="tab-bar__group" role="group" aria-label="音源">
            <button type="button" class="icon-btn" data-src="yt" aria-label="YouTube" title="YouTube"><?= icon('smart_display') ?></button>
            <button type="button" class="icon-btn" data-src="file" aria-label="ファイル" title="ファイル"><?= icon('audio_file') ?></button>
            <button type="button" class="icon-btn" data-src="none" aria-label="音源なし" title="音源なし"><?= icon('timer') ?></button>
            <!-- 同期解除: 音源は止めて、今の位置から内部時計で進む（好きな BPM で練習できる） -->
            <button type="button" class="icon-btn" data-unsync aria-pressed="false" aria-label="同期解除" title="同期解除"><?= icon('link') ?></button>
            <!-- ファイルはサーバーに送らない。ブラウザの中で再生するだけ -->
            <input type="file" accept="audio/*,video/*" data-file hidden>
        </div>
        <div class="tab-bar__group">
            <button type="button" class="icon-btn" data-seek="-5" aria-label="5秒戻る" title="5秒戻る（←）"><?= icon('replay_5') ?></button>
            <button type="button" class="icon-btn icon-btn--play" data-play aria-label="再生" title="再生（Space）"><?= icon('play_arrow', 'icon--fill') ?></button>
            <button type="button" class="icon-btn" data-seek="5" aria-label="5秒進む" title="5秒進む（→）"><?= icon('forward_5') ?></button>
        </div>
        <!-- 練習BPM: 元の BPM との比が再生速度になる。×0.5… は BPM を入れるショートカット -->
        <div class="tab-bar__group" role="group" aria-label="速度">
            <?php foreach ([0.5, 0.75, 0.9, 1] as $r): ?>
                <button type="button" class="tab-chip<?= $r === 1 ? ' is-active' : '' ?>" data-rate="<?= $r ?>">×<?= $r ?></button>
            <?php endforeach; ?>
            <button type="button" class="icon-btn icon-btn--sm" data-pbpm-step="-5" aria-label="BPM -5" title="BPM -5"><?= icon('remove') ?></button>
            <?php // 入力欄・セレクトは ::after が付かないので、説明の文字は外側の span（data-help）に出す（tab.css） ?>
            <span class="tab-help-field" data-help="練習 BPM"><input type="number" class="tab-pbpm" data-pbpm min="20" max="400" step="1" inputmode="decimal" aria-label="練習 BPM" title="練習 BPM"></span>
            <button type="button" class="icon-btn icon-btn--sm" data-pbpm-step="5" aria-label="BPM +5" title="BPM +5"><?= icon('add') ?></button>
        </div>
        <div class="tab-bar__group" role="group" aria-label="リピート">
            <button type="button" class="tab-chip" data-ab="a" title="A 地点">A</button>
            <button type="button" class="tab-chip" data-ab="b" title="B 地点">B</button>
            <button type="button" class="icon-btn icon-btn--sm" data-ab="clear" aria-label="リピート解除" title="リピート解除（\）"><?= icon('repeat_on') ?></button>
            <!-- ループごとに BPM を上げる（元の BPM まで） -->
            <span class="tab-help-field" data-help="ループごとに BPM 増加"><select class="tab-loop-step" data-loop-step aria-label="ループごとの BPM 増加" title="ループごとの BPM 増加">
                <?php foreach ([0, 2, 5, 10] as $n): ?><option value="<?= $n ?>"><?= $n === 0 ? '+0' : '+' . $n ?></option><?php endforeach; ?>
            </select></span>
        </div>
        <div class="tab-bar__group">
            <button type="button" class="icon-btn" data-marker-add aria-label="マーカー追加" title="マーカー追加（M）"><?= icon('flag') ?></button>
        </div>
        <div class="tab-bar__group">
            <!-- メトロノーム: ボタンはオン・オフ。▾（または長押し）で、細分・音量・カウントインの設定が開く -->
            <span class="tab-metro">
                <button type="button" class="icon-btn" data-metro aria-pressed="false" aria-label="メトロノーム" title="メトロノーム"><?= icon('music_note') ?></button>
                <button type="button" class="icon-btn icon-btn--sm" data-metro-menu-toggle aria-expanded="false" aria-label="メトロノーム設定" title="メトロノーム設定"><?= icon('arrow_drop_down') ?></button>
                <div class="tab-metro__menu card" data-metro-menu hidden>
                    <label class="field">細分
                        <select data-metro-sub>
                            <option value="1">なし</option><option value="2">8分</option><option value="3">3連</option><option value="4">16分</option>
                        </select>
                    </label>
                    <label class="field">音量 <input type="range" min="0" max="100" step="5" data-metro-vol></label>
                    <label class="field">カウント
                        <select data-metro-count><option value="0">なし</option><option value="1">1小節</option><option value="2">2小節</option></select>
                    </label>
                </div>
            </span>
            <button type="button" class="icon-btn" data-zoom="-1" aria-label="縮小" title="縮小"><?= icon('zoom_out') ?></button>
            <button type="button" class="icon-btn" data-zoom="1" aria-label="拡大" title="拡大"><?= icon('zoom_in') ?></button>
        </div>
        <p class="tab-bar__pos small" aria-live="off"><span class="tab-beat" data-beat aria-hidden="true"></span> <span data-pos></span> <span class="tab-chord-now" data-chord-now></span></p>
    </div>

    <!-- 流れる TAB。canvas に JS が描く。クリック / タップでその時刻へ移動 -->
    <!-- 曲のマーカー（セクション）: 押すとその位置へ。ループのアイコンはそのセクションだけを繰り返す -->
    <div class="tab-sections" data-sections></div>

    <div class="tab-stage" data-stage><canvas data-canvas aria-label="TAB 譜"></canvas></div>

    <!-- 練習用マーカー（自分だけに見える。JS が作る） -->
    <ul class="tab-markers" data-markers></ul>

    <!-- ===== 編集（編集モードのときだけ） ===== -->
    <section class="tab-edit" data-edit hidden>
        <div class="card tab-settings">
            <label class="field">パート <input type="text" data-set="part" maxlength="20"></label>
            <label class="field">BPM
                <span class="tab-settings__row">
                    <input type="number" data-set="bpm" min="20" max="400" step="0.01" inputmode="decimal">
                    <!-- BPM 計測: 曲に合わせて4回以上タップ（小節の頭を合わせる「タップ」とは別） -->
                    <button type="button" class="icon-btn" data-bpm-tap aria-label="BPM計測" title="BPM計測（4回以上タップ）"><?= icon('speed') ?></button>
                </span>
            </label>
            <label class="field">拍子 <input type="number" data-set="beats" min="1" max="16" step="1" inputmode="numeric"></label>
            <label class="field">分割
                <select data-set="steps">
                    <?php foreach ([1 => '4分', 2 => '8分', 3 => '3連', 4 => '16分', 6 => '6連', 8 => '32分'] as $v => $label): ?>
                        <option value="<?= $v ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">開始（秒）
                <span class="tab-settings__row">
                    <input type="number" data-set="offset" min="0" step="0.01" inputmode="decimal">
                    <button type="button" class="icon-btn" data-offset-now aria-label="現在位置" title="現在位置"><?= icon('my_location') ?></button>
                </span>
            </label>
            <label class="field tab-settings__wide">YouTube <input type="url" data-set="youtube" maxlength="500" placeholder="https://youtu.be/…"></label>
            <label class="field tab-settings__wide">メモ
                <textarea data-set="memo" rows="3" maxlength="<?= TAB_MEMO_MAX ?>"></textarea>
            </label>
            <label class="field" data-partial-field>開始小節
                <span class="tab-settings__row"><input type="number" data-set="first_bar" min="1" max="999" inputmode="numeric">
                    <label class="check" title="部分 TAB"><input type="checkbox" data-set="partial"> 部分</label></span>
            </label>
            <label class="field" data-string-only>カポ
                <select data-set="capo">
                    <?php for ($i = 0; $i <= TAB_MAX_CAPO; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?>
                </select>
            </label>
            <div class="field tab-settings__wide" data-string-only>チューニング
                <span class="tab-settings__row">
                    <select data-set="tuning-preset" aria-label="チューニング"></select>
                    <input type="text" data-set="tuning" maxlength="30" aria-label="チューニングの音名" hidden>
                </span>
            </div>
            <!-- 参考にする TAB サイトなどのリンク（最大10個。行は JS が作る） -->
            <div class="field tab-settings__wide">リンク
                <div class="tab-links-edit" data-links-edit></div>
                <button type="button" class="icon-btn icon-btn--sm" data-link-add aria-label="リンク追加" title="リンク追加"><?= icon('add_link') ?></button>
            </div>
        </div>

        <!-- 曲のマーカー: 選んだ小節（なければ再生中の小節）の頭から、次にマーカーが付く小節の手前までがそのセクション -->
        <div class="tab-secpick" data-secpick role="group" aria-label="セクション"></div>

        <div class="tab-tools">
            <button type="button" class="icon-btn" data-undo aria-label="元に戻す" title="元に戻す（Ctrl+Z）" disabled><?= icon('undo') ?></button>
            <button type="button" class="icon-btn" data-redo aria-label="やり直す" title="やり直す（Ctrl+Y）" disabled><?= icon('redo') ?></button>
            <button type="button" class="icon-btn" data-import-open aria-label="インポート" title="インポート（ASCII TAB・画像）"><?= icon('upload_file') ?></button>
            <button type="button" class="tab-chip tab-chip--tap" data-tap-mode aria-pressed="false" title="小節の頭をタップして位置を補正"><?= icon('touch_app') ?> タップ</button>
            <button type="button" class="btn btn--primary tab-tap-btn" data-tap hidden><?= icon('touch_app') ?> T</button>
        </div>

        <!-- 小節のグリッド。JS が作る（1小節 = 弦の数 × マス） -->
        <div class="tg" data-grid></div>
        <button type="button" class="btn btn--ghost tg-add" data-bar-add aria-label="小節を追加" title="小節を追加"><?= icon('add') ?></button>

        <div class="sticky-actions tab-actions">
            <span class="sticky-actions__note" data-note hidden></span>
            <button type="button" class="btn btn--danger" data-delete aria-label="削除" title="削除"><?= icon('delete') ?></button>
            <button type="button" class="btn btn--primary" data-save><?= icon('save') ?> 保存</button>
        </div>
    </section>

    <!-- インポート: ASCII の TAB（文字）は JS が読み取り、画像（と読めない文字）は AI（api_tab_ai.php）が読み取る。
         どちらも結果をプレビューして、置換・末尾に追加のどちらかを選ぶまで何も変わらない（保存もしない） -->
    <dialog class="modal tab-import" data-import aria-labelledby="tab-import-title">
        <div class="modal__body">
            <h3 id="tab-import-title" class="modal__title"><?= icon('upload_file') ?> インポート</h3>
            <textarea rows="6" data-import-text spellcheck="false" placeholder="e|--3--5--|&#10;B|--------|" aria-label="ASCII TAB"></textarea>
            <div class="tab-import__images" data-import-images></div>
            <div class="form-actions tab-import__actions">
                <button type="button" class="btn btn--sm" data-import-parse><?= icon('text_snippet') ?> 読込</button>
                <button type="button" class="btn btn--sm" data-import-ai hidden><?= icon('auto_awesome') ?> AI</button>
            </div>
            <p class="small tab-import__status" data-import-status role="status"></p>
            <div class="form-actions">
                <button type="button" class="btn btn--ghost" data-import-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
                <button type="button" class="btn" data-import-apply="append" disabled>末尾に追加</button>
                <button type="button" class="btn btn--primary" data-import-apply="replace" disabled>置換</button>
            </div>
        </div>
    </dialog>

    <!-- 履歴: 開くと JS が api_tab_save の history で一覧を作る（文字は textContent で入れる） -->
    <dialog class="modal" data-history aria-labelledby="tab-history-title">
        <div class="modal__body">
            <h3 id="tab-history-title" class="modal__title"><?= icon('history') ?> 履歴</h3>
            <ul class="tab-history" data-history-list></ul>
            <div class="form-actions"><button type="button" class="btn btn--ghost" data-history-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button></div>
        </div>
    </dialog>

    <!-- 使い方: 全体の流れ（編集 → 練習）と、できることの一覧 -->
    <dialog class="modal tab-guide" data-guide aria-labelledby="tab-guide-title">
        <div class="modal__body">
            <h3 id="tab-guide-title" class="modal__title"><?= icon('help') ?> 使い方</h3>

            <h4 class="tab-guide__h">流れ</h4>
            <ol class="tab-guide__steps">
                <li><b><?= icon('edit') ?> 編集</b>で <b>YouTube</b> の URL・<b>BPM</b>・<b>拍子</b>・<b>分割</b>を設定</li>
                <li><b><?= icon('my_location') ?> 開始</b>: 1小節目の頭で再生を止めて押す</li>
                <li>マスを選んで<b>画面内キーボード</b>でフレットを入力（<?= icon('upload_file') ?> で文字・画像の TAB も取込）</li>
                <li>ずれる曲は <b><?= icon('touch_app') ?> タップ</b>: 再生しながら小節の頭で T を押して補正</li>
                <li><b><?= icon('save') ?> 保存</b> → 編集を閉じて練習</li>
            </ol>

            <h4 class="tab-guide__h">練習</h4>
            <ul class="tab-guide__list">
                <li><?= icon('smart_display') ?><?= icon('audio_file') ?><?= icon('timer') ?><span>音源: YouTube / 手元のファイル（送信なし）/ なし</span></li>
                <li><?= icon('link') ?><span>同期解除: 音源を止めて好きな BPM で進む</span></li>
                <li><?= icon('touch_app') ?><span>TAB をタップでその位置へ移動</span></li>
                <li><span class="tab-guide__txt">×0.5</span><span>速度・練習 BPM</span></li>
                <li><span class="tab-guide__txt">A B</span><span>A→B リピート（+2 などでループごとに BPM 増加）</span></li>
                <li><?= icon('loop') ?><span>セクション横のアイコンでその部分だけ繰り返し</span></li>
                <li><?= icon('flag') ?><span>練習用マーカー（自分だけに表示）</span></li>
                <li><?= icon('music_note') ?><span>メトロノーム（▾ で細分・音量・カウント）</span></li>
                <li><?= icon('zoom_in') ?><span>拡大・縮小</span></li>
            </ul>

            <h4 class="tab-guide__h">編集</h4>
            <ul class="tab-guide__list">
                <li><?= icon('label') ?><span>小節を選んでセクション名を付ける</span></li>
                <li><?= icon('speed') ?><span>BPM 計測: 曲に合わせて4回以上タップ</span></li>
                <li><?= icon('undo') ?><?= icon('redo') ?><span>元に戻す・やり直す</span></li>
                <li><?= icon('history') ?><span>履歴から復元</span></li>
                <li><?= icon('sticky_note_2') ?><span>メモ・画像・参考リンク</span></li>
            </ul>

            <p class="tab-guide__keys small muted">Space 再生 · ←→ 5秒 · M マーカー · \ リピート解除 · Ctrl+Z / Y</p>

            <div class="form-actions">
                <label class="check tab-guide__labels"><input type="checkbox" data-help-labels> ボタン名を表示</label>
                <button type="button" class="btn btn--ghost" data-help-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
            </div>
        </div>
    </dialog>

    <!-- スマホ用のフレットパッド。マスを選んでいる間だけ画面下に出る -->
    <!-- 画面内キーボード（JS が作る。編集モードでマスを選んでいる間だけ。スマホは下、広い画面は右の横） -->
    <div class="tab-pad" data-pad hidden></div>
</div>

<script src="<?= asset_url('assets/tab.js') ?>" defer></script>
<?php render_footer();
