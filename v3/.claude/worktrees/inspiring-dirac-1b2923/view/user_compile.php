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
            <?php if(!empty($alert)){echo $alert;}?>
        </div>
        <div>
                <a href="/" class="submit_button bigger back_button">戻る</a>
                <input type="submit" name="submit" class="submit_button bigger" value="更新">
        </div>
    </div>

    </form>

</body>
</html>