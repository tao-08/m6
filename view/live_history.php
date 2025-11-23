<h1>ライブデータ</h1>
<?php foreach ($result as $live_master_name => $row) :?>

	<h2><?= $live_master_name ?></h2>
		
	<?php foreach($row as $live_detail): ?>
		<div class="box_1">
			<h3><?= $live_detail["day"] ?></h3>
			<div class="bigger"><?= $live_detail["date"] ?> 会場：<?= $live_detail["name"] ?></div>
			<div class="">
				<table>
					<?php foreach($member as $live_detail_id=>$row):?>
						<?php if($live_detail_id !== $live_detail["id_live_detail"]){continue;}?>
						<?php foreach($row as $key=>$band):?>
							<tr class="bigger">
								<th><?= $band["name"] ?></th>
								<td><?= $band["songs"] ?>曲</td>
						<?php foreach($band as $column=>$name): if($column === "id_band"|$column === "name"|$column === "order_live"|$column === "songs"){continue;}?>
									<td><?= $name ?></td>
									<?php endforeach?>
								</tr>
						<?php endforeach?>
					<?php endforeach?>
				</table>
			</div>
		</div>
	<?php endforeach?>
<?php endforeach?>;