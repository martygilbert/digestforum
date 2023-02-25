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

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once(__DIR__ . '/deprecatedlib.php');
require_once($CFG->libdir.'/filelib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

define('DFORUM_MODE_FLATOLDEST', 1);
define('DFORUM_MODE_FLATNEWEST', -1);
define('DFORUM_MODE_THREADED', 2);
define('DFORUM_MODE_NESTED', 3);

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

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

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

    $digestforum->timemodified = time();

    if (empty($digestforum->assessed)) {
        $digestforum->assessed = 0;
    }

    if (empty($digestforum->ratingtime) or empty($digestforum->assessed)) {
        $digestforum->assesstimestart  = 0;
        $digestforum->assesstimefinish = 0;
    }

    $digestforum->id = $DB->insert_record('digestforum', $digestforum);
    $modcontext = context_module::instance($digestforum->coursemodule);

    if ($digestforum->type == 'single') {  // Create related discussion.
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

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $discussion = $DB->get_record('digestforum_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('digestforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $options = array('subdirs'=>true); // Use the same options as intro field!
            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_digestforum', 'post', $post->id, $options, $post->message);
            $DB->set_field('digestforum_posts', 'message', $post->message, array('id'=>$post->id));
        }
    }

    digestforum_grade_item_update($digestforum);

    $completiontimeexpected = !empty($digestforum->completionexpected) ? $digestforum->completionexpected : null;
    \core_completion\api::update_completion_date_event($digestforum->coursemodule, 'digestforum', $digestforum->id, $completiontimeexpected);

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
 * @global object
 * @param object $digestforum digestforum instance (with magic quotes)
 * @return bool success
 */
function digestforum_update_instance($digestforum, $mform) {
    global $DB, $OUTPUT, $USER;

    $digestforum->timemodified = time();
    $digestforum->id           = $digestforum->instance;

    if (empty($digestforum->assessed)) {
        $digestforum->assessed = 0;
    }

    if (empty($digestforum->ratingtime) or empty($digestforum->assessed)) {
        $digestforum->assesstimestart  = 0;
        $digestforum->assesstimefinish = 0;
    }

    $olddigestforum = $DB->get_record('digestforum', array('id'=>$digestforum->id));

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire digestforum
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score) when calculating the grade
    if (($olddigestforum->assessed<>$digestforum->assessed) or ($olddigestforum->scale<>$digestforum->scale)) {
        digestforum_update_grades($digestforum); // recalculate grades for the digestforum
    }

    if ($digestforum->type == 'single') {  // Update related discussion and post.
        $discussions = $DB->get_records('digestforum_discussions', array('digestforum'=>$digestforum->id), 'timemodified ASC');
        if (!empty($discussions)) {
            if (count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'digestforum'));
            }
            $discussion = array_pop($discussions);
        } else {
            // try to recover by creating initial discussion - MDL-16262
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

            if (! $discussion = $DB->get_record('digestforum_discussions', array('digestforum'=>$digestforum->id))) {
                print_error('cannotadd', 'digestforum');
            }
        }
        if (! $post = $DB->get_record('digestforum_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'digestforum');
        }

        $cm         = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $modcontext = context_module::instance($cm->id, MUST_EXIST);

        $post = $DB->get_record('digestforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);
        $post->subject       = $digestforum->name;
        $post->message       = $digestforum->intro;
        $post->messageformat = $digestforum->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $digestforum->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities.

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $options = array('subdirs'=>true); // Use the same options as intro field!
            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_digestforum', 'post', $post->id, $options, $post->message);
        }

        $DB->update_record('digestforum_posts', $post);
        $discussion->name = $digestforum->name;
        $DB->update_record('digestforum_discussions', $discussion);
    }

    $DB->update_record('digestforum', $digestforum);

    $modcontext = context_module::instance($digestforum->coursemodule);
    if (($digestforum->forcesubscribe == DFORUM_INITIALSUBSCRIBE) && ($olddigestforum->forcesubscribe <> $digestforum->forcesubscribe)) {
        $users = \mod_digestforum\subscriptions::get_potential_subscribers($modcontext, 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum, $modcontext);
        }
    }

    digestforum_grade_item_update($digestforum);

    $completiontimeexpected = !empty($digestforum->completionexpected) ? $digestforum->completionexpected : null;
    \core_completion\api::update_completion_date_event($digestforum->coursemodule, 'digestforum', $digestforum->id, $completiontimeexpected);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id digestforum instance id
 * @return bool success
 */
