<?php
// 一時ファイルからタイテファイルを取得
$file_timetable = new SplFileObject($_FILES["file_timetable"]["tmp_name"],"r");
$file_timetable->setFlags(SplFileObject::READ_CSV|SplFileObject::SKIP_EMPTY);

// ライブ名取得->$live_name
// "ライブ" "日目"が含まれているセルを検索して#日目を削除
$label_timetable = $file_timetable->current();
$column_live_name= preg_grep("/.*ライブ.*|.*日目.*/",$label_timetable);
$original_live_name = reset($column_live_name);
$live_name = preg_replace('/\d日目/',"",$original_live_name);

// 日程取得->$live_day
$live_day = preg_replace("/.*(?=\d日目)|日目/","",$original_live_name);

// 会場取得->$live_venue
$live_venue = $label_timetable[array_search("会場",$label_timetable)+1];

// バンド名取得
// 指定したラベルか最後の行まで読み込んだら止まる
$lines = 1;
$band_column = null;
$songs_column = null;
$start_band_row = null;
foreach($file_timetable as $row) {
	if(!is_array($row)){
		continue;
	}
	// バンド名と曲数行を検索（少なくともバンド名は取得）
	if($band_column === null || $band_column === false){
		$songs_column = array_search("曲数",$row);
		$band_column = array_search("バンド名",$row);
		if($songs_column === false){
			$alert = '"曲数"列が見つかりませんでした';
		}
		continue;
	}
	// "集合"を探して見つかったら次の行からバンド名を取得
	if($start_band_row === null){
		if(in_array("集合",$row)){
			$start_band_row = true;
			continue;
		}
	}
	// 括弧を全角に置き換え
	$row[$band_column] = str_replace("(","（",$row[$band_column]);
	$row[$band_column] = str_replace(")","）",$row[$band_column]);
	if($songs_column === null){
		$band_info[] = [
			"number"=>$lines,
			"name"=>$row[$band_column]
		];
	}else{
		$band_info[] = [
			"number"=>$lines,
			"name"=>$row[$band_column],
			"songs"=>$row[$songs_column],
		];
	}
	$lines++;
}
$sql="SELECT id_venue,name FROM venue";
$stmt = $pdo->query($sql);
$result = $stmt->fetchAll();