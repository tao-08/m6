<?php

// ライブごとに表示
// $_SESSION["live_master_id"]= [11,12];
// $serch_live_master = $_SESSION["live_master_id"];
// $placeholder = array_fill(0,count($serch_live_master),"?");
// $placeholder = implode(",",$placeholder);
function placeholder($array)
{
	$array = array_fill(0,count($array),"?");
	$array = implode(",",$array);
	return $array;
}

// 年が指定されていないとき
$selected_year = $_GET["year"] ?? "latest";
if($selected_year === "latest"){
	$sql_latest_year = "SELECT year from live_master ORDER BY year DESC";
	$stmt = $pdo->prepare($sql_latest_year);
	$stmt->execute();
	$stmt->bindColumn(1,$selected_year);
	$stmt->fetch(pdo::FETCH_BOUND);
}

// バンド→b ライブマスター→lm ライブ詳細→ld 会場→v メンバー→m
$sql =
"SELECT
	-- lm.year,
	lm.live_master_id,
	lm.live_name,
	ld.live_detail_id,
	ld.day,
	ld.date,
	v.venue_name
from
	live_detail AS ld
JOIN live_master AS lm
	ON lm.live_master_id = ld.live_master_id
JOIN venue AS v
	ON ld.venue_id = v.venue_id";
			// WHERE l.id_live IN ({$placeholder})";

// 年度別でフィルター
if($selected_year !== false){
	$sql .= " WHERE lm.year = ?";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([$selected_year]);
// ライブ名でフィルター
}elseif(is_array($filter_live_master ?? false)){
	$placeholder = placeholder($filter_live_master);
	$sql .= " WHERE lm.name_live IN ({$placeholder})";
	$stmt = $pdo->prepare($sql);
	$stmt->execute($filter_live_master);
	
}else{
	$stmt = $pdo->prepare($sql);
	$stmt->execute();
	
}
$result = $stmt->fetchAll(pdo::FETCH_GROUP|pdo::FETCH_ASSOC);


// 選択したライブメンバー取得のためライブID取得
$live_detail_list = [];
array_walk_recursive($result,function($value, $key) use(&$live_detail_list) 
{
	if($key === "live_detail_id"){
		$live_detail_list[] = $value;
	}
});
$live_detail_list = array_unique($live_detail_list);
$placeholder = placeholder($live_detail_list);

// メンバー取得 b→band_master ld→live_detail
$sql =
"SELECT
	live_detail_id,
	band_id,
	band_name,
	live_order,
	songs,
	vocal_1,vocal_2,vocal_3,vocal_guiter_1,vocal_guiter_2,vocal_bass,vocal_drum,guiter_1,guiter_2,guiter_3,guiter_4,bass_1,bass_2,drum_1,drum_2,keybord_1,keybord_2,keybord_3,other_1,other_2,other_3,other_1_name,other_2_name,other_3_name,comment
from
	band_master
WHERE
	live_detail_id IN ({$placeholder})
order by band_id
";
$stmt = $pdo->prepare($sql);
$stmt->execute($live_detail_list);
$member = $stmt->fetchAll(pdo::FETCH_ASSOC);

foreach($result as $master_id => $row){
	$live_data[$master_id]["live_name"] = $row[0]["live_name"];
	foreach($row as $detail){
		$live_data[$master_id][$detail["live_detail_id"]] = [
			"day" => $detail["day"],
			"date" => $detail["date"],
			"venue" => $detail["venue_name"]
		];
		foreach($member as $member_list){
			foreach($member_list as $column=>$member_row){
				if($column === "live_detail_id"||$column === "band_id"){continue;}
				$live_data[$master_id][$detail["live_detail_id"]][$member_row["band_id"]][$column] = $member_row;
			}
		}
	}
}


var_dump($result);

