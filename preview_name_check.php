<?php
session_start();
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

//DB設定
require "src/setting/DB_connect.php";
$pdo = DBconnect();

$response = ["exists" => false];

// JSからnameが送られたか確認
if(isset($_POST["input_name"])){
    $name_check = $_POST["input_name"];
    try{
        $sql = "SELECT COUNT(*) FROM member WHERE name = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name_check]);

        // 件数取得
        $count = $stmt->fetchColumn();

        if($count > 0){
            $response["exists"]=true;
        }
    } catch (PDOException $e) {
    }
	
	// バンド名を探す→IDを返す
	// 毎回SELECTするの非効率だからsessionにためといて参照するのもありかも
}elseif (isset($_POST["band_name"])) {

	$name_check = $_POST["band_name"];
    try{
		// 完全一致

		$placeholder = array_fill(0,count($_SESSION["live_detail_id"]),"?");
		$placeholder = implode(",",$placeholder);
		$parameter = array_merge([$name_check],$_SESSION["live_detail_id"]);

		$sql = "SELECT id_band from band_master WHERE name = ? and id_live_detail IN ({$placeholder})";
		$stmt = $pdo->prepare($sql);
		$stmt->execute($parameter);
		$count = $stmt->fetchColumn();
		if($count !== false){
			$response["exists"] = true;
			
		}else{
			// 部分一致
			$name_check = "%{$name_check}%";
			$parameter = array_merge([$name_check],$_SESSION["live_detail_id"]);
			$sql = "SELECT id_band,name from band_master WHERE name like ? and id_live_detail IN ({$placeholder})";
			$stmt = $pdo->prepare($sql);
			$stmt->execute($parameter);
			$count = $stmt->fetchAll(pdo::FETCH_KEY_PAIR);
			
			if($count != false){
				$response["exists"] = "partial";
			}else{
				// 類似検索
				$placeholder--;
				array_shift($parameter);
				$sql = "SELECT id_band,name from band_master WHERE id_live_detail IN ({$placeholder})";
				$stmt = $pdo->prepare($sql);
				$stmt->execute($parameter);
				$idName_list = $stmt->fetchAll(pdo::FETCH_KEY_PAIR);

				$percent = 0;
				$idName_list = array_filter($idName_list,function ($value)use ($name_check) {
					similar_text($name_check,$value,$percent);
					return $percent >=70;
				});
				if(array_key_first($idName_list) !== null){
					$response["exists"] = "partial";
				}
			}
		}
	
		



        // $sql = "SELECT id_band,name FROM band_master WHERE id_live_detail IN (?)";
        // $stmt = $pdo->prepare($sql);
        // $stmt->execute([$_SESSION["live_detail_id"]]);
		// $result_band_id = $stmt->fetchAll(pdo::FETCH_COLUMN,0);
		// $result_band_name = $stmt->fetchAll(pdo::FETCH_COLUMN,1);

		// $result = array_combine($result_band_id,$result_band_name);
		// array_walk($result,function($v,$k,$a){
		// 	if(str_contains($v,$a)){

		// 	}
		// } ,($_POST["band_name"]));


		// foreach ($result as $row) {
		// 	if($row["name"] === $_POST["band_name"]){
		// 		$percent = 100;
		// 	}elseif (str_contains($_POST["band_name"],$row["name"])) {
				
		// 	}
		// 		similar_text($row["name"],$_POST["band_name"],$percent);

		// 	var_dump($row["name"],$_POST["band_name"],$percent);
		// 	if($percent == 100){
		// 		$response["exists"]=true;
		// 		$response["id"] = $row["id_band"];
				
		// 	}elseif($percent > 70){
		// 		$response["exists"]=true;
		// 		$response["id"] = $row["id_band"];
		// 		$response["band_name"] = $row["name"];
				
		// 		}
		// }
        
    } catch (PDOException $e) {
    }
	
}
// jsonでJSに返す
header('Content-Type: application/json');
echo json_encode($response);

// var_dump(
// 	// $result,
// 	// $_SESSION["live_detail_id"],
// 	// $name_check
// 	$count
// );