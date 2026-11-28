<?php
/**
 * =====================================================================
 *  player.php — シャッフル再生の外枠
 * =====================================================================
 *  ページを移っても音が切れないように、サイトは全面の <iframe> の中で動かし、この外枠の下のバーで鳴らす。
 *  中のページは今までどおり（リンク・保存・戻る）。外枠ごと読み直したとき（更新・URL を打つ）だけ止まる。
 *    ?scope=all | live | band | member | artist  &id=ライブ/バンド/メンバー/アーティストの ID  … 流す範囲（api_shuffle.php）
 *    ?resume=1 … 前に止めた曲・位置から（範囲は残したもの。× で閉じたあとヘッダーのアイコンで開くとき）
 *    ?u=中のページ（band?id=3 など。このサイトの中のページ名だけ）
 *  iframe に入れられるのは同じサイトからだけ（lib/bootstrap.php の frame-ancestors 'self'）
 *  動きは assets/player.js。曲の区切りは音源のチャプター（migrations/130・152）
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/drive.php'; // mix_tag
require_login();

$scope = in_array($_GET['scope'] ?? '', ['live', 'band', 'member', 'artist'], true) ? (string)$_GET['scope'] : 'all';
$id = $scope === 'all' ? (((int)($_GET['id'] ?? 0)) === -1 ? -1 : 0) : (int)($_GET['id'] ?? 0); // all の -1 = 絞り込みで選んだライブだけ（どのチップでもない）
// 中のページ: このサイトのページ名（とクエリ）だけ。外のサイト・//・player 自身は入れない
$u = (string)($_GET['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]*(\.php)?(\?[^#\s]*)?$/', $u) || preg_match('/^player\b/', $u)) {
    $u = '';
}
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h(APP_NAME) ?></title>
    <meta name="theme-color" content="#f6f5f1">
    <link rel="icon" href="assets/favicons/240.png?v=3" sizes="any"><!-- partials/header.php と同じ（変えたら両方） -->
    <link rel="icon" type="image/png" href="assets/favicons/48.png?v=3" sizes="48x48">
    <link rel="apple-touch-icon" href="assets/favicons/apple-touch-icon.png?v=2" sizes="180x180">
    <script>
        // テーマは中のページと同じ（partials/header.php と同じ読み方。中で切り替えたら player.js が写す）
        (function () {
            var t = null;
            try { t = localStorage.getItem('theme'); } catch (e) {}
            document.documentElement.dataset.theme = t === 'dark' ? 'dark' : 'light';
        })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400..600,0..1,0&icon_names=<?= h(implode(',', require __DIR__ . '/lib/icon_names.php')) ?>&display=block" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('assets/app.css') ?>">
    <?php if (($instCss = instrument_color_css()) !== ''): ?><style><?= $instCss ?></style><?php endif; // 演奏者の楽器ラベルの色（partials/header.php と同じ） ?>
    <script src="<?= asset_url('assets/app_player.js') ?>" defer></script><!-- audioProbeDuration だけ使う（関数の定義だけで、何も動かさない） -->
    <script src="<?= asset_url('assets/player.js') ?>" defer></script>
    <link rel="preconnect" href="https://www.youtube.com"><link rel="preconnect" href="https://i.ytimg.com"><!-- YouTube の曲の読み込みを早める -->
</head>
<body class="shell">
<iframe class="shell__frame" data-shell-frame name="site" src="<?= h($u !== '' ? $u : './') ?>" title="AbbeyRoad.online"></iframe>
<div class="shell-bar" data-shell>
    <!-- 再生中の曲のポップアップ（下のバーの曲名か、中のヘッダーの丸に再生のアイコンで開く） -->
    <div class="shell-panel" data-shell-panel role="dialog" aria-label="再生中の曲" hidden>
        <div class="shell-panel__video" data-shell-yt hidden></div><!-- YouTube の曲のときの動画（player.js の yt） -->
        <div class="shell-panel__head">
            <div class="shell-panel__art"><img data-shell-art alt="" width="104" height="104" hidden><?= icon('music_note', 'icon--fill') ?></div>
            <div class="shell-panel__meta">
                <div class="shell-panel__liveline"><a class="shell-panel__live" data-shell-live target="site"></a><?= mix_tag() // 流しているのはミックス（YouTube はまだ流さない） ?></div>
                <a class="shell-panel__band" data-shell-bandlink target="site"></a>
                <strong class="shell-panel__song" data-shell-song></strong>
                <div class="shell-panel__artistline"><a class="shell-panel__artist" data-shell-artist target="site"></a><span class="shell-panel__album" data-shell-album></span></div>
            </div>
        </div>
        <ul class="shell-panel__players" data-shell-players></ul>
        <div class="audio-player__seek" data-shell-seekbox><div class="audio-player__bubbles" data-shell-bubbles></div><input type="range" class="audio-player__range" data-shell-seek min="0" max="0" step="1" value="0" aria-label="再生位置"></div>
        <div class="shell-panel__time"><span data-shell-cur>0:00</span><span data-shell-dur>0:00</span></div>
        <div class="shell-panel__ctl">
            <button type="button" class="icon-btn shell-panel__shuffle" data-shell-shuffle aria-pressed="true" aria-label="シャッフル" title="シャッフル"><?= icon('shuffle') ?></button>
            <button type="button" class="icon-btn" data-shell-prev aria-label="前の曲" title="前の曲"><?= icon('skip_previous', 'icon--fill') ?></button>
            <button type="button" class="audio-player__play" data-shell-play aria-label="再生" title="再生"><?= icon('play_arrow', 'icon--fill') ?></button>
            <button type="button" class="icon-btn" data-shell-next aria-label="次の曲" title="次の曲"><?= icon('skip_next', 'icon--fill') ?></button>
            <span class="shell-panel__right">
                <button type="button" class="icon-btn shell-panel__cont" data-shell-cont aria-pressed="false" aria-label="曲間も再生" title="曲間も再生"><?= icon('start') ?></button>
                <button type="button" class="icon-btn shell-panel__unit" data-shell-unit aria-label="曲単位" title="曲単位"><?= icon('music_note') ?><?= icon('library_music') ?></button>
            </span>
        </div>
        <div class="shell-panel__subhead">再生範囲を変更
            <span class="shell-panel__srcs"><!-- 流す音源（player.js の filter.src。押すとすぐ読み直す） -->
                <button type="button" class="icon-btn shell-panel__later" data-shell-later aria-pressed="false" aria-label="次の曲から範囲を変える" title="次の曲から範囲を変える"><?= icon('queue_music') ?></button>
                <button type="button" class="shell-panel__src" data-shell-src="mix" aria-pressed="true" aria-label="MIX" title="MIX"><?= mix_tag() ?></button>
                <button type="button" class="shell-panel__src" data-shell-src="yt" aria-pressed="true" aria-label="YouTube" title="YouTube"><?= youtube_icon() ?></button>
                <button type="button" class="icon-btn shell-panel__filter" data-shell-filter aria-pressed="false" aria-label="絞り込み" title="絞り込み"><?= icon('filter_alt', 'icon--fill') ?></button>
            </span>
        </div>
        <ul class="shell-panel__scopes" data-shell-scopes></ul>
        <!-- 絞り込み: 年度ごとのライブ（player.js。閉じたら反映） -->
        <dialog class="modal shell-filter" data-shell-filter-dialog aria-labelledby="shell-filter-title">
            <form method="dialog" class="modal__body">
                <h3 id="shell-filter-title" class="modal__title">絞り込み</h3>
                <div class="shell-filter__years" data-shell-filter-years></div>
                <div class="form-actions"><button type="submit" class="btn btn--primary">閉じる</button></div>
            </form>
        </dialog>
    </div>
    <button type="button" class="icon-btn" data-shell-play aria-label="再生" title="再生"><?= icon('play_arrow', 'icon--fill') ?></button>
    <button type="button" class="icon-btn" data-shell-next aria-label="次の曲" title="次の曲"><?= icon('skip_next', 'icon--fill') ?></button>
    <div class="shell-bar__info">
        <button type="button" class="shell-bar__title" data-shell-title aria-haspopup="dialog"></button>
        <small class="shell-bar__band muted" data-shell-band></small>
        <div class="shell-bar__progress"><span data-shell-progress></span></div>
    </div>
    <button type="button" class="icon-btn" data-shell-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
    <audio data-shell-audio preload="none"></audio>
    <div class="toasts shell-toasts" data-shell-toasts aria-live="polite"></div>
</div>
<script type="application/json" id="shell-data"><?= json_encode(['scope' => $scope, 'id' => $id, 'resume' => isset($_GET['resume']), 'admin' => is_admin()], JSON_HEX_TAG) ?></script>
</body>
</html>
