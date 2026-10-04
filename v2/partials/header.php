<?php
/**
 * =====================================================================
 *  partials/header.php — 全ページ共通の <head> とナビゲーション
 * =====================================================================
 *  render_header('タイトル', 'lives') から呼ばれる。
 *    $pageTitle : <title> に出す文字
 *    $activeNav : ナビのどれを「今いるページ」として光らせるか
 * =====================================================================
 */
/** @var string $pageTitle @var string $activeNav */
$user = current_user();
$nav = [
    'lives'   => ['index.php', 'ライブ'],
    'members' => ['members.php', 'メンバー'],
    'stats'   => ['stats.php', '集計'],
    'import'  => ['import.php', '取り込み'],
];
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- JavaScript の fetch() から CSRF トークンを送るために置いておく（assets/app.js） -->
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
    <!-- ファビコンは元の m6 と同じ画像を使う -->
    <link rel="icon" href="../src/assets/favicons/240.png" sizes="any">
    <link rel="icon" type="image/png" href="../src/assets/favicons/48.png" sizes="48x48">
    <link rel="apple-touch-icon" href="../src/assets/favicons/240.png" sizes="240x240">
    <!--
        ライト / ダークの切り替え。
        CSS が読み込まれる「前」に <html data-theme="..."> を決めておかないと、
        一瞬白く光ってから黒くなる（ちらつく）ので、ここだけ <head> の中に直接書いている。
        保存先は localStorage（ブラウザごとの保存領域）。無ければ OS の設定に合わせる。
    -->
    <script>
        (function () {
            var theme = null;
            try { theme = localStorage.getItem('theme'); } catch (e) {}
            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.dataset.theme = theme;
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;900&display=swap" rel="stylesheet">
    <!-- ?v=2 はキャッシュ対策。CSS を変えたら数字を上げると、ブラウザが古い CSS を使い続けない -->
    <link rel="stylesheet" href="assets/app.css?v=3">
    <script src="assets/app.js?v=3" defer></script>
</head>
<body>
<header class="topbar">
    <div class="topbar__inner">
        <!--
            ロゴは元の m6 のヘッダーと同じ src/assets/online.png。
            ダークモードでは「.Online」の黒文字が背景に溶けるので、文字だけ白くした logo-dark.png に CSS で切り替える。
        -->
        <a class="brand" href="index.php" aria-label="AbbeyRoad.online トップへ">
            <img src="../src/assets/online.png" alt="AbbeyRoad.online" class="brand__logo brand__logo--light" width="146" height="40">
            <img src="assets/logo-dark.png" alt="" class="brand__logo brand__logo--dark" width="146" height="40">
        </a>
        <?php if ($user): ?>
        <nav class="nav" aria-label="メイン">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>" class="nav__link<?= $activeNav === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <?php if ($user): ?>
        <a href="search.php" class="icon-btn<?= $activeNav === 'search' ? ' is-active' : '' ?>" aria-label="検索" title="検索">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        </a>
        <?php endif; ?>
        <!-- テーマ切り替えボタン（中身のアイコンは CSS で太陽/月を出し分け） -->
        <button type="button" class="icon-btn" data-theme-toggle aria-label="ライト/ダーク切り替え" title="ライト/ダーク切り替え">
            <svg class="icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            <svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        </button>
        <?php if ($user): ?>
        <!-- <details> はクリックで開閉する HTML 標準の部品。JS なしでメニューが作れる -->
        <details class="usermenu">
            <summary aria-label="アカウント"><span class="avatar"><?= h(mb_substr($user['name'], 0, 1)) ?></span></summary>
            <div class="usermenu__panel">
                <div class="usermenu__name"><?= h($user['name']) ?><?= $user['admin'] ? ' <span class="tag">管理者</span>' : '' ?></div>
                <?php if (!empty($user['member_id'])): ?>
                    <a href="member.php?id=<?= (int)$user['member_id'] ?>">マイページ</a>
                <?php endif; ?>
                <a href="account.php">アカウント設定</a>
                <?php if ($user['admin']): ?>
                    <a href="users.php">ユーザー管理</a>
                    <a href="members_merge.php">メンバーの統合</a>
                <?php endif; ?>
                <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit" class="linkbtn">ログアウト</button></form>
            </div>
        </details>
        <?php endif; ?>
    </div>
</header>
<main class="container">
<?php foreach (take_flashes() as $f): ?>
    <div class="flash flash--<?= h($f['type']) ?>" role="status"><?= nl2br(h($f['message'])) ?></div>
<?php endforeach; ?>
