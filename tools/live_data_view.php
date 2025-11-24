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
	lm.id_live,
	lm.name_live,
	ld.id_live_detail,
	ld.day,
	ld.date,
	v.name
from
	live_detail AS ld
JOIN live_master AS lm
	ON lm.id_live = ld.id_live_master
JOIN venue AS v
	ON ld.id_venue = v.id_venue";
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

foreach($result as $master_id => $row){
	$live_data[$master_id][] = $row;

}

// 選択したライブメンバー取得のためライブID取得
$live_detail_list = [];
array_walk_recursive($result,function($value, $key) use(&$live_detail_list) 
{
	if($key === "id_live_detail"){
		$live_detail_list[] = $value;
	}
});
$live_detail_list = array_unique($live_detail_list);
$placeholder = placeholder($live_detail_list);

// メンバー取得 b→band_master ld→live_detail
$sql =
"SELECT
	id_live_detail,
	id_band,
	name,
	order_live,
	songs,
	vocal_1,vocal_2,vocal_3,vocal_guiter_1,vocal_guiter_2,vocal_bass,vocal_drum,guiter_1,guiter_2,guiter_3,guiter_4,bass_1,bass_2,drum_1,drum_2,keybord_1,keybord_2,keybord_3,other_1,other_2,other_3,other_1_name,other_2_name,other_3_name,comment
from
	band_master
WHERE
	id_live_detail IN ({$placeholder})
order by id_band
";
$stmt = $pdo->prepare($sql);
$stmt->execute($live_detail_list);
$member = $stmt->fetchAll(pdo::FETCH_GROUP|pdo::FETCH_ASSOC);

var_dump($result);

