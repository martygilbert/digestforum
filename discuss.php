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
 * Displays a post, and all the posts below it.
 * If no post is given, displays all posts in a discussion
 *
 * @package mod-digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    require_once('../../config.php');

    $d      = required_param('d', PARAM_INT);                // Discussion ID
    $parent = optional_param('parent', 0, PARAM_INT);        // If set, then display this post and all children.
    $mode   = optional_param('mode', 0, PARAM_INT);          // If set, changes the layout of the thread
    $move   = optional_param('move', 0, PARAM_INT);          // If set, moves this discussion to another forum
    $mark   = optional_param('mark', '', PARAM_ALPHA);       // Used for tracking read posts if user initiated.
    $postid = optional_param('postid', 0, PARAM_INT);        // Used for tracking read posts if user initiated.

    $url = new moodle_url('/mod/digestforum/discuss.php', array('d'=>$d));
    if ($parent !== 0) {
        $url->param('parent', $parent);
    }
    $PAGE->set_url($url);

    $discussion = $DB->get_record('digestforum_discussions', array('id' => $d), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
    $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);

    require_course_login($course, true, $cm);

    // move this down fix for MDL-6926
    require_once($CFG->dirroot.'/mod/digestforum/lib.php');

    $modcontext = context_module::instance($cm->id);
    require_capability('mod/digestforum:viewdiscussion', $modcontext, NULL, true, 'noviewdiscussionspermission', 'digestforum');

    if (!empty($CFG->enablerssfeeds) && !empty($CFG->digestforum_enablerssfeeds) && $digestforum->rsstype && $digestforum->rssarticles) {
        require_once("$CFG->libdir/rsslib.php");

        $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))) . ': ' . format_string($digestforum->name);
        rss_add_http_header($modcontext, 'mod_digestforum', $digestforum, $rsstitle);
    }

/// move discussion if requested
    if ($move > 0 and confirm_sesskey()) {
        $return = $CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$discussion->id;

        require_capability('mod/digestforum:movediscussions', $modcontext);

        if ($digestforum->type == 'single') {
            print_error('cannotmovefromsingledigestforum', 'digestforum', $return);
        }

        if (!$digestforumto = $DB->get_record('digestforum', array('id' => $move))) {
            print_error('cannotmovetonotexist', 'digestforum', $return);
        }

        if ($digestforumto->type == 'single') {
            print_error('cannotmovetosingledigestforum', 'digestforum', $return);
        }

        if (!$cmto = get_coursemodule_from_instance('digestforum', $digestforumto->id, $course->id)) {
            print_error('cannotmovetonotfound', 'digestforum', $return);
        }

        if (!coursemodule_visible_for_user($cmto)) {
            print_error('cannotmovenotvisible', 'digestforum', $return);
        }

        require_capability('mod/digestforum:startdiscussion', context_module::instance($cmto->id));

        if (!digestforum_move_attachments($discussion, $digestforum->id, $digestforumto->id)) {
            echo $OUTPUT->notification("Errors occurred while moving attachment directories - check your file permissions");
        }
        $DB->set_field('digestforum_discussions', 'digestforum', $digestforumto->id, array('id' => $discussion->id));
        $DB->set_field('digestforum_read', 'digestforumid', $digestforumto->id, array('discussionid' => $discussion->id));
        add_to_log($course->id, 'digestforum', 'move discussion', "discuss.php?d=$discussion->id", $discussion->id, $cmto->id);

        require_once($CFG->libdir.'/rsslib.php');
        require_once($CFG->dirroot.'/mod/digestforum/rsslib.php');

        // Delete the RSS files for the 2 digestforums to force regeneration of the feeds
        digestforum_rss_delete_file($digestforum);
        digestforum_rss_delete_file($digestforumto);

        redirect($return.'&moved=-1&sesskey='.sesskey());
    }

    add_to_log($course->id, 'digestforum', 'view discussion', "discuss.php?d=$discussion->id", $discussion->id, $cm->id);

    unset($SESSION->fromdiscussion);

    if ($mode) {
        set_user_preference('digestforum_displaymode', $mode);
    }

    $displaymode = get_user_preferences('digestforum_displaymode', $CFG->digestforum_displaymode);

    if ($parent) {
        // If flat AND parent, then force nested display this time
        if ($displaymode == DIGESTFORUM_MODE_FLATOLDEST or $displaymode == DIGESTFORUM_MODE_FLATNEWEST) {
            $displaymode = DIGESTFORUM_MODE_NESTED;
        }
    } else {
        $parent = $discussion->firstpost;
    }

    if (! $post = digestforum_get_post_full($parent)) {
        print_error("notexists", 'digestforum', "$CFG->wwwroot/mod/digestforum/view.php?f=$digestforum->id");
    }

    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
        print_error('noviewdiscussionspermission', 'digestforum', "$CFG->wwwroot/mod/digestforum/view.php?id=$digestforum->id");
    }

    if ($mark == 'read' or $mark == 'unread') {
        if ($CFG->digestforum_usermarksread && digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
            if ($mark == 'read') {
                digestforum_tp_add_read_record($USER->id, $postid);
            } else {
                // unread
                digestforum_tp_delete_read_records($USER->id, $postid);
            }
        }
    }

    $searchform = digestforum_search_form($course);

    $digestforumnode = $PAGE->navigation->find($cm->id, navigation_node::TYPE_ACTIVITY);
    if (empty($digestforumnode)) {
        $digestforumnode = $PAGE->navbar;
    } else {
        $digestforumnode->make_active();
    }
    $node = $digestforumnode->add(format_string($discussion->name), new moodle_url('/mod/digestforum/discuss.php', array('d'=>$discussion->id)));
    $node->display = false;
    if ($node && $post->id != $discussion->firstpost) {
        $node->add(format_string($post->subject), $PAGE->url);
    }

    $PAGE->set_title("$course->shortname: ".format_string($discussion->name));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_button($searchform);
    echo $OUTPUT->header();

