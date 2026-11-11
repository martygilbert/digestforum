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
 * Library of functions for the digestforum module.
 *
 * @package   mod_digestforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\url;
use mod_digestforum\local\entities\digestforum as digestforum_entity;

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once(__DIR__ . '/deprecatedlib.php');
require_once($CFG->libdir . '/filelib.php');

// Constants.

define('DFORUM_MODE_FLATOLDEST', 1);
define('DFORUM_MODE_FLATNEWEST', -1);
define('DFORUM_MODE_THREADED', 2);
define('DFORUM_MODE_NESTED', 3);
define('DFORUM_MODE_NESTED_V2', 4);

define('DFORUM_CHOOSESUBSCRIBE', 0);
define('DFORUM_FORCESUBSCRIBE', 1);
define('DFORUM_INITIALSUBSCRIBE', 2);
define('DFORUM_DISALLOWSUBSCRIBE', 3);

/**
 * DFORUM_TRACKING_OFF - Tracking is not available for this digestforum.
 */
define('DFORUM_TRACKING_OFF', 0);

/**
 * DFORUM_TRACKING_OPTIONAL - Tracking is based on user preference.
 */
define('DFORUM_TRACKING_OPTIONAL', 1);

/**
 * DFORUM_TRACKING_FORCED - Tracking is on, regardless of user setting.
 * Treated as DFORUM_TRACKING_OPTIONAL if $CFG->digestforum_allowforcedreadtracking is off.
 */
define('DFORUM_TRACKING_FORCED', 2);

define('DFORUM_MAILED_PENDING', 0);
define('DFORUM_MAILED_SUCCESS', 1);
define('DFORUM_MAILED_ERROR', 2);

if (!defined('DFORUM_CRON_USER_CACHE')) {
    /** Defines how many full user records are cached in digestforum cron. */
    define('DFORUM_CRON_USER_CACHE', 5000);
}

/**
 * DFORUM_POSTS_ALL_USER_GROUPS - All the posts in groups where the user is enrolled.
 */
define('DFORUM_POSTS_ALL_USER_GROUPS', -2);

define('DFORUM_DISCUSSION_PINNED', 1);
define('DFORUM_DISCUSSION_UNPINNED', 0);

// Standard functions.

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $digestforum add digestforum instance
 * @param mod_digestforum_mod_form $mform
 * @return int intance id
 */
function digestforum_add_instance($digestforum, $mform = null) {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

    $digestforum->timemodified = time();

    if (empty($digestforum->assessed)) {
        $digestforum->assessed = 0;
    }

    if (empty($digestforum->ratingtime) || empty($digestforum->assessed)) {
        $digestforum->assesstimestart  = 0;
        $digestforum->assesstimefinish = 0;
    }

    $digestforum->id = $DB->insert_record('digestforum', $digestforum);
    $modcontext = context_module::instance($digestforum->coursemodule);

    if ($digestforum->type == 'single') {
        // Create related discussion.
        $discussion = new stdClass();
        $discussion->course        = $digestforum->course;
        $discussion->digestforum         = $digestforum->id;
        $discussion->name          = $digestforum->name;
        $discussion->assessed      = $digestforum->assessed;
        $discussion->message       = $digestforum->intro;
        $discussion->messageformat = $digestforum->introformat;
        $discussion->messagetrust  = trusttext_trusted(context_course::instance($digestforum->course));
        $discussion->mailnow       = false;
        $discussion->groupid       = -1;

        $message = '';

        $discussion->id = digestforum_add_discussion($discussion, null, $message);

        if ($mform && $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $discussion = $DB->get_record('digestforum_discussions', ['id' => $discussion->id], '*', MUST_EXIST);
            $post = $DB->get_record('digestforum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);

            $options = ['subdirs' => true]; // Use the same options as intro field!
            $post->message = file_save_draft_area_files(
                $draftid,
                $modcontext->id,
                'mod_digestforum',
                'post',
                $post->id,
                $options,
                $post->message,
            );
            $DB->set_field('digestforum_posts', 'message', $post->message, ['id' => $post->id]);
        }
    }

    digestforum_update_calendar($digestforum, $digestforum->coursemodule);
    digestforum_grade_item_update($digestforum);

    $completiontimeexpected = !empty($digestforum->completionexpected) ? $digestforum->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $digestforum->coursemodule,
        'digestforum',
        $digestforum->id,
        $completiontimeexpected,
    );

    return $digestforum->id;
}

/**
 * Handle changes following the creation of a digestforum instance.
 * This function is typically called by the course_module_created observer.
 *
 * @param object $context the digestforum context
 * @param stdClass $digestforum The digestforum object
 * @return void
 */
function digestforum_instance_created($context, $digestforum) {
    if ($digestforum->forcesubscribe == DFORUM_INITIALSUBSCRIBE) {
        $users = \mod_digestforum\subscriptions::get_potential_subscribers($context, 0, 'u.id, u.email');
        foreach ($users as $user) {
            \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum, $context);
        }
    }
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $digestforum digestforum instance (with magic quotes)
 * @param mod_digestforum_mod_form $mform The submitted form
 * @return bool success
 */
function digestforum_update_instance($digestforum, $mform) {
    global $CFG, $DB, $OUTPUT, $USER;

    require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

    $digestforum->timemodified = time();
    $digestforum->id           = $digestforum->instance;

    if (empty($digestforum->assessed)) {
        $digestforum->assessed = 0;
    }

    if (empty($digestforum->ratingtime) || empty($digestforum->assessed)) {
        $digestforum->assesstimestart  = 0;
        $digestforum->assesstimefinish = 0;
    }

    $olddigestforum = $DB->get_record('digestforum', ['id' => $digestforum->id]);

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire digestforum
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score)
    // when calculating the grade.
    $updategrades = false;

    if ($olddigestforum->assessed <> $digestforum->assessed) {
        // Whether this digestforum is rated.
        $updategrades = true;
    }

    if ($olddigestforum->scale <> $digestforum->scale) {
        // The scale currently in use.
        $updategrades = true;
    }

    if (empty($olddigestforum->grade_digestforum) || $olddigestforum->grade_digestforum <> $digestforum->grade_digestforum) {
        // The whole digestforum grading.
        $updategrades = true;
    }

    if ($updategrades) {
        digestforum_update_grades($digestforum); // Recalculate grades for the digestforum.
    }

    if ($digestforum->type == 'single') {
        // Update related discussion and post.
        $discussions = $DB->get_records('digestforum_discussions', ['digestforum' => $digestforum->id], 'timemodified ASC');
        if (!empty($discussions)) {
            if (count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'digestforum'));
            }
            $discussion = array_pop($discussions);
        } else {
            // Try to recover by creating initial discussion - MDL-16262.
            $discussion = new stdClass();
            $discussion->course          = $digestforum->course;
            $discussion->digestforum           = $digestforum->id;
            $discussion->name            = $digestforum->name;
            $discussion->assessed        = $digestforum->assessed;
            $discussion->message         = $digestforum->intro;
            $discussion->messageformat   = $digestforum->introformat;
            $discussion->messagetrust    = true;
            $discussion->mailnow         = false;
            $discussion->groupid         = -1;

            $message = '';

            digestforum_add_discussion($discussion, null, $message);

            if (! $discussion = $DB->get_record('digestforum_discussions', ['digestforum' => $digestforum->id])) {
                throw new \moodle_exception('cannotadd', 'digestforum');
            }
        }
        if (! $post = $DB->get_record('digestforum_posts', ['id' => $discussion->firstpost])) {
            throw new \moodle_exception('cannotfindfirstpost', 'digestforum');
        }

        $cm         = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $modcontext = context_module::instance($cm->id, MUST_EXIST);

        $post = $DB->get_record('digestforum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
        $post->subject       = $digestforum->name;
        $post->message       = $digestforum->intro;
        $post->messageformat = $digestforum->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $digestforum->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities.

        if ($mform && $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $options = ['subdirs' => true]; // Use the same options as intro field!
            $post->message = file_save_draft_area_files(
                $draftid,
                $modcontext->id,
                'mod_digestforum',
                'post',
                $post->id,
                $options,
                $post->message,
            );
        }

        \mod_digestforum\local\entities\post::add_message_counts($post);
        $DB->update_record('digestforum_posts', $post);
        $discussion->name = $digestforum->name;
        $DB->update_record('digestforum_discussions', $discussion);
    }

    $DB->update_record('digestforum', $digestforum);

    $modcontext = context_module::instance($digestforum->coursemodule);
    if (
        ($digestforum->forcesubscribe == DFORUM_INITIALSUBSCRIBE)
        && ($olddigestforum->forcesubscribe <> $digestforum->forcesubscribe)
    ) {
        $users = \mod_digestforum\subscriptions::get_potential_subscribers($modcontext, 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum, $modcontext);
        }
    }

    digestforum_update_calendar($digestforum, $digestforum->coursemodule);
    digestforum_grade_item_update($digestforum);

    $completiontimeexpected = !empty($digestforum->completionexpected) ? $digestforum->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $digestforum->coursemodule,
        'digestforum',
        $digestforum->id,
        $completiontimeexpected,
    );

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id digestforum instance id
 * @return bool success
 */
function digestforum_delete_instance($id) {
    global $DB;

    if (!$digestforum = $DB->get_record('digestforum', ['id' => $id])) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', ['id' => $cm->course])) {
        return false;
    }

    $context = context_module::instance($cm->id);

    // Now get rid of all files.
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    \core_completion\api::update_completion_date_event($cm->id, 'digestforum', $digestforum->id, null);

    // Delete digest and subscription preferences.
    $DB->delete_records('digestforum_digests', ['digestforum' => $digestforum->id]);
    $DB->delete_records('digestforum_subscriptions', ['digestforum' => $digestforum->id]);
    $DB->delete_records('digestforum_discussion_subs', ['digestforum' => $digestforum->id]);
    $DB->delete_records('digestforum_tracker', ['digestforumid' => $digestforum->id]);

    if ($discussions = $DB->get_records('digestforum_discussions', ['digestforum' => $digestforum->id])) {
        foreach ($discussions as $discussion) {
            if (!digestforum_delete_discussion($discussion, true, $course, $cm, $digestforum)) {
                $result = false;
            }
        }
    }

    digestforum_tp_delete_read_records(-1, -1, -1, $digestforum->id);

    digestforum_grade_item_delete($digestforum);

    // We must delete the module record after we delete the grade item.
    if (!$DB->delete_records('digestforum', ['id' => $digestforum->id])) {
        $result = false;
    }

    return $result;
}


/**
 * Indicates API features that the digestforum supports.
 *
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_COMPLETION_HAS_RULES
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature
 * @return mixed True if module supports feature, false if not, null if doesn't know or string for the module purpose.
 */
function digestforum_supports($feature) {
    return match ($feature) {
        FEATURE_GROUPS => true,
        FEATURE_GROUPINGS => true,
        FEATURE_MOD_INTRO => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_GRADE_HAS_GRADE => true,
        FEATURE_GRADE_OUTCOMES => true,
        FEATURE_RATE => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_PLAGIARISM => true,
        FEATURE_ADVANCED_GRADING => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_COLLABORATION,
        FEATURE_MOD_OTHERPURPOSE => MOD_PURPOSE_COMMUNICATION,
        default => null,
    };
}

/**
 * Create a message-id string to use in the custom headers of digestforum notification emails
 *
 * message-id is used by email clients to identify emails and to nest conversations
 *
 * @param int $postid The ID of the digestforum post we are notifying the user about
 * @param int $usertoid The ID of the user being notified
 * @param string|null $date The digest date, so that each day's digest gets its own message-id. Defaults to today.
 * @return string A unique message-id
 */
function digestforum_get_email_message_id($postid, $usertoid, $date = null) {
    if (empty($date)) {
        $date = userdate(time(), '%Y%m%d');
    }
    return generate_email_messageid(hash('sha256', $postid . 'to' . $usertoid . 'on' . $date));
}

/**
 *
 * @param object $course
 * @param object $user
 * @param object $mod TODO this is not used in this function, refactor
 * @param object $digestforum
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function digestforum_user_outline($course, $user, $mod, $digestforum) {
    global $CFG;
    require_once("$CFG->libdir/gradelib.php");

    $gradeinfo = '';
    $gradetime = 0;

    $grades = grade_get_grades($course->id, 'mod', 'digestforum', $digestforum->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        // Item 0 is the rating.
        $grade = reset($grades->items[0]->grades);
        $gradetime = max($gradetime, grade_get_date_for_user_grade($grade, $user));
        if (!$grade->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            $gradeinfo .= get_string('gradeforrating', 'digestforum', $grade) .  html_writer::empty_tag('br');
        } else {
            $gradeinfo .= get_string('gradeforratinghidden', 'digestforum') . html_writer::empty_tag('br');
        }
    }

    // Item 1 is the whole-digestforum grade.
    if (!empty($grades->items[1]->grades)) {
        $grade = reset($grades->items[1]->grades);
        $gradetime = max($gradetime, grade_get_date_for_user_grade($grade, $user));
        if (!$grade->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            $gradeinfo .= get_string('gradeforwholedigestforum', 'digestforum', $grade) .  html_writer::empty_tag('br');
        } else {
            $gradeinfo .= get_string('gradeforwholedigestforumhidden', 'digestforum') . html_writer::empty_tag('br');
        }
    }

    $count = digestforum_count_user_posts($digestforum->id, $user->id);
    if ($count && $count->postcount > 0) {
        $info = get_string("numposts", "digestforum", $count->postcount);
        $time = $count->lastpost;

        if ($gradeinfo) {
            $info .= ', ' . $gradeinfo;
            $time = max($time, $gradetime);
        }

        return (object) [
            'info' => $info,
            'time' => $time,
        ];
    } else if ($gradeinfo) {
        return (object) [
            'info' => $gradeinfo,
            'time' => $gradetime,
        ];
    }

    return null;
}


/**
 * Print a detailed report of the user's grades and posts in this digestforum, for the course outline.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $digestforum
 */
function digestforum_user_complete($course, $user, $mod, $digestforum) {
    global $CFG, $USER;
    require_once("$CFG->libdir/gradelib.php");

    $getgradeinfo = function ($grades, string $type) use ($course): string {
        global $OUTPUT;

        if (empty($grades)) {
            return '';
        }

        $result = '';
        $grade = reset($grades);
        if (!$grade->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            $result .= $OUTPUT->container(get_string("gradefor{$type}", "digestforum", $grade));
            if ($grade->str_feedback) {
                $result .= $OUTPUT->container(get_string('feedback') . ': ' . $grade->str_feedback);
            }
        } else {
            $result .= $OUTPUT->container(get_string("gradefor{$type}hidden", "digestforum"));
        }

        return $result;
    };

    $grades = grade_get_grades($course->id, 'mod', 'digestforum', $digestforum->id, $user->id);

    // Item 0 is the rating.
    if (!empty($grades->items[0]->grades)) {
        echo $getgradeinfo($grades->items[0]->grades, 'rating');
    }

    // Item 1 is the whole-digestforum grade.
    if (!empty($grades->items[1]->grades)) {
        echo $getgradeinfo($grades->items[1]->grades, 'wholedigestforum');
    }

    if ($posts = digestforum_get_user_posts($digestforum->id, $user->id)) {
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id)) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        $context = context_module::instance($cm->id);
        $discussions = digestforum_get_user_involved_discussions($digestforum->id, $user->id);
        $posts = array_filter($posts, function ($post) use ($discussions) {
            return isset($discussions[$post->discussion]);
        });
        $entityfactory = mod_digestforum\local\container::get_entity_factory();
        $rendererfactory = mod_digestforum\local\container::get_renderer_factory();
        $postrenderer = $rendererfactory->get_posts_renderer();

        echo $postrenderer->render(
            $USER,
            [$digestforum->id => $entityfactory->get_digestforum_from_stdclass($digestforum, $context, $cm, $course)],
            array_map(function ($discussion) use ($entityfactory) {
                return $entityfactory->get_discussion_from_stdclass($discussion);
            }, $discussions),
            array_map(function ($post) use ($entityfactory) {
                return $entityfactory->get_post_from_stdclass($post);
            }, $posts)
        );
    } else {
        echo "<p>" . get_string("noposts", "digestforum") . "</p>";
    }
}

/**
 * Returns whether the discussion group is visible by the current user or not.
 *
 * @since Moodle 2.8, 2.7.1, 2.6.4
 * @param cm_info $cm The discussion course module
 * @param int $discussiongroupid The discussion groupid
 * @return bool
 */
function digestforum_is_user_group_discussion(cm_info $cm, $discussiongroupid) {

    if ($discussiongroupid == -1 || $cm->effectivegroupmode != SEPARATEGROUPS) {
        return true;
    }

    if (isguestuser()) {
        return false;
    }

    if (
        has_capability('moodle/site:accessallgroups', context_module::instance($cm->id)) ||
            in_array($discussiongroupid, $cm->get_modinfo()->get_groups($cm->groupingid))
    ) {
        return true;
    }

    return false;
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function digestforum_print_recent_activity($course, $viewfullnames, $timestart) {
    global $USER, $DB, $OUTPUT;

    // Do not use log table if possible, it may be huge and is expensive to join with other tables.

    $userfieldsapi = \core_user\fields::for_userpic();
    $allnamefields = $userfieldsapi->get_sql('u', false, '', 'duserid', false)->selects;
    // Order by initial posting date.
    if (
        !$posts = $DB->get_records_sql("SELECT p.*,
                                              f.course, f.type AS digestforumtype, f.name AS digestforumname, f.intro,
                                              f.introformat, f.duedate,
                                              f.cutoffdate, f.assessed AS digestforumassessed, f.assesstimestart,
                                              f.assesstimefinish,
                                              f.scale, f.grade_digestforum, f.maxbytes, f.maxattachments, f.forcesubscribe,
                                              f.trackingtype, f.rsstype, f.rssarticles, f.timemodified, f.warnafter, f.blockafter,
                                              f.blockperiod, f.completiondiscussions, f.completionreplies, f.completionposts,
                                              f.displaywordcount, f.lockdiscussionafter, f.grade_digestforum_notify,
                                              f.showimmediately,
                                              d.name AS discussionname, d.firstpost, d.userid AS discussionstarter,
                                              d.assessed AS discussionassessed, d.timemodified, d.usermodified, d.digestforum,
                                              d.groupid,
                                              d.timestart, d.timeend, d.pinned, d.timelocked,
                                              $allnamefields
                                         FROM {digestforum_posts} p
                                              JOIN {digestforum_discussions} d ON d.id = p.discussion
                                              JOIN {digestforum} f             ON f.id = d.digestforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.course = ? AND p.deleted <> 1
                                     ORDER BY p.id ASC", [$timestart, $course->id])
    ) {
         return false;
    }

    $modinfo = get_fast_modinfo($course);

    $strftimerecent = get_string('strftimerecent');

    $managerfactory = mod_digestforum\local\container::get_manager_factory();
    $entityfactory = mod_digestforum\local\container::get_entity_factory();

    $discussions = [];
    $capmanagers = [];
    $printposts = [];
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['digestforum'][$post->digestforum])) {
            // Not visible.
            continue;
        }
        $cm = $modinfo->instances['digestforum'][$post->digestforum];
        if (!$cm->uservisible) {
            continue;
        }

        // Get the discussion. Cache if not yet available.
        if (!isset($discussions[$post->discussion])) {
            // Build the discussion record object from the post data.
            $discussionrecord = (object)[
                'id' => $post->discussion,
                'course' => $post->course,
                'digestforum' => $post->digestforum,
                'name' => $post->discussionname,
                'firstpost' => $post->firstpost,
                'userid' => $post->discussionstarter,
                'groupid' => $post->groupid,
                'assessed' => $post->discussionassessed,
                'timemodified' => $post->timemodified,
                'usermodified' => $post->usermodified,
                'timestart' => $post->timestart,
                'timeend' => $post->timeend,
                'pinned' => $post->pinned,
                'timelocked' => $post->timelocked,
            ];
            // Build the discussion entity from the factory and cache it.
            $discussions[$post->discussion] = $entityfactory->get_discussion_from_stdclass($discussionrecord);
        }
        $discussionentity = $discussions[$post->discussion];

        // Get the capability manager. Cache if not yet available.
        if (!isset($capmanagers[$post->digestforum])) {
            $context = context_module::instance($cm->id);
            $coursemodule = $cm->get_course_module_record();
            // Build the digestforum record object from the post data.
            $digestforumrecord = (object)[
                'id' => $post->digestforum,
                'course' => $post->course,
                'type' => $post->digestforumtype,
                'name' => $post->digestforumname,
                'intro' => $post->intro,
                'introformat' => $post->introformat,
                'duedate' => $post->duedate,
                'cutoffdate' => $post->cutoffdate,
                'assessed' => $post->digestforumassessed,
                'assesstimestart' => $post->assesstimestart,
                'assesstimefinish' => $post->assesstimefinish,
                'scale' => $post->scale,
                'grade_digestforum' => $post->grade_digestforum,
                'maxbytes' => $post->maxbytes,
                'maxattachments' => $post->maxattachments,
                'forcesubscribe' => $post->forcesubscribe,
                'trackingtype' => $post->trackingtype,
                'rsstype' => $post->rsstype,
                'rssarticles' => $post->rssarticles,
                'timemodified' => $post->timemodified,
                'warnafter' => $post->warnafter,
                'blockafter' => $post->blockafter,
                'blockperiod' => $post->blockperiod,
                'completiondiscussions' => $post->completiondiscussions,
                'completionreplies' => $post->completionreplies,
                'completionposts' => $post->completionposts,
                'displaywordcount' => $post->displaywordcount,
                'lockdiscussionafter' => $post->lockdiscussionafter,
                'grade_digestforum_notify' => $post->grade_digestforum_notify,
                'showimmediately' => $post->showimmediately,
            ];
            // Build the digestforum entity from the factory.
            $digestforumentity = $entityfactory->get_digestforum_from_stdclass(
                $digestforumrecord,
                $context,
                $coursemodule,
                $course,
            );
            // Get the capability manager of this digestforum and cache it.
            $capmanagers[$post->digestforum] = $managerfactory->get_capability_manager($digestforumentity);
        }
        $capabilitymanager = $capmanagers[$post->digestforum];

        // Get the post entity.
        $postentity = $entityfactory->get_post_from_stdclass($post);

        // Check if the user can view the post.
        if ($capabilitymanager->can_view_post($USER, $discussionentity, $postentity)) {
            $printposts[] = $post;
        }
    }
    unset($posts);

    if (!$printposts) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newdigestforumposts', 'digestforum') . ':', 6);
    $list = html_writer::start_tag('ul', ['class' => 'unlist']);

    foreach ($printposts as $post) {
        $subjectclass = empty($post->parent) ? ' bold' : '';
        $authorhidden = digestforum_is_author_hidden($post, (object) ['type' => $post->digestforumtype]);

        $list .= html_writer::start_tag('li');
        $list .= html_writer::start_div('head');
        $list .= html_writer::div(userdate_htmltime($post->modified, $strftimerecent), 'date');
        if (!$authorhidden) {
            $list .= html_writer::div(fullname($post, $viewfullnames), 'name');
        }
        $list .= html_writer::end_div(); // Head.

        $list .= html_writer::start_div('info' . $subjectclass);
        $discussionurl = new moodle_url('/mod/digestforum/discuss.php', ['d' => $post->discussion]);
        if (!empty($post->parent)) {
            $discussionurl->param('parent', $post->parent);
            $discussionurl->set_anchor('p' . $post->id);
        }
        $post->subject = break_up_long_words(format_string($post->subject, true));
        $list .= html_writer::link($discussionurl, $post->subject, ['rel' => 'bookmark']);
        $list .= html_writer::end_div(); // Info.
        $list .= html_writer::end_tag('li');
    }

    $list .= html_writer::end_tag('ul');
    echo $list;

    return true;
}

/**
 * Update activity grades.
 *
 * @param object $digestforum
 * @param int $userid specific user only, 0 means all
 */
function digestforum_update_grades($digestforum, $userid = 0): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $ratings = null;
    if ($digestforum->assessed) {
        require_once($CFG->dirroot . '/rating/lib.php');

        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        $rm = new rating_manager();
        $ratings = $rm->get_user_grades((object) [
            'component' => 'mod_digestforum',
            'ratingarea' => 'post',
            'contextid' => \context_module::instance($cm->id)->id,

            'modulename' => 'digestforum',
            'moduleid  ' => $digestforum->id,
            'userid' => $userid,
            'aggregationmethod' => $digestforum->assessed,
            'scaleid' => $digestforum->scale,
            'itemtable' => 'digestforum_posts',
            'itemtableusercolumn' => 'userid',
        ]);
    }

    $digestforumgrades = null;
    if ($digestforum->grade_digestforum) {
        $sql = <<<EOF
SELECT
    g.userid,
    0 as datesubmitted,
    g.grade as rawgrade,
    g.timemodified as dategraded
  FROM {digestforum} f
  JOIN {digestforum_grades} g ON g.digestforum = f.id
 WHERE f.id = :digestforumid
EOF;

        $params = [
            'digestforumid' => $digestforum->id,
        ];

        if ($userid) {
            $sql .= " AND g.userid = :userid";
            $params['userid'] = $userid;
        }

        $digestforumgrades = [];
        if ($grades = $DB->get_recordset_sql($sql, $params)) {
            foreach ($grades as $userid => $grade) {
                if ($grade->rawgrade != -1) {
                    $digestforumgrades[$userid] = $grade;
                }
            }
            $grades->close();
        }
    }

    digestforum_grade_item_update($digestforum, $ratings, $digestforumgrades);
}

