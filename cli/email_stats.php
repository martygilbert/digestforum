<?php

define ('CLI_SCRIPT', true);

require_once(__DIR__.'/../../../config.php');

echo "Date,Forum,Num Opened,Not Opened,% Opened\n";
$digestforums = $DB->get_records('digestforum', null, '', 'id, name');


foreach ($digestforums as $forum) {
	$sql = 
		'SELECT digestdate, digestforumid,
		COUNT(case when numviews > 0 then 1 end) AS NumOpened, 
		COUNT(case when numviews = 0 then 1 end) AS NotOpened 
			FROM {digestforum_tracker} 
	WHERE digestforumid = ?
		GROUP BY digestdate
		ORDER BY digestdate DESC';

	//$opened = $DB->get_records_sql($sql, [$forum->id], 0, 20);
	$opened = $DB->get_records_sql($sql, [$forum->id]);

	foreach ($opened as $value) {
		$pctOpened =  100 * ($value->numopened) / ($value->numopened + $value->notopened);
		echo $value->digestdate.",".
			$forum->name.",".
			$value->numopened.",".
			$value->notopened.",".
			round($pctOpened, 2)."%\n";
	}
}
