<?php
function DBconnect()
{
    //DB設定
    try{
        require_once "DB_info.php";
        $pdo = new PDO(dsn, user, password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING));
        return $pdo;
    // }catch (Exception $e){
    //     try{
    //         require_once "DB_info_local.php";
    //         $pdo = new PDO(dsn1, user1, password1, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING));   
    //         return $pdo;
        }catch(Exception $e){
            echo "エラー-：".$e->getMessage();
            exit();
        // }
    }
}