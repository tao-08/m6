<?php
session_start();
//DB設定
require_once("src/setting/DB_connect.php");
$pdo = DBconnect();

ini_set('display_errors', 1);
error_reporting(E_ALL);

	//登録ボタン押されたら
	if(isset($_POST["new_register"])){

		//フォーム入力を確認
		if(!empty($_POST["new_id"]) && !empty($_POST["new_name"]) && !empty($_POST["new_ruby"]) &&!empty($_POST["new_pass_1"])){

			
			//パスワード一致を確認
			if ($_POST["new_pass_1"] == $_POST["new_pass_2"]) {
				
				// idの重複がないか確認
				$sql ="SELECT auto_id from user_index WHERE id = ?";
				$stmt = $pdo->prepare($sql);
				$stmt->execute([$_POST["new_id"]]);
				if($stmt->rowCount() == 0){
					
					// パスワードハッシュ化
					$hashed_pass = password_hash($_POST["new_pass_1"],PASSWORD_DEFAULT);

					//DBに登録
					$sql = "INSERT INTO user_index (id,name,ruby,password) values (:new_id,:new_name,:new_ruby,:new_password)";
					$stmt = $pdo->prepare($sql);
					$stmt->bindParam(":new_id",$_POST["new_id"], pdo::PARAM_STR);
					$stmt->bindParam(":new_name",$_POST["new_name"], pdo::PARAM_STR);
					$stmt->bindParam(":new_ruby",$_POST["new_ruby"], pdo::PARAM_STR);
					$stmt->bindParam(":new_password",$hashed_pass, pdo::PARAM_STR);
					$stmt->execute();

					//確認ページへ
					$_SESSION["id"] = $_POST["new_id"];
					$_SESSION["name"] = $_POST["new_name"];
					$_SESSION["password"] = $_POST["new_pass_1"];
					$_SESSION["ruby"] = $_POST["new_ruby"];
					header("location: new_complete");
					exit();

				}else{
					$alert= "すでに登録されているIDです";
				}
			}else{
				$alert = "パスワードが一致しません";
			}
		}else{
			$alert = "未入力の項目があります";
		}
	}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <title>新規ユーザー登録</title>
    <link rel="stylesheet" href="css/style.css">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
    /* body{
        background-color: aliceblue;
    }
	.box_2{
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
		color: white; */
	/* }? */
	.submit{
		padding: 15px;
	}
	th,td{
		padding: 12px;
	}
	th{
		text-align: left;
	}
	/* tr{
		background-color: ghostwhite;
	} */
	#alert{
		color: red;
		text-align: center;
		font-weight: bold;
		padding-bottom: 1em;
	}
</style>
</head>
<body>
	<h1>新規ユーザー登録</h1>
	<div class="box_2 shadow_1">
		<form action="" method="POST">
			<table class="separate input_content">
				<tr>
					<th>ログインID</th>
					<td><input type="text" name="new_id" placeholder="半角英数字16文字以内"></td>
				</tr>
				<tr>
					<th>名前</th>
					<td><input type="text" name="new_name" placeholder="スペースなし"></td>
				</tr>
				<tr>
					<th>ふりがな</th>				
					<td><input type="text" name="new_ruby" placeholder="ひらがな スペースなし"></td>
				</tr>
				<tr>
					<th>パスワード</th>
					<td><input type="password" name="new_pass_1" placeholder="半角英数字16文字以内"></td>
				</tr>
				<tr>
					<th>確認用パスワード</th>
					<td><input type="password" name="new_pass_2" placeholder="半角英数字16文字以内"></td>
				</tr>
			</table>
			<div class="input_content bigger">
                <a href="/login" class="submit_button back_button">戻る</a>
				<input type="submit" name="new_register" value="新規登録" class="bigger submit_button">
			</div>
			<div id="alert">
				<?php if(!empty($alert)){ echo $alert; }?>
			</div>
		</form>
	</div>
</body>
</html>