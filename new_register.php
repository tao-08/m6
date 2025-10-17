<!DOCTYPE html>
<head>
    <title>新規ユーザー登録</title>
    <link rel="stylesheet" href="style.css">
<style>
    body{
        background-color: aliceblue;
    }
	.box{
		width: 60%;
		border: 1px gray solid;
		border-radius: 10px;
		margin: 0 auto;
		background-color: white;
	}
	.submit_button{
		width: 150px;
		height: 40px;
		background-color: deepskyblue;
		color: white;
	}
	.submit{
		padding: 15px;
	}
	table{
		border-collapse:separate;
		border-radius: 10px;
		border-spacing: 20px;
	}
	th,td{
		padding: 12px;
	}
	tr{
		background-color: ghostwhite;
	}
	#alert{
		color: red;
		text-align: right;
		font-weight: bold;
		padding-left: 10%;
	}
</style>
</head>
<body>
	<?php
		//DB設定
        $dsn = 'mysql:dbname=**DBname**;host=localhost';
        $user = '**user**';
        $password = '**password**';
        $pdo = new PDO($dsn, $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING)); 
		
		//登録ボタン押されたら
		if(isset($_POST["new_register"])){

			//フォーム入力を確認
			if(!empty($_POST["new_id"]) && !empty($_POST["new_name"]) && !empty($_POST["new_ruby"]) &&!empty($_POST["new_pass_1"])){

				//パスワード一致を確認
				if ($_POST["new_pass_1"] == $_POST["new_pass_2"]) {
				
					//DBに登録
					$sql = "INSERT INTO user_index (id,name,ruby,password) values (:new_id,:new_name,:new_ruby,:new_password)";
					$stmt = $pdo->prepare($sql);
					$stmt->bindParam(":new_id",$_POST["new_id"], pdo::PARAM_STR);
					$stmt->bindParam(":new_name",$_POST["new_name"], pdo::PARAM_STR);
					$stmt->bindParam(":new_ruby",$_POST["new_ruby"], pdo::PARAM_STR);
					$stmt->bindParam(":new_password",$_POST["new_pass_1"], pdo::PARAM_STR);
					$stmt->execute();

					//確認ページへ
					session_start();
					//echo $_COOKIE["PHPSESSID"];
					$_SESSION["id"] = $_POST["new_id"];
					$_SESSION["name"] = $_POST["new_name"];
					$_SESSION["password"] = $_POST["new_pass_1"];
					$_SESSION["ruby"] = $_POST["new_ruby"];
					header("location: new_complete.php");
					exit();

				}else{
					$alert = "パスワードが一致しません";
				}
			}else{
				$alert = "未入力の項目があります";
			}
		}
	?>



    <h1>新規ユーザー登録</h1>
    <div class="box">
        <form action="" method="POST">
            <table>
                <tr>
                    <th>
                        ログインID
                    </th>
                    <td>
                        <input type="text" name="new_id" placeholder="半角英数字16文字以内">
                    </td>
                </tr>
				<tr>
					<th>
						名前
					</th>
					<td>
						<input type="text" name="new_name" placeholder="スペースなし">
					</td>
				</tr>
				<tr>
					<th>
						ふりがな
					</th>				
					<td>
						<input type="text" name="new_ruby" placeholder="ひらがな スペースなし">
					</td>
				</tr>
				<tr>
					<th>
						パスワード
					</th>
					<td>
						<input type="password" name="new_pass_1" placeholder="半角英数字16文字以内">
					</td>
				</tr>
				<tr>
					<th>
						確認用パスワード
					</th>
					<td>
						<input type="password" name="new_pass_2" placeholder="半角英数字16文字以内">
					</td>
				</tr>
            </table>
			<span id="alert">
				<?php if(!empty($alert)){ echo $alert; }?>
			</span>
			<div class="submit">
				<input type="submit" name="new_register" value="新規登録" class="submit_button">
			</div>
        </form>
    </div>
</body>
</html>