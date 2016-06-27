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
 * Edit and save a new post to a discussion
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib.php');
require_once($CFG->libdir.'/completionlib.php');

$reply   = optional_param('reply', 0, PARAM_INT);
$digestforum   = optional_param('digestforum', 0, PARAM_INT);
$edit    = optional_param('edit', 0, PARAM_INT);
$delete  = optional_param('delete', 0, PARAM_INT);
$prune   = optional_param('prune', 0, PARAM_INT);
$name    = optional_param('name', '', PARAM_CLEAN);
$confirm = optional_param('confirm', 0, PARAM_INT);
$groupid = optional_param('groupid', null, PARAM_INT);

$PAGE->set_url('/mod/digestforum/post.php', array(
        'reply' => $reply,
        'digestforum' => $digestforum,
        'edit'  => $edit,
        'delete'=> $delete,
        'prune' => $prune,
        'name'  => $name,
        'confirm'=>$confirm,
        'groupid'=>$groupid,
        ));
//these page_params will be passed as hidden variables later in the form.
$page_params = array('reply'=>$reply, 'digestforum'=>$digestforum, 'edit'=>$edit);

$sitecontext = context_system::instance();

if (!isloggedin() or isguestuser()) {

    if (!isloggedin() and !get_local_referer()) {
        // No referer+not logged in - probably coming in via email  See MDL-9052
        require_login();
    }

    if (!empty($digestforum)) {      // User is starting a new discussion in a digestforum
        if (! $digestforum = $DB->get_record('digestforum', array('id' => $digestforum))) {
            print_error('invaliddigestforumid', 'digestforum');
        }
    } else if (!empty($reply)) {      // User is writing a new reply
        if (! $parent = digestforum_get_post_full($reply)) {
            print_error('invalidparentpostid', 'digestforum');
        }
        if (! $discussion = $DB->get_record('digestforum_discussions', array('id' => $parent->discussion))) {
            print_error('notpartofdiscussion', 'digestforum');
        }
        if (! $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum))) {
            print_error('invaliddigestforumid');
        }
    }
    if (! $course = $DB->get_record('course', array('id' => $digestforum->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $digestforum);
    $PAGE->set_context($modcontext);
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    $referer = get_local_referer(false);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguestpost', 'digestforum').'<br /><br />'.get_string('liketologin'), get_login_url(), $referer);
    echo $OUTPUT->footer();
    exit;
}

require_login(0, false);   // Script is useless unless they're logged in

if (!empty($digestforum)) {      // User is starting a new discussion in a digestforum
    if (! $digestforum = $DB->get_record("digestforum", array("id" => $digestforum))) {
        print_error('invaliddigestforumid', 'digestforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $digestforum->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $course->id)) {
        print_error("invalidcoursemodule");
    }

    // Retrieve the contexts.
    $modcontext    = context_module::instance($cm->id);
    $coursecontext = context_course::instance($course->id);

    if (! digestforum_user_can_post_discussion($digestforum, $groupid, -1, $cm)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {
                if (enrol_selfenrol_available($course->id)) {
                    $SESSION->wantsurl = qualified_me();
                    $SESSION->enrolcancel = get_local_referer(false);
                    redirect(new moodle_url('/enrol/index.php', array('id' => $course->id,
                        'returnurl' => '/mod/digestforum/view.php?f=' . $digestforum->id)),
                        get_string('youneedtoenrol'));
                }
            }
        }
        print_error('nopostdigestforum', 'digestforum');
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $modcontext)) {
        print_error("activityiscurrentlyhidden");
    }

    $SESSION->fromurl = get_local_referer(false);

    // Load up the $post variable.

    $post = new stdClass();
    $post->course        = $course->id;
    $post->digestforum         = $digestforum->id;
    $post->discussion    = 0;           // ie discussion # not defined yet
    $post->parent        = 0;
    $post->subject       = '';
    $post->userid        = $USER->id;
    $post->message       = '';
    $post->messageformat = editors_get_preferred_format();
    $post->messagetrust  = 0;

    if (isset($groupid)) {
        $post->groupid = $groupid;
    } else {
        $post->groupid = groups_get_activity_group($cm);
    }

    // Unsetting this will allow the correct return URL to be calculated later.
    unset($SESSION->fromdiscussion);

} else if (!empty($reply)) {      // User is writing a new reply

    if (! $parent = digestforum_get_post_full($reply)) {
        print_error('invalidparentpostid', 'digestforum');
    }
    if (! $discussion = $DB->get_record("digestforum_discussions", array("id" => $parent->discussion))) {
        print_error('notpartofdiscussion', 'digestforum');
    }
    if (! $digestforum = $DB->get_record("digestforum", array("id" => $discussion->digestforum))) {
        print_error('invaliddigestforumid', 'digestforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    // Ensure lang, theme, etc. is set up properly. MDL-6926
    $PAGE->set_cm($cm, $course, $digestforum);

    // Retrieve the contexts.
    $modcontext    = context_module::instance($cm->id);
    $coursecontext = context_course::instance($course->id);

    if (! digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $modcontext)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {  // User is a guest here!
                $SESSION->wantsurl = qualified_me();
                $SESSION->enrolcancel = get_local_referer(false);
                redirect(new moodle_url('/enrol/index.php', array('id' => $course->id,
                    'returnurl' => '/mod/digestforum/view.php?f=' . $digestforum->id)),
                    get_string('youneedtoenrol'));
            }
        }
        print_error('nopostdigestforum', 'digestforum');
    }

    // Make sure user can post here
    if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
        $groupmode =  $cm->groupmode;
    } else {
        $groupmode = $course->groupmode;
    }
    if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $modcontext)) {
        if ($discussion->groupid == -1) {
            print_error('nopostdigestforum', 'digestforum');
        } else {
            if (!groups_is_member($discussion->groupid)) {
                print_error('nopostdigestforum', 'digestforum');
            }
        }
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $modcontext)) {
        print_error("activityiscurrentlyhidden");
    }

    // Load up the $post variable.

    $post = new stdClass();
    $post->course      = $course->id;
    $post->digestforum       = $digestforum->id;
    $post->discussion  = $parent->discussion;
    $post->parent      = $parent->id;
    $post->subject     = $parent->subject;
    $post->userid      = $USER->id;
    $post->message     = '';

    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $strre = get_string('re', 'digestforum');
    if (!(substr($post->subject, 0, strlen($strre)) == $strre)) {
        $post->subject = $strre.' '.$post->subject;
    }

    // Unsetting this will allow the correct return URL to be calculated later.
    unset($SESSION->fromdiscussion);

} else if (!empty($edit)) {  // User is editing their own post

    if (! $post = digestforum_get_post_full($edit)) {
        print_error('invalidpostid', 'digestforum');
    }
    if ($post->parent) {
        if (! $parent = digestforum_get_post_full($post->parent)) {
            print_error('invalidparentpostid', 'digestforum');
        }
    }

    if (! $discussion = $DB->get_record("digestforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'digestforum');
    }
    if (! $digestforum = $DB->get_record("digestforum", array("id" => $discussion->digestforum))) {
        print_error('invaliddigestforumid', 'digestforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $course->id)) {
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $digestforum);

    if (!($digestforum->type == 'news' && !$post->parent && $discussion->timestart > time())) {
        if (((time() - $post->created) > $CFG->maxeditingtime) and
                    !has_capability('mod/digestforum:editanypost', $modcontext)) {
            print_error('maxtimehaspassed', 'digestforum', '', format_time($CFG->maxeditingtime));
        }
    }
    if (($post->userid <> $USER->id) and
                !has_capability('mod/digestforum:editanypost', $modcontext)) {
        print_error('cannoteditposts', 'digestforum');
    }


    // Load up the $post variable.
    $post->edit   = $edit;
    $post->course = $course->id;
    $post->digestforum  = $digestforum->id;
    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $post = trusttext_pre_edit($post, 'message', $modcontext);

    // Unsetting this will allow the correct return URL to be calculated later.
    unset($SESSION->fromdiscussion);

}else if (!empty($delete)) {  // User is deleting a post

    if (! $post = digestforum_get_post_full($delete)) {
        print_error('invalidpostid', 'digestforum');
    }
    if (! $discussion = $DB->get_record("digestforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'digestforum');
    }
    if (! $digestforum = $DB->get_record("digestforum", array("id" => $discussion->digestforum))) {
        print_error('invaliddigestforumid', 'digestforum');
    }
    if (!$cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $digestforum->course)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $digestforum->course))) {
        print_error('invalidcourseid');
    }

    require_login($course, false, $cm);
    $modcontext = context_module::instance($cm->id);

    if ( !(($post->userid == $USER->id && has_capability('mod/digestforum:deleteownpost', $modcontext))
                || has_capability('mod/digestforum:deleteanypost', $modcontext)) ) {
        print_error('cannotdeletepost', 'digestforum');
    }


    $replycount = digestforum_count_replies($post);

    if (!empty($confirm) && confirm_sesskey()) {    // User has confirmed the delete
        //check user capability to delete post.
        $timepassed = time() - $post->created;
        if (($timepassed > $CFG->maxeditingtime) && !has_capability('mod/digestforum:deleteanypost', $modcontext)) {
            print_error("cannotdeletepost", "digestforum",
                        digestforum_go_back_to(new moodle_url("/mod/digestforum/discuss.php", array('d' => $post->discussion))));
        }

        if ($post->totalscore) {
            notice(get_string('couldnotdeleteratings', 'rating'),
                   digestforum_go_back_to(new moodle_url("/mod/digestforum/discuss.php", array('d' => $post->discussion))));

        } else if ($replycount && !has_capability('mod/digestforum:deleteanypost', $modcontext)) {
            print_error("couldnotdeletereplies", "digestforum",
                        digestforum_go_back_to(new moodle_url("/mod/digestforum/discuss.php", array('d' => $post->discussion))));

        } else {
            if (! $post->parent) {  // post is a discussion topic as well, so delete discussion
                if ($digestforum->type == 'single') {
                    notice("Sorry, but you are not allowed to delete that discussion!",
                           digestforum_go_back_to(new moodle_url("/mod/digestforum/discuss.php", array('d' => $post->discussion))));
                }
                digestforum_delete_discussion($discussion, false, $course, $cm, $digestforum);

                $params = array(
                    'objectid' => $discussion->id,
                    'context' => $modcontext,
                    'other' => array(
                        'digestforumid' => $digestforum->id,
                    )
                );

                $event = \mod_digestforum\event\discussion_deleted::create($params);
                $event->add_record_snapshot('digestforum_discussions', $discussion);
                $event->trigger();

                redirect("view.php?f=$discussion->digestforum");

            } else if (digestforum_delete_post($post, has_capability('mod/digestforum:deleteanypost', $modcontext),
                $course, $cm, $digestforum)) {

                if ($digestforum->type == 'single') {
                    // Single discussion digestforums are an exception. We show
                    // the digestforum itself since it only has one discussion
                    // thread.
                    $discussionurl = new moodle_url("/mod/digestforum/view.php", array('f' => $digestforum->id));
                } else {
                    $discussionurl = new moodle_url("/mod/digestforum/discuss.php", array('d' => $discussion->id));
                }

                redirect(digestforum_go_back_to($discussionurl));
            } else {
                print_error('errorwhiledelete', 'digestforum');
            }
        }


    } else { // User just asked to delete something

        digestforum_set_return();
        $PAGE->navbar->add(get_string('delete', 'digestforum'));
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);

        if ($replycount) {
            if (!has_capability('mod/digestforum:deleteanypost', $modcontext)) {
                print_error("couldnotdeletereplies", "digestforum",
                      digestforum_go_back_to(new moodle_url('/mod/digestforum/discuss.php', array('d' => $post->discussion), 'p'.$post->id)));
            }
            echo $OUTPUT->header();
            echo $OUTPUT->heading(format_string($digestforum->name), 2);
            echo $OUTPUT->confirm(get_string("deletesureplural", "digestforum", $replycount+1),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$post->discussion.'#p'.$post->id);

            digestforum_print_post($post, $discussion, $digestforum, $cm, $course, false, false, false);

            if (empty($post->edit)) {
                $digestforumtracked = digestforum_tp_is_tracked($digestforum);
                $posts = digestforum_get_all_discussion_posts($discussion->id, "created ASC", $digestforumtracked);
                digestforum_print_posts_nested($course, $cm, $digestforum, $discussion, $post, false, false, $digestforumtracked, $posts);
            }
        } else {
            echo $OUTPUT->header();
            echo $OUTPUT->heading(format_string($digestforum->name), 2);
            echo $OUTPUT->confirm(get_string("deletesure", "digestforum", $replycount),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$post->discussion.'#p'.$post->id);
            digestforum_print_post($post, $discussion, $digestforum, $cm, $course, false, false, false);
        }

    }
    echo $OUTPUT->footer();
    die;


} else if (!empty($prune)) {  // Pruning

    if (!$post = digestforum_get_post_full($prune)) {
        print_error('invalidpostid', 'digestforum');
    }
    if (!$discussion = $DB->get_record("digestforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'digestforum');
    }
    if (!$digestforum = $DB->get_record("digestforum", array("id" => $discussion->digestforum))) {
        print_error('invaliddigestforumid', 'digestforum');
    }
    if ($digestforum->type == 'single') {
        print_error('cannotsplit', 'digestforum');
    }
    if (!$post->parent) {
        print_error('alreadyfirstpost', 'digestforum');
    }
    if (!$cm = get_coursemodule_from_instance("digestforum", $digestforum->id, $digestforum->course)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }
    if (!has_capability('mod/digestforum:splitdiscussions', $modcontext)) {
        print_error('cannotsplit', 'digestforum');
    }

    $PAGE->set_cm($cm);
    $PAGE->set_context($modcontext);

    $prunemform = new mod_digestforum_prune_form(null, array('prune' => $prune, 'confirm' => $prune));


    if ($prunemform->is_cancelled()) {
        redirect(digestforum_go_back_to(new moodle_url("/mod/digestforum/discuss.php", array('d' => $post->discussion))));
    } else if ($fromform = $prunemform->get_data()) {
        // User submits the data.
        $newdiscussion = new stdClass();
        $newdiscussion->course       = $discussion->course;
        $newdiscussion->digestforum        = $discussion->digestforum;
        $newdiscussion->name         = $name;
        $newdiscussion->firstpost    = $post->id;
        $newdiscussion->userid       = $discussion->userid;
        $newdiscussion->groupid      = $discussion->groupid;
        $newdiscussion->assessed     = $discussion->assessed;
        $newdiscussion->usermodified = $post->userid;
        $newdiscussion->timestart    = $discussion->timestart;
        $newdiscussion->timeend      = $discussion->timeend;

        $newid = $DB->insert_record('digestforum_discussions', $newdiscussion);

        $newpost = new stdClass();
        $newpost->id      = $post->id;
        $newpost->parent  = 0;
        $newpost->subject = $name;

        $DB->update_record("digestforum_posts", $newpost);

        digestforum_change_discussionid($post->id, $newid);

        // Update last post in each discussion.
        digestforum_discussion_update_last_post($discussion->id);
        digestforum_discussion_update_last_post($newid);

        // Fire events to reflect the split..
        $params = array(
            'context' => $modcontext,
            'objectid' => $discussion->id,
            'other' => array(
                'digestforumid' => $digestforum->id,
            )
        );
        $event = \mod_digestforum\event\discussion_updated::create($params);
        $event->trigger();

        $params = array(
            'context' => $modcontext,
            'objectid' => $newid,
            'other' => array(
                'digestforumid' => $digestforum->id,
            )
        );
        $event = \mod_digestforum\event\discussion_created::create($params);
        $event->trigger();

        $params = array(
            'context' => $modcontext,
            'objectid' => $post->id,
            'other' => array(
                'discussionid' => $newid,
                'digestforumid' => $digestforum->id,
                'digestforumtype' => $digestforum->type,
            )
        );
        $event = \mod_digestforum\event\post_updated::create($params);
        $event->add_record_snapshot('digestforum_discussions', $discussion);
        $event->trigger();

        redirect(digestforum_go_back_to(new moodle_url("/mod/digestforum/discuss.php", array('d' => $newid))));

    } else {
        // Display the prune form.
        $course = $DB->get_record('course', array('id' => $digestforum->course));
        $PAGE->navbar->add(format_string($post->subject, true), new moodle_url('/mod/digestforum/discuss.php', array('d'=>$discussion->id)));
        $PAGE->navbar->add(get_string("prune", "digestforum"));
        $PAGE->set_title(format_string($discussion->name).": ".format_string($post->subject));
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(format_string($digestforum->name), 2);
        echo $OUTPUT->heading(get_string('pruneheading', 'digestforum'), 3);

        $prunemform->display();

        digestforum_print_post($post, $discussion, $digestforum, $cm, $course, false, false, false);
    }

    echo $OUTPUT->footer();
    die;
} else {
    print_error('unknowaction');

}

if (!isset($coursecontext)) {
    // Has not yet been set by post.php.
    $coursecontext = context_course::instance($digestforum->course);
}


// from now on user must be logged on properly

if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id)) { // For the logs
    print_error('invalidcoursemodule');
}
$modcontext = context_module::instance($cm->id);
require_login($course, false, $cm);

