<?php

define ('CLI_SCRIPT', true);

require_once(__DIR__.'/../../../config.php');
require_once("$CFG->libdir/clilib.php");

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array('displayonly'=>false,
    'emailonly'=>false, 'both'=>false, 'help'=>false), array('h'=>'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
        $help =
"Email stats settings

Options:
--displayonly   Display stats only. Default
--emailonly     Email the stats only. Must supply username to email.
--both          Both display *and* email the stats. Takes precedent if multiple
				flags set. Must supply username to email.
-h, --help      Print out this help

Example:
\$ sudo -u apache /usr/bin/php email_stats.php
\$ sudo -u apache /usr/bin/php email_stats.php --both=johndoe
\$ sudo -u apache /usr/bin/php email_stats.php --emailonly=johndoe

"; //TODO: localize - to be translated later when everything is finished

    echo $help;
    die;
}

if (!$options['emailonly'] && 
	!$options['displayonly'] &&
	!$options['both']) {
	$options['displayonly'] = true;
}

//print_r($options);

if ($options['emailonly'] && $options['displayonly']) {
	echo "Cannot have 'emailonly' and 'displayonly' set.\n";
	die;
}

if ($options['both'] || $options['displayonly']) {
	printf("%-18s %-15s %12s %12s %13s\n", "Date",
    	"Forum", "Num Opened","Not Opened","% Opened");
}

$todaysdate = userdate(time(), '%Y-%m-%d');

$posthtml = '<style type="text/css">
table, th, td {
    border: 1px solid;
    padding: 10px;
    text-align: left;
    border-collapse: collapse;
}
.title {
    font-weight: bold;
    text-align: center;
}
</style>'.
"<h3>Read Stats for the Daily Digest Emails as of $todaysdate</h3>";
$header =
'<tr>
    <th>Date</th>
    <th style="text-align: right">Num Opened</th>
    <th style="text-align: right">Not Opened</th>
    <th style="text-align: right">Pct Opened</th>
</tr>';

$digestforums = $DB->get_records('digestforum', null, '', 'id, name');


$count = 0;
foreach ($digestforums as $forum) {
    $sql = 
        'SELECT digestdate, digestforumid,
        COUNT(case when numviews > 0 then 1 end) AS NumOpened, 
        COUNT(case when numviews = 0 then 1 end) AS NotOpened 
            FROM {digestforum_tracker} 
    WHERE digestforumid = ?
        GROUP BY digestdate
        ORDER BY digestdate DESC';

    $opened = $DB->get_records_sql($sql, [$forum->id], 0, 7);
    //$opened = $DB->get_records_sql($sql, [$forum->id]);

    $name = preg_replace('/Daily Announcements for /', '', $forum->name);
    $name = preg_replace('/Daily Announcements Only for /', '', $name);

    $thistable = 
'<br><table>
<tr>
    <td colspan="4" class="title">'.$name.'</td>
</tr>
'.$header;

    foreach ($opened as $value) {

        $pctOpened =  100 * ($value->numopened) / ($value->numopened + $value->notopened);

		if ($options['both'] || $options['displayonly']) {
        	printf("%-18s %-15s %12s %12s %12.2f%%\n",

        	getDay($value->digestdate).' '.$value->digestdate,
            	$name,
            	$value->numopened,
            	$value->notopened,
            	$pctOpened);
            	//round($pctOpened, 2));
		}
        $thistable .=
'<tr>
    <td>'.getDay($value->digestdate).' - '.$value->digestdate.'</td>
    <td style="text-align: right">'.$value->numopened.'</td>
    <td style="text-align: right">'.$value->notopened.'</td>
    <td style="text-align: right">'.sprintf("%10.2f%%", $pctOpened).'</td>
</tr>';
    }
    if ($opened) {
        $thistable .= "</table>\n";
        $posthtml .= $thistable;
    }
}

if ($options['both'] || $options['emailonly']) {
    $userto = $DB->get_record('user', ['username' => $options['emailonly']]);

	if (!$userto) {
    	$userto = $DB->get_record('user', ['username' => $options['both']]);
		if (!$userto) {
			echo "Invalid user supplied\n";
			die;
		}
	}

	$mailresult = email_to_user($userto, core_user::get_noreply_user(), "Stats for $todaysdate", format_string($posthtml), $posthtml);

	if (!$mailresult) {
		echo "Error sending email\n";
	}
}

function getDay($datestr) {
	$info = mktime(12, 0, 0,
		substr($datestr, 5, 2),
		substr($datestr, 8, 2),
		substr($datestr, 0, 4));
	return date("D", $info);
	//echo $info."\n";
}
