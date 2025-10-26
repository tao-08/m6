<?php

//DB設定
require_once "DB_info.php";
$pdo = new PDO(dsn, user, password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING));    

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
}

// jsonでJSに返す
header('Content-Type: application/json');
echo json_encode($response);
?>