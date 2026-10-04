<?php
/** @var string $pageTitle @var string $activeNav */
$user = current_user();
$nav = [
    'lives'   => ['index.php', 'ライブ'],
    'members' => ['members.php', 'メンバー'],
    'import'  => ['import.php', '取り込み'],
];
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0c0c10">
    <title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
    <link rel="icon" href="../src/assets/favicons/96.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=1">
    <script src="assets/app.js?v=1" defer></script>
</head>
<body>
<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="index.php"><span class="brand__dot"></span>AbbeyRoad<span class="brand__tld">.online</span></a>
        <?php if ($user): ?>
        <nav class="nav" aria-label="メイン">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>" class="nav__link<?= $activeNav === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <details class="usermenu">
            <summary aria-label="アカウント"><span class="avatar"><?= h(mb_substr($user['name'], 0, 1)) ?></span></summary>
            <div class="usermenu__panel">
                <div class="usermenu__name"><?= h($user['name']) ?><?= $user['admin'] ? ' <span class="tag">管理者</span>' : '' ?></div>
                <?php if (!empty($user['member_id'])): ?>
                    <a href="member.php?id=<?= (int)$user['member_id'] ?>">マイページ</a>
                <?php endif; ?>
                <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit" class="linkbtn">ログアウト</button></form>
            </div>
        </details>
        <?php endif; ?>
    </div>
</header>
<main class="container">
<?php foreach (take_flashes() as $f): ?>
    <div class="flash flash--<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div>
<?php endforeach; ?>
