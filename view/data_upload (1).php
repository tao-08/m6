<h1>新規ライブデータ登録</h1>

<!-- タイムテーブルアップロード -->
<div class="box_2 shadow_1">
    <div class="scroll_2">
        <table class="separate input_content table_timetable_upload">
            <tr>
                <th>タイムテーブル.csv</th>
                <td>
                    <form action="" method="POST" enctype="multipart/form-data">
                        <input type="file" name="file_timetable" class="file_timetable" accept=".csv">
                    </td>
                </tr>
            <tr>
                <td colspan="2" style="text-align: center;">
                    <input type="submit" name="preview_timetable" value="プレビュー" class="button_1">
                </form>
            </td>
        </tr>
        <tr>
            <td class="border-top" style="color: gray; text-align: center;" colspan="2">以下の項目はプレビューで自動選択されます</td>
        </tr>
    </table>
</div>
</div>

<?php if(isset($_POST["preview_timetable"]) && !empty($_FILES["file_timetable"]["name"])):?>
    <div class="table_preview timetable_preview shadow_1">
        <div class="scroll_2">
		<form action="/tools/data_upload/band_register.php" method="post">
        <table class="center only_row">
            <tr>
                <th>演奏順</th>
                <th>バンド名</th>
                <th>曲数</th>
            </tr>
            <?php foreach($band_info as $key=>$band_row): ?>
                <tr>
                    <td><input name="band_data[<?= $key ?>][band_number]" value="<?= $band_row["number"] ?>" type="tel"class="number_preview"></td>
                    <td><input name="band_data[<?= $key ?>][band_name]" value="<?= $band_row["name"] ?>" type="text"class="band_preview"></td>
                    <td><input name="band_data[<?= $key ?>][band_songs]" value="<?= $band_row["songs"] ?>" type="tel"class="number_preview"></td>
                </tr>
                <?php endforeach?>
            </table>
            <table class="timetable_select center  only_row" style="border-top: 1px gray solid; padding-top: 1rem; margin-top: 1rem;">
                <tr class="input_content">
                    <th class="bigger">ライブ名</th>
                    <td><input type="text" class="text_input" style="width: 10em;" name="live_name" value="<?= $live_name ?>"></td>
                </tr>
                <tr>
                    <th class="bigger">日付</th>
                    <td>
                        <input type="date" name="date" style="margin:0 0.5rem; width: 12em;height:2rem" id="date">
                    </td>
                </tr>
                <tr>
                    <th class="bigger">日程</th>
                    <td>
                        <select name="days">
                            <option value="1日目" <?= $live_day_selected[1] ?>>1日目・1日のみ</option>
                            <option value="2日目" <?= $live_day_selected[2] ?>>2日目</option>
                            <option value="3日目" <?= $live_day_selected[3] ?>>3日目</option>
                            <option value="4日目" <?= $live_day_selected[4] ?>>4日目</option>
                            <option value="教室ライブ" <?= $live_day_selected[5] ?>>教室ライブ</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th class="bigger" style="padding:0 1.5em;">会場</th>
                    <!-- 会場の選択肢をDBから取得 -->
                    <td>
                        <select name="venue" id="venue">
                            <option value=""></option>
                            <?php foreach ($result as $key=>$row) : ?>
                            <option value='<?=$row["id_venue"]?>'<?=$venue_complete["n".$key]?>><?=$row["name"]?></option>
                            <?php endforeach;?>
                            <option value="new" <?= $new_venue ?>>新規作成する</option>
                        </select>
                    </td>
                </tr>
                <tr id="new_venue">
                    <th class="bigger">新規会場</th>
                    <td class="input_content">
                        <input type="text" name="new_venue" class="text_input" placeholder="会場名" value="<?php if($new_venue = "selected"){echo $live_venue;} ?>">
                    </td>
                </tr>
                <tr>
                    <td colspan="3" class="text_center">
                        <input type="submit" class="submit_button bigger button_color_yellow" value="別日程を登録" name="next_day">
                        <input type="submit" class="submit_button bigger" value="次へ" name="next">
                    </td>
                </tr>
            </table>
        </div>
        </form>
</div>
<?php endif;
// var_dump($live_venue);echo PHP_EOL;
// var_dump($venue_complete,$result);
// echo $row_venue["name"]?>

<script src="/scripts/band_upload.js"></script>
</body>
</html>