if (isguestuser()) {
    // just in case
    print_error('noguest');
}

if (!isset($digestforum->maxattachments)) {  // TODO - delete this once we add a field to the digestforum table
    $digestforum->maxattachments = 3;
}

$thresholdwarning = digestforum_check_throttling($digestforum, $cm);
$mform_post = new mod_digestforum_post_form('post.php', array('course' => $course,
                                                        'cm' => $cm,
                                                        'coursecontext' => $coursecontext,
                                                        'modcontext' => $modcontext,
                                                        'digestforum' => $digestforum,
                                                        'post' => $post,
                                                        'subscribe' => \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum,
                                                                null, $cm),
                                                        'thresholdwarning' => $thresholdwarning,
                                                        'edit' => $edit), 'post', '', array('id' => 'mformdigestforum'));

$draftitemid = file_get_submitted_draft_itemid('attachments');
file_prepare_draft_area($draftitemid, $modcontext->id, 'mod_digestforum', 'attachment', empty($post->id)?null:$post->id, mod_digestforum_post_form::attachment_options($digestforum));

//load data into form NOW!

if ($USER->id != $post->userid) {   // Not the original author, so add a message to the end
    $data = new stdClass();
    $data->date = userdate($post->modified);
    if ($post->messageformat == FORMAT_HTML) {
        $data->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$USER->id.'&course='.$post->course.'">'.
                       fullname($USER).'</a>';
        $post->message .= '<p><span class="edited">('.get_string('editedby', 'digestforum', $data).')</span></p>';
    } else {
        $data->name = fullname($USER);
        $post->message .= "\n\n(".get_string('editedby', 'digestforum', $data).')';
    }
    unset($data);
}

