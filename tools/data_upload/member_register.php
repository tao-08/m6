<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

//DB設定
require __DIR__ . "/../../src/setting/DB_connect.php";
$pdo = DBconnect();

// 未登録のメンバーを登録
// $_POST["member"] = [0]=>[band]=>バンド名
// 名前だけ取り出す
array_walk_recursive(
	$_POST["member"],
	function ($value, $key) use (&$new_member) {
		if (str_contains($key, "-new")) {
			$new_member[] = $value;
		}
	}
);
if (isset($new_member)) {
	$new_member = array_unique((array)$new_member);
	$sql = "INSERT into member (name) values(?)";
	$stmt = $pdo->prepare($sql);
	foreach ((array)$new_member as $row) {
		$stmt->execute([$row]);
	}
}

// バンド詳細にメンバー登録
foreach ($_POST["member"] as $key => $row) {
	$column = array_keys($row);
	$column = str_replace("-new", "", $column);
	$member = array_combine($column, $row);

	// メンバーID取得
	$sql = "SELECT id from member WHERE name = ?";
	$stmt = $pdo->prepare($sql);
	foreach ($member as $key => $value) {
		if($key === "band"){
			$band_name = $value;
		}else{
			// 楽器にメンバーが登録されていたら$member_id[バンド名]=>idに登録
			if ($value !== "") {
				$stmt->execute([$value]);
				$stmt->bindColumn("id", $member_id[$band_name][$value], pdo::PARAM_INT);
				$stmt->fetch(pdo::FETCH_BOUND);
			}
		}
	}


	// $member_id =

	// $sql = "INSERT into band_detail (name id_live_detail $key )";
	// $stmt = $pdo->prepare($sql);

}

print_r(
	// $_POST["member"],
	$member
	// $column
);
var_dump(
	$member_id,
	// $result,
);