/**
 * Create/update grade items for given digestforum.
 *
 * @param stdClass $digestforum Forum object with extra cmidnumber
 * @param mixed $ratings Optional array/object of rating grade(s); 'reset' means reset grades in gradebook
 * @param mixed $digestforumgrades Optional array/object of whole-digestforum grade(s); 'reset' means reset grades
 */
function digestforum_grade_item_update($digestforum, $ratings = null, $digestforumgrades = null): void {
    global $CFG;
    require_once("{$CFG->libdir}/gradelib.php");

    // Update the rating.
    $item = [
        'itemname' => get_string('gradeitemnameforrating', 'digestforum', $digestforum),
        'idnumber' => $digestforum->cmidnumber,
    ];

    if (!$digestforum->assessed || $digestforum->scale == 0) {
        $item['gradetype'] = GRADE_TYPE_NONE;
    } else if ($digestforum->scale > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax']  = $digestforum->scale;
        $item['grademin']  = 0;
    } else if ($digestforum->scale < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid']   = -$digestforum->scale;
    }

    if ($ratings === 'reset') {
        $item['reset'] = true;
        $ratings = null;
    }
    // Itemnumber 0 is the rating.
    grade_update('mod/digestforum', $digestforum->course, 'mod', 'digestforum', $digestforum->id, 0, $ratings, $item);

    // Whole digestforum grade.
    $item = [
        'itemname' => get_string('gradeitemnameforwholedigestforum', 'digestforum', $digestforum),
        // Note: We do not need to store the idnumber here.
    ];

    if (!$digestforum->grade_digestforum) {
        $item['gradetype'] = GRADE_TYPE_NONE;
    } else if ($digestforum->grade_digestforum > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax'] = $digestforum->grade_digestforum;
        $item['grademin'] = 0;
    } else if ($digestforum->grade_digestforum < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid'] = $digestforum->grade_digestforum * -1;
    }

    if ($digestforumgrades === 'reset') {
        $item['reset'] = true;
        $digestforumgrades = null;
    }
    // Itemnumber 1 is the whole digestforum grade.
    grade_update('mod/digestforum', $digestforum->course, 'mod', 'digestforum', $digestforum->id, 1, $digestforumgrades, $item);
}

/**
 * Delete grade item for given digestforum.
 *
 * @param stdClass $digestforum Forum object
 */
function digestforum_grade_item_delete($digestforum) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    grade_update('mod/digestforum', $digestforum->course, 'mod', 'digestforum', $digestforum->id, 0, null, ['deleted' => 1]);
    grade_update('mod/digestforum', $digestforum->course, 'mod', 'digestforum', $digestforum->id, 1, null, ['deleted' => 1]);
}

/**
 * Checks if scale is being used by any instance of digestforum.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid
 * @return boolean True if the scale is used by any digestforum
 */
function digestforum_scale_used_anywhere(int $scaleid): bool {
    global $DB;

    if (empty($scaleid)) {
        return false;
    }

    return $DB->record_exists_select('digestforum', "scale = ? and assessed > 0", [$scaleid * -1]);
}

// SQL functions.

/**
 * Gets a post with all info ready for digestforum_print_post
 * Most of these joins are just to get the digestforum id
 *
 * @param int $postid
 * @return mixed array of posts or false
 */
