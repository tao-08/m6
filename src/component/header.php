<?php
session_start()?>
<!doctype html>
<html lang="ja">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>AbbeyRoad<?php if(!empty($page_title)){echo " - ".$page_title;}?></title>
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
            }
            .header__box{
                width: 75%;
                padding: 0 7%;
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
            <div class="user">
                ログイン：<?=$_SESSION["name"]?>
            </div>
            
        </header>
    <main>