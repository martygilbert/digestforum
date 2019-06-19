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
 * External digestforum API
 *
 * @package    mod_digestforum
 * @copyright  2012 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

class mod_digestforum_external extends external_api {

    /**
     * Describes the parameters for get_digestforum.
     *
     * @return external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_digestforums_by_courses_parameters() {
        return new external_function_parameters (
            array(
                'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'course ID',
                        VALUE_REQUIRED, '', NULL_NOT_ALLOWED), 'Array of Course IDs', VALUE_DEFAULT, array()),
            )
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
    public static function get_digestforums_by_courses($courseids = array()) {
        global $CFG;

        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(self::get_digestforums_by_courses_parameters(), array('courseids' => $courseids));

        $courses = array();
        if (empty($params['courseids'])) {
            $courses = enrol_get_my_courses();
            $params['courseids'] = array_keys($courses);
        }

        // Array to store the digestforums to return.
        $arrdigestforums = array();
        $warnings = array();

        // Ensure there are courseids to loop through.
        if (!empty($params['courseids'])) {

            list($courses, $warnings) = external_util::validate_courses($params['courseids'], $courses);

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

                $digestforum->name = external_format_string($digestforum->name, $context->id);
                // Format the intro before being returning using the format setting.
                list($digestforum->intro, $digestforum->introformat) = external_format_text($digestforum->intro, $digestforum->introformat,
                                                                                $context->id, 'mod_digestforum', 'intro', null);
                $digestforum->introfiles = external_util::get_area_files($context->id, 'mod_digestforum', 'intro', false, false);
                // Discussions count. This function does static request cache.
                $digestforum->numdiscussions = digestforum_count_discussions($digestforum, $cm, $course);
                $digestforum->cmid = $digestforum->coursemodule;
                $digestforum->cancreatediscussions = digestforum_user_can_post_discussion($digestforum, null, -1, $cm, $context);
                $digestforum->istracked = digestforum_tp_is_tracked($digestforum);
                if ($digestforum->istracked) {
                    $digestforum->unreadpostscount = digestforum_tp_count_digestforum_unread_posts($cm, $course);
                }

                // Add the digestforum to the array to return.
                $arrdigestforums[$digestforum->id] = $digestforum;
            }
        }

        return $arrdigestforums;
    }

    /**
     * Describes the get_digestforum return value.
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
    public static function get_digestforums_by_courses_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'course' => new external_value(PARAM_INT, 'Course id'),
                    'type' => new external_value(PARAM_TEXT, 'The digestforum type'),
                    'name' => new external_value(PARAM_RAW, 'Forum name'),
                    'intro' => new external_value(PARAM_RAW, 'The digestforum intro'),
                    'introformat' => new external_format_value('intro'),
                    'introfiles' => new external_files('Files in the introduction text', VALUE_OPTIONAL),
                    'assessed' => new external_value(PARAM_INT, 'Aggregate type'),
                    'assesstimestart' => new external_value(PARAM_INT, 'Assess start time'),
                    'assesstimefinish' => new external_value(PARAM_INT, 'Assess finish time'),
                    'scale' => new external_value(PARAM_INT, 'Scale'),
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
                    'cancreatediscussions' => new external_value(PARAM_BOOL, 'If the user can create discussions', VALUE_OPTIONAL),
                    'lockdiscussionafter' => new external_value(PARAM_INT, 'After what period a discussion is locked', VALUE_OPTIONAL),
                    'istracked' => new external_value(PARAM_BOOL, 'If the user is tracking the digestforum', VALUE_OPTIONAL),
                    'unreadpostscount' => new external_value(PARAM_INT, 'The number of unread posts for tracked digestforums',
                        VALUE_OPTIONAL),
                ), 'digestforum'
            )
        );
    }

    /**
     * Describes the parameters for get_digestforum_discussion_posts.
     *
     * @return external_function_parameters
     * @since Moodle 2.7
     */
    public static function get_digestforum_discussion_posts_parameters() {
        return new external_function_parameters (
            array(
                'discussionid' => new external_value(PARAM_INT, 'discussion ID', VALUE_REQUIRED),
                'sortby' => new external_value(PARAM_ALPHA,
                    'sort by this element: id, created or modified', VALUE_DEFAULT, 'created'),
                'sortdirection' => new external_value(PARAM_ALPHA, 'sort direction: ASC or DESC', VALUE_DEFAULT, 'DESC')
            )
        );
    }

