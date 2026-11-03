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
 * Set tracking option for the digestforum.
 *
 * @package   mod_digestforum
 * @copyright 2005 mchurch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$f          = required_param('f',PARAM_INT); // The digestforum to mark
$mark       = required_param('mark',PARAM_ALPHA); // Read or unread?
$d          = optional_param('d',0,PARAM_INT); // Discussion to mark.
$return     = optional_param('return', null, PARAM_LOCALURL);    // Page to return to.

$url = new moodle_url('/mod/digestforum/markposts.php', array('f'=>$f, 'mark'=>$mark));
if ($d !== 0) {
    $url->param('d', $d);
}
if (null !== $return) {
    $url->param('return', $return);
}
$PAGE->set_url($url);

if (! $digestforum = $DB->get_record("digestforum", array("id" => $f))) {
    throw new \moodle_exception('invaliddigestforumid', 'digestforum');
}

if (! $course = $DB->get_record("course", array("id" => $digestforum->course))) {
    throw new \moodle_exception('invalidcourseid');
}

if (!$cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $course->id)) {
    throw new \moodle_exception('invalidcoursemodule');
}

$user = $USER;

require_login($course, false, $cm);
require_sesskey();

if (null === $return) {
    $returnto = new moodle_url("/mod/digestforum/index.php", ['id' => $course->id]);
} else {
    $returnto = new moodle_url($return);
}

if (isguestuser()) {   // Guests can't change digestforum
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguesttracking', 'digestforum').'<br /><br />'.get_string('liketologin'), get_login_url(), $returnto);
    echo $OUTPUT->footer();
    exit;
}

$info = new stdClass();
$info->name  = fullname($user);
$info->digestforum = format_string($digestforum->name);

if ($mark == 'read') {
    if (!empty($d)) {
        if (! $discussion = $DB->get_record('digestforum_discussions', array('id'=> $d, 'digestforum'=> $digestforum->id))) {
            throw new \moodle_exception('invaliddiscussionid', 'digestforum');
        }

        digestforum_tp_mark_discussion_read($user, $d);
    } else {
        // Mark all messages read in current group
        $currentgroup = groups_get_activity_group($cm);
        if(!$currentgroup) {
            // mark_digestforum_read requires ===false, while get_activity_group
            // may return 0
            $currentgroup=false;
        }
        digestforum_tp_mark_digestforum_read($user, $digestforum->id, $currentgroup);
    }
}

redirect($returnto);

