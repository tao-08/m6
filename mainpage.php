
<?php session_start(); ?>

<!DOCTYPE html>
<head>
	<title>データベース</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
	<h1>データベース</h1>
	<div>
		ログイン：
		<?php
			echo $_SESSION["name"];
		?>
	</div>
	<div class="upper_manu">
		<a href="data_register.php" class="inline_button">データ登録</a>
		<a href="user_compile.php" class="inline_button">ユーザー情報編集</a>
	</div>

</body>
</html>