$formheading = '';
if (!empty($parent)) {
    $heading = get_string("yourreply", "digestforum");
    $formheading = get_string('reply', 'digestforum');
} else {
    if ($digestforum->type == 'qanda') {
        $heading = get_string('yournewquestion', 'digestforum');
    } else {
        $heading = get_string('yournewtopic', 'digestforum');
    }
}

$postid = empty($post->id) ? null : $post->id;
$draftid_editor = file_get_submitted_draft_itemid('message');
$currenttext = file_prepare_draft_area($draftid_editor, $modcontext->id, 'mod_digestforum', 'post', $postid, mod_digestforum_post_form::editor_options($modcontext, $postid), $post->message);

$manageactivities = has_capability('moodle/course:manageactivities', $coursecontext);
if (\mod_digestforum\subscriptions::subscription_disabled($digestforum) && !$manageactivities) {
    // User does not have permission to subscribe to this discussion at all.
    $discussionsubscribe = false;
} else if (\mod_digestforum\subscriptions::is_forcesubscribed($digestforum)) {
    // User does not have permission to unsubscribe from this discussion at all.
    $discussionsubscribe = true;
} else {
    if (isset($discussion) && \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum, $discussion->id, $cm)) {
        // User is subscribed to the discussion - continue the subscription.
        $discussionsubscribe = true;
    } else if (!isset($discussion) && \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum, null, $cm)) {
        // Starting a new discussion, and the user is subscribed to the digestforum - subscribe to the discussion.
        $discussionsubscribe = true;
    } else {
        // User is not subscribed to either digestforum or discussion. Follow user preference.
        $discussionsubscribe = $USER->autosubscribe;
    }
}

