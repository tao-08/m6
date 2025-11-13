<?php session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);


//DB設定
require("DB_connect.php");
$pdo = DBconnect();

//ユーザー認証
if(!empty($_POST["id"]) && !empty($_POST["password"])){
    $sql = "SELECT ,password FROM user_index WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt -> bindParam(":id",$_POST["id"],pdo::PARAM_STR);
    // $stmt -> bindParam(":password",$_POST["password"],pdo::PARAM_STR);
    $stmt -> execute();

    $password_results = $stmt -> fetchAll();
    foreach($password_results as $row_password){

    }
    
    $results = $stmt->rowCount();
    if($results == 1){
        
        //ログイン成功したらindex.phpへ
        $_SESSION["id"] = $_POST["id"];
        $_SESSION["password"] = $_POST["password"];

        //sessionにユーザー情報をDBから引っ張る
        
        //DB操作
        $sql = "SELECT auto_id,name,ruby from user_index WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":id",$_POST["id"],pdo::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchAll();
        
        //sessionに保存
        foreach($result as $row){
            $_SESSION["ruby"] = $row["ruby"];
            $_SESSION["name"] = $row["name"];
            $_SESSION["auto_id"] = $row["auto_id"];
        }
        
        header("Location: index.php");
        
        exit();
    }else{
        //ログイン失敗
        $alert = "IDかパスワードが違います";
    }
}

?>
<!doctype HTML>
<head>
    <title>ログイン</title>
    <link rel="stylesheet" href="style.css?v=<?= date("Y:m:d:t H:i:s")?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
    </style>

</head>


<body id="login">
    <div class="center" style="margin-top:50px">
            <img src="/src/assets/online.png" alt="AbbeyRoad.online" width="1000px" height="274px" id="logo_login">
        <form action="" method="post">
            <div class="box shadow_1">
                <div class="bigger">
                    <div class="input_content">
                        ユーザーID
                        <input type="text" name="id" >
                    </div>
                    <div class="input_content">
                        パスワード  
                        <input type="password" name="password">
                    </div>
                    <div class="input_content">
                        <strong style="color: red;">
                        <?php
                        if(!empty($alert)){
                            echo $alert;
                        }?>
                        </strong>
                        <input type="submit" name="login" value="ログイン" class="submit_button shadow_2">
                    </div>
                </div>
            </div>
            <div style="padding: 5px; text-align: center;">
                <a href="new_register.php">新規ユーザー登録</a>
            </div>
        </form>
    </div>
</body>
</html>