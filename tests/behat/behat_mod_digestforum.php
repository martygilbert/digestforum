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
 * Steps definitions related with the digestforum activity.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2013 David Monllaó
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;

/**
 * Forum-related steps definitions.
 *
 * @package    mod_digestforum
 * @category   test
 * @copyright  2013 David Monllaó
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_digestforum extends behat_base {
    /**
     * Return the list of exact named selectors.
     *
     * @return behat_component_named_selector[]
     */
    public static function get_exact_named_selectors(): array {
        return [
            new behat_component_named_selector(
                'Discussion navigation link',
                [
                    ".//nav[contains(concat(' ', normalize-space(@class), ' '), ' discussion-nav ')]" .
                    "//*[self::a or self::span][@aria-label=%locator% or @data-type=%locator%]",
                ]
            ),
        ];
    }

    /**
     * Reset digestforum caches between tests.
     *
     * @BeforeScenario @mod_digestforum
     */
    public function reset_digestforum_caches(): void {
        \mod_digestforum\subscriptions::reset_discussion_cache();
        \mod_digestforum\subscriptions::reset_digestforum_cache();
    }

    /**
     * Adds a topic to the digestforum specified by it's name. Useful for the Announcements and blog-style digestforums.
     *
     * @Given /^I add a new topic to "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $digestforumname
     * @param TableNode $table
     */
    public function i_add_a_new_topic_to_digestforum_with($digestforumname, TableNode $table) {
        $this->add_new_discussion($digestforumname, $table, get_string('addanewdiscussion', 'digestforum'));
    }

    /**
     * Adds a Q&A discussion to the Q&A-type digestforum specified by it's name with the provided table data.
     *
     * @Given /^I add a new question to "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $digestforumname
     * @param TableNode $table
     */
    public function i_add_a_new_question_to_digestforum_with($digestforumname, TableNode $table) {
        $this->add_new_discussion($digestforumname, $table, get_string('addanewdiscussion', 'digestforum'));
    }

    /**
     * Adds a discussion to the digestforum specified by it's name with the provided table data (usually Subject and Message).
     * The step begins from the digestforum's course page.
     *
     * @Given /^I add a new discussion to "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $digestforumname
     * @param TableNode $table
     */
    public function i_add_a_digestforum_discussion_to_digestforum_with($digestforumname, TableNode $table) {
        $this->add_new_discussion($digestforumname, $table, get_string('addanewdiscussion', 'digestforum'));
    }

    /**
     * Adds a discussion to the digestforum specified by it's name with the provided table data (usually Subject and Message).
     * The step begins from the digestforum's course page.
     *
     * @Given /^I add a new discussion to "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum inline with:$/
     * @param string $digestforumname
     * @param TableNode $table
     */
    public function i_add_a_digestforum_discussion_to_digestforum_inline_with($digestforumname, TableNode $table) {
        $this->add_new_discussion_inline($digestforumname, $table, get_string('addanewdiscussion', 'digestforum'));
    }

    // phpcs:disable moodle.Files.LineLength
    /**
     * Adds a reply to the specified post of the specified digestforum.
     * The step begins from the digestforum's page or from the digestforum's course page.
     *
     * @Given /^I reply "(?P<post_subject_string>(?:[^"]|\\")*)" post from "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum with:$/
     * @param string $postname The subject of the post
     * @param string $digestforumname The digestforum name
     * @param TableNode $table
     */
    public function i_reply_post_from_digestforum_with($postsubject, $digestforumname, TableNode $table) {

        // Navigate to digestforum.
        $this->goto_main_post_reply($postsubject);

        // Fill form and post.
        $this->execute('behat_forms::i_set_the_following_fields_to_these_values', $table);

        $this->execute('behat_forms::press_button', get_string('posttodigestforum', 'digestforum'));
        $this->execute('behat_general::i_wait_to_be_redirected');
    }

    /**
     * Inpage Reply - adds a reply to the specified post of the specified digestforum.
     * The step begins from the digestforum's page or from the digestforum's course page.
     *
     * @Given /^I reply "(?P<post_subject_string>(?:[^"]|\\")*)" post from "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum using an inpage reply with:$/
     * @param string $postsubject The subject of the post
     * @param string $digestforumname The digestforum name
     * @param TableNode $table
     */
    public function i_reply_post_from_digestforum_using_an_inpage_reply_with($postsubject, $digestforumname, TableNode $table) {
        // Navigate to digestforum.
        $this->execute('behat_navigation::i_am_on_page_instance', [$this->escape($digestforumname), 'digestforum activity']);
        $this->execute('behat_general::click_link', $this->escape($postsubject));
        $this->execute('behat_general::click_link', get_string('reply', 'digestforum'));

        // Fill form and post.
        $this->execute('behat_forms::i_set_the_following_fields_to_these_values', $table);

        $this->execute('behat_forms::press_button', get_string('posttodigestforum', 'mod_digestforum'));
    }

    /**
     * Navigates to a particular discussion page
     *
     * @Given /^I navigate to post "(?P<post_subject_string>(?:[^"]|\\")*)" in "(?P<digestforum_name_string>(?:[^"]|\\")*)" digestforum$/
     * @param string $postsubject The subject of the post
     * @param string $digestforumname The digestforum name
     */
    public function i_navigate_to_post_in_digestforum($postsubject, $digestforumname) {
        // phpcs:enable moodle.Files.LineLength
        // Navigate to digestforum discussion.
        $this->execute('behat_navigation::i_am_on_page_instance', [$this->escape($digestforumname), 'digestforum activity']);
        $this->execute('behat_general::click_link', $this->escape($postsubject));
    }

    /**
     * Opens up the action menu for the discussion
     *
     * @Given /^I click on "(?P<post_subject_string>(?:[^"]|\\")*)" action menu$/
     * @param string $discussion The subject of the discussion
     */
    public function i_click_on_action_menu($discussion) {
        $this->execute('behat_general::i_click_on_in_the', [
            "[data-container='discussion-tools'] [data-bs-toggle='dropdown']", "css_element",
            "//tr[contains(concat(' ', normalize-space(@class), ' '), ' discussion ') and contains(.,'$discussion')]",
            "xpath_element",
        ]);
    }

    /**
     * Creates new discussions within digestforums of a given course.
     *
     * @Given the following digestforum discussions exist in course :coursename:
     * @param string $coursename The full name of the course where the digestforums exist.
     * @param TableNode $discussionsdata The discussion posts to be created.
     */
    public function the_following_digestforum_discussions_exist(string $coursename, TableNode $discussionsdata) {
        global $DB;

        $courseid = $this->get_course_id($coursename);
        $digestforumgenerator = behat_util::get_data_generator()->get_plugin_generator('mod_digestforum');

        // Add the discussions to the relevant digestforum.
        foreach ($discussionsdata->getHash() as $discussioninfo) {
            $discussioninfo['course'] = $courseid;
            $discussioninfo['digestforum'] = $this->get_digestforum_id($courseid, $discussioninfo['digestforum']);
            $discussioninfo['userid'] = $this->get_user_id($discussioninfo['user']);

            // Prepare data for any attachments.
            if (!empty($discussioninfo['attachments']) || !empty($discussioninfo['inlineattachments'])) {
                $discussioninfo['attachment'] = 1;
                $cm = get_coursemodule_from_instance('digestforum', $discussioninfo['digestforum']);
            }

            // Prepare data for groups if needed.
            if (!empty($discussioninfo['group'])) {
                $discussioninfo['groupid'] = $this->get_group_id($courseid, $discussioninfo['group']);
                unset($discussioninfo['group']);
            }

            // Create the discussion post.
            $discussion = $digestforumgenerator->create_discussion($discussioninfo);
            $postid = $DB->get_field('digestforum_posts', 'id', ['discussion' => $discussion->id]);

            // Override the creation and modified timestamps as required.
            if (!empty($discussioninfo['created']) || !empty($discussioninfo['modified'])) {
                $discussiondata = [
                    'id' => $discussion->id,
                    'timemodified' => empty($discussioninfo['modified']) ? $discussioninfo['created'] : $discussioninfo['modified'],
                ];

                $DB->update_record('digestforum_discussions', $discussiondata);

                $postdata = [
                    'id' => $postid,
                    'modified' => empty($discussioninfo['modified']) ? $discussioninfo['created'] : $discussioninfo['modified'],
                ];

                if (!empty($discussioninfo['created'])) {
                    $postdata['created'] = $discussioninfo['created'];
                }

                $DB->update_record('digestforum_posts', $postdata);
            }

            // Create attachments to the discussion post if required.
            if (!empty($discussioninfo['attachments'])) {
                $attachments = array_map('trim', explode(',', $discussioninfo['attachments']));
                $this->create_post_attachments($postid, $discussioninfo['userid'], $attachments, $cm, 'attachment');
            }

            // Create inline attachments to the discussion post if required.
            if (!empty($discussioninfo['inlineattachments'])) {
                $inlineattachments = array_map('trim', explode(',', $discussioninfo['inlineattachments']));
                $this->create_post_attachments($postid, $discussioninfo['userid'], $inlineattachments, $cm, 'post');
            }
        }
    }

    /**
     * Creates replies to discussions within digestforums of a given course.
     *
     * @Given the following digestforum replies exist in course :coursename:
     * @param string $coursename The full name of the course where the digestforums exist.
     * @param TableNode $repliesdata The reply posts to be created.
     */
    public function the_following_digestforum_replies_exist(string $coursename, TableNode $repliesdata) {
        global $DB;

        $courseid = $this->get_course_id($coursename);
        $digestforumgenerator = behat_util::get_data_generator()->get_plugin_generator('mod_digestforum');

        // Add the replies to the relevant discussions.
        foreach ($repliesdata->getHash() as $replyinfo) {
            $replyinfo['course'] = $courseid;
            $replyinfo['digestforum'] = $this->get_digestforum_id($courseid, $replyinfo['digestforum']);
            $replyinfo['userid'] = $this->get_user_id($replyinfo['user']);

            [
                'discussionid' => $replyinfo['discussion'],
                'parentid' => $replyinfo['parent'],
            ] = $this->get_base_discussion($replyinfo['digestforum'], $replyinfo['discussion']);

            // Prepare data for any attachments.
            if (!empty($replyinfo['attachments']) || !empty($replyinfo['inlineattachments'])) {
                $replyinfo['attachment'] = 1;
                $cm = get_coursemodule_from_instance('digestforum', $replyinfo['digestforum']);
            }

            // Get the user id of the user to whom the reply is private.
            if (!empty($replyinfo['privatereplyto'])) {
                $replyinfo['privatereplyto'] = $this->get_user_id($replyinfo['privatereplyto']);
            }

            // Create the reply post.
            $reply = $digestforumgenerator->create_post($replyinfo);

            // Create attachments to the post if required.
            if (!empty($replyinfo['attachments'])) {
                $attachments = array_map('trim', explode(',', $replyinfo['attachments']));
                $this->create_post_attachments($reply->id, $replyinfo['userid'], $attachments, $cm, 'attachment');
            }

            // Create inline attachments to the post if required.
            if (!empty($replyinfo['inlineattachments'])) {
                $inlineattachments = array_map('trim', explode(',', $replyinfo['inlineattachments']));
                $this->create_post_attachments($reply->id, $replyinfo['userid'], $inlineattachments, $cm, 'post');
            }
        }
    }

    /**
     * Checks if the user can subscribe to the digestforum.
     *
     * @Given /^I can subscribe to this digestforum$/
     */
    public function i_can_subscribe_to_this_digestforum() {
        $this->execute('behat_forms::the_field_matches_value', [get_string('subscribe', 'mod_digestforum'), '0']);
    }

    /**
     * Checks if the user can unsubscribe from the digestforum.
     *
     * @Given /^I can unsubscribe from this digestforum$/
     */
    public function i_can_unsubscribe_from_this_digestforum() {
        $this->execute('behat_forms::the_field_matches_value', [get_string('subscribe', 'mod_digestforum'), '1']);
    }

    /**
     * Subscribes to the digestforum.
     *
     * @Given /^I subscribe to this digestforum$/
     */
    public function i_subscribe_to_this_digestforum() {
        $this->execute('behat_general::i_click_on', [get_string('subscribe', 'mod_digestforum'), 'field']);
    }

    /**
     * Unsubscribes from the digestforum.
     *
     * @Given /^I unsubscribe from this digestforum$/
     */
    public function i_unsubscribe_from_this_digestforum() {
        $this->i_subscribe_to_this_digestforum();
    }

    /**
     * Fetch user ID from its username.
     *
     * @param string $username The username.
     * @return int The user ID.
     * @throws Exception
     */
    protected function get_user_id($username) {
        global $DB;

        if (!$userid = $DB->get_field('user', 'id', ['username' => $username])) {
            throw new Exception("A user with username '{$username}' does not exist");
        }
        return $userid;
    }

    /**
     * Fetch course ID using course name.
     *
     * @param string $coursename The name of the course.
     * @return int The course ID.
     * @throws Exception
     */
    protected function get_course_id(string $coursename): int {
        global $DB;

        if (!$courseid = $DB->get_field('course', 'id', ['fullname' => $coursename])) {
            throw new Exception("A course with name '{$coursename}' does not exist");
        }

        return $courseid;
    }

    /**
     * Fetch digestforum ID using digestforum name.
     *
     * @param int $courseid The course ID the digestforum exists within.
     * @param string $digestforumname The name of the digestforum.
     * @return int The digestforum ID.
     * @throws Exception
     */
    protected function get_digestforum_id(int $courseid, string $digestforumname): int {
        global $DB;

        $conditions = [
            'course' => $courseid,
            'name' => $digestforumname,
        ];

        if (!$digestforumid = $DB->get_field('digestforum', 'id', $conditions)) {
            throw new Exception("A digestforum with name '{$digestforumname}' does not exist in the provided course");
        }

        return $digestforumid;
    }

    /**
     * Fetch Group ID using group name.
     *
     * @param int $courseid The course ID the digestforum exists within.
     * @param string $groupname The short name of the group.
     * @return int The group ID.
     * @throws Exception
     */
    protected function get_group_id(int $courseid, string $groupname): int {
        global $DB;

        if ($groupname === 'All participants') {
            return -1;
        }

        $conditions = [
            'courseid' => $courseid,
            'idnumber' => $groupname,
        ];

        if (!$groupid = $DB->get_field('groups', 'id', $conditions)) {
            throw new Exception("A group with name '{$groupname}' does not exist in the provided course");
        }

        return $groupid;
    }

    /**
     * Fetch discussion ID and first post ID by discussion name.
     *
     * @param int $digestforumid The digestforum ID where the discussion resides.
     * @param string $name The name of the discussion.
     * @return array The discussion ID and first post ID.
     * @throws dml_exception If the discussion name is not unique within the digestforum (or doesn't exist).
     */
    protected function get_base_discussion(int $digestforumid, string $name): array {
        global $DB;

        $conditions = [
            'name' => $name,
            'digestforum' => $digestforumid,
        ];

        $result = $DB->get_record("digestforum_discussions", $conditions, 'id, firstpost', MUST_EXIST);

        return [
            'discussionid' => $result->id,
            'parentid' => $result->firstpost,
        ];
    }

    /**
     * Create one or more attached or inline attachments to a digestforum post.
     *
     * @param int $postid The ID of the digestforum post.
     * @param int $userid The user ID creating the attachment.
     * @param array $attachmentnames Names of all attachments to be created.
     * @param stdClass $cm The context module of the digestforum.
     * @param string $filearea The file area being written to, eg 'attachment' or 'post' (inline).
     */
    protected function create_post_attachments(
        int $postid,
        int $userid,
        array $attachmentnames,
        stdClass $cm,
        string $filearea,
    ): void {
        $filestorage = get_file_storage();

        foreach ($attachmentnames as $attachmentname) {
            $filestorage->create_file_from_string(
                [
                    'contextid' => context_module::instance($cm->id)->id,
                    'component' => 'mod_digestforum',
                    'filearea'  => $filearea,
                    'itemid'    => $postid,
                    'filepath'  => '/',
                    'filename'  => $attachmentname,
                    'userid'    => $userid,
                ],
                "File content {$attachmentname}"
            );
        }
    }

    /**
     * Returns the steps list to add a new discussion to a digestforum.
     *
     * Abstracts add a new topic and add a new discussion, as depending
     * on the digestforum type the button string changes.
     *
     * @param string $digestforumname
     * @param TableNode $table
     * @param string $buttonstr
     */
    protected function add_new_discussion($digestforumname, TableNode $table, $buttonstr) {
        // Navigate to digestforum.
        $this->execute('behat_navigation::i_am_on_page_instance', [$this->escape($digestforumname), 'digestforum activity']);
        $this->execute('behat_general::click_link', $buttonstr);
        $this->execute('behat_forms::press_button', get_string('showadvancededitor'));

        $this->fill_new_discussion_form($table);
    }

    /**
     * Returns the steps list to add a new discussion to a digestforum inline.
     *
     * Abstracts add a new topic and add a new discussion, as depending
     * on the digestforum type the button string changes.
     *
     * @param string $digestforumname
     * @param TableNode $table
     * @param string $buttonstr
     */
    protected function add_new_discussion_inline($digestforumname, TableNode $table, $buttonstr) {
        // Navigate to digestforum.
        $this->execute('behat_navigation::i_am_on_page_instance', [$this->escape($digestforumname), 'digestforum activity']);
        $this->execute('behat_general::click_link', $buttonstr);
        $this->fill_new_discussion_form($table);
    }

    /**
     * Fill in the digestforum's post form and submit. It assumes you've already navigated and enabled the form for view.
     *
     * @param TableNode $table
     * @throws coding_exception
     */
    protected function fill_new_discussion_form(TableNode $table) {
        // Fill form and post.
        $this->execute('behat_forms::i_set_the_following_fields_to_these_values', $table);
        $this->execute('behat_forms::press_button', get_string('posttodigestforum', 'digestforum'));
        $this->execute('behat_general::i_wait_to_be_redirected');
    }

    /**
     * Go to the default reply to post page.
     * This is used instead of navigating through 4-5 different steps and to solve issues where JS would be required to click
     * on the advanced button
     *
     * @param $postsubject
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function goto_main_post_reply($postsubject) {
        global $DB;
        $post = $DB->get_record("digestforum_posts", ["subject" => $postsubject], 'id', MUST_EXIST);
        $url = new moodle_url('/mod/digestforum/post.php', ['reply' => $post->id]);
        $this->execute('behat_general::i_visit', [$url]);
    }
}
