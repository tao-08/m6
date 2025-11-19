<h1>新規ライブデータ登録</h1>
<form action="" method="POST" enctype="multipart/form-data">
	<div class="box_1 shadow_1">
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
					</tr>
					<tr class="label_2">
						<td><input type="text" name="band_label" class="input_label" value="<?= $band_label?>"></td>
						<td><input type="text" name="vocal_label" class="input_label" value="<?= $vocal_label?>"></td>
						<td><input type="text" name="guiter_1_label" class="input_label" value="<?= $guiter_1_label?>"></td>
						<td><input type="text" name="guiter_2_label" class="input_label" value="<?= $guiter_2_label?>"></td>
						<td><input type="text" name="bass_label" class="input_label" value="<?= $bass_label?>"></td>
						<td><input type="text" name="drum_label" class="input_label" value="<?= $drum_label?>"></td>
						<td><input type="text" name="keybord_label" class="input_label" value="<?= $keybord_label?>"></td>
					</tr>
				</table>
			</div>
		</div>
        <hr>
        <div class="row">
			<b>名簿.SCV アップロード</b>
			<input type="file" name="file_member" class="button_big" accept=".csv">
			<input type="submit" name="preview" value="プレビュー" class="button_1">
		</div>
	</div>
</form>
    <!-- プレビューテーブルの見出しだけ（フォームの値反映） -->
    <?php if(isset($_POST["preview"]) && isset($_FILES['file_member']) && is_uploaded_file($_FILES['file_member']['tmp_name'])):?>

    <div class='banner'>インポートファイルの編集</div>
    <div class="scroll_1">
        <form action='/tools/data_upload/member_register.php' method='POST'>
            <table class='table_preview shadow_1'>
                <tr>
                    <th><?= $band_label ?></th>
                    <th><?= $vocal_label ?></th>
                    <th><?= $guiter_1_label ?></th>
                    <th><?= $guiter_2_label ?></th>
                    <th><?= $bass_label ?></th>
                    <th><?= $drum_label ?></th>
                    <th><?= $keybord_label?></th>
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
                    ];?>

                    <tr>
                        <td><input type='text' class='band_preview' name='member[<?= $n ?>][band]' value="<?= htmlspecialchars($row[$key_band],ENT_QUOTES,'UTF-8')?>"></td>
                        <td><input type='text' class='text_preview' name='member[<?= $n ?>][vocal]' value="<?= $band[$n]['vocal']?>"></td>
                        <td><input type='text' class='text_preview' name='member[<?= $n ?>][guiter_1]' value="<?= $band[$n]['guiter_1']?>"></td>
                        <td><input type='text' class='text_preview' name='member[<?= $n ?>][guiter_2]' value="<?= $band[$n]['guiter_2']?>"></td>
                        <td><input type='text' class='text_preview' name='member[<?= $n ?>][bass]' value="<?= $band[$n]['bass']?>"></td>
                        <td><input type='text' class='text_preview' name='member[<?= $n ?>][drum]' value="<?= $band[$n]['drum']?>"></td>
                        <td><input type='text' class='text_preview' name='member[<?= $n ?>][keybord]' value="<?= $band[$n]['keybord']?>"></td>
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
    <a href="/data_upload" class="center back_button bigger">戻る</a>
</body>
<script src="/scripts/data_upload.js"></script>
</html>