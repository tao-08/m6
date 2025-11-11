<?php
    $band_label = "バンド名";
    $vocal_label = "Vo(Gt.)";
    $guiter_1_label = "Gt.1";
    $guiter_2_label = "Gt.2";
    $bass_label = "Ba.";
    $drum_label = "Dr.";
    $keybord_label = "Key.";
    $songs_label = "曲数";

    $css = "data_upload";
    $page_title = "新規データ登録";
    require_once("src/component/header.php");
?>
    <h1>新規データ登録</h1>
    <div class="box shadow_1">
        <form action="" method="post">
            <div class="input_content">
                <b>ライブ名</b>
                <input type="text" name="title" class="text_input" value="<?php if(!empty($_POST["title"])){echo $_POST["title"];}?>" placeholder="◯月ライブ">
            </div>
            <div class="input_content">
                <hr>
                <b>列名の設定</b>
                    <table class="small_table shadow_2">
                        <tr class="label">
                            <td>バンド名</td>
                            <td>Vo(Gt.)</td>
                            <td>Gt.1</td>
                            <td>Gt.2</td>
                            <td>Ba.</td>
                            <td>Dr.</td>
                            <td>Key.</td>
                            <td>曲数</td>
                        </tr>
                        <tr class="label_2">
                            <td><input type="text" name="band_label" class="input_label" value="<?php echo $band_label?>"></td>
                            <td><input type="text" name="vocal_label" class="input_label" value="<?php echo $vocal_label?>"></td>
                            <td><input type="text" name="guiter_1_label" class="input_label" value="<?php echo $guiter_1_label?>"></td>
                            <td><input type="text" name="guiter_2_label" class="input_label" value="<?php echo $guiter_2_label?>"></td>
                            <td><input type="text" name="bass_label" class="input_label" value="<?php echo $bass_label?>"></td>
                            <td><input type="text" name="drum_label" class="input_label" value="<?php echo $drum_label?>"></td>
                            <td><input type="text" name="keybord_label" class="input_label" value="<?php echo $keybord_label?>"></td>
                            <td><input type="text" name="songs_label" class="input_label" value="<?php echo $songs_label?>"></td>
                        </tr>
                    </table>            
            </div>
        </form>
		<hr>
		<div class="row">
			<form action="" method="POST" enctype="multipart/form-data">
				<b>名簿.SCV アップロード</b>
				<input type="file" name="file_member" class="button_big" accept=".csv">
				<input type="submit" name="preview" value="プレビュー" class="button_1">
			</form>
		</div>
    </div>

	<?php
    //フォームから列名を変数に
    if(!empty($_post["preview"])){
        $band_label = $_POST["band_label"];
        $vocal_label = $_POST["vocal_label"];
        $guiter_1_label = $_POST["guiter_1_label"];
        $guiter_2_label = $_POST["guiter_2_label"];
        $bass_label = $_POST["bass_label"];
        $keybord_label = $_POST["keybord_label"];
        $drum_label = $_POST["drum_label"];
        $songs_label = $_POST["songs_label"];
    }

    //プレビューモード
    if(isset($_POST["preview"]) && isset($_FILES['file_member']) && is_uploaded_file($_FILES['file_member']['tmp_name'])):
        $band = [];

        //一時ファイルのパスを取得
        $path = $_FILES['file_member']['tmp_name'];
        if(($handle = fopen($path,"r")) !== false):

            //CSVのヘッダー名からどのパートが何番にあるか調べる
            // 1行目だけ読み込み
            $header = fgetcsv($handle);
            $key_band = array_search($band_label,$header);
            $key_vocal = array_search($vocal_label,$header);
            $key_guiter_1 = array_search($guiter_1_label,$header);
            $key_guiter_2 = array_search($guiter_2_label,$header);
            $key_bass = array_search($bass_label,$header);
            $key_drum = array_search($drum_label,$header);
            $key_keybord = array_search($keybord_label,$header);
            $key_songs = array_search($songs_label,$header);
            ?>

