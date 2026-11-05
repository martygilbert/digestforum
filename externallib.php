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

use core_course\external\helper_for_get_mods_by_courses;
use mod_digestforum\local\exporters\post as post_exporter;
use mod_digestforum\local\exporters\discussion as discussion_exporter;
use core_external\external_api;
use core_external\external_files;
use core_external\external_format_value;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use core_external\util as external_util;

/**
 * External digestforum API
 *
 * @package    mod_digestforum
 * @copyright  2012 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_digestforum_external extends external_api {
    /**
     * Describes the parameters for get_digestforum.
     *
     * @return external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_digestforums_by_courses_parameters() {
        return new external_function_parameters(
            [
                'courseids' => new external_multiple_structure(new external_value(
                    PARAM_INT,
                    'course ID',
                    VALUE_REQUIRED,
                    '',
                    NULL_NOT_ALLOWED
                ), 'Array of Course IDs', VALUE_DEFAULT, []),
            ]
        );
    }

    /**
     * Returns a list of digestforums in a provided list of courses,
     * if no list is provided all digestforums that the user can view
     * will be returned.
     *
     * @param array $courseids the course ids
     * @return array the digestforum details
     * @since Moodle 2.5
     */
    public static function get_digestforums_by_courses($courseids = []) {
        global $CFG;

        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(self::get_digestforums_by_courses_parameters(), ['courseids' => $courseids]);

        $courses = [];
        if (empty($params['courseids'])) {
            $courses = enrol_get_my_courses();
            $params['courseids'] = array_keys($courses);
        }

        // Array to store the digestforums to return.
        $arrdigestforums = [];
        $warnings = [];

        // Ensure there are courseids to loop through.
        if (!empty($params['courseids'])) {
            [$courses, $warnings] = external_util::validate_courses($params['courseids'], $courses);

            // Get the digestforums in this course. This function checks users visibility permissions.
            $digestforums = get_all_instances_in_courses("digestforum", $courses);
            foreach ($digestforums as $digestforum) {
                $course = $courses[$digestforum->course];
                $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id);
                $context = context_module::instance($cm->id);

                // Skip digestforums we are not allowed to see discussions.
                if (!has_capability('mod/digestforum:viewdiscussion', $context)) {
                    continue;
                }

                $digestforumdetails = helper_for_get_mods_by_courses::standard_coursemodule_element_values(
                    $digestforum,
                    'mod_digestforum',
                );

                unset(
                    $digestforum->section,
                    $digestforum->visible,
                    $digestforum->groupmode,
                    $digestforum->groupingid,
                    $digestforum->enableaitools,
                    $digestforum->enabledaiactions,
                );

                // Merge all digestforum instance fields on top of the standard course-module data.
                $digestforumdetails = array_merge((array) $digestforum, $digestforumdetails);

                // Discussions count. This function does static request cache.
                $digestforumdetails['numdiscussions'] = digestforum_count_discussions($digestforum, $cm, $course);
                $digestforumdetails['cmid'] = $digestforum->coursemodule;
                $digestforumdetails['cancreatediscussions'] = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
                $digestforumdetails['istracked'] = digestforum_tp_is_tracked($digestforum);
                if (!empty($digestforumdetails['istracked'])) {
                    $digestforumdetails['unreadpostscount'] = digestforum_tp_count_digestforum_unread_posts($cm, $course);
                }

                // Add the digestforum to the array to return.
                $arrdigestforums[$digestforum->id] = (object) $digestforumdetails;
            }
        }

        return $arrdigestforums;
    }

    /**
     * Describes the get_digestforum return value.
     *
     * @return external_multiple_structure
     * @since Moodle 2.5
     */
    public static function get_digestforums_by_courses_returns() {
        // This should be using helper_for_get_mods_by_courses::standard_coursemodule_elements_returns, but it is so horribly
        // inconsistent with all similar web serviecs in other modules that we just can't.
        // Also, the return type declaration is wrong, but I am not changing it now because I don't want ot break things.
        return new external_multiple_structure(
            new external_single_structure(
                array_merge(
                    helper_for_get_mods_by_courses::standard_coursemodule_elements_returns(),
                    [
                        'type' => new external_value(PARAM_TEXT, 'The digestforum type'),
                        'duedate' => new external_value(PARAM_INT, 'duedate for the user', VALUE_OPTIONAL),
                        'cutoffdate' => new external_value(PARAM_INT, 'cutoffdate for the user', VALUE_OPTIONAL),
                        'assessed' => new external_value(PARAM_INT, 'Aggregate type'),
                        'assesstimestart' => new external_value(PARAM_INT, 'Assess start time'),
                        'assesstimefinish' => new external_value(PARAM_INT, 'Assess finish time'),
                        'scale' => new external_value(PARAM_INT, 'Scale'),
                        'grade_digestforum' => new external_value(PARAM_INT, 'Whole digestforum grade'),
                        'grade_digestforum_notify' => new external_value(
                            PARAM_INT,
                            'Whether to send notifications to students upon grading by default',
                        ),
                        'maxbytes' => new external_value(PARAM_INT, 'Maximum attachment size'),
                        'maxattachments' => new external_value(PARAM_INT, 'Maximum number of attachments'),
                        'forcesubscribe' => new external_value(PARAM_INT, 'Force users to subscribe'),
                        'trackingtype' => new external_value(PARAM_INT, 'Subscription mode'),
                        'rsstype' => new external_value(PARAM_INT, 'RSS feed for this activity'),
                        'rssarticles' => new external_value(PARAM_INT, 'Number of RSS recent articles'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                        'warnafter' => new external_value(PARAM_INT, 'Post threshold for warning'),
                        'blockafter' => new external_value(PARAM_INT, 'Post threshold for blocking'),
                        'blockperiod' => new external_value(PARAM_INT, 'Time period for blocking'),
                        'completiondiscussions' => new external_value(PARAM_INT, 'Student must create discussions'),
                        'completionreplies' => new external_value(PARAM_INT, 'Student must post replies'),
                        'completionposts' => new external_value(PARAM_INT, 'Student must post discussions or replies'),
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'numdiscussions' => new external_value(PARAM_INT, 'Number of discussions in the digestforum', VALUE_OPTIONAL),
                        'cancreatediscussions' => new external_value(
                            PARAM_BOOL,
                            'If the user can create discussions',
                            VALUE_OPTIONAL,
                        ),
                        'lockdiscussionafter' => new external_value(
                            PARAM_INT,
                            'After what period a discussion is locked',
                            VALUE_OPTIONAL,
                        ),
                        'istracked' => new external_value(PARAM_BOOL, 'If the user is tracking the digestforum', VALUE_OPTIONAL),
                        'unreadpostscount' => new external_value(
                            PARAM_INT,
                            'The number of unread posts for tracked digestforums',
                            VALUE_OPTIONAL,
                        ),
                        'showimmediately' => new external_value(
                            PARAM_BOOL,
                            'Whether to show replies immediately in a Q&A digestforum',
                            VALUE_OPTIONAL,
                        ),
                    ],
                ),
                'digestforum'
            )
        );
    }

    /**
     * Get the digestforum posts in the specified discussion.
     *
     * @param   int $discussionid
     * @param   string $sortby
     * @param   string $sortdirection
     * @param   bool $includeinlineattachments Whether inline attachments should be included or not.
     * @return  array
     */
    public static function get_discussion_posts(int $discussionid, ?string $sortby, ?string $sortdirection, bool $includeinlineattachments = false) {
        global $USER, $CFG;
        // Validate the parameter.
        $params = self::validate_parameters(self::get_discussion_posts_parameters(), [
                'discussionid' => $discussionid,
                'sortby' => $sortby,
                'sortdirection' => $sortdirection,
            ]);
        $warnings = [];

        $vaultfactory = mod_digestforum\local\container::get_vault_factory();

        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($params['discussionid']);

        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_id($discussion->get_digestforum_id());
        $context = $digestforum->get_context();
        self::validate_context($context);

        $sortby = $params['sortby'];
        $sortdirection = $params['sortdirection'];
        $sortallowedvalues = ['id', 'created', 'modified'];
        $directionallowedvalues = ['ASC', 'DESC'];

        if (!in_array(strtolower($sortby), $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                'allowed values are: ' . implode(', ', $sortallowedvalues));
        }

        $sortdirection = strtoupper($sortdirection);
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        $orderbysql = "{$sortby} {$sortdirection}";
        if (!empty($sortby) && $sortby != 'id') {
            $orderbysql .= ", id {$sortdirection}";
        }

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);

        $postvault = $vaultfactory->get_post_vault();
        $posts = $postvault->get_from_discussion_id(
            $USER,
            $discussion->get_id(),
            $capabilitymanager->can_view_any_private_reply($USER),
            $orderbysql
        );

        $builderfactory = mod_digestforum\local\container::get_builder_factory();
        $postbuilder = $builderfactory->get_exported_posts_builder();

        $legacydatamapper = mod_digestforum\local\container::get_legacy_data_mapper_factory();

        // Export the posts for the template before we mark them as read.
        // This allows the template to indicate which are the new/unread ones
        // whilst marking them as read right here and now.
        $exportedposts = $postbuilder->build($USER, [$digestforum], [$discussion], $posts, $includeinlineattachments);

        // Mark these posts as read.
        if (!empty($posts)) {
            if (!$CFG->digestforum_usermarksread && digestforum_tp_is_tracked($digestforum->get_id())) {
                digestforum_tp_mark_posts_read($USER, array_keys($posts));
            }
        }

        return [
            'posts' => $exportedposts,
            'digestforumid' => $discussion->get_digestforum_id(),
            'courseid' => $discussion->get_course_id(),
            'ratinginfo' => \core_rating\external\util::get_rating_info(
                $legacydatamapper->get_digestforum_data_mapper()->to_legacy_object($digestforum),
                $digestforum->get_context(),
                'mod_digestforum',
                'post',
                $legacydatamapper->get_post_data_mapper()->to_legacy_objects($posts)
            ),
            'warnings' => $warnings,
        ];
    }

    /**
     * Describe the post parameters.
     *
     * @return external_function_parameters
     */
    public static function get_discussion_posts_parameters() {
        return new external_function_parameters([
            'discussionid' => new external_value(PARAM_INT, 'The ID of the discussion from which to fetch posts.', VALUE_REQUIRED),
            'sortby' => new external_value(PARAM_ALPHA, 'Sort by this element: id, created or modified', VALUE_DEFAULT, 'created'),
            'sortdirection' => new external_value(PARAM_ALPHA, 'Sort direction: ASC or DESC', VALUE_DEFAULT, 'DESC'),
            'includeinlineattachments' => new external_value(
                PARAM_BOOL,
                'Whether inline attachments should be included or not',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Describe the post return format.
     *
     * @return external_single_structure
     */
    public static function get_discussion_posts_returns() {
        return new external_single_structure([
            'posts' => new external_multiple_structure(\mod_digestforum\local\exporters\post::get_read_structure()),
            'digestforumid' => new external_value(PARAM_INT, 'The digestforum id'),
            'courseid' => new external_value(PARAM_INT, 'The digestforum course id'),
            'ratinginfo' => \core_rating\external\util::external_ratings_structure(),
            'warnings' => new external_warnings(),
        ]);
    }

    /**
     * Describes the parameters for get_digestforum_discussions.
     *
     * @return external_function_parameters
     * @since Moodle 3.7
     */
    public static function get_digestforum_discussions_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'digestforum instance id', VALUE_REQUIRED),
                'sortorder' => new external_value(
                    PARAM_INT,
                    'sort by this element: numreplies, , created or timemodified',
                    VALUE_DEFAULT,
                    -1
                ),
                'page' => new external_value(PARAM_INT, 'current page', VALUE_DEFAULT, -1),
                'perpage' => new external_value(PARAM_INT, 'items per page', VALUE_DEFAULT, 0),
                'groupid' => new external_value(PARAM_INT, 'group id', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Returns a list of digestforum discussions optionally sorted and paginated.
     *
     * @param int $digestforumid the digestforum instance id
     * @param int $sortorder The sort order
     * @param int $page page number
     * @param int $perpage items per page
     * @param int $groupid the user course group
     *
     *
     * @return array the digestforum discussion details including warnings
     * @since Moodle 3.7
     */
    public static function get_digestforum_discussions(
        int $digestforumid,
        ?int $sortorder = -1,
        ?int $page = -1,
        ?int $perpage = 0,
        ?int $groupid = 0
    ) {

        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $warnings = [];
        $discussions = [];

        $params = self::validate_parameters(
            self::get_digestforum_discussions_parameters(),
            [
                'digestforumid' => $digestforumid,
                'sortorder' => $sortorder,
                'page' => $page,
                'perpage' => $perpage,
                'groupid' => $groupid,
            ]
        );

        // Compact/extract functions are not recommended.
        $digestforumid        = $params['digestforumid'];
        $sortorder      = $params['sortorder'];
        $page           = $params['page'];
        $perpage        = $params['perpage'];
        $groupid        = $params['groupid'];

        $vaultfactory = \mod_digestforum\local\container::get_vault_factory();
        $discussionlistvault = $vaultfactory->get_discussions_in_digestforum_vault();

        $sortallowedvalues = [
            $discussionlistvault::SORTORDER_LASTPOST_DESC,
            $discussionlistvault::SORTORDER_LASTPOST_ASC,
            $discussionlistvault::SORTORDER_CREATED_DESC,
            $discussionlistvault::SORTORDER_CREATED_ASC,
            $discussionlistvault::SORTORDER_REPLIES_DESC,
            $discussionlistvault::SORTORDER_REPLIES_ASC,
        ];

        // If sortorder not defined set a default one.
        if ($sortorder == -1) {
            $sortorder = $discussionlistvault::SORTORDER_LASTPOST_DESC;
        }

        if (!in_array($sortorder, $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortorder parameter (value: ' . $sortorder . '),' .
                ' allowed values are: ' . implode(',', $sortallowedvalues));
        }

        $managerfactory = \mod_digestforum\local\container::get_manager_factory();
        $urlfactory = \mod_digestforum\local\container::get_url_factory();
        $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();

        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_id($digestforumid);
        if (!$digestforum) {
            throw new \moodle_exception("Unable to find digestforum with id {$digestforumid}");
        }
        $digestforumdatamapper = $legacydatamapperfactory->get_digestforum_data_mapper();
        $digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);

        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);

        $course = $DB->get_record('course', ['id' => $digestforum->get_course_id()], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->get_id(), $course->id, false, MUST_EXIST);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        $canseeanyprivatereply = $capabilitymanager->can_view_any_private_reply($USER);

        // Check they have the view digestforum capability.
        if (!$capabilitymanager->can_view_discussions($USER)) {
            throw new moodle_exception('noviewdiscussionspermission', 'digestforum');
        }

        $alldiscussions = mod_digestforum_get_discussion_summaries($digestforum, $USER, $groupid, $sortorder, $page, $perpage);

        if ($alldiscussions) {
            $discussionids = array_keys($alldiscussions);

            $postvault = $vaultfactory->get_post_vault();
            $postdatamapper = $legacydatamapperfactory->get_post_data_mapper();
            // Return the reply count for each discussion in a given digestforum.
            $replies = $postvault->get_reply_count_for_discussion_ids($USER, $discussionids, $canseeanyprivatereply);
            // Return the first post for each discussion in a given digestforum.
            $firstposts = $postvault->get_first_post_for_discussion_ids($discussionids);

            // Get the unreads array, this takes a digestforum id and returns data for all discussions.
            $unreads = [];
            if ($cantrack = digestforum_tp_can_track_digestforums($digestforumrecord)) {
                if ($digestforumtracked = digestforum_tp_is_tracked($digestforumrecord)) {
                    $unreads = $postvault->get_unread_count_for_discussion_ids($USER, $discussionids, $canseeanyprivatereply);
                }
            }

            $canlock = $capabilitymanager->can_manage_digestforum($USER);

            $usercontext = context_user::instance($USER->id);
            $ufservice = core_favourites\service_factory::get_service_for_user_context($usercontext);

            $canfavourite = has_capability('mod/digestforum:cantogglefavourite', $modcontext, $USER);

            foreach ($alldiscussions as $discussionsummary) {
                $discussion = $discussionsummary->get_discussion();
                $firstpostauthor = $discussionsummary->get_first_post_author();
                $latestpostauthor = $discussionsummary->get_latest_post_author();

                // This function checks for qanda digestforums.
                $canviewdiscussion = $capabilitymanager->can_view_discussion($USER, $discussion);
                if (!$canviewdiscussion) {
                    $warning = [];
                    // Function digestforum_get_discussions returns digestforum_posts ids not digestforum_discussions ones.
                    $warning['item'] = 'post';
                    $warning['itemid'] = $discussion->get_id();
                    $warning['warningcode'] = '1';
                    $warning['message'] = 'You can\'t see this discussion';
                    $warnings[] = $warning;
                    continue;
                }

                $firstpost = $firstposts[$discussion->get_first_post_id()];
                $discussionobject = $postdatamapper->to_legacy_object($firstpost);
                // Fix up the types for these properties.
                $discussionobject->mailed = $discussionobject->mailed ? 1 : 0;
                $discussionobject->messagetrust = $discussionobject->messagetrust ? 1 : 0;
                $discussionobject->mailnow = $discussionobject->mailnow ? 1 : 0;
                $discussionobject->groupid = $discussion->get_group_id();
                $discussionobject->timemodified = $discussion->get_time_modified();
                $discussionobject->usermodified = $discussion->get_user_modified();
                $discussionobject->timestart = $discussion->get_time_start();
                $discussionobject->timeend = $discussion->get_time_end();
                $discussionobject->pinned = $discussion->is_pinned();

                $discussionobject->numunread = 0;
                if ($cantrack && $digestforumtracked) {
                    if (isset($unreads[$discussion->get_id()])) {
                        $discussionobject->numunread = (int) $unreads[$discussion->get_id()];
                    }
                }

                $discussionobject->numreplies = 0;
                if (!empty($replies[$discussion->get_id()])) {
                    $discussionobject->numreplies = (int) $replies[$discussion->get_id()];
                }

                $discussionobject->name = \core_external\util::format_string($discussion->get_name(), $modcontext);
                $discussionobject->subject = \core_external\util::format_string($discussionobject->subject, $modcontext);
                // Rewrite embedded images URLs.
                $options = ['trusted' => $discussionobject->messagetrust];
                [$discussionobject->message, $discussionobject->messageformat] =
                    \core_external\util::format_text(
                        $discussionobject->message,
                        $discussionobject->messageformat,
                        $modcontext,
                        'mod_digestforum',
                        'post',
                        $discussionobject->id,
                        $options
                    );

                // List attachments.
                if (!empty($discussionobject->attachment)) {
                    $discussionobject->attachments = external_util::get_area_files(
                        $modcontext->id,
                        'mod_digestforum',
                        'attachment',
                        $discussionobject->id
                    );
                }
                $messageinlinefiles = external_util::get_area_files(
                    $modcontext->id,
                    'mod_digestforum',
                    'post',
                    $discussionobject->id
                );
                if (!empty($messageinlinefiles)) {
                    $discussionobject->messageinlinefiles = $messageinlinefiles;
                }

                $discussionobject->locked = $digestforum->is_discussion_locked($discussion);
                $discussionobject->canlock = $canlock;
                $discussionobject->starred = !empty($ufservice) ? $ufservice->favourite_exists(
                    'mod_digestforum',
                    'discussions',
                    $discussion->get_id(),
                    $modcontext
                ) : false;
                $discussionobject->canreply = $capabilitymanager->can_post_in_discussion($USER, $discussion);
                $discussionobject->canfavourite = $canfavourite;

                if (digestforum_is_author_hidden($discussionobject, $digestforumrecord)) {
                    $discussionobject->userid = null;
                    $discussionobject->userfullname = null;
                    $discussionobject->userinitials = null;
                    $discussionobject->userpictureurl = null;

                    $discussionobject->usermodified = null;
                    $discussionobject->usermodifiedfullname = null;
                    $discussionobject->usermodifiedinitials = null;
                    $discussionobject->usermodifiedpictureurl = null;
                } else {
                    $discussionobject->userfullname = $firstpostauthor->get_full_name();
                    $discussionobject->userinitials = $firstpostauthor->get_initials();
                    $discussionobject->userpictureurl = $urlfactory->get_author_profile_image_url($firstpostauthor, null, 2)
                        ->out(false);

                    $discussionobject->usermodifiedfullname = $latestpostauthor->get_full_name();
                    $discussionobject->usermodifiedinitials = $latestpostauthor->get_initials();
                    $discussionobject->usermodifiedpictureurl = $urlfactory->get_author_profile_image_url(
                        $latestpostauthor,
                        null,
                        2
                    )->out(false);
                }

                $discussions[] = (array) $discussionobject;
            }
        }
        $result = [];
        $result['discussions'] = $discussions;
        $result['warnings'] = $warnings;

        return $result;
    }

    /**
     * Describes the get_digestforum_discussions return value.
     *
     * @return external_single_structure
     * @since Moodle 3.7
     */
    public static function get_digestforum_discussions_returns() {
        return new external_single_structure(
            [
                'discussions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'Post id'),
                            'name' => new external_value(PARAM_RAW, 'Discussion name'),
                            'groupid' => new external_value(PARAM_INT, 'Group id'),
                            'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                            'usermodified' => new external_value(PARAM_INT, 'The id of the user who last modified'),
                            'timestart' => new external_value(PARAM_INT, 'Time discussion can start'),
                            'timeend' => new external_value(PARAM_INT, 'Time discussion ends'),
                            'discussion' => new external_value(PARAM_INT, 'Discussion id'),
                            'parent' => new external_value(PARAM_INT, 'Parent id'),
                            'userid' => new external_value(PARAM_INT, 'User who started the discussion id'),
                            'created' => new external_value(PARAM_INT, 'Creation time'),
                            'modified' => new external_value(PARAM_INT, 'Time modified'),
                            'mailed' => new external_value(PARAM_INT, 'Mailed?'),
                            'subject' => new external_value(PARAM_RAW, 'The post subject'),
                            'message' => new external_value(PARAM_RAW, 'The post message'),
                            'messageformat' => new external_format_value('message'),
                            'messagetrust' => new external_value(PARAM_INT, 'Can we trust?'),
                            'messageinlinefiles' => new external_files('post message inline files', VALUE_OPTIONAL),
                            'attachment' => new external_value(PARAM_RAW, 'Has attachments?'),
                            'attachments' => new external_files('attachments', VALUE_OPTIONAL),
                            'totalscore' => new external_value(PARAM_INT, 'The post message total score'),
                            'mailnow' => new external_value(PARAM_INT, 'Mail now?'),
                            'userfullname' => new external_value(PARAM_TEXT, 'Post author full name'),
                            'userinitials' => new external_value(PARAM_NOTAGS, 'Post author initials', VALUE_OPTIONAL),
                            'usermodifiedfullname' => new external_value(PARAM_TEXT, 'Post modifier full name'),
                            'usermodifiedinitials' => new external_value(PARAM_NOTAGS, 'Post modifier initials', VALUE_OPTIONAL),
                            'userpictureurl' => new external_value(PARAM_URL, 'Post author picture.'),
                            'usermodifiedpictureurl' => new external_value(PARAM_URL, 'Post modifier picture.'),
                            'numreplies' => new external_value(PARAM_INT, 'The number of replies in the discussion'),
                            'numunread' => new external_value(PARAM_INT, 'The number of unread discussions.'),
                            'pinned' => new external_value(PARAM_BOOL, 'Is the discussion pinned'),
                            'locked' => new external_value(PARAM_BOOL, 'Is the discussion locked'),
                            'starred' => new external_value(PARAM_BOOL, 'Is the discussion starred'),
                            'canreply' => new external_value(PARAM_BOOL, 'Can the user reply to the discussion'),
                            'canlock' => new external_value(PARAM_BOOL, 'Can the user lock the discussion'),
                            'canfavourite' => new external_value(PARAM_BOOL, 'Can the user star the discussion'),
                        ],
                        'post'
                    )
                ),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function view_digestforum_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'digestforum instance id'),
            ]
        );
    }

    /**
     * Trigger the course module viewed event and update the module completion status.
     *
     * @param int $digestforumid the digestforum instance id
     * @return array of warnings and status result
     * @since Moodle 2.9
     * @throws moodle_exception
     */
    public static function view_digestforum($digestforumid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::view_digestforum_parameters(),
            [
                                                'digestforumid' => $digestforumid,
            ]
        );
        $warnings = [];

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', ['id' => $params['digestforumid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        require_capability('mod/digestforum:viewdiscussion', $context, null, true, 'noviewdiscussionspermission', 'digestforum');

        // Call the digestforum/lib API.
        digestforum_view($digestforum, $course, $cm, $context);

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 2.9
     */
    public static function view_digestforum_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function view_digestforum_discussion_parameters() {
        return new external_function_parameters(
            [
                'discussionid' => new external_value(PARAM_INT, 'discussion id'),
            ]
        );
    }

    /**
     * Trigger the discussion viewed event.
     *
     * @param int $discussionid the discussion id
     * @return array of warnings and status result
     * @since Moodle 2.9
     * @throws moodle_exception
     */
    public static function view_digestforum_discussion($discussionid) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::view_digestforum_discussion_parameters(),
            [
                                                'discussionid' => $discussionid,
            ]
        );
        $warnings = [];

        $discussion = $DB->get_record('digestforum_discussions', ['id' => $params['discussionid']], '*', MUST_EXIST);
        $digestforum = $DB->get_record('digestforum', ['id' => $discussion->digestforum], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($digestforum, 'digestforum');

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        require_capability('mod/digestforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'digestforum');

        // Call the digestforum/lib API.
        digestforum_discussion_view($modcontext, $digestforum, $discussion);

        // Mark as read if required.
        if (!$CFG->digestforum_usermarksread && digestforum_tp_is_tracked($digestforum)) {
            digestforum_tp_mark_discussion_read($USER, $discussion->id);
        }

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 2.9
     */
    public static function view_digestforum_discussion_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function add_discussion_post_parameters() {
        return new external_function_parameters(
            [
                'postid' => new external_value(PARAM_INT, 'the post id we are going to reply to
                                                (can be the initial discussion post'),
                'subject' => new external_value(PARAM_TEXT, 'new post subject'),
                'message' => new external_value(PARAM_RAW, 'new post message (html assumed if messageformat is not provided)'),
                'options' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(
                                PARAM_ALPHANUM,
                                'The allowed keys (value format) are:
                                        discussionsubscribe (bool); subscribe to the discussion?, default to true
                                        private (bool); make this reply private to the author of the parent post, default to false.
                                        inlineattachmentsid              (int); the draft file area id for inline attachments
                                        attachmentsid       (int); the draft file area id for attachments
                                        topreferredformat (bool); convert the message & messageformat to FORMAT_HTML, defaults to false
                            '
                            ),
                            'value' => new external_value(PARAM_RAW, 'the value of the option,
                                                            this param is validated in the external function.'),
                        ]
                    ),
                    'Options',
                    VALUE_DEFAULT,
                    []
                ),
                'messageformat' => new external_format_value('message', VALUE_DEFAULT),
            ]
        );
    }

    /**
     * Create new posts into an existing discussion.
     *
     * @param int $postid the post id we are going to reply to
     * @param string $subject new post subject
     * @param string $message new post message (html assumed if messageformat is not provided)
     * @param array $options optional settings
     * @param string $messageformat The format of the message, defaults to FORMAT_HTML for BC
     * @return array of warnings and the new post id
     * @since Moodle 3.0
     * @throws moodle_exception
     */
    public static function add_discussion_post($postid, $subject, $message, $options = [], $messageformat = FORMAT_HTML) {
        global $CFG, $USER;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        // Get all the factories that are required.
        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $entityfactory = mod_digestforum\local\container::get_entity_factory();
        $datamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $discussiondatamapper = $datamapperfactory->get_discussion_data_mapper();
        $digestforumdatamapper = $datamapperfactory->get_digestforum_data_mapper();

        $params = self::validate_parameters(
            self::add_discussion_post_parameters(),
            [
                'postid' => $postid,
                'subject' => $subject,
                'message' => $message,
                'options' => $options,
                'messageformat' => $messageformat,
            ]
        );

        $warnings = [];

        if (!$parent = digestforum_get_post_full($params['postid'])) {
            throw new moodle_exception('invalidparentpostid', 'digestforum');
        }

        if (!$discussion = $discussionvault->get_from_id($parent->discussion)) {
            throw new moodle_exception('notpartofdiscussion', 'digestforum');
        }

        // Request and permission validation.
        $digestforum = $digestforumvault->get_from_id($discussion->get_digestforum_id());
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);
        $course = $digestforum->get_course_record();
        $cm = $digestforum->get_course_module_record();

        $discussionrecord = $discussiondatamapper->to_legacy_object($discussion);
        $digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $coursecontext = \context_course::instance($digestforum->get_course_id());
        $discussionsubscribe = \mod_digestforum\subscriptions::get_user_default_subscription(
            $digestforumrecord,
            $coursecontext,
            $cm,
            null
        );

        // Validate options.
        $options = [
            'discussionsubscribe' => $discussionsubscribe,
            'private'             => false,
            'inlineattachmentsid' => 0,
            'attachmentsid' => null,
            'topreferredformat'   => false,
        ];
        foreach ($params['options'] as $option) {
            $name = trim($option['name']);
            switch ($name) {
                case 'discussionsubscribe':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'private':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'inlineattachmentsid':
                    $value = clean_param($option['value'], PARAM_INT);
                    break;
                case 'attachmentsid':
                    $value = clean_param($option['value'], PARAM_INT);
                    // Ensure that the user has permissions to create attachments.
                    if (!has_capability('mod/digestforum:createattachment', $context)) {
                        $value = 0;
                    }
                    break;
                case 'topreferredformat':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                default:
                    throw new moodle_exception('errorinvalidparam', 'webservice', '', $name);
            }
            $options[$name] = $value;
        }

        if (!$capabilitymanager->can_post_in_discussion($USER, $discussion)) {
            throw new moodle_exception('nopostdigestforum', 'digestforum');
        }

        $thresholdwarning = digestforum_check_throttling($digestforumrecord, $cm);
        digestforum_check_blocking_threshold($thresholdwarning);

        // If we want to force a conversion to the preferred format, let's do it now.
        if ($options['topreferredformat']) {
            // We always are going to honor the preferred format. We are creating a new post.
            $preferredformat = editors_get_preferred_format();
            // If the post is not HTML and the preferred format is HTML, convert to it.
            if ($params['messageformat'] != FORMAT_HTML and $preferredformat == FORMAT_HTML) {
                $params['message'] = format_text($params['message'], $params['messageformat'], ['filter' => false]);
            }
            $params['messageformat'] = $preferredformat;
        }

        // Create the post.
        $post = new stdClass();
        $post->discussion = $discussion->get_id();
        $post->parent = $parent->id;
        $post->subject = $params['subject'];
        $post->message = $params['message'];
        $post->messageformat = $params['messageformat'];
        $post->messagetrust = trusttext_trusted($context);
        $post->itemid = $options['inlineattachmentsid'];
        $post->attachments = $options['attachmentsid'];
        $post->isprivatereply = $options['private'];
        $post->deleted = 0;
        $fakemform = $post->attachments;
        if ($postid = digestforum_add_new_post($post, $fakemform)) {
            $post->id = $postid;

            // Trigger events and completion.
            $params = [
                'context' => $context,
                'objectid' => $post->id,
                'other' => [
                    'discussionid' => $discussion->get_id(),
                    'digestforumid' => $digestforum->get_id(),
                    'digestforumtype' => $digestforum->get_type(),
                ],
            ];
            $event = \mod_digestforum\event\post_created::create($params);
            $event->add_record_snapshot('digestforum_posts', $post);
            $event->add_record_snapshot('digestforum_discussions', $discussionrecord);
            $event->trigger();

            // Update completion state.
            $completion = new completion_info($course);
            if (
                $completion->is_enabled($cm) &&
                    ($digestforum->get_completion_replies() || $digestforum->get_completion_posts())
            ) {
                $completion->update_state($cm, COMPLETION_COMPLETE);
            }

            if ($options['discussionsubscribe']) {
                $settings = new stdClass();
                $settings->discussionsubscribe = $options['discussionsubscribe'];
                digestforum_post_subscription($settings, $digestforumrecord, $discussionrecord);
            }
        } else {
            throw new moodle_exception('couldnotadd', 'digestforum');
        }

        $builderfactory = \mod_digestforum\local\container::get_builder_factory();
        $exportedpostsbuilder = $builderfactory->get_exported_posts_builder();
        $postentity = $entityfactory->get_post_from_stdClass($post);
        $exportedposts = $exportedpostsbuilder->build($USER, [$digestforum], [$discussion], [$postentity]);
        $exportedpost = $exportedposts[0];

        $message = [];
        $message[] = [
            'type' => 'success',
            'message' => get_string("postaddedsuccess", "digestforum"),
        ];

        $message[] = [
            'type' => 'success',
            'message' => get_string("postaddedtimeleft", "digestforum", format_time($CFG->maxeditingtime)),
        ];

        $result = [];
        $result['postid'] = $postid;
        $result['warnings'] = $warnings;
        $result['post'] = $exportedpost;
        $result['messages'] = $message;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.0
     */
    public static function add_discussion_post_returns() {
        return new external_single_structure(
            [
                'postid' => new external_value(PARAM_INT, 'new post id'),
                'warnings' => new external_warnings(),
                'post' => post_exporter::get_read_structure(),
                'messages' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'type' => new external_value(PARAM_TEXT, "The classification to be used in the client side", VALUE_REQUIRED),
                            'message' => new external_value(PARAM_TEXT, 'untranslated english message to explain the warning', VALUE_REQUIRED),
                        ],
                        'Messages'
                    ),
                    'list of warnings',
                    VALUE_OPTIONAL
                ),
                // 'alertmessage' => new external_value(PARAM_RAW, 'Success message to be displayed to the user.'),
            ]
        );
    }

    /**
     * Toggle the favouriting value for the discussion provided
     *
     * @param int $discussionid The discussion we need to favourite
     * @param bool $targetstate The state of the favourite value
     * @return array The exported discussion
     */
    public static function toggle_favourite_state($discussionid, $targetstate) {
        global $DB, $PAGE, $USER;

        $params = self::validate_parameters(self::toggle_favourite_state_parameters(), [
            'discussionid' => $discussionid,
            'targetstate' => $targetstate,
        ]);

        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        // Get the discussion vault and the corresponding discussion entity.
        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($params['discussionid']);

        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_id($discussion->get_digestforum_id());
        $digestforumcontext = $digestforum->get_context();
        self::validate_context($digestforumcontext);

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);

        // Does the user have the ability to favourite the discussion?
        if (!$capabilitymanager->can_favourite_discussion($USER)) {
            throw new moodle_exception('cannotfavourite', 'digestforum');
        }
        $usercontext = context_user::instance($USER->id);
        $ufservice = \core_favourites\service_factory::get_service_for_user_context($usercontext);
        $isfavourited = $ufservice->favourite_exists('mod_digestforum', 'discussions', $discussion->get_id(), $digestforumcontext);

        $favouritefunction = $targetstate ? 'create_favourite' : 'delete_favourite';
        if ($isfavourited != (bool) $params['targetstate']) {
            $ufservice->{$favouritefunction}('mod_digestforum', 'discussions', $discussion->get_id(), $digestforumcontext);
        }

        $exporterfactory = mod_digestforum\local\container::get_exporter_factory();
        $builder = mod_digestforum\local\container::get_builder_factory()->get_exported_discussion_builder();
        $favourited = ($builder->is_favourited($discussion, $digestforumcontext, $USER) ? [$discussion->get_id()] : []);
        $exporter = $exporterfactory->get_discussion_exporter($USER, $digestforum, $discussion, [], $favourited);
        return $exporter->export($PAGE->get_renderer('mod_digestforum'));
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.0
     */
    public static function toggle_favourite_state_returns() {
        return discussion_exporter::get_read_structure();
    }

    /**
     * Defines the parameters for the toggle_favourite_state method
     *
     * @return external_function_parameters
     */
    public static function toggle_favourite_state_parameters() {
        return new external_function_parameters(
            [
                'discussionid' => new external_value(PARAM_INT, 'The discussion to subscribe or unsubscribe'),
                'targetstate' => new external_value(PARAM_BOOL, 'The target state'),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function add_discussion_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'Forum instance ID'),
                'subject' => new external_value(PARAM_TEXT, 'New Discussion subject'),
                'message' => new external_value(PARAM_RAW, 'New Discussion message (only html format allowed)'),
                'groupid' => new external_value(PARAM_INT, 'The group, default to 0', VALUE_DEFAULT, 0),
                'options' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(
                                PARAM_ALPHANUM,
                                'The allowed keys (value format) are:
                                        discussionsubscribe (bool); subscribe to the discussion?, default to true
                                        discussionpinned    (bool); is the discussion pinned, default to false
                                        inlineattachmentsid              (int); the draft file area id for inline attachments
                                        attachmentsid       (int); the draft file area id for attachments
                            '
                            ),
                            'value' => new external_value(PARAM_RAW, 'The value of the option,
                                                            This param is validated in the external function.'),
                        ]
                    ),
                    'Options',
                    VALUE_DEFAULT,
                    []
                ),
            ]
        );
    }

    /**
     * Add a new discussion into an existing digestforum.
     *
     * @param int $digestforumid the digestforum instance id
     * @param string $subject new discussion subject
     * @param string $message new discussion message (only html format allowed)
     * @param int $groupid the user course group
     * @param array $options optional settings
     * @return array of warnings and the new discussion id
     * @since Moodle 3.0
     * @throws moodle_exception
     */
    public static function add_discussion($digestforumid, $subject, $message, $groupid = 0, $options = []) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::add_discussion_parameters(),
            [
                                                'digestforumid' => $digestforumid,
                                                'subject' => $subject,
                                                'message' => $message,
                                                'groupid' => $groupid,
                                                'options' => $options,
            ]
        );

        $warnings = [];

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', ['id' => $params['digestforumid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Validate options.
        $options = [
            'discussionsubscribe' => true,
            'discussionpinned' => false,
            'inlineattachmentsid' => 0,
            'attachmentsid' => null,
        ];
        foreach ($params['options'] as $option) {
            $name = trim($option['name']);
            switch ($name) {
                case 'discussionsubscribe':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'discussionpinned':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'inlineattachmentsid':
                    $value = clean_param($option['value'], PARAM_INT);
                    break;
                case 'attachmentsid':
                    $value = clean_param($option['value'], PARAM_INT);
                    // Ensure that the user has permissions to create attachments.
                    if (!has_capability('mod/digestforum:createattachment', $context)) {
                        $value = 0;
                    }
                    break;
                default:
                    throw new moodle_exception('errorinvalidparam', 'webservice', '', $name);
            }
            $options[$name] = $value;
        }

        // Normalize group.
        if (!groups_get_activity_groupmode($cm)) {
            // Groups not supported, force to -1.
            $groupid = -1;
        } else {
            // Check if we receive the default or and empty value for groupid,
            // in this case, get the group for the user in the activity.
            if (empty($params['groupid'])) {
                $groupid = groups_get_activity_group($cm);
            } else {
                // Here we rely in the group passed, digestforum_user_can_post_discussion will validate the group.
                $groupid = $params['groupid'];
            }
        }

        if (!digestforum_user_can_post_discussion($digestforum, $groupid, -1, $cm, $context)) {
            throw new moodle_exception('cannotcreatediscussion', 'digestforum');
        }

        $thresholdwarning = digestforum_check_throttling($digestforum, $cm);
        digestforum_check_blocking_threshold($thresholdwarning);

        // Create the discussion.
        $discussion = new stdClass();
        $discussion->course = $course->id;
        $discussion->digestforum = $digestforum->id;
        $discussion->message = $params['message'];
        $discussion->messageformat = FORMAT_HTML;   // Force formatting for now.
        $discussion->messagetrust = trusttext_trusted($context);
        $discussion->itemid = $options['inlineattachmentsid'];
        $discussion->groupid = $groupid;
        $discussion->mailnow = 0;
        $discussion->subject = $params['subject'];
        $discussion->name = $discussion->subject;
        $discussion->timestart = 0;
        $discussion->timeend = 0;
        $discussion->timelocked = 0;
        $discussion->attachments = $options['attachmentsid'];

        if (has_capability('mod/digestforum:pindiscussions', $context) && $options['discussionpinned']) {
            $discussion->pinned = DFORUM_DISCUSSION_PINNED;
        } else {
            $discussion->pinned = DFORUM_DISCUSSION_UNPINNED;
        }
        $fakemform = $options['attachmentsid'];
        if ($discussionid = digestforum_add_discussion($discussion, $fakemform)) {
            $discussion->id = $discussionid;

            // Trigger events and completion.

            $params = [
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => [
                    'digestforumid' => $digestforum->id,
                ],
            ];
            $event = \mod_digestforum\event\discussion_created::create($params);
            $event->add_record_snapshot('digestforum_discussions', $discussion);
            $event->trigger();

            $completion = new completion_info($course);
            if (
                $completion->is_enabled($cm) &&
                    ($digestforum->completiondiscussions || $digestforum->completionposts)
            ) {
                $completion->update_state($cm, COMPLETION_COMPLETE);
            }

            $settings = new stdClass();
            $settings->discussionsubscribe = $options['discussionsubscribe'];
            digestforum_post_subscription($settings, $digestforum, $discussion);
        } else {
            throw new moodle_exception('couldnotadd', 'digestforum');
        }

        $result = [];
        $result['discussionid'] = $discussionid;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.0
     */
    public static function add_discussion_returns() {
        return new external_single_structure(
            [
                'discussionid' => new external_value(PARAM_INT, 'New Discussion ID'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function can_add_discussion_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'Forum instance ID'),
                'groupid' => new external_value(PARAM_INT, 'The group to check, default to active group.
                                                Use -1 to check if the user can post in all the groups.', VALUE_DEFAULT, null),
            ]
        );
    }

    /**
     * Check if the current user can add discussions in the given digestforum (and optionally for the given group).
     *
     * @param int $digestforumid the digestforum instance id
     * @param int $groupid the group to check, default to active group. Use -1 to check if the user can post in all the groups.
     * @return array of warnings and the status (true if the user can add discussions)
     * @since Moodle 3.1
     * @throws moodle_exception
     */
    public static function can_add_discussion($digestforumid, $groupid = null) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::can_add_discussion_parameters(),
            [
                                                'digestforumid' => $digestforumid,
                                                'groupid' => $groupid,
            ]
        );
        $warnings = [];

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', ['id' => $params['digestforumid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $status = digestforum_user_can_post_discussion($digestforum, $params['groupid'], -1, $cm, $context);

        $result = [];
        $result['status'] = $status;
        $result['canpindiscussions'] = has_capability('mod/digestforum:pindiscussions', $context);
        $result['cancreateattachment'] = digestforum_can_create_attachment($digestforum, $context);
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.1
     */
    public static function can_add_discussion_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'True if the user can add discussions, false otherwise.'),
                'canpindiscussions' => new external_value(
                    PARAM_BOOL,
                    'True if the user can pin discussions, false otherwise.',
                    VALUE_OPTIONAL
                ),
                'cancreateattachment' => new external_value(
                    PARAM_BOOL,
                    'True if the user can add attachments, false otherwise.',
                    VALUE_OPTIONAL
                ),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_digestforum_access_information.
     *
     * @return external_external_function_parameters
     * @since Moodle 3.7
     */
    public static function get_digestforum_access_information_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'Forum instance id.'),
            ]
        );
    }

    /**
     * Return access information for a given digestforum.
     *
     * @param int $digestforumid digestforum instance id
     * @return array of warnings and the access information
     * @since Moodle 3.7
     * @throws  moodle_exception
     */
    public static function get_digestforum_access_information($digestforumid) {
        global $DB;

        $params = self::validate_parameters(self::get_digestforum_access_information_parameters(), ['digestforumid' => $digestforumid]);

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', ['id' => $params['digestforumid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $result = [];
        // Return all the available capabilities.
        $capabilities = load_capability_def('mod_digestforum');
        foreach ($capabilities as $capname => $capdata) {
            // Get fields like cansubmit so it is consistent with the access_information function implemented in other modules.
            $field = 'can' . str_replace('mod/digestforum:', '', $capname);
            $result[$field] = has_capability($capname, $context);
        }

        $result['warnings'] = [];
        return $result;
    }

    /**
     * Describes the get_digestforum_access_information return value.
     *
     * @return external_single_structure
     * @since Moodle 3.7
     */
    public static function get_digestforum_access_information_returns() {

        $structure = [
            'warnings' => new external_warnings(),
        ];

        $capabilities = load_capability_def('mod_digestforum');
        foreach ($capabilities as $capname => $capdata) {
            // Get fields like cansubmit so it is consistent with the access_information function implemented in other modules.
            $field = 'can' . str_replace('mod/digestforum:', '', $capname);
            $structure[$field] = new external_value(
                PARAM_BOOL,
                'Whether the user has the capability ' . $capname . ' allowed.',
                VALUE_OPTIONAL
            );
        }

        return new external_single_structure($structure);
    }

    /**
     * Set the subscription state.
     *
     * @param   int     $digestforumid
     * @param   int     $discussionid
     * @param   bool    $targetstate
     * @return  \stdClass
     */
    public static function set_subscription_state($digestforumid, $discussionid, $targetstate) {
        global $PAGE, $USER;

        $params = self::validate_parameters(self::set_subscription_state_parameters(), [
            'digestforumid' => $digestforumid,
            'discussionid' => $discussionid,
            'targetstate' => $targetstate,
        ]);

        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_id($params['digestforumid']);
        $coursemodule = $digestforum->get_course_module_record();
        $context = $digestforum->get_context();

        self::validate_context($context);

        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($params['discussionid']);
        $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();

        $digestforumrecord = $legacydatamapperfactory->get_digestforum_data_mapper()->to_legacy_object($digestforum);
        $discussionrecord = $legacydatamapperfactory->get_discussion_data_mapper()->to_legacy_object($discussion);

        if (!\mod_digestforum\subscriptions::is_subscribable($digestforumrecord)) {
            // Nothing to do. We won't actually output any content here though.
            throw new \moodle_exception('cannotsubscribe', 'mod_digestforum');
        }

        $issubscribed = \mod_digestforum\subscriptions::is_subscribed(
            $USER->id,
            $digestforumrecord,
            $discussion->get_id(),
            $coursemodule
        );

        // If the current state doesn't equal the desired state then update the current
        // state to the desired state.
        if ($issubscribed != (bool) $params['targetstate']) {
            if ($params['targetstate']) {
                \mod_digestforum\subscriptions::subscribe_user_to_discussion($USER->id, $discussionrecord, $context);
            } else {
                \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($USER->id, $discussionrecord, $context);
            }
        }

        $exporterfactory = mod_digestforum\local\container::get_exporter_factory();
        $exporter = $exporterfactory->get_discussion_exporter($USER, $digestforum, $discussion);
        return $exporter->export($PAGE->get_renderer('mod_digestforum'));
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function set_subscription_state_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'Forum that the discussion is in'),
                'discussionid' => new external_value(PARAM_INT, 'The discussion to subscribe or unsubscribe'),
                'targetstate' => new external_value(PARAM_BOOL, 'The target state'),
            ]
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return \core_external\external_description
     */
    public static function set_subscription_state_returns() {
        return discussion_exporter::get_read_structure();
    }

    /**
     * Set the lock state.
     *
     * @param   int     $digestforumid
     * @param   int     $discussionid
     * @param   string    $targetstate
     * @return  \stdClass
     */
    public static function set_lock_state($digestforumid, $discussionid, $targetstate) {
        global $DB, $PAGE, $USER;

        $params = self::validate_parameters(self::set_lock_state_parameters(), [
            'digestforumid' => $digestforumid,
            'discussionid' => $discussionid,
            'targetstate' => $targetstate,
        ]);

        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_id($params['digestforumid']);

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);
        if (!$capabilitymanager->can_manage_digestforum($USER)) {
            throw new moodle_exception('errorcannotlock', 'digestforum');
        }

        // If the targetstate(currentstate) is not 0 then it should be set to the current time.
        $lockedvalue = $targetstate ? 0 : time();
        self::validate_context($digestforum->get_context());

        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($params['discussionid']);

        // If the current state doesn't equal the desired state then update the current.
        // state to the desired state.
        $discussion->toggle_locked_state($lockedvalue);
        $response = $discussionvault->update_discussion($discussion);
        $discussion = !$response ? $response : $discussion;

        // Trigger an event.
        $params = [
            'context' => $digestforum->get_context(),
            'objectid' => $discussion->get_id(),
            'other' => ['digestforumid' => $digestforum->get_id(), 'status' => $lockedvalue ? 'locked' : 'unlocked'],
        ];
        $event = \mod_digestforum\event\discussion_lock_updated::create($params);
        $discussionrecord = $DB->get_record('digestforum_discussions', ['id' => $discussion->get_id()]);
        $event->add_record_snapshot('digestforum_discussions', $discussionrecord);
        $event->trigger();

        $exporterfactory = mod_digestforum\local\container::get_exporter_factory();
        $exporter = $exporterfactory->get_discussion_exporter($USER, $digestforum, $discussion);
        return $exporter->export($PAGE->get_renderer('mod_digestforum'));
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function set_lock_state_parameters() {
        return new external_function_parameters(
            [
                'digestforumid' => new external_value(PARAM_INT, 'Forum that the discussion is in'),
                'discussionid' => new external_value(PARAM_INT, 'The discussion to lock / unlock'),
                'targetstate' => new external_value(PARAM_INT, 'The timestamp for the lock state'),
            ]
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return \core_external\external_description
     */
    public static function set_lock_state_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The discussion we are locking.'),
            'locked' => new external_value(PARAM_BOOL, 'The locked state of the discussion.'),
            'times' => new external_single_structure([
                'locked' => new external_value(PARAM_INT, 'The locked time of the discussion.'),
            ]),
        ]);
    }

    /**
     * Set the pin state.
     *
     * @param   int     $discussionid
     * @param   bool    $targetstate
     * @return  \stdClass
     */
    public static function set_pin_state($discussionid, $targetstate) {
        global $PAGE, $USER;
        $params = self::validate_parameters(self::set_pin_state_parameters(), [
            'discussionid' => $discussionid,
            'targetstate' => $targetstate,
        ]);
        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($params['discussionid']);
        $digestforum = $digestforumvault->get_from_id($discussion->get_digestforum_id());
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);

        self::validate_context($digestforum->get_context());

        $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
        if (!$capabilitymanager->can_pin_discussions($USER)) {
            // Nothing to do. We won't actually output any content here though.
            throw new \moodle_exception('cannotpindiscussions', 'mod_digestforum');
        }

        $discussion->set_pinned($targetstate);
        $discussionvault->update_discussion($discussion);

        $exporterfactory = mod_digestforum\local\container::get_exporter_factory();
        $exporter = $exporterfactory->get_discussion_exporter($USER, $digestforum, $discussion);
        return $exporter->export($PAGE->get_renderer('mod_digestforum'));
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function set_pin_state_parameters() {
        return new external_function_parameters(
            [
                'discussionid' => new external_value(
                    PARAM_INT,
                    'The discussion to pin or unpin',
                    VALUE_REQUIRED,
                    null,
                    NULL_NOT_ALLOWED
                ),
                'targetstate' => new external_value(
                    PARAM_INT,
                    'The target state',
                    VALUE_REQUIRED,
                    null,
                    NULL_NOT_ALLOWED
                ),
            ]
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function set_pin_state_returns() {
        return discussion_exporter::get_read_structure();
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.8
     */
    public static function delete_post_parameters() {
        return new external_function_parameters(
            [
                'postid' => new external_value(PARAM_INT, 'Post to be deleted. It can be a discussion topic post.'),
            ]
        );
    }

    /**
     * Deletes a post or a discussion completely when the post is the discussion topic.
     *
     * @param int $postid post to be deleted, it can be a discussion topic post.
     * @return array of warnings and the status (true if the post/discussion was deleted)
     * @since Moodle 3.8
     * @throws moodle_exception
     */
    public static function delete_post($postid) {
        global $USER, $CFG;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::delete_post_parameters(),
            [
                'postid' => $postid,
            ]
        );
        $warnings = [];
        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $postvault = $vaultfactory->get_post_vault();
        $postentity = $postvault->get_from_id($params['postid']);

        if (empty($postentity)) {
            throw new moodle_exception('invalidpostid', 'digestforum');
        }

        $discussionentity = $discussionvault->get_from_id($postentity->get_discussion_id());

        if (empty($discussionentity)) {
            throw new moodle_exception('notpartofdiscussion', 'digestforum');
        }

        $digestforumentity = $digestforumvault->get_from_id($discussionentity->get_digestforum_id());
        if (empty($digestforumentity)) {
            throw new moodle_exception('invaliddigestforumid', 'digestforum');
        }

        $context = $digestforumentity->get_context();

        self::validate_context($context);

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforumentity);
        $digestforumdatamapper = $legacydatamapperfactory->get_digestforum_data_mapper();
        $discussiondatamapper = $legacydatamapperfactory->get_discussion_data_mapper();
        $postdatamapper = $legacydatamapperfactory->get_post_data_mapper();

        $replycount = $postvault->get_reply_count_for_post_id_in_discussion_id(
            $USER,
            $postentity->get_id(),
            $discussionentity->get_id(),
            true
        );
        $hasreplies = $replycount > 0;

        $capabilitymanager->validate_delete_post($USER, $discussionentity, $postentity, $hasreplies);

        if (!$postentity->has_parent()) {
            $status = digestforum_delete_discussion(
                $discussiondatamapper->to_legacy_object($discussionentity),
                false,
                $digestforumentity->get_course_record(),
                $digestforumentity->get_course_module_record(),
                $digestforumdatamapper->to_legacy_object($digestforumentity)
            );
        } else {
            $status = digestforum_delete_post(
                $postdatamapper->to_legacy_object($postentity),
                has_capability('mod/digestforum:deleteanypost', $context),
                $digestforumentity->get_course_record(),
                $digestforumentity->get_course_module_record(),
                $digestforumdatamapper->to_legacy_object($digestforumentity)
            );
        }

        $result = [];
        $result['status'] = $status;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.8
     */
    public static function delete_post_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'True if the post/discussion was deleted, false otherwise.'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Get the digestforum posts in the specified digestforum instance.
     *
     * @param   int $userid
     * @param   int $cmid
     * @param   string $sortby
     * @param   string $sortdirection
     * @return  array
     */
    public static function get_discussion_posts_by_userid(int $userid, int $cmid, ?string $sortby, ?string $sortdirection) {
        global $USER, $DB, $CFG;
        // Validate the parameter.
        $params = self::validate_parameters(self::get_discussion_posts_by_userid_parameters(), [
                'userid' => $userid,
                'cmid' => $cmid,
                'sortby' => $sortby,
                'sortdirection' => $sortdirection,
        ]);
        $warnings = [];

        $user = core_user::get_user($params['userid']);

        $vaultfactory = mod_digestforum\local\container::get_vault_factory();

        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $digestforum = $digestforumvault->get_from_course_module_id($params['cmid']);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        self::validate_context($digestforum->get_context());

        $sortby = $params['sortby'];
        $sortdirection = $params['sortdirection'];
        $sortallowedvalues = ['id', 'created', 'modified'];
        $directionallowedvalues = ['ASC', 'DESC'];

        if (!in_array(strtolower($sortby), $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                    'allowed values are: ' . implode(', ', $sortallowedvalues));
        }

        $sortdirection = strtoupper($sortdirection);
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                    'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforum);

        $discussionsummariesvault = $vaultfactory->get_discussions_in_digestforum_vault();
        $discussionsummaries = $discussionsummariesvault->get_from_digestforum_id(
            $digestforum->get_id(),
            true,
            null,
            $discussionsummariesvault::SORTORDER_CREATED_ASC,
            0,
            0
        );

        $postvault = $vaultfactory->get_post_vault();

        $builderfactory = mod_digestforum\local\container::get_builder_factory();
        $postbuilder = $builderfactory->get_exported_posts_builder();

        $builtdiscussions = [];
        foreach ($discussionsummaries as $discussionsummary) {
            $discussion = $discussionsummary->get_discussion();
            if (!$capabilitymanager->can_view_discussion($USER, $discussion)) {
                continue;
            }
            $posts = $postvault->get_posts_in_discussion_for_user_id(
                $discussion->get_id(),
                $user->id,
                $capabilitymanager->can_view_any_private_reply($USER),
                "{$sortby} {$sortdirection}"
            );
            if (empty($posts)) {
                continue;
            }

            $parentids = array_filter(array_map(function ($post) {
                return $post->has_parent() ? $post->get_parent_id() : null;
            }, $posts));

            $parentposts = [];
            if ($parentids) {
                $parentposts = $postbuilder->build(
                    $USER,
                    [$digestforum],
                    [$discussion],
                    $postvault->get_from_ids(array_values($parentids))
                );
            }

            $discussionauthor = $discussionsummary->get_first_post_author();
            $firstpost = $discussionsummary->get_first_post();

            $builtdiscussions[] = [
                'name' => $discussion->get_name(),
                'id' => $discussion->get_id(),
                'timecreated' => $firstpost->get_time_created(),
                'authorfullname' => $discussionauthor->get_full_name(),
                'posts' => [
                    'userposts' => $postbuilder->build($USER, [$digestforum], [$discussion], $posts),
                    'parentposts' => $parentposts,
                ],
            ];

            // Mark these posts as read.
            if (!empty($posts)) {
                if (!$CFG->digestforum_usermarksread && digestforum_tp_is_tracked($digestforum->get_id())) {
                    digestforum_tp_mark_posts_read($USER, array_keys($posts));
                }
            }
        }

        return [
                'discussions' => $builtdiscussions,
                'warnings' => $warnings,
        ];
    }

    /**
     * Describe the post parameters.
     *
     * @return external_function_parameters
     */
    public static function get_discussion_posts_by_userid_parameters() {
        return new external_function_parameters([
                'userid' => new external_value(
                    PARAM_INT,
                    'The ID of the user of whom to fetch posts.',
                    VALUE_REQUIRED
                ),
                'cmid' => new external_value(
                    PARAM_INT,
                    'The ID of the module of which to fetch items.',
                    VALUE_REQUIRED
                ),
                'sortby' => new external_value(
                    PARAM_ALPHA,
                    'Sort by this element: id, created or modified',
                    VALUE_DEFAULT,
                    'created'
                ),
                'sortdirection' => new external_value(
                    PARAM_ALPHA,
                    'Sort direction: ASC or DESC',
                    VALUE_DEFAULT,
                    'DESC'
                ),
        ]);
    }

    /**
     * Describe the post return format.
     *
     * @return external_single_structure
     */
    public static function get_discussion_posts_by_userid_returns() {
        return new external_single_structure([
                'discussions' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_RAW, 'Name of the discussion'),
                        'id' => new external_value(PARAM_INT, 'ID of the discussion'),
                        'timecreated' => new external_value(PARAM_INT, 'Timestamp of the discussion start'),
                        'authorfullname' => new external_value(PARAM_RAW, 'Full name of the user that started the discussion'),
                        'posts' => new external_single_structure([
                            'userposts' => new external_multiple_structure(\mod_digestforum\local\exporters\post::get_read_structure()),
                            'parentposts' => new external_multiple_structure(\mod_digestforum\local\exporters\post::get_read_structure()),
                        ]),
                    ])
                ),
                'warnings' => new external_warnings(),
        ]);
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.8
     */
    public static function get_discussion_post_parameters() {
        return new external_function_parameters(
            [
                'postid' => new external_value(PARAM_INT, 'Post to fetch.'),
            ]
        );
    }

    /**
     * Get a particular discussion post.
     *
     * @param int $postid post to fetch
     * @return array of post and warnings (if any)
     * @since Moodle 3.8
     * @throws moodle_exception
     */
    public static function get_discussion_post($postid) {
        global $USER, $CFG;

        $params = self::validate_parameters(
            self::get_discussion_post_parameters(),
            [
                                                'postid' => $postid,
            ]
        );
        $warnings = [];
        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $postvault = $vaultfactory->get_post_vault();

        $postentity = $postvault->get_from_id($params['postid']);
        if (empty($postentity)) {
            throw new moodle_exception('invalidpostid', 'digestforum');
        }
        $discussionentity = $discussionvault->get_from_id($postentity->get_discussion_id());
        if (empty($discussionentity)) {
            throw new moodle_exception('notpartofdiscussion', 'digestforum');
        }
        $digestforumentity = $digestforumvault->get_from_id($discussionentity->get_digestforum_id());
        if (empty($digestforumentity)) {
            throw new moodle_exception('invaliddigestforumid', 'digestforum');
        }
        self::validate_context($digestforumentity->get_context());

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforumentity);

        if (!$capabilitymanager->can_view_post($USER, $discussionentity, $postentity)) {
            throw new moodle_exception('noviewdiscussionspermission', 'digestforum');
        }

        $builderfactory = mod_digestforum\local\container::get_builder_factory();
        $postbuilder = $builderfactory->get_exported_posts_builder();
        $posts = $postbuilder->build($USER, [$digestforumentity], [$discussionentity], [$postentity]);
        $post = empty($posts) ? [] : reset($posts);

        $result = [];
        $result['post'] = $post;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.8
     */
    public static function get_discussion_post_returns() {
        return new external_single_structure(
            [
                'post' => \mod_digestforum\local\exporters\post::get_read_structure(),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.8
     */
    public static function prepare_draft_area_for_post_parameters() {
        return new external_function_parameters(
            [
                'postid' => new external_value(PARAM_INT, 'Post to prepare the draft area for.'),
                'area' => new external_value(PARAM_ALPHA, 'Area to prepare: attachment or post.'),
                'draftitemid' => new external_value(
                    PARAM_INT,
                    'The draft item id to use. 0 to generate one.',
                    VALUE_DEFAULT,
                    0
                ),
                'filestokeep' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'filename' => new external_value(PARAM_FILE, 'File name.'),
                            'filepath' => new external_value(PARAM_PATH, 'File path.'),
                        ]
                    ),
                    'Only keep these files in the draft file area. Empty for keeping all.',
                    VALUE_DEFAULT,
                    []
                ),
            ]
        );
    }

    /**
     * Prepares a draft area for editing a post.
     *
     * @param int $postid post to prepare the draft area for
     * @param string $area area to prepare attachment or post
     * @param int $draftitemid the draft item id to use. 0 to generate a new one.
     * @param array $filestokeep only keep these files in the draft file area. Empty for keeping all.
     * @return array of files in the area, the area options and the draft item id
     * @since Moodle 3.8
     * @throws moodle_exception
     */
    public static function prepare_draft_area_for_post($postid, $area, $draftitemid = 0, $filestokeep = []) {
        global $USER;

        $params = self::validate_parameters(
            self::prepare_draft_area_for_post_parameters(),
            [
                'postid' => $postid,
                'area' => $area,
                'draftitemid' => $draftitemid,
                'filestokeep' => $filestokeep,
            ]
        );
        $directionallowedvalues = ['ASC', 'DESC'];

        $allowedareas = ['attachment', 'post'];
        if (!in_array($params['area'], $allowedareas)) {
            throw new invalid_parameter_exception('Invalid value for area parameter
                (value: ' . $params['area'] . '),' . 'allowed values are: ' . implode(', ', $allowedareas));
        }

        $warnings = [];
        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $postvault = $vaultfactory->get_post_vault();

        $postentity = $postvault->get_from_id($params['postid']);
        if (empty($postentity)) {
            throw new moodle_exception('invalidpostid', 'digestforum');
        }
        $discussionentity = $discussionvault->get_from_id($postentity->get_discussion_id());
        if (empty($discussionentity)) {
            throw new moodle_exception('notpartofdiscussion', 'digestforum');
        }
        $digestforumentity = $digestforumvault->get_from_id($discussionentity->get_digestforum_id());
        if (empty($digestforumentity)) {
            throw new moodle_exception('invaliddigestforumid', 'digestforum');
        }

        $context = $digestforumentity->get_context();
        self::validate_context($context);

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $capabilitymanager = $managerfactory->get_capability_manager($digestforumentity);

        if (!$capabilitymanager->can_edit_post($USER, $discussionentity, $postentity)) {
            throw new moodle_exception('noviewdiscussionspermission', 'digestforum');
        }

        if ($params['area'] == 'attachment') {
            $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
            $digestforumdatamapper = $legacydatamapperfactory->get_digestforum_data_mapper();
            $digestforum = $digestforumdatamapper->to_legacy_object($digestforumentity);

            $areaoptions = mod_digestforum_post_form::attachment_options($digestforum);
            $messagetext = null;
        } else {
            $areaoptions = mod_digestforum_post_form::editor_options($context, $postentity->get_id());
            $messagetext = $postentity->get_message();
        }

        $draftitemid = empty($params['draftitemid']) ? 0 : $params['draftitemid'];
        $messagetext = file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'mod_digestforum',
            $params['area'],
            $postentity->get_id(),
            $areaoptions,
            $messagetext
        );

        // Just get a structure compatible with external API.
        array_walk($areaoptions, function (&$item, $key) {
            $item = ['name' => $key, 'value' => $item];
        });

        // Do we need to keep only the given files?
        $usercontext = context_user::instance($USER->id);
        if (!empty($params['filestokeep'])) {
            $fs = get_file_storage();

            if ($areafiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid)) {
                $filestokeep = [];
                foreach ($params['filestokeep'] as $ftokeep) {
                    $filestokeep[$ftokeep['filepath']][$ftokeep['filename']] = $ftokeep;
                }

                foreach ($areafiles as $file) {
                    if ($file->is_directory()) {
                        continue;
                    }
                    if (!isset($filestokeep[$file->get_filepath()][$file->get_filename()])) {
                        $file->delete();    // Not in the list to be kept.
                    }
                }
            }
        }

        $result = [
            'draftitemid' => $draftitemid,
            'files' => external_util::get_area_files(
                $usercontext->id,
                'user',
                'draft',
                $draftitemid
            ),
            'areaoptions' => $areaoptions,
            'messagetext' => $messagetext,
            'warnings' => $warnings,
        ];
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.8
     */
    public static function prepare_draft_area_for_post_returns() {
        return new external_single_structure(
            [
                'draftitemid' => new external_value(PARAM_INT, 'Draft item id for the file area.'),
                'files' => new external_files('Draft area files.', VALUE_OPTIONAL),
                'areaoptions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_RAW, 'Name of option.'),
                            'value' => new external_value(PARAM_RAW, 'Value of option.'),
                        ]
                    ),
                    'Draft file area options.'
                ),
                'messagetext' => new external_value(PARAM_RAW, 'Message text with URLs rewritten.'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.8
     */
    public static function update_discussion_post_parameters() {
        return new external_function_parameters(
            [
                'postid' => new external_value(PARAM_INT, 'Post to be updated. It can be a discussion topic post.'),
                'subject' => new external_value(PARAM_TEXT, 'Updated post subject', VALUE_DEFAULT, ''),
                'message' => new external_value(
                    PARAM_RAW,
                    'Updated post message (HTML assumed if messageformat is not provided)',
                    VALUE_DEFAULT,
                    ''
                ),
                'messageformat' => new external_format_value('message', VALUE_DEFAULT),
                'options' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(
                                PARAM_ALPHANUM,
                                'The allowed keys (value format) are:
                                pinned (bool); (only for discussions) whether to pin this discussion or not
                                discussionsubscribe (bool); whether to subscribe to the post or not
                                inlineattachmentsid (int); the draft file area id for inline attachments in the text
                                attachmentsid (int); the draft file area id for attachments'
                            ),
                            'value' => new external_value(PARAM_RAW, 'The value of the option.'),
                        ]
                    ),
                    'Configuration options for the post.',
                    VALUE_DEFAULT,
                    []
                ),
            ]
        );
    }

    /**
     * Updates a post or a discussion post topic.
     *
     * @param int $postid post to be updated, it can be a discussion topic post.
     * @param string $subject updated post subject
     * @param string $message updated post message (HTML assumed if messageformat is not provided)
     * @param int $messageformat The format of the message, defaults to FORMAT_HTML
     * @param array $options different configuration options for the post to be updated.
     * @return array of warnings and the status (true if the post/discussion was deleted)
     * @since Moodle 3.8
     * @throws moodle_exception
     * @todo support more options: timed posts, groups change and tags.
     */
    public static function update_discussion_post(
        $postid,
        $subject = '',
        $message = '',
        $messageformat = FORMAT_HTML,
        $options = []
    ) {
        global $CFG, $USER;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::add_discussion_post_parameters(),
            [
                'postid' => $postid,
                'subject' => $subject,
                'message' => $message,
                'options' => $options,
                'messageformat' => $messageformat,
            ]
        );
        $warnings = [];

        // Validate options.
        $options = [];
        foreach ($params['options'] as $option) {
            $name = trim($option['name']);
            switch ($name) {
                case 'pinned':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'discussionsubscribe':
                    $value = clean_param($option['value'], PARAM_BOOL);
                    break;
                case 'inlineattachmentsid':
                    $value = clean_param($option['value'], PARAM_INT);
                    break;
                case 'attachmentsid':
                    $value = clean_param($option['value'], PARAM_INT);
                    break;
                default:
                    throw new moodle_exception('errorinvalidparam', 'webservice', '', $name);
            }
            $options[$name] = $value;
        }

        $managerfactory = mod_digestforum\local\container::get_manager_factory();
        $vaultfactory = mod_digestforum\local\container::get_vault_factory();
        $digestforumvault = $vaultfactory->get_digestforum_vault();
        $discussionvault = $vaultfactory->get_discussion_vault();
        $postvault = $vaultfactory->get_post_vault();
        $legacydatamapperfactory = mod_digestforum\local\container::get_legacy_data_mapper_factory();
        $digestforumdatamapper = $legacydatamapperfactory->get_digestforum_data_mapper();
        $discussiondatamapper = $legacydatamapperfactory->get_discussion_data_mapper();
        $postdatamapper = $legacydatamapperfactory->get_post_data_mapper();

        $postentity = $postvault->get_from_id($params['postid']);
        if (empty($postentity)) {
            throw new moodle_exception('invalidpostid', 'digestforum');
        }
        $discussionentity = $discussionvault->get_from_id($postentity->get_discussion_id());
        if (empty($discussionentity)) {
            throw new moodle_exception('notpartofdiscussion', 'digestforum');
        }
        $digestforumentity = $digestforumvault->get_from_id($discussionentity->get_digestforum_id());
        if (empty($digestforumentity)) {
            throw new moodle_exception('invaliddigestforumid', 'digestforum');
        }
        $digestforum = $digestforumdatamapper->to_legacy_object($digestforumentity);
        $capabilitymanager = $managerfactory->get_capability_manager($digestforumentity);

        $modcontext = $digestforumentity->get_context();
        self::validate_context($modcontext);

        if (!$capabilitymanager->can_edit_post($USER, $discussionentity, $postentity)) {
            throw new moodle_exception('cannotupdatepost', 'digestforum');
        }

        // Get the original post.
        $updatepost = $postdatamapper->to_legacy_object($postentity);
        $updatepost->itemid = IGNORE_FILE_MERGE;
        $updatepost->attachments = IGNORE_FILE_MERGE;

        // Prepare the post to be updated.
        if ($params['subject'] !== '') {
            $updatepost->subject = $params['subject'];
        }

        if ($params['message'] !== '' && isset($params['messageformat'])) {
            $updatepost->message       = $params['message'];
            $updatepost->messageformat = $params['messageformat'];
            $updatepost->messagetrust  = trusttext_trusted($modcontext);
            // Clean message text.
            $updatepost = trusttext_pre_edit($updatepost, 'message', $modcontext);
        }

        if (isset($options['discussionsubscribe'])) {
            // No need to validate anything here, digestforum_post_subscription will do.
            $updatepost->discussionsubscribe = $options['discussionsubscribe'];
        }

        // When editing first post/discussion.
        if (!$postentity->has_parent()) {
            // Defaults for discussion topic posts.
            $updatepost->name = $discussionentity->get_name();
            $updatepost->timestart = $discussionentity->get_time_start();
            $updatepost->timeend = $discussionentity->get_time_end();

            if (isset($options['pinned'])) {
                if ($capabilitymanager->can_pin_discussions($USER)) {
                    // Can change pinned if we have capability.
                    $updatepost->pinned = !empty($options['pinned']) ? DFORUM_DISCUSSION_PINNED : DFORUM_DISCUSSION_UNPINNED;
                }
            }
        }

        if (isset($options['inlineattachmentsid'])) {
            $updatepost->itemid = $options['inlineattachmentsid'];
        }

        if (isset($options['attachmentsid']) && digestforum_can_create_attachment($digestforum, $modcontext)) {
            $updatepost->attachments = $options['attachmentsid'];
        }

        // Update the post.
        $fakemform = $updatepost->id;
        if (digestforum_update_post($updatepost, $fakemform)) {
            $discussion = $discussiondatamapper->to_legacy_object($discussionentity);

            digestforum_trigger_post_updated_event($updatepost, $discussion, $modcontext, $digestforum);

            digestforum_post_subscription(
                $updatepost,
                $digestforum,
                $discussion
            );
            $status = true;
        } else {
            $status = false;
        }

        return [
            'status' => $status,
            'warnings' => $warnings,
        ];
    }

    /**
     * Returns description of method result value
     *
     * @return \core_external\external_description
     * @since Moodle 3.8
     */
    public static function update_discussion_post_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'True if the post/discussion was updated, false otherwise.'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Returns description of mark_posts_read parameters.
     *
     * @return external_function_parameters
     */
    public static function mark_posts_read_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'The post ID to mark as read'),
                'List of post IDs to mark as read',
            ),
            'discussionid' => new external_value(PARAM_INT, 'The discussion the posts belong to'),
        ]);
    }

    /**
     * Trigger the posts viewed event.
     *
     * This method differs from view_digestforum_discussion in that it is only
     * interested in marking certain posts as viewed, and not an
     * entire discussion.
     *
     * @param array $postids The post ids
     * @param int $discussionid The discussion id
     * @return bool True on success
     */
    public static function mark_posts_read(array $postids, int $discussionid): bool {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(
            self::mark_posts_read_parameters(),
            [
                'postids' => $postids,
                'discussionid' => $discussionid,
            ]
        );

        // Check permissions.
        $discussion = $DB->get_record('digestforum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('digestforum', $discussion->digestforum, $discussion->course, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        require_capability('mod/digestforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'digestforum');

        // Check the posts belong to the discussion.
        [$insql, $params] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED);
        $params['discussionid'] = $discussionid;
        $validposts = $DB->get_records_select(
            'digestforum_posts',
            "id $insql AND discussion = :discussionid",
            $params,
            '',
            'id'
        );

        // Keep only valid posts.
        $postids = array_intersect($postids, array_keys($validposts));
        if (empty($postids)) {
            return false;
        }

        // Mark as read if required.
        $digestforum = $DB->get_record('digestforum', ['id' => $discussion->digestforum], '*', MUST_EXIST);
        if (!$CFG->digestforum_usermarksread && digestforum_tp_is_tracked($digestforum)) {
            return digestforum_tp_mark_posts_read($USER, $postids);
        }

        return false;
    }

    /**
     * Describes the data returned from mark_posts_read.
     */
    public static function mark_posts_read_returns(): external_value {
        return new external_value(PARAM_BOOL, 'Returns true on success');
    }
}
