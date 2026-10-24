<?php
/**
 * =====================================================================
 *  lib/attach_image.php — 掲示板・お知らせ・バンドの添付画像
 * =====================================================================
 *  保存・縮小・検査は lib/live_image.php と同じ（ファイルも storage/live_images/ に置く）。
 *  ここは「どのテーブルに入れるか・何枚までか」の違いだけを持つ。
 *    board  … 書き込み（board_post）に1枚 / notice … お知らせに5枚 / band … バンドに5枚 / venue … 会場に7枚
 *  見せるときは attach_image.php が、見てよい人かを確かめてから出す。
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/live_image.php';

const ATTACH_KINDS = [
    'board'  => ['table' => 'board_post_image', 'owner' => 'post_id',   'max' => 1],
    'notice' => ['table' => 'notice_image',     'owner' => 'notice_id', 'max' => 5],
    'band'   => ['table' => 'band_image',       'owner' => 'band_id',   'max' => 5],
    'venue'  => ['table' => 'venue_image',      'owner' => 'venue_id',  'max' => 7],
    // TAB 譜（流れる TAB。tab.php）: パートごと / 曲ごと。TAB を削除しても画像は消さない（復元すると同じ tab_id で戻るため。外部キーなし）
    'song_tab'  => ['table' => 'song_tab_image',  'owner' => 'tab_id',  'max' => 10],
    'song_meta' => ['table' => 'song_meta_image', 'owner' => 'meta_id', 'max' => 10],
];

/** @return array 持ち主ID => [画像の行, ...]（表示順） */
function attach_images(PDO $pdo, string $kind, array $ownerIds): array
{
    $k = ATTACH_KINDS[$kind];
    if (!$ownerIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ownerIds), '?'));
    $st = $pdo->prepare("SELECT * FROM {$k['table']} WHERE {$k['owner']} IN ($in) ORDER BY sort_order, image_id");
    $st->execute(array_values($ownerIds));
    $by = [];
    foreach ($st as $r) {
        $by[(int)$r[$k['owner']]][] = $r;
    }
    return $by;
}

/** 画像の行のうち PDF の画像ID（TAB 譜の JS に渡す） */
function tab_pdf_ids(array $images): array
{
    return array_values(array_map(static fn($r) => (int)$r['image_id'],
        array_filter($images, static fn($r) => live_file_is_pdf((string)$r['file_name']))));
}

/** TAB 譜（song_tab / song_meta）は PDF・その他のファイルも受け付ける。画像として読めるものは画像、%PDF- は PDF、残りはそのまま保存 */
function live_attach_store(string $kind, array $up): array
{
    if ($kind !== 'song_tab' && $kind !== 'song_meta') {
        return live_image_store($up);
    }
    if (live_upload_is_pdf($up)) {
        return live_pdf_store($up);
    }
    $isImage = ($up['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name']) && @getimagesize($up['tmp_name']) !== false;
    return $isImage ? live_image_store($up) : live_other_store($up);
}

/** 画像の行のうち、その他のファイルの画像ID（プレビューは無くダウンロードだけ） */
function tab_file_ids(array $images): array
{
    return array_values(array_map(static fn($r) => (int)$r['image_id'],
        array_filter($images, static fn($r) => live_file_is_other((string)$r['file_name']))));
}

