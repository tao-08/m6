<?php
session_start();
if(empty($_SESSION["id"])){
	header("location:../login.php");
	exit;
}
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
        
        <!-- CSS -->
        <link rel="stylesheet" href="style.css?v=<?= date("Y:m:d:t H:i:s")?>">
        <style>
            .site_header{
                display: flex;
                align-items: center;
                padding: 10px 10px;
                background-color: #ffffff;
                color: white;
                position: sticky;
                top: 0;
                border-bottom: 1px solid rgba(221, 221, 221, 1);
                margin: -8px -8px 0 -8px;
            }
            .logo{
                width: 100%;
                margin: 0 10px;
            }
            .logo__box{
                max-width: 13%;
                margin-top: 0.2%;
            }
            .header__box{
                width: 75%;
                padding: 1.2% 7%;
                display: flex;
                justify-content: space-between;
            }
            .header__card{
            }
            .header__card a{
                color: black;
                transition: 0.2s ease;
                text-decoration: none;
                font-size: 120%;
            }
            .header__card a:hover{
                color: rgb(235, 57, 57);
            }
            .user{
                display: flex;
                color: rgba(82, 82, 82, 1);
                margin-left: auto;
            }
            .user__menu{
                background-color: #ff3939ff;
                text-align: center;
                display: none;
            }
            .user__menu .visible{
                display: block;
            }
            .user__menu li{
                list-style-type: none;
            }
            .user__menu a{
                transition:0.2s ease;
                color: #ffffffff;
                text-decoration: none;                
            }
            .user__menu a:hover{
                color: rgb(255, 255, 255);
                text-decoration: underline;
                transition:0.2s ease;
            }
            @media (max-width:767px) {
                .site_header{
                    display: block;
                }
                .header__card a{
                    font-size: 100%;
                }
                .user{
                    font-size: 70%;
                }
                .logo__box{
                    max-width: 50%;
                    width: 200px;
                }
                .header__box{
                    margin: 0 auto;
                    width: 100%;
                    padding: 0;
                }

                
            }

        </style>
    </head>
    <body>
        <header class="site_header">
            <div class="drop_hover logo__box"><a href="/index.php"><img src="/src/assets/online.png" alt="AbbeyRoad.online" class="logo"></a></div>
            <div class="header__box">
                <div class="header__card"><a href="database_live.php">ライブデータベース《準備中》</a></div>
                <div class="header__card"><a href="database_live.php">ツール《準備中》</a></div>
                <div class="header__card"><a href="/user_compile.php">ユーザー情報編集</a></div>
            </div>
            <div class="user" id="user">
                ログイン：<?=$_SESSION["name"]?>
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