<div class="banner">インポートファイルの編集</div>


            <!-- プレビューテーブルの見出しだけ（フォームの値反映） -->
            <div>
                <form action='band_register.php' method='POST'>
                    <table class='table_preview shadow_1'>
                        <tr>
                            <th><?= $band_label ?></th>
                            <th><?= $vocal_label ?></th>
                            <th><?= $guiter_1_label ?></th>
                            <th><?= $guiter_2_label ?></th>
                            <th><?= $bass_label ?></th>
                            <th><?= $drum_label ?></th>
                            <th><?= $keybord_label?></th>
                            <th><?= $songs_label ?></th>
                        </tr>

                        <!-- 配列変数にバンド情報入れる -->
                        <?php
                        $n = 0;//nは0からなので注意
                        while(($row = fgetcsv($handle)) !== false):
                            $band[] = [
                                
                                //バンド名以外空白を除去して2次元配列へ
                                "name"=>$row[$key_band],
                                "vocal"=>preg_replace("/[\s　]+/u","",$row[$key_vocal]),
                                "guiter_1"=>preg_replace("/[\s　]+/u","",$row[$key_guiter_1]),
                                "guiter_2"=>preg_replace("/[\s　]+/u","",$row[$key_guiter_2]),
                                "bass"=>preg_replace("/[\s　]+/u","",$row[$key_bass]),
                                "drum"=>preg_replace("/[\s　]+/u","",$row[$key_drum]),
                                "keybord"=>preg_replace("/[\s　]+/u","",$row[$key_keybord]),
                                "songs"=>preg_replace("/[\s　]+/u","",$row[$key_songs])
                                
                            ];?>

                            <!-- プレビューテーブル -->
                            <tr>
                                <td><input type='text' class='band_preview' name='<?= $n."_"; ?>preview_band' data-master-name='<?= $n."_"; ?>preview_band' value="<?= htmlspecialchars($row[$key_band],ENT_QUOTES,'UTF-8')?>"></td>
                                <td><input type='text' class='text_preview' name='<?= $n."_"; ?>preview_vocal' data-master-name='<?= $n."_"; ?>preview_vocal' value="<?= $band[$n]['vocal']?>"></td>
                                <td><input type='text' class='text_preview' name='<?= $n."_"; ?>preview_guiter_1' data-master-name='<?= $n."_"; ?>preview_guiter_1' value="<?= $band[$n]['guiter_1']?>"></td>
                                <td><input type='text' class='text_preview' name='<?= $n."_"; ?>preview_guiter_2' data-master-name='<?= $n."_"; ?>preview_guiter_2' value="<?= $band[$n]['guiter_2']?>"></td>
                                <td><input type='text' class='text_preview' name='<?= $n."_"; ?>preview_bass' data-master-name='<?= $n."_"; ?>preview_bass' value="<?= $band[$n]['bass']?>"></td>
                                <td><input type='text' class='text_preview' name='<?= $n."_"; ?>preview_drum' data-master-name='<?= $n."_"; ?>preview_drum' value="<?= $band[$n]['drum']?>"></td>
                                <td><input type='text' class='text_preview' name='<?= $n."_"; ?>preview_keybord' data-master-name='<?= $n."_"; ?>preview_keybord' value="<?= $band[$n]['keybord']?>"></td>
                                <td><input type='tel' class='songs_preview' name='<?= $n."_"; ?>preview_songs' value="<?= $band[$n]['songs']?>"></td>
                            </tr>
                            <?php
                            $n++;
                            // echo $n;
                            // var_dump($band)
                            ?>

                        <?php endwhile;?>

                        <!-- アップロードボタンフィールド -->
                        <tr id="uplord_submit">
                            <td colspan="8">
                                    <input type='submit' name='preview_submit' class='submit_button bigger' value='アップロード'>
                                    <input type="hidden" name="number" value="<?=$n?>">
                                    <input type="hidden" name="new" id="new" value="">
                                </td>
                            </tr>
                    </table>
                </form>
            </div>
            <?php
            if(!empty($_POST["preview_submit"])){
                require_once "band_register.php";
            }?>

            <!-- <?=$result;?> -->
        <?php endif;?>
    <?php endif;?>
		
    <!-- タイムテーブルアップロード -->
    <div class="box">
        <hr>
        <div class="row">
            <b>日程</b>
            <select name="category">
                <option value="day1">1日目・1日のみ</option>
                <option value="day2">2日目</option>
                <option value="day3">3日目</option>
                <option value="day4">4日目</option>
                <option value="day5">5日目</option>
            </select>
        </div>
        <hr>
        <div class="row">

            <!-- 会場の選択肢をDBから取得 -->
            <b>会場</b>
            <select name="venue">
                <option value=""></option>
                <?php
                    $sql="SELECT id_venue,name FROM venue";
                    $stmt = $pdo->query($sql);
                    $result = $stmt->fetchAll();
                    foreach ($result as $row) : ?>
                        <option value='<?=$row["id_venue"]?>'><?=$row["name"]?></option>
                    <?php endforeach;?>
                <option name="new">新規作成する</option>
            </select>
            <span class="circle">新規作成 <input type="text" name="new_venue" class="text_input" placeholder="会場名"></span>
        </div>
        <hr>
        <div class="row">
            <b>日付</b>
            <input type="date" name="date">
        </div>

    </div>

    <script>

        //プレビューテーブルがDBの名前と一致
        // 一致するか検証してクラス変える関数の定義
        const validateUsername = async(inputElement) => {
            const input_name = inputElement.value;

            // なんもなかったらvalueを消す
            if (input_name === "") {
                inputElement.classList.remove("valid","invalid");
                return;
            }
            // check.phpに入力内容送信
            try{
                const responce = await fetch("preview_name_check.php",{
                    method: "POST",
                    headers:{
                        "Content-Type":'application/x-www-form-urlencoded',
                    },
                    body: "input_name="+ encodeURIComponent(input_name)
                });
                
                // サーバーからの結果受取
                const result = await responce.json();
                
                // 結果に応じてクラスとname変更
                // もとのname属性を保存
                const master_name = inputElement.getAttribute("data-master-name");

                // DBにいる
                if (result.exists) {
                    inputElement.classList.add("valid");
                    inputElement.classList.remove("invalid");
                    inputElement.setAttribute("name",master_name);

                // DBにいない
                }else{
                    inputElement.classList.add("invalid");
                    inputElement.classList.remove("valid");

                    // const new_input = document.getElementById("new");
                    // new_input.value = 
                    const new_name = inputElement.getAttribute("name");
                    if(!new_array.includes(new_name)){
                        new_array.push(new_name);
                    }

                    // inputのname属性を変更
                    // const new_name = master_name + "_new";
                    // inputElement.setAttribute("name",new_name);
                }
            }catch(error){
                console.error("通信エラー:",error);
            }
        };

        // ロード完了後
        let new_array = [];
        document.addEventListener("DOMContentLoaded",() =>{

            // text_previewクラスを持つ全要素取得
            const text_preview_element = document.querySelectorAll(".text_preview");
            text_preview_element.forEach((input) => {
                validateUsername(input);               
                // 入力待ちタイマー
                let typingTimer;

                // 入力を検知
                input.addEventListener("input",() => {
                    clearTimeout(typingTimer);

                    // タイマー切れたら検証
                    typingTimer = setTimeout(() =>{
                        validateUsername(input);
                    },30);
                });
            });
        });
            
        const togglebuttons = document.querySelectorAll(".toggle_button");
        togglebuttons.forEach(button => {
            button.addEventListener("click",() => {
                const content = button.nextElementSibling;
                content.classList.toggle("active");
            })
        });
    </script>
</body>
</html>