function digestforum_delete_instance($id) {
    global $DB;

    if (!$digestforum = $DB->get_record('digestforum', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = context_module::instance($cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    \core_completion\api::update_completion_date_event($cm->id, 'digestforum', $digestforum->id, null);

    // Delete digest and subscription preferences.
    $DB->delete_records('digestforum_digests', array('digestforum' => $digestforum->id));
    $DB->delete_records('digestforum_subscriptions', array('digestforum'=>$digestforum->id));
    $DB->delete_records('digestforum_discussion_subs', array('digestforum' => $digestforum->id));

    if ($discussions = $DB->get_records('digestforum_discussions', array('digestforum'=>$digestforum->id))) {
        foreach ($discussions as $discussion) {
            if (!digestforum_delete_discussion($discussion, true, $course, $cm, $digestforum)) {
                $result = false;
            }
        }
    }

    digestforum_tp_delete_read_records(-1, -1, -1, $digestforum->id);

    digestforum_grade_item_delete($digestforum);

    // We must delete the module record after we delete the grade item.
    if (!$DB->delete_records('digestforum', array('id'=>$digestforum->id))) {
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
 * @return mixed True if yes (some features may use other values)
 */
function digestforum_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_RATE:                    return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_PLAGIARISM:              return true;

        default: return null;
    }
}


/**
 * Obtains the automatic completion state for this digestforum based on any conditions
 * in digestforum settings.
 *
 * @global object
 * @global object
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function digestforum_get_completion_state($course,$cm,$userid,$type) {
    global $CFG,$DB;

    // Get digestforum details
    if (!($digestforum=$DB->get_record('digestforum',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find digestforum {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'digestforumid'=>$digestforum->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {digestforum_posts} fp
    INNER JOIN {digestforum_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.digestforum=:digestforumid";

    if ($digestforum->completiondiscussions) {
        $value = $digestforum->completiondiscussions <=
                 $DB->count_records('digestforum_discussions',array('digestforum'=>$digestforum->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($digestforum->completionreplies) {
        $value = $digestforum->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($digestforum->completionposts) {
        $value = $digestforum->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }

    return $result;
}

/**
 * Create a message-id string to use in the custom headers of digestforum notification emails
 *
 * message-id is used by email clients to identify emails and to nest conversations
 *
 * @param int $postid The ID of the digestforum post we are notifying the user about
 * @param int $usertoid The ID of the user being notified
 * @return string A unique message-id
 */
function digestforum_get_email_message_id($postid, $usertoid, $date = null) {
    if (empty($date)){
        $date = userdate(time(), '%Y%m%d');
    }

    return generate_email_messageid(hash('sha256', $postid . 'to' . $usertoid . 'on' .  $date));
}

/**
 * Removes properties from user record that are not necessary
 * for sending post notifications.
 * @param stdClass $user
 * @return void, $user parameter is modified
 */
function digestforum_cron_minimise_user_record(stdClass $user) {

    // We store large amount of users in one huge array,
    // make sure we do not store info there we do not actually need
    // in mail generation code or messaging.

    unset($user->institution);
    unset($user->department);
    unset($user->address);
    unset($user->city);
    unset($user->url);
    unset($user->currentlogin);
    unset($user->description);
    unset($user->descriptionformat);
}

/**
 * Function to be run periodically according to the scheduled task.
 *
 * Finds all posts that have yet to be mailed out, and mails them
 * out to all subscribers as well as other maintance tasks.
 *
 * NOTE: Since 2.7.2 this function is run by scheduled task rather
 * than standard cron.
 *
 * @todo MDL-44734 The function will be split up into seperate tasks.
 */
function digestforum_cron() {
    global $CFG, $USER, $DB, $PAGE;

    $site = get_site();

    // The main renderers.
    $htmlout = $PAGE->get_renderer('mod_digestforum', 'email', 'htmlemail');
    $textout = $PAGE->get_renderer('mod_digestforum', 'email', 'textemail');
    $htmldigestfullout = $PAGE->get_renderer('mod_digestforum', 'emaildigestfull', 'htmlemail');
    $textdigestfullout = $PAGE->get_renderer('mod_digestforum', 'emaildigestfull', 'textemail');
    $htmldigestbasicout = $PAGE->get_renderer('mod_digestforum', 'emaildigestbasic', 'htmlemail');
    $textdigestbasicout = $PAGE->get_renderer('mod_digestforum', 'emaildigestbasic', 'textemail');

    // All users that are subscribed to any post that needs sending,
    // please increase $CFG->extramemorylimit on large sites that
    // send notifications to a large number of users.
    $users = array();
    $userscount = 0; // Cached user counter - count($users) in PHP is horribly slow!!!

    // Status arrays.
    $mailcount  = array();
    $errorcount = array();

    // caches
    $discussions        = array();
    $digestforums       = array();
    $courses            = array();
    $coursemodules      = array();
    $subscribedusers    = array();
    $messageinboundhandlers = array();

    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    // Get the list of digestforum subscriptions for per-user per-digestforum maildigest settings.
    $digestsset = $DB->get_recordset('digestforum_digests', null, '', 'id, userid, digestforum, maildigest');
    $digests = array();
    foreach ($digestsset as $thisrow) {
        if (!isset($digests[$thisrow->digestforum])) {
            $digests[$thisrow->digestforum] = array();
        }
        $digests[$thisrow->digestforum][$thisrow->userid] = $thisrow->maildigest;
    }
    $digestsset->close();

    // Create the generic messageinboundgenerator.
    $messageinboundgenerator = new \core\message\inbound\address_manager();
    $messageinboundgenerator->set_handler('\mod_digestforum\message\inbound\reply_handler');

    if ($posts = digestforum_get_unmailed_posts($starttime, $endtime, $timenow)) {
        // Mark them all now as being mailed.  It's unlikely but possible there
        // might be an error later so that a post is NOT actually mailed out,
        // but since mail isn't crucial, we can accept this risk.  Doing it now
        // prevents the risk of duplicated mails, which is a worse problem.

        if (!digestforum_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');
            return false;  // Don't continue trying to mail them, in case we are in a cron loop
        }

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('digestforum_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                    \mod_digestforum\subscriptions::fill_subscription_cache($discussion->digestforum);
                    \mod_digestforum\subscriptions::fill_discussion_subscription_cache($discussion->digestforum);

                } else {
                    mtrace('Could not find discussion ' . $discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $digestforumid = $discussions[$discussionid]->digestforum;
            if (!isset($digestforums[$digestforumid])) {
                if ($digestforum = $DB->get_record('digestforum', array('id' => $digestforumid))) {
                    $digestforums[$digestforumid] = $digestforum;
                } else {
                    mtrace('Could not find digestforum '.$digestforumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $digestforums[$digestforumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$digestforumid])) {
                if ($cm = get_coursemodule_from_instance('digestforum', $digestforumid, $courseid)) {
                    $coursemodules[$digestforumid] = $cm;
                } else {
                    mtrace('Could not find course module for digestforum '.$digestforumid);
                    unset($posts[$pid]);
                    continue;
                }
            }

            $modcontext = context_module::instance($coursemodules[$digestforumid]->id);

            // Save the Inbound Message datakey here to reduce DB queries later.
            $messageinboundgenerator->set_data($pid);
            $messageinboundhandlers[$pid] = $messageinboundgenerator->fetch_data_key();

            // Caching subscribed users of each digestforum.
            if (!isset($subscribedusers[$digestforumid])) {
                if ($subusers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforums[$digestforumid], 0, $modcontext, 'u.*', true)) {

                    foreach ($subusers as $postuser) {
                        // this user is subscribed to this digestforum
                        $subscribedusers[$digestforumid][$postuser->id] = $postuser->id;
                        $userscount++;
                        if ($userscount > DFORUM_CRON_USER_CACHE) {
                            // Store minimal user info.
                            $minuser = new stdClass();
                            $minuser->id = $postuser->id;
                            $users[$postuser->id] = $minuser;
                        } else {
                            // Cache full user record.
                            digestforum_cron_minimise_user_record($postuser);
                            $users[$postuser->id] = $postuser;
                        }
                    }
                    // Release memory.
                    unset($subusers);
                    unset($postuser);
                }
            }
            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        foreach ($users as $userto) {
            // Terminate if processing of any account takes longer than 2 minutes.
            core_php_time_limit::raise(120);

            mtrace('Processing user ' . $userto->id);

            // Init user caches - we keep the cache for one cycle only, otherwise it could consume too much memory.
            if (isset($userto->username)) {
                $userto = clone($userto);
            } else {
                $userto = $DB->get_record('user', array('id' => $userto->id));
                digestforum_cron_minimise_user_record($userto);
            }
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();

            // Setup this user so that the capabilities are cached, and environment matches receiving user.
            cron_setup_user($userto);

            // Reset the caches.
            foreach ($coursemodules as $digestforumid => $unused) {
                $coursemodules[$digestforumid]->cache       = new stdClass();
                $coursemodules[$digestforumid]->cache->caps = array();
                unset($coursemodules[$digestforumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {
                $discussion = $discussions[$post->discussion];
                $digestforum      = $digestforums[$discussion->digestforum];
                $course     = $courses[$digestforum->course];
                $cm         =& $coursemodules[$digestforum->id];

                // Do some checks to see if we can bail out now.

                // Only active enrolled users are in the list of subscribers.
                // This does not necessarily mean that the user is subscribed to the digestforum or to the discussion though.
                if (!isset($subscribedusers[$digestforum->id][$userto->id])) {
                    // The user does not subscribe to this digestforum.
                    continue;
                }

                if (!\mod_digestforum\subscriptions::is_subscribed($userto->id, $digestforum, $post->discussion, $coursemodules[$digestforum->id])) {
                    // The user does not subscribe to this digestforum, or to this specific discussion.
                    continue;
                }

                if ($subscriptiontime = \mod_digestforum\subscriptions::fetch_discussion_subscription($digestforum->id, $userto->id)) {
                    // Skip posts if the user subscribed to the discussion after it was created.
                    if (isset($subscriptiontime[$post->discussion]) && ($subscriptiontime[$post->discussion] > $post->created)) {
                        continue;
                    }
                }

                $coursecontext = context_course::instance($course->id);
                if (!$course->visible and !has_capability('moodle/course:viewhiddencourses', $coursecontext, $userto->id)) {
                    // The course is hidden and the user does not have access to it.
                    continue;
                }

                // Don't send email if the digestforum is Q&A and the user has not posted.
                // Initial topics are still mailed.
                if ($digestforum->type == 'qanda' && !digestforum_get_user_posted_time($discussion->id, $userto->id) && $pid != $discussion->firstpost) {
                    mtrace('Did not email ' . $userto->id.' because user has not posted in discussion');
                    continue;
                }

                // Get info about the sending user.
                if (array_key_exists($post->userid, $users)) {
                    // We might know the user already.
                    $userfrom = $users[$post->userid];
                    if (!isset($userfrom->idnumber)) {
                        // Minimalised user info, fetch full record.
                        $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                        digestforum_cron_minimise_user_record($userfrom);
                    }

                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    digestforum_cron_minimise_user_record($userfrom);
                    // Fetch only once if possible, we can add it to user list, it will be skipped anyway.
                    if ($userscount <= DFORUM_CRON_USER_CACHE) {
                        $userscount++;
                        $users[$userfrom->id] = $userfrom;
                    }
                } else {
                    mtrace('Could not find user ' . $post->userid . ', author of post ' . $post->id . '. Unable to send message.');
                    continue;
                }

                // Note: If we want to check that userto and userfrom are not the same person this is probably the spot to do it.

                // Setup global $COURSE properly - needed for roles and languages.
                cron_setup_user($userto, $course);

                // Fill caches.
                if (!isset($userto->viewfullnames[$digestforum->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->viewfullnames[$digestforum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->canpost[$discussion->id] = digestforum_user_can_post($digestforum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$digestforum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        if (isset($users[$userfrom->id])) {
                            $users[$userfrom->id]->groups = array();
                        }
                    }
                    $userfrom->groups[$digestforum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    if (isset($users[$userfrom->id])) {
                        $users[$userfrom->id]->groups[$digestforum->id] = $userfrom->groups[$digestforum->id];
                    }
                }

                // Make sure groups allow this user to see this email.
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {
                    // Groups are being used.
                    if (!groups_group_exists($discussion->groupid)) {
                        // Can't find group - be safe and don't this message.
                        continue;
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // Do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS.
                        continue;
                    }
                }

                // Make sure we're allowed to see the post.
                if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
                    mtrace('User ' . $userto->id .' can not see ' . $post->id . '. Not sending message.');
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                $maildigest = digestforum_get_user_maildigest_bulk($digests, $userto, $digestforum->id);

                if ($maildigest > 0) {
                    // This user wants the mails to be in digest form.
                    $queue = new stdClass();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('digestforum_queue', $queue);
                    continue;
                }

                // Prepare to actually send the post now, and build up the content.

                $cleandigestforumname = str_replace('"', "'", strip_tags(format_string($digestforum->name)));

                $userfrom->customheaders = array (
                    // Headers to make emails easier to track.
                    'List-Id: "'        . $cleandigestforumname . '" ' . generate_email_messageid('moodledigestforum' . $digestforum->id),
                    'List-Help: '       . $CFG->wwwroot . '/mod/digestforum/view.php?f=' . $digestforum->id,
                    'Message-ID: '      . digestforum_get_email_message_id($digestforum->id, $userto->id),
                    'X-Course-Id: '     . $course->id,
                    'X-Course-Name: '   . format_string($course->fullname, true),

                    // Headers to help prevent auto-responders.
                    'Precedence: Bulk',
                    'X-Auto-Response-Suppress: All',
                    'Auto-Submitted: auto-generated',
                );

                $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                // Generate a reply-to address from using the Inbound Message handler.
                $replyaddress = null;
                if ($userto->canpost[$discussion->id] && array_key_exists($post->id, $messageinboundhandlers)) {
                    $messageinboundgenerator->set_data($post->id, $messageinboundhandlers[$post->id]);
                    $replyaddress = $messageinboundgenerator->generate($userto->id);
                }

                if (!isset($userto->canpost[$discussion->id])) {
                    $canreply = digestforum_user_can_post($digestforum, $discussion, $userto, $cm, $course, $modcontext);
                } else {
                    $canreply = $userto->canpost[$discussion->id];
                }

                $data = new \mod_digestforum\output\digestforum_post_email(
                        $course,
                        $cm,
                        $digestforum,
                        $discussion,
                        $post,
                        $userfrom,
                        $userto,
                        $canreply
                    );

                $userfrom->customheaders[] = sprintf('List-Unsubscribe: <%s>',
                    $data->get_unsubscribediscussionlink());

                if (!isset($userto->viewfullnames[$digestforum->id])) {
                    $data->viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
                } else {
                    $data->viewfullnames = $userto->viewfullnames[$digestforum->id];
                }

                // Not all of these variables are used in the default language
                // string but are made available to support custom subjects.
                $a = new stdClass();
                $a->subject = $data->get_subject();
                $a->digestforumname = $cleandigestforumname;
                $a->sitefullname = format_string($site->fullname);
                $a->siteshortname = format_string($site->shortname);
                $a->courseidnumber = $data->get_courseidnumber();
                $a->coursefullname = $data->get_coursefullname();
                $a->courseshortname = $data->get_coursename();
                $postsubject = html_to_text(get_string('postmailsubject', 'digestforum', $a), 0);

                $rootid = digestforum_get_email_message_id($discussion->firstpost, $userto->id);

                if ($post->parent) {
                    // This post is a reply, so add reply header (RFC 2822).
                    $parentid = digestforum_get_email_message_id($post->parent, $userto->id);
                    $userfrom->customheaders[] = "In-Reply-To: $parentid";

                    // If the post is deeply nested we also reference the parent message id and
                    // the root message id (if different) to aid threading when parts of the email
                    // conversation have been deleted (RFC1036).
                    if ($post->parent != $discussion->firstpost) {
                        $userfrom->customheaders[] = "References: $rootid $parentid";
                    } else {
                        $userfrom->customheaders[] = "References: $parentid";
                    }
                }

                // MS Outlook / Office uses poorly documented and non standard headers, including
                // Thread-Topic which overrides the Subject and shouldn't contain Re: or Fwd: etc.
                $a->subject = $discussion->name;
                $threadtopic = html_to_text(get_string('postmailsubject', 'digestforum', $a), 0);
                $userfrom->customheaders[] = "Thread-Topic: $threadtopic";
                $userfrom->customheaders[] = "Thread-Index: " . substr($rootid, 1, 28);

                // Send the post now!
                mtrace('Sending ', '');

                $eventdata = new \core\message\message();
                $eventdata->courseid            = $course->id;
                $eventdata->component           = 'mod_digestforum';
                $eventdata->name                = 'posts';
                $eventdata->userfrom            = $userfrom;
                $eventdata->userto              = $userto;
                $eventdata->subject             = $postsubject;
                $eventdata->fullmessage         = $textout->render($data);
                $eventdata->fullmessageformat   = FORMAT_PLAIN;
                $eventdata->fullmessagehtml     = $htmlout->render($data);
                $eventdata->notification        = 1;
                $eventdata->replyto             = $replyaddress;
                if (!empty($replyaddress)) {
                    // Add extra text to email messages if they can reply back.
                    $textfooter = "\n\n" . get_string('replytopostbyemail', 'mod_digestforum');
                    $htmlfooter = html_writer::tag('p', get_string('replytopostbyemail', 'mod_digestforum'));
                    $additionalcontent = array('fullmessage' => array('footer' => $textfooter),
                                     'fullmessagehtml' => array('footer' => $htmlfooter));
                    $eventdata->set_additional_content('email', $additionalcontent);
                }

                $smallmessagestrings = new stdClass();
                $smallmessagestrings->user          = fullname($userfrom);
                $smallmessagestrings->digestforumname     = "$shortname: " . format_string($digestforum->name, true) . ": " . $discussion->name;
                $smallmessagestrings->message       = $post->message;

                // Make sure strings are in message recipients language.
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'digestforum', $smallmessagestrings, $userto->lang);

                $contexturl = new moodle_url('/mod/digestforum/discuss.php', array('d' => $discussion->id), 'p' . $post->id);
                $eventdata->contexturl = $contexturl->out();
                $eventdata->contexturlname = $discussion->name;

                $mailresult = message_send($eventdata);
                if (!$mailresult) {
                    mtrace("Error: mod/digestforum/lib.php digestforum_cron(): Could not send out mail for id $post->id to user $userto->id".
                            " ($userto->email) .. not trying again.");
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;

                    // Mark post as read if digestforum_usermarksread is set off.
                    if (!$CFG->digestforum_usermarksread) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post ' . $post->id . ': ' . $post->subject);
            }

            // Mark processed posts as read.
            if (get_user_preferences('digestforum_markasreadonnotification', 1, $userto->id) == 1) {
                digestforum_tp_mark_posts_read($userto, $userto->markposts);
            }

            unset($userto);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field('digestforum_posts', 'mailed', DFORUM_MAILED_ERROR, array('id' => $post->id));
            }
        }
    }

    // release some memory
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = core_date::get_server_timezone();

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    core_php_time_limit::raise(300); // terminate if not able to fetch all digests in 5 minutes

    if (!isset($CFG->digestforum_mailtimelast)) {    // To catch the first time
        set_config('digestforum_mailtimelast', 0);
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($CFG->digestforum_mailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('digestforum_queue', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($CFG->digestforum_mailtimelast < $digesttime and $timenow > $digesttime) {
     // if (true) { // MJG - testing only!
        // $digesttime += 86400; // MJG - testing only!

        // MJG get date to add to messageID.
        $todaysdate = userdate(time(), '%Y-%m-%d');

        mtrace('Sending digestforum digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('digestforum_queue', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do.
            $usermailcount = 0;

            // caches - reuse the those filled before too.
            $discussionposts = array();
            $userdiscussions = array();
            $userforums = array(); // MJG - one digest per forum.

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('digestforum_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('digestforum_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $digestforumid = $discussions[$discussionid]->digestforum;
                if (!isset($digestforums[$digestforumid])) {
                    if ($digestforum = $DB->get_record('digestforum', array('id' => $digestforumid))) {
                        $digestforums[$digestforumid] = $digestforum;
                    } else {
                        continue;
                    }
                }

                $courseid = $digestforums[$digestforumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$digestforumid])) {
                    if ($cm = get_coursemodule_from_instance('digestforum', $digestforumid, $courseid)) {
                        $coursemodules[$digestforumid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                // MJG - one email per forum.
                $userforums[$digestpost->userid][$discussions[$discussionid]->digestforum][$digestpost->discussionid]
                    = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); // Finished iteration, let's close the resultset.

            // Data collected, start sending out emails to each user.
            foreach ($userforums as $userid => $digestforuminstanceids) {
                foreach ($digestforuminstanceids as $thesediscussions) {

                    core_php_time_limit::raise(120); // Terminate if processing of any account takes longer than 2 minutes.

                    cron_setup_user();

                    mtrace(get_string('processingdigest', 'digestforum', $userid), '... ');

                    // First of all delete all the queue entries for this user.
                    $DB->delete_records_select('digestforum_queue', "userid = ? AND timemodified < ?", array($userid, $digesttime));

                    // Init user caches - we keep the cache for one cycle only,
                    // otherwise it would unnecessarily consume memory.
                    if (array_key_exists($userid, $users) and isset($users[$userid]->username)) {
                        $userto = clone($users[$userid]);
                    } else {
                        $userto = $DB->get_record('user', array('id' => $userid));
                        digestforum_cron_minimise_user_record($userto);
                    }
                    $userto->viewfullnames = array();
                    $userto->canpost       = array();
                    $userto->markposts     = array();

                    // Override the language and timezone of the "current" user, so that
                    // mail is customised for the receiver.
                    cron_setup_user($userto);

                    // MJG.
                    $firstdisc = reset($thesediscussions);

                    $digestforumname = $digestforums[$discussions[$firstdisc]->digestforum]->name;
                    $digestforumid = $digestforums[$discussions[$firstdisc]->digestforum]->id;

                    $subjparams = new stdClass();
                    $subjparams->sitename = format_string($site->shortname, true);
                    $subjparams->digestforumname = format_string($digestforumname, true);
                    $subjparams->date = userdate(time(), "%a %b %e, %Y");

                    $postsubject = get_string('digestmailsubject', 'digestforum', $subjparams);
                    // End MJG.

                    $headerdata = new stdClass();
                    $headerdata->sitename = format_string($site->fullname, true);
                    $headerdata->date = userdate(time(), "%a %b %e, %Y"); // MJG.
                    $headerdata->digestforumname = $digestforumname; // MJG.
                    $headerdata->openrate = mod_digestforum_get_month_open_rate($userid , $digestforumid, $todaysdate);

                    $posttext = get_string('digestmailheader', 'digestforum')."\n\n";
                    $posthtml = "\n";
                    $posthtml .= '<h3>'.get_string('digestmailheader', 'digestforum', $headerdata).'</h3>';
                    $posthtml .= "\n";

                    if ($headerdata->openrate >= 0) {
                        $posthtml .= "\n";
                        $posthtml .= '<div class="subject" style="font-weight: bold; font-size: larger">'.
                            get_string('digestmailheaderstat', 'digestforum', $headerdata).'</div>';
                        $posthtml .= "\n";
                        $posttext .= get_string('digestmailheaderstat', 'digestforum', $headerdata)."\n\n";
                    }
                    $posthtml .= "\n";
                    $posthtml .= '<br><hr style="height: 3px; width: 100%; color:#000; background-color:#000">';
                    $posthtml .= "\n";

                    foreach ($thesediscussions as $discussionid) {

                        core_php_time_limit::raise(120);   // To be reset for each post.

                        $discussion = $discussions[$discussionid];
                        $digestforum      = $digestforums[$discussion->digestforum];
                        $course     = $courses[$digestforum->course];
                        $cm         = $coursemodules[$digestforum->id];

                        // Override language.
                        cron_setup_user($userto, $course);

                        // Fill caches.
                        if (!isset($userto->viewfullnames[$digestforum->id])) {
                            $modcontext = context_module::instance($cm->id);
                            $userto->viewfullnames[$digestforum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                        }
                        if (!isset($userto->canpost[$discussion->id])) {
                            $modcontext = context_module::instance($cm->id);
                            $userto->canpost[$discussion->id] = digestforum_user_can_post($digestforum,
                                $discussion, $userto, $cm, $course, $modcontext);
                        }

                        $strdigestforums      = get_string('digestforums', 'digestforum');
                        $canunsubscribe = ! \mod_digestforum\subscriptions::is_forcesubscribed($digestforum);
                        $canreply       = $userto->canpost[$discussion->id];
                        $shortname = format_string($course->shortname, true,
                            array('context' => context_course::instance($course->id)));

                        $posttext .= "\n \n";
                        $posttext .= '=====================================================================';
                        $posttext .= "\n \n";
                        $posttext  .= " -> ".format_string($discussion->name, true);
                        $posttext .= "\n";
                        $posttext .= $CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$discussion->id;
                        $posttext .= "\n";

                        $postsarray = $discussionposts[$discussionid];
                        sort($postsarray);
                        $sentcount = 0;

                        foreach ($postsarray as $postid) {
                            $post = $posts[$postid];

                            if (array_key_exists($post->userid, $users)) { // We might know him/her already.
                                $userfrom = $users[$post->userid];
                                if (!isset($userfrom->idnumber)) {
                                    $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                                    digestforum_cron_minimise_user_record($userfrom);
                                }

                            } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                                digestforum_cron_minimise_user_record($userfrom);
                                if ($userscount <= DFORUM_CRON_USER_CACHE) {
                                    $userscount++;
                                    $users[$userfrom->id] = $userfrom;
                                }

                            } else {
                                mtrace('Could not find user '.$post->userid);
                                continue;
                            }

                            if (!isset($userfrom->groups[$digestforum->id])) {
                                if (!isset($userfrom->groups)) {
                                    $userfrom->groups = array();
                                    if (isset($users[$userfrom->id])) {
                                        $users[$userfrom->id]->groups = array();
                                    }
                                }
                                $userfrom->groups[$digestforum->id] = groups_get_all_groups($course->id,
                                    $userfrom->id, $cm->groupingid);
                                if (isset($users[$userfrom->id])) {
                                    $users[$userfrom->id]->groups[$digestforum->id] = $userfrom->groups[$digestforum->id];
                                }
                            }

                            // Headers to help prevent auto-responders.
                            $userfrom->customheaders = array(
                                    "Precedence: Bulk",
                                    'X-Auto-Response-Suppress: All',
                                    'Auto-Submitted: auto-generated',
                                );

                            $maildigest = digestforum_get_user_maildigest_bulk($digests, $userto, $digestforum->id);
                            if (!isset($userto->canpost[$discussion->id])) {
                                $canreply = digestforum_user_can_post($digestforum, $discussion,
                                    $userto, $cm, $course, $modcontext);
                            } else {
                                $canreply = $userto->canpost[$discussion->id];
                            }

                            $data = new \mod_digestforum\output\digestforum_post_email(
                                    $course,
                                    $cm,
                                    $digestforum,
                                    $discussion,
                                    $post,
                                    $userfrom,
                                    $userto,
                                    $canreply
                                );

                            if (!isset($userto->viewfullnames[$digestforum->id])) {
                                $data->viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
                            } else {
                                $data->viewfullnames = $userto->viewfullnames[$digestforum->id];
                            }

                            if ($maildigest == 2) {
                                // Subjects and link only.
                                $posttext .= $textdigestbasicout->render($data);
                                $posthtml .= $htmldigestbasicout->render($data);
                            } else {
                                // The full treatment.
                                $posttext .= $textdigestfullout->render($data);
                                $posthtml .= $htmldigestfullout->render($data);

                                // Create an array of postid's for this user to mark as read.
                                if (!$CFG->digestforum_usermarksread) {
                                    $userto->markposts[$post->id] = $post->id;
                                }
                            }
                            $sentcount++;
                        }
                        $footerlinks = array();
                        $posthtml .= '<hr style="height: 3px; width: 100%; color:#000; background-color:#000">';
                    }

                    // MJG - Add a entry with 0 views to the tracker table.
                    $trackerentry = new stdClass();
                    $trackerentry->mdluserid        = $userid;
                    $trackerentry->digestforumid    = $digestforum->id;
                    $trackerentry->digestdate       = $todaysdate;

                    $trackerid = $DB->insert_record('digestforum_tracker', $trackerentry);

                    // MJG - for tracking attempt.
                    $posthtml .= html_writer::empty_tag('img', array(
                        'src' => $CFG->wwwroot.'/mod/digestforum/img.php?id='.$trackerid,
                        "height" => "1px", "width" => "1px",
                        "alt" => "Click Download pictures to see all of the announcements.",
                        "nosend" => "1"));

                    if (empty($userto->mailformat) || $userto->mailformat != 1) {
                        // This user DOESN'T want to receive HTML.
                        $posthtml = '';
                    }

                    // MJG - trying to avoid duplicate Message-IDs. Should only be one msg with
                    // this digestforum->id and userto->id.
                    $digestuserfrom = core_user::get_noreply_user();
                    $msgid = digestforum_get_email_message_id($digestforum->id, $userto->id, $todaysdate);

                    $digestuserfrom->customheaders = array (
                        'Message-ID: ' . $msgid,
                    );

                    $eventdata = new \core\message\message();
                    $eventdata->courseid            = SITEID;
                    $eventdata->component           = 'mod_digestforum';
                    $eventdata->name                = 'digests';
                    $eventdata->userfrom            = $digestuserfrom;
                    $eventdata->userto              = $userto;
                    $eventdata->subject             = $postsubject;
                    $eventdata->fullmessage         = $posttext;
                    $eventdata->fullmessageformat   = FORMAT_PLAIN;
                    $eventdata->fullmessagehtml     = $posthtml;
                    $eventdata->notification        = 1;
                    $eventdata->smallmessage        = get_string('smallmessagedigest', 'digestforum', $sentcount);
                    $mailresult = message_send($eventdata);

                    if (!$mailresult) {
                        mtrace("ERROR: mod/digestforum/cron.php: Could not send out digest mail to user $userto->id ".
                            "($userto->email)... not trying again.");
                    } else {
                        mtrace("success.");
                        $usermailcount++;

                        // Mark post as read if digestforum_usermarksread is set off.
                        if (get_user_preferences('digestforum_markasreadonnotification', 1, $userto->id) == 1) {
                            digestforum_tp_mark_posts_read($userto, $userto->markposts);
                        }
                    }
                }
            }
        }
        // We have finishied all digest emails, update $CFG->digestforum_mailtimelast.
        set_config('digestforum_mailtimelast', $timenow);
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'digestforum', $usermailcount));
    }

    if (!empty($CFG->digestforum_lastreadclean)) {
        $timenow = time();
        if ($CFG->digestforum_lastreadclean + (24 * 3600) < $timenow) {
            set_config('digestforum_lastreadclean', $timenow);
            mtrace('Removing old digestforum read tracking info...');
            digestforum_tp_clean_read_records();
        }
    } else {
        set_config('digestforum_lastreadclean', time());
    }

    return true;
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
    $grades = grade_get_grades($course->id, 'mod', 'digestforum', $digestforum->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        $grade = false;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $count = digestforum_count_user_posts($digestforum->id, $user->id);

    if ($count && $count->postcount > 0) {
        $result = new stdClass();
        $result->info = get_string("numposts", "digestforum", $count->postcount);
        $result->time = $count->lastpost;
        if ($grade) {
            if (!$grade->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
                $result->info .= ', ' . get_string('grade') . ': ' . $grade->str_long_grade;
            } else {
                $result->info = get_string('grade') . ': ' . get_string('hidden', 'grades');
            }
        }
        return $result;
    } else if ($grade) {
        $result = new stdClass();
        if (!$grade->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            $result->info = get_string('grade') . ': ' . $grade->str_long_grade;
        } else {
            $result->info = get_string('grade') . ': ' . get_string('hidden', 'grades');
        }

        //datesubmitted == time created. dategraded == time modified or time overridden
        //if grade was last modified by the user themselves use date graded. Otherwise use date submitted
        //TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704
        if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
            $result->time = $grade->dategraded;
        } else {
            $result->time = $grade->datesubmitted;
        }

        return $result;
    }
    return NULL;
}


/**
 * @global object
 * @global object
 * @param object $coure
 * @param object $user
 * @param object $mod
 * @param object $digestforum
 */
function digestforum_user_complete($course, $user, $mod, $digestforum) {
    global $CFG,$USER, $OUTPUT;
    require_once("$CFG->libdir/gradelib.php");

    $grades = grade_get_grades($course->id, 'mod', 'digestforum', $digestforum->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        if (!$grade->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
            }
        } else {
            echo $OUTPUT->container(get_string('grade') . ': ' . get_string('hidden', 'grades'));
        }
    }

    if ($posts = digestforum_get_user_posts($digestforum->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = digestforum_get_user_involved_discussions($digestforum->id, $user->id);

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            digestforum_print_post_start($post);
            digestforum_print_post($post, $discussion, $digestforum, $cm, $course, false, false, false);
            digestforum_print_post_end($post);
        }
    } else {
        echo "<p>".get_string("noposts", "digestforum")."</p>";
    }
}

/**
 * Filters the digestforum discussions according to groups membership and config.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @since  Moodle 2.8, 2.7.1, 2.6.4
 * @param  array $discussions Discussions with new posts array
 * @return array Forums with the number of new posts
 */
function digestforum_filter_user_groups_discussions($discussions) {

    debugging('The function digestforum_filter_user_groups_discussions() is now deprecated.', DEBUG_DEVELOPER);

    // Group the remaining discussions posts by their digestforumid.
    $filtereddigestforums = array();

    // Discard not visible groups.
    foreach ($discussions as $discussion) {

        // Course data is already cached.
        $instances = get_fast_modinfo($discussion->course)->get_instances();
        $digestforum = $instances['digestforum'][$discussion->digestforum];

        // Continue if the user should not see this discussion.
        if (!digestforum_is_user_group_discussion($digestforum, $discussion->groupid)) {
            continue;
        }

        // Grouping results by digestforum.
        if (empty($filtereddigestforums[$digestforum->instance])) {
            $filtereddigestforums[$digestforum->instance] = new stdClass();
            $filtereddigestforums[$digestforum->instance]->id = $digestforum->id;
            $filtereddigestforums[$digestforum->instance]->count = 0;
        }
        $filtereddigestforums[$digestforum->instance]->count += $discussion->count;

    }

    return $filtereddigestforums;
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

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id)) ||
            in_array($discussiongroupid, $cm->get_modinfo()->get_groups($cm->groupingid))) {
        return true;
    }

    return false;
}

/**
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function digestforum_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB, $SESSION;

    debugging('The function digestforum_print_overview() is now deprecated.', DEBUG_DEVELOPER);

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$digestforums = get_all_instances_in_courses('digestforum',$courses)) {
        return;
    }

    // Courses to search for new posts
    $coursessqls = array();
    $params = array();
    foreach ($courses as $course) {

        // If the user has never entered into the course all posts are pending
        if ($course->lastaccess == 0) {
            $coursessqls[] = '(d.course = ?)';
            $params[] = $course->id;

        // Only posts created after the course last access
        } else {
            $coursessqls[] = '(d.course = ? AND p.created > ?)';
            $params[] = $course->id;
            $params[] = $course->lastaccess;
        }
    }
    $params[] = $USER->id;
    $coursessql = implode(' OR ', $coursessqls);

    $sql = "SELECT d.id, d.digestforum, d.course, d.groupid, COUNT(*) as count "
                .'FROM {digestforum_discussions} d '
                .'JOIN {digestforum_posts} p ON p.discussion = d.id '
                ."WHERE ($coursessql) "
                .'AND p.deleted <> 1 '
                .'AND p.userid != ? '
                .'AND (d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?)) '
                .'GROUP BY d.id, d.digestforum, d.course, d.groupid '
                .'ORDER BY d.course, d.digestforum';
    $params[] = time();
    $params[] = time();

    // Avoid warnings.
    if (!$discussions = $DB->get_records_sql($sql, $params)) {
        $discussions = array();
    }

    $digestforumsnewposts = digestforum_filter_user_groups_discussions($discussions);

    // also get all digestforum tracking stuff ONCE.
    $trackingdigestforums = array();
    foreach ($digestforums as $digestforum) {
        if (digestforum_tp_can_track_digestforums($digestforum)) {
            $trackingdigestforums[$digestforum->id] = $digestforum;
        }
    }

    if (count($trackingdigestforums) > 0) {
        $cutoffdate = isset($CFG->digestforum_oldpostdays) ? (time() - ($CFG->digestforum_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.digestforum,d.course,COUNT(p.id) AS count '.
            ' FROM {digestforum_posts} p '.
            ' JOIN {digestforum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {digestforum_read} r ON r.postid = p.id AND r.userid = ? WHERE p.deleted <> 1 AND (';
        $params = array($USER->id);

        foreach ($trackingdigestforums as $track) {
            $sql .= '(d.digestforum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            if (isset($SESSION->currentgroup[$track->course])) {
                $groupid =  $SESSION->currentgroup[$track->course];
            } else {
                // get first groupid
                $groupids = groups_get_all_groups($track->course, $USER->id);
                if ($groupids) {
                    reset($groupids);
                    $groupid = key($groupids);
                    $SESSION->currentgroup[$track->course] = $groupid;
                } else {
                    $groupid = 0;
                }
                unset($groupids);
            }
            $params[] = $groupid;
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL ';
        $sql .= 'AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)) ';
        $sql .= 'GROUP BY d.digestforum,d.course';
        $params[] = $cutoffdate;
        $params[] = time();
        $params[] = time();

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    } else {
        $unread = array();
    }

    if (empty($unread) and empty($digestforumsnewposts)) {
        return;
    }

    $strdigestforum = get_string('modulename','digestforum');

    foreach ($digestforums as $digestforum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($digestforum->id, $digestforumsnewposts) && !empty($digestforumsnewposts[$digestforum->id])) {
            $count = $digestforumsnewposts[$digestforum->id]->count;
        }
        if (array_key_exists($digestforum->id,$unread)) {
            $thisunread = $unread[$digestforum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview digestforum"><div class="name">'.$strdigestforum.': <a title="'.$strdigestforum.'" href="'.$CFG->wwwroot.'/mod/digestforum/view.php?f='.$digestforum->id.'">'.
                $digestforum->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= get_string('overviewnumpostssince', 'digestforum', $count)."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.get_string('overviewnumunread', 'digestforum', $thisunread).'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($digestforum->course,$htmlarray)) {
                $htmlarray[$digestforum->course] = array();
            }
            if (!array_key_exists('digestforum',$htmlarray[$digestforum->course])) {
                $htmlarray[$digestforum->course]['digestforum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$digestforum->course]['digestforum'] .= $str;
        }
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function digestforum_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    // do not use log table if possible, it may be huge and is expensive to join with other tables

    $allnamefields = user_picture::fields('u', null, 'duserid');
    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS digestforumtype, d.digestforum, d.groupid,
                                              d.timestart, d.timeend, $allnamefields
                                         FROM {digestforum_posts} p
                                              JOIN {digestforum_discussions} d ON d.id = p.discussion
                                              JOIN {digestforum} f             ON f.id = d.digestforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.course = ? AND p.deleted <> 1
                                     ORDER BY p.id ASC", array($timestart, $course->id))) { // order by initial posting date
         return false;
    }

    $modinfo = get_fast_modinfo($course);

    $groupmodes = array();
    $cms    = array();

    $strftimerecent = get_string('strftimerecent');

    $printposts = array();
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['digestforum'][$post->digestforum])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['digestforum'][$post->digestforum];
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);

        if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($CFG->digestforum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!has_capability('mod/digestforum:viewhiddentimedposts', $context)) {
                continue;
            }
        }

        // Check that the user can see the discussion.
        if (digestforum_is_user_group_discussion($cm, $post->groupid)) {
            $printposts[] = $post;
        }

    }
    unset($posts);

    if (!$printposts) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newdigestforumposts', 'digestforum').':', 3);
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
            $discussionurl->set_anchor('p'. $post->id);
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
 * Return grade for given user or all users.
 *
 * @global object
 * @global object
 * @param object $digestforum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function digestforum_get_user_grades($digestforum, $userid = 0) {
    global $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_digestforum';
    $ratingoptions->ratingarea = 'post';

    //need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'digestforum';
    $ratingoptions->moduleid   = $digestforum->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $digestforum->assessed;
    $ratingoptions->scaleid = $digestforum->scale;
    $ratingoptions->itemtable = 'digestforum_posts';
    $ratingoptions->itemtableusercolumn = 'userid';

    $rm = new rating_manager();
    return $rm->get_user_grades($ratingoptions);
}

/**
 * Update activity grades
 *
 * @category grade
 * @param object $digestforum
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function digestforum_update_grades($digestforum, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if (!$digestforum->assessed) {
        digestforum_grade_item_update($digestforum);

    } else if ($grades = digestforum_get_user_grades($digestforum, $userid)) {
        digestforum_grade_item_update($digestforum, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        digestforum_grade_item_update($digestforum, $grade);

    } else {
        digestforum_grade_item_update($digestforum);
    }
}

/**
 * Create/update grade item for given digestforum
 *
 * @category grade
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param stdClass $digestforum Forum object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function digestforum_grade_item_update($digestforum, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname'=>$digestforum->name, 'idnumber'=>$digestforum->cmidnumber);

    if (!$digestforum->assessed or $digestforum->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($digestforum->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $digestforum->scale;
        $params['grademin']  = 0;

    } else if ($digestforum->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$digestforum->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/digestforum', $digestforum->course, 'mod', 'digestforum', $digestforum->id, 0, $grades, $params);
}

/**
 * Delete grade item for given digestforum
 *
 * @category grade
 * @param stdClass $digestforum Forum object
 * @return grade_item
 */
function digestforum_grade_item_delete($digestforum) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/digestforum', $digestforum->course, 'mod', 'digestforum', $digestforum->id, 0, NULL, array('deleted'=>1));
}


/**
 * This function returns if a scale is being used by one digestforum
 *
 * @global object
 * @param int $digestforumid
 * @param int $scaleid negative number
 * @return bool
 */
function digestforum_scale_used ($digestforumid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("digestforum",array("id" => "$digestforumid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of digestforum
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any digestforum
 */
function digestforum_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('digestforum', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

// SQL FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Gets a post with all info ready for digestforum_print_post
 * Most of these joins are just to get the digestforum id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function digestforum_get_post_full($postid) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_record_sql("SELECT p.*, d.digestforum, $allnames, u.email, u.picture, u.imagealt
                             FROM {digestforum_posts} p
                                  JOIN {digestforum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @global object
 * @global object
 * @global object
 * @param int $discussionid
 * @param string $sort
 * @param bool $tracking does user track the digestforum?
 * @return array of posts
 */
function digestforum_get_all_discussion_posts($discussionid, $sort, $tracking=false) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    if ($tracking) {
        $tr_sel  = ", fr.id AS postread";
        $tr_join = "LEFT JOIN {digestforum_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
        $params[] = $USER->id;
    }

    $allnames = get_all_user_name_fields(true, 'u');
    $params[] = $discussionid;
    if (!$posts = $DB->get_records_sql("SELECT p.*, $allnames, u.email, u.picture, u.imagealt $tr_sel
                                     FROM {digestforum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $tr_join
                                    WHERE p.discussion = ?
                                 ORDER BY $sort", $params)) {
        return array();
    }

    foreach ($posts as $pid=>$p) {
        if ($tracking) {
            if (digestforum_tp_is_post_old($p)) {
                 $posts[$pid]->postread = true;
            }
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = array();
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
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for digestforums throughout the whole site.
 * @return array of digestforum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function digestforum_get_readable_digestforums($userid, $courseid=0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$digestforummod = $DB->get_record('modules', array('name' => 'digestforum'))) {
        print_error('notinstalled', 'digestforum');
    }

    if ($courseid) {
        $courses = $DB->get_records('course', array('id' => $courseid));
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        $courses1 = $DB->get_records('course', array('id' => SITEID));
        $courses2 = enrol_get_users_courses($userid, true, array('modinfo'));
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return array();
    }

    $readabledigestforums = array();

    foreach ($courses as $course) {

        $modinfo = get_fast_modinfo($course);

        if (empty($modinfo->instances['digestforum'])) {
            // hmm, no digestforums?
            continue;
        }

        $coursedigestforums = $DB->get_records('digestforum', array('course' => $course->id));

        foreach ($modinfo->instances['digestforum'] as $digestforumid => $cm) {
            if (!$cm->uservisible or !isset($coursedigestforums[$digestforumid])) {
                continue;
            }
            $context = context_module::instance($cm->id);
            $digestforum = $coursedigestforums[$digestforumid];
            $digestforum->context = $context;
            $digestforum->cm = $cm;

            if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {

                $digestforum->onlygroups = $modinfo->get_groups($cm->groupingid);
                $digestforum->onlygroups[] = -1;
            }

        /// hidden timed discussions
            $digestforum->viewhiddentimedposts = true;
            if (!empty($CFG->digestforum_enabletimedposts)) {
                if (!has_capability('mod/digestforum:viewhiddentimedposts', $context)) {
                    $digestforum->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($digestforum->type == 'qanda'
                    && !has_capability('mod/digestforum:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda digestforum.
                $digestforum->onlydiscussions = array();  // Holds discussion ids for the discussions
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
 * @global object
 * @global object
 * @global object
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int &$totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function digestforum_search_posts($searchterms, $courseid=0, $limitfrom=0, $limitnum=50,
                            &$totalcount, $extrasql='') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir.'/searchlib.php');

    $digestforums = digestforum_get_readable_digestforums($USER->id, $courseid);

    if (count($digestforums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = floor(time() / 60) * 60; // DB Cache Friendly.

    $fullaccess = array();
    $where = array();
    $params = array();

    foreach ($digestforums as $digestforumid => $digestforum) {
        $select = array();

        if (!$digestforum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$digestforumid} OR (d.timestart < :timestart{$digestforumid} AND (d.timeend = 0 OR d.timeend > :timeend{$digestforumid})))";
            $params = array_merge($params, array('userid'.$digestforumid=>$USER->id, 'timestart'.$digestforumid=>$now, 'timeend'.$digestforumid=>$now));
        }

        $cm = $digestforum->cm;
        $context = $digestforum->context;

        if ($digestforum->type == 'qanda'
            && !has_capability('mod/digestforum:viewqandawithoutposting', $context)) {
            if (!empty($digestforum->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($digestforum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$digestforumid.'_');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($digestforum->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($digestforum->onlygroups, SQL_PARAMS_NAMED, 'grps'.$digestforumid.'_');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.digestforum = :digestforum{$digestforumid} AND $selects)";
            $params['digestforum'.$digestforumid] = $digestforumid;
        } else {
            $fullaccess[] = $digestforumid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.digestforum $fullid_sql)";
    }

    $selectdiscussion = "(".implode(" OR ", $where).")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach($searchterms as $searchterm){
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"","\"",$searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();

        $tagjoins = '';
        $tagfields = [];
        $tagfieldcount = 0;
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
        list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                              'p.userid', 'u.id', 'u.firstname',
                                                              'u.lastname', 'p.modified', 'd.digestforum',
                                                              $tagfields);
        $params = array_merge($params, $msparams);
    }

    $fromsql = "{digestforum_posts} p
                  INNER JOIN {digestforum_discussions} d ON d.id = p.discussion
                  INNER JOIN {user} u ON u.id = p.userid $tagjoins";

    $selectsql = " $messagesearch
               AND p.discussion = d.id
               AND p.userid = u.id
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $allnames = get_all_user_name_fields(true, 'u');
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
 * Returns a list of all new posts that have not been mailed yet
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function digestforum_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array();
    $params['mailed'] = DFORUM_MAILED_PENDING;
    $params['ptimestart'] = $starttime;
    $params['ptimeend'] = $endtime;
    $params['mailnow'] = 1;

    if (!empty($CFG->digestforum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $selectsql = "AND (p.created >= :ptimestart OR d.timestart >= :pptimestart)";
        $params['pptimestart'] = $starttime;
        $timedsql = "AND (d.timestart < :dtimestart AND (d.timeend = 0 OR d.timeend > :dtimeend))";
        $params['dtimestart'] = $now;
        $params['dtimeend'] = $now;
    } else {
        $timedsql = "";
        $selectsql = "AND p.created >= :ptimestart";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.digestforum
                                 FROM {digestforum_posts} p
                                 JOIN {digestforum_discussions} d ON d.id = p.discussion
                                 WHERE p.mailed = :mailed
                                 $selectsql
                                 AND (p.created < :ptimeend OR p.mailnow = :mailnow)
                                 $timedsql
                                 ORDER BY p.modified ASC", $params);
}

/**
 * Marks posts before a certain time as being mailed already
 *
 * @global object
 * @global object
 * @param int $endtime
 * @param int $now Defaults to time()
 * @return bool
 */
function digestforum_mark_old_posts_as_mailed($endtime, $now=null) {
    global $CFG, $DB;

    if (empty($now)) {
        $now = time();
    }

    $params = array();
    $params['mailedsuccess'] = DFORUM_MAILED_SUCCESS;
    $params['now'] = $now;
    $params['endtime'] = $endtime;
    $params['mailnow'] = 1;
    $params['mailedpending'] = DFORUM_MAILED_PENDING;

    if (empty($CFG->digestforum_enabletimedposts)) {
        return $DB->execute("UPDATE {digestforum_posts}
                             SET mailed = :mailedsuccess
                             WHERE (created < :endtime OR mailnow = :mailnow)
                             AND mailed = :mailedpending", $params);
    } else {
        return $DB->execute("UPDATE {digestforum_posts}
                             SET mailed = :mailedsuccess
                             WHERE discussion NOT IN (SELECT d.id
                                                      FROM {digestforum_discussions} d
                                                      WHERE d.timestart > :now)
                             AND (created < :endtime OR mailnow = :mailnow)
                             AND mailed = :mailedpending", $params);
    }
}

/**
 * Get all the posts for a user in a digestforum suitable for digestforum_print_post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function digestforum_get_user_posts($digestforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($digestforumid, $userid);

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
        if (!has_capability('mod/digestforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    $allnames = get_all_user_name_fields(true, 'u');
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
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param int $digestforumid
 * @param int $userid
 * @return array Array or false
 */
function digestforum_get_user_involved_discussions($digestforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($digestforumid, $userid);
    if (!empty($CFG->digestforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
        if (!has_capability('mod/digestforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
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
 * @global object
 * @global object
 * @param int $digestforumid
 * @param int $userid
 * @return array of counts or false
 */
function digestforum_count_user_posts($digestforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($digestforumid, $userid);
    if (!empty($CFG->digestforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforumid);
        if (!has_capability('mod/digestforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
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
 * @global object
 * @global object
 * @param object $log
 * @return array|null
 */
function digestforum_get_post_from_log($log) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
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
                                  AND f.id = d.digestforum", array($log->info));


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
                                  AND f.id = d.digestforum", array($log->info));
    }
    return NULL;
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @global object
 * @global object
 * @param int $dicsussionid
 * @return array
 */
function digestforum_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {digestforum_discussions} d,
                                  {digestforum_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", array($discussionid));
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @global object
 * @global object
 * @param int $digestforumid
 * @param string $digestforumsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function digestforum_count_discussion_replies($digestforumid, $digestforumsort="", $limit=-1, $page=-1, $perpage=0) {
    global $CFG, $DB;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
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
        $groupby = ", ".strtolower($digestforumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $digestforumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {digestforum_posts} p
                       JOIN {digestforum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.digestforum = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($digestforumid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {digestforum_posts} p
                       JOIN {digestforum_discussions} d ON p.discussion = d.id
                 WHERE d.digestforum = ?
              GROUP BY p.discussion $groupby $orderby";
        return $DB->get_records_sql($sql, array($digestforumid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $digestforum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function digestforum_count_discussions($digestforum, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = floor(time() / 60) * 60; // DB Cache Friendly.

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
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
                $counts[$count->id] = $count->dcount;
            }
            $cache[$course->id] = $counts;
        } else {
            $cache[$course->id] = array();
        }
    }

    if (empty($cache[$course->id][$digestforum->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$digestforum->id];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $cache[$course->id][$digestforum->id];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // add all groups posts
    $mygroups[-1] = -1;

    list($mygroups_sql, $params) = $DB->get_in_or_equal($mygroups);
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
             WHERE d.groupid $mygroups_sql AND d.digestforum = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Get all discussions in a digestforum
 *
 * @global object
 * @global object
 * @global object
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
function digestforum_get_discussions($cm, $digestforumsort="", $fullpost=true, $unused=-1, $limit=-1,
                                $userlastmodified=false, $page=-1, $perpage=0, $groupid = -1,
                                $updatedsince = 0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $now = floor(time() / 60) * 60;
    $params = array($cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!has_capability('mod/digestforum:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    if (!empty($CFG->digestforum_enabletimedposts)) { /// Users must fulfill timed posts

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
        $limitfrom = $page*$perpage;
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
                return array();
            }
            $currentgroup = $groupid;
        } else if ($groupid === -1) {
            $currentgroup = groups_get_activity_group($cm);
        } else {
            // Get discussions for all groups current user can see.
            $currentgroup = null;
        }

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
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
                    list($insqlgroups, $inparamsgroups) = $DB->get_in_or_equal($mygroups);
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
    if (empty($fullpost)) {
        $postdata = "p.id, p.subject, p.modified, p.discussion, p.userid, p.created";
    } else {
        $postdata = "p.*";
    }

    if (empty($userlastmodified)) {  // We don't need to know this
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = ', ' . get_all_user_name_fields(true, 'um', null, 'um') . ', um.email AS umemail, um.picture AS umpicture,
                        um.imagealt AS umimagealt';
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)";
    }

    $updatedsincesql = '';
    if (!empty($updatedsince)) {
        $updatedsincesql = 'AND d.timemodified > ?';
        $params[] = $updatedsince;
    }

    $allnames = get_all_user_name_fields(true, 'u');
    $sql = "SELECT $postdata, d.name, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend, d.pinned,
                   $allnames, u.email, u.picture, u.imagealt $umfields
              FROM {digestforum_discussions} d
                   JOIN {digestforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
                   $umtable
             WHERE d.digestforum = ? AND p.parent = 0
                   $timelimit $groupselect $updatedsincesql
          ORDER BY $digestforumsort, d.id DESC";

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

    if ($cm->instance != $discussion->digestforum or $discussion->digestforum != $digestforum->id or $digestforum->id != $cm->instance) {
        throw new coding_exception('Discussion is not part of the same digestforum.');
    }

    $neighbours = array('prev' => false, 'next' => false);
    $now = floor(time() / 60) * 60;
    $params = array();

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

         $sub1 = $subselect.$subselectwhere1;
         $comparevalue = "($sub1)";

         $sub2 = $subselect.$subselectwhere2;
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
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function digestforum_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $now = floor(time() / 60) * 60;
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays*24*60*60);

    $params = array();
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //separate groups without access all
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
        return array();
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @uses CONEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function digestforum_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $now = floor(time() / 60) * 60;
    $params = array($cm->instance);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
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


// OTHER FUNCTIONS ///////////////////////////////////////////////////////////


/**
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type
 */
function digestforum_get_course_digestforum($courseid, $type) {
// How to set up special 1-per-course digestforums
    global $CFG, $DB, $OUTPUT, $USER;

    if ($digestforums = $DB->get_records_select("digestforum", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($digestforums as $digestforum) {
            return $digestforum;   // ie the first one
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
            $digestforum->forcesubscribe = DFORUM_FORCESUBSCRIBE;
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

    if (! $module = $DB->get_record("modules", array("name" => "digestforum"))) {
        echo $OUTPUT->notification("Could not find digestforum module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $digestforum->id;
    $mod->section = 0;
    include_once("$CFG->dirroot/course/lib.php");
    if (! $mod->coursemodule = add_course_module($mod) ) {
        echo $OUTPUT->notification("Could not add a new course module to the course '" . $courseid . "'");
        return false;
    }
    $sectionid = course_add_cm_to_section($courseid, $mod->coursemodule, 0);
    return $DB->get_record("digestforum", array("id" => "$digestforum->id"));
}

/**
 * Return a static array of posts that are open.
 *
 * @return array
 */
function digestforum_post_nesting_cache() {
    static $nesting = array();
    return $nesting;
}

/**
 * Return true for the first time this post was started
 *
 * @param int $id The id of the post to start
 * @return bool
 */
function digestforum_should_start_post_nesting($id) {
    $cache = digestforum_post_nesting_cache();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = 1;
        return true;
    } else {
        $cache[$id]++;
        return false;
    }
}

/**
 * Return true when all the opens are nested with a close.
 *
 * @param int $id The id of the post to end
 * @return bool
 */
function digestforum_should_end_post_nesting($id) {
    $cache = digestforum_post_nesting_cache();
    if (!array_key_exists($id, $cache)) {
        return true;
    } else {
        $cache[$id]--;
        if ($cache[$id] == 0) {
            unset($cache[$id]);
            return true;
        }
    }
    return false;
}

/**
 * Start a digestforum post container
 *
 * @param object $post The post to print.
 * @param bool $return Return the string or print it
 * @return string
 */
function digestforum_print_post_start($post, $return = false) {
    $output = '';

    if (digestforum_should_start_post_nesting($post->id)) {
        $attributes = [
            'id' => 'p'.$post->id,
            'tabindex' => -1,
            'class' => 'relativelink'
        ];
        $output .= html_writer::start_tag('article', $attributes);
    }
    if ($return) {
        return $output;
    }
    echo $output;
    return;
}

/**
 * End a digestforum post container
 *
 * @param object $post The post to print.
 * @param bool $return Return the string or print it
 * @return string
 */
function digestforum_print_post_end($post, $return = false) {
    $output = '';

    if (digestforum_should_end_post_nesting($post->id)) {
        $output .= html_writer::end_tag('article');
    }
    if ($return) {
        return $output;
    }
    echo $output;
    return;
}

/**
 * Print a digestforum post
 * This function should always be surrounded with calls to digestforum_print_post_start
 * and digestforum_print_post_end to create the surrounding container for the post.
 * Replies can be nested before digestforum_print_post_end and should reflect the structure of
 * thread.
 *
 * @global object
 * @global object
 * @uses DFORUM_MODE_THREADED
 * @uses PORTFOLIO_FORMAT_PLAINHTML
 * @uses PORTFOLIO_FORMAT_FILE
 * @uses PORTFOLIO_FORMAT_RICHHTML
 * @uses PORTFOLIO_ADD_TEXT_LINK
 * @uses CONTEXT_MODULE
 * @param object $post The post to print.
 * @param object $discussion
 * @param object $digestforum
 * @param object $cm
 * @param object $course
 * @param boolean $ownpost Whether this post belongs to the current user.
 * @param boolean $reply Whether to print a 'reply' link at the bottom of the message.
 * @param boolean $link Just print a shortened version of the post as a link to the full post.
 * @param string $footer Extra stuff to print after the message.
 * @param string $highlight Space-separated list of terms to highlight.
 * @param int $post_read true, false or -99. If we already know whether this user
 *          has read this post, pass that in, otherwise, pass in -99, and this
 *          function will work it out.
 * @param boolean $dummyifcantsee When digestforum_user_can_see_post says that
 *          the current user can't see this post, if this argument is true
 *          (the default) then print a dummy 'you can't see this post' post.
 *          If false, don't output anything at all.
 * @param bool|null $istracked
 * @return void
 */
function digestforum_print_post($post, $discussion, $digestforum, &$cm, $course, $ownpost=false, $reply=false, $link=false,
                          $footer="", $highlight="", $postisread=null, $dummyifcantsee=true, $istracked=null, $return=false) {
    global $USER, $CFG, $OUTPUT;

    require_once($CFG->libdir . '/filelib.php');

    // String cache
    static $str;
    // This is an extremely hacky way to ensure we only print the 'unread' anchor
    // the first time we encounter an unread post on a page. Ideally this would
    // be moved into the caller somehow, and be better testable. But at the time
    // of dealing with this bug, this static workaround was the most surgical and
    // it fits together with only printing th unread anchor id once on a given page.
    static $firstunreadanchorprinted = false;

    $modcontext = context_module::instance($cm->id);

    $post->course = $course->id;
    $post->digestforum  = $digestforum->id;
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_digestforum', 'post', $post->id);
    if (!empty($CFG->enableplagiarism)) {
        require_once($CFG->libdir.'/plagiarismlib.php');
        $post->message .= plagiarism_get_links(array('userid' => $post->userid,
            'content' => $post->message,
            'cmid' => $cm->id,
            'course' => $post->course,
            'digestforum' => $post->digestforum));
    }

    // caching
    if (!isset($cm->cache)) {
        $cm->cache = new stdClass;
    }

    if (!isset($cm->cache->caps)) {
        $cm->cache->caps = array();
        $cm->cache->caps['mod/digestforum:viewdiscussion']   = has_capability('mod/digestforum:viewdiscussion', $modcontext);
        $cm->cache->caps['moodle/site:viewfullnames']  = has_capability('moodle/site:viewfullnames', $modcontext);
        $cm->cache->caps['mod/digestforum:editanypost']      = has_capability('mod/digestforum:editanypost', $modcontext);
        $cm->cache->caps['mod/digestforum:splitdiscussions'] = has_capability('mod/digestforum:splitdiscussions', $modcontext);
        $cm->cache->caps['mod/digestforum:deleteownpost']    = has_capability('mod/digestforum:deleteownpost', $modcontext);
        $cm->cache->caps['mod/digestforum:deleteanypost']    = has_capability('mod/digestforum:deleteanypost', $modcontext);
        $cm->cache->caps['mod/digestforum:viewanyrating']    = has_capability('mod/digestforum:viewanyrating', $modcontext);
        $cm->cache->caps['mod/digestforum:exportpost']       = has_capability('mod/digestforum:exportpost', $modcontext);
        $cm->cache->caps['mod/digestforum:exportownpost']    = has_capability('mod/digestforum:exportownpost', $modcontext);
    }

    if (!isset($cm->uservisible)) {
        $cm->uservisible = \core_availability\info_module::is_user_visible($cm, 0, false);
    }

    if ($istracked && is_null($postisread)) {
        $postisread = digestforum_tp_is_post_read($USER->id, $post);
    }

    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm, false)) {
        // Do _not_ check the deleted flag - we need to display a different UI.
        $output = '';
        if (!$dummyifcantsee) {
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }

        $output .= html_writer::start_tag('div', array('class' => 'digestforumpost clearfix',
                                                       'aria-label' => get_string('hiddendigestforumpost', 'digestforum')));
        $output .= html_writer::start_tag('header', array('class' => 'row header'));
        $output .= html_writer::tag('div', '', array('class' => 'left picture', 'role' => 'presentation')); // Picture.
        if ($post->parent) {
            $output .= html_writer::start_tag('div', array('class' => 'topic'));
        } else {
            $output .= html_writer::start_tag('div', array('class' => 'topic starter'));
        }
        $output .= html_writer::tag('div', get_string('digestforumsubjecthidden','digestforum'), array('class' => 'subject',
                                                                                           'role' => 'header',
                                                                                           'id' => ('headp' . $post->id))); // Subject.
        $authorclasses = array('class' => 'author');
        $output .= html_writer::tag('address', get_string('digestforumauthorhidden', 'digestforum'), $authorclasses); // Author.
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('header'); // Header.
        $output .= html_writer::start_tag('div', array('class'=>'row'));
        $output .= html_writer::tag('div', '&nbsp;', array('class'=>'left side')); // Groups
        $output .= html_writer::tag('div', get_string('digestforumbodyhidden','digestforum'), array('class'=>'content')); // Content
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::end_tag('div'); // digestforumpost

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }

    if (!empty($post->deleted)) {
        // Note: Posts marked as deleted are still returned by the above digestforum_user_can_post because it is required for
        // nesting of posts.
        $output = '';
        if (!$dummyifcantsee) {
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }
        $output .= html_writer::start_tag('div', [
                'class' => 'digestforumpost clearfix',
                'aria-label' => get_string('digestforumbodydeleted', 'digestforum'),
            ]);

        $output .= html_writer::start_tag('header', array('class' => 'row header'));
        $output .= html_writer::tag('div', '', array('class' => 'left picture', 'role' => 'presentation'));

        $classes = ['topic'];
        if (!empty($post->parent)) {
            $classes[] = 'starter';
        }
        $output .= html_writer::start_tag('div', ['class' => implode(' ', $classes)]);

        // Subject.
        $output .= html_writer::tag('div', get_string('digestforumsubjectdeleted', 'digestforum'), [
                'class' => 'subject',
                'role' => 'header',
                'id' => ('headp' . $post->id)
            ]);

        // Author.
        $output .= html_writer::tag('address', '', ['class' => 'author']);

        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('header'); // End header.
        $output .= html_writer::start_tag('div', ['class' => 'row']);
        $output .= html_writer::tag('div', '&nbsp;', ['class' => 'left side']); // Groups.
        $output .= html_writer::tag('div', get_string('digestforumbodydeleted', 'digestforum'), ['class' => 'content']); // Content.
        $output .= html_writer::end_tag('div'); // End row.
        $output .= html_writer::end_tag('div'); // End digestforumpost.

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }

    if (empty($str)) {
        $str = new stdClass;
        $str->edit         = get_string('edit', 'digestforum');
        $str->delete       = get_string('delete', 'digestforum');
        $str->reply        = get_string('reply', 'digestforum');
        $str->parent       = get_string('parent', 'digestforum');
        $str->pruneheading = get_string('pruneheading', 'digestforum');
        $str->prune        = get_string('prune', 'digestforum');
        $str->displaymode     = get_user_preferences('digestforum_displaymode', $CFG->digestforum_displaymode);
        $str->markread     = get_string('markread', 'digestforum');
        $str->markunread   = get_string('markunread', 'digestforum');
    }

    $discussionlink = new moodle_url('/mod/digestforum/discuss.php', array('d'=>$post->discussion));

    // Build an object that represents the posting user
    $postuser = new stdClass;
    $postuserfields = explode(',', user_picture::fields());
    $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
    $postuser->id = $post->userid;
    $postuser->fullname    = fullname($postuser, $cm->cache->caps['moodle/site:viewfullnames']);
    $postuser->profilelink = new moodle_url('/user/view.php', array('id'=>$post->userid, 'course'=>$course->id));

    // Prepare the groups the posting user belongs to
    if (isset($cm->cache->usersgroups)) {
        $groups = array();
        if (isset($cm->cache->usersgroups[$post->userid])) {
            foreach ($cm->cache->usersgroups[$post->userid] as $gid) {
                $groups[$gid] = $cm->cache->groups[$gid];
            }
        }
    } else {
        $groups = groups_get_all_groups($course->id, $post->userid, $cm->groupingid);
    }

    // Prepare the attachements for the post, files then images
    list($attachments, $attachedimages) = digestforum_print_attachments($post, $cm, 'separateimages');

    // Determine if we need to shorten this post
    $shortenpost = ($link && (strlen(strip_tags($post->message)) > $CFG->digestforum_longpost));

    // Prepare an array of commands
    $commands = array();

    // Add a permalink.
    $permalink = new moodle_url($discussionlink);
    $permalink->set_anchor('p' . $post->id);
    $commands[] = array('url' => $permalink, 'text' => get_string('permalink', 'digestforum'), 'attributes' => ['rel' => 'bookmark']);

    // SPECIAL CASE: The front page can display a news item post to non-logged in users.
    // Don't display the mark read / unread controls in this case.
    if ($istracked && $CFG->digestforum_usermarksread && isloggedin()) {
        $url = new moodle_url($discussionlink, array('postid'=>$post->id, 'mark'=>'unread'));
        $text = $str->markunread;
        if (!$postisread) {
            $url->param('mark', 'read');
            $text = $str->markread;
        }
        if ($str->displaymode == DFORUM_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->id);
        }
        $commands[] = array('url'=>$url, 'text'=>$text, 'attributes' => ['rel' => 'bookmark']);
    }

    // Zoom in to the parent specifically
    if ($post->parent) {
        $url = new moodle_url($discussionlink);
        if ($str->displaymode == DFORUM_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->parent);
        }
        $commands[] = array('url'=>$url, 'text'=>$str->parent, 'attributes' => ['rel' => 'bookmark']);
    }

    // Hack for allow to edit news posts those are not displayed yet until they are displayed
    $age = time() - $post->created;
    if (!$post->parent && $digestforum->type == 'news' && $discussion->timestart > time()) {
        $age = 0;
    }

    if ($digestforum->type == 'single' and $discussion->firstpost == $post->id) {
        if (has_capability('moodle/course:manageactivities', $modcontext)) {
            // The first post in single simple is the digestforum description.
            $commands[] = array('url'=>new moodle_url('/course/modedit.php', array('update'=>$cm->id, 'sesskey'=>sesskey(), 'return'=>1)), 'text'=>$str->edit);
        }
    } else if (($ownpost && $age < $CFG->maxeditingtime) || $cm->cache->caps['mod/digestforum:editanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/digestforum/post.php', array('edit'=>$post->id)), 'text'=>$str->edit);
    }

    if ($cm->cache->caps['mod/digestforum:splitdiscussions'] && $post->parent && $digestforum->type != 'single') {
        $commands[] = array('url'=>new moodle_url('/mod/digestforum/post.php', array('prune'=>$post->id)), 'text'=>$str->prune, 'title'=>$str->pruneheading);
    }

    if ($digestforum->type == 'single' and $discussion->firstpost == $post->id) {
        // Do not allow deleting of first post in single simple type.
    } else if (($ownpost && $age < $CFG->maxeditingtime && $cm->cache->caps['mod/digestforum:deleteownpost']) || $cm->cache->caps['mod/digestforum:deleteanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/digestforum/post.php', array('delete'=>$post->id)), 'text'=>$str->delete);
    }

    if ($reply) {
        $commands[] = array('url'=>new moodle_url('/mod/digestforum/post.php#mformdigestforum', array('reply'=>$post->id)), 'text'=>$str->reply);
    }

    if ($CFG->enableportfolios && ($cm->cache->caps['mod/digestforum:exportpost'] || ($ownpost && $cm->cache->caps['mod/digestforum:exportownpost']))) {
        $p = array('postid' => $post->id);
        require_once($CFG->libdir.'/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('digestforum_portfolio_caller', array('postid' => $post->id), 'mod_digestforum');
        if (empty($attachments)) {
            $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
        } else {
            $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
        }

        $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
        if (!empty($porfoliohtml)) {
            $commands[] = $porfoliohtml;
        }
    }
    // Finished building commands


    // Begin output

    $output  = '';

    if ($istracked) {
        if ($postisread) {
            $digestforumpostclass = ' read';
        } else {
            $digestforumpostclass = ' unread';
            // If this is the first unread post printed then give it an anchor and id of unread.
            if (!$firstunreadanchorprinted) {
                $output .= html_writer::tag('a', '', array('id' => 'unread'));
                $firstunreadanchorprinted = true;
            }
        }
    } else {
        // ignore trackign status if not tracked or tracked param missing
        $digestforumpostclass = '';
    }

    $topicclass = '';
    if (empty($post->parent)) {
        $topicclass = ' firstpost starter';
    }

    if (!empty($post->lastpost)) {
        $digestforumpostclass .= ' lastpost';
    }

    // Flag to indicate whether we should hide the author or not.
    $authorhidden = digestforum_is_author_hidden($post, $digestforum);
    $postbyuser = new stdClass;
    $postbyuser->post = $post->subject;
    $postbyuser->user = $postuser->fullname;
    $discussionbyuser = get_string('postbyuser', 'digestforum', $postbyuser);
    // Begin digestforum post.
    $output .= html_writer::start_div('digestforumpost clearfix' . $digestforumpostclass . $topicclass,
        ['aria-label' => $discussionbyuser]);
    // Begin header row.
    $output .= html_writer::start_tag('header', ['class' => 'row header clearfix']);

    // User picture.
    if (!$authorhidden) {
        $picture = $OUTPUT->user_picture($postuser, ['courseid' => $course->id]);
        $output .= html_writer::div($picture, 'left picture', ['role' => 'presentation']);
        $topicclass = 'topic' . $topicclass;
    }

    // Begin topic column.
    $output .= html_writer::start_div($topicclass);
    $postsubject = $post->subject;
    if (empty($post->subjectnoformat)) {
        $postsubject = format_string($postsubject);
    }
    $output .= html_writer::div($postsubject, 'subject', ['role' => 'heading', 'aria-level' => '1', 'id' => ('headp' . $post->id)]);

    if ($authorhidden) {
        $bytext = userdate_htmltime($post->created);
    } else {
        $by = new stdClass();
        $by->date = userdate_htmltime($post->created);
        $by->name = html_writer::link($postuser->profilelink, $postuser->fullname);
        $bytext = get_string('bynameondate', 'digestforum', $by);
    }
    $bytextoptions = [
        'class' => 'author'
    ];
    $output .= html_writer::tag('address', $bytext, $bytextoptions);
    // End topic column.
    $output .= html_writer::end_div();

    // End header row.
    $output .= html_writer::end_tag('header');

    // Row with the digestforum post content.
    $output .= html_writer::start_div('row maincontent clearfix');
    // Show if author is not hidden or we have groups.
    if (!$authorhidden || $groups) {
        $output .= html_writer::start_div('left');
        $groupoutput = '';
        if ($groups) {
            $groupoutput = print_group_picture($groups, $course->id, false, true, true);
        }
        if (empty($groupoutput)) {
            $groupoutput = '&nbsp;';
        }
        $output .= html_writer::div($groupoutput, 'grouppictures');
        $output .= html_writer::end_div(); // Left side.
    }

    $output .= html_writer::start_tag('div', array('class'=>'no-overflow'));
    $output .= html_writer::start_tag('div', array('class'=>'content'));

    $options = new stdClass;
    $options->para    = false;
    $options->trusted = $post->messagetrust;
    $options->context = $modcontext;
    if ($shortenpost) {
        // Prepare shortened version by filtering the text then shortening it.
        $postclass    = 'shortenedpost';
        $postcontent  = format_text($post->message, $post->messageformat, $options);
        $postcontent  = shorten_text($postcontent, $CFG->digestforum_shortpost);
        $postcontent .= html_writer::link($discussionlink, get_string('readtherest', 'digestforum'));
        $postcontent .= html_writer::tag('div', '('.get_string('numwords', 'moodle', count_words($post->message)).')',
            array('class'=>'post-word-count'));
    } else {
        // Prepare whole post
        $postclass    = 'fullpost';
        $postcontent  = format_text($post->message, $post->messageformat, $options, $course->id);
        if (!empty($highlight)) {
            $postcontent = highlight($highlight, $postcontent);
        }
        if (!empty($digestforum->displaywordcount)) {
            $postcontent .= html_writer::tag('div', get_string('numwords', 'moodle', count_words($postcontent)),
                array('class'=>'post-word-count'));
        }
        $postcontent .= html_writer::tag('div', $attachedimages, array('class'=>'attachedimages'));
    }

    if (\core_tag_tag::is_enabled('mod_digestforum', 'digestforum_posts')) {
        $postcontent .= $OUTPUT->tag_list(core_tag_tag::get_item_tags('mod_digestforum', 'digestforum_posts', $post->id), null, 'digestforum-tags');
    }

    // Output the post content
    $output .= html_writer::tag('div', $postcontent, array('class'=>'posting '.$postclass));
    $output .= html_writer::end_tag('div'); // Content
    $output .= html_writer::end_tag('div'); // Content mask
    $output .= html_writer::end_tag('div'); // Row

    $output .= html_writer::start_tag('nav', array('class' => 'row side'));
    $output .= html_writer::tag('div','&nbsp;', array('class'=>'left'));
    $output .= html_writer::start_tag('div', array('class'=>'options clearfix'));

    if (!empty($attachments)) {
        $output .= html_writer::tag('div', $attachments, array('class' => 'attachments'));
    }

    // Output ratings
    if (!empty($post->rating)) {
        $output .= html_writer::tag('div', $OUTPUT->render($post->rating), array('class'=>'digestforum-post-rating'));
    }

    // Output the commands
    $commandhtml = array();
    foreach ($commands as $command) {
        if (is_array($command)) {
            $attributes = ['class' => 'nav-item nav-link'];
            if (isset($command['attributes'])) {
                $attributes = array_merge($attributes, $command['attributes']);
            }
            $commandhtml[] = html_writer::link($command['url'], $command['text'], $attributes);
        } else {
            $commandhtml[] = $command;
        }
    }
    $output .= html_writer::tag('div', implode(' ', $commandhtml), array('class' => 'commands nav'));

    // Output link to post if required
    if ($link) {
        if (digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $modcontext)) {
            $langstring = 'discussthistopic';
        } else {
            $langstring = 'viewthediscussion';
        }
        if ($post->replies == 1) {
            $replystring = get_string('repliesone', 'digestforum', $post->replies);
        } else {
            $replystring = get_string('repliesmany', 'digestforum', $post->replies);
        }
        if (!empty($discussion->unread) && $discussion->unread !== '-') {
            $replystring .= ' <span class="sep">/</span> <span class="unread">';
            $unreadlink = new moodle_url($discussionlink, null, 'unread');
            if ($discussion->unread == 1) {
                $replystring .= html_writer::link($unreadlink, get_string('unreadpostsone', 'digestforum'));
            } else {
                $replystring .= html_writer::link($unreadlink, get_string('unreadpostsnumber', 'digestforum', $discussion->unread));
            }
            $replystring .= '</span>';
        }

        $output .= html_writer::start_tag('div', array('class'=>'link'));
        $output .= html_writer::link($discussionlink, get_string($langstring, 'digestforum'));
        $output .= '&nbsp;('.$replystring.')';
        $output .= html_writer::end_tag('div'); // link
    }

    // Output footer if required
    if ($footer) {
        $output .= html_writer::tag('div', $footer, array('class'=>'footer'));
    }

    // Close remaining open divs
    $output .= html_writer::end_tag('div'); // content
    $output .= html_writer::end_tag('nav'); // row
    $output .= html_writer::end_tag('div'); // digestforumpost

    // Mark the digestforum post as read if required
    if ($istracked && !$CFG->digestforum_usermarksread && !$postisread) {
        digestforum_tp_mark_post_read($USER->id, $post);
    }

    if ($return) {
        return $output;
    }
    echo $output;
    return;
}

/**
 * Return rating related permissions
 *
 * @param string $options the context id
 * @return array an associative array of the user's rating permissions
 */
function digestforum_rating_permissions($contextid, $component, $ratingarea) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_digestforum' || $ratingarea != 'post') {
        // We don't know about this component/ratingarea so just return null to get the
        // default restrictive permissions.
        return null;
    }
    return array(
        'view'    => has_capability('mod/digestforum:viewrating', $context),
        'viewany' => has_capability('mod/digestforum:viewanyrating', $context),
        'viewall' => has_capability('mod/digestforum:viewallratings', $context),
        'rate'    => has_capability('mod/digestforum:rate', $context)
    );
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
 *            rateduserid => int the id of the user whose items have been rated. NOT the user who submitted the ratings. 0 to update all. [required]
 *            aggregation => int the aggregation method to apply when calculating grades ie RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function digestforum_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_digestforum
    if ($params['component'] != 'mod_digestforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in digestforum)
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call digestforum_user_can_see_post
    $post = $DB->get_record('digestforum_posts', array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('digestforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $digestforum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id , false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the digestforum
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($digestforum->scale != $params['scaleid']) {
        //the scale being submitted doesnt match the one in the database
        throw new rating_exception('invalidscaleid');
    }

    // check the item we're rating was created in the assessable time window
    if (!empty($digestforum->assesstimestart) && !empty($digestforum->assesstimefinish)) {
        if ($post->created < $digestforum->assesstimestart || $post->created > $digestforum->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    //check that the submitted rating is valid for the scale

    // lower limit
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // upper limit
    if ($digestforum->scale < 0) {
        //its a custom scale
        $scalerecord = $DB->get_record('scale', array('id' => -$digestforum->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $digestforum->scale) {
        //if its numeric and submitted rating is above maximum
        throw new rating_exception('invalidnum');
    }

    // Make sure groups allow this user to see the item they're rating
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            throw new rating_exception('cannotfindgroup');//something is wrong
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not allow rating of posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            throw new rating_exception('notmemberofgroup');
        }
    }

    // perform some final capability checks
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

    $post = $DB->get_record('digestforum_posts', array('id' => $params['itemid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('digestforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $digestforum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id , false, MUST_EXIST);

    // Perform some final capability checks.
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, $USER, $cm)) {
        return false;
    }

    return true;
}

/**
 * This function prints the overview of a discussion in the digestforum listing.
 * It needs some discussion information and some post information, these
 * happen to be combined for efficiency in the $post parameter by the function
 * that calls this one: digestforum_print_latest_discussions()
 *
 * @global object
 * @global object
 * @param object $post The post object (passed by reference for speed).
 * @param object $digestforum The digestforum object.
 * @param int $group Current group.
 * @param string $datestring Format to use for the dates.
 * @param boolean $cantrack Is tracking enabled for this digestforum.
 * @param boolean $digestforumtracked Is the user tracking this digestforum.
 * @param boolean $canviewparticipants True if user has the viewparticipants permission for this course
 * @param boolean $canviewhiddentimedposts True if user has the viewhiddentimedposts permission for this digestforum
 */
function digestforum_print_discussion_header(&$post, $digestforum, $group = -1, $datestring = "",
                                        $cantrack = true, $digestforumtracked = true, $canviewparticipants = true, $modcontext = null,
                                        $canviewhiddentimedposts = false) {

    global $COURSE, $USER, $CFG, $OUTPUT, $PAGE;

    static $rowcount;
    static $strmarkalldread;

    if (empty($modcontext)) {
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = context_module::instance($cm->id);
    }

    if (!isset($rowcount)) {
        $rowcount = 0;
        $strmarkalldread = get_string('markalldread', 'digestforum');
    } else {
        $rowcount = ($rowcount + 1) % 2;
    }

    $post->subject = format_string($post->subject,true);

    $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);
    $timeddiscussion = !empty($CFG->digestforum_enabletimedposts) && ($post->timestart || $post->timeend);
    $timedoutsidewindow = '';
    if ($timeddiscussion && ($post->timestart > time() || ($post->timeend != 0 && $post->timeend < time()))) {
        $timedoutsidewindow = ' dimmed_text';
    }

    echo "\n\n";
    echo '<tr class="discussion r'.$rowcount.$timedoutsidewindow.'">';

    $topicclass = 'topic starter';
    if (DFORUM_DISCUSSION_PINNED == $post->pinned) {
        $topicclass .= ' pinned';
    }
    echo '<td class="'.$topicclass.'">';
    if (DFORUM_DISCUSSION_PINNED == $post->pinned) {
        echo $OUTPUT->pix_icon('i/pinned', get_string('discussionpinned', 'digestforum'), 'mod_digestforum');
    }
    $canalwaysseetimedpost = $USER->id == $post->userid || $canviewhiddentimedposts;
    if ($timeddiscussion && $canalwaysseetimedpost) {
        echo $PAGE->get_renderer('mod_digestforum')->timed_discussion_tooltip($post, empty($timedoutsidewindow));
    }

    echo '<a href="'.$CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$post->discussion.'">'.$post->subject.'</a>';
    echo "</td>\n";

    // Picture
    $postuser = new stdClass();
    $postuserfields = explode(',', user_picture::fields());
    $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
    $postuser->id = $post->userid;
    echo '<td class="author">';
    echo '<div class="media">';
    echo '<span class="pull-left">';
    echo $OUTPUT->user_picture($postuser, array('courseid'=>$digestforum->course));
    echo '</span>';
    // User name
    echo '<div class="media-body">';
    $fullname = fullname($postuser, $canviewfullnames);
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->userid.'&amp;course='.$digestforum->course.'">'.$fullname.'</a>';
    echo '</div>';
    echo '</div>';
    echo "</td>\n";

    // Group picture
    if ($group !== -1) {  // Groups are active - group is a group data object or NULL
        echo '<td class="picture group">';
        if (!empty($group->picture) and empty($group->hidepicture)) {
            if ($canviewparticipants && $COURSE->groupmode) {
                $picturelink = true;
            } else {
                $picturelink = false;
            }
            print_group_picture($group, $digestforum->course, false, false, $picturelink);
        } else if (isset($group->id)) {
            if ($canviewparticipants && $COURSE->groupmode) {
                echo '<a href="'.$CFG->wwwroot.'/user/index.php?id='.$digestforum->course.'&amp;group='.$group->id.'">'.$group->name.'</a>';
            } else {
                echo $group->name;
            }
        }
        echo "</td>\n";
    }

    if (has_capability('mod/digestforum:viewdiscussion', $modcontext)) {   // Show the column with replies
        echo '<td class="replies">';
        echo '<a href="'.$CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$post->discussion.'">';
        echo $post->replies.'</a>';
        echo "</td>\n";

        if ($cantrack) {
            echo '<td class="replies">';
            if ($digestforumtracked) {
                if ($post->unread > 0) {
                    echo '<span class="unread">';
                    echo '<a href="'.$CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$post->discussion.'#unread">';
                    echo $post->unread;
                    echo '</a>';
                    echo '<a title="'.$strmarkalldread.'" href="'.$CFG->wwwroot.'/mod/digestforum/markposts.php?f='.
                         $digestforum->id.'&amp;d='.$post->discussion.'&amp;mark=read&amp;returnpage=view.php&amp;sesskey=' . sesskey() . '">' .
                         $OUTPUT->pix_icon('t/markasread', $strmarkalldread) . '</a>';
                    echo '</span>';
                } else {
                    echo '<span class="read">';
                    echo $post->unread;
                    echo '</span>';
                }
            } else {
                echo '<span class="read">';
                echo '-';
                echo '</span>';
            }
            echo "</td>\n";
        }
    }

    echo '<td class="lastpost">';
    $usedate = (empty($post->timemodified)) ? $post->created : $post->timemodified;
    $parenturl = '';
    $usermodified = new stdClass();
    $usermodified->id = $post->usermodified;
    $usermodified = username_load_fields_from_object($usermodified, $post, 'um');

    // In QA digestforums we check that the user can view participants.
    if ($digestforum->type !== 'qanda' || $canviewparticipants) {
        echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$digestforum->course.'">'.
             fullname($usermodified, $canviewfullnames).'</a><br />';
        $parenturl = (empty($post->lastpostid)) ? '' : '&amp;parent='.$post->lastpostid;
    }

    echo '<a href="'.$CFG->wwwroot.'/mod/digestforum/discuss.php?d='.$post->discussion.$parenturl.'">'.
          userdate_htmltime($usedate, $datestring).'</a>';
    echo "</td>\n";

    // is_guest should be used here as this also checks whether the user is a guest in the current course.
    // Guests and visitors cannot subscribe - only enrolled users.
    if ((!is_guest($modcontext, $USER) && isloggedin()) && has_capability('mod/digestforum:viewdiscussion', $modcontext)) {
        // Discussion subscription.
        if (\mod_digestforum\subscriptions::is_subscribable($digestforum)) {
            echo '<td class="discussionsubscription">';
            echo digestforum_get_discussion_subscription_icon($digestforum, $post->discussion);
            echo '</td>';
        }
    }

    echo "</tr>\n\n";

}

/**
 * Return the markup for the discussion subscription toggling icon.
 *
 * @param stdClass $digestforum The digestforum object.
 * @param int $discussionid The discussion to create an icon for.
 * @return string The generated markup.
 */
function digestforum_get_discussion_subscription_icon($digestforum, $discussionid, $returnurl = null, $includetext = false) {
    global $USER, $OUTPUT, $PAGE;

    if ($returnurl === null && $PAGE->url) {
        $returnurl = $PAGE->url->out();
    }

    $o = '';
    $subscriptionstatus = \mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforum, $discussionid);
    $subscriptionlink = new moodle_url('/mod/digestforum/subscribe.php', array(
        'sesskey' => sesskey(),
        'id' => $digestforum->id,
        'd' => $discussionid,
        'returnurl' => $returnurl,
    ));

    if ($includetext) {
        $o .= $subscriptionstatus ? get_string('subscribed', 'mod_digestforum') : get_string('notsubscribed', 'mod_digestforum');
    }

    if ($subscriptionstatus) {
        $output = $OUTPUT->pix_icon('t/subscribed', get_string('clicktounsubscribe', 'digestforum'), 'mod_digestforum');
        if ($includetext) {
            $output .= get_string('subscribed', 'mod_digestforum');
        }

        return html_writer::link($subscriptionlink, $output, array(
                'title' => get_string('clicktounsubscribe', 'digestforum'),
                'class' => 'discussiontoggle iconsmall',
                'data-digestforumid' => $digestforum->id,
                'data-discussionid' => $discussionid,
                'data-includetext' => $includetext,
            ));

    } else {
        $output = $OUTPUT->pix_icon('t/unsubscribed', get_string('clicktosubscribe', 'digestforum'), 'mod_digestforum');
        if ($includetext) {
            $output .= get_string('notsubscribed', 'mod_digestforum');
        }

        return html_writer::link($subscriptionlink, $output, array(
                'title' => get_string('clicktosubscribe', 'digestforum'),
                'class' => 'discussiontoggle iconsmall',
                'data-digestforumid' => $digestforum->id,
                'data-discussionid' => $discussionid,
                'data-includetext' => $includetext,
            ));
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
function digestforum_print_mode_form($id, $mode, $digestforumtype='') {
    global $OUTPUT;
    if ($digestforumtype == 'single') {
        $select = new single_select(new moodle_url("/mod/digestforum/view.php", array('f'=>$id)), 'mode', digestforum_get_layout_modes(), $mode, null, "mode");
        $select->set_label(get_string('displaymode', 'digestforum'), array('class' => 'accesshide'));
        $select->class = "digestforummode";
    } else {
        $select = new single_select(new moodle_url("/mod/digestforum/discuss.php", array('d'=>$id)), 'mode', digestforum_get_layout_modes(), $mode, null, "mode");
        $select->set_label(get_string('displaymode', 'digestforum'), array('class' => 'accesshide'));
    }
    echo $OUTPUT->render($select);
}

/**
 * @global object
 * @param object $course
 * @param string $search
 * @return string
 */
function digestforum_search_form($course, $search='') {
    global $CFG, $PAGE;
    $digestforumsearch = new \mod_digestforum\output\quick_search_form($course->id, $search);
    $output = $PAGE->get_renderer('mod_digestforum');
    return $output->render($digestforumsearch);
}


/**
 * @global object
 * @global object
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
 * @global object
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
 * @global object
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

    // loop through all posts, better not use attachment flag ;-)
    if ($posts = $DB->get_records('digestforum_posts', array('discussion'=>$discussion->id), '', 'id, attachment')) {
        foreach ($posts as $post) {
            $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_digestforum', 'post', $post->id);
            $attachmentsmoved = $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_digestforum', 'attachment', $post->id);
            if ($attachmentsmoved > 0 && $post->attachment != '1') {
                // Weird - let's fix it
                $post->attachment = '1';
                $DB->update_record('digestforum_posts', $post);
            } else if ($attachmentsmoved == 0 && $post->attachment != '') {
                // Weird - let's fix it
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
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function digestforum_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!in_array($type, array('separateimages', 'html', 'text'))) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!$context = context_module::instance($cm->id)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }
    $strattachment = get_string('attachment', 'digestforum');

    $fs = get_file_storage();

    $imagereturn = '';
    $output = '';

    $canexport = !empty($CFG->enableportfolios) && (has_capability('mod/digestforum:exportpost', $context) || ($post->userid == $USER->id && has_capability('mod/digestforum:exportownpost', $context)));

    if ($canexport) {
        require_once($CFG->libdir.'/portfoliolib.php');
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
            $iconimage = $OUTPUT->pix_icon(file_file_icon($file), get_mimetype_description($file), 'moodle', array('class' => 'icon'));
            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.'/mod_digestforum/attachment/'.$post->id.'/'.$filename);

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">".s($filename)."</a>";
                if ($canexport) {
                    $button->set_callback_options('digestforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_digestforum');
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= "<br />";

            } else if ($type == 'text') {
                $output .= "$strattachment ".s($filename).":\n$path\n";

            } else { //'returnimages'
                if (in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png'))) {
                    // Image attachments don't get printed as links
                    $imagereturn .= "<br /><img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options('digestforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_digestforum');
                        $button->set_format_by_file($file);
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= format_text("<a href=\"$path\">".s($filename)."</a>", FORMAT_HTML, array('context'=>$context));
                    if ($canexport) {
                        $button->set_callback_options('digestforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_digestforum');
                        $button->set_format_by_file($file);
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                    $output .= '<br />';
                }
            }

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir.'/plagiarismlib.php');
                $output .= plagiarism_get_links(array('userid' => $post->userid,
                    'file' => $file,
                    'cmid' => $cm->id,
                    'course' => $cm->course,
                    'digestforum' => $cm->instance));
                $output .= '<br />';
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;

    } else {
        return array($output, $imagereturn);
    }
}

////////////////////////////////////////////////////////////////////////////////
// File API                                                                   //
////////////////////////////////////////////////////////////////////////////////

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
    return array(
        'attachment' => get_string('areaattachment', 'mod_digestforum'),
        'post' => get_string('areapost', 'mod_digestforum'),
    );
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

    // filearea must contain a real area
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
        require_once($CFG->dirroot.'/mod/digestforum/locallib.php');
        return new digestforum_file_info_container($browser, $course, $cm, $context, $areas, $filearea);
    }

    static $cached = array();
    // $cached will store last retrieved post, discussion and digestforum. To make sure that the cache
    // is cleared between unit tests we check if this is the same session
    if (!isset($cached['sesskey']) || $cached['sesskey'] != sesskey()) {
        $cached = array('sesskey' => sesskey());
    }

    if (isset($cached['post']) && $cached['post']->id == $itemid) {
        $post = $cached['post'];
    } else if ($post = $DB->get_record('digestforum_posts', array('id' => $itemid))) {
        $cached['post'] = $post;
    } else {
        return null;
    }

    if (isset($cached['discussion']) && $cached['discussion']->id == $post->discussion) {
        $discussion = $cached['discussion'];
    } else if ($discussion = $DB->get_record('digestforum_discussions', array('id' => $post->discussion))) {
        $cached['discussion'] = $discussion;
    } else {
        return null;
    }

    if (isset($cached['digestforum']) && $cached['digestforum']->id == $cm->instance) {
        $digestforum = $cached['digestforum'];
    } else if ($digestforum = $DB->get_record('digestforum', array('id' => $cm->instance))) {
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
    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0 && !has_capability('moodle/site:accessallgroups', $context)) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS && !groups_is_member($discussion->groupid)) {
            return null;
        }
    }

    // Make sure we're allowed to see it...
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, NULL, $cm)) {
        return null;
    }

    $urlbase = $CFG->wwwroot.'/pluginfile.php';
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
function digestforum_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $areas = digestforum_get_file_areas($course, $cm, $context);

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('digestforum_posts', array('id'=>$postid))) {
        return false;
    }

    if (!$discussion = $DB->get_record('digestforum_discussions', array('id'=>$post->discussion))) {
        return false;
    }

    if (!$digestforum = $DB->get_record('digestforum', array('id'=>$cm->instance))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_digestforum/$filearea/$postid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS) {
            if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
                return false;
            }
        }
    }

    // Make sure we're allowed to see it...
    if (!digestforum_user_can_see_post($digestforum, $discussion, $post, NULL, $cm)) {
        return false;
    }

    // finally send the file
    send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and digestforum
 * @param object $digestforum
 * @param object $cm
 * @param mixed $mform
 * @param string $unused
 * @return bool
 */
function digestforum_add_attachment($post, $digestforum, $cm, $mform=null, $unused=null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do
    }

    $context = context_module::instance($cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount']>0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'mod_digestforum', 'attachment', $post->id,
            mod_digestforum_post_form::attachment_options($digestforum));

    $DB->set_field('digestforum_posts', 'attachment', $present, array('id'=>$post->id));

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

    $discussion = $DB->get_record('digestforum_discussions', array('id' => $post->discussion));
    $digestforum      = $DB->get_record('digestforum', array('id' => $discussion->digestforum));
    $cm         = get_coursemodule_from_instance('digestforum', $digestforum->id);
    $context    = context_module::instance($cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = DFORUM_MAILED_PENDING;
    $post->userid     = $USER->id;
    $post->attachment = "";
    if (!isset($post->totalscore)) {
        $post->totalscore = 0;
    }
    if (!isset($post->mailnow)) {
        $post->mailnow    = 0;
    }

    $post->id = $DB->insert_record("digestforum_posts", $post);
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_digestforum', 'post', $post->id,
            mod_digestforum_post_form::editor_options($context, null), $post->message);
    $DB->set_field('digestforum_posts', 'message', $post->message, array('id'=>$post->id));
    digestforum_add_attachment($post, $digestforum, $cm, $mform);

    // Update discussion modified date
    $DB->set_field("digestforum_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
    $DB->set_field("digestforum_discussions", "usermodified", $post->userid, array("id" => $post->discussion));

    if (digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
        digestforum_tp_mark_post_read($post->userid, $post);
    }

    if (isset($post->tags)) {
        core_tag_tag::set_item_tags('mod_digestforum', 'digestforum_posts', $post->id, $context, $post->tags);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    digestforum_trigger_content_uploaded_event($post, $cm, 'digestforum_add_new_post');

    return $post->id;
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

    $post       = $DB->get_record('digestforum_posts', array('id' => $newpost->id));
    $discussion = $DB->get_record('digestforum_discussions', array('id' => $post->discussion));
    $digestforum      = $DB->get_record('digestforum', array('id' => $discussion->digestforum));
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

    if (!$post->parent) {   // Post is a discussion starter - update discussion title and times too
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;

        if (isset($post->pinned)) {
            $discussion->pinned = $post->pinned;
        }
    }
    $post->message = file_save_draft_area_files($newpost->itemid, $context->id, 'mod_digestforum', 'post', $post->id,
            mod_digestforum_post_form::editor_options($context, $post->id), $post->message);
    $DB->update_record('digestforum_posts', $post);
    // Note: Discussion modified time/user are intentionally not updated, to enable them to track the latest new post.
    $DB->update_record('digestforum_discussions', $discussion);

    digestforum_add_attachment($post, $digestforum, $cm, $mform);

    if (isset($newpost->tags)) {
        core_tag_tag::set_item_tags('mod_digestforum', 'digestforum_posts', $post->id, $context, $newpost->tags);
    }

    if (digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
        digestforum_tp_mark_post_read($USER->id, $post);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    digestforum_trigger_content_uploaded_event($post, $cm, 'digestforum_update_post');

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @param object $post
 * @param mixed $mform
 * @param string $unused
 * @param int $userid
 * @return object
 */
function digestforum_add_discussion($discussion, $mform=null, $unused=null, $userid=null) {
    global $USER, $CFG, $DB;

    $timenow = isset($discussion->timenow) ? $discussion->timenow : time();

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $digestforum = $DB->get_record('digestforum', array('id'=>$discussion->digestforum));
    $cm    = get_coursemodule_from_instance('digestforum', $digestforum->id);

    $post = new stdClass();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->userid        = $userid;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = DFORUM_MAILED_PENDING;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->message;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = isset($discussion->attachments) ? $discussion->attachments : null;
    $post->digestforum         = $digestforum->id;     // speedup
    $post->course        = $digestforum->course; // speedup
    $post->mailnow       = $discussion->mailnow;

    $post->id = $DB->insert_record("digestforum_posts", $post);

    // TODO: Fix the calling code so that there always is a $cm when this function is called
    if (!empty($cm->id) && !empty($discussion->itemid)) {   // In "single simple discussions" this may not exist yet
        $context = context_module::instance($cm->id);
        $text = file_save_draft_area_files($discussion->itemid, $context->id, 'mod_digestforum', 'post', $post->id,
                mod_digestforum_post_form::editor_options($context, null), $post->message);
        $DB->set_field('digestforum_posts', 'message', $text, array('id'=>$post->id));
    }

    // Now do the main entry for the discussion, linking to this first post

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $userid;
    $discussion->assessed     = 0;

    $post->discussion = $DB->insert_record("digestforum_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("digestforum_posts", "discussion", $post->discussion, array("id"=>$post->id));

    if (!empty($cm->id)) {
        digestforum_add_attachment($post, $digestforum, $cm, $mform, $unused);
    }

    if (isset($discussion->tags)) {
        core_tag_tag::set_item_tags('mod_digestforum', 'digestforum_posts', $post->id, context_module::instance($cm->id), $discussion->tags);
    }

    if (digestforum_tp_can_track_digestforums($digestforum) && digestforum_tp_is_tracked($digestforum)) {
        digestforum_tp_mark_post_read($post->userid, $post);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    if (!empty($cm->id)) {
        digestforum_trigger_content_uploaded_event($post, $cm, 'digestforum_add_discussion');
    }

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire digestforum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $digestforum Forum
 * @return bool
 */
function digestforum_delete_discussion($discussion, $fulldelete, $course, $cm, $digestforum) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("digestforum_posts", array("discussion" => $discussion->id))) {
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
    $DB->delete_records('digestforum_discussion_subs', array('discussion' => $discussion->id));
    if (!$DB->delete_records("digestforum_discussions", array("id" => $discussion->id))) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
           ($digestforum->completiondiscussions || $digestforum->completionreplies || $digestforum->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single digestforum post.
 *
 * @global object
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
function digestforum_delete_post($post, $children, $course, $cm, $digestforum, $skipcompletion=false) {
    global $DB, $CFG, $USER;
    require_once($CFG->libdir.'/completionlib.php');

    $context = context_module::instance($cm->id);

    if ($children !== 'ignore' && ($childposts = $DB->get_records('digestforum_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               digestforum_delete_post($childpost, true, $course, $cm, $digestforum, $skipcompletion);
           }
       } else {
           return false;
       }
    }

    // Delete ratings.
    require_once($CFG->dirroot.'/rating/lib.php');
    $delopt = new stdClass;
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
        require_once($CFG->dirroot.'/mod/digestforum/rsslib.php');
        digestforum_rss_delete_file($digestforum);
    }

    if ($DB->delete_records("digestforum_posts", array("id" => $post->id))) {

        digestforum_tp_delete_read_records(-1, $post->id);

    // Just in case we are deleting the last post
        digestforum_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
               ($digestforum->completiondiscussions || $digestforum->completionreplies || $digestforum->completionposts)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        $params = array(
            'context' => $context,
            'objectid' => $post->id,
            'other' => array(
                'discussionid' => $post->discussion,
                'digestforumid' => $digestforum->id,
                'digestforumtype' => $digestforum->type,
            )
        );
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
    $params = array(
        'context' => $context,
        'objectid' => $post->id,
        'other' => array(
            'content' => $post->message,
            'pathnamehashes' => array_keys($files),
            'discussionid' => $post->discussion,
            'triggeredfrom' => $name,
        )
    );
    $event = \mod_digestforum\event\assessable_uploaded::create($params);
    $event->trigger();
    return true;
}

/**
 * @global object
 * @param object $post
 * @param bool $children
 * @return int
 */
function digestforum_count_replies($post, $children=true) {
    global $DB;
    $count = 0;

    if ($children) {
        if ($childposts = $DB->get_records('digestforum_posts', array('parent' => $post->id))) {
           foreach ($childposts as $childpost) {
               $count ++;                   // For this child
               $count += digestforum_count_replies($childpost, true);
           }
        }
    } else {
        $count += $DB->count_records('digestforum_posts', array('parent' => $post->id));
    }

    return $count;
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
        if ($subscribed && !has_capability('moodle/course:manageactivities', context_course::instance($digestforum->course), $USER->id)) {
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
function digestforum_get_subscribe_link($digestforum, $context, $messages = array(), $cantaccessagroup = false, $unused1 = true,
    $backtoindex = false, $unused2 = null) {
    global $CFG, $USER, $PAGE, $OUTPUT;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'digestforum'),
        'unsubscribed' => get_string('subscribe', 'digestforum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'digestforum'),
        'cantsubscribe' => get_string('disallowsubscribe','digestforum')
    );
    $messages = $messages + $defaultmessages;

    if (\mod_digestforum\subscriptions::is_forcesubscribed($digestforum)) {
        return $messages['forcesubscribed'];
    } else if (\mod_digestforum\subscriptions::subscription_disabled($digestforum) &&
            !has_capability('mod/digestforum:managesubscriptions', $context)) {
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

        $options = array();
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }

        $options['id'] = $digestforum->id;
        $options['sesskey'] = sesskey();
        $url = new moodle_url('/mod/digestforum/subscribe.php', $options);
        return $OUTPUT->single_button($url, $linktext, 'get', array('title' => $linktitle));
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
 * @global object
 * @global object
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

    return $DB->get_records_sql($haspostedsql, array($digestforumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $digestforumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function digestforum_user_has_posted($digestforumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any digestforum discussion?
        $sql = "SELECT 'x'
                  FROM {digestforum_posts} p
                  JOIN {digestforum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.digestforum = :digestforumid";
        return $DB->record_exists_sql($sql, array('digestforumid'=>$digestforumid,'userid'=>$userid));
    } else {
        return $DB->record_exists('digestforum_posts', array('discussion'=>$did,'userid'=>$userid));
    }
}

/**
 * Returns creation time of the first user's post in given discussion
 * @global object $DB
 * @param int $did Discussion id
 * @param int $userid User id
 * @return int|bool post creation time stamp or return false
 */
function digestforum_get_user_posted_time($did, $userid) {
    global $DB;

    $posttime = $DB->get_field('digestforum_posts', 'MIN(created)', array('userid'=>$userid, 'discussion'=>$did));
    if (empty($posttime)) {
        return false;
    }
    return $posttime;
}

/**
 * @global object
 * @param object $digestforum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function digestforum_user_can_post_discussion($digestforum, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $digestforum is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
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

    if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // no group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a digestforum
 * discussion. Use digestforum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
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
function digestforum_user_can_post($digestforum, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $digestforum->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    // Check whether the discussion is locked.
    if (digestforum_discussion_is_locked($digestforum, $discussion)) {
        if (!has_capability('mod/digestforum:canoverridediscussionlock', $context)) {
            return false;
        }
    }

    // normal users with temporary guest access can not post, suspended users can not post either
    if (!is_viewing($context, $user->id) and !is_enrolled($context, $user->id, '', true)) {
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
            // allow students to reply to all participants discussions - this was not possible in Moodle <1.8
            return true;
        }
        return groups_is_member($discussion->groupid);

    } else {
        //separate groups
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
        if (($discussion->timestart != 0 && $discussion->timestart > $time)
            || ($discussion->timeend != 0 && $discussion->timeend < $time)) {
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
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @param object $digestforum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function digestforum_user_can_see_discussion($digestforum, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($digestforum)) {
        debugging('missing full digestforum', DEBUG_DEVELOPER);
        if (!$digestforum = $DB->get_record('digestforum',array('id'=>$digestforum))) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('digestforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
        print_error('invalidcoursemodule');
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

    // retrieve objects (yuk)
    if (is_numeric($digestforum)) {
        debugging('missing full digestforum', DEBUG_DEVELOPER);
        if (!$digestforum = $DB->get_record('digestforum',array('id'=>$digestforum))) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('digestforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('digestforum_posts',array('id'=>$post))) {
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
            print_error('invalidcoursemodule');
        }
    }

    // Context used throughout function.
    $modcontext = context_module::instance($cm->id);

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    $canviewdiscussion = (isset($cm->cache) && !empty($cm->cache->caps['mod/digestforum:viewdiscussion']))
        || has_capability('mod/digestforum:viewdiscussion', $modcontext, $user->id);
    if (!$canviewdiscussion && !has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), context_user::instance($post->userid))) {
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
        if (has_capability('mod/digestforum:viewqandawithoutposting', $modcontext, $user->id) || $post->userid == $user->id
                || (isset($discussion->firstpost) && $discussion->firstpost == $post->id)) {
            return true;
        }
        $firstpost = digestforum_get_firstpost_from_discussion($discussion->id);
        if ($firstpost->userid == $user->id) {
            return true;
        }
        $userfirstpost = digestforum_get_user_posted_time($discussion->id, $user->id);
        return (($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime)));
    }
    return true;
}


/**
 * Prints the discussion view screen for a digestforum.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $digestforum Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $displayformat The display format to use (optional).
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the digestforum (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 * @param boolean $subscriptionstatus Whether the user is currently subscribed to the discussion in some fashion.
 *
 */
function digestforum_print_latest_discussions($course, $digestforum, $maxdiscussions = -1, $displayformat = 'plain', $sort = '',
                                        $currentgroup = -1, $groupmode = -1, $page = -1, $perpage = 100, $cm = null) {
    global $CFG, $USER, $OUTPUT;

    require_once($CFG->dirroot . '/course/lib.php');

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = context_module::instance($cm->id);

    if (empty($sort)) {
        $sort = digestforum_get_default_sort_order();
    }

    $olddiscussionlink = false;

 // Sort out some defaults
    if ($perpage <= 0) {
        $perpage = 0;
        $page    = -1;
    }

    if ($maxdiscussions == 0) {
        // all discussions - backwards compatibility
        $page    = -1;
        $perpage = 0;
        if ($displayformat == 'plain') {
            $displayformat = 'header';  // Abbreviate display by default
        }

    } else if ($maxdiscussions > 0) {
        $page    = -1;
        $perpage = $maxdiscussions;
    }

    $fullpost = false;
    if ($displayformat == 'plain') {
        $fullpost = true;
    }


// Decide if current user is allowed to see ALL the current discussions or not

// First check the group stuff
    if ($currentgroup == -1 or $groupmode == -1) {
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
    }

    $groups = array(); //cache

// If the user can post discussions, then this is a good place to put the
// button for it. We do not show the button if we are showing site news
// and the current user is a guest.

    $canstart = digestforum_user_can_post_discussion($digestforum, $currentgroup, $groupmode, $cm, $context);
    if (!$canstart and $digestforum->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canstart = true;
        }
        if (!is_enrolled($context) and !is_viewing($context)) {
            // allow guests and not-logged-in to see the button - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this button too, they are asked to enrol instead
            // do not show the button to users with suspended enrolments here
            $canstart = enrol_selfenrol_available($course->id);
        }
    }

    if ($canstart) {
        switch ($digestforum->type) {
            case 'news':
            case 'blog':
                $buttonadd = get_string('addanewtopic', 'digestforum');
                break;
            case 'qanda':
                $buttonadd = get_string('addanewquestion', 'digestforum');
                break;
            default:
                $buttonadd = get_string('addanewdiscussion', 'digestforum');
                break;
        }
        $button = new single_button(new moodle_url('/mod/digestforum/post.php', ['digestforum' => $digestforum->id]), $buttonadd, 'get');
        $button->class = 'singlebutton digestforumaddnew';
        $button->formid = 'newdiscussionform';
        echo $OUTPUT->render($button);

    } else if (isguestuser() or !isloggedin() or $digestforum->type == 'news' or
        $digestforum->type == 'qanda' and !has_capability('mod/digestforum:addquestion', $context) or
        $digestforum->type != 'qanda' and !has_capability('mod/digestforum:startdiscussion', $context)) {
        // no button and no info

    } else if ($groupmode and !has_capability('moodle/site:accessallgroups', $context)) {
        // inform users why they can not post new discussion
        if (!$currentgroup) {
            if (!has_capability('mod/digestforum:canposttomygroups', $context)) {
                echo $OUTPUT->notification(get_string('cannotadddiscussiongroup', 'digestforum'));
            } else {
                echo $OUTPUT->notification(get_string('cannotadddiscussionall', 'digestforum'));
            }
        } else if (!groups_is_member($currentgroup)) {
            echo $OUTPUT->notification(get_string('cannotadddiscussion', 'digestforum'));
        }
    }

// Get all the recent discussions we're allowed to see

    $getuserlastmodified = ($displayformat == 'header');

    if (! $discussions = digestforum_get_discussions($cm, $sort, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage) ) {
        echo '<div class="digestforumnodiscuss">';
        if ($digestforum->type == 'news') {
            echo '('.get_string('nonews', 'digestforum').')';
        } else if ($digestforum->type == 'qanda') {
            echo '('.get_string('noquestions','digestforum').')';
        } else {
            echo '('.get_string('nodiscussions', 'digestforum').')';
        }
        echo "</div>\n";
        return;
    }

// If we want paging
    if ($page != -1) {
        ///Get the number of discussions found
        $numdiscussions = digestforum_get_discussions_count($cm);

        ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$digestforum->id");
        if ($numdiscussions > 1000) {
            // saves some memory on sites with very large digestforums
            $replies = digestforum_count_discussion_replies($digestforum->id, $sort, $maxdiscussions, $page, $perpage);
        } else {
            $replies = digestforum_count_discussion_replies($digestforum->id);
        }

    } else {
        $replies = digestforum_count_discussion_replies($digestforum->id);

        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }

    $canviewparticipants = course_can_view_participants($context);
    $canviewhiddentimedposts = has_capability('mod/digestforum:viewhiddentimedposts', $context);

    $strdatestring = get_string('strftimerecentfull');

    // Check if the digestforum is tracked.
    if ($cantrack = digestforum_tp_can_track_digestforums($digestforum)) {
        $digestforumtracked = digestforum_tp_is_tracked($digestforum);
    } else {
        $digestforumtracked = false;
    }

    if ($digestforumtracked) {
        $unreads = digestforum_get_discussions_unread($cm);
    } else {
        $unreads = array();
    }

    if ($displayformat == 'header') {
        echo '<table cellspacing="0" class="digestforumheaderlist">';
        echo '<thead class="text-left">';
        echo '<tr>';
        echo '<th class="header topic" scope="col">'.get_string('discussion', 'digestforum').'</th>';
        echo '<th class="header author" scope="col">'.get_string('startedby', 'digestforum').'</th>';
        if ($groupmode > 0) {
            echo '<th class="header group" scope="col">'.get_string('group').'</th>';
        }
        if (has_capability('mod/digestforum:viewdiscussion', $context)) {
            echo '<th class="header replies" scope="col">'.get_string('replies', 'digestforum').'</th>';
            // If the digestforum can be tracked, display the unread column.
            if ($cantrack) {
                echo '<th class="header replies" scope="col">'.get_string('unread', 'digestforum');
                if ($digestforumtracked) {
                    echo '<a title="'.get_string('markallread', 'digestforum').
                         '" href="'.$CFG->wwwroot.'/mod/digestforum/markposts.php?f='.
                         $digestforum->id.'&amp;mark=read&amp;returnpage=view.php&amp;sesskey=' . sesskey() . '">'.
                         $OUTPUT->pix_icon('t/markasread', get_string('markallread', 'digestforum')) . '</a>';
                }
                echo '</th>';
            }
        }
        echo '<th class="header lastpost" scope="col">'.get_string('lastpost', 'digestforum').'</th>';
        if ((!is_guest($context, $USER) && isloggedin()) && has_capability('mod/digestforum:viewdiscussion', $context)) {
            if (\mod_digestforum\subscriptions::is_subscribable($digestforum)) {
                echo '<th class="header discussionsubscription" scope="col">';
                echo digestforum_get_discussion_subscription_icon_preloaders();
                echo '</th>';
            }
        }
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
    }

    foreach ($discussions as $discussion) {
        if ($digestforum->type == 'qanda' && !has_capability('mod/digestforum:viewqandawithoutposting', $context) &&
            !digestforum_user_has_posted($digestforum->id, $discussion->discussion, $USER->id)) {
            $canviewparticipants = false;
        }

        if (!empty($replies[$discussion->discussion])) {
            $discussion->replies = $replies[$discussion->discussion]->replies;
            $discussion->lastpostid = $replies[$discussion->discussion]->lastpostid;
        } else {
            $discussion->replies = 0;
        }

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // All posts are read in this case.
        if (!$digestforumtracked) {
            $discussion->unread = '-';
        } else if (empty($USER)) {
            $discussion->unread = 0;
        } else {
            if (empty($unreads[$discussion->discussion])) {
                $discussion->unread = 0;
            } else {
                $discussion->unread = $unreads[$discussion->discussion];
            }
        }

        if (isloggedin()) {
            $ownpost = ($discussion->userid == $USER->id);
        } else {
            $ownpost=false;
        }
        // Use discussion name instead of subject of first post.
        $discussion->subject = $discussion->name;

        switch ($displayformat) {
            case 'header':
                if ($groupmode > 0) {
                    if (isset($groups[$discussion->groupid])) {
                        $group = $groups[$discussion->groupid];
                    } else {
                        $group = $groups[$discussion->groupid] = groups_get_group($discussion->groupid);
                    }
                } else {
                    $group = -1;
                }
                digestforum_print_discussion_header($discussion, $digestforum, $group, $strdatestring, $cantrack, $digestforumtracked,
                    $canviewparticipants, $context, $canviewhiddentimedposts);
            break;
            default:
                $link = false;

                if ($discussion->replies) {
                    $link = true;
                } else {
                    $modcontext = context_module::instance($cm->id);
                    $link = digestforum_user_can_see_discussion($digestforum, $discussion, $modcontext, $USER);
                }

                $discussion->digestforum = $digestforum->id;

                digestforum_print_post_start($discussion);
                digestforum_print_post($discussion, $discussion, $digestforum, $cm, $course, $ownpost, 0, $link, false,
                        '', null, true, $digestforumtracked);
                digestforum_print_post_end($discussion);
            break;
        }
    }

    if ($displayformat == "header") {
        echo '</tbody>';
        echo '</table>';
    }

    if ($olddiscussionlink) {
        if ($digestforum->type == 'news') {
            $strolder = get_string('oldertopics', 'digestforum');
        } else {
            $strolder = get_string('olderdiscussions', 'digestforum');
        }
        echo '<div class="digestforumolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/digestforum/view.php?f='.$digestforum->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    if ($page != -1) { ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$digestforum->id");
    }
}


/**
 * Prints a digestforum discussion
 *
 * @uses CONTEXT_MODULE
 * @uses DFORUM_MODE_FLATNEWEST
 * @uses DFORUM_MODE_FLATOLDEST
 * @uses DFORUM_MODE_THREADED
 * @uses DFORUM_MODE_NESTED
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $digestforum
 * @param stdClass $discussion
 * @param stdClass $post
 * @param int $mode
 * @param mixed $canreply
 * @param bool $canrate
 */
function digestforum_print_discussion($course, $cm, $digestforum, $discussion, $post, $mode, $canreply=NULL, $canrate=false) {
    global $USER, $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ownpost = (isloggedin() && $USER->id == $post->userid);

    $modcontext = context_module::instance($cm->id);
    if ($canreply === NULL) {
        $reply = digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // $cm holds general cache for digestforum functions
    $cm->cache = new stdClass;
    $cm->cache->groups      = groups_get_all_groups($course->id, 0, $cm->groupingid);
    $cm->cache->usersgroups = array();

    $posters = array();

    // preload all posts - TODO: improve...
    if ($mode == DFORUM_MODE_FLATNEWEST) {
        $sort = "p.created DESC";
    } else {
        $sort = "p.created ASC";
    }

    $digestforumtracked = digestforum_tp_is_tracked($digestforum);
    $posts = digestforum_get_all_discussion_posts($discussion->id, $sort, $digestforumtracked);
    $post = $posts[$post->id];

    foreach ($posts as $pid=>$p) {
        $posters[$p->userid] = $p->userid;
    }

    // preload all groups of ppl that posted in this discussion
    if ($postersgroups = groups_get_all_groups($course->id, $posters, $cm->groupingid, 'gm.id, gm.groupid, gm.userid')) {
        foreach($postersgroups as $pg) {
            if (!isset($cm->cache->usersgroups[$pg->userid])) {
                $cm->cache->usersgroups[$pg->userid] = array();
            }
            $cm->cache->usersgroups[$pg->userid][$pg->groupid] = $pg->groupid;
        }
        unset($postersgroups);
    }

    //load ratings
    if ($digestforum->assessed != RATING_AGGREGATE_NONE) {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $modcontext;
        $ratingoptions->component = 'mod_digestforum';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $posts;
        $ratingoptions->aggregate = $digestforum->assessed;//the aggregation method
        $ratingoptions->scaleid = $digestforum->scale;
        $ratingoptions->userid = $USER->id;
        if ($digestforum->type == 'single' or !$discussion->id) {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/digestforum/view.php?id=$cm->id";
        } else {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/digestforum/discuss.php?d=$discussion->id";
        }
        $ratingoptions->assesstimestart = $digestforum->assesstimestart;
        $ratingoptions->assesstimefinish = $digestforum->assesstimefinish;

        $rm = new rating_manager();
        $posts = $rm->get_ratings($ratingoptions);
    }


    $post->digestforum = $digestforum->id;   // Add the digestforum id to the post object, later used by digestforum_print_post
    $post->digestforumtype = $digestforum->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);

    digestforum_print_post_start($post);
    digestforum_print_post($post, $discussion, $digestforum, $cm, $course, $ownpost, $reply, false,
                         '', '', $postread, true, $digestforumtracked);

    switch ($mode) {
        case DFORUM_MODE_FLATOLDEST :
        case DFORUM_MODE_FLATNEWEST :
        default:
            digestforum_print_posts_flat($course, $cm, $digestforum, $discussion, $post, $mode, $reply, $digestforumtracked, $posts);
            break;

        case DFORUM_MODE_THREADED :
            digestforum_print_posts_threaded($course, $cm, $digestforum, $discussion, $post, 0, $reply, $digestforumtracked, $posts);
            break;

        case DFORUM_MODE_NESTED :
            digestforum_print_posts_nested($course, $cm, $digestforum, $discussion, $post, $reply, $digestforumtracked, $posts);
            break;
    }
    digestforum_print_post_end($post);
}


/**
 * @global object
 * @global object
 * @uses DFORUM_MODE_FLATNEWEST
 * @param object $course
 * @param object $cm
 * @param object $digestforum
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param bool $reply
 * @param bool $digestforumtracked
 * @param array $posts
 * @return void
 */
function digestforum_print_posts_flat($course, &$cm, $digestforum, $discussion, $post, $mode, $reply, $digestforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    foreach ($posts as $post) {
        if (!$post->parent) {
            continue;
        }
        $post->subject = format_string($post->subject);
        $ownpost = ($USER->id == $post->userid);

        $postread = !empty($post->postread);

        digestforum_print_post_start($post);
        digestforum_print_post($post, $discussion, $digestforum, $cm, $course, $ownpost, $reply, $link,
                             '', '', $postread, true, $digestforumtracked);
        digestforum_print_post_end($post);
    }
}

/**
 * @todo Document this function
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return void
 */
function digestforum_print_posts_threaded($course, &$cm, $digestforum, $discussion, $parent, $depth, $reply, $digestforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        $modcontext       = context_module::instance($cm->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if ($depth > 0) {
                $ownpost = ($USER->id == $post->userid);
                $post->subject = format_string($post->subject);

                $postread = !empty($post->postread);

                digestforum_print_post_start($post);
                digestforum_print_post($post, $discussion, $digestforum, $cm, $course, $ownpost, $reply, $link,
                                     '', '', $postread, true, $digestforumtracked);
                digestforum_print_post_end($post);
            } else {
                if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm, true)) {
                    if (digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm, false)) {
                        // This post has been deleted but still exists and may have children.
                        $subject = get_string('privacy:request:delete:post:subject', 'mod_digestforum');
                        $byline = '';
                    } else {
                        // The user can't see this post at all.
                        echo "</div>\n";
                        continue;
                    }
                } else {
                    $by = new stdClass();
                    $by->name = fullname($post, $canviewfullnames);
                    $by->date = userdate_htmltime($post->modified);
                    $byline = ' ' . get_string("bynameondate", "digestforum", $by);
                    $subject = format_string($post->subject, true);
                }

                if ($digestforumtracked) {
                    if (!empty($post->postread)) {
                        $style = '<span class="digestforumthread read">';
                    } else {
                        $style = '<span class="digestforumthread unread">';
                    }
                } else {
                    $style = '<span class="digestforumthread">';
                }

                echo $style;
                echo "<a name='{$post->id}'></a>";
                echo html_writer::link(new moodle_url('/mod/digestforum/discuss.php', [
                        'd' => $post->discussion,
                        'parent' => $post->id,
                    ]), $subject);
                echo $byline;
                echo "</span>";
            }

            digestforum_print_posts_threaded($course, $cm, $digestforum, $discussion, $post, $depth-1, $reply, $digestforumtracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * @todo Document this function
 * @global object
 * @global object
 * @return void
 */
function digestforum_print_posts_nested($course, &$cm, $digestforum, $discussion, $parent, $reply, $digestforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if (!isloggedin()) {
                $ownpost = false;
            } else {
                $ownpost = ($USER->id == $post->userid);
            }

            $post->subject = format_string($post->subject);
            $postread = !empty($post->postread);

            digestforum_print_post_start($post);
            digestforum_print_post($post, $discussion, $digestforum, $cm, $course, $ownpost, $reply, $link,
                                 '', '', $postread, true, $digestforumtracked);
            digestforum_print_posts_nested($course, $cm, $digestforum, $discussion, $post, $reply, $digestforumtracked, $posts);
            digestforum_print_post_end($post);
            echo "</div>\n";
        }
    }
}

/**
 * Returns all digestforum posts since a given time in specified digestforum.
 *
 * @todo Document this functions args
 * @global object
 * @global object
 * @global object
 * @global object
 */
function digestforum_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $params = array($timestart, $cm->instance);

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

    $allnames = get_all_user_name_fields(true, 'u');
    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS digestforumtype, d.digestforum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              $allnames, u.email, u.picture, u.imagealt, u.email
                                         FROM {digestforum_posts} p
                                              JOIN {digestforum_discussions} d ON d.id = p.discussion
                                              JOIN {digestforum} f             ON f.id = d.digestforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = context_module::instance($cm->id);
    $viewhiddentimed = has_capability('mod/digestforum:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($CFG->digestforum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or $accessallgroups) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
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

    $aname = format_string($cm->name,true);

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
        $additionalfields = array('id' => 'userid', 'picture', 'imagealt', 'email');
        $additionalfields = explode(',', user_picture::fields());
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
        'class' => 'digestforum-recent'
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
        $output .= $OUTPUT->image_icon('icon', $aname, $activity->type);
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
 * @global object
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function digestforum_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('digestforum_posts', 'discussion', $discussionid, array('id' => $postid));
    if ($posts = $DB->get_records('digestforum_posts', array('parent' => $postid))) {
        foreach ($posts as $post) {
            digestforum_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

/**
 * Prints the editing button on subscribers page
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param int $digestforumid
 * @return string
 */
function digestforum_update_subscriptions_button($courseid, $digestforumid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('managesubscriptionsoff', 'digestforum');
        $edit = "off";
    } else {
        $string = get_string('managesubscriptionson', 'digestforum');
        $edit = "on";
    }

    $subscribers = html_writer::start_tag('form', array('action' => $CFG->wwwroot . '/mod/digestforum/subscribers.php',
        'method' => 'get', 'class' => 'form-inline'));
    $subscribers .= html_writer::empty_tag('input', array('type' => 'submit', 'value' => $string,
        'class' => 'btn btn-secondary'));
    $subscribers .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'id', 'value' => $digestforumid));
    $subscribers .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'edit', 'value' => $edit));
    $subscribers .= html_writer::end_tag('form');

    return $subscribers;
}

// Functions to do with read tracking.

/**
 * Mark posts as read.
 *
 * @global object
 * @global object
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

    list($usql, $postidparams) = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'postid');

    $insertparams = array(
        'userid1' => $user->id,
        'userid2' => $user->id,
        'userid3' => $user->id,
        'firstread' => $now,
        'lastread' => $now,
        'cutoffdate' => $cutoffdate,
    );
    $params = array_merge($postidparams, $insertparams);

    if ($CFG->digestforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = ".DFORUM_TRACKING_FORCED."
                        OR (f.trackingtype = ".DFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))";
    } else {
        $trackingsql = "AND ((f.trackingtype = ".DFORUM_TRACKING_OPTIONAL."  OR f.trackingtype = ".DFORUM_TRACKING_FORCED.")
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
    $updateparams = array(
        'userid' => $user->id,
        'lastread' => $now,
    );
    $params = array_merge($postidparams, $updateparams);
    $status = $DB->set_field_select('digestforum_read', 'lastread', $now, '
                userid      =  :userid
            AND lastread    <> :lastread
            AND postid      ' . $usql,
            $params) && $status;

    return $status;
}

/**
 * Mark post as read.
 * @global object
 * @global object
 * @param int $userid
 * @param int $postid
 */
function digestforum_tp_add_read_record($userid, $postid) {
    global $CFG, $DB;

    $now = time();
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('digestforum_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {digestforum_read} (userid, postid, discussionid, digestforumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.digestforum, ?, ?
                  FROM {digestforum_posts} p
                       JOIN {digestforum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, array($userid, $now, $now, $postid, $cutoffdate));

    } else {
        $sql = "UPDATE {digestforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, array($now, $userid, $userid));
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
 * @global object
 * @global object
 * @param object $user
 * @param int $digestforumid
 * @param int|bool $groupid
 * @return bool
 */
function digestforum_tp_mark_digestforum_read($user, $digestforumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->digestforum_oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $digestforumid, $cutoffdate);

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
 * @global object
 * @global object
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function digestforum_tp_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->digestforum_oldpostdays*24*60*60);

    $sql = "SELECT p.id
              FROM {digestforum_posts} p
                   LEFT JOIN {digestforum_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, array($user->id, $discussionid, $cutoffdate))) {
        $postids = array_keys($posts);
        return digestforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * @global object
 * @param int $userid
 * @param object $post
 */
function digestforum_tp_is_post_read($userid, $post) {
    global $DB;
    return (digestforum_tp_is_post_old($post) ||
            $DB->record_exists('digestforum_read', array('userid' => $userid, 'postid' => $post->id)));
}

/**
 * @global object
 * @param object $post
 * @param int $time Defautls to time()
 */
function digestforum_tp_is_post_old($post, $time=null) {
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
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function digestforum_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $now = floor(time() / 60) * 60; // DB cache friendliness.
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays * 24 * 60 * 60);
    $params = array($userid, $userid, $courseid, $cutoffdate, $userid);

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    if ($CFG->digestforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = ".DFORUM_TRACKING_FORCED."
                            OR (f.trackingtype = ".DFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL
                                AND (SELECT trackforums FROM {user} WHERE id = ?) = 1))";
    } else {
        $trackingsql = "AND ((f.trackingtype = ".DFORUM_TRACKING_OPTIONAL." OR f.trackingtype = ".DFORUM_TRACKING_FORCED.")
                            AND tf.id IS NULL
                            AND (SELECT trackforums FROM {user} WHERE id = ?) = 1)";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {digestforum_posts} p
                   JOIN {digestforum_discussions} d       ON d.id = p.discussion
                   JOIN {digestforum} f                   ON f.id = d.digestforum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {digestforum_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {digestforum_track_prefs} tf ON (tf.userid = ? AND tf.digestforumid = f.id)
             WHERE f.course = ?
                   AND p.modified >= ? AND r.id is NULL
                   $trackingsql
                   $timedsql
          GROUP BY f.id";

    if ($return = $DB->get_records_sql($sql, $params)) {
        return $return;
    }

    return array();
}

/**
 * Returns the count of records for the provided user and digestforum and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @param bool   $resetreadcache optional, true to reset the function static $readcache var
 * @return int
 */
function digestforum_tp_count_digestforum_unread_posts($cm, $course, $resetreadcache = false) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    if ($resetreadcache) {
        $readcache = array();
    }

    $digestforumid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = digestforum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$digestforumid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$digestforumid];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $readcache[$course->id][$digestforumid];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // add all groups posts
    $mygroups[-1] = -1;

    list ($groups_sql, $groups_params) = $DB->get_in_or_equal($mygroups);

    $now = floor(time() / 60) * 60; // DB Cache friendliness.
    $cutoffdate = $now - ($CFG->digestforum_oldpostdays*24*60*60);
    $params = array($USER->id, $digestforumid, $cutoffdate);

    if (!empty($CFG->digestforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groups_params);

    $sql = "SELECT COUNT(p.id)
              FROM {digestforum_posts} p
                   JOIN {digestforum_discussions} d ON p.discussion = d.id
                   LEFT JOIN {digestforum_read} r   ON (r.postid = p.id AND r.userid = ?)
             WHERE d.digestforum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $timedsql
                   AND d.groupid $groups_sql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified index. At least one parameter must be specified.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $digestforumid
 * @return bool
 */
function digestforum_tp_delete_read_records($userid=-1, $postid=-1, $discussionid=-1, $digestforumid=-1) {
    global $DB;
    $params = array();

    $select = '';
    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($digestforumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'digestforumid = ?';
        $params[] = $digestforumid;
    }
    if ($select == '') {
        return false;
    }
    else {
        return $DB->delete_records_select('digestforum_read', $select, $params);
    }
}
/**
 * Get a list of digestforums not tracked by the user.
 *
 * @global object
 * @global object
 * @param int $userid The id of the user to use.
 * @param int $courseid The id of the course being checked.
 * @return mixed An array indexed by digestforum id, or false.
 */
function digestforum_tp_get_untracked_digestforums($userid, $courseid) {
    global $CFG, $DB;

    if ($CFG->digestforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = ".DFORUM_TRACKING_OFF."
                            OR (f.trackingtype = ".DFORUM_TRACKING_OPTIONAL." AND (ft.id IS NOT NULL
                                OR (SELECT trackforums FROM {user} WHERE id = ?) = 0)))";
    } else {
        $trackingsql = "AND (f.trackingtype = ".DFORUM_TRACKING_OFF."
                            OR ((f.trackingtype = ".DFORUM_TRACKING_OPTIONAL." OR f.trackingtype = ".DFORUM_TRACKING_FORCED.")
                                AND (ft.id IS NOT NULL
                                    OR (SELECT trackforums FROM {user} WHERE id = ?) = 0)))";
    }

    $sql = "SELECT f.id
              FROM {digestforum} f
                   LEFT JOIN {digestforum_track_prefs} ft ON (ft.digestforumid = f.id AND ft.userid = ?)
             WHERE f.course = ?
                   $trackingsql";

    if ($digestforums = $DB->get_records_sql($sql, array($userid, $courseid, $userid))) {
        foreach ($digestforums as $digestforum) {
            $digestforums[$digestforum->id] = $digestforum;
        }
        return $digestforums;

    } else {
        return array();
    }
}

/**
 * Determine if a user can track digestforums and optionally a particular digestforum.
 * Checks the site settings, the user settings and the digestforum settings (if
 * requested).
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $digestforum The digestforum object to test, or the int id (optional).
 * @param mixed $userid The user object to check for (optional).
 * @return boolean
 */
function digestforum_tp_can_track_digestforums($digestforum=false, $user=false) {
    global $USER, $CFG, $DB;

    // if possible, avoid expensive
    // queries
    if (empty($CFG->digestforum_trackreadposts)) {
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
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
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum), '', 'id,trackingtype');
    }

    $digestforumallows = ($digestforum->trackingtype == DFORUM_TRACKING_OPTIONAL);
    $digestforumforced = ($digestforum->trackingtype == DFORUM_TRACKING_FORCED);

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
 * @global object
 * @global object
 * @global object
 * @param mixed $digestforum If int, the id of the digestforum being checked; if object, the digestforum object
 * @param int $userid The id of the user being checked (optional).
 * @return boolean
 */
function digestforum_tp_is_tracked($digestforum, $user=false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    // Work toward always passing an object...
    if (is_numeric($digestforum)) {
        debugging('Better use proper digestforum object.', DEBUG_DEVELOPER);
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum));
    }

    if (!digestforum_tp_can_track_digestforums($digestforum, $user)) {
        return false;
    }

    $digestforumallows = ($digestforum->trackingtype == DFORUM_TRACKING_OPTIONAL);
    $digestforumforced = ($digestforum->trackingtype == DFORUM_TRACKING_FORCED);
    $userpref = $DB->get_record('digestforum_track_prefs', array('userid' => $user->id, 'digestforumid' => $digestforum->id));

    if ($CFG->digestforum_allowforcedreadtracking) {
        return $digestforumforced || ($digestforumallows && $userpref === false);
    } else {
        return  ($digestforumallows || $digestforumforced) && $userpref === false;
    }
}

/**
 * @global object
 * @global object
 * @param int $digestforumid
 * @param int $userid
 */
function digestforum_tp_start_tracking($digestforumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('digestforum_track_prefs', array('userid' => $userid, 'digestforumid' => $digestforumid));
}

/**
 * @global object
 * @global object
 * @param int $digestforumid
 * @param int $userid
 */
function digestforum_tp_stop_tracking($digestforumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('digestforum_track_prefs', array('userid' => $userid, 'digestforumid' => $digestforumid))) {
        $track_prefs = new stdClass();
        $track_prefs->userid = $userid;
        $track_prefs->digestforumid = $digestforumid;
        $DB->insert_record('digestforum_track_prefs', $track_prefs);
    }

    return digestforum_tp_delete_read_records($userid, -1, -1, $digestforumid);
}


/**
 * Clean old records from the digestforum_read table.
 * @global object
 * @global object
 * @return void
 */
function digestforum_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->digestforum_oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the digestforum_read table.
    $cutoffdate = time() - ($CFG->digestforum_oldpostdays*24*60*60);

    //first get the oldest tracking present - we need tis to speedup the next delete query
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {digestforum_posts} fp
                   JOIN {digestforum_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // nothing to delete;
        return;
    }

    // now delete old tracking info
    $sql = "DELETE
              FROM {digestforum_read}
             WHERE postid IN (SELECT fp.id
                                FROM {digestforum_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, array($first, $cutoffdate));
}

/**
 * Sets the last post for a given discussion
 *
 * @global object
 * @global object
 * @param into $discussionid
 * @return bool|int
 **/
function digestforum_discussion_update_last_post($discussionid) {
    global $CFG, $DB;

// Check the given discussion exists
    if (!$DB->record_exists('digestforum_discussions', array('id' => $discussionid))) {
        return false;
    }

// Use SQL to find the last post for this discussion
    $sql = "SELECT id, userid, modified
              FROM {digestforum_posts}
             WHERE discussion=?
             ORDER BY modified DESC";

// Lets go find the last post
    if (($lastposts = $DB->get_records_sql($sql, array($discussionid), 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new stdClass();
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        $DB->update_record('digestforum_discussions', $discussionobject);
        return $lastpost->id;
    }

// To get here either we couldn't find a post for the discussion (weird)
// or we couldn't update the discussion record (weird x2)
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
    return array('view discussion', 'search', 'digestforum', 'digestforums', 'subscribers', 'view digestforum');
}

/**
 * List the options for digestforum subscription modes.
 * This is used by the settings page and by the mod_form page.
 *
 * @return array
 */
function digestforum_get_subscriptionmode_options() {
    $options = array();
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
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
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
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum), '*', MUST_EXIST);
    }

    if (!is_object($digestforum)) {
        return false; // This is broken.
    }

    if (!$cm) {
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course, false, MUST_EXIST);
    }

    if (empty($digestforum->blockafter)) {
        return false;
    }

    if (empty($digestforum->blockperiod)) {
        return false;
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
                                        AND p.userid = ? AND p.created > ?', array($digestforum->id, $USER->id, $timeafter));

    $a = new stdClass();
    $a->blockafter = $digestforum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$digestforum->blockperiod);

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
        print_error($thresholdwarning->errorcode,
                    $thresholdwarning->module,
                    $thresholdwarning->link,
                    $thresholdwarning->additional);
    }
}


/**
 * Removes all grades from gradebook
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type optional
 */
function digestforum_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $wheresql = '';
    $params = array($courseid);
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {digestforum} f, {course_modules} cm, {modules} m
             WHERE m.name='digestforum' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($digestforums = $DB->get_records_sql($sql, $params)) {
        foreach ($digestforums as $digestforum) {
            digestforum_grade_item_update($digestforum, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified digestforum
 * and clean up any related data.
 *
 * @global object
 * @global object
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function digestforum_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot.'/rating/lib.php');

    $componentstr = get_string('modulenameplural', 'digestforum');
    $status = array();

    $params = array($data->courseid);

    $removeposts = false;
    $typesql     = "";
    if (!empty($data->reset_digestforum_all)) {
        $removeposts = true;
        $typesstr    = get_string('resetdigestforumsall', 'digestforum');
        $types       = array();
    } else if (!empty($data->reset_digestforum_types)){
        $removeposts = true;
        $types       = array();
        $sqltypes    = array();
        $digestforum_types_all = digestforum_get_digestforum_types_all();
        foreach ($data->reset_digestforum_types as $type) {
            if (!array_key_exists($type, $digestforum_types_all)) {
                continue;
            }
            $types[] = $digestforum_types_all[$type];
            $sqltypes[] = $type;
        }
        if (!empty($sqltypes)) {
            list($typesql, $typeparams) = $DB->get_in_or_equal($sqltypes);
            $typesql = " AND f.type " . $typesql;
            $params = array_merge($params, $typeparams);
        }
        $typesstr = get_string('resetdigestforums', 'digestforum').': '.implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {digestforum_discussions} fd, {digestforum} f
                           WHERE f.course=? AND f.id=fd.digestforum";

    $alldigestforumssql      = "SELECT f.id
                            FROM {digestforum} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {digestforum_posts} fp, {digestforum_discussions} fd, {digestforum} f
                           WHERE f.course=? AND f.id=fd.digestforum AND fd.id=fp.discussion";

    $digestforumssql = $digestforums = $rm = null;

    // Check if we need to get additional data.
    if ($removeposts || !empty($data->reset_digestforum_ratings) || !empty($data->reset_digestforum_tags)) {
        // Set this up if we have to remove ratings.
        $rm = new rating_manager();
        $ratingdeloptions = new stdClass;
        $ratingdeloptions->component = 'mod_digestforum';
        $ratingdeloptions->ratingarea = 'post';

        // Get the digestforums for actions that require it.
        $digestforumssql = "$alldigestforumssql $typesql";
        $digestforums = $DB->get_records_sql($digestforumssql, $params);
    }

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $postssql       = "$allpostssql $typesql";

        // now get rid of all attachments
        $fs = get_file_storage();
        if ($digestforums) {
            foreach ($digestforums as $digestforumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('digestforum', $digestforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);
                $fs->delete_area_files($context->id, 'mod_digestforum', 'attachment');
                $fs->delete_area_files($context->id, 'mod_digestforum', 'post');

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);

                core_tag_tag::delete_instances('mod_digestforum', null, $context->id);
            }
        }

        // first delete all read flags
        $DB->delete_records_select('digestforum_read', "digestforumid IN ($digestforumssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('digestforum_track_prefs', "digestforumid IN ($digestforumssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('digestforum_queue', "discussionid IN ($discussionssql)", $params);

        // all posts - initial posts must be kept in single simple discussion digestforums
        $DB->delete_records_select('digestforum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('digestforum_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple digestforums
        $DB->delete_records_select('digestforum_discussions', "digestforum IN ($digestforumssql AND f.type <> 'single')", $params);

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                digestforum_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    digestforum_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = array('component'=>$componentstr, 'item'=>$typesstr, 'error'=>false);
    }

    // remove all ratings in this course's digestforums
    if (!empty($data->reset_digestforum_ratings)) {
        if ($digestforums) {
            foreach ($digestforums as $digestforumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('digestforum', $digestforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // remove all grades from gradebook
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

        $status[] = array('component' => $componentstr, 'item' => get_string('tagsdeleted', 'digestforum'), 'error' => false);
    }

    // remove all digest settings unconditionally - even for users still enrolled in course.
    if (!empty($data->reset_digestforum_digests)) {
        $DB->delete_records_select('digestforum_digests', "digestforum IN ($alldigestforumssql)", $params);
        $status[] = array('component' => $componentstr, 'item' => get_string('resetdigests', 'digestforum'), 'error' => false);
    }

    // remove all subscriptions unconditionally - even for users still enrolled in course
    if (!empty($data->reset_digestforum_subscriptions)) {
        $DB->delete_records_select('digestforum_subscriptions', "digestforum IN ($alldigestforumssql)", $params);
        $DB->delete_records_select('digestforum_discussion_subs', "digestforum IN ($alldigestforumssql)", $params);
        $status[] = array('component' => $componentstr, 'item' => get_string('resetsubscriptions', 'digestforum'), 'error' => false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_digestforum_track_prefs)) {
        $DB->delete_records_select('digestforum_track_prefs', "digestforumid IN ($alldigestforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resettrackprefs','digestforum'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        shift_course_mod_dates('digestforum', array('assesstimestart', 'assesstimefinish'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param $mform form passed by reference
 */
function digestforum_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'digestforumheader', get_string('modulenameplural', 'digestforum'));

    $mform->addElement('checkbox', 'reset_digestforum_all', get_string('resetdigestforumsall','digestforum'));

    $mform->addElement('select', 'reset_digestforum_types', get_string('resetdigestforums', 'digestforum'), digestforum_get_digestforum_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_digestforum_types');
    $mform->disabledIf('reset_digestforum_types', 'reset_digestforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_digestforum_digests', get_string('resetdigests','digestforum'));
    $mform->setAdvanced('reset_digestforum_digests');

    $mform->addElement('checkbox', 'reset_digestforum_subscriptions', get_string('resetsubscriptions','digestforum'));
    $mform->setAdvanced('reset_digestforum_subscriptions');

    $mform->addElement('checkbox', 'reset_digestforum_track_prefs', get_string('resettrackprefs','digestforum'));
    $mform->setAdvanced('reset_digestforum_track_prefs');
    $mform->disabledIf('reset_digestforum_track_prefs', 'reset_digestforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_digestforum_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_digestforum_ratings', 'reset_digestforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_digestforum_tags', get_string('removealldigestforumtags', 'digestforum'));
    $mform->disabledIf('reset_digestforum_tags', 'reset_digestforum_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function digestforum_reset_course_form_defaults($course) {
    return array('reset_digestforum_all'=>1, 'reset_digestforum_digests' => 0, 'reset_digestforum_subscriptions'=>0, 'reset_digestforum_track_prefs'=>0, 'reset_digestforum_ratings'=>1);
}

/**
 * Returns array of digestforum layout modes
 *
 * @return array
 */
function digestforum_get_layout_modes() {
    return array (DFORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'digestforum'),
                  DFORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'digestforum'),
                  DFORUM_MODE_THREADED   => get_string('modethreaded', 'digestforum'),
                  DFORUM_MODE_NESTED     => get_string('modenested', 'digestforum'));
}

/**
 * Returns array of digestforum types chooseable on the digestforum editing form
 *
 * @return array
 */
function digestforum_get_digestforum_types() {
    return array ('general'  => get_string('generaldigestforum', 'digestforum'),
                  'eachuser' => get_string('eachuserdigestforum', 'digestforum'),
                  'single'   => get_string('singledigestforum', 'digestforum'),
                  'qanda'    => get_string('qandadigestforum', 'digestforum'),
                  'blog'     => get_string('blogdigestforum', 'digestforum'));
}

/**
 * Returns array of all digestforum layout modes
 *
 * @return array
 */
function digestforum_get_digestforum_types_all() {
    return array ('news'     => get_string('namenews','digestforum'),
                  'social'   => get_string('namesocial','digestforum'),
                  'general'  => get_string('generaldigestforum', 'digestforum'),
                  'eachuser' => get_string('eachuserdigestforum', 'digestforum'),
                  'single'   => get_string('singledigestforum', 'digestforum'),
                  'qanda'    => get_string('qandadigestforum', 'digestforum'),
                  'blog'     => get_string('blogdigestforum', 'digestforum'));
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
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $digestforumnode The node to add module settings to
 */
function digestforum_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $digestforumnode) {
    global $USER, $PAGE, $CFG, $DB, $OUTPUT;

    $digestforumobject = $DB->get_record("digestforum", array("id" => $PAGE->cm->instance));
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = context_module::instance($PAGE->cm->instance);
    }

    $params = $PAGE->url->params();
    if (!empty($params['d'])) {
        $discussionid = $params['d'];
    }

    // for some actions you need to be enrolled, beiing admin is not enough sometimes here
    $enrolled = is_enrolled($PAGE->cm->context, $USER, '', false);
    $activeenrolled = is_enrolled($PAGE->cm->context, $USER, '', true);

    $canmanage  = has_capability('mod/digestforum:managesubscriptions', $PAGE->cm->context);
    $subscriptionmode = \mod_digestforum\subscriptions::get_subscription_mode($digestforumobject);
    $cansubscribe = $activeenrolled && !\mod_digestforum\subscriptions::is_forcesubscribed($digestforumobject) &&
            (!\mod_digestforum\subscriptions::subscription_disabled($digestforumobject) || $canmanage);

    if ($canmanage) {
        $mode = $digestforumnode->add(get_string('subscriptionmode', 'digestforum'), null, navigation_node::TYPE_CONTAINER);
        $mode->add_class('subscriptionmode');

        $allowchoice = $mode->add(get_string('subscriptionoptional', 'digestforum'), new moodle_url('/mod/digestforum/subscribe.php', array('id'=>$digestforumobject->id, 'mode'=>DFORUM_CHOOSESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceforever = $mode->add(get_string("subscriptionforced", "digestforum"), new moodle_url('/mod/digestforum/subscribe.php', array('id'=>$digestforumobject->id, 'mode'=>DFORUM_FORCESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceinitially = $mode->add(get_string("subscriptionauto", "digestforum"), new moodle_url('/mod/digestforum/subscribe.php', array('id'=>$digestforumobject->id, 'mode'=>DFORUM_INITIALSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $disallowchoice = $mode->add(get_string('subscriptiondisabled', 'digestforum'), new moodle_url('/mod/digestforum/subscribe.php', array('id'=>$digestforumobject->id, 'mode'=>DFORUM_DISALLOWSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);

        switch ($subscriptionmode) {
            case DFORUM_CHOOSESUBSCRIBE : // 0
                $allowchoice->action = null;
                $allowchoice->add_class('activesetting');
                $allowchoice->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
            case DFORUM_FORCESUBSCRIBE : // 1
                $forceforever->action = null;
                $forceforever->add_class('activesetting');
                $forceforever->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
            case DFORUM_INITIALSUBSCRIBE : // 2
                $forceinitially->action = null;
                $forceinitially->add_class('activesetting');
                $forceinitially->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
            case DFORUM_DISALLOWSUBSCRIBE : // 3
                $disallowchoice->action = null;
                $disallowchoice->add_class('activesetting');
                $disallowchoice->icon = new pix_icon('t/selected', '', 'mod_digestforum');
                break;
        }

    } else if ($activeenrolled) {

        switch ($subscriptionmode) {
            case DFORUM_CHOOSESUBSCRIBE : // 0
                $notenode = $digestforumnode->add(get_string('subscriptionoptional', 'digestforum'));
                break;
            case DFORUM_FORCESUBSCRIBE : // 1
                $notenode = $digestforumnode->add(get_string('subscriptionforced', 'digestforum'));
                break;
            case DFORUM_INITIALSUBSCRIBE : // 2
                $notenode = $digestforumnode->add(get_string('subscriptionauto', 'digestforum'));
                break;
            case DFORUM_DISALLOWSUBSCRIBE : // 3
                $notenode = $digestforumnode->add(get_string('subscriptiondisabled', 'digestforum'));
                break;
        }
    }

    if ($cansubscribe) {
        if (\mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforumobject, null, $PAGE->cm)) {
            $linktext = get_string('unsubscribe', 'digestforum');
        } else {
            $linktext = get_string('subscribe', 'digestforum');
        }
        $url = new moodle_url('/mod/digestforum/subscribe.php', array('id'=>$digestforumobject->id, 'sesskey'=>sesskey()));
        $digestforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);

        if (isset($discussionid)) {
            if (\mod_digestforum\subscriptions::is_subscribed($USER->id, $digestforumobject, $discussionid, $PAGE->cm)) {
                $linktext = get_string('unsubscribediscussion', 'digestforum');
            } else {
                $linktext = get_string('subscribediscussion', 'digestforum');
            }
            $url = new moodle_url('/mod/digestforum/subscribe.php', array(
                    'id' => $digestforumobject->id,
                    'sesskey' => sesskey(),
                    'd' => $discussionid,
                    'returnurl' => $PAGE->url->out(),
                ));
            $digestforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if (has_capability('mod/digestforum:viewsubscribers', $PAGE->cm->context)){
        $url = new moodle_url('/mod/digestforum/subscribers.php', array('id'=>$digestforumobject->id));
        $digestforumnode->add(get_string('showsubscribers', 'digestforum'), $url, navigation_node::TYPE_SETTING);
    }

    if ($enrolled && digestforum_tp_can_track_digestforums($digestforumobject)) { // keep tracking info for users with suspended enrolments
        if ($digestforumobject->trackingtype == DFORUM_TRACKING_OPTIONAL
                || ((!$CFG->digestforum_allowforcedreadtracking) && $digestforumobject->trackingtype == DFORUM_TRACKING_FORCED)) {
            if (digestforum_tp_is_tracked($digestforumobject)) {
                $linktext = get_string('notrackdigestforum', 'digestforum');
            } else {
                $linktext = get_string('trackdigestforum', 'digestforum');
            }
            $url = new moodle_url('/mod/digestforum/settracking.php', array(
                    'id' => $digestforumobject->id,
                    'sesskey' => sesskey(),
                ));
            $digestforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if (!isloggedin() && $PAGE->course->id == SITEID) {
        $userid = guest_user()->id;
    } else {
        $userid = $USER->id;
    }

    $hascourseaccess = ($PAGE->course->id == SITEID) || can_access_course($PAGE->course, $userid);
    $enablerssfeeds = !empty($CFG->enablerssfeeds) && !empty($CFG->digestforum_enablerssfeeds);

    if ($enablerssfeeds && $digestforumobject->rsstype && $digestforumobject->rssarticles && $hascourseaccess) {

        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($digestforumobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions','digestforum');
        } else {
            $string = get_string('rsssubscriberssposts','digestforum');
        }

        $url = new moodle_url(rss_get_url($PAGE->cm->context->id, $userid, "mod_digestforum", $digestforumobject->id));
        $digestforumnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }
}

/**
 * Adds information about unread messages, that is only required for the course view page (and
 * similar), to the course-module object.
 * @param cm_info $cm Course-module object
 */
function digestforum_cm_info_view(cm_info $cm) {
    global $CFG;

    if (digestforum_tp_can_track_digestforums()) {
        if ($unread = digestforum_tp_count_digestforum_unread_posts($cm, $cm->get_course())) {
            $out = '<span class="unread"> <a href="' . $cm->url . '#unread">';
            if ($unread == 1) {
                $out .= get_string('unreadpostsone', 'digestforum');
            } else {
                $out .= get_string('unreadpostsnumber', 'digestforum', $unread);
            }
            $out .= '</a></span>';
            $cm->set_after_link($out);
        }
    }
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function digestforum_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $digestforum_pagetype = array(
        'mod-digestforum-*'=>get_string('page-mod-digestforum-x', 'digestforum'),
        'mod-digestforum-view'=>get_string('page-mod-digestforum-view', 'digestforum'),
        'mod-digestforum-discuss'=>get_string('page-mod-digestforum-discuss', 'digestforum')
    );
    return $digestforum_pagetype;
}

/**
 * Gets all of the courses where the provided user has posted in a digestforum.
 *
 * @global moodle_database $DB The database connection
 * @param stdClass $user The user who's posts we are looking for
 * @param bool $discussionsonly If true only look for discussions started by the user
 * @param bool $includecontexts If set to trye contexts for the courses will be preloaded
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of courses
 */
function digestforum_get_courses_user_posted_in($user, $discussionsonly = false, $includecontexts = true, $limitfrom = null, $limitnum = null) {
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
        $subquery= "(SELECT DISTINCT fd.course
                         FROM {digestforum_discussions} fd
                        WHERE fd.userid = :userid )";
    }

    $params = array('userid' => $user->id);

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
 * @global moodle_database $DB
 * @param stdClass $user
 * @param array $courseids An array of courseids to search or if not provided
 *                       all courses the user has posted within
 * @param bool $discussionsonly If true then only digestforums where the user has started
 *                       a discussion will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of digestforums the user has posted within in the provided courses
 */
function digestforum_get_digestforums_user_posted_in($user, array $courseids = null, $discussionsonly = false, $limitfrom = null, $limitnum = null) {
    global $DB;

    if (!is_null($courseids)) {
        list($coursewhere, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $coursewhere = ' AND f.course '.$coursewhere;
    } else {
        $coursewhere = '';
        $params = array();
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
 * @global moodle_database $DB
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
function digestforum_get_posts_by_user($user, array $courses, $musthaveaccess = false, $discussionsonly = false, $limitfrom = 0, $limitnum = 50) {
    global $DB, $USER, $CFG;

    $return = new stdClass;
    $return->totalcount = 0;    // The total number of posts that the current user is able to view
    $return->courses = array(); // The courses the current user can access
    $return->digestforums = array();  // The digestforums that the current user can access that contain posts
    $return->posts = array();   // The posts to display

    // First up a small sanity check. If there are no courses to check we can
    // return immediately, there is obviously nothing to search.
    if (empty($courses)) {
        return $return;
    }

    // A couple of quick setups
    $isloggedin = isloggedin();
    $isguestuser = $isloggedin && isguestuser();
    $iscurrentuser = $isloggedin && $USER->id == $user->id;

    // Checkout whether or not the current user has capabilities over the requested
    // user and if so they have the capabilities required to view the requested
    // users content.
    $usercontext = context_user::instance($user->id, MUST_EXIST);
    $hascapsonuser = !$iscurrentuser && $DB->record_exists('role_assignments', array('userid' => $USER->id, 'contextid' => $usercontext->id));
    $hascapsonuser = $hascapsonuser && has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), $usercontext);

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
                // Need to have full access to a course to see the rest of own info
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'digestforum');
                }
                continue;
            }
        } else {
            // Check whether the current user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course)) {
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'digestforum');
                }
                continue;
            }

            // If groups are in use and enforced throughout the course then make sure
            // we can meet in at least one course level group.
            // Note that we check if either the current user or the requested user have
            // the capability to access all groups. This is because with that capability
            // a user in group A could post in the group B digestforum. Grrrr.
            if (groups_get_course_groupmode($course) == SEPARATEGROUPS && $course->groupmodeforce
              && !has_capability('moodle/site:accessallgroups', $coursecontext) && !has_capability('moodle/site:accessallgroups', $coursecontext, $user->id)) {
                // If its the guest user to bad... the guest user cannot access groups
                if (!$isloggedin or $isguestuser) {
                    // do not use require_login() here because we might have already used require_login($course)
                    if ($musthaveaccess) {
                        redirect(get_login_url());
                    }
                    continue;
                }
                // Get the groups of the current user
                $mygroups = array_keys(groups_get_all_groups($course->id, $USER->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Get the groups the requested user is a member of
                $usergroups = array_keys(groups_get_all_groups($course->id, $user->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Check whether they are members of the same group. If they are great.
                $intersect = array_intersect($mygroups, $usergroups);
                if (empty($intersect)) {
                    // But they're not... if it was a specific course throw an error otherwise
                    // just skip this course so that it is not searched.
                    if ($musthaveaccess) {
                        print_error("groupnotamember", '', $CFG->wwwroot."/course/view.php?id=$course->id");
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
    // No longer beed $courses array - lose it not it may be big
    unset($courses);

    // Make sure that we have some courses to search
    if (empty($return->courses)) {
        // If we don't have any courses to search then the reality is that the current
        // user doesn't have access to any courses is which the requested user has posted.
        // Although we do know at this point that the requested user has posts.
        if ($musthaveaccess) {
            print_error('permissiondenied');
        } else {
            return $return;
        }
    }

    // Next step: Collect all of the digestforums that we will want to search.
    // It is important to note that this step isn't actually about searching, it is
    // about determining which digestforums we can search by testing accessibility.
    $digestforums = digestforum_get_digestforums_user_posted_in($user, array_keys($return->courses), $discussionsonly);

    // Will be used to build the where conditions for the search
    $digestforumsearchwhere = array();
    // Will be used to store the where condition params for the search
    $digestforumsearchparams = array();
    // Will record digestforums where the user can freely access everything
    $digestforumsearchfullaccess = array();
    // DB caching friendly
    $now = floor(time() / 60) * 60;
    // For each course to search we want to find the digestforums the user has posted in
    // and providing the current user can access the digestforum create a search condition
    // for the digestforum to get the requested users posts.
    foreach ($return->courses as $course) {
        // Now we need to get the digestforums
        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->instances['digestforum'])) {
            // hmmm, no digestforums? well at least its easy... skip!
            continue;
        }
        // Iterate
        foreach ($modinfo->get_instances_of('digestforum') as $digestforumid => $cm) {
            if (!$cm->uservisible or !isset($digestforums[$digestforumid])) {
                continue;
            }
            // Get the digestforum in question
            $digestforum = $digestforums[$digestforumid];

            // This is needed for functionality later on in the digestforum code. It is converted to an object
            // because the cm_info is readonly from 2.6. This is a dirty hack because some other parts of the
            // code were expecting an writeable object. See {@link digestforum_print_post()}.
            $digestforum->cm = new stdClass();
            foreach ($cm as $key => $value) {
                $digestforum->cm->$key = $value;
            }

            // Check that either the current user can view the digestforum, or that the
            // current user has capabilities over the requested user and the requested
            // user can view the discussion
            if (!has_capability('mod/digestforum:viewdiscussion', $cm->context) && !($hascapsonuser && has_capability('mod/digestforum:viewdiscussion', $cm->context, $user->id))) {
                continue;
            }

            // This will contain digestforum specific where clauses
            $digestforumsearchselect = array();
            if (!$iscurrentuser && !$hascapsonuser) {
                // Make sure we check group access
                if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $cm->context)) {
                    $groups = $modinfo->get_groups($cm->groupingid);
                    $groups[] = -1;
                    list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'grps'.$digestforumid.'_');
                    $digestforumsearchparams = array_merge($digestforumsearchparams, $groupid_params);
                    $digestforumsearchselect[] = "d.groupid $groupid_sql";
                }

                // hidden timed discussions
                if (!empty($CFG->digestforum_enabletimedposts) && !has_capability('mod/digestforum:viewhiddentimedposts', $cm->context)) {
                    $digestforumsearchselect[] = "(d.userid = :userid{$digestforumid} OR (d.timestart < :timestart{$digestforumid} AND (d.timeend = 0 OR d.timeend > :timeend{$digestforumid})))";
                    $digestforumsearchparams['userid'.$digestforumid] = $user->id;
                    $digestforumsearchparams['timestart'.$digestforumid] = $now;
                    $digestforumsearchparams['timeend'.$digestforumid] = $now;
                }

                // qanda access
                if ($digestforum->type == 'qanda' && !has_capability('mod/digestforum:viewqandawithoutposting', $cm->context)) {
                    // We need to check whether the user has posted in the qanda digestforum.
                    $discussionspostedin = digestforum_discussions_user_has_posted_in($digestforum->id, $user->id);
                    if (!empty($discussionspostedin)) {
                        $digestforumonlydiscussions = array();  // Holds discussion ids for the discussions the user is allowed to see in this digestforum.
                        foreach ($discussionspostedin as $d) {
                            $digestforumonlydiscussions[] = $d->id;
                        }
                        list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($digestforumonlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$digestforumid.'_');
                        $digestforumsearchparams = array_merge($digestforumsearchparams, $discussionid_params);
                        $digestforumsearchselect[] = "(d.id $discussionid_sql OR p.parent = 0)";
                    } else {
                        $digestforumsearchselect[] = "p.parent = 0";
                    }

                }

                if (count($digestforumsearchselect) > 0) {
                    $digestforumsearchwhere[] = "(d.digestforum = :digestforum{$digestforumid} AND ".implode(" AND ", $digestforumsearchselect).")";
                    $digestforumsearchparams['digestforum'.$digestforumid] = $digestforumid;
                } else {
                    $digestforumsearchfullaccess[] = $digestforumid;
                }
            } else {
                // The current user/parent can see all of their own posts
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
        list($fullidsql, $fullidparams) = $DB->get_in_or_equal($digestforumsearchfullaccess, SQL_PARAMS_NAMED, 'fula');
        $digestforumsearchparams = array_merge($digestforumsearchparams, $fullidparams);
        $digestforumsearchwhere[] = "(d.digestforum $fullidsql)";
    }

    // Prepare SQL to both count and search.
    // We alias user.id to useridx because we digestforum_posts already has a userid field and not aliasing this would break
    // oracle and mssql.
    $userfields = user_picture::fields('u', null, 'useridx');
    $countsql = 'SELECT COUNT(*) ';
    $selectsql = 'SELECT p.*, d.digestforum, d.name AS discussionname, '.$userfields.' ';
    $wheresql = implode(" OR ", $digestforumsearchwhere);

    if ($discussionsonly) {
        if ($wheresql == '') {
            $wheresql = 'p.parent = 0';
        } else {
            $wheresql = 'p.parent = 0 AND ('.$wheresql.')';
        }
    }

    $sql = "FROM {digestforum_posts} p
            JOIN {digestforum_discussions} d ON d.id = p.discussion
            JOIN {user} u ON u.id = p.userid
           WHERE ($wheresql)
             AND p.userid = :userid ";
    $orderby = "ORDER BY p.modified DESC";
    $digestforumsearchparams['userid'] = $user->id;

    // Set the total number posts made by the requested user that the current user can see
    $return->totalcount = $DB->count_records_sql($countsql.$sql, $digestforumsearchparams);
    // Set the collection of posts that has been requested
    $return->posts = $DB->get_records_sql($selectsql.$sql.$orderby, $digestforumsearchparams, $limitfrom, $limitnum);

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
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum));
    }

    if ($user === null) {
        $user = $USER;
    }

    $course  = $DB->get_record('course', array('id' => $digestforum->course), '*', MUST_EXIST);
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
    $subscription = $DB->get_record('digestforum_digests', array(
        'userid' => $user->id,
        'digestforum' => $digestforum->id,
    ));

    // Create or Update the existing maildigest setting.
    if ($subscription) {
        if ($maildigest == -1) {
            $DB->delete_records('digestforum_digests', array('digestforum' => $digestforum->id, 'userid' => $user->id));
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
    return 1; //hack, but isn't this the easy way to force it to be digest forum?
    /*
    if (isset($digests[$digestforumid]) && isset($digests[$digestforumid][$user->id])) {
        $maildigest = $digests[$digestforumid][$user->id];
        if ($maildigest === -1) {
            $maildigest = $user->maildigest;
        }
    } else {
        $maildigest = $user->maildigest;
    }
    return $maildigest;
    */
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

    $digestoptions = array();
    //$digestoptions['0']  = get_string('emaildigestoffshort', 'mod_digestforum');
    $digestoptions['1']  = get_string('emaildigestcompleteshort', 'mod_digestforum');
    //$digestoptions['2']  = get_string('emaildigestsubjectsshort', 'mod_digestforum');

    // We need to add the default digest option at the end - it relies on
    // the contents of the existing values.
    $digestoptions['-1'] = get_string('emaildigestdefault', 'mod_digestforum',
            $digestoptions['1']);
            //$digestoptions[$user->maildigest]);

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
        if ($PAGE->cm && $PAGE->cm->modname === 'digestforum' && $PAGE->cm->instance == $digestforumid
                && $PAGE->context->contextlevel == CONTEXT_MODULE && $PAGE->context->instanceid == $PAGE->cm->id) {
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

    $params = array(
        'context' => $context,
        'objectid' => $digestforum->id
    );

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
    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
    );

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

    $DB->set_field('digestforum_discussions', 'pinned', DFORUM_DISCUSSION_PINNED, array('id' => $discussion->id));

    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'other' => array('digestforumid' => $digestforum->id)
    );

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

    $DB->set_field('digestforum_discussions', 'pinned', DFORUM_DISCUSSION_UNPINNED, array('id' => $discussion->id));

    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'other' => array('digestforumid' => $digestforum->id)
    );

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
    $postsurl = new moodle_url('/mod/digestforum/user.php', array('id' => $user->id));
    if (!empty($course)) {
        $postsurl->param('course', $course->id);
    }
    $string = get_string('digestforumposts', 'mod_digestforum');
    $node = new core_user\output\myprofile\node('miscellaneous', 'digestforumposts', $string, null, $postsurl);
    $tree->add_node($node);

    $discussionssurl = new moodle_url('/mod/digestforum/user.php', array('id' => $user->id, 'mode' => 'discussions'));
    if (!empty($course)) {
        $discussionssurl->param('course', $course->id);
    }
    $string = get_string('myprofileotherdis', 'mod_digestforum');
    $node = new core_user\output\myprofile\node('miscellaneous', 'digestforumdiscussions', $string, null,
        $discussionssurl);
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
        $digestforum   = $DB->get_record('digestforum', array('id' => $itemid), '*', MUST_EXIST);
        $course  = $DB->get_record('course', array('id' => $digestforum->course), '*', MUST_EXIST);
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
 * Determine whether the specified discussion is time-locked.
 *
 * @param   stdClass    $digestforum          The digestforum that the discussion belongs to
 * @param   stdClass    $discussion     The discussion to test
 * @return  bool
 */
function digestforum_discussion_is_locked($digestforum, $discussion) {
    if (empty($digestforum->lockdiscussionafter)) {
        return false;
    }

    if ($digestforum->type === 'single') {
        // It does not make sense to lock a single discussion digestforum.
        return false;
    }

    if (($discussion->timemodified + $digestforum->lockdiscussionafter) < time()) {
        return true;
    }

    return false;
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
function digestforum_check_updates_since(cm_info $cm, $from, $filter = array()) {

    $context = $cm->context;
    $updates = new stdClass();
    if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
        return $updates;
    }

    $updates = course_check_module_updates_since($cm, $from, array(), $filter);

    // Check if there are new discussions in the digestforum.
    $updates->discussions = (object) array('updated' => false);
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
    if (empty($digestforum->maxattachments) || $digestforum->maxbytes == 1 ||
            !has_capability('mod/digestforum:createattachment', $context)) {
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
        'mod_digestforum:i/pinned' => 'fa-map-pin',
        'mod_digestforum:t/selected' => 'fa-check',
        'mod_digestforum:t/subscribed' => 'fa-envelope-o',
        'mod_digestforum:t/unsubscribed' => 'fa-envelope-open-o',
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
function mod_digestforum_core_calendar_provide_event_action(calendar_event $event,
                                                      \core_calendar\action_factory $factory,
                                                      int $userid = 0) {
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
    $digestforum = $DB->get_record('digestforum', array('id' => $cm->instance));
    $postcountsql = "
                SELECT
                    COUNT(1)
                  FROM
                    {digestforum_posts} fp
                    INNER JOIN {digestforum_discussions} fd ON fp.discussion=fd.id
                 WHERE
                    fp.userid=:userid AND fd.digestforum=:digestforumid";
    $postcountparams = array('userid' => $userid, 'digestforumid' => $digestforum->id);

    if ($digestforum->completiondiscussions) {
        $count = $DB->count_records('digestforum_discussions', array('digestforum' => $digestforum->id, 'userid' => $userid));
        $itemcount += ($digestforum->completiondiscussions >= $count) ? ($digestforum->completiondiscussions - $count) : 0;
    }

    if ($digestforum->completionreplies) {
        $count = $DB->get_field_sql( $postcountsql.' AND fp.parent<>0', $postcountparams);
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
    $fields = 'id, name, intro, introformat, completionposts, completiondiscussions, completionreplies';
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
    if (empty($cm->customdata['customcompletionrules'])
        || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
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
 * Returns a user's open rate for a specific digestforum for this month
 *
 * @param int $userid mdluserid of the user in question
 * @param int $forumid instance id of the digestforum
 * @param string $digestdate, in the format of 'yyyy-mm-dd'. if omitted, then all time
 * @return double percentage open rate, or -1 if not enough data
 */
function mod_digestforum_get_month_open_rate($userid, $forumid, $digestdate = 0) {
    global $DB;

    // Make sure $digestdate is yyyy-mm-dd.
    $pattern = '/^\d{4}-\d{2}-\d{2}$/';
    if ($digestdate != 0 && !preg_match($pattern, $digestdate)) {
        $digestdate = 0;
    }

    $sql = 'SELECT
             COUNT(case when numviews > 0 then 1 end) AS NumOpened,
             COUNT(case when numviews = 0 then 1 end) AS NotOpened
              FROM {digestforum_tracker}
             WHERE digestforumid = :forumid AND mdluserid = :userid';

    if ($digestdate != 0) {
        // Get for the whole month.
        $digestdate = substr($digestdate, 0, -2).'%';
        $sql .= ' AND digestdate like :digestdate';
    }

    $opened = $DB->get_record_sql($sql, ['forumid' => $forumid,
        'userid' => $userid, 'digestdate' => $digestdate]);

    $pctopened = -1;
    $total = $opened->numopened + $opened->notopened;
    if ($opened && $total > 0) {
        $pctopened = 100 * ($opened->numopened) / $total;
    }

    return sprintf("%6.2f", $pctopened);
}
