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
 * Privacy Subsystem implementation for mod_digestforum.
 *
 * @package    mod_digestforum
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\privacy;

use \core_privacy\local\request\userlist;
use \core_privacy\local\request\approved_contextlist;
use \core_privacy\local\request\approved_userlist;
use \core_privacy\local\request\deletion_criteria;
use \core_privacy\local\request\writer;
use \core_privacy\local\request\helper as request_helper;
use \core_privacy\local\metadata\collection;
use \core_privacy\local\request\transform;

defined('MOODLE_INTERNAL') || die();

/**
 * Implementation of the privacy subsystem plugin provider for the digestforum activity module.
 *
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    // This plugin has data.
    \core_privacy\local\metadata\provider,

    // This plugin currently implements the original plugin\provider interface.
    \core_privacy\local\request\plugin\provider,

    // This plugin is capable of determining which users have data within it.
    \core_privacy\local\request\core_userlist_provider,

    // This plugin has some sitewide user preferences to export.
    \core_privacy\local\request\user_preference_provider
{

    use subcontext_info;

    /**
     * Returns meta data about this system.
     *
     * @param   collection     $items The initialised collection to add items to.
     * @return  collection     A listing of user data stored through this system.
     */
    public static function get_metadata(collection $items) : collection {
        // The 'digestforum' table does not store any specific user data.
        $items->add_database_table('digestforum_digests', [
            'digestforum' => 'privacy:metadata:digestforum_digests:digestforum',
            'userid' => 'privacy:metadata:digestforum_digests:userid',
            'maildigest' => 'privacy:metadata:digestforum_digests:maildigest',
        ], 'privacy:metadata:digestforum_digests');

        // The 'digestforum_discussions' table stores the metadata about each digestforum discussion.
        $items->add_database_table('digestforum_discussions', [
            'name' => 'privacy:metadata:digestforum_discussions:name',
            'userid' => 'privacy:metadata:digestforum_discussions:userid',
            'assessed' => 'privacy:metadata:digestforum_discussions:assessed',
            'timemodified' => 'privacy:metadata:digestforum_discussions:timemodified',
            'usermodified' => 'privacy:metadata:digestforum_discussions:usermodified',
        ], 'privacy:metadata:digestforum_discussions');

        // The 'digestforum_discussion_subs' table stores information about which discussions a user is subscribed to.
        $items->add_database_table('digestforum_discussion_subs', [
            'discussionid' => 'privacy:metadata:digestforum_discussion_subs:discussionid',
            'preference' => 'privacy:metadata:digestforum_discussion_subs:preference',
            'userid' => 'privacy:metadata:digestforum_discussion_subs:userid',
        ], 'privacy:metadata:digestforum_discussion_subs');

        // The 'digestforum_posts' table stores the metadata about each digestforum discussion.
        $items->add_database_table('digestforum_posts', [
            'discussion' => 'privacy:metadata:digestforum_posts:discussion',
            'parent' => 'privacy:metadata:digestforum_posts:parent',
            'created' => 'privacy:metadata:digestforum_posts:created',
            'modified' => 'privacy:metadata:digestforum_posts:modified',
            'subject' => 'privacy:metadata:digestforum_posts:subject',
            'message' => 'privacy:metadata:digestforum_posts:message',
            'userid' => 'privacy:metadata:digestforum_posts:userid',
        ], 'privacy:metadata:digestforum_posts');

        // The 'digestforum_queue' table contains user data, but it is only a temporary cache of other data.
        // We should not need to export it as it does not allow profiling of a user.

        // The 'digestforum_read' table stores data about which digestforum posts have been read by each user.
        $items->add_database_table('digestforum_read', [
            'userid' => 'privacy:metadata:digestforum_read:userid',
            'discussionid' => 'privacy:metadata:digestforum_read:discussionid',
            'postid' => 'privacy:metadata:digestforum_read:postid',
            'firstread' => 'privacy:metadata:digestforum_read:firstread',
            'lastread' => 'privacy:metadata:digestforum_read:lastread',
        ], 'privacy:metadata:digestforum_read');

        // The 'digestforum_subscriptions' table stores information about which digestforums a user is subscribed to.
        $items->add_database_table('digestforum_subscriptions', [
            'userid' => 'privacy:metadata:digestforum_subscriptions:userid',
            'digestforum' => 'privacy:metadata:digestforum_subscriptions:digestforum',
        ], 'privacy:metadata:digestforum_subscriptions');

        // The 'digestforum_subscriptions' table stores information about which digestforums a user is subscribed to.
        $items->add_database_table('digestforum_track_prefs', [
            'userid' => 'privacy:metadata:digestforum_track_prefs:userid',
            'digestforumid' => 'privacy:metadata:digestforum_track_prefs:digestforumid',
        ], 'privacy:metadata:digestforum_track_prefs');

        // The 'digestforum_queue' table stores temporary data that is not exported/deleted.
        $items->add_database_table('digestforum_queue', [
            'userid' => 'privacy:metadata:digestforum_queue:userid',
            'discussionid' => 'privacy:metadata:digestforum_queue:discussionid',
            'postid' => 'privacy:metadata:digestforum_queue:postid',
            'timemodified' => 'privacy:metadata:digestforum_queue:timemodified'
        ], 'privacy:metadata:digestforum_queue');

        // Forum posts can be tagged and rated.
        $items->link_subsystem('core_tag', 'privacy:metadata:core_tag');
        $items->link_subsystem('core_rating', 'privacy:metadata:core_rating');

        // There are several user preferences.
        $items->add_user_preference('maildigest', 'privacy:metadata:preference:maildigest');
        $items->add_user_preference('autosubscribe', 'privacy:metadata:preference:autosubscribe');
        $items->add_user_preference('trackforums', 'privacy:metadata:preference:trackforums');
        $items->add_user_preference('markasreadonnotification', 'privacy:metadata:preference:markasreadonnotification');

        return $items;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * In the case of digestforum, that is any digestforum where the user has made any post, rated any content, or has any preferences.
     *
     * @param   int         $userid     The user to search.
     * @return  contextlist $contextlist  The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid) : \core_privacy\local\request\contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();

        $params = [
            'modname'       => 'digestforum',
            'contextlevel'  => CONTEXT_MODULE,
            'userid'        => $userid,
        ];

        // Discussion creators.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                 WHERE d.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Post authors.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                  JOIN {digestforum_posts} p ON p.discussion = d.id
                 WHERE p.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Forum digest records.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_digests} dig ON dig.digestforum = f.id
                 WHERE dig.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Forum subscriptions.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_subscriptions} sub ON sub.digestforum = f.id
                 WHERE sub.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Discussion subscriptions.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussion_subs} dsub ON dsub.digestforum = f.id
                 WHERE dsub.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Discussion tracking preferences.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_track_prefs} pref ON pref.digestforumid = f.id
                 WHERE pref.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Discussion read records.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_read} hasread ON hasread.digestforumid = f.id
                 WHERE hasread.userid = :userid
        ";
        $contextlist->add_from_sql($sql, $params);

        // Rating authors.
        $ratingsql = \core_rating\privacy\provider::get_sql_join('rat', 'mod_digestforum', 'post', 'p.id', $userid, true);
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                  JOIN {digestforum_posts} p ON p.discussion = d.id
                  {$ratingsql->join}
                 WHERE {$ratingsql->userwhere}
        ";
        $params += $ratingsql->params;
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!is_a($context, \context_module::class)) {
            return;
        }

        $params = [
            'instanceid'    => $context->instanceid,
            'modulename'    => 'digestforum',
        ];

        // Discussion authors.
        $sql = "SELECT d.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Forum authors.
        $sql = "SELECT p.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                  JOIN {digestforum_posts} p ON d.id = p.discussion
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Forum post ratings.
        $sql = "SELECT p.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                  JOIN {digestforum_posts} p ON d.id = p.discussion
                 WHERE cm.id = :instanceid";
        \core_rating\privacy\provider::get_users_in_context_from_sql($userlist, 'rat', 'mod_digestforum', 'post', $sql, $params);

        // Forum Digest settings.
        $sql = "SELECT dig.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_digests} dig ON dig.digestforum = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Forum Subscriptions.
        $sql = "SELECT sub.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_subscriptions} sub ON sub.digestforum = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Discussion subscriptions.
        $sql = "SELECT dsub.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_discussion_subs} dsub ON dsub.digestforum = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Read Posts.
        $sql = "SELECT hasread.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_read} hasread ON hasread.digestforumid = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Tracking Preferences.
        $sql = "SELECT pref.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_track_prefs} pref ON pref.digestforumid = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Store all user preferences for the plugin.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     */
    public static function export_user_preferences(int $userid) {
        $user = \core_user::get_user($userid);

        switch ($user->maildigest) {
            case 1:
                $digestdescription = get_string('emaildigestcomplete');
                break;
            case 2:
                $digestdescription = get_string('emaildigestsubjects');
                break;
            case 0:
            default:
                $digestdescription = get_string('emaildigestoff');
                break;
        }
        writer::export_user_preference('mod_digestforum', 'maildigest', $user->maildigest, $digestdescription);

        switch ($user->autosubscribe) {
            case 0:
                $subscribedescription = get_string('autosubscribeno');
                break;
            case 1:
            default:
                $subscribedescription = get_string('autosubscribeyes');
                break;
        }
        writer::export_user_preference('mod_digestforum', 'autosubscribe', $user->autosubscribe, $subscribedescription);

        switch ($user->trackforums) {
            case 0:
                $trackdigestforumdescription = get_string('trackforumsno');
                break;
            case 1:
            default:
                $trackdigestforumdescription = get_string('trackforumsyes');
                break;
        }
        writer::export_user_preference('mod_digestforum', 'trackforums', $user->trackforums, $trackdigestforumdescription);

        $markasreadonnotification = get_user_preferences('markasreadonnotification', null, $user->id);
        if (null !== $markasreadonnotification) {
            switch ($markasreadonnotification) {
                case 0:
                    $markasreadonnotificationdescription = get_string('markasreadonnotificationno', 'mod_digestforum');
                    break;
                case 1:
                default:
                    $markasreadonnotificationdescription = get_string('markasreadonnotificationyes', 'mod_digestforum');
                    break;
            }
            writer::export_user_preference('mod_digestforum', 'markasreadonnotification', $markasreadonnotification,
                    $markasreadonnotificationdescription);
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist)) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);
        $params = $contextparams;

        // Digested digestforums.
        $sql = "SELECT
                    c.id AS contextid,
                    dig.maildigest AS maildigest
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_digests} dig ON dig.digestforum = f.id
                 WHERE (
                    dig.userid = :userid AND
                    c.id {$contextsql}
                )
        ";
        $params['userid'] = $userid;
        $digests = $DB->get_records_sql_menu($sql, $params);

        // Forum subscriptions.
        $sql = "SELECT
                    c.id AS contextid,
                    sub.userid AS subscribed
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_subscriptions} sub ON sub.digestforum = f.id
                 WHERE (
                    sub.userid = :userid AND
                    c.id {$contextsql}
                )
        ";
        $params['userid'] = $userid;
        $subscriptions = $DB->get_records_sql_menu($sql, $params);

        // Tracked digestforums.
        $sql = "SELECT
                    c.id AS contextid,
                    pref.userid AS tracked
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid
                  JOIN {digestforum} f ON f.id = cm.instance
                  JOIN {digestforum_track_prefs} pref ON pref.digestforumid = f.id
                 WHERE (
                    pref.userid = :userid AND
                    c.id {$contextsql}
                )
        ";
        $params['userid'] = $userid;
        $tracked = $DB->get_records_sql_menu($sql, $params);

        $sql = "SELECT
                    c.id AS contextid,
                    f.*,
                    cm.id AS cmid
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid
                  JOIN {digestforum} f ON f.id = cm.instance
                 WHERE (
                    c.id {$contextsql}
                )
        ";

        $params += $contextparams;

        // Keep a mapping of digestforumid to contextid.
        $mappings = [];

        $digestforums = $DB->get_recordset_sql($sql, $params);
        foreach ($digestforums as $digestforum) {
            $mappings[$digestforum->id] = $digestforum->contextid;

            $context = \context::instance_by_id($mappings[$digestforum->id]);

            // Store the main digestforum data.
            $data = request_helper::get_context_data($context, $user);
            writer::with_context($context)
                ->export_data([], $data);
            request_helper::export_context_files($context, $user);

            // Store relevant metadata about this digestforum instance.
            if (isset($digests[$digestforum->contextid])) {
                static::export_digest_data($userid, $digestforum, $digests[$digestforum->contextid]);
            }
            if (isset($subscriptions[$digestforum->contextid])) {
                static::export_subscription_data($userid, $digestforum, $subscriptions[$digestforum->contextid]);
            }
            if (isset($tracked[$digestforum->contextid])) {
                static::export_tracking_data($userid, $digestforum, $tracked[$digestforum->contextid]);
            }
        }
        $digestforums->close();

        if (!empty($mappings)) {
            // Store all discussion data for this digestforum.
            static::export_discussion_data($userid, $mappings);

            // Store all post data for this digestforum.
            static::export_all_posts($userid, $mappings);
        }
    }

    /**
     * Store all information about all discussions that we have detected this user to have access to.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   array       $mappings A list of mappings from digestforumid => contextid.
     * @return  array       Which digestforums had data written for them.
     */
    protected static function export_discussion_data(int $userid, array $mappings) {
        global $DB;

        // Find all of the discussions, and discussion subscriptions for this digestforum.
        list($digestforuminsql, $digestforumparams) = $DB->get_in_or_equal(array_keys($mappings), SQL_PARAMS_NAMED);
        $sql = "SELECT
                    d.*,
                    g.name as groupname,
                    dsub.preference
                  FROM {digestforum} f
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
             LEFT JOIN {groups} g ON g.id = d.groupid
             LEFT JOIN {digestforum_discussion_subs} dsub ON dsub.discussion = d.id AND dsub.userid = :dsubuserid
             LEFT JOIN {digestforum_posts} p ON p.discussion = d.id
                 WHERE f.id ${digestforuminsql}
                   AND (
                        d.userid    = :discussionuserid OR
                        p.userid    = :postuserid OR
                        dsub.id IS NOT NULL
                   )
        ";

        $params = [
            'postuserid'        => $userid,
            'discussionuserid'  => $userid,
            'dsubuserid'        => $userid,
        ];
        $params += $digestforumparams;

        // Keep track of the digestforums which have data.
        $digestforumswithdata = [];

        $discussions = $DB->get_recordset_sql($sql, $params);
        foreach ($discussions as $discussion) {
            // No need to take timestart into account as the user has some involvement already.
            // Ignore discussion timeend as it should not block access to user data.
            $digestforumswithdata[$discussion->digestforum] = true;
            $context = \context::instance_by_id($mappings[$discussion->digestforum]);

            // Store related metadata for this discussion.
            static::export_discussion_subscription_data($userid, $context, $discussion);

            $discussiondata = (object) [
                'name' => format_string($discussion->name, true),
                'pinned' => transform::yesno((bool) $discussion->pinned),
                'timemodified' => transform::datetime($discussion->timemodified),
                'usermodified' => transform::datetime($discussion->usermodified),
                'creator_was_you' => transform::yesno($discussion->userid == $userid),
            ];

            // Store the discussion content.
            writer::with_context($context)
                ->export_data(static::get_discussion_area($discussion), $discussiondata);

            // Forum discussions do not have any files associately directly with them.
        }

        $discussions->close();

        return $digestforumswithdata;
    }

    /**
     * Store all information about all posts that we have detected this user to have access to.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   array       $mappings A list of mappings from digestforumid => contextid.
     * @return  array       Which digestforums had data written for them.
     */
    protected static function export_all_posts(int $userid, array $mappings) {
        global $DB;

        // Find all of the posts, and post subscriptions for this digestforum.
        list($digestforuminsql, $digestforumparams) = $DB->get_in_or_equal(array_keys($mappings), SQL_PARAMS_NAMED);
        $ratingsql = \core_rating\privacy\provider::get_sql_join('rat', 'mod_digestforum', 'post', 'p.id', $userid);
        $sql = "SELECT
                    p.discussion AS id,
                    f.id AS digestforumid,
                    d.name,
                    d.groupid
                  FROM {digestforum} f
                  JOIN {digestforum_discussions} d ON d.digestforum = f.id
                  JOIN {digestforum_posts} p ON p.discussion = d.id
             LEFT JOIN {digestforum_read} fr ON fr.postid = p.id AND fr.userid = :readuserid
            {$ratingsql->join}
                 WHERE f.id ${digestforuminsql} AND
                (
                    p.userid = :postuserid OR
                    fr.id IS NOT NULL OR
                    {$ratingsql->userwhere}
                )
              GROUP BY f.id, p.discussion, d.name, d.groupid
        ";

        $params = [
            'postuserid'    => $userid,
            'readuserid'    => $userid,
        ];
        $params += $digestforumparams;
        $params += $ratingsql->params;

        $discussions = $DB->get_records_sql($sql, $params);
        foreach ($discussions as $discussion) {
            $context = \context::instance_by_id($mappings[$discussion->digestforumid]);
            static::export_all_posts_in_discussion($userid, $context, $discussion);
        }
    }

    /**
     * Store all information about all posts that we have detected this user to have access to.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \context    $context The instance of the digestforum context.
     * @param   \stdClass   $discussion The discussion whose data is being exported.
     */
    protected static function export_all_posts_in_discussion(int $userid, \context $context, \stdClass $discussion) {
        global $DB, $USER;

        $discussionid = $discussion->id;

        // Find all of the posts, and post subscriptions for this digestforum.
        $ratingsql = \core_rating\privacy\provider::get_sql_join('rat', 'mod_digestforum', 'post', 'p.id', $userid);
        $sql = "SELECT
                    p.*,
                    d.digestforum AS digestforumid,
                    fr.firstread,
                    fr.lastread,
                    fr.id AS readflag,
                    rat.id AS hasratings
                    FROM {digestforum_discussions} d
                    JOIN {digestforum_posts} p ON p.discussion = d.id
               LEFT JOIN {digestforum_read} fr ON fr.postid = p.id AND fr.userid = :readuserid
            {$ratingsql->join} AND {$ratingsql->userwhere}
                   WHERE d.id = :discussionid
        ";

        $params = [
            'discussionid'  => $discussionid,
            'readuserid'    => $userid,
        ];
        $params += $ratingsql->params;

        // Keep track of the digestforums which have data.
        $structure = (object) [
            'children' => [],
        ];

        $posts = $DB->get_records_sql($sql, $params);
        foreach ($posts as $post) {
            $post->hasdata = (isset($post->hasdata)) ? $post->hasdata : false;
            $post->hasdata = $post->hasdata || !empty($post->hasratings);
            $post->hasdata = $post->hasdata || $post->readflag;
            $post->hasdata = $post->hasdata || ($post->userid == $USER->id);

            if (0 == $post->parent) {
                $structure->children[$post->id] = $post;
            } else {
                if (empty($posts[$post->parent]->children)) {
                    $posts[$post->parent]->children = [];
                }
                $posts[$post->parent]->children[$post->id] = $post;
            }

            // Set all parents.
            if ($post->hasdata) {
                $curpost = $post;
                while ($curpost->parent != 0) {
                    $curpost = $posts[$curpost->parent];
                    $curpost->hasdata = true;
                }
            }
        }

        $discussionarea = static::get_discussion_area($discussion);
        $discussionarea[] = get_string('posts', 'mod_digestforum');
        static::export_posts_in_structure($userid, $context, $discussionarea, $structure);
    }

    /**
     * Export all posts in the provided structure.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \context    $context The instance of the digestforum context.
     * @param   array       $parentarea The subcontext of the parent.
     * @param   \stdClass   $structure The post structure and all of its children
     */
    protected static function export_posts_in_structure(int $userid, \context $context, $parentarea, \stdClass $structure) {
        foreach ($structure->children as $post) {
            if (!$post->hasdata) {
                // This tree has no content belonging to the user. Skip it and all children.
                continue;
            }

            $postarea = array_merge($parentarea, static::get_post_area($post));

            // Store the post content.
            static::export_post_data($userid, $context, $postarea, $post);

            if (isset($post->children)) {
                // Now export children of this post.
                static::export_posts_in_structure($userid, $context, $postarea, $post);
            }
        }
    }

    /**
     * Export all data in the post.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \context    $context The instance of the digestforum context.
     * @param   array       $postarea The subcontext of the parent.
     * @param   \stdClass   $post The post structure and all of its children
     */
    protected static function export_post_data(int $userid, \context $context, $postarea, $post) {
        // Store related metadata.
        static::export_read_data($userid, $context, $postarea, $post);

        $postdata = (object) [
            'subject' => format_string($post->subject, true),
            'created' => transform::datetime($post->created),
            'modified' => transform::datetime($post->modified),
            'author_was_you' => transform::yesno($post->userid == $userid),
        ];

        $postdata->message = writer::with_context($context)
            ->rewrite_pluginfile_urls($postarea, 'mod_digestforum', 'post', $post->id, $post->message);

        $postdata->message = format_text($postdata->message, $post->messageformat, (object) [
            'para'    => false,
            'trusted' => $post->messagetrust,
            'context' => $context,
        ]);

        writer::with_context($context)
            // Store the post.
            ->export_data($postarea, $postdata)

            // Store the associated files.
            ->export_area_files($postarea, 'mod_digestforum', 'post', $post->id);

        if ($post->userid == $userid) {
            // Store all ratings against this post as the post belongs to the user. All ratings on it are ratings of their content.
            \core_rating\privacy\provider::export_area_ratings($userid, $context, $postarea, 'mod_digestforum', 'post', $post->id, false);

            // Store all tags against this post as the tag belongs to the user.
            \core_tag\privacy\provider::export_item_tags($userid, $context, $postarea, 'mod_digestforum', 'digestforum_posts', $post->id);

            // Export all user data stored for this post from the plagiarism API.
            $coursecontext = $context->get_course_context();
            \core_plagiarism\privacy\provider::export_plagiarism_user_data($userid, $context, $postarea, [
                    'cmid' => $context->instanceid,
                    'course' => $coursecontext->instanceid,
                    'digestforum' => $post->digestforumid,
                    'discussionid' => $post->discussion,
                    'postid' => $post->id,
                ]);
        }

        // Check for any ratings that the user has made on this post.
        \core_rating\privacy\provider::export_area_ratings($userid,
                $context,
                $postarea,
                'mod_digestforum',
                'post',
                $post->id,
                $userid,
                true
            );
    }

    /**
     * Store data about daily digest preferences
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \stdClass   $digestforum The digestforum whose data is being exported.
     * @param   int         $maildigest The mail digest setting for this digestforum.
     * @return  bool        Whether any data was stored.
     */
    protected static function export_digest_data(int $userid, \stdClass $digestforum, int $maildigest) {
        if (null !== $maildigest) {
            // The user has a specific maildigest preference for this digestforum.
            $a = (object) [
                'digestforum' => format_string($digestforum->name, true),
            ];

            switch ($maildigest) {
                case 0:
                    $a->type = get_string('emaildigestoffshort', 'mod_digestforum');
                    break;
                case 1:
                    $a->type = get_string('emaildigestcompleteshort', 'mod_digestforum');
                    break;
                case 2:
                    $a->type = get_string('emaildigestsubjectsshort', 'mod_digestforum');
                    break;
            }

            writer::with_context(\context_module::instance($digestforum->cmid))
                ->export_metadata([], 'digestpreference', $maildigest,
                    get_string('privacy:digesttypepreference', 'mod_digestforum', $a));

            return true;
        }

        return false;
    }

    /**
     * Store data about whether the user subscribes to digestforum.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \stdClass   $digestforum The digestforum whose data is being exported.
     * @param   int         $subscribed if the user is subscribed
     * @return  bool        Whether any data was stored.
     */
    protected static function export_subscription_data(int $userid, \stdClass $digestforum, int $subscribed) {
        if (null !== $subscribed) {
            // The user is subscribed to this digestforum.
            writer::with_context(\context_module::instance($digestforum->cmid))
                ->export_metadata([], 'subscriptionpreference', 1, get_string('privacy:subscribedtodigestforum', 'mod_digestforum'));

            return true;
        }

        return false;
    }

    /**
     * Store data about whether the user subscribes to this particular discussion.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \context_module $context The instance of the digestforum context.
     * @param   \stdClass   $discussion The discussion whose data is being exported.
     * @return  bool        Whether any data was stored.
     */
    protected static function export_discussion_subscription_data(int $userid, \context_module $context, \stdClass $discussion) {
        $area = static::get_discussion_area($discussion);
        if (null !== $discussion->preference) {
            // The user has a specific subscription preference for this discussion.
            $a = (object) [];

            switch ($discussion->preference) {
                case \mod_digestforum\subscriptions::DFORUM_DISCUSSION_UNSUBSCRIBED:
                    $a->preference = get_string('unsubscribed', 'mod_digestforum');
                    break;
                default:
                    $a->preference = get_string('subscribed', 'mod_digestforum');
                    break;
            }

            writer::with_context($context)
                ->export_metadata(
                    $area,
                    'subscriptionpreference',
                    $discussion->preference,
                    get_string('privacy:discussionsubscriptionpreference', 'mod_digestforum', $a)
                );

            return true;
        }

        return true;
    }

    /**
     * Store digestforum read-tracking data about a particular digestforum.
     *
     * This is whether a digestforum has read-tracking enabled or not.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \stdClass   $digestforum The digestforum whose data is being exported.
     * @param   int         $tracke if the user is subscribed
     * @return  bool        Whether any data was stored.
     */
    protected static function export_tracking_data(int $userid, \stdClass $digestforum, int $tracked) {
        if (null !== $tracked) {
            // The user has a main preference to track all digestforums, but has opted out of this one.
            writer::with_context(\context_module::instance($digestforum->cmid))
                ->export_metadata([], 'trackreadpreference', 0, get_string('privacy:readtrackingdisabled', 'mod_digestforum'));

            return true;
        }

        return false;
    }

    /**
     * Store read-tracking information about a particular digestforum post.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     * @param   \context_module $context The instance of the digestforum context.
     * @param   array       $postarea The subcontext for this post.
     * @param   \stdClass   $post The post whose data is being exported.
     * @return  bool        Whether any data was stored.
     */
    protected static function export_read_data(int $userid, \context_module $context, array $postarea, \stdClass $post) {
        if (null !== $post->firstread) {
            $a = (object) [
                'firstread' => $post->firstread,
                'lastread'  => $post->lastread,
            ];

            writer::with_context($context)
                ->export_metadata(
                    $postarea,
                    'postread',
                    (object) [
                        'firstread' => $post->firstread,
                        'lastread' => $post->lastread,
                    ],
                    get_string('privacy:postwasread', 'mod_digestforum', $a)
                );

            return true;
        }

        return false;
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param   context                 $context   The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        // Check that this is a context_module.
        if (!$context instanceof \context_module) {
            return;
        }

        // Get the course module.
        if (!$cm = get_coursemodule_from_id('digestforum', $context->instanceid)) {
            return;
        }

        $digestforumid = $cm->instance;

        $DB->delete_records('digestforum_track_prefs', ['digestforumid' => $digestforumid]);
        $DB->delete_records('digestforum_subscriptions', ['digestforum' => $digestforumid]);
        $DB->delete_records('digestforum_read', ['digestforumid' => $digestforumid]);
        $DB->delete_records('digestforum_digests', ['digestforum' => $digestforumid]);

        // Delete all discussion items.
        $DB->delete_records_select(
            'digestforum_queue',
            "discussionid IN (SELECT id FROM {digestforum_discussions} WHERE digestforum = :digestforum)",
            [
                'digestforum' => $digestforumid,
            ]
        );

        $DB->delete_records_select(
            'digestforum_posts',
            "discussion IN (SELECT id FROM {digestforum_discussions} WHERE digestforum = :digestforum)",
            [
                'digestforum' => $digestforumid,
            ]
        );

        $DB->delete_records('digestforum_discussion_subs', ['digestforum' => $digestforumid]);
        $DB->delete_records('digestforum_discussions', ['digestforum' => $digestforumid]);

        // Delete all files from the posts.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_digestforum', 'post');
        $fs->delete_area_files($context->id, 'mod_digestforum', 'attachment');

        // Delete all ratings in the context.
        \core_rating\privacy\provider::delete_ratings($context, 'mod_digestforum', 'post');

        // Delete all Tags.
        \core_tag\privacy\provider::delete_item_tags($context, 'mod_digestforum', 'digestforum_posts');
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        $userid = $user->id;
        foreach ($contextlist as $context) {
            // Get the course module.
            $cm = $DB->get_record('course_modules', ['id' => $context->instanceid]);
            $digestforum = $DB->get_record('digestforum', ['id' => $cm->instance]);

            $DB->delete_records('digestforum_track_prefs', [
                'digestforumid' => $digestforum->id,
                'userid' => $userid,
            ]);
            $DB->delete_records('digestforum_subscriptions', [
                'digestforum' => $digestforum->id,
                'userid' => $userid,
            ]);
            $DB->delete_records('digestforum_read', [
                'digestforumid' => $digestforum->id,
                'userid' => $userid,
            ]);

            $DB->delete_records('digestforum_digests', [
                'digestforum' => $digestforum->id,
                'userid' => $userid,
            ]);

            // Delete all discussion items.
            $DB->delete_records_select(
                'digestforum_queue',
                "userid = :userid AND discussionid IN (SELECT id FROM {digestforum_discussions} WHERE digestforum = :digestforum)",
                [
                    'userid' => $userid,
                    'digestforum' => $digestforum->id,
                ]
            );

            $DB->delete_records('digestforum_discussion_subs', [
                'digestforum' => $digestforum->id,
                'userid' => $userid,
            ]);

            // Do not delete discussion or digestforum posts.
            // Instead update them to reflect that the content has been deleted.
            $postsql = "userid = :userid AND discussion IN (SELECT id FROM {digestforum_discussions} WHERE digestforum = :digestforum)";
            $postidsql = "SELECT fp.id FROM {digestforum_posts} fp WHERE {$postsql}";
            $postparams = [
                'digestforum' => $digestforum->id,
                'userid' => $userid,
            ];

            // Update the subject.
            $DB->set_field_select('digestforum_posts', 'subject', '', $postsql, $postparams);

            // Update the message and its format.
            $DB->set_field_select('digestforum_posts', 'message', '', $postsql, $postparams);
            $DB->set_field_select('digestforum_posts', 'messageformat', FORMAT_PLAIN, $postsql, $postparams);

            // Mark the post as deleted.
            $DB->set_field_select('digestforum_posts', 'deleted', 1, $postsql, $postparams);

            // Note: Do _not_ delete ratings of other users. Only delete ratings on the users own posts.
            // Ratings are aggregate fields and deleting the rating of this post will have an effect on the rating
            // of any post.
            \core_rating\privacy\provider::delete_ratings_select($context, 'mod_digestforum', 'post',
                    "IN ($postidsql)", $postparams);

            // Delete all Tags.
            \core_tag\privacy\provider::delete_item_tags_select($context, 'mod_digestforum', 'digestforum_posts',
                    "IN ($postidsql)", $postparams);

            // Delete all files from the posts.
            $fs = get_file_storage();
            $fs->delete_area_files_select($context->id, 'mod_digestforum', 'post', "IN ($postidsql)", $postparams);
            $fs->delete_area_files_select($context->id, 'mod_digestforum', 'attachment', "IN ($postidsql)", $postparams);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param   approved_userlist       $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $cm = $DB->get_record('course_modules', ['id' => $context->instanceid]);
        $digestforum = $DB->get_record('digestforum', ['id' => $cm->instance]);

        list($userinsql, $userinparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params = array_merge(['digestforumid' => $digestforum->id], $userinparams);

        $DB->delete_records_select('digestforum_track_prefs', "digestforumid = :digestforumid AND userid {$userinsql}", $params);
        $DB->delete_records_select('digestforum_subscriptions', "digestforum = :digestforumid AND userid {$userinsql}", $params);
        $DB->delete_records_select('digestforum_read', "digestforumid = :digestforumid AND userid {$userinsql}", $params);
        $DB->delete_records_select(
            'digestforum_queue',
            "userid {$userinsql} AND discussionid IN (SELECT id FROM {digestforum_discussions} WHERE digestforum = :digestforumid)",
            $params
        );
        $DB->delete_records_select('digestforum_discussion_subs', "digestforum = :digestforumid AND userid {$userinsql}", $params);

        // Do not delete discussion or digestforum posts.
        // Instead update them to reflect that the content has been deleted.
        $postsql = "userid {$userinsql} AND discussion IN (SELECT id FROM {digestforum_discussions} WHERE digestforum = :digestforumid)";
        $postidsql = "SELECT fp.id FROM {digestforum_posts} fp WHERE {$postsql}";

        // Update the subject.
        $DB->set_field_select('digestforum_posts', 'subject', '', $postsql, $params);

        // Update the subject and its format.
        $DB->set_field_select('digestforum_posts', 'message', '', $postsql, $params);
        $DB->set_field_select('digestforum_posts', 'messageformat', FORMAT_PLAIN, $postsql, $params);

        // Mark the post as deleted.
        $DB->set_field_select('digestforum_posts', 'deleted', 1, $postsql, $params);

        // Note: Do _not_ delete ratings of other users. Only delete ratings on the users own posts.
        // Ratings are aggregate fields and deleting the rating of this post will have an effect on the rating
        // of any post.
        \core_rating\privacy\provider::delete_ratings_select($context, 'mod_digestforum', 'post', "IN ($postidsql)", $params);

        // Delete all Tags.
        \core_tag\privacy\provider::delete_item_tags_select($context, 'mod_digestforum', 'digestforum_posts', "IN ($postidsql)", $params);

        // Delete all files from the posts.
        $fs = get_file_storage();
        $fs->delete_area_files_select($context->id, 'mod_digestforum', 'post', "IN ($postidsql)", $params);
        $fs->delete_area_files_select($context->id, 'mod_digestforum', 'attachment', "IN ($postidsql)", $params);
    }
}