function digestforum_get_post_full($postid) {
    global $CFG, $DB;

    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    return $DB->get_record_sql("SELECT p.*, d.digestforum, $allnames, u.email, u.picture, u.imagealt
                             FROM {digestforum_posts} p
                                  JOIN {digestforum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", [$postid]);
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @param   int     $discussionid   The Discussion to fetch.
 * @param   string  $sort           The sorting to apply.
 * @param   bool    $tracking       Whether the user tracks this digestforum.
 * @return  array                   The posts in the discussion.
 */
function digestforum_get_all_discussion_posts($discussionid, $sort, $tracking = false) {
    global $CFG, $DB, $USER;

    $trsel  = "";
    $trjoin = "";
    $params = [];

    if ($tracking) {
        $trsel  = ", fr.id AS postread";
        $trjoin = "LEFT JOIN {digestforum_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
        $params[] = $USER->id;
    }

    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $params[] = $discussionid;
    if (
        !$posts = $DB->get_records_sql("SELECT p.*, $allnames, u.email, u.picture, u.imagealt $trsel
                                     FROM {digestforum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $trjoin
                                    WHERE p.discussion = ?
                                 ORDER BY $sort", $params)
    ) {
        return [];
    }

    foreach ($posts as $pid => $p) {
        if ($tracking) {
            if (digestforum_tp_is_post_old($p)) {
                 $posts[$pid]->postread = true;
            }
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // Parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = [];
        }
        $posts[$p->parent]->children[$pid] =& $posts[$pid];
    }

    // Start with the last child of the first post.
    $post = &$posts[reset($posts)->id];

    $lastpost = false;
    while (!$lastpost) {
        if (!isset($post->children)) {
            $post->lastpost = true;
            $lastpost = true;
        } else {
             // Go to the last child of this post.
            $post = &$posts[end($post->children)->id];
        }
    }

    return $posts;
}

/**
 * An array of digestforum objects that the user is allowed to read/search through.
 *
 * @param int $userid
 * @param int $courseid if 0, we look for digestforums throughout the whole site.
 * @return array of digestforum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function digestforum_get_readable_digestforums($userid, $courseid = 0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot . '/course/lib.php');

    if (!$digestforummod = $DB->get_record('modules', ['name' => 'digestforum'])) {
        throw new \moodle_exception('notinstalled', 'digestforum');
    }

    if ($courseid) {
        $courses = $DB->get_records('course', ['id' => $courseid]);
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        $courses1 = $DB->get_records('course', ['id' => SITEID]);
        $courses2 = enrol_get_users_courses($userid, true, ['modinfo']);
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return [];
    }

    $readabledigestforums = [];

    foreach ($courses as $course) {
        $modinfo = get_fast_modinfo($course);

        if (empty($modinfo->instances['digestforum'])) {
            // Hmm, no digestforums?
            continue;
        }

        $coursedigestforums = $DB->get_records('digestforum', ['course' => $course->id]);

        foreach ($modinfo->instances['digestforum'] as $digestforumid => $cm) {
            if (!$cm->uservisible || !isset($coursedigestforums[$digestforumid])) {
                continue;
            }
            $context = context_module::instance($cm->id);
            $digestforum = $coursedigestforums[$digestforumid];
            $digestforum->context = $context;
            $digestforum->cm = $cm;

            if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
                continue;
            }

            // Group access.
            if (
                groups_get_activity_groupmode(
                    $cm,
                    $course,
                ) == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)
            ) {
                $digestforum->onlygroups = $modinfo->get_groups($cm->groupingid);
                $digestforum->onlygroups[] = -1;
            }

            // Hidden timed discussions.
            $digestforum->viewhiddentimedposts = true;
            if (!empty($CFG->digestforum_enabletimedposts)) {
                if (!has_capability('mod/digestforum:viewhiddentimedposts', $context)) {
                    $digestforum->viewhiddentimedposts = false;
                }
            }

            // Q and A access.
            if (
                $digestforum->type == 'qanda'
                    && !has_capability('mod/digestforum:viewqandawithoutposting', $context)
            ) {
                // We need to check whether the user has posted in the qanda digestforum.
                $digestforum->onlydiscussions = [];  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this digestforum.
                if ($discussionspostedin = digestforum_discussions_user_has_posted_in($digestforum->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $digestforum->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readabledigestforums[$digestforum->id] = $digestforum;
        }

        unset($modinfo);
    } // End foreach $courses

    return $readabledigestforums;
}

/**
 * Returns a list of posts found using an array of search terms.
 *
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int $totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function digestforum_search_posts(
    $searchterms,
    $courseid,
    $limitfrom,
    $limitnum,
    &$totalcount,
    $extrasql = ''
) {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir . '/searchlib.php');

    $digestforums = digestforum_get_readable_digestforums($USER->id, $courseid);

    if (count($digestforums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = floor(time() / 60) * 60; // DB Cache Friendly.

    $fullaccess = [];
    $where = [];
    $params = [];

    foreach ($digestforums as $digestforumid => $digestforum) {
        $select = [];

        if (!$digestforum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$digestforumid} OR (d.timestart < :timestart{$digestforumid} " .
                "AND (d.timeend = 0 OR d.timeend > :timeend{$digestforumid})))";
            $params = array_merge(
                $params,
                ['userid' . $digestforumid => $USER->id, 'timestart' . $digestforumid => $now, 'timeend' . $digestforumid => $now],
            );
        }

        $cm = $digestforum->cm;
        $context = $digestforum->context;

        if (
            $digestforum->type == 'qanda'
            && !has_capability('mod/digestforum:viewqandawithoutposting', $context)
        ) {
            if (!empty($digestforum->onlydiscussions)) {
                [
                    $discussionidsql,
                    $discussionidparams,
                ] = $DB->get_in_or_equal($digestforum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda' . $digestforumid . '_');
                $params = array_merge($params, $discussionidparams);
                $select[] = "(d.id $discussionidsql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($digestforum->onlygroups)) {
            [
                $groupidsql,
                $groupidparams,
            ] = $DB->get_in_or_equal($digestforum->onlygroups, SQL_PARAMS_NAMED, 'grps' . $digestforumid . '_');
            $params = array_merge($params, $groupidparams);
            $select[] = "d.groupid $groupidsql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.digestforum = :digestforum{$digestforumid} AND $selects)";
            $params['digestforum' . $digestforumid] = $digestforumid;
        } else {
            $fullaccess[] = $digestforumid;
        }
    }

    if ($fullaccess) {
        [$fullidsql, $fullidparams] = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullidparams);
        $where[] = "(d.digestforum $fullidsql)";
    }

    $favjoin = "";
    if (in_array('starredonly:on', $searchterms)) {
        $usercontext = context_user::instance($USER->id);
        $ufservice = \core_favourites\service_factory::get_service_for_user_context($usercontext);
        [$favjoin, $favparams] = $ufservice->get_join_sql_by_type(
            'mod_digestforum',
            'discussions',
            "favourited",
            "d.id"
        );

        $searchterms = array_values(array_diff($searchterms, ['starredonly:on']));
        $params = array_merge($params, $favparams);
        $extrasql .= " AND favourited.itemid IS NOT NULL AND favourited.itemid != 0";
    }

    $selectdiscussion = "(" . implode(" OR ", $where) . ")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach ($searchterms as $searchterm) {
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"", "\"", $searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();

        $tagjoins = '';
        $tagfields = [];
        $tagfieldcount = 0;
        if ($parsearray) {
            foreach ($parsearray as $token) {
                if ($token->getType() == TOKEN_TAGS) {
                    for ($i = 0; $i <= substr_count($token->getValue(), ','); $i++) {
                        // Queries can only have a limited number of joins so set a limit sensible users won't exceed.
                        if ($tagfieldcount > 10) {
                            continue;
                        }
                        $tagjoins .= " LEFT JOIN {tag_instance} ti_$tagfieldcount
                                        ON p.id = ti_$tagfieldcount.itemid
                                            AND ti_$tagfieldcount.component = 'mod_digestforum'
                                            AND ti_$tagfieldcount.itemtype = 'digestforum_posts'";
                        $tagjoins .= " LEFT JOIN {tag} t_$tagfieldcount ON t_$tagfieldcount.id = ti_$tagfieldcount.tagid";
                        $tagfields[] = "t_$tagfieldcount.rawname";
                        $tagfieldcount++;
                    }
                }
            }
            [$messagesearch, $msparams] = search_generate_SQL(
                $parsearray,
                'p.message',
                'p.subject',
                'p.userid',
                'u.id',
                'u.firstname',
                'u.lastname',
                'p.modified',
                'd.digestforum',
                $tagfields
            );

            $params = ($msparams ? array_merge($params, $msparams) : $params);
        }
    }

    $fromsql = "{digestforum_posts} p
                  INNER JOIN {digestforum_discussions} d ON d.id = p.discussion
                  INNER JOIN {user} u ON u.id = p.userid $tagjoins $favjoin";

    $selectsql = ($messagesearch ? $messagesearch . " AND " : "") .
                " p.discussion = d.id
               AND p.userid = u.id
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $searchsql = "SELECT p.*,
                         d.digestforum,
                         $allnames,
                         u.email,
                         u.picture,
                         u.imagealt
                    FROM $fromsql
                   WHERE $selectsql
                ORDER BY p.modified DESC";

    $totalcount = $DB->count_records_sql($countsql, $params);

    return $DB->get_records_sql($searchsql, $params, $limitfrom, $limitnum);
}

/**
 * Get all the posts for a user in a digestforum suitable for digestforum_print_post
 *
 * @param int $digestforumid The digestforum id
 * @param int $userid The user id
 * @uses CONTEXT_MODULE
 * @return array
 */
function digestforum_get_user_posts($digestforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = [$digestforumid, $userid];

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
        if (!has_capability('mod/digestforum:viewhiddentimedposts', context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    return $DB->get_records_sql("SELECT p.*, d.digestforum, $allnames, u.email, u.picture, u.imagealt
                              FROM {digestforum} f
                                   JOIN {digestforum_discussions} d ON d.digestforum = f.id
                                   JOIN {digestforum_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Get all the discussions user participated in
 *
 * @uses CONTEXT_MODULE
 * @param int $digestforumid
 * @param int $userid
 * @return array Array or false
 */
function digestforum_get_user_involved_discussions($digestforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = [$digestforumid, $userid];
    if (!empty($CFG->digestforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
        if (!has_capability('mod/digestforum:viewhiddentimedposts', context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {digestforum} f
                                   JOIN {digestforum_discussions} d ON d.digestforum = f.id
                                   JOIN {digestforum_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a digestforum suitable for digestforum_print_post
 *
 * @param int $digestforumid
 * @param int $userid
 * @return stdClass|false collection of counts or false
 */
function digestforum_count_user_posts($digestforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = [$digestforumid, $userid];
    if (!empty($CFG->digestforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
        if (!has_capability('mod/digestforum:viewhiddentimedposts', context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {digestforum} f
                                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                                  JOIN {digestforum_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ?
                                  $timedsql", $params);
}

/**
 * Given a log entry, return the digestforum post details for it.
 *
 * @param object $log
 * @return array|null
 */
function digestforum_get_post_from_log($log) {
    global $CFG, $DB;

    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    if ($log->action == "add post") {
        return $DB->get_record_sql("SELECT p.*, f.type AS digestforumtype, d.digestforum, d.groupid, $allnames, u.email, u.picture
                                 FROM {digestforum_discussions} d,
                                      {digestforum_posts} p,
                                      {digestforum} f,
                                      {user} u
                                WHERE p.id = ?
                                  AND d.id = p.discussion
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.digestforum", [$log->info]);
    } else if ($log->action == "add discussion") {
        return $DB->get_record_sql("SELECT p.*, f.type AS digestforumtype, d.digestforum, d.groupid, $allnames, u.email, u.picture
                                 FROM {digestforum_discussions} d,
                                      {digestforum_posts} p,
                                      {digestforum} f,
                                      {user} u
                                WHERE d.id = ?
                                  AND d.firstpost = p.id
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.digestforum", [$log->info]);
    }
    return null;
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @param int $discussionid
 * @return array
 */
function digestforum_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {digestforum_discussions} d,
                                  {digestforum_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", [$discussionid]);
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @param   int     $digestforumid
 * @param   string  $digestforumsort
 * @param   int     $limit
 * @param   int     $page
 * @param   int     $perpage
 * @param   boolean $canseeprivatereplies   Whether the current user can see private replies.
 * @return  array
 */
function digestforum_count_discussion_replies(
    $digestforumid,
    $digestforumsort = "",
    $limit = -1,
    $page = -1,
    $perpage = 0,
    $canseeprivatereplies = false
) {
    global $CFG, $DB, $USER;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page * $perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if ($digestforumsort == "") {
        $orderby = "";
        $groupby = "";
    } else {
        $orderby = "ORDER BY $digestforumsort";
        $groupby = ", " . strtolower($digestforumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    $params = ['digestforumid' => $digestforumid];

    if (!$canseeprivatereplies) {
        $privatewhere = ' AND (p.privatereplyto = :currentuser1 OR p.userid = :currentuser2 OR p.privatereplyto = 0)';
        $params['currentuser1'] = $USER->id;
        $params['currentuser2'] = $USER->id;
    } else {
        $privatewhere = '';
    }

    if (($limitfrom == 0 && $limitnum == 0) || $digestforumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {digestforum_posts} p
                       JOIN {digestforum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.digestforum = :digestforumid
                       $privatewhere
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, $params);
    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {digestforum_posts} p
                       JOIN {digestforum_discussions} d ON p.discussion = d.id
                 WHERE d.digestforum = :digestforumid
                       $privatewhere
              GROUP BY p.discussion $groupby $orderby";
        return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }
}

/**
 * Count the discussions in a digestforum that the current user can see.
 *
 * @param object $digestforum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function digestforum_count_discussions($digestforum, $cm, $course) {
    global $CFG, $DB, $USER;

    $cache = cache::make('mod_digestforum', 'digestforum_count_discussions');
    $cachedcounts = $cache->get($course->id);
    if ($cachedcounts === false) {
        $cachedcounts = [];
    }

    $now = floor(time() / 60) * 60; // DB Cache Friendly.

    $params = [$course->id];

    if (!isset($cachedcounts[$digestforum->id])) {
        // Initialize the cachedcounts for this digestforum id to 0 by default. After the
        // database query, if there are discussions then it should update the count.
        $cachedcounts[$digestforum->id] = 0;

        if (!empty($CFG->digestforum_enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {digestforum} f
                       JOIN {digestforum_discussions} d ON d.digestforum = f.id
                 WHERE f.course = ?
                       $timedsql
              GROUP BY f.id";

        if ($counts = $DB->get_records_sql($sql, $params)) {
            foreach ($counts as $count) {
                $cachedcounts[$count->id] = $count->dcount;
            }

            $cache->set($course->id, $cachedcounts);
        } else {
            $cache->set($course->id, $cachedcounts);
            return $cachedcounts[$digestforum->id];
        }
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cachedcounts[$digestforum->id];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $cachedcounts[$digestforum->id];
    }

    require_once($CFG->dirroot . '/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // Add all groups posts.
    $mygroups[-1] = -1;

    [$mygroupssql, $params] = $DB->get_in_or_equal($mygroups);
    $params[] = $digestforum->id;

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {digestforum_discussions} d
             WHERE d.groupid $mygroupssql AND d.digestforum = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Get all discussions in a digestforum
 *
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $digestforumsort
 * @param bool $fullpost
 * @param int $unused
 * @param int $limit
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @param int $groupid if groups enabled, get discussions for this group overriding the current group.
 *                     Use DFORUM_POSTS_ALL_USER_GROUPS for all the user groups
 * @param int $updatedsince retrieve only discussions updated since the given time
 * @return array
 */
function digestforum_get_discussions(
    $cm,
    $digestforumsort = "",
    $fullpost = true,
    $unused = -1,
    $limit = -1,
    $userlastmodified = false,
    $page = -1,
    $perpage = 0,
    $groupid = -1,
    $updatedsince = 0
) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $now = floor(time() / 60) * 60;
    $params = [$cm->instance];

    $modcontext = context_module::instance($cm->id);

    if (!has_capability('mod/digestforum:viewdiscussion', $modcontext)) {
        // User must have perms to view discussions.
        return [];
    }

    if (!empty($CFG->digestforum_enabletimedposts)) {
        // Users must fulfill timed posts.
        if (!has_capability('mod/digestforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page * $perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    $groupmode    = groups_get_activity_groupmode($cm);

    if ($groupmode) {
        if (empty($modcontext)) {
            $modcontext = context_module::instance($cm->id);
        }

        // Special case, we received a groupid to override currentgroup.
        if ($groupid > 0) {
            $course = get_course($cm->course);
            if (!groups_group_visible($groupid, $course, $cm)) {
                // User doesn't belong to this group, return nothing.
                return [];
            }
            $currentgroup = $groupid;
        } else if ($groupid === -1) {
            $currentgroup = groups_get_activity_group($cm);
        } else {
            // Get discussions for all groups current user can see.
            $currentgroup = null;
        }

        if ($groupmode == VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }
        } else {
            // Separate groups.

            // Get discussions for all groups current user can see.
            if ($currentgroup === null) {
                $mygroups = array_keys(groups_get_all_groups($cm->course, $USER->id, $cm->groupingid, 'g.id'));
                if (empty($mygroups)) {
                     $groupselect = "AND d.groupid = -1";
                } else {
                    [$insqlgroups, $inparamsgroups] = $DB->get_in_or_equal($mygroups);
                    $groupselect = "AND (d.groupid = -1 OR d.groupid $insqlgroups)";
                    $params = array_merge($params, $inparamsgroups);
                }
            } else if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }
    if (empty($digestforumsort)) {
        $digestforumsort = digestforum_get_default_sort_order();
    }
    if (!str_contains($digestforumsort, 'id')) {
        $digestforumsort .= ', d.id DESC';
    }
    if (empty($fullpost)) {
        $postdata = "p.id, p.subject, p.modified, p.discussion, p.userid, p.created";
    } else {
        $postdata = "p.*";
    }

    $userfieldsapi = \core_user\fields::for_name();

    if (empty($userlastmodified)) {
        // We don't need to know this.
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = $userfieldsapi->get_sql('um', false, 'um')->selects . ', um.email AS umemail, um.picture AS umpicture,
                        um.imagealt AS umimagealt';
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)";
    }

    $updatedsincesql = '';
    if (!empty($updatedsince)) {
        $updatedsincesql = 'AND d.timemodified > ?';
        $params[] = $updatedsince;
    }

    $discussionfields = "d.id as discussionid, d.course, d.digestforum, d.name, d.firstpost, d.groupid, d.assessed," .
    " d.timemodified, d.usermodified, d.timestart, d.timeend, d.pinned, d.timelocked";

    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $sql = "SELECT $postdata, $discussionfields,
                   $allnames, u.email, u.picture, u.imagealt, u.deleted AS userdeleted $umfields
              FROM {digestforum_discussions} d
                   JOIN {digestforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
                   $umtable
             WHERE d.digestforum = ? AND p.parent = 0
                   $timelimit $groupselect $updatedsincesql
          ORDER BY $digestforumsort";

    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 * Gets the neighbours (previous and next) of a discussion.
 *
 * The calculation is based on the timemodified when time modified or time created is identical
 * It will revert to using the ID to sort consistently. This is better tha skipping a discussion.
 *
 * For blog-style digestforums, the calculation is based on the original creation time of the
 * blog post.
 *
 * Please note that this does not check whether or not the discussion passed is accessible
 * by the user, it simply uses it as a reference to find the neighbours. On the other hand,
 * the returned neighbours are checked and are accessible to the current user.
 *
 * @param object $cm The CM record.
 * @param object $discussion The discussion record.
 * @param object $digestforum The digestforum instance record.
 * @return array That always contains the keys 'prev' and 'next'. When there is a result
 *               they contain the record with minimal information such as 'id' and 'name'.
 *               When the neighbour is not found the value is false.
 */
function digestforum_get_discussion_neighbours($cm, $discussion, $digestforum) {
    global $CFG, $DB, $USER;

    if (
        $cm->instance != $discussion->digestforum
        || $discussion->digestforum != $digestforum->id
        || $digestforum->id != $cm->instance
    ) {
        throw new coding_exception('Discussion is not part of the same digestforum.');
    }

    $neighbours = ['prev' => false, 'next' => false];
    $now = floor(time() / 60) * 60;
    $params = [];

    $modcontext = context_module::instance($cm->id);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    // Users must fulfill timed posts.
    $timelimit = '';
    if (!empty($CFG->digestforum_enabletimedposts)) {
        if (!has_capability('mod/digestforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = ' AND ((d.timestart <= :tltimestart AND (d.timeend = 0 OR d.timeend > :tltimeend))';
            $params['tltimestart'] = $now;
            $params['tltimeend'] = $now;
            if (isloggedin()) {
                $timelimit .= ' OR d.userid = :tluserid';
                $params['tluserid'] = $USER->id;
            }
            $timelimit .= ')';
        }
    }

    // Limiting to posts accessible according to groups.
    $groupselect = '';
    if ($groupmode) {
        if ($groupmode == VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = 'AND (d.groupid = :groupid OR d.groupid = -1)';
                $params['groupid'] = $currentgroup;
            }
        } else {
            if ($currentgroup) {
                $groupselect = 'AND (d.groupid = :groupid OR d.groupid = -1)';
                $params['groupid'] = $currentgroup;
            } else {
                $groupselect = 'AND d.groupid = -1';
            }
        }
    }

    $params['digestforumid'] = $cm->instance;
    $params['discid1'] = $discussion->id;
    $params['discid2'] = $discussion->id;
    $params['discid3'] = $discussion->id;
    $params['discid4'] = $discussion->id;
    $params['disctimecompare1'] = $discussion->timemodified;
    $params['disctimecompare2'] = $discussion->timemodified;
    $params['pinnedstate1'] = (int) $discussion->pinned;
    $params['pinnedstate2'] = (int) $discussion->pinned;
    $params['pinnedstate3'] = (int) $discussion->pinned;
    $params['pinnedstate4'] = (int) $discussion->pinned;

    $sql = "SELECT d.id, d.name, d.timemodified, d.groupid, d.timestart, d.timeend
              FROM {digestforum_discussions} d
              JOIN {digestforum_posts} p ON d.firstpost = p.id
             WHERE d.digestforum = :digestforumid
               AND d.id <> :discid1
                   $timelimit
                   $groupselect";
    $comparefield = "d.timemodified";
    $comparevalue = ":disctimecompare1";
    $comparevalue2  = ":disctimecompare2";
    if (!empty($CFG->digestforum_enabletimedposts)) {
        // Here we need to take into account the release time (timestart)
        // if one is set, of the neighbouring posts and compare it to the
        // timestart or timemodified of *this* post depending on if the
        // release date of this post is in the future or not.
        // This stops discussions that appear later because of the
        // timestart value from being buried under discussions that were
        // made afterwards.
        $comparefield = "CASE WHEN d.timemodified < d.timestart
                                THEN d.timestart ELSE d.timemodified END";
        if ($discussion->timemodified < $discussion->timestart) {
            // Normally we would just use the timemodified for sorting
            // discussion posts. However, when timed discussions are enabled,
            // then posts need to be sorted base on the later of timemodified
            // or the release date of the post (timestart).
            $params['disctimecompare1'] = $discussion->timestart;
            $params['disctimecompare2'] = $discussion->timestart;
        }
    }
    $orderbydesc = digestforum_get_default_sort_order(true, $comparefield, 'd', false);
    $orderbyasc = digestforum_get_default_sort_order(false, $comparefield, 'd', false);

    if ($digestforum->type === 'blog') {
         $subselect = "SELECT pp.created
                   FROM {digestforum_discussions} dd
                   JOIN {digestforum_posts} pp ON dd.firstpost = pp.id ";

         $subselectwhere1 = " WHERE dd.id = :discid3";
         $subselectwhere2 = " WHERE dd.id = :discid4";

         $comparefield = "p.created";

         $sub1 = $subselect . $subselectwhere1;
         $comparevalue = "($sub1)";

         $sub2 = $subselect . $subselectwhere2;
         $comparevalue2 = "($sub2)";

         $orderbydesc = "d.pinned, p.created DESC";
         $orderbyasc = "d.pinned, p.created ASC";
    }

    $prevsql = $sql . " AND ( (($comparefield < $comparevalue) AND :pinnedstate1 = d.pinned)
                         OR ($comparefield = $comparevalue2 AND (d.pinned = 0 OR d.pinned = :pinnedstate4) AND d.id < :discid2)
                         OR (d.pinned = 0 AND d.pinned <> :pinnedstate2))
                   ORDER BY CASE WHEN d.pinned = :pinnedstate3 THEN 1 ELSE 0 END DESC, $orderbydesc, d.id DESC";

    $nextsql = $sql . " AND ( (($comparefield > $comparevalue) AND :pinnedstate1 = d.pinned)
                         OR ($comparefield = $comparevalue2 AND (d.pinned = 1 OR d.pinned = :pinnedstate4) AND d.id > :discid2)
                         OR (d.pinned = 1 AND d.pinned <> :pinnedstate2))
                   ORDER BY CASE WHEN d.pinned = :pinnedstate3 THEN 1 ELSE 0 END DESC, $orderbyasc, d.id ASC";

    $neighbours['prev'] = $DB->get_record_sql($prevsql, $params, IGNORE_MULTIPLE);
    $neighbours['next'] = $DB->get_record_sql($nextsql, $params, IGNORE_MULTIPLE);
    return $neighbours;
}

/**
 * Get the sql to use in the ORDER BY clause for digestforum discussions.
 *
 * This has the ordering take timed discussion windows into account.
 *
 * @param bool $desc True for DESC, False for ASC.
 * @param string $compare The field in the SQL to compare to normally sort by.
 * @param string $prefix The prefix being used for the discussion table.
 * @param bool $pinned sort pinned posts to the top
 * @return string
 */
function digestforum_get_default_sort_order($desc = true, $compare = 'd.timemodified', $prefix = 'd', $pinned = true) {
    global $CFG;

    if (!empty($prefix)) {
        $prefix .= '.';
    }

    $dir = $desc ? 'DESC' : 'ASC';

    if ($pinned == true) {
        $pinned = "{$prefix}pinned DESC,";
    } else {
        $pinned = '';
    }

    $sort = "{$prefix}timemodified";
    if (!empty($CFG->digestforum_enabletimedposts)) {
        $sort = "CASE WHEN {$compare} < {$prefix}timestart
                 THEN {$prefix}timestart
                 ELSE {$compare}
                 END";
    }
    return "$pinned $sort $dir";
}

/**
 *
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function digestforum_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $now = floor(time() / 60) * 60;
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * 24 * 60 * 60);

    $params = [];
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "";
            }
        } else {
            // Separate groups without access all.
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < :now1 AND (d.timeend = 0 OR d.timeend > :now2)";
        $params['now1'] = $now;
        $params['now2'] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT d.id, COUNT(p.id) AS unread
              FROM {digestforum_discussions} d
                   JOIN {digestforum_posts} p     ON p.discussion = d.id
                   LEFT JOIN {digestforum_read} r ON (r.postid = p.id AND r.userid = $USER->id)
             WHERE d.digestforum = {$cm->instance}
                   AND p.modified >= :cutoffdate AND r.id is NULL
                   $groupselect
                   $timedsql
          GROUP BY d.id";
    $params['cutoffdate'] = $cutoffdate;

    if ($unreads = $DB->get_records_sql($sql, $params)) {
        foreach ($unreads as $unread) {
            $unreads[$unread->id] = $unread->unread;
        }
        return $unreads;
    } else {
        return [];
    }
}

/**
 * Count the discussions in a digestforum that the current user can see, taking groups and timed posts into account.
 *
 * @param object $cm
 * @return array
 */
function digestforum_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $now = floor(time() / 60) * 60;
    $params = [$cm->instance];
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }
        } else {
            // Seprate groups without access all.
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $timelimit = "";

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $modcontext = context_module::instance($cm->id);

        if (!has_capability('mod/digestforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    $sql = "SELECT COUNT(d.id)
              FROM {digestforum_discussions} d
                   JOIN {digestforum_posts} p ON p.discussion = d.id
             WHERE d.digestforum = ? AND p.parent = 0
                   $groupselect $timelimit";

    return $DB->get_field_sql($sql, $params);
}


// Other functions.


/**
 * Get the special digestforum of the given type for a course (such as the news forum), creating it if needed.
 *
 * @param int $courseid
 * @param string $type
 */
function digestforum_get_course_digestforum($courseid, $type) {
    // How to set up special 1-per-course digestforums.
    global $CFG, $DB, $OUTPUT, $USER;

    if ($digestforums = $DB->get_records_select("digestforum", "course = ? AND type = ?", [$courseid, $type], "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($digestforums as $digestforum) {
            return $digestforum;   // Ie the first one.
        }
    }

    // Doesn't exist, so create one now.
    $digestforum = new stdClass();
    $digestforum->course = $courseid;
    $digestforum->type = "$type";
    if (!empty($USER->htmleditor)) {
        $digestforum->introformat = $USER->htmleditor;
    }
    switch ($digestforum->type) {
        case "news":
            $digestforum->name  = get_string("namenews", "digestforum");
            $digestforum->intro = get_string("intronews", "digestforum");
            $digestforum->introformat = FORMAT_HTML;
            $digestforum->forcesubscribe = $CFG->digestforum_announcementsubscription;
            $digestforum->maxattachments = $CFG->digestforum_announcementmaxattachments;
            $digestforum->maxbytes = $CFG->digestforum_announcementmaxbytes;
            $digestforum->assessed = 0;
            if ($courseid == SITEID) {
                $digestforum->name  = get_string("sitenews");
                $digestforum->forcesubscribe = 0;
            }
            break;
        case "social":
            $digestforum->name  = get_string("namesocial", "digestforum");
            $digestforum->intro = get_string("introsocial", "digestforum");
            $digestforum->introformat = FORMAT_HTML;
            $digestforum->assessed = 0;
            $digestforum->forcesubscribe = 0;
            break;
        case "blog":
            $digestforum->name = get_string('blogdigestforum', 'digestforum');
            $digestforum->intro = get_string('introblog', 'digestforum');
            $digestforum->introformat = FORMAT_HTML;
            $digestforum->assessed = 0;
            $digestforum->forcesubscribe = 0;
            break;
        default:
            echo $OUTPUT->notification("That digestforum type doesn't exist!");
            return false;
            break;
    }

    $digestforum->timemodified = time();
    $digestforum->id = $DB->insert_record("digestforum", $digestforum);

    if (! $module = $DB->get_record("modules", ["name" => "digestforum"])) {
        echo $OUTPUT->notification("Could not find digestforum module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $digestforum->id;
    $mod->section = 0;
    include_once("$CFG->dirroot/course/lib.php");
    if (! $mod->coursemodule = add_course_module($mod)) {
        echo $OUTPUT->notification("Could not add a new course module to the course '" . $courseid . "'");
        return false;
    }
    $sectionid = course_add_cm_to_section($courseid, $mod->coursemodule, 0, null, 'digestforum');
    return $DB->get_record("digestforum", ["id" => "$digestforum->id"]);
}

/**
 * Return rating related permissions
 *
 * @param int $contextid the context id
 * @param string $component the component
 * @param string $ratingarea the rating area
 * @return array an associative array of the user's rating permissions
 */
function digestforum_rating_permissions($contextid, $component, $ratingarea) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_digestforum' || $ratingarea != 'post') {
        // We don't know about this component/ratingarea so just return null to get the
        // default restrictive permissions.
        return null;
    }
    return [
        'view'    => has_capability('mod/digestforum:viewrating', $context),
        'viewany' => has_capability('mod/digestforum:viewanyrating', $context),
        'viewall' => has_capability('mod/digestforum:viewallratings', $context),
        'rate'    => has_capability('mod/digestforum:rate', $context),
    ];
}

/**
 * Validates a submitted rating
 * @param array $params submitted data
 *            context => object the context in which the rated items exists [required]
 *            component => The component for this module - should always be mod_digestforum [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int the scale from which the user can select a rating. Used for bounds checking. [required]
 *            rating => int the submitted rating [required]
 *            rateduserid => int the id of the user whose items have been rated. NOT the user who submitted the ratings.
 *                           0 to update all. [required]
 *            aggregation => int the aggregation method to apply when calculating grades ie RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function digestforum_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_digestforum.
    if ($params['component'] != 'mod_digestforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in digestforum).
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts.
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call digestforum_user_can_see_post.
    $post = $DB->get_record('digestforum_posts', ['id' => $params['itemid'], 'userid' => $params['rateduserid']], '*', MUST_EXIST);
    $discussion = $DB->get_record('digestforum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
    $digestforum = $DB->get_record('digestforum', ['id' => $discussion->digestforum], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $digestforum->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the digestforum.
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($digestforum->scale != $params['scaleid']) {
        // The scale being submitted doesnt match the one in the database.
        throw new rating_exception('invalidscaleid');
    }

    // Check the item we're rating was created in the assessable time window.
    if (!empty($digestforum->assesstimestart) && !empty($digestforum->assesstimefinish)) {
        if ($post->created < $digestforum->assesstimestart || $post->created > $digestforum->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    // Check that the submitted rating is valid for the scale.

    // Lower limit.
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // Upper limit.
    if ($digestforum->scale < 0) {
        // Its a custom scale.
        $scalerecord = $DB->get_record('scale', ['id' => -$digestforum->scale]);
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $digestforum->scale) {
        // If its numeric and submitted rating is above maximum.
        throw new rating_exception('invalidnum');
    }

    // Make sure groups allow this user to see the item they're rating.
    if ($discussion->groupid > 0 && $groupmode = groups_get_activity_groupmode($cm, $course)) {
        // Groups are being used.
        if (!groups_group_exists($discussion->groupid)) {
            // Can't find group.
            throw new rating_exception('cannotfindgroup'); // Something is wrong.
        }

        if (!groups_is_member($discussion->groupid) && !has_capability('moodle/site:accessallgroups', $context)) {
            // Do not allow rating of posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS.
            throw new rating_exception('notmemberofgroup');
        }
    }

    // Perform some final capability checks.
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, $USER, $cm)) {
        throw new rating_exception('nopermissiontorate');
    }

    return true;
}

/**
 * Can the current user see ratings for a given itemid?
 *
 * @param array $params submitted data
 *            contextid => int contextid [required]
 *            component => The component for this module - should always be mod_digestforum [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int scale id [optional]
 * @return bool
 * @throws coding_exception
 * @throws rating_exception
 */
function mod_digestforum_rating_can_see_item_ratings($params) {
    global $DB, $USER;

    // Check the component is mod_digestforum.
    if (!isset($params['component']) || $params['component'] != 'mod_digestforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in digestforum).
    if (!isset($params['ratingarea']) || $params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    if (!isset($params['itemid'])) {
        throw new rating_exception('invaliditemid');
    }

    $post = $DB->get_record('digestforum_posts', ['id' => $params['itemid']], '*', MUST_EXIST);
    $discussion = $DB->get_record('digestforum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
    $digestforum = $DB->get_record('digestforum', ['id' => $discussion->digestforum], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $digestforum->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    $ratingpermissions = digestforum_rating_permissions($context->id, 'mod_digestforum', 'post');
    $requiredpermission = ($post->userid != $USER->id) ? 'viewall' : 'view';
    if (!$ratingpermissions[$requiredpermission]) {
        return false;
    }

    // Perform some final capability checks.
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, $USER, $cm)) {
        return false;
    }

    return true;
}

/**
 * Return the markup for the discussion subscription toggling icon.
 *
 * @param stdClass $digestforum The digestforum object.
 * @param int $discussionid The discussion to create an icon for.
 * @param moodle_url|null $returnurl The URL to return to after changing the subscription.
 * @param bool $includetext Whether to include the text with the icon.
 * @return string The generated markup.
 */
function digestforum_get_discussion_subscription_icon($digestforum, $discussionid, $returnurl = null, $includetext = false) {
    global $USER, $OUTPUT, $PAGE;

    if ($returnurl === null && $PAGE->url) {
        $returnurl = $PAGE->url->out();
    }

    $o = '';
    $subscriptionstatus = \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum, $discussionid);
    $subscriptionlink = new moodle_url('/mod/digestforum/subscribe.php', [
        'sesskey' => sesskey(),
        'id' => $digestforum->id,
        'd' => $discussionid,
        'returnurl' => $returnurl,
    ]);

    if ($includetext) {
        $o .= $subscriptionstatus ? get_string('subscribed', 'mod_digestforum') : get_string('notsubscribed', 'mod_digestforum');
    }

    if ($subscriptionstatus) {
        $output = $OUTPUT->pix_icon('t/subscribed', get_string('clicktounsubscribe', 'digestforum'), 'mod_digestforum');
        if ($includetext) {
            $output .= get_string('subscribed', 'mod_digestforum');
        }

        return html_writer::link($subscriptionlink, $output, [
                'title' => get_string('clicktounsubscribe', 'digestforum'),
                'class' => 'discussiontoggle btn btn-link',
                'data-digestforumid' => $digestforum->id,
                'data-discussionid' => $discussionid,
                'data-includetext' => $includetext,
            ]);
    } else {
        $output = $OUTPUT->pix_icon('t/unsubscribed', get_string('clicktosubscribe', 'digestforum'), 'mod_digestforum');
        if ($includetext) {
            $output .= get_string('notsubscribed', 'mod_digestforum');
        }

        return html_writer::link($subscriptionlink, $output, [
                'title' => get_string('clicktosubscribe', 'digestforum'),
                'class' => 'discussiontoggle btn btn-link',
                'data-digestforumid' => $digestforum->id,
                'data-discussionid' => $discussionid,
                'data-includetext' => $includetext,
            ]);
    }
}

/**
 * Return a pair of spans containing classes to allow the subscribe and
 * unsubscribe icons to be pre-loaded by a browser.
 *
 * @return string The generated markup
 */
function digestforum_get_discussion_subscription_icon_preloaders() {
    $o = '';
    $o .= html_writer::span('&nbsp;', 'preload-subscribe');
    $o .= html_writer::span('&nbsp;', 'preload-unsubscribe');
    return $o;
}

/**
 * Print the drop down that allows the user to select how they want to have
 * the discussion displayed.
 *
 * @param int $id digestforum id if $digestforumtype is 'single',
 *              discussion id for any other digestforum type
 * @param mixed $mode digestforum layout mode
 * @param string $digestforumtype optional
 */
function digestforum_print_mode_form($id, $mode, $digestforumtype = '') {
    global $OUTPUT;
    $useexperimentalui = get_user_preferences('digestforum_useexperimentalui', false);
    if ($digestforumtype == 'single') {
        $select = new single_select(
            new moodle_url(
                "/mod/digestforum/view.php",
                ['f' => $id]
            ),
            'mode',
            digestforum_get_layout_modes($useexperimentalui),
            $mode,
            null,
            "mode"
        );
        $select->set_label(get_string('displaymode', 'digestforum'), ['class' => 'accesshide']);
        $select->class = "digestforummode";
    } else {
        $select = new single_select(
            new moodle_url(
                "/mod/digestforum/discuss.php",
                ['d' => $id]
            ),
            'mode',
            digestforum_get_layout_modes($useexperimentalui),
            $mode,
            null,
            "mode"
        );
        $select->set_label(get_string('displaymode', 'digestforum'), ['class' => 'accesshide']);
    }
    echo $OUTPUT->render($select);
}

/**
 * Render the quick search form for a course.
 *
 * @param object $course
 * @param string $search
 * @return string
 */
function digestforum_search_form($course, $search = '') {
    global $CFG, $PAGE;
    $digestforumsearch = new \mod_digestforum\output\quick_search_form($course->id, $search);
    $output = $PAGE->get_renderer('mod_digestforum');
    return $output->render($digestforumsearch);
}

/**
 * Retrieve HTML for the page action
 *
 * @param digestforum_entity|null $digestforum The digestforum entity.
 * @param mixed $groupid false if groups not used, int if groups used, 0 means all groups
 * @param stdClass $course The course object.
 * @param string $search The search string.
 * @return string rendered HTML string.
 */
function digestforum_activity_actionbar(?digestforum_entity $digestforum, $groupid, stdClass $course, string $search = ''): string {
    global $PAGE;

    $actionbar = new mod_digestforum\output\digestforum_actionbar($digestforum, $course, $groupid, $search);
    $output = $PAGE->get_renderer('mod_digestforum');
    return $output->render($actionbar);
}

/**
 * Remember the page the user came from, so that they can be sent back to it later.
 */
function digestforum_set_return() {
    global $CFG, $SESSION;

    if (! isset($SESSION->fromdiscussion)) {
        $referer = get_local_referer(false);
        // If the referer is NOT a login screen then save it.
        if (! strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $referer;
        }
    }
}


/**
 * Get the page to send the user back to, as remembered by digestforum_set_return().
 *
 * @param string|\moodle_url $default
 * @return string
 */
function digestforum_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);
        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Given a discussion object that is being moved to $digestforumto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new digestforum directory.
 *
 * @param object $discussion
 * @param int $digestforumfrom source digestforum id
 * @param int $digestforumto target digestforum id
 * @return bool success
 */
function digestforum_move_attachments($discussion, $digestforumfrom, $digestforumto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('digestforum', $digestforumto);
    $oldcm = get_coursemodule_from_instance('digestforum', $digestforumfrom);

    $newcontext = context_module::instance($newcm->id);
    $oldcontext = context_module::instance($oldcm->id);

    // Loop through all posts, better not use attachment flag ;-).
    if ($posts = $DB->get_records('digestforum_posts', ['discussion' => $discussion->id], '', 'id, attachment')) {
        foreach ($posts as $post) {
            $fs->move_area_files_to_new_context(
                $oldcontext->id,
                $newcontext->id,
                'mod_digestforum',
                'post',
                $post->id
            );
            $attachmentsmoved = $fs->move_area_files_to_new_context(
                $oldcontext->id,
                $newcontext->id,
                'mod_digestforum',
                'attachment',
                $post->id
            );
            if ($attachmentsmoved > 0 && $post->attachment != '1') {
                // Weird - let's fix it.
                $post->attachment = '1';
                $DB->update_record('digestforum_posts', $post);
            } else if ($attachmentsmoved == 0 && $post->attachment != '') {
                // Weird - let's fix it.
                $post->attachment = '';
                $DB->update_record('digestforum_posts', $post);
            }
        }
    }

    return true;
}

/**
 * Returns attachments as formated text/html optionally with separate images
 *
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function digestforum_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : ['', ''];
    }

    if (!in_array($type, ['separateimages', 'html', 'text'])) {
        return $type !== 'separateimages' ? '' : ['', ''];
    }

    if (!$context = context_module::instance($cm->id)) {
        return $type !== 'separateimages' ? '' : ['', ''];
    }
    $strattachment = get_string('attachment', 'digestforum');

    $fs = get_file_storage();

    $imagereturn = '';
    $output = '';

    $canexport = !empty($CFG->enableportfolios) && (has_capability(
        'mod/digestforum:exportpost',
        $context,
    ) || ($post->userid == $USER->id && has_capability('mod/digestforum:exportownpost', $context)));

    if ($canexport) {
        require_once($CFG->libdir . '/portfoliolib.php');
    }

    // We retrieve all files according to the time that they were created.  In the case that several files were uploaded
    // at the sametime (e.g. in the case of drag/drop upload) we revert to using the filename.
    $files = $fs->get_area_files($context->id, 'mod_digestforum', 'attachment', $post->id, "filename", false);
    if ($files) {
        if ($canexport) {
            $button = new portfolio_add_button();
        }
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            $iconimage = $OUTPUT->pix_icon(
                file_file_icon($file),
                get_mimetype_description($file),
                'moodle',
                [
                            'class' => 'icon',
                            'style' => 'max-width: 24px; max-height: 24px; vertical-align: middle;',
                    ]
            );
            $path = url::make_pluginfile_url(
                contextid: $context->id,
                component: 'mod_digestforum',
                area: 'attachment',
                itemid: $post->id,
                pathname: '/',
                filename: $filename
            )->out();

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">" . s($filename) . "</a>";
                if ($canexport) {
                    $button->set_callback_options(
                        'digestforum_portfolio_caller',
                        ['postid' => $post->id, 'attachment' => $file->get_id()],
                        'mod_digestforum',
                    );
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= "<br />";
            } else if ($type == 'text') {
                $output .= "$strattachment " . s($filename) . ":\n$path\n";
            } else {
                // Type is 'returnimages'.
                if (in_array($mimetype, ['image/gif', 'image/jpeg', 'image/png'])) {
                    // Image attachments don't get printed as links.
                    $imagereturn .= "<br /><img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options(
                            'digestforum_portfolio_caller',
                            ['postid' => $post->id, 'attachment' => $file->get_id()],
                            'mod_digestforum',
                        );
                        $button->set_format_by_file($file);
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= format_text("<a href=\"$path\">" . s($filename) . "</a>", FORMAT_HTML, ['context' => $context]);
                    if ($canexport) {
                        $button->set_callback_options(
                            'digestforum_portfolio_caller',
                            ['postid' => $post->id, 'attachment' => $file->get_id()],
                            'mod_digestforum',
                        );
                        $button->set_format_by_file($file);
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                    $output .= '<br />';
                }
            }

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir . '/plagiarismlib.php');
                $output .= plagiarism_get_links(['userid' => $post->userid,
                    'file' => $file,
                    'cmid' => $cm->id,
                    'course' => $cm->course,
                    'digestforum' => $cm->instance]);
                $output .= '<br />';
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;
    } else {
        return [$output, $imagereturn];
    }
}

// File API.

/**
 * Lists all browsable file areas
 *
 * @package  mod_digestforum
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @return array
 */
function digestforum_get_file_areas($course, $cm, $context) {
    return [
        'attachment' => get_string('areaattachment', 'mod_digestforum'),
        'post' => get_string('areapost', 'mod_digestforum'),
    ];
}

/**
 * File browsing support for digestforum module.
 *
 * @package  mod_digestforum
 * @category files
 * @param stdClass $browser file browser object
 * @param stdClass $areas file areas
 * @param stdClass $course course object
 * @param stdClass $cm course module
 * @param stdClass $context context module
 * @param string $filearea file area
 * @param int $itemid item ID
 * @param string $filepath file path
 * @param string $filename file name
 * @return file_info instance or null if not found
 */
function digestforum_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return null;
    }

    // Filearea must contain a real area.
    if (!isset($areas[$filearea])) {
        return null;
    }

    // Note that digestforum_user_can_see_post() additionally allows access for parent roles
    // and it explicitly checks qanda digestforum type, too. One day, when we stop requiring
    // course:managefiles, we will need to extend this.
    if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
        return null;
    }

    if (is_null($itemid)) {
        require_once($CFG->dirroot . '/mod/digestforum/locallib.php');
        return new digestforum_file_info_container($browser, $course, $cm, $context, $areas, $filearea);
    }

    static $cached = [];
    // The $cached variable stores the last retrieved post, discussion and digestforum. To make sure that the cache
    // is cleared between unit tests, we check if this is the same session.
    if (!isset($cached['sesskey']) || $cached['sesskey'] != sesskey()) {
        $cached = ['sesskey' => sesskey()];
    }

    if (isset($cached['post']) && $cached['post']->id == $itemid) {
        $post = $cached['post'];
    } else if ($post = $DB->get_record('digestforum_posts', ['id' => $itemid])) {
        $cached['post'] = $post;
    } else {
        return null;
    }

    if (isset($cached['discussion']) && $cached['discussion']->id == $post->discussion) {
        $discussion = $cached['discussion'];
    } else if ($discussion = $DB->get_record('digestforum_discussions', ['id' => $post->discussion])) {
        $cached['discussion'] = $discussion;
    } else {
        return null;
    }

    if (isset($cached['digestforum']) && $cached['digestforum']->id == $cm->instance) {
        $digestforum = $cached['digestforum'];
    } else if ($digestforum = $DB->get_record('digestforum', ['id' => $cm->instance])) {
        $cached['digestforum'] = $digestforum;
    } else {
        return null;
    }

    $fs = get_file_storage();
    $filepath = is_null($filepath) ? '/' : $filepath;
    $filename = is_null($filename) ? '.' : $filename;
    if (!($storedfile = $fs->get_file($context->id, 'mod_digestforum', $filearea, $itemid, $filepath, $filename))) {
        return null;
    }

    // Checks to see if the user can manage files or is the owner.
    // TODO MDL-33805 - Do not use userid here and move the capability check above.
    if (!has_capability('moodle/course:managefiles', $context) && $storedfile->get_userid() != $USER->id) {
        return null;
    }
    // Make sure groups allow this user to see this file.
    if ($discussion->groupid > 0 && !has_capability('moodle/site:accessallgroups', $context)) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS && !groups_is_member($discussion->groupid)) {
            return null;
        }
    }

    // Make sure we're allowed to see it...
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
        return null;
    }

    $urlbase = $CFG->wwwroot . '/pluginfile.php';
    return new file_info_stored($browser, $context, $storedfile, $urlbase, $itemid, true, true, false, false);
}

/**
 * Serves the digestforum attachments. Implements needed access control ;-)
 *
 * @package  mod_digestforum
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function digestforum_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $areas = digestforum_get_file_areas($course, $cm, $context);

    // Filearea must contain a real area.
    if (!isset($areas[$filearea])) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('digestforum_posts', ['id' => $postid])) {
        return false;
    }

    if (!$discussion = $DB->get_record('digestforum_discussions', ['id' => $post->discussion])) {
        return false;
    }

    if (!$digestforum = $DB->get_record('digestforum', ['id' => $cm->instance])) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_digestforum/$filearea/$postid/$relativepath";
    if (!($file = $fs->get_file_by_hash(sha1($fullpath))) || $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file.
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS) {
            if (!groups_is_member($discussion->groupid) && !has_capability('moodle/site:accessallgroups', $context)) {
                return false;
            }
        }
    }

    // Make sure we're allowed to see it...
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
        return false;
    }

    // Finally send the file.
    send_stored_file($file, 0, 0, true, $options); // Download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @param object $post is a full post record, including course and digestforum
 * @param object $digestforum
 * @param object $cm
 * @param mixed $mform
 * @param string $unused
 * @return bool
 */
function digestforum_add_attachment($post, $digestforum, $cm, $mform = null, $unused = null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do.
    }

    $context = context_module::instance($cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount'] > 0) ? '1' : '';
    file_save_draft_area_files(
        $post->attachments,
        $context->id,
        'mod_digestforum',
        'attachment',
        $post->id,
        mod_digestforum_post_form::attachment_options($digestforum)
    );

    $DB->set_field('digestforum_posts', 'attachment', $present, ['id' => $post->id]);

    return true;
}

/**
 * Add a new post in an existing discussion.
 *
 * @param   stdClass    $post       The post data
 * @param   mixed       $mform      The submitted form
 * @param   string      $unused
 * @return int
 */
function digestforum_add_new_post($post, $mform, $unused = null) {
    global $USER, $DB;

    $discussion = $DB->get_record('digestforum_discussions', ['id' => $post->discussion]);
    $digestforum      = $DB->get_record('digestforum', ['id' => $discussion->digestforum]);
    $cm         = get_coursemodule_from_instance('digestforum', $digestforum->id);
    $context    = context_module::instance($cm->id);
    $privatereplyto = 0;

    // Check whether private replies should be enabled for this post.
    if ($post->parent) {
        $parent = $DB->get_record('digestforum_posts', ['id' => $post->parent]);

        if (!empty($parent->privatereplyto)) {
            throw new \coding_exception('It should not be possible to reply to a private reply');
        }

        if (!empty($post->isprivatereply) && digestforum_user_can_reply_privately($context, $parent)) {
            $privatereplyto = $parent->userid;
        }
    }

    $post->created    = $post->modified = time();
    $post->mailed     = DFORUM_MAILED_PENDING;
    $post->userid     = $USER->id;
    $post->privatereplyto = $privatereplyto;
    $post->attachment = "";
    if (!isset($post->totalscore)) {
        $post->totalscore = 0;
    }
    if (!isset($post->mailnow)) {
        $post->mailnow    = 0;
    }

    \mod_digestforum\local\entities\post::add_message_counts($post);
    $post->id = $DB->insert_record("digestforum_posts", $post);
    $post->message = file_save_draft_area_files(
        $post->itemid,
        $context->id,
        'mod_digestforum',
        'post',
        $post->id,
        mod_digestforum_post_form::editor_options($context, null),
        $post->message
    );
    $DB->set_field('digestforum_posts', 'message', $post->message, ['id' => $post->id]);
    digestforum_add_attachment($post, $digestforum, $cm, $mform);

    // Update discussion modified date.
    $DB->set_field("digestforum_discussions", "timemodified", $post->modified, ["id" => $post->discussion]);
    $DB->set_field("digestforum_discussions", "usermodified", $post->userid, ["id" => $post->discussion]);

    if (digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
        digestforum_tp_mark_post_read($post->userid, $post);
    }

    if (isset($post->tags)) {
        core_tag_tag::set_item_tags('mod_digestforum', 'digestforum_posts', $post->id, $context, $post->tags);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection).
    digestforum_trigger_content_uploaded_event($post, $cm, 'digestforum_add_new_post');

    return $post->id;
}

/**
 * Trigger post updated event.
 *
 * @param object $post digestforum post object
 * @param object $discussion discussion object
 * @param object $context digestforum context object
 * @param object $digestforum digestforum object
 * @since Moodle 3.8
 * @return void
 */
function digestforum_trigger_post_updated_event($post, $discussion, $context, $digestforum) {
    global $USER;

    $params = [
        'context' => $context,
        'objectid' => $post->id,
        'other' => [
            'discussionid' => $discussion->id,
            'digestforumid' => $digestforum->id,
            'digestforumtype' => $digestforum->type,
        ],
    ];

    if ($USER->id !== $post->userid) {
        $params['relateduserid'] = $post->userid;
    }

    $event = \mod_digestforum\event\post_updated::create($params);
    $event->add_record_snapshot('digestforum_discussions', $discussion);
    $event->trigger();
}

/**
 * Update a post.
 *
 * @param   stdClass    $newpost    The post to update
 * @param   mixed       $mform      The submitted form
 * @param   string      $unused
 * @return  bool
 */
function digestforum_update_post($newpost, $mform, $unused = null) {
    global $DB, $USER;

    $post       = $DB->get_record('digestforum_posts', ['id' => $newpost->id]);
    $discussion = $DB->get_record('digestforum_discussions', ['id' => $post->discussion]);
    $digestforum      = $DB->get_record('digestforum', ['id' => $discussion->digestforum]);
    $cm         = get_coursemodule_from_instance('digestforum', $digestforum->id);
    $context    = context_module::instance($cm->id);

    // Allowed modifiable fields.
    $modifiablefields = [
        'subject',
        'message',
        'messageformat',
        'messagetrust',
        'timestart',
        'timeend',
        'pinned',
        'attachments',
    ];
    foreach ($modifiablefields as $field) {
        if (isset($newpost->{$field})) {
            $post->{$field} = $newpost->{$field};
        }
    }
    $post->modified = time();

    if (!$post->parent) {
        // Post is a discussion starter - update discussion title and times too.
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;

        if (isset($post->pinned)) {
            $discussion->pinned = $post->pinned;
        }
    }
    $post->message = file_save_draft_area_files(
        $newpost->itemid,
        $context->id,
        'mod_digestforum',
        'post',
        $post->id,
        mod_digestforum_post_form::editor_options($context, $post->id),
        $post->message
    );
    \mod_digestforum\local\entities\post::add_message_counts($post);
    $DB->update_record('digestforum_posts', $post);
    // Note: Discussion modified time/user are intentionally not updated, to enable them to track the latest new post.
    $DB->update_record('digestforum_discussions', $discussion);

    digestforum_add_attachment($post, $digestforum, $cm, $mform);

    if ($digestforum->type == 'single' && $post->parent == '0') {
        // Updating first post of single discussion type -> updating digestforum intro.
        $digestforum->intro = $post->message;
        $digestforum->timemodified = time();
        $DB->update_record("digestforum", $digestforum);
    }

    if (isset($newpost->tags)) {
        core_tag_tag::set_item_tags('mod_digestforum', 'digestforum_posts', $post->id, $context, $newpost->tags);
    }

    if (digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
        digestforum_tp_mark_post_read($USER->id, $post);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection).
    digestforum_trigger_content_uploaded_event($post, $cm, 'digestforum_update_post');

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @param object $discussion
 * @param mixed $mform
 * @param string $unused
 * @param int $userid
 * @return object
 */
function digestforum_add_discussion($discussion, $mform = null, $unused = null, $userid = null) {
    global $USER, $CFG, $DB;

    $timenow = isset($discussion->timenow) ? $discussion->timenow : time();

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $digestforum = $DB->get_record('digestforum', ['id' => $discussion->digestforum]);
    $cm    = get_coursemodule_from_instance('digestforum', $digestforum->id);

    $post = new stdClass();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->privatereplyto = 0;
    $post->userid        = $userid;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = DFORUM_MAILED_PENDING;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->message;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = isset($discussion->attachments) ? $discussion->attachments : null;
    $post->digestforum         = $digestforum->id;     // Speedup.
    $post->course        = $digestforum->course; // Speedup.
    $post->mailnow       = $discussion->mailnow;

    \mod_digestforum\local\entities\post::add_message_counts($post);
    $post->id = $DB->insert_record("digestforum_posts", $post);

    // Ideally the calling code would always pass a $cm to this function.
    if (!empty($cm->id) && !empty($discussion->itemid)) {
        // In "single simple discussions" this may not exist yet.
        $context = context_module::instance($cm->id);
        $text = file_save_draft_area_files(
            $discussion->itemid,
            $context->id,
            'mod_digestforum',
            'post',
            $post->id,
            mod_digestforum_post_form::editor_options($context, null),
            $post->message
        );
        $DB->set_field('digestforum_posts', 'message', $text, ['id' => $post->id]);
    }

    // Now do the main entry for the discussion, linking to this first post.

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $userid;
    $discussion->assessed     = 0;

    $post->discussion = $DB->insert_record("digestforum_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("digestforum_posts", "discussion", $post->discussion, ["id" => $post->id]);

    if (!empty($cm->id)) {
        digestforum_add_attachment($post, $digestforum, $cm, $mform, $unused);
    }

    if (isset($discussion->tags)) {
        $tags = is_array($discussion->tags) ? $discussion->tags : explode(',', $discussion->tags);

        core_tag_tag::set_item_tags(
            'mod_digestforum',
            'digestforum_posts',
            $post->id,
            context_module::instance($cm->id),
            $tags
        );
    }

    if (digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
        digestforum_tp_mark_post_read($post->userid, $post);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection).
    if (!empty($cm->id)) {
        digestforum_trigger_content_uploaded_event($post, $cm, 'digestforum_add_discussion');
    }

    // Clear the discussion count cache just in case it's in the same request.
    \cache_helper::purge_by_event('changesindigestforumdiscussions');

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire digestforum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $digestforum Forum
 * @return bool
 */
function digestforum_delete_discussion($discussion, $fulldelete, $course, $cm, $digestforum) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("digestforum_posts", ["discussion" => $discussion->id])) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->digestforum  = $discussion->digestforum;
            if (!digestforum_delete_post($post, 'ignore', $course, $cm, $digestforum, $fulldelete)) {
                $result = false;
            }
        }
    }

    digestforum_tp_delete_read_records(-1, -1, $discussion->id);

    // Discussion subscriptions must be removed before discussions because of key constraints.
    $DB->delete_records('digestforum_discussion_subs', ['discussion' => $discussion->id]);
    if (!$DB->delete_records("digestforum_discussions", ["id" => $discussion->id])) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing.
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if (
            $completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
            ($digestforum->completiondiscussions || $digestforum->completionreplies || $digestforum->completionposts)
        ) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    $params = [
        'objectid' => $discussion->id,
        'context' => context_module::instance($cm->id),
        'other' => [
            'digestforumid' => $digestforum->id,
        ],
    ];
    $event = \mod_digestforum\event\discussion_deleted::create($params);
    $event->add_record_snapshot('digestforum_discussions', $discussion);
    $event->trigger();

    // Clear the discussion count cache just in case it's in the same request.
    \cache_helper::purge_by_event('changesindigestforumdiscussions');

    return $result;
}


/**
 * Deletes a single digestforum post.
 *
 * @param object $post Forum post object
 * @param mixed $children Whether to delete children. If false, returns false
 *   if there are any children (without deleting the post). If true,
 *   recursively deletes all children. If set to special value 'ignore', deletes
 *   post regardless of children (this is for use only when deleting all posts
 *   in a disussion).
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $digestforum Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire digestforum anyway.
 * @return bool
 */
function digestforum_delete_post($post, $children, $course, $cm, $digestforum, $skipcompletion = false) {
    global $DB, $CFG, $USER;
    require_once($CFG->libdir . '/completionlib.php');

    $context = context_module::instance($cm->id);

    if ($children !== 'ignore' && ($childposts = $DB->get_records('digestforum_posts', ['parent' => $post->id]))) {
        if ($children) {
            foreach ($childposts as $childpost) {
                digestforum_delete_post($childpost, true, $course, $cm, $digestforum, $skipcompletion);
            }
        } else {
            return false;
        }
    }

    // Delete ratings.
    require_once($CFG->dirroot . '/rating/lib.php');
    $delopt = new stdClass();
    $delopt->contextid = $context->id;
    $delopt->component = 'mod_digestforum';
    $delopt->ratingarea = 'post';
    $delopt->itemid = $post->id;
    $rm = new rating_manager();
    $rm->delete_ratings($delopt);

    // Delete attachments.
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_digestforum', 'attachment', $post->id);
    $fs->delete_area_files($context->id, 'mod_digestforum', 'post', $post->id);

    // Delete cached RSS feeds.
    if (!empty($CFG->enablerssfeeds)) {
        require_once($CFG->dirroot . '/mod/digestforum/rsslib.php');
        digestforum_rss_delete_file($digestforum);
    }

    if ($DB->delete_records("digestforum_posts", ["id" => $post->id])) {
        digestforum_tp_delete_read_records(-1, $post->id);

        // Just in case we are deleting the last post.
        digestforum_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing.

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if (
                $completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
                ($digestforum->completiondiscussions || $digestforum->completionreplies || $digestforum->completionposts)
            ) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        $params = [
            'context' => $context,
            'objectid' => $post->id,
            'other' => [
                'discussionid' => $post->discussion,
                'digestforumid' => $digestforum->id,
                'digestforumtype' => $digestforum->type,
            ],
        ];
        $post->deleted = 1;
        if ($post->userid !== $USER->id) {
            $params['relateduserid'] = $post->userid;
        }
        $event = \mod_digestforum\event\post_deleted::create($params);
        $event->add_record_snapshot('digestforum_posts', $post);
        $event->trigger();

        return true;
    }
    return false;
}

/**
 * Sends post content to plagiarism plugin
 * @param object $post Forum post object
 * @param object $cm Course-module
 * @param string $name
 * @return bool
 */
function digestforum_trigger_content_uploaded_event($post, $cm, $name) {
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_digestforum', 'attachment', $post->id, "timemodified", false);
    $params = [
        'context' => $context,
        'objectid' => $post->id,
        'other' => [
            'content' => $post->message,
            'pathnamehashes' => array_keys($files),
            'discussionid' => $post->discussion,
            'triggeredfrom' => $name,
        ],
    ];
    $event = \mod_digestforum\event\assessable_uploaded::create($params);
    $event->trigger();
    return true;
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @param object $fromform The submitted form
 * @param stdClass $digestforum The digestforum record
 * @param stdClass $discussion The digestforum discussion record
 * @return string
 */
function digestforum_post_subscription($fromform, $digestforum, $discussion) {
    global $USER;

    if (\mod_digestforum\subscriptions::is_forcesubscribed($digestforum)) {
        return "";
    } else if (\mod_digestforum\subscriptions::subscription_disabled($digestforum)) {
        $subscribed = \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum);
        if (
            $subscribed && !has_capability(
                'moodle/course:manageactivities',
                context_course::instance($digestforum->course),
                $USER->id,
            )
        ) {
            // This user should not be subscribed to the digestforum.
            \mod_digestforum\subscriptions::unsubscribe_user($USER->id, $digestforum);
        }
        return "";
    }

    $info = new stdClass();
    $info->name  = fullname($USER);
    $info->discussion = format_string($discussion->name);
    $info->digestforum = format_string($digestforum->name);

    if (isset($fromform->discussionsubscribe) && $fromform->discussionsubscribe) {
        if ($result = \mod_digestforum\subscriptions::subscribe_user_to_discussion($USER->id, $discussion)) {
            return html_writer::tag('p', get_string('discussionnowsubscribed', 'digestforum', $info));
        }
    } else {
        if ($result = \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($USER->id, $discussion)) {
            return html_writer::tag('p', get_string('discussionnownotsubscribed', 'digestforum', $info));
        }
    }

    return '';
}

/**
 * Generate and return the subscribe or unsubscribe link for a digestforum.
 *
 * @param object $digestforum the digestforum. Fields used are $digestforum->id and $digestforum->forcesubscribe.
 * @param object $context the context object for this digestforum.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $unused1
 * @param bool $backtoindex
 * @param array $unused2
 * @return string
 */
function digestforum_get_subscribe_link(
    $digestforum,
    $context,
    $messages = [],
    $cantaccessagroup = false,
    $unused1 = true,
    $backtoindex = false,
    $unused2 = null
) {
    global $CFG, $USER, $PAGE, $OUTPUT;
    $defaultmessages = [
        'subscribed' => get_string('unsubscribe', 'digestforum'),
        'unsubscribed' => get_string('subscribe', 'digestforum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'digestforum'),
        'cantsubscribe' => get_string('disallowsubscribe', 'digestforum'),
    ];
    $messages = $messages + $defaultmessages;

    if (\mod_digestforum\subscriptions::is_forcesubscribed($digestforum)) {
        return $messages['forcesubscribed'];
    } else if (
        \mod_digestforum\subscriptions::subscription_disabled($digestforum) &&
            !has_capability('mod/digestforum:managesubscriptions', $context)
    ) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (!is_enrolled($context, $USER, '', true)) {
            return '';
        }

        $subscribed = \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum);
        if ($subscribed) {
            $linktext = $messages['subscribed'];
            $linktitle = get_string('subscribestop', 'digestforum');
        } else {
            $linktext = $messages['unsubscribed'];
            $linktitle = get_string('subscribestart', 'digestforum');
        }

        $options = [];
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }

        $options['id'] = $digestforum->id;
        $options['sesskey'] = sesskey();
        $url = new moodle_url('/mod/digestforum/subscribe.php', $options);
        return $OUTPUT->single_button($url, $linktext, 'get', ['title' => $linktitle]);
    }
}

/**
 * Returns true if user created new discussion already.
 *
 * @param int $digestforumid  The digestforum to check for postings
 * @param int $userid   The user to check for postings
 * @param int $groupid  The group to restrict the check to
 * @return bool
 */
function digestforum_user_has_posted_discussion($digestforumid, $userid, $groupid = null) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {digestforum_discussions} d, {digestforum_posts} p
             WHERE d.digestforum = ? AND p.discussion = d.id AND p.parent = 0 AND p.userid = ?";

    $params = [$digestforumid, $userid];

    if ($groupid) {
        $sql .= " AND d.groupid = ?";
        $params[] = $groupid;
    }

    return $DB->record_exists_sql($sql, $params);
}

/**
 * Get the discussions in a digestforum that a user has posted in.
 *
 * @param int $digestforumid
 * @param int $userid
 * @return array
 */
function digestforum_discussions_user_has_posted_in($digestforumid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT d.id AS id,
                            d.*
                       FROM {digestforum_posts} p,
                            {digestforum_discussions} d
                      WHERE p.discussion = d.id
                        AND d.digestforum = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, [$digestforumid, $userid]);
}

/**
 * Check whether a user has posted in a digestforum, or in one discussion of it.
 *
 * @param int $digestforumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function digestforum_user_has_posted($digestforumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // Posted in any digestforum discussion?
        $sql = "SELECT 'x'
                  FROM {digestforum_posts} p
                  JOIN {digestforum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.digestforum = :digestforumid";
        return $DB->record_exists_sql($sql, ['digestforumid' => $digestforumid, 'userid' => $userid]);
    } else {
        return $DB->record_exists('digestforum_posts', ['discussion' => $did, 'userid' => $userid]);
    }
}

/**
 * Returns true if user posted with mailnow in given discussion
 * @param int $did Discussion id
 * @param int $userid User id
 * @return bool
 */
function digestforum_get_user_posted_mailnow(int $did, int $userid): bool {
    global $DB;

    $postmailnow = $DB->get_field('digestforum_posts', 'MAX(mailnow)', ['userid' => $userid, 'discussion' => $did]);
    return !empty($postmailnow);
}

/**
 * Returns creation time of the first user's post in given discussion
 * @param int $did Discussion id
 * @param int $userid User id
 * @return int|bool post creation time stamp or return false
 */
function digestforum_get_user_posted_time($did, $userid) {
    global $DB;

    $posttime = $DB->get_field('digestforum_posts', 'MIN(created)', ['userid' => $userid, 'discussion' => $did]);
    if (empty($posttime)) {
        return false;
    }
    return $posttime;
}

/**
 * Check whether the current user can start a new discussion in a digestforum.
 *
 * @param object $digestforum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function digestforum_user_can_post_discussion($digestforum, $currentgroup = null, $unused = -1, $cm = null, $context = null) {
    global $USER;

    // Shortcut - guest and not-logged-in users can not post.
    if (isguestuser() || !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            throw new \moodle_exception('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    if (digestforum_is_cutoff_date_reached($digestforum)) {
        if (!has_capability('mod/digestforum:canoverridecutoff', $context)) {
            return false;
        }
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($digestforum->type == 'news') {
        $capname = 'mod/digestforum:addnews';
    } else if ($digestforum->type == 'qanda') {
        $capname = 'mod/digestforum:addquestion';
    } else {
        $capname = 'mod/digestforum:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($digestforum->type == 'single') {
        return false;
    }

    if ($digestforum->type == 'eachuser') {
        if (digestforum_user_has_posted_discussion($digestforum->id, $USER->id, $currentgroup)) {
            return false;
        }
    }

    if (!$groupmode || has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // No group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0.
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a digestforum
 * discussion. Use digestforum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $digestforum digestforum object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function digestforum_user_can_post($digestforum, $discussion, $user = null, $cm = null, $course = null, $context = null) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // Shortcut - guest and not-logged-in users can not post.
    if (isguestuser($user) || empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            throw new \moodle_exception('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', ['id' => $digestforum->course])) {
            throw new \moodle_exception('invalidcourseid');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    if (digestforum_is_cutoff_date_reached($digestforum)) {
        if (!has_capability('mod/digestforum:canoverridecutoff', $context)) {
            return false;
        }
    }

    // Check whether the discussion is locked.
    if (digestforum_discussion_is_locked($digestforum, $discussion)) {
        if (!has_capability('mod/digestforum:canoverridediscussionlock', $context)) {
            return false;
        }
    }

    // Normal users with temporary guest access can not post, suspended users can not post either.
    if (!is_viewing($context, $user->id) && !is_enrolled($context, $user->id, '', true)) {
        return false;
    }

    if ($digestforum->type == 'news') {
        $capname = 'mod/digestforum:replynews';
    } else {
        $capname = 'mod/digestforum:replypost';
    }

    if (!has_capability($capname, $context, $user->id)) {
        return false;
    }

    if (!$groupmode = groups_get_activity_groupmode($cm, $course)) {
        return true;
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($groupmode == VISIBLEGROUPS) {
        if ($discussion->groupid == -1) {
            // Allow students to reply to all participants discussions - this was not possible in Moodle <1.8.
            return true;
        }
        return groups_is_member($discussion->groupid);
    } else {
        // Separate groups.
        if ($discussion->groupid == -1) {
            return false;
        }
        return groups_is_member($discussion->groupid);
    }
}

/**
 * Check to ensure a user can view a timed discussion.
 *
 * @param object $discussion
 * @param object $user
 * @param object $context
 * @return boolean returns true if they can view post, false otherwise
 */
function digestforum_user_can_see_timed_discussion($discussion, $user, $context) {
    global $CFG;

    // Check that the user can view a discussion that is normally hidden due to access times.
    if (!empty($CFG->digestforum_enabletimedposts)) {
        $time = time();
        if (
            ($discussion->timestart != 0 && $discussion->timestart > $time)
            || ($discussion->timeend != 0 && $discussion->timeend < $time)
        ) {
            if (!has_capability('mod/digestforum:viewhiddentimedposts', $context, $user->id)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Check to ensure a user can view a group discussion.
 *
 * @param object $discussion
 * @param object $cm
 * @param object $context
 * @return boolean returns true if they can view post, false otherwise
 */
function digestforum_user_can_see_group_discussion($discussion, $cm, $context) {

    // If it's a grouped discussion, make sure the user is a member.
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS) {
            return groups_is_member($discussion->groupid) || has_capability('moodle/site:accessallgroups', $context);
        }
    }

    return true;
}

/**
 * Check whether a user can see a discussion.
 *
 * @param object $digestforum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function digestforum_user_can_see_discussion($digestforum, $discussion, $context, $user = null) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // Retrieve objects (yuk).
    if (is_numeric($digestforum)) {
        debugging('missing full digestforum', DEBUG_DEVELOPER);
        if (!$digestforum = $DB->get_record('digestforum', ['id' => $digestforum])) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('digestforum_discussions', ['id' => $discussion])) {
            return false;
        }
    }
    if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
        throw new \moodle_exception('invalidcoursemodule');
    }

    if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
        return false;
    }

    if (!digestforum_user_can_see_timed_discussion($discussion, $user, $context)) {
        return false;
    }

    if (!digestforum_user_can_see_group_discussion($discussion, $cm, $context)) {
        return false;
    }

    return true;
}

/**
 * Check whether a user can see the specified post.
 *
 * @param   \stdClass $digestforum The digestforum to chcek
 * @param   \stdClass $discussion The discussion the post is in
 * @param   \stdClass $post The post in question
 * @param   \stdClass $user The user to test - if not specified, the current user is checked.
 * @param   \stdClass $cm The Course Module that the digestforum is in (required).
 * @param   bool      $checkdeleted Whether to check the deleted flag on the post.
 * @return  bool
 */
function digestforum_user_can_see_post($digestforum, $discussion, $post, $user = null, $cm = null, $checkdeleted = true) {
    global $CFG, $USER, $DB;

    // Retrieve objects (yuk).
    if (is_numeric($digestforum)) {
        debugging('missing full digestforum', DEBUG_DEVELOPER);
        if (!$digestforum = $DB->get_record('digestforum', ['id' => $digestforum])) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('digestforum_discussions', ['id' => $discussion])) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('digestforum_posts', ['id' => $post])) {
            return false;
        }
    }

    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    if ($checkdeleted && !empty($post->deleted)) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            throw new \moodle_exception('invalidcoursemodule');
        }
    }

    // Context used throughout function.
    $modcontext = context_module::instance($cm->id);

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    $canviewdiscussion = (isset($cm->cache) && !empty($cm->cache->caps['mod/digestforum:viewdiscussion']))
        || has_capability('mod/digestforum:viewdiscussion', $modcontext, $user->id);
    if (
        !$canviewdiscussion && !has_all_capabilities(
            ['moodle/user:viewdetails', 'moodle/user:readuserposts'],
            context_user::instance($post->userid),
        )
    ) {
        return false;
    }

    if (!digestforum_post_is_visible_privately($post, $cm)) {
        return false;
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
        if (!\core_availability\info_module::is_user_visible($cm, $user->id, false)) {
            return false;
        }
    }

    if (!digestforum_user_can_see_timed_discussion($discussion, $user, $modcontext)) {
        return false;
    }

    if (!digestforum_user_can_see_group_discussion($discussion, $cm, $modcontext)) {
        return false;
    }

    if ($digestforum->type == 'qanda') {
        if (
            has_capability('mod/digestforum:viewqandawithoutposting', $modcontext, $user->id) || $post->userid == $user->id
                || (isset($discussion->firstpost) && $discussion->firstpost == $post->id)
        ) {
            return true;
        }
        $firstpost = digestforum_get_firstpost_from_discussion($discussion->id);
        if ($firstpost->userid == $user->id) {
            return true;
        }
        $userpostmailnow = digestforum_get_user_posted_mailnow($discussion->id, $user->id);
        if ($userpostmailnow) {
            return true;
        }
        $userfirstpost = digestforum_get_user_posted_time($discussion->id, $user->id);
        return ($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime || $digestforum->showimmediately));
    }
    return true;
}

/**
 * Returns all digestforum posts since a given time in specified digestforum.
 *
 * @param array $activities The list of activities, which posts are added to
 * @param int $index The index of the next activity in $activities
 * @param int $timestart Only include posts made since this time
 * @param int $courseid The course id
 * @param int $cmid The course module id of the digestforum
 * @param int $userid Only include posts by this user, or 0 for all users
 * @param int $groupid Only include posts in this group, or 0 for all groups
 */
function digestforum_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', ['id' => $courseid]);
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $params = [$timestart, $cm->instance];

    if ($userid) {
        $userselect = "AND u.id = ?";
        $params[] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND d.groupid = ?";
        $params[] = $groupid;
    } else {
        $groupselect = "";
    }

    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    // Order by initial posting date.
    if (
        !$posts = $DB->get_records_sql("SELECT p.*, f.type AS digestforumtype, d.digestforum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              $allnames, u.email, u.picture, u.imagealt, u.email
                                         FROM {digestforum_posts} p
                                              JOIN {digestforum_discussions} d ON d.id = p.discussion
                                              JOIN {digestforum} f             ON f.id = d.digestforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)
    ) {
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cmcontext      = context_module::instance($cm->id);
    $viewhiddentimed = has_capability('mod/digestforum:viewhiddentimedposts', $cmcontext);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cmcontext);

    $printposts = [];
    foreach ($posts as $post) {
        if (
            !empty($CFG->digestforum_enabletimedposts) && $USER->id != $post->duserid
            && (($post->timestart > 0 && $post->timestart > time()) || ($post->timeend > 0 && $post->timeend < time()))
        ) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            // Open discussions have groupid -1, and everyone can see them.
            if ($post->groupid != -1 && $groupmode != VISIBLEGROUPS && !$accessallgroups) {
                // Separate mode.
                if (isguestuser()) {
                    // Shortcut.
                    continue;
                }

                if (!in_array($post->groupid, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }

    if (!$printposts) {
        return;
    }

    $aname = format_string($cm->name, true);

    foreach ($printposts as $post) {
        $tmpactivity = new stdClass();

        $tmpactivity->type         = 'digestforum';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $post->modified;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->id         = $post->id;
        $tmpactivity->content->discussion = $post->discussion;
        $tmpactivity->content->subject    = format_string($post->subject);
        $tmpactivity->content->parent     = $post->parent;
        $tmpactivity->content->digestforumtype  = $post->digestforumtype;

        $tmpactivity->user = new stdClass();
        $additionalfields = ['id' => 'userid', 'picture', 'imagealt', 'email'];
        $additionalfields = explode(',', implode(',', \core_user\fields::get_picture_fields()));
        $tmpactivity->user = username_load_fields_from_object($tmpactivity->user, $post, null, $additionalfields);
        $tmpactivity->user->id = $post->userid;

        $activities[$index++] = $tmpactivity;
    }

    return;
}

/**
 * Outputs the digestforum post indicated by $activity.
 *
 * @param object $activity      the activity object the digestforum resides in
 * @param int    $courseid      the id of the course the digestforum resides in
 * @param bool   $detail        not used, but required for compatibilty with other modules
 * @param int    $modnames      not used, but required for compatibilty with other modules
 * @param bool   $viewfullnames not used, but required for compatibilty with other modules
 */
function digestforum_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
    global $OUTPUT;

    $content = $activity->content;
    if ($content->parent) {
        $class = 'reply';
    } else {
        $class = 'discussion';
    }

    $tableoptions = [
        'border' => '0',
        'cellpadding' => '3',
        'cellspacing' => '0',
        'class' => 'digestforum-recent table-reboot',
    ];
    $output = html_writer::start_tag('table', $tableoptions);
    $output .= html_writer::start_tag('tr');

    $post = (object) ['parent' => $content->parent];
    $digestforum = (object) ['type' => $content->digestforumtype];
    $authorhidden = digestforum_is_author_hidden($post, $digestforum);

    // Show user picture if author should not be hidden.
    if (!$authorhidden) {
        $pictureoptions = [
            'courseid' => $courseid,
            'link' => $authorhidden,
            'alttext' => $authorhidden,
        ];
        $picture = $OUTPUT->user_picture($activity->user, $pictureoptions);
        $output .= html_writer::tag('td', $picture, ['class' => 'userpicture', 'valign' => 'top']);
    }

    // Discussion title and author.
    $output .= html_writer::start_tag('td', ['class' => $class]);
    if ($content->parent) {
        $class = 'title';
    } else {
        // Bold the title of new discussions so they stand out.
        $class = 'title bold';
    }

    $output .= html_writer::start_div($class);
    if ($detail) {
        $aname = s($activity->name);
        $output .= $OUTPUT->image_icon('monologo', $aname, $activity->type);
    }
    $discussionurl = new moodle_url('/mod/digestforum/discuss.php', ['d' => $content->discussion]);
    $discussionurl->set_anchor('p' . $activity->content->id);
    $output .= html_writer::link($discussionurl, $content->subject);
    $output .= html_writer::end_div();

    $timestamp = userdate_htmltime($activity->timestamp);
    if ($authorhidden) {
        $authornamedate = $timestamp;
    } else {
        $fullname = fullname($activity->user, $viewfullnames);
        $userurl = new moodle_url('/user/view.php');
        $userurl->params(['id' => $activity->user->id, 'course' => $courseid]);
        $by = new stdClass();
        $by->name = html_writer::link($userurl, $fullname);
        $by->date = $timestamp;
        $authornamedate = get_string('bynameondate', 'digestforum', $by);
    }
    $output .= html_writer::div($authornamedate, 'user');
    $output .= html_writer::end_tag('td');
    $output .= html_writer::end_tag('tr');
    $output .= html_writer::end_tag('table');

    echo $output;
}

/**
 * recursively sets the discussion field to $discussionid on $postid and all its children
 * used when pruning a post
 *
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function digestforum_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('digestforum_posts', 'discussion', $discussionid, ['id' => $postid]);
    if ($posts = $DB->get_records('digestforum_posts', ['parent' => $postid])) {
        foreach ($posts as $post) {
            digestforum_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

// Functions to do with read tracking.

/**
 * Mark posts as read.
 *
 * @param object $user object
 * @param array $postids array of post ids
 * @return boolean success
 */
function digestforum_tp_mark_posts_read($user, $postids) {
    global $CFG, $DB;

    if (!digestforum_tp_can_track_digestforums(false, $user)) {
        return true;
    }

    $status = true;

    $now = time();
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * 24 * 3600);

    if (empty($postids)) {
        return true;
    } else if (count($postids) > 200) {
        while ($part = array_splice($postids, 0, 200)) {
            $status = digestforum_tp_mark_posts_read($user, $part) && $status;
        }
        return $status;
    }

    [$usql, $postidparams] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'postid');

    $insertparams = [
        'userid1' => $user->id,
        'userid2' => $user->id,
        'userid3' => $user->id,
        'firstread' => $now,
        'lastread' => $now,
        'cutoffdate' => $cutoffdate,
    ];
    $params = array_merge($postidparams, $insertparams);

    if ($CFG->digestforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = " . DFORUM_TRACKING_FORCED . "
                        OR (f.trackingtype = " . DFORUM_TRACKING_OPTIONAL . " AND tf.id IS NULL))";
    } else {
        $trackingsql = "AND ((f.trackingtype = " . DFORUM_TRACKING_OPTIONAL . "  OR f.trackingtype = " . DFORUM_TRACKING_FORCED . ")
                            AND tf.id IS NULL)";
    }

    // First insert any new entries.
    $sql = "INSERT INTO {digestforum_read} (userid, postid, discussionid, digestforumid, firstread, lastread)

            SELECT :userid1, p.id, p.discussion, d.digestforum, :firstread, :lastread
                FROM {digestforum_posts} p
                    JOIN {digestforum_discussions} d       ON d.id = p.discussion
                    JOIN {digestforum} f                   ON f.id = d.digestforum
                    LEFT JOIN {digestforum_track_prefs} tf ON (tf.userid = :userid2 AND tf.digestforumid = f.id)
                    LEFT JOIN {digestforum_read} fr        ON (
                            fr.userid = :userid3
                        AND fr.postid = p.id
                        AND fr.discussionid = d.id
                        AND fr.digestforumid = f.id
                    )
                WHERE p.id $usql
                    AND p.modified >= :cutoffdate
                    $trackingsql
                    AND fr.id IS NULL";

    $status = $DB->execute($sql, $params) && $status;

    // Then update all records.
    $updateparams = [
        'userid' => $user->id,
        'lastread' => $now,
    ];
    $params = array_merge($postidparams, $updateparams);
    $status = $DB->set_field_select(
        'digestforum_read',
        'lastread',
        $now,
        '
                userid      =  :userid
            AND lastread    <> :lastread
            AND postid      ' . $usql,
        $params
    ) && $status;

    return $status;
}

/**
 * Mark post as read.
 * @param int $userid
 * @param int $postid
 */
function digestforum_tp_add_read_record($userid, $postid) {
    global $CFG, $DB;

    $now = time();
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('digestforum_read', ['userid' => $userid, 'postid' => $postid])) {
        $sql = "INSERT INTO {digestforum_read} (userid, postid, discussionid, digestforumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.digestforum, ?, ?
                  FROM {digestforum_posts} p
                       JOIN {digestforum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, [$userid, $now, $now, $postid, $cutoffdate]);
    } else {
        $sql = "UPDATE {digestforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, [$now, $userid, $userid]);
    }
}

/**
 * If its an old post, do nothing. If the record exists, the maintenance will clear it up later.
 *
 * @param   int     $userid The ID of the user to mark posts read for.
 * @param   object  $post   The post record for the post to mark as read.
 * @param   mixed   $unused
 * @return bool
 */
function digestforum_tp_mark_post_read($userid, $post, $unused = null) {
    if (!digestforum_tp_is_post_old($post)) {
        return digestforum_tp_add_read_record($userid, $post->id);
    } else {
        return true;
    }
}

/**
 * Marks a whole digestforum as read, for a given user
 *
 * @param object $user
 * @param int $digestforumid
 * @param int|bool $groupid
 * @return bool
 */
function digestforum_tp_mark_digestforum_read($user, $digestforumid, $groupid = false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->digestforum_oldpostdays * 24 * 60 * 60);

    $groupsel = "";
    $params = [$user->id, $digestforumid, $cutoffdate];

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {digestforum_posts} p
                   LEFT JOIN {digestforum_discussions} d ON d.id = p.discussion
                   LEFT JOIN {digestforum_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.digestforum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $groupsel";

    if ($posts = $DB->get_records_sql($sql, $params)) {
        $postids = array_keys($posts);
        return digestforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Marks a whole discussion as read, for a given user
 *
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function digestforum_tp_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->digestforum_oldpostdays * 24 * 60 * 60);

    $sql = "SELECT p.id
              FROM {digestforum_posts} p
                   LEFT JOIN {digestforum_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, [$user->id, $discussionid, $cutoffdate])) {
        $postids = array_keys($posts);
        return digestforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Check whether a user has read a post. Old posts always count as read.
 *
 * @param int $userid
 * @param object $post
 */
function digestforum_tp_is_post_read($userid, $post) {
    global $DB;
    return (digestforum_tp_is_post_old($post) ||
            $DB->record_exists('digestforum_read', ['userid' => $userid, 'postid' => $post->id]));
}

/**
 * Check whether a post is old enough to always count as read.
 *
 * @param object $post
 * @param int $time Defaults to time()
 */
function digestforum_tp_is_post_old($post, $time = null) {
    global $CFG;

    if (is_null($time)) {
        $time = time();
    }
    return ($post->modified < ($time - ($CFG->digestforum_oldpostdays * 24 * 3600)));
}

/**
 * Returns the count of records for the provided user and course.
 * Please note that group access is ignored!
 *
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function digestforum_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $modinfo = get_fast_modinfo($courseid);
    $digestforumcms = $modinfo->get_instances_of('digestforum');
    if (empty($digestforumcms)) {
        // Return early if the course doesn't have any digestforum. Will save us a DB query.
        return [];
    }

    $now = floor(time() / MINSECS) * MINSECS; // DB cache friendliness.
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * DAYSECS);
    $params = [
        'privatereplyto' => $userid,
        'modified' => $cutoffdate,
        'readuserid' => $userid,
        'trackprefsuser' => $userid,
        'courseid' => $courseid,
        'trackdigestforumuser' => $userid,
    ];

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < :timestart AND (d.timeend = 0 OR d.timeend > :timeend)";
        $params['timestart'] = $now;
        $params['timeend'] = $now;
    } else {
        $timedsql = "";
    }

    if ($CFG->digestforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = " . DFORUM_TRACKING_FORCED . "
                            OR (f.trackingtype = " . DFORUM_TRACKING_OPTIONAL . " AND tf.id IS NULL
                                AND (SELECT trackforums FROM {user} WHERE id = :trackdigestforumuser) = 1))";
    } else {
        $trackingsql = "AND ((f.trackingtype = " . DFORUM_TRACKING_OPTIONAL . " OR f.trackingtype = " . DFORUM_TRACKING_FORCED . ")
                            AND tf.id IS NULL
                            AND (SELECT trackforums FROM {user} WHERE id = :trackdigestforumuser) = 1)";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread,
                   COUNT(p.privatereply) as privatereplies,
                   COUNT(p.privatereplytouser) as privaterepliestouser
              FROM (
                        SELECT
                            id,
                            discussion,
                            CASE WHEN privatereplyto <> 0 THEN 1 END privatereply,
                            CASE WHEN privatereplyto = :privatereplyto THEN 1 END privatereplytouser
                        FROM {digestforum_posts}
                        WHERE modified >= :modified
                   ) p
                   JOIN {digestforum_discussions} d       ON d.id = p.discussion
                   JOIN {digestforum} f                   ON f.id = d.digestforum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {digestforum_read} r         ON (r.postid = p.id AND r.userid = :readuserid)
                   LEFT JOIN {digestforum_track_prefs} tf ON (tf.userid = :trackprefsuser AND tf.digestforumid = f.id)
             WHERE f.course = :courseid
                   AND r.id is NULL
                   $trackingsql
                   $timedsql
          GROUP BY f.id";

    $results = [];
    if ($records = $DB->get_records_sql($sql, $params)) {
        // Loop through each digestforum instance to check for capability and count the number of unread posts.
        foreach ($digestforumcms as $cm) {
            // Check that the digestforum instance exists in the query results.
            if (!isset($records[$cm->instance])) {
                continue;
            }

            $record = $records[$cm->instance];
            $unread = $record->unread;

            // Check if the user has the capability to read private replies for this digestforum instance.
            $digestforumcontext = context_module::instance($cm->id);
            if (!has_capability('mod/digestforum:readprivatereplies', $digestforumcontext, $userid)) {
                // The real unread count would be the total of unread count minus the number of unread private replies plus
                // the total unread private replies to the user.
                $unread = $record->unread - $record->privatereplies + $record->privaterepliestouser;
            }

            // Build and add the object to the array of results to be returned.
            $results[$record->id] = (object)[
                'id' => $record->id,
                'unread' => $unread,
            ];
        }
    }

    return $results;
}

/**
 * Returns the count of records for the provided user and digestforum and [optionally] group.
 *
 * @param object $cm
 * @param object $course
 * @param bool   $resetreadcache optional, true to reset the function static $readcache var
 * @return int
 */
function digestforum_tp_count_digestforum_unread_posts($cm, $course, $resetreadcache = false) {
    global $CFG, $USER, $DB;

    static $readcache = [];

    if ($resetreadcache) {
        $readcache = [];
    }

    $digestforumid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = [];
        if ($counts = digestforum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$digestforumid])) {
        // No need to check group mode ;-).
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$digestforumid];
    }

    $digestforumcontext = context_module::instance($cm->id);
    if (has_any_capability(['moodle/site:accessallgroups', 'mod/digestforum:readprivatereplies'], $digestforumcontext)) {
        return $readcache[$course->id][$digestforumid];
    }

    require_once($CFG->dirroot . '/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // Add all groups posts.
    $mygroups[-1] = -1;

     [$groupssql, $groupsparams] = $DB->get_in_or_equal($mygroups, SQL_PARAMS_NAMED);

    $now = floor(time() / MINSECS) * MINSECS; // DB Cache friendliness.
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * DAYSECS);
    $params = [
        'readuser' => $USER->id,
        'digestforum' => $digestforumid,
        'cutoffdate' => $cutoffdate,
        'privatereplyto' => $USER->id,
    ];

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < :timestart AND (d.timeend = 0 OR d.timeend > :timeend)";
        $params['timestart'] = $now;
        $params['timeend'] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groupsparams);

    $sql = "SELECT COUNT(p.id)
              FROM {digestforum_posts} p
              JOIN {digestforum_discussions} d ON p.discussion = d.id
         LEFT JOIN {digestforum_read} r ON (r.postid = p.id AND r.userid = :readuser)
             WHERE d.digestforum = :digestforum
                   AND p.modified >= :cutoffdate AND r.id is NULL
                   $timedsql
                   AND d.groupid $groupssql
                   AND (p.privatereplyto = 0 OR p.privatereplyto = :privatereplyto)";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified index. At least one parameter must be specified.
 *
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $digestforumid
 * @return bool
 */
function digestforum_tp_delete_read_records($userid = -1, $postid = -1, $discussionid = -1, $digestforumid = -1) {
    global $DB;
    $params = [];

    $select = '';
    if ($userid > -1) {
        if ($select != '') {
            $select .= ' AND ';
        }
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') {
            $select .= ' AND ';
        }
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') {
            $select .= ' AND ';
        }
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($digestforumid > -1) {
        if ($select != '') {
            $select .= ' AND ';
        }
        $select .= 'digestforumid = ?';
        $params[] = $digestforumid;
    }
    if ($select == '') {
        return false;
    } else {
        return $DB->delete_records_select('digestforum_read', $select, $params);
    }
}

/**
 * Determine if a user can track digestforums and optionally a particular digestforum.
 * Checks the site settings, the user settings and the digestforum settings (if
 * requested).
 *
 * @param mixed $digestforum The digestforum object to test, or the int id (optional).
 * @param mixed $user The user object to check for (optional).
 * @return boolean
 */
function digestforum_tp_can_track_digestforums($digestforum = false, $user = false) {
    global $USER, $CFG, $DB;

    // If possible, avoid expensive
    // queries.
    if (empty($CFG->digestforum_trackreadposts)) {
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) || empty($user->id)) {
        return false;
    }

    if ($digestforum === false) {
        if ($CFG->digestforum_allowforcedreadtracking) {
            // Since we can force tracking, assume yes without a specific digestforum.
            return true;
        } else {
            return (bool)$user->trackforums;
        }
    }

    // Work toward always passing an object...
    if (is_numeric($digestforum)) {
        debugging('Better use proper digestforum object.', DEBUG_DEVELOPER);
        $digestforum = $DB->get_record('digestforum', ['id' => $digestforum], '', 'id,trackingtype');
    }

    if (method_exists($digestforum, 'get_tracking_type')) {
        $trackingtype = $digestforum->get_tracking_type();
    } else {
        $trackingtype = $digestforum->trackingtype;
    }

    $digestforumallows = ($trackingtype == DFORUM_TRACKING_OPTIONAL);
    $digestforumforced = ($trackingtype == DFORUM_TRACKING_FORCED);

    if ($CFG->digestforum_allowforcedreadtracking) {
        // If we allow forcing, then forced digestforums takes procidence over user setting.
        return ($digestforumforced || ($digestforumallows  && (!empty($user->trackforums) && (bool)$user->trackforums)));
    } else {
        // If we don't allow forcing, user setting trumps.
        return ($digestforumforced || $digestforumallows)  && !empty($user->trackforums);
    }
}

/**
 * Tells whether a specific digestforum is tracked by the user. A user can optionally
 * be specified. If not specified, the current user is assumed.
 *
 * @param mixed $digestforum If int, the id of the digestforum being checked; if object, the digestforum object
 * @param mixed $user The user object, or id, being checked (optional).
 * @return boolean
 */
function digestforum_tp_is_tracked($digestforum, $user = false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) || empty($user->id)) {
        return false;
    }

    $cache = cache::make('mod_digestforum', 'digestforum_is_tracked');
    $digestforumid = is_numeric($digestforum) ? $digestforum : $digestforum->id;
    $key = $digestforumid . '_' . $user->id;
    if ($cachedvalue = $cache->get($key)) {
        return $cachedvalue == 'tracked';
    }

    // Work toward always passing an object...
    if (is_numeric($digestforum)) {
        debugging('Better use proper digestforum object.', DEBUG_DEVELOPER);
        $digestforum = $DB->get_record('digestforum', ['id' => $digestforum]);
    }

    if (!digestforum_tp_can_track_digestforums($digestforum, $user)) {
        return false;
    }

    $digestforumallows = ($digestforum->trackingtype == DFORUM_TRACKING_OPTIONAL);
    $digestforumforced = ($digestforum->trackingtype == DFORUM_TRACKING_FORCED);
    $userpref = $DB->get_record('digestforum_track_prefs', ['userid' => $user->id, 'digestforumid' => $digestforum->id]);

    if ($CFG->digestforum_allowforcedreadtracking) {
        $istracked = $digestforumforced || ($digestforumallows && $userpref === false);
    } else {
        $istracked = ($digestforumallows || $digestforumforced) && $userpref === false;
    }

    // We have to store a string here because the cache API returns false
    // when it can't find the key which would be confused with our legitimate
    // false value. *sigh*.
    $cache->set($key, $istracked ? 'tracked' : 'not');

    return $istracked;
}

/**
 * Start tracking read posts in a digestforum for a user.
 *
 * @param int $digestforumid
 * @param int $userid
 */
function digestforum_tp_start_tracking($digestforumid, $userid = false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('digestforum_track_prefs', ['userid' => $userid, 'digestforumid' => $digestforumid]);
}

/**
 * Stop tracking read posts in a digestforum for a user, and remove their read records.
 *
 * @param int $digestforumid
 * @param int $userid
 */
function digestforum_tp_stop_tracking($digestforumid, $userid = false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('digestforum_track_prefs', ['userid' => $userid, 'digestforumid' => $digestforumid])) {
        $trackprefs = new stdClass();
        $trackprefs->userid = $userid;
        $trackprefs->digestforumid = $digestforumid;
        $DB->insert_record('digestforum_track_prefs', $trackprefs);
    }

    return digestforum_tp_delete_read_records($userid, -1, -1, $digestforumid);
}


/**
 * Clean old records from the digestforum_read table.
 * @return void
 */
function digestforum_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->digestforum_oldpostdays)) {
        return;
    }
    // Look for records older than the cutoffdate that are still in the digestforum_read table.
    $cutoffdate = time() - ($CFG->digestforum_oldpostdays * 24 * 60 * 60);

    // First get the oldest tracking present - we need tis to speedup the next delete query.
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {digestforum_posts} fp
                   JOIN {digestforum_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // Nothing to delete.
        return;
    }

    // Now delete old tracking info.
    $sql = "DELETE
              FROM {digestforum_read}
             WHERE postid IN (SELECT fp.id
                                FROM {digestforum_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, [$first, $cutoffdate]);
}

/**
 * Sets the last post for a given discussion
 *
 * @param into $discussionid
 * @return bool|int
 **/
function digestforum_discussion_update_last_post($discussionid) {
    global $CFG, $DB;

    // Check the given discussion exists.
    if (!$DB->record_exists('digestforum_discussions', ['id' => $discussionid])) {
        return false;
    }

    // Use SQL to find the last post for this discussion.
    $sql = "SELECT id, userid, modified
              FROM {digestforum_posts}
             WHERE discussion=?
             ORDER BY modified DESC, id DESC";

    // Lets go find the last post.
    if (($lastposts = $DB->get_records_sql($sql, [$discussionid], 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new stdClass();
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        $DB->update_record('digestforum_discussions', $discussionobject);
        return $lastpost->id;
    }

    // To get here either we couldn't find a post for the discussion (weird)
    // or we couldn't update the discussion record (weird x2).
    return false;
}


/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function digestforum_get_view_actions() {
    return ['view discussion', 'search', 'digestforum', 'digestforums', 'subscribers', 'view digestforum'];
}

/**
 * List the options for digestforum subscription modes.
 * This is used by the settings page and by the mod_form page.
 *
 * @return array
 */
function digestforum_get_subscriptionmode_options() {
    $options = [];
    $options[DFORUM_CHOOSESUBSCRIBE] = get_string('subscriptionoptional', 'digestforum');
    $options[DFORUM_FORCESUBSCRIBE] = get_string('subscriptionforced', 'digestforum');
    $options[DFORUM_INITIALSUBSCRIBE] = get_string('subscriptionauto', 'digestforum');
    $options[DFORUM_DISALLOWSUBSCRIBE] = get_string('subscriptiondisabled', 'digestforum');
    return $options;
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function digestforum_get_post_actions() {
    return ['add discussion', 'add post', 'delete discussion', 'delete post', 'move discussion', 'prune post', 'update post'];
}

/**
 * Returns a warning object if a user has reached the number of posts equal to
 * the warning/blocking setting, or false if there is no warning to show.
 *
 * @param int|stdClass $digestforum the digestforum id or the digestforum object
 * @param stdClass $cm the course module
 * @return stdClass|bool returns an object with the warning information, else
 *         returns false if no warning is required.
 */
function digestforum_check_throttling($digestforum, $cm = null) {
    global $CFG, $DB, $USER;

    if (is_numeric($digestforum)) {
        $digestforum = $DB->get_record(
            'digestforum',
            ['id' => $digestforum],
            'id, course, blockperiod, blockafter, warnafter',
            MUST_EXIST,
        );
    }

    if (!is_object($digestforum) || !isset($digestforum->id) || !isset($digestforum->course)) {
        // The passed digestforum parameter is invalid. This can happen if:
        // - a non-object and non-numeric digestforum is passed; or
        // - the digestforum object does not have an ID or course attributes.
        // This is unlikely to happen with properly formed digestforum record fetched from the database,
        // so it's most likely a dev error if we hit such this case.
        throw new coding_exception('Invalid digestforum parameter passed');
    }

    if (empty($digestforum->blockafter)) {
        return false;
    }

    if (empty($digestforum->blockperiod)) {
        return false;
    }

    if (!$cm) {
        // Try to fetch the $cm object via get_fast_modinfo() so we don't incur DB reads.
        $modinfo = get_fast_modinfo($digestforum->course);
        $digestforumcms = $modinfo->get_instances_of('digestforum');
        foreach ($digestforumcms as $tmpcm) {
            if ($tmpcm->instance == $digestforum->id) {
                $cm = $tmpcm;
                break;
            }
        }
        // Last resort. Try to fetch via get_coursemodule_from_instance().
        if (!$cm) {
            $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course, false, MUST_EXIST);
        }
    }

    $modcontext = context_module::instance($cm->id);
    if (has_capability('mod/digestforum:postwithoutthrottling', $modcontext)) {
        return false;
    }

    // Get the number of posts in the last period we care about.
    $timenow = time();
    $timeafter = $timenow - $digestforum->blockperiod;
    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {digestforum_posts} p
                                        JOIN {digestforum_discussions} d
                                        ON p.discussion = d.id WHERE d.digestforum = ?
                                        AND p.userid = ? AND p.created > ?', [$digestforum->id, $USER->id, $timeafter]);

    $a = new stdClass();
    $a->blockafter = $digestforum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime' . $digestforum->blockperiod);

    if ($digestforum->blockafter <= $numposts) {
        $warning = new stdClass();
        $warning->canpost = false;
        $warning->errorcode = 'digestforumblockingtoomanyposts';
        $warning->module = 'error';
        $warning->additional = $a;
        $warning->link = $CFG->wwwroot . '/mod/digestforum/view.php?f=' . $digestforum->id;

        return $warning;
    }

    if ($digestforum->warnafter <= $numposts) {
        $warning = new stdClass();
        $warning->canpost = true;
        $warning->errorcode = 'digestforumblockingalmosttoomanyposts';
        $warning->module = 'digestforum';
        $warning->additional = $a;
        $warning->link = null;

        return $warning;
    }

    // No warning needs to be shown yet.
    return false;
}

/**
 * Throws an error if the user is no longer allowed to post due to having reached
 * or exceeded the number of posts specified in 'Post threshold for blocking'
 * setting.
 *
 * @since Moodle 2.5
 * @param stdClass $thresholdwarning the warning information returned
 *        from the function digestforum_check_throttling.
 */
function digestforum_check_blocking_threshold($thresholdwarning) {
    if (!empty($thresholdwarning) && !$thresholdwarning->canpost) {
        throw new \moodle_exception(
            $thresholdwarning->errorcode,
            $thresholdwarning->module,
            $thresholdwarning->link,
            $thresholdwarning->additional
        );
    }
}


/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string $type optional
 */
function digestforum_reset_gradebook($courseid, $type = '') {
    global $CFG, $DB;

    $wheresql = '';
    $params = [$courseid];
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {digestforum} f, {course_modules} cm, {modules} m
             WHERE m.name='digestforum' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($digestforums = $DB->get_records_sql($sql, $params)) {
        foreach ($digestforums as $digestforum) {
            digestforum_grade_item_update($digestforum, 'reset', 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified digestforum
 * and clean up any related data.
 *
 * @param stdClass $data the data submitted from the reset course.
 * @return array status array
 */
function digestforum_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/rating/lib.php');

    $componentstr = get_string('modulenameplural', 'digestforum');
    $status = [];

    $params = [$data->courseid];

    $removeposts = false;
    $typesql = '';
    if (!empty($data->reset_digestforum_all)) {
        $removeposts = true;
        $typesstr = get_string('resetdigestforumsall', 'digestforum');
        $types = [];
    } else if (!empty($data->reset_digestforum_types)) {
        $removeposts = true;
        $types = [];
        $sqltypes = [];
        $digestforumtypesall = digestforum_get_digestforum_types_all();
        foreach ($data->reset_digestforum_types as $type) {
            if (!array_key_exists($type, $digestforumtypesall)) {
                continue;
            }
            $types[] = $digestforumtypesall[$type];
            $sqltypes[] = $type;
        }
        if (!empty($sqltypes)) {
            [$typesql, $typeparams] = $DB->get_in_or_equal($sqltypes);
            $typesql = " AND f.type " . $typesql;
            $params = array_merge($params, $typeparams);
        }
        $typesstr = get_string('resetdigestforums', 'digestforum') . ': ' . implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {digestforum_discussions} fd, {digestforum} f
                           WHERE f.course=? AND f.id=fd.digestforum";

    $alldigestforumssql = "SELECT f.id
                       FROM {digestforum} f
                      WHERE f.course=?";

    $allpostssql = "SELECT fp.id
                      FROM {digestforum_posts} fp, {digestforum_discussions} fd, {digestforum} f
                     WHERE f.course=? AND f.id=fd.digestforum AND fd.id=fp.discussion";

    $digestforumssql = $digestforums = $rm = null;

    // Check if we need to get additional data.
    if ($removeposts || !empty($data->reset_digestforum_ratings) || !empty($data->reset_digestforum_tags)) {
        // Set this up if we have to remove ratings.
        $rm = new rating_manager();
        $ratingdeloptions = new stdClass();
        $ratingdeloptions->component = 'mod_digestforum';
        $ratingdeloptions->ratingarea = 'post';

        // Get the digestforums for actions that require it.
        $digestforumssql = "$alldigestforumssql $typesql";
        $digestforums = $DB->get_records_sql($digestforumssql, $params);
    }

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $postssql = "$allpostssql $typesql";

        // Now get rid of all attachments.
        $fs = get_file_storage();
        if ($digestforums) {
            foreach ($digestforums as $digestforumid => $unused) {
                if (!$cm = get_coursemodule_from_instance('digestforum', $digestforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);
                $fs->delete_area_files($context->id, 'mod_digestforum', 'attachment');
                $fs->delete_area_files($context->id, 'mod_digestforum', 'post');

                // Remove ratings.
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);

                core_tag_tag::delete_instances('mod_digestforum', null, $context->id);
            }
        }

        // First delete all read flags.
        $DB->delete_records_select('digestforum_read', "digestforumid IN ($digestforumssql)", $params);

        // Remove tracking prefs.
        $DB->delete_records_select('digestforum_track_prefs', "digestforumid IN ($digestforumssql)", $params);

        // Remove posts from queue.
        $DB->delete_records_select('digestforum_queue', "discussionid IN ($discussionssql)", $params);

        // All posts - initial posts must be kept in single simple discussion digestforums.
        // First all children.
        $DB->delete_records_select('digestforum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params);
        // Now the initial posts for non single simple.
        $DB->delete_records_select(
            'digestforum_posts',
            "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0",
            $params
        );

        // Finally all discussions except single simple digestforums.
        $DB->delete_records_select('digestforum_discussions', "digestforum IN ($digestforumssql AND f.type <> 'single')", $params);

        // Remove all grades from gradebook.
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                digestforum_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    digestforum_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = [
            'component' => $componentstr,
            'item' => $typesstr,
            'error' => false,
        ];
    }

    // Remove all ratings in this course's digestforums.
    if (!empty($data->reset_digestforum_ratings)) {
        if ($digestforums) {
            foreach ($digestforums as $digestforumid => $unused) {
                if (!$cm = get_coursemodule_from_instance('digestforum', $digestforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);

                // Remove ratings.
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // Remove all grades from gradebook.
        if (empty($data->reset_gradebook_grades)) {
            digestforum_reset_gradebook($data->courseid);
        }
    }

    // Remove all the tags.
    if (!empty($data->reset_digestforum_tags)) {
        if ($digestforums) {
            foreach ($digestforums as $digestforumid => $unused) {
                if (!$cm = get_coursemodule_from_instance('digestforum', $digestforumid)) {
                    continue;
                }

                $context = context_module::instance($cm->id);
                core_tag_tag::delete_instances('mod_digestforum', null, $context->id);
            }
        }

        $status[] = [
            'component' => $componentstr,
            'item' => get_string('removealldigestforumtags', 'digestforum'),
            'error' => false,
        ];
    }

    // Remove all digest settings unconditionally - even for users still enrolled in course.
    if (!empty($data->reset_digestforum_digests)) {
        $DB->delete_records_select('digestforum_digests', "digestforum IN ($alldigestforumssql)", $params);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('resetdigests', 'digestforum'),
            'error' => false,
        ];
    }

    // Remove all subscriptions unconditionally - even for users still enrolled in course.
    if (!empty($data->reset_digestforum_subscriptions)) {
        $DB->delete_records_select('digestforum_subscriptions', "digestforum IN ($alldigestforumssql)", $params);
        $DB->delete_records_select('digestforum_discussion_subs', "digestforum IN ($alldigestforumssql)", $params);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('resetsubscriptions', 'digestforum'),
            'error' => false,
        ];
    }

    // Remove all tracking prefs unconditionally - even for users still enrolled in course.
    if (!empty($data->reset_digestforum_track_prefs)) {
        $DB->delete_records_select('digestforum_track_prefs', "digestforumid IN ($alldigestforumssql)", $params);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('resettrackprefs', 'digestforum'),
            'error' => false,
        ];
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        shift_course_mod_dates('digestforum', [
            'assesstimestart',
            'assesstimefinish',
            'duedate',
            'cutoffdate',
        ], $data->timeshift, $data->courseid);

        $status[] = [
            'component' => $componentstr,
            'item' => get_string('date'),
            'error' => false,
        ];
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param MoodleQuickForm $mform form passed by reference
 */
function digestforum_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'digestforumheader', get_string('modulenameplural', 'digestforum'));
    $mform->addElement('static', 'digestforumdelete', get_string('delete'));

    $mform->addElement('checkbox', 'reset_digestforum_digests', get_string('resetdigests', 'digestforum'));

    $mform->addElement('checkbox', 'reset_digestforum_subscriptions', get_string('resetsubscriptions', 'digestforum'));

    $mform->addElement('checkbox', 'reset_digestforum_all', get_string('resetdigestforumsall', 'digestforum'));

    $mform->addElement(
        'select',
        'reset_digestforum_types',
        get_string('resetdigestforums', 'digestforum'),
        digestforum_get_digestforum_types_all(),
        ['multiple' => 'multiple'],
    );
    $mform->hideIf('reset_digestforum_types', 'reset_digestforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_digestforum_track_prefs', get_string('resettrackprefs', 'digestforum'));
    $mform->hideIf('reset_digestforum_track_prefs', 'reset_digestforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_digestforum_ratings', get_string('deleteallratings'));
    $mform->hideIf('reset_digestforum_ratings', 'reset_digestforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_digestforum_tags', get_string('removealldigestforumtags', 'digestforum'));
    $mform->hideIf('reset_digestforum_tags', 'reset_digestforum_all', 'checked');
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course The course
 * @return array
 */
function digestforum_reset_course_form_defaults($course) {
    return [
        'reset_digestforum_all' => 1,
        'reset_digestforum_digests' => 0,
        'reset_digestforum_subscriptions' => 0,
        'reset_digestforum_track_prefs' => 0,
        'reset_digestforum_ratings' => 1,
    ];
}

/**
 * Returns array of digestforum layout modes
 *
 * @param bool $useexperimentalui use experimental layout modes or not
 * @return array
 */
function digestforum_get_layout_modes(bool $useexperimentalui = false) {
    $modes = [
        DFORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'digestforum'),
        DFORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'digestforum'),
        DFORUM_MODE_THREADED   => get_string('modethreaded', 'digestforum'),
    ];

    if ($useexperimentalui) {
        $modes[DFORUM_MODE_NESTED_V2] = get_string('modenestedv2', 'digestforum');
    } else {
        $modes[DFORUM_MODE_NESTED] = get_string('modenested', 'digestforum');
    }

    return $modes;
}

/**
 * Returns array of digestforum types chooseable on the digestforum editing form
 *
 * @return array
 */
function digestforum_get_digestforum_types() {
    return  ['general'  => get_string('generaldigestforum', 'digestforum'),
                  'eachuser' => get_string('eachuserdigestforum', 'digestforum'),
                  'single'   => get_string('singledigestforum', 'digestforum'),
                  'qanda'    => get_string('qandadigestforum', 'digestforum'),
                  'blog'     => get_string('blogdigestforum', 'digestforum')];
}

/**
 * Returns array of all digestforum layout modes
 *
 * @return array
 */
function digestforum_get_digestforum_types_all() {
    return  ['news'     => get_string('namenews', 'digestforum'),
                  'social'   => get_string('namesocial', 'digestforum'),
                  'general'  => get_string('generaldigestforum', 'digestforum'),
                  'eachuser' => get_string('eachuserdigestforum', 'digestforum'),
                  'single'   => get_string('singledigestforum', 'digestforum'),
                  'qanda'    => get_string('qandadigestforum', 'digestforum'),
                  'blog'     => get_string('blogdigestforum', 'digestforum')];
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function digestforum_get_extra_capabilities() {
    return ['moodle/rating:view', 'moodle/rating:viewany', 'moodle/rating:viewall', 'moodle/rating:rate'];
}

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settingsnav The settings navigation object
 * @param navigation_node $digestforumnode The node to add module settings to
 */
function digestforum_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $digestforumnode) {
    global $USER, $CFG;

    if (empty($settingsnav->get_page()->cm->context)) {
        $settingsnav->get_page()->cm->context = context_module::instance($settingsnav->get_page()->cm->instance);
    }

    $vaultfactory = mod_digestforum\local\container::get_vault_factory();
    $managerfactory = mod_digestforum\local\container::get_manager_factory();
    $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
    $digestforumvault = $vaultfactory->get_digestforum_vault();
    $digestforumentity = $digestforumvault->get_from_id($settingsnav->get_page()->cm->instance);
    $digestforumobject = $legacydatamapperfactory->get_digestforum_data_mapper()->to_legacy_object($digestforumentity);

    $params = $settingsnav->get_page()->url->params();
    if (!empty($params['d'])) {
        $discussionid = $params['d'];
    }

    // For some actions you need to be enrolled, being admin is not enough sometimes here.
    $enrolled = is_enrolled($settingsnav->get_page()->context, $USER, '', false);
    $activeenrolled = is_enrolled($settingsnav->get_page()->context, $USER, '', true);

    $canmanage  = has_capability('mod/digestforum:managesubscriptions', $settingsnav->get_page()->context);
    $subscriptionmode = \mod_digestforum\subscriptions::get_subscription_mode($digestforumobject);
    $cansubscribe = $activeenrolled && !\mod_digestforum\subscriptions::is_forcesubscribed($digestforumobject) &&
            (!\mod_digestforum\subscriptions::subscription_disabled($digestforumobject) || $canmanage);

    if ($canmanage) {
        $mode = $digestforumnode->add(get_string('subscriptionmode', 'digestforum'), null, navigation_node::TYPE_CONTAINER);
        $mode->add_class('subscriptionmode');
        $mode->set_show_in_secondary_navigation(false);

        // Optional subscription mode.
        $allowchoicestring = get_string('subscriptionoptional', 'digestforum');
        $allowchoiceaction = new action_link(
            new moodle_url('/mod/digestforum/subscribe.php', [
                'id' => $digestforumobject->id,
                'mode' => DFORUM_CHOOSESUBSCRIBE,
                'sesskey' => sesskey(),
            ]),
            $allowchoicestring,
            new confirm_action(get_string('subscriptionmodeconfirm', 'mod_digestforum', $allowchoicestring))
        );
        $allowchoice = $mode->add($allowchoicestring, $allowchoiceaction, navigation_node::TYPE_SETTING);

        // Forced subscription mode.
        $forceforeverstring = get_string('subscriptionforced', 'digestforum');
        $forceforeveraction = new action_link(
            new moodle_url('/mod/digestforum/subscribe.php', [
                'id' => $digestforumobject->id,
                'mode' => DFORUM_FORCESUBSCRIBE,
                'sesskey' => sesskey(),
            ]),
            $forceforeverstring,
            new confirm_action(get_string('subscriptionmodeconfirm', 'mod_digestforum', $forceforeverstring))
        );
        $forceforever = $mode->add($forceforeverstring, $forceforeveraction, navigation_node::TYPE_SETTING);

        // Initial subscription mode.
        $forceinitiallystring = get_string('subscriptionauto', 'digestforum');
        $forceinitiallyaction = new action_link(
            new moodle_url('/mod/digestforum/subscribe.php', [
                'id' => $digestforumobject->id,
                'mode' => DFORUM_INITIALSUBSCRIBE,
                'sesskey' => sesskey(),
            ]),
            $forceinitiallystring,
            new confirm_action(get_string('subscriptionmodeconfirm', 'mod_digestforum', $forceinitiallystring))
        );
        $forceinitially = $mode->add($forceinitiallystring, $forceinitiallyaction, navigation_node::TYPE_SETTING);

        // Disabled subscription mode.
        $disallowchoicestring = get_string('subscriptiondisabled', 'digestforum');
        $disallowchoiceaction = new action_link(
            new moodle_url('/mod/digestforum/subscribe.php', [
                'id' => $digestforumobject->id,
                'mode' => DFORUM_DISALLOWSUBSCRIBE,
                'sesskey' => sesskey(),
            ]),
            $disallowchoicestring,
            new confirm_action(get_string('subscriptionmodeconfirm', 'mod_digestforum', $disallowchoicestring))
        );
        $disallowchoice = $mode->add($disallowchoicestring, $disallowchoiceaction, navigation_node::TYPE_SETTING);

        switch ($subscriptionmode) {
            case DFORUM_CHOOSESUBSCRIBE: // 0
                $allowchoice->action = null;
                $allowchoice->add_class('activesetting');
                $allowchoice->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
            case DFORUM_FORCESUBSCRIBE: // 1
                $forceforever->action = null;
                $forceforever->add_class('activesetting');
                $forceforever->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
            case DFORUM_INITIALSUBSCRIBE: // 2
                $forceinitially->action = null;
                $forceinitially->add_class('activesetting');
                $forceinitially->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
            case DFORUM_DISALLOWSUBSCRIBE: // 3
                $disallowchoice->action = null;
                $disallowchoice->add_class('activesetting');
                $disallowchoice->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
        }
    } else if ($activeenrolled) {
        switch ($subscriptionmode) {
            case DFORUM_CHOOSESUBSCRIBE: // 0
                $notenode = $digestforumnode->add(get_string('subscriptionoptional', 'digestforum'));
                break;
            case DFORUM_FORCESUBSCRIBE: // 1
                $notenode = $digestforumnode->add(get_string('subscriptionforced', 'digestforum'));
                break;
            case DFORUM_INITIALSUBSCRIBE: // 2
                $notenode = $digestforumnode->add(get_string('subscriptionauto', 'digestforum'));
                break;
            case DFORUM_DISALLOWSUBSCRIBE: // 3
                $notenode = $digestforumnode->add(get_string('subscriptiondisabled', 'digestforum'));
                break;
        }
    }

    if (has_capability('mod/digestforum:viewsubscribers', $settingsnav->get_page()->context)) {
        $url = new moodle_url('/mod/digestforum/subscribers.php', ['id' => $digestforumobject->id, 'edit' => 'off']);
        $digestforumnode->add(
            get_string('subscriptions', 'digestforum'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'digestforumsubscriptions',
        );
    }

    // Display all digestforum reports user has access to.
    if (isloggedin() && !isguestuser()) {
        $reportnames = array_keys(core_component::get_plugin_list('digestforumreport'));

        foreach ($reportnames as $reportname) {
            if (has_capability("digestforumreport/{$reportname}:view", $settingsnav->get_page()->context)) {
                $reportlinkparams = [
                    'courseid' => $digestforumobject->course,
                    'digestforumid' => $digestforumobject->id,
                ];
                $reportlink = new moodle_url("/mod/digestforum/report/{$reportname}/index.php", $reportlinkparams);
                $digestforumnode->add(get_string('reports'), $reportlink, navigation_node::TYPE_CONTAINER);
            }
        }
    }

    // Keep tracking info for users with suspended enrolments.
    if ($enrolled && digestforum_tp_can_track_digestforums($digestforumobject)) {
        if (
            $digestforumobject->trackingtype == DFORUM_TRACKING_OPTIONAL
                || ((!$CFG->digestforum_allowforcedreadtracking) && $digestforumobject->trackingtype == DFORUM_TRACKING_FORCED)
        ) {
            if (digestforum_tp_is_tracked($digestforumobject)) {
                $linktext = get_string('notrackdigestforum', 'digestforum');
            } else {
                $linktext = get_string('trackdigestforum', 'digestforum');
            }
            $url = new moodle_url('/mod/digestforum/settracking.php', [
                    'id' => $digestforumobject->id,
                    'sesskey' => sesskey(),
                ]);
            $digestforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if (!isloggedin() && $settingsnav->get_page()->course->id == SITEID) {
        $userid = guest_user()->id;
    } else {
        $userid = $USER->id;
    }

    $hascourseaccess = ($settingsnav->get_page()->course->id == SITEID) ||
        can_access_course($settingsnav->get_page()->course, $userid);
    $enablerssfeeds = !empty($CFG->enablerssfeeds) && !empty($CFG->digestforum_enablerssfeeds);

    if ($enablerssfeeds && $digestforumobject->rsstype && $digestforumobject->rssarticles && $hascourseaccess) {
        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($digestforumobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions', 'digestforum');
        } else {
            $string = get_string('rsssubscriberssposts', 'digestforum');
        }

        $url = new moodle_url(rss_get_url(
            $settingsnav->get_page()->cm->context->id,
            $userid,
            "mod_digestforum",
            $digestforumobject->id
        ));
        $digestforumnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }

    $capabilitymanager = $managerfactory->get_capability_manager($digestforumentity);
    if ($capabilitymanager->can_export_digestforum($USER)) {
        $url = new moodle_url('/mod/digestforum/export.php', ['id' => $digestforumobject->id]);
        $digestforumnode->add(get_string('export', 'mod_digestforum'), $url, navigation_node::TYPE_SETTING);
    }
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function digestforum_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $digestforumpagetype = [
        'mod-digestforum-*' => get_string('page-mod-digestforum-x', 'digestforum'),
        'mod-digestforum-view' => get_string('page-mod-digestforum-view', 'digestforum'),
        'mod-digestforum-discuss' => get_string('page-mod-digestforum-discuss', 'digestforum'),
    ];
    return $digestforumpagetype;
}

/**
 * Gets all of the courses where the provided user has posted in a digestforum.
 *
 * @param stdClass $user The user who's posts we are looking for
 * @param bool $discussionsonly If true only look for discussions started by the user
 * @param bool $includecontexts If set to trye contexts for the courses will be preloaded
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of courses
 */
function digestforum_get_courses_user_posted_in(
    $user,
    $discussionsonly = false,
    $includecontexts = true,
    $limitfrom = null,
    $limitnum = null,
) {
    global $DB;

    // If we are only after discussions we need only look at the digestforum_discussions
    // table and join to the userid there. If we are looking for posts then we need
    // to join to the digestforum_posts table.
    if (!$discussionsonly) {
        $subquery = "(SELECT DISTINCT fd.course
                         FROM {digestforum_discussions} fd
                         JOIN {digestforum_posts} fp ON fp.discussion = fd.id
                        WHERE fp.userid = :userid )";
    } else {
        $subquery = "(SELECT DISTINCT fd.course
                         FROM {digestforum_discussions} fd
                        WHERE fd.userid = :userid )";
    }

    $params = ['userid' => $user->id];

    // Join to the context table so that we can preload contexts if required.
    if ($includecontexts) {
        $ctxselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
        $ctxjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
        $params['contextlevel'] = CONTEXT_COURSE;
    } else {
        $ctxselect = '';
        $ctxjoin = '';
    }

    // Now we need to get all of the courses to search.
    // All courses where the user has posted within a digestforum will be returned.
    $sql = "SELECT c.* $ctxselect
            FROM {course} c
            $ctxjoin
            WHERE c.id IN ($subquery)";
    $courses = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    if ($includecontexts) {
        array_map('context_helper::preload_from_record', $courses);
    }
    return $courses;
}

/**
 * Gets all of the digestforums a user has posted in for one or more courses.
 *
 * @param stdClass $user
 * @param array|null $courseids An array of courseids to search or if not provided
 *                       all courses the user has posted within
 * @param bool $discussionsonly If true then only digestforums where the user has started
 *                       a discussion will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of digestforums the user has posted within in the provided courses
 */
function digestforum_get_digestforums_user_posted_in(
    $user,
    ?array $courseids = null,
    $discussionsonly = false,
    $limitfrom = null,
    $limitnum = null,
) {
    global $DB;

    if (!is_null($courseids)) {
        [$coursewhere, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $coursewhere = ' AND f.course ' . $coursewhere;
    } else {
        $coursewhere = '';
        $params = [];
    }
    $params['userid'] = $user->id;
    $params['digestforum'] = 'digestforum';

    if ($discussionsonly) {
        $join = 'JOIN {digestforum_discussions} ff ON ff.digestforum = f.id';
    } else {
        $join = 'JOIN {digestforum_discussions} fd ON fd.digestforum = f.id
                 JOIN {digestforum_posts} ff ON ff.discussion = fd.id';
    }

    $sql = "SELECT f.*, cm.id AS cmid
              FROM {digestforum} f
              JOIN {course_modules} cm ON cm.instance = f.id
              JOIN {modules} m ON m.id = cm.module
              JOIN (
                  SELECT f.id
                    FROM {digestforum} f
                    {$join}
                   WHERE ff.userid = :userid
                GROUP BY f.id
                   ) j ON j.id = f.id
             WHERE m.name = :digestforum
                 {$coursewhere}";

    $coursedigestforums = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    return $coursedigestforums;
}

/**
 * Returns posts made by the selected user in the requested courses.
 *
 * This method can be used to return all of the posts made by the requested user
 * within the given courses.
 * For each course the access of the current user and requested user is checked
 * and then for each post access to the post and digestforum is checked as well.
 *
 * This function is safe to use with usercapabilities.
 *
 * @param stdClass $user The user whose posts we want to get
 * @param array $courses The courses to search
 * @param bool $musthaveaccess If set to true errors will be thrown if the user
 *                             cannot access one or more of the courses to search
 * @param bool $discussionsonly If set to true only discussion starting posts
 *                              will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return stdClass An object the following properties
 *               ->totalcount: the total number of posts made by the requested user
 *                             that the current user can see.
 *               ->courses: An array of courses the current user can see that the
 *                          requested user has posted in.
 *               ->digestforums: An array of digestforums relating to the posts returned in the
 *                         property below.
 *               ->posts: An array containing the posts to show for this request.
 */
function digestforum_get_posts_by_user(
    $user,
    array $courses,
    $musthaveaccess = false,
    $discussionsonly = false,
    $limitfrom = 0,
    $limitnum = 50,
) {
    global $DB, $USER, $CFG;

    $return = new stdClass();
    $return->totalcount = 0;    // The total number of posts that the current user is able to view.
    $return->courses = []; // The courses the current user can access.
    $return->digestforums = [];  // The digestforums that the current user can access that contain posts.
    $return->posts = [];   // The posts to display.

    // First up a small sanity check. If there are no courses to check we can
    // return immediately, there is obviously nothing to search.
    if (empty($courses)) {
        return $return;
    }

    // A couple of quick setups.
    $isloggedin = isloggedin();
    $isguestuser = $isloggedin && isguestuser();
    $iscurrentuser = $isloggedin && $USER->id == $user->id;

    // Checkout whether or not the current user has capabilities over the requested
    // user and if so they have the capabilities required to view the requested
    // users content.
    $usercontext = context_user::instance($user->id, MUST_EXIST);
    $hascapsonuser = !$iscurrentuser && $DB->record_exists(
        'role_assignments',
        ['userid' => $USER->id, 'contextid' => $usercontext->id],
    );
    $hascapsonuser = $hascapsonuser && has_all_capabilities(['moodle/user:viewdetails', 'moodle/user:readuserposts'], $usercontext);

    // Before we actually search each course we need to check the user's access to the
    // course. If the user doesn't have the appropraite access then we either throw an
    // error if a particular course was requested or we just skip over the course.
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id, MUST_EXIST);
        if ($iscurrentuser || $hascapsonuser) {
            // If it is the current user, or the current user has capabilities to the
            // requested user then all we need to do is check the requested users
            // current access to the course.
            // Note: There is no need to check group access or anything of the like
            // as either the current user is the requested user, or has granted
            // capabilities on the requested user. Either way they can see what the
            // requested user posted, although its VERY unlikely in the `parent` situation
            // that the current user will be able to view the posts in context.
            if (!is_viewing($coursecontext, $user) && !is_enrolled($coursecontext, $user)) {
                // Need to have full access to a course to see the rest of own info.
                if ($musthaveaccess) {
                    throw new \moodle_exception('errorenrolmentrequired', 'digestforum');
                }
                continue;
            }
        } else {
            // Check whether the current user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course)) {
                if ($musthaveaccess) {
                    throw new \moodle_exception('errorenrolmentrequired', 'digestforum');
                }
                continue;
            }

            // If groups are in use and enforced throughout the course then make sure
            // we can meet in at least one course level group.
            // Note that we check if either the current user or the requested user have
            // the capability to access all groups. This is because with that capability
            // a user in group A could post in the group B digestforum. Grrrr.
            if (
                groups_get_course_groupmode($course) == SEPARATEGROUPS && $course->groupmodeforce
                && !has_capability(
                    'moodle/site:accessallgroups',
                    $coursecontext,
                ) && !has_capability('moodle/site:accessallgroups', $coursecontext, $user->id)
            ) {
                // If its the guest user to bad... the guest user cannot access groups.
                if (!$isloggedin || $isguestuser) {
                    // Do not use require_login() here because we might have already used require_login($course).
                    if ($musthaveaccess) {
                        redirect(get_login_url());
                    }
                    continue;
                }
                // Get the groups of the current user.
                $mygroups = array_keys(groups_get_all_groups($course->id, $USER->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Get the groups the requested user is a member of.
                $usergroups = array_keys(groups_get_all_groups($course->id, $user->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Check whether they are members of the same group. If they are great.
                $intersect = array_intersect($mygroups, $usergroups);
                if (empty($intersect)) {
                    // But they're not... if it was a specific course throw an error otherwise
                    // just skip this course so that it is not searched.
                    if ($musthaveaccess) {
                        throw new \moodle_exception("groupnotamember", '', $CFG->wwwroot . "/course/view.php?id=$course->id");
                    }
                    continue;
                }
            }
        }
        // Woo hoo we got this far which means the current user can search this
        // this course for the requested user. Although this is only the course accessibility
        // handling that is complete, the digestforum accessibility tests are yet to come.
        $return->courses[$course->id] = $course;
    }
    // No longer beed $courses array - lose it not it may be big.
    unset($courses);

    // Make sure that we have some courses to search.
    if (empty($return->courses)) {
        // If we don't have any courses to search then the reality is that the current
        // user doesn't have access to any courses is which the requested user has posted.
        // Although we do know at this point that the requested user has posts.
        if ($musthaveaccess) {
            throw new \moodle_exception('permissiondenied');
        } else {
            return $return;
        }
    }

    // Next step: Collect all of the digestforums that we will want to search.
    // It is important to note that this step isn't actually about searching, it is
    // about determining which digestforums we can search by testing accessibility.
    $digestforums = digestforum_get_digestforums_user_posted_in($user, array_keys($return->courses), $discussionsonly);

    // Will be used to build the where conditions for the search.
    $digestforumsearchwhere = [];
    // Will be used to store the where condition params for the search.
    $digestforumsearchparams = [];
    // Will record digestforums where the user can freely access everything.
    $digestforumsearchfullaccess = [];
    // DB caching friendly.
    $now = floor(time() / 60) * 60;
    // For each course to search we want to find the digestforums the user has posted in
    // and providing the current user can access the digestforum create a search condition
    // for the digestforum to get the requested users posts.
    foreach ($return->courses as $course) {
        // Now we need to get the digestforums.
        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->instances['digestforum'])) {
            // Hmmm, no digestforums? well at least its easy... skip!
            continue;
        }
        // Iterate.
        foreach ($modinfo->get_instances_of('digestforum') as $digestforumid => $cm) {
            if (!$cm->uservisible || !isset($digestforums[$digestforumid])) {
                continue;
            }
            // Get the digestforum in question.
            $digestforum = $digestforums[$digestforumid];

            // This is needed for functionality later on in the digestforum code. It is converted to an object
            // because the cm_info is readonly from 2.6. This is a dirty hack because some other parts of the
            // code were expecting an writeable object. See {@see digestforum_print_post()}.
            $digestforum->cm = new stdClass();
            foreach ($cm as $key => $value) {
                $digestforum->cm->$key = $value;
            }

            // Check that either the current user can view the digestforum, or that the
            // current user has capabilities over the requested user and the requested
            // user can view the discussion.
            if (
                !has_capability(
                    'mod/digestforum:viewdiscussion',
                    $cm->context,
                ) && !($hascapsonuser && has_capability('mod/digestforum:viewdiscussion', $cm->context, $user->id))
            ) {
                continue;
            }

            // This will contain digestforum specific where clauses.
            $digestforumsearchselect = [];
            if (!$iscurrentuser && !$hascapsonuser) {
                // Make sure we check group access.
                if (
                    groups_get_activity_groupmode(
                        $cm,
                        $course,
                    ) == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $cm->context)
                ) {
                    $groups = $modinfo->get_groups($cm->groupingid);
                    $groups[] = -1;
                    [
                        $groupidsql,
                        $groupidparams,
                    ] = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'grps' . $digestforumid . '_');
                    $digestforumsearchparams = array_merge($digestforumsearchparams, $groupidparams);
                    $digestforumsearchselect[] = "d.groupid $groupidsql";
                }

                // Hidden timed discussions.
                if (
                    !empty($CFG->digestforum_enabletimedposts) && !has_capability(
                        'mod/digestforum:viewhiddentimedposts',
                        $cm->context,
                    )
                ) {
                    $digestforumsearchselect[] = "(d.userid = :userid{$digestforumid} " .
                        "OR (d.timestart < :timestart{$digestforumid} " .
                        "AND (d.timeend = 0 OR d.timeend > :timeend{$digestforumid})))";
                    $digestforumsearchparams['userid' . $digestforumid] = $user->id;
                    $digestforumsearchparams['timestart' . $digestforumid] = $now;
                    $digestforumsearchparams['timeend' . $digestforumid] = $now;
                }

                // Qanda access.
                if ($digestforum->type == 'qanda' && !has_capability('mod/digestforum:viewqandawithoutposting', $cm->context)) {
                    // We need to check whether the user has posted in the qanda digestforum.
                    $discussionspostedin = digestforum_discussions_user_has_posted_in($digestforum->id, $user->id);
                    if (!empty($discussionspostedin)) {
                        // Holds discussion ids for the discussions the user is allowed to see in this digestforum.
                        $digestforumonlydiscussions = [];
                        foreach ($discussionspostedin as $d) {
                            $digestforumonlydiscussions[] = $d->id;
                        }
                        [
                            $discussionidsql,
                            $discussionidparams,
                        ] = $DB->get_in_or_equal($digestforumonlydiscussions, SQL_PARAMS_NAMED, 'qanda' . $digestforumid . '_');
                        $digestforumsearchparams = array_merge($digestforumsearchparams, $discussionidparams);
                        $digestforumsearchselect[] = "(d.id $discussionidsql OR p.parent = 0)";
                    } else {
                        $digestforumsearchselect[] = "p.parent = 0";
                    }
                }

                if (count($digestforumsearchselect) > 0) {
                    $digestforumsearchwhere[] = "(d.digestforum = :digestforum{$digestforumid} AND " .
                        implode(" AND ", $digestforumsearchselect) . ")";
                    $digestforumsearchparams['digestforum' . $digestforumid] = $digestforumid;
                } else {
                    $digestforumsearchfullaccess[] = $digestforumid;
                }
            } else {
                // The current user/parent can see all of their own posts.
                $digestforumsearchfullaccess[] = $digestforumid;
            }
        }
    }

    // If we dont have any search conditions, and we don't have any digestforums where
    // the user has full access then we just return the default.
    if (empty($digestforumsearchwhere) && empty($digestforumsearchfullaccess)) {
        return $return;
    }

    // Prepare a where condition for the full access digestforums.
    if (count($digestforumsearchfullaccess) > 0) {
        [$fullidsql, $fullidparams] = $DB->get_in_or_equal($digestforumsearchfullaccess, SQL_PARAMS_NAMED, 'fula');
        $digestforumsearchparams = array_merge($digestforumsearchparams, $fullidparams);
        $digestforumsearchwhere[] = "(d.digestforum $fullidsql)";
    }

    // Prepare SQL to both count and search.
    // We alias user.id to useridx because we digestforum_posts already has a userid field and not aliasing this would break
    // mssql.
    $userfieldsapi = \core_user\fields::for_userpic();
    $userfields = $userfieldsapi->get_sql('u', false, '', 'useridx', false)->selects;
    $countsql = 'SELECT COUNT(*) ';
    $selectsql = 'SELECT p.*, d.digestforum, d.name AS discussionname, ' . $userfields . ' ';
    $wheresql = implode(" OR ", $digestforumsearchwhere);

    if ($discussionsonly) {
        if ($wheresql == '') {
            $wheresql = 'p.parent = 0';
        } else {
            $wheresql = 'p.parent = 0 AND (' . $wheresql . ')';
        }
    }

    $sql = "FROM {digestforum_posts} p
            JOIN {digestforum_discussions} d ON d.id = p.discussion
            JOIN {user} u ON u.id = p.userid
           WHERE ($wheresql)
             AND p.userid = :userid ";
    $orderby = "ORDER BY p.modified DESC";
    $digestforumsearchparams['userid'] = $user->id;

    // Set the total number posts made by the requested user that the current user can see.
    $return->totalcount = $DB->count_records_sql($countsql . $sql, $digestforumsearchparams);
    // Set the collection of posts that has been requested.
    $return->posts = $DB->get_records_sql($selectsql . $sql . $orderby, $digestforumsearchparams, $limitfrom, $limitnum);

    // We need to build an array of digestforums for which posts will be displayed.
    // We do this here to save the caller needing to retrieve them themselves before
    // printing these digestforums posts. Given we have the digestforums already there is
    // practically no overhead here.
    foreach ($return->posts as $post) {
        if (!array_key_exists($post->digestforum, $return->digestforums)) {
            $return->digestforums[$post->digestforum] = $digestforums[$post->digestforum];
        }
    }

    return $return;
}

/**
 * Set the per-digestforum maildigest option for the specified user.
 *
 * @param stdClass $digestforum The digestforum to set the option for.
 * @param int $maildigest The maildigest option.
 * @param stdClass $user The user object. This defaults to the global $USER object.
 * @throws invalid_digest_setting thrown if an invalid maildigest option is provided.
 */
function digestforum_set_user_maildigest($digestforum, $maildigest, $user = null) {
    global $DB, $USER;

    if (is_number($digestforum)) {
        $digestforum = $DB->get_record('digestforum', ['id' => $digestforum]);
    }

    if ($user === null) {
        $user = $USER;
    }

    $course  = $DB->get_record('course', ['id' => $digestforum->course], '*', MUST_EXIST);
    $cm      = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // User must be allowed to see this digestforum.
    require_capability('mod/digestforum:viewdiscussion', $context, $user->id);

    // Validate the maildigest setting.
    $digestoptions = digestforum_get_user_digest_options($user);

    if (!isset($digestoptions[$maildigest])) {
        throw new moodle_exception('invaliddigestsetting', 'mod_digestforum');
    }

    // Attempt to retrieve any existing digestforum digest record.
    $subscription = $DB->get_record('digestforum_digests', [
        'userid' => $user->id,
        'digestforum' => $digestforum->id,
    ]);

    // Create or Update the existing maildigest setting.
    if ($subscription) {
        if ($maildigest == -1) {
            $DB->delete_records('digestforum_digests', ['digestforum' => $digestforum->id, 'userid' => $user->id]);
        } else if ($maildigest !== $subscription->maildigest) {
            // Only update the maildigest setting if it's changed.

            $subscription->maildigest = $maildigest;
            $DB->update_record('digestforum_digests', $subscription);
        }
    } else {
        if ($maildigest != -1) {
            // Only insert the maildigest setting if it's non-default.

            $subscription = new stdClass();
            $subscription->digestforum = $digestforum->id;
            $subscription->userid = $user->id;
            $subscription->maildigest = $maildigest;
            $subscription->id = $DB->insert_record('digestforum_digests', $subscription);
        }
    }
}

/**
 * Determine the maildigest setting for the specified user against the
 * specified digestforum.
 *
 * @param Array $digests An array of digestforums and user digest settings.
 * @param stdClass $user The user object containing the id and maildigest default.
 * @param int $digestforumid The ID of the digestforum to check.
 * @return int The calculated maildigest setting for this user and digestforum.
 */
function digestforum_get_user_maildigest_bulk($digests, $user, $digestforumid) {
    // Everyone receives the complete digest, whatever their preferences.
    return 1;
}

/**
 * Retrieve the list of available user digest options.
 *
 * @param stdClass $user The user object. This defaults to the global $USER object.
 * @return array The mapping of values to digest options.
 */
function digestforum_get_user_digest_options($user = null) {
    global $USER;

    // Revert to the global user object.
    if ($user === null) {
        $user = $USER;
    }

    $digestoptions = [];
    $digestoptions['0']  = get_string('emaildigestoffshort', 'mod_digestforum');
    $digestoptions['1']  = get_string('emaildigestcompleteshort', 'mod_digestforum');
    $digestoptions['2']  = get_string('emaildigestsubjectsshort', 'mod_digestforum');

    // We need to add the default digest option at the end - it relies on
    // the contents of the existing values.
    $digestoptions['-1'] = get_string(
        'emaildigestdefault',
        'mod_digestforum',
        $digestoptions['1']
    ); // Force all users to get a digest.

    // Resort the options to be in a sensible order.
    ksort($digestoptions);

    return $digestoptions;
}

/**
 * Determine the current context if one was not already specified.
 *
 * If a context of type context_module is specified, it is immediately
 * returned and not checked.
 *
 * @param int $digestforumid The ID of the digestforum
 * @param context_module $context The current context.
 * @return context_module The context determined
 */
function digestforum_get_context($digestforumid, $context = null) {
    global $PAGE;

    if (!$context || !($context instanceof context_module)) {
        // Find out digestforum context. First try to take current page context to save on DB query.
        if (
            $PAGE->cm && $PAGE->cm->modname === 'digestforum' && $PAGE->cm->instance == $digestforumid
                && $PAGE->context->contextlevel == CONTEXT_MODULE && $PAGE->context->instanceid == $PAGE->cm->id
        ) {
            $context = $PAGE->context;
        } else {
            $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
            $context = \context_module::instance($cm->id);
        }
    }

    return $context;
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $digestforum   digestforum object
 * @param  stdClass $course  course object
 * @param  stdClass $cm      course module object
 * @param  stdClass $context context object
 * @since Moodle 2.9
 */
function digestforum_view($digestforum, $course, $cm, $context) {

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

    // Trigger course_module_viewed event.

    $params = [
        'context' => $context,
        'objectid' => $digestforum->id,
    ];

    $event = \mod_digestforum\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('digestforum', $digestforum);
    $event->trigger();
}

/**
 * Trigger the discussion viewed event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $digestforum      digestforum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 2.9
 */
function digestforum_discussion_view($modcontext, $digestforum, $discussion) {
    $params = [
        'context' => $modcontext,
        'objectid' => $discussion->id,
    ];

    $event = \mod_digestforum\event\discussion_viewed::create($params);
    $event->add_record_snapshot('digestforum_discussions', $discussion);
    $event->add_record_snapshot('digestforum', $digestforum);
    $event->trigger();
}

/**
 * Set the discussion to pinned and trigger the discussion pinned event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $digestforum      digestforum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 3.1
 */
function digestforum_discussion_pin($modcontext, $digestforum, $discussion) {
    global $DB;

    $DB->set_field('digestforum_discussions', 'pinned', DFORUM_DISCUSSION_PINNED, ['id' => $discussion->id]);

    $params = [
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'other' => ['digestforumid' => $digestforum->id],
    ];

    $event = \mod_digestforum\event\discussion_pinned::create($params);
    $event->add_record_snapshot('digestforum_discussions', $discussion);
    $event->trigger();
}

/**
 * Set discussion to unpinned and trigger the discussion unpin event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $digestforum      digestforum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 3.1
 */
function digestforum_discussion_unpin($modcontext, $digestforum, $discussion) {
    global $DB;

    $DB->set_field('digestforum_discussions', 'pinned', DFORUM_DISCUSSION_UNPINNED, ['id' => $discussion->id]);

    $params = [
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'other' => ['digestforumid' => $digestforum->id],
    ];

    $event = \mod_digestforum\event\discussion_unpinned::create($params);
    $event->add_record_snapshot('digestforum_discussions', $discussion);
    $event->trigger();
}

/**
 * Add nodes to myprofile page.
 *
 * @param \core_user\output\myprofile\tree $tree Tree object
 * @param stdClass $user user object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 *
 * @return bool
 */
function mod_digestforum_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (isguestuser($user)) {
        // The guest user cannot post, so it is not possible to view any posts.
        // May as well just bail aggressively here.
        return false;
    }
    $postsurl = new moodle_url('/mod/digestforum/user.php', ['id' => $user->id]);
    if (!empty($course)) {
        $postsurl->param('course', $course->id);
    }
    $string = get_string('digestforumposts', 'mod_digestforum');
    $node = new core_user\output\myprofile\node('miscellaneous', 'digestforumposts', $string, null, $postsurl);
    $tree->add_node($node);

    $discussionssurl = new moodle_url('/mod/digestforum/user.php', ['id' => $user->id, 'mode' => 'discussions']);
    if (!empty($course)) {
        $discussionssurl->param('course', $course->id);
    }
    $string = get_string('myprofileotherdis', 'mod_digestforum');
    $node = new core_user\output\myprofile\node(
        'miscellaneous',
        'digestforumdiscussions',
        $string,
        null,
        $discussionssurl
    );
    $tree->add_node($node);

    return true;
}

/**
 * Checks whether the author's name and picture for a given post should be hidden or not.
 *
 * @param object $post The digestforum post.
 * @param object $digestforum The digestforum object.
 * @return bool
 * @throws coding_exception
 */
function digestforum_is_author_hidden($post, $digestforum) {
    if (!isset($post->parent)) {
        throw new coding_exception('$post->parent must be set.');
    }
    if (!isset($digestforum->type)) {
        throw new coding_exception('$digestforum->type must be set.');
    }
    if ($digestforum->type === 'single' && empty($post->parent)) {
        return true;
    }
    return false;
}

/**
 * Manage inplace editable saves.
 *
 * @param   string      $itemtype       The type of item.
 * @param   int         $itemid         The ID of the item.
 * @param   mixed       $newvalue       The new value
 * @return  string
 */
function mod_digestforum_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB, $PAGE;

    if ($itemtype === 'digestoptions') {
        // The itemid is the digestforumid.
        $digestforum   = $DB->get_record('digestforum', ['id' => $itemid], '*', MUST_EXIST);
        $course  = $DB->get_record('course', ['id' => $digestforum->course], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        $PAGE->set_context($context);
        require_login($course, false, $cm);
        digestforum_set_user_maildigest($digestforum, $newvalue);

        $renderer = $PAGE->get_renderer('mod_digestforum');
        return $renderer->render_digest_options($digestforum, $newvalue);
    }
}

/**
 * Determine whether the specified digestforum's cutoff date is reached.
 *
 * @param stdClass $digestforum The digestforum
 * @return bool
 */
function digestforum_is_cutoff_date_reached($digestforum) {
    $entityfactory = \mod_digestforum\local\container::get_entity_factory();
    $coursemoduleinfo = get_fast_modinfo($digestforum->course);
    $cminfo = $coursemoduleinfo->instances['digestforum'][$digestforum->id];
    $digestforumentity = $entityfactory->get_digestforum_from_stdclass(
        $digestforum,
        context_module::instance($cminfo->id),
        $cminfo->get_course_module_record(),
        $cminfo->get_course()
    );

    return $digestforumentity->is_cutoff_date_reached();
}

/**
 * Determine whether the specified digestforum's due date is reached.
 *
 * @param stdClass $digestforum The digestforum
 * @return bool
 */
function digestforum_is_due_date_reached($digestforum) {
    $entityfactory = \mod_digestforum\local\container::get_entity_factory();
    $coursemoduleinfo = get_fast_modinfo($digestforum->course);
    $cminfo = $coursemoduleinfo->instances['digestforum'][$digestforum->id];
    $digestforumentity = $entityfactory->get_digestforum_from_stdclass(
        $digestforum,
        context_module::instance($cminfo->id),
        $cminfo->get_course_module_record(),
        $cminfo->get_course()
    );

    return $digestforumentity->is_due_date_reached();
}

/**
 * Determine whether the specified discussion is time-locked.
 *
 * @param   stdClass    $digestforum          The digestforum that the discussion belongs to
 * @param   stdClass    $discussion     The discussion to test
 * @return  bool
 */
function digestforum_discussion_is_locked($digestforum, $discussion) {
    $entityfactory = \mod_digestforum\local\container::get_entity_factory();
    $coursemoduleinfo = get_fast_modinfo($digestforum->course);
    $cminfo = $coursemoduleinfo->instances['digestforum'][$digestforum->id];
    $digestforumentity = $entityfactory->get_digestforum_from_stdclass(
        $digestforum,
        context_module::instance($cminfo->id),
        $cminfo->get_course_module_record(),
        $cminfo->get_course()
    );
    $discussionentity = $entityfactory->get_discussion_from_stdclass($discussion);

    return $digestforumentity->is_discussion_locked($discussionentity);
}

/**
 * Check if the module has any update that affects the current user since a given time.
 *
 * @param  cm_info $cm course module data
 * @param  int $from the time to check updates from
 * @param  array $filter  if we need to check only specific updates
 * @return stdClass an object with the different type of areas indicating if they were updated or not
 * @since Moodle 3.2
 */
function digestforum_check_updates_since(cm_info $cm, $from, $filter = []) {

    $context = $cm->context;
    $updates = new stdClass();
    if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
        return $updates;
    }

    $updates = course_check_module_updates_since($cm, $from, [], $filter);

    // Check if there are new discussions in the digestforum.
    $updates->discussions = (object) ['updated' => false];
    $discussions = digestforum_get_discussions($cm, '', false, -1, -1, true, -1, 0, DFORUM_POSTS_ALL_USER_GROUPS, $from);
    if (!empty($discussions)) {
        $updates->discussions->updated = true;
        $updates->discussions->itemids = array_keys($discussions);
    }

    return $updates;
}

/**
 * Check if the user can create attachments in a digestforum.
 * @param  stdClass $digestforum   digestforum object
 * @param  stdClass $context context object
 * @return bool true if the user can create attachments, false otherwise
 * @since  Moodle 3.3
 */
function digestforum_can_create_attachment($digestforum, $context) {
    // If maxbytes == 1 it means no attachments at all.
    if (
        empty($digestforum->maxattachments) || $digestforum->maxbytes == 1 ||
            !has_capability('mod/digestforum:createattachment', $context)
    ) {
        return false;
    }
    return true;
}

/**
 * Get icon mapping for font-awesome.
 *
 * @return  array
 */
function mod_digestforum_get_fontawesome_icon_map() {
    return [
        'mod_digestforum:i/pinned' => 'fa-thumbtack',
        'mod_digestforum:t/selected' => 'fa-check',
        'mod_digestforum:t/star' => 'fa-star',
        'mod_digestforum:t/subscribed' => 'fa-regular fa-envelope',
        'mod_digestforum:t/unsubscribed' => 'fa-regular fa-envelope-open',
    ];
}

/**
 * Callback function that determines whether an action event should be showing its item count
 * based on the event type and the item count.
 *
 * @param calendar_event $event The calendar event.
 * @param int $itemcount The item count associated with the action event.
 * @return bool
 */
function mod_digestforum_core_calendar_event_action_shows_item_count(calendar_event $event, $itemcount = 0) {
    // Always show item count for digestforums if item count is greater than 1.
    // If only one action is required than it is obvious and we don't show it for other modules.
    return $itemcount > 1;
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid User id to use for all capability checks, etc. Set to 0 for current user (default).
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_digestforum_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    int $userid = 0
) {
    global $DB, $USER;

    if (!$userid) {
        $userid = $USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['digestforum'][$event->instance];

    if (!$cm->uservisible) {
        // The module is not visible to the user for any reason.
        return null;
    }

    $context = context_module::instance($cm->id);

    if (!has_capability('mod/digestforum:viewdiscussion', $context, $userid)) {
        return null;
    }

    $completion = new \completion_info($cm->get_course());

    $completiondata = $completion->get_data($cm, false, $userid);

    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    // Get action itemcount.
    $itemcount = 0;
    $digestforum = $DB->get_record('digestforum', ['id' => $cm->instance]);
    $postcountsql = "
                SELECT
                    COUNT(1)
                  FROM
                    {digestforum_posts} fp
                    INNER JOIN {digestforum_discussions} fd ON fp.discussion=fd.id
                 WHERE
                    fp.userid=:userid AND fd.digestforum=:digestforumid";
    $postcountparams = ['userid' => $userid, 'digestforumid' => $digestforum->id];

    if ($digestforum->completiondiscussions) {
        $count = $DB->count_records('digestforum_discussions', ['digestforum' => $digestforum->id, 'userid' => $userid]);
        $itemcount += ($digestforum->completiondiscussions >= $count) ? ($digestforum->completiondiscussions - $count) : 0;
    }

    if ($digestforum->completionreplies) {
        $count = $DB->get_field_sql($postcountsql . ' AND fp.parent<>0', $postcountparams);
        $itemcount += ($digestforum->completionreplies >= $count) ? ($digestforum->completionreplies - $count) : 0;
    }

    if ($digestforum->completionposts) {
        $count = $DB->get_field_sql($postcountsql, $postcountparams);
        $itemcount += ($digestforum->completionposts >= $count) ? ($digestforum->completionposts - $count) : 0;
    }

    // Well there is always atleast one actionable item (view digestforum, etc).
    $itemcount = $itemcount > 0 ? $itemcount : 1;

    return $factory->create_instance(
        get_string('view'),
        new \moodle_url('/mod/digestforum/view.php', ['id' => $cm->id]),
        $itemcount,
        true
    );
}

/**
 * Add a get_coursemodule_info function in case any digestforum type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function digestforum_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionposts, completiondiscussions, completionreplies, duedate, cutoffdate, ' .
        'trackingtype';
    if (!$digestforum = $DB->get_record('digestforum', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $digestforum->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('digestforum', $digestforum, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completiondiscussions'] = $digestforum->completiondiscussions;
        $result->customdata['customcompletionrules']['completionreplies'] = $digestforum->completionreplies;
        $result->customdata['customcompletionrules']['completionposts'] = $digestforum->completionposts;
    }

    // Populate some other values that can be used in calendar or on dashboard.
    if ($digestforum->duedate) {
        $result->customdata['duedate'] = $digestforum->duedate;
    }
    if ($digestforum->cutoffdate) {
        $result->customdata['cutoffdate'] = $digestforum->cutoffdate;
    }
    // Add the digestforum type to the custom data for Web Services (core_course_get_contents).
    $result->customdata['trackingtype'] = $digestforum->trackingtype;

    return $result;
}

/**
 * Callback which returns human-readable strings describing the active completion custom rules for the module instance.
 *
 * @param cm_info|stdClass $cm object with fields ->completion and ->customdata['customcompletionrules']
 * @return array $descriptions the array of descriptions for the custom rules.
 */
function mod_digestforum_get_completion_active_rule_descriptions($cm) {
    // Values will be present in cm_info, and we assume these are up to date.
    if (
        empty($cm->customdata['customcompletionrules'])
        || $cm->completion != COMPLETION_TRACKING_AUTOMATIC
    ) {
        return [];
    }

    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $val) {
        switch ($key) {
            case 'completiondiscussions':
                if (!empty($val)) {
                    $descriptions[] = get_string('completiondiscussionsdesc', 'digestforum', $val);
                }
                break;
            case 'completionreplies':
                if (!empty($val)) {
                    $descriptions[] = get_string('completionrepliesdesc', 'digestforum', $val);
                }
                break;
            case 'completionposts':
                if (!empty($val)) {
                    $descriptions[] = get_string('completionpostsdesc', 'digestforum', $val);
                }
                break;
            default:
                break;
        }
    }
    return $descriptions;
}

/**
 * Check whether the digestforum post is a private reply visible to this user.
 *
 * @param   stdClass    $post   The post to check.
 * @param   cm_info     $cm     The context module instance.
 * @return  bool                Whether the post is visible in terms of private reply configuration.
 */
function digestforum_post_is_visible_privately($post, $cm) {
    global $USER;

    if (!empty($post->privatereplyto)) {
        // Allow the user to see the private reply if:
        // * they hold the permission;
        // * they are the author; or
        // * they are the intended recipient.
        $cansee = false;
        $cansee = $cansee || ($post->userid == $USER->id);
        $cansee = $cansee || ($post->privatereplyto == $USER->id);
        $cansee = $cansee || has_capability('mod/digestforum:readprivatereplies', context_module::instance($cm->id));
        return $cansee;
    }

    return true;
}

/**
 * Check whether the user can reply privately to the parent post.
 *
 * @param   \context_module $context
 * @param   \stdClass   $parent
 * @return  bool
 */
function digestforum_user_can_reply_privately(\context_module $context, \stdClass $parent): bool {
    if ($parent->privatereplyto) {
        // You cannot reply privately to a post which is, itself, a private reply.
        return false;
    }

    return has_capability('mod/digestforum:postprivatereply', $context);
}

/**
 * This function calculates the minimum and maximum cutoff values for the timestart of
 * the given event.
 *
 * It will return an array with two values, the first being the minimum cutoff value and
 * the second being the maximum cutoff value. Either or both values can be null, which
 * indicates there is no minimum or maximum, respectively.
 *
 * If a cutoff is required then the function must return an array containing the cutoff
 * timestamp and error string to display to the user if the cutoff value is violated.
 *
 * A minimum and maximum cutoff return value will look like:
 * [
 *     [1505704373, 'The date must be after this date'],
 *     [1506741172, 'The date must be before this date']
 * ]
 *
 * @param calendar_event $event The calendar event to get the time range for
 * @param stdClass $digestforum The module instance to get the range from
 * @return array Returns an array with min and max date.
 */
function mod_digestforum_core_calendar_get_valid_event_timestart_range(\calendar_event $event, \stdClass $digestforum) {
    global $CFG;

    require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

    $mindate = null;
    $maxdate = null;

    if ($event->eventtype == DFORUM_EVENT_TYPE_DUE) {
        if (!empty($digestforum->cutoffdate)) {
            $maxdate = [
                $digestforum->cutoffdate,
                get_string('cutoffdatevalidation', 'digestforum'),
            ];
        }
    }

    return [$mindate, $maxdate];
}

/**
 * This function will update the digestforum module according to the
 * event that has been modified.
 *
 * It will set the timeclose value of the digestforum instance
 * according to the type of event provided.
 *
 * @throws \moodle_exception
 * @param \calendar_event $event
 * @param stdClass $digestforum The module instance to get the range from
 */
function mod_digestforum_core_calendar_event_timestart_updated(\calendar_event $event, \stdClass $digestforum) {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

    if ($event->eventtype != DFORUM_EVENT_TYPE_DUE) {
        return;
    }

    $courseid = $event->courseid;
    $modulename = $event->modulename;
    $instanceid = $event->instance;

    // Something weird going on. The event is for a different module so
    // we should ignore it.
    if ($modulename != 'digestforum') {
        return;
    }

    if ($digestforum->id != $instanceid) {
        return;
    }

    $coursemodule = get_fast_modinfo($courseid)->instances[$modulename][$instanceid];
    $context = context_module::instance($coursemodule->id);

    // The user does not have the capability to modify this activity.
    if (!has_capability('moodle/course:manageactivities', $context)) {
        return;
    }

    if ($event->eventtype == DFORUM_EVENT_TYPE_DUE) {
        if ($digestforum->duedate != $event->timestart) {
            $digestforum->duedate = $event->timestart;
            $digestforum->timemodified = time();
            // Persist the instance changes.
            $DB->update_record('digestforum', $digestforum);
            $event = \core\event\course_module_updated::create_from_cm($coursemodule, $context);
            $event->trigger();
        }
    }
}

/**
 * Fetch the data used to display the discussions on the current page.
 *
 * @param   \mod_digestforum\local\entities\digestforum  $digestforum The digestforum entity
 * @param   stdClass                         $user The user to render for
 * @param   int|null                         $groupid The group to render
 * @param   int|null                         $sortorder The sort order to use when selecting the discussions in the list
 * @param   int|null                         $pageno The zero-indexed page number to use
 * @param   int|null                         $pagesize The number of discussions to show on the page
 * @return  array                            The data to use for display
 */
function mod_digestforum_get_discussion_summaries(
    \mod_digestforum\local\entities\digestforum $digestforum,
    stdClass $user,
    ?int $groupid,
    ?int $sortorder,
    ?int $pageno = 0,
    ?int $pagesize = 0
) {

    $vaultfactory = mod_digestforum\local\container::get_vault_factory();
    $discussionvault = $vaultfactory->get_discussions_in_digestforum_vault();
    $managerfactory = mod_digestforum\local\container::get_manager_factory();
    $capabilitymanager = $managerfactory->get_capability_manager($digestforum);

    $groupids = mod_digestforum_get_groups_from_groupid($digestforum, $user, $groupid);

    if (null === $groupids) {
        return $discussions = $discussionvault->get_from_digestforum_id(
            $digestforum->get_id(),
            $capabilitymanager->can_view_hidden_posts($user),
            $user->id,
            $sortorder,
            $pagesize,
            $pageno * $pagesize
        );
    } else {
        return $discussions = $discussionvault->get_from_digestforum_id_and_group_id(
            $digestforum->get_id(),
            $groupids,
            $capabilitymanager->can_view_hidden_posts($user),
            $user->id,
            $sortorder,
            $pagesize,
            $pageno * $pagesize
        );
    }
}

/**
 * Get a count of all discussions in a digestforum.
 *
 * @param   \mod_digestforum\local\entities\digestforum  $digestforum The digestforum entity
 * @param   stdClass                         $user The user to render for
 * @param   int|null                         $groupid The group to render
 * @return  int                              The number of discussions in a digestforum
 */
function mod_digestforum_count_all_discussions(
    \mod_digestforum\local\entities\digestforum $digestforum,
    stdClass $user,
    ?int $groupid,
) {

    $managerfactory = mod_digestforum\local\container::get_manager_factory();
    $capabilitymanager = $managerfactory->get_capability_manager($digestforum);
    $vaultfactory = mod_digestforum\local\container::get_vault_factory();
    $discussionvault = $vaultfactory->get_discussions_in_digestforum_vault();

    $groupids = mod_digestforum_get_groups_from_groupid($digestforum, $user, $groupid);

    if (null === $groupids) {
        return $discussionvault->get_total_discussion_count_from_digestforum_id(
            $digestforum->get_id(),
            $capabilitymanager->can_view_hidden_posts($user),
            $user->id
        );
    } else {
        return $discussionvault->get_total_discussion_count_from_digestforum_id_and_group_id(
            $digestforum->get_id(),
            $groupids,
            $capabilitymanager->can_view_hidden_posts($user),
            $user->id
        );
    }
}

/**
 * Get the list of groups to show based on the current user and requested groupid.
 *
 * @param   \mod_digestforum\local\entities\digestforum  $digestforum The digestforum entity
 * @param   stdClass                         $user The user viewing
 * @param   int|null                         $groupid The groupid requested
 * @return  array                            The list of groups to show
 */
function mod_digestforum_get_groups_from_groupid(
    \mod_digestforum\local\entities\digestforum $digestforum,
    stdClass $user,
    ?int $groupid,
): ?array {

    $effectivegroupmode = $digestforum->get_effective_group_mode();
    if (empty($effectivegroupmode)) {
        // This digestforum is not in a group mode. Show all posts always.
        return null;
    }

    if (null == $groupid) {
        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);
        // No group was specified.
        $showallgroups = (VISIBLEGROUPS == $effectivegroupmode);
        $showallgroups = $showallgroups || $capabilitymanager->can_access_all_groups($user);
        if ($showallgroups) {
            // Return null to show all groups.
            return null;
        } else {
            // No group was specified. Only show the users current groups.
            return array_keys(
                groups_get_all_groups(
                    $digestforum->get_course_id(),
                    $user->id,
                    $digestforum->get_course_module_record()->groupingid
                )
            );
        }
    } else {
        // A group was specified. Just show that group.
        return [$groupid];
    }
}

/**
 * Return a list of all the user preferences used by mod_digestforum.
 *
 * @return array
 */
function mod_digestforum_user_preferences() {
    $vaultfactory = \mod_digestforum\local\container::get_vault_factory();
    $discussionlistvault = $vaultfactory->get_discussions_in_digestforum_vault();

    $preferences = [];
    $preferences['digestforum_discussionlistsortorder'] = [
        'null' => NULL_NOT_ALLOWED,
        'default' => $discussionlistvault::SORTORDER_LASTPOST_DESC,
        'type' => PARAM_INT,
        'choices' => [
            $discussionlistvault::SORTORDER_LASTPOST_DESC,
            $discussionlistvault::SORTORDER_LASTPOST_ASC,
            $discussionlistvault::SORTORDER_CREATED_DESC,
            $discussionlistvault::SORTORDER_CREATED_ASC,
            $discussionlistvault::SORTORDER_REPLIES_DESC,
            $discussionlistvault::SORTORDER_REPLIES_ASC,
        ],
    ];
    $preferences['digestforum_useexperimentalui'] = [
        'null' => NULL_NOT_ALLOWED,
        'default' => false,
        'type' => PARAM_BOOL,
    ];

    return $preferences;
}

/**
 * Lists all gradable areas for the advanced grading methods gramework.
 *
 * @return array('string'=>'string') An array with area names as keys and descriptions as values
 */
function digestforum_grading_areas_list() {
    return [
        'digestforum' => get_string('grade_digestforum_header', 'digestforum'),
    ];
}

/**
 * Callback to fetch the activity event type lang string.
 *
 * @param string $eventtype The event type.
 * @return lang_string The event type lang string.
 */
function mod_digestforum_core_calendar_get_event_action_string(string $eventtype): string {
    global $CFG;
    require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

    $modulename = get_string('modulename', 'digestforum');

    if ($eventtype == DFORUM_EVENT_TYPE_DUE) {
        return get_string('calendardue', 'digestforum', $modulename);
    } else {
        return get_string('requiresaction', 'calendar', $modulename);
    }
}

/**
 * This callback will check the provided instance of this module
 * and make sure there are up-to-date events created for it.
 *
 * @param int $courseid Not used.
 * @param stdClass $instance Forum module instance.
 * @param stdClass $cm Course module object.
 */
function digestforum_refresh_events(int $courseid, stdClass $instance, stdClass $cm): void {
    global $CFG;

    // This function is called by cron and we need to include the locallib for calls further down.
    require_once($CFG->dirroot . '/mod/digestforum/locallib.php');

    digestforum_update_calendar($instance, $cm->id);
}

/**
 * Callback adds navigation to view user posts if the navadduserpostslinks config is on.
 *
 * @param navigation_node $usernode User node within navigation
 * @param stdClass $user User object
 * @param \core\context\user $usercontext User context
 * @param stdClass $course Current course
 * @param \core\context $coursecontext Course context
 */
function mod_digestforum_extend_navigation_user(
    navigation_node $usernode,
    stdClass $user,
    \core\context\user $usercontext,
    stdClass $course,
    \core\context $coursecontext,
): void {
    global $CFG;
    if (!empty($CFG->navadduserpostslinks) && $coursecontext instanceof \core\context\system) {
        $baseargs = ['id' => $user->id];

        // Add nodes for digestforum posts and discussions if the user can view either or both
        // There are no capability checks here as the content of the page is based
        // purely on the digestforums the current user has access too.
        $digestforumtab = \navigation_node::create(get_string('digestforumposts', 'digestforum'));
        $digestforumtab->add(
            get_string('posts', 'digestforum'),
            new moodle_url('/mod/digestforum/user.php', $baseargs),
        );
        $digestforumtab->add(
            get_string('discussions', 'digestforum'),
            new moodle_url(
                '/mod/digestforum/user.php',
                array_merge($baseargs, ['mode' => 'discussions']),
            ),
        );

        // We add the digestforum link either immediately after the 'viewuserdetails' link, or as the first item in the list.
        foreach ($usernode->children as $child) {
            if ($child->key === 'viewuserdetails') {
                continue;
            }
            $addbefore = $child;
            break;
        }
        $usernode->add_node($digestforumtab, $addbefore->key);
    }
}

/**
 * Returns a user's open rate for a specific digestforum for this month
 *
 * @param int $userid mdluserid of the user in question
 * @param int $forumid instance id of the digestforum
 * @param string $digestdate in the format of 'yyyy-mm-dd'. if omitted, then all time
 * @return int percentage open rate, rounded to a whole number, or -1 if not enough data
 */
function mod_digestforum_get_month_open_rate($userid, $forumid, $digestdate = 0) {
    global $DB;

    // Make sure $digestdate is yyyy-mm-dd or yyyy-mm-d, because strftime is weird.
    $pattern = '/^\d{4}-\d{2}-\d{1,2}$/';
    if ($digestdate != 0 && !preg_match($pattern, $digestdate)) {
        $digestdate = 0;
    }

    $openedsql = '
        SELECT COUNT(id)
          FROM {digestforum_tracker}
         WHERE digestforumid = :forumid
           AND numviews > 0
           AND mdluserid = :userid';

    $notopenedsql = '
        SELECT COUNT(id)
          FROM {digestforum_tracker}
         WHERE digestforumid = :forumid
           AND numviews = 0
           AND mdluserid = :userid';

    if ($digestdate != 0) {
        // Get for the whole month.
        $digestdate = substr($digestdate, 0, strrpos($digestdate, '-') + 1) . '%';
        $likedigestdate = $DB->sql_like('digestdate', ':digestdate');

        $openedsql .= ' AND ' . $likedigestdate;
        $notopenedsql .= ' AND ' . $likedigestdate;
    }

    $options = ['forumid' => $forumid, 'userid' => $userid, 'digestdate' => $digestdate];

    $opened     = $DB->count_records_sql($openedsql, $options);
    $notopened  = $DB->count_records_sql($notopenedsql, $options);

    $pctopened = -1;
    $total = $opened + $notopened;
    if ($total > 0) {
        $pctopened = (100 * $opened) / $total;
    }

    return (int) round($pctopened);
}

/**
 * Record that a digest email has been opened, as reported by its tracking image.
 *
 * An open only counts towards the open rate if it happens within a week of the digest date, but the first and
 * last viewed times are always updated.
 *
 * @param int $trackerid id of the digestforum_tracker record
 * @param int|null $now the time of the view. Defaults to now.
 * @return bool whether a tracker record was found
 */
function mod_digestforum_record_digest_view($trackerid, $now = null) {
    global $DB;

    $entry = $DB->get_record('digestforum_tracker', ['id' => $trackerid]);
    if (!$entry) {
        return false;
    }

    if ($now === null) {
        $now = time();
    }

    // Parse the digestdate, which is yyyy-mm-dd or yyyy-mm-d, to see if this view counts.
    $year = substr($entry->digestdate, 0, 4);
    $mon = substr($entry->digestdate, 5, 2);
    $day = substr($entry->digestdate, 8);

    $origdate = new DateTime();
    $origdate->setTimestamp($now);
    $origdate->setDate($year, $mon, $day);

    // Only count it as an open if it's been within a week.
    if (($now - (7 * DAYSECS)) < $origdate->getTimestamp()) {
        $entry->numviews++;
    }

    // The email has been viewed. Update info.
    if ($entry->firstviewed == 0) {
        $entry->firstviewed = $now;
    }
    $entry->lastviewed = $now;

    return $DB->update_record('digestforum_tracker', $entry);
}
