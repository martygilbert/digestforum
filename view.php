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

    require_once('../../config.php');
    require_once('lib.php');
    require_once($CFG->libdir.'/completionlib.php');

    $id          = optional_param('id', 0, PARAM_INT);       // Course Module ID
    $f           = optional_param('f', 0, PARAM_INT);        // Forum ID
    $mode        = optional_param('mode', 0, PARAM_INT);     // Display mode (for single digestforum)
    $showall     = optional_param('showall', '', PARAM_INT); // show all discussions on one page
    $changegroup = optional_param('group', -1, PARAM_INT);   // choose the current group
    $page        = optional_param('page', 0, PARAM_INT);     // which page to show
    $search      = optional_param('search', '', PARAM_CLEAN);// search string

    $params = array();
    if ($id) {
        $params['id'] = $id;
    } else {
        $params['f'] = $f;
    }
    if ($page) {
        $params['page'] = $page;
    }
    if ($search) {
        $params['search'] = $search;
    }
    $PAGE->set_url('/mod/digestforum/view.php', $params);

    if ($id) {
        if (! $cm = get_coursemodule_from_id('digestforum', $id)) {
            print_error('invalidcoursemodule');
        }
        if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
            print_error('coursemisconf');
        }
        if (! $digestforum = $DB->get_record("digestforum", array("id" => $cm->instance))) {
            print_error('invaliddigestforumid', 'digestforum');
        }
        if ($digestforum->type == 'single') {
            $PAGE->set_pagetype('mod-digestforum-discuss');
        }
        // move require_course_login here to use forced language for course
        // fix for MDL-6926
        require_course_login($course, true, $cm);
        $strdigestforums = get_string("modulenameplural", "digestforum");
        $strdigestforum = get_string("modulename", "digestforum");
    } else if ($f) {

        if (! $digestforum = $DB->get_record("digestforum", array("id" => $f))) {
            print_error('invaliddigestforumid', 'digestforum');
        }
        if (! $course = $DB->get_record("course", array("id" => $digestforum->course))) {
            print_error('coursemisconf');
        }

        if (!$cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $course->id)) {
            print_error('missingparameter');
        }
        // move require_course_login here to use forced language for course
        // fix for MDL-6926
        require_course_login($course, true, $cm);
        $strdigestforums = get_string("modulenameplural", "digestforum");
        $strdigestforum = get_string("modulename", "digestforum");
    } else {
        print_error('missingparameter');
    }

    if (!$PAGE->button) {
        $PAGE->set_button(digestforum_search_form($course, $search));
    }

    $context = context_module::instance($cm->id);
    $PAGE->set_context($context);

    if (!empty($CFG->enablerssfeeds) && !empty($CFG->digestforum_enablerssfeeds) && $digestforum->rsstype && $digestforum->rssarticles) {
        require_once("$CFG->libdir/rsslib.php");

        $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))) . ': ' . format_string($digestforum->name);
        rss_add_http_header($context, 'mod_digestforum', $digestforum, $rsstitle);
    }

/// Print header.

    $PAGE->set_title($digestforum->name);
    $PAGE->add_body_class('digestforumtype-'.$digestforum->type);
    $PAGE->set_heading($course->fullname);

/// Some capability checks.
    if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
        notice(get_string("activityiscurrentlyhidden"));
    }

    if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
        notice(get_string('noviewdiscussionspermission', 'digestforum'));
    }

    // Mark viewed and trigger the course_module_viewed event.
    digestforum_view($digestforum, $course, $cm, $context);

    echo $OUTPUT->header();

    echo $OUTPUT->heading(format_string($digestforum->name), 2);
    if (!empty($digestforum->intro) && $digestforum->type != 'single' && $digestforum->type != 'teacher') {
        echo $OUTPUT->box(format_module_intro('digestforum', $digestforum, $cm->id), 'generalbox', 'intro');
    }

/// find out current groups mode
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/digestforum/view.php?id=' . $cm->id);

    $SESSION->fromdiscussion = qualified_me();   // Return here if we post or set subscription etc


