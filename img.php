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

// Tracker id.
$id = required_param('id', PARAM_INT);

// Send the 1x1 transparent png.
header('Content-type: image/png');
readfile('pix/blank.png');

global $DB;

// Get the existing entry.
$entry = $DB->get_record('digestforum_tracker', ['id' => $id]);

if ($entry) {

    // The email has been viewed. Update info.
    $now = time();
    $entry->numviews++;
    if ($entry->firstviewed == 0) {
        $entry->firstviewed = $now;
    }
    $entry->lastviewed = $now;

    $result = $DB->update_record('digestforum_tracker', $entry);
}
