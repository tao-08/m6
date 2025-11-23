<h1>ライブデータ</h1>
<?php foreach ($result as $live_master_name => $row) :?>

	<h2><?= $live_master_name ?></h2>
	<table>
		
	<?php foreach($row as $live_detail): ?>
		<h3><?= $live_detail["day"] ?></h3>
		<tr>
			
		</tr>
	<?php endforeach?>
	</table>
<?php endforeach?>