    /**
     * Returns a list of digestforum posts for a discussion
     *
     * @param int $discussionid the post ids
     * @param string $sortby sort by this element (id, created or modified)
     * @param string $sortdirection sort direction: ASC or DESC
     *
     * @return array the digestforum post details
     * @since Moodle 2.7
     */
    public static function get_digestforum_discussion_posts($discussionid, $sortby = "created", $sortdirection = "DESC") {
        global $CFG, $DB, $USER, $PAGE;

        $posts = array();
        $warnings = array();

        // Validate the parameter.
        $params = self::validate_parameters(self::get_digestforum_discussion_posts_parameters(),
            array(
                'discussionid' => $discussionid,
                'sortby' => $sortby,
                'sortdirection' => $sortdirection));

        // Compact/extract functions are not recommended.
        $discussionid   = $params['discussionid'];
        $sortby         = $params['sortby'];
        $sortdirection  = $params['sortdirection'];

        $sortallowedvalues = array('id', 'created', 'modified');
        if (!in_array($sortby, $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                'allowed values are: ' . implode(',', $sortallowedvalues));
        }

        $sortdirection = strtoupper($sortdirection);
        $directionallowedvalues = array('ASC', 'DESC');
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        $discussion = $DB->get_record('digestforum_discussions', array('id' => $discussionid), '*', MUST_EXIST);
        $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $digestforum->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        // This require must be here, see mod/digestforum/discuss.php.
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        // Check they have the view digestforum capability.
        require_capability('mod/digestforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'digestforum');

        if (! $post = digestforum_get_post_full($discussion->firstpost)) {
            throw new moodle_exception('notexists', 'digestforum');
        }

        // This function check groups, qanda, timed discussions, etc.
        if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
            throw new moodle_exception('noviewdiscussionspermission', 'digestforum');
        }

        $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);

