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
    'artists' => ['artists.php', 'アーティスト'],
    'stats'   => ['stats.php', '集計'],
    'import'  => ['import.php', '新規追加'], // 取り込み・手入力の入口（partials/add_tabs.php のタブで切り替え）
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
    <link rel="icon" href="../assets/favicons/240.png" sizes="any">
    <link rel="icon" type="image/png" href="../assets/favicons/48.png" sizes="48x48">
    <link rel="apple-touch-icon" href="../assets/favicons/240.png" sizes="240x240">
    <!--
        ライト / ダークの切り替え。
        CSS が読み込まれる「前」に <html data-theme="..."> を決めておかないと、
        一瞬白く光ってから黒くなる（ちらつく）ので、ここだけ <head> の中に直接書いている。
        保存先は localStorage（ブラウザごとの保存領域）。無ければライト（白）で始める。
    -->
    <script>
        (function () {
            var theme = null;
            try { theme = localStorage.getItem('theme'); } catch (e) {}
            if (theme !== 'light' && theme !== 'dark') {
                theme = 'light';
            }
            document.documentElement.dataset.theme = theme;
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;900&display=swap" rel="stylesheet">
    <!-- アイコン用フォント（Material Symbols Rounded）。lib/bootstrap.php の icon() で使う。
         display=block: 読み込み中にアイコン名の英単語（edit など）が一瞬見えるのを防ぐ -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400..600,0..1,0&display=block" rel="stylesheet">
    <!-- ?v=2 はキャッシュ対策。CSS を変えたら数字を上げると、ブラウザが古い CSS を使い続けない -->
    <link rel="stylesheet" href="assets/app.css?v=138">
    <script src="assets/app.js?v=75" defer></script>
</head>
<body>
<header class="topbar">
    <div class="topbar__inner">
        <!--
            ロゴは元の m6 のヘッダーと同じ src/assets/online.png。
            ダークモードでは「.Online」の黒文字が背景に溶けるので、文字だけ白くした logo-dark.png に CSS で切り替える。
        -->
        <a class="brand" href="index.php" aria-label="AbbeyRoad.online トップへ">
            <img src="assets/online.png" alt="AbbeyRoad.online" class="brand__logo brand__logo--light" width="146" height="40">
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
            <?= icon('search') ?>
        </a>
        <?php endif; ?>
        <!-- テーマ切り替えボタン（中身のアイコンは CSS で太陽/月を出し分け） -->
        <button type="button" class="icon-btn" data-theme-toggle aria-label="ライト/ダーク切り替え" title="ライト/ダーク切り替え">
            <?= icon('light_mode', 'icon-sun') ?>
            <?= icon('dark_mode', 'icon-moon') ?>
        </button>
        <?php if ($user): ?>
        <!-- <details> はクリックで開閉する HTML 標準の部品。JS なしでメニューが作れる -->
        <details class="usermenu">
            <!-- 検索ボタンと同じ見た目の丸いボタンに、人型のアイコン（person）を出す -->
            <summary class="icon-btn" aria-label="アカウント" title="アカウント"><?= icon('person') ?></summary>
            <div class="usermenu__panel">
                <div class="usermenu__name"><?= h($user['name']) ?><?= $user['admin'] ? ' <span class="tag">管理者</span>' : '' ?></div>
                <?php if (!empty($user['member_id'])): ?>
                    <a href="member.php?id=<?= (int)$user['member_id'] ?>">マイページ</a>
                <?php else: ?>
                    <!-- メンバー未紐付けだとマイページが無い。紐付けは管理者がユーザー管理で行う（アカウント設定に案内を出している） -->
                    <a href="account.php">マイページ <span class="muted small">（管理者の紐付け待ち）</span></a>
                <?php endif; ?>
                <a href="account.php">アカウント設定</a>
                <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit" class="linkbtn">ログアウト</button></form>
                <?php if ($user['admin']): ?>
                    <!-- 管理者専用のメニューは単色の背景の枠でまとめて、一般メニューと見分けられるようにする -->
                    <div class="usermenu__admin">
                        <div class="usermenu__admin-label">管理者専用メニュー</div>
                        <a href="users.php">ユーザー管理</a>
                        <a href="members_merge.php">メンバーの統合</a>
                        <a href="members_entry.php">メンバープロフィールの一括編集</a>
                        <a href="masters.php">会場・日程名・楽器・係の管理</a>
                    </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
</header>
<!-- お知らせ（「更新しました」など）はヘッダーの下に固定で出し、4秒で消す（assets/app.js の setupToasts） -->
<div class="toasts" data-toasts aria-live="polite">
<?php foreach (take_flashes() as $f): ?>
    <div class="flash flash--<?= h($f['type']) ?> toast" role="status"><?= nl2br(h($f['message'])) ?></div>
<?php endforeach; ?>
</div>
<main class="container">
