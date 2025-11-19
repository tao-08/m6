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
$_SESSION["live_master_id"] = null;
$year = substr($_POST["date"],0,4);

// live_masterにライブ名と年が一致するレコードがなければ登録
// 検索
if(empty($_SESSION["live_master_id"])){
    $sql = "SELECT id_live from live_master where year = :year and name_live = :name_live";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":year",$year);
    $stmt->bindParam(":name_live",$_POST["live_name"]);
    $stmt ->execute();
    $result = $stmt->fetch();
    $_SESSION["live_master_id"] = $result[0] ?? null;
    // 登録
    if(empty($_SESSION["live_master_id"])){
        $sql = "INSERT INTO live_master (year,name_live) values (:year,:name_live)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":year",$year);
        $stmt->bindParam(":name_live",$_POST["live_name"]);
        $stmt->execute();
        $_SESSION["live_master_id"] = $pdo->lastInsertId();
    }
}


// ライブ詳細登録
// 新しい会場だった場合登録してID取得
if($_POST["venue"] === "new"){
    $sql = "INSERT into venue (name) values(:name_venue)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":name_venue",$_POST["new_venue"]);
    $stmt->execute();
    $new_venue_id = $pdo->lastInsertId();
}
$sql = "INSERT into live_detail 
        (id_live_master,date,day,id_venue) 
values  (:id_live_master,:date,:day,:id_venue)";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(":id_live_master",$_SESSION["live_master_id"]);
$stmt->bindParam(":date",$_POST["date"]);
$stmt->bindParam(":day",$_POST["days"]);
if($_POST["venue"] === "new"){
    $stmt->bindParam(":id_venue",$new_venue_id);
}else{
    $stmt->bindParam(":id_venue",$_POST["venue"]);
}
$stmt->execute();
$live_detail_id = $pdo->lastInsertId();
// 検索用のライブIDをセット
$_SESSION["live_detail_id"][] = $live_detail_id;


// バンドデータ登録
$sql = "INSERT into band_master (name,id_live_detail,order_live,songs) values (:name,:id_live_detail,:order_live,:songs)";
$stmt= $pdo->prepare($sql);
$stmt->bindParam(":name",$band_name);
$stmt->bindParam(":id_live_detail",$live_detail_id);
$stmt->bindParam(":order_live",$order);
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

// var_dump($_POST,$year);
// var_dump($_SESSION,$result,$live_detail_id);
if(isset($_POST["next"])){
    header("location:/member_upload");
}elseif(isset($_POST["next_day"])){
    header("location:/data_upload?multiple=1");
}