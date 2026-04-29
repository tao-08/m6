<h1>ライブデータ</h1>
<?php foreach ($result as $live_master_name => $row) :?>

	<h2><?= $live_master_name ?></h2>
		
	<?php foreach($row as $live_detail): ?>
		<div class="box_1">
			<h3><?= $live_detail["day"] ?></h3>
			<div class="bigger"><?= $live_detail["date"] ?> 会場：<?= $live_detail["venue_name"] ?></div>
			<div class="">
				<table>
					<?php foreach($member as $live_detail_id=>$row):?>
						<?php if($live_detail_id !== $live_detail["live_detail_id"]){continue;}?>
						<?php foreach($row as $key=>$band):?>
							<tr class="bigger">
								<th><?= $band["band_name"] ?></th>
								<td><?= $band["songs"] ?>曲</td>
						<?php foreach($band as $column=>$name): if($column === "band_id"|$column === "band_name"|$column === "live_order"|$column === "songs"){continue;}?>
									<td><?= $name ?></td>
									<?php endforeach?>
								</tr>
						<?php endforeach?>
					<?php endforeach?>
					<?php foreach($live_detail['members'] as $band):?>
						<tr class="bigger">
							<th><?= htmlspecialchars($band["name"], ENT_QUOTES, 'UTF-8') ?></th>
							<td><?= htmlspecialchars($band["songs"], ENT_QUOTES, 'UTF-8') ?>曲</td>
					<?php foreach($band as $column => $value): if(in_array($column, ["live_detail_id", "band_id", "band_name", "live_order", "songs"])){continue;}?>
								<td><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></td>
								<?php endforeach?>
							</tr>
					<?php endforeach; ?>
				</table>
			</div>
		</div>
	<?php endforeach?>
<?php endforeach?>;