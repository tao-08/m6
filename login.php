<?php session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$alert = "";

//DB設定
require("DB_connect.php");
$pdo = DBconnect();

if(isset($_POST["login"])){

    
    //ユーザー認証
    if(!empty($_POST["id"]) && !empty($_POST["password"])){
        $password = $_POST["password"];

        // 入力されたIDで検索
        $sql = "SELECT * FROM user_index WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt -> bindParam(":id",$_POST["id"],pdo::PARAM_STR);
        $stmt -> execute();
        
        $password_results = $stmt -> fetch(pdo::FETCH_ASSOC);
        if(!empty($password_results)){
            
            if(password_verify($password,$password_results["password"])){
                    
                //sessionにユーザー情報をDBから引っ張る
                $_SESSION["id"] = $_POST["id"];
                $_SESSION["ruby"] = $password_results["ruby"];
                $_SESSION["name"] = $password_results["name"];
                $_SESSION["auto_id"] = $password_results["auto_id"];
                $_SESSION["admin"] = $password_results["admin"];
                $_SESSION["id_member"] = $password_results["id_member"];
                
                //ログイン成功したらindex.phpへ
                header("Location:/");
                exit();
                
            }else{
                
                //ログイン失敗
                $alert = "パスワードが違います";
            }
        }else{
            $alert="IDが違います";
        }
    }else{
        $alert = "未入力の項目があります";
    }
}
?>
<!doctype HTML>
<head>
    <title>ログイン</title>
    <link rel="stylesheet" href="css/style.css?v=<?= date("Y:m:d:t H:i:s")?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        </style>

</head>


<body id="login">
    <div class="center">
            <img src="/src/assets/online.png" alt="AbbeyRoad.online" width="1000px" height="274px" id="logo_login">
        <form action="" method="post">
            <div class="box_2 shadow_1">
                <div class="bigger">
                    <div class="input_content">
                        ユーザーID
                        <input type="text" name="id" >
                    </div>
                    <div class="input_content">
                        パスワード  
                        <input type="password" name="password">
                    </div>
                    <div style="height:1em">
                        <strong style="color: red;">
                            <?php
                            if(!empty($alert)){
                                echo $alert;
                            }?>
                        </strong>
                    </div>
                    <div class="input_content">
                        <input type="submit" name="login" value="ログイン" class="submit_button shadow_2">
                    </div>
                </div>
            </div>
            <div style="padding: 5px; text-align: center;">
                <a href="new_register">新規ユーザー登録</a>
            </div>
        </form>
    </div>
</body>
</html>