$mform_post->set_data(array(        'attachments'=>$draftitemid,
                                    'general'=>$heading,
                                    'subject'=>$post->subject,
                                    'message'=>array(
                                        'text'=>$currenttext,
                                        'format'=>empty($post->messageformat) ? editors_get_preferred_format() : $post->messageformat,
                                        'itemid'=>$draftid_editor
                                    ),
                                    'discussionsubscribe' => $discussionsubscribe,
                                    'mailnow'=>!empty($post->mailnow),
                                    'userid'=>$post->userid,
                                    'parent'=>$post->parent,
                                    'discussion'=>$post->discussion,
                                    'course'=>$course->id) +
                                    $page_params +

                            (isset($post->format)?array(
                                    'format'=>$post->format):
                                array())+

                            (isset($discussion->timestart)?array(
                                    'timestart'=>$discussion->timestart):
                                array())+

                            (isset($discussion->timeend)?array(
                                    'timeend'=>$discussion->timeend):
                                array())+

                            (isset($discussion->pinned) ? array(
                                     'pinned' => $discussion->pinned) :
                                array()) +

                            (isset($post->groupid)?array(
                                    'groupid'=>$post->groupid):
                                array())+

                            (isset($discussion->id)?
                                    array('discussion'=>$discussion->id):
                                    array()));

