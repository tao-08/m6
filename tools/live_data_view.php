<?php

// ライブごとに表示
$_SESSION["live_master_id"]= [11,12];
$serch_live_master = $_SESSION["live_master_id"];
$placeholder = array_fill(0,count($serch_live_master),"?");
$placeholder = implode(",",$placeholder);

// バンド→b ライブマスター→l ライブ詳細→d 会場→v メンバー→m
$sql = "SELECT l.name_live,d.id_live_detail
			from live_master AS l
			JOIN live_detail AS d ON l.id_live = d.id_live_master
			WHERE l.id_live IN ({$placeholder})";
$stmt = $pdo->prepare($sql);
$stmt->execute($serch_live_master);
$number_live_detail = $stmt->fetchAll(pdo::FETCH_KEY_PAIR);