/** 画像を保存して DB に入れる。作ったファイル名を返す（呼ぶ側がエラー時に消せるように）。ダメなら RuntimeException */
function attach_add(PDO $pdo, string $kind, int $ownerId, array $uploads, ?int $userId = null, array &$newFiles = []): void
{
    $k = ATTACH_KINDS[$kind];
    $order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM {$k['table']} WHERE {$k['owner']} = " . $ownerId)->fetchColumn();
    foreach ($uploads as $up) {
        // PDF は TAB 譜（song_tab / song_meta）だけ
        $saved = live_attach_store($kind, $up);
        $newFiles[] = $saved['file_name'];
        if ($kind === 'band') {
            $pdo->prepare('INSERT INTO band_image (band_id, user_id, file_name, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$ownerId, $userId, $saved['file_name'], ++$order]);
        } else {
            $pdo->prepare("INSERT INTO {$k['table']} ({$k['owner']}, file_name, sort_order) VALUES (?, ?, ?)")
                ->execute([$ownerId, $saved['file_name'], ++$order]);
        }
    }
}

/** 持ち主ごとファイルを消す（行は ON DELETE CASCADE で消えるので、行を消す「前」に呼んでファイル名を集める） */
function attach_files_of(PDO $pdo, string $kind, array $ownerIds): array
{
    $files = [];
    foreach (attach_images($pdo, $kind, $ownerIds) as $rows) {
        foreach ($rows as $r) {
            $files[] = $r['file_name'];
        }
    }
    return $files;
}

/** 指定した画像を、その持ち主のものだけ消す。ファイル名の一覧を返す（コミット後に live_image_unlink する） */
function attach_remove(PDO $pdo, string $kind, int $ownerId, array $imageIds): array
{
    $k = ATTACH_KINDS[$kind];
    $files = [];
    foreach (attach_images($pdo, $kind, [$ownerId])[$ownerId] ?? [] as $im) {
        if (in_array((int)$im['image_id'], array_map('intval', $imageIds), true)) {
            $files[] = $im['file_name'];
            $pdo->prepare("DELETE FROM {$k['table']} WHERE image_id = ? AND {$k['owner']} = ?")->execute([(int)$im['image_id'], $ownerId]);
        }
    }
    return $files;
}

/** サムネイルの並び。押すとモーダルで大きく出る（assets/app.js の setupLiveImages）。ライブページと同じ見た目 */
function attach_thumbs_html(string $kind, array $images, callable $extra = null): string
{
    if (!$images) {
        return '';
    }
    $html = '<ul class="live-thumbs no-print">';
    foreach ($images as $im) {
        $id = (int)$im['image_id'];
        $html .= '<li><button type="button" class="live-thumbs__btn" data-live-img="' . $id . '"' . (live_file_is_pdf((string)$im['file_name']) ? ' data-live-pdf="1"' : (live_file_is_other((string)$im['file_name']) ? ' data-live-file="1"' : ''))
            . ' data-live-src="attach_image?k=' . h($kind) . '&amp;id=" aria-label="画像を表示">'
            . '<img src="attach_image?k=' . h($kind) . '&amp;id=' . $id . '&amp;t=1" alt="" loading="lazy"></button>' . ($extra ? $extra($im) : '') . '</li>';
    }
    return $html . '</ul>' . attach_modal_html();
}

/** 画像のモーダル（1ページに1つだけ出す） */
function attach_modal_html(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<dialog class="modal live-modal" data-live-modal><img alt="" data-live-modal-img><iframe title="PDF" hidden data-live-modal-pdf></iframe>'
        . '<p class="live-modal__bar"><span class="muted small" data-live-modal-taken></span>'
        . '<a class="btn btn--sm" href="#" download data-live-modal-dl aria-label="ダウンロード" title="ダウンロード">' . icon('download') . '</a>'
        . '<button type="button" class="btn btn--ghost btn--sm" data-live-modal-close aria-label="閉じる" title="閉じる">' . icon('close') . '</button></p></dialog>';
}

/**
 * 画像の欄: 添付済みのサムネイル（押して✕で削除にチェック）＋ 追加ボタン。name は images[] / image_remove[]
 * 上限は assets/app.js の setupLiveImages が数える（data-max）
 */
function attach_field_html(string $kind, array $images = [], ?int $max = null): string
{
    $html = '<div class="live-img-edit" data-live-img-edit data-max="' . ($max ?? ATTACH_KINDS[$kind]['max']) . '"><div class="live-img-edit__list">';
    foreach ($images as $im) {
        $id = (int)$im['image_id'];
        $html .= '<label class="live-img-edit__item" title="削除"><img src="attach_image?k=' . h($kind) . '&amp;id=' . $id . '&amp;t=1" alt="" loading="lazy">'
            . '<input type="checkbox" name="image_remove[]" value="' . $id . '" data-live-img-remove>'
            . '<span class="live-img-edit__x">' . icon('delete') . '</span></label>';
    }
    return $html . '</div><label class="btn btn--ghost btn--sm live-img-edit__add" title="画像を追加" aria-label="画像を追加">' . icon('add_photo_alternate')
        . '<input type="file" name="images[]" accept="image/*"' . (ATTACH_KINDS[$kind]['max'] > 1 ? ' multiple' : '') . ' hidden data-live-img-input></label>'
        . '<small class="merge-note" data-live-img-note></small></div>';
}