if ($mform_post->is_cancelled()) {
    if (!isset($discussion->id) || $digestforum->type === 'qanda') {
        // Q and A digestforums don't have a discussion page, so treat them like a new thread..
        redirect(new moodle_url('/mod/digestforum/view.php', array('f' => $digestforum->id)));
    } else {
        redirect(new moodle_url('/mod/digestforum/discuss.php', array('d' => $discussion->id)));
    }
} else if ($fromform = $mform_post->get_data()) {

    if (empty($SESSION->fromurl)) {
        $errordestination = "$CFG->wwwroot/mod/digestforum/view.php?f=$digestforum->id";
    } else {
        $errordestination = $SESSION->fromurl;
    }

    $fromform->itemid        = $fromform->message['itemid'];
    $fromform->messageformat = $fromform->message['format'];
    $fromform->message       = $fromform->message['text'];
    // WARNING: the $fromform->message array has been overwritten, do not use it anymore!
    $fromform->messagetrust  = trusttext_trusted($modcontext);

    if ($fromform->edit) {           // Updating a post
        unset($fromform->groupid);
        $fromform->id = $fromform->edit;
        $message = '';

        //fix for bug #4314
        if (!$realpost = $DB->get_record('digestforum_posts', array('id' => $fromform->id))) {
            $realpost = new stdClass();
            $realpost->userid = -1;
        }


        // if user has edit any post capability
        // or has either startnewdiscussion or reply capability and is editting own post
        // then he can proceed
        // MDL-7066
        if ( !(($realpost->userid == $USER->id && (has_capability('mod/digestforum:replypost', $modcontext)
                            || has_capability('mod/digestforum:startdiscussion', $modcontext))) ||
                            has_capability('mod/digestforum:editanypost', $modcontext)) ) {
            print_error('cannotupdatepost', 'digestforum');
        }

        // If the user has access to all groups and they are changing the group, then update the post.
        if (isset($fromform->groupinfo) && has_capability('mod/digestforum:movediscussions', $modcontext)) {
            if (empty($fromform->groupinfo)) {
                $fromform->groupinfo = -1;
            }

            if (!digestforum_user_can_post_discussion($digestforum, $fromform->groupinfo, null, $cm, $modcontext)) {
                print_error('cannotupdatepost', 'digestforum');
            }

            $DB->set_field('digestforum_discussions' ,'groupid' , $fromform->groupinfo, array('firstpost' => $fromform->id));
        }
        // When editing first post/discussion.
        if (!$fromform->parent) {
            if (has_capability('mod/digestforum:pindiscussions', $modcontext)) {
                // Can change pinned if we have capability.
                $fromform->pinned = !empty($fromform->pinned) ? DFORUM_DISCUSSION_PINNED : DFORUM_DISCUSSION_UNPINNED;
            } else {
                // We don't have the capability to change so keep to previous value.
                unset($fromform->pinned);
            }
        }
        $updatepost = $fromform; //realpost
        $updatepost->digestforum = $digestforum->id;
        if (!digestforum_update_post($updatepost, $mform_post, $message)) {
            print_error("couldnotupdate", "digestforum", $errordestination);
        }

        // MDL-11818
        if (($digestforum->type == 'single') && ($updatepost->parent == '0')){ // updating first post of single discussion type -> updating digestforum intro
            $digestforum->intro = $updatepost->message;
            $digestforum->timemodified = time();
            $DB->update_record("digestforum", $digestforum);
        }

        if ($realpost->userid == $USER->id) {
            $message .= '<br />'.get_string("postupdated", "digestforum");
        } else {
            $realuser = $DB->get_record('user', array('id' => $realpost->userid));
            $message .= '<br />'.get_string("editedpostupdated", "digestforum", fullname($realuser));
        }

        $subscribemessage = digestforum_post_subscription($fromform, $digestforum, $discussion);
        if ($digestforum->type == 'single') {
            // Single discussion digestforums are an exception. We show
            // the digestforum itself since it only has one discussion
            // thread.
            $discussionurl = new moodle_url("/mod/digestforum/view.php", array('f' => $digestforum->id));
        } else {
            $discussionurl = new moodle_url("/mod/digestforum/discuss.php", array('d' => $discussion->id), 'p' . $fromform->id);
        }

        $params = array(
            'context' => $modcontext,
            'objectid' => $fromform->id,
            'other' => array(
                'discussionid' => $discussion->id,
                'digestforumid' => $digestforum->id,
                'digestforumtype' => $digestforum->type,
            )
        );

        if ($realpost->userid !== $USER->id) {
            $params['relateduserid'] = $realpost->userid;
        }

        $event = \mod_digestforum\event\post_updated::create($params);
        $event->add_record_snapshot('digestforum_discussions', $discussion);
        $event->trigger();

        redirect(
                digestforum_go_back_to($discussionurl),
                $message . $subscribemessage,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );

    } else if ($fromform->discussion) { // Adding a new post to an existing discussion
        // Before we add this we must check that the user will not exceed the blocking threshold.
        digestforum_check_blocking_threshold($thresholdwarning);

        unset($fromform->groupid);
        $message = '';
        $addpost = $fromform;
        $addpost->digestforum=$digestforum->id;
        if ($fromform->id = digestforum_add_new_post($addpost, $mform_post, $message)) {
            $subscribemessage = digestforum_post_subscription($fromform, $digestforum, $discussion);

            if (!empty($fromform->mailnow)) {
                $message .= get_string("postmailnow", "digestforum");
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "digestforum") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "digestforum", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($digestforum->type == 'single') {
                // Single discussion digestforums are an exception. We show
                // the digestforum itself since it only has one discussion
                // thread.
                $discussionurl = new moodle_url("/mod/digestforum/view.php", array('f' => $digestforum->id), 'p'.$fromform->id);
            } else {
                $discussionurl = new moodle_url("/mod/digestforum/discuss.php", array('d' => $discussion->id), 'p'.$fromform->id);
            }

            $params = array(
                'context' => $modcontext,
                'objectid' => $fromform->id,
                'other' => array(
                    'discussionid' => $discussion->id,
                    'digestforumid' => $digestforum->id,
                    'digestforumtype' => $digestforum->type,
                )
            );
            $event = \mod_digestforum\event\post_created::create($params);
            $event->add_record_snapshot('digestforum_posts', $fromform);
            $event->add_record_snapshot('digestforum_discussions', $discussion);
            $event->trigger();

            // Update completion state
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($digestforum->completionreplies || $digestforum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(
                    digestforum_go_back_to($discussionurl),
                    $message . $subscribemessage,
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );

        } else {
            print_error("couldnotadd", "digestforum", $errordestination);
        }
        exit;

    } else { // Adding a new discussion.
        // The location to redirect to after successfully posting.
        $redirectto = new moodle_url('view.php', array('f' => $fromform->digestforum));

        $fromform->mailnow = empty($fromform->mailnow) ? 0 : 1;

        $discussion = $fromform;
        $discussion->name = $fromform->subject;

        $newstopic = false;
        if ($digestforum->type == 'news' && !$fromform->parent) {
            $newstopic = true;
        }
        $discussion->timestart = $fromform->timestart;
        $discussion->timeend = $fromform->timeend;

        if (has_capability('mod/digestforum:pindiscussions', $modcontext) && !empty($fromform->pinned)) {
            $discussion->pinned = DFORUM_DISCUSSION_PINNED;
        } else {
            $discussion->pinned = DFORUM_DISCUSSION_UNPINNED;
        }

        $allowedgroups = array();
        $groupstopostto = array();

        // If we are posting a copy to all groups the user has access to.
        if (isset($fromform->posttomygroups)) {
            // Post to each of my groups.
            require_capability('mod/digestforum:canposttomygroups', $modcontext);

            // Fetch all of this user's groups.
            // Note: all groups are returned when in visible groups mode so we must manually filter.
            $allowedgroups = groups_get_activity_allowed_groups($cm);
            foreach ($allowedgroups as $groupid => $group) {
                if (digestforum_user_can_post_discussion($digestforum, $groupid, -1, $cm, $modcontext)) {
                    $groupstopostto[] = $groupid;
                }
            }
        } else if (isset($fromform->groupinfo)) {
            // Use the value provided in the dropdown group selection.
            $groupstopostto[] = $fromform->groupinfo;
            $redirectto->param('group', $fromform->groupinfo);
        } else if (isset($fromform->groupid) && !empty($fromform->groupid)) {
            // Use the value provided in the hidden form element instead.
            $groupstopostto[] = $fromform->groupid;
            $redirectto->param('group', $fromform->groupid);
        } else {
            // Use the value for all participants instead.
            $groupstopostto[] = -1;
        }

        // Before we post this we must check that the user will not exceed the blocking threshold.
        digestforum_check_blocking_threshold($thresholdwarning);

        foreach ($groupstopostto as $group) {
            if (!digestforum_user_can_post_discussion($digestforum, $group, -1, $cm, $modcontext)) {
                print_error('cannotcreatediscussion', 'digestforum');
            }

            $discussion->groupid = $group;
            $message = '';
            if ($discussion->id = digestforum_add_discussion($discussion, $mform_post, $message)) {

                $params = array(
                    'context' => $modcontext,
                    'objectid' => $discussion->id,
                    'other' => array(
                        'digestforumid' => $digestforum->id,
                    )
                );
                $event = \mod_digestforum\event\discussion_created::create($params);
                $event->add_record_snapshot('digestforum_discussions', $discussion);
                $event->trigger();

                if ($fromform->mailnow) {
                    $message .= get_string("postmailnow", "digestforum");
                } else {
                    $message .= '<p>'.get_string("postaddedsuccess", "digestforum") . '</p>';
                    $message .= '<p>'.get_string("postaddedtimeleft", "digestforum", format_time($CFG->maxeditingtime)) . '</p>';
                }

                $subscribemessage = digestforum_post_subscription($fromform, $digestforum, $discussion);
            } else {
                print_error("couldnotadd", "digestforum", $errordestination);
            }
        }

        // Update completion status.
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) &&
                ($digestforum->completiondiscussions || $digestforum->completionposts)) {
            $completion->update_state($cm, COMPLETION_COMPLETE);
        }

        // Redirect back to the discussion.
        redirect(
                digestforum_go_back_to($redirectto->out()),
                $message . $subscribemessage,
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
    }
}



