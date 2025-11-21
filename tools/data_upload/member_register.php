<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
$post_band_member = $_POST["member"];#バンド名とメンバーの名前の配列

//DB設定
require __DIR__ . "/../../src/setting/DB_connect.php";
$pdo = DBconnect();

// 未登録のメンバーを登録
// $_POST["member"] = [0]=>[band]=>バンド名
// 名前だけ取り出す
array_walk_recursive(
	$post_band_member,
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
	foreach ((array)$new_member as $new_member_name) {
		$stmt->execute([$new_member_name]);
	}
}

// バンド詳細にメンバー登録
foreach ($post_band_member as $number => $name_array) {
	// newがついたキーからnewとる
	$column = array_keys($name_array);
	$column = str_replace("-new", "", $column);
	$band_member = array_combine($column, $name_array);

	// メンバーID取得
	$sql = "SELECT id from member WHERE name = ?";
	foreach ($band_member as $instrument => $name) {
		if($instrument === "band"){
			$band_name = $name;
			continue;
		}
		// 楽器にメンバーが登録されていたら$member_id[バンド名][名前]=>idに登録
		if ($name == "") {
			continue;
		}
		$stmt = $pdo->prepare($sql);
		$stmt->execute([$name]);
		$stmt->bindColumn("id", $member_id, pdo::PARAM_INT);
		$stmt->fetch(pdo::FETCH_BOUND);
		// 一人ずつ登録
		$placeholder = array_fill(0,count($_SESSION["live_detail_id"]),"?");
		$placeholder = implode(",",$placeholder);
		$parameter = array_merge([$band_name],$_SESSION["live_detail_id"]);
		$sql_register = "UPDATE band_master SET {$instrument} = $member_id WHERE name = ? AND id_live_detail IN ({$placeholder})";
		$stmt = $pdo->prepare($sql_register);
		$stmt->execute($parameter);
	}
}
header("location:/member_upload?complete=1");