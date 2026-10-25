<?php
/*
 * アルバムのモーダル（フッターに1個だけ置く。開くのは assets/app.js の setupAlbumModal）
 *   [data-album-open] を押すと、暗転した画面に アルバム名・発売年 → 音楽アプリのアイコン・検索・閉じる を出す。
 *   押された要素の data-album-* から中身を入れる（album_open_attrs で作る）。
 *   アイコンは <template> に4アプリぶん入れておき、data-album-app のものを JS が写す。
 */
require_once __DIR__ . '/../lib/albums.php';
?>
<dialog class="album-modal" data-album-modal aria-label="アルバム">
    <img class="album-modal__art" data-album-art alt="" hidden>
    <strong class="album-modal__name" data-album-name></strong>
    <span class="album-modal__year" data-album-year></span>
    <div class="album-modal__bar">
        <a class="album-modal__app" data-album-listen href="#" target="_blank" rel="noopener noreferrer"></a>
        <a class="icon-btn album-modal__btn" data-album-search href="search" aria-label="検索" title="検索"><?= icon('search') ?></a>
        <button type="button" class="icon-btn album-modal__btn" data-album-close aria-label="閉じる" title="閉じる"><?= icon('close') ?></button>
    </div>
    <template data-album-icons>
        <?php foreach (MUSIC_APP_ICONS as $app => $svg): ?>
            <span data-app="<?= h($app) ?>" data-label="<?= h(MUSIC_APPS[$app]) ?>"><?= $svg ?></span>
        <?php endforeach; ?>
    </template>
</dialog>
