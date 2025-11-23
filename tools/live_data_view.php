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

// バンド→b ライブマスター→lm ライブ詳細→ld 会場→v メンバー→m
$sql = 
"SELECT
	lm.year,
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
if(is_array($filter_year ?? false)){
	$placeholder = placeholder($filter_year);
	$sql .= " WHERE lm.year IN ({$placeholder})";
	$stmt = $pdo->prepare($sql);
	$stmt->execute($filter_year);
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
$result = $stmt->fetchAll(pdo::FETCH_GROUP|pdo::FETCH_GROUP|pdo::FETCH_ASSOC);
var_dump($result);
