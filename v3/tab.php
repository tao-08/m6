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
$isPlayer = isset($_GET['link']) && !is_post();
$user = $isPlayer ? current_user() : require_login(); // 練習プレイヤー（?link=）は未ログインでも見られる（設定の保存はログイン者だけ）
if (!$isPlayer && !tab_allowed()) { // TAB 譜は管理人だけ
    error_page(403, '管理人のみ利用できます'); // lib/tabs.php の tab_allowed
}
$pdo = db();

// =====================================================================
//  A. 曲ごとの一覧 + 新規作成（?song=曲ID または ?key=曲キー）
// =====================================================================
//  ?song= … セットリストの曲から来る入口。紐付けの有無に関係なく、曲から「曲キー」を決める
//  ?key=  … 曲キーそのもの（プレイヤーからの戻り先）。曲キーの形は lib/tabs.php の tab_song_key()
if (isset($_GET['song']) || isset($_GET['key'])) {
    // 曲を決める（曲 ID → 曲キー、または曲キーそのもの）。中身は lib/tabs.php の tab_resolve_song()（API のリンク登録でも使う）
    $songId = isset($_GET['song']) ? (int)$_GET['song'] : null;
    $key = $songId === null && is_string($_GET['key']) ? $_GET['key'] : null;
    $song = tab_resolve_song($pdo, $songId, $key);
    $listUrl = $songId !== null ? 'tab?song=' . $songId : 'tab?key=' . rawurlencode((string)$key);
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
    $st = $pdo->prepare('SELECT t.tab_id, t.part, t.instrument_id, t.tuning, t.kind, t.first_bar, t.is_partial, JSON_LENGTH(t.data, "$.bars") AS bar_count, t.updated_at, i.short_name, i.name AS inst_name
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

    // ---- カード: 曲全体（part = ''）と、パートごと（Gt1・Ba など。自作 TAB があるパート + 「楽器を追加」で作ったパート）----
    //   リンク・メモ・画像は、どのカードのものかをパート名（song_link.part / song_meta.part）で決める。パート名の大文字小文字は区別しない（DB と同じ）
    //   テーブル・列がまだ無い（マイグレーション 075 / 076 の前）ときは、その部分だけ空にして、画面は開けるようにする
    $partKey = static fn(string $p): string => mb_strtolower($p);
    $partCards = [];
    foreach ($parts as $p) { // マイグレーション前でも、自作 TAB のあるパートはカードにできる
        $partCards[$partKey($p['part'])] = ['part' => $p['part'], 'instrument_id' => (int)$p['instrument_id'], 'name' => $p['inst_name'], 'short_name' => $p['short_name'], 'has_tab' => true];
    }
    try {
        $all = tab_song_parts($pdo, $song['key']); // 先に読む（失敗したら、上の自作 TAB だけの一覧を残す）
        $partCards = [];
        foreach ($all as $pc) {
            $partCards[$partKey($pc['part'])] = $pc;
        }
        if (!$partCards) { // 初期表示: カードが1つも無い曲は、ギター（Gt1）とベース（Ba）のカードを作る
            $pdo->prepare('INSERT IGNORE INTO song_part (song_key, part, instrument_id, created_by) VALUES (?, \'Gt1\', 2, ?), (?, \'Ba\', 3, ?)')
                ->execute([$song['key'], $user['user_id'], $song['key'], $user['user_id']]);
            foreach (tab_song_parts($pdo, $song['key']) as $pc) {
                $partCards[$partKey($pc['part'])] = $pc;
            }
        }
    } catch (PDOException) {
        // song_part がまだ無い: 上の（自作 TAB だけの）一覧のまま
    }
    $linksByPart = []; // パート名（小文字）=> [リンクの行, ...]（サイトの並び順 → 登録順）。MV は楽曲データベースのモーダルに出すのでここには出さない
    try {
        $st = $pdo->prepare('SELECT link_id, part, site, url, label FROM song_link WHERE song_key = ? AND site <> \'mv\'
            ORDER BY FIELD(site, ' . implode(', ', array_map(static fn($k) => $pdo->quote($k), array_keys(TAB_LINK_SITES))) . '), link_id');
        $st->execute([$song['key']]);
        foreach ($st as $l) {
            $linksByPart[$partKey((string)$l['part'])][] = $l;
        }
    } catch (PDOException) {
        // song_link がまだ無い
    }

    // メモと画像（song_meta。曲全体・パートごとに1行。最初に保存したときに行ができる）
    $metaByPart = [];  // パート名（小文字）=> ['meta_id', 'memo']
    $imagesByMeta = []; // meta_id => 画像の行
    try {
        $st = $pdo->prepare('SELECT meta_id, part, memo FROM song_meta WHERE song_key = ?');
        $st->execute([$song['key']]);
        foreach ($st as $m) {
            $metaByPart[$partKey((string)$m['part'])] = $m;
        }
    } catch (PDOException) {
        // part 列がまだ無い（マイグレーション 076 の前）: 曲全体のメモだけ読む
        $st = $pdo->prepare('SELECT meta_id, memo FROM song_meta WHERE song_key = ?');
        $st->execute([$song['key']]);
        if ($m = $st->fetch()) {
            $metaByPart[''] = $m;
        }
    }
    if ($metaByPart) {
        $imagesByMeta = attach_images($pdo, 'song_meta', array_map(static fn($m) => (int)$m['meta_id'], array_values($metaByPart)));
    }
    // 画面の JS に渡す: カードごと（'' = 曲全体）の画像・PDF・ファイルの ID
    $cardData = [];
    foreach (array_keys($partCards) as $k) {
        $rows = $imagesByMeta[(int)($metaByPart[$k]['meta_id'] ?? 0)] ?? [];
        $cardData[] = ['part' => $partCards[$k]['part'], 'images' => array_map(static fn($im) => (int)$im['image_id'], $rows),
            'pdfs' => tab_pdf_ids($rows), 'files' => tab_file_ids($rows)];
    }
    $songData = json_encode(['key' => $song['key'], 'song_id' => $songId, 'cards' => $cardData],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

    render_header($song['title'] . ' TAB', 'lives');
    ?>
<link rel="stylesheet" href="<?= asset_url('assets/tab.css') ?>">
<nav class="crumbs"><a href="songs?at=<?= rawurlencode((string)$song['key']) ?>">楽曲データベース</a></nav>
<h1 class="display display--sm">TAB譜・コード</h1>
<section class="card tab-song">
    <?php if ($song['art']): ?>
        <img class="tab-song__art" src="<?= h($song['art']) ?>" alt="" width="72" height="72">
    <?php else: ?>
        <span class="tab-song__art tab-song__art--none"><?= icon('music_note') ?></span>
    <?php endif; ?>
    <div class="tab-song__text">
        <h2 class="tab-song__title"><?= h($song['title']) ?></h2> <?php // 見出し（h1）は上の「TAB譜・コード」。曲名はその下の見出し ?>
        <?php if ($song['artist']): ?><p class="muted"><?= h($song['artist']) ?></p><?php endif; ?>
    </div>
</section>

<?php
// ---- 新規作成フォーム（弦の数のタイル + 詳細）。パートのカードの中に置く ----
//   カードのパート名（Gt1 など）は固定（hidden）で、楽器もカードの楽器だけ（ギターなら 6 弦 / 7 弦）。
//   フォームの HTML は関数（クロージャ = 変数に入れた名前の無い関数）にして 1 か所にだけ書き、カードごとに呼ぶ。
//   ラジオの data-part には、パート名（楽器を選び替えても同じ名前）を持たせる（JS が、手で書き換えていないときだけ入力欄に入れる仕組みをそのまま使う）
$renderNewForm = static function (array $presets, string $part): void {
    $firstKey = array_key_first($presets); // 最初のプリセットを選んだ状態で出す（ラジオは何も選ばないと値が送られない）
    ?>
    <form method="post" class="tab-new">
        <?= csrf_field() ?>
        <?php // 弦の数: select ではなく大きなタイルのラジオ（スマホの select は別画面のピッカーが開いて 2 タップ以上かかる。タイルなら 1 タップ）。選べるのが1つだけなら出さない ?>
        <fieldset class="tab-inst"<?= count($presets) < 2 ? ' hidden' : '' ?>>
            <legend class="tab-sr">楽器</legend>
            <?php foreach ($presets as $k => $p): ?>
                <label class="tab-inst__opt">
                    <input type="radio" name="preset" value="<?= h($k) ?>" data-part="<?= h($part) ?>"<?= $k === $firstKey ? ' checked' : '' ?>>
                    <span><?= h($p['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <input type="hidden" name="part" value="<?= h($part) ?>">
        <details class="tab-new__more">
            <summary class="tab-summary"><?= icon('tune') ?> 詳細 <?= icon('expand_more', 'tab-summary__chev') ?></summary>
            <?php // 部分 TAB（ギターソロだけ等）。チェックを外している間は、開始小節は入力できない（JS が disabled にする） ?>
            <div class="tab-new__partial">
                <label class="check" title="部分 TAB（ギターソロだけ等）"><input type="checkbox" name="partial" value="1"> 部分</label>
                <label class="field">開始小節 <input type="number" name="first_bar" min="1" max="999" value="1" inputmode="numeric"></label>
            </div>
        </details>
        <button type="submit" class="btn btn--primary tab-new__submit"><?= icon('add') ?> 作成</button>
    </form>
    <?php
};
?>
<?php
// ---- カード: 曲全体 + パートごと（Gt1・Ba など）。どのカードも、リンク（YouTube・U-FRET など）・メモ・画像を持つ ----
//   自作の TAB（ベータ）はパートのカードの中の1行。TAB の作成フォームも、TAB がまだ無いギター・ベース・ドラムのカードに置く
$hasYoutubeKey = (string)(config('youtube')['api_key'] ?? '') !== '';
$renderCard = static function (string $part, ?array $info) use ($partKey, $linksByPart, $metaByPart, $imagesByMeta, $parts, $song, $hasYoutubeKey, $renderNewForm): void {
    $k = $partKey($part);
    $myLinks = $linksByPart[$k] ?? [];
    $meta = $metaByPart[$k] ?? ['meta_id' => 0, 'memo' => null];
    $hasMemo = (string)($meta['memo'] ?? '') !== '' || ($imagesByMeta[(int)$meta['meta_id']] ?? []) !== [];
    $myTab = null; // このパートの自作 TAB（パートごとに1つまで）
    foreach ($parts as $p) {
        if ($partKey($p['part']) === $k && $part !== '') {
            $myTab = $p;
        }
    }
    $isLive = $info && mb_strtolower($part) === 'live'; // ライブ版のカード（その他の楽器で「Live」というパート名）
    $instName = $isLive ? 'ライブ版' : $info['name'];
    $ytQuery = trim($song['title'] . ' ' . ($song['artist'] ?? '') . ' ' . ($isLive ? 'ライブ' : $info['name'])); // 動画検索の初期の検索語（楽器名も）
    $presets = $info ? array_filter(TAB_PRESETS, static fn($p) => $p['instrument'] === $info['instrument_id']) : [];
    ?>
<section class="card tab-links tab-card" data-card data-part="<?= h($part) ?>">
    <?php if ($info): ?>
        <header class="tab-card__head">
            <?= part_badge(['segments' => [['short' => $isLive ? 'Live' : $info['short_name'], 'class' => instrument_class($isLive ? '' : $info['short_name'])]], 'title' => $instName]) ?>
            <h3 class="tab-card__title"><?= h($part) ?></h3>
            <?php if (!$info['has_tab']): // 自作 TAB があるパートは、ここからは消せない（TAB の削除は TAB の画面から） ?>
                <button type="button" class="icon-btn" data-part-del title="パートを削除" aria-label="パートを削除"><?= icon('delete') ?></button>
            <?php endif; ?>
        </header>
    <?php endif; ?>
    <?php // 一覧は常に置く（リンク・TAB・メモ・画像のどれも無いカードでは隠す。メモ・画像の行は JS が足す） ?>
    <ul class="tab-links__list" data-link-list<?= $myLinks || $myTab ? '' : ' hidden' ?>>
        <?php // 譜のサイト（U-FRET・楽器.me・Songsterr）は、そのカードにそのサイトが1件だけなら長いタイトルではなくサイト名のボタンにする（タイトルは長押し・ホバーで）。YouTube・その他、同じサイトが複数のときは区別できるようタイトルのまま
        $siteCount = array_count_values(array_column($myLinks, 'site'));
        foreach ($myLinks as $l):
            $site = TAB_LINK_SITES[$l['site']] ?? TAB_LINK_SITES['other'];
            $asButton = in_array($l['site'], ['ufret', 'gakki', 'songsterr'], true) && $siteCount[$l['site']] === 1; ?>
            <li class="tab-links__item" data-link-id="<?= (int)$l['link_id'] ?>">
                <span class="tab-links__site tab-links__site--<?= h($l['site']) ?>" title="<?= h($site['label']) ?>"><?= icon($site['icon']) ?></span>
                <?php if ($asButton): ?>
                    <a class="btn btn--sm tab-links__btn" href="<?= h($l['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($l['label'] ?: $site['label']) ?>"><?= h($site['label']) ?> <?= icon('open_in_new') ?></a>
                    <span class="tab-links__name tab-links__name--spacer"></span>
                <?php else: ?>
                    <a class="tab-links__name" href="<?= h($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($l['label'] ?: $site['label']) ?></a>
                <?php endif; ?>
                <button type="button" class="icon-btn" data-link-move title="楽器を変更" aria-label="楽器を変更"><?= icon('swap_horiz') ?></button>
                <button type="button" class="icon-btn" data-link-del title="削除" aria-label="削除"><?= icon('delete') ?></button>
                <?php if ($l['site'] === 'youtube'): // 2段目: 練習プレイヤーと、その画面のホーム画面ショートカット（?a2hs=1 で開くと追加の案内が出る） ?>
                    <div class="tab-links__row2">
                        <a class="btn btn--sm tab-links__btn" href="tab?link=<?= (int)$l['link_id'] ?>" title="練習プレイヤー"><?= icon('slow_motion_video') ?> 練習</a>
                        <a class="btn btn--sm tab-links__btn touch-only" href="tab?link=<?= (int)$l['link_id'] ?>&amp;a2hs=1" title="ホーム画面に追加" aria-label="ホーム画面に追加"><?= icon('add_to_home_screen') ?></a>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if ($myTab): // 自作の TAB（ベータ） ?>
            <li class="tab-links__item">
                <span class="tab-links__site tab-links__site--own" title="TAB（ベータ）"><?= icon('edit_note') ?></span>
                <a class="tab-links__name" href="tab?id=<?= (int)$myTab['tab_id'] ?>">TAB
                    <?php if ($myTab['is_partial']): ?><span class="tag" title="部分">部分 <?= (int)$myTab['first_bar'] ?>〜<?= (int)$myTab['first_bar'] + max(0, (int)$myTab['bar_count'] - 1) ?></span><?php endif; ?>
                    <?php if ($myTab['kind'] !== 'drum'): ?><span class="muted small"><?= (int)tab_string_count($myTab['tuning']) ?>弦</span><?php endif; ?></a>
                <span class="tag tab-beta">β</span>
                <span class="muted small tab-parts__date"><?= h(fmt_datetime($myTab['updated_at'])) ?></span>
            </li>
        <?php endif; ?>
    </ul>
    <div class="tab-links__actions">
        <?php // YouTube 専用の検索（埋め込みで試し見してから登録）。API キーが無ければ出さない ?>
        <?php if ($hasYoutubeKey): ?>
            <button type="button" class="btn btn--sm" data-yts-open data-q="<?= h(mb_substr($ytQuery, 0, 100)) ?>" title="YouTube を検索"><?= icon('smart_display') ?> 動画検索</button>
        <?php endif; ?>
        <?php if (claude_enabled() || tab_search_enabled()): // U-FRET・楽器.me・Songsterr の検索（どのカードにも） ?>
            <button type="button" class="btn btn--sm" data-link-ai title="U-FRET・楽器.me・Songsterr を検索"><?= icon('auto_awesome') ?> コード検索</button>
        <?php endif; ?>
        <form class="tab-links__add" data-link-add>
            <input type="url" name="url" required placeholder="https://" aria-label="URL" autocomplete="off">
            <button type="submit" class="btn btn--primary btn--sm" title="登録" aria-label="登録"><?= icon('add_link') ?></button>
        </form>
    </div>
    <ul class="tab-links__list tab-links__found" data-link-found hidden></ul>
    <?php if ($info && !$myTab && $presets): // 自作の TAB（ベータ）の作成。TAB がまだ無い、ギター・ベース・ドラムのパートだけ。閉じた状態で置く ?>
        <details class="tab-new-wrap">
            <summary class="tab-new-wrap__summary"><?= icon('edit_note') ?> TAB 作成 <span class="tag tab-beta">ベータ</span></summary>
            <?php $renderNewForm($presets, $part); ?>
        </details>
    <?php endif; ?>
    <?php // メモと画像。折りたたみ（<details>）。入っているものは上のリンクの一覧に行（JS が作る）で出て、その行を押すとここが開く。画像のモーダル（<dialog>）は、閉じた <details> の中に置かない（下で1回だけ出す） ?>
    <details class="tab-notes-edit tab-card__memo" data-song-memo>
        <summary class="tab-summary"><?= icon('sticky_note_2') ?> テキスト・画像 <?= icon('expand_more', 'tab-summary__chev') ?></summary>
        <div class="tab-notes-edit__body">
            <label class="field">
                <textarea rows="3" maxlength="<?= TAB_MEMO_MAX ?>" data-song-memo-text aria-label="テキスト"><?= h($meta['memo'] ?? '') ?></textarea>
            </label>
            <ul class="live-thumbs tab-imgs" data-song-images></ul>
            <div class="tab-notes-edit__actions">
                <label class="btn btn--ghost btn--sm" data-img-add title="ファイルを追加" aria-label="ファイルを追加"><?= icon('add_photo_alternate') ?>
                    <input type="file" multiple hidden data-img-input></label>
                <button type="button" class="btn btn--primary btn--sm" data-song-memo-save><?= icon('save') ?> 保存</button>
            </div>
        </div>
    </details>
</section>
    <?php
};
foreach ($partCards as $pc) {
    $renderCard($pc['part'], $pc);
}
?>

<?php // 楽器を追加: 選んだ楽器のパート名（Gt1・Ba など）は空いている名前を自動で付ける。TAB の作成は、追加したカードの中から ?>
<details class="card tab-addpart">
    <summary class="tab-new-wrap__summary"><?= icon('add') ?> 楽器</summary>
    <div class="tab-addpart__chips">
        <?php foreach (['2' => 'ギター', '3' => 'ベース', '4' => 'ドラム', 'live' => 'ライブ版', 'etc' => 'その他'] as $addKey => $addLabel): ?>
            <button type="button" class="tab-chip" data-part-add="<?= h((string)$addKey) ?>"><?= h($addLabel) ?></button>
        <?php endforeach; ?>
    </div>
</details>

<?php // YouTube の検索ダイアログ（ページに1つ。どのカードの「動画」からも開く）。検索語は画面で直せる（楽器の語を足せる）。結果は押すとその場で埋め込み再生、＋で登録 ?>
<section class="tab-yts-wrap" data-yts-wrap>
    <dialog class="yts" data-yts>
        <form class="yts__bar" data-yts-form>
            <input type="search" name="q" maxlength="100" aria-label="検索語" autocomplete="off">
            <button type="submit" class="btn btn--primary btn--sm" title="検索" aria-label="検索"><?= icon('search') ?></button>
            <button type="button" class="icon-btn" data-yts-close title="閉じる" aria-label="閉じる"><?= icon('close') ?></button>
        </form>
        <div class="yts__chips">
            <?php foreach (['ギター', 'ベース', 'ドラム', '弾いてみた', 'TAB', 'コード', 'ライブ'] as $w): ?>
                <button type="button" class="tab-chip" data-yts-word="<?= h($w) ?>"><?= h($w) ?></button>
            <?php endforeach; ?>
        </div>
        <ul class="yts__list" data-yts-list></ul>
    </dialog>
</section>

<?php // リンクの付け替え（楽器の変更）ダイアログ。ページに1つ。今あるカード + まだ無い楽器（押すとカードを作って移す） ?>
<dialog class="yts lmove" data-lmove>
    <ul class="lmove__list">
        <li><button type="button" class="lmove__opt" data-lmove-part=""><?= icon('library_music') ?> 曲全体</button></li>
        <?php foreach ($partCards as $pc): $isLive = mb_strtolower($pc['part']) === 'live'; ?>
            <li><button type="button" class="lmove__opt" data-lmove-part="<?= h($pc['part']) ?>">
                <?= part_badge(['segments' => [['short' => $isLive ? 'Live' : $pc['short_name'], 'class' => instrument_class($isLive ? '' : $pc['short_name'])]], 'title' => $pc['name']]) ?> <?= h($pc['part']) ?></button></li>
        <?php endforeach; ?>
        <?php $haveInst = array_unique(array_map(static fn($pc) => (int)$pc['instrument_id'], $partCards));
        foreach (['Gt', 'Ba', 'Dr', 'Key', 'Vo', 'etc'] as $short):
            $iid = instrument_id_by_short($short);
            if ($iid === null || in_array($iid, $haveInst, true)) { continue; } ?>
            <li><button type="button" class="lmove__opt lmove__opt--new" data-lmove-inst="<?= h($short) ?>">
                <?= part_badge(['segments' => [['short' => $short, 'class' => instrument_class($short)]], 'title' => $short]) ?> <?= icon('add') ?></button></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="icon-btn lmove__close" data-lmove-close title="閉じる" aria-label="閉じる"><?= icon('close') ?></button>
</dialog>

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

<?php // 各カードのメモ・画像の JSON と、画像のモーダル（<dialog>）。閉じた <details> の中には置かない（中身が描画されないので、モーダルが開けない恐れがある） ?>
<script type="application/json" id="song-data"><?= $songData ?></script>
<?= attach_modal_html() ?>
<script src="<?= asset_url('assets/tab.js') ?>" defer></script><!-- カードごとのメモ・画像・リンク・動画検索、パートの追加・削除、削除した TAB の復元、新規作成フォーム --><?php
    render_footer();
    exit;
}

// =====================================================================
//  C. YouTube の練習プレイヤー（?link=リンクID）
// =====================================================================
//  曲に登録した YouTube のリンク（song_link）を、AB リピート・マーク・曲構成・◯秒戻し・速度変更つきで再生する。
//  設定は人ごと（song_link_practice）。動きは assets/ytplayer.js、保存は api_tab_save.php の link_practice
if (isset($_GET['link'])) {
    try {
        $st = $pdo->prepare('SELECT link_id, song_key, url, label, title, artist_name FROM song_link WHERE link_id = ? AND site = ?');
        $st->execute([(int)$_GET['link'], 'youtube']);
        $link = $st->fetch();
    } catch (PDOException) {
        $link = false;
    }
    $videoId = $link ? youtube_video_id($link['url']) : null;
    if (!$link || $videoId === null) {
        error_page(404, 'リンクが見つかりません');
    }
    $saved = false;
    if ($user !== null) {
        $st = $pdo->prepare('SELECT data FROM song_link_practice WHERE link_id = ? AND user_id = ?');
        $st->execute([(int)$link['link_id'], (int)$user['user_id']]);
        $saved = $st->fetchColumn();
    }
    $practice = tab_sanitize_link_practice(json_decode((string)$saved, true)) ?? ['m' => [], 'ab' => null, 'rate' => 1, 'back' => 5, 'pad' => 2];
    // 動画のチャプター（概要欄）は動画ごとに DB（song_link_chapter）へ1回だけ控える。ゲストにも、ログイン中の人にも同じものを使う
    //   cached = 控えがある / チャプターが無い動画も [] で控える（毎回 API を叩かない）。API が失敗したら控えず、次に開いたときにもう一度
    $chapters = null;
    $linkId = (int)$link['link_id'];
    try { // テーブルはマイグレーション 079。まだなら毎回 API で取る
        $st = $pdo->prepare('SELECT data FROM song_link_chapter WHERE link_id = ?');
        $st->execute([$linkId]);
        $cached = $st->fetchColumn();
        $chapters = $cached !== false ? (tab_sanitize_practice(['m' => json_decode((string)$cached, true)])['m'] ?? []) : null;
    } catch (PDOException) {
    }
    if ($chapters === null && ($user === null || empty($practice['ch']))) {
        $chapters = youtube_chapters($videoId);
        if ($chapters !== null) {
            try {
                $pdo->prepare('INSERT IGNORE INTO song_link_chapter (link_id, data) VALUES (?, ?)')
                    ->execute([$linkId, json_encode($chapters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            } catch (PDOException) {
            }
        }
    }
    // ログイン中は、最初に開いたときに1回だけマーカーを置き換える（取り込み済みの印 ch。以後の編集は消さない）
    if ($user !== null && empty($practice['ch']) && $chapters !== null) {
        $practice = ($chapters ? ['m' => $chapters, 'ab' => null] + $practice : $practice) + ['ch' => 1];
        $clean = tab_sanitize_link_practice($practice);
        if ($clean !== null) {
            $practice = $clean;
            $args = [$linkId, (int)$user['user_id'], json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            try { // 開いただけでは練習中リストに載せない（practicing = 0。再生すると 1 になる）。列はマイグレーション 078
                $pdo->prepare('INSERT INTO song_link_practice (link_id, user_id, data, practicing) VALUES (?, ?, ?, 0)
                        ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute($args);
            } catch (PDOException) {
                try {
                    $pdo->prepare('INSERT INTO song_link_practice (link_id, user_id, data) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute($args);
                } catch (PDOException) { /* 保存できなくても画面には出す。次に開いたときにもう一度 */ }
            }
        }
    }
    // 共通マーカー（曲構成のラベル付き）。行が無い（null）間は、人ごとのマーカーをそのまま使う。テーブルはマイグレーション 080
    $shared = null;
    try {
        $st = $pdo->prepare('SELECT data FROM song_link_shared_mark WHERE link_id = ?');
        $st->execute([$linkId]);
        $row = $st->fetchColumn();
        $shared = $row === false ? null : (tab_sanitize_practice(['m' => json_decode((string)$row, true)])['m'] ?? []);
    } catch (PDOException) {
    }
    $ytData = json_encode(['link_id' => (int)$link['link_id'], 'video' => $videoId, 'practice' => $practice, 'guest' => $user === null, 'shared' => $shared] +($user === null && $chapters ? ['chapters' => $chapters] : []),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $listUrl = 'tab?key=' . rawurlencode($link['song_key']);

    $GLOBALS['page_manifest'] = 'manifest_link.php?id=' . (int)$link['link_id']; // ホーム画面に追加すると、この練習画面が直接開く
    render_header($link['title'] . ' 練習', 'lives');
    ?>
<link rel="stylesheet" href="<?= asset_url('assets/tab.css') ?>">
<nav class="crumbs"><a href="songs?at=<?= rawurlencode((string)$link['song_key']) ?>">楽曲データベース</a><span>/</span><a href="<?= h($listUrl) ?>"><?= h($link['title']) ?></a></nav>
<h1 class="display display--sm"><?= h($link['label'] ?: $link['title']) ?></h1>
<script type="application/json" id="yt-data"><?= $ytData ?></script>
<section class="card ytp" data-ytp>
    <div class="ytp__video"><div data-ytp-mount></div></div>
    <?php // 操作部（全画面のときは動画に重ねる）。タイムライン: 再生位置・AB の範囲・マークを表示。押した位置へ移動 ?>
    <div class="ytp__dock">
    <div class="ytp__bubbles ytp__bubbles--up" data-ytp-bubbles-up aria-hidden="true"></div>
    <div class="ytp__line" data-ytp-line title="押す・ドラッグで移動">
        <div class="ytp__ab" data-ytp-abband hidden></div>
        <div class="ytp__pos" data-ytp-pos></div>
    </div>
    <div class="ytp__bubbles" data-ytp-bubbles aria-hidden="true"></div>
    <div class="ytp__time"><span data-ytp-cur>0:00</span> / <span data-ytp-dur>0:00</span></div>

    <div class="ytp__row">
        <button type="button" class="icon-btn" data-ytp-start title="先頭（Home）" aria-label="先頭"><?= icon('skip_previous') ?></button>
        <button type="button" class="icon-btn" data-ytp-back title="戻す（←）" aria-label="戻す"><?= icon('fast_rewind') ?></button>
        <label class="ytp__sec" title="戻す・進める秒数"><input type="number" min="1" max="60" inputmode="numeric" data-ytp-backsec aria-label="秒数">秒</label>
        <button type="button" class="btn btn--primary ytp__play" data-ytp-play title="再生・停止（Space）" aria-label="再生・停止"><?= icon('play_arrow') ?></button>
        <button type="button" class="icon-btn" data-ytp-fwd title="進める（→）" aria-label="進める"><?= icon('fast_forward') ?></button>
        <button type="button" class="icon-btn ytp__fsonly" data-ytp-zoom title="拡大（全体・上半分・下半分）" aria-label="拡大"><?= icon('fit_screen') ?></button>
        <button type="button" class="icon-btn" data-ytp-fs title="全画面" aria-label="全画面"><?= icon('fullscreen') ?></button>
    </div>

    <div class="ytp__row">
        <?= icon('speed', 'ytp__label') ?>
        <button type="button" class="icon-btn" data-ytp-slower title="遅く" aria-label="遅く"><?= icon('remove') ?></button>
        <span class="ytp__rate" data-ytp-rate>100%</span>
        <button type="button" class="icon-btn" data-ytp-faster title="速く" aria-label="速く"><?= icon('add') ?></button>
        <button type="button" class="tab-chip" data-ytp-rate1>100%</button>
    </div>

    <div class="ytp__row">
        <?= icon('repeat', 'ytp__label') ?>
        <button type="button" class="tab-chip" data-ytp-a title="現在位置を A に">A <span data-ytp-at></span></button>
        <button type="button" class="tab-chip" data-ytp-b title="現在位置を B に">B <span data-ytp-bt></span></button>
        <button type="button" class="tab-chip" data-ytp-loop aria-pressed="false" title="AB リピート"><?= icon('repeat') ?></button>
        <button type="button" class="icon-btn" data-ytp-abclear title="AB を解除" aria-label="AB を解除"><?= icon('close') ?></button>
    </div>
    <div class="ytp__row">
        <?= icon('bookmark', 'ytp__label') ?>
        <button type="button" class="icon-btn" data-ytp-prevmark title="前のマーカー（Shift+←）" aria-label="前のマーカー"><?= icon('keyboard_double_arrow_left') ?></button>
        <button type="button" class="icon-btn" data-ytp-nextmark title="次のマーカー（Shift+→）" aria-label="次のマーカー"><?= icon('keyboard_double_arrow_right') ?></button>
    </div>
    </div>

    <?php // 曲構成のラベル（押すと現在位置にそのラベルのマーク）と、ラベルなしのマーク ?>
    <div class="ytp__row">
        <button type="button" class="tab-chip" data-ytp-edit aria-pressed="false" title="マーク編集" aria-label="マーク編集"><?= icon('edit') ?></button>
    </div>
    <div class="ytp__secs is-closed" data-ytp-secs inert>
    <div class="ytp__row">
        <button type="button" class="tab-chip" data-ytp-mark title="現在位置にマーク（M）"><?= icon('bookmark_add') ?>練習用マーカー</button>
        <label class="ytp__sec" title="リピートの前後に足す秒数">リピート前後幅<input type="number" min="0" max="10" inputmode="numeric" data-ytp-pad aria-label="リピート前後幅">秒</label>
    </div>
    <div class="ytp__row">
        <?php foreach (TAB_SHARED_LABELS as $sec): ?>
            <button type="button" class="tab-chip" data-ytp-sec="<?= h($sec) ?>"><?= h($sec) ?></button>
        <?php endforeach; ?>
    </div>
    </div>
    <ul class="ytp__marks" data-ytp-marks></ul>
    <a class="ytp__open muted small" href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('open_in_new') ?> YouTube</a>
</section>
<script src="<?= asset_url('assets/ytplayer.js') ?>" defer></script>
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

// 曲全体のメモ・画像と、このパートのカードのメモ・画像（読むだけ。編集は曲のページ）。TAB のメモ・画像は JS が扱う
$st = $pdo->prepare('SELECT meta_id, part, memo FROM song_meta WHERE song_key = ? AND part IN (\'\', ?)');
$st->execute([$tab['song_key'], $tab['part']]);
$meta = ['meta_id' => 0, 'memo' => null];     // 曲全体
$partMeta = ['meta_id' => 0, 'memo' => null]; // このパート
foreach ($st as $m) {
    if ((string)$m['part'] === '') {
        $meta = $m;
    } else {
        $partMeta = $m;
    }
}
$songImages = attach_images($pdo, 'song_meta', [(int)$meta['meta_id']])[(int)$meta['meta_id']] ?? [];
$partImages = attach_images($pdo, 'song_meta', [(int)$partMeta['meta_id']])[(int)$partMeta['meta_id']] ?? [];
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
<nav class="crumbs"><a href="songs?at=<?= rawurlencode((string)$tab['song_key']) ?>">楽曲データベース</a><span>/</span><a href="<?= h($listUrl) ?>"><?= h($tab['title']) ?></a></nav>

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
        <?php if ((string)$partMeta['memo'] !== ''): // このパートのカードのメモ ?><div class="tab-notes__song"><?= icon('tune') ?> <span class="tab-memo"><?= linkify_body($partMeta['memo']) ?></span></div><?php endif; ?>
        <?= attach_thumbs_html('song_meta', $partImages) ?>
        <div class="tab-memo" data-memo-view></div>
        <ul class="live-thumbs tab-imgs" data-tab-images></ul>
        <label class="btn btn--ghost btn--sm" data-img-add title="ファイルを追加" aria-label="ファイルを追加" hidden><?= icon('add_photo_alternate') ?>
            <input type="file" multiple hidden data-img-input></label>
    </details>

    <?php /* ===== 準備ステップ（編集モードのときだけ）: ① 音源 → ② テンポ → ③ 開始位置 → ④ 入力 =====
       初めての人が「何から手を付けるか」で迷わないよう、編集画面の上に手順を出す（プログレッシブ・ディスクロージャー =
       最初から全部の設定を見せず、いま必要なものだけを順に出す、という UI の定番の考え方）。
       ・各ステップは見出しのボタンを押すと開く（アコーディオン = 1つ開くと他は閉じる）。完了したステップは ✓
       ・全部終わっている TAB は、1 行（準備 ●●●●）に畳んで出す
       ・位置は、ヘッダー・メモのすぐ下 = 動画（下の [data-video]）の上。③ の開始位置合わせは、すぐ下の動画を見ながらできる
       ・data-edit-only … 編集モードのときだけ見せる印（tab.css）。練習モードでは消える
       ・入力欄（data-set="…"）は、以前は下の「設定」カードにあったものを移しただけ。JS は root の中から探すので、場所が変わっても動く
       ・ステップの完了の判定・要約の文字・開閉は assets/tab.js の updateSetup() / openStep() */ ?>
    <details class="card tab-setup" data-setup data-edit-only>
        <summary class="tab-summary tab-setup__summary">
            <?= icon('checklist') ?> <b>準備</b>
            <span class="tab-setup__dots" data-setup-dots role="img" aria-label=""></span><?php // 4つの丸（JS が作る。完了したぶん塗る） ?>
            <?= icon('expand_more', 'tab-summary__chev') ?>
        </summary>
        <ol class="tab-steps">
            <li class="tab-step" data-step="src">
                <button type="button" class="tab-step__head" aria-expanded="false" aria-controls="tab-step-src">
                    <span class="tab-step__mark" data-step-mark>1</span>
                    <span class="tab-step__title">音源</span>
                    <span class="tab-step__sum muted small" data-step-sum></span>
                    <?= icon('expand_more', 'tab-step__chev') ?>
                </button>
                <div class="tab-step__body" id="tab-step-src" hidden>
                    <label class="field">YouTube
                        <span class="tab-settings__row">
                            <input type="url" data-set="youtube" maxlength="500" placeholder="https://youtu.be/…">
                            <?php if ((string)(config('youtube')['api_key'] ?? '') !== ''): ?>
                                <button type="button" class="icon-btn" data-search-open="yt_search" aria-label="動画検索" title="動画検索"><?= icon('search') ?></button>
                            <?php endif; ?>
                        </span>
                    </label>
                    <div class="tab-step__actions">
                        <button type="button" class="btn" data-setup-skip-src>音源なし</button>
                        <button type="button" class="btn btn--primary" data-setup-next disabled>次へ</button>
                    </div>
                </div>
            </li>
            <li class="tab-step" data-step="tempo">
                <button type="button" class="tab-step__head" aria-expanded="false" aria-controls="tab-step-tempo">
                    <span class="tab-step__mark" data-step-mark>2</span>
                    <span class="tab-step__title">テンポ</span>
                    <span class="tab-step__sum muted small" data-step-sum></span>
                    <?= icon('expand_more', 'tab-step__chev') ?>
                </button>
                <div class="tab-step__body" id="tab-step-tempo" hidden>
                    <div class="tab-step__fields">
                        <label class="field tab-step__wide">BPM
                            <span class="tab-settings__row">
                                <input type="number" data-set="bpm" min="20" max="400" step="0.01" inputmode="decimal">
                                <!-- BPM 計測: 曲に合わせて4回以上タップ（小節の頭を合わせる「タップ」とは別） -->
                                <button type="button" class="icon-btn" data-bpm-tap aria-label="BPM計測" title="BPM計測（4回以上タップ）"><?= icon('speed') ?></button>
                                <!-- 自動検知: 手元のファイルを開いているときだけ（YouTube の音はブラウザから読めない） -->
                                <button type="button" class="icon-btn" data-bpm-detect aria-label="BPM自動検知" title="BPM自動検知（ファイル）" disabled><?= icon('graphic_eq') ?></button>
                                <?php // AI 推定: 記憶による推定なので、候補として出して、押したときだけ反映する ?>
                                <?php if (claude_enabled()): ?>
                                    <button type="button" class="icon-btn" data-search-open="bpm_ai" aria-label="BPM AI推定" title="BPM AI推定"><?= icon('auto_awesome') ?></button>
                                <?php endif; ?>
                            </span>
                            <?php // 微調整: 押すたびに加減。押し続けると連続で変わる（assets/tab.js の BPM 微調整） ?>
                            <span class="tab-bpm-steps" role="group" aria-label="BPM調整">
                                <?php foreach ([-1, -0.1, 0.1, 1] as $n): ?>
                                    <button type="button" class="tab-chip" data-bpm-step="<?= $n ?>"><?= $n > 0 ? '+' : '−' ?><?= abs($n) ?></button>
                                <?php endforeach; ?>
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
                    </div>
                    <div class="tab-step__actions"><button type="button" class="btn btn--primary" data-setup-next>次へ</button></div>
                </div>
            </li>
            <li class="tab-step" data-step="start">
                <button type="button" class="tab-step__head" aria-expanded="false" aria-controls="tab-step-start">
                    <span class="tab-step__mark" data-step-mark>3</span>
                    <span class="tab-step__title">開始位置</span>
                    <span class="tab-step__sum muted small" data-step-sum></span>
                    <?= icon('expand_more', 'tab-step__chev') ?>
                </button>
                <div class="tab-step__body" id="tab-step-start" hidden>
                    <p class="tab-step__hint muted small">1小節目の頭で止めて <?= icon('my_location') ?></p>
                    <?php // ミニ操作: ボタンは下の操作バーと同じ data-seek / data-play なので、root のクリック処理（assets/tab.js）がそのまま動かす ?>
                    <?php // 波形: 手元のファイルのときだけ（YouTube の音はブラウザから読めない）。縦線（開始位置）は固定で、波形を左右にドラッグして合わせる。タップした所を開始位置にする ?>
                    <div class="tab-wave" data-wave hidden>
                        <canvas class="tab-wave__canvas" data-wave-canvas aria-label="波形"></canvas>
                        <span class="tab-wave__tools">
                            <button type="button" class="icon-btn icon-btn--sm" data-wave-zoom="-1" aria-label="縮小" title="縮小"><?= icon('zoom_out') ?></button>
                            <button type="button" class="icon-btn icon-btn--sm" data-wave-zoom="1" aria-label="拡大" title="拡大"><?= icon('zoom_in') ?></button>
                            <button type="button" class="icon-btn icon-btn--sm" data-wave-detect aria-label="無音検知" title="無音検知（音の出始めを開始位置に）"><?= icon('graphic_eq') ?></button>
                        </span>
                    </div>
                    <?php // 開始位置そのものを動かす（再生中なら、動かすたびに開始位置の少し前から鳴らし直す。メトロノームと聴き比べて合わせる） ?>
                    <div class="tab-step__mini">
                        <button type="button" class="btn" data-offset-nudge="-0.1" aria-label="開始位置 -0.1秒">-0.1</button>
                        <button type="button" class="btn" data-offset-nudge="-0.01" aria-label="開始位置 -0.01秒">-0.01</button>
                        <button type="button" class="icon-btn icon-btn--play" data-offset-play aria-label="開始位置から確認" title="開始位置から確認（メトロノーム付き）"><?= icon('start') ?></button>
                        <button type="button" class="icon-btn icon-btn--play" data-play aria-label="再生" title="再生（Space）"><?= icon('play_arrow', 'icon--fill') ?></button>
                        <button type="button" class="btn" data-offset-nudge="0.01" aria-label="開始位置 +0.01秒">+0.01</button>
                        <button type="button" class="btn" data-offset-nudge="0.1" aria-label="開始位置 +0.1秒">+0.1</button>
                    </div>
                    <label class="field">開始位置（秒）
                        <span class="tab-settings__row">
                            <input type="number" data-set="offset" min="0" step="0.01" inputmode="decimal">
                            <button type="button" class="icon-btn" data-offset-now aria-label="現在位置" title="現在位置"><?= icon('my_location') ?></button>
                        </span>
                    </label>
                    <div class="tab-step__actions"><button type="button" class="btn btn--primary" data-setup-next>次へ</button></div>
                </div>
            </li>
            <li class="tab-step" data-step="input">
                <button type="button" class="tab-step__head" aria-expanded="false" aria-controls="tab-step-input">
                    <span class="tab-step__mark" data-step-mark>4</span>
                    <span class="tab-step__title">入力</span>
                    <span class="tab-step__sum muted small" data-step-sum></span>
                    <?= icon('expand_more', 'tab-step__chev') ?>
                </button>
                <div class="tab-step__body" id="tab-step-input" hidden>
                    <div class="tab-step__actions">
                        <button type="button" class="btn" data-import-open><?= icon('upload_file') ?> インポート</button>
                        <button type="button" class="btn btn--primary" data-setup-go-input><?= icon('keyboard') ?> 入力へ</button>
                    </div>
                </div>
            </li>
        </ol>
    </details>

    <?php // 詳細設定: 準備ステップに入らない項目（パート名・メモ・開始小節と部分・カポ・チューニング・リンク）。普段は触らないので閉じておく ?>
    <details class="card tab-more" data-edit-only>
        <summary class="tab-summary"><?= icon('tune') ?> 詳細設定 <?= icon('expand_more', 'tab-summary__chev') ?></summary>
        <div class="tab-settings">
            <label class="field tab-settings__wide">パート <input type="text" data-set="part" maxlength="20"></label>
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
            <label class="field tab-settings__wide">メモ
                <textarea data-set="memo" rows="3" maxlength="<?= TAB_MEMO_MAX ?>"></textarea>
            </label>
            <!-- 参考にする TAB サイトなどのリンク（最大10個。行は JS が作る） -->
            <div class="field tab-settings__wide">リンク
                <div class="tab-links-edit" data-links-edit></div>
                <button type="button" class="icon-btn icon-btn--sm" data-link-add aria-label="リンク追加" title="リンク追加"><?= icon('add_link') ?></button>
            </div>
        </div>
    </details>

    <!-- 音源: YouTube の埋め込み / 手元のファイル / なし（アイコンで切り替え） -->
    <?php // つまみ（ドラッグで移動）・右下の角（ドラッグで大きさ）・元の位置へ。動きは assets/tab.js の setupVideoBox ?>
    <div class="tab-video" data-video hidden>
        <div data-video-mount></div>
        <button type="button" class="tab-video__btn tab-video__drag" data-video-drag aria-label="移動" title="移動"><?= icon('drag_indicator') ?></button>
        <button type="button" class="tab-video__btn tab-video__reset" data-video-reset aria-label="位置を復元" title="位置を復元" hidden><?= icon('restore') ?></button>
        <span class="tab-video__size" data-video-size aria-label="大きさ" title="大きさ"></span>
    </div>

    <!-- 操作バー（アイコンのみ。意味は title / aria-label で伝える） -->
    <div class="tab-bar card">
        <div class="tab-bar__group" role="group" aria-label="音源">
            <button type="button" class="icon-btn" data-src="yt" aria-label="YouTube" title="YouTube"><?= youtube_icon() ?></button>
            <button type="button" class="icon-btn" data-src="file" aria-label="ファイル" title="ファイル"><?= icon('audio_file') ?></button>
            <button type="button" class="icon-btn" data-src="none" aria-label="音源なし" title="音源なし"><?= icon('timer') ?></button>
            <!-- 同期解除: 音源は止めて、今の位置から内部時計で進む（好きな BPM で練習できる） -->
            <button type="button" class="icon-btn" data-unsync data-practice-only aria-pressed="false" aria-label="同期解除" title="同期解除"><?= icon('link') ?></button>
            <!-- ファイルはサーバーに送らない。ブラウザの中で再生するだけ -->
            <input type="file" accept="audio/*,video/*" data-file hidden>
        </div>
        <div class="tab-bar__group">
            <button type="button" class="icon-btn" data-restart aria-label="最初から" title="最初から再生"><?= icon('skip_previous') ?></button>
            <button type="button" class="icon-btn" data-seek="-5" aria-label="5秒戻る" title="5秒戻る（←）"><?= icon('replay_5') ?></button>
            <button type="button" class="icon-btn icon-btn--play" data-play aria-label="再生" title="再生（Space）"><?= icon('play_arrow', 'icon--fill') ?></button>
            <button type="button" class="icon-btn" data-seek="5" aria-label="5秒進む" title="5秒進む（→）"><?= icon('forward_5') ?></button>
        </div>
        <!-- 練習BPM: 元の BPM との比が再生速度になる。×0.5… は BPM を入れるショートカット -->
        <div class="tab-bar__group" role="group" aria-label="速度" data-practice-only>
            <?php foreach ([0.5, 0.75, 0.9, 1] as $r): ?>
                <button type="button" class="tab-chip<?= $r === 1 ? ' is-active' : '' ?>" data-rate="<?= $r ?>">×<?= $r ?></button>
            <?php endforeach; ?>
            <button type="button" class="icon-btn icon-btn--sm" data-pbpm-step="-5" aria-label="BPM -5" title="BPM -5"><?= icon('remove') ?></button>
            <?php // 入力欄・セレクトは ::after が付かないので、説明の文字は外側の span（data-help）に出す（tab.css） ?>
            <span class="tab-help-field" data-help="練習 BPM"><input type="number" class="tab-pbpm" data-pbpm min="20" max="400" step="1" inputmode="decimal" aria-label="練習 BPM" title="練習 BPM"></span>
            <button type="button" class="icon-btn icon-btn--sm" data-pbpm-step="5" aria-label="BPM +5" title="BPM +5"><?= icon('add') ?></button>
        </div>
        <div class="tab-bar__group" role="group" aria-label="リピート" data-practice-only>
            <button type="button" class="tab-chip" data-ab="a" title="A 地点">A</button>
            <button type="button" class="tab-chip" data-ab="b" title="B 地点">B</button>
            <button type="button" class="icon-btn icon-btn--sm" data-ab="clear" aria-label="リピート解除" title="リピート解除（\）"><?= icon('repeat_on') ?></button>
            <!-- ループごとに BPM を上げる（元の BPM まで） -->
            <span class="tab-help-field" data-help="ループごとに BPM 増加"><select class="tab-loop-step" data-loop-step aria-label="ループごとの BPM 増加" title="ループごとの BPM 増加">
                <?php foreach ([0, 2, 5, 10] as $n): ?><option value="<?= $n ?>"><?= $n === 0 ? '+0' : '+' . $n ?></option><?php endforeach; ?>
            </select></span>
        </div>
        <div class="tab-bar__group" data-practice-only>
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
    <ul class="tab-markers" data-markers data-practice-only></ul>

    <!-- ===== 編集（編集モードのときだけ） ===== -->
    <section class="tab-edit" data-edit hidden>
        <!-- 曲のマーカー: 選んだ小節（なければ再生中の小節）の頭から、次にマーカーが付く小節の手前までがそのセクション -->
        <?php // 先頭のラベルのアイコンで「小節にセクション名（イントロ・サビ…）を付けるチップ」だと分かるようにする。チップは JS がこのアイコンの後ろに足す ?>
        <div class="tab-secrow">
            <div class="tab-secpick" data-secpick role="group" aria-label="セクション" title="小節にセクション名を付ける"><?= icon('label', 'tab-secpick__icon') ?></div>
            <button type="button" class="icon-btn icon-btn--sm" popovertarget="tab-howto-sec" aria-label="セクションの使い方" title="使い方"><?= icon('help') ?></button>
        </div>
        <?php // 使い方のポップアップ（HTML の popover。外側を押すと閉じる） ?>
        <div class="tab-howto" id="tab-howto-sec" popover>
            <b><?= icon('label') ?> セクション</b>
            <ol>
                <li>小節を選択（未選択なら再生中の小節）</li>
                <li>イントロ・サビなどを押すと、その小節からセクション開始。再度押すと解除</li>
                <li>「+小節」に数を入れてから押すと、後ろにそのセクションの空き小節を挿入</li>
            </ol>
            <p>次のセクションの手前までが同じセクション。小節見出しの欄に直接入力も可</p>
        </div>
        <div class="tab-howto" id="tab-howto-tap" popover>
            <b><?= icon('touch_app') ?> タップ</b>
            <p>音源と小節の位置を合わせる機能</p>
            <ol>
                <li>合わせ始める小節を選択して「タップ」をON</li>
                <li>音源を再生</li>
                <li>小節の頭ごとに「T」を押す（光っている小節から順に記録）</li>
            </ol>
            <p>記録は小節見出しの <?= icon('touch_app') ?> で解除</p>
        </div>

        <div class="tab-tools">
            <button type="button" class="icon-btn" data-undo aria-label="元に戻す" title="元に戻す（Ctrl+Z）" disabled><?= icon('undo') ?></button>
            <button type="button" class="icon-btn" data-redo aria-label="やり直す" title="やり直す（Ctrl+Y）" disabled><?= icon('redo') ?></button>
            <button type="button" class="icon-btn" data-import-open aria-label="インポート" title="インポート（ASCII TAB・画像）"><?= icon('upload_file') ?></button>
            <button type="button" class="tab-chip tab-chip--tap" data-tap-mode aria-pressed="false" title="小節の頭をタップして位置を補正"><?= icon('touch_app') ?> タップ</button>
            <button type="button" class="btn btn--primary tab-tap-btn" data-tap hidden><?= icon('touch_app') ?> T</button>
            <button type="button" class="icon-btn icon-btn--sm" popovertarget="tab-howto-tap" aria-label="タップの使い方" title="使い方"><?= icon('help') ?></button>
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

    <!-- 検索の候補（動画 / BPM）: 開くと JS が api_tab_save の yt_search / bpm_ai で一覧を作る。押した候補を設定欄に入れる（保存はまだ） -->
    <dialog class="modal" data-search aria-labelledby="tab-search-title">
        <div class="modal__body">
            <h3 id="tab-search-title" class="modal__title"><?= icon('search') ?> <span data-search-title></span></h3>
            <p class="small muted" data-search-status role="status"></p>
            <ul class="tab-search" data-search-list></ul>
            <div class="form-actions"><button type="button" class="btn btn--ghost" data-search-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button></div>
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
            <?php // 編集を開くと、画面の上に「準備」のステップが出る（上の <details data-setup>）。流れもその順番に合わせる。番号は ①〜④ を文字で書くので、<ol> の自動の番号は消す（tab.css） ?>
            <ol class="tab-guide__steps">
                <li><b><?= icon('edit') ?> 編集</b>を開くと、上に<b>準備</b>が出る</li>
                <li><b>① 音源</b>: YouTube の URL（無ければ「音源なし」）</li>
                <li><b>② テンポ</b>: BPM・拍子・分割</li>
                <li><b>③ 開始位置</b>: 1小節目の頭で止めて <?= icon('my_location') ?></li>
                <li><b>④ 入力</b>: マスを選んで<b>画面内キーボード</b>で入力</li>
                <li><b><?= icon('save') ?> 保存</b> → <b><?= icon('school') ?> 練習</b></li>
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
                <li><?= icon('upload_file') ?><span>インポート: 文字・画像の TAB を取込</span></li>
                <li><?= icon('touch_app') ?><span>タップ: 再生しながら小節の頭で T を押して位置を補正</span></li>
                <li><?= icon('speed') ?><span>BPM 計測: 曲に合わせて4回以上タップ</span></li>
                <li><?= icon('search') ?><?= icon('graphic_eq') ?><span>動画・BPM の検索 / ファイルから BPM 自動検知</span></li>
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
