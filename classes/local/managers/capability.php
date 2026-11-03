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
 * Capability manager for the digestforum.
 *
 * @package    mod_digestforum
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_digestforum\local\managers;

defined('MOODLE_INTERNAL') || die();

use mod_digestforum\local\data_mappers\legacy\digestforum as legacy_digestforum_data_mapper;
use mod_digestforum\local\data_mappers\legacy\discussion as legacy_discussion_data_mapper;
use mod_digestforum\local\data_mappers\legacy\post as legacy_post_data_mapper;
use mod_digestforum\local\entities\discussion as discussion_entity;
use mod_digestforum\local\entities\digestforum as digestforum_entity;
use mod_digestforum\local\entities\post as post_entity;
use mod_digestforum\subscriptions;
use context;
use context_system;
use stdClass;
use moodle_exception;

require_once($CFG->dirroot . '/mod/digestforum/lib.php');

/**
 * Capability manager for the digestforum.
 *
 * Defines all the business rules for what a user can and can't do in the digestforum.
 *
 * @copyright  2019 Ryan Wyllie <ryan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capability {
    /** @var legacy_digestforum_data_mapper $digestforumdatamapper Legacy digestforum data mapper */
    private $digestforumdatamapper;
    /** @var legacy_discussion_data_mapper $discussiondatamapper Legacy discussion data mapper */
    private $discussiondatamapper;
    /** @var legacy_post_data_mapper $postdatamapper Legacy post data mapper */
    private $postdatamapper;
    /** @var digestforum_entity $digestforum Forum entity */
    private $digestforum;
    /** @var stdClass $digestforumrecord Legacy digestforum record */
    private $digestforumrecord;
    /** @var context $context Module context for the digestforum */
    private $context;
    /** @var array $canviewpostcache Cache of discussion posts that can be viewed by a user. */
    protected $canviewpostcache = [];

    /**
     * Constructor.
     *
     * @param digestforum_entity $digestforum The digestforum entity to manage capabilities for.
     * @param legacy_digestforum_data_mapper $digestforumdatamapper Legacy digestforum data mapper
     * @param legacy_discussion_data_mapper $discussiondatamapper Legacy discussion data mapper
     * @param legacy_post_data_mapper $postdatamapper Legacy post data mapper
     */
    public function __construct(
        digestforum_entity $digestforum,
        legacy_digestforum_data_mapper $digestforumdatamapper,
        legacy_discussion_data_mapper $discussiondatamapper,
        legacy_post_data_mapper $postdatamapper
    ) {
        $this->digestforumdatamapper = $digestforumdatamapper;
        $this->discussiondatamapper = $discussiondatamapper;
        $this->postdatamapper = $postdatamapper;
        $this->digestforum = $digestforum;
        $this->digestforumrecord = $digestforumdatamapper->to_legacy_object($digestforum);
        $this->context = $digestforum->get_context();
    }

    /**
     * Can the user subscribe to this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_subscribe_to_digestforum(stdClass $user): bool {
        if ($this->digestforum->get_type() == 'single') {
            return false;
        }

        return !is_guest($this->get_context(), $user) &&
            subscriptions::is_subscribable($this->get_digestforum_record());
    }

    /**
     * Can the user create discussions in this digestforum?
     *
     * @param stdClass $user The user to check
     * @param int|null $groupid The current activity group id
     * @return bool
     */
    public function can_create_discussions(stdClass $user, ?int $groupid = null): bool {
        if (isguestuser($user) or !isloggedin()) {
            return false;
        }

        if ($this->digestforum->is_cutoff_date_reached()) {
            if (!has_capability('mod/digestforum:canoverridecutoff', $this->get_context())) {
                return false;
            }
        }

        // If the user reaches the number of posts equal to warning/blocking setting then return the value of canpost in $warningobj.
        $cmrecord = $this->digestforum->get_course_module_record();
        if ($warningobj = digestforum_check_throttling($this->digestforumrecord, $cmrecord)) {
            return $warningobj->canpost;
        }

        switch ($this->digestforum->get_type()) {
            case 'news':
                $capability = 'mod/digestforum:addnews';
                break;
            case 'qanda':
                $capability = 'mod/digestforum:addquestion';
                break;
            default:
                $capability = 'mod/digestforum:startdiscussion';
        }

        if (!has_capability($capability, $this->digestforum->get_context(), $user)) {
            return false;
        }

        if ($this->digestforum->get_type() == 'eachuser') {
            if (digestforum_user_has_posted_discussion($this->digestforum->get_id(), $user->id, $groupid)) {
                return false;
            }
        }

        if ($this->digestforum->is_in_group_mode()) {
            return $groupid ? $this->can_access_group($user, $groupid) : $this->can_access_all_groups($user);
        } else {
            return true;
        }
    }

    /**
     * Can the user access all groups?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_access_all_groups(stdClass $user): bool {
        return has_capability('moodle/site:accessallgroups', $this->get_context(), $user);
    }

    /**
     * Can the user access the given group?
     *
     * @param stdClass $user The user to check
     * @param int $groupid The id of the group that the digestforum is set to
     * @return bool
     */
    public function can_access_group(stdClass $user, int $groupid): bool {
        if ($this->can_access_all_groups($user)) {
            // This user has access to all groups.
            return true;
        }

        // This is a group discussion for a digestforum in separate groups mode.
        // Check if the user is a member.
        // This is the most expensive check.
        return groups_is_member($groupid, $user->id);
    }

    /**
     * Can the user post to their groups?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_post_to_my_groups(stdClass $user): bool {
        return has_capability('mod/digestforum:canposttomygroups', $this->get_context(), $user);
    }

    /**
     * Can the user view discussions in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_view_discussions(stdClass $user): bool {
        return has_capability('mod/digestforum:viewdiscussion', $this->get_context(), $user);
    }

    /**
     * Can the user move discussions in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_move_discussions(stdClass $user): bool {
        $digestforum = $this->get_digestforum();
        return $digestforum->get_type() !== 'single' &&
                has_capability('mod/digestforum:movediscussions', $this->get_context(), $user);
    }

    /**
     * Can the user pin discussions in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_pin_discussions(stdClass $user): bool {
        return $this->digestforum->get_type() !== 'single' &&
                has_capability('mod/digestforum:pindiscussions', $this->get_context(), $user);
    }

    /**
     * Can the user split discussions in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_split_discussions(stdClass $user): bool {
        $digestforum = $this->get_digestforum();
        return $digestforum->get_type() !== 'single' && has_capability('mod/digestforum:splitdiscussions', $this->get_context(), $user);
    }

    /**
     * Can the user export (see portfolios) discussions in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_export_discussions(stdClass $user): bool {
        global $CFG;
        return $CFG->enableportfolios && has_capability('mod/digestforum:exportdiscussion', $this->get_context(), $user);
    }

    /**
     * Can the user manually mark posts as read/unread in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_manually_control_post_read_status(stdClass $user): bool {
        global $CFG;
        return $CFG->digestforum_usermarksread && isloggedin() && digestforum_tp_is_tracked($this->get_digestforum_record(), $user);
    }

    /**
     * Is the user required to post in the discussion before they can view it?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function must_post_before_viewing_discussion(stdClass $user, discussion_entity $discussion): bool {
        $digestforum = $this->get_digestforum();

        if ($digestforum->get_type() === 'qanda') {
            // If it's a Q and A digestforum then the user must either have the capability to view without
            // posting or the user must have posted before they can view the discussion.
            return !has_capability('mod/digestforum:viewqandawithoutposting', $this->get_context(), $user) &&
                !digestforum_user_has_posted($digestforum->get_id(), $discussion->get_id(), $user->id);
        } else {
            // No other digestforum types require posting before viewing.
            return false;
        }
    }

    /**
     * Can the user subscribe to the give discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function can_subscribe_to_discussion(stdClass $user, discussion_entity $discussion): bool {
        return $this->can_subscribe_to_digestforum($user);
    }

    /**
     * Can the user move the discussion in this digestforum?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function can_move_discussion(stdClass $user, discussion_entity $discussion): bool {
        return $this->can_move_discussions($user);
    }

    /**
     * Is the user pin the discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function can_pin_discussion(stdClass $user, discussion_entity $discussion): bool {
        return $this->can_pin_discussions($user);
    }

    /**
     * Can the user post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function can_post_in_discussion(stdClass $user, discussion_entity $discussion): bool {
        $digestforum = $this->get_digestforum();
        $digestforumrecord = $this->get_digestforum_record();

        $discussionrecord = $this->get_discussion_record($discussion);
        $context = $this->get_context();
        $coursemodule = $digestforum->get_course_module_record();
        $course = $digestforum->get_course_record();

        $status = digestforum_user_can_post($digestforumrecord, $discussionrecord, $user, $coursemodule, $course, $context);

        // If the user reaches the number of posts equal to warning/blocking setting then logically and canpost value with $status.
        if ($warningobj = digestforum_check_throttling($digestforumrecord, $coursemodule)) {
            return $status && $warningobj->canpost;
        }
        return $status;
    }

    /**
     * Can the user favourite the discussion
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_favourite_discussion(stdClass $user): bool {
        $context = $this->get_context();
        return has_capability('mod/digestforum:cantogglefavourite', $context, $user);
    }

    /**
     * Can the user view the content of a discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function can_view_discussion(stdClass $user, discussion_entity $discussion): bool {
        $digestforumrecord = $this->get_digestforum_record();
        $discussionrecord = $this->get_discussion_record($discussion);
        $context = $this->get_context();

        return digestforum_user_can_see_discussion($digestforumrecord, $discussionrecord, $context, $user);
    }

    /**
     * Can the user view the content of the post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @param post_entity $post The post the user wants to view
     * @return bool
     */
    public function can_view_post(stdClass $user, discussion_entity $discussion, post_entity $post): bool {
        if (!$this->can_view_post_shell($user, $post)) {
            return false;
        }

        // Return cached can view if possible.
        if (isset($this->canviewpostcache[$user->id][$post->get_id()])) {
            return $this->canviewpostcache[$user->id][$post->get_id()];
        }

        // Otherwise, check if the user can see this post.
        $digestforum = $this->get_digestforum();
        $digestforumrecord = $this->get_digestforum_record();
        $discussionrecord = $this->get_discussion_record($discussion);
        $postrecord = $this->get_post_record($post);
        $coursemodule = $digestforum->get_course_module_record();
        $canviewpost = digestforum_user_can_see_post($digestforumrecord, $discussionrecord, $postrecord, $user, $coursemodule, false);

        // Then cache the result before returning.
        $this->canviewpostcache[$user->id][$post->get_id()] = $canviewpost;

        return $canviewpost;
    }

    /**
     * Can the user view the post at all?
     * In some situations the user can view the shell of a post without being able to view its content.
     *
     * @param   stdClass $user The user to check
     * @param   post_entity $post The post the user wants to view
     * @return  bool
     *
     */
    public function can_view_post_shell(stdClass $user, post_entity $post): bool {
        if ($post->is_owned_by_user($user)) {
            return true;
        }

        if (!$post->is_private_reply()) {
            return true;
        }

        if ($post->is_private_reply_intended_for_user($user)) {
            return true;
        }

        return $this->can_view_any_private_reply($user);
    }

    /**
     * Whether the user can view any private reply in the digestforum.
     *
     * @param   stdClass $user The user to check
     * @return  bool
     */
    public function can_view_any_private_reply(stdClass $user): bool {
        return has_capability('mod/digestforum:readprivatereplies', $this->get_context(), $user);
    }

    /**
     * Can the user edit the post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @param post_entity $post The post the user wants to edit
     * @return bool
     */
    public function can_edit_post(stdClass $user, discussion_entity $discussion, post_entity $post): bool {
        global $CFG;

        $context = $this->get_context();
        $ownpost = $post->is_owned_by_user($user);
        $ineditingtime = $post->get_age() < $CFG->maxeditingtime;
        $mailnow = $post->should_mail_now();

        switch ($this->digestforum->get_type()) {
            case 'news':
                // Allow editing of news posts once the discussion has started.
                $ineditingtime = !$post->has_parent() && $discussion->has_started();
                break;
            case 'single':
                if ($discussion->is_first_post($post)) {
                    return has_capability('moodle/course:manageactivities', $context, $user);
                }
                break;
        }

        return ($ownpost && $ineditingtime && !$mailnow) || has_capability('mod/digestforum:editanypost', $context, $user);
    }

    /**
     * Verifies is the given user can delete a post.
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @param post_entity $post The post the user wants to delete
     * @param bool $hasreplies Whether the post has replies
     * @return bool
     * @throws moodle_exception
     */
    public function validate_delete_post(stdClass $user, discussion_entity $discussion, post_entity $post,
            bool $hasreplies = false): void {
        global $CFG;

        $digestforum = $this->get_digestforum();

        if ($digestforum->get_type() == 'single' && $discussion->is_first_post($post)) {
            // Do not allow deleting of first post in single simple type.
            throw new moodle_exception('cannotdeletepost', 'digestforum');
        }

        $context = $this->get_context();
        $ownpost = $post->is_owned_by_user($user);
        $ineditingtime = $post->get_age() < $CFG->maxeditingtime;
        $mailnow = $post->should_mail_now();

        if (!($ownpost && $ineditingtime && has_capability('mod/digestforum:deleteownpost', $context, $user) && !$mailnow ||
                has_capability('mod/digestforum:deleteanypost', $context, $user))) {

            throw new moodle_exception('cannotdeletepost', 'digestforum');
        }

        if ($post->get_total_score()) {
            throw new moodle_exception('couldnotdeleteratings', 'rating');
        }

        if ($hasreplies && !has_capability('mod/digestforum:deleteanypost', $context, $user)) {
            throw new moodle_exception('couldnotdeletereplies', 'digestforum');
        }
    }


    /**
     * Can the user delete the post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @param post_entity $post The post the user wants to delete
     * @param bool $hasreplies Whether the post has replies
     * @return bool
     */
    public function can_delete_post(stdClass $user, discussion_entity $discussion, post_entity $post,
            bool $hasreplies = false): bool {

        try {
            $this->validate_delete_post($user, $discussion, $post, $hasreplies);
            return true;
        } catch (moodle_exception $e) {
            return false;
        }
    }

    /**
     * Can the user split the post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @param post_entity $post The post the user wants to split
     * @return bool
     */
    public function can_split_post(stdClass $user, discussion_entity $discussion, post_entity $post): bool {
        if ($post->is_private_reply()) {
            // It is not possible to create a private discussion.
            return false;
        }

        return $this->can_split_discussions($user) && $post->has_parent();
    }

    /**
     * Can the user reply to the post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @param post_entity $post The post the user wants to reply to
     * @return bool
     */
    public function can_reply_to_post(stdClass $user, discussion_entity $discussion, post_entity $post): bool {
        if ($post->is_private_reply()) {
            // It is not possible to reply to a private reply.
            return false;
        } else if (!$this->can_view_post($user, $discussion, $post)) {
            // If the user cannot view the post in the first place, the user should not be able to reply to the post.
            return false;
        }

        return $this->can_post_in_discussion($user, $discussion);
    }

    /**
     * Can the user reply privately to the specified post?
     *
     * @param stdClass $user The user to check
     * @param post_entity $post The post the user wants to reply to
     * @return bool
     */
    public function can_reply_privately_to_post(stdClass $user, post_entity $post): bool {
        if ($post->is_private_reply()) {
            // You cannot reply privately to a post which is, itself, a private reply.
            return false;
        }

        return has_capability('mod/digestforum:postprivatereply', $this->get_context(), $user);
    }

    /**
     * Can the user export (see portfolios) the post in this discussion?
     *
     * @param stdClass $user The user to check
     * @param post_entity $post The post the user wants to export
     * @return bool
     */
    public function can_export_post(stdClass $user, post_entity $post): bool {
        global $CFG;
        $context = $this->get_context();
        return $CFG->enableportfolios  && (has_capability('mod/digestforum:exportpost', $context, $user) ||
            ($post->is_owned_by_user($user) && has_capability('mod/digestforum:exportownpost', $context, $user)));
    }

    /**
     * Get the digestforum entity for this capability manager.
     *
     * @return digestforum_entity
     */
    protected function get_digestforum(): digestforum_entity {
        return $this->digestforum;
    }

    /**
     * Get the legacy digestforum record for this digestforum.
     *
     * @return stdClass
     */
    protected function get_digestforum_record(): stdClass {
        return $this->digestforumrecord;
    }

    /**
     * Get the context for this capability manager.
     *
     * @return context
     */
    protected function get_context(): context {
        return $this->context;
    }

    /**
     * Get the legacy discussion record for the given discussion entity.
     *
     * @param discussion_entity $discussion The discussion to convert
     * @return stdClass
     */
    protected function get_discussion_record(discussion_entity $discussion): stdClass {
        return $this->discussiondatamapper->to_legacy_object($discussion);
    }

    /**
     * Get the legacy post record for the given post entity.
     *
     * @param post_entity $post The post to convert
     * @return stdClass
     */
    protected function get_post_record(post_entity $post): stdClass {
        return $this->postdatamapper->to_legacy_object($post);
    }

    /**
     * Can the user view the participants of this discussion?
     *
     * @param stdClass $user The user to check
     * @param discussion_entity $discussion The discussion to check
     * @return bool
     */
    public function can_view_participants(stdClass $user, discussion_entity $discussion): bool {
        return course_can_view_participants($this->get_context()) &&
            !$this->must_post_before_viewing_discussion($user, $discussion);
    }

    /**
     * Can the user view hidden posts in this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_view_hidden_posts(stdClass $user): bool {
        return has_capability('mod/digestforum:viewhiddentimedposts', $this->get_context(), $user);
    }

    /**
     * Can the user manage this digestforum?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_manage_digestforum(stdClass $user) {
        return has_capability('moodle/course:manageactivities', $this->get_context(), $user);
    }

    /**
     * Can the user manage tags on the site?
     *
     * @param stdClass $user The user to check
     * @return bool
     */
    public function can_manage_tags(stdClass $user): bool {
        return has_capability('moodle/tag:manage', context_system::instance(), $user);
    }

    /**
     * Checks whether the user can self enrol into the course.
     * Mimics the checks on the add button in deprecatedlib/digestforum_print_latest_discussions
     *
     * @param stdClass $user
     * @return bool
     */
    public function can_self_enrol(stdClass $user): bool {
        $canstart = false;

        if ($this->digestforum->get_type() != 'news') {
            if (isguestuser($user) or !isloggedin()) {
                $canstart = true;
            }

            if (!is_enrolled($this->context) and !is_viewing($this->context)) {
                 // Allow guests and not-logged-in to see the button - they are prompted to log in after clicking the link,
                 // Normal users with temporary guest access see this button too, they are asked to enrol instead,
                 // Do not show the button to users with suspended enrolments here.
                $canstart = enrol_selfenrol_available($this->digestforum->get_course_id());
            }
        }

        return $canstart;
    }

    /**
     * Checks whether the user can export the whole digestforum (discussions and posts).
     *
     * @param stdClass $user The user object.
     * @return bool True if the user can export the digestforum or false otherwise.
     */
    public function can_export_digestforum(stdClass $user): bool {
        return has_capability('mod/digestforum:exportdigestforum', $this->get_context(), $user);
    }

    /**
     * Check whether the supplied grader can grade the gradee.
     *
     * @param stdClass $grader The user grading
     * @param stdClass $gradee The user being graded
     * @return bool
     */
    public function can_grade(stdClass $grader, ?stdClass $gradee = null): bool {
        if (!has_capability('mod/digestforum:grade', $this->get_context(), $grader)) {
            return false;
        }

        return true;
    }
}
