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

<?php if(isset($_POST["preview_timetable"]) && isset($_FILES["file_timetable"])):?>
<div class="table_preview timetable_preview shadow_1">
    <form action="/band_register.php">
        <table class="center">
            <tr>
                <th>演奏順</th>
                <th>バンド名</th>
                <th>曲数</th>
            </tr>
            <?php foreach($band_info as $key=>$band_row): ?>
                <tr>
                    <td><input name="band_number" value="<?= $band_row["number"] ?>" type="tel"class="number_preview"></td>
                    <td><input name="band_name" value="<?= $key .$band_row["name"] ?>" type="text"class="band_preview"></td>
                    <td><input name="band_songs" value="<?= $band_row["songs"] ?>" type="tel"class="number_preview"></td>
                </tr>
            <?php endforeach?>
        </table><hr>
        <table class="center timetable_preview" style="margin-top: 1em;">
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
            <tr>
                <td colspan="3" class="text_center"><input type="submit" class="submit_button bigger" value="次へ" name="next"></td>
            </tr>
        </table>
    </form>
</div>
<?php endif?>

<script src="scripts/data_upload.js"></script>
</body>
</html>