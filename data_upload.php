<?php
$css = "data_upload";
$page_title = "新規データ登録";
require_once("src/component/header.php");

require_once(__DIR__."/tools/data_upload/timetable_reader.php");

// 
if($_GET["multiple"] ?? false){
	$registered_timetable = ($registered_timetable ?? 0)+1;
}elseif(!isset($_POST["nextday"] )){
	$_SESSION["live_master_id"] = [];
	$_SESSION["live_detail_id"] = [];
}

if(isset($_POST["preview_timetable"]) && !empty($_FILES["file_timetable"]["name"])){
	// 一時ファイルからタイテファイルを取得
	$filename_timetable = strtolower($_FILES["file_timetable"]["name"]);

	try {
		$rows = readTimetableRows($_FILES["file_timetable"]);
		$parsed = parseTimetableRows($rows,$pdo);
		extract($parsed);

	} catch (\RuntimeException $e) {
		$alert = $e->getMessage();
	}
}

require_once "view/data_upload.php";