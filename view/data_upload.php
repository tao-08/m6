<h1>新規ライブデータ登録</h1>

<!-- タイムテーブルアップロード -->
<div class="box_2 shadow_1">
    <div class="scroll_2">
        <table class="separate input_content table_timetable_upload">
            <tr>
                <th class="bigger">日付</th>
                <td>
                    <input type="date" name="date" style="width: 12.25em;height:2.5em">
                </td>
                </tr>
                <form action="" method="POST" enctype="multipart/form-data">
                    <tr>
                        <th>タイムテーブル.csv</th>
                        <td>
                            <input type="file" name="file_timetable" class="file_timetable" accept=".csv">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" style="text-align: center;">
                            <input type="submit" name="preview_timetable" value="プレビュー" class="button_1">
                        </td>
                    </tr>
                </form>
                <tr>
                    <td class="border-top" style="color: gray; text-align: center;" colspan="2">以下の項目はプレビューで自動選択されます</td>
                </tr>
                <tr>
                    <th class="bigger">日程</th>
                    <td>
                        <select name="category">
                            <option value="day1">1日目・1日のみ</option>
                            <option value="day2">2日目</option>
                            <option value="day3">3日目</option>
                            <option value="day4">4日目</option>
                            <option value="day5">5日目</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th class="bigger">会場</th>
                    <!-- 会場の選択肢をDBから取得 -->
                    <td>
                        <select name="venue" id="venue">
                            <option value=""></option>
                            <?php foreach ($result as $row) : ?>
                                <option value='<?=$row["id_venue"]?>'><?=$row["name"]?></option>
                            <?php endforeach;?>
                            <option value="new">新規作成する</option>
                        </select>
                    </td>
                </tr>
                <tr id="new_venue">
                    <th class="bigger">新規会場</th>
                    <td>
                        <input type="text" name="new_venue" class="text_input" placeholder="会場名">
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <?php if(isset($_POST["preview_timetable"]) && isset($_FILES["file_timetable"])){require_once __DIR__."/../tools/timetable_preview.php";} ?>

    <div class="box_1 shadow_1">
        <form action="" method="post">
            <div class="input_content">
                <b>csvファイル 列名の設定</b>
                <div class="scroll_2">
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
    <!-- プレビューテーブルの見出しだけ（フォームの値反映） -->
    <?php if(isset($_POST["preview"]) && isset($_FILES['file_member']) && is_uploaded_file($_FILES['file_member']['tmp_name'])):?>

    <div class='banner'>インポートファイルの編集</div>
    <div class="scroll_1">
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
                <!-- プレビューテーブル -->
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
                    
                    <?php $n++;?>
                    <?php endwhile;?>
                    
                    <!-- アップロードボタンフィールド -->
                    <tr id="uplord_submit text_center">
                        <td colspan="8" class="text_center">
                            <input type='submit' name='preview_submit' class='submit_button bigger' value='アップロード'>
                            <input type="hidden" name="number" value="<?=$n?>">
                            <input type="hidden" name="new" id="new" value="">
                        </td>
                    </tr>
                </table>
        </form>
    </div>
    <?php endif;?>
    <script src="scripts/data_upload.js"></script>
</body>
</html>