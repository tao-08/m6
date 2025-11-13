<?php session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
?>

<!DOCTYPE html>
<head>
    <title>
        登録完了
    </title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1>
        登録完了
    </h1>
    <div class="box">
        <h2>
            以下のユーザー情報で登録しました
        </h2>

        <table>
			<tr>
				<th>ID</th>
				<td><?=$_SESSION["id"]?></td>
			</tr>
			<tr>
				<th>氏名</th>
				<td><?=$_SESSION["name"]?></td>
			</tr>
			<tr>
				<th>ふりがな</th>
				<td><?=$_SESSION["ruby"]?></td>
			</tr>
			<tr>
				<th>パスワード</th>
				<td><?= $_SESSION["password"];
						$_SESSION["password"] = "";?></td>
			</tr>
        </table>
		<div>
			<a href="index.php" class="submit_button">ログインする</a>
		</div>
	</div>
</body>
</html>