/// Print settings and things across the top

    // If it's a simple single discussion digestforum, we need to print the display
    // mode control.
    if ($digestforum->type == 'single') {
        $discussion = NULL;
        $discussions = $DB->get_records('digestforum_discussions', array('digestforum'=>$digestforum->id), 'timemodified ASC');
        if (!empty($discussions)) {
            $discussion = array_pop($discussions);
        }
        if ($discussion) {
            if ($mode) {
                set_user_preference("digestforum_displaymode", $mode);
            }
            $displaymode = get_user_preferences("digestforum_displaymode", $CFG->digestforum_displaymode);
            digestforum_print_mode_form($digestforum->id, $displaymode, $digestforum->type);
        }
    }

    if (!empty($digestforum->blockafter) && !empty($digestforum->blockperiod)) {
        $a = new stdClass();
        $a->blockafter = $digestforum->blockafter;
        $a->blockperiod = get_string('secondstotime'.$digestforum->blockperiod);
        echo $OUTPUT->notification(get_string('thisdigestforumisthrottled', 'digestforum', $a));
    }

    if ($digestforum->type == 'qanda' && !has_capability('moodle/course:manageactivities', $context)) {
        echo $OUTPUT->notification(get_string('qandanotify','digestforum'));
    }

    switch ($digestforum->type) {
        case 'single':
            if (!empty($discussions) && count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'digestforum'));
            }
            if (! $post = digestforum_get_post_full($discussion->firstpost)) {
                print_error('cannotfindfirstpost', 'digestforum');
            }
            if ($mode) {
                set_user_preference("digestforum_displaymode", $mode);
            }

            $canreply    = digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $context);
            $canrate     = has_capability('mod/digestforum:rate', $context);
            $displaymode = get_user_preferences("digestforum_displaymode", $CFG->digestforum_displaymode);

            echo '&nbsp;'; // this should fix the floating in FF
            digestforum_print_discussion($course, $cm, $digestforum, $discussion, $post, $displaymode, $canreply, $canrate);
            break;

        case 'eachuser':
            echo '<p class="mdl-align">';
            if (digestforum_user_can_post_discussion($digestforum, null, -1, $cm)) {
                print_string("allowsdiscussions", "digestforum");
            } else {
                echo '&nbsp;';
            }
            echo '</p>';
            if (!empty($showall)) {
                digestforum_print_latest_discussions($course, $digestforum, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                digestforum_print_latest_discussions($course, $digestforum, -1, 'header', '', -1, -1, $page, $CFG->digestforum_manydiscussions, $cm);
            }
            break;

        case 'teacher':
            if (!empty($showall)) {
                digestforum_print_latest_discussions($course, $digestforum, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                digestforum_print_latest_discussions($course, $digestforum, -1, 'header', '', -1, -1, $page, $CFG->digestforum_manydiscussions, $cm);
            }
            break;

        case 'blog':
            echo '<br />';
            if (!empty($showall)) {
                digestforum_print_latest_discussions($course, $digestforum, 0, 'plain', 'd.pinned DESC, p.created DESC', -1, -1, -1, 0, $cm);
            } else {
                digestforum_print_latest_discussions($course, $digestforum, -1, 'plain', 'd.pinned DESC, p.created DESC', -1, -1, $page,
                    $CFG->digestforum_manydiscussions, $cm);
            }
            break;

        default:
            echo '<br />';
            if (!empty($showall)) {
                digestforum_print_latest_discussions($course, $digestforum, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                digestforum_print_latest_discussions($course, $digestforum, -1, 'header', '', -1, -1, $page, $CFG->digestforum_manydiscussions, $cm);
            }


            break;
    }

    // Add the subscription toggle JS.
    $PAGE->requires->yui_module('moodle-mod_digestforum-subscriptiontoggle', 'Y.M.mod_digestforum.subscriptiontoggle.init');

    echo $OUTPUT->footer($course);
