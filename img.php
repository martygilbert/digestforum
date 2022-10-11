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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/digestforum/lib.php');

$id     = optional_param('id', 0, PARAM_INT);       // DigestForumId
$uid    = optional_param('uid', null, PARAM_INT);   // UserID
$date   = optional_param('date', null, PARAM_TEXT);  // Date


error_log('Message info: '.$id.' - '.$uid.' - '.$date);
header('Content-type: image/png');
readfile('pix/blank.png');

global $DB;

$data = new stdClass();
$data->digestforumid = $id;
$data->mdluserid = $uid;
$data->digestforumdate = $date;
$data->timeviewed = time();

$result = $DB->insert_record('digestforum_emailtrack', $data);

error_log("DB insert result is: " + $result);






