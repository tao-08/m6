<?php
session_start();

// エラー表示
ini_set('display_errors', 1);
error_reporting(E_ALL);

if(empty($_SESSION["id"])){
	header("location:../login.php");
	exit;
}
require_once("DB_connect.php");
$pdo = DBconnect();
?>
<!doctype html>
<html lang="ja">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta charset="utf-8">
        
        <!-- リンクプレビュー -->
        <meta property="og:title" content="AbbeyRoad.online - アビーの自主製作データベースサイト" />
        <meta property="og:description" content="軽音楽アビーロードのライブデータベース・便利ツール集を配信（よてい）" />
        <meta property="og:type" content="website" />
        <meta property="og:url" content="https://www.abbeyroad.online" />
        <meta property="og:image" content="https://www.abbeyroad.online/src/assets/1200-630.png" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:site" content="@meiji_abbeyroad" />

        <!-- ファビコン設定 -->
        <link rel="icon" href="/src/assets/favicons/240.png" sizes="any">
        <link rel="icon" type="image/png" href="/src/assets/favicons/48.png" sizes="48x48">
        <link rel="apple-touch-icon" href="/src/assets/favicons/240.png" sizes="240x240">
        <link rel="manifest" href="/manifest.json">
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:site" content="@YourTwitterID" />
        
        <!-- タイトル -->
        <title>AbbeyRoad<?php if(!empty($page_title)){echo " - ".$page_title;}?></title>
        
        <!-- CSS 共通+ヘッダー+ページ固有-->
        <link rel="stylesheet" href="css/style.css?v=<?= date("Y:m:d:t H:i:s")?>">
        <link rel="stylesheet" href="/src/component/header.css">
        <link rel="stylesheet" href="/css/<?php if(!empty($css)){echo $css;}?>.css">
    </head>
    <body>
        <header class="site_header">
            <div class="drop_hover logo__box"><a href="/index.php"><img src="/src/assets/online.png" alt="AbbeyRoad.online" class="logo"></a></div>
            <div class="header__box">
                <div class="header__card"><a href="/data_upload.php">ライブデータ登録</a></div>
                <div class="header__card"><a href="database_live.php">ライブデータベース《準備中》</a></div>
                <div class="header__card"><a href="database_live.php">ツール《準備中》</a></div>
            </div>
            <div class="toggle_and_menu">
                <div class="user" id="user">
                    ログイン：<?=$_SESSION["name"]?><span style="padding-left: 0.4em;">▼</span>
                </div>
                <div class="user__menu shadow_1 ">
                    <li>
                        <ul><a href="/user_compile.php">ユーザー情報変更</a></ul>
                        <ul><a href="/logout.php">ログアウト</a></ul>
                    </li>
                </div>
            </div>
        </header>
<script src="/src/component/header.js"></script>

<main>