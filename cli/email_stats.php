<?php

define ('CLI_SCRIPT', true);

require_once(__DIR__.'/../../../config.php');


$digestforums = $DB->get_records('digestforum', null, '', 'id, name');

foreach ($digestforums as $forum) {
	$sql = 
		'SELECT digestdate, digestforumid,
		COUNT(case when numviews > 0 then 1 end) AS NumOpened, 
		COUNT(case when numviews = 0 then 1 end) AS NotOpened 
		FROM {digestforum_tracker} 
		WHERE digestforumid = ?
		GROUP BY digestforumid, digestdate
		ORDER BY digestdate DESC';

	$opened = $DB->get_records_sql($sql, [$forum->id], 0, 20);
	
	foreach ($opened as $value) {
		echo $value->digestdate.",".
			$forum->name.",".
			$value->numopened.",".
			$value->notopened."\n";
	}
}
