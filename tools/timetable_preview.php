<?php

// 
$file_timetable = new SplFileObject($_FILES["file_timetable"]["tmp_name"],"r");
$timetable_label = false;
$file_timetable->setFlags(SplFileObject::READ_CSV|SplFileObject::SKIP_EMPTY);
$lines = 0;
	while($timetable_label === false && $file_timetable->valid()) {
		$array_timetable = $file_timetable->current();
		if($timetable_label = strpos($file_timetable,"集合") !== false){
			$band_column = array_search("集合",$array_timetable);
		}
		$file_timetable->next();
		$lines++;
	}
echo $band_column+1;
echo $lines;?>
