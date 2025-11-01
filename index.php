<?php
session_start();
if(empty($_SESSION["id"])){
	header("location:login.php");
	exit;
}
$title_name = "ホーム";
?>

<!DOCTYPE html>
<?php require_once("src/component/header.php");?>
	<h1>データベース</h1>
	<div>
		ログイン：
		<?php
			echo $_SESSION["name"];
		?>
	</div>
	<div class="upper_manu">
		<a href="data_upload.php" class="inline_button">データ登録</a>
		<a href="user_compile.php" class="inline_button">ユーザー情報編集</a>
	</div>

</body>
</html>