// To get here they need to edit a post, and the $post
// variable will be loaded with all the particulars,
// so bring up the form.

// $course, $digestforum are defined.  $discussion is for edit and reply only.

if ($post->discussion) {
    if (! $toppost = $DB->get_record("digestforum_posts", array("discussion" => $post->discussion, "parent" => 0))) {
        print_error('cannotfindparentpost', 'digestforum', '', $post->id);
    }
} else {
    $toppost = new stdClass();
    $toppost->subject = ($digestforum->type == "news") ? get_string("addanewtopic", "digestforum") :
                                                   get_string("addanewdiscussion", "digestforum");
}

if (empty($post->edit)) {
    $post->edit = '';
}

if (empty($discussion->name)) {
    if (empty($discussion)) {
        $discussion = new stdClass();
    }
    $discussion->name = $digestforum->name;
}
if ($digestforum->type == 'single') {
    // There is only one discussion thread for this digestforum type. We should
    // not show the discussion name (same as digestforum name in this case) in
    // the breadcrumbs.
    $strdiscussionname = '';
} else {
    // Show the discussion name in the breadcrumbs.
    $strdiscussionname = format_string($discussion->name).':';
}

$forcefocus = empty($reply) ? NULL : 'message';

if (!empty($discussion->id)) {
    $PAGE->navbar->add(format_string($toppost->subject, true), "discuss.php?d=$discussion->id");
}

