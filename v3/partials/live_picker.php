<?php
/**
 * 「登録済みのライブと統合」のポップアップの中身（import.php / live_edit.php で共通）。
 * 1回だけ書いて、JS（assets/app.js の setupMergeToggle）が開くたびに複製して使う。
 *
 *   読み込む前に用意する変数:
 *     $lives         … lives_with_labels() の結果
 *     $excludeLiveId … 一覧から外すライブ（ライブ編集で「自分自身」に統合させない）。無ければ 0
 */
$excludeLiveId = $excludeLiveId ?? 0;
$pickLives = array_filter($lives, static fn($lv) => (int)$lv['live_id'] !== $excludeLiveId);
?>
<template id="live-picker">
    <div class="live-pop" role="listbox" aria-label="統合するライブ">
        <?php if (!$pickLives): ?><p class="live-pop__empty">登録済みのライブはまだありません</p><?php endif; ?>
        <?php $prevYear = null; foreach ($pickLives as $lv): ?>
            <?php if ($lv['fiscal_year'] !== $prevYear): $prevYear = $lv['fiscal_year']; ?>
                <p class="live-pop__year" data-year-head="<?= (int)$lv['fiscal_year'] ?>"><?= (int)$lv['fiscal_year'] ?>年度</p>
            <?php endif; ?>
            <button type="button" class="live-pop__item" role="option" data-live-option="<?= (int)$lv['live_id'] ?>"
                data-year="<?= (int)$lv['fiscal_year'] ?>" data-name="<?= h($lv['name']) ?>" data-labels="<?= h((string)$lv['labels']) ?>">
                <span class="live-pop__name"><?= h($lv['name']) ?></span>
                <small class="muted"><?= $lv['labels'] !== null ? '登録済み: ' . h($lv['labels']) : '日程なし' ?></small>
            </button>
        <?php endforeach; ?>
    </div>
</template>
