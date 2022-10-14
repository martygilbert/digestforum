<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__.'/../../config.php');

$title = 'Download All DigestForum Stats';
$pagetitle = $title;
$url = new moodle_url("/mod/digestforum/downloadstats.php");

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
} else {
	$filename = date("Y_m_d", time()).'_DigestForumData.csv';
	header('Content-type: application/ms-excel');
	header('Content-Disposition: attachment; filename='.$filename);

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
			$name = preg_replace('/Daily Announcements for /', '', $forum->name);
			$pctOpened =  100 * ($value->numopened) / ($value->numopened + $value->notopened);
			echo $value->digestdate.",".
				$name.",".
				$value->numopened.",".
				$value->notopened.",".
				round($pctOpened, 2)."%\n";
		}
	}
}
