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
 * The module digestforums tests
 *
 * @package    mod_digestforum
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/digestforum/lib.php');
require_once(__DIR__ . '/helper.php');

class mod_digestforum_subscriptions_testcase extends advanced_testcase {
    // Include the mod_digestforum test helpers.
    // This includes functions to create digestforums, users, discussions, and posts.
    use helper;

    /**
     * Test setUp.
     */
    public function setUp() {
        global $DB;

        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
        \mod_digestforum\subscriptions::reset_discussion_cache();
    }

    /**
     * Test tearDown.
     */
    public function tearDown() {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
        \mod_digestforum\subscriptions::reset_discussion_cache();
    }

    public function test_subscription_modes() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create a user enrolled in the course as a student.
        list($user) = $this->helper_create_users($course, 1);

        // Must be logged in as the current user.
        $this->setUser($user);

        \mod_digestforum\subscriptions::set_subscription_mode($digestforum->id, DFORUM_FORCESUBSCRIBE);
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum->id));
        $this->assertEquals(DFORUM_FORCESUBSCRIBE, \mod_digestforum\subscriptions::get_subscription_mode($digestforum));
        $this->assertTrue(\mod_digestforum\subscriptions::is_forcesubscribed($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribable($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::subscription_disabled($digestforum));

        \mod_digestforum\subscriptions::set_subscription_mode($digestforum->id, DFORUM_DISALLOWSUBSCRIBE);
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum->id));
        $this->assertEquals(DFORUM_DISALLOWSUBSCRIBE, \mod_digestforum\subscriptions::get_subscription_mode($digestforum));
        $this->assertTrue(\mod_digestforum\subscriptions::subscription_disabled($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribable($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::is_forcesubscribed($digestforum));

        \mod_digestforum\subscriptions::set_subscription_mode($digestforum->id, DFORUM_INITIALSUBSCRIBE);
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum->id));
        $this->assertEquals(DFORUM_INITIALSUBSCRIBE, \mod_digestforum\subscriptions::get_subscription_mode($digestforum));
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribable($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::subscription_disabled($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::is_forcesubscribed($digestforum));

        \mod_digestforum\subscriptions::set_subscription_mode($digestforum->id, DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $DB->get_record('digestforum', array('id' => $digestforum->id));
        $this->assertEquals(DFORUM_CHOOSESUBSCRIBE, \mod_digestforum\subscriptions::get_subscription_mode($digestforum));
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribable($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::subscription_disabled($digestforum));
        $this->assertFalse(\mod_digestforum\subscriptions::is_forcesubscribed($digestforum));
    }

    /**
     * Test fetching unsubscribable digestforums.
     */
    public function test_unsubscribable_digestforums() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        // Create a user enrolled in the course as a student.
        list($user) = $this->helper_create_users($course, 1);

        // Must be logged in as the current user.
        $this->setUser($user);

        // Without any subscriptions, there should be nothing returned.
        $result = \mod_digestforum\subscriptions::get_unsubscribable_digestforums();
        $this->assertEquals(0, count($result));

        // Create the digestforums.
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_FORCESUBSCRIBE);
        $forcedigestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_DISALLOWSUBSCRIBE);
        $disallowdigestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $choosedigestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $initialdigestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // At present the user is only subscribed to the initial digestforum.
        $result = \mod_digestforum\subscriptions::get_unsubscribable_digestforums();
        $this->assertEquals(1, count($result));

        // Ensure that the user is enrolled in all of the digestforums except force subscribed.
        \mod_digestforum\subscriptions::subscribe_user($user->id, $disallowdigestforum);
        \mod_digestforum\subscriptions::subscribe_user($user->id, $choosedigestforum);

        $result = \mod_digestforum\subscriptions::get_unsubscribable_digestforums();
        $this->assertEquals(3, count($result));

        // Hide the digestforums.
        set_coursemodule_visible($forcedigestforum->cmid, 0);
        set_coursemodule_visible($disallowdigestforum->cmid, 0);
        set_coursemodule_visible($choosedigestforum->cmid, 0);
        set_coursemodule_visible($initialdigestforum->cmid, 0);
        $result = \mod_digestforum\subscriptions::get_unsubscribable_digestforums();
        $this->assertEquals(0, count($result));

        // Add the moodle/course:viewhiddenactivities capability to the student user.
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $context = \context_course::instance($course->id);
        assign_capability('moodle/course:viewhiddenactivities', CAP_ALLOW, $roleids['student'], $context);

        // All of the unsubscribable digestforums should now be listed.
        $result = \mod_digestforum\subscriptions::get_unsubscribable_digestforums();
        $this->assertEquals(3, count($result));
    }

    /**
     * Test that toggling the digestforum-level subscription for a different user does not affect their discussion-level
     * subscriptions.
     */
    public function test_digestforum_subscribe_toggle_as_other() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create a user enrolled in the course as a student.
        list($author) = $this->helper_create_users($course, 1);

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Check that the user is currently not subscribed to the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Check that the user is unsubscribed from the discussion too.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // Check that we have no records in either of the subscription tables.
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribing to the digestforum should create a record in the subscriptions table, but not the digestforum discussion
        // subscriptions table.
        \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Unsubscribing should remove the record from the digestforum subscriptions table, and not modify the digestforum
        // discussion subscriptions table.
        \mod_digestforum\subscriptions::unsubscribe_user($author->id, $digestforum);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Enroling the user in the discussion should add one record to the digestforum discussion table without modifying the
        // form subscriptions.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Unsubscribing should remove the record from the digestforum subscriptions table, and not modify the digestforum
        // discussion subscriptions table.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Re-subscribe to the discussion so that we can check the effect of digestforum-level subscriptions.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribing to the digestforum should have no effect on the digestforum discussion subscriptions table if the user did
        // not request the change themself.
        \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Unsubscribing from the digestforum should have no effect on the digestforum discussion subscriptions table if the user
        // did not request the change themself.
        \mod_digestforum\subscriptions::unsubscribe_user($author->id, $digestforum);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribing to the digestforum should remove the per-discussion subscription preference if the user requested the
        // change themself.
        \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum, null, true);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Now unsubscribe from the current discussion whilst being subscribed to the digestforum as a whole.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Unsubscribing from the digestforum should remove the per-discussion subscription preference if the user requested the
        // change themself.
        \mod_digestforum\subscriptions::unsubscribe_user($author->id, $digestforum, null, true);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribe to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion);
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribe to the digestforum without removing the discussion preferences.
        \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Unsubscribing from the discussion should result in a change.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

    }

    /**
     * Test that a user unsubscribed from a digestforum is not subscribed to it's discussions by default.
     */
    public function test_digestforum_discussion_subscription_digestforum_unsubscribed() {
        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create users enrolled in the course as students.
        list($author) = $this->helper_create_users($course, 1);

        // Check that the user is currently not subscribed to the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Check that the user is unsubscribed from the discussion too.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));
    }

    /**
     * Test that the act of subscribing to a digestforum subscribes the user to it's discussions by default.
     */
    public function test_digestforum_discussion_subscription_digestforum_subscribed() {
        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create users enrolled in the course as students.
        list($author) = $this->helper_create_users($course, 1);

        // Enrol the user in the digestforum.
        // If a subscription was added, we get the record ID.
        $this->assertInternalType('int', \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum));

        // If we already have a subscription when subscribing the user, we get a boolean (true).
        $this->assertTrue(\mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum));

        // Check that the user is currently subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Check that the user is subscribed to the discussion too.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));
    }

    /**
     * Test that a user unsubscribed from a digestforum can be subscribed to a discussion.
     */
    public function test_digestforum_discussion_subscription_digestforum_unsubscribed_discussion_subscribed() {
        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create a user enrolled in the course as a student.
        list($author) = $this->helper_create_users($course, 1);

        // Check that the user is currently not subscribed to the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Attempting to unsubscribe from the discussion should not make a change.
        $this->assertFalse(\mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion));

        // Then subscribe them to the discussion.
        $this->assertTrue(\mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion));

        // Check that the user is still unsubscribed from the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // But subscribed to the discussion.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));
    }

    /**
     * Test that a user subscribed to a digestforum can be unsubscribed from a discussion.
     */
    public function test_digestforum_discussion_subscription_digestforum_subscribed_discussion_unsubscribed() {
        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create two users enrolled in the course as students.
        list($author) = $this->helper_create_users($course, 2);

        // Enrol the student in the digestforum.
        \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum);

        // Check that the user is currently subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Then unsubscribe them from the discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);

        // Check that the user is still subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // But unsubscribed from the discussion.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));
    }

    /**
     * Test the effect of toggling the discussion subscription status when subscribed to the digestforum.
     */
    public function test_digestforum_discussion_toggle_digestforum_subscribed() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create two users enrolled in the course as students.
        list($author) = $this->helper_create_users($course, 2);

        // Enrol the student in the digestforum.
        \mod_digestforum\subscriptions::subscribe_user($author->id, $digestforum);

        // Check that the user is currently subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Check that the user is initially subscribed to that discussion.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // An attempt to subscribe again should result in a falsey return to indicate that no change was made.
        $this->assertFalse(\mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion));

        // And there should be no discussion subscriptions (and one digestforum subscription).
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // Then unsubscribe them from the discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);

        // Check that the user is still subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // An attempt to unsubscribe again should result in a falsey return to indicate that no change was made.
        $this->assertFalse(\mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion));

        // And there should be a discussion subscriptions (and one digestforum subscription).
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // But unsubscribed from the discussion.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be a record in the discussion subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And one in the digestforum subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // Now subscribe the user again to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion);

        // Check that the user is still subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // And is subscribed to the discussion again.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be no record in the discussion subscription tracking table.
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And one in the digestforum subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // And unsubscribe again.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);

        // Check that the user is still subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // But unsubscribed from the discussion.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be a record in the discussion subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And one in the digestforum subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // And subscribe the user again to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion);

        // Check that the user is still subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // And is subscribed to the discussion again.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be no record in the discussion subscription tracking table.
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And one in the digestforum subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // And unsubscribe again.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);

        // Check that the user is still subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // But unsubscribed from the discussion.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be a record in the discussion subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And one in the digestforum subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // Now unsubscribe the user from the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::unsubscribe_user($author->id, $digestforum, null, true));

        // This removes both the digestforum_subscriptions, and the digestforum_discussion_subs records.
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $author->id,
            'digestforum'         => $digestforum->id,
        )));

        // And should have reset the discussion cache value.
        $result = \mod_digestforum\subscriptions::fetch_discussion_subscription($digestforum->id, $author->id);
        $this->assertInternalType('array', $result);
        $this->assertFalse(isset($result[$discussion->id]));
    }

    /**
     * Test the effect of toggling the discussion subscription status when unsubscribed from the digestforum.
     */
    public function test_digestforum_discussion_toggle_digestforum_unsubscribed() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create two users enrolled in the course as students.
        list($author) = $this->helper_create_users($course, 2);

        // Check that the user is currently unsubscribed to the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);

        // Check that the user is initially unsubscribed to that discussion.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // Then subscribe them to the discussion.
        $this->assertTrue(\mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion));

        // An attempt to subscribe again should result in a falsey return to indicate that no change was made.
        $this->assertFalse(\mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion));

        // Check that the user is still unsubscribed from the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // But subscribed to the discussion.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be a record in the discussion subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // Now unsubscribe the user again from the discussion.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);

        // Check that the user is still unsubscribed from the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // And is unsubscribed from the discussion again.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be no record in the discussion subscription tracking table.
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And subscribe the user again to the discussion.
        \mod_digestforum\subscriptions::subscribe_user_to_discussion($author->id, $discussion);

        // Check that the user is still unsubscribed from the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // And is subscribed to the discussion again.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be a record in the discussion subscription tracking table.
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));

        // And unsubscribe again.
        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($author->id, $discussion);

        // Check that the user is still unsubscribed from the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum));

        // But unsubscribed from the discussion.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($author->id, $digestforum, $discussion->id));

        // There should be no record in the discussion subscription tracking table.
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $author->id,
            'discussion'    => $discussion->id,
        )));
    }

    /**
     * Test that the correct users are returned when fetching subscribed users from a digestforum where users can choose to
     * subscribe and unsubscribe.
     */
    public function test_fetch_subscribed_users_subscriptions() {
        global $DB, $CFG;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum. where users are initially subscribed.
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some user enrolled in the course as a student.
        $usercount = 5;
        $users = $this->helper_create_users($course, $usercount);

        // All users should be subscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($subscribers));

        // Subscribe the guest user too to the digestforum - they should never be returned by this function.
        $this->getDataGenerator()->enrol_user($CFG->siteguest, $course->id);
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($subscribers));

        // Unsubscribe 2 users.
        $unsubscribedcount = 2;
        for ($i = 0; $i < $unsubscribedcount; $i++) {
            \mod_digestforum\subscriptions::unsubscribe_user($users[$i]->id, $digestforum);
        }

        // The subscription count should now take into account those users who have been unsubscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount - $unsubscribedcount, count($subscribers));
    }

    /**
     * Test that the correct users are returned hwen fetching subscribed users from a digestforum where users are forcibly
     * subscribed.
     */
    public function test_fetch_subscribed_users_forced() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum. where users are initially subscribed.
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_FORCESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some user enrolled in the course as a student.
        $usercount = 5;
        $users = $this->helper_create_users($course, $usercount);

        // All users should be subscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($subscribers));
    }

    /**
     * Test that unusual combinations of discussion subscriptions do not affect the subscribed user list.
     */
    public function test_fetch_subscribed_users_discussion_subscriptions() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum. where users are initially subscribed.
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some user enrolled in the course as a student.
        $usercount = 5;
        $users = $this->helper_create_users($course, $usercount);

        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $users[0]);

        // All users should be subscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($subscribers));
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum, 0, null, null, true);
        $this->assertEquals($usercount, count($subscribers));

        \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($users[0]->id, $discussion);

        // All users should be subscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($subscribers));

        // All users should be subscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum, 0, null, null, true);
        $this->assertEquals($usercount, count($subscribers));

        // Manually insert an extra subscription for one of the users.
        $record = new stdClass();
        $record->userid = $users[2]->id;
        $record->digestforum = $digestforum->id;
        $record->discussion = $discussion->id;
        $record->preference = time();
        $DB->insert_record('digestforum_discussion_subs', $record);

        // The discussion count should not have changed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount, count($subscribers));
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum, 0, null, null, true);
        $this->assertEquals($usercount, count($subscribers));

        // Unsubscribe 2 users.
        $unsubscribedcount = 2;
        for ($i = 0; $i < $unsubscribedcount; $i++) {
            \mod_digestforum\subscriptions::unsubscribe_user($users[$i]->id, $digestforum);
        }

        // The subscription count should now take into account those users who have been unsubscribed.
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount - $unsubscribedcount, count($subscribers));
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum, 0, null, null, true);
        $this->assertEquals($usercount - $unsubscribedcount, count($subscribers));

        // Now subscribe one of those users back to the discussion.
        $subscribeddiscussionusers = 1;
        for ($i = 0; $i < $subscribeddiscussionusers; $i++) {
            \mod_digestforum\subscriptions::subscribe_user_to_discussion($users[$i]->id, $discussion);
        }
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum);
        $this->assertEquals($usercount - $unsubscribedcount, count($subscribers));
        $subscribers = \mod_digestforum\subscriptions::fetch_subscribed_users($digestforum, 0, null, null, true);
        $this->assertEquals($usercount - $unsubscribedcount + $subscribeddiscussionusers, count($subscribers));
    }

    /**
     * Test whether a user is force-subscribed to a digestforum.
     */
    public function test_force_subscribed_to_digestforum() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_FORCESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create a user enrolled in the course as a student.
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleids['student']);

        // Check that the user is currently subscribed to the digestforum.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));

        // Remove the allowforcesubscribe capability from the user.
        $cm = get_coursemodule_from_instance('digestforum', $digestforum->id);
        $context = \context_module::instance($cm->id);
        assign_capability('mod/digestforum:allowforcesubscribe', CAP_PROHIBIT, $roleids['student'], $context);
        $this->assertFalse(has_capability('mod/digestforum:allowforcesubscribe', $context, $user->id));

        // Check that the user is no longer subscribed to the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
    }

    /**
     * Test that the subscription cache can be pre-filled.
     */
    public function test_subscription_cache_prefill() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some users.
        $users = $this->helper_create_users($course, 20);

        // Reset the subscription cache.
        \mod_digestforum\subscriptions::reset_digestforum_cache();

        // Filling the subscription cache should use a query.
        $startcount = $DB->perf_get_reads();
        $this->assertNull(\mod_digestforum\subscriptions::fill_subscription_cache($digestforum->id));
        $postfillcount = $DB->perf_get_reads();
        $this->assertNotEquals($postfillcount, $startcount);

        // Now fetch some subscriptions from that digestforum - these should use
        // the cache and not perform additional queries.
        foreach ($users as $user) {
            $this->assertTrue(\mod_digestforum\subscriptions::fetch_subscription_cache($digestforum->id, $user->id));
        }
        $finalcount = $DB->perf_get_reads();
        $this->assertEquals(0, $finalcount - $postfillcount);
    }

    /**
     * Test that the subscription cache can filled user-at-a-time.
     */
    public function test_subscription_cache_fill() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some users.
        $users = $this->helper_create_users($course, 20);

        // Reset the subscription cache.
        \mod_digestforum\subscriptions::reset_digestforum_cache();

        // Filling the subscription cache should only use a single query.
        $startcount = $DB->perf_get_reads();

        // Fetch some subscriptions from that digestforum - these should not use the cache and will perform additional queries.
        foreach ($users as $user) {
            $this->assertTrue(\mod_digestforum\subscriptions::fetch_subscription_cache($digestforum->id, $user->id));
        }
        $finalcount = $DB->perf_get_reads();
        $this->assertEquals(20, $finalcount - $startcount);
    }

    /**
     * Test that the discussion subscription cache can filled course-at-a-time.
     */
    public function test_discussion_subscription_cache_fill_for_course() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        // Create the digestforums.
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_DISALLOWSUBSCRIBE);
        $disallowdigestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $choosedigestforum = $this->getDataGenerator()->create_module('digestforum', $options);
        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $initialdigestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some users and keep a reference to the first user.
        $users = $this->helper_create_users($course, 20);
        $user = reset($users);

        // Reset the subscription caches.
        \mod_digestforum\subscriptions::reset_digestforum_cache();

        $startcount = $DB->perf_get_reads();
        $result = \mod_digestforum\subscriptions::fill_subscription_cache_for_course($course->id, $user->id);
        $this->assertNull($result);
        $postfillcount = $DB->perf_get_reads();
        $this->assertNotEquals($postfillcount, $startcount);
        $this->assertFalse(\mod_digestforum\subscriptions::fetch_subscription_cache($disallowdigestforum->id, $user->id));
        $this->assertFalse(\mod_digestforum\subscriptions::fetch_subscription_cache($choosedigestforum->id, $user->id));
        $this->assertTrue(\mod_digestforum\subscriptions::fetch_subscription_cache($initialdigestforum->id, $user->id));
        $finalcount = $DB->perf_get_reads();
        $this->assertEquals(0, $finalcount - $postfillcount);

        // Test for all users.
        foreach ($users as $user) {
            $result = \mod_digestforum\subscriptions::fill_subscription_cache_for_course($course->id, $user->id);
            $this->assertFalse(\mod_digestforum\subscriptions::fetch_subscription_cache($disallowdigestforum->id, $user->id));
            $this->assertFalse(\mod_digestforum\subscriptions::fetch_subscription_cache($choosedigestforum->id, $user->id));
            $this->assertTrue(\mod_digestforum\subscriptions::fetch_subscription_cache($initialdigestforum->id, $user->id));
        }
        $finalcount = $DB->perf_get_reads();
        $this->assertNotEquals($finalcount, $postfillcount);
    }

    /**
     * Test that the discussion subscription cache can be forcibly updated for a user.
     */
    public function test_discussion_subscription_cache_prefill() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some users.
        $users = $this->helper_create_users($course, 20);

        // Post some discussions to the digestforum.
        $discussions = array();
        $author = $users[0];
        for ($i = 0; $i < 20; $i++) {
            list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);
            $discussions[] = $discussion;
        }

        // Unsubscribe half the users from the half the discussions.
        $digestforumcount = 0;
        $usercount = 0;
        foreach ($discussions as $data) {
            if ($digestforumcount % 2) {
                continue;
            }
            foreach ($users as $user) {
                if ($usercount % 2) {
                    continue;
                }
                \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion);
                $usercount++;
            }
            $digestforumcount++;
        }

        // Reset the subscription caches.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
        \mod_digestforum\subscriptions::reset_discussion_cache();

        // Filling the discussion subscription cache should only use a single query.
        $startcount = $DB->perf_get_reads();
        $this->assertNull(\mod_digestforum\subscriptions::fill_discussion_subscription_cache($digestforum->id));
        $postfillcount = $DB->perf_get_reads();
        $this->assertNotEquals($postfillcount, $startcount);

        // Now fetch some subscriptions from that digestforum - these should use
        // the cache and not perform additional queries.
        foreach ($users as $user) {
            $result = \mod_digestforum\subscriptions::fetch_discussion_subscription($digestforum->id, $user->id);
            $this->assertInternalType('array', $result);
        }
        $finalcount = $DB->perf_get_reads();
        $this->assertEquals(0, $finalcount - $postfillcount);
    }

    /**
     * Test that the discussion subscription cache can filled user-at-a-time.
     */
    public function test_discussion_subscription_cache_fill() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_INITIALSUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create some users.
        $users = $this->helper_create_users($course, 20);

        // Post some discussions to the digestforum.
        $discussions = array();
        $author = $users[0];
        for ($i = 0; $i < 20; $i++) {
            list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $author);
            $discussions[] = $discussion;
        }

        // Unsubscribe half the users from the half the discussions.
        $digestforumcount = 0;
        $usercount = 0;
        foreach ($discussions as $data) {
            if ($digestforumcount % 2) {
                continue;
            }
            foreach ($users as $user) {
                if ($usercount % 2) {
                    continue;
                }
                \mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion);
                $usercount++;
            }
            $digestforumcount++;
        }

        // Reset the subscription caches.
        \mod_digestforum\subscriptions::reset_digestforum_cache();
        \mod_digestforum\subscriptions::reset_discussion_cache();

        $startcount = $DB->perf_get_reads();

        // Now fetch some subscriptions from that digestforum - these should use
        // the cache and not perform additional queries.
        foreach ($users as $user) {
            $result = \mod_digestforum\subscriptions::fetch_discussion_subscription($digestforum->id, $user->id);
            $this->assertInternalType('array', $result);
        }
        $finalcount = $DB->perf_get_reads();
        $this->assertNotEquals($finalcount, $startcount);
    }

    /**
     * Test that after toggling the digestforum subscription as another user,
     * the discussion subscription functionality works as expected.
     */
    public function test_digestforum_subscribe_toggle_as_other_repeat_subscriptions() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create a user enrolled in the course as a student.
        list($user) = $this->helper_create_users($course, 1);

        // Post a discussion to the digestforum.
        list($discussion, $post) = $this->helper_post_to_digestforum($digestforum, $user);

        // Confirm that the user is currently not subscribed to the digestforum.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));

        // Confirm that the user is unsubscribed from the discussion too.
        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum, $discussion->id));

        // Confirm that we have no records in either of the subscription tables.
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $user->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $user->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribing to the digestforum should create a record in the subscriptions table, but not the digestforum discussion
        // subscriptions table.
        \mod_digestforum\subscriptions::subscribe_user($user->id, $digestforum);
        $this->assertEquals(1, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $user->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(0, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $user->id,
            'discussion'    => $discussion->id,
        )));

        // Now unsubscribe from the discussion. This should return true.
        $this->assertTrue(\mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion));

        // Attempting to unsubscribe again should return false because no change was made.
        $this->assertFalse(\mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion));

        // Subscribing to the discussion again should return truthfully as the subscription preference was removed.
        $this->assertTrue(\mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion));

        // Attempting to subscribe again should return false because no change was made.
        $this->assertFalse(\mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion));

        // Now unsubscribe from the discussion. This should return true once more.
        $this->assertTrue(\mod_digestforum\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion));

        // And unsubscribing from the digestforum but not as a request from the user should maintain their preference.
        \mod_digestforum\subscriptions::unsubscribe_user($user->id, $digestforum);

        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $user->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $user->id,
            'discussion'    => $discussion->id,
        )));

        // Subscribing to the discussion should return truthfully because a change was made.
        $this->assertTrue(\mod_digestforum\subscriptions::subscribe_user_to_discussion($user->id, $discussion));
        $this->assertEquals(0, $DB->count_records('digestforum_subscriptions', array(
            'userid'        => $user->id,
            'digestforum'         => $digestforum->id,
        )));
        $this->assertEquals(1, $DB->count_records('digestforum_discussion_subs', array(
            'userid'        => $user->id,
            'discussion'    => $discussion->id,
        )));
    }

    /**
     * Test that providing a context_module instance to is_subscribed does not result in additional lookups to retrieve
     * the context_module.
     */
    public function test_is_subscribed_cm() {
        global $DB;

        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();

        $options = array('course' => $course->id, 'forcesubscribe' => DFORUM_FORCESUBSCRIBE);
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        // Create a user enrolled in the course as a student.
        list($user) = $this->helper_create_users($course, 1);

        // Retrieve the $cm now.
        $cm = get_fast_modinfo($digestforum->course)->instances['digestforum'][$digestforum->id];

        // Reset get_fast_modinfo.
        get_fast_modinfo(0, 0, true);

        // Call is_subscribed without passing the $cmid - this should result in a lookup and filling of some of the
        // caches. This provides us with consistent data to start from.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));

        // Make a note of the number of DB calls.
        $basecount = $DB->perf_get_reads();

        // Call is_subscribed - it should give return the correct result (False), and result in no additional queries.
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum, null, $cm));

        // The capability check does require some queries, so we don't test it directly.
        // We don't assert here because this is dependant upon linked code which could change at any time.
        $suppliedcmcount = $DB->perf_get_reads() - $basecount;

        // Call is_subscribed without passing the $cmid now - this should result in a lookup.
        get_fast_modinfo(0, 0, true);
        $basecount = $DB->perf_get_reads();
        $this->assertTrue(\mod_digestforum\subscriptions::is_subscribed($user->id, $digestforum));
        $calculatedcmcount = $DB->perf_get_reads() - $basecount;

        // There should be more queries than when we performed the same check a moment ago.
        $this->assertGreaterThan($suppliedcmcount, $calculatedcmcount);
    }

    public function is_subscribable_digestforums() {
        return [
            [
                'forcesubscribe' => DFORUM_DISALLOWSUBSCRIBE,
            ],
            [
                'forcesubscribe' => DFORUM_CHOOSESUBSCRIBE,
            ],
            [
                'forcesubscribe' => DFORUM_INITIALSUBSCRIBE,
            ],
            [
                'forcesubscribe' => DFORUM_FORCESUBSCRIBE,
            ],
        ];
    }

    public function is_subscribable_provider() {
        $data = [];
        foreach ($this->is_subscribable_digestforums() as $digestforum) {
            $data[] = [$digestforum];
        }

        return $data;
    }

    /**
     * @dataProvider is_subscribable_provider
     */
    public function test_is_subscribable_logged_out($options) {
        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();
        $options['course'] = $course->id;
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribable($digestforum));
    }

    /**
     * @dataProvider is_subscribable_provider
     */
    public function test_is_subscribable_is_guest($options) {
        global $DB;
        $this->resetAfterTest(true);

        $guest = $DB->get_record('user', array('username'=>'guest'));
        $this->setUser($guest);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();
        $options['course'] = $course->id;
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $this->assertFalse(\mod_digestforum\subscriptions::is_subscribable($digestforum));
    }

    public function is_subscribable_loggedin_provider() {
        return [
            [
                ['forcesubscribe' => DFORUM_DISALLOWSUBSCRIBE],
                false,
            ],
            [
                ['forcesubscribe' => DFORUM_CHOOSESUBSCRIBE],
                true,
            ],
            [
                ['forcesubscribe' => DFORUM_INITIALSUBSCRIBE],
                true,
            ],
            [
                ['forcesubscribe' => DFORUM_FORCESUBSCRIBE],
                false,
            ],
        ];
    }

    /**
     * @dataProvider is_subscribable_loggedin_provider
     */
    public function test_is_subscribable_loggedin($options, $expect) {
        $this->resetAfterTest(true);

        // Create a course, with a digestforum.
        $course = $this->getDataGenerator()->create_course();
        $options['course'] = $course->id;
        $digestforum = $this->getDataGenerator()->create_module('digestforum', $options);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $this->assertEquals($expect, \mod_digestforum\subscriptions::is_subscribable($digestforum));
    }
}
