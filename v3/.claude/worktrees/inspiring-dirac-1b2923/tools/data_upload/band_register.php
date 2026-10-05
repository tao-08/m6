<?php

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
//DB設定
require __DIR__."/../../src/setting/DB_connect.php";
$pdo = DBconnect();

// 
// 1.live_master登録 2.live_detail登録 3.band_master登録
// 

// ライブマスターを初回だけ登録
$_SESSION["live_master_id"] = $_SESSION["live_master_id"] ?? [];
// 年度を登録
$year = substr($_POST["date"],0,4);
if(	substr($_POST["date"],5,2) == "01" ||
	substr($_POST["date"],5,2) == "02" ||
	substr($_POST["date"],5,2) == "03" ){
	$year--;
}

// live_masterにライブ名と年が一致するレコードがなければ登録
// 検索
if(empty($_SESSION["live_master_id"])){
    $sql = "SELECT live_id from live_master where year = :year and name = :name";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":year",$year);
    $stmt->bindParam(":name",$_POST["live_name"]);
    $stmt ->execute();
    $result = $stmt->fetch();
	$found_id = $result[0] ?? null;
    // 登録
    if(empty($found_id)){
		$sql = "INSERT INTO live_master (year,name) values (:year,:live_name)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":year",$year);
        $stmt->bindParam(":live_name",$_POST["live_name"]);
        $stmt->execute();
        $found_id = $pdo->lastInsertId();
    }
	$_SESSION["live_master_id"][] = $found_id;
}


// ライブ詳細登録
// 新しい会場だった場合登録してID取得
if($_POST["venue"] === "new"){
    $sql = "INSERT into venue (venue_name) values(:venue_name)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":venue_name",$_POST["new_venue"]);
    $stmt->execute();
    $new_venue_id = $pdo->lastInsertId();
}
$insert_live_master = end($_SESSION["live_master_id"]);
$sql = "INSERT into live_detail
        (live_id,date,label,venue_id)
values  (:live_master_id,:date,:label,:venue_id)";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(":live_master_id",$insert_live_master);
$stmt->bindParam(":date",$_POST["date"]);
$stmt->bindParam(":label",$_POST["days"]);
if($_POST["venue"] === "new"){
    $stmt->bindParam(":venue_id",$new_venue_id);
}else{
    $stmt->bindParam(":venue_id",$_POST["venue"]);
}
$stmt->execute();
$live_detail_id = $pdo->lastInsertId();
// 検索用のライブIDをセット
$_SESSION["live_detail_id"][] = $live_detail_id;


// バンドデータ登録
// バンド名被りを検索

$sql = "INSERT into band (name,live_detail_id,play_order,song_count) values (:band_name,:live_detail_id,:play_order,:song_count)";
$stmt= $pdo->prepare($sql);
$stmt->bindParam(":band_name",$band_name);
$stmt->bindParam(":live_detail_id",$live_detail_id);
$stmt->bindParam(":live_order",$order);
$stmt->bindParam(":songs",$songs);
foreach($_POST["band_data"] as $band_data){
    $band_name = $band_data["band_name"];
    $order = $band_data["band_number"];
    $songs = $band_data["band_songs"];
    $stmt->execute();

	// $id = $pdo -> lastInsertId();
	// $_SESSION["band_name-id"][] = [
	// 	"name"=>$band_name,
	// 	"id"=>$id
	// ];
}

$_SESSION["complete_file_name"] = $_POST["file_name"];
if(isset($_POST["next"])){
    header("location:/member_upload");
}elseif(isset($_POST["next_day"])){
    header("location:/data_upload?multiple=1");
}