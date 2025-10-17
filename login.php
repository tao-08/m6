<?php session_start() ?>
<!doctype HTML>
<head>
    <title>ログイン</title>
    <meta charset="UTF-8">
    <style>
        .box {
            border: 1px solid gray;
			background-color: white;
			width: 50%;
            border-radius: 8px;
            padding: 10px;
			margin: 0 auto;
			margin-top: 50px;
			text-align: center;

        }
		.input_content{
			padding: 8px;
		}
    </style>
</head>
<body style="background-color: aliceblue;">
    <?php
        //DB設定
        $dsn = 'mysql:dbname=tb270684db;host=localhost';
        $user = 'tb-270684';
        $password = 'YxeP8FDwdZ';
        $pdo = new PDO($dsn, $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING));    

        //ユーザー認証
        if(!empty($_POST["id"]) && !empty($_POST["password"])){
            $sql = "SELECT * FROM user_index WHERE id = :id AND password = :password";
            $stmt = $pdo->prepare($sql);
            $stmt -> bindParam(":id",$_POST["id"],pdo::PARAM_STR);
            $stmt -> bindParam(":password",$_POST["password"],pdo::PARAM_STR);
            $stmt -> execute();

            $results = $stmt->rowCount();
            if($results == 1){
                
                //ログイン成功したらmain.phpへ
                $_SESSION["id"] = $_POST["id"];
                $_SESSION["password"] = $_POST["password"];

                //sessionにユーザー情報をDBから引っ張る
                //DB設定
                $dsn = 'mysql:dbname=**DBname**;host=localhost';
                $user = '**user**';
                $password = '**password**';
                $pdo = new PDO($dsn, $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING));    

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

                header("Location: mainpage.php");

                exit();
            }else{
                //ログイン失敗
                $alert = "IDかパスワードが違います";
            }
        }

    ?>


    <form action="" method="post">
        <div class="box">
			<div>
				<div class="input_content">
					<div>
						ユーザーID
					</div>
					<div>
						<input type="text" name="id">
					</div>
				</div>
				<div class="input_content">
					<div>
						パスワード
					</div>
					<div>
						<input type="password" name="password">
					</div>
				</div>
				<div class="input_content">
					<strong style="color: red;">
					<?php
					if(!empty($alert)){
						echo $alert;
					}?>
					</strong>
					<input type="submit" name="login" value="ログイン">
				</div>
			</div>
        </div>
        <div style="padding: 5px; text-align: center;">
            <a href="new_register.php">新規ユーザー登録</a>
        </div>
    </form>
</body>
</html>