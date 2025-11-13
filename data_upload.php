<?php
$band_label = "バンド名";
$vocal_label = "Vo(Gt.)";
$guiter_1_label = "Gt.1";
$guiter_2_label = "Gt.2";
$bass_label = "Ba.";
$drum_label = "Dr.";
$keybord_label = "Key.";
$songs_label = "曲数";

$css = "data_upload";
$page_title = "新規データ登録";
require_once("src/component/header.php");

//フォームから列名を変数に
if(!empty($_post["preview"])){
	$band_label = $_POST["band_label"];
	$vocal_label = $_POST["vocal_label"];
	$guiter_1_label = $_POST["guiter_1_label"];
	$guiter_2_label = $_POST["guiter_2_label"];
	$bass_label = $_POST["bass_label"];
	$keybord_label = $_POST["keybord_label"];
	$drum_label = $_POST["drum_label"];
	$songs_label = $_POST["songs_label"];
}

//プレビューモード
if(isset($_POST["preview"]) && isset($_FILES['file_member']) && is_uploaded_file($_FILES['file_member']['tmp_name'])){
	
	$band = [];

	//一時ファイルのパスを取得
	$path = $_FILES['file_member']['tmp_name'];
	if(($handle = fopen($path,"r")) !== false){
		
		//CSVのヘッダー名からどのパートが何番にあるか調べる
		// 1行目だけ読み込み
		$header = fgetcsv($handle);
		$key_band = array_search($band_label,$header);
		$key_vocal = array_search($vocal_label,$header);
		$key_guiter_1 = array_search($guiter_1_label,$header);
		$key_guiter_2 = array_search($guiter_2_label,$header);
		$key_bass = array_search($bass_label,$header);
		$key_drum = array_search($drum_label,$header);
		$key_keybord = array_search($keybord_label,$header);
		$key_songs = array_search($songs_label,$header);
	
		if(!empty($_POST["preview_submit"])){
			require_once "band_register.php";
		}
	}
}

	


$sql="SELECT id_venue,name FROM venue";
$stmt = $pdo->query($sql);
$result = $stmt->fetchAll();

require_once("view/data_upload.php")?>
		
