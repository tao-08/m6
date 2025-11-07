<?php
$page_title = "ホーム";
require_once("src/component/header.php");
if(empty($_SESSION["id"])){
	header("location:login.php");
	exit;
}
?>
<h1>データベース</h1>

<div class="box shadow_1">
	準備中
</div>

</body>
</html>