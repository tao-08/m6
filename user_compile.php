<?php
$page_title = "ユーザー情報編集";
require_once("src/component/header.php");

if(isset($_POST["submit"])){
    if(!empty($_POST["compile_id"]) && !empty($_POST["compile_name"]) && !empty($_POST["compile_ruby"]) && !empty($_POST["compile_pass"])){
        
        //DBで編集

        //idで検索してauto_idを取得
        $id = $_SESSION["id"];
        $sql = "SELECT auto_id from user_index WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":id", $id, pdo::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchAll();
        foreach($result as $row){
            $auto_id = $row["auto_id"];
        }

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
?>


    <form action="" method="POST">

    <h1>ユーザー情報編集</h1>
    <div class="box_2 shadow_1">
        <table class="separate input_content">
            <tr>
                <th>
                    ログインID
                </th>
                <td>
                    <input type="text" name="compile_id" value="<?php
                        if(!empty($_SESSION["id"])){
                        echo $_SESSION["id"];}
                    ?>">
                </td>
            </tr>
            <tr>
                <th>
                    氏名
                </th>
                <td>
                    <input type="text" name="compile_name" value="<?php if(!empty($_SESSION["name"])) echo $_SESSION["name"];?>">
                </td>
            </tr>
            <tr>
                <th>
                    ふりがな
                </th>
                <td>
                    <input type="text" name="compile_ruby" value="<?php if(!empty($_SESSION["ruby"])) echo $_SESSION["ruby"];?>">
                </td>
            </tr>
            <tr>
                <th>
                    パスワード
                </th>
                <td>
                    <input type="text" name="compile_pass" value="<?php if(!empty($_SESSION["password"])) echo $_SESSION["password"];?>" placeholder="変更後のパスワード">
                </td>
            </tr>


        </table>
        <div>
                <a href="/" class="submit_button bigger back_button">戻る</a>
                <input type="submit" name="submit" class="submit_button bigger" value="更新">
                <?php if(!empty($alert)){echo $alert;}?>
        </div>
    </div>

    </form>

</body>
</html>