if ($post->parent) {
    $PAGE->navbar->add(get_string('reply', 'digestforum'));
}

if ($edit) {
    $PAGE->navbar->add(get_string('edit', 'digestforum'));
}

$PAGE->set_title("$course->shortname: $strdiscussionname ".format_string($toppost->subject));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($digestforum->name), 2);

// checkup
if (!empty($parent) && !digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
    print_error('cannotreply', 'digestforum');
}
if (empty($parent) && empty($edit) && !digestforum_user_can_post_discussion($digestforum, $groupid, -1, $cm, $modcontext)) {
    print_error('cannotcreatediscussion', 'digestforum');
}

if ($digestforum->type == 'qanda'
            && !has_capability('mod/digestforum:viewqandawithoutposting', $modcontext)
            && !empty($discussion->id)
            && !digestforum_user_has_posted($digestforum->id, $discussion->id, $USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify','digestforum'));
}

// If there is a warning message and we are not editing a post we need to handle the warning.
if (!empty($thresholdwarning) && !$edit) {
    // Here we want to throw an exception if they are no longer allowed to post.
    digestforum_check_blocking_threshold($thresholdwarning);
}

if (!empty($parent)) {
    if (!$discussion = $DB->get_record('digestforum_discussions', array('id' => $parent->discussion))) {
        print_error('notpartofdiscussion', 'digestforum');
    }

    digestforum_print_post($parent, $discussion, $digestforum, $cm, $course, false, false, false);
    if (empty($post->edit)) {
        if ($digestforum->type != 'qanda' || digestforum_user_can_see_discussion($digestforum, $discussion, $modcontext)) {
            $digestforumtracked = digestforum_tp_is_tracked($digestforum);
            $posts = digestforum_get_all_discussion_posts($discussion->id, "created ASC", $digestforumtracked);
            digestforum_print_posts_threaded($course, $cm, $digestforum, $discussion, $parent, 0, false, $digestforumtracked, $posts);
        }
    }
} else {
    if (!empty($digestforum->intro)) {
        echo $OUTPUT->box(format_module_intro('digestforum', $digestforum, $cm->id), 'generalbox', 'intro');

        if (!empty($CFG->enableplagiarism)) {
            require_once($CFG->libdir.'/plagiarismlib.php');
            echo plagiarism_print_disclosure($cm->id);
        }
    }
}

if (!empty($formheading)) {
    echo $OUTPUT->heading($formheading, 2, array('class' => 'accesshide'));
}
$mform_post->display();

echo $OUTPUT->footer();
