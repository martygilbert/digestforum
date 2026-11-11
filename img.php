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
 * Tracking image for digest emails: records that a digest has been opened.
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The image is loaded by email clients, which are not logged in.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(dirname(__FILE__) . '/../../config.php');

// Tracker id.
$id = required_param('id', PARAM_INT);

// Send the 1x1 transparent png.
header('Content-type: image/png');
readfile(__DIR__ . '/pix/blank.png');

require_once(__DIR__ . '/lib.php');

mod_digestforum_record_digest_view($id);