/// Check to see if groups are being used in this digestforum
/// If so, make sure the current person is allowed to see this discussion
/// Also, if we know they should be able to reply, then explicitly set $canreply for performance reasons

    $canreply = digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $modcontext);
    if (!$canreply and $digestforum->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canreply = true;
        }
        if (!is_enrolled($modcontext) and !is_viewing($modcontext)) {
            // allow guests and not-logged-in to see the link - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this link too, they are asked to enrol instead
            $canreply = enrol_selfenrol_available($course->id);
        }
    }

/// Print the controls across the top
    echo '<div class="discussioncontrols clearfix">';

    if (!empty($CFG->enableportfolios) && has_capability('mod/digestforum:exportdiscussion', $modcontext)) {
        require_once($CFG->libdir.'/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('digestforum_portfolio_caller', array('discussionid' => $discussion->id), 'mod_digestforum');
        $button = $button->to_html(PORTFOLIO_ADD_FULL_FORM, get_string('exportdiscussion', 'mod_digestforum'));
        $buttonextraclass = '';
        if (empty($button)) {
            // no portfolio plugin available.
            $button = '&nbsp;';
            $buttonextraclass = ' noavailable';
        }
        echo html_writer::tag('div', $button, array('class' => 'discussioncontrol exporttoportfolio'.$buttonextraclass));
    } else {
        echo html_writer::tag('div', '&nbsp;', array('class'=>'discussioncontrol nullcontrol'));
    }

    // groups selector not needed here
    echo '<div class="discussioncontrol displaymode">';
    digestforum_print_mode_form($discussion->id, $displaymode);
    echo "</div>";

    if ($digestforum->type != 'single'
                && has_capability('mod/digestforum:movediscussions', $modcontext)) {

        echo '<div class="discussioncontrol movediscussion">';
        // Popup menu to move discussions to other digestforums. The discussion in a
        // single discussion digestforum can't be moved.
        $modinfo = get_fast_modinfo($course);
        if (isset($modinfo->instances['digestforum'])) {
            $digestforummenu = array();
            // Check digestforum types and eliminate simple discussions.
            $digestforumcheck = $DB->get_records('digestforum', array('course' => $course->id),'', 'id, type');
            foreach ($modinfo->instances['digestforum'] as $digestforumcm) {
                if (!$digestforumcm->uservisible || !has_capability('mod/digestforum:startdiscussion',
                    context_module::instance($digestforumcm->id))) {
                    continue;
                }
                $section = $digestforumcm->sectionnum;
                $sectionname = get_section_name($course, $section);
                if (empty($digestforummenu[$section])) {
                    $digestforummenu[$section] = array($sectionname => array());
                }
                $digestforumidcompare = $digestforumcm->instance != $digestforum->id;
                $digestforumtypecheck = $digestforumcheck[$digestforumcm->instance]->type !== 'single';
                if ($digestforumidcompare and $digestforumtypecheck) {
                    $url = "/mod/digestforum/discuss.php?d=$discussion->id&move=$digestforumcm->instance&sesskey=".sesskey();
                    $digestforummenu[$section][$sectionname][$url] = format_string($digestforumcm->name);
                }
            }
            if (!empty($digestforummenu)) {
                echo '<div class="movediscussionoption">';
                $select = new url_select($digestforummenu, '',
                        array(''=>get_string("movethisdiscussionto", "digestforum")),
                        'digestforummenu', get_string('move'));
                echo $OUTPUT->render($select);
                echo "</div>";
            }
        }
        echo "</div>";
    }
    echo '<div class="clearfloat">&nbsp;</div>';
    echo "</div>";

    if (!empty($digestforum->blockafter) && !empty($digestforum->blockperiod)) {
        $a = new stdClass();
        $a->blockafter  = $digestforum->blockafter;
        $a->blockperiod = get_string('secondstotime'.$digestforum->blockperiod);
        echo $OUTPUT->notification(get_string('thisdigestforumisthrottled','digestforum',$a));
    }

    if ($digestforum->type == 'qanda' && !has_capability('mod/digestforum:viewqandawithoutposting', $modcontext) &&
                !digestforum_user_has_posted($digestforum->id,$discussion->id,$USER->id)) {
        echo $OUTPUT->notification(get_string('qandanotify','digestforum'));
    }

    if ($move == -1 and confirm_sesskey()) {
        echo $OUTPUT->notification(get_string('discussionmoved', 'digestforum', format_string($digestforum->name,true)));
    }

    $canrate = has_capability('mod/digestforum:rate', $modcontext);
    digestforum_print_discussion($course, $cm, $digestforum, $discussion, $post, $displaymode, $canreply, $canrate);

    echo $OUTPUT->footer();



