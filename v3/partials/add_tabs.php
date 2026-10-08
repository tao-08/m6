<?php
/**
 * =====================================================================
 *  partials/add_tabs.php — 「新規追加」ページ共通の見出しとタブ
 * =====================================================================
 *  新規追加は3つのページに分かれているが、上にこのタブを出すことで1つのページのように見せる。
 *    file   … import.php      （タイムテーブル・名簿のファイルから取り込む）
 *    live   … live_edit.php   （ライブを手入力。id なし = 新規追加モード）
 *    member … member_new.php  （メンバーを手入力）
 *
 *  使い方: $addTab = 'live'; require __DIR__ . '/partials/add_tabs.php';
 * =====================================================================
 */
/** @var string $addTab */
$addTabs = [
    'file'   => ['import', 'ファイルから取り込む', 'タイムテーブルと名簿（CSV / PDF）をまとめてアップロードしてください。中身を自動で判別・照合し、登録前にプレビュー画面で編集できます。'],
    'live'   => ['live_edit', 'ライブを手入力', 'ファイルが無い場合はライブと日程を登録して、ライブページでバンドとメンバーを追加してください。'],
    'member' => ['member_new', 'メンバーを手入力', '名簿のファイルが無くても、ここから1人ずつ登録できます。バンドに登録するときは同じ表記の名前を入力してください。'],
];
?>
<section class="hero">
    <div>
        <p class="eyebrow">Add</p>
        <h1 class="display">新規追加</h1>
        <p class="muted"><?= h($addTabs[$addTab][2]) ?></p>
    </div>
</section>
<nav class="tabs tabs--static no-print" aria-label="追加の方法">
    <?php foreach ($addTabs as $key => [$href, $label]): ?>
        <a class="tab<?= $key === $addTab ? ' is-active' : '' ?>" href="<?= h($href) ?>"<?= $key === $addTab ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
</nav>
