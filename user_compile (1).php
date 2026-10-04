<?php
$page_title = "ユーザー情報編集";
require_once __DIR__."/src/component/header.php";

if(isset($_POST["submit"])){

    $compile_pass = str_replace(" ","",$_POST["compile_pass"]);
    if(!empty($_POST["compile_id"]) && !empty($_POST["compile_name"]) && !empty($_POST["compile_ruby"]) && !empty($compile_pass)){
        
        //DBで編集
        //変数にフォームの変更内容を入れる
        $id = $_POST["compile_id"];
        $name = $_POST["compile_name"];
        $ruby = $_POST["compile_ruby"];
        $password = password_hash($_POST["compile_pass"],PASSWORD_DEFAULT);
        
        //auto_idの場所に変更内容をDBで更新
        $sql = "UPDATE user_index SET name = :name,ruby = :ruby,password = :password,id = :id WHERE auto_id =:compile_id";
        $stmt = $pdo->prepare($sql);
        $stmt -> bindParam(":name",$name,pdo::PARAM_STR);
        $stmt -> bindParam(":ruby",$ruby,pdo::PARAM_STR);
        $stmt -> bindParam(":compile_id",$_SESSION["auto_id"],pdo::PARAM_STR);
        $stmt -> bindParam(":password",$password,pdo::PARAM_STR);
        $stmt -> bindParam(":id",$id,pdo::PARAM_STR);
        $result = $stmt->execute();
        
        //sessionに変更内容を保存
        $_SESSION["id"] = $id;
        $_SESSION["name"] = $name;
        $_SESSION["ruby"] = $ruby;
        
        $alert = "更新しました";
        
    }else{
        $alert = "空欄の項目があります";
    }
}
require_once __DIR__."/view/user_compile.php";