<!DOCTYPE html>
<html>

<head>
	<title>Digest Post - Open Rate</title>
	<script src='node_modules/chart.js/dist/Chart.js'></script>
</head>

<body>
<?php

require_once(__DIR__.'/../../../config.php');

$title = 'View Graph of Digest Open Rates';
$pagetitle = $title;
$url = new moodle_url("/mod/digestforum/statsgraph/index.php");

$sitecontext = context_system::instance();
$PAGE->set_context($sitecontext);

$PAGE->set_url($url);
$PAGE->set_title($title);
$PAGE->set_heading($title);


require_login();
if (!has_capability('moodle/site:config', $sitecontext, ($USER->id))) {
	echo "No capability ERROR";
	echo "about to redirect";
	$index = new moodle_url($CFG->wwwroot);
	redirect($index, 'You do not have permission to generate this
			report.', null, \core\output\notification::NOTIFY_ERROR);
}

$labels = array();
$forumdata = array();

// Load the LTE data and labels from a file.
$digestforums = $DB->get_records('digestforum', null, '', 'id, name');
foreach ($digestforums as $forum) {
    $sql = 
        'SELECT digestdate, digestforumid,
        COUNT(case when numviews > 0 then 1 end) AS NumOpened, 
        COUNT(case when numviews = 0 then 1 end) AS NotOpened 
            FROM {digestforum_tracker} 
    WHERE digestforumid = ?
        GROUP BY digestdate
        ORDER BY digestdate';

	// 90-day window
    $opened = $DB->get_records_sql($sql, [$forum->id], 0, 90);
    $name = preg_replace('/Daily Announcements for /', '', $forum->name);
    $name = preg_replace('/Daily Announcements Only for /', '', $name);

	$forumdata[$name]['name'] = $name;
    foreach ($opened as $value) {
        $pctOpened =  100 * ($value->numopened) / ($value->numopened + $value->notopened);

		$forumdata[$name]['data']['"'.$value->digestdate.'"'] = round($pctOpened, 2);
		if (!in_array('"'.$value->digestdate.'"', $labels)) $labels[] = '"'.$value->digestdate.'"';
	}
}

asort($labels);
$first = $labels[0];
$last = $labels[count($labels) - 1];

$period = new DatePeriod(
	new DateTime(preg_replace('/"/','', $first)),
	new DateInterval('P1D'),
	new DateTime(preg_replace('/"/','', $last))
);

unset($labels);
foreach($period as $key => $value) {
	$labels[] = '"'.$value->format('Y-m-d').'"';
}

foreach ($forumdata as &$fdata) {
	foreach($labels as $label) {
		if(!isset($fdata['data'][$label])) {
			//echo "$label NOT SET! ".$fdata['name']."<br>";

			$fdata['data'][$label] = 'NaN';
			//echo $fdata['data'][$label]."<br>\n";
		}
	}
	ksort($fdata['data']);
}

$labelstring = implode(",", $labels);
//echo $labelstring.'<br>';

//echo print_r($forumdata, true).'<br>';

$colors = [
	'blue',
	'purple',
	'green',
	'red',
	'orange',
	'yellow',
	'grey',
];

?>

<canvas id="myChart"></canvas>
<script>

window.chartColors = {
	red: 'rgb(255, 99, 132)',
	orange: 'rgb(255, 159, 64)',
	yellow: 'rgb(255, 205, 86)',
	green: 'rgb(75, 192, 192)',
	blue: 'rgb(54, 162, 235)',
	purple: 'rgb(153, 102, 255)',
	grey: 'rgb(201, 203, 207)'
};

var ctx = document.getElementById('myChart').getContext('2d');
var color = Chart.helpers.color;
var myChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: [<?= $labelstring ?>],
<?php
echo  '
		datasets:[';
foreach ($forumdata as $fodata) {
		$color = array_shift($colors);
        echo '{
			backgroundColor: window.chartColors.'.$color.',
			borderColor: window.chartColors.'.$color.',
            label: \''.$fodata['name'].'\',
            data: ['.implode(",", $fodata['data']).'],
			fill: false,
        }, ';
}
?>
]
    },
	options: {
		responsive: true,
		title: {
			display: true,
			text: 'MHU Daily Digest - Open Rate'
		},
		hover: {
			mode: 'nearest',
			intersect: true
		},
		scales: {
			xAxes: [{
				distribution: 'linear',
				ticks: {
					callback: function(value, index, values) {
						const [year, month, day] = value.split('-');	
						//console.log(value + " " + index + " " + values);
						let str = new Date(+year, +month - 1, +day).toDateString();
						//console.log(value + " - " + str);
						return str;
						/*
						if (index % 5 === 0) {
							return dataLabel;
							//var day = moment.unix(dataLabel);
							//return day.format("MM/DD/YY HH:mm");
						} else return '';
						*/
					}
				},
				scaleLabel: {
					display: true,
					labelString: 'Date'
				}
			}],
			yAxes: [{
				ticks: {
					suggestedMax: 100,
					suggestedMin: 0,
					callback: function(dataLabel, index){
						return dataLabel + '%';
					},
				},
				scaleLabel: {
					display: true,
					labelString: 'Open Rate (%)'
				}
			}]
		}
	}
});
</script>
</body>
</html>
