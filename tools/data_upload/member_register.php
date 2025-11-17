<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

//DB設定
require __DIR__."/../../src/setting/DB_connect.php";
$pdo = DBconnect();

// 未登録のメンバーを登録
// $_POST["member"] = [0]=>[band]=>バンド名
// 名前だけ取り出す
array_walk_recursive($_POST["member"],
function($value, $key) use(&$new_member, $pdo){
    if(str_contains($key,"-new")){
        $new_member[] = $value;
    }
});
if(isset($new_member)){
    $new_member = array_unique((array)$new_member);
    $sql = "INSERT into member (name) values(?)";
    $stmt = $pdo->prepare($sql);
    foreach((array)$new_member as $row){
        $stmt->execute([$row]);
    }
}



// foreach($_POST["member"] as $key=>$row){
    
//     $band[] = [
//         "name"
//         ""
//         ""
//         ""
//         ""

//     ];
// }

// print_r(
    // $_POST["member"],
    // $member
// );
var_dump(
    $new_member,
    // $result,
);