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
 * @copyright  2008 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$confirm = optional_param('confirm', false, PARAM_BOOL);

$PAGE->set_url('/mod/digestforum/unsubscribeall.php');

// Do not autologin guest. Only proper users can have digestforum subscriptions.
require_login(null, false);
$PAGE->set_context(context_user::instance($USER->id));

$return = $CFG->wwwroot.'/';

if (isguestuser()) {
    redirect($return);
}

$strunsubscribeall = get_string('unsubscribeall', 'digestforum');
$PAGE->navbar->add(get_string('modulename', 'digestforum'));
$PAGE->navbar->add($strunsubscribeall);
$PAGE->set_title($strunsubscribeall);
$PAGE->set_heading($COURSE->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($strunsubscribeall);

if (data_submitted() and $confirm and confirm_sesskey()) {
    $digestforums = \mod_digestforum\subscriptions::get_unsubscribable_digestforums();

    foreach($digestforums as $digestforum) {
        \mod_digestforum\subscriptions::unsubscribe_user($USER->id, $digestforum, context_module::instance($digestforum->cm), true);
    }
    $DB->delete_records('digestforum_discussion_subs', array('userid' => $USER->id));
    $DB->set_field('user', 'autosubscribe', 0, array('id'=>$USER->id));

    echo $OUTPUT->box(get_string('unsubscribealldone', 'digestforum'));
    echo $OUTPUT->continue_button($return);
    echo $OUTPUT->footer();
    die;

} else {
    $count = new stdClass();
    $count->digestforums = count(\mod_digestforum\subscriptions::get_unsubscribable_digestforums());
    $count->discussions = $DB->count_records('digestforum_discussion_subs', array('userid' => $USER->id));

    if ($count->digestforums || $count->discussions) {
        if ($count->digestforums && $count->discussions) {
            $msg = get_string('unsubscribeallconfirm', 'digestforum', $count);
        } else if ($count->digestforums) {
            $msg = get_string('unsubscribeallconfirmdigestforums', 'digestforum', $count);
        } else if ($count->discussions) {
            $msg = get_string('unsubscribeallconfirmdiscussions', 'digestforum', $count);
        }
        echo $OUTPUT->confirm($msg, new moodle_url('unsubscribeall.php', array('confirm'=>1)), $return);
        echo $OUTPUT->footer();
        die;

    } else {
        echo $OUTPUT->box(get_string('unsubscribeallempty', 'digestforum'));
        echo $OUTPUT->continue_button($return);
        echo $OUTPUT->footer();
        die;
    }
}