        // We will add this field in the response.
        $canreply = digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $modcontext);

        $digestforumtracked = digestforum_tp_is_tracked($digestforum);

        $sort = 'p.' . $sortby . ' ' . $sortdirection;
        $allposts = digestforum_get_all_discussion_posts($discussion->id, $sort, $digestforumtracked);

        foreach ($allposts as $post) {
            if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm, false)) {
                $warning = array();
                $warning['item'] = 'post';
                $warning['itemid'] = $post->id;
                $warning['warningcode'] = '1';
                $warning['message'] = 'You can\'t see this post';
                $warnings[] = $warning;
                continue;
            }

            // Function digestforum_get_all_discussion_posts adds postread field.
            // Note that the value returned can be a boolean or an integer. The WS expects a boolean.
            if (empty($post->postread)) {
                $post->postread = false;
            } else {
                $post->postread = true;
            }

            $post->canreply = $canreply;
            if (!empty($post->children)) {
                $post->children = array_keys($post->children);
            } else {
                $post->children = array();
            }

            if (!digestforum_user_can_see_post($digestforum, $discussion, $post, null, $cm)) {
                // The post is available, but has been marked as deleted.
                // It will still be available but filled with a placeholder.
                $post->userid = null;
                $post->userfullname = null;
                $post->userpictureurl = null;

                $post->subject = get_string('privacy:request:delete:post:subject', 'mod_digestforum');
                $post->message = get_string('privacy:request:delete:post:message', 'mod_digestforum');

                $post->deleted = true;
                $posts[] = $post;

                continue;
            }
            $post->deleted = false;

            if (digestforum_is_author_hidden($post, $digestforum)) {
                $post->userid = null;
                $post->userfullname = null;
                $post->userpictureurl = null;
            } else {
                $user = new stdclass();
                $user->id = $post->userid;
                $user = username_load_fields_from_object($user, $post, null, array('picture', 'imagealt', 'email'));
                $post->userfullname = fullname($user, $canviewfullname);

                $userpicture = new user_picture($user);
                $userpicture->size = 1; // Size f1.
                $post->userpictureurl = $userpicture->get_url($PAGE)->out(false);
            }

            $post->subject = external_format_string($post->subject, $modcontext->id);
            // Rewrite embedded images URLs.
            list($post->message, $post->messageformat) =
                external_format_text($post->message, $post->messageformat, $modcontext->id, 'mod_digestforum', 'post', $post->id);

            // List attachments.
            if (!empty($post->attachment)) {
                $post->attachments = external_util::get_area_files($modcontext->id, 'mod_digestforum', 'attachment', $post->id);
            }
            $messageinlinefiles = external_util::get_area_files($modcontext->id, 'mod_digestforum', 'post', $post->id);
            if (!empty($messageinlinefiles)) {
                $post->messageinlinefiles = $messageinlinefiles;
            }

            $posts[] = $post;
        }

        $result = array();
        $result['posts'] = $posts;
        $result['ratinginfo'] = \core_rating\external\util::get_rating_info($digestforum, $modcontext, 'mod_digestforum', 'post', $posts);
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_digestforum_discussion_posts return value.
     *
     * @return external_single_structure
     * @since Moodle 2.7
     */
    public static function get_digestforum_discussion_posts_returns() {
        return new external_single_structure(
            array(
                'posts' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'id' => new external_value(PARAM_INT, 'Post id'),
                                'discussion' => new external_value(PARAM_INT, 'Discussion id'),
                                'parent' => new external_value(PARAM_INT, 'Parent id'),
                                'userid' => new external_value(PARAM_INT, 'User id'),
                                'created' => new external_value(PARAM_INT, 'Creation time'),
                                'modified' => new external_value(PARAM_INT, 'Time modified'),
                                'mailed' => new external_value(PARAM_INT, 'Mailed?'),
                                'subject' => new external_value(PARAM_TEXT, 'The post subject'),
                                'message' => new external_value(PARAM_RAW, 'The post message'),
                                'messageformat' => new external_format_value('message'),
                                'messagetrust' => new external_value(PARAM_INT, 'Can we trust?'),
                                'messageinlinefiles' => new external_files('post message inline files', VALUE_OPTIONAL),
                                'attachment' => new external_value(PARAM_RAW, 'Has attachments?'),
                                'attachments' => new external_files('attachments', VALUE_OPTIONAL),
                                'totalscore' => new external_value(PARAM_INT, 'The post message total score'),
                                'mailnow' => new external_value(PARAM_INT, 'Mail now?'),
                                'children' => new external_multiple_structure(new external_value(PARAM_INT, 'children post id')),
                                'canreply' => new external_value(PARAM_BOOL, 'The user can reply to posts?'),
                                'postread' => new external_value(PARAM_BOOL, 'The post was read'),
                                'userfullname' => new external_value(PARAM_TEXT, 'Post author full name'),
                                'userpictureurl' => new external_value(PARAM_URL, 'Post author picture.', VALUE_OPTIONAL),
                                'deleted' => new external_value(PARAM_BOOL, 'This post has been removed.'),
                            ), 'post'
                        )
                    ),
                'ratinginfo' => \core_rating\external\util::external_ratings_structure(),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Describes the parameters for get_digestforum_discussions_paginated.
     *
     * @return external_function_parameters
     * @since Moodle 2.8
     */
    public static function get_digestforum_discussions_paginated_parameters() {
        return new external_function_parameters (
            array(
                'digestforumid' => new external_value(PARAM_INT, 'digestforum instance id', VALUE_REQUIRED),
                'sortby' => new external_value(PARAM_ALPHA,
                    'sort by this element: id, timemodified, timestart or timeend', VALUE_DEFAULT, 'timemodified'),
                'sortdirection' => new external_value(PARAM_ALPHA, 'sort direction: ASC or DESC', VALUE_DEFAULT, 'DESC'),
                'page' => new external_value(PARAM_INT, 'current page', VALUE_DEFAULT, -1),
                'perpage' => new external_value(PARAM_INT, 'items per page', VALUE_DEFAULT, 0),
            )
        );
    }

    /**
     * Returns a list of digestforum discussions optionally sorted and paginated.
     *
     * @param int $digestforumid the digestforum instance id
     * @param string $sortby sort by this element (id, timemodified, timestart or timeend)
     * @param string $sortdirection sort direction: ASC or DESC
     * @param int $page page number
     * @param int $perpage items per page
     *
     * @return array the digestforum discussion details including warnings
     * @since Moodle 2.8
     */
    public static function get_digestforum_discussions_paginated($digestforumid, $sortby = 'timemodified', $sortdirection = 'DESC',
                                                    $page = -1, $perpage = 0) {
        global $CFG, $DB, $USER, $PAGE;

        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $warnings = array();
        $discussions = array();

        $params = self::validate_parameters(self::get_digestforum_discussions_paginated_parameters(),
            array(
                'digestforumid' => $digestforumid,
                'sortby' => $sortby,
                'sortdirection' => $sortdirection,
                'page' => $page,
                'perpage' => $perpage
            )
        );

        // Compact/extract functions are not recommended.
        $digestforumid        = $params['digestforumid'];
        $sortby         = $params['sortby'];
        $sortdirection  = $params['sortdirection'];
        $page           = $params['page'];
        $perpage        = $params['perpage'];

        $sortallowedvalues = array('id', 'timemodified', 'timestart', 'timeend');
        if (!in_array($sortby, $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                'allowed values are: ' . implode(',', $sortallowedvalues));
        }

        $sortdirection = strtoupper($sortdirection);
        $directionallowedvalues = array('ASC', 'DESC');
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        $digestforum = $DB->get_record('digestforum', array('id' => $digestforumid), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $digestforum->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id, $course->id, false, MUST_EXIST);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        // Check they have the view digestforum capability.
        require_capability('mod/digestforum:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'digestforum');

        $sort = 'd.pinned DESC, d.' . $sortby . ' ' . $sortdirection;
        $alldiscussions = digestforum_get_discussions($cm, $sort, true, -1, -1, true, $page, $perpage, DFORUM_POSTS_ALL_USER_GROUPS);

        if ($alldiscussions) {
            $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);

            // Get the unreads array, this takes a digestforum id and returns data for all discussions.
            $unreads = array();
            if ($cantrack = digestforum_tp_can_track_digestforums($digestforum)) {
                if ($digestforumtracked = digestforum_tp_is_tracked($digestforum)) {
                    $unreads = digestforum_get_discussions_unread($cm);
                }
            }
            // The digestforum function returns the replies for all the discussions in a given digestforum.
            $replies = digestforum_count_discussion_replies($digestforumid, $sort, -1, $page, $perpage);

            foreach ($alldiscussions as $discussion) {

                // This function checks for qanda digestforums.
                // Note that the digestforum_get_discussions returns as id the post id, not the discussion id so we need to do this.
                $discussionrec = clone $discussion;
                $discussionrec->id = $discussion->discussion;
                if (!digestforum_user_can_see_discussion($digestforum, $discussionrec, $modcontext)) {
                    $warning = array();
                    // Function digestforum_get_discussions returns digestforum_posts ids not digestforum_discussions ones.
                    $warning['item'] = 'post';
                    $warning['itemid'] = $discussion->id;
                    $warning['warningcode'] = '1';
                    $warning['message'] = 'You can\'t see this discussion';
                    $warnings[] = $warning;
                    continue;
                }

                $discussion->numunread = 0;
                if ($cantrack && $digestforumtracked) {
                    if (isset($unreads[$discussion->discussion])) {
                        $discussion->numunread = (int) $unreads[$discussion->discussion];
                    }
                }

                $discussion->numreplies = 0;
                if (!empty($replies[$discussion->discussion])) {
                    $discussion->numreplies = (int) $replies[$discussion->discussion]->replies;
                }

                $discussion->name = external_format_string($discussion->name, $modcontext->id);
                $discussion->subject = external_format_string($discussion->subject, $modcontext->id);
                // Rewrite embedded images URLs.
                list($discussion->message, $discussion->messageformat) =
                    external_format_text($discussion->message, $discussion->messageformat,
                                            $modcontext->id, 'mod_digestforum', 'post', $discussion->id);

                // List attachments.
                if (!empty($discussion->attachment)) {
                    $discussion->attachments = external_util::get_area_files($modcontext->id, 'mod_digestforum', 'attachment',
                                                                                $discussion->id);
                }
                $messageinlinefiles = external_util::get_area_files($modcontext->id, 'mod_digestforum', 'post', $discussion->id);
                if (!empty($messageinlinefiles)) {
                    $discussion->messageinlinefiles = $messageinlinefiles;
                }

                $discussion->locked = digestforum_discussion_is_locked($digestforum, $discussion);
                $discussion->canreply = digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $modcontext);

                if (digestforum_is_author_hidden($discussion, $digestforum)) {
                    $discussion->userid = null;
                    $discussion->userfullname = null;
                    $discussion->userpictureurl = null;

                    $discussion->usermodified = null;
                    $discussion->usermodifiedfullname = null;
                    $discussion->usermodifiedpictureurl = null;
                } else {
                    $picturefields = explode(',', user_picture::fields());

                    // Load user objects from the results of the query.
                    $user = new stdclass();
                    $user->id = $discussion->userid;
                    $user = username_load_fields_from_object($user, $discussion, null, $picturefields);
                    // Preserve the id, it can be modified by username_load_fields_from_object.
                    $user->id = $discussion->userid;
                    $discussion->userfullname = fullname($user, $canviewfullname);

                    $userpicture = new user_picture($user);
                    $userpicture->size = 1; // Size f1.
                    $discussion->userpictureurl = $userpicture->get_url($PAGE)->out(false);

                    $usermodified = new stdclass();
                    $usermodified->id = $discussion->usermodified;
                    $usermodified = username_load_fields_from_object($usermodified, $discussion, 'um', $picturefields);
                    // Preserve the id (it can be overwritten due to the prefixed $picturefields).
                    $usermodified->id = $discussion->usermodified;
                    $discussion->usermodifiedfullname = fullname($usermodified, $canviewfullname);

                    $userpicture = new user_picture($usermodified);
                    $userpicture->size = 1; // Size f1.
                    $discussion->usermodifiedpictureurl = $userpicture->get_url($PAGE)->out(false);
                }

                $discussions[] = $discussion;
            }
        }

        $result = array();
        $result['discussions'] = $discussions;
        $result['warnings'] = $warnings;
        return $result;

    }

    /**
     * Describes the get_digestforum_discussions_paginated return value.
     *
     * @return external_single_structure
     * @since Moodle 2.8
     */
    public static function get_digestforum_discussions_paginated_returns() {
        return new external_single_structure(
            array(
                'discussions' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'id' => new external_value(PARAM_INT, 'Post id'),
                                'name' => new external_value(PARAM_TEXT, 'Discussion name'),
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
                                'subject' => new external_value(PARAM_TEXT, 'The post subject'),
                                'message' => new external_value(PARAM_RAW, 'The post message'),
                                'messageformat' => new external_format_value('message'),
                                'messagetrust' => new external_value(PARAM_INT, 'Can we trust?'),
                                'messageinlinefiles' => new external_files('post message inline files', VALUE_OPTIONAL),
                                'attachment' => new external_value(PARAM_RAW, 'Has attachments?'),
                                'attachments' => new external_files('attachments', VALUE_OPTIONAL),
                                'totalscore' => new external_value(PARAM_INT, 'The post message total score'),
                                'mailnow' => new external_value(PARAM_INT, 'Mail now?'),
                                'userfullname' => new external_value(PARAM_TEXT, 'Post author full name'),
                                'usermodifiedfullname' => new external_value(PARAM_TEXT, 'Post modifier full name'),
                                'userpictureurl' => new external_value(PARAM_URL, 'Post author picture.'),
                                'usermodifiedpictureurl' => new external_value(PARAM_URL, 'Post modifier picture.'),
                                'numreplies' => new external_value(PARAM_TEXT, 'The number of replies in the discussion'),
                                'numunread' => new external_value(PARAM_INT, 'The number of unread discussions.'),
                                'pinned' => new external_value(PARAM_BOOL, 'Is the discussion pinned'),
                                'locked' => new external_value(PARAM_BOOL, 'Is the discussion locked'),
                                'canreply' => new external_value(PARAM_BOOL, 'Can the user reply to the discussion'),
                            ), 'post'
                        )
                    ),
                'warnings' => new external_warnings()
            )
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
            array(
                'digestforumid' => new external_value(PARAM_INT, 'digestforum instance id')
            )
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

        $params = self::validate_parameters(self::view_digestforum_parameters(),
                                            array(
                                                'digestforumid' => $digestforumid
                                            ));
        $warnings = array();

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', array('id' => $params['digestforumid']), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        require_capability('mod/digestforum:viewdiscussion', $context, null, true, 'noviewdiscussionspermission', 'digestforum');

        // Call the digestforum/lib API.
        digestforum_view($digestforum, $course, $cm, $context);

        $result = array();
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function view_digestforum_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings()
            )
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
            array(
                'discussionid' => new external_value(PARAM_INT, 'discussion id')
            )
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

        $params = self::validate_parameters(self::view_digestforum_discussion_parameters(),
                                            array(
                                                'discussionid' => $discussionid
                                            ));
        $warnings = array();

        $discussion = $DB->get_record('digestforum_discussions', array('id' => $params['discussionid']), '*', MUST_EXIST);
        $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($digestforum, 'digestforum');

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

        $result = array();
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function view_digestforum_discussion_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings()
            )
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
            array(
                'postid' => new external_value(PARAM_INT, 'the post id we are going to reply to
                                                (can be the initial discussion post'),
                'subject' => new external_value(PARAM_TEXT, 'new post subject'),
                'message' => new external_value(PARAM_RAW, 'new post message (only html format allowed)'),
                'options' => new external_multiple_structure (
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUM,
                                        'The allowed keys (value format) are:
                                        discussionsubscribe (bool); subscribe to the discussion?, default to true
                                        inlineattachmentsid              (int); the draft file area id for inline attachments
                                        attachmentsid       (int); the draft file area id for attachments
                            '),
                            'value' => new external_value(PARAM_RAW, 'the value of the option,
                                                            this param is validated in the external function.'
                        )
                    )
                ), 'Options', VALUE_DEFAULT, array())
            )
        );
    }

    /**
     * Create new posts into an existing discussion.
     *
     * @param int $postid the post id we are going to reply to
     * @param string $subject new post subject
     * @param string $message new post message (only html format allowed)
     * @param array $options optional settings
     * @return array of warnings and the new post id
     * @since Moodle 3.0
     * @throws moodle_exception
     */
    public static function add_discussion_post($postid, $subject, $message, $options = array()) {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(self::add_discussion_post_parameters(),
            array(
                'postid' => $postid,
                'subject' => $subject,
                'message' => $message,
                'options' => $options
            )
        );
        $warnings = array();

        if (!$parent = digestforum_get_post_full($params['postid'])) {
            throw new moodle_exception('invalidparentpostid', 'digestforum');
        }

        if (!$discussion = $DB->get_record("digestforum_discussions", array("id" => $parent->discussion))) {
            throw new moodle_exception('notpartofdiscussion', 'digestforum');
        }

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', array('id' => $discussion->digestforum), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Validate options.
        $options = array(
            'discussionsubscribe' => true,
            'inlineattachmentsid' => 0,
            'attachmentsid' => null
        );
        foreach ($params['options'] as $option) {
            $name = trim($option['name']);
            switch ($name) {
                case 'discussionsubscribe':
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

        if (!digestforum_user_can_post($digestforum, $discussion, $USER, $cm, $course, $context)) {
            throw new moodle_exception('nopostdigestforum', 'digestforum');
        }

        $thresholdwarning = digestforum_check_throttling($digestforum, $cm);
        digestforum_check_blocking_threshold($thresholdwarning);

        // Create the post.
        $post = new stdClass();
        $post->discussion = $discussion->id;
        $post->parent = $parent->id;
        $post->subject = $params['subject'];
        $post->message = $params['message'];
        $post->messageformat = FORMAT_HTML;   // Force formatting for now.
        $post->messagetrust = trusttext_trusted($context);
        $post->itemid = $options['inlineattachmentsid'];
        $post->attachments = $options['attachmentsid'];
        $post->deleted = 0;
        $fakemform = $post->attachments;
        if ($postid = digestforum_add_new_post($post, $fakemform)) {

            $post->id = $postid;

            // Trigger events and completion.
            $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array(
                    'discussionid' => $discussion->id,
                    'digestforumid' => $digestforum->id,
                    'digestforumtype' => $digestforum->type,
                )
            );
            $event = \mod_digestforum\event\post_created::create($params);
            $event->add_record_snapshot('digestforum_posts', $post);
            $event->add_record_snapshot('digestforum_discussions', $discussion);
            $event->trigger();

            // Update completion state.
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) &&
                    ($digestforum->completionreplies || $digestforum->completionposts)) {
                $completion->update_state($cm, COMPLETION_COMPLETE);
            }

            $settings = new stdClass();
            $settings->discussionsubscribe = $options['discussionsubscribe'];
            digestforum_post_subscription($settings, $digestforum, $discussion);
        } else {
            throw new moodle_exception('couldnotadd', 'digestforum');
        }

        $result = array();
        $result['postid'] = $postid;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function add_discussion_post_returns() {
        return new external_single_structure(
            array(
                'postid' => new external_value(PARAM_INT, 'new post id'),
                'warnings' => new external_warnings()
            )
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
            array(
                'digestforumid' => new external_value(PARAM_INT, 'Forum instance ID'),
                'subject' => new external_value(PARAM_TEXT, 'New Discussion subject'),
                'message' => new external_value(PARAM_RAW, 'New Discussion message (only html format allowed)'),
                'groupid' => new external_value(PARAM_INT, 'The group, default to 0', VALUE_DEFAULT, 0),
                'options' => new external_multiple_structure (
                    new external_single_structure(
                        array(
                            'name' => new external_value(PARAM_ALPHANUM,
                                        'The allowed keys (value format) are:
                                        discussionsubscribe (bool); subscribe to the discussion?, default to true
                                        discussionpinned    (bool); is the discussion pinned, default to false
                                        inlineattachmentsid              (int); the draft file area id for inline attachments
                                        attachmentsid       (int); the draft file area id for attachments
                            '),
                            'value' => new external_value(PARAM_RAW, 'The value of the option,
                                                            This param is validated in the external function.'
                        )
                    )
                ), 'Options', VALUE_DEFAULT, array())
            )
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
    public static function add_discussion($digestforumid, $subject, $message, $groupid = 0, $options = array()) {
        global $DB, $CFG;
        require_once($CFG->dirroot . "/mod/digestforum/lib.php");

        $params = self::validate_parameters(self::add_discussion_parameters(),
                                            array(
                                                'digestforumid' => $digestforumid,
                                                'subject' => $subject,
                                                'message' => $message,
                                                'groupid' => $groupid,
                                                'options' => $options
                                            ));

        $warnings = array();

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', array('id' => $params['digestforumid']), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Validate options.
        $options = array(
            'discussionsubscribe' => true,
            'discussionpinned' => false,
            'inlineattachmentsid' => 0,
            'attachmentsid' => null
        );
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

            $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array(
                    'digestforumid' => $digestforum->id,
                )
            );
            $event = \mod_digestforum\event\discussion_created::create($params);
            $event->add_record_snapshot('digestforum_discussions', $discussion);
            $event->trigger();

            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) &&
                    ($digestforum->completiondiscussions || $digestforum->completionposts)) {
                $completion->update_state($cm, COMPLETION_COMPLETE);
            }

            $settings = new stdClass();
            $settings->discussionsubscribe = $options['discussionsubscribe'];
            digestforum_post_subscription($settings, $digestforum, $discussion);
        } else {
            throw new moodle_exception('couldnotadd', 'digestforum');
        }

        $result = array();
        $result['discussionid'] = $discussionid;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function add_discussion_returns() {
        return new external_single_structure(
            array(
                'discussionid' => new external_value(PARAM_INT, 'New Discussion ID'),
                'warnings' => new external_warnings()
            )
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
            array(
                'digestforumid' => new external_value(PARAM_INT, 'Forum instance ID'),
                'groupid' => new external_value(PARAM_INT, 'The group to check, default to active group.
                                                Use -1 to check if the user can post in all the groups.', VALUE_DEFAULT, null)
            )
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

        $params = self::validate_parameters(self::can_add_discussion_parameters(),
                                            array(
                                                'digestforumid' => $digestforumid,
                                                'groupid' => $groupid,
                                            ));
        $warnings = array();

        // Request and permission validation.
        $digestforum = $DB->get_record('digestforum', array('id' => $params['digestforumid']), '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($digestforum, 'digestforum');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $status = digestforum_user_can_post_discussion($digestforum, $params['groupid'], -1, $cm, $context);

        $result = array();
        $result['status'] = $status;
        $result['canpindiscussions'] = has_capability('mod/digestforum:pindiscussions', $context);
        $result['cancreateattachment'] = digestforum_can_create_attachment($digestforum, $context);
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.1
     */
    public static function can_add_discussion_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'True if the user can add discussions, false otherwise.'),
                'canpindiscussions' => new external_value(PARAM_BOOL, 'True if the user can pin discussions, false otherwise.',
                    VALUE_OPTIONAL),
                'cancreateattachment' => new external_value(PARAM_BOOL, 'True if the user can add attachments, false otherwise.',
                    VALUE_OPTIONAL),
                'warnings' => new external_warnings()
            )
        );
    }

}
