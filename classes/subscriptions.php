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
 * Forum subscription manager.
 *
 * @package    mod_digestforum
 * @copyright  2014 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum;

defined('MOODLE_INTERNAL') || die();

/**
 * Forum subscription manager.
 *
 * @copyright  2014 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscriptions {

    /**
     * The status value for an unsubscribed discussion.
     *
     * @var int
     */
    const DFORUM_DISCUSSION_UNSUBSCRIBED = -1;

    /**
     * The subscription cache for digestforums.
     *
     * The first level key is the user ID
     * The second level is the digestforum ID
     * The Value then is bool for subscribed of not.
     *
     * @var array[] An array of arrays.
     */
    protected static $digestforumcache = array();

    /**
     * The list of digestforums which have been wholly retrieved for the digestforum subscription cache.
     *
     * This allows for prior caching of an entire digestforum to reduce the
     * number of DB queries in a subscription check loop.
     *
     * @var bool[]
     */
    protected static $fetcheddigestforums = array();

    /**
     * The subscription cache for digestforum discussions.
     *
     * The first level key is the user ID
     * The second level is the digestforum ID
     * The third level key is the discussion ID
     * The value is then the users preference (int)
     *
     * @var array[]
     */
    protected static $digestforumdiscussioncache = array();

    /**
     * The list of digestforums which have been wholly retrieved for the digestforum discussion subscription cache.
     *
     * This allows for prior caching of an entire digestforum to reduce the
     * number of DB queries in a subscription check loop.
     *
     * @var bool[]
     */
    protected static $discussionfetcheddigestforums = array();

    /**
     * Whether a user is subscribed to this digestforum, or a discussion within
     * the digestforum.
     *
     * If a discussion is specified, then report whether the user is
     * subscribed to posts to this particular discussion, taking into
     * account the digestforum preference.
     *
     * If it is not specified then only the digestforum preference is considered.
     *
     * @param int $userid The user ID
     * @param \stdClass $digestforum The record of the digestforum to test
     * @param int $discussionid The ID of the discussion to check
     * @param $cm The coursemodule record. If not supplied, this will be calculated using get_fast_modinfo instead.
     * @return boolean
     */
    public static function is_subscribed($userid, $digestforum, $discussionid = null, $cm = null) {
        // If digestforum is force subscribed and has allowforcesubscribe, then user is subscribed.
        if (self::is_forcesubscribed($digestforum)) {
            if (!$cm) {
                $cm = get_fast_modinfo($digestforum->course)->instances['digestforum'][$digestforum->id];
            }
            if (has_capability('mod/digestforum:allowforcesubscribe', \context_module::instance($cm->id), $userid)) {
                return true;
            }
        }

        if ($discussionid === null) {
            return self::is_subscribed_to_digestforum($userid, $digestforum);
        }

        $subscriptions = self::fetch_discussion_subscription($digestforum->id, $userid);

        // Check whether there is a record for this discussion subscription.
        if (isset($subscriptions[$discussionid])) {
            return ($subscriptions[$discussionid] != self::DFORUM_DISCUSSION_UNSUBSCRIBED);
        }

        return self::is_subscribed_to_digestforum($userid, $digestforum);
    }

    /**
     * Whether a user is subscribed to this digestforum.
     *
     * @param int $userid The user ID
     * @param \stdClass $digestforum The record of the digestforum to test
     * @return boolean
     */
    protected static function is_subscribed_to_digestforum($userid, $digestforum) {
        return self::fetch_subscription_cache($digestforum->id, $userid);
    }

    /**
     * Helper to determine whether a digestforum has it's subscription mode set
     * to forced subscription.
     *
     * @param \stdClass $digestforum The record of the digestforum to test
     * @return bool
     */
    public static function is_forcesubscribed($digestforum) {
        return ($digestforum->forcesubscribe == DFORUM_FORCESUBSCRIBE);
    }

    /**
     * Helper to determine whether a digestforum has it's subscription mode set to disabled.
     *
     * @param \stdClass $digestforum The record of the digestforum to test
     * @return bool
     */
    public static function subscription_disabled($digestforum) {
        return ($digestforum->forcesubscribe == DFORUM_DISALLOWSUBSCRIBE);
    }

    /**
     * Helper to determine whether the specified digestforum can be subscribed to.
     *
     * @param \stdClass $digestforum The record of the digestforum to test
     * @return bool
     */
    public static function is_subscribable($digestforum) {
        return (!\mod_digestforum\subscriptions::is_forcesubscribed($digestforum) &&
                !\mod_digestforum\subscriptions::subscription_disabled($digestforum));
    }

    /**
     * Set the digestforum subscription mode.
     *
     * By default when called without options, this is set to DFORUM_FORCESUBSCRIBE.
     *
     * @param \stdClass $digestforum The record of the digestforum to set
     * @param int $status The new subscription state
     * @return bool
     */
    public static function set_subscription_mode($digestforumid, $status = 1) {
        global $DB;
        return $DB->set_field("digestforum", "forcesubscribe", $status, array("id" => $digestforumid));
    }

    /**
     * Returns the current subscription mode for the digestforum.
     *
     * @param \stdClass $digestforum The record of the digestforum to set
     * @return int The digestforum subscription mode
     */
    public static function get_subscription_mode($digestforum) {
        return $digestforum->forcesubscribe;
    }

    /**
     * Returns an array of digestforums that the current user is subscribed to and is allowed to unsubscribe from
     *
     * @return array An array of unsubscribable digestforums
     */
    public static function get_unsubscribable_digestforums() {
        global $USER, $DB;

        // Get courses that $USER is enrolled in and can see.
        $courses = enrol_get_my_courses();
        if (empty($courses)) {
            return array();
        }

        $courseids = array();
        foreach($courses as $course) {
            $courseids[] = $course->id;
        }
        list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

        // Get all digestforums from the user's courses that they are subscribed to and which are not set to forced.
        // It is possible for users to be subscribed to a digestforum in subscription disallowed mode so they must be listed
        // here so that that can be unsubscribed from.
        $sql = "SELECT f.id, cm.id as cm, cm.visible, f.course
                FROM {digestforum} f
                JOIN {course_modules} cm ON cm.instance = f.id
                JOIN {modules} m ON m.name = :modulename AND m.id = cm.module
                LEFT JOIN {digestforum_subscriptions} fs ON (fs.digestforum = f.id AND fs.userid = :userid)
                WHERE f.forcesubscribe <> :forcesubscribe
                AND fs.id IS NOT NULL
                AND cm.course
                $coursesql";
        $params = array_merge($courseparams, array(
            'modulename'=>'digestforum',
            'userid' => $USER->id,
            'forcesubscribe' => DFORUM_FORCESUBSCRIBE,
        ));
        $digestforums = $DB->get_recordset_sql($sql, $params);

        $unsubscribabledigestforums = array();
        foreach($digestforums as $digestforum) {
            if (empty($digestforum->visible)) {
                // The digestforum is hidden - check if the user can view the digestforum.
                $context = \context_module::instance($digestforum->cm);
                if (!has_capability('moodle/course:viewhiddenactivities', $context)) {
                    // The user can't see the hidden digestforum to cannot unsubscribe.
                    continue;
                }
            }

            $unsubscribabledigestforums[] = $digestforum;
        }
        $digestforums->close();

        return $unsubscribabledigestforums;
    }

    /**
     * Get the list of potential subscribers to a digestforum.
     *
     * @param context_module $context the digestforum context.
     * @param integer $groupid the id of a group, or 0 for all groups.
     * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
     * @param string $sort sort order. As for get_users_by_capability.
     * @return array list of users.
     */
    public static function get_potential_subscribers($context, $groupid, $fields, $sort = '') {
        global $DB;

        // Only active enrolled users or everybody on the frontpage.
        list($esql, $params) = get_enrolled_sql($context, 'mod/digestforum:allowforcesubscribe', $groupid, true);
        if (!$sort) {
            list($sort, $sortparams) = users_order_by_sql('u');
            $params = array_merge($params, $sortparams);
        }

        $sql = "SELECT $fields
                FROM {user} u
                JOIN ($esql) je ON je.id = u.id
            ORDER BY $sort";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Fetch the digestforum subscription data for the specified userid and digestforum.
     *
     * @param int $digestforumid The digestforum to retrieve a cache for
     * @param int $userid The user ID
     * @return boolean
     */
    public static function fetch_subscription_cache($digestforumid, $userid) {
        if (isset(self::$digestforumcache[$userid]) && isset(self::$digestforumcache[$userid][$digestforumid])) {
            return self::$digestforumcache[$userid][$digestforumid];
        }
        self::fill_subscription_cache($digestforumid, $userid);

        if (!isset(self::$digestforumcache[$userid]) || !isset(self::$digestforumcache[$userid][$digestforumid])) {
            return false;
        }

        return self::$digestforumcache[$userid][$digestforumid];
    }

    /**
     * Fill the digestforum subscription data for the specified userid and digestforum.
     *
     * If the userid is not specified, then all subscription data for that digestforum is fetched in a single query and used
     * for subsequent lookups without requiring further database queries.
     *
     * @param int $digestforumid The digestforum to retrieve a cache for
     * @param int $userid The user ID
     * @return void
     */
    public static function fill_subscription_cache($digestforumid, $userid = null) {
        global $DB;

        if (!isset(self::$fetcheddigestforums[$digestforumid])) {
            // This digestforum has not been fetched as a whole.
            if (isset($userid)) {
                if (!isset(self::$digestforumcache[$userid])) {
                    self::$digestforumcache[$userid] = array();
                }

                if (!isset(self::$digestforumcache[$userid][$digestforumid])) {
                    if ($DB->record_exists('digestforum_subscriptions', array(
                        'userid' => $userid,
                        'digestforum' => $digestforumid,
                    ))) {
                        self::$digestforumcache[$userid][$digestforumid] = true;
                    } else {
                        self::$digestforumcache[$userid][$digestforumid] = false;
                    }
                }
            } else {
                $subscriptions = $DB->get_recordset('digestforum_subscriptions', array(
                    'digestforum' => $digestforumid,
                ), '', 'id, userid');
                foreach ($subscriptions as $id => $data) {
                    if (!isset(self::$digestforumcache[$data->userid])) {
                        self::$digestforumcache[$data->userid] = array();
                    }
                    self::$digestforumcache[$data->userid][$digestforumid] = true;
                }
                self::$fetcheddigestforums[$digestforumid] = true;
                $subscriptions->close();
            }
        }
    }

    /**
     * Fill the digestforum subscription data for all digestforums that the specified userid can subscribe to in the specified course.
     *
     * @param int $courseid The course to retrieve a cache for
     * @param int $userid The user ID
     * @return void
     */
    public static function fill_subscription_cache_for_course($courseid, $userid) {
        global $DB;

        if (!isset(self::$digestforumcache[$userid])) {
            self::$digestforumcache[$userid] = array();
        }

        $sql = "SELECT
                    f.id AS digestforumid,
                    s.id AS subscriptionid
                FROM {digestforum} f
                LEFT JOIN {digestforum_subscriptions} s ON (s.digestforum = f.id AND s.userid = :userid)
                WHERE f.course = :course
                AND f.forcesubscribe <> :subscriptionforced";

        $subscriptions = $DB->get_recordset_sql($sql, array(
            'course' => $courseid,
            'userid' => $userid,
            'subscriptionforced' => DFORUM_FORCESUBSCRIBE,
        ));

        foreach ($subscriptions as $id => $data) {
            self::$digestforumcache[$userid][$id] = !empty($data->subscriptionid);
        }
        $subscriptions->close();
    }

    /**
     * Returns a list of user objects who are subscribed to this digestforum.
     *
     * @param stdClass $digestforum The digestforum record.
     * @param int $groupid The group id if restricting subscriptions to a group of users, or 0 for all.
     * @param context_module $context the digestforum context, to save re-fetching it where possible.
     * @param string $fields requested user fields (with "u." table prefix).
     * @param boolean $includediscussionsubscriptions Whether to take discussion subscriptions and unsubscriptions into consideration.
     * @return array list of users.
     */
    public static function fetch_subscribed_users($digestforum, $groupid = 0, $context = null, $fields = null,
            $includediscussionsubscriptions = false) {
        global $CFG, $DB;

        if (empty($fields)) {
            $allnames = get_all_user_name_fields(true, 'u');
            $fields ="u.id,
                      u.username,
                      $allnames,
                      u.maildisplay,
                      u.mailformat,
                      u.maildigest,
                      u.imagealt,
                      u.email,
                      u.emailstop,
                      u.city,
                      u.country,
                      u.lastaccess,
                      u.lastlogin,
                      u.picture,
                      u.timezone,
                      u.theme,
                      u.lang,
                      u.trackdigestforums,
                      u.mnethostid";
        }

        // Retrieve the digestforum context if it wasn't specified.
        $context = digestforum_get_context($digestforum->id, $context);

        if (self::is_forcesubscribed($digestforum)) {
            $results = \mod_digestforum\subscriptions::get_potential_subscribers($context, $groupid, $fields, "u.email ASC");

        } else {
            // Only active enrolled users or everybody on the frontpage.
            list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
            $params['digestforumid'] = $digestforum->id;

            if ($includediscussionsubscriptions) {
                $params['sdigestforumid'] = $digestforum->id;
                $params['dsdigestforumid'] = $digestforum->id;
                $params['unsubscribed'] = self::DFORUM_DISCUSSION_UNSUBSCRIBED;

                $sql = "SELECT $fields
                        FROM (
                            SELECT userid FROM {digestforum_subscriptions} s
                            WHERE
                                s.digestforum = :sdigestforumid
                                UNION
                            SELECT userid FROM {digestforum_discussion_subs} ds
                            WHERE
                                ds.digestforum = :dsdigestforumid AND ds.preference <> :unsubscribed
                        ) subscriptions
                        JOIN {user} u ON u.id = subscriptions.userid
                        JOIN ($esql) je ON je.id = u.id
                        ORDER BY u.email ASC";

            } else {
                $sql = "SELECT $fields
                        FROM {user} u
                        JOIN ($esql) je ON je.id = u.id
                        JOIN {digestforum_subscriptions} s ON s.userid = u.id
                        WHERE
                          s.digestforum = :digestforumid
                        ORDER BY u.email ASC";
            }
            $results = $DB->get_records_sql($sql, $params);
        }

        // Guest user should never be subscribed to a digestforum.
        unset($results[$CFG->siteguest]);

        // Apply the activity module availability resetrictions.
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $digestforum->course);
        $modinfo = get_fast_modinfo($digestforum->course);
        $info = new \core_availability\info_module($modinfo->get_cm($cm->id));
        $results = $info->filter_user_list($results);

        return $results;
    }

    /**
     * Retrieve the discussion subscription data for the specified userid and digestforum.
     *
     * This is returned as an array of discussions for that digestforum which contain the preference in a stdClass.
     *
     * @param int $digestforumid The digestforum to retrieve a cache for
     * @param int $userid The user ID
     * @return array of stdClass objects with one per discussion in the digestforum.
     */
    public static function fetch_discussion_subscription($digestforumid, $userid = null) {
        self::fill_discussion_subscription_cache($digestforumid, $userid);

        if (!isset(self::$digestforumdiscussioncache[$userid]) || !isset(self::$digestforumdiscussioncache[$userid][$digestforumid])) {
            return array();
        }

        return self::$digestforumdiscussioncache[$userid][$digestforumid];
    }

    /**
     * Fill the discussion subscription data for the specified userid and digestforum.
     *
     * If the userid is not specified, then all discussion subscription data for that digestforum is fetched in a single query
     * and used for subsequent lookups without requiring further database queries.
     *
     * @param int $digestforumid The digestforum to retrieve a cache for
     * @param int $userid The user ID
     * @return void
     */
    public static function fill_discussion_subscription_cache($digestforumid, $userid = null) {
        global $DB;

        if (!isset(self::$discussionfetcheddigestforums[$digestforumid])) {
            // This digestforum hasn't been fetched as a whole yet.
            if (isset($userid)) {
                if (!isset(self::$digestforumdiscussioncache[$userid])) {
                    self::$digestforumdiscussioncache[$userid] = array();
                }

                if (!isset(self::$digestforumdiscussioncache[$userid][$digestforumid])) {
                    $subscriptions = $DB->get_recordset('digestforum_discussion_subs', array(
                        'userid' => $userid,
                        'digestforum' => $digestforumid,
                    ), null, 'id, discussion, preference');
                    foreach ($subscriptions as $id => $data) {
                        self::add_to_discussion_cache($digestforumid, $userid, $data->discussion, $data->preference);
                    }
                    $subscriptions->close();
                }
            } else {
                $subscriptions = $DB->get_recordset('digestforum_discussion_subs', array(
                    'digestforum' => $digestforumid,
                ), null, 'id, userid, discussion, preference');
                foreach ($subscriptions as $id => $data) {
                    self::add_to_discussion_cache($digestforumid, $data->userid, $data->discussion, $data->preference);
                }
                self::$discussionfetcheddigestforums[$digestforumid] = true;
                $subscriptions->close();
            }
        }
    }

    /**
     * Add the specified discussion and user preference to the discussion
     * subscription cache.
     *
     * @param int $digestforumid The ID of the digestforum that this preference belongs to
     * @param int $userid The ID of the user that this preference belongs to
     * @param int $discussion The ID of the discussion that this preference relates to
     * @param int $preference The preference to store
     */
    protected static function add_to_discussion_cache($digestforumid, $userid, $discussion, $preference) {
        if (!isset(self::$digestforumdiscussioncache[$userid])) {
            self::$digestforumdiscussioncache[$userid] = array();
        }

        if (!isset(self::$digestforumdiscussioncache[$userid][$digestforumid])) {
            self::$digestforumdiscussioncache[$userid][$digestforumid] = array();
        }

        self::$digestforumdiscussioncache[$userid][$digestforumid][$discussion] = $preference;
    }

    /**
     * Reset the discussion cache.
     *
     * This cache is used to reduce the number of database queries when
     * checking digestforum discussion subscription states.
     */
    public static function reset_discussion_cache() {
        self::$digestforumdiscussioncache = array();
        self::$discussionfetcheddigestforums = array();
    }

    /**
     * Reset the digestforum cache.
     *
     * This cache is used to reduce the number of database queries when
     * checking digestforum subscription states.
     */
    public static function reset_digestforum_cache() {
        self::$digestforumcache = array();
        self::$fetcheddigestforums = array();
    }

    /**
     * Adds user to the subscriber list.
     *
     * @param int $userid The ID of the user to subscribe
     * @param \stdClass $digestforum The digestforum record for this digestforum.
     * @param \context_module|null $context Module context, may be omitted if not known or if called for the current
     *      module set in page.
     * @param boolean $userrequest Whether the user requested this change themselves. This has an effect on whether
     *     discussion subscriptions are removed too.
     * @return bool|int Returns true if the user is already subscribed, or the digestforum_subscriptions ID if the user was
     *     successfully subscribed.
     */
    public static function subscribe_user($userid, $digestforum, $context = null, $userrequest = false) {
        global $DB;

        if (self::is_subscribed($userid, $digestforum)) {
            return true;
        }

        $sub = new \stdClass();
        $sub->userid  = $userid;
        $sub->digestforum = $digestforum->id;

        $result = $DB->insert_record("digestforum_subscriptions", $sub);

        if ($userrequest) {
            $discussionsubscriptions = $DB->get_recordset('digestforum_discussion_subs', array('userid' => $userid, 'digestforum' => $digestforum->id));
            $DB->delete_records_select('digestforum_discussion_subs',
                    'userid = :userid AND digestforum = :digestforumid AND preference <> :preference', array(
                        'userid' => $userid,
                        'digestforumid' => $digestforum->id,
                        'preference' => self::DFORUM_DISCUSSION_UNSUBSCRIBED,
                    ));

            // Reset the subscription caches for this digestforum.
            // We know that the there were previously entries and there aren't any more.
            if (isset(self::$digestforumdiscussioncache[$userid]) && isset(self::$digestforumdiscussioncache[$userid][$digestforum->id])) {
                foreach (self::$digestforumdiscussioncache[$userid][$digestforum->id] as $discussionid => $preference) {
                    if ($preference != self::DFORUM_DISCUSSION_UNSUBSCRIBED) {
                        unset(self::$digestforumdiscussioncache[$userid][$digestforum->id][$discussionid]);
                    }
                }
            }
        }

        // Reset the cache for this digestforum.
        self::$digestforumcache[$userid][$digestforum->id] = true;

        $context = digestforum_get_context($digestforum->id, $context);
        $params = array(
            'context' => $context,
            'objectid' => $result,
            'relateduserid' => $userid,
            'other' => array('digestforumid' => $digestforum->id),

        );
        $event  = event\subscription_created::create($params);
        if ($userrequest && $discussionsubscriptions) {
            foreach ($discussionsubscriptions as $subscription) {
                $event->add_record_snapshot('digestforum_discussion_subs', $subscription);
            }
            $discussionsubscriptions->close();
        }
        $event->trigger();

        return $result;
    }

    /**
     * Removes user from the subscriber list
     *
     * @param int $userid The ID of the user to unsubscribe
     * @param \stdClass $digestforum The digestforum record for this digestforum.
     * @param \context_module|null $context Module context, may be omitted if not known or if called for the current
     *     module set in page.
     * @param boolean $userrequest Whether the user requested this change themselves. This has an effect on whether
     *     discussion subscriptions are removed too.
     * @return boolean Always returns true.
     */
    public static function unsubscribe_user($userid, $digestforum, $context = null, $userrequest = false) {
        global $DB;

        $sqlparams = array(
            'userid' => $userid,
            'digestforum' => $digestforum->id,
        );
        $DB->delete_records('digestforum_digests', $sqlparams);

        if ($digestforumsubscription = $DB->get_record('digestforum_subscriptions', $sqlparams)) {
            $DB->delete_records('digestforum_subscriptions', array('id' => $digestforumsubscription->id));

            if ($userrequest) {
                $discussionsubscriptions = $DB->get_recordset('digestforum_discussion_subs', $sqlparams);
                $DB->delete_records('digestforum_discussion_subs',
                        array('userid' => $userid, 'digestforum' => $digestforum->id, 'preference' => self::DFORUM_DISCUSSION_UNSUBSCRIBED));

                // We know that the there were previously entries and there aren't any more.
                if (isset(self::$digestforumdiscussioncache[$userid]) && isset(self::$digestforumdiscussioncache[$userid][$digestforum->id])) {
                    self::$digestforumdiscussioncache[$userid][$digestforum->id] = array();
                }
            }

            // Reset the cache for this digestforum.
            self::$digestforumcache[$userid][$digestforum->id] = false;

            $context = digestforum_get_context($digestforum->id, $context);
            $params = array(
                'context' => $context,
                'objectid' => $digestforumsubscription->id,
                'relateduserid' => $userid,
                'other' => array('digestforumid' => $digestforum->id),

            );
            $event = event\subscription_deleted::create($params);
            $event->add_record_snapshot('digestforum_subscriptions', $digestforumsubscription);
            if ($userrequest && $discussionsubscriptions) {
                foreach ($discussionsubscriptions as $subscription) {
                    $event->add_record_snapshot('digestforum_discussion_subs', $subscription);
                }
                $discussionsubscriptions->close();
            }
            $event->trigger();
        }

        return true;
    }

    /**
     * Subscribes the user to the specified discussion.
     *
     * @param int $userid The userid of the user being subscribed
     * @param \stdClass $discussion The discussion to subscribe to
     * @param \context_module|null $context Module context, may be omitted if not known or if called for the current
     *     module set in page.
     * @return boolean Whether a change was made
     */
    public static function subscribe_user_to_discussion($userid, $discussion, $context = null) {
        global $DB;

        // First check whether the user is subscribed to the discussion already.
        $subscription = $DB->get_record('digestforum_discussion_subs', array('userid' => $userid, 'discussion' => $discussion->id));
        if ($subscription) {
            if ($subscription->preference != self::DFORUM_DISCUSSION_UNSUBSCRIBED) {
                // The user is already subscribed to the discussion. Ignore.
                return false;
            }
        }
        // No discussion-level subscription. Check for a digestforum level subscription.
        if ($DB->record_exists('digestforum_subscriptions', array('userid' => $userid, 'digestforum' => $discussion->digestforum))) {
            if ($subscription && $subscription->preference == self::DFORUM_DISCUSSION_UNSUBSCRIBED) {
                // The user is subscribed to the digestforum, but unsubscribed from the discussion, delete the discussion preference.
                $DB->delete_records('digestforum_discussion_subs', array('id' => $subscription->id));
                unset(self::$digestforumdiscussioncache[$userid][$discussion->digestforum][$discussion->id]);
            } else {
                // The user is already subscribed to the digestforum. Ignore.
                return false;
            }
        } else {
            if ($subscription) {
                $subscription->preference = time();
                $DB->update_record('digestforum_discussion_subs', $subscription);
            } else {
                $subscription = new \stdClass();
                $subscription->userid  = $userid;
                $subscription->digestforum = $discussion->digestforum;
                $subscription->discussion = $discussion->id;
                $subscription->preference = time();

                $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);
                self::$digestforumdiscussioncache[$userid][$discussion->digestforum][$discussion->id] = $subscription->preference;
            }
        }

        $context = digestforum_get_context($discussion->digestforum, $context);
        $params = array(
            'context' => $context,
            'objectid' => $subscription->id,
            'relateduserid' => $userid,
            'other' => array(
                'digestforumid' => $discussion->digestforum,
                'discussion' => $discussion->id,
            ),

        );
        $event  = event\discussion_subscription_created::create($params);
        $event->trigger();

        return true;
    }
    /**
     * Unsubscribes the user from the specified discussion.
     *
     * @param int $userid The userid of the user being unsubscribed
     * @param \stdClass $discussion The discussion to unsubscribe from
     * @param \context_module|null $context Module context, may be omitted if not known or if called for the current
     *     module set in page.
     * @return boolean Whether a change was made
     */
    public static function unsubscribe_user_from_discussion($userid, $discussion, $context = null) {
        global $DB;

        // First check whether the user's subscription preference for this discussion.
        $subscription = $DB->get_record('digestforum_discussion_subs', array('userid' => $userid, 'discussion' => $discussion->id));
        if ($subscription) {
            if ($subscription->preference == self::DFORUM_DISCUSSION_UNSUBSCRIBED) {
                // The user is already unsubscribed from the discussion. Ignore.
                return false;
            }
        }
        // No discussion-level preference. Check for a digestforum level subscription.
        if (!$DB->record_exists('digestforum_subscriptions', array('userid' => $userid, 'digestforum' => $discussion->digestforum))) {
            if ($subscription && $subscription->preference != self::DFORUM_DISCUSSION_UNSUBSCRIBED) {
                // The user is not subscribed to the digestforum, but subscribed from the discussion, delete the discussion subscription.
                $DB->delete_records('digestforum_discussion_subs', array('id' => $subscription->id));
                unset(self::$digestforumdiscussioncache[$userid][$discussion->digestforum][$discussion->id]);
            } else {
                // The user is not subscribed from the digestforum. Ignore.
                return false;
            }
        } else {
            if ($subscription) {
                $subscription->preference = self::DFORUM_DISCUSSION_UNSUBSCRIBED;
                $DB->update_record('digestforum_discussion_subs', $subscription);
            } else {
                $subscription = new \stdClass();
                $subscription->userid  = $userid;
                $subscription->digestforum = $discussion->digestforum;
                $subscription->discussion = $discussion->id;
                $subscription->preference = self::DFORUM_DISCUSSION_UNSUBSCRIBED;

                $subscription->id = $DB->insert_record('digestforum_discussion_subs', $subscription);
            }
            self::$digestforumdiscussioncache[$userid][$discussion->digestforum][$discussion->id] = $subscription->preference;
        }

        $context = digestforum_get_context($discussion->digestforum, $context);
        $params = array(
            'context' => $context,
            'objectid' => $subscription->id,
            'relateduserid' => $userid,
            'other' => array(
                'digestforumid' => $discussion->digestforum,
                'discussion' => $discussion->id,
            ),

        );
        $event  = event\discussion_subscription_deleted::create($params);
        $event->trigger();

        return true;
    }

}
