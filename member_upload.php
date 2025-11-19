<?php
$css = "data_upload";
$page_title = "新規データ登録";
require_once __DIR__."/src/component/header.php";


$band_label = "バンド名";
$vocal_label = "Vo(Gt.)";
$guiter_1_label = "Gt.1";
$guiter_2_label = "Gt.2";
$bass_label = "Ba.";
$drum_label = "Dr.";
$keybord_label = "Key.";

//フォームから列名を変数に
if(!empty($_POST["preview"])){
	$band_label = $_POST["band_label"] ?? $band_label;
	$vocal_label = $_POST["vocal_label"] ?? $vocal_label;
	$guiter_1_label = $_POST["guiter_1_label"] ?? $guiter_1_label;
	$guiter_2_label = $_POST["guiter_2_label"] ?? $guiter_2_label;
	$bass_label = $_POST["bass_label"] ?? $bass_label;
	$keybord_label = $_POST["keybord_label"] ?? $keybord_label;
	$drum_label = $_POST["drum_label"] ?? $drum_label;
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
	}
}
require_once __DIR__."/view/member_upload.php";